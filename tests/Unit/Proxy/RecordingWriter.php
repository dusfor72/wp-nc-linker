<?php
/**
 * Test double for the response output.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Proxy;

use WpNcLinker\Proxy\ResponseWriter;

/**
 * Records status, headers and body.
 */
final class RecordingWriter implements ResponseWriter {

	/**
	 * Status (0 = not set).
	 *
	 * @var int
	 */
	public int $status = 0;

	/**
	 * Headers with lowercase names.
	 *
	 * @var array<string, string>
	 */
	public array $headers = array();

	/**
	 * Body.
	 *
	 * @var string
	 */
	public string $body = '';

	/**
	 * Number of body chunks.
	 *
	 * @var int
	 */
	public int $chunks = 0;

	public function status( int $code ): void {
		$this->status = $code;
	}

	public function header( string $name, string $value ): void {
		$this->headers[ strtolower( $name ) ] = $value;
	}

	public function body( string $chunk ): void {
		$this->body .= $chunk;
		++$this->chunks;
	}
}
