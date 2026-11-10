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
				const roomStatusesData = {}; // Track per-room occupancy status for dynamic candidate room styling
				const roomMinStaysData = {}; // Track per-room minimum stay overrides per check-in date
				const roomMaxStaysData = {}; // Track per-room maximum stay overrides per check-in date
				const roomCtaData = {}; // Track per-room Closed to Arrival overrides per date
				const roomCtdData = {}; // Track per-room Closed to Departure overrides per date
				let activeCandidateOverlay = {};
				const minStayData = {}; // Track minimum stay per check-in date (Free/Starter/Pro)
				const maxStayData = {}; // Track maximum stay per check-in date (Free/Starter/Pro)
				const reasonData = {}; // Track block reason per disabled date (booked/manual/maintenance/turnover/cta)
				const minStayReasonDates = new Set(); // Track dates whose reasonData came from dynamic minStay pre-filtering
				const minStayStatusDates = new Set(); // Track dates whose bookingStatusData came from dynamic minStay pre-filtering
				const ctaRestrictedDates = new Set(); // Track available nights restricted to arrival (CTA)
				const rawChangeoverData = {}; // Preserve server changeover status before dead-gap suppression
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
					for ( const key in roomStatusesData ) {
						delete roomStatusesData[ key ];
					}
					for ( const key in roomMinStaysData ) {
						delete roomMinStaysData[ key ];
					}
					for ( const key in roomMaxStaysData ) {
						delete roomMaxStaysData[ key ];
					}
					for ( const key in roomCtaData ) {
						delete roomCtaData[ key ];
					}
					for ( const key in roomCtdData ) {
						delete roomCtdData[ key ];
					}
					for ( const key in rawChangeoverData ) {
						delete rawChangeoverData[ key ];
					}
					for ( const key in minStayData ) {
						delete minStayData[ key ];
					}
					for ( const key in maxStayData ) {
						delete maxStayData[ key ];
					}
					for ( const key in reasonData ) {
						delete reasonData[ key ];
					}
					minStayReasonDates.clear();
					minStayStatusDates.clear();
					ctaRestrictedDates.clear();
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

				function formatLocalDate( dateObj ) {
					const y = dateObj.getFullYear();
					const m = String( dateObj.getMonth() + 1 ).padStart(
						2,
						'0'
					);
					const d = String( dateObj.getDate() ).padStart( 2, '0' );
					return `${ y }-${ m }-${ d }`;
				}

				function getRoomMinStay( checkInStr, rid ) {
					if (
						roomMinStaysData[ checkInStr ] &&
						roomMinStaysData[ checkInStr ][ rid ]
					) {
						return parseInt(
							roomMinStaysData[ checkInStr ][ rid ],
							10
						);
					}
					return minStayData[ checkInStr ] || 1;
				}

				function getRoomMaxStay( nightStr, rid ) {
					if (
						roomMaxStaysData[ nightStr ] &&
						roomMaxStaysData[ nightStr ][ rid ]
					) {
						return parseInt(
							roomMaxStaysData[ nightStr ][ rid ],
							10
						);
					}
					return maxStayData[ nightStr ] || null;
				}

				function getStrictestSingleMaxStay( checkInStr, nights ) {
					const span = Math.max( 1, nights || 1 );
					const parts = checkInStr.split( '-' ).map( Number );
					const baseDate = new Date(
						parts[ 0 ],
						parts[ 1 ] - 1,
						parts[ 2 ]
					);
					let strictest = null;
					for ( let j = 0; j < span; j++ ) {
						const curDate = new Date(
							baseDate.getFullYear(),
							baseDate.getMonth(),
							baseDate.getDate() + j
						);
						const curStr = formatLocalDate( curDate );
						const dayMax = maxStayData[ curStr ] || null;
						if (
							dayMax &&
							( strictest === null || dayMax < strictest )
						) {
							strictest = dayMax;
						}
					}
					return strictest;
				}

				function getRoomStrictestMaxStay( checkInStr, nights, rid ) {
					const span = Math.max( 1, nights || 1 );
					const parts = checkInStr.split( '-' ).map( Number );
					const baseDate = new Date(
						parts[ 0 ],
						parts[ 1 ] - 1,
						parts[ 2 ]
					);
					let strictest = null;
					for ( let j = 0; j < span; j++ ) {
						const curDate = new Date(
							baseDate.getFullYear(),
							baseDate.getMonth(),
							baseDate.getDate() + j
						);
						const curStr = formatLocalDate( curDate );
						const dayMax = getRoomMaxStay( curStr, rid );
						if (
							dayMax &&
							( strictest === null || dayMax < strictest )
						) {
							strictest = dayMax;
						}
					}
					return strictest;
				}

				function evaluateAggregatedStay( checkInStr, nights ) {
					const rawStartRooms = freeRoomsData[ checkInStr ] || [];
					const startRooms = rawStartRooms.filter(
						( rid ) =>
							! (
								roomCtaData[ checkInStr ] &&
								roomCtaData[ checkInStr ][ rid ]
							)
					);
					if ( startRooms.length === 0 || nights <= 0 ) {
						return {
							continuousRooms: [],
							departableRooms: [],
							validRooms: [],
							ctdBlocked: false,
							minRequired: minStayData[ checkInStr ] || null,
							maxAllowed: getStrictestSingleMaxStay(
								checkInStr,
								nights
							),
						};
					}

					const parts = checkInStr.split( '-' ).map( Number );
					const checkInDate = new Date(
						parts[ 0 ],
						parts[ 1 ] - 1,
						parts[ 2 ]
					);
					let continuousRooms = [ ...startRooms ];

					for ( let j = 1; j < nights; j++ ) {
						const nextNightDate = new Date(
							checkInDate.getFullYear(),
							checkInDate.getMonth(),
							checkInDate.getDate() + j
						);
						const nextNightStr = formatLocalDate( nextNightDate );
						if ( ! ( nextNightStr in freeRoomsData ) ) {
							if ( isHydrated ) {
								continuousRooms = [];
							}
							break;
						}
						const nextFree = freeRoomsData[ nextNightStr ] || [];
						continuousRooms = continuousRooms.filter( ( r ) =>
							nextFree.includes( r )
						);
						if ( continuousRooms.length === 0 ) {
							break;
						}
					}

					const checkOutDate = new Date(
						checkInDate.getFullYear(),
						checkInDate.getMonth(),
						checkInDate.getDate() + nights
					);
					const checkOutStr = formatLocalDate( checkOutDate );
					const departableRooms = continuousRooms.filter(
						( rid ) =>
							! (
								roomCtdData[ checkOutStr ] &&
								roomCtdData[ checkOutStr ][ rid ]
							)
					);
					const ctdBlocked =
						continuousRooms.length > 0 &&
						departableRooms.length === 0;

					const validRooms = [];
					const minReqs = [];
					const maxAlls = [];

					departableRooms.forEach( ( rid ) => {
						const rMin = getRoomMinStay( checkInStr, rid );
						const rMax = getRoomStrictestMaxStay(
							checkInStr,
							nights,
							rid
						);
						minReqs.push( rMin );
						if ( rMax ) {
							maxAlls.push( rMax );
						}
						if (
							nights >= rMin &&
							( ! rMax || nights <= rMax )
						) {
							validRooms.push( rid );
						}
					} );

					return {
						continuousRooms,
						departableRooms,
						validRooms,
						ctdBlocked,
						minRequired:
							minReqs.length > 0 ? Math.min( ...minReqs ) : null,
						maxAllowed:
							maxAlls.length > 0 ? Math.max( ...maxAlls ) : null,
					};
				}

				function processCalendarData( data ) {
					// Reset dynamic min-stay mutations before re-evaluating (prevents hydration boundary artifacts at Day 118-120)
					disabledDates.length = 0;
					rawDisabledDates.forEach( ( d ) => disabledDates.push( d ) );
					minStayReasonDates.forEach( ( d ) => {
						delete reasonData[ d ];
					} );
					minStayReasonDates.clear();
					minStayStatusDates.forEach( ( d ) => {
						delete bookingStatusData[ d ];
						if ( rawChangeoverData[ d ] ) {
							changeoverData[ d ] = rawChangeoverData[ d ];
						}
					} );
					minStayStatusDates.clear();
					ctaRestrictedDates.clear();

					data.forEach( ( item ) => {
						if (
							item.status === 'booked' ||
							item.status === 'unbookable' ||
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
						} else {
							delete bookingStatusData[ item.date ];
						}
						if ( item.raw_booking_status ) {
							rawBookingStatusData[ item.date ] = String( item.raw_booking_status ).toLowerCase();
						} else if ( item.booking_status ) {
							rawBookingStatusData[ item.date ] = String( item.booking_status ).toLowerCase();
						} else {
							delete rawBookingStatusData[ item.date ];
						}

						const normCheckoutStatus = normalizeBookingStatus( item.checkout_booking_status );
						if ( normCheckoutStatus ) {
							checkoutStatusData[ item.date ] = normCheckoutStatus;
						} else {
							delete checkoutStatusData[ item.date ];
						}
						if ( item.raw_checkout_booking_status ) {
							rawCheckoutStatusData[ item.date ] = String( item.raw_checkout_booking_status ).toLowerCase();
						} else if ( item.checkout_booking_status ) {
							rawCheckoutStatusData[ item.date ] = String( item.checkout_booking_status ).toLowerCase();
						} else {
							delete rawCheckoutStatusData[ item.date ];
						}

						// Determine changeover status for split-day styling
						if ( item.is_checkin && item.is_checkout ) {
							changeoverData[ item.date ] = 'both';
							rawChangeoverData[ item.date ] = 'both';
						} else if ( item.is_checkin ) {
							changeoverData[ item.date ] = 'checkin';
							rawChangeoverData[ item.date ] = 'checkin';
						} else if ( item.is_checkout ) {
							changeoverData[ item.date ] = 'checkout';
							rawChangeoverData[ item.date ] = 'checkout';
						} else {
							delete changeoverData[ item.date ];
							delete rawChangeoverData[ item.date ];
						}

						// Store eligibility for selection guards
						eligibilityData[ item.date ] = {
							checkin: item.can_checkin !== false,
							checkout: item.can_checkout !== false,
							isCtd: Boolean(
								item.is_ctd ||
									( item.room_ctd &&
										Object.keys( item.room_ctd ).length >
											0 )
							),
						};

						if ( Array.isArray( item.free_rooms ) ) {
							freeRoomsData[ item.date ] = item.free_rooms;
						}

						if (
							item.room_statuses &&
							typeof item.room_statuses === 'object' &&
							! Array.isArray( item.room_statuses )
						) {
							roomStatusesData[ item.date ] = item.room_statuses;
						} else {
							delete roomStatusesData[ item.date ];
						}

						if (
							item.room_min_stays &&
							typeof item.room_min_stays === 'object' &&
							! Array.isArray( item.room_min_stays )
						) {
							roomMinStaysData[ item.date ] = item.room_min_stays;
						} else {
							delete roomMinStaysData[ item.date ];
						}

						if (
							item.room_max_stays &&
							typeof item.room_max_stays === 'object' &&
							! Array.isArray( item.room_max_stays )
						) {
							roomMaxStaysData[ item.date ] = item.room_max_stays;
						} else {
							delete roomMaxStaysData[ item.date ];
						}

						if (
							item.room_cta &&
							typeof item.room_cta === 'object' &&
							! Array.isArray( item.room_cta )
						) {
							roomCtaData[ item.date ] = item.room_cta;
						} else {
							delete roomCtaData[ item.date ];
						}

						if (
							item.room_ctd &&
							typeof item.room_ctd === 'object' &&
							! Array.isArray( item.room_ctd )
						) {
							roomCtdData[ item.date ] = item.room_ctd;
						} else {
							delete roomCtdData[ item.date ];
						}

						// Store stay restrictions across all tiers (Free, Starter, Pro)
						if ( item.min_stay && parseInt( item.min_stay, 10 ) > 0 ) {
							minStayData[ item.date ] = parseInt(
								item.min_stay,
								10
							);
						} else {
							delete minStayData[ item.date ];
						}
						if ( item.max_stay && parseInt( item.max_stay, 10 ) > 0 ) {
							maxStayData[ item.date ] = parseInt(
								item.max_stay,
								10
							);
						} else {
							delete maxStayData[ item.date ];
						}
						if ( item.reason ) {
							reasonData[ item.date ] = item.reason;
						} else {
							delete reasonData[ item.date ];
						}
					} );

					// Pre-filter: Disable check-in dates that mathematically cannot satisfy minStay/CTA/CTD requirements.
					const isAggregated =
						Object.keys( freeRoomsData ).length > 0;
					const todayNow = new Date();
					todayNow.setHours( 0, 0, 0, 0 );
					const todayStr = formatLocalDate( todayNow );

					const invalidCheckIns = [];
					const validCheckIns = [];

					Object.keys( eligibilityData ).forEach( ( checkInStr ) => {
						// Past or already blocked dates cannot be used as check-ins or lookback coverage anchors
						if (
							checkInStr < todayStr ||
							rawDisabledDates.includes( checkInStr )
						) {
							return;
						}

						// Available nights restricted to arrival (CTA) cannot start a stay,
						// but CAN be stayed through when covered by an earlier valid check-in.
						if ( ! eligibilityData[ checkInStr ].checkin ) {
							invalidCheckIns.push( checkInStr );
							ctaRestrictedDates.add( checkInStr );
							return;
						}

						const parts = checkInStr.split( '-' ).map( Number );
						const checkInDate = new Date(
							parts[ 0 ],
							parts[ 1 ] - 1,
							parts[ 2 ]
						);

						if ( isAggregated ) {
							const rawStartRooms =
								freeRoomsData[ checkInStr ] || [];
							const startRooms = rawStartRooms.filter(
								( rid ) =>
									! (
										roomCtaData[ checkInStr ] &&
										roomCtaData[ checkInStr ][ rid ]
									)
							);
							if ( startRooms.length === 0 ) {
								invalidCheckIns.push( checkInStr );
								ctaRestrictedDates.add( checkInStr );
								return;
							}

							const achievableMins = [];
							startRooms.forEach( ( rid ) => {
								const reqMin = Math.max(
									1,
									getRoomMinStay( checkInStr, rid )
								);
								const maxSearch = reqMin + 14;
								let foundMinForRoom = null;

								for ( let j = 0; j < maxSearch; j++ ) {
									const nextNightDate = new Date(
										checkInDate.getFullYear(),
										checkInDate.getMonth(),
										checkInDate.getDate() + j
									);
									const nextNightStr =
										formatLocalDate( nextNightDate );
									if ( ! ( nextNightStr in freeRoomsData ) ) {
										if ( ! isHydrated && j + 1 >= reqMin ) {
											foundMinForRoom = j + 1;
										}
										break;
									}
									const nextFree =
										freeRoomsData[ nextNightStr ] || [];
									if ( ! nextFree.includes( rid ) ) {
										break;
									}

									const candidateNights = j + 1;
									const rStrictMax = getRoomStrictestMaxStay(
										checkInStr,
										candidateNights,
										rid
									);
									if (
										rStrictMax &&
										candidateNights > rStrictMax
									) {
										break;
									}

									if ( candidateNights >= reqMin ) {
										const coDate = new Date(
											checkInDate.getFullYear(),
											checkInDate.getMonth(),
											checkInDate.getDate() +
												candidateNights
										);
										const coDateStr =
											formatLocalDate( coDate );
										const dayCoOk =
											! eligibilityData[ coDateStr ] ||
											eligibilityData[ coDateStr ]
												.checkout !== false;
										const roomCoOk = ! (
											roomCtdData[ coDateStr ] &&
											roomCtdData[ coDateStr ][ rid ]
										);
										if ( dayCoOk && roomCoOk ) {
											foundMinForRoom = candidateNights;
											break;
										}
									}
								}

								if ( foundMinForRoom !== null ) {
									achievableMins.push( foundMinForRoom );
								}
							} );

							if ( achievableMins.length === 0 ) {
								invalidCheckIns.push( checkInStr );
							} else {
								validCheckIns.push( checkInStr );
								const minAchievable = Math.min(
									...achievableMins
								);
								if (
									minAchievable > 1 ||
									minStayData[ checkInStr ]
								) {
									minStayData[ checkInStr ] = minAchievable;
								}
							}
						} else {
							const minRequired = Math.max(
								1,
								minStayData[ checkInStr ] || 1
							);
							const maxSearch = minRequired + 14;
							let foundValidNights = null;

							for ( let j = 0; j < maxSearch; j++ ) {
								const nextNightDate = new Date(
									checkInDate.getFullYear(),
									checkInDate.getMonth(),
									checkInDate.getDate() + j
								);
								const nextNightStr =
									formatLocalDate( nextNightDate );
								if ( ! ( nextNightStr in eligibilityData ) ) {
									if (
										! isHydrated &&
										j + 1 >= minRequired
									) {
										foundValidNights = j + 1;
									}
									break;
								}
								if ( rawDisabledDates.includes( nextNightStr ) ) {
									break;
								}

								const candidateNights = j + 1;
								const strictMax = getStrictestSingleMaxStay(
									checkInStr,
									candidateNights
								);
								if (
									strictMax &&
									candidateNights > strictMax
								) {
									break;
								}

								if ( candidateNights >= minRequired ) {
									const coDate = new Date(
										checkInDate.getFullYear(),
										checkInDate.getMonth(),
										checkInDate.getDate() + candidateNights
									);
									const coDateStr = formatLocalDate( coDate );
									if (
										! eligibilityData[ coDateStr ] ||
										eligibilityData[ coDateStr ]
											.checkout !== false
									) {
										foundValidNights = candidateNights;
										break;
									}
								}
							}

							if ( foundValidNights === null ) {
								invalidCheckIns.push( checkInStr );
							} else {
								validCheckIns.push( checkInStr );
								if ( foundValidNights > minRequired ) {
									minStayData[ checkInStr ] =
										foundValidNights;
								}
							}
						}
					} );

					// Determine which invalid check-ins are COMPLETELY DEAD (cannot be covered by any prior valid check-in >= today)
					const maxLookback = Math.min( 365, getMaxAdvanceDays() );
					invalidCheckIns.forEach( ( checkInStr ) => {
						let isCovered = false;
						const parts = checkInStr.split( '-' ).map( Number );
						const checkInDate = new Date(
							parts[ 0 ],
							parts[ 1 ] - 1,
							parts[ 2 ]
						);

						for ( let i = 1; i <= maxLookback; i++ ) {
							const prevDate = new Date(
								checkInDate.getFullYear(),
								checkInDate.getMonth(),
								checkInDate.getDate() - i
							);
							const prevDateStr = formatLocalDate( prevDate );

							if (
								prevDateStr < todayStr ||
								rawDisabledDates.includes( prevDateStr )
							) {
								break;
							}

							if ( validCheckIns.includes( prevDateStr ) ) {
								const neededNights = i + 1;
								const targetNights = Math.max(
									neededNights,
									minStayData[ prevDateStr ] || 1
								);
								if ( isAggregated ) {
									const evalRes = evaluateAggregatedStay(
										prevDateStr,
										targetNights
									);
									if ( evalRes.validRooms.length > 0 ) {
										isCovered = true;
										break;
									}
								} else {
									const prevMax = getStrictestSingleMaxStay(
										prevDateStr,
										targetNights
									);
									const coCheck = new Date(
										prevDate.getFullYear(),
										prevDate.getMonth(),
										prevDate.getDate() + targetNights
									);
									const coCheckStr =
										formatLocalDate( coCheck );
									const coOk =
										! eligibilityData[ coCheckStr ] ||
										eligibilityData[ coCheckStr ]
											.checkout !== false;
									if (
										coOk &&
										( ! prevMax || targetNights <= prevMax )
									) {
										isCovered = true;
										break;
									}
								}
							}
						}

						if ( ctaRestrictedDates.has( checkInStr ) ) {
							reasonData[ checkInStr ] =
								mhbo_calendar.i18n.reason_cta ||
								mhbo_calendar.i18n.api_err_cta ||
								'Closed to Arrival';
						} else {
							const minRequired = minStayData[ checkInStr ] || 2;
							const baseReason =
								mhbo_calendar.i18n.api_err_min_stay ||
								'Minimum stay is %d nights.';
							reasonData[ checkInStr ] = baseReason
								.replace( '%d', minRequired )
								.replace( '%1$d', minRequired );
						}
						minStayReasonDates.add( checkInStr );

						if ( ! isCovered ) {
							if ( ! disabledDates.includes( checkInStr ) ) {
								disabledDates.push( checkInStr );
							}
							if ( ! bookingStatusData[ checkInStr ] ) {
								bookingStatusData[ checkInStr ] = 'confirmed';
								minStayStatusDates.add( checkInStr );
							}
							// Suppress misleading afternoon-free triangle on a completely unbookable dead gap
							if ( changeoverData[ checkInStr ] === 'checkout' ) {
								delete changeoverData[ checkInStr ];
								minStayStatusDates.add( checkInStr );
							}
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
									const isCtaBlock =
										ctaRestrictedDates.has(
											dateStrClicked
										) ||
										reasonData[ dateStrClicked ] === 'cta';
									showInlineError(
										isCtaBlock
											? mhbo_calendar.i18n.api_err_cta ||
													mhbo_calendar.i18n
														.reason_cta ||
													'Closed to Arrival'
											: mhbo_calendar.i18n
													.checkout_only_error ||
													'This date is restricted to check-outs only.'
									);
									instance.clear();
									return;
								}

								if (
									reasonData[ dateStrClicked ] &&
									! disabledDates.includes( dateStrClicked )
								) {
									showConstraintHint(
										reasonData[ dateStrClicked ]
									);
									instance.clear();
									return;
								}
							}

							// Same-date double-click: advance to earliest valid checkout (respecting minStay & checkout eligibility)
							if (
								selectedDates.length === 2 &&
								selectedDates[ 0 ].getTime() ===
									selectedDates[ 1 ].getTime()
							) {
								const sameStr = instance.formatDate(
									selectedDates[ 0 ],
									'Y-m-d'
								);
								const stepNights = Math.max(
									1,
									minStayData[ sameStr ] || 1
								);
								const targetOut = new Date(
									selectedDates[ 0 ].getFullYear(),
									selectedDates[ 0 ].getMonth(),
									selectedDates[ 0 ].getDate() + stepNights
								);
								const targetOutStr = instance.formatDate(
									targetOut,
									'Y-m-d'
								);

								let canAutoAdvance =
									! eligibilityData[ targetOutStr ] ||
									eligibilityData[ targetOutStr ].checkout !==
										false;

								if (
									canAutoAdvance &&
									Object.keys( freeRoomsData ).length > 0
								) {
									const evalSame = evaluateAggregatedStay(
										sameStr,
										stepNights
									);
									canAutoAdvance =
										evalSame.validRooms.length > 0;
								} else if ( canAutoAdvance ) {
									for ( let s = 0; s < stepNights; s++ ) {
										const mid = new Date(
											selectedDates[ 0 ].getFullYear(),
											selectedDates[ 0 ].getMonth(),
											selectedDates[ 0 ].getDate() + s
										);
										const midStr = instance.formatDate(
											mid,
											'Y-m-d'
										);
										if (
											rawDisabledDates.includes( midStr )
										) {
											canAutoAdvance = false;
											break;
										}
									}
								}

								if ( ! canAutoAdvance ) {
									instance.setDate(
										[ selectedDates[ 0 ] ],
										false
									);
									return;
								}

								selectedDates = [
									selectedDates[ 0 ],
									targetOut,
								];
								instance.setDate( selectedDates, false );
							}

							// 3. Guard: Min/Max Stay & Continuous Room Enforcement (Free, Starter & Pro)
							if ( selectedDates.length === 2 ) {
								const checkIn = selectedDates[ 0 ];
								const checkOut = selectedDates[ 1 ];
								const checkInStr = instance.formatDate(
									checkIn,
									'Y-m-d'
								);

								const nights = Math.round(
									( checkOut - checkIn ) /
										( 1000 * 60 * 60 * 24 )
								);
								let minRequired = minStayData[ checkInStr ];
								let maxAllowed = getStrictestSingleMaxStay(
									checkInStr,
									nights
								);

								if ( Object.keys( freeRoomsData ).length > 0 ) {
									const aggEval = evaluateAggregatedStay(
										checkInStr,
										nights
									);
									if ( aggEval.continuousRooms.length === 0 ) {
										showInlineError(
											mhbo_calendar.i18n
												.select_dates_error ||
												'No single room is continuously available for these dates.'
										);
										instance.setDate( [ checkIn ], false );
										return;
									}
									if ( aggEval.ctdBlocked ) {
										showInlineError(
											mhbo_calendar.i18n.api_err_ctd ||
												mhbo_calendar.i18n
													.checkin_only_error ||
												'Check-out is not permitted on the selected departure date (Closed to Departure).'
										);
										instance.setDate( [ checkIn ], false );
										return;
									}
									if ( aggEval.validRooms.length === 0 ) {
										if (
											aggEval.minRequired &&
											nights < aggEval.minRequired
										) {
											minRequired = aggEval.minRequired;
										} else if (
											aggEval.maxAllowed &&
											nights > aggEval.maxAllowed
										) {
											maxAllowed = aggEval.maxAllowed;
										}
									} else {
										minRequired = null;
										maxAllowed = null;
									}
								}

								if ( minRequired && nights < minRequired ) {
									const earliest = new Date( checkIn );
									earliest.setDate(
										earliest.getDate() + minRequired
									);
									const earliestStr =
										earliest.toLocaleDateString(
											document.documentElement.lang ||
												'en',
											{ month: 'short', day: 'numeric' }
										);
									const base =
										mhbo_calendar.i18n.api_err_min_stay ||
										'Minimum stay is %d nights.';
									const hint =
										mhbo_calendar.i18n.min_stay_hint ||
										'Please select %s or later as your check-out date.';
									const msg =
										base
											.replace( '%d', minRequired )
											.replace( '%1$d', minRequired ) +
										' ' +
										hint
											.replace( '%s', earliestStr )
											.replace( '%2$s', earliestStr );
									showConstraintHint( msg );
									instance.setDate( [ checkIn ], false );
									return;
								}

								if ( maxAllowed && nights > maxAllowed ) {
									const latest = new Date( checkIn );
									latest.setDate(
										latest.getDate() + maxAllowed
									);
									const latestStr = latest.toLocaleDateString(
										document.documentElement.lang || 'en',
										{ month: 'short', day: 'numeric' }
									);
									const base =
										mhbo_calendar.i18n.api_err_max_stay ||
										'Maximum stay is %d nights.';
									const hint =
										mhbo_calendar.i18n.max_stay_hint ||
										'Please select %s or earlier as your check-out date.';
									const msg =
										base
											.replace( '%d', maxAllowed )
											.replace( '%1$d', maxAllowed ) +
										' ' +
										hint
											.replace( '%s', latestStr )
											.replace( '%2$s', latestStr );
									showConstraintHint( msg );
									instance.setDate( [ checkIn ], false );
									return;
								}

								hideConstraintHint();
							}

							// 2. Guard: Check-Out Eligibility
							const effectiveCheckOutStr =
								selectedDates.length === 2
									? instance.formatDate(
											selectedDates[ 1 ],
											'Y-m-d'
									  )
									: dateStrClicked;
							if (
								selectedDates.length === 2 &&
								effectiveCheckOutStr &&
								eligibilityData[ effectiveCheckOutStr ]
							) {
								if (
									! eligibilityData[ effectiveCheckOutStr ]
										.checkout
								) {
									const isCtdCo = Boolean(
										eligibilityData[ effectiveCheckOutStr ]
											.isCtd
									);
									showInlineError(
										isCtdCo
											? mhbo_calendar.i18n.api_err_ctd ||
													mhbo_calendar.i18n
														.reason_ctd ||
													'Closed to Departure'
											: mhbo_calendar.i18n
													.checkin_only_error ||
													'This date is restricted to check-ins only.'
									);
									instance.setDate(
										[ selectedDates[ 0 ] ],
										false
									);
									return;
								}
							}

							function resolveCandidateState(
								checkInDate,
								fpInstance
							) {
								const checkInStr = fpInstance.formatDate(
									checkInDate,
									'Y-m-d'
								);
								let firstBookedAfter = null;
								const rawInitialFree =
									freeRoomsData[ checkInStr ] || null;
								let currentFreeRooms = Array.isArray(
									rawInitialFree
								)
									? rawInitialFree.filter(
											( r ) =>
												! (
													roomCtaData[ checkInStr ] &&
													roomCtaData[ checkInStr ][
														r
													]
												)
									  )
									: null;
								let candidateRooms =
									currentFreeRooms &&
									Array.isArray( currentFreeRooms ) &&
									currentFreeRooms.length > 0
										? [ ...currentFreeRooms ]
										: roomId && parseInt( roomId, 10 ) > 0
										? [ parseInt( roomId, 10 ) ]
										: null;

								const maxDays = getMaxAdvanceDays();
								for ( let i = 1; i <= maxDays; i++ ) {
									const nextDate = new Date(
										checkInDate.getFullYear(),
										checkInDate.getMonth(),
										checkInDate.getDate() + i
									);
									const nextDateStr = fpInstance.formatDate(
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
											candidateRooms = [
												...currentFreeRooms,
											];
											firstBookedAfter = nextDateStr;
											break;
										}
										currentFreeRooms = intersection;
										candidateRooms = [
											...currentFreeRooms,
										];
									}
								}

								activeCandidateOverlay = {};

								if (
									firstBookedAfter &&
									candidateRooms &&
									candidateRooms.length > 0 &&
									Object.keys( freeRoomsData ).length > 0
								) {
									const firstParts = firstBookedAfter
										.split( '-' )
										.map( Number );
									const baseBlockedDate = new Date(
										firstParts[ 0 ],
										firstParts[ 1 ] - 1,
										firstParts[ 2 ]
									);

									let ciHasPending = false;
									candidateRooms.forEach( ( rid ) => {
										const rInfo =
											roomStatusesData[
												firstBookedAfter
											] &&
											roomStatusesData[
												firstBookedAfter
											][ rid ];
										if (
											rInfo &&
											( rInfo.status === 'pending' ||
												rInfo.b_status === 'pending' ||
												rInfo.co_status === 'pending' )
										) {
											ciHasPending = true;
										}
									} );
									const firstStatus = ciHasPending
										? 'pending'
										: bookingStatusData[
												firstBookedAfter
										  ] || 'confirmed';
									activeCandidateOverlay[
										firstBookedAfter
									] = {
										changeover: 'checkin',
										bookingStatus: firstStatus,
										checkoutStatus: null,
										isBlocked: false,
									};

									for (
										let offset = 1;
										offset <= 60;
										offset++
									) {
										const curDate = new Date(
											baseBlockedDate.getFullYear(),
											baseBlockedDate.getMonth(),
											baseBlockedDate.getDate() + offset
										);
										const curDateStr =
											fpInstance.formatDate(
												curDate,
												'Y-m-d'
											);
										const dayMap =
											roomStatusesData[ curDateStr ];

										let allBookedPm = true;
										let hasPendingPm = false;
										let hasCheckoutAm = false;
										let hasPendingCo = false;

										candidateRooms.forEach( ( rid ) => {
											const rInfo = ( dayMap &&
												dayMap[ rid ] ) || {
												status: 'available',
											};
											if (
												rInfo.status === 'available'
											) {
												allBookedPm = false;
												if ( rInfo.is_checkout ) {
													hasCheckoutAm = true;
													if (
														rInfo.co_status ===
															'pending' ||
														rInfo.b_status ===
															'pending'
													) {
														hasPendingCo = true;
													}
												}
											} else if (
												rInfo.status === 'pending' ||
												rInfo.b_status === 'pending'
											) {
												hasPendingPm = true;
											}
										} );

										if ( allBookedPm ) {
											const bStat = hasPendingPm
												? 'pending'
												: 'confirmed';
											activeCandidateOverlay[
												curDateStr
											] = {
												changeover: null,
												bookingStatus: bStat,
												checkoutStatus: null,
												isBlocked: true,
											};
										} else {
											if ( hasCheckoutAm ) {
												const coStat =
													hasPendingCo || hasPendingPm
														? 'pending'
														: 'confirmed';
												activeCandidateOverlay[
													curDateStr
												] = {
													changeover: 'checkout',
													bookingStatus: coStat,
													checkoutStatus: coStat,
													isBlocked: false,
												};
											}
											break;
										}
									}
								}

								return {
									checkInStr,
									firstBookedAfter,
								};
							}

							// Dynamically allow next booked date as checkout date ONLY if morning checkout is eligible
							if ( selectedDates.length === 1 ) {
								pendingCheckIn = selectedDates[ 0 ];
								const { checkInStr, firstBookedAfter } =
									resolveCandidateState(
										selectedDates[ 0 ],
										instance
									);

								let newDisabled = [ ...disabledDates ];
								if ( firstBookedAfter ) {
									const canCheckoutBoundary =
										! eligibilityData[ firstBookedAfter ] ||
										eligibilityData[ firstBookedAfter ]
											.checkout !== false;
									if ( canCheckoutBoundary ) {
										newDisabled = disabledDates.filter(
											( d ) =>
												d < checkInStr ||
												d > firstBookedAfter
										);
										instance.set(
											'maxDate',
											firstBookedAfter
										);
									} else {
										const fParts = firstBookedAfter
											.split( '-' )
											.map( Number );
										const prevMax = new Date(
											fParts[ 0 ],
											fParts[ 1 ] - 1,
											fParts[ 2 ] - 1
										);
										instance.set(
											'maxDate',
											instance.formatDate(
												prevMax,
												'Y-m-d'
											)
										);
									}
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
								} else {
									instance.redraw();
								}
							} else if ( selectedDates.length === 2 ) {
								pendingCheckIn = null;
								resolveCandidateState(
									selectedDates[ 0 ],
									instance
								);
								instance.set(
									'maxDate',
									new Date().fp_incr( getMaxAdvanceDays() )
								);

								const checkOutStr2 = instance.formatDate(
									selectedDates[ 1 ],
									'Y-m-d'
								);
								const newDisabledFinal2 = disabledDates.filter(
									( d ) => d !== checkOutStr2
								);
								instance.set( 'disable', newDisabledFinal2 );
								instance.redraw();

								updateBookingForm( selectedDates );
							} else {
								pendingCheckIn = null;
								activeCandidateOverlay = {};
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

							// Dynamic Candidate Room Resolution for Aggregated Search (room_id = 0)
							let effectiveChangeover =
								changeoverData[ date ] || null;
							let effectiveBookingStatus =
								bookingStatusData[ date ] || null;
							let effectiveCheckoutStatus =
								checkoutStatusData[ date ] || null;
							let isCandidateBlocked = false;

							if (
								activeCandidateOverlay &&
								activeCandidateOverlay[ date ]
							) {
								const ov = activeCandidateOverlay[ date ];
								effectiveChangeover = ov.changeover;
								effectiveBookingStatus = ov.bookingStatus;
								effectiveCheckoutStatus = ov.checkoutStatus;
								isCandidateBlocked = Boolean( ov.isBlocked );
							}

							// Half-day styling for checkin/checkout changeover dates
							const changeover = effectiveChangeover;
							if (
								changeover &&
								dayElem.dateObj >= today
							) {
								dayElem.classList.add(
									'mhbo-half-booked-' + changeover
								);

								if ( changeover === 'both' ) {
									const coStatus =
										effectiveCheckoutStatus || 'confirmed';
									const ciStatus =
										effectiveBookingStatus || 'confirmed';
									dayElem.classList.add(
										'mhbo-checkout-' + coStatus
									);
									dayElem.classList.add(
										'mhbo-checkin-' + ciStatus
									);
									dayElem.classList.add(
										'mhbo-booking-' + ciStatus
									);
									if ( rawCheckoutStatusData[ date ] ) {
										dayElem.classList.add(
											'mhbo-checkout-' +
												rawCheckoutStatusData[ date ]
										);
									}
									if ( rawBookingStatusData[ date ] ) {
										dayElem.classList.add(
											'mhbo-booking-' +
												rawBookingStatusData[ date ]
										);
									}
								} else if ( changeover === 'checkout' ) {
									const coStatus =
										effectiveCheckoutStatus ||
										effectiveBookingStatus ||
										'confirmed';
									dayElem.classList.add(
										'mhbo-booking-' + coStatus
									);
									dayElem.classList.add(
										'mhbo-checkout-' + coStatus
									);
									if ( rawCheckoutStatusData[ date ] ) {
										dayElem.classList.add(
											'mhbo-booking-' +
												rawCheckoutStatusData[ date ]
										);
									}
								} else if ( changeover === 'checkin' ) {
									const ciStatus =
										effectiveBookingStatus || 'confirmed';
									dayElem.classList.add(
										'mhbo-booking-' + ciStatus
									);
									dayElem.classList.add(
										'mhbo-checkin-' + ciStatus
									);
									if ( rawBookingStatusData[ date ] ) {
										dayElem.classList.add(
											'mhbo-booking-' +
												rawBookingStatusData[ date ]
										);
									}
								}
							}

							// Apply booking status CSS class for full booked days
							if (
								! changeover &&
								effectiveBookingStatus &&
								( isCandidateBlocked ||
									dayElem.classList.contains(
										'flatpickr-disabled'
									) ||
									dayElem.classList.contains(
										'mhbo-unbookable'
									) )
							) {
								if ( dayElem.dateObj >= today ) {
									dayElem.classList.add(
										'mhbo-booking-' +
											effectiveBookingStatus
									);
									if ( isCandidateBlocked ) {
										dayElem.classList.add(
											'mhbo-candidate-blocked'
										);
									}
									if ( rawBookingStatusData[ date ] ) {
										dayElem.classList.add(
											'mhbo-booking-' +
												rawBookingStatusData[ date ]
										);
									}
								}
							}

							// Keep bookingStatusData synced for tooltip and accessibility
							if ( effectiveBookingStatus ) {
								bookingStatusData[ date ] = effectiveBookingStatus;
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
							} else if ( effectiveBookingStatus ) {
								ariaDesc =
									effectiveBookingStatus === 'pending'
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

						if (
							dayElem.classList.contains(
								'flatpickr-disabled'
							) &&
							dayElem.dateObj
						) {
							const disabledDateStr = picker
								? picker.formatDate( dayElem.dateObj, 'Y-m-d' )
								: '';
							const reason = disabledDateStr
								? reasonData[ disabledDateStr ]
								: null;
							const blockReason =
								reason ||
								( bookingStatusData[ disabledDateStr ]
									? bookingStatusData[ disabledDateStr ]
									: null );
							if ( blockReason ) {
								const reasonLabels = {
									booked:
										mhbo_calendar.i18n.reason_booked ||
										'Booked',
									confirmed:
										mhbo_calendar.i18n.reason_booked ||
										'Booked',
									pending:
										mhbo_calendar.i18n.status_pending ||
										'Provisional / Pending',
									manual:
										mhbo_calendar.i18n.reason_manual ||
										'Unavailable',
									maintenance:
										mhbo_calendar.i18n.reason_maintenance ||
										'Maintenance',
									turnover:
										mhbo_calendar.i18n.reason_turnover ||
										'Turnover Day (No Check-in)',
									cta:
										mhbo_calendar.i18n.reason_cta ||
										'Closed to Arrival',
									ctd:
										mhbo_calendar.i18n.reason_ctd ||
										'Closed to Departure',
								};
								const nSpan = tooltip.querySelector(
									'.mhbo-tooltip-nights'
								);
								if ( nSpan ) {
									nSpan.textContent = '';
								}
								const pSpan = tooltip.querySelector(
									'.mhbo-tooltip-price'
								);
								if ( pSpan ) {
									pSpan.style.display = 'none';
								}
								const cSpan = tooltip.querySelector(
									'.mhbo-tooltip-constraint'
								);
								if ( cSpan ) {
									cSpan.textContent =
										reasonLabels[ blockReason ] || blockReason;
									cSpan.style.display = '';
								}
								tooltip.style.left = e.clientX + 'px';
								tooltip.style.top = e.clientY + 'px';
								tooltip.classList.add( 'mhbo-visible' );
								tooltip.classList.remove( 'mhbo-tooltip-warn' );
								return;
							}
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

						const checkInStr = picker.formatDate( start, 'Y-m-d' );
						let minReqHover = minStayData[ checkInStr ];
						let maxAllHover = getStrictestSingleMaxStay(
							checkInStr,
							nights
						);
						let ctdHoverWarn = false;
						if ( Object.keys( freeRoomsData ).length > 0 ) {
							const hoverEval = evaluateAggregatedStay(
								checkInStr,
								nights
							);
							if ( hoverEval.validRooms.length > 0 ) {
								minReqHover = null;
								maxAllHover = null;
							} else if ( hoverEval.ctdBlocked ) {
								ctdHoverWarn = true;
							} else if ( hoverEval.continuousRooms.length > 0 ) {
								minReqHover = hoverEval.minRequired;
								maxAllHover = hoverEval.maxAllowed;
							}
						} else {
							const endHoverStr = picker.formatDate(
								end,
								'Y-m-d'
							);
							if (
								eligibilityData[ endHoverStr ] &&
								eligibilityData[ endHoverStr ].checkout ===
									false
							) {
								ctdHoverWarn = true;
							}
						}
						const constraintSpan = tooltip.querySelector(
							'.mhbo-tooltip-constraint'
						);

						if ( ctdHoverWarn ) {
							if ( constraintSpan ) {
								constraintSpan.textContent =
									mhbo_calendar.i18n.reason_ctd ||
									'Closed to Departure';
								constraintSpan.style.display = '';
							}
							tooltip.classList.add( 'mhbo-tooltip-warn' );
						} else if ( minReqHover && nights < minReqHover ) {
							if ( constraintSpan ) {
								constraintSpan.textContent = (
									mhbo_calendar.i18n.tooltip_min_hint ||
									'⚠ Min stay: %d nights'
								).replace( '%d', minReqHover );
								constraintSpan.style.display = '';
							}
							tooltip.classList.add( 'mhbo-tooltip-warn' );
						} else if ( maxAllHover && nights > maxAllHover ) {
							if ( constraintSpan ) {
								constraintSpan.textContent = (
									mhbo_calendar.i18n.tooltip_max_hint ||
									'⚠ Max stay: %d nights'
								).replace( '%d', maxAllHover );
								constraintSpan.style.display = '';
							}
							tooltip.classList.add( 'mhbo-tooltip-warn' );
						} else {
							if ( constraintSpan ) {
								constraintSpan.style.display = 'none';
							}
							tooltip.classList.remove( 'mhbo-tooltip-warn' );
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

						const summaryScope =
							mainForm.closest( '.mhbo-booking-wrapper' ) ||
							document;
						const dateValEls = summaryScope.querySelectorAll(
							'.mhbo-booking-dates .mhbo-date-val'
						);
						if ( dateValEls.length >= 2 ) {
							const fmtOpts = {
								month: 'short',
								day: 'numeric',
								year: 'numeric',
							};
							const docLang =
								document.documentElement.lang || 'en-US';
							dateValEls[ 0 ].textContent =
								start.toLocaleDateString( docLang, fmtOpts );
							dateValEls[ 1 ].textContent =
								end.toLocaleDateString( docLang, fmtOpts );
						}
						const nightsCountEl = summaryScope.querySelector(
							'.mhbo-booking-dates .mhbo-nights-count'
						);
						if ( nightsCountEl ) {
							const diffNights = Math.max(
								1,
								Math.round(
									( end - start ) / ( 1000 * 60 * 60 * 24 )
								)
							);
							nightsCountEl.textContent = String( diffNights );
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
			const action = form
				? form.getAttribute( 'action' )
				: ( wrapper.dataset.actionUrl ||
					( typeof mhbo_calendar !== 'undefined' &&
					mhbo_calendar.booking_url
						? mhbo_calendar.booking_url
						: '' ) ||
					'' );
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

			const minStayFinal = wrapper._mhboMinStayData || {};
			const maxStayFinal = wrapper._mhboMaxStayData || {};
			if (
				minStayFinal[ checkIn ] !== undefined ||
				maxStayFinal[ checkIn ] !== undefined
			) {
				const ciParts = checkIn.split( '-' );
				const coParts = checkOut.split( '-' );
				const nightsFinal = Math.round(
					( new Date( coParts[ 0 ], coParts[ 1 ] - 1, coParts[ 2 ] ) -
						new Date(
							ciParts[ 0 ],
							ciParts[ 1 ] - 1,
							ciParts[ 2 ]
						) ) /
						86400000
				);
				const minReq = minStayFinal[ checkIn ];
				const maxAll = maxStayFinal[ checkIn ];
				if ( minReq && nightsFinal < minReq ) {
					showError(
						(
							mhbo_calendar.i18n.api_err_min_stay ||
							'Minimum stay is %d nights.'
						)
							.replace( '%d', minReq )
							.replace( '%1$d', minReq )
					);
					return;
				}
				if ( maxAll && nightsFinal > maxAll ) {
					showError(
						(
							mhbo_calendar.i18n.api_err_max_stay ||
							'Maximum stay is %d nights.'
						)
							.replace( '%d', maxAll )
							.replace( '%1$d', maxAll )
					);
					return;
				}
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
				const resolvedAction =
					action && action !== '#' && action !== ''
						? action
						: ( wrapper.dataset.actionUrl ||
							( typeof mhbo_calendar !== 'undefined' &&
							mhbo_calendar.booking_url
								? mhbo_calendar.booking_url
								: '' ) ||
							'' );
				try {
					const base = window.location.origin;
					const url =
						resolvedAction &&
						resolvedAction !== '#' &&
						resolvedAction !== ''
							? new URL( resolvedAction, base )
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
						resolvedAction && resolvedAction.indexOf( '?' ) !== -1
							? '&'
							: '?';
					const baseUrl =
						resolvedAction &&
						resolvedAction !== '#' &&
						resolvedAction !== ''
							? resolvedAction
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

	// Bootstrap when DOM is ready and when Gutenberg Interactivity blocks mount
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAllCalendars );
	} else {
		initAllCalendars();
	}
	document.addEventListener( 'mhbo_init_calendar', initAllCalendars );
	document.addEventListener( 'mhbo_init_form', initAllCalendars );
} )();
