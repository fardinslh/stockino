<?php

namespace Stockino\Support;

use Stockino\Database\SupplierProductRepository;
use Stockino\Database\SupplierRepository;
use Stockino\Purchasing\PurchaseOrderService;
use Stockino\Purchasing\PurchaseReceivingService;

final class PurchaseFixtureCommand {
	public function __construct(
		private readonly PurchaseOrderService $orders,
		private readonly PurchaseReceivingService $receiving,
		private readonly SupplierRepository $suppliers,
		private readonly SupplierProductRepository $relations
	) {}

	public function register(): void {
		\WP_CLI::add_command( 'stockino purchase-fixtures', array( $this, 'generate' ) );
	}

	/** Generate deterministic Phase 3 purchasing examples through production services. */
	public function generate(): void {
		$environment = wp_get_environment_type();
		if ( ! FixtureEnvironment::is_allowed( $environment ) ) {
			\WP_CLI::error( sprintf( 'Fixtures are disabled in the %s environment. Use local or development only.', $environment ) );
		}
		$supplier = $this->suppliers->find_by_code( 'SUP-001' );
		if ( ! $supplier || 'active' !== $supplier['status'] ) {
			\WP_CLI::error( 'Generate the active SUP-001 supplier fixture before purchasing fixtures.' );
		}
		$products = $this->relations->paginate_for_supplier( $supplier['id'], 1, 20 );
		if ( array() === $products['items'] ) {
			\WP_CLI::error( 'SUP-001 needs at least one linked product.' );
		}
		$previous_user = get_current_user_id();
		wp_set_current_user( 1 );
		$created = 0;
		for ( $index = 1; $index <= 25; ++$index ) {
			$reference = sprintf( 'STOCKINO-DEMO-%02d', $index );
			if ( $this->exists( $reference ) ) {
				continue;
			}
			$order = $this->orders->create(
				array(
					'supplier_id'        => $supplier['id'],
					'supplier_reference' => $reference,
					'expected_date'      => gmdate( 'Y-m-d', strtotime( 1 === $index ? '-5 days' : '+' . ( 2 + $index ) . ' days' ) ),
					'notes'              => 'داده نمایشی خرید Stockino در محیط توسعه',
				)
			);
			if ( is_wp_error( $order ) ) {
				\WP_CLI::warning( $order->get_error_message() );
				continue;
			}
			$relation = $products['items'][ ( $index - 1 ) % count( $products['items'] ) ];
			$item     = $this->orders->add_item(
				$order['id'],
				array(
					'product_id'        => $relation['product_id'],
					'ordered_quantity'  => '4',
					'ordered_unit_cost' => '10.000000',
				)
			);
			if ( is_wp_error( $item ) ) {
				\WP_CLI::warning( $item->get_error_message() );
				continue;
			}
			$state = $index % 5;
			if ( 0 !== $state ) {
				$this->orders->mark_ordered( $order['id'] );
			}
			if ( 2 === $state || 3 === $state ) {
				$quantity = 2 === $state ? '2' : '4';
				$result   = $this->receiving->receive(
					$order['id'],
					array(
						'idempotency_key' => 'stockino-fixture-' . $index,
						'note'            => 'رسید نمایشی تولیدشده از مسیر واقعی دریافت',
						'items'           => array(
							array(
								'item_id'          => $item['id'],
								'quantity'         => $quantity,
								'actual_unit_cost' => '10.000000',
							),
						),
					)
				);
				if ( is_wp_error( $result ) ) {
					\WP_CLI::warning( $result->get_error_message() );
				}
			}
			if ( 4 === $state ) {
				$this->orders->cancel( $order['id'] );
			}
			++$created;
		}
		wp_set_current_user( $previous_user );
		\WP_CLI::success( sprintf( 'Created %d deterministic Phase 3 purchase-order fixtures.', $created ) );
	}

	private function exists( string $reference ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE supplier_reference = %s', $wpdb->prefix . 'stockino_purchase_orders', $reference ) ) > 0;
	}
}
