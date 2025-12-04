# AI Media SEO - WordPress Plugin

Automatically generate SEO-optimized image metadata (ALT text, captions, titles, keywords) using AI providers (OpenAI, Anthropic, Google). Built with multilingual support for Polylang/WPML.

## Features

### Lite Version (Free)
- ✅ ALT text generation
- ✅ Single language support
- ✅ Manual processing (button per image)
- ✅ Daily limit: 25 images
- ✅ Basic validation
- ✅ Single provider configuration

### Pro Version
- ✅ ALT + Caption + Title + Keywords
- ✅ Unlimited languages
- ✅ Batch processing with queue
- ✅ Auto-approve with score threshold
- ✅ Playground for prompt testing
- ✅ Multiple providers & fallback
- ✅ Audit logs & history
- ✅ WP-CLI commands
- ✅ Smart context for unattached images
- ✅ Collections & rules engine

## Requirements

- WordPress 6.3+
- PHP 8.1-8.3
- MySQL 5.7+ / MariaDB 10.3+
- Imagick or GD library

## Installation

1. Upload the plugin files to `/wp-content/plugins/ai-media-seo/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **AI Media > Settings** to configure your API keys
4. Start analyzing your images!

## Configuration

### OpenAI Setup

1. Get your API key from [OpenAI Platform](https://platform.openai.com/)
2. Navigate to **AI Media > Settings > Providers**
3. Enter your OpenAI API key
4. Select model (recommended: gpt-4o)
5. Save settings

### Quality Rules

Configure validation rules in **Settings > Quality Rules**:

- **ALT Text**: Max 125 characters, no forbidden phrases
- **Caption**: 1-2 sentences, contextual
- **Title**: 3-6 words, factual
- **Keywords**: 3-6 relevant terms
- **Auto-approve threshold**: Set minimum score for automatic approval

## Usage

### Single Image Processing

1. Go to **Media Library**
2. Click on an image
3. Click **Generate AI Metadata** button
4. Review the generated metadata
5. Click **Approve** to apply

### Batch Processing (Pro)

1. Navigate to **AI Media > Library**
2. Select multiple images
3. Choose language
4. Click **Analyze Selected**
5. Review results in **Queue** page
6. Bulk approve or reject

### REST API

```php
// Analyze images
POST /wp-json/ai-media/v1/analyze
{
  "attachment_ids": [123, 456],
  "language": "cs"
}

// Approve metadata
POST /wp-json/ai-media/v1/approve
{
  "job_id": 789,
  "fields": ["alt", "caption", "title"]
}

// Get statistics
GET /wp-json/ai-media/v1/stats
```

## WP-CLI Commands (Pro)

```bash
# Scan for images missing ALT text
wp ai-media scan --language=cs --missing=alt

# Analyze specific images
wp ai-media analyze --ids=1,2,3 --language=cs

# Approve batch
wp ai-media approve --batch-id=123

# Get statistics
wp ai-media stats --month=2025-01

# Reset image metadata
wp ai-media reset --attachment-id=456
```

## Hooks & Filters

### Actions

```php
// Before analysis starts
add_action( 'ai_media_before_analyze', function( $attachment_id, $language, $options ) {
    // Custom logic
}, 10, 3 );

// After analysis completes
add_action( 'ai_media_after_analyze', function( $result ) {
    // Custom logic
}, 10, 1 );

// Before applying metadata
add_action( 'ai_media_before_apply_metadata', function( $attachment_id, $language, $metadata ) {
    // Custom logic
}, 10, 3 );

// After applying metadata
add_action( 'ai_media_after_apply_metadata', function( $attachment_id, $language, $metadata ) {
    // Custom logic
}, 10, 3 );
```

### Filters

```php
// Modify context before analysis
add_filter( 'ai_media_context', function( $context, $attachment_id, $language ) {
    $context['custom_field'] = 'Custom value';
    return $context;
}, 10, 3 );

// Modify OpenAI prompt
add_filter( 'ai_media_openai_prompt', function( $prompt, $language, $context ) {
    $prompt .= "\nAdditional instructions...";
    return $prompt;
}, 10, 3 );
```

## Troubleshooting

### Images not processing

1. Check your API key is valid
2. Verify you haven't hit daily limits
3. Check error logs in **AI Media > Logs**
4. Ensure image file exists and is accessible

### Poor quality results

1. Lower auto-approve threshold in settings
2. Provide better context (attach images to posts)
3. Configure site topic in settings
4. Use custom prompts (Pro)

### Rate limiting

1. Reduce batch size in settings
2. Increase delay between requests
3. Use multiple API keys with fallback (Pro)

## Privacy & Data

- **Your API keys** are stored encrypted in your WordPress database
- **No data is sent** to our servers - all processing happens between your server and the AI provider
- **Image analysis** is performed by the AI provider you configure
- **Audit logs** are stored locally in your database

## Support

- Documentation: [https://docs.aimediaseo.com](https://docs.aimediaseo.com)
- GitHub Issues: [https://github.com/aimediaseo/ai-media-seo](https://github.com/aimediaseo/ai-media-seo)
- Support Email: support@aimediaseo.com

## License

This plugin is licensed under the GPL v3 or later.

## Credits

Developed by AI Media SEO Team

---

**Note**: This plugin requires an API key from OpenAI, Anthropic, or Google. API usage costs are your responsibility.
