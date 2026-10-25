<?php
/**
 * Dimensions of the previews Nextcloud delivers.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Predicts the size of a Nextcloud preview for a requested bounding box, so WordPress gets the
 * real width and height of each image size (D-1, D-2).
 *
 * Behaviour of Nextcloud 34 (observed and verified against the test environment): the preview
 * keeps the aspect ratio and fits into the box; unless one requested edge equals the largest
 * preview exactly, both edges are rounded up to the next power of four (minimum 64) and the
 * result is scaled to the edge that is filled less; it never exceeds the largest preview, which
 * is the original fitted into preview_max_x × preview_max_y (default 4096) without upscaling.
 */
final class SizeCalculator {

	public const DEFAULT_PREVIEW_MAX = 4096;

	private const MIN_STEP = 64;

	/**
	 * Constructor.
	 *
	 * @param int $preview_max Nextcloud preview_max_x/preview_max_y.
	 */
	public function __construct( private readonly int $preview_max = self::DEFAULT_PREVIEW_MAX ) {
	}

	/**
	 * Calculator with the filtered preview maximum.
	 */
	public static function create(): self {
		/**
		 * Filters the largest preview edge of the Nextcloud server (preview_max_x/preview_max_y).
		 *
		 * @param int $pixels Default 4096.
		 */
		$max = apply_filters( 'wpncl_preview_max_size', self::DEFAULT_PREVIEW_MAX );

		return new self( is_int( $max ) && $max >= self::MIN_STEP ? $max : self::DEFAULT_PREVIEW_MAX );
	}

	/**
	 * Size of the largest preview of an original.
	 *
	 * @param int $width  Original width.
	 * @param int $height Original height.
	 * @return array{0: int, 1: int}
	 */
	public function largest_preview( int $width, int $height ): array {
		$scale = min( 1.0, $this->preview_max / max( 1, $width ), $this->preview_max / max( 1, $height ) );

		return array( max( 1, (int) round( $width * $scale ) ), max( 1, (int) round( $height * $scale ) ) );
	}

	/**
	 * Size of the preview delivered for a requested box.
	 *
	 * @param int $width      Original width.
	 * @param int $height     Original height.
	 * @param int $box_width  Requested width (x).
	 * @param int $box_height Requested height (y).
	 * @return array{0: int, 1: int}
	 */
	public function delivered( int $width, int $height, int $box_width, int $box_height ): array {
		[ $max_width, $max_height ] = $this->largest_preview( $width, $height );
		$box_width                  = max( 1, $box_width );
		$box_height                 = max( 1, $box_height );

		// Fit into the box: the edge that is relatively larger stays, the other follows the ratio.
		$aspect = $max_height / $max_width;
		if ( $box_height / $max_height > $box_width / $max_width ) {
			$kept_is_width = true;
			$w             = (float) $box_width;
			$h             = $box_width * $aspect;
		} else {
			$kept_is_width = false;
			$h             = (float) $box_height;
			$w             = $box_height / $aspect;
		}

		$kept_matches_max = $kept_is_width ? $box_width === $max_width : $box_height === $max_height;
		if ( ! $kept_matches_max ) {
			$step_width  = self::step( $w );
			$step_height = self::step( $h );
			$fill_width  = $w / $step_width;
			$fill_height = $h / $step_height;
			if ( $fill_height < $fill_width ) {
				$h = $h / $fill_width;
				$w = (float) $step_width;
			} else {
				$w = $w / $fill_height;
				$h = (float) $step_height;
			}
		}

		if ( $h > $max_height ) {
			$w = $w * $max_height / $h;
			$h = (float) $max_height;
		}
		if ( $w > $max_width ) {
			$h = $h * $max_width / $w;
			$w = (float) $max_width;
		}

		return array( (int) round( $w ), (int) round( $h ) );
	}

	/**
	 * Smallest power of four that is at least the value (minimum 64).
	 *
	 * @param float $value Edge length.
	 */
	private static function step( float $value ): int {
		$step = self::MIN_STEP;
		while ( $step < $value ) {
			$step *= 4;
		}

		return $step;
	}
}
