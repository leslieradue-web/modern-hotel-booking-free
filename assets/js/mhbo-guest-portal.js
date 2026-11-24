/**
 * Modern Hotel Booking - Guest Self-Service Portal Workstation (Law 65)
 *
 * Provides passwordless dual-mode guest authentication (1-click cryptographic token
 * or booking ID + email), interactive USALI folio display, live Wi-Fi access,
 * stay modifications, staff-reviewed paid cancellations, and AI Concierge context bridging.
 *
 * @package MHBO\Frontend
 * @since   2.6.2
 */

(function() {
	'use strict';

	function initGuestPortal() {
		var root = document.getElementById('mhbo-guest-portal');
		if (!root) {
			return;
		}

		if (root.getAttribute('data-initialized') === '1') {
			return;
		}
		root.setAttribute('data-initialized', '1');

		var restUrl = root.getAttribute('data-rest-url') || (window.location.origin + '/wp-json/mhbo/v1/portal');
		var nonce = root.getAttribute('data-nonce') || '';
		var alertEl = document.getElementById('mhbo-portal-alert');
		var lookupView = document.getElementById('mhbo-portal-lookup-view');
		var detailsView = document.getElementById('mhbo-portal-details-view');
		var currentBooking = null;
		var bookingAbortController = null;

		function parseJsonSafe(res) {
			return res.text().then(function(raw) {
				var parsed = null;
				if (raw) {
					try {
						parsed = JSON.parse(raw);
					} catch (_e) {
						parsed = null;
					}
				}
				return { ok: res.ok, status: res.status, data: parsed };
			});
		}

		var i18n = window.mhbo_portal_i18n || {};
		function __t(key, fallback) {
			return (i18n && i18n[key]) ? i18n[key] : fallback;
		}

		function escapeHtml(str) {
			return String(str == null ? '' : str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		}

		function showAlert(msg, isSuccess) {
			if (!alertEl) return;
			alertEl.setAttribute('role', isSuccess ? 'status' : 'alert');
			alertEl.setAttribute('aria-live', isSuccess ? 'polite' : 'assertive');
			alertEl.textContent = msg;
			alertEl.className = 'mhbo-portal-alert ' + (isSuccess ? 'mhbo-portal-alert-success' : 'mhbo-portal-alert-error');
			alertEl.style.display = 'block';
			alertEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}

		function hideAlert() {
			if (alertEl) {
				alertEl.style.display = 'none';
			}
		}

		function fetchBooking(params) {
			hideAlert();
			if (bookingAbortController) {
				bookingAbortController.abort();
			}
			bookingAbortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
			var reqController = bookingAbortController;
			var query = new URLSearchParams(params).toString();
			var fetchOpts = { method: 'GET' };
			if (reqController) {
				fetchOpts.signal = reqController.signal;
			}
			fetch(restUrl + '/booking?' + query, fetchOpts)
			.then(function(res) {
				return res.json().then(function(data) {
					return { ok: res.ok, status: res.status, data: data };
				}).catch(function() {
					return { ok: res.ok, status: res.status, data: null };
				});
			})
			.then(function(result) {
				if (reqController && reqController.signal.aborted) {
					return;
				}
				var data = result.data;
				if (result.ok && data && data.success && data.booking) {
					try {
						renderDetails(data.booking);
					} catch (renderErr) {
						console.error('MHBO Guest Portal Render Error:', renderErr);
						showAlert(__t('render_error', 'An error occurred displaying your reservation details.'), false);
					}
				} else {
					var msg = (data && data.message) ? data.message : __t('invalid_details', 'Unable to find reservation. Please verify your details.');
					showAlert(msg, false);
				}
			})
			.catch(function(err) {
				if (err && err.name === 'AbortError') {
					return;
				}
				console.error('MHBO Guest Portal Fetch Error:', err);
				showAlert(__t('network_error', 'Network error while looking up reservation. Please try again.'), false);
			})
			.finally(function() {
				if (bookingAbortController === reqController) {
					bookingAbortController = null;
				}
			});
		}

		function getPortalLookupParams() {
			if ( currentBooking && currentBooking.booking_token ) {
				return { token: currentBooking.booking_token };
			}
			return {
				booking_id: currentBooking ? currentBooking.booking_id : '',
				email: currentBooking ? currentBooking.customer_email : ''
			};
		}

		function requestAndOpenBalanceLink( payScope, fallbackBtn ) {
			if ( ! currentBooking ) return;
			if ( fallbackBtn ) {
				fallbackBtn.setAttribute( 'aria-busy', 'true' );
			}
			var payload = Object.assign( {}, getPortalLookupParams(), { pay_scope: payScope || 'room' } );
			fetch( restUrl + '/balance-link', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify( payload )
			} )
			.then( function( res ) { return res.json(); } )
			.then( function( data ) {
				if ( fallbackBtn ) fallbackBtn.removeAttribute( 'aria-busy' );
				if ( data && data.success && data.url ) {
					window.location.href = data.url;
				} else {
					showAlert( ( data && data.message ) || __t( 'balance_link_error', 'Unable to open payment link. Please contact the hotel.' ), false );
				}
			} )
			.catch( function() {
				if ( fallbackBtn ) fallbackBtn.removeAttribute( 'aria-busy' );
				showAlert( __t( 'network_error', 'Network error while opening payment link. Please try again.' ), false );
			} );
		}

		function renderDetails(b) {
			currentBooking = b;

			// Broadcast authenticated guest context to AI Concierge and save in session (Law 65)
			try {
				if ( b.booking_token ) {
					sessionStorage.setItem('mhbo_portal_token', b.booking_token);
				} else {
					sessionStorage.removeItem('mhbo_portal_token');
				}
				sessionStorage.setItem('mhbo_portal_booking_id', b.booking_id || '');
				window.mhboGuestPortalContext = b;
				window.dispatchEvent(new CustomEvent('mhbo_guest_logged_in', { detail: b }));
			} catch(e) {}

			// Hospitality Hero & Monogram Avatar
			var avatarEl = document.getElementById('mhbo-guest-avatar');
			if (avatarEl) avatarEl.textContent = b.guest_initials || 'G';
			var greetingEl = document.getElementById('mhbo-guest-greeting');
			if (greetingEl) greetingEl.textContent = b.greeting || ('Welcome, ' + (b.first_name || b.customer_name));
			var loyaltyBadgeEl = document.getElementById('mhbo-guest-loyalty-badge');
			if (loyaltyBadgeEl) {
				if (b.loyalty_badge) {
					loyaltyBadgeEl.textContent = b.loyalty_badge;
					loyaltyBadgeEl.style.display = 'inline-flex';
				} else if (b.is_vip) {
					loyaltyBadgeEl.textContent = '★ VIP Guest';
					loyaltyBadgeEl.style.display = 'inline-flex';
				} else {
					loyaltyBadgeEl.style.display = 'none';
				}
			}
			var stageMsgEl = document.getElementById('mhbo-guest-stage-msg');
			if (stageMsgEl) {
				var stageMsg = b.stay_stage_message || '';
				if (stageMsg && stageMsg.indexOf('\n') === -1) {
					stageMsg = stageMsg.replace(/([!?.])\s+/, '$1\n');
				}
				stageMsgEl.textContent = stageMsg;
			}
			var roomThumbWrap = document.getElementById('mhbo-res-room-thumb-wrap');
			var roomThumb = document.getElementById('mhbo-res-room-thumb');
			if (roomThumbWrap && roomThumb) {
				if (b.room_image_url) {
					roomThumb.src = b.room_image_url;
					roomThumbWrap.style.display = 'block';
				} else {
					roomThumbWrap.style.display = 'none';
				}
			}

			// Concierge Quick Actions
			var qaWa = document.getElementById('mhbo-qa-whatsapp');
			if (qaWa) {
				if (b.whatsapp_url) {
					qaWa.href = b.whatsapp_url;
					qaWa.style.display = 'inline-flex';
				} else {
					qaWa.style.display = 'none';
				}
			}
			var qaPhone = document.getElementById('mhbo-qa-phone');
			if (qaPhone) {
				if (b.hotel_phone) {
					qaPhone.href = 'tel:' + b.hotel_phone.replace(/[^0-9+]/g, '');
					qaPhone.style.display = 'inline-flex';
				} else {
					qaPhone.style.display = 'none';
				}
			}
			var qaDir = document.getElementById('mhbo-qa-directions');
			if (qaDir) {
				if (b.google_maps_url) {
					qaDir.href = b.google_maps_url;
					qaDir.style.display = 'inline-flex';
				} else {
					qaDir.style.display = 'none';
				}
			}
			var qaInv = document.getElementById('mhbo-qa-invoice');
			if (qaInv) {
				if (b.invoice_url) {
					qaInv.href = b.invoice_url;
					qaInv.style.display = 'inline-flex';
				} else {
					qaInv.style.display = 'none';
				}
			}

			// Add to Calendar Integration (Google Calendar + Apple/Outlook .ics)
			var isClosedCalStatus = (b.status === 'cancelled' || b.status === 'refunded');
			var calBtn = document.getElementById('mhbo-qa-calendar-btn');
			var calWrap = calBtn ? calBtn.closest('.mhbo-qa-dropdown-wrap') : null;
			if (calWrap) {
				calWrap.style.display = isClosedCalStatus ? 'none' : 'inline-flex';
			}
			var calGoogle = document.getElementById('mhbo-cal-google');
			if (calGoogle && b.check_in && b.check_out) {
				var calTitle = encodeURIComponent((b.hotel_name || 'Hotel') + ' - Reservation #' + b.booking_id);
				var dtStart = b.check_in.replace(/-/g, '');
				var dtEnd = b.check_out.replace(/-/g, '');
				var calDesc = encodeURIComponent('Hotel Reservation #' + b.booking_id + '\nRoom: ' + b.room_name + '\nGuests: ' + b.guests);
				var calLoc = encodeURIComponent(b.hotel_address || '');
				calGoogle.href = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' + calTitle + '&dates=' + dtStart + '/' + dtEnd + '&details=' + calDesc + '&location=' + calLoc;
			}
			var calIcs = document.getElementById('mhbo-cal-ics');
			if (calIcs && b.check_in && b.check_out) {
				calIcs.onclick = function(e) {
					e.preventDefault();
					var icsStatus = 'CONFIRMED';
					if (b.status === 'pending' || b.status === 'pending_payment') {
						icsStatus = 'TENTATIVE';
					} else if (b.status === 'cancelled' || b.status === 'refunded') {
						icsStatus = 'CANCELLED';
					}
					var icsData = [
						'BEGIN:VCALENDAR',
						'VERSION:2.0',
						'PRODID:-//Modern Hotel Booking//EN',
						'BEGIN:VEVENT',
						'UID:mhbo-' + b.booking_id + '@' + window.location.hostname,
						'DTSTAMP:' + new Date().toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z',
						'DTSTART;VALUE=DATE:' + b.check_in.replace(/-/g, ''),
						'DTEND;VALUE=DATE:' + b.check_out.replace(/-/g, ''),
						'SUMMARY:' + (b.hotel_name || 'Hotel') + ' - ' + b.room_name,
						'DESCRIPTION:Reservation #' + b.booking_id + ' for ' + b.customer_name,
						'LOCATION:' + (b.hotel_address || ''),
						'STATUS:' + icsStatus,
						'END:VEVENT',
						'END:VCALENDAR'
					].join('\r\n');
					var blob = new Blob([icsData], { type: 'text/calendar;charset=utf-8' });
					var link = document.createElement('a');
					link.href = URL.createObjectURL(blob);
					link.download = 'stay-reservation-' + b.booking_id + '.ics';
					document.body.appendChild(link);
					link.click();
					document.body.removeChild(link);
				};
			}

			// Share / Copy Portal Link (only available when authenticated via magic link token)
			var shareBtn = document.getElementById('mhbo-qa-share-btn');
			var shareText = document.getElementById('mhbo-qa-share-text');
			if (shareBtn) {
				if (b.booking_token) {
					shareBtn.style.display = 'inline-flex';
					shareBtn.onclick = function() {
						var shareUrl = window.location.origin + window.location.pathname + '?token=' + encodeURIComponent(b.booking_token);
						if (navigator.clipboard) {
							navigator.clipboard.writeText(shareUrl);
							if (shareText) shareText.textContent = __t('copied', 'Copied!');
							setTimeout(function() {
								if (shareText) shareText.textContent = 'Share Portal Link';
							}, 2000);
						}
					};
				} else {
					shareBtn.style.display = 'none';
					shareBtn.onclick = null;
				}
			}

			// Multi-Room Group Reservation Banner
			var groupCard = document.getElementById('mhbo-portal-group-card');
			var groupIdLabel = document.getElementById('mhbo-group-id-label');
			var groupPayBtn = document.getElementById('mhbo-group-pay-btn');
			var siblingsList = document.getElementById('mhbo-group-siblings-list');
			if (groupCard) {
				if (b.is_group_booking) {
					if (groupIdLabel) groupIdLabel.textContent = '#' + (b.group_id || b.group_parent_ref || b.booking_id);
					if (groupPayBtn) {
						const isPayableGroupStatus = (b.status !== 'cancelled' && b.status !== 'refunded' && b.status !== 'pending_payment');
						const groupRemaining = b.group_remaining_balance !== undefined ? parseFloat(b.group_remaining_balance) : parseFloat(b.balance_remaining || 0);
						if (isPayableGroupStatus && groupRemaining > 0.001 && b.group_balance_payment_url) {
							groupPayBtn.href = b.group_balance_payment_url;
							groupPayBtn.onclick = null;
							groupPayBtn.style.display = 'inline-flex';
						} else if (isPayableGroupStatus && groupRemaining > 0.001 && b.group_can_pay_balance) {
							groupPayBtn.href = '#mhbo-pay-group-balance';
							groupPayBtn.onclick = function(e) {
								e.preventDefault();
								requestAndOpenBalanceLink('group', groupPayBtn);
							};
							groupPayBtn.style.display = 'inline-flex';
						} else {
							groupPayBtn.onclick = null;
							groupPayBtn.style.display = 'none';
						}
					}
					if (siblingsList && b.group_siblings && b.group_siblings.length > 0) {
						siblingsList.innerHTML = '';
						b.group_siblings.forEach(function(sib) {
							var isCurrent = parseInt(sib.booking_id, 10) === parseInt(b.booking_id, 10);
							var btn = document.createElement('button');
							btn.type = 'button';
							btn.className = 'mhbo-group-chip' + (isCurrent ? ' mhbo-group-chip-active' : '');
							btn.textContent = '#' + sib.booking_id + ' • ' + (sib.room_name || sib.room_label || '');
							if (!isCurrent) {
								btn.addEventListener('click', function() {
									fetchBooking({ booking_id: sib.booking_id, email: b.customer_email });
								});
							}
							siblingsList.appendChild(btn);
						});
					}
					groupCard.style.display = 'block';
				} else {
					groupCard.style.display = 'none';
				}
			}

			var resIdEl = document.getElementById('mhbo-res-id');
			if (resIdEl) resIdEl.textContent = b.booking_id;
			var printIds = document.querySelectorAll('.mhbo-print-res-id');
			printIds.forEach(function(el) { el.textContent = b.booking_id; });

			var roomEl = document.getElementById('mhbo-res-room');
			if (roomEl) roomEl.textContent = b.room_name;
			var roomTypeEl = document.getElementById('mhbo-res-room-type');
			if (roomTypeEl) roomTypeEl.textContent = (b.room_type && b.room_type !== b.room_name) ? b.room_type : '';
			var ciEl = document.getElementById('mhbo-res-checkin');
			if (ciEl) ciEl.textContent = b.check_in_formatted;
			var coEl = document.getElementById('mhbo-res-checkout');
			if (coEl) coEl.textContent = b.check_out_formatted;
			var ciTimeEl = document.getElementById('mhbo-res-checkin-time');
			if (ciTimeEl) {
				ciTimeEl.textContent = b.check_in_time || '14:00';
				syncPortalTimeBadge(ciTimeEl, b.is_early_checkin ? { cls: 'is-early', text: 'Early Check-in' } : (b.is_checkin_shifted ? { cls: 'is-shifted', text: 'Adjusted for Turnaround' } : null), b.is_request_only);
			}
			var coTimeEl = document.getElementById('mhbo-res-checkout-time');
			if (coTimeEl) {
				coTimeEl.textContent = b.check_out_time || '11:00';
				syncPortalTimeBadge(coTimeEl, b.is_late_checkout ? { cls: 'is-late', text: 'Late Check-out' } : null, b.is_request_only);
			}
			function syncPortalTimeBadge(targetEl, primaryBadge, isRequestOnly) {
				var parent = targetEl.parentElement;
				if (!parent) return;
				var existing = parent.querySelectorAll('.mhbo-portal-time-badge');
				existing.forEach(function(n) { n.remove(); });
				if (primaryBadge) {
					var pill = document.createElement('span');
					pill.className = 'mhbo-portal-time-badge ' + primaryBadge.cls;
					pill.textContent = primaryBadge.text;
					parent.appendChild(pill);
					if (isRequestOnly) {
						var req = document.createElement('span');
						req.className = 'mhbo-portal-time-badge is-request';
						req.textContent = 'Subject to confirmation';
						parent.appendChild(req);
					}
				}
			}
			var nightsEl = document.getElementById('mhbo-res-nights');
			if (nightsEl) nightsEl.textContent = b.nights;
			var nameEl = document.getElementById('mhbo-res-name');
			if (nameEl) nameEl.textContent = b.customer_name;
			var emailEl = document.getElementById('mhbo-res-email');
			if (emailEl) emailEl.textContent = b.customer_email;
			var guestsEl = document.getElementById('mhbo-res-guests');
			if (guestsEl) guestsEl.textContent = b.guests + ' Adults' + (b.children > 0 ? ', ' + b.children + ' Children' : '');
			
			var phoneEl = document.getElementById('mhbo-res-phone');
			if (phoneEl) phoneEl.textContent = b.customer_phone || 'None provided';

			var arrivalWrap = document.getElementById('mhbo-res-arrival-wrap');
			var arrivalEl = document.getElementById('mhbo-res-arrival');
			if (arrivalWrap && arrivalEl) {
				if (b.arrival_time) {
					arrivalEl.textContent = b.arrival_time;
					arrivalWrap.style.display = 'block';
				} else {
					arrivalWrap.style.display = 'none';
				}
			}

			var requestsWrap = document.getElementById('mhbo-res-requests-wrap');
			var requestsEl = document.getElementById('mhbo-res-requests');
			if (requestsWrap && requestsEl) {
				if (b.special_requests) {
					requestsEl.textContent = b.special_requests;
					requestsWrap.style.display = 'block';
				} else {
					requestsWrap.style.display = 'none';
				}
			}

			// Hotel Property Details & Front Desk Contact Card
			var hotelAddr = document.getElementById('mhbo-hotel-address');
			if (hotelAddr) hotelAddr.textContent = b.hotel_address || 'Hotel Front Desk';
			var hotelMapLink = document.getElementById('mhbo-hotel-map-link');
			if (hotelMapLink) {
				if (b.google_maps_url) {
					hotelMapLink.href = b.google_maps_url;
					hotelMapLink.style.display = 'inline-flex';
				} else {
					hotelMapLink.style.display = 'none';
				}
			}
			var hotelPhoneLink = document.getElementById('mhbo-hotel-phone-link');
			if (hotelPhoneLink) {
				if (b.hotel_phone) {
					hotelPhoneLink.textContent = b.hotel_phone;
					hotelPhoneLink.href = 'tel:' + b.hotel_phone.replace(/[^0-9+]/g, '');
				} else {
					hotelPhoneLink.textContent = 'Contact on arrival';
					hotelPhoneLink.removeAttribute('href');
				}
			}
			var hotelEmailLink = document.getElementById('mhbo-hotel-email-link');
			if (hotelEmailLink) {
				if (b.hotel_email) {
					hotelEmailLink.textContent = b.hotel_email;
					hotelEmailLink.href = 'mailto:' + b.hotel_email;
				} else {
					hotelEmailLink.textContent = '';
				}
			}
			var hotelWaWrap = document.getElementById('mhbo-hotel-wa-wrap');
			var hotelWaLink = document.getElementById('mhbo-hotel-wa-link');
			if (hotelWaWrap && hotelWaLink) {
				if (b.whatsapp_url) {
					hotelWaLink.href = b.whatsapp_url;
					hotelWaWrap.style.display = 'block';
				} else {
					hotelWaWrap.style.display = 'none';
				}
			}

			// Local Attractions Guide (Pro)
			var attrCard = document.getElementById('mhbo-portal-attractions-card');
			var attrList = document.getElementById('mhbo-attractions-list');
			if (attrCard && attrList) {
				if (b.attractions && b.attractions.length > 0) {
					attrList.innerHTML = '';
					b.attractions.forEach(function(item) {
						var card = document.createElement('div');
						card.className = 'mhbo-attraction-card';
						card.innerHTML = '<div class="mhbo-attraction-title">' + escapeHtml(item.title || item.name) + '</div>' +
							'<div class="mhbo-attraction-meta">' + (item.category ? escapeHtml(item.category) + ' &bull; ' : '') + escapeHtml(item.distance || '') + '</div>' +
							(item.description ? '<div style="font-size:12px; color:#475569; margin-top:4px;">' + escapeHtml(item.description) + '</div>' : '');
						attrList.appendChild(card);
					});
					attrCard.style.display = 'block';
				} else {
					attrCard.style.display = 'none';
				}
			}

			var totalEl = document.getElementById('mhbo-res-total');
			if (totalEl) totalEl.textContent = b.total_price_formatted;
			var paidEl = document.getElementById('mhbo-res-paid');
			if (paidEl) paidEl.textContent = b.total_paid_formatted;
			var balEl = document.getElementById('mhbo-res-balance');
			if (balEl) balEl.textContent = b.balance_remaining_formatted;

			// Coupon discount breakdown
			var couponRow = document.getElementById('mhbo-res-coupon-row');
			var couponCodeEl = document.getElementById('mhbo-res-coupon-code');
			var couponDiscountEl = document.getElementById('mhbo-res-coupon-discount');
			if (couponRow && b.coupon_discount > 0.001 && b.coupon_discount_formatted) {
				if (couponCodeEl) couponCodeEl.textContent = b.coupon_code || '';
				if (couponDiscountEl) couponDiscountEl.textContent = '−' + b.coupon_discount_formatted;
				couponRow.style.display = 'flex';
			} else if (couponRow) {
				couponRow.style.display = 'none';
			}

			// Service fee breakdown
			var sfRow = document.getElementById('mhbo-res-service-fee-row');
			var sfLabelEl = document.getElementById('mhbo-res-service-fee-label');
			var sfAmountEl = document.getElementById('mhbo-res-service-fee-amount');
			if (sfRow && b.service_fee_amount > 0.001 && b.service_fee_formatted) {
				if (sfLabelEl) sfLabelEl.textContent = b.service_fee_label || 'Service Fee';
				if (sfAmountEl) sfAmountEl.textContent = b.service_fee_formatted;
				sfRow.style.display = 'flex';
			} else if (sfRow) {
				sfRow.style.display = 'none';
			}

			// Tax breakdown
			var taxRow = document.getElementById('mhbo-res-tax-row');
			var taxLabelEl = document.getElementById('mhbo-res-tax-label');
			var taxAmountEl = document.getElementById('mhbo-res-tax-amount');
			if (taxRow && b.tax_amount > 0.001 && b.tax_formatted) {
				if (taxLabelEl) taxLabelEl.textContent = b.tax_label || 'Tax';
				if (taxAmountEl) taxAmountEl.textContent = b.tax_formatted;
				taxRow.style.display = 'flex';
			} else if (taxRow) {
				taxRow.style.display = 'none';
			}

			// Deposit breakdown
			var isClosedBooking = (b.status === 'cancelled' || b.status === 'refunded');
			var depositRow = document.getElementById('mhbo-res-deposit-row');
			var depositValEl = document.getElementById('mhbo-res-deposit-val');
			var depositNoteEl = document.getElementById('mhbo-res-deposit-status-note');
			if (depositRow && b.deposit_amount > 0.001 && (!isClosedBooking || b.is_deposit_paid)) {
				if (depositValEl) depositValEl.textContent = b.deposit_formatted;
				if (depositNoteEl) {
					var noteText = b.is_deposit_paid
						? ('✓ ' + (i18n.deposit_paid || 'Deposit Paid'))
						: (i18n.due_at_booking || 'Due at booking');
					depositNoteEl.className = 'mhbo-deposit-status-note ' + (b.is_deposit_paid ? 'is-paid' : 'is-due');
					depositNoteEl.textContent = ' (' + noteText + ')';
				}
				depositRow.style.display = 'flex';
			} else if (depositRow) {
				depositRow.style.display = 'none';
			}

			// Settled badge
			var settledBadge = document.getElementById('mhbo-res-settled-badge');
			if (settledBadge) {
				settledBadge.style.display = (!isClosedBooking && b.balance_remaining <= 0.001 && b.total_paid > 0.001) ? 'block' : 'none';
			}

			// Wi-Fi Access Card
			var wifiCard = document.getElementById('mhbo-wifi-card');
			var wifiSsid = document.getElementById('mhbo-wifi-ssid');
			var wifiPass = document.getElementById('mhbo-wifi-pass');
			var wifiPassWrap = document.getElementById('mhbo-wifi-pass-wrap');
			if (wifiCard) {
				if (b.wifi_ssid) {
					if (wifiSsid) wifiSsid.textContent = b.wifi_ssid;
					if (b.wifi_password) {
						if (wifiPass) wifiPass.textContent = b.wifi_password;
						if (wifiPassWrap) wifiPassWrap.style.display = 'inline-block';
					} else if (wifiPassWrap) {
						wifiPassWrap.style.display = 'none';
					}
					wifiCard.style.display = 'block';
				} else {
					wifiCard.style.display = 'none';
				}
			}

			// Digital Guest Compendium & House Rules
			var guideCard = document.getElementById('mhbo-guide-card');
			var guideContent = document.getElementById('mhbo-guide-content');
			if (guideCard && guideContent) {
				if (b.guest_guide) {
					guideContent.innerHTML = b.guest_guide;
					guideCard.style.display = 'block';
				} else {
					guideCard.style.display = 'none';
				}
			}

			// Sent Communications History (Pro)
			var historyCard = document.getElementById('mhbo-email-history-card');
			var historyList = document.getElementById('mhbo-email-history-list');
			if (historyCard && historyList) {
				if (b.email_history && b.email_history.length > 0) {
					historyList.innerHTML = '';
					b.email_history.forEach(function(item) {
						var row = document.createElement('div');
						row.className = 'mhbo-history-item';
						row.innerHTML = '<div><strong>' + escapeHtml(item.subject) + '</strong><div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">' + escapeHtml(item.type) + '</div></div>' +
							'<div style="font-size: 12px; color: #475569; font-weight: 500;">' + escapeHtml(item.date) + '</div>';
						historyList.appendChild(row);
					});
					historyCard.style.display = 'block';
				} else {
					historyCard.style.display = 'none';
				}
			}

			// Print header metadata
			var printHotelName = document.getElementById('mhbo-print-hotel-name');
			if (printHotelName) printHotelName.textContent = b.hotel_name || '';
			var printHotelAddress = document.getElementById('mhbo-print-hotel-address');
			if (printHotelAddress) printHotelAddress.textContent = b.hotel_address || '';
			var printHotelContact = document.getElementById('mhbo-print-hotel-contact');
			if (printHotelContact) printHotelContact.textContent = (b.hotel_phone ? b.hotel_phone + ' | ' : '') + (b.hotel_email || '');

			// Available Vouchers / Promo Codes (Pro)
			var vouchersCard = document.getElementById('mhbo-res-vouchers-card');
			var vouchersList = document.getElementById('mhbo-res-vouchers-list');
			if (vouchersCard && vouchersList) {
				if (b.available_coupons && b.available_coupons.length > 0) {
					vouchersList.innerHTML = '';
					b.available_coupons.forEach(function(cpn) {
						var chip = document.createElement('div');
						chip.className = 'mhbo-voucher-chip';
						chip.innerHTML = '<span class="mhbo-voucher-code">' + escapeHtml(cpn.code) + '</span>' +
							'<span><strong>' + escapeHtml(cpn.discount_formatted) + ' Off</strong> &bull; ' + (cpn.expiry_formatted ? 'Valid until ' + escapeHtml(cpn.expiry_formatted) : 'No expiry') + '</span>' +
							'<button type="button" class="mhbo-copy-btn" data-code="' + escapeHtml(cpn.code) + '">' + escapeHtml(__t('copy', 'Copy')) + '</button>';
						vouchersList.appendChild(chip);
					});
					vouchersCard.style.display = 'block';

					vouchersList.querySelectorAll('.mhbo-copy-btn').forEach(function(btn) {
						btn.addEventListener('click', function() {
							var code = this.getAttribute('data-code');
							if (navigator.clipboard && code) {
								navigator.clipboard.writeText(code);
								this.textContent = __t('copied', 'Copied!');
								var self = this;
								setTimeout(function() { self.textContent = __t('copy', 'Copy'); }, 2000);
							}
						});
					});
				} else {
					vouchersCard.style.display = 'none';
				}
			}

			// Pending self-service request banner
			var pendingBanner = document.getElementById('mhbo-portal-pending-req-banner');
			if (pendingBanner) {
				var reqMsgs = [];
				if (b.has_pending_cancellation_request) {
					reqMsgs.push(__t('pending_cancel_request', 'A cancellation request is currently pending front desk review.'));
				}
				if (b.has_pending_modification_request) {
					reqMsgs.push(__t('pending_mod_request', 'A stay modification request is currently pending front desk review.'));
				}
				if (reqMsgs.length > 0 && b.status !== 'cancelled' && b.status !== 'refunded') {
					pendingBanner.textContent = reqMsgs.join(' ');
					pendingBanner.style.display = 'block';
				} else {
					pendingBanner.style.display = 'none';
				}
			}

			// Badges
			var statusBadge = document.getElementById('mhbo-res-status-badge');
			if (statusBadge) {
				statusBadge.textContent = b.status_label || b.status;
				statusBadge.className = 'mhbo-portal-badge mhbo-badge-' + (b.status || 'pending');
			}

			var paymentBadge = document.getElementById('mhbo-res-payment-badge');
			if (paymentBadge) {
				paymentBadge.textContent = b.payment_status_label || b.payment_status;
				var payBadgeClass = 'mhbo-badge-paid';
				if (b.status === 'refunded' || b.payment_status === 'refunded') {
					payBadgeClass = 'mhbo-badge-refunded';
				} else if (b.status === 'cancelled') {
					payBadgeClass = (parseFloat(b.total_paid || 0) > 0.001) ? 'mhbo-badge-pending' : 'mhbo-badge-neutral';
				}
				paymentBadge.className = 'mhbo-portal-badge ' + payBadgeClass;
			}

			// Balance payment link
			var balanceCta = document.getElementById('mhbo-res-balance-cta');
			var balanceBtn = document.getElementById('mhbo-res-balance-btn');
			if (balanceCta && balanceBtn) {
				var isPayableStatus = (b.status !== 'cancelled' && b.status !== 'refunded' && b.status !== 'pending_payment');
				if (isPayableStatus && b.balance_remaining > 0.001 && b.balance_payment_url) {
					balanceBtn.href = b.balance_payment_url;
					balanceBtn.onclick = null;
					balanceCta.style.display = 'block';
				} else if (isPayableStatus && b.balance_remaining > 0.001 && b.can_pay_balance) {
					balanceBtn.href = '#mhbo-pay-balance';
					balanceBtn.onclick = function(e) {
						e.preventDefault();
						requestAndOpenBalanceLink('room', balanceBtn);
					};
					balanceCta.style.display = 'block';
				} else {
					balanceBtn.onclick = null;
					balanceCta.style.display = 'none';
				}
			}

			// Cancellation buttons & modes
			var cancelBtn = document.getElementById('mhbo-portal-cancel-btn');
			var cancelBtnText = document.getElementById('mhbo-cancel-btn-text');

			if (cancelBtn) {
				if (b.cancellation_requires_staff && b.cancellation_request_allowed) {
					cancelBtn.style.display = 'inline-flex';
					if (cancelBtnText) cancelBtnText.textContent = __t('request_cancellation', 'Request Cancellation');
				} else if (b.cancellation_allowed) {
					cancelBtn.style.display = 'inline-flex';
					if (cancelBtnText) cancelBtnText.textContent = __t('cancel_reservation', 'Cancel Reservation');
				} else {
					cancelBtn.style.display = 'none';
				}
			}

			// Stay modification button
			var modifyBtn = document.getElementById('mhbo-portal-modify-btn');
			if (modifyBtn) {
				modifyBtn.style.display = b.modification_allowed ? 'inline-flex' : 'none';
				var modifyBtnLabel = modifyBtn.querySelector('.mhbo-modify-btn-label');
				var modifyText = b.modification_requires_staff
					? __t('request_stay_change', 'Request Stay Change')
					: __t('change_stay_dates', 'Change Stay Dates');
				if (modifyBtnLabel) {
					modifyBtnLabel.textContent = modifyText;
				}
			}

			// Cancellation & Modification Policy / Front Desk Guidance Card
			var policyCard = document.getElementById('mhbo-portal-policy-card');
			var policyTextEl = document.getElementById('mhbo-portal-policy-text');
			var policyContactEl = document.getElementById('mhbo-portal-policy-contact');
			if (policyCard) {
				var isInactiveBooking = (b.status === 'cancelled' || b.status === 'refunded' || b.status === 'checked_out' || b.status === 'completed');
				var selfServiceHidden = !b.modification_allowed && !b.cancellation_allowed && !b.cancellation_request_allowed;
				var policyEnabled = Boolean(b.cancellation_policy_enabled);
				var policyText = policyEnabled ? (b.property_cancellation_policy || '').trim() : '';
				var shouldShowPolicyCard = !isInactiveBooking && policyEnabled && (policyText !== '' || selfServiceHidden);

				if (shouldShowPolicyCard) {
					if (policyTextEl) {
						policyTextEl.textContent = policyText;
						policyTextEl.style.display = policyText !== '' ? 'block' : 'none';
					}
					if (policyContactEl) {
						if (selfServiceHidden) {
							var deskContact = b.hotel_phone || b.hotel_email || '';
							policyContactEl.textContent = deskContact
								? __t('need_changes_contact', 'Need to change or cancel your stay? Please contact our front desk directly at %s.').replace('%s', deskContact)
								: __t('need_changes_fallback', 'Need to change or cancel your stay? Please contact our front desk directly for assistance.');
							policyContactEl.style.display = 'block';
						} else {
							policyContactEl.textContent = '';
							policyContactEl.style.display = 'none';
						}
					}
					policyCard.style.display = 'block';
				} else {
					if (policyTextEl) policyTextEl.style.display = 'none';
					if (policyContactEl) policyContactEl.style.display = 'none';
					policyCard.style.display = 'none';
				}
			}

			// AI Concierge button (Pro)
			var aiBtnEl = document.getElementById('mhbo-portal-ai-btn');
			if (aiBtnEl) {
				aiBtnEl.style.display = (b.ai_concierge_enabled === true) ? 'inline-flex' : 'none';
			}

			if (lookupView) lookupView.style.display = 'none';
			if (detailsView) detailsView.style.display = 'block';
		}

		// Lookup Form submit
		var form = document.getElementById('mhbo-portal-lookup-form');
		if (form) {
			form.addEventListener('submit', function(e) {
				e.preventDefault();
				var token = document.getElementById('mhbo-lookup-token').value.trim();
				var id = document.getElementById('mhbo-lookup-id').value.trim();
				var email = document.getElementById('mhbo-lookup-email').value.trim();

				if (token) {
					fetchBooking({ token: token });
				} else if (id && email) {
					fetchBooking({ booking_id: id, email: email });
				} else {
					showAlert(__t('empty_fields', 'Please enter either a booking token or both your Booking ID and Email address.'), false);
				}
			});
		}

		// Reset / Look up another
		var resetBtn = document.getElementById('mhbo-portal-reset-btn');
		if (resetBtn) {
			resetBtn.addEventListener('click', function() {
				hideAlert();
				currentBooking = null;
				try {
					sessionStorage.removeItem('mhbo_portal_token');
					sessionStorage.removeItem('mhbo_portal_booking_id');
				} catch(e) {}
				if (detailsView) detailsView.style.display = 'none';
				if (lookupView) lookupView.style.display = 'block';
			});
		}

		// AI Concierge Shortcut
		var aiBtn = document.getElementById('mhbo-portal-ai-btn');
		if (aiBtn) {
			aiBtn.addEventListener('click', function() {
				var widgetToggle = document.querySelector('.mhbo-chat-fab') || document.getElementById('mhbo-ai-concierge-toggle') || document.querySelector('.mhbo-concierge-toggle') || document.getElementById('mhbo-concierge-btn');
				if (widgetToggle) {
					widgetToggle.click();
				}
				try {
					window.dispatchEvent(new CustomEvent('mhbo_open_concierge', { detail: currentBooking }));
				} catch (e) {}
			});
		}

		// Wi-Fi Password Copy
		var wifiCopyBtn = document.getElementById('mhbo-wifi-copy-btn');
		if (wifiCopyBtn) {
			wifiCopyBtn.addEventListener('click', function() {
				var passEl = document.getElementById('mhbo-wifi-pass');
				if (passEl && navigator.clipboard) {
					navigator.clipboard.writeText(passEl.textContent.trim());
					wifiCopyBtn.textContent = __t('copied', 'Copied!');
					setTimeout(function() { wifiCopyBtn.textContent = __t('copy_password', 'Copy Password'); }, 2000);
				}
			});
		}

		// Guide Card Accordion Toggle
		var guideHeader = document.getElementById('mhbo-guide-header');
		var guideContent = document.getElementById('mhbo-guide-content');
		var guideArrow = document.getElementById('mhbo-guide-arrow');
		if (guideHeader && guideContent) {
			guideHeader.addEventListener('click', function() {
				var isHidden = guideContent.style.display === 'none';
				guideContent.style.display = isHidden ? 'block' : 'none';
				if (guideArrow) guideArrow.textContent = isHidden ? '▲' : '▼';
			});
		}

		// Email History Accordion Toggle
		var histHeader = document.getElementById('mhbo-email-history-header');
		var histList = document.getElementById('mhbo-email-history-list');
		var histArrow = document.getElementById('mhbo-email-history-arrow');
		if (histHeader && histList) {
			histHeader.addEventListener('click', function() {
				var isHidden = histList.style.display === 'none';
				histList.style.display = isHidden ? 'block' : 'none';
				if (histArrow) histArrow.textContent = isHidden ? '▲' : '▼';
			});
		}

		// Cancel Modal Handling
		var cancelModal = document.getElementById('mhbo-portal-cancel-modal');
		var cancelBtn = document.getElementById('mhbo-portal-cancel-btn');
		var cancelClose = document.getElementById('mhbo-cancel-modal-close');
		var cancelConfirm = document.getElementById('mhbo-cancel-modal-confirm');
		var cancelTitle = document.getElementById('mhbo-cancel-modal-title');
		var cancelPaidWarning = document.getElementById('mhbo-cancel-paid-warning');
		var cancelReviewInfo = document.getElementById('mhbo-cancel-review-info');
		var cancelPaidAmount = document.getElementById('mhbo-cancel-paid-amount');

		if (cancelBtn && cancelModal) {
			cancelBtn.addEventListener('click', function() {
				if (!currentBooking) return;
				var noticeEl = document.getElementById('mhbo-cancel-policy-notice');
				var hasPaid = parseFloat(currentBooking.total_paid || 0) > 0.001;
				if (noticeEl) {
					if (currentBooking.cancellation_requires_staff) {
						var propPolicy = (currentBooking.property_cancellation_policy || '').trim();
						noticeEl.textContent = propPolicy;
						noticeEl.style.display = propPolicy ? 'block' : 'none';
					} else {
						noticeEl.textContent = currentBooking.cancellation_policy_notice || '';
						noticeEl.style.display = noticeEl.textContent ? 'block' : 'none';
					}
				}
				if (currentBooking.cancellation_requires_staff) {
					if (cancelTitle) cancelTitle.textContent = __t('request_cancellation', 'Request Cancellation');
					if (cancelConfirm) cancelConfirm.textContent = __t('submit_cancel_request', 'Submit Cancellation Request');
					if (hasPaid) {
						if (cancelPaidAmount) cancelPaidAmount.textContent = currentBooking.total_paid_formatted;
						if (cancelPaidWarning) cancelPaidWarning.style.display = 'block';
						if (cancelReviewInfo) cancelReviewInfo.style.display = 'none';
					} else {
						if (cancelPaidWarning) cancelPaidWarning.style.display = 'none';
						if (cancelReviewInfo) cancelReviewInfo.style.display = 'block';
					}
				} else {
					if (cancelTitle) cancelTitle.textContent = __t('cancel_reservation', 'Cancel Reservation');
					if (cancelConfirm) cancelConfirm.textContent = __t('confirm_cancellation', 'Confirm Cancellation');
					if (cancelPaidWarning) cancelPaidWarning.style.display = 'none';
					if (cancelReviewInfo) cancelReviewInfo.style.display = 'none';
				}
				
				cancelModal.style.display = 'flex';
				if (cancelClose) cancelClose.focus();
			});
		}
		if (cancelClose && cancelModal) {
			cancelClose.addEventListener('click', function() {
				cancelModal.style.display = 'none';
				if (cancelBtn) cancelBtn.focus();
			});
			cancelModal.addEventListener('click', function(e) {
				if (e.target === cancelModal) {
					cancelModal.style.display = 'none';
					if (cancelBtn) cancelBtn.focus();
				}
			});
		}
		if (cancelConfirm && cancelModal) {
			cancelConfirm.addEventListener('click', function() {
				if (!currentBooking) return;
				cancelConfirm.disabled = true;
				cancelConfirm.textContent = __t('processing', 'Processing...');

				var reason = document.getElementById('mhbo-cancel-reason').value.trim();
				var cancelHeaders = {
					'Content-Type': 'application/json'
				};
				if (nonce) {
					cancelHeaders['X-WP-Nonce'] = nonce;
				}
				fetch(restUrl + '/cancel', {
					method: 'POST',
					headers: cancelHeaders,
					body: JSON.stringify({
						token: currentBooking.booking_token,
						booking_id: currentBooking.booking_id,
						email: currentBooking.customer_email,
						reason: reason
					})
				})
				.then(parseJsonSafe)
				.then(function(result) {
					var data = result.data;
					cancelConfirm.disabled = false;
					cancelConfirm.textContent = currentBooking && currentBooking.cancellation_requires_staff ? __t('submit_cancel_request', 'Submit Cancellation Request') : __t('confirm_cancellation', 'Confirm Cancellation');
					cancelModal.style.display = 'none';
					if (cancelBtn) cancelBtn.focus();
					if (result.ok && data && data.success) {
						showAlert(data.message, true);
						fetchBooking(getPortalLookupParams());
					} else {
						showAlert((data && data.message) ? data.message : __t('cancel_failed', 'Failed to process cancellation request.'), false);
					}
				})
				.catch(function(_err) {
					cancelConfirm.disabled = false;
					cancelConfirm.textContent = currentBooking && currentBooking.cancellation_requires_staff ? __t('submit_cancel_request', 'Submit Cancellation Request') : __t('confirm_cancellation', 'Confirm Cancellation');
					cancelModal.style.display = 'none';
					if (cancelBtn) cancelBtn.focus();
					showAlert(__t('network_error', 'Network error while processing request. Please try again.'), false);
				});
			});
		}

		// Calendar Dropdown Toggle
		var calBtn = document.getElementById('mhbo-qa-calendar-btn');
		var calMenu = document.getElementById('mhbo-qa-calendar-menu');
		if (calBtn && calMenu) {
			calBtn.addEventListener('click', function(e) {
				e.stopPropagation();
				var isVisible = calMenu.style.display !== 'none';
				calMenu.style.display = isVisible ? 'none' : 'block';
			});
			document.addEventListener('click', function() {
				if (calMenu) calMenu.style.display = 'none';
			});
		}

		// Attractions Accordion Toggle (Pro)
		var attrHeader = document.getElementById('mhbo-attractions-header');
		var attrContent = document.getElementById('mhbo-attractions-content');
		var attrArrow = document.getElementById('mhbo-attractions-arrow');
		if (attrHeader && attrContent) {
			attrHeader.addEventListener('click', function() {
				var isHidden = attrContent.style.display === 'none';
				attrContent.style.display = isHidden ? 'block' : 'none';
				if (attrArrow) attrArrow.textContent = isHidden ? '▲' : '▼';
			});
		}

		// Modify / Stay Change Modal Handling
		var modModal = document.getElementById('mhbo-portal-modify-modal');
		var modBtn = document.getElementById('mhbo-portal-modify-btn');
		var modClose = document.getElementById('mhbo-mod-modal-close');
		var modSubmit = document.getElementById('mhbo-mod-modal-submit');
		var modError = document.getElementById('mhbo-mod-modal-error');
		var modPaidInfo = document.getElementById('mhbo-mod-paid-info');
		var modPreviewBox = document.getElementById('mhbo-mod-preview-box');
		var modPreviewStatus = document.getElementById('mhbo-mod-preview-status');
		var modPreviewNights = document.getElementById('mhbo-mod-preview-nights');
		var modPreviewPrice = document.getElementById('mhbo-mod-preview-price');
		var modPreviewNote = document.getElementById('mhbo-mod-preview-note');
		var modPriceConfirmWrap = document.getElementById('mhbo-mod-price-confirm-wrap');
		var modConfirmPrice = document.getElementById('mhbo-mod-confirm-price');
		var modCheckInInput = document.getElementById('mhbo-mod-checkin');
		var modCheckOutInput = document.getElementById('mhbo-mod-checkout');
		var lastPreview = null;
		var previewTimer = null;
		var previewAbortController = null;

		function showModError(msg) {
			if (!modError) return;
			modError.setAttribute('role', 'alert');
			modError.setAttribute('aria-live', 'assertive');
			modError.textContent = msg;
			modError.style.display = 'block';
		}

		function clearModError() {
			if (modError) {
				modError.textContent = '';
				modError.style.display = 'none';
			}
		}

		function resetModPreview() {
			lastPreview = null;
			if (previewTimer) {
				clearTimeout(previewTimer);
				previewTimer = null;
			}
			if (previewAbortController) {
				previewAbortController.abort();
				previewAbortController = null;
			}
			if (modPreviewBox) modPreviewBox.style.display = 'none';
			if (modPreviewStatus) modPreviewStatus.textContent = '';
			if (modPreviewNights) modPreviewNights.textContent = '';
			if (modPreviewPrice) modPreviewPrice.textContent = '';
			if (modPreviewNote) {
				modPreviewNote.textContent = '';
				modPreviewNote.style.display = 'none';
			}
			if (modPriceConfirmWrap) modPriceConfirmWrap.style.display = 'none';
			if (modConfirmPrice) modConfirmPrice.checked = false;
		}

		function applyPreviewToUi(p) {
			lastPreview = p;
			if (!modPreviewBox) return;
			modPreviewBox.style.display = 'block';
			if (modPreviewStatus) {
				modPreviewStatus.textContent = (p.mode === 'auto')
					? (__t('available_label', 'Available') + ' (' + (p.current_status_label || p.current_status || '') + ')')
					: (p.review_reason || __t('review_by_front_desk', 'This request will be reviewed by our front desk team.'));
			}
			if (modPreviewNights) {
				modPreviewNights.textContent = p.nights_new + ' ' + (p.nights_new === 1 ? __t('night_singular', 'night') : __t('night_plural', 'nights'));
			}
			if (modPreviewPrice) {
				if (p.price_changed) {
					modPreviewPrice.textContent = p.old_total_formatted + ' → ' + p.new_total_formatted + ' (' + p.price_delta_formatted + ')';
				} else {
					modPreviewPrice.textContent = p.new_total_formatted + ' (' + __t('no_price_change', 'No price change') + ')';
				}
			}
			if (modPreviewNote) {
				if (p.status_notice) {
					modPreviewNote.textContent = p.status_notice;
					modPreviewNote.style.display = 'block';
				} else {
					modPreviewNote.textContent = '';
					modPreviewNote.style.display = 'none';
				}
			}
			if (modPaidInfo) {
				if (p.mode === 'staff_review' && p.review_reason) {
					modPaidInfo.textContent = p.review_reason;
					modPaidInfo.style.display = 'block';
				} else {
					modPaidInfo.style.display = 'none';
				}
			}
			if (modPriceConfirmWrap && modConfirmPrice) {
				if (p.mode === 'auto' && p.price_changed) {
					modPriceConfirmWrap.style.display = 'flex';
					modConfirmPrice.checked = false;
				} else {
					modPriceConfirmWrap.style.display = 'none';
					modConfirmPrice.checked = false;
				}
			}
			if (modSubmit) {
				modSubmit.textContent = (p.mode === 'staff_review')
					? __t('submit_mod_request', 'Submit Modification Request')
					: __t('update_reservation', 'Update Reservation');
			}
		}

		function scheduleStayPreview() {
			if (!currentBooking || !modCheckInInput || !modCheckOutInput) return;
			clearModError();
			var ci = (modCheckInInput.value || '').trim();
			var co = (modCheckOutInput.value || '').trim();
			if (!ci || !co) {
				resetModPreview();
				return;
			}
			if (ci >= co) {
				resetModPreview();
				showModError(__t('checkout_after_checkin', 'Check-out date must be after check-in date.'));
				return;
			}
			if (ci === currentBooking.check_in && co === currentBooking.check_out) {
				resetModPreview();
				return;
			}
			if (previewTimer) clearTimeout(previewTimer);
			previewTimer = setTimeout(function() {
				if (previewAbortController) previewAbortController.abort();
				previewAbortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
				var reqCtrl = previewAbortController;
				if (modPreviewBox && modPreviewStatus) {
					modPreviewBox.style.display = 'block';
					modPreviewStatus.textContent = __t('checking_availability', 'Checking availability and recalculating total...');
				}
				var payload = Object.assign({}, getPortalLookupParams(), {
					check_in: ci,
					check_out: co
				});
				var previewHeaders = { 'Content-Type': 'application/json' };
				if (nonce) {
					previewHeaders['X-WP-Nonce'] = nonce;
				}
				var opts = {
					method: 'POST',
					headers: previewHeaders,
					body: JSON.stringify(payload)
				};
				if (reqCtrl) opts.signal = reqCtrl.signal;
				fetch(restUrl + '/preview-stay', opts)
					.then(function(res) { return res.json(); })
					.then(function(data) {
						if (reqCtrl && reqCtrl.signal.aborted) return;
						if (data && data.success) {
							applyPreviewToUi(data);
						} else {
							resetModPreview();
							showModError((data && data.message) ? data.message : __t('modify_failed', 'Failed to validate selected dates.'));
						}
					})
					.catch(function(err) {
						if (err && err.name === 'AbortError') return;
						resetModPreview();
					});
			}, 250);
		}

		function addDaysYmd(ymd, days) {
			var parts = String(ymd || '').split('-');
			if (parts.length !== 3) return '';
			var dt = new Date(Date.UTC(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10) + days));
			if (isNaN(dt.getTime())) return '';
			return dt.toISOString().slice(0, 10);
		}

		function syncModifyDateMins() {
			var todayStr = new Date().toISOString().slice(0, 10);
			if (modCheckInInput) {
				modCheckInInput.min = todayStr;
			}
			if (modCheckOutInput) {
				var baseCi = (modCheckInInput && modCheckInInput.value) ? modCheckInInput.value : todayStr;
				var minCo = addDaysYmd(baseCi, 1);
				if (minCo) modCheckOutInput.min = minCo;
			}
		}

		if (modCheckInInput) {
			modCheckInInput.addEventListener('change', function() {
				syncModifyDateMins();
				scheduleStayPreview();
			});
		}
		if (modCheckOutInput) modCheckOutInput.addEventListener('change', scheduleStayPreview);

		if (modBtn && modModal) {
			modBtn.addEventListener('click', function() {
				clearModError();
				resetModPreview();
				if (currentBooking) {
					if (modCheckInInput) modCheckInInput.value = currentBooking.check_in || '';
					if (modCheckOutInput) modCheckOutInput.value = currentBooking.check_out || '';
					syncModifyDateMins();
					var adultsInput = document.getElementById('mhbo-mod-adults');
					if (adultsInput) adultsInput.value = currentBooking.guests || 1;
					var childrenInput = document.getElementById('mhbo-mod-children');
					if (childrenInput) childrenInput.value = currentBooking.children || 0;
					
					if (modPaidInfo) {
						if (currentBooking.modification_requires_staff && currentBooking.modification_policy_notice) {
							modPaidInfo.textContent = currentBooking.modification_policy_notice;
							modPaidInfo.style.display = 'block';
						} else if (currentBooking.total_paid > 0.001) {
							modPaidInfo.style.display = 'block';
						} else {
							modPaidInfo.style.display = 'none';
						}
					}
					if (modSubmit) {
						modSubmit.textContent = currentBooking.modification_requires_staff
							? __t('submit_mod_request', 'Submit Modification Request')
							: __t('update_reservation', 'Update Reservation');
					}
				}
				modModal.style.display = 'flex';
				var initialInput = modCheckInInput || modClose;
				if (initialInput) initialInput.focus();
			});
		}
		if (modClose && modModal) {
			modClose.addEventListener('click', function() {
				clearModError();
				resetModPreview();
				modModal.style.display = 'none';
				if (modBtn) modBtn.focus();
			});
			modModal.addEventListener('click', function(e) {
				if (e.target === modModal) {
					clearModError();
					resetModPreview();
					modModal.style.display = 'none';
					if (modBtn) modBtn.focus();
				}
			});
		}
		if (modSubmit && modModal) {
			modSubmit.addEventListener('click', function() {
				if (!currentBooking) return;
				clearModError();
				var newCheckIn = (document.getElementById('mhbo-mod-checkin').value || '').trim();
				var newCheckOut = (document.getElementById('mhbo-mod-checkout').value || '').trim();
				var newAdults = parseInt(document.getElementById('mhbo-mod-adults').value, 10) || 1;
				var newChildren = parseInt(document.getElementById('mhbo-mod-children').value, 10) || 0;
				var notes = (document.getElementById('mhbo-mod-notes').value || '').trim();

				if (!newCheckIn || !newCheckOut) {
					showModError(__t('choose_dates_required', 'Please choose both check-in and check-out dates.'));
					return;
				}
				if (newCheckIn >= newCheckOut) {
					showModError(__t('checkout_after_checkin', 'Check-out date must be after check-in date.'));
					return;
				}
				if (lastPreview && lastPreview.mode === 'auto' && lastPreview.price_changed) {
					if (!modConfirmPrice || !modConfirmPrice.checked) {
						showModError(__t('confirm_price_required', 'Please check the box confirming the updated reservation total before proceeding.'));
						return;
					}
				}

				var confirmedTotal = '';
				if (lastPreview && lastPreview.mode === 'auto') {
					if (!lastPreview.price_changed || (modConfirmPrice && modConfirmPrice.checked)) {
						confirmedTotal = String(lastPreview.new_total_decimal || lastPreview.new_total || '');
					}
				}

				modSubmit.disabled = true;
				modSubmit.textContent = __t('submitting', 'Submitting...');

				var modHeaders = {
					'Content-Type': 'application/json'
				};
				if (nonce) {
					modHeaders['X-WP-Nonce'] = nonce;
				}
				fetch(restUrl + '/update-stay', {
					method: 'POST',
					headers: modHeaders,
					body: JSON.stringify({
						token: currentBooking.booking_token,
						booking_id: currentBooking.booking_id,
						email: currentBooking.customer_email,
						check_in: newCheckIn,
						check_out: newCheckOut,
						guests: newAdults,
						children: newChildren,
						new_check_in: newCheckIn,
						new_check_out: newCheckOut,
						new_guests: newAdults,
						new_children: newChildren,
						notes: notes,
						confirmed_total: confirmedTotal
					})
				})
				.then(parseJsonSafe)
				.then(function(result) {
					var data = result.data;
					modSubmit.disabled = false;
					var useStaffLabel = (lastPreview && lastPreview.mode === 'staff_review') || currentBooking.modification_requires_staff;
					modSubmit.textContent = useStaffLabel
						? __t('submit_mod_request', 'Submit Modification Request')
						: __t('update_reservation', 'Update Reservation');
					if (result.ok && data && data.success) {
						modModal.style.display = 'none';
						resetModPreview();
						if (modBtn) modBtn.focus();
						showAlert(data.message, true);
						fetchBooking(getPortalLookupParams());
					} else if (result.status === 409 && data && data.code === 'mhbo_price_confirmation_required' && data.data) {
						var d = data.data;
						applyPreviewToUi({
							mode: 'auto',
							nights_new: lastPreview ? lastPreview.nights_new : currentBooking.nights,
							old_total_formatted: d.old_total_formatted || currentBooking.total_price_formatted,
							new_total_formatted: d.new_total_formatted || '',
							new_total_decimal: d.new_total_decimal || d.new_total || '',
							new_total: d.new_total || 0,
							price_delta_formatted: d.price_delta_formatted || (d.price_delta === 0 ? '$0.00' : ''),
							price_changed: true,
							current_status_label: currentBooking.status_label || currentBooking.status
						});
						showModError(data.message || __t('confirm_price_required', 'Please confirm the updated reservation total above.'));
					} else {
						showModError(data && data.message ? data.message : __t('modify_failed', 'Failed to update stay.'));
					}
				})
				.catch(function(err) {
					modSubmit.disabled = false;
					var useStaffLabel = (lastPreview && lastPreview.mode === 'staff_review') || currentBooking.modification_requires_staff;
					modSubmit.textContent = useStaffLabel
						? __t('submit_mod_request', 'Submit Modification Request')
						: __t('update_reservation', 'Update Reservation');
					showModError(__t('network_error', 'Network error while submitting request. Please try again.'));
				});
			});
		}

		// Edit Guest Profile Modal Handling
		var editProfBtn = document.getElementById('mhbo-portal-edit-profile-btn');
		var editProfModal = document.getElementById('mhbo-portal-edit-profile-modal');
		var editProfClose = document.getElementById('mhbo-profile-modal-close');
		var editProfSubmit = document.getElementById('mhbo-profile-modal-submit');
		var editProfError = document.getElementById('mhbo-profile-modal-error');

		function showProfError(msg) {
			if (!editProfError) return;
			editProfError.setAttribute('role', 'alert');
			editProfError.setAttribute('aria-live', 'assertive');
			editProfError.textContent = msg;
			editProfError.style.display = 'block';
		}

		function clearProfError() {
			if (editProfError) {
				editProfError.textContent = '';
				editProfError.style.display = 'none';
			}
		}

		if (editProfBtn && editProfModal) {
			editProfBtn.addEventListener('click', function() {
				clearProfError();
				if (currentBooking) {
					var nameInput = document.getElementById('mhbo-profile-name');
					if (nameInput) nameInput.value = currentBooking.customer_name || '';
					var phoneInput = document.getElementById('mhbo-profile-phone');
					if (phoneInput) phoneInput.value = currentBooking.customer_phone || '';
					var emailInput = document.getElementById('mhbo-profile-email');
					if (emailInput) {
						emailInput.value = currentBooking.customer_email || '';
						emailInput.readOnly = !currentBooking.booking_token;
						emailInput.title = currentBooking.booking_token
							? ''
							: __t('email_requires_token', 'Open the portal from your confirmation email link to change your email address.');
					}
					var arrivalInput = document.getElementById('mhbo-profile-arrival');
					if (arrivalInput) arrivalInput.value = currentBooking.arrival_time || '';
					var reqInput = document.getElementById('mhbo-profile-requests');
					if (reqInput) reqInput.value = currentBooking.special_requests || '';
				}
				editProfModal.style.display = 'flex';
				var initialFocus = document.getElementById('mhbo-profile-name') || editProfClose;
				if (initialFocus) initialFocus.focus();
			});
		}
		if (editProfClose && editProfModal) {
			editProfClose.addEventListener('click', function() {
				clearProfError();
				editProfModal.style.display = 'none';
				if (editProfBtn) editProfBtn.focus();
			});
			editProfModal.addEventListener('click', function(e) {
				if (e.target === editProfModal) {
					clearProfError();
					editProfModal.style.display = 'none';
					if (editProfBtn) editProfBtn.focus();
				}
			});
		}
		if (editProfSubmit && editProfModal) {
			editProfSubmit.addEventListener('click', function() {
				if (!currentBooking) return;
				clearProfError();
				var newName = (document.getElementById('mhbo-profile-name').value || '').trim();
				var newPhone = (document.getElementById('mhbo-profile-phone').value || '').trim();
				var newEmail = (document.getElementById('mhbo-profile-email').value || '').trim();
				var newArrival = (document.getElementById('mhbo-profile-arrival').value || '').trim();
				var newRequests = (document.getElementById('mhbo-profile-requests').value || '').trim();

				if (!newName) {
					showProfError('Please enter your full name.');
					return;
				}
				if (!newEmail) {
					showProfError('Please enter a valid email address.');
					return;
				}

				editProfSubmit.disabled = true;
				editProfSubmit.textContent = __t('submitting', 'Submitting...');

				fetch(restUrl + '/update-profile', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify({
						token: currentBooking.booking_token,
						booking_id: currentBooking.booking_id,
						email: currentBooking.customer_email,
						customer_name: newName,
						customer_phone: newPhone,
						customer_email: newEmail,
						arrival_time: newArrival,
						special_requests: newRequests
					})
				})
				.then(function(res) { return res.json(); })
				.then(function(data) {
					editProfSubmit.disabled = false;
					editProfSubmit.textContent = 'Save Changes';
					if (data && data.success) {
						editProfModal.style.display = 'none';
						if (editProfBtn) editProfBtn.focus();
						showAlert(data.message, true);
						// Update local state and UI immediately
						currentBooking.customer_name = newName;
						currentBooking.customer_phone = newPhone;
						currentBooking.customer_email = newEmail;
						currentBooking.arrival_time = newArrival;
						currentBooking.special_requests = newRequests;
						renderDetails(currentBooking);
					} else {
						showProfError(data && data.message ? data.message : 'Failed to update contact details.');
					}
				})
				.catch(function(err) {
					editProfSubmit.disabled = false;
					editProfSubmit.textContent = 'Save Changes';
					showProfError(__t('network_error', 'Network error while updating details. Please try again.'));
				});
			});
		}

		// Keyboard Accessibility: Escape key dismisses open modals (WCAG 2.2 AA)
		window.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				if (cancelModal && cancelModal.style.display !== 'none') {
					cancelModal.style.display = 'none';
					if (cancelBtn) cancelBtn.focus();
				}
				if (modModal && modModal.style.display !== 'none') {
					clearModError();
					resetModPreview();
					modModal.style.display = 'none';
					if (modBtn) modBtn.focus();
				}
				if (editProfModal && editProfModal.style.display !== 'none') {
					clearProfError();
					editProfModal.style.display = 'none';
					if (editProfBtn) editProfBtn.focus();
				}
				if (calMenu && calMenu.style.display !== 'none') {
					calMenu.style.display = 'none';
				}
			}
		});

		// Automatic initialization from URL attributes or session storage
		var initialToken = root.getAttribute('data-token') || '';
		var initialId = root.getAttribute('data-id') || '';
		var initialEmail = root.getAttribute('data-email') || '';

		if (!initialToken && window.location.search) {
			try {
				var searchParams = new URLSearchParams(window.location.search);
				initialToken = searchParams.get('token') || searchParams.get('booking_token') || '';
				if (!initialId) initialId = searchParams.get('booking_id') || '';
				if (!initialEmail) initialEmail = searchParams.get('email') || '';
			} catch (e) {}
		}

		if (initialToken) {
			fetchBooking({ token: initialToken });
		} else if (initialId && initialEmail) {
			fetchBooking({ booking_id: initialId, email: initialEmail });
		} else {
			try {
				var storedToken = sessionStorage.getItem('mhbo_portal_token');
				if (storedToken) {
					fetchBooking({ token: storedToken });
				}
			} catch(e) {}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initGuestPortal);
	} else {
		initGuestPortal();
	}
})();
