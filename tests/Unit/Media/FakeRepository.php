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
}
