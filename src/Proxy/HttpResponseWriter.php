<?php
/**
 * Output of proxy responses to the client.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Proxy;

defined( 'ABSPATH' ) || exit;

/**
 * Writes directly to the client: drops output buffers and headers set before (e.g. cookies of
 * other plugins), sends each chunk immediately so nothing is held in memory.
 */
final class HttpResponseWriter implements ResponseWriter {

	/**
	 * Sets the status code and resets previous output state.
	 *
	 * @param int $code HTTP status.
	 */
	public function status( int $code ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( ! headers_sent() ) {
			header_remove();
			status_header( $code );
		}
		ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Required for streaming.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large originals are streamed for longer than max_execution_time.
		}
	}

	/**
	 * Sets a header.
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 */
	public function header( string $name, string $value ): void {
		if ( ! headers_sent() ) {
			header( $name . ': ' . str_replace( array( "\r", "\n" ), '', $value ), true );
		}
	}

	/**
	 * Sends a chunk.
	 *
	 * @param string $chunk Bytes.
	 */
	public function body( string $chunk ): void {
		echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File content with explicit Content-Type and nosniff.
		flush();
	}
}
