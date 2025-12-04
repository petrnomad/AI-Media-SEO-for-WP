# AI Media SEO - WordPress Plugin

[![WordPress Plugin Version](https://img.shields.io/badge/WordPress-6.3%2B-blue.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v3-green.svg)](https://www.gnu.org/licenses/gpl-3.0.html)

Automatically generate SEO-optimized image metadata (ALT text, captions, titles, keywords) using AI providers (OpenAI, Anthropic, Google Gemini). Built with multilingual support for Polylang and WPML.

## ✨ Features

### Core Features
- 🤖 **Multiple AI Providers**: OpenAI (GPT-4o, GPT-4-Turbo), Anthropic (Claude 3.5 Sonnet), Google (Gemini 1.5 Pro)
- 🌍 **Multilingual Support**: Native integration with Polylang and WPML
- 📝 **Complete Metadata**: Generate ALT text, captions, titles, and keywords
- 🎯 **Quality Validation**: Automatic quality scoring and validation rules
- 🔄 **Batch Processing**: Process multiple images with progress tracking
- 📊 **Cost Tracking**: Real-time token usage and cost calculation
- 🖼️ **AVIF Support**: Automatic conversion for modern image formats
- ⚡ **Background Processing**: Non-blocking batch operations
- 🎨 **React-based UI**: Modern, responsive admin interface

### Advanced Features
- 🔍 **Context Detection**: Smart context from parent posts, categories, and tags
- 🎚️ **Auto-Approve Thresholds**: Automatic approval for high-quality results (≥85% score)
- 📈 **Analytics Dashboard**: Track processing stats, costs, and success rates
- 🔌 **REST API**: Full API for programmatic access
- 💻 **WP-CLI Support**: Command-line tools for automation
- 🎭 **Three Prompt Variants**: Minimal, Standard, Advanced (optimized for different use cases)
- 🛡️ **Security**: Comprehensive security audits and safe processing
- 📋 **Audit Logs**: Complete processing history and error tracking

## 📋 Requirements

- **WordPress**: 6.3 or higher
- **PHP**: 8.1, 8.2, or 8.3
- **MySQL**: 5.7+ or MariaDB 10.3+
- **Image Library**: Imagick or GD with AVIF support (recommended)
- **Memory**: Minimum 256MB PHP memory limit
- **API Key**: OpenAI, Anthropic, or Google Gemini account

## 🚀 Installation

### From GitHub

```bash
cd wp-content/plugins
git clone https://github.com/petrnomad/AI-Media-SEO-for-WP.git ai-media-seo
cd ai-media-seo
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

### Manual Installation

1. Download the latest release ZIP file
2. Upload to WordPress via **Plugins > Add New > Upload Plugin**
3. Activate the plugin
4. Navigate to **AI Media > Settings** to configure

## ⚙️ Configuration

### 1. API Provider Setup

#### OpenAI (Recommended)
1. Get API key from [OpenAI Platform](https://platform.openai.com/api-keys)
2. Navigate to **AI Media > Settings > Providers**
3. Enter API key and select model (recommended: `gpt-4o`)
4. Save settings

#### Anthropic Claude
1. Get API key from [Anthropic Console](https://console.anthropic.com/)
2. Configure in **Settings > Providers**
3. Select model (recommended: `claude-3-5-sonnet-20241022`)

#### Google Gemini
1. Get API key from [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Configure in **Settings > Providers**
3. Select model (recommended: `gemini-1.5-pro`)

### 2. Quality Settings

Configure in **Settings > Quality Rules**:

- **ALT Text**: Max 125 characters, descriptive, keyword-rich
- **Caption**: 1-2 sentences with context
- **Title**: 3-6 words, factual description
- **Keywords**: 3-6 relevant SEO terms
- **Auto-approve threshold**: 85% (recommended)

### 3. Prompt Variants

Choose processing mode in **Settings > Prompts**:

- **Minimal** (100-150 tokens): Fast, basic metadata
- **Standard** (200-300 tokens): Balanced quality/cost (recommended)
- **Advanced** (400-500 tokens): Maximum quality with detailed instructions

## 📖 Usage

### Single Image Analysis

1. Go to **Media Library**
2. Click on an image
3. Click **Analyze with AI** button
4. Review generated metadata
5. **High scores (≥85%)**: Auto-applied
6. **Low scores (<85%)**: Saved as draft for review
7. Approve or edit as needed

### Batch Processing

1. Navigate to **AI Media > Library**
2. Select images using checkboxes
3. Click **Analyze Selected**
4. Monitor progress in real-time modal
5. Review results and approve drafts

### WP-CLI Commands

```bash
# Scan for images missing metadata
wp ai-media scan --language=cs --missing=alt

# Analyze specific images
wp ai-media analyze --ids=123,456,789 --language=cs --provider=openai

# Analyze all unprocessed images
wp ai-media analyze --language=cs

# Get processing statistics
wp ai-media stats --period=month

# Reset metadata for specific image
wp ai-media reset --attachment-id=123 --language=cs

# Run database migrations
wp ai-media migrate

# Sync AI model pricing data
wp ai-media sync-pricing
```

### REST API

```php
// Analyze images
POST /wp-json/ai-media/v1/analyze
Content-Type: application/json
{
  "attachment_ids": [123, 456],
  "language": "cs"
}

// Process single image
POST /wp-json/ai-media/v1/process-single
{
  "attachment_id": 123,
  "language": "en",
  "auto_apply": true,
  "force_reprocess": false
}

// Batch analyze
POST /wp-json/ai-media/v1/batch-analyze
{
  "mode": "selected",
  "attachment_ids": [123, 456, 789]
}

// Get processing statistics
GET /wp-json/ai-media/v1/stats?period=week

// Get media library
GET /wp-json/ai-media/v1/library?page=1&per_page=20&language=cs
```

## 🔧 Developer Hooks

### Actions

```php
// Before analysis starts
add_action('ai_media_before_analyze', function($attachment_id, $language, $options) {
    error_log("Starting analysis for image $attachment_id");
}, 10, 3);

// After analysis completes
add_action('ai_media_after_analyze', function($result) {
    if ($result['success']) {
        // Custom processing
    }
}, 10, 1);

// Before applying metadata
add_action('ai_media_before_apply_metadata', function($attachment_id, $language, $metadata) {
    // Modify or log metadata before saving
}, 10, 3);

// After applying metadata
add_action('ai_media_after_apply_metadata', function($attachment_id, $language, $metadata) {
    // Trigger custom events
}, 10, 3);
```

### Filters

```php
// Modify analysis context
add_filter('ai_media_context', function($context, $attachment_id, $language) {
    $context['custom_field'] = get_post_meta($attachment_id, 'custom_key', true);
    return $context;
}, 10, 3);

// Modify OpenAI prompt
add_filter('ai_media_openai_prompt', function($prompt, $language, $context, $attachment_id) {
    $prompt .= "\n\nAdditional instructions: Focus on product features.";
    return $prompt;
}, 10, 4);

// Modify Anthropic prompt
add_filter('ai_media_anthropic_prompt', function($prompt, $language, $context, $attachment_id) {
    return $prompt;
}, 10, 4);

// Modify Google prompt
add_filter('ai_media_google_prompt', function($prompt, $language, $context, $attachment_id) {
    return $prompt;
}, 10, 4);

// Modify auto-approve threshold
add_filter('ai_media_auto_approve_threshold', function($threshold) {
    return 90; // Increase to 90%
});
```

## 🏗️ Architecture

### Technology Stack
- **Backend**: PHP 8.1+ with PSR-4 autoloading
- **Frontend**: React 18 + WordPress Components
- **Build**: Webpack 5 + Babel
- **Database**: Custom tables with WordPress $wpdb
- **API**: WordPress REST API
- **CLI**: WP-CLI integration
- **Testing**: PHPUnit + Jest

### Project Structure
```
ai-media-seo/
├── admin/                  # React admin interface
│   ├── src/               # Source JSX/SCSS
│   └── build/             # Compiled assets
├── assets/                # Static assets
├── includes/              # PHP classes (PSR-4)
│   ├── Analyzer/         # Image analysis logic
│   ├── API/              # REST controllers
│   ├── CLI/              # WP-CLI commands
│   ├── Providers/        # AI provider integrations
│   ├── Queue/            # Processing synchronizer
│   ├── Storage/          # Database operations
│   └── Utilities/        # Helper classes
├── languages/             # Translation files
├── tests/                 # Test suites
├── vendor/                # Composer dependencies
├── build.sh              # Production build script
└── ai-media-seo.php      # Main plugin file
```

## 🧪 Testing

```bash
# PHP tests
composer test

# JavaScript tests
npm test

# Code style
composer phpcs
npm run lint

# Auto-fix code style
composer phpcbf
npm run lint:fix
```

## 📊 Cost Estimates

Approximate costs per 1,000 images (as of January 2025):

| Provider | Model | Prompt Variant | Cost |
|----------|-------|----------------|------|
| OpenAI | GPT-4o | Minimal | ~$2-3 |
| OpenAI | GPT-4o | Standard | ~$4-6 |
| OpenAI | GPT-4o | Advanced | ~$8-12 |
| Anthropic | Claude 3.5 Sonnet | Standard | ~$6-9 |
| Google | Gemini 1.5 Pro | Standard | ~$1-2 |

*Costs vary based on image size and prompt complexity*

## 🔐 Security

- All API keys stored encrypted in database
- CSRF protection on all forms
- Nonce verification for AJAX requests
- Capability checks for all operations
- Input sanitization and validation
- SQL injection prevention via prepared statements
- XSS protection on all outputs
- Regular security audits

See [SECURITY.md](SECURITY.md) for vulnerability reporting.

## 🐛 Troubleshooting

### Images Not Processing

**Symptoms**: Analysis fails or shows errors

**Solutions**:
1. Verify API key is valid and has credits
2. Check PHP error log for detailed errors
3. Ensure image file exists and is readable
4. Verify GD/Imagick extension is installed
5. Check memory limit (minimum 256MB)

### AVIF Format Issues

**Symptoms**: "Unsupported format" errors for AVIF images

**Solutions**:
1. Ensure GD 2.3+ with AVIF support OR ImageMagick with AVIF delegate
2. Run diagnostic: `wp ai-media check-avif`
3. Plugin auto-converts AVIF to JPEG before AI analysis

### Low Quality Results

**Symptoms**: Generated metadata is not relevant

**Solutions**:
1. Attach images to posts for better context
2. Configure site topic in settings
3. Use Advanced prompt variant
4. Lower auto-approve threshold to review manually
5. Add custom context via filters

### Rate Limiting

**Symptoms**: "Rate limit exceeded" errors

**Solutions**:
1. Reduce batch size in processing
2. Increase delay between requests
3. Upgrade API plan with provider
4. Use multiple providers with fallback

### Memory Issues

**Symptoms**: "Allowed memory size exhausted" errors

**Solutions**:
```php
// In wp-config.php
define('WP_MEMORY_LIMIT', '512M');
define('WP_MAX_MEMORY_LIMIT', '512M');
```

## 📝 Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## 📄 License

This plugin is licensed under the [GNU General Public License v3.0 or later](LICENSE).

## 👥 Credits

**Author**: Petr Novák
**Website**: [petrnovak.com](https://petrnovak.com)
**Email**: jsem@petrnovak.com

### Built With
- [WordPress](https://wordpress.org/)
- [React](https://react.dev/)
- [OpenAI API](https://platform.openai.com/)
- [Anthropic Claude API](https://www.anthropic.com/)
- [Google Gemini API](https://ai.google.dev/)
- [Action Scheduler](https://actionscheduler.org/)

## ⚠️ Disclaimer

This plugin requires an API key from OpenAI, Anthropic, or Google. API usage costs are your responsibility. The plugin author is not responsible for any costs incurred through API usage.

## 🔗 Links

- [Documentation](docs/)
- [Issue Tracker](https://github.com/petrnomad/AI-Media-SEO-for-WP/issues)
- [Changelog](CHANGELOG.md)
- [Security Policy](SECURITY.md)

---

Made with ❤️ for the WordPress community
