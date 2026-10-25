<?php
/**
 * Plugin bootstrap.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker;

defined( 'ABSPATH' ) || exit;

/**
 * Wires all plugin components into WordPress.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Registers the WordPress hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads the bundled translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wp-nc-linker', false, dirname( plugin_basename( WPNCL_PLUGIN_FILE ) ) . '/languages' );
	}
}
