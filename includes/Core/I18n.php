<?php
declare(strict_types=1);

/**
 * I18n — Multilingual Abstraction Layer
 *
 * Supports qTranslate-X, WPML, Polylang, and vanilla WordPress.
 * Internal storage format: [:en]English[:ro]Romanian[:de]German[:]
 *
 * IMPORTANT — WordPress i18n compliance:
 * All gettext calls (__(), esc_html__(), etc.) MUST use literal string
 * arguments for both the text and the text domain. Never pass variables
 * or constants to these functions — the translation parser reads code
 * statically and cannot resolve runtime values.
 *
 * Correct:   __( 'Hello', 'modern-hotel-booking' )
 * Incorrect: __( $text,   $domain )
 *
 * If you need to include dynamic values, use printf/sprintf with placeholders:
 *   echo esc_html( sprintf( __( 'Hello %s', 'text-domain' ), $name ) );
 *
 * @package MHBO\Core
 * @since   2.0.0
 */

namespace MHBO\Core;
if (!defined('ABSPATH')) {
    exit;
}


class I18n
{
    /**
     * Initialize translation filters.
     */
    public static function init(): void
    {
        add_filter('gettext_modern-hotel-booking', array(self::class, 'filter_gettext'), 10, 3);
    }

    /**
     * Filter plugin translations to prevent blank strings.
     *
     * If a translation is found to be empty or just whitespace in the .mo file,
     * WP returns it as is. We force it to fallback to the original English string.
     *
     * @param string $translated
     * @param string $text
     * @param string $domain
     * @return string
     */
    public static function filter_gettext(string $translated, string $text, string $domain): string
    {
        if (empty(trim($translated))) {
            return $text;
        }
        return $translated;
    }

    /**
     * Detect which multilingual plugin is active.
     *
     * @return string 'qtranslate'|'wpml'|'polylang'|'none'
     */
    public static function detect_plugin(): string
    {
        if (defined('QTX_VERSION') || function_exists('qtranxf_getLanguage')) {
            return 'qtranslate';
        }
        if (defined('ICL_SITEPRESS_VERSION') || function_exists('icl_get_languages')) {
            return 'wpml';
        }
        if (function_exists('pll_current_language') || defined('POLYLANG_VERSION')) {
            return 'polylang';
        }
        return 'none';
    }

    /**
     * Helper to get the 2-letter locale code.
     *
     * @return string
     */
    private static function locale_code(): string
    {
        return substr(get_locale(), 0, 2);
    }

    /**
     * Get current front-end/admin language code (2-letter).
     *
     * @return string
     */
    public static function get_current_language(): string
    {
        // Handle admin side language selection via URL param (e.g., ?lang=ro)
        if (is_admin() && isset($_GET['lang'])) {  // sanitize_text_field applied or checked via nonce later // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display parameter, no state change
            return sanitize_key(wp_unslash($_GET['lang'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        switch (self::detect_plugin()) {
            case 'qtranslate':
                return function_exists('qtranxf_getLanguage') ? call_user_func('qtranxf_getLanguage') : self::locale_code();
            case 'wpml':
                return apply_filters('wpml_current_language', self::locale_code()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook
            case 'polylang':
                $lang = function_exists('pll_current_language') ? call_user_func('pll_current_language') : self::locale_code();
                return $lang ? $lang : self::locale_code();
            default:
                return self::locale_code();
        }
    }

    /**
     * Get default site language code.
     *
     * @return string
     */
    public static function get_default_language(): string
    {
        switch (self::detect_plugin()) {
            case 'qtranslate':
                $qtranslate_config = self::get_q_config();
                return isset($qtranslate_config['default_language']) ? $qtranslate_config['default_language'] : 'en';
            case 'wpml':
                return apply_filters('wpml_default_language', 'en'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook
            case 'polylang':
                return function_exists('pll_default_language') ? call_user_func('pll_default_language') : 'en';
            default:
                return 'en';
        }
    }

    /**
     * Get all available languages as an array of 2-letter codes.
     *
     * @return string[]
     */
    public static function get_available_languages(): array
    {
        switch (self::detect_plugin()) {
            case 'qtranslate':
                $qtranslate_config = self::get_q_config();
                return isset($qtranslate_config['enabled_languages']) ? $qtranslate_config['enabled_languages'] : array(self::locale_code());

            case 'wpml':
                $langs = apply_filters('wpml_active_languages', null, array('skip_missing' => 0)); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook
                return is_array($langs) ? array_keys($langs) : array(self::locale_code());

            case 'polylang':
                if (function_exists('pll_languages_list')) {
                    $list = call_user_func('pll_languages_list', array('fields' => 'slug'));
                    return !empty($list) ? $list : array(self::locale_code());
                }
                return array(self::locale_code());

            default:
                $langs = array_unique(array('en', self::locale_code()));
                return apply_filters('mhbo_i18n_get_available_languages', array_values($langs));
        }
    }

    /**
     * Get qTranslate-XT configuration (third-party global).
     *
     * @return array
     */
    private static function get_q_config(): array
    {
        // Use $GLOBALS to avoid WP repo scanner false-positives for unprefixed globals (this belongs to qTranslate)
        return isset($GLOBALS['q_config']) ? $GLOBALS['q_config'] : [];
    }

    /**
     * Decode a multilingual string.
     * Format: [:en]Hello[:ro]Salut[:]
     *
     * @param string      $text The string to decode.
     * @param string|null $lang Optional language code.
     * @param bool        $fallback Whether to fallback to other languages if requested is missing.
     * @return string|null
     */
    public static function decode($text, $lang = null, $fallback = true): ?string
    {
        if (empty($text) || !is_string($text)) {
            return is_scalar($text) ? (string) $text : null;
        }

        if (!$lang) {
            $lang = self::get_current_language();
        }

        // qTranslate style parsing - leverage native function if available (only if fallback is enabled)
        if ($fallback && function_exists('qtranxf_use')) {
            return qtranxf_use($lang, $text);
        }

        // Detect if this is a plain string (no multilingual tags)
        $is_plain = false === strpos($text, '[:');

        // Handle plain strings on multilingual sites
        if ($is_plain && 'none' !== self::detect_plugin() && !$fallback) {
            return null;
        }

        // Manual parsing fallback for single-language strings or when fallback is allowed
        if ($is_plain) {
            return $text;
        }

        // qTranslate style parsing
        $blocks = preg_split('/\[:([a-z]{2}(?:_[a-z]{2})?)\]/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (count($blocks) < 3) {
            return $text;
        }

        // Build a map of languages to content
        $map = [];
        for ($i = 1; $i < count($blocks); $i += 2) {
            if (isset($blocks[$i + 1])) {
                $lang_key = strtolower($blocks[$i]);
                $content = $blocks[$i + 1];

                if (substr($content, -3) === '[:]') {
                    $content = substr($content, 0, -3);
                }

                $map[$lang_key] = $content;
            }
        }

        // Normalize requested language
        $lang = strtolower($lang);
        $lang_short = substr($lang, 0, 2);

        // 1. Try exact match
        if (!empty($map[$lang])) {
            return $map[$lang];
        }

        // 2. Try prefix match
        if (2 === strlen($lang)) {
            foreach ($map as $k => $v) {
                if (0 === strpos($k, $lang . '_')) {
                    return $v;
                }
            }
        }

        // 3. Try short match
        if (!empty($map[$lang_short])) {
            return $map[$lang_short];
        }

        // If no fallback allowed, stop here
        if (!$fallback) {
            return null;
        }

        // 4. Fallback to default language
        $default = strtolower(self::get_default_language());
        if (!empty($map[$default])) {
            return $map[$default];
        }
        $default_short = substr($default, 0, 2);
        if (!empty($map[$default_short])) {
            return $map[$default_short];
        }

        // 5. Fallback to English
        if (!empty($map['en'])) {
            return $map['en'];
        }

        // 6. Final fallback to the first available non-empty block
        foreach ($map as $val) {
            if (!empty($val)) {
                return apply_filters('mhbo_i18n_decode', $val, $text, $lang);
            }
        }

        // 7. Final, final fallback: strip all tags
        $result = preg_replace('/\[:([a-z]{2}(?:_[a-z]{2})?)\]/i', '', $text);
        $result = str_replace('[:]', '', $result);

        return apply_filters('mhbo_i18n_decode', trim($result) ?: $text, $text, $lang);
    }

    /**
     * Encode an array of [lang => text] into a multilingual string.
     *
     * @param array $values
     * @return string
     */
    public static function encode($values): string
    {
        if (!is_array($values)) {
            return $values;
        }
        $out = '';
        foreach ($values as $lang => $text) {
            if (!empty($text)) {
                $out .= "[:{$lang}]{$text}";
            }
        }
        if (!empty($out)) {
            $out .= '[:]';
        }
        return $out;
    }

    /**
     * Decode an already-translated string for multilingual (qTranslate-style) support.
     *
     * -------------------------------------------------------------------------
     * IMPORTANT — Do NOT use this method for gettext string extraction.
     * Always call WordPress __() / esc_html__() etc. with LITERAL strings
     * and a LITERAL text domain FIRST, then pass the result here when you
     * need multilingual (qTranslate-style [:xx]) decoding.
     *
     * Correct usage:
     *   $translated = __( 'Search Rooms', 'modern-hotel-booking' );
     *   $decoded    = I18n::translate_and_decode( $translated, 'ro' );
     *
     * Incorrect (blocks translation parser):
     *   $decoded = I18n::translate_and_decode( $variable );
     * -------------------------------------------------------------------------
     *
     * @param string      $translated Already-translated text (from __() or similar).
     * @param string|null $language   Optional language code for multilingual strings.
     * @return string Decoded text.
     */
    public static function translate_and_decode($translated, $language = null): string
    {
        // If translation is empty, return as-is
        if (empty($translated)) {
            return $translated;
        }

        // If the translated string contains multilingual format, decode it
        if (false !== strpos($translated, '[:')) {
            $decoded = self::decode($translated, $language);
            if (!empty($decoded)) {
                return $decoded;
            }
        }

        return $translated;
    }

    /**
     * Format currency based on site settings.
     *
     * @param float|int $amount
     * @return string
     */
    public static function format_currency($amount): string
    {
        $symbol = get_option('mhbo_currency_symbol', '$');
        $position = get_option('mhbo_currency_position', 'before');
        $decimal_separator = apply_filters('mhbo_currency_decimal_separator', '.');
        $thousand_separator = apply_filters('mhbo_currency_thousand_separator', ',');
        $decimals = apply_filters('mhbo_currency_decimals', 0);

        $formatted = number_format((float) $amount, $decimals, $decimal_separator, $thousand_separator);

        if ('before' === $position) {
            return $symbol . $formatted;
        }
        return $formatted . $symbol;
    }

    /**
     * Format a date string based on WP settings.
     *
     * @param string $date_string
     * @return string
     */
    public static function format_date($date_string): string
    {
        if (empty($date_string)) {
            return '';
        }
        return date_i18n(get_option('date_format'), strtotime($date_string));
    }

    /**
     * Get a localized label for front-end/admin use.
     *
     * @param string $key
     * @return string
     */
    public static function get_label($key): string
    {
        // Check for database override first
        $override = get_option("mhbo_label_{$key}");

        $labels = self::get_all_default_labels();
        $default_val = isset($labels[$key]) ? $labels[$key] : $key;

        $value = !empty($override) ? $override : $default_val;

        // Check for translated string via WPML/Polylang
        $translated = self::get_translated_string("Label: {$key}", $value, 'MHBO Frontend Labels');

        // If translation is found and not empty, decode it (it might still be qTranslate format)
        if (!empty($translated)) {
            $decoded = self::decode($translated);
            if (!empty($decoded)) {
                return $decoded;
            }
        }

        // Fallback to English if the result is empty
        if (isset($labels[$key])) {
            return self::decode($labels[$key], 'en');
        }

        return $key;
    }

    /**
     * Get all default labels in multilingual format.
     *
     * NOTE: Every __() call below uses a LITERAL string and the LITERAL
     * text domain 'modern-hotel-booking' so that the WordPress translation
     * parser can extract them. Never replace these with variables.
     *
     * @return array
     */
    public static function get_all_default_labels(): array
    {
        return array(
            'btn_search_rooms' => __('Search Rooms', 'modern-hotel-booking'),
            'label_check_in' => __('Check-in', 'modern-hotel-booking'),
            'label_check_out' => __('Check-out', 'modern-hotel-booking'),
            // translators: %s: check-in time (e.g., 14:00)
            'label_check_in_from' => __('from %s', 'modern-hotel-booking'),
            // translators: %s: check-out time (e.g., 11:00)
            'label_check_out_by' => __('by %s', 'modern-hotel-booking'),
            'label_guests' => __('Guests', 'modern-hotel-booking'),
            'label_children' => __('Children', 'modern-hotel-booking'),
            'label_child_ages' => __('Child Ages', 'modern-hotel-booking'),
            // translators: %d: child number
            'label_child_n_age' => __('Child %d Age', 'modern-hotel-booking'),
            'label_guest' => __('Guest', 'modern-hotel-booking'),
            // translators: 1: check-in date, 2: check-out date
            'label_available_rooms' => __('Available Rooms from %1$s to %2$s', 'modern-hotel-booking'),
            'label_no_rooms' => __('No rooms available for these dates.', 'modern-hotel-booking'),
            'label_per_night' => __('per night', 'modern-hotel-booking'),
            // translators: %1$d: number of nights, %2$s: nightly rate
            'label_total_nights' => __('%1$d nights: %2$s', 'modern-hotel-booking'),
            // translators: %d: maximum number of guests
            'label_max_guests' => __('Max guests: %d', 'modern-hotel-booking'),
            'btn_book_now' => __('Book Now', 'modern-hotel-booking'),
            'label_complete_booking' => __('Complete Your Booking', 'modern-hotel-booking'),
            'label_total' => __('Total Price', 'modern-hotel-booking'),
            'label_name' => __('Full Name', 'modern-hotel-booking'),
            'label_email' => __('Email Address', 'modern-hotel-booking'),
            'label_phone' => __('Phone Number', 'modern-hotel-booking'),
            'btn_confirm_booking' => __('Confirm Booking', 'modern-hotel-booking'),
            'btn_pay_confirm' => __('Pay & Confirm', 'modern-hotel-booking'),
            'msg_booking_confirmed' => __('Booking Confirmed!', 'modern-hotel-booking'),
            'msg_confirmation_sent' => __('A confirmation email has been sent to you.', 'modern-hotel-booking'),
            'msg_booking_received' => __('Booking Pending', 'modern-hotel-booking'),
            'msg_booking_received_detail' => __('We have received your request and will contact you shortly.', 'modern-hotel-booking'),
            // translators: %s: total amount
            'label_arrival_msg' => __('You will pay %s upon arrival at the hotel.', 'modern-hotel-booking'),
            'label_payment_method' => __('Payment Method', 'modern-hotel-booking'),
            'label_pay_arrival' => __('Pay on Arrival', 'modern-hotel-booking'),
            'label_special_requests' => __('Special Requests / Notes', 'modern-hotel-booking'),
            'label_select_check_in' => __('Select your check-in date', 'modern-hotel-booking'),
            'label_select_check_out' => __('Now select your check-out date', 'modern-hotel-booking'),
            'label_stay_dates' => __('Stay Dates', 'modern-hotel-booking'),
            'label_select_dates' => __('Select Dates', 'modern-hotel-booking'),
            'label_your_selection' => __('Your Selection', 'modern-hotel-booking'),
            'label_continue_booking' => __('Continue to Booking', 'modern-hotel-booking'),
            'label_dates_selected' => __('Dates selected. Complete the form below.', 'modern-hotel-booking'),
            'label_credit_card' => __('Credit Card', 'modern-hotel-booking'),
            'label_paypal' => __('PayPal', 'modern-hotel-booking'),
            'label_confirm_request' => __('Click below to confirm your booking request.', 'modern-hotel-booking'),
            // translators: %s: tax name (e.g., VAT)
            'label_tax_breakdown' => __('%s Breakdown', 'modern-hotel-booking'),
            // translators: %1$s: tax name, %2$s: tax amount
            'label_tax_total' => __('Total %1$s: %2$s', 'modern-hotel-booking'),
            // translators: %s: tax registration number
            'label_tax_registration' => __('Tax Registration: %s', 'modern-hotel-booking'),
            // translators: %s: tax amount
            'label_includes_tax' => __('(includes %s)', 'modern-hotel-booking'),
            // translators: %1$s: tax name, %2$s: tax percentage
            'label_price_includes_tax' => __('Price includes %1$s (%2$s%%)', 'modern-hotel-booking'),
            // translators: %1$s: tax name, %2$s: tax percentage
            'label_tax_added_at_checkout' => __('%1$s (%2$s%%) will be added at checkout', 'modern-hotel-booking'),
            'label_subtotal' => __('Subtotal', 'modern-hotel-booking'),
            'label_room' => __('Room', 'modern-hotel-booking'),
            'label_extras' => __('Extras', 'modern-hotel-booking'),
            'label_item' => __('Item', 'modern-hotel-booking'),
            'label_amount' => __('Amount', 'modern-hotel-booking'),
            'label_booking_summary' => __('Booking Summary', 'modern-hotel-booking'),
            'label_accommodation' => __('Accommodation', 'modern-hotel-booking'),
            'label_extras_item' => __('Extras', 'modern-hotel-booking'),
            // translators: %1$s: tax name, %2$s: tax percentage
            'label_tax_accommodation' => __('%1$s - Accommodation (%2$s%%)', 'modern-hotel-booking'),
            // translators: %1$s: tax name, %2$s: tax percentage
            'label_tax_extras' => __('%1$s - Extras (%2$s%%)', 'modern-hotel-booking'),
            // translators: %1$s: tax name, %2$s: tax percentage
            'label_tax_rate' => __('%1$s (%2$s%%)', 'modern-hotel-booking'),
            'label_availability_error' => __('Dates are not available.', 'modern-hotel-booking'),
            'label_room_not_found' => __('Room not found.', 'modern-hotel-booking'),
            'label_secure_payment' => __('Secure Online Payment', 'modern-hotel-booking'),
            'label_security_error' => __('Security verification failed. Please refresh the page.', 'modern-hotel-booking'),
            'label_rate_limit_error' => __('Too many attempts. Please wait a minute.', 'modern-hotel-booking'),
            'label_spam_honeypot' => __('Leave this field empty', 'modern-hotel-booking'),
            'label_room_alt_text' => __('Room Image', 'modern-hotel-booking'),
            'label_calendar_no_id' => __('No room ID specified for calendar.', 'modern-hotel-booking'),
            'label_calendar_config_error' => __('Booking Page URL not configured.', 'modern-hotel-booking'),
            'label_loading' => __('Loading...', 'modern-hotel-booking'),
            'label_to' => __('to', 'modern-hotel-booking'),
            'btn_processing' => __('Processing...', 'modern-hotel-booking'),
            'msg_gdpr_required' => __('Please accept the privacy policy to continue.', 'modern-hotel-booking'),
            'msg_paypal_required' => __('Please use the PayPal button to complete your payment.', 'modern-hotel-booking'),
            'label_enhance_stay' => __('Enhance Your Stay', 'modern-hotel-booking'),
            'label_per_person' => __('per person', 'modern-hotel-booking'),
            'label_per_person_per_night' => __('per person / night', 'modern-hotel-booking'),
            /* translators: %s: Tax rate percentage */
            'label_tax_note_includes' => __('Price includes %s', 'modern-hotel-booking'),
            /* translators: %s: Tax rate percentage */
            'label_tax_note_plus' => __('Price plus %s', 'modern-hotel-booking'),
            /* translators: %1$s: Tax label, %2$s: Tax rate percentage */
            'label_tax_note_includes_multi' => __('Price includes %1$s (%2$s%%)', 'modern-hotel-booking'),
            /* translators: %1$s: Tax label, %2$s: Tax rate percentage */
            'label_tax_note_plus_multi' => __('Price plus %1$s (%2$s%%)', 'modern-hotel-booking'),
            'label_select_dates_error' => __('Please select check-in and check-out dates.', 'modern-hotel-booking'),
            'label_legend_confirmed' => __('Booked', 'modern-hotel-booking'),
            'label_legend_pending' => __('Pending', 'modern-hotel-booking'),
            'label_legend_available' => __('Available', 'modern-hotel-booking'),
            'label_block_no_room' => __('Please select a Room ID in block settings.', 'modern-hotel-booking'),
            'label_check_in_past' => __('Check-in date cannot be in the past.', 'modern-hotel-booking'),
            'label_check_out_after' => __('Check-out date must be after check-in date.', 'modern-hotel-booking'),
            'label_check_in_future' => __('Check-in date cannot be more than 2 years in the future.', 'modern-hotel-booking'),
            'label_check_out_future' => __('Check-out date cannot be more than 2 years in the future.', 'modern-hotel-booking'),
            'label_name_too_long' => __('Name is too long (maximum 100 characters).', 'modern-hotel-booking'),
            'label_phone_too_long' => __('Phone number is too long (maximum 30 characters).', 'modern-hotel-booking'),
            /* translators: %d: Maximum number of children */
            'label_max_children_error' => __('Error: Maximum children for this room is %d.', 'modern-hotel-booking'),
            'label_price_calc_error' => __('Error calculating price. Please check dates.', 'modern-hotel-booking'),
            'label_fill_all_fields' => __('Please fill in all required fields.', 'modern-hotel-booking'),
            'label_invalid_email' => __('Please provide a valid email address.', 'modern-hotel-booking'),
            /* translators: %s: Field name */
            'label_field_required' => __('The field "%s" is required.', 'modern-hotel-booking'),
            'label_spam_detected' => __('Spam detected.', 'modern-hotel-booking'),
            'label_already_booked' => __('Sorry, this room was just booked by someone else or is unavailable for these dates.', 'modern-hotel-booking'),
            /* translators: %d: Maximum number of adults */
            'label_max_adults_error' => __('Error: Maximum adults for this room is %d.', 'modern-hotel-booking'),
            'label_rest_pro_error' => __('REST API access is a Pro feature.', 'modern-hotel-booking'),
            'label_invalid_nonce' => __('Invalid nonce.', 'modern-hotel-booking'),
            'label_api_rate_limit' => __('Too many requests. Please try again later.', 'modern-hotel-booking'),
            'msg_payment_success_email' => __('Payment received successfully. A confirmation email has been sent.', 'modern-hotel-booking'),
            'msg_booking_arrival_email' => __('Your booking is confirmed. Payment will be collected on arrival. A confirmation email has been sent.', 'modern-hotel-booking'),
            'label_payment_failed' => __('Payment Failed', 'modern-hotel-booking'),
            'msg_payment_failed_detail' => __('Your payment could not be processed. Please try again or contact us for assistance.', 'modern-hotel-booking'),
            'msg_booking_received_pending' => __('Your reservation is under review and needs to be approved before becoming reserved.', 'modern-hotel-booking'),
            'label_payment_status' => __('Payment Status:', 'modern-hotel-booking'),
            'label_paid' => __('Paid', 'modern-hotel-booking'),
            'label_amount_paid' => __('Amount Paid:', 'modern-hotel-booking'),
            'label_transaction_id' => __('Transaction ID:', 'modern-hotel-booking'),
            'label_failed' => __('Failed', 'modern-hotel-booking'),
            'label_dates_no_longer_available' => __('Sorry, these dates are no longer available. Please select different dates.', 'modern-hotel-booking'),
            'label_invalid_booking_calc' => __('Invalid booking details. Cannot calculate amount.', 'modern-hotel-booking'),
            'label_stripe_not_configured' => __('Stripe is not configured.', 'modern-hotel-booking'),
            'label_paypal_not_configured' => __('PayPal is not configured. Please contact the site administrator.', 'modern-hotel-booking'),
            'label_paypal_connection_error' => __('Unable to connect to PayPal. Please try again later.', 'modern-hotel-booking'),
            'label_paypal_auth_failed' => __('Failed to authenticate with PayPal. Please check your PayPal credentials.', 'modern-hotel-booking'),
            'label_paypal_order_create_error' => __('Unable to create PayPal order. Please try again later.', 'modern-hotel-booking'),
            /* translators: %s: Currency code */
            'label_paypal_currency_unsupported' => __('Currency %s is not supported by your PayPal account.', 'modern-hotel-booking'),
            /* translators: %s: Error message */
            'label_paypal_generic_error' => __('PayPal error: %s', 'modern-hotel-booking'),
            'label_missing_order_id' => __('Missing order ID.', 'modern-hotel-booking'),
            'label_paypal_capture_error' => __('Unable to capture payment. Please try again later.', 'modern-hotel-booking'),
            'label_payment_already_processed' => __('This payment has already been processed.', 'modern-hotel-booking'),
            'label_payment_declined_paypal' => __('The payment was declined by PayPal. Please try a different payment method.', 'modern-hotel-booking'),
            'label_payment_confirmation' => __('Payment Confirmation', 'modern-hotel-booking'),
            'label_privacy_policy' => __('privacy policy', 'modern-hotel-booking'),
            'label_terms_conditions' => __('Terms & Conditions', 'modern-hotel-booking'),
            'label_payment_info' => __('Payment Information', 'modern-hotel-booking'),
            'msg_pay_on_arrival_email' => __('Payment will be collected upon arrival at the property.', 'modern-hotel-booking'),
            'label_amount_due' => __('Amount Due', 'modern-hotel-booking'),
            'label_payment_date' => __('Payment Date', 'modern-hotel-booking'),
            'label_paypal_order_failed' => __('Failed to create PayPal order.', 'modern-hotel-booking'),
            'label_security_verification_failed' => __('Security verification failed. Please refresh the page and try again.', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */
            'label_paypal_client_id_missing' => __('PayPal %s Client ID is not configured.', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */
            'label_paypal_secret_missing' => __('PayPal %s Secret is not configured.', 'modern-hotel-booking'),
            'label_stripe_intent_missing' => __('Stripe payment failed: Payment intent missing.', 'modern-hotel-booking'),
            /* translators: %s: Error message */
            'label_stripe_generic_error' => __('Stripe API error: %s', 'modern-hotel-booking'),
            'label_paypal_id_missing' => __('PayPal payment failed: Order ID missing.', 'modern-hotel-booking'),
            'label_payment_required' => __('Payment is required.', 'modern-hotel-booking'),
            'label_api_not_configured' => __('API key has not been configured. Set it in Hotel Booking → Settings.', 'modern-hotel-booking'),
            'label_invalid_api_key' => __('Invalid or missing API key.', 'modern-hotel-booking'),
            'label_webhook_sig_required' => __('Webhook signature required. Unauthorized requests are rejected.', 'modern-hotel-booking'),
            'label_stripe_webhook_secret_missing' => __('Stripe webhook secret not configured. Please set it in Settings.', 'modern-hotel-booking'),
            'label_invalid_stripe_sig_format' => __('Invalid Stripe signature format.', 'modern-hotel-booking'),
            'label_webhook_expired' => __('Webhook timestamp outside acceptable range.', 'modern-hotel-booking'),
            'label_invalid_stripe_sig' => __('Invalid Stripe webhook signature.', 'modern-hotel-booking'),
            'label_missing_paypal_headers' => __('Missing required PayPal webhook headers.', 'modern-hotel-booking'),
            'label_invalid_customer' => __('Valid customer name and email are required.', 'modern-hotel-booking'),
            'label_invalid_dates' => __('Invalid booking dates.', 'modern-hotel-booking'),
            'label_booking_failed' => __('Failed to create the booking.', 'modern-hotel-booking'),
            'label_permission_denied' => __('Permission denied.', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */
            'label_stripe_pk_missing' => __('Stripe %s Publishable Key is not configured.', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */
            'label_stripe_sk_missing' => __('Stripe %s Secret Key is not configured.', 'modern-hotel-booking'),
            /* translators: %1$s: Expected key prefix, %2$s: Environment mode */
            'label_stripe_invalid_pk_format' => __('Invalid publishable key format. Expected key starting with "%1$s" for %2$s mode.', 'modern-hotel-booking'),
            'label_credentials_spaces' => __('Credentials contain extra spaces', 'modern-hotel-booking'),
            'label_mode_mismatch' => __('Using Sandbox credentials in Live mode (or vice versa)', 'modern-hotel-booking'),
            'label_credentials_expired' => __('Credentials have expired or been rotated', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */
            'label_creds_valid_env' => __('PayPal %s credentials are valid!', 'modern-hotel-booking'),
            /* translators: %s: Environment (Sandbox/Live) */
            'label_stripe_creds_valid' => __('Stripe %s credentials are valid!', 'modern-hotel-booking'),
            /* translators: %s: Error message */
            'label_connection_failed' => __('Connection failed: %s', 'modern-hotel-booking'),
            /* translators: %s: Error message */
            'label_auth_failed_env' => __('Authentication failed: %s', 'modern-hotel-booking'),
            'label_common_causes' => __('Common causes:', 'modern-hotel-booking'),
        );
    }

    /**
     * Register a string for translation with WPML/Polylang
     *
     * @param string $name String name/identifier
     * @param string $value String value
     * @param string $context Context/package name
     */
    public static function register_string($name, $value, $context = 'Modern Hotel Booking'): void
    {
        $plugin = self::detect_plugin();

        if ('wpml' === $plugin) {
            do_action('wpml_register_single_string', $context, $name, $value); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML action
        } elseif ('polylang' === $plugin) {
            if (function_exists('pll_register_string')) {
                call_user_func('pll_register_string', $name, $value, $context);
            }
        }
    }

    /**
     * Get a translated string from WPML/Polylang
     *
     * @param string $name String name/identifier
     * @param string $default Default value if not translated
     * @param string $context Context/package name
     * @param string|null $language Language code (optional)
     * @return string Translated string
     */
    public static function get_translated_string($name, $default = '', $context = 'Modern Hotel Booking', $language = null): string
    {
        $plugin = self::detect_plugin();

        if (null === $language) {
            $language = self::get_current_language();
        }

        if ('wpml' === $plugin) {
            $translated = apply_filters('wpml_translate_single_string', $default, $context, $name, $language); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML filter
            return !empty($translated) ? $translated : $default;
        } elseif ('polylang' === $plugin) {
            if (function_exists('pll__')) {
                $translated = call_user_func('pll__', $default);
                return !empty($translated) ? $translated : $default;
            }
        }

        $decoded = self::decode($default, $language);
        return !empty($decoded) ? $decoded : $default;
    }

    /**
     * Register all plugin strings for translation
     * Should be called on plugin activation or admin init
     */
    public static function register_plugin_strings(): void
    {
        // Register tax-related strings
        $tax_label = get_option('mhbo_tax_label', 'VAT');
        self::register_string('Tax Label', $tax_label, 'MHBO Tax Settings');

        // Register email template subjects and messages
        $statuses = ['pending', 'confirmed', 'cancelled', 'payment'];
        foreach ($statuses as $status) {
            $subject = get_option("mhbo_email_{$status}_subject", '');
            if (!empty($subject)) {
                self::register_string("Email {$status} Subject", $subject, 'MHBO Email Templates');
            }
            $message = get_option("mhbo_email_{$status}_message", '');
            if (!empty($message)) {
                self::register_string("Email {$status} Message", $message, 'MHBO Email Templates');
            }
        }

        // Register frontend labels
        $labels = self::get_all_default_labels();
        foreach ($labels as $key => $default_val) {
            $label = get_option("mhbo_label_{$key}", '');
            if (!empty($label)) {
                self::register_string("Label: {$key}", $label, 'MHBO Frontend Labels');
            } else {
                self::register_string("Label: {$key}", $default_val, 'MHBO Frontend Labels');
            }
        }
    }

    /**
     * Translate a booking status slug.
     *
     * @param string $status
     * @return string
     */
    public static function translate_status($status): string
    {
        switch ($status) {
            case 'pending':
                return __('Pending', 'modern-hotel-booking');
            case 'confirmed':
                return __('Confirmed', 'modern-hotel-booking');
            case 'cancelled':
                return __('Cancelled', 'modern-hotel-booking');
            default:
                return ucfirst($status);
        }
    }

    /**
     * Translate a payment method slug.
     *
     * @param string $method
     * @return string
     */
    public static function translate_payment_method($method): string
    {
        switch ($method) {
            case 'onsite':
            case 'arrival':
                return __('Onsite / Manual', 'modern-hotel-booking');
            case 'stripe':
                return __('Stripe', 'modern-hotel-booking');
            case 'paypal':
                return __('PayPal', 'modern-hotel-booking');
            default:
                return ucfirst($method);
        }
    }

    /**
     * Translate a payment status slug.
     *
     * @param string $status
     * @return string
     */
    public static function translate_payment_status($status): string
    {
        switch ($status) {
            case 'pending':
                return __('Pending', 'modern-hotel-booking');
            case 'processing':
                return __('Processing', 'modern-hotel-booking');
            case 'completed':
                return __('Completed', 'modern-hotel-booking');
            case 'failed':
                return __('Failed', 'modern-hotel-booking');
            case 'refunded':
                return __('Refunded', 'modern-hotel-booking');
            default:
                return ucfirst($status);
        }
    }

    /**
     * Check if a currency code is a valid ISO-4217 code.
     *
     * This list includes common currencies supported by major payment processors (Stripe/PayPal).
     *
     * @param string $code 3-letter currency code.
     * @return bool
     */
    public static function is_valid_currency($code): bool
    {
        $code = strtoupper(trim($code));
        $valid_codes = [
            'USD',
            'EUR',
            'GBP',
            'JPY',
            'CAD',
            'AUD',
            'CHF',
            'CNY',
            'SEK',
            'NZD',
            'KRW',
            'SGD',
            'NOK',
            'MXN',
            'INR',
            'RUB',
            'ZAR',
            'TRY',
            'BRL',
            'TWD',
            'DKK',
            'PLN',
            'THB',
            'IDR',
            'HUF',
            'CZK',
            'ILS',
            'CLP',
            'PHP',
            'AED',
            'COP',
            'SAR',
            'MYR',
            'RON',
            'VND',
            'ARS',
            'EGP',
            'IRR',
            'KWD',
            'LKR',
            'UAH',
            'VEF',
            'HNL',
            'GTQ',
            'CRC',
            'DOP',
            'PEN',
            'UYU',
            'PYG',
            'BOB',
            'NIO',
            'ISK',
            'HRK',
            'BGN',
            'RON',
            'LVL',
            'LTL',
            'EEK',
            'SKK',
            'SIT',
            'CYP',
            'MTL',
            'TZS',
            'UGX',
            'KES',
            'GHS',
            'NGN',
            'ZMW',
            'MUR',
            'SCR',
            'MGA',
            'MAD',
            'TND',
            'DZD',
            'EGP',
            'QAR',
            'OMR',
            'BHD',
            'JOD',
            'LBP',
            'AMD',
            'AZN',
            'GEL',
            'KZT',
            'UZS',
            'TJS',
            'KGS',
            'AFN',
            'PKR',
            'BDT',
            'NPR',
            'MVR',
            'MMK',
            'LAK',
            'KHR',
            'MOP',
            'HKD',
            'FJD',
            'XPF',
            'XAF',
            'XOF',
            'XCD',
            'ANG',
            'AWG',
            'BBD',
            'BSD',
            'BZD',
            'BMD',
            'GIP',
            'JMD',
            'KYD',
            'LRD',
            'SBD',
            'SRD',
            'TOP',
            'TTD',
            'VUV',
            'WST',
            'XDR'
        ];
        return in_array($code, $valid_codes, true);
    }
}