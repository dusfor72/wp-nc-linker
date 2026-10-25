<?php
/**
 * HTTP client for the configured Nextcloud server.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Nextcloud;

use WpNcLinker\Config\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends buffered requests to the configured Nextcloud via the WordPress HTTP API.
 *
 * The target host always comes from the configuration; paths are built by DavPath. TLS
 * verification is enforced for these requests even if other code filters it off, redirects
 * are never followed and no cookies are sent or kept.
 */
class Client {

	public const DEFAULT_TIMEOUT = 15;

	public const MAX_LISTING_BYTES = 16777216;

	private const MAX_STATUS_BYTES = 65536;

	private const PREVIEW_PATH = '/index.php/apps/photos/api/v1/preview/';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Effective settings.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * GET /status.php (unauthenticated).
	 *
	 * @throws NotConfiguredException If the URL is missing.
	 * @throws TransportException If no HTTP response was received.
	 */
	public function status(): Response {
		return $this->send( 'GET', '/status.php', array(), null, false, self::MAX_STATUS_BYTES );
	}

	/**
	 * PROPFIND with the properties of MultiStatus::propfind_body().
	 *
	 * @param string $path  Path from DavPath.
	 * @param int    $depth 0 or 1.
	 * @throws NotConfiguredException If the connection is not configured.
	 * @throws TransportException If no HTTP response was received.
	 */
	public function propfind( string $path, int $depth ): Response {
		return $this->send(
			'PROPFIND',
			$path,
			array(
				'Depth'        => 0 === $depth ? '0' : '1',
				'Content-Type' => 'application/xml; charset=utf-8',
			),
			MultiStatus::propfind_body(),
			true,
			self::MAX_LISTING_BYTES
		);
	}

	/**
	 * SEARCH for files by MIME type prefix in the account's file tree.
	 *
	 * @param string $type_prefix MIME type prefix, e.g. "image/".
	 * @param int    $limit       Maximum number of results.
	 * @throws NotConfiguredException If the connection is not configured.
	 * @throws TransportException If no HTTP response was received.
	 */
	public function search( string $type_prefix, int $limit ): Response {
		return $this->send(
			'SEARCH',
			DavPath::root(),
			array( 'Content-Type' => 'text/xml; charset=utf-8' ),
			MultiStatus::search_body( DavPath::search_scope( $this->user() ), $type_prefix, $limit ),
			true,
			self::MAX_LISTING_BYTES
		);
	}

	/**
	 * SEARCH for a file by its file ID in the account's file tree.
	 *
	 * @param int $file_id File ID.
	 * @throws NotConfiguredException If the connection is not configured.
	 * @throws TransportException If no HTTP response was received.
	 */
	public function search_file_id( int $file_id ): Response {
		return $this->send(
			'SEARCH',
			DavPath::root(),
			array( 'Content-Type' => 'text/xml; charset=utf-8' ),
			MultiStatus::search_by_id_body( DavPath::search_scope( $this->user() ), $file_id ),
			true,
			self::MAX_LISTING_BYTES
		);
	}

	/**
	 * Path of the preview route for a file and box.
	 *
	 * @param int $file_id File ID.
	 * @param int $width   Maximum width.
	 * @param int $height  Maximum height.
	 */
	public static function preview_path( int $file_id, int $width, int $height ): string {
		$query = http_build_query(
			array(
				'x' => max( 1, $width ),
				'y' => max( 1, $height ),
			)
		);

		return self::PREVIEW_PATH . max( 0, $file_id ) . '?' . $query;
	}

	/**
	 * Streams a GET request with cURL without buffering the body (D-4).
	 *
	 * $on_headers receives the status and the response headers (lowercase names) once they are
	 * complete and returns whether the body is wanted; $on_body receives the body in chunks.
	 * Redirects are not followed; TLS is always verified.
	 *
	 * @param string                $path       Path below the base URL.
	 * @param array<string, string> $headers    Request headers.
	 * @param callable              $on_headers Called once with (int $status, array $headers): bool.
	 * @param callable              $on_body    Called with (string $chunk) for each chunk.
	 * @phpstan-param callable(int, array<string, string>): bool $on_headers
	 * @phpstan-param callable(string): void $on_body
	 * @return int HTTP status of the response.
	 * @throws NotConfiguredException If the connection is not configured.
	 * @throws TransportException If no complete response header was received.
	 */
	public function stream( string $path, array $headers, callable $on_headers, callable $on_body ): int {
		$base     = $this->settings->url() ?? throw new NotConfiguredException( 'URL not configured.' );
		$password = $this->settings->app_password() ?? throw new NotConfiguredException( 'App password not configured.' );
		$url      = $base . $path;

		if ( ( new \WP_Http() )->block_request( $url ) ) {
			throw new TransportException( TransportException::BLOCKED, 'User has blocked requests through HTTP.' );
		}

		$request_headers = array(
			'Authorization: Basic ' . base64_encode( $this->user() . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
			'User-Agent: WP-Nextcloud-Linker/' . WPNCL_VERSION,
		);
		foreach ( $headers as $name => $value ) {
			$request_headers[] = $name . ': ' . str_replace( array( "\r", "\n" ), '', $value );
		}

		$status           = 0;
		$response_headers = array();
		$headers_done     = false;
		$want_body        = false;

		// phpcs:disable WordPress.WP.AlternativeFunctions -- The WordPress HTTP API cannot stream without buffering or temp files (D-4).
		$handle = curl_init( $url );
		if ( false === $handle ) {
			throw new TransportException( TransportException::OTHER, 'cURL could not be initialised.' );
		}
		$options = array(
			CURLOPT_HTTPGET         => true,
			CURLOPT_HTTPHEADER      => $request_headers,
			CURLOPT_FOLLOWLOCATION  => false,
			CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
			CURLOPT_SSL_VERIFYPEER  => true,
			CURLOPT_SSL_VERIFYHOST  => 2,
			CURLOPT_CAINFO          => self::ca_bundle(),
			CURLOPT_CONNECTTIMEOUT  => self::timeout(),
			CURLOPT_LOW_SPEED_LIMIT => 1,
			CURLOPT_LOW_SPEED_TIME  => 60,
			CURLOPT_HEADERFUNCTION  => static function ( $curl, string $line ) use ( &$status, &$response_headers, &$headers_done, &$want_body, $on_headers ): int {
				$trimmed = trim( $line );
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $trimmed, $matches ) ) {
					$status           = (int) $matches[1];
					$response_headers = array();
				} elseif ( '' === $trimmed ) {
					// Interim responses (1xx) are followed by another status line.
					if ( $status >= 200 && ! $headers_done ) {
						$headers_done = true;
						$want_body    = (bool) $on_headers( $status, $response_headers );
					}
				} elseif ( str_contains( $trimmed, ':' ) ) {
					[ $name, $value ]                                  = explode( ':', $trimmed, 2 );
					$response_headers[ strtolower( trim( $name ) ) ] = trim( $value );
				}
				return strlen( $line );
			},
			CURLOPT_WRITEFUNCTION   => static function ( $curl, string $chunk ) use ( &$want_body, $on_body ): int {
				if ( ! $want_body ) {
					return -1;
				}
				$on_body( $chunk );
				return strlen( $chunk );
			},
		);

		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			$options[ CURLOPT_PROXY ]     = $proxy->host();
			$options[ CURLOPT_PROXYPORT ] = (int) $proxy->port();
			if ( $proxy->use_authentication() ) {
				$options[ CURLOPT_PROXYUSERPWD ] = $proxy->authentication();
			}
		}

		foreach ( $options as $option => $value ) {
			curl_setopt( $handle, $option, $value );
		}
		curl_exec( $handle );
		$errno = curl_errno( $handle );
		$error = curl_error( $handle );
		curl_close( $handle );
		// phpcs:enable

		if ( ! $headers_done ) {
			throw TransportException::from_message( 'cURL error ' . $errno . ': ' . $error );
		}

		return $status;
	}

	/**
	 * Buffered preview of a file (Photos preview route).
	 *
	 * @param int $file_id   File ID.
	 * @param int $width     Maximum width.
	 * @param int $height    Maximum height.
	 * @param int $max_bytes Response size limit.
	 * @throws NotConfiguredException If the connection is not configured.
	 * @throws TransportException If no HTTP response was received.
	 */
	public function preview( int $file_id, int $width, int $height, int $max_bytes ): Response {
		return $this->send( 'GET', self::preview_path( $file_id, $width, $height ), array(), null, true, $max_bytes );
	}

	/**
	 * First bytes of a file (Range request), e.g. to read image dimensions.
	 *
	 * @param string $path   WebDAV path from DavPath.
	 * @param int    $length Number of bytes.
	 * @throws NotConfiguredException If the connection is not configured.
	 * @throws TransportException If no HTTP response was received.
	 */
	public function head_bytes( string $path, int $length ): Response {
		$length = max( 1, $length );

		return $this->send( 'GET', $path, array( 'Range' => 'bytes=0-' . ( $length - 1 ) ), null, true, $length );
	}

	/**
	 * User ID of the connection account.
	 *
	 * @throws NotConfiguredException If it is not configured.
	 */
	public function user(): string {
		return $this->settings->user() ?? throw new NotConfiguredException( 'User not configured.' );
	}

	/**
	 * CA bundle used to verify the Nextcloud certificate.
	 *
	 * @return non-empty-string
	 */
	public static function ca_bundle(): string {
		$default = ABSPATH . WPINC . '/certificates/ca-bundle.crt';

		/**
		 * Filters the CA bundle used to verify the TLS certificate of Nextcloud.
		 *
		 * Verification itself cannot be disabled.
		 *
		 * @param string $path Absolute path to a PEM bundle. Default: the WordPress bundle.
		 */
		$path = apply_filters( 'wpncl_ca_bundle', $default );

		return is_string( $path ) && '' !== $path && is_readable( $path ) ? $path : $default;
	}

	/**
	 * Request timeout in seconds.
	 */
	public static function timeout(): int {
		/**
		 * Filters the timeout of requests to Nextcloud.
		 *
		 * @param int $seconds Default 15.
		 */
		$timeout = apply_filters( 'wpncl_http_timeout', self::DEFAULT_TIMEOUT );

		return is_int( $timeout ) && $timeout > 0 && $timeout <= 300 ? $timeout : self::DEFAULT_TIMEOUT;
	}

	/**
	 * Sends a request.
	 *
	 * @param string                $method    HTTP method.
	 * @param string                $path      Path below the base URL (starting with "/").
	 * @param array<string, string> $headers   Request headers.
	 * @param string|null           $body      Request body.
	 * @param bool                  $auth      Whether to send the credentials.
	 * @param int                   $max_bytes Response size limit.
	 * @throws NotConfiguredException If the connection is not configured.
	 * @throws TransportException If no HTTP response was received.
	 */
	private function send( string $method, string $path, array $headers, ?string $body, bool $auth, int $max_bytes ): Response {
		$base = $this->settings->url() ?? throw new NotConfiguredException( 'URL not configured.' );
		$url  = $base . $path;

		if ( $auth ) {
			$password                 = $this->settings->app_password() ?? throw new NotConfiguredException( 'App password not configured.' );
			$headers['Authorization'] = 'Basic ' . base64_encode( $this->user() . ':' . $password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
		}

		$ca_bundle = self::ca_bundle();
		$args      = array(
			'method'              => $method,
			'timeout'             => self::timeout(),
			'redirection'         => 0,
			'httpversion'         => '1.1',
			'user-agent'          => 'WP-Nextcloud-Linker/' . WPNCL_VERSION,
			'headers'             => $headers,
			'cookies'             => array(),
			'sslverify'           => true,
			'sslcertificates'     => $ca_bundle,
			'limit_response_size' => $max_bytes,
			'reject_unsafe_urls'  => false,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$enforce_args   = static function ( $parsed_args, $request_url ) use ( $url, $ca_bundle ) {
			if ( $request_url === $url && is_array( $parsed_args ) ) {
				$parsed_args['sslverify']       = true;
				$parsed_args['sslcertificates'] = $ca_bundle;
				$parsed_args['redirection']     = 0;
			}
			return $parsed_args;
		};
		$enforce_verify = static fn ( $verify, $request_url = '' ) => $request_url === $url ? $ca_bundle : $verify;
		$enforce_curl   = static function ( $handle, $parsed_args, $request_url ) use ( $url ): void {
			if ( $request_url === $url && ( $handle instanceof \CurlHandle ) ) {
				// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Re-enforces TLS verification on the WordPress HTTP API handle.
				curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, true );
				curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 2 );
				curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
				// phpcs:enable
			}
		};

		add_filter( 'http_request_args', $enforce_args, PHP_INT_MAX, 2 );
		add_filter( 'https_ssl_verify', $enforce_verify, PHP_INT_MAX, 2 );
		add_action( 'http_api_curl', $enforce_curl, PHP_INT_MAX, 3 );
		try {
			$result = wp_remote_request( $url, $args );
		} finally {
			remove_filter( 'http_request_args', $enforce_args, PHP_INT_MAX );
			remove_filter( 'https_ssl_verify', $enforce_verify, PHP_INT_MAX );
			remove_action( 'http_api_curl', $enforce_curl, PHP_INT_MAX );
		}

		if ( is_wp_error( $result ) ) {
			throw TransportException::from_message( $result->get_error_message() );
		}

		$response_headers = array();
		foreach ( wp_remote_retrieve_headers( $result ) as $name => $value ) {
			$response_headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return new Response(
			(int) wp_remote_retrieve_response_code( $result ),
			$response_headers,
			wp_remote_retrieve_body( $result )
		);
	}
}
