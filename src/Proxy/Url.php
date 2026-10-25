<?php
/**
 * Public URLs of linked files.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Proxy;

use WpNcLinker\Config\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds /{prefix}/{attachment ID}/{variant}/{file name} (pretty permalinks) or
 * /index.php/{prefix}/… (other permalink settings). The variant is "full" (original) or the
 * delivered dimensions "{width}x{height}", so all image sizes with the same dimensions share one
 * URL. The file name is always the last path segment, so WordPress can match srcset candidates by
 * basename (D-5).
 */
class Url {

	public const FULL = 'full';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Effective settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * URL of a variant of an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $variant       self::FULL or self::dimensions().
	 * @param string $file          File name (from FileName).
	 */
	public function build( int $attachment_id, string $variant, string $file ): string {
		$path = $this->settings->proxy_prefix() . '/' . $attachment_id . '/' . rawurlencode( $variant ) . '/' . rawurlencode( $file );

		return home_url( ( self::pretty() ? '/' : '/index.php/' ) . $path );
	}

	/**
	 * Variant segment of a resized image.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 */
	public static function dimensions( int $width, int $height ): string {
		return $width . 'x' . $height;
	}

	/**
	 * Path below the site root that proxy requests start with (without leading slash).
	 */
	public function path_prefix(): string {
		return $this->settings->proxy_prefix() . '/';
	}

	/**
	 * Whether pretty permalinks without index.php are active.
	 */
	private static function pretty(): bool {
		global $wp_rewrite;

		return $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks() && ! $wp_rewrite->using_index_permalinks();
	}
}
