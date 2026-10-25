<?php
/**
 * Integration tests for the settings page (acceptance tests 1 and 1a).
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\Settings;

/**
 * Settings page, saving, constants and the capability filter over HTTP.
 */
#[CoversNothing]
final class SettingsPageTest extends TestCase {

	private const PAGE = '/wp-admin/options-general.php?page=wp-nc-linker';

	private const ROLE = 'wpncl_test_site_manager';

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( Settings::OPTION, Settings::defaults() );
		$this->set_option( 'wpncl_test_constants', false );
		$this->set_option( 'wpncl_test_settings_capability', false );
	}

	protected function tearDown(): void {
		parent::tearDown();
		remove_role( self::ROLE );
	}

	/**
	 * Current option value from the database.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( Settings::OPTION, 'options' );
		$value = get_option( Settings::OPTION );
		$this->assertIsArray( $value );

		return $value;
	}

	/**
	 * Submits the settings form as the given user.
	 *
	 * @param array<string, string> $cookies Login cookies.
	 * @param array<string, mixed>  $fields  Fields below wpncl_settings[...].
	 * @return array{status: int, body: string}
	 */
	private function submit_form( array $cookies, array $fields ): array {
		$page = $this->http( 'GET', self::PAGE, $cookies );
		$this->assertSame( 200, $page['status'] );

		return $this->http(
			'POST',
			'/wp-admin/options.php',
			$cookies,
			array(
				'option_page'      => 'wpncl',
				'action'           => 'update',
				'_wpnonce'         => $this->form_value( $page['body'], '_wpnonce' ),
				'_wp_http_referer' => self::PAGE,
				Settings::OPTION   => array_merge( array( 'form' => '1' ), $fields ),
			)
		);
	}

	public function test_admin_saves_settings_and_password_is_stored_encrypted(): void {
		$cookies  = $this->login_cookies( $this->create_user( 'administrator' ) );
		$response = $this->submit_form(
			$cookies,
			array(
				'url'              => 'https://cloud.example.org/',
				'user'             => 'linker',
				'new_app_password' => 'Integration-Secret-1',
				'cache_max_age'    => '600',
				'proxy_prefix'     => 'media-files',
				'mime_types'       => array( 'image/jpeg', 'application/pdf' ),
			)
		);
		$this->assertSame( 302, $response['status'] );

		$stored = $this->stored();
		$this->assertSame( 'https://cloud.example.org', $stored['url'] );
		$this->assertSame( 'linker', $stored['user'] );
		$this->assertSame( 600, $stored['cache_max_age'] );
		$this->assertSame( 'media-files', $stored['proxy_prefix'] );
		$this->assertSame( array( 'image/jpeg', 'application/pdf' ), $stored['mime_types'] );
		$this->assertIsString( $stored['app_password'] );
		$this->assertTrue( Crypto::is_ciphertext( $stored['app_password'] ) );
		$this->assertSame( 'Integration-Secret-1', Crypto::from_wordpress()->decrypt( $stored['app_password'] ) );

		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Settings::OPTION ) );
		$this->assertIsString( $raw );
		$this->assertStringNotContainsString( 'Integration-Secret-1', $raw );

		$page = $this->http( 'GET', self::PAGE, $cookies );
		$this->assertStringNotContainsString( 'Integration-Secret-1', $page['body'] );
		$this->assertStringNotContainsString( $stored['app_password'], $page['body'] );
		$this->assertStringContainsString( 'name="wpncl_settings[new_app_password]" value=""', $page['body'] );
		$this->assertStringContainsString( 'An app password is stored.', $page['body'] );
	}

	public function test_empty_password_field_keeps_stored_password(): void {
		$cipher = Crypto::from_wordpress()->encrypt( 'Kept-Secret-2' );
		$this->set_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'app_password' => $cipher ) ) );

		$cookies  = $this->login_cookies( $this->create_user( 'administrator' ) );
		$response = $this->submit_form(
			$cookies,
			array(
				'url'              => 'https://cloud.example.org',
				'new_app_password' => '',
			)
		);
		$this->assertSame( 302, $response['status'] );
		$this->assertSame( $cipher, $this->stored()['app_password'] );
	}

	public function test_constants_lock_fields_and_hide_password(): void {
		$this->set_option(
			'wpncl_test_constants',
			array(
				'WPNCL_URL'          => 'https://cloud.example.org',
				'WPNCL_USER'         => 'linker',
				'WPNCL_APP_PASSWORD' => 'Constant-Secret-3',
			)
		);
		$cookies = $this->login_cookies( $this->create_user( 'administrator' ) );

		$page = $this->http( 'GET', self::PAGE, $cookies );
		$this->assertSame( 200, $page['status'] );
		$this->assertMatchesRegularExpression( '/<input type="url" id="wpncl_url"[^>]+value="https:\/\/cloud\.example\.org"[^>]+disabled/', $page['body'] );
		$this->assertMatchesRegularExpression( '/<input type="text" id="wpncl_user"[^>]+value="linker"[^>]+disabled/', $page['body'] );
		$this->assertMatchesRegularExpression( '/<input type="password" id="wpncl_app_password"[^>]+disabled/', $page['body'] );
		$this->assertStringContainsString( '<code>WPNCL_APP_PASSWORD</code>', $page['body'] );
		$this->assertStringNotContainsString( 'Constant-Secret-3', $page['body'] );

		$response = $this->submit_form(
			$cookies,
			array(
				'url'              => 'https://other.example.org',
				'new_app_password' => 'Ignored-Secret',
			)
		);
		$this->assertSame( 302, $response['status'] );
		$stored = $this->stored();
		$this->assertSame( '', $stored['url'] );
		$this->assertSame( '', $stored['app_password'] );
	}

	public function test_capability_filter_blocks_page_and_saving(): void {
		$this->set_option( 'wpncl_test_settings_capability', 'create_users' );
		$this->set_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'url' => 'https://cloud.example.org' ) ) );
		add_role(
			self::ROLE,
			'Site manager (test)',
			array(
				'read'           => true,
				'manage_options' => true,
			)
		);
		$user_id = $this->create_user( self::ROLE );
		$cookies = $this->login_cookies( $user_id );

		$settings = $this->http( 'GET', '/wp-admin/options-general.php', $cookies );
		$this->assertSame( 200, $settings['status'] );
		$this->assertStringNotContainsString( 'page=wp-nc-linker', $settings['body'] );

		$this->assertSame( 403, $this->http( 'GET', self::PAGE, $cookies )['status'] );

		// Direct POST to the plugin's option group.
		$direct = $this->http(
			'POST',
			'/wp-admin/options.php',
			$cookies,
			array(
				'option_page'    => 'wpncl',
				'action'         => 'update',
				'_wpnonce'       => $this->nonce( $user_id, $cookies, 'wpncl-options' ),
				Settings::OPTION => array(
					'form' => '1',
					'url'  => 'https://evil.example.net',
				),
			)
		);
		$this->assertSame( 403, $direct['status'] );
		$this->assertSame( 'https://cloud.example.org', $this->stored()['url'] );

		// POST via the generic "all options" form, which only checks manage_options.
		$generic = $this->http(
			'POST',
			'/wp-admin/options.php',
			$cookies,
			array(
				'option_page'    => 'options',
				'action'         => 'update',
				'_wpnonce'       => $this->nonce( $user_id, $cookies, 'options-options' ),
				'page_options'   => Settings::OPTION,
				Settings::OPTION => array(
					'form' => '1',
					'url'  => 'https://evil.example.net',
				),
			)
		);
		$this->assertContains( $generic['status'], array( 200, 302 ) );
		$this->assertSame( 'https://cloud.example.org', $this->stored()['url'] );

		// Administrators (with create_users) still have access.
		$admin = $this->login_cookies( $this->create_user( 'administrator' ) );
		$this->assertSame( 200, $this->http( 'GET', self::PAGE, $admin )['status'] );
	}

	public function test_update_outside_admin_is_sanitized(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );
		try {
			update_option(
				Settings::OPTION,
				array(
					'url'              => 'https://cloud.example.org/',
					'new_app_password' => 'Outside-Admin-Secret-4',
				)
			);
		} finally {
			wp_set_current_user( 0 );
		}

		$stored = $this->stored();
		$this->assertArrayNotHasKey( 'new_app_password', $stored );
		$this->assertSame( 'https://cloud.example.org', $stored['url'] );
		$this->assertIsString( $stored['app_password'] );
		$this->assertSame( 'Outside-Admin-Secret-4', Crypto::from_wordpress()->decrypt( $stored['app_password'] ) );
	}

	public function test_update_without_authorized_user_is_rejected(): void {
		update_option( Settings::OPTION, array( 'url' => 'https://evil.example.net' ) );

		$this->assertSame( '', $this->stored()['url'] );
	}

	public function test_editor_has_no_access(): void {
		$cookies = $this->login_cookies( $this->create_user( 'editor' ) );

		$this->assertSame( 403, $this->http( 'GET', self::PAGE, $cookies )['status'] );
	}
}
