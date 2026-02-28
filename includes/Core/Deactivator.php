<?php declare(strict_types=1);

namespace MHB\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deactivator class for plugin deactivation tasks.
 *
 * Handles cleanup of scheduled events, transients, and rewrite rules
 * when the plugin is deactivated.
 *
 * @package MHB\Core
 * @since   2.2.3
 */
class Deactivator
{
    /**
     * Run deactivation tasks.
     */
    public static function deactivate(): void
    {
        // Clear all scheduled cron events
        self::clear_scheduled_crons();

        // Clear plugin transients
        self::clear_transients();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Clear all scheduled cron events for this plugin.
     */
    private static function clear_scheduled_crons(): void
    {
        $crons = array(
            'mhb_hourly_sync',
            'mhb_daily_maintenance',
            'mhb_ical_scheduled_sync',
        );

        foreach ($crons as $cron_hook) {
            $timestamp = wp_next_scheduled($cron_hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $cron_hook);
            }
            // Clear any remaining scheduled events
            wp_clear_scheduled_hook($cron_hook);
        }
    }

    /**
     * Clear plugin transients (rate limiting, caching, etc.).
     */
    private static function clear_transients(): void
    {
        global $wpdb;

        // Delete all MHB transients (rate limits, cache, locks, etc.)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deactivation cleanup, patterns are hardcoded
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_mhb_%' 
             OR option_name LIKE '_site_transient_mhb_%'"
        );
    }
}
