/**
 * MHBO Business Settings Admin
 *
 * Handles media upload for Business Logo, WhatsApp QR, and Banking QR.
 *
 * @package
 * @since   2.1.0
 */

( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		// Generic Media Upload Handler via event delegation
		document.addEventListener( 'click', function ( e ) {
			const button = e.target.closest( '.mhbo-business-upload-button' );
			if ( ! button ) {
				return;
			}
			e.preventDefault();

			const container = button.closest( '.mhbo-business-media-control' );
			if ( ! container ) {
				return;
			}
			const input = container.querySelector( '.mhbo-business-media-url' );
			const preview = container.querySelector(
				'.mhbo-business-media-preview'
			);

			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}

			const title = button.getAttribute( 'data-title' ) || 'Select Media';
			const buttonText =
				button.getAttribute( 'data-button' ) || 'Use this media';

			const customFrame = wp.media( {
				title,
				button: { text: buttonText },
				multiple: false,
			} );

			customFrame.on( 'select', function () {
				const attachment = customFrame
					.state()
					.get( 'selection' )
					.first()
					.toJSON();

				if ( input ) {
					input.value = attachment.url;
					input.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}

				if ( preview ) {
					preview.innerHTML =
						'<img src="' +
						encodeURI( attachment.url ) +
						'" style="max-width:150px;height:auto;margin-top:10px;" alt="">';
				}
			} );

			customFrame.open();
		} );
	} );
} )();
