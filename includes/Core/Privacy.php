<?php declare(strict_types=1);

namespace MHB\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles GDPR Data Export and Erasure Requests
 */
class Privacy
{
    /**
     * Initialize GDPR hooks.
     */
    public function init()
    {
        // Add Privacy Policy content
        add_action('admin_init', function () {
            if (function_exists('wp_add_privacy_policy_content')) {
                $content = '<h2>' . __('Modern Hotel Booking', 'modern-hotel-booking') . '</h2>';
                $content .= '<p>' . __('This plugin collects the following personal data from guests during the booking process:', 'modern-hotel-booking') . '</p>';
                $content .= '<ul>';
                $content .= '<li>' . __('Name', 'modern-hotel-booking') . '</li>';
                $content .= '<li>' . __('Email Address', 'modern-hotel-booking') . '</li>';
                $content .= '<li>' . __('Phone Number', 'modern-hotel-booking') . '</li>';
                $content .= '<li>' . __('Booking Dates and Details', 'modern-hotel-booking') . '</li>';
                $content .= '</ul>';
                $content .= '<p>' . __('This data is stored in the database for the purpose of managing reservations and contacting guests regarding their stay.', 'modern-hotel-booking') . '</p>';

                wp_add_privacy_policy_content('Modern Hotel Booking', $content);
            }
        });

        // Register Exporters and Erasers
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_gdpr_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_gdpr_eraser']);
    }

    public function register_gdpr_exporter($exporters)
    {
        $exporters['modern-hotel-booking'] = array(
            'exporter_friendly_name' => __('Modern Hotel Booking', 'modern-hotel-booking'),
            'callback' => [$this, 'export_personal_data'],
        );
        return $exporters;
    }

    public function register_gdpr_eraser($erasers)
    {
        $erasers['modern-hotel-booking'] = array(
            'eraser_friendly_name' => __('Modern Hotel Booking', 'modern-hotel-booking'),
            'callback' => [$this, 'erase_personal_data'],
        );
        return $erasers;
    }

    /**
     * Export Personal Data for a given email address.
     *
     * @param string $email_address
     * @param int    $page
     * @return array
     */
    public static function export_personal_data($email_address, $page = 1)
    {
        $number = 500; // Limit per page
        $page = (int) $page;

        $export_items = array();
        global $wpdb;

        $cache_key = 'mhb_gdpr_export_' . md5($email_address . '_' . $page);
        $bookings = wp_cache_get($cache_key, 'mhb_privacy');

        if (false === $bookings) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, direct lookup, caching implemented above
            $bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mhb_bookings 
                 WHERE customer_email = %s 
                 ORDER BY check_in DESC 
                 LIMIT %d, %d",
                $email_address,
                ($page - 1) * $number,
                $number
            ));
            wp_cache_set($cache_key, $bookings, 'mhb_privacy', HOUR_IN_SECONDS);
        }

        foreach ($bookings as $booking) {
            $data = array(
                array(
                    'name' => __('Booking ID', 'modern-hotel-booking'),
                    'value' => $booking->id,
                ),
                array(
                    'name' => __('Room ID', 'modern-hotel-booking'),
                    'value' => $booking->room_id,
                ),
                array(
                    'name' => __('Check-in Date', 'modern-hotel-booking'),
                    'value' => $booking->check_in,
                ),
                array(
                    'name' => __('Check-out Date', 'modern-hotel-booking'),
                    'value' => $booking->check_out,
                ),
                array(
                    'name' => __('Total Price', 'modern-hotel-booking'),
                    'value' => $booking->total_price,
                ),
                array(
                    'name' => __('Status', 'modern-hotel-booking'),
                    'value' => $booking->status,
                ),
                array(
                    'name' => __('Customer Name', 'modern-hotel-booking'),
                    'value' => $booking->customer_name,
                ),
                array(
                    'name' => __('Customer Phone', 'modern-hotel-booking'),
                    'value' => $booking->customer_phone,
                ),
            );

            $export_items[] = array(
                'group_id' => 'mhb-bookings',
                'group_label' => __('Hotel Bookings', 'modern-hotel-booking'),
                'item_id' => "mhb-booking-{$booking->id}",
                'data' => $data,
            );
        }

        $done = (is_array($bookings) ? count($bookings) : 0) < $number;

        return array(
            'data' => $export_items,
            'done' => $done,
        );
    }

    /**
     * Erase Personal Data for a given email address.
     *
     * @param string $email_address
     * @param int    $page
     * @return array
     */
    public static function erase_personal_data($email_address, $page = 1)
    {
        $number = 500; // Limit per page
        $page = (int) $page;
        $items_removed = false;
        $items_retained = false;
        $messages = array();

        global $wpdb;

        // Find bookings to anonymize
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, direct lookup
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}mhb_bookings 
             WHERE customer_email = %s 
             LIMIT %d, %d",
            $email_address,
            ($page - 1) * $number,
            $number
        ));

        foreach ($bookings as $booking) {
            // Anonymize data instead of deleting the row to keep financial records
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, GDPR erasure
            $updated = $wpdb->update(
                $wpdb->prefix . 'mhb_bookings',
                array(
                    'customer_name' => '[Deleted]',
                    'customer_email' => 'deleted@site.invalid',
                    'customer_phone' => '[Deleted]',
                ),
                array('id' => $booking->id),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($updated) {
                \MHB\Core\Cache::invalidate_booking($booking->id);
                $items_removed = true;
            } else {
                $items_retained = true;
            }
        }

        $done = (is_array($bookings) ? count($bookings) : 0) < $number;

        return array(
            'items_removed' => $items_removed,
            'items_retained' => $items_retained,
            'messages' => $messages,
            'done' => $done,
        );
    }

    /**
     * Helper to anonymize a single booking by ID.
     * 
     * @param int $booking_id
     * @return bool
     */
    public static function erase_personal_data_by_id($booking_id)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, GDPR erasure
        $result = (bool) $wpdb->update(
            $wpdb->prefix . 'mhb_bookings',
            array(
                'customer_name' => '[Anonymized]',
                'customer_email' => 'deleted@site.invalid',
                'customer_phone' => '[Anonymized]',
                'booking_extras' => null, // Clear Pro extras too for GDPR
            ),
            array('id' => $booking_id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($result) {
            \MHB\Core\Cache::invalidate_booking($booking_id);
        }

        return $result;
    }
}
