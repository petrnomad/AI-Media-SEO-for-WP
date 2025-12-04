=== AI Media SEO ===
Contributors: petrnomad
Tags: seo, image seo, ai, openai, anthropic, google gemini, media library, alt text, accessibility
Requires at least: 6.3
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 8.1
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Automatically generate SEO-optimized image metadata (ALT, caption, title, keywords) using AI providers (OpenAI, Anthropic, Google).

== Description ==

**Automatically generate SEO-optimized image metadata (ALT text, captions, titles, keywords) using advanced AI models.**

AI Media SEO leverages the power of OpenAI (GPT-4 Vision), Anthropic (Claude 3), and Google (Gemini Pro Vision) to analyze your images and generate high-quality, context-aware metadata. This helps improve your website's accessibility and search engine rankings without manual effort.

**Features**

*   **AI-Powered Analysis**: Uses state-of-the-art vision models to understand image content.
*   **Auto-Generated Metadata**:
    *   **ALT Text**: Descriptive and accessible alternative text.
    *   **Captions**: Engaging captions suitable for display.
    *   **Titles**: Concise and factual titles.
    *   **Keywords**: Relevant tags/keywords for internal search or SEO.
*   **Multi-Provider Support**: Choose between OpenAI, Anthropic, or Google Gemini.
*   **Multilingual**: Supports multiple languages (English, Czech, German, Slovak) and integrates with Polylang/WPML.
*   **Bulk Processing**: Process your entire media library in the background using Action Scheduler.
*   **Context Aware**: Takes into account the post title, categories, and tags where the image is used for more relevant results.
*   **Smart Resizing**: Automatically resizes large images before sending to API to save costs and bandwidth.
*   **Cost Estimation**: Tracks token usage and estimates costs for each analysis.

== Installation ==

1.  Upload the plugin files to the `/wp-content/plugins/ai-media-seo` directory, or install the plugin through the WordPress plugins screen directly.
2.  Activate the plugin through the 'Plugins' screen in WordPress.
3.  Navigate to **Settings** > **AI Media SEO** to configure your API keys and settings.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes, you need an active API key from at least one of the supported providers (OpenAI, Anthropic, or Google).

= Does it work with other languages? =

Yes, the plugin supports multiple languages including English, Czech, German, and Slovak. It also integrates with Polylang and WPML.

== Changelog ==

= 1.0.0 =
*   Initial release.
