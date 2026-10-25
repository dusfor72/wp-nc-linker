<?php
/**
 * PHPUnit bootstrap for unit tests (WordPress functions are mocked with Brain Monkey).
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, stubbed for unit tests.
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, stubbed for unit tests.
}
if ( ! defined( 'WPNCL_VERSION' ) ) {
	define( 'WPNCL_VERSION', '0.0.0-test' );
}
if ( ! defined( 'WPNCL_PLUGIN_FILE' ) ) {
	define( 'WPNCL_PLUGIN_FILE', dirname( __DIR__ ) . '/wp-nc-linker.php' );
}

require_once dirname( __DIR__ ) . '/src/autoload.php';

if ( ! class_exists( 'WP_Error' ) ) {
	require_once __DIR__ . '/Unit/wp-error-stub.php';
}
