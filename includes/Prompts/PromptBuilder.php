<?php
/**
 * Abstract Prompt Builder
 *
 * Base class for building AI prompts with template-based system.
 * Each variant implements get_default_template() method.
 *
 * @package    AIMediaSEO
 * @subpackage Prompts
 * @since      1.0.0
 */

namespace AIMediaSEO\Prompts;

/**
 * PromptBuilder abstract class.
 *
 * Provides template-based prompt generation with:
 * - Template rendering with placeholders ({{variable}})
 * - Conditional blocks ({{#if}}...{{/if}})
 * - Database fallback for custom templates
 * - Utility methods for language detection
 *
 * @since 1.0.0
 */
abstract class PromptBuilder {

	/**
	 * Get default template.
	 *
	 * Each variant must implement this method to return its default prompt template.
	 *
	 * @since 1.0.0
	 * @return string Default template with placeholders.
	 */
	abstract protected function get_default_template(): string;

	/**
	 * Build complete prompt.
	 *
	 * Main method that orchestrates template rendering:
	 * 1. Get template (custom from DB or default)
	 * 2. Prepare data for placeholders
	 * 3. Render template with data
	 *
	 * @since 1.0.0
	 * @param string $language Language code (e.g., 'en', 'cs').
	 * @param array  $context  Context data from ContextBuilder.
	 * @param array  $settings Global settings array.
	 * @return string Complete prompt text.
	 */
	public function build( string $language, array $context, array $settings ): string {
		$template = $this->get_template();
		$data     = $this->prepare_data( $language, $context, $settings );
		return $this->render( $template, $data );
	}

	/**
	 * Get template with DB fallback.
	 *
	 * Checks for custom template in database, falls back to default.
	 * Custom templates can be set via: update_option('ai_media_seo_prompt_minimal', $template)
	 *
	 * @since 1.0.0
	 * @return string Template string.
	 */
	protected function get_template(): string {
		$variant = $this->get_variant_name();
		$custom  = get_option( "ai_media_seo_prompt_{$variant}", false );

		return $custom ?: $this->get_default_template();
	}

	/**
	 * Prepare template data from context.
	 *
	 * Merges settings, language data, and context into flat array for placeholders.
	 *
	 * @since 1.0.0
	 * @param string $language Language code.
	 * @param array  $context  Context data.
	 * @param array  $settings Settings array.
	 * @return array Flat array of placeholder data.
	 */
	protected function prepare_data( string $language, array $context, array $settings ): array {
		$language_detector = new \AIMediaSEO\Multilingual\LanguageDetector();

		return array_merge(
			// Settings.
			array(
				'ai_role'        => $settings['ai_role'] ?? 'SEO expert',
				'site_context'   => $settings['site_context'] ?? '',
				'alt_max_length' => $settings['alt_max_length'] ?? 125,
			),
			// Language.
			array(
				'language'        => $language,
				'language_name'   => $this->get_language_name( $language ),
				'is_multilingual' => $language_detector->is_multilingual_active(),
			),
			// Flatten all context fields.
			$context
		);
	}

	/**
	 * Render template with data.
	 *
	 * Replaces placeholders and processes conditionals:
	 * - {{variable}} → value
	 * - {{#if variable}}...{{/if}} → conditional blocks
	 * - Arrays auto-join with comma: {{categories}} → "cat1, cat2, cat3"
	 *
	 * @since 1.0.0
	 * @param string $template Template string.
	 * @param array  $data     Placeholder data.
	 * @return string Rendered template.
	 */
	protected function render( string $template, array $data ): string {
		// 1. Apply conditionals first (before placeholder replacement).
		$template = $this->apply_conditionals( $template, $data );

		// 2. Replace simple placeholders.
		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) || is_numeric( $value ) ) {
				$template = str_replace( '{{' . $key . '}}', $value, $template );
			} elseif ( is_array( $value ) ) {
				// Arrays: auto-join with comma.
				$template = str_replace( '{{' . $key . '}}', implode( ', ', $value ), $template );
			} elseif ( is_bool( $value ) ) {
				// Booleans: convert to string (for debug purposes).
				$template = str_replace( '{{' . $key . '}}', $value ? 'true' : 'false', $template );
			}
		}

		// 3. Clean up any remaining placeholders (optional - for debugging).
		// $template = preg_replace('/\{\{[^}]+\}\}/', '', $template);

		return $template;
	}

	/**
	 * Apply conditional blocks.
	 *
	 * Processes {{#if variable}}...{{/if}} blocks.
	 * Shows content only if variable exists and is truthy.
	 *
	 * @since 1.0.0
	 * @param string $template Template string.
	 * @param array  $data     Data array.
	 * @return string Template with conditionals processed.
	 */
	protected function apply_conditionals( string $template, array $data ): string {
		// Match {{#if variable}}...{{/if}} blocks.
		$pattern = '/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s';

		$template = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $data ) {
				$variable = $matches[1];
				$content  = $matches[2];

				// Check if variable exists and is truthy.
				$value = $data[ $variable ] ?? null;

				$is_truthy = ! empty( $value ) || ( is_numeric( $value ) && $value !== 0 );

				return $is_truthy ? $content : '';
			},
			$template
		);

		return $template;
	}

	/**
	 * Check if multilingual plugin is active.
	 *
	 * @since 1.0.0
	 * @return bool True if multilingual plugin detected.
	 */
	protected function is_multilingual(): bool {
		$detector = new \AIMediaSEO\Multilingual\LanguageDetector();
		return $detector->is_multilingual_active();
	}

	/**
	 * Get full language name from locale code.
	 *
	 * Maps language codes to uppercase full names for prompts.
	 *
	 * @since 1.0.0
	 * @param string $locale Language code (e.g., 'en', 'cs').
	 * @return string Full language name (e.g., 'ENGLISH', 'CZECH').
	 */
	protected function get_language_name( string $locale ): string {
		$names = array(
			'en' => 'ENGLISH',
			'cs' => 'CZECH',
			'de' => 'GERMAN',
			'fr' => 'FRENCH',
			'es' => 'SPANISH',
			'it' => 'ITALIAN',
			'pt' => 'PORTUGUESE',
			'pl' => 'POLISH',
			'ru' => 'RUSSIAN',
			'nl' => 'DUTCH',
			'sv' => 'SWEDISH',
			'da' => 'DANISH',
			'fi' => 'FINNISH',
			'no' => 'NORWEGIAN',
		);

		return $names[ $locale ] ?? strtoupper( $locale );
	}

	/**
	 * Estimate token count (approximate).
	 *
	 * Uses rough heuristic: 1 token ≈ 4 characters.
	 *
	 * @since 1.0.0
	 * @param string $prompt The prompt text.
	 * @return int Estimated token count.
	 */
	public function estimate_tokens( string $prompt ): int {
		return (int) ( strlen( $prompt ) / 4 );
	}

	/**
	 * Get variant name.
	 *
	 * Extracts variant name from class name (e.g., 'MinimalPromptBuilder' → 'minimal').
	 *
	 * @since 1.0.0
	 * @return string Variant name (e.g., 'standard', 'minimal', 'advanced').
	 */
	public function get_variant_name(): string {
		$class_name = get_class( $this );
		$short_name = substr( $class_name, strrpos( $class_name, '\\' ) + 1 );
		return strtolower( str_replace( 'PromptBuilder', '', $short_name ) );
	}
}
