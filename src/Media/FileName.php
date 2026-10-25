<?php
/**
 * URL-safe file names.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Media;

defined( 'ABSPATH' ) || exit;

/**
 * File names used in proxy URLs and attachment metadata. They contain only [A-Za-z0-9._-], so
 * the URL path equals the stored name (WordPress matches srcset candidates by basename).
 */
final class FileName {

	/**
	 * ASCII slug of a file name, keeping the extension.
	 *
	 * @param string $name Original name.
	 */
	public static function slug( string $name ): string {
		$name      = remove_accents( $name );
		$extension = strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', pathinfo( $name, PATHINFO_EXTENSION ) ) );
		$stem      = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', pathinfo( $name, PATHINFO_FILENAME ) );
		$stem      = trim( (string) preg_replace( '/-{2,}/', '-', $stem ), '-.' );
		if ( '' === $stem ) {
			$stem = 'file';
		}
		$stem = substr( $stem, 0, 100 );

		return '' === $extension ? $stem : $stem . '.' . substr( $extension, 0, 10 );
	}

	/**
	 * Name of a resized variant ("name-WxH.ext").
	 *
	 * @param string $slug   Slug from slug().
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 */
	public static function sized( string $slug, int $width, int $height ): string {
		$extension = pathinfo( $slug, PATHINFO_EXTENSION );
		$stem      = pathinfo( $slug, PATHINFO_FILENAME );

		return $stem . '-' . $width . 'x' . $height . ( '' === $extension ? '' : '.' . $extension );
	}

	/**
	 * Title for the media library (name without extension).
	 *
	 * @param string $name Original name.
	 */
	public static function title( string $name ): string {
		$title = pathinfo( $name, PATHINFO_FILENAME );

		return '' === $title ? $name : $title;
	}
}
