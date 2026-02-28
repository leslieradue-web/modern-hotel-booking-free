<?php declare(strict_types=1);

namespace MHB\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * iCal parser utility class.
 *
 * Parses iCal VEVENT blocks from raw iCal data.
 * Used by both Core\ICal and Pro\ICalManager.
 *
 * @package MHB\Core
 * @since   2.1.0
 */
class ICalParser
{
    /**
     * Parse iCal data and extract events.
     *
     * @param string $ical_data Raw iCal content.
     * @param string $platform  Optional platform identifier (e.g., 'airbnb', 'bookingcom').
     * @return array Array of events with keys: uid, dtstart, dtend, summary, description, status, sequence, last_modified, platform.
     */
    public static function parse_events(string $ical_data, string $platform = ''): array
    {
        $events = [];

        // Unfold lines (RFC 5545: lines starting with space/tab are continuation)
        $ical_data = preg_replace("/\r?\n[ \t]/", '', $ical_data);

        // Match all VEVENT blocks
        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ical_data, $matches);

        if (empty($matches[1])) {
            return $events;
        }

        foreach ($matches[1] as $event_data) {
            $event = [
                'uid' => '',
                'dtstart' => '',
                'dtend' => '',
                'summary' => '',
                'description' => '',
                'status' => 'CONFIRMED',
                'sequence' => 0,
                'last_modified' => '',
                'platform' => $platform,
            ];

            // Extract UID
            if (preg_match('/UID:(.+)/i', $event_data, $m)) {
                $event['uid'] = trim($m[1]);
            }

            // Extract DTSTART (handle both DATE and DATETIME formats)
            if (preg_match('/DTSTART[^:]*:(\d{8}(?:T\d{6}Z?)?)/i', $event_data, $m)) {
                $event['dtstart'] = trim($m[1]);
            }

            // Extract DTEND
            if (preg_match('/DTEND[^:]*:(\d{8}(?:T\d{6}Z?)?)/i', $event_data, $m)) {
                $event['dtend'] = trim($m[1]);
            }

            // Extract SUMMARY
            if (preg_match('/SUMMARY:(.+)/i', $event_data, $m)) {
                $event['summary'] = trim($m[1]);
            }

            // Extract DESCRIPTION
            if (preg_match('/DESCRIPTION:(.+)/i', $event_data, $m)) {
                $event['description'] = trim($m[1]);
            }

            // Extract STATUS
            if (preg_match('/STATUS:(CONFIRMED|TENTATIVE|CANCELLED)/i', $event_data, $m)) {
                $event['status'] = strtoupper(trim($m[1]));
            }

            // Extract SEQUENCE
            if (preg_match('/SEQUENCE:(\d+)/i', $event_data, $m)) {
                $event['sequence'] = (int) trim($m[1]);
            }

            // Extract LAST-MODIFIED
            if (preg_match('/LAST-MODIFIED:(\d{8}T\d{6}Z?)/i', $event_data, $m)) {
                $event['last_modified'] = trim($m[1]);
            }

            // Platform-specific defaults
            if (empty($event['summary'])) {
                $event['summary'] = self::get_default_summary($platform);
            }

            if (!empty($event['dtstart'])) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Get default summary for a platform.
     *
     * @param string $platform Platform identifier.
     * @return string Default summary.
     */
    private static function get_default_summary(string $platform): string
    {
        switch (strtolower($platform)) {
            case 'airbnb':
                return __('Airbnb Reservation', 'modern-hotel-booking');
            case 'bookingcom':
            case 'booking.com':
                return __('Booking.com Reservation', 'modern-hotel-booking');
            case 'google':
            case 'google-calendar':
                return __('Google Calendar Event', 'modern-hotel-booking');
            default:
                return '';
        }
    }
}
