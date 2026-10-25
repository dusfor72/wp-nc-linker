<?php
/**
 * Listings of folders and albums.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Browse;

use WpNcLinker\Config\Settings;
use WpNcLinker\Nextcloud\Client;
use WpNcLinker\Nextcloud\DavEntry;
use WpNcLinker\Nextcloud\DavPath;
use WpNcLinker\Nextcloud\InvalidResponseException;
use WpNcLinker\Nextcloud\MultiStatus;
use WpNcLinker\Nextcloud\NotConfiguredException;
use WpNcLinker\Nextcloud\TransportException;

defined( 'ABSPATH' ) || exit;

/**
 * Lists one level of the connection account's folders or albums. Listings are cached for a
 * short time (transient); only files with an allowed MIME type are returned.
 *
 * @phpstan-import-type ItemArray from Item
 */
class Browser {

	public const CACHE_TTL = 120;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Effective settings.
	 * @param Client   $client   Nextcloud client.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Client $client,
	) {
	}

	/**
	 * Lists a location.
	 *
	 * @param Area         $area    Area.
	 * @param string       $path    Path relative to the area root.
	 * @param list<string> $types   Optional type filter as used by the media library ("image", "application/pdf").
	 * @param bool         $refresh Bypass the cache.
	 * @return list<Item>
	 * @throws BrowseException If the location cannot be listed.
	 */
	public function list( Area $area, string $path, array $types = array(), bool $refresh = false ): array {
		$relative = self::normalize( $area, $path );
		$key      = $this->cache_key( $area, $relative );

		$cached = $refresh ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			$items = self::items_from_cache( $cached );
		} else {
			$items = $this->fetch( $area, $relative );
			set_transient( $key, array_map( static fn ( Item $item ): array => $item->to_array(), $items ), self::cache_ttl() );
		}

		$allowed = $this->settings->allowed_mime_types();
		$items   = array_values(
			array_filter(
				$items,
				static fn ( Item $item ): bool => Item::FILE !== $item->kind
					|| ( null !== $item->mime && in_array( $item->mime, $allowed, true ) && self::matches_types( $item->mime, $types ) )
			)
		);

		return $items;
	}

	/**
	 * Normalised relative path.
	 *
	 * @param Area   $area Area.
	 * @param string $path Raw path.
	 * @throws BrowseException If the path is not allowed.
	 */
	public static function normalize( Area $area, string $path ): string {
		try {
			$segments = DavPath::segments( $path );
		} catch ( \InvalidArgumentException ) {
			throw new BrowseException( 'Invalid path.', BrowseException::BAD_REQUEST );
		}
		if ( $area->is_album_area() && count( $segments ) > 1 ) {
			throw new BrowseException( 'Invalid album path.', BrowseException::BAD_REQUEST );
		}

		return implode( '/', $segments );
	}

	/**
	 * Normalised path of a file: at least one segment in folders, "album/{fileId}-{name}" in albums.
	 *
	 * @param Area   $area Area.
	 * @param string $path Raw path.
	 * @throws BrowseException If the path cannot denote a file.
	 */
	public static function normalize_file( Area $area, string $path ): string {
		try {
			$segments = DavPath::segments( $path );
		} catch ( \InvalidArgumentException ) {
			throw new BrowseException( 'Invalid path.', BrowseException::BAD_REQUEST );
		}

		$valid = $area->is_album_area()
			? 2 === count( $segments ) && 1 === preg_match( '/^\d+-./', $segments[1] )
			: array() !== $segments;
		if ( ! $valid ) {
			throw new BrowseException( 'Invalid file path.', BrowseException::BAD_REQUEST );
		}

		return implode( '/', $segments );
	}

	/**
	 * Cache lifetime of listings in seconds.
	 */
	private static function cache_ttl(): int {
		/**
		 * Filters how long folder and album listings are cached (seconds, 0 disables caching).
		 *
		 * @param int $seconds Default 120.
		 */
		$ttl = apply_filters( 'wpncl_browse_cache_ttl', self::CACHE_TTL );

		return is_int( $ttl ) && $ttl >= 0 && $ttl <= 3600 ? $ttl : self::CACHE_TTL;
	}

	/**
	 * Requests and converts a listing.
	 *
	 * @param Area   $area     Area.
	 * @param string $relative Normalised path.
	 * @return list<Item>
	 * @throws BrowseException If the location cannot be listed.
	 */
	private function fetch( Area $area, string $relative ): array {
		try {
			$path     = $area->dav_path( $this->client->user(), $relative );
			$response = $this->client->propfind( $path, 1 );
		} catch ( NotConfiguredException | TransportException ) {
			throw new BrowseException( 'Nextcloud not reachable.', BrowseException::UNAVAILABLE );
		} catch ( \InvalidArgumentException ) {
			throw new BrowseException( 'Invalid path.', BrowseException::BAD_REQUEST );
		}

		if ( 404 === $response->status ) {
			throw new BrowseException( 'Not found.', BrowseException::NOT_FOUND );
		}
		if ( 401 === $response->status || 403 === $response->status ) {
			throw new BrowseException( 'Login to Nextcloud failed.', BrowseException::UNAVAILABLE );
		}
		if ( 207 !== $response->status ) {
			throw new BrowseException( 'Unexpected answer from Nextcloud.', BrowseException::UPSTREAM );
		}

		try {
			$entries = MultiStatus::parse( $response->body );
		} catch ( InvalidResponseException ) {
			throw new BrowseException( 'Invalid answer from Nextcloud.', BrowseException::UPSTREAM );
		}

		$items = array();
		foreach ( $entries as $entry ) {
			if ( '' === DavPath::relative( $entry->href, $path ) ) {
				continue;
			}
			$item = $this->to_item( $area, $relative, $entry );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		usort( $items, array( self::class, 'compare' ) );

		return $items;
	}

	/**
	 * Converts an entry of the listing.
	 *
	 * @param Area     $area     Area.
	 * @param string   $relative Listed path.
	 * @param DavEntry $entry    Entry.
	 */
	private function to_item( Area $area, string $relative, DavEntry $entry ): ?Item {
		$child = ( '' === $relative ? '' : $relative . '/' ) . $entry->name();

		if ( $entry->is_collection ) {
			if ( Area::Folders === $area ) {
				return new Item( Item::FOLDER, $entry->name(), $child, modified: $entry->last_modified );
			}
			return '' === $relative
				? new Item( Item::ALBUM, $entry->label(), $child, count: $entry->item_count )
				: null;
		}

		if ( null === $entry->file_id || null === $entry->content_type ) {
			return null;
		}

		$selectable = Area::Folders !== $area || $entry->download_allowed;

		return new Item(
			kind: Item::FILE,
			name: $entry->label(),
			path: $child,
			file_id: $entry->file_id,
			mime: strtolower( $entry->content_type ),
			size: $entry->size,
			modified: $entry->last_modified,
			width: $entry->width,
			height: $entry->height,
			preview: true === $entry->has_preview,
			selectable: $selectable,
			reason: $selectable ? null : Item::REASON_DOWNLOAD_RESTRICTED,
		);
	}

	/**
	 * Restores items from the cache.
	 *
	 * @param array<mixed> $cached Cached arrays.
	 * @return list<Item>
	 */
	private static function items_from_cache( array $cached ): array {
		$items = array();
		foreach ( $cached as $data ) {
			if ( ! is_array( $data ) || ! is_string( $data['kind'] ?? null ) || ! is_string( $data['name'] ?? null ) || ! is_string( $data['path'] ?? null ) ) {
				continue;
			}
			$items[] = new Item(
				kind: $data['kind'],
				name: $data['name'],
				path: $data['path'],
				file_id: is_int( $data['file_id'] ?? null ) ? $data['file_id'] : null,
				mime: is_string( $data['mime'] ?? null ) ? $data['mime'] : null,
				size: is_int( $data['size'] ?? null ) ? $data['size'] : null,
				modified: is_int( $data['modified'] ?? null ) ? $data['modified'] : null,
				width: is_int( $data['width'] ?? null ) ? $data['width'] : null,
				height: is_int( $data['height'] ?? null ) ? $data['height'] : null,
				count: is_int( $data['count'] ?? null ) ? $data['count'] : null,
				preview: true === ( $data['preview'] ?? false ),
				selectable: true === ( $data['selectable'] ?? false ),
				reason: is_string( $data['reason'] ?? null ) ? $data['reason'] : null,
			);
		}

		return $items;
	}

	/**
	 * Whether a MIME type matches the media library type filter.
	 *
	 * @param string       $mime  MIME type.
	 * @param list<string> $types Filter ("image", "application/pdf", …); empty = all.
	 */
	private static function matches_types( string $mime, array $types ): bool {
		if ( array() === $types ) {
			return true;
		}
		foreach ( $types as $type ) {
			if ( $mime === $type || str_starts_with( $mime, $type . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sort order: folders and albums first, then by name (natural, case-insensitive).
	 *
	 * @param Item $a First item.
	 * @param Item $b Second item.
	 */
	private static function compare( Item $a, Item $b ): int {
		$a_container = Item::FILE !== $a->kind;
		$b_container = Item::FILE !== $b->kind;
		if ( $a_container !== $b_container ) {
			return $a_container ? -1 : 1;
		}

		return strnatcasecmp( $a->name, $b->name );
	}

	/**
	 * Transient key of a listing.
	 *
	 * @param Area   $area     Area.
	 * @param string $relative Normalised path.
	 */
	private function cache_key( Area $area, string $relative ): string {
		return 'wpncl_browse_' . md5( (string) wp_json_encode( array( $this->settings->url(), $this->settings->user(), $area->value, $relative ) ) );
	}
}
