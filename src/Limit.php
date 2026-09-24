<?php
/**
 * A limiter bound to an action and an identity.
 *
 * @package   SugarCommerce\RateLimit
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\RateLimit;

/**
 * Class Limit
 *
 * Returned by {@see RateLimiter::for()}. Holds the key so callers state
 * the allowance and nothing else:
 *
 *   $limiter->for( 'login', $ip )->check( 5, 60 );
 *
 * @since 1.0.0
 */
final readonly class Limit {

	/**
	 * @since 1.0.0
	 *
	 * @param RateLimiter $limiter  Owning limiter.
	 * @param string      $action   Action name.
	 * @param string      $identity Who is acting.
	 */
	public function __construct(
		private RateLimiter $limiter,
		private string $action,
		private string $identity,
	) {}

	/**
	 * Test and consume an attempt.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit  Attempts permitted per window.
	 * @param int $window Window length in seconds.
	 *
	 * @return Decision
	 */
	#[\NoDiscard( 'A denied decision must be enforced; counting alone limits nothing.' )]
	public function check( int $limit, int $window ): Decision {
		return $this->limiter->check( $this->key(), $limit, $window );
	}

	/**
	 * Test without consuming.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit  Attempts permitted per window.
	 * @param int $window Window length in seconds.
	 *
	 * @return Decision
	 */
	#[\NoDiscard( 'Peeking only answers a question.' )]
	public function peek( int $limit, int $window ): Decision {
		return $this->limiter->peek( $this->key(), $limit, $window );
	}

	/**
	 * Reset this identity's counters.
	 *
	 * @since 1.0.0
	 *
	 * @param int $window Window length the key was counted in.
	 *
	 * @return void
	 */
	public function clear( int $window ): void {
		$this->limiter->clear( $this->key(), $window );
	}

	/**
	 * The storage key.
	 *
	 * The identity is hashed because it is usually an email address or
	 * an IP, and neither belongs in a cache key, a filename, or a debug
	 * dump.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->action . ':' . substr( hash( 'sha256', strtolower( trim( $this->identity ) ) ), 0, 32 );
	}
}
