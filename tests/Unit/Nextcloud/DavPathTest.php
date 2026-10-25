<?php
/**
 * Tests for WebDAV path building.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Nextcloud;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WpNcLinker\Nextcloud\DavPath;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Paths stay below the account's collections.
 */
#[CoversClass( DavPath::class )]
final class DavPathTest extends TestCase {

	public function test_files_paths(): void {
		$this->assertSame( '/remote.php/dav/files/linker/', DavPath::files( 'linker' ) );
		$this->assertSame( '/remote.php/dav/files/web%20site%40example.org/', DavPath::files( 'web site@example.org' ) );
		$this->assertSame( '/remote.php/dav/files/linker/Shared%20With%20Linker/a%23b%3F.jpg', DavPath::files( 'linker', '/Shared With Linker//a#b?.jpg' ) );
		$this->assertSame( '/remote.php/dav/files/linker/Folder/', DavPath::files( 'linker', 'Folder/' ) );
	}

	public function test_photos_paths(): void {
		$this->assertSame( '/remote.php/dav/photos/linker/albums/', DavPath::photos( 'linker', DavPath::ALBUMS ) );
		$this->assertSame(
			'/remote.php/dav/photos/linker/sharedalbums/Shared%20Album%20%28content%29/96-landscape.jpg',
			DavPath::photos( 'linker', DavPath::SHARED_ALBUMS, 'Shared Album (content)/96-landscape.jpg' )
		);
		$this->assertSame( '/files/linker', DavPath::search_scope( 'linker' ) );
	}

	/**
	 * Relative paths that must be rejected.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_paths(): array {
		return array(
			'parent'        => array( '../other' ),
			'nested parent' => array( 'a/../../b' ),
			'dot'           => array( './a' ),
			'null byte'     => array( "a\0b" ),
			'newline'       => array( "a\nb" ),
			'backslash'     => array( 'a\\..\\b' ),
		);
	}

	#[DataProvider( 'invalid_paths' )]
	public function test_rejects_traversal( string $relative ): void {
		$this->expectException( \InvalidArgumentException::class );

		DavPath::files( 'linker', $relative );
	}

	public function test_rejects_unknown_collection_and_bad_user(): void {
		foreach (
			array(
				static fn () => DavPath::photos( 'linker', 'places' ),
				static fn () => DavPath::files( '' ),
				static fn () => DavPath::files( 'a/b' ),
			) as $call
		) {
			try {
				$call();
				$this->fail( 'Accepted invalid input' );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_relative_paths_from_hrefs(): void {
		$collection = DavPath::files( 'linker' );

		$this->assertSame( '', DavPath::relative( '/remote.php/dav/files/linker/', $collection ) );
		$this->assertSame( '', DavPath::relative( '/remote.php/dav/files/linker', $collection ) );
		$this->assertSame( 'Shared With Linker/a b.jpg', DavPath::relative( '/remote.php/dav/files/linker/Shared%20With%20Linker/a%20b.jpg', $collection ) );
		// Nextcloud in a sub-directory.
		$this->assertSame( '', DavPath::relative( '/nextcloud/remote.php/dav/files/linker/', $collection ) );
		$this->assertSame( 'Docs', DavPath::relative( '/nextcloud/remote.php/dav/files/linker/Docs/', $collection ) );
		// Sabre does not encode everything rawurlencode() encodes.
		$this->assertSame( 'Shared Album (content)', DavPath::relative( '/remote.php/dav/photos/linker/sharedalbums/Shared%20Album%20(content)/', DavPath::photos( 'linker', DavPath::SHARED_ALBUMS ) ) );
		$this->assertNull( DavPath::relative( '/remote.php/dav/files/other/x.jpg', $collection ) );
	}
}
