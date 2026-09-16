<?php
/**
 * The outcome of a rate-limit check.
 *
 * @package   SugarCommerce\RateLimit
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\RateLimit;

/**
 * Class Decision
 *
 * @since 1.0.0
 */
final readonly class Decision {

	/**
	 * @since 1.0.0
	 *
	 * @param bool $allowed     Whether the attempt may proceed.
	 * @param int  $limit       Attempts permitted per window.
	 * @param int  $remaining   Attempts left in this window.
	 * @param int  $retry_after Seconds until the window rolls over.
	 * @param int  $resets_at   Unix time the window rolls over.
	 */
	public function __construct(
		public bool $allowed,
		public int $limit,
		public int $remaining,
		public int $retry_after,
		public int $resets_at,
	) {}

	/**
	 * Whether the attempt was refused.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function denied(): bool {
		return ! $this->allowed;
	}

	/**
	 * The standard rate-limit response headers.
	 *
	 * `Retry-After` is only meaningful on a refusal, so it is omitted
	 * when the request was allowed.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		$headers = array(
			'X-RateLimit-Limit'     => (string) $this->limit,
			'X-RateLimit-Remaining' => (string) $this->remaining,
			'X-RateLimit-Reset'     => (string) $this->resets_at,
		);

		if ( ! $this->allowed ) {
			$headers['Retry-After'] = (string) $this->retry_after;
		}

		return $headers;
	}
}
