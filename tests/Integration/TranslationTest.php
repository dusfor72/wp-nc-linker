<?php
/**
 * Integration test for the bundled de_DE translation.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use WpNcLinker\Admin\MediaTab;
use WpNcLinker\Plugin;

/**
 * PHP strings (MO) and the strings of the media tab script (JSON) are translated.
 */
#[CoversNothing]
final class TranslationTest extends TestCase {

	public function test_de_de_is_loaded_for_php_and_script(): void {
		$locale = static fn (): string => 'de_DE';
		add_filter( 'determine_locale', $locale );
		unload_textdomain( 'wp-nc-linker' );
		try {
			Plugin::instance()->load_textdomain();
			$this->assertSame( 'Verbindungstest', __( 'Connection test', 'wp-nc-linker' ) );
			$this->assertSame( '2 eigene Alben.', sprintf( _n( '%d own album.', '%d own albums.', 2, 'wp-nc-linker' ), 2 ) );

			wp_register_script( MediaTab::HANDLE, plugins_url( 'build/media-tab.js', WPNCL_PLUGIN_FILE ), array(), WPNCL_VERSION, true );
			$json = load_script_textdomain( MediaTab::HANDLE, 'wp-nc-linker', WPNCL_PLUGIN_DIR . '/languages' );
			$this->assertIsString( $json );
			$this->assertStringContainsString( '"Add to media library":["Zur Mediathek hinzuf', $json );
		} finally {
			wp_deregister_script( MediaTab::HANDLE );
			remove_filter( 'determine_locale', $locale );
			unload_textdomain( 'wp-nc-linker' );
		}
	}
}
