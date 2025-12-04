<?php
/**
 * Performance Migration Handler
 *
 * Adds database indexes for improved query performance.
 *
 * @package    AIMediaSEO
 * @subpackage Database
 * @since      1.0.0
 */

namespace AIMediaSEO\Database;

/**
 * PerformanceMigration class.
 *
 * Handles creation of performance indexes for media library queries.
 *
 * @since 1.0.0
 */
class PerformanceMigration {

	/**
	 * Option name to track migration status.
	 *
	 * @var string
	 */
	const MIGRATION_OPTION = 'ai_media_seo_performance_indexes_v1';

	/**
	 * Run the migration.
	 *
	 * @since 1.0.0
	 * @return array Migration results with success status and messages.
	 */
	public static function run(): array {
		global $wpdb;

		// Check if already migrated.
		if ( get_option( self::MIGRATION_OPTION ) === 'completed' ) {
			return array(
				'success' => true,
				'message' => 'Performance indexes already created.',
				'indexes' => array(),
			);
		}

		$results = array(
			'success' => true,
			'message' => 'Performance indexes created successfully.',
			'indexes' => array(),
		);

		// Define indexes to create.
		$indexes = array(
			array(
				'table'       => $wpdb->posts,
				'name'        => 'idx_ai_media_post_type_mime',
				'columns'     => 'post_type, post_mime_type(20)',
				'description' => 'Speeds up WHERE post_type + post_mime_type queries',
			),
			array(
				'table'       => $wpdb->posts,
				'name'        => 'idx_ai_media_post_parent',
				'columns'     => 'post_parent',
				'description' => 'Speeds up JOIN with parent posts',
			),
			array(
				'table'       => $wpdb->postmeta,
				'name'        => 'idx_ai_media_meta_key_value',
				'columns'     => 'meta_key(191), meta_value(191)',
				'description' => 'Speeds up meta queries (filters, search)',
			),
			array(
				'table'       => $wpdb->prefix . 'ai_media_jobs',
				'name'        => 'idx_ai_media_jobs_attachment_date',
				'columns'     => 'attachment_id, created_at DESC',
				'description' => 'Speeds up latest job per attachment query',
			),
		);

		// Create each index.
		foreach ( $indexes as $index ) {
			$result = self::create_index(
				$index['table'],
				$index['name'],
				$index['columns']
			);

			$results['indexes'][] = array(
				'table'       => $index['table'],
				'name'        => $index['name'],
				'description' => $index['description'],
				'status'      => $result['status'],
				'message'     => $result['message'],
			);

			if ( ! $result['success'] ) {
				$results['success'] = false;
				$results['message'] = 'Some indexes failed to create. Check individual results.';
			}
		}

		// Mark as completed if all successful.
		if ( $results['success'] ) {
			update_option( self::MIGRATION_OPTION, 'completed' );
		}

		return $results;
	}

	/**
	 * Create a single index.
	 *
	 * @since 1.0.0
	 * @param string $table   Table name.
	 * @param string $name    Index name.
	 * @param string $columns Columns to index.
	 * @return array Result with success status and message.
	 */
	private static function create_index( string $table, string $name, string $columns ): array {
		global $wpdb;

		// Check if index already exists.
		$index_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1)
				FROM INFORMATION_SCHEMA.STATISTICS
				WHERE table_schema = %s
				AND table_name = %s
				AND index_name = %s",
				DB_NAME,
				$table,
				$name
			)
		);

		if ( $index_exists ) {
			return array(
				'success' => true,
				'status'  => 'exists',
				'message' => 'Index already exists',
			);
		}

		// Create index.
		$sql = "ALTER TABLE {$table} ADD INDEX {$name} ({$columns})";

		// Suppress errors temporarily to handle gracefully.
		$wpdb->suppress_errors( true );
		$result = $wpdb->query( $sql );
		$wpdb->suppress_errors( false );

		if ( false === $result ) {
			return array(
				'success' => false,
				'status'  => 'error',
				'message' => 'Failed to create index: ' . $wpdb->last_error,
			);
		}

		return array(
			'success' => true,
			'status'  => 'created',
			'message' => 'Index created successfully',
		);
	}

	/**
	 * Check if indexes exist.
	 *
	 * @since 1.0.0
	 * @return array Status of each index.
	 */
	public static function check_indexes(): array {
		global $wpdb;

		$indexes = array(
			array(
				'table' => $wpdb->posts,
				'name'  => 'idx_ai_media_post_type_mime',
			),
			array(
				'table' => $wpdb->posts,
				'name'  => 'idx_ai_media_post_parent',
			),
			array(
				'table' => $wpdb->postmeta,
				'name'  => 'idx_ai_media_meta_key_value',
			),
			array(
				'table' => $wpdb->prefix . 'ai_media_jobs',
				'name'  => 'idx_ai_media_jobs_attachment_date',
			),
		);

		$results = array();

		foreach ( $indexes as $index ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(1)
					FROM INFORMATION_SCHEMA.STATISTICS
					WHERE table_schema = %s
					AND table_name = %s
					AND index_name = %s",
					DB_NAME,
					$index['table'],
					$index['name']
				)
			);

			$results[] = array(
				'table'  => $index['table'],
				'name'   => $index['name'],
				'exists' => (bool) $exists,
			);
		}

		return $results;
	}

	/**
	 * Remove indexes (for rollback).
	 *
	 * @since 1.0.0
	 * @return array Removal results.
	 */
	public static function rollback(): array {
		global $wpdb;

		$indexes = array(
			array(
				'table' => $wpdb->posts,
				'name'  => 'idx_ai_media_post_type_mime',
			),
			array(
				'table' => $wpdb->posts,
				'name'  => 'idx_ai_media_post_parent',
			),
			array(
				'table' => $wpdb->postmeta,
				'name'  => 'idx_ai_media_meta_key_value',
			),
			array(
				'table' => $wpdb->prefix . 'ai_media_jobs',
				'name'  => 'idx_ai_media_jobs_attachment_date',
			),
		);

		$results = array(
			'success' => true,
			'message' => 'Indexes removed successfully.',
			'indexes' => array(),
		);

		foreach ( $indexes as $index ) {
			// Check if index exists.
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(1)
					FROM INFORMATION_SCHEMA.STATISTICS
					WHERE table_schema = %s
					AND table_name = %s
					AND index_name = %s",
					DB_NAME,
					$index['table'],
					$index['name']
				)
			);

			if ( ! $exists ) {
				$results['indexes'][] = array(
					'table'   => $index['table'],
					'name'    => $index['name'],
					'status'  => 'not_found',
					'message' => 'Index does not exist',
				);
				continue;
			}

			// Drop index.
			$sql = "ALTER TABLE {$index['table']} DROP INDEX {$index['name']}";

			$wpdb->suppress_errors( true );
			$result = $wpdb->query( $sql );
			$wpdb->suppress_errors( false );

			if ( false === $result ) {
				$results['success'] = false;
				$results['indexes'][] = array(
					'table'   => $index['table'],
					'name'    => $index['name'],
					'status'  => 'error',
					'message' => 'Failed to remove: ' . $wpdb->last_error,
				);
			} else {
				$results['indexes'][] = array(
					'table'   => $index['table'],
					'name'    => $index['name'],
					'status'  => 'removed',
					'message' => 'Index removed successfully',
				);
			}
		}

		// Clear migration option.
		delete_option( self::MIGRATION_OPTION );

		return $results;
	}
}
