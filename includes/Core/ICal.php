<?php declare(strict_types=1);

namespace MHBO\Core;

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
 * @package MHBO\Core
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
    /* BUILD_PRO_END */

    
}
