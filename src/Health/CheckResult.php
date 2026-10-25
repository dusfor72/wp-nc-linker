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
	public const RELOCATED = 'relocated';
	public const MISSING   = 'missing';

	/**
	 * Outcome per attachment ID.
	 *
	 * @var array<int, string>
	 */
	public array $outcomes = array();

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
