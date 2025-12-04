<?php
/**
 * Provider Interface
 *
 * Defines the contract for AI provider implementations.
 *
 * @package    AIMediaSEO
 * @subpackage Providers
 * @since      1.0.0
 */

namespace AIMediaSEO\Providers;

/**
 * Provider Interface.
 *
 * All AI providers (OpenAI, Anthropic, Google) must implement this interface.
 *
 * @since 1.0.0
 */
interface ProviderInterface {

	/**
	 * Analyze image and return metadata.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id The WordPress attachment ID.
	 * @param string $language      The language code (e.g., 'cs', 'en').
	 * @param array  $context       Additional context for analysis.
	 * @return array {
	 *     Analysis results.
	 *
	 *     @type string $alt      The generated ALT text.
	 *     @type string $caption  The generated caption.
	 *     @type string $title    The generated title.
	 *     @type array  $keywords Array of keywords.
	 *     @type float  $score    Quality score (0.0-1.0).
	 * }
	 * @throws \Exception When analysis fails.
	 */
	public function analyze( int $attachment_id, string $language, array $context ): array;

	/**
	 * Validate provider configuration.
	 *
	 * Checks if API key is valid and provider is accessible.
	 *
	 * @since 1.0.0
	 * @return bool True if configuration is valid.
	 * @throws \Exception When validation fails.
	 */
	public function validate_config(): bool;

	/**
	 * Estimate cost for processing.
	 *
	 * @since 1.0.0
	 * @param int $tokens Estimated number of tokens.
	 * @return float Cost in cents.
	 */
	public function estimate_cost( int $tokens ): float;

	/**
	 * Get model capabilities.
	 *
	 * Returns information about what this model/provider can do.
	 *
	 * @since 1.0.0
	 * @return array {
	 *     Model capabilities.
	 *
	 *     @type bool   $supports_vision   Whether model supports image analysis.
	 *     @type bool   $supports_json     Whether model supports JSON output.
	 *     @type int    $max_tokens        Maximum tokens per request.
	 *     @type int    $max_image_size    Maximum image size in pixels.
	 *     @type array  $supported_formats Supported image formats.
	 * }
	 */
	public function get_model_capabilities(): array;

	/**
	 * Get provider name.
	 *
	 * @since 1.0.0
	 * @return string Provider name (e.g., 'openai', 'anthropic', 'google').
	 */
	public function get_name(): string;

	/**
	 * Get current model being used.
	 *
	 * @since 1.0.0
	 * @return string Model identifier (e.g., 'gpt-4-vision', 'claude-3-opus').
	 */
	public function get_model(): string;

	/**
	 * Set API configuration.
	 *
	 * @since 1.0.0
	 * @param array $config Configuration array with api_key, model, etc.
	 */
	public function set_config( array $config ): void;

	/**
	 * Test connection to provider.
	 *
	 * Makes a simple API call to verify connectivity.
	 *
	 * @since 1.0.0
	 * @return bool True if connection successful.
	 * @throws \Exception When connection fails.
	 */
	public function test_connection(): bool;
}
