<?php declare(strict_types=1);

namespace MHBO\Admin;

use MHBO\Core\Capabilities;
use MHBO\Core\HotelTime;

use MHBO\Core\Security;
if ( ! defined('ABSPATH')) {
    exit;
}

use MHBO\Core\I18n;

class Settings {

    public function init(): void {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'process_settings_save'));
        add_action('admin_init', array($this, 'register_wpml_polylang_strings'));
        
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

/**
     * Enqueue admin settings scripts.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_scripts( mixed $hook = '' ): void {
        $hook = is_string( $hook ) ? $hook : '';
        // Only load on settings and pro pages
        if (false === strpos($hook, 'mhbo-settings') && false === strpos($hook, 'mhbo-pro')) {
            return;
        }

        wp_enqueue_script(
            'mhbo-admin-settings',
            \MHBO\Core\Plugin::asset_url('assets/js/mhbo-admin-settings.js'),
            array('wp-api-fetch'),
            \MHBO\Core\Plugin::asset_ver('assets/js/mhbo-admin-settings.js'),
            true
        );

        // Get active languages for custom fields
        $langs = I18n::get_available_languages();
        $lang_labels = array();
        foreach ($langs as $lang) {
            $lang_labels[ $lang ] = $lang;
        }

        // Inject configuration
        $config = array(
            'restUrl'   => rest_url('mhbo/v1'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'nonces' => array(
                
            ),
            'i18n' => array(
                
                'connection_error' => I18n::get_label('connection_error'),
                'are_you_sure' => I18n::get_label('settings_msg_are_you_sure'),
                'remove_field_confirm' => I18n::get_label('settings_msg_remove_field'),
                'no_holidays' => I18n::get_label('settings_msg_no_holidays'),
                'clearing_cache' => I18n::get_label('performance_msg_clearing'),
                'cache_cleared' => I18n::get_label('performance_msg_cleared'),
            ),
            'langLabels' => $lang_labels,
        );
        wp_add_inline_script('mhbo-admin-settings', 'window.mhboAdminSettingsConfig = ' . wp_json_encode($config) . ';', 'before');
    }

    /**
     * Register strings for WPML/Polylang translation
     */
    public function register_wpml_polylang_strings(): void {
        I18n::register_plugin_strings();
    }

    public function register_settings(): void {
        // General Settings
        register_setting('mhbo_settings_group', 'mhbo_checkin_time', array('type' => 'string', 'default' => '14:00', 'sanitize_callback' => array($this, 'sanitize_hhmm_option')));
        register_setting('mhbo_settings_group', 'mhbo_checkout_time', array('type' => 'string', 'default' => '11:00', 'sanitize_callback' => array($this, 'sanitize_hhmm_option')));
        register_setting('mhbo_settings_group', 'mhbo_hotel_timezone', array('type' => 'string', 'sanitize_callback' => array($this, 'sanitize_timezone'), 'default' => ''));
        register_setting('mhbo_settings_group', 'mhbo_force_frontend_locale', array('type' => 'string', 'sanitize_callback' => array($this, 'sanitize_forced_locale'), 'default' => ''));
        register_setting('mhbo_settings_group', 'mhbo_notification_email', array('default' => get_option('admin_email'), 'sanitize_callback' => array($this, 'sanitize_notification_email')));
        register_setting('mhbo_settings_group', 'mhbo_additional_notification_email', array('default' => '', 'sanitize_callback' => array($this, 'sanitize_additional_notification_emails')));
        register_setting('mhbo_settings_group', 'mhbo_modal_enabled', array('default' => 1, 'sanitize_callback' => 'absint'));
        
        // Global Stay Limits
        register_setting('mhbo_settings_group', 'mhbo_global_min_stay', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_global_max_stay', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_max_advance_booking_days', array('type' => 'integer', 'sanitize_callback' => array($this, 'sanitize_advance_booking_days'), 'default' => 365));

        // Guest Portal & Digital Compendium
        register_setting('mhbo_settings_group', 'mhbo_guest_portal_page', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_portal_self_service_enabled', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_portal_modify_mode', array('type' => 'string', 'sanitize_callback' => array(\MHBO\Api\GuestPortalController::class, 'sanitize_policy_mode'), 'default' => 'request_only'));
        register_setting('mhbo_settings_group', 'mhbo_portal_cancel_mode', array('type' => 'string', 'sanitize_callback' => array(\MHBO\Api\GuestPortalController::class, 'sanitize_policy_mode'), 'default' => 'request_only'));
        register_setting('mhbo_settings_group', 'mhbo_cancellation_lead_hours', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 24));
        register_setting('mhbo_settings_group', 'mhbo_portal_max_modifications', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 2));
        register_setting('mhbo_settings_group', 'mhbo_cancellation_policy_enabled', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_cancellation_policy', array('type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => ''));
        register_setting('mhbo_settings_group', 'mhbo_booking_terms_checkbox_enabled', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_label_booking_terms_checkbox_text', array('type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'default' => ''));
        
        register_setting('mhbo_settings_group', 'mhbo_hotel_wifi_ssid', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting('mhbo_settings_group', 'mhbo_hotel_wifi_password', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting('mhbo_settings_group', 'mhbo_hotel_guest_instructions', array('type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'default' => ''));

// Currency Settings
        register_setting('mhbo_settings_group', 'mhbo_currency_code', array('type' => 'string', 'default' => 'USD', 'sanitize_callback' => array($this, 'sanitize_currency_code')));
        register_setting('mhbo_settings_group', 'mhbo_currency_symbol', array('type' => 'string', 'default' => '$', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_currency_position', array('type' => 'string', 'default' => 'before', 'sanitize_callback' => array($this, 'sanitize_currency_position')));
        register_setting('mhbo_settings_group', 'mhbo_price_decimals', array('default' => '2', 'sanitize_callback' => array($this, 'sanitize_price_decimals')));
        register_setting('mhbo_settings_group', 'mhbo_calendar_show_decimals', array('default' => 0, 'sanitize_callback' => 'absint'));

        // Note: Multilingual label/email options (mhbo_label_*, mhbo_email_*) are dynamically
        // generated and sanitized inline in save_multilingual_settings() using sanitize_text_field()
        // and wp_kses_post(). They are not individually registered as they are dynamic keys.

        // Custom Fields
        register_setting('mhbo_settings_group', 'mhbo_custom_fields', array('default' => array(), 'sanitize_callback' => array($this, 'sanitize_custom_fields')));
        register_setting('mhbo_settings_group', 'mhbo_terms_page', array('default' => 0, 'sanitize_callback' => 'absint'));

        // Booking Page Settings
        register_setting('mhbo_settings_group', 'mhbo_booking_page', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_booking_page_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));

// Uninstall Settings
        register_setting('mhbo_settings_group', 'mhbo_save_data_on_uninstall', array('default' => 1, 'sanitize_callback' => 'absint'));
        
        // Guest CRM Settings (v2.6.0) - Disabled by default
        register_setting('mhbo_settings_group', 'mhbo_guest_crm_enabled', array('type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'));

// Security Settings
        register_setting('mhbo_settings_group', 'mhbo_trusted_proxies', array('default' => '', 'sanitize_callback' => array(Security::class, 'sanitize_trusted_proxies')));

        // Display Settings
        register_setting('mhbo_settings_group', 'mhbo_powered_by_link', array('default' => 0, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_calendar_show_title', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_calendar_show_badge', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_booking_show_title', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_booking_show_badge', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_rooms_show_title', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_rooms_show_badge', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_room_cards_show_title', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_room_cards_show_badge', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_portal_show_title', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_portal_show_badge', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_payment_show_title', array('default' => 1, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_payment_show_badge', array('default' => 1, 'sanitize_callback' => 'absint'));

        // Performance Settings

// Theme Settings

// Amenities (Dynamic) - Handled manually with inline sanitization
        // register_setting('mhbo_settings_group', 'mhbo_amenities_list');

        add_settings_section('mhbo_general_section', I18n::get_label('settings_section_general'), '__return_null', 'mhbo-settings');
        add_settings_field('mhbo_checkin_time', I18n::get_label('settings_label_checkin'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_checkin_time'));
        add_settings_field('mhbo_checkout_time', I18n::get_label('settings_label_checkout'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_checkout_time'));
        add_settings_field('mhbo_notification_email', I18n::get_label('settings_label_notification'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_notification_email'));
        add_settings_field('mhbo_additional_notification_email', __('Additional Notification Email', 'modern-hotel-booking'), array($this, 'render_additional_notification_email_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_additional_notification_email'));
        
        add_settings_field('mhbo_hotel_timezone', __('Hotel Timezone', 'modern-hotel-booking'), array($this, 'render_hotel_timezone_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_hotel_timezone'));
        add_settings_field('mhbo_booking_page', I18n::get_label('settings_label_booking_page'), array($this, 'render_page_select_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_booking_page'));
        add_settings_field('mhbo_booking_page_url', I18n::get_label('settings_label_booking_override'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_booking_page_url'));
        
        add_settings_field('mhbo_modal_enabled', __('Enable Inline Booking Modal', 'modern-hotel-booking'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for'   => 'mhbo_modal_enabled',
            'default'     => 1,
            'description' => __('Open the booking form in a slide-in drawer instead of navigating to a separate booking page.', 'modern-hotel-booking'),
        ));
        
        add_settings_field('mhbo_global_min_stay', I18n::get_label('settings_label_global_min_stay'), array($this, 'render_global_stay_fields'), 'mhbo-settings', 'mhbo_general_section');
        
        add_settings_field('mhbo_guest_crm_enabled', __('Enable Guest CRM & Profiles', 'modern-hotel-booking'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for'   => 'mhbo_guest_crm_enabled',
            'default'     => 0,
            'description' => __('Centralize guest profiles, lifetime stay history, staff ratings, and customer intelligence.', 'modern-hotel-booking'),
        ));
        
        add_settings_field('mhbo_custom_fields', I18n::get_label('settings_label_custom_fields'), array($this, 'render_custom_fields_repeater'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_custom_fields'));
        add_settings_field('mhbo_save_data_on_uninstall', I18n::get_label('settings_label_uninstall'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for'   => 'mhbo_save_data_on_uninstall',
            'default'     => 1,
            'description' => I18n::get_label('settings_desc_uninstall'),
        ));
        add_settings_field('mhbo_powered_by_link', I18n::get_label('settings_label_powered_by'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for'   => 'mhbo_powered_by_link',
            'description' => I18n::get_label('settings_desc_powered_by'),
        ));

        add_settings_section('mhbo_security_section', I18n::get_label('settings_section_security'), '__return_null', 'mhbo-settings');
        add_settings_field('mhbo_trusted_proxies', I18n::get_label('settings_label_proxies'), array($this, 'render_textarea_field'), 'mhbo-settings', 'mhbo_security_section', array(
            'label_for'   => 'mhbo_trusted_proxies',
            'description' => I18n::get_label('settings_desc_proxies'),
        ));

        add_settings_section('mhbo_currency_section', I18n::get_label('settings_section_currency'), '__return_null', 'mhbo-settings');
        add_settings_field('mhbo_currency_code', I18n::get_label('settings_label_currency_code'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_currency_section', array('label_for' => 'mhbo_currency_code'));
        add_settings_field('mhbo_currency_symbol', I18n::get_label('settings_label_currency_symbol'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_currency_section', array('label_for' => 'mhbo_currency_symbol'));
        add_settings_field('mhbo_currency_position', I18n::get_label('settings_label_currency_pos'), array($this, 'render_select_field'), 'mhbo-settings', 'mhbo_currency_section', array(
            'label_for' => 'mhbo_currency_position',
            'options'   => array(
                'before' => I18n::get_label('settings_opt_before'),
                'after'  => I18n::get_label('settings_opt_after'),
            ),
        ));
        add_settings_field('mhbo_calendar_show_decimals', I18n::get_label('settings_label_decimals'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_currency_section', array(
            'label_for'   => 'mhbo_calendar_show_decimals',
            'description' => I18n::get_label('settings_desc_decimals'),
        ));
    }

    /**
     * Sanitize custom fields array.
     *
     * @param mixed $fields
     * @return array<int, array<string, mixed>>
     */
    public function sanitize_custom_fields( mixed $fields ): array {
        if ( ! is_array($fields)) {
            return array();
        }
        $sanitized = array();
        $allowed_types = array('text', 'number', 'textarea');
        foreach ($fields as $field) {
            if ( ! is_array($field)) {
                continue;
            }
            $raw_type = isset($field['type']) ? sanitize_key( (string) $field['type']) : 'text';
            $sanitized_field = array(
                'id' => isset($field['id']) ? sanitize_key($field['id']) : '',
                'type' => in_array($raw_type, $allowed_types, true) ? $raw_type : 'text',
                'required' => isset($field['required']) ? absint($field['required']) : 0,
            );

            if (isset($field['label']) && is_array($field['label'])) {
                foreach ($field['label'] as $lang => $label) {
                    $sanitized_field['label'][ sanitize_key($lang) ] = sanitize_text_field($label);
                }
            } else {
                // The original line was: $sanitized_field['label'] = isset($field['label']) ? sanitize_text_field($field['label']) : '';
                // The provided change was syntactically incorrect and introduced unrelated variables.
                // Reverting to the original correct and sanitized line.
                $sanitized_field['label'] = isset($field['label']) ? sanitize_text_field($field['label']) : '';
            }
            $sanitized[] = $sanitized_field;
        }
        return $sanitized;
    }

    /**
     * Sanitize price decimals setting ('0', '1', '2', '3', '4', 'auto').
     */
    public function sanitize_price_decimals( mixed $input ): string {
        $input = sanitize_text_field( (string) $input);
        return in_array($input, array('0', '1', '2', '3', '4', 'auto'), true) ? $input : '2';
    }

    /**
     * Sanitize 24-hour HH:MM time strings.
     */
    public function sanitize_hhmm_option( mixed $value ): string {
        $clean = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
        return HotelTime::sanitize_hhmm( $clean, '' );
    }

    /**
     * Validate IANA timezone identifier (or empty string to inherit WordPress site timezone).
     */
    public function sanitize_timezone( mixed $value ): string {
        $tz = trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );
        if ( '' === $tz ) {
            return '';
        }
        return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : '';
    }

    /**
     * Validate ISO-4217 currency code against supported currencies.
     */
    public function sanitize_currency_code( mixed $value ): string {
        $code = strtoupper( trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) ) );
        return I18n::is_valid_currency( $code ) ? $code : 'USD';
    }

    /**
     * Validate currency symbol position ('before' or 'after').
     */
    public function sanitize_currency_position( mixed $value ): string {
        $pos = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
        return 'after' === $pos ? 'after' : 'before';
    }

    /**
     * Validate deposit calculation mode.
     */
    public function sanitize_deposit_type( mixed $value ): string {
        $type = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
        return in_array( $type, array( 'percentage', 'fixed', 'first_night' ), true ) ? $type : 'percentage';
    }

    /**
     * Validate service fee type ('fixed' or 'percentage').
     */
    public function sanitize_service_fee_type( mixed $value ): string {
        $type = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
        return 'percentage' === $type ? 'percentage' : 'fixed';
    }

    /**
     * Clamp non-negative decimal settings to >= 0 formatted with 2 decimals when non-zero.
     */
    public function sanitize_non_negative_decimal( mixed $value ): string {
        $raw = is_scalar( $value ) ? str_replace( ',', '.', trim( (string) $value ) ) : '0';
        $num = is_numeric( $raw ) ? max( 0.0, (float) $raw ) : 0.0;
        return number_format( $num, 2, '.', '' );
    }

    /**
     * Clamp percentage settings to 0.00..100.00.
     */
    public function sanitize_percentage( mixed $value ): string {
        $raw = is_scalar( $value ) ? str_replace( ',', '.', trim( (string) $value ) ) : '0';
        $num = is_numeric( $raw ) ? max( 0.0, min( 100.0, (float) $raw ) ) : 0.0;
        return number_format( $num, 2, '.', '' );
    }

    /**
     * Validate early check-in / late check-out conflict mode.
     */
    public function sanitize_conflict_mode( mixed $value ): string {
        $mode = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
        return in_array( $mode, array( 'standard', 'smart', 'strict', 'request_only' ), true ) ? $mode : 'standard';
    }

    /**
     * Validate forced frontend locale against supported plugin locales.
     */
    public function sanitize_forced_locale( mixed $value ): string {
        $locale = trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );
        if ( '' === $locale ) {
            return '';
        }
        return array_key_exists( $locale, I18n::get_supported_locales() ) ? $locale : '';
    }

    /**
     * Validate balance payment link dispatch schedule.
     */
    public function sanitize_balance_schedule( mixed $value ): string {
        $sched = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
        return in_array( $sched, array( 'manual', 'on_confirm', 'arrival', 'departure_minus_1', 'custom_lead' ), true ) ? $sched : 'arrival';
    }

    /**
     * Clamp positive day-count settings to 1..365.
     */
    public function sanitize_positive_days( mixed $value ): int {
        return max( 1, min( 365, absint( $value ) ) );
    }

/**
     * Lazy-initialized settings save handler.
     *
     * @var SettingsSaveHandler|null
     */
    private ?SettingsSaveHandler $save_handler = null;

    /**
     * Get the settings save handler instance.
     *
     * @return SettingsSaveHandler
     */
    private function get_save_handler(): SettingsSaveHandler {
        if ( null === $this->save_handler ) {
            $this->save_handler = new SettingsSaveHandler( $this );
        }
        return $this->save_handler;
    }

    /**
     * Render quick shortcode copy reference panel.
     *
     * @return void
     */
    public static function render_shortcode_info(): void {
        SettingsGeneralView::render_shortcode_info();
    }

    /**
     * Render rooms summary table in General settings.
     *
     * @return void
     */
    public static function render_rooms_table(): void {
        SettingsGeneralView::render_rooms_table();
    }

    /**
     * Render license tab.
     *
     * @return void
     */

/**
     * Render a standard text input field.
     *
     * @param array{label_for: string, description?: string} $args Field arguments.
     * @return void
     */
    public function render_text_field( array $args ): void {
        $option = get_option($args['label_for']);
        $value = (string) ( I18n::decode($option) ?? '' );
        echo '<input type="text" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }

    /**
     * Render a standard number input field.
     *
     * @param array{label_for: string, min?: int, max?: int, step?: int} $args Field arguments.
     * @return void
     */
    public function render_number_field( array $args ): void {
        $option = get_option($args['label_for']);
        
        echo '<input type="number" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr( (string) $option) . '" class="regular-text"';
        if (isset($args['min'])) {
            echo ' min="' . esc_attr( (string) $args['min']) . '"';
        }
        if (isset($args['max'])) {
            echo ' max="' . esc_attr( (string) $args['max']) . '"';
        }
        if (isset($args['step'])) {
            echo ' step="' . esc_attr( (string) $args['step']) . '"';
        }
        echo '>';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    /**
     * Sanitize and validate primary notification email input.
     * Falls back to WordPress's global admin_email when empty or invalid.
     *
     * @param mixed $value Input value from settings form.
     * @return string Sanitized email address.
     */
    public function sanitize_notification_email( mixed $value ): string {
        $cleaned = is_string( $value ) ? sanitize_email( trim( $value ) ) : '';
        if ( '' !== $cleaned && is_email( $cleaned ) ) {
            return $cleaned;
        }
        $fallback = sanitize_email( (string) get_option( 'admin_email', '' ) );
        return ( '' !== $fallback && is_email( $fallback ) ) ? $fallback : '';
    }

    /**
     * Sanitize and validate additional notification email input.
     * Supports single or comma/semicolon-separated email lists.
     *
     * @param mixed $value Input value from settings form.
     * @return string Sanitized, comma-separated list of valid emails.
     */
    public function sanitize_additional_notification_emails( mixed $value ): string {
        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return '';
        }
        $parts = preg_split( '/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) ) {
            return '';
        }
        $valid = array();
        foreach ( $parts as $part ) {
            $cleaned = sanitize_email( $part );
            if ( is_email( $cleaned ) ) {
                $cleaned_lower = strtolower( $cleaned );
                if ( ! in_array( $cleaned_lower, $valid, true ) ) {
                    $valid[] = $cleaned_lower;
                }
            }
        }
        return implode( ', ', $valid );
    }

    /**
     * Render the additional notification email field with description.
     *
     * @param array{label_for: string} $args Field arguments.
     * @return void
     */
    public function render_additional_notification_email_field( array $args ): void {
        $value = $this->sanitize_additional_notification_emails( (string) get_option($args['label_for'], '') );
        echo '<input type="text" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($value) . '" class="regular-text" placeholder="manager@example.com, accountant@example.com">';
        echo '<p class="description">' . esc_html__('Optional secondary email address(es) copied (CC) on every admin booking alert. Multiple addresses can be separated by commas.', 'modern-hotel-booking') . '</p>';
    }

    /**
     * Render the hotel timezone selector using WordPress's built-in timezone list.
     */
    public function render_hotel_timezone_field(): void {
        $current = (string) get_option('mhbo_hotel_timezone', '');
        $wp_tz   = (string) get_option('timezone_string', '');
        echo '<select id="mhbo_hotel_timezone" name="mhbo_hotel_timezone" class="regular-text">';
        echo '<option value=""' . selected($current, '', false) . '>'
           /* translators: %s: WordPress site timezone string (e.g. UTC, Europe/London) */
           . esc_html(sprintf(__('— Same as WordPress site (%s) —', 'modern-hotel-booking'), (string) $wp_tz ?: 'UTC'))
           . '</option>';
        echo wp_timezone_choice($current ?: $wp_tz, get_user_locale());
        echo '</select>';
        echo '<p class="description">'
           . esc_html(__('All calendar dates, pricing rules, and iCal events use this timezone. Leave blank to inherit the WordPress site timezone.', 'modern-hotel-booking'))
           . '</p>';
    }

    /**
     * Render the global min/max stay fields.
     */
    public function render_global_stay_fields(): void {
        $min = (int) get_option('mhbo_global_min_stay', 0);
        $max = (int) get_option('mhbo_global_max_stay', 0);
        echo '<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center;">';
        echo '<label for="mhbo_global_min_stay">'
           . esc_html(I18n::get_label('settings_label_global_min_nights')) . '&nbsp;'
           . '<input type="number" id="mhbo_global_min_stay" name="mhbo_global_min_stay" '
           . 'value="' . esc_attr( (string) $min) . '" min="0" max="365" class="small-text">'
           . '</label>';
        echo '<label for="mhbo_global_max_stay">'
           . esc_html(I18n::get_label('settings_label_global_max_nights')) . '&nbsp;'
           . '<input type="number" id="mhbo_global_max_stay" name="mhbo_global_max_stay" '
           . 'value="' . esc_attr( (string) $max) . '" min="0" max="365" class="small-text">'
           . '</label>';
        echo '</div>';
        echo '<p class="description">' . esc_html(I18n::get_label('settings_desc_global_stay')) . '</p>';
    }

    /**
     * Render a standard textarea field.
     *
     * @param array{label_for: string, description?: string} $args Field arguments.
     * @return void
     */
    public function render_textarea_field( array $args ): void {
        $option = get_option($args['label_for']);
        $value = (string) ( I18n::decode($option) ?? '' );
        echo '<textarea id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" rows="3" class="large-text code">' . esc_textarea($value) . '</textarea>';
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    /**
     * Render a standard select field.
     *
     * @param array{label_for: string, options: array<string, string>} $args Field arguments.
     * @return void
     */
    public function render_select_field( array $args ): void {
        $option = get_option($args['label_for']);
        $option = I18n::decode($option);
        echo '<select id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '">';
        foreach ($args['options'] as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($option, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Render a page selection dropdown.
     *
     * @param array{label_for: string} $args Field arguments.
     * @return void
     */
    public function render_page_select_field( array $args ): void {
        $option = get_option($args['label_for'], 0);
        wp_dropdown_pages(array(
            'name' => esc_attr($args['label_for']),
            'selected' => absint($option),
            'show_option_none' => esc_html(I18n::get_label('settings_opt_none_page')),
            'class' => 'regular-text',
        ));
        echo '<p class="description">' . esc_html(I18n::get_label('settings_desc_booking_shortcode')) . '</p>';
    }

    /**
     * Render a standard checkbox field.
     *
     * @param array{label_for: string, default?: int, description?: string} $args Field arguments.
     * @return void
     */
    public function render_checkbox_field( array $args ): void {
        $default = isset($args['default']) ? $args['default'] : 0;
        $option = get_option($args['label_for'], $default);
        echo '<input type="hidden" name="' . esc_attr($args['label_for']) . '" value="0">';
        echo '<input type="checkbox" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="1" ' . checked(1, $option, false) . '>';
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    /**
     * Render the custom fields row repeater.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public static function render_custom_fields_repeater( array $args = array() ): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only render from get_option(), nonce verified in process_settings_save().
        $fields = get_option('mhbo_custom_fields', array());
        $langs = I18n::get_available_languages();
        ?>
        <div id="mhbo-custom-fields-repeater" style="max-width: 800px;">
            <div class="mhbo-repeater-items">
                <?php
                if (isset($fields) && count($fields) > 0) :
                    foreach ($fields as $index => $field) :
						?>
                        <div class="mhbo-repeater-item"
                            style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 15px; position: relative; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                            <button type="button" class="mhbo-remove-field"
                                style="position: absolute; top: 10px; right: 10px; color: #d63638; background: none; border: none; font-size: 20px; cursor: pointer; padding: 0;">&times;</button>

                            <div
                                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 12px;">
                                <div>
                                    <label
                                        style="display: block; font-weight: bold; margin-bottom: 5px;"><?php echo esc_html(I18n::get_label('cf_field_id')); ?></label>
                                    <input type="text" name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][id]"
                                        value="<?php echo esc_attr($field['id']); ?>" class="widefat" placeholder="e.g. address"
                                        required>
                                </div>
                                <div>
                                    <label
                                        style="display: block; font-weight: bold; margin-bottom: 5px;"><?php echo esc_html(I18n::get_label('cf_type')); ?></label>
                                    <select name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][type]" class="widefat">
                                        <option value="text" <?php selected($field['type'], 'text'); ?>>
                                            <?php echo esc_html(I18n::get_label('cf_type_text')); ?>
                                        </option>
                                        <option value="number" <?php selected($field['type'], 'number'); ?>>
                                            <?php echo esc_html(I18n::get_label('cf_type_number')); ?>
                                        </option>
                                        <option value="textarea" <?php selected($field['type'], 'textarea'); ?>>
                                            <?php echo esc_html(I18n::get_label('cf_type_textarea')); ?>
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div style="margin-bottom: 12px;">
                                <label
                                    style="display: block; font-weight: bold; margin-bottom: 8px;"><?php echo esc_html(I18n::get_label('cf_label_multilingual')); ?></label>
                                <?php foreach ($langs as $lang) : ?>
                                    <div style="display: flex; align-items: center; margin-bottom: 5px;">
                                        <span
                                            style="width: 35px; font-weight: 600; font-size: 11px;"><?php echo esc_html(strtoupper($lang)); ?>:</span>
                                        <input type="text"
                                            name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][label][<?php echo esc_attr($lang); ?>]"
                                            value="<?php echo esc_attr(isset($field['label'][ $lang ]) ? $field['label'][ $lang ] : ''); ?>"
                                            class="widefat" style="flex: 1;">
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div>
                                <label style="font-weight: bold;">
                                    <input type="checkbox" name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][required]"
                                        value="1" <?php checked(isset($field['required']) && $field['required']); ?>>
                                    <?php echo esc_html(I18n::get_label('cf_required')); ?>
                                </label>
                            </div>
                        </div>
						<?php
                    endforeach;
endif;
				?>
            </div>

            <button type="button" id="mhbo-add-custom-field" class="button button-secondary"
                style="margin-top: 10px;"><?php echo esc_html(I18n::get_label('cf_btn_add')); ?></button>
            <p class="description">
                <?php echo esc_html(I18n::get_label('cf_desc')); ?>
            </p>
        </div>
        <?php
        // phpcs:enable
    }
        // Note: Custom fields JavaScript logic has been moved to assets/js/mhbo-admin-settings.js
        // Configuration is injected via wp_add_inline_script() in enqueue_scripts()

    private static function is_tab_license_gated( string $tab ): bool {
        
        if (class_exists('\MHBO\Core\Tier') && \MHBO\Core\Tier::get_package_tier() === \MHBO\Core\Tier::TIER_FREE) {
            return false;
        }

return false;
    }

    /**
     * Render settings page.
     *
     * @return void
     */
    public static function render(): void {
        if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
            wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ), '', array( 'response' => 403 ) );
        }

        $active_tab   = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation, no state change.

        // Restore PRG settings errors from redirect transient if available
        $transient_errors     = get_transient('settings_errors');
        $has_transient_errors = is_array($transient_errors) && array() !== $transient_errors;
        if (is_array($transient_errors)) {
            delete_transient('settings_errors');
            foreach ($transient_errors as $err) {
                if (is_array($err) && isset($err['setting'], $err['code'], $err['message'])) {
                    add_settings_error(
                        $err['setting'],
                        $err['code'],
                        $err['message'],
                        $err['type'] ?? 'success'
                    );
                }
            }
        }

        // Targeted fallback notice only when no specific settings_errors() notice was registered/restored.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WP core redirect param, display-only.
        $settings_updated = isset($_GET['settings-updated']) ? sanitize_key(wp_unslash($_GET['settings-updated'])) : '';
        if (
            '' !== $settings_updated
            && ! $has_transient_errors
            && array() === get_settings_errors('mhbo_settings')
            && array() === get_settings_errors('mhbo_amenities')
        ) {
            echo '<div class="updated notice is-dismissible"><p>' . esc_html(I18n::get_label('settings_msg_saved')) . '</p></div>';
        }

        // Redundant generic notice removed. Specific tab notices are handled via settings_errors().

        ?>
        <div class="wrap mhbo-admin-wrap mhbo-settings-wrap">
            <?php
            AdminUI::render_header(
                I18n::get_label('settings_title'),
                I18n::get_label('settings_desc_page'),
                array(),
                array(
                    array('label' => I18n::get_label('menu_main'), 'url' => admin_url('admin.php?page=mhbo-hotel-booking')),
                )
            );
            ?>
            <?php settings_errors('mhbo_settings'); ?>
            <?php settings_errors('mhbo_amenities'); ?>
            <nav class="nav-tab-wrapper mhbo-settings-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'modern-hotel-booking' ); ?>">
                
                <a href="?page=mhbo-settings&tab=general"
                    class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>"<?php echo 'general' === $active_tab ? ' aria-current="page"' : ''; ?>><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><span><?php I18n::esc_html_e('tab_general'); ?></span></a>
                <a href="?page=mhbo-settings&tab=emails"
                    class="nav-tab <?php echo 'emails' === $active_tab ? 'nav-tab-active' : ''; ?>"<?php echo 'emails' === $active_tab ? ' aria-current="page"' : ''; ?>><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><span><?php I18n::esc_html_e('tab_emails'); ?></span></a>
                <a href="?page=mhbo-settings&tab=labels"
                    class="nav-tab <?php echo 'labels' === $active_tab ? 'nav-tab-active' : ''; ?>"<?php echo 'labels' === $active_tab ? ' aria-current="page"' : ''; ?>><span class="dashicons dashicons-translation" aria-hidden="true"></span><span><?php I18n::esc_html_e('tab_labels'); ?></span></a>
                <a href="?page=mhbo-settings&tab=amenities"
                    class="nav-tab <?php echo 'amenities' === $active_tab ? 'nav-tab-active' : ''; ?>"<?php echo 'amenities' === $active_tab ? ' aria-current="page"' : ''; ?>><span class="dashicons dashicons-tag" aria-hidden="true"></span><span><?php I18n::esc_html_e('tab_amenities'); ?></span></a>
                <a href="?page=mhbo-settings&tab=business"
                    class="nav-tab <?php echo 'business' === $active_tab ? 'nav-tab-active' : ''; ?>"<?php echo 'business' === $active_tab ? ' aria-current="page"' : ''; ?>><span class="dashicons dashicons-building" aria-hidden="true"></span><span><?php I18n::esc_html_e('tab_business'); ?></span></a>

</nav>

            <?php

if ( SettingsGeneralView::render_free_upsell_tab( $active_tab ) ) {
                echo '</div>';
                return;
            }
            
            ?>

<?php AdminUI::render_card_start('', 'settings-card'); ?>
                <?php
                $manual_tabs = array(

'emails',
                    'labels',
                    'amenities',
                    'business',
                    'general',
                );
                if ( ! in_array($active_tab, $manual_tabs, true)) {
                    $active_tab = 'general';
                }
                $action = in_array($active_tab, $manual_tabs, true) ? '' : 'options.php';
                ?>
                <form method="post" action="<?php echo esc_url($action); ?>">
                    <?php
                    // Don't use settings_fields for manual tabs to avoid WP trying to save to options.php
                    if ( ! in_array($active_tab, $manual_tabs, true)) {
                        settings_fields('mhbo_settings_group');
                    } else {
                        wp_nonce_field('mhbo_settings_nonce', 'mhbo_nonce');
                    }

                    if ('license' === $active_tab) {
                        
                    } elseif ('general' === $active_tab) {
                        SettingsGeneralView::render_general_tab();
                    } elseif ('emails' === $active_tab) {
                        SettingsEmailsView::render_email_templates_tab();
                    } elseif ('labels' === $active_tab) {
                        SettingsGeneralView::render_labels_tab();
                    } elseif ('amenities' === $active_tab) {
                        SettingsGeneralView::render_amenities_tab();

} elseif ('business' === $active_tab) {
                        \MHBO\Business\Info::render_settings_tab();
						
                    }

                    // Show save button — license tab uses its own dedicated action buttons
                    $show_save = true;
                    
                    if ($show_save) {
                        echo '<input type="hidden" name="mhbo_save_tab" value="' . esc_attr($active_tab) . '">';
                        echo '<div class="mhbo-settings-submit-bar" data-tab="' . esc_attr($active_tab) . '">';
                        echo '<span class="mhbo-dirty-indicator" id="mhbo-settings-dirty-indicator" aria-live="polite" hidden><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__('You have unsaved changes', 'modern-hotel-booking') . '</span>';
                        submit_button('', 'primary large', 'submit', false);
                        echo '</div>';
                    }

                    ?>
                </form>
                <?php
                if ('general' === $active_tab) {
                    self::render_shortcode_info();
                    self::render_rooms_table();
                }
                ?>
            <?php AdminUI::render_card_end(); ?>
        </div>
        <?php
    }

    /**
     * Evaluate whether the current user is authorized to submit a Settings tab POST payload.
     *
     * Hotel Managers (`mhbo_manage_pricing`) may save `pricing` and `coupons` tabs; all other tabs
     * and system-admin submit triggers strictly require `mhbo_manage_settings` / `manage_options`.
     *
     * @param string               $requested_tab Sanitized `mhbo_save_tab` value.
     * @param array<string, mixed> $post_data     Raw POST submission flags.
     * @return bool True if authorized, false otherwise.
     */
    public static function can_save_tab_request( string $requested_tab, array $post_data = array() ): bool {
        $is_license_req = 'license' === $requested_tab
            || isset($post_data['mhbo_save_pro_settings'])
            || isset($post_data['mhbo_remove_license']);
        if ($is_license_req) {
            return current_user_can('manage_options');
        }

        $is_pricing_tab_req = in_array($requested_tab, array('pricing', 'coupons'), true)
            && ! isset($post_data['mhbo_pro_themes_save'])
            && ! isset($post_data['submit_save_themes']);

        return $is_pricing_tab_req
            ? Capabilities::can_override_booking_pricing()
            : Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS);
    }

    /**
     * Process settings save operations.
     */
    public function process_settings_save(): void {

// 1. Determine which nonce to verify based on the action/tab
        $nonce_action = 'mhbo_settings_nonce';
        $nonce_field  = 'mhbo_nonce';

// 2. Security Gatekeeper - Only trigger if this is a plugin-specific save request.
        if (isset($_POST['mhbo_save_tab']) || isset($_POST['mhbo_save_pro_settings']) || isset($_POST['mhbo_remove_license']) || isset($_POST['mhbo_pro_themes_save']) || isset($_POST['submit_save_themes'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified immediately below.

            // Coarse capability gate before reading POST payload or verifying nonce.
            if ( ! Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS) && ! Capabilities::can_override_booking_pricing()) {
                wp_die(esc_html(I18n::get_label('msg_insufficient_permissions')));
            }

            // Find the actual nonce field sent
            $sent_nonce = isset($_POST[ $nonce_field ]) ? sanitize_text_field(wp_unslash($_POST[ $nonce_field ])) : '';

            if ( ! $sent_nonce || ! wp_verify_nonce($sent_nonce, $nonce_action)) {
                 wp_die(esc_html(I18n::get_label('label_security_check_failed')));
            }

            // 3. Routing & fine-grained tab authorization (nonce-verified).
            $data = $_POST;
            $tab  = isset($data['mhbo_save_tab']) ? sanitize_key(wp_unslash($data['mhbo_save_tab'])) : '';
            if ( ! self::can_save_tab_request($tab, $data)) {
                wp_die(esc_html(I18n::get_label('msg_insufficient_permissions')));
            }

switch ($tab) {
                case 'general':
                    $this->save_general_settings($data);
                    break;
                
                case 'amenities':
                    $this->save_amenities_settings($data);
                    break;
                case 'payments':
                    $this->save_payments_settings($data);
                    break;

case 'emails':
                case 'labels':
                case 'i18n':
                    $this->save_multilingual_settings($data);
                    break;
                case 'business':
                    $this->save_business_settings($data);
                    break;

}

            // 2026 BP: Standard Post-Redirect-Get (PRG) pattern for clean settings state & error display
            $settings_errors = get_settings_errors();
            if ( ! empty($settings_errors)) {
                set_transient('settings_errors', $settings_errors, 30);
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI routing for safe redirect.
            $get_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
            if (str_starts_with($get_page, 'mhbo-pro-') || 'mhbo-pro' === $get_page) {
                $redirect_url = admin_url('admin.php?page=' . $get_page . '&settings-updated=true');
            } elseif ($get_page === 'mhbo-settings') {
                $redirect_tab = ! empty($tab) ? $tab : 'general';
                $redirect_url = admin_url('admin.php?page=mhbo-settings&tab=' . $redirect_tab . '&settings-updated=true');
            } else {
                $redirect_tab = ! empty($tab) ? $tab : 'general';
                $redirect_url = admin_url('admin.php?page=mhbo-settings&tab=' . $redirect_tab . '&settings-updated=true');
            }

            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Save multilingual email templates, labels, and GDPR strings.
     *
     * @param array<string, mixed> $data POST payload.
     * @return void
     */
    public function save_multilingual_settings( array $data ): void {
        $this->get_save_handler()->save_multilingual_settings( $data );
    }

    /**
     * Save general settings.
     *
     * @param array<string, mixed> $data POST payload.
     * @return void
     */
    public function save_general_settings( array $data ): void {
        $this->get_save_handler()->save_general_settings( $data );
    }

    /**
     * Save business settings.
     *
     * @param array<string, mixed> $data POST payload.
     * @return void
     */
    public function save_business_settings( array $data ): void {
        $this->get_save_handler()->save_business_settings( $data );
    }

    /**
     * Save amenities settings.
     *
     * @param array<string, mixed> $data POST payload.
     * @return void
     */
    public function save_amenities_settings( array $data ): void {
        $this->get_save_handler()->save_amenities_settings( $data );
    }

    /**
     * Save payment gateway settings.
     *
     * @param array<string, mixed> $data POST payload.
     * @return void
     */
    public function save_payments_settings( array $data ): void {

unset( $data );
        
    }

/**
     * Sanitize callback for mhbo_max_advance_booking_days registered via register_setting().
     * Clamps window between 14 and 730 days.
     */
    public function sanitize_advance_booking_days( mixed $value ): int {
        $val = absint($value);
        if ($val < 14) {
            return 14;
        }
        if ($val > 730) {
            return 730;
        }
        return $val;
    }

public static function render_pro_page(): void {
        
    }

private static function render_payments_tab(): void {
        
    }

private static function render_tax_tab(): void {
        
    }

}
