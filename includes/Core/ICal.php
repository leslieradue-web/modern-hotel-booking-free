<?php declare(strict_types=1);

namespace MHB\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * iCal export and import functionality.
 *
 * RFC 5545 compliant iCal generation for compatibility with:
 * - Google Calendar
 * - Airbnb
 * - Booking.com
 *
 * @package MHB\Core
 * @since   2.0.1
 */
class ICal
{
    /**
     * WordPress timezone string.
     *
     * @var string
     */
    private static $timezone;

    /**
     * Generate an ICS file for a specific room.
     *
     * @param int    $room_id Room ID.
     * @param string $token   Optional authentication token (for backward compatibility).
     * @param string $key     Optional per-room key (used by Pro version).
     */
    public static function generate_ics($room_id, $token = '', $key = '')
    {
        if (!false) {
            wp_die(esc_html__('Pro license required.', 'modern-hotel-booking'), '', array('response' => 403));
        }

        // Support both authentication methods:
        // 1. Legacy: Global token (mhb_ical_token)
        // 2. Pro: Per-room key (mhb_ical_key_{room_id})
        $authenticated = false;

        // Check per-room key first (Pro version)
        if (!empty($key)) {
            $stored_key = get_option('mhb_ical_key_' . $room_id, '');
            if (!empty($stored_key) && hash_equals($stored_key, $key)) {
                $authenticated = true;
            }
        }

        // Fall back to global token (backward compatibility)
        // SECURITY: Use hash_equals for timing-safe comparison
        if (!$authenticated && !empty($token)) {
            $saved_token = get_option('mhb_ical_token');
            if (!empty($saved_token) && hash_equals($saved_token, $token)) {
                $authenticated = true;
            }
        }

        if (!$authenticated) {
            wp_die(esc_html__('Invalid or missing iCal token.', 'modern-hotel-booking'), esc_html__('Security Error', 'modern-hotel-booking'), array('response' => 403));
        }

        self::$timezone = wp_timezone_string();

        global $wpdb;

        // Include cancelled bookings so external platforms can remove them
        $cache_key = 'mhb_room_bookings_ics_' . $room_id;
        $bookings = wp_cache_get($cache_key, 'mhb_bookings');

        if (false === $bookings) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, specific lookup, caching implemented above
            $bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mhb_bookings WHERE room_id = %d",
                $room_id
            ));
            wp_cache_set($cache_key, $bookings, 'mhb_bookings', HOUR_IN_SECONDS);
        }

        header('Content-Type: text/calendar; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        header('Content-Disposition: attachment; filename="room-' . $room_id . '.ics"');

        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "PRODID:-//Modern Hotel Booking//EN\r\n";
        echo "CALSCALE:GREGORIAN\r\n";
        echo "METHOD:PUBLISH\r\n";

        // Add VTIMEZONE component for Google Calendar compatibility
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ICS calendar format, not HTML
        echo self::generate_vtimezone();

        foreach ($bookings as $booking) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ICS calendar format, not HTML
            echo self::generate_vevent($booking);
        }

        echo "END:VCALENDAR";
        exit;
    }

    /**
     * Generate VTIMEZONE component for the current WordPress timezone.
     *
     * @return string VTIMEZONE block.
     */
    private static function generate_vtimezone()
    {
        $tz = self::$timezone;

        // Skip UTC offset format (e.g., +02:00) - not ideal for VTIMEZONE
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $tz)) {
            return '';
        }

        $now = time();
        $year = gmdate('Y', $now);

        // Get timezone transitions for DST detection
        try {
            $timezone = new \DateTimeZone($tz);
            $transitions = $timezone->getTransitions(strtotime($year . '-01-01'), strtotime($year . '-12-31'));

            $dst_start = null;
            $dst_end = null;
            $std_offset = null;
            $dst_offset = null;

            foreach ($transitions as $i => $transition) {
                if ($transition['isdst']) {
                    $dst_start = $transition;
                    $dst_offset = $transition['offset'];
                } else {
                    if ($dst_end === null || ($dst_start !== null && $i > 0)) {
                        $dst_end = $transition;
                    }
                    $std_offset = $transition['offset'];
                }
            }

            $vtimezone = "BEGIN:VTIMEZONE\r\n";
            $vtimezone .= "TZID:{$tz}\r\n";

            // Standard time component
            if ($std_offset !== null) {
                $std_offset_formatted = self::format_utc_offset($std_offset);
                $vtimezone .= "BEGIN:STANDARD\r\n";
                $vtimezone .= "DTSTART:19701101T020000\r\n";
                $vtimezone .= "RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU\r\n";
                $vtimezone .= "TZOFFSETFROM:{$std_offset_formatted}\r\n";
                $vtimezone .= "TZOFFSETTO:{$std_offset_formatted}\r\n";
                $vtimezone .= "END:STANDARD\r\n";
            }

            // Daylight time component (if applicable)
            if ($dst_offset !== null && $dst_offset !== $std_offset) {
                $dst_offset_formatted = self::format_utc_offset($dst_offset);
                $std_offset_formatted = self::format_utc_offset($std_offset);
                $vtimezone .= "BEGIN:DAYLIGHT\r\n";
                $vtimezone .= "DTSTART:19700308T020000\r\n";
                $vtimezone .= "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU\r\n";
                $vtimezone .= "TZOFFSETFROM:{$std_offset_formatted}\r\n";
                $vtimezone .= "TZOFFSETTO:{$dst_offset_formatted}\r\n";
                $vtimezone .= "END:DAYLIGHT\r\n";
            }

            $vtimezone .= "END:VTIMEZONE\r\n";

            return $vtimezone;
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Format UTC offset for VTIMEZONE.
     *
     * @param int $seconds Offset in seconds.
     * @return string Formatted offset (e.g., +0200).
     */
    private static function format_utc_offset($seconds)
    {
        $sign = $seconds >= 0 ? '+' : '-';
        $seconds = abs($seconds);
        $hours = (int) ($seconds / 3600);
        $minutes = (int) (($seconds % 3600) / 60);

        return sprintf('%s%02d%02d', $sign, $hours, $minutes);
    }

    /**
     * Generate a VEVENT component for a booking.
     *
     * @param object $booking Booking data.
     * @return string VEVENT block.
     */
    private static function generate_vevent($booking)
    {
        $uid = $booking->ical_uid ?: 'mhb-' . $booking->id . '@' . wp_parse_url(home_url(), PHP_URL_HOST);

        // DTSTAMP should be the time the iCal was generated, not booking creation
        $dtstamp = gmdate('Ymd\THis\Z');

        // Use DATE format for all-day events (hotel bookings)
        $dtstart = gmdate('Ymd', strtotime($booking->check_in));
        $dtend = gmdate('Ymd', strtotime($booking->check_out));

        // Determine STATUS based on booking status
        $status = 'CONFIRMED';
        if ('cancelled' === $booking->status) {
            $status = 'CANCELLED';
        } elseif ('pending' === $booking->status) {
            $status = 'TENTATIVE';
        }

        // Get sequence number (increment on updates)
        $sequence = (int) get_post_meta($booking->id, '_mhb_ical_sequence', true);
        if ('cancelled' === $booking->status) {
            // Increment sequence for cancellations
            $sequence = max(1, $sequence + 1);
        }

        // LAST-MODIFIED timestamp
        $last_modified = !empty($booking->updated_at)
            ? gmdate('Ymd\THis\Z', strtotime($booking->updated_at))
            : $dtstamp;

        // Build summary
        $summary = sprintf(
            /* translators: 1: booking ID, 2: customer name */
            __('Booking #%1$d - %2$s', 'modern-hotel-booking'),
            $booking->id,
            $booking->customer_name ?: __('Guest', 'modern-hotel-booking')
        );

        // Build description with booking details
        $description = array();
        // translators: %s: check-in date
        $description[] = sprintf(__('Check-in: %s', 'modern-hotel-booking'), $booking->check_in);
        // translators: %s: check-out date
        $description[] = sprintf(__('Check-out: %s', 'modern-hotel-booking'), $booking->check_out);
        // translators: %d: number of guests
        $description[] = sprintf(__('Guests: %d', 'modern-hotel-booking'), $booking->guests ?: 1);
        if ($booking->customer_email) {
            // translators: %s: customer email address
            $description[] = sprintf(__('Email: %s', 'modern-hotel-booking'), $booking->customer_email);
        }
        if ($booking->customer_phone) {
            // translators: %s: customer phone number
            $description[] = sprintf(__('Phone: %s', 'modern-hotel-booking'), $booking->customer_phone);
        }
        // translators: %s: booking source (e.g., direct, airbnb)
        $description[] = sprintf(__('Source: %s', 'modern-hotel-booking'), $booking->source ?: 'direct');

        $vevent = "BEGIN:VEVENT\r\n";
        $vevent .= "UID:{$uid}\r\n";
        $vevent .= "DTSTAMP:{$dtstamp}\r\n";
        $vevent .= "DTSTART;VALUE=DATE:{$dtstart}\r\n";
        $vevent .= "DTEND;VALUE=DATE:{$dtend}\r\n";
        $vevent .= "SUMMARY:" . self::escape_ical_text($summary) . "\r\n";
        $vevent .= "DESCRIPTION:" . self::escape_ical_text(implode('\\n', $description)) . "\r\n";
        $vevent .= "STATUS:{$status}\r\n";
        $vevent .= "SEQUENCE:{$sequence}\r\n";
        $vevent .= "LAST-MODIFIED:{$last_modified}\r\n";
        $vevent .= "END:VEVENT\r\n";

        return $vevent;
    }

    /**
     * Escape special characters for iCal text values.
     *
     * @param string $text Text to escape.
     * @return string Escaped text.
     */
    private static function escape_ical_text($text)
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('"', '\\"', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace("\r", '', $text);

        return $text;
    }

    /**
     * Sync external calendars from the mhb_ical_connections table.
     *
     * Fetches each feed URL, parses VEVENT blocks, and creates bookings
     * for any new events not already in the database (matched by external_id).
     * 
     * SECURITY: Includes SSRF protection to prevent access to internal services.
     */
    public static function sync_external_calendars()
    {
        if (!false) {
            return;
        }
        global $wpdb;

        // Use new table if it exists, fallback to legacy table
        $new_table = $wpdb->prefix . 'mhb_ical_connections';
        $legacy_table = $wpdb->prefix . 'mhb_ical_feeds';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check
        $new_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $new_table));
        $table = $new_exists ? $new_table : $legacy_table;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix
        $feeds = $wpdb->get_results("SELECT * FROM `{$table}`");

        if (empty($feeds)) {
            return;
        }

        foreach ($feeds as $feed) {
            // SECURITY: Validate URL before making request (SSRF protection)
            if (!Security::is_safe_url($feed->feed_url)) {
                continue;
            }

            // SECURITY: Enable SSL verification to prevent MITM attacks
            $response = wp_remote_get($feed->feed_url, array(
                'timeout' => 30,
                'sslverify' => true,
                'redirection' => 3,
            ));

            if (is_wp_error($response)) {
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                continue;
            }

            $events = ICalParser::parse_events($body);

            foreach ($events as $event) {
                if (empty($event['dtstart']) || empty($event['dtend'])) {
                    continue;
                }

                $external_id = !empty($event['uid']) ? $event['uid'] : md5($event['dtstart'] . $event['dtend']);

                // Check if this event already exists
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, existence check
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}mhb_bookings WHERE room_id = %d AND external_id = %s",
                    $feed->room_id,
                    $external_id
                ));

                if (!$exists) {
                    $check_in = gmdate('Y-m-d', strtotime($event['dtstart']));
                    $check_out = gmdate('Y-m-d', strtotime($event['dtend']));

                    // Validate dates
                    if ($check_in >= $check_out) {
                        continue;
                    }

                    $summary = !empty($event['summary']) ? sanitize_text_field($event['summary']) : __('External Booking', 'modern-hotel-booking');

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom tables
                    $wpdb->insert(
                        $wpdb->prefix . 'mhb_bookings',
                        array(
                            'room_id' => $feed->room_id,
                            'check_in' => $check_in,
                            'check_out' => $check_out,
                            'status' => 'confirmed',
                            'customer_name' => $summary,
                            'customer_email' => 'external@import',
                            'total_price' => 0,
                            'booking_token' => wp_generate_password(32, false),
                            'source' => 'ical',
                            'external_id' => $external_id,
                            'ical_uid' => $external_id,
                        ),
                        array('%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s')
                    );
                }
            }

            // Update last_synced timestamp
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, write operation
            $wpdb->update(
                $table,
                array('last_synced' => current_time('mysql')),
                array('id' => $feed->id),
                array('%s'),
                array('%d')
            );
        }
    }
}
