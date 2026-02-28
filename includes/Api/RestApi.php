<?php declare(strict_types=1);

/**
 * REST API endpoints for Modern Hotel Booking.
 *
 * Namespace: mhb/v1
 * Endpoints:
 *   GET  /rooms         — list room types
 *   GET  /availability  — check availability for date range
 *   POST /bookings      — create a booking (API key required)
 *   GET  /bookings/{id} — get booking details (API key required)
 *
 * @package MHB\Api
 * @since   2.0.1
 */

namespace MHB\Api;

if (!defined('ABSPATH')) {
    exit;
}

use MHB\Core\I18n;
use MHB\Core\Pricing;

/**
 * REST API endpoints for Modern Hotel Booking.
 *
 * Namespace: mhb/v1
 * Endpoints:
 *   GET  /rooms         — list room types
 *   GET  /availability  — check availability for date range
 *   POST /bookings      — create a booking (API key required)
 *   GET  /bookings/{id} — get booking details (API key required)
 *
 * @package MHB\Api
 * @since   2.0.1
 */
class RestApi
{
    /**
     * Register REST routes.
     */
    public function register_routes()
    {
        $namespace = 'mhb/v1';

        register_rest_route($namespace, '/rooms', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_rooms'),
            'permission_callback' => function ($request) {
                // Combine Pro check and Rate limiting
                $pro = $this->check_pro_access();
                if (is_wp_error($pro))
                    return $pro;
                return $this->check_read_access($request);
            },
        ));

        register_rest_route($namespace, '/availability', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_availability'),
            'permission_callback' => function ($request) {
                $pro = $this->check_pro_access();
                if (is_wp_error($pro))
                    return $pro;
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

        register_rest_route($namespace, '/bookings', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_booking'),
            'permission_callback' => array($this, 'check_api_key'),
            'args' => array(
                'room_id' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && intval($value) > 0;
                    },
                    'sanitize_callback' => 'absint',
                ),
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
                'customer_name' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'customer_email' => array(
                    'required' => true,
                    'validate_callback' => function ($value) {
                        return is_email($value);
                    },
                    'sanitize_callback' => 'sanitize_email',
                ),
                'customer_phone' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'language' => array(
                    'required' => false,
                    'sanitize_callback' => 'sanitize_key',
                ),
            ),
        ));

        register_rest_route($namespace, '/bookings/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_booking'),
            'permission_callback' => array($this, 'check_api_key'),
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

        // Payment webhook endpoint for Stripe/PayPal webhooks
        // SECURITY: Permission callback verifies webhook signature internally
        register_rest_route($namespace, '/payment-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_payment_webhook'),
            'permission_callback' => array($this, 'verify_webhook_permission'),
        ));

        // Tax settings endpoint for frontend
        register_rest_route($namespace, '/tax-settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_tax_settings'),
            'permission_callback' => array($this, 'check_read_access'),
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
        if (!false) {
            return new \WP_Error(
                'mhb_pro_required',
                esc_html(I18n::get_label('label_rest_pro_error')),
                array('status' => 403)
            );
        }
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
                    'mhb_unauthorized',
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
        $ip = \MHB\Core\Security::get_client_ip();
        if (empty($ip) || '0.0.0.0' === $ip) {
            // Can't determine IP, allow but log
            return true;
        }

        $transient_key = 'mhb_api_rate_' . md5($ip);
        $request_count = get_transient($transient_key);

        $limit = apply_filters('mhb_api_rate_limit', 60); // Default 60 requests
        $window = apply_filters('mhb_api_rate_window', 60); // Per 60 seconds

        if (false === $request_count) {
            set_transient($transient_key, 1, $window);
            return true;
        }

        if ($request_count >= $limit) {
            return new \WP_Error(
                'mhb_rate_limit_exceeded',
                esc_html(I18n::get_label('label_api_rate_limit')),
                array('status' => 429)
            );
        }

        set_transient($transient_key, $request_count + 1, $window);
        return true;
    }

    /**
     * Check API key from request header for sensitive endpoints.
     *
     * @param \WP_REST_Request $request Request object.
     * @return bool|\WP_Error
     */
    public function check_api_key($request)
    {
        $pro_check = $this->check_pro_access();
        if (is_wp_error($pro_check)) {
            return $pro_check;
        }

        $api_key = $request->get_header('X-MHB-API-Key');
        $stored_key = get_option('mhb_api_key', '');

        if (empty($stored_key)) {
            return new \WP_Error(
                'mhb_api_not_configured',
                esc_html(I18n::get_label('label_api_not_configured')),
                array('status' => 500)
            );
        }

        if (empty($api_key) || !hash_equals($stored_key, $api_key)) {
            return new \WP_Error(
                'mhb_unauthorized',
                esc_html(I18n::get_label('label_invalid_api_key')),
                array('status' => 401)
            );
        }

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

        // Check for Stripe signature
        $stripe_signature = isset($headers['stripe_signature']) ? $headers['stripe_signature'][0] : null;
        if ($stripe_signature) {
            return $this->verify_stripe_signature($payload, $stripe_signature);
        }

        // Check for PayPal authentication headers
        $paypal_auth = isset($headers['paypal_auth_algo']) ? $headers['paypal_auth_algo'][0] : null;
        if ($paypal_auth) {
            return $this->verify_paypal_signature($request);
        }

        // SECURITY: Reject webhooks without proper signatures
        // The old behavior of accepting 'source' in body was a critical vulnerability
        return new \WP_Error(
            'mhb_webhook_unauthorized',
            esc_html(I18n::get_label('label_webhook_sig_required')),
            array('status' => 401)
        );
    }

    /**
     * Verify Stripe webhook signature.
     * SECURITY: Implements proper HMAC signature verification.
     *
     * @param string $payload Raw request body.
     * @param string $signature Stripe signature header.
     * @return bool|\WP_Error
     */
    private function verify_stripe_signature($payload, $signature)
    {
        $mode = get_option('mhb_stripe_mode', 'test');
        $webhook_secret = get_option("mhb_stripe_{$mode}_webhook_secret", '');

        if (empty($webhook_secret)) {
            // SECURITY: Reject if no webhook secret configured
            return new \WP_Error(
                'mhb_webhook_not_configured',
                esc_html(I18n::get_label('label_stripe_webhook_secret_missing')),
                array('status' => 500)
            );
        }

        // Parse Stripe signature header
        // Format: t=1234567890,v1=abc123def456...
        $sig_elements = [];
        foreach (explode(',', $signature) as $item) {
            $parts = explode('=', $item, 2);
            if (count($parts) === 2) {
                $sig_elements[$parts[0]] = $parts[1];
            }
        }

        if (!isset($sig_elements['t']) || !isset($sig_elements['v1'])) {
            return new \WP_Error(
                'mhb_invalid_signature_format',
                esc_html(I18n::get_label('label_invalid_stripe_sig_format')),
                array('status' => 400)
            );
        }

        $timestamp = $sig_elements['t'];
        $expected_signature = $sig_elements['v1'];

        // SECURITY: Verify timestamp to prevent replay attacks (5 minute tolerance)
        $current_time = time();
        $tolerance = 300; // 5 minutes
        if (abs($current_time - $timestamp) > $tolerance) {
            return new \WP_Error(
                'mhb_webhook_expired',
                esc_html(I18n::get_label('label_webhook_expired')),
                array('status' => 400)
            );
        }

        // Compute expected signature
        $signed_payload = $timestamp . '.' . $payload;
        $computed_signature = hash_hmac('sha256', $signed_payload, $webhook_secret);

        // SECURITY: Use hash_equals to prevent timing attacks
        if (!hash_equals($expected_signature, $computed_signature)) {
            return new \WP_Error(
                'mhb_invalid_signature',
                esc_html(I18n::get_label('label_invalid_stripe_sig')),
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * Verify PayPal webhook signature.
     * SECURITY: Validates PayPal webhook authentication headers.
     *
     * @param \WP_REST_Request $request Request object.
     * @return bool|\WP_Error
     */
    private function verify_paypal_signature($request)
    {
        $mode = get_option('mhb_paypal_mode', 'sandbox');
        $client_id = get_option("mhb_paypal_{$mode}_client_id", '');
        $gateway = null /* Pro class removed */;
        $client_secret = $gateway->get_decrypted_secret(get_option("mhb_paypal_{$mode}_secret", ''));

        if (empty($client_id) || empty($client_secret)) {
            return new \WP_Error(
                'mhb_paypal_not_configured',
                esc_html(I18n::get_label('label_paypal_not_configured')),
                array('status' => 500)
            );
        }

        $headers = $request->get_headers();
        $payload = $request->get_body();
        $api_base = ('live' === $mode) ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        // 1. Get Access Token
        $auth_response = wp_remote_post($api_base . '/v1/oauth2/token', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => 'grant_type=client_credentials',
            'timeout' => 30,
        ));

        if (is_wp_error($auth_response)) {
            return false;
        }

        $auth_body = json_decode(wp_remote_retrieve_body($auth_response), true);
        $access_token = $auth_body['access_token'] ?? '';

        if (empty($access_token)) {
            return false;
        }

        // 2. Call PayPal to verify signature
        $webhook_id = get_option("mhb_paypal_{$mode}_webhook_id", '');
        if (empty($webhook_id)) {
            return new \WP_Error(
                'mhb_paypal_webhook_id_missing',
                __('PayPal Webhook ID is not configured.', 'modern-hotel-booking'),
                array('status' => 500)
            );
        }

        $verification_response = wp_remote_post($api_base . '/v1/notifications/verify-webhook-signature', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'auth_algo' => $headers['paypal_auth_algo'][0] ?? '',
                'cert_url' => $headers['paypal_cert_url'][0] ?? '',
                'transmission_id' => $headers['paypal_transmission_id'][0] ?? '',
                'transmission_sig' => $headers['paypal_transmission_sig'][0] ?? '',
                'transmission_time' => $headers['paypal_transmission_time'][0] ?? '',
                'webhook_id' => $webhook_id,
                'webhook_event' => json_decode($payload, true),
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($verification_response)) {
            return false;
        }

        $verification_body = json_decode(wp_remote_retrieve_body($verification_response), true);

        return (isset($verification_body['verification_status']) && $verification_body['verification_status'] === 'SUCCESS');
    }

    /**
     * GET /rooms — List all room types.
     *
     * @return \WP_REST_Response
     */
    public function get_rooms()
    {
        global $wpdb;

        // Try cache first - room types don't change frequently
        $cache_key = 'mhb_room_types_all';
        $room_types = wp_cache_get($cache_key, 'mhb');

        if (false === $room_types) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, caching implemented above
            $room_types = $wpdb->get_results(
                "SELECT id, name, description, base_price, max_adults, max_children, total_rooms, amenities, image_url
                 FROM {$wpdb->prefix}mhb_room_types ORDER BY id ASC"
            );
            wp_cache_set($cache_key, $room_types, 'mhb', HOUR_IN_SECONDS);
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
                'mhb_invalid_dates',
                esc_html(I18n::get_label('label_check_out_after')),
                array('status' => 400)
            );
        }

        // Get all rooms - cache this as room configuration rarely changes
        $rooms_cache_key = 'mhb_rooms_with_types';
        $rooms = wp_cache_get($rooms_cache_key, 'mhb');

        if (false === $rooms) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, caching implemented above
            $rooms = $wpdb->get_results(
                "SELECT r.id AS room_id, r.room_number, r.status, r.custom_price,
                        t.id AS type_id, t.name AS type_name, t.base_price, t.max_adults, t.max_children
                 FROM {$wpdb->prefix}mhb_rooms r
                 JOIN {$wpdb->prefix}mhb_room_types t ON r.type_id = t.id
                 ORDER BY t.id, r.id"
            );
            wp_cache_set($rooms_cache_key, $rooms, 'mhb', HOUR_IN_SECONDS);
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
     * POST /bookings — Create a new booking.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function create_booking($request)
    {
        global $wpdb;

        $room_id = absint($request->get_param('room_id'));
        $check_in = sanitize_text_field($request->get_param('check_in'));
        $check_out = sanitize_text_field($request->get_param('check_out'));
        $customer_name = sanitize_text_field($request->get_param('customer_name'));
        $customer_email = sanitize_email($request->get_param('customer_email'));
        $customer_phone = sanitize_text_field($request->get_param('customer_phone') ?: '');
        $language = sanitize_text_field($request->get_param('language') ?: I18n::get_current_language());

        // Validate required fields
        if (empty($customer_name) || empty($customer_email) || !is_email($customer_email)) {
            return new \WP_Error(
                'mhb_invalid_customer_data',
                esc_html(I18n::get_label('label_invalid_customer')),
                array('status' => 400)
            );
        }

        // Check availability
        $available = Pricing::is_room_available((int) $room_id, $check_in, $check_out);

        if (true !== $available) {
            return new \WP_Error(
                'mhb_room_unavailable',
                esc_html(I18n::get_label($available)),
                array('status' => 409)
            );
        }

        // Calculate total price using central logic
        $calc = Pricing::calculate_booking_total($room_id, $check_in, $check_out);

        if (!$calc) {
            return new \WP_Error(
                'mhb_invalid_dates',
                esc_html(I18n::get_label('label_invalid_dates')),
                array('status' => 400)
            );
        }

        $total_price = $calc['total'];

        $booking_token = wp_generate_password(32, false);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no alternative for bookings insertion
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'mhb_bookings',
            array(
                'room_id' => $room_id,
                'customer_name' => $customer_name,
                'customer_email' => $customer_email,
                'customer_phone' => $customer_phone,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'total_price' => $total_price,
                'status' => 'pending',
                'booking_token' => $booking_token,
                'booking_language' => $language,
                'source' => 'api',
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s')
        );

        if (!$inserted) {
            return new \WP_Error(
                'mhb_booking_failed',
                esc_html(I18n::get_label('label_booking_failed')),
                array('status' => 500)
            );
        }

        $booking_id = $wpdb->insert_id;

        // Invalidate calendar cache to ensure availability is updated
        \MHB\Core\Cache::invalidate_calendar_cache((int) $room_id);

        // Fire webhook
        do_action('mhb_booking_created', $booking_id);

        return rest_ensure_response(array(
            'id' => $booking_id,
            'room_id' => $room_id,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'total_price' => round($total_price, 2),
            'status' => 'pending',
            'booking_token' => $booking_token,
        ));
    }

    /**
     * GET /bookings/{id} — Get a single booking.
     * SECURITY: Requires API key and verifies booking access.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_booking($request)
    {
        global $wpdb;

        $id = absint($request->get_param('id'));

        $cache_key = 'mhb_booking_' . $id;
        $booking = wp_cache_get($cache_key, 'mhb_bookings');

        if (false === $booking) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mhb_bookings WHERE id = %d",
                $id
            ));
            if ($booking) {
                wp_cache_set($cache_key, $booking, 'mhb_bookings', HOUR_IN_SECONDS);
            }
        }

        if (!$booking) {
            return new \WP_Error(
                'mhb_not_found',
                esc_html(I18n::get_label('label_room_not_found')),
                array('status' => 404)
            );
        }

        // SECURITY: Verify access to this booking
        // Option 1: User is logged in and has manage_options capability (admin)
        // Option 2: API key is associated with this booking (via booking_reference)
        // Option 3: Request includes the booking's customer email for verification
        $has_access = $this->verify_booking_access($request, $booking);

        if (is_wp_error($has_access)) {
            return $has_access;
        }

        // Return limited data for non-admin access
        $is_admin = current_user_can('manage_options');

        $response_data = array(
            'id' => (int) $booking->id,
            'room_id' => (int) $booking->room_id,
            'check_in' => $booking->check_in,
            'check_out' => $booking->check_out,
            'total_price' => (float) $booking->total_price,
            'status' => $booking->status,
            'booking_language' => $booking->booking_language,
            'source' => $booking->source,
            'created_at' => $booking->created_at,
        );

        // Include PII only for admin access or verified owners
        if ($is_admin || 'owner' === $has_access) {
            $response_data['customer_name'] = $booking->customer_name;
            $response_data['customer_email'] = $booking->customer_email;
            $response_data['customer_phone'] = $booking->customer_phone;
            $breakdown = $booking->tax_breakdown ? json_decode($booking->tax_breakdown, true) : null;
            if (isset($breakdown['extras']) && is_array($breakdown['extras'])) {
                foreach ($breakdown['extras'] as &$extra) {
                    if (isset($extra['name'])) {
                        $extra['name'] = I18n::decode($extra['name'], $booking->booking_language);
                    }
                }
            }

            $response_data['tax'] = array(
                'enabled' => (bool) ($booking->tax_enabled ?? 0),
                'mode' => $booking->tax_mode ?? 'disabled',
                'subtotal_net' => (float) ($booking->subtotal_net ?? $booking->total_price),
                'total_tax' => (float) ($booking->total_tax ?? 0),
                'total_gross' => (float) ($booking->total_gross ?? $booking->total_price),
                'breakdown' => $breakdown,
            );
        }

        return rest_ensure_response($response_data);
    }

    /**
     * Verify access to a booking.
     * SECURITY: Prevents unauthorized access to booking PII.
     *
     * @param \WP_REST_Request $request Request object.
     * @param object $booking Booking object.
     * @return bool|string|\WP_Error True for admin, 'owner' for verified owner, WP_Error for denied.
     */
    private function verify_booking_access($request, $booking)
    {
        // Admin users have full access
        if (current_user_can('manage_options')) {
            return true;
        }

        // Check for booking reference in request (for customer verification)
        // This allows customers to view their own bookings with a reference code
        $booking_reference = $request->get_param('reference');
        if (!empty($booking_reference)) {
            $expected_reference = hash('sha256', $booking->id . $booking->customer_email . wp_salt('auth'));
            if (hash_equals($expected_reference, $booking_reference)) {
                return 'owner';
            }
        }

        // Check for email verification
        $verify_email = $request->get_param('verify_email');
        if (!empty($verify_email)) {
            $verify_email = sanitize_email($verify_email);
            if (hash_equals(strtolower($booking->customer_email), strtolower($verify_email))) {
                return 'owner';
            }
        }

        // SECURITY: Deny access by default
        return new \WP_Error(
            'mhb_access_denied',
            __('You do not have permission to access this booking.', 'modern-hotel-booking'),
            array('status' => 403)
        );
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
        $bookings_cache_key = 'mhb_calendar_bookings_' . $room_id;
        $bookings = wp_cache_get($bookings_cache_key, 'mhb');

        if (false === $bookings) {
            // Respect pending expiration here too for calendar display
            $expiry_time = wp_date('Y-m-d H:i:s', strtotime('-60 minutes'));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, caching implemented above
            $bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT check_in, check_out, status FROM {$wpdb->prefix}mhb_bookings 
                 WHERE room_id = %d 
                 AND status != 'cancelled'
                 AND NOT (status = 'pending' AND created_at < %s)",
                $room_id,
                $expiry_time
            ));
            wp_cache_set($bookings_cache_key, $bookings, 'mhb', 1 * MINUTE_IN_SECONDS); // Reduced cache for more real-time feel
        }

        // Get room status to mark as unbookable if maintenance/hidden
        $cache_key = 'mhb_room_status_' . $room_id;
        $room_status = wp_cache_get($cache_key, 'mhb');

        if (false === $room_status) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
            $room_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}mhb_rooms WHERE id = %d",
                $room_id
            )) ?: 'available';
            wp_cache_set($cache_key, $room_status, 'mhb', HOUR_IN_SECONDS);
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
                    'is_checkin' => in_array($date_str, $check_ins),
                    'is_checkout' => in_array($date_str, $check_outs),
                    'price' => $price,
                    'price_formatted' => I18n::format_currency($price)
                ];
            }
        } catch (\Exception $e) {
            return new \WP_Error('mhb_calendar_error', __('Error generating calendar data.', 'modern-hotel-booking'), array('status' => 500));
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
        $rooms = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}mhb_rooms WHERE status = 'available'");

        if (empty($rooms)) {
            return rest_ensure_response([]);
        }

        $room_ids = array_column($rooms, 'id');
        $room_placeholders = implode(',', array_fill(0, count($room_ids), '%d'));

        // Fetch all bookings for these rooms in the period
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN clause with room IDs is handled properly via placeholders
        $sql = "SELECT room_id, check_in, check_out, status FROM {$wpdb->prefix}mhb_bookings 
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
                'mhb_calculation_failed',
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
            'tax_breakdown_html' => (!\MHB\Core\Tax::is_enabled() || get_option('mhb_tax_display_frontend', 1)) ? \MHB\Core\Tax::render_breakdown_html($calc['tax'] ?? array()) : '',
        ));
    }

    /**
     * GET /tax-settings — Get current tax settings for frontend.
     *
     * @return \WP_REST_Response
     */
    public function get_tax_settings()
    {
        $tax = \MHB\Core\Tax::get_settings();

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

    /**
     * POST /payment-webhook - Handle Stripe/PayPal webhook events.
     * SECURITY: Signature verification is performed in verify_webhook_permission().
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_payment_webhook($request)
    {
        global $wpdb;

        // Get raw body for processing
        $payload = $request->get_body();
        $headers = $request->get_headers();

        // Determine webhook source (Stripe or PayPal)
        $stripe_signature = isset($headers['stripe_signature']) ? $headers['stripe_signature'][0] : null;
        $paypal_auth = isset($headers['paypal_auth_algo']) ? $headers['paypal_auth_algo'][0] : null;

        // Handle Stripe webhook
        if ($stripe_signature) {
            $event = json_decode($payload, true);
            return $this->process_stripe_event($event);
        }

        // Handle PayPal webhook
        if ($paypal_auth) {
            return $this->handle_paypal_webhook($payload, $headers);
        }

        // SECURITY: This should never be reached due to permission_callback verification
        return new \WP_Error(
            'mhb_invalid_webhook',
            __('Invalid webhook request.', 'modern-hotel-booking'),
            array('status' => 400)
        );
    }

    /**
     * Process Stripe webhook event.
     *
     * @param array $event Stripe event data.
     * @return \WP_REST_Response
     */
    private function process_stripe_event($event)
    {
        if (!isset($event['type'])) {
            return rest_ensure_response(array('status' => 'ignored', 'reason' => __('No event type', 'modern-hotel-booking')));
        }

        global $wpdb;

        switch ($event['type']) {
            case 'payment_intent.succeeded':
                $payment_intent = $event['data']['object'] ?? null;
                if ($payment_intent && isset($payment_intent['id'])) {
                    $cache_key = 'mhb_booking_tx_' . md5($payment_intent['id']);
                    $booking = wp_cache_get($cache_key, 'mhb_bookings');

                    if (false === $booking) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                        $booking = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}mhb_bookings WHERE payment_transaction_id = %s",
                            $payment_intent['id']
                        ));
                        if ($booking) {
                            wp_cache_set($cache_key, $booking, 'mhb_bookings', 5 * MINUTE_IN_SECONDS);
                        }
                    }

                    if ($booking) {
                        $amount = isset($payment_intent['amount']) ? $payment_intent['amount'] / 100 : null;
                        // PaymentGateways::update_payment_status() removed for Free version;
                    }
                }
                break;

            case 'payment_intent.payment_failed':
                $payment_intent = $event['data']['object'] ?? null;
                if ($payment_intent && isset($payment_intent['id'])) {
                    $cache_key = 'mhb_booking_tx_' . md5($payment_intent['id']);
                    $booking = wp_cache_get($cache_key, 'mhb_bookings');

                    if (false === $booking) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                        $booking = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}mhb_bookings WHERE payment_transaction_id = %s",
                            $payment_intent['id']
                        ));
                        if ($booking) {
                            wp_cache_set($cache_key, $booking, 'mhb_bookings', 5 * MINUTE_IN_SECONDS);
                        }
                    }

                    if ($booking) {
                        $error_message = isset($payment_intent['last_payment_error']['message'])
                            ? $payment_intent['last_payment_error']['message']
                            : __('Payment failed', 'modern-hotel-booking');
                        // PaymentGateways::update_payment_status() removed for Free version;
                    }
                }
                break;

            case 'charge.refunded':
                $charge = $event['data']['object'] ?? null;
                if ($charge && isset($charge['payment_intent'])) {
                    $cache_key = 'mhb_booking_tx_' . md5($charge['payment_intent']);
                    $booking = wp_cache_get($cache_key, 'mhb_bookings');

                    if (false === $booking) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                        $booking = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}mhb_bookings WHERE payment_transaction_id = %s",
                            $charge['payment_intent']
                        ));
                        if ($booking) {
                            wp_cache_set($cache_key, $booking, 'mhb_bookings', 5 * MINUTE_IN_SECONDS);
                        }
                    }
                    if ($booking) {
                        // PaymentGateways::update_payment_status() removed for Free version;
                    }
                }
                break;
        }

        // Fire action for extensibility
        do_action('mhb_stripe_webhook_received', $event);

        return rest_ensure_response(array('status' => 'received', 'event_type' => $event['type']));
    }

    /**
     * Handle PayPal webhook with header verification.
     *
     * @param string $payload Raw request body.
     * @param array $headers Request headers.
     * @return \WP_REST_Response|\WP_Error
     */
    private function handle_paypal_webhook($payload, $headers)
    {
        // For full verification, you'd verify the PayPal signature
        // This is a simplified version
        $event = json_decode($payload, true);
        return $this->process_paypal_event($event);
    }

    /**
     * Process PayPal webhook event.
     *
     * @param array $event PayPal event data.
     * @return \WP_REST_Response
     */
    private function process_paypal_event($event)
    {
        if (!isset($event['event_type'])) {
            return rest_ensure_response(array('status' => 'ignored', 'reason' => __('No event type', 'modern-hotel-booking')));
        }

        global $wpdb;

        switch ($event['event_type']) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                $resource = $event['resource'] ?? null;
                if ($resource) {
                    $order_id = $resource['supplementary_data']['related_ids']['order_id'] ?? $resource['id'] ?? null;

                    if ($order_id) {
                        $cache_key = 'mhb_booking_tx_' . md5($order_id);
                        $booking = wp_cache_get($cache_key, 'mhb_bookings');

                        if (false === $booking) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                            $booking = $wpdb->get_row($wpdb->prepare(
                                "SELECT id FROM {$wpdb->prefix}mhb_bookings WHERE payment_transaction_id = %s",
                                $order_id
                            ));
                            if ($booking) {
                                wp_cache_set($cache_key, $booking, 'mhb_bookings', 5 * MINUTE_IN_SECONDS);
                            }
                        }

                        if ($booking) {
                            $amount = isset($resource['amount']['value']) ? floatval($resource['amount']['value']) : null;
                            // PaymentGateways::update_payment_status() removed for Free version;
                        }
                    }
                }
                break;

            case 'PAYMENT.CAPTURE.DENIED':
            case 'PAYMENT.CAPTURE.REFUNDED':
                $resource = $event['resource'] ?? null;
                if ($resource) {
                    $order_id = $resource['supplementary_data']['related_ids']['order_id'] ?? $resource['id'] ?? null;

                    if ($order_id) {
                        $cache_key = 'mhb_booking_tx_' . md5($order_id);
                        $booking = wp_cache_get($cache_key, 'mhb_bookings');

                        if (false === $booking) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                            $booking = $wpdb->get_row($wpdb->prepare(
                                "SELECT id FROM {$wpdb->prefix}mhb_bookings WHERE payment_transaction_id = %s",
                                $order_id
                            ));
                            if ($booking) {
                                wp_cache_set($cache_key, $booking, 'mhb_bookings', 5 * MINUTE_IN_SECONDS);
                            }
                        }

                        if ($booking) {
                            $status = ($event['event_type'] === 'PAYMENT.CAPTURE.REFUNDED') ? 'refunded' : 'failed';
                            // PaymentGateways::update_payment_status() removed for Free version;
                        }
                    }
                }
                break;

            case 'CHECKOUT.ORDER.APPROVED':
                // Order approved but not yet captured - mark as processing
                $resource = $event['resource'] ?? null;
                if ($resource && isset($resource['id'])) {
                    $cache_key = 'mhb_booking_tx_' . md5($resource['id']);
                    $booking = wp_cache_get($cache_key, 'mhb_bookings');

                    if (false === $booking) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented above
                        $booking = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}mhb_bookings WHERE payment_transaction_id = %s",
                            $resource['id']
                        ));
                        if ($booking) {
                            wp_cache_set($cache_key, $booking, 'mhb_bookings', 5 * MINUTE_IN_SECONDS);
                        }
                    }
                    if ($booking) {
                        // PaymentGateways::update_payment_status() removed for Free version;
                    }
                }
                break;
        }

        // Fire action for extensibility
        do_action('mhb_paypal_webhook_received', $event);

        return rest_ensure_response(array('status' => 'received', 'event_type' => $event['event_type']));
    }
}
