<?php

declare(strict_types=1);

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Support\RetryPolicy;

final class RetryPolicyTest extends TestCase {
	public function test_executes_operation_on_first_try_successfully(): void {
		$called = 0;
		$result = RetryPolicy::execute(
			function () use ( &$called ) {
				++$called;
				return 'success_val';
			},
			3,
			10
		);

		self::assertSame( 1, $called );
		self::assertSame( 'success_val', $result );
	}

	public function test_retries_transient_failure_and_succeeds(): void {
		$attempts = 0;
		$result   = RetryPolicy::execute(
			function () use ( &$attempts ) {
				++$attempts;
				if ( $attempts < 2 ) {
					throw new \RuntimeException( 'Connection timeout', 503 );
				}
				return 'recovered';
			},
			3,
			10
		);

		self::assertSame( 2, $attempts );
		self::assertSame( 'recovered', $result );
	}

	public function test_throws_when_max_attempts_exceeded(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Permanent 500 error' );

		RetryPolicy::execute(
			function () {
				throw new \RuntimeException( 'Permanent 500 error', 500 );
			},
			2,
			10
		);
	}
}
