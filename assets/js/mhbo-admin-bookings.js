/**
 * Modern Hotel Booking - Admin Bookings
 *
 * Handles admin booking management interactions including:
 * - Price calculation for add/edit booking forms
 * - Dynamic child ages inputs
 * - Calendar integration
 * - Extras management
 *
 * @package ModernHotelBooking
 * @since 2.0.1
 * @version 2.6.2
 */

( function () {
	'use strict';

	// Debug logger - only logs when debug mode is enabled
	const debugLog = ( function () {
		const isDebug =
			( window.mhboAdminBookingsConfig &&
				window.mhboAdminBookingsConfig.debug ) ||
			( typeof localStorage !== 'undefined' &&
				localStorage.getItem( 'mhbo_debug' ) );
		return isDebug
			? console.log.bind( console, '[MHBO Admin]' )
			: function () {};
	} )();

	// Configuration is injected via wp_add_inline_script()
	const config = window.mhboAdminBookingsConfig || {};

	/**
	 * Safe HTML escaping helper
	 *
	 * @param {*} str Input string to escape.
	 * @return {string} Escaped HTML string.
	 */
	function escapeHtml( str ) {
		if ( str === null || str === undefined ) {
			return '';
		}
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	/**
	 * Price Calculation Setup
	 * @param prefix
	 */
	function setupCalculation( prefix ) {
		const roomSelect = document.getElementById( prefix + '_room_id' );
		const checkInInput = document.getElementById( prefix + '_check_in' );
		const checkOutInput = document.getElementById( prefix + '_check_out' );
		const totalPriceInput = document.getElementById(
			prefix + '_total_price'
		);
		const discountInput = document.getElementById(
			prefix + '_discount_amount'
		);
		const depositInput = document.getElementById(
			prefix + '_deposit_amount'
		);
		const depositReceivedInput = document.getElementById(
			prefix + '_deposit_received'
		);
		const paymentReceivedInput = document.getElementById(
			prefix + '_payment_received'
		);
		const paymentAmountInput = document.getElementById(
			prefix + '_payment_amount'
		);
		const outstandingInput = document.getElementById(
			prefix + '_amount_outstanding'
		);
		const paymentStatusSelect = document.getElementById(
			prefix + '_payment_status'
		);
		const outstandingBadge = document.getElementById(
			prefix + '_outstanding_badge'
		);

		if ( ! totalPriceInput ) {
			return;
		} // Not on Add or Edit page for this prefix

		const form = totalPriceInput.closest( 'form' );
		let calculationTimeout = null;
		let pendingCalculation = false;

		// List for changes in child ages (dynamically added inputs)
		const childAgesContainers = [
			'mhbo_add_child_ages_container',
			'mhbo_edit_child_ages_container',
		];
		childAgesContainers.forEach( ( id ) => {
			const container = document.getElementById( id );
			if ( container ) {
				container.addEventListener( 'change', function ( e ) {
					if ( e.target && e.target.name === 'child_ages[]' ) {
						updatePrices();
					}
				} );
				// Also listen for input event for more real-time feedback
				container.addEventListener( 'input', function ( e ) {
					if ( e.target && e.target.name === 'child_ages[]' ) {
						// Debounce price update for typing
						clearTimeout( window.mhboPriceTimer );
						window.mhboPriceTimer = setTimeout( updatePrices, 300 );
					}
				} );
			}
		} );

		/**
		 * Helper to perform the calculation
		 */
		function updatePrices() {
			if ( calculationTimeout ) {
				clearTimeout( calculationTimeout );
			}

			// Debounce to avoid too many requests while typing
			calculationTimeout = setTimeout( function () {
				const roomId = roomSelect ? roomSelect.value : '';
				const checkIn = checkInInput ? checkInInput.value : '';
				const checkOut = checkOutInput ? checkOutInput.value : '';
				const guestsInput = document.getElementById(
					prefix + '_guests'
				);
				const guests = guestsInput
					? parseInt( guestsInput.value ) || 1
					: 1;
				const childrenInput = document.getElementById(
					prefix + '_children'
				);
				const children = childrenInput
					? parseInt( childrenInput.value ) || 0
					: 0;

				const childrenAges = [];
				if ( form ) {
					form.querySelectorAll(
						'input[name="child_ages[]"]'
					).forEach( function ( inp ) {
						childrenAges.push( parseInt( inp.value ) || 0 );
					} );
				}

				if ( ! roomId || ! checkIn || ! checkOut ) {
					totalPriceInput.value = '';
					if ( outstandingInput ) {
						outstandingInput.value = '';
					}
					pendingCalculation = false;
					return;
				}

				const start = new Date( checkIn + 'T00:00:00' );
				const end = new Date( checkOut + 'T00:00:00' );

				if (
					isNaN( start.getTime() ) ||
					isNaN( end.getTime() ) ||
					end <= start
				) {
					totalPriceInput.value = '';
					if ( outstandingInput ) {
						outstandingInput.value = '';
					}
					pendingCalculation = false;
					return;
				}

				// Gather extras
				const extras = {};
				if ( form ) {
					form.querySelectorAll( '.mhbo-extra-input' ).forEach(
						function ( input ) {
							const qty =
								input.type === 'checkbox'
									? input.checked
										? 1
										: 0
									: parseInt( input.value ) || 0;
							if ( qty > 0 ) {
								let extraId = input.dataset.extraId;
								if ( ! extraId && input.name ) {
									const m = input.name.match( /(?:mhbo_extras|extras)\[([^\]]+)\]/ );
									if ( m && m[ 1 ] ) {
										extraId = m[ 1 ];
									}
								}
								if ( extraId ) {
									extras[ extraId ] = qty;
								}
							}
						}
					);
				}

				const couponInput = document.getElementById(
					prefix + '_coupon_code'
				);
				const couponCode = couponInput ? couponInput.value : '';
				const taxModeSelect = document.getElementById(
					prefix + '_tax_mode'
				);
				const taxMode = taxModeSelect ? taxModeSelect.value : '';

			// Visual feedback
			totalPriceInput.style.opacity = '0.5';
			pendingCalculation = true;
			window.mhboCalcStartTime = Date.now();

				const calcPayload = {
					room_id: roomId,
					check_in: checkIn,
					check_out: checkOut,
					guests,
					children,
					children_ages: childrenAges,
					extras,
					coupon_code: couponCode,
					tax_mode: taxMode,
				};
				const calcPromise = ( window.wp && window.wp.apiFetch )
					? window.wp.apiFetch( {
						path: '/mhbo/v1/recalculate-price',
						method: 'POST',
						data: calcPayload,
					} )
					: fetch( '/wp-json/mhbo/v1/recalculate-price', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': ( config && config.nonce ) ? config.nonce : '',
						},
						body: JSON.stringify( calcPayload ),
					} ).then( function ( response ) {
						return response.json();
					} );

				calcPromise
					.then( function ( data ) {
						totalPriceInput.style.opacity = '1';
						pendingCalculation = false;
						window.mhboCalcStartTime = null;
						if ( data.success ) {
							let total = parseFloat( data.total ) || 0;
							if ( data.coupon_applied ) {
								if ( discountInput ) {
									const disc = parseFloat( data.coupon_discount ) || 0;
									discountInput.value = disc.toFixed( 2 );
								}
							} else {
								const manualDiscount = parseFloat( discountInput ? discountInput.value : 0 ) || 0;
								if ( manualDiscount > 0 ) {
									total = Math.max( 0, total - manualDiscount );
								}
							}
							totalPriceInput.value = total.toFixed( 2 );

							// If in edit mode and total increased above currently paid amount, uncheck full payment received
							if ( prefix === 'mhbo_edit' ) {
								const currPay = parseFloat( paymentAmountInput ? paymentAmountInput.value : 0 ) || 0;
								if ( total > currPay + 0.005 ) {
									if ( paymentReceivedInput && paymentReceivedInput.checked ) {
										paymentReceivedInput.checked = false;
									}
									if ( paymentStatusSelect && paymentStatusSelect.value === 'completed' ) {
										paymentStatusSelect.value = currPay > 0.005 ? 'deposit_paid' : 'pending';
									}
								}
							}

							// In Add mode, auto-fill deposit amount if returned and not manually overridden
							if (
								prefix === 'mhbo_add' &&
								depositInput &&
								( typeof data.deposit_amount === 'number' || typeof data.deposit_amount === 'string' ) &&
								parseFloat( data.deposit_amount ) > 0 &&
								( ! depositInput.value || depositInput.dataset.autoFilled === 'true' )
							) {
								depositInput.value = parseFloat( data.deposit_amount ).toFixed( 2 );
								depositInput.dataset.autoFilled = 'true';
							}

							// Real-time synchronization of overview deposit KPI
							const overviewDepositVal = document.getElementById( 'mhbo_overview_deposit_val' );
							if ( overviewDepositVal ) {
								const currSymbol = ( window.mhboAdminBookingsData && window.mhboAdminBookingsData.currencySymbol ) ? window.mhboAdminBookingsData.currencySymbol : '$';
								overviewDepositVal.textContent = currSymbol + ( parseFloat( data.deposit_amount || 0 ) ).toFixed( 2 );
							}

							// Render Live Folio & Charges Breakdown table for Add and Edit modes
							renderFolioBreakdown( prefix, data, total, checkIn, checkOut, guests, children );

							calculateOutstanding();
						}
					} )
					.catch( function ( err ) {
						totalPriceInput.style.opacity = '1';
						pendingCalculation = false;
						window.mhboCalcStartTime = null;
						debugLog( 'Calculation Error:', err );
					} );
			}, 300 );
		}

		/**
		 * Calculate and update Amount Outstanding locally & synchronously
		 */
		function calculateOutstanding() {
			if ( ! outstandingInput ) {
				return;
			}
			const total = parseFloat( totalPriceInput ? totalPriceInput.value : 0 ) || 0;
			const currentPaymentAmount = parseFloat( paymentAmountInput ? paymentAmountInput.value : 0 ) || 0;
			const isMarkedPaid = paymentReceivedInput && paymentReceivedInput.checked;

			let outstanding = total;
			if ( isMarkedPaid && currentPaymentAmount >= total - 0.005 ) {
				outstanding = 0;
			} else {
				outstanding = Math.max( 0, outstanding - currentPaymentAmount );
			}
			outstandingInput.value = outstanding.toFixed( 2 );

			// Check for overpayment / credit balance (e.g. shortened stay or removed services)
			const isOverpaid = currentPaymentAmount > total + 0.005;
			const creditBalance = isOverpaid ? ( currentPaymentAmount - total ) : 0;

			// Real-time synchronization of Upper Financial & Collection Overview counters
			const overviewTotalVal   = document.getElementById( 'mhbo_overview_total_val' );
			const overviewPendingVal = document.getElementById( 'mhbo_overview_pending_val' );
			const overviewPaidVal    = document.getElementById( 'mhbo_overview_paid_val' );
			const overviewCreditTile = document.getElementById( 'mhbo_overview_credit_tile' );
			const overviewCreditVal  = document.getElementById( 'mhbo_overview_credit_val' );
			const currSymbol         = ( window.mhboAdminBookingsData && window.mhboAdminBookingsData.currencySymbol ) ? window.mhboAdminBookingsData.currencySymbol : '$';

			if ( overviewTotalVal ) {
				overviewTotalVal.textContent = currSymbol + total.toFixed( 2 );
			}
			if ( overviewPendingVal ) {
				overviewPendingVal.textContent = currSymbol + outstanding.toFixed( 2 );
				if ( outstanding > 0.005 ) {
					overviewPendingVal.classList.add( 'mhbo-kpi-value-due' );
				} else {
					overviewPendingVal.classList.remove( 'mhbo-kpi-value-due' );
				}
			}
			if ( overviewPaidVal ) {
				overviewPaidVal.textContent = currSymbol + currentPaymentAmount.toFixed( 2 );
			}
			if ( overviewCreditTile && overviewCreditVal ) {
				if ( isOverpaid ) {
					overviewCreditTile.style.display = 'block';
					overviewCreditVal.textContent = '−' + currSymbol + creditBalance.toFixed( 2 );
				} else {
					overviewCreditTile.style.display = 'none';
				}
			}

			// Real-time synchronization of Card 4 Folio Payment Ledger Balance Due row (Edit mode)
			const ledgerBalanceDueLabel = document.getElementById( 'mhbo_ledger_balance_due_label' );
			const ledgerBalanceDueVal   = document.getElementById( 'mhbo_ledger_balance_due_val' );
			if ( ledgerBalanceDueLabel && ledgerBalanceDueVal ) {
				if ( isOverpaid ) {
					ledgerBalanceDueLabel.textContent = ( config.i18n && config.i18n.creditBalance ) ? ( config.i18n.creditBalance + ':' ) : 'Credit Balance:';
					ledgerBalanceDueLabel.style.color = '#0284c7';
					ledgerBalanceDueVal.textContent = '−' + currSymbol + creditBalance.toFixed( 2 );
					ledgerBalanceDueVal.style.color = '#0284c7';
				} else {
					ledgerBalanceDueLabel.textContent = ( config.i18n && config.i18n.balanceDue ) ? ( config.i18n.balanceDue + ':' ) : 'Balance Due:';
					ledgerBalanceDueLabel.style.color = outstanding > 0.005 ? '#dc2626' : '#166534';
					ledgerBalanceDueVal.textContent = currSymbol + outstanding.toFixed( 2 );
					ledgerBalanceDueVal.style.color = outstanding > 0.005 ? '#dc2626' : '#166534';
				}
			}

			// Synchronize Card 5 quick tender balance button
			const quickPayBalanceBtn = document.querySelector( '.mhbo-quick-pay-btn[data-type="balance"]' );
			if ( quickPayBalanceBtn ) {
				if ( outstanding > 0.005 ) {
					quickPayBalanceBtn.style.display = 'inline-flex';
					quickPayBalanceBtn.setAttribute( 'data-amount', outstanding.toFixed( 2 ) );
					quickPayBalanceBtn.textContent = '⚡ ' + ( ( config.i18n && config.i18n.payRemaining ) ? config.i18n.payRemaining : 'Settle Balance' ) + ' (' + currSymbol + outstanding.toFixed( 2 ) + ')';
				} else {
					quickPayBalanceBtn.style.display = 'none';
				}
			}

			if ( outstandingBadge ) {
				outstandingBadge.style.display = 'inline-block';
				if ( isOverpaid ) {
					outstandingBadge.textContent = '★ ' + ( ( config.i18n && config.i18n.creditBalance ) ? config.i18n.creditBalance : 'Credit Balance' );
					outstandingBadge.style.background = '#e0f2fe';
					outstandingBadge.style.color = '#0369a1';
				} else if ( outstanding <= 0.005 && total > 0 ) {
					outstandingBadge.textContent = '✓ ' + ( ( config.i18n && config.i18n.paidInFull ) ? config.i18n.paidInFull : 'Paid in Full' );
					outstandingBadge.style.background = '#dcfce7';
					outstandingBadge.style.color = '#166534';
				} else if ( total > 0 ) {
					outstandingBadge.textContent = ( config.i18n && config.i18n.balanceDue ) ? config.i18n.balanceDue : 'Balance Due';
					outstandingBadge.style.background = '#fee2e2';
					outstandingBadge.style.color = '#991b1b';
				} else {
					outstandingBadge.style.display = 'none';
				}
			}
		}

		// Quick Tender / Pay Helper Buttons in Folio Workstation
		document.querySelectorAll( '.mhbo-quick-pay-btn' ).forEach( function ( btn ) {
			if ( btn.dataset.mhboBound === 'true' ) {
				return;
			}
			btn.dataset.mhboBound = 'true';
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				const amt       = this.getAttribute( 'data-amount' );
				const type      = this.getAttribute( 'data-type' );
				const note      = this.getAttribute( 'data-note' );
				const amtInput  = document.getElementById( 'mhbo_record_payment_amount' );
				const typeInput = document.getElementById( 'mhbo_record_payment_type' );
				const noteInput = document.getElementById( 'mhbo_record_payment_note' );
				if ( amtInput && amt ) {
					const num = parseFloat( amt );
					amtInput.value = isNaN( num ) ? amt : num.toFixed( 2 );
					amtInput.focus();
				}
				if ( typeInput && type ) {
					typeInput.value = type;
				}
				if ( noteInput && note ) {
					noteInput.value = note;
				}
			} );
		} );

		// 1-Click Copy Magic Balance Link Helper
		document.querySelectorAll( '.mhbo-copy-balance-link' ).forEach( function ( btn ) {
			if ( btn.dataset.mhboBound === 'true' ) {
				return;
			}
			btn.dataset.mhboBound = 'true';
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				const url = this.getAttribute( 'data-url' );
				if ( url && navigator.clipboard ) {
					navigator.clipboard.writeText( url ).then( function () {
						const origText = btn.textContent;
						btn.textContent = '✓ Copied';
						setTimeout( function () {
							btn.textContent = origText;
						}, 2000 );
					} );
				}
			} );
		} );

		/**
		 * Helper to resolve deposit amount when deposit input is empty or zero
		 * @param {number} totalVal Total price value
		 * @return {number} Resolved deposit amount
		 */
		function resolveDefaultDeposit( totalVal ) {
			if ( ! totalVal || totalVal <= 0 ) {
				return 0;
			}
			const depType = ( config && config.depositType ) ? config.depositType : 'percentage';
			const depValSetting = ( config && typeof config.depositValue === 'number' ) ? config.depositValue : 20;
			if ( depType === 'fixed' && depValSetting > 0 ) {
				return Math.min( totalVal, depValSetting );
			}
			const pct = depValSetting > 0 ? depValSetting : 20;
			return Math.min( totalVal, ( totalVal * pct ) / 100 );
		}

		// Synchronous payment & deposit listeners
		if ( paymentReceivedInput ) {
			paymentReceivedInput.addEventListener( 'change', function () {
				if ( this.checked ) {
					if ( depositReceivedInput ) {
						depositReceivedInput.checked = false;
					}
					if ( paymentAmountInput && totalPriceInput ) {
						const totalVal = parseFloat( totalPriceInput.value ) || 0;
						if ( totalVal > 0 ) {
							paymentAmountInput.value = totalVal.toFixed( 2 );
						}
					}
					if ( paymentStatusSelect ) {
						paymentStatusSelect.value = 'completed';
					}
					if ( outstandingInput ) {
						outstandingInput.value = '0.00';
					}
				} else {
					if ( paymentStatusSelect && paymentStatusSelect.value === 'completed' ) {
						paymentStatusSelect.value = ( depositReceivedInput && depositReceivedInput.checked ) ? 'deposit_paid' : 'pending';
					}
					if ( paymentAmountInput && totalPriceInput ) {
						const totalVal = parseFloat( totalPriceInput.value ) || 0;
						const currentPay = parseFloat( paymentAmountInput.value ) || 0;
						if ( Math.abs( currentPay - totalVal ) < 0.01 ) {
							paymentAmountInput.value = '0.00';
						}
					}
				}
				calculateOutstanding();
			} );
		}

		if ( paymentStatusSelect ) {
			paymentStatusSelect.addEventListener( 'change', function () {
				if ( this.value === 'completed' ) {
					if ( paymentReceivedInput ) {
						paymentReceivedInput.checked = true;
					}
					if ( depositReceivedInput ) {
						depositReceivedInput.checked = false;
					}
					if ( paymentAmountInput && totalPriceInput ) {
						const totalVal = parseFloat( totalPriceInput.value ) || 0;
						if ( totalVal > 0 ) {
							paymentAmountInput.value = totalVal.toFixed( 2 );
						}
					}
					if ( outstandingInput ) {
						outstandingInput.value = '0.00';
					}
				} else if ( this.value === 'deposit_paid' ) {
					if ( paymentReceivedInput ) {
						paymentReceivedInput.checked = false;
					}
					if ( depositReceivedInput && ! depositReceivedInput.checked ) {
						depositReceivedInput.checked = true;
					}
					let depVal = parseFloat( depositInput ? depositInput.value : 0 ) || 0;
					if ( depVal <= 0 && totalPriceInput ) {
						const totalVal = parseFloat( totalPriceInput.value ) || 0;
						depVal = resolveDefaultDeposit( totalVal );
						if ( depositInput && depVal > 0 ) {
							depositInput.value = depVal.toFixed( 2 );
						}
					}
					if ( paymentAmountInput && depVal > 0 ) {
						paymentAmountInput.value = depVal.toFixed( 2 );
					}
				} else if ( this.value === 'pending' ) {
					if ( paymentReceivedInput ) {
						paymentReceivedInput.checked = false;
					}
					if ( depositReceivedInput ) {
						depositReceivedInput.checked = false;
					}
					if ( paymentAmountInput ) {
						paymentAmountInput.value = '0.00';
					}
				}
				calculateOutstanding();
			} );
		}

		if ( depositReceivedInput ) {
			depositReceivedInput.addEventListener( 'change', function () {
				if ( this.checked ) {
					if ( paymentReceivedInput ) {
						paymentReceivedInput.checked = false;
					}
					let depVal = parseFloat( depositInput ? depositInput.value : 0 ) || 0;
					if ( depVal <= 0 && totalPriceInput ) {
						const totalVal = parseFloat( totalPriceInput.value ) || 0;
						depVal = resolveDefaultDeposit( totalVal );
						if ( depositInput && depVal > 0 ) {
							depositInput.value = depVal.toFixed( 2 );
						}
					}
					if ( paymentAmountInput && depVal > 0 ) {
						paymentAmountInput.value = depVal.toFixed( 2 );
					}
					if ( paymentStatusSelect ) {
						paymentStatusSelect.value = 'deposit_paid';
					}
				} else {
					if ( paymentStatusSelect && paymentStatusSelect.value === 'deposit_paid' ) {
						paymentStatusSelect.value = 'pending';
					}
					if ( paymentAmountInput && depositInput ) {
						const depVal = parseFloat( depositInput.value ) || 0;
						const currentPay = parseFloat( paymentAmountInput.value ) || 0;
						if ( Math.abs( currentPay - depVal ) < 0.01 ) {
							paymentAmountInput.value = '0.00';
						}
					}
				}
				calculateOutstanding();
			} );
		}

		if ( depositInput ) {
			depositInput.addEventListener( 'input', function () {
				if ( depositReceivedInput && depositReceivedInput.checked && paymentAmountInput ) {
					const depVal = parseFloat( depositInput.value ) || 0;
					if ( depVal > 0 ) {
						paymentAmountInput.value = depVal.toFixed( 2 );
						calculateOutstanding();
					}
				}
			} );
		}

		[ paymentAmountInput, depositInput, totalPriceInput ].forEach( function ( el ) {
			if ( el ) {
				el.addEventListener( 'input', calculateOutstanding );
				el.addEventListener( 'change', calculateOutstanding );
			}
		} );

		// Stay parameter listeners trigger rate recalculation
		const guestsInput = document.getElementById( prefix + '_guests' );
		const childrenInput = document.getElementById( prefix + '_children' );
		const taxModeSelect = document.getElementById( prefix + '_tax_mode' );
		const rateListeners = [
			roomSelect,
			checkInInput,
			checkOutInput,
			guestsInput,
			childrenInput,
			discountInput,
			taxModeSelect,
		];
		rateListeners.forEach( function ( el ) {
			if ( el ) {
				el.addEventListener( 'change', updatePrices );
				if ( el.tagName === 'INPUT' ) {
					el.addEventListener( 'input', updatePrices );
				}
			}
		} );

		if ( form ) {
			// Use event delegation for dynamically added extras
			form.addEventListener( 'change', function ( e ) {
				if ( e.target.classList.contains( 'mhbo-extra-input' ) ) {
					updatePrices();
				}
			} );
			form.addEventListener( 'input', function ( e ) {
				if ( e.target.classList.contains( 'mhbo-extra-input' ) ) {
					clearTimeout( window.mhboPriceTimer );
					window.mhboPriceTimer = setTimeout( updatePrices, 300 );
				}
			} );
		}

		// Form submit handler - ensure calculation completes before submit
		if ( form ) {
			const MAX_WAIT_MS = 5000;
			const RETRY_INTERVAL_MS = 500;
			let submitAttempts = 0;

			form.addEventListener( 'submit', function ( e ) {
				if ( ! pendingCalculation ) {
					return;
				}

				e.preventDefault();
				submitAttempts++;

				const elapsed = Date.now() - ( window.mhboCalcStartTime || Date.now() );
				const remaining = Math.max( 0, MAX_WAIT_MS - elapsed );

				if ( elapsed >= MAX_WAIT_MS ) {
					alert(
						'Price calculation is taking longer than expected. ' +
						'Please check your network connection and try again. ' +
						'If the problem persists, you can submit the form and the server will recalculate the total.'
					);
					form.submit();
					return;
				}

				setTimeout( function () {
					if ( ! pendingCalculation ) {
						form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
					} else if ( submitAttempts < Math.ceil( MAX_WAIT_MS / RETRY_INTERVAL_MS ) ) {
						form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
					} else {
						alert(
							'Price calculation is taking longer than expected. ' +
							'Submitting with current values. The server will validate the total on save.'
						);
						form.submit();
					}
				}, Math.min( RETRY_INTERVAL_MS, remaining ) );
			} );
		}

		/**
		 * Render live USALI folio charges breakdown table for Add & Edit booking workstations
		 *
		 * @param {string} prefix Form prefix ('mhbo_add' or 'mhbo_edit').
		 * @param {Object} data REST recalculate-price response payload.
		 * @param {number} total Final recalculated total price.
		 * @param {string} checkIn Check-in ISO date.
		 * @param {string} checkOut Check-out ISO date.
		 * @param {number} guests Adult guests count.
		 * @param {number} children Child guests count.
		 */
		function renderFolioBreakdown( prefix, data, total, checkIn, checkOut, guests, children ) {
			const folioTbody = document.getElementById( prefix + '_folio_tbody' );
			const folioFootTotal = document.getElementById( prefix + '_folio_foot_total' );
			if ( ! folioTbody ) {
				return;
			}

			const currSymbol = ( window.mhboAdminBookingsData && window.mhboAdminBookingsData.currencySymbol ) ? window.mhboAdminBookingsData.currencySymbol : '$';
			let rowsHtml = '';
			const roomTotalFormatted = data.room_total_formatted || ( currSymbol + ( parseFloat( data.room_total ) || 0 ).toFixed( 2 ) );
			const roomOpt = roomSelect ? roomSelect.selectedOptions[ 0 ] : null;
			const roomLabel = roomOpt ? ( roomOpt.dataset.roomName || roomOpt.textContent.trim() ) : ( ( config.i18n && config.i18n.room ) ? config.i18n.room : 'Assigned Unit' );
			const nightsCount = ( data.breakdown && data.breakdown.nights ) ? data.breakdown.nights : 1;

			// Tax configuration & modes
			const taxEnabled = Boolean( data.tax && data.tax.enabled && data.tax.mode !== 'disabled' );
			const totalTaxNum = ( data.tax && data.tax.totals ) ? ( parseFloat( data.tax.totals.total_tax ) || 0 ) : 0;
			const hasTax = taxEnabled && totalTaxNum > 0.005;
			const isVat = hasTax && ( data.tax.mode === 'vat' || data.tax.mode === 'inclusive' );
			const isSalesTax = hasTax && ( data.tax.mode === 'sales_tax' || data.tax.mode === 'exclusive' );

			const accRate = ( hasTax && data.tax.rates && parseFloat( data.tax.rates.accommodation ) > 0 )
				? parseFloat( data.tax.rates.accommodation )
				: 0;

			const extRate = ( hasTax && data.tax.rates && parseFloat( data.tax.rates.extras ) > 0 )
				? parseFloat( data.tax.rates.extras )
				: 0;

			// Rate formatting with average indication if prices vary across nights
			const roomTotalNum = parseFloat( data.room_total ) || 0;
			const baseRate = ( roomOpt && roomOpt.dataset.price ) ? parseFloat( roomOpt.dataset.price ) : ( nightsCount > 0 ? ( roomTotalNum / nightsCount ) : 0 );
			const avgRate = nightsCount > 0 ? ( roomTotalNum / nightsCount ) : baseRate;
			let rateDisplay = currSymbol + baseRate.toFixed( 2 );
			if ( nightsCount > 1 && Math.abs( avgRate - baseRate ) > 0.01 ) {
				rateDisplay = currSymbol + avgRate.toFixed( 2 ) + ' <span style="font-size:10px; color:#64748b; font-weight:500;">(avg)</span>';
			}

			let lodgingTaxDisplay = '—';
			if ( taxEnabled && accRate > 0 ) {
				lodgingTaxDisplay = accRate + '%' + ( isVat ? ' <span style="font-size:10px; color:#0284c7; font-weight:600;">(Incl.)</span>' : '' );
			}

			// 1. Accommodation
			rowsHtml += '<tr>';
			rowsHtml += '<td><span class="mhbo-charge-category-badge mhbo-cat-room">LODGING</span></td>';
			rowsHtml += '<td><strong>' + escapeHtml( roomLabel ) + '</strong><br><span style="font-size:11px; color:#64748b;">' + escapeHtml( checkIn ) + ' → ' + escapeHtml( checkOut ) + '</span></td>';
			rowsHtml += '<td style="text-align:right; font-variant-numeric:tabular-nums;">' + rateDisplay + '</td>';
			rowsHtml += '<td style="text-align:center; font-variant-numeric:tabular-nums;">' + nightsCount + '</td>';
			rowsHtml += '<td style="text-align:right; color:#64748b; font-size:12px; font-variant-numeric:tabular-nums;">' + lodgingTaxDisplay + '</td>';
			rowsHtml += '<td style="text-align:right; font-weight:700; font-variant-numeric:tabular-nums;">' + roomTotalFormatted + '</td>';
			rowsHtml += '</tr>';

			// 2. Child supplements
			if ( data.children_total && parseFloat( data.children_total ) > 0 ) {
				let childTaxDisplay = '—';
				if ( taxEnabled && accRate > 0 ) {
					childTaxDisplay = accRate + '%' + ( isVat ? ' <span style="font-size:10px; color:#0284c7; font-weight:600;">(Incl.)</span>' : '' );
				}
				rowsHtml += '<tr>';
				rowsHtml += '<td><span class="mhbo-charge-category-badge mhbo-cat-children">CHILDREN</span></td>';
				rowsHtml += '<td><strong>' + ( ( config.i18n && config.i18n.childSupplement ) ? config.i18n.childSupplement : 'Child Supplements' ) + '</strong></td>';
				rowsHtml += '<td style="text-align:right; font-variant-numeric:tabular-nums;">—</td>';
				rowsHtml += '<td style="text-align:center; font-variant-numeric:tabular-nums;">' + children + '</td>';
				rowsHtml += '<td style="text-align:right; color:#64748b; font-size:12px; font-variant-numeric:tabular-nums;">' + childTaxDisplay + '</td>';
				rowsHtml += '<td style="text-align:right; font-weight:700; font-variant-numeric:tabular-nums;">' + ( data.children_total_formatted || ( currSymbol + parseFloat( data.children_total ).toFixed( 2 ) ) ) + '</td>';
				rowsHtml += '</tr>';
			}

			// 3. Extras & Stay Extensions
			if ( data.extras_breakdown && typeof data.extras_breakdown === 'object' ) {
				Object.keys( data.extras_breakdown ).forEach( function ( k ) {
					const ex = data.extras_breakdown[ k ];
					const isStayExtension = ( k === '_early_checkin' || k === '_late_checkout' );
					const itemTaxRate = isStayExtension ? accRate : extRate;
					let extraTaxDisplay = '—';
					if ( taxEnabled && itemTaxRate > 0 ) {
						extraTaxDisplay = itemTaxRate + '%' + ( isVat ? ' <span style="font-size:10px; color:#0284c7; font-weight:600;">(Incl.)</span>' : '' );
					}
					const catBadgeClass = isStayExtension ? 'mhbo-cat-room' : 'mhbo-cat-extra';
					const catBadgeLabel = isStayExtension ? 'EXTENSION' : 'ENHANCEMENT';
					const detailsHtml = isStayExtension
						? '<div style="font-size:11px; color:#64748b; margin-top:2px;">Stay schedule extension</div>'
						: ( ex.pricing_type ? '<div style="font-size:11px; color:#64748b; margin-top:2px;">' + escapeHtml( ex.pricing_type.replace( /_/g, ' ' ) ) + '</div>' : '' );

					rowsHtml += '<tr>';
					rowsHtml += '<td><span class="mhbo-charge-category-badge ' + catBadgeClass + '">' + catBadgeLabel + '</span></td>';
					rowsHtml += '<td><strong>' + escapeHtml( ex.name ) + '</strong>' + ( ex.compulsory ? ' <span style="font-size:10px; color:#5b72b8; font-weight:600;">(Compulsory)</span>' : '' ) + detailsHtml + '</td>';
					rowsHtml += '<td style="text-align:right; font-variant-numeric:tabular-nums;">' + ( ex.unit_price_formatted || ( currSymbol + parseFloat( ex.unit_price_value || 0 ).toFixed( 2 ) ) ) + '</td>';
					rowsHtml += '<td style="text-align:center; font-variant-numeric:tabular-nums;">' + ( ex.quantity || 1 ) + '</td>';
					rowsHtml += '<td style="text-align:right; color:#64748b; font-size:12px; font-variant-numeric:tabular-nums;">' + extraTaxDisplay + '</td>';
					rowsHtml += '<td style="text-align:right; font-weight:700; font-variant-numeric:tabular-nums;">' + ( ex.formatted || ( currSymbol + parseFloat( ex.value || 0 ).toFixed( 2 ) ) ) + '</td>';
					rowsHtml += '</tr>';
				} );
			}

			// 4. Promo coupon
			if ( data.coupon_applied && data.coupon_discount && parseFloat( data.coupon_discount ) > 0 ) {
				rowsHtml += '<tr>';
				rowsHtml += '<td><span class="mhbo-charge-category-badge mhbo-cat-discount">PROMO</span></td>';
				rowsHtml += '<td><strong style="color:#166534;">' + ( ( config.i18n && config.i18n.couponDiscount ) ? config.i18n.couponDiscount : 'Promo Discount' ) + '</strong></td>';
				rowsHtml += '<td style="text-align:right; font-variant-numeric:tabular-nums;">—</td>';
				rowsHtml += '<td style="text-align:center; font-variant-numeric:tabular-nums;">1</td>';
				rowsHtml += '<td style="text-align:right; color:#64748b; font-size:12px; font-variant-numeric:tabular-nums;">—</td>';
				rowsHtml += '<td style="text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:#166534;">−' + ( data.coupon_discount_formatted || ( currSymbol + parseFloat( data.coupon_discount ).toFixed( 2 ) ) ) + '</td>';
				rowsHtml += '</tr>';
			}

			// 5. Courtesy discount
			const manualDisc = parseFloat( discountInput ? discountInput.value : 0 ) || 0;
			if ( manualDisc > 0 && ! data.coupon_applied ) {
				rowsHtml += '<tr>';
				rowsHtml += '<td><span class="mhbo-charge-category-badge mhbo-cat-discount">CONCESSION</span></td>';
				rowsHtml += '<td><strong style="color:#92400e;">' + ( ( config.i18n && config.i18n.courtesyDiscount ) ? config.i18n.courtesyDiscount : 'Manual Discount / Concession' ) + '</strong></td>';
				rowsHtml += '<td style="text-align:right; font-variant-numeric:tabular-nums;">—</td>';
				rowsHtml += '<td style="text-align:center; font-variant-numeric:tabular-nums;">1</td>';
				rowsHtml += '<td style="text-align:right; color:#64748b; font-size:12px; font-variant-numeric:tabular-nums;">—</td>';
				rowsHtml += '<td style="text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:#92400e;">−' + currSymbol + manualDisc.toFixed( 2 ) + '</td>';
				rowsHtml += '</tr>';
			}

			// 6. Taxes (if enabled and tax > 0)
			if ( hasTax && data.tax && data.tax.totals ) {
				const taxVal = totalTaxNum;
				if ( taxVal > 0 ) {
					const baseTaxLabel = ( data.tax.base_label && data.tax.base_label.trim() ) ? data.tax.base_label.trim() : ( ( data.tax.label && data.tax.label.trim() ) ? data.tax.label.trim() : 'Tax' );
					const modeDescription = isVat ? ( baseTaxLabel + ' (included)' ) : ( baseTaxLabel + ' (Sales Tax / GST Added)' );
					rowsHtml += '<tr style="background:#f8fafc;">';
					rowsHtml += '<td><span class="mhbo-charge-category-badge mhbo-cat-adjustment">TAXES</span></td>';
					rowsHtml += '<td><strong>' + escapeHtml( modeDescription ) + '</strong></td>';
					rowsHtml += '<td colspan="3" style="text-align:right; color:#64748b; font-size:11px;">' + ( isVat ? 'Included in Lodging' : 'Added to Total' ) + '</td>';
					rowsHtml += '<td style="text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:#475569;">' + currSymbol + taxVal.toFixed( 2 ) + '</td>';
					rowsHtml += '</tr>';
				}
			}

			folioTbody.innerHTML = rowsHtml;

			if ( folioFootTotal ) {
				folioFootTotal.textContent = data.total_formatted || ( currSymbol + total.toFixed( 2 ) );
			}

			// Footer tax & subtotal net rows
			const folioFootSubtotalRow   = document.getElementById( prefix + '_folio_foot_subtotal_row' );
			const folioFootSubtotalLabel = document.getElementById( prefix + '_folio_foot_subtotal_label' );
			const folioFootSubtotalNet   = document.getElementById( prefix + '_folio_foot_subtotal_net' );
			const folioFootTaxRow        = document.getElementById( prefix + '_folio_foot_tax_row' );
			const folioFootTaxLabel      = document.getElementById( prefix + '_folio_foot_tax_label' );
			const folioFootTax           = document.getElementById( prefix + '_folio_foot_tax' );
			const folioFootTotalLabel    = document.getElementById( prefix + '_folio_foot_total_label' );

			if ( hasTax && data.tax && data.tax.totals ) {
				const subtotalNet = parseFloat( data.tax.totals.subtotal_net ) || 0;
				const totalTax    = totalTaxNum;
				const baseLabel   = ( data.tax.base_label && data.tax.base_label.trim() ) ? data.tax.base_label.trim() : ( ( data.tax.label && data.tax.label.trim() ) ? data.tax.label.trim() : 'Tax' );

				if ( folioFootSubtotalRow && folioFootSubtotalNet ) {
					folioFootSubtotalRow.style.removeProperty( 'display' );
					folioFootSubtotalRow.style.display = '';
					folioFootSubtotalRow.classList.remove( 'mhbo-hidden' );
					folioFootSubtotalRow.removeAttribute( 'hidden' );
					folioFootSubtotalNet.textContent = currSymbol + subtotalNet.toFixed( 2 );
					if ( folioFootSubtotalLabel ) {
						folioFootSubtotalLabel.textContent = isVat ? ( 'Subtotal Net (excl. ' + baseLabel + '):' ) : 'Subtotal Net (excl. tax):';
					}
				}
				if ( folioFootTaxRow && folioFootTax ) {
					folioFootTaxRow.style.removeProperty( 'display' );
					folioFootTaxRow.style.display = '';
					folioFootTaxRow.classList.remove( 'mhbo-hidden' );
					folioFootTaxRow.removeAttribute( 'hidden' );
					if ( folioFootTaxLabel ) {
						folioFootTaxLabel.textContent = isVat ? ( baseLabel + ' (included):' ) : ( 'Taxes (' + baseLabel + '):' );
					}
					folioFootTax.textContent = currSymbol + totalTax.toFixed( 2 );
				}
				if ( folioFootTotalLabel ) {
					if ( prefix === 'mhbo_add' ) {
						folioFootTotalLabel.textContent = isVat ? ( 'Estimated Total (' + baseLabel + ' incl.):' ) : 'Estimated Total Gross:';
					} else {
						folioFootTotalLabel.textContent = isVat ? ( 'Total Charges (' + baseLabel + ' incl.):' ) : 'Total Charges:';
					}
				}
			} else {
				if ( folioFootSubtotalRow ) {
					folioFootSubtotalRow.style.setProperty( 'display', 'none', 'important' );
					folioFootSubtotalRow.classList.add( 'mhbo-hidden' );
					folioFootSubtotalRow.setAttribute( 'hidden', '' );
				}
				if ( folioFootTaxRow ) {
					folioFootTaxRow.style.setProperty( 'display', 'none', 'important' );
					folioFootTaxRow.classList.add( 'mhbo-hidden' );
					folioFootTaxRow.setAttribute( 'hidden', '' );
				}
				if ( folioFootTotalLabel ) {
					folioFootTotalLabel.textContent = ( prefix === 'mhbo_add' ) ? 'Estimated Total Gross:' : 'Total Charges:';
				}
			}

			// Coupon status indicator
			const couponInput = document.getElementById( prefix + '_coupon_code' );
			const couponStatusBadge = document.getElementById( prefix + '_coupon_status' );
			if ( couponStatusBadge ) {
				const code = couponInput ? couponInput.value.trim() : '';
				if ( code ) {
					couponStatusBadge.style.display = 'inline-block';
					if ( data.coupon_applied ) {
						couponStatusBadge.style.background = '#dcfce7';
						couponStatusBadge.style.color = '#166534';
						couponStatusBadge.textContent = '✓ ' + ( ( config.i18n && config.i18n.couponValid ) ? config.i18n.couponValid : 'Valid' ) + ' (' + ( data.coupon_discount_formatted || 'Applied' ) + ')';
					} else {
						couponStatusBadge.style.background = '#fee2e2';
						couponStatusBadge.style.color = '#991b1b';
						couponStatusBadge.textContent = '⚠️ ' + ( ( config.i18n && config.i18n.couponInvalid ) ? config.i18n.couponInvalid : 'Invalid Code' );
					}
				} else {
					couponStatusBadge.style.display = 'none';
				}
			}
		}

		// Backward compatibility alias for renderAddFolioBreakdown
		const renderAddFolioBreakdown = function ( data, total, checkIn, checkOut, guests, children ) {
			renderFolioBreakdown( 'mhbo_add', data, total, checkIn, checkOut, guests, children );
		};

		// Flatpickr & PMS Room Availability Calendar Integration
		if ( prefix === 'mhbo_add' ) {
			const dateRangeInput = document.getElementById( 'mhbo_add_date_range_picker' );
			const clearDatesBtn = document.getElementById( 'mhbo_add_clear_dates_btn' );
			const calendarMount = document.getElementById( 'mhbo_add_calendar_mount' );
			const calendarLegend = document.getElementById( 'mhbo_add_calendar_legend' );
			const availBanner = document.getElementById( 'mhbo_add_room_avail_banner' );

			const roomCalendarCache = {};
			let currentRoomCalendarData = null;
			let pmsFlatpickrInstance = null;

			function checkSelectedDatesAvailability( d1, d2 ) {
				if ( ! availBanner || ! currentRoomCalendarData || ! d1 || ! d2 ) {
					return;
				}

				const roomOpt = roomSelect ? roomSelect.selectedOptions[ 0 ] : null;
				const roomName = ( roomOpt && roomOpt.dataset.roomName ) ? roomOpt.dataset.roomName : ( roomOpt ? roomOpt.textContent.trim() : 'Room' );

				const preventTurnover = Boolean( config.preventTurnover );
				let hasConflict = false;
				let conflictDate = '';
				let conflictReason = '';

				const cur = new Date( d1.getFullYear(), d1.getMonth(), d1.getDate() );
				const end = new Date( d2.getFullYear(), d2.getMonth(), d2.getDate() );
				const d1Str = `${ d1.getFullYear() }-${ String( d1.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( d1.getDate() ).padStart( 2, '0' ) }`;
				const d2Str = `${ d2.getFullYear() }-${ String( d2.getMonth() + 1 ).padStart( 2, '0' ) }-${ String( d2.getDate() ).padStart( 2, '0' ) }`;

				// 1. Check stay nights [d1, d2)
				while ( cur < end ) {
					const y = cur.getFullYear();
					const m = String( cur.getMonth() + 1 ).padStart( 2, '0' );
					const d = String( cur.getDate() ).padStart( 2, '0' );
					const ymd = `${ y }-${ m }-${ d }`;
					const info = currentRoomCalendarData[ ymd ];

					if ( info ) {
						if ( ymd === d1Str ) {
							// Check-in day:
							// A turnover day (is_checkout) is acceptable as check-in when
							// same-day turnover is allowed AND can_checkin is true (i.e., the
							// departing guest has vacated the room by check-in time).
							if ( info.is_checkout ) {
								const turnoverAllowed = ! preventTurnover && info.can_checkin !== false;
								if ( ! turnoverAllowed ) {
									hasConflict = true;
									conflictDate = ymd;
									conflictReason = 'Same-day turnover is disabled. Cannot check in on departure date';
									break;
								}
								// Turnover allowed — the departing booking vacated; proceed.
							} else if ( info.status === 'booked' || info.status === 'unbookable' || info.booking_status === 'pending' || info.can_checkin === false ) {
								hasConflict = true;
								conflictDate = ymd;
								break;
							}
						} else {
							// Intermediate night (not the check-in day):
							// is_checkout alone is NOT a conflict — it means a prior booking's
							// guest checked out that morning (same-day turnover scenario).
							// Only block if the night is genuinely occupied OR if it is
							// simultaneously a new check-in (back-to-back, no gap).
							const isIntermediateConflict =
								info.status === 'booked' ||
								info.status === 'unbookable' ||
								info.booking_status === 'pending' ||
								( info.is_checkout && info.is_checkin );
							if ( isIntermediateConflict ) {
								hasConflict = true;
								conflictDate = ymd;
								break;
							}
						}
					}
					cur.setDate( cur.getDate() + 1 );
				}

				// 2. Check checkout day d2.
				// d2 is only a conflict if:
				//   a) preventTurnover is ON and a new booking arrives that day, OR
				//   b) d2 is a genuine interior night — fully booked AND not merely a
				//      check-out morning (is_checkout with no is_checkin means the room
				//      empties; checking out on that day is perfectly legal).
				if ( ! hasConflict ) {
					const infoOut = currentRoomCalendarData ? currentRoomCalendarData[ d2Str ] : null;
					if ( infoOut ) {
						if ( preventTurnover && ( infoOut.is_checkin || infoOut.status === 'booked' ) ) {
							hasConflict = true;
							conflictDate = d2Str;
							conflictReason = 'Same-day turnover is disabled. Cannot check out on arrival date';
						} else if (
							! preventTurnover &&
							infoOut.status === 'booked' &&
							! infoOut.is_checkin &&
							! infoOut.is_checkout
						) {
							// d2 is booked as an interior night (not a check-in or checkout):
							// checking out here would overlap a genuine occupied stay night.
							hasConflict = true;
							conflictDate = d2Str;
						}
					}
				}

				availBanner.style.display = 'block';
				if ( hasConflict ) {
					availBanner.className = 'mhbo-avail-banner-conflict';
					const reasonSuffix = conflictReason ? ` (${ conflictReason })` : '';
					availBanner.innerHTML = '⚠️ <strong>' + escapeHtml( roomName ) + '</strong> is unavailable on <strong>' + conflictDate + '</strong>' + reasonSuffix + '. Please select different stay dates or pick another available room below.';
				} else {
					const nights = Math.max( 1, Math.round( ( d2 - d1 ) / ( 1000 * 60 * 60 * 24 ) ) );
					const fmt1 = d1Str;
					const fmt2 = d2Str;
					let bannerMsg = '✓ <strong>' + escapeHtml( roomName ) + '</strong> is available for ' + nights + ' ' + ( nights === 1 ? 'night' : 'nights' ) + ' (' + fmt1 + ' → ' + fmt2 + ').';

					// Admin-override advisory notices for min/max stay and gap auto-correction.
					// These are INFORMATIONAL ONLY — admin and hotel staff can always override.
					const advisories = [];
					const cfgMin = ( config.minNights && config.minNights > 0 ) ? config.minNights : 0;
					const cfgMax = ( config.maxNights && config.maxNights > 0 ) ? config.maxNights : 0;

					// Per-day min/max from the calendar-data payload (overrides global if present)
					const d1Info = currentRoomCalendarData ? currentRoomCalendarData[ d1Str ] : null;
					const effMin = ( d1Info && d1Info.min_stay > 0 ) ? d1Info.min_stay : cfgMin;
					const effMax = ( d1Info && d1Info.max_stay > 0 ) ? d1Info.max_stay : cfgMax;

					if ( effMin > 0 && nights < effMin ) {
						advisories.push( '📋 Min stay: <strong>' + effMin + ' nights</strong> (you selected ' + nights + '). Admin override applied.' );
					}
					if ( effMax > 0 && nights > effMax ) {
						advisories.push( '📋 Max stay: <strong>' + effMax + ' nights</strong> (you selected ' + nights + '). Admin override applied.' );
					}
					if ( config.gapAutocorrect && config.gapMinNights > 0 && nights <= config.gapMinNights ) {
						advisories.push( '🔧 Gap auto-correction is active (min gap: <strong>' + config.gapMinNights + ' nights</strong>). This booking fills an orphan gap — verify no overlap.' );
					}

					if ( advisories.length > 0 ) {
						availBanner.className = 'mhbo-avail-banner-advisory';
						availBanner.innerHTML = bannerMsg + '<br><small style="display:block;margin-top:4px;opacity:0.9;">' + advisories.join( ' &nbsp;|&nbsp; ' ) + '</small>';
					} else {
						availBanner.className = 'mhbo-avail-banner-success';
						availBanner.innerHTML = bannerMsg;
					}
				}
			}

			function updateRoomDropdownAvailability( cin, cout ) {
				if ( ! roomSelect || ! cin || ! cout || cin >= cout ) {
					return;
				}
				const availUrl = '/mhbo/v1/availability?check_in=' + encodeURIComponent( cin ) + '&check_out=' + encodeURIComponent( cout );
				const apiPromise = ( window.wp && window.wp.apiFetch )
					? window.wp.apiFetch( { path: availUrl } )
					: fetch( '/wp-json' + availUrl, { headers: { 'X-WP-Nonce': ( config && config.nonce ) ? config.nonce : '' } } ).then( function ( r ) { return r.json(); } );

				apiPromise.then( function ( res ) {
					if ( res && Array.isArray( res.available ) ) {
						const availSet = new Set( res.available.map( function ( r ) { return String( r.room_id ); } ) );
						Array.from( roomSelect.options ).forEach( function ( opt ) {
							if ( ! opt.value ) {
								return;
							}
							const isFree = availSet.has( String( opt.value ) );
							const baseText = opt.dataset.roomName ? ( opt.dataset.roomName + ' - ' + ( opt.dataset.price ? ( '$' + parseFloat( opt.dataset.price ).toFixed( 2 ) + ' / night' ) : '' ) ) : opt.textContent.replace( /^[🟢🔴]\s*/, '' ).replace( /\s*\[(✓ Available|Occupied)\]$/, '' );
							if ( isFree ) {
								opt.textContent = '🟢 ' + baseText + ' [✓ Available]';
							} else {
								opt.textContent = '🔴 ' + baseText + ' [Occupied]';
							}
						} );
					}
				} ).catch( function () {} );
			}

			function renderPmsCalendar( roomId, daysArray ) {
				const map = {};
				const disabledDates = [];
				const preventTurnover = Boolean( config.preventTurnover );
				const today = new Date();
				today.setHours( 0, 0, 0, 0 );

				daysArray.forEach( function ( item ) {
					map[ item.date ] = item;
					const itemDate = new Date( item.date + 'T00:00:00' );
					if ( itemDate < today ) {
						disabledDates.push( item.date );
					} else if ( item.status === 'booked' || item.status === 'unbookable' || item.can_checkin === false ) {
						// Turnover days (is_checkout + !preventTurnover + can_checkin) must stay
						// selectable as new check-in dates even though the API returns
						// status='booked' (because the departing stay occupies the morning).
						const isTurnoverCheckin =
							item.is_checkout &&
							! preventTurnover &&
							item.can_checkin !== false;
						if ( ! isTurnoverCheckin ) {
							disabledDates.push( item.date );
						}
					}
				} );
				currentRoomCalendarData = map;

				if ( calendarMount && typeof window.flatpickr === 'function' ) {
					calendarMount.style.display = 'block';
					if ( calendarLegend ) {
						calendarLegend.style.display = 'flex';
					}

					const curIn = checkInInput ? checkInInput.value : '';
					const curOut = checkOutInput ? checkOutInput.value : '';
					const defaultRange = ( curIn && curOut ) ? [ curIn, curOut ] : [];

					let currentCheckIn = curIn || null;
					let currentCheckOut = curOut || null;
					let currentTurnoverTarget = null;

					if ( pmsFlatpickrInstance ) {
						pmsFlatpickrInstance.destroy();
						calendarMount.innerHTML = '';
					}

					// Mount dedicated input inside calendarMount so flatpickr renders INSIDE .mhbo-pms-calendar-wrapper
					const mountTarget = document.createElement( 'input' );
					mountTarget.type = 'hidden';
					mountTarget.className = 'mhbo-pms-cal-input-target';
					mountTarget.style.display = 'none';
					calendarMount.appendChild( mountTarget );

					const initialDisable = curOut
						? disabledDates.filter( function ( d ) { return d !== curOut; } )
						: disabledDates;

					pmsFlatpickrInstance = window.flatpickr( mountTarget, {
						appendTo: calendarMount,
						inline: true,
						mode: 'range',
						dateFormat: 'Y-m-d',
						defaultDate: defaultRange,
						disable: initialDisable,
						locale: { firstDayOfWeek: 1 },
						showMonths: 1,
						onReady: function ( selectedDates, dateStr, instance ) {
							if ( instance.calendarContainer ) {
								instance.calendarContainer.classList.add( 'mhbo-pms-calendar' );
							}
						},
						onDayCreate: function ( dObj, dStr, fp, dayElem ) {
							const dateStr = fp.formatDate( dayElem.dateObj, 'Y-m-d' );
							const dayNum = dayElem.dateObj.getDate();
							dayElem.innerHTML = '';

							const numSpan = document.createElement( 'span' );
							numSpan.className = 'mhbo-day-number';
							numSpan.textContent = dayNum;
							dayElem.appendChild( numSpan );

							// 1-click unselect handler: clicking the already selected start date deselects it
							dayElem.addEventListener( 'click', function ( e ) {
								if ( currentCheckIn && ! currentCheckOut && dateStr === currentCheckIn ) {
									e.preventDefault();
									e.stopPropagation();
									fp.clear();
									currentCheckIn = null;
									currentCheckOut = null;
									currentTurnoverTarget = null;
									fp.set( 'disable', disabledDates );
									fp.set( 'maxDate', null );
									if ( checkInInput ) { checkInInput.value = ''; }
									if ( checkOutInput ) { checkOutInput.value = ''; }
									if ( dateRangeInput ) { dateRangeInput.value = ''; }
									if ( availBanner ) { availBanner.style.display = 'none'; }
									if ( clearDatesBtn ) { clearDatesBtn.style.display = 'none'; }
									const nightsPill = document.getElementById( 'mhbo_add_nights_pill' );
									const nightsIndicator = document.getElementById( 'mhbo_add_calendar_nights_indicator' );
									const heroDates = document.getElementById( 'mhbo_add_hero_dates_text' );
									if ( nightsPill ) { nightsPill.textContent = '🌙 0 Nights'; }
									if ( nightsIndicator ) { nightsIndicator.textContent = 'Select stay dates'; }
									if ( heroDates ) { heroDates.textContent = '📅 Select stay dates'; }
								}
							} );

							const isPast = dayElem.dateObj < today;
							const info = currentRoomCalendarData ? currentRoomCalendarData[ dateStr ] : null;

							if ( info && ! isPast ) {
								const isSelectedCheckout = ( currentCheckOut && dateStr === currentCheckOut );
								const isTurnoverTarget = ( currentTurnoverTarget && dateStr === currentTurnoverTarget );

								// 1. Turnover Changeover status
								if ( info.is_checkout && info.is_checkin ) {
									dayElem.classList.add( 'mhbo-half-booked-both' );
								} else if ( info.is_checkout ) {
									dayElem.classList.add( 'mhbo-half-booked-checkout' );
								} else if ( info.is_checkin ) {
									dayElem.classList.add( 'mhbo-half-booked-checkin' );
								}

								// 2. Day Status & Pricing
								if ( isSelectedCheckout ) {
									// Departure date for current selected stay: never show Booked sign!
									dayElem.classList.remove( 'flatpickr-disabled' );
									dayElem.classList.remove( 'mhbo-pms-day-booked' );
									dayElem.classList.add( 'mhbo-pms-day-checkout-selected' );
								} else if ( isTurnoverTarget ) {
									// Eligible turnover checkout date:
									dayElem.classList.remove( 'flatpickr-disabled' );
									dayElem.classList.remove( 'mhbo-pms-day-booked' );
									dayElem.classList.add( 'mhbo-valid-checkout-target' );
									const badge = document.createElement( 'span' );
									badge.className = 'mhbo-pms-day-status-pill mhbo-pms-pill-turnover-target';
									badge.textContent = 'Check-out';
									dayElem.appendChild( badge );
								} else if ( info.is_checkout && ! preventTurnover && info.can_checkin !== false ) {
									// Allowed turnover: status may be 'booked' (incoming booking) — still renderable as Out/In when can_checkin is true.
									dayElem.classList.remove( 'flatpickr-disabled' );
									dayElem.classList.add( 'mhbo-pms-day-turnover-available' );

									const turnoverBadge = document.createElement( 'span' );
									turnoverBadge.className = 'mhbo-pms-day-status-pill mhbo-pms-pill-turnover';
									turnoverBadge.textContent = 'Out/In';
									dayElem.appendChild( turnoverBadge );

									if ( info.price_formatted ) {
										const priceSpan = document.createElement( 'span' );
										priceSpan.className = 'mhbo-fp-price';
										priceSpan.textContent = info.price_formatted;
										dayElem.appendChild( priceSpan );
									}
								} else if ( info.status === 'booked' || info.booking_status === 'confirmed' || ( info.is_checkout && preventTurnover ) ) {
									dayElem.classList.add( 'mhbo-pms-day-booked' );
									const badge = document.createElement( 'span' );
									badge.className = 'mhbo-pms-day-status-pill mhbo-pms-pill-booked';
									badge.textContent = ( info.is_checkout && preventTurnover ) ? 'Buffer' : 'Booked';
									dayElem.appendChild( badge );
								} else if ( info.booking_status === 'pending' ) {
									dayElem.classList.add( 'mhbo-pms-day-pending' );
									const badge = document.createElement( 'span' );
									badge.className = 'mhbo-pms-day-status-pill mhbo-pms-pill-pending';
									badge.textContent = 'Hold';
									dayElem.appendChild( badge );
								} else if ( info.status === 'unbookable' ) {
									dayElem.classList.add( 'mhbo-pms-day-closed' );
									const badge = document.createElement( 'span' );
									badge.className = 'mhbo-pms-day-status-pill mhbo-pms-pill-closed';
									badge.textContent = 'Closed';
									dayElem.appendChild( badge );
								} else {
									dayElem.classList.add( 'mhbo-pms-day-available' );
									if ( info.price_formatted ) {
										const priceSpan = document.createElement( 'span' );
										priceSpan.className = 'mhbo-fp-price';
										priceSpan.textContent = info.price_formatted;
										dayElem.appendChild( priceSpan );
									}
								}
							}
						},
						onChange: function ( selectedDates, dateStr, instance ) {
							if ( selectedDates.length === 1 ) {
								const checkIn = selectedDates[ 0 ];
								const checkInStr = instance.formatDate( checkIn, 'Y-m-d' );
								currentCheckIn = checkInStr;
								currentCheckOut = null;
								let firstBookedAfter = null;
								let minDiff = Infinity;

								for ( let i = 0; i < disabledDates.length; i++ ) {
									const bdParts = disabledDates[ i ].split( '-' );
									const bd = new Date( bdParts[ 0 ], bdParts[ 1 ] - 1, bdParts[ 2 ] );
									if ( bd > checkIn ) {
										const diff = bd - checkIn;
										if ( diff < minDiff ) {
											minDiff = diff;
											firstBookedAfter = disabledDates[ i ];
										}
									}
								}

								currentTurnoverTarget = ( firstBookedAfter && ! preventTurnover ) ? firstBookedAfter : null;

								// Never clamp maxDate on single click - keeps full calendar responsive and un-trapped
								if ( firstBookedAfter && ! preventTurnover ) {
									const allowedDisabled = disabledDates.filter( function ( d ) { return d !== firstBookedAfter; } );
									instance.set( 'disable', allowedDisabled );
								} else {
									instance.set( 'disable', disabledDates );
								}
								instance.set( 'maxDate', null );

								if ( clearDatesBtn ) {
									clearDatesBtn.style.display = 'flex';
								}

								if ( firstBookedAfter && ! preventTurnover && instance.calendarContainer ) {
									const _checkoutTarget = firstBookedAfter;
									requestAnimationFrame( function () {
										if ( ! instance.calendarContainer ) { return; }
										instance.calendarContainer
											.querySelectorAll( '.flatpickr-day' )
											.forEach( function ( dayEl ) {
												const ariaLabel = dayEl.getAttribute( 'aria-label' );
												if ( ! ariaLabel ) { return; }
												try {
													const parsed = instance.parseDate( ariaLabel, instance.l10n ? ( instance.l10n.ariaDateFormat || 'F j, Y' ) : 'F j, Y' );
													if ( ! parsed ) { return; }
													const ds = instance.formatDate( parsed, 'Y-m-d' );
													if ( ds === _checkoutTarget ) {
														dayEl.classList.remove( 'flatpickr-disabled' );
														dayEl.classList.remove( 'mhbo-pms-day-booked' );
														dayEl.classList.add( 'mhbo-valid-checkout-target' );
														const bookedBadge = dayEl.querySelector( '.mhbo-pms-pill-booked' );
														if ( bookedBadge ) {
															bookedBadge.className = 'mhbo-pms-day-status-pill mhbo-pms-pill-turnover-target';
															bookedBadge.textContent = 'Check-out';
														}
													}
												} catch ( e ) {}
											} );
									} );
								}
							} else if ( selectedDates.length === 2 ) {
								let d1 = selectedDates[ 0 ];
								let d2 = selectedDates[ 1 ];

								// Unselect gesture: Clicking the same date again produces a 0-night stay
								if ( d1.getTime() === d2.getTime() ) {
									instance.clear();
									currentCheckIn = null;
									currentCheckOut = null;
									currentTurnoverTarget = null;
									instance.set( 'disable', disabledDates );
									instance.set( 'maxDate', null );
									if ( checkInInput ) { checkInInput.value = ''; }
									if ( checkOutInput ) { checkOutInput.value = ''; }
									if ( dateRangeInput ) { dateRangeInput.value = ''; }
									if ( availBanner ) { availBanner.style.display = 'none'; }
									if ( clearDatesBtn ) { clearDatesBtn.style.display = 'none'; }
									const nightsPill = document.getElementById( 'mhbo_add_nights_pill' );
									const nightsIndicator = document.getElementById( 'mhbo_add_calendar_nights_indicator' );
									const heroDates = document.getElementById( 'mhbo_add_hero_dates_text' );
									if ( nightsPill ) { nightsPill.textContent = '🌙 0 Nights'; }
									if ( nightsIndicator ) { nightsIndicator.textContent = 'Select stay dates'; }
									if ( heroDates ) { heroDates.textContent = '📅 Select stay dates'; }
									return;
								}

								if ( d1 > d2 ) {
									const tmp = d1;
									d1 = d2;
									d2 = tmp;
								}

								const y1 = d1.getFullYear();
								const m1 = String( d1.getMonth() + 1 ).padStart( 2, '0' );
								const day1 = String( d1.getDate() ).padStart( 2, '0' );
								const y2 = d2.getFullYear();
								const m2 = String( d2.getMonth() + 1 ).padStart( 2, '0' );
								const day2 = String( d2.getDate() ).padStart( 2, '0' );

								const iso1 = `${ y1 }-${ m1 }-${ day1 }`;
								const iso2 = `${ y2 }-${ m2 }-${ day2 }`;

								currentCheckIn = iso1;
								currentCheckOut = iso2;
								currentTurnoverTarget = null;

								const postSelectDisabled = disabledDates.filter( function ( d ) {
									return d !== iso2;
								} );
								instance.set( 'disable', postSelectDisabled );
								instance.set( 'maxDate', null );

								instance.setDate( [ d1, d2 ], false );

								if ( checkInInput ) {
									checkInInput.value = iso1;
								}
								if ( checkOutInput ) {
									checkOutInput.value = iso2;
								}
								if ( dateRangeInput ) {
									dateRangeInput.value = instance.formatDate( d1, 'M j, Y' ) + ' → ' + instance.formatDate( d2, 'M j, Y' );
								}
								if ( clearDatesBtn ) {
									clearDatesBtn.style.display = 'flex';
								}

								const nights = Math.max( 1, Math.round( ( d2 - d1 ) / ( 1000 * 60 * 60 * 24 ) ) );
								const nightsPill = document.getElementById( 'mhbo_add_nights_pill' );
								const heroDates = document.getElementById( 'mhbo_add_hero_dates_text' );
								const nightsIndicator = document.getElementById( 'mhbo_add_calendar_nights_indicator' );

								if ( nightsPill ) {
									nightsPill.textContent = '🌙 ' + nights + ( nights === 1 ? ' Night' : ' Nights' );
								}
								if ( nightsIndicator ) {
									nightsIndicator.textContent = '🌙 ' + nights + ( nights === 1 ? ' Night Stay' : ' Nights Stay' );
								}
								if ( heroDates ) {
									heroDates.textContent = '📅 ' + instance.formatDate( d1, 'M j, Y' ) + ' → ' + instance.formatDate( d2, 'M j, Y' );
								}

								checkSelectedDatesAvailability( d1, d2 );
								updateRoomDropdownAvailability( iso1, iso2 );
								updatePrices();
							} else {
								currentCheckIn = null;
								currentCheckOut = null;
								currentTurnoverTarget = null;
								instance.set( 'disable', disabledDates );
								instance.set( 'maxDate', null );
								if ( clearDatesBtn ) {
									clearDatesBtn.style.display = 'none';
								}
							}
						}
					} );

					// Verify availability for initial range
					if ( defaultRange.length === 2 && curIn && curOut ) {
						checkSelectedDatesAvailability( new Date( curIn + 'T00:00:00' ), new Date( curOut + 'T00:00:00' ) );
						updateRoomDropdownAvailability( curIn, curOut );
						if ( dateRangeInput ) {
							dateRangeInput.value = pmsFlatpickrInstance.formatDate( new Date( curIn + 'T00:00:00' ), 'M j, Y' ) + ' → ' + pmsFlatpickrInstance.formatDate( new Date( curOut + 'T00:00:00' ), 'M j, Y' );
						}
						if ( clearDatesBtn ) {
							clearDatesBtn.style.display = 'flex';
						}
					}
				}
			}

			function parseManualDateRange( str, wpDateFormat ) {
				if ( ! str || typeof str !== 'string' ) {
					return null;
				}
				const trimmed = str.trim();
				if ( ! trimmed ) {
					return null;
				}

				// Split by common range delimiters: " to ", " - ", " – ", " — ", " -> ", " → ", " til ", " until ", " through "
				const parts = trimmed.split( /\s*(?:to|until|through|til|–|—|->|→|\s-\s)\s*/i );
				if ( parts.length !== 2 ) {
					return null;
				}

				function parseSingleDate( dStr ) {
					const s = dStr.trim();
					if ( ! s ) {
						return null;
					}

					// 1. ISO format: YYYY-MM-DD
					const isoMatch = s.match( /^(\d{4})[-\/\.](\d{1,2})[-\/\.](\d{1,2})$/ );
					if ( isoMatch ) {
						const y = parseInt( isoMatch[ 1 ], 10 );
						const m = parseInt( isoMatch[ 2 ], 10 ) - 1;
						const d = parseInt( isoMatch[ 3 ], 10 );
						const dt = new Date( y, m, d );
						return ( ! isNaN( dt.getTime() ) && dt.getDate() === d ) ? dt : null;
					}

					// 2. 3-part numeric: DD/MM/YYYY or MM/DD/YYYY
					const numMatch = s.match( /^(\d{1,2})[-\/\.](\d{1,2})[-\/\.](\d{4})$/ );
					if ( numMatch ) {
						const n1 = parseInt( numMatch[ 1 ], 10 );
						const n2 = parseInt( numMatch[ 2 ], 10 );
						const y = parseInt( numMatch[ 3 ], 10 );
						let day, month;

						if ( n1 > 12 && n2 <= 12 ) {
							day = n1;
							month = n2 - 1;
						} else if ( n2 > 12 && n1 <= 12 ) {
							month = n1 - 1;
							day = n2;
						} else {
							// Ambiguous: use WP date_format hint (e.g. 'd/m/Y' or 'j/n/Y' -> day first)
							const isDayFirst = wpDateFormat ? ( /^[dj]/i.test( wpDateFormat ) || wpDateFormat.indexOf( 'd' ) < wpDateFormat.indexOf( 'm' ) ) : true;
							if ( isDayFirst ) {
								day = n1;
								month = n2 - 1;
							} else {
								month = n1 - 1;
								day = n2;
							}
						}
						const dt = new Date( y, month, day );
						return ( ! isNaN( dt.getTime() ) && dt.getDate() === day ) ? dt : null;
					}

					// 3. Fallback: native Date.parse (handles "Oct 13, 2026", "13 Oct 2026", etc.)
					const parsed = Date.parse( s );
					if ( ! isNaN( parsed ) ) {
						const dt = new Date( parsed );
						return new Date( dt.getFullYear(), dt.getMonth(), dt.getDate() );
					}

					return null;
				}

				const d1 = parseSingleDate( parts[ 0 ] );
				const d2 = parseSingleDate( parts[ 1 ] );
				if ( ! d1 || ! d2 ) {
					return null;
				}

				return d1 <= d2 ? [ d1, d2 ] : [ d2, d1 ];
			}

			if ( dateRangeInput ) {
				function handleManualDateInput() {
					const val = dateRangeInput.value.trim();
					if ( ! val ) {
						if ( pmsFlatpickrInstance ) {
							pmsFlatpickrInstance.clear();
							pmsFlatpickrInstance.set( 'disable', disabledDates );
							pmsFlatpickrInstance.set( 'maxDate', null );
						}
						currentCheckIn = null;
						currentCheckOut = null;
						currentTurnoverTarget = null;
						if ( checkInInput ) { checkInInput.value = ''; }
						if ( checkOutInput ) { checkOutInput.value = ''; }
						if ( availBanner ) { availBanner.style.display = 'none'; }
						if ( clearDatesBtn ) { clearDatesBtn.style.display = 'none'; }
						const nightsPill = document.getElementById( 'mhbo_add_nights_pill' );
						const nightsIndicator = document.getElementById( 'mhbo_add_calendar_nights_indicator' );
						const heroDates = document.getElementById( 'mhbo_add_hero_dates_text' );
						if ( nightsPill ) { nightsPill.textContent = '🌙 0 Nights'; }
						if ( nightsIndicator ) { nightsIndicator.textContent = 'Select stay dates'; }
						if ( heroDates ) { heroDates.textContent = '📅 Select stay dates'; }
						return;
					}
					const wpFmt = ( config && config.dateFormat ) ? config.dateFormat : '';
					const range = parseManualDateRange( val, wpFmt );
					if ( range && range.length === 2 ) {
						if ( clearDatesBtn ) {
							clearDatesBtn.style.display = 'flex';
						}
						if ( pmsFlatpickrInstance ) {
							const iso2 = pmsFlatpickrInstance.formatDate( range[ 1 ], 'Y-m-d' );
							const allowedDisabled = disabledDates.filter( function ( d ) { return d !== iso2; } );
							pmsFlatpickrInstance.set( 'disable', allowedDisabled );
							pmsFlatpickrInstance.set( 'maxDate', null );
							pmsFlatpickrInstance.setDate( range, true );
						} else {
							const y1 = range[ 0 ].getFullYear();
							const m1 = String( range[ 0 ].getMonth() + 1 ).padStart( 2, '0' );
							const day1 = String( range[ 0 ].getDate() ).padStart( 2, '0' );
							const y2 = range[ 1 ].getFullYear();
							const m2 = String( range[ 1 ].getMonth() + 1 ).padStart( 2, '0' );
							const day2 = String( range[ 1 ].getDate() ).padStart( 2, '0' );
							if ( checkInInput ) {
								checkInInput.value = `${ y1 }-${ m1 }-${ day1 }`;
							}
							if ( checkOutInput ) {
								checkOutInput.value = `${ y2 }-${ m2 }-${ day2 }`;
							}
							updatePrices();
						}
					}
				}

				dateRangeInput.addEventListener( 'change', handleManualDateInput );
				dateRangeInput.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Enter' ) {
						e.preventDefault();
						handleManualDateInput();
					}
				} );
			}

			if ( clearDatesBtn ) {
				clearDatesBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					e.stopPropagation();
					if ( pmsFlatpickrInstance ) {
						pmsFlatpickrInstance.clear();
						pmsFlatpickrInstance.set( 'disable', disabledDates );
						pmsFlatpickrInstance.set( 'maxDate', null );
					}
					currentCheckIn = null;
					currentCheckOut = null;
					currentTurnoverTarget = null;
					if ( checkInInput ) { checkInInput.value = ''; }
					if ( checkOutInput ) { checkOutInput.value = ''; }
					if ( dateRangeInput ) { dateRangeInput.value = ''; }
					if ( availBanner ) { availBanner.style.display = 'none'; }
					clearDatesBtn.style.display = 'none';
					const nightsPill = document.getElementById( 'mhbo_add_nights_pill' );
					const nightsIndicator = document.getElementById( 'mhbo_add_calendar_nights_indicator' );
					const heroDates = document.getElementById( 'mhbo_add_hero_dates_text' );
					if ( nightsPill ) { nightsPill.textContent = '🌙 0 Nights'; }
					if ( nightsIndicator ) { nightsIndicator.textContent = 'Select stay dates'; }
					if ( heroDates ) { heroDates.textContent = '📅 Select stay dates'; }
				} );
			}

			function loadRoomCalendar( roomId ) {
				if ( ! roomId || parseInt( roomId, 10 ) <= 0 ) {
					if ( calendarMount ) {
						calendarMount.style.display = 'none';
					}
					if ( calendarLegend ) {
						calendarLegend.style.display = 'none';
					}
					if ( availBanner ) {
						availBanner.style.display = 'none';
					}
					return;
				}

				if ( roomCalendarCache[ roomId ] ) {
					renderPmsCalendar( roomId, roomCalendarCache[ roomId ] );
					return;
				}

				const apiPath = '/mhbo/v1/calendar-data?room_id=' + encodeURIComponent( roomId );
				const apiPromise = ( window.wp && window.wp.apiFetch )
					? window.wp.apiFetch( { path: apiPath } )
					: fetch( '/wp-json' + apiPath, { headers: { 'X-WP-Nonce': ( config && config.nonce ) ? config.nonce : '' } } ).then( function ( r ) { return r.json(); } );

				apiPromise.then( function ( data ) {
					if ( Array.isArray( data ) ) {
						roomCalendarCache[ roomId ] = data;
						renderPmsCalendar( roomId, data );
					}
				} ).catch( function ( err ) {
					console.warn( 'MHBO: Failed to load room calendar data:', err );
				} );
			}

			// When room changes: reload room calendar & update prices
			if ( roomSelect ) {
				roomSelect.addEventListener( 'change', function () {
					const rId = roomSelect.value;
					loadRoomCalendar( rId );
					updatePrices();
				} );

				// Initial boot: if a room is pre-selected, load its calendar
				if ( roomSelect.value ) {
					loadRoomCalendar( roomSelect.value );
				}
			}

			// CRM Returning Guest Lookup Select
			const crmSelect = document.getElementById( 'mhbo_crm_guest_select' );
			if ( crmSelect ) {
				crmSelect.addEventListener( 'change', function () {
					const opt = crmSelect.selectedOptions[ 0 ];
					const gidInput = document.getElementById( 'mhbo_add_guest_id' );
					const nameInput = document.getElementById( 'mhbo_add_customer_name' );
					const emailInput = document.getElementById( 'mhbo_add_customer_email' );
					const phoneInput = document.getElementById( 'mhbo_add_customer_phone' );
					const badge = document.getElementById( 'mhbo_crm_match_badge' );
					const caution = document.getElementById( 'mhbo_crm_caution_alert' );

					if ( ! opt || ! opt.value ) {
						if ( gidInput ) {
							gidInput.value = '0';
						}
						if ( badge ) {
							badge.style.display = 'none';
						}
						if ( caution ) {
							caution.style.display = 'none';
						}
						return;
					}

					const gid = opt.value;
					const name = opt.dataset.name || '';
					const email = opt.dataset.email || '';
					const phone = opt.dataset.phone || '';
					const vip = opt.dataset.vip || 'none';
					const cautionFlag = opt.dataset.caution === '1';

					if ( gidInput ) {
						gidInput.value = gid;
					}
					if ( nameInput && name ) {
						nameInput.value = name;
					}
					if ( emailInput && email ) {
						emailInput.value = email;
					}
					if ( phoneInput && phone ) {
						phoneInput.value = phone;
					}

					if ( badge ) {
						badge.style.display = 'inline-block';
						if ( vip && vip !== 'none' ) {
							badge.style.background = '#fef3c7';
							badge.style.color = '#92400e';
							badge.textContent = '⭐ ' + vip.toUpperCase().replace( '_', ' ' );
						} else {
							badge.style.background = '#dcfce7';
							badge.style.color = '#166534';
							badge.textContent = '✓ CRM Linked #' + gid;
						}
					}

					if ( caution ) {
						caution.style.display = cautionFlag ? 'block' : 'none';
					}
				} );
			}

			// Add Mode Quick Tender Buttons
			if ( form ) {
				form.querySelectorAll( '.mhbo-quick-tender-btn' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function ( e ) {
						e.preventDefault();
						const action = this.getAttribute( 'data-action' );
						const total = parseFloat( totalPriceInput ? totalPriceInput.value : 0 ) || 0;
						const deposit = parseFloat( depositInput ? depositInput.value : 0 ) || 0;

						if ( action === 'full' ) {
							if ( paymentAmountInput ) {
								paymentAmountInput.value = total.toFixed( 2 );
							}
							if ( paymentReceivedInput ) {
								paymentReceivedInput.checked = true;
							}
							if ( depositReceivedInput ) {
								depositReceivedInput.checked = false;
							}
						} else if ( action === 'deposit' ) {
							if ( paymentAmountInput ) {
								paymentAmountInput.value = deposit.toFixed( 2 );
							}
							if ( paymentReceivedInput ) {
								paymentReceivedInput.checked = false;
							}
							if ( depositReceivedInput ) {
								depositReceivedInput.checked = true;
							}
						} else if ( action === 'zero' ) {
							if ( paymentAmountInput ) {
								paymentAmountInput.value = '0.00';
							}
							if ( paymentReceivedInput ) {
								paymentReceivedInput.checked = false;
							}
							if ( depositReceivedInput ) {
								depositReceivedInput.checked = false;
							}
						}
						calculateOutstanding();
					} );
				} );
			}

			// Add Mode Coupon Code Apply Button
			const applyCouponBtn = document.getElementById( 'mhbo_add_apply_coupon_btn' );
			if ( applyCouponBtn ) {
				applyCouponBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					updatePrices();
				} );
			}
			const addCouponInput = document.getElementById( 'mhbo_add_coupon_code' );
			if ( addCouponInput ) {
				addCouponInput.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Enter' ) {
						e.preventDefault();
						updatePrices();
					}
				} );
			}

			// Occupancy Pill synchronization
			if ( guestsInput ) {
				guestsInput.addEventListener( 'input', function () {
					const gPill = document.getElementById( 'mhbo_add_occupancy_pill' );
					if ( gPill ) {
						const gVal = parseInt( guestsInput.value ) || 1;
						const cVal = parseInt( childrenInput ? childrenInput.value : 0 ) || 0;
						const totalGuests = gVal + cVal;
						gPill.textContent = '👥 ' + totalGuests + ( totalGuests === 1 ? ' Guest' : ' Guests' );
					}
				} );
			}
			if ( childrenInput ) {
				childrenInput.addEventListener( 'input', function () {
					const gPill = document.getElementById( 'mhbo_add_occupancy_pill' );
					if ( gPill ) {
						const gVal = parseInt( guestsInput ? guestsInput.value : 1 ) || 1;
						const cVal = parseInt( childrenInput.value ) || 0;
						const totalGuests = gVal + cVal;
						gPill.textContent = '👥 ' + totalGuests + ( totalGuests === 1 ? ' Guest' : ' Guests' );
					}
				} );
			}
		}

		// Initial calculation from DOM values
		calculateOutstanding();

		// In Add mode only, if room and dates are prefilled, recalculate price
		if ( prefix === 'mhbo_add' && roomSelect && roomSelect.value && checkInInput && checkInInput.value && checkOutInput && checkOutInput.value ) {
			updatePrices();
		}
	}

	/**
	 * Dynamic Child Ages Setup
	 * @param prefix
	 */
	function setupChildAges( prefix ) {
		const childrenInput = document.getElementById( prefix + '_children' );
		const agesRow = document.getElementById( prefix + '_child_ages_row' );
		const agesContainer = document.getElementById(
			prefix + '_child_ages_container'
		);

		if ( ! childrenInput || ! agesRow || ! agesContainer ) {
			return;
		}

		childrenInput.addEventListener( 'change', function () {
			const count = parseInt( this.value ) || 0;
			if ( count > 0 ) {
				agesRow.style.display = '';
				// Preserve existing values
				const existing = agesContainer.querySelectorAll(
					'input[name="child_ages[]"]'
				);
				const vals = [];
				existing.forEach( function ( inp ) {
					vals.push( inp.value );
				} );
				agesContainer.innerHTML = '';
				for ( let i = 0; i < count; i++ ) {
					const lbl = document.createElement( 'label' );
					lbl.style.display = 'inline-block';
					lbl.style.marginRight = '10px';
					lbl.style.marginBottom = '5px';
					lbl.textContent = 'Child ' + ( i + 1 ) + ': ';
					const inp = document.createElement( 'input' );
					inp.type = 'number';
					inp.name = 'child_ages[]';
					inp.value = vals[ i ] || 0;
					inp.min = 0;
					inp.max = 17;
					inp.style.width = '60px';
					lbl.appendChild( inp );
					agesContainer.appendChild( lbl );
				}
			} else {
				agesRow.style.display = 'none';
				agesContainer.innerHTML = '';
			}
		} );
	}

	function onReady( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	// Initialize on DOM ready
	onReady( function () {
		// Run Price Setup for both Add and Edit forms
		setupCalculation( 'mhbo_add' );
		setupCalculation( 'mhbo_edit' );

		// Run Child Ages Setup
		setupChildAges( 'mhbo_add' );
		setupChildAges( 'mhbo_edit' );
	} );

	// Admin interactions
	onReady( function () {
		// Mark Balance Collected (Pro)
		document.addEventListener( 'click', function ( e ) {
			const btn = e.target.closest( '.mhbo-mark-collected' );
			if ( ! btn ) {
				return;
			}

			const id = btn.dataset.id;
			const restNonce =
				( config && config.nonce ) ||
				( window.mhboAdminBookingsConfig && window.mhboAdminBookingsConfig.nonce ) ||
				btn.dataset.nonce ||
				'';

			if ( ! confirm( 'Are you sure you want to mark this balance as collected?' ) ) {
				return;
			}

			btn.disabled = true;
			btn.textContent = 'Updating...';

			const balancePromise = ( window.wp && window.wp.apiFetch )
				? window.wp.apiFetch( {
					path: '/mhbo/v1/admin/bookings/' + encodeURIComponent( id ) + '/balance-collected',
					method: 'POST',
				} )
				: fetch( '/wp-json/mhbo/v1/admin/bookings/' + encodeURIComponent( id ) + '/balance-collected', {
					method: 'POST',
					headers: {
						'X-WP-Nonce': restNonce,
						'Content-Type': 'application/json',
					},
				} ).then( function ( response ) {
					return response.json();
				} );

			balancePromise
				.then( function ( data ) {
					if ( data && data.success ) {
						location.reload();
					} else {
						throw new Error( ( data && data.message ) || 'Error updating balance.' );
					}
				} )
				.catch( function ( err ) {
					alert( ( err && err.message ) || 'Error updating balance.' );
					btn.disabled = false;
					btn.textContent = 'Mark Balance as Collected';
				} );
		} );

		// Copy Balance Payment Link
		document.addEventListener( 'click', function ( e ) {
			const btn = e.target.closest( '.mhbo-copy-balance-link' );
			if ( ! btn ) {
				return;
			}

			const url = btn.dataset.url;
			if ( ! url ) {
				return;
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( url ).then( function () {
					btn.textContent = 'Copied';
					setTimeout( function () {
						btn.textContent = 'Copy';
					}, 1500 );
				} );
			} else {
				const input = document.createElement( 'input' );
				input.value = url;
				document.body.appendChild( input );
				input.select();
				document.execCommand( 'copy' );
				document.body.removeChild( input );
				btn.textContent = 'Copied';
				setTimeout( function () {
					btn.textContent = 'Copy';
				}, 1500 );
			}
		} );
	} );

	/**
	 * FullCalendar Initialization for Bookings Page
	 */
	function initFullCalendar() {
		const calendarEl = document.getElementById( 'mhbo-calendar' );
		if ( ! calendarEl || typeof FullCalendar === 'undefined' ) {
			return;
		}

		const config = window.mhboCalendarConfig || {};
		const rawEvents = config.events || [];
		let currentViewType = 'dayGridMonth';

		function getEventsForView( viewType, baseEvents ) {
			if ( ! Array.isArray( baseEvents ) ) {
				return [];
			}
			if ( viewType && viewType.startsWith( 'list' ) ) {
				// In List View: exclude companion Sunday checkouts, and extend event end to check_out + 1 day
				// so that the check-out day is rendered under its respective day header with isEnd: true.
				return baseEvents
					.filter( function ( ev ) {
						return ! ev.extendedProps?.is_sunday_checkout;
					} )
					.map( function ( ev ) {
						const checkOut = ev.extendedProps?.check_out || ev.end;
						let listEnd = checkOut;
						if ( checkOut && typeof checkOut === 'string' && checkOut.length >= 10 ) {
							const parts = checkOut.substring( 0, 10 ).split( '-' );
							if ( parts.length === 3 ) {
								const y = parseInt( parts[ 0 ], 10 );
								const m = parseInt( parts[ 1 ], 10 ) - 1;
								const d = parseInt( parts[ 2 ], 10 ) + 1;
								const nextDate = new Date( Date.UTC( y, m, d ) );
								listEnd = nextDate.toISOString().substring( 0, 10 );
							}
						}

						return {
							...ev,
							end: listEnd,
							className: ( ev.className || '' ).replace( /mhbo-event-saturday-end|mhbo-sunday-checkout/g, '' ).trim(),
						};
					} );
			}
			return baseEvents;
		}

		function getFilteredEvents( filterVal, viewType ) {
			let list = rawEvents;
			if ( filterVal ) {
				if ( filterVal.startsWith( 'type:' ) ) {
					const targetType = filterVal.substring( 5 ).trim().toLowerCase();
					list = list.filter( function ( ev ) {
						return String( ev.extendedProps?.room_type ?? '' ).toLowerCase() === targetType;
					} );
				} else {
					const targetRoom = filterVal.startsWith( 'room:' ) ? filterVal.substring( 5 ).trim() : filterVal.trim();
					list = list.filter( function ( ev ) {
						return String( ev.extendedProps?.room_number ?? '' ) === targetRoom;
					} );
				}
			}
			return getEventsForView( viewType, list );
		}

		// Floating Event Popover Card
		let popoverEl = document.getElementById( 'mhbo-event-popover' );
		if ( ! popoverEl ) {
			popoverEl = document.createElement( 'div' );
			popoverEl.id = 'mhbo-event-popover';
			popoverEl.className = 'mhbo-event-popover';
			document.body.appendChild( popoverEl );
		}

		let hideTimeout = null;
		let showTimer = null;
		let pinnedBookingId = null;
		let isInsidePopover = false;

		function escapeHtml( str ) {
			if ( typeof str !== 'string' ) {
				return str ?? '';
			}
			return str
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' )
				.replace( /'/g, '&#039;' );
		}

		function showPopover( event, targetEl, isPinned = false ) {
			if ( showTimer ) {
				clearTimeout( showTimer );
				showTimer = null;
			}
			if ( hideTimeout ) {
				clearTimeout( hideTimeout );
				hideTimeout = null;
			}

			const props = event.extendedProps || {};
			const bookingId = escapeHtml( String( props.booking_id || '' ) );

			if ( pinnedBookingId && ! isPinned && String( pinnedBookingId ) !== String( bookingId ) ) {
				// Don't switch popovers if another booking is pinned open
				return;
			}

			if ( isPinned ) {
				pinnedBookingId = bookingId;
			}

			const customer = escapeHtml( props.customer || event.title || '' );
			const email = escapeHtml( props.email || '' );
			const phone = escapeHtml( props.phone || '' );
			const roomNumber = escapeHtml( String( props.room_number || '' ) );
			const roomType = escapeHtml( props.room_type || '' );
			const checkIn = escapeHtml( props.check_in_formatted || event.startStr || '' );
			const checkOut = escapeHtml( props.check_out_formatted || event.endStr || '' );
			const nights = Math.max( 1, parseInt( props.nights, 10 ) || 1 );
			const guests = Math.max( 1, parseInt( props.guests, 10 ) || 1 );
			const children = Math.max( 0, parseInt( props.children, 10 ) || 0 );
			const totalPrice = escapeHtml( props.total_price || '' );
			const statusLabel = escapeHtml( props.status || '' );
			const statusKey = escapeHtml( props.status_key || 'confirmed' );
			const vipTier = escapeHtml( props.vip_tier || '' );
			const cautionFlag = Boolean( props.caution_flag );
			const crmUrl = props.crm_url ? encodeURI( props.crm_url ) : '';
			const balanceUrl = props.balance_url ? encodeURI( props.balance_url ) : '';
			const balancePending = escapeHtml( props.remaining_balance || '' );

			let vipBadgeHtml = '';
			if ( vipTier ) {
				vipBadgeHtml = `<span class="mhbo-popover-vip">★ ${vipTier}</span>`;
			}
			let cautionBadgeHtml = '';
			if ( cautionFlag ) {
				cautionBadgeHtml = `<span class="mhbo-popover-caution">🚫 Caution</span>`;
			}

			let balanceHtml = '';
			if ( balancePending ) {
				balanceHtml = `<div class="mhbo-popover-row"><span class="mhbo-popover-label">Pending Bal:</span> <strong class="mhbo-popover-pending">${balancePending}</strong></div>`;
			}

			let actionButtonsHtml = '';
			if ( event.url ) {
				actionButtonsHtml += `<a href="${encodeURI( event.url )}" class="button button-primary button-small">Edit Booking</a>`;
			}
			if ( crmUrl ) {
				actionButtonsHtml += `<a href="${crmUrl}" target="_blank" rel="noopener noreferrer" class="button button-small" style="margin-left:6px;">Guest CRM ↗</a>`;
			}
			if ( balanceUrl ) {
				actionButtonsHtml += `<button type="button" class="button button-small mhbo-copy-trigger" data-copy="${balanceUrl}" style="margin-left:6px;" title="Copy Payment Link">🔗 Copy Link</button>`;
			}

			popoverEl.innerHTML = `
				<div class="mhbo-popover-header">
					<div class="mhbo-popover-title-row">
						<span class="mhbo-popover-id">#${bookingId}</span>
						<strong class="mhbo-popover-name">${customer}</strong>
						${vipBadgeHtml}
						${cautionBadgeHtml}
					</div>
					<div style="display:flex; align-items:center; gap:6px;">
						<span class="mhbo-popover-status mhbo-status-${statusKey}">${statusLabel}</span>
						<button type="button" class="mhbo-popover-close" title="Close" aria-label="Close">✕</button>
					</div>
				</div>
				<div class="mhbo-popover-body">
					<div class="mhbo-popover-row"><span class="mhbo-popover-label">Room:</span> <strong>Room ${roomNumber}</strong> <small>(${roomType})</small></div>
					<div class="mhbo-popover-row" style="align-items: flex-start;"><span class="mhbo-popover-label">Stay:</span> <span style="text-align: right;">${checkIn} → ${checkOut}<br><strong>(${nights} night${nights > 1 ? 's' : ''})</strong></span></div>
					<div class="mhbo-popover-row"><span class="mhbo-popover-label">Guests:</span> <span>${guests} Adult${guests > 1 ? 's' : ''}${children > 0 ? `, ${children} Child${children > 1 ? 'ren' : ''}` : ''}</span></div>
					${email ? `<div class="mhbo-popover-row"><span class="mhbo-popover-label">Email:</span> <span>${email}</span></div>` : ''}
					${phone ? `<div class="mhbo-popover-row"><span class="mhbo-popover-label">Phone:</span> <span>${phone}</span></div>` : ''}
					<div class="mhbo-popover-row"><span class="mhbo-popover-label">Total:</span> <strong class="mhbo-popover-price">${totalPrice}</strong></div>
					${balanceHtml}
				</div>
				<div class="mhbo-popover-actions">
					${actionButtonsHtml}
				</div>
			`;

			const closeBtn = popoverEl.querySelector( '.mhbo-popover-close' );
			if ( closeBtn ) {
				closeBtn.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					pinnedBookingId = null;
					popoverEl.style.display = 'none';
				} );
			}

			const copyBtn = popoverEl.querySelector( '.mhbo-copy-trigger' );
			if ( copyBtn ) {
				copyBtn.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					const copyText = copyBtn.getAttribute( 'data-copy' );
					if ( copyText && navigator.clipboard ) {
						navigator.clipboard.writeText( copyText ).then( function () {
							copyBtn.innerText = '✓ Copied!';
							setTimeout( function () {
								copyBtn.innerText = '🔗 Copy Link';
							}, 2000 );
						} );
					}
				} );
			}

			const rect = targetEl.getBoundingClientRect();
			popoverEl.style.display = 'block';
			popoverEl.style.visibility = 'hidden';

			const popoverHeight = popoverEl.offsetHeight;
			const popoverWidth = popoverEl.offsetWidth;

			let top = rect.top + window.scrollY - popoverHeight - 6;
			let left = rect.left + window.scrollX + (rect.width / 2) - (popoverWidth / 2);

			if ( top < window.scrollY + 10 || ( rect.top < 260 && ( rect.bottom + popoverHeight + 20 < window.innerHeight ) ) ) {
				top = rect.bottom + window.scrollY + 6;
			}
			if ( left < 10 ) left = 10;
			if ( left + popoverWidth > window.innerWidth - 10 ) {
				left = window.innerWidth - popoverWidth - 10;
			}

			popoverEl.style.top = `${top}px`;
			popoverEl.style.left = `${left}px`;
			popoverEl.style.visibility = 'visible';
		}

		function hidePopover( force = false ) {
			if ( showTimer ) {
				clearTimeout( showTimer );
				showTimer = null;
			}
			if ( pinnedBookingId && ! force ) {
				return;
			}
			if ( hideTimeout ) {
				clearTimeout( hideTimeout );
			}
			hideTimeout = setTimeout( function () {
				if ( ! isInsidePopover && ( ! pinnedBookingId || force ) ) {
					if ( popoverEl ) {
						popoverEl.style.display = 'none';
					}
				}
			}, 350 );
		}

		popoverEl.addEventListener( 'mouseenter', function () {
			isInsidePopover = true;
			if ( hideTimeout ) {
				clearTimeout( hideTimeout );
				hideTimeout = null;
			}
			if ( showTimer ) {
				clearTimeout( showTimer );
				showTimer = null;
			}
		} );

		popoverEl.addEventListener( 'mouseleave', function () {
			isInsidePopover = false;
			hidePopover();
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( popoverEl && popoverEl.style.display === 'block' ) {
				if ( ! popoverEl.contains( e.target ) && ! e.target.closest( '.fc-event' ) ) {
					pinnedBookingId = null;
					popoverEl.style.display = 'none';
				}
			}
		} );

		// eslint-disable-next-line no-undef
		const calendar = new FullCalendar.Calendar( calendarEl, {
			initialView: 'dayGridMonth',
			headerToolbar: {
				left: 'prev,next today',
				center: 'title',
				right: 'dayGridMonth,dayGridWeek,listWeek',
			},
			events: getEventsForView( 'dayGridMonth', rawEvents ),
			navLinks: true,
			nowIndicator: true,
			eventOrderStrict: true,
			eventOrder: 'room_sort,start',
			height: 'auto',
			contentHeight: 'auto',
			expandRows: false,
			// FullCalendar v7.1.2+ encloses DayGrid cell events in role="list"/"listitem"
			// wrappers (a11y). Expose a stable view-type bridge class on the view root so
			// List-view-only CSS never leaks into Month/Week day cells (hashed V7 classes).
			viewClass: function ( arg ) {
				const type = ( arg && ( arg.view?.type || arg.viewApi?.type ) ) || '';
				const isList = type.startsWith( 'list' );
				return `mhbo-fc-view mhbo-fc-view-${ type } ${ isList ? 'mhbo-fc-view-list' : 'mhbo-fc-view-grid' }`;
			},
			datesSet: function ( info ) {
				if ( info && info.view && info.view.type !== currentViewType ) {
					const oldView = currentViewType;
					currentViewType = info.view.type;
					const isOldList = oldView.startsWith( 'list' );
					const isNewList = currentViewType.startsWith( 'list' );
					if ( isOldList !== isNewList ) {
						const filterEl = document.getElementById( 'mhbo-calendar-room-filter' );
						const activeFilter = filterEl ? filterEl.value : '';
						calendar.removeAllEvents();
						calendar.addEventSource( getFilteredEvents( activeFilter, currentViewType ) );
					}
				}
			},
			eventMouseEnter: function( info ) {
				if ( showTimer ) {
					clearTimeout( showTimer );
				}
				if ( hideTimeout ) {
					clearTimeout( hideTimeout );
					hideTimeout = null;
				}

				showTimer = setTimeout( function () {
					showPopover( info.event, info.el );
				}, 110 );

				const bookingId = info.event.extendedProps?.booking_id;
				if ( bookingId ) {
					const connectedEls = calendarEl.querySelectorAll( `[data-booking-id="${bookingId}"]` );
					connectedEls.forEach( function ( el ) {
						el.classList.add( 'mhbo-event-connected-hover' );
					} );
				}
			},
			eventMouseLeave: function( info ) {
				if ( showTimer ) {
					clearTimeout( showTimer );
					showTimer = null;
				}
				hidePopover();
				const bookingId = info.event.extendedProps?.booking_id;
				if ( bookingId ) {
					const connectedEls = calendarEl.querySelectorAll( `[data-booking-id="${bookingId}"]` );
					connectedEls.forEach( function ( el ) {
						el.classList.remove( 'mhbo-event-connected-hover' );
					} );
				}
			},
			eventClick( info ) {
				info.jsEvent.preventDefault();
				showPopover( info.event, info.el, true );
			},
			dayCellClass: function( arg ) {
				const year = arg.date.getFullYear();
				const month = String( arg.date.getMonth() + 1 ).padStart( 2, '0' );
				const day = String( arg.date.getDate() ).padStart( 2, '0' );
				const dateStr = `${year}-${month}-${day}`;
				let classes = [];
				if ( config.fullDays && config.fullDays.includes( dateStr ) ) {
					classes.push( 'mhbo-day-full' );
				}
				if ( arg.isToday ) {
					classes.push( 'mhbo-day-today-highlight' );
				}
				return classes.join( ' ' );
			},
			eventClass: function( arg ) {
				const isList = arg.view?.type?.startsWith( 'list' );
				if ( isList ) {
					return 'fc-event fc-list-event mhbo-calendar-event mhbo-list-event';
				}
				let classes = [ 'fc-event', 'fc-daygrid-event', 'mhbo-calendar-event' ];
				const isSunOut = Boolean( arg.event.extendedProps?.is_sunday_checkout );
				const isSatEnd = Boolean( arg.event.extendedProps?.ends_on_saturday );

				if ( isSunOut ) {
					classes.push( 'mhbo-sunday-checkout' );
				}
				if ( isSatEnd ) {
					classes.push( 'mhbo-event-saturday-end' );
				}
				if ( arg.isStart && ! isSunOut ) {
					classes.push( 'mhbo-event-checkin' );
					classes.push( 'fc-event-start' );
				}
				if ( arg.isEnd && ! isSatEnd ) {
					classes.push( 'mhbo-event-checkout' );
					classes.push( 'fc-event-end' );
				}
				return classes.join( ' ' );
			},
			dayCellDidMount: function(arg) {
				if (arg.el) {
					const year = arg.date.getFullYear();
					const month = String(arg.date.getMonth() + 1).padStart(2, '0');
					const day = String(arg.date.getDate()).padStart(2, '0');
					const dateStr = `${year}-${month}-${day}`;
					if (config.fullDays && config.fullDays.includes(dateStr)) {
						arg.el.classList.add('mhbo-day-full');
					}
					if (arg.isToday) {
						arg.el.classList.add('mhbo-day-today-highlight');
					}
				}
			},
			eventDidMount: function( arg ) {
				if ( arg.el ) {
					const isList = arg.view?.type?.startsWith( 'list' );
					arg.el.classList.add( 'fc-event', isList ? 'fc-list-event' : 'fc-daygrid-event', 'mhbo-calendar-event' );
					if ( isList ) {
						arg.el.classList.add( 'mhbo-list-event' );
					}
					const isSunOut = Boolean( arg.event.extendedProps?.is_sunday_checkout );
					const isSatEnd = Boolean( arg.event.extendedProps?.ends_on_saturday );

					if ( isSunOut ) {
						arg.el.classList.add( 'mhbo-sunday-checkout' );
					}
					if ( isSatEnd && ! isList ) {
						arg.el.classList.add( 'mhbo-event-saturday-end' );
					}
					const roomNum = arg.event.extendedProps?.room_number || '';
					const bookingId = arg.event.extendedProps?.booking_id || '';
					if ( roomNum ) {
						arg.el.dataset.room = roomNum;
						if ( arg.el.parentElement ) {
							arg.el.parentElement.dataset.room = roomNum;
						}
					}
					if ( bookingId ) {
						arg.el.dataset.bookingId = bookingId;
					}

					if ( ! isList ) {
						// Ensure check-in / check-out markers are reliably attached in daygrid
						if ( ! isSunOut && ( arg.el.querySelector( '.mhbo-dot-checkin' ) || arg.isStart ) ) {
							arg.el.classList.add( 'mhbo-event-checkin' );
							arg.el.classList.add( 'fc-event-start' );
						}
						if ( ! isSatEnd && ( arg.el.querySelector( '.mhbo-dot-checkout' ) || arg.isEnd ) ) {
							arg.el.classList.add( 'mhbo-event-checkout' );
							arg.el.classList.add( 'fc-event-end' );
						}

						// Same-room turnover on Sunday: If this is a Sunday check-in following a Sunday checkout for the SAME room,
						// align check-in into the same slot alongside the checkout capsule
						if ( arg.isStart && ! isSunOut && roomNum && arg.event.start ) {
							const startD = new Date( arg.event.start );
							if ( startD.getDay() === 0 ) {
								const sunStr = arg.event.startStr ? arg.event.startStr.substring( 0, 10 ) : '';
								const hasMatchingSunOut = rawEvents.some( function( ev ) {
									return Boolean( ev.extendedProps?.is_sunday_checkout ) &&
										String( ev.extendedProps?.room_number || '' ) === String( roomNum ) &&
										ev.start === sunStr;
								} );
								if ( hasMatchingSunOut ) {
									const harness = arg.el.closest( '.fc-daygrid-event-harness' ) || arg.el.parentElement;
									if ( harness && harness.previousElementSibling ) {
										const prevH = harness.previousElementSibling.offsetHeight || 26;
										harness.style.marginTop = '-' + prevH + 'px';
									}
								}
							}
						}
					}
				}
			},
			eventContent: function( arg ) {
				const isList = arg.view?.type?.startsWith( 'list' );
				const props = arg.event.extendedProps || {};
				const customer = escapeHtml( props.customer || arg.event.title || '' );
				const roomNum = props.room_number ? `Room ${escapeHtml( String( props.room_number ) )}` : '';
				const nights = props.nights ? `(${props.nights} nt)` : '';

				if ( isList ) {
					let roleBadge = '';
					if ( arg.isStart ) {
						roleBadge = '<span class="mhbo-dot-checkin" title="Check-in Date" style="margin-right:6px; flex-shrink:0;"></span><strong style="color:#166534; margin-right:6px;">Check-in:</strong> ';
					} else if ( arg.isEnd ) {
						roleBadge = '<span class="mhbo-dot-checkout" title="Check-out Date" style="margin-right:6px; flex-shrink:0;"></span><strong style="color:#991b1b; margin-right:6px;">Check-out:</strong> ';
					} else {
						roleBadge = '<span class="mhbo-legend-dot" style="background:#64748b; margin-right:6px; flex-shrink:0;" title="Stayover"></span><span style="color:#475569; margin-right:6px;">Stay:</span> ';
					}

					return {
						html: `<div class="mhbo-event-inner mhbo-list-event-inner" style="display:flex; align-items:center; gap:4px; width:100%; box-sizing:border-box;">${roleBadge}<span class="fc-event-title" style="font-weight:600;">${roomNum ? `<strong>${roomNum}</strong> — ` : ''}${customer} <span style="font-weight:400; color:#64748b; font-size:11px;">${nights}</span></span></div>`,
					};
				}

				if ( arg.event.extendedProps?.is_sunday_checkout ) {
					return {
						html: `<div class="mhbo-event-inner mhbo-event-inner-checkout-only" title="Check-out: ${escapeHtml( arg.event.title )}"><span class="mhbo-dot-checkout" style="margin:0 auto;" title="Check-out Date"></span></div>`,
					};
				}
				let checkinDot = '';
				let checkoutDot = '';

				if ( arg.isStart ) {
					checkinDot = '<span class="mhbo-dot-checkin" title="Check-in Date"></span>';
				}
				if ( arg.isEnd && ! arg.event.extendedProps?.ends_on_saturday ) {
					checkoutDot = '<span class="mhbo-dot-checkout" title="Check-out Date"></span>';
				}

				return {
					html: `<div class="mhbo-event-inner">${checkinDot}<span class="fc-event-title">${escapeHtml( arg.event.title )}</span>${checkoutDot}</div>`,
				};
			},
			aspectRatio: 1.8,
		} );

		calendar.render();

		// Room Filter Dropdown for Calendar (Supports Type & Physical Room Filtering)
		const roomFilter = document.getElementById( 'mhbo-calendar-room-filter' );
		if ( roomFilter ) {
			roomFilter.addEventListener( 'change', function () {
				const filterVal = this.value;
				calendar.removeAllEvents();
				calendar.addEventSource( getFilteredEvents( filterVal, currentViewType ) );
			} );
		}
	}

function initPaginationHandlers() {
		const jumpInput = document.getElementById( 'mhbo-page-jump-input' );
		if ( jumpInput ) {
			jumpInput.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					let page = parseInt( this.value, 10 );
					const maxPage = parseInt( this.getAttribute( 'max' ), 10 ) || 1;
					const minPage = parseInt( this.getAttribute( 'min' ), 10 ) || 1;
					if ( isNaN( page ) || page < minPage ) page = minPage;
					if ( page > maxPage ) page = maxPage;

					const baseUrl = this.getAttribute( 'data-base-url' ) || window.location.href;
					try {
						const url = new URL( baseUrl, window.location.origin );
						url.searchParams.set( 'paged', page );
						window.location.href = url.toString();
					} catch ( err ) {
						const delim = baseUrl.indexOf( '?' ) !== -1 ? '&' : '?';
						window.location.href = baseUrl + delim + 'paged=' + encodeURIComponent( page );
					}
				}
			} );
		}

		const perPageSelect = document.getElementById( 'mhbo-table-per-page-select' );
		if ( perPageSelect ) {
			perPageSelect.addEventListener( 'change', function () {
				const val = this.value;
				const baseUrl = this.getAttribute( 'data-base-url' ) || window.location.href;
				try {
					const url = new URL( baseUrl, window.location.origin );
					url.searchParams.set( 'per_page', val );
					url.searchParams.set( 'paged', '1' );
					window.location.href = url.toString();
				} catch ( err ) {
					const delim = baseUrl.indexOf( '?' ) !== -1 ? '&' : '?';
					window.location.href = baseUrl + delim + 'per_page=' + encodeURIComponent( val ) + '&paged=1';
				}
			} );
		}
	}

	/**
	 * Bulk actions: selection synchronization and confirmation warning engine.
	 */
	function initBulkActions() {
		const selectAllCb = document.getElementById( 'mhbo-cb-select-all' );
		const rowCheckboxes = document.querySelectorAll( '.mhbo-booking-checkbox' );
		const bulkForm = document.getElementById( 'mhbo-bookings-bulk-form' );
		const badge = document.getElementById( 'mhbo-selected-badge' ) || document.getElementById( 'mhbo-selected-badge-free' );

		function updateSelectionState() {
			let totalVisible = 0;
			let totalChecked = 0;

			rowCheckboxes.forEach( function ( cb ) {
				const row = cb.closest( 'tr.mhbo-booking-row' );
				const isVisible = row ? row.style.display !== 'none' : true;
				if ( isVisible ) {
					totalVisible++;
					if ( cb.checked ) {
						totalChecked++;
					}
				}
				if ( row ) {
					if ( cb.checked ) {
						row.classList.add( 'is-selected' );
					} else {
						row.classList.remove( 'is-selected' );
					}
				}
			} );

			if ( selectAllCb ) {
				if ( totalChecked === 0 ) {
					selectAllCb.checked = false;
					selectAllCb.indeterminate = false;
				} else if ( totalChecked === totalVisible && totalVisible > 0 ) {
					selectAllCb.checked = true;
					selectAllCb.indeterminate = false;
				} else {
					selectAllCb.checked = false;
					selectAllCb.indeterminate = true;
				}
			}

			if ( badge ) {
				if ( totalChecked > 0 ) {
					const label = window.mhboAdminBookingsConfig?.i18n?.selectedCount || 'selected';
					badge.textContent = `${ totalChecked } ${ label }`;
					badge.style.display = 'inline-flex';
				} else {
					badge.style.display = 'none';
				}
			}
		}

		if ( selectAllCb ) {
			selectAllCb.addEventListener( 'change', function () {
				const isChecked = this.checked;
				rowCheckboxes.forEach( function ( cb ) {
					const row = cb.closest( 'tr.mhbo-booking-row' );
					const isVisible = row ? row.style.display !== 'none' : true;
					if ( isVisible ) {
						cb.checked = isChecked;
					}
				} );
				updateSelectionState();
			} );
		}

		if ( rowCheckboxes && rowCheckboxes.length > 0 ) {
			rowCheckboxes.forEach( function ( cb ) {
				cb.addEventListener( 'change', function () {
					updateSelectionState();
				} );
			} );
		}

		if ( bulkForm ) {
			bulkForm.addEventListener( 'submit', function ( e ) {
				const actionSelect = bulkForm.querySelector( 'select[name="bulk_action"]' );
				const selectedAction = actionSelect ? actionSelect.value : '-1';

				if ( ! selectedAction || selectedAction === '-1' ) {
					e.preventDefault();
					return false;
				}

				if ( selectedAction === 'bulk_delete' ) {
					const checkedBoxes = bulkForm.querySelectorAll( '.mhbo-booking-checkbox:checked' );
					const count = checkedBoxes.length;

					if ( count === 0 ) {
						e.preventDefault();
						const noSelMsg = window.mhboAdminBookingsConfig?.i18n?.noBookingsSelected || 'Please select at least one booking to delete.';
						if ( window.MHBO && window.MHBO.toast ) {
							window.MHBO.toast( noSelMsg, 'warning', 3500 );
						} else {
							alert( noSelMsg );
						}
						return false;
					}

					const template = window.mhboAdminBookingsConfig?.i18n?.confirmBulkDelete || 'Are you sure you want to permanently delete the %d selected booking(s)? All associated data will be removed and this action cannot be undone.';
					const confirmMsg = template.replace( '%d', count );

					if ( ! window.confirm( confirmMsg ) ) {
						e.preventDefault();
						return false;
					}
				}
			} );
		}
	}

// Initialize on DOM ready
	onReady( function () {
		initFullCalendar();
		initPaginationHandlers();
		initBulkActions();
		
	} );
} )();
