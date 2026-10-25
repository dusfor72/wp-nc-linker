<?php
/**
 * Areas of the Nextcloud browser.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Browse;

use WpNcLinker\Nextcloud\DavPath;

defined( 'ABSPATH' ) || exit;

/**
 * Folders, own albums and shared albums of the connection account.
 */
enum Area: string {
	case Folders      = 'folders';
	case Albums       = 'albums';
	case SharedAlbums = 'sharedalbums';

	/**
	 * WebDAV path of a location in this area.
	 *
	 * @param string $user     User ID.
	 * @param string $relative Normalised relative path ("" for the root).
	 * @throws \InvalidArgumentException If the path is not allowed.
	 */
	public function dav_path( string $user, string $relative ): string {
		$directory = '' === $relative ? '' : $relative . '/';

		return match ( $this ) {
			self::Folders      => DavPath::files( $user, $directory ),
			self::Albums       => DavPath::photos( $user, DavPath::ALBUMS, $directory ),
			self::SharedAlbums => DavPath::photos( $user, DavPath::SHARED_ALBUMS, $directory ),
		};
	}

	/**
	 * WebDAV path of a file in this area.
	 *
	 * @param string $user     User ID.
	 * @param string $relative Normalised relative path of the file.
	 * @throws \InvalidArgumentException If the path is not allowed.
	 */
	public function file_path( string $user, string $relative ): string {
		return match ( $this ) {
			self::Folders      => DavPath::files( $user, $relative ),
			self::Albums       => DavPath::photos( $user, DavPath::ALBUMS, $relative ),
			self::SharedAlbums => DavPath::photos( $user, DavPath::SHARED_ALBUMS, $relative ),
		};
	}

	/**
	 * Whether this area is an album collection.
	 */
	public function is_album_area(): bool {
		return self::Folders !== $this;
	}
}
