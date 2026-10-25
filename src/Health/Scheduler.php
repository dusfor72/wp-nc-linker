<?php
/**
 * Regular availability checks.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Health;

use WpNcLinker\Media\LinkedFile;
use WpNcLinker\Media\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the check via WP-Cron in batches (servers without WP-Cron use `wp nc-linker check`)
 * and keeps the status up to date when the proxy notices a missing or available file.
 */
final class Scheduler {

	public const EVENT          = 'wpncl_check';
	public const CONTINUE_EVENT = 'wpncl_check_continue';

	public const BATCH_SIZE = 50;

	private const LAST_RUN_OPTION = 'wpncl_last_check';

	private const CYCLE_OPTION = 'wpncl_check_cycle';

	/**
	 * Creates the checker (only when needed).
	 *
	 * @var \Closure(): Checker
	 */
	private \Closure $checker_factory;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository      Attachment storage.
	 * @param \Closure   $checker_factory Creates the checker.
	 * @phpstan-param \Closure(): Checker $checker_factory
	 */
	public function __construct( private readonly Repository $repository, \Closure $checker_factory ) {
		$this->checker_factory = $checker_factory;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( self::EVENT, array( $this, 'start_cycle' ) );
		add_action( self::CONTINUE_EVENT, array( $this, 'continue_cycle' ) );
		add_action( 'wpncl_file_unavailable', array( $this, 'mark_missing' ) );
		add_action( 'wpncl_file_available', array( $this, 'mark_available' ) );
	}

	/**
	 * Schedules the recurring check if it is not scheduled.
	 */
	public function ensure_scheduled(): void {
		if ( false === wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, self::recurrence(), self::EVENT );
		}
	}

	/**
	 * Removes all scheduled checks (deactivation and uninstall).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::EVENT );
		wp_clear_scheduled_hook( self::CONTINUE_EVENT );
	}

	/**
	 * Options written by the scheduler (removed on uninstall).
	 *
	 * @return list<string>
	 */
	public static function options(): array {
		return array( self::LAST_RUN_OPTION, self::CYCLE_OPTION );
	}

	/**
	 * Cron handler of the recurring event: starts a new check cycle.
	 */
	public function start_cycle(): void {
		update_option( self::CYCLE_OPTION, time(), false );
		$this->run_batch();
	}

	/**
	 * Cron handler of the follow-up event: continues the current cycle.
	 */
	public function continue_cycle(): void {
		$this->run_batch();
	}

	/**
	 * Checks the next batch of attachments not yet checked in the current cycle; schedules the
	 * next batch if more are due.
	 */
	public function run_batch(): void {
		$cycle = (int) get_option( self::CYCLE_OPTION, 0 );
		$ids   = array_values(
			array_filter(
				$this->repository->ids_to_check( self::BATCH_SIZE ),
				fn ( int $id ): bool => $this->checked_at( $id ) < $cycle
			)
		);
		if ( array() === $ids ) {
			return;
		}

		$result = ( $this->checker_factory )()->check( $ids );
		update_option(
			self::LAST_RUN_OPTION,
			array(
				'time'    => time(),
				'checked' => count( $result->outcomes ),
				'missing' => $result->count( CheckResult::MISSING ),
				'aborted' => $result->aborted,
			),
			false
		);

		if ( null === $result->aborted && count( $ids ) === self::BATCH_SIZE && false === wp_next_scheduled( self::CONTINUE_EVENT ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CONTINUE_EVENT );
		}
	}

	/**
	 * The proxy received 403/404 for the original.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function mark_missing( $attachment_id ): void {
		$this->repository->set_status( (int) $attachment_id, LinkedFile::STATUS_MISSING );
	}

	/**
	 * The proxy delivered a file that was marked missing.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function mark_available( $attachment_id ): void {
		$this->repository->set_status( (int) $attachment_id, LinkedFile::STATUS_OK );
	}

	/**
	 * Summary of the last cron run, or null.
	 *
	 * @return array{time: int, checked: int, missing: int, aborted: string|null}|null
	 */
	public static function last_run(): ?array {
		$run = get_option( self::LAST_RUN_OPTION );
		if ( ! is_array( $run ) || ! is_int( $run['time'] ?? null ) ) {
			return null;
		}

		return array(
			'time'    => $run['time'],
			'checked' => (int) ( $run['checked'] ?? 0 ),
			'missing' => (int) ( $run['missing'] ?? 0 ),
			'aborted' => is_string( $run['aborted'] ?? null ) ? $run['aborted'] : null,
		);
	}

	/**
	 * Recurrence of the check.
	 */
	private static function recurrence(): string {
		/**
		 * Filters the WP-Cron recurrence of the availability check.
		 *
		 * @param string $recurrence Default "daily"; any key of wp_get_schedules().
		 */
		$recurrence = apply_filters( 'wpncl_check_recurrence', 'daily' );

		return is_string( $recurrence ) && isset( wp_get_schedules()[ $recurrence ] ) ? $recurrence : 'daily';
	}

	/**
	 * Time of the last check of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function checked_at( int $attachment_id ): int {
		return (int) get_post_meta( $attachment_id, Repository::META_CHECKED, true );
	}
}
