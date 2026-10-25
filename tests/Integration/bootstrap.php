<?php
/**
 * Bootstrap for integration tests: loads the WordPress installation of the test environment.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

$wpncl_wp_load = getenv( 'WPNCL_WP_LOAD' );
if ( false === $wpncl_wp_load || '' === $wpncl_wp_load ) {
	$wpncl_wp_load = '/var/www/html/wp-load.php';
}
if ( ! is_readable( $wpncl_wp_load ) ) {
	fwrite( STDERR, "WordPress not found; run the integration tests with dev/bin/test-integration.sh.\n" );
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$_SERVER['HTTP_HOST']   = 'localhost:8080';
$_SERVER['SERVER_NAME'] = 'localhost';

require_once $wpncl_wp_load;
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( \WpNcLinker\Plugin::class ) ) {
	fwrite( STDERR, "The plugin is not active in the test installation.\n" );
	exit( 1 );
}
