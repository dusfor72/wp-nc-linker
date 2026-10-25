<?php
/**
 * Tests for linking files.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Media;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\Settings;
use WpNcLinker\Media\ImportException;
use WpNcLinker\Media\Importer;
use WpNcLinker\Media\LinkedFile;
use WpNcLinker\Nextcloud\Response;
use WpNcLinker\Tests\Unit\Config\FakeConstants;
use WpNcLinker\Tests\Unit\Diagnostics\FakeClient;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Lookup, validation, dimensions and reuse.
 */
#[CoversClass( Importer::class )]
#[CoversClass( ImportException::class )]
#[CoversClass( LinkedFile::class )]
final class ImporterTest extends TestCase {

	/**
	 * Repository double.
	 *
	 * @var FakeRepository
	 */
	private FakeRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->justReturn( false );
		$this->repository = new FakeRepository();
	}

	/**
	 * Importer answering with the given responses.
	 *
	 * @param array<string, Response|\Throwable> $answers Answers.
	 */
	private function importer( array $answers ): Importer {
		$settings = new Settings( new FakeConstants(), new Crypto( static fn (): string => 'salt' ) );

		return new Importer( $settings, new FakeClient( $answers ), $this->repository );
	}

	/**
	 * PROPFIND answer for one file.
	 *
	 * @param string $href  Href.
	 * @param string $props Additional properties.
	 */
	private static function file( string $href, string $props ): Response {
		return new Response(
			207,
			array(),
			'<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
			. '<d:response><d:href>' . $href . '</d:href><d:propstat><d:prop><d:resourcetype/>' . $props
			. '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>'
		);
	}

	public function test_links_folder_image_with_metadata_size(): void {
		$importer = $this->importer(
			array(
				'PROPFIND /remote.php/dav/files/linker/Shared%20With%20Linker/forest.jpg 0' => self::file(
					'/remote.php/dav/files/linker/Shared%20With%20Linker/forest.jpg',
					'<oc:fileid>102</oc:fileid><d:getcontenttype>image/jpeg</d:getcontenttype><d:getcontentlength>85213</d:getcontentlength><d:getetag>"abc"</d:getetag>'
					. '<nc:metadata-photos-size><width>2400</width><height>1600</height></nc:metadata-photos-size>'
				),
			)
		);

		$this->assertSame( 101, $importer->import( 'folders', '/Shared With Linker//forest.jpg', 5 ) );
		$file = $this->repository->created[0];
		$this->assertSame( 102, $file->file_id );
		$this->assertSame( 'Shared With Linker/forest.jpg', $file->path );
		$this->assertSame( 'image/jpeg', $file->mime );
		$this->assertSame( array( 2400, 1600 ), array( $file->width, $file->height ) );
		$this->assertSame( 85213, $file->size );
		$this->assertSame( 'abc', $file->etag );
	}

	public function test_reuses_existing_attachment_and_updates_source(): void {
		$this->repository->existing[96] = 42;
		$importer                       = $this->importer(
			array(
				'PROPFIND /remote.php/dav/photos/linker/sharedalbums/Shared%20Album%20%28content%29/96-landscape.jpg 0' => self::file(
					'/remote.php/dav/photos/linker/sharedalbums/Shared%20Album%20(content)/96-landscape.jpg',
					'<oc:fileid>96</oc:fileid><d:getcontenttype>image/jpeg</d:getcontenttype><nc:file-name>landscape.jpg</nc:file-name>'
					. '<nc:metadata-photos-size><width>1600</width><height>1067</height></nc:metadata-photos-size>'
				),
			)
		);

		$this->assertSame( 42, $importer->import( 'sharedalbums', 'Shared Album (content)/96-landscape.jpg', 0 ) );
		$this->assertSame( array(), $this->repository->created );
		$this->assertSame( 'landscape.jpg', $this->repository->saved[42]->name );
		$this->assertSame( 'Shared Album (content)/96-landscape.jpg', $this->repository->saved[42]->path );
	}

	public function test_reads_dimensions_from_the_original_when_metadata_is_missing(): void {
		ob_start();
		$png = imagecreatetruecolor( 37, 21 );
		imagepng( $png );
		$bytes = (string) ob_get_clean();

		$importer = $this->importer(
			array(
				'PROPFIND /remote.php/dav/files/linker/a.png 0' => self::file( '/remote.php/dav/files/linker/a.png', '<oc:fileid>7</oc:fileid><d:getcontenttype>image/png</d:getcontenttype>' ),
			)
		);
		$client   = ( new \ReflectionProperty( Importer::class, 'client' ) )->getValue( $importer );
		$this->assertInstanceOf( FakeClient::class, $client );

		$this->assertSame( 101, $importer->import( 'folders', 'a.png', 0 ) );
		$this->assertNull( $this->repository->created[0]->width );
		$this->assertContains( 'HEAD /remote.php/dav/files/linker/a.png', $client->requests );

		$client->answers_head = new Response( 206, array(), substr( $bytes, 0, 200 ) );
		$importer->import( 'folders', 'a.png', 0 );
		$second = $this->repository->created[1] ?? null;
		$this->assertNotNull( $second );
		$this->assertSame( array( 37, 21 ), array( $second->width, $second->height ) );
	}

	/**
	 * Rejected requests and the expected reasons.
	 *
	 * @return array<string, array{string, string, Response|null, string}>
	 */
	public static function rejected(): array {
		return array(
			'unknown area'     => array( 'places', 'x.jpg', null, ImportException::INVALID ),
			'traversal'        => array( 'folders', '../content/x.jpg', null, ImportException::INVALID ),
			'empty path'       => array( 'folders', '/', null, ImportException::INVALID ),
			'album only'       => array( 'albums', 'Album', null, ImportException::INVALID ),
			'album without id' => array( 'albums', 'Album/x.jpg', null, ImportException::INVALID ),
			'not found'        => array( 'folders', 'x.jpg', new Response( 404, array(), '' ), ImportException::NOT_FOUND ),
			'login failed'     => array( 'folders', 'x.jpg', new Response( 401, array(), '' ), ImportException::UNAVAILABLE ),
			'folder'           => array( 'folders', 'x.jpg', new Response( 207, array(), '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response><d:href>/x/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype><oc:fileid>3</oc:fileid></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>' ), ImportException::INVALID ),
			'svg'              => array( 'folders', 'x.jpg', self::file( '/x.svg', '<oc:fileid>3</oc:fileid><d:getcontenttype>image/svg+xml</d:getcontenttype>' ), ImportException::TYPE ),
			'restricted'       => array( 'folders', 'x.jpg', self::file( '/x.jpg', '<oc:fileid>3</oc:fileid><d:getcontenttype>image/jpeg</d:getcontenttype><nc:share-attributes>[{"scope":"permissions","key":"download","value":false}]</nc:share-attributes>' ), ImportException::RESTRICTED ),
		);
	}

	/**
	 * Requests that are rejected.
	 *
	 * @param string        $area     Area.
	 * @param string        $path     Path.
	 * @param Response|null $answer   PROPFIND answer for "x.jpg".
	 * @param string        $reason   Expected reason.
	 */
	#[DataProvider( 'rejected' )]
	public function test_rejected( string $area, string $path, ?Response $answer, string $reason ): void {
		$answers = null === $answer ? array() : array( 'PROPFIND /remote.php/dav/files/linker/x.jpg 0' => $answer );

		try {
			$this->importer( $answers )->import( $area, $path, 0 );
			$this->fail( 'Not rejected' );
		} catch ( ImportException $e ) {
			$this->assertSame( $reason, $e->reason );
			$this->assertNotSame( '', $e->user_message() );
		}
		$this->assertSame( array(), $this->repository->created );
	}
}
