<?php
/**
 * Base class for integration tests against the test installation.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Integration;

/**
 * HTTP helpers that talk to the web server of the WordPress container like a browser.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {

	/**
	 * Web server of the container; the Host header matches the site URL.
	 */
	private const BASE = 'http://127.0.0.1';

	/**
	 * Users created by the test, deleted in tearDown().
	 *
	 * @var list<int>
	 */
	private array $created_users = array();

	/**
	 * Options to restore in tearDown() (name => original value, null = delete).
	 *
	 * @var array<string, mixed>
	 */
	private array $saved_options = array();

	protected function tearDown(): void {
		try {
			foreach ( $this->saved_options as $name => $value ) {
				if ( null === $value ) {
					delete_option( $name );
				} else {
					$this->update_raw( $name, $value );
				}
			}
			foreach ( $this->created_users as $user_id ) {
				wp_delete_user( $user_id );
			}
		} finally {
			$this->created_users = array();
			$this->saved_options = array();
			parent::tearDown();
		}
	}

	/**
	 * Sets an option and restores it after the test (bypasses the sanitize callback).
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	protected function set_option( string $name, mixed $value ): void {
		if ( ! array_key_exists( $name, $this->saved_options ) ) {
			$current                      = get_option( $name, null );
			$this->saved_options[ $name ] = $current;
		}
		$this->update_raw( $name, $value );
	}

	/**
	 * Writes an option without its sanitize callback (the callback stays registered).
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	private function update_raw( string $name, mixed $value ): void {
		global $wp_filter;
		$hook  = 'sanitize_option_' . $name;
		$saved = $wp_filter[ $hook ] ?? null;
		unset( $wp_filter[ $hook ] );
		try {
			update_option( $name, $value );
		} finally {
			if ( null !== $saved ) {
				$wp_filter[ $hook ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the hook removed above.
			}
		}
	}

	/**
	 * Creates a user with the given role.
	 *
	 * @param string $role Role name.
	 */
	protected function create_user( string $role ): int {
		$login   = 'wpncl_test_' . $role . '_' . wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => $login . '@example.org',
				'role'       => $role,
			)
		);
		$this->assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		return $user_id;
	}

	/**
	 * Authentication cookies for a user (as after a regular login).
	 *
	 * @param int $user_id User ID.
	 * @return array<string, string>
	 */
	protected function login_cookies( int $user_id ): array {
		$expiration = time() + HOUR_IN_SECONDS;

		return array(
			AUTH_COOKIE      => wp_generate_auth_cookie( $user_id, $expiration, 'auth' ),
			LOGGED_IN_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in' ),
		);
	}

	/**
	 * Sends a request to the web server.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $path    Path below the site root.
	 * @param array<string, string>     $cookies Cookies.
	 * @param array<string, mixed>|null $form    Form fields (POST).
	 * @param list<string>              $extra_headers Additional request headers.
	 * @phpstan-param non-empty-string $method
	 * @return array{status: int, body: string, headers: array<string, string>}
	 */
	protected function http( string $method, string $path, array $cookies = array(), ?array $form = null, array $extra_headers = array() ): array {
		$cookie_header = array();
		foreach ( $cookies as $name => $value ) {
			$cookie_header[] = $name . '=' . rawurlencode( $value );
		}

		$headers = array();
		$handle  = curl_init( self::BASE . $path );
		$this->assertNotFalse( $handle );
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_HTTPHEADER     => array_merge(
					array(
						'Host: localhost:8080',
						'Cookie: ' . implode( '; ', $cookie_header ),
					),
					$extra_headers
				),
				CURLOPT_HEADERFUNCTION => static function ( $curl, string $line ) use ( &$headers ): int {
					$parts = explode( ':', $line, 2 );
					if ( 2 === count( $parts ) ) {
						$headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
					}
					return strlen( $line );
				},
			)
		);
		if ( null !== $form ) {
			curl_setopt( $handle, CURLOPT_POSTFIELDS, http_build_query( $form ) );
		}

		$body   = curl_exec( $handle );
		$status = curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		curl_close( $handle );
		$this->assertIsString( $body );

		return array(
			'status'  => (int) $status,
			'body'    => $body,
			'headers' => $headers,
		);
	}

	/**
	 * Nonce for a user's session (as WordPress would print it in a page).
	 *
	 * @param int                   $user_id User ID.
	 * @param array<string, string> $cookies Login cookies of that user.
	 * @param string                $action  Nonce action.
	 */
	protected function nonce( int $user_id, array $cookies, string $action ): string {
		$previous_cookie             = $_COOKIE[ LOGGED_IN_COOKIE ] ?? null;
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookies[ LOGGED_IN_COOKIE ];
		wp_set_current_user( $user_id );
		$nonce = wp_create_nonce( $action );
		wp_set_current_user( 0 );
		if ( null === $previous_cookie ) {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		} else {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $previous_cookie;
		}

		return $nonce;
	}

	/**
	 * App password of the connection account created by the seed (passed by dev/bin/test-integration.sh).
	 */
	protected static function app_password(): string {
		$password = getenv( 'WPNCL_TEST_APP_PASSWORD' );
		if ( ! is_string( $password ) || '' === $password ) {
			self::fail( 'WPNCL_TEST_APP_PASSWORD missing; run the tests with dev/bin/test-integration.sh.' );
		}

		return $password;
	}

	/**
	 * Stores the connection settings of the test environment (restored after the test).
	 *
	 * @param string|null $password App password (default: the seeded one).
	 * @param string      $url      Nextcloud URL.
	 */
	protected function configure_connection( ?string $password = null, string $url = 'https://nextcloud.test' ): void {
		$this->set_option(
			\WpNcLinker\Config\Settings::OPTION,
			array_merge(
				\WpNcLinker\Config\Settings::defaults(),
				array(
					'url'          => $url,
					'user'         => 'linker',
					'app_password' => \WpNcLinker\Config\Crypto::from_wordpress()->encrypt( $password ?? self::app_password() ),
				)
			)
		);
	}

	/**
	 * Extracts the value of a hidden form field.
	 *
	 * @param string $html HTML.
	 * @param string $name Field name.
	 */
	protected function form_value( string $html, string $name ): string {
		$this->assertSame(
			1,
			preg_match( '/<input[^>]+name="' . preg_quote( $name, '/' ) . '"[^>]+value="([^"]*)"/', $html, $matches ),
			"Form field $name not found"
		);

		return html_entity_decode( $matches[1] ?? '' );
	}
}
