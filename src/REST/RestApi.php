<?php

namespace Stockino\REST;

use Stockino\Database\StockMovementRepository;
use Stockino\Inventory\AdjustmentReason;
use Stockino\Inventory\InventoryService;
use Stockino\Inventory\StockAdjustmentService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestApi {
	public const NAMESPACE = 'stockino/v1';

	public function __construct(
		private readonly InventoryService $inventory,
		private readonly StockAdjustmentService $adjustments,
		private readonly StockMovementRepository $movements
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_csv' ), 10, 4 );
	}

	public function register_routes(): void {
		$management = array( 'permission_callback' => array( $this, 'can_manage' ) );
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array_merge(
				$management,
				array(
					'methods'  => 'GET',
					'callback' => array( $this, 'status' ),
				)
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/inventory',
			array_merge(
				$management,
				array(
					'methods'  => 'GET',
					'callback' => array( $this, 'inventory' ),
				)
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/inventory/stats',
			array_merge(
				$management,
				array(
					'methods'  => 'GET',
					'callback' => array( $this, 'stats' ),
				)
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/inventory/filters',
			array_merge(
				$management,
				array(
					'methods'  => 'GET',
					'callback' => array( $this, 'filters' ),
				)
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/inventory/bulk-adjust',
			array_merge(
				$management,
				array(
					'methods'  => 'POST',
					'callback' => array( $this, 'bulk_adjust' ),
				)
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/inventory/export',
			array_merge(
				$management,
				array(
					'methods'  => 'GET',
					'callback' => array( $this, 'export' ),
				)
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/movements',
			array_merge(
				$management,
				array(
					'methods'  => 'GET',
					'callback' => array( $this, 'movements' ),
				)
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)/adjust-stock',
			array_merge(
				$management,
				array(
					'methods'  => 'POST',
					'callback' => array( $this, 'adjust_stock' ),
				)
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function status(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'name'       => 'Stockino',
				'version'    => STOCKINO_VERSION,
				'db_version' => STOCKINO_DB_VERSION,
			)
		);
	}

	public function inventory( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->inventory->list( $this->inventory_params( $request ) ) );
	}

	public function stats(): WP_REST_Response {
		return new WP_REST_Response( $this->inventory->stats() );
	}

	public function filters(): WP_REST_Response {
		return new WP_REST_Response( $this->inventory->filters() );
	}

	/** @return WP_REST_Response|WP_Error */
	public function movements( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( ! wc_get_product( $id ) ) {
			return new WP_Error( 'stockino_invalid_product', __( 'The requested product does not exist.', 'stockino' ), array( 'status' => 404 ) );
		}
		$page_param     = $request->get_param( 'page' );
		$per_page_param = $request->get_param( 'per_page' );
		$page           = max( 1, absint( null !== $page_param ? $page_param : 1 ) );
		$per_page       = min( 100, max( 1, absint( null !== $per_page_param ? $per_page_param : 20 ) ) );
		return new WP_REST_Response( $this->movements->paginate_for_product( $id, $page, $per_page ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public function adjust_stock( WP_REST_Request $request ) {
		$payload = $this->adjustment_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$result = $this->adjustments->adjust(
			absint( $request['id'] ),
			$payload['mode'],
			$payload['quantity'],
			$payload['reason'],
			$payload['note'],
			$payload['expected_current']
		);

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function bulk_adjust( WP_REST_Request $request ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'product_ids' ) ) ) ) );
		if ( array() === $ids ) {
			return new WP_Error( 'stockino_empty_selection', __( 'Select at least one product.', 'stockino' ), array( 'status' => 400 ) );
		}
		if ( count( $ids ) > 100 ) {
			return new WP_Error( 'stockino_bulk_limit', __( 'A bulk adjustment can include at most 100 products.', 'stockino' ), array( 'status' => 400 ) );
		}

		$quantity = $request->get_param( 'quantity' );
		$reason   = sanitize_key( (string) $request->get_param( 'reason' ) );
		$note     = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		if ( ! is_numeric( $quantity ) || ! is_finite( (float) $quantity ) || 0.0 === (float) $quantity ) {
			return new WP_Error( 'stockino_invalid_quantity', __( 'Enter a non-zero numeric adjustment.', 'stockino' ), array( 'status' => 400 ) );
		}
		if ( ! AdjustmentReason::is_valid( $reason ) ) {
			return new WP_Error( 'stockino_invalid_reason', __( 'Choose a valid adjustment reason.', 'stockino' ), array( 'status' => 400 ) );
		}

		$updated = array();
		$failed  = array();
		foreach ( $ids as $id ) {
			$result = $this->adjustments->adjust( $id, 'delta', (float) $quantity, $reason, $note );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'product_id' => $id,
					'code'       => $result->get_error_code(),
					'message'    => $result->get_error_message(),
				);
			} else {
				$updated[] = $result;
			}
		}

		return new WP_REST_Response(
			array(
				'updated' => $updated,
				'failed'  => $failed,
			)
		);
	}

	public function export( WP_REST_Request $request ): WP_REST_Response {
		$params = $this->inventory_params( $request );
		$stream = fopen( 'php://temp/maxmemory:5242880', 'w+' );
		fwrite( $stream, "\xEF\xBB\xBF" );
		fputcsv( $stream, array( 'product_id', 'variation_id', 'product_name', 'sku', 'product_type', 'stock_quantity', 'stock_status', 'low_stock_amount' ) );
		$page  = 1;
		$total = null;
		do {
			$params['page']     = $page;
			$params['per_page'] = 100;
			$result             = $this->inventory->list( $params, $total );
			$total              = $result['total_items'];
			foreach ( $result['items'] as $item ) {
				fputcsv(
					$stream,
					array(
						$item['parent_id'] ? $item['parent_id'] : $item['id'],
						$item['parent_id'] ? $item['id'] : '',
						$this->csv_safe( $item['name'] ),
						$this->csv_safe( $item['sku'] ),
						$item['type'],
						$item['stock_quantity'],
						$item['stock_status'],
						$item['low_stock_amount'],
					)
				);
			}
			++$page;
		} while ( $page <= $result['total_pages'] );

		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream );
		$response = new WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="stockino-inventory-' . gmdate( 'Y-m-d' ) . '.csv"' );
		return $response;
	}

	public function serve_csv( bool $served, $result, WP_REST_Request $request, $server ): bool {
		if ( '/stockino/v1/inventory/export' !== $request->get_route() || ! $result instanceof WP_REST_Response ) {
			return $served;
		}
		$headers = $result->get_headers();
		if ( 200 !== $result->get_status() || ! str_starts_with( (string) ( $headers['Content-Type'] ?? '' ), 'text/csv' ) ) {
			return $served;
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated CSV download body.
		return true;
	}

	/** @return array<string,mixed> */
	private function inventory_params( WP_REST_Request $request ): array {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		return array(
			'page'         => max( 1, absint( null !== $page ? $page : 1 ) ),
			'per_page'     => absint( null !== $per_page ? $per_page : 20 ),
			'search'       => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'stock_status' => sanitize_key( (string) $request->get_param( 'stock_status' ) ),
			'type'         => sanitize_key( (string) $request->get_param( 'type' ) ),
			'category'     => sanitize_title( (string) $request->get_param( 'category' ) ),
			'low_stock'    => rest_sanitize_boolean( $request->get_param( 'low_stock' ) ),
			'manage_stock' => null === $request->get_param( 'manage_stock' ) ? null : rest_sanitize_boolean( $request->get_param( 'manage_stock' ) ),
		);
	}

	/** @return array{mode:string,quantity:float,reason:string,note:string,expected_current:?float}|WP_Error */
	private function adjustment_payload( WP_REST_Request $request ) {
		$mode     = sanitize_key( (string) $request->get_param( 'mode' ) );
		$quantity = $request->get_param( 'quantity' );
		$reason   = sanitize_key( (string) $request->get_param( 'reason' ) );
		$note     = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		$expected = $request->get_param( 'expected_current' );
		if ( ! in_array( $mode, array( 'set', 'delta' ), true ) || ! is_numeric( $quantity ) || ! is_finite( (float) $quantity ) ) {
			return new WP_Error( 'stockino_invalid_adjustment', __( 'Choose set or delta mode and enter a numeric quantity.', 'stockino' ), array( 'status' => 400 ) );
		}
		if ( ! AdjustmentReason::is_valid( $reason ) ) {
			return new WP_Error( 'stockino_invalid_reason', __( 'Choose a valid adjustment reason.', 'stockino' ), array( 'status' => 400 ) );
		}
		if ( strlen( $note ) > 1000 ) {
			return new WP_Error( 'stockino_note_too_long', __( 'The note must be 1000 characters or fewer.', 'stockino' ), array( 'status' => 400 ) );
		}

		return array(
			'mode'             => $mode,
			'quantity'         => (float) $quantity,
			'reason'           => $reason,
			'note'             => $note,
			'expected_current' => is_numeric( $expected ) ? (float) $expected : null,
		);
	}

	private function csv_safe( string $value ): string {
		return preg_match( '/^[=+\-@]/', $value ) ? "'{$value}" : $value;
	}
}
