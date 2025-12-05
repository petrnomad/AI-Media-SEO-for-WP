<?php
/**
 * Minimal Prompt Builder
 *
 * Fast and cost-effective approach with essential context only.
 * Best for high-volume sites or budget-conscious scenarios.
 *
 * @package    AIMediaSEO
 * @subpackage Prompts
 * @since      1.0.0
 */

namespace AIMediaSEO\Prompts;

/**
 * MinimalPromptBuilder class.
 *
 * Implements minimal prompt generation with:
 * - Basic page context (title, categories, tags)
 * - Essential image information
 * - Concise task instructions
 * - Lowest token count for cost optimization
 *
 * Use this when:
 * - Processing high volumes of images
 * - Cost optimization is priority
 * - Basic SEO metadata is sufficient
 *
 * @since 1.0.0
 */
class MinimalPromptBuilder extends PromptBuilder {

	/**
	 * Get default template.
	 *
	 * Returns minimal prompt template with essential context only.
	 *
	 * @since 1.0.0
	 * @return string Template string with placeholders.
	 */
	protected function get_default_template(): string {
		$template = 'You are a {{ai_role}} analyzing images for WordPress websites.' . "\n\n";
		$template .= 'CONTEXT:' . "\n";
		$template .= '{{#if site_context}}Site: {{site_context}}' . "\n";
		$template .= '{{/if}}{{#if post_title}}Page: {{post_title}}' . "\n";
		$template .= '{{/if}}{{#if categories}}Categories: {{categories}}' . "\n";
		$template .= '{{/if}}{{#if tags}}Tags: {{tags}}' . "\n";
		$template .= '{{/if}}' . "\n\n";
		$template .= 'IMAGE:' . "\n";
		$template .= '{{#if filename_hint}}File: {{filename_hint}}' . "\n";
		$template .= '{{/if}}{{#if orientation}}Format: {{orientation}}' . "\n";
		$template .= '{{/if}}' . "\n\n";
		$template .= 'IMPORTANT: Generate ALL content in {{language_name}} language.' . "\n\n";
		$template .= 'Task: Generate SEO metadata:' . "\n";
		$template .= '1. ALT ({{alt_max_length}} chars max) - page-relevant, accessible, in {{language_name}}' . "\n";
		$template .= '2. Caption (1-2 sentences) - contextual, in {{language_name}}' . "\n";
		$template .= '3. Title (3-6 words) - SEO-focused, in {{language_name}}' . "\n";
		$template .= '4. Keywords (3-6 terms) - match page tags/categories, in {{language_name}}' . "\n\n";
		$template .= 'Respond ONLY with JSON:' . "\n";
		$template .= '{"alt":"...","caption":"...","title":"...","keywords":["..."],"score":0.95}';
		
		return $template;
	}
}
