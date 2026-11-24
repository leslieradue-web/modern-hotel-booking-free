<?php declare(strict_types=1);

namespace MHBO\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\Cache;
use MHBO\Core\HotelTime;
use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Security;
use MHBO\Core\Tier;

/**
 * Settings Save Handler
 *
 * Persists validated option payloads for General, Multilingual, Business,
 * Amenities, Payments, Tax, Licensing, Invoices, Themes, API, Performance,
 * GDPR, Deposits, Reviews, and Coupons tabs.
 */
class SettingsSaveHandler {

	/**
	 * Parent Settings facade for shared field sanitization helpers.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Parent Settings facade instance.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings ?? new Settings();
	}

/**
     * @param array<string, mixed> $data
     */
    public function save_multilingual_settings( array $data ): void {
        // Permission check and nonce verification are centralized in process_settings_save()
        if ( ! isset($data['mhbo_save_tab']) || ! in_array(sanitize_key(wp_unslash($data['mhbo_save_tab'])), array('emails', 'labels', 'gdpr', 'i18n'), true)) {
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
            $is_pro_save = class_exists( Tier::class ) && Tier::is_effective_at_least( Tier::TIER_PRO );
            
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
            if ( class_exists( Cache::class ) ) {
                Cache::bump( Cache::TABLE_SETTINGS );
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
            $raw_checkin = sanitize_text_field( (string) wp_unslash($data['mhbo_checkin_time']));
            update_option('mhbo_checkin_time', HotelTime::sanitize_hhmm($raw_checkin, HotelTime::get_checkin_time()));
        }
        if (isset($data['mhbo_checkout_time'])) {
            $raw_checkout = sanitize_text_field( (string) wp_unslash($data['mhbo_checkout_time']));
            update_option('mhbo_checkout_time', HotelTime::sanitize_hhmm($raw_checkout, HotelTime::get_checkout_time()));
        }
        if (isset($data['mhbo_booking_page'])) {
            update_option('mhbo_booking_page', absint(wp_unslash($data['mhbo_booking_page'])));
        }
        if (isset($data['mhbo_guest_portal_page'])) {
            update_option('mhbo_guest_portal_page', absint(wp_unslash($data['mhbo_guest_portal_page'])));
        }
        if (isset($data['mhbo_portal_modify_mode'])) {
            $raw_modify_mode = sanitize_key( (string) wp_unslash($data['mhbo_portal_modify_mode']));
            update_option('mhbo_portal_modify_mode', in_array($raw_modify_mode, array('request_only', 'auto_unpaid', 'disabled'), true) ? $raw_modify_mode : 'request_only');
        }
        if (isset($data['mhbo_portal_cancel_mode'])) {
            $raw_cancel_mode = sanitize_key( (string) wp_unslash($data['mhbo_portal_cancel_mode']));
            update_option('mhbo_portal_cancel_mode', in_array($raw_cancel_mode, array('request_only', 'auto_unpaid', 'disabled'), true) ? $raw_cancel_mode : 'request_only');
        }
        if (isset($data['mhbo_cancellation_lead_hours'])) {
            update_option('mhbo_cancellation_lead_hours', min(720, absint(wp_unslash($data['mhbo_cancellation_lead_hours']))));
        }
        if (isset($data['mhbo_portal_max_modifications'])) {
            update_option('mhbo_portal_max_modifications', min(20, absint(wp_unslash($data['mhbo_portal_max_modifications']))));
        }
        if (isset($data['mhbo_cancellation_policy'])) {
            update_option('mhbo_cancellation_policy', sanitize_textarea_field( (string) wp_unslash($data['mhbo_cancellation_policy'])));
        }
        if (isset($data['mhbo_terms_page'])) {
            update_option('mhbo_terms_page', absint(wp_unslash($data['mhbo_terms_page'])));
        }
        if (isset($data['mhbo_label_templates']['booking_terms_checkbox_text']) && is_array($data['mhbo_label_templates'])) {
            $raw_terms_data = wp_unslash($data['mhbo_label_templates']['booking_terms_checkbox_text']);
            if (is_array($raw_terms_data)) {
                $terms_data = array_map('wp_kses_post', $raw_terms_data);
            } else {
                $terms_data = wp_kses_post( (string) $raw_terms_data);
            }
            update_option('mhbo_label_booking_terms_checkbox_text', I18n::encode($terms_data));
        }

if (isset($data['mhbo_notification_email'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_email = wp_unslash($data['mhbo_notification_email']);
            update_option('mhbo_notification_email', $this->settings->sanitize_notification_email($raw_email));
        }
        if (isset($data['mhbo_additional_notification_email'])) {
            $raw_extra = wp_unslash($data['mhbo_additional_notification_email']);
            update_option('mhbo_additional_notification_email', $this->settings->sanitize_additional_notification_emails($raw_extra));
        }
        if (isset($data['mhbo_booking_page_url'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_url = wp_unslash($data['mhbo_booking_page_url']);
            update_option('mhbo_booking_page_url', esc_url_raw($raw_url));
        }

if (isset($data['mhbo_hotel_timezone'])) {
            // 2026 BP: Rule 11 - Individual extraction then sanitization.
            $raw_tz = wp_unslash($data['mhbo_hotel_timezone']);
            update_option('mhbo_hotel_timezone', $this->settings->sanitize_timezone($raw_tz));
        }

if (isset($data['mhbo_trusted_proxies'])) {
            $raw_proxies = wp_unslash($data['mhbo_trusted_proxies']);
            update_option('mhbo_trusted_proxies', Security::sanitize_trusted_proxies($raw_proxies));
        }

        if (isset($data['mhbo_hotel_wifi_ssid'])) {
            $raw_ssid = wp_unslash($data['mhbo_hotel_wifi_ssid']);
            update_option('mhbo_hotel_wifi_ssid', sanitize_text_field($raw_ssid));
        }
        if (isset($data['mhbo_hotel_wifi_password'])) {
            $raw_wifi_pass = wp_unslash($data['mhbo_hotel_wifi_password']);
            update_option('mhbo_hotel_wifi_password', sanitize_text_field($raw_wifi_pass), false);
        }
        if (isset($data['mhbo_hotel_guest_instructions'])) {
            $raw_instructions = wp_unslash($data['mhbo_hotel_guest_instructions']);
            update_option('mhbo_hotel_guest_instructions', wp_kses_post($raw_instructions));
        }

// Boolean Fields
        $bool_fields = array(
            'mhbo_modal_enabled',
            'mhbo_calendar_show_title',
            'mhbo_calendar_show_badge',
            'mhbo_booking_show_title',
            'mhbo_booking_show_badge',
            'mhbo_rooms_show_title',
            'mhbo_rooms_show_badge',
            'mhbo_room_cards_show_title',
            'mhbo_room_cards_show_badge',
            'mhbo_portal_show_title',
            'mhbo_portal_show_badge',
            'mhbo_portal_self_service_enabled',
            'mhbo_cancellation_policy_enabled',
            'mhbo_booking_terms_checkbox_enabled',
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

        if (
            1 === (int) get_option('mhbo_portal_self_service_enabled', 0)
            && 'disabled' === (string) get_option('mhbo_portal_modify_mode', 'request_only')
            && 'disabled' === (string) get_option('mhbo_portal_cancel_mode', 'request_only')
        ) {
            update_option('mhbo_portal_self_service_enabled', 0);
            add_settings_error(
                'mhbo_settings',
                'mhbo_portal_self_service_auto_off',
                __('Guest self-service was turned off automatically because both Stay Date Changes and Cancellations are set to Disabled.', 'modern-hotel-booking'),
                'info'
            );
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
            $custom_fields      = array();
            $allowed_field_type = array('text', 'number', 'textarea');
            $raw_custom_fields  = wp_unslash($data['mhbo_custom_fields']);
            $fields_data        = map_deep($raw_custom_fields, 'sanitize_text_field');
            foreach ($fields_data as $field) {
                if (isset($field['id']) && $field['id'] !== '' && isset($field['label'], $field['type'])) {
                    $f_type = sanitize_key( (string) $field['type']);
                    $custom_fields[] = array(
                        'id'       => sanitize_key($field['id']),
                        'label'    => is_array($field['label']) ? array_map('sanitize_text_field', $field['label']) : sanitize_text_field($field['label']),
                        'type'     => in_array($f_type, $allowed_field_type, true) ? $f_type : 'text',
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
            update_option('mhbo_currency_position', $this->settings->sanitize_currency_position($raw_position));
        }
        if (isset($data['mhbo_price_decimals'])) {
            $raw_decimals = wp_unslash($data['mhbo_price_decimals']);
            update_option('mhbo_price_decimals', $this->settings->sanitize_price_decimals($raw_decimals));
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
        if (isset($data['mhbo_max_advance_booking_days'])) {
            update_option('mhbo_max_advance_booking_days', $this->settings->sanitize_advance_booking_days(wp_unslash($data['mhbo_max_advance_booking_days'])));
        }

        add_settings_error('mhbo_settings', 'saved', I18n::get_label('msg_general_saved'), 'success');
    }

/**
     * @param array<string, mixed> $data
     */
    public function save_payments_settings( array $data ): void {
        
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
