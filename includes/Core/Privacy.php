<?php declare(strict_types=1);

namespace MHBO\Core;
use MHBO\Core\Cache;

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
    public function init(): void
    {
        // Add Privacy Policy content
        add_action('admin_init', function () {
            if (function_exists('wp_add_privacy_policy_content')) {
                $content = '<h2>' . __('Modern Hotel Booking', 'modern-hotel-booking') . '</h2>';
                $content .= '<p>' . __('This plugin collects and displays the following information:', 'modern-hotel-booking') . '</p>';
                $content .= '<h3>' . __('Guest Data', 'modern-hotel-booking') . '</h3>';
                $content .= '<ul>';
                $content .= '<li>' . __('Name, Email, and Phone Number', 'modern-hotel-booking') . '</li>';
                $content .= '<li>' . __('Booking Dates and Room Preferences', 'modern-hotel-booking') . '</li>';
                $content .= '</ul>';
                $content .= '<h3>' . __('Business Information', 'modern-hotel-booking') . '</h3>';
                $content .= '<p>' . __('The site administrator may configure business details (Company Name, Address, Tax ID) and payment information (Bank Transfer IBAN, Revolut Tag) to be displayed on the frontend and in booking emails.', 'modern-hotel-booking') . '</p>';
                $content .= '<p>' . __('This data is stored in the WordPress options table and is used solely for facilitation of the booking process.', 'modern-hotel-booking') . '</p>';

                wp_add_privacy_policy_content('Modern Hotel Booking', $content);
            }
        });

        // Register Exporters and Erasers
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_gdpr_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_gdpr_eraser']);
    }

    public function register_gdpr_exporter(array $exporters): array
    {
        $exporters['modern-hotel-booking'] = [
            'exporter_friendly_name' => __('Modern Hotel Booking', 'modern-hotel-booking'),
            'callback' => [$this, 'export_personal_data'],
        ];
        return $exporters;
    }

    public function register_gdpr_eraser(array $erasers): array
    {
        $erasers['modern-hotel-booking'] = [
            'eraser_friendly_name' => __('Modern Hotel Booking', 'modern-hotel-booking'),
            'callback' => [$this, 'erase_personal_data'],
        ];
        return $erasers;
    }

    /**
     * Export Personal Data for a given email address.
     *
     * @param string $email_address
     * @param int    $page
     * @return array<string, mixed>
     */
    public static function export_personal_data(string $email_address, int $page = 1): array
    {
        $number = 500; // Limit per page
        $page = (int) $page;

        $export_items = [];
        global $wpdb;

        $cache_key = 'mhbo_gdpr_export_' . md5($email_address . '_' . $page);
        $bookings = wp_cache_get($cache_key, 'mhbo_privacy');

        if (false === $bookings) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, direct lookup, caching implemented above
            $bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mhbo_bookings 
                 WHERE customer_email = %s 
                 ORDER BY check_in DESC 
                 LIMIT %d, %d",
                $email_address,
                ($page - 1) * $number,
                $number
            ));
            wp_cache_set($cache_key, $bookings, 'mhbo_privacy', HOUR_IN_SECONDS);
        }

        foreach ($bookings as $booking) {
            $data = [
                [
                    'name' => __('Booking ID', 'modern-hotel-booking'),
                    'value' => $booking->id,
                ],
                [
                    'name' => __('Room ID', 'modern-hotel-booking'),
                    'value' => $booking->room_id,
                ],
                [
                    'name' => __('Check-in Date', 'modern-hotel-booking'),
                    'value' => $booking->check_in,
                ],
                [
                    'name' => __('Check-out Date', 'modern-hotel-booking'),
                    'value' => $booking->check_out,
                ],
                [
                    'name' => __('Total Price', 'modern-hotel-booking'),
                    'value' => $booking->total_price,
                ],
                [
                    'name' => __('Status', 'modern-hotel-booking'),
                    'value' => $booking->status,
                ],
                [
                    'name' => __('Customer Name', 'modern-hotel-booking'),
                    'value' => $booking->customer_name,
                ],
                [
                    'name' => __('Customer Phone', 'modern-hotel-booking'),
                    'value' => $booking->customer_phone,
                ],
                [
                    'name' => __('Admin Notes', 'modern-hotel-booking'),
                    'value' => $booking->admin_notes,
                ],
                [
                    'name' => __('Custom Fields', 'modern-hotel-booking'),
                    'value' => $booking->custom_fields,
                ],
                [
                    'name' => __('Booking Extras', 'modern-hotel-booking'),
                    'value' => $booking->booking_extras,
                ],
                [
                    'name' => __('Payment Error Logs', 'modern-hotel-booking'),
                    'value' => $booking->payment_error,
                ],
            ];

            $export_items[] = [
                'group_id' => 'mhbo-bookings',
                'group_label' => __('Hotel Bookings', 'modern-hotel-booking'),
                'item_id' => "mhbo-booking-{$booking->id}",
                'data' => $data,
            ];
        }

        $done = (is_array($bookings) ? count($bookings) : 0) < $number;

        return [
            'data' => $export_items,
            'done' => $done,
        ];
    }

    /**
     * Erase Personal Data for a given email address.
     *
     * @param string $email_address
     * @param int    $page
     * @return array<string, mixed>
     */
    public static function erase_personal_data(string $email_address, int $page = 1): array
    {
        $number = 500; // Limit per page
        $page = (int) $page;
        $items_removed = false;
        $items_retained = false;
        $messages = [];

        global $wpdb;

        // Find bookings to anonymize
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, direct lookup
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}mhbo_bookings 
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
                $wpdb->prefix . 'mhbo_bookings',
                array(
                    'customer_name'  => '[Deleted]',
                    'customer_email' => 'deleted@site.invalid',
                    'customer_phone' => '[Deleted]',
                    'booking_token'  => wp_generate_password(64, false), // Revoke access link
                    'admin_notes'    => null,
                    'custom_fields'  => null,
                    'booking_extras' => null,
                    'payment_error'  => null,
                ),
                array('id' => $booking->id),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );

            if ($updated) {
                Cache::invalidate_booking($booking->id);
                $items_removed = true;
            } else {
                $items_retained = true;
            }
        }

        $done = (is_array($bookings) ? count($bookings) : 0) < $number;

        return [
            'items_removed' => $items_removed,
            'items_retained' => $items_retained,
            'messages' => $messages,
            'done' => $done,
        ];
    }

    /**
     * Helper to anonymize a single booking by ID.
     * 
     * @param int $booking_id
     * @return bool
     */
    public static function erase_personal_data_by_id(int $booking_id): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, GDPR erasure
        $result = (bool) $wpdb->update(
            $wpdb->prefix . 'mhbo_bookings',
            [
                'customer_name'  => '[Anonymized]',
                'customer_email' => 'deleted@site.invalid',
                'customer_phone' => '[Anonymized]',
                'booking_token'  => wp_generate_password(64, false), // Revoke access link
                'admin_notes'    => null,
                'custom_fields'  => null,
                'booking_extras' => null, // Clear Pro extras too for GDPR
                'payment_error'  => null,
            ],
            ['id' => $booking_id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($result) {
            Cache::invalidate_booking($booking_id);
        }

        return $result;
    }
}
