<?php
/**
 * Unparseable response from Nextcloud.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Nextcloud;

defined( 'ABSPATH' ) || exit;

/**
 * Nextcloud answered, but the body is not what the protocol requires.
 */
final class InvalidResponseException extends \RuntimeException {
}
