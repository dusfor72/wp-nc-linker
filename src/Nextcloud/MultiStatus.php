<?php
/**
 * Parser for WebDAV multistatus responses.
 *
 * @package WpNcLinker
 */

declare(strict_types=1);

namespace WpNcLinker\Nextcloud;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property names.

/**
 * Parses PROPFIND/SEARCH responses into DavEntry objects. Documents with a DOCTYPE are
 * rejected and no network access is allowed while parsing (no XXE).
 */
final class MultiStatus {

	private const DAV = 'DAV:';
	private const OC  = 'http://owncloud.org/ns';
	private const NC  = 'http://nextcloud.org/ns';

	/**
	 * Request body for PROPFIND with all properties the plugin uses.
	 */
	public static function propfind_body(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"><d:prop>'
			. self::prop_list()
			. '</d:prop></d:propfind>';
	}

	/**
	 * Request body for a SEARCH of files by MIME type prefix in the user's file tree.
	 *
	 * @param string $scope       Scope href from DavPath::search_scope().
	 * @param string $type_prefix MIME type prefix, e.g. "image/".
	 * @param int    $limit       Maximum number of results.
	 */
	public static function search_body( string $scope, string $type_prefix, int $limit ): string {
		$literal = htmlspecialchars( $type_prefix . '%', ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$href    = htmlspecialchars( $scope, ENT_XML1 | ENT_QUOTES, 'UTF-8' );

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
			. '<d:basicsearch><d:select><d:prop>' . self::prop_list() . '</d:prop></d:select>'
			. '<d:from><d:scope><d:href>' . $href . '</d:href><d:depth>infinity</d:depth></d:scope></d:from>'
			. '<d:where><d:like><d:prop><d:getcontenttype/></d:prop><d:literal>' . $literal . '</d:literal></d:like></d:where>'
			. '<d:orderby/><d:limit><d:nresults>' . max( 1, $limit ) . '</d:nresults></d:limit>'
			. '</d:basicsearch></d:searchrequest>';
	}

	/**
	 * Request body for a SEARCH of a file by its file ID in the user's file tree.
	 *
	 * @param string $scope   Scope href from DavPath::search_scope().
	 * @param int    $file_id File ID.
	 */
	public static function search_by_id_body( string $scope, int $file_id ): string {
		$href = htmlspecialchars( $scope, ENT_XML1 | ENT_QUOTES, 'UTF-8' );

		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
			. '<d:basicsearch><d:select><d:prop>' . self::prop_list() . '</d:prop></d:select>'
			. '<d:from><d:scope><d:href>' . $href . '</d:href><d:depth>infinity</d:depth></d:scope></d:from>'
			. '<d:where><d:eq><d:prop><oc:fileid/></d:prop><d:literal>' . max( 0, $file_id ) . '</d:literal></d:eq></d:where>'
			. '<d:orderby/><d:limit><d:nresults>5</d:nresults></d:limit>'
			. '</d:basicsearch></d:searchrequest>';
	}

	/**
	 * Parses a multistatus document.
	 *
	 * @param string $xml Response body.
	 * @return list<DavEntry>
	 * @throws InvalidResponseException If the document is not a valid multistatus response.
	 */
	public static function parse( string $xml ): array {
		if ( '' === trim( $xml ) ) {
			throw new InvalidResponseException( 'Empty WebDAV response.' );
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadXML( $xml, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || null !== $document->doctype ) {
			throw new InvalidResponseException( 'Invalid WebDAV response.' );
		}
		$root = $document->documentElement;
		if ( null === $root || self::DAV !== $root->namespaceURI || 'multistatus' !== $root->localName ) {
			throw new InvalidResponseException( 'Not a WebDAV multistatus response.' );
		}

		$entries = array();
		foreach ( self::children( $root, self::DAV, 'response' ) as $response ) {
			$entry = self::parse_response( $response );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Requested properties.
	 */
	private static function prop_list(): string {
		return '<d:resourcetype/><d:getcontenttype/><d:getcontentlength/><d:getetag/><d:getlastmodified/>'
			. '<oc:fileid/><oc:size/><nc:has-preview/><nc:share-attributes/><nc:metadata-photos-size/>'
			. '<nc:file-name/><nc:original-name/><nc:nbItems/>';
	}

	/**
	 * Converts one d:response element.
	 *
	 * @param \DOMElement $response Element.
	 */
	private static function parse_response( \DOMElement $response ): ?DavEntry {
		$href = self::first( $response, self::DAV, 'href' );
		if ( null === $href || '' === trim( $href->textContent ) ) {
			return null;
		}

		$props = array();
		foreach ( self::children( $response, self::DAV, 'propstat' ) as $propstat ) {
			$status = self::first( $propstat, self::DAV, 'status' );
			if ( null === $status || ! preg_match( '#^HTTP/\d(?:\.\d)? 200#', trim( $status->textContent ) ) ) {
				continue;
			}
			$prop = self::first( $propstat, self::DAV, 'prop' );
			if ( null === $prop ) {
				continue;
			}
			foreach ( $prop->childNodes as $node ) {
				if ( $node instanceof \DOMElement ) {
					$props[ '{' . $node->namespaceURI . '}' . $node->localName ] = $node;
				}
			}
		}

		$resourcetype  = $props[ '{' . self::DAV . '}resourcetype' ] ?? null;
		$is_collection = null !== $resourcetype && null !== self::first( $resourcetype, self::DAV, 'collection' );

		$size = self::int_prop( $props, self::DAV, 'getcontentlength' ) ?? self::int_prop( $props, self::OC, 'size' );

		$last_modified = null;
		$modified_text = self::text_prop( $props, self::DAV, 'getlastmodified' );
		if ( null !== $modified_text ) {
			$timestamp     = strtotime( $modified_text );
			$last_modified = false === $timestamp ? null : $timestamp;
		}

		$etag = self::text_prop( $props, self::DAV, 'getetag' );
		if ( null !== $etag ) {
			$etag = trim( $etag, '"' );
		}

		$has_preview = self::text_prop( $props, self::NC, 'has-preview' );

		[ $width, $height ] = self::photo_size( $props[ '{' . self::NC . '}metadata-photos-size' ] ?? null );

		return new DavEntry(
			href: trim( $href->textContent ),
			is_collection: $is_collection,
			file_id: self::int_prop( $props, self::OC, 'fileid' ),
			content_type: self::text_prop( $props, self::DAV, 'getcontenttype' ),
			size: $size,
			etag: $etag,
			last_modified: $last_modified,
			has_preview: null === $has_preview ? null : 'true' === $has_preview,
			download_allowed: self::download_allowed( self::text_prop( $props, self::NC, 'share-attributes' ) ),
			width: $width,
			height: $height,
			display_name: self::text_prop( $props, self::NC, 'original-name' ) ?? self::text_prop( $props, self::NC, 'file-name' ),
			item_count: self::int_prop( $props, self::NC, 'nbItems' ),
		);
	}

	/**
	 * Evaluates nc:share-attributes (JSON list of {scope, key, value}).
	 *
	 * @param string|null $json Property value.
	 */
	private static function download_allowed( ?string $json ): bool {
		if ( null === $json || '' === $json ) {
			return true;
		}
		$attributes = json_decode( $json, true );
		if ( ! is_array( $attributes ) ) {
			return true;
		}
		foreach ( $attributes as $attribute ) {
			if ( is_array( $attribute )
				&& 'permissions' === ( $attribute['scope'] ?? null )
				&& 'download' === ( $attribute['key'] ?? null )
				&& false === ( $attribute['value'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Width and height from nc:metadata-photos-size (child elements without namespace).
	 *
	 * @param \DOMElement|null $element Property element.
	 * @return array{0: int|null, 1: int|null}
	 */
	private static function photo_size( ?\DOMElement $element ): array {
		if ( null === $element ) {
			return array( null, null );
		}
		$width  = null;
		$height = null;
		foreach ( $element->childNodes as $node ) {
			if ( ! $node instanceof \DOMElement || ! preg_match( '/^\d{1,6}$/', trim( $node->textContent ) ) ) {
				continue;
			}
			if ( 'width' === $node->localName ) {
				$width = (int) trim( $node->textContent );
			} elseif ( 'height' === $node->localName ) {
				$height = (int) trim( $node->textContent );
			}
		}

		return ( null !== $width && null !== $height && $width > 0 && $height > 0 ) ? array( $width, $height ) : array( null, null );
	}

	/**
	 * Text of a property, or null if missing or empty.
	 *
	 * @param array<string, \DOMElement> $props     Properties.
	 * @param string                     $ns Namespace URI.
	 * @param string                     $name      Local name.
	 */
	private static function text_prop( array $props, string $ns, string $name ): ?string {
		$element = $props[ '{' . $ns . '}' . $name ] ?? null;
		if ( null === $element ) {
			return null;
		}
		$text = trim( $element->textContent );

		return '' === $text ? null : $text;
	}

	/**
	 * Non-negative integer property, or null.
	 *
	 * @param array<string, \DOMElement> $props     Properties.
	 * @param string                     $ns Namespace URI.
	 * @param string                     $name      Local name.
	 */
	private static function int_prop( array $props, string $ns, string $name ): ?int {
		$text = self::text_prop( $props, $ns, $name );

		return ( null !== $text && preg_match( '/^\d{1,18}$/', $text ) ) ? (int) $text : null;
	}

	/**
	 * Direct child elements with the given name.
	 *
	 * @param \DOMElement $element Parent element.
	 * @param string      $ns Namespace URI.
	 * @param string      $name      Local name.
	 * @return list<\DOMElement>
	 */
	private static function children( \DOMElement $element, string $ns, string $name ): array {
		$result = array();
		foreach ( $element->childNodes as $node ) {
			if ( $node instanceof \DOMElement && $ns === $node->namespaceURI && $name === $node->localName ) {
				$result[] = $node;
			}
		}

		return $result;
	}

	/**
	 * First direct child element with the given name.
	 *
	 * @param \DOMElement $element Parent element.
	 * @param string      $ns Namespace URI.
	 * @param string      $name      Local name.
	 */
	private static function first( \DOMElement $element, string $ns, string $name ): ?\DOMElement {
		return self::children( $element, $ns, $name )[0] ?? null;
	}
}
