<?php
/**
 * Quality Validator
 *
 * Validates the quality of AI-generated metadata.
 *
 * @package    AIMediaSEO
 * @subpackage Analyzer
 * @since      1.0.0
 */

namespace AIMediaSEO\Analyzer;

/**
 * QualityValidator class.
 *
 * Validates and scores AI-generated metadata.
 *
 * @since 1.0.0
 */
class QualityValidator {

	/**
	 * Quality rules.
	 *
	 * @var array
	 */
	private $rules;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->load_rules();
	}

	/**
	 * Load quality rules from settings.
	 *
	 * @since 1.0.0
	 */
	private function load_rules(): void {
		$this->rules = get_option( 'ai_media_seo_quality_rules', array() );

		// Set defaults if not configured.
		if ( empty( $this->rules ) ) {
			$this->rules = $this->get_default_rules();
		}
	}

	/**
	 * Get default quality rules.
	 *
	 * @since 1.0.0
	 * @return array Default rules.
	 */
	private function get_default_rules(): array {
		return array(
			'alt' => array(
				'min_length'        => 10,
				'max_length'        => 125,
				'forbidden_phrases' => array(
					'image of',
					'picture of',
					'photo of',
					'screenshot of',
					'graphic of',
				),
				'require_descriptive' => true,
			),
			'caption' => array(
				'min_words'    => 5,
				'max_words'    => 30,
				'min_length'   => 20,
				'max_length'   => 300,
			),
			'title' => array(
				'min_words'  => 3,
				'max_words'  => 6,
				'min_length' => 10,
				'max_length' => 60,
			),
			'keywords' => array(
				'min_count' => 3,
				'max_count' => 6,
			),
			'score' => array(
				'min_acceptable' => 0.7,
			),
		);
	}

	/**
	 * Validate all metadata fields.
	 *
	 * @since 1.0.0
	 * @param array $metadata The metadata to validate.
	 * @return array {
	 *     Validation results.
	 *
	 *     @type bool  $valid   Whether validation passed.
	 *     @type array $errors  Array of error messages.
	 *     @type float $score   Quality score (0.0-1.0).
	 * }
	 */
	public function validate( array $metadata ): array {
		$errors = array();
		$scores = array();

		// Validate ALT text.
		if ( isset( $metadata['alt'] ) ) {
			$alt_validation = $this->validate_alt( $metadata['alt'] );
			if ( ! $alt_validation['valid'] ) {
				$errors['alt'] = $alt_validation['errors'];
			}
			$scores['alt'] = $alt_validation['score'];
		} else {
			$errors['alt'] = array( __( 'ALT text is required.', 'ai-media-seo' ) );
			$scores['alt'] = 0.0;
		}

		// Validate caption.
		if ( isset( $metadata['caption'] ) ) {
			$caption_validation = $this->validate_caption( $metadata['caption'] );
			if ( ! $caption_validation['valid'] ) {
				$errors['caption'] = $caption_validation['errors'];
			}
			$scores['caption'] = $caption_validation['score'];
		}

		// Validate title.
		if ( isset( $metadata['title'] ) ) {
			$title_validation = $this->validate_title( $metadata['title'] );
			if ( ! $title_validation['valid'] ) {
				$errors['title'] = $title_validation['errors'];
			}
			$scores['title'] = $title_validation['score'];
		}

		// Validate keywords.
		if ( isset( $metadata['keywords'] ) ) {
			$keywords_validation = $this->validate_keywords( $metadata['keywords'] );
			if ( ! $keywords_validation['valid'] ) {
				$errors['keywords'] = $keywords_validation['errors'];
			}
			$scores['keywords'] = $keywords_validation['score'];
		}

		// Calculate overall score.
		$overall_score = ! empty( $scores ) ? array_sum( $scores ) / count( $scores ) : 0.0;

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
			'score'  => $overall_score,
		);
	}

	/**
	 * Validate ALT text.
	 *
	 * @since 1.0.0
	 * @param string $alt The ALT text.
	 * @return array Validation result.
	 */
	public function validate_alt( string $alt ): array {
		$errors = array();
		$score = 1.0;

		$rules = $this->rules['alt'] ?? $this->get_default_rules()['alt'];

		// Check length.
		$length = mb_strlen( $alt );

		if ( $length < $rules['min_length'] ) {
			$errors[] = sprintf(
				/* translators: %d: Minimum length */
				__( 'ALT text is too short (minimum %d characters).', 'ai-media-seo' ),
				$rules['min_length']
			);
			$score -= 0.3;
		}

		if ( $length > $rules['max_length'] ) {
			$errors[] = sprintf(
				/* translators: %d: Maximum length */
				__( 'ALT text is too long (maximum %d characters).', 'ai-media-seo' ),
				$rules['max_length']
			);
			$score -= 0.5;
		}

		// Check forbidden phrases.
		if ( ! empty( $rules['forbidden_phrases'] ) ) {
			$alt_lower = mb_strtolower( $alt );

			foreach ( $rules['forbidden_phrases'] as $phrase ) {
				if ( strpos( $alt_lower, mb_strtolower( $phrase ) ) !== false ) {
					$errors[] = sprintf(
						/* translators: %s: Forbidden phrase */
						__( 'ALT text contains forbidden phrase: "%s"', 'ai-media-seo' ),
						$phrase
					);
					$score -= 0.2;
				}
			}
		}

		// Check if descriptive enough.
		if ( $rules['require_descriptive'] ) {
			$word_count = str_word_count( $alt );
			if ( $word_count < 3 ) {
				$errors[] = __( 'ALT text should be more descriptive (at least 3 words).', 'ai-media-seo' );
				$score -= 0.2;
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
			'score'  => max( 0.0, $score ),
		);
	}

	/**
	 * Validate caption.
	 *
	 * @since 1.0.0
	 * @param string $caption The caption text.
	 * @return array Validation result.
	 */
	public function validate_caption( string $caption ): array {
		$errors = array();
		$score = 1.0;

		$rules = $this->rules['caption'] ?? $this->get_default_rules()['caption'];

		$length = mb_strlen( $caption );
		$word_count = str_word_count( $caption );

		// Check length.
		if ( $length < $rules['min_length'] ) {
			$errors[] = sprintf(
				/* translators: %d: Minimum length */
				__( 'Caption is too short (minimum %d characters).', 'ai-media-seo' ),
				$rules['min_length']
			);
			$score -= 0.2;
		}

		if ( $length > $rules['max_length'] ) {
			$errors[] = sprintf(
				/* translators: %d: Maximum length */
				__( 'Caption is too long (maximum %d characters).', 'ai-media-seo' ),
				$rules['max_length']
			);
			$score -= 0.3;
		}

		// Check word count.
		if ( $word_count < $rules['min_words'] ) {
			$errors[] = sprintf(
				/* translators: %d: Minimum words */
				__( 'Caption needs more content (minimum %d words).', 'ai-media-seo' ),
				$rules['min_words']
			);
			$score -= 0.2;
		}

		if ( $word_count > $rules['max_words'] ) {
			$errors[] = sprintf(
				/* translators: %d: Maximum words */
				__( 'Caption is too wordy (maximum %d words).', 'ai-media-seo' ),
				$rules['max_words']
			);
			$score -= 0.2;
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
			'score'  => max( 0.0, $score ),
		);
	}

	/**
	 * Validate title.
	 *
	 * @since 1.0.0
	 * @param string $title The title text.
	 * @return array Validation result.
	 */
	public function validate_title( string $title ): array {
		$errors = array();
		$score = 1.0;

		$rules = $this->rules['title'] ?? $this->get_default_rules()['title'];

		$length = mb_strlen( $title );
		$word_count = str_word_count( $title );

		// Check word count.
		if ( $word_count < $rules['min_words'] ) {
			$errors[] = sprintf(
				/* translators: %d: Minimum words */
				__( 'Title should have at least %d words.', 'ai-media-seo' ),
				$rules['min_words']
			);
			$score -= 0.3;
		}

		if ( $word_count > $rules['max_words'] ) {
			$errors[] = sprintf(
				/* translators: %d: Maximum words */
				__( 'Title should have at most %d words.', 'ai-media-seo' ),
				$rules['max_words']
			);
			$score -= 0.2;
		}

		// Check length.
		if ( $length > $rules['max_length'] ) {
			$errors[] = sprintf(
				/* translators: %d: Maximum length */
				__( 'Title is too long (maximum %d characters).', 'ai-media-seo' ),
				$rules['max_length']
			);
			$score -= 0.3;
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
			'score'  => max( 0.0, $score ),
		);
	}

	/**
	 * Validate keywords.
	 *
	 * @since 1.0.0
	 * @param array $keywords Array of keywords.
	 * @return array Validation result.
	 */
	public function validate_keywords( array $keywords ): array {
		$errors = array();
		$score = 1.0;

		$rules = $this->rules['keywords'] ?? $this->get_default_rules()['keywords'];

		$count = count( $keywords );

		if ( $count < $rules['min_count'] ) {
			$errors[] = sprintf(
				/* translators: %d: Minimum count */
				__( 'Need at least %d keywords.', 'ai-media-seo' ),
				$rules['min_count']
			);
			$score -= 0.3;
		}

		if ( $count > $rules['max_count'] ) {
			$errors[] = sprintf(
				/* translators: %d: Maximum count */
				__( 'Too many keywords (maximum %d).', 'ai-media-seo' ),
				$rules['max_count']
			);
			$score -= 0.2;
		}

		// Check for duplicates.
		if ( count( $keywords ) !== count( array_unique( $keywords ) ) ) {
			$errors[] = __( 'Keywords contain duplicates.', 'ai-media-seo' );
			$score -= 0.1;
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
			'score'  => max( 0.0, $score ),
		);
	}

	/**
	 * Check if metadata meets auto-approve threshold.
	 *
	 * @since 1.0.0
	 * @param float $score Quality score.
	 * @return bool True if can be auto-approved.
	 */
	public function can_auto_approve( float $score ): bool {
		$settings = get_option( 'ai_media_seo_settings', array() );
		$threshold = $settings['auto_approve_threshold'] ?? 0.85;

		return $score >= $threshold;
	}
}
