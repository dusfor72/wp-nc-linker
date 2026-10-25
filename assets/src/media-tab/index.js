/**
 * Adds the "Nextcloud" tab to the media dialog (classic editor, block editor, media library).
 */
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import Browser from './browser';
import './media-tab.scss';

const media = window.wp?.media;

/**
 * Media library type filter of the frame (e.g. "image" for the image block).
 *
 * @param {Object} controller Media frame.
 * @return {string} Comma-separated types or an empty string.
 */
function libraryType( controller ) {
	const type = controller?.state()?.get( 'library' )?.props?.get( 'type' );
	if ( Array.isArray( type ) ) {
		return type
			.filter( ( value ) => typeof value === 'string' )
			.join( ',' );
	}
	return typeof type === 'string' ? type : '';
}

/**
 * Whether the frame allows selecting several files.
 *
 * @param {Object} controller Media frame.
 * @return {boolean} Multiple selection.
 */
function isMultiple( controller ) {
	return Boolean( controller?.state()?.get( 'multiple' ) );
}

/**
 * Links the files, selects the new attachments and switches to the media library.
 *
 * @param {Object}   controller Media frame.
 * @param {Object[]} items      Selected files ({ area, path }).
 * @return {Promise<Object[]>} Errors per file.
 */
async function linkFiles( controller, items ) {
	const result = await apiFetch( {
		path: '/wpncl/v1/attachments',
		method: 'POST',
		data: {
			items: items.map( ( { area, path } ) => ( { area, path } ) ),
			post_id: media.view.settings?.post?.id || 0,
		},
	} );

	const models = result.attachments.map( ( json ) => {
		const model = media.model.Attachment.get( json.id );
		model.set( model.parse( json ) );
		return model;
	} );

	if ( models.length > 0 ) {
		const state = controller.state();
		state.get( 'library' )?.add( models, { at: 0 } );
		const selection = state.get( 'selection' );
		if ( selection ) {
			if ( isMultiple( controller ) ) {
				selection.add( models );
			} else {
				selection.reset( models.slice( -1 ) );
			}
		}
		controller.content.mode( 'browse' );
		controller.router.get()?.select?.( 'browse' );
	}

	return result.errors;
}

if ( media?.view?.MediaFrame?.Select ) {
	const ContentView = media.View.extend( {
		className: 'wpncl-browser-view',

		render() {
			if ( ! this.root ) {
				this.root = createRoot( this.el );
			}
			this.root.render(
				<Browser
					type={ libraryType( this.controller ) }
					multiple={ isMultiple( this.controller ) }
					onLink={ ( items ) => linkFiles( this.controller, items ) }
				/>
			);
			return this;
		},

		remove() {
			if ( this.root ) {
				this.root.unmount();
				this.root = null;
			}
			return media.View.prototype.remove.apply( this, arguments );
		},
	} );

	const Select = media.view.MediaFrame.Select;

	const browseRouter = Select.prototype.browseRouter;
	Select.prototype.browseRouter = function ( routerView ) {
		browseRouter.apply( this, arguments );
		routerView.set( {
			nextcloud: {
				text: __( 'Nextcloud', 'wp-nc-linker' ),
				priority: 60,
			},
		} );
	};

	const bindHandlers = Select.prototype.bindHandlers;
	Select.prototype.bindHandlers = function () {
		bindHandlers.apply( this, arguments );
		this.on(
			'content:create:nextcloud',
			function ( region ) {
				region.view = new ContentView( { controller: this } );
			},
			this
		);
	};
}
