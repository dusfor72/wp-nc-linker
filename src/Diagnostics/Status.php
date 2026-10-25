<?php
/**
 * Result status of a diagnostic check.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of one check.
 */
enum Status: string {
	case Ok      = 'ok';
	case Warning = 'warning';
	case Error   = 'error';
	case Skipped = 'skipped';

	/**
	 * Translated label.
	 */
	public function label(): string {
		return match ( $this ) { // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Enum method; the sniff predates enums.
			self::Ok      => __( 'OK', 'wp-nc-linker' ),
			self::Warning => __( 'Warning', 'wp-nc-linker' ),
			self::Error   => __( 'Error', 'wp-nc-linker' ),
			self::Skipped => __( 'Skipped', 'wp-nc-linker' ),
		};
	}
}
