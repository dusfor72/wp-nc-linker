<?php
/**
 * Placeholder for images that cannot be delivered.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Proxy;

defined( 'ABSPATH' ) || exit;

/**
 * Neutral SVG (static markup, no script) in the size of the requested image.
 */
final class Placeholder {

	/**
	 * SVG markup.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 */
	public static function svg( int $width, int $height ): string {
		$width  = max( 1, min( 10000, $width ) );
		$height = max( 1, min( 10000, $height ) );
		$scale  = max( 0.2, min( 4.0, min( $width, $height ) / 160 ) );
		$x      = round( $width / 2 - 24 * $scale, 1 );
		$y      = round( $height / 2 - 18 * $scale, 1 );

		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
			. '<rect width="100%" height="100%" fill="#f0f0f1"/>'
			. '<g transform="translate(' . $x . ' ' . $y . ') scale(' . round( $scale, 3 ) . ')" fill="none" stroke="#a7aaad" stroke-width="2" stroke-linejoin="round">'
			. '<rect x="1" y="1" width="46" height="34" rx="3"/><circle cx="14" cy="12" r="4"/><path d="M4 32l12-12 8 8 6-6 14 10"/>'
			. '</g></svg>';
	}
}
