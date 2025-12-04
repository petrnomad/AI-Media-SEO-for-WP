<?php
/**
 * Image Analyzer
 *
 * Main class for analyzing images and generating metadata.
 *
 * @package    AIMediaSEO
 * @subpackage Analyzer
 * @since      1.0.0
 */

namespace AIMediaSEO\Analyzer;

use AIMediaSEO\Providers\ProviderInterface;
use Exception;

/**
 * ImageAnalyzer class.
 *
 * Orchestrates the image analysis pipeline.
 *
 * @since 1.0.0
 */
class ImageAnalyzer {

	/**
	 * Context builder.
	 *
	 * @var ContextBuilder
	 */
	private $context_builder;

	/**
	 * Quality validator.
	 *
	 * @var QualityValidator
	 */
	private $quality_validator;

	/**
	 * Processing steps.
	 *
	 * @var array
	 */
	private $steps = array(
		'validate_image',
		'build_context',
		'call_provider',
		'parse_response',
		'validate_quality',
		'calculate_score',
		'store_proposal',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->context_builder   = new ContextBuilder();
		$this->quality_validator = new QualityValidator();
	}

	/**
	 * Process an image.
	 *
	 * @since 1.0.0
	 * @param int               $attachment_id The attachment ID.
	 * @param string            $language      The language code.
	 * @param ProviderInterface $provider      The AI provider.
	 * @param array             $options       Additional options.
	 * @return array {
	 *     Processing result.
	 *
	 *     @type bool   $success   Whether processing succeeded.
	 *     @type array  $metadata  Generated metadata.
	 *     @type array  $context   Analysis context.
	 *     @type array  $timings   Performance timings.
	 *     @type string $error     Error message if failed.
	 * }
	 */
	public function process( int $attachment_id, string $language, ProviderInterface $provider, array $options = array() ): array {
		$result = array(
			'success'       => false,
			'attachment_id' => $attachment_id,
			'language'      => $language,
			'provider'      => $provider->get_name(),
			'model'         => $provider->get_model(),
			'errors'        => array(),
			'timings'       => array(),
			'metadata'      => array(),
			'context'       => array(),
		);

		$start_time = microtime( true );

		/**
		 * Fires before image analysis starts.
		 *
		 * @since 1.0.0
		 * @param int    $attachment_id Attachment ID.
		 * @param string $language      Language code.
		 * @param array  $options       Processing options.
		 */
		do_action( 'ai_media_before_analyze', $attachment_id, $language, $options );

		try {
			// Execute processing steps.
			foreach ( $this->steps as $step ) {
				$step_start = microtime( true );

				$method = $step;
				if ( method_exists( $this, $method ) ) {
					$result = $this->$method( $result, $attachment_id, $language, $provider, $options );
				}

				$result['timings'][ $step ] = microtime( true ) - $step_start;

				// Stop if error occurred.
				if ( ! empty( $result['errors'] ) ) {
					break;
				}
			}

			// Set success if no errors.
			$result['success'] = empty( $result['errors'] );

		} catch ( Exception $e ) {
			$result['errors'][] = $e->getMessage();
			$result['success']  = false;
		}

		$result['total_time'] = microtime( true ) - $start_time;

		/**
		 * Fires after image analysis completes.
		 *
		 * @since 1.0.0
		 * @param array $result Processing result.
		 */
		do_action( 'ai_media_after_analyze', $result );

		return $result;
	}

	/**
	 * Validate image.
	 *
	 * @since 1.0.0
	 * @param array  $result        Current result.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param object $provider      Provider instance.
	 * @param array  $options       Options.
	 * @return array Updated result.
	 */
	private function validate_image( array $result, int $attachment_id, string $language, $provider, array $options ): array {
		// Check if attachment exists.
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			$result['errors'][] = __( 'Attachment is not an image.', 'ai-media-seo' );
			return $result;
		}

		// Check file exists.
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			$result['errors'][] = __( 'Image file not found.', 'ai-media-seo' );
			return $result;
		}

		// Check supported formats.
		$mime_type = get_post_mime_type( $attachment_id );
		$supported_mimes = array(
			'image/jpeg',
			'image/jpg',
			'image/png',
			'image/gif',
			'image/webp',
		);

		if ( ! in_array( $mime_type, $supported_mimes, true ) ) {
			$result['errors'][] = sprintf(
				/* translators: %s: MIME type */
				__( 'Unsupported image format: %s', 'ai-media-seo' ),
				$mime_type
			);
			return $result;
		}

		$result['mime_type'] = $mime_type;

		return $result;
	}

	/**
	 * Build context.
	 *
	 * @since 1.0.0
	 * @param array  $result        Current result.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param object $provider      Provider instance.
	 * @param array  $options       Options.
	 * @return array Updated result.
	 */
	private function build_context( array $result, int $attachment_id, string $language, $provider, array $options ): array {
		$context = $this->context_builder->build( $attachment_id, $language );

		// Allow manual context override.
		if ( ! empty( $options['context_override'] ) ) {
			$context = array_merge( $context, $options['context_override'] );
		}

		$result['context'] = $context;
		$result['context_score'] = $this->context_builder->calculate_context_score( $context );

		return $result;
	}

	/**
	 * Call provider API.
	 *
	 * @since 1.0.0
	 * @param array  $result        Current result.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param object $provider      Provider instance.
	 * @param array  $options       Options.
	 * @return array Updated result.
	 */
	private function call_provider( array $result, int $attachment_id, string $language, $provider, array $options ): array {
		try {
			$metadata = $provider->analyze( $attachment_id, $language, $result['context'] );
			$result['metadata'] = $metadata;

			// Extract token_data from metadata to result root level.
			if ( isset( $metadata['token_data'] ) ) {
				$result['token_data'] = $metadata['token_data'];
			}
		} catch ( Exception $e ) {
			$result['errors'][] = sprintf(
				/* translators: %s: Error message */
				__( 'Provider error: %s', 'ai-media-seo' ),
				$e->getMessage()
			);
		}

		return $result;
	}

	/**
	 * Parse response.
	 *
	 * @since 1.0.0
	 * @param array  $result        Current result.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param object $provider      Provider instance.
	 * @param array  $options       Options.
	 * @return array Updated result.
	 */
	private function parse_response( array $result, int $attachment_id, string $language, $provider, array $options ): array {
		// Response parsing is done by provider.
		// Verify we have required fields with non-empty values.
		$required = array( 'alt', 'score' );

		foreach ( $required as $field ) {
			if ( ! isset( $result['metadata'][ $field ] ) ) {
				$result['errors'][] = sprintf(
					/* translators: %s: Field name */
					__( 'Missing required field: %s', 'ai-media-seo' ),
					$field
				);
			} elseif ( $field === 'alt' && empty( trim( $result['metadata'][ $field ] ) ) ) {
				// ALT text must not be empty or just whitespace.
				$result['errors'][] = __( 'ALT text is empty or contains only whitespace', 'ai-media-seo' );
			} elseif ( $field === 'score' && ! is_numeric( $result['metadata'][ $field ] ) ) {
				// Score must be numeric.
				$result['errors'][] = __( 'Score must be a numeric value', 'ai-media-seo' );
			}
		}

		return $result;
	}

	/**
	 * Validate quality.
	 *
	 * @since 1.0.0
	 * @param array  $result        Current result.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param object $provider      Provider instance.
	 * @param array  $options       Options.
	 * @return array Updated result.
	 */
	private function validate_quality( array $result, int $attachment_id, string $language, $provider, array $options ): array {
		if ( empty( $result['metadata'] ) ) {
			return $result;
		}

		$validation = $this->quality_validator->validate( $result['metadata'] );

		$result['validation'] = $validation;

		if ( ! $validation['valid'] ) {
			$result['quality_warnings'] = $validation['errors'];
		}

		return $result;
	}

	/**
	 * Calculate final score.
	 *
	 * @since 1.0.0
	 * @param array  $result        Current result.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param object $provider      Provider instance.
	 * @param array  $options       Options.
	 * @return array Updated result.
	 */
	private function calculate_score( array $result, int $attachment_id, string $language, $provider, array $options ): array {
		// Combine AI score, validation score, and context score.
		$ai_score = $result['metadata']['score'] ?? 0.0;
		$validation_score = $result['validation']['score'] ?? 1.0;
		$context_score = $result['context_score'] ?? 0.5;

		// Weighted average.
		$final_score = ( $ai_score * 0.5 ) + ( $validation_score * 0.3 ) + ( $context_score * 0.2 );

		$result['final_score'] = round( $final_score, 2 );
		$result['can_auto_approve'] = $this->quality_validator->can_auto_approve( $final_score );

		return $result;
	}

	/**
	 * Store proposal.
	 *
	 * @since 1.0.0
	 * @param array  $result        Current result.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param object $provider      Provider instance.
	 * @param array  $options       Options.
	 * @return array Updated result.
	 */
	private function store_proposal( array $result, int $attachment_id, string $language, $provider, array $options ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		// Store in jobs table.
		$job_data = array(
			'attachment_id'  => $attachment_id,
			'language_code'  => $language,
			'status'         => $result['can_auto_approve'] ? 'approved' : 'processed',
			'provider'       => $provider->get_name(),
			'model'          => $provider->get_model(),
			'prompt_version' => '1.0',
			'request_data'   => wp_json_encode( $result['context'] ),
			'response_data'  => wp_json_encode( $result['metadata'] ),
			'tokens_used'    => isset( $result['metadata']['tokens_used'] ) ? (int) $result['metadata']['tokens_used'] : null,
			'cost_cents'     => isset( $result['metadata']['cost_cents'] ) ? (float) $result['metadata']['cost_cents'] : null,
			'score'          => $result['final_score'],
			'created_at'     => current_time( 'mysql' ),
			'processed_at'   => current_time( 'mysql' ),
		);

		// Add token/cost tracking data if available.
		if ( isset( $result['token_data'] ) ) {
			$job_data['input_tokens']            = isset( $result['token_data']['input_tokens'] ) ? (int) $result['token_data']['input_tokens'] : null;
			$job_data['output_tokens']           = isset( $result['token_data']['output_tokens'] ) ? (int) $result['token_data']['output_tokens'] : null;
			$job_data['estimated_input_tokens']  = ! empty( $result['token_data']['estimated_input'] ) ? 1 : 0;
			$job_data['input_cost']              = isset( $result['token_data']['input_cost'] ) ? (float) $result['token_data']['input_cost'] : null;
			$job_data['output_cost']             = isset( $result['token_data']['output_cost'] ) ? (float) $result['token_data']['output_cost'] : null;
			$job_data['total_cost']              = isset( $result['token_data']['total_cost'] ) ? (float) $result['token_data']['total_cost'] : null;
		}

		if ( $result['can_auto_approve'] ) {
			$job_data['approved_at'] = current_time( 'mysql' );
			$job_data['approved_by'] = get_current_user_id();
		}

		$inserted = $wpdb->insert( $table_name, $job_data );

		if ( $inserted ) {
			$result['job_id'] = $wpdb->insert_id;

			// Apply metadata based on context and score:
			// - Auto-processing (upload): Apply to draft if score < 85%, apply directly if score >= 85%
			// - Manual processing: Only apply directly if score >= 85%
			$should_apply_to_draft = false;
			$should_apply_directly = false;

			if ( ! empty( $options['auto_apply'] ) ) {
				if ( ! empty( $options['auto_process'] ) ) {
					// Auto-processing from upload
					if ( $result['can_auto_approve'] ) {
						// High score: apply directly
						$should_apply_directly = true;
					} else {
						// Low score: apply to draft for review
						$should_apply_to_draft = true;
					}
				} else {
					// Manual processing: only apply if score is high enough
					if ( $result['can_auto_approve'] ) {
						$should_apply_directly = true;
					} else {
					}
				}
			}

			if ( $should_apply_directly ) {
				$this->apply_metadata( $attachment_id, $language, $result['metadata'] );
				$result['metadata_applied'] = true;
			} elseif ( $should_apply_to_draft ) {
				$this->apply_draft_metadata( $attachment_id, $language, $result['metadata'] );
				$result['metadata_applied_to_draft'] = true;
			}
		} else {
			$result['errors'][] = __( 'Failed to store job in database.', 'ai-media-seo' );
		}

		return $result;
	}

	/**
	 * Apply metadata to attachment.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param array  $metadata      Metadata to apply.
	 */
	public function apply_metadata( int $attachment_id, string $language, array $metadata ): void {
		/**
		 * Fires before applying metadata.
		 *
		 * @since 1.0.0
		 * @param int    $attachment_id Attachment ID.
		 * @param string $language      Language code.
		 * @param array  $metadata      Metadata being applied.
		 */
		do_action( 'ai_media_before_apply_metadata', $attachment_id, $language, $metadata );

		// Apply ALT text.
		if ( ! empty( $metadata['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $metadata['alt'] ) );
			update_post_meta( $attachment_id, "ai_alt_{$language}", sanitize_text_field( $metadata['alt'] ) );
		}

		// Apply caption.
		if ( ! empty( $metadata['caption'] ) ) {
			wp_update_post( array(
				'ID'           => $attachment_id,
				'post_excerpt' => wp_kses_post( $metadata['caption'] ),
			) );
			update_post_meta( $attachment_id, "ai_caption_{$language}", wp_kses_post( $metadata['caption'] ) );
		}

		// Apply title.
		if ( ! empty( $metadata['title'] ) ) {
			wp_update_post( array(
				'ID'         => $attachment_id,
				'post_title' => sanitize_text_field( $metadata['title'] ),
			) );
			update_post_meta( $attachment_id, "ai_title_{$language}", sanitize_text_field( $metadata['title'] ) );
		}

		// Apply keywords.
		if ( ! empty( $metadata['keywords'] ) ) {
			update_post_meta(
				$attachment_id,
				"ai_keywords_{$language}",
				array_map( 'sanitize_text_field', $metadata['keywords'] )
			);
		}

		/**
		 * Fires after applying metadata.
		 *
		 * @since 1.0.0
		 * @param int    $attachment_id Attachment ID.
		 * @param string $language      Language code.
		 * @param array  $metadata      Metadata that was applied.
		 */
		do_action( 'ai_media_after_apply_metadata', $attachment_id, $language, $metadata );
	}

	/**
	 * Apply draft metadata to attachment.
	 *
	 * Stores metadata in draft meta fields for review before approval.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param array  $metadata      Metadata array.
	 */
	private function apply_draft_metadata( int $attachment_id, string $language, array $metadata ): void {

		// Store draft ALT text.
		if ( ! empty( $metadata['alt'] ) ) {
			update_post_meta( $attachment_id, '_ai_media_draft_alt', sanitize_text_field( $metadata['alt'] ) );
		}

		// Store draft caption.
		if ( ! empty( $metadata['caption'] ) ) {
			update_post_meta( $attachment_id, '_ai_media_draft_caption', wp_kses_post( $metadata['caption'] ) );
		}

		// Store draft title.
		if ( ! empty( $metadata['title'] ) ) {
			update_post_meta( $attachment_id, '_ai_media_draft_title', sanitize_text_field( $metadata['title'] ) );
		}

		// Store keywords (same as normal, not drafted).
		if ( ! empty( $metadata['keywords'] ) && is_array( $metadata['keywords'] ) ) {
			update_post_meta(
				$attachment_id,
				"_ai_media_keywords_{$language}",
				array_map( 'sanitize_text_field', $metadata['keywords'] )
			);
		}

		/**
		 * Fires after applying draft metadata.
		 *
		 * @since 1.0.0
		 * @param int    $attachment_id Attachment ID.
		 * @param string $language      Language code.
		 * @param array  $metadata      Draft metadata that was applied.
		 */
		do_action( 'ai_media_after_apply_draft_metadata', $attachment_id, $language, $metadata );
	}
}
