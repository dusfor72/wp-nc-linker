<?php
/**
 * Removal of the plugin data.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker;

use WpNcLinker\Config\Settings;
use WpNcLinker\Health\Scheduler;
use WpNcLinker\Media\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Removes options, scheduled checks and transients (F10). Linked attachments are deleted only if
 * the setting says so; otherwise they stay (with their link data, so a reinstallation shows them
 * again).
 */
final class Uninstaller {

	/**
	 * Transient prefixes of the plugin.
	 */
	private const TRANSIENT_PREFIXES = array( 'wpncl_browse_', 'wpncl_seen_', 'wpncl_test_result_' );

	/**
	 * Runs the removal for the current site.
	 */
	public static function run(): void {
		$option = get_option( Settings::OPTION );
		if ( is_array( $option ) && true === ( $option['delete_on_uninstall'] ?? false ) ) {
			self::delete_attachments();
		}

		Scheduler::unschedule();
		delete_option( Settings::OPTION );
		foreach ( Scheduler::options() as $name ) {
			delete_option( $name );
		}
		self::delete_transients();
	}

	/**
	 * Deletes all linked attachments; never touches files (they have none in uploads).
	 */
	private static function delete_attachments(): void {
		add_filter( 'wp_delete_file', '__return_empty_string', PHP_INT_MAX - 1 );
		try {
			foreach ( ( new Repository() )->all_ids() as $id ) {
				wp_delete_attachment( $id, true );
			}
		} finally {
			remove_filter( 'wp_delete_file', '__return_empty_string', PHP_INT_MAX - 1 );
		}
	}

	/**
	 * Deletes the transients stored in the options table (with an external object cache they expire
	 * on their own, after at most an hour).
	 */
	private static function delete_transients(): void {
		global $wpdb;

		foreach ( self::TRANSIENT_PREFIXES as $prefix ) {
			foreach ( array( '_transient_', '_transient_timeout_' ) as $type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup; transients cannot be listed through the API.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $type . $prefix ) . '%' ) );
			}
		}
		wp_cache_delete( 'alloptions', 'options' );
	}
}
