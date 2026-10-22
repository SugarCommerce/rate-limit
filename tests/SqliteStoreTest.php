<?php
/**
 * SQLite store test suite.
 *
 * @package   SugarCommerce\RateLimit
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\RateLimit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\RateLimit\RateLimiter;
use SugarCommerce\RateLimit\Stores\Sqlite;

final class SqliteStoreTest extends TestCase {

	private const int NOW = 1700000000;

	private \PDO $pdo;

	protected function setUp(): void {
		$this->pdo = new \PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );

		( new Sqlite( $this->pdo ) )->install();
	}

	/**
	 * A store over the shared connection, at a given moment, that never
	 * prunes on its own unless asked to.
	 */
	private function store( int $now = self::NOW, int $prune_odds = 0, string $table = Sqlite::TABLE ): Sqlite {
		return new Sqlite( $this->pdo, $table, $prune_odds, $now );
	}

	private function rows( string $table = Sqlite::TABLE ): int {
		return (int) $this->pdo->query( 'SELECT COUNT(*) FROM "' . $table . '"' )->fetchColumn();
	}

	/* ─── Counting ──────────────────────────────────────────────────── */

	public function test_increment_returns_the_new_count(): void {
		$store = $this->store();

		$this->assertSame( 1, $store->increment( 'k', 60 ) );
		$this->assertSame( 2, $store->increment( 'k', 60 ) );
		$this->assertSame( 3, $store->increment( 'k', 60 ) );
		$this->assertSame( 3, $store->get( 'k' ) );
	}

	public function test_increment_adds_the_amount_given(): void {
		$store = $this->store();

		$this->assertSame( 5, $store->increment( 'k', 60, 5 ) );
		$this->assertSame( 8, $store->increment( 'k', 60, 3 ) );
		$this->assertSame( 8, $store->get( 'k' ) );
	}

	public function test_keys_count_separately(): void {
		$store = $this->store();

		$store->increment( 'a', 60 );
		$store->increment( 'a', 60 );
		$store->increment( 'b', 60 );

		$this->assertSame( 2, $store->get( 'a' ) );
		$this->assertSame( 1, $store->get( 'b' ) );
	}

	public function test_a_missing_key_reads_as_zero(): void {
		$this->assertSame( 0, $this->store()->get( 'nobody' ) );
	}

	/* ─── Expiry ────────────────────────────────────────────────────── */

	public function test_a_counter_expires_after_its_ttl(): void {
		$this->store()->increment( 'k', 60 );
		$this->store()->increment( 'k', 60 );

		$this->assertSame( 2, $this->store( self::NOW + 59 )->get( 'k' ) );
		$this->assertSame( 0, $this->store( self::NOW + 60 )->get( 'k' ), 'expired at exactly its ttl' );
		$this->assertSame( 0, $this->store( self::NOW + 61 )->get( 'k' ) );
	}

	/**
	 * The window starts with the first increment. A counter that every
	 * increment pushed further out would never expire for anyone who
	 * keeps trying, which is not what the other stores do.
	 */
	public function test_later_increments_do_not_extend_the_window(): void {
		$this->store()->increment( 'k', 60 );
		$this->store( self::NOW + 50 )->increment( 'k', 60 );

		$this->assertSame( 2, $this->store( self::NOW + 59 )->get( 'k' ) );
		$this->assertSame( 0, $this->store( self::NOW + 60 )->get( 'k' ) );
	}

	public function test_an_expired_counter_starts_a_new_window(): void {
		$this->store()->increment( 'k', 60, 4 );

		$later = self::NOW + 61;

		$this->assertSame( 1, $this->store( $later )->increment( 'k', 60 ), 'counts from zero again' );
		$this->assertSame( 2, $this->store( $later )->increment( 'k', 60 ) );

		// The expiry moved with the count: it is the new window's, not
		// the old one's, and not one from each.
		$this->assertSame( 2, $this->store( $later + 59 )->get( 'k' ) );
		$this->assertSame( 0, $this->store( $later + 60 )->get( 'k' ) );
	}

	public function test_an_expired_counter_is_reused_rather_than_duplicated(): void {
		$this->store()->increment( 'k', 60 );
		$this->store( self::NOW + 61 )->increment( 'k', 60 );

		$this->assertSame( 1, $this->rows() );
	}

	/* ─── Forgetting and pruning ────────────────────────────────────── */

	public function test_forget_removes_only_that_counter(): void {
		$store = $this->store();

		$store->increment( 'a', 60 );
		$store->increment( 'b', 60 );
		$store->forget( 'a' );

		$this->assertSame( 0, $store->get( 'a' ) );
		$this->assertSame( 1, $store->get( 'b' ) );
		$this->assertSame( 1, $store->increment( 'a', 60 ), 'a forgotten counter starts again' );
	}

	public function test_forgetting_a_missing_key_is_harmless(): void {
		$this->store()->forget( 'nobody' );

		$this->assertSame( 0, $this->rows() );
	}

	public function test_prune_removes_only_expired_counters(): void {
		$this->store()->increment( 'old', 60 );
		$this->store( self::NOW + 30 )->increment( 'new', 60 );

		$this->assertSame( 0, $this->store()->prune(), 'nothing expired yet' );
		$this->assertSame( 1, $this->store( self::NOW + 60 )->prune() );
		$this->assertSame( 1, $this->rows() );
		$this->assertSame( 1, $this->store( self::NOW + 60 )->get( 'new' ) );
	}

	public function test_increments_prune_at_the_odds_given(): void {
		$this->store()->increment( 'old', 60 );

		$this->store( self::NOW + 61, 0 )->increment( 'a', 60 );
		$this->assertSame( 2, $this->rows(), 'odds of 0 never prune' );

		$this->store( self::NOW + 61, 1 )->increment( 'b', 60 );
		$this->assertSame( 2, $this->rows(), 'odds of 1 prune every time' );
		$this->assertSame( 0, $this->store( self::NOW + 61 )->get( 'old' ) );
	}

	public function test_negative_prune_odds_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->store( prune_odds: -1 );
	}

	/* ─── Schema ────────────────────────────────────────────────────── */

	public function test_install_is_safe_to_repeat(): void {
		$this->store()->increment( 'k', 60 );
		$this->store()->install();

		$this->assertSame( 1, $this->store()->get( 'k' ), 'an existing table is left alone' );
	}

	public function test_install_indexes_the_expiry(): void {
		$index = $this->pdo->query( "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'rate_limits_expires_at'" )->fetchColumn();

		$this->assertIsString( $index );
		$this->assertStringContainsString( 'expires_at', $index );
	}

	public function test_the_schema_names_the_table_given(): void {
		$schema = $this->store( table: 'throttle' )->schema();

		$this->assertStringContainsString( 'CREATE TABLE IF NOT EXISTS "throttle"', $schema );
		$this->assertStringContainsString( '"throttle_expires_at" ON "throttle"', $schema );
	}

	public function test_a_custom_table_is_used(): void {
		$store = $this->store( table: 'throttle' );
		$store->install();

		$this->assertSame( 1, $store->increment( 'k', 60 ) );
		$this->assertSame( 1, $this->rows( 'throttle' ) );
		$this->assertSame( 0, $this->rows(), 'the default table is untouched' );
	}

	/**
	 * The name is interpolated into SQL, so anything but a bare word is
	 * refused before it can get there.
	 */
	#[DataProvider( 'unsafe_tables' )]
	public function test_an_unsafe_table_name_is_refused( string $table ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->store( table: $table );
	}

	/** @return array<string, array{0: string}> */
	public static function unsafe_tables(): array {
		return array(
			'empty'            => array( '' ),
			'leading digit'    => array( '1limits' ),
			'hyphen'           => array( 'rate-limits' ),
			'space'            => array( 'rate limits' ),
			'dot'              => array( 'main.rate_limits' ),
			'quote'            => array( 'x"; DROP TABLE users; --' ),
			'semicolon'        => array( 'x;y' ),
			'unicode'          => array( 'lïmits' ),
			'trailing newline' => array( "rate_limits\n" ),
			'reserved prefix'  => array( 'sqlite_limits' ),
			'too long'         => array( str_repeat( 'a', 64 ) ),
		);
	}

	public function test_a_missing_table_fails_loudly(): void {
		$this->expectException( \PDOException::class );
		$this->store( table: 'never_installed' )->increment( 'k', 60 );
	}

	/**
	 * Under ERRMODE_SILENT a failed query returns false, and a store that
	 * took that as zero would quietly stop limiting anything.
	 */
	public function test_a_silent_connection_still_fails_loudly(): void {
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT );

		$this->expectException( \RuntimeException::class );
		$this->store( table: 'never_installed' )->get( 'k' );
	}

	/* ─── Sharing ───────────────────────────────────────────────────── */

	/**
	 * The reason for the store: two workers, or a worker before and after
	 * a restart, opening the same file see the same counters.
	 */
	public function test_connections_to_the_same_file_share_counters(): void {
		$path = sys_get_temp_dir() . '/rate-limit-test-' . bin2hex( random_bytes( 6 ) ) . '.sqlite';

		try {
			$first = new Sqlite( new \PDO( 'sqlite:' . $path ), Sqlite::TABLE, 0, self::NOW );
			$first->install();

			$second = new Sqlite( new \PDO( 'sqlite:' . $path ), Sqlite::TABLE, 0, self::NOW );

			$this->assertSame( 1, $first->increment( 'k', 60 ) );
			$this->assertSame( 2, $second->increment( 'k', 60 ) );
			$this->assertSame( 3, $first->increment( 'k', 60 ) );
			$this->assertSame( 3, $second->get( 'k' ) );

			unset( $first, $second );

			$this->assertSame( 3, ( new Sqlite( new \PDO( 'sqlite:' . $path ), Sqlite::TABLE, 0, self::NOW ) )->get( 'k' ) );
		} finally {
			@unlink( $path );
		}
	}

	/* ─── Under the limiter ─────────────────────────────────────────── */

	public function test_a_limiter_works_over_the_sqlite_store(): void {
		$limiter = new RateLimiter( $this->store(), self::NOW );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $limiter->for( 'login', '1.2.3.4' )->check( 3, 60 )->allowed );
		}

		$this->assertTrue( $limiter->for( 'login', '1.2.3.4' )->check( 3, 60 )->denied() );
		$this->assertTrue( $limiter->for( 'login', '5.6.7.8' )->check( 3, 60 )->allowed );

		$limiter->for( 'login', '1.2.3.4' )->clear( 60 );

		$this->assertTrue( $limiter->for( 'login', '1.2.3.4' )->check( 3, 60 )->allowed );
	}

	public function test_a_burst_across_the_window_boundary_is_caught(): void {
		$before = new RateLimiter( $this->store( self::NOW + 59 ), self::NOW + 59 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $before->check( 'k', 5, 60 )->allowed );
		}

		$after = new RateLimiter( $this->store( self::NOW + 61 ), self::NOW + 61 );

		$this->assertTrue( $after->check( 'k', 5, 60 )->denied() );
	}

	public function test_the_allowance_returns_once_the_window_has_passed(): void {
		$before = new RateLimiter( $this->store(), self::NOW );

		for ( $i = 0; $i < 5; $i++ ) {
			(void) $before->check( 'k', 5, 60 );
		}

		$later = self::NOW + 130;

		$this->assertTrue( ( new RateLimiter( $this->store( $later ), $later ) )->check( 'k', 5, 60 )->allowed );
	}
}
