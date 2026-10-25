<?php
/**
 * Connection test on the settings page.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Admin;

use WpNcLinker\Diagnostics\CheckResult;
use WpNcLinker\Diagnostics\ConnectionTest;
use WpNcLinker\Diagnostics\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the connection test via admin-post.php and shows the result on the settings page.
 */
final class ConnectionTestAction {

	public const ACTION = 'wpncl_connection_test';

	private const RESULT_TTL = 300;

	/**
	 * Creates the connection test (only when it is run).
	 *
	 * @var \Closure(): ConnectionTest
	 */
	private \Closure $test_factory;

	/**
	 * Constructor.
	 *
	 * @param \Closure $test_factory Creates the connection test.
	 * @phpstan-param \Closure(): ConnectionTest $test_factory
	 */
	public function __construct( \Closure $test_factory ) {
		$this->test_factory = $test_factory;
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Runs the test and redirects back to the settings page.
	 */
	public function handle(): void {
		if ( ! Capabilities::can_manage_settings() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to run the connection test.', 'wp-nc-linker' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$results = ( $this->test_factory )()->run();
		set_transient(
			self::transient_key(),
			array_map( static fn ( CheckResult $result ): array => $result->to_array(), $results ),
			self::RESULT_TTL
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=' . SettingsPage::PAGE . '#wpncl-connection-test' ) );
		exit;
	}

	/**
	 * Renders the test section (button and the last result of the current user).
	 */
	public function render(): void {
		?>
		<h2 id="wpncl-connection-test"><?php esc_html_e( 'Connection test', 'wp-nc-linker' ); ?></h2>
		<p><?php esc_html_e( 'Checks the saved settings: server and certificate, login, folders, albums and the preview of a sample image. Save changes first.', 'wp-nc-linker' ); ?></p>
		<?php
		$this->render_results();
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php
			wp_nonce_field( self::ACTION );
			submit_button( __( 'Test connection', 'wp-nc-linker' ), 'secondary', 'wpncl-test', false );
			?>
		</form>
		<?php
	}

	/**
	 * Prints and removes the stored result of the current user.
	 */
	private function render_results(): void {
		$stored = get_transient( self::transient_key() );
		if ( ! is_array( $stored ) ) {
			return;
		}
		delete_transient( self::transient_key() );

		$results = array_values( array_filter( array_map( array( CheckResult::class, 'from_array' ), $stored ) ) );
		if ( array() === $results ) {
			return;
		}

		$failed = ConnectionTest::has_errors( $results );
		printf(
			'<div class="notice %1$s inline"><p>%2$s</p></div>',
			esc_attr( $failed ? 'notice-error' : 'notice-success' ),
			esc_html( $failed ? __( 'The connection test found problems.', 'wp-nc-linker' ) : __( 'The connection works.', 'wp-nc-linker' ) )
		);
		?>
		<table class="widefat striped wpncl-test-results" style="max-width: 60em; margin-bottom: 1em;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Check', 'wp-nc-linker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'wp-nc-linker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'wp-nc-linker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $result ) : ?>
					<tr data-check="<?php echo esc_attr( $result->id ); ?>" data-status="<?php echo esc_attr( $result->status->value ); ?>">
						<td><?php echo esc_html( $result->label ); ?></td>
						<td style="white-space: nowrap;"><?php echo wp_kses_post( self::status_badge( $result->status ) ); ?></td>
						<td><?php echo esc_html( $result->message ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Status with an icon.
	 *
	 * @param Status $status Status.
	 */
	private static function status_badge( Status $status ): string {
		$icon = match ( $status ) {
			Status::Ok      => 'dashicons-yes-alt',
			Status::Warning => 'dashicons-warning',
			Status::Error   => 'dashicons-dismiss',
			Status::Skipped => 'dashicons-minus',
		};

		return '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span> ' . esc_html( $status->label() );
	}

	/**
	 * Transient key of the current user.
	 */
	private static function transient_key(): string {
		return 'wpncl_test_result_' . get_current_user_id();
	}
}
