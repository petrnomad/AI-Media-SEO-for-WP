<?php
/**
 * Models.dev API Parser
 *
 * Fetches and parses AI model pricing from models.dev API.
 *
 * @package    AIMediaSEO
 * @subpackage Pricing
 * @since      1.2.0
 */

namespace AIMediaSEO\Pricing;

/**
 * ModelsDevParser class.
 *
 * Handles fetching and parsing pricing data from models.dev API.
 *
 * @since 1.2.0
 */
class ModelsDevParser {

	/**
	 * Models.dev API URL.
	 *
	 * @var string
	 */
	private $api_url = 'https://models.dev/api.json';

	/**
	 * Maximum retry attempts.
	 *
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * Retry delay in seconds (exponential backoff).
	 *
	 * @var int
	 */
	private $retry_delay = 2;

	/**
	 * Supported providers to fetch from API.
	 *
	 * @var array
	 */
	private $supported_providers = array( 'anthropic', 'openai', 'google' );

	/**
	 * Fetch pricing data from models.dev API.
	 *
	 * @since 1.2.0
	 * @return array Parsed pricing data or empty array on failure.
	 */
	public function fetch_pricing_data(): array {
		$attempts = 0;
		$last_error = null;

		while ( $attempts < $this->max_retries ) {
			try {
				// Fetch JSON from API.
				$json = $this->fetch_json_from_api();

				if ( false === $json ) {
					throw new \Exception( 'Failed to fetch JSON from models.dev API.' );
				}

				// Parse JSON response.
				$pricing_data = $this->parse_json_response( $json );

				if ( ! empty( $pricing_data ) ) {
					return $pricing_data;
				}

				throw new \Exception( 'No pricing data found in API response.' );

			} catch ( \Exception $e ) {
				$last_error = $e->getMessage();
				$attempts++;

				if ( $attempts < $this->max_retries ) {
					// Exponential backoff.
					sleep( $this->retry_delay * $attempts );
				}
			}
		}

		// Log final error.
		error_log( sprintf(
			'[ModelsDevParser] Failed to fetch pricing after %d attempts. Last error: %s',
			$this->max_retries,
			$last_error
		) );

		return array();
	}

	/**
	 * Fetch JSON from models.dev API.
	 *
	 * @since 1.2.0
	 * @return string|false JSON string or false on failure.
	 */
	private function fetch_json_from_api() {
		$response = wp_remote_get(
			$this->api_url,
			array(
				'timeout'     => 15,
				'user-agent'  => 'AI-Media-SEO-Plugin/' . AI_MEDIA_SEO_VERSION,
				'httpversion' => '1.1',
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return false;
		}

		return $body;
	}

	/**
	 * Parse JSON response from models.dev API.
	 *
	 * Dynamically loads ALL models from supported providers.
	 *
	 * @since 1.2.0
	 * @param string $json JSON string from API.
	 * @return array Parsed pricing data.
	 */
	private function parse_json_response( string $json ): array {
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log( '[ModelsDevParser] JSON decode error: ' . json_last_error_msg() );
			return array();
		}

		if ( ! is_array( $data ) ) {
			return array();
		}

		$pricing_data = array();
		$total_loaded = 0;

		// Parse each supported provider.
		foreach ( $this->supported_providers as $provider ) {
			if ( ! isset( $data[ $provider ]['models'] ) ) {
				error_log( sprintf(
					'[ModelsDevParser] Provider %s not found in API response.',
					$provider
				) );
				continue;
			}

			$provider_models = $data[ $provider ]['models'];

			if ( ! is_array( $provider_models ) ) {
				error_log( sprintf(
					'[ModelsDevParser] Invalid models data for provider %s.',
					$provider
				) );
				continue;
			}

			// Load ALL models from this provider.
			foreach ( $provider_models as $model_id => $model_data ) {
				// Skip models without cost data.
				if ( ! isset( $model_data['cost'] ) || empty( $model_data['cost'] ) ) {
					continue;
				}

				$cost = $model_data['cost'];

				// Validate required pricing fields.
				if ( ! isset( $cost['input'] ) || ! isset( $cost['output'] ) ) {
					error_log( sprintf(
						'[ModelsDevParser] Missing input/output pricing for model %s in provider %s.',
						$model_id,
						$provider
					) );
					continue;
				}

				// Skip models that don't support image input.
				if ( ! isset( $model_data['modalities']['input'] ) ||
					! is_array( $model_data['modalities']['input'] ) ||
					! in_array( 'image', $model_data['modalities']['input'], true ) ) {
					continue;
				}

				// Use model_id as-is for internal ID.
				$pricing_data[ $model_id ] = array(
					'model_name'                     => $model_id,
					'provider'                       => $provider,
					'input_price_per_million'        => floatval( $cost['input'] ?? 0 ),
					'output_price_per_million'       => floatval( $cost['output'] ?? 0 ),
					'cache_read_price_per_million'   => isset( $cost['cache_read'] ) ? floatval( $cost['cache_read'] ) : null,
					'cache_write_price_per_million'  => isset( $cost['cache_write'] ) ? floatval( $cost['cache_write'] ) : null,
					'source'                         => 'models.dev',
				);

				$total_loaded++;
			}
		}

		if ( $total_loaded > 0 ) {
			error_log( sprintf(
				'[ModelsDevParser] Successfully loaded %d models from API (Anthropic + OpenAI + Google).',
				$total_loaded
			) );
		}

		return $pricing_data;
	}

	/**
	 * Get supported providers.
	 *
	 * @since 1.2.0
	 * @return array Supported providers list.
	 */
	public function get_supported_providers(): array {
		return $this->supported_providers;
	}

	/**
	 * Set API URL (for testing).
	 *
	 * @since 1.2.0
	 * @param string $url API URL.
	 */
	public function set_api_url( string $url ): void {
		$this->api_url = $url;
	}
}
