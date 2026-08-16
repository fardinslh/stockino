<?php

declare(strict_types=1);

namespace Stockino\Domain\Order;

final class CanonicalAddress {
	public function __construct(
		public readonly string $first_name,
		public readonly string $last_name,
		public readonly string $address_1,
		public readonly string $city,
		public readonly string $state,
		public readonly string $postcode,
		public readonly string $country = 'IR',
		public readonly ?string $phone = null,
		public readonly ?string $address_2 = null
	) {}

	public function get_full_name(): string {
		return trim( $this->first_name . ' ' . $this->last_name );
	}

	public function get_full_address(): string {
		$parts = array_filter( array( $this->state, $this->city, $this->address_1, $this->address_2 ) );
		return implode( '، ', $parts );
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'first_name' => $this->first_name,
			'last_name'  => $this->last_name,
			'address_1'  => $this->address_1,
			'address_2'  => $this->address_2,
			'city'       => $this->city,
			'state'      => $this->state,
			'postcode'   => $this->postcode,
			'country'    => $this->country,
			'phone'      => $this->phone,
		);
	}
}
