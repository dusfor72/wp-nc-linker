<?php
/**
 * Tests for finding moved files.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Media;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Browse\Area;
use WpNcLinker\Media\LinkedFile;
use WpNcLinker\Media\Locator;
use WpNcLinker\Nextcloud\Response;
use WpNcLinker\Tests\Unit\Diagnostics\FakeClient;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Relocation by file ID in the file tree and in albums.
 */
#[CoversClass( Locator::class )]
final class LocatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'remove_accents' )->returnArg();
	}

	/**
	 * Multistatus with the given responses.
	 *
	 * @param string ...$responses d:response elements.
	 */
	private static function multistatus( string ...$responses ): Response {
		return new Response( 207, array(), '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">' . implode( '', $responses ) . '</d:multistatus>' );
	}

	/**
	 * One d:response element.
	 *
	 * @param string $href       Href.
	 * @param bool   $collection Collection.
	 * @param int    $file_id    File ID.
	 */
	private static function entry( string $href, bool $collection, int $file_id = 0 ): string {
		return '<d:response><d:href>' . $href . '</d:href><d:propstat><d:prop>'
			. ( $collection ? '<d:resourcetype><d:collection/></d:resourcetype>' : '<d:resourcetype/><oc:fileid>' . $file_id . '</oc:fileid>' )
			. '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
	}

	public function test_finds_moved_file_in_file_tree(): void {
		$client     = new FakeClient( array( 'SEARCHID 102' => self::multistatus( self::entry( '/nextcloud/remote.php/dav/files/linker/New%20Place/forest.jpg', false, 102 ) ) ) );
		$repository = new FakeRepository();
		$file       = new LinkedFile( 102, Area::Folders, 'Old/forest.jpg', 'image/jpeg', 'forest.jpg' );

		$moved = ( new Locator( $client, $repository ) )->relocate( 7, $file );

		$this->assertNotNull( $moved );
		$this->assertSame( 'New Place/forest.jpg', $moved->path );
		$this->assertSame( $moved, $repository->saved[7] );
	}

	public function test_finds_file_in_other_album(): void {
		$client = new FakeClient(
			array(
				'PROPFIND /remote.php/dav/photos/linker/albums/ 1' => self::multistatus(
					self::entry( '/remote.php/dav/photos/linker/albums/', true ),
					self::entry( '/remote.php/dav/photos/linker/albums/Holiday/', true )
				),
				'PROPFIND /remote.php/dav/photos/linker/albums/Holiday/ 1' => self::multistatus(
					self::entry( '/remote.php/dav/photos/linker/albums/Holiday/', true ),
					self::entry( '/remote.php/dav/photos/linker/albums/Holiday/96-beach.jpg', false, 96 )
				),
			)
		);
		$file   = new LinkedFile( 96, Area::Albums, 'Old Album/96-beach.jpg', 'image/jpeg', 'beach.jpg' );

		$moved = ( new Locator( $client, new FakeRepository() ) )->relocate( 7, $file );

		$this->assertNotNull( $moved );
		$this->assertSame( Area::Albums, $moved->area );
		$this->assertSame( 'Holiday/96-beach.jpg', $moved->path );
	}

	public function test_returns_null_when_not_found(): void {
		$file = new LinkedFile( 5, Area::Folders, 'x.jpg', 'image/jpeg', 'x.jpg' );

		$this->assertNull( ( new Locator( new FakeClient( array() ), new FakeRepository() ) )->relocate( 7, $file ) );
	}
}
