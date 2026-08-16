<?php

declare(strict_types=1);

namespace Stockino\Domain\Publication;

final class PublicationState {
	public const STATUS_DRAFT       = 'DRAFT';
	public const STATUS_READY       = 'READY';
	public const STATUS_PENDING     = 'PENDING';
	public const STATUS_PUBLISHED   = 'PUBLISHED';
	public const STATUS_FAILED      = 'FAILED';
	public const STATUS_UNPUBLISHED = 'UNPUBLISHED';

	public static function is_valid_status( string $status ): bool {
		return in_array(
			$status,
			array(
				self::STATUS_DRAFT,
				self::STATUS_READY,
				self::STATUS_PENDING,
				self::STATUS_PUBLISHED,
				self::STATUS_FAILED,
				self::STATUS_UNPUBLISHED,
			),
			true
		);
	}
}
