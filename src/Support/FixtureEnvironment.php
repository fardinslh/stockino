<?php

namespace Stockino\Support;

final class FixtureEnvironment {
	public static function is_allowed( string $environment ): bool {
		return in_array( strtolower( trim( $environment ) ), array( 'local', 'development' ), true );
	}
}
