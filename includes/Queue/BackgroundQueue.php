<?php
/**
 * Background Queue Processing
 *
 * Manages asynchronous batch processing using Action Scheduler.
 * Allows users to close browser while processing continues.
 *
 * @package    AIMediaSEO
 * @subpackage Queue
 * @since      2.2.0
 * @version    2.2.0 - FÁZE 3: Background Processing
 */

namespace AIMediaSEO\Queue;

/**
 * BackgroundQueue class.
 *
 * Handles background processing of image batches using Action Scheduler.
 *
 * @since 2.2.0
 */
class BackgroundQueue {

	/**
	 * Queue group name for Action Scheduler.
	 *
	 * @var string
	 */
	private const QUEUE_GROUP = 'ai_media_seo';

	/**
	 * Hook name for async processing action.
	 *
	 * @var string
	 */
	private const HOOK_PROCESS_IMAGE = 'ai_media_process_image_async';

	/**
	 * Hook name for batch cleanup.
	 *
	 * @var string
	 */
	private const HOOK_CLEANUP = 'ai_media_cleanup_batches';

	/**
	 * Enqueue batch for background processing.
	 *
	 * @since 2.2.0
	 * @param array $attachment_ids Array of attachment IDs to process.
	 * @param array $options        Processing options (language, provider, etc.).
	 * @return string Batch ID.
	 */
	public function enqueue_batch( array $attachment_ids, array $options = array() ): string {
		if ( empty( $attachment_ids ) ) {
			return '';
		}

		// Generate unique batch ID.
		$batch_id = uniqid( 'batch_', true );

		// Store batch metadata.
		$this->store_batch_metadata(
			$batch_id,
			array(
				'total'        => count( $attachment_ids ),
				'processed'    => 0,
				'success'      => 0,
				'failed'       => 0,
				'status'       => 'queued',
				'started_at'   => null,
				'completed_at' => null,
				'options'      => $options,
				'created_at'   => current_time( 'mysql' ),
			)
		);

		// Enqueue individual jobs using Action Scheduler.
		foreach ( $attachment_ids as $attachment_id ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					self::HOOK_PROCESS_IMAGE,
					array(
						'attachment_id' => $attachment_id,
						'batch_id'      => $batch_id,
						'options'       => $options,
					),
					self::QUEUE_GROUP
				);
			}
		}

		// Mark batch as processing.
		$batch_meta               = $this->get_batch_status( $batch_id );
		$batch_meta['status']     = 'processing';
		$batch_meta['started_at'] = current_time( 'mysql' );
		$this->store_batch_metadata( $batch_id, $batch_meta );

		return $batch_id;
	}

	/**
	 * Get batch status.
	 *
	 * @since 2.2.0
	 * @param string $batch_id Batch ID.
	 * @return array|null Batch metadata or null if not found.
	 */
	public function get_batch_status( string $batch_id ): ?array {
		$data = get_transient( "ai_media_batch_{$batch_id}" );

		if ( false === $data ) {
			return null;
		}

		return $data;
	}

	/**
	 * Update batch progress.
	 *
	 * @since 2.2.0
	 * @param string $batch_id Batch ID.
	 * @param bool   $success  Whether the last image was processed successfully.
	 */
	public function update_batch_progress( string $batch_id, bool $success ): void {
		$batch = $this->get_batch_status( $batch_id );

		if ( ! $batch ) {
			return;
		}

		$batch['processed']++;

		if ( $success ) {
			$batch['success']++;
		} else {
			$batch['failed']++;
		}

		// Check if batch completed.
		if ( $batch['processed'] >= $batch['total'] ) {
			$batch['status']       = 'completed';
			$batch['completed_at'] = current_time( 'mysql' );

			/**
			 * Fires when a batch is completed.
			 *
			 * @since 2.2.0
			 * @param string $batch_id Batch ID.
			 * @param array  $batch    Batch metadata.
			 */
			do_action( 'ai_media_batch_completed', $batch_id, $batch );
		}

		$this->store_batch_metadata( $batch_id, $batch );
	}

	/**
	 * Cancel batch processing.
	 *
	 * @since 2.2.0
	 * @param string $batch_id Batch ID.
	 * @return bool True on success.
	 */
	public function cancel_batch( string $batch_id ): bool {
		global $wpdb;

		// Find all pending actions for this batch using database query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$actions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, args FROM {$wpdb->prefix}actionscheduler_actions
				WHERE hook = %s
				AND status = 'pending'
				AND args LIKE %s",
				self::HOOK_PROCESS_IMAGE,
				'%' . $wpdb->esc_like( $batch_id ) . '%'
			)
		);

		// Unschedule each action individually.
		foreach ( $actions as $action ) {
			$args = json_decode( $action->args, true );

			if ( isset( $args['batch_id'] ) && $args['batch_id'] === $batch_id ) {
				if ( function_exists( 'as_unschedule_action' ) ) {
					as_unschedule_action( self::HOOK_PROCESS_IMAGE, $args, self::QUEUE_GROUP );
				}
			}
		}

		// Update batch status.
		$batch = $this->get_batch_status( $batch_id );

		if ( $batch ) {
			$batch['status']       = 'cancelled';
			$batch['completed_at'] = current_time( 'mysql' );
			$this->store_batch_metadata( $batch_id, $batch );
		}

		/**
		 * Fires when a batch is cancelled.
		 *
		 * @since 2.2.0
		 * @param string $batch_id Batch ID.
		 */
		do_action( 'ai_media_batch_cancelled', $batch_id );

		return true;
	}

	/**
	 * Get active batches.
	 *
	 * @since 2.2.0
	 * @return array Array of active batch IDs.
	 */
	public function get_active_batches(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$transients = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_ai_media_batch_%'
			AND option_value LIKE '%\"status\":\"processing\"%'"
		);

		$batch_ids = array();

		foreach ( $transients as $transient ) {
			$batch_id = str_replace( '_transient_ai_media_batch_', '', $transient );
			$batch_ids[] = $batch_id;
		}

		return $batch_ids;
	}

	/**
	 * Cleanup old batches.
	 *
	 * Removes completed/cancelled batches older than 7 days.
	 *
	 * @since 2.2.0
	 * @param int $days Number of days to keep. Default 7.
	 * @return int Number of batches cleaned up.
	 */
	public function cleanup_old_batches( int $days = 7 ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$transients = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_ai_media_batch_%'"
		);

		$cleaned = 0;
		$cutoff  = time() - ( $days * DAY_IN_SECONDS );

		foreach ( $transients as $row ) {
			$batch = maybe_unserialize( $row->option_value );

			// Check if completed or cancelled and old enough.
			if ( isset( $batch['completed_at'] ) && in_array( $batch['status'], array( 'completed', 'cancelled' ), true ) ) {
				$completed_time = strtotime( $batch['completed_at'] );

				if ( $completed_time < $cutoff ) {
					$batch_id = str_replace( '_transient_ai_media_batch_', '', $row->option_name );
					delete_transient( "ai_media_batch_{$batch_id}" );
					$cleaned++;
				}
			}
		}

		return $cleaned;
	}

	/**
	 * Store batch metadata.
	 *
	 * @since 2.2.0
	 * @param string $batch_id Batch ID.
	 * @param array  $data     Batch metadata.
	 */
	private function store_batch_metadata( string $batch_id, array $data ): void {
		set_transient(
			"ai_media_batch_{$batch_id}",
			$data,
			7 * DAY_IN_SECONDS // Keep for 7 days.
		);
	}

	/**
	 * Register Action Scheduler hooks.
	 *
	 * @since 2.2.0
	 */
	public static function register_hooks(): void {
		// Worker action for processing images.
		add_action( self::HOOK_PROCESS_IMAGE, array( __CLASS__, 'process_image_background' ), 10, 1 );

		// Daily cleanup of old batches.
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( ! as_next_scheduled_action( self::HOOK_CLEANUP ) ) {
			as_schedule_recurring_action(
				time(),
				DAY_IN_SECONDS,
				self::HOOK_CLEANUP,
				array(),
				self::QUEUE_GROUP
			);
		}

		add_action( self::HOOK_CLEANUP, array( __CLASS__, 'cleanup_batches_action' ) );
	}

	/**
	 * Background worker for processing images.
	 *
	 * @since 2.2.0
	 * @param array $args Action arguments.
	 */
	public static function process_image_background( array $args ): void {
		$attachment_id = $args['attachment_id'] ?? 0;
		$batch_id      = $args['batch_id'] ?? '';
		$options       = $args['options'] ?? array();

		if ( ! $attachment_id || ! $batch_id ) {
			return;
		}

		try {
			// Process image using ProcessingSynchronizer.
			$synchronizer = new ProcessingSynchronizer();
			$result       = $synchronizer->process_single( $attachment_id, $options );

			// Update batch progress.
			$queue = new self();
			$queue->update_batch_progress( $batch_id, $result['success'] ?? false );

		} catch ( \Exception $e ) {
			// Log error.
			error_log(
				sprintf(
					'[AI Media SEO] Background processing failed for image %d in batch %s: %s',
					$attachment_id,
					$batch_id,
					$e->getMessage()
				)
			);

			// Mark as failed.
			$queue = new self();
			$queue->update_batch_progress( $batch_id, false );
		}
	}

	/**
	 * Cleanup batches action (scheduled daily).
	 *
	 * @since 2.2.0
	 */
	public static function cleanup_batches_action(): void {
		$queue   = new self();
		$cleaned = $queue->cleanup_old_batches( 7 );

		if ( $cleaned > 0 ) {
			error_log( "[AI Media SEO] Cleaned up {$cleaned} old batches." );
		}
	}

	/**
	 * Get queue statistics.
	 *
	 * @since 2.2.0
	 * @return array Queue statistics.
	 */
	public function get_statistics(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$all_batches = $wpdb->get_results(
			"SELECT option_value FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_ai_media_batch_%'"
		);

		$stats = array(
			'total_batches'     => 0,
			'processing'        => 0,
			'completed'         => 0,
			'cancelled'         => 0,
			'queued'            => 0,
			'total_images'      => 0,
			'images_processed'  => 0,
			'images_successful' => 0,
			'images_failed'     => 0,
		);

		foreach ( $all_batches as $row ) {
			$batch = maybe_unserialize( $row->option_value );

			if ( ! is_array( $batch ) ) {
				continue;
			}

			$stats['total_batches']++;
			$stats['total_images']      += $batch['total'] ?? 0;
			$stats['images_processed']  += $batch['processed'] ?? 0;
			$stats['images_successful'] += $batch['success'] ?? 0;
			$stats['images_failed']     += $batch['failed'] ?? 0;

			$status = $batch['status'] ?? 'unknown';

			if ( isset( $stats[ $status ] ) ) {
				$stats[ $status ]++;
			}
		}

		return $stats;
	}
}
