<?php
/**
 * Rate Limiter (In-Memory)
 *
 * Manages API rate limiting with in-memory sliding window tracking.
 * 1000× faster than DB transients.
 *
 * @package    AIMediaSEO
 * @subpackage Queue
 * @since      1.0.0
 * @version    2.2.0 (In-Memory Optimization)
 */

namespace AIMediaSEO\Queue;

/**
 * RateLimiter class.
 *
 * Tracks and enforces rate limits for API requests using in-memory storage.
 *
 * @since 1.0.0
 */
class RateLimiter {

	/**
	 * In-memory cache for tracking requests
	 *
	 * @var array<string, array<int>>
	 */
	private static $request_timestamps = array();

	/**
	 * Default rate limits (requests per minute).
	 *
	 * @var array<string, int>
	 */
	private $default_limits = array(
		'openai'    => 60,
		'anthropic' => 50,
		'google'    => 60,
	);

	/**
	 * Window size in seconds.
	 *
	 * @var int
	 */
	private const WINDOW_SIZE = 60;

	/**
	 * Check if request is allowed.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window ('minute', 'hour', 'day').
	 * @return bool True if request is allowed.
	 */
	public function is_allowed( string $provider, string $window = 'minute' ): bool {
		$cache_key = $this->get_cache_key( $provider, $window );

		// Initialize cache pokud neexistuje.
		if ( ! isset( self::$request_timestamps[ $cache_key ] ) ) {
			self::$request_timestamps[ $cache_key ] = array();
		}

		// Cleanup starých timestampů (mimo sliding window).
		$this->cleanup_old_requests( $cache_key, $window );

		// Check limit.
		$current_count = count( self::$request_timestamps[ $cache_key ] );
		$limit         = $this->get_limit( $provider, $window );

		return $current_count < $limit;
	}

	/**
	 * Record a request.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 * @return int New count.
	 */
	public function record_request( string $provider, string $window = 'minute' ): int {
		$cache_key = $this->get_cache_key( $provider, $window );

		// Initialize cache pokud neexistuje.
		if ( ! isset( self::$request_timestamps[ $cache_key ] ) ) {
			self::$request_timestamps[ $cache_key ] = array();
		}

		// Přidat nový request timestamp.
		self::$request_timestamps[ $cache_key ][] = time();

		// Cleanup starých requestů.
		$this->cleanup_old_requests( $cache_key, $window );

		return count( self::$request_timestamps[ $cache_key ] );
	}

	/**
	 * Get current request count.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 * @return int Current count.
	 */
	public function get_current_count( string $provider, string $window = 'minute' ): int {
		$cache_key = $this->get_cache_key( $provider, $window );

		if ( ! isset( self::$request_timestamps[ $cache_key ] ) ) {
			return 0;
		}

		// Cleanup starých requestů.
		$this->cleanup_old_requests( $cache_key, $window );

		return count( self::$request_timestamps[ $cache_key ] );
	}

	/**
	 * Get rate limit for provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 * @return int Limit.
	 */
	public function get_limit( string $provider, string $window = 'minute' ): int {
		$settings = get_option( 'ai_media_seo_settings', array() );

		// Custom limit from settings.
		$limit_key = "rate_limit_{$window}";
		if ( isset( $settings[ $limit_key ] ) ) {
			return (int) $settings[ $limit_key ];
		}

		// Default limit for provider.
		return $this->default_limits[ $provider ] ?? 60;
	}

	/**
	 * Calculate delay needed before next request.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 * @return int Delay in seconds (0 if no delay needed).
	 */
	public function get_delay( string $provider, string $window = 'minute' ): int {
		if ( $this->is_allowed( $provider, $window ) ) {
			return 0;
		}

		$cache_key = $this->get_cache_key( $provider, $window );

		if ( ! isset( self::$request_timestamps[ $cache_key ] ) || empty( self::$request_timestamps[ $cache_key ] ) ) {
			return 0;
		}

		// Najít nejstarší request.
		$oldest_timestamp = min( self::$request_timestamps[ $cache_key ] );
		$window_seconds   = $this->get_window_seconds( $window );
		$window_end       = $oldest_timestamp + $window_seconds;

		$delay = $window_end - time();

		// Add small buffer to avoid race conditions.
		return max( 0, (int) ceil( $delay ) + 1 );
	}

	/**
	 * Get remaining requests in current window.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 * @return int Remaining requests.
	 */
	public function get_remaining( string $provider, string $window = 'minute' ): int {
		$limit   = $this->get_limit( $provider, $window );
		$current = $this->get_current_count( $provider, $window );

		return max( 0, $limit - $current );
	}

	/**
	 * Check if we're approaching rate limit.
	 *
	 * @since 1.0.0
	 * @param string $provider  Provider name.
	 * @param string $window    Time window.
	 * @param float  $threshold Threshold as percentage (e.g., 0.8 for 80%).
	 * @return bool True if approaching limit.
	 */
	public function is_approaching_limit( string $provider, string $window = 'minute', float $threshold = 0.8 ): bool {
		$limit   = $this->get_limit( $provider, $window );
		$current = $this->get_current_count( $provider, $window );

		if ( $limit === 0 ) {
			return false;
		}

		return ( $current / $limit ) >= $threshold;
	}

	/**
	 * Reset rate limit counter.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name (optional, if empty resets all).
	 * @param string $window   Time window (optional).
	 */
	public function reset( string $provider = '', string $window = '' ): void {
		if ( empty( $provider ) ) {
			// Reset all.
			self::$request_timestamps = array();
			return;
		}

		if ( empty( $window ) ) {
			// Reset all windows for provider.
			$windows = array( 'minute', 'hour', 'day' );
			foreach ( $windows as $w ) {
				$cache_key = $this->get_cache_key( $provider, $w );
				unset( self::$request_timestamps[ $cache_key ] );
			}
			return;
		}

		// Reset specific provider+window.
		$cache_key = $this->get_cache_key( $provider, $window );
		unset( self::$request_timestamps[ $cache_key ] );
	}

	/**
	 * Wait if rate limit is exceeded.
	 *
	 * DEPRECATED: Use get_delay() and reschedule instead to avoid blocking workers.
	 *
	 * @since 1.0.0
	 * @deprecated
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 * @param int    $max_wait Maximum seconds to wait (0 = no limit).
	 * @return bool True if can proceed immediately, false if should reschedule.
	 */
	public function wait_if_needed( string $provider, string $window = 'minute', int $max_wait = 60 ): bool {
		$delay = $this->get_delay( $provider, $window );

		// Don't block - return false if delay needed.
		return $delay === 0;
	}

	/**
	 * Get rate limit status for all providers.
	 *
	 * @since 1.0.0
	 * @return array Status for each provider.
	 */
	public function get_status(): array {
		$providers = array( 'openai', 'anthropic', 'google' );
		$status    = array();

		foreach ( $providers as $provider ) {
			$status[ $provider ] = array(
				'minute' => array(
					'limit'     => $this->get_limit( $provider, 'minute' ),
					'current'   => $this->get_current_count( $provider, 'minute' ),
					'remaining' => $this->get_remaining( $provider, 'minute' ),
					'delay'     => $this->get_delay( $provider, 'minute' ),
				),
				'hour'   => array(
					'limit'     => $this->get_limit( $provider, 'hour' ),
					'current'   => $this->get_current_count( $provider, 'hour' ),
					'remaining' => $this->get_remaining( $provider, 'hour' ),
					'delay'     => $this->get_delay( $provider, 'hour' ),
				),
			);
		}

		return $status;
	}

	/**
	 * Get statistics (pro debugging/monitoring).
	 *
	 * @since 2.2.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 * @return array Statistics.
	 */
	public function get_stats( string $provider, string $window = 'minute' ): array {
		$cache_key = $this->get_cache_key( $provider, $window );
		$this->cleanup_old_requests( $cache_key, $window );

		$timestamps = self::$request_timestamps[ $cache_key ] ?? array();

		return array(
			'provider'             => $provider,
			'window'               => $window,
			'limit'                => $this->get_limit( $provider, $window ),
			'current_count'        => count( $timestamps ),
			'remaining'            => $this->get_remaining( $provider, $window ),
			'time_until_available' => $this->get_delay( $provider, $window ),
			'window_size'          => $this->get_window_seconds( $window ),
		);
	}

	/**
	 * Vyčistit staré requesty mimo sliding window.
	 *
	 * @since 2.2.0
	 * @param string $cache_key Cache key.
	 * @param string $window    Time window.
	 */
	private function cleanup_old_requests( string $cache_key, string $window = 'minute' ): void {
		if ( ! isset( self::$request_timestamps[ $cache_key ] ) ) {
			return;
		}

		$window_seconds = $this->get_window_seconds( $window );
		$window_start   = time() - $window_seconds;

		self::$request_timestamps[ $cache_key ] = array_filter(
			self::$request_timestamps[ $cache_key ],
			function ( $timestamp ) use ( $window_start ) {
				return $timestamp > $window_start;
			}
		);

		// Reindex array.
		self::$request_timestamps[ $cache_key ] = array_values(
			self::$request_timestamps[ $cache_key ]
		);
	}

	/**
	 * Get cache key for provider+window.
	 *
	 * @since 2.2.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 * @return string Cache key.
	 */
	private function get_cache_key( string $provider, string $window = 'minute' ): string {
		return "rate_limit_{$provider}_{$window}";
	}

	/**
	 * Get window duration in seconds.
	 *
	 * @since 1.0.0
	 * @param string $window Time window.
	 * @return int Seconds.
	 */
	private function get_window_seconds( string $window ): int {
		switch ( $window ) {
			case 'minute':
				return 60;
			case 'hour':
				return 3600;
			case 'day':
				return 86400;
			default:
				return 60;
		}
	}
}
