<?php
/**
 * TierDetector - Detekuje API tier pro jednotlivé providery
 *
 * @package AIMediaSEO
 * @since 2.2.0
 */

namespace AIMediaSEO\Providers;

/**
 * Detekuje tier uživatele u jednotlivých API providerů
 */
class TierDetector {

	/**
	 * Tier cache (uloženo v transientu na 24h)
	 *
	 * @var array
	 */
	private static $tier_cache = array();

	/**
	 * Detekuje tier pro všechny nakonfigurované providery
	 *
	 * @return array ['provider' => ['tier' => string, 'rpm' => int, 'confidence' => float]]
	 */
	public static function detect_all_tiers(): array {
		$providers_config = get_option( 'ai_media_seo_providers', array() );
		$results          = array();

		foreach ( $providers_config as $provider_name => $config ) {
			// Skip if no API key or explicitly disabled
			if ( empty( $config['api_key'] ) ) {
				continue;
			}

			// Skip if explicitly disabled (but allow missing 'enabled' key)
			if ( isset( $config['enabled'] ) && false === $config['enabled'] ) {
				continue;
			}

			$results[ $provider_name ] = self::detect_tier( $provider_name, $config );
		}

		return $results;
	}

	/**
	 * Detekuje tier pro konkrétního providera
	 *
	 * @param string $provider_name Název providera (openai|anthropic|google)
	 * @param array  $config        Konfigurace providera
	 * @return array Tier informace
	 */
	public static function detect_tier( string $provider_name, array $config ): array {
		// Check cache first
		$cache_key = 'ai_media_tier_' . $provider_name . '_' . md5( $config['api_key'] );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		// Detect tier based on provider
		$tier_info = match ( $provider_name ) {
			'openai' => self::detect_openai_tier( $config ),
			'anthropic' => self::detect_anthropic_tier( $config ),
			'google' => self::detect_google_tier( $config ),
			default => self::get_default_tier( $provider_name ),
		};

		// Cache for 24 hours
		set_transient( $cache_key, $tier_info, DAY_IN_SECONDS );

		return $tier_info;
	}

	/**
	 * Detekuje OpenAI tier
	 * Metoda: Trial request s rate limit headers
	 *
	 * @param array $config Provider configuration
	 * @return array Tier info
	 */
	private static function detect_openai_tier( array $config ): array {
		try {
			// OpenAI vrací rate limit info v response headers
			$response = wp_remote_get(
				'https://api.openai.com/v1/models',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $config['api_key'],
					),
					'timeout' => 3, // ✅ QUICK WIN 3: Reduced from 10s to 3s for faster fallback
				)
			);

			if ( is_wp_error( $response ) ) {
				return self::get_default_tier( 'openai' );
			}

			// Získat rate limit z headers
			$headers   = wp_remote_retrieve_headers( $response );
			$rpm_limit = null;

			// OpenAI používá 'x-ratelimit-limit-requests' header
			if ( isset( $headers['x-ratelimit-limit-requests'] ) ) {
				$rpm_limit = (int) $headers['x-ratelimit-limit-requests'];
			}

			// Fallback: Zkusit detekovat podle organization info
			if ( ! $rpm_limit ) {
				$body     = json_decode( wp_remote_retrieve_body( $response ), true );
				$has_gpt4 = false;

				// Heuristika: Pokud má přístup k GPT-4, pravděpodobně má Tier 1+
				if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
					foreach ( $body['data'] as $model ) {
						if ( isset( $model['id'] ) && false !== strpos( $model['id'], 'gpt-4' ) ) {
							$has_gpt4 = true;
							break;
						}
					}
				}
				$rpm_limit = $has_gpt4 ? 60 : 3; // Tier 1 vs Free
			}

			return self::classify_openai_tier( $rpm_limit );

		} catch ( \Exception $e ) {
			return self::get_default_tier( 'openai' );
		}
	}

	/**
	 * Klasifikuje OpenAI tier podle RPM limitu
	 *
	 * @param int $rpm Requests per minute limit
	 * @return array Tier info
	 */
	private static function classify_openai_tier( int $rpm ): array {
		if ( $rpm >= 10000 ) {
			return array(
				'tier'        => 'tier_5',
				'tier_name'   => 'Tier 5 (Enterprise)',
				'rpm'         => $rpm,
				'confidence'  => 0.95,
				'description' => 'Enterprise level access with maximum throughput',
			);
		} elseif ( $rpm >= 5000 ) {
			return array(
				'tier'        => 'tier_4',
				'tier_name'   => 'Tier 4',
				'rpm'         => $rpm,
				'confidence'  => 0.9,
				'description' => 'High volume access',
			);
		} elseif ( $rpm >= 500 ) {
			return array(
				'tier'        => 'tier_2',
				'tier_name'   => 'Tier 2',
				'rpm'         => $rpm,
				'confidence'  => 0.85,
				'description' => 'Standard paid tier with good throughput',
			);
		} elseif ( $rpm >= 50 ) {
			return array(
				'tier'        => 'tier_1',
				'tier_name'   => 'Tier 1',
				'rpm'         => $rpm,
				'confidence'  => 0.8,
				'description' => 'Basic paid tier',
			);
		} else {
			return array(
				'tier'        => 'free',
				'tier_name'   => 'Free Tier',
				'rpm'         => $rpm,
				'confidence'  => 0.75,
				'description' => 'Free tier with limited requests',
			);
		}
	}

	/**
	 * Detekuje Anthropic tier
	 * Metoda: Trial request a analýza error messages
	 *
	 * @param array $config Provider configuration
	 * @return array Tier info
	 */
	private static function detect_anthropic_tier( array $config ): array {
		try {
			// Anthropic nemá přímý endpoint pro tier info
			// Použijeme trial request a sledujeme rate limit v error response

			// Zkusíme rychlou sérii requestů a sledujeme response
			$test_results = array();
			$max_tests    = 3;

			for ( $i = 0; $i < $max_tests; $i++ ) {
				$response = wp_remote_post(
					'https://api.anthropic.com/v1/messages',
					array(
						'headers' => array(
							'x-api-key'         => $config['api_key'],
							'anthropic-version' => '2023-06-01',
							'content-type'      => 'application/json',
						),
						'body'    => wp_json_encode(
							array(
								'model'      => 'claude-sonnet-4-5-20250929',
								'max_tokens' => 10,
								'messages'   => array(
									array(
										'role'    => 'user',
										'content' => 'Hi',
									),
								),
							)
						),
						'timeout' => 3, // ✅ QUICK WIN 3: Reduced from 10s to 3s for faster fallback
					)
				);

				$status_code = wp_remote_retrieve_response_code( $response );
				$headers     = wp_remote_retrieve_headers( $response );

				// Zkontrolovat rate limit headers
				if ( isset( $headers['anthropic-ratelimit-requests-limit'] ) ) {
					$rpm_limit = (int) $headers['anthropic-ratelimit-requests-limit'];
					return self::classify_anthropic_tier( $rpm_limit );
				}

				// Pokud dostaneme 200, máme alespoň základní tier
				if ( 200 === $status_code ) {
					$test_results[] = 'success';
				}

				// Malá pauza mezi testy
				usleep( 200000 ); // 200ms
			}

			// Heuristika: Pokud všechny testy prošly, pravděpodobně máme Tier 1+
			$success_rate = count( array_filter( $test_results, fn( $r ) => 'success' === $r ) ) / $max_tests;

			if ( $success_rate >= 0.8 ) {
				return self::classify_anthropic_tier( 50 ); // Tier 1
			} else {
				return self::classify_anthropic_tier( 5 ); // Free
			}
		} catch ( \Exception $e ) {
			return self::get_default_tier( 'anthropic' );
		}
	}

	/**
	 * Klasifikuje Anthropic tier
	 *
	 * @param int $rpm Requests per minute limit
	 * @return array Tier info
	 */
	private static function classify_anthropic_tier( int $rpm ): array {
		if ( $rpm >= 2000 ) {
			return array(
				'tier'        => 'tier_4',
				'tier_name'   => 'Scale (Tier 4)',
				'rpm'         => $rpm,
				'confidence'  => 0.9,
				'description' => 'Enterprise scale access',
			);
		} elseif ( $rpm >= 1000 ) {
			return array(
				'tier'        => 'tier_3',
				'tier_name'   => 'Build (Tier 3)',
				'rpm'         => $rpm,
				'confidence'  => 0.85,
				'description' => 'High volume production access',
			);
		} elseif ( $rpm >= 50 ) {
			return array(
				'tier'        => 'tier_1',
				'tier_name'   => 'Build (Tier 1)',
				'rpm'         => $rpm,
				'confidence'  => 0.8,
				'description' => 'Standard production access',
			);
		} else {
			return array(
				'tier'        => 'free',
				'tier_name'   => 'Free Tier',
				'rpm'         => $rpm,
				'confidence'  => 0.7,
				'description' => 'Limited trial access',
			);
		}
	}

	/**
	 * Detekuje Google Gemini tier
	 *
	 * @param array $config Provider configuration
	 * @return array Tier info
	 */
	private static function detect_google_tier( array $config ): array {
		try {
			// Google Gemini: Make actual API call to get real rate limit headers
			// Models endpoint doesn't return rate limit info, we need to make a real generateContent call
			$model = $config['model'] ?? 'gemini-1.5-flash';
			$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';

			$response = wp_remote_post(
				$endpoint,
				array(
					'headers' => array(
						'Content-Type'   => 'application/json',
						'x-goog-api-key' => $config['api_key'],
					),
					'body'    => wp_json_encode(
						array(
							'contents' => array(
								array(
									'parts' => array(
										array( 'text' => 'Hi' ),
									),
								),
							),
						)
					),
					'timeout' => 3, // ✅ QUICK WIN 3: Reduced from 10s to 3s for faster fallback
				)
			);

			if ( is_wp_error( $response ) ) {
				return self::get_default_tier( 'google' );
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$headers     = wp_remote_retrieve_headers( $response );

			// Debug: Log all headers to see what Google returns
			error_log( '[TierDetector] Google API Headers: ' . print_r( $headers, true ) );

			// Google vrací rate limit info v headerech (pokud je dostupný)
			$rpm_limit = null;

			// Try different header names Google might use
			$header_names = array(
				'x-ratelimit-limit-requests',
				'x-ratelimit-limit',
				'ratelimit-limit',
			);

			foreach ( $header_names as $header_name ) {
				if ( isset( $headers[ $header_name ] ) ) {
					$rpm_limit = (int) $headers[ $header_name ];
					error_log( "[TierDetector] Found RPM limit in header '{$header_name}': {$rpm_limit}" );
					break;
				}
			}

			// If no rate limit header found, use CONSERVATIVE default
			// Better to underestimate than overestimate and disappoint users
			if ( ! $rpm_limit ) {
				error_log( '[TierDetector] No rate limit header found, defaulting to 15 RPM' );
				// Default to Tier 1 (15 RPM) - safest assumption
				$rpm_limit = 15;
			}

			return self::classify_google_tier( $rpm_limit );

		} catch ( \Exception $e ) {
			return self::get_default_tier( 'google' );
		}
	}

	/**
	 * Klasifikuje Google tier
	 *
	 * @param int $rpm Requests per minute limit
	 * @return array Tier info
	 */
	private static function classify_google_tier( int $rpm ): array {
		if ( $rpm >= 1500 ) {
			return array(
				'tier'        => 'enterprise',
				'tier_name'   => 'Enterprise',
				'rpm'         => $rpm,
				'confidence'  => 0.9,
				'description' => 'Enterprise level access',
			);
		} elseif ( $rpm >= 1000 ) {
			return array(
				'tier'        => 'premium',
				'tier_name'   => 'Premium',
				'rpm'         => $rpm,
				'confidence'  => 0.85,
				'description' => 'High volume access',
			);
		} elseif ( $rpm >= 360 ) {
			return array(
				'tier'        => 'standard',
				'tier_name'   => 'Standard (Paid)',
				'rpm'         => $rpm,
				'confidence'  => 0.8,
				'description' => 'Standard paid tier',
			);
		} elseif ( $rpm >= 60 ) {
			return array(
				'tier'        => 'tier_2',
				'tier_name'   => 'Tier 2',
				'rpm'         => $rpm,
				'confidence'  => 0.75,
				'description' => 'Basic paid tier',
			);
		} elseif ( $rpm >= 30 ) {
			return array(
				'tier'        => 'tier_1_plus',
				'tier_name'   => 'Tier 1+',
				'rpm'         => $rpm,
				'confidence'  => 0.75,
				'description' => 'Enhanced basic tier',
			);
		} elseif ( $rpm >= 15 ) {
			return array(
				'tier'        => 'tier_1',
				'tier_name'   => 'Tier 1',
				'rpm'         => $rpm,
				'confidence'  => 0.7,
				'description' => 'Basic tier with standard limits',
			);
		} else {
			return array(
				'tier'        => 'free',
				'tier_name'   => 'Free Tier',
				'rpm'         => $rpm,
				'confidence'  => 0.7,
				'description' => 'Free tier with basic limits',
			);
		}
	}

	/**
	 * Vrátí výchozí tier pokud detekce selže
	 *
	 * @param string $provider Provider name
	 * @return array Default tier info
	 */
	private static function get_default_tier( string $provider ): array {
		$defaults = array(
			'openai'    => array(
				'tier'        => 'tier_1',
				'tier_name'   => 'Tier 1 (Estimated)',
				'rpm'         => 60,
				'confidence'  => 0.5,
				'description' => 'Could not detect tier, using conservative defaults',
			),
			'anthropic' => array(
				'tier'        => 'tier_1',
				'tier_name'   => 'Build Tier (Estimated)',
				'rpm'         => 50,
				'confidence'  => 0.5,
				'description' => 'Could not detect tier, using conservative defaults',
			),
			'google'    => array(
				'tier'        => 'standard',
				'tier_name'   => 'Standard (Estimated)',
				'rpm'         => 60,
				'confidence'  => 0.5,
				'description' => 'Could not detect tier, using conservative defaults',
			),
		);

		return $defaults[ $provider ] ?? array(
			'tier'        => 'unknown',
			'tier_name'   => 'Unknown',
			'rpm'         => 60,
			'confidence'  => 0.3,
			'description' => 'Could not detect tier, using conservative defaults',
		);
	}

	/**
	 * Vypočítá doporučenou concurrency podle tier
	 *
	 * @param string $tier Tier identifier
	 * @param int    $rpm  Requests per minute
	 * @return array Array with 'easy', 'optimal', 'extreme' concurrency values
	 */
	public static function get_recommended_concurrency( string $tier, int $rpm ): array {
		// Návratový formát: ['easy' => int, 'optimal' => int, 'extreme' => int]
		// Formula: concurrency should be ~20-30% of RPM for optimal, ~10% for easy, ~40% for extreme

		if ( $rpm >= 3000 ) {
			// High tier (OpenAI Tier 2+, Anthropic Scale)
			return array(
				'easy'    => 10,
				'optimal' => 30,
				'extreme' => 50,
			);
		} elseif ( $rpm >= 1000 ) {
			// Medium-high tier
			return array(
				'easy'    => 8,
				'optimal' => 20,
				'extreme' => 35,
			);
		} elseif ( $rpm >= 360 ) {
			// Medium tier (Google Standard Paid, OpenAI Tier 1)
			return array(
				'easy'    => 5,
				'optimal' => 12,
				'extreme' => 20,
			);
		} elseif ( $rpm >= 60 ) {
			// Tier 2 (60 RPM)
			return array(
				'easy'    => 3,
				'optimal' => 6,
				'extreme' => 10,
			);
		} elseif ( $rpm >= 30 ) {
			// Tier 1+ (30 RPM)
			return array(
				'easy'    => 2,
				'optimal' => 4,
				'extreme' => 6,
			);
		} elseif ( $rpm >= 15 ) {
			// Tier 1 (15 RPM)
			return array(
				'easy'    => 1,
				'optimal' => 2,
				'extreme' => 3,
			);
		} else {
			// Free tier or very low limits
			return array(
				'easy'    => 1,
				'optimal' => 1,
				'extreme' => 2,
			);
		}
	}

	/**
	 * Force refresh tier detection
	 *
	 * @param string $provider Provider name
	 * @return void
	 */
	public static function refresh_tier_cache( string $provider ): void {
		$providers_config = get_option( 'ai_media_seo_providers', array() );
		if ( isset( $providers_config[ $provider ] ) ) {
			$cache_key = 'ai_media_tier_' . $provider . '_' . md5( $providers_config[ $provider ]['api_key'] );
			delete_transient( $cache_key );
		}
	}
}
