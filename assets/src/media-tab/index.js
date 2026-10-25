/**
 * Adds the "Nextcloud" tab to the media dialog (classic editor, block editor, media library).
 */
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

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

if ( media?.view?.MediaFrame?.Select ) {
	const ContentView = media.View.extend( {
		className: 'wpncl-browser-view',

		render() {
			if ( ! this.root ) {
				this.root = createRoot( this.el );
			}
			this.root.render(
				<Browser type={ libraryType( this.controller ) } />
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
