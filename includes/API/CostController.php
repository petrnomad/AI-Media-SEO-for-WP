<?php
/**
 * Cost Controller
 *
 * REST API endpoints for cost tracking and statistics.
 *
 * @package    AIMediaSEO
 * @subpackage API
 * @since      1.1.0
 */

namespace AIMediaSEO\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * CostController class.
 *
 * Provides REST API endpoints for cost data.
 *
 * @since 1.1.0
 */
class CostController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-media/v1';

	/**
	 * Rest base.
	 *
	 * @var string
	 */
	protected $rest_base = 'costs';

	/**
	 * Cost calculator instance.
	 *
	 * @var \AIMediaSEO\Pricing\CostCalculator
	 */
	private $cost_calculator;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->cost_calculator = new \AIMediaSEO\Pricing\CostCalculator();
	}

	/**
	 * Register routes.
	 *
	 * @since 1.1.0
	 */
	public function register_routes() {
		// GET /ai-media/v1/costs/total
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/total',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_total_cost' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_filter_params(),
				),
			)
		);

		// GET /ai-media/v1/costs/breakdown
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/breakdown',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_cost_breakdown' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_filter_params(),
				),
			)
		);

		// GET /ai-media/v1/costs/stats
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_cost_stats' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// GET /ai-media/v1/costs/pricing
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/pricing',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pricing' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// POST /ai-media/v1/costs/pricing/sync
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/pricing/sync',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sync_pricing' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
				),
			)
		);
	}

	/**
	 * Get total cost.
	 *
	 * @since 1.1.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_total_cost( WP_REST_Request $request ) {
		$filters = $this->parse_filters( $request );

		global $wpdb;
		$table_name = $wpdb->prefix . 'ai_media_jobs';

		$where_clauses = array( 'total_cost IS NOT NULL' );
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

		if ( ! empty( $filters['provider'] ) ) {
			$where_clauses[] = 'provider = %s';
			$where_values[]  = $filters['provider'];
		}

		$where_sql = implode( ' AND ', $where_clauses );

		if ( ! empty( $where_values ) ) {
			$where_sql = $wpdb->prepare( $where_sql, $where_values );
		}

		$query = "SELECT
			COUNT(*) as total_jobs,
			SUM(total_cost) as total_cost
		FROM {$table_name}
		WHERE {$where_sql}";

		$result = $wpdb->get_row( $query, ARRAY_A );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'total_cost' => round( floatval( $result['total_cost'] ?? 0 ), 8 ),
				'total_jobs' => (int) ( $result['total_jobs'] ?? 0 ),
				'currency'   => 'USD',
				'filters'    => $filters,
			),
			200
		);
	}

	/**
	 * Get cost breakdown.
	 *
	 * @since 1.1.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_cost_breakdown( WP_REST_Request $request ) {
		$filters   = $this->parse_filters( $request );
		$breakdown = $this->cost_calculator->get_cost_breakdown( $filters );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'by_model'    => $breakdown['by_model'],
				'total_cost'  => $breakdown['total_cost'],
				'total_jobs'  => $breakdown['total_jobs'],
				'currency'    => 'USD',
				'filters'     => $filters,
			),
			200
		);
	}

	/**
	 * Get cost statistics.
	 *
	 * @since 1.1.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_cost_stats( WP_REST_Request $request ) {
		$stats = $this->cost_calculator->get_cost_stats();

		return new WP_REST_Response(
			array(
				'success'                 => true,
				'total_images_processed'  => $stats['total_images_processed'],
				'total_tokens_used'       => $stats['total_tokens_used'],
				'total_cost'              => $stats['total_cost'],
				'average_cost_per_image'  => $stats['average_cost_per_image'],
				'most_expensive_model'    => $stats['most_expensive_model'],
				'last_sync'               => $stats['last_sync'],
				'currency'                => 'USD',
			),
			200
		);
	}

	/**
	 * Parse filters from request.
	 *
	 * @since 1.1.0
	 * @param WP_REST_Request $request Request object.
	 * @return array Filters.
	 */
	private function parse_filters( WP_REST_Request $request ): array {
		$filters = array();

		if ( $request->has_param( 'date_from' ) ) {
			$filters['date_from'] = sanitize_text_field( $request->get_param( 'date_from' ) );
		}

		if ( $request->has_param( 'date_to' ) ) {
			$filters['date_to'] = sanitize_text_field( $request->get_param( 'date_to' ) );
		}

		if ( $request->has_param( 'status' ) ) {
			$filters['status'] = sanitize_text_field( $request->get_param( 'status' ) );
		}

		if ( $request->has_param( 'provider' ) ) {
			$filters['provider'] = sanitize_text_field( $request->get_param( 'provider' ) );
		}

		return $filters;
	}

	/**
	 * Get filter parameters schema.
	 *
	 * @since 1.1.0
	 * @return array Parameters.
	 */
	private function get_filter_params(): array {
		return array(
			'date_from' => array(
				'description'       => __( 'Filter by date from (Y-m-d H:i:s format).', 'ai-media-seo' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'date_to'   => array(
				'description'       => __( 'Filter by date to (Y-m-d H:i:s format).', 'ai-media-seo' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'    => array(
				'description'       => __( 'Filter by job status.', 'ai-media-seo' ),
				'type'              => 'string',
				'enum'              => array( 'pending', 'processing', 'needs_review', 'approved', 'failed', 'skipped' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'provider'  => array(
				'description'       => __( 'Filter by provider name.', 'ai-media-seo' ),
				'type'              => 'string',
				'enum'              => array( 'openai', 'anthropic', 'google' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Get pricing data.
	 *
	 * @since 1.2.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_pricing( WP_REST_Request $request ) {
		$pricing = $this->cost_calculator->get_all_pricing();

		// Get sync info.
		$last_sync = get_option( 'ai_media_pricing_last_sync', null );
		$source    = get_option( 'ai_media_pricing_source', 'unknown' );

		// Format last sync time.
		$last_sync_formatted = null;
		if ( $last_sync ) {
			$last_sync_formatted = gmdate( 'Y-m-d H:i:s', $last_sync );
		}

		// Group by provider.
		$by_provider = array(
			'openai'    => array(),
			'anthropic' => array(),
			'google'    => array(),
			'unknown'   => array(),
		);

		foreach ( $pricing as $model ) {
			$provider = $model['provider'] ?? 'unknown';
			if ( ! isset( $by_provider[ $provider ] ) ) {
				$by_provider[ $provider ] = array();
			}
			$by_provider[ $provider ][] = $model;
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'pricing'     => $pricing,
				'by_provider' => $by_provider,
				'last_sync'   => $last_sync_formatted,
				'source'      => $source,
				'currency'    => 'USD',
			),
			200
		);
	}

	/**
	 * Sync pricing data.
	 *
	 * @since 1.2.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function sync_pricing( WP_REST_Request $request ) {
		$synchronizer = new \AIMediaSEO\Pricing\PricingSynchronizer();
		$result       = $synchronizer->sync_pricing();

		$status_code = $result['success'] ? 200 : 500;

		return new WP_REST_Response(
			array(
				'success'       => $result['success'],
				'models_synced' => $result['models_synced'] ?? 0,
				'source'        => $result['source'] ?? null,
				'timestamp'     => $result['timestamp'] ?? null,
				'errors'        => $result['errors'] ?? array(),
				'models'        => $result['models'] ?? array(),
			),
			$status_code
		);
	}

	/**
	 * Check permission.
	 *
	 * @since 1.1.0
	 * @return bool True if user has permission.
	 */
	public function check_permission(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Check admin permission.
	 *
	 * @since 1.2.0
	 * @return bool True if user has admin permission.
	 */
	public function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}
}
