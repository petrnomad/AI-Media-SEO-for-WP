<?php
/**
 * Auto Processor
 *
 * Automatically processes newly uploaded images.
 *
 * @package    AIMediaSEO
 * @subpackage Admin
 * @since      1.0.0
 */

namespace AIMediaSEO\Admin;

use AIMediaSEO\Queue\ProcessingSynchronizer;
use AIMediaSEO\Multilingual\LanguageDetector;

/**
 * AutoProcessor class.
 *
 * Handles automatic processing of uploaded images.
 *
 * @since 1.0.0
 */
class AutoProcessor {

	/**
	 * Processing synchronizer instance.
	 *
	 * @var ProcessingSynchronizer
	 */
	private $synchronizer;

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
		// Lazy load to avoid initialization issues.
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		// Use wp_generate_attachment_metadata hook which fires after upload completes.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'schedule_processing' ), 10, 2 );
	}

	/**
	 * Get processing synchronizer instance.
	 *
	 * @since 1.5.0
	 * @return ProcessingSynchronizer
	 */
	private function get_synchronizer() {
		if ( ! $this->synchronizer ) {
			$this->synchronizer = new ProcessingSynchronizer();
		}
		return $this->synchronizer;
	}

	/**
	 * Get language detector instance.
	 *
	 * @since 1.0.0
	 * @return LanguageDetector
	 */
	private function get_language_detector() {
		if ( ! $this->language_detector ) {
			$this->language_detector = new LanguageDetector();
		}
		return $this->language_detector;
	}

	/**
	 * Schedule processing for newly uploaded attachment.
	 *
	 * @since 1.0.0
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unmodified metadata.
	 */
	public function schedule_processing( $metadata, $attachment_id ) {
		try {
			// Check if auto-processing is enabled.
			$settings = get_option( 'ai_media_seo_settings', array() );

			if ( empty( $settings['enable_auto_process'] ) ) {
				return $metadata;
			}

			// Check if attachment is an image.
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				return $metadata;
			}

			// Check image size limit.
			$max_size = $settings['max_image_size'] ?? 5; // MB
			$file_path = get_attached_file( $attachment_id );
			if ( $file_path && file_exists( $file_path ) ) {
				$file_size_mb = filesize( $file_path ) / ( 1024 * 1024 );
				if ( $file_size_mb > $max_size ) {
					return $metadata;
				}
			}

			// Get default language.
			$language = $this->get_language_detector()->get_default_language();

			// Set status to pending.
			update_post_meta( $attachment_id, '_ai_media_status', 'pending' );

			// Process synchronously using ProcessingSynchronizer.
			$synchronizer = $this->get_synchronizer();
			$result = $synchronizer->process_single(
				$attachment_id,
				$language,
				array(
					'auto_process' => true,
					'auto_apply'   => true, // Automatically apply metadata if score is good enough.
					'source'       => 'upload',
				)
			);
		} catch ( \Exception $e ) {
			// Don't block the upload on error.
		}

		return $metadata; // Must return metadata for filter.
	}
}
