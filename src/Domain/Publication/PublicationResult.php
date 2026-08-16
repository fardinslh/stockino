<?php

declare(strict_types=1);

namespace Stockino\Domain\Publication;

final class PublicationResult {
	/**
	 * @param array<int, string> $errors
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $status,
		public readonly string $message,
		public readonly ?string $external_id = null,
		public readonly array $errors = array(),
		public readonly ?int $status_code = null
	) {}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'success'     => $this->success,
			'status'      => $this->status,
			'message'     => $this->message,
			'external_id' => $this->external_id,
			'errors'      => $this->errors,
			'status_code' => $this->status_code,
		);
	}
}
