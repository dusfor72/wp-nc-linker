<?php
/**
 * Tests for the Nextcloud HTTP client.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Nextcloud;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\Settings;
use WpNcLinker\Nextcloud\Client;
use WpNcLinker\Nextcloud\NotConfiguredException;
use WpNcLinker\Nextcloud\Response;
use WpNcLinker\Nextcloud\TransportException;
use WpNcLinker\Tests\Unit\Config\FakeConstants;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Request arguments, TLS enforcement and error handling.
 */
#[CoversClass( Client::class )]
#[CoversClass( Response::class )]
final class ClientTest extends TestCase {

	/**
	 * Arguments of the last wp_remote_request() call.
	 *
	 * @var array{url: string, args: array<string, mixed>}|null
	 */
	private ?array $last_request = null;

	/**
	 * Client with complete configuration (from constants).
	 *
	 * @param mixed $result Value returned by wp_remote_request().
	 */
	private function client( mixed $result ): Client {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( array $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn ( array $r ) => $r['headers'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $r ) => $r['body'] );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) use ( $result ) {
				$this->last_request = array(
					'url'  => $url,
					'args' => $args,
				);
				return $result;
			}
		);

		$settings = new Settings(
			new FakeConstants(
				array(
					'WPNCL_URL'          => 'https://cloud.example.org/nc',
					'WPNCL_USER'         => 'linker',
					'WPNCL_APP_PASSWORD' => 'app-secret',
				)
			),
			new Crypto( static fn (): string => 'salt' )
		);

		return new Client( $settings );
	}

	/**
	 * A successful HTTP API result.
	 *
	 * @param int                                $code    Status.
	 * @param array<string, string|list<string>> $headers Headers.
	 * @param string                             $body    Body.
	 * @return array<string, mixed>
	 */
	private static function http_result( int $code, array $headers = array(), string $body = '' ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => $headers,
			'body'     => $body,
		);
	}

	public function test_propfind_request(): void {
		$client   = $this->client( self::http_result( 207, array( 'Content-Type' => 'application/xml' ), '<xml/>' ) );
		$response = $client->propfind( '/remote.php/dav/files/linker/', 1 );

		$this->assertSame( 207, $response->status );
		$this->assertSame( 'application/xml', $response->header( 'content-type' ) );
		$this->assertNotNull( $this->last_request );
		$this->assertSame( 'https://cloud.example.org/nc/remote.php/dav/files/linker/', $this->last_request['url'] );

		$args = $this->last_request['args'];
		$this->assertSame( 'PROPFIND', $args['method'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( ABSPATH . WPINC . '/certificates/ca-bundle.crt', $args['sslcertificates'] );
		$this->assertSame( array(), $args['cookies'] );
		$this->assertSame( Client::MAX_LISTING_BYTES, $args['limit_response_size'] );
		$this->assertSame( '1', $args['headers']['Depth'] );
		$this->assertSame( 'Basic ' . base64_encode( 'linker:app-secret' ), $args['headers']['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Expected Basic auth header.
	}

	public function test_status_is_unauthenticated(): void {
		$client = $this->client( self::http_result( 200, array(), '{}' ) );
		$client->status();

		$this->assertNotNull( $this->last_request );
		$this->assertSame( 'https://cloud.example.org/nc/status.php', $this->last_request['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->last_request['args']['headers'] );
	}

	public function test_preview_url(): void {
		$client = $this->client( self::http_result( 200 ) );
		$client->preview( 42, 256, 128, 1000 );

		$this->assertNotNull( $this->last_request );
		$this->assertSame( 'https://cloud.example.org/nc/index.php/apps/photos/api/v1/preview/42?x=256&y=128', $this->last_request['url'] );
		$this->assertSame( 1000, $this->last_request['args']['limit_response_size'] );
	}

	public function test_multiple_header_values_are_joined(): void {
		$client   = $this->client( self::http_result( 200, array( 'Set-Cookie' => array( 'a=1', 'b=2' ) ) ) );
		$response = $client->status();

		$this->assertSame( 'a=1, b=2', $response->header( 'set-cookie' ) );
	}

	public function test_transport_error(): void {
		$client = $this->client( new \WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem' ) );

		try {
			$client->status();
			$this->fail( 'No exception' );
		} catch ( TransportException $e ) {
			$this->assertSame( TransportException::TLS, $e->kind );
		}
	}

	public function test_tls_cannot_be_disabled_by_filters(): void {
		$enforce_args   = null;
		$enforce_verify = null;
		Filters\expectAdded( 'http_request_args' )->once()->whenHappen(
			static function ( $callback ) use ( &$enforce_args ): void {
				$enforce_args = $callback;
			}
		);
		Filters\expectAdded( 'https_ssl_verify' )->once()->whenHappen(
			static function ( $callback ) use ( &$enforce_verify ): void {
				$enforce_verify = $callback;
			}
		);

		$this->client( self::http_result( 200 ) )->status();

		$this->assertIsCallable( $enforce_args );
		$this->assertIsCallable( $enforce_verify );
		$url      = 'https://cloud.example.org/nc/status.php';
		$filtered = $enforce_args(
			array(
				'sslverify'   => false,
				'redirection' => 5,
			),
			$url
		);
		$this->assertTrue( $filtered['sslverify'] );
		$this->assertSame( 0, $filtered['redirection'] );
		$this->assertSame( array( 'sslverify' => false ), $enforce_args( array( 'sslverify' => false ), 'https://other.example.net/' ) );
		$this->assertSame( ABSPATH . WPINC . '/certificates/ca-bundle.crt', $enforce_verify( false, $url ) );
		$this->assertFalse( $enforce_verify( false, 'https://other.example.net/' ) );

		$this->assertFalse( has_filter( 'http_request_args', $enforce_args ) );
		$this->assertFalse( has_filter( 'https_ssl_verify', $enforce_verify ) );
	}

	public function test_invalid_ca_bundle_filter_falls_back(): void {
		Filters\expectApplied( 'wpncl_ca_bundle' )->andReturn( '/does/not/exist.pem' );

		$this->assertSame( ABSPATH . WPINC . '/certificates/ca-bundle.crt', Client::ca_bundle() );
	}

	public function test_timeout_filter_is_validated(): void {
		Filters\expectApplied( 'wpncl_http_timeout' )->andReturn( 0, 30 );

		$this->assertSame( Client::DEFAULT_TIMEOUT, Client::timeout() );
		$this->assertSame( 30, Client::timeout() );
	}

	public function test_missing_configuration(): void {
		Functions\when( 'get_option' )->justReturn( false );
		$client = new Client( new Settings( new FakeConstants(), new Crypto( static fn (): string => 'salt' ) ) );

		$this->expectException( NotConfiguredException::class );
		$client->status();
	}
}
