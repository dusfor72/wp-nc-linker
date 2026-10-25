<?php
/**
 * WebDAV paths of the connection account.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Nextcloud;

defined( 'ABSPATH' ) || exit;

/**
 * Builds URL-encoded WebDAV paths below the collections of the connection account. Relative
 * paths are normalised; "." and ".." segments and control characters are rejected, so a path
 * can never leave the account's collections.
 */
final class DavPath {

	public const ALBUMS        = 'albums';
	public const SHARED_ALBUMS = 'sharedalbums';

	private const ROOT = '/remote.php/dav/';

	/**
	 * Path of the WebDAV root (used for SEARCH).
	 */
	public static function root(): string {
		return self::ROOT;
	}

	/**
	 * Path in the file tree of a user.
	 *
	 * @param string $user     User ID.
	 * @param string $relative Path relative to the user's root ("" for the root).
	 * @throws \InvalidArgumentException If the path is not allowed.
	 */
	public static function files( string $user, string $relative = '' ): string {
		return self::ROOT . 'files/' . self::encode_segment( $user ) . '/' . self::encode( $relative );
	}

	/**
	 * Path in the photos collections (albums or shared albums) of a user.
	 *
	 * @param string $user       User ID.
	 * @param string $collection self::ALBUMS or self::SHARED_ALBUMS.
	 * @param string $relative   Album name, optionally followed by "/{fileId}-{name}".
	 * @throws \InvalidArgumentException If the collection or path is not allowed.
	 */
	public static function photos( string $user, string $collection, string $relative = '' ): string {
		if ( self::ALBUMS !== $collection && self::SHARED_ALBUMS !== $collection ) {
			throw new \InvalidArgumentException( 'Unknown photos collection.' );
		}

		return self::ROOT . 'photos/' . self::encode_segment( $user ) . '/' . $collection . '/' . self::encode( $relative );
	}

	/**
	 * Href used in SEARCH scopes (without the /remote.php/dav prefix).
	 *
	 * @param string $user User ID.
	 */
	public static function search_scope( string $user ): string {
		return '/files/' . self::encode_segment( $user );
	}

	/**
	 * Path of an href relative to a collection, independent of the base path of the Nextcloud
	 * installation (hrefs include e.g. "/nextcloud" when Nextcloud runs in a sub-directory).
	 *
	 * @param string $href       Href from a multistatus response.
	 * @param string $collection Collection path from this class (e.g. self::files( $user )).
	 * @return string|null Decoded relative path ("" for the collection itself), null if outside.
	 */
	public static function relative( string $href, string $collection ): ?string {
		$decoded_href       = rtrim( rawurldecode( $href ), '/' ) . '/';
		$decoded_collection = rtrim( rawurldecode( $collection ), '/' ) . '/';
		$position           = strpos( $decoded_href, $decoded_collection );
		if ( false === $position ) {
			return null;
		}

		return trim( substr( $decoded_href, $position + strlen( $decoded_collection ) ), '/' );
	}

	/**
	 * Normalises a relative path into its segments.
	 *
	 * @param string $relative Relative path.
	 * @return list<string>
	 * @throws \InvalidArgumentException If the path contains ".", ".." or control characters.
	 */
	public static function segments( string $relative ): array {
		$segments = array();
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			if ( '.' === $segment || '..' === $segment || preg_match( '/[\x00-\x1F\x7F\\\\]/', $segment ) ) {
				throw new \InvalidArgumentException( 'Path segment not allowed.' );
			}
			$segments[] = $segment;
		}

		return $segments;
	}

	/**
	 * URL-encodes a relative path; directories keep their trailing slash.
	 *
	 * @param string $relative Relative path.
	 * @throws \InvalidArgumentException If the path is not allowed.
	 */
	private static function encode( string $relative ): string {
		$segments = self::segments( $relative );
		if ( array() === $segments ) {
			return '';
		}
		$encoded = implode( '/', array_map( array( self::class, 'encode_segment' ), $segments ) );

		return str_ends_with( $relative, '/' ) ? $encoded . '/' : $encoded;
	}

	/**
	 * URL-encodes one segment.
	 *
	 * @param string $segment Segment.
	 * @throws \InvalidArgumentException If the segment is empty or contains a slash.
	 */
	private static function encode_segment( string $segment ): string {
		if ( '' === $segment || str_contains( $segment, '/' ) ) {
			throw new \InvalidArgumentException( 'Invalid path segment.' );
		}

		return rawurlencode( $segment );
	}
}
