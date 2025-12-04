<?php
/**
 * The core plugin class.
 *
 * @package    AIMediaSEO
 * @subpackage Core
 * @since      1.0.0
 */

namespace AIMediaSEO\Core;

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since  1.0.0
	 * @access protected
	 * @var    Loader $loader Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since  1.0.0
	 * @access protected
	 * @var    string $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since  1.0.0
	 * @access protected
	 * @var    string $version The current version of the plugin.
	 */
	protected $version;

	/**
	 * Language detector instance
	 *
	 * @since  1.0.0
	 * @access protected
	 * @var    \AIMediaSEO\Multilingual\LanguageDetector
	 */
	protected $language_detector;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->version     = AI_MEDIA_SEO_VERSION;
		$this->plugin_name = 'ai-media-seo';

		$this->loader = new Loader();

		// Run one-time settings migrations.
		$this->migrate_settings();

		$this->set_locale();
		$this->init_multilingual();
		$this->define_admin_hooks();
		$this->define_api_hooks();
		$this->define_multilingual_hooks();
		$this->define_cron_hooks();
		$this->register_wpcli_commands();
	}

	/**
	 * Migrate settings for version updates.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function migrate_settings() {
		$settings = get_option( 'ai_media_seo_settings', array() );
		$updated = false;

		// Migrate auto_approve_threshold from 0.85 to 0.80.
		if ( isset( $settings['auto_approve_threshold'] ) && $settings['auto_approve_threshold'] == 0.85 ) {
			$settings['auto_approve_threshold'] = 0.80;
			$updated = true;
		}

		if ( $updated ) {
			update_option( 'ai_media_seo_settings', $settings );
		}
	}

	/**
	 * Initialize multilingual support.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function init_multilingual() {
		$this->language_detector = new \AIMediaSEO\Multilingual\LanguageDetector();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function set_locale() {
		$this->loader->add_action(
			'plugins_loaded',
			$this,
			'load_plugin_textdomain'
		);
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since 1.0.0
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'ai-media-seo',
			false,
			dirname( AI_MEDIA_SEO_BASENAME ) . '/languages/'
		);
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function define_admin_hooks() {
		// Initialize admin pages.
		$dashboard     = new \AIMediaSEO\Admin\Dashboard();
		$media_library = new \AIMediaSEO\Admin\MediaLibrary();
		$settings      = new \AIMediaSEO\Admin\Settings();

		$dashboard->register();
		$media_library->register();
		$settings->register();

		// Initialize post content updater for automatic ALT text synchronization.
		$post_content_updater = new \AIMediaSEO\Admin\PostContentUpdater();
		$post_content_updater->register();

		// Initialize auto processor for automatic image processing on upload.
		$auto_processor = new \AIMediaSEO\Admin\AutoProcessor();
		$auto_processor->register();

		// Add settings link to plugins page.
		$this->loader->add_filter(
			'plugin_action_links_' . AI_MEDIA_SEO_BASENAME,
			$this,
			'add_action_links'
		);
	}

	/**
	 * Register all of the hooks related to the REST API functionality.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function define_api_hooks() {
		$this->loader->add_action(
			'rest_api_init',
			$this,
			'register_rest_routes'
		);
	}

	/**
	 * Register all of the hooks related to multilingual functionality.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function define_multilingual_hooks() {
		// Filter: Allow external modification of language detection
		$this->loader->add_filter(
			'ai_media_detected_language',
			$this,
			'filter_detected_language',
			10,
			2
		);

		// Filter: Allow external modification of fallback chain
		$this->loader->add_filter(
			'ai_media_fallback_chain',
			$this,
			'filter_fallback_chain',
			10,
			2
		);
	}

	/**
	 * Register all WP-Cron hooks.
	 *
	 * @since  1.1.0
	 * @access private
	 */
	private function define_cron_hooks() {
		// Register pricing sync cron job.
		$this->loader->add_action(
			'ai_media_seo_sync_pricing',
			$this,
			'run_pricing_sync'
		);

		// Register cleanup cron job.
		$this->loader->add_action(
			'ai_media_cleanup_logs',
			$this,
			'cleanup_old_logs'
		);
	}

	/**
	 * Run pricing synchronization.
	 *
	 * Callback for WP-Cron to sync model pricing from external CSV.
	 *
	 * @since 1.1.0
	 */
	public function run_pricing_sync(): void {
		$synchronizer = new \AIMediaSEO\Pricing\PricingSynchronizer();
		$synchronizer->sync_pricing();
	}

	/**
	 * Cleanup old logs callback.
	 *
	 * Removes logs older than 90 days.
	 *
	 * @since 1.0.0
	 */
	public function cleanup_old_logs(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_events';
		$days_ago   = 90;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name}
				WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days_ago
			)
		);

	}

	/**
	 * Enqueue admin area scripts and styles.
	 *
	 * @since 1.0.0
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on our admin pages.
		if ( ! $this->is_ai_media_page( $hook ) ) {
			return;
		}

		// Enqueue WordPress dependencies.
		wp_enqueue_script( 'react' );
		wp_enqueue_script( 'react-dom' );
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );
		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_script( 'wp-i18n' );

		// Enqueue base admin styles.
		wp_enqueue_style(
			$this->plugin_name . '-admin',
			AI_MEDIA_SEO_URL . 'assets/css/admin.css',
			array(),
			$this->version,
			'all'
		);

		// Determine which React component to load based on page.
		$page_scripts = array(
			'toplevel_page_ai-media-seo'      => 'dashboard',
			'ai-media_page_ai-media-library'  => 'library',
			'ai-media_page_ai-media-queue'    => 'queue',
			'ai-media_page_ai-media-settings' => 'settings',
		);

		$script_name = $page_scripts[ $hook ] ?? null;

		if ( $script_name ) {
			// Enqueue page-specific bundle.
			wp_enqueue_script(
				$this->plugin_name . '-' . $script_name,
				AI_MEDIA_SEO_URL . 'assets/js/dist/' . $script_name . '.bundle.js',
				array( 'wp-element', 'wp-api-fetch', 'wp-i18n' ),
				$this->version,
				true
			);

			// Enqueue page-specific styles.
			wp_enqueue_style(
				$this->plugin_name . '-' . $script_name,
				AI_MEDIA_SEO_URL . 'assets/css/dist/' . $script_name . '.bundle.css',
				array(),
				$this->version,
				'all'
			);
		}

		// Localize script with data (available to all pages).
		wp_localize_script(
			'wp-api-fetch',
			'aiMediaSEO',
			array(
				'apiUrl'    => rest_url( 'ai-media/v1/' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'version'   => $this->version,
				'settings'  => get_option( 'ai_media_seo_settings', array() ),
				'providers' => get_option( 'ai_media_seo_providers', array() ),
				'languages' => array(
					'current'     => $this->language_detector->get_current_language(),
					'default'     => $this->language_detector->get_default_language(),
					'available'   => $this->language_detector->get_languages(),
					'plugin'      => $this->language_detector->get_active_plugin(),
					'isMultilingual' => $this->language_detector->is_multilingual_active(),
				),
				'i18n'      => array(
					'processing' => __( 'Processing...', 'ai-media-seo' ),
					'success'    => __( 'Success!', 'ai-media-seo' ),
					'error'      => __( 'Error occurred', 'ai-media-seo' ),
				),
			)
		);
	}

	/**
	 * Check if current page is an AI Media SEO admin page.
	 *
	 * @since 1.0.0
	 * @param string $hook The current admin page hook.
	 * @return bool
	 */
	private function is_ai_media_page( $hook ) {
		$ai_media_pages = array(
			'toplevel_page_ai-media-seo',
			'ai-media_page_ai-media-library',
			'ai-media_page_ai-media-queue',
			'ai-media_page_ai-media-prompts',
			'ai-media_page_ai-media-logs',
			'ai-media_page_ai-media-settings',
		);

		return in_array( $hook, $ai_media_pages, true );
	}

	/**
	 * Add admin menu pages.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		// Main menu page - Dashboard.
		add_menu_page(
			__( 'AI Media SEO', 'ai-media-seo' ),
			__( 'AI Media', 'ai-media-seo' ),
			'ai_media_process_images',
			'ai-media-seo',
			array( $this, 'render_dashboard_page' ),
			'dashicons-images-alt2',
			30
		);

		// Submenu - Dashboard (rename the first item).
		add_submenu_page(
			'ai-media-seo',
			__( 'Dashboard', 'ai-media-seo' ),
			__( 'Dashboard', 'ai-media-seo' ),
			'ai_media_process_images',
			'ai-media-seo',
			array( $this, 'render_dashboard_page' )
		);

		// Submenu - Library.
		add_submenu_page(
			'ai-media-seo',
			__( 'Media Library', 'ai-media-seo' ),
			__( 'Library', 'ai-media-seo' ),
			'ai_media_process_images',
			'ai-media-library',
			array( $this, 'render_library_page' )
		);

		// Submenu - Queue.
		add_submenu_page(
			'ai-media-seo',
			__( 'Processing Queue', 'ai-media-seo' ),
			__( 'Queue', 'ai-media-seo' ),
			'ai_media_process_images',
			'ai-media-queue',
			array( $this, 'render_queue_page' )
		);

		// Submenu - Settings.
		add_submenu_page(
			'ai-media-seo',
			__( 'Settings', 'ai-media-seo' ),
			__( 'Settings', 'ai-media-seo' ),
			'ai_media_manage_settings',
			'ai-media-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render dashboard page.
	 *
	 * @since 1.0.0
	 */
	public function render_dashboard_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Media SEO - Dashboard', 'ai-media-seo' ) . '</h1>';
		echo '<div id="ai-media-dashboard-root"></div>';
		echo '</div>';
	}

	/**
	 * Render library page.
	 *
	 * @since 1.0.0
	 */
	public function render_library_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Media SEO - Media Library', 'ai-media-seo' ) . '</h1>';
		echo '<div id="ai-media-library-root"></div>';
		echo '</div>';
	}

	/**
	 * Render queue page.
	 *
	 * @since 1.0.0
	 */
	public function render_queue_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Media SEO - Processing Queue', 'ai-media-seo' ) . '</h1>';
		echo '<div id="ai-media-queue-root"></div>';
		echo '</div>';
	}

	/**
	 * Render settings page.
	 *
	 * @since 1.0.0
	 */
	public function render_settings_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Media SEO - Settings', 'ai-media-seo' ) . '</h1>';
		echo '<div id="ai-media-settings-root"></div>';
		echo '</div>';
	}

	/**
	 * Add action links to plugin page.
	 *
	 * @since 1.0.0
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=ai-media-settings' ),
			__( 'Settings', 'ai-media-seo' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 */
	public function register_rest_routes() {
		$controller = new \AIMediaSEO\API\RestController();
		$controller->register_routes();
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since 1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since  1.0.0
	 * @return string The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since  1.0.0
	 * @return Loader Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since  1.0.0
	 * @return string The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Get language detector instance.
	 *
	 * @since  1.0.0
	 * @return \AIMediaSEO\Multilingual\LanguageDetector
	 */
	public function get_language_detector() {
		return $this->language_detector;
	}

	/**
	 * Filter detected language.
	 *
	 * Allows external modification of detected language for an attachment.
	 *
	 * @since 1.0.0
	 * @param string $language      Detected language code.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Filtered language code.
	 */
	public function filter_detected_language( $language, $attachment_id ) {
		return $language;
	}

	/**
	 * Filter fallback chain.
	 *
	 * Allows external modification of fallback chain for a language.
	 *
	 * @since 1.0.0
	 * @param array  $chain         Fallback chain.
	 * @param string $language_code Language code.
	 * @return array Filtered fallback chain.
	 */
	public function filter_fallback_chain( $chain, $language_code ) {
		return $chain;
	}

	/**
	 * Register WP-CLI commands.
	 *
	 * @since 1.0.0
	 */
	private function register_wpcli_commands() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		// Register CLI commands (available in freemium version).
		\WP_CLI::add_command( 'ai-media', '\AIMediaSEO\CLI\Commands' );
	}
}
