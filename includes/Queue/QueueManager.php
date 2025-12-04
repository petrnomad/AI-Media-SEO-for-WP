<?php
/**
 * Queue Manager
 *
 * Manages background processing queue using Action Scheduler.
 *
 * @package    AIMediaSEO
 * @subpackage Queue
 * @since      1.0.0
 * @deprecated 1.5.0 Use ProcessingSynchronizer instead. Action Scheduler has been replaced with AJAX-based processing.
 */

namespace AIMediaSEO\Queue;

use AIMediaSEO\Storage\MetadataStore;
use AIMediaSEO\Storage\AuditLogger;

/**
 * QueueManager class.
 *
 * Handles enqueueing and processing of image analysis jobs.
 *
 * @since 1.0.0
 * @deprecated 1.5.0 Use ProcessingSynchronizer instead.
 */
class QueueManager {

	/**
	 * Action group name.
	 *
	 * @var string
	 */
	private $group = 'ai-media';

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
	 * Configuration.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->rate_limiter   = new RateLimiter();
		$this->metadata_store = new MetadataStore();
		$this->audit_logger   = new AuditLogger();

		$settings = get_option( 'ai_media_seo_settings', array() );

		$this->config = array(
			'batch_size'      => $settings['batch_size'] ?? 50,
			'max_concurrent'  => $settings['max_concurrent'] ?? 3,
			'rate_limit_rpm'  => $settings['rate_limit_rpm'] ?? 120,
			'backoff_base'    => $settings['backoff_base'] ?? 5,
			'backoff_max'     => $settings['backoff_max'] ?? 300,
			'max_retries'     => 3,
		);
	}

	/**
	 * Enqueue single image for processing.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param array  $options       Processing options.
	 * @return int|false Action ID or false on failure.
	 */
	public function enqueue_single( int $attachment_id, string $language, array $options = array() ) {
		// Check if already queued.
		if ( $this->is_queued( $attachment_id, $language ) ) {
			return false;
		}

		// Action Scheduler requires indexed array for positional parameters.
		$args = array(
			$attachment_id, // 0: attachment_id
			$language,      // 1: language
			$options,       // 2: options
		);

		$action_id = as_schedule_single_action(
			time() + $this->calculate_delay(),
			'ai_media_process_single',
			$args,
			$this->group
		);

		if ( $action_id ) {
			$this->audit_logger->log_event(
				'queued_single',
				$attachment_id,
				array(
					'language'  => $language,
					'action_id' => $action_id,
				)
			);
		}

		return $action_id;
	}

	/**
	 * Enqueue batch of images for processing.
	 *
	 * @since 1.0.0
	 * @param array  $attachment_ids Array of attachment IDs.
	 * @param string $language       Language code.
	 * @param array  $options        Processing options.
	 * @return array {
	 *     Batch information.
	 *
	 *     @type string $batch_id    Unique batch identifier.
	 *     @type int    $total       Total images in batch.
	 *     @type int    $chunks      Number of chunks.
	 *     @type array  $action_ids  Scheduled action IDs.
	 * }
	 */
	public function enqueue_batch( array $attachment_ids, string $language, array $options = array() ): array {
		$batch_id = $this->generate_batch_id();

		// Remove duplicates and already queued items.
		$attachment_ids = array_unique( $attachment_ids );
		$attachment_ids = array_filter( $attachment_ids, function( $id ) use ( $language ) {
			return ! $this->is_queued( $id, $language );
		});

		if ( empty( $attachment_ids ) ) {
			return array(
				'batch_id'   => $batch_id,
				'total'      => 0,
				'chunks'     => 0,
				'action_ids' => array(),
			);
		}

		// Split into chunks.
		$chunks = array_chunk( $attachment_ids, $this->config['batch_size'] );
		$action_ids = array();

		$delay = 0;

		foreach ( $chunks as $index => $chunk ) {
			// Action Scheduler requires indexed array for positional parameters.
			$args = array(
				$batch_id,        // 0: batch_id
				$chunk,           // 1: attachment_ids
				$language,        // 2: language
				$options,         // 3: options
				$index,           // 4: chunk_index
				count( $chunks ), // 5: total_chunks
			);

			// Debug: Check if Action Scheduler function exists.
			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				continue;
			}

			$action_id = as_schedule_single_action(
				time() + $delay,
				'ai_media_process_batch',
				$args,
				$this->group
			);

			if ( $action_id ) {
				$action_ids[] = $action_id;
			}

			// Add delay between chunks to respect rate limits.
			$delay += $this->calculate_chunk_delay();
		}

		// Log batch creation.
		$this->audit_logger->log_batch_started(
			$attachment_ids,
			array(
				'batch_id' => $batch_id,
				'language' => $language,
				'chunks'   => count( $chunks ),
			)
		);

		// Store batch metadata.
		$this->store_batch_metadata( $batch_id, array(
			'total'         => count( $attachment_ids ),
			'chunks'        => count( $chunks ),
			'language'      => $language,
			'action_ids'    => $action_ids,
			'created_at'    => current_time( 'mysql' ),
			'status'        => 'queued',
		));

		return array(
			'batch_id'   => $batch_id,
			'total'      => count( $attachment_ids ),
			'chunks'     => count( $chunks ),
			'action_ids' => $action_ids,
		);
	}

	/**
	 * Check if attachment is already queued.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @return bool True if queued.
	 */
	public function is_queued( int $attachment_id, string $language ): bool {
		// Check in database for pending/processing jobs.
		$jobs = $this->metadata_store->get_jobs_for_attachment( $attachment_id, $language );

		foreach ( $jobs as $job ) {
			if ( in_array( $job['status'], array( 'pending', 'processing' ), true ) ) {
				return true;
			}
		}

		// Check Action Scheduler.
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => 'ai_media_process_single',
				'args'   => array(
					'attachment_id' => $attachment_id,
					'language'      => $language,
				),
				'group'  => $this->group,
				'status' => array( 'pending', 'in-progress' ),
			),
			'ids'
		);

		return ! empty( $actions );
	}

	/**
	 * Get queue status.
	 *
	 * @since 1.0.0
	 * @return array Queue statistics.
	 */
	public function get_status(): array {
		$pending = as_get_scheduled_actions(
			array(
				'group'  => $this->group,
				'status' => 'pending',
			),
			'ids'
		);

		$in_progress = as_get_scheduled_actions(
			array(
				'group'  => $this->group,
				'status' => 'in-progress',
			),
			'ids'
		);

		$failed = as_get_scheduled_actions(
			array(
				'group'  => $this->group,
				'status' => 'failed',
			),
			'ids'
		);

		return array(
			'pending'     => count( $pending ),
			'in_progress' => count( $in_progress ),
			'failed'      => count( $failed ),
			'rate_limits' => $this->rate_limiter->get_status(),
		);
	}

	/**
	 * Cancel all pending actions.
	 *
	 * @since 1.0.0
	 * @return int Number of actions cancelled.
	 */
	public function cancel_all(): int {
		return as_unschedule_all_actions( null, array(), $this->group );
	}

	/**
	 * Cancel specific batch.
	 *
	 * @since 1.0.0
	 * @param string $batch_id Batch ID.
	 * @return int Number of actions cancelled.
	 */
	public function cancel_batch( string $batch_id ): int {
		$batch = $this->get_batch_metadata( $batch_id );

		if ( ! $batch ) {
			return 0;
		}

		$cancelled = 0;

		if ( ! empty( $batch['action_ids'] ) ) {
			foreach ( $batch['action_ids'] as $action_id ) {
				if ( as_unschedule_action( $action_id, array(), $this->group ) ) {
					$cancelled++;
				}
			}
		}

		// Update batch status.
		$this->update_batch_metadata( $batch_id, array( 'status' => 'cancelled' ) );

		return $cancelled;
	}

	/**
	 * Calculate delay for next action.
	 *
	 * Optimized to avoid proactive delays - let workers process at full speed
	 * and only reschedule when actual rate limits are hit.
	 *
	 * @since 1.0.0
	 * @return int Delay in seconds.
	 */
	private function calculate_delay(): int {
		// Process immediately - no proactive delays.
		// Rate limiting is handled during execution via get_delay() and reschedule.
		return 0;
	}

	/**
	 * Calculate delay between batch chunks.
	 *
	 * Optimized to process chunks immediately without delays.
	 * Rate limiting is handled during execution.
	 *
	 * @since 1.0.0
	 * @return int Delay in seconds.
	 */
	private function calculate_chunk_delay(): int {
		// Process chunks immediately - no artificial delays.
		// Rate limiting happens during processing via get_delay() and reschedule.
		return 0;
	}

	/**
	 * Generate unique batch ID.
	 *
	 * @since 1.0.0
	 * @return string Batch ID.
	 */
	private function generate_batch_id(): string {
		return 'batch_' . time() . '_' . wp_generate_password( 8, false );
	}

	/**
	 * Store batch metadata.
	 *
	 * @since 1.0.0
	 * @param string $batch_id Batch ID.
	 * @param array  $metadata Metadata to store.
	 */
	private function store_batch_metadata( string $batch_id, array $metadata ): void {
		set_transient( 'ai_media_batch_' . $batch_id, $metadata, WEEK_IN_SECONDS );
	}

	/**
	 * Get batch metadata.
	 *
	 * @since 1.0.0
	 * @param string $batch_id Batch ID.
	 * @return array|false Batch metadata or false.
	 */
	public function get_batch_metadata( string $batch_id ) {
		return get_transient( 'ai_media_batch_' . $batch_id );
	}

	/**
	 * Update batch metadata.
	 *
	 * @since 1.0.0
	 * @param string $batch_id Batch ID.
	 * @param array  $updates  Data to update.
	 */
	private function update_batch_metadata( string $batch_id, array $updates ): void {
		$metadata = $this->get_batch_metadata( $batch_id );

		if ( $metadata ) {
			$metadata = array_merge( $metadata, $updates );
			$this->store_batch_metadata( $batch_id, $metadata );
		}
	}

	/**
	 * Get batch progress.
	 *
	 * @since 1.0.0
	 * @param string $batch_id Batch ID.
	 * @return array Progress information.
	 */
	public function get_batch_progress( string $batch_id ): array {
		$metadata = $this->get_batch_metadata( $batch_id );

		if ( ! $metadata ) {
			return array(
				'found'      => false,
				'total'      => 0,
				'completed'  => 0,
				'failed'     => 0,
				'percentage' => 0,
			);
		}

		// Count completed and failed actions.
		$completed = 0;
		$failed = 0;

		if ( ! empty( $metadata['action_ids'] ) ) {
			foreach ( $metadata['action_ids'] as $action_id ) {
				$action = as_get_scheduled_actions(
					array(
						'claim_id' => $action_id,
					),
					'ARRAY_A'
				);

				if ( ! empty( $action ) ) {
					$status = $action[0]['status'] ?? '';

					if ( $status === 'complete' ) {
						$completed++;
					} elseif ( $status === 'failed' ) {
						$failed++;
					}
				}
			}
		}

		$total = $metadata['total'] ?? 0;
		$percentage = $total > 0 ? ( ( $completed + $failed ) / $total ) * 100 : 0;

		return array(
			'found'      => true,
			'total'      => $total,
			'completed'  => $completed,
			'failed'     => $failed,
			'pending'    => $total - $completed - $failed,
			'percentage' => round( $percentage, 2 ),
			'status'     => $metadata['status'] ?? 'unknown',
		);
	}
}
