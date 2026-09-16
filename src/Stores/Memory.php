<?php
/**
 * In-process counter storage.
 *
 * @package   SugarCommerce\RateLimit
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\RateLimit\Stores;

use SugarCommerce\RateLimit\Store;

/**
 * Class Memory
 *
 * Counters live for one request and vanish with the process.
 *
 * Useful in tests, and as a second-level check in a long-running worker.
 * Useless for rate limiting a web application: every PHP-FPM worker gets
 * its own copy, so the effective limit is your limit multiplied by the
 * worker count.
 *
 * @since 1.0.0
 */
final class Memory implements Store {

	/**
	 * Counters, as key => [ value, expires_at ].
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	private array $counters = array();

	/**
	 * @since 1.0.0
	 *
	 * @param int|null $timestamp Fixed clock, for tests.
	 */
	public function __construct( private readonly ?int $timestamp = null ) {}

	/**
	 * {@inheritDoc}
	 */
	public function increment( string $key, int $ttl, int $by = 1 ): int {
		$now = $this->now();

		if ( ! isset( $this->counters[ $key ] ) || $this->counters[ $key ][1] <= $now ) {
			$this->counters[ $key ] = array( 0, $now + $ttl );
		}

		$this->counters[ $key ][0] += $by;

		return $this->counters[ $key ][0];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key ): int {
		if ( ! isset( $this->counters[ $key ] ) || $this->counters[ $key ][1] <= $this->now() ) {
			return 0;
		}

		return $this->counters[ $key ][0];
	}

	/**
	 * {@inheritDoc}
	 */
	public function forget( string $key ): void {
		unset( $this->counters[ $key ] );
	}

	/**
	 * Discard every counter.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->counters = array();
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
