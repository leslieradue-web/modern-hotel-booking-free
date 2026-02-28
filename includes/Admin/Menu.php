<?php declare(strict_types=1);

namespace MHB\Admin;

if (!defined('ABSPATH')) {
    exit;
}


use MHB\Core\Email;
use MHB\Core\I18n;

class Menu
{
    public function init()
    {
        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));

        $settings = new Settings();
        $settings->init();
    }

    public function add_dashboard_widgets()
    {
        wp_add_dashboard_widget('mhb_dashboard_overview', __('Hotel Booking Overview', 'modern-hotel-booking'), array($this, 'render_dashboard_widget'));
    }

    public function render_dashboard_widget()
    {
        // Explicit capability check for defense-in-depth
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mhb_bookings"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, no WP API
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mhb_bookings WHERE status='pending'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, no WP API
        $today_date = wp_date('Y-m-d');
        $today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}mhb_bookings WHERE check_in = %s", $today_date)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
        echo '<div style="display:flex;justify-content:space-between;text-align:center;">';
        printf('<div><h4 style="margin:0;color:#2271b1;font-size:24px;">%d</h4><p style="margin:0;">%s</p></div>', (int) $total, esc_html__('Total', 'modern-hotel-booking'));
        printf('<div><h4 style="margin:0;color:#d63638;font-size:24px;">%d</h4><p style="margin:0;">%s</p></div>', (int) $pending, esc_html__('Pending', 'modern-hotel-booking'));
        printf('<div><h4 style="margin:0;color:#00a32a;font-size:24px;">%d</h4><p style="margin:0;">%s</p></div>', (int) $today, esc_html__('Today', 'modern-hotel-booking'));
        echo '</div><hr><a href="' . esc_url(admin_url('admin.php?page=mhb-bookings')) . '" class="button button-primary" style="width:100%;text-align:center;">' . esc_html__('Manage Bookings', 'modern-hotel-booking') . '</a>';
    }

    public function enqueue_admin_assets($hook)
    {
        if (false === strpos($hook, 'modern-hotel-booking') && false === strpos($hook, 'mhb-') && 'index.php' !== $hook) {
            return;
        }
        wp_enqueue_style('mhb-admin-css', MHB_PLUGIN_URL . 'assets/css/mhb-admin.css', array(), MHB_VERSION);
        wp_enqueue_script('mhb-admin-js', MHB_PLUGIN_URL . 'assets/js/mhb-admin.js', array('jquery'), MHB_VERSION, true);

        // Enqueue iCal admin script on iCal pages
        if (false !== strpos($hook, 'mhb-pro-ical')) {
            wp_enqueue_script('mhb-ical-admin-js', MHB_PLUGIN_URL . 'assets/js/mhb-ical-admin.js', array('jquery'), MHB_VERSION, true);
            wp_localize_script('mhb-ical-admin-js', 'mhbIcalNonce', array('nonce' => wp_create_nonce('mhb_ical_nonce')));
        }

        if (false !== strpos($hook, 'mhb-bookings')) {
            wp_enqueue_script('fullcalendar', MHB_PLUGIN_URL . 'assets/js/vendor/fullcalendar.min.js', array(), '6.1.10', true);

            // Enqueue admin bookings script
            wp_enqueue_script(
                'mhb-admin-bookings',
                MHB_PLUGIN_URL . 'assets/js/mhb-admin-bookings.js',
                array('jquery', 'mhb-admin-js'),
                MHB_VERSION,
                true
            );

            // Inject configuration
            $config = array(
                'nonce' => wp_create_nonce('wp_rest'),
                'extrasCount' => 0,
            );
            wp_add_inline_script('mhb-admin-bookings', 'window.mhbAdminBookingsConfig = ' . wp_json_encode($config) . ';', 'before');
        }
        if (false !== strpos($hook, 'mhb-pro-analytics')) {
            wp_enqueue_script('chartjs', MHB_PLUGIN_URL . 'assets/js/vendor/chart.min.js', array(), '4.4.1', true);
        }
    }

    public function add_plugin_admin_menu()
    {
        add_menu_page(I18n::__('Hotel Booking', 'modern-hotel-booking'), I18n::__('Hotel Booking', 'modern-hotel-booking'), 'manage_options', 'modern-hotel-booking', array($this, 'display_dashboard_page'), 'dashicons-building', 26);
        add_submenu_page('modern-hotel-booking', I18n::__('Bookings', 'modern-hotel-booking'), I18n::__('Bookings', 'modern-hotel-booking'), 'manage_options', 'mhb-bookings', array($this, 'display_bookings_page'));
        add_submenu_page('modern-hotel-booking', I18n::__('Room Types', 'modern-hotel-booking'), I18n::__('Room Types', 'modern-hotel-booking'), 'manage_options', 'mhb-room-types', array($this, 'display_room_types_page'));
        add_submenu_page('modern-hotel-booking', I18n::__('Rooms', 'modern-hotel-booking'), I18n::__('Rooms', 'modern-hotel-booking'), 'manage_options', 'mhb-rooms', array($this, 'display_rooms_page'));
        add_submenu_page('modern-hotel-booking', I18n::__('Pricing Rules', 'modern-hotel-booking'), I18n::__('Pricing Rules', 'modern-hotel-booking'), 'manage_options', 'mhb-pricing-rules', array($this, 'display_pricing_rules_page'));
        add_submenu_page('modern-hotel-booking', I18n::__('Settings', 'modern-hotel-booking'), I18n::__('Settings', 'modern-hotel-booking'), 'manage_options', 'mhb-settings', array('MHB\\Admin\\Settings', 'render'));

        add_submenu_page('modern-hotel-booking', I18n::__('PRO Features', 'modern-hotel-booking'), I18n::__('PRO Features', 'modern-hotel-booking'), 'manage_options', 'mhb-pro', array('MHB\\Admin\\Settings', 'render_pro_page'));

        // Register Pro subpages (hidden from sidebar by passing null as parent)
        add_submenu_page(null, I18n::__('Extras', 'modern-hotel-booking'), I18n::__('Extras', 'modern-hotel-booking'), 'manage_options', 'mhb-pro-extras', array('MHB\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page(null, I18n::__('iCal Sync', 'modern-hotel-booking'), I18n::__('iCal Sync', 'modern-hotel-booking'), 'manage_options', 'mhb-pro-ical', array('MHB\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page(null, I18n::__('Payments', 'modern-hotel-booking'), I18n::__('Payments', 'modern-hotel-booking'), 'manage_options', 'mhb-pro-payments', array('MHB\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page(null, I18n::__('Webhooks', 'modern-hotel-booking'), I18n::__('Webhooks', 'modern-hotel-booking'), 'manage_options', 'mhb-pro-webhooks', array('MHB\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page(null, I18n::__('Analytics', 'modern-hotel-booking'), I18n::__('Analytics', 'modern-hotel-booking'), 'manage_options', 'mhb-pro-analytics', array('MHB\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page(null, I18n::__('Appearance', 'modern-hotel-booking'), I18n::__('Appearance', 'modern-hotel-booking'), 'manage_options', 'mhb-pro-themes', array('MHB\\Admin\\Settings', 'render_pro_page'));
        add_submenu_page(null, I18n::__('Advanced Pricing', 'modern-hotel-booking'), I18n::__('Advanced Pricing', 'modern-hotel-booking'), 'manage_options', 'mhb-pro-pricing', array('MHB\\Admin\\Settings', 'render_pro_page'));
    }

    public function display_dashboard_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'modern-hotel-booking'));
        }

        global $wpdb;

        // Statistics
        $today_date = wp_date('Y-m-d');
        $total_bookings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mhb_bookings"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, no WP API
        $pending_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mhb_bookings WHERE status='pending'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, no WP API
        $earned_revenue = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total_price),0) FROM {$wpdb->prefix}mhb_bookings WHERE status='confirmed' AND check_out <= %s", $today_date)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
        $future_revenue = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total_price),0) FROM {$wpdb->prefix}mhb_bookings WHERE status='confirmed' AND check_out > %s", $today_date)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table

        // Recent Activity
        $recent_bookings = $wpdb->get_results("SELECT b.*, r.room_number as room_name FROM {$wpdb->prefix}mhb_bookings b LEFT JOIN {$wpdb->prefix}mhb_rooms r ON b.room_id = r.id ORDER BY b.created_at DESC LIMIT 5"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, no WP API, static query
        $today_checkins = $wpdb->get_results($wpdb->prepare("SELECT b.*, r.room_number as room_name FROM {$wpdb->prefix}mhb_bookings b LEFT JOIN {$wpdb->prefix}mhb_rooms r ON b.room_id = r.id WHERE b.status='confirmed' AND b.check_in = %s LIMIT 5", $today_date)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table

        $is_pro_active = 'active' === '';
        ?>
        <div class="wrap mhb-dashboard">
            <h1 style="margin-bottom: 25px; font-weight: 800; color: #1a3b5d;">
                <?php esc_html_e('Hotel Control Center', 'modern-hotel-booking'); ?>
            </h1>

            <?php if (!$is_pro_active): ?>
                <div class="mhb-card accent"
                    style="background: linear-gradient(90deg, #f4eee1 0%, #ffffff 100%); display: flex; align-items: center; justify-content: space-between; border-left: 5px solid #c5a059;">
                    <div style="flex: 1;">
                        <h3 style="margin-bottom: 8px; font-size: 1.4rem;">
                            <?php esc_html_e('Unlock Premium Hospitality Features', 'modern-hotel-booking'); ?>
                        </h3>
                        <p style="margin: 0; color: #646970; font-size: 15px;">
                            <?php esc_html_e('Power up with iCal Sync, Stripe/PayPal integration, Advanced Pricing rules, and Deep Analytics.', 'modern-hotel-booking'); ?>
                        </p>
                    </div>
                    <a href="https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/" target="_blank"
                        class="button button-primary button-hero"
                        style="background: #c5a059; border-color: #b38d48; box-shadow: 0 2px 0 #9e7a3a;">
                        <?php esc_html_e('Upgrade to Pro', 'modern-hotel-booking'); ?>
                    </a>
                </div>
            <?php endif; ?>

            <div class="mhb-stats-grid">
                <div class="mhb-stat-card">
                    <h3><?php esc_html_e('Stay Revenue', 'modern-hotel-booking'); ?></h3>
                    <p><?php echo esc_html(I18n::format_currency($earned_revenue)); ?></p>
                </div>
                <div class="mhb-stat-card">
                    <h3><?php esc_html_e('Target Pipeline', 'modern-hotel-booking'); ?></h3>
                    <p><?php echo esc_html(I18n::format_currency($future_revenue)); ?></p>
                </div>
                <div class="mhb-stat-card">
                    <h3><?php esc_html_e('Total Volume', 'modern-hotel-booking'); ?></h3>
                    <p><?php echo esc_html((string) $total_bookings); ?></p>
                </div>
                <div class="mhb-stat-card" style="border-color: #ffe0b2;">
                    <h3><?php esc_html_e('Attention Needed', 'modern-hotel-booking'); ?></h3>
                    <p style="color: #f57c00;"><?php echo esc_html((string) $pending_count); ?></p>
                </div>
            </div>

            <div class="mhb-dashboard-layout">
                <div class="mhb-main-col">
                    <div class="mhb-card">
                        <h3><?php esc_html_e('Recent Activity', 'modern-hotel-booking'); ?></h3>
                        <?php if (empty($recent_bookings)): ?>
                            <p style="color: #999; font-style: italic;">
                                <?php esc_html_e('No recent bookings found.', 'modern-hotel-booking'); ?>
                            </p>
                        <?php else: ?>
                            <table class="wp-list-table widefat fixed striped" style="box-shadow: none; border: none;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Guest', 'modern-hotel-booking'); ?></th>
                                        <th><?php esc_html_e('Status', 'modern-hotel-booking'); ?></th>
                                        <th><?php esc_html_e('Date', 'modern-hotel-booking'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_bookings as $b): ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($b->customer_name); ?></strong></td>
                                            <td>
                                                <span class="mhb-status-badge mhb-status-<?php echo esc_attr($b->status); ?>">
                                                    <?php echo esc_html(I18n::translate_status($b->status)); ?>
                                                </span>
                                            </td>
                                            <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($b->created_at))); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div class="mhb-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3 style="margin:0;"><?php esc_html_e('Recent Bookings', 'modern-hotel-booking'); ?></h3>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhb-bookings')); ?>"
                                class="button"><?php esc_html_e('View All', 'modern-hotel-booking'); ?></a>
                        </div>
                        <table class="wp-list-table widefat fixed striped" style="box-shadow: none; border: none;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Date', 'modern-hotel-booking'); ?></th>
                                    <th><?php esc_html_e('Guest', 'modern-hotel-booking'); ?></th>
                                    <th><?php esc_html_e('Status', 'modern-hotel-booking'); ?></th>
                                    <th><?php esc_html_e('Total', 'modern-hotel-booking'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_bookings as $b): ?>
                                    <tr>
                                        <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($b->created_at))); ?>
                                        </td>
                                        <td><strong><?php echo esc_html(I18n::decode($b->customer_name)); ?></strong></td>
                                        <td><span
                                                class="mhb-status-badge mhb-status-<?php echo esc_attr($b->status); ?>"><?php echo esc_html($b->status); ?></span>
                                        </td>
                                        <td><?php echo esc_html(I18n::format_currency($b->total_price)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mhb-side-col">
                    <div class="mhb-card accent">
                        <h3><?php esc_html_e('Quick Actions', 'modern-hotel-booking'); ?></h3>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhb-bookings&action=add')); ?>"
                                class="button button-primary button-large"
                                style="background: #1a3b5d; border-color: #1a3b5d;"><?php esc_html_e('Create New Booking', 'modern-hotel-booking'); ?></a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhb-rooms')); ?>"
                                class="button button-large"><?php esc_html_e('Manage Inventory', 'modern-hotel-booking'); ?></a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhb-settings')); ?>"
                                class="button button-large"><?php esc_html_e('Global Settings', 'modern-hotel-booking'); ?></a>
                        </div>
                    </div>

                    <div class="mhb-card">
                        <h3><?php esc_html_e('System Status', 'modern-hotel-booking'); ?></h3>
                        <div style="font-size: 13px; line-height: 2;">
                            <div style="display: flex; justify-content: space-between;">
                                <span><?php esc_html_e('License Status:', 'modern-hotel-booking'); ?></span>
                                <strong
                                    style="color: <?php echo $is_pro_active ? '#2e7d32' : '#c62828'; ?>;"><?php echo $is_pro_active ? esc_html__('PRO Active', 'modern-hotel-booking') : esc_html__('Free Version', 'modern-hotel-booking'); ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span><?php esc_html_e('Plugin Version:', 'modern-hotel-booking'); ?></span>
                                <strong><?php echo esc_html(MHB_VERSION); ?></strong>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span><?php esc_html_e('Database Status:', 'modern-hotel-booking'); ?></span>
                                <strong style="color: #2e7d32;"><?php esc_html_e('Healthy', 'modern-hotel-booking'); ?></strong>
                            </div>
                        </div>
                    </div>

                    <?php
                    // Dynamically fetch the latest changelog from readme.txt
                    $changelog_items = [];
                    $latest_version = MHB_VERSION;
                    $readme_file = MHB_PLUGIN_DIR . 'readme.txt';

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

                    <div class="mhb-card" style="margin-top: 20px; border-left: 4px solid #10b981;">
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

                    <div class="mhb-card" style="background: #f8f6f2; border-color: #e9e5de;">
                        <h3><?php esc_html_e('Need Assistance?', 'modern-hotel-booking'); ?></h3>
                        <p style="font-size: 13px; color: #646970;">
                            <?php esc_html_e('Explore our documentation or contact the support team for property management advice.', 'modern-hotel-booking'); ?>
                        </p>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <a href="https://startmysuccess.com" target="_blank" class="button button-link"
                                style="padding:0; text-align: left;"><?php esc_html_e('Open Self-Service Portal →', 'modern-hotel-booking'); ?></a>
                            <a href="https://startmysuccess.com" target="_blank" class="button button-link"
                                style="padding:0; text-align: left; color:#c5a059; font-weight:bold;"><?php esc_html_e('Upgrade to Pro Version →', 'modern-hotel-booking'); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function display_bookings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'modern-hotel-booking'));
        }

        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely constructed from $wpdb->prefix
        $tb = $wpdb->prefix . 'mhb_bookings';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely constructed from $wpdb->prefix
        $tr = $wpdb->prefix . 'mhb_rooms';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely constructed from $wpdb->prefix
        $tt = $wpdb->prefix . 'mhb_room_types';
        $edit_mode = false;
        $add_mode = false;
        $edit_data = null;

        // Actions
        if (isset($_GET['action'])) {
            $act = sanitize_key($_GET['action']);

            if ('add' === $act) {
                $add_mode = true;
            } elseif (isset($_GET['id'])) {
                $id = absint($_GET['id']);
                if ('edit' === $act) {
                    $edit_mode = true;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, admin-only query
                    $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mhb_bookings WHERE id = %d", $id));
                } elseif ('confirm' === $act) {
                    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'mhb_confirm_booking_' . $id)) {
                        wp_die(esc_html__('Security check failed.', 'modern-hotel-booking'));
                    }
                    $wpdb->update($tb, array('status' => 'confirmed'), array('id' => $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
                    \MHB\Core\Cache::invalidate_booking($id);
                    Email::send_email($id, 'confirmed');
                    do_action('mhb_booking_confirmed', $id);
                    do_action('mhb_booking_status_changed', $id, 'confirmed');
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Booking Confirmed & Email Sent!', 'modern-hotel-booking') . '</p></div>';
                } elseif ('cancel' === $act) {
                    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'mhb_cancel_booking_' . $id)) {
                        wp_die(esc_html__('Security check failed.', 'modern-hotel-booking'));
                    }
                    $wpdb->update($tb, array('status' => 'cancelled'), array('id' => $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
                    \MHB\Core\Cache::invalidate_booking($id);
                    Email::send_email($id, 'cancelled');
                    do_action('mhb_booking_cancelled', $id);
                    do_action('mhb_booking_status_changed', $id, 'cancelled');
                    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Booking Cancelled.', 'modern-hotel-booking') . '</p></div>';
                } elseif ('delete' === $act) {
                    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'mhb_delete_booking_' . $id)) {
                        wp_die(esc_html__('Security check failed.', 'modern-hotel-booking'));
                    }
                    $wpdb->delete($tb, array('id' => $id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
                    \MHB\Core\Cache::invalidate_booking($id);
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Booking Deleted.', 'modern-hotel-booking') . '</p></div>';
                }
            }
        }

        // Handle manual booking submission
        if (isset($_POST['submit_manual_booking']) && check_admin_referer('mhb_add_manual_booking')) {
            $booking_extras = [];
            if (isset($_POST['mhb_extras']) && is_array($_POST['mhb_extras'])) {
                $available_extras = get_option('mhb_pro_extras', []);
                $extras_map = [];
                foreach ($available_extras as $ex)
                    $extras_map[$ex['id']] = $ex;

                $mhb_extras = array_map('sanitize_text_field', wp_unslash($_POST['mhb_extras']));
                foreach ($mhb_extras as $ex_id => $val) {
                    if (isset($extras_map[$ex_id])) {
                        $extra = $extras_map[$ex_id];
                        $quantity = 0;
                        if ($extra['control_type'] === 'checkbox' && '1' == $val)
                            $quantity = 1;
                        elseif ($extra['control_type'] === 'quantity')
                            $quantity = intval($val);

                        if (0 < $quantity) {
                            $booking_extras[] = [
                                'name' => $extra['name'],
                                'price' => floatval($extra['price']),
                                'quantity' => $quantity,
                                'total' => 0 // We don't easily calc total here without nights/guests context, relying on manual Total Price input for now
                            ];
                        }
                    }
                }
            }

            $children_count = absint($_POST['children'] ?? 0);
            $children_ages_raw = isset($_POST['child_ages']) && is_array($_POST['child_ages']) ? array_map('intval', wp_unslash($_POST['child_ages'])) : [];

            // Calculate tax breakdown for the manual booking
            $room_id = isset($_POST['room_id']) ? absint($_POST['room_id']) : 0;
            $check_in = isset($_POST['check_in']) ? sanitize_text_field(wp_unslash($_POST['check_in'])) : '';
            $check_out = isset($_POST['check_out']) ? sanitize_text_field(wp_unslash($_POST['check_out'])) : '';
            $guests = absint($_POST['guests'] ?? 1);

            // Format extras for Pricing::calculate_booking_total
            $post_extras = [];
            if (isset($_POST['mhb_extras']) && is_array($_POST['mhb_extras'])) {
                $mhb_extras_post = array_map('sanitize_text_field', wp_unslash($_POST['mhb_extras']));
                foreach ($mhb_extras_post as $ex_id => $val) {
                    $qty = (isset($extras_map[$ex_id]) && $extras_map[$ex_id]['control_type'] === 'quantity') ? intval($val) : ($val == '1' ? 1 : 0);
                    if ($qty > 0)
                        $post_extras[$ex_id] = $qty;
                }
            }

            $calc = \MHB\Core\Pricing::calculate_booking_total($room_id, $check_in, $check_out, $guests, $post_extras, $children_count, $children_ages_raw);
            $tax_data = $calc['tax'] ?? null;

            $customer_name = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
            $customer_email = isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '';
            $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field(wp_unslash($_POST['customer_phone'])) : '';
            $total_price = isset($_POST['total_price']) ? floatval($_POST['total_price']) : 0;
            $payment_received = isset($_POST['payment_received']) && !empty(sanitize_text_field(wp_unslash($_POST['payment_received'])));
            $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'pending';
            $admin_notes = isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '';
            $mhb_custom = isset($_POST['mhb_custom']) && is_array($_POST['mhb_custom']) ? array_map('sanitize_text_field', wp_unslash($_POST['mhb_custom'])) : [];

            // Availability Check
            $available = \MHB\Core\Pricing::is_room_available((int) $room_id, $check_in, $check_out);
            if (true !== $available) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(I18n::get_label($available)) . '</p></div>';
                $add_mode = true; // Stay in add mode to allow fixing
            } else {
                $wpdb->insert($tb, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
                    'customer_name' => $customer_name,
                    'customer_email' => $customer_email,
                    'customer_phone' => $customer_phone,
                    'room_id' => $room_id,
                    'check_in' => $check_in,
                    'check_out' => $check_out,
                    'total_price' => $total_price,
                    'discount_amount' => floatval($_POST['discount_amount'] ?? 0),
                    'deposit_amount' => floatval($_POST['deposit_amount'] ?? 0),
                    'deposit_received' => isset($_POST['deposit_received']) ? 1 : 0,
                    'payment_method' => sanitize_key($_POST['payment_method'] ?? 'onsite'),
                    'payment_status' => $payment_received ? 'completed' : 'pending',
                    'payment_received' => $payment_received ? 1 : 0,
                    'payment_amount' => $payment_received ? $total_price : null,
                    'payment_date' => $payment_received ? current_time('mysql') : null,
                    'status' => $status,
                    'admin_notes' => $admin_notes . "\n" . __('Manual booking added by admin.', 'modern-hotel-booking'),
                    'booking_extras' => !empty($booking_extras) ? json_encode($booking_extras) : null,
                    'booking_language' => sanitize_key($_POST['booking_language'] ?? 'en'),
                    'guests' => $guests,
                    'children' => $children_count,
                    'children_ages' => !empty($children_ages_raw) ? json_encode($children_ages_raw) : null,
                    'custom_fields' => !empty($mhb_custom) ? json_encode($mhb_custom) : null,
                    'created_at' => current_time('mysql'),
                    // Tax fields
                    'tax_enabled' => ($tax_data && $tax_data['enabled']) ? 1 : 0,
                    'tax_mode' => $tax_data['mode'] ?? 'disabled',
                    'tax_rate_accommodation' => $tax_data['breakdown']['rates']['accommodation'] ?? 0,
                    'tax_rate_extras' => $tax_data['breakdown']['rates']['extras'] ?? 0,
                    'room_total_net' => $tax_data['breakdown']['totals']['room_net'] ?? 0,
                    'room_tax' => $tax_data['breakdown']['totals']['room_tax'] ?? 0,
                    'children_total_net' => $tax_data['breakdown']['totals']['children_net'] ?? 0,
                    'children_tax' => $tax_data['breakdown']['totals']['children_tax'] ?? 0,
                    'extras_total_net' => $tax_data['breakdown']['totals']['extras_net'] ?? 0,
                    'extras_tax' => $tax_data['breakdown']['totals']['extras_tax'] ?? 0,
                    'subtotal_net' => $tax_data['breakdown']['totals']['subtotal_net'] ?? $total_price,
                    'total_tax' => $tax_data['breakdown']['totals']['total_tax'] ?? 0,
                    'total_gross' => $tax_data['breakdown']['totals']['total_gross'] ?? $total_price,
                    'tax_breakdown' => $tax_data ? json_encode($tax_data['breakdown']) : null,
                ));
                $new_id = $wpdb->insert_id;
                if ($new_id) {
                    // Invalidate booking and calendar cache to ensure availability and lists are updated
                    \MHB\Core\Cache::invalidate_booking($new_id, (int) $room_id);
                    do_action('mhb_booking_created', $new_id);
                    if ('confirmed' === $status) {
                        Email::send_email($new_id, 'confirmed');
                        do_action('mhb_booking_confirmed', $new_id);
                    }
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Manual Booking Added!', 'modern-hotel-booking') . '</p></div>';
                $add_mode = false;
            }
        }

        // Handle edit submission
        if (isset($_POST['submit_booking_update']) && check_admin_referer('mhb_update_booking')) {
            $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
            $new_status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'pending';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix, admin-only query
            $old_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM `{$tb}` WHERE id = %d", $booking_id));

            // Get existing payment data to preserve payment_date if already set
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, admin-only query
            $existing_payment = $wpdb->get_row($wpdb->prepare(
                "SELECT payment_received, payment_date FROM {$wpdb->prefix}mhb_bookings WHERE id = %d",
                $booking_id
            ), ARRAY_A);
            $was_payment_received = !empty($existing_payment['payment_received']);
            $existing_payment_date = $existing_payment['payment_date'] ?? null;

            $booking_extras = [];
            if (isset($_POST['mhb_extras']) && is_array($_POST['mhb_extras'])) {
                $available_extras = get_option('mhb_pro_extras', []);
                $extras_map = [];
                foreach ($available_extras as $ex)
                    $extras_map[$ex['id']] = $ex;

                $mhb_extras_edit = array_map('sanitize_text_field', wp_unslash($_POST['mhb_extras']));
                foreach ($mhb_extras_edit as $ex_id => $val) {
                    if (isset($extras_map[$ex_id])) {
                        $extra = $extras_map[$ex_id];
                        $quantity = 0;
                        if ($extra['control_type'] === 'checkbox' && '1' == $val)
                            $quantity = 1;
                        elseif ($extra['control_type'] === 'quantity')
                            $quantity = intval($val);

                        if (0 < $quantity) {
                            $booking_extras[] = [
                                'name' => $extra['name'],
                                'price' => floatval($extra['price']),
                                'quantity' => $quantity,
                                'total' => 0
                            ];
                        }
                    }
                }
            }

            $children_count = absint($_POST['children'] ?? 0);
            $children_ages_raw = isset($_POST['child_ages']) && is_array($_POST['child_ages']) ? array_map('intval', wp_unslash($_POST['child_ages'])) : [];

            // Calculate tax breakdown for the updated booking
            $room_id = isset($_POST['room_id']) ? absint($_POST['room_id']) : 0;
            $check_in = isset($_POST['check_in']) ? sanitize_text_field(wp_unslash($_POST['check_in'])) : '';
            $check_out = isset($_POST['check_out']) ? sanitize_text_field(wp_unslash($_POST['check_out'])) : '';
            $guests = absint($_POST['guests'] ?? 1);

            $post_extras = [];
            if (isset($_POST['mhb_extras']) && is_array($_POST['mhb_extras'])) {
                $mhb_extras_post_edit = array_map('sanitize_text_field', wp_unslash($_POST['mhb_extras']));
                foreach ($mhb_extras_post_edit as $ex_id => $val) {
                    $qty = (isset($extras_map[$ex_id]) && $extras_map[$ex_id]['control_type'] === 'quantity') ? intval($val) : ($val == '1' ? 1 : 0);
                    if ($qty > 0)
                        $post_extras[$ex_id] = $qty;
                }
            }

            $calc = \MHB\Core\Pricing::calculate_booking_total($room_id, $check_in, $check_out, $guests, $post_extras, $children_count, $children_ages_raw);
            $tax_data = $calc['tax'] ?? null;

            // Determine payment received status for auto-updating payment fields
            $payment_received = isset($_POST['payment_received']) ? 1 : 0;
            $total_price_edit = isset($_POST['total_price']) ? floatval($_POST['total_price']) : 0;
            $customer_name_edit = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
            $customer_email_edit = isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '';
            $customer_phone_edit = isset($_POST['customer_phone']) ? sanitize_text_field(wp_unslash($_POST['customer_phone'])) : '';
            $admin_notes_edit = isset($_POST['admin_notes']) ? sanitize_textarea_field(wp_unslash($_POST['admin_notes'])) : '';
            $mhb_custom_edit = isset($_POST['mhb_custom']) && is_array($_POST['mhb_custom']) ? array_map('sanitize_text_field', wp_unslash($_POST['mhb_custom'])) : [];

            // Availability Check (excluding current booking)
            $available = \MHB\Core\Pricing::is_room_available((int) $room_id, $check_in, $check_out, (int) $booking_id);
            if (true !== $available) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(I18n::get_label($available)) . '</p></div>';
                $edit_mode = true; // Stay in edit mode
            } else {
                $wpdb->update($tb, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
                    'customer_name' => $customer_name_edit,
                    'customer_email' => $customer_email_edit,
                    'customer_phone' => $customer_phone_edit,
                    'room_id' => $room_id,
                    'check_in' => $check_in,
                    'check_out' => $check_out,
                    'total_price' => $total_price_edit,
                    'discount_amount' => floatval($_POST['discount_amount'] ?? 0),
                    'deposit_amount' => floatval($_POST['deposit_amount'] ?? 0),
                    'deposit_received' => isset($_POST['deposit_received']) ? 1 : 0,
                    'payment_method' => sanitize_key($_POST['payment_method'] ?? 'onsite'),
                    'payment_status' => $payment_received ? 'completed' : sanitize_key($_POST['payment_status'] ?? 'pending'),
                    'payment_received' => $payment_received,
                    'payment_amount' => $payment_received && (!isset($_POST['payment_amount']) || $_POST['payment_amount'] === '')
                        ? $total_price_edit
                        : (isset($_POST['payment_amount']) && $_POST['payment_amount'] !== '' ? floatval($_POST['payment_amount']) : null),
                    'payment_date' => $payment_received && !$was_payment_received ? current_time('mysql') : $existing_payment_date,
                    'status' => $new_status,
                    'booking_language' => sanitize_key($_POST['booking_language'] ?? 'en'),
                    'admin_notes' => $admin_notes_edit,
                    'booking_extras' => !empty($booking_extras) ? json_encode($booking_extras) : null,
                    'guests' => $guests,
                    'children' => $children_count,
                    'children_ages' => !empty($children_ages_raw) ? json_encode($children_ages_raw) : null,
                    'custom_fields' => !empty($mhb_custom_edit) ? json_encode($mhb_custom_edit) : null,
                    // Tax fields
                    'tax_enabled' => ($tax_data && $tax_data['enabled']) ? 1 : 0,
                    'tax_mode' => $tax_data['mode'] ?? 'disabled',
                    'tax_rate_accommodation' => $tax_data['breakdown']['rates']['accommodation'] ?? 0,
                    'tax_rate_extras' => $tax_data['breakdown']['rates']['extras'] ?? 0,
                    'room_total_net' => $tax_data['breakdown']['totals']['room_net'] ?? 0,
                    'room_tax' => $tax_data['breakdown']['totals']['room_tax'] ?? 0,
                    'children_total_net' => $tax_data['breakdown']['totals']['children_net'] ?? 0,
                    'children_tax' => $tax_data['breakdown']['totals']['children_tax'] ?? 0,
                    'extras_total_net' => $tax_data['breakdown']['totals']['extras_net'] ?? 0,
                    'extras_tax' => $tax_data['breakdown']['totals']['extras_tax'] ?? 0,
                    'subtotal_net' => $tax_data['breakdown']['totals']['subtotal_net'] ?? $total_price_edit,
                    'total_tax' => $tax_data['breakdown']['totals']['total_tax'] ?? 0,
                    'total_gross' => $tax_data['breakdown']['totals']['total_gross'] ?? $total_price_edit,
                    'tax_breakdown' => $tax_data ? json_encode($tax_data['breakdown']) : null,
                ), array('id' => $booking_id));

                if ($old_status !== $new_status) {
                    do_action('mhb_booking_status_changed', $booking_id, $new_status);
                    if ('confirmed' === $new_status) {
                        do_action('mhb_booking_confirmed', $booking_id);
                    } elseif ('cancelled' === $new_status) {
                        do_action('mhb_booking_cancelled', $booking_id);
                    }
                }

                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Booking Updated!', 'modern-hotel-booking') . '</p></div>';
                $edit_mode = false;
            }
        }

        $status_filter = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $allowed_statuses = ['pending', 'confirmed', 'cancelled'];
        $status = in_array($status_filter, $allowed_statuses, true) ? $status_filter : '';

        $cache_key = 'mhb_bookings_' . md5($status);
        $bookings = wp_cache_get($cache_key, 'mhb_bookings');

        if (false === $bookings) {
            $sql = "SELECT b.*, r.room_number, t.name as room_type FROM {$wpdb->prefix}mhb_bookings b LEFT JOIN {$wpdb->prefix}mhb_rooms r ON b.room_id = r.id LEFT JOIN {$wpdb->prefix}mhb_room_types t ON r.type_id = t.id";

            if ($status) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, admin-only, caching above
                $bookings = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT b.*, r.room_number, t.name as room_type FROM {$wpdb->prefix}mhb_bookings b LEFT JOIN {$wpdb->prefix}mhb_rooms r ON b.room_id = r.id LEFT JOIN {$wpdb->prefix}mhb_room_types t ON r.type_id = t.id WHERE b.status = %s ORDER BY b.created_at DESC",
                        $status
                    )
                );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, admin-only, caching above
                $bookings = $wpdb->get_results(
                    "SELECT b.*, r.room_number, t.name as room_type FROM {$wpdb->prefix}mhb_bookings b LEFT JOIN {$wpdb->prefix}mhb_rooms r ON b.room_id = r.id LEFT JOIN {$wpdb->prefix}mhb_room_types t ON r.type_id = t.id ORDER BY b.created_at DESC"
                );
            }
            wp_cache_set($cache_key, $bookings, 'mhb_bookings', HOUR_IN_SECONDS);
        }

        $all_rooms = wp_cache_get('mhb_all_rooms', 'mhb_rooms');
        if (false === $all_rooms) {
            $all_rooms = $wpdb->get_results("SELECT r.id, r.room_number, t.name as type_name, t.base_price FROM {$wpdb->prefix}mhb_rooms r JOIN {$wpdb->prefix}mhb_room_types t ON r.type_id = t.id ORDER BY r.room_number ASC"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, admin-only query
            wp_cache_set('mhb_all_rooms', $all_rooms, 'mhb_rooms', HOUR_IN_SECONDS);
        }

        $events = array();
        foreach ($bookings as $b) {
            if ('cancelled' === $b->status)
                continue;
            $events[] = array(
                'title' => 'Room ' . $b->room_number . ' - ' . $b->customer_name,
                'start' => $b->check_in,
                'end' => gmdate('Y-m-d', strtotime($b->check_out . ' +1 day')),
                'color' => 'confirmed' === $b->status ? '#28a745' : '#ffc107',
                'url' => admin_url('admin.php?page=mhb-bookings&action=edit&id=' . $b->id),
            );
        }
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Manage Bookings', 'modern-hotel-booking'); ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=mhb-bookings&action=add')); ?>"
                class="page-title-action"><?php esc_html_e('Add New Booking', 'modern-hotel-booking'); ?></a>
            <hr class="wp-header-end">
            <?php if (isset($_GET['status'])): ?>
                <div class="notice notice-info is-dismissible" style="margin-top:15px;">
                    <p>
                        <?php
                        $status_filter = sanitize_key(wp_unslash($_GET['status']));
                        // translators: %s: booking status label (e.g., Pending, Confirmed)
                        printf(esc_html__('Filtering by status: %s', 'modern-hotel-booking'), '<strong>' . esc_html(ucfirst($status_filter)) . '</strong>'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mhb-bookings')); ?>" class="button button-small"
                            style="margin-left:10px;"><?php esc_html_e('Clear Filter', 'modern-hotel-booking'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($add_mode): ?>
                <div class="mhb-card">
                    <h2><?php esc_html_e('Add Manual Booking', 'modern-hotel-booking'); ?></h2>
                    <form method="post"><?php wp_nonce_field('mhb_add_manual_booking'); ?>
                        <table class="form-table">
                            <!-- 1. Customer Details -->
                            <tr class="mhb-form-section-header">
                                <th colspan="2">
                                    <h3><?php esc_html_e('Customer Details', 'modern-hotel-booking'); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Customer Name', 'modern-hotel-booking'); ?></th>
                                <td><input type="text" name="customer_name" required class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Email', 'modern-hotel-booking'); ?></th>
                                <td><input type="email" name="customer_email" required class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Phone', 'modern-hotel-booking'); ?></th>
                                <td><input type="tel" name="customer_phone" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Guests', 'modern-hotel-booking'); ?></th>
                                <td><input type="number" name="guests" id="mhb_add_guests" value="2" min="1" max="10"
                                        class="small-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Children', 'modern-hotel-booking'); ?></th>
                                <td><input type="number" name="children" id="mhb_add_children" value="0" min="0" max="10"
                                        class="small-text"></td>
                            </tr>
                            <tr id="mhb_add_child_ages_row" style="display:none;">
                                <th><?php esc_html_e('Child Ages', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <div id="mhb_add_child_ages_container"></div>
                                </td>
                            </tr>

                            <!-- Custom Fields -->
                            <?php
                            $custom_fields_defn = get_option('mhb_custom_fields', []);
                            if (!empty($custom_fields_defn)): ?>
                                <tr class="mhb-form-section-header">
                                    <th colspan="2">
                                        <h3><?php esc_html_e('Extra Guest Information', 'modern-hotel-booking'); ?></h3>
                                    </th>
                                </tr>
                                <?php foreach ($custom_fields_defn as $defn):
                                    $label = I18n::decode(I18n::encode($defn['label']));
                                    ?>
                                    <tr>
                                        <th><?php echo esc_html($label); ?></th>
                                        <td>
                                            <?php if ($defn['type'] === 'textarea'): ?>
                                                <textarea name="mhb_custom[<?php echo esc_attr($defn['id']); ?>]" rows="3"
                                                    class="regular-text"></textarea>
                                            <?php else: ?>
                                                <input type="<?php echo esc_attr($defn['type']); ?>"
                                                    name="mhb_custom[<?php echo esc_attr($defn['id']); ?>]" class="regular-text">
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- 2. Room & Dates -->
                            <tr class="mhb-form-section-header">
                                <th colspan="2">
                                    <h3><?php esc_html_e('Room & Dates', 'modern-hotel-booking'); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Room', 'modern-hotel-booking'); ?></th>
                                <td><select name="room_id" id="mhb_add_room_id" required>
                                        <option value=""><?php esc_html_e('-- Select Room --', 'modern-hotel-booking'); ?></option>
                                        <?php foreach ($all_rooms as $rm): ?>
                                            <option value="<?php echo esc_attr($rm->id); ?>"
                                                data-price="<?php echo esc_attr($rm->base_price); ?>">
                                                <?php echo esc_html($rm->room_number . ' (' . I18n::decode($rm->type_name) . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Check-in', 'modern-hotel-booking'); ?></th>
                                <td><input type="date" name="check_in" id="mhb_add_check_in" required></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Check-out', 'modern-hotel-booking'); ?></th>
                                <td><input type="date" name="check_out" id="mhb_add_check_out" required></td>
                            </tr>

                            <!-- 3. Extras & Discounts -->
                            <tr class="mhb-form-section-header">
                                <th colspan="2">
                                    <h3><?php esc_html_e('Extras & Discounts', 'modern-hotel-booking'); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Extras', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <?php
                                    $extras = get_option('mhb_pro_extras', []);
                                    if (!empty($extras)) {
                                        foreach ($extras as $ex) {
                                            $lbl = esc_html($ex['name']) . ' (' . I18n::format_currency($ex['price']) . ')';
                                            $pricing_type = $ex['pricing_type'] ?? 'fixed';
                                            if ($ex['control_type'] === 'quantity') {
                                                echo '<label style="display:block;margin-bottom:5px;"><input type="number" name="mhb_extras[' . esc_attr($ex['id']) . ']" value="0" min="0" style="width:50px;" class="mhb-extra-input" data-extra-id="' . esc_attr($ex['id']) . '" data-price="' . esc_attr($ex['price']) . '" data-pricing-type="' . esc_attr($pricing_type) . '"> ' . esc_html($lbl) . '</label>';
                                            } else {
                                                echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="mhb_extras[' . esc_attr($ex['id']) . ']" value="1" class="mhb-extra-input" data-extra-id="' . esc_attr($ex['id']) . '" data-price="' . esc_attr($ex['price']) . '" data-pricing-type="' . esc_attr($pricing_type) . '"> ' . esc_html($lbl) . '</label>';
                                            }
                                        }
                                    } else {
                                        echo '<span class="description">' . esc_html__('No extras configured.', 'modern-hotel-booking') . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Discount Amount', 'modern-hotel-booking'); ?></th>
                                <td><input type="number" step="0.01" name="discount_amount" id="mhb_add_discount_amount"
                                        value="0.00" class="regular-text"></td>
                            </tr>

                            <!-- 4. Payment Info -->
                            <tr class="mhb-form-section-header">
                                <th colspan="2">
                                    <h3><?php esc_html_e('Payment Info', 'modern-hotel-booking'); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Total Price', 'modern-hotel-booking'); ?></th>
                                <td><input type="number" step="0.01" name="total_price" id="mhb_add_total_price" required
                                        class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Deposit Amount', 'modern-hotel-booking'); ?></th>
                                <td><input type="number" step="0.01" name="deposit_amount" id="mhb_add_deposit_amount" value="0.00"
                                        class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Deposit Received', 'modern-hotel-booking'); ?></th>
                                <td><label><input type="checkbox" name="deposit_received" id="mhb_add_deposit_received" value="1">
                                        <?php esc_html_e('Mark as received', 'modern-hotel-booking'); ?></label></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Payment Method', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <select name="payment_method">
                                        <option value="onsite" selected>
                                            <?php esc_html_e('Onsite / Manual', 'modern-hotel-booking'); ?>
                                        </option>
                                        <?php if (false): ?>
                                            <option value="stripe"><?php esc_html_e('Stripe', 'modern-hotel-booking'); ?></option>
                                            <option value="paypal"><?php esc_html_e('PayPal', 'modern-hotel-booking'); ?></option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Payment Received', 'modern-hotel-booking'); ?></th>
                                <td><label><input type="checkbox" name="payment_received" id="mhb_add_payment_received" value="1">
                                        <?php esc_html_e('Mark full payment as received', 'modern-hotel-booking'); ?></label></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Amount Outstanding', 'modern-hotel-booking'); ?></th>
                                <td><input type="text" id="mhb_add_amount_outstanding" readonly class="regular-text"
                                        style="background:#f0f0f0;"></td>
                            </tr>

                            <!-- 5. Booking Management -->
                            <tr class="mhb-form-section-header">
                                <th colspan="2">
                                    <h3><?php esc_html_e('Booking Management', 'modern-hotel-booking'); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Status', 'modern-hotel-booking'); ?></th>
                                <td><select name="status">
                                        <option value="pending"><?php esc_html_e('Pending', 'modern-hotel-booking'); ?></option>
                                        <option value="confirmed" selected><?php esc_html_e('Confirmed', 'modern-hotel-booking'); ?>
                                        </option>
                                    </select></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Language', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <select name="booking_language">
                                        <?php foreach (I18n::get_available_languages() as $lang): ?>
                                            <option value="<?php echo esc_attr($lang); ?>" <?php selected($lang, I18n::get_current_language()); ?>><?php echo esc_html(strtoupper($lang)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Admin Notes', 'modern-hotel-booking'); ?></th>
                                <td><textarea name="admin_notes" rows="3" class="large-text"></textarea></td>
                            </tr>
                        </table>
                        <p><input type="submit" name="submit_manual_booking" class="button button-primary"
                                value="<?php esc_attr_e('Add Booking', 'modern-hotel-booking'); ?>">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhb-bookings')); ?>"
                                class="button"><?php esc_html_e('Cancel', 'modern-hotel-booking'); ?></a>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($edit_mode && $edit_data): ?>
                <div class="mhb-card">
                    <h2><?php
                    // translators: %d: booking ID number
                    printf(esc_html__('Edit Booking #%d', 'modern-hotel-booking'), (int) $edit_data->id); ?>
                    </h2>
                    <form method="post"><?php wp_nonce_field('mhb_update_booking'); ?>
                        <input type="hidden" name="booking_id" value="<?php echo esc_attr($edit_data->id); ?>">
                        <table class="form-table">
                            <!-- 1. Customer Details -->
                            <tr class="mhb-form-section-header">
                                <th colspan="2">
                                    <h3><?php esc_html_e('Customer Details', 'modern-hotel-booking'); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Customer Name', 'modern-hotel-booking'); ?></th>
                                <td><input type="text" name="customer_name"
                                        value="<?php echo esc_attr($edit_data->customer_name); ?>" required class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Email', 'modern-hotel-booking'); ?></th>
                                <td><input type="email" name="customer_email"
                                        value="<?php echo esc_attr($edit_data->customer_email); ?>" required class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Phone', 'modern-hotel-booking'); ?></th>
                                <td><input type="tel" name="customer_phone"
                                        value="<?php echo esc_attr($edit_data->customer_phone ?? ''); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Guests', 'modern-hotel-booking'); ?></th>
                                <td><input type="number" name="guests" id="mhb_edit_guests"
                                        value="<?php echo esc_attr($edit_data->guests ?? 2); ?>" min="1" max="10"
                                        class="small-text">
                                </td>
                            </tr>
                            <?php
                            $edit_children = intval($edit_data->children ?? 0);
                            $edit_children_ages = !empty($edit_data->children_ages) ? json_decode($edit_data->children_ages, true) : [];
                            if (!is_array($edit_children_ages))
                                $edit_children_ages = [];
                            ?>
                            <tr>
                                <th><?php esc_html_e('Children', 'modern-hotel-booking'); ?></th>
                                <td><input type="number" name="children" id="mhb_edit_children"
                                        value="<?php echo esc_attr((string) $edit_children); ?>" min="0" max="10"
                                        class="small-text">
                                </td>
                            </tr>
                            <tr id="mhb_edit_child_ages_row" style="<?php echo $edit_children > 0 ? '' : 'display:none;'; ?>">
                                <th><?php esc_html_e('Child Ages', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <div id="mhb_edit_child_ages_container">
                                        <?php for ($ca = 0; $ca < $edit_children; $ca++): ?>
                                            <label style="display:inline-block; margin-right:10px; margin-bottom:5px;">
                                                <?php
                                                // translators: %d: child number (1-indexed)
                                                printf(esc_html__('Child %d:', 'modern-hotel-booking'), (int) ($ca + 1)); ?>
                                                <input type="number" name="child_ages[]"
                                                    value="<?php echo esc_attr($edit_children_ages[$ca] ?? 0); ?>" min="0" max="17"
                                                    style="width:60px;">
                                            </label>
                                        <?php endfor; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- Custom Fields -->
                            <?php
                            $custom_fields_defn = get_option('mhb_custom_fields', []);
                            $saved_custom = !empty($edit_data->custom_fields) ? json_decode($edit_data->custom_fields, true) : [];
                            if (!empty($custom_fields_defn)): ?>
                                <tr class="mhb-form-section-header">
                                    <th colspan="2">
                                        <h3><?php esc_html_e('Extra Guest Information', 'modern-hotel-booking'); ?></h3>
                                    </th>
                                </tr>
                                <?php foreach ($custom_fields_defn as $defn):
                                    $label = I18n::decode(I18n::encode($defn['label']));
                                    $val = $saved_custom[$defn['id']] ?? '';
                                    ?>
                                    <tr>
                                        <th><?php echo esc_html($label); ?></th>
                                        <td>
                                            <?php if ($defn['type'] === 'textarea'): ?>
                                                <textarea name="mhb_custom[<?php echo esc_attr($defn['id']); ?>]" rows="3"
                                                    class="regular-text"><?php echo esc_textarea($val); ?></textarea>
                                            <?php else: ?>
                                                <input type="<?php echo esc_attr($defn['type']); ?>"
                                                    name="mhb_custom[<?php echo esc_attr($defn['id']); ?>]"
                                                    value="<?php echo esc_attr($val); ?>" class="regular-text">
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- 2. Room & Dates -->
                            <tr class="mhb-form-section-header">
                                <th colspan="2">
                                    <h3><?php esc_html_e('Room & Dates', 'modern-hotel-booking'); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Room', 'modern-hotel-booking'); ?></th>
                                <td><select name="room_id" id="mhb_edit_room_id"><?php foreach ($all_rooms as $rm): ?>
                                            <option value="<?php echo esc_attr($rm->id); ?>"
                                                data-price="<?php echo esc_attr($rm->base_price); ?>" <?php selected($edit_data->room_id, $rm->id); ?>>
                                                <?php echo esc_html($rm->room_number . ' (' . I18n::decode($rm->type_name) . ')'); ?>
                                            </option><?php endforeach; ?>
                                    </select></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Check-in', 'modern-hotel-booking'); ?></th>
                                <td><input type="date" name="check_in" id="mhb_edit_check_in"
                                        value="<?php echo esc_attr($edit_data->check_in); ?>" required></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Check-out', 'modern-hotel-booking'); ?></th>
                                <td><input type="date" name="check_out" id="mhb_edit_check_out"
                                        value="<?php echo esc_attr($edit_data->check_out); ?>" required></td>
                            </tr>

                            <!-- 3. Extras & Discounts -->
                            <tr class="mhb-form-section-header">
                                <th colspan="2">
                                    <h3><?php esc_html_e('Extras & Discounts', 'modern-hotel-booking'); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Extras', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <?php
                                    $extras = get_option('mhb_pro_extras', []);
                                    $saved_extras = !empty($edit_data->booking_extras) ? json_decode($edit_data->booking_extras, true) : [];
                                    $saved_map = [];
                                    if (is_array($saved_extras)) {
                                        foreach ($saved_extras as $se)
                                            $saved_map[$se['name']] = $se['quantity'];
                                    }

                                    if (!empty($extras)) {
                                        foreach ($extras as $ex) {
                                            $extra_name = I18n::decode($ex['name'] ?? '');
                                            $lbl = esc_html($extra_name) . ' (' . I18n::format_currency($ex['price']) . ')';
                                            $qty = $saved_map[$ex['name']] ?? 0;
                                            $pricing_type = $ex['pricing_type'] ?? 'fixed';

                                            if ($ex['control_type'] === 'quantity') {
                                                echo '<label style="display:block;margin-bottom:5px;"><input type="number" name="mhb_extras[' . esc_attr($ex['id']) . ']" value="' . esc_attr($qty) . '" min="0" style="width:50px;" class="mhb-extra-input" data-extra-id="' . esc_attr($ex['id']) . '" data-price="' . esc_attr($ex['price']) . '" data-pricing-type="' . esc_attr($pricing_type) . '"> ' . esc_html($lbl) . '</label>';
                                            } else {
                                                echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="mhb_extras[' . esc_attr($ex['id']) . ']" value="1" ' . checked($qty > 0, true, false) . ' class="mhb-extra-input" data-extra-id="' . esc_attr($ex['id']) . '" data-price="' . esc_attr($ex['price']) . '" data-pricing-type="' . esc_attr($pricing_type) . '"> ' . esc_html($lbl) . '</label>';
                                            }
                                        }
                                    } else {
                                        echo '<span class="description">' . esc_html__('No extras configured.', 'modern-hotel-booking') . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Discount Amount', 'modern-hotel-booking'); ?></th>
                                <td><input type="number" step="0.01" name="discount_amount" id="mhb_edit_discount_amount"
                                        value="<?php echo esc_attr($edit_data->discount_amount ?? '0.00'); ?>" class="regular-text">
                                </td>
                            </tr>

                            <!-- 4. Payment Info -->
                            <tr class="mhb-form-section-header">
                                <th colspan="2">
                                    <h3><?php esc_html_e('Payment Info', 'modern-hotel-booking'); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Total Price', 'modern-hotel-booking'); ?></th>
                                <td><input type="number" step="0.01" name="total_price" id="mhb_edit_total_price"
                                        value="<?php echo esc_attr($edit_data->total_price); ?>" required class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Deposit Amount', 'modern-hotel-booking'); ?></th>
                                <td><input type="number" step="0.01" name="deposit_amount" id="mhb_edit_deposit_amount"
                                        value="<?php echo esc_attr($edit_data->deposit_amount ?? '0.00'); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Deposit Received', 'modern-hotel-booking'); ?></th>
                                <td><label><input type="checkbox" name="deposit_received" id="mhb_edit_deposit_received" value="1"
                                            <?php checked($edit_data->deposit_received ?? 0, 1); ?>>
                                        <?php esc_html_e('Mark as received', 'modern-hotel-booking'); ?></label></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Payment Method', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <select name="payment_method">
                                        <option value="onsite" <?php selected($edit_data->payment_method ?? 'onsite', 'onsite'); ?>>
                                            <?php esc_html_e('Onsite / Manual', 'modern-hotel-booking'); ?>
                                        </option>
                                        <?php if (false): ?>
                                            <option value="stripe" <?php selected($edit_data->payment_method ?? '', 'stripe'); ?>>
                                                <?php esc_html_e('Stripe', 'modern-hotel-booking'); ?>
                                            </option>
                                            <option value="paypal" <?php selected($edit_data->payment_method ?? '', 'paypal'); ?>>
                                                <?php esc_html_e('PayPal', 'modern-hotel-booking'); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Payment Status', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <select name="payment_status">
                                        <option value="pending" <?php selected($edit_data->payment_status ?? 'pending', 'pending'); ?>>
                                            <?php esc_html_e('Pending', 'modern-hotel-booking'); ?>
                                        </option>
                                        <option value="processing" <?php selected($edit_data->payment_status ?? '', 'processing'); ?>>
                                            <?php esc_html_e('Processing', 'modern-hotel-booking'); ?>
                                        </option>
                                        <option value="completed" <?php selected($edit_data->payment_status ?? '', 'completed'); ?>>
                                            <?php esc_html_e('Completed', 'modern-hotel-booking'); ?>
                                        </option>
                                        <option value="failed" <?php selected($edit_data->payment_status ?? '', 'failed'); ?>>
                                            <?php esc_html_e('Failed', 'modern-hotel-booking'); ?>
                                        </option>
                                        <option value="refunded" <?php selected($edit_data->payment_status ?? '', 'refunded'); ?>>
                                            <?php esc_html_e('Refunded', 'modern-hotel-booking'); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Payment Received', 'modern-hotel-booking'); ?></th>
                                <td><label><input type="checkbox" name="payment_received" id="mhb_edit_payment_received" value="1"
                                            <?php checked($edit_data->payment_received ?? 0, 1); ?>>
                                        <?php esc_html_e('Mark full payment as received', 'modern-hotel-booking'); ?></label></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Transaction ID', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <input type="text" name="payment_transaction_id" class="regular-text"
                                        value="<?php echo esc_attr($edit_data->payment_transaction_id ?? ''); ?>" readonly>
                                    <p class="description">
                                        <?php esc_html_e('Transaction ID from payment gateway (read-only).', 'modern-hotel-booking'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Payment Amount', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <input type="number" step="0.01" name="payment_amount" class="regular-text"
                                        value="<?php echo esc_attr($edit_data->payment_amount ?? ''); ?>">
                                    <p class="description">
                                        <?php esc_html_e('Actual amount paid (may differ from total).', 'modern-hotel-booking'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Payment Date', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <input type="text" name="payment_date" class="regular-text" readonly
                                        value="<?php echo esc_attr($edit_data->payment_date ?? ''); ?>">
                                    <p class="description">
                                        <?php esc_html_e('Date/time payment was completed (read-only).', 'modern-hotel-booking'); ?>
                                    </p>
                                </td>
                            </tr>
                            <?php if (!empty($edit_data->payment_error)): ?>
                                <tr>
                                    <th><?php esc_html_e('Payment Error', 'modern-hotel-booking'); ?></th>
                                    <td>
                                        <textarea name="payment_error" rows="2" class="large-text"
                                            readonly><?php echo esc_textarea($edit_data->payment_error); ?></textarea>
                                        <p class="description">
                                            <?php esc_html_e('Error message from failed payment.', 'modern-hotel-booking'); ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <th><?php esc_html_e('Amount Outstanding', 'modern-hotel-booking'); ?></th>
                                <td><input type="text" id="mhb_edit_amount_outstanding" readonly class="regular-text"
                                        style="background:#f0f0f0;"></td>
                            </tr>

                            <?php
                            if (!empty($edit_data->tax_breakdown)) {
                                $tax_data = json_decode($edit_data->tax_breakdown, true);
                                if ($tax_data && ($tax_data['enabled'] ?? false)) {
                                    $tax_label = \MHB\Core\Tax::get_label();
                                    ?>
                                    <tr class="mhb-form-section-header">
                                        <th colspan="2">
                                            <h3><?php
                                            // translators: %s: tax or fee label (e.g., Tax, VAT)
                                            echo esc_html(sprintf(__('%s Breakdown', 'modern-hotel-booking'), $tax_label)); ?>
                                            </h3>
                                        </th>
                                    </tr>
                                    <tr>
                                        <td colspan="2">
                                            <?php
                                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method returns sanitized HTML
                                            echo \MHB\Core\Tax::render_breakdown_html($tax_data);
                                            ?>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            }
                            ?>

                            <!-- 5. Booking Management -->
                            <tr class="mhb-form-section-header">
                                <th colspan="2">
                                    <h3><?php esc_html_e('Booking Management', 'modern-hotel-booking'); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Status', 'modern-hotel-booking'); ?></th>
                                <td><select name="status">
                                        <option value="pending" <?php selected($edit_data->status, 'pending'); ?>>
                                            <?php esc_html_e('Pending', 'modern-hotel-booking'); ?>
                                        </option>
                                        <option value="confirmed" <?php selected($edit_data->status, 'confirmed'); ?>>
                                            <?php esc_html_e('Confirmed', 'modern-hotel-booking'); ?>
                                        </option>
                                        <option value="cancelled" <?php selected($edit_data->status, 'cancelled'); ?>>
                                            <?php esc_html_e('Cancelled', 'modern-hotel-booking'); ?>
                                        </option>
                                    </select></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Language', 'modern-hotel-booking'); ?></th>
                                <td>
                                    <select name="booking_language">
                                        <?php foreach (I18n::get_available_languages() as $lang): ?>
                                            <option value="<?php echo esc_attr($lang); ?>" <?php selected($edit_data->booking_language ?? 'en', $lang); ?>><?php echo esc_html(strtoupper($lang)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Admin Notes', 'modern-hotel-booking'); ?></th>
                                <td><textarea name="admin_notes" rows="3"
                                        class="large-text"><?php echo esc_textarea($edit_data->admin_notes ?? ''); ?></textarea>
                                </td>
                            </tr>
                        </table>
                        <p><input type="submit" name="submit_booking_update" class="button button-primary"
                                value="<?php esc_attr_e('Update Booking', 'modern-hotel-booking'); ?>">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhb-bookings')); ?>"
                                class="button"><?php esc_html_e('Cancel', 'modern-hotel-booking'); ?></a>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhb-bookings&action=delete&id={$edit_data->id}"), 'mhb_delete_booking_' . $edit_data->id)); ?>"
                                class="button button-link-delete mhb-delete-action" style="margin-left: 10px;"
                                data-confirm="<?php echo esc_attr__('Are you sure you want to delete this booking? This action cannot be undone.', 'modern-hotel-booking'); ?>">
                                <?php esc_html_e('Delete Booking', 'modern-hotel-booking'); ?>
                            </a>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <div id="mhb-calendar" style="background:#fff;padding:20px;margin-top:20px;"></div>
            <?php
            // Note: Price calculation and child ages JavaScript logic has been moved to assets/js/mhb-admin-bookings.js
            // Pass calendar events for FullCalendar initialization
            $calendar_config = array(
                'events' => $events,
            );
            wp_add_inline_script('mhb-admin-bookings', 'window.mhbCalendarConfig = ' . wp_json_encode($calendar_config) . ';', 'before');
            ?>

            <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Guest', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Room', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Dates', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Total', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Status', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Payment', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Lang', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Actions', 'modern-hotel-booking'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($bookings)):
                        foreach ($bookings as $bk):
                            $sc = 'mhb-status-' . esc_attr($bk->status);
                            ?>
                            <tr>
                                <td data-colname="<?php esc_attr_e('ID', 'modern-hotel-booking'); ?>">#<?php echo esc_html($bk->id); ?>
                                </td>
                                <td data-colname="<?php esc_attr_e('Guest', 'modern-hotel-booking'); ?>">
                                    <strong><?php echo esc_html($bk->customer_name); ?></strong><br><?php echo esc_html($bk->customer_email); ?><?php if (!empty($bk->customer_phone))
                                              echo '<br>' . esc_html($bk->customer_phone); ?>
                                </td>
                                <td data-colname="<?php esc_attr_e('Room', 'modern-hotel-booking'); ?>">
                                    <?php echo esc_html($bk->room_number); ?><br><small><?php echo esc_html(I18n::decode($bk->room_type)); ?></small>
                                </td>
                                <td data-colname="<?php esc_attr_e('Dates', 'modern-hotel-booking'); ?>">
                                    <?php echo esc_html($bk->check_in . ' → ' . $bk->check_out); ?>
                                </td>
                                <td data-colname="<?php esc_attr_e('Total', 'modern-hotel-booking'); ?>"><?php
                                   echo esc_html(I18n::format_currency($bk->total_price));
                                   // Check if full payment received first
                                   if ($bk->payment_received ?? 0) {
                                       // Full payment received - no outstanding balance
                                       echo '<br><small style="color:#00a32a;">' . esc_html__('Paid in full', 'modern-hotel-booking') . '</small>';
                                   } elseif (($bk->deposit_received ?? 0) && ($bk->deposit_amount ?? 0) > 0) {
                                       // Deposit only - show remaining balance
                                       $outstanding = $bk->total_price - $bk->deposit_amount;
                                       // translators: %s: outstanding balance amount (formatted currency)
                                       echo '<br><small style="color:#d63638;">' . sprintf(esc_html__('Outstanding: %s', 'modern-hotel-booking'), esc_html(I18n::format_currency($outstanding))) . '</small>';
                                   } elseif (!($bk->deposit_received ?? 0) && ($bk->total_price > 0)) {
                                       // No payment - show full amount outstanding
                                       // translators: %s: outstanding balance amount (formatted currency)
                                       echo '<br><small style="color:#d63638;">' . sprintf(esc_html__('Outstanding: %s', 'modern-hotel-booking'), esc_html(I18n::format_currency($bk->total_price))) . '</small>';
                                   }
                                   ?></td>
                                <td data-colname="<?php esc_attr_e('Status', 'modern-hotel-booking'); ?>"><span
                                        class="mhb-status-badge mhb-status-<?php echo esc_attr($bk->status); ?>">
                                        <?php echo esc_html(I18n::translate_status($bk->status)); ?></span>
                                </td>
                                <td data-colname="<?php esc_attr_e('Payment', 'modern-hotel-booking'); ?>">
                                    <small><?php
                                    $method_label = '';
                                    switch ($bk->payment_method ?? 'onsite') {
                                        case 'onsite':
                                            $method_label = __('Onsite / Manual', 'modern-hotel-booking');
                                            break;
                                        case 'stripe':
                                            $method_label = __('Stripe', 'modern-hotel-booking');
                                            break;
                                        case 'paypal':
                                            $method_label = __('PayPal', 'modern-hotel-booking');
                                            break;
                                        default:
                                            $method_label = ucfirst($bk->payment_method ?? 'onsite');
                                    }
                                    echo esc_html($method_label); ?></small>
                                    <?php
                                    // Display payment status badge
                                    $payment_status = $bk->payment_status ?? 'pending';
                                    $payment_status_colors = array(
                                        'pending' => '#f0ad4e',
                                        'processing' => '#5bc0de',
                                        'completed' => '#28a745',
                                        'failed' => '#d63638',
                                        'refunded' => '#6c757d',
                                    );
                                    $payment_color = $payment_status_colors[$payment_status] ?? '#f0ad4e';
                                    ?>
                                    <br><small class="mhb-payment-status-badge"
                                        style="color:<?php echo esc_attr($payment_color); ?>; font-weight:bold; text-transform:uppercase;">
                                        <?php
                                        $status_p_label = '';
                                        switch ($payment_status) {
                                            case 'pending':
                                                $status_p_label = __('Pending', 'modern-hotel-booking');
                                                break;
                                            case 'processing':
                                                $status_p_label = __('Processing', 'modern-hotel-booking');
                                                break;
                                            case 'completed':
                                                $status_p_label = __('Completed', 'modern-hotel-booking');
                                                break;
                                            case 'failed':
                                                $status_p_label = __('Failed', 'modern-hotel-booking');
                                                break;
                                            case 'refunded':
                                                $status_p_label = __('Refunded', 'modern-hotel-booking');
                                                break;
                                            default:
                                                $status_p_label = ucfirst($payment_status);
                                        }
                                        echo esc_html($status_p_label); ?>
                                    </small>
                                    <?php if (!empty($bk->payment_transaction_id)): ?>
                                        <br><small title="<?php esc_attr_e('Transaction ID', 'modern-hotel-booking'); ?>">TX:
                                            <?php echo esc_html(substr($bk->payment_transaction_id, 0, 12)); ?>...</small>
                                    <?php endif; ?>
                                </td>
                                <td data-colname="<?php esc_attr_e('Lang', 'modern-hotel-booking'); ?>"><span
                                        class="mhb-lang-badge"><?php echo esc_html(strtoupper($bk->booking_language ?? 'en')); ?></span>
                                </td>
                                <td data-colname="<?php esc_attr_e('Actions', 'modern-hotel-booking'); ?>">
                                    <a href="<?php echo esc_url(admin_url("admin.php?page=mhb-bookings&action=edit&id={$bk->id}")); ?>"
                                        class="button"><?php esc_html_e('Edit', 'modern-hotel-booking'); ?></a>
                                    <?php if ('pending' === $bk->status): ?>
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhb-bookings&action=confirm&id={$bk->id}"), 'mhb_confirm_booking_' . $bk->id)); ?>"
                                            class="button button-primary"><?php esc_html_e('Confirm', 'modern-hotel-booking'); ?></a>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhb-bookings&action=cancel&id={$bk->id}"), 'mhb_cancel_booking_' . $bk->id)); ?>"
                                        class="button"><?php esc_html_e('Cancel', 'modern-hotel-booking'); ?></a>
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhb-bookings&action=delete&id={$bk->id}"), 'mhb_delete_booking_' . $bk->id)); ?>"
                                        class="button button-link-delete mhb-confirm-delete"
                                        data-confirm="<?php esc_attr_e('Delete this booking?', 'modern-hotel-booking'); ?>"><?php esc_html_e('Delete', 'modern-hotel-booking'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach;
                    else: ?>
                        <tr>
                            <td colspan="9"><?php esc_html_e('No bookings found.', 'modern-hotel-booking'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function display_room_types_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'modern-hotel-booking'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'mhb_room_types';
        $edit_mode = false;
        $edit_data = null;

        // Delete Action
        if (isset($_GET['action']) && 'delete' === $_GET['action'] && isset($_GET['id'])) {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'mhb_delete_room_type_' . absint($_GET['id']))) {
                wp_die(esc_html__('Security check failed.', 'modern-hotel-booking'));
            }
            $wpdb->delete($table, array('id' => absint($_GET['id']))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Room Type Deleted.', 'modern-hotel-booking') . '</p></div>';
        }

        if (isset($_GET['action']) && 'edit' === $_GET['action'] && isset($_GET['id'])) {
            $edit_mode = true;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix, admin-only query
            $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", absint($_GET['id'])));
        }

        if (isset($_POST['submit_room_type']) && check_admin_referer('mhb_add_room_type')) {
            $amenities = isset($_POST['amenities']) && is_array($_POST['amenities']) ? wp_json_encode(array_map('sanitize_text_field', wp_unslash($_POST['amenities']))) : '';
            $room_name = isset($_POST['room_name']) ? (is_array($_POST['room_name']) ? I18n::encode(array_map('sanitize_text_field', wp_unslash($_POST['room_name']))) : sanitize_text_field(wp_unslash($_POST['room_name']))) : '';
            $room_desc = isset($_POST['room_description']) ? (is_array($_POST['room_description']) ? I18n::encode(array_map('sanitize_textarea_field', wp_unslash($_POST['room_description']))) : sanitize_textarea_field(wp_unslash($_POST['room_description']))) : '';
            $base_price = isset($_POST['base_price']) ? floatval($_POST['base_price']) : 0;
            $max_adults = isset($_POST['max_adults']) ? absint($_POST['max_adults']) : 1;
            $image_url = isset($_POST['image_url']) ? esc_url_raw(wp_unslash($_POST['image_url'])) : '';
            $data = array(
                'name' => $room_name,
                'description' => $room_desc,
                'base_price' => $base_price,
                'max_adults' => $max_adults,
                'max_children' => absint($_POST['max_children'] ?? 0),
                'child_age_free_limit' => absint($_POST['child_age_free_limit'] ?? 0),
                'child_rate' => floatval($_POST['child_rate'] ?? 0),
                'amenities' => $amenities,
                'image_url' => $image_url,
            );
            if (!empty($_POST['room_type_id'])) {
                $wpdb->update($table, $data, array('id' => absint($_POST['room_type_id']))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Room Type Updated!', 'modern-hotel-booking') . '</p></div>';
                $edit_mode = false;
            } else {
                $wpdb->insert($table, $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Room Type Added!', 'modern-hotel-booking') . '</p></div>';
            }
        }



        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix, admin-only query
        $types = $wpdb->get_results("SELECT * FROM `{$table}`");
        $current_amenities = ($edit_mode && !empty($edit_data->amenities)) ? json_decode($edit_data->amenities, true) : array();
        if (!is_array($current_amenities))
            $current_amenities = array();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Manage Room Types', 'modern-hotel-booking'); ?></h1>
            <div class="card" style="padding:20px;margin-top:20px;">
                <h2><?php echo $edit_mode ? esc_html__('Edit Room Type', 'modern-hotel-booking') : esc_html__('Add New Type', 'modern-hotel-booking'); ?>
                </h2>
                <form method="post"><?php wp_nonce_field('mhb_add_room_type'); ?>
                    <?php if ($edit_mode): ?><input type="hidden" name="room_type_id"
                            value="<?php echo esc_attr($edit_data->id); ?>"><?php endif; ?>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Name', 'modern-hotel-booking'); ?></th>
                            <td>
                                <?php if ($edit_mode): ?>
                                    <?php foreach (I18n::get_available_languages() as $lang): ?>
                                        <div style="margin-bottom:5px;">
                                            <strong><?php echo esc_html(strtoupper($lang)); ?>:</strong><br>
                                            <input type="text" name="room_name[<?php echo esc_attr($lang); ?>]"
                                                value="<?php echo esc_attr(I18n::decode($edit_data->name, $lang)); ?>"
                                                class="regular-text">
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <input type="text" name="room_name" required class="regular-text">
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Description', 'modern-hotel-booking'); ?></th>
                            <td>
                                <?php if ($edit_mode): ?>
                                    <?php foreach (I18n::get_available_languages() as $lang): ?>
                                        <div style="margin-bottom:10px;">
                                            <strong><?php echo esc_html(strtoupper($lang)); ?>:</strong><br>
                                            <textarea name="room_description[<?php echo esc_attr($lang); ?>]" rows="3"
                                                class="large-text"><?php echo esc_textarea(I18n::decode($edit_data->description, $lang)); ?></textarea>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <textarea name="room_description" rows="3" class="large-text"></textarea>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Base Price', 'modern-hotel-booking'); ?></th>
                            <td><input type="number" step="0.01" name="base_price"
                                    value="<?php echo $edit_mode ? esc_attr($edit_data->base_price) : ''; ?>" required
                                    class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Max Adults', 'modern-hotel-booking'); ?></th>
                            <td><input type="number" name="max_adults"
                                    value="<?php echo $edit_mode ? esc_attr($edit_data->max_adults) : '2'; ?>"
                                    class="small-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Max Children', 'modern-hotel-booking'); ?></th>
                            <td><input type="number" name="max_children"
                                    value="<?php echo $edit_mode ? esc_attr($edit_data->max_children ?? 0) : '0'; ?>"
                                    class="small-text" min="0"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Child Free Age Limit', 'modern-hotel-booking'); ?></th>
                            <td>
                                <input type="number" name="child_age_free_limit"
                                    value="<?php echo $edit_mode ? esc_attr($edit_data->child_age_free_limit ?? 0) : '0'; ?>"
                                    class="small-text" min="0">
                                <p class="description">
                                    <?php esc_html_e('Children at or under this age stay free.', 'modern-hotel-booking'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Child Rate (per night)', 'modern-hotel-booking'); ?></th>
                            <td><input type="number" step="0.01" name="child_rate"
                                    value="<?php echo $edit_mode ? esc_attr($edit_data->child_rate ?? '0.00') : '0.00'; ?>"
                                    class="regular-text" min="0"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Image URL', 'modern-hotel-booking'); ?></th>
                            <td><input type="text" name="image_url"
                                    value="<?php echo $edit_mode ? esc_attr($edit_data->image_url) : ''; ?>" class="large-text">
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Amenities', 'modern-hotel-booking'); ?></th>
                            <td>
                                <?php
                                $amenities_list = get_option('mhb_amenities_list', [
                                    'wifi' => __('Free WiFi', 'modern-hotel-booking'),
                                    'ac' => __('Air Conditioning', 'modern-hotel-booking'),
                                    'tv' => __('Smart TV', 'modern-hotel-booking'),
                                    'breakfast' => __('Breakfast Included', 'modern-hotel-booking'),
                                    'pool' => __('Pool View', 'modern-hotel-booking')
                                ]);
                                if (!is_array($amenities_list)) {
                                    $amenities_list = [];
                                }
                                foreach ($amenities_list as $key => $lbl): ?>
                                    <label><input type="checkbox" name="amenities[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $current_amenities, true)); ?>>
                                        <?php echo esc_html($lbl); ?></label><br>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    </table>
                    <p><input type="submit" name="submit_room_type" class="button button-primary"
                            value="<?php echo $edit_mode ? esc_attr__('Update Room Type', 'modern-hotel-booking') : esc_attr__('Add Room Type', 'modern-hotel-booking'); ?>">
                        <?php if ($edit_mode): ?><a href="<?php echo esc_url(admin_url('admin.php?page=mhb-room-types')); ?>"
                                class="button"><?php esc_html_e('Cancel', 'modern-hotel-booking'); ?></a><?php endif; ?></p>
                </form>
            </div>
            <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th style="width:50px;"><?php esc_html_e('ID', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Image', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Name', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Description', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Price', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Amenities', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Actions', 'modern-hotel-booking'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($types as $t): ?>
                        <tr>
                            <td><?php echo esc_html($t->id); ?></td>
                            <td><?php echo $t->image_url ? '<img src="' . esc_url($t->image_url) . '" width="50" style="border-radius:4px;">' : esc_html__('No Image', 'modern-hotel-booking'); ?>
                            </td>
                            <td><?php echo esc_html(I18n::decode($t->name)); ?></td>
                            <td><?php echo esc_html(wp_trim_words(I18n::decode($t->description), 10)); ?></td>
                            <td><?php echo esc_html(I18n::format_currency($t->base_price)); ?></td>
                            <td><?php
                            if (!empty($t->amenities)) {
                                $ams_array = json_decode($t->amenities);
                                if (is_array($ams_array)) {
                                    $all_amenities = get_option('mhb_amenities_list', [
                                        'wifi' => __('Free WiFi', 'modern-hotel-booking'),
                                        'ac' => __('Air Conditioning', 'modern-hotel-booking'),
                                        'tv' => __('Smart TV', 'modern-hotel-booking'),
                                        'breakfast' => __('Breakfast Included', 'modern-hotel-booking'),
                                        'pool' => __('Pool View', 'modern-hotel-booking')
                                    ]);
                                    $display_ams = [];
                                    foreach ($ams_array as $k) {
                                        $display_ams[] = isset($all_amenities[$k]) ? $all_amenities[$k] : $k;
                                    }
                                    echo esc_html(implode(', ', $display_ams));
                                } else {
                                    esc_html_e('None', 'modern-hotel-booking');
                                }
                            } else {
                                esc_html_e('None', 'modern-hotel-booking');
                            }
                            ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url("admin.php?page=mhb-room-types&action=edit&id={$t->id}")); ?>"
                                    class="button"><?php esc_html_e('Edit', 'modern-hotel-booking'); ?></a>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhb-room-types&action=delete&id={$t->id}"), 'mhb_delete_room_type_' . $t->id)); ?>"
                                    class="button button-link-delete"
                                    onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this room type? This may affect existing rooms.', 'modern-hotel-booking'); ?>')"><?php esc_html_e('Delete', 'modern-hotel-booking'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function display_rooms_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'modern-hotel-booking'));
        }

        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix
        $t_rooms = $wpdb->prefix . 'mhb_rooms';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix
        $t_types = $wpdb->prefix . 'mhb_room_types';
        // Use new table if it exists, fallback to legacy table
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix
        $new_ical_table = $wpdb->prefix . 'mhb_ical_connections';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix
        $legacy_ical_table = $wpdb->prefix . 'mhb_ical_feeds';
        $new_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $new_ical_table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix
        $t_ical = $new_exists ? $new_ical_table : $legacy_ical_table;
        $edit_mode = false;
        $edit_data = null;
        $ical_mode = false;
        $ical_feeds = array();

        // Delete Action
        if (isset($_GET['action']) && 'delete' === $_GET['action'] && isset($_GET['id']) && !isset($_GET['sub_action'])) {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'mhb_delete_room_' . absint($_GET['id']))) {
                wp_die(esc_html__('Security check failed.', 'modern-hotel-booking'));
            }
            $wpdb->delete($t_rooms, array('id' => absint($_GET['id']))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Room Deleted.', 'modern-hotel-booking') . '</p></div>';
        }

        if (isset($_GET['action']) && 'ical' === $_GET['action'] && isset($_GET['id'])) {
            $room_id = absint($_GET['id']);
            if (!false) {
                ?>
                <div class="wrap">
                    <h1><?php esc_html_e('Manage Rooms', 'modern-hotel-booking'); ?></h1>
                    <?php \MHB\Core\License::render_upsell_notice(__('iCal Synchronization', 'modern-hotel-booking')); ?>
                    <p style="margin-top: 20px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mhb-rooms')); ?>" class="button">&larr;
                            <?php esc_html_e('Back to Rooms', 'modern-hotel-booking'); ?></a>
                    </p>
                </div>
                <?php
                return;
            }
            $ical_mode = true;
            if (isset($_POST['submit_ical_feed']) && check_admin_referer('mhb_add_ical')) {
                // Insert with appropriate columns based on table version
                $feed_name = isset($_POST['feed_name']) ? sanitize_text_field(wp_unslash($_POST['feed_name'])) : '';
                $feed_url = isset($_POST['feed_url']) ? esc_url_raw(wp_unslash($_POST['feed_url'])) : '';
                if ($new_exists) {
                    $wpdb->insert($t_ical, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
                        'room_id' => $room_id,
                        'name' => $feed_name,
                        'ical_url' => $feed_url,
                        'platform' => 'custom',
                        'sync_direction' => 'import',
                        'sync_status' => 'pending',
                        'created_at' => current_time('mysql'),
                    ));
                } else {
                    $wpdb->insert($t_ical, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
                        'room_id' => $room_id,
                        'feed_name' => $feed_name,
                        'feed_url' => $feed_url,
                    ));
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Feed Added!', 'modern-hotel-booking') . '</p></div>';
            }
            if (isset($_GET['sub_action']) && 'delete_feed' === $_GET['sub_action'] && isset($_GET['feed_id'])) {
                check_admin_referer('mhb_delete_feed_' . absint($_GET['feed_id']));
                $wpdb->delete($t_ical, array('id' => absint($_GET['feed_id']))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
            }
            if (isset($_GET['sub_action']) && 'sync_now' === $_GET['sub_action']) {
                check_admin_referer('mhb_sync_now_' . $room_id);
                \MHB\Core\ICal::sync_external_calendars();
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sync Completed.', 'modern-hotel-booking') . '</p></div>';
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix, admin-only query
            $ical_feeds = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$t_ical}` WHERE room_id = %d", $room_id));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix, admin-only query
            $room_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$t_rooms}` WHERE id = %d", $room_id));
        }

        if (isset($_GET['action']) && 'edit' === $_GET['action'] && isset($_GET['id'])) {
            $edit_mode = true;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix, admin-only query
            $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$t_rooms}` WHERE id = %d", absint($_GET['id'])));
        }

        if (isset($_POST['submit_room']) && check_admin_referer('mhb_add_room')) {
            $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : 0;
            $room_number = isset($_POST['room_number']) ? sanitize_text_field(wp_unslash($_POST['room_number'])) : '';
            $room_status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'available';
            $data = array(
                'type_id' => $type_id,
                'room_number' => $room_number,
                'custom_price' => !empty($_POST['custom_price']) ? floatval($_POST['custom_price']) : null,
                'status' => $room_status,
            );
            if (!empty($_POST['room_id'])) {
                $wpdb->update($t_rooms, $data, array('id' => absint($_POST['room_id']))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Room Updated!', 'modern-hotel-booking') . '</p></div>';
                $edit_mode = false;
            } else {
                $wpdb->insert($t_rooms, $data); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Room Added!', 'modern-hotel-booking') . '</p></div>';
            }
        }



        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely constructed from $wpdb->prefix, admin-only query
        $rooms = $wpdb->get_results("SELECT r.*, t.name as type_name, t.base_price FROM `{$t_rooms}` r LEFT JOIN `{$t_types}` t ON r.type_id = t.id ORDER BY r.room_number ASC");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix, admin-only query
        $types = $wpdb->get_results("SELECT * FROM `{$t_types}`");
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Manage Rooms', 'modern-hotel-booking'); ?></h1>

            <?php if ($ical_mode && isset($room_info)): ?>
                <div class="card mhb-ical-card" style="padding:20px;margin-bottom:20px;">
                    <h2><?php
                    // translators: %s: room number
                    printf(esc_html__('iCal Sync — Room %s', 'modern-hotel-booking'), esc_html($room_info->room_number)); ?>
                    </h2>
                    <h3><?php esc_html_e('Export URL', 'modern-hotel-booking'); ?></h3>
                    <p><input type="text"
                            value="<?php echo esc_url(site_url('?mhb_action=ical_export&room_id=' . $room_info->id . '&token=' . get_option('mhb_ical_token'))); ?>"
                            class="large-text" readonly onclick="this.select()"></p>
                    <h3><?php esc_html_e('Import Feeds', 'modern-hotel-booking'); ?></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Name', 'modern-hotel-booking'); ?></th>
                                <th><?php esc_html_e('URL', 'modern-hotel-booking'); ?></th>
                                <th><?php esc_html_e('Last Synced', 'modern-hotel-booking'); ?></th>
                                <th><?php esc_html_e('Actions', 'modern-hotel-booking'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ical_feeds as $feed): ?>
                                <?php
                                // Handle both legacy and new table column names
                                $feed_name = $feed->feed_name ?? $feed->name ?? '';
                                $feed_url = $feed->feed_url ?? $feed->ical_url ?? '';
                                $last_sync = $feed->last_synced ?? $feed->last_sync ?? '';
                                ?>
                                <tr>
                                    <td><?php echo esc_html($feed_name); ?></td>
                                    <td><input type="text" value="<?php echo esc_url($feed_url); ?>" readonly style="width:100%">
                                    </td>
                                    <td><?php echo esc_html($last_sync ?: __('Never', 'modern-hotel-booking')); ?></td>
                                    <td><a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhb-rooms&action=ical&id={$room_info->id}&sub_action=delete_feed&feed_id={$feed->id}"), 'mhb_delete_feed_' . $feed->id)); ?>"
                                            class="button button-link-delete mhb-confirm-delete"
                                            data-confirm="<?php esc_attr_e('Delete?', 'modern-hotel-booking'); ?>"><?php esc_html_e('Delete', 'modern-hotel-booking'); ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <h4><?php esc_html_e('Add New Feed', 'modern-hotel-booking'); ?></h4>
                    <form method="post"><?php wp_nonce_field('mhb_add_ical'); ?>
                        <p><input type="text" name="feed_name"
                                placeholder="<?php esc_attr_e('Name (e.g. Airbnb)', 'modern-hotel-booking'); ?>" required>
                            <input type="url" name="feed_url" placeholder="<?php esc_attr_e('iCal URL', 'modern-hotel-booking'); ?>"
                                required style="width:300px;">
                            <input type="submit" name="submit_ical_feed" class="button button-primary"
                                value="<?php esc_attr_e('Add Feed', 'modern-hotel-booking'); ?>">
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhb-rooms&action=ical&id={$room_info->id}&sub_action=sync_now"), 'mhb_sync_now_' . $room_info->id)); ?>"
                                class="button"><?php esc_html_e('Sync All Now', 'modern-hotel-booking'); ?></a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhb-rooms')); ?>"
                                class="button"><?php esc_html_e('Back', 'modern-hotel-booking'); ?></a>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <div class="card" style="padding:20px;margin-top:20px;">
                <h2><?php echo $edit_mode ? esc_html__('Edit Room', 'modern-hotel-booking') : esc_html__('Add Room', 'modern-hotel-booking'); ?>
                </h2>
                <form method="post"><?php wp_nonce_field('mhb_add_room'); ?>
                    <?php if ($edit_mode): ?><input type="hidden" name="room_id"
                            value="<?php echo esc_attr($edit_data->id); ?>"><?php endif; ?>
                    <p><label><?php esc_html_e('Type', 'modern-hotel-booking'); ?></label><br><select
                            name="type_id"><?php foreach ($types as $t): ?>
                                <option value="<?php echo esc_attr($t->id); ?>" <?php echo ($edit_mode && $edit_data->type_id == $t->id) ? 'selected' : ''; ?>>
                                    <?php echo esc_html(I18n::decode($t->name)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select></p>
                    <p><label><?php esc_html_e('Number/Name', 'modern-hotel-booking'); ?></label><br><input type="text"
                            name="room_number" value="<?php echo $edit_mode ? esc_attr($edit_data->room_number) : ''; ?>"
                            required class="regular-text"></p>
                    <p><label><?php esc_html_e('Custom Price', 'modern-hotel-booking'); ?></label><br><input type="number"
                            step="0.01" name="custom_price"
                            value="<?php echo $edit_mode ? esc_attr($edit_data->custom_price) : ''; ?>" class="regular-text">
                    </p>
                    <p><label><?php esc_html_e('Status', 'modern-hotel-booking'); ?></label><br><select name="status">
                            <option value="available" <?php echo ($edit_mode && 'available' === $edit_data->status) ? 'selected' : ''; ?>><?php esc_html_e('Available', 'modern-hotel-booking'); ?></option>
                            <option value="maintenance" <?php echo ($edit_mode && 'maintenance' === $edit_data->status) ? 'selected' : ''; ?>><?php esc_html_e('Maintenance', 'modern-hotel-booking'); ?></option>
                        </select></p>
                    <p><input type="submit" name="submit_room" class="button button-primary"
                            value="<?php echo $edit_mode ? esc_attr__('Update Room', 'modern-hotel-booking') : esc_attr__('Add Room', 'modern-hotel-booking'); ?>">
                        <?php if ($edit_mode): ?><a href="<?php echo esc_url(admin_url('admin.php?page=mhb-rooms')); ?>"
                                class="button"><?php esc_html_e('Cancel', 'modern-hotel-booking'); ?></a><?php endif; ?></p>
                </form>
            </div>
            <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th style="width:50px;"><?php esc_html_e('ID', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Number', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Type', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Price', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Status', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Actions', 'modern-hotel-booking'); ?></th>
                    </tr>
                </thead>
                <tbody><?php foreach ($rooms as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r->id); ?></td>
                            <td><?php echo esc_html($r->room_number); ?></td>
                            <td><?php echo esc_html(I18n::decode($r->type_name)); ?></td>
                            <td><?php echo esc_html(I18n::format_currency($r->custom_price ?: $r->base_price)); ?></td>
                            <td><?php echo esc_html(ucfirst($r->status)); ?></td>
                            <td><a href="<?php echo esc_url(admin_url("admin.php?page=mhb-rooms&action=edit&id={$r->id}")); ?>"
                                    class="button"><?php esc_html_e('Edit', 'modern-hotel-booking'); ?></a>
                                <?php if (false): ?>
                                    <a href="<?php echo esc_url(admin_url("admin.php?page=mhb-rooms&action=ical&id={$r->id}")); ?>"
                                        class="button"
                                        style="border-color:#0073aa;color:#0073aa;"><?php esc_html_e('iCal Sync', 'modern-hotel-booking'); ?></a>
                                <?php else: ?>
                                    <a href="<?php echo esc_url(admin_url("admin.php?page=mhb-rooms&action=ical&id={$r->id}")); ?>"
                                        class="button" style="border-color:#dcdcde;color:#a7aaad; opacity: 0.7;"
                                        title="<?php esc_attr_e('Pro Feature', 'modern-hotel-booking'); ?>">
                                        <span class="dashicons dashicons-lock"
                                            style="font-size: 14px; width: 14px; height: 14px; line-height: 20px;"></span>
                                        <?php esc_html_e('iCal Sync', 'modern-hotel-booking'); ?>
                                    </a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhb-rooms&action=delete&id={$r->id}"), 'mhb_delete_room_' . $r->id)); ?>"
                                    class="button button-link-delete mhb-confirm-delete"
                                    data-confirm="<?php esc_attr_e('Delete?', 'modern-hotel-booking'); ?>"><?php esc_html_e('Delete', 'modern-hotel-booking'); ?></a>
                            </td>
                        </tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function display_pricing_rules_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'modern-hotel-booking'));
        }

        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix
        $table = $wpdb->prefix . 'mhb_pricing_rules';

        if (isset($_POST['submit_pricing']) && check_admin_referer('mhb_add_pricing')) {
            $rule_name = isset($_POST['rule_name']) ? sanitize_text_field(wp_unslash($_POST['rule_name'])) : '';
            $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
            $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
            $price_modifier = isset($_POST['price_modifier']) ? floatval($_POST['price_modifier']) : 0;
            $modifier_type = isset($_POST['modifier_type']) ? sanitize_key(wp_unslash($_POST['modifier_type'])) : 'fixed';
            $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : 0;
            $wpdb->insert($table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
                'name' => $rule_name,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'amount' => $price_modifier,
                'operation' => $modifier_type,
                'type_id' => $type_id,
            ));
        }

        if (isset($_GET['action']) && 'delete' === $_GET['action'] && isset($_GET['id'])) {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'mhb_delete_pricing_' . absint($_GET['id']))) {
                wp_die(esc_html__('Security check failed.', 'modern-hotel-booking'));
            }
            $wpdb->delete($table, array('id' => absint($_GET['id']))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix, admin-only query
        $rules = $wpdb->get_results("SELECT * FROM `{$table}`");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix, admin-only query
        $types = $wpdb->get_results("SELECT * FROM `{$wpdb->prefix}mhb_room_types`");
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pricing Rules', 'modern-hotel-booking'); ?></h1>
            <form method="post" class="card" style="padding:20px;"><?php wp_nonce_field('mhb_add_pricing'); ?>
                <p><label><?php esc_html_e('Name', 'modern-hotel-booking'); ?></label> <input type="text" name="rule_name"
                        required></p>
                <p><label><?php esc_html_e('Dates', 'modern-hotel-booking'); ?></label> <input type="date" name="start_date"
                        required> <?php esc_html_e('to', 'modern-hotel-booking'); ?> <input type="date" name="end_date"
                        required></p>
                <p><label><?php esc_html_e('Modifier', 'modern-hotel-booking'); ?></label> <input type="number" step="0.01"
                        name="price_modifier" required> <select name="modifier_type">
                        <option value="fixed"><?php esc_html_e('Fixed', 'modern-hotel-booking'); ?></option>
                        <option value="percent">%</option>
                    </select></p>
                <p><label><?php esc_html_e('Type', 'modern-hotel-booking'); ?></label> <select name="type_id">
                        <option value="0"><?php esc_html_e('All', 'modern-hotel-booking'); ?></option>
                        <?php foreach ($types as $t)
                            echo '<option value="' . esc_attr($t->id) . '">' . esc_html(I18n::decode($t->name)) . '</option>'; ?>
                    </select></p>
                <input type="submit" name="submit_pricing" class="button button-primary"
                    value="<?php esc_attr_e('Add Rule', 'modern-hotel-booking'); ?>">
            </form>
            <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Dates', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Modifier', 'modern-hotel-booking'); ?></th>
                        <th><?php esc_html_e('Action', 'modern-hotel-booking'); ?></th>
                    </tr>
                </thead>
                <tbody><?php foreach ($rules as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r->name); ?></td>
                            <td><?php echo esc_html($r->start_date . ' → ' . $r->end_date); ?></td>
                            <td><?php echo esc_html($r->amount . ('percent' === $r->operation ? '%' : '')); ?></td>
                            <td><a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=mhb-pricing-rules&action=delete&id={$r->id}"), 'mhb_delete_pricing_' . $r->id)); ?>"
                                    class="button button-link-delete"
                                    onclick="return confirm('<?php esc_attr_e('Delete this pricing rule?', 'modern-hotel-booking'); ?>')"><?php esc_html_e('Delete', 'modern-hotel-booking'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    public function display_extras_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'modern-hotel-booking'));
        }

        // Handle Form Submission
        if (isset($_POST['mhb_save_extras']) && check_admin_referer('mhb_save_extras_action')) {
            $new_extras = [];
            if (isset($_POST['extras']) && is_array($_POST['extras'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization performed per-field below
                $extras_data = wp_unslash($_POST['extras']);
                foreach ($extras_data as $ex) {
                    // Skip if required fields are missing
                    if (!isset($ex['name'], $ex['price'], $ex['pricing_type'], $ex['control_type'])) {
                        continue;
                    }
                    // Sanitize all fields
                    $name = sanitize_text_field($ex['name']);
                    if (empty($name)) {
                        continue;
                    }
                    $new_extras[] = [
                        'id' => !empty($ex['id']) ? sanitize_text_field($ex['id']) : uniqid('extra_'),
                        'name' => $name,
                        'price' => floatval($ex['price']),
                        'pricing_type' => sanitize_key($ex['pricing_type']),
                        'control_type' => sanitize_key($ex['control_type']),
                        'description' => isset($ex['description']) ? sanitize_textarea_field($ex['description']) : ''
                    ];
                }
            }
            update_option('mhb_pro_extras', $new_extras);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Extras Saved!', 'modern-hotel-booking') . '</p></div>';
        }

        $extras = get_option('mhb_pro_extras', []);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Booking Extras & Add-ons', 'modern-hotel-booking'); ?></h1>
            <form method="post">
                <?php wp_nonce_field('mhb_save_extras_action'); ?>
                <div id="mhb-extras-list">
                    <?php
                    if (!empty($extras)) {
                        foreach ($extras as $index => $extra) {
                            $this->render_extra_item($index, $extra);
                        }
                    } else {
                        // Empty state or initial item
                        // $this->render_extra_item(0, []);
                    }
                    ?>
                </div>
                <button type="button" class="button"
                    id="mhb-add-extra"><?php esc_html_e('+ Add New Extra', 'modern-hotel-booking'); ?></button>
                <hr>
                <input type="submit" name="mhb_save_extras" class="button button-primary"
                    value="<?php esc_attr_e('Save Changes', 'modern-hotel-booking'); ?>">
            </form>

            <script type="text/template" id="tmpl-mhb-extra">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <div class="mhb-extra-item card" style="padding:15px; margin-bottom:15px; background:#f9f9f9;">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <button type="button" class="notice-dismiss mhb-remove-extra" style="position:absolute; right:10px; top:10px;"></button>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <input type="hidden" name="extras[{{index}}][id]" value="{{id}}">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <table class="form-table" style="margin:0;">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <th><?php esc_html_e('Name', 'modern-hotel-booking'); ?></th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <td><input type="text" name="extras[{{index}}][name]" value="{{name}}" class="regular-text" required></td>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        </tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <th><?php esc_html_e('Price', 'modern-hotel-booking'); ?></th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <td><input type="number" step="0.01" name="extras[{{index}}][price]" value="{{price}}" class="small-text" required></td>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        </tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <th><?php esc_html_e('Pricing Model', 'modern-hotel-booking'); ?></th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <td>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <select name="extras[{{index}}][pricing_type]">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <option value="fixed" {{selected_fixed}}><?php esc_html_e('Fixed Price (Once)', 'modern-hotel-booking'); ?></option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <option value="per_person" {{selected_pp}}><?php esc_html_e('Per Person', 'modern-hotel-booking'); ?></option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <option value="per_night" {{selected_pn}}><?php esc_html_e('Per Night', 'modern-hotel-booking'); ?></option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <option value="per_person_per_night" {{selected_pppn}}><?php esc_html_e('Per Person / Per Night', 'modern-hotel-booking'); ?></option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                </select>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            </td>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        </tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        <tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <th><?php esc_html_e('Control Type', 'modern-hotel-booking'); ?></th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <td>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                <select name="extras[{{index}}][control_type]">
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <option value="checkbox" {{selected_checkbox}}><?php esc_html_e('Checkbox (Yes/No)', 'modern-hotel-booking'); ?></option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    <option value="quantity" {{selected_quantity}}><?php esc_html_e('Quantity Input', 'modern-hotel-booking'); ?></option>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                </select>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            </td>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        </tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         <tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <th><?php esc_html_e('Description', 'modern-hotel-booking'); ?></th>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <td><textarea name="extras[{{index}}][description]" rows="2" class="large-text">{{description}}</textarea></td>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        </tr>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    </table>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                </div>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             </script>
            <?php
            // Note: Extras management JavaScript logic has been moved to assets/js/mhb-admin-bookings.js
            // Pass extras count for the script
            wp_add_inline_script('mhb-admin-bookings', 'window.mhbExtrasCount = ' . count($extras) . ';', 'before');
            ?>
        </div>
        <?php
    }

    private function render_extra_item($index, $extra)
    {
        $id = esc_attr($extra['id'] ?? '');
        $name = esc_attr($extra['name'] ?? '');
        $price = esc_attr($extra['price'] ?? '');
        $desc = esc_textarea($extra['description'] ?? '');
        $pt = $extra['pricing_type'] ?? 'fixed';
        $ct = $extra['control_type'] ?? 'checkbox';
        ?>
        <div class="mhb-extra-item card" style="padding:15px; margin-bottom:15px; background:#f9f9f9;">
            <button type="button" class="notice-dismiss mhb-remove-extra"
                style="position:absolute; right:10px; top:10px;"></button>
            <input type="hidden" name="extras[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($id); ?>">
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><?php esc_html_e('Name', 'modern-hotel-booking'); ?></th>
                    <td><input type="text" name="extras[<?php echo esc_attr($index); ?>][name]"
                            value="<?php echo esc_attr($name); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Price', 'modern-hotel-booking'); ?></th>
                    <td><input type="number" step="0.01" name="extras[<?php echo esc_attr($index); ?>][price]"
                            value="<?php echo esc_attr($price); ?>" class="small-text" required></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Pricing Model', 'modern-hotel-booking'); ?></th>
                    <td>
                        <select name="extras[<?php echo esc_attr($index); ?>][pricing_type]">
                            <option value="fixed" <?php selected($pt, 'fixed'); ?>>
                                <?php esc_html_e('Fixed Price (Once)', 'modern-hotel-booking'); ?>
                            </option>
                            <option value="per_person" <?php selected($pt, 'per_person'); ?>>
                                <?php esc_html_e('Per Person', 'modern-hotel-booking'); ?>
                            </option>
                            <option value="per_night" <?php selected($pt, 'per_night'); ?>>
                                <?php esc_html_e('Per Night', 'modern-hotel-booking'); ?>
                            </option>
                            <option value="per_person_per_night" <?php selected($pt, 'per_person_per_night'); ?>>
                                <?php esc_html_e('Per Person / Per Night', 'modern-hotel-booking'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Control Type', 'modern-hotel-booking'); ?></th>
                    <td>
                        <select name="extras[<?php echo esc_attr($index); ?>][control_type]">
                            <option value="checkbox" <?php selected($ct, 'checkbox'); ?>>
                                <?php esc_html_e('Checkbox (Yes/No)', 'modern-hotel-booking'); ?>
                            </option>
                            <option value="quantity" <?php selected($ct, 'quantity'); ?>>
                                <?php esc_html_e('Quantity Input', 'modern-hotel-booking'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Description', 'modern-hotel-booking'); ?></th>
                    <td><textarea name="extras[<?php echo esc_attr($index); ?>][description]" rows="2"
                            class="large-text"><?php echo esc_textarea($desc); ?></textarea></td>
                </tr>
            </table>
        </div>
        <?php
    }
}
