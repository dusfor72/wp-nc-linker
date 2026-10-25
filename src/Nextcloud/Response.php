<?php
/**
 * HTTP response from Nextcloud.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Nextcloud;

defined( 'ABSPATH' ) || exit;

/**
 * Status, headers (lowercase names) and body of a buffered response.
 */
final class Response {

	/**
	 * Constructor.
	 *
	 * @param int                   $status  HTTP status code.
	 * @param array<string, string> $headers Headers with lowercase names.
	 * @param string                $body    Body.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $headers,
		public readonly string $body,
	) {
	}

	/**
	 * Header value or null.
	 *
	 * @param string $name Header name (case-insensitive).
	 */
	public function header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}
}
