<?php

namespace Stockino\Purchasing;

final class MysqlReceiveLock implements ReceiveLock {
	public function __construct( private readonly int $timeout = 3 ) {}

	public function acquire( int $purchase_order_id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->name( $purchase_order_id ), $this->timeout ) );
	}

	public function release( int $purchase_order_id ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name( $purchase_order_id ) ) );
	}

	private function name( int $purchase_order_id ): string {
		global $wpdb;
		return substr( 'stockino:' . md5( DB_NAME . ':' . $wpdb->prefix ) . ':receive:' . $purchase_order_id, 0, 64 );
	}
}
