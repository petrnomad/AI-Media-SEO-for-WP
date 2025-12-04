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
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Register cache invalidation hooks.
		add_action( 'add_attachment', array( $this, 'invalidate_count_cache' ) );
		add_action( 'delete_attachment', array( $this, 'invalidate_count_cache' ) );
	}

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

		register_rest_route(
			$this->namespace,
			'/library/count',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_library_count' ),
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
		$skip_count         = rest_sanitize_boolean( $request->get_param( 'skip_count' ) );

		$offset = ( $page - 1 ) * $per_page;

		// Get language from request parameter (sent by frontend).
		// Frontend detects Polylang language from URL or cookie and passes it here.
		// pll_current_language() doesn't work in REST API context, so we rely on frontend.
		$current_language = sanitize_text_field( $request->get_param( 'lang' ) );
		// Empty string or 'all' means show all languages.
		if ( empty( $current_language ) || 'all' === $current_language ) {
			$current_language = null;
		}

		// Build WHERE conditions.
		$where_conditions = array( "p.post_type = 'attachment'" );
		$where_conditions[] = "p.post_mime_type LIKE 'image/%'";
		$prepare_values = array();

		// Status filter.
		if ( ! empty( $status ) && $status !== 'all' ) {
			// For pending status, also include attachments without status meta (NULL means pending).
			if ( $status === 'pending' ) {
				$where_conditions[] = '(pm_status.meta_value = %s OR pm_status.meta_value IS NULL)';
				$prepare_values[] = $status;
			} else {
				$where_conditions[] = 'pm_status.meta_value = %s';
				$prepare_values[] = $status;
			}
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

		// Language filter (Polylang).
		if ( ! empty( $current_language ) ) {
			$where_conditions[] = "t.slug = %s AND tt.taxonomy = 'language'";
			$prepare_values[] = $current_language;
		}

		$where_clause = implode( ' AND ', $where_conditions );

		// Build OPTIMIZED main query with CONDITIONAL JOINs.
		// Only include JOINs that are actually needed for WHERE clause (massive performance improvement).
		$main_joins = array();

		// Always join parent_post (needed for parent_info).
		$main_joins[] = "LEFT JOIN {$wpdb->posts} parent_post ON p.post_parent = parent_post.ID";

		// Only add metadata JOINs if they're needed for filtering.
		// For SELECT, we'll load metadata separately using get_post_meta() (much faster).
		if ( ! empty( $status ) && $status !== 'all' ) {
			$main_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_ai_media_status'";
		}
		if ( ! empty( $search ) ) {
			$main_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_alt ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'";
			$main_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_draft_alt ON p.ID = pm_draft_alt.post_id AND pm_draft_alt.meta_key = '_ai_media_draft_alt'";
			$main_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_draft_caption ON p.ID = pm_draft_caption.post_id AND pm_draft_caption.meta_key = '_ai_media_draft_caption'";
			$main_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_draft_title ON p.ID = pm_draft_title.post_id AND pm_draft_title.meta_key = '_ai_media_draft_title'";
		}
		// Language filter JOIN (Polylang).
		if ( ! empty( $current_language ) ) {
			$main_joins[] = "INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id";
			$main_joins[] = "INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
			$main_joins[] = "INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id";
		}

		$main_joins_sql = implode( "\n", $main_joins );

		// MINIMAL SELECT - only core post fields.
		// Metadata will be loaded separately (much faster than JOINs on 11k rows).
		$query = "
			SELECT
				p.ID,
				p.post_title,
				p.post_excerpt,
				p.post_content,
				p.post_parent,
				p.post_date,
				p.post_mime_type as mime_type,
				parent_post.ID as parent_id,
				parent_post.post_title as parent_title,
				parent_post.post_type as parent_type
			FROM {$wpdb->posts} p
			{$main_joins_sql}
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

		// Load metadata and jobs data for all items (much faster than JOINs).
		if ( ! empty( $items ) ) {
			$attachment_ids = array_column( $items, 'ID' );

			// Load jobs data in one query.
			$jobs_data = $this->get_latest_jobs_for_attachments( $attachment_ids );

			// Load all metadata in batch (much faster than individual get_post_meta calls).
			$meta_keys = array(
				'_wp_attachment_image_alt',
				'_ai_media_status',
				'_ai_media_draft_alt',
				'_ai_media_draft_caption',
				'_ai_media_draft_title',
			);

			// Build single query to fetch all metadata for all attachments.
			$ids_placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
			$keys_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

			$meta_query = $wpdb->prepare(
				"SELECT post_id, meta_key, meta_value
				FROM {$wpdb->postmeta}
				WHERE post_id IN ($ids_placeholders)
				AND meta_key IN ($keys_placeholders)",
				array_merge( $attachment_ids, $meta_keys )
			);

			$meta_results = $wpdb->get_results( $meta_query, ARRAY_A );

			// Organize metadata by post_id.
			$metadata_by_id = array();
			foreach ( $meta_results as $meta ) {
				$metadata_by_id[ $meta['post_id'] ][ $meta['meta_key'] ] = $meta['meta_value'];
			}

			// Merge metadata and jobs data into items.
			foreach ( $items as &$item ) {
				$attachment_id = $item['ID'];

				// Add metadata.
				$item['alt_text'] = $metadata_by_id[ $attachment_id ]['_wp_attachment_image_alt'] ?? '';
				$item['_ai_media_status'] = $metadata_by_id[ $attachment_id ]['_ai_media_status'] ?? 'pending';
				$item['_ai_media_draft_alt'] = $metadata_by_id[ $attachment_id ]['_ai_media_draft_alt'] ?? '';
				$item['_ai_media_draft_caption'] = $metadata_by_id[ $attachment_id ]['_ai_media_draft_caption'] ?? '';
				$item['_ai_media_draft_title'] = $metadata_by_id[ $attachment_id ]['_ai_media_draft_title'] ?? '';

				// Add jobs data.
				if ( isset( $jobs_data[ $attachment_id ] ) ) {
					$item['provider'] = $jobs_data[ $attachment_id ]['provider'];
					$item['model'] = $jobs_data[ $attachment_id ]['model'];
					$item['score'] = $jobs_data[ $attachment_id ]['score'];
				} else {
					$item['provider'] = null;
					$item['model'] = null;
					$item['score'] = null;
				}
			}
		}

		// Count total items (for pagination) with persistent caching.
		// Skip COUNT if skip_count=true (progressive loading).
		$total_items = null;
		$total_pages = null;

		if ( ! $skip_count ) {
			// Build optimized count query (only include JOINs that are actually used in filters).
			$count_joins = array();
			$count_prepare_values = array();

			// Only add JOINs that are needed for the WHERE clause.
			if ( ! empty( $status ) && $status !== 'all' ) {
				$count_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_ai_media_status'";
				$count_prepare_values[] = $status;
			}
			if ( ! empty( $search ) ) {
				$count_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_alt ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'";
				$count_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_draft_alt ON p.ID = pm_draft_alt.post_id AND pm_draft_alt.meta_key = '_ai_media_draft_alt'";
				$count_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_draft_caption ON p.ID = pm_draft_caption.post_id AND pm_draft_caption.meta_key = '_ai_media_draft_caption'";
				$count_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_draft_title ON p.ID = pm_draft_title.post_id AND pm_draft_title.meta_key = '_ai_media_draft_title'";
				$search_like = '%' . $wpdb->esc_like( $search ) . '%';
				for ( $i = 0; $i < 5; $i++ ) {
					$count_prepare_values[] = $search_like;
				}
			}

			$count_joins_sql = implode( "\n", $count_joins );
			$cache_key = $where_clause . $count_joins_sql . serialize( $count_prepare_values );
			$total_items = $this->get_cached_count( $cache_key );

			if ( false === $total_items ) {
				// OPTIMIZATION: Use COUNT(*) when no JOINs (much faster than COUNT(DISTINCT)).
				// DISTINCT is only needed when JOINs create duplicate rows.
				$count_select = empty( $count_joins ) ? 'COUNT(*)' : 'COUNT(DISTINCT p.ID)';

				$count_query = "
					SELECT {$count_select}
					FROM {$wpdb->posts} p
					{$count_joins_sql}
					WHERE {$where_clause}
				";

				$total_items = (int) $wpdb->get_var(
					empty( $count_prepare_values ) ? $count_query : $wpdb->prepare( $count_query, $count_prepare_values )
				);

				// Cache for 24 hours (persistent).
				$this->set_cached_count( $cache_key, $total_items );
			}

			// Calculate total pages.
			$total_pages = ceil( $total_items / $per_page );
		}

		// Format items to match WordPress REST API format.
		// Use lightweight format for large page sizes to improve performance.
		$lightweight = $per_page > 50;

		// OPTIMIZATION: Get upload_dir once instead of 20 times (called in format_attachment).
		$upload_dir = wp_upload_dir();
		$upload_base_url = $upload_dir['baseurl'];

		// OPTIMIZATION: Batch load language data for all attachments (if Polylang active).
		// This prevents N+1 problem (calling pll_get_post_language 20-100x).
		$languages_map = array();
		$polylang_languages = array();
		if ( ! empty( $items ) && function_exists( 'pll_get_post_language' ) ) {
			$attachment_ids = array_column( $items, 'ID' );
			$languages_map = $this->get_languages_for_attachments_batch( $attachment_ids );

			// Load Polylang language names ONCE (not 20x).
			if ( function_exists( 'pll_the_languages' ) ) {
				$polylang_languages = pll_the_languages( array( 'raw' => 1 ) );
			}
		}

		$formatted_items = array_map(
			function( $item ) use ( $lightweight, $upload_base_url, $languages_map, $polylang_languages ) {
				return $this->format_attachment( $item, $lightweight, $upload_base_url, $languages_map, $polylang_languages );
			},
			$items
		);

		return new WP_REST_Response(
			array(
				'items'         => $formatted_items,
				'total'         => $total_items, // null if skip_count=true.
				'page'          => $page,
				'per_page'      => $per_page,
				'total_pages'   => $total_pages, // null if skip_count=true.
				'loading_count' => $skip_count, // true if COUNT was skipped.
			),
			200
		);
	}

	/**
	 * Get library count only (for progressive loading).
	 *
	 * Returns ONLY the total count without loading any items.
	 * Used for background COUNT loading in progressive loading strategy.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_library_count( WP_REST_Request $request ) {
		global $wpdb;

		// Get and sanitize parameters (same as get_library_items).
		$per_page          = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$status            = sanitize_text_field( $request->get_param( 'status' ) );
		$attachment_filter = sanitize_text_field( $request->get_param( 'attachment_filter' ) );
		$search            = sanitize_text_field( $request->get_param( 'search' ) );

		// Get language from request parameter (sent by frontend).
		// pll_current_language() doesn't work in REST API context.
		$current_language = sanitize_text_field( $request->get_param( 'lang' ) );
		// Empty string or 'all' means show all languages.
		if ( empty( $current_language ) || 'all' === $current_language ) {
			$current_language = null;
		}

		// Build WHERE conditions (same as get_library_items).
		$where_conditions = array( "p.post_type = 'attachment'" );
		$where_conditions[] = "p.post_mime_type LIKE 'image/%'";
		$prepare_values = array();

		// Status filter.
		if ( ! empty( $status ) && $status !== 'all' ) {
			// For pending status, also include attachments without status meta (NULL means pending).
			if ( $status === 'pending' ) {
				$where_conditions[] = '(pm_status.meta_value = %s OR pm_status.meta_value IS NULL)';
				$prepare_values[] = $status;
			} else {
				$where_conditions[] = 'pm_status.meta_value = %s';
				$prepare_values[] = $status;
			}
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

		// Language filter (Polylang).
		if ( ! empty( $current_language ) ) {
			$where_conditions[] = "t.slug = %s AND tt.taxonomy = 'language'";
			$prepare_values[] = $current_language;
		}

		$where_clause = implode( ' AND ', $where_conditions );

		// Build optimized count query (only include JOINs that are actually used).
		$count_joins = array();
		$count_prepare_values = array();

		// Only add JOINs that are needed for the WHERE clause.
		if ( ! empty( $status ) && $status !== 'all' ) {
			$count_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_ai_media_status'";
			$count_prepare_values[] = $status;
		}
		if ( ! empty( $search ) ) {
			$count_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_alt ON p.ID = pm_alt.post_id AND pm_alt.meta_key = '_wp_attachment_image_alt'";
			$count_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_draft_alt ON p.ID = pm_draft_alt.post_id AND pm_draft_alt.meta_key = '_ai_media_draft_alt'";
			$count_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_draft_caption ON p.ID = pm_draft_caption.post_id AND pm_draft_caption.meta_key = '_ai_media_draft_caption'";
			$count_joins[] = "LEFT JOIN {$wpdb->postmeta} pm_draft_title ON p.ID = pm_draft_title.post_id AND pm_draft_title.meta_key = '_ai_media_draft_title'";
			$search_like = '%' . $wpdb->esc_like( $search ) . '%';
			for ( $i = 0; $i < 5; $i++ ) {
				$count_prepare_values[] = $search_like;
			}
		}
		// Language filter JOIN (Polylang).
		if ( ! empty( $current_language ) ) {
			$count_joins[] = "INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id";
			$count_joins[] = "INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
			$count_joins[] = "INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id";
			$count_prepare_values[] = $current_language;
		}

		$count_joins_sql = implode( "\n", $count_joins );
		$cache_key = $where_clause . $count_joins_sql . serialize( $count_prepare_values );
		$total_items = $this->get_cached_count( $cache_key );

		if ( false === $total_items ) {
			// OPTIMIZATION: Use COUNT(*) when no JOINs (much faster than COUNT(DISTINCT)).
			// DISTINCT is only needed when JOINs create duplicate rows.
			$count_select = empty( $count_joins ) ? 'COUNT(*)' : 'COUNT(DISTINCT p.ID)';

			$count_query = "
				SELECT {$count_select}
				FROM {$wpdb->posts} p
				{$count_joins_sql}
				WHERE {$where_clause}
			";

			$total_items = (int) $wpdb->get_var(
				empty( $count_prepare_values ) ? $count_query : $wpdb->prepare( $count_query, $count_prepare_values )
			);

			// Cache for 24 hours (persistent).
			$this->set_cached_count( $cache_key, $total_items );
		}

		// Calculate total pages.
		$total_pages = ceil( $total_items / $per_page );

		return new WP_REST_Response(
			array(
				'total'       => $total_items,
				'total_pages' => $total_pages,
			),
			200
		);
	}

	/**
	 * Format attachment data to match WordPress REST API format.
	 *
	 * @since 1.0.0
	 * @param array  $attachment Raw attachment data from database.
	 * @param bool   $lightweight Use lightweight format (skip heavy data for large page sizes).
	 * @param string $upload_base_url Base URL for uploads (passed from caller to avoid repeated wp_upload_dir calls).
	 * @param array  $languages_map Batch-loaded language slugs map (attachment_id => language_slug).
	 * @param array  $polylang_languages Polylang languages array from pll_the_languages (loaded once).
	 * @return array Formatted attachment data.
	 */
	private function format_attachment( array $attachment, bool $lightweight = false, string $upload_base_url = '', array $languages_map = array(), array $polylang_languages = array() ): array {
		$id = (int) $attachment['ID'];

		// Get language info (Polylang/WPML) using batch-loaded cache
		$language_info = $this->get_attachment_language_info( $id, $languages_map, $polylang_languages );

		// Lightweight format for large page sizes (per_page > 50).
		// Skips heavy data that's not essential for table display.
		if ( $lightweight ) {
			return array(
				'id'        => $id,
				'title'     => array(
					'rendered' => $attachment['post_title'] ?? '',
				),
				'caption'   => array(
					'rendered' => $attachment['post_excerpt'] ?? '',
				),
				'alt_text'  => $attachment['alt_text'] ?? '',
				'source_url' => wp_get_attachment_url( $id ) ?: '',
				'mime_type' => $attachment['mime_type'] ?? '',
				'post'      => (int) $attachment['post_parent'],
				'parent'    => (int) $attachment['post_parent'],
				'parent_info' => ! empty( $attachment['parent_id'] ) ? array(
					'id'    => (int) $attachment['parent_id'],
					'title' => $attachment['parent_title'] ?? '',
					'type'  => $attachment['parent_type'] ?? '',
				) : null,
				'meta'      => array(
					'_ai_media_status'        => $attachment['_ai_media_status'] ?? 'pending',
					'_ai_media_draft_alt'     => $attachment['_ai_media_draft_alt'] ?? '',
					'_ai_media_draft_caption' => $attachment['_ai_media_draft_caption'] ?? '',
					'_ai_media_draft_title'   => $attachment['_ai_media_draft_title'] ?? '',
				),
				'provider'  => $attachment['provider'] ?? null,
				'model'     => $attachment['model'] ?? null,
				'score'     => $attachment['score'] !== null ? (float) $attachment['score'] : null,
				'language_info' => $language_info,
				// SKIP: media_details (can be large), description (not needed in table)
			);
		}

		// Full format for normal page sizes (per_page <= 50).
		// Get attachment metadata (for image sizes, dimensions, etc.).
		$metadata = wp_get_attachment_metadata( $id );

		// Get proper attachment URL (handles WooCommerce and other custom storage).
		$source_url = wp_get_attachment_url( $id );

		// OPTIMIZATION: Removed wp_get_attachment_image_src() calls (saves 60 DB queries per page).
		// Frontend will construct image URLs from metadata using upload_base_url.
		// upload_base_url is now passed as parameter (called once instead of 20 times).
		$base_url = $upload_base_url;

		return array(
			'id'                => $id,
			'title'             => array(
				'rendered' => $attachment['post_title'] ?? '',
			),
			'caption'           => array(
				'rendered' => $attachment['post_excerpt'] ?? '',
			),
			'description'       => array(
				'rendered' => $attachment['post_content'] ?? '',
			),
			'alt_text'          => $attachment['alt_text'] ?? '',
			'source_url'        => $source_url ?: '',
			'mime_type'         => $attachment['mime_type'] ?? '',
			'post'              => (int) $attachment['post_parent'],
			'parent'            => (int) $attachment['post_parent'],
			'parent_info'       => ! empty( $attachment['parent_id'] ) ? array(
				'id'    => (int) $attachment['parent_id'],
				'title' => $attachment['parent_title'] ?? '',
				'type'  => $attachment['parent_type'] ?? '',
			) : null,
			'meta'              => array(
				'_ai_media_status'        => $attachment['_ai_media_status'] ?? 'pending',
				'_ai_media_draft_alt'     => $attachment['_ai_media_draft_alt'] ?? '',
				'_ai_media_draft_caption' => $attachment['_ai_media_draft_caption'] ?? '',
				'_ai_media_draft_title'   => $attachment['_ai_media_draft_title'] ?? '',
			),
			'provider'          => $attachment['provider'] ?? null,
			'model'             => $attachment['model'] ?? null,
			'score'             => $attachment['score'] !== null ? (float) $attachment['score'] : null,
			'language_info'     => $language_info,
			'media_details'     => $metadata ?? array(),
			'upload_base_url'   => $base_url, // For frontend to construct image URLs.
		);
	}

	/**
	 * Get attachment language info (language code, name, flag emoji).
	 *
	 * OPTIMIZED: Uses batch-loaded cache to prevent N+1 problem.
	 *
	 * @since 1.0.0
	 * @param int   $attachment_id Attachment ID.
	 * @param array $languages_map Batch-loaded language slugs map (attachment_id => language_slug).
	 * @param array $polylang_languages Polylang languages array from pll_the_languages (loaded once).
	 * @return array|null Language info array or null if not multilingual.
	 */
	private function get_attachment_language_info( int $attachment_id, array $languages_map = array(), array $polylang_languages = array() ) {
		// Check if Polylang is active.
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return null;
		}

		// Get language slug from batch-loaded cache (FAST - no DB query).
		$lang_slug = $languages_map[ $attachment_id ] ?? null;

		// Fallback: If not in cache, try individual lookup (compatibility).
		// This should rarely happen if batch loading works correctly.
		if ( ! $lang_slug ) {
			$lang_slug = pll_get_post_language( $attachment_id, 'slug' );

			// If no language found, try parent post.
			if ( ! $lang_slug ) {
				$parent_id = wp_get_post_parent_id( $attachment_id );
				if ( $parent_id ) {
					$lang_slug = pll_get_post_language( $parent_id, 'slug' );
				}
			}
		}

		// Still no language? Return null.
		if ( ! $lang_slug ) {
			return null;
		}

		// Get language name from batch-loaded Polylang data (FAST - no repeated calls).
		$lang_name = $lang_slug;
		if ( ! empty( $polylang_languages ) && isset( $polylang_languages[ $lang_slug ]['name'] ) ) {
			$lang_name = $polylang_languages[ $lang_slug ]['name'];
		} elseif ( function_exists( 'pll_the_languages' ) ) {
			// Fallback: Load languages if not in cache (compatibility).
			$languages = pll_the_languages( array( 'raw' => 1 ) );
			if ( is_array( $languages ) && isset( $languages[ $lang_slug ]['name'] ) ) {
				$lang_name = $languages[ $lang_slug ]['name'];
			}
		}

		// Map language codes to emoji flags
		// Using regional indicator symbols (A=U+1F1E6, Z=U+1F1FF)
		$flag_map = array(
			'cs' => '🇨🇿', // Czech
			'en' => '🇬🇧', // English (UK)
			'de' => '🇩🇪', // German
			'fr' => '🇫🇷', // French
			'es' => '🇪🇸', // Spanish
			'it' => '🇮🇹', // Italian
			'pt' => '🇵🇹', // Portuguese
			'pl' => '🇵🇱', // Polish
			'ru' => '🇷🇺', // Russian
			'nl' => '🇳🇱', // Dutch
			'sv' => '🇸🇪', // Swedish
			'da' => '🇩🇰', // Danish
			'fi' => '🇫🇮', // Finnish
			'no' => '🇳🇴', // Norwegian
			'ja' => '🇯🇵', // Japanese
			'zh' => '🇨🇳', // Chinese
			'ko' => '🇰🇷', // Korean
			'ar' => '🇸🇦', // Arabic (Saudi)
			'tr' => '🇹🇷', // Turkish
			'el' => '🇬🇷', // Greek
			'he' => '🇮🇱', // Hebrew
			'sk' => '🇸🇰', // Slovak
			'hu' => '🇭🇺', // Hungarian
			'ro' => '🇷🇴', // Romanian
			'bg' => '🇧🇬', // Bulgarian
			'hr' => '🇭🇷', // Croatian
			'uk' => '🇺🇦', // Ukrainian
		);

		// Get emoji flag for this language (fallback to 🌐 if not found)
		$flag_emoji = $flag_map[ $lang_slug ] ?? '🌐';

		// Return language info with emoji flag
		return array(
			'code' => $lang_slug,
			'name' => $lang_name,
			'flag' => $flag_emoji,
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
				'description'       => 'Filter by status (all, pending, processing, processed, approved, failed).',
				'type'              => 'string',
				'default'           => 'all',
				'enum'              => array( 'all', 'pending', 'processing', 'processed', 'approved', 'failed' ),
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
			'skip_count'        => array(
				'description'       => 'Skip COUNT query for faster initial load (progressive loading).',
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Get latest jobs for multiple attachments efficiently.
	 *
	 * @since 1.0.0
	 * @param array $attachment_ids Array of attachment IDs.
	 * @return array Associative array of attachment_id => job data.
	 */
	private function get_latest_jobs_for_attachments( array $attachment_ids ): array {
		global $wpdb;

		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$jobs_table = $wpdb->prefix . 'ai_media_jobs';
		$ids_placeholder = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );

		// Detect MySQL version for optimized query.
		$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );
		$version_parts = explode( '.', $mysql_version );
		$major_version = (int) $version_parts[0];

		// Use window functions for MySQL 8.0+.
		if ( $major_version >= 8 ) {
			$query = "
				SELECT attachment_id, provider, model, score
				FROM (
					SELECT
						attachment_id,
						provider,
						model,
						score,
						ROW_NUMBER() OVER (PARTITION BY attachment_id ORDER BY created_at DESC) as rn
					FROM {$jobs_table}
					WHERE attachment_id IN ($ids_placeholder)
				) ranked
				WHERE rn = 1
			";
		} else {
			// Fallback for older MySQL.
			$query = "
				SELECT j1.attachment_id, j1.provider, j1.model, j1.score
				FROM {$jobs_table} j1
				INNER JOIN (
					SELECT attachment_id, MAX(created_at) as max_created
					FROM {$jobs_table}
					WHERE attachment_id IN ($ids_placeholder)
					GROUP BY attachment_id
				) j2 ON j1.attachment_id = j2.attachment_id AND j1.created_at = j2.max_created
			";
		}

		$results = $wpdb->get_results(
			$wpdb->prepare( $query, $attachment_ids ),
			ARRAY_A
		);

		// Convert to associative array keyed by attachment_id.
		$jobs_map = array();
		foreach ( $results as $row ) {
			$jobs_map[ $row['attachment_id'] ] = array(
				'provider' => $row['provider'],
				'model' => $row['model'],
				'score' => $row['score'] !== null ? (float) $row['score'] : null,
			);
		}

		return $jobs_map;
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
	 * Get cached count from persistent storage (24h cache).
	 *
	 * @since 1.0.0
	 * @param string $cache_key Cache key.
	 * @return int|false Count value or false if not cached/expired.
	 */
	private function get_cached_count( string $cache_key ) {
		$option_name = 'ai_media_library_count_' . md5( $cache_key );
		$cached = get_option( $option_name );

		if ( $cached && is_array( $cached ) && isset( $cached['count'], $cached['timestamp'] ) ) {
			// Check if cache is still valid (24 hours).
			if ( ( time() - $cached['timestamp'] ) < 86400 ) {
				return (int) $cached['count'];
			}
		}

		return false;
	}

	/**
	 * Set cached count in persistent storage (24h).
	 *
	 * @since 1.0.0
	 * @param string $cache_key Cache key.
	 * @param int    $count Count value.
	 * @return void
	 */
	private function set_cached_count( string $cache_key, int $count ): void {
		$option_name = 'ai_media_library_count_' . md5( $cache_key );
		update_option(
			$option_name,
			array(
				'count'     => $count,
				'timestamp' => time(),
			),
			false // autoload = false (don't load on every page).
		);
	}

	/**
	 * Batch load language slugs for multiple attachments.
	 *
	 * OPTIMIZATION: Loads languages for all attachments in ONE query instead of N queries.
	 * Prevents N+1 problem when displaying 20-100 attachments.
	 * Now includes fallback to parent post languages.
	 *
	 * @since 1.0.0
	 * @param array $attachment_ids Array of attachment IDs.
	 * @return array Associative array of attachment_id => language_slug.
	 */
	private function get_languages_for_attachments_batch( array $attachment_ids ): array {
		global $wpdb;

		if ( empty( $attachment_ids ) ) {
			return array();
		}

		// STEP 1: Get direct language assignments for attachments.
		$ids_placeholder = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );

		$query = "
			SELECT
				tr.object_id as attachment_id,
				t.slug as language_slug
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			WHERE tt.taxonomy = 'language'
			AND tr.object_id IN ($ids_placeholder)
		";

		$results = $wpdb->get_results(
			$wpdb->prepare( $query, $attachment_ids ),
			ARRAY_A
		);

		// Convert to associative array: attachment_id => language_slug.
		$languages_map = array();
		foreach ( $results as $row ) {
			$languages_map[ (int) $row['attachment_id'] ] = $row['language_slug'];
		}

		// STEP 2: For attachments without direct assignment, check parent posts.
		$attachments_without_lang = array_diff( $attachment_ids, array_keys( $languages_map ) );

		if ( ! empty( $attachments_without_lang ) ) {
			// Get parent IDs for attachments without direct language.
			$parent_ids_placeholder = implode( ',', array_fill( 0, count( $attachments_without_lang ), '%d' ) );

			$parent_query = "
				SELECT ID, post_parent
				FROM {$wpdb->posts}
				WHERE ID IN ($parent_ids_placeholder)
				AND post_parent > 0
			";

			$parent_results = $wpdb->get_results(
				$wpdb->prepare( $parent_query, $attachments_without_lang ),
				ARRAY_A
			);

			// Build map of attachment_id => parent_id.
			$parent_map = array();
			foreach ( $parent_results as $row ) {
				$parent_map[ (int) $row['ID'] ] = (int) $row['post_parent'];
			}

			// Get languages for all parent posts in one query.
			if ( ! empty( $parent_map ) ) {
				$parent_ids = array_values( $parent_map );
				$parent_lang_placeholder = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );

				$parent_lang_query = "
					SELECT
						tr.object_id as parent_id,
						t.slug as language_slug
					FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
					WHERE tt.taxonomy = 'language'
					AND tr.object_id IN ($parent_lang_placeholder)
				";

				$parent_lang_results = $wpdb->get_results(
					$wpdb->prepare( $parent_lang_query, $parent_ids ),
					ARRAY_A
				);

				// Map parent_id => language_slug.
				$parent_languages = array();
				foreach ( $parent_lang_results as $row ) {
					$parent_languages[ (int) $row['parent_id'] ] = $row['language_slug'];
				}

				// Assign parent languages to attachments.
				foreach ( $parent_map as $attachment_id => $parent_id ) {
					if ( isset( $parent_languages[ $parent_id ] ) ) {
						$languages_map[ $attachment_id ] = $parent_languages[ $parent_id ];
					}
				}
			}
		}

		return $languages_map;
	}

	/**
	 * Invalidate all COUNT caches.
	 *
	 * Called when attachments are added or deleted.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function invalidate_count_cache(): void {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE 'ai_media_library_count_%'"
		);
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
