<?php
/**
 * "Nextcloud" tab of the media dialog.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the media dialog extension wherever the media modal is used (classic editor, block
 * editor, media library) for users with the select capability.
 */
final class MediaTab {

	public const HANDLE = 'wpncl-media-tab';

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_media', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues script and style.
	 */
	public function enqueue(): void {
		if ( ! Capabilities::can_select() ) {
			return;
		}

		$asset_file = WPNCL_PLUGIN_DIR . '/build/media-tab.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		if ( ! is_array( $asset ) || ! is_array( $asset['dependencies'] ?? null ) || ! is_string( $asset['version'] ?? null ) ) {
			return;
		}

		$build_url = plugins_url( 'build/', WPNCL_PLUGIN_FILE );
		wp_enqueue_script( self::HANDLE, $build_url . 'media-tab.js', array_merge( array( 'media-views' ), $asset['dependencies'] ), $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'wp-nc-linker', WPNCL_PLUGIN_DIR . '/languages' );

		if ( is_readable( WPNCL_PLUGIN_DIR . '/build/media-tab.css' ) ) {
			wp_enqueue_style( self::HANDLE, $build_url . 'media-tab.css', array( 'wp-components' ), $asset['version'] );
		}
	}
}
