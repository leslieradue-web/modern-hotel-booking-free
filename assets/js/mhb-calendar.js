jQuery(document).ready(function ($) {

    // Debug logger - only logs when mhb_calendar.debug is true or localStorage flag is set
    const debugLog = (function () {
        const isDebug = (typeof mhb_calendar !== 'undefined' && mhb_calendar.debug) ||
            (typeof localStorage !== 'undefined' && localStorage.getItem('mhb_debug'));
        return isDebug ? console.error.bind(console, '[MHB]') : function () { };
    })();

    function initAllCalendars() {
        $('.mhb-calendar-container').each(function () {
            const $wrapper = $(this);
            const roomId = $wrapper.data('room-id');
            const $selectionBox = $wrapper.find('.mhb-selection-box');
            const $guide = $wrapper.find('.mhb-calendar-guide');
            const $inlineContainer = $wrapper.find('.mhb-calendar-inline');
            const showPrice = String($wrapper.data('show-price')) === '1';

            let picker = null;
            let disabledDates = [];
            let priceData = {};
            let bookingStatusData = {}; // Track booking status per date
            let changeoverData = {}; // Track changeover status (checkin/checkout/both)

            function initFlatpickr() {
                $.ajax({
                    url: mhb_calendar.rest_url,
                    method: 'GET',
                    data: {
                        room_id: roomId,
                        year: new Date().getFullYear(),
                        month: new Date().getMonth() + 1
                    },
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', mhb_calendar.nonce);
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
                if (mhb_calendar.current_lang && typeof flatpickr !== 'undefined' && flatpickr.l10ns && flatpickr.l10ns[mhb_calendar.current_lang]) {
                    locale = mhb_calendar.current_lang;
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
                            $selectionBox.hide();
                            $guide.text(mhb_calendar.i18n.select_checkout || 'Now select your check-out date');
                        }
                    },
                    onDayCreate: function (dObj, dStr, fp, dayElem) {
                        const date = fp.formatDate(dayElem.dateObj, "Y-m-d");
                        const dayNum = dayElem.dateObj.getDate();
                        dayElem.innerHTML = '';
                        const $num = $('<span class="mhb-day-number">' + dayNum + '</span>');
                        $(dayElem).append($num);

                        // Apply half-day styling for checkin/checkout changeover dates
                        if (changeoverData[date]) {
                            $(dayElem).addClass('mhb-half-booked-' + changeoverData[date]);
                        }

                        // Apply booking status CSS class for color coding
                        // Allow styling for disabled dates OR changeover dates (even if enabled/selectable)
                        if (bookingStatusData[date] && (dayElem.classList.contains('flatpickr-disabled') || changeoverData[date])) {
                            const today = new Date();
                            today.setHours(0, 0, 0, 0);
                            // Only apply status color if the date is not in the past
                            if (dayElem.dateObj >= today) {
                                $(dayElem).addClass('mhb-booking-' + bookingStatusData[date]);
                            }
                        }

                        // Only render prices for room-specific calendars (not aggregated views).
                        if (showPrice && priceData[date] && !dayElem.classList.contains('flatpickr-disabled')) {
                            // Insert a space between number and currency for better wrapping on small screens
                            const priceText = priceData[date].formatted.replace(/(\d)([^\d\s.,])/, '$1 $2');
                            const $priceTag = $('<span class="mhb-fp-price">' + priceText + '</span>');
                            $(dayElem).append($priceTag);
                            $(dayElem).addClass('has-price');
                        }
                    }
                });
            }

            function updateBookingForm(dates) {
                const start = dates[0];
                const end = dates[1];
                const startStr = picker.formatDate(start, "Y-m-d");
                const endStr = picker.formatDate(end, "Y-m-d");

                $wrapper.find('.mhb-cal-check-in').val(startStr);
                $wrapper.find('.mhb-cal-check-out').val(endStr);
                $wrapper.find('.mhb-display-check-in').text(startStr);
                $wrapper.find('.mhb-display-check-out').text(endStr);

                // Update summary box labels if they have translation tags
                if ($selectionBox.find('.mhb-selection-header h3').length) {
                    $selectionBox.find('.mhb-selection-header h3').text(mhb_calendar.i18n.your_selection || 'Your Selection');
                }
                const $dateLabels = $selectionBox.find('.mhb-selection-dates .mhb-label');
                if ($dateLabels.length >= 2) {
                    $($dateLabels[0]).text(mhb_calendar.i18n.check_in || 'Check-in');
                    $($dateLabels[1]).text(mhb_calendar.i18n.check_out || 'Check-out');
                }

                // Update button text if translation is available
                const $submitBtn = $wrapper.find('.mhb-booking-btn-submit');
                if ($submitBtn.length && mhb_calendar.i18n.continue_booking) {
                    $submitBtn.text(mhb_calendar.i18n.continue_booking);
                }

                // Update pricing summary only for room-specific calendars
                if (showPrice && typeof calculateTotalPrice === 'function') {
                    $selectionBox.find('.mhb-selection-price .mhb-label').text(mhb_calendar.i18n.total || 'Total');
                    calculateTotalPrice(start, end);
                }

                $guide.text(mhb_calendar.i18n.dates_selected || 'Dates selected. Complete the form below.');
                $selectionBox.fadeIn();
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
                $wrapper.find('.mhb-display-price').text(formatted);
                $wrapper.find('.mhb-cal-total-price').val(total);
            }

            function formatCurrency(amount) {
                const pos = mhb_calendar.settings.currency_pos;
                const symbol = mhb_calendar.settings.currency_symbol;
                const decimals = (typeof mhb_calendar.settings.currency_decimals !== 'undefined') ? parseInt(mhb_calendar.settings.currency_decimals) : 2;
                const formatted = amount.toFixed(decimals);
                return pos === 'before' ? (symbol + formatted) : (formatted + symbol);
            }

            initFlatpickr();
        });
    }

    // Capture the button click globally for better resilience
    $(document).on('click', '.mhb-booking-btn-submit', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const $wrapper = $btn.closest('.mhb-calendar-container');
        const $form = $wrapper.find('.mhb-selection-form');
        const roomId = $wrapper.data('room-id');

        const action = $form.attr('action');
        const checkIn = $wrapper.find('.mhb-cal-check-in').val();
        const checkOut = $wrapper.find('.mhb-cal-check-out').val();
        const totalPrice = $wrapper.find('.mhb-cal-total-price').val();

        if (!checkIn || !checkOut) {
            alert(mhb_calendar.i18n.select_dates_error || 'Please select check-in and check-out dates.');
            return;
        }

        let finalUrl;
        try {
            const base = window.location.origin;
            const url = (action && action !== '#' && action !== '') ? new URL(action, base) : new URL(window.location.href);

            url.searchParams.set('room_id', roomId);
            url.searchParams.set('check_in', checkIn);
            url.searchParams.set('check_out', checkOut);
            url.searchParams.set('total_price', totalPrice);
            url.searchParams.set('mhb_auto_book', '1');

            finalUrl = url.toString();
        } catch (err) {
            debugLog('Redirect error:', err);
            const sep = (action && action.indexOf('?') !== -1) ? '&' : '?';
            const baseUrl = (action && action !== '#' && action !== '') ? action : window.location.href.split('?')[0];
            finalUrl = baseUrl + sep + 'room_id=' + roomId + '&check_in=' + checkIn + '&check_out=' + checkOut + '&total_price=' + totalPrice + '&mhb_auto_book=1';
        }

        window.location.href = finalUrl;
    });

    initAllCalendars();
});
