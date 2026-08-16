<?php

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FoundationTest extends TestCase {
	public function test_php_runtime_meets_plugin_minimum(): void {
		self::assertGreaterThanOrEqual( 80200, PHP_VERSION_ID );
	}

	public function test_plugin_version_is_1_0_0(): void {
		self::assertSame( '1.0.0', STOCKINO_VERSION );
		self::assertSame( '5.0.0', STOCKINO_DB_VERSION );
	}
}
