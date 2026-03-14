/**
 * Modern Hotel Booking - Booking Form
 * 
 * Handles frontend booking form interactions including:
 * - Child ages dynamic inputs
 * - Price recalculation via REST API
 * - GDPR consent enforcement
 * - Form submission validation
 * 
 * @package MHBO
 * @since 2.0.1
 */

(function ($) {
    'use strict';

    // Debug logger - only logs when mhbo_vars.debug is true or localStorage flag is set
    const debugLog = (function () {
        const isDebug = (typeof mhbo_vars !== 'undefined' && mhbo_vars.debug) ||
            (typeof localStorage !== 'undefined' && localStorage.getItem('mhbo_debug'));
        return isDebug ? console.error.bind(console, '[MHBO]') : function () { };
    })();

    // Wait for DOMContentLoaded to ensure mhbo_vars is defined
    document.addEventListener('DOMContentLoaded', function () {

        // Safety check for mhbo_vars
        if (typeof mhbo_vars === 'undefined') {
            debugLog('mhbo_vars is not defined. Payment processing may not work.');
            // Don't return - continue to set up other handlers
        }

        const childrenSelect = document.getElementById('mhbo-booking-children');
        const agesContainer = document.getElementById('mhbo-child-ages-container');
        const agesInputs = document.getElementById('mhbo-child-ages-inputs');
        const guestsSelect = document.getElementById('mhbo-booking-guests');
        const totalDisplay = document.getElementById('mhbo-display-total');
        const totalHidden = document.querySelector('input[name="total_price"]');
        const arrivalTotal = document.querySelector('#mhbo-arrival-container strong');

        /**
         * Format currency based on settings
         */
        function formatCurrency(price) {
            if (mhbo_vars.currency_pos === 'before') {
                return mhbo_vars.currency_symbol + price.toFixed(0);
            }
            return price.toFixed(0) + mhbo_vars.currency_symbol;
        }

        const submitBtn = document.getElementById('mhbo-submit-btn');

        let debounceTimer;

        /**
         * Recalculate price via REST API
         */
        function recalculatePrice() {
            clearTimeout(debounceTimer);

            // Visual loading state
            totalDisplay.classList.add('mhbo_faded');
            const arrivalTotalEl = document.getElementById('mhbo-arrival-total-price');
            if (arrivalTotalEl) arrivalTotalEl.classList.add('mhbo_faded');

            if (submitBtn) submitBtn.disabled = true;

            debounceTimer = setTimeout(function () {
                const roomId = document.querySelector('input[name="mhbo_room_id"]').value;
                const checkIn = document.querySelector('input[name="check_in"]').value;
                const checkOut = document.querySelector('input[name="check_out"]').value;
                const guests = guestsSelect.value;
                const children = childrenSelect ? childrenSelect.value : 0;

                const childrenAges = [];
                document.querySelectorAll('input[name="child_ages[]"]').forEach(function (input) {
                    childrenAges.push(input.value);
                });

                const extras = {};
                document.querySelectorAll('input[name^="mhbo_extras"]').forEach(function (input) {
                    if (input.type === 'checkbox') {
                        if (input.checked) extras[input.name.match(/\[(.*?)\]/)[1]] = 1;
                    } else {
                        extras[input.name.match(/\[(.*?)\]/)[1]] = input.value;
                    }
                });

                fetch(mhbo_vars.rest_url + '/recalculate-price', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': mhbo_vars.nonce
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

                            const taxContainer = document.getElementById('mhbo-tax-breakdown-container');
                            if (taxContainer && typeof data.tax_breakdown_html !== 'undefined') {
                                taxContainer.innerHTML = data.tax_breakdown_html;
                            }
                        }
                    })
                    .finally(function () {
                        totalDisplay.classList.remove('mhbo_faded');
                        if (arrivalTotalEl) arrivalTotalEl.classList.remove('mhbo_faded');
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
                        let label = mhbo_vars.label_child_n_age.replace('%d', (i + 1));
                        html += '<div class="mhbo-child-age-group">' +
                            '<label>' + label + ' <span class="required">*</span></label>' +
                            '<input type="number" name="child_ages[]" value="0" min="0" max="17" required class="mhbo-child-age-input">' +
                            '</div>';
                    }
                    agesInputs.innerHTML = html;
                } else {
                    agesContainer.style.display = 'none';
                    agesInputs.innerHTML = '';
                }
                recalculatePrice();
            });

            $(document).on('change', '.mhbo-child-age-input', recalculatePrice);
        }

        if (guestsSelect) guestsSelect.addEventListener('change', recalculatePrice);
        $(document).on('change', 'input[name^="mhbo_extras"]', recalculatePrice);

        /**
         * GDPR Consent Enforcement
         */
        const consentCheckbox = document.getElementById('mhbo-consent');

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
            var errBox = document.getElementById('mhbo-booking-errors');
            if (errBox) {
                errBox.innerHTML = '<div class="mhbo-error-notification"><span class="mhbo-error-icon">⚠️</span> ' + message + '<button type="button" class="mhbo-error-close" onclick="this.parentNode.parentNode.style.display=\'none\'">&times;</button></div>';
                errBox.style.display = 'block';
                errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                alert(message);
            }
        }

        // Expose globally for PaymentGateways.php JS
        window.mhboShowBookingError = showBookingError;

        /**
         * Form submission handler - validate payment method from PaymentGateways.php
         */
        const bookingForm = document.getElementById('mhbo-booking-form');
        if (bookingForm) {
            bookingForm.addEventListener('submit', function (e) {
                // Clear previous errors
                var errBox = document.getElementById('mhbo-booking-errors');
                if (errBox) errBox.style.display = 'none';

                // Final GDPR check
                if (consentCheckbox && !consentCheckbox.checked) {
                    e.preventDefault();
                    showBookingError(mhbo_vars.msg_gdpr_required || 'Please accept the privacy policy to continue.');
                    return;
                }

                // Check payment method from PaymentGateways.php rendered selector
                var method = document.querySelector('input[name="mhbo_payment_method"]:checked');
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
                    var paypalOrderInput = document.querySelector('input[name="mhbo_paypal_order_id"]');
                    var paypalOrderId = paypalOrderInput ? paypalOrderInput.value : '';

                    if (!paypalOrderId) {
                        e.preventDefault();
                        showBookingError(mhbo_vars.msg_paypal_required || 'Please use the PayPal button to complete your payment.');
                        return;
                    }
                }
            });
        }
    }); // End DOMContentLoaded
})(jQuery);
