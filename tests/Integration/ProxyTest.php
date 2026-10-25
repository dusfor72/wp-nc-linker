<?php
/**
 * Integration tests for the delivery proxy (F5, acceptance tests 5, 6, 7, 8, 10 and 12).
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use WpNcLinker\Browse\Area;
use WpNcLinker\Browse\Browser;
use WpNcLinker\Config\Settings;
use WpNcLinker\Media\Importer;
use WpNcLinker\Media\Locator;
use WpNcLinker\Media\Repository;
use WpNcLinker\Plugin;
use WpNcLinker\Proxy\Controller;
use WpNcLinker\Proxy\ResponseWriter;

/**
 * Requests to the proxy URLs over HTTP against the Nextcloud container.
 */
#[CoversNothing]
final class ProxyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->configure_connection();
		self::delete_linked_attachments();
	}

	protected function tearDown(): void {
		self::delete_linked_attachments();
		parent::tearDown();
	}

	/**
	 * Links a file and returns the attachment ID.
	 *
	 * @param string $area Area.
	 * @param string $path Path.
	 */
	private static function link( string $area, string $path ): int {
		$plugin = Plugin::instance();

		return ( new Importer( $plugin->settings(), $plugin->client(), new Repository() ) )->import( $area, $path, 0 );
	}

	/**
	 * Path of an album photo.
	 *
	 * @param string $album Album.
	 * @param string $name  File name.
	 */
	private static function shared_album_photo( string $album, string $name ): string {
		foreach ( ( new Browser( Plugin::instance()->settings(), Plugin::instance()->client() ) )->list( Area::SharedAlbums, $album, array(), true ) as $item ) {
			if ( $item->name === $name ) {
				return $item->path;
			}
		}
		self::fail( "$name not found" );
	}

	/**
	 * Path of a URL below the site (for the HTTP helper).
	 *
	 * @param string|false $url URL.
	 */
	private static function path_of( $url ): string {
		self::assertIsString( $url );

		return (string) wp_parse_url( $url, PHP_URL_PATH );
	}

	/**
	 * Content of a file of the linker account, fetched directly from Nextcloud.
	 *
	 * @param string $path Path below /remote.php/dav/files/linker/ (URL-encoded).
	 */
	private static function original( string $path ): string {
		$response = self::nextcloud( 'GET', '/remote.php/dav/files/linker/' . $path, 'linker', self::app_password() );
		self::assertSame( 200, $response['status'] );

		return $response['body'];
	}

	public function test_original_and_sizes(): void {
		$id   = self::link( 'folders', 'Shared With Linker/forest.jpg' );
		$full = $this->http( 'GET', self::path_of( wp_get_attachment_url( $id ) ) );

		$this->assertSame( 200, $full['status'] );
		$this->assertSame( 'image/jpeg', $full['headers']['content-type'] ?? null );
		$this->assertSame( sha1( self::original( 'Shared%20With%20Linker/forest.jpg' ) ), sha1( $full['body'] ) );
		$this->assertSame( (string) strlen( $full['body'] ), $full['headers']['content-length'] ?? null );
		$this->assertSame( 'nosniff', $full['headers']['x-content-type-options'] ?? null );
		$this->assertSame( 'public, max-age=86400', $full['headers']['cache-control'] ?? null );
		$this->assertArrayHasKey( 'etag', $full['headers'] );
		$this->assertArrayHasKey( 'last-modified', $full['headers'] );
		foreach ( array( 'set-cookie', 'x-user-id', 'x-request-id', 'content-security-policy', 'x-powered-by' ) as $leak ) {
			$this->assertArrayNotHasKey( $leak, $full['headers'], $leak );
		}

		foreach ( array(
			'thumbnail' => array( 256, 171 ),
			'medium'    => array( 384, 256 ),
			'large'     => array( 1024, 683 ),
		) as $size => $expected ) {
			$response = $this->http( 'GET', self::path_of( wp_get_attachment_image_url( $id, $size ) ) );
			$this->assertSame( 200, $response['status'], $size );
			$this->assertSame( 'image/jpeg', $response['headers']['content-type'] ?? null );
			$dimensions = getimagesizefromstring( $response['body'] );
			$this->assertIsArray( $dimensions );
			$this->assertSame( $expected, array( $dimensions[0], $dimensions[1] ), $size );
		}
	}

	public function test_conditional_requests(): void {
		$id    = self::link( 'folders', 'Shared With Linker/forest.jpg' );
		$paths = array( self::path_of( wp_get_attachment_url( $id ) ), self::path_of( wp_get_attachment_image_url( $id, 'thumbnail' ) ) );

		foreach ( $paths as $path ) {
			$first = $this->http( 'GET', $path );
			$etag  = $first['headers']['etag'] ?? '';
			$this->assertNotSame( '', $etag );

			$again = $this->http( 'GET', $path, array(), null, array( 'If-None-Match: ' . $etag ) );
			$this->assertSame( 304, $again['status'], $path );
			$this->assertSame( '', $again['body'] );
			$this->assertSame( $etag, $again['headers']['etag'] ?? null );

			$since = $this->http( 'GET', $path, array(), null, array( 'If-Modified-Since: ' . ( $first['headers']['last-modified'] ?? '' ) ) );
			$this->assertSame( 304, $since['status'], $path );

			$head = $this->http( 'HEAD', $path );
			$this->assertSame( 200, $head['status'] );
			$this->assertSame( '', $head['body'] );
		}
	}

	public function test_pdf_headers(): void {
		$this->set_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'cache_max_age' => 600 ) ) );
		$id       = self::link( 'folders', 'Shared With Linker/sample.pdf' );
		$response = $this->http( 'GET', self::path_of( wp_get_attachment_url( $id ) ) );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'application/pdf', $response['headers']['content-type'] ?? null );
		$this->assertSame( 'inline; filename="sample.pdf"; filename*=UTF-8\'\'sample.pdf', $response['headers']['content-disposition'] ?? null );
		$this->assertSame( 'public, max-age=600', $response['headers']['cache-control'] ?? null );
		$this->assertSame( 'nosniff', $response['headers']['x-content-type-options'] ?? null );
		$this->assertStringStartsWith( '%PDF-', $response['body'] );
	}

	public function test_manipulated_requests_are_rejected(): void {
		$id      = self::link( 'folders', 'Shared With Linker/forest.jpg' );
		$regular = wp_insert_attachment(
			array(
				'post_title'     => 'regular',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
		$post    = wp_insert_post( array( 'post_title' => 'post' ) );
		$this->assertIsInt( $regular );
		$this->assertIsInt( $post );

		try {
			foreach ( array(
				"/nextcloud-media/$regular/full/regular.jpg",
				"/nextcloud-media/$post/full/post.jpg",
				'/nextcloud-media/999999/full/forest.jpg',
				'/nextcloud-media/102/full/forest.jpg',
				"/nextcloud-media/$id/full/other.jpg",
				"/nextcloud-media/$id/full/forest.jpg.php",
				"/nextcloud-media/$id/500x500/forest-500x500.jpg",
				"/nextcloud-media/$id/4096x4096/forest-4096x4096.jpg",
				"/nextcloud-media/$id/256x171/forest-384x256.jpg",
			) as $path ) {
				$this->assertSame( 404, $this->http( 'GET', $path )['status'], $path );
			}

			$this->assertSame( 405, $this->http( 'POST', "/nextcloud-media/$id/full/forest.jpg" )['status'] );
			$this->assertSame( 405, $this->http( 'DELETE', "/nextcloud-media/$id/full/forest.jpg" )['status'] );

			// Types removed from the settings are no longer delivered.
			$this->set_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'mime_types' => array( 'application/pdf' ) ) ) );
			$this->assertSame( 404, $this->http( 'GET', "/nextcloud-media/$id/full/forest.jpg" )['status'] );
		} finally {
			wp_delete_attachment( $regular, true );
			wp_delete_post( $post, true );
		}
	}

	public function test_large_original_is_streamed(): void {
		$id   = self::link( 'sharedalbums', self::shared_album_photo( 'Shared Album (content)', 'large-original.jpg' ) );
		$file = ( new Repository() )->get( $id );
		$this->assertNotNull( $file );
		$this->assertGreaterThan( 50 * 1024 * 1024, (int) $file->size );

		$response = $this->http_digest( self::path_of( wp_get_attachment_url( $id ) ) );
		$this->assertSame( 200, $response['status'] );
		$this->assertSame( (string) $file->size, $response['headers']['content-length'] ?? null );
		$this->assertSame( $file->size, $response['size'] );
		$hash = $response['sha1'];

		// Peak memory while streaming in this process: chunks are hashed, never collected.
		$writer     = new class() implements ResponseWriter {
			/**
			 * Running hash.
			 *
			 * @var \HashContext
			 */
			public \HashContext $hash;

			/**
			 * Status.
			 *
			 * @var int
			 */
			public int $code = 0;

			public function __construct() {
				$this->hash = hash_init( 'sha1' );
			}

			public function status( int $code ): void {
				$this->code = $code;
			}

			public function header( string $name, string $value ): void {
			}

			public function body( string $chunk ): void {
				hash_update( $this->hash, $chunk );
			}
		};
		$plugin     = Plugin::instance();
		$controller = new Controller( $plugin->settings(), new Repository(), $plugin->client(), new Locator( $plugin->client(), new Repository() ) );

		gc_collect_cycles();
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$before = memory_get_usage();
		$controller->handle( $id, 'full', 'large-original.jpg', 'GET', array(), $writer );
		$peak = memory_get_peak_usage() - $before;

		$this->assertSame( 200, $writer->code );
		$this->assertSame( $hash, hash_final( $writer->hash ) );
		$this->assertLessThan( 16 * 1024 * 1024, $peak, 'Streaming must not buffer the file.' );

		// No preview for > 50 MB originals: the sizes show the placeholder instead of 70 MB.
		$thumbnail = $this->http( 'GET', self::path_of( wp_get_attachment_image_url( $id, 'thumbnail' ) ) );
		$this->assertSame( 'image/svg+xml', $thumbnail['headers']['content-type'] ?? null );
		$this->assertLessThan( 1024, strlen( $thumbnail['body'] ) );
	}

	public function test_moved_file_stays_available(): void {
		$password = getenv( 'WPNCL_TEST_CONTENT_PASSWORD' );
		$this->assertIsString( $password );
		$id   = self::link( 'folders', 'Shared With Linker/harbor.jpg' );
		$from = '/remote.php/dav/files/content/Shared%20With%20Linker/harbor.jpg';
		$to   = '/remote.php/dav/files/content/Shared%20With%20Linker/Subfolder/harbor%20moved.jpg';

		$move = self::nextcloud( 'MOVE', $from, 'content', $password, array( 'Destination' => 'https://nextcloud.test' . $to ) );
		$this->assertSame( 201, $move['status'] );
		try {
			$full = $this->http( 'GET', self::path_of( wp_get_attachment_url( $id ) ) );
			$this->assertSame( 200, $full['status'] );
			$this->assertSame( 'image/jpeg', $full['headers']['content-type'] ?? null );

			// The proxy (web server process) stored the new path; drop this process' meta cache.
			wp_cache_delete( $id, 'post_meta' );
			$file = ( new Repository() )->get( $id );
			$this->assertNotNull( $file );
			$this->assertSame( 'Shared With Linker/Subfolder/harbor moved.jpg', $file->path );

			$thumbnail = $this->http( 'GET', self::path_of( wp_get_attachment_image_url( $id, 'thumbnail' ) ) );
			$this->assertSame( 'image/jpeg', $thumbnail['headers']['content-type'] ?? null );
		} finally {
			self::nextcloud( 'MOVE', $to, 'content', $password, array( 'Destination' => 'https://nextcloud.test' . $from ) );
		}
	}

	public function test_missing_files(): void {
		$image    = self::link( 'folders', 'Shared With Linker/forest.jpg' );
		$document = self::link( 'folders', 'Shared With Linker/sample.pdf' );
		foreach ( array( $image, $document ) as $id ) {
			update_post_meta( $id, Repository::META_FILE_ID, 999999 );
			update_post_meta(
				$id,
				Repository::META_SOURCE,
				array(
					'area' => 'folders',
					'path' => 'Shared With Linker/deleted-' . $id,
				)
			);
		}

		$placeholder = $this->http( 'GET', self::path_of( wp_get_attachment_url( $image ) ) );
		$this->assertSame( 200, $placeholder['status'] );
		$this->assertSame( 'image/svg+xml', $placeholder['headers']['content-type'] ?? null );
		$this->assertStringStartsWith( '<svg ', $placeholder['body'] );
		$this->assertStringNotContainsString( '<script', $placeholder['body'] );
		wp_cache_delete( $image, 'post_meta' );
		$this->assertSame( \WpNcLinker\Media\LinkedFile::STATUS_MISSING, ( new Repository() )->get( $image )?->status );

		$this->assertSame( 404, $this->http( 'GET', self::path_of( wp_get_attachment_url( $document ) ) )['status'] );
	}

	public function test_urls_without_pretty_permalinks(): void {
		global $wp_rewrite;
		$id = self::link( 'folders', 'Shared With Linker/forest.jpg' );

		$this->set_option( 'permalink_structure', '' );
		$wp_rewrite->init();
		try {
			$url = (string) wp_get_attachment_image_url( $id, 'thumbnail' );
			$this->assertStringContainsString( '/index.php/nextcloud-media/' . $id . '/256x171/', $url );
			$response = $this->http( 'GET', self::path_of( $url ) );
			$this->assertSame( 200, $response['status'] );
			$this->assertSame( 'image/jpeg', $response['headers']['content-type'] ?? null );
		} finally {
			$this->restore_permalinks();
		}
	}

	/**
	 * Restores the permalink settings of the test installation.
	 */
	private function restore_permalinks(): void {
		global $wp_rewrite;
		update_option( 'permalink_structure', '/%postname%/' );
		$wp_rewrite->init();
	}
}
