<?php
/**
 * REST route to link files as attachments.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Rest;

use WpNcLinker\Media\ImportException;
use WpNcLinker\Media\Importer;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST wpncl/v1/attachments with {items: [{area, path}], post_id}. Returns the attachments in the
 * format of the media library (wp_prepare_attachment_for_js) and one error per failed item.
 */
final class AttachmentsController {

	/**
	 * Constructor.
	 *
	 * @param Importer $importer Linking of files.
	 */
	public function __construct( private readonly Importer $importer ) {
	}

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			BrowseController::NAMESPACE,
			'/attachments',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( BrowseController::class, 'check_permission' ),
				'args'                => array(
					'items'   => array(
						'type'     => 'array',
						'required' => true,
						'minItems' => 1,
						'maxItems' => Importer::MAX_ITEMS,
						'items'    => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'area' => array(
									'type'     => 'string',
									'required' => true,
								),
								'path' => array(
									'type'      => 'string',
									'required'  => true,
									'maxLength' => 4096,
								),
							),
						),
					),
					'post_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * POST /attachments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response {
		$post_id   = (int) $request->get_param( 'post_id' );
		$parent_id = $post_id > 0 && current_user_can( 'edit_post', $post_id ) ? $post_id : 0;
		$items     = $request->get_param( 'items' );

		$attachments = array();
		$errors      = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$area = is_array( $item ) && is_string( $item['area'] ?? null ) ? $item['area'] : '';
			$path = is_array( $item ) && is_string( $item['path'] ?? null ) ? $item['path'] : '';
			try {
				$attachment_id = $this->importer->import( $area, $path, $parent_id );
				$attachments[] = wp_prepare_attachment_for_js( $attachment_id );
			} catch ( ImportException $e ) {
				$errors[] = array(
					'area'    => $area,
					'path'    => $path,
					'code'    => $e->reason,
					'message' => $e->user_message(),
				);
			}
		}

		return new WP_REST_Response(
			array(
				'attachments' => $attachments,
				'errors'      => $errors,
			)
		);
	}
}
