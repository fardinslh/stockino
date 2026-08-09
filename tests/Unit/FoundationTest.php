<?php

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FoundationTest extends TestCase {
	public function test_php_runtime_meets_plugin_minimum(): void {
		self::assertGreaterThanOrEqual( 80200, PHP_VERSION_ID );
	}
}

