# AI Media SEO - WordPress Plugin

**Automatically generate SEO-optimized image metadata (ALT text, captions, titles, keywords) using advanced AI models.**

AI Media SEO leverages the power of OpenAI (GPT-4 Vision), Anthropic (Claude 3), and Google (Gemini Pro Vision) to analyze your images and generate high-quality, context-aware metadata. This helps improve your website's accessibility and search engine rankings without manual effort.

## 🚀 Features

-   **AI-Powered Analysis**: Uses state-of-the-art vision models to understand image content.
-   **Auto-Generated Metadata**:
    -   **ALT Text**: Descriptive and accessible alternative text.
    -   **Captions**: Engaging captions suitable for display.
    -   **Titles**: Concise and factual titles.
    -   **Keywords**: Relevant tags/keywords for internal search or SEO.
-   **Multi-Provider Support**: Choose between OpenAI, Anthropic, or Google Gemini.
-   **Multilingual**: Supports multiple languages (English, Czech, German, Slovak) and integrates with Polylang/WPML.
-   **Bulk Processing**: Process your entire media library in the background using Action Scheduler.
-   **Context Aware**: Takes into account the post title, categories, and tags where the image is used for more relevant results.
-   **Smart Resizing**: Automatically resizes large images before sending to API to save costs and bandwidth.
-   **Cost Estimation**: Tracks token usage and estimates costs for each analysis.

## 📋 Requirements

-   **WordPress**: 6.3 or higher
-   **PHP**: 8.1 or higher
-   **API Key**: An active API key from at least one provider (OpenAI, Anthropic, or Google).

## 🛠️ Installation

### Option 1: Manual Installation (Zip)

1.  Download the repository as a ZIP file.
2.  Log in to your WordPress admin dashboard.
3.  Go to **Plugins** > **Add New** > **Upload Plugin**.
4.  Select the downloaded ZIP file and click **Install Now**.
5.  Click **Activate**.

### Option 2: Composer (For Developers)

If you are managing your WordPress site with Composer, you can install the plugin by adding it to your `composer.json` (assuming you have a custom repository setup or are cloning this into `wp-content/plugins`).

```bash
composer install
```

*Note: You must run `composer install` within the plugin directory to install dependencies (Action Scheduler) if you are cloning the repo directly.*

## ⚙️ Configuration

1.  Navigate to **Settings** > **AI Media SEO** in your WordPress admin.
2.  **General Settings**:
    -   Set your **Primary Language**.
    -   Configure **Batch Size** and **Rate Limits** for background processing.
3.  **Providers**:
    -   Enter your API keys for the providers you wish to use (OpenAI, Anthropic, Google).
    -   Select the specific model (e.g., `gpt-4o`, `claude-3-opus`, `gemini-pro-vision`).
    -   Enable the provider.
4.  **Quality Rules**:
    -   Set minimum quality scores.
    -   Define forbidden phrases for ALT text (e.g., "image of", "photo of").

## 💡 Usage

### Single Image Analysis
1.  Go to **Media** > **Library**.
2.  Click on an image to open the details modal (or use the list view).
3.  Look for the **AI Media SEO** sidebar or action button.
4.  Click **Analyze** to generate metadata.
5.  Review the generated suggestions and click **Apply** to save them.

### Bulk Processing
1.  Go to **Media** > **AI Media SEO Dashboard**.
2.  View statistics about your library (Total images, Missing ALT text, etc.).
3.  Start a bulk process to analyze images that are missing metadata.
4.  The plugin will process images in the background. You can monitor progress on the dashboard.

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1.  Fork the repository.
2.  Create your feature branch (`git checkout -b feature/AmazingFeature`).
3.  Commit your changes (`git commit -m 'Add some AmazingFeature'`).
4.  Push to the branch (`git push origin feature/AmazingFeature`).
5.  Open a Pull Request.

## 📄 License

This project is licensed under the GNU General Public License v3.0. See the [LICENSE](LICENSE) file for details.
