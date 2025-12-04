<?php
/**
 * Fired during plugin activation.
 *
 * @package    AIMediaSEO
 * @subpackage Core
 * @since      1.0.0
 */

namespace AIMediaSEO\Core;

/**
 * Activator class.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since 1.0.0
 */
class Activator {

	/**
	 * Activate the plugin.
	 *
	 * Creates database tables and sets default options.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		self::create_tables();
		self::set_default_options();
		self::create_capabilities();
		self::schedule_cron_jobs();

		// Store activation time.
		if ( ! get_option( 'ai_media_seo_activated' ) ) {
			add_option( 'ai_media_seo_activated', time() );
		}

		// Run database migrations.
		$migration = new \AIMediaSEO\Database\DatabaseMigration();
		$migration->run_migrations();

		// Sync pricing data if pricing table is empty.
		self::sync_pricing_data();

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Create database tables.
	 *
	 * @since 1.0.0
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_prefix    = $wpdb->prefix;

		// Table for AI processing jobs.
		$jobs_table = $table_prefix . 'ai_media_jobs';

		// Table for audit events.
		$events_table = $table_prefix . 'ai_media_events';

		$sql = array();

		// Jobs table schema.
		$sql[] = "CREATE TABLE {$jobs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			language_code varchar(10) DEFAULT NULL,
			status enum('pending','processing','needs_review','approved','failed','skipped') NOT NULL DEFAULT 'pending',
			provider varchar(50) DEFAULT NULL,
			model varchar(100) DEFAULT NULL,
			prompt_version varchar(20) DEFAULT NULL,
			request_data longtext DEFAULT NULL,
			response_data longtext DEFAULT NULL,
			tokens_used int(11) DEFAULT NULL,
			cost_cents decimal(10,4) DEFAULT NULL,
			score decimal(3,2) DEFAULT NULL,
			error_message text DEFAULT NULL,
			retry_count tinyint(4) DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			processed_at datetime DEFAULT NULL,
			approved_at datetime DEFAULT NULL,
			approved_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_attachment (attachment_id),
			KEY idx_status (status),
			KEY idx_language (language_code),
			KEY idx_created (created_at)
		) $charset_collate;";

		// Events table schema.
		$sql[] = "CREATE TABLE {$events_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			attachment_id bigint(20) unsigned DEFAULT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			job_id bigint(20) unsigned DEFAULT NULL,
			language_code varchar(10) DEFAULT NULL,
			meta_json text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_attachment (attachment_id),
			KEY idx_event (event_type),
			KEY idx_created (created_at)
		) $charset_collate;";

		// Include upgrade file.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Execute queries.
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		// Verify tables were created.
		$tables_created = array(
			$jobs_table   => $wpdb->get_var( "SHOW TABLES LIKE '{$jobs_table}'" ),
			$events_table => $wpdb->get_var( "SHOW TABLES LIKE '{$events_table}'" ),
		);

		// Log creation status.
		foreach ( $tables_created as $table_name => $result ) {
			if ( $result === $table_name ) {
			} else {
			}
		}
	}

	/**
	 * Set default plugin options.
	 *
	 * @since 1.0.0
	 */
	private static function set_default_options() {
		$default_settings = array(
			'version'              => AI_MEDIA_SEO_VERSION,
			'batch_size'           => 50,
			'max_concurrent'       => 3,
			'rate_limit_rpm'       => 120,
			'backoff_base'         => 5,
			'backoff_max'          => 300,
			'auto_approve_threshold' => 0.80, // Lowered from 0.85 to allow more auto-approvals.
			'max_image_size'       => 1600,
			'cache_duration'       => 86400, // 24 hours.
			'alt_max_length'       => 125,
			'caption_min_words'    => 5,
			'caption_max_words'    => 30,
			'title_min_words'      => 3,
			'title_max_words'      => 6,
			'keywords_min'         => 3,
			'keywords_max'         => 6,
			'enable_auto_process'  => false,
			'primary_language'     => get_locale(),
		);

		// Only set if not already exists.
		if ( ! get_option( 'ai_media_seo_settings' ) ) {
			add_option( 'ai_media_seo_settings', $default_settings );
		} else {
			// Update existing settings with new threshold if still at old default.
			$existing_settings = get_option( 'ai_media_seo_settings', array() );
			if ( isset( $existing_settings['auto_approve_threshold'] ) && $existing_settings['auto_approve_threshold'] == 0.85 ) {
				$existing_settings['auto_approve_threshold'] = 0.80;
				update_option( 'ai_media_seo_settings', $existing_settings );
			}
		}

		// Provider settings (empty by default).
		if ( ! get_option( 'ai_media_seo_providers' ) ) {
			add_option( 'ai_media_seo_providers', array() );
		}

		// Quality rules.
		if ( ! get_option( 'ai_media_seo_quality_rules' ) ) {
			$quality_rules = array(
				'forbidden_alt_phrases' => array(
					'image of',
					'picture of',
					'photo of',
					'screenshot of',
				),
				'require_descriptive' => true,
				'min_score'          => 0.7,
			);
			add_option( 'ai_media_seo_quality_rules', $quality_rules );
		}
	}

	/**
	 * Create custom capabilities.
	 *
	 * @since 1.0.0
	 */
	private static function create_capabilities() {
		$role = get_role( 'administrator' );

		if ( $role ) {
			$role->add_cap( 'ai_media_manage_settings' );
			$role->add_cap( 'ai_media_process_images' );
			$role->add_cap( 'ai_media_approve_metadata' );
			$role->add_cap( 'ai_media_view_logs' );
		}

		// Allow editors to process and approve.
		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->add_cap( 'ai_media_process_images' );
			$editor->add_cap( 'ai_media_approve_metadata' );
		}
	}

	/**
	 * Schedule cron jobs.
	 *
	 * @since 1.0.0
	 */
	private static function schedule_cron_jobs() {
		// Schedule cleanup of old logs (weekly).
		if ( ! wp_next_scheduled( 'ai_media_cleanup_logs' ) ) {
			wp_schedule_event( time(), 'weekly', 'ai_media_cleanup_logs' );
		}

		// Schedule daily pricing sync at 3 AM.
		if ( ! wp_next_scheduled( 'ai_media_seo_sync_pricing' ) ) {
			wp_schedule_event(
				strtotime( 'tomorrow 3:00 AM' ),
				'daily',
				'ai_media_seo_sync_pricing'
			);
		}
	}

	/**
	 * Sync pricing data from CSV.
	 *
	 * Runs pricing sync on plugin activation if pricing table is empty.
	 *
	 * @since 1.1.0
	 */
	private static function sync_pricing_data() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ai_media_pricing';

		// Check if pricing table exists and is empty.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		if ( $count > 0 ) {
			// Pricing data already exists, skip sync.
			return;
		}

		// Try pricing sync from external sources (models.dev API or CSV).
		$synchronizer = new \AIMediaSEO\Pricing\PricingSynchronizer();
		$result       = $synchronizer->sync_pricing();

		// Always ensure all required models are present with correct pricing.
		self::ensure_complete_pricing();
	}

	/**
	 * Ensure complete pricing data.
	 *
	 * Checks for missing models and adds them with hardcoded pricing.
	 * Also fixes known incorrect prices from external sources.
	 *
	 * @since 1.2.0
	 */
	private static function ensure_complete_pricing() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ai_media_pricing';

		// Get currently stored models.
		$existing_models = $wpdb->get_col( "SELECT model_name FROM {$table_name}" );

		// Required pricing data (prices per million tokens in USD).
		$required_pricing = array(
			// Anthropic models.
			array(
				'model_name'                     => 'claude-sonnet-4-5-20250929',
				'provider'                       => 'anthropic',
				'input_price_per_million'        => 3.000000,
				'output_price_per_million'       => 15.000000,
				'cache_read_price_per_million'   => 0.300000,
				'cache_write_price_per_million'  => 3.750000,
				'source'                         => 'hardcoded',
			),
			array(
				'model_name'                     => 'claude-haiku-4-5-20251001',
				'provider'                       => 'anthropic',
				'input_price_per_million'        => 1.000000,
				'output_price_per_million'       => 5.000000,
				'cache_read_price_per_million'   => 0.100000,
				'cache_write_price_per_million'  => 1.250000,
				'source'                         => 'hardcoded',
			),
			array(
				'model_name'                     => 'claude-opus-4-1-20250805',
				'provider'                       => 'anthropic',
				'input_price_per_million'        => 15.000000,
				'output_price_per_million'       => 75.000000,
				'cache_read_price_per_million'   => 1.500000,
				'cache_write_price_per_million'  => 18.750000,
				'source'                         => 'hardcoded',
			),
			array(
				'model_name'                     => 'claude-3-5-haiku-20241022',
				'provider'                       => 'anthropic',
				'input_price_per_million'        => 1.000000,
				'output_price_per_million'       => 5.000000,
				'cache_read_price_per_million'   => 0.100000,
				'cache_write_price_per_million'  => 1.250000,
				'source'                         => 'hardcoded',
			),

			// Google models.
			array(
				'model_name'                     => 'gemini-1.5-flash-8b',
				'provider'                       => 'google',
				'input_price_per_million'        => 0.037500,
				'output_price_per_million'       => 0.150000,
				'cache_read_price_per_million'   => null,
				'cache_write_price_per_million'  => null,
				'source'                         => 'hardcoded',
			),
			array(
				'model_name'                     => 'gemini-1.5-flash',
				'provider'                       => 'google',
				'input_price_per_million'        => 0.075000,
				'output_price_per_million'       => 0.300000,
				'cache_read_price_per_million'   => null,
				'cache_write_price_per_million'  => null,
				'source'                         => 'hardcoded',
			),
			array(
				'model_name'                     => 'gemini-2.0-flash',
				'provider'                       => 'google',
				'input_price_per_million'        => 0.100000,
				'output_price_per_million'       => 0.400000,
				'cache_read_price_per_million'   => null,
				'cache_write_price_per_million'  => null,
				'source'                         => 'hardcoded',
			),
			array(
				'model_name'                     => 'gemini-2.5-flash',
				'provider'                       => 'google',
				'input_price_per_million'        => 0.300000,
				'output_price_per_million'       => 2.500000,
				'cache_read_price_per_million'   => null,
				'cache_write_price_per_million'  => null,
				'source'                         => 'hardcoded',
			),
			array(
				'model_name'                     => 'gemini-2.5-pro',
				'provider'                       => 'google',
				'input_price_per_million'        => 1.250000,
				'output_price_per_million'       => 10.000000,
				'cache_read_price_per_million'   => null,
				'cache_write_price_per_million'  => null,
				'source'                         => 'hardcoded',
			),

			// OpenAI models.
			array(
				'model_name'                     => 'gpt-4o',
				'provider'                       => 'openai',
				'input_price_per_million'        => 2.500000,
				'output_price_per_million'       => 10.000000,
				'cache_read_price_per_million'   => null,
				'cache_write_price_per_million'  => null,
				'source'                         => 'hardcoded',
			),
			array(
				'model_name'                     => 'gpt-4o-mini',
				'provider'                       => 'openai',
				'input_price_per_million'        => 0.150000,
				'output_price_per_million'       => 0.600000,
				'cache_read_price_per_million'   => null,
				'cache_write_price_per_million'  => null,
				'source'                         => 'hardcoded',
			),
			array(
				'model_name'                     => 'gpt-4-turbo',
				'provider'                       => 'openai',
				'input_price_per_million'        => 2.000000,
				'output_price_per_million'       => 8.000000,
				'cache_read_price_per_million'   => null,
				'cache_write_price_per_million'  => null,
				'source'                         => 'hardcoded',
			),
		);

		// Insert only missing models.
		$added_count = 0;
		foreach ( $required_pricing as $pricing ) {
			// Skip if model already exists.
			if ( in_array( $pricing['model_name'], $existing_models, true ) ) {
				continue;
			}

			$wpdb->replace(
				$table_name,
				array(
					'model_name'                     => $pricing['model_name'],
					'provider'                       => $pricing['provider'],
					'input_price_per_million'        => $pricing['input_price_per_million'],
					'output_price_per_million'       => $pricing['output_price_per_million'],
					'cache_read_price_per_million'   => $pricing['cache_read_price_per_million'],
					'cache_write_price_per_million'  => $pricing['cache_write_price_per_million'],
					'last_updated'                   => current_time( 'mysql' ),
					'source'                         => $pricing['source'],
				),
				array( '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s' )
			);

			$added_count++;
		}

		if ( $added_count > 0 ) {
			error_log( sprintf(
				'[Activator] Added %d missing pricing entries from hardcoded fallback.',
				$added_count
			) );
		}
	}
}
