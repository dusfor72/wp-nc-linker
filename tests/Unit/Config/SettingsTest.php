<?php
/**
 * Tests for the effective settings.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Config;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\MimeTypes;
use WpNcLinker\Config\Settings;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Stored option combined with wp-config.php constants.
 */
#[CoversClass( Settings::class )]
#[CoversClass( MimeTypes::class )]
final class SettingsTest extends TestCase {

	/**
	 * Encryption with a fixed test key.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	protected function setUp(): void {
		parent::setUp();
		$this->crypto = new Crypto( static fn (): string => 'salt' );
	}

	/**
	 * Settings with the given option value and constants.
	 *
	 * @param mixed                $option    Value returned by get_option().
	 * @param array<string, mixed> $constants Constants.
	 */
	private function settings( mixed $option, array $constants = array() ): Settings {
		Functions\when( 'get_option' )->justReturn( $option );

		return new Settings( new FakeConstants( $constants ), $this->crypto );
	}

	public function test_defaults_without_option(): void {
		$settings = $this->settings( false );

		$this->assertSame( Settings::defaults(), $settings->stored() );
		$this->assertFalse( $settings->is_configured() );
		$this->assertSame( 86400, $settings->cache_max_age() );
		$this->assertSame( 'nextcloud-media', $settings->proxy_prefix() );
		$this->assertSame( MimeTypes::SUPPORTED, $settings->allowed_mime_types() );
		$this->assertFalse( $settings->delete_on_uninstall() );
	}

	public function test_stored_values(): void {
		$settings = $this->settings(
			array(
				'url'          => 'https://cloud.example.org',
				'user'         => 'linker',
				'app_password' => $this->crypto->encrypt( 'app-secret' ),
			)
		);

		$this->assertSame( 'https://cloud.example.org', $settings->url() );
		$this->assertSame( 'linker', $settings->user() );
		$this->assertSame( 'app-secret', $settings->app_password() );
		$this->assertTrue( $settings->is_configured() );
		$this->assertFalse( $settings->is_locked( 'url' ) );
	}

	public function test_constants_take_precedence(): void {
		$settings = $this->settings(
			array(
				'url'          => 'https://stored.example.org',
				'user'         => 'stored',
				'app_password' => $this->crypto->encrypt( 'stored-secret' ),
			),
			array(
				'WPNCL_URL'          => 'https://cloud.example.org/',
				'WPNCL_USER'         => 'linker',
				'WPNCL_APP_PASSWORD' => 'constant-secret',
			)
		);

		$this->assertTrue( $settings->is_locked( 'url' ) );
		$this->assertTrue( $settings->is_locked( 'app_password' ) );
		$this->assertSame( 'https://cloud.example.org', $settings->url() );
		$this->assertSame( 'linker', $settings->user() );
		$this->assertSame( 'constant-secret', $settings->app_password() );
	}

	public function test_invalid_constant_is_not_used(): void {
		$settings = $this->settings(
			array( 'url' => 'https://stored.example.org' ),
			array( 'WPNCL_URL' => 'http://insecure.example.org' )
		);

		$this->assertTrue( $settings->has_invalid_constant( 'url' ) );
		$this->assertNull( $settings->url() );
		$this->assertFalse( $settings->has_invalid_constant( 'user' ) );
	}

	public function test_non_string_constant_is_invalid(): void {
		$settings = $this->settings( false, array( 'WPNCL_USER' => 42 ) );

		$this->assertTrue( $settings->has_invalid_constant( 'user' ) );
		$this->assertNull( $settings->user() );
	}

	public function test_undecryptable_password(): void {
		$other    = new Crypto( static fn (): string => 'other-salt' );
		$settings = $this->settings( array( 'app_password' => $other->encrypt( 'secret' ) ) );

		$this->assertTrue( $settings->has_stored_app_password() );
		$this->assertTrue( $settings->is_stored_app_password_unreadable() );
		$this->assertNull( $settings->app_password() );
	}

	public function test_stored_values_are_normalised(): void {
		$settings = $this->settings(
			array(
				'url'                 => array( 'x' ),
				'cache_max_age'       => 'abc',
				'mime_types'          => array( 'image/png', 'image/svg+xml', 'text/html' ),
				'proxy_prefix'        => 'wp-admin',
				'delete_on_uninstall' => '1',
			)
		);

		$stored = $settings->stored();
		$this->assertSame( '', $stored['url'] );
		$this->assertSame( 86400, $stored['cache_max_age'] );
		$this->assertSame( array( 'image/png' ), $stored['mime_types'] );
		$this->assertSame( 'nextcloud-media', $stored['proxy_prefix'] );
		$this->assertFalse( $stored['delete_on_uninstall'] );
	}

	public function test_mime_type_filter_never_allows_active_content(): void {
		$settings = $this->settings( array( 'mime_types' => array( 'image/png' ) ) );
		Filters\expectApplied( 'wpncl_allowed_mime_types' )
			->once()
			->andReturn( array( 'image/png', 'IMAGE/HEIC ', 'image/svg+xml', 'text/html', 'not a type', 42 ) );

		$this->assertSame( array( 'image/png', 'image/heic' ), $settings->allowed_mime_types() );
	}

	public function test_invalid_filter_results_fall_back_to_stored_values(): void {
		$settings = $this->settings(
			array(
				'cache_max_age' => 600,
				'proxy_prefix'  => 'media',
			)
		);
		Filters\expectApplied( 'wpncl_cache_max_age' )->andReturn( -5 );
		Filters\expectApplied( 'wpncl_proxy_prefix' )->andReturn( 'wp-json' );
		Filters\expectApplied( 'wpncl_allowed_mime_types' )->andReturn( 'image/png' );

		$this->assertSame( 600, $settings->cache_max_age() );
		$this->assertSame( 'media', $settings->proxy_prefix() );
		$this->assertSame( MimeTypes::SUPPORTED, $settings->allowed_mime_types() );
	}

	public function test_valid_filter_results_are_used(): void {
		$settings = $this->settings( false );
		Filters\expectApplied( 'wpncl_cache_max_age' )->andReturn( 3600 );
		Filters\expectApplied( 'wpncl_proxy_prefix' )->andReturn( 'Files' );

		$this->assertSame( 3600, $settings->cache_max_age() );
		$this->assertSame( 'files', $settings->proxy_prefix() );
	}
}
