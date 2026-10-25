<?php
/**
 * Settings → Nextcloud Linker.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Admin;

use WpNcLinker\Config\MimeTypes;
use WpNcLinker\Config\Settings;
use WpNcLinker\Config\SettingsRegistration;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page built on the Settings API.
 */
final class SettingsPage {

	public const PAGE  = 'wp-nc-linker';
	public const GROUP = SettingsRegistration::GROUP;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings        Effective settings.
	 * @param ConnectionTestAction $connection_test Connection test section.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ConnectionTestAction $connection_test,
	) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( Capabilities::class, 'settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPNCL_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the page below "Settings".
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Nextcloud Linker', 'wp-nc-linker' ),
			__( 'Nextcloud Linker', 'wp-nc-linker' ),
			Capabilities::settings(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the sections and fields (the option itself: SettingsRegistration).
	 */
	public function register_fields(): void {
		add_settings_section(
			'wpncl_connection',
			__( 'Connection', 'wp-nc-linker' ),
			array( $this, 'render_connection_intro' ),
			self::PAGE
		);
		add_settings_field( 'wpncl_url', __( 'Nextcloud URL', 'wp-nc-linker' ), array( $this, 'render_url' ), self::PAGE, 'wpncl_connection', array( 'label_for' => 'wpncl_url' ) );
		add_settings_field( 'wpncl_user', __( 'User name', 'wp-nc-linker' ), array( $this, 'render_user' ), self::PAGE, 'wpncl_connection', array( 'label_for' => 'wpncl_user' ) );
		add_settings_field( 'wpncl_app_password', __( 'App password', 'wp-nc-linker' ), array( $this, 'render_app_password' ), self::PAGE, 'wpncl_connection', array( 'label_for' => 'wpncl_app_password' ) );

		add_settings_section( 'wpncl_delivery', __( 'Delivery', 'wp-nc-linker' ), '__return_null', self::PAGE );
		add_settings_field( 'wpncl_cache_max_age', __( 'Browser cache lifetime', 'wp-nc-linker' ), array( $this, 'render_cache_max_age' ), self::PAGE, 'wpncl_delivery', array( 'label_for' => 'wpncl_cache_max_age' ) );
		add_settings_field( 'wpncl_proxy_prefix', __( 'URL prefix', 'wp-nc-linker' ), array( $this, 'render_proxy_prefix' ), self::PAGE, 'wpncl_delivery', array( 'label_for' => 'wpncl_proxy_prefix' ) );
		add_settings_field( 'wpncl_mime_types', __( 'Allowed file types', 'wp-nc-linker' ), array( $this, 'render_mime_types' ), self::PAGE, 'wpncl_delivery' );

		add_settings_section( 'wpncl_uninstall', __( 'Uninstall', 'wp-nc-linker' ), '__return_null', self::PAGE );
		add_settings_field( 'wpncl_delete_on_uninstall', __( 'Linked media', 'wp-nc-linker' ), array( $this, 'render_delete_on_uninstall' ), self::PAGE, 'wpncl_uninstall' );
	}

	/**
	 * Adds a "Settings" link to the plugin list (only for users who may open the page).
	 *
	 * @param array<string> $links Existing links.
	 * @return array<string>
	 */
	public function action_links( array $links ): array {
		if ( Capabilities::can_manage_settings() ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
					esc_html__( 'Settings', 'wp-nc-linker' )
				)
			);
		}

		return $links;
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! Capabilities::can_manage_settings() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wp-nc-linker' ), 403 );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[form]" value="1">
				<?php
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
			<?php $this->connection_test->render(); ?>
		</div>
		<?php
	}

	/**
	 * Introduction of the connection section.
	 */
	public function render_connection_intro(): void {
		echo '<p>' . esc_html__( 'Use a dedicated Nextcloud account and an app password. Share folders and albums with this account read-only and with download permission; files in shares with hidden download cannot be linked.', 'wp-nc-linker' ) . '</p>';
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: 1: WPNCL_URL, 2: WPNCL_USER, 3: WPNCL_APP_PASSWORD, 4: wp-config.php */
				__( 'Alternatively, define %1$s, %2$s and %3$s in %4$s; these values take precedence and cannot be changed here.', 'wp-nc-linker' ),
				'<code>WPNCL_URL</code>',
				'<code>WPNCL_USER</code>',
				'<code>WPNCL_APP_PASSWORD</code>',
				'<code>wp-config.php</code>'
			),
			array( 'code' => array() )
		) . '</p>';
	}

	/**
	 * URL field.
	 */
	public function render_url(): void {
		$locked = $this->settings->is_locked( 'url' );
		$value  = $locked ? (string) $this->settings->url() : $this->settings->stored()['url'];
		printf(
			'<input type="url" id="wpncl_url" name="%1$s[url]" value="%2$s" class="regular-text code" placeholder="https://cloud.example.org" autocomplete="off" %3$s>',
			esc_attr( Settings::OPTION ),
			esc_attr( $value ),
			disabled( $locked, true, false )
		);
		$this->render_constant_note( 'url', 'WPNCL_URL' );
		echo '<p class="description">' . esc_html__( 'Base address of the Nextcloud installation (https only), including a sub-path if Nextcloud is not installed at the root.', 'wp-nc-linker' ) . '</p>';
	}

	/**
	 * User field.
	 */
	public function render_user(): void {
		$locked = $this->settings->is_locked( 'user' );
		$value  = $locked ? (string) $this->settings->user() : $this->settings->stored()['user'];
		printf(
			'<input type="text" id="wpncl_user" name="%1$s[user]" value="%2$s" class="regular-text" autocomplete="off" spellcheck="false" %3$s>',
			esc_attr( Settings::OPTION ),
			esc_attr( $value ),
			disabled( $locked, true, false )
		);
		$this->render_constant_note( 'user', 'WPNCL_USER' );
	}

	/**
	 * App password field. The password is never printed.
	 */
	public function render_app_password(): void {
		$locked = $this->settings->is_locked( 'app_password' );
		printf(
			'<input type="password" id="wpncl_app_password" name="%1$s[new_app_password]" value="" class="regular-text" autocomplete="new-password" spellcheck="false" %2$s>',
			esc_attr( Settings::OPTION ),
			disabled( $locked, true, false )
		);

		if ( $locked ) {
			$this->render_constant_note( 'app_password', 'WPNCL_APP_PASSWORD' );
			return;
		}

		if ( $this->settings->is_stored_app_password_unreadable() ) {
			echo '<p class="description"><strong>' . esc_html__( 'The stored app password can no longer be decrypted (the WordPress salts were changed). Please enter it again.', 'wp-nc-linker' ) . '</strong></p>';
		} elseif ( $this->settings->has_stored_app_password() ) {
			echo '<p class="description">' . esc_html__( 'An app password is stored. Leave the field empty to keep it.', 'wp-nc-linker' ) . '</p>';
			printf(
				'<p><label><input type="checkbox" name="%1$s[clear_app_password]" value="1"> %2$s</label></p>',
				esc_attr( Settings::OPTION ),
				esc_html__( 'Remove the stored app password', 'wp-nc-linker' )
			);
		} else {
			echo '<p class="description">' . esc_html__( 'Create it in Nextcloud under Personal settings → Security → Devices & sessions. It is stored encrypted.', 'wp-nc-linker' ) . '</p>';
		}
	}

	/**
	 * Cache lifetime field.
	 */
	public function render_cache_max_age(): void {
		printf(
			'<input type="number" id="wpncl_cache_max_age" name="%1$s[cache_max_age]" value="%2$d" min="0" max="31536000" step="1" class="small-text" style="width: 9em;"> %3$s',
			esc_attr( Settings::OPTION ),
			(int) $this->settings->stored()['cache_max_age'],
			esc_html__( 'seconds', 'wp-nc-linker' )
		);
		echo '<p class="description">' . esc_html__( 'How long browsers may cache delivered images and documents (default: 86400 = 1 day).', 'wp-nc-linker' ) . '</p>';
	}

	/**
	 * Proxy prefix field.
	 */
	public function render_proxy_prefix(): void {
		printf(
			'<code>%1$s</code><input type="text" id="wpncl_proxy_prefix" name="%2$s[proxy_prefix]" value="%3$s" class="regular-text code" spellcheck="false"><code>/…</code>',
			esc_html( home_url( '/' ) ),
			esc_attr( Settings::OPTION ),
			esc_attr( $this->settings->stored()['proxy_prefix'] )
		);
		echo '<p class="description">' . esc_html__( 'First path segment of the public URLs of linked files.', 'wp-nc-linker' ) . '</p>';
	}

	/**
	 * MIME type checkboxes.
	 */
	public function render_mime_types(): void {
		$enabled = $this->settings->stored()['mime_types'];
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Allowed file types', 'wp-nc-linker' ) . '</legend>';
		foreach ( MimeTypes::labels() as $type => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[mime_types][]" value="%2$s" %3$s> %4$s <code>%5$s</code></label><br>',
				esc_attr( Settings::OPTION ),
				esc_attr( $type ),
				checked( in_array( $type, $enabled, true ), true, false ),
				esc_html( $label ),
				esc_html( $type )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Uninstall behaviour checkbox.
	 */
	public function render_delete_on_uninstall(): void {
		printf(
			'<label><input type="checkbox" name="%1$s[delete_on_uninstall]" value="1" %2$s> %3$s</label>',
			esc_attr( Settings::OPTION ),
			checked( $this->settings->stored()['delete_on_uninstall'], true, false ),
			esc_html__( 'Delete all linked media when the plugin is uninstalled', 'wp-nc-linker' )
		);
		echo '<p class="description">' . esc_html__( 'If unchecked, the media items remain and are shown as placeholders.', 'wp-nc-linker' ) . '</p>';
	}

	/**
	 * Note for fields set by a constant.
	 *
	 * @param 'url'|'user'|'app_password' $field    Field name.
	 * @param string                      $constant Constant name.
	 */
	private function render_constant_note( string $field, string $constant ): void {
		if ( ! $this->settings->is_locked( $field ) ) {
			return;
		}

		if ( $this->settings->has_invalid_constant( $field ) ) {
			/* translators: %s: constant name */
			$message = __( 'Set in wp-config.php (%s), but the value is invalid.', 'wp-nc-linker' );
			echo '<p class="description"><strong>' . wp_kses( sprintf( $message, '<code>' . esc_html( $constant ) . '</code>' ), array( 'code' => array() ) ) . '</strong></p>';
			return;
		}

		/* translators: %s: constant name */
		$message = __( 'Set in wp-config.php (%s).', 'wp-nc-linker' );
		echo '<p class="description">' . wp_kses( sprintf( $message, '<code>' . esc_html( $constant ) . '</code>' ), array( 'code' => array() ) ) . '</p>';
	}
}
