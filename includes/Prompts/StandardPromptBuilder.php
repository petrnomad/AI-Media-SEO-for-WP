<?php
/**
 * Standard Prompt Builder
 *
 * Balanced approach with all critical context data.
 * Recommended for most use cases - provides best quality/cost balance.
 *
 * @package    AIMediaSEO
 * @subpackage Prompts
 * @since      1.0.0
 */

namespace AIMediaSEO\Prompts;

/**
 * StandardPromptBuilder class.
 *
 * Implements comprehensive prompt generation with:
 * - Page description (post_excerpt) for better context
 * - Image orientation and dimensions for composition awareness
 * - Current ALT text for refinement instead of replacement
 * - Filename hints for purpose detection
 * - EXIF metadata when available
 * - Enhanced instructions for better AI output
 *
 * This is the recommended variant for most users.
 *
 * @since 1.0.0
 */
class StandardPromptBuilder extends PromptBuilder {

	/**
	 * Get default template.
	 *
	 * Returns standard prompt template with comprehensive context.
	 *
	 * @since 1.0.0
	 * @return string Template string with placeholders.
	 */
	protected function get_default_template(): string {
		return <<<'TEMPLATE'
You are a {{ai_role}} analyzing images for WordPress websites.

WEBSITE CONTEXT:
{{#if site_context}}Site: {{site_context}}
{{/if}}{{#if post_title}}Page Title: {{post_title}}
{{/if}}{{#if post_excerpt}}Page Description: {{post_excerpt}}
{{/if}}{{#if categories}}Categories: {{categories}}
{{/if}}{{#if tags}}Tags: {{tags}}
{{/if}}

IMAGE INFORMATION:
{{#if filename_hint}}Filename: {{filename_hint}}
{{/if}}{{#if orientation}}Format: {{orientation}}
{{/if}}{{#if dimensions}}Dimensions: {{dimensions}}
{{/if}}{{#if current_alt}}Current ALT: "{{current_alt}}" (refine and improve this)
{{/if}}{{#if attachment_title}}Original Title: {{attachment_title}}
{{/if}}{{#if attachment_caption}}Author Caption: {{attachment_caption}}
{{/if}}{{#if attachment_description}}Author Description: {{attachment_description}}
{{/if}}

{{#if exif_title}}EXIF METADATA:
- Title: {{exif_title}}
{{/if}}{{#if exif_caption}}- Caption: {{exif_caption}}
{{/if}}

OUTPUT LANGUAGE: {{language_name}}
Generate ALL metadata (alt, caption, title, keywords) exclusively in {{language_name}} language.

{{#if is_multilingual}}═══════════════════════════════════════════════════════════════
⚠️  CRITICAL MULTILINGUAL SITE WARNING ⚠️
═══════════════════════════════════════════════════════════════

This is a multilingual website. Even though the context above may contain text
in different languages, ALL your output MUST be in {{language_name}} language ONLY.

• DO NOT translate into other languages
• DO NOT copy text from context if it's in a different language
• ONLY {{language_name}} language is acceptable

═══════════════════════════════════════════════════════════════
{{/if}}

TASK: Generate SEO-optimized metadata in {{language_name}} language:

1. ALT text (max {{alt_max_length}} characters, descriptive, no 'image of')
   → Focus on: page relevance, accessibility, SEO keywords
   → If Current ALT exists: improve it, don't replace entirely
{{#if is_multilingual}}   → MUST BE IN {{language_name}} LANGUAGE
{{/if}}

2. Caption (1-2 sentences, contextual, engaging)
   → Focus on: storytelling, page context
{{#if is_multilingual}}   → MUST BE IN {{language_name}} LANGUAGE
{{/if}}

3. Title (3-6 words, factual, keyword-rich)
   → Focus on: SEO, clarity, searchability
{{#if is_multilingual}}   → MUST BE IN {{language_name}} LANGUAGE
{{/if}}

4. Keywords (3-6 relevant terms, comma-separated)
   → Focus on: page categories/tags alignment, search intent
{{#if is_multilingual}}   → MUST BE IN {{language_name}} LANGUAGE
{{/if}}

IMPORTANT GUIDELINES:
- Prioritize PAGE CONTEXT over image technical details
- Use filename hints to understand image purpose (hero, thumbnail, etc.)
- Consider image orientation when describing scene
- If current ALT is good, refine it instead of replacing
- Match keywords with page categories and tags
- Be specific and descriptive, avoid generic terms
{{#if is_multilingual}}- ALL OUTPUT IN {{language_name}} ONLY
{{/if}}

{{#if is_multilingual}}═══════════════════════════════════════════════════════════════
FINAL REMINDER: Write ALL fields in {{language_name}} language ONLY!
═══════════════════════════════════════════════════════════════
{{/if}}

Respond ONLY with valid JSON in this exact format:
{"alt":"...","caption":"...","title":"...","keywords":["..."],"score":0.95}
TEMPLATE;
	}
}
