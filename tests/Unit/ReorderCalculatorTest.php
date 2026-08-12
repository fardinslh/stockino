<?php

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Reorder\ReorderCalculator;

final class ReorderCalculatorTest extends TestCase {
	private ReorderCalculator $calculator;

	protected function setUp(): void {
		$this->calculator = new ReorderCalculator();
	}

	public function test_low_stock_without_incoming_uses_default_target_and_supplier_rules(): void {
		$result = $this->calculator->calculate( $this->owner( '3', '0', '10' ), array( $this->candidate( 10, 6 ) ), '2.000000' );
		self::assertSame( 'reorder_needed', $result['state'] );
		self::assertSame( '20.000000', $result['target_stock'] );
		self::assertSame( '17.000000', $result['raw_reorder_quantity'] );
		self::assertSame( '18.000000', $result['recommended_quantity'] );
	}

	public function test_incoming_can_cover_low_current_stock(): void {
		$result = $this->calculator->calculate( $this->owner( '3', '8', '10' ), array( $this->candidate() ), '2.000000' );
		self::assertSame( 'covered_by_incoming', $result['state'] );
		self::assertSame( '11.000000', $result['inventory_position'] );
		self::assertSame( '0.000000', $result['recommended_quantity'] );
	}

	public function test_partial_incoming_reduces_the_shortage(): void {
		$result = $this->calculator->calculate( $this->owner( '3', '2', '10' ), array( $this->candidate() ), '2.000000' );
		self::assertSame( '15.000000', $result['raw_reorder_quantity'] );
	}

	public function test_fractional_moq_and_multiple_round_up_exactly(): void {
		$result = $this->calculator->calculate( $this->owner( '0.25', '0', '1.25' ), array( $this->candidate( '2.5', '0.75' ) ), '2.000000' );
		self::assertSame( '3.000000', $result['recommended_quantity'] );
	}

	public function test_custom_target_overrides_default_formula(): void {
		$owner                        = $this->owner( '3', '0', '10' );
		$owner['custom_target_stock'] = '30.000000';
		$result                       = $this->calculator->calculate( $owner, array( $this->candidate() ), '2.000000' );
		self::assertSame( '27.000000', $result['recommended_quantity'] );
	}

	public function test_attention_blocks_safe_quantity(): void {
		$owner                       = $this->owner( '1', '0', '10' );
		$owner['attention_required'] = true;
		$result                      = $this->calculator->calculate( $owner, array( $this->candidate() ), '2.000000' );
		self::assertSame( 'attention_required', $result['state'] );
		self::assertNull( $result['recommended_quantity'] );
	}

	public function test_unknown_threshold_is_not_treated_as_zero(): void {
		$result = $this->calculator->calculate( $this->owner( '1', '0', null ), array( $this->candidate() ), null );
		self::assertSame( 'threshold_unknown', $result['state'] );
	}

	public function test_competing_source_products_for_shared_owner_require_selection(): void {
		$result = $this->calculator->calculate( $this->owner( '1', '0', '5' ), array( $this->candidate( null, null, 101, 7 ), $this->candidate( null, null, 102, 8 ) ), '2.000000' );
		self::assertSame( 'supplier_selection_required', $result['state'] );
		self::assertNull( $result['supplier'] );
	}

	public function test_explicit_preference_resolves_shared_owner(): void {
		$owner                          = $this->owner( '1', '0', '5' );
		$owner['preferred_supplier_id'] = 8;
		$owner['preferred_product_id']  = 102;
		$result                         = $this->calculator->calculate( $owner, array( $this->candidate( null, null, 101, 7 ), $this->candidate( null, null, 102, 8 ) ), '2.000000' );
		self::assertSame( 'reorder_needed', $result['state'] );
		self::assertSame( 8, $result['supplier']['supplier_id'] );
		self::assertSame( 'preferred', $result['supplier_selection_method'] );
	}

	public function test_lowest_known_lead_time_then_supplier_id_is_deterministic(): void {
		$slow                             = $this->candidate( null, null, 101, 7 );
		$fast                             = $this->candidate( null, null, 101, 8 );
		$slow['effective_lead_time_days'] = 9;
		$fast['effective_lead_time_days'] = 3;
		$result                           = $this->calculator->calculate( $this->owner( '1', '0', '5' ), array( $slow, $fast ), '2.000000' );
		self::assertSame( 8, $result['supplier']['supplier_id'] );
	}

	public function test_unknown_lead_times_use_explicit_lowest_id_fallback(): void {
		$higher                             = $this->candidate( null, null, 101, 8 );
		$lower                              = $this->candidate( null, null, 101, 7 );
		$higher['effective_lead_time_days'] = null;
		$lower['effective_lead_time_days']  = null;
		$result                             = $this->calculator->calculate( $this->owner( '1', '0', '5' ), array( $higher, $lower ), '2.000000' );
		self::assertSame( 7, $result['supplier']['supplier_id'] );
		self::assertSame( 'lowest_id_fallback', $result['supplier_selection_method'] );
	}

	public function test_invalid_preference_falls_back_only_for_one_source_identity(): void {
		$owner                          = $this->owner( '1', '0', '5' );
		$owner['preferred_supplier_id'] = 99;
		$owner['preferred_product_id']  = 999;
		$result                         = $this->calculator->calculate( $owner, array( $this->candidate() ), '2.000000' );
		self::assertTrue( $result['preferred_supplier_invalid'] );
		self::assertSame( 7, $result['supplier']['supplier_id'] );
		self::assertSame( 'preferred_invalid_fallback_lowest_lead_time', $result['supplier_selection_method'] );
	}

	/** @return array<string,mixed> */
	private function owner( string $stock, string $incoming, ?string $threshold ): array {
		return array(
			'stock_owner_id'        => 1,
			'product_name'          => 'Fixture',
			'current_stock'         => $stock,
			'confirmed_incoming'    => $incoming,
			'attention_incoming'    => '0',
			'attention_required'    => false,
			'woo_low_stock_amount'  => $threshold,
			'custom_reorder_point'  => null,
			'custom_target_stock'   => null,
			'preferred_supplier_id' => null,
			'preferred_product_id'  => null,
			'settings_updated_at'   => null,
		);
	}

	/** @return array<string,mixed> */
	private function candidate( ?string $minimum = null, ?string $multiple = null, int $product_id = 101, int $supplier_id = 7 ): array {
		return array(
			'supplier_id'               => $supplier_id,
			'supplier_name'             => 'Supplier',
			'source_product_id'         => $product_id,
			'source_product_name'       => 'Source',
			'minimum_order_quantity'    => $minimum,
			'order_multiple'            => $multiple,
			'effective_lead_time_days'  => 5,
			'default_ordered_unit_cost' => null,
		);
	}
}
