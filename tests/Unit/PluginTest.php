<?php
/**
 * Tests for the plugin bootstrap.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
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
		Actions\expectAdded( 'init' )->once()->with( array( Plugin::instance(), 'load_textdomain' ) );

		Plugin::instance()->register();
	}

	public function test_load_textdomain_uses_bundled_languages_dir(): void {
		Functions\when( 'plugin_basename' )->justReturn( 'wp-nc-linker/wp-nc-linker.php' );
		Functions\expect( 'load_plugin_textdomain' )
			->once()
			->with( 'wp-nc-linker', false, 'wp-nc-linker/languages' );

		Plugin::instance()->load_textdomain();
	}
}
