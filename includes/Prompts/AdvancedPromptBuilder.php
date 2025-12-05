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
		$template = 'You are a {{ai_role}} analyzing images for WordPress websites.' . "\n\n";
		$template .= '═══════════════════════════════════════════════════════════════' . "\n";
		$template .= 'WEBSITE & PAGE CONTEXT' . "\n";
		$template .= '═══════════════════════════════════════════════════════════════' . "\n\n";
		$template .= 'WEBSITE CONTEXT:' . "\n";
		$template .= '{{#if site_context}}Site: {{site_context}}' . "\n";
		$template .= '{{/if}}{{#if post_title}}Page Title: {{post_title}}' . "\n";
		$template .= '{{/if}}{{#if post_excerpt}}Page Description: {{post_excerpt}}' . "\n";
		$template .= '{{/if}}{{#if categories}}Categories: {{categories}}' . "\n";
		$template .= '{{/if}}{{#if tags}}Tags: {{tags}}' . "\n";
		$template .= '{{/if}}{{#if post_type}}Post Type: {{post_type}}' . "\n";
		$template .= '{{/if}}' . "\n\n";
		$template .= '═══════════════════════════════════════════════════════════════' . "\n";
		$template .= 'IMAGE DETAILS' . "\n";
		$template .= '═══════════════════════════════════════════════════════════════' . "\n\n";
		$template .= 'IMAGE INFORMATION:' . "\n";
		$template .= '{{#if filename_hint}}Filename: {{filename_hint}}' . "\n";
		$template .= '{{/if}}{{#if orientation}}Format: {{orientation}}' . "\n";
		$template .= '{{/if}}{{#if dimensions}}Dimensions: {{dimensions}}' . "\n";
		$template .= '{{/if}}{{#if current_alt}}Current ALT: "{{current_alt}}" (refine and improve this)' . "\n";
		$template .= '{{/if}}{{#if attachment_title}}Original Title: {{attachment_title}}' . "\n";
		$template .= '{{/if}}{{#if attachment_caption}}Author Caption: {{attachment_caption}}' . "\n";
		$template .= '{{/if}}{{#if attachment_description}}Author Description: {{attachment_description}}' . "\n";
		$template .= '{{/if}}' . "\n\n";
		$template .= '{{#if exif_title}}EXIF METADATA:' . "\n";
		$template .= '📌 Title: {{exif_title}}' . "\n";
		$template .= '{{/if}}{{#if exif_caption}}💭 Caption: {{exif_caption}}' . "\n";
		$template .= '{{/if}}{{#if camera}}📷 Camera: {{camera}}' . "\n";
		$template .= '{{/if}}{{#if photo_date}}📅 Taken: {{photo_date}}' . "\n";
		$template .= '{{/if}}{{#if location}}📍 Location: {{location}}' . "\n";
		$template .= '{{/if}}{{#if copyright}}©️  Copyright: {{copyright}}' . "\n";
		$template .= '{{/if}}' . "\n\n";
		$template .= '═══════════════════════════════════════════════════════════════' . "\n";
		$template .= 'ANALYSIS STRATEGY' . "\n";
		$template .= '═══════════════════════════════════════════════════════════════' . "\n\n";
		$template .= '1️⃣ CONTEXT PRIORITY HIERARCHY:' . "\n";
		$template .= '   a) Page description & title (what is this page about?)' . "\n";
		$template .= '   b) Categories & tags (SEO taxonomy)' . "\n";
		$template .= '   c) Filename & author metadata (image purpose)' . "\n";
		$template .= '   d) Current ALT text (baseline to improve)' . "\n";
		$template .= '   e) EXIF data (technical context)' . "\n\n";
		$template .= '2️⃣ IMAGE PURPOSE DETECTION:' . "\n";
		$template .= '   - Filename \'hero-*\', \'banner-*\' → Main visual, wide format' . "\n";
		$template .= '   - Filename \'team-*\', \'staff-*\' → People, group shots' . "\n";
		$template .= '   - Filename \'product-*\' → Product photography' . "\n";
		$template .= '   - Orientation landscape → Wide scene, panoramic' . "\n";
		$template .= '   - Orientation portrait → Vertical composition, person-focused' . "\n";
		$template .= '   - Orientation square → Balanced, social media optimized' . "\n\n";
		$template .= '3️⃣ METADATA GENERATION RULES:' . "\n";
		$template .= '   - ALT: Prioritize accessibility + SEO + page relevance' . "\n";
		$template .= '   - Caption: Tell story that fits page narrative' . "\n";
		$template .= '   - Title: Short, keyword-rich, searchable' . "\n";
		$template .= '   - Keywords: MUST align with page categories/tags' . "\n";
		$template .= '   - Score: Self-assess confidence (0.0-1.0)' . "\n\n";
		$template .= '═══════════════════════════════════════════════════════════════' . "\n";
		$template .= 'OUTPUT LANGUAGE: {{language_name}}' . "\n";
		$template .= '═══════════════════════════════════════════════════════════════' . "\n\n";
		$template .= '{{#if is_multilingual}}⚠️  CRITICAL MULTILINGUAL SITE WARNING ⚠️' . "\n\n";
		$template .= 'This is a multilingual website. Even though the context above may contain text' . "\n";
		$template .= 'in different languages, ALL your output MUST be in {{language_name}} language ONLY.' . "\n\n";
		$template .= 'IMPORTANT INSTRUCTIONS:' . "\n";
		$template .= '• DO NOT translate into other languages' . "\n";
		$template .= '• DO NOT copy text from context if it\'s in a different language' . "\n";
		$template .= '• DO NOT mix languages in the output' . "\n";
		$template .= '• ONLY {{language_name}} language is acceptable' . "\n\n";
		$template .= '═══════════════════════════════════════════════════════════════' . "\n\n";
		$template .= '{{/if}}TASK: Generate SEO-optimized metadata in {{language_name}} language:' . "\n\n";
		$template .= '1. ALT text (max {{alt_max_length}} characters, descriptive, no \'image of\')' . "\n";
		$template .= '   → Focus on: page relevance, accessibility, SEO keywords' . "\n";
		$template .= '   → If Current ALT exists: improve it, don\'t replace entirely' . "\n";
		$template .= '{{#if is_multilingual}}   → MUST BE IN {{language_name}} LANGUAGE' . "\n";
		$template .= '{{/if}}' . "\n\n";
		$template .= '2. Caption (1-2 sentences, contextual, engaging)' . "\n";
		$template .= '   → Focus on: storytelling, page context' . "\n";
		$template .= '{{#if is_multilingual}}   → MUST BE IN {{language_name}} LANGUAGE' . "\n";
		$template .= '{{/if}}' . "\n\n";
		$template .= '3. Title (3-6 words, factual, keyword-rich)' . "\n";
		$template .= '   → Focus on: SEO, clarity, searchability' . "\n";
		$template .= '{{#if is_multilingual}}   → MUST BE IN {{language_name}} LANGUAGE' . "\n";
		$template .= '{{/if}}' . "\n\n";
		$template .= '4. Keywords (3-6 relevant terms, comma-separated)' . "\n";
		$template .= '   → Focus on: page categories/tags alignment, search intent' . "\n";
		$template .= '{{#if is_multilingual}}   → MUST BE IN {{language_name}} LANGUAGE' . "\n";
		$template .= '{{/if}}' . "\n\n";
		$template .= 'IMPORTANT GUIDELINES:' . "\n";
		$template .= '- Prioritize PAGE CONTEXT over image technical details' . "\n";
		$template .= '- Use filename hints to understand image purpose (hero, thumbnail, etc.)' . "\n";
		$template .= '- Consider image orientation when describing scene' . "\n";
		$template .= '- If current ALT is good, refine it instead of replacing' . "\n";
		$template .= '- Match keywords with page categories and tags' . "\n";
		$template .= '- Be specific and descriptive, avoid generic terms' . "\n";
		$template .= '{{#if is_multilingual}}- ALL OUTPUT IN {{language_name}} ONLY' . "\n";
		$template .= '{{/if}}' . "\n\n";
		$template .= '{{#if is_multilingual}}═══════════════════════════════════════════════════════════════' . "\n";
		$template .= 'FINAL REMINDER: Write ALL fields in {{language_name}} language ONLY!' . "\n";
		$template .= '═══════════════════════════════════════════════════════════════' . "\n";
		$template .= '{{/if}}' . "\n\n";
		$template .= 'Respond ONLY with valid JSON in this exact format:' . "\n";
		$template .= '{"alt":"...","caption":"...","title":"...","keywords":["..."],"score":0.95}';
		
		return $template;
	}
}
