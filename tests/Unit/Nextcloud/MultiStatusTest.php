<?php
/**
 * Tests for the WebDAV multistatus parser.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Nextcloud;

use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Nextcloud\DavEntry;
use WpNcLinker\Nextcloud\InvalidResponseException;
use WpNcLinker\Nextcloud\MultiStatus;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Parsing of real Nextcloud 34 responses (recorded in the test environment).
 */
#[CoversClass( MultiStatus::class )]
#[CoversClass( DavEntry::class )]
final class MultiStatusTest extends TestCase {

	/**
	 * Parses a recorded response.
	 *
	 * @param string $name Fixture name.
	 * @return list<DavEntry>
	 */
	private function fixture( string $name ): array {
		$xml = file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/propfind-' . $name . '.xml' );
		$this->assertIsString( $xml );

		return MultiStatus::parse( $xml );
	}

	/**
	 * Entry by decoded name.
	 *
	 * @param list<DavEntry> $entries Entries.
	 * @param string         $name    Name.
	 */
	private function by_name( array $entries, string $name ): DavEntry {
		foreach ( $entries as $entry ) {
			if ( $entry->name() === $name ) {
				return $entry;
			}
		}
		$this->fail( "Entry $name not found" );
	}

	public function test_file_tree_root(): void {
		$entries = $this->fixture( 'files-root' );

		$this->assertCount( 4, $entries );
		$this->assertTrue( $entries[0]->is_collection );
		$this->assertSame( 'linker', $entries[0]->name() );

		$shared = $this->by_name( $entries, 'Shared With Linker' );
		$this->assertTrue( $shared->is_collection );
		$this->assertTrue( $shared->download_allowed );
		$this->assertIsInt( $shared->file_id );

		$hidden = $this->by_name( $entries, 'Hidden Download' );
		$this->assertFalse( $hidden->download_allowed );
	}

	public function test_shared_albums(): void {
		$entries = $this->fixture( 'sharedalbums' );
		$albums  = array_slice( $entries, 1 );

		$this->assertCount( 2, $albums );
		$album = $this->by_name( $albums, 'Shared Album (content)' );
		$this->assertTrue( $album->is_collection );
		$this->assertSame( 'Shared Album (content)', $album->label() );
		$this->assertSame( 3, $album->item_count );
		$this->assertNull( $album->file_id );
	}

	public function test_album_photos(): void {
		$entries = $this->fixture( 'album-shared' );
		$photos  = array_slice( $entries, 1 );

		$this->assertCount( 3, $photos );
		foreach ( $photos as $photo ) {
			$this->assertFalse( $photo->is_collection );
			$this->assertIsInt( $photo->file_id );
			$this->assertStringStartsWith( $photo->file_id . '-', $photo->name() );
			$this->assertTrue( $photo->is_image() );
			$this->assertTrue( $photo->has_preview );
			$this->assertNotNull( $photo->etag );
			$this->assertStringNotContainsString( '"', (string) $photo->etag );
			$this->assertIsInt( $photo->last_modified );
			$this->assertSame( $photo->file_id . '-' . $photo->display_name, $photo->name() );
		}

		$sizes = array();
		foreach ( $photos as $photo ) {
			$sizes[ $photo->label() ] = array( $photo->width, $photo->height, $photo->size );
		}
		$this->assertSame( 1600, $sizes['landscape.jpg'][0] );
		$this->assertSame( 1067, $sizes['landscape.jpg'][1] );
		$this->assertSame( array( 6000, 4000 ), array_slice( $sizes['large-original.jpg'], 0, 2 ) );
		$this->assertGreaterThan( 50 * 1024 * 1024, $sizes['large-original.jpg'][2] );
	}

	public function test_rejects_doctype(): void {
		$this->expectException( InvalidResponseException::class );

		MultiStatus::parse( '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><d:multistatus xmlns:d="DAV:"><d:response><d:href>&e;</d:href></d:response></d:multistatus>' );
	}

	public function test_rejects_other_documents(): void {
		foreach ( array( '', 'not xml', '<html/>', '<d:prop xmlns:d="DAV:"/>' ) as $body ) {
			try {
				MultiStatus::parse( $body );
				$this->fail( 'Accepted: ' . $body );
			} catch ( InvalidResponseException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_ignores_failed_propstats_and_bad_values(): void {
		$entries = MultiStatus::parse(
			'<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
			. '<d:response><d:href>/remote.php/dav/files/u/a%20b.jpg</d:href>'
			. '<d:propstat><d:prop><oc:fileid>-5</oc:fileid><nc:share-attributes>not json</nc:share-attributes>'
			. '<nc:metadata-photos-size><width>0</width><height>10</height></nc:metadata-photos-size></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
			. '<d:propstat><d:prop><d:getcontenttype>image/jpeg</d:getcontenttype></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>'
			. '</d:response><d:response><d:href></d:href></d:response></d:multistatus>'
		);

		$this->assertCount( 1, $entries );
		$this->assertSame( 'a b.jpg', $entries[0]->name() );
		$this->assertNull( $entries[0]->file_id );
		$this->assertNull( $entries[0]->content_type );
		$this->assertTrue( $entries[0]->download_allowed );
		$this->assertNull( $entries[0]->width );
	}

	public function test_request_bodies_are_well_formed(): void {
		$propfind = new \DOMDocument();
		$this->assertTrue( $propfind->loadXML( MultiStatus::propfind_body() ) );

		$search = MultiStatus::search_body( '/files/a&b', 'image/<x>', 20 );
		$doc    = new \DOMDocument();
		$this->assertTrue( $doc->loadXML( $search ) );
		$this->assertStringContainsString( '<d:href>/files/a&amp;b</d:href>', $search );
		$this->assertStringContainsString( '<d:literal>image/&lt;x&gt;%</d:literal>', $search );
		$this->assertStringContainsString( '<d:nresults>20</d:nresults>', $search );
	}
}
