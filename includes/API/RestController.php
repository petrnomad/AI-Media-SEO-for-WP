<?php
/**
 * REST API Controller
 *
 * Main controller that registers all sub-controllers.
 *
 * @package    AIMediaSEO
 * @subpackage API
 * @since      1.0.0
 */

namespace AIMediaSEO\API;

use WP_REST_Controller;

/**
 * RestController class.
 *
 * Provides REST API endpoints for AI Media SEO operations.
 *
 * @since 1.0.0
 */
class RestController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-media/v1';

	/**
	 * Sub-controllers.
	 *
	 * @var array
	 */
	private $controllers = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Initialize sub-controllers.
		$this->controllers = array(
			'image_analysis' => new ImageAnalysisController(),
			'metadata'       => new MetadataController(),
			'jobs'           => new JobsController(),
			'settings'       => new SettingsController(),
			'costs'          => new CostController(),
			'media_library'  => new MediaLibraryController(),
		);
	}

	/**
	 * Register routes.
	 *
	 * Delegates route registration to sub-controllers.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		foreach ( $this->controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Get controller by name.
	 *
	 * @since 1.0.0
	 * @param string $name Controller name.
	 * @return WP_REST_Controller|null Controller instance or null.
	 */
	public function get_controller( string $name ): ?WP_REST_Controller {
		return $this->controllers[ $name ] ?? null;
	}
}
