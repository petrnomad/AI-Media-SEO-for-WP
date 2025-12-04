<?php
/**
 * Audit Logger
 *
 * Logs all AI Media SEO events for auditing.
 *
 * @package    AIMediaSEO
 * @subpackage Storage
 * @since      1.0.0
 */

namespace AIMediaSEO\Storage;

/**
 * AuditLogger class.
 *
 * Provides detailed logging for all plugin operations.
 *
 * @since 1.0.0
 */
class AuditLogger {

	/**
	 * Metadata store instance.
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
	 * Log analysis started.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param string $provider      Provider name.
	 */
	public function log_analysis_started( int $attachment_id, string $language, string $provider ): void {
		$this->metadata_store->log_event(
			'analysis_started',
			$attachment_id,
			array(
				'language' => $language,
				'provider' => $provider,
			)
		);
	}

	/**
	 * Log analysis completed.
	 *
	 * @since 1.0.0
	 * @param int   $attachment_id Attachment ID.
	 * @param array $result        Processing result.
	 */
	public function log_analysis_completed( int $attachment_id, array $result ): void {
		$this->metadata_store->log_event(
			'analysis_completed',
			$attachment_id,
			array(
				'success'     => $result['success'],
				'language'    => $result['language'],
				'provider'    => $result['provider'],
				'score'       => $result['final_score'] ?? 0,
				'job_id'      => $result['job_id'] ?? 0,
				'total_time'  => $result['total_time'] ?? 0,
			)
		);
	}

	/**
	 * Log analysis failed.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $error         Error message.
	 * @param array  $context       Error context.
	 */
	public function log_analysis_failed( int $attachment_id, string $error, array $context = array() ): void {
		$this->metadata_store->log_event(
			'analysis_failed',
			$attachment_id,
			array_merge(
				array(
					'error' => $error,
				),
				$context
			)
		);
	}

	/**
	 * Log metadata approved.
	 *
	 * @since 1.0.0
	 * @param int   $attachment_id Attachment ID.
	 * @param int   $job_id        Job ID.
	 * @param array $fields        Approved fields.
	 */
	public function log_metadata_approved( int $attachment_id, int $job_id, array $fields ): void {
		$this->metadata_store->log_event(
			'metadata_approved',
			$attachment_id,
			array(
				'job_id' => $job_id,
				'fields' => $fields,
			)
		);
	}

	/**
	 * Log metadata rejected.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $job_id        Job ID.
	 * @param string $reason        Rejection reason.
	 */
	public function log_metadata_rejected( int $attachment_id, int $job_id, string $reason ): void {
		$this->metadata_store->log_event(
			'metadata_rejected',
			$attachment_id,
			array(
				'job_id' => $job_id,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Log batch processing started.
	 *
	 * @since 1.0.0
	 * @param array $attachment_ids Array of attachment IDs.
	 * @param array $options        Batch options.
	 */
	public function log_batch_started( array $attachment_ids, array $options ): void {
		$this->metadata_store->log_event(
			'batch_started',
			0,
			array(
				'count'   => count( $attachment_ids ),
				'options' => $options,
			)
		);
	}

	/**
	 * Log batch processing completed.
	 *
	 * @since 1.0.0
	 * @param array $result Batch result.
	 */
	public function log_batch_completed( array $result ): void {
		$this->metadata_store->log_event(
			'batch_completed',
			0,
			$result
		);
	}

	/**
	 * Log provider error.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $error    Error message.
	 * @param array  $context  Error context.
	 */
	public function log_provider_error( string $provider, string $error, array $context = array() ): void {
		$this->metadata_store->log_event(
			'provider_error',
			0,
			array(
				'provider' => $provider,
				'error'    => $error,
				'context'  => $context,
			)
		);
	}

	/**
	 * Log settings changed.
	 *
	 * @since 1.0.0
	 * @param array $old_settings Old settings.
	 * @param array $new_settings New settings.
	 */
	public function log_settings_changed( array $old_settings, array $new_settings ): void {
		$changes = array();

		foreach ( $new_settings as $key => $value ) {
			if ( ! isset( $old_settings[ $key ] ) || $old_settings[ $key ] !== $value ) {
				$changes[ $key ] = array(
					'old' => $old_settings[ $key ] ?? null,
					'new' => $value,
				);
			}
		}

		if ( ! empty( $changes ) ) {
			$this->metadata_store->log_event(
				'settings_changed',
				0,
				array(
					'changes' => $changes,
				)
			);
		}
	}

	/**
	 * Log generic event.
	 *
	 * @since 1.0.0
	 * @param string $event_type    Event type.
	 * @param int    $attachment_id Attachment ID (0 for global events).
	 * @param array  $meta          Event metadata.
	 */
	public function log_event( string $event_type, int $attachment_id, array $meta = array() ): void {
		$this->metadata_store->log_event( $event_type, $attachment_id, $meta );
	}

	/**
	 * Get audit trail for attachment.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID.
	 * @return array Array of events.
	 */
	public function get_audit_trail( int $attachment_id ): array {
		return $this->metadata_store->get_events(
			array(
				'attachment_id' => $attachment_id,
				'limit'         => 100,
			)
		);
	}
}
