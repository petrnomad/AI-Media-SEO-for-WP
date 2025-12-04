<?php
/**
 * Dashboard Admin Page
 *
 * Main dashboard for AI Media SEO plugin.
 *
 * @package    AIMediaSEO
 * @subpackage Admin
 * @since      1.0.0
 */

namespace AIMediaSEO\Admin;

use AIMediaSEO\Storage\MetadataStore;

/**
 * Dashboard class.
 *
 * Handles the main dashboard admin page.
 *
 * @since 1.0.0
 */
class Dashboard {

	/**
	 * Metadata store instance.
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
	 * Register hooks.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add dashboard menu page.
	 *
	 * @since 1.0.0
	 */
	public function add_menu_page() {
		add_menu_page(
			__( 'AI Media SEO', 'ai-media-seo' ),
			__( 'AI Media', 'ai-media-seo' ),
			'upload_files',
			'ai-media-seo',
			array( $this, 'render_dashboard' ),
			'dashicons-images-alt2',
			30
		);

		add_submenu_page(
			'ai-media-seo',
			__( 'Dashboard', 'ai-media-seo' ),
			__( 'Dashboard', 'ai-media-seo' ),
			'upload_files',
			'ai-media-seo',
			array( $this, 'render_dashboard' )
		);
	}

	/**
	 * Enqueue dashboard assets.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_ai-media-seo' !== $hook ) {
			return;
		}

		// Enqueue React bundle.
		$asset_file = AI_MEDIA_SEO_PATH . 'admin/build/dashboard.asset.php';
		if ( file_exists( $asset_file ) ) {
			$asset = include $asset_file;

			wp_enqueue_script(
				'ai-media-seo-dashboard',
				AI_MEDIA_SEO_URL . 'admin/build/dashboard.js',
				$asset['dependencies'] ?? array( 'wp-element', 'wp-api-fetch' ),
				$asset['version'] ?? AI_MEDIA_SEO_VERSION,
				true
			);

			wp_enqueue_style(
				'ai-media-seo-dashboard',
				AI_MEDIA_SEO_URL . 'admin/build/dashboard.css',
				array(),
				$asset['version'] ?? AI_MEDIA_SEO_VERSION
			);
		} else {
			// Fallback: enqueue source files for development.
			wp_enqueue_script(
				'ai-media-seo-dashboard',
				AI_MEDIA_SEO_URL . 'admin/src/dashboard.jsx',
				array( 'wp-element', 'wp-api-fetch', 'wp-components' ),
				AI_MEDIA_SEO_VERSION,
				true
			);

			wp_enqueue_style(
				'ai-media-seo-dashboard',
				AI_MEDIA_SEO_URL . 'admin/css/dashboard.css',
				array(),
				AI_MEDIA_SEO_VERSION
			);
		}

		// Localize script data.
		wp_localize_script(
			'ai-media-seo-dashboard',
			'aiMediaSEO',
			array(
				'apiUrl'    => rest_url( 'ai-media/v1' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'stats'     => $this->get_dashboard_stats(),
				'settings'  => get_option( 'ai_media_seo_settings', array() ),
				'isPro'     => true, // Always true in freemium version
			)
		);
	}

	/**
	 * Render dashboard page.
	 *
	 * @since 1.0.0
	 */
	public function render_dashboard() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Media SEO Dashboard', 'ai-media-seo' ); ?></h1>
			<div id="ai-media-dashboard-root"></div>
		</div>
		<?php
	}

	/**
	 * Get dashboard statistics.
	 *
	 * @since 1.0.0
	 * @return array Dashboard statistics.
	 */
	private function get_dashboard_stats() {
		$stats = $this->metadata_store->get_stats( 'all' );

		return array(
			'total'         => $stats['total'] ?? 0,
			'pending'       => $stats['pending'] ?? 0,
			'processing'    => $stats['processing'] ?? 0,
			'needs_review'  => $stats['needs_review'] ?? 0,
			'approved'      => $stats['approved'] ?? 0,
			'failed'        => $stats['failed'] ?? 0,
			'skipped'       => $stats['skipped'] ?? 0,
			'total_cost'    => $stats['total_cost'] ?? 0,
			'avg_score'     => $stats['avg_score'] ?? 0,
			'processed_today' => $this->get_processed_today(),
		);
	}

	/**
	 * Get count of images processed today.
	 *
	 * @since 1.0.0
	 * @return int Count of processed images today.
	 */
	private function get_processed_today() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE DATE(created_at) = %s",
				current_time( 'Y-m-d' )
			)
		);

		return (int) $count;
	}
}
