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
 * Incorrect: __ ( ' . variable . ' )
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

/**
     * I18n class handles all localization and translation logic.
     */
    class I18n
    {
    /**
     * Initialize translation filters.
     *
     * @return void
     */
    public static function init(): void
    {
        // WordPress 4.6+ auto-loads translations for plugins hosted on WP.org.
        // load_plugin_textdomain() is no longer required and is discouraged by Plugin Check.
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
        if ('' === trim($translated)) {
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
        // Handle admin side language selection via URL param (e.g., ?lang=ro).
        // Uses filter_input() to avoid nonce-verification warnings — this is a
        // read-only display parameter that does not change state.
        $lang_param = filter_input(INPUT_GET, 'lang', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (is_admin() && '' !== (string) $lang_param) {
            return sanitize_key($lang_param);
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
                return isset($qtranslate_config['enabled_languages']) ? $qtranslate_config['enabled_languages'] : [self::locale_code()];

            case 'wpml':
                $langs = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook
                return is_array($langs) ? array_keys($langs) : [self::locale_code()];

            case 'polylang':
                if (function_exists('pll_languages_list')) {
                    $list = call_user_func('pll_languages_list', ['fields' => 'slug']);
                    return [] !== $list ? $list : [self::locale_code()];
                }
                return [self::locale_code()];

            default:
                $langs = array_unique(['en', self::locale_code()]);
                return (array) apply_filters('mhbo_i18n_get_available_languages', array_values($langs));
        }
    }

    /**
     * Get qTranslate-XT configuration (third-party global).
     *
     * @return array<string, mixed>
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
     * @param mixed       $text The value to decode.
     * @param string|null $lang Optional language code.
     * @param bool        $fallback Whether to fallback to other languages if requested is missing.
     * @return string|null
     */
    public static function decode(mixed $text, ?string $lang = null, bool $fallback = true): ?string
    {
        if ($text === '' || !is_string($text)) {
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
            return trim($text);
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
        if (isset($map[$lang]) && '' !== $map[$lang]) {
            return $map[$lang];
        }

        // 2. Try short code match (e.g. 'en' for 'en_US')
        if (isset($map[$lang_short]) && '' !== $map[$lang_short]) {
            return $map[$lang_short];
        }

        // 3. Fallback logic (WP 7.0+ Future-Proofing)
        if ($fallback) {
            $default = strtolower(self::get_default_language());
            $default_short = substr($default, 0, 2);

            // Try site default (full then short)
            if (isset($map[$default])) {
                return apply_filters('mhbo_i18n_decode', $map[$default], $text, $lang);
            }
            if (isset($map[$default_short])) {
                return apply_filters('mhbo_i18n_decode', $map[$default_short], $text, $lang);
            }

            // Fallback to English (Global Standard)
            if (isset($map['en'])) {
                return apply_filters('mhbo_i18n_decode', $map['en'], $text, $lang);
            }

            // Final fallback to any first available non-empty translation
            foreach ($map as $val) {
                if ('' !== $val) {
                    return apply_filters('mhbo_i18n_decode', $val, $text, $lang);
                }
            }
        }

        // 4. Last resort: strip qTranslate tags and return result
        $result = preg_replace('/\[:([a-z]{2}(?:_[a-z]{2})?)\]/i', '', $text);
        $result = str_replace('[:]', '', $result);

        return apply_filters('mhbo_i18n_decode', trim($result) ?: $text, $text, $lang);
    }

    /**
     * Encode an array of [lang => text] into a multilingual string.
     *
     * @param mixed $values The values to encode (typically an associative array).
     * @return string The encoded multilingual string.
     */
    public static function encode(mixed $values): string
    {
        if (!is_array($values)) {
            return (string) $values;
        }
        $out = '';
        foreach ($values as $lang => $text) {
            if ('' !== $text) {
                $out .= "[:{$lang}]{$text}";
            }
        }
        if ('' !== $out) {
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
    public static function translate_and_decode(string $translated, ?string $language = null): string
    {
        // If translation is empty, return as-is
        if ('' === $translated) {
            return $translated;
        }

        // If the translated string contains multilingual format, decode it
        if (false !== strpos($translated, '[:')) {
            $decoded = self::decode($translated, $language);
            if (null !== $decoded && '' !== $decoded) {
                return $decoded;
            }
        }

        return $translated;
    }

    /**
     * Format currency based on site settings.
     *
     * @param float|int|string|Money $amount The numeric amount to format.
     * @return string The formatted currency string.
     */
    public static function format_currency(mixed $amount, ?int $decimals_override = null): string
    {
        $decimal_separator = (string) apply_filters('mhbo_currency_decimal_separator', '.');
        $thousand_separator = (string) apply_filters('mhbo_currency_thousand_separator', ',');
        $symbol = (string) get_option('mhbo_currency_symbol', '$');
        $position = (string) get_option('mhbo_currency_position', 'before');

        // Handle arrays from legacy or malformed JSON serialization
        if (is_array($amount)) {
            if (isset($amount['amount_in_cents']) && isset($amount['currency'])) {
                $amount = Money::fromCents($amount['amount_in_cents'], $amount['currency']);
            } else {
                $amount = 0; // Default fallback for entirely invalid data
            }
        }

        if ($amount instanceof Money) {
            $decimals = $decimals_override ?? (int) apply_filters('mhbo_currency_decimals', $amount->getCurrencyDecimals());
            $formatted = number_format((float) $amount->toDecimal(), $decimals, $decimal_separator, $thousand_separator);
        } else {
            $decimals = $decimals_override ?? (int) apply_filters('mhbo_currency_decimals', 2);
            $formatted = number_format((float) ($amount ?? 0), $decimals, $decimal_separator, $thousand_separator);
        }

        // Smart Space Logic: Add space if symbol is multi-character or alphanumeric
        $add_space = strlen($symbol) > 1 || preg_match('/^[a-zA-Z0-9]+$/', $symbol);
        $add_space = (bool) apply_filters('mhbo_currency_add_space', $add_space, $symbol);
        $space = $add_space ? ' ' : '';

        $result = ($position === 'before') ? $symbol . $space . $formatted : $formatted . $space . $symbol;

        return (string) apply_filters('mhbo_currency_format', $result, $amount, $symbol, $position);
    }

    /**
     * Format a date string based on WP settings.
     *
     * @param string $date_string The date string to format (Y-m-d).
     * @return string The localized date string.
     */
    public static function format_date(string $date_string): string
    {
        if ('' === $date_string) {
            return '';
        }
        return date_i18n(get_option('date_format'), strtotime($date_string));
    }

/**
     * Get a localized label for a payment method.
     *
     * @param string|null $method The payment method key.
     * @return string The localized label.
     */
    public static function get_payment_method_label(?string $method): string
    {
        if (null === $method || '' === $method) {
            $method = 'arrival';
        }
        return apply_filters('mhbo_payment_method_label', match ($method) {
            'stripe'  => __('Stripe', 'modern-hotel-booking'),
            'paypal'  => __('PayPal', 'modern-hotel-booking'),
            'arrival', 'onsite' => __('Pay on Arrival / Manual', 'modern-hotel-booking'),
            default   => ucfirst($method)
        }, $method);
    }

    /**
     * Get a localized label for front-end/admin use.
     *
     * @param string $key The label key.
     * @return string The localized label.
     */
    public static function get_label(string $key): string
    {
        // Check for database override first
        $override = get_option("mhbo_label_{$key}");

        $labels = self::get_all_default_labels();
        $default_val = isset($labels[$key]) ? $labels[$key] : $key;

        $value = (false !== $override && '' !== $override) ? $override : $default_val;

        // Check for translated string via WPML/Polylang
        $translated = self::get_translated_string("Label: {$key}", $value, 'MHBO Frontend Labels');

        // If translation is found and not empty, decode it (it might still be qTranslate format)
        if ('' !== $translated) {
            $decoded = self::decode($translated);
            if (null !== $decoded && '' !== $decoded) {
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
            // translators: %d: number of guests
            'label_guests_count' => __('Guests x %d', 'modern-hotel-booking'),
            // translators: %d: number of children
            'label_children_count' => __('Children x %d', 'modern-hotel-booking'),
            // translators: %d: number of nights
            'label_nights_count' => __('%d Nights', 'modern-hotel-booking'),
            'label_nights_count_single' => __('1 Night', 'modern-hotel-booking'),
            'label_night' => __('Night', 'modern-hotel-booking'),
            'label_nights' => __('Nights', 'modern-hotel-booking'),
            'label_stay_details' => __('Stay Details', 'modern-hotel-booking'),
            'label_guest' => __('Guest', 'modern-hotel-booking'),
            'label_room_number' => __('Room Number', 'modern-hotel-booking'),
            // translators: 1: check-in date, 2: check-out date
            'label_available_rooms' => __('Available Rooms from %1$s to %2$s', 'modern-hotel-booking'),
            'label_no_rooms' => __('No rooms available for these dates.', 'modern-hotel-booking'),
            'label_checkout_only' => __('This date is restricted to check-outs only.', 'modern-hotel-booking'),
            'label_checkin_only' => __('This date is restricted to check-ins only.', 'modern-hotel-booking'),
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
            'msg_booking_confirmed_received' => __('Booking Pending', 'modern-hotel-booking'),
            'msg_confirmation_sent' => __('A confirmation email has been sent to you.', 'modern-hotel-booking'),
            // translators: %s: Customer email address
            'msg_confirmation_sent_to' => __('A confirmation email has been sent to %s.', 'modern-hotel-booking'),
            // translators: %s: Customer email address
            'msg_pending_sent_to' => __('A copy of this pending booking was sent to %s. We will contact you shortly.', 'modern-hotel-booking'),
            'label_reservation' => __('RESERVATION', 'modern-hotel-booking'),
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
            'label_check_in_time' => __('Check-in Time', 'modern-hotel-booking'),
            'label_check_out_time' => __('Check-out Time', 'modern-hotel-booking'),
            'label_select_dates' => __('Select Dates', 'modern-hotel-booking'),
            'label_your_selection' => __('Your Selection', 'modern-hotel-booking'),
            'label_continue_booking' => __('Continue to Booking', 'modern-hotel-booking'),
            'label_dates_selected' => __('Dates selected. Complete the form below.', 'modern-hotel-booking'),
            'label_credit_card' => __('Credit Card', 'modern-hotel-booking'),
            'label_paypal' => __('PayPal', 'modern-hotel-booking'),
            'label_confirm_request' => __('Click below to confirm your booking request.', 'modern-hotel-booking'),
            // translators: %s: tax name (e.g., VAT)
            'label_tax_breakdown' => __('Reservation Summary', 'modern-hotel-booking'),
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
            'label_room_not_available' => __('Room not Available', 'modern-hotel-booking'),
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
            
            // translators: %s: Tax rate percentage
            'label_tax_note_includes' => __('Price includes %s', 'modern-hotel-booking'),
            // translators: %s: Tax rate percentage
            'label_tax_note_plus' => __('Price plus %s', 'modern-hotel-booking'),
            // translators: %1$s: Tax label, %2$s: Tax rate percentage
            'label_tax_note_includes_multi' => __('Price includes %1$s (%2$s%%)', 'modern-hotel-booking'),
            // translators: %1$s: Tax label, %2$s: Tax rate percentage
            'label_tax_note_plus_multi' => __('Price plus %1$s (%2$s%%)', 'modern-hotel-booking'),
            'label_select_dates_error' => __('Please select check-in and check-out dates.', 'modern-hotel-booking'),
            'label_legend_confirmed' => __('Booked', 'modern-hotel-booking'),
            'label_legend_pending' => __('Pending', 'modern-hotel-booking'),
            'label_legend_available' => __('Available', 'modern-hotel-booking'),
            'label_room_type' => __('Room Type', 'modern-hotel-booking'),
            'label_all_types' => __('All Types', 'modern-hotel-booking'),
            'label_total_starting_from' => __('Total (Starting from)', 'modern-hotel-booking'),
            'label_block_no_room' => __('Please select a Room ID in block settings.', 'modern-hotel-booking'),
            'label_check_in_past' => __('Check-in date cannot be in the past.', 'modern-hotel-booking'),
            'label_check_out_after' => __('Check-out date must be after check-in date.', 'modern-hotel-booking'),
            'label_check_in_future' => __('Check-in date cannot be more than 2 years in the future.', 'modern-hotel-booking'),
            'label_check_out_future' => __('Check-out date cannot be more than 2 years in the future.', 'modern-hotel-booking'),
            'label_name_too_long' => __('Name is too long (maximum 100 characters).', 'modern-hotel-booking'),
            'label_phone_too_long' => __('Phone number is too long (maximum 30 characters).', 'modern-hotel-booking'),
            // translators: %d: Maximum number of children
            'label_max_children_error' => __('Error: Maximum children for this room is %d.', 'modern-hotel-booking'),
            'label_price_calc_error' => __('Error calculating price. Please check dates.', 'modern-hotel-booking'),
            'label_fill_all_fields' => __('Please fill in all required fields.', 'modern-hotel-booking'),
            'label_invalid_email' => __('Please provide a valid email address.', 'modern-hotel-booking'),
            // translators: %s: Field name
            'label_field_required' => __('The field "%s" is required.', 'modern-hotel-booking'),
            'label_spam_detected' => __('Spam detected.', 'modern-hotel-booking'),
            'label_already_booked' => __('Sorry, this room was just booked by someone else or is unavailable for these dates.', 'modern-hotel-booking'),
            // translators: %d: Maximum number of adults
            'label_max_adults_error' => __('Error: Maximum adults for this room is %d.', 'modern-hotel-booking'),
            'label_rest_pro_error' => __('REST API access is a Pro feature.', 'modern-hotel-booking'),
            'msg_pro_required' => __('This feature requires a Pro licence.', 'modern-hotel-booking'),
            'msg_missing_api_key' => __('API key is missing. Please include your API key in the request.', 'modern-hotel-booking'),
            'msg_invalid_api_key' => __('Invalid API key. Please check your credentials.', 'modern-hotel-booking'),
            'label_booking_busy' => __('The room is currently being booked. Please try again in a moment.', 'modern-hotel-booking'),
            'label_booking_success' => __('Booking created successfully.', 'modern-hotel-booking'),
            'label_calculation_failed' => __('Unable to calculate the price for the selected dates. Please try again.', 'modern-hotel-booking'),
            'label_invalid_child_age' => __('Child age must be between 0 and 17 years.', 'modern-hotel-booking'),
            'label_missing_child_ages' => __('Please provide an age for each child.', 'modern-hotel-booking'),
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
            // translators: %s: Currency code
            'label_paypal_currency_unsupported' => __('Currency %s is not supported by your PayPal account.', 'modern-hotel-booking'),
            // translators: %s: Error message
            'label_paypal_generic_error' => __('PayPal error: %s', 'modern-hotel-booking'),
            'label_missing_order_id' => __('Missing order ID.', 'modern-hotel-booking'),
            'label_paypal_capture_error' => __('Unable to capture payment. Please try again later.', 'modern-hotel-booking'),
            'label_payment_already_processed' => __('This payment has already been processed.', 'modern-hotel-booking'),
            'label_payment_declined_paypal' => __('The payment was declined by PayPal. Please try a different payment method.', 'modern-hotel-booking'),
            'label_setup_failed' => __('Payment setup failed.', 'modern-hotel-booking'),
            'label_payment_already_confirmed' => __('Payment already confirmed. Finalizing your booking...', 'modern-hotel-booking'),
            'label_finalizing' => __('Finalizing...', 'modern-hotel-booking'),
            'label_gateway_not_ready' => __('Credit card payment system is not ready. Please refresh the page or choose another method.', 'modern-hotel-booking'),
            'label_payment_success_form_fail' => __('Payment successful but form submission failed. Please contact support.', 'modern-hotel-booking'),
            'label_payment_cancelled' => __('Payment cancelled.', 'modern-hotel-booking'),
            'label_redirecting' => __('Redirecting...', 'modern-hotel-booking'),
            'label_loading_payment' => __('Loading secure payment form...', 'modern-hotel-booking'),
            'label_payment_capture_failed' => __('Payment capture failed: ', 'modern-hotel-booking'),
            'label_payment_confirmation' => __('Payment Confirmation', 'modern-hotel-booking'),
            'label_privacy_policy' => __('privacy policy', 'modern-hotel-booking'),
            'label_terms_conditions' => __('Terms & Conditions', 'modern-hotel-booking'),
            'gdpr_checkbox_text' => __('I have read and agree to the [privacy_policy].', 'modern-hotel-booking'),
            'label_payment_info' => __('Payment Information', 'modern-hotel-booking'),
            
            'msg_pay_on_arrival_email' => __('Payment will be collected upon arrival at the property.', 'modern-hotel-booking'),
            'label_amount_due' => __('Amount Due', 'modern-hotel-booking'),
            'label_payment_date' => __('Payment Date', 'modern-hotel-booking'),
            'label_paypal_order_failed' => __('Failed to create PayPal order.', 'modern-hotel-booking'),
            'label_security_verification_failed' => __('Security verification failed. Please refresh the page and try again.', 'modern-hotel-booking'),
            // translators: %s: Environment (Sandbox/Live)
            'label_paypal_client_id_missing' => __('PayPal %s Client ID is not configured.', 'modern-hotel-booking'),
            // translators: %s: Environment (Sandbox/Live)
            'label_paypal_secret_missing' => __('PayPal %s Secret is not configured.', 'modern-hotel-booking'),
            'label_stripe_intent_missing' => __('Stripe payment failed: Payment intent missing.', 'modern-hotel-booking'),
            // translators: %s: Error message
            'label_stripe_generic_error' => __('Stripe API error: %s', 'modern-hotel-booking'),
            'label_paypal_id_missing' => __('PayPal payment failed: Order ID missing.', 'modern-hotel-booking'),
            'label_payment_required' => __('Payment is required.', 'modern-hotel-booking'),
            'label_api_not_configured' => __('API key has not been configured. Set it in Hotel Booking → Settings.', 'modern-hotel-booking'),
            'label_no_room_available_auto' => __('No available room could be resolved for your selection. Please try different dates or contact us.', 'modern-hotel-booking'),
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
            'label_booking_error' => __('An error occurred while processing your booking. Please try again.', 'modern-hotel-booking'),
            'label_permission_denied' => __('Permission denied.', 'modern-hotel-booking'),
            // translators: %s: Environment (Sandbox/Live)
            'label_stripe_pk_missing' => __('Stripe %s Publishable Key is not configured.', 'modern-hotel-booking'),
            // translators: %s: Environment (Sandbox/Live)
            'label_stripe_sk_missing' => __('Stripe %s Secret Key is not configured.', 'modern-hotel-booking'),
            // translators: %1$s: Expected key prefix, %2$s: Environment mode
            'label_stripe_invalid_pk_format' => __('Invalid publishable key format. Expected key starting with "%1$s" for %2$s mode.', 'modern-hotel-booking'),
            'label_credentials_spaces' => __('Credentials contain extra spaces', 'modern-hotel-booking'),
            'label_mode_mismatch' => __('Using Sandbox credentials in Live mode (or vice versa)', 'modern-hotel-booking'),
            'label_credentials_expired' => __('Credentials have expired or been rotated', 'modern-hotel-booking'),
            // translators: %s: Environment (Sandbox/Live)
            'label_creds_valid_env' => __('PayPal %s credentials are valid!', 'modern-hotel-booking'),
            // translators: %s: Environment (Sandbox/Live)
            'label_stripe_creds_valid' => __('Stripe %s credentials are valid!', 'modern-hotel-booking'),
            // translators: %s: Error message
            'label_connection_failed' => __('Connection failed: %s', 'modern-hotel-booking'),
            // translators: %s: Error message
            'label_auth_failed_env' => __('Authentication failed: %s', 'modern-hotel-booking'),
            'label_common_causes' => __('Common causes:', 'modern-hotel-booking'),
            'label_booking_id' => __('Booking ID', 'modern-hotel-booking'),
            'label_reference_number' => __('Reference Number', 'modern-hotel-booking'),
            'label_amenities' => __('Amenities', 'modern-hotel-booking'),
            'btn_view_room' => __('View Room Details', 'modern-hotel-booking'),
            'label_back_to_rooms' => __('Back to Rooms List', 'modern-hotel-booking'),
            'btn_select_room' => __('Select This Room', 'modern-hotel-booking'),
            'label_room_details' => __('Room Summary & Amenities', 'modern-hotel-booking'),
            'label_guest_details' => __('Guest Contact Information', 'modern-hotel-booking'),
            'label_payment_details' => __('Payment & Billing Details', 'modern-hotel-booking'),
            'label_adults' => __('Adults', 'modern-hotel-booking'),
            'label_adult' => __('Adult', 'modern-hotel-booking'),
            'label_children_count_simple' => __('Children', 'modern-hotel-booking'),
            'label_child' => __('Child', 'modern-hotel-booking'),
            'label_no_extras' => __('No extras selected.', 'modern-hotel-booking'),
            'label_total_with_tax' => __('Total (including taxes)', 'modern-hotel-booking'),
            'label_booking_dates' => __('Reservation Dates', 'modern-hotel-booking'),

);
    }

    /**
     * Register a string for translation with WPML/Polylang.
     *
     * @param string $name    String name/identifier.
     * @param string $value   String value.
     * @param string $context Context/package name.
     * @return void
     */
    public static function register_string(string $name, string $value, string $context = 'Modern Hotel Booking'): void
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
    public static function get_translated_string(string $name, string $default = '', string $context = 'Modern Hotel Booking', ?string $language = null): string
    {
        $plugin = self::detect_plugin();

        if (null === $language) {
            $language = self::get_current_language();
        }

        if ('wpml' === $plugin) {
            $translated = apply_filters('wpml_translate_single_string', $default, $context, $name, $language); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML filter
            return '' !== $translated ? $translated : $default;
        } elseif ('polylang' === $plugin) {
            if (function_exists('pll__')) {
                $translated = call_user_func('pll__', $default);
                return '' !== $translated ? $translated : $default;
            }
        }

        $decoded = self::decode($default, $language);
        return (null !== $decoded && '' !== $decoded) ? $decoded : $default;
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
            if ('' !== $subject) {
                self::register_string("Email {$status} Subject", $subject, 'MHBO Email Templates');
            }
            $message = get_option("mhbo_email_{$status}_message", '');
            if ('' !== $message) {
                self::register_string("Email {$status} Message", $message, 'MHBO Email Templates');
            }
        }

        // Register frontend labels
        $labels = self::get_all_default_labels();
        foreach ($labels as $key => $default_val) {
            $label = get_option("mhbo_label_{$key}", '');
            if ('' !== $label) {
                self::register_string("Label: {$key}", $label, 'MHBO Frontend Labels');
            } else {
                self::register_string("Label: {$key}", $default_val, 'MHBO Frontend Labels');
            }
        }
    }

    /**
     * Translate a booking status slug.
     *
     * @param string $status The status slug.
     * @return string The translated status.
     */
    public static function translate_status(string $status): string
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
     * @param string $method The payment method slug.
     * @return string The translated payment method.
     */
    public static function translate_payment_method(string $method): string
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
    public static function is_valid_currency(string $code): bool
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
