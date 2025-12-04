<?php
/**
 * Post Content Updater
 *
 * Automatically updates ALT text in post content when attachment metadata changes.
 *
 * @package    AIMediaSEO
 * @subpackage Admin
 * @since      1.0.0
 */

namespace AIMediaSEO\Admin;

/**
 * PostContentUpdater class.
 *
 * Handles automatic update of image ALT text in post content
 * when attachment metadata is changed in Media Library.
 *
 * @since 1.0.0
 */
class PostContentUpdater {

	/**
	 * Register hooks.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		// Hook into ALT text updates.
		add_action( 'updated_post_meta', array( $this, 'on_alt_text_updated' ), 10, 4 );
	}

	/**
	 * Handle ALT text update.
	 *
	 * Triggered when _wp_attachment_image_alt is updated.
	 *
	 * @since 1.0.0
	 * @param int    $meta_id    Meta ID.
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New meta value.
	 */
	public function on_alt_text_updated( $meta_id, $attachment_id, $meta_key, $meta_value ): void {
		// Only process ALT text updates for attachments.
		if ( '_wp_attachment_image_alt' !== $meta_key ) {
			return;
		}

		// Verify this is an attachment.
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return;
		}

		// Get new ALT text (sanitized).
		$new_alt = sanitize_text_field( $meta_value );

		// Find all posts using this attachment.
		$posts = $this->find_posts_using_attachment( $attachment_id );

		if ( empty( $posts ) ) {
			return;
		}

		// Update ALT text in each post.
		foreach ( $posts as $post_id ) {
			$this->update_alt_in_post( $post_id, $attachment_id, $new_alt );
		}
	}

	/**
	 * Get supported post types for ALT text updates.
	 *
	 * @since 1.0.0
	 * @return array Array of post type names.
	 */
	private function get_supported_post_types(): array {
		// Get all public post types (except attachments).
		$post_types = get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			),
			'names'
		);

		// Remove attachment from list.
		unset( $post_types['attachment'] );

		// Default: post, page, and all custom post types.
		$default_post_types = array_values( $post_types );

		/**
		 * Filter: Allow customization of post types to update.
		 *
		 * @since 1.0.0
		 * @param array $post_types Array of post type names.
		 */
		return apply_filters( 'ai_media_post_content_updater_post_types', $default_post_types );
	}

	/**
	 * Find all posts using a specific attachment.
	 *
	 * Searches in post_content for image tags referencing this attachment.
	 * Supports posts, pages, and all custom post types.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID.
	 * @return array Array of post IDs.
	 */
	private function find_posts_using_attachment( int $attachment_id ): array {
		global $wpdb;

		// Get attachment URL and filename for searching.
		$attachment_url = wp_get_attachment_url( $attachment_id );
		$attachment_filename = basename( $attachment_url );

		if ( ! $attachment_url ) {
			return array();
		}

		// Get supported post types (posts, pages, custom post types).
		$post_types = $this->get_supported_post_types();

		if ( empty( $post_types ) ) {
			return array();
		}

		// Create placeholders for IN clause.
		$post_type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// Search for posts containing this image.
		// Search by:
		// 1. WordPress block with attachment ID: wp:image {"id":123}
		// 2. Image filename in content
		// 3. wp-image-{id} class

		$query = $wpdb->prepare(
			"SELECT DISTINCT ID FROM {$wpdb->posts}
			WHERE post_type IN ($post_type_placeholders)
			AND post_status IN ('publish', 'draft', 'future', 'pending', 'private')
			AND (
				post_content LIKE %s
				OR post_content LIKE %s
				OR post_content LIKE %s
			)",
			array_merge(
				$post_types,
				array(
					'%"id":' . $attachment_id . '%',          // Block editor format
					'%' . $wpdb->esc_like( $attachment_filename ) . '%',  // Filename in URL
					'%wp-image-' . $attachment_id . '%'        // WordPress image class
				)
			)
		);

		$results = $wpdb->get_col( $query );

		return array_map( 'intval', $results );
	}

	/**
	 * Update ALT text in post content.
	 *
	 * Updates ALT attribute for all <img> tags referencing this attachment.
	 *
	 * @since 1.0.0
	 * @param int    $post_id       Post ID.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $new_alt       New ALT text.
	 * @return bool True if updated, false otherwise.
	 */
	private function update_alt_in_post( int $post_id, int $attachment_id, string $new_alt ): bool {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		$content = $post->post_content;
		$updated = false;

		// 1. Update Gutenberg blocks (wp:image with id)
		$content = preg_replace_callback(
			'/<!-- wp:image {"id":' . $attachment_id . '[^>]*-->.*?<!-- \/wp:image -->/s',
			function ( $matches ) use ( $new_alt, &$updated ) {
				$block = $matches[0];
				// Update alt in img tag within block
				$block = preg_replace(
					'/(<img[^>]*)\salt="[^"]*"/',
					'$1 alt="' . esc_attr( $new_alt ) . '"',
					$block
				);
				// Also add alt if it doesn't exist
				$block = preg_replace(
					'/(<img(?![^>]*\salt=)[^>]*)(\/?>)/',
					'$1 alt="' . esc_attr( $new_alt ) . '"$2',
					$block
				);
				$updated = true;
				return $block;
			},
			$content
		);

		// 2. Update classic editor images with wp-image-{id} class
		$content = preg_replace_callback(
			'/(<img[^>]*class="[^"]*wp-image-' . $attachment_id . '[^"]*"[^>]*)(\/?>)/i',
			function ( $matches ) use ( $new_alt, &$updated ) {
				$img_tag = $matches[1];
				$closing = $matches[2];

				// Check if alt attribute exists
				if ( preg_match( '/\salt="[^"]*"/', $img_tag ) ) {
					// Update existing alt
					$img_tag = preg_replace(
						'/\salt="[^"]*"/',
						' alt="' . esc_attr( $new_alt ) . '"',
						$img_tag
					);
				} else {
					// Add alt attribute
					$img_tag .= ' alt="' . esc_attr( $new_alt ) . '"';
				}

				$updated = true;
				return $img_tag . $closing;
			},
			$content
		);

		// 3. Update images by filename (as fallback)
		$attachment_url = wp_get_attachment_url( $attachment_id );
		$attachment_filename = basename( $attachment_url );

		if ( $attachment_filename ) {
			$content = preg_replace_callback(
				'/(<img[^>]*src="[^"]*' . preg_quote( $attachment_filename, '/' ) . '[^"]*"[^>]*)(\/?>)/i',
				function ( $matches ) use ( $new_alt, &$updated ) {
					$img_tag = $matches[1];
					$closing = $matches[2];

					// Check if alt attribute exists
					if ( preg_match( '/\salt="[^"]*"/', $img_tag ) ) {
						// Update existing alt
						$img_tag = preg_replace(
							'/\salt="[^"]*"/',
							' alt="' . esc_attr( $new_alt ) . '"',
							$img_tag
						);
					} else {
						// Add alt attribute
						$img_tag .= ' alt="' . esc_attr( $new_alt ) . '"';
					}

					$updated = true;
					return $img_tag . $closing;
				},
				$content
			);
		}

		// Only update if changes were made.
		if ( $updated ) {
			// Use wp_update_post to update content.
			// Remove hook temporarily to avoid infinite loops.
			remove_action( 'updated_post_meta', array( $this, 'on_alt_text_updated' ), 10 );

			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
				),
				false // Don't trigger wp_error
			);

			// Re-add hook.
			add_action( 'updated_post_meta', array( $this, 'on_alt_text_updated' ), 10, 4 );

			return true;
		}

		return false;
	}

	/**
	 * Manually update ALT text for all posts using specific attachment.
	 *
	 * Useful for bulk updates or manual fixes.
	 *
	 * @since 1.0.0
	 * @param int $attachment_id Attachment ID.
	 * @return array {
	 *     @type int   $updated Count of updated posts.
	 *     @type array $post_ids Array of updated post IDs.
	 * }
	 */
	public function update_attachment_in_all_posts( int $attachment_id ): array {
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		if ( empty( $alt_text ) ) {
			return array(
				'updated'  => 0,
				'post_ids' => array(),
			);
		}

		$posts = $this->find_posts_using_attachment( $attachment_id );
		$updated_posts = array();

		foreach ( $posts as $post_id ) {
			if ( $this->update_alt_in_post( $post_id, $attachment_id, $alt_text ) ) {
				$updated_posts[] = $post_id;
			}
		}

		return array(
			'updated'  => count( $updated_posts ),
			'post_ids' => $updated_posts,
		);
	}

	/**
	 * Bulk update all attachments with AI-generated ALT text.
	 *
	 * Updates all posts using attachments that have AI-generated ALT text.
	 *
	 * @since 1.0.0
	 * @param int $limit Maximum number of attachments to process (default: 100).
	 * @return array {
	 *     @type int $attachments_processed Count of processed attachments.
	 *     @type int $posts_updated Count of updated posts.
	 * }
	 */
	public function bulk_update_all_posts( int $limit = 100 ): array {
		global $wpdb;

		// Find attachments with AI-generated ALT text.
		$attachments = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_ai_media_status'
				AND meta_value IN ('approved', 'processed')
				LIMIT %d",
				$limit
			)
		);

		$total_attachments = 0;
		$total_posts = 0;

		foreach ( $attachments as $attachment_id ) {
			$result = $this->update_attachment_in_all_posts( (int) $attachment_id );
			$total_attachments++;
			$total_posts += $result['updated'];
		}

		return array(
			'attachments_processed' => $total_attachments,
			'posts_updated'         => $total_posts,
		);
	}
}
