<?php
/**
 * Batch Metadata Store
 *
 * Optimized batch database operations for metadata storage.
 * Reduces individual UPDATE queries to bulk operations.
 *
 * @package    AIMediaSEO
 * @subpackage Storage
 * @since      2.2.0
 * @version    2.2.0 - FÁZE 2: Database Optimization
 */

namespace AIMediaSEO\Storage;

/**
 * BatchMetadataStore class.
 *
 * Provides optimized batch operations for storing metadata.
 * Reduces 5-7 queries per image to 2-3 queries total for entire batch.
 *
 * @since 2.2.0
 */
class BatchMetadataStore {

	/**
	 * Bulk update post meta for multiple attachments.
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE for efficient bulk updates.
	 *
	 * @since 2.2.0
	 * @param array $updates Array of [attachment_id => [meta_key => meta_value]].
	 * @return int Number of rows affected.
	 */
	public function bulk_update_post_meta( array $updates ): int {
		global $wpdb;

		if ( empty( $updates ) ) {
			return 0;
		}

		$values       = array();
		$placeholders = array();

		foreach ( $updates as $attachment_id => $metadata ) {
			foreach ( $metadata as $meta_key => $meta_value ) {
				$values[]       = (int) $attachment_id;
				$values[]       = $meta_key;
				$values[]       = maybe_serialize( $meta_value );
				$placeholders[] = '(%d, %s, %s)';
			}
		}

		if ( empty( $placeholders ) ) {
			return 0;
		}

		$sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				VALUES " . implode( ', ', $placeholders ) . '
				ON DUPLICATE KEY UPDATE
					meta_value = VALUES(meta_value)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, $values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $prepared );

		// Clear post meta cache for all affected attachments.
		foreach ( array_keys( $updates ) as $attachment_id ) {
			wp_cache_delete( $attachment_id, 'post_meta' );
		}

		return (int) $result;
	}

	/**
	 * Bulk update post fields (title, excerpt, content).
	 *
	 * Uses CASE statement for efficient bulk updates.
	 *
	 * @since 2.2.0
	 * @param array $updates Array of [post_id => [field => value]].
	 * @return int Number of rows affected.
	 */
	public function bulk_update_posts( array $updates ): int {
		global $wpdb;

		if ( empty( $updates ) ) {
			return 0;
		}

		$affected      = 0;
		$allowed_fields = array( 'post_title', 'post_excerpt', 'post_content' );

		// Process each field separately.
		foreach ( $allowed_fields as $field ) {
			$case_parts = array();
			$ids        = array();

			foreach ( $updates as $post_id => $fields ) {
				if ( isset( $fields[ $field ] ) ) {
					$case_parts[] = $wpdb->prepare(
						'WHEN %d THEN %s',
						$post_id,
						$fields[ $field ]
					);
					$ids[]        = (int) $post_id;
				}
			}

			if ( ! empty( $case_parts ) ) {
				$ids_list = implode( ',', $ids );
				$case_sql = implode( ' ', $case_parts );

				$sql = "UPDATE {$wpdb->posts}
						SET {$field} = CASE ID {$case_sql} END
						WHERE ID IN ({$ids_list})";

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$result    = $wpdb->query( $sql );
				$affected += (int) $result;
			}
		}

		// Clear post cache for all affected posts.
		foreach ( array_keys( $updates ) as $post_id ) {
			clean_post_cache( $post_id );
		}

		return $affected;
	}

	/**
	 * Batch insert jobs.
	 *
	 * @since 2.2.0
	 * @param array $jobs Array of job data arrays.
	 * @return int Number of rows inserted.
	 */
	public function bulk_insert_jobs( array $jobs ): int {
		global $wpdb;

		if ( empty( $jobs ) ) {
			return 0;
		}

		$values       = array();
		$placeholders = array();

		foreach ( $jobs as $job ) {
			$values = array_merge(
				$values,
				array(
					$job['attachment_id'],
					$job['language_code'] ?? 'en',
					$job['status'] ?? 'pending',
					$job['provider'],
					$job['model'],
					$job['prompt_version'] ?? '1.0',
					wp_json_encode( $job['request_data'] ?? array() ),
					wp_json_encode( $job['response_data'] ?? array() ),
					$job['input_tokens'] ?? 0,
					$job['output_tokens'] ?? 0,
					$job['input_cost'] ?? 0.0,
					$job['output_cost'] ?? 0.0,
					$job['total_cost'] ?? 0.0,
					$job['score'] ?? 0,
					$job['created_at'] ?? current_time( 'mysql' ),
					$job['processed_at'] ?? current_time( 'mysql' ),
				)
			);

			$placeholders[] = '(%d, %s, %s, %s, %s, %s, %s, %s, %d, %d, %f, %f, %f, %d, %s, %s)';
		}

		$table = $wpdb->prefix . 'ai_media_jobs';

		$sql = "INSERT INTO {$table}
				(attachment_id, language_code, status, provider, model, prompt_version,
				 request_data, response_data, input_tokens, output_tokens,
				 input_cost, output_cost, total_cost, score, created_at, processed_at)
				VALUES " . implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, $values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $prepared );

		return (int) $result;
	}

	/**
	 * Bulk get post meta for multiple posts.
	 *
	 * Retrieves metadata for multiple posts in a single query.
	 * Useful for context prefetching.
	 *
	 * @since 2.2.0
	 * @param array $post_ids Array of post IDs.
	 * @param array $meta_keys Optional. Specific meta keys to retrieve.
	 * @return array Array of [post_id => [meta_key => meta_value]].
	 */
	public function bulk_get_post_meta( array $post_ids, array $meta_keys = array() ): array {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return array();
		}

		$post_ids_list = implode( ',', array_map( 'intval', $post_ids ) );

		if ( ! empty( $meta_keys ) ) {
			$meta_keys_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
			$query                  = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE post_id IN ({$post_ids_list})
				AND meta_key IN ({$meta_keys_placeholders})",
				$meta_keys
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$query = "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
					  WHERE post_id IN ({$post_ids_list})";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $query, ARRAY_A );

		$metadata = array();

		foreach ( $results as $row ) {
			$post_id              = (int) $row['post_id'];
			$meta_key             = $row['meta_key'];
			$meta_value           = maybe_unserialize( $row['meta_value'] );
			$metadata[ $post_id ][ $meta_key ] = $meta_value;
		}

		return $metadata;
	}

	/**
	 * Bulk get posts data.
	 *
	 * Retrieves post data for multiple posts in a single query.
	 *
	 * @since 2.2.0
	 * @param array $post_ids Array of post IDs.
	 * @return array Array of post objects indexed by post ID.
	 */
	public function bulk_get_posts( array $post_ids ): array {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return array();
		}

		$post_ids_list = implode( ',', array_map( 'intval', $post_ids ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = "SELECT * FROM {$wpdb->posts} WHERE ID IN ({$post_ids_list})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$posts = $wpdb->get_results( $query, OBJECT );

		$indexed = array();

		foreach ( $posts as $post ) {
			$indexed[ $post->ID ] = $post;
		}

		return $indexed;
	}

	/**
	 * Bulk get term relationships (categories, tags).
	 *
	 * Retrieves taxonomy terms for multiple posts in a single query.
	 *
	 * @since 2.2.0
	 * @param array  $post_ids  Array of post IDs.
	 * @param string $taxonomy  Taxonomy name (e.g., 'category', 'post_tag').
	 * @return array Array of [post_id => [term_ids]].
	 */
	public function bulk_get_term_relationships( array $post_ids, string $taxonomy ): array {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return array();
		}

		$post_ids_list = implode( ',', array_map( 'intval', $post_ids ) );

		$query = $wpdb->prepare(
			"SELECT tr.object_id, t.term_id, t.name
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			WHERE tr.object_id IN ({$post_ids_list})
			AND tt.taxonomy = %s",
			$taxonomy
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $query, ARRAY_A );

		$relationships = array();

		foreach ( $results as $row ) {
			$post_id = (int) $row['object_id'];
			$relationships[ $post_id ][] = array(
				'term_id' => (int) $row['term_id'],
				'name'    => $row['name'],
			);
		}

		return $relationships;
	}
}
