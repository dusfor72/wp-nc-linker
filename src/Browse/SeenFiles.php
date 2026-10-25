<?php
/**
 * File IDs a user has seen in the browser.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Browse;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers which file IDs were listed for a WordPress user, so the thumbnail route only
 * serves files the user could actually browse (D-6). Stored in a short-lived transient.
 */
class SeenFiles {

	public const LIMIT = 5000;

	public const TTL = 3600;

	/**
	 * Adds file IDs for a user.
	 *
	 * @param int       $user_id  WordPress user ID.
	 * @param list<int> $file_ids File IDs.
	 */
	public function remember( int $user_id, array $file_ids ): void {
		if ( array() === $file_ids ) {
			return;
		}
		$ids = array_merge( array_diff( $this->load( $user_id ), $file_ids ), $file_ids );
		if ( count( $ids ) > self::LIMIT ) {
			$ids = array_slice( $ids, -self::LIMIT );
		}
		set_transient( self::key( $user_id ), array_values( $ids ), self::TTL );
	}

	/**
	 * Whether the user has seen the file ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $file_id File ID.
	 */
	public function contains( int $user_id, int $file_id ): bool {
		return in_array( $file_id, $this->load( $user_id ), true );
	}

	/**
	 * Stored IDs.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return list<int>
	 */
	private function load( int $user_id ): array {
		$stored = get_transient( self::key( $user_id ) );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values( array_filter( $stored, 'is_int' ) );
	}

	/**
	 * Transient key.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private static function key( int $user_id ): string {
		return 'wpncl_seen_' . $user_id;
	}
}
