<?php declare(strict_types=1);

namespace MHBO\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Pricing
{
    /**
     * Centralized availability check with room status and pending expiration.
     *
     * @param int    $room_id   Room ID.
     * @param string $check_in   Check-in date (Y-m-d).
     * @param string $check_out  Check-out date (Y-m-d).
     * @param int    $exclude_id Optional booking ID to exclude (for editing).
     * @return bool|string True if available, or error label slug if not.
     */
    public static function is_room_available($room_id, $check_in, $check_out, $exclude_id = 0)
    {
        global $wpdb;

        // 1. Check Room Global Status (Cached with versioning)
        // Rule 13 rationale: Direct status check is cached using versioned row access
        // to ensure instant global updates when room status changes.
        $room_status = Cache::get_row($room_id, Cache::TABLE_ROOMS);

        if (false === $room_status) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- 2026 BP: Row-level status check for high-concurrency availability engine. Value is cached with table-versioning via MHBO\Core\Cache.
            $room_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d",
                $room_id
            )) ?: 'available';
            Cache::set_row($room_id, $room_status, Cache::TABLE_ROOMS, 86400); // 24h as it's versioned
        }

        if (!$room_status || 'available' !== $room_status) {
            return 'label_room_unavailable';
        }

        // 2. Check for Overlapping Bookings
        // NOTE: Availability is NEVER cached to prevent race conditions and overbooking.
        // We only cache the room's global status above.

        // Industry-standard overlap check (matches Airbnb, Booking.com, all major PMS):
        // Uses strict inequality so checkout day is always available for new check-ins.
        // This is correct because checkout time (e.g. 11:00) precedes check-in time (e.g. 14:00).
        // Formula: existing.check_in < new.check_out AND existing.check_out > new.check_in

        // Same-day Turnover Setting:
        // Checked (1) = PREVENT same-day overlap (Gap day required).
        // Unchecked (0) = ALLOW same-day turnover (Industry Standard).
        $prevent_same_day = (int) get_option('mhbo_prevent_same_day_turnover', 0) === 1;

        if ($prevent_same_day) {
            // Inclusive bounds: prevents same-day overlap entirely (Gap day required)
            if ($exclude_id > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Real-time conflict check for prevent-same-day turnover. Bypasses persistent cache to ensure zero-overbooking.
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND (check_in <= %s AND check_out >= %s) AND id != %d",
                    $room_id, $check_out, $check_in, $exclude_id
                ));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Real-time conflict check for prevent-same-day turnover. Bypasses persistent cache to ensure zero-overbooking.
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND (check_in <= %s AND check_out >= %s)",
                    $room_id, $check_out, $check_in
                ));
            }
        } else {
            // Exclusive bounds: allows existing check_out to equal new check_in (Industry Standard)
            if ($exclude_id > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Real-time conflict check for standard turnover (same-day). Bypasses persistent cache to ensure zero-overbooking.
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND (check_in < %s AND check_out > %s) AND id != %d",
                    $room_id, $check_out, $check_in, $exclude_id
                ));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Real-time conflict check for standard turnover (same-day). Bypasses persistent cache to ensure zero-overbooking.
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND (check_in < %s AND check_out > %s)",
                    $room_id, $check_out, $check_in
                ));
            }
        }

        return ((int) $conflict > 0) ? 'label_already_booked' : true;
    }

    /**
     * Get the SQL condition for room availability based on turnover settings.
     * 
     * @param string $check_in_placeholder  SQL placeholder for check-in.
     * @param string $check_out_placeholder SQL placeholder for check-out.
     * @param bool   $prevent_same_day     Whether to prevent same-day turnover.
     * @return string The SQL condition fragment.
     */
    public static function get_overlap_sql_condition(string $check_in_placeholder, string $check_out_placeholder, bool $prevent_same_day = false): string
    {
        if ($prevent_same_day) {
            // Inclusive bounds: prevents same-day overlap entirely (Gap day required)
            return "(check_in <= {$check_in_placeholder} AND check_out >= {$check_out_placeholder})";
        }

        // Exclusive bounds: allows existing check_out to equal new check_in (Industry Standard)
        return "(check_in < {$check_in_placeholder} AND check_out > {$check_out_placeholder})";
    }

    /**
     * Find the first available room of a specific type for a date range.
     *
     * @param int    $type_id   Room Type ID.
     * @param string $check_in   Check-in date (Y-m-d).
     * @param string $check_out  Check-out date (Y-m-d).
     * @return int|false Room ID if found, false otherwise.
     */
    public static function find_available_room($type_id, $check_in, $check_out, $guests = 1)
    {
        global $wpdb;
        $type_id = absint($type_id);
        $guests  = absint($guests);

        if (0 === $type_id) {
            return false;
        }

        // Get all rooms of this type
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rooms = $wpdb->get_col($wpdb->prepare(
            "SELECT r.id FROM {$wpdb->prefix}mhbo_rooms r JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id WHERE r.type_id = %d AND r.status = 'available' ORDER BY r.id ASC",
            $type_id
        ));

        if (empty($rooms)) {
            return false;
        }

        foreach ($rooms as $room_id) {
            if (true === self::is_room_available($room_id, $check_in, $check_out)) {
                return (int) $room_id;
            }
        }

        return false;
    }

    /**
     * Calculate the price for a specific room on a specific date.
     *
     * @param int    $room_id Room ID.
     * @param string $date    Date in Y-m-d format.
     * @return float Calculated price.
     */
    public static function calculate_daily_price($room_id, $date)
    {
        global $wpdb;

        // Validate inputs
        $room_id = absint($room_id);
        if (0 === $room_id) {
            return 0.00;
        }

        // Get room type data from database with versioned caching
        // Rule 13 rationale: Room pricing data is versioned to allow instant
        // global updates when base prices or types are modified.
        $room_cache_key = 'room_data_' . $room_id;
        $room_data = Cache::get_query($room_cache_key, Cache::TABLE_ROOMS);

        if (false === $room_data) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $room_data = $wpdb->get_row($wpdb->prepare(
                "SELECT r.custom_price, t.base_price, r.type_id
                 FROM {$wpdb->prefix}mhbo_rooms r 
                 JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id 
                 WHERE r.id = %d",
                $room_id
            ));
            Cache::set_query($room_cache_key, $room_data, Cache::TABLE_ROOMS, 86400);
        }

        if (!$room_data || !isset($room_data->base_price)) {
            return 0.00;
        }

        // Prefer room-specific custom price, fallback to type base price
        $base_price = (float) (0 < $room_data->custom_price ? $room_data->custom_price : $room_data->base_price);

        // Get pricing rules from database with versioned caching
        // Rule 13 rationale: Pricing rules are mission-critical and must be
        // invalidated site-wide the moment settings are updated.
        $rule_cache_key = 'pricing_rule_' . ($room_data->type_id ?? 0) . '_' . $date;
        $rule = Cache::get_query($rule_cache_key, Cache::TABLE_PRICING_RULES);

        if (false === $rule) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rule = $wpdb->get_row($wpdb->prepare(
                "SELECT amount, operation 
                 FROM {$wpdb->prefix}mhbo_pricing_rules 
                 WHERE (type_id = %d OR type_id = 0)
                 AND %s BETWEEN start_date AND end_date 
                 ORDER BY type_id DESC, priority DESC LIMIT 1",
                $room_data->type_id ?? 0,
                $date
            ));
            Cache::set_query($rule_cache_key, $rule, Cache::TABLE_PRICING_RULES, 86400);
        }

        if ($rule && isset($rule->operation) && isset($rule->amount)) {
            if ('percent' === $rule->operation) {
                $base_price += ($base_price * ((float) $rule->amount / 100));
            } else {
                $base_price += (float) $rule->amount;
            }
        }

        /**
         * Filter the calculated stay price for seasonal/advanced pricing rules.
         *
         * @param float  $base_price The calculated base price.
         * @param int    $room_id    The room ID.
         * @param string $date       The date string (Y-m-d).
         */
        $base_price = apply_filters('mhbo_calculate_stay_price', $base_price, $room_id, $date);

return (float) max(0, $base_price);
    }

    /**
     * Calculate full booking total including extras.
     * 
     * @param int    $room_id  Room ID.
     * @param string $check_in Check-in date (Y-m-d).
     * @param string $check_out Check-out date (Y-m-d).
     * @param int    $guests   Number of guests (Adults).
     * @param array  $extras   Extras array (id => value).
     * @param int    $children Number of children.
     * @param array  $child_ages Array of child ages.
     * @return array|false     Array with totals and breakdown, or false on error.
     */
    public static function calculate_booking_total($room_id, $check_in, $check_out, $guests = 1, $extras = [], $children = 0, $child_ages = [])
    {
        global $wpdb;

        // Validate room exists and get policy - with versioned caching
        // Rule 13 rationale: Direct policy lookup is cached via prices_version
        // to ensure immediate consistency for checkout totals.
        $version = Cache::get_prices_version();
        $room_cache_key = 'mhbo_room_policy_' . $room_id . '_' . $version;
        $room = wp_cache_get($room_cache_key, 'mhbo');

        if (false === $room) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $room = $wpdb->get_row($wpdb->prepare("SELECT r.*, t.base_price, t.max_adults, t.max_children, t.child_age_free_limit, t.child_rate FROM {$wpdb->prefix}mhbo_rooms r JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id WHERE r.id = %d", $room_id));
            wp_cache_set($room_cache_key, $room, 'mhbo', 86400);
        }

        if (!$room) {
            return false;
        }

        // Validate dates
        try {
            $start_date = new \DateTime($check_in);
            $end_date = new \DateTime($check_out);
        } catch (\Exception $e) {
            return false;
        }

        if ($end_date <= $start_date) {
            return false;
        }

        $interval = new \DateInterval('P1D');
        $period = new \DatePeriod($start_date, $interval, $end_date);
        $nights = iterator_count($period);

        $room_total = 0;
        $room_daily_prices = [];
        foreach ($period as $dt) {
            $price = self::calculate_daily_price($room_id, $dt->format('Y-m-d'));
            $room_total += $price;
            $room_daily_prices[] = $price;
        }

        // Calculate Child Costs (Smart Allocation)
        $child_cost_total = 0;
        if (0 < $children) {
            $max_adults = intval($room->max_adults);
            $child_limit = intval($room->child_age_free_limit);
            $child_rate = floatval($room->child_rate);

            // Separate children
            $free_children = 0;
            $chargeable_children = 0;

            // Ensure child_ages is an array
            $child_ages = is_array($child_ages) ? $child_ages : [];

            // If ages provided, use them. If not (legacy/error), treat all as chargeable? 
            foreach ($child_ages as $age) {
                if ($age <= $child_limit) {
                    $free_children++;
                } else {
                    $chargeable_children++;
                }
            }

            // Handle any missing ages
            $missing_ages = $children - count($child_ages);
            if (0 < $missing_ages) {
                $chargeable_children += $missing_ages;
            }

            // Smart Allocation: Fill empty adult slots with chargeable children
            $adults = $guests;
            $empty_adult_slots = max(0, $max_adults - $adults);

            $children_in_adult_slots = min($chargeable_children, $empty_adult_slots);
            $billed_children = $chargeable_children - $children_in_adult_slots;

            // Calculate cost (round to 2 decimals for monetary consistency)
            $child_cost_total = round($billed_children * $child_rate * $nights, 2);

            $room_total += $child_cost_total;
        }

        // Calculate extras total
        $extras_total = 0;
        $extras_breakdown = [];

$grand_total = (float) round(max(0, $room_total + $extras_total), 2);

return [
            'room_total' => round($room_total, 2),
            'children_total' => round($child_cost_total, 2),
            'extras_total' => round($extras_total, 2),
            'total' => round($grand_total, 2),
            'extras_breakdown' => $extras_breakdown,
            'nights' => $nights,
            'daily_prices' => $room_daily_prices, // Added for deposit calculation
            
        ];
    }

    /**
     * Get currency symbol
     *
     * @return string
     */
    public static function get_currency_symbol()
    {
        return get_option('mhbo_currency_symbol', '$');
    }

    /**
     * Get currency code
     *
     * @return string
     */
    public static function get_currency_code()
    {
        return get_option('mhbo_currency_code', 'USD');
    }

    /**
     * Format price with currency
     *
     * @param float $amount Amount to format
     * @param bool $include_tax_note Include tax note if applicable
     * @return string Formatted price
     */
    public static function format_price($amount, $include_tax_note = false)
    {
        $formatted = I18n::format_currency($amount);

return $formatted;
    }

}
