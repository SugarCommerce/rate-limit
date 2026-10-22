<?php
/**
 * SQLite counter storage.
 *
 * @package   SugarCommerce\RateLimit
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\RateLimit\Stores;

use SugarCommerce\RateLimit\Store;

/**
 * Class Sqlite
 *
 * Counters in a table, for an application that already runs on SQLite.
 * Shared by every worker that opens the same database file, survives a
 * PHP-FPM restart, and needs no extension beyond `pdo_sqlite`.
 *
 * The increment is one statement — an upsert that resets an expired
 * row, adds to a live one and returns the result. SQLite runs a
 * statement under its write lock from start to finish, so there is no
 * window between reading the count and writing it for another worker to
 * slip through. A SELECT then an UPDATE has exactly that window, and
 * wrapping them in a deferred transaction does not close it.
 *
 * The window starts with the first increment and is not extended by
 * later ones, the same as the other stores. A row past its expiry reads
 * as zero and is reused in place by the next increment.
 *
 * Expired rows are kept until something deletes them, and the limiter
 * writes a new key every window, so they accumulate. One increment in
 * `$prune_odds` also prunes; set it to 0 and call {@see self::prune()}
 * from cron if you would rather no request ever paid for housekeeping.
 *
 * Set a `busy_timeout` on the connection. Without one, a worker that
 * finds the database locked fails at once instead of waiting its turn —
 * and a limiter is written to under exactly the burst that causes it.
 *
 * The table, for a schema file or a migration — {@see self::schema()}
 * gives it with your table name, {@see self::install()} creates it:
 *
 *   CREATE TABLE IF NOT EXISTS rate_limits (
 *       counter_key TEXT    PRIMARY KEY NOT NULL,
 *       hits        INTEGER NOT NULL,
 *       expires_at  INTEGER NOT NULL
 *   );
 *   CREATE INDEX IF NOT EXISTS rate_limits_expires_at ON rate_limits ( expires_at );
 *
 * Needs SQLite 3.35 or later, for `RETURNING`.
 *
 * @since 1.3.0
 */
final class Sqlite implements Store {

	/**
	 * Table name when none is given.
	 *
	 * @since 1.3.0
	 */
	public const string TABLE = 'rate_limits';

	/**
	 * Oldest SQLite with `RETURNING`.
	 *
	 * @since 1.3.0
	 */
	private const string MINIMUM_VERSION = '3.35.0';

	/**
	 * @since 1.3.0
	 *
	 * @param \PDO     $pdo        An SQLite connection.
	 * @param string   $table      Table name. Interpolated into SQL, so
	 *                             it must be a plain identifier.
	 * @param int      $prune_odds One increment in this many also deletes
	 *                             expired rows. 0 never does.
	 * @param int|null $timestamp  Fixed clock, for tests.
	 *
	 * @throws \InvalidArgumentException When the connection is not SQLite,
	 *                                   the table name is not a plain
	 *                                   identifier, or the odds are
	 *                                   negative.
	 * @throws \RuntimeException         When SQLite is older than 3.35.
	 */
	public function __construct(
		private readonly \PDO $pdo,
		private readonly string $table = self::TABLE,
		private readonly int $prune_odds = 1000,
		private readonly ?int $timestamp = null,
	) {
		if ( 'sqlite' !== $pdo->getAttribute( \PDO::ATTR_DRIVER_NAME ) ) {
			throw new \InvalidArgumentException( 'The SQLite store needs an SQLite connection.' );
		}

		// Identifiers cannot be bound, so the name is checked rather
		// than escaped: if it is not a bare word it never reaches SQL.
		// `D`, or `$` lets a trailing newline through. SQLite reserves
		// `sqlite_` for itself and refuses to create it.
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $table ) || 0 === stripos( $table, 'sqlite_' ) ) {
			throw new \InvalidArgumentException( 'Not a plain table name: ' . $table );
		}

		if ( $prune_odds < 0 ) {
			throw new \InvalidArgumentException( 'Prune odds cannot be negative.' );
		}

		$version = (string) $pdo->getAttribute( \PDO::ATTR_SERVER_VERSION );

		if ( version_compare( $version, self::MINIMUM_VERSION, '<' ) ) {
			throw new \RuntimeException(
				'The SQLite store needs SQLite ' . self::MINIMUM_VERSION . ' or later for RETURNING; this is ' . $version . '.'
			);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function increment( string $key, int $ttl, int $by = 1 ): int {
		$now = $this->now();

		// Every right-hand side in an UPDATE sees the row as it was, so
		// both CASEs test the old expiry — the count and the window are
		// reset together or not at all.
		$hits = $this->run(
			"INSERT INTO \"{$this->table}\" ( counter_key, hits, expires_at ) VALUES ( :key, :by, :expires ) "
			. 'ON CONFLICT ( counter_key ) DO UPDATE SET '
			. 'hits = CASE WHEN expires_at <= :now THEN excluded.hits ELSE hits + excluded.hits END, '
			. 'expires_at = CASE WHEN expires_at <= :now THEN excluded.expires_at ELSE expires_at END '
			. 'RETURNING hits',
			array( 'key' => $key, 'by' => $by, 'expires' => $now + $ttl, 'now' => $now )
		)->fetchColumn();

		if ( $this->prune_odds > 0 && 1 === random_int( 1, $this->prune_odds ) ) {
			try {
				$this->prune();
			} catch ( \RuntimeException ) {
				// Housekeeping. The count is already written, and a
				// busy database is no reason to fail the request.
			}
		}

		return (int) $hits;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function get( string $key ): int {
		$hits = $this->run(
			"SELECT hits FROM \"{$this->table}\" WHERE counter_key = :key AND expires_at > :now",
			array( 'key' => $key, 'now' => $this->now() )
		)->fetchColumn();

		return false === $hits ? 0 : (int) $hits;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function forget( string $key ): void {
		$this->run( "DELETE FROM \"{$this->table}\" WHERE counter_key = :key", array( 'key' => $key ) );
	}

	/**
	 * Delete expired counters.
	 *
	 * Runs on its own one increment in `$prune_odds`; call it from cron as
	 * well, or instead, on a database that sees bursts of new keys.
	 *
	 * @since 1.3.0
	 *
	 * @return int Rows removed.
	 */
	public function prune(): int {
		return $this->run(
			"DELETE FROM \"{$this->table}\" WHERE expires_at <= :now",
			array( 'now' => $this->now() )
		)->rowCount();
	}

	/**
	 * The table and its index, for a schema file or a migration.
	 *
	 * @since 1.3.0
	 *
	 * @return string Two statements, separated by a semicolon.
	 */
	public function schema(): string {
		return "CREATE TABLE IF NOT EXISTS \"{$this->table}\" (\n"
			. "\tcounter_key TEXT    PRIMARY KEY NOT NULL,\n"
			. "\thits        INTEGER NOT NULL,\n"
			. "\texpires_at  INTEGER NOT NULL\n"
			. ");\n"
			. "CREATE INDEX IF NOT EXISTS \"{$this->table}_expires_at\" ON \"{$this->table}\" ( expires_at );";
	}

	/**
	 * Create the table and its index, unless they are there already.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When SQLite refuses.
	 */
	public function install(): void {
		if ( false === $this->pdo->exec( $this->schema() ) ) {
			throw new \RuntimeException( 'Could not create the rate-limit table: ' . $this->error( $this->pdo->errorInfo() ) );
		}
	}

	/**
	 * Prepare and run a statement.
	 *
	 * Throws whatever the connection's error mode, so a connection set
	 * to `ERRMODE_SILENT` cannot turn a missing table into a limiter that
	 * quietly counts nothing.
	 *
	 * @since 1.3.0
	 *
	 * @param string                    $sql    Statement.
	 * @param array<string, int|string> $params Named parameters.
	 *
	 * @return \PDOStatement
	 *
	 * @throws \RuntimeException When SQLite refuses.
	 */
	private function run( string $sql, array $params ): \PDOStatement {
		$statement = $this->pdo->prepare( $sql );

		if ( false === $statement ) {
			throw new \RuntimeException( 'Rate-limit query failed: ' . $this->error( $this->pdo->errorInfo() ) );
		}

		foreach ( $params as $name => $value ) {
			$statement->bindValue( ':' . $name, $value, is_int( $value ) ? \PDO::PARAM_INT : \PDO::PARAM_STR );
		}

		if ( ! $statement->execute() ) {
			throw new \RuntimeException( 'Rate-limit query failed: ' . $this->error( $statement->errorInfo() ) );
		}

		return $statement;
	}

	/**
	 * A driver error, as one line.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int, mixed> $info From `errorInfo()`.
	 *
	 * @return string
	 */
	private function error( array $info ): string {
		return (string) ( $info[2] ?? $info[0] ?? 'unknown error' );
	}

	/**
	 * Current time.
	 *
	 * @since 1.3.0
	 *
	 * @return int
	 */
	private function now(): int {
		return $this->timestamp ?? time();
	}
}
