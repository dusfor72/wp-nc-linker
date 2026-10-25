<?php
/**
 * Entry shown in the Nextcloud browser.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Browse;

defined( 'ABSPATH' ) || exit;

/**
 * Folder, album or file of a listing.
 *
 * @phpstan-type ItemArray array{
 *     kind: string,
 *     name: string,
 *     path: string,
 *     file_id: int|null,
 *     mime: string|null,
 *     size: int|null,
 *     modified: int|null,
 *     width: int|null,
 *     height: int|null,
 *     count: int|null,
 *     preview: bool,
 *     selectable: bool,
 *     reason: string|null
 * }
 */
final class Item {

	public const FOLDER = 'folder';
	public const ALBUM  = 'album';
	public const FILE   = 'file';

	public const REASON_DOWNLOAD_RESTRICTED = 'download_restricted';

	/**
	 * Constructor.
	 *
	 * @param string      $kind       self::FOLDER, self::ALBUM or self::FILE.
	 * @param string      $name       Display name.
	 * @param string      $path       Path relative to the area root.
	 * @param int|null    $file_id    File ID (files only).
	 * @param string|null $mime       MIME type (files only).
	 * @param int|null    $size       Size in bytes.
	 * @param int|null    $modified   Last modification (Unix time).
	 * @param int|null    $width      Image width.
	 * @param int|null    $height     Image height.
	 * @param int|null    $count      Number of items (albums).
	 * @param bool        $preview    Whether Nextcloud can render a preview.
	 * @param bool        $selectable Whether the file can be linked.
	 * @param string|null $reason     Why it cannot be linked.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $name,
		public readonly string $path,
		public readonly ?int $file_id = null,
		public readonly ?string $mime = null,
		public readonly ?int $size = null,
		public readonly ?int $modified = null,
		public readonly ?int $width = null,
		public readonly ?int $height = null,
		public readonly ?int $count = null,
		public readonly bool $preview = false,
		public readonly bool $selectable = false,
		public readonly ?string $reason = null,
	) {
	}

	/**
	 * Array form (cache and REST).
	 *
	 * @return ItemArray
	 */
	public function to_array(): array {
		return array(
			'kind'       => $this->kind,
			'name'       => $this->name,
			'path'       => $this->path,
			'file_id'    => $this->file_id,
			'mime'       => $this->mime,
			'size'       => $this->size,
			'modified'   => $this->modified,
			'width'      => $this->width,
			'height'     => $this->height,
			'count'      => $this->count,
			'preview'    => $this->preview,
			'selectable' => $this->selectable,
			'reason'     => $this->reason,
		);
	}
}
