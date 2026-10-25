<?php
/**
 * REST routes of the media dialog.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Rest;

use WpNcLinker\Admin\Capabilities;
use WpNcLinker\Browse\Area;
use WpNcLinker\Browse\BrowseException;
use WpNcLinker\Browse\Browser;
use WpNcLinker\Browse\Item;
use WpNcLinker\Browse\SeenFiles;
use WpNcLinker\Nextcloud\Client;
use WpNcLinker\Nextcloud\NotConfiguredException;
use WpNcLinker\Nextcloud\TransportException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET wpncl/v1/browse (one level of folders or albums) and GET wpncl/v1/thumb/{id} (dialog
 * thumbnail of a file the current user has just listed). Both require the select capability.
 */
final class BrowseController {

	public const NAMESPACE = 'wpncl/v1';

	public const THUMB_SIZE = 256;

	private const MAX_THUMB_BYTES = 2097152;

	/**
	 * Constructor.
	 *
	 * @param Browser   $browser Listings.
	 * @param SeenFiles $seen    File IDs listed per user.
	 * @param Client    $client  Nextcloud client.
	 */
	public function __construct(
		private readonly Browser $browser,
		private readonly SeenFiles $seen,
		private readonly Client $client,
	) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_binary' ), 10, 2 );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/browse',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'browse' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'area'    => array(
						'type'     => 'string',
						'enum'     => array_map( static fn ( Area $area ): string => $area->value, Area::cases() ),
						'required' => true,
					),
					'path'    => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 4096,
					),
					'type'    => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^[a-z0-9.+/,-]*$',
					),
					'refresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/thumb/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'thumb' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
	}

	/**
	 * Permission check of both routes.
	 *
	 * @return true|WP_Error
	 */
	public static function check_permission(): bool|WP_Error {
		if ( Capabilities::can_select() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to browse Nextcloud.', 'wp-nc-linker' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /browse.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function browse( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$area = Area::tryFrom( (string) $request->get_param( 'area' ) );
		if ( null === $area ) {
			return new WP_Error( 'rest_invalid_param', __( 'Invalid area.', 'wp-nc-linker' ), array( 'status' => 400 ) );
		}
		$path  = (string) $request->get_param( 'path' );
		$types = array_values( array_filter( explode( ',', (string) $request->get_param( 'type' ) ) ) );

		try {
			$path  = Browser::normalize( $area, $path );
			$items = $this->browser->list( $area, $path, $types, (bool) $request->get_param( 'refresh' ) );
		} catch ( BrowseException $e ) {
			return self::error( $e );
		}

		$thumb_ids = array();
		foreach ( $items as $item ) {
			if ( null !== $item->file_id && $item->preview && $item->selectable ) {
				$thumb_ids[] = $item->file_id;
			}
		}
		$this->seen->remember( get_current_user_id(), $thumb_ids );

		$nonce = wp_create_nonce( 'wp_rest' );
		$data  = array(
			'area'   => $area->value,
			'path'   => $path,
			'parent' => self::parent_path( $path ),
			'items'  => array_map(
				static function ( Item $item ) use ( $thumb_ids, $nonce ): array {
					$data          = $item->to_array();
					$data['thumb'] = null !== $item->file_id && in_array( $item->file_id, $thumb_ids, true )
						? add_query_arg( '_wpnonce', $nonce, rest_url( self::NAMESPACE . '/thumb/' . $item->file_id ) )
						: null;
					return $data;
				},
				$items
			),
		);

		return new WP_REST_Response( $data );
	}

	/**
	 * GET /thumb/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function thumb( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$file_id   = (int) $request->get_param( 'id' );
		$not_found = new WP_Error( 'wpncl_not_found', __( 'Preview not available.', 'wp-nc-linker' ), array( 'status' => 404 ) );

		if ( ! $this->seen->contains( get_current_user_id(), $file_id ) ) {
			return $not_found;
		}

		try {
			$preview = $this->client->preview( $file_id, self::THUMB_SIZE, self::THUMB_SIZE, self::MAX_THUMB_BYTES );
		} catch ( NotConfiguredException | TransportException ) {
			return self::error( new BrowseException( 'Nextcloud not reachable.', BrowseException::UNAVAILABLE ) );
		}

		$type = strtolower( trim( explode( ';', (string) $preview->header( 'content-type' ) )[0] ) );
		if ( 200 !== $preview->status || ! in_array( $type, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ), true ) || '' === $preview->body ) {
			return $not_found;
		}

		$response = new WP_REST_Response( new BinaryBody( $preview->body ), 200 );
		$response->set_headers(
			array(
				'Content-Type'           => $type,
				'Content-Length'         => (string) strlen( $preview->body ),
				'Cache-Control'          => 'private, max-age=3600',
				'X-Content-Type-Options' => 'nosniff',
			)
		);

		return $response;
	}

	/**
	 * Sends binary bodies unchanged instead of JSON.
	 *
	 * @param bool  $served Whether the request was already served.
	 * @param mixed $result Response.
	 */
	public function serve_binary( bool $served, mixed $result ): bool {
		if ( $served || ! $result instanceof WP_REST_Response || ! $result->get_data() instanceof BinaryBody ) {
			return $served;
		}

		echo $result->get_data()->bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image bytes with an explicit image Content-Type and nosniff.

		return true;
	}

	/**
	 * Path of the parent location, or null at the root.
	 *
	 * @param string $path Normalised path.
	 */
	private static function parent_path( string $path ): ?string {
		if ( '' === $path ) {
			return null;
		}
		$slash = strrpos( $path, '/' );

		return false === $slash ? '' : substr( $path, 0, $slash );
	}

	/**
	 * REST error for a failed listing (no internal details).
	 *
	 * @param BrowseException $e Failure.
	 */
	private static function error( BrowseException $e ): WP_Error {
		$status  = $e->getCode();
		$message = match ( $status ) {
			BrowseException::BAD_REQUEST => __( 'Invalid path.', 'wp-nc-linker' ),
			BrowseException::NOT_FOUND   => __( 'This folder or album does not exist (anymore).', 'wp-nc-linker' ),
			BrowseException::UNAVAILABLE => __( 'Nextcloud is not reachable. Please ask an administrator to run the connection test.', 'wp-nc-linker' ),
			default                      => __( 'Nextcloud sent an unexpected answer.', 'wp-nc-linker' ),
		};

		return new WP_Error( 'wpncl_browse_failed', $message, array( 'status' => $status ) );
	}
}
