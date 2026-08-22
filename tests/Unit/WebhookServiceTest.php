<?php

declare(strict_types=1);

namespace Stockino\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stockino\Database\MarketplaceRepository;
use Stockino\Marketplaces\MarketplaceConnectionService;
use Stockino\Marketplaces\WebhookService;
use Stockino\Orders\OrderSyncService;

final class WebhookServiceTest extends TestCase {
	public function test_webhook_service_ignores_unrelated_events(): void {
		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );

		$repo       = new MarketplaceRepository();
		$conn       = new MarketplaceConnectionService( $repo );
		$order_sync = new OrderSyncService( $repo, $conn );
		$webhook    = new WebhookService( $repo, $order_sync );

		$res = $webhook->handle_webhook( 'basalam', array( 'event' => 'user.logged_in' ) );
		self::assertSame( 'ignored', $res['status'] );
	}
}
