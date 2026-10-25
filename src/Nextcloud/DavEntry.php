<?php
/**
 * One resource from a WebDAV multistatus response.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Nextcloud;

defined( 'ABSPATH' ) || exit;

/**
 * Properties of a file, folder or album as reported by Nextcloud.
 */
final class DavEntry {

	/**
	 * Constructor.
	 *
	 * @param string      $href             Href as returned (URL-encoded, absolute path).
	 * @param bool        $is_collection    Folder or album.
	 * @param int|null    $file_id          oc:fileid.
	 * @param string|null $content_type     d:getcontenttype.
	 * @param int|null    $size             d:getcontentlength (files) or oc:size.
	 * @param string|null $etag             d:getetag without quotes.
	 * @param int|null    $last_modified    d:getlastmodified as Unix timestamp.
	 * @param bool|null   $has_preview      nc:has-preview.
	 * @param bool        $download_allowed False if the share has permissions/download = false.
	 * @param int|null    $width            nc:metadata-photos-size width.
	 * @param int|null    $height           nc:metadata-photos-size height.
	 * @param string|null $display_name     nc:original-name (albums) or nc:file-name (album photos).
	 * @param int|null    $item_count       nc:nbItems (albums).
	 */
	public function __construct(
		public readonly string $href,
		public readonly bool $is_collection,
		public readonly ?int $file_id = null,
		public readonly ?string $content_type = null,
		public readonly ?int $size = null,
		public readonly ?string $etag = null,
		public readonly ?int $last_modified = null,
		public readonly ?bool $has_preview = null,
		public readonly bool $download_allowed = true,
		public readonly ?int $width = null,
		public readonly ?int $height = null,
		public readonly ?string $display_name = null,
		public readonly ?int $item_count = null,
	) {
	}

	/**
	 * Decoded last path segment of the href.
	 */
	public function name(): string {
		return rawurldecode( basename( rtrim( $this->href, '/' ) ) );
	}

	/**
	 * Name to show: the display name if reported, otherwise the last path segment.
	 */
	public function label(): string {
		return $this->display_name ?? $this->name();
	}

	/**
	 * Whether the resource is an image according to its MIME type.
	 */
	public function is_image(): bool {
		return null !== $this->content_type && str_starts_with( $this->content_type, 'image/' );
	}
}
