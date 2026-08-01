<?php
/**
 * Filesystem counter storage.
 *
 * @package   SugarMill\RateLimit
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\RateLimit\Stores;

use SugarMill\RateLimit\Store;

/**
 * Class File
 *
 * Counters in files. Slower than APCu and it touches the disk on every
 * request, but it needs no extension and survives a worker restart.
 *
 * The read and the write happen inside a single exclusive lock. The
 * obvious implementation — `file_get_contents()` then
 * `file_put_contents(..., LOCK_EX)` — leaves the read unprotected, so
 * two requests both read 9 and both write 10. It undercounts exactly
 * when it is under the load it exists to limit.
 *
 * @since 1.0.0
 */
final class File implements Store {

	/**
	 * @since 1.0.0
	 *
	 * @param string   $directory Where counters are written.
	 * @param int|null $timestamp Fixed clock, for tests.
	 *
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	public function __construct(
		private readonly string $directory,
		private readonly ?int $timestamp = null,
	) {
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
			throw new \RuntimeException( 'Could not create the rate-limit directory: ' . $directory );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function increment( string $key, int $ttl, int $by = 1 ): int {
		$path   = $this->path( $key );
		$handle = @fopen( $path, 'c+' );

		if ( false === $handle ) {
			// Failing open would remove the limit entirely; failing
			// closed would lock everyone out. Counting the request as
			// the first is the least-bad option and stays available.
			return $by;
		}

		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				return $by;
			}

			$now      = $this->now();
			$contents = stream_get_contents( $handle );
			[ $count, $expires ] = self::parse( is_string( $contents ) ? $contents : '' );

			if ( $expires <= $now ) {
				$count   = 0;
				$expires = $now + $ttl;
			}

			$count += $by;

			ftruncate( $handle, 0 );
			rewind( $handle );
			fwrite( $handle, $count . ':' . $expires );
			fflush( $handle );

			return $count;
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $key ): int {
		$contents = @file_get_contents( $this->path( $key ) );

		if ( false === $contents ) {
			return 0;
		}

		[ $count, $expires ] = self::parse( $contents );

		return $expires > $this->now() ? $count : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function forget( string $key ): void {
		@unlink( $this->path( $key ) );
	}

	/**
	 * Delete expired counter files.
	 *
	 * Nothing removes these on its own, so a busy site accumulates one
	 * file per key per window indefinitely. Call it from cron.
	 *
	 * @since 1.0.0
	 *
	 * @return int Files removed.
	 */
	public function prune(): int {
		$removed = 0;
		$now     = $this->now();

		foreach ( glob( $this->directory . '/*.count' ) ?: array() as $file ) {
			$contents = @file_get_contents( $file );

			if ( false === $contents ) {
				continue;
			}

			[ , $expires ] = self::parse( $contents );

			if ( $expires <= $now && @unlink( $file ) ) {
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * The file backing a key.
	 *
	 * Hashed, because keys contain email addresses and IP addresses and
	 * those should not become filenames.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Counter key.
	 *
	 * @return string
	 */
	private function path( string $key ): string {
		return $this->directory . '/' . hash( 'sha256', $key ) . '.count';
	}

	/**
	 * Parse the stored `count:expires` pair.
	 *
	 * @since 1.0.0
	 *
	 * @param string $contents File contents.
	 *
	 * @return array{0: int, 1: int}
	 */
	private static function parse( string $contents ): array {
		$parts = explode( ':', trim( $contents ), 2 );

		return array( (int) ( $parts[0] ?? 0 ), (int) ( $parts[1] ?? 0 ) );
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
