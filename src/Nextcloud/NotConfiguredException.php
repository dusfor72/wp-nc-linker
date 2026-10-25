<?php
/**
 * Missing connection settings.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Nextcloud;

defined( 'ABSPATH' ) || exit;

/**
 * URL, user or app password are not configured (or invalid).
 */
final class NotConfiguredException extends \RuntimeException {
}
