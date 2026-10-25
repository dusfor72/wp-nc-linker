<?php
/**
 * Plugin Name:       WP Nextcloud Linker
 * Description:       Use images and documents from Nextcloud folders and albums in the media library without copying them to WordPress.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-nc-linker
 * Domain Path:       /languages
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

const WPNCL_VERSION     = '0.1.0';
const WPNCL_PLUGIN_FILE = __FILE__;
const WPNCL_PLUGIN_DIR  = __DIR__;

require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, array( \WpNcLinker\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WpNcLinker\Plugin::class, 'deactivate' ) );

\WpNcLinker\Plugin::instance()->register();
