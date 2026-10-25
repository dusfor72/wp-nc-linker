<?php
/**
 * Failure while browsing Nextcloud.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Browse;

defined( 'ABSPATH' ) || exit;

/**
 * Listing failed. The code is an HTTP status suitable for the REST response.
 */
final class BrowseException extends \RuntimeException {

	public const NOT_FOUND   = 404;
	public const BAD_REQUEST = 400;
	public const UPSTREAM    = 502;
	public const UNAVAILABLE = 503;
}
