<?php
/**
 * Context Builder
 *
 * Builds context information for image analysis.
 *
 * @package    AIMediaSEO
 * @subpackage Analyzer
 * @since      1.0.0
 */

namespace AIMediaSEO\Analyzer;

/**
 * ContextBuilder class.
 *
 * Gathers contextual information about images to improve AI analysis.
 *
 * @since 1.0.0
 */
class ContextBuilder {

	/**
	 * Build context for image analysis.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id The attachment ID.
	 * @param string $language      The language code.
	 * @return array Context data.
	 */
	public function build( int $attachment_id, string $language ): array {
		$context = array(
			'attachment_id' => $attachment_id,
			'language'      => $language,
			'site_topic'    => $this->get_site_topic(),
		);

		// Priority order for context sources.
		$sources = array(
			'attached_post',
			'filename_slug',
			'exif_data',
			'image_metadata',
		);

		foreach ( $sources as $source ) {
			$method = 'get_' . $source . '_context';
			if ( method_exists( $this, $method ) ) {
				$source_context = $this->$method( $attachment_id, $language );
				if ( ! empty( $source_context ) ) {
					$context = array_merge( $context, $source_context );
				}
			}
		}

		/**
		 * Filter the built context before analysis.
		 *
		 * @since 1.0.0
		 * @param array  $context       The context data.
		 * @param int    $attachment_id The attachment ID.
		 * @param string $language      The language code.
		 */
		return apply_filters( 'ai_media_context', $context, $attachment_id, $language );
	}

	/**
	 * Get site topic from settings.
	 *
	 * @since 1.0.0
	 * @return string Site topic.
	 */
	private function get_site_topic(): string {
		$settings = get_option( 'ai_media_seo_settings', array() );
		return $settings['site_topic'] ?? get_bloginfo( 'description' );
	}

	/**
	 * Get context from attached post.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id The attachment ID.
	 * @param string $language      The language code.
	 * @return array Post context.
	 */
	private function get_attached_post_context( int $attachment_id, string $language ): array {
		$context = array();

		// Get parent post ID.
		$post_id = wp_get_post_parent_id( $attachment_id );

		if ( ! $post_id ) {
			// Try to find usage in posts.
			$post_id = $this->find_post_usage( $attachment_id );
		}

		if ( $post_id ) {
			$post = get_post( $post_id );

			if ( $post ) {
				$context['post_title'] = get_the_title( $post_id );
				$context['post_excerpt'] = $post->post_excerpt;
				$context['post_type'] = $post->post_type;

				// Get categories.
				$categories = get_the_category( $post_id );
				if ( ! empty( $categories ) ) {
					$context['categories'] = array_map(
						function( $cat ) {
							return $cat->name;
						},
						$categories
					);
				}

				// Get tags.
				$tags = get_the_tags( $post_id );
				if ( ! empty( $tags ) ) {
					$context['tags'] = array_map(
						function( $tag ) {
							return $tag->name;
						},
						$tags
					);
				}
			}
		}

		return $context;
	}

	/**
	 * Find post that uses this attachment.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id The attachment ID.
	 * @return int|null Post ID or null.
	 */
	private function find_post_usage( int $attachment_id ): ?int {
		global $wpdb;

		// Look for post that contains this image.
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_thumbnail_id'
				AND meta_value = %d
				LIMIT 1",
				$attachment_id
			)
		);

		if ( $post_id ) {
			return (int) $post_id;
		}

		// Look in post content.
		$attachment_url = wp_get_attachment_url( $attachment_id );
		if ( $attachment_url ) {
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_content LIKE %s
					AND post_status = 'publish'
					AND post_type IN ('post', 'page')
					LIMIT 1",
					'%' . $wpdb->esc_like( basename( $attachment_url ) ) . '%'
				)
			);

			if ( $post_id ) {
				return (int) $post_id;
			}
		}

		return null;
	}

	/**
	 * Get context from filename.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id The attachment ID.
	 * @param string $language      Not used.
	 * @return array Filename context.
	 */
	private function get_filename_slug_context( int $attachment_id, string $language ): array {
		$context = array();

		$file = get_attached_file( $attachment_id );
		if ( $file ) {
			$filename = basename( $file, '.' . pathinfo( $file, PATHINFO_EXTENSION ) );

			// Convert filename to readable text.
			$readable = str_replace( array( '-', '_' ), ' ', $filename );
			$readable = preg_replace( '/[^a-zA-Z0-9\s]/', '', $readable );
			$readable = trim( $readable );

			if ( strlen( $readable ) > 3 ) {
				$context['filename_hint'] = $readable;
			}
		}

		return $context;
	}

	/**
	 * Get context from EXIF data.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id The attachment ID.
	 * @param string $language      Not used.
	 * @return array EXIF context.
	 */
	private function get_exif_data_context( int $attachment_id, string $language ): array {
		$context = array();

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! empty( $metadata['image_meta'] ) ) {
			$exif = $metadata['image_meta'];

			// Camera info.
			if ( ! empty( $exif['camera'] ) ) {
				$context['camera'] = $exif['camera'];
			}

			// GPS location.
			if ( ! empty( $exif['latitude'] ) && ! empty( $exif['longitude'] ) ) {
				$context['gps_latitude'] = $exif['latitude'];
				$context['gps_longitude'] = $exif['longitude'];

				// Try to get location name (would require geocoding API).
				// For now, just include coordinates.
				$context['location'] = sprintf(
					'GPS: %s, %s',
					$exif['latitude'],
					$exif['longitude']
				);
			}

			// Timestamp.
			if ( ! empty( $exif['created_timestamp'] ) ) {
				$context['photo_date'] = date( 'Y-m-d', $exif['created_timestamp'] );
			}

			// Copyright.
			if ( ! empty( $exif['copyright'] ) ) {
				$context['copyright'] = $exif['copyright'];
			}

			// Title from EXIF.
			if ( ! empty( $exif['title'] ) ) {
				$context['exif_title'] = $exif['title'];
			}

			// Caption from EXIF.
			if ( ! empty( $exif['caption'] ) ) {
				$context['exif_caption'] = $exif['caption'];
			}
		}

		return $context;
	}

	/**
	 * Get context from image metadata.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id The attachment ID.
	 * @param string $language      Not used.
	 * @return array Image metadata context.
	 */
	private function get_image_metadata_context( int $attachment_id, string $language ): array {
		$context = array();

		// Get current alt text if exists.
		$current_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( ! empty( $current_alt ) ) {
			$context['current_alt'] = $current_alt;
		}

		// Get attachment post data.
		$attachment = get_post( $attachment_id );
		if ( $attachment ) {
			if ( ! empty( $attachment->post_title ) ) {
				$context['attachment_title'] = $attachment->post_title;
			}

			if ( ! empty( $attachment->post_excerpt ) ) {
				$context['attachment_caption'] = $attachment->post_excerpt;
			}

			if ( ! empty( $attachment->post_content ) ) {
				$context['attachment_description'] = $attachment->post_content;
			}
		}

		// Get image dimensions.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
			$context['dimensions'] = sprintf(
				'%dx%d',
				$metadata['width'],
				$metadata['height']
			);

			// Determine orientation.
			if ( $metadata['width'] > $metadata['height'] ) {
				$context['orientation'] = 'landscape';
			} elseif ( $metadata['height'] > $metadata['width'] ) {
				$context['orientation'] = 'portrait';
			} else {
				$context['orientation'] = 'square';
			}
		}

		return $context;
	}

	/**
	 * Calculate context quality score.
	 *
	 * Determines how much contextual information is available.
	 *
	 * @since 1.0.0
	 * @param array $context The context data.
	 * @return float Score from 0.0 to 1.0.
	 */
	public function calculate_context_score( array $context ): float {
		$score = 0.0;
		$max_score = 0.0;

		$weights = array(
			'post_title'     => 0.25,
			'categories'     => 0.15,
			'tags'           => 0.10,
			'filename_hint'  => 0.10,
			'exif_data'      => 0.15,
			'current_alt'    => 0.10,
			'site_topic'     => 0.15,
		);

		foreach ( $weights as $key => $weight ) {
			$max_score += $weight;

			if ( isset( $context[ $key ] ) && ! empty( $context[ $key ] ) ) {
				$score += $weight;
			}
		}

		return $max_score > 0 ? ( $score / $max_score ) : 0.0;
	}
}
