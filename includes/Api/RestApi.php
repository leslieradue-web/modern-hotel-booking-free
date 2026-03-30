<?php declare(strict_types=1);

/**
 * REST API endpoints for Modern Hotel Booking.
 *
 * Namespace: mhbo/v1
 * Endpoints:
 *   GET  /rooms         — list room types
 *   GET  /availability  — check availability for date range
 *   POST /bookings      — create a booking (API key required)
 *   GET  /bookings/{id} — get booking details (API key required)
 *
 * @package MHBO\Api
 * @since   2.0.1
 */

namespace MHBO\Api;
if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Core\Cache;
use MHBO\Core\I18n;
use MHBO\Core\Pricing;

/**
 * REST API endpoints for Modern Hotel Booking.
 *
 * @package MHBO\Api
 * @since   2.0.1
 */
class RestApi
{
    /**
     * Register REST routes.
     */
    public function register_routes(): void
    {
        $namespace = 'mhbo/v1';

        register_rest_route($namespace, '/rooms', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_rooms'),
            'permission_callback' => function ($request) {
                
                return $this->check_read_access($request);
            },
        ));

        register_rest_route($namespace, '/availability', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_availability'),
            'permission_callback' => function ($request) {
                
                return $this->check_read_access($request);
            },
            'args' => array(
                'check_in' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'check_out' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

register_rest_route($namespace, '/bookings/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_booking'),
            'permission_callback' => function () {
                // SECURITY: Integer ID access is restricted to administrators only.
                // General users must use the /bookings/{reference} endpoint.
                return current_user_can('manage_options');
            },
        ));

        register_rest_route($namespace, '/bookings/(?P<reference>[a-zA-Z0-9]{24,64})', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_booking'),
            'permission_callback' => '__return_true', // Authorization handled inside get_booking via verify_booking_access
        ));

        register_rest_route($namespace, '/calendar-data', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_calendar_data'),
            'permission_callback' => array($this, 'check_read_access'),
            'args' => array(
                'room_id' => array(
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ),
                'year' => array(
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ),
                'month' => array(
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        register_rest_route($namespace, '/recalculate-price', array(
            'methods' => 'POST',
            'callback' => array($this, 'recalculate_price'),
            'permission_callback' => array($this, 'check_read_access'),
            'args' => array(
                'room_id' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && intval($value) > 0;
                    },
                    'sanitize_callback' => 'absint'
                ),
                'check_in' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'check_out' => array(
                    'required' => true,
                    'validate_callback' => array($this, 'validate_date'),
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'guests' => array(
                    'required' => false,
                    'sanitize_callback' => 'absint',
                    'default' => 1
                ),
                'children' => array(
                    'required' => false,
                    'sanitize_callback' => 'absint',
                    'default' => 0
                ),
                'child_ages' => array(
                    'required' => false,
                    'validate_callback' => function ($value) {
                        return is_array($value);
                    },
                    'sanitize_callback' => function ($value) {
                        return is_array($value) ? array_map('absint', $value) : array();
                    },
                    'default' => array()
                ),
                'extras' => array(
                    'required' => false,
                    'validate_callback' => function ($value) {
                        return is_array($value);
                    },
                    'sanitize_callback' => function ($value) {
                        if (!is_array($value))
                            return array();
                        $sanitized = array();
                        foreach ($value as $k => $v) {
                            $sanitized[sanitize_key($k)] = sanitize_text_field($v);
                        }
                        return $sanitized;
                    },
                    'default' => array()
                ),
            ),
        ));

}

    /**
     * Validate a date string (Y-m-d).
     *
     * @param string $value Date string.
     * @return bool
     */
    public function validate_date($value)
    {
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    }

    /**
     * General check if REST API is allowed (Pro only).
     *
     * @return bool|\WP_Error
     */
    public function check_pro_access()
    {
        
        return true;
    }

    /**
     * Check for read access - Verify nonce (CSRF protection) and rate limit.
     * Use for non-authenticated public endpoints that need protection.
     *
     * @param \WP_REST_Request|null $request Request object.
     * @return bool|\WP_Error
     */
    public function check_read_access($request = null)
    {
        // Require nonce for protection against CSRF
        if ($request !== null) {
            $nonce = $request->get_header('X-WP-Nonce');
            if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
                return new \WP_Error(
                    'mhbo_unauthorized',
                    esc_html(I18n::get_label('label_invalid_nonce')),
                    array('status' => 403)
                );
            }
        }

        // Apply rate limiting for public endpoints
        $rate_limit = $this->check_rate_limit();
        if (is_wp_error($rate_limit)) {
            return $rate_limit;
        }

        return true;
    }

    /**
     * Check rate limit for API requests.
     * Limits to 60 requests per minute per IP address.
     *
     * @return bool|\WP_Error True if allowed, WP_Error if rate limited.
     */
    private function check_rate_limit()
    {
        $ip = \MHBO\Core\Security::get_client_ip();
        if (empty($ip) || '0.0.0.0' === $ip) {
            // Can't determine IP, allow but log
            return true;
        }

        $transient_key = 'mhbo_api_rate_' . md5($ip);
        $request_count = get_transient($transient_key);

        $limit = apply_filters('mhbo_api_rate_limit', 60); // Default 60 requests
        $window = apply_filters('mhbo_api_rate_window', 60); // Per 60 seconds

        if (false === $request_count) {
            set_transient($transient_key, 1, $window);
            return true;
        }

        if ($request_count >= $limit) {
            return new \WP_Error(
                'mhbo_rate_limit_exceeded',
                esc_html(I18n::get_label('label_api_rate_limit')),
                array('status' => 429)
            );
        }

        set_transient($transient_key, $request_count + 1, $window);
        return true;
    }

/**
     * Verify webhook permission - check for valid webhook signature.
     * SECURITY: This prevents unauthorized webhook submissions.
     *
     * @param \WP_REST_Request $request Request object.
     * @return bool|\WP_Error
     */
    public function verify_webhook_permission($request)
    {
        $headers = $request->get_headers();
        $payload = $request->get_body();

// SECURITY: Reject webhooks without proper signatures
        return new \WP_Error(
            'mhbo_webhook_unauthorized',
            esc_html(I18n::get_label('label_webhook_sig_required')),
            array('status' => 401)
        );
    }

/**
     * GET /rooms — List all room types.
     *
     * @return \WP_REST_Response
     */
    public function get_rooms()
    {
        global $wpdb;
        $cache_key = 'all_types';
        $room_types = Cache::get_query($cache_key, Cache::TABLE_ROOM_TYPES);

        if (false === $room_types) {
            // RATIONALE: Required to list room types for public rooms REST endpoint.
            // Read-only; result is cached via Cache::set_query with versioned salt.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented via Cache class
            $room_types = $wpdb->get_results(
                "SELECT id, name, description, base_price, max_adults, max_children, total_rooms, amenities, image_url
                 FROM {$wpdb->prefix}mhbo_room_types ORDER BY id ASC"
            );
            Cache::set_query($cache_key, $room_types, Cache::TABLE_ROOM_TYPES, HOUR_IN_SECONDS);
        }

        $data = array();
        foreach ($room_types as $type) {
            $data[] = array(
                'id' => (int) $type->id,
                'name' => I18n::decode($type->name),
                'description' => I18n::decode($type->description),
                'base_price' => (float) $type->base_price,
                'max_adults' => (int) $type->max_adults,
                'max_children' => (int) $type->max_children,
                'total_rooms' => (int) $type->total_rooms,
                'amenities' => $type->amenities ? json_decode($type->amenities, true) : array(),
                'image_url' => esc_url($type->image_url),
            );
        }

        return rest_ensure_response($data);
    }

    /**
     * GET /availability — Check room availability.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_availability($request)
    {
        global $wpdb;

        $check_in = $request->get_param('check_in');
        $check_out = $request->get_param('check_out');

        if ($check_in >= $check_out) {
            return new \WP_Error(
                'mhbo_invalid_dates',
                esc_html(I18n::get_label('label_check_out_after')),
                array('status' => 400)
            );
        }

        // Get all rooms - cache this as room configuration rarely changes
        $rooms_cache_key = 'rooms_with_types';
        $rooms = Cache::get_query($rooms_cache_key, Cache::TABLE_ROOMS);

        if (false === $rooms) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- RATIONALE: Room Type lookup uses a custom table. Result is cached above using a unique key to prevent redundant schema-level JOINS under high REST traffic.
            $rooms = $wpdb->get_results(
                "SELECT r.id AS room_id, r.room_number, r.status, r.custom_price,
                        t.id AS type_id, t.name AS type_name, t.base_price, t.max_adults, t.max_children
                 FROM {$wpdb->prefix}mhbo_rooms r
                 JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
                 ORDER BY t.id, r.id"
            );
            Cache::set_query($rooms_cache_key, $rooms, Cache::TABLE_ROOMS, HOUR_IN_SECONDS);
        }

        $available = array();

        foreach ($rooms as $room) {
            // Use the centralized availability check
            $availability_status = Pricing::is_room_available((int) $room->room_id, $check_in, $check_out);

            if (true === $availability_status) {
                // Calculate total price for the stay
                $total_price = 0;
                $period = new \DatePeriod(
                    new \DateTime($check_in),
                    new \DateInterval('P1D'),
                    new \DateTime($check_out)
                );
                foreach ($period as $date) {
                    $total_price += Pricing::calculate_daily_price($room->room_id, $date->format('Y-m-d'));
                }

                $available[] = array(
                    'room_id' => (int) $room->room_id,
                    'room_number' => $room->room_number,
                    'type_id' => (int) $room->type_id,
                    'type_name' => I18n::decode($room->type_name),
                    'max_adults' => (int) $room->max_adults,
                    'max_children' => (int) $room->max_children,
                    'total_price' => round($total_price, 2),
                    'price_formatted' => I18n::format_currency($total_price),
                );
            }
        }

        return rest_ensure_response(array(
            'check_in' => $check_in,
            'check_out' => $check_out,
            'available' => $available,
            'count' => count($available),
        ));
    }

/**
     * GET /calendar-data — Get availability data for calendar display.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_calendar_data($request)
    {
        global $wpdb;
        $room_id = $request->get_param('room_id');
        $year = $request->get_param('year') ?: wp_date('Y');
        $month = $request->get_param('month') ?: wp_date('m');

        // Aggregated view if room_id is 0 or missing
        if (!$room_id) {
            $start_date_obj = new \DateTime("$year-$month-01");
            $end_date_obj = clone $start_date_obj;
            $end_date_obj->modify('+12 months');

            $start_str = $start_date_obj->format('Y-m-d');
            $end_str = $end_date_obj->format('Y-m-d');

            return $this->get_aggregated_calendar_data($start_str, $end_str);
        }

        // Fetch bookings with status to differentiate pending vs confirmed
        // Cache with versioning for Rule 13 compliance
        $bookings_cache_key = 'calendar_bookings_' . $room_id;
        $bookings = Cache::get_query($bookings_cache_key, Cache::TABLE_BOOKINGS);

        if (false === $bookings) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, versioned caching implemented above
            $bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT check_in, check_out, status FROM {$wpdb->prefix}mhbo_bookings 
                 WHERE room_id = %d 
                 AND status != 'cancelled'",
                $room_id
            ));
            Cache::set_query($bookings_cache_key, $bookings, Cache::TABLE_BOOKINGS, 300); // 5 min cache for real-time accuracy
        }

        // Get room status to mark as unbookable if maintenance/hidden
        $room_status = Cache::get_row($room_id, Cache::TABLE_ROOMS);

        if (false === $room_status) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, row caching implemented above
            $room_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d",
                $room_id
            )) ?: 'available';
            Cache::set_row($room_id, $room_status, Cache::TABLE_ROOMS, HOUR_IN_SECONDS);
        }

        // Map each date to its booking status (pending or confirmed)
        $booked_dates = [];
        $check_ins = [];
        $check_outs = [];

        foreach ($bookings as $b) {
            $check_ins[] = $b->check_in;
            $check_outs[] = $b->check_out;
            try {
                // Determine if this booking blocks specific dates
                $period = new \DatePeriod(
                    new \DateTime($b->check_in),
                    new \DateInterval('P1D'),
                    new \DateTime($b->check_out)
                );
                foreach ($period as $date) {
                    $date_str = $date->format('Y-m-d');
                    // Store the booking status for this date
                    $booked_dates[$date_str] = $b->status;
                }
            } catch (\Exception $e) {
                // Skip invalid dates
            }
        }

        // Generate data for 12 months (1 year) starting from the requested month
        $data = [];
        try {
            $start_date = new \DateTime("$year-$month-01");
            $end_date = clone $start_date;
            $end_date->modify('+12 months');

            $period = new \DatePeriod($start_date, new \DateInterval('P1D'), $end_date);
            foreach ($period as $dt) {
                $date_str = $dt->format('Y-m-d');
                $price = Pricing::calculate_daily_price($room_id, $date_str);

                // Determine availability status using centralized logic
                // For calendar, we check if the room can be booked STARTING on this date
                // However, the calendar usually shows "booked" if any part of the day is occupied.
                // To match user expectations: a date is "booked" if it's already reserved.
                $is_booked = isset($booked_dates[$date_str]);

                // Check "Prevent Same-day Turnover" for check-in availability
                // If the date is a check-out date of an existing booking, it's only available if turnover is allowed.
                $is_check_out_day = in_array($date_str, $check_outs, true);
                $prevent_turnover = (int) get_option('mhbo_prevent_same_day_turnover', 0) === 1;
                
                $can_check_in = true;
                if ($is_booked) {
                    $can_check_in = false;
                } elseif ($is_check_out_day && (bool) $prevent_turnover) {
                    $can_check_in = false;
                }

                // Room is unbookable if status is not 'available' OR if price is 0 (unbooked)
                $is_unbookable = ('available' !== $room_status) || (!$is_booked && $price <= 0);

                $data[] = [
                    'date' => $date_str,
                    'status' => $is_booked ? 'booked' : ($is_unbookable ? 'unbookable' : 'available'),
                    'booking_status' => $is_booked ? $booked_dates[$date_str] : null,
                    'is_checkin' => in_array($date_str, $check_ins, true),
                    'is_checkout' => $is_check_out_day,
                    'can_check_in' => $can_check_in, // Add hint for frontend
                    'price' => $price,
                    'price_formatted' => I18n::format_currency($price)
                ];
            }
        } catch (\Exception $e) {
            return new \WP_Error('mhbo_calendar_error', __('Error generating calendar data.', 'modern-hotel-booking'), array('status' => 500));
        }

        return rest_ensure_response($data);
    }

    /**
     * Get aggregated calendar data for all rooms.
     */
    private function get_aggregated_calendar_data($start_str, $end_str)
    {
        global $wpdb;

        // Get all rooms with caching using Rule 13 patterns
        $cache_key = 'mhbo_available_rooms_ids';
        $rooms = \MHBO\Core\Cache::get_query($cache_key, \MHBO\Core\Cache::TABLE_ROOMS);

        if (false === $rooms) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, caching implemented via Cache::set_query
            $rooms = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}mhbo_rooms WHERE status = 'available'");
            \MHBO\Core\Cache::set_query($cache_key, $rooms, \MHBO\Core\Cache::TABLE_ROOMS, 3600);
        }

        if (empty($rooms)) {
            return rest_ensure_response([]);
        }

        $room_ids = array_column($rooms, 'id');
        $room_placeholders = implode(',', array_fill(0, count($room_ids), '%d'));

        // Same-day Turnover Setting
        $prevent_turnover = (bool) get_option('mhbo_prevent_same_day_turnover', false);

        // 2026 Best Practice (Rule 13): Use Cache class with versioned keys.
        $cache_key = 'mhbo_avail_agg_' . md5(implode(',', $room_ids) . $start_str . $end_str . (int)$prevent_turnover);
        $bookings = \MHBO\Core\Cache::get_query($cache_key, \MHBO\Core\Cache::TABLE_BOOKINGS);

        if (false === $bookings) {
            $room_placeholders_string = implode(',', array_fill(0, count($room_ids), '%d'));
            $params = array_merge($room_ids, [$end_str, $start_str]);

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            /**
             * RATIONALE FOR PHPCS DISABLE (RULE 13):
             * 1. DirectQuery: Required for custom table 'mhbo_bookings'.
             * 2. NoCaching: FALSE. Caching is handled via the $last_changed salt wrap-around.
             * 3. PreparedSQL: Fragmented preparation for code readability has been reviewed for security.
             */
            if ($prevent_turnover) {
                $bookings = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT room_id, check_in, check_out, status FROM {$wpdb->prefix}mhbo_bookings WHERE room_id IN ($room_placeholders_string) AND status != 'cancelled' AND (check_in <= %s AND check_out >= %s)",
                        ...$params
                    )
                );
            } else {
                $bookings = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT room_id, check_in, check_out, status FROM {$wpdb->prefix}mhbo_bookings WHERE room_id IN ($room_placeholders_string) AND status != 'cancelled' AND (check_in < %s AND check_out > %s)",
                        ...$params
                    )
                );
            }
            // phpcs:enable

            // Store in cache with 1 hour TTL (Rule 13 versioned)
            \MHBO\Core\Cache::set_query($cache_key, $bookings, \MHBO\Core\Cache::TABLE_BOOKINGS, 3600);
        }

        // Organize bookings by room
        $room_bookings = [];
        foreach ($room_ids as $rid) {
            $room_bookings[$rid] = [];
        }
        foreach ($bookings as $b) {
            $room_bookings[$b->room_id][] = [
                'check_in' => $b->check_in,
                'check_out' => $b->check_out,
                'status' => $b->status
            ];
        }

        $data = [];
        $start_date = new \DateTime($start_str);
        $end_date = new \DateTime($end_str);
        $period = new \DatePeriod($start_date, new \DateInterval('P1D'), $end_date);

        foreach ($period as $dt) {
            $date_str = $dt->format('Y-m-d');

            $total_rooms = count($room_ids);
            $rooms_free_pm = 0; // Can check in?
            $rooms_free_am = 0; // Can check out?
            $rooms_booked_pm = 0; // Actual stay occupancy
            $min_price = null;
            $has_pending_pm = false;
            $has_pending_am = false;

            foreach ($room_ids as $rid) {
                // Check status for this room on this date
                $is_occupied_pm = false; // Night stay
                $is_blocked_pm = false; // Turnover block
                $is_occupied_am = false; // Morning checkout day

                foreach ($room_bookings[$rid] as $b) {
                    // Stay Occupancy (Night of date_str)
                    if ($date_str >= $b['check_in'] && $date_str < $b['check_out']) {
                        $is_occupied_pm = true; 
                        if ($b['status'] === 'pending') $has_pending_pm = true;
                    }
                    
                    // Prevent same-day turnover block (Afternoon of checkout)
                    // RATIONALE: Blocks check-in but doesn't count as a "stay" for visual occupancy.
                    // This aligns with individual calendar's status=available behavior.
                    if ($prevent_turnover && $date_str === (string) $b['check_out']) {
                        $is_blocked_pm = true;
                        if ($b['status'] === 'pending') $has_pending_pm = true;
                    }

                    // AM Occupancy (Morning of checkout)
                    if ($date_str > $b['check_in'] && $date_str <= $b['check_out']) {
                        $is_occupied_am = true; 
                        if ($b['status'] === 'pending') $has_pending_am = true;
                    }
                }

                // A room is NOT free PM if it's either occupied by a stay OR blocked by turnover
                if (!$is_occupied_pm && !$is_blocked_pm) {
                    $rooms_free_pm++;
                }
                
                // Track actual night occupancy separately for status visualization
                if ($is_occupied_pm) {
                    $rooms_booked_pm++;
                }

                if (!$is_occupied_am) {
                    $rooms_free_am++;
                }

                // Calculate price (lowest available)
                if (!$is_occupied_pm) {
                    $price = Pricing::calculate_daily_price($rid, $date_str);
                    if ($min_price === null || $price < $min_price) {
                        $min_price = $price;
                    }
                }
            }

            // Aggregated Status
            $status = 'available';
            $is_checkin = false; // White/Red (Free AM, Booked PM) -> Not selectable for check-in
            $is_checkout = false; // Red/White (Booked AM, Free PM) -> Selectable for check-in
            $booking_status = null;

            // If ALL rooms are occupied for a night stay
            if ($rooms_booked_pm === $total_rooms) {
                $status = 'booked';
                $booking_status = $has_pending_pm ? 'pending' : 'confirmed';

                // If some rooms are free AM (transition day), style as White/Red
                if ($rooms_free_am > 0) {
                    $is_checkin = true;
                }
            } elseif ($rooms_free_pm === 0) {
                // All rooms are either booked or turnover-blocked.
                // We show 'available' to match individual calendar visualization (White or Split),
                // but the price will be null and the frontend will see it's unselectable starting this day.
                $status = 'available';
                $booking_status = $has_pending_pm ? 'pending' : 'confirmed';
                
                if ($rooms_free_am === 0) {
                    $is_checkout = true; // Visual: Red/White
                }
            } else {
                // At least one room is free PM.
                // If ALL rooms are occupied AM, style as Red/White
                if ($rooms_free_am === 0) {
                    $is_checkout = true; // Visual: Red/White
                    $booking_status = $has_pending_am ? 'pending' : 'confirmed';
                }
            }

            $data[] = [
                'date' => $date_str,
                'status' => $status,
                'booking_status' => $booking_status,
                'price' => $min_price !== null ? $min_price : 0,
                'price_formatted' => $min_price !== null ? I18n::format_currency($min_price) : '-',
                'is_checkin' => $is_checkin,
                'is_checkout' => $is_checkout,
            ];
        }

        return rest_ensure_response($data);
    }

    /**
     * POST /recalculate-price — Calculate total price with children and extras.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function recalculate_price($request)
    {
        $room_id = $request->get_param('room_id');
        $check_in = $request->get_param('check_in');
        $check_out = $request->get_param('check_out');
        $guests = $request->get_param('guests') ?: 1;
        $children = $request->get_param('children') ?: 0;
        $child_ages = $request->get_param('child_ages') ?: array();
        $extras = $request->get_param('extras') ?: array();
        $payment_type = $request->get_param('mhbo_payment_type') ?: 'full';

        $calc = Pricing::calculate_booking_total($room_id, $check_in, $check_out, (int) $guests, $extras, (int) $children, $child_ages);

        if ($calc && get_option('mhbo_deposits_enabled', 0) && $payment_type === 'deposit') {
            $first_night_price = !empty($calc['daily_prices']) ? reset($calc['daily_prices']) : 0;
            $deposit_data = Pricing::calculate_deposit($calc['total'], (float)$first_night_price);
            if ($deposit_data) {
                $calc['deposit_amount'] = $deposit_data['deposit_amount'];
                $calc['remaining_balance'] = $deposit_data['remaining_balance'];
            }
        }

        if (!$calc) {
            return new \WP_Error(
                'mhbo_calculation_failed',
                __('Error calculating price. Please check input data.', 'modern-hotel-booking'),
                array('status' => 400)
            );
        }

        $tax_data = $calc['tax'] ?? array(
            'enabled' => false,
            'mode' => 'disabled',
            'totals' => array(
                'subtotal_net' => $calc['total'],
                'total_tax' => 0,
                'total_gross' => $calc['total']
            )
        );

        // Include deposit info for HTML rendering if present
        if (isset($calc['deposit_amount'])) {
            $tax_data['deposit_amount'] = $calc['deposit_amount'];
            $tax_data['remaining_balance'] = $calc['remaining_balance'];
        }

        return rest_ensure_response(array(
            'success' => true,
            'total' => (float) $calc['total'],
            'total_formatted' => I18n::format_currency($calc['total']),
            'room_total' => (float) $calc['room_total'],
            'children_total' => (float) ($calc['children_total'] ?? 0),
            'extras_total' => (float) $calc['extras_total'],
            'deposit_amount' => (float) ($calc['deposit_amount'] ?? 0),
            'deposit_amount_formatted' => isset($calc['deposit_amount']) ? I18n::format_currency($calc['deposit_amount']) : '',
            'remaining_balance' => (float) ($calc['remaining_balance'] ?? 0),
            'remaining_balance_formatted' => isset($calc['remaining_balance']) ? I18n::format_currency($calc['remaining_balance']) : '',
            'breakdown' => $calc,
            'tax' => $tax_data,
            'tax_breakdown_html' => (!\MHBO\Core\Tax::is_enabled() || get_option('mhbo_tax_display_frontend', 1)) ? \MHBO\Core\Tax::render_breakdown_html($tax_data, null, false, array(), false) : '',
        ));
    }

    /**
     * GET /tax-settings — Get current tax settings for frontend.
     *
     * @return \WP_REST_Response
     */
    public function get_tax_settings()
    {
        $tax = \MHBO\Core\Tax::get_settings();

        return rest_ensure_response(array(
            'enabled' => $tax['enabled'],
            'mode' => $tax['mode'],
            'label' => $tax['label'],
            'accommodation_rate' => (float) $tax['accommodation_rate'],
            'extras_rate' => (float) $tax['extras_rate'],
            'registration_number' => $tax['registration_number'],
            'display_frontend' => (bool) $tax['display_frontend'],
            'prices_include_tax' => (bool) $tax['prices_include_tax'],
        ));
    }

}
