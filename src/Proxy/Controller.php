<?php
/**
 * Delivery of linked files.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Proxy;

use WpNcLinker\Config\Settings;
use WpNcLinker\Media\LinkedFile;
use WpNcLinker\Media\Locator;
use WpNcLinker\Media\Repository;
use WpNcLinker\Nextcloud\Client;
use WpNcLinker\Nextcloud\NotConfiguredException;
use WpNcLinker\Nextcloud\TransportException;

defined( 'ABSPATH' ) || exit;

/**
 * Serves /{prefix}/{id}/{variant}/{file} before WordPress parses the request (F5).
 *
 * Only registered linked attachments are served; the Nextcloud file ID and path always come
 * from the database, never from the URL, and variant and file name must match the metadata
 * exactly. Bodies are streamed; only a fixed set of headers reaches the client.
 */
final class Controller {

	/**
	 * Images without Nextcloud preview: sizes are served from the original up to this size (OP-8).
	 */
	public const DEFAULT_PREVIEW_FALLBACK_BYTES = 20971520;

	private const PREVIEW_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );

	private const PLACEHOLDER_MAX_AGE = 300;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings   Effective settings.
	 * @param Repository $repository Attachment storage.
	 * @param Client     $client     Nextcloud client.
	 * @param Locator    $locator    Finds moved files.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Repository $repository,
		private readonly Client $client,
		private readonly Locator $locator,
	) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_filter( 'do_parse_request', array( $this, 'intercept' ), 1 );
	}

	/**
	 * Serves proxy requests and ends the request; other requests continue normally.
	 *
	 * @param bool $do_parse Whether WordPress should parse the request.
	 */
	public function intercept( $do_parse ): bool {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Matched against a strict pattern.
		$match = $this->match( $uri );
		if ( null === $match ) {
			return (bool) $do_parse;
		}

		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$headers = array();
		foreach ( array(
			'If-None-Match'     => 'HTTP_IF_NONE_MATCH',
			'If-Modified-Since' => 'HTTP_IF_MODIFIED_SINCE',
		) as $name => $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$headers[ $name ] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}

		$this->handle( $match['id'], $match['variant'], $match['file'], $method, $headers, new HttpResponseWriter() );
		exit;
	}

	/**
	 * Parses a request URI.
	 *
	 * @param string $uri Request URI.
	 * @return array{id: int, variant: string, file: string}|null
	 */
	public function match( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) . '/';
		if ( ! str_starts_with( $path, $home ) ) {
			return null;
		}
		$path = substr( $path, strlen( $home ) );
		if ( str_starts_with( $path, 'index.php/' ) ) {
			$path = substr( $path, strlen( 'index.php/' ) );
		}

		$pattern = '#^' . preg_quote( $this->settings->proxy_prefix(), '#' ) . '/([1-9]\d{0,18})/(full|[1-9]\d{0,5}x[1-9]\d{0,5})/([A-Za-z0-9._-]{1,128})$#';
		if ( ! preg_match( $pattern, $path, $matches ) ) {
			return null;
		}

		return array(
			'id'      => (int) $matches[1],
			'variant' => $matches[2],
			'file'    => $matches[3],
		);
	}

	/**
	 * Serves a request.
	 *
	 * @param int                   $attachment_id Attachment ID from the URL.
	 * @param string                $variant       "full" or "{w}x{h}".
	 * @param string                $file          File name from the URL.
	 * @param string                $method        HTTP method.
	 * @param array<string, string> $conditional   If-None-Match / If-Modified-Since.
	 * @param ResponseWriter        $out           Output.
	 */
	public function handle( int $attachment_id, string $variant, string $file, string $method, array $conditional, ResponseWriter $out ): void {
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			$this->plain( $out, 405, array( 'Allow' => 'GET, HEAD' ) );
			return;
		}

		$linked = 'attachment' === get_post_type( $attachment_id ) ? $this->repository->get( $attachment_id ) : null;
		if ( null === $linked || ! in_array( $linked->mime, $this->settings->allowed_mime_types(), true ) ) {
			$this->plain( $out, 404 );
			return;
		}

		$size = $this->requested_size( $attachment_id, $linked, $variant, $file );
		if ( false === $size ) {
			$this->plain( $out, 404 );
			return;
		}

		$head        = 'HEAD' === $method;
		$conditional = self::safe_conditional( $conditional );

		// Whether Nextcloud reported the file itself as missing or forbidden. A missing preview
		// (404 from the preview route) does not mean that the file is missing.
		$missing = false;
		try {
			if ( null === $size ) {
				$status  = $this->serve_original( $attachment_id, $linked, $conditional, $head, $out );
				$missing = 403 === $status || 404 === $status;
			} else {
				$status  = $this->stream( Client::preview_path( $linked->file_id, $size[0], $size[1] ), $conditional, $linked, true, $head, $out );
				$missing = 403 === $status;
				if ( 404 === $status && null !== $linked->size && $linked->size <= self::preview_fallback_bytes() ) {
					// No preview from Nextcloud (e.g. very large original): serve the original (OP-8).
					$status  = $this->serve_original( $attachment_id, $linked, $conditional, $head, $out );
					$missing = 403 === $status || 404 === $status;
				}
			}
		} catch ( NotConfiguredException | TransportException | \InvalidArgumentException ) {
			$status = 0;
		}

		if ( null === $status ) {
			if ( LinkedFile::STATUS_MISSING === $linked->status ) {
				/**
				 * Fires when a linked file that was marked missing is delivered again.
				 *
				 * @param int $attachment_id Attachment ID.
				 */
				do_action( 'wpncl_file_available', $attachment_id );
			}
			return;
		}

		if ( $missing ) {
			/**
			 * Fires when Nextcloud reports a linked file as missing or forbidden.
			 *
			 * @param int $attachment_id Attachment ID.
			 * @param int $status        HTTP status from Nextcloud (403 or 404).
			 */
			do_action( 'wpncl_file_unavailable', $attachment_id, $status );
		}

		// Permanent answers (missing file, no preview) may be cached briefly; temporary errors not.
		$cacheable = $missing || 404 === $status;
		if ( str_starts_with( $linked->mime, 'image/' ) ) {
			$this->placeholder( $out, $size ?? array( (int) ( $linked->width ?? 640 ), (int) ( $linked->height ?? 480 ) ), $cacheable, $head );
		} else {
			$this->plain( $out, $missing ? 404 : 502 );
		}
	}

	/**
	 * Streams the original; relocates the file once if it was moved.
	 *
	 * @param int                   $attachment_id Attachment ID.
	 * @param LinkedFile            $linked        File.
	 * @param array<string, string> $conditional   Conditional headers.
	 * @param bool                  $head          HEAD request.
	 * @param ResponseWriter        $out           Output.
	 * @return int|null Null if a response was sent, otherwise the failing upstream status.
	 */
	private function serve_original( int $attachment_id, LinkedFile $linked, array $conditional, bool $head, ResponseWriter $out ): ?int {
		$status = $this->stream( $linked->area->file_path( $this->client->user(), $linked->path ), $conditional, $linked, false, $head, $out );
		if ( 404 !== $status ) {
			return $status;
		}

		$moved = $this->locator->relocate( $attachment_id, $linked );
		if ( null === $moved ) {
			return $status;
		}

		return $this->stream( $moved->area->file_path( $this->client->user(), $moved->path ), $conditional, $moved, false, $head, $out );
	}

	/**
	 * Streams one upstream request to the client.
	 *
	 * @param string                $path        Upstream path.
	 * @param array<string, string> $conditional Conditional headers.
	 * @param LinkedFile            $linked      File.
	 * @param bool                  $preview     Whether this is a preview (type taken from upstream).
	 * @param bool                  $head        HEAD request.
	 * @param ResponseWriter        $out         Output.
	 * @return int|null Null if a response was sent, otherwise the upstream status.
	 */
	private function stream( string $path, array $conditional, LinkedFile $linked, bool $preview, bool $head, ResponseWriter $out ): ?int {
		$sent = false;

		$status = $this->client->stream(
			$path,
			$conditional,
			function ( int $status, array $headers ) use ( &$sent, $linked, $preview, $head, $out ): bool {
				if ( 304 === $status ) {
					$out->status( 304 );
					$this->cache_headers( $out, $headers );
					$sent = true;
					return false;
				}
				if ( 200 !== $status ) {
					return false;
				}

				$type = $preview ? strtolower( trim( explode( ';', $headers['content-type'] ?? '' )[0] ) ) : $linked->mime;
				if ( ! in_array( $type, $preview ? self::PREVIEW_TYPES : array( $linked->mime ), true ) ) {
					return false;
				}

				$out->status( 200 );
				$out->header( 'Content-Type', $type );
				if ( isset( $headers['content-length'] ) && ctype_digit( $headers['content-length'] ) && ! isset( $headers['content-encoding'] ) ) {
					$out->header( 'Content-Length', $headers['content-length'] );
				}
				$this->cache_headers( $out, $headers );
				$out->header( 'X-Content-Type-Options', 'nosniff' );
				$out->header( 'Accept-Ranges', 'none' );
				$out->header( 'Content-Disposition', self::disposition( $linked ) );
				$sent = true;

				return ! $head;
			},
			static function ( string $chunk ) use ( $out ): void {
				$out->body( $chunk );
			}
		);

		return $sent ? null : $status;
	}

	/**
	 * Cache headers: own Cache-Control, validators passed through when well-formed.
	 *
	 * @param ResponseWriter        $out     Output.
	 * @param array<string, string> $headers Upstream headers.
	 */
	private function cache_headers( ResponseWriter $out, array $headers ): void {
		$out->header( 'Cache-Control', 'public, max-age=' . $this->settings->cache_max_age() );
		if ( isset( $headers['etag'] ) && preg_match( '#^(W/)?"[\x21\x23-\x7E]{1,200}"$#', $headers['etag'] ) ) {
			$out->header( 'ETag', $headers['etag'] );
		}
		if ( isset( $headers['last-modified'] ) && false !== strtotime( $headers['last-modified'] ) ) {
			$out->header( 'Last-Modified', gmdate( 'D, d M Y H:i:s', (int) strtotime( $headers['last-modified'] ) ) . ' GMT' );
		}
	}

	/**
	 * Requested image size: null for the original, [w, h] for a size, false if invalid.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param LinkedFile $linked        File.
	 * @param string     $variant       Variant from the URL.
	 * @param string     $file          File name from the URL.
	 * @return array{0: int, 1: int}|null|false
	 */
	private function requested_size( int $attachment_id, LinkedFile $linked, string $variant, string $file ): array|null|false {
		if ( 'full' === $variant ) {
			return $file === $linked->slug() ? null : false;
		}
		if ( ! $linked->is_sized_image() ) {
			return false;
		}

		[ $width, $height ] = array_map( 'intval', explode( 'x', $variant ) );
		$meta               = wp_get_attachment_metadata( $attachment_id );
		foreach ( is_array( $meta ) && is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array() as $size ) {
			if ( is_array( $size ) && (int) ( $size['width'] ?? 0 ) === $width && (int) ( $size['height'] ?? 0 ) === $height && ( $size['file'] ?? null ) === $file ) {
				return array( $width, $height );
			}
		}

		return false;
	}

	/**
	 * Content-Disposition: inline for images and PDF, attachment for other documents.
	 *
	 * @param LinkedFile $linked File.
	 */
	private static function disposition( LinkedFile $linked ): string {
		$type = str_starts_with( $linked->mime, 'image/' ) || 'application/pdf' === $linked->mime ? 'inline' : 'attachment';

		return $type . '; filename="' . $linked->slug() . '"; filename*=UTF-8\'\'' . rawurlencode( $linked->name );
	}

	/**
	 * Conditional request headers that may be forwarded.
	 *
	 * @param array<string, string> $headers Client headers.
	 * @return array<string, string>
	 */
	private static function safe_conditional( array $headers ): array {
		$safe = array();
		if ( isset( $headers['If-None-Match'] ) && preg_match( '#^[\x20-\x7E]{1,512}$#', $headers['If-None-Match'] ) ) {
			$safe['If-None-Match'] = $headers['If-None-Match'];
		}
		if ( isset( $headers['If-Modified-Since'] ) && false !== strtotime( $headers['If-Modified-Since'] ) ) {
			$safe['If-Modified-Since'] = gmdate( 'D, d M Y H:i:s', (int) strtotime( $headers['If-Modified-Since'] ) ) . ' GMT';
		}

		return $safe;
	}

	/**
	 * Largest original that is served instead of a missing preview.
	 */
	private static function preview_fallback_bytes(): int {
		/**
		 * Filters up to which size the original is delivered when Nextcloud has no preview.
		 *
		 * @param int $bytes Default 20 MB; 0 always shows the placeholder instead.
		 */
		$bytes = apply_filters( 'wpncl_preview_fallback_max_bytes', self::DEFAULT_PREVIEW_FALLBACK_BYTES );

		return is_int( $bytes ) && $bytes >= 0 ? $bytes : self::DEFAULT_PREVIEW_FALLBACK_BYTES;
	}

	/**
	 * Sends the placeholder image.
	 *
	 * @param ResponseWriter        $out       Output.
	 * @param array{0: int, 1: int} $size      Width and height.
	 * @param bool                  $cacheable Whether the answer is permanent (cacheable) or the error temporary.
	 * @param bool                  $head      HEAD request.
	 */
	private function placeholder( ResponseWriter $out, array $size, bool $cacheable, bool $head ): void {
		$svg = Placeholder::svg( $size[0], $size[1] );
		$out->status( 200 );
		$out->header( 'Content-Type', 'image/svg+xml' );
		$out->header( 'Content-Length', (string) strlen( $svg ) );
		$out->header( 'Cache-Control', $cacheable ? 'public, max-age=' . self::PLACEHOLDER_MAX_AGE : 'no-store' );
		$out->header( 'Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'" );
		$out->header( 'X-Content-Type-Options', 'nosniff' );
		if ( ! $head ) {
			$out->body( $svg );
		}
	}

	/**
	 * Sends an empty error response.
	 *
	 * @param ResponseWriter        $out     Output.
	 * @param int                   $status  HTTP status.
	 * @param array<string, string> $headers Additional headers.
	 */
	private function plain( ResponseWriter $out, int $status, array $headers = array() ): void {
		$out->status( $status );
		$out->header( 'Content-Type', 'text/plain; charset=utf-8' );
		$out->header( 'Cache-Control', 'no-store' );
		$out->header( 'X-Content-Type-Options', 'nosniff' );
		foreach ( $headers as $name => $value ) {
			$out->header( $name, $value );
		}
	}
}
