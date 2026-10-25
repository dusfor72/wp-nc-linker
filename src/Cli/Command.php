<?php
/**
 * WP-CLI commands.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Cli;

use WpNcLinker\Diagnostics\CheckResult;
use WpNcLinker\Diagnostics\ConnectionTest;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp nc-linker …` commands.
 */
final class Command {

	/**
	 * Creates the connection test.
	 *
	 * @var \Closure(): ConnectionTest
	 */
	private \Closure $test_factory;

	/**
	 * Constructor.
	 *
	 * @param \Closure $test_factory Creates the connection test.
	 * @phpstan-param \Closure(): ConnectionTest $test_factory
	 */
	public function __construct( \Closure $test_factory ) {
		$this->test_factory = $test_factory;
	}

	/**
	 * Registers the commands.
	 */
	public function register(): void {
		WP_CLI::add_command(
			'nc-linker test',
			array( $this, 'test' ),
			array(
				'shortdesc' => 'Tests the connection to Nextcloud (server, TLS, login, folders, albums, preview).',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'Output format.',
						'optional'    => true,
						'default'     => 'table',
						'options'     => array( 'table', 'json', 'csv', 'yaml' ),
					),
				),
			)
		);
	}

	/**
	 * Runs the connection test; exits with status 1 if a check failed.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function test( array $args, array $assoc_args ): void {
		$results = ( $this->test_factory )()->run();
		$rows    = array_map(
			static fn ( CheckResult $result ): array => array(
				'check'   => $result->id,
				'status'  => $result->status->value,
				'message' => $result->message,
			),
			$results
		);

		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'check', 'status', 'message' ) );

		if ( ConnectionTest::has_errors( $results ) ) {
			WP_CLI::error( 'The connection test failed.' );
		}
		if ( 'table' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::success( 'The connection works.' );
		}
	}
}
