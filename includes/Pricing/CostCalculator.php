<?php
/**
 * Cost Calculator
 *
 * Calculates costs based on token usage and current model pricing.
 *
 * @package    AIMediaSEO
 * @subpackage Pricing
 * @since      1.1.0
 */

namespace AIMediaSEO\Pricing;

/**
 * CostCalculator class.
 *
 * Provides cost calculation and pricing retrieval.
 *
 * @since 1.1.0
 */
class CostCalculator {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Pricing cache.
	 *
	 * @var array
	 */
	private $pricing_cache = array();

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
	 * Calculate cost for given token usage.
	 *
	 * @since 1.1.0
	 * @param string $model              Model name.
	 * @param int    $input_tokens       Input token count.
	 * @param int    $output_tokens      Output token count.
	 * @param int    $cache_read_tokens  Cache read token count (optional).
	 * @param int    $cache_write_tokens Cache write token count (optional).
	 * @return array Cost breakdown or error.
	 */
	public function calculate_cost( string $model, int $input_tokens, int $output_tokens, int $cache_read_tokens = 0, int $cache_write_tokens = 0 ): array {
		// Get model pricing.
		$pricing = $this->get_model_pricing( $model );

		if ( null === $pricing ) {
			error_log( sprintf(
				'[CostCalculator] No pricing data found for model: %s. Available models need to be synced.',
				$model
			) );
			return array(
				'success'           => false,
				'input_cost'        => 0,
				'output_cost'       => 0,
				'cache_read_cost'   => 0,
				'cache_write_cost'  => 0,
				'total_cost'        => 0,
				'error'             => 'No pricing data available for this model.',
			);
		}

		// Calculate costs.
		// Formula: tokens * (price_per_million / 1,000,000)
		$input_cost  = $input_tokens * ( $pricing['input_price_per_million'] / 1000000 );
		$output_cost = $output_tokens * ( $pricing['output_price_per_million'] / 1000000 );

		// Calculate cache costs if pricing is available.
		$cache_read_cost  = 0;
		$cache_write_cost = 0;

		if ( $cache_read_tokens > 0 && ! empty( $pricing['cache_read_price_per_million'] ) ) {
			$cache_read_cost = $cache_read_tokens * ( $pricing['cache_read_price_per_million'] / 1000000 );
		}

		if ( $cache_write_tokens > 0 && ! empty( $pricing['cache_write_price_per_million'] ) ) {
			$cache_write_cost = $cache_write_tokens * ( $pricing['cache_write_price_per_million'] / 1000000 );
		}

		$total_cost = $input_cost + $output_cost + $cache_read_cost + $cache_write_cost;

		return array(
			'success'           => true,
			'input_cost'        => round( $input_cost, 8 ),
			'output_cost'       => round( $output_cost, 8 ),
			'cache_read_cost'   => round( $cache_read_cost, 8 ),
			'cache_write_cost'  => round( $cache_write_cost, 8 ),
			'total_cost'        => round( $total_cost, 8 ),
			'pricing'           => $pricing,
		);
	}

	/**
	 * Get pricing for specific model.
	 *
	 * @since 1.1.0
	 * @param string $model Model name.
	 * @return array|null Pricing data or null if not found.
	 */
	public function get_model_pricing( string $model ): ?array {
		// Check cache first.
		if ( isset( $this->pricing_cache[ $model ] ) ) {
			return $this->pricing_cache[ $model ];
		}

		$table_name = $this->wpdb->prefix . 'ai_media_pricing';

		$pricing = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE model_name = %s",
				$model
			),
			ARRAY_A
		);

		if ( ! $pricing ) {
			return null;
		}

		// Cache result.
		$this->pricing_cache[ $model ] = $pricing;

		return $pricing;
	}

	/**
	 * Calculate total cost from array of jobs.
	 *
	 * @since 1.1.0
	 * @param array $jobs Array of job data with token/cost info.
	 * @return float Total cost.
	 */
	public function calculate_total_cost( array $jobs ): float {
		$total = 0.0;

		foreach ( $jobs as $job ) {
			if ( isset( $job['total_cost'] ) ) {
				$total += floatval( $job['total_cost'] );
			}
		}

		return round( $total, 8 );
	}

	/**
	 * Get cost breakdown by model.
	 *
	 * Aggregates costs from jobs table grouped by model.
	 *
	 * @since 1.1.0
	 * @param array $filters Optional filters (date_from, date_to, status).
	 * @return array Cost breakdown by model.
	 */
	public function get_cost_breakdown( array $filters = array() ): array {
		$table_name = $this->wpdb->prefix . 'ai_media_jobs';

		$where_clauses = array( '1=1' );
		$where_values  = array();

		// Apply filters.
		if ( ! empty( $filters['date_from'] ) ) {
			$where_clauses[] = 'created_at >= %s';
			$where_values[]  = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where_clauses[] = 'created_at <= %s';
			$where_values[]  = $filters['date_to'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where_clauses[] = 'status = %s';
			$where_values[]  = $filters['status'];
		}

		$where_sql = implode( ' AND ', $where_clauses );

		if ( ! empty( $where_values ) ) {
			$where_sql = $this->wpdb->prepare( $where_sql, $where_values );
		}

		$query = "SELECT
			model,
			COUNT(*) as job_count,
			SUM(input_tokens) as total_input_tokens,
			SUM(output_tokens) as total_output_tokens,
			SUM(input_cost) as total_input_cost,
			SUM(output_cost) as total_output_cost,
			SUM(total_cost) as total_cost
		FROM {$table_name}
		WHERE {$where_sql}
		AND total_cost IS NOT NULL
		GROUP BY model
		ORDER BY total_cost DESC";

		$results = $this->wpdb->get_results( $query, ARRAY_A );

		$breakdown = array(
			'by_model'    => array(),
			'total_cost'  => 0.0,
			'total_jobs'  => 0,
		);

		foreach ( $results as $row ) {
			$model = $row['model'] ?? 'unknown';

			$breakdown['by_model'][ $model ] = array(
				'jobs'               => (int) $row['job_count'],
				'input_tokens'       => (int) $row['total_input_tokens'],
				'output_tokens'      => (int) $row['total_output_tokens'],
				'input_cost'         => round( floatval( $row['total_input_cost'] ), 8 ),
				'output_cost'        => round( floatval( $row['total_output_cost'] ), 8 ),
				'total_cost'         => round( floatval( $row['total_cost'] ), 8 ),
			);

			$breakdown['total_cost'] += floatval( $row['total_cost'] );
			$breakdown['total_jobs'] += (int) $row['job_count'];
		}

		$breakdown['total_cost'] = round( $breakdown['total_cost'], 8 );

		return $breakdown;
	}

	/**
	 * Get cost statistics.
	 *
	 * @since 1.1.0
	 * @return array Statistics including total costs, averages, etc.
	 */
	public function get_cost_stats(): array {
		$table_name = $this->wpdb->prefix . 'ai_media_jobs';

		$stats = $this->wpdb->get_row(
			"SELECT
				COUNT(*) as total_images,
				SUM(input_tokens) as total_input_tokens,
				SUM(output_tokens) as total_output_tokens,
				SUM(total_cost) as total_cost,
				AVG(total_cost) as avg_cost_per_image
			FROM {$table_name}
			WHERE total_cost IS NOT NULL",
			ARRAY_A
		);

		// Get most expensive model.
		$most_expensive = $this->wpdb->get_row(
			"SELECT model, SUM(total_cost) as model_cost
			FROM {$table_name}
			WHERE total_cost IS NOT NULL
			GROUP BY model
			ORDER BY model_cost DESC
			LIMIT 1",
			ARRAY_A
		);

		// Get last pricing sync time.
		$last_sync = get_option( 'ai_media_pricing_last_sync', null );
		$last_sync_formatted = null;

		if ( $last_sync ) {
			$last_sync_formatted = gmdate( 'Y-m-d H:i:s', $last_sync );
		}

		return array(
			'total_images_processed'  => (int) ( $stats['total_images'] ?? 0 ),
			'total_tokens_used'       => (int) ( $stats['total_input_tokens'] ?? 0 ) + (int) ( $stats['total_output_tokens'] ?? 0 ),
			'total_cost'              => round( floatval( $stats['total_cost'] ?? 0 ), 8 ),
			'average_cost_per_image'  => round( floatval( $stats['avg_cost_per_image'] ?? 0 ), 8 ),
			'most_expensive_model'    => $most_expensive['model'] ?? null,
			'last_sync'               => $last_sync_formatted,
		);
	}

	/**
	 * Get all available model pricing.
	 *
	 * @since 1.1.0
	 * @return array All pricing data.
	 */
	public function get_all_pricing(): array {
		$table_name = $this->wpdb->prefix . 'ai_media_pricing';

		$results = $this->wpdb->get_results(
			"SELECT * FROM {$table_name} ORDER BY model_name ASC",
			ARRAY_A
		);

		return $results ?? array();
	}

	/**
	 * Clear pricing cache.
	 *
	 * @since 1.1.0
	 */
	public function clear_cache(): void {
		$this->pricing_cache = array();
	}
}
