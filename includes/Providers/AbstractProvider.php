<?php
/**
 * Abstract Provider Base Class
 *
 * Provides shared functionality for all AI provider implementations.
 *
 * @package    AIMediaSEO
 * @subpackage Providers
 * @since      1.8.0
 */

namespace AIMediaSEO\Providers;

use AIMediaSEO\Prompts\MinimalPromptBuilder;
use AIMediaSEO\Prompts\StandardPromptBuilder;
use AIMediaSEO\Prompts\AdvancedPromptBuilder;

/**
 * Abstract Provider class.
 *
 * Base class for all AI providers (OpenAI, Anthropic, Google).
 * Contains shared logic to eliminate code duplication.
 *
 * @since 1.8.0
 */
abstract class AbstractProvider implements ProviderInterface {

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Model to use.
	 *
	 * @var string
	 */
	protected $model;

	/**
	 * Path to temporary file (for cleanup).
	 *
	 * @since 1.8.0
	 * @var string|null
	 */
	protected $temp_file_path = null;

	/**
	 * Get image URL for AI analysis with AVIF handling.
	 *
	 * This method is shared across all providers.
	 *
	 * @since 1.8.0
	 * @param int $attachment_id The attachment ID.
	 * @return string Image URL.
	 */
	protected function get_image_url( int $attachment_id ): string {
		$image_data = \AIMediaSEO\Utilities\ImageSizeHelper::get_image_url_for_ai_with_avif( $attachment_id );

		// Store temp file path for cleanup.
		if ( $image_data['is_temp'] ) {
			$this->temp_file_path = $image_data['temp_path'];
		}

		return $image_data['url'];
	}

	/**
	 * Build prompt using centralized PromptBuilder.
	 *
	 * This method is shared across all providers.
	 * The only difference is the filter name, which is generated dynamically.
	 *
	 * @since 1.8.0
	 * @param string $language      The language code.
	 * @param array  $context       Additional context.
	 * @param int    $attachment_id The attachment ID (for filter).
	 * @return string The formatted prompt.
	 */
	protected function build_prompt( string $language, array $context, int $attachment_id = 0 ): string {
		$settings = get_option( 'ai_media_seo_settings', array() );

		// Get selected prompt variant (default: standard).
		$variant = $settings['prompt_variant'] ?? 'standard';

		// Select appropriate builder based on variant.
		switch ( $variant ) {
			case 'minimal':
				$builder = new MinimalPromptBuilder();
				break;
			case 'advanced':
				$builder = new AdvancedPromptBuilder();
				break;
			case 'standard':
			default:
				$builder = new StandardPromptBuilder();
				break;
		}

		$prompt = $builder->build( $language, $context, $settings );

		/**
		 * Filter the prompt before sending to AI provider.
		 *
		 * Dynamic filter name based on provider: ai_media_{provider}_prompt
		 *
		 * @since 1.8.0
		 * @param string $prompt        The prompt text.
		 * @param string $language      The language code.
		 * @param array  $context       Additional context.
		 * @param int    $attachment_id The attachment ID.
		 */
		return apply_filters( "ai_media_{$this->name}_prompt", $prompt, $language, $context, $attachment_id );
	}

	/**
	 * Track tokens and calculate costs.
	 *
	 * This method extracts token usage from API response and calculates costs.
	 * Shared across all providers.
	 *
	 * @since 1.8.0
	 * @param array  $response      API response with usage data.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $prompt        The prompt that was sent.
	 * @param array  $token_keys    Keys for token extraction from response.
	 *                              Format: ['usage_key', 'input_key', 'output_key']
	 * @return array|null Token data array or null if unavailable.
	 */
	protected function track_tokens_and_calculate_cost( array $response, int $attachment_id, string $prompt, array $token_keys = array() ): ?array {
		// Default keys if not provided.
		$usage_key  = $token_keys['usage_key'] ?? 'usage';
		$input_key  = $token_keys['input_key'] ?? 'prompt_tokens';
		$output_key = $token_keys['output_key'] ?? 'completion_tokens';

		// Extract token usage from API response.
		$usage         = $response[ $usage_key ] ?? array();
		$input_tokens  = isset( $usage[ $input_key ] ) ? (int) $usage[ $input_key ] : null;
		$output_tokens = isset( $usage[ $output_key ] ) ? (int) $usage[ $output_key ] : null;

		// Estimate input tokens if missing.
		$estimated_input = false;
		if ( null === $input_tokens ) {
			$token_estimator = new \AIMediaSEO\Pricing\TokenEstimator();
			$input_tokens    = $token_estimator->estimate_input_tokens( $attachment_id, $this->name, $prompt );
			$estimated_input = true;
		}

		// Calculate costs if we have token data.
		if ( null !== $input_tokens && null !== $output_tokens ) {
			$cost_calculator = new \AIMediaSEO\Pricing\CostCalculator();
			$cost_data       = $cost_calculator->calculate_cost( $this->model, $input_tokens, $output_tokens );

			if ( $cost_data['success'] ) {
				return array(
					'input_tokens'    => $input_tokens,
					'output_tokens'   => $output_tokens,
					'estimated_input' => $estimated_input,
					'input_cost'      => $cost_data['input_cost'],
					'output_cost'     => $cost_data['output_cost'],
					'total_cost'      => $cost_data['total_cost'],
				);
			}
		}

		return null;
	}

	/**
	 * Cleanup temporary files.
	 *
	 * Should be called in finally block of analyze() method.
	 * Shared across all providers.
	 *
	 * @since 1.8.0
	 */
	protected function cleanup_temp_files(): void {
		if ( $this->temp_file_path ) {
			\AIMediaSEO\Utilities\AvifConverter::delete_temp_jpeg( $this->temp_file_path );
			$this->temp_file_path = null;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model(): string {
		return $this->model;
	}
}
