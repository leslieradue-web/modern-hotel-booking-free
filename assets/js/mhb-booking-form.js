/**
 * Modern Hotel Booking - Booking Form
 * 
 * Handles frontend booking form interactions including:
 * - Child ages dynamic inputs
 * - Price recalculation via REST API
 * - GDPR consent enforcement
 * - Form submission validation
 * 
 * @package MHB
 * @since 2.0.1
 */

(function ($) {
    'use strict';

    // Debug logger - only logs when mhb_vars.debug is true or localStorage flag is set
    const debugLog = (function () {
        const isDebug = (typeof mhb_vars !== 'undefined' && mhb_vars.debug) ||
            (typeof localStorage !== 'undefined' && localStorage.getItem('mhb_debug'));
        return isDebug ? console.error.bind(console, '[MHB]') : function () { };
    })();

    // Wait for DOMContentLoaded to ensure mhb_vars is defined
    document.addEventListener('DOMContentLoaded', function () {

        // Safety check for mhb_vars
        if (typeof mhb_vars === 'undefined') {
            debugLog('mhb_vars is not defined. Payment processing may not work.');
            // Don't return - continue to set up other handlers
        }

        const childrenSelect = document.getElementById('mhb-booking-children');
        const agesContainer = document.getElementById('mhb-child-ages-container');
        const agesInputs = document.getElementById('mhb-child-ages-inputs');
        const guestsSelect = document.getElementById('mhb-booking-guests');
        const totalDisplay = document.getElementById('mhb-display-total');
        const totalHidden = document.querySelector('input[name="total_price"]');
        const arrivalTotal = document.querySelector('#mhb-arrival-container strong');

        /**
         * Format currency based on settings
         */
        function formatCurrency(price) {
            if (mhb_vars.currency_pos === 'before') {
                return mhb_vars.currency_symbol + price.toFixed(0);
            }
            return price.toFixed(0) + mhb_vars.currency_symbol;
        }

        const submitBtn = document.getElementById('mhb-submit-btn');

        let debounceTimer;

        /**
         * Recalculate price via REST API
         */
        function recalculatePrice() {
            clearTimeout(debounceTimer);

            // Visual loading state
            totalDisplay.classList.add('mhb_faded');
            const arrivalTotalEl = document.getElementById('mhb-arrival-total-price');
            if (arrivalTotalEl) arrivalTotalEl.classList.add('mhb_faded');

            if (submitBtn) submitBtn.disabled = true;

            debounceTimer = setTimeout(function () {
                const roomId = document.querySelector('input[name="room_id"]').value;
                const checkIn = document.querySelector('input[name="check_in"]').value;
                const checkOut = document.querySelector('input[name="check_out"]').value;
                const guests = guestsSelect.value;
                const children = childrenSelect ? childrenSelect.value : 0;

                const childrenAges = [];
                document.querySelectorAll('input[name="child_ages[]"]').forEach(function (input) {
                    childrenAges.push(input.value);
                });

                const extras = {};
                document.querySelectorAll('input[name^="mhb_extras"]').forEach(function (input) {
                    if (input.type === 'checkbox') {
                        if (input.checked) extras[input.name.match(/\[(.*?)\]/)[1]] = 1;
                    } else {
                        extras[input.name.match(/\[(.*?)\]/)[1]] = input.value;
                    }
                });

                fetch(mhb_vars.rest_url + '/recalculate-price', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': mhb_vars.nonce
                    },
                    body: JSON.stringify({
                        room_id: roomId,
                        check_in: checkIn,
                        check_out: checkOut,
                        guests: guests,
                        children: children,
                        children_ages: childrenAges,
                        extras: extras
                    })
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data.success) {
                            totalDisplay.textContent = data.total_formatted;
                            totalHidden.value = data.total;
                            if (arrivalTotalEl) arrivalTotalEl.textContent = data.total_formatted;

                            const taxContainer = document.getElementById('mhb-tax-breakdown-container');
                            if (taxContainer && typeof data.tax_breakdown_html !== 'undefined') {
                                taxContainer.innerHTML = data.tax_breakdown_html;
                            }
                        }
                    })
                    .finally(function () {
                        totalDisplay.classList.remove('mhb_faded');
                        if (arrivalTotalEl) arrivalTotalEl.classList.remove('mhb_faded');
                        if (submitBtn) submitBtn.disabled = false;
                    });
            }, 400); // 400ms debounce
        }

        /**
         * Child ages dynamic inputs
         */
        if (childrenSelect) {
            childrenSelect.addEventListener('change', function () {
                const count = parseInt(this.value);
                if (count > 0) {
                    agesContainer.style.display = 'block';
                    let html = '';
                    for (let i = 0; i < count; i++) {
                        let label = mhb_vars.label_child_n_age.replace('%d', (i + 1));
                        html += '<div class="mhb-child-age-group">' +
                            '<label>' + label + ' <span class="required">*</span></label>' +
                            '<input type="number" name="child_ages[]" value="0" min="0" max="17" required class="mhb-child-age-input">' +
                            '</div>';
                    }
                    agesInputs.innerHTML = html;
                } else {
                    agesContainer.style.display = 'none';
                    agesInputs.innerHTML = '';
                }
                recalculatePrice();
            });

            $(document).on('change', '.mhb-child-age-input', recalculatePrice);
        }

        if (guestsSelect) guestsSelect.addEventListener('change', recalculatePrice);
        $(document).on('change', 'input[name^="mhb_extras"]', recalculatePrice);

        /**
         * GDPR Consent Enforcement
         */
        const consentCheckbox = document.getElementById('mhb-consent');

        function updateGdprState() {
            if (!consentCheckbox) return;
            const isConsentGiven = consentCheckbox.checked;

            // Block/Unblock Submit Button
            if (submitBtn) {
                submitBtn.disabled = !isConsentGiven;
                submitBtn.style.opacity = isConsentGiven ? '1' : '0.5';
                submitBtn.style.cursor = isConsentGiven ? 'pointer' : 'not-allowed';
            }
        }

        if (consentCheckbox) {
            consentCheckbox.addEventListener('change', updateGdprState);
            // Initial state
            updateGdprState();
        }

        /**
         * Helper: show inline error notification
         */
        function showBookingError(message) {
            var errBox = document.getElementById('mhb-booking-errors');
            if (errBox) {
                errBox.innerHTML = '<div class="mhb-error-notification"><span class="mhb-error-icon">⚠️</span> ' + message + '<button type="button" class="mhb-error-close" onclick="this.parentNode.parentNode.style.display=\'none\'">&times;</button></div>';
                errBox.style.display = 'block';
                errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                alert(message);
            }
        }

        // Expose globally for PaymentGateways.php JS
        window.mhbShowBookingError = showBookingError;

        /**
         * Form submission handler - validate payment method from PaymentGateways.php
         */
        const bookingForm = document.getElementById('mhb-booking-form');
        if (bookingForm) {
            bookingForm.addEventListener('submit', function (e) {
                // Clear previous errors
                var errBox = document.getElementById('mhb-booking-errors');
                if (errBox) errBox.style.display = 'none';

                // Final GDPR check
                if (consentCheckbox && !consentCheckbox.checked) {
                    e.preventDefault();
                    showBookingError(mhb_vars.msg_gdpr_required || 'Please accept the privacy policy to continue.');
                    return;
                }

                // Check payment method from PaymentGateways.php rendered selector
                var method = document.querySelector('input[name="mhb_payment_method"]:checked');
                var methodValue = method ? method.value : '';

                // Stripe payment is handled by PaymentGateways.js - skip validation here
                // The PaymentGateways.js will create payment intent and submit the form
                if (methodValue === 'stripe') {
                    // PaymentGateways.js will handle the form submission
                    // Don't block here - let the other handler process it
                    return;
                }

                // PayPal should only submit after PayPal button flow completes
                if (methodValue === 'paypal') {
                    var paypalOrderInput = document.querySelector('input[name="mhb_paypal_order_id"]');
                    var paypalOrderId = paypalOrderInput ? paypalOrderInput.value : '';

                    if (!paypalOrderId) {
                        e.preventDefault();
                        showBookingError(mhb_vars.msg_paypal_required || 'Please use the PayPal button to complete your payment.');
                        return;
                    }
                }
            });
        }
    }); // End DOMContentLoaded
})(jQuery);