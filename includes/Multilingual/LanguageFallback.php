<?php
/**
 * Language Fallback
 *
 * Handles fallback logic when translations are not available.
 *
 * @package AIMediaSEO
 * @since 1.0.0
 */

namespace AIMediaSEO\Multilingual;

/**
 * Class LanguageFallback
 *
 * Manages fallback chains for missing translations.
 */
class LanguageFallback {

	/**
	 * Language detector instance
	 *
	 * @var LanguageDetector
	 */
	private $detector;

	/**
	 * Fallback chains configuration
	 *
	 * @var array
	 */
	private $fallback_chains = array();

	/**
	 * Constructor
	 *
	 * @param LanguageDetector $detector Language detector instance.
	 */
	public function __construct( LanguageDetector $detector ) {
		$this->detector = $detector;
		$this->load_fallback_chains();
	}

	/**
	 * Load fallback chains from options
	 *
	 * @return void
	 */
	private function load_fallback_chains() {
		$saved_chains = get_option( 'ai_media_fallback_chains', array() );

		// Default fallback chains
		$default_chains = array(
			'cs' => array( 'sk', 'en' ), // Czech -> Slovak -> English
			'sk' => array( 'cs', 'en' ), // Slovak -> Czech -> English
			'de' => array( 'en' ),       // German -> English
			'fr' => array( 'en' ),       // French -> English
			'es' => array( 'en' ),       // Spanish -> English
			'it' => array( 'en' ),       // Italian -> English
			'pl' => array( 'en' ),       // Polish -> English
			'ru' => array( 'en' ),       // Russian -> English
		);

		$this->fallback_chains = wp_parse_args( $saved_chains, $default_chains );
	}

	/**
	 * Get fallback chain for language
	 *
	 * @param string $language_code Language code.
	 * @return array Fallback language codes
	 */
	public function get_fallback_chain( $language_code ) {
		if ( isset( $this->fallback_chains[ $language_code ] ) ) {
			return $this->fallback_chains[ $language_code ];
		}

		// Default fallback: try default language, then English
		$default_lang = $this->detector->get_default_language();
		$fallback     = array();

		if ( $language_code !== $default_lang ) {
			$fallback[] = $default_lang;
		}

		if ( $language_code !== 'en' && $default_lang !== 'en' ) {
			$fallback[] = 'en';
		}

		return $fallback;
	}

	/**
	 * Set fallback chain for language
	 *
	 * @param string $language_code Language code.
	 * @param array  $chain         Fallback chain.
	 * @return bool Success
	 */
	public function set_fallback_chain( $language_code, array $chain ) {
		$this->fallback_chains[ $language_code ] = $chain;
		return update_option( 'ai_media_fallback_chains', $this->fallback_chains );
	}

	/**
	 * Get metadata with fallback
	 *
	 * Tries to get metadata in requested language, falls back to chain if not found.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $field         Metadata field (e.g., 'alt', 'caption').
	 * @param string $language_code Language code.
	 * @return array {
	 *     @type string      $value    Metadata value
	 *     @type string      $language Language code of found value
	 *     @type bool        $is_fallback Whether fallback was used
	 * }
	 */
	public function get_metadata_with_fallback( $attachment_id, $field, $language_code ) {
		// Try primary language
		$meta_key = "ai_{$field}_{$language_code}";
		$value    = get_post_meta( $attachment_id, $meta_key, true );

		if ( ! empty( $value ) ) {
			return array(
				'value'       => $value,
				'language'    => $language_code,
				'is_fallback' => false,
			);
		}

		// Try fallback chain
		$fallback_chain = $this->get_fallback_chain( $language_code );

		foreach ( $fallback_chain as $fallback_lang ) {
			$meta_key = "ai_{$field}_{$fallback_lang}";
			$value    = get_post_meta( $attachment_id, $meta_key, true );

			if ( ! empty( $value ) ) {
				return array(
					'value'       => $value,
					'language'    => $fallback_lang,
					'is_fallback' => true,
				);
			}
		}

		// No value found
		return array(
			'value'       => '',
			'language'    => $language_code,
			'is_fallback' => false,
		);
	}

	/**
	 * Get all metadata fields with fallback
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language_code Language code.
	 * @return array {
	 *     @type array $alt     Alt text data
	 *     @type array $caption Caption data
	 *     @type array $title   Title data
	 *     @type array $keywords Keywords data
	 * }
	 */
	public function get_all_metadata_with_fallback( $attachment_id, $language_code ) {
		$fields = array( 'alt', 'caption', 'title', 'keywords' );
		$result = array();

		foreach ( $fields as $field ) {
			$result[ $field ] = $this->get_metadata_with_fallback( $attachment_id, $field, $language_code );
		}

		return $result;
	}

	/**
	 * Check if metadata exists in language
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $field         Metadata field.
	 * @param string $language_code Language code.
	 * @return bool
	 */
	public function has_metadata( $attachment_id, $field, $language_code ) {
		$meta_key = "ai_{$field}_{$language_code}";
		$value    = get_post_meta( $attachment_id, $meta_key, true );
		return ! empty( $value );
	}

	/**
	 * Get available languages for attachment metadata
	 *
	 * Returns all languages that have at least one metadata field populated.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Language codes
	 */
	public function get_available_languages( $attachment_id ) {
		$all_languages = $this->detector->get_languages();
		$fields        = array( 'alt', 'caption', 'title', 'keywords' );
		$available     = array();

		foreach ( $all_languages as $language ) {
			foreach ( $fields as $field ) {
				if ( $this->has_metadata( $attachment_id, $field, $language ) ) {
					$available[] = $language;
					break;
				}
			}
		}

		return array_unique( $available );
	}

	/**
	 * Get missing languages for attachment
	 *
	 * Returns languages that don't have any metadata yet.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Language codes
	 */
	public function get_missing_languages( $attachment_id ) {
		$all_languages       = $this->detector->get_languages();
		$available_languages = $this->get_available_languages( $attachment_id );
		return array_diff( $all_languages, $available_languages );
	}

	/**
	 * Get completion status for attachment
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array {
	 *     @type int   $total     Total languages
	 *     @type int   $completed Languages with metadata
	 *     @type int   $missing   Languages without metadata
	 *     @type float $percentage Completion percentage
	 * }
	 */
	public function get_completion_status( $attachment_id ) {
		$all_languages       = $this->detector->get_languages();
		$available_languages = $this->get_available_languages( $attachment_id );

		$total     = count( $all_languages );
		$completed = count( $available_languages );
		$missing   = $total - $completed;

		return array(
			'total'      => $total,
			'completed'  => $completed,
			'missing'    => $missing,
			'percentage' => $total > 0 ? ( $completed / $total ) * 100 : 0,
		);
	}

	/**
	 * Suggest next language to generate
	 *
	 * Based on priority and fallback chains.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null Language code or null if all complete
	 */
	public function suggest_next_language( $attachment_id ) {
		$missing_languages = $this->get_missing_languages( $attachment_id );

		if ( empty( $missing_languages ) ) {
			return null;
		}

		// Priority: default language first
		$default_lang = $this->detector->get_default_language();
		if ( in_array( $default_lang, $missing_languages, true ) ) {
			return $default_lang;
		}

		// Then English if missing
		if ( in_array( 'en', $missing_languages, true ) ) {
			return 'en';
		}

		// Then any other language
		return reset( $missing_languages );
	}
}
