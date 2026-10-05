<?php
/**
 * Results that must not be ignored.
 *
 * @package   SugarCommerce\RateLimit
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\RateLimit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\RateLimit\{Limit, RateLimiter, Rules};

/**
 * Class MustCheckTest
 *
 * These answer "did it work?" or "is it allowed?", and code that ignores the
 * answer behaves as though it were yes. #[\NoDiscard] makes PHP warn where a
 * result is dropped; this keeps the attribute from being lost in a refactor.
 */
final class MustCheckTest extends TestCase {

	/**
	 * @return array<string, array{string}>
	 */
	public static function methods(): array {
		return array(
			'Limit::check'       => array( Limit::class . '::check' ),
			'Limit::peek'        => array( Limit::class . '::peek' ),
			'RateLimiter::check' => array( RateLimiter::class . '::check' ),
			'RateLimiter::peek'  => array( RateLimiter::class . '::peek' ),
			'Rules::check'       => array( Rules::class . '::check' ),
			'Rules::peek'        => array( Rules::class . '::peek' ),
			'Rules::allows'      => array( Rules::class . '::allows' ),
		);
	}

	#[DataProvider( 'methods' )]
	public function test_the_result_must_be_used( string $method ): void {
		$attributes = \ReflectionMethod::createFromMethodName( $method )->getAttributes( \NoDiscard::class );

		$this->assertCount( 1, $attributes, $method . ' lost #[\NoDiscard].' );
		$this->assertNotSame( '', $attributes[0]->newInstance()->message, 'Say why it matters.' );
	}
}
