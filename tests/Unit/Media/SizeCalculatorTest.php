<?php
/**
 * Tests for the preview size prediction.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WpNcLinker\Media\SizeCalculator;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Expected values were measured against Nextcloud 34.0.4 / Photos 7.0.0 in the test environment.
 */
#[CoversClass( SizeCalculator::class )]
final class SizeCalculatorTest extends TestCase {

	/**
	 * Measured previews: original, requested box, delivered size.
	 *
	 * @return iterable<string, array{array{int, int}, array{int, int}, array{int, int}}>
	 */
	public static function measured(): iterable {
		$measurements = array(
			'2400x1600' => '150x150=>256x171 300x300=>384x256 768x4096=>1024x683 1024x1024=>1024x683 1536x1536=>1536x1024 2048x2048=>2400x1600 4096x4096=>2400x1600 100x4096=>256x171 4096x100=>256x171 640x960=>1024x683 1200x800=>1536x1024 257x257=>384x256 1025x700=>1536x1024',
			'1200x800'  => '150x150=>256x171 300x300=>384x256 768x4096=>1024x683 1024x1024=>1024x683 1536x1536=>1200x800 2048x2048=>1200x800 4096x4096=>1200x800 100x4096=>256x171 4096x100=>256x171 640x960=>1024x683 1200x800=>1200x800 257x257=>384x256 1025x700=>1200x800',
			'640x960'   => '150x150=>171x256 300x300=>256x384 768x4096=>640x960 1024x1024=>640x960 1536x1536=>640x960 2048x2048=>640x960 4096x4096=>640x960 100x4096=>171x256 4096x100=>171x256 640x960=>640x960 1200x800=>640x960 257x257=>256x384 1025x700=>640x960',
			'1280x960'  => '150x150=>256x192 300x300=>341x256 768x4096=>1024x768 1024x1024=>1024x768 1536x1536=>1280x960 2048x2048=>1280x960 4096x4096=>1280x960 100x4096=>256x192 4096x100=>256x192 640x960=>1024x768 1200x800=>1280x960 257x257=>341x256 1025x700=>1024x768',
		);

		foreach ( $measurements as $original => $line ) {
			foreach ( explode( ' ', $line ) as $pair ) {
				[ $box, $result ] = explode( '=>', $pair );
				yield "$original $box" => array( self::dims( $original ), self::dims( $box ), self::dims( $result ) );
			}
		}
	}

	/**
	 * Parses "WxH".
	 *
	 * @param string $value Dimensions.
	 * @return array{int, int}
	 */
	private static function dims( string $value ): array {
		[ $width, $height ] = array_map( 'intval', explode( 'x', $value ) );

		return array( $width, $height );
	}

	/**
	 * Matches the measured Nextcloud behaviour.
	 *
	 * @param array{int, int} $original Original size.
	 * @param array{int, int} $box      Requested box.
	 * @param array{int, int} $expected Delivered size.
	 */
	#[DataProvider( 'measured' )]
	public function test_matches_nextcloud( array $original, array $box, array $expected ): void {
		$this->assertSame( $expected, ( new SizeCalculator() )->delivered( $original[0], $original[1], $box[0], $box[1] ) );
	}

	public function test_largest_preview_is_capped(): void {
		$calculator = new SizeCalculator();

		$this->assertSame( array( 4096, 2731 ), $calculator->largest_preview( 6000, 4000 ) );
		$this->assertSame( array( 800, 600 ), $calculator->largest_preview( 800, 600 ) );
		$this->assertSame( array( 1024, 683 ), ( new SizeCalculator( 1024 ) )->largest_preview( 2400, 1600 ) );
		$this->assertSame( array( 2731, 4096 ), $calculator->delivered( 4000, 6000, 9999, 9999 ) );
	}
}
