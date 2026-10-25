<?php
/**
 * Development only: lets the integration tests switch filters and constants in web requests.
 *
 * The values come from options that only the tests set (option names start with wpncl_test_).
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

$wpncl_test_constants = get_option( 'wpncl_test_constants' );
if ( is_array( $wpncl_test_constants ) ) {
	foreach ( array( 'WPNCL_URL', 'WPNCL_USER', 'WPNCL_APP_PASSWORD' ) as $wpncl_test_name ) {
		if ( isset( $wpncl_test_constants[ $wpncl_test_name ] ) && ! defined( $wpncl_test_name ) ) {
			define( $wpncl_test_name, $wpncl_test_constants[ $wpncl_test_name ] );
		}
	}
}
unset( $wpncl_test_constants, $wpncl_test_name );

add_filter(
	'wpncl_settings_capability',
	static function ( $capability ) {
		$test_capability = get_option( 'wpncl_test_settings_capability' );

		return is_string( $test_capability ) && '' !== $test_capability ? $test_capability : $capability;
	}
);
