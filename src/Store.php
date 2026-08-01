<?php
/**
 * Counter storage.
 *
 * @package   SugarMill\RateLimit
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\RateLimit;

/**
 * Interface Store
 *
 * Where the counters live.
 *
 * The one requirement that matters is that {@see self::increment()} is
 * **atomic**. Read-then-write is the classic mistake here — two requests
 * both read 9, both write 10, and a limit of 10 lets 11 through. Under
 * exactly the burst the limiter exists to stop, it undercounts.
 *
 * @since 1.0.0
 */
interface Store {

	/**
	 * Add to a counter and return its new value.
	 *
	 * Must be atomic: concurrent callers must never observe the same
	 * result. The counter must expire on its own after `$ttl` seconds.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Counter key.
	 * @param int    $ttl Seconds until the counter expires.
	 * @param int    $by  Amount to add.
	 *
	 * @return int Value after the increment.
	 */
	public function increment( string $key, int $ttl, int $by = 1 ): int;

	/**
	 * Read a counter without changing it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Counter key.
	 *
	 * @return int Zero when absent or expired.
	 */
	public function get( string $key ): int;

	/**
	 * Forget a counter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Counter key.
	 *
	 * @return void
	 */
	public function forget( string $key ): void;
}
