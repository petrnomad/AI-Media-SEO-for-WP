<?php
/**
 * Settings REST API Controller
 *
 * Handles settings management operations.
 *
 * @package    AIMediaSEO
 * @subpackage API
 * @since      1.0.0
 */

namespace AIMediaSEO\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * SettingsController class.
 *
 * Provides REST API endpoints for plugin settings.
 *
 * @since 1.0.0
 */
class SettingsController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-media/v1';

	/**
	 * Register routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		// Settings.
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_settings_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_settings_permission' ),
				),
			)
		);

		// User preferences.
		register_rest_route(
			$this->namespace,
			'/user-preferences',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_user_preferences' ),
					'permission_callback' => array( $this, 'check_user_permission' ),
					'args'                => array(
						'hidden_columns' => array(
							'type'              => 'array',
							'sanitize_callback' => array( $this, 'sanitize_hidden_columns' ),
						),
						'per_page'       => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Clear OPCache.
		register_rest_route(
			$this->namespace,
			'/clear-opcache',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'clear_opcache' ),
					'permission_callback' => array( $this, 'check_settings_permission' ),
				),
			)
		);

		// Prompt preview.
		register_rest_route(
			$this->namespace,
			'/settings/prompt-preview',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_prompt_preview' ),
					'permission_callback' => array( $this, 'check_settings_permission' ),
					'args'                => array(
						'variant'  => array(
							'required'          => true,
							'type'              => 'string',
							'enum'              => array( 'minimal', 'standard', 'advanced' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
						'language' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'en',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Detect API tiers.
		register_rest_route(
			$this->namespace,
			'/settings/detect-tiers',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'detect_tiers' ),
					'permission_callback' => array( $this, 'check_settings_permission' ),
					'args'                => array(
						'force_refresh' => array(
							'required'          => false,
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);
	}

	/**
	 * Get settings.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'settings'        => get_option( 'ai_media_seo_settings', array() ),
				'providers'       => get_option( 'ai_media_seo_providers', array() ),
				'quality_rules'   => get_option( 'ai_media_seo_quality_rules', array() ),
				'quality_weights' => get_option( 'ai_media_seo_quality_weights', array(
					'alt'     => 0.40,
					'title'   => 0.30,
					'caption' => 0.20,
					'keywords' => 0.10,
				) ),
			),
			200
		);
	}

	/**
	 * Update settings.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function update_settings( WP_REST_Request $request ) {
		$settings        = $request->get_param( 'settings' );
		$providers       = $request->get_param( 'providers' );
		$quality_rules   = $request->get_param( 'quality_rules' );
		$quality_weights = $request->get_param( 'quality_weights' );

		$updated = array();

		if ( null !== $settings ) {
			$sanitized = $this->sanitize_settings( $settings );
			update_option( 'ai_media_seo_settings', $sanitized );
			$updated['settings'] = $sanitized;
		}

		if ( null !== $providers ) {
			$sanitized = $this->sanitize_providers( $providers );
			update_option( 'ai_media_seo_providers', $sanitized );
			$updated['providers'] = $sanitized;

			// Update fallback order to prioritize the primary provider.
			$this->update_fallback_order( $sanitized );
		}

		if ( null !== $quality_rules ) {
			$sanitized = $this->sanitize_quality_rules( $quality_rules );
			update_option( 'ai_media_seo_quality_rules', $sanitized );
			$updated['quality_rules'] = $sanitized;
		}

		if ( null !== $quality_weights ) {
			$sanitized = $this->sanitize_quality_weights( $quality_weights );
			update_option( 'ai_media_seo_quality_weights', $sanitized );
			$updated['quality_weights'] = $sanitized;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Settings updated successfully.', 'ai-media-seo' ),
				'updated' => $updated,
			),
			200
		);
	}

	/**
	 * Update user preferences.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function update_user_preferences( WP_REST_Request $request ) {
		$user_id        = get_current_user_id();
		$hidden_columns = $request->get_param( 'hidden_columns' );
		$per_page       = $request->get_param( 'per_page' );

		$updated = array();

		// Update hidden columns.
		if ( null !== $hidden_columns ) {
			update_user_meta( $user_id, 'manageai-media_page_ai-media-libraryhidden_columns', $hidden_columns );
			$updated['hidden_columns'] = $hidden_columns;
		}

		// Update per page.
		if ( null !== $per_page ) {
			update_user_meta( $user_id, 'ai_media_per_page', $per_page );
			$updated['per_page'] = $per_page;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Preferences updated successfully.', 'ai-media-seo' ),
				'updated' => $updated,
			),
			200
		);
	}

	/**
	 * Check user permission.
	 *
	 * @since 1.0.0
	 * @return bool True if user is logged in.
	 */
	public function check_user_permission(): bool {
		return is_user_logged_in();
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
	 * Sanitize hidden columns array.
	 *
	 * @since 1.0.0
	 * @param array $columns Array of column names.
	 * @return array Sanitized column names.
	 */
	public function sanitize_hidden_columns( $columns ): array {
		if ( ! is_array( $columns ) ) {
			return array();
		}

		$valid_columns = array( 'file', 'alt', 'caption', 'status', 'attached_to', 'provider', 'score' );
		return array_values( array_intersect( $columns, $valid_columns ) );
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 1.0.0
	 * @param array $input Raw settings input.
	 * @return array Sanitized settings.
	 */
	private function sanitize_settings( $input ): array {
		$sanitized = array();

		if ( isset( $input['lite_daily_limit'] ) ) {
			$sanitized['lite_daily_limit'] = absint( $input['lite_daily_limit'] );
		}


		if ( isset( $input['auto_approve_threshold'] ) ) {
			$sanitized['auto_approve_threshold'] = floatval( $input['auto_approve_threshold'] );
		}

		if ( isset( $input['image_size_for_ai'] ) ) {
			// Validate that the size exists.
			if ( \AIMediaSEO\Utilities\ImageSizeHelper::is_valid_size( $input['image_size_for_ai'] ) ) {
				$sanitized['image_size_for_ai'] = sanitize_text_field( $input['image_size_for_ai'] );
			}
		}

		if ( isset( $input['enable_image_size_fallback'] ) ) {
			$sanitized['enable_image_size_fallback'] = (bool) $input['enable_image_size_fallback'];
		}

		if ( isset( $input['enable_auto_process'] ) ) {
			$sanitized['enable_auto_process'] = (bool) $input['enable_auto_process'];
		}

		if ( isset( $input['primary_language'] ) ) {
			$sanitized['primary_language'] = sanitize_text_field( $input['primary_language'] );
		}

		if ( isset( $input['ai_role'] ) ) {
			$sanitized['ai_role'] = sanitize_text_field( $input['ai_role'] );
		}

		if ( isset( $input['site_context'] ) ) {
			$sanitized['site_context'] = sanitize_textarea_field( $input['site_context'] );
		}

		if ( isset( $input['alt_max_length'] ) ) {
			$sanitized['alt_max_length'] = absint( $input['alt_max_length'] );
		}

		if ( isset( $input['prompt_variant'] ) ) {
			$valid_variants = array( 'minimal', 'standard', 'advanced' );
			$variant        = sanitize_text_field( $input['prompt_variant'] );
			if ( in_array( $variant, $valid_variants, true ) ) {
				$sanitized['prompt_variant'] = $variant;
			} else {
				$sanitized['prompt_variant'] = 'standard'; // Default to standard.
			}
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
	private function sanitize_providers( $input ): array {
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

			if ( isset( $input[ $provider ]['primary'] ) ) {
				$sanitized[ $provider ]['primary'] = (bool) $input[ $provider ]['primary'];
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
	private function sanitize_quality_rules( $input ): array {
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
	 * Sanitize quality weights.
	 *
	 * @since 1.2.0
	 * @param array $input Raw quality weights.
	 * @return array Sanitized and normalized quality weights.
	 */
	private function sanitize_quality_weights( $input ): array {
		$sanitized    = array();
		$valid_fields = array( 'alt', 'title', 'caption', 'keywords' );

		// Sanitize each field (ensure float between 0.0 and 1.0).
		foreach ( $valid_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$weight              = floatval( $input[ $field ] );
				$sanitized[ $field ] = max( 0.0, min( 1.0, $weight ) );
			}
		}

		// Ensure all fields are present with defaults if missing.
		$defaults = array(
			'alt'     => 0.40,
			'title'   => 0.30,
			'caption' => 0.20,
			'keywords' => 0.10,
		);

		foreach ( $defaults as $field => $default_value ) {
			if ( ! isset( $sanitized[ $field ] ) ) {
				$sanitized[ $field ] = $default_value;
			}
		}

		// Validate that weights sum to 1.0 (100%).
		$total = array_sum( $sanitized );
		if ( abs( $total - 1.0 ) > 0.01 ) {
			throw new \Exception(
				sprintf(
					'Quality weights must total 100%%. Current total: %d%%',
					round( $total * 100 )
				)
			);
		}

		return $sanitized;
	}

	/**
	 * Update fallback order based on primary provider.
	 *
	 * @since 1.0.0
	 * @param array $providers Sanitized provider settings.
	 */
	private function update_fallback_order( array $providers ): void {
		$primary_provider = null;
		$other_providers = array();

		// Find primary provider and collect others.
		foreach ( array( 'openai', 'anthropic', 'google' ) as $provider_name ) {
			if ( ! empty( $providers[ $provider_name ]['primary'] ) ) {
				$primary_provider = $provider_name;
			} else {
				$other_providers[] = $provider_name;
			}
		}

		// Build new fallback order: primary first, then others.
		$fallback_order = array();
		if ( $primary_provider ) {
			$fallback_order[] = $primary_provider;
		}
		$fallback_order = array_merge( $fallback_order, $other_providers );

		// Update fallback order option.
		update_option( 'ai_media_seo_fallback_order', $fallback_order );
	}

	/**
	 * Clear PHP OPCache.
	 *
	 * Clears PHP OPCache to ensure new code changes take effect immediately.
	 * Useful after plugin updates or code modifications.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function clear_opcache( WP_REST_Request $request ): WP_REST_Response {
		$cleared = false;
		$message = '';

		// Check if OPCache is enabled and available.
		if ( function_exists( 'opcache_reset' ) ) {
			// Get status before clearing.
			$status_before = opcache_get_status();
			$scripts_before = $status_before ? $status_before['opcache_statistics']['num_cached_scripts'] : 0;

			// Clear OPCache.
			$cleared = opcache_reset();

			if ( $cleared ) {
				$message = sprintf(
					'OPCache cleared successfully! %d cached scripts were removed.',
					$scripts_before
				);
			} else {
				$message = 'Failed to clear OPCache. Check server permissions.';
			}
		} else {
			$message = 'OPCache is not enabled on this server.';
		}

		return new WP_REST_Response(
			array(
				'success' => $cleared,
				'message' => $message,
			),
			200
		);
	}

	/**
	 * Get prompt preview for settings page.
	 *
	 * Builds a real prompt using actual PromptBuilder classes with sample context.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_prompt_preview( WP_REST_Request $request ): WP_REST_Response {
		$variant  = $request->get_param( 'variant' );
		$language = $request->get_param( 'language' ) ?? 'en';

		// Get current settings.
		$settings = get_option( 'ai_media_seo_settings', array() );

		// Create sample context for preview.
		$sample_context = array(
			'post_title'             => '[Post Title]',
			'post_excerpt'           => '[Post Excerpt]',
			'categories'             => array( '[Category 1]', '[Category 2]' ),
			'tags'                   => array( '[Tag 1]', '[Tag 2]', '[Tag 3]' ),
			'post_type'              => 'post',
			'filename_hint'          => '[filename-hint]',
			'orientation'            => '[orientation]',
			'dimensions'             => '[dimensions]',
			'current_alt'            => '[Existing ALT]',
			'attachment_title'       => '[Attachment Title]',
			'attachment_caption'     => '[Author Caption]',
			'attachment_description' => '[Attachment Description]',
			'exif_title'             => '[EXIF Title]',
			'exif_caption'           => '[EXIF Caption]',
			'camera'                 => '[Camera Model]',
			'photo_date'             => '[Photo Date]',
			'location'               => '[GPS Location]',
			'copyright'              => '[Copyright Info]',
		);

		// Select appropriate builder.
		switch ( $variant ) {
			case 'minimal':
				$builder = new \AIMediaSEO\Prompts\MinimalPromptBuilder();
				break;
			case 'advanced':
				$builder = new \AIMediaSEO\Prompts\AdvancedPromptBuilder();
				break;
			case 'standard':
			default:
				$builder = new \AIMediaSEO\Prompts\StandardPromptBuilder();
				break;
		}

		// Build prompt using real PromptBuilder.
		$prompt = $builder->build( $language, $sample_context, $settings );

		return new WP_REST_Response(
			array(
				'success' => true,
				'variant' => $variant,
				'language' => $language,
				'prompt' => $prompt,
			),
			200
		);
	}

	/**
	 * Detect API tiers for all configured providers.
	 *
	 * @since 2.2.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function detect_tiers( WP_REST_Request $request ): WP_REST_Response {
		$force_refresh = $request->get_param( 'force_refresh' ) ?? false;

		if ( $force_refresh ) {
			// Clear cache for all providers.
			$providers = array( 'openai', 'anthropic', 'google' );
			foreach ( $providers as $provider ) {
				\AIMediaSEO\Providers\TierDetector::refresh_tier_cache( $provider );
			}
		}

		$tiers = \AIMediaSEO\Providers\TierDetector::detect_all_tiers();

		// Přidat doporučení pro každý provider.
		foreach ( $tiers as $provider => &$tier_info ) {
			$tier_info['recommended_concurrency'] = \AIMediaSEO\Providers\TierDetector::get_recommended_concurrency(
				$tier_info['tier'],
				$tier_info['rpm']
			);
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'tiers'       => $tiers,
				'detected_at' => current_time( 'mysql' ),
			),
			200
		);
	}
}
