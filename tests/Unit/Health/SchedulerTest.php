<?php
/**
 * Tests for the scheduled checks.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Health;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use WpNcLinker\Browse\Area;
use WpNcLinker\Health\Checker;
use WpNcLinker\Health\Scheduler;
use WpNcLinker\Media\LinkedFile;
use WpNcLinker\Media\Locator;
use WpNcLinker\Nextcloud\Response;
use WpNcLinker\Tests\Unit\Diagnostics\FakeClient;
use WpNcLinker\Tests\Unit\Media\FakeRepository;
use WpNcLinker\Tests\Unit\TestCase;

/**
 * Batches, cycles, follow-up events and proxy notifications.
 */
#[CoversClass( Scheduler::class )]
final class SchedulerTest extends TestCase {

	/**
	 * Options written during the test.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Last check time per attachment.
	 *
	 * @var array<int, int>
	 */
	private array $checked = array();

	/**
	 * Scheduled single events.
	 *
	 * @var list<string>
	 */
	private array $single = array();

	/**
	 * Attachment storage.
	 *
	 * @var FakeRepository
	 */
	private FakeRepository $repository;

	/**
	 * Nextcloud client.
	 *
	 * @var FakeClient
	 */
	private FakeClient $client;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = new FakeRepository();
		$this->client     = new FakeClient( array() );

		Functions\when( 'get_option' )->alias( fn ( string $name, mixed $fallback = false ): mixed => $this->options[ $name ] ?? $fallback );
		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias( fn ( int $id ): int => $this->checked[ $id ] ?? 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $time, string $hook ): bool {
				$this->single[] = $hook;
				return true;
			}
		);
	}

	private function scheduler(): Scheduler {
		return new Scheduler(
			$this->repository,
			fn (): Checker => new Checker( $this->client, $this->repository, new Locator( $this->client, $this->repository ) )
		);
	}

	/**
	 * Adds linked files with the given IDs whose source answers with the file.
	 *
	 * @param list<int> $ids Attachment IDs.
	 */
	private function add_files( array $ids ): void {
		$answers = array();
		foreach ( $ids as $id ) {
			$this->repository->files[ $id ]                                        = new LinkedFile( $id, Area::Folders, 'f' . $id . '.jpg', 'image/jpeg', 'f' . $id . '.jpg' );
			$answers[ 'PROPFIND /remote.php/dav/files/linker/f' . $id . '.jpg 0' ] = new Response(
				207,
				array(),
				'<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response><d:href>/remote.php/dav/files/linker/f' . $id . '.jpg</d:href>'
					. '<d:propstat><d:prop><d:resourcetype/><oc:fileid>' . $id . '</oc:fileid></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>'
			);
		}
		$this->repository->due = $ids;
		$this->client          = new FakeClient( $answers );
	}

	public function test_full_batch_schedules_a_follow_up(): void {
		$this->add_files( range( 1, Scheduler::BATCH_SIZE + 5 ) );

		$this->scheduler()->start_cycle();

		$this->assertCount( Scheduler::BATCH_SIZE, $this->repository->statuses );
		$this->assertSame( array( Scheduler::CONTINUE_EVENT ), $this->single );
		$run = Scheduler::last_run();
		$this->assertNotNull( $run );
		$this->assertSame( Scheduler::BATCH_SIZE, $run['checked'] );
		$this->assertSame( 0, $run['missing'] );
		$this->assertNull( $run['aborted'] );
	}

	public function test_files_checked_in_this_cycle_are_skipped(): void {
		$this->add_files( array( 1, 2, 3 ) );
		$this->options['wpncl_check_cycle'] = 1000;
		$this->checked                      = array(
			1 => 1500,
			2 => 900,
			3 => 1000,
		);

		$this->scheduler()->continue_cycle();

		$this->assertSame( array( 2 ), array_keys( $this->repository->statuses ) );
		$this->assertSame( array(), $this->single );
	}

	public function test_nothing_due_writes_nothing(): void {
		$this->add_files( array( 1 ) );
		$this->options['wpncl_check_cycle'] = 1000;
		$this->checked                      = array( 1 => 2000 );

		$this->scheduler()->continue_cycle();

		$this->assertNull( Scheduler::last_run() );
	}

	public function test_aborted_run_is_recorded_and_not_continued(): void {
		$this->add_files( range( 1, Scheduler::BATCH_SIZE ) );
		$this->client = new FakeClient( array() );

		$this->scheduler()->start_cycle();

		$run = Scheduler::last_run();
		$this->assertNotNull( $run );
		$this->assertSame( 'Unexpected answer from Nextcloud (HTTP 500).', $run['aborted'] );
		$this->assertSame( array(), $this->repository->statuses );
		$this->assertSame( array(), $this->single );
	}

	public function test_proxy_notifications_set_the_status(): void {
		$scheduler = $this->scheduler();

		$scheduler->mark_missing( 4 );
		$scheduler->mark_available( 5 );

		$this->assertSame(
			array(
				4 => LinkedFile::STATUS_MISSING,
				5 => LinkedFile::STATUS_OK,
			),
			$this->repository->statuses
		);
	}

	public function test_recurrence_falls_back_to_daily(): void {
		$recurrence = null;
		Functions\when( 'wp_get_schedules' )->justReturn(
			array(
				'daily'  => array(),
				'hourly' => array(),
			)
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( int $time, string $value ) use ( &$recurrence ): bool {
				$recurrence = $value;
				return true;
			}
		);

		Functions\when( 'apply_filters' )->justReturn( 'hourly' );
		$this->scheduler()->ensure_scheduled();
		$this->assertSame( 'hourly', $recurrence );

		Functions\when( 'apply_filters' )->justReturn( 'every_second' );
		$this->scheduler()->ensure_scheduled();
		$this->assertSame( 'daily', $recurrence );
	}
}
