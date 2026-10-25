<?php
/**
 * Tests for the settings sanitizer.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Config;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\Settings;
use WpNcLinker\Config\SettingsSanitizer;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Sanitising, authorisation and password handling on save.
 */
#[CoversClass( SettingsSanitizer::class )]
final class SettingsSanitizerTest extends TestCase {

	/**
	 * Encryption with a fixed test key.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/**
	 * Codes passed to add_settings_error().
	 *
	 * @var list<string>
	 */
	private array $errors = array();

	protected function setUp(): void {
		parent::setUp();
		$this->crypto = new Crypto( static fn (): string => 'salt' );
		$this->errors = array();
		Functions\when( 'add_settings_error' )->alias(
			function ( string $setting, string $code ): void {
				$this->errors[] = $code;
			}
		);
	}

	/**
	 * Sanitizer for a stored option.
	 *
	 * @param mixed                $stored     Stored option.
	 * @param bool                 $authorized Result of the capability check.
	 * @param array<string, mixed> $constants  Constants.
	 */
	private function sanitizer( mixed $stored = false, bool $authorized = true, array $constants = array() ): SettingsSanitizer {
		Functions\when( 'get_option' )->justReturn( $stored );
		$settings = new Settings( new FakeConstants( $constants ), $this->crypto );

		return new SettingsSanitizer( $settings, $this->crypto, static fn (): bool => $authorized );
	}

	public function test_unauthorized_request_keeps_current_value(): void {
		$stored = array_merge( Settings::defaults(), array( 'url' => 'https://cloud.example.org' ) );
		$result = $this->sanitizer( $stored, false )->sanitize(
			array(
				'form' => '1',
				'url'  => 'https://evil.example.net',
			)
		);

		$this->assertSame( 'https://cloud.example.org', $result['url'] );
		$this->assertSame( array( 'wpncl_forbidden' ), $this->errors );
	}

	public function test_valid_form_input(): void {
		$result = $this->sanitizer()->sanitize(
			array(
				'form'                => '1',
				'url'                 => ' https://Cloud.example.org/ ',
				'user'                => 'linker',
				'new_app_password'    => 'AbCdE-fGhIj',
				'cache_max_age'       => '3600',
				'mime_types'          => array( 'image/png', 'application/pdf', 'image/svg+xml' ),
				'proxy_prefix'        => 'Media',
				'delete_on_uninstall' => '1',
			)
		);

		$this->assertSame( 'https://cloud.example.org', $result['url'] );
		$this->assertSame( 'linker', $result['user'] );
		$this->assertSame( 'AbCdE-fGhIj', $this->crypto->decrypt( $result['app_password'] ) );
		$this->assertSame( 3600, $result['cache_max_age'] );
		$this->assertSame( array( 'image/png', 'application/pdf' ), $result['mime_types'] );
		$this->assertSame( 'media', $result['proxy_prefix'] );
		$this->assertTrue( $result['delete_on_uninstall'] );
		$this->assertSame( array(), $this->errors );
	}

	public function test_invalid_fields_keep_previous_values(): void {
		$stored = array_merge(
			Settings::defaults(),
			array(
				'url'  => 'https://cloud.example.org',
				'user' => 'linker',
			)
		);
		$result = $this->sanitizer( $stored )->sanitize(
			array(
				'form'          => '1',
				'url'           => 'http://cloud.example.org',
				'user'          => 'bad/user',
				'cache_max_age' => '-1',
				'proxy_prefix'  => 'wp-admin',
				'mime_types'    => array( 'image/png' ),
			)
		);

		$this->assertSame( 'https://cloud.example.org', $result['url'] );
		$this->assertSame( 'linker', $result['user'] );
		$this->assertSame( 86400, $result['cache_max_age'] );
		$this->assertSame( 'nextcloud-media', $result['proxy_prefix'] );
		$this->assertSame( array( 'wpncl_url', 'wpncl_user', 'wpncl_cache_max_age', 'wpncl_proxy_prefix' ), $this->errors );
	}

	public function test_empty_connection_fields_clear_them(): void {
		$stored = array_merge( Settings::defaults(), array( 'url' => 'https://cloud.example.org' ) );
		$result = $this->sanitizer( $stored )->sanitize(
			array(
				'form' => '1',
				'url'  => '',
				'user' => '',
			)
		);

		$this->assertSame( '', $result['url'] );
		$this->assertSame( '', $result['user'] );
	}

	public function test_empty_password_field_keeps_stored_password(): void {
		$cipher = $this->crypto->encrypt( 'secret' );
		$result = $this->sanitizer( array_merge( Settings::defaults(), array( 'app_password' => $cipher ) ) )
			->sanitize(
				array(
					'form'             => '1',
					'new_app_password' => '',
				)
			);

		$this->assertSame( $cipher, $result['app_password'] );
	}

	public function test_password_can_be_removed(): void {
		$cipher = $this->crypto->encrypt( 'secret' );
		$result = $this->sanitizer( array_merge( Settings::defaults(), array( 'app_password' => $cipher ) ) )
			->sanitize(
				array(
					'form'               => '1',
					'clear_app_password' => '1',
				)
			);

		$this->assertSame( '', $result['app_password'] );
	}

	public function test_invalid_password_keeps_stored_password(): void {
		$cipher = $this->crypto->encrypt( 'secret' );
		$result = $this->sanitizer( array_merge( Settings::defaults(), array( 'app_password' => $cipher ) ) )
			->sanitize(
				array(
					'form'             => '1',
					'new_app_password' => 'has space',
				)
			);

		$this->assertSame( $cipher, $result['app_password'] );
		$this->assertSame( array( 'wpncl_app_password' ), $this->errors );
	}

	public function test_plaintext_in_stored_field_is_never_accepted(): void {
		$result = $this->sanitizer()->sanitize( array( 'app_password' => 'plain-secret' ) );

		$this->assertSame( '', $result['app_password'] );
	}

	public function test_second_run_on_sanitized_value_is_idempotent(): void {
		$sanitizer = $this->sanitizer();
		$first     = $sanitizer->sanitize(
			array(
				'form'             => '1',
				'url'              => 'https://cloud.example.org',
				'user'             => 'linker',
				'new_app_password' => 'secret',
				'mime_types'       => array( 'image/png' ),
			)
		);

		$this->assertSame( $first, $sanitizer->sanitize( $first ) );
	}

	public function test_unchecked_boxes_in_form_clear_values(): void {
		$stored = array_merge( Settings::defaults(), array( 'delete_on_uninstall' => true ) );
		$result = $this->sanitizer( $stored )->sanitize( array( 'form' => '1' ) );

		$this->assertSame( array(), $result['mime_types'] );
		$this->assertFalse( $result['delete_on_uninstall'] );
	}

	public function test_locked_fields_ignore_input(): void {
		$stored = array_merge( Settings::defaults(), array( 'url' => 'https://stored.example.org' ) );
		$result = $this->sanitizer(
			$stored,
			true,
			array(
				'WPNCL_URL'          => 'https://cloud.example.org',
				'WPNCL_APP_PASSWORD' => 'constant',
			)
		)->sanitize(
			array(
				'form'             => '1',
				'url'              => 'https://other.example.org',
				'new_app_password' => 'new-secret',
			)
		);

		$this->assertSame( 'https://stored.example.org', $result['url'] );
		$this->assertSame( '', $result['app_password'] );
	}

	public function test_non_array_input_keeps_current_value(): void {
		$stored = array_merge( Settings::defaults(), array( 'user' => 'linker' ) );

		$this->assertSame( 'linker', $this->sanitizer( $stored )->sanitize( 'garbage' )['user'] );
	}
}
