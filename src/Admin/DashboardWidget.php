<?php
/**
 * Dashboard notice about missing files.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Admin;

use WpNcLinker\Health\Scheduler;
use WpNcLinker\Media\LinkedFile;
use WpNcLinker\Media\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Lists linked attachments whose file is missing in Nextcloud (F7). Shown only to users who may
 * link files, and only while files are missing.
 */
final class DashboardWidget {

	public const ID = 'wpncl_missing_files';

	private const MAX_LISTED = 20;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Attachment storage.
	 */
	public function __construct( private readonly Repository $repository ) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add' ) );
	}

	/**
	 * Adds the widget if files are missing.
	 */
	public function add(): void {
		if ( ! Capabilities::can_select() ) {
			return;
		}
		if ( array() === $this->repository->ids_with_status( LinkedFile::STATUS_MISSING, 1 ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::ID,
			__( 'Nextcloud: missing files', 'wp-nc-linker' ),
			array( $this, 'render' ),
			null,
			null,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the list.
	 */
	public function render(): void {
		$ids   = $this->repository->ids_with_status( LinkedFile::STATUS_MISSING );
		$count = count( $ids );

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of media items */
				_n(
					'%d linked media item cannot be loaded from Nextcloud (deleted, share removed or download no longer allowed). Visitors see a placeholder.',
					'%d linked media items cannot be loaded from Nextcloud (deleted, share removed or download no longer allowed). Visitors see a placeholder.',
					$count,
					'wp-nc-linker'
				),
				$count
			)
		) . '</p>';

		echo '<ul class="wpncl-missing-files">';
		foreach ( array_slice( $ids, 0, self::MAX_LISTED ) as $id ) {
			$file  = $this->repository->get( $id );
			$title = get_the_title( $id );
			$label = '' !== $title ? $title : ( null !== $file ? $file->name : '#' . $id );
			$link  = get_edit_post_link( $id );
			echo '<li>';
			if ( null !== $link && current_user_can( 'edit_post', $id ) ) {
				printf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( $label ) );
			} else {
				echo esc_html( $label );
			}
			if ( null !== $file ) {
				echo ' <span class="description">(' . esc_html( $file->path ) . ')</span>';
			}
			echo '</li>';
		}
		echo '</ul>';

		if ( $count > self::MAX_LISTED ) {
			/* translators: %d: number of further media items */
			echo '<p>' . esc_html( sprintf( __( 'And %d more.', 'wp-nc-linker' ), $count - self::MAX_LISTED ) ) . '</p>';
		}

		$run = Scheduler::last_run();
		if ( null !== $run ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: date and time */
					__( 'Last automatic check: %s.', 'wp-nc-linker' ),
					wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $run['time'] )
				)
			) . '</p>';
		}
	}
}
