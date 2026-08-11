<?php

namespace Stockino\Costing;

use Stockino\Database\InventoryCostRepository;
use Stockino\Database\ValuationRepository;
use WP_Error;

final class ValuationService {
	public function __construct(
		private readonly ValuationRepository $valuations,
		private readonly InventoryCostRepository $costs,
		private readonly InventoryCostService $cost_service
	) {}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>} */
	public function list( int $page, int $per_page, string $search, string $stock_status, string $cost_status, string $sort, string $direction ): array {
		return $this->valuations->paginate(
			$page,
			$per_page,
			sanitize_text_field( $search ),
			sanitize_key( $stock_status ),
			sanitize_key( $cost_status ),
			sanitize_key( $sort ),
			sanitize_key( $direction )
		);
	}

	/** @return array<string,mixed> */
	public function stats(): array {
		return $this->valuations->stats();
	}

	/** @return array<string,mixed>|WP_Error */
	public function get( int $stock_owner_id ) {
		$row = $this->valuations->find( $stock_owner_id );
		return $row ?? new WP_Error( 'stockino_valuation_not_found', 'The active stock owner does not exist.', array( 'status' => 404 ) );
	}

	/** @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>}|WP_Error */
	public function history( int $stock_owner_id, int $page, int $per_page ) {
		if ( $stock_owner_id <= 0 ) {
			return new WP_Error( 'stockino_invalid_stock_owner', 'Provide a valid stock-owner ID.', array( 'status' => 400 ) );
		}
		return $this->costs->history( $stock_owner_id, $page, $per_page );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function set_initial( int $stock_owner_id, array $input ) {
		return $this->cost_service->set_initial( $stock_owner_id, $input );
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function correct( int $stock_owner_id, array $input ) {
		return $this->cost_service->correct( $stock_owner_id, $input );
	}
}
