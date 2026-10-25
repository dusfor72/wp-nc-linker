<?php
/**
 * Tests for the plugin bootstrap.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Config\Settings;
use WpNcLinker\Plugin;

/**
 * Plugin bootstrap.
 */
#[CoversClass( Plugin::class )]
final class PluginTest extends TestCase {

	public function test_instance_is_shared(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_register_hooks_textdomain_on_init(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Actions\expectAdded( 'init' )->once()->with( array( Plugin::instance(), 'load_textdomain' ) );
		Actions\expectAdded( 'init' )->once()->with( \Mockery::type( 'array' ), 0 );
		Actions\expectAdded( 'admin_menu' )->never();

		Plugin::instance()->register();
	}

	public function test_register_adds_settings_page_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'plugin_basename' )->justReturn( 'wp-nc-linker/wp-nc-linker.php' );
		Actions\expectAdded( 'admin_menu' )->once();
		Actions\expectAdded( 'admin_init' )->once();
		Actions\expectAdded( 'admin_post_wpncl_connection_test' )->once();
		Filters\expectAdded( 'option_page_capability_wpncl' )->once();
		Filters\expectAdded( 'plugin_action_links_wp-nc-linker/wp-nc-linker.php' )->once();

		Plugin::instance()->register();
	}

	public function test_activate_creates_option_with_defaults(): void {
		Functions\expect( 'add_option' )->once()->with( Settings::OPTION, Settings::defaults() );

		Plugin::activate();
	}

	public function test_load_textdomain_uses_bundled_languages_dir(): void {
		Functions\when( 'plugin_basename' )->justReturn( 'wp-nc-linker/wp-nc-linker.php' );
		Functions\expect( 'load_plugin_textdomain' )
			->once()
			->with( 'wp-nc-linker', false, 'wp-nc-linker/languages' );

		Plugin::instance()->load_textdomain();
	}
}
