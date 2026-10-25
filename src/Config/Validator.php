<?php
/**
 * Validation of configuration values.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalises configuration values. Every method returns null for invalid input.
 */
final class Validator {

	/**
	 * Maximum length of a Nextcloud user ID (`OCP\IUser::MAX_USERID_LENGTH`).
	 */
	private const MAX_USER_LENGTH = 64;

	/**
	 * Path segments below the site root that the proxy prefix must not shadow.
	 */
	private const RESERVED_PREFIXES = array(
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
		'feed',
		'comments',
		'page',
		'search',
		'author',
		'category',
		'tag',
		'embed',
		'trackback',
		'attachment',
	);

	/**
	 * Normalises the Nextcloud base URL: HTTPS only, no credentials, query or fragment,
	 * optional sub-path without trailing slash.
	 *
	 * @param string $value Raw URL.
	 */
	public static function url( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 2048 ) {
			return null;
		}

		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}

		$host = strtolower( $parts['host'] ?? '' );
		if ( '' === $host || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$|^\[[0-9a-f:.]+\]$/', $host ) ) {
			return null;
		}

		$path = rtrim( $parts['path'] ?? '', '/' );
		if ( '' !== $path ) {
			if ( ! preg_match( '#^(?:/[A-Za-z0-9._~-]+)+$#', $path ) ) {
				return null;
			}
			foreach ( explode( '/', $path ) as $segment ) {
				if ( '.' === $segment || '..' === $segment ) {
					return null;
				}
			}
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return 'https://' . $host . $port . $path;
	}

	/**
	 * Validates a Nextcloud user ID (same character set as `OC\User\Manager::validateUserId()`).
	 *
	 * @param string $value Raw user ID.
	 */
	public static function user( string $value ): ?string {
		if ( '' === $value || trim( $value ) !== $value || strlen( $value ) > self::MAX_USER_LENGTH ) {
			return null;
		}
		if ( preg_match( "/[^a-zA-Z0-9 _.@\\-']/", $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Validates an app password: printable ASCII without whitespace.
	 *
	 * @param string $value Raw app password.
	 */
	public static function app_password( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 256 || ! preg_match( '/^[\x21-\x7E]+$/', $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Validates the URL prefix of the proxy (a single lowercase slug).
	 *
	 * @param string $value Raw prefix.
	 */
	public static function proxy_prefix( string $value ): ?string {
		$value = strtolower( trim( $value, " \t\n\r\0\x0B/" ) );
		if ( strlen( $value ) > 64 || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value ) ) {
			return null;
		}
		if ( in_array( $value, self::RESERVED_PREFIXES, true ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Validates the browser cache lifetime in seconds (0 to 1 year).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function cache_max_age( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			$seconds = $value;
		} elseif ( is_string( $value ) && preg_match( '/^\d{1,9}$/', trim( $value ) ) ) {
			$seconds = (int) trim( $value );
		} else {
			return null;
		}

		return ( $seconds >= 0 && $seconds <= 31536000 ) ? $seconds : null;
	}
}
