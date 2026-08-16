<?php

declare(strict_types=1);

namespace Stockino\Orders;

use Stockino\Domain\Order\CanonicalAddress;
use Stockino\Domain\Order\CanonicalCustomer;
use Stockino\Domain\Order\CanonicalOrder;
use Stockino\Domain\Order\CanonicalOrderItem;

final class OrderNormalizer {
	/**
	 * @param array<string, mixed> $raw
	 * @param string $marketplace
	 * @return CanonicalOrder
	 */
	public function normalize( array $raw, string $marketplace = 'basalam' ): CanonicalOrder {
		$external_id = (string) ( $raw['id'] ?? $raw['order_id'] ?? $raw['external_id'] ?? '' );
		if ( '' === $external_id ) {
			throw new \InvalidArgumentException( 'شناسه سفارش در داده‌های دریافتی یافت نشد.' );
		}

		$status = $this->normalize_status( (string) ( $raw['status'] ?? 'pending' ), $marketplace );

		$total    = (float) ( $raw['total_amount'] ?? $raw['total'] ?? $raw['price'] ?? 0 );
		$shipping = (float) ( $raw['shipping_amount'] ?? $raw['shipping_cost'] ?? 0 );
		$discount = (float) ( $raw['discount_amount'] ?? $raw['discount'] ?? 0 );
		$currency = (string) ( $raw['currency'] ?? 'IRT' );

		// Customer
		$customer_raw = is_array( $raw['customer'] ?? null ) ? $raw['customer'] : array();
		$customer_name = (string) ( $customer_raw['name'] ?? $raw['customer_name'] ?? 'مشتری بازارگاه' );
		$customer_phone = (string) ( $customer_raw['phone'] ?? $raw['customer_phone'] ?? $raw['phone'] ?? '' );
		$customer_email = (string) ( $customer_raw['email'] ?? $raw['customer_email'] ?? '' );
		$customer = new CanonicalCustomer(
			isset( $customer_raw['id'] ) ? (string) $customer_raw['id'] : null,
			$customer_name,
			$customer_phone ?: null,
			$customer_email ?: null,
			isset( $customer_raw['national_id'] ) ? (string) $customer_raw['national_id'] : null
		);

		// Shipping Address
		$addr_raw = is_array( $raw['shipping_address'] ?? null ) ? $raw['shipping_address'] : $customer_raw;
		$first_name = (string) ( $addr_raw['first_name'] ?? ( explode( ' ', $customer_name )[0] ?? 'مشتری' ) );
		$last_name  = (string) ( $addr_raw['last_name'] ?? ( substr( $customer_name, strlen( $first_name ) + 1 ) ?: 'بازارگاه' ) );
		$address_1  = (string) ( $addr_raw['address_1'] ?? $addr_raw['address'] ?? $raw['address'] ?? '' );
		$city       = (string) ( $addr_raw['city'] ?? $raw['city'] ?? 'تهران' );
		$state      = (string) ( $addr_raw['state'] ?? $addr_raw['province'] ?? $raw['state'] ?? 'تهران' );
		$postcode   = (string) ( $addr_raw['postcode'] ?? $addr_raw['postal_code'] ?? $raw['postal_code'] ?? '0000000000' );
		$country    = (string) ( $addr_raw['country'] ?? 'IR' );

		$shipping_address = new CanonicalAddress(
			$first_name,
			$last_name,
			$address_1,
			$city,
			$state,
			$postcode,
			$country,
			$customer_phone ?: null
		);

		// Line Items
		$items_raw = is_array( $raw['items'] ?? null ) ? $raw['items'] : array();
		$items     = array();

		foreach ( $items_raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item_id    = isset( $item['id'] ) ? (string) $item['id'] : null;
			$title      = (string) ( $item['title'] ?? $item['name'] ?? $item['product_name'] ?? 'کالای بازارگاه' );
			$sku        = ! empty( $item['sku'] ) ? (string) $item['sku'] : null;
			$quantity   = (float) ( $item['quantity'] ?? $item['count'] ?? 1 );
			$unit_price = (float) ( $item['unit_price'] ?? $item['price'] ?? 0 );
			$item_total = (float) ( $item['total_price'] ?? ( $unit_price * $quantity ) );

			// Try to lookup local WC Product ID by SKU
			$wc_prod_id = null;
			$wc_var_id  = null;
			if ( $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$found_id = wc_get_product_id_by_sku( $sku );
				if ( $found_id > 0 && function_exists( 'wc_get_product' ) ) {
					$wc_product = wc_get_product( $found_id );
					if ( $wc_product ) {
						if ( $wc_product->is_type( 'variation' ) ) {
							$wc_var_id  = $found_id;
							$wc_prod_id = $wc_product->get_parent_id();
						} else {
							$wc_prod_id = $found_id;
						}
					}
				}
			}

			$items[] = new CanonicalOrderItem(
				$item_id,
				$title,
				$sku,
				$quantity,
				$unit_price,
				$item_total,
				$wc_prod_id,
				$wc_var_id
			);
		}

		$created_raw = (string) ( $raw['created_at'] ?? $raw['date_created'] ?? 'now' );
		try {
			$created_at = new \DateTimeImmutable( $created_raw );
		} catch ( \Exception $e ) {
			$created_at = new \DateTimeImmutable();
		}

		return new CanonicalOrder(
			$external_id,
			$marketplace,
			$status,
			$total,
			$shipping,
			$discount,
			$currency,
			$customer,
			$shipping_address,
			$shipping_address,
			$items,
			$created_at,
			(string) ( $raw['customer_note'] ?? $raw['note'] ?? '' ),
			isset( $raw['tracking_number'] ) ? (string) $raw['tracking_number'] : null,
			(string) ( $raw['shipping_method'] ?? 'پست پیشتاز' ),
			'marketplace_' . $marketplace,
			array( 'raw' => $raw )
		);
	}

	public function normalize_status( string $raw_status, string $marketplace ): string {
		$s = strtolower( trim( $raw_status ) );

		if ( 'basalam' === $marketplace ) {
			return match ( $s ) {
				'100', 'pending', 'awaiting_payment' => CanonicalOrder::STATUS_PENDING,
				'200', 'paid', 'processing', 'preparing' => CanonicalOrder::STATUS_PROCESSING,
				'300', 'sent', 'shipped', 'in_transit' => CanonicalOrder::STATUS_SHIPPED,
				'400', 'delivered', 'completed' => CanonicalOrder::STATUS_DELIVERED,
				'500', 'cancelled', 'canceled', 'refunded' => CanonicalOrder::STATUS_CANCELLED,
				'600', 'returned' => CanonicalOrder::STATUS_RETURNED,
				default => CanonicalOrder::STATUS_CONFIRMED,
			};
		}

		return match ( $s ) {
			'pending' => CanonicalOrder::STATUS_PENDING,
			'processing' => CanonicalOrder::STATUS_PROCESSING,
			'shipped' => CanonicalOrder::STATUS_SHIPPED,
			'delivered' => CanonicalOrder::STATUS_DELIVERED,
			'cancelled', 'canceled' => CanonicalOrder::STATUS_CANCELLED,
			'returned' => CanonicalOrder::STATUS_RETURNED,
			default => CanonicalOrder::STATUS_CONFIRMED,
		};
	}
}
