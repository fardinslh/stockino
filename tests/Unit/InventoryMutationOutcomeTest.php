<?php

// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax,Universal.Namespaces.DisallowDeclarationWithoutName,Universal.Namespaces.OneDeclarationPerFile,Generic.Files.OneObjectStructurePerFile -- Isolated WooCommerce stubs must be declared before the namespaced function seam.

namespace {
	use PHPUnit\Framework\TestCase;

	if ( ! function_exists( '__' ) ) {
		function __( string $message ): string {
			return $message;
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private readonly string $code, private readonly string $message, private readonly mixed $data ) {}
			public function get_error_code(): string {
				return $this->code; }
			public function get_error_message(): string {
				return $this->message; }
			public function get_error_data(): mixed {
				return $this->data; }
		}
	}

	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public function __construct( private readonly int $id, private float $quantity ) {}
			public function get_id(): int {
				return $this->id; }
			public function get_stock_managed_by_id(): int {
				return $this->id; }
			public function get_stock_quantity(): float {
				return $this->quantity; }
			public function increase_for_test( float $quantity ): void {
				$this->quantity += $quantity; }
		}
	}

	final class InventoryMutationOutcomeTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['stockino_mutation_test_product'] = new WC_Product( 42, 10.0 );
			$GLOBALS['stockino_mutation_test_mode']    = 'throw_after_change';
		}

		public function test_throw_after_effective_write_is_uncertain(): void {
			$service = new Stockino\Inventory\InventoryMutationService( new Stockino\Database\StockMovementRepository() );
			$result  = $service->mutate( $GLOBALS['stockino_mutation_test_product'], 'delta', 2.0, array() );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( Stockino\Inventory\InventoryMutation::OUTCOME_UNCERTAIN, $result->get_error_data()['mutation_outcome'] );
			self::assertSame( 12.0, $result->get_error_data()['observed_quantity_after'] );
			self::assertSame( 'late hook failure', $result->get_error_data()['exception_message'] );
		}

		public function test_definite_no_write_result_is_unchanged(): void {
			$GLOBALS['stockino_mutation_test_mode'] = 'unchanged';
			$service                                = new Stockino\Inventory\InventoryMutationService( new Stockino\Database\StockMovementRepository() );
			$result                                 = $service->mutate( $GLOBALS['stockino_mutation_test_product'], 'delta', 2.0, array() );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( Stockino\Inventory\InventoryMutation::OUTCOME_UNCHANGED, $result->get_error_data()['mutation_outcome'] );
			self::assertSame( 10.0, $GLOBALS['stockino_mutation_test_product']->get_stock_quantity() );
		}
	}
}

namespace Stockino\Inventory {
	function wc_update_product_stock( \WC_Product $product, float $quantity, string $operation ): float|false {
		if ( 'unchanged' === $GLOBALS['stockino_mutation_test_mode'] ) {
			return false;
		}
		$product->increase_for_test( $quantity );
		throw new \RuntimeException( 'late hook failure' );
	}

	function wc_get_product( int $id ): \WC_Product {
		return $GLOBALS['stockino_mutation_test_product'];
	}
}
