<?php
/**
 * Effective plugin configuration.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Combines the stored option with the optional wp-config.php constants (constants take precedence).
 *
 * @phpstan-type StoredSettings array{
 *     url: string,
 *     user: string,
 *     app_password: string,
 *     cache_max_age: int,
 *     mime_types: list<string>,
 *     proxy_prefix: string,
 *     delete_on_uninstall: bool
 * }
 */
class Settings {

	public const OPTION = 'wpncl_settings';

	public const DEFAULT_CACHE_MAX_AGE = 86400;
	public const DEFAULT_PROXY_PREFIX  = 'nextcloud-media';

	/**
	 * Constructor.
	 *
	 * @param Constants $constants wp-config.php constants.
	 * @param Crypto    $crypto    Encryption of the stored app password.
	 */
	public function __construct(
		private readonly Constants $constants,
		private readonly Crypto $crypto,
	) {
	}

	/**
	 * Values of a fresh installation.
	 *
	 * @return StoredSettings
	 */
	public static function defaults(): array {
		return array(
			'url'                 => '',
			'user'                => '',
			'app_password'        => '',
			'cache_max_age'       => self::DEFAULT_CACHE_MAX_AGE,
			'mime_types'          => MimeTypes::SUPPORTED,
			'proxy_prefix'        => self::DEFAULT_PROXY_PREFIX,
			'delete_on_uninstall' => false,
		);
	}

	/**
	 * The stored option, normalised to the expected types.
	 *
	 * @return StoredSettings
	 */
	public function stored(): array {
		$defaults = self::defaults();
		$option   = get_option( self::OPTION );
		if ( ! is_array( $option ) ) {
			return $defaults;
		}

		$mime_types = $defaults['mime_types'];
		if ( isset( $option['mime_types'] ) && is_array( $option['mime_types'] ) ) {
			$mime_types = array_values( array_intersect( MimeTypes::SUPPORTED, $option['mime_types'] ) );
		}

		return array(
			'url'                 => is_string( $option['url'] ?? null ) ? $option['url'] : '',
			'user'                => is_string( $option['user'] ?? null ) ? $option['user'] : '',
			'app_password'        => is_string( $option['app_password'] ?? null ) ? $option['app_password'] : '',
			'cache_max_age'       => Validator::cache_max_age( $option['cache_max_age'] ?? null ) ?? $defaults['cache_max_age'],
			'mime_types'          => $mime_types,
			'proxy_prefix'        => Validator::proxy_prefix( is_string( $option['proxy_prefix'] ?? null ) ? $option['proxy_prefix'] : '' ) ?? $defaults['proxy_prefix'],
			'delete_on_uninstall' => true === ( $option['delete_on_uninstall'] ?? false ),
		);
	}

	/**
	 * Whether a connection field is set by a wp-config.php constant.
	 *
	 * @param 'url'|'user'|'app_password' $field Field name.
	 */
	public function is_locked( string $field ): bool {
		return $this->constants->is_defined( self::constant_name( $field ) );
	}

	/**
	 * Whether a wp-config.php constant is defined but has an invalid value.
	 *
	 * @param 'url'|'user'|'app_password' $field Field name.
	 */
	public function has_invalid_constant( string $field ): bool {
		return $this->is_locked( $field ) && null === $this->from_constant( $field );
	}

	/**
	 * Nextcloud base URL (normalised, without trailing slash), or null if not configured.
	 */
	public function url(): ?string {
		if ( $this->is_locked( 'url' ) ) {
			return $this->from_constant( 'url' );
		}

		return Validator::url( $this->stored()['url'] );
	}

	/**
	 * User ID of the connection account, or null if not configured.
	 */
	public function user(): ?string {
		if ( $this->is_locked( 'user' ) ) {
			return $this->from_constant( 'user' );
		}

		return Validator::user( $this->stored()['user'] );
	}

	/**
	 * App password in plain text, or null if not configured or not decryptable.
	 */
	public function app_password(): ?string {
		if ( $this->is_locked( 'app_password' ) ) {
			return $this->from_constant( 'app_password' );
		}

		$stored = $this->stored()['app_password'];

		return '' === $stored ? null : $this->crypto->decrypt( $stored );
	}

	/**
	 * Whether an app password is stored in the database (regardless of the constant).
	 */
	public function has_stored_app_password(): bool {
		return '' !== $this->stored()['app_password'];
	}

	/**
	 * Whether the stored app password can no longer be decrypted (e.g. the salts were changed).
	 */
	public function is_stored_app_password_unreadable(): bool {
		$stored = $this->stored()['app_password'];

		return '' !== $stored && null === $this->crypto->decrypt( $stored );
	}

	/**
	 * Whether URL, user and app password are available.
	 */
	public function is_configured(): bool {
		return null !== $this->url() && null !== $this->user() && null !== $this->app_password();
	}

	/**
	 * Browser cache lifetime of delivered files in seconds.
	 */
	public function cache_max_age(): int {
		$stored = $this->stored()['cache_max_age'];

		/**
		 * Filters the browser cache lifetime (Cache-Control max-age) of delivered files.
		 *
		 * @param int $seconds Lifetime in seconds (0 to 31536000).
		 */
		$filtered = apply_filters( 'wpncl_cache_max_age', $stored );

		return Validator::cache_max_age( $filtered ) ?? $stored;
	}

	/**
	 * MIME types that may be linked and delivered.
	 *
	 * @return list<string>
	 */
	public function allowed_mime_types(): array {
		$stored = $this->stored()['mime_types'];

		/**
		 * Filters the MIME types that may be linked and delivered.
		 *
		 * Types that could carry active content (SVG, HTML, XML, JavaScript) are always removed.
		 *
		 * @param list<string> $types MIME types.
		 */
		$filtered = apply_filters( 'wpncl_allowed_mime_types', $stored );
		if ( ! is_array( $filtered ) ) {
			return $stored;
		}

		$types = array();
		foreach ( $filtered as $type ) {
			if ( is_string( $type ) ) {
				$type = strtolower( trim( $type ) );
				if ( MimeTypes::is_allowed_type( $type ) ) {
					$types[] = $type;
				}
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * First path segment of the proxy URLs.
	 */
	public function proxy_prefix(): string {
		$stored = $this->stored()['proxy_prefix'];

		/**
		 * Filters the first path segment of the proxy URLs.
		 *
		 * @param string $prefix Lowercase slug, e.g. "nextcloud-media".
		 */
		$filtered = apply_filters( 'wpncl_proxy_prefix', $stored );

		return is_string( $filtered ) ? ( Validator::proxy_prefix( $filtered ) ?? $stored ) : $stored;
	}

	/**
	 * Whether virtual attachments are deleted when the plugin is uninstalled.
	 */
	public function delete_on_uninstall(): bool {
		return $this->stored()['delete_on_uninstall'];
	}

	/**
	 * Validated value of a constant.
	 *
	 * @param 'url'|'user'|'app_password' $field Field name.
	 */
	private function from_constant( string $field ): ?string {
		$value = $this->constants->get( self::constant_name( $field ) );
		if ( null === $value ) {
			return null;
		}

		return match ( $field ) {
			'url'          => Validator::url( $value ),
			'user'         => Validator::user( $value ),
			'app_password' => Validator::app_password( $value ),
		};
	}

	/**
	 * Constant name of a connection field.
	 *
	 * @param 'url'|'user'|'app_password' $field Field name.
	 */
	private static function constant_name( string $field ): string {
		return match ( $field ) {
			'url'          => Constants::URL,
			'user'         => Constants::USER,
			'app_password' => Constants::APP_PASSWORD,
		};
	}
}
