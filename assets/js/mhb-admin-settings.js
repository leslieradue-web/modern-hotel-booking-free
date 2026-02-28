/**
 * Modern Hotel Booking - Admin Settings
 * 
 * Handles admin settings page interactions including:
 * - License activation/deactivation
 * - Custom fields repeater
 * - Holiday dates management
 * - Theme selection
 * 
 * @package MHB
 * @since 2.0.1
 */

(function ($) {
    'use strict';

    // Configuration is injected via wp_add_inline_script()
    const config = window.mhbAdminSettingsConfig || {};

    /**
     * License Management
     */
    function initLicenseManagement() {
        const $activateBtn = $('#mhb_activate_license');
        const $deactivateBtn = $('#mhb_deactivate_license');
        const $spinner = $('#mhb_license_spinner');
        const $message = $('#mhb_license_message');

        if (!$activateBtn.length && !$deactivateBtn.length) return;

        $activateBtn.on('click', function (e) {
            e.preventDefault();
            const key = $('#mhb_license_key').val();
            if (!key) {
                alert(config.i18n?.enter_license_key || 'Please enter a license key.');
                return;
            }
            $spinner.addClass('is-active');
            $message.text('');

            $.post(ajaxurl, {
                action: 'mhb_activate_license',
                license_key: key,
                security: config.nonces?.license || ''
            }, function (response) {
                $spinner.removeClass('is-active');
                if (response.success) {
                    $message.html('<span style="color:green;">' + response.data.message + '</span>');
                    setTimeout(function () { location.reload(); }, 1500);
                } else {
                    $message.html('<span style="color:red;">' + (response.data ? response.data.message : 'Error') + '</span>');
                }
            }).fail(function () {
                $spinner.removeClass('is-active');
                $message.html('<span style="color:red;">' + (config.i18n?.connection_error || 'Connection error.') + '</span>');
            });
        });

        $deactivateBtn.on('click', function (e) {
            e.preventDefault();
            if (!confirm(config.i18n?.are_you_sure || 'Are you sure?')) return;

            $spinner.addClass('is-active');
            $.post(ajaxurl, {
                action: 'mhb_deactivate_license',
                security: config.nonces?.license || ''
            }, function (response) {
                $spinner.removeClass('is-active');
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message);
                }
            });
        });
    }

    /**
     * Custom Fields Repeater
     */
    function initCustomFieldsRepeater() {
        const $addBtn = $('#mhb-add-custom-field');
        const $repeater = $('#mhb-custom-fields-repeater');

        if (!$addBtn.length) return;

        const langLabels = config.langLabels || {};

        $addBtn.on('click', function () {
            const index = $repeater.find('.mhb-repeater-item').length;
            let langFields = '';

            Object.keys(langLabels).forEach(function (lang) {
                langFields += '<div style="display: flex; align-items: center; margin-bottom: 5px;">' +
                    '<span style="width: 35px; font-weight: 600; font-size: 11px;">' + lang.toUpperCase() + ':</span>' +
                    '<input type="text" name="mhb_custom_fields[' + index + '][label][' + lang + ']" value="" class="widefat" style="flex: 1;"></div>';
            });

            const html = '<div class="mhb-repeater-item" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 15px; position: relative; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">' +
                '<button type="button" class="mhb-remove-field" style="position: absolute; top: 10px; right: 10px; color: #d63638; background: none; border: none; font-size: 20px; cursor: pointer; padding: 0;">&times;</button>' +
                '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">' +
                '<div>' +
                '<label style="display: block; font-weight: bold; margin-bottom: 5px;">Field ID (slug)</label>' +
                '<input type="text" name="mhb_custom_fields[' + index + '][id]" value="" class="widefat" placeholder="slug" required>' +
                '</div>' +
                '<div>' +
                '<label style="display: block; font-weight: bold; margin-bottom: 5px;">Type</label>' +
                '<select name="mhb_custom_fields[' + index + '][type]" class="widefat"><option value="text">Text</option><option value="number">Number</option><option value="textarea">Textarea</option></select>' +
                '</div>' +
                '</div>' +
                '<div style="margin-bottom: 12px;">' +
                '<label style="display: block; font-weight: bold; margin-bottom: 8px;">Label (Multilingual)</label>' +
                langFields +
                '</div>' +
                '<div><label style="font-weight: bold;"><input type="checkbox" name="mhb_custom_fields[' + index + '][required]" value="1"> Required Field</label></div>' +
                '</div>';

            $repeater.find('.mhb-repeater-items').append(html);
        });

        $(document).on('click', '.mhb-remove-field', function () {
            if (confirm(config.i18n?.remove_field_confirm || 'Are you sure you want to remove this field?')) {
                $(this).closest('.mhb-repeater-item').remove();
            }
        });
    }

    /**
     * Holiday Dates Management
     */
    function initHolidayManagement() {
        const $list = $('#mhb-holiday-list');
        const $hidden = $('#mhb-holiday-dates-hidden');
        const $newDate = $('#mhb-new-holiday');
        const $addBtn = $('#mhb-add-holiday-btn');

        if (!$list.length) return;

        function updateList() {
            const dates = $hidden.val() ? $hidden.val().split(',').filter(d => d).sort() : [];
            $list.empty();
            if (dates.length === 0) {
                $list.append('<div style="color:#999; font-style:italic; padding:5px;">' + (config.i18n?.no_holidays || 'No holidays added yet.') + '</div>');
                return;
            }
            dates.forEach(function (date) {
                $list.append(
                    '<div style="display:flex; justify-content:space-between; align-items:center; background:#f6f7f7; padding:6px 10px; border-radius:4px; border:1px solid #dcdcde;">' +
                    '<span style="font-family:monospace; font-weight:600;">' + date + '</span>' +
                    '<span class="dashicons dashicons-no-alt mhb-remove-date" data-date="' + date + '" style="cursor:pointer; color:#d63638; font-size:18px;"></span>' +
                    '</div>'
                );
            });
        }

        $addBtn.on('click', function () {
            const date = $newDate.val();
            if (!date) return;
            let dates = $hidden.val() ? $hidden.val().split(',').filter(d => d) : [];
            if (!dates.includes(date)) {
                dates.push(date);
                $hidden.val(dates.join(','));
                updateList();
            }
            $newDate.val('');
        });

        $list.on('click', '.mhb-remove-date', function () {
            const toRemove = $(this).data('date');
            let dates = $hidden.val().split(',').filter(d => d && d !== toRemove);
            $hidden.val(dates.join(','));
            updateList();
        });

        updateList();
    }

    /**
     * Adjustment Toggles
     */
    function initAdjustmentToggles() {
        $(document).on('change', '.mhb-adj-toggle', function () {
            const $inputs = $(this).closest('td').find('.mhb-adj-inputs');
            if ($(this).is(':checked')) {
                $inputs.css({ opacity: 1, pointerEvents: 'auto' });
            } else {
                $inputs.css({ opacity: 0.5, pointerEvents: 'none' });
            }
        });
    }

    /**
     * Theme Selection
     */
    function initThemeSelection() {
        const $themeInputs = $('input[name="mhb_active_theme"]');
        const $customColors = $('#mhb-custom-colors-wrap');

        if (!$themeInputs.length) return;

        $themeInputs.on('change', function () {
            $('.mhb-theme-card').removeClass('active');
            $(this).closest('.mhb-theme-card').addClass('active');
            if ($(this).val() === 'custom') {
                $customColors.slideDown();
            } else {
                $customColors.slideUp();
            }
        });
    }

    /**
     * Payment Gateway Test Credentials
     */
    function initPaymentGatewayTests() {
        // Stripe Test
        const $stripeTestBtn = $('#mhb-test-stripe-btn');
        const $stripeResult = $('#mhb-stripe-test-result');

        $stripeTestBtn.on('click', function (e) {
            e.preventDefault();
            const btn = $(this);
            const selectedMode = $('select[name="mhb_stripe_mode"]').val() || 'test';

            btn.prop('disabled', true);
            $stripeResult.html('<span class="spinner is-active" style="float: none; margin: 0;"></span>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mhb_test_stripe_credentials',
                    security: config.nonces?.test_stripe || '',
                    mode: selectedMode
                },
                success: function (response) {
                    btn.prop('disabled', false);
                    if (response.success) {
                        $stripeResult.html('<span style="color: green; font-weight: bold;">✓ ' + response.data.message + '</span>');
                    } else {
                        $stripeResult.html('<span style="color: red; font-weight: bold;">✗ ' + (response.data ? response.data.message : 'Unknown error') + '</span>');
                    }
                },
                error: function () {
                    btn.prop('disabled', false);
                    $stripeResult.html('<span style="color: red;">Connection error. Please try again.</span>');
                }
            });
        });

        // PayPal Test
        const $paypalTestBtn = $('#mhb-test-paypal-btn');
        const $paypalSpinner = $('#mhb-paypal-test-spinner');
        const $paypalResult = $('#mhb-paypal-test-result');

        $paypalTestBtn.on('click', function (e) {
            e.preventDefault();
            const btn = $(this);
            const selectedMode = $('select[name="mhb_paypal_mode"]').val() || 'sandbox';

            btn.prop('disabled', true);
            $paypalSpinner.addClass('is-active');
            $paypalResult.html('');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mhb_test_paypal_credentials',
                    security: config.nonces?.test_paypal || '',
                    mode: selectedMode
                },
                success: function (response) {
                    $paypalSpinner.removeClass('is-active');
                    btn.prop('disabled', false);
                    if (response.success) {
                        $paypalResult.html('<span style="color: green; font-weight: bold;">✓ ' + response.data.message + '</span>');
                    } else {
                        $paypalResult.html('<span style="color: red; font-weight: bold;">✗ ' + (response.data ? response.data.message : 'Unknown error') + '</span>');
                    }
                },
                error: function () {
                    $paypalSpinner.removeClass('is-active');
                    btn.prop('disabled', false);
                    $paypalResult.html('<span style="color: red;">Connection error. Please try again.</span>');
                }
            });
        });
    }

    // Initialize on DOM ready
    $(document).ready(function () {
        initLicenseManagement();
        initCustomFieldsRepeater();
        initHolidayManagement();
        initAdjustmentToggles();
        initThemeSelection();
        initPaymentGatewayTests();
    });

})(jQuery);