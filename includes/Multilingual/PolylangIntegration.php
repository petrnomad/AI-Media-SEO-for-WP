<?php
/**
 * Polylang Integration
 *
 * Integration with Polylang multilingual plugin.
 *
 * @package AIMediaSEO
 * @since 1.0.0
 */

namespace AIMediaSEO\Multilingual;

/**
 * Class PolylangIntegration
 *
 * Provides Polylang-specific language operations.
 */
class PolylangIntegration {

	/**
	 * Get all available languages
	 *
	 * @return array Array of language codes
	 */
	public function get_languages() {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}

		return pll_languages_list();
	}

	/**
	 * Get default language
	 *
	 * @return string Language code
	 */
	public function get_default_language() {
		if ( ! function_exists( 'pll_default_language' ) ) {
			$locale = get_locale();
			return substr( $locale, 0, 2 );
		}

		return pll_default_language();
	}

	/**
	 * Get current language
	 *
	 * @return string Current language code
	 */
	public function get_current_language() {
		if ( ! function_exists( 'pll_current_language' ) ) {
			return $this->get_default_language();
		}

		$current = pll_current_language();
		return $current ? $current : $this->get_default_language();
	}

	/**
	 * Get language name
	 *
	 * @param string $language_code Language code.
	 * @return string Language name
	 */
	public function get_language_name( $language_code ) {
		if ( ! function_exists( 'PLL' ) ) {
			return $language_code;
		}

		$pll = PLL();
		if ( ! isset( $pll->model ) || ! method_exists( $pll->model, 'get_language' ) ) {
			return $language_code;
		}

		$language = $pll->model->get_language( $language_code );
		return $language ? $language->name : $language_code;
	}

	/**
	 * Get post language
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Language code or null
	 */
	public function get_post_language( $post_id ) {
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return null;
		}

		$language = pll_get_post_language( $post_id );
		return $language ? $language : null;
	}

	/**
	 * Get post translations
	 *
	 * @param int $post_id Post ID.
	 * @return array Associative array [language_code => post_id]
	 */
	public function get_post_translations( $post_id ) {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return array();
		}

		$translations = pll_get_post_translations( $post_id );
		return is_array( $translations ) ? $translations : array();
	}

	/**
	 * Get attachment language
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null Language code or null
	 */
	public function get_attachment_language( $attachment_id ) {
		// Polylang can assign language to attachments
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return null;
		}

		$language = pll_get_post_language( $attachment_id, 'slug' );
		if ( $language ) {
			return $language;
		}

		// Fallback: Check parent post
		$parent_id = wp_get_post_parent_id( $attachment_id );
		if ( $parent_id ) {
			return pll_get_post_language( $parent_id, 'slug' );
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
		if ( ! function_exists( 'pll_set_post_language' ) ) {
			return false;
		}

		// Validate language exists
		$languages = $this->get_languages();
		if ( ! in_array( $language_code, $languages, true ) ) {
			return false;
		}

		pll_set_post_language( $attachment_id, $language_code );
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
		if ( ! function_exists( 'pll_get_post' ) ) {
			return null;
		}

		$translation_id = pll_get_post( $attachment_id, $language_code );
		return $translation_id ? $translation_id : null;
	}

	/**
	 * Get all translations of attachment
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Associative array [language_code => attachment_id]
	 */
	public function get_attachment_translations( $attachment_id ) {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return array();
		}

		$translations = pll_get_post_translations( $attachment_id );
		return is_array( $translations ) ? $translations : array();
	}

	/**
	 * Link attachments as translations
	 *
	 * @param array $attachments Associative array [language_code => attachment_id].
	 * @return bool Success
	 */
	public function link_attachments( $attachments ) {
		if ( ! function_exists( 'pll_save_post_translations' ) ) {
			return false;
		}

		if ( empty( $attachments ) || ! is_array( $attachments ) ) {
			return false;
		}

		pll_save_post_translations( $attachments );
		return true;
	}

	/**
	 * Check if Polylang Pro is active
	 *
	 * @return bool
	 */
	public function is_pro() {
		return defined( 'POLYLANG_PRO' ) && POLYLANG_PRO;
	}
}
