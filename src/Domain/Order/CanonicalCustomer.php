<?php

declare(strict_types=1);

namespace Stockino\Domain\Order;

final class CanonicalCustomer {
	public function __construct(
		public readonly ?string $external_customer_id,
		public readonly string $name,
		public readonly ?string $phone = null,
		public readonly ?string $email = null,
		public readonly ?string $national_id = null
	) {}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'external_customer_id' => $this->external_customer_id,
			'name'                 => $this->name,
			'phone'                => $this->phone,
			'email'                => $this->email,
			'national_id'          => $this->national_id,
		);
	}
}
