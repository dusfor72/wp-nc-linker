<?php
/**
 * Integration tests for documents (F6, acceptance test 10).
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
use WpNcLinker\Media\Repository;
use WpNcLinker\Plugin;

/**
 * PDF and office documents: linking, delivery headers and the file block.
 */
#[CoversNothing]
final class DocumentsTest extends TestCase {

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
	 * Links a file of "Shared With Linker".
	 *
	 * @param string $path Path below the shared folder.
	 */
	private static function link( string $path ): int {
		$plugin = Plugin::instance();

		return ( new Importer( $plugin->settings(), $plugin->client(), new Repository() ) )->import( 'folders', 'Shared With Linker/' . $path, 0 );
	}

	/**
	 * Path of an attachment URL.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function path( int $id ): string {
		return (string) wp_parse_url( (string) wp_get_attachment_url( $id ), PHP_URL_PATH );
	}

	public function test_office_documents_are_downloads(): void {
		$cases = array(
			'Subfolder/notes.odt'         => array( 'application/vnd.oasis.opendocument.text', 'attachment; filename="notes.odt"; filename*=UTF-8\'\'notes.odt', 'Subfolder/notes.odt' ),
			'Subfolder/Table Report.docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'attachment; filename="Table-Report.docx"; filename*=UTF-8\'\'Table%20Report.docx', 'Subfolder/Table%20Report.docx' ),
		);

		foreach ( $cases as $path => [ $type, $disposition, $encoded ] ) {
			$id   = self::link( $path );
			$data = wp_prepare_attachment_for_js( $id );
			$this->assertIsArray( $data );
			$this->assertSame( $type, $data['mime'] );
			$this->assertSame( 'application', $data['type'] );
			$this->assertArrayNotHasKey( 'sizes', $data );
			$this->assertNotEmpty( $data['icon'] );

			$response = $this->http( 'GET', self::path( $id ) );
			$this->assertSame( 200, $response['status'], $path );
			$this->assertSame( $type, $response['headers']['content-type'] ?? null );
			$this->assertSame( $disposition, $response['headers']['content-disposition'] ?? null );
			$this->assertSame( 'nosniff', $response['headers']['x-content-type-options'] ?? null );

			$original = self::nextcloud( 'GET', '/remote.php/dav/files/linker/Shared%20With%20Linker/' . $encoded, 'linker', self::app_password() );
			$this->assertSame( sha1( $original['body'] ), sha1( $response['body'] ) );
			$this->assertStringStartsWith( 'PK', $response['body'] );
		}
	}

	public function test_pdf_is_shown_inline(): void {
		$id       = self::link( 'sample.pdf' );
		$response = $this->http( 'GET', self::path( $id ) );

		$this->assertSame( 'application/pdf', $response['headers']['content-type'] ?? null );
		$this->assertStringStartsWith( 'inline;', $response['headers']['content-disposition'] ?? '' );
	}

	public function test_file_block_renders_proxy_links(): void {
		$id    = self::link( 'sample.pdf' );
		$url   = (string) wp_get_attachment_url( $id );
		$block = sprintf(
			'<!-- wp:file {"id":%1$d,"href":"%2$s","displayPreview":true} --><div class="wp-block-file"><object class="wp-block-file__embed" data="%2$s" type="application/pdf" style="width:100%%;height:600px" aria-label="sample"></object><a id="wp-block-file--media-x" href="%2$s">sample</a><a href="%2$s" class="wp-block-file__button wp-element-button" download aria-describedby="wp-block-file--media-x">Download</a></div><!-- /wp:file -->',
			$id,
			esc_url( $url )
		);
		$html  = (string) apply_filters( 'the_content', $block ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, renders blocks like a theme.

		$this->assertStringContainsString( 'data="' . $url . '"', $html );
		$this->assertSame( 3, substr_count( $html, $url ) );
		$this->assertStringNotContainsString( '/wp-content/uploads/', $html );
	}

	public function test_type_settings_apply_to_documents(): void {
		$id = self::link( 'Subfolder/notes.odt' );
		$this->set_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'mime_types' => array( 'application/pdf' ) ) ) );

		$names = array_map(
			static fn ( $item ): string => $item->name,
			( new Browser( Plugin::instance()->settings(), Plugin::instance()->client() ) )->list( Area::Folders, 'Shared With Linker/Subfolder', array(), true )
		);
		$this->assertNotContains( 'notes.odt', $names );
		$this->assertSame( 404, $this->http( 'GET', self::path( $id ) )['status'] );
	}
}
