<?php
/**
 * Integration tests for the connection test (F2, acceptance tests 1 and 9).
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use WpNcLinker\Config\Crypto;
use WpNcLinker\Config\Settings;
use WpNcLinker\Diagnostics\CheckResult;
use WpNcLinker\Diagnostics\ConnectionTest;
use WpNcLinker\Plugin;

/**
 * Connection test against the Nextcloud container: WP-CLI, settings page and error cases.
 */
#[CoversNothing]
final class ConnectionTestTest extends TestCase {

	private const PAGE = '/wp-admin/options-general.php?page=wp-nc-linker';

	protected function setUp(): void {
		parent::setUp();
		$this->configure( self::app_password() );
	}

	/**
	 * App password of the connection account created by the seed (passed by dev/bin/test-integration.sh).
	 */
	private static function app_password(): string {
		$password = getenv( 'WPNCL_TEST_APP_PASSWORD' );
		if ( ! is_string( $password ) || '' === $password ) {
			self::fail( 'WPNCL_TEST_APP_PASSWORD missing; run the tests with dev/bin/test-integration.sh.' );
		}

		return $password;
	}

	/**
	 * Stores the connection settings.
	 *
	 * @param string $password App password.
	 * @param string $url      Nextcloud URL.
	 */
	private function configure( string $password, string $url = 'https://nextcloud.test' ): void {
		$this->set_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				array(
					'url'          => $url,
					'user'         => 'linker',
					'app_password' => Crypto::from_wordpress()->encrypt( $password ),
				)
			)
		);
	}

	/**
	 * Runs the test in this process.
	 *
	 * @return array<string, CheckResult>
	 */
	private static function run_test(): array {
		$plugin  = Plugin::instance();
		$results = array();
		foreach ( ( new ConnectionTest( $plugin->settings(), $plugin->client() ) )->run() as $result ) {
			$results[ $result->id ] = $result;
		}

		return $results;
	}

	public function test_cli_reports_working_connection(): void {
		$output = array();
		$code   = 0;
		exec( 'cd /var/www/html && wp nc-linker test --format=json 2>&1', $output, $code );
		$rows = json_decode( implode( "\n", $output ), true );

		$this->assertSame( 0, $code, implode( "\n", $output ) );
		$this->assertIsArray( $rows );
		$statuses = array_column( $rows, 'status', 'check' );
		$this->assertSame(
			array(
				'configuration'        => 'ok',
				'server'               => 'ok',
				'login'                => 'ok',
				'files'                => 'ok',
				'albums'               => 'ok',
				'shared_albums'        => 'ok',
				'download_restriction' => 'warning',
				'preview'              => 'ok',
			),
			$statuses
		);
		$messages = array_column( $rows, 'message', 'check' );
		$this->assertStringContainsString( 'Hidden Download', $messages['download_restriction'] );
		$this->assertStringContainsString( '1 own album', $messages['albums'] );
		$this->assertStringContainsString( '2 albums shared', $messages['shared_albums'] );
		$this->assertStringNotContainsString( self::app_password(), implode( "\n", $output ) );
	}

	public function test_cli_fails_with_exit_code_1(): void {
		$this->configure( self::app_password(), 'https://cloud.example.org' );

		$output = array();
		$code   = 0;
		exec( 'cd /var/www/html && wp nc-linker test --format=json 2>/dev/null', $output, $code );

		$this->assertSame( 1, $code );
	}

	public function test_wrong_password_reports_login_error(): void {
		$this->configure( 'Wrong-App-Password' );
		$results = self::run_test();

		$this->assertSame( array( 'configuration', 'server', 'login' ), array_keys( $results ) );
		$this->assertSame( 'error', $results['login']->status->value );
		$this->assertStringContainsString( 'HTTP 401', $results['login']->message );
	}

	public function test_certificate_for_other_host_is_rejected(): void {
		$this->configure( self::app_password(), 'https://wrong-name.test' );
		$results = self::run_test();

		$this->assertSame( 'error', $results['server']->status->value );
		$this->assertStringContainsString( 'TLS certificate', $results['server']->message );
		$this->assertArrayNotHasKey( 'login', $results );
	}

	public function test_tls_verification_cannot_be_disabled_by_other_plugins(): void {
		$this->configure( self::app_password(), 'https://wrong-name.test' );
		$no_verify_args = static function ( array $args ): array {
			$args['sslverify'] = false;
			return $args;
		};
		$no_verify_curl = static function ( $handle ): void {
			curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 );
		};
		add_filter( 'http_request_args', $no_verify_args, 1000 );
		add_filter( 'https_ssl_verify', '__return_false', 1000 );
		add_action( 'http_api_curl', $no_verify_curl, 1000 );
		try {
			$results = self::run_test();
		} finally {
			remove_filter( 'http_request_args', $no_verify_args, 1000 );
			remove_filter( 'https_ssl_verify', '__return_false', 1000 );
			remove_action( 'http_api_curl', $no_verify_curl, 1000 );
		}

		$this->assertSame( 'error', $results['server']->status->value );
		$this->assertStringContainsString( 'TLS certificate', $results['server']->message );
	}

	public function test_blocked_external_host_is_explained(): void {
		$this->configure( self::app_password(), 'https://cloud.example.org' );
		$results = self::run_test();

		$this->assertSame( 'error', $results['server']->status->value );
		$this->assertStringContainsString( 'WP_ACCESSIBLE_HOSTS', $results['server']->message );
	}

	public function test_settings_page_button_shows_results_once(): void {
		$cookies = $this->login_cookies( $this->create_user( 'administrator' ) );
		$page    = $this->http( 'GET', self::PAGE, $cookies );
		$this->assertSame( 200, $page['status'] );
		$this->assertStringContainsString( 'name="action" value="wpncl_connection_test"', $page['body'] );

		$nonce = array();
		$this->assertSame( 1, preg_match( '/name="action" value="wpncl_connection_test">\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([^"]+)"/', $page['body'], $nonce ) );

		$post = $this->http(
			'POST',
			'/wp-admin/admin-post.php',
			$cookies,
			array(
				'action'           => 'wpncl_connection_test',
				'_wpnonce'         => $nonce[1] ?? '',
				'_wp_http_referer' => self::PAGE,
			)
		);
		$this->assertSame( 302, $post['status'] );

		$result = $this->http( 'GET', self::PAGE, $cookies );
		$this->assertStringContainsString( 'The connection works.', $result['body'] );
		$this->assertStringContainsString( 'data-check="login" data-status="ok"', $result['body'] );
		$this->assertStringContainsString( 'data-check="download_restriction" data-status="warning"', $result['body'] );
		$this->assertStringContainsString( 'Hidden Download', $result['body'] );
		$this->assertStringNotContainsString( self::app_password(), $result['body'] );

		$again = $this->http( 'GET', self::PAGE, $cookies );
		$this->assertStringNotContainsString( 'wpncl-test-results', $again['body'] );
	}

	public function test_button_requires_capability_and_nonce(): void {
		$editor = $this->login_cookies( $this->create_user( 'editor' ) );
		$this->assertSame( 403, $this->http( 'POST', '/wp-admin/admin-post.php', $editor, array( 'action' => 'wpncl_connection_test' ) )['status'] );

		$admin = $this->login_cookies( $this->create_user( 'administrator' ) );
		$this->assertSame(
			403,
			$this->http(
				'POST',
				'/wp-admin/admin-post.php',
				$admin,
				array(
					'action'   => 'wpncl_connection_test',
					'_wpnonce' => 'invalid',
				)
			)['status']
		);
	}
}
