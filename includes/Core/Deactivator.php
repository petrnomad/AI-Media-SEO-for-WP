<?php
/**
 * Fired during plugin deactivation.
 *
 * @package    AIMediaSEO
 * @subpackage Core
 * @since      1.0.0
 */

namespace AIMediaSEO\Core;

/**
 * Deactivator class.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since 1.0.0
 */
class Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * Cleanup tasks on plugin deactivation.
	 * Note: We preserve data and tables for safety.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		self::clear_scheduled_actions();
		self::clear_cron_jobs();
		self::clear_transients();

		// Flush rewrite rules.
		flush_rewrite_rules();

		// Log deactivation.
	}

	/**
	 * Clear all scheduled Action Scheduler actions.
	 *
	 * @since 1.0.0
	 */
	private static function clear_scheduled_actions() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// Clear all AI Media SEO scheduled actions.
			as_unschedule_all_actions( 'ai_media_process_batch', array(), 'ai-media' );
			as_unschedule_all_actions( 'ai_media_process_single', array(), 'ai-media' );
			as_unschedule_all_actions( 'ai_media_cleanup', array(), 'ai-media' );

		}
	}

	/**
	 * Clear all WP cron jobs.
	 *
	 * @since 1.0.0
	 */
	private static function clear_cron_jobs() {
		// Clear cleanup cron.
		wp_clear_scheduled_hook( 'ai_media_cleanup_logs' );

		// Clear pricing sync schedule.
		wp_clear_scheduled_hook( 'ai_media_seo_sync_pricing' );
	}

	/**
	 * Clear all plugin transients.
	 *
	 * @since 1.0.0
	 */
	private static function clear_transients() {
		global $wpdb;

		// Delete all transients that start with ai_media_.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '\_transient\_ai\_media\_%'
			OR option_name LIKE '\_transient\_timeout\_ai\_media\_%'"
		);

	}

	/**
	 * Uninstall the plugin completely.
	 *
	 * This method should only be called from uninstall.php.
	 * It removes all plugin data including tables and options.
	 *
	 * @since 1.0.0
	 */
	public static function uninstall() {
		global $wpdb;

		// Only proceed if user has permission.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Check if we should preserve data.
		$settings = get_option( 'ai_media_seo_settings', array() );
		if ( isset( $settings['preserve_data_on_uninstall'] ) && $settings['preserve_data_on_uninstall'] ) {
			return;
		}

		self::drop_tables();
		self::delete_options();
		self::delete_post_meta();
		self::remove_capabilities();

	}

	/**
	 * Drop plugin database tables.
	 *
	 * @since 1.0.0
	 */
	private static function drop_tables() {
		global $wpdb;

		$table_prefix  = $wpdb->prefix;
		$jobs_table    = $table_prefix . 'ai_media_jobs';
		$events_table  = $table_prefix . 'ai_media_events';
		$pricing_table = $table_prefix . 'ai_media_pricing';

		$wpdb->query( "DROP TABLE IF EXISTS {$jobs_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$events_table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$pricing_table}" );
	}

	/**
	 * Delete all plugin options.
	 *
	 * @since 1.0.0
	 */
	private static function delete_options() {
		global $wpdb;

		delete_option( 'ai_media_seo_settings' );
		delete_option( 'ai_media_seo_providers' );
		delete_option( 'ai_media_seo_quality_rules' );
		delete_option( 'ai_media_seo_db_version' );
		delete_option( 'ai_media_seo_activated' );

		// Clean up any legacy license-related options (from pre-freemium versions).
		delete_option( 'ai_media_license_key' );
		delete_option( 'ai_media_license_token' );
		delete_option( 'ai_media_license_tier' );
		delete_option( 'ai_media_license_status' );
		delete_option( 'ai_media_license_last_check' );
		delete_option( 'ai_media_license_features' );
		delete_transient( 'ai_media_license_valid' );

		// Clean up daily limit transients (from Lite version).
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_ai_media_daily_count_%'
			OR option_name LIKE '_transient_timeout_ai_media_daily_count_%'"
		);

	}

	/**
	 * Delete all AI-generated post meta.
	 *
	 * @since 1.0.0
	 */
	private static function delete_post_meta() {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->postmeta}
			WHERE meta_key LIKE 'ai\_alt\_%'
			OR meta_key LIKE 'ai\_caption\_%'
			OR meta_key LIKE 'ai\_title\_%'
			OR meta_key LIKE 'ai\_keywords\_%'
			OR meta_key LIKE 'ai\_score\_%'
			OR meta_key IN (
				'ai_status',
				'ai_last_provider',
				'ai_last_model',
				'ai_prompt_version',
				'ai_old_values'
			)"
		);

	}

	/**
	 * Remove custom capabilities.
	 *
	 * @since 1.0.0
	 */
	private static function remove_capabilities() {
		$roles = array( 'administrator', 'editor' );

		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );

			if ( $role ) {
				$role->remove_cap( 'ai_media_manage_settings' );
				$role->remove_cap( 'ai_media_process_images' );
				$role->remove_cap( 'ai_media_approve_metadata' );
				$role->remove_cap( 'ai_media_view_logs' );
			}
		}

	}
}
