<?php
/**
 * An application's rate limits, named.
 *
 * @package   SugarCommerce\RateLimit
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\RateLimit;

/**
 * Class Rules
 *
 * Every limit an application enforces, in one table of
 * `action => [ limit, seconds ]`, and the only way call sites reach the
 * limiter.
 *
 * Without it each call site writes its own numbers next to its own
 * `->for( … )->check( 5, 3600 )`, and "how many lookups does an address
 * get?" is answered by a grep that finds two different answers. With it
 * the numbers live in one place and a call site names the action.
 *
 * Two cases are never limited, on purpose, and both answer with
 * {@see Decision::unlimited()} rather than throwing:
 *
 * - **An action with no rule.** A typo in an action name should not take
 *   an endpoint down; {@see self::has()} is there for a test that wants to
 *   prove every name it uses is real.
 * - **An empty key.** The caller had nothing to count against — no address
 *   behind a misconfigured proxy, no email on a form. Counting every such
 *   caller against one shared '' counter would let any of them lock out
 *   all the others, which is the failure {@see RateLimiter::for()} exists to
 *   prevent.
 *
 * @since 1.2.0
 */
final readonly class Rules {

	/**
	 * Each action's rule, as [ attempts allowed, window in seconds ].
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	private array $rules;

	/**
	 * @since 1.2.0
	 *
	 * @param RateLimiter                          $limiter Where attempts are counted.
	 * @param array<string, array{0: int, 1: int}> $rules   Action => [ attempts
	 *                                                      allowed, window in
	 *                                                      seconds ].
	 *
	 * @throws \InvalidArgumentException When a rule is not two positive integers.
	 */
	public function __construct(
		private RateLimiter $limiter,
		array $rules,
	) {
		$checked = array();

		foreach ( $rules as $action => $rule ) {
			$checked[ (string) $action ] = self::checked( (string) $action, $rule );
		}

		$this->rules = $checked;
	}

	/**
	 * Count an attempt and say whether it may proceed.
	 *
	 * @since 1.2.0
	 *
	 * @param string $action An action named in the rules.
	 * @param string $key    What to count against — an address, a key, an
	 *                       email.
	 *
	 * @return Decision
	 */
	#[\NoDiscard( 'A denied decision must be enforced; counting alone limits nothing.' )]
	public function check( string $action, string $key ): Decision {
		$rule = $this->rule( $action, $key );

		return null === $rule ? Decision::unlimited() : $this->limiter->for( $action, $key )->check( ...$rule );
	}

	/**
	 * Say whether an attempt would be allowed, without counting it.
	 *
	 * For limits that count failures: look before acting, and count only
	 * when the attempt turns out to be a miss.
	 *
	 * @since 1.2.0
	 *
	 * @param string $action An action named in the rules.
	 * @param string $key    What to count against.
	 *
	 * @return Decision
	 */
	#[\NoDiscard( 'Peeking only answers a question.' )]
	public function peek( string $action, string $key ): Decision {
		$rule = $this->rule( $action, $key );

		return null === $rule ? Decision::unlimited() : $this->limiter->for( $action, $key )->peek( ...$rule );
	}

	/**
	 * Whether a caller still has allowance, without using any.
	 *
	 * @since 1.2.0
	 *
	 * @param string $action An action named in the rules.
	 * @param string $key    What to count against.
	 *
	 * @return bool
	 */
	#[\NoDiscard( 'Peeking only answers a question.' )]
	public function allows( string $action, string $key ): bool {
		return ! $this->peek( $action, $key )->denied();
	}

	/**
	 * Whether an action has a rule.
	 *
	 * @since 1.2.0
	 *
	 * @param string $action Action name.
	 *
	 * @return bool
	 */
	public function has( string $action ): bool {
		return isset( $this->rules[ $action ] );
	}

	/**
	 * A rule as configured, checked and read by position.
	 *
	 * Read as `[0]` and `[1]`, never reordered: `array( 1 => 60, 0 => 5 )`
	 * is a limit of 5 in 60 seconds, as its keys say, not the 60 in 5 its
	 * order would give. Configuration is checked here rather than trusted
	 * to its documented type, because a mistake in it is a limit that is
	 * silently wrong.
	 *
	 * @since 1.3.0
	 *
	 * @param string $action Action name, for the message.
	 * @param mixed  $rule   The rule as given.
	 *
	 * @return array{0: int, 1: int}
	 *
	 * @throws \InvalidArgumentException When it is not two positive integers.
	 */
	private static function checked( string $action, mixed $rule ): array {
		if ( ! is_array( $rule ) || ! is_int( $rule[0] ?? null ) || ! is_int( $rule[1] ?? null ) || $rule[0] < 1 || $rule[1] < 1 ) {
			throw new \InvalidArgumentException( 'Rate limit rule "' . $action . '" must be [ limit, seconds ], both at least 1.' );
		}

		return array( $rule[0], $rule[1] );
	}

	/**
	 * The rule that applies, or null when nothing does.
	 *
	 * @since 1.2.0
	 *
	 * @param string $action Action name.
	 * @param string $key    What is being counted.
	 *
	 * @return array{0: int, 1: int}|null
	 */
	private function rule( string $action, string $key ): ?array {
		return '' === trim( $key ) || ! isset( $this->rules[ $action ] ) ? null : $this->rules[ $action ];
	}
}
