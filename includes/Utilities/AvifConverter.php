<?php
/**
 * AVIF to JPEG Converter
 *
 * Handles conversion of AVIF images to JPEG for AI analysis.
 *
 * @package    AIMediaSEO
 * @subpackage Utilities
 * @since      1.7.0
 */

namespace AIMediaSEO\Utilities;

/**
 * AvifConverter class.
 *
 * Provides utilities for detecting and converting AVIF images to JPEG format.
 *
 * @since 1.7.0
 */
class AvifConverter {

	/**
	 * Detekce zda je attachment AVIF formát
	 *
	 * @since 1.7.0
	 * @param int $attachment_id Attachment ID.
	 * @return bool True if AVIF, false otherwise.
	 */
	public static function is_avif( int $attachment_id ): bool {
		$mime_type = get_post_mime_type( $attachment_id );

		// Check MIME type first (correct way).
		if ( $mime_type === 'image/avif' ) {
			return true;
		}

		// Fallback: Check file extension (WordPress sometimes gets MIME type wrong).
		$file_path = get_attached_file( $attachment_id );
		if ( $file_path ) {
			$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
			return ( $extension === 'avif' );
		}

		return false;
	}

	/**
	 * Vytvoření dočasného JPEG z AVIF
	 *
	 * @since 1.7.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size WordPress image size (large, medium, etc.).
	 * @return array|false Array with temp_path and temp_url, or false on failure.
	 */
	public static function create_temp_jpeg( int $attachment_id, string $size = 'large' ) {
		$support = self::check_avif_support();
		if ( ! $support['supported'] ) {
			error_log( "AI Media SEO: AVIF support check failed. Library: {$support['library']}" );
			return false;
		}

		$avif_path = self::get_attachment_path( $attachment_id, $size );
		if ( ! $avif_path ) {
			error_log( "AI Media SEO: Could not get attachment path for ID {$attachment_id}, size {$size}" );
			return false;
		}

		if ( ! file_exists( $avif_path ) ) {
			error_log( "AI Media SEO: AVIF file does not exist at path: {$avif_path}" );
			return false;
		}

		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/ai-media-temp/';

		if ( ! is_dir( $temp_dir ) ) {
			$created = wp_mkdir_p( $temp_dir );
			if ( ! $created ) {
				error_log( "AI Media SEO: Failed to create temp directory: {$temp_dir}" );
				return false;
			}
		}

		$timestamp     = time();
		$temp_filename = "avif-temp-{$attachment_id}-{$timestamp}.jpg";
		$temp_path     = $temp_dir . $temp_filename;
		$temp_url      = $upload_dir['baseurl'] . '/ai-media-temp/' . $temp_filename;

		try {
			$result = self::convert_avif_to_jpeg( $avif_path, $temp_path );

			if ( is_wp_error( $result ) ) {
				error_log( 'AI Media SEO: convert_avif_to_jpeg returned WP_Error: ' . $result->get_error_message() );
				return false;
			}

			if ( ! file_exists( $temp_path ) ) {
				error_log( "AI Media SEO: Temp JPEG file was not created at: {$temp_path}" );
				return false;
			}

			return array(
				'temp_path' => $temp_path,
				'temp_url'  => $temp_url,
			);

		} catch ( \Exception $e ) {
			error_log( 'AI Media SEO: Exception in create_temp_jpeg: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Smazání dočasného JPEG souboru
	 *
	 * @since 1.7.0
	 * @param string $temp_path Path to temporary file.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_temp_jpeg( string $temp_path ): bool {
		if ( empty( $temp_path ) || ! file_exists( $temp_path ) ) {
			return false;
		}

		// Bezpečnostní kontrola - jen temp soubory.
		if ( strpos( $temp_path, '/ai-media-temp/' ) === false ) {
			return false;
		}

		return @unlink( $temp_path );
	}

	/**
	 * Kontrola zda server podporuje AVIF konverzi
	 *
	 * @since 1.7.0
	 * @return array Array with 'supported' (bool) and 'library' (string: gd|imagick|none).
	 */
	public static function check_avif_support(): array {
		if ( function_exists( 'imagecreatefromavif' ) && function_exists( 'imagejpeg' ) ) {
			return array(
				'supported' => true,
				'library'   => 'gd',
			);
		}

		if ( class_exists( 'Imagick' ) ) {
			try {
				$imagick = new \Imagick();
				$formats = $imagick->queryFormats( 'AVIF' );
				if ( ! empty( $formats ) ) {
					return array(
						'supported' => true,
						'library'   => 'imagick',
					);
				}
			} catch ( \Exception $e ) {
				// Imagick check failed, continue to return not supported.
			}
		}

		return array(
			'supported' => false,
			'library'   => 'none',
		);
	}

	/**
	 * Konverze AVIF na JPEG pomocí wp_get_image_editor
	 *
	 * @since 1.7.0
	 * @param string $source_path Cesta k AVIF souboru.
	 * @param string $dest_path Cesta k výslednému JPEG.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	private static function convert_avif_to_jpeg( string $source_path, string $dest_path ) {
		$editor = wp_get_image_editor( $source_path );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$saved = $editor->save( $dest_path, 'image/jpeg' );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( ! file_exists( $dest_path ) ) {
			if ( isset( $saved['path'] ) && file_exists( $saved['path'] ) ) {
				if ( copy( $saved['path'], $dest_path ) ) {
					unlink( $saved['path'] );
				} else {
					return new \WP_Error( 'copy_failed', 'Could not copy file to expected location' );
				}
			} else {
				return new \WP_Error( 'file_not_created', 'JPEG file was not created' );
			}
		}

		return true;
	}

	/**
	 * Získat cestu k attachment souboru
	 *
	 * @since 1.7.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size Image size.
	 * @return string|false File path or false on failure.
	 */
	private static function get_attachment_path( int $attachment_id, string $size = 'large' ) {
		// Zkusit získat konkrétní velikost.
		$image_url = wp_get_attachment_image_url( $attachment_id, $size );

		if ( ! $image_url ) {
			// Fallback na full size.
			$image_url = wp_get_attachment_url( $attachment_id );
		}

		if ( ! $image_url ) {
			return false;
		}

		// Konverze URL na path.
		$upload_dir = wp_upload_dir();
		$file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $image_url );

		return $file_path;
	}

	/**
	 * Convert AVIF image binary data to JPEG binary data.
	 *
	 * This method was previously duplicated in AnthropicProvider and GoogleProvider.
	 * Now centralized here for reusability.
	 *
	 * @since 1.8.0
	 * @param string $avif_data AVIF image binary data.
	 * @return string|false JPEG image binary data or false on failure.
	 */
	public static function convert_avif_data_to_jpeg( string $avif_data ) {
		$support = self::check_avif_support();

		if ( ! $support['supported'] ) {
			error_log( "AI Media SEO: convert_avif_data_to_jpeg failed - AVIF not supported. Library: {$support['library']}" );
			return false;
		}

		// Save AVIF data to temp file for wp_get_image_editor.
		$temp_avif = wp_tempnam( 'avif-source-' );
		if ( ! $temp_avif ) {
			error_log( 'AI Media SEO: Failed to create temp AVIF file' );
			return false;
		}

		$written = file_put_contents( $temp_avif, $avif_data );
		if ( $written === false ) {
			error_log( 'AI Media SEO: Failed to write AVIF data to temp file' );
			return false;
		}

		try {
			$editor = wp_get_image_editor( $temp_avif );

			if ( is_wp_error( $editor ) ) {
				error_log( 'AI Media SEO: wp_get_image_editor failed: ' . $editor->get_error_message() );
				unlink( $temp_avif );
				return false;
			}

			$temp_jpeg = wp_tempnam( 'avif-converted-' ) . '.jpg';
			$saved     = $editor->save( $temp_jpeg, 'image/jpeg' );

			if ( is_wp_error( $saved ) ) {
				error_log( 'AI Media SEO: Image editor save failed: ' . $saved->get_error_message() );
				unlink( $temp_avif );
				return false;
			}

			// Check if file exists where we expect it.
			if ( ! file_exists( $temp_jpeg ) ) {
				// Try to get actual saved path from return value.
				$actual_path = isset( $saved['path'] ) ? $saved['path'] : null;
				if ( $actual_path && file_exists( $actual_path ) ) {
					$temp_jpeg = $actual_path;
				} else {
					error_log( "AI Media SEO: Converted JPEG file not found at {$temp_jpeg}" );
					unlink( $temp_avif );
					return false;
				}
			}

			// Read JPEG data.
			$jpeg_data = file_get_contents( $temp_jpeg );

			if ( $jpeg_data === false ) {
				error_log( "AI Media SEO: Failed to read converted JPEG data from {$temp_jpeg}" );
				unlink( $temp_avif );
				if ( file_exists( $temp_jpeg ) ) {
					unlink( $temp_jpeg );
				}
				return false;
			}

			// Cleanup temp files.
			unlink( $temp_avif );
			if ( file_exists( $temp_jpeg ) ) {
				unlink( $temp_jpeg );
			}

			return $jpeg_data;

		} catch ( \Exception $e ) {
			error_log( 'AI Media SEO: Exception in convert_avif_data_to_jpeg: ' . $e->getMessage() );
			if ( file_exists( $temp_avif ) ) {
				unlink( $temp_avif );
			}
			return false;
		}
	}

	/**
	 * Cleanup starých temporary souborů
	 *
	 * @since 1.7.0
	 * @param int $max_age Maximum age in seconds (default 3600 = 1 hour).
	 * @return int Number of deleted files.
	 */
	public static function cleanup_old_temp_files( int $max_age = 3600 ): int {
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/ai-media-temp/';

		if ( ! is_dir( $temp_dir ) ) {
			return 0;
		}

		$deleted = 0;
		$files   = glob( $temp_dir . '*.jpg' );

		if ( empty( $files ) ) {
			return 0;
		}

		foreach ( $files as $file ) {
			if ( filemtime( $file ) < time() - $max_age ) {
				if ( @unlink( $file ) ) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}
}
