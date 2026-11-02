<?php
/**
 * Tests for the availability check.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Health;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WpNcLinker\Browse\Area;
use WpNcLinker\Health\Checker;
use WpNcLinker\Health\CheckResult;
use WpNcLinker\Media\LinkedFile;
use WpNcLinker\Media\Locator;
use WpNcLinker\Nextcloud\Response;
use WpNcLinker\Nextcloud\TransportException;
use WpNcLinker\Tests\Unit\Diagnostics\FakeClient;
use WpNcLinker\Tests\Unit\Media\FakeRepository;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Outcomes per file and aborted runs.
 */
#[CoversClass( Checker::class )]
#[CoversClass( CheckResult::class )]
final class CheckerTest extends TestCase {

	private const SOURCE = 'PROPFIND /remote.php/dav/files/linker/Photos/forest.jpg 0';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'remove_accents' )->returnArg();
	}

	/**
	 * Multistatus with one file.
	 *
	 * @param string $href     Href.
	 * @param int    $file_id  File ID.
	 * @param bool   $download Download allowed.
	 * @param string $extra    Further properties (XML).
	 */
	private static function found( string $href, int $file_id, bool $download = true, string $extra = '' ): Response {
		return new Response(
			207,
			array(),
			'<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
				. '<d:response><d:href>' . $href . '</d:href><d:propstat><d:prop><d:resourcetype/><oc:fileid>' . $file_id . '</oc:fileid>' . $extra
				. '<nc:share-attributes>' . ( $download ? '[]' : '[{"scope":"permissions","key":"download","value":false}]' ) . '</nc:share-attributes>'
				. '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>'
		);
	}

	/**
	 * Checks attachment 7 (folder file 102) with the given answers.
	 *
	 * @param array<string, Response|\Throwable> $answers    Answers of the fake client.
	 * @param FakeRepository|null                $repository Receives the repository.
	 * @param-out FakeRepository                  $repository
	 */
	private static function check( array $answers, ?FakeRepository &$repository = null ): CheckResult {
		$client               = new FakeClient( $answers );
		$repository           = new FakeRepository();
		$repository->files[7] = new LinkedFile( 102, Area::Folders, 'Photos/forest.jpg', 'image/jpeg', 'forest.jpg', 1000, 2400, 1600, 'e1' );

		return ( new Checker( $client, $repository, new Locator( $client, $repository ) ) )->check( array( 7, 8 ) );
	}

	public function test_file_at_its_source_is_ok(): void {
		$repository = null;
		$result     = self::check( array( self::SOURCE => self::found( '/remote.php/dav/files/linker/Photos/forest.jpg', 102 ) ), $repository );

		$this->assertSame( array( 7 => CheckResult::OK ), $result->outcomes );
		$this->assertNull( $result->aborted );
		$this->assertNotNull( $repository );
		$this->assertSame( array( 7 => LinkedFile::STATUS_OK ), $repository->statuses );
	}

	public function test_moved_file_is_relocated(): void {
		$repository = null;
		$result     = self::check(
			array(
				self::SOURCE   => new Response( 404, array(), '' ),
				'SEARCHID 102' => self::found( '/remote.php/dav/files/linker/Archive/forest.jpg', 102 ),
			),
			$repository
		);

		$this->assertSame( 1, $result->count( CheckResult::RELOCATED ) );
		$this->assertNotNull( $repository );
		$this->assertSame( 'Archive/forest.jpg', $repository->saved[7]->path );
		$this->assertSame( LinkedFile::STATUS_OK, $repository->saved[7]->status );
	}

	/**
	 * Answers at the stored source that mean "missing" when the file is not found elsewhere.
	 *
	 * @return array<string, array{Response, string}>
	 */
	public static function missing_answers(): array {
		return array(
			'deleted or share revoked' => array( new Response( 404, array(), '' ), CheckResult::REASON_NOT_FOUND ),
			'forbidden'                => array( new Response( 403, array(), '' ), CheckResult::REASON_FORBIDDEN ),
			'other file at the path'   => array( self::found( '/remote.php/dav/files/linker/Photos/forest.jpg', 999 ), CheckResult::REASON_OTHER_FILE ),
			'download not allowed'     => array( self::found( '/remote.php/dav/files/linker/Photos/forest.jpg', 102, false ), CheckResult::REASON_NO_DOWNLOAD ),
		);
	}

	/**
	 * Missing files and their reason.
	 *
	 * @param Response $answer Answer at the source.
	 * @param string   $reason Expected reason.
	 */
	#[DataProvider( 'missing_answers' )]
	public function test_missing_file( Response $answer, string $reason ): void {
		$repository = null;
		$result     = self::check(
			array(
				self::SOURCE   => $answer,
				'SEARCHID 102' => new Response( 207, array(), '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"/>' ),
			),
			$repository
		);

		$this->assertSame( array( 7 => CheckResult::MISSING ), $result->outcomes );
		$this->assertSame( array( 7 => $reason ), $result->reasons );
		$this->assertNotNull( $repository );
		$this->assertSame( array( 7 => LinkedFile::STATUS_MISSING ), $repository->statuses );
		$this->assertSame( array( 7 => $reason ), $repository->reasons );
	}

	public function test_changed_content_updates_the_stored_data(): void {
		$repository = null;
		$changed    = '<d:getetag>"e2"</d:getetag><d:getcontentlength>2000</d:getcontentlength>'
			. '<nc:metadata-photos-size><width>1200</width><height>900</height></nc:metadata-photos-size>';
		$result     = self::check( array( self::SOURCE => self::found( '/remote.php/dav/files/linker/Photos/forest.jpg', 102, true, $changed ) ), $repository );

		$this->assertSame( array( 7 => CheckResult::UPDATED ), $result->outcomes );
		$this->assertNotNull( $repository );
		$saved = $repository->saved[7];
		$this->assertSame( array( 'e2', 2000, 1200, 900, 'Photos/forest.jpg' ), array( $saved->etag, $saved->size, $saved->width, $saved->height, $saved->path ) );
	}

	public function test_changed_image_without_known_dimensions_is_checked_again_later(): void {
		$repository = null;
		$result     = self::check( array( self::SOURCE => self::found( '/remote.php/dav/files/linker/Photos/forest.jpg', 102, true, '<d:getetag>"e2"</d:getetag>' ) ), $repository );

		$this->assertSame( array( 7 => CheckResult::OK ), $result->outcomes );
		$this->assertNotNull( $repository );
		$this->assertSame( array(), $repository->saved );
	}

	/**
	 * Answers that abort the run.
	 *
	 * @return array<string, array{Response|\Throwable, string}>
	 */
	public static function aborting_answers(): array {
		return array(
			'login failed' => array( new Response( 401, array(), '' ), 'Login to Nextcloud failed.' ),
			'server error' => array( new Response( 503, array(), '' ), 'Unexpected answer from Nextcloud (HTTP 503).' ),
			'unreachable'  => array( new TransportException( TransportException::CONNECT, 'refused' ), 'Nextcloud is not reachable.' ),
		);
	}

	/**
	 * Aborted runs change no status.
	 *
	 * @param Response|\Throwable $answer  Answer at the source.
	 * @param string              $message Expected reason.
	 */
	#[DataProvider( 'aborting_answers' )]
	public function test_run_is_aborted_without_status_change( Response|\Throwable $answer, string $message ): void {
		$repository = null;
		$result     = self::check( array( self::SOURCE => $answer ), $repository );

		$this->assertSame( $message, $result->aborted );
		$this->assertSame( array(), $result->outcomes );
		$this->assertNotNull( $repository );
		$this->assertSame( array(), $repository->statuses );
		$this->assertSame( array(), $repository->saved );
	}
}
