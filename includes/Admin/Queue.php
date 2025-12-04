<?php
/**
 * Queue Admin Page
 *
 * Queue monitoring page for AI Media SEO.
 *
 * @package    AIMediaSEO
 * @subpackage Admin
 * @since      1.0.0
 */

namespace AIMediaSEO\Admin;

/**
 * Queue class.
 *
 * Handles the queue monitoring admin page.
 *
 * @since 1.0.0
 */
class Queue {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add queue submenu page.
	 *
	 * @since 1.0.0
	 */
	public function add_submenu_page() {
		add_submenu_page(
			'ai-media-seo',
			__( 'Queue', 'ai-media-seo' ),
			__( 'Queue', 'ai-media-seo' ),
			'upload_files',
			'ai-media-queue',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'ai-media_page_ai-media-queue' !== $hook ) {
			return;
		}

		// Enqueue React bundle.
		$asset_file = AI_MEDIA_SEO_PATH . 'admin/build/queue.asset.php';
		if ( file_exists( $asset_file ) ) {
			$asset = include $asset_file;

			wp_enqueue_script(
				'ai-media-seo-queue',
				AI_MEDIA_SEO_URL . 'admin/build/queue.js',
				$asset['dependencies'] ?? array( 'wp-element', 'wp-api-fetch', 'wp-components' ),
				$asset['version'] ?? AI_MEDIA_SEO_VERSION,
				true
			);

			wp_enqueue_style(
				'ai-media-seo-queue',
				AI_MEDIA_SEO_URL . 'admin/build/queue.css',
				array(),
				$asset['version'] ?? AI_MEDIA_SEO_VERSION
			);
		} else {
			// Fallback: development mode.
			wp_enqueue_script(
				'ai-media-seo-queue',
				AI_MEDIA_SEO_URL . 'admin/src/queue.jsx',
				array( 'wp-element', 'wp-api-fetch', 'wp-components' ),
				AI_MEDIA_SEO_VERSION,
				true
			);

			wp_enqueue_style(
				'ai-media-seo-queue',
				AI_MEDIA_SEO_URL . 'admin/css/queue.css',
				array(),
				AI_MEDIA_SEO_VERSION
			);
		}

		// Localize script data.
		wp_localize_script(
			'ai-media-seo-queue',
			'aiMediaSEO',
			array(
				'apiUrl' => rest_url( 'ai-media/v1' ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Render page.
	 *
	 * @since 1.0.0
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Processing Queue', 'ai-media-seo' ); ?></h1>
			<div id="ai-media-queue-root"></div>
		</div>
		<?php
	}
}
