<?php
/**
 * WP-CLI Commands
 *
 * WP-CLI commands for AI Media SEO plugin.
 *
 * @package    AIMediaSEO
 * @subpackage CLI
 * @since      1.0.0
 */

namespace AIMediaSEO\CLI;

use AIMediaSEO\Providers\ProviderFactory;
use AIMediaSEO\Queue\QueueManager;
use AIMediaSEO\Storage\MetadataStore;
use AIMediaSEO\Multilingual\LanguageDetector;

/**
 * AI Media SEO CLI Commands
 *
 * @since 1.0.0
 */
class Commands {

	/**
	 * Provider factory.
	 *
	 * @var ProviderFactory
	 */
	private $provider_factory;

	/**
	 * Queue manager.
	 *
	 * @var QueueManager
	 */
	private $queue_manager;

	/**
	 * Metadata store.
	 *
	 * @var MetadataStore
	 */
	private $metadata_store;

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
		$this->provider_factory  = new ProviderFactory();
		$this->queue_manager     = new QueueManager();
		$this->metadata_store    = new MetadataStore();
		$this->language_detector = new LanguageDetector();
	}

	/**
	 * Scan media library for images missing metadata.
	 *
	 * ## OPTIONS
	 *
	 * [--language=<language>]
	 * : Language code (e.g., cs, en). Default: current language.
	 *
	 * [--missing=<field>]
	 * : Only show images missing specific field (alt, caption, title).
	 *
	 * [--format=<format>]
	 * : Output format (table, json, count). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-media scan --language=cs --missing=alt
	 *     wp ai-media scan --format=count
	 *
	 * @since 1.0.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function scan( $args, $assoc_args ) {
		$language = $assoc_args['language'] ?? $this->language_detector->get_current_language();
		$missing  = $assoc_args['missing'] ?? null;
		$format   = $assoc_args['format'] ?? 'table';

		\WP_CLI::line( sprintf( 'Scanning media library for language: %s', $language ) );

		$query_args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$attachments = get_posts( $query_args );
		$results     = array();

		foreach ( $attachments as $attachment_id ) {
			$metadata = $this->metadata_store->get_metadata( $attachment_id, $language );

			$is_missing = false;
			if ( $missing ) {
				$is_missing = empty( $metadata[ $missing ] );
			} else {
				$is_missing = empty( $metadata['alt'] );
			}

			if ( $is_missing ) {
				$results[] = array(
					'ID'       => $attachment_id,
					'filename' => basename( get_attached_file( $attachment_id ) ),
					'missing'  => $missing ?? 'alt',
				);
			}
		}

		if ( $format === 'count' ) {
			\WP_CLI::success( sprintf( 'Found %d images missing metadata.', count( $results ) ) );
		} elseif ( $format === 'json' ) {
			\WP_CLI::line( wp_json_encode( $results ) );
		} else {
			if ( empty( $results ) ) {
				\WP_CLI::success( 'No images missing metadata!' );
			} else {
				\WP_CLI\Utils\format_items( 'table', $results, array( 'ID', 'filename', 'missing' ) );
			}
		}
	}

	/**
	 * Analyze images and generate metadata.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs. If not provided, scans all.
	 *
	 * [--language=<language>]
	 * : Language code. Default: current language.
	 *
	 * [--provider=<provider>]
	 * : Specific provider to use (openai, anthropic, google). Default: fallback order.
	 *
	 * [--batch-size=<size>]
	 * : Number of images to process in batch. Default: 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-media analyze --ids=1,2,3 --language=cs
	 *     wp ai-media analyze --provider=anthropic
	 *
	 * @since 1.0.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function analyze( $args, $assoc_args ) {
		$language    = $assoc_args['language'] ?? $this->language_detector->get_current_language();
		$provider    = $assoc_args['provider'] ?? null;
		$batch_size  = absint( $assoc_args['batch-size'] ?? 50 );

		if ( ! empty( $assoc_args['ids'] ) ) {
			$attachment_ids = array_map( 'intval', explode( ',', $assoc_args['ids'] ) );
		} else {
			// Scan all images without metadata.
			$query_args = array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			);
			$attachment_ids = get_posts( $query_args );
		}

		$total = count( $attachment_ids );
		\WP_CLI::line( sprintf( 'Analyzing %d images...', $total ) );

		// Enqueue batch.
		$options = array();
		if ( $provider ) {
			$options['provider'] = $provider;
		}

		$batch_id = $this->queue_manager->enqueue_batch( $attachment_ids, $language, $options );

		\WP_CLI::success( sprintf( 'Batch %s enqueued. Processing will start automatically.', $batch_id ) );
	}

	/**
	 * Approve metadata for jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-id=<batch-id>]
	 * : Approve all jobs in a batch.
	 *
	 * [--status=<status>]
	 * : Approve all jobs with specific status. Default: needs_review.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-media approve --batch-id=abc123
	 *     wp ai-media approve --status=needs_review
	 *
	 * @since 1.0.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function approve( $args, $assoc_args ) {
		global $wpdb;

		$batch_id = $assoc_args['batch-id'] ?? null;
		$status   = $assoc_args['status'] ?? 'needs_review';

		$table_name = $wpdb->prefix . 'ai_media_jobs';

		if ( $batch_id ) {
			$jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name} WHERE request_data LIKE %s AND status = %s",
					'%batch_id":"' . $batch_id . '"%',
					$status
				),
				ARRAY_A
			);
		} else {
			$jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name} WHERE status = %s LIMIT 100",
					$status
				),
				ARRAY_A
			);
		}

		if ( empty( $jobs ) ) {
			\WP_CLI::warning( 'No jobs found to approve.' );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Approving jobs', count( $jobs ) );

		foreach ( $jobs as $job ) {
			$this->metadata_store->approve_job( $job['id'] );
			$progress->tick();
		}

		$progress->finish();

		\WP_CLI::success( sprintf( 'Approved %d jobs.', count( $jobs ) ) );
	}

	/**
	 * Show statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : Period for stats (today, week, month, all). Default: all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-media stats --period=month
	 *
	 * @since 1.0.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function stats( $args, $assoc_args ) {
		$period = $assoc_args['period'] ?? 'all';
		$stats  = $this->metadata_store->get_stats( $period );

		$data = array(
			array( 'Metric' => 'Total', 'Count' => $stats['total'] ),
			array( 'Metric' => 'Pending', 'Count' => $stats['pending'] ),
			array( 'Metric' => 'Processing', 'Count' => $stats['processing'] ),
			array( 'Metric' => 'Needs Review', 'Count' => $stats['needs_review'] ),
			array( 'Metric' => 'Approved', 'Count' => $stats['approved'] ),
			array( 'Metric' => 'Failed', 'Count' => $stats['failed'] ),
			array( 'Metric' => 'Skipped', 'Count' => $stats['skipped'] ),
			array( 'Metric' => 'Total Cost', 'Count' => '$' . number_format( $stats['total_cost'] / 100, 2 ) ),
			array( 'Metric' => 'Avg Score', 'Count' => number_format( $stats['avg_score'], 2 ) ),
		);

		\WP_CLI\Utils\format_items( 'table', $data, array( 'Metric', 'Count' ) );
	}

	/**
	 * Reset metadata for attachment.
	 *
	 * ## OPTIONS
	 *
	 * --attachment-id=<id>
	 * : Attachment ID to reset.
	 *
	 * [--language=<language>]
	 * : Language to reset. If not provided, resets all languages.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-media reset --attachment-id=123
	 *     wp ai-media reset --attachment-id=123 --language=cs
	 *
	 * @since 1.0.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function reset( $args, $assoc_args ) {
		$attachment_id = absint( $assoc_args['attachment-id'] ?? 0 );
		$language      = $assoc_args['language'] ?? null;

		if ( ! $attachment_id ) {
			\WP_CLI::error( 'Attachment ID is required.' );
		}

		if ( $language ) {
			delete_post_meta( $attachment_id, "ai_alt_{$language}" );
			delete_post_meta( $attachment_id, "ai_caption_{$language}" );
			delete_post_meta( $attachment_id, "ai_title_{$language}" );
			delete_post_meta( $attachment_id, "ai_keywords_{$language}" );
			delete_post_meta( $attachment_id, "ai_score_{$language}" );
			\WP_CLI::success( sprintf( 'Reset metadata for attachment %d (language: %s).', $attachment_id, $language ) );
		} else {
			$languages = $this->language_detector->get_languages();
			foreach ( $languages as $lang ) {
				delete_post_meta( $attachment_id, "ai_alt_{$lang}" );
				delete_post_meta( $attachment_id, "ai_caption_{$lang}" );
				delete_post_meta( $attachment_id, "ai_title_{$lang}" );
				delete_post_meta( $attachment_id, "ai_keywords_{$lang}" );
				delete_post_meta( $attachment_id, "ai_score_{$lang}" );
			}
			delete_post_meta( $attachment_id, 'ai_status' );
			delete_post_meta( $attachment_id, 'ai_last_provider' );
			delete_post_meta( $attachment_id, 'ai_last_model' );
			\WP_CLI::success( sprintf( 'Reset all metadata for attachment %d.', $attachment_id ) );
		}
	}

	/**
	 * Export configuration.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-media export-config > config.json
	 *
	 * @since 1.0.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function export_config( $args, $assoc_args ) {
		$config = array(
			'settings'       => get_option( 'ai_media_seo_settings', array() ),
			'providers'      => get_option( 'ai_media_seo_providers', array() ),
			'fallback_order' => get_option( 'ai_media_seo_fallback_order', array() ),
			'fallback_chains' => get_option( 'ai_media_fallback_chains', array() ),
		);

		// Remove sensitive data.
		foreach ( $config['providers'] as &$provider ) {
			if ( isset( $provider['api_key'] ) ) {
				$provider['api_key'] = '***REDACTED***';
			}
		}

		\WP_CLI::line( wp_json_encode( $config, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Import configuration.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to configuration JSON file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-media import-config config.json
	 *
	 * @since 1.0.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function import_config( $args, $assoc_args ) {
		$file = $args[0] ?? null;

		if ( ! $file || ! file_exists( $file ) ) {
			\WP_CLI::error( 'Configuration file not found.' );
		}

		$json   = file_get_contents( $file );
		$config = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			\WP_CLI::error( 'Invalid JSON file.' );
		}

		if ( isset( $config['settings'] ) ) {
			update_option( 'ai_media_seo_settings', $config['settings'] );
		}

		if ( isset( $config['fallback_order'] ) ) {
			update_option( 'ai_media_seo_fallback_order', $config['fallback_order'] );
		}

		if ( isset( $config['fallback_chains'] ) ) {
			update_option( 'ai_media_fallback_chains', $config['fallback_chains'] );
		}

		\WP_CLI::success( 'Configuration imported. Note: API keys were not imported for security.' );
	}

	/**
	 * Run database migrations.
	 *
	 * Forces database migrations to run, updating schema to the latest version.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-media migrate
	 *
	 * @since 1.1.0
	 */
	public function migrate() {
		\WP_CLI::line( 'Running database migrations...' );

		$current_version = get_option( 'ai_media_seo_db_version', '1.0.0' );
		$target_version  = AI_MEDIA_SEO_DB_VERSION;

		\WP_CLI::line( sprintf( 'Current DB version: %s', $current_version ) );
		\WP_CLI::line( sprintf( 'Target DB version: %s', $target_version ) );

		if ( version_compare( $current_version, $target_version, '>=' ) ) {
			\WP_CLI::success( 'Database is already at the latest version. No migrations needed.' );
			return;
		}

		$migration = new \AIMediaSEO\Database\DatabaseMigration();
		$results   = $migration->run_migrations();

		if ( ! empty( $results['errors'] ) ) {
			\WP_CLI::error( sprintf( 'Migration failed: %s', implode( ', ', $results['errors'] ) ) );
		}

		\WP_CLI::success( sprintf(
			'Migrations completed successfully! Database updated from %s to %s',
			$current_version,
			$target_version
		) );

		// Show details
		if ( ! empty( $results['completed'] ) ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( 'Completed migrations:' );
			foreach ( $results['completed'] as $version ) {
				\WP_CLI::line( sprintf( '  ✓ Version %s', $version ) );
			}
		}

		// Sync pricing data if table is empty
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Checking pricing data...' );

		global $wpdb;
		$table_name = $wpdb->prefix . 'ai_media_pricing';
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		if ( $count > 0 ) {
			\WP_CLI::line( sprintf( 'Pricing table already contains %d models.', $count ) );
		} else {
			\WP_CLI::line( 'Pricing table is empty. Syncing pricing data...' );
			$synchronizer = new \AIMediaSEO\Pricing\PricingSynchronizer();
			$result = $synchronizer->sync_pricing();

			if ( $result['success'] ) {
				\WP_CLI::success( sprintf( 'Pricing sync completed! %d models synced.', $result['models_synced'] ) );
			} else {
				\WP_CLI::warning( sprintf( 'Pricing sync failed: %s', implode( ', ', $result['errors'] ) ) );
			}
		}
	}

	/**
	 * Sync pricing data from CSV.
	 *
	 * Downloads and syncs AI model pricing from external CSV source.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-media sync-pricing
	 *
	 * @since 1.1.0
	 */
	public function sync_pricing() {
		\WP_CLI::line( 'Syncing pricing data from CSV...' );

		$synchronizer = new \AIMediaSEO\Pricing\PricingSynchronizer();
		$result       = $synchronizer->sync_pricing();

		if ( ! $result['success'] ) {
			\WP_CLI::error( sprintf( 'Pricing sync failed: %s', implode( ', ', $result['errors'] ) ) );
		}

		\WP_CLI::success( sprintf(
			'Pricing sync completed! %d models synced.',
			$result['models_synced']
		) );

		// Show synced models
		if ( ! empty( $result['models'] ) ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( 'Synced models:' );
			foreach ( $result['models'] as $model ) {
				\WP_CLI::line( sprintf(
					'  • %s: $%.4f / $%.4f per 1M tokens',
					$model['model_name'],
					$model['input_price_per_million'],
					$model['output_price_per_million']
				) );
			}
		}

		// Show timestamp
		$last_sync = get_option( 'ai_media_pricing_last_sync' );
		if ( $last_sync ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( sprintf( 'Last sync: %s', date( 'Y-m-d H:i:s', $last_sync ) ) );
		}
	}
}
