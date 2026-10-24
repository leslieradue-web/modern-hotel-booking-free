/**
 * Booking Form Block: View Module (2026 BP)
 */
import { store, getElement } from '@wordpress/interactivity';

store( 'modern-hotel-booking/booking-form', {
	callbacks: {
		init: () => {
			const { ref } = getElement();

			if ( ref ) {
				document.dispatchEvent(
					new CustomEvent( 'mhbo_init_form', {
						detail: { element: ref },
					} )
				);
			}
		},
	},
} );
