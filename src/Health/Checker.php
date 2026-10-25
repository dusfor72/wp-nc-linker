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
use WpNcLinker\Nextcloud\InvalidResponseException;
use WpNcLinker\Nextcloud\MultiStatus;
use WpNcLinker\Nextcloud\NotConfiguredException;
use WpNcLinker\Nextcloud\TransportException;

defined( 'ABSPATH' ) || exit;

/**
 * Checks linked files against Nextcloud (F7): files at their stored source are OK, moved files
 * are found again by file ID, deleted files, revoked shares and shares without download
 * permission are marked missing. If Nextcloud is unreachable or the login fails, the run is
 * aborted without changing any status.
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
				$outcome = $this->check_one( $attachment_id, $file );
			} catch ( TransportException | NotConfiguredException | UnavailableException $e ) {
				$result->aborted = $e instanceof UnavailableException ? $e->getMessage() : 'Nextcloud is not reachable.';
				return $result;
			}

			$result->outcomes[ $attachment_id ] = $outcome;
		}

		return $result;
	}

	/**
	 * Checks one attachment and stores its status.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param LinkedFile $file          Stored data.
	 * @return string CheckResult::OK, ::RELOCATED or ::MISSING.
	 * @throws TransportException If Nextcloud cannot be reached.
	 * @throws NotConfiguredException If the connection is not configured.
	 * @throws UnavailableException If the login fails or Nextcloud answers with a server error.
	 */
	private function check_one( int $attachment_id, LinkedFile $file ): string {
		try {
			$response = $this->client->propfind( $file->area->file_path( $this->client->user(), $file->path ), 0 );
		} catch ( \InvalidArgumentException ) {
			$this->repository->set_status( $attachment_id, LinkedFile::STATUS_MISSING );
			return CheckResult::MISSING;
		}

		self::assert_answered( $response->status );

		if ( 207 === $response->status && $this->is_available( $response->body, $file ) ) {
			$this->repository->set_status( $attachment_id, LinkedFile::STATUS_OK );
			return CheckResult::OK;
		}

		// Not at the stored source (or no longer downloadable there): look for it elsewhere.
		$moved = $this->locator->relocate( $attachment_id, $file );
		if ( null !== $moved ) {
			return CheckResult::RELOCATED;
		}

		$this->repository->set_status( $attachment_id, LinkedFile::STATUS_MISSING );
		return CheckResult::MISSING;
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
	 * Whether the PROPFIND answer shows the same, downloadable file.
	 *
	 * @param string     $body Multistatus body.
	 * @param LinkedFile $file Stored data.
	 */
	private function is_available( string $body, LinkedFile $file ): bool {
		try {
			$entry = MultiStatus::parse( $body )[0] ?? null;
		} catch ( InvalidResponseException ) {
			return false;
		}

		return null !== $entry
			&& ! $entry->is_collection
			&& $entry->file_id === $file->file_id
			&& ( Area::Folders !== $file->area || $entry->download_allowed );
	}
}
