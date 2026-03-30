(function ($) {
    'use strict';

    // Debug logger
    const debugLog = (function () {
        const isDebug = (typeof mhbo_vars !== 'undefined' && mhbo_vars.debug) ||
            (typeof localStorage !== 'undefined' && localStorage.getItem('mhbo_debug'));
        return isDebug ? console.error.bind(console, '[MHBO]') : function () { };
    })();

    // Wait for DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof mhbo_vars === 'undefined') {
            debugLog('mhbo_vars is not defined.');
        }

        /**
         * Initialize each booking form instance found on the page
         */
        document.querySelectorAll('.mhbo-booking-form-wrapper').forEach(function (wrapper) {
            const form = wrapper.querySelector('.mhbo-booking-form');
            if (!form) return;

            const childrenSelect = wrapper.querySelector('.mhbo-booking-children');
            const agesContainer = wrapper.querySelector('.mhbo-child-ages-container');
            const agesInputs = wrapper.querySelector('.mhbo-child-ages-inputs');
            const guestsSelect = wrapper.querySelector('.mhbo-booking-guests');
            const totalDisplay = wrapper.querySelector('.mhbo-display-total');
            const totalHidden = form.querySelector('input[name="total_price"]');
            const arrivalTotalEl = wrapper.querySelector('.mhbo-arrival-total-price');
            const submitBtn = wrapper.querySelector('.mhbo-submit-btn');
            const consentCheckbox = wrapper.querySelector('.mhbo-consent');
            const errorBox = wrapper.querySelector('.mhbo-booking-errors');

            let debounceTimer;

            /**
             * Recalculate price via REST API
             */
            function recalculatePrice() {
                if (!totalDisplay) return;
                
                clearTimeout(debounceTimer);
                totalDisplay.classList.add('mhbo_faded');
                if (arrivalTotalEl) arrivalTotalEl.classList.add('mhbo_faded');
                if (submitBtn) submitBtn.disabled = true;

                debounceTimer = setTimeout(async function () {
                    window.dispatchEvent(new CustomEvent('mhbo_processing_start', { 
                        detail: { source: 'booking_form', instance: wrapper } 
                    }));

                    try {
                        const roomIdInput = form.querySelector('input[name="mhbo_room_id"]');
                        const roomId = roomIdInput ? roomIdInput.value : 0;
                        const checkInInput = form.querySelector('input[name="check_in"]');
                        const checkIn = checkInInput ? checkInInput.value : '';
                        const checkOutInput = form.querySelector('input[name="check_out"]');
                        const checkOut = checkOutInput ? checkOutInput.value : '';
                        const guests = guestsSelect ? guestsSelect.value : 1;
                        const children = childrenSelect ? childrenSelect.value : 0;

                        const extras = {};
                        form.querySelectorAll('.mhbo-extra-input:checked, .mhbo-extra-quantity-input').forEach(function (input) {
                            let val = 0;
                            if (input.type === 'checkbox') {
                                if (input.checked) val = 1;
                            } else {
                                val = input.value;
                            }
                            
                            if (val) {
                                // Extract key from data-extra-id or name attribute [key]
                                const extraId = input.dataset.extraId;
                                if (extraId) {
                                    extras[extraId] = val;
                                } else {
                                    const match = input.name.match(/\[(.*?)\]/);
                                    if (match) extras[match[1]] = val;
                                }
                            }
                        });

                        const paymentTypeInput = form.querySelector('input[name="mhbo_payment_type"]:checked');
                        const paymentType = paymentTypeInput ? paymentTypeInput.value : 'full';

                        const childrenAges = [];
                        form.querySelectorAll('input[name="child_ages[]"]').forEach(function (input) {
                            childrenAges.push(input.value);
                        });

                        const response = await fetch(mhbo_vars.rest_url + '/recalculate-price', {
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
                                child_ages: childrenAges,
                                extras: extras,
                                mhbo_payment_type: paymentType
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            totalDisplay.textContent = data.total_formatted;
                            if (totalHidden) totalHidden.value = data.total;
                            if (arrivalTotalEl) arrivalTotalEl.textContent = data.total_formatted;

                            const taxContainer = wrapper.querySelector('.mhbo-tax-breakdown-container');
                            if (taxContainer && typeof data.tax_breakdown_html !== 'undefined') {
                                taxContainer.innerHTML = data.tax_breakdown_html;
                            }

                            // Update Payment Cards if deposit info returned
                            if (data.deposit_amount_formatted) {
                                const depositCard = form.querySelector('.mhbo-payment-card[data-payment-type="deposit"]');
                                if (depositCard) {
                                    const amountEl = depositCard.querySelector('.mhbo-deposit-amount');
                                    const balanceEl = depositCard.querySelector('.mhbo-deposit-balance-display');
                                    if (amountEl) amountEl.textContent = data.deposit_amount_formatted;
                                    if (balanceEl) balanceEl.textContent = data.remaining_balance_formatted;
                                    
                                    // Update data attributes for gateways
                                    depositCard.dataset.amount = data.deposit_amount;
                                    depositCard.dataset.balance = data.remaining_balance;
                                }
                                
                                const fullCard = form.querySelector('.mhbo-payment-card[data-payment-type="full"]');
                                if (fullCard) {
                                    const fullAmountEl = fullCard.querySelector('.mhbo-full-amount');
                                    if (fullAmountEl) fullAmountEl.textContent = data.total_formatted;
                                    fullCard.dataset.amount = data.total;
                                }
                            }

                            // Toggle deposit/balance rows based on selection
                            updateDepositVisibility();

                            window.dispatchEvent(new CustomEvent('mhbo_price_updated', { 
                                detail: { data: data, instance: wrapper } 
                            }));

                            // Sync Stripe intent if active
                            if (typeof window.mhboRefreshStripeIntent === 'function') {
                                window.mhboRefreshStripeIntent();
                            }
                        } else {
                            debugLog('Recalculate failed:', data.message);
                        }
                    } catch (err) {
                        debugLog('Recalculate error:', err);
                    } finally {
                        totalDisplay.classList.remove('mhbo_faded');
                        if (arrivalTotalEl) arrivalTotalEl.classList.remove('mhbo_faded');
                        
                        window.dispatchEvent(new CustomEvent('mhbo_processing_end', { 
                            detail: { source: 'booking_form', instance: wrapper } 
                        }));

                        updateSubmitState();
                    }
                }, 400);
            }

            /**
             * Update visibility of deposit-related summary rows
             */
            function updateDepositVisibility() {
                const paymentType = form.querySelector('input[name="mhbo_payment_type"]:checked');
                const isDeposit = paymentType && paymentType.value === 'deposit';
                
                wrapper.querySelectorAll('.mhbo-deposit-amount-row, .mhbo-remaining-balance-row').forEach(row => {
                    row.style.display = isDeposit ? 'table-row' : 'none';
                });
            }

            /**
             * GDPR Consent Enforcement & Button State
             */
            function updateSubmitState() {
                if (!submitBtn) return;
                
                let isAllowed = true;
                if (consentCheckbox) {
                    isAllowed = consentCheckbox.checked;
                }
                
                submitBtn.disabled = !isAllowed;
                submitBtn.style.opacity = isAllowed ? '1' : '0.5';
                submitBtn.style.cursor = isAllowed ? 'pointer' : 'not-allowed';
            }

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

                $(wrapper).on('change', '.mhbo-child-age-input', recalculatePrice);
            }

            if (guestsSelect) guestsSelect.addEventListener('change', recalculatePrice);
            $(wrapper).on('change', 'input[name^="mhbo_extras"]', recalculatePrice);

            // Payment Type Change
            $(wrapper).on('change', 'input[name="mhbo_payment_type"]', function() {
                // UI feedback
                wrapper.querySelectorAll('.mhbo-payment-card').forEach(c => c.classList.remove('active'));
                const card = this.closest('.mhbo-payment-card');
                if (card) card.classList.add('active');
                
                recalculatePrice();
            });

            if (consentCheckbox) {
                consentCheckbox.addEventListener('change', updateSubmitState);
                updateSubmitState();
            } else {
                updateSubmitState();
            }

            // Initial visibility
            updateDepositVisibility();

            /**
             * Form submission validation
             */
            form.addEventListener('submit', function (e) {
                if (errorBox) errorBox.style.display = 'none';

                if (consentCheckbox && !consentCheckbox.checked) {
                    e.preventDefault();
                    showInstanceError(mhbo_vars.msg_gdpr_required || 'Please accept the privacy policy to continue.');
                    return;
                }

                const method = form.querySelector('input[name="mhbo_payment_method"]:checked');
                const methodValue = method ? method.value : '';

                if (methodValue === 'stripe') return; // Handled by PaymentGateways.js

                if (methodValue === 'paypal') {
                    const paypalOrderInput = form.querySelector('input[name="mhbo_paypal_order_id"]');
                    if (!paypalOrderInput || !paypalOrderInput.value) {
                        e.preventDefault();
                        showInstanceError(mhbo_vars.msg_paypal_required || 'Please use the PayPal button to complete payment.');
                        return;
                    }
                }
            });

            function showInstanceError(message) {
                if (errorBox) {
                    errorBox.innerHTML = '<div class="mhbo-error-notification"><span class="mhbo-error-icon">⚠️</span> ' + message + '</div>';
                    errorBox.style.display = 'block';
                    errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    alert(message);
                }
            }
        });
    });
})(jQuery);
