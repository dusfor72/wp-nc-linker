<?php
/**
 * Result of checking linked files.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Counters and per-attachment outcome of a check run.
 */
final class CheckResult {

	public const OK        = 'ok';
	public const UPDATED   = 'updated';
	public const RELOCATED = 'relocated';
	public const MISSING   = 'missing';

	// Why a file is missing (stored with the attachment, shown by `check` and `list`).
	public const REASON_NOT_FOUND    = 'not_found';
	public const REASON_FORBIDDEN    = 'forbidden';
	public const REASON_NO_DOWNLOAD  = 'download_not_allowed';
	public const REASON_OTHER_FILE   = 'other_file_at_path';
	public const REASON_INVALID_PATH = 'invalid_path';

	/**
	 * Outcome per attachment ID.
	 *
	 * @var array<int, string>
	 */
	public array $outcomes = array();

	/**
	 * Reason per missing attachment ID (REASON_*).
	 *
	 * @var array<int, string>
	 */
	public array $reasons = array();

	/**
	 * Why the run was aborted (Nextcloud not reachable), or null.
	 *
	 * @var string|null
	 */
	public ?string $aborted = null;

	/**
	 * Number of attachments with an outcome.
	 *
	 * @param string $outcome One of the class constants.
	 */
	public function count( string $outcome ): int {
		return count( array_keys( $this->outcomes, $outcome, true ) );
	}
}
