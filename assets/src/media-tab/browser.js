/**
 * Browser for folders and albums of the Nextcloud connection account.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const AREAS = [
	{ id: 'folders', label: __( 'Folders', 'wp-nc-linker' ) },
	{ id: 'albums', label: __( 'My albums', 'wp-nc-linker' ) },
	{ id: 'sharedalbums', label: __( 'Shared albums', 'wp-nc-linker' ) },
];

/**
 * Dashicon for items without a thumbnail.
 *
 * @param {Object} item Listing entry.
 * @return {string} Dashicon class.
 */
function iconFor( item ) {
	if ( item.kind === 'folder' ) {
		return 'dashicons-category';
	}
	if ( item.kind === 'album' ) {
		return 'dashicons-format-gallery';
	}
	if ( item.mime === 'application/pdf' ) {
		return 'dashicons-pdf';
	}
	if ( item.mime?.startsWith( 'image/' ) ) {
		return 'dashicons-format-image';
	}
	return 'dashicons-media-document';
}

/**
 * Key of a file in the selection.
 *
 * @param {string} area Area.
 * @param {Object} item Listing entry.
 * @return {string} Key.
 */
function selectionKey( area, item ) {
	return `${ area }:${ item.path }`;
}

/**
 * Breadcrumb of the current location.
 *
 * @param {Object}   props
 * @param {string}   props.areaLabel Label of the area root.
 * @param {string}   props.path      Current path.
 * @param {Function} props.onOpen    Opens a path.
 */
function Breadcrumb( { areaLabel, path, onOpen } ) {
	const segments = path === '' ? [] : path.split( '/' );
	return (
		<nav
			className="wpncl-breadcrumb"
			aria-label={ __( 'Location', 'wp-nc-linker' ) }
		>
			<Button
				variant="link"
				onClick={ () => onOpen( '' ) }
				disabled={ segments.length === 0 }
			>
				{ areaLabel }
			</Button>
			{ segments.map( ( segment, index ) => {
				const target = segments.slice( 0, index + 1 ).join( '/' );
				const isLast = index === segments.length - 1;
				return (
					<span key={ target }>
						<span className="wpncl-breadcrumb__separator">/</span>
						<Button
							variant="link"
							onClick={ () => onOpen( target ) }
							disabled={ isLast }
							aria-current={ isLast ? 'location' : undefined }
						>
							{ segment }
						</Button>
					</span>
				);
			} ) }
		</nav>
	);
}

/**
 * One tile of the listing.
 *
 * @param {Object}   props
 * @param {Object}   props.item     Listing entry.
 * @param {boolean}  props.selected Whether the file is selected.
 * @param {Function} props.onOpen   Opens a folder or album.
 * @param {Function} props.onToggle Toggles the selection of a file.
 */
function Tile( { item, selected, onOpen, onToggle } ) {
	// Nextcloud may report a preview but not deliver one (e.g. very large originals).
	const [ thumbFailed, setThumbFailed ] = useState( false );
	const isContainer = item.kind !== 'file';
	const disabled = ! isContainer && ! item.selectable;
	let title = item.name;
	if ( disabled && item.reason === 'download_restricted' ) {
		title = sprintf(
			/* translators: %s: file name */
			__(
				'%s is shared without download permission and cannot be used.',
				'wp-nc-linker'
			),
			item.name
		);
	}

	const classes = [
		'wpncl-tile',
		`wpncl-tile--${ item.kind }`,
		selected ? 'is-selected' : '',
		disabled ? 'is-disabled' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<li className={ classes }>
			<button
				type="button"
				className="wpncl-tile__button"
				title={ title }
				disabled={ disabled }
				aria-pressed={ isContainer ? undefined : selected }
				onClick={ () =>
					isContainer ? onOpen( item.path ) : onToggle( item )
				}
				data-path={ item.path }
				data-file-id={ item.file_id ?? undefined }
			>
				<span className="wpncl-tile__preview">
					{ item.thumb && ! thumbFailed ? (
						<img
							src={ item.thumb }
							alt=""
							loading="lazy"
							onError={ () => setThumbFailed( true ) }
						/>
					) : (
						<span
							className={ `dashicons ${ iconFor( item ) }` }
							aria-hidden="true"
						/>
					) }
					{ selected && (
						<span
							className="wpncl-tile__check dashicons dashicons-yes"
							aria-hidden="true"
						/>
					) }
				</span>
				<span className="wpncl-tile__name">{ item.name }</span>
				{ item.kind === 'album' && item.count !== null && (
					<span className="wpncl-tile__meta">
						{ sprintf(
							/* translators: %d: number of photos */
							_n(
								'%d item',
								'%d items',
								item.count,
								'wp-nc-linker'
							),
							item.count
						) }
					</span>
				) }
			</button>
		</li>
	);
}

/**
 * The browser.
 *
 * @param {Object}   props
 * @param {string}   props.type     Media library type filter.
 * @param {boolean}  props.multiple Whether several files may be selected.
 * @param {Function} props.onLink   Links the selected files; resolves to errors per file.
 */
export default function Browser( { type, multiple, onLink } ) {
	const [ area, setArea ] = useState( 'folders' );
	const [ path, setPath ] = useState( '' );
	const [ listing, setListing ] = useState( {
		loading: true,
		error: null,
		items: [],
	} );
	const [ selection, setSelection ] = useState( () => new Map() );
	const [ reloads, setReloads ] = useState( 0 );
	const [ linking, setLinking ] = useState( false );
	const [ linkErrors, setLinkErrors ] = useState( [] );
	const forceRefresh = useRef( false );
	const mounted = useRef( true );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	useEffect( () => {
		let cancelled = false;
		const refresh = forceRefresh.current;
		forceRefresh.current = false;
		setListing( ( previous ) => ( {
			...previous,
			loading: true,
			error: null,
		} ) );

		apiFetch( {
			path: addQueryArgs( '/wpncl/v1/browse', {
				area,
				path,
				type: type || undefined,
				refresh: refresh ? 1 : undefined,
			} ),
		} )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setListing( {
						loading: false,
						error: null,
						items: data.items,
					} );
				}
			} )
			.catch( ( error ) => {
				if ( ! cancelled ) {
					setListing( {
						loading: false,
						error:
							error?.message ||
							__( 'Loading failed.', 'wp-nc-linker' ),
						items: [],
					} );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ area, path, type, reloads ] );

	const toggle = ( item ) => {
		setSelection( ( previous ) => {
			const key = selectionKey( area, item );
			const next = multiple ? new Map( previous ) : new Map();
			if ( previous.has( key ) ) {
				next.delete( key );
			} else {
				next.set( key, { ...item, area } );
			}
			return next;
		} );
	};

	const link = async () => {
		setLinking( true );
		setLinkErrors( [] );
		try {
			const errors = await onLink( Array.from( selection.values() ) );
			if ( mounted.current ) {
				setLinkErrors( errors );
				setSelection( new Map() );
			}
		} catch ( error ) {
			if ( mounted.current ) {
				setLinkErrors( [
					{
						path: '',
						message:
							error?.message ||
							__( 'Linking failed.', 'wp-nc-linker' ),
					},
				] );
			}
		} finally {
			if ( mounted.current ) {
				setLinking( false );
			}
		}
	};

	const openArea = ( id ) => {
		setArea( id );
		setPath( '' );
	};

	const areaLabel = AREAS.find( ( entry ) => entry.id === area )?.label;

	return (
		<div className="wpncl-browser">
			<div className="wpncl-browser__toolbar">
				<div
					className="wpncl-browser__areas"
					role="group"
					aria-label={ __( 'Source', 'wp-nc-linker' ) }
				>
					{ AREAS.map( ( entry ) => (
						<Button
							key={ entry.id }
							variant={
								entry.id === area ? 'primary' : 'secondary'
							}
							isPressed={ entry.id === area }
							onClick={ () => openArea( entry.id ) }
						>
							{ entry.label }
						</Button>
					) ) }
				</div>
				<Button
					variant="tertiary"
					icon="update"
					label={ __( 'Refresh', 'wp-nc-linker' ) }
					onClick={ () => {
						forceRefresh.current = true;
						setReloads( ( value ) => value + 1 );
					} }
				/>
			</div>

			<Breadcrumb
				areaLabel={ areaLabel }
				path={ path }
				onOpen={ setPath }
			/>

			<div
				className="wpncl-browser__content"
				aria-busy={ listing.loading }
			>
				{ listing.loading && <Spinner /> }
				{ linkErrors.length > 0 && (
					<Notice
						status="error"
						onRemove={ () => setLinkErrors( [] ) }
						className="wpncl-browser__link-errors"
					>
						<ul>
							{ linkErrors.map( ( error, index ) => (
								<li key={ `${ error.path }-${ index }` }>
									{ error.path
										? `${ error.path }: ${ error.message }`
										: error.message }
								</li>
							) ) }
						</ul>
					</Notice>
				) }
				{ listing.error && (
					<Notice status="error" isDismissible={ false }>
						{ listing.error }
					</Notice>
				) }
				{ ! listing.loading &&
					! listing.error &&
					listing.items.length === 0 && (
						<p className="wpncl-browser__empty">
							{ __( 'Nothing to show here.', 'wp-nc-linker' ) }
						</p>
					) }
				{ ! listing.loading && listing.items.length > 0 && (
					<ul className="wpncl-browser__grid">
						{ listing.items.map( ( item ) => (
							<Tile
								key={ item.path }
								item={ item }
								selected={ selection.has(
									selectionKey( area, item )
								) }
								onOpen={ setPath }
								onToggle={ toggle }
							/>
						) ) }
					</ul>
				) }
			</div>

			<div className="wpncl-browser__footer">
				<Button
					variant="primary"
					onClick={ link }
					disabled={ selection.size === 0 || linking }
					isBusy={ linking }
				>
					{ linking
						? __( 'Adding…', 'wp-nc-linker' )
						: __( 'Add to media library', 'wp-nc-linker' ) }
				</Button>
				<span className="wpncl-browser__count" aria-live="polite">
					{ sprintf(
						/* translators: %d: number of selected files */
						_n(
							'%d file selected',
							'%d files selected',
							selection.size,
							'wp-nc-linker'
						),
						selection.size
					) }
				</span>
				{ selection.size > 0 && (
					<Button
						variant="link"
						onClick={ () => setSelection( new Map() ) }
					>
						{ __( 'Clear selection', 'wp-nc-linker' ) }
					</Button>
				) }
			</div>
		</div>
	);
}
