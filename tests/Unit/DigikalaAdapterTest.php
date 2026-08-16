<?php

declare(strict_types=1);

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Marketplaces\Digikala\DigikalaAdapter;

final class DigikalaAdapterTest extends TestCase {
	public function test_digikala_adapter_properties(): void {
		$adapter = new DigikalaAdapter( 'dk_test_key_123', 'seller_99' );

		self::assertSame( 'digikala', $adapter->get_kind() );
		self::assertFalse( $adapter->is_mock() );

		$categories = $adapter->get_categories();
		self::assertNotEmpty( $categories );
	}

	public function test_digikala_adapter_throws_when_api_key_empty(): void {
		$adapter = new DigikalaAdapter( '' );
		$this->expectException( \InvalidArgumentException::class );
		$adapter->validate_connection();
	}
}
