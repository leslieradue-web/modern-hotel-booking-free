<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Modern_Hotel_Booking
 */

// If uninstall not called from WordPress, exit.
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if we should save data on uninstall (default to 1 for safety)
$save_data = get_option('mhb_save_data_on_uninstall', 1);

if ($save_data) {
    // Only clear scheduled cron events, keep data and tables
    $cron_hooks = array(
        'mhb_hourly_sync',
        'mhb_daily_maintenance',
        'mhb_ical_scheduled_sync',
    );

    foreach ($cron_hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }
    return;  // Let script complete normally for WordPress
}

global $wpdb;

// Drop custom tables
$tables = array(
    $wpdb->prefix . 'mhb_room_types',
    $wpdb->prefix . 'mhb_rooms',
    $wpdb->prefix . 'mhb_bookings',
    $wpdb->prefix . 'mhb_ical_feeds',
    $wpdb->prefix . 'mhb_ical_connections',
    $wpdb->prefix . 'mhb_pricing_rules',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
}

// Delete ALL mhb_ options (comprehensive cleanup)
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Cleanup query, hardcoded strings
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mhb_%'"
);

// Clear all scheduled cron events
$cron_hooks = array(
    'mhb_hourly_sync',
    'mhb_daily_maintenance',
    'mhb_ical_scheduled_sync',
);

foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// Clear any remaining single events for iCal retries
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Cleanup query, hardcoded strings
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mhb_%' OR option_name LIKE '_site_transient_mhb_%'"
);
