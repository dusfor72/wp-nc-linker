<?php
/**
 * Data of a linked (virtual) attachment.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Media;

use WpNcLinker\Browse\Area;

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin stores about a Nextcloud file.
 */
final class LinkedFile {

	public const STATUS_OK      = 'ok';
	public const STATUS_MISSING = 'missing';

	/**
	 * Constructor.
	 *
	 * @param int      $file_id  Nextcloud file ID.
	 * @param Area     $area     Source area.
	 * @param string   $path     Path relative to the area root.
	 * @param string   $mime     MIME type.
	 * @param string   $name     Original file name.
	 * @param int|null $size     Size in bytes.
	 * @param int|null $width    Width (images).
	 * @param int|null $height   Height (images).
	 * @param string   $etag     ETag of the original.
	 * @param int|null $modified Last modification (Unix time).
	 * @param string   $status   self::STATUS_*.
	 */
	public function __construct(
		public readonly int $file_id,
		public readonly Area $area,
		public readonly string $path,
		public readonly string $mime,
		public readonly string $name,
		public readonly ?int $size = null,
		public readonly ?int $width = null,
		public readonly ?int $height = null,
		public readonly string $etag = '',
		public readonly ?int $modified = null,
		public readonly string $status = self::STATUS_OK,
	) {
	}

	/**
	 * Whether the file is an image with known dimensions.
	 */
	public function is_sized_image(): bool {
		return str_starts_with( $this->mime, 'image/' ) && null !== $this->width && null !== $this->height;
	}

	/**
	 * URL-safe file name.
	 */
	public function slug(): string {
		return FileName::slug( $this->name );
	}
}
