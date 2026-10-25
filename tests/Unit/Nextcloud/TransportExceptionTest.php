<?php
/**
 * Tests for the classification of transport errors.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Nextcloud;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WpNcLinker\Nextcloud\TransportException;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Error messages of the WordPress HTTP API.
 */
#[CoversClass( TransportException::class )]
final class TransportExceptionTest extends TestCase {

	/**
	 * Messages and expected kinds.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function messages(): array {
		return array(
			'unknown CA'   => array( 'cURL error 60: SSL certificate problem: unable to get local issuer certificate', TransportException::TLS ),
			'handshake'    => array( 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL', TransportException::TLS ),
			'dns'          => array( 'cURL error 6: Could not resolve host: cloud.example.org', TransportException::DNS ),
			'refused'      => array( 'cURL error 7: Failed to connect to cloud.example.org port 443', TransportException::CONNECT ),
			'timeout'      => array( 'cURL error 28: Operation timed out after 15001 milliseconds', TransportException::TIMEOUT ),
			'blocked'      => array( 'User has blocked requests through HTTP.', TransportException::BLOCKED ),
			'fsock ssl'    => array( 'stream_socket_client(): SSL operation failed', TransportException::TLS ),
			'other curl'   => array( 'cURL error 52: Empty reply from server', TransportException::OTHER ),
			'unclassified' => array( 'Something else', TransportException::OTHER ),
		);
	}

	#[DataProvider( 'messages' )]
	public function test_classification( string $message, string $kind ): void {
		$exception = TransportException::from_message( $message );

		$this->assertSame( $kind, $exception->kind );
		$this->assertSame( $message, $exception->getMessage() );
	}
}
