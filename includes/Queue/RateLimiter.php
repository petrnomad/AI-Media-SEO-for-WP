<?php
/**
 * Rate Limiter
 *
 * Manages API rate limiting to prevent hitting provider limits.
 *
 * @package    AIMediaSEO
 * @subpackage Queue
 * @since      1.0.0
 */

namespace AIMediaSEO\Queue;

/**
 * RateLimiter class.
 *
 * Tracks and enforces rate limits for API requests.
 *
 * @since 1.0.0
 */
class RateLimiter {

	/**
	 * Transient prefix for rate limit tracking.
	 *
	 * @var string
	 */
	private $transient_prefix = 'ai_media_rate_';

	/**
	 * Transient prefix for sliding window tracking.
	 *
	 * @var string
	 */
	private $sliding_prefix = 'ai_media_sliding_';

	/**
	 * Default rate limits (requests per minute).
	 *
	 * @var array
	 */
	private $default_limits = array(
		'openai'     => 60,
		'anthropic'  => 50,
		'google'     => 60,
	);

	/**
	 * Use sliding window instead of fixed minute buckets.
	 *
	 * @var bool
	 */
	private $use_sliding_window = true;

	/**
	 * Check if request is allowed.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window ('minute', 'hour', 'day').
	 * @return bool True if request is allowed.
	 */
	public function is_allowed( string $provider, string $window = 'minute' ): bool {
		$limit = $this->get_limit( $provider, $window );
		$current = $this->get_current_count( $provider, $window );

		return $current < $limit;
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
		if ( $this->use_sliding_window ) {
			$this->record_request_sliding( $provider, $window );
			return $this->get_current_count_sliding( $provider, $window );
		}

		$key = $this->get_transient_key( $provider, $window );
		$count = (int) get_transient( $key );
		$count++;

		$expiration = $this->get_window_seconds( $window );
		set_transient( $key, $count, $expiration );

		return $count;
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
		if ( $this->use_sliding_window ) {
			return $this->get_current_count_sliding( $provider, $window );
		}

		$key = $this->get_transient_key( $provider, $window );
		return (int) get_transient( $key );
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

		if ( $this->use_sliding_window ) {
			// With sliding window, delay until oldest request expires.
			$key = $this->sliding_prefix . $provider . '_' . $window;
			$requests = get_transient( $key );

			if ( ! is_array( $requests ) || empty( $requests ) ) {
				return 0;
			}

			// Sort to get oldest request.
			sort( $requests );
			$oldest = $requests[0];

			$window_seconds = $this->get_window_seconds( $window );
			$delay = ( $oldest + $window_seconds ) - time();

			// Add small buffer to avoid race conditions.
			return max( 0, (int) ceil( $delay ) + 1 );
		}

		// Fixed window: Calculate time until window resets.
		$key = $this->get_transient_key( $provider, $window );
		$timeout = get_option( '_transient_timeout_' . $key );

		if ( ! $timeout ) {
			return 0;
		}

		$delay = $timeout - time();
		return max( 0, $delay );
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
	 * Record request with sliding window.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 */
	private function record_request_sliding( string $provider, string $window = 'minute' ): void {
		$key = $this->sliding_prefix . $provider . '_' . $window;
		$requests = get_transient( $key );

		if ( ! is_array( $requests ) ) {
			$requests = array();
		}

		// Add current timestamp.
		$requests[] = time();

		// Clean old requests outside the window.
		$window_seconds = $this->get_window_seconds( $window );
		$cutoff = time() - $window_seconds;
		$requests = array_filter( $requests, function( $timestamp ) use ( $cutoff ) {
			return $timestamp > $cutoff;
		} );

		// Store for window duration + buffer.
		set_transient( $key, array_values( $requests ), $window_seconds + 60 );
	}

	/**
	 * Get current request count with sliding window.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 * @return int Current count in sliding window.
	 */
	private function get_current_count_sliding( string $provider, string $window = 'minute' ): int {
		$key = $this->sliding_prefix . $provider . '_' . $window;
		$requests = get_transient( $key );

		if ( ! is_array( $requests ) ) {
			return 0;
		}

		// Filter to only requests within the window.
		$window_seconds = $this->get_window_seconds( $window );
		$cutoff = time() - $window_seconds;
		$recent_requests = array_filter( $requests, function( $timestamp ) use ( $cutoff ) {
			return $timestamp > $cutoff;
		} );

		return count( $recent_requests );
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
		$limit = $this->get_limit( $provider, $window );
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
		$limit = $this->get_limit( $provider, $window );
		$current = $this->get_current_count( $provider, $window );

		return ( $current / $limit ) >= $threshold;
	}

	/**
	 * Reset rate limit counter.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 */
	public function reset( string $provider, string $window = 'minute' ): void {
		$key = $this->get_transient_key( $provider, $window );
		delete_transient( $key );
	}

	/**
	 * Get transient key.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider name.
	 * @param string $window   Time window.
	 * @return string Transient key.
	 */
	private function get_transient_key( string $provider, string $window ): string {
		$timestamp = $this->get_window_timestamp( $window );
		return $this->transient_prefix . $provider . '_' . $window . '_' . $timestamp;
	}

	/**
	 * Get window timestamp.
	 *
	 * Creates a timestamp that changes each window period.
	 *
	 * @since 1.0.0
	 * @param string $window Time window.
	 * @return string Timestamp string.
	 */
	private function get_window_timestamp( string $window ): string {
		$now = time();

		switch ( $window ) {
			case 'minute':
				return gmdate( 'YmdHi', $now );
			case 'hour':
				return gmdate( 'YmdH', $now );
			case 'day':
				return gmdate( 'Ymd', $now );
			default:
				return gmdate( 'YmdHi', $now );
		}
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

	/**
	 * Get rate limit status for all providers.
	 *
	 * @since 1.0.0
	 * @return array Status for each provider.
	 */
	public function get_status(): array {
		$providers = array( 'openai', 'anthropic', 'google' );
		$status = array();

		foreach ( $providers as $provider ) {
			$status[ $provider ] = array(
				'minute' => array(
					'limit'     => $this->get_limit( $provider, 'minute' ),
					'current'   => $this->get_current_count( $provider, 'minute' ),
					'remaining' => $this->get_remaining( $provider, 'minute' ),
					'delay'     => $this->get_delay( $provider, 'minute' ),
				),
				'hour' => array(
					'limit'     => $this->get_limit( $provider, 'hour' ),
					'current'   => $this->get_current_count( $provider, 'hour' ),
					'remaining' => $this->get_remaining( $provider, 'hour' ),
				),
			);
		}

		return $status;
	}
}
