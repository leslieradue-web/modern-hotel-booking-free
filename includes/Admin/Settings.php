<?php declare(strict_types=1);

namespace MHBO\Admin;

use MHBO\Core\I18n;

if (!defined('ABSPATH')) {
    exit;
}

class Settings
{
    public function init()
    {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'save_general_settings'));
        add_action('admin_init', array($this, 'save_multilingual_settings'));
        add_action('admin_init', array($this, 'save_api_settings'));
        add_action('admin_init', array($this, 'save_themes_settings'));
        add_action('admin_init', array($this, 'save_pricing_settings'));
        add_action('admin_init', array($this, 'save_amenities_settings'));
        add_action('admin_init', array($this, 'save_gdpr_settings'));
        add_action('admin_init', array($this, 'save_payments_settings'));
        add_action('admin_init', array($this, 'save_tax_settings'));
        add_action('admin_init', array($this, 'save_performance_settings'));
        add_action('admin_init', array($this, 'register_wpml_polylang_strings'));        add_action('wp_ajax_mhbo_clear_cache', array($this, 'ajax_clear_cache'));
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
            ),
            'i18n' => array(                'connection_error' => __('Connection error.', 'modern-hotel-booking'),
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
    public function register_wpml_polylang_strings()
    {
        I18n::register_plugin_strings();
    }

    public function register_settings()
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

        // Multilingual settings handled manually in save_multilingual_settings
        // Custom Fields
        register_setting('mhbo_settings_group', 'mhbo_custom_fields', array('default' => [], 'sanitize_callback' => array($this, 'sanitize_custom_fields')));
        register_setting('mhbo_settings_group', 'mhbo_terms_page', array('default' => 0, 'sanitize_callback' => 'absint'));

        // GDPR Settings (Pro)
        register_setting('mhbo_settings_group', 'mhbo_gdpr_enabled', array('default' => 0, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_gdpr_checkbox_enabled', array('default' => 0, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_label_gdpr_checkbox_text', array('default' => '[:en]I accept the privacy policy.[:]', 'sanitize_callback' => 'wp_kses_post'));
        register_setting('mhbo_settings_group', 'mhbo_gdpr_retention_days', array('default' => 365, 'sanitize_callback' => 'absint'));
        register_setting('mhbo_settings_group', 'mhbo_gdpr_cookie_prefix', array('default' => 'mhbo_', 'sanitize_callback' => 'sanitize_text_field'));

        // Uninstall Settings
        register_setting('mhbo_settings_group', 'mhbo_save_data_on_uninstall', array('default' => 1, 'sanitize_callback' => 'absint'));

        // Display Settings
        register_setting('mhbo_settings_group', 'mhbo_powered_by_link', array('default' => 0, 'sanitize_callback' => 'absint'));
        // Amenities (Dynamic) - Handled manually
        // register_setting('mhbo_settings_group', 'mhbo_amenities_list'); 

        add_settings_section('mhbo_general_section', __('General Settings', 'modern-hotel-booking'), '__return_null', 'mhbo-settings');
        add_settings_field('mhbo_checkin_time', __('Default Check-in Time', 'modern-hotel-booking'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_checkin_time'));
        add_settings_field('mhbo_checkout_time', __('Default Check-out Time', 'modern-hotel-booking'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_checkout_time'));
        add_settings_field('mhbo_notification_email', __('Notification Email', 'modern-hotel-booking'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_notification_email'));
        add_settings_field('mhbo_booking_page', __('Booking Page', 'modern-hotel-booking'), array($this, 'render_page_select_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_booking_page'));
        add_settings_field('mhbo_booking_page_url', __('Booking Page URL (Override)', 'modern-hotel-booking'), array($this, 'render_text_field'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_booking_page_url'));
        add_settings_field('mhbo_prevent_same_day_turnover', __('Same-day Turnover', 'modern-hotel-booking'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for' => 'mhbo_prevent_same_day_turnover',
            'description' => __('If enabled, rooms cannot be checked-in on the same day someone else checks out.', 'modern-hotel-booking')
        ));
        add_settings_field('mhbo_children_enabled', __('Enable Children Management', 'modern-hotel-booking'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for' => 'mhbo_children_enabled',
            'description' => __('Show children selector in search and booking forms.', 'modern-hotel-booking')
        ));
        add_settings_field('mhbo_custom_fields', __('Custom Guest Fields', 'modern-hotel-booking'), array($this, 'render_custom_fields_repeater'), 'mhbo-settings', 'mhbo_general_section', array('label_for' => 'mhbo_custom_fields'));
        add_settings_field('mhbo_save_data_on_uninstall', __('Save Data on Uninstall', 'modern-hotel-booking'), array($this, 'render_checkbox_field'), 'mhbo-settings', 'mhbo_general_section', array(
            'label_for' => 'mhbo_save_data_on_uninstall',
            'description' => __('If enabled, your data and database tables will be preserved when uninstalling the plugin. This is important for free to pro upgrades.', 'modern-hotel-booking')
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

    public function sanitize_custom_fields($fields)
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
                $sanitized_field['label'] = isset($field['label']) ? sanitize_text_field($field['label']) : '';
            }
            $sanitized[] = $sanitized_field;
        }
        return $sanitized;
    }

    public function render_text_field($args)
    {
        $option = get_option($args['label_for']);
        $value = I18n::decode($option);
        echo '<input type="text" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function render_select_field($args)
    {
        $option = get_option($args['label_for']);
        $option = I18n::decode($option);
        echo '<select id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '">';
        foreach ($args['options'] as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($option, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function render_page_select_field($args)
    {
        $option = get_option($args['label_for']);
        $option = I18n::decode($option);
        wp_dropdown_pages(array(
            'name' => esc_attr($args['label_for']),
            'selected' => absint($option),
            'show_option_none' => esc_html__('— Select a Page —', 'modern-hotel-booking'),
            'class' => 'regular-text'
        ));
        echo '<p class="description">' . esc_html__('Select the page where you have placed the [modern_hotel_booking] shortcode.', 'modern-hotel-booking') . '</p>';
    }

    public function render_checkbox_field($args)
    {
        $option = get_option($args['label_for']);
        $option = I18n::decode($option);
        echo '<input type="checkbox" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="1" ' . checked(1, $option, false) . '>';
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function render_custom_fields_repeater($args)
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
                                    <input type="checkbox" name="mhbo_custom_fields[<?php echo esc_attr($index); ?>][required]" value="1"
                                        <?php checked(isset($field['required']) && $field['required']); ?>>
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

    public static function render()
    {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_pro = false; // Free version
        ?>
        <div class="wrap mhbo-admin-wrap">
            <h1 style="margin-bottom: 25px; font-weight: 800; color: #1a3b5d;">
                <?php esc_html_e('Hotel Configuration', 'modern-hotel-booking'); ?>
            </h1>
            <h2 class="nav-tab-wrapper">                <a href="?page=mhb-settings&tab=general"
                    class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('General', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhb-settings&tab=emails"
                    class="nav-tab <?php echo 'emails' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Email Templates', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhb-settings&tab=labels"
                    class="nav-tab <?php echo 'labels' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Frontend Labels', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhb-settings&tab=amenities"
                    class="nav-tab <?php echo 'amenities' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Amenities', 'modern-hotel-booking'); ?></a>

                
                
                <a href="?page=mhb-settings&tab=performance"
                    class="nav-tab <?php echo 'performance' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Performance', 'modern-hotel-booking'); ?></a>
            </h2>

            <div class="mhbo-card" style="margin-top: 20px;">
                <?php
                $manual_tabs = ['emails', 'labels', 'amenities', 'gdpr', 'tax', 'pricing', 'themes', 'performance', 'general'];
                $action = in_array($active_tab, $manual_tabs, true) ? '' : 'options.php';
                ?>
                <form method="post" action="<?php echo esc_attr($action); ?>">
                    <?php
                    // Don't use settings_fields for manual tabs to avoid WP trying to save to options.php
                    if (!in_array($active_tab, $manual_tabs, true)) {
                        settings_fields('mhbo_settings_group');
                    } else {
                        wp_nonce_field('mhbo_settings_group-options');
                    }

                    if ('general' === $active_tab) {
                        echo '<input type="hidden" name="mhbo_save_tab" value="general">';
                        do_settings_sections('mhbo-settings');
                    } elseif ('emails' === $active_tab) {
                        self::render_email_templates_tab();
                    } elseif ('labels' === $active_tab) {
                        self::render_labels_tab();
                    } elseif ('amenities' === $active_tab) {
                        self::render_amenities_tab();
                    }     elseif ('performance' === $active_tab) {
                        self::render_performance_tab();
                    }

                    // Only show save button if not on a locked Pro tab
                    $locked_tabs = [];
                    if ($is_pro || !in_array($active_tab, $locked_tabs, true)) {
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

        // Add nonce for cache clear action
        wp_nonce_field('mhbo_clear_cache_nonce', 'mhbo_clear_cache_nonce_field');

        // Add inline script for cache clear button
        echo '<script>
        jQuery(document).ready(function($) {
            $("#mhbo_clear_cache").on("click", function() {
                var $btn = $(this);
                var $spinner = $("#mhbo_cache_spinner");
                $btn.prop("disabled", true);
                $spinner.addClass("is-active");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "mhbo_clear_cache",
                        nonce: $("#mhbo_clear_cache_nonce_field").val()
                    },
                    success: function(response) {
                        if (response.success) {
                            $btn.after(\'<span style="color: green; margin-left: 10px;">\' + response.data.message + \'</span>\');
                        } else {
                            $btn.after(\'<span style="color: red; margin-left: 10px;">\' + response.data.message + \'</span>\');
                        }
                    },
                    complete: function() {
                        $spinner.removeClass("is-active");
                        $btn.prop("disabled", false);
                    }
                });
            });
        });
        </script>';
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
        $amenities = get_option('mhbo_amenities_list', [
            'wifi' => __('Free WiFi', 'modern-hotel-booking'),
            'ac' => __('Air Conditioning', 'modern-hotel-booking'),
            'tv' => __('Smart TV', 'modern-hotel-booking'),
            'breakfast' => __('Breakfast Included', 'modern-hotel-booking'),
            'pool' => __('Pool View', 'modern-hotel-booking')
        ]);
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
    public function save_multilingual_settings()
    {
        if (!isset($_POST['mhbo_save_tab']) || !in_array(sanitize_key(wp_unslash($_POST['mhbo_save_tab'])), ['emails', 'labels', 'gdpr'], true)) {
            return;
        }

        if (!check_admin_referer('mhbo_settings_group-options')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // Save Emails
        if (isset($_POST['mhbo_email_templates']) && is_array($_POST['mhbo_email_templates'])) {
            $allowed_email_statuses = ['pending', 'confirmed', 'cancelled', 'payment'];
            $email_templates = wp_unslash($_POST['mhbo_email_templates']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization performed per-field below
            foreach ($email_templates as $status => $data) {
                $status = sanitize_key($status);
                if (!in_array($status, $allowed_email_statuses, true)) {
                    continue;
                }
                if (isset($data['subject'])) {
                    $subject_data = is_array($data['subject'])
                        ? array_map('sanitize_text_field', $data['subject'])
                        : sanitize_text_field($data['subject']);
                    update_option("mhbo_email_{$status}_subject", I18n::encode($subject_data));
                }
                if (isset($data['message'])) {
                    $message_data = is_array($data['message'])
                        ? array_map('wp_kses_post', $data['message'])
                        : wp_kses_post($data['message']);
                    update_option("mhbo_email_{$status}_message", I18n::encode($message_data));
                }
            }
        }

        // Save Labels
        if (isset($_POST['mhbo_label_templates']) && is_array($_POST['mhbo_label_templates'])) {
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
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash applied, sanitization performed per-field below
            $label_templates = wp_unslash($_POST['mhbo_label_templates']);
            foreach ($label_templates as $key => $data) {
                $key = sanitize_key($key);
                if (!in_array($key, $allowed_label_keys, true)) {
                    continue;
                }
                $label_data = is_array($data)
                    ? array_map('sanitize_text_field', $data)
                    : sanitize_text_field($data);
                update_option("mhbo_label_{$key}", I18n::encode($label_data));
            }
        }

        add_settings_error('mhbo_settings', 'saved', __('Multilingual settings saved successfully.', 'modern-hotel-booking'), 'success');
    }

    public function save_gdpr_settings()
    {
        if (!isset($_POST['mhbo_save_tab']) || 'gdpr' !== sanitize_key(wp_unslash($_POST['mhbo_save_tab']))) {
            return;
        }

        if (!check_admin_referer('mhbo_settings_group-options')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        update_option('mhbo_gdpr_enabled', isset($_POST['mhbo_gdpr_enabled']) ? 1 : 0);
        update_option('mhbo_gdpr_checkbox_enabled', isset($_POST['mhbo_gdpr_checkbox_enabled']) ? 1 : 0);

        if (isset($_POST['mhbo_label_templates']['gdpr_checkbox_text']) && is_array($_POST['mhbo_label_templates'])) {
            $consent_data = wp_unslash($_POST['mhbo_label_templates']['gdpr_checkbox_text']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization performed below
            if (is_array($consent_data)) {
                $consent_data = array_map('sanitize_text_field', $consent_data);
            } else {
                $consent_data = sanitize_text_field($consent_data);
            }
            update_option('mhbo_label_gdpr_checkbox_text', I18n::encode($consent_data));
        }

        if (isset($_POST['mhbo_gdpr_cookie_prefix'])) {
            update_option('mhbo_gdpr_cookie_prefix', sanitize_key(wp_unslash($_POST['mhbo_gdpr_cookie_prefix'])));
        }

        if (isset($_POST['mhbo_gdpr_retention_days'])) {
            update_option('mhbo_gdpr_retention_days', absint($_POST['mhbo_gdpr_retention_days']));
        }

        if (isset($_POST['mhbo_terms_page'])) {
            update_option('mhbo_terms_page', absint($_POST['mhbo_terms_page']));
        }

        add_settings_error('mhbo_settings', 'saved', __('GDPR settings saved successfully.', 'modern-hotel-booking'), 'success');
    }

    public function save_general_settings()
    {
        if (!isset($_POST['mhbo_save_tab']) || 'general' !== sanitize_key(wp_unslash($_POST['mhbo_save_tab']))) {
            return;
        }

        // Check nonce
        if (!check_admin_referer('mhbo_settings_group-options')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // General
        if (isset($_POST['mhbo_checkin_time']))
            update_option('mhbo_checkin_time', sanitize_text_field(wp_unslash($_POST['mhbo_checkin_time'])));
        if (isset($_POST['mhbo_checkout_time']))
            update_option('mhbo_checkout_time', sanitize_text_field(wp_unslash($_POST['mhbo_checkout_time'])));
        if (isset($_POST['mhbo_notification_email']))
            update_option('mhbo_notification_email', sanitize_email(wp_unslash($_POST['mhbo_notification_email'])));
        if (isset($_POST['mhbo_booking_page']))
            update_option('mhbo_booking_page', absint($_POST['mhbo_booking_page']));
        if (isset($_POST['mhbo_booking_page_url']))
            update_option('mhbo_booking_page_url', esc_url_raw(wp_unslash($_POST['mhbo_booking_page_url'])));

        // Boolean: Same-day turnover
        $turnover = isset($_POST['mhbo_prevent_same_day_turnover']) ? 1 : 0;
        update_option('mhbo_prevent_same_day_turnover', $turnover);

        // Boolean: Children enabled
        $children_enabled = isset($_POST['mhbo_children_enabled']) ? 1 : 0;
        update_option('mhbo_children_enabled', $children_enabled);

        // Boolean: Powered by link
        $powered_by = isset($_POST['mhbo_powered_by_link']) ? 1 : 0;
        update_option('mhbo_powered_by_link', $powered_by);

        // Custom Fields
        if (isset($_POST['mhbo_custom_fields']) && is_array($_POST['mhbo_custom_fields'])) {
            $custom_fields = [];
            $fields_data = wp_unslash($_POST['mhbo_custom_fields']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization performed per-field below
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

        // Currency
        if (isset($_POST['mhbo_currency_code'])) {
            $code = sanitize_text_field(wp_unslash($_POST['mhbo_currency_code']));
            if (I18n::is_valid_currency($code)) {
                update_option('mhbo_currency_code', strtoupper($code));
            } else {
                add_settings_error('mhbo_settings', 'invalid_currency', __('Invalid currency code. Please use a valid ISO-4217 3-letter code.', 'modern-hotel-booking'));
            }
        }

        if (isset($_POST['mhbo_currency_symbol']))
            update_option('mhbo_currency_symbol', sanitize_text_field(wp_unslash($_POST['mhbo_currency_symbol'])));
        if (isset($_POST['mhbo_currency_position']))
            update_option('mhbo_currency_position', sanitize_text_field(wp_unslash($_POST['mhbo_currency_position'])));

        // Boolean: Save data on uninstall (important for free-to-pro upgrades)
        $save_data_on_uninstall = isset($_POST['mhbo_save_data_on_uninstall']) ? 1 : 0;
        update_option('mhbo_save_data_on_uninstall', $save_data_on_uninstall);

        add_settings_error('mhbo_settings', 'saved', __('General settings saved successfully.', 'modern-hotel-booking'), 'success');
    }

    public function save_themes_settings()
    {
        if (!isset($_POST['mhbo_save_tab']) || 'themes' !== sanitize_key(wp_unslash($_POST['mhbo_save_tab']))) {
            return;
        }

        if (!check_admin_referer('mhbo_settings_group-options')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        self::save_theme_options_from_post($_POST);

        add_settings_error('mhbo_settings', 'saved', __('Theme settings saved successfully.', 'modern-hotel-booking'), 'success');
    }

    /**
     * Shared theme option saving logic for Settings and Pro Themes pages.
     *
     * @param array $source Raw $_POST-like array.
     */
    private static function save_theme_options_from_post(array $source): void
    {
        if (isset($source['mhbo_active_theme'])) {
            update_option('mhbo_active_theme', sanitize_key(wp_unslash($source['mhbo_active_theme'])));
        }
        if (isset($source['mhbo_custom_primary_color'])) {
            update_option('mhbo_custom_primary_color', sanitize_hex_color(wp_unslash($source['mhbo_custom_primary_color'])));
        }
        if (isset($source['mhbo_custom_secondary_color'])) {
            update_option('mhbo_custom_secondary_color', sanitize_hex_color(wp_unslash($source['mhbo_custom_secondary_color'])));
        }
        if (isset($source['mhbo_custom_accent_color'])) {
            update_option('mhbo_custom_accent_color', sanitize_hex_color(wp_unslash($source['mhbo_custom_accent_color'])));
        }
        if (isset($source['mhbo_custom_css'])) {
            update_option('mhbo_custom_css', wp_strip_all_tags(wp_unslash($source['mhbo_custom_css'])));
        }
    }

    public static function render_pro_page()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified on the next line via check_admin_referer()
        if (isset($_POST['mhbo_save_pro_settings'])) {
            check_admin_referer('mhbo_pro_settings');
            $key = ''; // License key removed for Free version
            $result = ['success' => false, 'message' => 'License system not available'];

            if ($result['success']) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($result['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result['message']) . '</p></div>';
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified on the next line via check_admin_referer()
        if (isset($_POST['mhbo_remove_license'])) {
            check_admin_referer('mhbo_pro_settings');
            $result = ['success' => false, 'message' => 'License system not available'];
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($result['message']) . '</p></div>';
        }

        if (isset($_GET['reset_theme']) && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'mhbo_reset_theme')) {
            if ($_GET['reset_theme'] === '1') {
                update_option('mhbo_active_theme', 'midnight');
                update_option('mhbo_custom_primary_color', '');
                update_option('mhbo_custom_secondary_color', '');
                update_option('mhbo_custom_accent_color', '');
                update_option('mhbo_custom_css', '');
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Theme reset to default successfully.', 'modern-hotel-booking') . '</p></div>';
            }
        }

                        $is_active = ('active' === 'inactive');
        $active_tab = isset($_GET['page']) && 'mhbo-pro' !== $_GET['page'] ? str_replace('mhbo-pro-', '', sanitize_key(wp_unslash($_GET['page']))) : 'overview';

        // Handle theme settings save from the Pro Themes page.
        if ('themes' === $active_tab && isset($_POST['mhbo_pro_themes_save'])) {
            if (check_admin_referer('mhbo_pro_themes_settings')) {
                if (current_user_can('manage_options')) {
                    self::save_theme_options_from_post($_POST);
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Theme settings saved successfully.', 'modern-hotel-booking') . '</p></div>';
                }
            }
        }

        ?>
        <div class="wrap mhbo-pro-wrap">
            <h1 style="margin-bottom: 25px; font-weight: 800; color: #1a3b5d;">
                <?php esc_html_e('Pro Experience', 'modern-hotel-booking'); ?>
            </h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=mhb-pro"
                    class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Overview', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhb-pro-extras"
                    class="nav-tab <?php echo 'extras' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Booking Extras', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhb-pro-ical"
                    class="nav-tab <?php echo 'ical' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('iCal Sync', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhb-pro-payments"
                    class="nav-tab <?php echo 'payments' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Payments', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhb-pro-webhooks"
                    class="nav-tab <?php echo 'webhooks' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Webhooks', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhb-pro-themes"
                    class="nav-tab <?php echo 'themes' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Pro Themes', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhb-pro-analytics"
                    class="nav-tab <?php echo 'analytics' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Analytics', 'modern-hotel-booking'); ?></a>
                <a href="?page=mhb-pro-pricing"
                    class="nav-tab <?php echo 'pricing' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Advanced Pricing', 'modern-hotel-booking'); ?></a>
            </h2>

            <div class="mhbo-pro-content-area" style="margin-top: 20px;">
                <?php if ('overview' === $active_tab): ?>
                    <?php
                    // License status for display
                                        ?>

                    <!-- License Status Banner -->
                    <div class="mhbo-license-banner <?php echo $is_active ? 'mhbo-license-active' : 'mhbo-license-inactive'; ?>">
                        <div class="mhbo-license-banner-content">
                            <div class="mhbo-license-icon">
                                <?php if ($is_active): ?>
                                    <span class="dashicons dashicons-yes-alt"></span>
                                <?php else: ?>
                                    <span class="dashicons dashicons-lock"></span>
                                <?php endif; ?>
                            </div>
                            <div class="mhbo-license-info">
                                <h3>
                                    <?php if ($is_active): ?>
                                        <?php esc_html_e('Pro License Active', 'modern-hotel-booking'); ?>
                                    <?php else: ?>
                                        <?php esc_html_e('Pro License Required', 'modern-hotel-booking'); ?>
                                    <?php endif; ?>
                                </h3>
                                <p>
                                    <?php if ($is_active): ?>
                                        <?php if (''): ?>
                                            <?php
                                            // translators: %s: license expiration date
                                            echo sprintf(esc_html__('Expires: %s', 'modern-hotel-booking'), esc_html(date_i18n(get_option('date_format'), strtotime('')))); ?>
                                        <?php else: ?>
                                            <?php esc_html_e('All Pro features are unlocked and ready to use.', 'modern-hotel-booking'); ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php esc_html_e('Unlock all premium features to maximize your booking potential.', 'modern-hotel-booking'); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="mhbo-license-action">
                                <?php if ($is_active): ?>                                <?php else: ?>
                                    <div style="display: flex; gap: 10px;">
                                        <a href="https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/"
                                            target="_blank" rel="noopener noreferrer" class="button button-primary">
                                            <?php esc_html_e('Upgrade to Pro', 'modern-hotel-booking'); ?>
                                        </a>                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$is_active): ?>
                            <div class="mhbo-license-benefits">
                                <span class="mhbo-benefit-item"><?php esc_html_e('Priority Support', 'modern-hotel-booking'); ?></span>
                                <span class="mhbo-benefit-item"><?php esc_html_e('Priority Updates', 'modern-hotel-booking'); ?></span>
                                <span
                                    class="mhbo-benefit-item"><?php esc_html_e('All Premium Features', 'modern-hotel-booking'); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php
                    // Dynamically fetch the latest changelog from readme.txt
                    $changelog_items = [];
                    $latest_version = MHBO_VERSION;
                    $readme_file = MHBO_PLUGIN_DIR . 'readme.txt';

                    if (file_exists($readme_file)) {
                        $readme_content = file_get_contents($readme_file);
                        if (preg_match('/==\s*Changelog\s*==(.*?)($|==)/s', $readme_content, $matches)) {
                            $changelog_section = $matches[1];
                            if (preg_match('/^\s*=\s*([0-9\.]+.*?)\s*=(.*?)(?:\n\s*=\s*[0-9]|$)/s', $changelog_section, $version_matches)) {
                                $latest_version = trim($version_matches[1]);
                                $version_notes = trim($version_matches[2]);
                                $lines = explode("\n", $version_notes);
                                foreach ($lines as $line) {
                                    $line = trim($line);
                                    if (strpos($line, '*') === 0 || strpos($line, '-') === 0) {
                                        $changelog_items[] = trim(substr($line, 1));
                                    }
                                }
                            }
                        }
                    }
                    ?>

                    <div class="mhbo-card" style="margin-top: 20px; border-left: 4px solid #10b981;">
                        <h3 style="color: #10b981; margin-top: 0; margin-bottom: 10px; font-size: 15px;">
                            <?php
                            // translators: %s: Plugin version number
                            echo esc_html(sprintf(__('Version %s Updates', 'modern-hotel-booking'), $latest_version));
                            ?>
                        </h3>
                        <?php if (!empty($changelog_items)): ?>
                            <ul style="margin-left: 20px; font-size: 12px; color: #646970;">
                                <?php foreach ($changelog_items as $item): ?>
                                    <li style="margin-bottom: 4px;"><?php
                                    $item_clean = trim($item);
                                    if (preg_match('/^([^:]+:)(.*)$/', $item_clean, $parts)) {
                                        echo '<strong>' . esc_html($parts[1]) . '</strong>' . esc_html($parts[2]);
                                    } else {
                                        echo esc_html($item_clean);
                                    }
                                    ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p style="font-size: 12px; color: #646970;">
                                <?php esc_html_e('Please check the plugin readme.txt for the latest updates.', 'modern-hotel-booking'); ?>
                            </p>
                        <?php endif; ?>

                        <div style="margin-top: 10px;">
                            <a href="https://wordpress.org/plugins/modern-hotel-booking/#developers" target="_blank"
                                rel="noopener noreferrer"
                                style="font-size: 12px; color: #10b981; text-decoration: none; font-weight: bold;">
                                <?php esc_html_e('View Full Changelog →', 'modern-hotel-booking'); ?>
                            </a>
                        </div>
                    </div>

                    <!-- Feature Categories -->
                    <div class="mhbo-feature-categories">

                        <!-- Payment Processing Category -->
                        <div class="mhbo-feature-category">
                            <div class="mhbo-category-header">
                                <h2><span class="mhbo-category-icon">💳</span>
                                    <?php esc_html_e('Payment Processing', 'modern-hotel-booking'); ?></h2>
                                <a href="?page=mhb-pro-payments" class="mhbo-configure-link">
                                    <?php esc_html_e('Configure', 'modern-hotel-booking'); ?> <span
                                        class="dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                            </div>
                            <div class="mhbo-feature-grid">
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">💳</span>
                                    <h4><?php esc_html_e('Stripe Integration', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Accept payments with Stripe including Apple Pay and Google Pay support.', 'modern-hotel-booking'); ?>
                                    </p>
                                    <span
                                        class="mhbo-badge mhbo-badge-popular"><?php esc_html_e('Popular', 'modern-hotel-booking'); ?></span>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🅿️</span>
                                    <h4><?php esc_html_e('PayPal Integration', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Accept PayPal payments with automatic IPN verification and status updates.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🏨</span>
                                    <h4><?php esc_html_e('Pay On-site Option', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Allow guests to pay upon arrival with flexible payment tracking.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🔄</span>
                                    <h4><?php esc_html_e('Payment Webhooks', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Automatic payment status updates from Stripe and PayPal webhooks.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">📊</span>
                                    <h4><?php esc_html_e('Payment Status Tracking', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Full payment lifecycle management: pending, deposit paid, fully paid, refunded.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Tax Management Category -->
                        <div class="mhbo-feature-category">
                            <div class="mhbo-category-header">
                                <h2><span class="mhbo-category-icon">🧾</span>
                                    <?php esc_html_e('Tax Management', 'modern-hotel-booking'); ?></h2>
                                
                            </div>
                            <div class="mhbo-feature-grid">
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🧾</span>
                                    <h4><?php esc_html_e('VAT/TAX System', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Three modes: VAT inclusive, Sales Tax exclusive, or Disabled. Flexible configuration.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">📈</span>
                                    <h4><?php esc_html_e('Separate Tax Rates', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Different tax rates for accommodation vs extras services.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🌍</span>
                                    <h4><?php esc_html_e('Country-Specific VAT', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Set different VAT rates per country for international guests.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">📝</span>
                                    <h4><?php esc_html_e('Tax Breakdown Display', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Detailed tax display by room, children, and extras in booking confirmations.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🌐</span>
                                    <h4><?php esc_html_e('Multilingual Tax Labels', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Per-language tax terminology (VAT, IVA, TVA, MwSt, etc.).', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Booking Management Category -->
                        <div class="mhbo-feature-category">
                            <div class="mhbo-category-header">
                                <h2><span class="mhbo-category-icon">📅</span>
                                    <?php esc_html_e('Booking Management', 'modern-hotel-booking'); ?></h2>
                            </div>
                            <div class="mhbo-feature-grid">
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🏷️</span>
                                    <h4><?php esc_html_e('Seasonal Pricing', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Set automated weekend and holiday multipliers for dynamic pricing.', 'modern-hotel-booking'); ?>
                                    </p>
                                    <a href="?page=mhb-pro-pricing" class="mhbo-configure-link">
                                        <?php esc_html_e('Configure', 'modern-hotel-booking'); ?>
                                    </a>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">➕</span>
                                    <h4><?php esc_html_e('Booking Extras', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Offer guided tours, airport transfers, breakfast packages, and local experiences.', 'modern-hotel-booking'); ?>
                                    </p>
                                    <a href="?page=mhb-pro-extras" class="mhbo-configure-link">
                                        <?php esc_html_e('Configure', 'modern-hotel-booking'); ?>
                                    </a>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🔄</span>
                                    <h4><?php esc_html_e('iCal Synchronization', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Bi-directional sync with Airbnb, Booking.com, and Google Calendar.', 'modern-hotel-booking'); ?>
                                    </p>
                                    <a href="?page=mhb-pro-ical" class="mhbo-configure-link">
                                        <?php esc_html_e('Configure', 'modern-hotel-booking'); ?>
                                    </a>
                                    <span
                                        class="mhbo-badge mhbo-badge-popular"><?php esc_html_e('Popular', 'modern-hotel-booking'); ?></span>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">📊</span>
                                    <h4><?php esc_html_e('Analytics Dashboard', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Chart.js visualizations, occupancy rates, and ADR tracking for yield optimization.', 'modern-hotel-booking'); ?>
                                    </p>
                                    <a href="?page=mhb-pro-analytics" class="mhbo-configure-link">
                                        <?php esc_html_e('View', 'modern-hotel-booking'); ?>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Compliance & UX Category -->
                        <div class="mhbo-feature-category">
                            <div class="mhbo-category-header">
                                <h2><span class="mhbo-category-icon">🔒</span>
                                    <?php esc_html_e('Compliance & UX', 'modern-hotel-booking'); ?></h2>
                            </div>
                            <div class="mhbo-feature-grid">
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🔒</span>
                                    <h4><?php esc_html_e('GDPR Compliance Tools', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Automated data retention, consent checkboxes, and cookie management.', 'modern-hotel-booking'); ?>
                                    </p>
                                    
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🎨</span>
                                    <h4><?php esc_html_e('Theme Customization', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('6 designer presets, custom branding colors, and advanced CSS injection.', 'modern-hotel-booking'); ?>
                                    </p>
                                    <a href="?page=mhb-pro-themes" class="mhbo-configure-link">
                                        <?php esc_html_e('Configure', 'modern-hotel-booking'); ?>
                                    </a>
                                </div>
                                <div class="mhbo-feature-card">
                                    <span class="mhbo-feature-icon">🌐</span>
                                    <h4><?php esc_html_e('Multilingual Support', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Compatible with qTranslate, WPML, and Polylang for full translation support.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Developer Tools Category -->
                        <div class="mhbo-feature-category mhbo-feature-category-dark">
                            <div class="mhbo-category-header">
                                <h2><span class="mhbo-category-icon">⚡</span>
                                    <?php esc_html_e('Developer Platform', 'modern-hotel-booking'); ?></h2>
                                <a href="?page=mhb-pro-webhooks" class="mhbo-configure-link">
                                    <?php esc_html_e('Configure', 'modern-hotel-booking'); ?> <span
                                        class="dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                            </div>
                            <div class="mhbo-feature-grid">
                                <div class="mhbo-feature-card mhbo-feature-card-dark">
                                    <span class="mhbo-feature-icon">🔐</span>
                                    <h4><?php esc_html_e('REST API', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Full REST API access for external integrations and custom applications.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                                <div class="mhbo-feature-card mhbo-feature-card-dark">
                                    <span class="mhbo-feature-icon">🔗</span>
                                    <h4><?php esc_html_e('HMAC Webhooks', 'modern-hotel-booking'); ?></h4>
                                    <p><?php esc_html_e('Secure webhooks with HMAC-signed payloads for booking events.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions Section -->
                    <div class="mhbo-quick-actions">
                        <h2><?php esc_html_e('Quick Actions', 'modern-hotel-booking'); ?></h2>
                        <div class="mhbo-quick-actions-grid">
                            <a href="?page=mhb-pro-payments" class="mhbo-quick-action-btn">
                                <span class="dashicons dashicons-money-alt"></span>
                                <?php esc_html_e('Configure Payment Gateways', 'modern-hotel-booking'); ?>
                            </a>
                            
                            <a href="?page=mhb-pro-analytics" class="mhbo-quick-action-btn">
                                <span class="dashicons dashicons-chart-bar"></span>
                                <?php esc_html_e('View Analytics', 'modern-hotel-booking'); ?>
                            </a>
                            <a href="?page=mhb-pro-ical" class="mhbo-quick-action-btn">
                                <span class="dashicons dashicons-calendar-alt"></span>
                                <?php esc_html_e('Manage iCal Feeds', 'modern-hotel-booking'); ?>
                            </a>
                        </div>
                    </div>

                <?php elseif ('extras' === $active_tab): ?>
                    <?php if ($is_active): ?>
                        <div class="mhbo-card">
                            <?php
                            if (method_exists('MHB\Admin\Menu', 'display_extras_page')) {
                                (new Menu())->display_extras_page();
                            }
                            ?>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>

                <?php elseif ('payments' === $active_tab): ?>
                    <?php if ($is_active): ?>
                        <div class="mhbo-card">
                            <form method="post" action="">
                                <?php wp_nonce_field('mhbo_settings_group-options'); ?>
                                <input type="hidden" name="mhbo_save_tab" value="payments">
                                <?php self::render_payments_tab(); ?>
                                <?php submit_button(__('Save Payment Settings', 'modern-hotel-booking')); ?>
                            </form>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>

                <?php elseif ('webhooks' === $active_tab): ?>
                    <?php if ($is_active): ?>
                        <div class="mhbo-card">
                            <?php self::render_api_tab(); ?>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>

                <?php elseif ('themes' === $active_tab): ?>
                    <?php if ($is_active): ?>
                        <form method="post">
                            <?php wp_nonce_field('mhbo_pro_themes_settings'); ?>
                            <div class="mhbo-card">
                                <?php self::render_pro_upsell(); ?>
                            </div>
                            <p class="submit">
                                <button type="submit" name="mhbo_pro_themes_save" value="1" class="button button-primary">
                                    <?php esc_html_e('Save Theme Settings', 'modern-hotel-booking'); ?>
                                </button>
                            </p>
                        </form>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>

                <?php elseif ('analytics' === $active_tab): ?>
                    <?php if ($is_active): ?>
                        <div class="mhbo-card">
                            <?php
                            if (false) {
                                /* Pro method call removed */;
                            }
                            ?>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>

                <?php elseif ('pricing' === $active_tab): ?>
                    <?php if ($is_active): ?>
                        <div class="mhbo-card">
                            <?php self::render_pro_upsell(); ?>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>

                <?php elseif ('ical' === $active_tab): ?>
                    <?php if ($is_active): ?>
                        <div class="mhbo-card">
                            <?php
                            if (false) {
                                /* Pro method call removed */;
                            }
                            ?>
                        </div>
                    <?php else:
                        self::render_pro_upsell();
                    endif; ?>
                <?php endif; ?>
            </div>
            <?php
    }

    /**
     * Render Pro Upsell notice for unlicensed users trying to access Pro tabs.
     */
    private static function render_pro_upsell()
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
                    <a href="https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/" target="_blank"
                        rel="noopener noreferrer"
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

    /**
     * Render Payment Gateways settings tab (Pro).
     */
    private static function render_payments_tab()
    {
        if (false) {
            /* Pro static method removed */;
        } else {
            echo '<p>' . esc_html__('Pro features are not available.', 'modern-hotel-booking') . '</p>';
        }
    }

    /**
     * Render API & Webhooks settings tab.
     */
    private static function render_api_tab()
    {
        $api_key = get_option('mhbo_api_key', '');
        $webhook_url = get_option('mhbo_webhook_url', '');
        ?>
            <div class="mhbo-settings-section">
                <h3><?php esc_html_e('REST API', 'modern-hotel-booking'); ?></h3>
                <p class="description">
                    <?php esc_html_e('The REST API allows external systems to query room availability and manage bookings. API base:', 'modern-hotel-booking'); ?>
                    <code><?php echo esc_html(rest_url('mhb/v1/')); ?></code>
                </p>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('API Key', 'modern-hotel-booking'); ?></th>
                        <td>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <input type="text" id="mhbo_api_key" name="mhbo_api_key" value="<?php echo esc_attr($api_key); ?>"
                                    class="regular-text">
                                <button type="button" class="button"
                                    onclick="document.getElementById('mhbo_api_key').value = '<?php echo esc_js(wp_generate_password(32, false)); ?>';">🔑
                                    <?php esc_html_e('Generate', 'modern-hotel-booking'); ?></button>
                                <button type="button" class="button mhbo-copy-btn"
                                    data-copy-target="mhbo_api_key"><?php esc_html_e('Copy', 'modern-hotel-booking'); ?></button>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Include as X-MHB-API-Key header for authenticated endpoints (POST /bookings, GET /bookings/{id}).', 'modern-hotel-booking'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e('Webhooks', 'modern-hotel-booking'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Webhook URL', 'modern-hotel-booking'); ?></th>
                        <td>
                            <input type="url" name="mhbo_webhook_url" value="<?php echo esc_url($webhook_url); ?>"
                                class="regular-text" placeholder="https://example.com/webhook">
                            <p class="description">
                                <?php esc_html_e('Receive a JSON POST for booking events: booking_created, booking_confirmed, booking_cancelled. Payloads are HMAC-signed with your API key.', 'modern-hotel-booking'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e('Available Endpoints', 'modern-hotel-booking'); ?></h3>
                <table class="widefat" style="max-width: 800px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Method', 'modern-hotel-booking'); ?></th>
                            <th><?php esc_html_e('Endpoint', 'modern-hotel-booking'); ?></th>
                            <th><?php esc_html_e('Auth / Access', 'modern-hotel-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>GET</code></td>
                            <td><code>/mhb/v1/rooms</code></td>
                            <td><?php esc_html_e('Pro (Rate Limited)', 'modern-hotel-booking'); ?></td>
                        </tr>
                        <tr>
                            <td><code>GET</code></td>
                            <td><code>/mhb/v1/availability</code></td>
                            <td><?php esc_html_e('Pro (Rate Limited)', 'modern-hotel-booking'); ?></td>
                        </tr>
                        <tr>
                            <td><code>POST</code></td>
                            <td><code>/mhb/v1/recalculate-price</code></td>
                            <td><?php esc_html_e('Public (Rate Limited)', 'modern-hotel-booking'); ?></td>
                        </tr>
                        <tr>
                            <td><code>GET</code></td>
                            <td><code>/mhb/v1/calendar-data</code></td>
                            <td><?php esc_html_e('Public (Rate Limited)', 'modern-hotel-booking'); ?></td>
                        </tr>
                        <tr>
                            <td><code>POST</code></td>
                            <td><code>/mhb/v1/bookings</code></td>
                            <td>🔑 <?php esc_html_e('API Key Required', 'modern-hotel-booking'); ?></td>
                        </tr>
                        <tr>
                            <td><code>GET</code></td>
                            <td><code>/mhb/v1/bookings/{id}</code></td>
                            <td>🔑 <?php esc_html_e('API Key Required', 'modern-hotel-booking'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php
    }

    /**
     * Render Advanced Pricing tab (Pro).
     */
    
    /**
     * Render Pro Themes settings tab.
     */
    
    

    

    private static function render_amenities_tab()
    {
        $amenities = get_option('mhbo_amenities_list');
        $default_amenities = [
            'wifi' => __('Free WiFi', 'modern-hotel-booking'),
            'ac' => __('Air Conditioning', 'modern-hotel-booking'),
            'tv' => __('Smart TV', 'modern-hotel-booking'),
            'breakfast' => __('Breakfast Included', 'modern-hotel-booking'),
            'pool' => __('Pool View', 'modern-hotel-booking')
        ];

        if (empty($amenities) || !is_array($amenities)) {
            $amenities = $default_amenities;
        }
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

    public function save_api_settings()
    {
        if (!isset($_POST['mhbo_save_tab']) || $_POST['mhbo_save_tab'] !== 'api') {
            return;
        }

        if (!check_admin_referer('mhbo_settings_group-options')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['mhbo_api_key']))
            update_option('mhbo_api_key', sanitize_text_field(wp_unslash($_POST['mhbo_api_key'])));
        if (isset($_POST['mhbo_webhook_url']))
            update_option('mhbo_webhook_url', esc_url_raw(wp_unslash($_POST['mhbo_webhook_url'])));

        add_settings_error('mhbo_settings', 'saved', __('API settings saved successfully.', 'modern-hotel-booking'), 'success');
    }

    public function save_payments_settings()
    {
        if (!isset($_POST['mhbo_save_tab']) || $_POST['mhbo_save_tab'] !== 'payments') {
            return;
        }

        if (!check_admin_referer('mhbo_settings_group-options')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // Stripe
        update_option('mhbo_gateway_stripe_enabled', isset($_POST['mhbo_gateway_stripe_enabled']) ? 1 : 0);
        if (isset($_POST['mhbo_stripe_mode']))
            update_option('mhbo_stripe_mode', sanitize_text_field(wp_unslash($_POST['mhbo_stripe_mode'])));
        if (isset($_POST['mhbo_stripe_test_publishable_key']))
            update_option('mhbo_stripe_test_publishable_key', sanitize_text_field(wp_unslash($_POST['mhbo_stripe_test_publishable_key'])));
        // Only update secret if a new value is provided (field is not empty) - encrypt before storage
        if (isset($_POST['mhbo_stripe_test_secret_key']) && !empty(trim(wp_unslash($_POST['mhbo_stripe_test_secret_key'])))) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $gateway = null /* Pro class removed */;
            update_option('mhbo_stripe_test_secret_key', $gateway->sanitize_secret_key(wp_unslash($_POST['mhbo_stripe_test_secret_key']))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }
        if (isset($_POST['mhbo_stripe_live_publishable_key']))
            update_option('mhbo_stripe_live_publishable_key', sanitize_text_field(wp_unslash($_POST['mhbo_stripe_live_publishable_key'])));
        if (isset($_POST['mhbo_stripe_live_secret_key']) && !empty(trim(wp_unslash($_POST['mhbo_stripe_live_secret_key'])))) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $gateway = null /* Pro class removed */;
            update_option('mhbo_stripe_live_secret_key', $gateway->sanitize_secret_key(wp_unslash($_POST['mhbo_stripe_live_secret_key']))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        // PayPal
        update_option('mhbo_gateway_paypal_enabled', isset($_POST['mhbo_gateway_paypal_enabled']) ? 1 : 0);
        if (isset($_POST['mhbo_paypal_mode']))
            update_option('mhbo_paypal_mode', sanitize_text_field(wp_unslash($_POST['mhbo_paypal_mode'])));
        if (isset($_POST['mhbo_paypal_sandbox_client_id']))
            update_option('mhbo_paypal_sandbox_client_id', trim(sanitize_text_field(wp_unslash($_POST['mhbo_paypal_sandbox_client_id']))));
        if (isset($_POST['mhbo_paypal_sandbox_webhook_id']))
            update_option('mhbo_paypal_sandbox_webhook_id', trim(sanitize_text_field(wp_unslash($_POST['mhbo_paypal_sandbox_webhook_id']))));
        if (isset($_POST['mhbo_paypal_sandbox_secret']) && !empty(trim(wp_unslash($_POST['mhbo_paypal_sandbox_secret'])))) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $gateway = null /* Pro class removed */;
            update_option('mhbo_paypal_sandbox_secret', $gateway->sanitize_secret_key(wp_unslash($_POST['mhbo_paypal_sandbox_secret']))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }
        if (isset($_POST['mhbo_paypal_live_client_id']))
            update_option('mhbo_paypal_live_client_id', trim(sanitize_text_field(wp_unslash($_POST['mhbo_paypal_live_client_id']))));
        if (isset($_POST['mhbo_paypal_live_webhook_id']))
            update_option('mhbo_paypal_live_webhook_id', trim(sanitize_text_field(wp_unslash($_POST['mhbo_paypal_live_webhook_id']))));
        if (isset($_POST['mhbo_paypal_live_secret']) && !empty(trim(wp_unslash($_POST['mhbo_paypal_live_secret'])))) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $gateway = null /* Pro class removed */;
            update_option('mhbo_paypal_live_secret', $gateway->sanitize_secret_key(wp_unslash($_POST['mhbo_paypal_live_secret']))); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        // On-site
        update_option('mhbo_gateway_onsite_enabled', isset($_POST['mhbo_gateway_onsite_enabled']) ? 1 : 0);
        if (isset($_POST['mhbo_onsite_instructions']))
            update_option('mhbo_onsite_instructions', wp_kses_post(wp_unslash($_POST['mhbo_onsite_instructions'])));

        add_settings_error('mhbo_settings', 'saved', __('Payment settings saved successfully.', 'modern-hotel-booking'), 'success');
    }

    public function save_pricing_settings()
    {
        if (!isset($_POST['mhbo_save_tab']) || $_POST['mhbo_save_tab'] !== 'pricing') {
            return;
        }

        if (!check_admin_referer('mhbo_settings_group-options')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // Weekend Pricing
        $weekend_enabled = isset($_POST['mhbo_weekend_pricing_enabled']) ? 1 : 0;
        update_option('mhbo_weekend_pricing_enabled', $weekend_enabled);

        if (isset($_POST['mhbo_weekend_days']) && is_array($_POST['mhbo_weekend_days'])) {
            $days = array_map('sanitize_text_field', wp_unslash($_POST['mhbo_weekend_days']));
            update_option('mhbo_weekend_days', $days);
        } else {
            update_option('mhbo_weekend_days', []);
        }

        if (isset($_POST['mhbo_weekend_rate_multiplier']))
            update_option('mhbo_weekend_rate_multiplier', floatval($_POST['mhbo_weekend_rate_multiplier']));
        if (isset($_POST['mhbo_weekend_modifier_type']))
            update_option('mhbo_weekend_modifier_type', sanitize_key(wp_unslash($_POST['mhbo_weekend_modifier_type'])));

        // Holiday Pricing
        $holiday_enabled = isset($_POST['mhbo_holiday_pricing_enabled']) ? 1 : 0;
        update_option('mhbo_holiday_pricing_enabled', $holiday_enabled);

        if (isset($_POST['mhbo_holiday_rate_modifier']))
            update_option('mhbo_holiday_rate_modifier', floatval($_POST['mhbo_holiday_rate_modifier']));
        if (isset($_POST['mhbo_holiday_modifier_type']))
            update_option('mhbo_holiday_modifier_type', sanitize_key(wp_unslash($_POST['mhbo_holiday_modifier_type'])));
        if (isset($_POST['mhbo_holiday_dates']))
            update_option('mhbo_holiday_dates', sanitize_text_field(wp_unslash($_POST['mhbo_holiday_dates'])));

        $apply_both = isset($_POST['mhbo_apply_weekend_to_holidays']) ? 1 : 0;
        update_option('mhbo_apply_weekend_to_holidays', $apply_both);

        add_settings_error('mhbo_settings', 'saved', __('Pricing settings saved successfully.', 'modern-hotel-booking'), 'success');
    }

    public function save_amenities_settings()
    {
        if (!isset($_POST['mhbo_save_tab']) || $_POST['mhbo_save_tab'] !== 'amenities') {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('mhbo_settings_group-options');

        $amenities = get_option('mhbo_amenities_list');
        if (empty($amenities) || !is_array($amenities)) {
            $amenities = [
                'wifi' => __('Free WiFi', 'modern-hotel-booking'),
                'ac' => __('Air Conditioning', 'modern-hotel-booking'),
                'tv' => __('Smart TV', 'modern-hotel-booking'),
                'breakfast' => __('Breakfast Included', 'modern-hotel-booking'),
                'pool' => __('Pool View', 'modern-hotel-booking')
            ];
        }

        // Add Amenity
        if (isset($_POST['mhbo_add_amenity']) && !empty($_POST['mhbo_new_amenity'])) {
            $label = sanitize_text_field(wp_unslash($_POST['mhbo_new_amenity']));
            $key = sanitize_title($label);
            if ($key && !isset($amenities[$key])) {
                $amenities[$key] = $label;
                update_option('mhbo_amenities_list', $amenities);
                add_settings_error('mhbo_amenities', 'added', __('Amenity added successfully.', 'modern-hotel-booking'), 'success');
            }
        }

        // Remove Amenity
        if (isset($_POST['mhbo_remove_amenity'])) {
            $key = sanitize_text_field(wp_unslash($_POST['mhbo_remove_amenity']));
            if (isset($amenities[$key])) {
                unset($amenities[$key]);
                update_option('mhbo_amenities_list', $amenities);
                add_settings_error('mhbo_amenities', 'removed', __('Amenity removed successfully.', 'modern-hotel-booking'), 'success');
            }
        }
    }

    /**
     * Render Tax Settings Tab
     */
    
    /**
     * Save Tax Settings
     */
    public function save_tax_settings()
    {
        if (!isset($_POST['mhbo_save_tab']) || $_POST['mhbo_save_tab'] !== 'tax') {
            return;
        }

        if (!check_admin_referer('mhbo_settings_group-options')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // Tax Mode
        if (isset($_POST['mhbo_tax_mode'])) {
            $allowed_modes = ['disabled', 'vat', 'sales_tax'];
            $mode = sanitize_text_field(wp_unslash($_POST['mhbo_tax_mode']));
            if (in_array($mode, $allowed_modes, true)) {
                update_option('mhbo_tax_mode', $mode);
            }
        }

        // Tax Label (Multilingual)
        if (isset($_POST['mhbo_tax_label_lang']) && is_array($_POST['mhbo_tax_label_lang'])) {
            $label_data = array_map('sanitize_text_field', wp_unslash($_POST['mhbo_tax_label_lang']));
            update_option('mhbo_tax_label', I18n::encode($label_data));
        }

        // Tax Registration Number
        if (isset($_POST['mhbo_tax_registration_number'])) {
            update_option('mhbo_tax_registration_number', sanitize_text_field(wp_unslash($_POST['mhbo_tax_registration_number'])));
        }

        // Tax Rates (with server-side range validation 0-100)
        if (isset($_POST['mhbo_tax_rate_accommodation'])) {
            $rate = max(0, min(100, floatval($_POST['mhbo_tax_rate_accommodation'])));
            update_option('mhbo_tax_rate_accommodation', $rate);
        }
        if (isset($_POST['mhbo_tax_rate_extras'])) {
            $rate = max(0, min(100, floatval($_POST['mhbo_tax_rate_extras'])));
            update_option('mhbo_tax_rate_extras', $rate);
        }

        // Display Options
        update_option('mhbo_tax_display_frontend', isset($_POST['mhbo_tax_display_frontend']) ? 1 : 0);
        update_option('mhbo_tax_display_email', isset($_POST['mhbo_tax_display_email']) ? 1 : 0);

        // Advanced Settings
        if (isset($_POST['mhbo_tax_rounding_mode'])) {
            $allowed_rounding = ['per_total', 'per_line'];
            $rounding = sanitize_text_field(wp_unslash($_POST['mhbo_tax_rounding_mode']));
            if (in_array($rounding, $allowed_rounding, true)) {
                update_option('mhbo_tax_rounding_mode', $rounding);
            }
        }
        if (isset($_POST['mhbo_tax_decimal_places'])) {
            update_option('mhbo_tax_decimal_places', absint($_POST['mhbo_tax_decimal_places']));
        }

        add_settings_error('mhbo_settings', 'saved', __('Tax settings saved successfully.', 'modern-hotel-booking'), 'success');
    }

    /**
     * Save Performance Settings
     */
    public function save_performance_settings()
    {
        if (!isset($_POST['mhbo_save_tab']) || $_POST['mhbo_save_tab'] !== 'performance') {
            return;
        }

        if (!check_admin_referer('mhbo_settings_group-options')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // Cache Enable/Disable
        update_option('mhbo_cache_enabled', isset($_POST['mhbo_cache_enabled']) ? 1 : 0);

        add_settings_error('mhbo_settings', 'saved', __('Performance settings saved successfully.', 'modern-hotel-booking'), 'success');
    }

    /**
     * AJAX handler for clearing cache.
     */
    public function ajax_clear_cache()
    {
        check_ajax_referer('mhbo_clear_cache_nonce_field', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'modern-hotel-booking')]);
        }

        if (class_exists('MHB\Core\Cache')) {
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
