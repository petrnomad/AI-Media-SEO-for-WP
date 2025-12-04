<?php
/**
 * Provider Factory
 *
 * Factory class for creating and managing AI providers.
 *
 * @package    AIMediaSEO
 * @subpackage Providers
 * @since      1.0.0
 */

namespace AIMediaSEO\Providers;

/**
 * ProviderFactory class.
 *
 * Creates provider instances and manages fallback logic.
 *
 * @since 1.0.0
 */
class ProviderFactory {

	/**
	 * Available provider classes.
	 *
	 * @var array
	 */
	private static $provider_classes = array(
		'openai'     => OpenAIProvider::class,
		'anthropic'  => AnthropicProvider::class,
		'google'     => GoogleProvider::class,
	);

	/**
	 * Provider configurations.
	 *
	 * @var array
	 */
	private $providers_config;

	/**
	 * Fallback order.
	 *
	 * @var array
	 */
	private $fallback_order;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->load_config();
	}

	/**
	 * Load provider configurations.
	 *
	 * @since 1.0.0
	 */
	private function load_config(): void {
		$this->providers_config = get_option( 'ai_media_seo_providers', array() );
		$this->fallback_order   = get_option( 'ai_media_seo_fallback_order', array( 'openai', 'anthropic', 'google' ) );
	}

	/**
	 * Create provider instance.
	 *
	 * @since 1.0.0
	 * @param string $provider_name Provider name (openai, anthropic, google).
	 * @return ProviderInterface|null Provider instance or null if not available.
	 */
	public function create( string $provider_name ): ?ProviderInterface {

		if ( ! isset( self::$provider_classes[ $provider_name ] ) ) {
			return null;
		}

		$config = $this->providers_config[ $provider_name ] ?? array();

		// Check if provider is configured.
		if ( empty( $config['api_key'] ) ) {
			return null;
		}

		$class = self::$provider_classes[ $provider_name ];

		try {
			$instance = new $class( $config );
			return $instance;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Get primary provider.
	 *
	 * Returns the provider marked as primary in settings.
	 * Falls back to first configured provider if no primary is set.
	 *
	 * @since 1.0.0
	 * @return ProviderInterface|null
	 */
	public function get_primary(): ?ProviderInterface {

		// Find provider marked as primary.
		foreach ( array_keys( self::$provider_classes ) as $provider_name ) {

			if ( ! empty( $this->providers_config[ $provider_name ]['primary'] ) ) {
				$provider = $this->create( $provider_name );

				if ( $provider ) {
					return $provider;
				} else {
				}
			}
		}

		// Fallback to first available provider.
		$fallback = $this->get_with_fallback();

		if ( $fallback ) {
		} else {
		}

		return $fallback;
	}

	/**
	 * Get provider with fallback.
	 *
	 * Returns the first available and configured provider from fallback order.
	 *
	 * @since 1.0.0
	 * @return ProviderInterface|null
	 */
	public function get_with_fallback(): ?ProviderInterface {
		foreach ( $this->fallback_order as $provider_name ) {
			$provider = $this->create( $provider_name );
			if ( $provider ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Get all available providers.
	 *
	 * @since 1.0.0
	 * @return array Array of provider instances.
	 */
	public function get_all_available(): array {
		$providers = array();

		foreach ( array_keys( self::$provider_classes ) as $provider_name ) {
			$provider = $this->create( $provider_name );
			if ( $provider ) {
				$providers[ $provider_name ] = $provider;
			}
		}

		return $providers;
	}

	/**
	 * Test provider connection.
	 *
	 * @since 1.0.0
	 * @param string $provider_name Provider name.
	 * @return bool|string True if successful, error message on failure.
	 */
	public function test_provider( string $provider_name ) {
		$provider = $this->create( $provider_name );

		if ( ! $provider ) {
			return __( 'Provider not configured.', 'ai-media-seo' );
		}

		try {
			$provider->test_connection();
			return true;
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}
	}

	/**
	 * Get configured providers list.
	 *
	 * @since 1.0.0
	 * @return array Array of configured provider names.
	 */
	public function get_configured_providers(): array {
		$configured = array();

		foreach ( array_keys( self::$provider_classes ) as $provider_name ) {
			if ( ! empty( $this->providers_config[ $provider_name ]['api_key'] ) ) {
				$configured[] = $provider_name;
			}
		}

		return $configured;
	}

	/**
	 * Set fallback order.
	 *
	 * @since 1.0.0
	 * @param array $order Array of provider names in order.
	 * @return bool True on success.
	 */
	public function set_fallback_order( array $order ): bool {
		// Validate that all providers exist.
		foreach ( $order as $provider_name ) {
			if ( ! isset( self::$provider_classes[ $provider_name ] ) ) {
				return false;
			}
		}

		$this->fallback_order = $order;
		return update_option( 'ai_media_seo_fallback_order', $order );
	}

	/**
	 * Get fallback order.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_fallback_order(): array {
		return $this->fallback_order;
	}

	/**
	 * Analyze with fallback.
	 *
	 * Tries providers in fallback order until one succeeds.
	 *
	 * @since 1.0.0
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language      Language code.
	 * @param array  $context       Additional context.
	 * @return array {
	 *     @type bool   $success  Whether analysis succeeded.
	 *     @type array  $metadata Metadata if successful.
	 *     @type string $provider Provider that succeeded.
	 *     @type array  $errors   Errors from failed attempts.
	 * }
	 */
	public function analyze_with_fallback( int $attachment_id, string $language, array $context ): array {
		$errors = array();

		foreach ( $this->fallback_order as $provider_name ) {
			$provider = $this->create( $provider_name );

			if ( ! $provider ) {
				$errors[ $provider_name ] = __( 'Provider not configured.', 'ai-media-seo' );
				continue;
			}

			try {
				$metadata = $provider->analyze( $attachment_id, $language, $context );

				return array(
					'success'  => true,
					'metadata' => $metadata,
					'provider' => $provider_name,
					'model'    => $provider->get_model(),
					'errors'   => array(),
				);
			} catch ( \Exception $e ) {
				$errors[ $provider_name ] = $e->getMessage();
				continue;
			}
		}

		return array(
			'success'  => false,
			'metadata' => array(),
			'provider' => null,
			'model'    => null,
			'errors'   => $errors,
		);
	}

	/**
	 * Get available provider names.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public static function get_available_provider_names(): array {
		return array_keys( self::$provider_classes );
	}

	/**
	 * Get provider display name.
	 *
	 * @since 1.0.0
	 * @param string $provider_name Provider name.
	 * @return string
	 */
	public static function get_provider_display_name( string $provider_name ): string {
		$names = array(
			'openai'     => 'OpenAI (GPT-4)',
			'anthropic'  => 'Anthropic (Claude)',
			'google'     => 'Google (Gemini)',
		);

		return $names[ $provider_name ] ?? $provider_name;
	}

	/**
	 * Get provider models.
	 *
	 * @since 1.0.0
	 * @param string $provider_name Provider name.
	 * @return array
	 */
	public static function get_provider_models( string $provider_name ): array {
		$models = array(
			'openai'    => array(
				'gpt-4o'      => 'GPT-4o (Recommended)',
				'gpt-4o-mini' => 'GPT-4o Mini (Cheaper)',
				'gpt-4-turbo' => 'GPT-4 Turbo',
			),
			'anthropic' => array(
				'claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5 (Recommended)',
				'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5 (Cheaper)',
				'claude-opus-4-1-20250805'   => 'Claude Opus 4.1',
				'claude-3-5-haiku-20241022'  => 'Claude 3.5 Haiku (Legacy)',
			),
			'google'    => array(
				'gemini-1.5-flash-8b' => 'Gemini 1.5 Flash-8B (Ultra Cheap - $0.0375/$0.15)',
				'gemini-1.5-flash'    => 'Gemini 1.5 Flash (Recommended - $0.075/$0.30)',
				'gemini-2.0-flash'    => 'Gemini 2.0 Flash ($0.10/$0.40)',
				'gemini-2.5-flash'    => 'Gemini 2.5 Flash (Enhanced - $0.30/$2.50)',
				'gemini-2.5-pro'      => 'Gemini 2.5 Pro ($1.25/$10.00)',
			),
		);

		return $models[ $provider_name ] ?? array();
	}
}
