<?php

declare(strict_types=1);

namespace Stockino\Domain\Pricing;

final class CanonicalPrice {
	public function __construct(
		public readonly float $regular_price,
		public readonly ?float $sale_price = null,
		public readonly string $currency = 'IRT'
	) {
		if ( $this->regular_price < 0 ) {
			throw new \InvalidArgumentException( 'قیمت پایه نمی‌تواند منفی باشد.' );
		}
		if ( null !== $this->sale_price && $this->sale_price < 0 ) {
			throw new \InvalidArgumentException( 'قیمت حراج نمی‌تواند منفی باشد.' );
		}
	}

	public function get_effective_price(): float {
		return ( null !== $this->sale_price && $this->sale_price > 0 && $this->sale_price < $this->regular_price )
			? $this->sale_price
			: $this->regular_price;
	}

	public function get_effective_price_toman(): int {
		return (int) round( $this->get_effective_price() );
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'regular_price'   => $this->regular_price,
			'sale_price'      => $this->sale_price,
			'effective_price' => $this->get_effective_price(),
			'currency'        => $this->currency,
		);
	}
}
