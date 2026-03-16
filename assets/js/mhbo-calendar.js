jQuery(document).ready(function ($) {

    // Debug logger - only logs when mhbo_calendar.debug is true or localStorage flag is set
    const debugLog = (function () {
        const isDebug = (typeof mhbo_calendar !== 'undefined' && mhbo_calendar.debug) ||
            (typeof localStorage !== 'undefined' && localStorage.getItem('mhbo_debug'));
        return isDebug ? console.error.bind(console, '[MHBO]') : function () { };
    })();

    function initAllCalendars() {
        $('.mhbo-calendar-container').each(function () {
            const $wrapper = $(this);
            
            // Double-initialization protection (2026 best practice)
            if ($wrapper.data('mhbo-initialized')) return;
            $wrapper.data('mhbo-initialized', true);
            $wrapper.addClass('mhbo-initialized');

            const roomId = $wrapper.data('room-id');
            const $selectionBox = $wrapper.find('.mhbo-selection-box');
            const $guide = $wrapper.find('.mhbo-calendar-guide');
            const $inlineContainer = $wrapper.find('.mhbo-calendar-inline');
            const $errorBox = $wrapper.find('.mhbo-calendar-errors');
            const showPrice = String($wrapper.data('show-price')) === '1';

            let picker = null;
            let disabledDates = [];
            let priceData = {};
            let bookingStatusData = {}; // Track booking status per date
            let changeoverData = {}; // Track changeover status (checkin/checkout/both)

            function showInlineError(message) {
                if ($errorBox.length) {
                    $errorBox.text(message).addClass('mhbo-visible').fadeIn();
                    // Auto-hide after 5s
                    setTimeout(() => {
                        $errorBox.fadeOut(() => {
                            $errorBox.removeClass('mhbo-visible').text('');
                        });
                    }, 5000);
                } else {
                    console.error('[MHBO] Validation Error:', message);
                }
            }

            function initFlatpickr() {
                // Explicitly ensure hidden states on init (defense-in-depth)
                $selectionBox.removeClass('mhbo-visible').hide();
                $errorBox.removeClass('mhbo-visible').hide();

                $.ajax({
                    url: mhbo_calendar.rest_url,
                    method: 'GET',
                    data: {
                        room_id: roomId,
                        year: new Date().getFullYear(),
                        month: new Date().getMonth() + 1
                    },
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', mhbo_calendar.nonce);
                    },
                    success: function (data) {
                        if (data && Array.isArray(data)) {
                            processCalendarData(data);
                            renderFlatpickr();
                        }
                    },
                    error: function (xhr) {
                        debugLog('REST Error:', xhr.responseText);
                    }
                });
            }

            function processCalendarData(data) {
                data.forEach(item => {
                    if (item.status === 'booked' || item.price === 0 || item.price === '0' || !item.price) {
                        disabledDates.push(item.date);
                    }
                    priceData[item.date] = {
                        price: item.price,
                        formatted: item.price_formatted
                    };
                    // Store booking status (pending/confirmed) for color coding
                    if (item.booking_status) {
                        bookingStatusData[item.date] = item.booking_status;
                    }
                    // Determine changeover status for split-day styling
                    if (item.is_checkin && item.is_checkout) {
                        changeoverData[item.date] = 'both';
                    } else if (item.is_checkin) {
                        changeoverData[item.date] = 'checkin';
                    } else if (item.is_checkout) {
                        changeoverData[item.date] = 'checkout';
                    }
                });
            }

            function renderFlatpickr() {
                if (!$inlineContainer.length) return;

                // Determine locale dynamically based on loaded scripts
                // Fallback to English if the requested locale isn't loaded
                let locale = 'en';
                if (mhbo_calendar.current_lang && typeof flatpickr !== 'undefined' && flatpickr.l10ns && flatpickr.l10ns[mhbo_calendar.current_lang]) {
                    locale = mhbo_calendar.current_lang;
                }

                picker = flatpickr($inlineContainer[0], {
                    inline: true,
                    mode: "range",
                    minDate: "today",
                    maxDate: new Date().fp_incr(365), // Limit selectable dates to 1 year in the future
                    dateFormat: "Y-m-d",
                    disable: disabledDates,
                    disableMobile: true,
                    showMonths: 1,
                    locale: locale,
                    onChange: function (selectedDates, dateStr, instance) {
                        // Clear errors on change
                        $errorBox.hide();

                        // dynamically allow the next booked date to be a checkout date
                        if (selectedDates.length === 1) {
                            const checkIn = selectedDates[0];
                            let firstBookedAfter = null;
                            let minDiff = Infinity;

                            // Find the first disabled date that occurs AFTER the check-in date
                            for (let i = 0; i < disabledDates.length; i++) {
                                // Prevent timezone shift issues
                                const bdParts = disabledDates[i].split('-');
                                const bd = new Date(bdParts[0], bdParts[1] - 1, bdParts[2]);

                                if (bd > checkIn) {
                                    const diff = bd - checkIn;
                                    if (diff < minDiff) {
                                        minDiff = diff;
                                        firstBookedAfter = disabledDates[i];
                                    }
                                }
                            }

                            // Enable the first booked date AFTER check-in so it can be checked out of
                            // AND restrict maxDate to prevent "jumping over" existing bookings
                            let newDisabled = [...disabledDates];
                            if (firstBookedAfter) {
                                newDisabled = newDisabled.filter(d => d !== firstBookedAfter);
                                instance.set('maxDate', firstBookedAfter);
                            } else {
                                instance.set('maxDate', new Date().fp_incr(365));
                            }

                            // Only update if changed to avoid unnecessary re-renders
                            if (JSON.stringify(instance.config.disable) !== JSON.stringify(newDisabled)) {
                                instance.set('disable', newDisabled);
                            }
                        }
                        else if (selectedDates.length === 2) {
                            // Reset maxDate limit once range is selected or auto-advanced
                            instance.set('maxDate', new Date().fp_incr(365));

                            // If both dates are the same, auto-advance checkout to the next day (min 1 night)
                            // We allow the next day even if it's "booked" because it's a valid check-out.
                            if (selectedDates[0].getTime() === selectedDates[1].getTime()) {
                                var nextDay = new Date(selectedDates[0]);
                                nextDay.setDate(nextDay.getDate() + 1);

                                // Silently update visual selection
                                instance.setDate([selectedDates[0], nextDay], false);

                                // Keep checkout date un-disabled
                                let checkOutStr = instance.formatDate(nextDay, "Y-m-d");
                                let newDisabledFinal = disabledDates.filter(d => d !== checkOutStr);
                                instance.set('disable', newDisabledFinal);

                                updateBookingForm([selectedDates[0], nextDay]);
                                return;
                            }

                            // Keep the checkout date un-disabled if it was booked, so flatpickr doesn't erase it
                            let checkOutStr2 = instance.formatDate(selectedDates[1], "Y-m-d");
                            let newDisabledFinal2 = disabledDates.filter(d => d !== checkOutStr2);
                            instance.set('disable', newDisabledFinal2);

                            updateBookingForm(selectedDates);
                        }
                        else {
                            // length 0 or cleared
                            instance.set('maxDate', new Date().fp_incr(365));
                            instance.set('disable', disabledDates);
                            $selectionBox.removeClass('mhbo-visible').hide();
                            $guide.text(mhbo_calendar.i18n.select_checkout || 'Now select your check-out date');
                        }
                    },
                    onDayCreate: function (dObj, dStr, fp, dayElem) {
                        const date = fp.formatDate(dayElem.dateObj, "Y-m-d");
                        const dayNum = dayElem.dateObj.getDate();
                        dayElem.innerHTML = '';
                        const $num = $('<span class="mhbo-day-number">' + dayNum + '</span>');
                        $(dayElem).append($num);

                        // Apply half-day styling for checkin/checkout changeover dates
                        if (changeoverData[date]) {
                            $(dayElem).addClass('mhbo-half-booked-' + changeoverData[date]);
                        }

                        // Apply booking status CSS class for color coding
                        // Allow styling for disabled dates OR changeover dates (even if enabled/selectable)
                        if (bookingStatusData[date] && (dayElem.classList.contains('flatpickr-disabled') || changeoverData[date])) {
                            const today = new Date();
                            today.setHours(0, 0, 0, 0);
                            // Only apply status color if the date is not in the past
                            if (dayElem.dateObj >= today) {
                                $(dayElem).addClass('mhbo-booking-' + bookingStatusData[date]);
                            }
                        }

                        // Only render prices for room-specific calendars (not aggregated views).
                        if (showPrice && priceData[date] && !dayElem.classList.contains('flatpickr-disabled')) {
                            // Insert a space between number and currency for better wrapping on small screens
                            const priceText = priceData[date].formatted.replace(/(\d)([^\d\s.,])/, '$1 $2');
                            const $priceTag = $('<span class="mhbo-fp-price">' + priceText + '</span>');
                            $(dayElem).append($priceTag);
                            $(dayElem).addClass('has-price');
                        }
                    }
                });

                // ATTACH INSTANCE TO WRAPPER (CRITICAL FOR HARDENING)
                $wrapper.data('mhbo-picker', picker);
            }

            function updateBookingForm(dates) {
                const start = dates[0];
                const end = dates[1];
                const startStr = picker.formatDate(start, "Y-m-d");
                const endStr = picker.formatDate(end, "Y-m-d");

                $wrapper.find('.mhbo-cal-check-in').val(startStr);
                $wrapper.find('.mhbo-cal-check-out').val(endStr);
                $wrapper.find('.mhbo-display-check-in').text(startStr);
                $wrapper.find('.mhbo-display-check-out').text(endStr);

                // CROSS-SYNC: If we are on the booking form page already, find the main form inputs too
                const $mainForm = $('#mhbo-booking-form');
                if ($mainForm.length) {
                    $mainForm.find('input[name="check_in"]').val(startStr);
                    $mainForm.find('input[name="check_out"]').val(endStr);
                    // Trigger change to update prices if mhbo-booking-form.js is active
                    $mainForm.find('input[name="check_in"]').trigger('change');
                }

                // Update summary box labels if they have translation tags
                if ($selectionBox.find('.mhbo-selection-header h3').length) {
                    $selectionBox.find('.mhbo-selection-header h3').text(mhbo_calendar.i18n.your_selection || 'Your Selection');
                }
                const $dateLabels = $selectionBox.find('.mhbo-selection-dates .mhbo-label');
                if ($dateLabels.length >= 2) {
                    $($dateLabels[0]).text(mhbo_calendar.i18n.check_in || 'Check-in');
                    $($dateLabels[1]).text(mhbo_calendar.i18n.check_out || 'Check-out');
                }

                // Update button text if translation is available
                const $submitBtn = $wrapper.find('.mhbo-booking-btn-submit');
                if ($submitBtn.length && mhbo_calendar.i18n.continue_booking) {
                    $submitBtn.text(mhbo_calendar.i18n.continue_booking);
                }

                // Update pricing summary only for room-specific calendars
                if (showPrice && typeof calculateTotalPrice === 'function') {
                    $selectionBox.find('.mhbo-selection-price .mhbo-label').text(mhbo_calendar.i18n.total || 'Total');
                    calculateTotalPrice(start, end);
                }

                $guide.text(mhbo_calendar.i18n.dates_selected || 'Dates selected. Complete the form below.');
                $selectionBox.addClass('mhbo-visible').fadeIn();
            }

            function calculateTotalPrice(start, end) {
                let total = 0;
                let d = new Date(start);
                while (d < end) {
                    const dateStr = picker.formatDate(d, "Y-m-d");
                    if (priceData[dateStr]) {
                        total += parseFloat(priceData[dateStr].price);
                    }
                    d.setDate(d.getDate() + 1);
                }
                const formatted = formatCurrency(total);
                $wrapper.find('.mhbo-display-price').text(formatted);
                $wrapper.find('.mhbo-cal-total-price').val(total);
                
                // Also sync to main form total hidden field if it exists
                const $mainTotal = $('#mhbo-booking-form').find('input[name="total_price"]');
                if ($mainTotal.length) {
                    $mainTotal.val(total);
                }
            }

            function formatCurrency(amount) {
                const pos = mhbo_calendar.settings.currency_pos;
                const symbol = mhbo_calendar.settings.currency_symbol;
                const decimals = (typeof mhbo_calendar.settings.currency_decimals !== 'undefined') ? parseInt(mhbo_calendar.settings.currency_decimals) : 2;
                const formatted = amount.toFixed(decimals);
                return pos === 'before' ? (symbol + formatted) : (formatted + symbol);
            }

            // Expose error handler to wrapper
            $wrapper.data('mhbo-error-handler', showInlineError);

            initFlatpickr();
        });
    }

    // Capture the button click globally for better resilience
    // AND check for double-attachment
    if (!$(document).data('mhbo-click-attached')) {
        $(document).on('click', '.mhbo-booking-btn-submit', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $wrapper = $btn.closest('.mhbo-calendar-container');
            
            // Safety check: if we somehow clicked a button without a wrapper, abort
            if (!$wrapper.length) return;

            const $form = $wrapper.find('.mhbo-selection-form');
            const roomId = $wrapper.data('room-id');
            const action = $form.attr('action');
            const showError = $wrapper.data('mhbo-error-handler') || alert;
            
            // HARDENING: Try Flatpickr instance FIRST (most reliable)
            const picker = $wrapper.data('mhbo-picker');
            let checkIn, checkOut, totalPrice;

            if (picker && picker.selectedDates.length === 2) {
                checkIn = picker.formatDate(picker.selectedDates[0], "Y-m-d");
                checkOut = picker.formatDate(picker.selectedDates[1], "Y-m-d");
                // Get totalPrice from the internal hidden input as it's calculated in updateBookingForm
                totalPrice = $wrapper.find('.mhbo-cal-total-price').val() || '0';
            } else {
                // FALLBACK: Try wrapper hidden inputs
                checkIn = $wrapper.find('.mhbo-cal-check-in').val();
                checkOut = $wrapper.find('.mhbo-cal-check-out').val();
                totalPrice = $wrapper.find('.mhbo-cal-total-price').val() || '0';
            }

            if (!checkIn || !checkOut) {
                showError(mhbo_calendar.i18n.select_dates_error || 'Please select check-in and check-out dates.');
                return;
            }

            // Get nonce from form hidden input (primary) or localized JS object (fallback)
            var autoNonce = $form.find('input[name="mhbo_nonce"]').val() || (typeof mhbo_calendar !== 'undefined' && mhbo_calendar.auto_nonce) || '';

            let finalUrl;
            try {
                const base = window.location.origin;
                const url = (action && action !== '#' && action !== '') ? new URL(action, base) : new URL(window.location.href);

                url.searchParams.set('room_id', roomId);
                url.searchParams.set('check_in', checkIn);
                url.searchParams.set('check_out', checkOut);
                url.searchParams.set('total_price', totalPrice);
                url.searchParams.set('mhbo_auto_book', '1');
                if (autoNonce) {
                    url.searchParams.set('mhbo_nonce', autoNonce);
                }

                finalUrl = url.toString();
            } catch (err) {
                debugLog('Redirect error:', err);
                const sep = (action && action.indexOf('?') !== -1) ? '&' : '?';
                const baseUrl = (action && action !== '#' && action !== '') ? action : window.location.href.split('?')[0];
                finalUrl = baseUrl + sep + 'room_id=' + roomId + '&check_in=' + checkIn + '&check_out=' + checkOut + '&total_price=' + totalPrice + '&mhbo_auto_book=1' + (autoNonce ? '&mhbo_nonce=' + encodeURIComponent(autoNonce) : '');
            }

            window.location.href = finalUrl;
        });
        $(document).data('mhbo-click-attached', true);
    }

    initAllCalendars();
});
