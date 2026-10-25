<?php
/**
 * Tests for the delivery of linked files.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Proxy;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Browse\Area;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\Settings;
use WpNcLinker\Media\LinkedFile;
use WpNcLinker\Media\Locator;
use WpNcLinker\Nextcloud\TransportException;
use WpNcLinker\Proxy\Controller;
use WpNcLinker\Proxy\Placeholder;
use WpNcLinker\Tests\Unit\Config\FakeConstants;
use WpNcLinker\Tests\Unit\Diagnostics\FakeClient;
use WpNcLinker\Tests\Unit\Media\FakeRepository;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Validation, headers, streaming, 304 and fallbacks of the proxy.
 */
#[CoversClass( Controller::class )]
#[CoversClass( Placeholder::class )]
final class ControllerTest extends TestCase {

	private const FOREST  = '/remote.php/dav/files/linker/Shared%20With%20Linker/forest.jpg';
	private const PREVIEW = '/index.php/apps/photos/api/v1/preview/102?x=256&y=171';

	/**
	 * Client double.
	 *
	 * @var FakeClient
	 */
	private FakeClient $client;

	/**
	 * Linked files by attachment ID.
	 *
	 * @var array<int, LinkedFile>
	 */
	private array $files = array();

	/**
	 * Result of the relocation.
	 *
	 * @var LinkedFile|null
	 */
	private ?LinkedFile $relocated = null;

	protected function setUp(): void {
		parent::setUp();
		$this->client = new FakeClient( array() );
		$this->files  = array(
			10 => new LinkedFile( 102, Area::Folders, 'Shared With Linker/forest.jpg', 'image/jpeg', 'forest.jpg', 85213, 2400, 1600, 'e' ),
			11 => new LinkedFile( 300, Area::Folders, 'Docs/report.pdf', 'application/pdf', 'Report 2026.pdf', 605 ),
			12 => new LinkedFile( 301, Area::Folders, 'Docs/table.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'table.docx', 900 ),
		);
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'get_post_type' )->alias( fn ( int $id ) => isset( $this->files[ $id ] ) ? 'attachment' : 'post' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'sizes' => array(
					'thumbnail' => array(
						'file'   => 'forest-256x171.jpg',
						'width'  => 256,
						'height' => 171,
					),
				),
			)
		);
	}

	/**
	 * Controller with the fake client and repository.
	 */
	private function controller(): Controller {
		$repository        = new FakeRepository();
		$repository->files = $this->files;
		$test              = $this;
		$locator           = new class( $this->client, $repository, $test ) extends Locator {
			/**
			 * Constructor.
			 *
			 * @param FakeClient     $client     Client.
			 * @param FakeRepository $repository Repository.
			 * @param ControllerTest $test       Test providing the result.
			 */
			public function __construct( FakeClient $client, FakeRepository $repository, private ControllerTest $test ) {
				parent::__construct( $client, $repository );
			}

			public function relocate( int $attachment_id, LinkedFile $file ): ?LinkedFile {
				return $this->test->relocation();
			}
		};
		$settings          = new Settings( new FakeConstants(), new Crypto( static fn (): string => 'salt' ) );

		return new Controller( $settings, $repository, $this->client, $locator );
	}

	/**
	 * Result for the locator double.
	 */
	public function relocation(): ?LinkedFile {
		return $this->relocated;
	}

	/**
	 * Runs a request.
	 *
	 * @param int                   $id          Attachment ID.
	 * @param string                $variant     Variant.
	 * @param string                $file        File name.
	 * @param string                $method      Method.
	 * @param array<string, string> $conditional Conditional headers.
	 */
	private function request( int $id, string $variant, string $file, string $method = 'GET', array $conditional = array() ): RecordingWriter {
		$out = new RecordingWriter();
		$this->controller()->handle( $id, $variant, $file, $method, $conditional, $out );

		return $out;
	}

	public function test_match(): void {
		Functions\when( 'home_url' )->justReturn( 'http://example.org/blog/' );
		$controller = $this->controller();

		$this->assertSame(
			array(
				'id'      => 10,
				'variant' => '256x171',
				'file'    => 'forest-256x171.jpg',
			),
			$controller->match( '/blog/nextcloud-media/10/256x171/forest-256x171.jpg?ver=1' )
		);
		$this->assertSame( 'full', $controller->match( '/blog/index.php/nextcloud-media/10/full/forest.jpg' )['variant'] ?? null );
		foreach ( array(
			'/nextcloud-media/10/full/forest.jpg',
			'/blog/nextcloud-media/0/full/forest.jpg',
			'/blog/nextcloud-media/10/large/forest.jpg',
			'/blog/nextcloud-media/10/full/../x.jpg',
			'/blog/nextcloud-media/10/full/a b.jpg',
			'/blog/nextcloud-media/10/full/forest.jpg/extra',
			'/blog/other/10/full/forest.jpg',
		) as $uri ) {
			$this->assertNull( $controller->match( $uri ), $uri );
		}
	}

	public function test_streams_original_with_safe_headers_only(): void {
		$this->client->streams[ self::FOREST ] = array(
			200,
			array(
				'content-type'   => 'application/octet-stream',
				'content-length' => '6',
				'etag'           => '"abc"',
				'last-modified'  => 'Sun, 25 Oct 2026 13:09:17 GMT',
				'set-cookie'     => 'oc=1',
				'x-user-id'      => 'linker',
			),
			array( 'abc', 'def' ),
		);
		$out                                   = $this->request( 10, 'full', 'forest.jpg' );

		$this->assertSame( 200, $out->status );
		$this->assertSame( 'abcdef', $out->body );
		$this->assertSame( 2, $out->chunks );
		$this->assertSame(
			array(
				'content-type'           => 'image/jpeg',
				'content-length'         => '6',
				'cache-control'          => 'public, max-age=86400',
				'etag'                   => '"abc"',
				'last-modified'          => 'Sun, 25 Oct 2026 13:09:17 GMT',
				'x-content-type-options' => 'nosniff',
				'accept-ranges'          => 'none',
				'content-disposition'    => 'inline; filename="forest.jpg"; filename*=UTF-8\'\'forest.jpg',
			),
			$out->headers
		);
	}

	public function test_preview_type_comes_from_nextcloud_but_must_be_an_image(): void {
		$this->client->streams[ self::PREVIEW ] = array( 200, array( 'content-type' => 'image/png' ), array( 'png' ) );
		$this->assertSame( 'image/png', $this->request( 10, '256x171', 'forest-256x171.jpg' )->headers['content-type'] );

		$this->client->streams[ self::PREVIEW ] = array( 200, array( 'content-type' => 'image/svg+xml' ), array( '<svg onload="alert(1)"/>' ) );
		$out                                    = $this->request( 10, '256x171', 'forest-256x171.jpg' );
		$this->assertSame( 'image/svg+xml', $out->headers['content-type'] );
		$this->assertSame( Placeholder::svg( 256, 171 ), $out->body );
		$this->assertSame( 'no-store', $out->headers['cache-control'] );
	}

	public function test_not_modified(): void {
		$this->client->streams[ self::FOREST ] = array( 304, array( 'etag' => "\"bad\r\nX: y\"" ), array() );
		$out                                   = $this->request(
			10,
			'full',
			'forest.jpg',
			'GET',
			array(
				'If-None-Match'     => '"abc"',
				'If-Modified-Since' => 'not a date',
			)
		);

		$this->assertSame( 304, $out->status );
		$this->assertSame( '', $out->body );
		$this->assertArrayNotHasKey( 'etag', $out->headers );
		$this->assertSame( array( 'If-None-Match' => '"abc"' ), $this->client->last_stream_headers );
	}

	public function test_head_sends_no_body(): void {
		$this->client->streams[ self::FOREST ] = array( 200, array(), array( 'data' ) );
		$out                                   = $this->request( 10, 'full', 'forest.jpg', 'HEAD' );

		$this->assertSame( 200, $out->status );
		$this->assertSame( '', $out->body );
	}

	public function test_rejected_requests(): void {
		$this->assertSame( 405, $this->request( 10, 'full', 'forest.jpg', 'POST' )->status );
		$this->assertSame( 'GET, HEAD', $this->request( 10, 'full', 'forest.jpg', 'DELETE' )->headers['allow'] );
		$this->assertSame( 404, $this->request( 99, 'full', 'forest.jpg' )->status );
		$this->assertSame( 404, $this->request( 10, 'full', 'other.jpg' )->status );
		$this->assertSame( 404, $this->request( 10, '500x500', 'forest-500x500.jpg' )->status );
		$this->assertSame( 404, $this->request( 10, '256x171', 'forest-384x256.jpg' )->status );
		$this->assertSame( 404, $this->request( 11, '256x171', 'report-256x171.pdf' )->status );
		$this->assertSame( array(), $this->client->requests );

		// Type no longer allowed in the settings.
		Functions\when( 'get_option' )->justReturn( array_merge( Settings::defaults(), array( 'mime_types' => array( 'image/png' ) ) ) );
		$this->assertSame( 404, $this->request( 10, 'full', 'forest.jpg' )->status );
	}

	public function test_missing_preview_falls_back_to_small_original(): void {
		$this->client->streams[ self::FOREST ] = array( 200, array(), array( 'original' ) );
		Actions\expectDone( 'wpncl_file_unavailable' )->never();

		$out = $this->request( 10, '256x171', 'forest-256x171.jpg' );
		$this->assertSame( 'original', $out->body );
		$this->assertSame( 'image/jpeg', $out->headers['content-type'] );
	}

	public function test_missing_preview_of_large_original_shows_placeholder(): void {
		$this->files[10] = new LinkedFile( 102, Area::Folders, 'Shared With Linker/forest.jpg', 'image/jpeg', 'forest.jpg', 70000000, 2400, 1600 );
		Actions\expectDone( 'wpncl_file_unavailable' )->never();

		$out = $this->request( 10, '256x171', 'forest-256x171.jpg' );
		$this->assertSame( Placeholder::svg( 256, 171 ), $out->body );
		$this->assertSame( 'public, max-age=300', $out->headers['cache-control'] );
		$this->assertStringContainsString( "default-src 'none'", $out->headers['content-security-policy'] );
		$this->assertNotContains( 'STREAM ' . self::FOREST, $this->client->requests );
	}

	public function test_moved_file_is_relocated(): void {
		$this->relocated = new LinkedFile( 102, Area::Folders, 'New/forest.jpg', 'image/jpeg', 'forest.jpg', 85213, 2400, 1600 );
		$this->client->streams['/remote.php/dav/files/linker/New/forest.jpg'] = array( 200, array(), array( 'moved' ) );

		$this->assertSame( 'moved', $this->request( 10, 'full', 'forest.jpg' )->body );
	}

	public function test_missing_files(): void {
		Actions\expectDone( 'wpncl_file_unavailable' )->twice();

		$image = $this->request( 10, 'full', 'forest.jpg' );
		$this->assertSame( 200, $image->status );
		$this->assertSame( Placeholder::svg( 2400, 1600 ), $image->body );

		$document = $this->request( 11, 'full', 'Report-2026.pdf' );
		$this->assertSame( 404, $document->status );
	}

	public function test_unreachable_nextcloud(): void {
		$this->client->streams[ self::FOREST ]                                 = new TransportException( TransportException::TIMEOUT, 'timeout' );
		$this->client->streams['/remote.php/dav/files/linker/Docs/report.pdf'] = new TransportException( TransportException::TIMEOUT, 'timeout' );
		Actions\expectDone( 'wpncl_file_unavailable' )->never();

		$this->assertSame( 'no-store', $this->request( 10, 'full', 'forest.jpg' )->headers['cache-control'] );
		$this->assertSame( 502, $this->request( 11, 'full', 'Report-2026.pdf' )->status );
	}

	public function test_documents(): void {
		$this->client->streams['/remote.php/dav/files/linker/Docs/report.pdf'] = array( 200, array( 'content-type' => 'application/pdf' ), array( '%PDF' ) );
		$this->client->streams['/remote.php/dav/files/linker/Docs/table.docx'] = array( 200, array(), array( 'PK' ) );

		$pdf = $this->request( 11, 'full', 'Report-2026.pdf' );
		$this->assertSame( 'application/pdf', $pdf->headers['content-type'] );
		$this->assertSame( 'inline; filename="Report-2026.pdf"; filename*=UTF-8\'\'Report%202026.pdf', $pdf->headers['content-disposition'] );

		$docx = $this->request( 12, 'full', 'table.docx' );
		$this->assertStringStartsWith( 'attachment;', $docx->headers['content-disposition'] );
	}
}
