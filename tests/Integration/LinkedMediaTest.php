<?php
/**
 * Integration tests for linked attachments (F4, acceptance tests 3, 4 and 13).
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use WpNcLinker\Browse\Area;
use WpNcLinker\Browse\Browser;
use WpNcLinker\Config\Settings;
use WpNcLinker\Media\Repository;
use WpNcLinker\Media\SizeCalculator;
use WpNcLinker\Plugin;

/**
 * Linking over REST and the behaviour of the resulting attachments in WordPress.
 */
#[CoversNothing]
final class LinkedMediaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->configure_connection();
		self::delete_linked_attachments();
	}

	protected function tearDown(): void {
		self::delete_linked_attachments();
		remove_image_size( 'wpncl_test_size' );
		parent::tearDown();
	}

	/**
	 * Login cookies and REST nonce of a new user.
	 *
	 * @param string $role Role.
	 * @return array{id: int, cookies: array<string, string>, nonce: string}
	 */
	private function session( string $role ): array {
		$user_id = $this->create_user( $role );
		$cookies = $this->login_cookies( $user_id );

		return array(
			'id'      => $user_id,
			'cookies' => $cookies,
			'nonce'   => $this->nonce( $user_id, $cookies, 'wp_rest' ),
		);
	}

	/**
	 * POST /attachments.
	 *
	 * @param array{id: int, cookies: array<string, string>, nonce: string} $session Session.
	 * @param list<array{area: string, path: string}>                       $items   Files.
	 * @param int                                                           $post_id Parent post.
	 * @return array{status: int, data: array<string, mixed>}
	 */
	private function link( array $session, array $items, int $post_id = 0 ): array {
		$response = $this->http(
			'POST',
			'/wp-json/wpncl/v1/attachments',
			$session['cookies'],
			null,
			array(
				'X-WP-Nonce: ' . $session['nonce'],
				'Content-Type: application/json',
			),
			(string) wp_json_encode(
				array(
					'items'   => $items,
					'post_id' => $post_id,
				)
			)
		);
		$data     = json_decode( $response['body'], true );

		return array(
			'status' => $response['status'],
			'data'   => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Path of an album photo as listed by the browser.
	 *
	 * @param Area   $area  Album area.
	 * @param string $album Album name.
	 * @param string $name  File name.
	 */
	private static function album_photo( Area $area, string $album, string $name ): string {
		$browser = new Browser( Plugin::instance()->settings(), Plugin::instance()->client() );
		foreach ( $browser->list( $area, $album, array(), true ) as $item ) {
			if ( $item->name === $name ) {
				return $item->path;
			}
		}
		self::fail( "$name not found in $album" );
	}

	/**
	 * Number of files below uploads.
	 */
	private static function upload_files(): int {
		$dir = wp_get_upload_dir()['basedir'];
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$count = 0;
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$count += $file->isFile() ? 1 : 0;
		}

		return $count;
	}

	public function test_link_folder_image_without_file(): void {
		$files_before = self::upload_files();
		$editor       = $this->session( 'editor' );
		$post_id      = wp_insert_post(
			array(
				'post_title'  => 'Linked media test',
				'post_author' => $editor['id'],
			)
		);
		$this->assertIsInt( $post_id );

		$response = $this->link(
			$editor,
			array(
				array(
					'area' => 'folders',
					'path' => 'Shared With Linker/forest.jpg',
				),
			),
			$post_id
		);
		$this->assertSame( 200, $response['status'] );
		$this->assertSame( array(), $response['data']['errors'] );
		$this->assertIsArray( $response['data']['attachments'] );
		$attachment = $response['data']['attachments'][0];
		$this->assertIsArray( $attachment );
		$id = (int) $attachment['id'];

		$this->assertSame( 'http://localhost:8080/nextcloud-media/' . $id . '/full/forest.jpg', $attachment['url'] );
		$this->assertSame( 2400, $attachment['width'] );
		$this->assertSame( 1600, $attachment['height'] );
		$this->assertSame( 'image/jpeg', $attachment['mime'] );
		$this->assertArrayNotHasKey( 'edit', (array) $attachment['nonces'] );
		$this->assertIsArray( $attachment['sizes'] );
		$this->assertSame( 'http://localhost:8080/nextcloud-media/' . $id . '/1024x683/forest-1024x683.jpg', $attachment['sizes']['large']['url'] );

		$this->assertSame( $post_id, wp_get_post_parent_id( $id ) );
		$this->assertSame( '', get_post_meta( $id, '_wp_attached_file', true ) );
		$this->assertFalse( get_attached_file( $id ) );
		$this->assertSame( $files_before, self::upload_files() );

		$thumbnail = wp_get_attachment_image_src( $id, 'thumbnail' );
		$this->assertSame( array( 'http://localhost:8080/nextcloud-media/' . $id . '/256x171/forest-256x171.jpg', 256, 171, true ), $thumbnail );

		wp_delete_post( $post_id, true );
	}

	public function test_same_file_is_linked_once(): void {
		$editor = $this->session( 'editor' );
		$item   = array(
			'area' => 'folders',
			'path' => 'Shared With Linker/harbor.jpg',
		);

		$first  = $this->link( $editor, array( $item ) )['data']['attachments'];
		$second = $this->link( $editor, array( $item, $item ) )['data']['attachments'];
		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( $first[0]['id'], $second[0]['id'] );
		$this->assertSame( $first[0]['id'], $second[1]['id'] );

		$repository = new Repository();
		$file       = $repository->get( (int) $first[0]['id'] );
		$this->assertNotNull( $file );
		$this->assertSame( (int) $first[0]['id'], $repository->find_by_file_id( $file->file_id ) );
	}

	public function test_link_album_photos_and_document(): void {
		$author   = $this->session( 'author' );
		$response = $this->link(
			$author,
			array(
				array(
					'area' => 'albums',
					'path' => self::album_photo( Area::Albums, 'Linker Album', 'linker-photo.jpg' ),
				),
				array(
					'area' => 'sharedalbums',
					'path' => self::album_photo( Area::SharedAlbums, 'Shared Album (content)', 'landscape.jpg' ),
				),
				array(
					'area' => 'sharedalbums',
					'path' => self::album_photo( Area::SharedAlbums, 'Group Album (content)', 'square.png' ),
				),
				array(
					'area' => 'folders',
					'path' => 'Shared With Linker/sample.pdf',
				),
			)
		);

		$this->assertSame( array(), $response['data']['errors'] );
		$attachments = $response['data']['attachments'];
		$this->assertIsArray( $attachments );
		$this->assertSame( array( 'linker-photo.jpg', 'landscape.jpg', 'square.png', 'sample.pdf' ), array_column( $attachments, 'filename' ) );
		$this->assertSame( array( 1600, 1067 ), array( $attachments[1]['width'], $attachments[1]['height'] ) );
		$this->assertSame( 'image/png', $attachments[2]['mime'] );

		$pdf = $attachments[3];
		$this->assertSame( 'application/pdf', $pdf['mime'] );
		$this->assertStringEndsWith( '/full/sample.pdf', $pdf['url'] );
		$this->assertSame( 605, $pdf['filesizeInBytes'] );
		$this->assertArrayNotHasKey( 'sizes', $pdf );
	}

	public function test_rejected_files(): void {
		$this->set_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'mime_types' => array( 'image/jpeg' ) ) ) );
		$editor   = $this->session( 'editor' );
		$response = $this->link(
			$editor,
			array(
				array(
					'area' => 'folders',
					'path' => 'Hidden Download/no-download.jpg',
				),
				array(
					'area' => 'folders',
					'path' => 'Shared With Linker/Subfolder/night.png',
				),
				array(
					'area' => 'folders',
					'path' => 'Shared With Linker/missing.jpg',
				),
				array(
					'area' => 'folders',
					'path' => '../content/Photos/landscape.jpg',
				),
				array(
					'area' => 'places',
					'path' => 'x.jpg',
				),
			)
		);

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( array(), $response['data']['attachments'] );
		$this->assertIsArray( $response['data']['errors'] );
		$this->assertSame(
			array( 'download_restricted', 'type_not_allowed', 'not_found', 'invalid', 'invalid' ),
			array_column( $response['data']['errors'], 'code' )
		);
	}

	public function test_permissions(): void {
		$item = array(
			array(
				'area' => 'folders',
				'path' => 'Shared With Linker/forest.jpg',
			),
		);
		$this->assertSame( 403, $this->link( $this->session( 'subscriber' ), $item )['status'] );

		$anonymous = $this->http( 'POST', '/wp-json/wpncl/v1/attachments', array(), null, array( 'Content-Type: application/json' ), (string) wp_json_encode( array( 'items' => $item ) ) );
		$this->assertSame( 401, $anonymous['status'] );

		// An author cannot attach to someone else's post; the file is linked without parent.
		$admin_post = wp_insert_post(
			array(
				'post_title'  => 'Admin post',
				'post_author' => $this->create_user( 'administrator' ),
			)
		);
		$this->assertIsInt( $admin_post );
		$response = $this->link( $this->session( 'author' ), $item, $admin_post );
		$this->assertIsArray( $response['data']['attachments'] );
		$attachment_id = (int) $response['data']['attachments'][0]['id'];
		$this->assertSame( 0, wp_get_post_parent_id( $attachment_id ) );
		wp_delete_post( $admin_post, true );

		// The Nextcloud source is part of the media library data only for users who may link files.
		wp_set_current_user( $this->create_user( 'author' ) );
		$this->assertArrayHasKey( 'wpncl', (array) wp_prepare_attachment_for_js( $attachment_id ) );
		$restrict = static fn (): string => 'manage_options';
		add_filter( 'wpncl_select_capability', $restrict );
		$this->assertArrayNotHasKey( 'wpncl', (array) wp_prepare_attachment_for_js( $attachment_id ) );
		remove_filter( 'wpncl_select_capability', $restrict );
		wp_set_current_user( 0 );
	}

	public function test_blocks_render_proxy_srcset(): void {
		$attachments = $this->link(
			$this->session( 'editor' ),
			array(
				array(
					'area' => 'folders',
					'path' => 'Shared With Linker/forest.jpg',
				),
				array(
					'area' => 'sharedalbums',
					'path' => self::album_photo( Area::SharedAlbums, 'Shared Album (content)', 'portrait.jpg' ),
				),
			)
		)['data']['attachments'];
		$this->assertIsArray( $attachments );
		[ $forest, $portrait ] = array( (int) $attachments[0]['id'], (int) $attachments[1]['id'] );

		$image = sprintf(
			'<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
			$forest,
			(string) wp_get_attachment_image_url( $forest, 'large' )
		);
		$html  = (string) apply_filters( 'the_content', $image ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, renders blocks like a theme.

		$this->assertStringContainsString( 'width="1024" height="683"', $html );
		$this->assertMatchesRegularExpression( '#srcset="[^"]*/nextcloud-media/' . $forest . '/256x171/forest-256x171\.jpg 256w#', $html );
		$this->assertStringContainsString( '/nextcloud-media/' . $forest . '/1536x1024/forest-1536x1024.jpg 1536w', $html );
		$this->assertStringNotContainsString( '/wp-content/uploads/', $html );

		$gallery = '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped">';
		foreach ( array( $forest, $portrait ) as $id ) {
			$gallery .= sprintf(
				'<!-- wp:image {"id":%1$d,"sizeSlug":"medium","linkDestination":"none"} --><figure class="wp-block-image size-medium"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
				$id,
				(string) wp_get_attachment_image_url( $id, 'medium' )
			);
		}
		$gallery .= '</figure><!-- /wp:gallery -->';
		$html     = (string) apply_filters( 'the_content', $gallery ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, renders blocks like a theme.

		$this->assertSame( 2, substr_count( $html, 'srcset=' ) );
		$this->assertStringContainsString( '/nextcloud-media/' . $portrait . '/256x384/portrait-256x384.jpg', $html );
		$this->assertStringNotContainsString( '/wp-content/uploads/', $html );
	}

	public function test_sizes_follow_registered_image_sizes(): void {
		$attachments = $this->link(
			$this->session( 'editor' ),
			array(
				array(
					'area' => 'folders',
					'path' => 'Shared With Linker/forest.jpg',
				),
			)
		)['data']['attachments'];
		$this->assertIsArray( $attachments );
		$id = (int) $attachments[0]['id'];

		add_image_size( 'wpncl_test_size', 500, 500, true );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertIsArray( $meta );
		$expected = ( new SizeCalculator() )->delivered( 2400, 1600, 500, 500 );
		$this->assertSame( $expected, array( $meta['sizes']['wpncl_test_size']['width'], $meta['sizes']['wpncl_test_size']['height'] ) );
	}

	public function test_deleting_never_touches_files(): void {
		$attachments = $this->link(
			$this->session( 'editor' ),
			array(
				array(
					'area' => 'folders',
					'path' => 'Shared With Linker/forest.jpg',
				),
			)
		)['data']['attachments'];
		$this->assertIsArray( $attachments );
		$files_before = self::upload_files();

		$deleted = array();
		$capture = static function ( $file ) use ( &$deleted ) {
			$deleted[] = $file;
			return $file;
		};
		add_filter( 'wp_delete_file', $capture, 100 );
		try {
			$this->assertNotFalse( wp_delete_attachment( (int) $attachments[0]['id'], true ) );
		} finally {
			remove_filter( 'wp_delete_file', $capture, 100 );
		}

		$this->assertSame( array(), array_filter( $deleted ) );
		$this->assertSame( $files_before, self::upload_files() );
	}
}
