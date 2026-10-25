<?php
/**
 * Minimal WP_Error for unit tests (WordPress is not loaded).
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting, WordPress.NamingConventions.PrefixAllGlobals -- Test stub of a core class.

/**
 * Subset of the core WP_Error class.
 */
class WP_Error {

	public function __construct( private string $code = '', private string $message = '' ) {
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}
