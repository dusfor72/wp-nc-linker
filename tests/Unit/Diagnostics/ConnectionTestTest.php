<?php
/**
 * Tests for the connection test.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Diagnostics;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\Settings;
use WpNcLinker\Diagnostics\CheckResult;
use WpNcLinker\Diagnostics\ConnectionTest;
use WpNcLinker\Diagnostics\Status;
use WpNcLinker\Nextcloud\MultiStatus;
use WpNcLinker\Nextcloud\Response;
use WpNcLinker\Nextcloud\TransportException;
use WpNcLinker\Tests\Unit\Config\FakeConstants;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Checks, their order and their messages.
 */
#[CoversClass( ConnectionTest::class )]
#[CoversClass( CheckResult::class )]
#[CoversClass( Status::class )]
final class ConnectionTestTest extends TestCase {

	private const FILES  = 'PROPFIND /remote.php/dav/files/linker/';
	private const ALBUMS = 'PROPFIND /remote.php/dav/photos/linker/albums/ 1';
	private const SHARED = 'PROPFIND /remote.php/dav/photos/linker/sharedalbums/ 1';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( '_n' )->alias( static fn ( string $single, string $plural, int $n ): string => 1 === $n ? $single : $plural );
	}

	/**
	 * Complete settings from constants.
	 */
	private static function settings(): Settings {
		return new Settings(
			new FakeConstants(
				array(
					'WPNCL_URL'          => 'https://cloud.example.org',
					'WPNCL_USER'         => 'linker',
					'WPNCL_APP_PASSWORD' => 'app-secret',
				)
			),
			new Crypto( static fn (): string => 'salt' )
		);
	}

	/**
	 * Recorded multistatus response.
	 *
	 * @param string $name Fixture name.
	 */
	private static function fixture( string $name ): Response {
		$xml = file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/propfind-' . $name . '.xml' );

		return new Response( 207, array(), (string) $xml );
	}

	/**
	 * Multistatus with only the collection itself.
	 *
	 * @param string $href Collection href.
	 */
	private static function empty_collection( string $href ): Response {
		return new Response(
			207,
			array(),
			'<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>' . $href . '</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>'
		);
	}

	/**
	 * Answers of a working server.
	 *
	 * @return array<string, Response|\Throwable>
	 */
	private static function working(): array {
		$search = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
			. '<d:response><d:href>/remote.php/dav/files/linker/Nested/Hidden/x.jpg</d:href><d:propstat><d:prop><oc:fileid>7</oc:fileid><d:getcontenttype>image/jpeg</d:getcontenttype><nc:has-preview>true</nc:has-preview>'
			. '<nc:share-attributes>[{"scope":"permissions","key":"download","value":false}]</nc:share-attributes></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
			. '<d:response><d:href>/remote.php/dav/files/linker/Shared%20With%20Linker/forest.jpg</d:href><d:propstat><d:prop><oc:fileid>102</oc:fileid><d:getcontenttype>image/jpeg</d:getcontenttype><nc:has-preview>true</nc:has-preview>'
			. '<nc:share-attributes>[]</nc:share-attributes></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';

		return array(
			'GET /status.php'  => new Response( 200, array(), '{"installed":true,"maintenance":false,"versionstring":"34.0.4"}' ),
			self::FILES . ' 0' => self::empty_collection( '/remote.php/dav/files/linker/' ),
			self::FILES . ' 1' => self::fixture( 'files-root' ),
			self::ALBUMS       => self::empty_collection( '/remote.php/dav/photos/linker/albums/' ),
			self::SHARED       => self::fixture( 'sharedalbums' ),
			'SEARCH image/'    => new Response( 207, array(), $search ),
			'PREVIEW 102'      => new Response( 200, array( 'content-type' => 'image/jpeg' ), 'jpeg-bytes' ),
		);
	}

	/**
	 * Runs the test.
	 *
	 * @param FakeClient    $client   Client.
	 * @param Settings|null $settings Settings.
	 * @return array<string, CheckResult>
	 */
	private static function run_test( FakeClient $client, ?Settings $settings = null ): array {
		$results = ( new ConnectionTest( $settings ?? self::settings(), $client ) )->run();
		$by_id   = array();
		foreach ( $results as $result ) {
			$by_id[ $result->id ] = $result;
		}

		return $by_id;
	}

	public function test_working_connection(): void {
		$results = self::run_test( new FakeClient( self::working() ) );

		$this->assertSame(
			array(
				'configuration'        => 'ok',
				'server'               => 'ok',
				'login'                => 'ok',
				'files'                => 'ok',
				'albums'               => 'ok',
				'shared_albums'        => 'ok',
				'download_restriction' => 'warning',
				'preview'              => 'ok',
			),
			array_map( static fn ( CheckResult $r ): string => $r->status->value, $results )
		);
		$this->assertStringContainsString( '34.0.4', $results['server']->message );
		$this->assertStringContainsString( '3 entries', $results['files']->message );
		$this->assertStringContainsString( '0 own albums', $results['albums']->message );
		$this->assertStringContainsString( '2 albums shared', $results['shared_albums']->message );
		$this->assertStringContainsString( 'Hidden Download, Nested', $results['download_restriction']->message );
		$this->assertStringContainsString( 'forest.jpg', $results['preview']->message );
		$this->assertFalse( ConnectionTest::has_errors( array_values( $results ) ) );
	}

	public function test_missing_configuration_stops(): void {
		$client   = new FakeClient( self::working() );
		$settings = new Settings( new FakeConstants( array( 'WPNCL_URL' => 'http://insecure.example.org' ) ), new Crypto( static fn (): string => 'salt' ) );
		$results  = self::run_test( $client, $settings );

		$this->assertSame( array( 'configuration' ), array_keys( $results ) );
		$this->assertSame( Status::Error, $results['configuration']->status );
		$this->assertStringContainsString( 'invalid value in wp-config.php', $results['configuration']->message );
		$this->assertStringContainsString( 'user name', $results['configuration']->message );
		$this->assertSame( array(), $client->requests );
	}

	public function test_failed_login_stops_further_requests(): void {
		$answers                       = self::working();
		$answers[ self::FILES . ' 0' ] = new Response( 401, array(), '' );
		$client                        = new FakeClient( $answers );
		$results                       = self::run_test( $client );

		$this->assertSame( array( 'configuration', 'server', 'login' ), array_keys( $results ) );
		$this->assertSame( Status::Error, $results['login']->status );
		$this->assertStringContainsString( 'HTTP 401', $results['login']->message );
		$this->assertSame( array( 'GET /status.php', self::FILES . ' 0' ), $client->requests );
	}

	public function test_unknown_account(): void {
		$answers                       = self::working();
		$answers[ self::FILES . ' 0' ] = new Response( 404, array(), '' );
		$results                       = self::run_test( new FakeClient( $answers ) );

		$this->assertStringContainsString( 'account ID', $results['login']->message );
	}

	public function test_tls_error(): void {
		$answers                    = self::working();
		$answers['GET /status.php'] = TransportException::from_message( 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' );
		$results                    = self::run_test( new FakeClient( $answers ) );

		$this->assertSame( array( 'configuration', 'server' ), array_keys( $results ) );
		$this->assertStringContainsString( 'TLS certificate', $results['server']->message );
		$this->assertStringContainsString( 'unable to get local issuer certificate', $results['server']->message );
	}

	public function test_blocked_external_requests(): void {
		$answers                    = self::working();
		$answers['GET /status.php'] = TransportException::from_message( 'User has blocked requests through HTTP.' );
		$results                    = self::run_test( new FakeClient( $answers ) );

		$this->assertStringContainsString( 'WP_ACCESSIBLE_HOSTS', $results['server']->message );
	}

	public function test_redirect_is_not_followed(): void {
		$answers                    = self::working();
		$answers['GET /status.php'] = new Response( 301, array( 'location' => 'https://other.example.net/status.php' ), '' );
		$results                    = self::run_test( new FakeClient( $answers ) );

		$this->assertSame( Status::Error, $results['server']->status );
		$this->assertStringContainsString( 'other.example.net', $results['server']->message );
	}

	public function test_no_nextcloud_and_maintenance(): void {
		$answers                    = self::working();
		$answers['GET /status.php'] = new Response( 200, array(), '<html>' );
		$this->assertStringContainsString( 'No Nextcloud installation', self::run_test( new FakeClient( $answers ) )['server']->message );

		$answers['GET /status.php'] = new Response( 200, array(), '{"installed":true,"maintenance":true}' );
		$this->assertStringContainsString( 'maintenance mode', self::run_test( new FakeClient( $answers ) )['server']->message );
	}

	public function test_old_version_warns(): void {
		$answers                    = self::working();
		$answers['GET /status.php'] = new Response( 200, array(), '{"installed":true,"maintenance":false,"versionstring":"33.0.1"}' );
		$results                    = self::run_test( new FakeClient( $answers ) );

		$this->assertSame( Status::Warning, $results['server']->status );
		$this->assertSame( Status::Ok, $results['login']->status );
	}

	public function test_missing_photos_app_warns(): void {
		$answers                 = self::working();
		$answers[ self::ALBUMS ] = new Response( 404, array(), '' );
		$answers[ self::SHARED ] = new Response( 404, array(), '' );
		$results                 = self::run_test( new FakeClient( $answers ) );

		$this->assertSame( Status::Warning, $results['albums']->status );
		$this->assertSame( Status::Warning, $results['shared_albums']->status );
		$this->assertStringContainsString( 'Photos app', $results['albums']->message );
		$this->assertSame( Status::Ok, $results['preview']->status );
	}

	public function test_preview_falls_back_to_album_photo(): void {
		$answers                  = self::working();
		$answers['SEARCH image/'] = new Response( 501, array(), '' );
		$answers['PROPFIND /remote.php/dav/photos/linker/sharedalbums/Group%20Album%20%28content%29/ 1']  = self::empty_collection( '/x/' );
		$answers['PROPFIND /remote.php/dav/photos/linker/sharedalbums/Shared%20Album%20%28content%29/ 1'] = self::fixture( 'album-shared' );
		$photos                                      = MultiStatus::parse( self::fixture( 'album-shared' )->body );
		$answers[ 'PREVIEW ' . $photos[1]->file_id ] = new Response( 200, array( 'content-type' => 'image/jpeg' ), 'x' );
		$results                                     = self::run_test( new FakeClient( $answers ) );

		$this->assertSame( Status::Ok, $results['preview']->status );
		$this->assertStringContainsString( $photos[1]->label(), $results['preview']->message );
		$this->assertStringNotContainsString( 'Nested', $results['download_restriction']->message );
	}

	public function test_missing_preview_is_an_error(): void {
		$answers                = self::working();
		$answers['PREVIEW 102'] = new Response( 404, array(), '[]' );
		$results                = self::run_test( new FakeClient( $answers ) );

		$this->assertSame( Status::Error, $results['preview']->status );
		$this->assertStringContainsString( 'HTTP 404', $results['preview']->message );
		$this->assertTrue( ConnectionTest::has_errors( array_values( $results ) ) );
	}

	public function test_no_sample_image_skips_preview(): void {
		$answers                  = self::working();
		$answers['SEARCH image/'] = new Response( 207, array(), '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"/>' );
		$answers[ self::SHARED ]  = self::empty_collection( '/remote.php/dav/photos/linker/sharedalbums/' );
		$results                  = self::run_test( new FakeClient( $answers ) );

		$this->assertSame( Status::Skipped, $results['preview']->status );
	}

	public function test_result_round_trip(): void {
		$result = new CheckResult( 'login', 'Login', Status::Warning, 'Message' );

		$this->assertEquals( $result, CheckResult::from_array( $result->to_array() ) );
		$this->assertNull( CheckResult::from_array( array( 'id' => 'x' ) ) );
		$this->assertNull( CheckResult::from_array( array_merge( $result->to_array(), array( 'status' => 'bogus' ) ) ) );
		$this->assertNull( CheckResult::from_array( 'string' ) );
	}
}
