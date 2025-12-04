<?php
/**
 * Pricing Synchronizer
 *
 * Fetches and updates AI model pricing from external CSV source.
 *
 * @package    AIMediaSEO
 * @subpackage Pricing
 * @since      1.1.0
 */

namespace AIMediaSEO\Pricing;

/**
 * PricingSynchronizer class.
 *
 * Handles daily synchronization of model pricing data.
 *
 * @since 1.1.0
 */
class PricingSynchronizer {

	/**
	 * CSV source URL.
	 *
	 * @var string
	 */
	private $csv_url = 'https://gist.githubusercontent.com/t3dotgg/a4bb252e590320e223e71c595e60e6be/raw/21a5b689cd5f4a4944df58893e5a0a4db43a5481/model-prices.csv';

	/**
	 * Model name mapping (CSV name => internal model identifier).
	 *
	 * @var array
	 */
	private $model_map = array(
		// OpenAI.
		'ChatGPT 4o'      => 'gpt-4o',
		'ChatGPT 4o-mini' => 'gpt-4o-mini',
		'ChatGPT 4.1'     => 'gpt-4-turbo',

		// Anthropic.
		'Claude Sonnet 4.5' => 'claude-sonnet-4-5-20250929',
		'Claude Haiku 4.5'  => 'claude-haiku-4-5-20251001',
		'Claude Opus 4.1'   => 'claude-opus-4-1-20250805',
		'Claude 3.5 Haiku'  => 'claude-3-5-haiku-20241022',

		// Google.
		'Gemini 1.5 Flash-8B' => 'gemini-1.5-flash-8b',
		'Gemini 1.5 Flash'    => 'gemini-1.5-flash',
		'Gemini 2.0 Flash'    => 'gemini-2.0-flash',
		'Gemini 2.5 Flash'    => 'gemini-2.5-flash',
		'Gemini 2.5 Pro'      => 'gemini-2.5-pro',
	);

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Sync pricing data from external sources.
	 *
	 * Tries models.dev API first, falls back to CSV on failure.
	 *
	 * @since 1.1.0
	 * @return array Sync results with status and errors.
	 */
	public function sync_pricing(): array {
		$results = array(
			'success'       => false,
			'models_synced' => 0,
			'errors'        => array(),
			'timestamp'     => current_time( 'mysql' ),
			'source'        => null,
		);

		// Check if sync is already in progress (simple lock mechanism).
		$lock_key = 'ai_media_pricing_sync_lock';
		if ( get_transient( $lock_key ) ) {
			$results['errors'][] = 'Sync already in progress.';
			return $results;
		}

		// Set lock for 5 minutes.
		set_transient( $lock_key, time(), 5 * MINUTE_IN_SECONDS );

		try {
			$pricing_data = array();

			// Try models.dev API first.
			$models_dev_parser = new \AIMediaSEO\Pricing\ModelsDevParser();
			$pricing_data = $models_dev_parser->fetch_pricing_data();

			if ( ! empty( $pricing_data ) ) {
				$results['source'] = 'models.dev';
			} else {
				// Fallback to CSV.
				$csv_content = $this->fetch_csv();

				if ( false !== $csv_content ) {
					$pricing_data = $this->parse_csv( $csv_content );
					$results['source'] = 'csv';
				}
			}

			if ( empty( $pricing_data ) ) {
				throw new \Exception( 'No pricing data available from any source.' );
			}

			// Update database with new schema.
			$updated = $this->update_database_v2( $pricing_data );

			if ( $updated ) {
				$results['success']       = true;
				$results['models_synced'] = count( $pricing_data );
				$results['models']        = array_keys( $pricing_data );

				// Update last sync time and source.
				update_option( 'ai_media_pricing_last_sync', time() );
				update_option( 'ai_media_pricing_source', $results['source'] );
			} else {
				throw new \Exception( 'Failed to update database with pricing data.' );
			}
		} catch ( \Exception $e ) {
			$results['errors'][] = $e->getMessage();
		} finally {
			// Release lock.
			delete_transient( $lock_key );
		}

		return $results;
	}

	/**
	 * Fetch CSV content from remote URL.
	 *
	 * @since 1.1.0
	 * @return string|false CSV content or false on failure.
	 */
	private function fetch_csv() {
		$response = wp_remote_get(
			$this->csv_url,
			array(
				'timeout'     => 15,
				'user-agent'  => 'AI-Media-SEO-Plugin/' . AI_MEDIA_SEO_VERSION,
				'httpversion' => '1.1',
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $response_code ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return false;
		}

		return $body;
	}

	/**
	 * Parse CSV content into pricing array.
	 *
	 * @since 1.1.0
	 * @param string $csv_content Raw CSV content.
	 * @return array Parsed pricing data.
	 */
	public function parse_csv( string $csv_content ): array {
		$pricing_data = array();

		// Parse CSV.
		$lines = str_getcsv( $csv_content, "\n" );

		if ( empty( $lines ) ) {
			return $pricing_data;
		}

		// Get headers.
		$headers = str_getcsv( array_shift( $lines ) );

		// Expected headers: name, input_cost_per_million, output_cost_per_million.
		if ( count( $headers ) < 3 ) {
			return $pricing_data;
		}

		foreach ( $lines as $line ) {
			$row = str_getcsv( $line );

			if ( count( $row ) < 3 ) {
				continue;
			}

			$csv_model_name = trim( $row[0] );

			// Clean price strings - remove $ and convert comma to dot
			$input_price_str  = str_replace( array( '$', ',' ), array( '', '.' ), trim( $row[1] ) );
			$output_price_str = str_replace( array( '$', ',' ), array( '', '.' ), trim( $row[2] ) );

			$input_price  = floatval( $input_price_str );
			$output_price = floatval( $output_price_str );

			// Skip if not in our model map.
			if ( ! isset( $this->model_map[ $csv_model_name ] ) ) {
				continue;
			}

			$internal_model = $this->model_map[ $csv_model_name ];

			// Validate prices.
			if ( $input_price < 0 || $output_price < 0 ) {
				continue;
			}

			$pricing_data[ $internal_model ] = array(
				'model_name'                  => $internal_model,
				'input_price_per_million'     => $input_price,
				'output_price_per_million'    => $output_price,
				'source'                      => 'csv',
			);
		}

		return $pricing_data;
	}

	/**
	 * Update database with pricing data (legacy method for CSV).
	 *
	 * @since 1.1.0
	 * @param array $pricing_data Pricing data array.
	 * @return bool True on success.
	 */
	public function update_database( array $pricing_data ): bool {
		if ( empty( $pricing_data ) ) {
			return false;
		}

		$table_name = $this->wpdb->prefix . 'ai_media_pricing';
		$success    = true;

		foreach ( $pricing_data as $model_data ) {
			$result = $this->wpdb->replace(
				$table_name,
				array(
					'model_name'                  => $model_data['model_name'],
					'input_price_per_million'     => $model_data['input_price_per_million'],
					'output_price_per_million'    => $model_data['output_price_per_million'],
					'last_updated'                => current_time( 'mysql' ),
				),
				array(
					'%s', // model_name.
					'%f', // input_price_per_million.
					'%f', // output_price_per_million.
					'%s', // last_updated.
				)
			);

			if ( false === $result ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Update database with pricing data (v2 with cache support).
	 *
	 * @since 1.2.0
	 * @param array $pricing_data Pricing data array.
	 * @return bool True on success.
	 */
	private function update_database_v2( array $pricing_data ): bool {
		if ( empty( $pricing_data ) ) {
			return false;
		}

		$table_name = $this->wpdb->prefix . 'ai_media_pricing';
		$success    = true;

		foreach ( $pricing_data as $model_data ) {
			$data = array(
				'model_name'                     => $model_data['model_name'],
				'input_price_per_million'        => $model_data['input_price_per_million'],
				'output_price_per_million'       => $model_data['output_price_per_million'],
				'last_updated'                   => current_time( 'mysql' ),
			);

			$format = array( '%s', '%f', '%f', '%s' );

			// Add optional fields if present.
			if ( isset( $model_data['provider'] ) ) {
				$data['provider'] = $model_data['provider'];
				$format[] = '%s';
			}

			if ( isset( $model_data['cache_read_price_per_million'] ) ) {
				$data['cache_read_price_per_million'] = $model_data['cache_read_price_per_million'];
				$format[] = '%f';
			}

			if ( isset( $model_data['cache_write_price_per_million'] ) ) {
				$data['cache_write_price_per_million'] = $model_data['cache_write_price_per_million'];
				$format[] = '%f';
			}

			if ( isset( $model_data['source'] ) ) {
				$data['source'] = $model_data['source'];
				$format[] = '%s';
			}

			$result = $this->wpdb->replace( $table_name, $data, $format );

			if ( false === $result ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Get last sync timestamp.
	 *
	 * @since 1.1.0
	 * @return string|null Last sync time or null.
	 */
	public function get_last_sync_time(): ?string {
		$last_sync = get_option( 'ai_media_pricing_last_sync', null );

		if ( $last_sync ) {
			return gmdate( 'Y-m-d H:i:s', $last_sync );
		}

		return null;
	}

	/**
	 * Schedule daily sync (called from activation).
	 *
	 * @since 1.1.0
	 */
	public function schedule_daily_sync(): void {
		if ( ! wp_next_scheduled( 'ai_media_seo_sync_pricing' ) ) {
			wp_schedule_event(
				strtotime( 'tomorrow 3:00 AM' ),
				'daily',
				'ai_media_seo_sync_pricing'
			);
		}
	}

	/**
	 * Get model name mapping.
	 *
	 * @since 1.1.0
	 * @return array Model map.
	 */
	public function get_model_map(): array {
		return $this->model_map;
	}
}
