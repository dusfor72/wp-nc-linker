<?php
/**
 * Integration tests for missing files (F7, acceptance test 8).
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use WpNcLinker\Admin\DashboardWidget;
use WpNcLinker\Health\Scheduler;
use WpNcLinker\Media\Importer;
use WpNcLinker\Media\LinkedFile;
use WpNcLinker\Media\Repository;
use WpNcLinker\Plugin;

/**
 * Deleted files and revoked shares in Nextcloud, `wp nc-linker check`, the dashboard and recovery.
 */
#[CoversNothing]
final class HealthTest extends TestCase {

	private const COPY = '/remote.php/dav/files/content/Shared%20With%20Linker/health-copy.jpg';

	private const FOLDER = '/remote.php/dav/files/content/Health%20Share';

	private const SHARES = '/ocs/v2.php/apps/files_sharing/api/v1/shares';

	protected function setUp(): void {
		parent::setUp();
		$this->configure_connection();
		self::delete_linked_attachments();
	}

	protected function tearDown(): void {
		self::delete_linked_attachments();
		self::nextcloud( 'DELETE', self::COPY, 'content', self::content_password() );
		self::nextcloud( 'DELETE', self::FOLDER, 'content', self::content_password() );
		parent::tearDown();
	}

	/**
	 * Password of the content account (passed by dev/bin/test-integration.sh).
	 */
	private static function content_password(): string {
		$password = getenv( 'WPNCL_TEST_CONTENT_PASSWORD' );
		self::assertIsString( $password );

		return $password;
	}

	/**
	 * Links a folder file and returns the attachment ID.
	 *
	 * @param string $path Path.
	 */
	private static function link( string $path ): int {
		$plugin = Plugin::instance();

		return ( new Importer( $plugin->settings(), $plugin->client(), new Repository() ) )->import( 'folders', $path, 0 );
	}

	/**
	 * Stored status (as written by another process).
	 *
	 * @param int $id Attachment ID.
	 */
	private static function stored_status( int $id ): string {
		wp_cache_delete( $id, 'post_meta' );
		$file = ( new Repository() )->get( $id );
		self::assertNotNull( $file );

		return $file->status;
	}

	/**
	 * Runs `wp nc-linker check`.
	 *
	 * @return array{code: int, rows: array<int, string>, output: string}
	 */
	private static function cli_check(): array {
		$output = array();
		$code   = 0;
		exec( 'cd /var/www/html && wp nc-linker check --format=json 2>&1', $output, $code );
		$text = implode( "\n", $output );
		$rows = json_decode( (string) strtok( $text, "\n" ), true );

		return array(
			'code'   => $code,
			'rows'   => is_array( $rows ) ? array_column( $rows, 'status', 'id' ) : array(),
			'output' => $text,
		);
	}

	/**
	 * Runs `wp nc-linker list`.
	 *
	 * @param string $options Additional options.
	 * @return array<mixed>
	 */
	private static function cli_list( string $options ): array {
		$output = array();
		$code   = 0;
		exec( 'cd /var/www/html && wp nc-linker list --format=json ' . $options . ' 2>&1', $output, $code );
		self::assertSame( 0, $code, implode( "\n", $output ) );
		$rows = json_decode( implode( "\n", $output ), true );
		self::assertIsArray( $rows );

		return $rows;
	}

	/**
	 * Shares a folder of the content account with the linker account.
	 *
	 * @param string $path Folder path.
	 * @return int Share ID.
	 */
	private static function share( string $path ): int {
		$response = self::nextcloud(
			'POST',
			self::SHARES . '?format=json',
			'content',
			self::content_password(),
			array( 'OCS-APIRequest' => 'true' ),
			http_build_query(
				array(
					'path'      => $path,
					'shareType' => 0,
					'shareWith' => 'linker',
				)
			)
		);
		self::assertSame( 200, $response['status'], $response['body'] );
		$data = json_decode( $response['body'], true );
		self::assertIsArray( $data );

		return (int) $data['ocs']['data']['id'];
	}

	public function test_deleted_file_and_revoked_share_are_reported(): void {
		$password = self::content_password();
		$this->assertSame( 201, self::nextcloud( 'COPY', '/remote.php/dav/files/content/Shared%20With%20Linker/forest.jpg', 'content', $password, array( 'Destination' => 'https://nextcloud.test' . self::COPY ) )['status'] );
		$this->assertSame( 201, self::nextcloud( 'MKCOL', self::FOLDER, 'content', $password )['status'] );
		$this->assertSame( 201, self::nextcloud( 'COPY', '/remote.php/dav/files/content/Shared%20With%20Linker/harbor.jpg', 'content', $password, array( 'Destination' => 'https://nextcloud.test' . self::FOLDER . '/harbor.jpg' ) )['status'] );
		$share = self::share( '/Health Share' );

		$kept    = self::link( 'Shared With Linker/forest.jpg' );
		$deleted = self::link( 'Shared With Linker/health-copy.jpg' );
		$revoked = self::link( 'Health Share/harbor.jpg' );

		$this->assertSame( 204, self::nextcloud( 'DELETE', self::COPY, 'content', $password )['status'] );
		$this->assertSame( 200, self::nextcloud( 'DELETE', self::SHARES . '/' . $share, 'content', $password, array( 'OCS-APIRequest' => 'true' ) )['status'] );

		$check = self::cli_check();
		$this->assertSame( 0, $check['code'], $check['output'] );
		$this->assertSame(
			array(
				$kept    => 'ok',
				$deleted => 'missing',
				$revoked => 'missing',
			),
			$check['rows']
		);
		$this->assertSame( LinkedFile::STATUS_OK, self::stored_status( $kept ) );
		$this->assertSame( LinkedFile::STATUS_MISSING, self::stored_status( $deleted ) );
		$this->assertSame( LinkedFile::STATUS_MISSING, self::stored_status( $revoked ) );

		// Visitors get the placeholder.
		$placeholder = $this->http( 'GET', (string) wp_parse_url( (string) wp_get_attachment_url( $deleted ), PHP_URL_PATH ) );
		$this->assertSame( 'image/svg+xml', $placeholder['headers']['content-type'] ?? null );

		// Editors see the dashboard notice with both files; subscribers do not.
		$editor = $this->http( 'GET', '/wp-admin/index.php', $this->login_cookies( $this->create_user( 'editor' ) ) );
		$this->assertSame( 200, $editor['status'] );
		$this->assertStringContainsString( 'id="' . DashboardWidget::ID . '"', $editor['body'] );
		$this->assertStringContainsString( 'Health Share/harbor.jpg', $editor['body'] );
		$this->assertStringContainsString( 'Shared With Linker/health-copy.jpg', $editor['body'] );
		$this->assertStringNotContainsString( 'Shared With Linker/forest.jpg', $editor['body'] );
		$this->assertStringNotContainsString( 'app_password', $editor['body'] );

		$subscriber = $this->http( 'GET', '/wp-admin/index.php', $this->login_cookies( $this->create_user( 'subscriber' ) ) );
		$this->assertStringNotContainsString( DashboardWidget::ID, $subscriber['body'] );

		// Sharing the folder again: the next check marks the file available again.
		self::share( '/Health Share' );
		$recheck = self::cli_check();
		$this->assertSame( 0, $recheck['code'], $recheck['output'] );
		$this->assertSame( 'ok', $recheck['rows'][ $revoked ] ?? null );
		$this->assertSame( LinkedFile::STATUS_OK, self::stored_status( $revoked ) );
	}

	public function test_cli_lists_linked_media(): void {
		$ok      = self::link( 'Shared With Linker/forest.jpg' );
		$missing = self::link( 'Shared With Linker/harbor.jpg' );
		( new Repository() )->set_status( $missing, LinkedFile::STATUS_MISSING );

		$all = self::cli_list( '' );
		$this->assertSame(
			array(
				$ok      => 'ok',
				$missing => 'missing',
			),
			array_column( $all, 'status', 'id' )
		);
		$this->assertSame( 'folders:Shared With Linker/forest.jpg', array_column( $all, 'source', 'id' )[ $ok ] ?? null );
		$this->assertSame( 'image/jpeg', array_column( $all, 'mime', 'id' )[ $ok ] ?? null );

		$this->assertSame( array( $missing => 'missing' ), array_column( self::cli_list( '--status=missing' ), 'status', 'id' ) );
	}

	public function test_proxy_marks_files_available_again(): void {
		$id = self::link( 'Shared With Linker/forest.jpg' );
		( new Repository() )->set_status( $id, LinkedFile::STATUS_MISSING );

		$response = $this->http( 'GET', (string) wp_parse_url( (string) wp_get_attachment_url( $id ), PHP_URL_PATH ) );
		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'image/jpeg', $response['headers']['content-type'] ?? null );

		// The proxy stores the status after the body was sent: wait for the web server process.
		$attempts = 0;
		while ( LinkedFile::STATUS_OK !== self::stored_status( $id ) && ++$attempts < 20 ) {
			usleep( 100000 );
		}
		$this->assertSame( LinkedFile::STATUS_OK, self::stored_status( $id ) );
	}

	public function test_check_aborts_without_status_change_if_login_fails(): void {
		$id = self::link( 'Shared With Linker/forest.jpg' );
		( new Repository() )->set_status( $id, LinkedFile::STATUS_MISSING );
		$this->configure_connection( 'wrong-app-password' );

		$check = self::cli_check();
		$this->assertSame( 1, $check['code'], $check['output'] );
		$this->assertStringContainsString( 'Login to Nextcloud failed.', $check['output'] );
		$this->assertStringNotContainsString( 'wrong-app-password', $check['output'] );
		$this->assertSame( LinkedFile::STATUS_MISSING, self::stored_status( $id ) );
	}

	public function test_check_aborts_without_status_change_if_nextcloud_is_unreachable(): void {
		$id = self::link( 'Shared With Linker/forest.jpg' );
		$this->configure_connection( null, 'https://wrong-name.test' );

		$check = self::cli_check();
		$this->assertSame( 1, $check['code'], $check['output'] );
		$this->assertStringContainsString( 'Nextcloud is not reachable.', $check['output'] );
		$this->assertSame( LinkedFile::STATUS_OK, self::stored_status( $id ) );
	}

	public function test_check_is_scheduled(): void {
		$this->assertNotFalse( wp_next_scheduled( Scheduler::EVENT ) );
	}
}
