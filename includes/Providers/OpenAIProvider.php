<?php
/**
 * OpenAI Provider Implementation
 *
 * @package    AIMediaSEO
 * @subpackage Providers
 * @since      1.0.0
 */

namespace AIMediaSEO\Providers;

use Exception;

/**
 * OpenAI Provider class.
 *
 * Implements image analysis using OpenAI's Vision API.
 *
 * @since 1.0.0
 */
class OpenAIProvider extends AbstractProvider {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private $api_url = 'https://api.openai.com/v1';

	/**
	 * Maximum retries for failed requests.
	 *
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout = 60;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param array $config Configuration array.
	 */
	public function __construct( array $config = array() ) {
		$this->name = 'openai';

		if ( ! empty( $config ) ) {
			$this->set_config( $config );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function set_config( array $config ): void {
		$this->api_key = $config['api_key'] ?? '';
		$this->model   = $config['model'] ?? 'gpt-4o';

		if ( isset( $config['api_url'] ) ) {
			$this->api_url = $config['api_url'];
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function analyze( int $attachment_id, string $language, array $context ): array {
		try {
			if ( empty( $this->api_key ) ) {
				throw new Exception( __( 'OpenAI API key is not configured.', 'ai-media-seo' ) ) );
			}

			// Get image URL (from AbstractProvider).
			$image_url = $this->get_image_url( $attachment_id );
			if ( ! $image_url ) {
				throw new Exception( __( 'Failed to get image URL.', 'ai-media-seo' ) ) );
			}

			// Build prompt (from AbstractProvider).
			$prompt = $this->build_prompt( $language, $context, $attachment_id );

			// Make API request.
			$response = $this->make_api_request( $image_url, $prompt );

			// Parse and validate response.
			$parsed = $this->parse_response( $response );

			// Track tokens and calculate cost (from AbstractProvider).
			$token_data = $this->track_tokens_and_calculate_cost(
				$response,
				$attachment_id,
				$prompt,
				array(
					'usage_key'  => 'usage',
					'input_key'  => 'prompt_tokens',
					'output_key' => 'completion_tokens',
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
	 * Make API request to OpenAI.
	 *
	 * @since 1.0.0
	 * @param string $image_url The image URL.
	 * @param string $prompt    The prompt text.
	 * @return array API response.
	 * @throws Exception When request fails.
	 */
	private function make_api_request( string $image_url, string $prompt ): array {
		$endpoint = $this->api_url . '/chat/completions';

		// Get image data as base64 (works for local and remote URLs).
		$image_data = \AIMediaSEO\Utilities\ImageDataHelper::get_image_base64( $image_url, 'media_type', 'OpenAI' );
		if ( ! $image_data ) {
			throw new Exception( __( 'Could not load image data.', 'ai-media-seo' ) ) );
		}

		// Create data URI for OpenAI (supports base64).
		$data_uri = sprintf(
			'data:%s;base64,%s',
			$image_data['media_type'],
			$image_data['base64']
		);

		$body = array(
			'model'      => $this->model,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $prompt,
						),
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url' => $data_uri,
							),
						),
					),
				),
			),
			'max_tokens' => 500,
			'temperature' => 0.3,
		);

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => $this->timeout,
			'method'  => 'POST',
		);

		/**
		 * Fires before making OpenAI API request.
		 *
		 * @since 1.0.0
		 * @param array  $args  Request arguments.
		 * @param string $image_url Image URL being analyzed.
		 */
		do_action( 'ai_media_before_openai_request', $args, $image_url );

		$response = wp_remote_request( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			throw new Exception(
				sprintf(
					/* translators: %s: Error message */
					__( 'OpenAI API request failed: %s', 'ai-media-seo' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code !== 200 ) {
			$error_message = $body_data['error']['message'] ?? 'Unknown error';
			throw new Exception(
				sprintf(
					/* translators: 1: Status code, 2: Error message */
					__( 'OpenAI API error %1$d: %2$s', 'ai-media-seo' ),
					$status_code,
					$error_message
				)
			);
		}

		/**
		 * Fires after successful OpenAI API request.
		 *
		 * @since 1.0.0
		 * @param array $body_data Response data.
		 * @param string $image_url Image URL that was analyzed.
		 */
		do_action( 'ai_media_after_openai_request', $body_data, $image_url );

		return $body_data;
	}

	/**
	 * Parse API response.
	 *
	 * @since 1.0.0
	 * @param array $response The API response.
	 * @return array Parsed metadata.
	 * @throws Exception When parsing fails.
	 */
	private function parse_response( array $response ): array {
		if ( ! isset( $response['choices'][0]['message']['content'] ) ) {
			throw new Exception( __( 'Invalid API response structure.', 'ai-media-seo' ) ) );
		}

		$content = trim( $response['choices'][0]['message']['content'] );

		// Try to extract JSON from response (sometimes wrapped in markdown code blocks).
		if ( preg_match( '/```json\s*(\{.*?\})\s*```/s', $content, $matches ) ) {
			$content = $matches[1];
		} elseif ( preg_match( '/(\{.*\})/s', $content, $matches ) ) {
			$content = $matches[1];
		}

		$data = json_decode( $content, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception(
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Failed to parse JSON response: %s', 'ai-media-seo' ),
					json_last_error_msg()
				)
			);
		}

		// Validate required fields.
		$required = array( 'alt', 'caption', 'title', 'keywords', 'score' );
		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				throw new Exception(
					sprintf(
						/* translators: %s: Field name */
						__( 'Missing required field in response: %s', 'ai-media-seo' ),
						$field
					)
				);
			}
		}

		// Sanitize and validate.
		$sanitized = array(
			'alt'      => sanitize_text_field( mb_substr( $data['alt'], 0, 125 ) ),
			'caption'  => wp_kses_post( $data['caption'] ),
			'title'    => sanitize_text_field( $data['title'] ),
			'keywords' => array_map( 'sanitize_text_field', (array) $data['keywords'] ),
			'score'    => min( 1.0, max( 0.0, (float) $data['score'] ) ),
		);

		// Add legacy usage data for backwards compatibility.
		if ( isset( $response['usage'] ) ) {
			$sanitized['tokens_used'] = (int) ( $response['usage']['total_tokens'] ?? 0 );
			$sanitized['prompt_tokens'] = (int) ( $response['usage']['prompt_tokens'] ?? 0 );
			$sanitized['completion_tokens'] = (int) ( $response['usage']['completion_tokens'] ?? 0 );

			// Legacy cost calculation in cents.
			$prompt_tokens = $sanitized['prompt_tokens'];
			$completion_tokens = $sanitized['completion_tokens'];
			$input_cost = ( $prompt_tokens / 1000000 ) * 5.00;
			$output_cost = ( $completion_tokens / 1000000 ) * 15.00;
			$sanitized['cost_cents'] = ( $input_cost + $output_cost ) * 100;
		}

		return $sanitized;
	}

	/**
	 * {@inheritdoc}
	 */
	public function validate_config(): bool {
		if ( empty( $this->api_key ) ) {
			throw new Exception( __( 'API key is required.', 'ai-media-seo' ) ) );
		}

		if ( strlen( $this->api_key ) < 20 ) {
			throw new Exception( __( 'API key appears to be invalid.', 'ai-media-seo' ) ) );
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection(): bool {
		$endpoint = $this->api_url . '/models';

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		return $status_code === 200;
	}

	/**
	 * {@inheritdoc}
	 */
	public function estimate_cost( int $tokens ): float {
		// GPT-4o pricing (approximate).
		// Input: $5 per 1M tokens, Output: $15 per 1M tokens.
		// For vision, images are ~170 tokens each.
		$input_tokens  = $tokens + 170; // Text + image.
		$output_tokens = 300; // Average output.

		$input_cost  = ( $input_tokens / 1000000 ) * 5.00;
		$output_cost = ( $output_tokens / 1000000 ) * 15.00;

		return ( $input_cost + $output_cost ) * 100; // Return in cents.
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_capabilities(): array {
		return array(
			'supports_vision'   => true,
			'supports_json'     => true,
			'max_tokens'        => 4096,
			'max_image_size'    => 2048,
			'supported_formats' => array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ),
		);
	}
}
