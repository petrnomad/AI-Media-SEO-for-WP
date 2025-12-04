<?php
/**
 * Image Data Helper
 *
 * Provides utilities for fetching and encoding image data for AI analysis.
 *
 * @package    AIMediaSEO
 * @subpackage Utilities
 * @since      1.8.0
 */

namespace AIMediaSEO\Utilities;

/**
 * ImageDataHelper class.
 *
 * Centralizes image data fetching and base64 encoding logic.
 * Previously duplicated in AnthropicProvider and GoogleProvider.
 *
 * @since 1.8.0
 */
class ImageDataHelper {

	/**
	 * Get image as base64 encoded string with AVIF handling.
	 *
	 * This method was previously duplicated in AnthropicProvider and GoogleProvider.
	 * Now centralized here for reusability.
	 *
	 * @since 1.8.0
	 * @param string $image_url      Image URL to fetch.
	 * @param string $mime_type_key  Key name for MIME type in return array ('media_type' or 'mime_type').
	 * @param string $provider_name  Provider name for error messages (e.g., 'Anthropic', 'Google').
	 * @return array|false Array with 'base64' and mime_type_key, or false on failure.
	 * @throws \Exception When AVIF conversion fails.
	 */
	public static function get_image_base64( string $image_url, string $mime_type_key = 'media_type', string $provider_name = 'API' ) {
		// Check for local temp file - read directly from filesystem.
		$upload_dir = wp_upload_dir();

		if ( strpos( $image_url, $upload_dir['baseurl'] . '/ai-media-temp/' ) === 0 ) {
			// Local temp file - read directly.
			$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $image_url );

			if ( file_exists( $file_path ) ) {
				$image_data = file_get_contents( $file_path );

				if ( $image_data !== false ) {
					return array(
						'base64'         => base64_encode( $image_data ),
						$mime_type_key   => 'image/jpeg',
					);
				}
			}
		}

		// Standard remote fetch for other cases.
		$response = wp_remote_get( $image_url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$image_data = wp_remote_retrieve_body( $response );
		$mime_type  = wp_remote_retrieve_header( $response, 'content-type' );

		if ( empty( $image_data ) ) {
			return false;
		}

		// Check if MIME type is AVIF - most APIs don't support it.
		// If AVIF is detected, try to convert to JPEG in memory.
		if ( $mime_type === 'image/avif' || strpos( $mime_type, 'avif' ) !== false ) {
			$converted = AvifConverter::convert_avif_data_to_jpeg( $image_data );

			if ( $converted !== false ) {
				return array(
					'base64'       => base64_encode( $converted ),
					$mime_type_key => 'image/jpeg',
				);
			}

			// translators: %s is the provider name (e.g., Anthropic, Google)
			throw new \Exception( sprintf( __( 'Failed to analyze image: %s API does not support AVIF format and conversion failed. Please use JPEG, PNG or WebP.', 'ai-media-seo' ), $provider_name ) );
		}

		return array(
			'base64'       => base64_encode( $image_data ),
			$mime_type_key => $mime_type,
		);
	}
}
