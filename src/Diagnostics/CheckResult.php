<?php
/**
 * Result of a diagnostic check.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * One line of the connection test. Messages never contain credentials.
 */
final class CheckResult {

	/**
	 * Constructor.
	 *
	 * @param string $id      Machine-readable check ID.
	 * @param string $label   Translated name of the check.
	 * @param Status $status  Outcome.
	 * @param string $message Translated explanation.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly Status $status,
		public readonly string $message,
	) {
	}

	/**
	 * Array form (for storage and WP-CLI output).
	 *
	 * @return array{id: string, label: string, status: string, message: string}
	 */
	public function to_array(): array {
		return array(
			'id'      => $this->id,
			'label'   => $this->label,
			'status'  => $this->status->value,
			'message' => $this->message,
		);
	}

	/**
	 * Restores a result from to_array(); returns null for invalid data.
	 *
	 * @param mixed $data Stored data.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$status = Status::tryFrom( is_string( $data['status'] ?? null ) ? $data['status'] : '' );
		foreach ( array( 'id', 'label', 'message' ) as $key ) {
			if ( ! is_string( $data[ $key ] ?? null ) ) {
				return null;
			}
		}
		if ( null === $status ) {
			return null;
		}

		return new self( $data['id'], $data['label'], $status, $data['message'] );
	}
}
