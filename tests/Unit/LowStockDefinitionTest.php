<?php

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stockino\Inventory\LowStockDefinition;

final class LowStockDefinitionTest extends TestCase {
	/** @return array<string,array{?float,float,float,bool}> */
	public static function cases(): array {
		return array(
			'at threshold'      => array( 5.0, 5.0, 0.0, true ),
			'above threshold'   => array( 6.0, 5.0, 0.0, false ),
			'at no-stock floor' => array( 0.0, 5.0, 0.0, false ),
			'negative floor'    => array( -1.0, 5.0, -2.0, true ),
			'unknown quantity'  => array( null, 5.0, 0.0, false ),
		);
	}

	#[DataProvider( 'cases' )]
	public function test_matches_woocommerce_bounds( ?float $quantity, float $low, float $no_stock, bool $expected ): void {
		self::assertSame( $expected, LowStockDefinition::is_low( $quantity, $low, $no_stock ) );
	}
}
