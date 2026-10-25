<?php
/**
 * Plugin bootstrap.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker;

use WpNcLinker\Admin\Capabilities;
use WpNcLinker\Admin\ConnectionTestAction;
use WpNcLinker\Admin\SettingsPage;
use WpNcLinker\Cli\Command;
use WpNcLinker\Config\Constants;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\Settings;
use WpNcLinker\Config\SettingsRegistration;
use WpNcLinker\Config\SettingsSanitizer;
use WpNcLinker\Diagnostics\ConnectionTest;
use WpNcLinker\Nextcloud\Client;

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
	 * Encryption of the stored app password.
	 *
	 * @var Crypto|null
	 */
	private ?Crypto $crypto = null;

	/**
	 * Effective settings.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

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
		( new SettingsRegistration( $this->sanitizer() ) )->register();

		$connection_test = fn (): ConnectionTest => new ConnectionTest( $this->settings(), $this->client() );

		if ( is_admin() ) {
			$test_action = new ConnectionTestAction( $connection_test );
			$test_action->register();
			( new SettingsPage( $this->settings(), $test_action ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			( new Command( $connection_test ) )->register();
		}
	}

	/**
	 * Activation: creates the option with default values.
	 */
	public static function activate(): void {
		add_option( Settings::OPTION, Settings::defaults() );
	}

	/**
	 * Loads the bundled translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wp-nc-linker', false, dirname( plugin_basename( WPNCL_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Effective settings.
	 */
	public function settings(): Settings {
		return $this->settings ??= new Settings( new Constants(), $this->crypto() );
	}

	/**
	 * Client for the configured Nextcloud.
	 */
	public function client(): Client {
		return new Client( $this->settings() );
	}

	/**
	 * Encryption of the stored app password.
	 */
	private function crypto(): Crypto {
		return $this->crypto ??= Crypto::from_wordpress();
	}

	/**
	 * Sanitize callback of the settings option; WP-CLI (shell access) is always authorised.
	 */
	private function sanitizer(): SettingsSanitizer {
		return new SettingsSanitizer(
			$this->settings(),
			$this->crypto(),
			static fn (): bool => ( defined( 'WP_CLI' ) && WP_CLI ) || Capabilities::can_manage_settings()
		);
	}
}
