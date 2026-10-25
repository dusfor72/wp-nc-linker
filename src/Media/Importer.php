<?php
/**
 * Linking of Nextcloud files as attachments.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Media;

use WpNcLinker\Browse\Area;
use WpNcLinker\Browse\BrowseException;
use WpNcLinker\Browse\Browser;
use WpNcLinker\Config\Settings;
use WpNcLinker\Nextcloud\Client;
use WpNcLinker\Nextcloud\DavEntry;
use WpNcLinker\Nextcloud\InvalidResponseException;
use WpNcLinker\Nextcloud\MultiStatus;
use WpNcLinker\Nextcloud\NotConfiguredException;
use WpNcLinker\Nextcloud\TransportException;

defined( 'ABSPATH' ) || exit;

/**
 * Creates (or reuses) a virtual attachment for a file selected in the media dialog. The file is
 * looked up again on the server; nothing from the client except area and path is trusted.
 */
final class Importer {

	public const MAX_ITEMS = 100;

	private const HEAD_BYTES = 262144;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings   Effective settings.
	 * @param Client     $client     Nextcloud client.
	 * @param Repository $repository Attachment storage.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Client $client,
		private readonly Repository $repository,
	) {
	}

	/**
	 * Links one file and returns the attachment ID (existing attachments are reused).
	 *
	 * @param string $area_name Area ("folders", "albums", "sharedalbums").
	 * @param string $path      Path of the file relative to the area root.
	 * @param int    $parent_id Post to attach new attachments to.
	 * @throws ImportException If the file cannot be linked.
	 */
	public function import( string $area_name, string $path, int $parent_id ): int {
		$area = Area::tryFrom( $area_name ) ?? throw new ImportException( ImportException::INVALID );
		$path = self::normalize( $area, $path );

		$entry = $this->lookup( $area, $path );
		if ( $entry->is_collection || null === $entry->file_id || null === $entry->content_type ) {
			throw new ImportException( ImportException::INVALID );
		}
		$mime = strtolower( $entry->content_type );
		if ( ! in_array( $mime, $this->settings->allowed_mime_types(), true ) ) {
			throw new ImportException( ImportException::TYPE );
		}
		if ( Area::Folders === $area && ! $entry->download_allowed ) {
			throw new ImportException( ImportException::RESTRICTED );
		}

		[ $width, $height ] = $this->dimensions( $area, $path, $entry, $mime );

		$file = new LinkedFile(
			file_id: $entry->file_id,
			area: $area,
			path: $path,
			mime: $mime,
			name: $entry->label(),
			size: $entry->size,
			width: $width,
			height: $height,
			etag: (string) $entry->etag,
			modified: $entry->last_modified,
		);

		$existing = $this->repository->find_by_file_id( $entry->file_id );
		if ( null !== $existing ) {
			$this->repository->save( $existing, $file );
			return $existing;
		}

		try {
			return $this->repository->create( $file, $parent_id );
		} catch ( \RuntimeException ) {
			throw new ImportException( ImportException::UPSTREAM );
		}
	}

	/**
	 * Validates and normalises the path of a file.
	 *
	 * @param Area   $area Area.
	 * @param string $path Raw path.
	 * @throws ImportException If the path does not denote a file of the area.
	 */
	private static function normalize( Area $area, string $path ): string {
		try {
			return Browser::normalize_file( $area, $path );
		} catch ( BrowseException ) {
			throw new ImportException( ImportException::INVALID );
		}
	}

	/**
	 * Current properties of the file.
	 *
	 * @param Area   $area Area.
	 * @param string $path Normalised path.
	 * @throws ImportException If the file cannot be read.
	 */
	private function lookup( Area $area, string $path ): DavEntry {
		try {
			$response = $this->client->propfind( $area->file_path( $this->client->user(), $path ), 0 );
		} catch ( NotConfiguredException | TransportException ) {
			throw new ImportException( ImportException::UNAVAILABLE );
		} catch ( \InvalidArgumentException ) {
			throw new ImportException( ImportException::INVALID );
		}

		if ( 404 === $response->status ) {
			throw new ImportException( ImportException::NOT_FOUND );
		}
		if ( 401 === $response->status || 403 === $response->status ) {
			throw new ImportException( ImportException::UNAVAILABLE );
		}
		if ( 207 !== $response->status ) {
			throw new ImportException( ImportException::UPSTREAM );
		}

		try {
			$entries = MultiStatus::parse( $response->body );
		} catch ( InvalidResponseException ) {
			throw new ImportException( ImportException::UPSTREAM );
		}

		return $entries[0] ?? throw new ImportException( ImportException::UPSTREAM );
	}

	/**
	 * Image dimensions: Nextcloud metadata, otherwise read from the first bytes of the original (OP-9).
	 *
	 * @param Area     $area  Area.
	 * @param string   $path  Normalised path.
	 * @param DavEntry $entry File properties.
	 * @param string   $mime  MIME type.
	 * @return array{0: int|null, 1: int|null}
	 */
	private function dimensions( Area $area, string $path, DavEntry $entry, string $mime ): array {
		if ( ! str_starts_with( $mime, 'image/' ) ) {
			return array( null, null );
		}
		if ( null !== $entry->width && null !== $entry->height ) {
			return array( $entry->width, $entry->height );
		}

		try {
			$response = $this->client->head_bytes( $area->file_path( $this->client->user(), $path ), self::HEAD_BYTES );
		} catch ( TransportException | NotConfiguredException | \InvalidArgumentException ) {
			return array( null, null );
		}
		if ( 206 !== $response->status && 200 !== $response->status ) {
			return array( null, null );
		}

		$size = @getimagesizefromstring( $response->body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Partial data may be incomplete; failure is handled.
		if ( ! is_array( $size ) || $size[0] <= 0 || $size[1] <= 0 ) {
			return array( null, null );
		}

		return array( (int) $size[0], (int) $size[1] );
	}
}
