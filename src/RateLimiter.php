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
	 * A limiter on the best store this process can use.
	 *
	 * Shared memory when APCu is enabled *here*, the filesystem otherwise.
	 * "Here" is the point: `extension_loaded( 'apcu' )` and `apc.enabled`
	 * are both true on a command line where `apc.enable_cli` is off, and
	 * the APCu store then throws on construction — so a test run, a cron
	 * job or a deploy script dies on any server that has APCu installed
	 * for its web workers. {@see Stores\Apcu::available()} asks the
	 * question that matters.
	 *
	 * The file store is the fallback rather than the exception: slower,
	 * but a limit that works everywhere beats a fast one that does not.
	 *
	 * @since 1.2.0
	 *
	 * @param string $directory Where the file store keeps its counters.
	 * @param string $prefix    APCu key namespace.
	 *
	 * @return self
	 *
	 * @throws \RuntimeException When the file store's directory cannot be
	 *                           created.
	 */
	public static function auto( string $directory, string $prefix = 'rl:' ): self {
		return new self( Stores\Apcu::available() ? new Stores\Apcu( $prefix ) : new Stores\File( $directory ) );
	}

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
	 * The answer rests on the count the atomic increment returned, not on
	 * the read before it. Read-then-decide is the race the stores exist to
	 * prevent, one level up: a burst of requests that all read the count
	 * before any of them adds to it would all see room, and all be let
	 * through. Each increment returns a different count, so exactly the
	 * allowance passes however many arrive at once. The read beforehand
	 * only spares an attempt that is already over the limit from adding to
	 * a count it cannot change the answer of.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Decides on the incremented count.
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
		self::assert_allowance( $limit, $window );

		$now     = $this->now();
		$elapsed = $now % $window;
		$current = intdiv( $now, $window );
		$carried = $this->carried( $key, $current, $window, $elapsed );
		$denied  = new Decision( false, $limit, 0, $window - $elapsed, $now + ( $window - $elapsed ) );

		if ( $carried + $this->store->get( $key . ':' . $current ) >= $limit ) {
			return $denied;
		}

		// Two windows are retained so the previous one can still be
		// weighted while it remains partly in view.
		$count = $this->store->increment( $key . ':' . $current, $window * 2 );

		// What the count was just before this attempt, as this attempt alone
		// saw it. The same test as above, on a number no other request got.
		if ( $carried + ( $count - 1 ) >= $limit ) {
			return $denied;
		}

		$remaining = max( 0, $limit - (int) ceil( $carried + $count ) );

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
	 *
	 * @throws \InvalidArgumentException On a non-positive limit or window.
	 */
	#[\NoDiscard( 'Peeking only answers a question.' )]
	public function peek( string $key, int $limit, int $window ): Decision {
		self::assert_allowance( $limit, $window );

		$now      = $this->now();
		$elapsed  = $now % $window;
		$current  = intdiv( $now, $window );
		$estimate = $this->carried( $key, $current, $window, $elapsed ) + $this->store->get( $key . ':' . $current );

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
	 * Refuse an allowance that cannot be counted against.
	 *
	 * @since 1.3.0
	 *
	 * @param int $limit  Permitted attempts per window.
	 * @param int $window Window length in seconds.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException On a non-positive limit or window.
	 */
	private static function assert_allowance( int $limit, int $window ): void {
		if ( $limit < 1 || $window < 1 ) {
			throw new \InvalidArgumentException( 'Rate limit and window must both be at least 1.' );
		}
	}

	/**
	 * What the previous window still counts for: its total, weighted by how
	 * much of it is still in view.
	 *
	 * Ten seconds into a sixty-second window, fifty seconds of the
	 * previous window still fall inside the trailing minute, so it counts
	 * for 50/60 of its total. Add the current window's count for the
	 * estimate.
	 *
	 * @since 1.3.0
	 *
	 * @param string $key     Counter key.
	 * @param int    $current Current window index.
	 * @param int    $window  Window length.
	 * @param int    $elapsed Seconds into the current window.
	 *
	 * @return float
	 */
	private function carried( string $key, int $current, int $window, int $elapsed ): float {
		$previous = $this->store->get( $key . ':' . ( $current - 1 ) );

		return 0 === $previous ? 0.0 : $previous * ( ( $window - $elapsed ) / $window );
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
