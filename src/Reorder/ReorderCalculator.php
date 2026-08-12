<?php

namespace Stockino\Reorder;

use Stockino\Costing\FixedDecimal;

final class ReorderCalculator {
	/** @param array<string,mixed> $owner @param array<int,array<string,mixed>> $candidates @return array<string,mixed> */
	public function calculate( array $owner, array $candidates, ?string $global_threshold ): array {
		$current   = $this->quantity( $owner['current_stock'] ?? null, true );
		$incoming  = $this->quantity( $owner['confirmed_incoming'] ?? '0.000000' );
		$attention = ! empty( $owner['attention_required'] );
		$position  = FixedDecimal::add( $current, $incoming );
		$point     = $this->nullable_quantity( $owner['custom_reorder_point'] ?? null )
			?? $this->nullable_quantity( $owner['woo_low_stock_amount'] ?? null )
			?? $global_threshold;
		$supplier  = $this->select_supplier( $owner, $candidates );

		$result = array_merge(
			$owner,
			array(
				'current_stock'              => $current,
				'confirmed_incoming'         => $incoming,
				'attention_incoming'         => $this->quantity( $owner['attention_incoming'] ?? '0.000000' ),
				'inventory_position'         => $position,
				'effective_reorder_point'    => $point,
				'target_stock'               => null,
				'raw_reorder_quantity'       => null,
				'recommended_quantity'       => null,
				'state'                      => 'threshold_unknown',
				'urgency'                    => null,
				'preferred_supplier_invalid' => $supplier['preferred_invalid'],
				'supplier'                   => $supplier['supplier'],
				'supplier_selection_method'  => $supplier['method'],
				'supplier_source_count'      => $supplier['source_count'],
			)
		);

		if ( $attention ) {
			$result['state'] = 'attention_required';
			return $result;
		}
		if ( null === $point ) {
			return $result;
		}

		$target                 = $this->nullable_quantity( $owner['custom_target_stock'] ?? null ) ?? $this->default_target( $point );
		$result['target_stock'] = $target;
		if ( FixedDecimal::compare( $position, $point ) > 0 ) {
			$result['state']                = FixedDecimal::compare( $current, $point ) <= 0 ? 'covered_by_incoming' : 'healthy';
			$result['raw_reorder_quantity'] = '0.000000';
			$result['recommended_quantity'] = '0.000000';
			return $result;
		}

		$raw = FixedDecimal::subtract( $target, $position );
		if ( FixedDecimal::compare( $raw, '0.000000' ) < 0 ) {
			$raw = '0.000000';
		}
		$result['raw_reorder_quantity'] = $raw;
		$result['urgency']              = FixedDecimal::compare( $current, '0.000000' ) <= 0
			? 'critical'
			: ( FixedDecimal::compare( $position, $point ) < 0 ? 'high' : 'normal' );

		if ( array() === $candidates ) {
			$result['state']                = 'no_supplier';
			$result['recommended_quantity'] = $raw;
			return $result;
		}
		if ( null === $supplier['supplier'] ) {
			$result['state']                = 'supplier_selection_required';
			$result['recommended_quantity'] = $raw;
			return $result;
		}

		$recommended = $raw;
		$minimum     = $supplier['supplier']['minimum_order_quantity'];
		$multiple    = $supplier['supplier']['order_multiple'];
		if ( null !== $minimum && FixedDecimal::compare( $recommended, $minimum ) < 0 ) {
			$recommended = $minimum;
		}
		if ( null !== $multiple ) {
			$recommended = FixedDecimal::ceil_to_multiple( $recommended, $multiple );
		}
		$result['state']                = 'reorder_needed';
		$result['recommended_quantity'] = $recommended;
		return $result;
	}

	private function default_target( string $point ): string {
		$doubled  = FixedDecimal::multiply_by_integer( $point, 2 );
		$plus_one = FixedDecimal::add( $point, '1.000000' );
		return FixedDecimal::compare( $doubled, $plus_one ) >= 0 ? $doubled : $plus_one;
	}

	/** @param array<string,mixed> $owner @param array<int,array<string,mixed>> $candidates @return array{supplier:array<string,mixed>|null,method:string|null,preferred_invalid:bool,source_count:int} */
	private function select_supplier( array $owner, array $candidates ): array {
		$source_count       = count( array_unique( array_column( $candidates, 'source_product_id' ) ) );
		$preferred_supplier = (int) ( $owner['preferred_supplier_id'] ?? 0 );
		$preferred_product  = (int) ( $owner['preferred_product_id'] ?? 0 );
		if ( $preferred_supplier > 0 && $preferred_product > 0 ) {
			foreach ( $candidates as $candidate ) {
				if ( $preferred_supplier === (int) $candidate['supplier_id'] && $preferred_product === (int) $candidate['source_product_id'] ) {
					return array(
						'supplier'          => $candidate,
						'method'            => 'preferred',
						'preferred_invalid' => false,
						'source_count'      => $source_count,
					);
				}
			}
		}
		$preferred_invalid = $preferred_supplier > 0 || $preferred_product > 0;
		if ( array() === $candidates || $source_count > 1 ) {
			return array(
				'supplier'          => null,
				'method'            => null,
				'preferred_invalid' => $preferred_invalid,
				'source_count'      => $source_count,
			);
		}

		usort(
			$candidates,
			static function ( array $left, array $right ): int {
				$left_lead  = $left['effective_lead_time_days'];
				$right_lead = $right['effective_lead_time_days'];
				if ( null === $left_lead || null === $right_lead ) {
					if ( null === $left_lead && null !== $right_lead ) {
						return 1;
					}
					if ( null !== $left_lead && null === $right_lead ) {
						return -1;
					}
				} elseif ( $left_lead !== $right_lead ) {
					return $left_lead <=> $right_lead;
				}
				return (int) $left['supplier_id'] <=> (int) $right['supplier_id'];
			}
		);
		$method = null === $candidates[0]['effective_lead_time_days'] ? 'lowest_id_fallback' : 'lowest_lead_time';
		if ( $preferred_invalid ) {
			$method = 'preferred_invalid_fallback_' . $method;
		}
		return array(
			'supplier'          => $candidates[0],
			'method'            => $method,
			'preferred_invalid' => $preferred_invalid,
			'source_count'      => $source_count,
		);
	}

	private function quantity( mixed $value, bool $signed = false ): string {
		$normalized = $signed ? FixedDecimal::normalize_quantity( $value ) : FixedDecimal::normalize_cost( $value );
		if ( null === $normalized ) {
			throw new \InvalidArgumentException( 'Invalid reorder quantity.' );
		}
		return $normalized;
	}

	private function nullable_quantity( mixed $value ): ?string {
		return null === $value || '' === $value ? null : FixedDecimal::normalize_cost( $value );
	}
}
