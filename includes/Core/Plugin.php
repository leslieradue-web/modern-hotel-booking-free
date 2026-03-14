<?php declare(strict_types=1);

namespace MHBO\Core;

if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Admin\Menu;
use MHBO\Admin\Settings;
use MHBO\Frontend\Shortcode;
use MHBO\Frontend\Calendar;
use MHBO\Frontend\BookingWidget;
use MHBO\Frontend\Block;
use MHBO\Api\RestApi;
use MHBO\Core\Privacy;
// Note: Webhook and LicenseValidator are Pro-only and conditionally loaded below

class Plugin
{

    public function __construct()
    {
        // Translations are auto-loaded by WordPress for plugins hosted on WordPress.org since WP 4.6.
    }

    /**
     * Wire up all hooks and load subsystems.
     */
    public function run()
    {
        // Initialize I18n filters
        I18n::init();

        /* ---- iCal Export (public endpoint) ---- */
        

        if (!wp_next_scheduled('mhbo_hourly_sync')) {
            wp_schedule_event(time(), 'hourly', 'mhbo_hourly_sync');
        }
        /* BUILD_PRO_END */

        /* ---- REST API ---- */
        add_action('rest_api_init', function () {
            $api = new RestApi();
            $api->register_routes();
        });

        /* ---- Webhook System (Pro-only) ---- */
        

        /* ---- GDPR / Privacy ---- */
        $privacy = new Privacy();
        $privacy->init();

        /* ---- Block Editor support ---- */
        $block = new Block();
        $block->init();

        if (is_admin()) {
            $this->load_admin();
        } else {
            $this->load_frontend();
        }

        // Always register AJAX handlers (needed because is_admin() is true for AJAX)
        $calendar = new Calendar();
        $calendar->init();

        // Initialize License Validator for periodic revalidation (Pro-only)
        

        // Register Widget (Must be global to show in Admin > Widgets)
        add_action('widgets_init', function () {
            register_widget(BookingWidget::class);
        });

        add_filter('plugin_action_links_' . MHBO_PLUGIN_BASENAME, function ($links) {
            $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=mhbo-settings')) . '">' . esc_html__('Settings', 'modern-hotel-booking') . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        });

        $this->schedule_cron();
    }

    private function load_admin()
    {
        $menu = new Menu();
        $menu->init();
    }

    private function load_frontend()
    {
        $shortcode = new Shortcode();
        $shortcode->init();
    }

    /**
     * Schedule daily maintenance cron job.
     */
    private function schedule_cron()
    {
        if (!wp_next_scheduled('mhbo_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'mhbo_daily_maintenance');
        }
    }
}
