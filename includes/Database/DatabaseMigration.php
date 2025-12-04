<?php
/**
 * Database Migration Manager
 *
 * Handles database schema upgrades and migrations.
 *
 * @package    AIMediaSEO
 * @subpackage Database
 * @since      1.1.0
 */

namespace AIMediaSEO\Database;

/**
 * DatabaseMigration class.
 *
 * Manages incremental database migrations.
 *
 * @since 1.1.0
 */
class DatabaseMigration {

	/**
	 * Current database version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Target database version.
	 *
	 * @var string
	 */
	private $target_version;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb            = $wpdb;
		$this->current_version = get_option( 'ai_media_seo_db_version', '1.0.0' );
		$this->target_version  = AI_MEDIA_SEO_DB_VERSION;
	}

	/**
	 * Run migrations if needed.
	 *
	 * @since 1.1.0
	 * @return array Migration results.
	 */
	public function run_migrations(): array {
		$results = array(
			'success'    => true,
			'migrations' => array(),
			'errors'     => array(),
		);

		// Check if migration is needed.
		if ( version_compare( $this->current_version, $this->target_version, '>=' ) ) {
			$results['message'] = 'Database is up to date.';
			return $results;
		}

		// Run migrations in order.
		$migrations = $this->get_migrations();

		foreach ( $migrations as $version => $migration_method ) {
			// Only run migrations newer than current version.
			if ( version_compare( $version, $this->current_version, '>' ) &&
				version_compare( $version, $this->target_version, '<=' ) ) {

				try {
					$result = call_user_func( array( $this, $migration_method ) );

					$results['migrations'][] = array(
						'version' => $version,
						'status'  => $result ? 'success' : 'failed',
					);

					if ( ! $result ) {
						$results['success'] = false;
						$results['errors'][] = "Migration to {$version} failed.";
					}
				} catch ( \Exception $e ) {
					$results['success'] = false;
					$results['errors'][] = "Migration to {$version} error: " . $e->getMessage();
				}
			}
		}

		// Update database version if all migrations succeeded.
		if ( $results['success'] ) {
			update_option( 'ai_media_seo_db_version', $this->target_version );
		}

		return $results;
	}

	/**
	 * Get list of migrations.
	 *
	 * @since 1.1.0
	 * @return array Migration map (version => method name).
	 */
	private function get_migrations(): array {
		return array(
			'1.1.0' => 'migrate_1_1_0',
			'1.2.0' => 'migrate_1_2_0',
		);
	}

	/**
	 * Migration to version 1.1.0.
	 *
	 * Adds token cost tracking tables and columns.
	 *
	 * @since 1.1.0
	 * @return bool True on success, false on failure.
	 */
	private function migrate_1_1_0(): bool {
		$success = true;

		// 1. Create wp_ai_media_pricing table.
		$success = $success && $this->create_pricing_table();

		// 2. Add token/cost columns to wp_ai_media_jobs.
		$success = $success && $this->add_token_cost_columns();

		return $success;
	}

	/**
	 * Create pricing table.
	 *
	 * @since 1.1.0
	 * @return bool True on success.
	 */
	private function create_pricing_table(): bool {
		$table_name      = $this->wpdb->prefix . 'ai_media_pricing';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			model_name varchar(100) NOT NULL,
			input_price_per_million decimal(10,6) NOT NULL,
			output_price_per_million decimal(10,6) NOT NULL,
			last_updated datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY model_name (model_name),
			KEY last_updated (last_updated)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Verify table was created.
		$table_exists = $this->wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name;

		return $table_exists;
	}

	/**
	 * Add token and cost columns to jobs table.
	 *
	 * @since 1.1.0
	 * @return bool True on success.
	 */
	private function add_token_cost_columns(): bool {
		$table_name = $this->wpdb->prefix . 'ai_media_jobs';

		// Check if columns already exist.
		$columns = $this->wpdb->get_results( "SHOW COLUMNS FROM {$table_name}" );
		$column_names = array_column( $columns, 'Field' );

		$columns_to_add = array(
			'input_tokens'            => "ADD COLUMN input_tokens int(11) DEFAULT NULL AFTER model",
			'output_tokens'           => "ADD COLUMN output_tokens int(11) DEFAULT NULL AFTER input_tokens",
			'estimated_input_tokens'  => "ADD COLUMN estimated_input_tokens tinyint(1) DEFAULT 0 AFTER output_tokens",
			'input_cost'              => "ADD COLUMN input_cost decimal(10,8) DEFAULT NULL AFTER estimated_input_tokens",
			'output_cost'             => "ADD COLUMN output_cost decimal(10,8) DEFAULT NULL AFTER input_cost",
			'total_cost'              => "ADD COLUMN total_cost decimal(10,8) DEFAULT NULL AFTER output_cost",
		);

		$success = true;

		foreach ( $columns_to_add as $column_name => $alter_statement ) {
			// Skip if column already exists.
			if ( in_array( $column_name, $column_names, true ) ) {
				continue;
			}

			$sql = "ALTER TABLE {$table_name} {$alter_statement}";
			$result = $this->wpdb->query( $sql );

			if ( false === $result ) {
				$success = false;
			}
		}

		// Add indexes.
		if ( $success ) {
			$indexes_to_add = array(
				'input_tokens' => "ADD KEY input_tokens (input_tokens)",
				'total_cost'   => "ADD KEY total_cost (total_cost)",
			);

			foreach ( $indexes_to_add as $index_name => $index_statement ) {
				// Check if index already exists.
				$index_exists = $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SHOW INDEX FROM {$table_name} WHERE Key_name = %s",
						$index_name
					)
				);

				if ( $index_exists ) {
					continue;
				}

				$sql = "ALTER TABLE {$table_name} {$index_statement}";
				$this->wpdb->query( $sql );
			}
		}

		return $success;
	}

	/**
	 * Migration to version 1.2.0.
	 *
	 * Adds cache pricing columns and provider tracking.
	 *
	 * @since 1.2.0
	 * @return bool True on success, false on failure.
	 */
	private function migrate_1_2_0(): bool {
		$success = true;

		// Add cache pricing columns to wp_ai_media_pricing table.
		$success = $success && $this->add_cache_pricing_columns();

		return $success;
	}

	/**
	 * Add cache pricing columns to pricing table.
	 *
	 * Adds support for cache read/write pricing and provider/source tracking.
	 *
	 * @since 1.2.0
	 * @return bool True on success.
	 */
	private function add_cache_pricing_columns(): bool {
		$table_name = $this->wpdb->prefix . 'ai_media_pricing';

		// Check if columns already exist.
		$columns = $this->wpdb->get_results( "SHOW COLUMNS FROM {$table_name}" );
		$column_names = array_column( $columns, 'Field' );

		$columns_to_add = array(
			'cache_read_price_per_million'  => "ADD COLUMN cache_read_price_per_million decimal(10,8) DEFAULT NULL AFTER output_price_per_million",
			'cache_write_price_per_million' => "ADD COLUMN cache_write_price_per_million decimal(10,8) DEFAULT NULL AFTER cache_read_price_per_million",
			'provider'                       => "ADD COLUMN provider varchar(50) DEFAULT NULL AFTER model_name",
			'source'                         => "ADD COLUMN source varchar(100) DEFAULT 'csv' AFTER last_updated",
		);

		$success = true;

		foreach ( $columns_to_add as $column_name => $alter_statement ) {
			// Skip if column already exists.
			if ( in_array( $column_name, $column_names, true ) ) {
				continue;
			}

			$sql = "ALTER TABLE {$table_name} {$alter_statement}";
			$result = $this->wpdb->query( $sql );

			if ( false === $result ) {
				$success = false;
			}
		}

		// Add index on provider.
		if ( $success ) {
			$index_name = 'provider';
			$index_exists = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SHOW INDEX FROM {$table_name} WHERE Key_name = %s",
					$index_name
				)
			);

			if ( ! $index_exists ) {
				$sql = "ALTER TABLE {$table_name} ADD KEY provider (provider)";
				$this->wpdb->query( $sql );
			}
		}

		return $success;
	}

	/**
	 * Get current database version.
	 *
	 * @since 1.1.0
	 * @return string Current version.
	 */
	public function get_current_version(): string {
		return $this->current_version;
	}

	/**
	 * Get target database version.
	 *
	 * @since 1.1.0
	 * @return string Target version.
	 */
	public function get_target_version(): string {
		return $this->target_version;
	}
}
