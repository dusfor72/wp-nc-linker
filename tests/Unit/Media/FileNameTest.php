<?php
/**
 * Tests for URL-safe file names.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Media;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WpNcLinker\Media\FileName;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Names in URLs must equal the stored names (srcset matching by basename).
 */
#[CoversClass( FileName::class )]
final class FileNameTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'remove_accents' )->alias(
			static fn ( string $text ): string => strtr(
				$text,
				array(
					'Ü' => 'U',
					'ü' => 'u',
					'ö' => 'o',
					'ß' => 'ss',
					'é' => 'e',
				)
			)
		);
	}

	/**
	 * Original names and slugs.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function names(): array {
		return array(
			'plain'          => array( 'forest.jpg', 'forest.jpg' ),
			'spaces'         => array( 'My Photo 1.JPG', 'My-Photo-1.jpg' ),
			'accents'        => array( 'Über Größe é.png', 'Uber-Grosse-e.png' ),
			'special'        => array( 'a#b?c&d%e.pdf', 'a-b-c-d-e.pdf' ),
			'no extension'   => array( 'README', 'README' ),
			'only extension' => array( '.jpg', 'file.jpg' ),
			'dots'           => array( '..x..jpg', 'x.jpg' ),
			'empty stem'     => array( '###.webp', 'file.webp' ),
		);
	}

	#[DataProvider( 'names' )]
	public function test_slug( string $name, string $slug ): void {
		$this->assertSame( $slug, FileName::slug( $name ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9._-]+$/', FileName::slug( $name ) );
	}

	public function test_sized_and_title(): void {
		$this->assertSame( 'forest-1024x683.jpg', FileName::sized( 'forest.jpg', 1024, 683 ) );
		$this->assertSame( 'README-10x20', FileName::sized( 'README', 10, 20 ) );
		$this->assertSame( 'My Photo', FileName::title( 'My Photo.jpg' ) );
		$this->assertSame( '.htaccess', FileName::title( '.htaccess' ) );
	}

	public function test_slug_is_bounded(): void {
		$this->assertLessThanOrEqual( 111, strlen( FileName::slug( str_repeat( 'a', 500 ) . '.jpeg' ) ) );
	}
}
