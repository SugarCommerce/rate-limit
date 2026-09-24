<?php
/**
 * APCu counter storage.
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
 * Class Apcu
 *
 * Shared memory, atomic, and fast — the right default for a single
 * server. `apcu_inc()` increments under a lock inside the extension, so
 * there is no read-then-write window to lose counts through.
 *
 * Counters are per-machine. Behind a load balancer each server keeps its
 * own, so the effective limit multiplies by the server count. Use a
 * shared store when that matters.
 *
 * @since 1.0.0
 */
final class Apcu implements Store {

	/**
	 * @since 1.0.0
	 *
	 * @param string $prefix Namespace, so several applications can share
	 *                       one APCu segment without colliding.
	 *
	 * @throws \RuntimeException When APCu is unavailable or disabled.
	 */
	public function __construct( private readonly string $prefix = 'rl:' ) {
		if ( ! self::available() ) {
			throw new \RuntimeException(
				'APCu is not available. Install ext-apcu, and note that it is disabled on CLI '
				. 'unless apc.enable_cli=1.'
			);
		}
	}

	/**
	 * Whether APCu can be used in this process.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'apcu_enabled' ) && apcu_enabled();
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function increment( string $key, int $ttl, int $by = 1 ): int {
		$key = $this->prefix . $key;

		// apcu_inc with $success tells us whether the key already
		// existed; creating it separately would reopen the race.
		$value = apcu_inc( $key, $by, $success, $ttl );

		return false === $value ? $by : (int) $value;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function get( string $key ): int {
		$value = apcu_fetch( $this->prefix . $key, $found );

		return $found ? (int) $value : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function forget( string $key ): void {
		apcu_delete( $this->prefix . $key );
	}
}
