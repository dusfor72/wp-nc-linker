<?php
/**
 * Output of proxy responses.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Proxy;

defined( 'ABSPATH' ) || exit;

/**
 * Receives status, headers and body chunks of a proxy response.
 */
interface ResponseWriter {

	/**
	 * Sets the status code (called once, before headers).
	 *
	 * @param int $code HTTP status.
	 */
	public function status( int $code ): void;

	/**
	 * Sets a header.
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value (without line breaks).
	 */
	public function header( string $name, string $value ): void;

	/**
	 * Sends a part of the body.
	 *
	 * @param string $chunk Bytes.
	 */
	public function body( string $chunk ): void;
}
