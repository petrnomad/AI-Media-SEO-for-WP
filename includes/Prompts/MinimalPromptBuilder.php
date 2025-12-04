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
		return <<<'TEMPLATE'
You are a {{ai_role}} analyzing images for WordPress websites.

CONTEXT:
{{#if site_context}}Site: {{site_context}}
{{/if}}{{#if post_title}}Page: {{post_title}}
{{/if}}{{#if categories}}Categories: {{categories}}
{{/if}}{{#if tags}}Tags: {{tags}}
{{/if}}

IMAGE:
{{#if filename_hint}}File: {{filename_hint}}
{{/if}}{{#if orientation}}Format: {{orientation}}
{{/if}}

IMPORTANT: Generate ALL content in {{language_name}} language.

Task: Generate SEO metadata:
1. ALT ({{alt_max_length}} chars max) - page-relevant, accessible, in {{language_name}}
2. Caption (1-2 sentences) - contextual, in {{language_name}}
3. Title (3-6 words) - SEO-focused, in {{language_name}}
4. Keywords (3-6 terms) - match page tags/categories, in {{language_name}}

Respond ONLY with JSON:
{"alt":"...","caption":"...","title":"...","keywords":["..."],"score":0.95}
TEMPLATE;
	}
}
