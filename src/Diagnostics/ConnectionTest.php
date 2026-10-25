<?php
/**
 * Connection test and diagnostics.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Diagnostics;

use WpNcLinker\Config\Settings;
use WpNcLinker\Nextcloud\Client;
use WpNcLinker\Nextcloud\DavEntry;
use WpNcLinker\Nextcloud\DavPath;
use WpNcLinker\Nextcloud\InvalidResponseException;
use WpNcLinker\Nextcloud\MultiStatus;
use WpNcLinker\Nextcloud\NotConfiguredException;
use WpNcLinker\Nextcloud\Response;
use WpNcLinker\Nextcloud\TransportException;

defined( 'ABSPATH' ) || exit;

/**
 * Checks configuration, reachability (incl. TLS), login, file tree, album collections and the
 * preview of a sample image. Stops after a failed login to avoid brute-force throttling.
 */
final class ConnectionTest {

	/**
	 * Nextcloud major version the plugin was verified against.
	 */
	public const TESTED_MAJOR_VERSION = 34;

	private const SAMPLE_SEARCH_LIMIT = 50;

	private const MAX_LISTED_NAMES = 10;

	private const PREVIEW_SIZE = 256;

	private const MAX_PREVIEW_BYTES = 5242880;

	/**
	 * Results collected during run().
	 *
	 * @var list<CheckResult>
	 */
	private array $results = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Effective settings.
	 * @param Client   $client   Nextcloud client.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Client $client,
	) {
	}

	/**
	 * Runs all checks.
	 *
	 * @return list<CheckResult>
	 */
	public function run(): array {
		$this->results = array();

		try {
			if ( $this->check_configuration() && $this->check_server() && $this->check_login() ) {
				$restricted = $this->check_files();
				$albums     = $this->check_albums( DavPath::ALBUMS );
				$shared     = $this->check_albums( DavPath::SHARED_ALBUMS );
				$this->check_preview( $restricted, array_merge( $albums, $shared ) );
			}
		} catch ( NotConfiguredException ) {
			$this->add( 'configuration', __( 'Configuration', 'wp-nc-linker' ), Status::Error, __( 'The connection is not configured completely.', 'wp-nc-linker' ) );
		}

		return $this->results;
	}

	/**
	 * Whether any result is an error.
	 *
	 * @param list<CheckResult> $results Results of run().
	 */
	public static function has_errors( array $results ): bool {
		foreach ( $results as $result ) {
			if ( Status::Error === $result->status ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * URL, user and app password present and valid.
	 */
	private function check_configuration(): bool {
		$label   = __( 'Configuration', 'wp-nc-linker' );
		$missing = array();
		$fields  = array(
			'url'          => __( 'Nextcloud URL', 'wp-nc-linker' ),
			'user'         => __( 'user name', 'wp-nc-linker' ),
			'app_password' => __( 'app password', 'wp-nc-linker' ),
		);
		foreach ( $fields as $field => $name ) {
			if ( $this->settings->has_invalid_constant( $field ) ) {
				/* translators: %s: name of the setting */
				$missing[] = sprintf( __( '%s (invalid value in wp-config.php)', 'wp-nc-linker' ), $name );
			}
		}
		if ( null === $this->settings->url() && ! $this->settings->has_invalid_constant( 'url' ) ) {
			$missing[] = $fields['url'];
		}
		if ( null === $this->settings->user() && ! $this->settings->has_invalid_constant( 'user' ) ) {
			$missing[] = $fields['user'];
		}
		if ( null === $this->settings->app_password() && ! $this->settings->has_invalid_constant( 'app_password' ) ) {
			$missing[] = $this->settings->is_stored_app_password_unreadable()
				? __( 'app password (stored value can no longer be decrypted; enter it again)', 'wp-nc-linker' )
				: $fields['app_password'];
		}

		if ( array() !== $missing ) {
			/* translators: %s: comma-separated list of missing settings */
			$this->add( 'configuration', $label, Status::Error, sprintf( __( 'Missing or invalid: %s.', 'wp-nc-linker' ), implode( ', ', $missing ) ) );
			return false;
		}

		$this->add( 'configuration', $label, Status::Ok, __( 'URL, user name and app password are set.', 'wp-nc-linker' ) );
		return true;
	}

	/**
	 * Server reachable over verified TLS and in normal operation.
	 */
	private function check_server(): bool {
		$label = __( 'Server', 'wp-nc-linker' );
		try {
			$response = $this->client->status();
		} catch ( TransportException $e ) {
			$this->add( 'server', $label, Status::Error, self::transport_message( $e ) );
			return false;
		}

		if ( $this->is_redirect( $response ) ) {
			$this->add( 'server', $label, Status::Error, $this->redirect_message( $response ) );
			return false;
		}

		$status = json_decode( $response->body, true );
		if ( 200 !== $response->status || ! is_array( $status ) || true !== ( $status['installed'] ?? null ) ) {
			/* translators: %d: HTTP status code */
			$this->add( 'server', $label, Status::Error, sprintf( __( 'No Nextcloud installation found at this URL (HTTP %d). Check the address, including a sub-path.', 'wp-nc-linker' ), $response->status ) );
			return false;
		}
		if ( true === ( $status['maintenance'] ?? false ) ) {
			$this->add( 'server', $label, Status::Error, __( 'Nextcloud is in maintenance mode.', 'wp-nc-linker' ) );
			return false;
		}

		$version = is_string( $status['versionstring'] ?? null ) ? $status['versionstring'] : '';
		$major   = (int) strtok( $version, '.' );
		if ( $major > 0 && $major < self::TESTED_MAJOR_VERSION ) {
			/* translators: 1: installed Nextcloud version, 2: tested major version */
			$this->add( 'server', $label, Status::Warning, sprintf( __( 'Nextcloud %1$s is reachable (TLS verified), but older than the tested version %2$d.', 'wp-nc-linker' ), $version, self::TESTED_MAJOR_VERSION ) );
			return true;
		}

		/* translators: %s: Nextcloud version */
		$this->add( 'server', $label, Status::Ok, sprintf( __( 'Nextcloud %s is reachable (TLS verified).', 'wp-nc-linker' ), '' !== $version ? $version : '?' ) );
		return true;
	}

	/**
	 * Login with user name and app password.
	 */
	private function check_login(): bool {
		$label    = __( 'Login', 'wp-nc-linker' );
		$user     = $this->client->user();
		$response = $this->propfind( 'login', $label, DavPath::files( $user ), 0 );
		if ( null === $response ) {
			return false;
		}

		$message = match ( $response->status ) {
			207     => null,
			401     => __( 'Login failed (HTTP 401). Check the user name and the app password. Repeated failed logins slow down further attempts (brute-force protection).', 'wp-nc-linker' ),
			404     => __( 'The account was not found (HTTP 404). Enter the account ID, which can differ from the login name or e-mail address.', 'wp-nc-linker' ),
			default => self::status_message( $response ),
		};
		if ( null !== $message ) {
			$this->add( 'login', $label, Status::Error, $message );
			return false;
		}

		$this->add( 'login', $label, Status::Ok, __( 'Logged in with the app password.', 'wp-nc-linker' ) );
		return true;
	}

	/**
	 * File tree readable; reports shares without download permission.
	 *
	 * @return list<string> Names of shared folders without download permission.
	 */
	private function check_files(): array {
		$label    = __( 'Folders', 'wp-nc-linker' );
		$user     = $this->client->user();
		$response = $this->propfind( 'files', $label, DavPath::files( $user ), 1 );
		$entries  = null === $response ? null : $this->entries( 'files', $label, $response );
		if ( null === $entries ) {
			return array();
		}

		$children   = array_slice( $entries, 1 );
		$restricted = array();
		foreach ( $children as $entry ) {
			if ( ! $entry->download_allowed ) {
				$restricted[] = $entry->name();
			}
		}

		/* translators: %d: number of entries */
		$message = sprintf( _n( '%d entry in the top level of the file tree.', '%d entries in the top level of the file tree.', count( $children ), 'wp-nc-linker' ), count( $children ) );
		$this->add( 'files', $label, Status::Ok, $message );

		return $restricted;
	}

	/**
	 * Album collection readable.
	 *
	 * @param string $collection DavPath::ALBUMS or DavPath::SHARED_ALBUMS.
	 * @return list<DavEntry> Albums found.
	 */
	private function check_albums( string $collection ): array {
		$own   = DavPath::ALBUMS === $collection;
		$id    = $own ? 'albums' : 'shared_albums';
		$label = $own ? __( 'Own albums', 'wp-nc-linker' ) : __( 'Shared albums', 'wp-nc-linker' );

		try {
			$response = $this->client->propfind( DavPath::photos( $this->client->user(), $collection ), 1 );
		} catch ( TransportException $e ) {
			$this->add( $id, $label, Status::Error, self::transport_message( $e ) );
			return array();
		}

		if ( 404 === $response->status ) {
			$this->add( $id, $label, Status::Warning, __( 'Not available (HTTP 404). Is the Photos app enabled in Nextcloud? Albums cannot be used without it.', 'wp-nc-linker' ) );
			return array();
		}

		$entries = $this->entries( $id, $label, $response );
		if ( null === $entries ) {
			return array();
		}
		$albums = array_values( array_filter( array_slice( $entries, 1 ), static fn ( DavEntry $entry ): bool => $entry->is_collection ) );

		$message = $own
			/* translators: %d: number of albums */
			? sprintf( _n( '%d own album.', '%d own albums.', count( $albums ), 'wp-nc-linker' ), count( $albums ) )
			/* translators: %d: number of albums */
			: sprintf( _n( '%d album shared with the account (directly or via a group).', '%d albums shared with the account (directly or via a group).', count( $albums ), 'wp-nc-linker' ), count( $albums ) );
		$this->add( $id, $label, Status::Ok, $message );

		return $albums;
	}

	/**
	 * Preview of a sample image; reports download-restricted shares.
	 *
	 * @param list<string>   $restricted_folders Top-level folders without download permission.
	 * @param list<DavEntry> $albums             Albums found.
	 */
	private function check_preview( array $restricted_folders, array $albums ): void {
		$label      = __( 'Preview', 'wp-nc-linker' );
		$sample     = null;
		$restricted = $restricted_folders;

		$images = $this->search_images();
		foreach ( $images as $image ) {
			if ( ! $image->download_allowed ) {
				$restricted[] = self::top_folder( $image->href, $this->client->user() );
				continue;
			}
			if ( null === $sample && null !== $image->file_id && false !== $image->has_preview ) {
				$sample = $image;
			}
		}
		if ( null === $sample ) {
			$sample = $this->album_sample( $albums );
		}

		$restricted = array_values( array_unique( array_filter( $restricted ) ) );
		if ( array() !== $restricted ) {
			$this->add(
				'download_restriction',
				__( 'Download permission', 'wp-nc-linker' ),
				Status::Warning,
				sprintf(
					/* translators: %s: comma-separated list of folder names */
					__( 'Shared without download permission ("hide download"): %s. Files in these shares cannot be used. Ask the owner to share them with download permission (read-only is enough).', 'wp-nc-linker' ),
					implode( ', ', array_slice( $restricted, 0, self::MAX_LISTED_NAMES ) ) . ( count( $restricted ) > self::MAX_LISTED_NAMES ? ', …' : '' )
				)
			);
		}

		if ( null === $sample || null === $sample->file_id ) {
			$this->add( 'preview', $label, Status::Skipped, __( 'No image with a preview found in the shared folders or albums.', 'wp-nc-linker' ) );
			return;
		}

		try {
			$response = $this->client->preview( $sample->file_id, self::PREVIEW_SIZE, self::PREVIEW_SIZE, self::MAX_PREVIEW_BYTES );
		} catch ( TransportException $e ) {
			$this->add( 'preview', $label, Status::Error, self::transport_message( $e ) );
			return;
		}

		$type = strtolower( (string) $response->header( 'content-type' ) );
		if ( 200 === $response->status && str_starts_with( $type, 'image/' ) && '' !== $response->body ) {
			/* translators: 1: file name, 2: file ID */
			$this->add( 'preview', $label, Status::Ok, sprintf( __( 'Preview of "%1$s" (file ID %2$d) delivered.', 'wp-nc-linker' ), $sample->label(), $sample->file_id ) );
			return;
		}

		if ( 404 === $response->status ) {
			/* translators: 1: file name, 2: file ID */
			$this->add( 'preview', $label, Status::Error, sprintf( __( 'Nextcloud delivered no preview for "%1$s" (file ID %2$d, HTTP 404). Check the preview settings of Nextcloud and the download permission of the share.', 'wp-nc-linker' ), $sample->label(), $sample->file_id ) );
			return;
		}

		$this->add( 'preview', $label, Status::Error, self::status_message( $response ) );
	}

	/**
	 * Images found by SEARCH (empty if SEARCH fails; that is not an error of its own).
	 *
	 * @return list<DavEntry>
	 */
	private function search_images(): array {
		try {
			$response = $this->client->search( 'image/', self::SAMPLE_SEARCH_LIMIT );
			if ( 207 !== $response->status ) {
				return array();
			}
			return array_values( array_filter( MultiStatus::parse( $response->body ), static fn ( DavEntry $entry ): bool => ! $entry->is_collection ) );
		} catch ( TransportException | InvalidResponseException ) {
			return array();
		}
	}

	/**
	 * First image with a preview from the given albums.
	 *
	 * @param list<DavEntry> $albums Albums.
	 */
	private function album_sample( array $albums ): ?DavEntry {
		$user = $this->client->user();
		foreach ( $albums as $album ) {
			$collection = str_contains( $album->href, '/' . DavPath::SHARED_ALBUMS . '/' ) ? DavPath::SHARED_ALBUMS : DavPath::ALBUMS;
			try {
				$response = $this->client->propfind( DavPath::photos( $user, $collection, $album->name() . '/' ), 1 );
				if ( 207 !== $response->status ) {
					continue;
				}
				foreach ( MultiStatus::parse( $response->body ) as $entry ) {
					if ( ! $entry->is_collection && $entry->is_image() && null !== $entry->file_id && false !== $entry->has_preview ) {
						return $entry;
					}
				}
			} catch ( TransportException | InvalidResponseException | \InvalidArgumentException ) {
				continue;
			}
		}

		return null;
	}

	/**
	 * PROPFIND that records transport errors as a failed check.
	 *
	 * @param string $id    Check ID.
	 * @param string $label Check label.
	 * @param string $path  DAV path.
	 * @param int    $depth Depth.
	 */
	private function propfind( string $id, string $label, string $path, int $depth ): ?Response {
		try {
			return $this->client->propfind( $path, $depth );
		} catch ( TransportException $e ) {
			$this->add( $id, $label, Status::Error, self::transport_message( $e ) );
			return null;
		}
	}

	/**
	 * Parses a 207 response or records an error.
	 *
	 * @param string   $id       Check ID.
	 * @param string   $label    Check label.
	 * @param Response $response Response.
	 * @return list<DavEntry>|null
	 */
	private function entries( string $id, string $label, Response $response ): ?array {
		if ( 207 !== $response->status ) {
			$this->add( $id, $label, Status::Error, self::status_message( $response ) );
			return null;
		}
		try {
			return MultiStatus::parse( $response->body );
		} catch ( InvalidResponseException ) {
			$this->add( $id, $label, Status::Error, __( 'Nextcloud sent an invalid WebDAV response.', 'wp-nc-linker' ) );
			return null;
		}
	}

	/**
	 * Name of the top-level folder of a file href below the user's file tree.
	 *
	 * @param string $href File href.
	 * @param string $user User ID.
	 */
	private static function top_folder( string $href, string $user ): string {
		$relative = DavPath::relative( $href, DavPath::files( $user ) );
		if ( null === $relative || ! str_contains( $relative, '/' ) ) {
			return '';
		}

		return explode( '/', $relative )[0];
	}

	/**
	 * Whether the response is a redirect.
	 *
	 * @param Response $response Response.
	 */
	private function is_redirect( Response $response ): bool {
		return $response->status >= 300 && $response->status < 400;
	}

	/**
	 * Message for a redirect (the plugin does not follow redirects).
	 *
	 * @param Response $response Response.
	 */
	private function redirect_message( Response $response ): string {
		$location = (string) $response->header( 'location' );
		$host     = (string) wp_parse_url( $location, PHP_URL_HOST );

		return '' !== $host
			/* translators: 1: HTTP status code, 2: host name */
			? sprintf( __( 'Nextcloud answered with a redirect (HTTP %1$d to %2$s). Enter the final address as Nextcloud URL; redirects are not followed.', 'wp-nc-linker' ), $response->status, $host )
			/* translators: %d: HTTP status code */
			: sprintf( __( 'Nextcloud answered with a redirect (HTTP %d). Enter the final address as Nextcloud URL; redirects are not followed.', 'wp-nc-linker' ), $response->status );
	}

	/**
	 * Message for an unexpected HTTP status.
	 *
	 * @param Response $response Response.
	 */
	private static function status_message( Response $response ): string {
		/* translators: %d: HTTP status code */
		return sprintf( __( 'Unexpected answer from Nextcloud (HTTP %d).', 'wp-nc-linker' ), $response->status );
	}

	/**
	 * Message for a transport error.
	 *
	 * @param TransportException $e Error.
	 */
	private static function transport_message( TransportException $e ): string {
		return match ( $e->kind ) {
			/* translators: %s: technical error message */
			TransportException::TLS     => sprintf( __( 'The TLS certificate of Nextcloud could not be verified: %s', 'wp-nc-linker' ), $e->getMessage() ),
			/* translators: %s: technical error message */
			TransportException::DNS     => sprintf( __( 'The host name could not be resolved: %s', 'wp-nc-linker' ), $e->getMessage() ),
			/* translators: %s: technical error message */
			TransportException::CONNECT => sprintf( __( 'No connection to the server: %s', 'wp-nc-linker' ), $e->getMessage() ),
			/* translators: %s: technical error message */
			TransportException::TIMEOUT => sprintf( __( 'The server did not answer in time: %s', 'wp-nc-linker' ), $e->getMessage() ),
			TransportException::BLOCKED => __( 'WordPress blocks external requests (WP_HTTP_BLOCK_EXTERNAL). Add the Nextcloud host to WP_ACCESSIBLE_HOSTS in wp-config.php.', 'wp-nc-linker' ),
			/* translators: %s: technical error message */
			default                     => sprintf( __( 'Request failed: %s', 'wp-nc-linker' ), $e->getMessage() ),
		};
	}

	/**
	 * Records a result.
	 *
	 * @param string $id      Check ID.
	 * @param string $label   Label.
	 * @param Status $status  Status.
	 * @param string $message Message.
	 */
	private function add( string $id, string $label, Status $status, string $message ): void {
		$this->results[] = new CheckResult( $id, $label, $status, $message );
	}
}
