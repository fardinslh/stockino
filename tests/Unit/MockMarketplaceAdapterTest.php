<?php

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Marketplaces\Mock\MockMarketplaceAdapter;

final class MockMarketplaceAdapterTest extends TestCase {
	public function test_mock_adapter_identifies_as_mock_and_returns_categories(): void {
		$adapter = new MockMarketplaceAdapter();
		self::assertTrue( $adapter->is_mock() );
		self::assertSame( 'mock', $adapter->get_kind() );

		$categories = $adapter->get_categories();
		self::assertNotEmpty( $categories );
		self::assertSame( 'mock-100', $categories[0]['id'] );
	}

	public function test_mock_adapter_validates_connection_successfully(): void {
		$adapter = new MockMarketplaceAdapter( 'success' );
		$validation = $adapter->validate_connection();

		self::assertSame( 'active', $validation['status'] );
		self::assertSame( 'mock-vendor-101', $validation['account_id'] );
		self::assertSame( 'غرفه آزمایشی استوکینو', $validation['account_name'] );
	}

	public function test_mock_adapter_creates_and_updates_products(): void {
		$adapter = new MockMarketplaceAdapter();
		$product = array(
			'title'                => 'محصول تست استوکینو',
			'description'          => 'توضیحات تست محصول',
			'category_external_id' => 'mock-100',
			'price'                => 150000,
			'stock'                => 10,
		);

		$res = $adapter->create_product( $product, 'idemp_key_1' );
		self::assertSame( 201, $res['status_code'] );
		self::assertStringStartsWith( 'mock_', $res['external_product_id'] );

		$update_res = $adapter->update_product( $res['external_product_id'], $product );
		self::assertSame( 200, $update_res['status_code'] );
		self::assertSame( $res['external_product_id'], $update_res['external_product_id'] );
	}

	public function test_mock_adapter_updates_stock(): void {
		$adapter = new MockMarketplaceAdapter();
		$stock_res = $adapter->update_stock( 'mock_12345', 25 );

		self::assertSame( 200, $stock_res['status_code'] );
		self::assertSame( 'mock_12345', $stock_res['external_product_id'] );
	}

	public function test_mock_adapter_simulates_failure(): void {
		$adapter = new MockMarketplaceAdapter( 'failure' );
		$this->expectException( \RuntimeException::class );
		$adapter->validate_connection();
	}
}
