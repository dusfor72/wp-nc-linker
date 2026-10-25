<?php
/**
 * Test double for the attachment storage.
 *
 * @package WpNcLinker\Tests
 */

declare(strict_types=1);

namespace WpNcLinker\Tests\Unit\Media;

use WpNcLinker\Media\LinkedFile;
use WpNcLinker\Media\Repository;

/**
 * Records created and saved files instead of writing posts.
 */
final class FakeRepository extends Repository {

	/**
	 * Created files.
	 *
	 * @var list<LinkedFile>
	 */
	public array $created = array();

	/**
	 * Saved files by attachment ID.
	 *
	 * @var array<int, LinkedFile>
	 */
	public array $saved = array();

	/**
	 * Existing attachment IDs by file ID.
	 *
	 * @var array<int, int>
	 */
	public array $existing = array();

	/**
	 * Linked files returned by get(), by attachment ID.
	 *
	 * @var array<int, LinkedFile>
	 */
	public array $files = array();

	/**
	 * Stored statuses by attachment ID.
	 *
	 * @var array<int, string>
	 */
	public array $statuses = array();

	/**
	 * IDs returned by ids_to_check().
	 *
	 * @var list<int>
	 */
	public array $due = array();

	public function get( int $attachment_id ): ?LinkedFile {
		return $this->files[ $attachment_id ] ?? null;
	}

	public function find_by_file_id( int $file_id ): ?int {
		return $this->existing[ $file_id ] ?? null;
	}

	public function create( LinkedFile $file, int $parent_id ): int {
		$this->created[] = $file;

		return 100 + count( $this->created );
	}

	public function save( int $attachment_id, LinkedFile $file ): void {
		$this->saved[ $attachment_id ] = $file;
	}

	public function set_status( int $attachment_id, string $status ): void {
		$this->statuses[ $attachment_id ] = $status;
	}

	public function ids_to_check( int $limit = -1 ): array {
		return -1 === $limit ? $this->due : array_slice( $this->due, 0, $limit );
	}
}
