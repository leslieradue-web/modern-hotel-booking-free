/**
 * MHBO Admin Media Upload
 *
 * Handles WordPress media modal for logo and QR code uploads in Business Info settings.
 *
 * @package
 * @since   2.1.0
 */

( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		document.addEventListener( 'click', function ( e ) {
			const uploadBtn = e.target.closest(
				'.mhbo-upload-btn, .mhbo-upload-button'
			);
			if ( uploadBtn ) {
				e.preventDefault();

				const targetId = uploadBtn.getAttribute( 'data-target-id' );
				const targetUrl =
					uploadBtn.getAttribute( 'data-target-url' ) ||
					uploadBtn.getAttribute( 'data-target' );
				const previewId = uploadBtn.getAttribute( 'data-preview' );

				const idTarget = targetId
					? document.getElementById( targetId )
					: null;
				const urlTarget = targetUrl
					? document.querySelector(
							targetUrl.startsWith( '#' )
								? targetUrl
								: '#' + targetUrl
					  )
					: null;
				const preview = previewId
					? document.getElementById( previewId )
					: null;
				const title =
					uploadBtn.getAttribute( 'data-title' ) || 'Select Image';

				if ( typeof wp === 'undefined' || ! wp.media ) {
					return;
				}

				const frame = wp.media( {
					title,
					button: {
						text: title,
					},
					multiple: false,
				} );

				frame.on( 'select', function () {
					const attachment = frame
						.state()
						.get( 'selection' )
						.first()
						.toJSON();

					if ( idTarget ) {
						idTarget.value = attachment.id;
						idTarget.dispatchEvent(
							new Event( 'change', { bubbles: true } )
						);
					}
					if ( urlTarget ) {
						urlTarget.value = attachment.url;
						urlTarget.dispatchEvent(
							new Event( 'change', { bubbles: true } )
						);
					}

					if ( preview ) {
						preview.innerHTML =
							'<img src="' +
							encodeURI( attachment.url ) +
							'" alt="" style="max-width:200px;height:auto;" />';
						preview.style.display = '';
					}

					const removeBtn = uploadBtn.parentElement
						? uploadBtn.parentElement.querySelector(
								'.mhbo-remove-btn'
						  )
						: null;
					if ( removeBtn ) {
						removeBtn.style.display = '';
					}
				} );

				frame.open();
				return;
			}

			const removeBtn = e.target.closest( '.mhbo-remove-btn' );
			if ( removeBtn ) {
				e.preventDefault();
				const wrap = removeBtn.closest( '.mhbo-media-upload-wrap' );
				if ( wrap ) {
					wrap.querySelectorAll( 'input[type="hidden"]' ).forEach(
						function ( input ) {
							input.value = '';
							input.dispatchEvent(
								new Event( 'change', { bubbles: true } )
							);
						}
					);
					wrap.querySelectorAll( '.mhbo-image-preview' ).forEach(
						function ( prev ) {
							prev.innerHTML = '';
							prev.style.display = 'none';
						}
					);
				}
				removeBtn.style.display = 'none';
			}
		} );
	} );
} )();
