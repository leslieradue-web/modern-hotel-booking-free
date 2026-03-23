<?php declare(strict_types=1);

namespace MHBO\Admin;
if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Core\I18n;

class Settings
{
    public function init(): void
    {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'save_general_settings'));
        add_action('admin_init', array($this, 'save_multilingual_settings'));
        add_action('admin_init', array($this, 'save_amenities_settings'));
        add_action('admin_init', array($this, 'register_wpml_polylang_strings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

}

    /**
     * Enqueue admin settings scripts.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue_scripts(string $hook): void
    {
        // Only load on settings and pro pages
        if (false === strpos($hook, 'mhbo-settings') && false === strpos($hook, 'mhbo-pro')) {
            return;
        }

        wp_enqueue_script(
            'mhbo-admin-settings',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-settings.js',
            array('jquery'),
            MHBO_VERSION,
            true
        );

        // Get active languages for custom fields
        $langs = I18n::get_available_languages();
        $lang_labels = array();
        foreach ($langs as $lang) {
            $lang_labels[$lang] = $lang;
        }

        // Inject configuration
        $config = array(
            'nonces' => array(
                
                'test_stripe' => wp_create_nonce('mhbo_test_stripe_nonce'),
                'test_paypal' => wp_create_nonce('mhbo_test_paypal_nonce'),
                'clear_cache' => wp_create_nonce('mhbo_clear_cache_nonce_field'),
            ),
            'i18n' => array(
                
                'connection_error' => __('Connection error.', 'modern-hotel-booking'),
                'are_you_sure' => __('Are you sure?', 'modern-hotel-booking'),
                'remove_field_confirm' => __('Are you sure you want to remove this field?', 'modern-hotel-booking'),
                'no_holidays' => __('No holidays added yet.', 'modern-hotel-booking'),
            ),
            'langLabels' => $lang_labels,
        );
        wp_add_inline_script('mhbo-admin-settings', 'window.mhboAdminSettingsConfig = ' . wp_json_encode($config) . ';', 'before');
    }

    /**
     * Register strings for WPML/Polylang translation
     */
    public function register_wpml_polylang_strings(): void
    {
        I18n::register_plugin_strings();
    }

    public function register_settings(): void
    {
        // General Settings
        register_setting('mhbo_settings_group', 'mhbo_checkin_time', array('default' => '14:00', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_checkout_time', array('default' => '11:00', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_notification_email', array('default' => get_option('admin_email'), 'sanitize_callback' => 'sanitize_email'));
        register_setting('mhbo_settings_group', 'mhbo_prevent_same_day_turnover', array('default' => 0, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_children_enabled', array('default' => 0, 'sanitize_callback' => 'absint'));

        // Currency Settings
        register_setting('mhbo_settings_group', 'mhbo_currency_code', array('default' => 'USD', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_currency_symbol', array('default' => '$', 'sanitize_callback' => 'sanitize_text_field'));
        register_setting('mhbo_settings_group', 'mhbo_currency_position', array('default' => 'before', 'sanitize_callback' => 'sanitize_text_field'));

        // Note: Multilingual label/email options (mhbo_label_*, mhbo_email_*) are dynamically
        // generated and sanitized inline in save_multilingual_settings() using sanitize_text_field()
        // and wp_kses_post(). They are not individually registered as they are dynamic keys.

        // Custom Fields
        register_setting('mhbo_settings_group', 'mhbo_custom_fields', array('default' => [], 'sanitize_callback' => array($this, 'sanitize_custom_fields')));
        register_setting('mhbo_settings_group', 'mhbo_terms_page', array('default' => 0, 'sanitize_callback' => 'absint'));

        // Booking Page Settings
        register_setting('mhbo_settings_group', 'mhbo_booking_page', array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting('mhbo_settings_group', 'mhbo_booking_page_url', array('type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => ''));

// Uninstall Settings
        register_setting('mhbo_settings_group', 'mhbo_save_data_on_uninstall', array('default' => 1, 'sanitize_callback' => 'absint'));

        // Display Settings
        register_setting('mhbo_settings_group', 'mhbo_powered_by_link', array('default' => 0, 'sanitize_callback' => 'absint'));

        // Performance Settings

// Theme Settings

// Amenities (Dynamic) - Handled manually with inline sanitization
        // register_setting('mhbo_settings_group', 'mhbo_amenities_list');

        add_settings_section('mhbo_general_section', __('General Settings', 'modern-hotel-booking'), '__return_null', 'mhbo-settings');
        add_settings_field('mhbo_checkin_time', __('Default Check-in Time', 'modern-hotel-booking'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_checkin_time'));
        add_settings_field('mhbo_checkout_time', __('Default Check-out Time', 'modern-hotel-booking'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_checkout_time'));
        add_settings_field('mhbo_notification_email', __('Notification Email', 'modern-hotel-booking'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_notification_email'));
        add_settings_field('mhbo_booking_page', __('Booking Page', 'modern-hotel-booking'), array($this, 'render_page_select_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_booking_page'));
        add_settings_field('mhbo_booking_page_url', __('Booking Page URL (Override)', 'modern-hotel-booking'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_booking_page_url'));
        add_settings_field('mhbo_prevent_same_day_turnover', __('Same-day Turnover', 'modern-hotel-booking'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for' => 'mhbo_prevent_same_day_turnover',
            'description' => __('If enabled, rooms can be checked-in on the same day someone else checks out. If disabled, a gap day is required.', 'modern-hotel-booking')
        ));
        add_settings_field('mhbo_children_enabled', __('Enable Children Management', 'modern-hotel-booking'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for' => 'mhbo_children_enabled',
            'description' => __('Show children selector in search and booking forms.', 'modern-hotel-booking')
        ));
        add_settings_field('mhbo_custom_fields', __('Custom Guest Fields', 'modern-hotel-booking'), array($this, 'render_custom_fields_repeater'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_custom_fields'));
        add_settings_field('mhbo_save_data_on_uninstall', __('Save Data on Uninstall', 'modern-hotel-booking'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for'   => 'mhbo_save_data_on_uninstall',
            'description' => __('If enabled, your data and database tables will be preserved when uninstalling the plugin. This is important for free to pro upgrades.', 'modern-hotel-booking'),
            'default'     => 1,
        ));
        add_settings_field('mhbo_powered_by_link', __('Show Powered By Link', 'modern-hotel-booking'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for' => 'mhbo_powered_by_link',
            'description' => __('Display a small "Powered by MHB" link below the calendar.', 'modern-hotel-booking')
        ));

        add_settings_section('mhbo_currency_section', __('Currency Settings', 'modern-hotel-booking'), '__return_null', 'mhbo-settings');
        add_settings_field('mhbo_currency_code', __('Currency Code (e.g. USD)', 'modern-hotel-booking'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_currency_section', array('label_for' => 'mhbo_currency_code'));
        add_settings_field('mhbo_currency_symbol', __('Currency Symbol (e.g. $)', 'modern-hotel-booking'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_currency_section', array('label_for' => 'mhbo_currency_symbol'));
        add_settings_field('mhbo_currency_position', __('Symbol Position', 'modern-hotel-booking'), array($this, 'render_select_field'), 'mhbo-settings', 'mhbo_currency_section', array(
            'label_for' => 'mhbo_currency_position',
            'options' => [
                'before' => __('Before Amount ($100)', 'modern-hotel-booking'),
                'after' => __('After Amount (100$)', 'modern-hotel-booking')
            ]
        ));
    }

    public function sanitize_custom_fields($fields): array
    {
        if (!is_array($fields))
            return [];
        $sanitized = [];
        foreach ($fields as $field) {
            $sanitized_field = [
                'id' => isset($field['id']) ? sanitize_key($field['id']) : '',
                'type' => isset($field['type']) ? sanitize_text_field($field['type']) : 'text',
                'required' => isset($field['required']) ? absint($field['required']) : 0,
            ];

            if (isset($field['label']) && is_array($field['label'])) {
                foreach ($field['label'] as $lang => $label) {
                    $sanitized_field['label'][sanitize_key($lang)] = sanitize_text_field($label);
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

    public function render_text_field($args): void
    {
        $option = get_option($args['label_for']);
        $value = I18n::decode($option);
        echo '<input type="text" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function render_select_field($args): void
    {
        $option = get_option($args['label_for']);
        $option = I18n::decode($option);
        echo '<select id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '">';
        foreach ($args['options'] as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($option, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function render_page_select_field($args): void
    {
        $option = get_option($args['label_for'], 0);
        wp_dropdown_pages(array(
            'name' => esc_attr($args['label_for']),
            'selected' => absint($option),
            'show_option_none' => esc_html__('— Select a Page —', 'modern-hotel-booking'),
            'class' => 'regular-text'
        ));
        echo '<p class="description">' . esc_html__('Select the page where you have placed the [modern_hotel_booking] shortcode.', 'modern-hotel-booking') . '</p>';
    }

    public function render_checkbox_field($args): void
    {
        $default = isset($args['default']) ? $args['default'] : 0;
        $option = get_option($args['label_for'], $default);
        echo '<input type="checkbox" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="1" ' . checked(1, $option, false) . '>';
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function render_custom_fields_repeater($args): void
    {
        $fields = get_option('mhbo_custom_fields', []);
        $langs = I18n::get_available_languages();
        ?>
        <div id="mhbo-custom-fields-repeater" style="max-width: 800px;">
            <div class="mhbo-repeater-items">
                <?php if (!empty($fields)):
                    foreach ($fields as $index => $field): ?>
                        <div class="mhbo-repeater-item"
                            style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 15px; position: relative; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
                            <button type="button" class="mhbo-remove-field"
                                style="position: absolute; top: 10px; right: 10px; color: #d63638; background: none; border: none; font-size: 20px; cursor: pointer; padding: 0;">&times;</button>

                            <div
                                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 12px;">
                                <div>
                                    <label
                                        style="display: block; font-weight: bold; margin-bottom: 5px;"><?php esc_html_e('Field ID (slug)', 'modern-hotel-booking'); ?></label>
                                    <input type="text" name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][id]"
                                        value="<?php echo esc_attr($field['id']); ?>" class="widefat" placeholder="e.g. address"
                                        required>
                                </div>
                                <div>
                                    <label
                                        style="display: block; font-weight: bold; margin-bottom: 5px;"><?php esc_html_e('Type', 'modern-hotel-booking'); ?></label>
                                    <select name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][type]" class="widefat">
                                        <option value="text" <?php selected($field['type'], 'text'); ?>>
                                            <?php esc_html_e('Text', 'modern-hotel-booking'); ?>
                                        </option>
                                        <option value="number" <?php selected($field['type'], 'number'); ?>>
                                            <?php esc_html_e('Number', 'modern-hotel-booking'); ?>
                                        </option>
                                        <option value="textarea" <?php selected($field['type'], 'textarea'); ?>>
                                            <?php esc_html_e('Textarea', 'modern-hotel-booking'); ?>
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div style="margin-bottom: 12px;">
                                <label
                                    style="display: block; font-weight: bold; margin-bottom: 8px;"><?php esc_html_e('Label (Multilingual)', 'modern-hotel-booking'); ?></label>
                                <?php foreach ($langs as $lang): ?>
                                    <div style="display: flex; align-items: center; margin-bottom: 5px;">
                                        <span
                                            style="width: 35px; font-weight: 600; font-size: 11px;"><?php echo esc_html(strtoupper($lang)); ?>:</span>
                                        <input type="text"
                                            name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][label][<?php echo esc_attr($lang); ?>]"
                                            value="<?php echo esc_attr(isset($field['label'][$lang]) ? $field['label'][$lang] : ''); ?>"
                                            class="widefat" style="flex: 1;">
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div>
                                <label style="font-weight: bold;">
                                    <input type="checkbox" name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][required]"
                                        value="1" <?php checked(isset($field['required']) && $field['required']); ?>>
                                    <?php esc_html_e('Required Field', 'modern-hotel-booking'); ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
            </div>

            <button type="button" id="mhbo-add-custom-field" class="button button-secondary"
                style="margin-top: 10px;"><?php esc_html_e('+ Add New Guest Field', 'modern-hotel-booking'); ?></button>
            <p class="description">
                <?php esc_html_e('Define extra fields for your booking form. Labels support multilingual tags.', 'modern-hotel-booking'); ?>
            </p>
        </div>
        <?php
        // Note: Custom fields JavaScript logic has been moved to assets/js/mhbo-admin-settings.js
        // Configuration is injected via wp_add_inline_script() in enqueue_scripts()
    }

    private static function is_tab_license_gated(string $tab): bool
    {
        
        return false;

}

    public static function render()
    {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

?>
        <div class="wrap mhbo-admin-wrap">
            <h1 style="margin-bottom: 25px; font-weight: 800; color: #1a3b5d;">
                <?php esc_html_e('Hotel Configuration', 'modern-hotel-booking'); ?>
            </h1>
            <?php settings_errors('mhbo_settings'); ?>
            <?php settings_errors('mhbo_amenities'); ?>
            <h2 class="nav-tab-wrapper">
                
                <a href="?page=mhbo-settings&tab=general"
                    class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('General', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhbo-settings&tab=emails"
                    class="nav-tab <?php echo 'emails' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Email Templates', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhbo-settings&tab=labels"
                    class="nav-tab <?php echo 'labels' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Frontend Labels', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhbo-settings&tab=amenities"
                    class="nav-tab <?php echo 'amenities' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Amenities', 'modern-hotel-booking'); ?></a>

</h2>

<div class="mhbo-card" style="margin-top: 20px;">
                <?php
                $manual_tabs = [
                    
                    'emails',
                    'labels',
                    'amenities',
                    
                    'general'
                ];
                $action = in_array($active_tab, $manual_tabs, true) ? '' : 'options.php';
                ?>
                <form method="post" action="<?php echo esc_attr($action); ?>">
                    <?php
                    // Don't use settings_fields for manual tabs to avoid WP trying to save to options.php
                    if (!in_array($active_tab, $manual_tabs, true)) {
                        settings_fields('mhbo_settings_group');
                    } else {
                        wp_nonce_field('mhbo_settings_nonce', 'mhbo_nonce');
                    }

                    if ('license' === $active_tab) {
                        
                    } elseif ('general' === $active_tab) {
                        do_settings_sections('mhbo-settings');
                    } elseif ('emails' === $active_tab) {
                        self::render_email_templates_tab();
                    } elseif ('labels' === $active_tab) {
                        self::render_labels_tab();
                    } elseif ('amenities' === $active_tab) {
                        self::render_amenities_tab();

}

                    // Show save button — Pro version gates locked tabs, Free always shows
                    
                    $show_save = true;

if ($show_save) {
                        echo '<input type="hidden" name="mhbo_save_tab" value="' . esc_attr($active_tab) . '">';
                        submit_button();
                    }

                    ?>
                </form>
            </div>
        </div>
        <?php
    }

/**
     * Render Performance/Cache settings tab.
     */
    private static function render_performance_tab()
    {
        $cache_enabled = get_option('mhbo_cache_enabled', 1);

        // Safely check if object cache is available
        $object_cache_available = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

        echo '<h2>' . esc_html__('Performance Settings', 'modern-hotel-booking') . '</h2>';
        echo '<p>' . esc_html__('Configure caching and performance options for faster page loads.', 'modern-hotel-booking') . '</p>';

        echo '<table class="form-table" role="presentation">';

        // Cache Enable/Disable
        echo '<tr>';
        echo '<th scope="row"><label for="mhbo_cache_enabled">' . esc_html__('Enable Caching', 'modern-hotel-booking') . '</label></th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" id="mhbo_cache_enabled" name="mhbo_cache_enabled" value="1" ' . checked($cache_enabled, 1, false) . '>';
        echo ' ' . esc_html__('Enable caching for room data and pricing', 'modern-hotel-booking');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Cache room type data and pricing rules for faster calculations. Recommended for most sites.', 'modern-hotel-booking') . '</p>';
        echo '</td>';
        echo '</tr>';

        // Object Cache Status
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Object Cache Status', 'modern-hotel-booking') . '</th>';
        echo '<td>';
        if ($object_cache_available) {
            echo '<span style="color: green; font-weight: bold;">&#10003; ' . esc_html__('Active', 'modern-hotel-booking') . '</span>';
            echo '<p class="description">' . esc_html__('A persistent object cache (Redis, Memcached, etc.) is detected. Cache data will persist across requests.', 'modern-hotel-booking') . '</p>';
        } else {
            echo '<span style="color: #dba617; font-weight: bold;">&#9888; ' . esc_html__('Using Database Transients', 'modern-hotel-booking') . '</span>';
            echo '<p class="description">' . esc_html__('No persistent object cache detected. Consider installing Redis or Memcached for better performance.', 'modern-hotel-booking') . '</p>';
        }
        echo '</td>';
        echo '</tr>';

        // Clear Cache Button
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Clear Cache', 'modern-hotel-booking') . '</th>';
        echo '<td>';
        echo '<button type="button" id="mhbo_clear_cache" class="button button-secondary">' . esc_html__('Clear All Cache', 'modern-hotel-booking') . '</button>';
        echo '<span id="mhbo_cache_spinner" class="spinner" style="float: none; margin-left: 10px;"></span>';
        echo '<p class="description">' . esc_html__('Clear all cached data. Use this if you see outdated pricing or room information.', 'modern-hotel-booking') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        // Note: Cache clear JS handler has been moved to assets/js/mhbo-admin-settings.js
        // Nonce is injected via wp_add_inline_script() in enqueue_scripts()
    }
private static function render_email_templates_tab()
    {
        $langs = I18n::get_available_languages();
        $statuses = [
            'pending' => __('Pending Booking', 'modern-hotel-booking'),
            'confirmed' => __('Confirmed Booking', 'modern-hotel-booking'),
            'cancelled' => __('Cancelled Booking', 'modern-hotel-booking'),
            'payment' => __('Payment Confirmation', 'modern-hotel-booking')
        ];

        echo '<div class="mhbo-email-templates-wrap">';
        foreach ($statuses as $status => $label) {
            echo '<h3>' . esc_html($label) . '</h3>';
            echo '<table class="form-table">';

            // Subject
            $subject_val = get_option("mhbo_email_{$status}_subject");
            echo '<tr><th>' . esc_html__('Subject', 'modern-hotel-booking') . '</th><td>';
            foreach ($langs as $lang) {
                $val = I18n::decode($subject_val, $lang);
                echo '<div style="margin-bottom:5px;"><strong>' . esc_html(strtoupper($lang)) . ':</strong><br>';
                echo '<input type="text" name="mhbo_email_templates[' . esc_attr($status) . '][subject][' . esc_attr($lang) . ']" value="' . esc_attr($val) . '" class="large-text"></div>';
            }
            echo '</td></tr>';

            // Message
            $message_val = get_option("mhbo_email_{$status}_message");
            echo '<tr><th>' . esc_html__('Message', 'modern-hotel-booking') . '</th><td>';
            foreach ($langs as $lang) {
                $val = I18n::decode($message_val, $lang);
                echo '<div style="margin-bottom:10px;"><strong>' . esc_html(strtoupper($lang)) . ':</strong><br>';
                wp_editor($val, "mhbo_email_{$status}_{$lang}", array('textarea_name' => "mhbo_email_templates[{$status}][message][{$lang}]", 'textarea_rows' => 5));
                echo '</div>';
            }
            echo '<p class="description">' . esc_html__('Available placeholders: {customer_name}, {customer_email}, {customer_phone}, {site_name}, {booking_id}, {booking_token}, {status}, {check_in}, {check_out}, {total_price}, {guests}, {children}, {room_name}, {custom_fields}, {booking_extras}, {payment_details}, {tax_breakdown}, {tax_breakdown_text}, {tax_total}, {tax_registration_number}', 'modern-hotel-booking') . '</p>';
            echo '</td></tr>';

            echo '</table><hr>';
        }
        echo '</div>';

    }

private static function render_labels_tab()
    {
        $langs = I18n::get_available_languages();
        $label_groups = [
            'Search & Selection' => [
                'btn_search_rooms' => __('Search Rooms Button', 'modern-hotel-booking'),
                'label_check_in' => __('Check-in Label', 'modern-hotel-booking'),
                'label_check_out' => __('Check-out Label', 'modern-hotel-booking'),
                'label_guests' => __('Guests Label', 'modern-hotel-booking'),
                'label_children' => __('Children Label', 'modern-hotel-booking'),
                'label_child_ages' => __('Child Ages Label', 'modern-hotel-booking'),
                // translators: %d: child number (1, 2, 3, etc.)
                'label_child_n_age' => __('Child %d Age Label', 'modern-hotel-booking'),
                'label_select_dates' => __('Select Dates Label', 'modern-hotel-booking'),
                'label_dates_selected' => __('Dates Selected Message', 'modern-hotel-booking'),
                'label_your_selection' => __('Your Selection Label', 'modern-hotel-booking'),
                'label_continue_booking' => __('Continue to Booking Button', 'modern-hotel-booking'),
                'label_availability_error' => __('Dates Not Available Error', 'modern-hotel-booking'),
                'label_stay_dates' => __('Stay Dates Label', 'modern-hotel-booking'),
                'label_select_check_in' => __('Select Check-in Guide', 'modern-hotel-booking'),
                'label_select_check_out' => __('Select Check-out Guide', 'modern-hotel-booking'),
                'label_calendar_no_id' => __('Calendar No ID Error', 'modern-hotel-booking'),
                'label_calendar_config_error' => __('Calendar Config Error', 'modern-hotel-booking'),
                'label_select_dates_error' => __('Select Dates Error', 'modern-hotel-booking'),
                'label_block_no_room' => __('Block No Room Error', 'modern-hotel-booking'),
                'label_check_in_past' => __('Check-in in Past Error', 'modern-hotel-booking'),
                'label_check_out_after' => __('Check-out Before Check-in Error', 'modern-hotel-booking'),
                'label_check_in_future' => __('Check-in Too Far Error', 'modern-hotel-booking'),
                'label_check_out_future' => __('Check-out Too Far Error', 'modern-hotel-booking'),
                'label_legend_confirmed' => __('Booked Calendar Legend', 'modern-hotel-booking'),
                'label_legend_pending' => __('Pending Calendar Legend', 'modern-hotel-booking'),
                'label_legend_available' => __('Available Calendar Legend', 'modern-hotel-booking'),
                'label_room_alt_text' => __('Room Image Alt Text', 'modern-hotel-booking'),
            ],
            'Results & Pricing' => [
                'label_available_rooms' => __('Available Rooms Message', 'modern-hotel-booking'),
                'label_no_rooms' => __('No Rooms Found Message', 'modern-hotel-booking'),
                'label_per_night' => __('Per Night Text', 'modern-hotel-booking'),
                'label_total_nights' => __('Total Nights Price Summary', 'modern-hotel-booking'),
                'label_max_guests' => __('Max Guests Text', 'modern-hotel-booking'),
                'label_loading' => __('Loading Message', 'modern-hotel-booking'),
                'label_to' => __('To Separator', 'modern-hotel-booking'),
                'btn_book_now' => __('Book Now Button', 'modern-hotel-booking'),
                'btn_processing' => __('Processing Button Text', 'modern-hotel-booking'),
            ],
            'Booking Form' => [
                'label_complete_booking' => __('Complete Booking Title', 'modern-hotel-booking'),
                'label_total' => __('Total Price Label', 'modern-hotel-booking'),
                'label_name' => __('Full Name Label', 'modern-hotel-booking'),
                'label_email' => __('Email Address Label', 'modern-hotel-booking'),
                'label_phone' => __('Phone Number Label', 'modern-hotel-booking'),
                'label_special_requests' => __('Special Requests Label', 'modern-hotel-booking'),
                'label_secure_payment' => __('Secure Payment Message', 'modern-hotel-booking'),
                'label_security_error' => __('Security Error Message', 'modern-hotel-booking'),
                'label_rate_limit_error' => __('Rate Limit Error Message', 'modern-hotel-booking'),
                'label_spam_honeypot' => __('Spam Honeypot Label', 'modern-hotel-booking'),
                'btn_confirm_booking' => __('Confirm Booking Button', 'modern-hotel-booking'),
                'btn_pay_confirm' => __('Pay & Confirm Button', 'modern-hotel-booking'),
                'label_confirm_request' => __('Confirm Request Message', 'modern-hotel-booking'),
                'label_room_not_found' => __('Room Not Found Error', 'modern-hotel-booking'),
                'label_name_too_long' => __('Name Too Long Error', 'modern-hotel-booking'),
                'label_phone_too_long' => __('Phone Too Long Error', 'modern-hotel-booking'),
                'label_max_children_error' => __('Max Children Error', 'modern-hotel-booking'),
                'label_max_adults_error' => __('Max Adults Error', 'modern-hotel-booking'),
                'label_price_calc_error' => __('Price Calculation Error', 'modern-hotel-booking'),
                'label_fill_all_fields' => __('Missing Fields Error', 'modern-hotel-booking'),
                'label_field_required' => __('Required Field Error', 'modern-hotel-booking'),
                'label_spam_detected' => __('Spam Detected Error', 'modern-hotel-booking'),
                'label_already_booked' => __('Room Already Booked Error', 'modern-hotel-booking'),
                'label_invalid_email' => __('Invalid Email Error', 'modern-hotel-booking'),
            ],
            'Confirmation Messages' => [
                'msg_booking_confirmed' => __('Booking Success Message', 'modern-hotel-booking'),
                'msg_confirmation_sent' => __('Email Sent Message', 'modern-hotel-booking'),
                'msg_booking_received' => __('Request Received Title', 'modern-hotel-booking'),
                'msg_booking_received_detail' => __('Request Received Detail', 'modern-hotel-booking'),
                'label_arrival_msg' => __('Pay on Arrival Message', 'modern-hotel-booking'),
                'msg_gdpr_required' => __('GDPR Required Warning', 'modern-hotel-booking'),
                'label_privacy_policy' => __('Privacy Policy Link Text', 'modern-hotel-booking'),
                'label_terms_conditions' => __('Terms & Conditions Link Text', 'modern-hotel-booking'),
                'msg_paypal_required' => __('PayPal Required Warning', 'modern-hotel-booking'),
                'msg_payment_success_email' => __('Payment Success Confirmation', 'modern-hotel-booking'),
                'msg_booking_arrival_email' => __('Booking arrival confirmation', 'modern-hotel-booking'),
                'msg_payment_failed_detail' => __('Payment Failed Detail', 'modern-hotel-booking'),
                'msg_booking_received_pending' => __('Booking Received Pending Detail', 'modern-hotel-booking'),
            ],
            'Payments' => [
                'label_payment_method' => __('Payment Method Label', 'modern-hotel-booking'),
                'label_pay_arrival' => __('Pay on Arrival Option', 'modern-hotel-booking'),
                'label_credit_card' => __('Credit Card Option', 'modern-hotel-booking'),
                'label_paypal' => __('PayPal Option', 'modern-hotel-booking'),
                'label_payment_status' => __('Payment Status Label', 'modern-hotel-booking'),
                'label_paid' => __('Paid Badge Text', 'modern-hotel-booking'),
                'label_amount_paid' => __('Amount Paid Label', 'modern-hotel-booking'),
                'label_transaction_id' => __('Transaction ID Label', 'modern-hotel-booking'),
                'label_failed' => __('Failed Badge Text', 'modern-hotel-booking'),
                'label_payment_failed' => __('Payment Failed Title', 'modern-hotel-booking'),
                'label_dates_no_longer_available' => __('Dates Taken Error', 'modern-hotel-booking'),
                'label_invalid_booking_calc' => __('Invalid Booking Calc Error', 'modern-hotel-booking'),
                'label_stripe_not_configured' => __('Stripe Not Configured Error', 'modern-hotel-booking'),
                'label_paypal_not_configured' => __('PayPal Not Configured Error', 'modern-hotel-booking'),
                'label_paypal_connection_error' => __('PayPal Connection Error', 'modern-hotel-booking'),
                'label_paypal_auth_failed' => __('PayPal Auth Error', 'modern-hotel-booking'),
                'label_paypal_order_create_error' => __('PayPal Order Error', 'modern-hotel-booking'),
                'label_paypal_currency_unsupported' => __('PayPal Currency Error', 'modern-hotel-booking'),
                'label_paypal_generic_error' => __('PayPal Generic Error', 'modern-hotel-booking'),
                'label_missing_order_id' => __('Missing Order ID Error', 'modern-hotel-booking'),
                'label_paypal_capture_error' => __('PayPal Capture Error', 'modern-hotel-booking'),
                'label_payment_already_processed' => __('Payment Already Processed Error', 'modern-hotel-booking'),
                'label_payment_declined_paypal' => __('Payment Declined Error', 'modern-hotel-booking'),
                'label_stripe_intent_missing' => __('Stripe Intent Missing Error', 'modern-hotel-booking'),
                'label_paypal_id_missing' => __('PayPal ID Missing Error', 'modern-hotel-booking'),
                'label_payment_required' => __('Payment Required Error', 'modern-hotel-booking'),
                'label_rest_pro_error' => __('REST Pro Access Error', 'modern-hotel-booking'),
                'label_invalid_nonce' => __('Invalid Nonce Error', 'modern-hotel-booking'),
                'label_api_rate_limit' => __('API Rate Limit Error', 'modern-hotel-booking'),
                'label_payment_confirmation' => __('Email: Payment Confirmation Title', 'modern-hotel-booking'),
                'label_payment_info' => __('Email: Payment Info Title', 'modern-hotel-booking'),
                'msg_pay_on_arrival_email' => __('Email: Pay on Arrival Detail', 'modern-hotel-booking'),
                'label_amount_due' => __('Email: Amount Due Label', 'modern-hotel-booking'),
                'label_payment_date' => __('Email: Payment Date Label', 'modern-hotel-booking'),
                'label_paypal_order_failed' => __('PayPal Order Creation Failed', 'modern-hotel-booking'),
                'label_security_verification_failed' => __('Security Verification Failed', 'modern-hotel-booking'),
                'label_paypal_client_id_missing' => __('PayPal Client ID Missing', 'modern-hotel-booking'),
                'label_paypal_secret_missing' => __('PayPal Secret Missing', 'modern-hotel-booking'),
                'label_api_not_configured' => __('API Key Not Configured', 'modern-hotel-booking'),
                'label_invalid_api_key' => __('Invalid API Key', 'modern-hotel-booking'),
                'label_webhook_sig_required' => __('Webhook Signature Required', 'modern-hotel-booking'),
                'label_stripe_webhook_secret_missing' => __('Stripe Webhook Secret Missing', 'modern-hotel-booking'),
                'label_invalid_stripe_sig_format' => __('Invalid Stripe Signature Format', 'modern-hotel-booking'),
                'label_webhook_expired' => __('Webhook Expired', 'modern-hotel-booking'),
                'label_invalid_stripe_sig' => __('Invalid Stripe Signature', 'modern-hotel-booking'),
                'label_missing_paypal_headers' => __('Missing PayPal Headers', 'modern-hotel-booking'),
                'label_invalid_customer' => __('Invalid Customer Data', 'modern-hotel-booking'),
                'label_invalid_dates' => __('Invalid Booking Dates', 'modern-hotel-booking'),
                'label_booking_failed' => __('Booking Creation Failed', 'modern-hotel-booking'),
                'label_permission_denied' => __('Permission Denied', 'modern-hotel-booking'),
                'label_stripe_pk_missing' => __('Stripe PK Missing', 'modern-hotel-booking'),
                'label_stripe_sk_missing' => __('Stripe SK Missing', 'modern-hotel-booking'),
                'label_stripe_invalid_pk_format' => __('Invalid Stripe PK Format', 'modern-hotel-booking'),
                'label_credentials_spaces' => __('Credentials Space Warning', 'modern-hotel-booking'),
                'label_mode_mismatch' => __('Sandbox/Live Mismatch Warning', 'modern-hotel-booking'),
                'label_credentials_expired' => __('Credentials Expired Warning', 'modern-hotel-booking'),
                'label_creds_valid_env' => __('PayPal Success Message', 'modern-hotel-booking'),
                'label_stripe_creds_valid' => __('Stripe Success Message', 'modern-hotel-booking'),
                'label_connection_failed' => __('Connection Failed Error', 'modern-hotel-booking'),
                'label_auth_failed_env' => __('Authentication Failed Error', 'modern-hotel-booking'),
                'label_common_causes' => __('Common Causes Title', 'modern-hotel-booking'),
                'label_stripe_generic_error' => __('Stripe Generic Error', 'modern-hotel-booking'),
            ],
            'Booking Extras' => [
                'label_enhance_stay' => __('Enhance Your Stay Title', 'modern-hotel-booking'),
                'label_per_person' => __('Per Person Text', 'modern-hotel-booking'),
                'label_per_person_per_night' => __('Per Person / Night Text', 'modern-hotel-booking'),
            ],
            'Tax & Summary' => [
                'label_booking_summary' => __('Booking Summary Title', 'modern-hotel-booking'),
                'label_accommodation' => __('Accommodation Row Label', 'modern-hotel-booking'),
                'label_extras_item' => __('Extras Row Label', 'modern-hotel-booking'),
                'label_tax_breakdown' => __('Tax Breakdown Label', 'modern-hotel-booking'),
                'label_tax_total' => __('Tax Total Label', 'modern-hotel-booking'),
                'label_tax_registration' => __('Tax Registration Label', 'modern-hotel-booking'),
                'label_includes_tax' => __('Includes Tax Suffix', 'modern-hotel-booking'),
                'label_price_includes_tax' => __('Price Includes Tax Note', 'modern-hotel-booking'),
                'label_tax_added_at_checkout' => __('Tax Added at Checkout Note', 'modern-hotel-booking'),
                'label_subtotal' => __('Subtotal Label', 'modern-hotel-booking'),
                'label_room' => __('Room Column Label', 'modern-hotel-booking'),
                'label_extras' => __('Extras Column Label', 'modern-hotel-booking'),
                'label_item' => __('Item Column Label', 'modern-hotel-booking'),
                'label_amount' => __('Amount Column Label', 'modern-hotel-booking'),
                'label_tax_accommodation' => __('Tax Accommodation Rate Label', 'modern-hotel-booking'),
                'label_tax_extras' => __('Tax Extras Rate Label', 'modern-hotel-booking'),
                'label_tax_rate' => __('Generic Tax Rate Label', 'modern-hotel-booking'),
                'label_tax_note_includes' => /* translators: %s: Tax rate percentage */ __('Tax Note (includes %s)', 'modern-hotel-booking'),
                'label_tax_note_plus' => /* translators: %s: Tax rate percentage */ __('Tax Note (plus %s)', 'modern-hotel-booking'),
                'label_tax_note_includes_multi' => /* translators: %1$s: Tax label, %2$s: First tax rate, %3$s: Second tax rate */ __('Tax Note Multi (includes %1$s: %2$s%% / %3$s%%)', 'modern-hotel-booking'),
                'label_tax_note_plus_multi' => /* translators: %1$s: Tax label, %2$s: First tax rate, %3$s: Second tax rate */ __('Tax Note Multi (plus %1$s: %2$s%% / %3$s%%)', 'modern-hotel-booking'),
            ],
            'Amenities' => []
        ];

        // Add dynamic amenities to labels
        $amenities = get_option('mhbo_amenities_list');
        if (false === $amenities) {
            $amenities = [
                'wifi'      => __('Free WiFi', 'modern-hotel-booking'),
                'ac'        => __('Air Conditioning', 'modern-hotel-booking'),
                'tv'        => __('Smart TV', 'modern-hotel-booking'),
                'breakfast' => __('Breakfast Included', 'modern-hotel-booking'),
                'pool'      => __('Pool View', 'modern-hotel-booking')
            ];
        }
        $amenities = is_array($amenities) ? $amenities : [];
        foreach ($amenities as $key => $label) {
            $label_groups['Amenities'][$key] = $label;
        }

        echo '<div class="mhbo-labels-tab-wrap">';
        // translators: 1: placeholder example %s, 2: placeholder example %d
        echo '<p class="description">' . sprintf(esc_html__('Leave a field empty to use the default English text for that language. Use <code>%1$s</code> or <code>%2$d</code> as placeholders where they appear in the default text.', 'modern-hotel-booking'), '%s', '%d') . '</p>';

        foreach ($label_groups as $group_name => $labels) {
            echo '<h3 style="background:#f6f7f7;padding:10px;border-left:4px solid #2271b1;">' . esc_html($group_name) . '</h3>';
            echo '<table class="form-table">';
            foreach ($labels as $key => $label_desc) {
                $val = get_option("mhbo_label_{$key}");
                echo '<tr><th scope="row">' . esc_html($label_desc) . '<br><small style="font-weight:normal;color:#666;">' . esc_html($key) . '</small></th><td>';
                foreach ($langs as $lang) {
                    $lang_val = I18n::decode($val, $lang);
                    echo '<div style="margin-bottom:5px; display:flex; align-items:center;">';
                    echo '<span style="width:30px;font-weight:bold;">' . esc_html(strtoupper($lang)) . ':</span>';
                    echo '<input type="text" name="mhbo_label_templates[' . esc_attr($key) . '][' . esc_attr($lang) . ']" value="' . esc_attr($lang_val) . '" class="large-text" style="flex:1;">';
                    echo '</div>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    }

    /**
     * Handle the custom multilingual settings saving (Emails & Labels).
     */
    public function save_multilingual_settings(): void
    {
        if (!isset($_POST['mhbo_save_tab']) || !in_array(sanitize_key(wp_unslash($_POST['mhbo_save_tab'])), ['emails', 'labels', 'gdpr'], true)) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'modern-hotel-booking'));
        }

        if (!check_admin_referer('mhbo_settings_nonce', 'mhbo_nonce')) {
            wp_die('Security check failed');
        }

        // Save Emails
        $allowed_email_statuses = ['pending', 'confirmed', 'cancelled', 'payment'];
        if (isset($_POST['mhbo_email_templates']) && is_array($_POST['mhbo_email_templates'])) {
            // Sanitize with wp_kses_post (preserves safe HTML for email message bodies).
            $email_templates_post = map_deep(wp_unslash($_POST['mhbo_email_templates']), 'wp_kses_post');
            foreach ($allowed_email_statuses as $status) {
                if (!isset($email_templates_post[$status]) || !is_array($email_templates_post[$status])) {
                    continue;
                }
                $data = $email_templates_post[$status];
                if (isset($data['subject'])) {
                    // Subjects should be plain text — tighten with sanitize_text_field.
                    $subject_data = is_array($data['subject'])
                        ? array_map('sanitize_text_field', $data['subject'])
                        : sanitize_text_field($data['subject']);
                    update_option("mhbo_email_{$status}_subject", I18n::encode($subject_data));
                }
                if (isset($data['message'])) {
                    // Messages are already safe from map_deep(... 'wp_kses_post').
                    $message_data = $data['message'];
                    update_option("mhbo_email_{$status}_message", I18n::encode($message_data));
                }
            }
        }

        // Save Labels
        if (isset($_POST['mhbo_label_templates']) && is_array($_POST['mhbo_label_templates'])) { // sanitize_text_field applied or checked via nonce later
            $allowed_label_keys = [
                'btn_search_rooms',
                'label_check_in',
                'label_check_out',
                'label_guests',
                'label_children',
                'label_child_ages',
                'label_child_n_age',
                'label_your_selection',
                'label_available_rooms',
                'label_no_rooms',
                'label_per_night',
                'label_total_nights',
                'label_max_guests',
                'btn_book_now',
                'label_complete_booking',
                'label_total',
                'label_name',
                'label_email',
                'label_phone',
                'label_special_requests',
                'btn_confirm_booking',
                'btn_pay_confirm',
                'msg_booking_confirmed',
                'msg_confirmation_sent',
                'msg_booking_received',
                'msg_booking_received_detail',
                'label_arrival_msg',
                'label_payment_method',
                'label_pay_arrival',
                'label_select_dates',
                'label_dates_selected',
                'label_continue_booking',
                'label_confirm_request',
                'label_credit_card',
                'label_paypal',
                'label_booking_summary',
                'label_accommodation',
                'label_extras_item',
                'label_tax_breakdown',
                'label_tax_total',
                'label_tax_registration',
                'label_includes_tax',
                'label_price_includes_tax',
                'label_tax_added_at_checkout',
                'label_subtotal',
                'label_room',
                'label_extras',
                'label_item',
                'label_amount',
                'label_tax_accommodation',
                'label_tax_extras',
                'label_tax_rate',
                'gdpr_checkbox_text',
                'label_availability_error',
                'label_room_not_found',
                'label_stay_dates',
                'label_enhance_stay',
                'label_per_person',
                'label_per_person_per_night',
                'label_tax_note_includes',
                'label_tax_note_plus',
                'label_tax_note_includes_multi',
                'label_tax_note_plus_multi',
                'label_secure_payment',
                'label_security_error',
                'label_rate_limit_error',
                'label_spam_honeypot',
                'label_room_alt_text',
                'label_select_check_in',
                'label_select_check_out',
                'label_calendar_no_id',
                'label_calendar_config_error',
                'label_select_dates_error',
                'label_block_no_room',
                'label_loading',
                'label_to',
                'btn_processing',
                'msg_gdpr_required',
                'msg_paypal_required',
            ];
            $amenities = get_option('mhbo_amenities_list', []);
            $allowed_label_keys = array_merge($allowed_label_keys, array_keys($amenities));
            $label_templates_post = map_deep(wp_unslash($_POST['mhbo_label_templates']), 'sanitize_text_field');
            foreach ($allowed_label_keys as $key) {
                if (!isset($label_templates_post[$key])) {
                    continue;
                }
                $data = $label_templates_post[$key];
                $label_data = is_array($data)
                    ? array_map('sanitize_text_field', $data)
                    : sanitize_text_field($data);
                update_option("mhbo_label_{$key}", I18n::encode($label_data));
            }
        }

        add_settings_error('mhbo_settings', 'saved', __('Multilingual settings saved successfully.', 'modern-hotel-booking'), 'success');
}

    public function save_gdpr_settings(): void
    {
        
    }

public function save_general_settings(): void
    {
        if (!isset($_POST['mhbo_save_tab']) || 'general' !== sanitize_key(wp_unslash($_POST['mhbo_save_tab']))) {
            return;
        }

        // 2026/WP Repo Compliance: Security first
        if (!isset($_POST['mhbo_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mhbo_nonce'])), 'mhbo_settings_nonce')) {
            wp_die(esc_html__('Security check failed.', 'modern-hotel-booking'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'modern-hotel-booking'));
        }

        // Text & Select Fields
        if (isset($_POST['mhbo_checkin_time'])) {
            update_option('mhbo_checkin_time', sanitize_text_field(wp_unslash($_POST['mhbo_checkin_time'])));
        }
        if (isset($_POST['mhbo_checkout_time'])) {
            update_option('mhbo_checkout_time', sanitize_text_field(wp_unslash($_POST['mhbo_checkout_time'])));
        }
        if (isset($_POST['mhbo_booking_page'])) {
            update_option('mhbo_booking_page', absint(wp_unslash($_POST['mhbo_booking_page'])));
        }
        if (isset($_POST['mhbo_notification_email'])) {
            update_option('mhbo_notification_email', sanitize_email(wp_unslash($_POST['mhbo_notification_email'])));
        }
        if (isset($_POST['mhbo_booking_page_url'])) {
            update_option('mhbo_booking_page_url', esc_url_raw(wp_unslash($_POST['mhbo_booking_page_url'])));
        }

        // Boolean Fields: Robust 1/0 conversion for 2026 standards
        $bool_fields = [
            'mhbo_prevent_same_day_turnover',
            'mhbo_children_enabled',
            'mhbo_powered_by_link',
            'mhbo_save_data_on_uninstall'
        ];

        foreach ($bool_fields as $field) {
            $val = (isset($_POST[$field]) && '1' === sanitize_text_field(wp_unslash($_POST[$field]))) ? 1 : 0;
            update_option($field, $val);
        }

        // Custom Fields
        if (isset($_POST['mhbo_custom_fields']) && is_array($_POST['mhbo_custom_fields'])) {
            $custom_fields = [];
            $fields_data = map_deep(wp_unslash($_POST['mhbo_custom_fields']), 'sanitize_text_field');
            foreach ($fields_data as $field) {
                if (!empty($field['id']) && isset($field['label'], $field['type'])) {
                    $custom_fields[] = [
                        'id' => sanitize_key($field['id']),
                        'label' => is_array($field['label']) ? array_map('sanitize_text_field', $field['label']) : sanitize_text_field($field['label']),
                        'type' => sanitize_text_field($field['type']),
                        'required' => isset($field['required']) ? 1 : 0
                    ];
                }
            }
            update_option('mhbo_custom_fields', $custom_fields);
        } else {
            update_option('mhbo_custom_fields', []);
        }

        // Currency with Validation
        if (isset($_POST['mhbo_currency_code'])) {
            $code = sanitize_text_field(wp_unslash($_POST['mhbo_currency_code']));
            if (I18n::is_valid_currency($code)) {
                update_option('mhbo_currency_code', strtoupper($code));
            } else {
                add_settings_error('mhbo_settings', 'invalid_currency', __('Invalid currency code.', 'modern-hotel-booking'));
            }
        }

        if (isset($_POST['mhbo_currency_symbol'])) {
            update_option('mhbo_currency_symbol', sanitize_text_field(wp_unslash($_POST['mhbo_currency_symbol'])));
        }
        if (isset($_POST['mhbo_currency_position'])) {
            update_option('mhbo_currency_position', sanitize_text_field(wp_unslash($_POST['mhbo_currency_position'])));
        }

        add_settings_error('mhbo_settings', 'saved', __('General settings saved successfully.', 'modern-hotel-booking'), 'success');
    }

    public function save_themes_settings(): void
    {
        if (!isset($_POST['mhbo_save_tab']) || 'themes' !== sanitize_key(wp_unslash($_POST['mhbo_save_tab']))) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'modern-hotel-booking'));
        }

        if (!check_admin_referer('mhbo_settings_nonce', 'mhbo_nonce')) {
            wp_die('Security check failed');
        }

        // Sanitize and save fields directly from $_POST rather than passing the whole array
        if (isset($_POST['mhbo_active_theme'])) { // sanitize_text_field applied or checked via nonce later
            update_option('mhbo_active_theme', sanitize_key(wp_unslash($_POST['mhbo_active_theme'])));
        }

add_settings_error('mhbo_settings', 'saved', __('Theme settings saved successfully.', 'modern-hotel-booking'), 'success');
    }
    
    public static function render_pro_page()
    {

$license_key = '';
        $is_active = false;

}

    /**
     * Render Pro Upsell notice for unlicensed users trying to access Pro tabs.
     */
    public static function render_pro_upsell()
    {
        ?>
            <div class="mhbo-pro-upsell"
                style="margin-top: 20px; padding: 40px; text-align: center; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 12px; border: 1px solid #dee2e6;">
                <div style="font-size: 48px; margin-bottom: 20px;">🔒</div>
                <h3 style="margin: 0 0 10px 0; font-size: 1.4rem; color: #1a3b5d;">
                    <?php esc_html_e('Pro Feature', 'modern-hotel-booking'); ?>
                </h3>
                <p style="color: #6c757d; max-width: 400px; margin: 0 auto 20px auto; font-size: 14px;">
                    <?php esc_html_e('Unlock this feature and many more with a Pro license. Get access to payment processing, VAT/TAX management, analytics, and more.', 'modern-hotel-booking'); ?>
                </p>
                <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                    <a href="<?php echo esc_url('https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/'); ?>"
                        target="_blank" rel="noopener noreferrer"
                        class="button button-primary button-large"><?php esc_html_e('Upgrade to Pro', 'modern-hotel-booking'); ?></a>                </div>
                <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #dee2e6;">
                    <span
                        style="display: inline-block; font-size: 12px; color: #856404; background: rgba(255,255,255,0.8); padding: 4px 12px; border-radius: 12px; margin: 0 5px;">✓
                        <?php esc_html_e('Priority Support', 'modern-hotel-booking'); ?></span>
                    <span
                        style="display: inline-block; font-size: 12px; color: #856404; background: rgba(255,255,255,0.8); padding: 4px 12px; border-radius: 12px; margin: 0 5px;">✓
                        <?php esc_html_e('Priority Updates', 'modern-hotel-booking'); ?></span>
                    <span
                        style="display: inline-block; font-size: 12px; color: #856404; background: rgba(255,255,255,0.8); padding: 4px 12px; border-radius: 12px; margin: 0 5px;">✓
                        <?php esc_html_e('All Premium Features', 'modern-hotel-booking'); ?></span>
                </div>
            </div>
            <?php
    }

    private static function render_payments_tab()
    {
        
    }

    private static function render_api_tab()
    {
        
    }

    private static function render_pricing_tab()
    {
        
    }

    /**
     * Render Pro Themes settings tab.
     */
    private static function render_themes_tab()
    {
        $active_theme = get_option('mhbo_active_theme', 'midnight');
        $custom_primary = get_option('mhbo_custom_primary_color', '#1a365d');
        $custom_secondary = get_option('mhbo_custom_secondary_color', '#f2e2c4');
        $custom_accent = get_option('mhbo_custom_accent_color', '#d4af37');

$themes = [
            'midnight' => [
                'name' => __('Midnight Coast', 'modern-hotel-booking'),
                'colors' => ['#1a365d', '#f2e2c4', '#d4af37'],
                'desc' => __('Our signature classic luxury look.', 'modern-hotel-booking')
            ],
            'emerald' => [
                'name' => __('Emerald Forest', 'modern-hotel-booking'),
                'colors' => ['#064e3b', '#34d399', '#10b981'],
                'desc' => __('Rich greens for nature-inspired properties.', 'modern-hotel-booking')
            ],
            'oceanic' => [
                'name' => __('Oceanic Drift', 'modern-hotel-booking'),
                'colors' => ['#1e3a8a', '#60a5fa', '#3b82f6'],
                'desc' => __('Deep blues and bright highlights.', 'modern-hotel-booking')
            ],
            'ruby' => [
                'name' => __('Ruby Sunset', 'modern-hotel-booking'),
                'colors' => ['#7f1d1d', '#f87171', '#ef4444'],
                'desc' => __('Warm tones for cozy boutiques.', 'modern-hotel-booking')
            ],
            'urban' => [
                'name' => __('Urban Modern', 'modern-hotel-booking'),
                'colors' => ['#1f2937', '#9ca3af', '#4b5563'],
                'desc' => __('Minimalist grays for city lofts.', 'modern-hotel-booking')
            ],
            'lavender' => [
                'name' => __('Lavender Breeze', 'modern-hotel-booking'),
                'colors' => ['#4c1d95', '#a78bfa', '#8b5cf6'],
                'desc' => __('Elegant purples for spa and wellness.', 'modern-hotel-booking')
            ],
        ];
        ?>
            <div class="mhbo-settings-section">
                <h3 style="margin-top:0;"><?php esc_html_e('Theme Presets', 'modern-hotel-booking'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Choose a professional color palette for your booking frontend.', 'modern-hotel-booking'); ?>
                </p>

                <div
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin: 25px 0;">
                    <?php foreach ($themes as $slug => $theme): ?>
                        <label class="mhbo-theme-card <?php echo esc_attr($active_theme === $slug ? 'active' : ''); ?>"
                            style="cursor:pointer; border: 2px solid #ddd; border-radius: 12px; padding: 15px; display: block; background: #fff; transition: all 0.2s;">
                            <input type="radio" name="mhbo_active_theme" value="<?php echo esc_attr($slug); ?>" <?php checked($active_theme, $slug); ?> style="display:none;">
                            <div
                                style="display: flex; gap: 5px; height: 40px; border-radius: 6px; overflow: hidden; margin-bottom: 12px;">
                                <div style="flex: 2; background: <?php echo esc_attr($theme['colors'][0]); ?>;"></div>
                                <div style="flex: 1; background: <?php echo esc_attr($theme['colors'][1]); ?>;"></div>
                                <div style="flex: 1; background: <?php echo esc_attr($theme['colors'][2]); ?>;"></div>
                            </div>
                            <h4 style="margin: 0 0 5px 0;"><?php echo esc_html($theme['name']); ?></h4>
                            <p style="margin: 0; font-size: 13px; color: #646970;"><?php echo esc_html($theme['desc']); ?></p>
                        </label>
                    <?php endforeach; ?>

</div>

<div style="margin-top: 30px; display: flex; gap: 15px; align-items: center;">
                    <?php $reset_nonce = wp_create_nonce('mhbo_reset_theme'); ?>
                    <button type="button" class="button"
                        onclick="if(confirm('<?php esc_attr_e('Reset all theme settings to default?', 'modern-hotel-booking'); ?>')) { window.location.href = window.location.href + '&reset_theme=1&_wpnonce=<?php echo esc_attr($reset_nonce); ?>'; }">
                        <?php esc_html_e('Return to Default', 'modern-hotel-booking'); ?>
                    </button>
                    <?php // Note: Theme selection JavaScript logic has been moved to assets/js/mhbo-admin-settings.js ?>
                </div>
            </div>
            <?php
    }

    public function save_api_settings(): void
    {
        
    }

    public function save_payments_settings(): void
    {
        
    }

    public function save_pricing_settings(): void
    {
        
    }

private static function render_amenities_tab()
    {
        $amenities = get_option('mhbo_amenities_list');
        if (false === $amenities) { // If option doesn't exist, initialize with defaults
            $amenities = [
                'wifi'      => __('Free WiFi', 'modern-hotel-booking'),
                'ac'        => __('Air Conditioning', 'modern-hotel-booking'),
                'tv'        => __('Smart TV', 'modern-hotel-booking'),
                'breakfast' => __('Breakfast Included', 'modern-hotel-booking'),
                'pool'      => __('Pool View', 'modern-hotel-booking')
            ];
        }
        $amenities = is_array($amenities) ? $amenities : []; // Ensure it's always an array
        ?>
            <h3><?php esc_html_e('Room Amenities', 'modern-hotel-booking'); ?></h3>
            <p><?php esc_html_e('Manage the list of amenities available for rooms. After adding here, you can translate them in the "Frontend Labels" tab.', 'modern-hotel-booking'); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Add New Amenity', 'modern-hotel-booking'); ?></th>
                    <td>
                        <div style="display:flex; gap:10px;">
                            <input type="text" name="mhbo_new_amenity" placeholder="e.g. Hot Tub" class="regular-text">
                            <button type="submit" name="mhbo_add_amenity" value="1"
                                class="button button-primary"><?php esc_html_e('Add', 'modern-hotel-booking'); ?></button>
                        </div>
                    </td>
                </tr>
            </table>

            <div style="margin-top:20px; max-width:600px;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Label', 'modern-hotel-booking'); ?></th>
                            <th><?php esc_html_e('Key (Internal)', 'modern-hotel-booking'); ?></th>
                            <th style="width:100px;"><?php esc_html_e('Action', 'modern-hotel-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($amenities)): ?>
                            <tr>
                                <td colspan="3"><?php esc_html_e('No amenities found.', 'modern-hotel-booking'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($amenities as $key => $label): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($label); ?></strong></td>
                                    <td><code><?php echo esc_html($key); ?></code></td>
                                    <td>
                                        <button type="submit" name="mhbo_remove_amenity" value="<?php echo esc_attr($key); ?>"
                                            class="button button-link-delete"
                                            onclick="return confirm('<?php esc_attr_e('Are you sure?', 'modern-hotel-booking'); ?>');"><?php esc_html_e('Delete', 'modern-hotel-booking'); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
    }

public function save_amenities_settings(): void
    {
        if (!isset($_POST['mhbo_save_tab']) || 'amenities' !== sanitize_key(wp_unslash($_POST['mhbo_save_tab']))) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'modern-hotel-booking'));
        }

        if (!check_admin_referer('mhbo_settings_nonce', 'mhbo_nonce')) {
            wp_die('Security check failed');
        }

        $amenities = get_option('mhbo_amenities_list');
        if (false === $amenities) { // If option doesn't exist, initialize with defaults
            $amenities = [
                'wifi'      => __('Free WiFi', 'modern-hotel-booking'),
                'ac'        => __('Air Conditioning', 'modern-hotel-booking'),
                'tv'        => __('Smart TV', 'modern-hotel-booking'),
                'breakfast' => __('Breakfast Included', 'modern-hotel-booking'),
                'pool'      => __('Pool View', 'modern-hotel-booking')
            ];
        }
        $amenities = is_array($amenities) ? $amenities : []; // Ensure it's always an array

        // Add Amenity
        if (isset($_POST['mhbo_add_amenity']) && !empty($_POST['mhbo_new_amenity'])) { // sanitize_text_field applied or checked via nonce later
            $label = sanitize_text_field(wp_unslash($_POST['mhbo_new_amenity']));
            $key = sanitize_title($label);
            if ($key && !isset($amenities[$key])) {
                $amenities[$key] = $label;
                update_option('mhbo_amenities_list', $amenities);
                add_settings_error('mhbo_amenities', 'added', __('Amenity added successfully.', 'modern-hotel-booking'), 'success');
            }
        }

        // Remove Amenity
        if (isset($_POST['mhbo_remove_amenity'])) { // sanitize_text_field applied or checked via nonce later
            $key = sanitize_text_field(wp_unslash($_POST['mhbo_remove_amenity']));
            if (isset($amenities[$key])) {
                unset($amenities[$key]);
                update_option('mhbo_amenities_list', $amenities);
                add_settings_error('mhbo_amenities', 'removed', __('Amenity removed successfully.', 'modern-hotel-booking'), 'success');
            }
        }
    }

    private static function render_tax_tab()
    {
        
    }

    public function save_license_settings(): void
    {
        
    }

    /**
     * Save Tax Settings
     */
    public function save_tax_settings(): void
    {
        if (!isset($_POST['mhbo_save_tab']) || 'tax' !== sanitize_key(wp_unslash($_POST['mhbo_save_tab']))) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'modern-hotel-booking'));
        }

        if (!check_admin_referer('mhbo_settings_nonce', 'mhbo_nonce')) {
            wp_die('Security check failed');
        }

        // Whitelist of valid tax modes
        if (isset($_POST['mhbo_tax_mode'])) { // sanitize_text_field applied or checked via nonce later
            $allowed_modes = ['disabled', 'vat', 'sales_tax'];
            $mode = sanitize_text_field(wp_unslash($_POST['mhbo_tax_mode']));
            if (in_array($mode, $allowed_modes, true)) {
                update_option('mhbo_tax_mode', $mode);
            }
        }

        // Tax Label (Multilingual)
        if (isset($_POST['mhbo_tax_label_lang']) && is_array($_POST['mhbo_tax_label_lang'])) { // sanitize_text_field applied or checked via nonce later
            $label_data = array_map('sanitize_text_field', wp_unslash($_POST['mhbo_tax_label_lang']));
            update_option('mhbo_tax_label', I18n::encode($label_data));
        }

        // Tax Registration Number
        if (isset($_POST['mhbo_tax_registration_number'])) { // sanitize_text_field applied or checked via nonce later
            update_option('mhbo_tax_registration_number', sanitize_text_field(wp_unslash($_POST['mhbo_tax_registration_number'])));
        }

        // Tax Rates (with server-side range validation 0-100)
        if (isset($_POST['mhbo_tax_rate_accommodation'])) { // sanitize_text_field applied or checked via nonce later
            $rate = max(0, min(100, floatval($_POST['mhbo_tax_rate_accommodation']))); // sanitize_text_field applied or checked via nonce later
            update_option('mhbo_tax_rate_accommodation', $rate);
        }
        if (isset($_POST['mhbo_tax_rate_extras'])) { // sanitize_text_field applied or checked via nonce later
            $rate = max(0, min(100, floatval($_POST['mhbo_tax_rate_extras']))); // sanitize_text_field applied or checked via nonce later
            update_option('mhbo_tax_rate_extras', $rate);
        }

        // Display Options
        update_option('mhbo_tax_display_frontend', isset($_POST['mhbo_tax_display_frontend']) ? 1 : 0); // sanitize_text_field applied or checked via nonce later
        update_option('mhbo_tax_display_email', isset($_POST['mhbo_tax_display_email']) ? 1 : 0); // sanitize_text_field applied or checked via nonce later

        // Advanced Settings
        if (isset($_POST['mhbo_tax_rounding_mode'])) { // sanitize_text_field applied or checked via nonce later
            $allowed_rounding = ['per_total', 'per_line'];
            $rounding = sanitize_text_field(wp_unslash($_POST['mhbo_tax_rounding_mode']));
            if (in_array($rounding, $allowed_rounding, true)) {
                update_option('mhbo_tax_rounding_mode', $rounding);
            }
        }
        if (isset($_POST['mhbo_tax_decimal_places'])) { // sanitize_text_field applied or checked via nonce later
            update_option('mhbo_tax_decimal_places', absint($_POST['mhbo_tax_decimal_places']));
        }

        add_settings_error('mhbo_settings', 'saved', __('Tax settings saved successfully.', 'modern-hotel-booking'), 'success');
}

    public function save_performance_settings(): void
    {
        
    }

    /**
     * AJAX handler for clearing cache.
     */
    public function ajax_clear_cache(): void
    {
        check_ajax_referer('mhbo_clear_cache_nonce_field', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'modern-hotel-booking')]);
        }

        if (class_exists('MHBO\Core\Cache')) {
            $result = \MHBO\Core\Cache::flush();
            if ($result) {
                wp_send_json_success(['message' => __('Cache cleared successfully.', 'modern-hotel-booking')]);
            } else {
                wp_send_json_error(['message' => __('Failed to clear cache.', 'modern-hotel-booking')]);
            }
        } else {
            wp_send_json_error(['message' => __('Cache class not available.', 'modern-hotel-booking')]);
        }
    }
}
