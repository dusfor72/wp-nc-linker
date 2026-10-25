<?php
/**
 * Test double for wp-config.php constants.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Config;

use WpNcLinker\Config\Constants;

/**
 * Constants from an array instead of define().
 */
final class FakeConstants extends Constants {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $values Constant name => value.
	 */
	public function __construct( private array $values = array() ) {
	}

	public function get( string $name ): ?string {
		$value = $this->values[ $name ] ?? null;

		return is_string( $value ) ? $value : null;
	}

	public function is_defined( string $name ): bool {
		return array_key_exists( $name, $this->values );
	}
}
