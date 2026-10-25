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
use WpNcLinker\Health\CheckResult as HealthResult;
use WpNcLinker\Health\Checker;
use WpNcLinker\Media\Repository;
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
	 * Creates the checker.
	 *
	 * @var \Closure(): Checker
	 */
	private \Closure $checker_factory;

	/**
	 * Constructor.
	 *
	 * @param \Closure   $test_factory    Creates the connection test.
	 * @param \Closure   $checker_factory Creates the availability checker.
	 * @param Repository $repository      Attachment storage.
	 * @phpstan-param \Closure(): ConnectionTest $test_factory
	 * @phpstan-param \Closure(): Checker $checker_factory
	 */
	public function __construct( \Closure $test_factory, \Closure $checker_factory, private readonly Repository $repository ) {
		$this->test_factory    = $test_factory;
		$this->checker_factory = $checker_factory;
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
				'synopsis'  => array( self::format_argument() ),
			)
		);

		WP_CLI::add_command(
			'nc-linker check',
			array( $this, 'check' ),
			array(
				'shortdesc' => 'Checks all linked files against Nextcloud and marks missing ones (for servers that run this from a timer instead of WP-Cron).',
				'synopsis'  => array( self::format_argument() ),
			)
		);
	}

	/**
	 * Checks all linked files; exits with status 1 if Nextcloud could not be reached.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function check( array $args, array $assoc_args ): void {
		$result = ( $this->checker_factory )()->check( $this->repository->all_ids() );

		$rows = array();
		foreach ( $result->outcomes as $id => $outcome ) {
			$file   = $this->repository->get( $id );
			$rows[] = array(
				'id'      => $id,
				'status'  => $outcome,
				'source'  => null === $file ? '' : $file->area->value . ':' . $file->path,
				'file_id' => null === $file ? '' : $file->file_id,
			);
		}
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'status', 'source', 'file_id' ) );

		if ( null !== $result->aborted ) {
			WP_CLI::error( 'Check aborted, no status was changed for the remaining files: ' . $result->aborted );
		}
		if ( 'table' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::success(
				sprintf(
					'%d checked, %d moved, %d missing.',
					count( $result->outcomes ),
					$result->count( HealthResult::RELOCATED ),
					$result->count( HealthResult::MISSING )
				)
			);
		}
	}

	/**
	 * Synopsis of the --format argument.
	 *
	 * @return array<string, mixed>
	 */
	private static function format_argument(): array {
		return array(
			'type'        => 'assoc',
			'name'        => 'format',
			'description' => 'Output format.',
			'optional'    => true,
			'default'     => 'table',
			'options'     => array( 'table', 'json', 'csv', 'yaml' ),
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
