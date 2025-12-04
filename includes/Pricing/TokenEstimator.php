<?php
/**
 * Token Estimator
 *
 * Estimates input tokens for AI image processing when API doesn't return them.
 *
 * @package    AIMediaSEO
 * @subpackage Pricing
 * @since      1.1.0
 */

namespace AIMediaSEO\Pricing;

/**
 * TokenEstimator class.
 *
 * Provider-specific token estimation algorithms.
 *
 * @since 1.1.0
 */
class TokenEstimator {

	/**
	 * Estimate input tokens for image analysis.
	 *
	 * @since 1.1.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $provider      Provider name (openai, anthropic, google).
	 * @param string $prompt        Text prompt sent with image.
	 * @return int Estimated token count.
	 */
	public function estimate_input_tokens( int $attachment_id, string $provider, string $prompt = '' ): int {
		// Get image dimensions.
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! isset( $metadata['width'], $metadata['height'] ) ) {
			return 0;
		}

		$width  = (int) $metadata['width'];
		$height = (int) $metadata['height'];

		// Calculate image tokens based on provider.
		$image_tokens = $this->estimate_image_tokens( $width, $height, $provider );

		// Calculate text prompt tokens.
		$text_tokens = $this->estimate_text_tokens( $prompt );

		return $image_tokens + $text_tokens;
	}

	/**
	 * Estimate image tokens based on dimensions and provider.
	 *
	 * @since 1.1.0
	 * @param int    $width    Image width in pixels.
	 * @param int    $height   Image height in pixels.
	 * @param string $provider Provider name.
	 * @return int Estimated token count.
	 */
	private function estimate_image_tokens( int $width, int $height, string $provider ): int {
		switch ( strtolower( $provider ) ) {
			case 'openai':
				return $this->estimate_openai_tokens( $width, $height );

			case 'anthropic':
				return $this->estimate_anthropic_tokens( $width, $height );

			case 'google':
				return $this->estimate_google_tokens( $width, $height );

			default:
				return 0;
		}
	}

	/**
	 * Estimate tokens for OpenAI (GPT-4 Vision / GPT-4o).
	 *
	 * Formula: 85 + 170 * tiles
	 * Tiles = ceil(width/512) * ceil(height/512)
	 * Max dimension scaled to 2048px, min to 768px
	 *
	 * @since 1.1.0
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return int Token count.
	 */
	private function estimate_openai_tokens( int $width, int $height ): int {
		// Scale to max 2048px.
		if ( $width > 2048 || $height > 2048 ) {
			$scale  = min( 2048 / $width, 2048 / $height );
			$width  = (int) ( $width * $scale );
			$height = (int) ( $height * $scale );
		}

		// Scale min dimension to 768px.
		$min_dim = min( $width, $height );
		if ( $min_dim > 768 ) {
			$scale  = 768 / $min_dim;
			$width  = (int) ( $width * $scale );
			$height = (int) ( $height * $scale );
		}

		// Calculate tiles (512x512 each).
		$tiles_x = (int) ceil( $width / 512 );
		$tiles_y = (int) ceil( $height / 512 );
		$tiles   = $tiles_x * $tiles_y;

		// 170 tokens per tile + 85 base.
		return 85 + ( 170 * $tiles );
	}

	/**
	 * Estimate tokens for Anthropic (Claude).
	 *
	 * Formula: (width * height) / 750
	 * Max dimension: 1568px (auto-scaled)
	 * Max tokens per image: ~1600
	 *
	 * @since 1.1.0
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return int Token count.
	 */
	private function estimate_anthropic_tokens( int $width, int $height ): int {
		// Scale if larger than 1568px.
		if ( $width > 1568 || $height > 1568 ) {
			$scale  = min( 1568 / $width, 1568 / $height );
			$width  = (int) ( $width * $scale );
			$height = (int) ( $height * $scale );
		}

		// Calculate tokens.
		$tokens = (int) ( ( $width * $height ) / 750 );

		// Cap at ~1600 tokens.
		return min( $tokens, 1600 );
	}

	/**
	 * Estimate tokens for Google (Gemini).
	 *
	 * Each 768x768 tile = 258 tokens
	 * Images scaled/cropped to tiles
	 *
	 * @since 1.1.0
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return int Token count.
	 */
	private function estimate_google_tokens( int $width, int $height ): int {
		// Calculate tiles (768x768 each).
		$tiles_x = (int) ceil( $width / 768 );
		$tiles_y = (int) ceil( $height / 768 );
		$tiles   = $tiles_x * $tiles_y;

		// 258 tokens per tile.
		return 258 * $tiles;
	}

	/**
	 * Estimate text prompt tokens.
	 *
	 * Rough estimation: 1 token ≈ 4 characters
	 * This is a simplified approach. For more accuracy, could use
	 * tiktoken for OpenAI or provider-specific tokenizers.
	 *
	 * @since 1.1.0
	 * @param string $text Prompt text.
	 * @return int Token count.
	 */
	private function estimate_text_tokens( string $text ): int {
		if ( empty( $text ) ) {
			return 0;
		}

		// Simple estimation: 4 chars = 1 token.
		return (int) ceil( mb_strlen( $text ) / 4 );
	}

	/**
	 * Get estimated token count with breakdown.
	 *
	 * Returns detailed breakdown of image and text tokens.
	 *
	 * @since 1.1.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $provider      Provider name.
	 * @param string $prompt        Text prompt.
	 * @return array Token breakdown.
	 */
	public function get_token_breakdown( int $attachment_id, string $provider, string $prompt = '' ): array {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! isset( $metadata['width'], $metadata['height'] ) ) {
			return array(
				'total'       => 0,
				'image'       => 0,
				'text'        => 0,
				'dimensions'  => null,
				'error'       => 'Missing image dimensions',
			);
		}

		$width  = (int) $metadata['width'];
		$height = (int) $metadata['height'];

		$image_tokens = $this->estimate_image_tokens( $width, $height, $provider );
		$text_tokens  = $this->estimate_text_tokens( $prompt );

		return array(
			'total'       => $image_tokens + $text_tokens,
			'image'       => $image_tokens,
			'text'        => $text_tokens,
			'dimensions'  => array(
				'width'  => $width,
				'height' => $height,
			),
		);
	}
}
