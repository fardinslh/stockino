<?php

// Run with: wp eval-file wp-content/plugins/stockino/tests/Smoke/purchasing.php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This smoke suite must run through WP-CLI.' );
}

$assert        = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
	WP_CLI::log( 'PASS: ' . $message );
};
$request       = static function ( string $method, string $route, array $params = array() ) {
	if ( 'POST' === $method && str_ends_with( $route, '/receipts' ) && isset( $params['items'] ) && is_array( $params['items'] ) ) {
		foreach ( $params['items'] as &$line ) {
			if ( is_array( $line ) && ! array_key_exists( 'actual_unit_cost', $line ) ) {
				$line['actual_unit_cost'] = '10.000000';
			}
		}
		unset( $line );
	}
	$rest = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$rest->set_query_params( $params );
	} else {
		$rest->set_body_params( $params );
	}
	return rest_do_request( $rest );
};
$same_quantity = static fn( float $left, float $right ): bool => 0.000001 > abs( $left - $right );

wp_set_current_user( 0 );
$assert( 401 === $request( 'GET', '/stockino/v1/purchase-orders' )->get_status(), 'Unauthorized users cannot view purchase orders.' );
$assert( 401 === $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => 1 ) )->get_status(), 'Unauthorized users cannot create purchase orders.' );

wp_set_current_user( 1 );
global $wpdb;
$orders_table        = $wpdb->prefix . 'stockino_purchase_orders';
$items_table         = $wpdb->prefix . 'stockino_purchase_order_items';
$receipts_table      = $wpdb->prefix . 'stockino_purchase_receipts';
$receipt_items_table = $wpdb->prefix . 'stockino_purchase_receipt_items';
$movements_table     = $wpdb->prefix . 'stockino_stock_movements';
$costs_table         = $wpdb->prefix . 'stockino_inventory_costs';
$suppliers_table     = $wpdb->prefix . 'stockino_suppliers';
$relations_table     = $wpdb->prefix . 'stockino_supplier_products';

$simple_id    = wc_get_product_id_by_sku( 'STK-049' );
$variation_id = wc_get_product_id_by_sku( 'STK-110-S' );
$inherited_id = wc_get_product_id_by_sku( 'STK-110-P' );
$unlinked_id  = wc_get_product_id_by_sku( 'STK-051' );
$parent_id    = wc_get_product( $inherited_id )->get_parent_id();
$assert( $simple_id > 0 && $variation_id > 0 && $inherited_id > 0 && $unlinked_id > 0, 'Simple, self-managed variation, and parent-managed variation fixtures exist.' );

$supplier_response = $request(
	'POST',
	'/stockino/v1/suppliers',
	array(
		'name' => 'Phase 3 Snapshot Supplier',
		'code' => 'P3-' . wp_generate_password( 8, false, false ),
	)
);
$supplier          = $supplier_response->get_data();
$supplier_id       = (int) $supplier['id'];
$assert( 201 === $supplier_response->get_status(), 'An active supplier can be created for purchasing.' );
$assert( 404 === $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => 999999999 ) )->get_status(), 'An invalid supplier cannot create a purchase order.' );

foreach ( array( $simple_id, $variation_id, $inherited_id ) as $product_id ) {
	$link = $request(
		'POST',
		"/stockino/v1/suppliers/{$supplier_id}/products",
		array(
			'product_id'   => $product_id,
			'supplier_sku' => "P3-{$product_id}",
		)
	);
	$assert( 201 === $link->get_status(), "Supplier relationship created for product {$product_id}." );
}

$draft_response = $request(
	'POST',
	'/stockino/v1/purchase-orders',
	array(
		'supplier_id'        => $supplier_id,
		'supplier_reference' => 'SUP-REF-P3',
		'expected_date'      => gmdate( 'Y-m-d', strtotime( '+7 days' ) ),
		'notes'              => 'Phase 3 automated purchasing smoke',
	)
);
$draft          = $draft_response->get_data();
$order_id       = (int) $draft['id'];
$po_number      = $draft['po_number'];
$assert( 201 === $draft_response->get_status() && 'draft' === $draft['status'] && preg_match( '/^PO-\d{6}$/', $po_number ), 'A draft PO receives a stable server-generated number.' );
$assert( 409 === $request( 'POST', "/stockino/v1/purchase-orders/{$order_id}/mark-ordered" )->get_status(), 'An empty draft cannot be marked ordered.' );
$assert(
	409 === $request(
		'POST',
		"/stockino/v1/purchase-orders/{$order_id}/items",
		array(
			'product_id'       => $unlinked_id,
			'ordered_quantity' => '1',
		)
	)->get_status(),
	'A product not linked to the PO supplier is rejected.'
);
$assert(
	400 === $request(
		'POST',
		"/stockino/v1/purchase-orders/{$order_id}/items",
		array(
			'product_id'       => $simple_id,
			'ordered_quantity' => '0.0000001',
		)
	)->get_status(),
	'A purchase quantity that normalizes to zero is rejected.'
);

$simple_line = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$order_id}/items",
	array(
		'product_id'       => $simple_id,
		'ordered_quantity' => '99999999999999.999999',
	)
)->get_data();
$assert( '99999999999999.999999' === $simple_line['ordered_quantity'], 'The maximum DECIMAL(20,6) ordered quantity is accepted exactly.' );
$assert( 400 === $request( 'PUT', "/stockino/v1/purchase-orders/{$order_id}/items/{$simple_line['id']}", array( 'ordered_quantity' => '100000000000000' ) )->get_status(), 'An ordered quantity beyond DECIMAL(20,6) is rejected.' );
$simple_line = $request( 'PUT', "/stockino/v1/purchase-orders/{$order_id}/items/{$simple_line['id']}", array( 'ordered_quantity' => '3.000000' ) )->get_data();
$self_line   = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$order_id}/items",
	array(
		'product_id'       => $variation_id,
		'ordered_quantity' => '1',
	)
)->get_data();
$parent_line = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$order_id}/items",
	array(
		'product_id'       => $inherited_id,
		'ordered_quantity' => '2',
	)
)->get_data();
$assert( $simple_id === $simple_line['product_id'] && $variation_id === $self_line['product_id'] && $inherited_id === $parent_line['product_id'], 'PO lines retain exact simple and variation identities.' );
$assert( 200 === $request( 'DELETE', "/stockino/v1/purchase-orders/{$order_id}/items/{$self_line['id']}" )->get_status(), 'A draft PO line can be removed.' );
$self_line = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$order_id}/items",
	array(
		'product_id'       => $variation_id,
		'ordered_quantity' => '1',
	)
)->get_data();
$assert(
	409 === $request(
		'POST',
		"/stockino/v1/purchase-orders/{$order_id}/items",
		array(
			'product_id'       => $simple_id,
			'ordered_quantity' => '1',
		)
	)->get_status(),
	'Duplicate product lines are rejected.'
);
$self_line = $request( 'PUT', "/stockino/v1/purchase-orders/{$order_id}/items/{$self_line['id']}", array( 'ordered_quantity' => '2' ) )->get_data();
$assert( '2.000000' === $self_line['ordered_quantity'], 'Draft ordered quantity can be updated exactly.' );

$snapshot_name = $draft['supplier_name'];
$request( 'PUT', "/stockino/v1/suppliers/{$supplier_id}", array( 'name' => 'Renamed Phase 3 Supplier' ) );
$assert( $snapshot_name === $request( 'GET', "/stockino/v1/purchase-orders/{$order_id}" )->get_data()['supplier_name'], 'Supplier rename does not rewrite the PO snapshot.' );
$request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/archive" );
$assert( 409 === $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_status(), 'An archived supplier cannot create a new PO.' );
$assert( 409 === $request( 'POST', "/stockino/v1/purchase-orders/{$order_id}/mark-ordered" )->get_status(), 'A draft cannot be ordered while its supplier is archived.' );
$request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/reactivate" );

$simple_before    = (float) wc_get_product( $simple_id )->get_stock_quantity();
$variation_before = (float) wc_get_product( $variation_id )->get_stock_quantity();
$parent_before    = (float) wc_get_product( $parent_id )->get_stock_quantity();
$movement_before  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE movement_type = 'purchase_receipt' AND metadata LIKE %s", $movements_table, '%"purchase_order_id":' . $order_id . '%' ) );
$ordered          = $request( 'POST', "/stockino/v1/purchase-orders/{$order_id}/mark-ordered" )->get_data();
$assert( 'ordered' === $ordered['status'] && $po_number === $ordered['po_number'], 'Mark ordered performs the explicit draft-to-ordered transition.' );
$assert( $same_quantity( $simple_before, (float) wc_get_product( $simple_id )->get_stock_quantity() ) && 0 === $movement_before - (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE movement_type = 'purchase_receipt' AND metadata LIKE %s", $movements_table, '%"purchase_order_id":' . $order_id . '%' ) ), 'Creating and ordering a PO does not change stock or create movements.' );
$assert( 409 === $request( 'PUT', "/stockino/v1/purchase-orders/{$order_id}/items/{$simple_line['id']}", array( 'ordered_quantity' => '1' ) )->get_status(), 'Ordered line structure is locked.' );
$request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/archive" );
$zero_receipt = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$order_id}/receipts",
	array(
		'idempotency_key' => wp_generate_uuid4(),
		'items'           => array(
			array(
				'item_id'  => $simple_line['id'],
				'quantity' => '0',
			),
		),
	)
);
$assert( 400 === $zero_receipt->get_status() && $same_quantity( $simple_before, (float) wc_get_product( $simple_id )->get_stock_quantity() ), 'A receipt must contain at least one positive quantity.' );
$fractional_stock = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$order_id}/receipts",
	array(
		'idempotency_key' => wp_generate_uuid4(),
		'items'           => array(
			array(
				'item_id'  => $simple_line['id'],
				'quantity' => '0.100000',
			),
		),
	)
);
$assert( 409 === $fractional_stock->get_status() && $same_quantity( $simple_before, (float) wc_get_product( $simple_id )->get_stock_quantity() ), 'A quantity WooCommerce would round is rejected before stock mutation.' );

$token_one    = wp_generate_uuid4();
$receipt_one  = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$order_id}/receipts",
	array(
		'idempotency_key' => $token_one,
		'note'            => 'First partial receipt',
		'items'           => array(
			array(
				'item_id'  => $simple_line['id'],
				'quantity' => '1',
			),
			array(
				'item_id'  => $self_line['id'],
				'quantity' => '1',
			),
			array(
				'item_id'  => $parent_line['id'],
				'quantity' => '1',
			),
		),
	)
);
$receipt_data = $receipt_one->get_data();
$assert( 201 === $receipt_one->get_status() && 'completed' === $receipt_data['status'] && preg_match( '/^RCV-\d{6}$/', $receipt_data['receipt_number'] ), 'A multi-line partial receipt completes with a stable receipt number.' );
$assert( 'inactive' === $request( 'GET', "/stockino/v1/suppliers/{$supplier_id}" )->get_data()['status'], 'An already ordered PO can continue receiving after its supplier is archived.' );
$after_partial = $request( 'GET', "/stockino/v1/purchase-orders/{$order_id}" )->get_data();
$assert( 'partially_received' === $after_partial['status'] && '3.000000' === $after_partial['received_units'] && '4.000000' === $after_partial['remaining_units'], 'Partial receiving derives exact received and remaining quantities.' );
$assert( $same_quantity( $simple_before + 1, (float) wc_get_product( $simple_id )->get_stock_quantity() ), 'Simple product stock increases by the exact receipt delta.' );
$assert( $same_quantity( $variation_before + 1, (float) wc_get_product( $variation_id )->get_stock_quantity() ), 'A self-managed variation updates its own stock.' );
$assert( $same_quantity( $parent_before + 1, (float) wc_get_product( $parent_id )->get_stock_quantity() ), 'A parent-managed variation updates the WooCommerce stock owner.' );
$movement_after_partial = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE movement_type = 'purchase_receipt' AND metadata LIKE %s", $movements_table, '%"purchase_order_id":' . $order_id . '%' ) );
$assert( $movement_before + 3 === $movement_after_partial, 'Each successful receipt line creates exactly one purchase_receipt movement and no duplicate external movement.' );
$owner_receipt_item = $wpdb->get_row( $wpdb->prepare( 'SELECT id, product_id, stock_owner_id, movement_id FROM %i WHERE receipt_id = %d AND purchase_order_item_id = %d', $receipt_items_table, $receipt_data['id'], $parent_line['id'] ), ARRAY_A );
$assert( $inherited_id === (int) $owner_receipt_item['product_id'] && $parent_id === (int) $owner_receipt_item['stock_owner_id'] && (int) $owner_receipt_item['movement_id'] > 0, 'Receipt history preserves source variation and actual stock owner IDs.' );
$owner_movement = $wpdb->get_row( $wpdb->prepare( 'SELECT quantity_before, quantity_delta, quantity_after, reference_type, reference_id, metadata FROM %i WHERE id = %d', $movements_table, $owner_receipt_item['movement_id'] ), ARRAY_A );
$owner_metadata = json_decode( $owner_movement['metadata'], true );
$assert( 1.0 === (float) $owner_movement['quantity_delta'] && 1.0 === (float) $owner_movement['quantity_after'] - (float) $owner_movement['quantity_before'], 'Purchase-receipt movement arithmetic records exact before, delta, and after values.' );
$assert( 'purchase_receipt' === $owner_movement['reference_type'] && (string) $owner_receipt_item['id'] === $owner_movement['reference_id'] && $order_id === (int) $owner_metadata['purchase_order_id'], 'Purchase-receipt movement references and metadata link back to the receipt line and purchase order.' );

$retry_stock    = (float) wc_get_product( $simple_id )->get_stock_quantity();
$retry_movement = $movement_after_partial;
$retry          = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$order_id}/receipts",
	array(
		'idempotency_key' => $token_one,
		'items'           => array(
			array(
				'item_id'  => $simple_line['id'],
				'quantity' => '1',
			),
		),
	)
);
$assert( 200 === $retry->get_status() && ! empty( $retry->get_data()['idempotent_replay'] ), 'A completed idempotency token returns the existing receipt.' );
$assert( $same_quantity( $retry_stock, (float) wc_get_product( $simple_id )->get_stock_quantity() ) && 0 === $retry_movement - (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE movement_type = 'purchase_receipt' AND metadata LIKE %s", $movements_table, '%"purchase_order_id":' . $order_id . '%' ) ), 'Idempotent retry does not change stock or create another movement.' );

$over_receive = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$order_id}/receipts",
	array(
		'idempotency_key' => wp_generate_uuid4(),
		'items'           => array(
			array(
				'item_id'  => $simple_line['id'],
				'quantity' => '3',
			),
		),
	)
);
$assert( 409 === $over_receive->get_status(), 'Over-receiving is rejected under the receive lock before stock mutation.' );
$receipt_two = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$order_id}/receipts",
	array(
		'idempotency_key' => wp_generate_uuid4(),
		'items'           => array(
			array(
				'item_id'  => $simple_line['id'],
				'quantity' => '2',
			),
			array(
				'item_id'  => $self_line['id'],
				'quantity' => '1',
			),
			array(
				'item_id'  => $parent_line['id'],
				'quantity' => '1',
			),
		),
	)
);
$completed   = $request( 'GET', "/stockino/v1/purchase-orders/{$order_id}" )->get_data();
$assert( 201 === $receipt_two->get_status() && 'received' === $completed['status'] && '0.000000' === $completed['remaining_units'], 'A second partial receipt completes the PO with zero exact remaining quantity.' );
$request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/reactivate" );
$assert(
	409 === $request(
		'POST',
		"/stockino/v1/purchase-orders/{$order_id}/receipts",
		array(
			'idempotency_key' => wp_generate_uuid4(),
			'items'           => array(
				array(
					'item_id'  => $simple_line['id'],
					'quantity' => '0.000001',
				),
			),
		)
	)->get_status(),
	'A received PO cannot receive again.'
);
$assert( 409 === $request( 'POST', "/stockino/v1/purchase-orders/{$order_id}/cancel" )->get_status(), 'When receiving completes first, cancellation re-reads the received state and rejects the transition.' );

$list = $request(
	'GET',
	'/stockino/v1/purchase-orders',
	array(
		'search'   => $po_number,
		'status'   => 'received',
		'per_page' => 20,
	)
)->get_data();
$assert( 1 === $list['pagination']['total_items'] && $po_number === $list['items'][0]['po_number'], 'PO list search, status filter, and server pagination agree.' );
$snapshot_search = $request(
	'GET',
	'/stockino/v1/purchase-orders',
	array(
		'search'   => $snapshot_name,
		'per_page' => 100,
	)
)->get_data();
$snapshot_ids    = array_map( 'intval', wp_list_pluck( $snapshot_search['items'], 'id' ) );
$assert( in_array( $order_id, $snapshot_ids, true ), 'PO search uses the immutable supplier snapshot after the supplier is renamed.' );
$history = $request( 'GET', "/stockino/v1/purchase-orders/{$order_id}/receipts" )->get_data();
$assert( 2 === $history['pagination']['total_items'], 'Receipt history is server-paginated.' );

$cancel_order = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_data();
$cancel_line  = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$cancel_order['id']}/items",
	array(
		'product_id'       => $simple_id,
		'ordered_quantity' => '2',
	)
)->get_data();
$request( 'POST', "/stockino/v1/purchase-orders/{$cancel_order['id']}/mark-ordered" );
$request(
	'POST',
	"/stockino/v1/purchase-orders/{$cancel_order['id']}/receipts",
	array(
		'idempotency_key' => wp_generate_uuid4(),
		'items'           => array(
			array(
				'item_id'  => $cancel_line['id'],
				'quantity' => '1',
			),
		),
	)
);
$cancel_stock = (float) wc_get_product( $simple_id )->get_stock_quantity();
$cancelled    = $request( 'POST', "/stockino/v1/purchase-orders/{$cancel_order['id']}/cancel" )->get_data();
$assert( 'cancelled' === $cancelled['status'] && $same_quantity( $cancel_stock, (float) wc_get_product( $simple_id )->get_stock_quantity() ), 'Cancelling a partially received PO preserves received stock.' );
$assert(
	409 === $request(
		'POST',
		"/stockino/v1/purchase-orders/{$cancel_order['id']}/receipts",
		array(
			'idempotency_key' => wp_generate_uuid4(),
			'items'           => array(
				array(
					'item_id'  => $cancel_line['id'],
					'quantity' => '1',
				),
			),
		)
	)->get_status(),
	'A cancelled PO cannot receive remaining goods.'
);

$draft_cancel = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_data();
$assert( 'cancelled' === $request( 'POST', "/stockino/v1/purchase-orders/{$draft_cancel['id']}/cancel" )->get_data()['status'], 'A draft PO can be cancelled without inventory effects.' );
$ordered_cancel = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_data();
$ordered_line   = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$ordered_cancel['id']}/items",
	array(
		'product_id'       => $simple_id,
		'ordered_quantity' => '1',
	)
)->get_data();
$request( 'POST', "/stockino/v1/purchase-orders/{$ordered_cancel['id']}/mark-ordered" );
$assert( 'cancelled' === $request( 'POST', "/stockino/v1/purchase-orders/{$ordered_cancel['id']}/cancel" )->get_data()['status'] && $simple_id === $ordered_line['product_id'], 'An unreceived ordered PO can be cancelled without changing stock.' );
$assert(
	409 === $request(
		'POST',
		"/stockino/v1/purchase-orders/{$ordered_cancel['id']}/receipts",
		array(
			'idempotency_key' => wp_generate_uuid4(),
			'items'           => array(
				array(
					'item_id'  => $ordered_line['id'],
					'quantity' => '1',
				),
			),
		)
	)->get_status(),
	'When cancellation completes first, receiving re-reads the cancelled state and changes no inventory.'
);

$race_add        = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_data();
$mark_wins_lock  = new class( new Stockino\Database\PurchaseOrderRepository(), $race_add['id'] ) implements Stockino\Purchasing\ReceiveLock {
	private bool $ran = false;
	public function __construct( private readonly Stockino\Database\PurchaseOrderRepository $orders, private readonly int $order_id ) {}
	public function acquire( int $purchase_order_id ): bool {
		if ( ! $this->ran ) {
			$this->orders->update( $this->order_id, array( 'status' => 'ordered' ) );
			$this->ran = true;
		}
		return true;
	}
	public function release( int $purchase_order_id ): void {}
};
$race_service    = new Stockino\Purchasing\PurchaseOrderService( new Stockino\Database\PurchaseOrderRepository(), new Stockino\Database\SupplierRepository(), new Stockino\Database\SupplierProductRepository(), $mark_wins_lock );
$race_add_result = $race_service->add_item(
	$race_add['id'],
	array(
		'product_id'       => $simple_id,
		'ordered_quantity' => '1',
	)
);
$assert( is_wp_error( $race_add_result ) && 'stockino_purchase_order_locked' === $race_add_result->get_error_code(), 'A mark-ordered transition that wins the PO lock prevents a stale add-item write.' );

$race_line_order   = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_data();
$race_line         = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$race_line_order['id']}/items",
	array(
		'product_id'       => $simple_id,
		'ordered_quantity' => '2',
	)
)->get_data();
$line_mark_lock    = new class( new Stockino\Database\PurchaseOrderRepository(), $race_line_order['id'] ) implements Stockino\Purchasing\ReceiveLock {
	private bool $ran = false;
	public function __construct( private readonly Stockino\Database\PurchaseOrderRepository $orders, private readonly int $order_id ) {}
	public function acquire( int $purchase_order_id ): bool {
		if ( ! $this->ran ) {
			$this->orders->update( $this->order_id, array( 'status' => 'ordered' ) );
			$this->ran = true;
		}
		return true;
	}
	public function release( int $purchase_order_id ): void {}
};
$line_race_service = new Stockino\Purchasing\PurchaseOrderService( new Stockino\Database\PurchaseOrderRepository(), new Stockino\Database\SupplierRepository(), new Stockino\Database\SupplierProductRepository(), $line_mark_lock );
$stale_update      = $line_race_service->update_item( $race_line_order['id'], $race_line['id'], array( 'ordered_quantity' => '3' ) );
$stale_delete      = $line_race_service->delete_item( $race_line_order['id'], $race_line['id'] );
$assert( is_wp_error( $stale_update ) && is_wp_error( $stale_delete ) && '2.000000' === ( new Stockino\Database\PurchaseOrderRepository() )->find_item( $race_line_order['id'], $race_line['id'] )['ordered_quantity'], 'A mark-ordered transition prevents stale update and delete writes after the lock is acquired.' );

$race_blocked = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_data();
$request(
	'POST',
	"/stockino/v1/purchase-orders/{$race_blocked['id']}/items",
	array(
		'product_id'       => $simple_id,
		'ordered_quantity' => '1',
	)
);
$operation_blocked_lock = new class() implements Stockino\Purchasing\ReceiveLock {
	public function acquire( int $purchase_order_id ): bool {
		return false; }
	public function release( int $purchase_order_id ): void {}
};
$blocked_order_service  = new Stockino\Purchasing\PurchaseOrderService( new Stockino\Database\PurchaseOrderRepository(), new Stockino\Database\SupplierRepository(), new Stockino\Database\SupplierProductRepository(), $operation_blocked_lock );
$blocked_mark           = $blocked_order_service->mark_ordered( $race_blocked['id'] );
$blocked_edit           = $blocked_order_service->update( $race_blocked['id'], array( 'supplier_reference' => 'stale-write' ) );
$blocked_cancel         = $blocked_order_service->cancel( $race_blocked['id'] );
$blocked_state          = $request( 'GET', "/stockino/v1/purchase-orders/{$race_blocked['id']}" )->get_data();
$assert( is_wp_error( $blocked_mark ) && is_wp_error( $blocked_edit ) && is_wp_error( $blocked_cancel ) && 'draft' === $blocked_state['status'] && null === $blocked_state['supplier_reference'], 'Structural mutation, mark-ordered, and cancel all respect PO lock contention.' );

$unmanaged = new WC_Product_Simple();
$unmanaged->set_name( 'Phase 3 unmanaged product' );
$unmanaged->set_status( 'publish' );
$unmanaged->set_manage_stock( false );
$unmanaged_id = $unmanaged->save();
$request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/products", array( 'product_id' => $unmanaged_id ) );
$unmanaged_order = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_data();
$unmanaged_line  = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$unmanaged_order['id']}/items",
	array(
		'product_id'       => $unmanaged_id,
		'ordered_quantity' => '1',
	)
)->get_data();
$request( 'POST', "/stockino/v1/purchase-orders/{$unmanaged_order['id']}/mark-ordered" );
$unmanaged_receive = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$unmanaged_order['id']}/receipts",
	array(
		'idempotency_key' => wp_generate_uuid4(),
		'items'           => array(
			array(
				'item_id'  => $unmanaged_line['id'],
				'quantity' => '1',
			),
		),
	)
);
$assert( 409 === $unmanaged_receive->get_status() && ! wc_get_product( $unmanaged_id )->managing_stock(), 'Receiving rejects a product without a WooCommerce stock owner before mutation.' );

$temporary = new WC_Product_Simple();
$temporary->set_name( 'Phase 3 deleted product snapshot' );
$temporary->set_status( 'publish' );
$temporary->set_manage_stock( true );
$temporary->set_stock_quantity( 5 );
$temporary_id = $temporary->save();
$request( 'POST', "/stockino/v1/suppliers/{$supplier_id}/products", array( 'product_id' => $temporary_id ) );
$deleted_order = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_data();
$valid_line    = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$deleted_order['id']}/items",
	array(
		'product_id'       => $simple_id,
		'ordered_quantity' => '1',
	)
)->get_data();
$deleted_line  = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$deleted_order['id']}/items",
	array(
		'product_id'       => $temporary_id,
		'ordered_quantity' => '1',
	)
)->get_data();
$request( 'POST', "/stockino/v1/purchase-orders/{$deleted_order['id']}/mark-ordered" );
wp_delete_post( $temporary_id, true );
$prevalidate_stock = (float) wc_get_product( $simple_id )->get_stock_quantity();
$deleted_receive   = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$deleted_order['id']}/receipts",
	array(
		'idempotency_key' => wp_generate_uuid4(),
		'items'           => array(
			array(
				'item_id'  => $valid_line['id'],
				'quantity' => '1',
			),
			array(
				'item_id'  => $deleted_line['id'],
				'quantity' => '1',
			),
		),
	)
);
$assert( 409 === $deleted_receive->get_status() && $same_quantity( $prevalidate_stock, (float) wc_get_product( $simple_id )->get_stock_quantity() ), 'A deleted PO product rejects the whole receipt before another valid line mutates stock.' );
$deleted_detail = $request( 'GET', "/stockino/v1/purchase-orders/{$deleted_order['id']}" )->get_data();
$assert( 2 === count( $deleted_detail['items'] ) && 'Phase 3 deleted product snapshot' === $deleted_detail['items'][1]['product_name'], 'Product deletion preserves historical PO line snapshots.' );
$assert(
	409 === $request(
		'POST',
		"/stockino/v1/purchase-orders/{$deleted_order['id']}/receipts",
		array(
			'idempotency_key' => $token_one,
			'items'           => array(),
		)
	)->get_status(),
	'A completed idempotency token cannot be reused for a different purchase order.'
);

$receipt_repository = new Stockino\Database\PurchaseReceiptRepository();
$cost_lock          = new Stockino\Costing\MysqlCostLock();
$cost_service       = new Stockino\Costing\InventoryCostService( new Stockino\Database\InventoryCostRepository(), $cost_lock );
$processing_key     = 'processing-' . wp_generate_uuid4();
$processing_id      = $receipt_repository->create(
	array(
		'purchase_order_id' => $deleted_order['id'],
		'idempotency_key'   => $processing_key,
	)
);
$assert(
	409 === $request(
		'POST',
		"/stockino/v1/purchase-orders/{$deleted_order['id']}/receipts",
		array(
			'idempotency_key' => $processing_key,
			'items'           => array(),
		)
	)->get_status(),
	'A processing idempotency token cannot execute again.'
);
$receipt_repository->update( $processing_id, array( 'status' => 'requires_attention' ) );
$assert(
	409 === $request(
		'POST',
		"/stockino/v1/purchase-orders/{$deleted_order['id']}/receipts",
		array(
			'idempotency_key' => $processing_key,
			'items'           => array(),
		)
	)->get_status(),
	'A requires-attention idempotency token cannot replay inventory mutation.'
);

$lock_one = new Stockino\Purchasing\MysqlReceiveLock( 0 );
$assert( $lock_one->acquire( $order_id ) && $lock_one->acquire( $deleted_order['id'] ), 'Advisory locks are scoped per purchase order rather than globally.' );
$lock_one->release( $deleted_order['id'] );
$lock_one->release( $order_id );

$failure_order = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_data();
$failure_line  = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$failure_order['id']}/items",
	array(
		'product_id'       => $simple_id,
		'ordered_quantity' => '1',
	)
)->get_data();
$request( 'POST', "/stockino/v1/purchase-orders/{$failure_order['id']}/mark-ordered" );
$failed_mutation   = new class() implements Stockino\Inventory\InventoryMutation {
	public function mutate( WC_Product $stock_target, string $mode, float $quantity, array $movement ) {
		return new WP_Error(
			'stockino_injected_ledger_failure',
			'Injected failure after stock mutation.',
			array(
				'status'           => 500,
				'mutation_outcome' => Stockino\Inventory\InventoryMutation::OUTCOME_CHANGED,
				'quantity_before'  => 10,
				'quantity_delta'   => $quantity,
				'quantity_after'   => 10 + $quantity,
			)
		);
	}
};
$failure_receiving = new Stockino\Purchasing\PurchaseReceivingService( new Stockino\Database\PurchaseOrderRepository(), $receipt_repository, $failed_mutation, new Stockino\Purchasing\MysqlReceiveLock(), $cost_service, $cost_lock );
$attention_key     = 'attention-' . wp_generate_uuid4();
$attention         = $failure_receiving->receive(
	$failure_order['id'],
	array(
		'idempotency_key' => $attention_key,
		'items'           => array(
			array(
				'item_id'          => $failure_line['id'],
				'quantity'         => '1',
				'actual_unit_cost' => '10.000000',
			),
		),
	)
);
$attention_receipt = $receipt_repository->find_by_key( $attention_key );
$failure_detail    = $request( 'GET', "/stockino/v1/purchase-orders/{$failure_order['id']}" )->get_data();
$assert( is_wp_error( $attention ) && 'requires_attention' === $attention_receipt['status'] && 'requires_attention' === $attention_receipt['items'][0]['status'] && 'requires_attention' === $attention_receipt['items'][0]['costing_status'], 'A post-stock persistence failure creates an explicit requires-attention receipt, line, and costing state.' );
$assert( '1.000000' === $failure_detail['received_units'], 'A known stock-changed failure accounts for the quantity to prevent blind duplicate receiving.' );
$assert(
	is_wp_error(
		$failure_receiving->receive(
			$failure_order['id'],
			array(
				'idempotency_key' => $attention_key,
				'items'           => array(
					array(
						'item_id'  => $failure_line['id'],
						'quantity' => '1',
					),
				),
			)
		)
	),
	'A requires-attention receipt cannot replay through the same token.'
);
$failure_history = $receipt_repository->paginate_for_order( $failure_order['id'], 1, 20 );
$assert( '0.000000' === $failure_history['items'][0]['confirmed_units'] && '1.000000' === $failure_history['items'][0]['attention_units'] && '0.000000' === $failure_history['items'][0]['failed_units'], 'Receipt history separates confirmed quantities from applied quantities requiring attention.' );

$uncertain_order = $request( 'POST', '/stockino/v1/purchase-orders', array( 'supplier_id' => $supplier_id ) )->get_data();
$uncertain_line  = $request(
	'POST',
	"/stockino/v1/purchase-orders/{$uncertain_order['id']}/items",
	array(
		'product_id'       => $simple_id,
		'ordered_quantity' => '1',
	)
)->get_data();
$request( 'POST', "/stockino/v1/purchase-orders/{$uncertain_order['id']}/mark-ordered" );
$uncertain_before      = (float) wc_get_product( $simple_id )->get_stock_quantity();
$uncertain_cost_before = $wpdb->get_var( $wpdb->prepare( 'SELECT average_unit_cost FROM %i WHERE stock_owner_id = %d', $costs_table, $simple_id ) );
$uncertain_mutation    = new class() implements Stockino\Inventory\InventoryMutation {
	public function mutate( WC_Product $stock_target, string $mode, float $quantity, array $movement ) {
		$before = (float) $stock_target->get_stock_quantity();
		wc_update_product_stock( $stock_target, $quantity, 'increase' );
		return new WP_Error(
			'stockino_injected_late_hook_failure',
			'Injected exception after the stock write reached persistence.',
			array(
				'status'                  => 500,
				'mutation_outcome'        => Stockino\Inventory\InventoryMutation::OUTCOME_UNCERTAIN,
				'quantity_before'         => $before,
				'observed_quantity_after' => $before + $quantity,
				'exception_type'          => RuntimeException::class,
				'exception_message'       => 'Injected late hook failure.',
			)
		);
	}
};
$uncertain_receiving   = new Stockino\Purchasing\PurchaseReceivingService( new Stockino\Database\PurchaseOrderRepository(), $receipt_repository, $uncertain_mutation, new Stockino\Purchasing\MysqlReceiveLock(), $cost_service, $cost_lock );
$uncertain_key         = 'uncertain-' . wp_generate_uuid4();
$uncertain_result      = $uncertain_receiving->receive(
	$uncertain_order['id'],
	array(
		'idempotency_key' => $uncertain_key,
		'items'           => array(
			array(
				'item_id'          => $uncertain_line['id'],
				'quantity'         => '1',
				'actual_unit_cost' => '10.000000',
			),
		),
	)
);
$uncertain_receipt     = $receipt_repository->find_by_key( $uncertain_key );
$uncertain_detail      = $request( 'GET', "/stockino/v1/purchase-orders/{$uncertain_order['id']}" )->get_data();
$assert( is_wp_error( $uncertain_result ) && Stockino\Inventory\InventoryMutation::OUTCOME_UNCERTAIN === $uncertain_result->get_error_data()['mutation_outcome'] && 'requires_attention' === $uncertain_receipt['items'][0]['status'], 'An unprovable post-write exception records an uncertain requires-attention receipt line.' );
$assert( $uncertain_cost_before === $wpdb->get_var( $wpdb->prepare( 'SELECT average_unit_cost FROM %i WHERE stock_owner_id = %d', $costs_table, $simple_id ) ) && 'requires_attention' === $uncertain_receipt['items'][0]['costing_status'], 'An uncertain stock mutation does not advance weighted-average cost and visibly requires cost reconciliation.' );
$assert( $same_quantity( $uncertain_before + 1, (float) wc_get_product( $simple_id )->get_stock_quantity() ) && '0.000000' === $uncertain_detail['received_units'], 'An uncertain mutation is not mislabeled as a confirmed PO receipt and is never auto-reversed.' );
$assert( '0.000000' === $uncertain_receipt['confirmed_units'] && '1.000000' === $uncertain_receipt['attention_units'] && '0.000000' === $uncertain_receipt['failed_units'], 'Receipt totals expose uncertain attention quantity separately from confirmed and failed quantity.' );
$uncertain_retry_stock = (float) wc_get_product( $simple_id )->get_stock_quantity();
$same_token_retry      = $uncertain_receiving->receive(
	$uncertain_order['id'],
	array(
		'idempotency_key' => $uncertain_key,
		'items'           => array(),
	)
);
$new_token_retry       = $uncertain_receiving->receive(
	$uncertain_order['id'],
	array(
		'idempotency_key' => 'uncertain-new-' . wp_generate_uuid4(),
		'items'           => array(
			array(
				'item_id'          => $uncertain_line['id'],
				'quantity'         => '1',
				'actual_unit_cost' => '10.000000',
			),
		),
	)
);
$assert( is_wp_error( $same_token_retry ) && is_wp_error( $new_token_retry ) && $same_quantity( $uncertain_retry_stock, (float) wc_get_product( $simple_id )->get_stock_quantity() ), 'Neither the same token nor a fresh token can blindly apply an uncertain quantity again.' );

$failed_totals_key = 'failed-totals-' . wp_generate_uuid4();
$failed_totals_id  = $receipt_repository->create(
	array(
		'purchase_order_id' => $deleted_order['id'],
		'idempotency_key'   => $failed_totals_key,
	)
);
$failed_item_id    = $receipt_repository->create_item(
	array(
		'receipt_id'             => $failed_totals_id,
		'purchase_order_item_id' => $valid_line['id'],
		'product_id'             => $simple_id,
		'stock_owner_id'         => $simple_id,
		'quantity_received'      => '1',
	)
);
$receipt_repository->update_item(
	$failed_item_id,
	array(
		'status'     => 'failed',
		'error_code' => 'stockino_receipt_halted',
	)
);
$receipt_repository->update( $failed_totals_id, array( 'status' => 'requires_attention' ) );
$failed_totals = $receipt_repository->find( $failed_totals_id );
$assert( '0.000000' === $failed_totals['received_units'] && '0.000000' === $failed_totals['confirmed_units'] && '0.000000' === $failed_totals['attention_units'] && '1.000000' === $failed_totals['failed_units'], 'Failed or halted receipt lines are never presented as successfully received.' );

$blocked_lock      = new class() implements Stockino\Purchasing\ReceiveLock {
	public function acquire( int $purchase_order_id ): bool {
		return false; }
	public function release( int $purchase_order_id ): void {}
};
$blocked_receiving = new Stockino\Purchasing\PurchaseReceivingService( new Stockino\Database\PurchaseOrderRepository(), $receipt_repository, $failed_mutation, $blocked_lock, $cost_service, $cost_lock );
$blocked_key       = 'blocked-' . wp_generate_uuid4();
$blocked           = $blocked_receiving->receive(
	$deleted_order['id'],
	array(
		'idempotency_key' => $blocked_key,
		'items'           => array(),
	)
);
$assert( is_wp_error( $blocked ) && 'stockino_purchase_order_busy' === $blocked->get_error_code() && null === $receipt_repository->find_by_key( $blocked_key ), 'Lock contention performs no receiving and leaves the request safely retryable.' );

$order_count_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $orders_table ) );
$failing_orders     = new Stockino\Database\PurchaseOrderRepository( static fn(): bool => false );
try {
	$failing_orders->create(
		array(
			'supplier_id'            => $supplier_id,
			'supplier_name_snapshot' => 'Number failure supplier',
		)
	);
	$po_number_failed = false;
} catch ( RuntimeException $exception ) {
	$po_number_failed = 'purchase_order_number_failed' === $exception->getMessage();
}
$assert( $po_number_failed && $order_count_before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $orders_table ) ), 'A failed PO-number finalization removes its provably temporary draft row.' );

$receipt_count_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $receipts_table ) );
$failed_number_key    = 'number-failure-' . wp_generate_uuid4();
$failing_receipts     = new Stockino\Database\PurchaseReceiptRepository( static fn(): bool => false );
try {
	$failing_receipts->create(
		array(
			'purchase_order_id' => $deleted_order['id'],
			'idempotency_key'   => $failed_number_key,
		)
	);
	$receipt_number_failed = false;
} catch ( RuntimeException $exception ) {
	$receipt_number_failed = 'receipt_number_failed' === $exception->getMessage();
}
$assert( $receipt_number_failed && $receipt_count_before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $receipts_table ) ) && null === $receipt_repository->find_by_key( $failed_number_key ), 'A failed receipt-number finalization removes its safe temporary row and does not strand the idempotency token.' );

$supplier_repository = new Stockino\Database\SupplierRepository();
$hardening_suppliers = array();
$active_count        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'active'", $suppliers_table ) );
$needed              = max( 0, 101 - $active_count );
for ( $index = 0; $index < $needed; ++$index ) {
	$hardening_suppliers[] = $supplier_repository->create(
		array(
			'name'   => sprintf( 'Hardening remote supplier %03d %s', $index, wp_generate_password( 6, false, false ) ),
			'code'   => sprintf( 'HRD-%03d-%s', $index, wp_generate_password( 5, false, false ) ),
			'status' => 'active',
		)
	);
}
$second_supplier_page = $request(
	'GET',
	'/stockino/v1/suppliers',
	array(
		'page'     => 2,
		'per_page' => 100,
		'status'   => 'active',
	)
)->get_data();
$beyond_first_hundred = $second_supplier_page['items'][0] ?? null;
$remote_match         = $beyond_first_hundred ? $request(
	'GET',
	'/stockino/v1/suppliers',
	array(
		'page'     => 1,
		'per_page' => 20,
		'status'   => 'active',
		'search'   => $beyond_first_hundred['name'],
	)
)->get_data() : null;
$assert( $beyond_first_hundred && 1 === $remote_match['pagination']['total_items'] && $beyond_first_hundred['id'] === $remote_match['items'][0]['id'], 'Bounded remote supplier search can select a supplier beyond the first 100 active records.' );

wc_update_product_stock( wc_get_product( $simple_id ), $simple_before, 'set' );
wc_update_product_stock( wc_get_product( $variation_id ), $variation_before, 'set' );
wc_update_product_stock( wc_get_product( $parent_id ), $parent_before, 'set' );

$order_ids = array( $order_id, $cancel_order['id'], $draft_cancel['id'], $ordered_cancel['id'], $race_add['id'], $race_line_order['id'], $race_blocked['id'], $unmanaged_order['id'], $deleted_order['id'], $failure_order['id'], $uncertain_order['id'] );
foreach ( $order_ids as $cleanup_order_id ) {
	$receipt_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE purchase_order_id = %d', $receipts_table, $cleanup_order_id ) );
	foreach ( $receipt_ids as $cleanup_receipt_id ) {
		$wpdb->delete( $receipt_items_table, array( 'receipt_id' => $cleanup_receipt_id ) );
	}
	$wpdb->delete( $receipts_table, array( 'purchase_order_id' => $cleanup_order_id ) );
	$wpdb->delete( $items_table, array( 'purchase_order_id' => $cleanup_order_id ) );
	$wpdb->delete( $orders_table, array( 'id' => $cleanup_order_id ) );
}
$wpdb->delete( $relations_table, array( 'supplier_id' => $supplier_id ) );
$wpdb->delete( $suppliers_table, array( 'id' => $supplier_id ) );
foreach ( $hardening_suppliers as $hardening_supplier_id ) {
	$wpdb->delete( $suppliers_table, array( 'id' => $hardening_supplier_id ) );
}
wp_delete_post( $unmanaged_id, true );

WP_CLI::success( 'Stockino Phase 3 purchasing smoke suite passed.' );
