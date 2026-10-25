<?php
/**
 * Integration tests for the uninstallation (F10).
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use WpNcLinker\Config\Settings;
use WpNcLinker\Health\Scheduler;
use WpNcLinker\Media\Importer;
use WpNcLinker\Media\Repository;
use WpNcLinker\Plugin;
use WpNcLinker\Uninstaller;

/**
 * `wp plugin uninstall` removes options, cron events and transients; linked media only on request.
 */
#[CoversNothing]
final class UninstallTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->configure_connection();
		self::delete_linked_attachments();
	}

	protected function tearDown(): void {
		self::delete_linked_attachments();
		self::scheduler()->ensure_scheduled();
		parent::tearDown();
	}

	/**
	 * Scheduler (only used to schedule the event).
	 *
	 * @throws \LogicException If the scheduler tries to run a check.
	 */
	private static function scheduler(): Scheduler {
		return new Scheduler( new Repository(), static fn () => throw new \LogicException( 'Not used.' ) );
	}

	/**
	 * Links a folder file, schedules the check and fills the caches; returns the attachment ID.
	 */
	private static function prepare(): int {
		$plugin = Plugin::instance();
		$id     = ( new Importer( $plugin->settings(), $plugin->client(), new Repository() ) )->import( 'folders', 'Shared With Linker/forest.jpg', 0 );

		self::scheduler()->ensure_scheduled();
		update_option( Scheduler::options()[0], array( 'time' => time() ), false );
		set_transient( 'wpncl_browse_test', array( 'x' ), 60 );
		set_transient( 'wpncl_seen_1', array( 1 ), 60 );

		return $id;
	}

	/**
	 * Asserts that only the linked attachments may remain.
	 */
	private function assert_plugin_data_removed(): void {
		wp_cache_flush();
		$this->assertFalse( get_option( Settings::OPTION ) );
		foreach ( Scheduler::options() as $name ) {
			$this->assertFalse( get_option( $name ), $name );
		}
		$this->assertFalse( wp_next_scheduled( Scheduler::EVENT ) );
		$this->assertFalse( get_transient( 'wpncl_browse_test' ) );
		$this->assertFalse( get_transient( 'wpncl_seen_1' ) );
	}

	public function test_uninstall_keeps_linked_media_by_default(): void {
		$id = self::prepare();

		$output = array();
		$code   = 0;
		exec( 'cd /var/www/html && wp plugin uninstall wp-nc-linker --deactivate --skip-delete 2>&1', $output, $code );
		$reactivate = array();
		exec( 'cd /var/www/html && wp plugin activate wp-nc-linker 2>&1', $reactivate );
		$this->assertSame( 0, $code, implode( "\n", $output ) );

		// Activation adds the default settings again; everything else must be gone.
		wp_cache_flush();
		$this->assertIsArray( get_option( Settings::OPTION ) );
		delete_option( Settings::OPTION );
		$this->assert_plugin_data_removed();

		$this->assertInstanceOf( \WP_Post::class, get_post( $id ) );
		$this->assertNotNull( ( new Repository() )->get( $id ) );
	}

	public function test_uninstall_deletes_linked_media_if_set(): void {
		$this->set_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION ), array( 'delete_on_uninstall' => true ) ) );
		$id = self::prepare();

		$deleted = array();
		$capture = static function ( $file ) use ( &$deleted ) {
			$deleted[] = $file;
			return $file;
		};
		add_filter( 'wp_delete_file', $capture, PHP_INT_MAX );
		try {
			Uninstaller::run();
		} finally {
			remove_filter( 'wp_delete_file', $capture, PHP_INT_MAX );
		}

		$this->assert_plugin_data_removed();
		$this->assertNull( get_post( $id ) );
		$this->assertSame( array(), array_filter( $deleted ) );
	}
}
