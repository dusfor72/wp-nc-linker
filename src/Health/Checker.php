<?php
/**
 * Availability check of linked files.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Health;

use WpNcLinker\Browse\Area;
use WpNcLinker\Media\LinkedFile;
use WpNcLinker\Media\Locator;
use WpNcLinker\Media\Repository;
use WpNcLinker\Nextcloud\Client;
use WpNcLinker\Nextcloud\DavEntry;
use WpNcLinker\Nextcloud\InvalidResponseException;
use WpNcLinker\Nextcloud\MultiStatus;
use WpNcLinker\Nextcloud\NotConfiguredException;
use WpNcLinker\Nextcloud\TransportException;

defined( 'ABSPATH' ) || exit;

/**
 * Checks linked files against Nextcloud (F7): files at their stored source are OK (changed
 * content updates the stored metadata), moved files are found again by file ID, deleted files,
 * revoked shares and shares without download permission are marked missing with a reason. If
 * Nextcloud is unreachable or the login fails, the run is aborted without changing any status.
 */
class Checker {

	/**
	 * Constructor.
	 *
	 * @param Client     $client     Nextcloud client.
	 * @param Repository $repository Attachment storage.
	 * @param Locator    $locator    Finds moved files.
	 */
	public function __construct(
		private readonly Client $client,
		private readonly Repository $repository,
		private readonly Locator $locator,
	) {
	}

	/**
	 * Checks attachments.
	 *
	 * @param list<int> $attachment_ids Attachments to check.
	 */
	public function check( array $attachment_ids ): CheckResult {
		$result = new CheckResult();

		foreach ( $attachment_ids as $attachment_id ) {
			$file = $this->repository->get( $attachment_id );
			if ( null === $file ) {
				continue;
			}

			try {
				$outcome = $this->check_one( $attachment_id, $file, $result );
			} catch ( TransportException | NotConfiguredException | UnavailableException $e ) {
				$result->aborted = $e instanceof UnavailableException ? $e->getMessage() : 'Nextcloud is not reachable.';
				return $result;
			}

			$result->outcomes[ $attachment_id ] = $outcome;
		}

		return $result;
	}

	/**
	 * Checks one attachment and stores its status (and the reason if it is missing).
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param LinkedFile  $file          Stored data.
	 * @param CheckResult $result        Receives the reason of a missing file.
	 * @return string CheckResult::OK, ::UPDATED, ::RELOCATED or ::MISSING.
	 * @throws TransportException If Nextcloud cannot be reached.
	 * @throws NotConfiguredException If the connection is not configured.
	 * @throws UnavailableException If the login fails or Nextcloud answers with a server error.
	 */
	private function check_one( int $attachment_id, LinkedFile $file, CheckResult $result ): string {
		try {
			$response = $this->client->propfind( $file->area->file_path( $this->client->user(), $file->path ), 0 );
		} catch ( \InvalidArgumentException ) {
			return $this->missing( $attachment_id, CheckResult::REASON_INVALID_PATH, $result );
		}

		self::assert_answered( $response->status );

		$reason = 403 === $response->status ? CheckResult::REASON_FORBIDDEN : CheckResult::REASON_NOT_FOUND;
		if ( 207 === $response->status ) {
			$entry  = $this->entry( $response->body );
			$reason = CheckResult::REASON_OTHER_FILE;
			if ( null !== $entry && ! $entry->is_collection && $entry->file_id === $file->file_id ) {
				if ( Area::Folders !== $file->area || $entry->download_allowed ) {
					return $this->refresh( $attachment_id, $file, $entry );
				}
				$reason = CheckResult::REASON_NO_DOWNLOAD;
			}
		}

		// Not at the stored source (or no longer downloadable there): look for it elsewhere.
		$moved = $this->locator->relocate( $attachment_id, $file );
		if ( null !== $moved ) {
			return CheckResult::RELOCATED;
		}

		return $this->missing( $attachment_id, $reason, $result );
	}

	/**
	 * Marks an attachment missing.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string      $reason        CheckResult::REASON_*.
	 * @param CheckResult $result        Receives the reason.
	 */
	private function missing( int $attachment_id, string $reason, CheckResult $result ): string {
		$this->repository->set_status( $attachment_id, LinkedFile::STATUS_MISSING, $reason );
		$result->reasons[ $attachment_id ] = $reason;

		return CheckResult::MISSING;
	}

	/**
	 * Marks an available file OK and takes over changed metadata (new content uploaded to the
	 * same file: same file ID, new ETag).
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param LinkedFile $file          Stored data.
	 * @param DavEntry   $entry         Current data from Nextcloud.
	 * @return string CheckResult::OK or ::UPDATED.
	 */
	private function refresh( int $attachment_id, LinkedFile $file, DavEntry $entry ): string {
		$etag = (string) $entry->etag;
		// Images need their new dimensions; without them (Nextcloud has not analysed the new
		// version yet, OP-9) the old data stays and the next check tries again.
		$dimensions_known = ! $file->is_sized_image() || ( null !== $entry->width && null !== $entry->height );
		if ( '' === $etag || $etag === $file->etag || ! $dimensions_known ) {
			$this->repository->set_status( $attachment_id, LinkedFile::STATUS_OK );
			return CheckResult::OK;
		}

		$this->repository->save(
			$attachment_id,
			new LinkedFile(
				file_id: $file->file_id,
				area: $file->area,
				path: $file->path,
				mime: $file->mime,
				name: $file->name,
				size: $entry->size ?? $file->size,
				width: $file->is_sized_image() ? $entry->width : $file->width,
				height: $file->is_sized_image() ? $entry->height : $file->height,
				etag: $etag,
				modified: $entry->last_modified ?? $file->modified,
				status: LinkedFile::STATUS_OK,
			)
		);

		return CheckResult::UPDATED;
	}

	/**
	 * Aborts the run if the answer says nothing about the file.
	 *
	 * @param int $status HTTP status of the PROPFIND.
	 * @throws UnavailableException If the login fails or Nextcloud answers unexpectedly.
	 */
	private static function assert_answered( int $status ): void {
		if ( 401 === $status ) {
			throw new UnavailableException( 'Login to Nextcloud failed.' );
		}
		if ( ! in_array( $status, array( 207, 403, 404 ), true ) ) {
			throw new UnavailableException( 'Unexpected answer from Nextcloud (HTTP ' . $status . ').' );
		}
	}

	/**
	 * First entry of a PROPFIND answer, or null.
	 *
	 * @param string $body Multistatus body.
	 */
	private function entry( string $body ): ?DavEntry {
		try {
			return MultiStatus::parse( $body )[0] ?? null;
		} catch ( InvalidResponseException ) {
			return null;
		}
	}
}
