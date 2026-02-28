<?php declare(strict_types=1);

namespace MHB\Core;

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

        // 1. Check Room Global Status (Cached)
        $room_status_cache_key = 'mhb_room_status_' . $room_id;
        $room_status = wp_cache_get($room_status_cache_key, 'mhb_rooms');

        if (false === $room_status) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, caching implemented
            $room_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}mhb_rooms WHERE id = %d",
                $room_id
            ));
            wp_cache_set($room_status_cache_key, $room_status, 'mhb_rooms', HOUR_IN_SECONDS);
        }

        if (!$room_status || 'available' !== $room_status) {
            return 'label_room_unavailable';
        }

        // 2. Check for Overlapping Bookings
        // Treat pending bookings older than 60 minutes as expired/non-blocking
        $expiry_time = wp_date('Y-m-d H:i:s', strtotime('-60 minutes'));

        // NOTE: Availability is NEVER cached to prevent race conditions and overbooking.
        // We only cache the room's global status above.

        // Industry-standard overlap check (matches Airbnb, Booking.com, all major PMS):
        // Uses strict inequality so checkout day is always available for new check-ins.
        // This is correct because checkout time (e.g. 11:00) precedes check-in time (e.g. 14:00).
        // Formula: existing.check_in < new.check_out AND existing.check_out > new.check_in

        if ($exclude_id > 0) {
            $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mhb_bookings 
                    WHERE room_id = %d 
                    AND status != 'cancelled'
                    AND NOT (status = 'pending' AND created_at < %s)
                    AND check_in < %s AND check_out > %s
                    AND id != %d";
            $params = [$room_id, $expiry_time, $check_out, $check_in, $exclude_id];
        } else {
            $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mhb_bookings 
                    WHERE room_id = %d 
                    AND status != 'cancelled'
                    AND NOT (status = 'pending' AND created_at < %s)
                    AND check_in < %s AND check_out > %s";
            $params = [$room_id, $expiry_time, $check_out, $check_in];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, SQL structure is hardcoded per-branch for security
        $conflict = $wpdb->get_var($wpdb->prepare($sql, ...$params));

        return ((int) $conflict > 0) ? 'label_already_booked' : true;
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

        // Get room type data from database with caching
        $room_cache_key = 'mhb_room_data_' . $room_id;
        $room_data = wp_cache_get($room_cache_key, 'mhb');

        if (false === $room_data) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, caching implemented
            $room_data = $wpdb->get_row($wpdb->prepare(
                "SELECT r.custom_price, t.base_price, r.type_id
                 FROM {$wpdb->prefix}mhb_rooms r 
                 JOIN {$wpdb->prefix}mhb_room_types t ON r.type_id = t.id 
                 WHERE r.id = %d",
                $room_id
            ));
            wp_cache_set($room_cache_key, $room_data, 'mhb', HOUR_IN_SECONDS);
        }

        if (!$room_data || !isset($room_data->base_price)) {
            return 0.00;
        }

        // Prefer room-specific custom price, fallback to type base price
        $base_price = (float) (0 < $room_data->custom_price ? $room_data->custom_price : $room_data->base_price);

        // Get pricing rules from database with caching
        $rule_cache_key = 'mhb_pricing_rule_' . $room_data->type_id . '_' . $date;
        $rule = wp_cache_get($rule_cache_key, 'mhb');

        if (false === $rule) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented
            $rule = $wpdb->get_row($wpdb->prepare(
                "SELECT amount, operation 
                 FROM {$wpdb->prefix}mhb_pricing_rules 
                 WHERE (type_id = %d OR type_id = 0)
                 AND %s BETWEEN start_date AND end_date 
                 ORDER BY type_id DESC, priority DESC LIMIT 1",
                $room_data->type_id ?? 0,
                $date
            ));
            wp_cache_set($rule_cache_key, $rule, 'mhb', HOUR_IN_SECONDS);
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
        $base_price = apply_filters('mhb_calculate_stay_price', $base_price, $room_id, $date);

        // Pro Features: Weekend & Holiday Pricing
        if (false) {
            $dt = strtotime($date);
            // Use gmdate() with 'l' to always get English day names (monday, etc.) 
            // regardless of site locale settings.
            $day_of_week = strtolower(gmdate('l', $dt));

            $weekend_days = get_option('mhb_weekend_days', ['friday', 'saturday', 'sunday']);
            if (!is_array($weekend_days)) {
                $weekend_days = is_string($weekend_days) && !empty($weekend_days) ? explode(',', $weekend_days) : [];
            }
            $is_weekend = in_array($day_of_week, $weekend_days);

            $holiday_dates_str = get_option('mhb_holiday_dates', '');
            $holiday_dates = array_map('trim', explode(',', $holiday_dates_str));
            $is_holiday = in_array($date, $holiday_dates);

            $weekend_adj = 0;
            if ($is_weekend && get_option('mhb_weekend_pricing_enabled', 0)) {
                $val = (float) get_option('mhb_weekend_rate_multiplier', 1.2);
                $type = get_option('mhb_weekend_modifier_type', 'multiplier');
                if ('multiplier' === $type) {
                    $weekend_adj = ($base_price * $val) - $base_price;
                } elseif ('percent' === $type) {
                    $weekend_adj = ($base_price * ($val / 100));
                } else {
                    $weekend_adj = $val;
                }
            }

            $holiday_adj = 0;
            if ($is_holiday && get_option('mhb_holiday_pricing_enabled', 0)) {
                $val = (float) get_option('mhb_holiday_rate_modifier', 1.2);
                $type = get_option('mhb_holiday_modifier_type', 'multiplier');
                if ('multiplier' === $type) {
                    $holiday_adj = ($base_price * $val) - $base_price;
                } elseif ('percent' === $type) {
                    $holiday_adj = ($base_price * ($val / 100));
                } else {
                    $holiday_adj = $val;
                }
            }

            if (get_option('mhb_apply_weekend_to_holidays', 1)) {
                // Use the larger adjustment if both apply (Conflict Resolution)
                $base_price += max($weekend_adj, $holiday_adj);
            } else {
                // Apply both if both apply (cumulative)
                $base_price += $weekend_adj + $holiday_adj;
            }
        }

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
     * @param array  $children_ages Array of child ages.
     * @return array|false     Array with totals and breakdown, or false on error.
     */
    public static function calculate_booking_total($room_id, $check_in, $check_out, $guests = 1, $extras = [], $children = 0, $children_ages = [])
    {
        global $wpdb;

        // Validate room exists and get policy - with caching
        $room_cache_key = 'mhb_room_policy_' . $room_id;
        $room = wp_cache_get($room_cache_key, 'mhb');

        if (false === $room) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented
            $room = $wpdb->get_row($wpdb->prepare("SELECT r.*, t.base_price, t.max_adults, t.max_children, t.child_age_free_limit, t.child_rate FROM {$wpdb->prefix}mhb_rooms r JOIN {$wpdb->prefix}mhb_room_types t ON r.type_id = t.id WHERE r.id = %d", $room_id));
            wp_cache_set($room_cache_key, $room, 'mhb', HOUR_IN_SECONDS);
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

        // Calculate room total
        $room_total = 0;
        foreach ($period as $dt) {
            $room_total += self::calculate_daily_price($room_id, $dt->format('Y-m-d'));
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

            // Ensure children_ages is an array
            $children_ages = is_array($children_ages) ? $children_ages : [];

            // If ages provided, use them. If not (legacy/error), treat all as chargeable? 
            // Better: if ages missing, assume chargeable to be safe, or 0 if we want to be nice.
            // Given the form requires ages, we trust the array.
            // However, ensuring we have entry for each child:
            foreach ($children_ages as $age) {
                if ($age <= $child_limit) {
                    $free_children++;
                } else {
                    $chargeable_children++;
                }
            }

            // Handle any missing ages (e.g. if children=2 but ages=[])
            $missing_ages = $children - count($children_ages);
            if (0 < $missing_ages) {
                // Default missing ages to chargeable? Or free? 
                // Let's assume chargeable to avoid revenue loss, or maybe 0 if usually babies?
                // Let's assume free for safety/UX? No, better to assume chargeable if not specified.
                // But wait, the form validates. Let's assume user input is correct.
                // For simplicity, treat missing as 'chargeable' (worst case) or 'free' (best case).
                // Let's add them to chargeable to match "Standard" behavior unless specified.
                $chargeable_children += $missing_ages;
            }

            // Smart Allocation: Fill empty adult slots with chargeable children
            $adults = $guests;
            $empty_adult_slots = max(0, $max_adults - $adults);

            $children_in_adult_slots = min($chargeable_children, $empty_adult_slots);
            $billed_children = $chargeable_children - $children_in_adult_slots;

            // Calculate cost
            $child_cost_total = $billed_children * $child_rate * $nights;

            // Add to room total or keep separate? 
            // Let's add to room_total but maybe track it?
            $room_total += $child_cost_total;
        }

        // Calculate extras total
        $extras_total = 0;
        $extras_breakdown = [];

        if (!empty($extras) && is_array($extras)) {
            $available_extras = get_option('mhb_pro_extras', []);
            $extras_map = [];
            foreach ($available_extras as $ex) {
                $extras_map[$ex['id']] = $ex;
            }

            foreach ($extras as $ex_id => $val) {
                if (!isset($extras_map[$ex_id])) {
                    continue;
                }
                $extra = $extras_map[$ex_id];
                $quantity = 0;

                if (($extra['control_type'] ?? 'checkbox') === 'checkbox' && '1' == $val) {
                    $quantity = 1;
                } elseif (($extra['control_type'] ?? 'checkbox') === 'quantity') {
                    $quantity = intval($val);
                }

                if (0 < $quantity) {
                    $price = floatval($extra['price']);
                    $pricing_type = $extra['pricing_type'] ?? 'fixed';
                    $cost = 0;
                    $total_guests = $guests + $children;

                    switch ($pricing_type) {
                        case 'fixed':
                            $cost = $price * $quantity;
                            break;
                        case 'per_person':
                            $cost = ($extra['control_type'] === 'checkbox') ? ($price * $total_guests) : ($price * $quantity);
                            break;
                        case 'per_night':
                            $cost = $price * $quantity * $nights;
                            break;
                        case 'per_person_per_night':
                            $cost = ($extra['control_type'] === 'checkbox') ? ($price * $total_guests * $nights) : ($price * $quantity * $nights);
                            break;
                    }
                    $extras_total += $cost;
                    $extras_breakdown[] = [
                        'name' => I18n::decode($extra['name']),
                        'price' => $price,
                        'quantity' => $quantity, // Raw quantity (1 for checkbox)
                        'total' => $cost
                    ];
                }
            }
        }

        $grand_total = (float) max(0, $room_total + $extras_total);

        // Prepare data for tax calculation (Tax class handles disabled mode internally)
        $tax_data = [
            'room_total' => $room_total - $child_cost_total, // Room only (without children)
            'children_total' => $child_cost_total,
            'extras_total' => $extras_total,
            'extras' => []
        ];

        // Add extras breakdown for tax calculation
        foreach ($extras_breakdown as $extra) {
            $tax_data['extras'][] = [
                'id' => sanitize_key($extra['name']),
                'name' => $extra['name'],
                'total' => $extra['total']
            ];
        }

        $tax_breakdown = Tax::calculate_booking_tax($tax_data);
        $tax_totals = $tax_breakdown['totals'];

        // For sales tax mode, update grand total to include tax
        if (Tax::get_mode() === Tax::MODE_SALES_TAX) {
            $grand_total = $tax_totals['total_gross'];
        }

        return [
            'room_total' => $room_total,
            'children_total' => $child_cost_total,
            'extras_total' => $extras_total,
            'total' => $grand_total,
            'extras_breakdown' => $extras_breakdown,
            'nights' => $nights,
            'tax' => [
                'enabled' => Tax::is_enabled(),
                'mode' => Tax::get_mode(),
                'breakdown' => $tax_breakdown,
                'totals' => $tax_totals
            ]
        ];
    }

    /**
     * Get currency symbol
     *
     * @return string
     */
    public static function get_currency_symbol()
    {
        return get_option('mhb_currency_symbol', '$');
    }

    /**
     * Get currency code
     *
     * @return string
     */
    public static function get_currency_code()
    {
        return get_option('mhb_currency_code', 'USD');
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

        if ($include_tax_note && Tax::is_enabled()) {
            $mode = Tax::get_mode();
            $label = Tax::get_label();
            if (Tax::MODE_VAT === $mode) {
                // translators: %s: tax label (e.g., VAT, Tax)
                $formatted .= ' <span class="mhb-tax-note">(' . sprintf(I18n::get_label('label_tax_note_includes'), $label) . ')</span>';
            } elseif (Tax::MODE_SALES_TAX === $mode) {
                // translators: %s: tax label (e.g., VAT, Tax)
                $formatted .= ' <span class="mhb-tax-note">(' . sprintf(I18n::get_label('label_tax_note_plus'), $label) . ')</span>';
            }
        }

        return $formatted;
    }
}

