<?php
/**
 * Binary body of a REST response.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Raw bytes that are sent as-is instead of JSON (see BrowseController::serve_binary()).
 */
final class BinaryBody {

	/**
	 * Constructor.
	 *
	 * @param string $bytes Body.
	 */
	public function __construct( public readonly string $bytes ) {
	}
}
