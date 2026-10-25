<?php
/**
 * Access to configuration constants from wp-config.php.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the optional configuration constants (separate class so tests can replace it).
 */
class Constants {

	public const URL          = 'WPNCL_URL';
	public const USER         = 'WPNCL_USER';
	public const APP_PASSWORD = 'WPNCL_APP_PASSWORD';

	/**
	 * Returns the value of a constant, or null if it is not defined or not a string.
	 *
	 * @param string $name Constant name.
	 */
	public function get( string $name ): ?string {
		if ( ! defined( $name ) ) {
			return null;
		}
		$value = constant( $name );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Whether the constant is defined (regardless of its value).
	 *
	 * @param string $name Constant name.
	 */
	public function is_defined( string $name ): bool {
		return defined( $name );
	}
}
