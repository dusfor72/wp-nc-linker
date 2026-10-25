<?php
/**
 * Supported MIME types.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Config;

defined( 'ABSPATH' ) || exit;

/**
 * MIME types that can be linked, and types that are never delivered.
 */
final class MimeTypes {

	/**
	 * Types that could carry active content on the site's origin; never allowed, not even by filter.
	 */
	private const DENIED = array(
		'image/svg+xml',
		'text/html',
		'application/xhtml+xml',
		'application/xml',
		'text/xml',
		'text/javascript',
		'application/javascript',
		'application/x-shockwave-flash',
	);

	/**
	 * Types offered on the settings page (all enabled by default).
	 */
	public const SUPPORTED = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/avif',
		'application/pdf',
		'application/vnd.oasis.opendocument.text',
		'application/vnd.oasis.opendocument.spreadsheet',
		'application/vnd.oasis.opendocument.presentation',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/msword',
		'application/vnd.ms-excel',
		'application/vnd.ms-powerpoint',
	);

	/**
	 * Translated labels of the supported types.
	 *
	 * @return array<string, string> MIME type => label.
	 */
	public static function labels(): array {
		return array(
			'image/jpeg'                              => __( 'JPEG image', 'wp-nc-linker' ),
			'image/png'                               => __( 'PNG image', 'wp-nc-linker' ),
			'image/gif'                               => __( 'GIF image', 'wp-nc-linker' ),
			'image/webp'                              => __( 'WebP image', 'wp-nc-linker' ),
			'image/avif'                              => __( 'AVIF image', 'wp-nc-linker' ),
			'application/pdf'                         => __( 'PDF document', 'wp-nc-linker' ),
			'application/vnd.oasis.opendocument.text' => __( 'OpenDocument text (.odt)', 'wp-nc-linker' ),
			'application/vnd.oasis.opendocument.spreadsheet' => __( 'OpenDocument spreadsheet (.ods)', 'wp-nc-linker' ),
			'application/vnd.oasis.opendocument.presentation' => __( 'OpenDocument presentation (.odp)', 'wp-nc-linker' ),
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => __( 'Word document (.docx)', 'wp-nc-linker' ),
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => __( 'Excel spreadsheet (.xlsx)', 'wp-nc-linker' ),
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => __( 'PowerPoint presentation (.pptx)', 'wp-nc-linker' ),
			'application/msword'                      => __( 'Word 97–2003 document (.doc)', 'wp-nc-linker' ),
			'application/vnd.ms-excel'                => __( 'Excel 97–2003 spreadsheet (.xls)', 'wp-nc-linker' ),
			'application/vnd.ms-powerpoint'           => __( 'PowerPoint 97–2003 presentation (.ppt)', 'wp-nc-linker' ),
		);
	}

	/**
	 * Whether a value is a syntactically valid MIME type that is not denied.
	 *
	 * @param string $type MIME type.
	 */
	public static function is_allowed_type( string $type ): bool {
		return 1 === preg_match( '#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#', $type )
			&& ! in_array( $type, self::DENIED, true );
	}
}
