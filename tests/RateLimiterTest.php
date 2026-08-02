<?php
/**
 * RateLimiter test suite.
 *
 * @package   Sugar\RateLimit
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace Sugar\RateLimit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sugar\RateLimit\Decision;
use Sugar\RateLimit\RateLimiter;
use Sugar\RateLimit\Store;
use Sugar\RateLimit\Stores\File;
use Sugar\RateLimit\Stores\Memory;

final class RateLimiterTest extends TestCase {

	private const NOW = 1700000000;

	private function limiter( ?int $now = self::NOW, ?Store $store = null ): RateLimiter {
		return new RateLimiter( $store ?? new Memory( $now ), $now );
	}

	/* ─── Allowing and denying ──────────────────────────────────────── */

	public function test_attempts_within_the_limit_are_allowed(): void {
		$limiter = $this->limiter();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $limiter->check( 'k', 5, 60 )->allowed, 'attempt ' . ( $i + 1 ) );
		}
	}

	public function test_the_attempt_after_the_limit_is_denied(): void {
		$limiter = $this->limiter();

		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->check( 'k', 5, 60 );
		}

		$this->assertTrue( $limiter->check( 'k', 5, 60 )->denied() );
	}

	public function test_remaining_counts_down(): void {
		$limiter = $this->limiter();

		$this->assertSame( 4, $limiter->check( 'k', 5, 60 )->remaining );
		$this->assertSame( 3, $limiter->check( 'k', 5, 60 )->remaining );
		$this->assertSame( 2, $limiter->check( 'k', 5, 60 )->remaining );
	}

	public function test_a_denial_reports_when_to_retry(): void {
		// 30 seconds into a 60-second window.
		$limiter = $this->limiter( self::NOW + 30 );

		for ( $i = 0; $i < 3; $i++ ) {
			$limiter->check( 'k', 3, 60 );
		}

		$decision = $limiter->check( 'k', 3, 60 );

		$this->assertTrue( $decision->denied() );
		$this->assertSame( 10, $decision->retry_after );
		$this->assertSame( 0, $decision->remaining );
	}

	public function test_separate_keys_have_separate_allowances(): void {
		$store   = new Memory( self::NOW );
		$limiter = $this->limiter( self::NOW, $store );

		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->check( 'a', 5, 60 );
		}

		$this->assertTrue( $limiter->check( 'a', 5, 60 )->denied() );
		$this->assertTrue( $limiter->check( 'b', 5, 60 )->allowed );
	}

	/* ─── The identity requirement ──────────────────────────────────── */

	/**
	 * The mistake this API exists to prevent. Without an identity every
	 * visitor shares one counter, so any one of them can lock out all the
	 * others — a denial of service you built yourself, that reads as
	 * correct in review.
	 */
	public function test_an_empty_identity_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->limiter()->for( 'login', '' );
	}

	public function test_a_whitespace_identity_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->limiter()->for( 'login', '   ' );
	}

	public function test_an_empty_action_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->limiter()->for( '', '1.2.3.4' );
	}

	public function test_different_identities_do_not_share_an_allowance(): void {
		$limiter = $this->limiter();

		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->for( 'login', '1.2.3.4' )->check( 5, 60 );
		}

		$this->assertTrue( $limiter->for( 'login', '1.2.3.4' )->check( 5, 60 )->denied() );
		$this->assertTrue( $limiter->for( 'login', '5.6.7.8' )->check( 5, 60 )->allowed );
	}

	public function test_different_actions_do_not_share_an_allowance(): void {
		$limiter = $this->limiter();

		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->for( 'login', '1.2.3.4' )->check( 5, 60 );
		}

		$this->assertTrue( $limiter->for( 'signup', '1.2.3.4' )->check( 5, 60 )->allowed );
	}

	public function test_identities_are_hashed_into_the_key(): void {
		$key = $this->limiter()->for( 'login', 'dave@example.com' )->key();

		$this->assertStringStartsWith( 'login:', $key );
		$this->assertStringNotContainsString( 'dave@example.com', $key );
	}

	public function test_identity_matching_ignores_case_and_padding(): void {
		$limiter = $this->limiter();

		$this->assertSame(
			$limiter->for( 'login', 'Dave@Example.com' )->key(),
			$limiter->for( 'login', ' dave@example.com ' )->key()
		);
	}

	/* ─── Sliding window ────────────────────────────────────────────── */

	/**
	 * A fixed window lets someone spend the whole allowance at the end of
	 * one window and again at the start of the next — double the limit
	 * across a couple of seconds. The previous window is weighted by how
	 * much of it remains in view, so that burst is caught.
	 */
	public function test_a_burst_across_the_window_boundary_is_caught(): void {
		$store = new Memory();

		// Fill the allowance at the very end of a window.
		$before = new RateLimiter( $store, self::NOW + 59 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $before->check( 'k', 5, 60 )->allowed );
		}

		// One second later, in the next window: a fixed-window limiter
		// would reset and allow five more immediately.
		$after = new RateLimiter( $store, self::NOW + 61 );

		$this->assertTrue( $after->check( 'k', 5, 60 )->denied() );
	}

	public function test_the_allowance_returns_once_the_window_has_passed(): void {
		$store  = new Memory();
		$before = new RateLimiter( $store, self::NOW );

		for ( $i = 0; $i < 5; $i++ ) {
			$before->check( 'k', 5, 60 );
		}

		// Two full windows later nothing remains in view.
		$this->assertTrue( ( new RateLimiter( $store, self::NOW + 130 ) )->check( 'k', 5, 60 )->allowed );
	}

	/* ─── Peek and clear ────────────────────────────────────────────── */

	public function test_peeking_does_not_consume(): void {
		$limiter = $this->limiter();

		$this->assertSame( 5, $limiter->peek( 'k', 5, 60 )->remaining );
		$this->assertSame( 5, $limiter->peek( 'k', 5, 60 )->remaining );
		$this->assertSame( 4, $limiter->check( 'k', 5, 60 )->remaining );
		$this->assertSame( 4, $limiter->peek( 'k', 5, 60 )->remaining );
	}

	public function test_clearing_restores_the_allowance(): void {
		$limiter = $this->limiter();

		for ( $i = 0; $i < 5; $i++ ) {
			$limiter->check( 'k', 5, 60 );
		}

		$this->assertTrue( $limiter->check( 'k', 5, 60 )->denied() );

		$limiter->clear( 'k', 60 );

		$this->assertTrue( $limiter->check( 'k', 5, 60 )->allowed );
	}

	public function test_clearing_through_the_bound_limit(): void {
		$limiter = $this->limiter();
		$limit   = $limiter->for( 'login', '1.2.3.4' );

		for ( $i = 0; $i < 3; $i++ ) {
			$limit->check( 3, 60 );
		}

		$this->assertTrue( $limit->check( 3, 60 )->denied() );

		$limit->clear( 60 );

		$this->assertTrue( $limit->check( 3, 60 )->allowed );
	}

	/* ─── Validation ────────────────────────────────────────────────── */

	#[DataProvider( 'invalid_allowances' )]
	public function test_nonsensical_allowances_are_refused( int $limit, int $window ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->limiter()->check( 'k', $limit, $window );
	}

	/** @return array<string, array{0: int, 1: int}> */
	public static function invalid_allowances(): array {
		return array(
			'zero limit'      => array( 0, 60 ),
			'negative limit'  => array( -1, 60 ),
			'zero window'     => array( 5, 0 ),
			'negative window' => array( 5, -60 ),
		);
	}

	/* ─── Headers ───────────────────────────────────────────────────── */

	public function test_allowed_responses_omit_retry_after(): void {
		$headers = $this->limiter()->check( 'k', 5, 60 )->headers();

		$this->assertSame( '5', $headers['X-RateLimit-Limit'] );
		$this->assertSame( '4', $headers['X-RateLimit-Remaining'] );
		$this->assertArrayHasKey( 'X-RateLimit-Reset', $headers );
		$this->assertArrayNotHasKey( 'Retry-After', $headers );
	}

	public function test_denied_responses_include_retry_after(): void {
		$limiter = $this->limiter();

		for ( $i = 0; $i < 2; $i++ ) {
			$limiter->check( 'k', 2, 60 );
		}

		$this->assertArrayHasKey( 'Retry-After', $limiter->check( 'k', 2, 60 )->headers() );
	}

	/* ─── Stores ────────────────────────────────────────────────────── */

	public function test_memory_store_expires_counters(): void {
		$store = new Memory( self::NOW );

		$this->assertSame( 1, $store->increment( 'k', 60 ) );
		$this->assertSame( 2, $store->increment( 'k', 60 ) );
		$this->assertSame( 2, $store->get( 'k' ) );

		$this->assertSame( 0, ( new Memory( self::NOW + 61 ) )->get( 'k' ) );
	}

	public function test_memory_store_forgets(): void {
		$store = new Memory( self::NOW );
		$store->increment( 'k', 60 );
		$store->forget( 'k' );

		$this->assertSame( 0, $store->get( 'k' ) );
	}

	public function test_file_store_round_trips(): void {
		$directory = sys_get_temp_dir() . '/rate-limit-test-' . bin2hex( random_bytes( 6 ) );

		try {
			$store = new File( $directory, self::NOW );

			$this->assertSame( 1, $store->increment( 'k', 60 ) );
			$this->assertSame( 2, $store->increment( 'k', 60 ) );
			$this->assertSame( 2, $store->get( 'k' ) );

			$store->forget( 'k' );
			$this->assertSame( 0, $store->get( 'k' ) );
		} finally {
			array_map( 'unlink', glob( $directory . '/*' ) ?: array() );
			@rmdir( $directory );
		}
	}

	public function test_file_store_keeps_identities_out_of_filenames(): void {
		$directory = sys_get_temp_dir() . '/rate-limit-test-' . bin2hex( random_bytes( 6 ) );

		try {
			( new File( $directory, self::NOW ) )->increment( 'login:dave@example.com', 60 );

			foreach ( glob( $directory . '/*' ) ?: array() as $file ) {
				$this->assertStringNotContainsString( 'dave', basename( $file ) );
				$this->assertStringNotContainsString( '@', basename( $file ) );
			}
		} finally {
			array_map( 'unlink', glob( $directory . '/*' ) ?: array() );
			@rmdir( $directory );
		}
	}

	public function test_file_store_prunes_expired_counters(): void {
		$directory = sys_get_temp_dir() . '/rate-limit-test-' . bin2hex( random_bytes( 6 ) );

		try {
			( new File( $directory, self::NOW ) )->increment( 'k', 60 );

			$this->assertSame( 0, ( new File( $directory, self::NOW ) )->prune(), 'not yet expired' );
			$this->assertSame( 1, ( new File( $directory, self::NOW + 61 ) )->prune() );
		} finally {
			array_map( 'unlink', glob( $directory . '/*' ) ?: array() );
			@rmdir( $directory );
		}
	}

	public function test_a_limiter_works_over_the_file_store(): void {
		$directory = sys_get_temp_dir() . '/rate-limit-test-' . bin2hex( random_bytes( 6 ) );

		try {
			$limiter = new RateLimiter( new File( $directory, self::NOW ), self::NOW );

			for ( $i = 0; $i < 3; $i++ ) {
				$this->assertTrue( $limiter->for( 'login', '1.2.3.4' )->check( 3, 60 )->allowed );
			}

			$this->assertTrue( $limiter->for( 'login', '1.2.3.4' )->check( 3, 60 )->denied() );
			$this->assertTrue( $limiter->for( 'login', '5.6.7.8' )->check( 3, 60 )->allowed );
		} finally {
			array_map( 'unlink', glob( $directory . '/*' ) ?: array() );
			@rmdir( $directory );
		}
	}
}
