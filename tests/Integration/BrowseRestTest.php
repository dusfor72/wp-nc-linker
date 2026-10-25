<?php
/**
 * Integration tests for the browse and thumbnail routes (F3, acceptance tests 2 and 11).
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * REST routes of the media dialog against the Nextcloud container.
 */
#[CoversNothing]
final class BrowseRestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->configure_connection();
	}

	/**
	 * Login cookies and REST nonce of a new user.
	 *
	 * @param string $role Role.
	 * @return array{cookies: array<string, string>, nonce: string}
	 */
	private function session( string $role ): array {
		$user_id = $this->create_user( $role );
		$cookies = $this->login_cookies( $user_id );

		return array(
			'cookies' => $cookies,
			'nonce'   => $this->nonce( $user_id, $cookies, 'wp_rest' ),
		);
	}

	/**
	 * GET /browse.
	 *
	 * @param array{cookies: array<string, string>, nonce: string} $session Session.
	 * @param array<string, string|int>                            $query   Query arguments.
	 * @return array{status: int, data: mixed}
	 */
	private function browse( array $session, array $query ): array {
		$response = $this->http(
			'GET',
			'/wp-json/wpncl/v1/browse?' . http_build_query( $query + array( 'refresh' => 1 ) ),
			$session['cookies'],
			null,
			array( 'X-WP-Nonce: ' . $session['nonce'] )
		);

		return array(
			'status' => $response['status'],
			'data'   => json_decode( $response['body'], true ),
		);
	}

	/**
	 * Items of a successful listing, keyed by name.
	 *
	 * @param array{status: int, data: mixed} $response Response.
	 * @return array<string, array<string, mixed>>
	 */
	private function items( array $response ): array {
		$this->assertSame( 200, $response['status'], (string) wp_json_encode( $response['data'] ) );
		$this->assertIsArray( $response['data'] );
		$this->assertIsArray( $response['data']['items'] );

		$items = array();
		foreach ( $response['data']['items'] as $item ) {
			$this->assertIsArray( $item );
			$items[ (string) $item['name'] ] = $item;
		}

		return $items;
	}

	/**
	 * Path of a thumbnail URL (relative to the site).
	 *
	 * @param mixed $url Thumbnail URL.
	 */
	private static function thumb_path( mixed $url ): string {
		self::assertIsString( $url );
		$parts = wp_parse_url( $url );
		self::assertIsArray( $parts );

		return ( $parts['path'] ?? '' ) . '?' . ( $parts['query'] ?? '' );
	}

	public function test_folders(): void {
		$editor = $this->session( 'editor' );

		$root = $this->items( $this->browse( $editor, array( 'area' => 'folders' ) ) );
		$this->assertSame( array( 'Hidden Download', 'Own', 'Shared With Linker' ), array_keys( $root ) );
		$this->assertSame( 'folder', $root['Own']['kind'] );

		$shared = $this->items(
			$this->browse(
				$editor,
				array(
					'area' => 'folders',
					'path' => 'Shared With Linker',
				)
			)
		);
		$this->assertSame( array( 'Subfolder', 'forest.jpg', 'harbor.jpg', 'sample.pdf' ), array_keys( $shared ) );
		$this->assertSame( 'Shared With Linker/forest.jpg', $shared['forest.jpg']['path'] );
		$this->assertSame( 2400, $shared['forest.jpg']['width'] );
		$this->assertTrue( $shared['forest.jpg']['selectable'] );
		$this->assertIsString( $shared['forest.jpg']['thumb'] );
		$this->assertSame( 'application/pdf', $shared['sample.pdf']['mime'] );
		$this->assertTrue( $shared['sample.pdf']['selectable'] );

		$images = $this->items(
			$this->browse(
				$editor,
				array(
					'area' => 'folders',
					'path' => 'Shared With Linker',
					'type' => 'image',
				)
			)
		);
		$this->assertSame( array( 'Subfolder', 'forest.jpg', 'harbor.jpg' ), array_keys( $images ) );
	}

	public function test_download_restricted_files_are_not_selectable(): void {
		$items = $this->items(
			$this->browse(
				$this->session( 'author' ),
				array(
					'area' => 'folders',
					'path' => 'Hidden Download',
				)
			)
		);

		$this->assertFalse( $items['no-download.jpg']['selectable'] );
		$this->assertSame( 'download_restricted', $items['no-download.jpg']['reason'] );
		$this->assertNull( $items['no-download.jpg']['thumb'] );
	}

	public function test_own_shared_and_group_albums(): void {
		$editor = $this->session( 'editor' );

		$own = $this->items( $this->browse( $editor, array( 'area' => 'albums' ) ) );
		$this->assertSame( array( 'Linker Album' ), array_keys( $own ) );

		$shared = $this->items( $this->browse( $editor, array( 'area' => 'sharedalbums' ) ) );
		$this->assertSame( array( 'Group Album (content)', 'Shared Album (content)' ), array_keys( $shared ) );
		$this->assertSame( 3, $shared['Shared Album (content)']['count'] );

		$photos = $this->items(
			$this->browse(
				$editor,
				array(
					'area' => 'sharedalbums',
					'path' => 'Shared Album (content)',
				)
			)
		);
		$this->assertSame( array( 'landscape.jpg', 'large-original.jpg', 'portrait.jpg' ), array_keys( $photos ) );
		$this->assertSame( 1067, $photos['landscape.jpg']['height'] );
		$this->assertGreaterThan( 50 * 1024 * 1024, $photos['large-original.jpg']['size'] );

		$group = $this->items(
			$this->browse(
				$editor,
				array(
					'area' => 'sharedalbums',
					'path' => 'Group Album (content)',
				)
			)
		);
		$this->assertSame( array( 'square.png' ), array_keys( $group ) );
	}

	public function test_invalid_and_missing_locations(): void {
		$editor = $this->session( 'editor' );

		$this->assertSame(
			400,
			$this->browse(
				$editor,
				array(
					'area' => 'folders',
					'path' => '../content',
				)
			)['status']
		);
		$this->assertSame(
			400,
			$this->browse(
				$editor,
				array(
					'area' => 'sharedalbums',
					'path' => 'Shared Album (content)/96-landscape.jpg',
				)
			)['status']
		);
		$this->assertSame( 400, $this->browse( $editor, array( 'area' => 'places' ) )['status'] );
		$this->assertSame(
			404,
			$this->browse(
				$editor,
				array(
					'area' => 'folders',
					'path' => 'Does not exist',
				)
			)['status']
		);
	}

	public function test_roles(): void {
		$this->assertSame( 200, $this->browse( $this->session( 'administrator' ), array( 'area' => 'folders' ) )['status'] );
		$this->assertSame( 200, $this->browse( $this->session( 'author' ), array( 'area' => 'folders' ) )['status'] );
		$this->assertSame( 403, $this->browse( $this->session( 'subscriber' ), array( 'area' => 'folders' ) )['status'] );

		$anonymous = $this->http( 'GET', '/wp-json/wpncl/v1/browse?area=folders' );
		$this->assertSame( 401, $anonymous['status'] );
	}

	public function test_thumbnails_only_for_listed_files(): void {
		$editor = $this->session( 'editor' );
		$items  = $this->items(
			$this->browse(
				$editor,
				array(
					'area' => 'folders',
					'path' => 'Shared With Linker',
				)
			)
		);

		$thumb = $this->http( 'GET', self::thumb_path( $items['forest.jpg']['thumb'] ), $editor['cookies'] );
		$this->assertSame( 200, $thumb['status'] );
		$this->assertSame( 'image/jpeg', $thumb['headers']['content-type'] ?? null );
		$this->assertSame( 'nosniff', $thumb['headers']['x-content-type-options'] ?? null );
		$this->assertStringContainsString( 'private', $thumb['headers']['cache-control'] ?? '' );
		$size = getimagesizefromstring( $thumb['body'] );
		$this->assertIsArray( $size );
		$this->assertLessThanOrEqual( 256, max( $size[0], $size[1] ) );
		$this->assertArrayNotHasKey( 'set-cookie', $thumb['headers'] );

		// Another user who has not listed the folder gets nothing for the same ID.
		$author = $this->session( 'author' );
		$path   = '/wp-json/wpncl/v1/thumb/' . (int) $items['forest.jpg']['file_id'] . '?_wpnonce=' . $author['nonce'];
		$this->assertSame( 404, $this->http( 'GET', $path, $author['cookies'] )['status'] );

		// IDs that were not listed (e.g. guessed) are not proxied.
		$guessed = '/wp-json/wpncl/v1/thumb/' . ( (int) $items['forest.jpg']['file_id'] + 1000 ) . '?_wpnonce=' . $editor['nonce'];
		$this->assertSame( 404, $this->http( 'GET', $guessed, $editor['cookies'] )['status'] );

		// Without nonce the cookie is not used (REST API CSRF protection).
		$no_nonce = '/wp-json/wpncl/v1/thumb/' . (int) $items['forest.jpg']['file_id'];
		$this->assertSame( 401, $this->http( 'GET', $no_nonce, $editor['cookies'] )['status'] );
	}
}
