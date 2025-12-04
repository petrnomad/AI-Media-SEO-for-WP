<?php
/**
 * Anthropic Provider
 *
 * Integration with Anthropic Claude API for image analysis.
 *
 * @package    AIMediaSEO
 * @subpackage Providers
 * @since      1.0.0
 */

namespace AIMediaSEO\Providers;

/**
 * AnthropicProvider class.
 *
 * Implements Claude API integration for image metadata generation.
 *
 * @since 1.0.0
 */
class AnthropicProvider extends AbstractProvider {

	/**
	 * API endpoint.
	 *
	 * @var string
	 */
	private $api_endpoint = 'https://api.anthropic.com/v1/messages';

	/**
	 * API version.
	 *
	 * @var string
	 */
	private $api_version = '2023-06-01';

	/**
	 * Max tokens.
	 *
	 * @var int
	 */
	private $max_tokens = 1024;

	/**
	 * Temperature.
	 *
	 * @var float
	 */
	private $temperature = 0.7;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param array $config Configuration array.
	 */
	public function __construct( array $config = array() ) {
		$this->name = 'anthropic';
		$this->set_config( $config );
	}

	/**
	 * Set configuration.
	 *
	 * @since 1.0.0
	 * @param array $config Configuration array.
	 */
	public function set_config( array $config ): void {
		$this->api_key     = $config['api_key'] ?? '';
		$this->model       = $config['model'] ?? 'claude-sonnet-4-5-20250929';
		$this->max_tokens  = $config['max_tokens'] ?? 1024;
		$this->temperature = $config['temperature'] ?? 0.7;
	}

	/**
	 * Analyze image.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param array  $context       Additional context.
	 * @return array Analysis results.
	 * @throws \Exception When analysis fails.
	 */
	public function analyze( int $attachment_id, string $language, array $context ): array {
		try {
			if ( empty( $this->api_key ) ) {
				throw new \Exception( __( 'Anthropic API key is not configured.', 'ai-media-seo' ) );
			}

			// Get image URL (from AbstractProvider).
			$image_url = $this->get_image_url( $attachment_id );
			if ( ! $image_url ) {
				throw new \Exception( __( 'Could not get image URL.', 'ai-media-seo' ) );
			}

			// Get image data as base64 (from ImageDataHelper).
			$image_data = \AIMediaSEO\Utilities\ImageDataHelper::get_image_base64( $image_url, 'media_type', 'Anthropic' );
			if ( ! $image_data ) {
				throw new \Exception( __( 'Could not load image data.', 'ai-media-seo' ) );
			}

			// Build prompt (from AbstractProvider).
			$prompt = $this->build_prompt( $language, $context, $attachment_id );

			// Make API request.
			$response = $this->make_api_request( $image_data['base64'], $image_data['media_type'], $prompt );

			// Parse and return response.
			$parsed = $this->parse_response( $response );

			// Track tokens and calculate cost (from AbstractProvider).
			$token_data = $this->track_tokens_and_calculate_cost(
				$response,
				$attachment_id,
				$prompt,
				array(
					'usage_key'  => 'usage',
					'input_key'  => 'input_tokens',
					'output_key' => 'output_tokens',
				)
			);

			if ( $token_data ) {
				$parsed['token_data'] = $token_data;
			}

			return $parsed;

		} finally {
			// Cleanup temp files (from AbstractProvider).
			$this->cleanup_temp_files();
		}
	}

	/**
	 * Make API request.
	 *
	 * @since 1.0.0
	 * @param string $image_base64 Base64 encoded image.
	 * @param string $media_type   Media type.
	 * @param string $prompt       Prompt text.
	 * @return array API response.
	 * @throws \Exception When request fails.
	 */
	private function make_api_request( string $image_base64, string $media_type, string $prompt ): array {
		$body = array(
			'model'      => $this->model,
			'max_tokens' => $this->max_tokens,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => $media_type,
								'data'       => $image_base64,
							),
						),
						array(
							'type' => 'text',
							'text' => $prompt,
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			$this->api_endpoint,
			array(
				'headers' => array(
					'x-api-key'         => $this->api_key,
					'anthropic-version' => $this->api_version,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code !== 200 ) {
			$error_message = $data['error']['message'] ?? 'Unknown error';
			$error_type = $data['error']['type'] ?? '';
			$full_error = "Anthropic API error ({$status_code})";
			if ( $error_type ) {
				$full_error .= " [{$error_type}]";
			}
			$full_error .= ": {$error_message}";

			// Add raw response for debugging
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$full_error .= " | Raw response: " . substr( $body, 0, 500 );
			}

			throw new \Exception( $full_error );
		}

		return $data;
	}

	/**
	 * Parse API response.
	 *
	 * @since 1.0.0
	 * @param array $response API response.
	 * @return array Parsed metadata.
	 * @throws \Exception When parsing fails.
	 */
	private function parse_response( array $response ): array {
		if ( empty( $response['content'][0]['text'] ) ) {
			$debug_info = 'Response structure: ' . wp_json_encode( array_keys( $response ) );
			if ( isset( $response['content'] ) ) {
				$debug_info .= ' | Content: ' . wp_json_encode( $response['content'] );
			}
			throw new \Exception( __( 'Invalid response from Anthropic API.', 'ai-media-seo' ) . ' | ' . $debug_info );
		}

		$text = $response['content'][0]['text'];

		// Extract JSON from response.
		if ( preg_match( '/\{[^}]+\}/', $text, $matches ) ) {
			$json_str = $matches[0];
		} else {
			$json_str = $text;
		}

		$metadata = json_decode( $json_str, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \Exception( __( 'Failed to parse JSON response.', 'ai-media-seo' ) );
		}

		// Validate required fields.
		if ( empty( $metadata['alt'] ) ) {
			throw new \Exception( __( 'Missing ALT text in response.', 'ai-media-seo' ) );
		}

		// Ensure keywords is array.
		if ( isset( $metadata['keywords'] ) && is_string( $metadata['keywords'] ) ) {
			$metadata['keywords'] = array_map( 'trim', explode( ',', $metadata['keywords'] ) );
		}

		// Set default score if missing.
		if ( ! isset( $metadata['score'] ) ) {
			$metadata['score'] = 0.85;
		}

		return $metadata;
	}

	/**
	 * Validate configuration.
	 *
	 * @since 1.0.0
	 * @return bool
	 * @throws \Exception When validation fails.
	 */
	public function validate_config(): bool {
		if ( empty( $this->api_key ) ) {
			throw new \Exception( __( 'Anthropic API key is required.', 'ai-media-seo' ) );
		}

		if ( empty( $this->model ) ) {
			throw new \Exception( __( 'Model is required.', 'ai-media-seo' ) );
		}

		return true;
	}

	/**
	 * Test connection.
	 *
	 * @since 1.0.0
	 * @return bool
	 * @throws \Exception When connection fails.
	 */
	public function test_connection(): bool {
		$response = wp_remote_post(
			$this->api_endpoint,
			array(
				'headers' => array(
					'x-api-key'         => $this->api_key,
					'anthropic-version' => $this->api_version,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $this->model,
						'max_tokens' => 10,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Hello',
							),
						),
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		return $status_code === 200;
	}

	/**
	 * Estimate cost.
	 *
	 * @since 1.0.0
	 * @param int $tokens Number of tokens.
	 * @return float Cost in cents.
	 */
	public function estimate_cost( int $tokens ): float {
		// Claude Sonnet 4.5 pricing: $3 per million input tokens, $15 per million output tokens.
		// Estimate 1000 input + 500 output tokens per image.
		$input_tokens  = 1000;
		$output_tokens = 500;

		$input_cost  = ( $input_tokens / 1000000 ) * 300; // $3 = 300 cents.
		$output_cost = ( $output_tokens / 1000000 ) * 1500; // $15 = 1500 cents.

		return $input_cost + $output_cost;
	}

	/**
	 * Get model capabilities.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_model_capabilities(): array {
		return array(
			'supports_vision'   => true,
			'supports_json'     => true,
			'max_tokens'        => 4096,
			'max_image_size'    => 1600,
			'supported_formats' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ),
		);
	}
}
