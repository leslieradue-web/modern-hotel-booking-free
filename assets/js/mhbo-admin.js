/**
 * Modern Hotel Booking — Admin JavaScript
 *
 * Provides admin UI enhancements: confirm dialogs for status changes,
 * copy-to-clipboard for iCal URLs, and tabbed interface support.
 *
 * @package
 * @since   2.0.1
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initConfirmDialogs();
		initCopyButtons();
		initTabs();
		initBulkActions();
	} );

	/**
	 * Confirm dialogs for destructive actions.
	 */
	function initConfirmDialogs() {
		// Booking status changes (confirm/cancel)
		document
			.querySelectorAll( '.mhbo-confirm-action' )
			.forEach( function ( el ) {
				el.addEventListener( 'click', function ( e ) {
					const message =
						el.getAttribute( 'data-confirm' ) || 'Are you sure?';
					if ( ! confirm( message ) ) {
						e.preventDefault();
					}
				} );
			} );

		// Delete confirmations
		document
			.querySelectorAll( '.mhbo-delete-action' )
			.forEach( function ( el ) {
				el.addEventListener( 'click', function ( e ) {
					if (
						! confirm(
							'Are you sure you want to delete this item? This action cannot be undone.'
						)
					) {
						e.preventDefault();
					}
				} );
			} );
	}

	/**
	 * Copy-to-clipboard for iCal URLs and API keys.
	 */
	function initCopyButtons() {
		document
			.querySelectorAll( '.mhbo-copy-btn' )
			.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					const targetId = btn.getAttribute( 'data-copy-target' );
					const target = document.getElementById( targetId );

					if ( ! target ) {
						return;
					}

					const text = target.value || target.textContent;

					if (
						navigator.clipboard &&
						navigator.clipboard.writeText
					) {
						navigator.clipboard
							.writeText( text )
							.then( function () {
								showCopyFeedback( btn, true );
							} )
							.catch( function () {
								fallbackCopy( target );
								showCopyFeedback( btn, true );
							} );
					} else {
						fallbackCopy( target );
						showCopyFeedback( btn, true );
					}
				} );
			} );
	}

	/**
	 * Fallback copy method for older browsers.
	 * @param element
	 */
	function fallbackCopy( element ) {
		if ( element.select ) {
			element.select();
			element.setSelectionRange( 0, 99999 );
		}
		try {
			document.execCommand( 'copy' );
		} catch ( err ) {
			// Silently fail
		}
	}

	/**
	 * Toast Notification Engine for modern WP Admin UX (2026 Edition).
	 */
	window.MHBO = window.MHBO || {};

	/**
	 * Display a floating glassmorphic toast notification.
	 *
	 * @param {string} message  - Message text or HTML.
	 * @param {string} type     - 'success' | 'error' | 'warning' | 'info'
	 * @param {number} duration - Display time in ms (default 3500)
	 */
	window.MHBO.toast = function ( message, type, duration ) {
		type = type || 'success';
		duration = duration || 3500;

		let container = document.querySelector( '.mhbo-toast-container' );
		if ( ! container ) {
			container = document.createElement( 'div' );
			container.className = 'mhbo-toast-container';
			document.body.appendChild( container );
		}

		const toast = document.createElement( 'div' );
		toast.className = 'mhbo-toast mhbo-toast-' + type;

		const icons = {
			success:
				'<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>',
			error: '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>',
			warning:
				'<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>',
			info: '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.247.25v3.25a.75.75 0 001.5 0v-3.5A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd" /></svg>',
		};

		toast.innerHTML =
			'<div class="mhbo-toast-icon">' +
			( icons[ type ] || icons.info ) +
			'</div>' +
			'<div class="mhbo-toast-content">' +
			message +
			'</div>';

		container.appendChild( toast );

		// Trigger entrance animation
		requestAnimationFrame( function () {
			toast.classList.add( 'mhbo-toast-visible' );
		} );

		// Auto dismiss
		setTimeout( function () {
			toast.classList.remove( 'mhbo-toast-visible' );
			toast.classList.add( 'mhbo-toast-hiding' );
			setTimeout( function () {
				if ( toast.parentNode ) {
					toast.parentNode.removeChild( toast );
				}
			}, 300 );
		}, duration );
	};

	/**
	 * Show visual feedback after copy action.
	 * @param btn
	 * @param success
	 */
	function showCopyFeedback( btn, success ) {
		const originalText = btn.textContent;
		btn.textContent = success ? '✓ Copied!' : '✗ Failed';
		btn.disabled = true;

		if ( window.MHBO && window.MHBO.toast ) {
			if ( success ) {
				window.MHBO.toast( 'Copied to clipboard!', 'success', 2500 );
			} else {
				window.MHBO.toast(
					'Failed to copy to clipboard',
					'error',
					3000
				);
			}
		}

		setTimeout( function () {
			btn.textContent = originalText;
			btn.disabled = false;
		}, 2000 );
	}

	/**
	 * Tabbed interface for Settings page.
	 */
	function initTabs() {
		const tabNav = document.querySelectorAll(
			'.mhbo-tab-nav a, .mhbo-tab-nav button'
		);
		if ( tabNav.length === 0 ) {
			return;
		}

		tabNav.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();

				let targetId =
					tab.getAttribute( 'data-tab' ) ||
					tab.getAttribute( 'href' );
				if ( targetId && targetId.startsWith( '#' ) ) {
					targetId = targetId.substring( 1 );
				}

				// Remove active states
				tabNav.forEach( function ( t ) {
					t.classList.remove( 'mhbo-tab-active' );
				} );
				document
					.querySelectorAll( '.mhbo-tab-content' )
					.forEach( function ( panel ) {
						panel.style.display = 'none';
					} );

				// Activate clicked tab
				tab.classList.add( 'mhbo-tab-active' );
				const targetPanel = document.getElementById( targetId );
				if ( targetPanel ) {
					targetPanel.style.display = 'block';
				}
			} );
		} );
	}

	/**
	 * Bulk action select-all checkboxes.
	 */
	function initBulkActions() {
		const selectAll = document.getElementById( 'mhbo-select-all' );
		if ( ! selectAll ) {
			return;
		}

		selectAll.addEventListener( 'change', function () {
			const checkboxes =
				document.querySelectorAll( '.mhbo-row-checkbox' );
			checkboxes.forEach( function ( cb ) {
				cb.checked = selectAll.checked;
			} );
		} );
	}

/**
	 * Guest CRM Search
	 * Powers both the WP Dashboard Widget and the Executive Command Center.
	 */
	function initFastGuestSearch() {
		const containers = document.querySelectorAll(
			'.mhbo-fast-guest-search'
		);
		if ( ! containers.length ) {
			return;
		}

		function escapeHtml( str ) {
			const div = document.createElement( 'div' );
			div.textContent = str || '';
			return div.innerHTML;
		}

		const adminData = window.mhboAdminData || {};
		const nonce = adminData.nonce || '';
		const guestsUrl =
			adminData.guestsAdminUrl || 'admin.php?page=mhbo-guests';
		const currency = adminData.currencySymbol || '$';
		const i18n = adminData.i18n || {
			searching: 'Searching guests…',
			noGuests: 'No matching guests found.',
			newBooking: 'Create New Booking →',
			stays: 'stays',
			stay: 'stay',
			vip: 'VIP',
			caution: 'Caution',
			viewDossier: 'Open Guest Dossier →',
		};

		containers.forEach( function ( container ) {
			const input = container.querySelector( '.mhbo-fast-search-input' );
			const clearBtn = container.querySelector(
				'.mhbo-fast-search-clear'
			);
			const spinner = container.querySelector(
				'.mhbo-fast-search-spinner'
			);
			const dropdown = container.querySelector(
				'.mhbo-fast-search-dropdown'
			);

			if ( ! input || ! dropdown ) {
				return;
			}

			let debounceTimer = null;
			let activeIndex = -1;
			let currentQuery = '';

			function showSpinner( show ) {
				if ( spinner ) {
					spinner.style.display = show ? 'inline-block' : 'none';
				}
				if ( clearBtn ) {
					clearBtn.style.display =
						show || ! input.value.trim() ? 'none' : 'flex';
				}
			}

			function hideDropdown() {
				dropdown.style.display = 'none';
				activeIndex = -1;
			}

			function resetDropdown() {
				dropdown.style.display = 'none';
				dropdown.innerHTML = '';
				activeIndex = -1;
			}

			function highlightActiveItem( items ) {
				items.forEach( function ( item, idx ) {
					if ( idx === activeIndex ) {
						item.classList.add( 'mhbo-item-active' );
						item.scrollIntoView( { block: 'nearest' } );
					} else {
						item.classList.remove( 'mhbo-item-active' );
					}
				} );
			}

			function renderResults( results ) {
				activeIndex = -1;

				if ( ! results || ! results.length ) {
					dropdown.innerHTML =
						'<div class="mhbo-fast-search-empty">' +
						'<span>' +
						escapeHtml( i18n.noGuests ) +
						'</span><br>' +
						'<a href="admin.php?page=mhbo-bookings&action=add" class="mhbo-fast-search-empty-action">' +
						escapeHtml( i18n.newBooking ) +
						'</a>' +
						'</div>';
					dropdown.style.display = 'block';
					return;
				}

				let html = '';
				results.forEach( function ( guest, idx ) {
					const name =
						(
							( guest.first_name || '' ) +
							' ' +
							( guest.last_name || '' )
						).trim() || 'Guest #' + guest.id;
					const isVip = guest.vip_status === 'vip';
					const hasCaution = Number( guest.caution_flag ) === 1;
					const stays = Number( guest.total_stays || 0 );
					const spend = Number(
						parseFloat( guest.total_spend || 0 )
					);
					const formattedSpend = currency + spend.toFixed( 2 );
					const email = guest.email || '';
					const phone = guest.phone || '';
					const metaContact =
						email + ( email && phone ? ' • ' : '' ) + phone;

					const linkUrl =
						guestsUrl +
						'&guest_id=' +
						encodeURIComponent( guest.id );

					html +=
						'<a href="' +
						escapeHtml( linkUrl ) +
						'" class="mhbo-fast-search-item" data-index="' +
						idx +
						'">';
					html += '<div class="mhbo-fast-search-guest-main">';
					html += '<div class="mhbo-fast-search-guest-name-row">';
					html +=
						'<span class="mhbo-fast-search-guest-name">' +
						escapeHtml( name ) +
						'</span>';
					if ( isVip ) {
						html +=
							'<span class="mhbo-fast-pill mhbo-fast-pill-vip">★ ' +
							escapeHtml( i18n.vip ) +
							'</span>';
					}
					if ( hasCaution ) {
						html +=
							'<span class="mhbo-fast-pill mhbo-fast-pill-caution">⚠️ ' +
							escapeHtml( i18n.caution ) +
							'</span>';
					}
					html += '</div>';
					if ( metaContact ) {
						html +=
							'<span class="mhbo-fast-search-guest-meta">' +
							escapeHtml( metaContact ) +
							'</span>';
					}
					html += '</div>';

					html += '<div class="mhbo-fast-search-guest-stats">';
					html +=
						'<span class="mhbo-fast-search-stays">' +
						stays +
						' ' +
						( stays === 1 ? i18n.stay : i18n.stays ) +
						'</span>';
					if ( spend > 0 ) {
						html +=
							'<span class="mhbo-fast-search-spend">' +
							escapeHtml( formattedSpend ) +
							'</span>';
					}
					html += '</div>';
					html += '</a>';
				} );

				html += '<div class="mhbo-fast-search-footer">';
				html +=
					'<span>' +
					results.length +
					' ' +
					( results.length === 1
						? escapeHtml( i18n.match || 'match' )
						: escapeHtml( i18n.matches || 'matches' ) ) +
					'</span>';
				html +=
					'<span>' +
					escapeHtml(
						i18n.keyboardNav || 'Press ↑↓ to choose, Enter to open'
					) +
					'</span>';
				html += '</div>';

				dropdown.innerHTML = html;
				dropdown.style.display = 'block';
			}

			function executeSearch( query ) {
				currentQuery = query;
				showSpinner( true );

				const fetchPromise =
					window.wp && window.wp.apiFetch
						? window.wp.apiFetch( {
								path:
									'/mhbo/v1/guests?search=' +
									encodeURIComponent( query ) +
									'&per_page=6',
						  } )
						: fetch(
								( adminData.restUrl || '/wp-json/' ) +
									'mhbo/v1/guests?search=' +
									encodeURIComponent( query ) +
									'&per_page=6',
								{
									headers: {
										'X-WP-Nonce': nonce,
										'Content-Type': 'application/json',
									},
								}
						  ).then( function ( r ) {
								return r.json();
						  } );

				fetchPromise
					.then( function ( response ) {
						if ( query !== currentQuery ) {
							return; // Discard stale response
						}
						const items =
							response && response.items
								? response.items
								: Array.isArray( response )
								? response
								: [];
						renderResults( items );
					} )
					.catch( function ( err ) {
						if ( query === currentQuery ) {
							dropdown.innerHTML =
								'<div class="mhbo-fast-search-empty"><span style="color:#dc2626;">Search error: ' +
								escapeHtml(
									err.message || 'Failed to query guests'
								) +
								'</span></div>';
							dropdown.style.display = 'block';
						}
					} )
					.finally( function () {
						if ( query === currentQuery ) {
							showSpinner( false );
						}
					} );
			}

			input.addEventListener( 'input', function () {
				const query = input.value.trim();
				clearTimeout( debounceTimer );

				if ( ! query ) {
					showSpinner( false );
					resetDropdown();
					return;
				}

				if ( clearBtn ) {
					clearBtn.style.display = 'flex';
				}

				debounceTimer = setTimeout( function () {
					executeSearch( query );
				}, 150 );
			} );

			input.addEventListener( 'keydown', function ( e ) {
				const items = dropdown.querySelectorAll(
					'.mhbo-fast-search-item'
				);

				if ( e.key === 'ArrowDown' ) {
					if ( dropdown.style.display === 'none' || ! items.length ) {
						return;
					}
					e.preventDefault();
					activeIndex = ( activeIndex + 1 ) % items.length;
					highlightActiveItem( items );
				} else if ( e.key === 'ArrowUp' ) {
					if ( dropdown.style.display === 'none' || ! items.length ) {
						return;
					}
					e.preventDefault();
					activeIndex =
						( activeIndex - 1 + items.length ) % items.length;
					highlightActiveItem( items );
				} else if ( e.key === 'Enter' ) {
					if (
						dropdown.style.display !== 'none' &&
						items.length > 0
					) {
						e.preventDefault();
						const targetItem =
							activeIndex >= 0
								? items[ activeIndex ]
								: items[ 0 ];
						if ( targetItem && targetItem.href ) {
							window.location.href = targetItem.href;
						}
					}
				} else if ( e.key === 'Escape' ) {
					hideDropdown();
					input.blur();
				}
			} );

			input.addEventListener( 'focus', function () {
				const query = input.value.trim();
				if ( query ) {
					if ( dropdown.children.length > 0 ) {
						dropdown.style.display = 'block';
					} else {
						executeSearch( query );
					}
				}
			} );

			if ( clearBtn ) {
				clearBtn.addEventListener( 'click', function () {
					input.value = '';
					clearBtn.style.display = 'none';
					resetDropdown();
					input.focus();
				} );
			}

			// Dismiss on outside click
			document.addEventListener( 'click', function ( e ) {
				if ( ! container.contains( e.target ) ) {
					hideDropdown();
				}
			} );
		} );
	}

	/**
	 * Email Delivery Health & Diagnostic Inspector.
	 */
	function initEmailDiagnostic() {
		const card = document.getElementById( 'mhbo-email-health-card' );
		if ( ! card ) {
			return;
		}

		function escapeHtml( str ) {
			const div = document.createElement( 'div' );
			div.textContent = str || '';
			return div.innerHTML;
		}

		const tileMailer = document.getElementById( 'mhbo-tile-mailer' );
		const tileDomain = document.getElementById( 'mhbo-tile-domain' );
		const tileSpf = document.getElementById( 'mhbo-tile-spf' );
		const tileDmarc = document.getElementById( 'mhbo-tile-dmarc' );
		const alertsContainer = document.getElementById( 'mhbo-diagnostic-alerts' );
		const failureBanner = document.getElementById( 'mhbo-diagnostic-failure-banner' );
		const rescanBtn = document.getElementById( 'mhbo-btn-rescan-email' );
		const sendBtn = document.getElementById( 'mhbo-btn-send-test-email' );
		const recipientInput = document.getElementById( 'mhbo-test-email-recipient' );
		const spinner = document.getElementById( 'mhbo-test-email-spinner' );
		const resultBox = document.getElementById( 'mhbo-test-email-result' );

		function renderDiagnostic( data ) {
			// 1. Mailer Tile
			if ( tileMailer ) {
				if ( data.is_smtp ) {
					tileMailer.innerHTML = '<span style="color: #166534; display: inline-flex; align-items: center; gap: 4px;">✓ ' + escapeHtml( data.mailer_name ) + '</span>';
				} else {
					tileMailer.innerHTML = '<span style="color: #b45309; display: inline-flex; align-items: center; gap: 4px;">⚠️ ' + escapeHtml( data.mailer_name ) + '</span>';
				}
			}

			// 2. Domain Tile
			if ( tileDomain ) {
				if ( data.is_free_webmail ) {
					tileDomain.innerHTML = '<span style="color: #dc2626; display: inline-flex; align-items: center; gap: 4px;">⚠️ @' + escapeHtml( data.sender_domain ) + ' (Webmail)</span>';
				} else if ( data.domain_matches_site ) {
					tileDomain.innerHTML = '<span style="color: #166534; display: inline-flex; align-items: center; gap: 4px;">✓ @' + escapeHtml( data.sender_domain ) + '</span>';
				} else {
					tileDomain.innerHTML = '<span style="color: #b45309; display: inline-flex; align-items: center; gap: 4px;">ℹ️ @' + escapeHtml( data.sender_domain ) + '</span>';
				}
			}

			// 3. SPF Tile
			if ( tileSpf ) {
				if ( data.is_free_webmail ) {
					tileSpf.innerHTML = '<span style="color: #64748b;">N/A (Provider Managed)</span>';
				} else if ( data.spf_status === 'valid' ) {
					tileSpf.innerHTML = '<span style="color: #166534;">✓ Detected</span>';
				} else if ( data.spf_status === 'missing' ) {
					tileSpf.innerHTML = '<span style="color: #dc2626;">⚠️ Missing</span>';
				} else {
					tileSpf.innerHTML = '<span style="color: #64748b;">Unknown / Unchecked</span>';
				}
			}

			// 4. DMARC Tile
			if ( tileDmarc ) {
				if ( data.is_free_webmail ) {
					tileDmarc.innerHTML = '<span style="color: #dc2626;">Strict (p=reject)</span>';
				} else if ( data.dmarc_status === 'valid' ) {
					const pol = data.dmarc_policy ? ' (p=' + escapeHtml( data.dmarc_policy ) + ')' : '';
					tileDmarc.innerHTML = '<span style="color: #166534;">✓ Detected' + pol + '</span>';
				} else if ( data.dmarc_status === 'missing' ) {
					tileDmarc.innerHTML = '<span style="color: #dc2626;">⚠️ Missing</span>';
				} else {
					tileDmarc.innerHTML = '<span style="color: #64748b;">Unknown / Unchecked</span>';
				}
			}

			// 5. Dynamic Alerts
			if ( alertsContainer ) {
				let alertsHtml = '';
				if ( data.is_free_webmail ) {
					alertsHtml += '<div style="padding: 12px 16px; background: #fef2f2; border-left: 4px solid #ef4444; border-radius: 4px; color: #991b1b; font-size: 13px; line-height: 1.5; margin-bottom: 10px;">' +
						'<strong>⚠️ Free Webmail Address Risk:</strong> You are sending from <code>' + escapeHtml( data.sender_email ) + '</code>. Google and Yahoo DMARC policies (p=reject) require all emails from @' + escapeHtml( data.sender_domain ) + ' to originate from official servers. Since your web server is not authorized by ' + escapeHtml( data.sender_domain ) + ', recipient mailboxes like Gmail and Outlook will reject your booking emails. <strong>Solution:</strong> Change your sender email to an address on your domain (e.g. <code>info@' + escapeHtml( data.site_domain || 'yourdomain.com' ) + '</code>).' +
						'</div>';
				}

				if ( ! data.is_smtp ) {
					alertsHtml += '<div style="padding: 12px 16px; background: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 4px; color: #92400e; font-size: 13px; line-height: 1.5; margin-bottom: 10px;">' +
						'<strong>⚠️ Unauthenticated PHP mail() in use:</strong> Modern email services (Gmail, Yahoo, Outlook) flag emails sent directly via PHP mail() as spam or reject them. <strong>Recommendation:</strong> Install a free dedicated SMTP plugin like <strong>FluentSMTP</strong> or <strong>WP Mail SMTP</strong> to send authenticated emails via Brevo, SendGrid, Amazon SES, or Google Workspace.' +
						'</div>';
				}

				if ( ! data.is_free_webmail && data.dmarc_status === 'missing' ) {
					alertsHtml += '<div style="padding: 12px 16px; background: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 4px; color: #1e40af; font-size: 13px; line-height: 1.5; margin-bottom: 10px;">' +
						'<strong>ℹ️ DMARC Record Recommended:</strong> As of 2024, Google and Yahoo require custom domains to publish a DMARC policy. Add a TXT record to your DNS for <code>_dmarc.' + escapeHtml( data.sender_domain ) + '</code> with value <code>v=DMARC1; p=none;</code>.' +
						'</div>';
				}

				if ( alertsHtml ) {
					alertsContainer.innerHTML = alertsHtml;
					alertsContainer.style.display = 'block';
				} else {
					alertsContainer.style.display = 'none';
				}
			}

			// 6. Last Recorded Failure
			if ( failureBanner ) {
				if ( data.last_failure && data.last_failure.timestamp ) {
					const subj = data.last_failure.subject ? ' &bull; Subject: <em>' + escapeHtml( data.last_failure.subject ) + '</em>' : '';
					failureBanner.innerHTML = '<div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap;">' +
						'<div>' +
						'<strong>⚠️ Recent Email Delivery Failure Recorded (' + escapeHtml( data.last_failure.timestamp ) + '):</strong><br>' +
						'Recipient: <code>' + escapeHtml( data.last_failure.recipient || 'N/A' ) + '</code>' + subj + '<br>' +
						'Error: <strong style="color: #b91c1c;">' + escapeHtml( data.last_failure.message || 'Unknown error' ) + '</strong>' +
						'</div>' +
						'<button type="button" id="mhbo-btn-clear-mail-failure" class="button button-small" style="background: #ffffff; border-color: #fca5a5; color: #991b1b;">Clear Log</button>' +
						'</div>';
					failureBanner.style.display = 'block';

					const clearBtn = document.getElementById( 'mhbo-btn-clear-mail-failure' );
					if ( clearBtn ) {
						clearBtn.addEventListener( 'click', function () {
							clearBtn.disabled = true;
							window.wp.apiFetch( { path: '/mhbo/v1/admin/email/clear-failure', method: 'POST' } )
								.then( function () {
									failureBanner.style.display = 'none';
								} );
						} );
					}
				} else {
					failureBanner.style.display = 'none';
				}
			}
		}

		function loadDiagnostic() {
			if ( rescanBtn ) {
				rescanBtn.disabled = true;
				const icon = rescanBtn.querySelector( '.dashicons' );
				if ( icon ) {
					icon.classList.add( 'mhbo-spin' );
				}
			}

			if ( window.wp && window.wp.apiFetch ) {
				window.wp.apiFetch( { path: '/mhbo/v1/admin/email/diagnostic' } )
					.then( function ( res ) {
						const data = res && res.data ? res.data : res || {};
						renderDiagnostic( data );
					} )
					.catch( function () {
						// Fail gracefully
					} )
					.finally( function () {
						if ( rescanBtn ) {
							rescanBtn.disabled = false;
							const icon = rescanBtn.querySelector( '.dashicons' );
							if ( icon ) {
								icon.classList.remove( 'mhbo-spin' );
							}
						}
					} );
			}
		}

		// Initial load
		loadDiagnostic();

		if ( rescanBtn ) {
			rescanBtn.addEventListener( 'click', loadDiagnostic );
		}

		// Test Email Dispatcher
		if ( sendBtn && recipientInput ) {
			sendBtn.addEventListener( 'click', function () {
				const recipient = recipientInput.value.trim();
				if ( ! recipient ) {
					recipientInput.focus();
					return;
				}

				sendBtn.disabled = true;
				if ( spinner ) {
					spinner.style.display = 'inline-block';
				}
				if ( resultBox ) {
					resultBox.style.display = 'none';
				}

				window.wp.apiFetch( {
					path: '/mhbo/v1/admin/email/test',
					method: 'POST',
					data: { recipient: recipient },
				} )
					.then( function ( res ) {
						const payload = res && res.data ? res.data : res || {};
						if ( resultBox ) {
							resultBox.innerHTML = '<div style="padding: 14px 16px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; color: #166534; font-size: 13px;">' +
								'<div style="font-weight: 700; font-size: 14px; margin-bottom: 4px;">✓ ' + escapeHtml( payload.message || 'Test email dispatched successfully!' ) + '</div>' +
								'<div>Recipient: <code>' + escapeHtml( payload.recipient || recipient ) + '</code> &bull; Mailer Engine: <strong>' + escapeHtml( payload.mailer_name || 'Active' ) + '</strong> &bull; Probe: <code>' + escapeHtml( payload.probe_id || '' ) + '</code></div>' +
								'<div style="margin-top: 6px; font-size: 12px; color: #15803d;">If you do not see it in a few moments, be sure to inspect your recipient Spam/Junk folder.</div>' +
								'</div>';
							resultBox.style.display = 'block';
						}
					} )
					.catch( function ( err ) {
						const errMessage = ( err && err.message ) ? err.message : 'Unknown dispatch error';
						const suggestion = ( err && err.data && err.data.suggestion ) ? err.data.suggestion : 'We recommend installing FluentSMTP or WP Mail SMTP to resolve server mail delivery issues.';
						if ( resultBox ) {
							resultBox.innerHTML = '<div style="padding: 14px 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b; font-size: 13px;">' +
								'<div style="font-weight: 700; font-size: 14px; margin-bottom: 4px;">✕ Dispatch Failed: ' + escapeHtml( errMessage ) + '</div>' +
								'<div style="line-height: 1.5; margin-top: 6px;">' + escapeHtml( suggestion ) + '</div>' +
								'</div>';
							resultBox.style.display = 'block';
						}
					} )
					.finally( function () {
						sendBtn.disabled = false;
						if ( spinner ) {
							spinner.style.display = 'none';
						}
						// Refresh failure record if one was saved
						loadDiagnostic();
					} );
			} );
		}
	}

	/**
	 * BroadcastChannel sync when settings or pricing rules are saved.
	 * Instantly notifies live front desk tablets without waiting for polling cycle.
	 */
	function initBroadcastSync() {
		if ( typeof BroadcastChannel === 'undefined' ) {
			return;
		}
		const search = window.location.search || '';
		const isUpdated =
			search.indexOf( 'settings-updated=true' ) !== -1 ||
			search.indexOf( 'updated=true' ) !== -1 ||
			document.querySelector(
				'#setting-error-rule_added, #setting-error-rule_deleted, .mhbo-settings-updated, .notice-success.settings-error'
			) !== null;

		if ( isUpdated ) {
			try {
				const bc = new BroadcastChannel( 'mhbo_tablet_sync' );
				bc.postMessage( {
					type: 'BOARD_MUTATION',
					scope: 'admin_settings',
				} );
				bc.close();
			} catch ( _err ) {}
		}
	}

	function onReady() {
		initFastGuestSearch();
		initEmailDiagnostic();
		
		initBroadcastSync();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', onReady );
	} else {
		onReady();
	}
} )();
