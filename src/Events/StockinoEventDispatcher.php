<?php

declare(strict_types=1);

namespace Stockino\Events;

final class StockinoEventDispatcher {
	/** @var array<string, array<int, callable>> */
	private static array $listeners = array();

	public static function listen( string $event_name, callable $listener ): void {
		self::$listeners[ $event_name ][] = $listener;
	}

	public static function dispatch( StockinoEvent $event ): void {
		// Native Stockino internal listeners
		$listeners = self::$listeners[ $event->event_name ] ?? array();
		foreach ( $listeners as $listener ) {
			try {
				$listener( $event );
			} catch ( \Throwable $e ) {
				// Suppress listener errors to prevent breaking caller
			}
		}

		// Also bridge to WordPress actions
		do_action( $event->event_name, $event->payload, $event );
	}
}
