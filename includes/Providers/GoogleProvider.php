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
class GoogleProvider implements ProviderInterface {

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	private $model;

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
	 * Get provider name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_name(): string {
		return 'google';
	}

	/**
	 * Get model.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_model(): string {
		return $this->model;
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
		if ( empty( $this->api_key ) ) {
			throw new \Exception( __( 'Google API key is not configured.', 'ai-media-seo' ) );
		}

		// Get image URL.
		$image_url = $this->get_image_url( $attachment_id );
		if ( ! $image_url ) {
			throw new \Exception( __( 'Could not get image URL.', 'ai-media-seo' ) );
		}

		// Get image data as base64.
		$image_data = $this->get_image_base64( $image_url );
		if ( ! $image_data ) {
			throw new \Exception( __( 'Could not load image data.', 'ai-media-seo' ) );
		}

		// Build prompt.
		$prompt = $this->build_prompt( $language, $context );

		// Make API request.
		$response = $this->make_api_request( $image_data['base64'], $image_data['mime_type'], $prompt );

		// Parse and return response.
		$parsed = $this->parse_response( $response );

		// Extract token usage from API response.
		$usage_metadata = $response['usageMetadata'] ?? array();
		$input_tokens   = isset( $usage_metadata['promptTokenCount'] ) ? (int) $usage_metadata['promptTokenCount'] : null;
		$output_tokens  = isset( $usage_metadata['candidatesTokenCount'] ) ? (int) $usage_metadata['candidatesTokenCount'] : null;

		// Google sometimes doesn't return input tokens, estimate if missing.
		$estimated_input = false;
		if ( null === $input_tokens ) {
			$token_estimator = new \AIMediaSEO\Pricing\TokenEstimator();
			$input_tokens    = $token_estimator->estimate_input_tokens( $attachment_id, 'google', $prompt );
			$estimated_input = true;
		}

		// Calculate costs if we have token data.
		if ( null !== $input_tokens && null !== $output_tokens ) {
			$cost_calculator = new \AIMediaSEO\Pricing\CostCalculator();
			$cost_data       = $cost_calculator->calculate_cost( $this->model, $input_tokens, $output_tokens );

			if ( $cost_data['success'] ) {
				$parsed['token_data'] = array(
					'input_tokens'    => $input_tokens,
					'output_tokens'   => $output_tokens,
					'estimated_input' => $estimated_input,
					'input_cost'      => $cost_data['input_cost'],
					'output_cost'     => $cost_data['output_cost'],
					'total_cost'      => $cost_data['total_cost'],
				);
			} else {
				error_log( sprintf(
					'[GoogleProvider] Failed to calculate cost for model %s: %s. Input tokens: %d, Output tokens: %d',
					$this->model,
					$cost_data['error'] ?? 'Unknown error',
					$input_tokens,
					$output_tokens
				) );
			}
		} else {
			error_log( sprintf(
				'[GoogleProvider] Missing token data for model %s. Input: %s, Output: %s',
				$this->model,
				$input_tokens === null ? 'NULL' : $input_tokens,
				$output_tokens === null ? 'NULL' : $output_tokens
			) );
		}

		return $parsed;
	}

	/**
	 * Build prompt.
	 *
	 * @since 1.0.0
	 * @param string $language Language code.
	 * @param array  $context  Context data.
	 * @return string
	 */
	private function build_prompt( string $language, array $context ): string {
		$settings = get_option( 'ai_media_seo_settings', array() );
		$ai_role = $settings['ai_role'] ?? 'SEO expert';
		$site_context = $settings['site_context'] ?? '';

		// Legacy site_topic support.
		$site_topic = $context['site_topic'] ?? get_option( 'ai_media_site_topic', '' );

		$prompt = "You are a {$ai_role} analyzing images for WordPress websites.\n\n";

		// Add site context if available.
		if ( ! empty( $site_context ) ) {
			$prompt .= "Site context: {$site_context}\n\n";
		} elseif ( ! empty( $site_topic ) ) {
			$prompt .= "Site context: {$site_topic}\n\n";
		}

		if ( ! empty( $context['post_title'] ) ) {
			$prompt .= "Post title: {$context['post_title']}\n";
		}

		if ( ! empty( $context['categories'] ) ) {
			$prompt .= "Categories: " . implode( ', ', $context['categories'] ) . "\n";
		}

		if ( ! empty( $context['tags'] ) ) {
			$prompt .= "Tags: " . implode( ', ', $context['tags'] ) . "\n";
		}

		$prompt .= "\nTask: Generate SEO-optimized metadata for this image in {$language} language:\n\n";
		$prompt .= "1. ALT text (max 125 characters, descriptive, no 'image of')\n";
		$prompt .= "2. Caption (1-2 sentences, contextual)\n";
		$prompt .= "3. Title (3-6 words, factual)\n";
		$prompt .= "4. Keywords (3-6 relevant terms)\n\n";
		$prompt .= "Respond ONLY with valid JSON in this exact format:\n";
		$prompt .= '{"alt":"...","caption":"...","title":"...","keywords":["..."],"score":0.95}';

		return $prompt;
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
			throw new \Exception( $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code !== 200 ) {
			$error_message = $data['error']['message'] ?? 'Unknown error';
			throw new \Exception( "Google API error ({$status_code}): {$error_message}" );
		}

		// Log usage metadata if available.
		if ( ! empty( $data['usageMetadata'] ) ) {
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
		// Log the full response structure for debugging.

		if ( empty( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			throw new \Exception( __( 'Invalid response from Google API.', 'ai-media-seo' ) );
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
	 * Get image URL.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID.
	 * @return string|false Image URL or false on failure.
	 */
	private function get_image_url( int $attachment_id ) {
		$image_url = wp_get_attachment_image_url( $attachment_id, 'full' );

		if ( ! $image_url ) {
			return false;
		}

		// Resize if needed (max 1600px).
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $metadata['width'] ) && $metadata['width'] > 1600 ) {
			$image_url = wp_get_attachment_image_url( $attachment_id, 'large' );
		}

		return $image_url;
	}

	/**
	 * Get image as base64.
	 *
	 * @since 1.0.0
	 * @param string $image_url Image URL.
	 * @return array|false Array with base64 and mime_type, or false on failure.
	 */
	private function get_image_base64( string $image_url ) {
		$response = wp_remote_get( $image_url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$image_data = wp_remote_retrieve_body( $response );
		$mime_type  = wp_remote_retrieve_header( $response, 'content-type' );

		if ( empty( $image_data ) ) {
			return false;
		}

		return array(
			'base64'    => base64_encode( $image_data ),
			'mime_type' => $mime_type,
		);
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
			throw new \Exception( __( 'Google API key is required.', 'ai-media-seo' ) );
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
