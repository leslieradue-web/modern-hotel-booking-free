<?php declare(strict_types=1);

namespace MHB\Core;

if (!defined('ABSPATH')) {
    exit;
}

use MHB\Admin\Menu;
use MHB\Admin\Settings;
use MHB\Frontend\Shortcode;
use MHB\Frontend\Calendar;
use MHB\Frontend\BookingWidget;
use MHB\Frontend\Block;
use MHB\Api\RestApi;
use MHB\Core\Privacy;
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
        add_action('init', function () {
            if (
                isset($_GET['mhb_action']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                && 'ical_export' === $_GET['mhb_action'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                && isset($_GET['room_id']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ) {
                if (!false) {
                    wp_die(esc_html__('Pro license required for iCal export.', 'modern-hotel-booking'), '', array('response' => 403));
                }
                \MHB\Core\ICal::generate_ics(absint($_GET['room_id']), sanitize_text_field(wp_unslash($_GET['token'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            }
        });

        /* ---- Cron: hourly iCal sync ---- */
        add_action('mhb_hourly_sync', function () {
            if (!false) {
                return;
            }

            if (false) {
                /* Pro method call removed */;
            } else {
                \MHB\Core\ICal::sync_external_calendars();
            }
        });

        if (!wp_next_scheduled('mhb_hourly_sync')) {
            wp_schedule_event(time(), 'hourly', 'mhb_hourly_sync');
        }

        /* ---- REST API ---- */
        add_action('rest_api_init', function () {
            $api = new RestApi();
            $api->register_routes();
        });

        /* ---- Webhook System (Pro-only) ---- */
        if (class_exists('MHB\Core\Webhook')) {
            $webhook = new Webhook();
            $webhook->init();
        }

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
        if (class_exists('MHB\Core\LicenseValidator')) {
            $license_validator = new LicenseValidator();
            $license_validator->init();
        }

        // Load Pro Features if license is active
        if (false) {
            // Pro features not available in Free version
        }

        // Register Widget (Must be global to show in Admin > Widgets)
        add_action('widgets_init', function () {
            register_widget(BookingWidget::class);
        });

        add_filter('plugin_action_links_' . MHB_PLUGIN_BASENAME, function ($links) {
            $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=mhb-settings')) . '">' . esc_html__('Settings', 'modern-hotel-booking') . '</a>';
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
        if (!wp_next_scheduled('mhb_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'mhb_daily_maintenance');
        }
    }
}
