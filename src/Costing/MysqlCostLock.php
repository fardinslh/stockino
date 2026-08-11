<?php

namespace Stockino\Costing;

final class MysqlCostLock implements CostLock {
	public function __construct( private readonly int $timeout = 3 ) {}

	public function acquire_many( array $stock_owner_ids ): bool {
		$ids      = $this->ids( $stock_owner_ids );
		$acquired = array();
		foreach ( $ids as $id ) {
			if ( ! $this->acquire( $id ) ) {
				$this->release_many( $acquired );
				return false;
			}
			$acquired[] = $id;
		}
		return true;
	}

	public function release_many( array $stock_owner_ids ): void {
		foreach ( array_reverse( $this->ids( $stock_owner_ids ) ) as $id ) {
			global $wpdb;
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name( $id ) ) );
		}
	}

	private function acquire( int $stock_owner_id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->name( $stock_owner_id ), $this->timeout ) );
	}

	/** @param array<int,int> $stock_owner_ids @return array<int,int> */
	private function ids( array $stock_owner_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $stock_owner_ids ) ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	private function name( int $stock_owner_id ): string {
		global $wpdb;
		return substr( 'stockino:' . md5( DB_NAME . ':' . $wpdb->prefix ) . ':cost:' . $stock_owner_id, 0, 64 );
	}
}
