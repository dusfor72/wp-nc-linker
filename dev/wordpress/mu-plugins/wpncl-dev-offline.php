<?php
/**
 * Development only: the containers have no internet access. Disables the update checks against
 * wordpress.org, which would otherwise log PHP warnings on every admin request.
 *
 * External HTTP requests are blocked via WP_HTTP_BLOCK_EXTERNAL (docker-compose.yml).
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

add_action(
	'muplugins_loaded',
	static function (): void {
		remove_action( 'init', 'wp_schedule_update_checks' );
		remove_action( 'wp_version_check', 'wp_version_check' );
		remove_action( 'wp_update_plugins', 'wp_update_plugins' );
		remove_action( 'wp_update_themes', 'wp_update_themes' );
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		foreach ( array( 'load-plugins.php', 'load-update.php', 'load-update-core.php' ) as $hook ) {
			remove_action( $hook, 'wp_update_plugins' );
		}
		foreach ( array( 'load-themes.php', 'load-update.php', 'load-update-core.php' ) as $hook ) {
			remove_action( $hook, 'wp_update_themes' );
		}
		remove_action( 'upgrader_process_complete', 'wp_update_plugins' );
		remove_action( 'upgrader_process_complete', 'wp_update_themes' );
	}
);

// Language list on Settings → General: answer locally instead of asking wordpress.org.
add_filter(
	'translations_api',
	static fn (): array => array( 'translations' => array() )
);
