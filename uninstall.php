<?php
/**
 * Uninstallation: removes the plugin data (see WpNcLinker\Uninstaller).
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/autoload.php';

\WpNcLinker\Uninstaller::run();
