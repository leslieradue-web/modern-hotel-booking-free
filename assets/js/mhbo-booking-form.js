( function () {
	'use strict';

	// Debug logger
	const debugLog = ( function () {
		const isDebug =
			( typeof mhbo_vars !== 'undefined' && mhbo_vars.debug ) ||
			( typeof localStorage !== 'undefined' &&
				localStorage.getItem( 'mhbo_debug' ) );
		return isDebug
			? console.error.bind( console, '[MHBO]' )
			: function () {};
	} )();

	function escapeHtml( str ) {
		if ( null === str || undefined === str ) {
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
	 * Display an inline booking error banner in the active booking form wrapper.
	 * Exposed on window so payment gateway scripts share identical error rendering.
	 *
	 * @param {string}       message   Error message to display.
	 * @param {Element|null} contextEl Optional form or wrapper element.
	 * @param {Object}       options   Optional settings ({ scroll: boolean }).
	 */
	window.mhboShowBookingError = function ( message, contextEl, options ) {
		const opts = Object.assign( { scroll: true }, options || {} );
		const activeModalWrapper = document.querySelector(
			'.mhbo-modal-overlay.is-open .mhbo-booking-form-wrapper'
		);
		const wrapper =
			( contextEl &&
				typeof contextEl.closest === 'function' &&
				( contextEl.closest( '.mhbo-booking-form-wrapper' ) ||
					contextEl ) ) ||
			activeModalWrapper ||
			document.querySelector( '.mhbo-booking-form-wrapper' );

		const errorBox =
			( wrapper &&
				typeof wrapper.querySelector === 'function' &&
				wrapper.querySelector( '.mhbo-booking-errors' ) ) ||
			document.querySelector( '.mhbo-booking-errors' );

		if ( errorBox ) {
			errorBox.innerHTML =
				'<div class="mhbo-error-notification" role="alert"><span class="mhbo-error-icon">⚠️</span> <span>' +
				escapeHtml( message ) +
				'</span></div>';
			errorBox.style.display = 'block';
			if ( opts.scroll && typeof errorBox.scrollIntoView === 'function' ) {
				errorBox.scrollIntoView( {
					behavior: 'smooth',
					block: 'center',
				} );
			}
		}
	};

	/**
	 * Append an inline field-level error message inside a field group container.
	 *
	 * @param {Element|null} container Field group element.
	 * @param {string}       text      Error message.
	 */
	function appendFieldErrorMessage( container, text ) {
		if ( ! container || ! text ) {
			return;
		}
		let msgEl = container.querySelector( '.mhbo-field-error-msg' );
		if ( ! msgEl ) {
			msgEl = document.createElement( 'span' );
			msgEl.className = 'mhbo-field-error-msg';
			msgEl.setAttribute( 'role', 'alert' );
			container.appendChild( msgEl );
		}
		msgEl.textContent = text;
	}

	/**
	 * Validate required booking form fields and GDPR consent checkbox.
	 * Supports both silent checks and visible inline error notifications across
	 * Modal Drawer, Shortcode, and Gutenberg Block booking forms.
	 *
	 * @param {HTMLFormElement|null} targetForm Booking form to validate.
	 * @param {boolean}              silent     When true, do not render UI errors.
	 * @return {boolean} True when all required fields and consent are valid.
	 */
	window.mhboValidateBookingForm = function ( targetForm, silent ) {
		const isSilent = Boolean( silent );
		const activeModalForm = document.querySelector(
			'.mhbo-modal-overlay.is-open .mhbo-booking-form'
		);
		const form =
			targetForm ||
			activeModalForm ||
			document.querySelector( '.mhbo-booking-form' );

		if ( ! form ) {
			return true;
		}

		if ( ! isSilent ) {
			form.querySelectorAll( '.mhbo-input-error' ).forEach(
				function ( el ) {
					el.classList.remove( 'mhbo-input-error' );
					el.removeAttribute( 'aria-invalid' );
					el.style.borderColor = '';
				}
			);
			form.querySelectorAll( '.mhbo-gdpr-error, .mhbo-terms-error' ).forEach(
				function ( el ) {
					el.classList.remove( 'mhbo-gdpr-error', 'mhbo-terms-error' );
				}
			);
			form.querySelectorAll( '.mhbo-field-error-msg' ).forEach(
				function ( el ) {
					el.remove();
				}
			);
		}

		let hasFieldErrors = false;
		let hasTermsError = false;
		let hasGdprError = false;
		let firstInvalidEl = null;

		const v = typeof window.mhbo_vars !== 'undefined' ? window.mhbo_vars : {};
		const msgFieldRequired =
			v.msg_field_required || 'This field is required.';
		const msgFillAllFields =
			v.msg_fill_all_fields || 'Please fill in all required fields.';
		const msgTermsRequired =
			v.msg_terms_required ||
			'Please accept the booking terms and cancellation policy to continue.';
		const msgGdprRequired =
			v.msg_gdpr_required ||
			'Please accept the privacy policy to continue.';

		// 1. Validate required inputs, selects, and textareas (excluding checkboxes/hidden)
		const requiredInputs = form.querySelectorAll(
			'input[required]:not([type="checkbox"]):not([type="hidden"]), select[required], textarea[required]'
		);

		requiredInputs.forEach( function ( input ) {
			if ( input.disabled ) {
				return;
			}
			const hiddenAncestor = input.closest( '[style*="display: none"], [style*="display:none"]' );
			if ( hiddenAncestor && hiddenAncestor !== form ) {
				return;
			}

			const rawVal =
				typeof input.value === 'string' ? input.value.trim() : '';
			let isInvalid = rawVal === '';
			let fieldMsg = msgFieldRequired;

			if ( input.name === 'child_ages[]' ) {
				const ageInt = parseInt( rawVal, 10 );
				if (
					rawVal === '' ||
					isNaN( ageInt ) ||
					ageInt < 0 ||
					ageInt > 17
				) {
					isInvalid = true;
				}
			} else if (
				! isInvalid &&
				typeof input.checkValidity === 'function' &&
				! input.checkValidity()
			) {
				isInvalid = true;
				fieldMsg = input.validationMessage || msgFillAllFields;
			}

			if ( isInvalid ) {
				hasFieldErrors = true;
				if ( ! firstInvalidEl ) {
					firstInvalidEl = input;
				}
				if ( ! isSilent ) {
					input.classList.add( 'mhbo-input-error' );
					input.setAttribute( 'aria-invalid', 'true' );
					const group =
						input.closest(
							'.mhbo-form-group, .mhbo-child-age-group'
						) || input.parentElement;
					appendFieldErrorMessage( group, fieldMsg );
				}
			}
		} );

		// 2. Validate Booking Terms & GDPR consent checkboxes
		const termsConsent = form.querySelector(
			'input[name="mhbo_terms_accepted"], #mhbo-terms-consent, .mhbo-terms-consent-input'
		);
		if ( termsConsent && ! termsConsent.checked ) {
			hasTermsError = true;
			if ( ! firstInvalidEl ) {
				firstInvalidEl = termsConsent;
			}
			if ( ! isSilent ) {
				termsConsent.setAttribute( 'aria-invalid', 'true' );
				const termsGroup =
					termsConsent.closest(
						'.mhbo-terms-consent, .mhbo-form-group'
					) || termsConsent.parentElement;
				if ( termsGroup ) {
					termsGroup.classList.add( 'mhbo-terms-error' );
					appendFieldErrorMessage( termsGroup, msgTermsRequired );
				}
			}
		}

		const consent = form.querySelector(
			'input[name="mhbo_consent"], #mhbo-consent, .mhbo-consent'
		);
		if ( consent && ! consent.checked ) {
			hasGdprError = true;
			if ( ! firstInvalidEl ) {
				firstInvalidEl = consent;
			}
			if ( ! isSilent ) {
				consent.setAttribute( 'aria-invalid', 'true' );
				const gdprGroup =
					consent.closest( '.mhbo-gdpr-consent, .mhbo-form-group' ) ||
					consent.parentElement;
				if ( gdprGroup ) {
					gdprGroup.classList.add( 'mhbo-gdpr-error' );
					appendFieldErrorMessage( gdprGroup, msgGdprRequired );
				}
			}
		}

		if ( hasFieldErrors || hasTermsError || hasGdprError ) {
			if ( ! isSilent ) {
				const bannerMsg = hasFieldErrors
					? msgFillAllFields
					: hasTermsError
					? msgTermsRequired
					: msgGdprRequired;
				window.mhboShowBookingError( bannerMsg, form, {
					scroll: ! firstInvalidEl,
				} );

				if ( firstInvalidEl ) {
					const scrollTarget =
						firstInvalidEl.closest(
							'.mhbo-terms-consent, .mhbo-gdpr-consent, .mhbo-form-group, .mhbo-child-age-group'
						) || firstInvalidEl;
					if ( typeof scrollTarget.scrollIntoView === 'function' ) {
						scrollTarget.scrollIntoView( {
							behavior: 'smooth',
							block: 'center',
						} );
					}
					if ( typeof firstInvalidEl.focus === 'function' ) {
						try {
							firstInvalidEl.focus( { preventScroll: true } );
						} catch ( _ ) {
							firstInvalidEl.focus();
						}
					}
				}
			}
			return false;
		}

		return true;
	};

	/**
	 * Initialize a single booking form wrapper instance.
	 * Called on DOMContentLoaded for page-embedded forms and on
	 * mhboModalContent for dynamically injected modal forms.
	 * A data attribute guards against double-initialization.
	 * @param wrapper
	 */
	function initBookingFormWrapper( wrapper ) {
		// Guard: skip if already initialized or no active form in wrapper yet
		if ( ! wrapper || wrapper.dataset.mhboFormInit === '1' ) {
			return;
		}
		const form = wrapper.querySelector( '.mhbo-booking-form' );
		if ( ! form ) {
			return;
		}
		wrapper.dataset.mhboFormInit = '1';
		form.noValidate = true;

		const childrenSelect = wrapper.querySelector(
			'.mhbo-booking-children'
		);
		const agesContainer = wrapper.querySelector(
			'.mhbo-child-ages-container'
		);
		const agesInputs = wrapper.querySelector( '.mhbo-child-ages-inputs' );
		const guestsSelect = wrapper.querySelector( '.mhbo-booking-guests' );
		const totalDisplays = wrapper.querySelectorAll( '.mhbo-display-total' );
		const totalHidden = form.querySelector( 'input[name="total_price"]' );
		const arrivalTotalEl = wrapper.querySelector(
			'.mhbo-arrival-total-price'
		);
		totalDisplays.forEach( function ( el ) {
			el.setAttribute( 'aria-live', 'polite' );
			el.setAttribute( 'aria-atomic', 'true' );
		} );
		if ( arrivalTotalEl ) {
			arrivalTotalEl.setAttribute( 'aria-live', 'polite' );
			arrivalTotalEl.setAttribute( 'aria-atomic', 'true' );
		}
		const submitBtn = wrapper.querySelector( '.mhbo-submit-btn' );
		const termsCheckbox = wrapper.querySelector(
			'input[name="mhbo_terms_accepted"], .mhbo-terms-consent-input'
		);
		const consentCheckbox = wrapper.querySelector(
			'input[name="mhbo_consent"], #mhbo-consent, .mhbo-consent'
		);
		const errorBox = wrapper.querySelector( '.mhbo-booking-errors' );

		let debounceTimer = null;
		let recalcAbortController = null; // Cancel in-flight requests to prevent 429
		let recalcDirty = false;
		let inFlightPromise = null;
		let recalcSeq = 0;

		if ( typeof window.mhboFlushPriceRecalculation !== 'function' ) {
			window.mhboFlushPriceRecalculation = function ( targetEl ) {
				const targetWrapper =
					targetEl &&
					targetEl.classList &&
					targetEl.classList.contains( 'mhbo-booking-form-wrapper' )
						? targetEl
						: targetEl && typeof targetEl.closest === 'function'
						? targetEl.closest( '.mhbo-booking-form-wrapper' )
						: document.querySelector(
								'.mhbo-modal-overlay.is-open .mhbo-booking-form-wrapper'
						  ) ||
						  document.querySelector( '.mhbo-booking-form-wrapper' );
				if (
					targetWrapper &&
					typeof targetWrapper._mhboFlushRecalc === 'function'
				) {
					return targetWrapper._mhboFlushRecalc();
				}
				return Promise.resolve();
			};
			window.mhboHasPendingPriceRecalculation = function ( targetEl ) {
				const targetWrapper =
					targetEl &&
					targetEl.classList &&
					targetEl.classList.contains( 'mhbo-booking-form-wrapper' )
						? targetEl
						: targetEl && typeof targetEl.closest === 'function'
						? targetEl.closest( '.mhbo-booking-form-wrapper' )
						: document.querySelector(
								'.mhbo-modal-overlay.is-open .mhbo-booking-form-wrapper'
						  ) ||
						  document.querySelector( '.mhbo-booking-form-wrapper' );
				return Boolean(
					targetWrapper &&
						typeof targetWrapper._mhboIsRecalcPending ===
							'function' &&
						targetWrapper._mhboIsRecalcPending()
				);
			};
		}

		function scheduleRecalculate( delayMs ) {
			if ( ! totalDisplays || totalDisplays.length === 0 ) {
				return;
			}
			const delay = typeof delayMs === 'number' ? delayMs : 400;

			if ( recalcAbortController ) {
				recalcAbortController.abort();
				recalcAbortController = null;
			}
			clearTimeout( debounceTimer );
			debounceTimer = null;
			recalcDirty = true;

			totalDisplays.forEach( function ( el ) {
				el.classList.add( 'mhbo_faded' );
			} );
			if ( arrivalTotalEl ) {
				arrivalTotalEl.classList.add( 'mhbo_faded' );
			}

			if ( delay <= 0 ) {
				runRecalculatePromise();
			} else {
				debounceTimer = setTimeout( function () {
					debounceTimer = null;
					runRecalculatePromise();
				}, delay );
			}
		}

		function recalculatePrice() {
			scheduleRecalculate( 400 );
		}

		function runRecalculatePromise() {
			recalcDirty = false;
			const mySeq = ++recalcSeq;
			const promise = executeRecalculate( mySeq ).finally( function () {
				if ( inFlightPromise === promise ) {
					inFlightPromise = null;
				}
			} );
			inFlightPromise = promise;
			return promise;
		}

		wrapper._mhboIsRecalcPending = function () {
			return Boolean( recalcDirty || debounceTimer || inFlightPromise );
		};

		wrapper._mhboFlushRecalc = async function () {
			let guard = 0;
			while (
				( recalcDirty || debounceTimer || inFlightPromise ) &&
				guard < 4
			) {
				guard++;
				if ( debounceTimer ) {
					clearTimeout( debounceTimer );
					debounceTimer = null;
				}
				if ( recalcDirty ) {
					await runRecalculatePromise();
				} else if ( inFlightPromise ) {
					try {
						await inFlightPromise;
					} catch ( _ ) {}
				}
			}
		};

		async function executeRecalculate( mySeq ) {
			recalcAbortController = new AbortController();

			try {
					const roomIdInput = form.querySelector(
						'input[name="mhbo_room_id"]'
					);
					const roomId = roomIdInput ? roomIdInput.value : 0;
					const checkInInput = form.querySelector(
						'input[name="check_in"]'
					);
					const checkIn = checkInInput ? checkInInput.value : '';
					const checkOutInput = form.querySelector(
						'input[name="check_out"]'
					);
					const checkOut = checkOutInput ? checkOutInput.value : '';
					const guests = guestsSelect ? guestsSelect.value : 1;
					const children = childrenSelect ? childrenSelect.value : 0;

					const extras = {};
					form.querySelectorAll(
						'.mhbo-extra-input, .mhbo-booking-extra-checkbox'
					).forEach( function ( input ) {
						let val = 0;
						if ( input.type === 'checkbox' ) {
							if ( input.checked ) {
								val = 1;
							}
						} else {
							val = input.value;
						}

						if ( val && parseFloat( val ) > 0 ) {
							// Priority: data-extra-id, then id from name
							const extraId = input.dataset.extraId;
							if ( extraId ) {
								extras[ extraId ] = val;
							} else {
								const match = input.name.match( /\[(.*?)\]/ );
								if ( match ) {
									extras[ match[ 1 ] ] = val;
								}
							}
						}
					} );

					const paymentTypeInput = form.querySelector(
						'input[name="mhbo_payment_type"]:checked'
					);
					const paymentType = paymentTypeInput
						? paymentTypeInput.value
						: 'full';

					const childrenAges = [];
					form.querySelectorAll(
						'input[name="child_ages[]"]'
					).forEach( function ( input ) {
						const v = input.value.trim();
						// Only include ages that have been explicitly entered.
						// Missing ages are treated as chargeable by the backend (safe default).
						if ( v !== '' ) {
							childrenAges.push( parseInt( v, 10 ) );
						}
					} );

					const couponAppliedField = form.querySelector(
						'[name="mhbo_coupon_applied"]'
					);
					const activeCouponCode = couponAppliedField
						? couponAppliedField.value.trim()
						: '';

					const updateIdField = form.querySelector(
						'[name="mhbo_update_id"], [name="mhbo_exclude_id"]'
					);
					const excludeId = updateIdField
						? parseInt( updateIdField.value, 10 ) || 0
						: 0;
					const tokenField = form.querySelector(
						'[name="mhbo_booking_token"], [name="mhbo_session_token"]'
					);
					const bookingToken = tokenField
						? tokenField.value.trim()
						: '';

					const custEmailInput = form.querySelector(
						'input[name="customer_email"], input[name="mhbo_customer_email"]'
					);
					const customerEmail = custEmailInput
						? custEmailInput.value.trim()
						: '';

					const response = await fetch(
						mhbo_vars.api_base + '/recalculate-price',
						{
							method: 'POST',
							signal: recalcAbortController.signal,
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': mhbo_vars.nonce,
							},
							body: JSON.stringify( {
								room_id: roomId,
								check_in: checkIn,
								check_out: checkOut,
								guests,
								children,
								child_ages: childrenAges,
								extras,
								mhbo_payment_type: paymentType,
								coupon_code: activeCouponCode,
								customer_email: customerEmail,
								exclude_id: excludeId,
								update_id: excludeId,
								booking_token: bookingToken,
							} ),
						}
					);

					const data = await response.json();
					if ( mySeq !== recalcSeq ) {
						return;
					}

					if ( data.success ) {
						totalDisplays.forEach( function ( el ) {
							el.textContent = data.total_formatted;
						} );
						if ( totalHidden ) {
							totalHidden.value = data.total;
						}

						// Room cost row
						const roomTotalEl = wrapper.querySelector(
							'.mhbo-room-total-display'
						);
						if ( roomTotalEl && data.room_total_formatted ) {
							roomTotalEl.textContent = data.room_total_formatted;
						}

						// Children cost row — show when children contribute to the total
						const childrenRow = wrapper.querySelector(
							'.mhbo-children-cost-row'
						);
						const childrenTotalEl = wrapper.querySelector(
							'.mhbo-children-total-display'
						);
						if ( childrenRow && childrenTotalEl ) {
							const numericChildrenTotal =
								parseFloat( data.children_total ) || 0;
							if (
								numericChildrenTotal > 0 &&
								data.children_total_formatted
							) {
								childrenTotalEl.textContent =
									data.children_total_formatted;
								childrenRow.classList.remove( 'is-hidden' );
								childrenRow.style.display = '';
							} else {
								childrenRow.style.display = 'none';
								childrenTotalEl.textContent = '';
							}
						}

						// Extras summary rows (including early checkin & late checkout)
						const extrasSummaryContainer = wrapper.querySelector(
							'.mhbo-extras-summary-rows'
						);
						if ( extrasSummaryContainer ) {
							extrasSummaryContainer.innerHTML = '';
							if (
								data.extras_breakdown &&
								typeof data.extras_breakdown === 'object'
							) {
								Object.values( data.extras_breakdown ).forEach(
									function ( extraItem ) {
										if (
											extraItem &&
											extraItem.value > 0
										) {
											const row =
												document.createElement( 'div' );
											row.className =
												'mhbo-summary-row mhbo-extra-summary-row';
											if ( extraItem.id ) {
												row.dataset.extraId = String(
													extraItem.id
												);
											}

											const nameSpan =
												document.createElement(
													'span'
												);
											nameSpan.textContent =
												extraItem.name || '';

											const amountSpan =
												document.createElement(
													'span'
												);
											amountSpan.className =
												'mhbo-extra-item-total';
											amountSpan.textContent =
												extraItem.formatted || '';

											row.appendChild( nameSpan );
											row.appendChild( amountSpan );
											extrasSummaryContainer.appendChild(
												row
											);
										}
									}
								);
							}
						}

						// Service fee cost row
						const serviceFeeRow = wrapper.querySelector(
							'.mhbo-service-fee-cost-row'
						);
						const serviceFeeEl = wrapper.querySelector(
							'.mhbo-service-fee-display'
						);
						if ( serviceFeeRow && serviceFeeEl ) {
							if (
								data.service_fee &&
								parseFloat( data.service_fee ) > 0 &&
								data.service_fee_formatted
							) {
								serviceFeeEl.textContent =
									data.service_fee_formatted;
								serviceFeeRow.style.display = 'flex';
							} else {
								serviceFeeRow.style.display = 'none';
								serviceFeeEl.textContent = '';
							}
						}

						// Synchronize top summary card coupon row and live validation feedback
						const couponSummaryRow = wrapper.querySelector(
							'.mhbo-coupon-cost-row'
						);
						if ( couponSummaryRow ) {
							const couponLabelEl = couponSummaryRow.querySelector(
								'.mhbo-coupon-label'
							);
							const couponAmountEl = couponSummaryRow.querySelector(
								'.mhbo-coupon-discount-display'
							);
							if (
								data.coupon_applied &&
								data.coupon_discount_formatted
							) {
								if ( couponLabelEl ) {
									couponLabelEl.textContent =
										( ( typeof mhbo_coupon !== 'undefined' &&
											mhbo_coupon.label_coupon ) ||
											'Coupon' ) +
										' ' +
										data.coupon_applied;
								}
								if ( couponAmountEl ) {
									couponAmountEl.innerHTML =
										'<strong>&minus;' +
										escapeHtml(
											data.coupon_discount_formatted
										) +
										'</strong>';
								}
								couponSummaryRow.style.display = 'flex';
							} else if ( ! data.coupon_applied ) {
								couponSummaryRow.style.display = 'none';
								if ( couponAmountEl ) {
									couponAmountEl.innerHTML = '';
								}
							}
						}

						if (
							data.coupon_valid === true &&
							data.coupon_message
						) {
							const couponMsgEl = wrapper.querySelector(
								'.mhbo-coupon-message'
							);
							if ( couponMsgEl ) {
								couponMsgEl.textContent = data.coupon_message;
								couponMsgEl.style.color = '#16a34a';
							}
						} else if ( data.coupon_valid === false ) {
							const appliedCouponInput = form.querySelector(
								'[name="mhbo_coupon_applied"]'
							);
							if ( appliedCouponInput ) {
								appliedCouponInput.value = '';
							}
							wrapper.dispatchEvent(
								new CustomEvent( 'mhboCouponInvalidated', {
									bubbles: true,
									detail: {
										message: data.coupon_error || '',
									},
								} )
							);
						}

						if ( arrivalTotalEl ) {
							arrivalTotalEl.textContent = data.total_formatted;
						}

						// Update tax breakdown (2026 BP: Robust detection)
						const taxContainer = wrapper.querySelector(
							'.mhbo-tax-breakdown-container, .mhbo-tax-summary, .mhbo-price-summary-totals'
						);
						if (
							taxContainer &&
							typeof data.tax_breakdown_html !== 'undefined'
						) {
							taxContainer.innerHTML = data.tax_breakdown_html;
							if ( data.tax_breakdown_html === '' ) {
								taxContainer.style.display = 'none';
							} else {
								taxContainer.style.display = 'block';
							}
							updateDepositVisibility();
						}

						// Update Payment Cards and first-night amount if deposit info returned
						const depositCard = form.querySelector(
							'.mhbo-payment-card[data-payment-type="deposit"]'
						);
						if (
							depositCard &&
							typeof data.first_night_total !== 'undefined'
						) {
							depositCard.dataset.firstNightAmount = String(
								data.first_night_total
							);
						}

						const depositWrapper = form.querySelector(
							'.mhbo-deposit-options-wrapper'
						);
						if ( data.deposit_amount_formatted ) {
							if (
								depositWrapper &&
								depositWrapper.style.display === 'none'
							) {
								depositWrapper.style.display = '';
							}

							if ( depositCard ) {
								const amountEl = depositCard.querySelector(
									'.mhbo-deposit-amount'
								);
								const balanceEl = depositCard.querySelector(
									'.mhbo-deposit-balance-display'
								);
								if ( amountEl ) {
									amountEl.textContent =
										data.deposit_amount_formatted;
								}
								if ( balanceEl ) {
									balanceEl.textContent =
										data.remaining_balance_formatted;
								}

								depositCard.dataset.amount =
									data.deposit_amount;
								depositCard.dataset.balance =
									data.remaining_balance;
							}

							const depositAmountDisplay = wrapper.querySelector(
								'#mhbo-deposit-amount-display'
							);
							const remainingBalanceDisplay =
								wrapper.querySelector(
									'#mhbo-remaining-balance-display'
								);
							if ( depositAmountDisplay ) {
								depositAmountDisplay.textContent =
									data.deposit_amount_formatted;
							}
							if ( remainingBalanceDisplay ) {
								remainingBalanceDisplay.textContent =
									data.remaining_balance_formatted;
							}

							const fullCard = form.querySelector(
								'.mhbo-payment-card[data-payment-type="full"]'
							);
							if ( fullCard ) {
								const fullAmountEl =
									fullCard.querySelector(
										'.mhbo-full-amount'
									);
								if ( fullAmountEl ) {
									fullAmountEl.textContent =
										data.total_formatted;
								}
								fullCard.dataset.amount = data.total;
							}
						} else if ( depositWrapper ) {
							depositWrapper.style.display = 'none';
							const fullRadio = depositWrapper.querySelector(
								'input[name="mhbo_payment_type"][value="full"]'
							);
							if ( fullRadio && ! fullRadio.checked ) {
								fullRadio.checked = true;
							}
						}

						// [Premium] Update individual extra pricing display
						if ( data.extras_breakdown ) {
							Object.entries( data.extras_breakdown ).forEach(
								( [ id, impact ] ) => {
									const card = form.querySelector(
										`.mhbo-extra-card[data-extra-id="${ id }"]`
									);
									if ( ! card ) {
										return;
									}

									const tag = card.querySelector(
										'.mhbo-extra-price-tag'
									);
									const breakdown = card.querySelector(
										'.mhbo-extra-price-breakdown'
									);

									// Unit price always shows the per-unit cost (large display)
									if ( tag && impact.unit_price_formatted ) {
										tag.textContent =
											impact.unit_price_formatted;
										tag.classList.add(
											'mhbo-impact-highlight'
										);
									}

									// Breakdown line: "× N = RON 40" when multiplier > 1
									if ( breakdown ) {
										const mult = impact.multiplier || 1;
										if ( mult > 1 && impact.value > 0 ) {
											breakdown.textContent =
												'× ' +
												mult +
												' = ' +
												impact.formatted;
										} else {
											breakdown.textContent = '';
										}
									}
								}
							);
						}

						// Sync dynamic stay timing, shifted check-in, and Early/Late availability
						if ( data.stay_timing && typeof data.stay_timing === 'object' ) {
							const st = data.stay_timing;
							const syncExtensionCard = ( extraId, info ) => {
								if ( ! info ) {
									return;
								}
								const card = form.querySelector(
									`.mhbo-arrival-option-card[data-extra-id="${ extraId }"]`
								);
								if ( ! card ) {
									return;
								}
								const cb = card.querySelector( 'input[type="checkbox"]' );
								const priceEl = card.querySelector( '.mhbo-arrival-option-price' );
								const reqBadge = card.querySelector( '.mhbo-arrival-option-badge-request' );
								const statusEl = card.querySelector( '.mhbo-arrival-option-status' );
								const isAvail = !! info.available;

								if ( priceEl ) {
									if ( info.is_free ) {
										priceEl.textContent = 'Complimentary';
										priceEl.classList.add( 'mhbo-arrival-option-badge-free' );
									} else {
										priceEl.textContent = '+' + ( info.fee_formatted || info.fee || '0.00' );
										priceEl.classList.remove( 'mhbo-arrival-option-badge-free' );
									}
								}

								if ( reqBadge ) {
									reqBadge.hidden = ! ( isAvail && info.request_only );
								}

								card.classList.toggle( 'is-unavailable', ! isAvail );
								if ( cb ) {
									cb.disabled = ! isAvail;
									if ( ! isAvail && cb.checked ) {
										cb.checked = false;
										card.classList.remove( 'is-selected', 'selected' );
										recalcDirty = true;
									}
								}
								if ( statusEl ) {
									if ( ! isAvail && info.message ) {
										statusEl.textContent = info.message;
										statusEl.hidden = false;
									} else {
										statusEl.textContent = '';
										statusEl.hidden = true;
									}
								}
							};

							syncExtensionCard( '_early_checkin', st.early_checkin );
							syncExtensionCard( '_late_checkout', st.late_checkout );

							const ciVal = wrapper.querySelector( '.mhbo-checkin-time-val' );
							if ( ciVal ) {
								if ( st.effective_checkin ) {
									ciVal.dataset.standard = st.effective_checkin;
								}
								if ( st.early_checkin && st.early_checkin.time ) {
									ciVal.dataset.early = st.early_checkin.time;
								}
								const ecCb = form.querySelector(
									'.mhbo-arrival-option-card[data-extra-id="_early_checkin"] input[type="checkbox"]'
								);
								const useEarly = !!( ecCb && ecCb.checked && ! ecCb.disabled && ciVal.dataset.early );
								ciVal.textContent = useEarly
									? ciVal.dataset.early
									: ( ciVal.dataset.standard || ciVal.textContent );
							}

							const coVal = wrapper.querySelector( '.mhbo-checkout-time-val' );
							if ( coVal ) {
								if ( st.standard_checkout ) {
									coVal.dataset.standard = st.standard_checkout;
								}
								if ( st.late_checkout && st.late_checkout.time ) {
									coVal.dataset.late = st.late_checkout.time;
								}
								const lcCb = form.querySelector(
									'.mhbo-arrival-option-card[data-extra-id="_late_checkout"] input[type="checkbox"]'
								);
								const useLate = !!( lcCb && lcCb.checked && ! lcCb.disabled && coVal.dataset.late );
								coVal.textContent = useLate
									? coVal.dataset.late
									: ( coVal.dataset.standard || coVal.textContent );
							}

							const shiftNotice = wrapper.querySelector( '.mhbo-checkin-shift-notice' );
							if ( shiftNotice ) {
								const ecCb = form.querySelector(
									'.mhbo-arrival-option-card[data-extra-id="_early_checkin"] input[type="checkbox"]'
								);
								const showShift = !!( st.checkin_shifted && ! ( ecCb && ecCb.checked ) );
								if ( showShift ) {
									shiftNotice.textContent =
										'Arrival adjusted to ' +
										st.effective_checkin +
										' (standard ' +
										( st.standard_checkin || '' ) +
										') for room turnaround.';
									shiftNotice.hidden = false;
								} else {
									shiftNotice.textContent = '';
									shiftNotice.hidden = true;
								}
							}

							if ( recalcDirty ) {
								recalcDirty = false;
								setTimeout( () => recalculatePrice(), 0 );
							}
						}

						// Toggle deposit/balance rows based on selection
						updateDepositVisibility();

						window.dispatchEvent(
							new CustomEvent( 'mhbo_price_updated', {
								detail: { data, instance: wrapper },
							} )
						);

						// Sync Stripe intent if active
						if (
							typeof window.mhboRefreshStripeIntent === 'function'
						) {
							window.mhboRefreshStripeIntent();
						}
					} else {
						debugLog( 'Recalculate failed:', data.message );
						// [CRITICAL 2026 FIX] Show error to user in a consistent way
						const errorContainer = wrapper.querySelector(
							'.mhbo-booking-errors, .mhbo-errors-container'
						);
						if ( errorContainer ) {
							const errEl = document.createElement( 'div' );
							errEl.className = 'mhbo-error';
							errEl.setAttribute( 'role', 'alert' );
							errEl.setAttribute( 'aria-live', 'assertive' );
							errEl.textContent =
								data.message || 'Error recalculating price.';
							errorContainer.replaceChildren( errEl );
							errorContainer.style.display = 'block';
							setTimeout( () => {
								errorContainer.style.display = 'none';
							}, 5000 );
						}
					}
				} catch ( err ) {
					if ( err.name === 'AbortError' ) {
						return;
					} // Superseded by a newer request — not an error.
					debugLog( 'Recalculate error:', err );
				} finally {
					recalcAbortController = null;
					if ( mySeq === recalcSeq && ! recalcDirty ) {
						totalDisplays.forEach( function ( el ) {
							el.classList.remove( 'mhbo_faded' );
						} );
						if ( arrivalTotalEl ) {
							arrivalTotalEl.classList.remove( 'mhbo_faded' );
						}

						updateSubmitState();
					}
				}
		}

		/**
		 * Update visibility of deposit-related summary rows and wrapper.
		 * 2026 BP: Also toggles .mhbo-deposit-breakdown-summary wrapper — required because
		 * Tax.php renders the wrapper hidden by default on the booking form (payment_type='full').
		 */
		function updateDepositVisibility() {
			const paymentType = form.querySelector(
				'input[name="mhbo_payment_type"]:checked'
			);
			const isDeposit = paymentType && paymentType.value === 'deposit';

			wrapper
				.querySelectorAll(
					'.mhbo-deposit-amount-row, .mhbo-remaining-balance-row'
				)
				.forEach( ( row ) => {
					row.style.display = isDeposit ? 'table-row' : 'none';
				} );

			// Show/hide the deposit summary section wrapper
			wrapper
				.querySelectorAll( '.mhbo-deposit-breakdown-summary' )
				.forEach( ( el ) => {
					el.style.display = isDeposit ? '' : 'none';
				} );
		}

		/**
		 * Restore submit button interactive state after async recalculations.
		 * Keep the button clickable so missing required fields or GDPR consent
		 * always trigger clear inline validation messages on click.
		 */
		function updateSubmitState() {
			if ( ! submitBtn ) {
				return;
			}
			submitBtn.disabled = false;
			submitBtn.classList.remove( 'loading' );
			submitBtn.style.opacity = '';
			submitBtn.style.cursor = '';
		}

		/**
		 * Clear field-level error state as soon as the user corrects an input or checkbox.
		 *
		 * @param {Event} e Input or change event.
		 */
		function clearFieldErrorOnInteract( e ) {
			const target = e.target;
			if ( ! target || ! target.tagName ) {
				return;
			}

			if (
				target.type === 'checkbox' &&
				( target.name === 'mhbo_terms_accepted' ||
					target.id === 'mhbo-terms-consent' ||
					target.classList.contains( 'mhbo-terms-consent-input' ) )
			) {
				if ( target.checked ) {
					target.removeAttribute( 'aria-invalid' );
					const termsGroup =
						target.closest(
							'.mhbo-terms-consent, .mhbo-form-group'
						) || target.parentElement;
					if ( termsGroup ) {
						termsGroup.classList.remove( 'mhbo-terms-error' );
						const msg = termsGroup.querySelector(
							'.mhbo-field-error-msg'
						);
						if ( msg ) {
							msg.remove();
						}
					}
				}
			} else if (
				target.type === 'checkbox' &&
				( target.name === 'mhbo_consent' ||
					target.id === 'mhbo-consent' ||
					target.classList.contains( 'mhbo-consent' ) )
			) {
				if ( target.checked ) {
					target.removeAttribute( 'aria-invalid' );
					const gdprGroup =
						target.closest(
							'.mhbo-gdpr-consent, .mhbo-form-group'
						) || target.parentElement;
					if ( gdprGroup ) {
						gdprGroup.classList.remove( 'mhbo-gdpr-error' );
						const msg = gdprGroup.querySelector(
							'.mhbo-field-error-msg'
						);
						if ( msg ) {
							msg.remove();
						}
					}
				}
			} else if ( target.classList.contains( 'mhbo-input-error' ) ) {
				const val =
					typeof target.value === 'string'
						? target.value.trim()
						: '';
				if ( val !== '' ) {
					target.classList.remove( 'mhbo-input-error' );
					target.removeAttribute( 'aria-invalid' );
					target.style.borderColor = '';
					const group =
						target.closest(
							'.mhbo-form-group, .mhbo-child-age-group'
						) || target.parentElement;
					if ( group ) {
						const msg = group.querySelector(
							'.mhbo-field-error-msg'
						);
						if ( msg ) {
							msg.remove();
						}
					}
				}
			}

			if (
				errorBox &&
				errorBox.style.display !== 'none' &&
				form.querySelectorAll(
					'.mhbo-input-error, .mhbo-gdpr-error, .mhbo-terms-error'
				).length === 0
			) {
				errorBox.style.display = 'none';
				errorBox.innerHTML = '';
			}
		}

		form.addEventListener( 'input', clearFieldErrorOnInteract );
		form.addEventListener( 'change', clearFieldErrorOnInteract );

		if ( childrenSelect ) {
			childrenSelect.addEventListener( 'change', function () {
				const count = parseInt( this.value );
				if ( count > 0 ) {
					agesContainer.style.display = 'block';
					let html = '';
					const agePlaceholder =
						typeof mhbo_vars !== 'undefined' &&
						mhbo_vars.label_child_age_placeholder
							? mhbo_vars.label_child_age_placeholder
							: 'Age';
					for ( let i = 0; i < count; i++ ) {
						const label = mhbo_vars.label_child_n_age.replace(
							'%d',
							i + 1
						);
						html +=
							'<div class="mhbo-child-age-group">' +
							'<label>' +
							escapeHtml( label ) +
							' <span class="required">*</span></label>' +
							'<input type="number" name="child_ages[]" value="" placeholder="' +
							escapeHtml( agePlaceholder ) +
							'" min="0" max="17" required class="mhbo-child-age-input">' +
							'</div>';
					}
					agesInputs.innerHTML = html;
				} else {
					agesContainer.style.display = 'none';
					agesInputs.innerHTML = '';
				}
				scheduleRecalculate( 0 );
			} );

			wrapper.addEventListener( 'input', function ( e ) {
				if (
					e.target &&
					e.target.classList.contains( 'mhbo-child-age-input' )
				) {
					scheduleRecalculate( 250 );
				}
			} );

			wrapper.addEventListener( 'change', function ( e ) {
				if (
					e.target &&
					e.target.classList.contains( 'mhbo-child-age-input' )
				) {
					if ( recalcDirty || debounceTimer ) {
						scheduleRecalculate( 0 );
					}
				}
			} );
		}

		if ( guestsSelect ) {
			guestsSelect.addEventListener( 'change', recalculatePrice );
		}

		// Quantity stepper buttons — update the input directly (it IS the form field).
		wrapper.addEventListener( 'click', function ( e ) {
			const qtyBtn = e.target.closest( '.mhbo-qty-btn' );
			if ( ! qtyBtn ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			const card = qtyBtn.closest( '.mhbo-extra-card' );
			if ( ! card ) {
				return;
			}
			const input = card.querySelector( '.mhbo-extra-input' );
			if ( ! input ) {
				return;
			}
			let val = parseFloat( input.value ) || 0;
			val = qtyBtn.classList.contains( 'plus' )
				? val + 1
				: Math.max( 0, val - 1 );
			input.value = val;
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );

		// Sync card selected state + trigger price recalculation on any extra change.
		wrapper.addEventListener( 'change', function ( e ) {
			const input = e.target.closest(
				'.mhbo-extra-input, .mhbo-booking-extra-checkbox'
			);
			if ( ! input ) {
				return;
			}
			const card = input.closest(
				'.mhbo-extra-card, .mhbo-arrival-option-card'
			);
			const selected =
				input.type === 'checkbox'
					? input.checked
					: parseFloat( input.value ) > 0;
			if ( card ) {
				card.classList.toggle( 'selected', selected );
				card.classList.toggle( 'is-selected', selected );
			}

			// Live update check-in / check-out times in the stay dates overview bar
			const extraId = input.dataset.extraId || input.name || '';
			if (
				extraId === '_early_checkin' ||
				extraId.indexOf( '_early_checkin' ) !== -1
			) {
				const ciVal = wrapper.querySelector( '.mhbo-checkin-time-val' );
				if ( ciVal ) {
					ciVal.textContent =
						selected && ciVal.dataset.early
							? ciVal.dataset.early
							: ciVal.dataset.standard || ciVal.textContent;
				}
				const shiftNotice = wrapper.querySelector( '.mhbo-checkin-shift-notice' );
				if ( shiftNotice && shiftNotice.textContent.trim() !== '' ) {
					shiftNotice.hidden = selected;
				}
			}
			if (
				extraId === '_late_checkout' ||
				extraId.indexOf( '_late_checkout' ) !== -1
			) {
				const coVal = wrapper.querySelector(
					'.mhbo-checkout-time-val'
				);
				if ( coVal ) {
					coVal.textContent =
						selected && coVal.dataset.late
							? coVal.dataset.late
							: coVal.dataset.standard || coVal.textContent;
				}
			}

			recalculatePrice();
		} );

		// Immediate recalculation when typing directly in extra quantity number fields
		wrapper.addEventListener( 'input', function ( e ) {
			const input = e.target.closest(
				'.mhbo-extra-input[type="number"]'
			);
			if ( input ) {
				const card = input.closest( '.mhbo-extra-card' );
				const selected = parseFloat( input.value ) > 0;
				if ( card ) {
					card.classList.toggle( 'selected', selected );
					card.classList.toggle( 'is-selected', selected );
				}
				recalculatePrice();
			}
		} );

		// [CRITICAL 2026 FIX] Add listeners for check-in/out to trigger recalculation when calendar updates them
		const dateInputs = wrapper.querySelectorAll(
			'input[name="check_in"], input[name="check_out"]'
		);
		dateInputs.forEach( ( input ) => {
			input.addEventListener( 'change', recalculatePrice );
		} );

		// Payment Type Change
		wrapper.addEventListener( 'change', function ( e ) {
			const ptInput = e.target.closest(
				'input[name="mhbo_payment_type"]'
			);
			if ( ! ptInput ) {
				return;
			}
			// UI feedback
			wrapper
				.querySelectorAll( '.mhbo-payment-card' )
				.forEach( ( c ) => c.classList.remove( 'active' ) );
			const card = ptInput.closest( '.mhbo-payment-card' );
			if ( card ) {
				card.classList.add( 'active' );
			}

			// Immediately update deposit row visibility so the summary reflects
			// the selection before the async recalculate response arrives.
			updateDepositVisibility();
			recalculatePrice();
		} );

		// Listen for coupon application or removal to ensure 100% price parity
		wrapper.addEventListener( 'mhboCouponApplied', function ( e ) {
			if ( e && e.detail && e.detail.skipRecalculate ) {
				return;
			}
			scheduleRecalculate( 0 );
		} );
		wrapper.addEventListener( 'mhboCouponRemoved', function () {
			scheduleRecalculate( 0 );
		} );

		if ( termsCheckbox ) {
			termsCheckbox.addEventListener( 'change', updateSubmitState );
		}
		if ( consentCheckbox ) {
			consentCheckbox.addEventListener( 'change', updateSubmitState );
		}
		updateSubmitState();

		// Initial visibility
		updateDepositVisibility();

		/**
		 * Form submission validation
		 */
		form.addEventListener( 'submit', function ( e ) {
			const wasFlushed = form.dataset.mhboRecalcFlushed === '1';
			delete form.dataset.mhboRecalcFlushed;

			if ( errorBox ) {
				errorBox.style.display = 'none';
			}

			if ( ! window.mhboValidateBookingForm( form, false ) ) {
				e.preventDefault();
				if ( typeof e.stopImmediatePropagation === 'function' ) {
					e.stopImmediatePropagation();
				}
				return;
			}

			if (
				! wasFlushed &&
				typeof window.mhboHasPendingPriceRecalculation === 'function' &&
				window.mhboHasPendingPriceRecalculation( form )
			) {
				e.preventDefault();
				if ( typeof e.stopImmediatePropagation === 'function' ) {
					e.stopImmediatePropagation();
				}
				if ( submitBtn ) {
					submitBtn.disabled = true;
					submitBtn.classList.add( 'loading' );
				}
				window.mhboFlushPriceRecalculation( form )
					.then( function () {
						updateSubmitState();
						form.dataset.mhboRecalcFlushed = '1';
						if ( typeof form.requestSubmit === 'function' ) {
							form.requestSubmit();
						} else {
							form.dispatchEvent(
								new Event( 'submit', {
									bubbles: true,
									cancelable: true,
								} )
							);
						}
					} )
					.catch( function () {
						updateSubmitState();
					} );
				return;
			}

			const method = form.querySelector(
				'input[name="mhbo_payment_method"]:checked'
			);
			const methodValue = method ? method.value : 'arrival';

			if ( methodValue === 'stripe' ) {
				const piInput = form.querySelector(
					'input[name="mhbo_stripe_payment_intent"]'
				);
				if ( ! piInput || ! piInput.value ) {
					return; // Hand off to PaymentGateways.js
				}
				// PI confirmed — fall through to unified REST path
			}

			if ( methodValue === 'paypal' ) {
				const paypalOrderInput = form.querySelector(
					'input[name="mhbo_paypal_order_id"]'
				);
				if ( ! paypalOrderInput || ! paypalOrderInput.value ) {
					e.preventDefault();
					showInstanceError(
						mhbo_vars.msg_paypal_required ||
							'Please use the PayPal button to complete payment.'
					);
					return;
				}
				// PayPal confirmed — fall through to unified REST path
			}

			if ( methodValue === 'braintree' ) {
				e.preventDefault();
				if ( typeof e.stopPropagation === 'function' ) {
					e.stopPropagation();
				}
				return; // Hand off exclusively to PaymentGateways.js
			}

			// [2026 BP] REST API intercept for all remaining payment methods (arrival, bank transfer, etc.)
			e.preventDefault();

			// Clear ALL previous error boxes in wrapper/form/modal before processing
			const searchCtx =
				( typeof form.closest === 'function'
					? form.closest( '.mhbo-booking-form-wrapper' )
					: null ) ||
				( typeof document.querySelector === 'function'
					? document
					: null );
			if ( searchCtx && searchCtx.querySelectorAll ) {
				searchCtx
					.querySelectorAll(
						'#mhbo-booking-error, .mhbo-booking-error, .mhbo-error-notification, .mhbo-booking-errors, .mhbo-inline-errors, .mhbo-message.mhbo-error'
					)
					.forEach( function ( box ) {
						box.style.display = 'none';
						box.innerHTML = '';
					} );
			}

			const origSubmitBtnText = submitBtn ? submitBtn.textContent : '';
			if ( submitBtn ) {
				submitBtn.disabled = true;
				submitBtn.classList.add( 'loading' );
				submitBtn.textContent = mhbo_vars.processing || 'Processing...';
			}

			const roomIdInput = form.querySelector(
				'input[name="mhbo_room_id"]'
			);
			const roomId = roomIdInput ? roomIdInput.value : 0;
			const typeIdInput = form.querySelector(
				'input[name="mhbo_type_id"]'
			);
			const typeId = typeIdInput ? typeIdInput.value : 0;
			const checkInInput = form.querySelector( 'input[name="check_in"]' );
			const checkIn = checkInInput ? checkInInput.value : '';
			const checkOutInput = form.querySelector(
				'input[name="check_out"]'
			);
			const checkOut = checkOutInput ? checkOutInput.value : '';
			const guests = guestsSelect ? guestsSelect.value : 1;
			const children = childrenSelect ? childrenSelect.value : 0;

			const extras = {};
			form.querySelectorAll( '.mhbo-extra-input, .mhbo-booking-extra-checkbox' ).forEach(
				function ( input ) {
					let val = 0;
					if ( input.type === 'checkbox' ) {
						if ( input.checked ) {
							val = 1;
						}
					} else {
						val = input.value;
					}
					if ( val && parseFloat( val ) > 0 ) {
						const extraId = input.dataset.extraId;
						if ( extraId ) {
							extras[ extraId ] = val;
						} else {
							const match = input.name.match( /\[(.*?)\]/ );
							if ( match ) {
								extras[ match[ 1 ] ] = val;
							}
						}
					}
				}
			);

			const paymentTypeInput = form.querySelector(
				'input[name="mhbo_payment_type"]:checked'
			);
			const paymentType = paymentTypeInput
				? paymentTypeInput.value
				: 'full';

			const childrenAges = [];
			form.querySelectorAll( 'input[name="child_ages[]"]' ).forEach(
				function ( input ) {
					const v = input.value.trim();
					if ( v !== '' ) {
						childrenAges.push( parseInt( v, 10 ) );
					}
				}
			);

			const customFields = {};
			form.querySelectorAll(
				'input[name^="mhbo_custom["], textarea[name^="mhbo_custom["]'
			).forEach( function ( input ) {
				const match = input.name.match( /mhbo_custom\[(.*?)\]/ );
				if ( match ) {
					customFields[ match[ 1 ] ] = input.value;
				}
			} );

			const termsEl = form.querySelector(
				'input[name="mhbo_terms_accepted"]'
			);
			const termsAccepted = termsEl ? termsEl.checked : false;

			const consentEl = form.querySelector(
				'input[name="mhbo_consent"]'
			);
			const consent = consentEl ? consentEl.checked : false;

			const body = {
				room_id: roomId,
				type_id: typeId,
				check_in: checkIn,
				check_out: checkOut,
				customer_name:
					form.querySelector( 'input[name="customer_name"]' )
						?.value || '',
				customer_email:
					form.querySelector( 'input[name="customer_email"]' )
						?.value || '',
				customer_phone:
					form.querySelector( 'input[name="customer_phone"]' )
						?.value || '',
				guests,
				children,
				child_ages: childrenAges,
				extras,
				payment_method: methodValue,
				payment_type: paymentType,
				custom_fields: customFields,
				admin_notes:
					form.querySelector( 'textarea[name="admin_notes"]' )
						?.value || '',
				update_id:
					form.querySelector( 'input[name="mhbo_update_id"]' )
						?.value || 0,
				terms_accepted: termsAccepted,
				consent,
				booking_language: mhbo_vars.language || 'en',
				page_url: window.location.href,
				stripe_pi: ( function () {
					const el = form.querySelector(
						'input[name="mhbo_stripe_payment_intent"]'
					);
					return el ? el.value : '';
				} )(),
				paypal_order_id: ( function () {
					const el = form.querySelector(
						'input[name="mhbo_paypal_order_id"]'
					);
					return el ? el.value : '';
				} )(),
				paypal_capture_id: ( function () {
					const el = form.querySelector(
						'input[name="mhbo_paypal_capture_id"]'
					);
					return el ? el.value : '';
				} )(),
				booking_token: ( function () {
					const el = form.querySelector(
						'input[name="mhbo_booking_token"]'
					);
					return el ? el.value : '';
				} )(),
				resume_nonce: ( function () {
					const el = form.querySelector(
						'input[name="mhbo_resume_nonce"]'
					);
					return el ? el.value : '';
				} )(),
				session_token: ( function () {
					const el = form.querySelector(
						'input[name="mhbo_session_token"]'
					);
					return el ? el.value : '';
				} )(),
				mhbo_coupon_code: ( function () {
					const el = form.querySelector(
						'[name="mhbo_coupon_applied"]'
					);
					return el ? el.value.trim() : '';
				} )(),
			};

			fetch( mhbo_vars.api_base + '/booking/complete', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': mhbo_vars.nonce,
				},
				body: JSON.stringify( body ),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					// Normalize between legacy AJAX (result.success+result.data) and direct REST response
					const successData =
						result.success && result.data ? result.data : result;

					if ( successData.redirect_url ) {
						const isModalContext = !! (
							form.closest( '[data-modal-context="1"]' ) ||
							( wrapper &&
								wrapper.dataset &&
								wrapper.dataset.modalContext === '1' )
						);
						if ( isModalContext ) {
							// Modal path: parse token + status from redirect URL,
							// hand to Phase 5 confirmation handler.
							let bookingToken = '';
							let bookingStatus = 'confirmed';
							try {
								const u = new URL(
									successData.redirect_url,
									window.location.href
								);
								bookingToken =
									u.searchParams.get( 'reference' ) || '';
								bookingStatus =
									u.searchParams.get( 'mhbo_status' ) ||
									'confirmed';
							} catch ( _ ) {}
							document.dispatchEvent(
								new CustomEvent( 'mhboBookingComplete', {
									detail: {
										booking_token: bookingToken,
										status: bookingStatus,
									},
								} )
							);
						} else {
							window.location.href = successData.redirect_url;
						}
					} else {
						const msg =
							successData.message ||
							mhbo_vars.label_generic_error ||
							'Booking failed. Please try again.';
						showInstanceError( msg );
						if ( submitBtn ) {
							submitBtn.disabled = false;
							submitBtn.classList.remove( 'loading' );
							submitBtn.textContent =
								origSubmitBtnText ||
								mhbo_vars.confirm ||
								'Confirm Booking';
						}
					}
				} )
				.catch( function ( err ) {
					console.error( '[MHBO] REST API Error:', err );
					showInstanceError(
						mhbo_vars.label_network_error ||
							'A network error occurred. Please try again.'
					);
					if ( submitBtn ) {
						submitBtn.disabled = false;
						submitBtn.classList.remove( 'loading' );
						submitBtn.textContent =
							origSubmitBtnText ||
							mhbo_vars.confirm ||
							'Confirm Booking';
					}
				} );
		} );

		function showInstanceError( message ) {
			window.mhboShowBookingError( message, wrapper );
		}

		// Wire mobile sticky bar CTA button and intersection observer
		const mobileStickyBar = wrapper.querySelector(
			'.mhbo-mobile-sticky-bar'
		);
		const mobileScrollBtn = wrapper.querySelector(
			'.mhbo-mobile-scroll-btn'
		);
		if ( mobileScrollBtn && submitBtn ) {
			mobileScrollBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				submitBtn.scrollIntoView( {
					behavior: 'smooth',
					block: 'center',
				} );
				submitBtn.focus();
			} );
		}

		if ( mobileStickyBar && submitBtn && 'IntersectionObserver' in window ) {
			const submitContainer =
				wrapper.querySelector( '.mhbo-submit-container' ) || submitBtn;
			const observer = new IntersectionObserver(
				function ( entries ) {
					entries.forEach( function ( entry ) {
						if ( entry.isIntersecting ) {
							mobileStickyBar.classList.add( 'is-hidden' );
						} else {
							mobileStickyBar.classList.remove( 'is-hidden' );
						}
					} );
				},
				{
					threshold: 0.1,
					rootMargin: '0px 0px -40px 0px',
				}
			);
			observer.observe( submitContainer );
		}
	} // end initBookingFormWrapper

	// Initialize forms present on page load
	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof mhbo_vars === 'undefined' ) {
			debugLog( 'mhbo_vars is not defined.' );
		}
		document
			.querySelectorAll( '.mhbo-booking-form-wrapper' )
			.forEach( initBookingFormWrapper );
	} );

	// Re-initialize when the modal injects new booking form content or Gutenberg Interactivity API store fires
	document.addEventListener( 'mhboModalContent', function () {
		document
			.querySelectorAll( '.mhbo-booking-form-wrapper' )
			.forEach( initBookingFormWrapper );
	} );
	document.addEventListener( 'mhbo_init_form', function ( e ) {
		if ( e && e.target && e.target.closest ) {
			const targetWrapper =
				e.target.closest( '.mhbo-booking-form-wrapper' ) ||
				e.target.querySelector( '.mhbo-booking-form-wrapper' );
			if ( targetWrapper ) {
				initBookingFormWrapper( targetWrapper );
				return;
			}
		}
		document
			.querySelectorAll( '.mhbo-booking-form-wrapper' )
			.forEach( initBookingFormWrapper );
	} );
} )();
