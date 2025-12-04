<?php
/**
 * Metadata REST API Controller
 *
 * Handles metadata approval operations.
 *
 * @package    AIMediaSEO
 * @subpackage API
 * @since      1.0.0
 */

namespace AIMediaSEO\API;

use AIMediaSEO\Storage\MetadataStore;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * MetadataController class.
 *
 * Provides REST API endpoints for metadata management.
 *
 * @since 1.0.0
 */
class MetadataController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-media/v1';

	/**
	 * Metadata store.
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
	 * Register routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		// Approve metadata.
		register_rest_route(
			$this->namespace,
			'/approve',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'approve_metadata' ),
					'permission_callback' => array( $this, 'check_approve_permission' ),
					'args'                => $this->get_approve_args(),
				),
			)
		);
	}

	/**
	 * Approve metadata.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function approve_metadata( WP_REST_Request $request ) {
		$job_id = $request->get_param( 'job_id' );
		$fields = $request->get_param( 'fields' ) ?: array();

		if ( empty( $job_id ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Job ID is required.', 'ai-media-seo' ),
				array( 'status' => 400 )
			);
		}

		$success = $this->metadata_store->approve_job( (int) $job_id, $fields );

		if ( $success ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Metadata approved and applied.', 'ai-media-seo' ),
				),
				200
			);
		} else {
			return new WP_Error(
				'approval_failed',
				__( 'Failed to approve metadata.', 'ai-media-seo' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Check approve permission.
	 *
	 * @since 1.0.0
	 * @return bool True if user has permission.
	 */
	public function check_approve_permission(): bool {
		return current_user_can( 'ai_media_approve_metadata' );
	}

	/**
	 * Get approve endpoint arguments.
	 *
	 * @since 1.0.0
	 * @return array Arguments schema.
	 */
	private function get_approve_args(): array {
		return array(
			'job_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'fields' => array(
				'required'          => false,
				'type'              => 'array',
				'items'             => array( 'type' => 'string' ),
				'default'           => array(),
				'sanitize_callback' => function( $value ) {
					return array_map( 'sanitize_text_field', (array) $value );
				},
			),
		);
	}
}
