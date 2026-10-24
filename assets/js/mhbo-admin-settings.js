/**
 * Modern Hotel Booking - Admin Settings
 *
 * Handles admin settings page interactions (Pure Vanilla JS / WP 7.1 Standard - Zero jQuery):
 * - License activation/deactivation/verification
 * - Custom fields repeater with multilingual labels
 * - Holiday dates management
 * - Theme selection & real-time preview
 * - Payment gateway live credential verifications (Stripe, PayPal, Braintree)
 * - Cache clear handler
 * - Gap settings & CRM VIP threshold visibility toggles
 *
 * @package
 * @since 2.6.0
 */

( function () {
	'use strict';

	// Configuration is injected via wp_add_inline_script()
	const config = window.mhboAdminSettingsConfig || {};

	/**
	 * Unified REST client with wp.apiFetch and window.fetch fallback (WP 7.1 / 6.6+ parity)
	 *
	 * @param {string} path   API route relative to namespace or full /wp-json path.
	 * @param {string} method HTTP method (GET, POST, DELETE).
	 * @param {Object} [data] Payload object.
	 * @return {Promise<Object>}
	 */
	function fetchApi( path, method, data ) {
		if ( window.wp && typeof window.wp.apiFetch === 'function' ) {
			const opts = {
				path,
				method: method || 'GET',
				headers: {
					'X-WP-Nonce': config.restNonce || '',
				},
			};
			if ( data && method !== 'GET' ) {
				opts.data = data;
			}
			return window.wp.apiFetch( opts );
		}

		const rootUrl =
			config && config.restUrl
				? config.restUrl.replace( /\/mhbo\/v1\/?$/, '' )
				: '';
		const url = path.startsWith( 'http' )
			? path
			: rootUrl
			? rootUrl + ( path.startsWith( '/' ) ? path : '/' + path )
			: '/wp-json' + ( path.startsWith( '/' ) ? path : '/' + path );
		const fetchOptions = {
			method: method || 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.restNonce || '',
			},
		};
		if ( data && method !== 'GET' ) {
			fetchOptions.body = JSON.stringify( data );
		}
		return fetch( url, fetchOptions ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( ! res.ok ) {
					const err = new Error(
						( json && json.message ) || 'Request failed'
					);
					err.responseJSON = json;
					throw err;
				}
				return json;
			} );
		} );
	}

	/**
	 * License Management
	 */
	function initLicenseManagement() {
		const activateBtn = document.getElementById( 'mhbo_activate_license' );
		const deactivateBtn = document.getElementById(
			'mhbo_deactivate_license'
		);
		const checkBtn = document.getElementById( 'mhbo_check_license_btn' );
		const spinner = document.getElementById( 'mhbo_license_spinner' );
		const message = document.getElementById( 'mhbo_license_message' );

		if ( ! activateBtn && ! deactivateBtn && ! checkBtn ) {
			return;
		}

		if ( checkBtn ) {
			checkBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( spinner ) {
					spinner.classList.add( 'is-active' );
				}
				if ( message ) {
					message.textContent = '';
				}

				fetchApi( '/mhbo/v1/admin/system/license/status', 'GET' )
					.then( function ( response ) {
						if ( spinner ) {
							spinner.classList.remove( 'is-active' );
						}
						const msg =
							( response &&
								response.data &&
								response.data.message ) ||
							( response && response.message ) ||
							'Status checked.';
						const color =
							response && response.data && response.data.warning
								? '#ea580c'
								: '#16a34a';
						if ( message ) {
							message.innerHTML =
								'<span style="color:' +
								color +
								'; font-weight:600;">' +
								msg +
								'</span>';
						}
						setTimeout( function () {
							location.reload();
						}, 1500 );
					} )
					.catch( function ( err ) {
						if ( spinner ) {
							spinner.classList.remove( 'is-active' );
						}
						const errorMsg =
							( err && err.message ) ||
							( config.i18n && config.i18n.connection_error ) ||
							'Error';
						if ( message ) {
							message.innerHTML =
								'<span style="color:#dc2626; font-weight:600;">' +
								errorMsg +
								'</span>';
						}
					} );
			} );
		}

		if ( activateBtn ) {
			activateBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				const keyInput = document.getElementById( 'mhbo_license_key' );
				const key = keyInput ? keyInput.value.trim() : '';
				if ( ! key ) {
					alert(
						( config.i18n && config.i18n.enter_license_key ) ||
							'Please enter a license key.'
					);
					return;
				}
				if ( spinner ) {
					spinner.classList.add( 'is-active' );
				}
				if ( message ) {
					message.textContent = '';
				}

				fetchApi( '/mhbo/v1/admin/system/license/activate', 'POST', {
					license_key: key,
				} )
					.then( function ( response ) {
						if ( spinner ) {
							spinner.classList.remove( 'is-active' );
						}
						const msg =
							( response &&
								response.data &&
								response.data.message ) ||
							( response && response.message ) ||
							'License activated.';
						if ( message ) {
							message.innerHTML =
								'<span style="color:#16a34a; font-weight:600;">' +
								msg +
								'</span>';
						}
						setTimeout( function () {
							location.reload();
						}, 1500 );
					} )
					.catch( function ( err ) {
						if ( spinner ) {
							spinner.classList.remove( 'is-active' );
						}
						const errorMsg =
							( err && err.message ) ||
							( config.i18n && config.i18n.connection_error ) ||
							'Error';
						if ( message ) {
							message.innerHTML =
								'<span style="color:#dc2626; font-weight:600;">' +
								errorMsg +
								'</span>';
						}
					} );
			} );
		}

		if ( deactivateBtn ) {
			deactivateBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if (
					! confirm(
						( config.i18n && config.i18n.are_you_sure ) ||
							'Are you sure?'
					)
				) {
					return;
				}

				if ( spinner ) {
					spinner.classList.add( 'is-active' );
				}
				fetchApi( '/mhbo/v1/admin/system/license/deactivate', 'POST' )
					.then( function () {
						if ( spinner ) {
							spinner.classList.remove( 'is-active' );
						}
						location.reload();
					} )
					.catch( function ( err ) {
						if ( spinner ) {
							spinner.classList.remove( 'is-active' );
						}
						alert(
							( err && err.message ) ||
								'Error deactivating license.'
						);
					} );
			} );
		}
	}

	/**
	 * Custom Fields Repeater
	 */
	function initCustomFieldsRepeater() {
		const addBtn = document.getElementById( 'mhbo-add-custom-field' );
		const repeater = document.getElementById(
			'mhbo-custom-fields-repeater'
		);

		if ( ! addBtn || ! repeater ) {
			return;
		}

		const langLabels = config.langLabels || {};

		addBtn.addEventListener( 'click', function () {
			const itemsContainer = repeater.querySelector(
				'.mhbo-repeater-items'
			);
			const index = repeater.querySelectorAll(
				'.mhbo-repeater-item'
			).length;
			let langFields = '';

			Object.keys( langLabels ).forEach( function ( lang ) {
				langFields +=
					'<div style="display: flex; align-items: center; margin-bottom: 5px;">' +
					'<span style="width: 35px; font-weight: 600; font-size: 11px;">' +
					lang.toUpperCase() +
					':</span>' +
					'<input type="text" name="mhbo_custom_fields[' +
					index +
					'][label][' +
					lang +
					']" value="" class="widefat" style="flex: 1;"></div>';
			} );

			const itemDiv = document.createElement( 'div' );
			itemDiv.className = 'mhbo-repeater-item';
			itemDiv.style.cssText =
				'background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 15px; position: relative; border-radius: 6px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);';
			itemDiv.innerHTML =
				'<button type="button" class="mhbo-remove-field" style="position: absolute; top: 10px; right: 10px; color: #d63638; background: none; border: none; font-size: 20px; cursor: pointer; padding: 0;">&times;</button>' +
				'<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">' +
				'<div>' +
				'<label style="display: block; font-weight: bold; margin-bottom: 5px;">Field ID (slug)</label>' +
				'<input type="text" name="mhbo_custom_fields[' +
				index +
				'][id]" value="" class="widefat" placeholder="slug" required>' +
				'</div>' +
				'<div>' +
				'<label style="display: block; font-weight: bold; margin-bottom: 5px;">Type</label>' +
				'<select name="mhbo_custom_fields[' +
				index +
				'][type]" class="widefat"><option value="text">Text</option><option value="number">Number</option><option value="textarea">Textarea</option></select>' +
				'</div>' +
				'</div>' +
				'<div style="margin-bottom: 12px;">' +
				'<label style="display: block; font-weight: bold; margin-bottom: 8px;">Label (Multilingual)</label>' +
				langFields +
				'</div>' +
				'<div><label style="font-weight: bold;"><input type="checkbox" name="mhbo_custom_fields[' +
				index +
				'][required]" value="1"> Required Field</label></div>';

			if ( itemsContainer ) {
				itemsContainer.appendChild( itemDiv );
			} else {
				repeater.appendChild( itemDiv );
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			const removeBtn = e.target.closest( '.mhbo-remove-field' );
			if ( removeBtn ) {
				if (
					confirm(
						( config.i18n && config.i18n.remove_field_confirm ) ||
							'Are you sure you want to remove this field?'
					)
				) {
					const item = removeBtn.closest( '.mhbo-repeater-item' );
					if ( item ) {
						item.remove();
					}
				}
			}
		} );
	}

	/**
	 * Holiday Dates Management
	 */
	function initHolidayManagement() {
		const list = document.getElementById( 'mhbo-holiday-list' );
		const hidden = document.getElementById( 'mhbo-holiday-dates-hidden' );
		const newDate = document.getElementById( 'mhbo-new-holiday' );
		const addBtn = document.getElementById( 'mhbo-add-holiday-btn' );

		if ( ! list || ! hidden ) {
			return;
		}

		function updateList() {
			const dates = hidden.value
				? hidden.value
						.split( ',' )
						.map( ( d ) => d.trim() )
						.filter( ( d ) => /^\d{4}-\d{2}-\d{2}$/.test( d ) )
						.sort()
				: [];

			while ( list.firstChild ) {
				list.removeChild( list.firstChild );
			}

			if ( dates.length === 0 ) {
				const empty = document.createElement( 'div' );
				empty.style.cssText = 'color:#94a3b8; font-style:italic; padding:5px;';
				empty.textContent =
					( config.i18n && config.i18n.no_holidays ) ||
					'No holidays added yet.';
				list.appendChild( empty );
				return;
			}

			dates.forEach( function ( date ) {
				const row = document.createElement( 'div' );
				row.style.cssText =
					'display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:6px 10px; border-radius:6px; border:1px solid #e2e8f0; margin-bottom:4px;';

				const dateSpan = document.createElement( 'span' );
				dateSpan.style.cssText =
					'font-family:monospace; font-weight:600; color:#0f172a;';
				dateSpan.textContent = date;

				const removeIcon = document.createElement( 'span' );
				removeIcon.className =
					'dashicons dashicons-no-alt mhbo-remove-date';
				removeIcon.setAttribute( 'data-date', date );
				removeIcon.style.cssText =
					'cursor:pointer; color:#dc2626; font-size:18px;';

				row.appendChild( dateSpan );
				row.appendChild( removeIcon );
				list.appendChild( row );
			} );
		}

		if ( addBtn && newDate ) {
			addBtn.addEventListener( 'click', function () {
				const date = newDate.value.trim();
				if ( ! date || ! /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
					return;
				}
				const dates = hidden.value
					? hidden.value
							.split( ',' )
							.map( ( d ) => d.trim() )
							.filter( ( d ) => /^\d{4}-\d{2}-\d{2}$/.test( d ) )
					: [];
				if ( ! dates.includes( date ) ) {
					dates.push( date );
					dates.sort();
					hidden.value = dates.join( ',' );
					updateList();
				}
				newDate.value = '';
			} );
		}

		list.addEventListener( 'click', function ( e ) {
			const removeIcon = e.target.closest( '.mhbo-remove-date' );
			if ( removeIcon ) {
				const toRemove = removeIcon.getAttribute( 'data-date' );
				const dates = hidden.value
					.split( ',' )
					.filter( ( d ) => d.trim() && d.trim() !== toRemove );
				hidden.value = dates.join( ',' );
				updateList();
			}
		} );

		updateList();
	}

	/**
	 * Adjustment Toggles
	 */
	function initAdjustmentToggles() {
		document.addEventListener( 'change', function ( e ) {
			if ( e.target.matches( '.mhbo-adj-toggle' ) ) {
				const td = e.target.closest( 'td' );
				if ( ! td ) {
					return;
				}
				const inputs = td.querySelector( '.mhbo-adj-inputs' );
				if ( inputs ) {
					if ( e.target.checked ) {
						inputs.style.opacity = '1';
						inputs.style.pointerEvents = 'auto';
					} else {
						inputs.style.opacity = '0.5';
						inputs.style.pointerEvents = 'none';
					}
				}
			}
		} );
	}

	/**
	 * Theme Selection
	 */
	function initThemeSelection() {
		const themeInputs = document.querySelectorAll(
			'input[name="mhbo_active_theme"]'
		);
		const customColors = document.getElementById(
			'mhbo-custom-colors-wrap'
		);
		const applyBtn = document.querySelector( '.mhbo-apply-theme-btn' );

		if ( ! themeInputs.length ) {
			return;
		}

		themeInputs.forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				document
					.querySelectorAll( '.mhbo-theme-card' )
					.forEach( function ( card ) {
						card.classList.remove( 'active' );
					} );
				const parentCard = this.closest( '.mhbo-theme-card' );
				if ( parentCard ) {
					parentCard.classList.add( 'active' );
				}
				if ( customColors ) {
					if ( this.value === 'custom' ) {
						customColors.style.display = 'block';
					} else {
						customColors.style.display = 'none';
					}
				}
			} );
		} );

		if ( applyBtn ) {
			const form = applyBtn.closest( 'form' );
			if ( form ) {
				form.addEventListener( 'submit', function ( e ) {
					if (
						e.submitter &&
						e.submitter.classList.contains( 'mhbo-apply-theme-btn' )
					) {
						const btn = e.submitter;
						btn.value =
							( config.i18n && config.i18n.applying ) ||
							'Applying Theme...';
						// Defer disabling to avoid dropping button name/value from POST payload
						setTimeout( function () {
							if ( btn ) {
								btn.disabled = true;
							}
						}, 0 );
					}
				} );
			}
		}
	}

	/**
	 * Payment Gateway Test Credentials
	 */
	function initPaymentGatewayTests() {
		// Stripe Test
		const stripeTestBtn = document.getElementById( 'mhbo-test-stripe-btn' );
		const stripeResult = document.getElementById(
			'mhbo-stripe-test-result'
		);

		if ( stripeTestBtn && stripeResult ) {
			stripeTestBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				const modeSelect = document.querySelector(
					'select[name="mhbo_stripe_mode"]'
				);
				const selectedMode = modeSelect ? modeSelect.value : 'test';

				const pkField =
					selectedMode === 'live'
						? 'mhbo_stripe_live_publishable_key'
						: 'mhbo_stripe_test_publishable_key';
				const skField =
					selectedMode === 'live'
						? 'mhbo_stripe_live_secret_key'
						: 'mhbo_stripe_test_secret_key';

				const pkInput = document.querySelector(
					'input[name="' + pkField + '"]'
				);
				const skInput = document.querySelector(
					'input[name="' + skField + '"]'
				);

				stripeTestBtn.disabled = true;
				stripeResult.innerHTML =
					'<span class="spinner is-active" style="float: none; margin: 0;"></span>';

				fetchApi( '/mhbo/v1/admin/gateways/test-stripe', 'POST', {
					mode: selectedMode,
					publishable_key: pkInput ? pkInput.value : '',
					secret_key: skInput ? skInput.value : '',
				} )
					.then( function ( response ) {
						stripeTestBtn.disabled = false;
						const data =
							response && response.data !== undefined
								? response.data
								: response;
						if ( data && data.currency_mismatch ) {
							stripeResult.innerHTML =
								'<div style="margin-top: 10px; padding: 10px; border-left: 4px solid #f59e0b; background: #fffbeb; color: #92400e; border-radius:4px;">' +
								'<span style="color: #dc2626; font-weight: bold; display: block; margin-bottom: 5px;">⚠️ ' +
								( ( config.i18n &&
									config.i18n.currency_mismatch_title ) ||
									'Currency Mismatch Detected' ) +
								'</span>' +
								'<span>' +
								( data.message || '' ) +
								'</span></div>';
						} else {
							stripeResult.innerHTML =
								'<span style="color: #16a34a; font-weight: bold;">✓ </span>' +
								'<span style="color: #16a34a; font-weight: 600;">' +
								( data.message ||
									'Stripe connection verified.' ) +
								'</span>';
						}
					} )
					.catch( function ( err ) {
						stripeTestBtn.disabled = false;
						const errorMsg =
							( err && err.message ) ||
							( config.i18n && config.i18n.connection_error ) ||
							'Connection error. Please try again.';
						stripeResult.innerHTML =
							'<span style="color: #dc2626; font-weight: bold;">✗ </span>' +
							'<span style="color: #dc2626; font-weight: 600;">' +
							errorMsg +
							'</span>';
					} );
			} );
		}

		// PayPal Test
		const paypalTestBtn = document.getElementById( 'mhbo-test-paypal-btn' );
		const paypalSpinner = document.getElementById(
			'mhbo-paypal-test-spinner'
		);
		const paypalResult = document.getElementById(
			'mhbo-paypal-test-result'
		);

		if ( paypalTestBtn && paypalResult ) {
			paypalTestBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				const modeSelect = document.querySelector(
					'select[name="mhbo_paypal_mode"]'
				);
				const selectedMode = modeSelect ? modeSelect.value : 'sandbox';

				paypalTestBtn.disabled = true;
				if ( paypalSpinner ) {
					paypalSpinner.classList.add( 'is-active' );
				}
				paypalResult.innerHTML = '';

				const ciField =
					selectedMode === 'live'
						? 'mhbo_paypal_live_client_id'
						: 'mhbo_paypal_sandbox_client_id';
				const sField =
					selectedMode === 'live'
						? 'mhbo_paypal_live_secret'
						: 'mhbo_paypal_sandbox_secret';

				const ciInput = document.querySelector(
					'input[name="' + ciField + '"]'
				);
				const sInput = document.querySelector(
					'input[name="' + sField + '"]'
				);

				fetchApi( '/mhbo/v1/admin/gateways/test-paypal', 'POST', {
					mode: selectedMode,
					client_id: ciInput ? ciInput.value : '',
					secret: sInput ? sInput.value : '',
				} )
					.then( function ( response ) {
						if ( paypalSpinner ) {
							paypalSpinner.classList.remove( 'is-active' );
						}
						paypalTestBtn.disabled = false;
						const data =
							response && response.data !== undefined
								? response.data
								: response;
						paypalResult.innerHTML =
							'<span style="color: #16a34a; font-weight: bold;">✓ </span>' +
							'<span style="color: #16a34a; font-weight: 600;">' +
							( data.message || 'PayPal credentials verified.' ) +
							'</span>';
					} )
					.catch( function ( err ) {
						if ( paypalSpinner ) {
							paypalSpinner.classList.remove( 'is-active' );
						}
						paypalTestBtn.disabled = false;
						const errorMsg =
							( err && err.message ) ||
							( config.i18n && config.i18n.connection_error ) ||
							'Connection error. Please try again.';
						paypalResult.innerHTML =
							'<span style="color: #dc2626; font-weight: bold;">✗ </span>' +
							'<span style="color: #dc2626; font-weight: 600;">' +
							errorMsg +
							'</span>';
					} );
			} );
		}

		// Braintree Test
		const braintreeTestBtn = document.getElementById(
			'mhbo-test-braintree-btn'
		);
		const braintreeResult = document.getElementById(
			'mhbo-braintree-test-result'
		);

		if ( braintreeTestBtn && braintreeResult ) {
			braintreeTestBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				const modeSelect = document.querySelector(
					'select[name="mhbo_braintree_mode"]'
				);
				const selectedMode = modeSelect ? modeSelect.value : 'sandbox';

				braintreeTestBtn.disabled = true;
				braintreeResult.innerHTML =
					'<span class="spinner is-active" style="float: none; margin: 0;"></span>';

				const mIdField =
					selectedMode === 'production' || selectedMode === 'live'
						? 'mhbo_braintree_live_merchant_id'
						: 'mhbo_braintree_sandbox_merchant_id';
				const pkField =
					selectedMode === 'production' || selectedMode === 'live'
						? 'mhbo_braintree_live_public_key'
						: 'mhbo_braintree_sandbox_public_key';
				const skField =
					selectedMode === 'production' || selectedMode === 'live'
						? 'mhbo_braintree_live_private_key'
						: 'mhbo_braintree_sandbox_private_key';

				const mIdInput = document.querySelector(
					'input[name="' + mIdField + '"]'
				);
				const pkInput = document.querySelector(
					'input[name="' + pkField + '"]'
				);
				const skInput = document.querySelector(
					'input[name="' + skField + '"]'
				);
				const maInput = document.querySelector(
					'input[name="mhbo_braintree_merchant_account_id"]'
				);

				fetchApi( '/mhbo/v1/admin/gateways/test-braintree', 'POST', {
					mode: selectedMode,
					merchant_id: mIdInput ? mIdInput.value : '',
					public_key: pkInput ? pkInput.value : '',
					private_key: skInput ? skInput.value : '',
					merchant_account_id: maInput ? maInput.value : '',
				} )
					.then( function ( response ) {
						braintreeTestBtn.disabled = false;
						const data =
							response && response.data !== undefined
								? response.data
								: response;
						braintreeResult.innerHTML =
							'<span style="color: #16a34a; font-weight: bold;">✓ </span>' +
							'<span style="color: #16a34a; font-weight: 600;">' +
							( data.message ||
								'Braintree credentials verified.' ) +
							'</span>';
					} )
					.catch( function ( err ) {
						braintreeTestBtn.disabled = false;
						const errorMsg =
							( err && err.message ) ||
							( config.i18n && config.i18n.connection_error ) ||
							'Connection error. Please try again.';
						braintreeResult.innerHTML =
							'<span style="color: #dc2626; font-weight: bold;">✗ </span>' +
							'<span style="color: #dc2626; font-weight: 600;">' +
							errorMsg +
							'</span>';
					} );
			} );
		}
	}

	/**
	 * Cache Clear Handler
	 */
	function initCacheClear() {
		const btn = document.getElementById( 'mhbo_clear_cache' );
		const spinner = document.getElementById( 'mhbo_cache_spinner' );
		const resultSpan = document.getElementById( 'mhbo_cache_result' );

		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			if ( spinner ) {
				spinner.classList.add( 'is-active' );
			}
			if ( resultSpan ) {
				resultSpan.textContent =
					( config.i18n && config.i18n.clearing_cache ) ||
					'Clearing cache...';
				resultSpan.style.cssText =
					'color: #64748b; font-weight:600; margin-left: 10px;';
			}

			const oldStatus = btn.parentElement
				? btn.parentElement.querySelector( '.mhbo-cache-status' )
				: null;
			if ( oldStatus ) {
				oldStatus.remove();
			}

			fetchApi( '/mhbo/v1/admin/system/cache/clear', 'POST' )
				.then( function ( response ) {
					const message =
						( response &&
							response.data &&
							response.data.message ) ||
						( response && response.message ) ||
						( ( config.i18n && config.i18n.cache_cleared ) ||
							'Cache cleared successfully.' );

					if ( resultSpan ) {
						resultSpan.style.cssText =
							'color: #16a34a; font-weight:600; margin-left: 10px;';
						resultSpan.textContent = '✓ ' + message;
					} else {
						const statusSpan = document.createElement( 'span' );
						statusSpan.className = 'mhbo-cache-status';
						statusSpan.style.cssText =
							'color: #16a34a; font-weight:600; margin-left: 10px;';
						statusSpan.textContent = '✓ ' + message;
						btn.after( statusSpan );
					}

					if (
						window.MHBO &&
						typeof window.MHBO.toast === 'function'
					) {
						window.MHBO.toast( message, 'success', 3500 );
					}

					// Live update of Kairos generation version numbers
					const versions =
						( response && response.data && response.data.versions ) ||
						( response && response.versions );
					if ( versions && typeof versions === 'object' ) {
						Object.keys( versions ).forEach( function ( key ) {
							const el = document.querySelector(
								'[data-kairos-table="' + key + '"]'
							);
							if ( el ) {
								el.textContent = 'v' + versions[ key ];
								el.style.transition =
									'color 0.3s ease, transform 0.3s ease';
								el.style.color = '#16a34a';
								el.style.transform = 'scale(1.15)';
								setTimeout( function () {
									el.style.color = '#0f172a';
									el.style.transform = 'scale(1)';
								}, 600 );
							}
						} );
					}
				} )
				.catch( function ( err ) {
					const message =
						( err && err.message ) || 'Failed to clear cache.';

					if ( resultSpan ) {
						resultSpan.style.cssText =
							'color: #dc2626; font-weight:600; margin-left: 10px;';
						resultSpan.textContent = '⚠ ' + message;
					} else {
						const statusSpan = document.createElement( 'span' );
						statusSpan.className = 'mhbo-cache-status';
						statusSpan.style.cssText =
							'color: #dc2626; font-weight:600; margin-left: 10px;';
						statusSpan.textContent = '⚠ ' + message;
						btn.after( statusSpan );
					}

					if (
						window.MHBO &&
						typeof window.MHBO.toast === 'function'
					) {
						window.MHBO.toast( message, 'error', 4000 );
					}
				} )
				.finally( function () {
					if ( spinner ) {
						spinner.classList.remove( 'is-active' );
					}
					btn.disabled = false;
				} );
		} );
	}

	/**
	 * Declarative Universal Conditional Toggles (2026 BP)
	 * Handles elements with [data-parent-toggle="<checkbox_id>"]
	 */
	function initConditionalToggles() {
		const conditionalElements = document.querySelectorAll(
			'[data-parent-toggle]'
		);
		if ( ! conditionalElements.length ) {
			return;
		}

		const parentsTracked = new Set();

		conditionalElements.forEach( function ( childEl ) {
			const parentId = childEl.getAttribute( 'data-parent-toggle' );
			if ( ! parentId ) {
				return;
			}

			const parentInput = document.getElementById( parentId );
			if ( ! parentInput ) {
				return;
			}

			function updateVisibility() {
				const isChecked =
					parentInput.type === 'checkbox'
						? parentInput.checked
						: Boolean( parentInput.value );
				childEl.style.display = isChecked ? '' : 'none';
			}

			if ( ! parentsTracked.has( parentId ) ) {
				parentInput.addEventListener( 'change', function () {
					const matchingChildren = document.querySelectorAll(
						'[data-parent-toggle="' + parentId + '"]'
					);
					matchingChildren.forEach( function ( el ) {
						const isChecked =
							parentInput.type === 'checkbox'
								? parentInput.checked
								: Boolean( parentInput.value );
						el.style.display = isChecked ? '' : 'none';
					} );
				} );
				parentsTracked.add( parentId );
			}

			updateVisibility();
		} );
	}

	/**
	 * Live Timezone Preview Clock
	 */
	function initTimezonePreviewClock() {
		const clockEl = document.querySelector( '.mhbo-tz-live-time' );
		if ( ! clockEl ) {
			return;
		}

		function updateClock() {
			const tzAttr = clockEl.getAttribute( 'data-tz' ) || '';
			try {
				const options = {
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
					hour12: false,
				};
				if ( tzAttr ) {
					options.timeZone = tzAttr;
				}
				const formatter = new Intl.DateTimeFormat( [], options );
				clockEl.textContent = formatter.format( new Date() );
			} catch ( e ) {
				// Fallback if browser timezone identifier is non-standard
				const now = new Date();
				clockEl.textContent = now.toTimeString().split( ' ' )[ 0 ];
			}
		}

		setInterval( updateClock, 1000 );
	}

	/**
	 * Gap Settings Toggle (Legacy Table Fallback)
	 */
	function initGapSettingsToggle() {
		const autocorrectCheckbox = document.getElementById(
			'mhbo_orphan_gap_autocorrection'
		);
		const minStayInput = document.getElementById(
			'mhbo_orphan_gap_min_stay'
		);
		const minStayRow = minStayInput ? minStayInput.closest( 'tr' ) : null;

		if ( ! autocorrectCheckbox || ! minStayRow ) {
			return;
		}

		function toggleRow() {
			minStayRow.style.display = autocorrectCheckbox.checked
				? ''
				: 'none';
		}

		autocorrectCheckbox.addEventListener( 'change', toggleRow );
		toggleRow();
	}

	/**
	 * Guest CRM & VIP Thresholds Toggle (Legacy Table Fallback)
	 */
	function initGuestCrmToggle() {
		const crmCheckbox = document.getElementById( 'mhbo_guest_crm_enabled' );
		const staysInput = document.getElementById(
			'mhbo_crm_vip_stays_threshold'
		);
		const spendInput = document.getElementById(
			'mhbo_crm_vip_spend_threshold'
		);
		const vipRows = [
			staysInput ? staysInput.closest( 'tr' ) : null,
			spendInput ? spendInput.closest( 'tr' ) : null,
			...document.querySelectorAll( '.mhbo-crm-vip-row' ),
		].filter( Boolean );

		if ( ! crmCheckbox || ! vipRows.length ) {
			return;
		}

		function toggleVipRows() {
			const displayStyle = crmCheckbox.checked ? '' : 'none';
			vipRows.forEach( function ( row ) {
				row.style.display = displayStyle;
			} );
		}

		crmCheckbox.addEventListener( 'change', toggleVipRows );
		toggleVipRows();
	}

	/**
	 * Post-Booking Action Button Toggle (Legacy Table Fallback)
	 */
	function initConfirmationButtonToggle() {
		const btnCheckbox = document.getElementById(
			'mhbo_confirmation_btn_enabled'
		);
		const textInput = document.getElementById(
			'mhbo_confirmation_btn_text'
		);
		const urlInput = document.getElementById( 'mhbo_confirmation_btn_url' );
		const btnRows = [
			textInput ? textInput.closest( 'tr' ) : null,
			urlInput ? urlInput.closest( 'tr' ) : null,
			...document.querySelectorAll( '.mhbo-confirmation-btn-row' ),
		].filter( Boolean );

		if ( ! btnCheckbox || ! btnRows.length ) {
			return;
		}

		function toggleBtnRows() {
			const displayStyle = btnCheckbox.checked ? '' : 'none';
			btnRows.forEach( function ( row ) {
				row.style.display = displayStyle;
			} );
		}

		btnCheckbox.addEventListener( 'change', toggleBtnRows );
		toggleBtnRows();
	}

	/**
	 * Tax Mode Toggle & Multilingual Label Normalization
	 */
	function initTaxModeToggle() {
		const taxModeRadios = document.querySelectorAll( 'input[name="mhbo_tax_mode"]' );
		if ( ! taxModeRadios.length ) {
			return;
		}

		function onTaxModeChange( selectedMode ) {
			taxModeRadios.forEach( function ( radio ) {
				const parentLabel = radio.closest( 'label' );
				if ( parentLabel ) {
					parentLabel.style.borderColor = ( radio.value === selectedMode ) ? '#2563eb' : '#e2e8f0';
				}
			} );

			const labelInputs = document.querySelectorAll( 'input[name^="mhbo_tax_label_lang"]' );
			if ( ! labelInputs.length ) {
				return;
			}

			if ( selectedMode === 'vat' ) {
				labelInputs.forEach( function ( inp ) {
					const val = ( inp.value || '' ).trim().toUpperCase();
					if ( ! val || val === 'GST' || val === 'SALES TAX' || val === 'TAX' || val === 'TAXES' ) {
						inp.value = 'VAT';
					}
				} );
			} else if ( selectedMode === 'sales_tax' ) {
				labelInputs.forEach( function ( inp ) {
					const val = ( inp.value || '' ).trim().toUpperCase();
					if ( ! val || val === 'VAT' ) {
						inp.value = 'GST';
					}
				} );
			}
		}

		taxModeRadios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				if ( this.checked ) {
					onTaxModeChange( this.value );
				}
			} );
		} );
	}

	// Initialize on DOM ready (Vanilla JS native)
	function initAll() {
		initLicenseManagement();
		initCustomFieldsRepeater();
		initHolidayManagement();
		initAdjustmentToggles();
		initThemeSelection();
		initPaymentGatewayTests();
		initCacheClear();
		initConditionalToggles();
		initTimezonePreviewClock();
		initGapSettingsToggle();
		initGuestCrmToggle();
		initConfirmationButtonToggle();
		initTaxModeToggle();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
} )();
