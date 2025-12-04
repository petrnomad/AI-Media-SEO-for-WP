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
				'settings'      => get_option( 'ai_media_seo_settings', array() ),
				'providers'     => get_option( 'ai_media_seo_providers', array() ),
				'quality_rules' => get_option( 'ai_media_seo_quality_rules', array() ),
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
		$settings      = $request->get_param( 'settings' );
		$providers     = $request->get_param( 'providers' );
		$quality_rules = $request->get_param( 'quality_rules' );

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

		if ( isset( $input['ai_role'] ) ) {
			$sanitized['ai_role'] = sanitize_text_field( $input['ai_role'] );
		}

		if ( isset( $input['site_context'] ) ) {
			$sanitized['site_context'] = sanitize_textarea_field( $input['site_context'] );
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
}
