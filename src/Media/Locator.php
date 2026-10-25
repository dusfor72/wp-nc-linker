<?php
/**
 * Finding moved or renamed files.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Media;

use WpNcLinker\Browse\Area;
use WpNcLinker\Nextcloud\Client;
use WpNcLinker\Nextcloud\DavEntry;
use WpNcLinker\Nextcloud\DavPath;
use WpNcLinker\Nextcloud\InvalidResponseException;
use WpNcLinker\Nextcloud\MultiStatus;
use WpNcLinker\Nextcloud\NotConfiguredException;
use WpNcLinker\Nextcloud\TransportException;

defined( 'ABSPATH' ) || exit;

/**
 * Finds a file by its (stable) file ID after it was renamed or moved and updates the stored
 * source: in the file tree via SEARCH, in albums by the "{fileId}-" prefix of album entries.
 */
class Locator {

	/**
	 * Constructor.
	 *
	 * @param Client     $client     Nextcloud client.
	 * @param Repository $repository Attachment storage.
	 */
	public function __construct(
		private readonly Client $client,
		private readonly Repository $repository,
	) {
	}

	/**
	 * Finds the file and stores the new source.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param LinkedFile $file          Stored data.
	 * @return LinkedFile|null Updated data, or null if the file was not found.
	 */
	public function relocate( int $attachment_id, LinkedFile $file ): ?LinkedFile {
		try {
			$order = Area::Folders === $file->area
				? array( Area::Folders, Area::Albums, Area::SharedAlbums )
				: array( $file->area, Area::Albums === $file->area ? Area::SharedAlbums : Area::Albums, Area::Folders );

			foreach ( $order as $area ) {
				$path = Area::Folders === $area ? $this->in_file_tree( $file->file_id ) : $this->in_albums( $area, $file->file_id );
				if ( null !== $path ) {
					$moved = new LinkedFile(
						file_id: $file->file_id,
						area: $area,
						path: $path,
						mime: $file->mime,
						name: $file->name,
						size: $file->size,
						width: $file->width,
						height: $file->height,
						etag: $file->etag,
						modified: $file->modified,
						status: LinkedFile::STATUS_OK,
					);
					$this->repository->save( $attachment_id, $moved );
					return $moved;
				}
			}
		} catch ( TransportException | NotConfiguredException | InvalidResponseException | \InvalidArgumentException ) {
			return null;
		}

		return null;
	}

	/**
	 * Path of a file in the file tree, found by SEARCH.
	 *
	 * @param int $file_id File ID.
	 * @throws InvalidResponseException If the response is invalid.
	 */
	private function in_file_tree( int $file_id ): ?string {
		$response = $this->client->search_file_id( $file_id );
		if ( 207 !== $response->status ) {
			return null;
		}
		foreach ( MultiStatus::parse( $response->body ) as $entry ) {
			if ( $entry->file_id === $file_id && ! $entry->is_collection && $entry->download_allowed ) {
				$relative = DavPath::relative( $entry->href, DavPath::files( $this->client->user() ) );
				if ( null !== $relative && '' !== $relative ) {
					return $relative;
				}
			}
		}

		return null;
	}

	/**
	 * Path of a file in one of the albums of an album area.
	 *
	 * @param Area $area    Albums or SharedAlbums.
	 * @param int  $file_id File ID.
	 * @throws InvalidResponseException If a response is invalid.
	 */
	private function in_albums( Area $area, int $file_id ): ?string {
		$user     = $this->client->user();
		$response = $this->client->propfind( $area->dav_path( $user, '' ), 1 );
		if ( 207 !== $response->status ) {
			return null;
		}

		foreach ( MultiStatus::parse( $response->body ) as $album ) {
			if ( ! $album->is_collection || '' === (string) DavPath::relative( $album->href, $area->dav_path( $user, '' ) ) ) {
				continue;
			}
			$photos = $this->client->propfind( $area->dav_path( $user, $album->name() ), 1 );
			if ( 207 !== $photos->status ) {
				continue;
			}
			foreach ( MultiStatus::parse( $photos->body ) as $entry ) {
				if ( $this->matches( $entry, $file_id ) ) {
					return $album->name() . '/' . $entry->name();
				}
			}
		}

		return null;
	}

	/**
	 * Whether an album entry is the file.
	 *
	 * @param DavEntry $entry   Entry.
	 * @param int      $file_id File ID.
	 */
	private function matches( DavEntry $entry, int $file_id ): bool {
		return ! $entry->is_collection && str_starts_with( $entry->name(), $file_id . '-' );
	}
}
