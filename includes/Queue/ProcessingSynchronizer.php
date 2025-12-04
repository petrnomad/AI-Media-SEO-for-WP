<?php
/**
 * Processing Synchronizer
 *
 * Handles synchronous image processing without Action Scheduler.
 *
 * @package    AIMediaSEO
 * @subpackage Queue
 * @since      1.5.0
 */

namespace AIMediaSEO\Queue;

use AIMediaSEO\Analyzer\ImageAnalyzer;
use AIMediaSEO\Providers\ProviderFactory;
use AIMediaSEO\Providers\ProviderInterface;
use AIMediaSEO\Storage\AuditLogger;
use Exception;

/**
 * ProcessingSynchronizer class.
 *
 * Synchronous processing wrapper for immediate batch operations.
 *
 * @since 1.5.0
 */
class ProcessingSynchronizer {

	/**
	 * Image analyzer instance.
	 *
	 * @var ImageAnalyzer
	 */
	private $analyzer;

	/**
	 * Rate limiter instance.
	 *
	 * @var RateLimiter
	 */
	private $rate_limiter;

	/**
	 * Audit logger instance.
	 *
	 * @var AuditLogger
	 */
	private $audit_logger;

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 */
	public function __construct() {
		$this->analyzer      = new ImageAnalyzer();
		$this->rate_limiter  = new RateLimiter();
		$this->audit_logger  = new AuditLogger();

		// Increase timeout for synchronous processing.
		$this->increase_timeout();
	}

	/**
	 * Process single image synchronously.
	 *
	 * @since 1.5.0
	 * @param int    $attachment_id Attachment ID to process.
	 * @param string $language      Language code.
	 * @param array  $options       Processing options.
	 * @return array Processing result.
	 */
	public function process_single( int $attachment_id, string $language, array $options = array() ): array {
		try {
			// Set status to processing.
			update_post_meta( $attachment_id, '_ai_media_status', 'processing' );

			// Get provider.
			$provider = $this->get_provider();
			if ( ! $provider ) {
				throw new Exception( __( 'No provider configured.', 'ai-media-seo' ) );
			}

			// Log analysis started.
			$this->audit_logger->log_analysis_started( $attachment_id, $language, $provider->get_name() );

			// Check rate limit.
			if ( ! $this->check_rate_limit( $provider ) ) {
				throw new Exception( __( 'Rate limit exceeded. Please try again later.', 'ai-media-seo' ) );
			}

			// Record request.
			$this->rate_limiter->record_request( $provider->get_name() );

			// Process image.
			$result = $this->analyzer->process(
				$attachment_id,
				$language,
				$provider,
				$options
			);

			if ( $result['success'] ) {
				// Determine final status based on how metadata was applied:
				// - metadata_applied = true: metadata applied directly → status = 'approved'
				// - metadata_applied_to_draft = true: metadata in draft → status = 'processed' (needs review)
				// - neither: not applied at all → status = 'processed'
				$status = 'processed';

				if ( ! empty( $result['metadata_applied'] ) ) {
					$status = 'approved';
				} elseif ( ! empty( $result['metadata_applied_to_draft'] ) ) {
					$status = 'processed';
				}

				update_post_meta( $attachment_id, '_ai_media_status', $status );
				$result['status'] = $status;

				$this->audit_logger->log_analysis_completed( $attachment_id, $result );
			} else {
				update_post_meta( $attachment_id, '_ai_media_status', 'failed' );
				$result['status'] = 'failed';

				$error = ! empty( $result['errors'] ) ? $result['errors'][0] : 'Unknown error';
				$this->audit_logger->log_analysis_failed(
					$attachment_id,
					$error,
					array(
						'language' => $language,
						'options'  => $options,
					)
				);
			}

			return $result;

		} catch ( Exception $e ) {

			// Set status to failed.
			update_post_meta( $attachment_id, '_ai_media_status', 'failed' );

			$this->audit_logger->log_analysis_failed(
				$attachment_id,
				$e->getMessage(),
				array(
					'language' => $language,
					'options'  => $options,
				)
			);

			return array(
				'success' => false,
				'status'  => 'failed',
				'errors'  => array( $e->getMessage() ),
			);
		}
	}

	/**
	 * Process batch of images synchronously.
	 *
	 * Used internally by process_single in loop.
	 * This method is for future batch optimization if needed.
	 *
	 * @since 1.5.0
	 * @param array  $attachment_ids Array of attachment IDs.
	 * @param string $language       Language code.
	 * @param array  $options        Processing options.
	 * @return array Batch results.
	 */
	public function process_batch( array $attachment_ids, string $language, array $options = array() ): array {
		$results = array(
			'success' => array(),
			'failed'  => array(),
			'total'   => count( $attachment_ids ),
		);

		foreach ( $attachment_ids as $attachment_id ) {
			$result = $this->process_single( $attachment_id, $language, $options );

			if ( $result['success'] ) {
				$results['success'][] = $attachment_id;
			} else {
				$results['failed'][] = $attachment_id;
			}
		}

		return $results;
	}

	/**
	 * Increase PHP timeout for long-running operations.
	 *
	 * @since 1.5.0
	 */
	private function increase_timeout(): void {
		// Only increase if we can.
		if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
			@set_time_limit( 300 ); // 5 minutes
		}

		// Increase memory limit if needed.
		$current_memory = ini_get( 'memory_limit' );
		if ( $current_memory && intval( $current_memory ) < 256 ) {
			@ini_set( 'memory_limit', '256M' );
		}
	}

	/**
	 * Check if rate limit allows processing.
	 *
	 * @since 1.5.0
	 * @param object $provider Provider instance.
	 * @return bool True if can process, false if rate limited.
	 */
	private function check_rate_limit( $provider ): bool {
		$delay = $this->rate_limiter->get_delay( $provider->get_name(), 'minute' );

		if ( $delay > 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Get configured primary provider.
	 *
	 * @since 1.5.0
	 * @return ProviderInterface|null Provider instance or null.
	 */
	private function get_provider(): ?ProviderInterface {
		$factory = new ProviderFactory();
		$provider = $factory->get_primary();

		if ( ! $provider ) {
			return null;
		}

		return $provider;
	}

	/**
	 * Handle processing error.
	 *
	 * @since 1.5.0
	 * @param Exception $e             Exception that occurred.
	 * @param int       $attachment_id Attachment ID.
	 * @return array Error result.
	 */
	private function handle_error( Exception $e, int $attachment_id ): array {

		update_post_meta( $attachment_id, '_ai_media_status', 'failed' );

		return array(
			'success'       => false,
			'attachment_id' => $attachment_id,
			'errors'        => array( $e->getMessage() ),
		);
	}
}
