<?php
/**
 * Media Library REST API Controller
 *
 * Handles optimized media library listing with server-side pagination and filtering.
 *
 * @package    AIMediaSEO
 * @subpackage API
 * @since      1.0.0
 */

namespace AIMediaSEO\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;

/**
 * MediaLibraryController class.
 *
 * Provides optimized REST API endpoint for media library with server-side operations.
 *
 * @since 1.0.0
 */
class MediaLibraryController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-media/v1';

	/**
	 * Register routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/library',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_library_items' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
					'args'                => $this->get_library_args(),
				),
			)
		);
	}

	/**
	 * Get library items with server-side pagination and filtering.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_library_items( WP_REST_Request $request ) {
		global $wpdb;

		// Get and sanitize parameters.
		$page               = max( 1, (int) $request->get_param( 'page' ) );
		$per_page           = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$status             = sanitize_text_field( $request->get_param( 'status' ) );
		$attachment_filter  = sanitize_text_field( $request->get_param( 'attachment_filter' ) );
		$search             = sanitize_text_field( $request->get_param( 'search' ) );

		$offset = ( $page - 1 ) * $per_page;

		// Build WHERE conditions.
		$where_conditions = array( "p.post_type = 'attachment'" );
		$where_conditions[] = "p.post_mime_type LIKE 'image/%'";
		$prepare_values = array();

		// Status filter.
		if ( ! empty( $status ) && $status !== 'all' ) {
			$where_conditions[] = 'pm_status.meta_value = %s';
			$prepare_values[] = $status;
		}

		// Attachment filter (attached/unattached to posts).
		if ( ! empty( $attachment_filter ) && $attachment_filter !== 'all' ) {
			if ( $attachment_filter === 'attached' ) {
				$where_conditions[] = 'p.post_parent > 0';
			} elseif ( $attachment_filter === 'unattached' ) {
				$where_conditions[] = 'p.post_parent = 0';
			}
		}

		// Search filter.
		if ( ! empty( $search ) ) {
			$search_like = '%' . $wpdb->esc_like( $search ) . '%';
			$where_conditions[] = '(
				p.post_title LIKE %s OR
				pm_alt.meta_value LIKE %s OR
				pm_draft_alt.meta_value LIKE %s OR
				pm_draft_caption.meta_value LIKE %s OR
				pm_draft_title.meta_value LIKE %s
			)';
			$prepare_values[] = $search_like;
			$prepare_values[] = $search_like;
			$prepare_values[] = $search_like;
			$prepare_values[] = $search_like;
			$prepare_values[] = $search_like;
		}

		$where_clause = implode( ' AND ', $where_conditions );

		// Build main query with optimized jobs subquery.
		$jobs_table = $wpdb->prefix . 'ai_media_jobs';
		$jobs_subquery = $this->get_jobs_subquery( $jobs_table );

		$query = "
			SELECT
				p.ID,
				p.post_title,
				p.post_excerpt,
				p.post_content,
				p.guid as source_url,
				p.post_parent,
				p.post_date,
				p.post_mime_type as mime_type,
				pm_alt.meta_value as alt_text,
				pm_status.meta_value as _ai_media_status,
				pm_draft_alt.meta_value as _ai_media_draft_alt,
				pm_draft_caption.meta_value as _ai_media_draft_caption,
				pm_draft_title.meta_value as _ai_media_draft_title,
				parent_post.ID as parent_id,
				parent_post.post_title as parent_title,
				parent_post.post_type as parent_type,
				jobs.provider,
				jobs.model,
				jobs.score
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm_alt ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'
			LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_ai_media_status'
			LEFT JOIN {$wpdb->postmeta} pm_draft_alt ON p.ID = pm_draft_alt.post_id AND pm_draft_alt.meta_key = '_ai_media_draft_alt'
			LEFT JOIN {$wpdb->postmeta} pm_draft_caption ON p.ID = pm_draft_caption.post_id AND pm_draft_caption.meta_key = '_ai_media_draft_caption'
			LEFT JOIN {$wpdb->postmeta} pm_draft_title ON p.ID = pm_draft_title.post_id AND pm_draft_title.meta_key = '_ai_media_draft_title'
			LEFT JOIN {$wpdb->posts} parent_post ON p.post_parent = parent_post.ID
			{$jobs_subquery}
			WHERE {$where_clause}
			ORDER BY p.ID DESC
			LIMIT %d OFFSET %d
		";

		// Add limit and offset to prepare values.
		$prepare_values[] = $per_page;
		$prepare_values[] = $offset;

		// Execute query.
		$items = $wpdb->get_results(
			$wpdb->prepare( $query, $prepare_values ),
			ARRAY_A
		);

		// Count total items (for pagination).
		$count_query = "
			SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_ai_media_status'
			LEFT JOIN {$wpdb->postmeta} pm_alt ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'
			LEFT JOIN {$wpdb->postmeta} pm_draft_alt ON p.ID = pm_draft_alt.post_id AND pm_draft_alt.meta_key = '_ai_media_draft_alt'
			LEFT JOIN {$wpdb->postmeta} pm_draft_caption ON p.ID = pm_draft_caption.post_id AND pm_draft_caption.meta_key = '_ai_media_draft_caption'
			LEFT JOIN {$wpdb->postmeta} pm_draft_title ON p.ID = pm_draft_title.post_id AND pm_draft_title.meta_key = '_ai_media_draft_title'
			WHERE {$where_clause}
		";

		// Remove limit/offset from prepare values for count query.
		$count_prepare_values = array_slice( $prepare_values, 0, -2 );

		$total_items = (int) $wpdb->get_var(
			$wpdb->prepare( $count_query, $count_prepare_values )
		);

		// Format items to match WordPress REST API format.
		$formatted_items = array_map( array( $this, 'format_attachment' ), $items );

		// Calculate total pages.
		$total_pages = ceil( $total_items / $per_page );

		return new WP_REST_Response(
			array(
				'items'       => $formatted_items,
				'total'       => $total_items,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
			),
			200
		);
	}

	/**
	 * Format attachment data to match WordPress REST API format.
	 *
	 * @since 1.0.0
	 * @param array $attachment Raw attachment data from database.
	 * @return array Formatted attachment data.
	 */
	private function format_attachment( array $attachment ): array {
		$id = (int) $attachment['ID'];

		// Get attachment metadata (for image sizes, dimensions, etc.).
		$metadata = wp_get_attachment_metadata( $id );

		return array(
			'id'          => $id,
			'title'       => array(
				'rendered' => $attachment['post_title'] ?? '',
			),
			'caption'     => array(
				'rendered' => $attachment['post_excerpt'] ?? '',
			),
			'description' => array(
				'rendered' => $attachment['post_content'] ?? '',
			),
			'alt_text'    => $attachment['alt_text'] ?? '',
			'source_url'  => $attachment['source_url'] ?? '',
			'mime_type'   => $attachment['mime_type'] ?? '',
			'post'        => (int) $attachment['post_parent'],
			'parent'      => (int) $attachment['post_parent'],
			'parent_info' => ! empty( $attachment['parent_id'] ) ? array(
				'id'    => (int) $attachment['parent_id'],
				'title' => $attachment['parent_title'] ?? '',
				'type'  => $attachment['parent_type'] ?? '',
			) : null,
			'meta'        => array(
				'_ai_media_status'        => $attachment['_ai_media_status'] ?? 'pending',
				'_ai_media_draft_alt'     => $attachment['_ai_media_draft_alt'] ?? '',
				'_ai_media_draft_caption' => $attachment['_ai_media_draft_caption'] ?? '',
				'_ai_media_draft_title'   => $attachment['_ai_media_draft_title'] ?? '',
			),
			'provider'    => $attachment['provider'] ?? null,
			'model'       => $attachment['model'] ?? null,
			'score'       => $attachment['score'] !== null ? (float) $attachment['score'] : null,
			'media_details' => $metadata ?? array(),
		);
	}

	/**
	 * Get library endpoint arguments.
	 *
	 * @since 1.0.0
	 * @return array Endpoint arguments.
	 */
	private function get_library_args(): array {
		return array(
			'page'              => array(
				'description'       => 'Current page number.',
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'          => array(
				'description'       => 'Items per page.',
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'status'            => array(
				'description'       => 'Filter by status (all, pending, draft, approved).',
				'type'              => 'string',
				'default'           => 'all',
				'enum'              => array( 'all', 'pending', 'draft', 'approved' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'attachment_filter' => array(
				'description'       => 'Filter by attachment status (all, attached, unattached).',
				'type'              => 'string',
				'default'           => 'all',
				'enum'              => array( 'all', 'attached', 'unattached' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'            => array(
				'description'       => 'Search term to filter by title, alt text, caption, etc.',
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Get optimized jobs subquery.
	 *
	 * Uses window functions (ROW_NUMBER) for MySQL 8.0+ for better performance.
	 * Falls back to subquery with GROUP BY for older MySQL versions.
	 *
	 * @since 1.0.0
	 * @param string $jobs_table Jobs table name.
	 * @return string SQL subquery for jobs JOIN.
	 */
	private function get_jobs_subquery( string $jobs_table ): string {
		global $wpdb;

		// Detect MySQL version.
		$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );
		$version_parts = explode( '.', $mysql_version );
		$major_version = (int) $version_parts[0];

		// Use window functions for MySQL 8.0+.
		if ( $major_version >= 8 ) {
			return "
				LEFT JOIN (
					SELECT
						attachment_id,
						provider,
						model,
						score,
						ROW_NUMBER() OVER (PARTITION BY attachment_id ORDER BY created_at DESC) as rn
					FROM {$jobs_table}
				) jobs ON p.ID = jobs.attachment_id AND jobs.rn = 1
			";
		}

		// Fallback to subquery for older MySQL versions.
		return "
			LEFT JOIN (
				SELECT j1.attachment_id, j1.provider, j1.model, j1.score
				FROM {$jobs_table} j1
				INNER JOIN (
					SELECT attachment_id, MAX(created_at) as max_created
					FROM {$jobs_table}
					GROUP BY attachment_id
				) j2 ON j1.attachment_id = j2.attachment_id AND j1.created_at = j2.max_created
			) jobs ON p.ID = jobs.attachment_id
		";
	}

	/**
	 * Check read permission.
	 *
	 * @since 1.0.0
	 * @return bool True if user has permission.
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'upload_files' );
	}
}
