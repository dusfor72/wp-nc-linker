<?php
/**
 * Registration of the settings option.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the option with its sanitize callback in every context (admin, front end, REST,
 * WP-CLI, cron), so every add_option()/update_option() is sanitised and the app password is
 * never stored in plain text.
 */
final class SettingsRegistration {

	public const GROUP = 'wpncl';

	/**
	 * Constructor.
	 *
	 * @param SettingsSanitizer $sanitizer Sanitize callback.
	 */
	public function __construct( private readonly SettingsSanitizer $sanitizer ) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_setting' ), 0 );
	}

	/**
	 * Registers the option.
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->sanitizer, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => Settings::defaults(),
			)
		);
	}
}
