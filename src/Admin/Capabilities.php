<?php
/**
 * Capabilities used by the plugin.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the (filterable) capabilities.
 */
final class Capabilities {

	public const DEFAULT_SETTINGS = 'manage_options';

	public const DEFAULT_SELECT = 'upload_files';

	/**
	 * Capability for the settings page, saving the settings and the connection test.
	 */
	public static function settings(): string {
		/**
		 * Filters the capability required for the settings page, saving and the connection test.
		 *
		 * @param string $capability Default "manage_options".
		 */
		$capability = apply_filters( 'wpncl_settings_capability', self::DEFAULT_SETTINGS );

		return is_string( $capability ) && '' !== $capability ? $capability : self::DEFAULT_SETTINGS;
	}

	/**
	 * Whether the current user may manage the settings.
	 */
	public static function can_manage_settings(): bool {
		return current_user_can( self::settings() );
	}

	/**
	 * Capability for browsing Nextcloud and creating linked media.
	 */
	public static function select(): string {
		/**
		 * Filters the capability required to browse Nextcloud in the media dialog and to link files.
		 *
		 * @param string $capability Default "upload_files".
		 */
		$capability = apply_filters( 'wpncl_select_capability', self::DEFAULT_SELECT );

		return is_string( $capability ) && '' !== $capability ? $capability : self::DEFAULT_SELECT;
	}

	/**
	 * Whether the current user may browse Nextcloud and link files.
	 */
	public static function can_select(): bool {
		return current_user_can( self::select() );
	}
}
