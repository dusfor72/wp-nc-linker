<?php
/**
 * Network-level failure of a request to Nextcloud.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Nextcloud;

defined( 'ABSPATH' ) || exit;

/**
 * The request did not produce an HTTP response (DNS, connection, TLS, timeout).
 */
final class TransportException extends \RuntimeException {

	public const DNS     = 'dns';
	public const CONNECT = 'connect';
	public const TLS     = 'tls';
	public const TIMEOUT = 'timeout';
	public const BLOCKED = 'blocked';
	public const OTHER   = 'other';

	/**
	 * Error numbers of cURL per kind (see https://curl.se/libcurl/c/libcurl-errors.html).
	 */
	private const CURL_CODES = array(
		self::DNS     => array( 6 ),
		self::CONNECT => array( 7 ),
		self::TIMEOUT => array( 28 ),
		self::TLS     => array( 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98 ),
	);

	/**
	 * Constructor.
	 *
	 * @param string $kind    One of the class constants.
	 * @param string $message Technical message (contains no credentials).
	 */
	public function __construct( public readonly string $kind, string $message ) {
		parent::__construct( $message );
	}

	/**
	 * Classifies a transport error message of the WordPress HTTP API.
	 *
	 * @param string $message Error message.
	 */
	public static function from_message( string $message ): self {
		if ( preg_match( '/cURL error (\d+)/', $message, $matches ) ) {
			$code = (int) $matches[1];
			foreach ( self::CURL_CODES as $kind => $codes ) {
				if ( in_array( $code, $codes, true ) ) {
					return new self( $kind, $message );
				}
			}
		}

		$lower = strtolower( $message );
		$kind  = match ( true ) {
			str_contains( $lower, 'blocked requests through http' ) => self::BLOCKED,
			str_contains( $lower, 'ssl' ) || str_contains( $lower, 'certificate' ) || str_contains( $lower, 'tls' ) => self::TLS,
			str_contains( $lower, 'timed out' ) || str_contains( $lower, 'timeout' ) => self::TIMEOUT,
			str_contains( $lower, 'resolve' ) => self::DNS,
			default => self::OTHER,
		};

		return new self( $kind, $message );
	}
}
