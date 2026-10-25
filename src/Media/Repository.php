<?php
/**
 * Storage of linked attachments.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Media;

use WpNcLinker\Browse\Area;

defined( 'ABSPATH' ) || exit;

/**
 * Linked files are attachments (post type "attachment") without a file in uploads; their data is
 * kept in post meta.
 */
class Repository {

	public const META_FILE_ID  = '_wpncl_file_id';
	public const META_SOURCE   = '_wpncl_source';
	public const META_ETAG     = '_wpncl_etag';
	public const META_MODIFIED = '_wpncl_modified';
	public const META_STATUS   = '_wpncl_status';
	public const META_CHECKED  = '_wpncl_checked';
	public const META_NAME     = '_wpncl_name';

	/**
	 * Attachment ID linked to a Nextcloud file ID, or null.
	 *
	 * @param int $file_id File ID.
	 */
	public function find_by_file_id( int $file_id ): ?int {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed by meta_key; runs only when linking files.
					array(
						'key'   => self::META_FILE_ID,
						'value' => $file_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return isset( $ids[0] ) ? (int) $ids[0] : null;
	}

	/**
	 * Whether an attachment is a linked file.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function is_linked( int $attachment_id ): bool {
		return (int) get_post_meta( $attachment_id, self::META_FILE_ID, true ) > 0;
	}

	/**
	 * Data of a linked attachment, or null.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function get( int $attachment_id ): ?LinkedFile {
		$file_id = (int) get_post_meta( $attachment_id, self::META_FILE_ID, true );
		$source  = get_post_meta( $attachment_id, self::META_SOURCE, true );
		if ( $file_id <= 0 || ! is_array( $source ) ) {
			return null;
		}
		$area = Area::tryFrom( is_string( $source['area'] ?? null ) ? $source['area'] : '' );
		if ( null === $area || ! is_string( $source['path'] ?? null ) ) {
			return null;
		}

		$meta     = wp_get_attachment_metadata( $attachment_id, true );
		$meta     = is_array( $meta ) ? $meta : array();
		$name     = get_post_meta( $attachment_id, self::META_NAME, true );
		$modified = get_post_meta( $attachment_id, self::META_MODIFIED, true );
		$status   = get_post_meta( $attachment_id, self::META_STATUS, true );

		return new LinkedFile(
			file_id: $file_id,
			area: $area,
			path: $source['path'],
			mime: (string) get_post_mime_type( $attachment_id ),
			name: is_string( $name ) && '' !== $name ? $name : 'file',
			size: isset( $meta['filesize'] ) && is_int( $meta['filesize'] ) ? $meta['filesize'] : null,
			width: isset( $meta['width'] ) && is_int( $meta['width'] ) ? $meta['width'] : null,
			height: isset( $meta['height'] ) && is_int( $meta['height'] ) ? $meta['height'] : null,
			etag: (string) get_post_meta( $attachment_id, self::META_ETAG, true ),
			modified: is_numeric( $modified ) ? (int) $modified : null,
			status: LinkedFile::STATUS_MISSING === $status ? LinkedFile::STATUS_MISSING : LinkedFile::STATUS_OK,
		);
	}

	/**
	 * Creates an attachment for a file.
	 *
	 * @param LinkedFile $file      File.
	 * @param int        $parent_id Post the attachment belongs to (0 = none).
	 * @return int Attachment ID.
	 * @throws \RuntimeException If WordPress could not create the post.
	 */
	public function create( LinkedFile $file, int $parent_id ): int {
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => sanitize_text_field( FileName::title( $file->name ) ),
				'post_mime_type' => $file->mime,
				'post_status'    => 'inherit',
				'post_content'   => '',
			),
			false,
			$parent_id,
			true
		);
		if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
			throw new \RuntimeException( 'Attachment could not be created.' );
		}

		$this->save( $attachment_id, $file );

		return $attachment_id;
	}

	/**
	 * Stores the data of a file on an attachment (also used to update source, ETag or status).
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param LinkedFile $file          File.
	 */
	public function save( int $attachment_id, LinkedFile $file ): void {
		update_post_meta( $attachment_id, self::META_FILE_ID, $file->file_id );
		update_post_meta(
			$attachment_id,
			self::META_SOURCE,
			array(
				'area' => $file->area->value,
				'path' => $file->path,
			)
		);
		update_post_meta( $attachment_id, self::META_NAME, $file->name );
		update_post_meta( $attachment_id, self::META_ETAG, $file->etag );
		update_post_meta( $attachment_id, self::META_MODIFIED, $file->modified ?? 0 );
		update_post_meta( $attachment_id, self::META_STATUS, $file->status );
		update_post_meta( $attachment_id, self::META_CHECKED, time() );

		$meta = array( 'file' => $file->slug() );
		if ( null !== $file->size ) {
			$meta['filesize'] = $file->size;
		}
		if ( $file->is_sized_image() ) {
			$meta['width']      = $file->width;
			$meta['height']     = $file->height;
			$meta['sizes']      = array();
			$meta['image_meta'] = array();
		}
		wp_update_attachment_metadata( $attachment_id, $meta );
	}

	/**
	 * Sets the availability status of a linked attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $status        LinkedFile::STATUS_*.
	 */
	public function set_status( int $attachment_id, string $status ): void {
		update_post_meta( $attachment_id, self::META_STATUS, LinkedFile::STATUS_MISSING === $status ? LinkedFile::STATUS_MISSING : LinkedFile::STATUS_OK );
		update_post_meta( $attachment_id, self::META_CHECKED, time() );
	}

	/**
	 * Linked attachments with the given status, newest first.
	 *
	 * @param string $status LinkedFile::STATUS_*.
	 * @param int    $limit  Maximum number (-1 = all).
	 * @return list<int>
	 */
	public function ids_with_status( string $status, int $limit = -1 ): array {
		return $this->query(
			array(
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin views and checks only.
					array(
						'key'   => self::META_STATUS,
						'value' => $status,
					),
				),
			)
		);
	}

	/**
	 * Number of linked attachments with the given status.
	 *
	 * @param string $status LinkedFile::STATUS_*.
	 */
	public function count_with_status( string $status ): int {
		return count( $this->ids_with_status( $status ) );
	}

	/**
	 * Linked attachments, least recently checked first.
	 *
	 * @param int $limit Maximum number (-1 = all).
	 * @return list<int>
	 */
	public function ids_to_check( int $limit = -1 ): array {
		return $this->query(
			array(
				'posts_per_page' => $limit,
				'meta_key'       => self::META_CHECKED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Checks only.
				'orderby'        => array(
					'meta_value_num' => 'ASC',
					'ID'             => 'ASC',
				),
			)
		);
	}

	/**
	 * All linked attachments (IDs).
	 *
	 * @return list<int>
	 */
	public function all_ids(): array {
		return $this->query(
			array(
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => self::META_FILE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Listing linked attachments only.
			)
		);
	}

	/**
	 * Queries attachment IDs.
	 *
	 * @param array<string, mixed> $args WP_Query arguments.
	 * @return list<int>
	 */
	private function query( array $args ): array {
		$ids = get_posts(
			array_merge(
				array(
					'post_type'        => 'attachment',
					'post_status'      => 'any',
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				),
				$args
			)
		);

		return array_values( array_map( static fn ( $id ): int => is_object( $id ) ? (int) $id->ID : (int) $id, $ids ) );
	}
}
