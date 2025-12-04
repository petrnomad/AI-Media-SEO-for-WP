<?php
/**
 * WPML Integration
 *
 * Integration with WPML multilingual plugin.
 *
 * @package AIMediaSEO
 * @since 1.0.0
 */

namespace AIMediaSEO\Multilingual;

/**
 * Class WPMLIntegration
 *
 * Provides WPML-specific language operations.
 */
class WPMLIntegration {

	/**
	 * Get SitePress instance
	 *
	 * @return object|null SitePress instance or null
	 */
	private function get_sitepress() {
		global $sitepress;
		return $sitepress;
	}

	/**
	 * Get all available languages
	 *
	 * @return array Array of language codes
	 */
	public function get_languages() {
		$sitepress = $this->get_sitepress();

		if ( ! $sitepress ) {
			return array();
		}

		$languages = $sitepress->get_active_languages();

		if ( empty( $languages ) ) {
			return array();
		}

		return array_keys( $languages );
	}

	/**
	 * Get default language
	 *
	 * @return string Language code
	 */
	public function get_default_language() {
		$sitepress = $this->get_sitepress();

		if ( ! $sitepress ) {
			$locale = get_locale();
			return substr( $locale, 0, 2 );
		}

		return $sitepress->get_default_language();
	}

	/**
	 * Get current language
	 *
	 * @return string Current language code
	 */
	public function get_current_language() {
		$sitepress = $this->get_sitepress();

		if ( ! $sitepress ) {
			return $this->get_default_language();
		}

		$current = $sitepress->get_current_language();
		return $current ? $current : $this->get_default_language();
	}

	/**
	 * Get language name
	 *
	 * @param string $language_code Language code.
	 * @return string Language name
	 */
	public function get_language_name( $language_code ) {
		$sitepress = $this->get_sitepress();

		if ( ! $sitepress ) {
			return $language_code;
		}

		$languages = $sitepress->get_active_languages();

		if ( isset( $languages[ $language_code ] ) ) {
			return $languages[ $language_code ]['display_name'];
		}

		return $language_code;
	}

	/**
	 * Get post language
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Language code or null
	 */
	public function get_post_language( $post_id ) {
		if ( ! function_exists( 'wpml_get_language_information' ) ) {
			return null;
		}

		$lang_info = wpml_get_language_information( $post_id );

		if ( is_wp_error( $lang_info ) ) {
			return null;
		}

		return isset( $lang_info['language_code'] ) ? $lang_info['language_code'] : null;
	}

	/**
	 * Get post translations
	 *
	 * @param int $post_id Post ID.
	 * @return array Associative array [language_code => post_id]
	 */
	public function get_post_translations( $post_id ) {
		$sitepress = $this->get_sitepress();

		if ( ! $sitepress ) {
			return array();
		}

		$trid = $sitepress->get_element_trid( $post_id, 'post_' . get_post_type( $post_id ) );

		if ( ! $trid ) {
			return array();
		}

		$translations = $sitepress->get_element_translations( $trid, 'post_' . get_post_type( $post_id ) );

		if ( empty( $translations ) ) {
			return array();
		}

		$result = array();
		foreach ( $translations as $translation ) {
			if ( isset( $translation->element_id ) && isset( $translation->language_code ) ) {
				$result[ $translation->language_code ] = (int) $translation->element_id;
			}
		}

		return $result;
	}

	/**
	 * Get attachment language
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null Language code or null
	 */
	public function get_attachment_language( $attachment_id ) {
		// WPML can assign language to attachments
		$language = $this->get_post_language( $attachment_id );

		if ( $language ) {
			return $language;
		}

		// Fallback: Check parent post
		$parent_id = wp_get_post_parent_id( $attachment_id );
		if ( $parent_id ) {
			return $this->get_post_language( $parent_id );
		}

		return null;
	}

	/**
	 * Set attachment language
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language_code Language code.
	 * @return bool Success
	 */
	public function set_attachment_language( $attachment_id, $language_code ) {
		$sitepress = $this->get_sitepress();

		if ( ! $sitepress ) {
			return false;
		}

		// Validate language exists
		$languages = $this->get_languages();
		if ( ! in_array( $language_code, $languages, true ) ) {
			return false;
		}

		// Set language for attachment
		$sitepress->set_element_language_details(
			$attachment_id,
			'post_attachment',
			null,
			$language_code
		);

		return true;
	}

	/**
	 * Get translation of attachment
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language_code Target language code.
	 * @return int|null Translated attachment ID or null
	 */
	public function get_attachment_translation( $attachment_id, $language_code ) {
		if ( ! function_exists( 'icl_object_id' ) ) {
			return null;
		}

		$translation_id = icl_object_id( $attachment_id, 'attachment', false, $language_code );
		return $translation_id ? (int) $translation_id : null;
	}

	/**
	 * Get all translations of attachment
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Associative array [language_code => attachment_id]
	 */
	public function get_attachment_translations( $attachment_id ) {
		$sitepress = $this->get_sitepress();

		if ( ! $sitepress ) {
			return array();
		}

		$trid = $sitepress->get_element_trid( $attachment_id, 'post_attachment' );

		if ( ! $trid ) {
			return array();
		}

		$translations = $sitepress->get_element_translations( $trid, 'post_attachment' );

		if ( empty( $translations ) ) {
			return array();
		}

		$result = array();
		foreach ( $translations as $translation ) {
			if ( isset( $translation->element_id ) && isset( $translation->language_code ) ) {
				$result[ $translation->language_code ] = (int) $translation->element_id;
			}
		}

		return $result;
	}

	/**
	 * Link attachments as translations
	 *
	 * @param array $attachments Associative array [language_code => attachment_id].
	 * @return bool Success
	 */
	public function link_attachments( $attachments ) {
		$sitepress = $this->get_sitepress();

		if ( ! $sitepress || empty( $attachments ) || ! is_array( $attachments ) ) {
			return false;
		}

		// Get or create trid
		$trid = null;
		foreach ( $attachments as $lang => $id ) {
			$existing_trid = $sitepress->get_element_trid( $id, 'post_attachment' );
			if ( $existing_trid ) {
				$trid = $existing_trid;
				break;
			}
		}

		// Link all attachments
		foreach ( $attachments as $lang => $id ) {
			$sitepress->set_element_language_details(
				$id,
				'post_attachment',
				$trid,
				$lang
			);
		}

		return true;
	}

	/**
	 * Check if WPML Media is active
	 *
	 * @return bool
	 */
	public function has_media_addon() {
		return defined( 'WPML_MEDIA_VERSION' );
	}
}
