<?php
/**
 * Media Library Admin Page
 *
 * Custom media library view for AI Media SEO.
 *
 * @package    AIMediaSEO
 * @subpackage Admin
 * @since      1.0.0
 */

namespace AIMediaSEO\Admin;

use AIMediaSEO\Storage\MetadataStore;
use AIMediaSEO\Multilingual\LanguageDetector;

/**
 * MediaLibrary class.
 *
 * Handles the media library admin page with AI metadata management.
 *
 * @since 1.0.0
 */
class MediaLibrary {

	/**
	 * Metadata store instance.
	 *
	 * @var MetadataStore
	 */
	private $metadata_store;

	/**
	 * Language detector instance.
	 *
	 * @var LanguageDetector
	 */
	private $language_detector;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->metadata_store    = new MetadataStore();
		$this->language_detector = new LanguageDetector();
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'set-screen-option', array( $this, 'set_screen_option' ), 10, 3 );
	}

	/**
	 * Add media library submenu page.
	 *
	 * @since 1.0.0
	 */
	public function add_submenu_page() {
		$hook = add_submenu_page(
			'ai-media-seo',
			__( 'Library', 'ai-media-seo' ),
			__( 'Library', 'ai-media-seo' ),
			'upload_files',
			'ai-media-library',
			array( $this, 'render_page' )
		);

		// Add screen options.
		add_action( "load-{$hook}", array( $this, 'add_screen_options' ) );
	}

	/**
	 * Add screen options for per_page and columns.
	 *
	 * @since 1.0.0
	 */
	public function add_screen_options() {
		// Add per_page option.
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Images per page', 'ai-media-seo' ),
				'default' => 20,
				'option'  => 'ai_media_per_page',
			)
		);

		// Add column visibility checkboxes via screen_settings filter.
		add_filter( 'screen_settings', array( $this, 'render_column_screen_options' ), 10, 2 );
	}

	/**
	 * Render column visibility options in Screen Options.
	 *
	 * @since 1.0.0
	 * @param string    $screen_settings Screen settings HTML.
	 * @param WP_Screen $screen          Current screen object.
	 * @return string Modified screen settings HTML.
	 */
	public function render_column_screen_options( $screen_settings, $screen ) {
		// Only add to our media library page.
		if ( 'ai-media_page_ai-media-library' !== $screen->id ) {
			return $screen_settings;
		}

		$user           = get_current_user_id();
		$hidden_columns = get_user_meta( $user, 'manageai-media_page_ai-media-libraryhidden_columns', true );
		$hidden_columns = is_array( $hidden_columns ) ? $hidden_columns : array();

		$columns = array(
			'file'        => __( 'File', 'ai-media-seo' ),
			'alt'         => __( 'ALT Text', 'ai-media-seo' ),
			'caption'     => __( 'Caption', 'ai-media-seo' ),
			'status'      => __( 'Status', 'ai-media-seo' ),
			'attached_to' => __( 'Attached to', 'ai-media-seo' ),
			'provider'    => __( 'Provider', 'ai-media-seo' ),
			'score'       => __( 'Score', 'ai-media-seo' ),
		);

		ob_start();
		?>
		<fieldset class="metabox-prefs">
			<legend><?php esc_html_e( 'Columns', 'ai-media-seo' ); ?></legend>
			<?php foreach ( $columns as $column_key => $column_label ) : ?>
				<label for="ai-media-column-<?php echo esc_attr( $column_key ); ?>">
					<input
						type="checkbox"
						id="ai-media-column-<?php echo esc_attr( $column_key ); ?>"
						name="ai_media_hidden_columns[]"
						value="<?php echo esc_attr( $column_key ); ?>"
						<?php checked( ! in_array( $column_key, $hidden_columns, true ) ); ?>
						class="ai-media-column-toggle"
					/>
					<?php echo esc_html( $column_label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Handle column visibility changes
			$('.ai-media-column-toggle').on('change', function() {
				var hiddenColumns = [];
				$('.ai-media-column-toggle:not(:checked)').each(function() {
					hiddenColumns.push($(this).val());
				});

				// Save via AJAX
				$.ajax({
					url: '<?php echo esc_url( rest_url( 'ai-media/v1/user-preferences' ) ); ?>',
					method: 'POST',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>');
					},
					data: JSON.stringify({ hidden_columns: hiddenColumns }),
					contentType: 'application/json',
					success: function() {
						// Trigger custom event to update React component
						var event = new CustomEvent('aiMediaColumnVisibilityChanged', {
							detail: { hiddenColumns: hiddenColumns }
						});
						window.dispatchEvent(event);
					}
				});
			});
		});
		</script>
		<?php
		return $screen_settings . ob_get_clean();
	}

	/**
	 * Save screen option.
	 *
	 * @since 1.0.0
	 * @param mixed  $status Screen option value.
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return mixed
	 */
	public function set_screen_option( $status, $option, $value ) {
		if ( 'ai_media_per_page' === $option ) {
			return $value;
		}
		return $status;
	}

	/**
	 * Enqueue assets.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'ai-media_page_ai-media-library' !== $hook ) {
			return;
		}

		// Enqueue React bundle.
		$asset_file = AI_MEDIA_SEO_PATH . 'admin/build/media-library.asset.php';
		if ( file_exists( $asset_file ) ) {
			$asset = include $asset_file;

			wp_enqueue_script(
				'ai-media-seo-library',
				AI_MEDIA_SEO_URL . 'admin/build/media-library.js',
				$asset['dependencies'] ?? array( 'wp-element', 'wp-api-fetch', 'wp-components' ),
				$asset['version'] ?? AI_MEDIA_SEO_VERSION,
				true
			);

			wp_enqueue_style(
				'ai-media-seo-library',
				AI_MEDIA_SEO_URL . 'admin/build/media-library.css',
				array( 'wp-components' ),
				$asset['version'] ?? AI_MEDIA_SEO_VERSION
			);
		} else {
			// Fallback: development mode.
			wp_enqueue_script(
				'ai-media-seo-library',
				AI_MEDIA_SEO_URL . 'admin/src/media-library.jsx',
				array( 'wp-element', 'wp-api-fetch', 'wp-components' ),
				AI_MEDIA_SEO_VERSION,
				true
			);

			wp_enqueue_style(
				'ai-media-seo-library',
				AI_MEDIA_SEO_URL . 'admin/css/media-library.css',
				array( 'wp-components' ),
				AI_MEDIA_SEO_VERSION
			);
		}

		// Get screen options.
		$user            = get_current_user_id();
		$per_page        = get_user_meta( $user, 'ai_media_per_page', true );
		$per_page        = $per_page ? $per_page : 20;
		$hidden_columns  = get_user_meta( $user, 'manageai-media_page_ai-media-libraryhidden_columns', true );
		$hidden_columns  = is_array( $hidden_columns ) ? $hidden_columns : array();

		// Localize script data.
		wp_localize_script(
			'ai-media-seo-library',
			'aiMediaSEO',
			array(
				'apiUrl'     => rest_url( 'ai-media/v1' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'wpApiUrl'   => rest_url(),
				'languages'  => array(
					'current'        => $this->language_detector->get_current_language(),
					'default'        => $this->language_detector->get_default_language(),
					'available'      => $this->language_detector->get_languages(),
					'isMultilingual' => $this->language_detector->is_multilingual_active(),
				),
				'settings'   => get_option( 'ai_media_seo_settings', array() ),
				'isPro'      => true, // Always true in freemium version
				'screenOptions' => array(
					'perPage'        => (int) $per_page,
					'hiddenColumns'  => $hidden_columns,
				),
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
			<div id="ai-media-library-root"></div>
		</div>
		<?php
	}
}
