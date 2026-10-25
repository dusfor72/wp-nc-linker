<?php
/**
 * Tests for the per-user list of listed file IDs.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Browse;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Browse\SeenFiles;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Thumbnails are only served for files the user has listed (D-6).
 */
#[CoversClass( SeenFiles::class )]
final class SeenFilesTest extends TestCase {

	/**
	 * Stored transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_transient' )->alias( fn ( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, mixed $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	public function test_ids_are_per_user(): void {
		$seen = new SeenFiles();
		$seen->remember( 1, array( 10, 11 ) );
		$seen->remember( 1, array( 11, 12 ) );

		$this->assertTrue( $seen->contains( 1, 10 ) );
		$this->assertTrue( $seen->contains( 1, 12 ) );
		$this->assertFalse( $seen->contains( 1, 13 ) );
		$this->assertFalse( $seen->contains( 2, 10 ) );
	}

	public function test_limit_keeps_newest(): void {
		$seen = new SeenFiles();
		$seen->remember( 1, range( 1, SeenFiles::LIMIT ) );
		$seen->remember( 1, array( SeenFiles::LIMIT + 1 ) );

		$this->assertFalse( $seen->contains( 1, 1 ) );
		$this->assertTrue( $seen->contains( 1, 2 ) );
		$this->assertTrue( $seen->contains( 1, SeenFiles::LIMIT + 1 ) );
	}

	public function test_corrupt_storage_is_ignored(): void {
		$this->transients['wpncl_seen_1'] = array( '5', 6, null );

		$this->assertFalse( ( new SeenFiles() )->contains( 1, 5 ) );
		$this->assertTrue( ( new SeenFiles() )->contains( 1, 6 ) );
	}
}
