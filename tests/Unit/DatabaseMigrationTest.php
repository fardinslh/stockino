<?php

declare(strict_types=1);

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Database\Installer;

final class DatabaseMigrationTest extends TestCase {
	public function test_migration_version_constants_are_valid(): void {
		self::assertSame( '5.0.0', STOCKINO_DB_VERSION );
		self::assertSame( '1.0.0', STOCKINO_VERSION );
	}

	public function test_installer_class_exists_and_has_required_methods(): void {
		self::assertTrue( method_exists( Installer::class, 'activate' ) );
		self::assertTrue( method_exists( Installer::class, 'maybe_upgrade' ) );
	}
}
