<?php
/**
 * Nextcloud cannot answer reliably.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Login failure or server error: a check must not mark files as missing in this state.
 */
final class UnavailableException extends \RuntimeException {
}
