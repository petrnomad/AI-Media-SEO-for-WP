<?php
/**
 * Image Size Helper
 *
 * Helper class for working with WordPress image sizes.
 *
 * @package    AIMediaSEO
 * @subpackage Utilities
 * @since      1.6.0
 */

namespace AIMediaSEO\Utilities;

/**
 * ImageSizeHelper class.
 *
 * Provides helper methods for working with WordPress image sizes.
 *
 * @since 1.6.0
 */
class ImageSizeHelper {

	/**
	 * Get all available WordPress image sizes with their dimensions.
	 *
	 * @since 1.6.0
	 * @return array Array of image sizes with dimensions.
	 */
	public static function get_available_sizes(): array {
		$sizes = array();

		// Get all registered image size names.
		$size_names = get_intermediate_image_sizes();

		// Always include 'full' size.
		if ( ! in_array( 'full', $size_names, true ) ) {
			$size_names[] = 'full';
		}

		foreach ( $size_names as $size_name ) {
			$size_data = self::get_size_data( $size_name );

			if ( $size_data ) {
				$sizes[ $size_name ] = $size_data;
			}
		}

		return $sizes;
	}

	/**
	 * Get size data for a specific size name.
	 *
	 * @since 1.6.0
	 * @param string $size_name Size name.
	 * @return array|false Size data or false.
	 */
	private static function get_size_data( string $size_name ) {
		// Handle 'full' size specially.
		if ( $size_name === 'full' ) {
			return array(
				'width'  => 0,
				'height' => 0,
				'crop'   => false,
			);
		}

		// Get dimensions from options for default sizes.
		$width  = (int) get_option( $size_name . '_size_w', 0 );
		$height = (int) get_option( $size_name . '_size_h', 0 );
		$crop   = (bool) get_option( $size_name . '_crop', false );

		// If width and height are both 0, this might be a custom size.
		// Try to get it from global $_wp_additional_image_sizes.
		if ( $width === 0 && $height === 0 ) {
			global $_wp_additional_image_sizes;

			if ( isset( $_wp_additional_image_sizes[ $size_name ] ) ) {
				$width  = (int) $_wp_additional_image_sizes[ $size_name ]['width'];
				$height = (int) $_wp_additional_image_sizes[ $size_name ]['height'];
				$crop   = (bool) $_wp_additional_image_sizes[ $size_name ]['crop'];
			}
		}

		// Skip sizes that have no dimensions defined.
		if ( $width === 0 && $height === 0 ) {
			return false;
		}

		return array(
			'width'  => $width,
			'height' => $height,
			'crop'   => $crop,
		);
	}

	/**
	 * Get formatted label for an image size.
	 *
	 * @since 1.6.0
	 * @param string $size_name Size name.
	 * @return string Formatted label (e.g., "Large (1024 x 1024)").
	 */
	public static function get_size_label( string $size_name ): string {
		// Capitalize and format the name.
		$label = ucwords( str_replace( array( '_', '-' ), ' ', $size_name ) );

		// Get dimensions.
		$size_data = self::get_size_data( $size_name );

		if ( ! $size_data ) {
			return $label;
		}

		// Handle 'full' size.
		if ( $size_name === 'full' ) {
			return $label . ' (Original)';
		}

		// Format dimensions.
		$width  = $size_data['width'];
		$height = $size_data['height'];

		if ( $width > 0 && $height > 0 ) {
			$dimensions = $width . ' x ' . $height;
		} elseif ( $width > 0 ) {
			$dimensions = 'Max width: ' . $width . 'px';
		} elseif ( $height > 0 ) {
			$dimensions = 'Max height: ' . $height . 'px';
		} else {
			$dimensions = 'Variable';
		}

		return $label . ' (' . $dimensions . ')';
	}

	/**
	 * Get available sizes formatted for JavaScript.
	 *
	 * @since 1.6.0
	 * @return array Array of sizes formatted for wp_localize_script.
	 */
	public static function get_available_sizes_for_js(): array {
		$sizes     = self::get_available_sizes();
		$formatted = array();

		foreach ( $sizes as $size_name => $size_data ) {
			$formatted[] = array(
				'value'  => $size_name,
				'label'  => self::get_size_label( $size_name ),
				'width'  => $size_data['width'],
				'height' => $size_data['height'],
			);
		}

		return $formatted;
	}

	/**
	 * Get image URL in the size configured for AI analysis.
	 *
	 * @since 1.6.0
	 * @param int $attachment_id Attachment ID.
	 * @return string|false Image URL or false on failure.
	 */
	public static function get_image_url_for_ai( int $attachment_id ) {
		$settings         = get_option( 'ai_media_seo_settings', array() );
		$image_size       = $settings['image_size_for_ai'] ?? 'large';
		$enable_fallback  = $settings['enable_image_size_fallback'] ?? true;

		// Try the selected size first.
		$image_url = wp_get_attachment_image_url( $attachment_id, $image_size );

		if ( $image_url ) {
			return $image_url;
		}

		// If fallback is not enabled, return the full size.
		if ( ! $enable_fallback ) {
			return wp_get_attachment_url( $attachment_id );
		}

		// Fallback: try other standard sizes in order.
		$fallback_sizes = array( 'large', 'medium_large', 'medium', 'thumbnail' );

		foreach ( $fallback_sizes as $fallback_size ) {
			// Skip if we already tried this size.
			if ( $fallback_size === $image_size ) {
				continue;
			}

			$image_url = wp_get_attachment_image_url( $attachment_id, $fallback_size );

			if ( $image_url ) {
				return $image_url;
			}
		}

		// Last fallback: use full (original image).
		return wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Validate if a size name exists.
	 *
	 * @since 1.6.0
	 * @param string $size_name Size name to validate.
	 * @return bool True if size exists, false otherwise.
	 */
	public static function is_valid_size( string $size_name ): bool {
		if ( $size_name === 'full' ) {
			return true;
		}

		$size_names = get_intermediate_image_sizes();

		return in_array( $size_name, $size_names, true );
	}

	/**
	 * Get image URL for AI analysis with AVIF handling.
	 *
	 * Automatically detects AVIF and creates temporary JPEG if needed.
	 *
	 * @since 1.7.0
	 * @param int $attachment_id Attachment ID.
	 * @return array {
	 *     @type string      $url       Image URL to use for AI.
	 *     @type bool        $is_temp   Whether this is a temporary file.
	 *     @type string|null $temp_path Full path to temp file (for cleanup).
	 * }
	 */
	public static function get_image_url_for_ai_with_avif( int $attachment_id ): array {
		// Kontrola zda je AVIF.
		if ( AvifConverter::is_avif( $attachment_id ) ) {
			// Check AVIF support first.
			$support = AvifConverter::check_avif_support();

			if ( ! $support['supported'] ) {
				// AVIF not supported - throw clear error.
				error_log( 'AI Media SEO: AVIF conversion not supported on this server. Library: ' . $support['library'] );
				throw new \Exception(
					esc_html( __( 'AVIF format detected but server does not support AVIF conversion. Please install GD with AVIF support or use ImageMagick with AVIF support.', 'ai-media-seo' ) )
				);
			}

			// Získat nastavení pro image size.
			$settings   = get_option( 'ai_media_seo_settings', array() );
			$image_size = $settings['image_size_for_ai'] ?? 'large';

			// Pokus o konverzi AVIF → JPEG.
			$temp_data = AvifConverter::create_temp_jpeg( $attachment_id, $image_size );

			if ( $temp_data !== false ) {
				// AVIF konverze úspěšná.
				return array(
					'url'       => $temp_data['temp_url'],
					'is_temp'   => true,
					'temp_path' => $temp_data['temp_path'],
				);
			}

			// AVIF konverze selhala - throw error instead of returning AVIF URL.
			error_log( 'AI Media SEO: AVIF temp file creation failed for attachment ' . $attachment_id );
			throw new \Exception(
				esc_html( __( 'Failed to convert AVIF image to JPEG. The image could not be processed.', 'ai-media-seo' ) )
			);
		}

		// Není AVIF - normální flow.
		$normal_url = self::get_image_url_for_ai( $attachment_id );
		return array(
			'url'       => $normal_url,
			'is_temp'   => false,
			'temp_path' => null,
		);
	}
}
