<?php
/**
 * Advanced Prompt Builder
 *
 * Maximum context approach with all available data.
 * Best for premium sites where quality is paramount.
 *
 * @package    AIMediaSEO
 * @subpackage Prompts
 * @since      1.0.0
 */

namespace AIMediaSEO\Prompts;

/**
 * AdvancedPromptBuilder class.
 *
 * Implements comprehensive prompt generation with:
 * - All page context (title, description, categories, tags, post type)
 * - Complete image information (filename, orientation, dimensions, current ALT)
 * - Full EXIF metadata (title, caption, camera, date, location)
 * - Author metadata (title, caption, description)
 * - Detailed AI instructions with pattern detection
 * - Maximum token count for best quality
 *
 * Use this when:
 * - Quality is more important than cost
 * - Working with important/landing pages
 * - Need maximum accuracy and context
 *
 * @since 1.0.0
 */
class AdvancedPromptBuilder extends PromptBuilder {

	/**
	 * Get default template.
	 *
	 * Returns advanced prompt template with maximum context and detailed instructions.
	 *
	 * @since 1.0.0
	 * @return string Template string with placeholders.
	 */
	protected function get_default_template(): string {
		return <<<'TEMPLATE'
You are a {{ai_role}} analyzing images for WordPress websites.

═══════════════════════════════════════════════════════════════
WEBSITE & PAGE CONTEXT
═══════════════════════════════════════════════════════════════

WEBSITE CONTEXT:
{{#if site_context}}Site: {{site_context}}
{{/if}}{{#if post_title}}Page Title: {{post_title}}
{{/if}}{{#if post_excerpt}}Page Description: {{post_excerpt}}
{{/if}}{{#if categories}}Categories: {{categories}}
{{/if}}{{#if tags}}Tags: {{tags}}
{{/if}}{{#if post_type}}Post Type: {{post_type}}
{{/if}}

═══════════════════════════════════════════════════════════════
IMAGE DETAILS
═══════════════════════════════════════════════════════════════

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
📌 Title: {{exif_title}}
{{/if}}{{#if exif_caption}}💭 Caption: {{exif_caption}}
{{/if}}{{#if camera}}📷 Camera: {{camera}}
{{/if}}{{#if photo_date}}📅 Taken: {{photo_date}}
{{/if}}{{#if location}}📍 Location: {{location}}
{{/if}}{{#if copyright}}©️  Copyright: {{copyright}}
{{/if}}

═══════════════════════════════════════════════════════════════
ANALYSIS STRATEGY
═══════════════════════════════════════════════════════════════

1️⃣ CONTEXT PRIORITY HIERARCHY:
   a) Page description & title (what is this page about?)
   b) Categories & tags (SEO taxonomy)
   c) Filename & author metadata (image purpose)
   d) Current ALT text (baseline to improve)
   e) EXIF data (technical context)

2️⃣ IMAGE PURPOSE DETECTION:
   - Filename 'hero-*', 'banner-*' → Main visual, wide format
   - Filename 'team-*', 'staff-*' → People, group shots
   - Filename 'product-*' → Product photography
   - Orientation landscape → Wide scene, panoramic
   - Orientation portrait → Vertical composition, person-focused
   - Orientation square → Balanced, social media optimized

3️⃣ METADATA GENERATION RULES:
   - ALT: Prioritize accessibility + SEO + page relevance
   - Caption: Tell story that fits page narrative
   - Title: Short, keyword-rich, searchable
   - Keywords: MUST align with page categories/tags
   - Score: Self-assess confidence (0.0-1.0)

═══════════════════════════════════════════════════════════════
OUTPUT LANGUAGE: {{language_name}}
═══════════════════════════════════════════════════════════════

{{#if is_multilingual}}⚠️  CRITICAL MULTILINGUAL SITE WARNING ⚠️

This is a multilingual website. Even though the context above may contain text
in different languages, ALL your output MUST be in {{language_name}} language ONLY.

IMPORTANT INSTRUCTIONS:
• DO NOT translate into other languages
• DO NOT copy text from context if it's in a different language
• DO NOT mix languages in the output
• ONLY {{language_name}} language is acceptable

═══════════════════════════════════════════════════════════════

{{/if}}TASK: Generate SEO-optimized metadata in {{language_name}} language:

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
