/**
 * Modern Hotel Booking - Calendar JavaScript (Pure Vanilla JS - WP 7.1 / 2026 BP)
 * Zero jQuery dependency, native DOM APIs, custom events, and fetch integration.
 */
( function () {
	'use strict';

	// Future booking window limits (dynamic settings with safe fallbacks)
	function getMaxAdvanceDays() {
		if (
			typeof mhbo_calendar !== 'undefined' &&
			mhbo_calendar.settings &&
			mhbo_calendar.settings.max_advance_days
		) {
			const d = parseInt( mhbo_calendar.settings.max_advance_days, 10 );
			if ( d >= 14 && d <= 730 ) {
				return d;
			}
		}
		return 365;
	}

	function getInitialCalendarDays() {
		if (
			typeof mhbo_calendar !== 'undefined' &&
			mhbo_calendar.settings &&
			mhbo_calendar.settings.initial_calendar_days
		) {
			const d = parseInt(
				mhbo_calendar.settings.initial_calendar_days,
				10
			);
			if ( d >= 14 && d <= 730 ) {
				return d;
			}
		}
		return Math.min( 120, getMaxAdvanceDays() );
	}

	// Debug logger - only logs when mhbo_calendar.debug is true or localStorage flag is set
	const debugLog = ( function () {
		const isDebug =
			( typeof mhbo_calendar !== 'undefined' && mhbo_calendar.debug ) ||
			( typeof localStorage !== 'undefined' &&
				localStorage.getItem( 'mhbo_debug' ) );
		return isDebug
			? console.error.bind( console, '[MHBO]' )
			: function () {};
	} )();

	// Create shared tooltip element (2026 BP: Single tooltip per page for performance)
	let tooltip = document.querySelector( '.mhbo-calendar-tooltip' );
	if ( ! tooltip ) {
		tooltip = document.createElement( 'div' );
		tooltip.className = 'mhbo-calendar-tooltip';
		tooltip.innerHTML =
			'<span class="mhbo-tooltip-nights"></span><span class="mhbo-tooltip-price"></span><span class="mhbo-tooltip-constraint" style="display:none"></span>';
		document.body.appendChild( tooltip );
	}

	// Mobile: mouseleave never fires on touch, so dismiss tooltip on any touch outside the calendar
	if ( ! document.documentElement.dataset.mhboTooltipTouchAttached ) {
		const dismissTooltip = function ( e ) {
			if ( ! e.target.closest( '.mhbo-calendar-inline' ) ) {
				tooltip.classList.remove( 'mhbo-visible', 'mhbo-tooltip-warn' );
				const constraint = tooltip.querySelector(
					'.mhbo-tooltip-constraint'
				);
				if ( constraint ) {
					constraint.style.display = 'none';
				}
				document
					.querySelectorAll( '.mhbo-range-hover' )
					.forEach( ( el ) =>
						el.classList.remove( 'mhbo-range-hover' )
					);
			}
		};
		document.addEventListener( 'touchstart', dismissTooltip, {
			passive: true,
		} );
		document.addEventListener( 'pointerup', dismissTooltip, {
			passive: true,
		} );
		document.documentElement.dataset.mhboTooltipTouchAttached = 'true';
	}

	function initAllCalendars() {
		document
			.querySelectorAll( '.mhbo-calendar-container' )
			.forEach( ( wrapper ) => {
				// Double-initialization protection (2026 best practice)
				if ( wrapper.dataset.mhboInitialized ) {
					return;
				}
				wrapper.dataset.mhboInitialized = 'true';
				wrapper.classList.add( 'mhbo-initialized' );

				const roomId = wrapper.dataset.roomId;
				const selectionBox = wrapper.querySelector(
					'.mhbo-selection-box'
				);
				const guide = wrapper.querySelector( '.mhbo-calendar-guide' );
				const inlineContainer = wrapper.querySelector(
					'.mhbo-calendar-inline'
				);
				const errorBox = wrapper.querySelector(
					'.mhbo-calendar-errors'
				);
				const showPrice = String( wrapper.dataset.showPrice ) === '1';

				let picker = null;
				const disabledDates = [];
				const priceData = {};
				const bookingStatusData = {}; // Track booking status per date
				const rawBookingStatusData = {}; // Track raw booking status per date
				const checkoutStatusData = {}; // Track checkout booking status per date
				const rawCheckoutStatusData = {}; // Track raw checkout booking status per date
				const changeoverData = {}; // Track changeover status (checkin/checkout/both)
				const eligibilityData = {}; // Track selection eligibility (can_checkin/can_checkout)
				const freeRoomsData = {}; // Track specifically which rooms are free PM for continuous availability checking
				const minStayData = {}; // PRO: Track minimum stay per check-in date
				const maxStayData = {}; // PRO: Track maximum stay per check-in date
				const reasonData = {}; // Track block reason per disabled date (booked/manual/maintenance)
				let pendingCheckIn = null; // Track the first date clicked to detect backwards selection
				const rawDisabledDates = []; // Track backend disabled dates (ignoring dynamic min-stay blocks)
				let isHydrated = false;
				let isHydrating = false;

				function scheduleHydration( offsetDays, totalDays ) {
					if (
						isHydrated ||
						isHydrating ||
						offsetDays >= totalDays
					) {
						return;
					}
					const scheduleFn =
						window.requestIdleCallback ||
						function ( cb ) {
							return setTimeout( cb, 800 );
						};
					scheduleFn( function () {
						fetchRemainingCalendarData(
							offsetDays,
							totalDays - offsetDays
						);
					} );
				}

				function fetchRemainingCalendarData( offset, limit ) {
					if ( isHydrated || isHydrating || limit <= 0 ) {
						return;
					}
					isHydrating = true;
					const params = new URLSearchParams( {
						room_id: roomId,
						year: new Date().getFullYear(),
						month: new Date().getMonth() + 1,
						offset_days: offset,
						days_limit: limit,
					} );
					fetch(
						mhbo_calendar.rest_url + '?' + params.toString(),
						{
							method: 'GET',
							headers: {
								'X-WP-Nonce': mhbo_calendar.nonce || '',
								Accept: 'application/json',
							},
						}
					)
						.then( ( res ) => {
							if ( ! res.ok ) {
								throw new Error( 'HTTP ' + res.status );
							}
							return res.json();
						} )
						.then( ( data ) => {
							isHydrating = false;
							if (
								Array.isArray( data ) &&
								data.length > 0
							) {
								isHydrated = true;
								processCalendarData( data );
								wrapper._mhboMinStayData = minStayData;
								wrapper._mhboMaxStayData = maxStayData;
								if ( picker ) {
									picker.set( 'disable', disabledDates );
								}
								debugLog(
									'Progressive Hydration: loaded ' +
										data.length +
										' additional days.'
								);
							}
						} )
						.catch( ( err ) => {
							isHydrating = false;
							debugLog(
								'Progressive Hydration Error:',
								err ? err.message : err
							);
						} );
				}

				function showInlineError( message ) {
					if ( errorBox ) {
						errorBox.classList.remove( 'mhbo-constraint-hint' );
						errorBox.textContent = message;
						errorBox.style.display = '';
						errorBox.classList.add( 'mhbo-visible' );
						// Auto-hide after 5s for generic errors
						setTimeout( () => {
							errorBox.classList.remove( 'mhbo-visible' );
							errorBox.textContent = '';
							errorBox.style.display = 'none';
						}, 5000 );
					} else {
						console.error( '[MHBO] Validation Error:', message );
					}
				}

				// Persistent hint for min/max stay violations — stays visible until user corrects
				// their selection. Styled differently (warning, not error) so it reads as guidance.
				function showConstraintHint( message ) {
					if ( errorBox ) {
						errorBox.classList.add(
							'mhbo-constraint-hint',
							'mhbo-visible'
						);
						errorBox.textContent = message;
						errorBox.style.display = '';
					} else {
						console.warn( '[MHBO] Stay constraint:', message );
					}
				}

				function hideConstraintHint() {
					if (
						errorBox &&
						errorBox.classList.contains( 'mhbo-constraint-hint' )
					) {
						errorBox.classList.remove(
							'mhbo-visible',
							'mhbo-constraint-hint'
						);
						errorBox.textContent = '';
						errorBox.style.display = 'none';
					}
				}

				function initFlatpickr() {
					// Explicitly ensure hidden states on init (defense-in-depth)
					if ( selectionBox ) {
						selectionBox.classList.remove( 'mhbo-visible' );
						selectionBox.style.display = 'none';
					}
					if ( errorBox ) {
						errorBox.classList.remove( 'mhbo-visible' );
						errorBox.style.display = 'none';
					}

					// BP 2026: Zero-Latency DOM Preloading (Tier 1 fast 4-month payload)
					const payloadScript = document.getElementById(
						'mhbo-calendar-payload-' + roomId
					);
					let preloadedData = null;
					let currentHash = null;
					let preloadedInitialDays = null;
					let preloadedMaxDays = null;

					if ( payloadScript ) {
						try {
							const rawPayload =
								payloadScript.getAttribute( 'data-payload' ) ||
								payloadScript.textContent;
							const json = JSON.parse( rawPayload );
							if ( json && json.data ) {
								preloadedData = json.data;
								currentHash = json.hash;
								preloadedInitialDays =
									json.initial_days || null;
								preloadedMaxDays = json.max_days || null;
							}
						} catch ( e ) {
							debugLog( 'DOM Preload Parse Error:', e );
						}
					}

if ( preloadedData && Array.isArray( preloadedData ) ) {
						// Instant load from DOM!
						processCalendarData( preloadedData );
						wrapper._mhboMinStayData = minStayData;
						wrapper._mhboMaxStayData = maxStayData;
						renderFlatpickr();

						// Stale-While-Revalidate: Silently verify cache state in the background
						const initialDays =
							preloadedInitialDays || getInitialCalendarDays();
						const maxDays = preloadedMaxDays || getMaxAdvanceDays();
						verifyCacheState( currentHash, initialDays );

						// Schedule Tier 2 Background Progressive Hydration
						scheduleHydration( initialDays, maxDays );
					} else {
						// Fallback to initial network request
						fetchCalendarData();
					}
				}

				function resetCalendarState() {
					isHydrated = false;
					isHydrating = false;
					disabledDates.length = 0;
					rawDisabledDates.length = 0;
					for ( const key in priceData ) {
						delete priceData[ key ];
					}
					for ( const key in bookingStatusData ) {
						delete bookingStatusData[ key ];
					}
					for ( const key in rawBookingStatusData ) {
						delete rawBookingStatusData[ key ];
					}
					for ( const key in checkoutStatusData ) {
						delete checkoutStatusData[ key ];
					}
					for ( const key in rawCheckoutStatusData ) {
						delete rawCheckoutStatusData[ key ];
					}
					for ( const key in changeoverData ) {
						delete changeoverData[ key ];
					}
					for ( const key in eligibilityData ) {
						delete eligibilityData[ key ];
					}
					for ( const key in freeRoomsData ) {
						delete freeRoomsData[ key ];
					}
					
				}

				function verifyCacheState( currentHash, initialDays ) {
					const params = new URLSearchParams( {
						room_id: roomId,
						year: new Date().getFullYear(),
						month: new Date().getMonth() + 1,
						offset_days: 0,
						days_limit: initialDays || getInitialCalendarDays(),
					} );
					fetch( mhbo_calendar.rest_url + '?' + params.toString(), {
						method: 'GET',
						headers: {
							'X-WP-Nonce': mhbo_calendar.nonce || '',
							Accept: 'application/json',
						},
					} )
						.then( ( response ) => {
							if ( ! response.ok ) {
								throw new Error(
									'HTTP error ' + response.status
								);
							}
							const serverHash = response.headers.get(
								'X-MHBO-Calendar-Hash'
							);
							if ( serverHash && serverHash === currentHash ) {
								debugLog(
									'Stale-While-Revalidate: Cache is valid.'
								);
								return null; // UI is perfectly in sync!
							}
							return response.json();
						} )
						.then( ( data ) => {
							if ( ! data ) {
								return;
							}
							// Cache mismatch! Silent hot-swap of availability
							debugLog(
								'Stale-While-Revalidate: Hot-swapping stale calendar data.'
							);
							if ( Array.isArray( data ) ) {
								resetCalendarState();
								isHydrated = false;
								processCalendarData( data );
								wrapper._mhboMinStayData = minStayData;
								wrapper._mhboMaxStayData = maxStayData;

								// Reset any in-progress selection to prevent stale state
								pendingCheckIn = null;
								if ( selectionBox ) {
									selectionBox.classList.remove(
										'mhbo-visible'
									);
									selectionBox.style.display = 'none';
								}

								if ( picker ) {
									picker.set( 'disable', disabledDates );
									picker.redraw();
								} else {
									renderFlatpickr();
								}
								scheduleHydration(
									initialDays || getInitialCalendarDays(),
									getMaxAdvanceDays()
								);
							}
						} )
						.catch( ( err ) => {
							debugLog(
								'REST Verification Error:',
								err ? err.message : err
							);
						} );
				}

				function fetchCalendarData() {
					const initialDays = getInitialCalendarDays();
					const params = new URLSearchParams( {
						room_id: roomId,
						year: new Date().getFullYear(),
						month: new Date().getMonth() + 1,
						offset_days: 0,
						days_limit: initialDays,
					} );
					fetch( mhbo_calendar.rest_url + '?' + params.toString(), {
						method: 'GET',
						headers: {
							'X-WP-Nonce': mhbo_calendar.nonce || '',
							Accept: 'application/json',
						},
					} )
						.then( ( response ) => {
							if ( ! response.ok ) {
								throw new Error(
									'HTTP error ' + response.status
								);
							}
							return response.json();
						} )
						.then( ( data ) => {
							if (
								data &&
								Array.isArray( data ) &&
								data.length > 0
							) {
								processCalendarData( data );
								wrapper._mhboMinStayData = minStayData;
								wrapper._mhboMaxStayData = maxStayData;
								renderFlatpickr();
								scheduleHydration(
									initialDays,
									getMaxAdvanceDays()
								);
							} else {
								renderFlatpickr();
							}
						} )
						.catch( ( err ) => {
							debugLog(
								'REST Error:',
								err ? err.message : 'Network error'
							);
							renderFlatpickr();
						} );
				}

				function normalizeBookingStatus( status ) {
					if ( ! status ) {
						return null;
					}
					const s = String( status ).toLowerCase();
					if ( s === 'pending' || s === 'pending_payment' || s === 'processing' ) {
						return 'pending';
					}
					if ( s === 'cancelled' || s === 'refunded' || s === 'failed' ) {
						return null;
					}
					return 'confirmed';
				}

				function processCalendarData( data ) {
					data.forEach( ( item ) => {
						if (
							item.status === 'booked' ||
							item.price === 0 ||
							item.price === '0' ||
							! item.price
						) {
							if ( ! disabledDates.includes( item.date ) ) {
								disabledDates.push( item.date );
							}
							if ( ! rawDisabledDates.includes( item.date ) ) {
								rawDisabledDates.push( item.date );
							}
						}
						priceData[ item.date ] = {
							price: item.price,
							formatted: item.price_formatted,
						};
						// Store booking status (pending/confirmed) for color coding
						const normBookingStatus = normalizeBookingStatus( item.booking_status );
						if ( normBookingStatus ) {
							bookingStatusData[ item.date ] = normBookingStatus;
						}
						if ( item.raw_booking_status ) {
							rawBookingStatusData[ item.date ] = String( item.raw_booking_status ).toLowerCase();
						} else if ( item.booking_status ) {
							rawBookingStatusData[ item.date ] = String( item.booking_status ).toLowerCase();
						}

						const normCheckoutStatus = normalizeBookingStatus( item.checkout_booking_status );
						if ( normCheckoutStatus ) {
							checkoutStatusData[ item.date ] = normCheckoutStatus;
						}
						if ( item.raw_checkout_booking_status ) {
							rawCheckoutStatusData[ item.date ] = String( item.raw_checkout_booking_status ).toLowerCase();
						} else if ( item.checkout_booking_status ) {
							rawCheckoutStatusData[ item.date ] = String( item.checkout_booking_status ).toLowerCase();
						}

						// Determine changeover status for split-day styling
						if ( item.is_checkin && item.is_checkout ) {
							changeoverData[ item.date ] = 'both';
						} else if ( item.is_checkin ) {
							changeoverData[ item.date ] = 'checkin';
						} else if ( item.is_checkout ) {
							changeoverData[ item.date ] = 'checkout';
						}

						// Store eligibility for selection guards
						eligibilityData[ item.date ] = {
							checkin: item.can_checkin !== false,
							checkout: item.can_checkout !== false,
						};

						if ( Array.isArray( item.free_rooms ) ) {
							freeRoomsData[ item.date ] = item.free_rooms;
						}

} );

}

				function renderFlatpickr() {
					if ( ! inlineContainer ) {
						return;
					}

					// Determine locale dynamically based on loaded scripts
					let locale = 'en';
					if (
						mhbo_calendar.current_lang &&
						typeof flatpickr !== 'undefined' &&
						flatpickr.l10ns &&
						flatpickr.l10ns[ mhbo_calendar.current_lang ]
					) {
						locale = mhbo_calendar.current_lang;
					}

					picker = flatpickr( inlineContainer, {
						inline: true,
						mode: 'range',
						minDate: 'today',
						maxDate: new Date().fp_incr( getMaxAdvanceDays() ), // Limit selectable dates to advance booking window
						dateFormat: 'Y-m-d',
						disable: disabledDates,
						disableMobile: true,
						showMonths: 1,
						locale,
						onChange( selectedDates, dateStr, instance ) {
							// Clear errors on change
							if ( errorBox ) {
								errorBox.style.display = 'none';
							}

							const dateStrClicked =
								selectedDates.length > 0
									? instance.formatDate(
											selectedDates[
												selectedDates.length - 1
											],
											'Y-m-d'
									  )
									: null;

							// Backwards-selection guard: flatpickr range mode auto-sorts selected dates
							if (
								selectedDates.length === 2 &&
								pendingCheckIn
							) {
								if (
									selectedDates[ 0 ].getTime() !==
									pendingCheckIn.getTime()
								) {
									// Earlier date was clicked — treat it as a fresh check-in
									pendingCheckIn = selectedDates[ 0 ];
									instance.setDate(
										[ selectedDates[ 0 ] ],
										true
									);
									return;
								}
							}

							// 1. Guard: Check-In Eligibility
							if (
								selectedDates.length === 1 &&
								dateStrClicked &&
								eligibilityData[ dateStrClicked ]
							) {
								if (
									! eligibilityData[ dateStrClicked ].checkin
								) {
									showInlineError(
										mhbo_calendar.i18n
											.checkout_only_error ||
											'This date is restricted to check-outs only.'
									);
									instance.clear();
									return;
								}

}

							// 3. Guard: Min/Max Stay Enforcement (PRO)

// 2. Guard: Check-Out Eligibility
							if (
								selectedDates.length === 2 &&
								dateStrClicked &&
								eligibilityData[ dateStrClicked ]
							) {
								if (
									! eligibilityData[ dateStrClicked ].checkout
								) {
									showInlineError(
										mhbo_calendar.i18n.checkin_only_error ||
											'This date is restricted to check-ins only.'
									);
									instance.setDate(
										[ selectedDates[ 0 ] ],
										false
									);
									return;
								}
							}

							// Dynamically allow next booked date as checkout date
							if ( selectedDates.length === 1 ) {
								pendingCheckIn = selectedDates[ 0 ];
								const checkIn = selectedDates[ 0 ];
								let firstBookedAfter = null;

								const checkInStr = instance.formatDate(
									checkIn,
									'Y-m-d'
								);
								let currentFreeRooms =
									freeRoomsData[ checkInStr ] || null;

								for (
									let i = 1;
									i <= getMaxAdvanceDays();
									i++
								) {
									const nextDate = new Date(
										checkIn.getFullYear(),
										checkIn.getMonth(),
										checkIn.getDate() + i
									);
									const nextDateStr = instance.formatDate(
										nextDate,
										'Y-m-d'
									);

									if (
										disabledDates.includes( nextDateStr )
									) {
										firstBookedAfter = nextDateStr;
										break;
									}

									if (
										currentFreeRooms &&
										Array.isArray( currentFreeRooms ) &&
										currentFreeRooms.length > 0
									) {
										const nextFreeRooms =
											freeRoomsData[ nextDateStr ] || [];
										const intersection =
											currentFreeRooms.filter( ( r ) =>
												nextFreeRooms.includes( r )
											);

										if ( intersection.length === 0 ) {
											firstBookedAfter = nextDateStr;
											break;
										}
										currentFreeRooms = intersection;
									}
								}

								let newDisabled = [ ...disabledDates ];
								if ( firstBookedAfter ) {
									newDisabled = disabledDates.filter(
										( d ) =>
											d < checkInStr ||
											d > firstBookedAfter
									);
									instance.set( 'maxDate', firstBookedAfter );
								} else {
									instance.set(
										'maxDate',
										new Date().fp_incr(
											getMaxAdvanceDays()
										)
									);
								}

								if (
									JSON.stringify(
										instance.config.disable
									) !== JSON.stringify( newDisabled )
								) {
									instance.set( 'disable', newDisabled );
								}
							} else if ( selectedDates.length === 2 ) {
								pendingCheckIn = null;
								instance.set(
									'maxDate',
									new Date().fp_incr( getMaxAdvanceDays() )
								);

								if (
									selectedDates[ 0 ].getTime() ===
									selectedDates[ 1 ].getTime()
								) {
									const nextDay = new Date(
										selectedDates[ 0 ]
									);
									nextDay.setDate( nextDay.getDate() + 1 );

									instance.setDate(
										[ selectedDates[ 0 ], nextDay ],
										false
									);

									const checkOutStr = instance.formatDate(
										nextDay,
										'Y-m-d'
									);
									const newDisabledFinal =
										disabledDates.filter(
											( d ) => d !== checkOutStr
										);
									instance.set( 'disable', newDisabledFinal );

									updateBookingForm( [
										selectedDates[ 0 ],
										nextDay,
									] );
									return;
								}

								const checkOutStr2 = instance.formatDate(
									selectedDates[ 1 ],
									'Y-m-d'
								);
								const newDisabledFinal2 = disabledDates.filter(
									( d ) => d !== checkOutStr2
								);
								instance.set( 'disable', newDisabledFinal2 );

								updateBookingForm( selectedDates );
							} else {
								pendingCheckIn = null;
								instance.set(
									'maxDate',
									new Date().fp_incr( getMaxAdvanceDays() )
								);
								instance.set( 'disable', disabledDates );
								if ( selectionBox ) {
									selectionBox.classList.remove(
										'mhbo-visible'
									);
									selectionBox.style.display = 'none';
								}
								if ( guide ) {
									guide.textContent =
										mhbo_calendar.i18n.select_checkin ||
										'Select your check-in date';
								}
							}
						},
						onMonthChange( selectedDates, dateStr, instance ) {
							if ( ! isHydrated && ! isHydrating ) {
								const initialDays = getInitialCalendarDays();
								const maxDays = getMaxAdvanceDays();
								if ( initialDays < maxDays ) {
									fetchRemainingCalendarData(
										initialDays,
										maxDays - initialDays
									);
								}
							}
						},
						onDayCreate( dObj, dStr, fp, dayElem ) {
							const date = fp.formatDate(
								dayElem.dateObj,
								'Y-m-d'
							);
							const dayNum = dayElem.dateObj.getDate();
							dayElem.innerHTML = '';
							const num = document.createElement( 'span' );
							num.className = 'mhbo-day-number';
							num.textContent = dayNum;
							dayElem.appendChild( num );

							const today = new Date();
							today.setHours( 0, 0, 0, 0 );

							// Half-day styling for checkin/checkout changeover dates
							const changeover = changeoverData[ date ];
							if (
								changeover &&
								dayElem.dateObj >= today
							) {
								dayElem.classList.add(
									'mhbo-half-booked-' + changeover
								);

								if ( changeover === 'both' ) {
									const coStatus = checkoutStatusData[ date ] || 'confirmed';
									const ciStatus = bookingStatusData[ date ] || 'confirmed';
									dayElem.classList.add( 'mhbo-checkout-' + coStatus );
									dayElem.classList.add( 'mhbo-checkin-' + ciStatus );
									dayElem.classList.add( 'mhbo-booking-' + ciStatus );
									if ( rawCheckoutStatusData[ date ] ) {
										dayElem.classList.add( 'mhbo-checkout-' + rawCheckoutStatusData[ date ] );
									}
									if ( rawBookingStatusData[ date ] ) {
										dayElem.classList.add( 'mhbo-booking-' + rawBookingStatusData[ date ] );
									}
								} else if ( changeover === 'checkout' ) {
									const coStatus = checkoutStatusData[ date ] || bookingStatusData[ date ] || 'confirmed';
									dayElem.classList.add( 'mhbo-booking-' + coStatus );
									dayElem.classList.add( 'mhbo-checkout-' + coStatus );
									if ( rawCheckoutStatusData[ date ] ) {
										dayElem.classList.add( 'mhbo-booking-' + rawCheckoutStatusData[ date ] );
									}
								} else if ( changeover === 'checkin' ) {
									const ciStatus = bookingStatusData[ date ] || 'confirmed';
									dayElem.classList.add( 'mhbo-booking-' + ciStatus );
									dayElem.classList.add( 'mhbo-checkin-' + ciStatus );
									if ( rawBookingStatusData[ date ] ) {
										dayElem.classList.add( 'mhbo-booking-' + rawBookingStatusData[ date ] );
									}
								}
							}

							// Apply booking status CSS class for full booked days
							if (
								! changeover &&
								bookingStatusData[ date ] &&
								( dayElem.classList.contains(
									'flatpickr-disabled'
								) ||
									dayElem.classList.contains(
										'mhbo-unbookable'
									) )
							) {
								if ( dayElem.dateObj >= today ) {
									dayElem.classList.add(
										'mhbo-booking-' +
											bookingStatusData[ date ]
									);
									if ( rawBookingStatusData[ date ] ) {
										dayElem.classList.add(
											'mhbo-booking-' +
												rawBookingStatusData[ date ]
										);
									}
								}
							}

							// Accessible state annotation (WCAG 2.2 AA)
							const baseAria =
								dayElem.getAttribute( 'aria-label' ) || date;
							let ariaDesc = '';
							if ( changeover === 'both' ) {
								ariaDesc = ', Check-out & Check-in Day';
							} else if ( changeover === 'checkout' ) {
								ariaDesc = ', Check-out Only';
							} else if ( changeover === 'checkin' ) {
								ariaDesc = ', Check-in Only';
							} else if ( bookingStatusData[ date ] ) {
								ariaDesc =
									bookingStatusData[ date ] === 'pending'
										? ', Provisional / Pending'
										: ', Booked';
							}
							if ( ariaDesc ) {
								dayElem.setAttribute(
									'aria-label',
									baseAria + ariaDesc
								);
							}

							// Price rendering
							if (
								showPrice &&
								priceData[ date ] &&
								! rawDisabledDates.includes( date ) &&
								dayElem.dateObj >= today
							) {
								const pos =
									( mhbo_calendar.settings &&
										mhbo_calendar.settings.currency_pos ) ||
									'before';
								const symbol =
									mhbo_calendar.settings.currency_symbol;
								const priceVal = priceData[ date ].price;
								const decimals =
									typeof mhbo_calendar.settings
										.currency_decimals !== 'undefined'
										? parseInt(
												mhbo_calendar.settings
													.currency_decimals,
												10
										  )
										: 0;

								const formattedPrice =
									parseFloat( priceVal ).toFixed( decimals );

								const priceTag =
									document.createElement( 'span' );
								priceTag.className = 'mhbo-fp-price';
								const spanCurrency =
									'<span class="mhbo-price-part-currency">' +
									symbol +
									'</span>';
								const spanAmount =
									'<span class="mhbo-price-part-amount">' +
									formattedPrice +
									'</span>';

								if ( pos === 'before' ) {
									priceTag.innerHTML =
										spanCurrency + ' ' + spanAmount;
								} else {
									priceTag.innerHTML =
										spanAmount + ' ' + spanCurrency;
								}

								dayElem.appendChild( priceTag );
								dayElem.classList.add( 'has-price' );
							}
						},
					} );

					wrapper._mhboPicker = picker;

					// --- INTERACTIVE PRICE-ON-HOVER (2026 BP) ---
					wrapper.addEventListener( 'mousemove', function ( e ) {
						const dayElem = e.target.closest( '.flatpickr-day' );
						if ( ! dayElem ) {
							return;
						}

if ( ! picker || picker.selectedDates.length !== 1 ) {
							tooltip.classList.remove( 'mhbo-visible' );
							wrapper
								.querySelectorAll( '.mhbo-range-hover' )
								.forEach( ( el ) =>
									el.classList.remove( 'mhbo-range-hover' )
								);
							return;
						}

						const hoverDate = dayElem.dateObj;
						if (
							! hoverDate ||
							hoverDate < picker.selectedDates[ 0 ] ||
							dayElem.classList.contains( 'flatpickr-disabled' )
						) {
							tooltip.classList.remove( 'mhbo-visible' );
							wrapper
								.querySelectorAll( '.mhbo-range-hover' )
								.forEach( ( el ) =>
									el.classList.remove( 'mhbo-range-hover' )
								);
							return;
						}

						const start = picker.selectedDates[ 0 ];
						const end = hoverDate;

						if ( start.getTime() === end.getTime() ) {
							tooltip.classList.remove( 'mhbo-visible' );
							wrapper
								.querySelectorAll( '.mhbo-range-hover' )
								.forEach( ( el ) =>
									el.classList.remove( 'mhbo-range-hover' )
								);
							return;
						}

						let total = 0;
						let nights = 0;
						const d = new Date( start );

						wrapper
							.querySelectorAll( '.mhbo-range-hover' )
							.forEach( ( el ) =>
								el.classList.remove( 'mhbo-range-hover' )
							);

						while ( d < end ) {
							const dStr = picker.formatDate( d, 'Y-m-d' );
							if ( priceData[ dStr ] ) {
								total += parseFloat( priceData[ dStr ].price );
							}

							const matchedDay =
								picker.daysContainer.querySelector(
									`[aria-label="${ picker.formatDate(
										d,
										picker.config.ariaDateFormat
									) }"]`
								);
							if ( matchedDay ) {
								matchedDay.classList.add( 'mhbo-range-hover' );
							}

							nights++;
							d.setDate( d.getDate() + 1 );
						}

						const formattedTotal = formatCurrency( total );
						const nightsLabel =
							nights === 1
								? mhbo_calendar.i18n.night || 'Night'
								: mhbo_calendar.i18n.nights || 'Nights';
						const nightsFull = nights + ' ' + nightsLabel;

						const nSpan = tooltip.querySelector(
							'.mhbo-tooltip-nights'
						);
						if ( nSpan ) {
							nSpan.textContent = nightsFull;
						}

						const pSpan = tooltip.querySelector(
							'.mhbo-tooltip-price'
						);
						if ( pSpan ) {
							if ( showPrice && total > 0 ) {
								pSpan.textContent = formattedTotal;
								pSpan.style.display = '';
							} else {
								pSpan.style.display = 'none';
							}
						}

tooltip.style.left = e.clientX + 'px';
						tooltip.style.top = e.clientY + 'px';
						tooltip.classList.add( 'mhbo-visible' );
					} );

					wrapper.addEventListener( 'mouseleave', function () {
						tooltip.classList.remove(
							'mhbo-visible',
							'mhbo-tooltip-warn'
						);
						const cSpan = tooltip.querySelector(
							'.mhbo-tooltip-constraint'
						);
						if ( cSpan ) {
							cSpan.style.display = 'none';
						}
						wrapper
							.querySelectorAll( '.mhbo-range-hover' )
							.forEach( ( el ) =>
								el.classList.remove( 'mhbo-range-hover' )
							);
						wrapper.classList.remove(
							'mhbo-hovering-valid-checkout'
						);
					} );
				}

				function updateBookingForm( dates ) {
					const start = dates[ 0 ];
					const end = dates[ 1 ];
					const startStr = picker.formatDate( start, 'Y-m-d' );
					const endStr = picker.formatDate( end, 'Y-m-d' );

					const inInput =
						wrapper.querySelector( '.mhbo-cal-check-in' );
					if ( inInput ) {
						inInput.value = startStr;
					}
					const outInput = wrapper.querySelector(
						'.mhbo-cal-check-out'
					);
					if ( outInput ) {
						outInput.value = endStr;
					}
					const inDisp = wrapper.querySelector(
						'.mhbo-display-check-in'
					);
					if ( inDisp ) {
						inDisp.textContent = startStr;
					}
					const outDisp = wrapper.querySelector(
						'.mhbo-display-check-out'
					);
					if ( outDisp ) {
						outDisp.textContent = endStr;
					}

					// CROSS-SYNC: If on the booking form page, sync main form inputs too
					const mainForm =
						document.getElementById( 'mhbo-booking-form' );
					if ( mainForm ) {
						const mainIn = mainForm.querySelector(
							'input[name="check_in"]'
						);
						if ( mainIn ) {
							mainIn.value = startStr;
							mainIn.dispatchEvent(
								new Event( 'change', { bubbles: true } )
							);
						}
						const mainOut = mainForm.querySelector(
							'input[name="check_out"]'
						);
						if ( mainOut ) {
							mainOut.value = endStr;
							mainOut.dispatchEvent(
								new Event( 'change', { bubbles: true } )
							);
						}
					}

					// Update summary box labels
					if ( selectionBox ) {
						const headerH3 = selectionBox.querySelector(
							'.mhbo-selection-header h3'
						);
						if ( headerH3 ) {
							headerH3.textContent =
								mhbo_calendar.i18n.your_selection ||
								'Your Selection';
						}
						const dateLabels = selectionBox.querySelectorAll(
							'.mhbo-selection-dates .mhbo-label'
						);
						if ( dateLabels.length >= 2 ) {
							dateLabels[ 0 ].textContent =
								mhbo_calendar.i18n.check_in || 'Check-in';
							dateLabels[ 1 ].textContent =
								mhbo_calendar.i18n.check_out || 'Check-out';
						}
					}

					// Update button text
					const submitBtn = wrapper.querySelector(
						'.mhbo-booking-btn-submit'
					);
					if ( submitBtn && mhbo_calendar.i18n.continue_booking ) {
						submitBtn.textContent =
							mhbo_calendar.i18n.continue_booking;
					}

					// Update pricing summary
					if ( showPrice ) {
						if ( selectionBox ) {
							const priceLabel = selectionBox.querySelector(
								'.mhbo-selection-price .mhbo-label'
							);
							if ( priceLabel ) {
								priceLabel.textContent =
									mhbo_calendar.i18n.total || 'Total';
							}
						}
						calculateTotalPrice( start, end );
					}

					if ( guide ) {
						guide.textContent =
							mhbo_calendar.i18n.dates_selected ||
							'Dates selected. Complete the form below.';
					}
					if ( selectionBox ) {
						selectionBox.style.display = '';
						selectionBox.classList.add( 'mhbo-visible' );
					}
				}

				function calculateTotalPrice( start, end ) {
					let total = 0;
					const d = new Date( start );
					while ( d < end ) {
						const dateStr = picker.formatDate( d, 'Y-m-d' );
						if ( priceData[ dateStr ] ) {
							total += parseFloat( priceData[ dateStr ].price );
						}
						d.setDate( d.getDate() + 1 );
					}
					const formatted = formatCurrency( total );
					const dispPrice = wrapper.querySelector(
						'.mhbo-display-price'
					);
					if ( dispPrice ) {
						dispPrice.textContent = formatted;
					}
					const totalPriceInput = wrapper.querySelector(
						'.mhbo-cal-total-price'
					);
					if ( totalPriceInput ) {
						totalPriceInput.value = total;
					}

					const mainForm =
						document.getElementById( 'mhbo-booking-form' );
					if ( mainForm ) {
						const mainTotal = mainForm.querySelector(
							'input[name="total_price"]'
						);
						if ( mainTotal ) {
							mainTotal.value = total;
						}
					}
				}

				function formatCurrency( amount ) {
					const pos =
						( mhbo_calendar.settings &&
							mhbo_calendar.settings.currency_pos ) ||
						'before';
					const symbol = mhbo_calendar.settings.currency_symbol;
					const decimals =
						typeof mhbo_calendar.settings.currency_decimals !==
						'undefined'
							? parseInt(
									mhbo_calendar.settings.currency_decimals,
									10
							  )
							: 2;
					const formatted = amount.toFixed( decimals );
					return pos === 'before'
						? symbol + ' ' + formatted
						: formatted + ' ' + symbol;
				}

				wrapper._mhboErrorHandler = showInlineError;
				initFlatpickr();
			} );
	}

	// Capture button click globally with delegation (Vanilla JS)
	if ( ! document.documentElement.dataset.mhboClickAttached ) {
		document.addEventListener( 'click', function ( e ) {
			const btn = e.target.closest( '.mhbo-booking-btn-submit' );
			if ( ! btn ) {
				return;
			}
			e.preventDefault();

			const wrapper = btn.closest( '.mhbo-calendar-container' );
			if ( ! wrapper ) {
				return;
			}

			const form = wrapper.querySelector( '.mhbo-selection-form' );
			const roomId = wrapper.dataset.roomId;
			const action = form ? form.getAttribute( 'action' ) : '';
			const showError = wrapper._mhboErrorHandler || alert;

			const picker = wrapper._mhboPicker;
			let checkIn, checkOut, totalPrice;

			if ( picker && picker.selectedDates.length === 2 ) {
				checkIn = picker.formatDate(
					picker.selectedDates[ 0 ],
					'Y-m-d'
				);
				checkOut = picker.formatDate(
					picker.selectedDates[ 1 ],
					'Y-m-d'
				);
				const tInput = wrapper.querySelector( '.mhbo-cal-total-price' );
				totalPrice = tInput ? tInput.value : '0';
			} else {
				const inInput = wrapper.querySelector( '.mhbo-cal-check-in' );
				const outInput = wrapper.querySelector( '.mhbo-cal-check-out' );
				const tInput = wrapper.querySelector( '.mhbo-cal-total-price' );
				checkIn = inInput ? inInput.value : '';
				checkOut = outInput ? outInput.value : '';
				totalPrice = tInput ? tInput.value : '0';
			}

			if ( ! checkIn || ! checkOut ) {
				showError(
					mhbo_calendar.i18n.select_dates_error ||
						'Please select check-in and check-out dates.'
				);
				return;
			}

			const showPrice = String( wrapper.dataset.showPrice ) === '1';
			const hasRoom = roomId && parseInt( roomId, 10 ) > 0;

			if ( showPrice && hasRoom && parseFloat( totalPrice ) <= 0 ) {
				showError(
					mhbo_calendar.i18n.invalid_price ||
						'Bookings cannot be placed because no valid price has been set for these dates.'
				);
				return;
			}

// Modal mode: dispatch custom event
			const modalMode =
				wrapper.dataset.modalMode === '1' ||
				wrapper.dataset.modalMode === 'true';
			if ( modalMode && typeof window.MhboModal !== 'undefined' ) {
				document.dispatchEvent(
					new CustomEvent( 'mhboBookNow', {
						detail: {
							room_id: roomId,
							check_in: checkIn,
							check_out: checkOut,
							total_price: totalPrice,
						},
					} )
				);
				return;
			}

			// Sync values to form inputs before submitting
			if ( form ) {
				const inHidden = form.querySelector( 'input[name="check_in"]' );
				if ( inHidden ) {
					inHidden.value = checkIn;
				}
				const outHidden = form.querySelector(
					'input[name="check_out"]'
				);
				if ( outHidden ) {
					outHidden.value = checkOut;
				}
				const totalHidden = form.querySelector(
					'input[name="total_price"]'
				);
				if ( totalHidden ) {
					totalHidden.value = totalPrice;
				}

				form.submit();
			} else {
				let finalUrl;
				try {
					const base = window.location.origin;
					const url =
						action && action !== '#' && action !== ''
							? new URL( action, base )
							: new URL( window.location.href );

					url.searchParams.set( 'room_id', roomId );
					url.searchParams.set( 'check_in', checkIn );
					url.searchParams.set( 'check_out', checkOut );
					url.searchParams.set( 'total_price', totalPrice );
					url.searchParams.set( 'mhbo_auto_book', '1' );

					const autoNonce =
						( typeof mhbo_calendar !== 'undefined' &&
							mhbo_calendar.auto_nonce ) ||
						'';
					if ( autoNonce ) {
						url.searchParams.set( 'mhbo_nonce', autoNonce );
					}

					finalUrl = url.toString();
				} catch ( err ) {
					debugLog( 'Redirect error:', err );
					const sep =
						action && action.indexOf( '?' ) !== -1 ? '&' : '?';
					const baseUrl =
						action && action !== '#' && action !== ''
							? action
							: window.location.href.split( '?' )[ 0 ];
					finalUrl =
						baseUrl +
						sep +
						'room_id=' +
						roomId +
						'&check_in=' +
						checkIn +
						'&check_out=' +
						checkOut +
						'&total_price=' +
						totalPrice +
						'&mhbo_auto_book=1' +
						( autoNonce
							? '&mhbo_nonce=' + encodeURIComponent( autoNonce )
							: '' );
				}

				window.location.href = finalUrl;
			}
		} );
		document.documentElement.dataset.mhboClickAttached = 'true';
	}

	// Bootstrap when DOM is ready
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAllCalendars );
	} else {
		initAllCalendars();
	}
} )();
