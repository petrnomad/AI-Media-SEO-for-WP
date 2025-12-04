<?php
/**
 * Image Analysis REST API Controller
 *
 * Handles image analysis operations.
 *
 * @package    AIMediaSEO
 * @subpackage API
 * @since      1.0.0
 */

namespace AIMediaSEO\API;

use AIMediaSEO\Analyzer\ImageAnalyzer;
use AIMediaSEO\Providers\ProviderFactory;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * ImageAnalysisController class.
 *
 * Provides REST API endpoints for image analysis operations.
 *
 * @since 1.0.0
 */
class ImageAnalysisController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-media/v1';

	/**
	 * Language detector.
	 *
	 * @var \AIMediaSEO\Multilingual\LanguageDetector
	 */
	private $language_detector;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->language_detector = new \AIMediaSEO\Multilingual\LanguageDetector();
	}

	/**
	 * Register routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		// Register post meta for status field.
		register_post_meta(
			'attachment',
			'_ai_media_status',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
				'default'      => 'pending',
			)
		);

		// Register draft metadata fields.
		register_post_meta(
			'attachment',
			'_ai_media_draft_alt',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
				'default'      => '',
			)
		);

		register_post_meta(
			'attachment',
			'_ai_media_draft_caption',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
				'default'      => '',
			)
		);

		register_post_meta(
			'attachment',
			'_ai_media_draft_title',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
				'default'      => '',
			)
		);

		// Analyze images.
		register_rest_route(
			$this->namespace,
			'/analyze',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'analyze_images' ),
					'permission_callback' => array( $this, 'check_analyze_permission' ),
					'args'                => $this->get_analyze_args(),
				),
			)
		);

		// Batch analyze.
		register_rest_route(
			$this->namespace,
			'/batch-analyze',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'batch_analyze' ),
					'permission_callback' => array( $this, 'check_analyze_permission' ),
					'args'                => $this->get_batch_analyze_args(),
				),
			)
		);

		// Process single image (for AJAX batch processing).
		register_rest_route(
			$this->namespace,
			'/process-single',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'process_single' ),
					'permission_callback' => array( $this, 'check_analyze_permission' ),
					'args'                => $this->get_process_single_args(),
				),
			)
		);

		// Regenerate metadata.
		register_rest_route(
			$this->namespace,
			'/regenerate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'regenerate_metadata' ),
					'permission_callback' => array( $this, 'check_analyze_permission' ),
					'args'                => $this->get_regenerate_args(),
				),
			)
		);

		// Update image status.
		register_rest_route(
			$this->namespace,
			'/status',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_status' ),
					'permission_callback' => array( $this, 'check_settings_permission' ),
					'args'                => array(
						'attachment_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'status' => array(
							'required'          => true,
							'type'              => 'string',
							'enum'              => array( 'pending', 'processing', 'processed', 'approved', 'failed' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Analyze images.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function analyze_images( WP_REST_Request $request ) {
		$attachment_ids = $request->get_param( 'attachment_ids' );
		$language       = $request->get_param( 'language' ) ?: $this->language_detector->get_current_language();

		if ( empty( $attachment_ids ) || ! is_array( $attachment_ids ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Attachment IDs are required.', 'ai-media-seo' ),
				array( 'status' => 400 )
			);
		}

		// Get primary provider.
		$factory = new ProviderFactory();
		$provider = $factory->get_primary();

		if ( ! $provider ) {
			return new WP_Error(
				'provider_not_configured',
				__( 'No AI provider is configured. Please configure an API key in Settings.', 'ai-media-seo' ),
				array( 'status' => 400 )
			);
		}


		// Initialize analyzer.
		$analyzer = new ImageAnalyzer();

		$results = array();
		$errors = array();

		foreach ( $attachment_ids as $attachment_id ) {
			// Set status to processing.
			update_post_meta( $attachment_id, '_ai_media_status', 'processing' );

			try {
				$result = $analyzer->process(
					(int) $attachment_id,
					$language,
					$provider,
					array( 'auto_apply' => true )
				);

				if ( $result['success'] ) {
					// Apply metadata based on auto-approve status.
					if ( isset( $result['metadata'] ) ) {
						if ( $result['can_auto_approve'] ) {
							// Auto-approved: apply directly to attachment.
							$this->apply_metadata_to_attachment( $attachment_id, $result['metadata'], $language );
							update_post_meta( $attachment_id, '_ai_media_status', 'approved' );
						} else {
							// Not auto-approved: save as draft for review.
							$this->apply_draft_metadata( $attachment_id, $result['metadata'], $language );
							update_post_meta( $attachment_id, '_ai_media_status', 'processed' );
						}
					}

					$result_data = array(
						'attachment_id' => $attachment_id,
						'job_id'        => $result['job_id'],
						'status'        => $result['can_auto_approve'] ? 'approved' : 'processed',
						'score'         => $result['final_score'],
						'provider'      => $result['provider'],
						'model'         => $result['model'],
						'metadata'      => $result['metadata'],
					);

					// Add cost data if available.
					if ( isset( $result['token_data'] ) ) {
						$result_data['costs'] = array(
							'input_tokens'    => $result['token_data']['input_tokens'],
							'output_tokens'   => $result['token_data']['output_tokens'],
							'input_cost'      => $result['token_data']['input_cost'],
							'output_cost'     => $result['token_data']['output_cost'],
							'total_cost'      => $result['token_data']['total_cost'],
							'estimated_input' => $result['token_data']['estimated_input'],
						);
					}

					$results[] = $result_data;
				} else {
					// Set status to failed.
					update_post_meta( $attachment_id, '_ai_media_status', 'failed' );
					$errors[] = array(
						'attachment_id' => $attachment_id,
						'errors'        => $result['errors'],
					);
				}
			} catch ( \Exception $e ) {
				// Set status to failed.
				update_post_meta( $attachment_id, '_ai_media_status', 'failed' );
				$errors[] = array(
					'attachment_id' => $attachment_id,
					'errors'        => array( $e->getMessage() ),
				);
			}
		}

		return new WP_REST_Response(
			array(
				'success' => ! empty( $results ),
				'results' => $results,
				'errors'  => $errors,
				'total'   => count( $attachment_ids ),
				'processed' => count( $results ),
				'failed'  => count( $errors ),
			),
			200
		);
	}

	/**
	 * Batch analyze images.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function batch_analyze( WP_REST_Request $request ) {
		$mode       = $request->get_param( 'mode' ) ?: 'selected';
		$ids        = $request->get_param( 'attachment_ids' ) ?: array();
		$language   = $request->get_param( 'language' ) ?: $this->language_detector->get_current_language();

		$attachment_ids = array();

		switch ( $mode ) {
			case 'selected':
				if ( empty( $ids ) || ! is_array( $ids ) ) {
					return new WP_Error(
						'invalid_params',
						__( 'Attachment IDs are required for selected mode.', 'ai-media-seo' ),
						array( 'status' => 400 )
					);
				}
				$attachment_ids = array_map( 'absint', $ids );
				break;

			case 'missing_metadata':
				// Get all images without ALT text or caption.
				$attachment_ids = $this->get_attachments_missing_metadata();
				break;

			case 'all':
				// Get all images.
				$attachment_ids = $this->get_all_image_attachments();
				break;

			default:
				return new WP_Error(
					'invalid_mode',
					__( 'Invalid batch mode. Use: selected, missing_metadata, or all.', 'ai-media-seo' ),
					array( 'status' => 400 )
				);
		}

		if ( empty( $attachment_ids ) ) {
			return new WP_REST_Response(
				array(
					'success'        => false,
					'message'        => __( 'No images found to process.', 'ai-media-seo' ),
					'attachment_ids' => array(),
					'total'          => 0,
				),
				200
			);
		}

		// Return attachment IDs for frontend AJAX processing.
		// No longer using Action Scheduler - processing is synchronous via /process-single endpoint.
		return new WP_REST_Response(
			array(
				'success'        => true,
				'attachment_ids' => $attachment_ids,
				'total'          => count( $attachment_ids ),
				'language'       => $language,
				'estimated_time' => count( $attachment_ids ) * 3, // 3 seconds per image estimate.
				'mode'           => $mode,
			),
			200
		);
	}

	/**
	 * Process single image synchronously (for AJAX batch processing).
	 *
	 * @since 1.5.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function process_single( WP_REST_Request $request ) {
		$attachment_id    = $request->get_param( 'attachment_id' );
		$language         = $request->get_param( 'language' ) ?: $this->language_detector->get_current_language();
		$auto_apply       = $request->get_param( 'auto_apply' );
		$force_reprocess  = $request->get_param( 'force_reprocess' );

		// Default auto_apply to true if not specified.
		if ( null === $auto_apply ) {
			$auto_apply = true;
		}

		// Default force_reprocess to false if not specified.
		if ( null === $force_reprocess ) {
			$force_reprocess = false;
		}

		// Validate attachment.
		if ( empty( $attachment_id ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Attachment ID is required.', 'ai-media-seo' ),
				array( 'status' => 400 )
			);
		}

		// Check if attachment exists and is an image.
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error(
				'invalid_attachment',
				__( 'Invalid attachment ID or not an image.', 'ai-media-seo' ),
				array( 'status' => 400 )
			);
		}

		// Check provider configuration.
		$factory = new ProviderFactory();
		$provider = $factory->get_primary();

		if ( ! $provider ) {
			return new WP_Error(
				'provider_not_configured',
				__( 'No AI provider is configured. Please configure an API key in Settings.', 'ai-media-seo' ),
				array( 'status' => 400 )
			);
		}


		$start_time = microtime( true );

		try {
			// Process using synchronizer.
			$synchronizer = new \AIMediaSEO\Queue\ProcessingSynchronizer();
			$result       = $synchronizer->process_single(
				$attachment_id,
				$language,
				array(
					'auto_apply'      => $auto_apply,
					'force_reprocess' => $force_reprocess,
				)
			);

			$processing_time = microtime( true ) - $start_time;

			if ( $result['success'] ) {
				$response_data = array(
					'success'         => true,
					'attachment_id'   => $attachment_id,
					'status'          => $result['status'] ?? 'processed',
					'metadata'        => $result['metadata'] ?? array(),
					'score'           => $result['final_score'] ?? 0.0,
					'processing_time' => round( $processing_time, 2 ),
				);

				// Add cost data if available.
				if ( isset( $result['token_data'] ) ) {
					$response_data['costs'] = array(
						'input_tokens'    => $result['token_data']['input_tokens'],
						'output_tokens'   => $result['token_data']['output_tokens'],
						'input_cost'      => $result['token_data']['input_cost'],
						'output_cost'     => $result['token_data']['output_cost'],
						'total_cost'      => $result['token_data']['total_cost'],
						'estimated_input' => $result['token_data']['estimated_input'],
					);
				}

				return new WP_REST_Response( $response_data, 200 );
			} else {
				return new WP_Error(
					'processing_failed',
					$result['errors'][0] ?? __( 'Failed to process image.', 'ai-media-seo' ),
					array(
						'status'          => 500,
						'attachment_id'   => $attachment_id,
						'errors'          => $result['errors'] ?? array(),
						'processing_time' => round( $processing_time, 2 ),
					)
				);
			}
		} catch ( \Exception $e ) {
			$processing_time = microtime( true ) - $start_time;

			return new WP_Error(
				'processing_error',
				$e->getMessage(),
				array(
					'status'          => 500,
					'attachment_id'   => $attachment_id,
					'processing_time' => round( $processing_time, 2 ),
				)
			);
		}
	}

	/**
	 * Regenerate metadata.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function regenerate_metadata( WP_REST_Request $request ) {
		$attachment_id    = $request->get_param( 'attachment_id' );
		$language         = $request->get_param( 'language' ) ?: $this->language_detector->get_current_language();
		$context_override = $request->get_param( 'context_override' ) ?: array();

		if ( empty( $attachment_id ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Attachment ID is required.', 'ai-media-seo' ),
				array( 'status' => 400 )
			);
		}

		// Get primary provider.
		$factory = new ProviderFactory();
		$provider = $factory->get_primary();

		if ( ! $provider ) {
			return new WP_Error(
				'provider_not_configured',
				__( 'No AI provider is configured. Please configure an API key in Settings.', 'ai-media-seo' ),
				array( 'status' => 400 )
			);
		}


		$analyzer = new ImageAnalyzer();

		try {
			$result = $analyzer->process(
				(int) $attachment_id,
				$language,
				$provider,
				array(
					'context_override' => $context_override,
					'auto_apply'       => false,
				)
			);

			if ( $result['success'] ) {
				return new WP_REST_Response(
					array(
						'success'  => true,
						'job_id'   => $result['job_id'],
						'metadata' => $result['metadata'],
						'score'    => $result['final_score'],
					),
					200
				);
			} else {
				return new WP_Error(
					'regeneration_failed',
					$result['errors'][0] ?? __( 'Failed to regenerate metadata.', 'ai-media-seo' ),
					array( 'status' => 500 )
				);
			}
		} catch ( \Exception $e ) {
			return new WP_Error(
				'regeneration_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Update image status.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function update_status( WP_REST_Request $request ) {
		$attachment_id = $request->get_param( 'attachment_id' );
		$status        = $request->get_param( 'status' );
		$alt_text      = $request->get_param( 'alt_text' ); // Optional: custom ALT text for approval.

		// Validate attachment exists and is an image.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'invalid_attachment',
				__( 'Invalid attachment ID.', 'ai-media-seo' ),
				array( 'status' => 404 )
			);
		}

		// Handle status-specific metadata changes.
		if ( 'approved' === $status ) {
			// If custom alt_text is provided, use it and clear draft.
			if ( ! empty( $alt_text ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
				delete_post_meta( $attachment_id, '_ai_media_draft_alt' );
				delete_post_meta( $attachment_id, '_ai_media_draft_caption' );
				delete_post_meta( $attachment_id, '_ai_media_draft_title' );
			} else {
				// Move draft metadata to real fields.
				$this->approve_draft_metadata( $attachment_id );
			}
		} elseif ( 'processed' === $status ) {
			// Move real metadata to draft fields (for manual review).
			$this->move_to_draft_metadata( $attachment_id );
		} elseif ( 'pending' === $status ) {
			// Clear all metadata when resetting to pending.
			$this->clear_all_metadata( $attachment_id );
		}

		// Update status.
		update_post_meta( $attachment_id, '_ai_media_status', $status );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Status updated successfully.', 'ai-media-seo' ),
				'status'  => $status,
			),
			200
		);
	}

	/**
	 * Check analyze permission.
	 *
	 * @since 1.0.0
	 * @return bool True if user has permission.
	 */
	public function check_analyze_permission(): bool {
		return current_user_can( 'ai_media_process_images' );
	}

	/**
	 * Check settings permission.
	 *
	 * @since 1.0.0
	 * @return bool True if user has permission.
	 */
	public function check_settings_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get analyze endpoint arguments.
	 *
	 * @since 1.0.0
	 * @return array Arguments schema.
	 */
	private function get_analyze_args(): array {
		return array(
			'attachment_ids' => array(
				'required'          => true,
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'sanitize_callback' => function( $value ) {
					return array_map( 'intval', (array) $value );
				},
			),
			'language' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => $this->language_detector->get_current_language(),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function( $value ) {
					return $this->language_detector->is_valid_language( $value );
				},
			),
		);
	}

	/**
	 * Get batch analyze endpoint arguments.
	 *
	 * @since 1.0.0
	 * @return array Arguments schema.
	 */
	private function get_batch_analyze_args(): array {
		return array(
			'mode' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'selected',
				'enum'              => array( 'selected', 'missing_metadata', 'all' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'attachment_ids' => array(
				'required' => false,
				'type'     => 'array',
				'default'  => array(),
				'items'    => array(
					'type' => 'integer',
				),
			),
			'language' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => $this->language_detector->get_current_language(),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Get process-single endpoint arguments.
	 *
	 * @since 1.5.0
	 * @return array Arguments schema.
	 */
	private function get_process_single_args(): array {
		return array(
			'attachment_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'language' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => $this->language_detector->get_current_language(),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function( $value ) {
					return $this->language_detector->is_valid_language( $value );
				},
			),
			'auto_apply' => array(
				'required'          => false,
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'force_reprocess' => array(
				'required'          => false,
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Get regenerate endpoint arguments.
	 *
	 * @since 1.0.0
	 * @return array Arguments schema.
	 */
	private function get_regenerate_args(): array {
		return array(
			'attachment_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'language' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => $this->language_detector->get_current_language(),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function( $value ) {
					return $this->language_detector->is_valid_language( $value );
				},
			),
			'context_override' => array(
				'required' => false,
				'type'     => 'object',
				'default'  => array(),
			),
		);
	}

	/**
	 * Apply metadata to attachment.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $metadata      Metadata to apply.
	 * @param string $language      Language code.
	 */
	private function apply_metadata_to_attachment( int $attachment_id, array $metadata, string $language ) {
		// Update ALT text.
		if ( ! empty( $metadata['alt'] ) ) {
			if ( $this->language_detector->is_multilingual_active() ) {
				// Multilingual: store with language suffix.
				update_post_meta( $attachment_id, '_wp_attachment_image_alt_' . $language, $metadata['alt'] );
			} else {
				// Standard: update ALT text.
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $metadata['alt'] );
			}
		}

		// Update caption (post_excerpt).
		if ( ! empty( $metadata['caption'] ) ) {
			$update_data = array(
				'ID'           => $attachment_id,
				'post_excerpt' => $metadata['caption'],
			);
			wp_update_post( $update_data );
		}

		// Update title (post_title).
		if ( ! empty( $metadata['title'] ) ) {
			$update_data = array(
				'ID'         => $attachment_id,
				'post_title' => $metadata['title'],
			);
			wp_update_post( $update_data );
		}

		// Update keywords as taxonomy or meta.
		if ( ! empty( $metadata['keywords'] ) && is_array( $metadata['keywords'] ) ) {
			// Store as comma-separated meta for now.
			update_post_meta( $attachment_id, '_ai_media_keywords_' . $language, implode( ', ', $metadata['keywords'] ) );
		}

		// Store AI score.
		if ( isset( $metadata['score'] ) ) {
			update_post_meta( $attachment_id, '_ai_media_score_' . $language, $metadata['score'] );
		}
	}

	/**
	 * Apply draft metadata to attachment.
	 *
	 * Stores metadata in draft meta fields for review before approval.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $metadata Metadata array.
	 * @param string $language Language code.
	 */
	private function apply_draft_metadata( int $attachment_id, array $metadata, string $language ) {
		// Store draft ALT text.
		if ( ! empty( $metadata['alt'] ) ) {
			update_post_meta( $attachment_id, '_ai_media_draft_alt', $metadata['alt'] );
		}

		// Store draft caption.
		if ( ! empty( $metadata['caption'] ) ) {
			update_post_meta( $attachment_id, '_ai_media_draft_caption', $metadata['caption'] );
		}

		// Store draft title.
		if ( ! empty( $metadata['title'] ) ) {
			update_post_meta( $attachment_id, '_ai_media_draft_title', $metadata['title'] );
		}

		// Store keywords and score (same as normal, not drafted).
		if ( ! empty( $metadata['keywords'] ) && is_array( $metadata['keywords'] ) ) {
			update_post_meta( $attachment_id, '_ai_media_keywords_' . $language, implode( ', ', $metadata['keywords'] ) );
		}

		if ( isset( $metadata['score'] ) ) {
			update_post_meta( $attachment_id, '_ai_media_score_' . $language, $metadata['score'] );
		}
	}

	/**
	 * Approve draft metadata and move to real fields.
	 *
	 * Copies draft metadata to actual attachment fields and clears draft.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID.
	 */
	private function approve_draft_metadata( int $attachment_id ) {
		// Get draft metadata.
		$draft_alt     = get_post_meta( $attachment_id, '_ai_media_draft_alt', true );
		$draft_caption = get_post_meta( $attachment_id, '_ai_media_draft_caption', true );
		$draft_title   = get_post_meta( $attachment_id, '_ai_media_draft_title', true );

		// Apply draft ALT text to real field.
		if ( ! empty( $draft_alt ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $draft_alt );
		}

		// Apply draft caption to real field.
		if ( ! empty( $draft_caption ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => $draft_caption,
				)
			);
		}

		// Apply draft title to real field.
		if ( ! empty( $draft_title ) ) {
			wp_update_post(
				array(
					'ID'         => $attachment_id,
					'post_title' => $draft_title,
				)
			);
		}

		// Clear draft fields.
		delete_post_meta( $attachment_id, '_ai_media_draft_alt' );
		delete_post_meta( $attachment_id, '_ai_media_draft_caption' );
		delete_post_meta( $attachment_id, '_ai_media_draft_title' );
	}

	/**
	 * Move real metadata to draft fields.
	 *
	 * Moves current attachment metadata to draft fields for review.
	 * Clears the real fields after moving.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID.
	 */
	private function move_to_draft_metadata( int $attachment_id ) {
		// Get current real metadata.
		$current_alt     = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$attachment_post = get_post( $attachment_id );
		$current_caption = $attachment_post->post_excerpt ?? '';
		$current_title   = $attachment_post->post_title ?? '';

		// Move to draft fields if they exist.
		if ( ! empty( $current_alt ) ) {
			update_post_meta( $attachment_id, '_ai_media_draft_alt', $current_alt );
			delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
		}

		if ( ! empty( $current_caption ) ) {
			update_post_meta( $attachment_id, '_ai_media_draft_caption', $current_caption );
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => '',
				)
			);
		}

		if ( ! empty( $current_title ) && 'Untitled' !== $current_title ) {
			update_post_meta( $attachment_id, '_ai_media_draft_title', $current_title );
		}
	}

	/**
	 * Clear all metadata (both draft and real).
	 *
	 * Clears all metadata when resetting to pending status.
	 * Sets title to filename without extension.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID.
	 */
	private function clear_all_metadata( int $attachment_id ) {
		// Clear real metadata.
		delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );

		// Clear draft metadata.
		delete_post_meta( $attachment_id, '_ai_media_draft_alt' );
		delete_post_meta( $attachment_id, '_ai_media_draft_caption' );
		delete_post_meta( $attachment_id, '_ai_media_draft_title' );

		// Get filename without extension for title.
		$filename = basename( get_attached_file( $attachment_id ) );
		$title = preg_replace( '/\.[^.]+$/', '', $filename ); // Remove extension.

		// Clear caption and set title to filename without extension.
		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_excerpt' => '',
				'post_title'   => $title,
			)
		);
	}

	/**
	 * Get attachments missing metadata.
	 *
	 * @since 1.0.0
	 * @return array Array of attachment IDs.
	 */
	private function get_attachments_missing_metadata(): array {
		global $wpdb;

		$query = "
			SELECT p.ID
			FROM {$wpdb->posts} p
			WHERE p.post_type = 'attachment'
			AND p.post_mime_type LIKE 'image/%'
			AND (
				NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID
					AND pm.meta_key = '_wp_attachment_image_alt'
					AND pm.meta_value != ''
				)
				OR NOT EXISTS (
					SELECT 1 FROM {$wpdb->posts} pc
					WHERE pc.ID = p.ID
					AND pc.post_excerpt != ''
				)
			)
			ORDER BY p.post_date DESC
		";

		return array_map( 'intval', $wpdb->get_col( $query ) );
	}

	/**
	 * Get all image attachments.
	 *
	 * @since 1.0.0
	 * @return array Array of attachment IDs.
	 */
	private function get_all_image_attachments(): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		return get_posts( $args );
	}
}
