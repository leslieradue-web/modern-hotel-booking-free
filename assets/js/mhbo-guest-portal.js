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
		var alertEl = document.getElementById('mhbo-portal-alert');
		var lookupView = document.getElementById('mhbo-portal-lookup-view');
		var detailsView = document.getElementById('mhbo-portal-details-view');
		var currentBooking = null;

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
			var query = new URLSearchParams(params).toString();
			fetch(restUrl + '/booking?' + query, {
				method: 'GET'
			})
			.then(function(res) {
				return res.json().then(function(data) {
					return { ok: res.ok, status: res.status, data: data };
				}).catch(function() {
					return { ok: res.ok, status: res.status, data: null };
				});
			})
			.then(function(result) {
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
				console.error('MHBO Guest Portal Fetch Error:', err);
				showAlert(__t('network_error', 'Network error while looking up reservation. Please try again.'), false);
			});
		}

		function renderDetails(b) {
			currentBooking = b;

			// Broadcast authenticated guest context to AI Concierge and save in session (Law 65)
			try {
				sessionStorage.setItem('mhbo_portal_token', b.booking_token || '');
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
						'STATUS:CONFIRMED',
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

			// Share / Copy Portal Link
			var shareBtn = document.getElementById('mhbo-qa-share-btn');
			var shareText = document.getElementById('mhbo-qa-share-text');
			if (shareBtn && b.booking_token) {
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
						if (b.balance_remaining > 0.001 && b.group_balance_payment_url) {
							groupPayBtn.href = b.group_balance_payment_url;
							groupPayBtn.style.display = 'inline-flex';
						} else {
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
			if (ciTimeEl) ciTimeEl.textContent = b.check_in_time || '14:00';
			var coTimeEl = document.getElementById('mhbo-res-checkout-time');
			if (coTimeEl) coTimeEl.textContent = b.check_out_time || '11:00';
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
			var depositRow = document.getElementById('mhbo-res-deposit-row');
			var depositValEl = document.getElementById('mhbo-res-deposit-val');
			var depositNoteEl = document.getElementById('mhbo-res-deposit-status-note');
			if (depositRow && b.deposit_amount > 0.001) {
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
				settledBadge.style.display = (b.balance_remaining <= 0.001 && b.total_paid > 0.001) ? 'block' : 'none';
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

			// Promotional Coupon Breakdown
			var couponRow = document.getElementById('mhbo-res-coupon-row');
			var couponCodeEl = document.getElementById('mhbo-res-coupon-code');
			var couponDiscountEl = document.getElementById('mhbo-res-coupon-discount');
			if (b.coupon_code && b.coupon_discount_formatted) {
				if (couponCodeEl) couponCodeEl.textContent = b.coupon_code.toUpperCase();
				if (couponDiscountEl) couponDiscountEl.textContent = '-' + b.coupon_discount_formatted;
				if (couponRow) couponRow.style.display = 'flex';
			} else if (couponRow) {
				couponRow.style.display = 'none';
			}

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

			// Badges
			var statusBadge = document.getElementById('mhbo-res-status-badge');
			if (statusBadge) {
				statusBadge.textContent = b.status_label || b.status;
				statusBadge.className = 'mhbo-portal-badge mhbo-badge-' + (b.status || 'pending');
			}

			var paymentBadge = document.getElementById('mhbo-res-payment-badge');
			if (paymentBadge) {
				paymentBadge.textContent = b.payment_status_label || b.payment_status;
				paymentBadge.className = 'mhbo-portal-badge mhbo-badge-paid';
			}

			// Balance payment link
			var balanceCta = document.getElementById('mhbo-res-balance-cta');
			var balanceBtn = document.getElementById('mhbo-res-balance-btn');
			if (balanceCta && balanceBtn) {
				if (b.balance_remaining > 0.001 && b.balance_payment_url) {
					balanceBtn.href = b.balance_payment_url;
					balanceCta.style.display = 'block';
				} else {
					balanceCta.style.display = 'none';
				}
			}

			// Cancellation buttons & modes
			var cancelBtn = document.getElementById('mhbo-portal-cancel-btn');
			var cancelBtnText = document.getElementById('mhbo-cancel-btn-text');

			if (cancelBtn) {
				if (b.cancellation_requires_staff && b.cancellation_request_allowed) {
					cancelBtn.style.display = 'inline-flex';
					if (cancelBtnText) cancelBtnText.textContent = 'Request Cancellation';
				} else if (b.cancellation_allowed) {
					cancelBtn.style.display = 'inline-flex';
					if (cancelBtnText) cancelBtnText.textContent = 'Cancel Reservation';
				} else {
					cancelBtn.style.display = 'none';
				}
			}

			// Stay modification button
			var modifyBtn = document.getElementById('mhbo-portal-modify-btn');
			if (modifyBtn) {
				modifyBtn.style.display = b.modification_allowed ? 'inline-flex' : 'none';
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
		var cancelPaidAmount = document.getElementById('mhbo-cancel-paid-amount');

		if (cancelBtn && cancelModal) {
			cancelBtn.addEventListener('click', function() {
				if (!currentBooking) return;
				var noticeEl = document.getElementById('mhbo-cancel-policy-notice');
				if (noticeEl) noticeEl.textContent = currentBooking.cancellation_policy_notice || '';
				
				if (currentBooking.cancellation_requires_staff) {
					if (cancelTitle) cancelTitle.textContent = 'Request Cancellation';
					if (cancelConfirm) cancelConfirm.textContent = 'Submit Cancellation Request';
					if (cancelPaidAmount) cancelPaidAmount.textContent = currentBooking.total_paid_formatted;
					if (cancelPaidWarning) cancelPaidWarning.style.display = 'block';
				} else {
					if (cancelTitle) cancelTitle.textContent = 'Cancel Reservation';
					if (cancelConfirm) cancelConfirm.textContent = __t('confirm_cancellation', 'Confirm Cancellation');
					if (cancelPaidWarning) cancelPaidWarning.style.display = 'none';
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
				fetch(restUrl + '/cancel', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify({
						token: currentBooking.booking_token,
						booking_id: currentBooking.booking_id,
						email: currentBooking.customer_email,
						reason: reason
					})
				})
				.then(function(res) { return res.json(); })
				.then(function(data) {
					cancelConfirm.disabled = false;
					cancelConfirm.textContent = currentBooking && currentBooking.cancellation_requires_staff ? 'Submit Cancellation Request' : __t('confirm_cancellation', 'Confirm Cancellation');
					cancelModal.style.display = 'none';
					if (cancelBtn) cancelBtn.focus();
					if (data && data.success) {
						showAlert(data.message, true);
						fetchBooking({ token: currentBooking.booking_token });
					} else {
						showAlert(data.message || __t('cancel_failed', 'Failed to process cancellation request.'), false);
					}
				})
				.catch(function(err) {
					cancelConfirm.disabled = false;
					cancelConfirm.textContent = currentBooking && currentBooking.cancellation_requires_staff ? 'Submit Cancellation Request' : __t('confirm_cancellation', 'Confirm Cancellation');
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

		function showModError(msg) {
			if (!modError) return;
			modError.textContent = msg;
			modError.style.display = 'block';
		}

		function clearModError() {
			if (modError) {
				modError.textContent = '';
				modError.style.display = 'none';
			}
		}

		if (modBtn && modModal) {
			modBtn.addEventListener('click', function() {
				clearModError();
				if (currentBooking) {
					var ciInput = document.getElementById('mhbo-mod-checkin');
					if (ciInput) ciInput.value = currentBooking.check_in || '';
					var coInput = document.getElementById('mhbo-mod-checkout');
					if (coInput) coInput.value = currentBooking.check_out || '';
					var adultsInput = document.getElementById('mhbo-mod-adults');
					if (adultsInput) adultsInput.value = currentBooking.guests || 1;
					var childrenInput = document.getElementById('mhbo-mod-children');
					if (childrenInput) childrenInput.value = currentBooking.children || 0;
					
					if (modPaidInfo) {
						modPaidInfo.style.display = (currentBooking.total_paid > 0.001) ? 'block' : 'none';
					}
					if (modSubmit) {
						modSubmit.textContent = (currentBooking.total_paid > 0.001) ? 'Submit Modification Request' : 'Update Reservation';
					}
				}
				modModal.style.display = 'flex';
				var initialInput = document.getElementById('mhbo-mod-checkin') || modClose;
				if (initialInput) initialInput.focus();
			});
		}
		if (modClose && modModal) {
			modClose.addEventListener('click', function() {
				clearModError();
				modModal.style.display = 'none';
				if (modBtn) modBtn.focus();
			});
			modModal.addEventListener('click', function(e) {
				if (e.target === modModal) {
					clearModError();
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
					showModError('Please choose both check-in and check-out dates.');
					return;
				}
				if (newCheckIn >= newCheckOut) {
					showModError('Check-out date must be after check-in date.');
					return;
				}

				modSubmit.disabled = true;
				modSubmit.textContent = __t('submitting', 'Submitting...');

				fetch(restUrl + '/update-stay', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
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
						notes: notes
					})
				})
				.then(function(res) { return res.json(); })
				.then(function(data) {
					modSubmit.disabled = false;
					modSubmit.textContent = (currentBooking.total_paid > 0.001) ? 'Submit Modification Request' : 'Update Reservation';
					if (data && data.success) {
						modModal.style.display = 'none';
						if (modBtn) modBtn.focus();
						showAlert(data.message, true);
						fetchBooking({ token: currentBooking.booking_token });
					} else {
						showModError(data && data.message ? data.message : __t('modify_failed', 'Failed to update stay.'));
					}
				})
				.catch(function(err) {
					modSubmit.disabled = false;
					modSubmit.textContent = (currentBooking.total_paid > 0.001) ? 'Submit Modification Request' : 'Update Reservation';
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
					if (emailInput) emailInput.value = currentBooking.customer_email || '';
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
