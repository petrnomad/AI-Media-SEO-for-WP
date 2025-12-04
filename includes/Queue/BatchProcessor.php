<?php
/**
 * Batch Processor
 *
 * Processes queued image analysis jobs.
 *
 * @package    AIMediaSEO
 * @subpackage Queue
 * @since      1.0.0
 * @deprecated 1.5.0 Use ProcessingSynchronizer instead. Action Scheduler has been replaced with AJAX-based processing.
 */

namespace AIMediaSEO\Queue;

use AIMediaSEO\Analyzer\ImageAnalyzer;
use AIMediaSEO\Providers\OpenAIProvider;
use AIMediaSEO\Storage\MetadataStore;
use AIMediaSEO\Storage\AuditLogger;
use Exception;

/**
 * BatchProcessor class.
 *
 * Handles actual processing of queued jobs.
 *
 * @since 1.0.0
 * @deprecated 1.5.0 Use ProcessingSynchronizer instead.
 */
class BatchProcessor {

	/**
	 * Image analyzer.
	 *
	 * @var ImageAnalyzer
	 */
	private $analyzer;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $rate_limiter;

	/**
	 * Metadata store.
	 *
	 * @var MetadataStore
	 */
	private $metadata_store;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private $audit_logger;

	/**
	 * Maximum retries per job.
	 *
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->analyzer       = new ImageAnalyzer();
		$this->rate_limiter   = new RateLimiter();
		$this->metadata_store = new MetadataStore();
		$this->audit_logger   = new AuditLogger();
	}

	/**
	 * Process single image.
	 *
	 * This is the callback for 'ai_media_process_single' action.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param array  $options       Processing options.
	 * @return bool True on success.
	 */
	public function process_single( int $attachment_id, string $language, array $options = array() ): bool {
		try {
			// Set status to processing.
			update_post_meta( $attachment_id, '_ai_media_status', 'processing' );

			$this->audit_logger->log_analysis_started( $attachment_id, $language, 'openai' );

			// Get provider.
			$provider = $this->get_provider();

			if ( ! $provider ) {
				throw new Exception( __( 'No provider configured.', 'ai-media-seo' ) );
			}

			// Check rate limit - if exceeded, reschedule instead of blocking.
			$delay = $this->rate_limiter->get_delay( $provider->get_name(), 'minute' );
			if ( $delay > 0 ) {
				// Reschedule for later instead of blocking the worker.
				update_post_meta( $attachment_id, '_ai_media_status', 'pending' );

				as_schedule_single_action(
					time() + $delay,
					'ai_media_process_single',
					array(
						'attachment_id' => $attachment_id,
						'language'      => $language,
						'options'       => $options,
					),
					'ai-media'
				);

				return false; // Job will be retried later.
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
				// Update status based on whether metadata was applied.
				if ( ! empty( $result['metadata_applied'] ) || ( ! empty( $result['can_auto_approve'] ) && ! empty( $options['auto_apply'] ) ) ) {
					update_post_meta( $attachment_id, '_ai_media_status', 'approved' );
				} else {
					update_post_meta( $attachment_id, '_ai_media_status', 'processed' );
				}

				$this->audit_logger->log_analysis_completed( $attachment_id, $result );
				return true;
			} else {
				$error = ! empty( $result['errors'] ) ? $result['errors'][0] : 'Unknown error';
				throw new Exception( $error );
			}
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

			$this->handle_failure( $attachment_id, $language, $e->getMessage() );

			// Re-throw to mark action as failed in Action Scheduler.
			throw $e;
		}
	}

	/**
	 * Process batch of images.
	 *
	 * This is the callback for 'ai_media_process_batch' action.
	 *
	 * @since 1.0.0
	 * @param string $batch_id       Batch ID.
	 * @param array  $attachment_ids Array of attachment IDs.
	 * @param string $language       Language code.
	 * @param array  $options        Processing options.
	 * @param int    $chunk_index    Current chunk index.
	 * @param int    $total_chunks   Total number of chunks.
	 * @return array Processing results.
	 */
	public function process_batch( string $batch_id, array $attachment_ids, string $language, array $options = array(), int $chunk_index = 0, int $total_chunks = 1 ): array {
		$results = array(
			'batch_id'      => $batch_id,
			'chunk_index'   => $chunk_index,
			'total_chunks'  => $total_chunks,
			'total'         => count( $attachment_ids ),
			'success'       => array(),
			'failed'        => array(),
			'skipped'       => array(),
		);

		$provider = $this->get_provider();

		if ( ! $provider ) {
			return $results;
		}

		foreach ( $attachment_ids as $attachment_id ) {
			try {
				// Check if already processed.
				$existing_jobs = $this->metadata_store->get_jobs_for_attachment( $attachment_id, $language, 'approved' );
				if ( ! empty( $existing_jobs ) && empty( $options['force_reprocess'] ) ) {
					$results['skipped'][] = $attachment_id;
					continue;
				}

				// Set status to processing.
				update_post_meta( $attachment_id, '_ai_media_status', 'processing' );

				// Check rate limit and wait if needed.
				if ( ! $this->rate_limiter->wait_if_needed( $provider->get_name(), 'minute', 60 ) ) {
					// Schedule remaining items for later.
					$this->reschedule_remaining( $attachment_ids, $attachment_id, $batch_id, $language, $options );
					break;
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
					// Update status based on whether metadata was applied.
					if ( ! empty( $result['metadata_applied'] ) || ( ! empty( $result['can_auto_approve'] ) && ! empty( $options['auto_apply'] ) ) ) {
						update_post_meta( $attachment_id, '_ai_media_status', 'approved' );
					} else {
						update_post_meta( $attachment_id, '_ai_media_status', 'processed' );
					}

					$results['success'][] = $attachment_id;
					$this->audit_logger->log_analysis_completed( $attachment_id, $result );
				} else {
					update_post_meta( $attachment_id, '_ai_media_status', 'failed' );
					$results['failed'][] = $attachment_id;
					$error = ! empty( $result['errors'] ) ? $result['errors'][0] : 'Unknown error';
					$this->handle_failure( $attachment_id, $language, $error );
				}

			} catch ( Exception $e ) {
				// Set status to failed.
				update_post_meta( $attachment_id, '_ai_media_status', 'failed' );

				$results['failed'][] = $attachment_id;

				$this->audit_logger->log_analysis_failed(
					$attachment_id,
					$e->getMessage(),
					array(
						'batch_id' => $batch_id,
						'language' => $language,
					)
				);

				$this->handle_failure( $attachment_id, $language, $e->getMessage() );
			}
		}

		// Log batch completion if this is the last chunk.
		if ( $chunk_index === $total_chunks - 1 ) {
			$this->audit_logger->log_batch_completed( $results );
		}

		return $results;
	}

	/**
	 * Handle processing failure.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param string $error         Error message.
	 */
	private function handle_failure( int $attachment_id, string $language, string $error ): void {
		// Get existing job.
		$jobs = $this->metadata_store->get_jobs_for_attachment( $attachment_id, $language, 'pending' );

		if ( ! empty( $jobs ) ) {
			$job = $jobs[0];
			$retry_count = (int) ( $job['retry_count'] ?? 0 );

			if ( $retry_count < $this->max_retries ) {
				// Update job with error and increment retry count.
				$this->metadata_store->update_job_status(
					$job['id'],
					'pending',
					array(
						'error_message' => $error,
						'retry_count'   => $retry_count + 1,
					)
				);

				// Schedule retry with exponential backoff.
				$delay = $this->calculate_retry_delay( $retry_count + 1 );

				as_schedule_single_action(
					time() + $delay,
					'ai_media_process_single',
					array(
						'attachment_id' => $attachment_id,
						'language'      => $language,
						'options'       => array( 'retry' => $retry_count + 1 ),
					),
					'ai-media'
				);
			} else {
				// Max retries reached, mark as failed.
				$this->metadata_store->update_job_status(
					$job['id'],
					'failed',
					array(
						'error_message' => $error,
					)
				);
			}
		}
	}

	/**
	 * Calculate retry delay with exponential backoff.
	 *
	 * @since 1.0.0
	 * @param int $retry_count Current retry count.
	 * @return int Delay in seconds.
	 */
	private function calculate_retry_delay( int $retry_count ): int {
		$settings = get_option( 'ai_media_seo_settings', array() );
		$base = $settings['backoff_base'] ?? 5;
		$max = $settings['backoff_max'] ?? 300;

		// Exponential backoff: base * 2^(retry_count - 1).
		$delay = $base * pow( 2, $retry_count - 1 );

		// Cap at max.
		return min( $delay, $max );
	}

	/**
	 * Reschedule remaining items in batch.
	 *
	 * @since 1.0.0
	 * @param array  $all_items      All items in batch.
	 * @param int    $current_item   Current item being processed.
	 * @param string $batch_id       Batch ID.
	 * @param string $language       Language code.
	 * @param array  $options        Processing options.
	 */
	private function reschedule_remaining( array $all_items, int $current_item, string $batch_id, string $language, array $options ): void {
		$current_index = array_search( $current_item, $all_items, true );

		if ( $current_index === false ) {
			return;
		}

		$remaining = array_slice( $all_items, $current_index );

		if ( empty( $remaining ) ) {
			return;
		}

		// Schedule new batch action with remaining items after rate limit resets.
		$delay = $this->rate_limiter->get_delay( 'openai', 'minute' ) + 5; // Add 5 second buffer.

		as_schedule_single_action(
			time() + $delay,
			'ai_media_process_batch',
			array(
				'batch_id'       => $batch_id,
				'attachment_ids' => $remaining,
				'language'       => $language,
				'options'        => $options,
			),
			'ai-media'
		);
	}

	/**
	 * Get configured provider.
	 *
	 * @since 1.0.0
	 * @return OpenAIProvider|null Provider instance or null.
	 */
	private function get_provider(): ?OpenAIProvider {
		$providers = get_option( 'ai_media_seo_providers', array() );

		if ( empty( $providers['openai']['api_key'] ) ) {
			return null;
		}

		return new OpenAIProvider( $providers['openai'] );
	}

	/**
	 * Get processing statistics.
	 *
	 * @since 1.0.0
	 * @return array Statistics.
	 */
	public function get_stats(): array {
		return array(
			'queue'       => $this->get_queue_stats(),
			'rate_limits' => $this->rate_limiter->get_status(),
			'jobs'        => $this->metadata_store->get_stats( 'today' ),
		);
	}

	/**
	 * Get queue statistics.
	 *
	 * @since 1.0.0
	 * @return array Queue stats.
	 */
	private function get_queue_stats(): array {
		$pending = as_get_scheduled_actions(
			array(
				'group'  => 'ai-media',
				'status' => 'pending',
			),
			'ids'
		);

		$in_progress = as_get_scheduled_actions(
			array(
				'group'  => 'ai-media',
				'status' => 'in-progress',
			),
			'ids'
		);

		$failed = as_get_scheduled_actions(
			array(
				'group'  => 'ai-media',
				'status' => 'failed',
				'date'   => strtotime( '-1 day' ),
			),
			'ids'
		);

		return array(
			'pending'     => count( $pending ),
			'in_progress' => count( $in_progress ),
			'failed'      => count( $failed ),
		);
	}
}
