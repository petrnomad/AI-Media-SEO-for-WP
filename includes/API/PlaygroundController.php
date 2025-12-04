<?php
/**
 * Playground REST API Controller
 *
 * Provides debugging endpoints for AI context and prompt preview.
 *
 * @package    AIMediaSEO
 * @subpackage API
 * @since      1.0.0
 */

namespace AIMediaSEO\API;

use AIMediaSEO\Analyzer\ContextBuilder;
use AIMediaSEO\Multilingual\LanguageDetector;
use AIMediaSEO\Prompts\MinimalPromptBuilder;
use AIMediaSEO\Prompts\StandardPromptBuilder;
use AIMediaSEO\Prompts\AdvancedPromptBuilder;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * PlaygroundController class.
 *
 * Provides REST API endpoints for debugging and testing AI analysis pipeline.
 *
 * @since 1.0.0
 */
class PlaygroundController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-media/v1';

	/**
	 * Language detector.
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
		$this->language_detector = new LanguageDetector();
	}

	/**
	 * Register routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		// GET /playground/images - Get recent images for testing.
		register_rest_route(
			$this->namespace,
			'/playground/images',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_recent_images' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'limit'  => array(
							'required'    => false,
							'type'        => 'integer',
							'description' => __( 'Number of images to return (default 12, max 50).', 'ai-media-seo' ),
							'default'     => 12,
						),
						'offset' => array(
							'required'    => false,
							'type'        => 'integer',
							'description' => __( 'Offset for pagination (default 0).', 'ai-media-seo' ),
							'default'     => 0,
						),
					),
				),
			)
		);

		// GET /playground/search - Search images by filename or ID.
		register_rest_route(
			$this->namespace,
			'/playground/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search_images' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_search_args(),
				),
			)
		);

		// POST /playground/context - Build and preview context without analyzing.
		register_rest_route(
			$this->namespace,
			'/playground/context',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_image_context' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_context_args(),
				),
			)
		);
	}

	/**
	 * Get recent images for playground testing.
	 *
	 * Returns recently uploaded images with metadata for quick picks.
	 * Supports pagination via limit and offset parameters.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_recent_images( WP_REST_Request $request ): WP_REST_Response {
		$limit  = min( (int) $request->get_param( 'limit' ), 50 ); // Max 50 results.
		$offset = (int) $request->get_param( 'offset' );

		if ( $limit <= 0 ) {
			$limit = 12; // Default 12 images.
		}

		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$attachments = get_posts( $args );
		$images      = array();

		foreach ( $attachments as $attachment ) {
			$language = $this->language_detector->get_attachment_language( $attachment->ID );
			$file     = get_attached_file( $attachment->ID );

			$images[] = array(
				'id'       => $attachment->ID,
				'title'    => get_the_title( $attachment->ID ) ?: basename( $file ),
				'url'      => wp_get_attachment_image_url( $attachment->ID, 'thumbnail' ),
				'date'     => $attachment->post_date,
				'language' => $language,
				'filename' => basename( $file ),
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'images'  => $images,
			),
			200
		);
	}

	/**
	 * Get image context without analyzing.
	 *
	 * Builds complete context data and prompt preview for debugging.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_image_context( WP_REST_Request $request ) {
		$attachment_id    = $request->get_param( 'attachment_id' );
		$language         = $request->get_param( 'language' );
		$variant_override = $request->get_param( 'prompt_variant' );

		// Validate attachment exists.
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error(
				'invalid_attachment',
				__( 'Invalid attachment ID or attachment is not an image.', 'ai-media-seo' ),
				array( 'status' => 400 )
			);
		}

		// Auto-detect language if not provided.
		if ( empty( $language ) ) {
			$language = $this->language_detector->get_attachment_language( $attachment_id );
		}

		// Build context using ContextBuilder.
		$context_builder = new ContextBuilder();
		$context         = $context_builder->build( $attachment_id, $language );
		$context_score   = $context_builder->calculate_context_score( $context );

		// Get global settings.
		$settings       = get_option( 'ai_media_seo_settings', array() );
		$ai_role        = $settings['ai_role'] ?? 'SEO expert';
		$site_context   = $settings['site_context'] ?? '';
		$alt_max_length = $settings['alt_max_length'] ?? 125;
		$prompt_variant = $settings['prompt_variant'] ?? 'standard';

		// Override variant if specified (for testing purposes).
		if ( $variant_override && in_array( $variant_override, array( 'minimal', 'standard', 'advanced' ), true ) ) {
			$prompt_variant              = $variant_override;
			$settings['prompt_variant'] = $variant_override;
		}

		$global_settings = array(
			'ai_role'        => $ai_role,
			'site_context'   => $site_context,
			'alt_max_length' => $alt_max_length,
			'prompt_variant' => $prompt_variant,
		);

		// Build final prompt using PromptBuilder abstraction.
		$prompt = $this->build_prompt( $language, $context, $settings );

		return new WP_REST_Response(
			array(
				'success'         => true,
				'attachment_id'   => $attachment_id,
				'language'        => $language,
				'global_settings' => $global_settings,
				'context'         => $context,
				'context_score'   => $context_score,
				'final_prompt'    => $prompt,
				'used_variant'    => $prompt_variant,
			),
			200
		);
	}

	/**
	 * Build prompt using centralized PromptBuilder (same as providers).
	 *
	 * Uses the PromptBuilder abstraction to ensure consistent prompt generation
	 * across playground and actual analysis.
	 *
	 * @since 1.0.0
	 * @param string $language Language code.
	 * @param array  $context  Context data.
	 * @param array  $settings Settings array.
	 * @return string Complete prompt.
	 */
	private function build_prompt( string $language, array $context, array $settings ): string {
		// Get selected prompt variant from settings (default: standard).
		$variant = $settings['prompt_variant'] ?? 'standard';

		// Select appropriate builder based on variant.
		switch ( $variant ) {
			case 'minimal':
				$builder = new MinimalPromptBuilder();
				break;
			case 'advanced':
				$builder = new AdvancedPromptBuilder();
				break;
			case 'standard':
			default:
				$builder = new StandardPromptBuilder();
				break;
		}

		// Build prompt using centralized logic.
		$prompt = $builder->build( $language, $context, $settings );

		return $prompt;
	}

	/**
	 * Search images by filename, title, or ID.
	 *
	 * Optimized search endpoint for finding specific images without loading all.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function search_images( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$query = sanitize_text_field( $request->get_param( 'q' ) );
		$limit = min( (int) $request->get_param( 'limit' ), 20 ); // Max 20 results.

		if ( empty( $query ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'images'  => array(),
				),
				200
			);
		}

		// Optimized query - search in title, name (slug), or exact ID match.
		$like = '%' . $wpdb->esc_like( $query ) . '%';

		$sql = $wpdb->prepare(
			"SELECT ID, post_title, post_name, guid, post_date
			FROM {$wpdb->posts}
			WHERE post_type = 'attachment'
			AND post_mime_type LIKE 'image/%%'
			AND post_status = 'inherit'
			AND (
				post_title LIKE %s
				OR post_name LIKE %s
				OR ID = %d
			)
			ORDER BY post_date DESC
			LIMIT %d",
			$like,
			$like,
			intval( $query ),
			$limit
		);

		$results = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$images  = array();

		foreach ( $results as $img ) {
			$language = $this->language_detector->get_attachment_language( $img->ID );
			$file     = get_attached_file( $img->ID );

			$images[] = array(
				'id'       => $img->ID,
				'title'    => ! empty( $img->post_title ) ? $img->post_title : basename( $img->guid ),
				'url'      => wp_get_attachment_image_url( $img->ID, 'thumbnail' ),
				'date'     => $img->post_date,
				'language' => $language,
				'filename' => basename( $file ),
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'images'  => $images,
				'count'   => count( $images ),
			),
			200
		);
	}

	/**
	 * Get context endpoint arguments.
	 *
	 * @since 1.0.0
	 * @return array Arguments definition.
	 */
	private function get_context_args(): array {
		return array(
			'attachment_id'  => array(
				'required'          => true,
				'type'              => 'integer',
				'description'       => __( 'Attachment ID to preview context for.', 'ai-media-seo' ),
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'language'       => array(
				'required'    => false,
				'type'        => 'string',
				'description' => __( 'Language code (auto-detected if not provided).', 'ai-media-seo' ),
			),
			'prompt_variant' => array(
				'required'    => false,
				'type'        => 'string',
				'description' => __( 'Override prompt variant (minimal, standard, advanced).', 'ai-media-seo' ),
				'enum'        => array( 'minimal', 'standard', 'advanced' ),
			),
		);
	}

	/**
	 * Get search endpoint arguments.
	 *
	 * @since 1.0.0
	 * @return array Arguments definition.
	 */
	private function get_search_args(): array {
		return array(
			'q'     => array(
				'required'          => true,
				'type'              => 'string',
				'description'       => __( 'Search query (filename, title, or ID).', 'ai-media-seo' ),
				'validate_callback' => function ( $param ) {
					return ! empty( $param ) && strlen( $param ) >= 1;
				},
			),
			'limit' => array(
				'required'    => false,
				'type'        => 'integer',
				'description' => __( 'Maximum number of results (default 10, max 20).', 'ai-media-seo' ),
				'default'     => 10,
			),
		);
	}

	/**
	 * Check permission for playground endpoints.
	 *
	 * @since 1.0.0
	 * @return bool Whether user has permission.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}
}
