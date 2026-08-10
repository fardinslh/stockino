<?php

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Support\FixtureEnvironment;

final class FixtureEnvironmentTest extends TestCase {
	public function test_only_explicit_development_environments_are_allowed(): void {
		self::assertTrue( FixtureEnvironment::is_allowed( 'local' ) );
		self::assertTrue( FixtureEnvironment::is_allowed( 'development' ) );
		self::assertFalse( FixtureEnvironment::is_allowed( 'staging' ) );
		self::assertFalse( FixtureEnvironment::is_allowed( 'production' ) );
		self::assertFalse( FixtureEnvironment::is_allowed( 'unknown' ) );
	}
}
