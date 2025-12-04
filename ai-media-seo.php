<?php
/**
 * AI Media SEO - WordPress Plugin
 *
 * @package     AIMediaSEO
 * @author      Petr Novák
 * @copyright   2025 Petr Novák
 * @license     GPL-3.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       AI Media SEO
 * Plugin URI:        https://github.com/petrnomad/AI-Media-SEO-for-WP
 * Description:       Automatically generate SEO-optimized image metadata (ALT, caption, title, keywords) using AI providers (OpenAI, Anthropic, Google). Multilingual support via Polylang/WPML.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            Petr Novák
 * Author URI:        https://petrnovak.com
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ai-media-seo
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version.
 */
define( 'AI_MEDIA_SEO_VERSION', '1.0.0' );

/**
 * Plugin directory path.
 */
define( 'AI_MEDIA_SEO_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'AI_MEDIA_SEO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'AI_MEDIA_SEO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Database version for migrations.
 */
define( 'AI_MEDIA_SEO_DB_VERSION', '1.2.0' );

/**
 * Minimum PHP version required.
 */
define( 'AI_MEDIA_SEO_MIN_PHP_VERSION', '8.1' );

/**
 * Minimum WordPress version required.
 */
define( 'AI_MEDIA_SEO_MIN_WP_VERSION', '6.3' );

/**
 * Check PHP version compatibility.
 */
if ( version_compare( PHP_VERSION, AI_MEDIA_SEO_MIN_PHP_VERSION, '<' ) ) {
	add_action( 'admin_notices', 'ai_media_seo_php_version_notice' );
	return;
}

/**
 * Check WordPress version compatibility.
 */
if ( version_compare( get_bloginfo( 'version' ), AI_MEDIA_SEO_MIN_WP_VERSION, '<' ) ) {
	add_action( 'admin_notices', 'ai_media_seo_wp_version_notice' );
	return;
}

/**
 * Display PHP version error notice.
 *
 * @since 1.0.0
 */
function ai_media_seo_php_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: Required PHP version */
				esc_html__( 'AI Media SEO requires PHP version %s or higher. Please upgrade PHP to activate this plugin.', 'ai-media-seo' ),
				esc_html( AI_MEDIA_SEO_MIN_PHP_VERSION )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Display WordPress version error notice.
 *
 * @since 1.0.0
 */
function ai_media_seo_wp_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: Required WordPress version */
				esc_html__( 'AI Media SEO requires WordPress version %s or higher. Please upgrade WordPress to activate this plugin.', 'ai-media-seo' ),
				esc_html( AI_MEDIA_SEO_MIN_WP_VERSION )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Composer autoloader.
 */
if ( file_exists( AI_MEDIA_SEO_PATH . 'vendor/autoload.php' ) ) {
	require_once AI_MEDIA_SEO_PATH . 'vendor/autoload.php';
} else {
	// Fallback to manual autoloader if Composer is not installed.
	spl_autoload_register( 'ai_media_seo_autoloader' );
}

/**
 * Load Action Scheduler.
 */
if ( file_exists( AI_MEDIA_SEO_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once AI_MEDIA_SEO_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

/**
 * PSR-4 Autoloader for plugin classes.
 *
 * @since 1.0.0
 * @param string $class The fully-qualified class name.
 */
function ai_media_seo_autoloader( $class ) {
	// Namespace prefix.
	$prefix = 'AIMediaSEO\\';

	// Base directory for the namespace prefix.
	$base_dir = AI_MEDIA_SEO_PATH . 'includes/';

	// Does the class use the namespace prefix?
	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		// No, move to the next registered autoloader.
		return;
	}

	// Get the relative class name.
	$relative_class = substr( $class, $len );

	// Replace the namespace prefix with the base directory, replace namespace
	// separators with directory separators in the relative class name, append
	// with .php.
	$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	// If the file exists, require it.
	if ( file_exists( $file ) ) {
		require $file;
	}
}

/**
 * The code that runs during plugin activation.
 *
 * @since 1.0.0
 */
function activate_ai_media_seo() {
	require_once AI_MEDIA_SEO_PATH . 'includes/Core/Activator.php';
	AIMediaSEO\Core\Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 *
 * @since 1.0.0
 */
function deactivate_ai_media_seo() {
	require_once AI_MEDIA_SEO_PATH . 'includes/Core/Deactivator.php';
	AIMediaSEO\Core\Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_ai_media_seo' );
register_deactivation_hook( __FILE__, 'deactivate_ai_media_seo' );

/**
 * Initialize the plugin.
 *
 * @since 1.0.0
 */
function run_ai_media_seo() {
	$plugin = new AIMediaSEO\Core\Plugin();
	$plugin->run();
}

// Start the plugin.
run_ai_media_seo();
