<?php
/**
 * Google Provider
 *
 * Integration with Google Gemini API for image analysis.
 *
 * @package    AIMediaSEO
 * @subpackage Providers
 * @since      1.0.0
 */

namespace AIMediaSEO\Providers;

/**
 * GoogleProvider class.
 *
 * Implements Gemini API integration for image metadata generation.
 *
 * @since 1.0.0
 */
class GoogleProvider extends AbstractProvider {

	/**
	 * API endpoint base.
	 *
	 * @var string
	 */
	private $api_endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * Max tokens.
	 *
	 * @var int
	 */
	private $max_tokens = 4096;

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
		$this->name = 'google';
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
		$this->model       = $config['model'] ?? 'gemini-1.5-flash';
		$this->max_tokens  = $config['max_tokens'] ?? 4096;
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
				throw new \Exception( esc_html( __( 'Google API key is not configured.', 'ai-media-seo' ) ) );
			}

			// Get image URL (from AbstractProvider).
			$image_url = $this->get_image_url( $attachment_id );
			if ( ! $image_url ) {
				throw new \Exception( esc_html( __( 'Could not get image URL.', 'ai-media-seo' ) ) );
			}

			// Get image data as base64 (from ImageDataHelper).
			// Note: Google uses 'mime_type' key instead of 'media_type'.
			$image_data = \AIMediaSEO\Utilities\ImageDataHelper::get_image_base64( $image_url, 'mime_type', 'Google' );
			if ( ! $image_data ) {
				throw new \Exception( esc_html( __( 'Could not load image data.', 'ai-media-seo' ) ) );
			}

			// Build prompt (from AbstractProvider).
			$prompt = $this->build_prompt( $language, $context, $attachment_id );

			// Make API request.
			$response = $this->make_api_request( $image_data['base64'], $image_data['mime_type'], $prompt );

			// Parse response.
			$parsed = $this->parse_response( $response );

			// Track tokens and calculate cost (from AbstractProvider).
			// Google uses different token key names.
			$token_data = $this->track_tokens_and_calculate_cost(
				$response,
				$attachment_id,
				$prompt,
				array(
					'usage_key'  => 'usageMetadata',
					'input_key'  => 'promptTokenCount',
					'output_key' => 'candidatesTokenCount',
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
	 * @param string $mime_type    MIME type.
	 * @param string $prompt       Prompt text.
	 * @return array API response.
	 * @throws \Exception When request fails.
	 */
	private function make_api_request( string $image_base64, string $mime_type, string $prompt ): array {
		$endpoint = $this->api_endpoint . $this->model . ':generateContent';

		$body = array(
			'contents' => array(
				array(
					'parts' => array(
						array(
							'text' => $prompt,
						),
						array(
							'inline_data' => array(
								'mime_type' => $mime_type,
								'data'      => $image_base64,
							),
						),
					),
				),
			),
			'generationConfig' => array(
				'temperature'     => $this->temperature,
				'maxOutputTokens' => $this->max_tokens,
			),
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'x-goog-api-key' => $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( esc_html( $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code !== 200 ) {
			$error_message = $data['error']['message'] ?? 'Unknown error';
			$full_error = "Google API error ({$status_code}): {$error_message}";

			throw new \Exception( esc_html( $full_error ) );
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
		if ( empty( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			throw new \Exception( esc_html( __( 'Invalid response from Google API.', 'ai-media-seo' ) ) );
		}

		$text = $response['candidates'][0]['content']['parts'][0]['text'];

		// Extract JSON from response.
		if ( preg_match( '/\{[^}]+\}/', $text, $matches ) ) {
			$json_str = $matches[0];
		} else {
			$json_str = $text;
		}

		$metadata = json_decode( $json_str, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \Exception( esc_html( __( 'Failed to parse JSON response.', 'ai-media-seo' ) ) );
		}

		// Validate required fields.
		if ( empty( $metadata['alt'] ) ) {
			throw new \Exception( esc_html( __( 'Missing ALT text in response.', 'ai-media-seo' ) ) );
		}

		// Ensure keywords is array.
		if ( isset( $metadata['keywords'] ) && is_string( $metadata['keywords'] ) ) {
			$metadata['keywords'] = array_map( 'trim', explode( ',', $metadata['keywords'] ) );
		}

		// Set default score if missing.
		if ( ! isset( $metadata['score'] ) ) {
			$metadata['score'] = 0.85;
		}

		// Add usage metadata if available.
		if ( ! empty( $response['usageMetadata'] ) ) {
			$usage = $response['usageMetadata'];
			$metadata['usage'] = array(
				'prompt_tokens'     => $usage['promptTokenCount'] ?? 0,
				'completion_tokens' => $usage['candidatesTokenCount'] ?? 0,
				'total_tokens'      => $usage['totalTokenCount'] ?? 0,
			);

			// Calculate cost based on actual token usage for Gemini 2.5 Flash.
			// Pricing: $0.075 per 1M input tokens, $0.30 per 1M output tokens (under 128k context).
			$input_tokens  = $metadata['usage']['prompt_tokens'];
			$output_tokens = $metadata['usage']['completion_tokens'];

			$input_cost  = ( $input_tokens / 1000000 ) * 7.5;  // $0.075 = 7.5 cents.
			$output_cost = ( $output_tokens / 1000000 ) * 30;  // $0.30 = 30 cents.

			$metadata['cost_cents'] = $input_cost + $output_cost;
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
			throw new \Exception( esc_html( __( 'Google API key is required.', 'ai-media-seo' ) ) );
		}

		if ( empty( $this->model ) ) {
			throw new \Exception( esc_html( __( 'Model is required.', 'ai-media-seo' ) ) );
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
		$endpoint = $this->api_endpoint . $this->model . ':generateContent';

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $this->api_key,
				),
				'body'    => wp_json_encode(
					array(
						'contents' => array(
							array(
								'parts' => array(
									array( 'text' => 'Hello' ),
								),
							),
						),
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( esc_html( $response->get_error_message() ) );
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
		// Gemini 1.5 Pro pricing: $1.25 per million input tokens, $5 per million output tokens.
		// Estimate 1000 input + 500 output tokens per image.
		$input_tokens  = 1000;
		$output_tokens = 500;

		$input_cost  = ( $input_tokens / 1000000 ) * 125; // $1.25 = 125 cents.
		$output_cost = ( $output_tokens / 1000000 ) * 500; // $5 = 500 cents.

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
			'max_tokens'        => 8192,
			'max_image_size'    => 2048,
			'supported_formats' => array( 'image/jpeg', 'image/png', 'image/webp' ),
		);
	}
}
