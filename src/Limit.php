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
	 * dump. It is normalised first — see {@see self::identity()} — so the
	 * same caller spelled two ways is one counter.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Normalises IP addresses and non-ASCII case.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->action . ':' . substr( hash( 'sha256', self::identity( $this->identity ) ), 0, 32 );
	}

	/**
	 * The form of an identity that is counted.
	 *
	 * Without this, one caller is as many counters as it has spellings:
	 *
	 * - **An IPv6 address counts as its /64.** A single host is routinely
	 *   given a whole /64 and can use any of its 2^64 addresses, so a limit
	 *   per address is no limit — rotate the last 64 bits and every request
	 *   is a first. Hosts sharing one /64 share one allowance, which is how
	 *   the network sees them too.
	 * - **An address is written one way.** `2001:DB8::1`, `2001:db8:0::1`
	 *   and the IPv4-mapped `::ffff:192.0.2.1` are the same callers as
	 *   `2001:db8::1` and `192.0.2.1`.
	 * - **Case is folded beyond ASCII** when mbstring is there, so
	 *   `JÖRG@example.com` and `jörg@example.com` are one address.
	 *
	 * @since 1.3.0
	 *
	 * @param string $identity As the caller gave it.
	 *
	 * @return string
	 */
	public static function identity( string $identity ): string {
		$identity = trim( $identity );
		$binary   = false === filter_var( $identity, FILTER_VALIDATE_IP ) ? false : inet_pton( $identity );

		if ( false !== $binary && 16 === strlen( $binary ) ) {
			if ( str_starts_with( $binary, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
				$binary = substr( $binary, 12 );
			} else {
				return ( inet_ntop( substr( $binary, 0, 8 ) . str_repeat( "\0", 8 ) ) ?: $identity ) . '/64';
			}
		}

		if ( false !== $binary ) {
			return inet_ntop( $binary ) ?: $identity;
		}

		return function_exists( 'mb_strtolower' ) && mb_check_encoding( $identity, 'UTF-8' )
			? mb_strtolower( $identity, 'UTF-8' )
			: strtolower( $identity );
	}
}
