<?php
/**
 * Settings Admin Page
 *
 * Settings page for AI Media SEO plugin.
 *
 * @package    AIMediaSEO
 * @subpackage Admin
 * @since      1.0.0
 */

namespace AIMediaSEO\Admin;

use AIMediaSEO\Providers\ProviderFactory;

/**
 * Settings class.
 *
 * Handles the settings admin page.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Provider factory instance.
	 *
	 * @var ProviderFactory
	 */
	private $provider_factory;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->provider_factory = new ProviderFactory();
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add settings submenu page.
	 *
	 * @since 1.0.0
	 */
	public function add_submenu_page() {
		add_submenu_page(
			'ai-media-seo',
			__( 'Settings', 'ai-media-seo' ),
			__( 'Settings', 'ai-media-seo' ),
			'manage_options',
			'ai-media-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @since 1.0.0
	 */
	public function register_settings() {
		register_setting(
			'ai_media_seo_settings',
			'ai_media_seo_settings',
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'show_in_rest'      => true,
				'default'           => array(),
			)
		);

		register_setting(
			'ai_media_seo_providers',
			'ai_media_seo_providers',
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitize_providers' ),
				'show_in_rest'      => true,
				'default'           => array(),
			)
		);

		register_setting(
			'ai_media_seo_quality_rules',
			'ai_media_seo_quality_rules',
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitize_quality_rules' ),
				'show_in_rest'      => true,
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 1.0.0
	 * @param array $input Raw settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		if ( isset( $input['batch_size'] ) ) {
			$sanitized['batch_size'] = absint( $input['batch_size'] );
		}

		if ( isset( $input['max_concurrent'] ) ) {
			$sanitized['max_concurrent'] = absint( $input['max_concurrent'] );
		}

		if ( isset( $input['rate_limit_rpm'] ) ) {
			$sanitized['rate_limit_rpm'] = absint( $input['rate_limit_rpm'] );
		}

		if ( isset( $input['auto_approve_threshold'] ) ) {
			$sanitized['auto_approve_threshold'] = floatval( $input['auto_approve_threshold'] );
		}

		if ( isset( $input['max_image_size'] ) ) {
			$sanitized['max_image_size'] = absint( $input['max_image_size'] );
		}

		if ( isset( $input['enable_auto_process'] ) ) {
			$sanitized['enable_auto_process'] = (bool) $input['enable_auto_process'];
		}

		if ( isset( $input['primary_language'] ) ) {
			$sanitized['primary_language'] = sanitize_text_field( $input['primary_language'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize provider settings.
	 *
	 * @since 1.0.0
	 * @param array $input Raw provider settings.
	 * @return array Sanitized provider settings.
	 */
	public function sanitize_providers( $input ) {
		$sanitized = array();

		foreach ( array( 'openai', 'anthropic', 'google' ) as $provider ) {
			if ( ! isset( $input[ $provider ] ) ) {
				continue;
			}

			$sanitized[ $provider ] = array();

			if ( isset( $input[ $provider ]['api_key'] ) ) {
				$sanitized[ $provider ]['api_key'] = sanitize_text_field( $input[ $provider ]['api_key'] );
			}

			if ( isset( $input[ $provider ]['model'] ) ) {
				$sanitized[ $provider ]['model'] = sanitize_text_field( $input[ $provider ]['model'] );
			}

			if ( isset( $input[ $provider ]['enabled'] ) ) {
				$sanitized[ $provider ]['enabled'] = (bool) $input[ $provider ]['enabled'];
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize quality rules.
	 *
	 * @since 1.0.0
	 * @param array $input Raw quality rules.
	 * @return array Sanitized quality rules.
	 */
	public function sanitize_quality_rules( $input ) {
		$sanitized = array();

		if ( isset( $input['forbidden_alt_phrases'] ) && is_array( $input['forbidden_alt_phrases'] ) ) {
			$sanitized['forbidden_alt_phrases'] = array_map( 'sanitize_text_field', $input['forbidden_alt_phrases'] );
		}

		if ( isset( $input['require_descriptive'] ) ) {
			$sanitized['require_descriptive'] = (bool) $input['require_descriptive'];
		}

		if ( isset( $input['min_score'] ) ) {
			$sanitized['min_score'] = floatval( $input['min_score'] );
		}

		return $sanitized;
	}

	/**
	 * Enqueue assets.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'ai-media_page_ai-media-settings' !== $hook ) {
			return;
		}

		// Enqueue React bundle.
		$asset_file = AI_MEDIA_SEO_PATH . 'admin/build/settings.asset.php';
		if ( file_exists( $asset_file ) ) {
			$asset = include $asset_file;

			wp_enqueue_script(
				'ai-media-seo-settings',
				AI_MEDIA_SEO_URL . 'admin/build/settings.js',
				$asset['dependencies'] ?? array( 'wp-element', 'wp-api-fetch', 'wp-components' ),
				$asset['version'] ?? AI_MEDIA_SEO_VERSION,
				true
			);

			wp_enqueue_style(
				'ai-media-seo-settings',
				AI_MEDIA_SEO_URL . 'admin/build/settings.css',
				array(),
				$asset['version'] ?? AI_MEDIA_SEO_VERSION
			);
		} else {
			// Fallback: development mode.
			wp_enqueue_script(
				'ai-media-seo-settings',
				AI_MEDIA_SEO_URL . 'admin/src/settings.jsx',
				array( 'wp-element', 'wp-api-fetch', 'wp-components' ),
				AI_MEDIA_SEO_VERSION,
				true
			);

			wp_enqueue_style(
				'ai-media-seo-settings',
				AI_MEDIA_SEO_URL . 'admin/css/settings.css',
				array(),
				AI_MEDIA_SEO_VERSION
			);
		}

		// Localize script data.
		wp_localize_script(
			'ai-media-seo-settings',
			'aiMediaSEO',
			array(
				'apiUrl'             => rest_url( 'ai-media/v1' ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'settings'           => get_option( 'ai_media_seo_settings', array() ),
				'providers'          => get_option( 'ai_media_seo_providers', array() ),
				'quality_rules'      => get_option( 'ai_media_seo_quality_rules', array() ),
				'available_providers' => ProviderFactory::get_available_provider_names(),
				'provider_models'    => $this->get_provider_models(),
				'isPro'              => true, // Always true in freemium version
			)
		);
	}

	/**
	 * Get provider models with pricing.
	 *
	 * @since 1.0.0
	 * @return array Provider models with pricing keyed by provider name.
	 */
	private function get_provider_models() {
		global $wpdb;

		$models = array();

		// Fetch models from pricing table.
		$pricing_table = $wpdb->prefix . 'ai_media_pricing';
		$results       = $wpdb->get_results(
			"SELECT provider, model_name, input_price_per_million, output_price_per_million
			FROM {$pricing_table}
			ORDER BY provider, input_price_per_million ASC",
			ARRAY_A
		);

		if ( ! $results ) {
			// Fallback to hardcoded models if database is empty.
			foreach ( ProviderFactory::get_available_provider_names() as $provider ) {
				$models[ $provider ] = ProviderFactory::get_provider_models( $provider );
			}
			return $models;
		}

		// Group models by provider with pricing info.
		foreach ( $results as $row ) {
			$provider   = $row['provider'];
			$model_name = $row['model_name'];
			$input_price  = floatval( $row['input_price_per_million'] );
			$output_price = floatval( $row['output_price_per_million'] );

			if ( ! isset( $models[ $provider ] ) ) {
				$models[ $provider ] = array();
			}

			// Format: "model-id" => "Model Name ($input/$output per 1M tokens)"
			$models[ $provider ][ $model_name ] = sprintf(
				'%s ($%.2f/$%.2f per 1M)',
				$model_name,
				$input_price,
				$output_price
			);
		}

		return $models;
	}

	/**
	 * Render page.
	 *
	 * @since 1.0.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai-media-seo' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Media SEO Settings', 'ai-media-seo' ); ?></h1>
			<div id="ai-media-settings-root"></div>
		</div>
		<?php
	}

}
