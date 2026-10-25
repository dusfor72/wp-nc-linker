<?php
/**
 * Constants defined at runtime (plugin main file, WordPress cookie constants), for static analysis.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

define( 'WPNCL_VERSION', '0.1.0-dev' );
define( 'WPNCL_PLUGIN_FILE', dirname( __DIR__ ) . '/wp-nc-linker.php' );
define( 'WPNCL_PLUGIN_DIR', dirname( __DIR__ ) );
define( 'AUTH_COOKIE', 'wordpress_' );
define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_' );
