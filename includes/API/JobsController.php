<?php
/**
 * Jobs REST API Controller
 *
 * Handles jobs and statistics operations.
 *
 * @package    AIMediaSEO
 * @subpackage API
 * @since      1.0.0
 */

namespace AIMediaSEO\API;

use AIMediaSEO\Storage\MetadataStore;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * JobsController class.
 *
 * Provides REST API endpoints for jobs and statistics.
 *
 * @since 1.0.0
 */
class JobsController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-media/v1';

	/**
	 * Metadata store.
	 *
	 * @var MetadataStore
	 */
	private $metadata_store;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->metadata_store = new MetadataStore();
	}

	/**
	 * Register routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		// Get jobs.
		register_rest_route(
			$this->namespace,
			'/jobs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_jobs' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
					'args'                => $this->get_jobs_args(),
				),
			)
		);

		// Get stats.
		register_rest_route(
			$this->namespace,
			'/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_stats' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
				),
			)
		);

		// Check queue status for specific attachments.
		register_rest_route(
			$this->namespace,
			'/queue-status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_queue_status' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
					'args'                => array(
						'attachment_ids' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Comma-separated list of attachment IDs',
						),
					),
				),
			)
		);
	}

	/**
	 * Get jobs.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_jobs( WP_REST_Request $request ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		$status = $request->get_param( 'status' );
		$language = $request->get_param( 'language' );
		$attachment_ids_str = $request->get_param( 'attachment_ids' );
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$offset = ( $page - 1 ) * $per_page;

		$jobs = array();

		// Parse attachment IDs if provided.
		$attachment_ids = array();
		if ( ! empty( $attachment_ids_str ) ) {
			$attachment_ids = array_map( 'intval', explode( ',', $attachment_ids_str ) );
			$attachment_ids = array_filter( $attachment_ids ); // Remove zeros/empty values.
		}

		// Treat 'all' as empty (show everything).
		if ( $status === 'all' ) {
			$status = '';
		}

		// If status is 'pending' or 'processing', only get from Action Scheduler.
		if ( $status === 'pending' || $status === 'processing' ) {
			$as_jobs = $this->get_action_scheduler_jobs( $language, $status );
			$jobs = $as_jobs;
		} else {
			// Get jobs from database.
			$where = array( '1=1' );
			$values = array();

			// Filter by specific status if provided.
			if ( ! empty( $status ) ) {
				$where[] = 'status = %s';
				$values[] = $status;
			}

			if ( ! empty( $language ) ) {
				$where[] = 'language_code = %s';
				$values[] = $language;
			}

			// Filter by attachment IDs if provided.
			if ( ! empty( $attachment_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
				$where[] = "attachment_id IN ({$placeholders})";
				$values = array_merge( $values, $attachment_ids );
			}

			$where_clause = implode( ' AND ', $where );

			$db_jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name}
					WHERE {$where_clause}
					ORDER BY created_at DESC
					LIMIT %d OFFSET %d",
					array_merge( $values, array( $per_page, $offset ) )
				),
				ARRAY_A
			);

			// Decode JSON fields.
			foreach ( $db_jobs as &$job ) {
				if ( ! empty( $job['request_data'] ) ) {
					$job['request_data'] = json_decode( $job['request_data'], true );
				}

				if ( ! empty( $job['response_data'] ) ) {
					$job['response_data'] = json_decode( $job['response_data'], true );
				}
			}

			$jobs = $db_jobs;

			// If showing all (no status filter), also include Action Scheduler jobs.
			if ( empty( $status ) ) {
				$as_jobs = $this->get_action_scheduler_jobs( $language );
				$jobs = array_merge( $as_jobs, $jobs );
			}
		}

		// Sort by created_at.
		usort( $jobs, function( $a, $b ) {
			$time_a = strtotime( $a['created_at'] ?? '0' );
			$time_b = strtotime( $b['created_at'] ?? '0' );
			return $time_b - $time_a;
		});

		// Apply pagination.
		$total = count( $jobs );
		$jobs = array_slice( $jobs, $offset, $per_page );

		return new WP_REST_Response(
			array(
				'jobs'       => $jobs,
				'pagination' => array(
					'total'     => $total,
					'page'      => $page,
					'per_page'  => $per_page,
					'total_pages' => ceil( $total / $per_page ),
				),
			),
			200
		);
	}

	/**
	 * Get jobs from Action Scheduler.
	 *
	 * @since 1.0.0
	 * @param string $language     Language filter.
	 * @param string $status_filter Status filter ('pending', 'processing', or empty for both).
	 * @return array Array of jobs.
	 */
	private function get_action_scheduler_jobs( $language = '', $status_filter = '' ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		$jobs = array();

		// Determine which Action Scheduler statuses to fetch.
		$as_statuses = array();
		if ( $status_filter === 'pending' ) {
			$as_statuses = array( 'pending' );
		} elseif ( $status_filter === 'processing' ) {
			$as_statuses = array( 'in-progress' );
		} else {
			// Default: get both pending and in-progress.
			$as_statuses = array( 'pending', 'in-progress' );
		}

		// Get actions from Action Scheduler.
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => 'ai_media_process_single',
				'status'   => $as_statuses,
				'per_page' => 100,
			),
			'ids'
		);

		foreach ( $actions as $action_id ) {
			$action = \ActionScheduler::store()->fetch_action( $action_id );
			$args = $action->get_args();

			$attachment_id = $args['attachment_id'] ?? 0;
			$job_language = $args['language'] ?? '';

			// Filter by language if specified.
			if ( ! empty( $language ) && $job_language !== $language ) {
				continue;
			}

			// Check if already in database (started processing).
			global $wpdb;
			$table_name = $wpdb->prefix . 'ai_media_jobs';
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_name}
					WHERE attachment_id = %d
					AND language_code = %s
					AND status = 'processing'
					ORDER BY created_at DESC
					LIMIT 1",
					$attachment_id,
					$job_language
				)
			);

			if ( $existing ) {
				continue; // Already in database as processing.
			}

			$status_label = \ActionScheduler::store()->get_status( $action_id );
			$status = $status_label === 'in-progress' ? 'processing' : 'pending';

			$jobs[] = array(
				'id'             => 'as_' . $action_id,
				'attachment_id'  => $attachment_id,
				'language_code'  => $job_language,
				'status'         => $status,
				'provider'       => null,
				'model'          => null,
				'score'          => null,
				'created_at'     => $action->get_schedule()->get_date()->format( 'Y-m-d H:i:s' ),
				'processed_at'   => null,
				'approved_at'    => null,
				'error_message'  => null,
				'is_action_scheduler' => true,
			);
		}

		return $jobs;
	}

	/**
	 * Get queue status for specific attachments.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_queue_status( WP_REST_Request $request ) {
		$attachment_ids_str = $request->get_param( 'attachment_ids' );
		$attachment_ids = array_map( 'intval', explode( ',', $attachment_ids_str ) );

		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		// Get all pending and in-progress actions.
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => 'ai_media_process_single',
				'status'   => array( 'pending', 'in-progress' ),
				'per_page' => 100,
			),
			'ids'
		);

		$queued_attachments = array();

		foreach ( $actions as $action_id ) {
			$action = \ActionScheduler::store()->fetch_action( $action_id );
			$args = $action->get_args();
			$attachment_id = $args['attachment_id'] ?? 0;

			if ( in_array( $attachment_id, $attachment_ids, true ) ) {
				$status = \ActionScheduler::store()->get_status( $action_id );
				$queued_attachments[ $attachment_id ] = array(
					'in_queue' => true,
					'status'   => $status === 'in-progress' ? 'processing' : 'pending',
				);
			}
		}

		return new WP_REST_Response( $queued_attachments, 200 );
	}

	/**
	 * Get statistics.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_stats( WP_REST_Request $request ) {
		global $wpdb;

		// Get total number of image attachments.
		$total_images = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
		);

		// Count attachments by _ai_media_status from post_meta.
		$status_counts = $wpdb->get_results(
			"SELECT pm.meta_value as status, COUNT(DISTINCT pm.post_id) as count
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE pm.meta_key = '_ai_media_status'
			AND p.post_type = 'attachment'
			AND p.post_mime_type LIKE 'image/%'
			GROUP BY pm.meta_value",
			ARRAY_A
		);

		$stats = array(
			'total'          => $total_images,
			'pending'        => 0,
			'processing'     => 0,
			'needs_review'   => 0,
			'approved'       => 0,
			'failed'         => 0,
			'processed_today' => 0,
			'avg_score'      => 0.0,
			'total_cost'     => 0,
		);

		// Map status counts.
		$images_with_status = 0;
		foreach ( $status_counts as $row ) {
			$status = $row['status'];
			$count = (int) $row['count'];
			$images_with_status += $count;

			if ( $status === 'pending' ) {
				$stats['pending'] = $count;
			} elseif ( $status === 'processing' ) {
				$stats['processing'] = $count;
			} elseif ( $status === 'processed' ) {
				$stats['needs_review'] = $count;
			} elseif ( $status === 'approved' ) {
				$stats['approved'] = $count;
			} elseif ( $status === 'failed' ) {
				$stats['failed'] = $count;
			}
		}

		// Images without _ai_media_status meta are considered 'pending'.
		$images_without_status = $total_images - $images_with_status;
		if ( $images_without_status > 0 ) {
			$stats['pending'] += $images_without_status;
		}

		// Add jobs from Action Scheduler (pending/processing).
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$as_jobs = as_get_scheduled_actions(
				array(
					'hook'     => 'ai_media_process_single',
					'status'   => array( 'pending', 'in-progress' ),
					'per_page' => 1000,
				),
				'ids'
			);
			$stats['processing'] += count( $as_jobs );
		}

		// Get performance metrics from jobs table.
		$table_name = $wpdb->prefix . 'ai_media_jobs';
		$metrics = $wpdb->get_row(
			"SELECT
				COUNT(*) as processed_today,
				AVG(score) as avg_score,
				SUM(cost_cents) as total_cost
			FROM {$table_name}
			WHERE DATE(created_at) = CURDATE()
			AND status IN ('approved', 'processed')",
			ARRAY_A
		);

		if ( $metrics ) {
			$stats['processed_today'] = (int) $metrics['processed_today'];
			$stats['avg_score'] = $metrics['avg_score'] ? (float) $metrics['avg_score'] : 0.0;
			$stats['total_cost'] = $metrics['total_cost'] ? (int) $metrics['total_cost'] : 0;
		}

		return new WP_REST_Response( $stats, 200 );
	}

	/**
	 * Check read permission.
	 *
	 * @since 1.0.0
	 * @return bool True if user has permission.
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'ai_media_process_images' );
	}

	/**
	 * Get jobs endpoint arguments.
	 *
	 * @since 1.0.0
	 * @return array Arguments schema.
	 */
	private function get_jobs_args(): array {
		return array(
			'status' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'language' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'attachment_ids' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => 'Comma-separated list of attachment IDs',
			),
			'page' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 50,
				'sanitize_callback' => 'absint',
			),
		);
	}
}
