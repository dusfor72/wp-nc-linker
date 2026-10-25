<?php
/**
 * WordPress integration of linked attachments.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Media;

use WpNcLinker\Proxy\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Makes linked attachments behave like uploaded media: URLs point to the proxy, every
 * registered image size is reported with the dimensions Nextcloud will deliver, srcset uses
 * proxy URLs, and nothing is ever deleted from or written to the uploads directory.
 */
final class AttachmentFilters {

	/**
	 * Attachments currently being deleted (their "files" must not be touched).
	 *
	 * @var array<int, true>
	 */
	private array $deleting = array();

	/**
	 * Constructor.
	 *
	 * @param Repository     $repository Attachment storage.
	 * @param Url            $url        Proxy URLs.
	 * @param SizeCalculator $sizes      Preview size prediction.
	 */
	public function __construct(
		private readonly Repository $repository,
		private readonly Url $url,
		private readonly SizeCalculator $sizes,
	) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_get_attachment_url', array( $this, 'attachment_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_metadata', array( $this, 'attachment_metadata' ), 10, 2 );
		add_filter( 'image_downsize', array( $this, 'image_downsize' ), 10, 3 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'image_srcset' ), 10, 5 );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'prepare_for_js' ), 10, 2 );
		add_filter( 'get_attached_file', array( $this, 'attached_file' ), 10, 2 );
		add_action( 'delete_attachment', array( $this, 'before_delete' ) );
		add_action( 'deleted_post', array( $this, 'after_delete' ) );
		add_filter( 'wp_delete_file', array( $this, 'protect_files' ) );
	}

	/**
	 * Proxy URL of the original.
	 *
	 * @param string|false $url           URL.
	 * @param int          $attachment_id Attachment ID.
	 * @return string|false
	 */
	public function attachment_url( $url, $attachment_id ) {
		$file = $this->linked( (int) $attachment_id );

		return null === $file ? $url : $this->url->build( (int) $attachment_id, Url::FULL, $file->slug() );
	}

	/**
	 * Adds the image sizes (current registered sizes, delivered dimensions).
	 *
	 * @param mixed $data          Metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function attachment_metadata( $data, $attachment_id ) {
		if ( ! is_array( $data ) || ! isset( $data['width'], $data['height'], $data['file'] ) || ! $this->repository->is_linked( (int) $attachment_id ) ) {
			return $data;
		}

		$data['sizes'] = $this->sizes_for( (int) $data['width'], (int) $data['height'], (string) $data['file'], (string) get_post_mime_type( (int) $attachment_id ) );

		return $data;
	}

	/**
	 * URL and dimensions of a size.
	 *
	 * @param array<mixed>|false $downsize      Short-circuit value.
	 * @param int                $attachment_id Attachment ID.
	 * @param string|int[]       $size          Size name or [width, height].
	 * @return array<mixed>|false
	 */
	public function image_downsize( $downsize, $attachment_id, $size ) {
		$attachment_id = (int) $attachment_id;
		$file          = $this->linked( $attachment_id );
		if ( null === $file || ! $file->is_sized_image() ) {
			return $downsize;
		}

		$meta  = wp_get_attachment_metadata( $attachment_id );
		$sizes = is_array( $meta ) && is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array();
		$name  = null;

		if ( is_string( $size ) && isset( $sizes[ $size ] ) ) {
			$name = $size;
		} elseif ( is_array( $size ) ) {
			$intermediate = image_get_intermediate_size( $attachment_id, $size );
			if ( is_array( $intermediate ) ) {
				foreach ( $sizes as $candidate => $data ) {
					if ( is_array( $data ) && ( $data['file'] ?? null ) === ( $intermediate['file'] ?? '' ) ) {
						$name = (string) $candidate;
						break;
					}
				}
			}
		}

		if ( null === $name ) {
			return array( $this->url->build( $attachment_id, Url::FULL, $file->slug() ), (int) $file->width, (int) $file->height, false );
		}

		$data = $sizes[ $name ];

		return array( $this->size_url( $attachment_id, $data ), (int) $data['width'], (int) $data['height'], true );
	}

	/**
	 * Replaces the upload URLs that core computed for srcset with proxy URLs.
	 *
	 * @param array<int|string, array<string, mixed>>|false $sources       Candidates by width.
	 * @param int[]                                         $size_array    Requested size.
	 * @param string                                        $image_src     Image src.
	 * @param array<string, mixed>                          $image_meta    Metadata.
	 * @param int                                           $attachment_id Attachment ID.
	 * @return array<int|string, array<string, mixed>>|false
	 */
	public function image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$file          = $this->linked( $attachment_id );
		if ( ! is_array( $sources ) || null === $file || ! $file->is_sized_image() ) {
			return $sources;
		}

		$by_width = array( (int) $file->width => $this->url->build( $attachment_id, Url::FULL, $file->slug() ) );
		foreach ( is_array( $image_meta['sizes'] ?? null ) ? $image_meta['sizes'] : array() as $data ) {
			if ( is_array( $data ) && isset( $data['width'], $data['height'], $data['file'] ) && ! isset( $by_width[ (int) $data['width'] ] ) ) {
				$by_width[ (int) $data['width'] ] = $this->size_url( $attachment_id, $data );
			}
		}

		foreach ( $sources as $width => $source ) {
			if ( isset( $by_width[ (int) $width ] ) ) {
				$sources[ $width ]['url'] = $by_width[ (int) $width ];
			} else {
				unset( $sources[ $width ] );
			}
		}

		return $sources;
	}

	/**
	 * Media library data: no image editing, plugin information for the dialog.
	 *
	 * @param array<string, mixed> $response   Data.
	 * @param \WP_Post             $attachment Attachment.
	 * @return array<string, mixed>
	 */
	public function prepare_for_js( $response, $attachment ) {
		$file = $this->linked( (int) $attachment->ID );
		if ( null === $file ) {
			return $response;
		}

		if ( isset( $response['nonces'] ) && is_array( $response['nonces'] ) ) {
			unset( $response['nonces']['edit'] );
		}
		$response['filename'] = $file->slug();
		if ( null !== $file->size ) {
			$response['filesizeInBytes']       = $file->size;
			$response['filesizeHumanReadable'] = size_format( $file->size );
		}
		$response['wpncl'] = array(
			'fileId' => $file->file_id,
			'area'   => $file->area->value,
			'path'   => $file->path,
			'status' => $file->status,
		);

		return $response;
	}

	/**
	 * Linked attachments have no local file.
	 *
	 * @param string|false $file          Path.
	 * @param int          $attachment_id Attachment ID.
	 * @return string|false
	 */
	public function attached_file( $file, $attachment_id ) {
		return $this->repository->is_linked( (int) $attachment_id ) ? false : $file;
	}

	/**
	 * Marks a linked attachment that is being deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function before_delete( $post_id ): void {
		if ( $this->repository->is_linked( (int) $post_id ) ) {
			$this->deleting[ (int) $post_id ] = true;
		}
	}

	/**
	 * Clears the mark after deletion.
	 *
	 * @param int $post_id Post ID.
	 */
	public function after_delete( $post_id ): void {
		unset( $this->deleting[ (int) $post_id ] );
	}

	/**
	 * Prevents any file deletion while a linked attachment is deleted.
	 *
	 * @param string $file Path to delete.
	 */
	public function protect_files( $file ): string {
		return array() === $this->deleting ? (string) $file : '';
	}

	/**
	 * Registered sizes with the dimensions Nextcloud delivers.
	 *
	 * @param int    $width  Original width.
	 * @param int    $height Original height.
	 * @param string $slug   File name.
	 * @param string $mime   MIME type.
	 * @return array<string, array{file: string, width: int, height: int, mime-type: string}>
	 */
	private function sizes_for( int $width, int $height, string $slug, string $mime ): array {
		[ $max_width, $max_height ] = $this->sizes->largest_preview( $width, $height );

		$sizes = array();
		foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
			$box_width  = (int) ( $size['width'] ?? 0 );
			$box_height = (int) ( $size['height'] ?? 0 );
			if ( $box_width <= 0 && $box_height <= 0 ) {
				continue;
			}
			// Like core: no size for boxes the original already fits into.
			if ( ( $box_width <= 0 || $box_width >= $width ) && ( $box_height <= 0 || $box_height >= $height ) ) {
				continue;
			}

			[ $w, $h ] = $this->sizes->delivered(
				$width,
				$height,
				$box_width > 0 ? $box_width : $max_width,
				$box_height > 0 ? $box_height : $max_height
			);

			$sizes[ (string) $name ] = array(
				'file'      => FileName::sized( $slug, $w, $h ),
				'width'     => $w,
				'height'    => $h,
				'mime-type' => $mime,
			);
		}

		return $sizes;
	}

	/**
	 * Proxy URL of a size entry of the metadata.
	 *
	 * @param int          $attachment_id Attachment ID.
	 * @param array<mixed> $data          Size entry (width, height, file).
	 */
	private function size_url( int $attachment_id, array $data ): string {
		$width  = is_numeric( $data['width'] ?? null ) ? (int) $data['width'] : 0;
		$height = is_numeric( $data['height'] ?? null ) ? (int) $data['height'] : 0;
		$file   = is_string( $data['file'] ?? null ) ? $data['file'] : '';

		return $this->url->build( $attachment_id, Url::dimensions( $width, $height ), $file );
	}

	/**
	 * Linked file data, or null for regular attachments.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function linked( int $attachment_id ): ?LinkedFile {
		return $attachment_id > 0 ? $this->repository->get( $attachment_id ) : null;
	}
}
