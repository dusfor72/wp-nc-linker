<?php
/**
 * Tests for the folder and album listings.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Browse;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WpNcLinker\Browse\Area;
use WpNcLinker\Browse\BrowseException;
use WpNcLinker\Browse\Browser;
use WpNcLinker\Browse\Item;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\Settings;
use WpNcLinker\Nextcloud\Response;
use WpNcLinker\Nextcloud\TransportException;
use WpNcLinker\Tests\Unit\Config\FakeConstants;
use WpNcLinker\Tests\Unit\Diagnostics\FakeClient;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Conversion, filtering, caching and errors of listings.
 */
#[CoversClass( Browser::class )]
#[CoversClass( Area::class )]
#[CoversClass( Item::class )]
final class BrowserTest extends TestCase {

	/**
	 * Transients written during the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_transient' )->alias( fn ( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, mixed $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	/**
	 * Recorded response.
	 *
	 * @param string $name Fixture name.
	 */
	private static function fixture( string $name ): Response {
		return new Response( 207, array(), (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/propfind-' . $name . '.xml' ) );
	}

	/**
	 * Browser with the given answers.
	 *
	 * @param array<string, Response|\Throwable> $answers Answers of the fake client.
	 * @param FakeClient|null                    $client  Receives the client.
	 * @param-out FakeClient                      $client
	 */
	private static function browser( array $answers, ?FakeClient &$client = null ): Browser {
		$client   = new FakeClient( $answers );
		$settings = new Settings( new FakeConstants(), new Crypto( static fn (): string => 'salt' ) );

		return new Browser( $settings, $client );
	}

	/**
	 * Names of items.
	 *
	 * @param list<Item> $items Items.
	 * @return list<string>
	 */
	private static function names( array $items ): array {
		return array_map( static fn ( Item $item ): string => $item->name, $items );
	}

	public function test_folder_root(): void {
		$items = self::browser( array( 'PROPFIND /remote.php/dav/files/linker/ 1' => self::fixture( 'files-root' ) ) )
			->list( Area::Folders, '' );

		$this->assertSame( array( 'Hidden Download', 'Own', 'Shared With Linker' ), self::names( $items ) );
		$this->assertSame( Item::FOLDER, $items[0]->kind );
		$this->assertSame( 'Shared With Linker', $items[2]->path );
	}

	public function test_folder_files_are_filtered_and_restricted_files_marked(): void {
		$xml     = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
			. self::response( '/remote.php/dav/files/linker/Docs/', true )
			. self::response( '/remote.php/dav/files/linker/Docs/b.jpg', false, 2, 'image/jpeg' )
			. self::response( '/remote.php/dav/files/linker/Docs/a.pdf', false, 3, 'application/pdf' )
			. self::response( '/remote.php/dav/files/linker/Docs/c.svg', false, 4, 'image/svg+xml' )
			. self::response( '/remote.php/dav/files/linker/Docs/clip.mp4', false, 5, 'video/mp4' )
			. self::response( '/remote.php/dav/files/linker/Docs/hidden.png', false, 6, 'image/png', false )
			. self::response( '/remote.php/dav/files/linker/Docs/Sub/', true )
			. '</d:multistatus>';
		$browser = self::browser( array( 'PROPFIND /remote.php/dav/files/linker/Docs/ 1' => new Response( 207, array(), $xml ) ) );

		$items = $browser->list( Area::Folders, '/Docs/' );
		$this->assertSame( array( 'Sub', 'a.pdf', 'b.jpg', 'hidden.png' ), self::names( $items ) );
		$this->assertSame( 'Docs/b.jpg', $items[2]->path );
		$this->assertSame( 2, $items[2]->file_id );
		$this->assertTrue( $items[2]->selectable );
		$this->assertFalse( $items[3]->selectable );
		$this->assertSame( Item::REASON_DOWNLOAD_RESTRICTED, $items[3]->reason );

		$images = $browser->list( Area::Folders, 'Docs', array( 'image' ) );
		$this->assertSame( array( 'Sub', 'b.jpg', 'hidden.png' ), self::names( $images ) );

		$pdf = $browser->list( Area::Folders, 'Docs', array( 'application/pdf' ) );
		$this->assertSame( array( 'Sub', 'a.pdf' ), self::names( $pdf ) );
	}

	public function test_albums_and_album_photos(): void {
		$browser = self::browser(
			array(
				'PROPFIND /remote.php/dav/photos/linker/sharedalbums/ 1' => self::fixture( 'sharedalbums' ),
				'PROPFIND /remote.php/dav/photos/linker/sharedalbums/Shared%20Album%20%28content%29/ 1' => self::fixture( 'album-shared' ),
			)
		);

		$albums = $browser->list( Area::SharedAlbums, '' );
		$this->assertSame( array( 'Group Album (content)', 'Shared Album (content)' ), self::names( $albums ) );
		$this->assertSame( Item::ALBUM, $albums[1]->kind );
		$this->assertSame( 3, $albums[1]->count );

		$photos = $browser->list( Area::SharedAlbums, 'Shared Album (content)' );
		$this->assertSame( array( 'landscape.jpg', 'large-original.jpg', 'portrait.jpg' ), self::names( $photos ) );
		foreach ( $photos as $photo ) {
			$this->assertTrue( $photo->selectable );
			$this->assertStringStartsWith( 'Shared Album (content)/' . $photo->file_id . '-', $photo->path );
		}
		$this->assertSame( 1600, $photos[0]->width );
	}

	public function test_listing_is_cached(): void {
		$client  = null;
		$browser = self::browser( array( 'PROPFIND /remote.php/dav/files/linker/ 1' => self::fixture( 'files-root' ) ), $client );

		$browser->list( Area::Folders, '' );
		$browser->list( Area::Folders, '' );
		$this->assertNotNull( $client );
		$this->assertCount( 1, $client->requests );

		$browser->list( Area::Folders, '', array(), true );
		$this->assertCount( 2, $client->requests );
	}

	public function test_mime_types_are_filtered_after_the_cache(): void {
		$xml     = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
			. self::response( '/remote.php/dav/files/linker/a.pdf', false, 3, 'application/pdf' ) . '</d:multistatus>';
		$browser = self::browser( array( 'PROPFIND /remote.php/dav/files/linker/ 1' => new Response( 207, array(), $xml ) ) );
		$this->assertCount( 1, $browser->list( Area::Folders, '' ) );

		Functions\when( 'get_option' )->justReturn( array_merge( Settings::defaults(), array( 'mime_types' => array( 'image/jpeg' ) ) ) );
		$this->assertCount( 0, $browser->list( Area::Folders, '' ) );
	}

	/**
	 * Failing answers and the resulting error codes.
	 *
	 * @return array<string, array{Response|\Throwable, int}>
	 */
	public static function failures(): array {
		return array(
			'not found'    => array( new Response( 404, array(), '' ), BrowseException::NOT_FOUND ),
			'unauthorized' => array( new Response( 401, array(), '' ), BrowseException::UNAVAILABLE ),
			'server error' => array( new Response( 500, array(), '' ), BrowseException::UPSTREAM ),
			'garbage'      => array( new Response( 207, array(), 'garbage' ), BrowseException::UPSTREAM ),
			'transport'    => array( new TransportException( TransportException::TIMEOUT, 'timeout' ), BrowseException::UNAVAILABLE ),
		);
	}

	/**
	 * Error handling.
	 *
	 * @param Response|\Throwable $answer Answer.
	 * @param int                 $code   Expected code.
	 */
	#[DataProvider( 'failures' )]
	public function test_failures( Response|\Throwable $answer, int $code ): void {
		try {
			self::browser( array( 'PROPFIND /remote.php/dav/files/linker/x/ 1' => $answer ) )->list( Area::Folders, 'x' );
			$this->fail( 'No exception' );
		} catch ( BrowseException $e ) {
			$this->assertSame( $code, $e->getCode() );
		}
	}

	public function test_invalid_paths_are_rejected_before_any_request(): void {
		foreach ( array( array( Area::Folders, '../x' ), array( Area::Folders, "a\0" ), array( Area::Albums, 'Album/123-x.jpg' ) ) as [ $area, $path ] ) {
			$client  = null;
			$browser = self::browser( array(), $client );
			try {
				$browser->list( $area, $path );
				$this->fail( 'Accepted ' . $path );
			} catch ( BrowseException $e ) {
				$this->assertSame( BrowseException::BAD_REQUEST, $e->getCode() );
				$this->assertNotNull( $client );
				$this->assertSame( array(), $client->requests );
			}
		}
	}

	/**
	 * One d:response element.
	 *
	 * @param string $href       Href.
	 * @param bool   $collection Whether it is a collection.
	 * @param int    $file_id    File ID.
	 * @param string $mime       MIME type.
	 * @param bool   $download   Download allowed.
	 */
	private static function response( string $href, bool $collection, int $file_id = 1, string $mime = '', bool $download = true ): string {
		$props = $collection
			? '<d:resourcetype><d:collection/></d:resourcetype>'
			: '<d:resourcetype/><oc:fileid>' . $file_id . '</oc:fileid><d:getcontenttype>' . $mime . '</d:getcontenttype><nc:has-preview>true</nc:has-preview>'
				. '<nc:share-attributes>' . ( $download ? '[]' : '[{"scope":"permissions","key":"download","value":false}]' ) . '</nc:share-attributes>';

		return '<d:response><d:href>' . $href . '</d:href><d:propstat><d:prop>' . $props . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
	}
}
