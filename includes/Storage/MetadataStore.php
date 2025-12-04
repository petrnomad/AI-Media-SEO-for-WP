<?php
/**
 * Metadata Store
 *
 * Handles storage and retrieval of AI-generated metadata.
 *
 * @package    AIMediaSEO
 * @subpackage Storage
 * @since      1.0.0
 */

namespace AIMediaSEO\Storage;

use AIMediaSEO\Multilingual\LanguageDetector;
use AIMediaSEO\Multilingual\LanguageFallback;

/**
 * MetadataStore class.
 *
 * Manages metadata storage in database and post meta.
 *
 * @since 1.0.0
 */
class MetadataStore {

	/**
	 * Language detector instance
	 *
	 * @var LanguageDetector
	 */
	private $language_detector;

	/**
	 * Language fallback instance
	 *
	 * @var LanguageFallback
	 */
	private $language_fallback;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->language_detector = new LanguageDetector();
		$this->language_fallback = new LanguageFallback( $this->language_detector );
	}

	/**
	 * Get job by ID.
	 *
	 * @since 1.0.0
	 * @param int $job_id Job ID.
	 * @return array|null Job data or null if not found.
	 */
	public function get_job( int $job_id ): ?array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE id = %d",
				$job_id
			),
			ARRAY_A
		);

		if ( ! $job ) {
			return null;
		}

		// Decode JSON fields.
		if ( ! empty( $job['request_data'] ) ) {
			$job['request_data'] = json_decode( $job['request_data'], true );
		}

		if ( ! empty( $job['response_data'] ) ) {
			$job['response_data'] = json_decode( $job['response_data'], true );
		}

		return $job;
	}

	/**
	 * Get jobs for an attachment.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Optional. Filter by language.
	 * @param string $status        Optional. Filter by status.
	 * @return array Array of jobs.
	 */
	public function get_jobs_for_attachment( int $attachment_id, string $language = '', string $status = '' ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		$where = array( 'attachment_id = %d' );
		$values = array( $attachment_id );

		if ( ! empty( $language ) ) {
			$where[] = 'language_code = %s';
			$values[] = $language;
		}

		if ( ! empty( $status ) ) {
			$where[] = 'status = %s';
			$values[] = $status;
		}

		$query = "SELECT * FROM {$table_name} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC';

		$jobs = $wpdb->get_results(
			$wpdb->prepare( $query, $values ),
			ARRAY_A
		);

		// Decode JSON fields.
		foreach ( $jobs as &$job ) {
			if ( ! empty( $job['request_data'] ) ) {
				$job['request_data'] = json_decode( $job['request_data'], true );
			}

			if ( ! empty( $job['response_data'] ) ) {
				$job['response_data'] = json_decode( $job['response_data'], true );
			}
		}

		return $jobs;
	}

	/**
	 * Get pending jobs.
	 *
	 * @since 1.0.0
	 * @param int $limit Maximum number of jobs to return.
	 * @return array Array of pending jobs.
	 */
	public function get_pending_jobs( int $limit = 50 ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name}
				WHERE status = 'pending'
				ORDER BY created_at ASC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		foreach ( $jobs as &$job ) {
			if ( ! empty( $job['request_data'] ) ) {
				$job['request_data'] = json_decode( $job['request_data'], true );
			}

			if ( ! empty( $job['response_data'] ) ) {
				$job['response_data'] = json_decode( $job['response_data'], true );
			}
		}

		return $jobs;
	}

	/**
	 * Update job status.
	 *
	 * @since 1.0.0
	 * @param int    $job_id Job ID.
	 * @param string $status New status.
	 * @param array  $data   Additional data to update.
	 * @return bool True on success.
	 */
	public function update_job_status( int $job_id, string $status, array $data = array() ): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		$update_data = array_merge(
			array( 'status' => $status ),
			$data
		);

		$result = $wpdb->update(
			$table_name,
			$update_data,
			array( 'id' => $job_id ),
			null,
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Approve job and apply metadata.
	 *
	 * @since 1.0.0
	 * @param int   $job_id  Job ID.
	 * @param array $fields  Optional. Specific fields to approve.
	 * @return bool True on success.
	 */
	public function approve_job( int $job_id, array $fields = array() ): bool {
		$job = $this->get_job( $job_id );

		if ( ! $job ) {
			return false;
		}

		// Update job status.
		$this->update_job_status(
			$job_id,
			'approved',
			array(
				'approved_at' => current_time( 'mysql' ),
				'approved_by' => get_current_user_id(),
			)
		);

		// Apply metadata.
		$metadata = $job['response_data'] ?? array();

		if ( ! empty( $fields ) ) {
			// Only apply selected fields.
			$metadata = array_intersect_key( $metadata, array_flip( $fields ) );
		}

		$analyzer = new \AIMediaSEO\Analyzer\ImageAnalyzer();
		$analyzer->apply_metadata( $job['attachment_id'], $job['language_code'], $metadata );

		// Log event.
		$this->log_event(
			'metadata_approved',
			$job['attachment_id'],
			array(
				'job_id'   => $job_id,
				'fields'   => $fields,
				'language' => $job['language_code'],
			)
		);

		return true;
	}

	/**
	 * Get metadata for attachment.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @return array Metadata array.
	 */
	public function get_metadata( int $attachment_id, string $language ): array {
		$metadata = array();

		// Get ALT text.
		$alt = get_post_meta( $attachment_id, "ai_alt_{$language}", true );
		if ( $alt ) {
			$metadata['alt'] = $alt;
		}

		// Get caption.
		$caption = get_post_meta( $attachment_id, "ai_caption_{$language}", true );
		if ( $caption ) {
			$metadata['caption'] = $caption;
		}

		// Get title.
		$title = get_post_meta( $attachment_id, "ai_title_{$language}", true );
		if ( $title ) {
			$metadata['title'] = $title;
		}

		// Get keywords.
		$keywords = get_post_meta( $attachment_id, "ai_keywords_{$language}", true );
		if ( $keywords ) {
			$metadata['keywords'] = $keywords;
		}

		// Get score.
		$score = get_post_meta( $attachment_id, "ai_score_{$language}", true );
		if ( $score ) {
			$metadata['score'] = (float) $score;
		}

		return $metadata;
	}

	/**
	 * Get metadata with fallback.
	 *
	 * Returns metadata in requested language, or falls back to other languages if not available.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @return array Metadata array with fallback info.
	 */
	public function get_metadata_with_fallback( int $attachment_id, string $language ): array {
		return $this->language_fallback->get_all_metadata_with_fallback( $attachment_id, $language );
	}

	/**
	 * Save metadata for attachment.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param array  $metadata      Metadata to save.
	 * @return bool True on success.
	 */
	public function save_metadata( int $attachment_id, string $language, array $metadata ): bool {
		// Save old values for history.
		$old_values = $this->get_metadata( $attachment_id, $language );
		if ( ! empty( $old_values ) ) {
			update_post_meta( $attachment_id, 'ai_old_values', $old_values );
		}

		// Save ALT text.
		if ( isset( $metadata['alt'] ) ) {
			update_post_meta( $attachment_id, "ai_alt_{$language}", sanitize_text_field( $metadata['alt'] ) );

			// For default language, also update core WP ALT.
			if ( $language === $this->language_detector->get_default_language() ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $metadata['alt'] ) );
			}
		}

		// Save caption.
		if ( isset( $metadata['caption'] ) ) {
			update_post_meta( $attachment_id, "ai_caption_{$language}", wp_kses_post( $metadata['caption'] ) );
		}

		// Save title.
		if ( isset( $metadata['title'] ) ) {
			update_post_meta( $attachment_id, "ai_title_{$language}", sanitize_text_field( $metadata['title'] ) );
		}

		// Save keywords.
		if ( isset( $metadata['keywords'] ) ) {
			$keywords = is_array( $metadata['keywords'] )
				? $metadata['keywords']
				: explode( ',', $metadata['keywords'] );
			update_post_meta( $attachment_id, "ai_keywords_{$language}", array_map( 'sanitize_text_field', $keywords ) );
		}

		// Save score.
		if ( isset( $metadata['score'] ) ) {
			update_post_meta( $attachment_id, "ai_score_{$language}", (float) $metadata['score'] );
		}

		// Save AI status.
		update_post_meta( $attachment_id, 'ai_status', 'processed' );

		// Save last provider and model if provided.
		if ( isset( $metadata['provider'] ) ) {
			update_post_meta( $attachment_id, 'ai_last_provider', sanitize_text_field( $metadata['provider'] ) );
		}

		if ( isset( $metadata['model'] ) ) {
			update_post_meta( $attachment_id, 'ai_last_model', sanitize_text_field( $metadata['model'] ) );
		}

		if ( isset( $metadata['prompt_version'] ) ) {
			update_post_meta( $attachment_id, 'ai_prompt_version', sanitize_text_field( $metadata['prompt_version'] ) );
		}

		do_action( 'ai_media_metadata_saved', $attachment_id, $language, $metadata );

		return true;
	}

	/**
	 * Get all languages with metadata for attachment.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID.
	 * @return array Array of language codes.
	 */
	public function get_available_languages( int $attachment_id ): array {
		return $this->language_fallback->get_available_languages( $attachment_id );
	}

	/**
	 * Get missing languages for attachment.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID.
	 * @return array Array of language codes.
	 */
	public function get_missing_languages( int $attachment_id ): array {
		return $this->language_fallback->get_missing_languages( $attachment_id );
	}

	/**
	 * Get completion status for attachment.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID.
	 * @return array Completion statistics.
	 */
	public function get_completion_status( int $attachment_id ): array {
		return $this->language_fallback->get_completion_status( $attachment_id );
	}

	/**
	 * Get statistics.
	 *
	 * @since 1.0.0
	 * @param string $period Optional. Period for stats (today, week, month, all).
	 * @return array Statistics data.
	 */
	public function get_stats( string $period = 'all' ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		$date_filter = '';
		switch ( $period ) {
			case 'today':
				$date_filter = "AND DATE(created_at) = CURDATE()";
				break;
			case 'week':
				$date_filter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
				break;
			case 'month':
				$date_filter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
				break;
		}

		$stats = array(
			'total'         => 0,
			'pending'       => 0,
			'processing'    => 0,
			'needs_review'  => 0,
			'approved'      => 0,
			'failed'        => 0,
			'skipped'       => 0,
			'total_cost'    => 0.0,
			'avg_score'     => 0.0,
		);

		// Get counts by status.
		$counts = $wpdb->get_results(
			"SELECT status, COUNT(*) as count, SUM(cost_cents) as total_cost, AVG(score) as avg_score
			FROM {$table_name}
			WHERE 1=1 {$date_filter}
			GROUP BY status",
			ARRAY_A
		);

		foreach ( $counts as $row ) {
			$status = $row['status'];
			$stats[ $status ] = (int) $row['count'];
			$stats['total'] += (int) $row['count'];

			if ( $row['total_cost'] ) {
				$stats['total_cost'] += (float) $row['total_cost'];
			}

			if ( $row['avg_score'] ) {
				$stats['avg_score'] = (float) $row['avg_score'];
			}
		}

		return $stats;
	}

	/**
	 * Log an event.
	 *
	 * @since 1.0.0
	 * @param string $event_type    Event type.
	 * @param int    $attachment_id Optional. Attachment ID.
	 * @param array  $meta          Optional. Additional metadata.
	 * @return bool True on success.
	 */
	public function log_event( string $event_type, int $attachment_id = 0, array $meta = array() ): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_events';

		$data = array(
			'event_type'    => $event_type,
			'attachment_id' => $attachment_id ?: null,
			'user_id'       => get_current_user_id() ?: null,
			'meta_json'     => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
			'created_at'    => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( $table_name, $data );

		return $result !== false;
	}

	/**
	 * Get events.
	 *
	 * @since 1.0.0
	 * @param array $args Query arguments.
	 * @return array Array of events.
	 */
	public function get_events( array $args = array() ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_events';

		$defaults = array(
			'event_type'    => '',
			'attachment_id' => 0,
			'limit'         => 50,
			'offset'        => 0,
			'order'         => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );
		$values = array();

		if ( ! empty( $args['event_type'] ) ) {
			$where[] = 'event_type = %s';
			$values[] = $args['event_type'];
		}

		if ( ! empty( $args['attachment_id'] ) ) {
			$where[] = 'attachment_id = %d';
			$values[] = $args['attachment_id'];
		}

		$query = "SELECT * FROM {$table_name}
				  WHERE " . implode( ' AND ', $where ) . "
				  ORDER BY created_at {$args['order']}
				  LIMIT %d OFFSET %d";

		$values[] = $args['limit'];
		$values[] = $args['offset'];

		$events = $wpdb->get_results(
			$wpdb->prepare( $query, $values ),
			ARRAY_A
		);

		// Decode JSON meta.
		foreach ( $events as &$event ) {
			if ( ! empty( $event['meta_json'] ) ) {
				$event['meta'] = json_decode( $event['meta_json'], true );
			}
		}

		return $events;
	}

	/**
	 * Clean old events.
	 *
	 * @since 1.0.0
	 * @param int $days Number of days to keep.
	 * @return int Number of rows deleted.
	 */
	public function clean_old_events( int $days = 90 ): int {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_events';

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name}
				WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);

		return (int) $result;
	}

	/**
	 * Get total cost for all processed jobs.
	 *
	 * @since 1.1.0
	 * @param array $filters Optional filters (date_from, date_to, status).
	 * @return float Total cost in USD.
	 */
	public function get_total_cost( array $filters = array() ): float {
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

		$where_sql = implode( ' AND ', $where_clauses );

		if ( ! empty( $where_values ) ) {
			$where_sql = $wpdb->prepare( $where_sql, $where_values );
		}

		$query = "SELECT SUM(total_cost) as total FROM {$table_name} WHERE {$where_sql}";

		$total = $wpdb->get_var( $query );

		return round( floatval( $total ), 8 );
	}

	/**
	 * Get cost breakdown by model.
	 *
	 * @since 1.1.0
	 * @return array Cost breakdown grouped by model.
	 */
	public function get_cost_by_model(): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		$results = $wpdb->get_results(
			"SELECT
				model,
				COUNT(*) as job_count,
				SUM(input_tokens) as total_input_tokens,
				SUM(output_tokens) as total_output_tokens,
				SUM(total_cost) as total_cost
			FROM {$table_name}
			WHERE total_cost IS NOT NULL
			GROUP BY model
			ORDER BY total_cost DESC",
			ARRAY_A
		);

		$breakdown = array();

		foreach ( $results as $row ) {
			$model = $row['model'] ?? 'unknown';

			$breakdown[ $model ] = array(
				'jobs'          => (int) $row['job_count'],
				'input_tokens'  => (int) $row['total_input_tokens'],
				'output_tokens' => (int) $row['total_output_tokens'],
				'total_cost'    => round( floatval( $row['total_cost'] ), 8 ),
			);
		}

		return $breakdown;
	}

	/**
	 * Get cost by date range.
	 *
	 * @since 1.1.0
	 * @param string $start Start date (Y-m-d).
	 * @param string $end   End date (Y-m-d).
	 * @return array Daily cost breakdown.
	 */
	public function get_cost_by_date_range( string $start, string $end ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(created_at) as date,
					COUNT(*) as job_count,
					SUM(total_cost) as total_cost
				FROM {$table_name}
				WHERE total_cost IS NOT NULL
				AND created_at >= %s
				AND created_at <= %s
				GROUP BY DATE(created_at)
				ORDER BY date ASC",
				$start,
				$end
			),
			ARRAY_A
		);

		$breakdown = array();

		foreach ( $results as $row ) {
			$breakdown[ $row['date'] ] = array(
				'jobs'       => (int) $row['job_count'],
				'total_cost' => round( floatval( $row['total_cost'] ), 8 ),
			);
		}

		return $breakdown;
	}

	/**
	 * Get cost statistics.
	 *
	 * @since 1.1.0
	 * @return array Overall cost statistics.
	 */
	public function get_cost_stats(): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		$stats = $wpdb->get_row(
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

		return array(
			'total_images_processed'  => (int) ( $stats['total_images'] ?? 0 ),
			'total_input_tokens'      => (int) ( $stats['total_input_tokens'] ?? 0 ),
			'total_output_tokens'     => (int) ( $stats['total_output_tokens'] ?? 0 ),
			'total_cost'              => round( floatval( $stats['total_cost'] ?? 0 ), 8 ),
			'average_cost_per_image'  => round( floatval( $stats['avg_cost_per_image'] ?? 0 ), 8 ),
		);
	}
}
