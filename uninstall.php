<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package AIMediaSEO
 * @since   1.0.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the Deactivator class.
require_once plugin_dir_path( __FILE__ ) . 'includes/Core/Deactivator.php';

// Run the uninstall method.
AIMediaSEO\Core\Deactivator::uninstall();
