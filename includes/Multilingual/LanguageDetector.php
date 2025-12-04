<?php
/**
 * Language Detector
 *
 * Detects active multilingual plugin and provides unified language interface.
 *
 * @package AIMediaSEO
 * @since 1.0.0
 */

namespace AIMediaSEO\Multilingual;

/**
 * Class LanguageDetector
 *
 * Detects Polylang or WPML and provides unified language access.
 */
class LanguageDetector {

	/**
	 * Active multilingual plugin
	 *
	 * @var string|null 'polylang', 'wpml', or null
	 */
	private $active_plugin = null;

	/**
	 * Integration instance
	 *
	 * @var PolylangIntegration|WPMLIntegration|null
	 */
	private $integration = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->detect_plugin();
		$this->initialize_integration();
	}

	/**
	 * Detect active multilingual plugin
	 *
	 * @return void
	 */
	private function detect_plugin() {
		// Check for Polylang
		if ( function_exists( 'pll_languages_list' ) || defined( 'POLYLANG_VERSION' ) ) {
			$this->active_plugin = 'polylang';
			return;
		}

		// Check for WPML
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' ) ) {
			$this->active_plugin = 'wpml';
			return;
		}

		// No multilingual plugin detected
		$this->active_plugin = null;
	}

	/**
	 * Initialize integration instance
	 *
	 * @return void
	 */
	private function initialize_integration() {
		if ( 'polylang' === $this->active_plugin ) {
			$this->integration = new PolylangIntegration();
		} elseif ( 'wpml' === $this->active_plugin ) {
			$this->integration = new WPMLIntegration();
		}
	}

	/**
	 * Check if multilingual plugin is active
	 *
	 * @return bool
	 */
	public function is_multilingual_active() {
		return null !== $this->active_plugin;
	}

	/**
	 * Get active multilingual plugin name
	 *
	 * @return string|null 'polylang', 'wpml', or null
	 */
	public function get_active_plugin() {
		return $this->active_plugin;
	}

	/**
	 * Get all available languages
	 *
	 * @return array Array of language codes ['en', 'cs', 'de']
	 */
	public function get_languages() {
		if ( ! $this->is_multilingual_active() ) {
			return array( $this->get_default_language() );
		}

		return $this->integration->get_languages();
	}

	/**
	 * Get default language
	 *
	 * @return string Language code (e.g., 'en', 'cs')
	 */
	public function get_default_language() {
		if ( ! $this->is_multilingual_active() ) {
			// Get WordPress locale and extract language code
			$locale = get_locale();
			return substr( $locale, 0, 2 );
		}

		return $this->integration->get_default_language();
	}

	/**
	 * Get current language
	 *
	 * @return string Current language code
	 */
	public function get_current_language() {
		if ( ! $this->is_multilingual_active() ) {
			return $this->get_default_language();
		}

		return $this->integration->get_current_language();
	}

	/**
	 * Get language name
	 *
	 * @param string $language_code Language code.
	 * @return string Language name
	 */
	public function get_language_name( $language_code ) {
		if ( ! $this->is_multilingual_active() ) {
			return $language_code;
		}

		return $this->integration->get_language_name( $language_code );
	}

	/**
	 * Get post language
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Language code or null if not found
	 */
	public function get_post_language( $post_id ) {
		if ( ! $this->is_multilingual_active() ) {
			return $this->get_default_language();
		}

		return $this->integration->get_post_language( $post_id );
	}

	/**
	 * Get translations of a post
	 *
	 * @param int $post_id Post ID.
	 * @return array Associative array [language_code => post_id]
	 */
	public function get_post_translations( $post_id ) {
		if ( ! $this->is_multilingual_active() ) {
			return array( $this->get_default_language() => $post_id );
		}

		return $this->integration->get_post_translations( $post_id );
	}

	/**
	 * Get language for attachment
	 *
	 * Determines language based on attached post or explicit assignment
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Language code
	 */
	public function get_attachment_language( $attachment_id ) {
		if ( ! $this->is_multilingual_active() ) {
			return $this->get_default_language();
		}

		// Try to get language from multilingual plugin
		$language = $this->integration->get_attachment_language( $attachment_id );

		if ( $language ) {
			return $language;
		}

		// Fallback: Get language from parent post
		$parent_id = wp_get_post_parent_id( $attachment_id );
		if ( $parent_id ) {
			return $this->get_post_language( $parent_id );
		}

		// Fallback: Default language
		return $this->get_default_language();
	}

	/**
	 * Set attachment language
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language_code Language code.
	 * @return bool Success
	 */
	public function set_attachment_language( $attachment_id, $language_code ) {
		if ( ! $this->is_multilingual_active() ) {
			return false;
		}

		return $this->integration->set_attachment_language( $attachment_id, $language_code );
	}

	/**
	 * Check if language code is valid
	 *
	 * @param string $language_code Language code to validate.
	 * @return bool
	 */
	public function is_valid_language( $language_code ) {
		$languages = $this->get_languages();
		return in_array( $language_code, $languages, true );
	}
}
