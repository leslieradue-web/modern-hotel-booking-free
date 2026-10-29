<?php declare(strict_types=1);

namespace MHBO\Admin;

use MHBO\Core\Cache;
use MHBO\Core\Capabilities;
use MHBO\Core\License;
use MHBO\Core\LicenseManager;
use MHBO\Core\Pricing;
use MHBO\Core\Money;
use MHBO\Core\Tax;
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
    public function enqueue_scripts( string $hook ): void {
        // Only load on settings and pro pages
        if (false === strpos($hook, 'mhbo-settings') && false === strpos($hook, 'mhbo-pro')) {
            return;
        }

        wp_enqueue_script(
            'mhbo-admin-settings',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-settings.js',
            array('wp-api-fetch'),
            MHBO_VERSION,
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
                
                'clear_cache' => wp_create_nonce('mhbo_clear_cache_nonce_field'),
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
        register_setting('mhbo_settings_group', 'mhbo_checkin_time', array('default' => '14:00', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_checkout_time', array('default' => '11:00', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_hotel_timezone', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting('mhbo_settings_group', 'mhbo_notification_email', array('default' => get_option('admin_email'), 'sanitize_callback' => array($this, 'sanitize_notification_email')));
        register_setting('mhbo_settings_group', 'mhbo_additional_notification_email', array('default' => '', 'sanitize_callback' => array($this, 'sanitize_additional_notification_emails')));
        register_setting('mhbo_settings_group', 'mhbo_modal_enabled', array('default' => 1, 'sanitize_callback' => 'absint'));
        
        register_setting('mhbo_settings_group', 'mhbo_children_enabled', array('default' => 0, 'sanitize_callback' => 'absint'));
        // Global Stay Limits
        register_setting('mhbo_settings_group', 'mhbo_global_min_stay', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_global_max_stay', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_max_advance_booking_days', array('type' => 'integer', 'sanitize_callback' => array($this, 'sanitize_advance_booking_days'), 'default' => 365));

        // Guest Portal & Digital Compendium
        register_setting('mhbo_settings_group', 'mhbo_guest_portal_page', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        
        register_setting('mhbo_settings_group', 'mhbo_hotel_wifi_ssid', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting('mhbo_settings_group', 'mhbo_hotel_wifi_password', array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''));
        register_setting('mhbo_settings_group', 'mhbo_hotel_guest_instructions', array('type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'default' => ''));

// Currency Settings
        register_setting('mhbo_settings_group', 'mhbo_currency_code', array('default' => 'USD', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_currency_symbol', array('default' => '$', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_currency_position', array('default' => 'before', 'sanitize_callback' => 'sanitize_text_field'));
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
        register_setting('mhbo_settings_group', 'mhbo_trusted_proxies', array('default' => '', 'sanitize_callback' => 'sanitize_textarea_field'));

        // Display Settings
        register_setting('mhbo_settings_group', 'mhbo_powered_by_link', array('default' => 0, 'sanitize_callback' => 'absint'));

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
        foreach ($fields as $field) {
            $sanitized_field = array(
                'id' => isset($field['id']) ? sanitize_key($field['id']) : '',
                'type' => isset($field['type']) ? sanitize_text_field($field['type']) : 'text',
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
     * Render the shortcode setup guide info box at the bottom of the General tab.
     */
    public static function render_shortcode_info(): void {
        echo '<div class="mhbo-settings-section-card" style="margin-top: 24px; border-left: 4px solid #2563eb;">';
        echo '<div class="mhbo-settings-section-title-wrap" style="margin-bottom: 12px;">';
        echo '<div class="mhbo-settings-section-icon" style="background:#eff6ff;color:#2563eb;"><span class="dashicons dashicons-shortcode"></span></div>';
        echo '<div><h4 style="margin:0;font-size:15px;font-weight:700;color:#0f172a;">' . esc_html(I18n::get_label('setup_guide_title')) . '</h4>';
        echo '<p style="margin:2px 0 0 0;font-size:12.5px;color:#64748b;">' . esc_html(__('Publishing calendars and booking flows across your WordPress pages using Gutenberg blocks or shortcodes.', 'modern-hotel-booking')) . '</p></div>';
        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:12px;margin-top:14px;">';

        // 1. Full Multi-Room Booking Form
        echo '<div style="background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0;">';
        echo '<div style="font-weight:700;color:#0f172a;font-size:13px;display:flex;align-items:center;gap:6px;margin-bottom:4px;"><span style="color:#2563eb;">🔍</span> ' . esc_html(__('1. Full Accommodation Search Form', 'modern-hotel-booking')) . '</div>';
        echo '<p style="margin:0 0 8px 0;font-size:12px;color:#64748b;line-height:1.5;">' . esc_html(__('Searches all available room categories by dates and party size. Best for your primary Booking / Reservations page.', 'modern-hotel-booking')) . '</p>';
        echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;"><span style="font-size:11px;color:#475569;font-weight:600;min-width:68px;">' . esc_html(__('Shortcode:', 'modern-hotel-booking')) . '</span> <code style="user-select:all;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-size:11.5px;font-weight:700;color:#0f172a;">[mhbo_booking_form]</code> <button type="button" class="button button-small" onclick="mhboCopyCode(this, \'[mhbo_booking_form]\');" title="' . esc_attr__('Copy shortcode', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button></div>';
        echo '<div style="display:flex;align-items:center;gap:6px;"><span style="font-size:11px;color:#475569;font-weight:600;min-width:68px;">' . esc_html(__('Block Code:', 'modern-hotel-booking')) . '</span> <code style="user-select:all;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-size:11px;color:#0369a1;">&lt;!-- wp:modern-hotel-booking/booking-form /--&gt;</code> <button type="button" class="button button-small" onclick="mhboCopyCode(this, \'<!-- wp:modern-hotel-booking/booking-form /-->\');" title="' . esc_attr__('Copy block markup', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button></div>';
        echo '</div>';

        // 2. Direct Single-Room Availability & Price Calendar
        echo '<div style="background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0;">';
        echo '<div style="font-weight:700;color:#0f172a;font-size:13px;display:flex;align-items:center;gap:6px;margin-bottom:4px;"><span style="color:#16a34a;">📅</span> ' . esc_html(__('2. Direct Room Availability Calendar', 'modern-hotel-booking')) . '</div>';
        echo '<p style="margin:0 0 8px 0;font-size:12px;color:#64748b;line-height:1.5;">' . esc_html(__('Displays a direct visual calendar for a specific room with daily rates and open/booked dates. Replace X with numeric Room ID.', 'modern-hotel-booking')) . '</p>';
        echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;"><span style="font-size:11px;color:#475569;font-weight:600;min-width:68px;">' . esc_html(__('Shortcode:', 'modern-hotel-booking')) . '</span> <code style="user-select:all;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-size:11.5px;font-weight:700;color:#0f172a;">[mhbo_room_calendar room_id="1"]</code> <button type="button" class="button button-small" onclick="mhboCopyCode(this, \'[mhbo_room_calendar room_id=&quot;1&quot;]\');" title="' . esc_attr__('Copy sample shortcode', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button></div>';
        echo '<div style="display:flex;align-items:center;gap:6px;"><span style="font-size:11px;color:#475569;font-weight:600;min-width:68px;">' . esc_html(__('Block Code:', 'modern-hotel-booking')) . '</span> <code style="user-select:all;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-size:11px;color:#0369a1;">&lt;!-- wp:modern-hotel-booking/room-calendar {&quot;roomId&quot;:1} /--&gt;</code> <button type="button" class="button button-small" onclick="mhboCopyCode(this, \'<!-- wp:modern-hotel-booking/room-calendar {&quot;roomId&quot;:1} /-->\');" title="' . esc_attr__('Copy sample block markup', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button></div>';
        echo '</div>';

        // 3. Guest Self-Service Portal & Balances
        echo '<div style="background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0;">';
        echo '<div style="font-weight:700;color:#0f172a;font-size:13px;display:flex;align-items:center;gap:6px;margin-bottom:4px;"><span style="color:#9333ea;">🔑</span> ' . esc_html(__('3. Guest Self-Service Workstation', 'modern-hotel-booking')) . '</div>';
        echo '<p style="margin:0 0 8px 0;font-size:12px;color:#64748b;line-height:1.5;">' . esc_html(__('Guest portal for viewing reservations, arrival cards, Wi-Fi info, modification requests, and balance settlement.', 'modern-hotel-booking')) . '</p>';
        echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;"><span style="font-size:11px;color:#475569;font-weight:600;min-width:68px;">' . esc_html(__('Portal Code:', 'modern-hotel-booking')) . '</span> <code style="user-select:all;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-size:11.5px;font-weight:700;color:#0f172a;">[mhbo_guest_portal]</code> <button type="button" class="button button-small" onclick="mhboCopyCode(this, \'[mhbo_guest_portal]\');" title="' . esc_attr__('Copy portal shortcode', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button></div>';
        echo '<div><a href="' . esc_url(admin_url('admin.php?page=mhbo-settings&tab=business&subtab=shortcodes')) . '" style="text-decoration:underline;color:#2563eb;font-size:11.5px;font-weight:600;">' . esc_html(__('View Full 12-Shortcode Directory &rarr;', 'modern-hotel-booking')) . '</a></div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    /**
     * Render the configured rooms reference table (shown after Save Changes).
     */
    public static function render_rooms_table(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rooms = $wpdb->get_results(
            "SELECT r.id, r.room_number, t.name AS type_name
             FROM {$wpdb->prefix}mhbo_rooms r
             LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
             ORDER BY (r.room_number + 0) ASC, r.room_number ASC, r.id ASC
             LIMIT 100"
        );
        if (is_array($rooms)) {
            usort($rooms, function ( $a, $b ) {
                $cmp = strnatcasecmp( (string) $a->room_number, (string) $b->room_number);
                if (0 !== $cmp) {
                    return $cmp;
                }
                return ( (int) $a->id ) <=> ( (int) $b->id );
            });
        }

        if (is_array($rooms) && array() !== $rooms) {
            echo '<div class="mhbo-settings-section-card" style="margin-top: 24px;">';
            echo '<details style="cursor:pointer;" open>';
            echo '<summary style="font-weight:700;font-size:14.5px;color:#0f172a;display:flex;align-items:center;justify-content:space-between;">'
               . '<div style="display:flex;align-items:center;gap:8px;"><span class="dashicons dashicons-list-view" style="color:#2563eb;"></span> '
               . esc_html(I18n::get_label('setup_guide_your_rooms')) . ' (' . count($rooms) . ')</div>'
               . '<span style="font-size:12px;color:#64748b;font-weight:normal;">' . esc_html(__('Click to expand / collapse numeric Room IDs & embed codes', 'modern-hotel-booking')) . '</span>'
               . '</summary>';
            echo '<p style="margin:10px 0 14px 0;font-size:12.5px;color:#64748b;line-height:1.5;">'
               . esc_html(__('Each physical room below belongs to a parent Room Type and has a unique numeric Room ID. Use the 1-click copy buttons to embed a direct live calendar for any room into WordPress posts, pages, or block layouts.', 'modern-hotel-booking'))
               . '</p>';
            echo '<div class="mhbo-table-responsive">';
            echo '<table class="widefat striped mhbo-setup-guide-table" style="border-radius:6px;border:1px solid #e2e8f0;margin:0;">';
            echo '<thead><tr>';
            echo '<th scope="col" style="width:80px;">' . esc_html(I18n::get_label('setup_guide_col_id')) . '</th>';
            echo '<th scope="col" style="width:140px;">' . esc_html(I18n::get_label('setup_guide_col_name')) . '</th>';
            echo '<th scope="col" style="width:160px;">' . esc_html(I18n::get_label('setup_guide_col_type')) . '</th>';
            echo '<th scope="col">' . esc_html(__('Direct Calendar Shortcode', 'modern-hotel-booking')) . '</th>';
            echo '<th scope="col">' . esc_html(__('Gutenberg Block Code', 'modern-hotel-booking')) . '</th>';
            echo '<th scope="col" style="width:100px;text-align:right;">' . esc_html(__('Action', 'modern-hotel-booking')) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($rooms as $room) {
                $room_id   = (int) $room->id;
                $shortcode = '[mhbo_room_calendar room_id="' . $room_id . '"]';
                $blockcode = '<!-- wp:modern-hotel-booking/room-calendar {"roomId":' . $room_id . '} /-->';
                $edit_url  = admin_url('admin.php?page=mhbo-rooms&action=edit&id=' . $room_id);
                echo '<tr>';
                echo '<td><span class="mhbo-badge" style="background:#f1f5f9;color:#0f172a;font-weight:700;padding:3px 8px;border-radius:4px;border:1px solid #cbd5e1;font-size:12px;">#' . esc_html( (string) $room_id) . '</span></td>';
                echo '<td><strong>' . esc_html( (string) ( $room->room_number ?? '—' )) . '</strong></td>';
                echo '<td><span style="color:#475569;font-weight:500;">' . esc_html( (string) ( I18n::decode($room->type_name ?? '') ?: '—' )) . '</span></td>';
                echo '<td><div style="display:flex;align-items:center;gap:6px;">';
                echo '<code style="background:#f8fafc;padding:3px 7px;border-radius:4px;border:1px solid #e2e8f0;font-size:12px;user-select:all;color:#0f172a;font-weight:600;">' . esc_html($shortcode) . '</code>';
                echo '<button type="button" class="button button-small" onclick="mhboCopyCode(this, \'' . esc_js($shortcode) . '\');" title="' . esc_attr__('Copy shortcode to clipboard', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button>';
                echo '</div></td>';
                echo '<td><div style="display:flex;align-items:center;gap:6px;">';
                echo '<code style="background:#f8fafc;padding:3px 7px;border-radius:4px;border:1px solid #e2e8f0;font-size:11.5px;user-select:all;color:#0369a1;">' . esc_html($blockcode) . '</code>';
                echo '<button type="button" class="button button-small" onclick="mhboCopyCode(this, \'' . esc_js($blockcode) . '\');" title="' . esc_attr__('Copy block markup to clipboard', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button>';
                echo '</div></td>';
                echo '<td style="text-align:right;"><a href="' . esc_url($edit_url) . '" class="button button-small" style="text-decoration:none;">' . esc_html__('Edit Room', 'modern-hotel-booking') . '</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
            echo '</details>';
            echo '</div>';
        } else {
            echo '<div class="mhbo-settings-section-card" style="margin-top: 24px;">';
            echo '<div style="padding: 24px; text-align: center; background: #f8fafc; border-radius: 8px; border: 1.5px dashed #cbd5e1;">';
            echo '<div style="font-size: 32px; line-height: 1; margin-bottom: 8px;">🛏️</div>';
            echo '<h4 style="margin: 0 0 6px 0; font-size: 15px; font-weight: 700; color: #0f172a;">' . esc_html(__('No Physical Rooms Configured Yet', 'modern-hotel-booking')) . '</h4>';
            echo '<p style="margin: 0 0 14px 0; font-size: 12.5px; color: #64748b; max-width: 520px; margin-inline: auto; line-height: 1.5;">' . esc_html(__('To generate direct room booking calendar shortcodes and block embed codes, first define your Room Types (Step 1 above), then create your physical rooms and keys (Step 2).', 'modern-hotel-booking')) . '</p>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=mhbo-rooms&action=add')) . '" class="button button-primary" style="font-weight: 600;">' . esc_html__('+ Add Your First Physical Room', 'modern-hotel-booking') . '</a>';
            echo '</div>';
            echo '</div>';
        }

        echo '<script>
        function mhboCopyCode(btn, code) {
            if (!btn || !code) return;
            var orig = btn.getAttribute("data-orig") || btn.innerText;
            btn.setAttribute("data-orig", orig);
            function done() {
                btn.innerText = "✓ ' . \esc_js(__('Copied!', 'modern-hotel-booking')) . '";
                btn.style.color = "#15803d";
                btn.style.borderColor = "#86efac";
                setTimeout(function() {
                    btn.innerText = orig;
                    btn.style.color = "";
                    btn.style.borderColor = "";
                }, 1500);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(done).catch(fallback);
            } else {
                fallback();
            }
            function fallback() {
                var ta = document.createElement("textarea");
                ta.value = code;
                ta.setAttribute("readonly", "");
                ta.style.position = "fixed";
                ta.style.top = "-9999px";
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand("copy");
                    done();
                } catch (e) {}
                document.body.removeChild(ta);
            }
        }
        </script>';
    }

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
        $active_tab   = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation, no state change.

        // Restore PRG settings errors from redirect transient if available
        $transient_errors = get_transient('settings_errors');
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

        // Targeted success notice for manual redirects (e.g. Business tab)
        // Others (General, Performance, etc.) use settings_errors() via register_setting()
        if (isset($_GET['settings-updated']) && sanitize_key(wp_unslash($_GET['settings-updated']))) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WP core redirect param, display-only.
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
            <h2 class="nav-tab-wrapper">
                
                <a href="?page=mhbo-settings&tab=general"
                    class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_general'); ?></a>
                <a href="?page=mhbo-settings&tab=emails"
                    class="nav-tab <?php echo 'emails' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_emails'); ?></a>
                <a href="?page=mhbo-settings&tab=labels"
                    class="nav-tab <?php echo 'labels' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_labels'); ?></a>
                <a href="?page=mhbo-settings&tab=amenities"
                    class="nav-tab <?php echo 'amenities' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_amenities'); ?></a>
                <a href="?page=mhbo-settings&tab=business"
                    class="nav-tab <?php echo 'business' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php I18n::esc_html_e('tab_business'); ?></a>

</h2>

<?php AdminUI::render_card_start('', 'settings-card'); ?>
                <?php
                $manual_tabs = array(

'emails',
                    'labels',
                    'amenities',
                    'business',
                    'webhooks',
                    'general',
                );
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
                        self::render_general_tab();
                    } elseif ('emails' === $active_tab) {
                        self::render_email_templates_tab();
                    } elseif ('labels' === $active_tab) {
                        self::render_labels_tab();
                    } elseif ('amenities' === $active_tab) {
                        self::render_amenities_tab();

} elseif ('business' === $active_tab) {
                        \MHBO\Business\Info::render_settings_tab();
                    } elseif ('webhooks' === $active_tab) {
                        self::render_api_tab();
                    }

                    // Show save button — license tab uses its own dedicated action buttons
                    $show_save = true;
                    
                    if ($show_save) {
                        echo '<input type="hidden" name="mhbo_save_tab" value="' . esc_attr($active_tab) . '">';
                        submit_button();
                    }

                    if ('general' === $active_tab) {
                        self::render_shortcode_info();
                        self::render_rooms_table();
                    }

?>
                </form>
            <?php AdminUI::render_card_end(); ?>
        </div>
        <?php
    }

    /**
     * Render the Modern General Settings Tab (2026 BP).
     *
     * 9 Card-Based Architectural Sections with instant declarative Vanilla JS reactivity.
     */
    private static function render_general_tab(): void {
        // 1. Property & Stay Timing
        $tz_current = (string) get_option('mhbo_hotel_timezone', '');
        $wp_tz      = (string) get_option('timezone_string', '');
        $active_tz  = \MHBO\Core\HotelTime::timezone();
        $ci_time    = (string) get_option('mhbo_checkin_time', '14:00');
        $co_time    = (string) get_option('mhbo_checkout_time', '11:00');
        
        $global_min  = (int) get_option('mhbo_global_min_stay', 0);
        $global_max  = (int) get_option('mhbo_global_max_stay', 0);

        // 2. Early Check-in & Late Check-out

// 3. Revenue & Stay Optimization

// 4. Booking Flow & Frontend Experience
        $b_page     = (int) get_option('mhbo_booking_page', 0);
        $b_url      = (string) get_option('mhbo_booking_page_url', '');
        $modal_en   = (int) get_option('mhbo_modal_enabled', 1);

// 5. Operational Notifications & Housekeeping
        $notif_email   = \MHBO\Core\Email::get_notification_email();
        $cc_email      = (string) get_option('mhbo_additional_notification_email', '');

// 6. Guest CRM & Intelligence
        $crm_en     = (int) get_option('mhbo_guest_crm_enabled', 0);

// 8. Currency & Formatting
        $curr_code  = (string) get_option('mhbo_currency_code', 'USD');
        $curr_sym   = (string) get_option('mhbo_currency_symbol', '$');
        $curr_pos       = (string) get_option('mhbo_currency_position', 'before');
        $price_decimals = (string) get_option('mhbo_price_decimals', '2');
        $decimals       = (int) get_option('mhbo_calendar_show_decimals', 0);
        $powered_by     = (int) get_option('mhbo_powered_by_link', 0);

        // 9. Guest Portal & Digital Compendium
        $wifi_ssid    = (string) get_option('mhbo_hotel_wifi_ssid', '');
        $wifi_pass    = (string) get_option('mhbo_hotel_wifi_password', '');
        $guest_guide  = (string) get_option('mhbo_hotel_guest_instructions', '');
        $portal_page  = (int) get_option('mhbo_guest_portal_page', 0);

// 10. Security & System
        $proxies    = (string) get_option('mhbo_trusted_proxies', '');
        $keep_data  = (int) get_option('mhbo_save_data_on_uninstall', 1);
        ?>

        <?php
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $type_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_room_types");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $room_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_rooms");
        $company_info = class_exists('\MHBO\Business\Info') ? \MHBO\Business\Info::get_company() : array();
        $is_company_set = ! empty($company_info['company_name']);
        $company_display_name = $is_company_set ? (string) $company_info['company_name'] : (string) get_option('mhbo_hotel_name', '');
        $is_booking_page_linked = $b_page > 0;
        ?>

        <!-- Pro Lean Mode: Reopen Pill Wrapper (visible when guide is dismissed) -->
        <div id="mhbo-guide-pill-wrapper" style="display: none; justify-content: flex-end; margin-bottom: 16px;">
            <button type="button" class="button button-secondary" onclick="mhboShowOnboardingGuide();" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; border-radius: 8px; border-color: #cbd5e1; background: #ffffff; color: #0f172a; padding: 6px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); font-size: 13px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.borderColor='#94a3b8'; this.style.boxShadow='0 2px 6px rgba(0,0,0,0.1)';" onmouseout="this.style.borderColor='#cbd5e1'; this.style.boxShadow='0 1px 3px rgba(0,0,0,0.06)';">
                <span style="font-size: 15px;">✨</span>
                <span><?php esc_html_e('Property Setup & Shortcode Guide', 'modern-hotel-booking'); ?></span>
                <span class="mhbo-badge" style="background: #f1f5f9; color: #475569; font-size: 11px; padding: 2px 6px; border-radius: 4px; border: 1px solid #e2e8f0;"><?php esc_html_e('Quick Reference', 'modern-hotel-booking'); ?></span>
            </button>
        </div>

        <!-- Onboarding & Property Setup Guidance Banner -->
        <div class="mhbo-onboarding-welcome-banner" id="mhbo-general-onboarding-banner" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 12px; padding: 24px 28px; margin-bottom: 24px; color: #f8fafc; border: 1px solid #334155; box-shadow: 0 6px 20px rgba(0,0,0,0.15); position: relative;">
            <!-- Top Action Row -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px;">
                <div style="display: flex; gap: 14px; align-items: center;">
                    <span style="font-size: 32px; line-height: 1;">🏨</span>
                    <div>
                        <h3 style="margin: 0; color: #38bdf8; font-size: 1.25rem; font-weight: 700; letter-spacing: -0.01em;">
                            <?php esc_html_e('Modern Hotel Booking — Quick Start & Operational Setup Guide', 'modern-hotel-booking'); ?>
                        </h3>
                        <p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 0.9rem;">
                            <?php esc_html_e('Follow this 4-step roadmap to configure accommodation categories, keys, public calendars, and front desk cashiering.', 'modern-hotel-booking'); ?>
                        </p>
                    </div>
                </div>
                <button type="button" class="button" onclick="mhboDismissOnboardingGuide();" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: #cbd5e1; border-radius: 6px; font-size: 12px; padding: 4px 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.18)'; this.style.color='#fff';" onmouseout="this.style.background='rgba(255,255,255,0.08)'; this.style.color='#cbd5e1;'" title="<?php esc_attr_e('Dismiss this guide (Pro Lean Mode)', 'modern-hotel-booking'); ?>">
                    <span>&times;</span> <?php esc_html_e('Dismiss Guide (Lean Mode)', 'modern-hotel-booking'); ?>
                </button>
            </div>

            <!-- Live Readiness Meter Bar -->
            <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 10px 14px; margin-bottom: 20px;">
                <span style="font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-right: 4px;">
                    <?php esc_html_e('Live Setup Status:', 'modern-hotel-booking'); ?>
                </span>
                <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-room-types')); ?>" style="text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: <?php echo $type_count > 0 ? 'rgba(34,197,94,0.15)' : 'rgba(234,179,8,0.15)'; ?>; color: <?php echo $type_count > 0 ? '#4ade80' : '#facc15'; ?>; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid <?php echo $type_count > 0 ? 'rgba(34,197,94,0.3)' : 'rgba(234,179,8,0.3)'; ?>;">
                    📋 1. Room Types: <?php echo $type_count > 0 ? esc_html($type_count . ' Active') : esc_html__('0 Set (Action Needed)', 'modern-hotel-booking'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-rooms')); ?>" style="text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: <?php echo $room_count > 0 ? 'rgba(34,197,94,0.15)' : 'rgba(234,179,8,0.15)'; ?>; color: <?php echo $room_count > 0 ? '#4ade80' : '#facc15'; ?>; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid <?php echo $room_count > 0 ? 'rgba(34,197,94,0.3)' : 'rgba(234,179,8,0.3)'; ?>;">
                    🛏️ 2. Physical Rooms: <?php echo $room_count > 0 ? esc_html($room_count . ' Active') : esc_html__('0 Set (Action Needed)', 'modern-hotel-booking'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business')); ?>" style="text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: <?php echo ! empty($company_display_name) ? 'rgba(34,197,94,0.15)' : 'rgba(234,179,8,0.15)'; ?>; color: <?php echo ! empty($company_display_name) ? '#4ade80' : '#facc15'; ?>; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid <?php echo ! empty($company_display_name) ? 'rgba(34,197,94,0.3)' : 'rgba(234,179,8,0.3)'; ?>;">
                    🏢 3. Business Info: <?php echo ! empty($company_display_name) ? esc_html($company_display_name) : esc_html__('Incomplete', 'modern-hotel-booking'); ?>
                </a>
                <a href="#mhbo_booking_page" style="text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: <?php echo $is_booking_page_linked ? 'rgba(56,189,248,0.15)' : 'rgba(234,179,8,0.15)'; ?>; color: <?php echo $is_booking_page_linked ? '#38bdf8' : '#facc15'; ?>; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid <?php echo $is_booking_page_linked ? 'rgba(56,189,248,0.3)' : 'rgba(234,179,8,0.3)'; ?>;">
                    🚀 4. Booking Page: <?php echo $is_booking_page_linked ? esc_html__('Page Linked', 'modern-hotel-booking') : esc_html__('Select in Card 4', 'modern-hotel-booking'); ?>
                </a>
            </div>

            <!-- 4-Step Action Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
                <!-- Step 1: Room Types First -->
                <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 16px 18px; display: flex; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 700; color: #38bdf8; text-transform: uppercase; letter-spacing: 0.05em;">
                            <?php esc_html_e('Step 1 (First)', 'modern-hotel-booking'); ?>
                        </span>
                        <span class="mhbo-badge" style="background: rgba(56,189,248,0.15); color: #38bdf8; font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 4px;">
                            <?php echo esc_html($type_count . ' Types'); ?>
                        </span>
                    </div>
                    <h4 style="margin: 0 0 6px 0; color: #f8fafc; font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                        <span>📋</span> <?php esc_html_e('Define Room Types', 'modern-hotel-booking'); ?>
                    </h4>
                    <p style="margin: 0 0 12px 0; font-size: 12px; color: #cbd5e1; line-height: 1.55; flex: 1;">
                        <?php esc_html_e('Always create your Room Types first (e.g. Deluxe Suite, Double Room, Villa). Room Types define base prices, guest capacity (standard & max adults/children), extra guest charges, bed setups, photos, and amenities.', 'modern-hotel-booking'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-room-types')); ?>" class="button button-small" style="text-align: center; font-weight: 600; text-decoration: none; border-radius: 6px; padding: 4px 10px; background: #0284c7; color: #fff; border-color: #0284c7;">
                        <?php esc_html_e('Manage Room Types &rarr;', 'modern-hotel-booking'); ?>
                    </a>
                </div>

                <!-- Step 2: Physical Rooms Second -->
                <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 16px 18px; display: flex; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 700; color: #c084fc; text-transform: uppercase; letter-spacing: 0.05em;">
                            <?php esc_html_e('Step 2 (Second)', 'modern-hotel-booking'); ?>
                        </span>
                        <span class="mhbo-badge" style="background: rgba(168,85,247,0.15); color: #c084fc; font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 4px;">
                            <?php echo esc_html($room_count . ' Rooms'); ?>
                        </span>
                    </div>
                    <h4 style="margin: 0 0 6px 0; color: #f8fafc; font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                        <span>🛏️</span> <?php esc_html_e('Add Physical Rooms', 'modern-hotel-booking'); ?>
                    </h4>
                    <p style="margin: 0 0 12px 0; font-size: 12px; color: #cbd5e1; line-height: 1.55; flex: 1;">
                        <?php esc_html_e('Create physical units/keys (e.g. Room 101, Villa 4) and assign each to its parent Room Type. Every room receives a unique numeric Room ID (e.g. #1, #2), visible in the expandable "Your Configured Rooms" section below.', 'modern-hotel-booking'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-rooms')); ?>" class="button button-small" style="text-align: center; font-weight: 600; text-decoration: none; border-radius: 6px; padding: 4px 10px; background: #7c3aed; color: #fff; border-color: #7c3aed;">
                        <?php esc_html_e('Manage Physical Rooms &rarr;', 'modern-hotel-booking'); ?>
                    </a>
                </div>

                <!-- Step 3: Publish Your Booking Flow -->
                <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 16px 18px; display: flex; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 700; color: #4ade80; text-transform: uppercase; letter-spacing: 0.05em;">
                            <?php esc_html_e('Step 3 (Publishing)', 'modern-hotel-booking'); ?>
                        </span>
                        <span class="mhbo-badge" style="background: rgba(34,197,94,0.15); color: #4ade80; font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 4px;">
                            <?php esc_html_e('Blocks & Codes', 'modern-hotel-booking'); ?>
                        </span>
                    </div>
                    <h4 style="margin: 0 0 6px 0; color: #f8fafc; font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                        <span>🚀</span> <?php esc_html_e('Publish Booking Calendars', 'modern-hotel-booking'); ?>
                    </h4>
                    <div style="margin: 0 0 12px 0; font-size: 12px; color: #cbd5e1; line-height: 1.55; flex: 1;">
                        <div style="margin-bottom: 6px;">
                            <strong style="color: #38bdf8;">• Full Search Form:</strong> <code>[mhbo_booking_form]</code> or block <code>booking-form</code> searches all room types. Place on your main Booking Page.
                        </div>
                        <div style="margin-bottom: 6px;">
                            <strong style="color: #4ade80;">• Direct Room Calendar:</strong> <code>[mhbo_room_calendar room_id="X"]</code> or block <code>room-calendar</code> embeds daily rates & dates for a single room.
                        </div>
                        <div>
                            <strong style="color: #fbbf24;">• Modal vs Page:</strong> In Card 4 below, choose slide-in drawer (zero reload) or redirecting to your Booking Page.
                        </div>
                    </div>
                    <a href="#mhbo_booking_page" class="button button-small" style="text-align: center; font-weight: 600; text-decoration: none; border-radius: 6px; padding: 4px 10px; background: #16a34a; color: #fff; border-color: #16a34a;">
                        <?php esc_html_e('Configure Booking Flow &darr;', 'modern-hotel-booking'); ?>
                    </a>
                </div>

                <!-- Step 4: Front Desk & Manual Bookings -->
                <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 16px 18px; display: flex; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 700; color: #fbbf24; text-transform: uppercase; letter-spacing: 0.05em;">
                            <?php esc_html_e('Step 4 (Operations)', 'modern-hotel-booking'); ?>
                        </span>
                        <span class="mhbo-badge" style="background: rgba(251,191,36,0.15); color: #fbbf24; font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 4px;">
                            <?php esc_html_e('PMS Workstation', 'modern-hotel-booking'); ?>
                        </span>
                    </div>
                    <h4 style="margin: 0 0 6px 0; color: #f8fafc; font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                        <span>🛎️</span> <?php esc_html_e('Front Desk & Cashiering', 'modern-hotel-booking'); ?>
                    </h4>
                    <p style="margin: 0 0 12px 0; font-size: 12px; color: #cbd5e1; line-height: 1.55; flex: 1;">
                        <?php esc_html_e('For walk-ins or phone reservations, go to Bookings > Add New Booking. CRM Guest Quick-Fill auto-completes repeat guests with VIP/caution notes. Price breakdowns calculate live, and 1-click tender shortcuts record Cash, Card Terminal, or Bank payments instantly.', 'modern-hotel-booking'); ?>
                    </p>
                    <div style="display: flex; gap: 8px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-bookings&action=add')); ?>" class="button button-small" style="flex: 1; text-align: center; font-weight: 600; text-decoration: none; border-radius: 6px; padding: 4px 6px; background: #d97706; color: #fff; border-color: #d97706;">
                            <?php esc_html_e('Add Booking &rarr;', 'modern-hotel-booking'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-bookings')); ?>" class="button button-small" style="text-align: center; font-weight: 600; text-decoration: none; border-radius: 6px; padding: 4px 8px; background: rgba(255,255,255,0.1); color: #cbd5e1; border-color: rgba(255,255,255,0.2);">
                            <?php esc_html_e('All Bookings', 'modern-hotel-booking'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function mhboSafeStorage() {
            try {
                if (typeof window !== 'undefined' && 'localStorage' in window && window.localStorage !== null) {
                    return window.localStorage;
                }
            } catch (e) {}
            return null;
        }
        function mhboDismissOnboardingGuide() {
            var banner = document.getElementById('mhbo-general-onboarding-banner');
            var pill   = document.getElementById('mhbo-guide-pill-wrapper');
            if (banner) banner.style.display = 'none';
            if (pill) pill.style.display = 'flex';
            var storage = mhboSafeStorage();
            if (storage) {
                try {
                    storage.setItem('mhbo_general_onboarding_dismissed', '1');
                } catch (e) {}
            }
        }
        function mhboShowOnboardingGuide() {
            var banner = document.getElementById('mhbo-general-onboarding-banner');
            var pill   = document.getElementById('mhbo-guide-pill-wrapper');
            if (banner) banner.style.display = 'block';
            if (pill) pill.style.display = 'none';
            var storage = mhboSafeStorage();
            if (storage) {
                try {
                    storage.removeItem('mhbo_general_onboarding_dismissed');
                } catch (e) {}
            }
        }
        (function() {
            var storage = mhboSafeStorage();
            if (storage) {
                try {
                    if (storage.getItem('mhbo_general_onboarding_dismissed') === '1') {
                        var banner = document.getElementById('mhbo-general-onboarding-banner');
                        var pill   = document.getElementById('mhbo-guide-pill-wrapper');
                        if (banner) banner.style.display = 'none';
                        if (pill) pill.style.display = 'flex';
                    }
                } catch (e) {}
            }
        })();
        </script>

        <div class="mhbo-settings-card-container">

            <!-- Card 1: Property & Stay Timing -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-building"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(__('Property Stay Times & Timezone', 'modern-hotel-booking')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Define standard arrival/departure times, your property operational timezone, and booking limits.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group mhbo-span-full">
                        <label class="mhbo-form-label" for="mhbo_hotel_timezone">
                            <?php echo esc_html(__('Hotel Operational Timezone', 'modern-hotel-booking')); ?>
                        </label>
                        <select id="mhbo_hotel_timezone" name="mhbo_hotel_timezone" class="mhbo-form-input">
                            <option value="" <?php selected($tz_current, '', true); ?>>
                                <?php
                                /* translators: %s: WordPress site timezone string (e.g. UTC, Europe/London) */
                                echo esc_html(sprintf(__('— Same as WordPress site (%s) —', 'modern-hotel-booking'), (string) $wp_tz ?: 'UTC'));
                                ?>
                            </option>
                            <?php echo wp_timezone_choice($tz_current ?: $wp_tz, get_user_locale()); ?>
                        </select>
                        <div class="mhbo-tz-live-badge">
                            <span class="mhbo-tz-live-pulse"></span>
                            <span><?php echo esc_html(__('Active Hotel Time:', 'modern-hotel-booking')); ?> <strong class="mhbo-tz-live-time" data-tz="<?php echo esc_attr($active_tz->getName()); ?>"><?php echo esc_html(\MHBO\Core\HotelTime::now()->format('H:i:s T')); ?></strong></span>
                        </div>
                        <p class="mhbo-form-help"><?php echo esc_html(__('All calendar dates, pricing rules, email timestamps, housekeeping manifests, and check-in workflows operate in this timezone.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_checkin_time"><?php echo esc_html(I18n::get_label('settings_label_checkin')); ?></label>
                        <input type="text" id="mhbo_checkin_time" name="mhbo_checkin_time" value="<?php echo esc_attr($ci_time); ?>" class="mhbo-form-input" placeholder="14:00">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Standard guest check-in arrival time (24-hour HH:MM format).', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_checkout_time"><?php echo esc_html(I18n::get_label('settings_label_checkout')); ?></label>
                        <input type="text" id="mhbo_checkout_time" name="mhbo_checkout_time" value="<?php echo esc_attr($co_time); ?>" class="mhbo-form-input" placeholder="11:00">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Standard guest check-out departure time (24-hour HH:MM format).', 'modern-hotel-booking')); ?></p>
                    </div>

<div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_global_min_stay"><?php echo esc_html(I18n::get_label('settings_label_global_min_nights')); ?></label>
                        <input type="number" id="mhbo_global_min_stay" name="mhbo_global_min_stay" value="<?php echo esc_attr( (string) $global_min); ?>" min="0" max="365" class="mhbo-form-input" placeholder="0">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Global minimum length of stay across all rooms (0 to disable).', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_global_max_stay"><?php echo esc_html(I18n::get_label('settings_label_global_max_nights')); ?></label>
                        <input type="number" id="mhbo_global_max_stay" name="mhbo_global_max_stay" value="<?php echo esc_attr( (string) $global_max); ?>" min="0" max="365" class="mhbo-form-input" placeholder="0">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Global maximum length of stay across all rooms (0 to disable).', 'modern-hotel-booking')); ?></p>
                    </div>

</div>
            </div>

<!-- Card 4: Booking Flow & Frontend Experience -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-desktop"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(__('Booking Flow & Frontend Experience', 'modern-hotel-booking')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Manage public booking page routing, drawer modal presentation, and post-booking actions.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_booking_page"><?php echo esc_html(I18n::get_label('settings_label_booking_page')); ?></label>
                        <?php
                        $b_page_dropdown = wp_dropdown_pages(array(
                            'name'             => 'mhbo_booking_page',
                            'id'               => 'mhbo_booking_page',
                            'selected'         => absint($b_page),
                            'show_option_none' => esc_html(I18n::get_label('settings_opt_none_page')),
                            'class'            => 'mhbo-form-input',
                            'echo'             => 0,
                        ));
                        echo wp_kses(
                            (string) $b_page_dropdown,
                            array(
                                'select' => array(
                                    'name'  => true,
                                    'id'    => true,
                                    'class' => true,
                                ),
                                'option' => array(
                                    'value'    => true,
                                    'selected' => true,
                                    'class'    => true,
                                ),
                            )
                        );
                        ?>
                        <p class="mhbo-form-help"><?php echo esc_html(I18n::get_label('settings_desc_booking_shortcode')); ?></p>
                        <?php
                        $booking_page_post = $b_page > 0 ? get_post($b_page) : null;
                        $has_booking_shortcode = false;
                        if ($booking_page_post instanceof \WP_Post) {
                            $b_content = (string) $booking_page_post->post_content;
                            $has_booking_shortcode = has_shortcode($b_content, 'mhbo_booking_form') || has_shortcode($b_content, 'modern_hotel_booking') || has_block('modern-hotel-booking/booking-form', $b_content);
                        }
                        ?>
                        <?php if ($b_page > 0) : ?>
                            <?php if ($has_booking_shortcode) : ?>
                                <div style="margin-top: 6px; font-size: 11.5px; color: #16a34a; font-weight: 600; display: flex; align-items: center; gap: 4px;">
                                    <span>✓</span> <?php esc_html_e('Booking search shortcode [mhbo_booking_form] or Modern Hotel Booking block detected on this page.', 'modern-hotel-booking'); ?>
                                </div>
                            <?php else : ?>
                                <div style="margin-top: 6px; font-size: 11.5px; color: #d97706; font-weight: 500; display: flex; align-items: center; gap: 4px;">
                                    <span>⚠️</span> <?php esc_html_e('Notice: Selected page does not contain [mhbo_booking_form] or the Modern Hotel Booking block yet.', 'modern-hotel-booking'); ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_booking_page_url"><?php echo esc_html(I18n::get_label('settings_label_booking_override')); ?></label>
                        <input type="text" id="mhbo_booking_page_url" name="mhbo_booking_page_url" value="<?php echo esc_attr($b_url); ?>" class="mhbo-form-input" placeholder="e.g. /reservations or https://yoursite.com/book">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Optional. Overrides the standard booking page selection with a custom relative path or full URL.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group mhbo-span-full">
                        <div class="mhbo-switch-row">
                            <div class="mhbo-switch-info">
                                <span class="mhbo-switch-title"><?php echo esc_html(__('Enable Inline Booking Modal Drawer', 'modern-hotel-booking')); ?></span>
                                <p class="mhbo-switch-desc"><?php echo esc_html(__('Open the booking flow in an interactive slide-in drawer instead of navigating away.', 'modern-hotel-booking')); ?></p>
                            </div>
                            <label class="mhbo-toggle-switch">
                                <input type="hidden" name="mhbo_modal_enabled" value="0">
                                <input type="checkbox" id="mhbo_modal_enabled" name="mhbo_modal_enabled" value="1" <?php checked(1, $modal_en); ?>>
                                <span class="mhbo-toggle-slider"></span>
                            </label>
                        </div>
                        <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-top: 10px; font-size: 12px; color: #475569; line-height: 1.5;">
                            <div style="font-weight: 700; color: #0f172a; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                                <span>💡</span> <?php esc_html_e('How Booking Presentation Works (Modal Drawer vs. Page Redirect)', 'modern-hotel-booking'); ?>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 10px;">
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid <?php echo $modal_en ? '#93c5fd' : '#e2e8f0'; ?>;">
                                    <div style="font-weight: 700; color: #2563eb; margin-bottom: 3px;">
                                        <?php esc_html_e('1. Inline Modal Drawer (Active when toggled ON)', 'modern-hotel-booking'); ?>
                                    </div>
                                    <p style="margin: 0; font-size: 11.5px; color: #64748b; line-height: 1.5;">
                                        <?php esc_html_e('When guests select dates on a direct room calendar ([mhbo_room_calendar]) or click "Book Now", a slide-out drawer opens smoothly on the current screen. Guests complete reservations without ever leaving the page. Ideal for modern conversion rates.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid <?php echo ! $modal_en ? '#93c5fd' : '#e2e8f0'; ?>;">
                                    <div style="font-weight: 700; color: #475569; margin-bottom: 3px;">
                                        <?php esc_html_e('2. Dedicated Page Redirect (Active when toggled OFF)', 'modern-hotel-booking'); ?>
                                    </div>
                                    <p style="margin: 0; font-size: 11.5px; color: #64748b; line-height: 1.5;">
                                        <?php esc_html_e('When dates are chosen on a room calendar, guests are redirected to your primary Booking Page (set above) with dates pre-filled into the full accommodation search form ([mhbo_booking_form]). Ideal for traditional multi-step checkout funnels.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

</div>
            </div>

            <!-- Card 5: Operational Notifications & Housekeeping -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-email-alt"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title">
                                <?php echo esc_html(__('Operational Notifications & Housekeeping', 'modern-hotel-booking')); ?>
                            </h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Admin notification inboxes and daily housekeeping turnover manifest automation.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_notification_email"><?php echo esc_html(I18n::get_label('settings_label_notification')); ?></label>
                        <input type="email" id="mhbo_notification_email" name="mhbo_notification_email" value="<?php echo esc_attr($notif_email); ?>" class="mhbo-form-input" placeholder="admin@example.com">
                        <p class="mhbo-form-help">
                            <?php echo esc_html(__('Primary admin inbox for new booking alerts and reservation updates.', 'modern-hotel-booking')); ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=emails#mhbo-email-health-card')); ?>" style="display: inline-block; margin-left: 6px; font-weight: 600; text-decoration: none; color: #2563eb;">
                                <?php esc_html_e('Email Health Inspector & Test Probe →', 'modern-hotel-booking'); ?>
                            </a>
                        </p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_additional_notification_email"><?php echo esc_html(__('Additional Notification Email (CC)', 'modern-hotel-booking')); ?></label>
                        <input type="text" id="mhbo_additional_notification_email" name="mhbo_additional_notification_email" value="<?php echo esc_attr($cc_email); ?>" class="mhbo-form-input" placeholder="manager@example.com, accountant@example.com">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Optional secondary email address(es) copied on every admin booking alert. Multiple addresses can be separated by commas.', 'modern-hotel-booking')); ?></p>
                    </div>

</div>
            </div>

            <!-- Card 6: Guest CRM & Intelligence -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-admin-users"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(__('Guest CRM & Intelligence', 'modern-hotel-booking')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Guest profile unification, lifetime stay history, staff notes, and VIP thresholds.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group mhbo-span-full">
                        <div class="mhbo-switch-row">
                            <div class="mhbo-switch-info">
                                <span class="mhbo-switch-title"><?php echo esc_html(__('Enable Guest CRM & Profiles', 'modern-hotel-booking')); ?></span>
                                <p class="mhbo-switch-desc"><?php echo esc_html(__('Centralize guest profiles, lifetime stay metrics, staff ratings, and customer intelligence.', 'modern-hotel-booking')); ?></p>
                            </div>
                            <label class="mhbo-toggle-switch">
                                <input type="hidden" name="mhbo_guest_crm_enabled" value="0">
                                <input type="checkbox" id="mhbo_guest_crm_enabled" name="mhbo_guest_crm_enabled" value="1" <?php checked(1, $crm_en); ?>>
                                <span class="mhbo-toggle-slider"></span>
                            </label>
                        </div>

</div>

</div>
            </div>

            <!-- Card 7: Custom Guest Checkout Fields -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-forms"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(I18n::get_label('settings_label_custom_fields')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Add extra required or optional fields to the booking checkout form.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div>
                    <?php self::render_custom_fields_repeater(array('label_for' => 'mhbo_custom_fields')); ?>
                </div>
            </div>

            <!-- Card 8: Currency & Display Formatting -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-money-alt"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(I18n::get_label('settings_section_currency')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Configure the property display currency, symbol format, and price decimals.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_currency_code"><?php echo esc_html(I18n::get_label('settings_label_currency_code')); ?></label>
                        <input type="text" id="mhbo_currency_code" name="mhbo_currency_code" value="<?php echo esc_attr($curr_code); ?>" class="mhbo-form-input" maxlength="3" style="text-transform: uppercase;">
                        <p class="mhbo-form-help"><?php echo esc_html(__('3-letter ISO code (e.g. USD, EUR, GBP, RON).', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_currency_symbol"><?php echo esc_html(I18n::get_label('settings_label_currency_symbol')); ?></label>
                        <input type="text" id="mhbo_currency_symbol" name="mhbo_currency_symbol" value="<?php echo esc_attr($curr_sym); ?>" class="mhbo-form-input">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Symbol displayed next to rates (e.g. $, €, £, lei).', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_currency_position"><?php echo esc_html(I18n::get_label('settings_label_currency_pos')); ?></label>
                        <select id="mhbo_currency_position" name="mhbo_currency_position" class="mhbo-form-input">
                            <option value="before" <?php selected($curr_pos, 'before'); ?>><?php echo esc_html(I18n::get_label('settings_opt_before')); ?></option>
                            <option value="after" <?php selected($curr_pos, 'after'); ?>><?php echo esc_html(I18n::get_label('settings_opt_after')); ?></option>
                        </select>
                        <p class="mhbo-form-help"><?php echo esc_html(__('Position of currency symbol relative to amount.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_price_decimals"><?php echo esc_html(__('Price Decimals (Display & Gateway Totals)', 'modern-hotel-booking')); ?></label>
                        <select id="mhbo_price_decimals" name="mhbo_price_decimals" class="mhbo-form-input">
                            <option value="2" <?php selected($price_decimals, '2'); ?>><?php echo esc_html(__('2 Decimal Places (Standard — e.g. $120.00)', 'modern-hotel-booking')); ?></option>
                            <option value="0" <?php selected($price_decimals, '0'); ?>><?php echo esc_html(__('0 Decimal Places (Whole numbers / JPY — e.g. ¥12,000)', 'modern-hotel-booking')); ?></option>
                            <option value="3" <?php selected($price_decimals, '3'); ?>><?php echo esc_html(__('3 Decimal Places (e.g. KWD / BHD 12.500)', 'modern-hotel-booking')); ?></option>
                            <option value="4" <?php selected($price_decimals, '4'); ?>><?php echo esc_html(__('4 Decimal Places (Accounting / Micro-rates)', 'modern-hotel-booking')); ?></option>
                            <option value="auto" <?php selected($price_decimals, 'auto'); ?>><?php echo esc_html(__('Automatic (Based on ISO 4217 Currency Code)', 'modern-hotel-booking')); ?></option>
                        </select>
                        <p class="mhbo-form-help"><?php echo esc_html(__('Standard precision used across booking checkout, totals, deposits, taxes, emails, and invoices. Defaults to 2 decimals for exact cent matching.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group mhbo-span-full">
                        <div class="mhbo-switch-row">
                            <div class="mhbo-switch-info">
                                <span class="mhbo-switch-title"><?php echo esc_html(__('Show Decimals on Calendar Date Cells', 'modern-hotel-booking')); ?></span>
                                <p class="mhbo-switch-desc"><?php echo esc_html(__('When disabled (default), daily price labels inside calendar date cells are shown as uncluttered whole numbers. When enabled, date cells display full decimals according to the setting above.', 'modern-hotel-booking')); ?></p>
                            </div>
                            <label class="mhbo-toggle-switch">
                                <input type="hidden" name="mhbo_calendar_show_decimals" value="0">
                                <input type="checkbox" id="mhbo_calendar_show_decimals" name="mhbo_calendar_show_decimals" value="1" <?php checked(1, $decimals); ?>>
                                <span class="mhbo-toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="mhbo-form-group mhbo-span-full">
                        <div class="mhbo-switch-row">
                            <div class="mhbo-switch-info">
                                <span class="mhbo-switch-title"><?php echo esc_html(I18n::get_label('settings_label_powered_by')); ?></span>
                                <p class="mhbo-switch-desc"><?php echo esc_html(I18n::get_label('settings_desc_powered_by')); ?></p>
                            </div>
                            <label class="mhbo-toggle-switch">
                                <input type="hidden" name="mhbo_powered_by_link" value="0">
                                <input type="checkbox" id="mhbo_powered_by_link" name="mhbo_powered_by_link" value="1" <?php checked(1, $powered_by); ?>>
                                <span class="mhbo-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 9: Guest Portal & Digital Compendium -->
            <div class="mhbo-settings-section-card" id="mhbo-guest-portal-section">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-welcome-learn-more"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(__('Guest Portal & Digital Compendium', 'modern-hotel-booking')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Configure your guest self-service portal, property Wi-Fi access, and house rules. Guests access the portal to review stay details, copy Wi-Fi passwords, request stay changes, and make balance payments.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group mhbo-span-full">
                        <label class="mhbo-form-label" for="mhbo_guest_portal_page">
                            <?php echo esc_html(__('Guest Self-Service Portal Page', 'modern-hotel-booking')); ?>
                        </label>
                        <select name="mhbo_guest_portal_page" id="mhbo_guest_portal_page" class="mhbo-form-input">
                            <option value="0"><?php echo esc_html__('— Select WordPress Page hosting [mhbo_guest_portal] —', 'modern-hotel-booking'); ?></option>
                            <?php
                            $wp_pages            = get_pages();
                            $selected_portal_obj = null;
                            if ( is_array( $wp_pages ) ) {
                                foreach ( $wp_pages as $p ) {
                                    if ( (int) $p->ID === $portal_page ) {
                                        $selected_portal_obj = $p;
                                    }
                                    printf(
                                        '<option value="%d" %s>%s</option>',
                                        esc_attr( (string) $p->ID ),
                                        selected( $portal_page, $p->ID, false ),
                                        esc_html( $p->post_title )
                                    );
                                }
                            }
                            ?>
                        </select>
                        <p class="mhbo-form-help">
                            <?php esc_html_e('Select the page hosting the [mhbo_guest_portal] shortcode. All booking confirmation emails automatically generate verified 1-click access links for guests to manage their reservation.', 'modern-hotel-booking'); ?>
                            <?php if ( $selected_portal_obj ) : ?>
                                <?php $has_portal_sc = str_contains( (string) $selected_portal_obj->post_content, '[mhbo_guest_portal]' ); ?>
                                <span style="display: block; margin-top: 6px;">
                                    <?php if ( $has_portal_sc ) : ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; color: #166534; background: #dcfce7; padding: 2px 8px; border-radius: 9999px;">✓ <?php esc_html_e( 'Shortcode [mhbo_guest_portal] active', 'modern-hotel-booking' ); ?></span>
                                        <a href="<?php echo esc_url( (string) get_permalink( $selected_portal_obj->ID ) ); ?>" target="_blank" rel="noopener noreferrer" style="font-size: 12px; margin-left: 6px; text-decoration: underline; color: #2563eb;"><?php esc_html_e( 'View Portal Page &rarr;', 'modern-hotel-booking' ); ?></a>
                                    <?php else : ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; color: #b45309; background: #fef3c7; padding: 2px 8px; border-radius: 9999px;">⚠️ <?php esc_html_e( '[mhbo_guest_portal] shortcode not detected on this page', 'modern-hotel-booking' ); ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php else : ?>
                                <span style="display: block; margin-top: 6px; color: #d97706; font-size: 12px; font-weight: 500;">
                                    ℹ️ <?php esc_html_e('To enable guest self-service, create a page (e.g. "My Booking" or "Guest Portal"), insert the [mhbo_guest_portal] shortcode, and select it above.', 'modern-hotel-booking'); ?>
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>

<!-- Connected Property Data & Experience Readiness Checklist -->
                    <div class="mhbo-form-group mhbo-span-full">
                        <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 18px 20px;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                                <span style="font-size: 18px;">✨</span>
                                <strong style="font-size: 14px; color: #0f172a;"><?php esc_html_e('Connected Property Data & Arrival Experience', 'modern-hotel-booking'); ?></strong>
                            </div>
                            <p style="margin: 0 0 14px 0; font-size: 12.5px; color: #64748b; line-height: 1.5;">
                                <?php esc_html_e('The Guest Self-Service Portal dynamically synthesizes essential details configured across your settings to create a seamless digital arrival card. When guests access their portal or read confirmation emails, these fields are automatically displayed:', 'modern-hotel-booking'); ?>
                            </p>
                            <?php
                            $company_data = class_exists('\MHBO\Business\Info') ? \MHBO\Business\Info::get_company() : array();
                            $banking_data = class_exists('\MHBO\Business\Info') ? \MHBO\Business\Info::get_banking() : array();
                            $revolut_data = class_exists('\MHBO\Business\Info') ? \MHBO\Business\Info::get_revolut() : array();

                            $hotel_name  = ! empty( $company_data['company_name'] ) ? (string) $company_data['company_name'] : (string) get_option('mhbo_hotel_name', '');
                            $hotel_email = ! empty( $company_data['email'] ) ? (string) $company_data['email'] : (string) get_option('admin_email', '');
                            $hotel_addr  = ! empty( $company_data['address_line_1'] ) ? (string) $company_data['address_line_1'] : (string) get_option('mhbo_hotel_address', '');
                            $hotel_maps  = ! empty( $company_data['google_maps_url'] ) ? (string) $company_data['google_maps_url'] : (string) get_option('mhbo_hotel_google_maps_url', '');

                            $is_bank_active  = ! empty( $banking_data['enabled'] ) && ( ! empty( $banking_data['iban'] ) || ! empty( $banking_data['bank_name'] ) || ! empty( $banking_data['account_number'] ) );
                            $is_rev_active   = ! empty( $revolut_data['enabled'] ) && ( ! empty( $revolut_data['revolut_tag'] ) || ! empty( $revolut_data['revolut_link'] ) || ! empty( $revolut_data['revolut_iban'] ) );
                            $hotel_bank      = (string) get_option('mhbo_bank_details', '');
                            $hotel_rev       = (string) get_option('mhbo_revolut_revtag', '');
                            $has_offline_pay = $is_bank_active || $is_rev_active || ! empty( $hotel_bank ) || ! empty( $hotel_rev );

                            $is_name_ready = ! empty( $hotel_name );
                            $is_addr_ready = ! empty( $hotel_addr );
                            $is_maps_ready = ! empty( $hotel_maps );
                            $is_wifi_ready = ! empty( $wifi_ssid );
                            $is_all_ready  = $is_name_ready && $is_addr_ready && $is_maps_ready && $is_wifi_ready;
                            ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; font-size: 12.5px;">
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <strong><?php esc_html_e('Property Name & Contact', 'modern-hotel-booking'); ?></strong>
                                        <?php if ( $is_name_ready ) : ?>
                                            <span style="color: #16a34a; font-weight: 700;">✓ Ready</span>
                                        <?php else : ?>
                                            <span style="color: #d97706; font-weight: 600;">⚠️ Incomplete</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $is_name_ready ) : ?>
                                        <div style="color: #0f172a; font-weight: 600; font-size: 12px;"><?php echo esc_html($hotel_name); ?></div>
                                        <?php if ( ! empty($hotel_email) ) : ?>
                                            <div style="color: #64748b; font-size: 11.5px;"><?php echo esc_html($hotel_email); ?></div>
                                        <?php endif; ?>
                                        <div style="margin-top: 4px;"><a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business')); ?>" style="text-decoration: underline; color: #2563eb;"><?php esc_html_e('Edit in Business Info &rarr;', 'modern-hotel-booking'); ?></a></div>
                                    <?php else : ?>
                                        <span style="color: #64748b; font-size: 11.5px;"><?php esc_html_e('Shown in portal header & email footers.', 'modern-hotel-booking'); ?></span>
                                        <div style="margin-top: 4px;"><a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business')); ?>" style="text-decoration: underline; color: #2563eb;"><?php esc_html_e('Set in Business Info &rarr;', 'modern-hotel-booking'); ?></a></div>
                                    <?php endif; ?>
                                </div>
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <strong><?php esc_html_e('Google Maps & Directions', 'modern-hotel-booking'); ?></strong>
                                        <?php if ( $is_maps_ready ) : ?>
                                            <span style="color: #16a34a; font-weight: 700;">✓ Ready</span>
                                        <?php else : ?>
                                            <span style="color: #64748b; font-weight: 500;"><?php esc_html_e('Optional', 'modern-hotel-booking'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <span style="color: #64748b; font-size: 11.5px;"><?php esc_html_e('Renders 1-click navigation in portal.', 'modern-hotel-booking'); ?></span>
                                    <div style="margin-top: 4px;"><a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business')); ?>" style="text-decoration: underline; color: #2563eb;"><?php echo $is_maps_ready ? esc_html__('Edit Maps Link &rarr;', 'modern-hotel-booking') : esc_html__('Add Maps URL &rarr;', 'modern-hotel-booking'); ?></a></div>
                                </div>
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <strong><?php esc_html_e('Bank & Revolut Pay', 'modern-hotel-booking'); ?></strong>
                                        <?php if ( $has_offline_pay ) : ?>
                                            <span style="color: #16a34a; font-weight: 700;">✓ Configured</span>
                                        <?php else : ?>
                                            <span style="color: #64748b; font-weight: 500;"><?php esc_html_e('Optional', 'modern-hotel-booking'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $has_offline_pay ) : ?>
                                        <div style="display: flex; gap: 4px; margin-top: 2px; margin-bottom: 4px; flex-wrap: wrap;">
                                            <?php if ( $is_bank_active || ! empty($hotel_bank) ) : ?>
                                                <span style="background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 4px; font-size: 11px; font-weight: 600;">Bank</span>
                                            <?php endif; ?>
                                            <?php if ( $is_rev_active || ! empty($hotel_rev) ) : ?>
                                                <span style="background: #f3e8ff; color: #7e22ce; padding: 1px 6px; border-radius: 4px; font-size: 11px; font-weight: 600;">Revolut</span>
                                            <?php endif; ?>
                                        </div>
                                        <div><a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business&subtab=banking')); ?>" style="text-decoration: underline; color: #2563eb;"><?php esc_html_e('Manage Offline Pay &rarr;', 'modern-hotel-booking'); ?></a></div>
                                    <?php else : ?>
                                        <span style="color: #64748b; font-size: 11.5px;"><?php esc_html_e('Instructs offline/direct paying guests.', 'modern-hotel-booking'); ?></span>
                                        <div style="margin-top: 4px;"><a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business&subtab=banking')); ?>" style="text-decoration: underline; color: #2563eb;"><?php esc_html_e('Configure in Business Info &rarr;', 'modern-hotel-booking'); ?></a></div>
                                    <?php endif; ?>
                                </div>
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <strong><?php esc_html_e('Guest Wi-Fi Credentials', 'modern-hotel-booking'); ?></strong>
                                        <?php if ( $is_wifi_ready ) : ?>
                                            <span style="color: #16a34a; font-weight: 700;">✓ Ready</span>
                                        <?php else : ?>
                                            <span style="color: #64748b; font-weight: 500;"><?php esc_html_e('Optional', 'modern-hotel-booking'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $is_wifi_ready ) : ?>
                                        <div style="color: #0f172a; font-weight: 600; font-size: 12px;"><?php echo esc_html($wifi_ssid); ?></div>
                                    <?php endif; ?>
                                    <span style="color: #64748b; font-size: 11.5px;"><?php esc_html_e('Instant 1-click password copy for guests.', 'modern-hotel-booking'); ?></span>
                                </div>
                            </div>
                            <?php if ( $is_all_ready ) : ?>
                                <div style="margin-top: 12px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 8px 12px; color: #15803d; font-size: 12px; display: flex; align-items: center; gap: 6px;">
                                    <span>🎉</span>
                                    <span><?php esc_html_e('All core property details are configured! Your Guest Portal is fully equipped to deliver an outstanding arrival experience.', 'modern-hotel-booking'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_hotel_wifi_ssid"><?php echo esc_html(__('Guest Wi-Fi Network Name (SSID)', 'modern-hotel-booking')); ?></label>
                        <input type="text" id="mhbo_hotel_wifi_ssid" name="mhbo_hotel_wifi_ssid" value="<?php echo esc_attr($wifi_ssid); ?>" class="mhbo-form-input" placeholder="e.g. GrandHotel_Guest">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Leave blank to hide the Wi-Fi card in the guest self-service portal.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_hotel_wifi_password"><?php echo esc_html(__('Guest Wi-Fi Password', 'modern-hotel-booking')); ?></label>
                        <input type="text" id="mhbo_hotel_wifi_password" name="mhbo_hotel_wifi_password" value="<?php echo esc_attr($wifi_pass); ?>" class="mhbo-form-input" placeholder="e.g. Welcome2026!">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Guests can copy this password with 1 click from their verified portal screen.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group mhbo-span-full">
                        <label class="mhbo-form-label" for="mhbo_hotel_guest_instructions"><?php echo esc_html(__('Digital House Rules & Check-In Guide', 'modern-hotel-booking')); ?></label>
                        <textarea id="mhbo_hotel_guest_instructions" name="mhbo_hotel_guest_instructions" rows="4" class="large-text" placeholder="<?php echo esc_attr(__('e.g. Breakfast is served 7:00-10:30 AM in the Garden Pavilion. Front desk is staffed 24/7. Quiet hours begin at 10:00 PM.', 'modern-hotel-booking')); ?>"><?php echo esc_textarea($guest_guide); ?></textarea>
                        <p class="mhbo-form-help"><?php echo esc_html(__('Helpful instructions, breakfast hours, parking directions, and house rules. Rendered conditionally in the guest portal.', 'modern-hotel-booking')); ?></p>
                    </div>
                </div>
            </div>

            <!-- Card 10: System & Security -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-shield"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(I18n::get_label('settings_section_security')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Reverse proxy trust list for client IP detection and plugin data retention.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group mhbo-span-full">
                        <label class="mhbo-form-label" for="mhbo_trusted_proxies"><?php echo esc_html(I18n::get_label('settings_label_proxies')); ?></label>
                        <textarea id="mhbo_trusted_proxies" name="mhbo_trusted_proxies" rows="3" class="large-text code" placeholder="127.0.0.1&#10;10.0.0.0/8&#10;172.16.0.0/12"><?php echo esc_textarea($proxies); ?></textarea>
                        <p class="mhbo-form-help"><?php echo esc_html(I18n::get_label('settings_desc_proxies')); ?></p>
                    </div>

                    <div class="mhbo-form-group mhbo-span-full">
                        <div class="mhbo-switch-row">
                            <div class="mhbo-switch-info">
                                <span class="mhbo-switch-title"><?php echo esc_html(I18n::get_label('settings_label_uninstall')); ?></span>
                                <p class="mhbo-switch-desc"><?php echo esc_html(I18n::get_label('settings_desc_uninstall')); ?></p>
                            </div>
                            <label class="mhbo-toggle-switch">
                                <input type="hidden" name="mhbo_save_data_on_uninstall" value="0">
                                <input type="checkbox" id="mhbo_save_data_on_uninstall" name="mhbo_save_data_on_uninstall" value="1" <?php checked(1, $keep_data); ?>>
                                <span class="mhbo-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Render license tab.
     *
     * @return void
     */
    private static function render_license_tab(): void {
        
    }

/**
     * Render the Email Delivery Health & Diagnostic Inspector card.
     */
    private static function render_email_health_inspector_card(): void {
        $sender_email = \MHBO\Core\Email::get_notification_email();
        $current_user = wp_get_current_user();
        $test_target  = $current_user->user_email ?: $sender_email;
        $site_domain  = (string) preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
        ?>
        <div id="mhbo-email-health-card" class="mhbo-card mhbo-email-health-card" style="margin-bottom: 28px; padding: 24px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 42px; height: 42px; border-radius: 10px; background: #eff6ff; display: flex; align-items: center; justify-content: center; color: #2563eb;">
                        <span class="dashicons dashicons-shield" style="font-size: 24px; width: 24px; height: 24px;"></span>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 4px 0; font-size: 17px; font-weight: 700; color: #0f172a;">
                            <?php esc_html_e('Email Delivery Health & Diagnostic Inspector', 'modern-hotel-booking'); ?>
                        </h3>
                        <p style="margin: 0; font-size: 13px; color: #64748b;">
                            <?php esc_html_e('Live audit of your active mailer engine, domain DNS records (SPF & DMARC), and 1-click test probe.', 'modern-hotel-booking'); ?>
                        </p>
                    </div>
                </div>
                <div>
                    <button type="button" id="mhbo-btn-rescan-email" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; margin-top: 1px;"></span>
                        <?php esc_html_e('Re-scan Deliverability', 'modern-hotel-booking'); ?>
                    </button>
                </div>
            </div>

            <!-- Health Tiles Grid -->
            <div id="mhbo-email-diagnostic-tiles" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 20px;">
                <div class="mhbo-diagnostic-tile" style="padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <div style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; margin-bottom: 4px;"><?php esc_html_e('Active Mailer Engine', 'modern-hotel-booking'); ?></div>
                    <div id="mhbo-tile-mailer" style="font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-update mhbo-spin" style="font-size: 16px; width: 16px; height: 16px; color: #94a3b8;"></span>
                        <span style="color: #94a3b8;"><?php esc_html_e('Inspecting…', 'modern-hotel-booking'); ?></span>
                    </div>
                </div>

                <div class="mhbo-diagnostic-tile" style="padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <div style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; margin-bottom: 4px;"><?php esc_html_e('Sender & Domain Alignment', 'modern-hotel-booking'); ?></div>
                    <div id="mhbo-tile-domain" style="font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-update mhbo-spin" style="font-size: 16px; width: 16px; height: 16px; color: #94a3b8;"></span>
                        <span style="color: #94a3b8;"><?php esc_html_e('Inspecting…', 'modern-hotel-booking'); ?></span>
                    </div>
                </div>

                <div class="mhbo-diagnostic-tile" style="padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <div style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; margin-bottom: 4px;"><?php esc_html_e('SPF DNS Record', 'modern-hotel-booking'); ?></div>
                    <div id="mhbo-tile-spf" style="font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-update mhbo-spin" style="font-size: 16px; width: 16px; height: 16px; color: #94a3b8;"></span>
                        <span style="color: #94a3b8;"><?php esc_html_e('Inspecting…', 'modern-hotel-booking'); ?></span>
                    </div>
                </div>

                <div class="mhbo-diagnostic-tile" style="padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <div style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; margin-bottom: 4px;"><?php esc_html_e('DMARC Policy Record', 'modern-hotel-booking'); ?></div>
                    <div id="mhbo-tile-dmarc" style="font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-update mhbo-spin" style="font-size: 16px; width: 16px; height: 16px; color: #94a3b8;"></span>
                        <span style="color: #94a3b8;"><?php esc_html_e('Inspecting…', 'modern-hotel-booking'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Dynamic Alerts Container -->
            <div id="mhbo-diagnostic-alerts" style="display: none; margin-bottom: 20px;"></div>

            <!-- Last Recorded Failure Banner -->
            <div id="mhbo-diagnostic-failure-banner" style="display: none; margin-bottom: 20px; padding: 14px 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b;"></div>

            <!-- 1-Click Send Test Email Probe -->
            <div style="padding: 18px 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 16px;">
                <h4 style="margin: 0 0 6px 0; font-size: 14px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-email-alt" style="color: #2563eb;"></span>
                    <?php esc_html_e('1-Click Diagnostic Email Probe', 'modern-hotel-booking'); ?>
                </h4>
                <p style="margin: 0 0 14px 0; font-size: 13px; color: #64748b;">
                    <?php esc_html_e('Send a live test email directly through your server. If sending fails, the system will intercept the underlying PHPMailer error and display troubleshooting steps.', 'modern-hotel-booking'); ?>
                </p>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 260px;">
                        <input type="email" id="mhbo-test-email-recipient" class="regular-text" style="width: 100%; max-width: 420px; height: 38px; border-radius: 6px;" value="<?php echo esc_attr($test_target); ?>" placeholder="you@example.com">
                    </div>
                    <button type="button" id="mhbo-btn-send-test-email" class="button button-primary" style="height: 38px; display: inline-flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-email" style="margin-top: 2px;"></span>
                        <?php esc_html_e('Send Test Email', 'modern-hotel-booking'); ?>
                    </button>
                    <span id="mhbo-test-email-spinner" class="dashicons dashicons-update mhbo-spin" style="display: none; color: #2563eb; font-size: 20px; width: 20px; height: 20px;"></span>
                </div>
                <div id="mhbo-test-email-result" style="display: none; margin-top: 14px;"></div>
            </div>

            <!-- Collapsible Deliverability Setup Guide -->
            <details class="mhbo-deliverability-guide" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #ffffff;">
                <summary style="padding: 12px 16px; font-size: 13px; font-weight: 600; color: #334155; cursor: pointer; background: #f8fafc; user-select: none;">
                    📖 <?php esc_html_e('Domain Setup & Recommended SMTP Solutions (Best Practices Guide)', 'modern-hotel-booking'); ?>
                </summary>
                <div style="padding: 18px 20px; font-size: 13px; color: #475569; line-height: 1.6;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                        <div>
                            <h5 style="margin: 0 0 6px 0; font-size: 14px; color: #0f172a; font-weight: 700;">🚀 <?php esc_html_e('Option A (Recommended): Install a Dedicated SMTP Plugin', 'modern-hotel-booking'); ?></h5>
                            <p style="margin: 0 0 10px 0;">
                                <?php esc_html_e('Default WordPress PHP mail() is sent directly from your web server without reputation or digital signing, which causes Gmail, Yahoo, and Outlook to flag emails as spam or drop them silently.', 'modern-hotel-booking'); ?>
                            </p>
                            <p style="margin: 0 0 10px 0;">
                                <?php esc_html_e('We strongly recommend installing one of the following free plugins to route emails through an authenticated provider (such as Brevo, SendGrid, Amazon SES, Google Workspace, or Microsoft 365):', 'modern-hotel-booking'); ?>
                            </p>
                            <ul style="margin: 0 0 10px 20px; list-style: disc;">
                                <li><strong>FluentSMTP</strong> (100% Free, zero ads, excellent logging)</li>
                                <li><strong>WP Mail SMTP</strong> (Widely used, simple setup wizard)</li>
                            </ul>
                        </div>
                        <div>
                            <h5 style="margin: 0 0 6px 0; font-size: 14px; color: #0f172a; font-weight: 700;">🛡️ <?php esc_html_e('Domain DNS Authentication Checklist', 'modern-hotel-booking'); ?></h5>
                            <p style="margin: 0 0 8px 0;">
                                <?php esc_html_e('To pass modern 2024–2026 inbox security checks, your domain DNS manager (e.g. Cloudflare, cPanel, GoDaddy) must have:', 'modern-hotel-booking'); ?>
                            </p>
                            <div style="background: #f1f5f9; padding: 10px 14px; border-radius: 6px; font-family: monospace; font-size: 12px; margin-bottom: 8px;">
                                <strong>SPF TXT:</strong> v=spf1 include:your-mail-host.com ~all
                            </div>
                            <div style="background: #f1f5f9; padding: 10px 14px; border-radius: 6px; font-family: monospace; font-size: 12px; margin-bottom: 8px;">
                                <strong>DMARC TXT:</strong> Name: _dmarc.<?php echo esc_html($site_domain); ?><br>Value: v=DMARC1; p=none;
                            </div>
                            <p style="margin: 0; font-size: 12px; color: #64748b;">
                                <em><?php esc_html_e('Tip: Never use an @gmail.com or @yahoo.com address as the sender from your custom domain — Google and Yahoo DMARC policies will reject the mail at the receiving server.', 'modern-hotel-booking'); ?></em>
                            </p>
                        </div>
                    </div>
                </div>
            </details>
        </div>
        <?php
    }

    /**
     * Render email templates tab.
     *
     * @return void
     */
    private static function render_email_templates_tab(): void {
        $is_pro = class_exists('\MHBO\Core\Tier') ? \MHBO\Core\Tier::is_effective_at_least(\MHBO\Core\Tier::TIER_PRO) : false;

        $statuses = array(
            'pending'   => array(
                'label'     => I18n::get_label('email_status_pending'),
                'badge_bg'  => '#fef3c7',
                'badge_fg'  => '#92400e',
                'icon'      => 'dashicons-clock',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest immediately when their booking request is awaiting confirmation.', 'modern-hotel-booking'),
            ),
            'confirmed' => array(
                'label'     => I18n::get_label('email_status_confirmed'),
                'badge_bg'  => '#dcfce7',
                'badge_fg'  => '#166534',
                'icon'      => 'dashicons-yes-alt',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest when their reservation is approved & confirmed.', 'modern-hotel-booking'),
            ),
            'cancelled' => array(
                'label'     => I18n::get_label('email_status_cancelled'),
                'badge_bg'  => '#fee2e2',
                'badge_fg'  => '#991b1b',
                'icon'      => 'dashicons-no-alt',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest if their booking is rejected or cancelled.', 'modern-hotel-booking'),
            ),
            'payment'   => array(
                'label'     => I18n::get_label('email_status_payment'),
                'badge_bg'  => '#eff6ff',
                'badge_fg'  => '#1e40af',
                'icon'      => 'dashicons-money-alt',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Payment receipt sent to the guest when checkout payment is completed.', 'modern-hotel-booking'),
            ),
            'stay_dates_updated' => array(
                'label'     => I18n::get_label('email_status_stay_dates_updated'),
                'badge_bg'  => '#ecfdf5',
                'badge_fg'  => '#065f46',
                'icon'      => 'dashicons-calendar',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest acknowledging that their stay dates have been updated in the Guest Self-Service Portal.', 'modern-hotel-booking'),
            ),
            'modification_request' => array(
                'label'     => I18n::get_label('email_status_modification_request'),
                'badge_bg'  => '#e0e7ff',
                'badge_fg'  => '#3730a3',
                'icon'      => 'dashicons-calendar-alt',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest acknowledging that their stay modification request has been submitted to the front desk.', 'modern-hotel-booking'),
            ),
            'cancellation_request' => array(
                'label'     => I18n::get_label('email_status_cancellation_request'),
                'badge_bg'  => '#fef3c7',
                'badge_fg'  => '#92400e',
                'icon'      => 'dashicons-warning',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Sent to the guest acknowledging that their cancellation request for a paid booking has been received and is under staff review.', 'modern-hotel-booking'),
            ),
            'email_updated' => array(
                'label'     => I18n::get_label('email_status_email_updated'),
                'badge_bg'  => '#fee2e2',
                'badge_fg'  => '#991b1b',
                'icon'      => 'dashicons-shield',
                'pro'       => false,
                'recipient' => _x('Guest & Admin', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('Security alert dispatched to old and new email addresses, as well as property staff, when a contact email is updated.', 'modern-hotel-booking'),
            ),
            'identity_verification' => array(
                'label'     => I18n::get_label('email_status_identity_verification'),
                'badge_bg'  => '#ecfdf5',
                'badge_fg'  => '#065f46',
                'icon'      => 'dashicons-shield-alt',
                'pro'       => false,
                'recipient' => _x('Guest', 'Email template recipient role', 'modern-hotel-booking'),
                'desc'      => __('One-time verification PIN sent to guest email to verify reservation ownership and concierge access.', 'modern-hotel-booking'),
            ),
            'admin_new_booking' => array(
                'label'     => I18n::get_label('email_status_admin_new_booking'),
                'badge_bg'  => '#f3e8ff',
                'badge_fg'  => '#6b21a8',
                'icon'      => 'dashicons-bell',
                'pro'       => false,
                'recipient' => __('Admin / Property', 'modern-hotel-booking'),
                'desc'      => __('Alert sent to property administrators whenever a new booking is submitted.', 'modern-hotel-booking'),
            ),
            'admin_payment' => array(
                'label'     => I18n::get_label('email_status_admin_payment'),
                'badge_bg'  => '#ecfdf5',
                'badge_fg'  => '#065f46',
                'icon'      => 'dashicons-cart',
                'pro'       => false,
                'recipient' => __('Admin / Property', 'modern-hotel-booking'),
                'desc'      => __('Notification sent to property administrators when a payment is processed.', 'modern-hotel-booking'),
            ),
            'admin_modification_request' => array(
                'label'     => I18n::get_label('email_status_admin_modification_request'),
                'badge_bg'  => '#eff6ff',
                'badge_fg'  => '#1d4ed8',
                'icon'      => 'dashicons-edit',
                'pro'       => false,
                'recipient' => __('Admin / Property', 'modern-hotel-booking'),
                'desc'      => __('Alert sent to hotel administrators and reservation staff when a guest submits a stay modification request in the portal.', 'modern-hotel-booking'),
            ),
            'admin_cancellation_request' => array(
                'label'     => I18n::get_label('email_status_admin_cancellation_request'),
                'badge_bg'  => '#fef2f2',
                'badge_fg'  => '#b91c1c',
                'icon'      => 'dashicons-flag',
                'pro'       => false,
                'recipient' => __('Admin / Property', 'modern-hotel-booking'),
                'desc'      => __('Action-required alert sent to property staff when a guest requests cancellation for a reservation with payments on file.', 'modern-hotel-booking'),
            ),
            'admin_cancellation' => array(
                'label'     => I18n::get_label('email_status_admin_cancellation'),
                'badge_bg'  => '#fee2e2',
                'badge_fg'  => '#dc2626',
                'icon'      => 'dashicons-dismiss',
                'pro'       => false,
                'recipient' => __('Admin / Property', 'modern-hotel-booking'),
                'desc'      => __('Notification sent to property administrators when an unpaid reservation is cancelled by a guest via the self-service portal.', 'modern-hotel-booking'),
            ),
            
        );

        $langs = I18n::get_available_languages();
        $is_multilingual = count($langs) > 1;
        ?>
        <?php self::render_email_health_inspector_card(); ?>
        <div class="mhbo-settings-section mhbo-email-templates-section">
            <div style="margin-bottom: 24px;">
                <h3 style="font-size:18px; font-weight:700; color:#0f172a; margin:0 0 6px 0;"><?php esc_html_e('Custom Email Templates & Multilingual Overrides', 'modern-hotel-booking'); ?></h3>
                <p style="color:#64748b; font-size:14px; margin:0; line-height:1.5;">
                    <?php esc_html_e('Customize the subject lines and message bodies of all automated emails. Leave any field empty to use the system default template.', 'modern-hotel-booking'); ?>
                    <?php if ($is_multilingual) : ?>
                        <span style="display:block; margin-top:4px; color:#2563eb; font-weight:500;">
                            🌐 <?php esc_html_e('Multilingual Mode Active: Translate templates individually for each supported language.', 'modern-hotel-booking'); ?>
                        </span>
                    <?php endif; ?>
                    <span style="display:block; margin-top:4px; color:#475569; font-size:13px;">
                        💡 <?php esc_html_e('Theme Overrides: Default email template partials can also be customized via your theme in modern-hotel-booking/emails/ or mhbo/emails/.', 'modern-hotel-booking'); ?>
                    </span>
                </p>
            </div>

            <?php
            foreach ($statuses as $id => $meta) : 
                $is_locked = ! empty($meta['pro']) && ! $is_pro;
                $card_style = $is_locked
                    ? 'margin-bottom: 24px; padding: 22px; background:#fff; border: 1px solid #e9d5ff; border-radius: 12px; box-shadow: 0 1px 3px rgba(147, 51, 234, 0.05);'
                    : 'margin-bottom: 24px; padding: 22px; background:#fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);';
				?>
                <div class="mhbo-card" style="<?php echo esc_attr($card_style); ?>">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                        <div>
                            <h3 style="margin: 0 0 4px 0; font-size:16px; font-weight:700; color:#0f172a; display: flex; align-items: center; gap: 8px;">
                                <span class="dashicons <?php echo esc_attr($meta['icon']); ?>" style="color:<?php echo $is_locked ? '#9333ea' : '#2563eb'; ?>; font-size:20px; width:20px; height:20px;"></span>
                                <?php echo esc_html($meta['label']); ?>
                                <?php if ( ! empty($meta['pro'])) : ?>
                                    <span style="background: linear-gradient(135deg, #2563eb, #7c3aed); color: #fff; font-size: 10px; font-weight: 800; padding: 2px 6px; border-radius: 4px; letter-spacing: 0.5px; text-transform: uppercase;">PRO</span>
                                <?php endif; ?>
                            </h3>
                            <p style="margin:0; font-size:13px; color:#64748b;"><?php echo esc_html($meta['desc']); ?></p>
                        </div>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <span style="font-size:11px; font-weight:600; padding:3px 10px; border-radius:99px; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;">
                                <?php echo esc_html($meta['recipient']); ?>
                            </span>
                            <span style="font-size:11px; font-weight:700; text-transform:uppercase; padding:3px 10px; border-radius:99px; background:<?php echo esc_attr($meta['badge_bg']); ?>; color:<?php echo esc_attr($meta['badge_fg']); ?>;">
                                <?php echo esc_html($id); ?>
                            </span>
                        </div>
                    </div>

                    <?php if ($is_locked) : ?>
                        <div style="background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%); border: 1px solid #d8b4fe; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 32px; height: 32px; border-radius: 50%; background: #9333ea; color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <span class="dashicons dashicons-lock" style="font-size: 16px; width: 16px; height: 16px;"></span>
                                </div>
                                <div>
                                    <strong style="color: #581c87; font-size: 13px; display: block; margin-bottom: 2px;">
                                        <?php
                                        if ( 'balance_payment' === $id || 'balance_payment_confirmed' === $id ) {
                                            esc_html_e('Online Deposits & Balance Payment Automation is a Pro Feature', 'modern-hotel-booking');
                                        } elseif ( 'vip_coupon' === $id ) {
                                            esc_html_e('Automated VIP Loyalty Vouchers & Coupons is a Pro Feature', 'modern-hotel-booking');
                                        } elseif ( 'review_request' === $id ) {
                                            esc_html_e('Automated Post-Stay Review Invitations is a Pro Feature', 'modern-hotel-booking');
                                        } else {
                                            esc_html_e('This Automated Email Workflow is a Pro Feature', 'modern-hotel-booking');
                                        }
                                        ?>
                                    </strong>
                                    <span style="color: #7e22ce; font-size: 12px; line-height: 1.4; display: block;">
                                        <?php
                                        if ( 'balance_payment' === $id || 'balance_payment_confirmed' === $id ) {
                                            esc_html_e('Upgrade to Modern Hotel Booking Pro to collect partial deposits, automate balance payment links, and dispatch instant settlement receipts.', 'modern-hotel-booking');
                                        } elseif ( 'vip_coupon' === $id ) {
                                            esc_html_e('Upgrade to Modern Hotel Booking Pro to generate single-use discount codes and send targeted promotional incentives to your VIP guests.', 'modern-hotel-booking');
                                        } elseif ( 'review_request' === $id ) {
                                            esc_html_e('Upgrade to Modern Hotel Booking Pro to accelerate 5-star reviews on Google Business Profile, TripAdvisor, and custom review channels.', 'modern-hotel-booking');
                                        } else {
                                            esc_html_e('Upgrade to Modern Hotel Booking Pro to unlock full automation and marketing workflows.', 'modern-hotel-booking');
                                        }
                                        ?>
                                    </span>
                                </div>
                            </div>
                            <a href="<?php echo esc_url('https://startmysuccess.com/modern-hotel-booking-wordpress-plugin/'); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="background: #9333ea; border-color: #7e22ce; font-weight: 600; white-space: nowrap; padding: 4px 14px;">
                                <?php esc_html_e('Upgrade to Pro', 'modern-hotel-booking'); ?> &rarr;
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php
                    foreach ($langs as $code) : 
                        $raw_subject = get_option("mhbo_email_{$id}_subject", '');
                        $raw_message = get_option("mhbo_email_{$id}_message", '');
                        
                        $subject = (string) ( I18n::decode($raw_subject, $code, true) ?? '' );
                        $message = (string) ( I18n::decode($raw_message, $code, true) ?? '' );
                        $lang_label = I18n::get_language_name($code);
						?>
                        <div class="mhbo-lang-group" style="<?php echo $is_multilingual ? 'margin-bottom: 18px; padding: 16px 20px; background: #f8fafc; border-radius: 8px; border-left: 4px solid #2563eb;' : ''; ?>">
                            <?php if ($is_multilingual) : ?>
                                <h4 style="margin: 0 0 12px 0; color: #1e40af; font-size:13px; font-weight:700; display:flex; align-items:center; gap:6px;">
                                    <span class="dashicons dashicons-translation" style="font-size:16px; width:16px; height:16px;"></span>
                                    <?php echo esc_html($lang_label); ?> (<?php echo esc_html(strtoupper($code)); ?>)
                                </h4>
                            <?php endif; ?>

                            <div style="margin-bottom: 14px;">
                                <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155;">
                                    <?php echo esc_html(I18n::get_label('email_label_subject')); ?>
                                </label>
                                <input type="text" 
                                    name="mhbo_email_templates[<?php echo esc_attr($id); ?>][subject][<?php echo esc_attr($code); ?>]"
                                    value="<?php echo esc_attr($subject); ?>" 
                                    class="widefat" 
                                    placeholder="<?php echo esc_attr($is_locked ? __('Available exclusively in Modern Hotel Booking Pro...', 'modern-hotel-booking') : __('Leave empty to use default subject...', 'modern-hotel-booking')); ?>"
                                    style="border-radius: 6px; height: 38px;<?php echo $is_locked ? ' background:#f8fafc; color:#94a3b8; cursor:not-allowed;' : ''; ?>"
                                    <?php disabled($is_locked); ?>>
                            </div>

                            <div>
                                <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155;">
                                    <?php echo esc_html(I18n::get_label('email_label_message')); ?>
                                </label>
                                <textarea 
                                    name="mhbo_email_templates[<?php echo esc_attr($id); ?>][message][<?php echo esc_attr($code); ?>]" 
                                    rows="7"
                                    class="widefat" 
                                    placeholder="<?php echo esc_attr($is_locked ? __('Available exclusively in Modern Hotel Booking Pro...', 'modern-hotel-booking') : __('Leave empty to use built-in responsive HTML template...', 'modern-hotel-booking')); ?>"
                                    style="border-radius: 6px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;<?php echo $is_locked ? ' background:#f8fafc; color:#94a3b8; cursor:not-allowed;' : ''; ?>"
                                    <?php disabled($is_locked); ?>><?php echo esc_textarea($message); ?></textarea>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <!-- Comprehensive Categorized Placeholder Reference Guide -->
            <div class="mhbo-card" style="margin-top: 32px; padding: 24px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                    <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-editor-code" style="color: #2563eb; font-size: 22px; width: 22px; height: 22px;"></span>
                        <?php echo esc_html(I18n::get_label('email_placeholders_title')); ?>
                    </h3>
                    <span id="mhbo-copy-toast" style="display:none; font-size:12px; font-weight:600; color:#166534; background:#dcfce7; padding:3px 10px; border-radius:6px; border:1px solid #bbf7d0;">
                        <?php esc_html_e('Copied tag to clipboard!', 'modern-hotel-booking'); ?>
                    </span>
                </div>
                <p style="color: #64748b; font-size: 13px; margin: 0 0 20px 0; line-height: 1.5;">
                    <?php echo esc_html(I18n::get_label('email_placeholders_desc_full')); ?>
                </p>

                <?php
                $placeholder_categories = array(
                    __('Guest Details', 'modern-hotel-booking') => array(
                        '{customer_name}'     => __('Guest full name', 'modern-hotel-booking'),
                        '{guest_name}'        => __('Guest full name (synonym)', 'modern-hotel-booking'),
                        '{first_name}'        => __('Guest first name', 'modern-hotel-booking'),
                        '{last_name}'         => __('Guest last name', 'modern-hotel-booking'),
                        '{customer_email}'    => __('Guest email address', 'modern-hotel-booking'),
                        '{customer_phone}'    => __('Guest phone number', 'modern-hotel-booking'),
                        '{special_requests}'  => __('Special requests submitted at checkout', 'modern-hotel-booking'),
                        '{arrival_time}'      => __('Estimated arrival time', 'modern-hotel-booking'),
                    ),
                    __('Stay & Accommodation Details', 'modern-hotel-booking') => array(
                        '{booking_id}'        => __('Booking ID number (e.g. 1042)', 'modern-hotel-booking'),
                        '{booking_token}'     => __('Secure booking reference token', 'modern-hotel-booking'),
                        '{view_url}'          => __('Direct link to view / manage reservation', 'modern-hotel-booking'),
                        '{room_name}'         => __('Room type name', 'modern-hotel-booking'),
                        '{room_number}'       => __('Assigned room unit number (e.g. 101, A2)', 'modern-hotel-booking'),
                        '{check_in}'          => __('Check-in date (formatted)', 'modern-hotel-booking'),
                        '{check_out}'         => __('Check-out date (formatted)', 'modern-hotel-booking'),
                        '{check_in_time}'     => __('Check-in time (e.g. 14:00)', 'modern-hotel-booking'),
                        '{check_out_time}'    => __('Check-out time (e.g. 11:00)', 'modern-hotel-booking'),
                        '{nights}'            => __('Number of nights', 'modern-hotel-booking'),
                        '{guests}'            => __('Adult guest count', 'modern-hotel-booking'),
                        
                        '{status}'            => __('Translated booking status', 'modern-hotel-booking'),
                    ),
                    __('Financials, Deposits & Payment Summary', 'modern-hotel-booking') => array(
                        '{total_price}'            => __('Total booking price (formatted)', 'modern-hotel-booking'),
                        '{payment_amount}'         => __('Amount paid so far (formatted)', 'modern-hotel-booking'),
                        '{payment_transaction_id}' => __('Gateway transaction or charge ID', 'modern-hotel-booking'),
                        '{payment_date}'           => __('Payment timestamp', 'modern-hotel-booking'),
                        '{payment_details}'        => __('Payment confirmation status card', 'modern-hotel-booking'),
                        
                        '{reservation_breakdown}'  => __('Complete itemized HTML reservation breakdown table', 'modern-hotel-booking'),
                    ),
                    __('Extras & Custom Fields', 'modern-hotel-booking') => array(
                        '{booking_extras}'    => __('Selected booking extras & services (HTML list)', 'modern-hotel-booking'),
                        '{extras_text}'       => __('Selected booking extras (Plain text format)', 'modern-hotel-booking'),
                        '{custom_fields}'     => __('Formatted custom guest checkout fields', 'modern-hotel-booking'),
                    ),
                    __('Guest Self-Service, Requests & Identity Verification', 'modern-hotel-booking') => array(
                        '{cancellation_reason}' => __('Guest cancellation reason or explanatory note', 'modern-hotel-booking'),
                        '{requested_dates}'     => __('Requested new stay dates from guest portal', 'modern-hotel-booking'),
                        '{requested_guests}'    => __('Requested new guest composition from guest portal', 'modern-hotel-booking'),
                        '{modification_notes}'  => __('Special requests or notes for stay modification', 'modern-hotel-booking'),
                        '{old_email}'           => __('Previous guest contact email address (security alert)', 'modern-hotel-booking'),
                        '{new_email}'           => __('Updated guest contact email address (security alert)', 'modern-hotel-booking'),
                        '{admin_booking_url}'   => __('Direct URL to open reservation in WordPress PMS', 'modern-hotel-booking'),
                        '{verification_code}'   => __('6-digit security verification PIN code', 'modern-hotel-booking'),
                        '{verification_expiry}' => __('Verification code validity window (e.g. 20 minutes)', 'modern-hotel-booking'),
                        '{portal_card}'         => __('Styled Guest Self-Service Portal login card with link & token', 'modern-hotel-booking'),
                        '{security_token_row}'  => __('Table row displaying the security reference token', 'modern-hotel-booking'),
                    ),
                    
                    __('Property & Business Information', 'modern-hotel-booking') => array(
                        '{company_name}'         => __('Property / hotel registered name', 'modern-hotel-booking'),
                        '{hotel_name}'           => __('Hotel property name (synonym)', 'modern-hotel-booking'),
                        '{company_address}'      => __('Full hotel street address', 'modern-hotel-booking'),
                        '{company_phone}'        => __('Hotel telephone number', 'modern-hotel-booking'),
                        '{company_email}'        => __('Hotel inquiries email address', 'modern-hotel-booking'),
                        '{company_website}'      => __('Hotel website link', 'modern-hotel-booking'),
                        '{company_registration}' => __('Business registration number', 'modern-hotel-booking'),
                        '{whatsapp_number}'      => __('WhatsApp contact phone', 'modern-hotel-booking'),
                        '{whatsapp_link}'        => __('Click-to-chat WhatsApp direct URL', 'modern-hotel-booking'),
                        '{mhbo_logo_url}'        => __('Hotel logo image URL', 'modern-hotel-booking'),
                        '{site_name}'            => __('WordPress site name', 'modern-hotel-booking'),
                        '{site_title}'           => __('WordPress site title (synonym)', 'modern-hotel-booking'),
                        
                    ),
                    
                );
                ?>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
                    <?php foreach ($placeholder_categories as $category_title => $tags) : ?>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                            <h4 style="margin: 0 0 12px 0; font-size: 13px; font-weight: 700; color: #1e293b; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                                <?php echo esc_html($category_title); ?>
                            </h4>
                            <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                                <?php foreach ($tags as $tag => $tag_desc) : ?>
                                    <tr>
                                        <td style="padding: 5px 0; vertical-align: top; width: 45%;">
                                            <code onclick="mhboCopyTag(this)" title="<?php esc_attr_e('Click to copy', 'modern-hotel-booking'); ?>" style="cursor: pointer; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-weight: 600; font-size: 11px; display: inline-block;">
                                                <?php echo esc_html($tag); ?>
                                            </code>
                                        </td>
                                        <td style="padding: 5px 0; color: #64748b; vertical-align: top;">
                                            <?php echo esc_html($tag_desc); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <script>
        function mhboCopyTag(el) {
            const text = el.innerText.trim();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    const toast = document.getElementById('mhbo-copy-toast');
                    if (toast) {
                        toast.style.display = 'inline-block';
                        toast.innerText = '<?php echo esc_js(__('Copied', 'modern-hotel-booking')); ?> ' + text + ' !';
                        setTimeout(function() {
                            toast.style.display = 'none';
                        }, 2500);
                    }
                });
            }
        }
        </script>
        <?php
    }

private static function render_labels_tab(): void {
        $langs   = I18n::get_available_languages();
        $catalog = I18n::get_labels_catalog();

        // Add dynamic amenities to labels if configured.
        $amenities = get_option( 'mhbo_amenities_list' );
        if ( false === $amenities ) {
            $amenities = array(
                'wifi'      => I18n::__( 'amenity_free_wifi' ),
                'ac'        => I18n::__( 'amenity_air_conditioning' ),
                'tv'        => I18n::__( 'amenity_smart_tv' ),
                'breakfast' => I18n::__( 'amenity_breakfast_included' ),
                'pool'      => I18n::__( 'amenity_pool_view' ),
            );
        }
        $amenities = is_array( $amenities ) ? $amenities : array();
        if ( isset( $catalog['amenities']['labels'] ) && is_array( $catalog['amenities']['labels'] ) ) {
            foreach ( $amenities as $key => $label ) {
                if ( is_string( $label ) && '' !== $label ) {
                    $catalog['amenities']['labels'][ $key ] = $label;
                }
            }
        }

        // Tier Gating (Law 7 & User Specification):
        // In Free edition exclude labels only found in commercial tiers.
        // In Starter and Pro editions include all labels.
        $package_tier = class_exists( '\MHBO\Core\Tier' ) ? \MHBO\Core\Tier::get_package_tier() : 'free';
        if ( 'free' === $package_tier ) {
            foreach ( $catalog as $cat_id => $cat_data ) {
                if ( 'free' !== ( $cat_data['tier'] ?? 'free' ) ) {
                    unset( $catalog[ $cat_id ] );
                }
            }
        }

        $total_labels        = 0;
        $customized_count    = 0;
        $free_label_count    = 0;
        $starter_label_count = 0;
        $pro_label_count     = 0;
        $cat_custom_counts   = array();

        foreach ( $catalog as $cat_id => $cat_data ) {
            $cat_tier   = $cat_data['tier'] ?? 'free';
            $num_in_cat = count( $cat_data['labels'] );
            $total_labels += $num_in_cat;
            $cat_custom_counts[ $cat_id ] = 0;

            if ( 'free' === $cat_tier ) {
                $free_label_count += $num_in_cat;
            } elseif ( 'starter' === $cat_tier ) {
                $starter_label_count += $num_in_cat;
            } elseif ( 'pro' === $cat_tier ) {
                $pro_label_count += $num_in_cat;
            }

            foreach ( $cat_data['labels'] as $k => $desc ) {
                $existing_val = get_option( "mhbo_label_{$k}" );
                if ( false !== $existing_val && '' !== trim( (string) $existing_val ) ) {
                    ++$customized_count;
                    ++$cat_custom_counts[ $cat_id ];
                }
            }
        }

        $total_categories   = count( $catalog );
        $active_langs_count = count( $langs );
        ?>
        <div class="mhbo-settings-card-container">

            <!-- Search, Filter & Metrics Ribbon -->
            <div class="mhbo-settings-section-card" style="padding: 18px 24px; margin-bottom: 0;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                    <div>
                        <h3 class="mhbo-settings-section-title" style="margin-bottom: 2px !important;"><?php echo esc_html( I18n::get_label( 'tab_labels' ) ); ?></h3>
                        <p class="mhbo-settings-section-desc">
                            <?php
                            /* translators: 1: placeholder example %s, 2: placeholder example %d */
                            printf( esc_html( I18n::get_label( 'settings_labels_desc' ) ), '<code>%s</code>', '<code>%d</code>' );
                            ?>
                        </p>
                        <div style="display: flex; align-items: center; gap: 8px; margin-top: 8px; flex-wrap: wrap;">
                            <span class="mhbo-badge" style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px;">
                                <?php
                                /* translators: %d: Total count of translatable labels. */
                                printf( esc_html__( '%d Total Labels', 'modern-hotel-booking' ), (int) $total_labels );
                                ?>
                            </span>
                            <span class="mhbo-badge" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px;">
                                <?php
                                /* translators: %d: Total count of label categories. */
                                printf( esc_html__( '%d Categories', 'modern-hotel-booking' ), (int) $total_categories );
                                ?>
                            </span>
                            <span class="mhbo-badge" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px;">
                                <?php
                                /* translators: %d: Count of active languages. */
                                printf( esc_html__( '%d Active Languages', 'modern-hotel-booking' ), (int) $active_langs_count );
                                ?>
                            </span>
                            <?php if ( $customized_count > 0 ) : ?>
                                <span class="mhbo-badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px;">
                                    <?php
                                    /* translators: %d: Count of customized labels. */
                                    printf( esc_html__( '%d Customized', 'modern-hotel-booking' ), (int) $customized_count );
                                    ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( 'free' !== $package_tier ) : ?>
                                <span class="mhbo-badge" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 999px;">
                                    <?php
                                    /* translators: 1: Count of Core labels, 2: Count of Starter labels, 3: Count of Pro labels. */
                                    echo esc_html( sprintf( __( 'Core: %1$d | Starter: %2$d | Pro: %3$d', 'modern-hotel-booking' ), $free_label_count, $starter_label_count, $pro_label_count ) );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; min-width: 340px; flex: 1; max-width: 720px; justify-content: flex-end; flex-wrap: wrap;">
                        <button type="button" id="mhbo_toggle_all_cats" class="button button-secondary" onclick="mhboToggleAllCategories();" style="height: 36px; line-height: 34px; padding: 0 10px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;" aria-expanded="true">
                            <span class="dashicons dashicons-arrow-up-alt2" id="mhbo_toggle_icon" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px;"></span>
                            <span id="mhbo_toggle_label"><?php esc_html_e( 'Collapse All', 'modern-hotel-booking' ); ?></span>
                        </button>
                        <?php if ( 'free' !== $package_tier ) : ?>
                        <select id="mhbo_label_tier_select" class="mhbo-form-input" style="height: 36px; font-size: 13px; max-width: 135px;" onchange="mhboFilterLabels();" aria-label="<?php esc_attr_e( 'Filter by edition tier', 'modern-hotel-booking' ); ?>">
                            <option value="all"><?php esc_html_e( 'All Tiers', 'modern-hotel-booking' ); ?></option>
                            <option value="free"><?php esc_html_e( 'Core / Free', 'modern-hotel-booking' ); ?> (<?php echo (int) $free_label_count; ?>)</option>
                            <option value="starter"><?php esc_html_e( 'Starter Tier', 'modern-hotel-booking' ); ?> (<?php echo (int) $starter_label_count; ?>)</option>
                            <option value="pro"><?php esc_html_e( 'Pro Tier', 'modern-hotel-booking' ); ?> (<?php echo (int) $pro_label_count; ?>)</option>
                        </select>
                        <?php endif; ?>
                        <select id="mhbo_label_category_select" class="mhbo-form-input" style="height: 36px; font-size: 13px; max-width: 220px;" onchange="mhboFilterLabels();" aria-label="<?php esc_attr_e( 'Filter by label category', 'modern-hotel-booking' ); ?>">
                            <option value="all"><?php esc_html_e( 'All Categories', 'modern-hotel-booking' ); ?> (<?php echo (int) $total_categories; ?>)</option>
                            <?php foreach ( $catalog as $cat_id => $cat_data ) : ?>
                                <option value="<?php echo esc_attr( $cat_id ); ?>" data-tier="<?php echo esc_attr( $cat_data['tier'] ); ?>">
                                    <?php echo esc_html( $cat_data['title'] ); ?> (<?php echo count( $cat_data['labels'] ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="display: flex; align-items: center; gap: 6px; flex: 1; min-width: 170px;">
                            <span class="dashicons dashicons-search" style="color: #64748b;"></span>
                            <input type="search" id="mhbo_label_search_input" class="mhbo-form-input" placeholder="<?php esc_attr_e( 'Filter labels by key or text...', 'modern-hotel-booking' ); ?>" style="height: 36px; font-size: 13px;" oninput="mhboFilterLabels();" aria-label="<?php esc_attr_e( 'Search labels', 'modern-hotel-booking' ); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <?php
            foreach ( $catalog as $cat_id => $cat_data ) :
                $cat_tier   = $cat_data['tier'] ?? 'free';
                $card_icon  = ! empty( $cat_data['icon'] ) ? $cat_data['icon'] : 'dashicons-translation';
                $cat_custom = $cat_custom_counts[ $cat_id ] ?? 0;
                ?>
                <div class="mhbo-settings-section-card mhbo-label-group-card" data-group="<?php echo esc_attr( $cat_id ); ?>" data-tier="<?php echo esc_attr( $cat_tier ); ?>">
                    <div class="mhbo-settings-section-header" onclick="mhboToggleCategory(this.closest('.mhbo-label-group-card'));" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault(); mhboToggleCategory(this.closest('.mhbo-label-group-card'));}" style="cursor: pointer; user-select: none;" role="button" tabindex="0" aria-expanded="true">
                        <div class="mhbo-settings-section-title-wrap" style="flex: 1;">
                            <div class="mhbo-settings-section-icon"><span class="dashicons <?php echo esc_attr( $card_icon ); ?>"></span></div>
                            <div style="flex: 1;">
                                <h3 class="mhbo-settings-section-title" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <span><?php echo esc_html( $cat_data['title'] ); ?></span>
                                    <?php if ( 'pro' === $cat_tier ) : ?>
                                        <span class="mhbo-tier-badge mhbo-badge-pro" style="display: inline-flex; align-items: center; gap: 3px; font-size: 11px; padding: 2px 8px; border-radius: 999px; font-weight: 700; background: #fef3c7; color: #92400e; border: 1px solid #fde68a;">
                                            <span class="dashicons dashicons-awards" style="font-size: 12px; width: 12px; height: 12px; line-height: 12px;"></span> PRO
                                        </span>
                                    <?php elseif ( 'starter' === $cat_tier ) : ?>
                                        <span class="mhbo-tier-badge mhbo-badge-starter" style="display: inline-flex; align-items: center; gap: 3px; font-size: 11px; padding: 2px 8px; border-radius: 999px; font-weight: 700; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;">
                                            <span class="dashicons dashicons-star-filled" style="font-size: 12px; width: 12px; height: 12px; line-height: 12px;"></span> STARTER
                                        </span>
                                    <?php else : ?>
                                        <span class="mhbo-tier-badge mhbo-badge-core" style="display: inline-flex; align-items: center; gap: 3px; font-size: 11px; padding: 2px 8px; border-radius: 999px; font-weight: 700; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">
                                            <span class="dashicons dashicons-admin-generic" style="font-size: 12px; width: 12px; height: 12px; line-height: 12px;"></span> CORE
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( $cat_custom > 0 ) : ?>
                                        <span class="mhbo-badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 999px;">
                                            <?php
                                            /* translators: %d: count of customized labels in category */
                                            printf( esc_html__( '%d customized', 'modern-hotel-booking' ), (int) $cat_custom );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </h3>
                                <p class="mhbo-settings-section-desc">
                                <?php
                                    /* translators: %d: Number of labels in this category. */
                                    printf( esc_html__( '%d labels in this category', 'modern-hotel-booking' ), count( $cat_data['labels'] ) );
                                ?>
                                </p>
                            </div>
                            <span class="dashicons dashicons-arrow-down-alt2 mhbo-cat-chevron" style="transition: transform 0.2s ease; margin-left: 12px; color: #64748b; font-size: 18px; width: 18px; height: 18px;"></span>
                        </div>
                    </div>

                    <div class="mhbo-settings-grid mhbo-cat-grid" style="gap: 16px;">
                        <?php
                        foreach ( $cat_data['labels'] as $key => $label_desc ) :
                            $val             = get_option( "mhbo_label_{$key}" );
                            $has_custom      = ( false !== $val && '' !== trim( (string) $val ) );
                            $search_haystack = strtolower( $key . ' ' . $label_desc . ' ' . $cat_tier );
                            $default_label   = I18n::get_label( $key );
                            $placeholder_txt = ( '' !== $default_label && $default_label !== $key ) ? $default_label : $label_desc;
                            ?>
                            <div class="mhbo-form-group mhbo-span-full mhbo-label-row" data-search="<?php echo esc_attr( $search_haystack ); ?>" data-tier="<?php echo esc_attr( $cat_tier ); ?>" style="padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                                    <strong style="font-size: 13.5px; color: #0f172a;"><?php echo esc_html( $label_desc ); ?></strong>
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <?php if ( 'pro' === $cat_tier ) : ?>
                                            <span class="mhbo-tier-badge mhbo-badge-pro" style="font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; display: inline-flex; align-items: center; gap: 2px;">
                                                <span class="dashicons dashicons-awards" style="font-size: 11px; width: 11px; height: 11px; line-height: 11px;"></span> PRO
                                            </span>
                                        <?php elseif ( 'starter' === $cat_tier ) : ?>
                                            <span class="mhbo-tier-badge mhbo-badge-starter" style="font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; display: inline-flex; align-items: center; gap: 2px;">
                                                <span class="dashicons dashicons-star-filled" style="font-size: 11px; width: 11px; height: 11px; line-height: 11px;"></span> STARTER
                                            </span>
                                        <?php else : ?>
                                            <span class="mhbo-tier-badge mhbo-badge-core" style="font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; display: inline-flex; align-items: center; gap: 2px;">
                                                <span class="dashicons dashicons-admin-generic" style="font-size: 11px; width: 11px; height: 11px; line-height: 11px;"></span> CORE
                                            </span>
                                        <?php endif; ?>

                                        <?php if ( $has_custom ) : ?>
                                            <span class="mhbo-badge mhbo-status-badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 10.5px; font-weight: 700; padding: 2px 6px; border-radius: 4px;">
                                                <?php esc_html_e( 'Customized', 'modern-hotel-booking' ); ?>
                                            </span>
                                            <button type="button" class="button button-small" onclick="var p = this.closest('.mhbo-label-row'); p.querySelectorAll('input.mhbo-form-input').forEach(function(i){i.value=''; i.dataset.modified='1';}); this.style.display='none'; var n = p.querySelector('.mhbo-revert-badge'); if(n) n.style.display=''; var b = p.querySelector('.mhbo-status-badge'); if(b) b.style.display='none'; mhboShowFloatingSave();" title="<?php esc_attr_e( 'Clear overrides and revert to default translation', 'modern-hotel-booking' ); ?>" style="font-size: 11px; height: 24px; min-height: 24px; line-height: 22px; padding: 0 8px;">
                                                <?php esc_html_e( 'Revert', 'modern-hotel-booking' ); ?>
                                            </button>
                                        <?php else : ?>
                                            <span class="mhbo-badge" style="background: #f1f5f9; color: #64748b; font-size: 10.5px; font-weight: 600; padding: 2px 6px; border-radius: 4px;">
                                                <?php esc_html_e( 'Default', 'modern-hotel-booking' ); ?>
                                            </span>
                                        <?php endif; ?>
                                        <span class="mhbo-badge mhbo-revert-badge" style="display:none; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px;">
                                            ✓ <?php esc_html_e( 'Reverted (Save to apply)', 'modern-hotel-booking' ); ?>
                                        </span>
                                        <code style="font-size: 11px; color: #64748b; background: #e2e8f0; padding: 2px 6px; border-radius: 4px;"><?php echo esc_html( $key ); ?></code>
                                    </div>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 6px;">
                                    <?php
                                    foreach ( $langs as $lang ) :
                                        $lang_val = (string) ( I18n::decode( $val, $lang ) ?? '' );
                                        ?>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span class="mhbo-badge" style="width: 34px; text-align: center; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-size: 11px; font-weight: 800; padding: 2px 4px; border-radius: 4px; flex-shrink: 0;">
                                                <?php echo esc_html( strtoupper( $lang ) ); ?>
                                            </span>
                                            <input type="text" name="mhbo_label_templates[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $lang ); ?>]" value="<?php echo esc_attr( $lang_val ); ?>" placeholder="<?php echo esc_attr( $placeholder_txt ); ?>" class="mhbo-form-input" style="flex: 1; height: 34px; font-size: 13px;" oninput="this.dataset.modified='1'; mhboShowFloatingSave();">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Floating Unsaved Changes Shortcut Bar -->
            <div id="mhbo-floating-save-bar" style="display:none; position: fixed; bottom: 24px; right: 28px; z-index: 99999; background: #0f172a; color: #ffffff; padding: 12px 20px; border-radius: 12px; box-shadow: 0 12px 30px rgba(0,0,0,0.35); align-items: center; gap: 14px; border: 1px solid #334155;">
                <span style="font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                    <span class="dashicons dashicons-saved" style="color: #38bdf8; font-size: 18px; width: 18px; height: 18px; line-height: 18px;"></span>
                    <?php esc_html_e( 'You have unsaved label modifications.', 'modern-hotel-booking' ); ?>
                </span>
                <button type="button" class="button button-primary" onclick="var f = document.querySelector('.mhbo-settings-card-container').closest('form'); if(f) f.requestSubmit();" style="background: #2563eb; border-color: #1d4ed8; height: 32px; line-height: 30px; font-weight: 700; padding: 0 16px; border-radius: 6px;">
                    <?php esc_html_e( 'Save Changes', 'modern-hotel-booking' ); ?>
                </button>
            </div>

        </div>
        <script>
        function mhboToggleCategory(card) {
            if (!card) return;
            var grid = card.querySelector('.mhbo-cat-grid');
            var chevron = card.querySelector('.mhbo-cat-chevron');
            var header = card.querySelector('.mhbo-settings-section-header');
            if (!grid) return;
            var isHidden = grid.style.display === 'none';
            grid.style.display = isHidden ? '' : 'none';
            if (chevron) {
                chevron.style.transform = isHidden ? '' : 'rotate(-90deg)';
            }
            if (header) {
                header.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
            }
        }

        function mhboToggleAllCategories() {
            var btn = document.getElementById('mhbo_toggle_all_cats');
            var icon = document.getElementById('mhbo_toggle_icon');
            var label = document.getElementById('mhbo_toggle_label');
            var isCurrentlyExpanded = btn && btn.getAttribute('aria-expanded') === 'true';
            var nextExpanded = !isCurrentlyExpanded;

            document.querySelectorAll('.mhbo-label-group-card').forEach(function(card) {
                var grid = card.querySelector('.mhbo-cat-grid');
                var chevron = card.querySelector('.mhbo-cat-chevron');
                var header = card.querySelector('.mhbo-settings-section-header');
                if (grid) {
                    grid.style.display = nextExpanded ? '' : 'none';
                }
                if (chevron) {
                    chevron.style.transform = nextExpanded ? '' : 'rotate(-90deg)';
                }
                if (header) {
                    header.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
                }
            });

            if (btn) btn.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
            if (icon) {
                icon.className = nextExpanded ? 'dashicons dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2';
            }
            if (label) {
                label.textContent = nextExpanded ? '<?php echo esc_js( __( 'Collapse All', 'modern-hotel-booking' ) ); ?>' : '<?php echo esc_js( __( 'Expand All', 'modern-hotel-booking' ) ); ?>';
            }
        }

        function mhboShowFloatingSave() {
            var bar = document.getElementById('mhbo-floating-save-bar');
            if (bar) bar.style.display = 'flex';
        }

        function mhboFilterLabels() {
            var searchInput = document.getElementById('mhbo_label_search_input');
            var catSelect   = document.getElementById('mhbo_label_category_select');
            var tierSelect  = document.getElementById('mhbo_label_tier_select');
            var searchVal   = searchInput ? (searchInput.value || '').toLowerCase().trim() : '';
            var catVal      = catSelect ? catSelect.value : 'all';
            var tierVal     = tierSelect ? tierSelect.value : 'all';

            document.querySelectorAll('.mhbo-label-group-card').forEach(function(card) {
                var cardGroup = card.getAttribute('data-group') || '';
                var cardTier  = card.getAttribute('data-tier') || 'free';
                var grid      = card.querySelector('.mhbo-cat-grid');
                var chevron   = card.querySelector('.mhbo-cat-chevron');
                var header    = card.querySelector('.mhbo-settings-section-header');

                if (catVal && catVal !== 'all' && cardGroup !== catVal) {
                    card.style.display = 'none';
                    return;
                }
                if (tierVal && tierVal !== 'all' && cardTier !== tierVal) {
                    card.style.display = 'none';
                    return;
                }

                var visibleCount = 0;
                card.querySelectorAll('.mhbo-label-row').forEach(function(row) {
                    var searchData = (row.getAttribute('data-search') || '').toLowerCase();
                    var rowTier    = row.getAttribute('data-tier') || 'free';

                    var matchesTier   = (tierVal === 'all' || rowTier === tierVal);
                    var matchesSearch = (!searchVal || searchData.indexOf(searchVal) !== -1);
                    var isVisible     = matchesTier && matchesSearch;

                    row.style.display = isVisible ? '' : 'none';
                    if (isVisible) visibleCount++;
                });

                card.style.display = (visibleCount > 0) ? '' : 'none';

                // If searching or filtering specific category, automatically expand matching cards
                if (visibleCount > 0 && (searchVal || catVal !== 'all')) {
                    if (grid) grid.style.display = '';
                    if (chevron) chevron.style.transform = '';
                    if (header) header.setAttribute('aria-expanded', 'true');
                }
            });
        }

        // max_input_vars protection: Prune clean empty inputs at submit time so PHP doesn't drop vars
        (function() {
            var initSubmitPruning = function() {
                var form = document.querySelector('.mhbo-settings-card-container') ? document.querySelector('.mhbo-settings-card-container').closest('form') : null;
                if (!form) return;
                form.addEventListener('submit', function() {
                    form.querySelectorAll('.mhbo-label-row input.mhbo-form-input').forEach(function(input) {
                        if (!input.value && !input.dataset.modified) {
                            input.disabled = true;
                        }
                    });
                });
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initSubmitPruning);
            } else {
                initSubmitPruning();
            }
        })();
        </script>
        <?php
    }

/**
     * Handle the custom multilingual settings saving (Emails & Labels).
     */
    /**
     * Process settings save operations.
     */
    public function process_settings_save(): void {

// 1. Determine which nonce to verify based on the action/tab
        $nonce_action = 'mhbo_settings_nonce';
        $nonce_field  = 'mhbo_nonce';

// 2. Security Gatekeeper - Only trigger if this is a plugin-specific save request.
        if (isset($_POST['mhbo_save_tab']) || isset($_POST['mhbo_save_pro_settings']) || isset($_POST['mhbo_pro_themes_save']) || isset($_POST['submit_save_themes'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified immediately below.

            // Find the actual nonce field sent
            $sent_nonce = isset($_POST[ $nonce_field ]) ? sanitize_text_field(wp_unslash($_POST[ $nonce_field ])) : '';
            
            if ( ! $sent_nonce || ! wp_verify_nonce($sent_nonce, $nonce_action)) {
                 wp_die(esc_html(I18n::get_label('label_security_check_failed')));
            }

            if ( ! Capabilities::current_user_can(Capabilities::MANAGE_SETTINGS)) {
                wp_die(esc_html(I18n::get_label('msg_insufficient_permissions')));
            }

            // 3. Routing
            $data = $_POST;
            $tab  = isset($data['mhbo_save_tab']) ? sanitize_key(wp_unslash($data['mhbo_save_tab'])) : '';

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
     * @param array<string, mixed> $data
     */
    public function save_multilingual_settings( array $data ): void {
        // Permission check and nonce verification are centralized in process_settings_save()
        if ( ! isset($data['mhbo_save_tab']) || ! in_array(sanitize_key(wp_unslash($data['mhbo_save_tab'])), array('emails', 'labels', 'gdpr'), true)) {
            return;
        }

        // Save Emails
        $allowed_email_statuses = array(
            'pending',
            'confirmed',
            'cancelled',
            'payment',
            'stay_dates_updated',
            'modification_request',
            'cancellation_request',
            'email_updated',
            'identity_verification',
            'admin_new_booking',
            'admin_payment',
            'admin_modification_request',
            'admin_cancellation_request',
            'admin_cancellation',
            
        );
        if (isset($data['mhbo_email_templates']) && is_array($data['mhbo_email_templates'])) {
            $is_pro_save = class_exists('\MHBO\Core\Tier') ? \MHBO\Core\Tier::is_effective_at_least(\MHBO\Core\Tier::TIER_PRO) : false;
            $pro_only_statuses = array('balance_payment', 'balance_payment_confirmed', 'vip_coupon', 'review_request');

            // 2026 BP: Rule 11 - Decouple extraction from sanitization.
            $raw_email_templates = wp_unslash($data['mhbo_email_templates']);
            $email_templates_post = map_deep($raw_email_templates, 'wp_kses_post');
            foreach ($allowed_email_statuses as $status) {
                if ( in_array($status, $pro_only_statuses, true) && ! $is_pro_save ) {
                    continue;
                }
                if ( ! isset($email_templates_post[ $status ]) || ! is_array($email_templates_post[ $status ])) {
                    continue;
                }
                $tpl_entry = $email_templates_post[ $status ];
                if (isset($tpl_entry['subject'])) {
                    // Subjects should be plain text — tighten with sanitize_text_field.
                    $subject_data = is_array($tpl_entry['subject'])
                        ? array_map('sanitize_text_field', $tpl_entry['subject'])
                        : sanitize_text_field($tpl_entry['subject']);
                    update_option("mhbo_email_{$status}_subject", I18n::encode($subject_data));
                }
                if (isset($tpl_entry['message'])) {
                    // Messages are already safe from map_deep(... 'wp_kses_post').
                    $message_data = $tpl_entry['message'];
                    update_option("mhbo_email_{$status}_message", I18n::encode($message_data));
                }
            }
        }

        // Save Labels
        if (isset($data['mhbo_label_templates']) && is_array($data['mhbo_label_templates'])) {
            $allowed_label_keys = array_keys(I18n::get_all_default_labels());
            $amenities = get_option('mhbo_amenities_list', array());
            if (is_array($amenities)) {
                $allowed_label_keys = array_merge($allowed_label_keys, array_keys($amenities));
            }
            $allowed_label_keys = array_unique($allowed_label_keys);

            // 2026 BP: Rule 11 - Decouple extraction from sanitization.
            $raw_label_templates = wp_unslash($data['mhbo_label_templates']);
            $label_templates_post = map_deep($raw_label_templates, 'sanitize_text_field');
            foreach ($allowed_label_keys as $key) {
                if ( ! isset($label_templates_post[ $key ])) {
                    continue;
                }
                $val = $label_templates_post[ $key ];
                $label_data = is_array($val)
                    ? array_map('sanitize_text_field', $val)
                    : sanitize_text_field( (string) $val);
                $encoded = I18n::encode($label_data);
                if ( '' === $encoded ) {
                    delete_option("mhbo_label_{$key}");
                } else {
                    update_option("mhbo_label_{$key}", $encoded);
                }
            }
        }

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_multilingual_saved'), 'success');
    }

/**
     * @param array<string, mixed> $data
     */
    public function save_general_settings( array $data ): void {
        // Text & Select Fields
        if (isset($data['mhbo_checkin_time'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_checkin = wp_unslash($data['mhbo_checkin_time']);
            update_option('mhbo_checkin_time', sanitize_text_field($raw_checkin));
        }
        if (isset($data['mhbo_checkout_time'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_checkout = wp_unslash($data['mhbo_checkout_time']);
            update_option('mhbo_checkout_time', sanitize_text_field($raw_checkout));
        }
        if (isset($data['mhbo_booking_page'])) {
            update_option('mhbo_booking_page', absint(wp_unslash($data['mhbo_booking_page'])));
        }
        if (isset($data['mhbo_guest_portal_page'])) {
            update_option('mhbo_guest_portal_page', absint(wp_unslash($data['mhbo_guest_portal_page'])));
        }

if (isset($data['mhbo_notification_email'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_email = wp_unslash($data['mhbo_notification_email']);
            update_option('mhbo_notification_email', $this->sanitize_notification_email($raw_email));
        }
        if (isset($data['mhbo_additional_notification_email'])) {
            $raw_extra = wp_unslash($data['mhbo_additional_notification_email']);
            update_option('mhbo_additional_notification_email', $this->sanitize_additional_notification_emails($raw_extra));
        }
        if (isset($data['mhbo_booking_page_url'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_url = wp_unslash($data['mhbo_booking_page_url']);
            update_option('mhbo_booking_page_url', esc_url_raw($raw_url));
        }

if (isset($data['mhbo_hotel_timezone'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_tz = wp_unslash($data['mhbo_hotel_timezone']);
            update_option('mhbo_hotel_timezone', sanitize_text_field($raw_tz));
        }

if (isset($data['mhbo_trusted_proxies'])) {
            $raw_proxies = wp_unslash($data['mhbo_trusted_proxies']);
            update_option('mhbo_trusted_proxies', sanitize_textarea_field($raw_proxies));
        }

        if (isset($data['mhbo_hotel_wifi_ssid'])) {
            $raw_ssid = wp_unslash($data['mhbo_hotel_wifi_ssid']);
            update_option('mhbo_hotel_wifi_ssid', sanitize_text_field($raw_ssid));
        }
        if (isset($data['mhbo_hotel_wifi_password'])) {
            $raw_wifi_pass = wp_unslash($data['mhbo_hotel_wifi_password']);
            update_option('mhbo_hotel_wifi_password', sanitize_text_field($raw_wifi_pass));
        }
        if (isset($data['mhbo_hotel_guest_instructions'])) {
            $raw_instructions = wp_unslash($data['mhbo_hotel_guest_instructions']);
            update_option('mhbo_hotel_guest_instructions', sanitize_textarea_field($raw_instructions));
        }

// Boolean Fields
        $bool_fields = array(
            'mhbo_modal_enabled',
            'mhbo_calendar_show_decimals',
            'mhbo_powered_by_link',
            'mhbo_save_data_on_uninstall',
            'mhbo_guest_crm_enabled',
        );

foreach ($bool_fields as $field) {
            $raw_val = isset($data[ $field ]) ? (string) wp_unslash($data[ $field ]) : '0';
            $val = ( '1' === $raw_val ) ? 1 : 0;
            update_option($field, $val);
        }

// Invalidate settings cache so API payload version hash changes immediately
        Cache::bump(Cache::TABLE_SETTINGS);
        if (class_exists(Cache::class)) {
            Cache::bump(Cache::TABLE_GUESTS);
        }

        if (isset($data['mhbo_guest_crm_enabled']) && '1' === (string) $data['mhbo_guest_crm_enabled']) {
            if (class_exists('MHBO\Core\GuestMigration') && \MHBO\Core\GuestMigration::get_unmigrated_count() > 0) {
                if (class_exists('MHBO\Core\GuestAsyncTasks')) {
                    \MHBO\Core\GuestAsyncTasks::queue_migrate_batch(0, 250);
                }
            }
        }

        // 2026 BP: Rule 11 - Individual extraction then sanitization.
        if (isset($data['mhbo_custom_fields']) && is_array($data['mhbo_custom_fields'])) {
            $custom_fields = array();
            $raw_custom_fields = wp_unslash($data['mhbo_custom_fields']);
            $fields_data = map_deep($raw_custom_fields, 'sanitize_text_field');
            foreach ($fields_data as $field) {
                if (isset($field['id']) && $field['id'] !== '' && isset($field['label'], $field['type'])) {
                    $custom_fields[] = array(
                        'id' => sanitize_key($field['id']),
                        'label' => is_array($field['label']) ? array_map('sanitize_text_field', $field['label']) : sanitize_text_field($field['label']),
                        'type' => sanitize_text_field($field['type']),
                        'required' => isset($field['required']) ? 1 : 0,
                    );
                }
            }
            update_option('mhbo_custom_fields', $custom_fields);
        } else {
            update_option('mhbo_custom_fields', array());
        }

        // Currency with Validation
        if (isset($data['mhbo_currency_code'])) {
            $code = sanitize_text_field(wp_unslash($data['mhbo_currency_code']));
            if (I18n::is_valid_currency($code)) {
                update_option('mhbo_currency_code', strtoupper($code));
            } else {
                add_settings_error('mhbo_settings', 'invalid_currency', I18n::get_label('msg_invalid_currency'));
            }
        }

        if (isset($data['mhbo_currency_symbol'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_symbol = wp_unslash($data['mhbo_currency_symbol']);
            update_option('mhbo_currency_symbol', sanitize_text_field($raw_symbol));
        }
        if (isset($data['mhbo_currency_position'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_position = wp_unslash($data['mhbo_currency_position']);
            update_option('mhbo_currency_position', sanitize_text_field($raw_position));
        }
        if (isset($data['mhbo_price_decimals'])) {
            $raw_decimals = wp_unslash($data['mhbo_price_decimals']);
            update_option('mhbo_price_decimals', $this->sanitize_price_decimals($raw_decimals));
        }

        // Global Stay Limits — 0 means disabled; cap at 365 nights.
        if (isset($data['mhbo_global_min_stay'])) {
            $global_min = min(absint(wp_unslash($data['mhbo_global_min_stay'])), 365);
            update_option('mhbo_global_min_stay', $global_min);
        }
        if (isset($data['mhbo_global_max_stay'])) {
            $global_max = min(absint(wp_unslash($data['mhbo_global_max_stay'])), 365);
            update_option('mhbo_global_max_stay', $global_max);
        }

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_general_saved'), 'success');
    }

public static function render_pro_page(): void {
        
    }

private static function render_payments_tab(): void {
        
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

    /**
     * @param array<string, mixed> $data
     */
    public function save_payments_settings( array $data ): void {
        
    }

private static function render_amenities_tab(): void {
        $amenities = get_option('mhbo_amenities_list');
        if (false === $amenities) {
            $amenities = array(
                'wifi'      => I18n::get_label('label_free_wifi'),
                'ac'        => I18n::get_label('label_air_conditioning'),
                'tv'        => I18n::get_label('label_smart_tv'),
                'breakfast' => I18n::get_label('label_breakfast_included'),
                'pool'      => I18n::get_label('label_pool_view'),
            );
        }
        $amenities = is_array($amenities) ? $amenities : array();
        ?>
        <div class="mhbo-settings-card-container">

            <!-- Card 1: Add New Amenity -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-plus-alt2"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php I18n::esc_html_e('label_add_new_amenity'); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php I18n::esc_html_e('msg_room_amenities_desc'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid">
                    <div class="mhbo-form-group mhbo-span-full">
                        <div style="display: flex; gap: 12px; align-items: center; max-width: 600px; flex-wrap: wrap;">
                            <input type="text" name="mhbo_new_amenity" placeholder="<?php esc_attr_e('e.g. Hot Tub, Ocean View, Mini Bar', 'modern-hotel-booking'); ?>" class="mhbo-form-input" style="flex: 1; min-width: 240px; height: 38px;">
                            <button type="submit" name="mhbo_add_amenity" value="1" class="button button-primary" style="height: 38px; padding: 0 20px; font-weight: 600;">
                                <?php I18n::esc_html_e('btn_add'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: Configured Amenities Grid -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-tag"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php I18n::esc_html_e('label_room_amenities_title'); ?></h3>
                            <p class="mhbo-settings-section-desc">
                            <?php 
                                /* translators: %d: Number of active room amenities. */
                                printf( esc_html__('%d active amenities available for rooms & filters', 'modern-hotel-booking'), count($amenities) ); 
                            ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid">
                    <div class="mhbo-form-group mhbo-span-full">
                        <?php if (count($amenities) === 0) : ?>
                            <div style="padding: 24px; text-align: center; color: #64748b; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                                <?php I18n::esc_html_e('label_no_amenities_found'); ?>
                            </div>
                        <?php else : ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
                                <?php foreach ($amenities as $key => $label) : ?>
                                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                                        <div>
                                            <strong style="font-size: 13.5px; color: #0f172a; display: block;"><?php echo esc_html($label); ?></strong>
                                            <code style="font-size: 11px; color: #64748b; background: #e2e8f0; padding: 1px 6px; border-radius: 4px; margin-top: 3px; display: inline-block;"><?php echo esc_html($key); ?></code>
                                        </div>
                                        <button type="submit" name="mhbo_remove_amenity" value="<?php echo esc_attr($key); ?>"
                                            class="button button-link-delete"
                                            style="color: #ef4444; text-decoration: none; font-size: 13px; font-weight: 600; padding: 4px 8px;"
                                            onclick="return confirm('<?php echo esc_js(I18n::get_label('label_are_you_sure')); ?>');">
                                            <span class="dashicons dashicons-trash" style="font-size: 16px; width: 16px; height: 16px; margin-top: 2px;"></span>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

/**
     * @param array<string, mixed> $data
     */
    public function save_business_settings( array $data ): void {
        \MHBO\Business\Info::get_instance()->handle_save($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save_amenities_settings( array $data ): void {
        $amenities = get_option('mhbo_amenities_list');
        if (false === $amenities) { // If option doesn't exist, initialize with defaults
            $amenities = array(
                'wifi'      => I18n::get_label('label_free_wifi'),
                'ac'        => I18n::get_label('label_air_conditioning'),
                'tv'        => I18n::get_label('label_smart_tv'),
                'breakfast' => I18n::get_label('label_breakfast_included'),
                'pool'      => I18n::get_label('label_pool_view'),
            );
        }
        $amenities = is_array($amenities) ? $amenities : array(); // Ensure it's always an array

        // Add Amenity
        if (isset($data['mhbo_add_amenity']) && isset($data['mhbo_new_amenity']) && is_string($data['mhbo_new_amenity']) && '' !== trim($data['mhbo_new_amenity'])) {
            $label = sanitize_text_field(wp_unslash($data['mhbo_new_amenity']));
            $key = sanitize_title($label);
            if ($key && ! isset($amenities[ $key ])) {
                $amenities[ $key ] = $label;
                update_option('mhbo_amenities_list', $amenities);
                add_settings_error('mhbo_amenities', 'added', I18n::get_label('msg_amenity_added'), 'success');
            }
        }

        // Remove Amenity
        if (isset($data['mhbo_remove_amenity'])) {
            $key = sanitize_key(wp_unslash($data['mhbo_remove_amenity']));
            if (isset($amenities[ $key ])) {
                unset($amenities[ $key ]);
                update_option('mhbo_amenities_list', $amenities);
                add_settings_error('mhbo_amenities', 'removed', I18n::get_label('msg_amenity_removed'), 'success');
            }
        }
    }

    private static function render_tax_tab(): void {
        
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save_license_settings( array $data ): void {
        
    }

    /**
     * Save Tax Settings
     */
    /**
     * @param array<string, mixed> $data
     */
    public function save_tax_settings( array $data ): void {
        
    }

}
