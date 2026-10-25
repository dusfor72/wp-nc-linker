<?php
/**
 * Sanitising of the settings option.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitize callback of the settings option. Runs on every add/update of the option, so it also
 * enforces the settings capability (options.php can update any option for users with
 * manage_options, see `option_page=options`).
 *
 * @phpstan-import-type StoredSettings from Settings
 */
final class SettingsSanitizer {

	public const ERROR_SLUG = 'wpncl_settings';

	/**
	 * Decides whether the current request may change the settings.
	 *
	 * @var \Closure(): bool
	 */
	private \Closure $is_authorized;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings      Current settings.
	 * @param Crypto   $crypto        Encryption of the app password.
	 * @param \Closure $is_authorized Authorisation check for the current request.
	 * @phpstan-param \Closure(): bool $is_authorized
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Crypto $crypto,
		\Closure $is_authorized,
	) {
		$this->is_authorized = $is_authorized;
	}

	/**
	 * Sanitises the submitted settings. Invalid fields keep their previous value.
	 *
	 * Accepts the form input (marker "form", new password in "new_app_password") as well as an
	 * already sanitised value (WordPress may run the callback twice when the option is created).
	 *
	 * @param mixed $input Submitted value.
	 * @return StoredSettings
	 */
	public function sanitize( mixed $input ): array {
		$current = $this->settings->stored();

		if ( ! ( $this->is_authorized )() ) {
			$this->error( 'forbidden', __( 'You are not allowed to change the Nextcloud Linker settings.', 'wp-nc-linker' ) );
			return $current;
		}
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$from_form = isset( $input['form'] );
		$result    = $current;

		if ( ! $this->settings->is_locked( 'url' ) && isset( $input['url'] ) && is_string( $input['url'] ) ) {
			$url = trim( $input['url'] );
			if ( '' === $url ) {
				$result['url'] = '';
			} elseif ( null !== Validator::url( $url ) ) {
				$result['url'] = (string) Validator::url( $url );
			} else {
				$this->error( 'url', __( 'The Nextcloud URL must be a valid https:// address without user name, query or fragment.', 'wp-nc-linker' ) );
			}
		}

		if ( ! $this->settings->is_locked( 'user' ) && isset( $input['user'] ) && is_string( $input['user'] ) ) {
			$user = trim( $input['user'] );
			if ( '' === $user ) {
				$result['user'] = '';
			} elseif ( null !== Validator::user( $user ) ) {
				$result['user'] = $user;
			} else {
				$this->error( 'user', __( 'The user name contains characters that Nextcloud does not allow.', 'wp-nc-linker' ) );
			}
		}

		if ( ! $this->settings->is_locked( 'app_password' ) ) {
			$result['app_password'] = $this->sanitize_app_password( $input, $current['app_password'] );
		}

		if ( isset( $input['cache_max_age'] ) ) {
			$seconds = Validator::cache_max_age( $input['cache_max_age'] );
			if ( null !== $seconds ) {
				$result['cache_max_age'] = $seconds;
			} else {
				$this->error( 'cache_max_age', __( 'The cache lifetime must be a number of seconds between 0 and 31536000.', 'wp-nc-linker' ) );
			}
		}

		if ( isset( $input['mime_types'] ) && is_array( $input['mime_types'] ) ) {
			$result['mime_types'] = array_values( array_intersect( MimeTypes::SUPPORTED, $input['mime_types'] ) );
		} elseif ( $from_form ) {
			$result['mime_types'] = array();
		}

		if ( isset( $input['proxy_prefix'] ) && is_string( $input['proxy_prefix'] ) ) {
			$prefix = Validator::proxy_prefix( $input['proxy_prefix'] );
			if ( null !== $prefix ) {
				$result['proxy_prefix'] = $prefix;
			} else {
				$this->error( 'proxy_prefix', __( 'The URL prefix may only contain lowercase letters, digits and single hyphens and must not be a WordPress path.', 'wp-nc-linker' ) );
			}
		}

		if ( isset( $input['delete_on_uninstall'] ) ) {
			$result['delete_on_uninstall'] = in_array( $input['delete_on_uninstall'], array( true, '1', 1 ), true );
		} elseif ( $from_form ) {
			$result['delete_on_uninstall'] = false;
		}

		return $result;
	}

	/**
	 * Determines the stored (encrypted) app password.
	 *
	 * @param array<mixed> $input   Submitted value.
	 * @param string       $current Currently stored ciphertext.
	 */
	private function sanitize_app_password( array $input, string $current ): string {
		$new = $input['new_app_password'] ?? '';
		if ( is_string( $new ) && '' !== trim( $new ) ) {
			$password = Validator::app_password( $new );
			if ( null === $password ) {
				$this->error( 'app_password', __( 'The app password contains invalid characters.', 'wp-nc-linker' ) );
				return $current;
			}
			return $this->crypto->encrypt( $password );
		}

		if ( ! empty( $input['clear_app_password'] ) ) {
			return '';
		}

		// Second run on an already sanitised value: keep a valid ciphertext.
		$stored = $input['app_password'] ?? null;
		if ( is_string( $stored ) && Crypto::is_ciphertext( $stored ) && null !== $this->crypto->decrypt( $stored ) ) {
			return $stored;
		}

		return $current;
	}

	/**
	 * Registers an error message for the settings page.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 */
	private function error( string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::ERROR_SLUG, 'wpncl_' . $code, $message );
		}
	}
}
