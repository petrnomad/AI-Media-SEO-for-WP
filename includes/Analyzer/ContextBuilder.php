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
	 * Uses waterfall approach with multiple fallback strategies to find parent post:
	 * 1. Direct parent (wp_get_post_parent_id) - fastest
	 * 2. Polylang translations API - reliable for Polylang copies
	 * 3. Filename matching - universal fallback for duplicates
	 * 4. Post usage search - existing fallback for featured images
	 * 5. Language translation - convert parent to target language
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id The attachment ID.
	 * @param string $language      The language code for context.
	 * @return array Post context.
	 */
	private function get_attached_post_context( int $attachment_id, string $language ): array {
		$post_id = null;

		// STEP 1: Direct parent (fastest).
		$post_id = wp_get_post_parent_id( $attachment_id );

		// STEP 2: Polylang API (reliable for Polylang).
		if ( ! $post_id && function_exists( 'pll_get_post_translations' ) ) {
			$parent_id = $this->find_parent_via_polylang_api( $attachment_id );
			if ( $parent_id ) {
				$post_id = $parent_id;
			}
		}

		// STEP 3: Filename matching (universal fallback).
		if ( ! $post_id ) {
			$original_id = $this->find_original_attachment_by_filename( $attachment_id );
			if ( $original_id ) {
				$post_id = wp_get_post_parent_id( $original_id );
			}
		}

		// STEP 4: Search usage (existing fallback).
		if ( ! $post_id ) {
			$post_id = $this->find_post_usage( $attachment_id );
		}

		// STEP 5: Get translated version of parent post.
		if ( $post_id && function_exists( 'pll_get_post' ) ) {
			$translated_post = pll_get_post( $post_id, $language );
			if ( $translated_post ) {
				$post_id = $translated_post;
			}
		}

		// Extract context from final post ID with target language context.
		return $this->extract_post_context( $post_id, $language );
	}

	/**
	 * Extract context information from a post ID.
	 *
	 * Extracts post title, excerpt, type, categories, and tags.
	 * This method is used by multiple parent detection strategies.
	 *
	 * IMPORTANT: If Polylang is active, temporarily switches to target language
	 * to ensure get_the_title(), get_the_category(), etc. return correct translations.
	 *
	 * @since 1.0.0
	 * @param int|null    $post_id  The post ID, or null if no post found.
	 * @param string|null $language Target language code for Polylang context (optional).
	 * @return array Post context data (empty array if no post).
	 */
	private function extract_post_context( ?int $post_id, ?string $language = null ): array {
		$context = array();

		if ( ! $post_id ) {
			return $context;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $context;
		}

		// CRITICAL FIX: Temporarily switch Polylang context to target language.
		// This ensures get_the_title(), get_the_category(), get_the_tags() return
		// data in the CORRECT language, not the admin language.
		$original_lang = null;
		if ( $language && function_exists( 'pll_current_language' ) ) {
			$original_lang = pll_current_language();

			// Switch to target language using Polylang filter.
			add_filter( 'pll_current_language', function() use ( $language ) {
				return $language;
			}, 999 );
		}

		// Post basic info.
		$context['post_title']   = get_the_title( $post_id );
		$context['post_excerpt'] = $post->post_excerpt;
		$context['post_type']    = $post->post_type;

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

		// Restore original Polylang context.
		if ( $original_lang !== null ) {
			remove_all_filters( 'pll_current_language', 999 );
		}

		return $context;
	}

	/**
	 * Find parent post via Polylang translations API.
	 *
	 * When Polylang creates language copies of attachments, the copy may not have
	 * a parent post (post_parent = 0), but its translation might. This method
	 * checks all language versions to find one with a parent post.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID (may be a copy without parent).
	 * @return int|null Parent post ID or null if none found.
	 */
	private function find_parent_via_polylang_api( int $attachment_id ): ?int {
		// Check if Polylang is available.
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return null;
		}

		// Get all language versions of this attachment.
		$translations = pll_get_post_translations( $attachment_id );

		if ( empty( $translations ) || ! is_array( $translations ) ) {
			return null;
		}

		// Check each translation for a parent post.
		foreach ( $translations as $lang_code => $translation_id ) {
			if ( ! $translation_id ) {
				continue;
			}

			$parent_id = wp_get_post_parent_id( $translation_id );

			if ( $parent_id ) {
				// Found a translation with a parent post.
				return $parent_id;
			}
		}

		// No translation has a parent post.
		return null;
	}

	/**
	 * Find original attachment by matching filename.
	 *
	 * Polylang copies often share the same _wp_attached_file meta value.
	 * This method finds other attachments with the same file and returns
	 * one that has a parent post.
	 *
	 * This is a universal fallback that works even without Polylang,
	 * useful for manually duplicated media or other multilingual plugins.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID.
	 * @return int|null Original attachment ID with parent, or null if none found.
	 */
	private function find_original_attachment_by_filename( int $attachment_id ): ?int {
		// Get the attached file path for this attachment.
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( empty( $attached_file ) ) {
			return null;
		}

		global $wpdb;

		// Find other attachments with the same file.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attached_file'
				AND meta_value = %s
				AND post_id != %d",
				$attached_file,
				$attachment_id
			)
		);

		if ( empty( $attachment_ids ) ) {
			return null;
		}

		// Check each attachment for a parent post.
		foreach ( $attachment_ids as $other_id ) {
			$parent_id = wp_get_post_parent_id( (int) $other_id );

			if ( $parent_id ) {
				// Found an attachment with a parent post.
				return (int) $other_id;
			}
		}

		// No attachment with this filename has a parent.
		return null;
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
				$context['photo_date'] = gmdate( 'Y-m-d', $exif['created_timestamp'] );
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
	 * Bulk build contexts for multiple attachments.
	 *
	 * Optimized version that prefetches all data in batch queries.
	 * Reduces 3-5 SELECT queries per image to 3-5 total queries for entire batch.
	 *
	 * @since 2.2.0 FÁZE 2.2: Context Prefetching
	 * @param array  $attachment_ids Array of attachment IDs.
	 * @param string $language       Language code.
	 * @return array Array of [attachment_id => context].
	 */
	public function bulk_build_contexts( array $attachment_ids, string $language ): array {
		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$batch_store = new \AIMediaSEO\Storage\BatchMetadataStore();
		$contexts    = array();

		// Initialize contexts with basic info.
		$site_topic = $this->get_site_topic();
		foreach ( $attachment_ids as $attachment_id ) {
			$contexts[ $attachment_id ] = array(
				'attachment_id' => $attachment_id,
				'language'      => $language,
				'site_topic'    => $site_topic,
			);
		}

		// Prefetch all parent post IDs.
		global $wpdb;
		$ids_list  = implode( ',', array_map( 'intval', $attachment_ids ) );
		$parent_map = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$parents = $wpdb->get_results(
			"SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN ({$ids_list})",
			ARRAY_A
		);

		foreach ( $parents as $row ) {
			$attachment_id            = (int) $row['ID'];
			$parent_id                = (int) $row['post_parent'];
			$parent_map[ $attachment_id ] = $parent_id ?: null;
		}

		// Collect unique parent post IDs for bulk fetching.
		$parent_post_ids = array_filter( array_unique( array_values( $parent_map ) ) );

		if ( ! empty( $parent_post_ids ) ) {
			// Bulk fetch parent posts.
			$parent_posts = $batch_store->bulk_get_posts( $parent_post_ids );

			// Bulk fetch categories and tags.
			$categories_map = $batch_store->bulk_get_term_relationships( $parent_post_ids, 'category' );
			$tags_map       = $batch_store->bulk_get_term_relationships( $parent_post_ids, 'post_tag' );

			// Build post context for each attachment.
			foreach ( $parent_map as $attachment_id => $parent_id ) {
				if ( ! $parent_id || ! isset( $parent_posts[ $parent_id ] ) ) {
					continue;
				}

				$post = $parent_posts[ $parent_id ];

				// Add post context.
				$contexts[ $attachment_id ]['post_title']   = $post->post_title;
				$contexts[ $attachment_id ]['post_excerpt'] = $post->post_excerpt;
				$contexts[ $attachment_id ]['post_type']    = $post->post_type;

				// Add categories.
				if ( isset( $categories_map[ $parent_id ] ) ) {
					$contexts[ $attachment_id ]['categories'] = array_map(
						function( $cat ) {
							return $cat['name'];
						},
						$categories_map[ $parent_id ]
					);
				}

				// Add tags.
				if ( isset( $tags_map[ $parent_id ] ) ) {
					$contexts[ $attachment_id ]['tags'] = array_map(
						function( $tag ) {
							return $tag['name'];
						},
						$tags_map[ $parent_id ]
					);
				}
			}
		}

		// Bulk fetch attachment metadata.
		$attachment_posts = $batch_store->bulk_get_posts( $attachment_ids );
		$attachment_meta  = $batch_store->bulk_get_post_meta(
			$attachment_ids,
			array(
				'_wp_attachment_image_alt',
				'_wp_attached_file',
				'_wp_attachment_metadata',
			)
		);

		// Add filename, EXIF, and image metadata context.
		foreach ( $attachment_ids as $attachment_id ) {
			// Filename context.
			if ( isset( $attachment_meta[ $attachment_id ]['_wp_attached_file'] ) ) {
				$file     = $attachment_meta[ $attachment_id ]['_wp_attached_file'];
				$filename = basename( $file, '.' . pathinfo( $file, PATHINFO_EXTENSION ) );

				// Convert filename to readable text.
				$readable = str_replace( array( '-', '_' ), ' ', $filename );
				$readable = preg_replace( '/[^a-zA-Z0-9\s]/', '', $readable );
				$readable = trim( $readable );

				if ( strlen( $readable ) > 3 ) {
					$contexts[ $attachment_id ]['filename_hint'] = $readable;
				}
			}

			// Current ALT text.
			if ( isset( $attachment_meta[ $attachment_id ]['_wp_attachment_image_alt'] ) ) {
				$contexts[ $attachment_id ]['current_alt'] = $attachment_meta[ $attachment_id ]['_wp_attachment_image_alt'];
			}

			// Attachment post data.
			if ( isset( $attachment_posts[ $attachment_id ] ) ) {
				$attachment = $attachment_posts[ $attachment_id ];

				if ( ! empty( $attachment->post_title ) ) {
					$contexts[ $attachment_id ]['attachment_title'] = $attachment->post_title;
				}

				if ( ! empty( $attachment->post_excerpt ) ) {
					$contexts[ $attachment_id ]['attachment_caption'] = $attachment->post_excerpt;
				}

				if ( ! empty( $attachment->post_content ) ) {
					$contexts[ $attachment_id ]['attachment_description'] = $attachment->post_content;
				}
			}

			// EXIF and image metadata.
			if ( isset( $attachment_meta[ $attachment_id ]['_wp_attachment_metadata'] ) ) {
				$metadata = maybe_unserialize( $attachment_meta[ $attachment_id ]['_wp_attachment_metadata'] );

				// Image dimensions.
				if ( ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
					$contexts[ $attachment_id ]['dimensions'] = sprintf(
						'%dx%d',
						$metadata['width'],
						$metadata['height']
					);

					// Orientation.
					if ( $metadata['width'] > $metadata['height'] ) {
						$contexts[ $attachment_id ]['orientation'] = 'landscape';
					} elseif ( $metadata['height'] > $metadata['width'] ) {
						$contexts[ $attachment_id ]['orientation'] = 'portrait';
					} else {
						$contexts[ $attachment_id ]['orientation'] = 'square';
					}
				}

				// EXIF data.
				if ( ! empty( $metadata['image_meta'] ) ) {
					$exif = $metadata['image_meta'];

					if ( ! empty( $exif['camera'] ) ) {
						$contexts[ $attachment_id ]['camera'] = $exif['camera'];
					}

					if ( ! empty( $exif['latitude'] ) && ! empty( $exif['longitude'] ) ) {
						$contexts[ $attachment_id ]['gps_latitude']  = $exif['latitude'];
						$contexts[ $attachment_id ]['gps_longitude'] = $exif['longitude'];
						$contexts[ $attachment_id ]['location']      = sprintf(
							'GPS: %s, %s',
							$exif['latitude'],
							$exif['longitude']
						);
					}

					if ( ! empty( $exif['created_timestamp'] ) ) {
						$contexts[ $attachment_id ]['photo_date'] = gmdate( 'Y-m-d', $exif['created_timestamp'] );
					}

					if ( ! empty( $exif['copyright'] ) ) {
						$contexts[ $attachment_id ]['copyright'] = $exif['copyright'];
					}

					if ( ! empty( $exif['title'] ) ) {
						$contexts[ $attachment_id ]['exif_title'] = $exif['title'];
					}

					if ( ! empty( $exif['caption'] ) ) {
						$contexts[ $attachment_id ]['exif_caption'] = $exif['caption'];
					}
				}
			}

			/**
			 * Filter the built context before analysis.
			 *
			 * @since 1.0.0
			 * @since 2.2.0 Added bulk context building support.
			 * @param array  $context       The context data.
			 * @param int    $attachment_id The attachment ID.
			 * @param string $language      The language code.
			 */
			$contexts[ $attachment_id ] = apply_filters(
				'ai_media_context',
				$contexts[ $attachment_id ],
				$attachment_id,
				$language
			);
		}

		return $contexts;
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
