<?php
/**
 * Failure while linking a file.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Media;

defined( 'ABSPATH' ) || exit;

/**
 * A file could not be linked; the reason is one of the class constants.
 */
final class ImportException extends \RuntimeException {

	public const INVALID     = 'invalid';
	public const NOT_FOUND   = 'not_found';
	public const TYPE        = 'type_not_allowed';
	public const RESTRICTED  = 'download_restricted';
	public const UNAVAILABLE = 'unavailable';
	public const UPSTREAM    = 'upstream';

	/**
	 * Why the file cannot be linked (one of the class constants).
	 *
	 * @var string
	 */
	public readonly string $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason One of the class constants.
	 */
	public function __construct( string $reason ) {
		$this->reason = $reason;
		parent::__construct( 'File cannot be linked: ' . $reason );
	}

	/**
	 * Translated message.
	 */
	public function user_message(): string {
		return match ( $this->reason ) {
			self::INVALID     => __( 'Invalid file reference.', 'wp-nc-linker' ),
			self::NOT_FOUND   => __( 'The file does not exist (anymore).', 'wp-nc-linker' ),
			self::TYPE        => __( 'This file type is not allowed.', 'wp-nc-linker' ),
			self::RESTRICTED  => __( 'The file is shared without download permission.', 'wp-nc-linker' ),
			self::UNAVAILABLE => __( 'Nextcloud is not reachable. Please ask an administrator to run the connection test.', 'wp-nc-linker' ),
			default           => __( 'Nextcloud sent an unexpected answer.', 'wp-nc-linker' ),
		};
	}
}
