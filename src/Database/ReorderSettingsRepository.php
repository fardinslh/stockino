<?php

namespace Stockino\Database;

final class ReorderSettingsRepository {
	/** @return array<string,mixed>|null */
	public function find( int $stock_owner_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE stock_owner_id = %d', $this->table(), $stock_owner_id ), ARRAY_A );
		return $row ? $this->format( $row ) : null;
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	public function save( int $stock_owner_id, array $data ): array {
		global $wpdb;
		$current = $this->find( $stock_owner_id );
		$now     = current_time( 'mysql', true );
		$user_id = get_current_user_id() > 0 ? get_current_user_id() : null;
		if ( $current ) {
			$data['updated_by'] = $user_id;
			$data['updated_at'] = $now;
			if ( false === $wpdb->update( $this->table(), $data, array( 'stock_owner_id' => $stock_owner_id ) ) ) {
				throw new \RuntimeException( 'reorder_settings_update_failed' );
			}
		} else {
			$data += array(
				'stock_owner_id' => $stock_owner_id,
				'created_by'     => $user_id,
				'updated_by'     => $user_id,
				'created_at'     => $now,
				'updated_at'     => $now,
			);
			if ( false === $wpdb->insert( $this->table(), $data ) ) {
				throw new \RuntimeException( 'reorder_settings_insert_failed' );
			}
		}
		return $this->find( $stock_owner_id ) ?? throw new \RuntimeException( 'reorder_settings_read_failed' );
	}

	/** @return array<string,mixed> */
	private function format( array $row ): array {
		return array(
			'stock_owner_id'        => (int) $row['stock_owner_id'],
			'custom_reorder_point'  => null !== $row['custom_reorder_point'] ? (string) $row['custom_reorder_point'] : null,
			'custom_target_stock'   => null !== $row['custom_target_stock'] ? (string) $row['custom_target_stock'] : null,
			'preferred_supplier_id' => $row['preferred_supplier_id'] ? (int) $row['preferred_supplier_id'] : null,
			'preferred_product_id'  => $row['preferred_product_id'] ? (int) $row['preferred_product_id'] : null,
			'created_by'            => $row['created_by'] ? (int) $row['created_by'] : null,
			'updated_by'            => $row['updated_by'] ? (int) $row['updated_by'] : null,
			'created_at'            => $this->iso_date( (string) $row['created_at'] ),
			'updated_at'            => $this->iso_date( (string) $row['updated_at'] ),
		);
	}

	private function iso_date( string $value ): string {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date ? $date->format( DATE_RFC3339 ) : $value;
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'stockino_reorder_settings';
	}
}
