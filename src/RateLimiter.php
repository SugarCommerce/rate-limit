<?php
/**
 * Sliding-window rate limiting.
 *
 * @package   SugarCommerce\RateLimit
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\RateLimit;

/**
 * Class RateLimiter
 *
 * Answers "has this person done this too many times lately?"
 *
 * **The key is the whole design.** A limiter is only as good as what it
 * counts against, and the most common mistake is a key with nothing
 * identifying in it:
 *
 *   $limiter->check( 'login', 5, 60 );              // wrong
 *   $limiter->check( 'login:' . $ip, 5, 60 );       // right
 *
 * The first shares one counter across every visitor, so five attempts
 * from anyone locks out everybody — an attacker denies your whole
 * customer base by hammering the endpoint. It reads like rate limiting
 * and is closer to a self-inflicted outage. {@see self::for()} exists to
 * make the key explicit and hard to forget.
 *
 * **Key by the thing being protected, not just the network.** IP is
 * right for "requests to this endpoint". For "magic links sent to this
 * address", key on the address — otherwise one mailbox can be flooded
 * from a botnet, and a legitimate office behind one NAT gets blocked
 * together. Often you want both.
 *
 * **Counting is a sliding window.** A fixed window lets someone spend
 * the whole allowance at 59 seconds and again at 61 — double the limit
 * across a two-second span. This weights the previous window by how much
 * of it remains in view, at the same storage cost.
 *
 * Typical use:
 *
 *   $limiter = new RateLimiter( new Stores\Apcu() );
 *
 *   $decision = $limiter->for( 'login', $ip )->check( 5, 60 );
 *
 *   if ( $decision->denied() ) {
 *       http_response_code( 429 );
 *       header( 'Retry-After: ' . $decision->retry_after );
 *       exit;
 *   }
 *
 * @since 1.0.0
 */
final readonly class RateLimiter {

	/**
	 * @since 1.0.0
	 *
	 * @param Store    $store     Where counters live.
	 * @param int|null $timestamp Fixed clock, for tests.
	 */
	public function __construct(
		private Store $store,
		private ?int $timestamp = null,
	) {}

	/**
	 * Bind this limiter to an action and an identity.
	 *
	 * The identity is required rather than optional, because the failure
	 * mode of forgetting it is a self-inflicted denial of service and it
	 * looks correct in review.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action   What is being limited, e.g. `login`.
	 * @param string $identity Who is doing it — an IP, an email, a
	 *                         customer id. Hashed before storage.
	 *
	 * @return Limit
	 *
	 * @throws \InvalidArgumentException When either part is empty.
	 */
	public function for( string $action, string $identity ): Limit {
		if ( '' === trim( $action ) ) {
			throw new \InvalidArgumentException( 'A rate limit needs an action name.' );
		}

		if ( '' === trim( $identity ) ) {
			throw new \InvalidArgumentException(
				'A rate limit needs an identity to count against — an IP, an email, a customer id. '
				. 'Without one, every visitor shares a single counter and any of them can lock out all the others.'
			);
		}

		return new Limit( $this, $action, $identity );
	}

	/**
	 * Test and consume, against a fully-formed key.
	 *
	 * Prefer {@see self::for()}, which makes the identity part of the key
	 * impossible to leave out.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key    Complete counter key.
	 * @param int    $limit  Permitted attempts per window.
	 * @param int    $window Window length in seconds.
	 *
	 * @return Decision
	 *
	 * @throws \InvalidArgumentException On a non-positive limit or window.
	 */
	#[\NoDiscard( 'A denied decision must be enforced; counting alone limits nothing.' )]
	public function check( string $key, int $limit, int $window ): Decision {
		if ( $limit < 1 || $window < 1 ) {
			throw new \InvalidArgumentException( 'Rate limit and window must both be at least 1.' );
		}

		$now      = $this->now();
		$elapsed  = $now % $window;
		$current  = intdiv( $now, $window );
		$estimate = $this->estimate( $key, $current, $window, $elapsed );

		if ( $estimate >= $limit ) {
			return new Decision( false, $limit, 0, $window - $elapsed, $now + ( $window - $elapsed ) );
		}

		// Two windows are retained so the previous one can still be
		// weighted while it remains partly in view.
		$count     = $this->store->increment( $key . ':' . $current, $window * 2 );
		$estimate  = $this->weighted( $key, $current, $window, $elapsed, $count );
		$remaining = max( 0, $limit - (int) ceil( $estimate ) );

		return new Decision( true, $limit, $remaining, $window - $elapsed, $now + ( $window - $elapsed ) );
	}

	/**
	 * Test without consuming.
	 *
	 * For showing a caller how much allowance is left without spending
	 * any of it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key    Complete counter key.
	 * @param int    $limit  Permitted attempts per window.
	 * @param int    $window Window length in seconds.
	 *
	 * @return Decision
	 */
	#[\NoDiscard( 'Peeking only answers a question.' )]
	public function peek( string $key, int $limit, int $window ): Decision {
		$now      = $this->now();
		$elapsed  = $now % $window;
		$current  = intdiv( $now, $window );
		$estimate = $this->estimate( $key, $current, $window, $elapsed );

		return new Decision(
			$estimate < $limit,
			$limit,
			max( 0, $limit - (int) ceil( $estimate ) ),
			$window - $elapsed,
			$now + ( $window - $elapsed )
		);
	}

	/**
	 * Clear a key's counters.
	 *
	 * Call this after a successful login, so someone who mistyped their
	 * password four times is not still one attempt from a lockout.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key    Complete counter key.
	 * @param int    $window Window length the key was counted in.
	 *
	 * @return void
	 */
	public function clear( string $key, int $window ): void {
		$current = intdiv( $this->now(), max( 1, $window ) );

		$this->store->forget( $key . ':' . $current );
		$this->store->forget( $key . ':' . ( $current - 1 ) );
	}

	/**
	 * The current weighted count for a key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Counter key.
	 * @param int    $current Current window index.
	 * @param int    $window  Window length.
	 * @param int    $elapsed Seconds into the current window.
	 *
	 * @return float
	 */
	private function estimate( string $key, int $current, int $window, int $elapsed ): float {
		return $this->weighted( $key, $current, $window, $elapsed, $this->store->get( $key . ':' . $current ) );
	}

	/**
	 * Weight the previous window by how much of it is still in view.
	 *
	 * Ten seconds into a sixty-second window, fifty seconds of the
	 * previous window still fall inside the trailing minute, so it counts
	 * for 50/60 of its total.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Counter key.
	 * @param int    $current Current window index.
	 * @param int    $window  Window length.
	 * @param int    $elapsed Seconds into the current window.
	 * @param int    $count   Current window's count.
	 *
	 * @return float
	 */
	private function weighted( string $key, int $current, int $window, int $elapsed, int $count ): float {
		$previous = $this->store->get( $key . ':' . ( $current - 1 ) );

		if ( 0 === $previous ) {
			return (float) $count;
		}

		return $previous * ( ( $window - $elapsed ) / $window ) + $count;
	}

	/**
	 * Current time.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	private function now(): int {
		return $this->timestamp ?? time();
	}
}
