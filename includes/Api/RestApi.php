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
    public function register_routes()
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
                'children_ages' => array(
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
        // The old behavior of accepting 'source' in body was a critical vulnerability
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
        $cache_key = 'mhbo_room_types_all';
        $room_types = wp_cache_get($cache_key, 'mhbo');

        if (false === $room_types) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, caching implemented above
            $room_types = $wpdb->get_results(
                "SELECT id, name, description, base_price, max_adults, max_children, total_rooms, amenities, image_url
                 FROM {$wpdb->prefix}mhbo_room_types ORDER BY id ASC"
            );
            wp_cache_set($cache_key, $room_types, 'mhbo', HOUR_IN_SECONDS);
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
        $rooms_cache_key = 'mhbo_rooms_with_types';
        $rooms = wp_cache_get($rooms_cache_key, 'mhbo');

        if (false === $rooms) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, caching implemented above
            $rooms = $wpdb->get_results(
                "SELECT r.id AS room_id, r.room_number, r.status, r.custom_price,
                        t.id AS type_id, t.name AS type_name, t.base_price, t.max_adults, t.max_children
                 FROM {$wpdb->prefix}mhbo_rooms r
                 JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
                 ORDER BY t.id, r.id"
            );
            wp_cache_set($rooms_cache_key, $rooms, 'mhbo', HOUR_IN_SECONDS);
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
        // Cache for a short time since calendar data is frequently accessed
        $bookings_cache_key = 'mhbo_calendar_bookings_' . $room_id;
        $bookings = wp_cache_get($bookings_cache_key, 'mhbo');

        if (false === $bookings) {
            // Respect pending expiration here too for calendar display
            $expiry_time = wp_date('Y-m-d H:i:s', strtotime('-60 minutes'));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, caching implemented above
            $bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT check_in, check_out, status FROM {$wpdb->prefix}mhbo_bookings 
                 WHERE room_id = %d 
                 AND status != 'cancelled'
                 AND NOT (status = 'pending' AND created_at < %s)",
                $room_id,
                $expiry_time
            ));
            wp_cache_set($bookings_cache_key, $bookings, 'mhbo', 1 * MINUTE_IN_SECONDS); // Reduced cache for more real-time feel
        }

        // Get room status to mark as unbookable if maintenance/hidden
        $cache_key = 'mhbo_room_status_' . $room_id;
        $room_status = wp_cache_get($cache_key, 'mhbo');

        if (false === $room_status) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
            $room_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d",
                $room_id
            )) ?: 'available';
            wp_cache_set($cache_key, $room_status, 'mhbo', HOUR_IN_SECONDS);
        }

        // Map each date to its booking status (pending or confirmed)
        $booked_dates = [];
        $check_ins = [];
        $check_outs = [];

        foreach ($bookings as $b) {
            $check_ins[] = $b->check_in;
            $check_outs[] = $b->check_out;
            try {
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

                // Determine availability status and booking status if applicable
                $is_booked = isset($booked_dates[$date_str]);
                // Room is unbookable if status is not 'available' OR if price is 0 (unbooked)
                $is_unbookable = ('available' !== $room_status) || (!$is_booked && $price <= 0);

                $data[] = [
                    'date' => $date_str,
                    'status' => $is_booked ? 'booked' : ($is_unbookable ? 'unbookable' : 'available'),
                    'booking_status' => $is_booked ? $booked_dates[$date_str] : null,
                    'is_checkin' => in_array($date_str, $check_ins, true),
                    'is_checkout' => in_array($date_str, $check_outs, true),
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

        // Get all rooms
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, data changes frequently
        $rooms = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}mhbo_rooms WHERE status = 'available'");

        if (empty($rooms)) {
            return rest_ensure_response([]);
        }

        $room_ids = array_column($rooms, 'id');
        $room_placeholders = implode(',', array_fill(0, count($room_ids), '%d'));

        // Fetch all bookings for these rooms in the period
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN clause with room IDs is handled properly via placeholders
        $sql = "SELECT room_id, check_in, check_out, status FROM {$wpdb->prefix}mhbo_bookings 
                WHERE room_id IN ($room_placeholders) 
                AND status != 'cancelled' 
                AND (check_in < %s AND check_out > %s)";

        $params = array_merge($room_ids, [$end_str, $start_str]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, dynamic query per request
        $bookings = $wpdb->get_results($wpdb->prepare($sql, $params));
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

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

            $rooms_free_pm = 0; // Can check in?
            $rooms_free_am = 0; // Can check out?
            $min_price = null;

            foreach ($room_ids as $rid) {
                // Check status for this room on this date
                $is_occupied_pm = false; // Is check-in or middle
                $is_occupied_am = false; // Is check-out or middle

                foreach ($room_bookings[$rid] as $b) {
                    if ($date_str >= $b['check_in'] && $date_str < $b['check_out']) {
                        $is_occupied_pm = true; // Included in [check_in, check_out)
                    }
                    if ($date_str > $b['check_in'] && $date_str <= $b['check_out']) {
                        $is_occupied_am = true; // Included in (check_in, check_out]
                    }
                }

                if (!$is_occupied_pm)
                    $rooms_free_pm++;
                if (!$is_occupied_am)
                    $rooms_free_am++;

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

            // If NO room is free for check-in (PM)
            if ($rooms_free_pm === 0) {
                $status = 'booked';
                $booking_status = 'confirmed';

                // If some rooms are free AM (e.g. checking out), style as White/Red
                if ($rooms_free_am > 0) {
                    $is_checkin = true; // Visual: White/Red
                }
            } else {
                // At least one room is free PM.
                // If NO room is free AM (all rooms occupied AM), style as Red/White
                if ($rooms_free_am === 0) {
                    $is_checkout = true; // Visual: Red/White
                    $booking_status = 'confirmed'; // Needed for styling
                }
                // Else (Free PM and Free AM) -> Full White (Normal available)
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
        $children_ages = $request->get_param('children_ages') ?: array();
        $extras = $request->get_param('extras') ?: array();

        $calc = Pricing::calculate_booking_total($room_id, $check_in, $check_out, $guests, $extras, (int) $children, $children_ages);

        if (!$calc) {
            return new \WP_Error(
                'mhbo_calculation_failed',
                __('Error calculating price. Please check input data.', 'modern-hotel-booking'),
                array('status' => 400)
            );
        }

        return rest_ensure_response(array(
            'success' => true,
            'total' => (float) $calc['total'],
            'total_formatted' => I18n::format_currency($calc['total']),
            'room_total' => (float) $calc['room_total'],
            'children_total' => (float) ($calc['children_total'] ?? 0),
            'extras_total' => (float) $calc['extras_total'],
            'breakdown' => $calc,
            'tax' => $calc['tax'] ?? array(
                'enabled' => false,
                'mode' => 'disabled',
                'totals' => array(
                    'subtotal_net' => $calc['total'],
                    'total_tax' => 0,
                    'total_gross' => $calc['total']
                )
            ),
            'tax_breakdown_html' => (!\MHBO\Core\Tax::is_enabled() || get_option('mhbo_tax_display_frontend', 1)) ? \MHBO\Core\Tax::render_breakdown_html($calc['tax'] ?? array()) : '',
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
