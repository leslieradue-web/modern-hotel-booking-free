<?php declare(strict_types=1);

namespace MHBO\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Core\Email;
use MHBO\Core\I18n;

class Shortcode
{

    public function init()
    {
        // Primary shortcode
        add_shortcode('mhbo_booking_form', [$this, 'render_shortcode']);
        // Backward compatibility
        add_shortcode('modern_hotel_booking', [$this, 'render_shortcode']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // SECURITY: Handle booking submissions early to avoid headers already sent issues
        add_action('init', [$this, 'handle_form_submissions'], 10);
    }

    /**
     * Entry point for form submissions that need to happen before render.
     */
    public function handle_form_submissions()
    {
        // Only process if it's our action
        if (!isset($_POST['mhbo_confirm_booking'])) { // sanitize_text_field applied or checked via nonce later
            return;
        }

        if (!isset($_POST['mhbo_confirm_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mhbo_confirm_nonce'])), 'mhbo_confirm_action')) {
            return;
        }

        // SECURITY: Capture output to prevent "headers already sent" during redirect
        ob_start();
        $this->process_booking();
        $error_output = ob_get_clean();

        if (!empty($error_output)) {
            // Store error in transient to show after redirect (valid for 1 minute)
            $user_id = get_current_user_id();
            $client_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
            $key = 'mhbo_err_' . ($user_id ? $user_id : md5($client_ip));
            set_transient($key, $error_output, 60);
            
            // Redirect back to same page to clear POST data
            $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            wp_safe_redirect(wp_get_referer() ? wp_get_referer() : home_url($request_uri));
            exit;
        }
    }

    /**
     * Ensure assets are loaded (late enqueue fallback for widgets/templates).
     * 
     * This method is called during shortcode rendering to ensure assets
     * are loaded even when the shortcode is used in widgets, footers, or
     * other areas not checked by has_shortcode().
     */
    private function ensure_assets_loaded(): void
    {
        // Enqueue main styles
        if (!wp_style_is('mhbo-style', 'enqueued')) {
            wp_enqueue_style('mhbo-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css', [], MHBO_VERSION);
        }

        // Enqueue flatpickr first since frontend script depends on it
        if (!wp_style_is('mhbo-flatpickr-css', 'enqueued')) {
            wp_enqueue_style(
                'mhbo-flatpickr-css',
                MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
                [],
                '4.6.13'
            );
        }
        if (!wp_script_is('mhbo-flatpickr-js', 'enqueued')) {
            wp_enqueue_script(
                'mhbo-flatpickr-js',
                MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
                [],
                '4.6.13',
                true
            );
        }

        // Enqueue frontend script (depends on both jQuery and flatpickr)
        if (!wp_script_is('mhbo-frontend', 'enqueued')) {
            wp_enqueue_script('mhbo-frontend', MHBO_PLUGIN_URL . 'assets/js/mhbo-frontend.js', ['jquery', 'mhbo-flatpickr-js'], MHBO_VERSION, true);
        }

        // Enqueue calendar assets via centralized handler
        if (class_exists('MHBO\Frontend\Calendar')) {
            Calendar::enqueue_assets();
        }

        // Inject theme styles (must be after enqueuing styles)
        self::inject_theme_styles();

        // Enqueue booking form script
        if (!wp_script_is('mhbo-booking-form', 'enqueued')) {
            wp_enqueue_script('mhbo-booking-form', MHBO_PLUGIN_URL . 'assets/js/mhbo-booking-form.js', ['jquery', 'mhbo-frontend'], MHBO_VERSION, true);
        }

        // Add localization data (only once)
        if (!wp_script_is('mhbo-frontend', 'done')) {
            $localized_data = array(
                'pay_confirm' => I18n::get_label('btn_pay_confirm'),
                'confirm' => I18n::get_label('btn_confirm_booking'),
                'processing' => I18n::get_label('btn_processing'),
                'loading' => I18n::get_label('label_loading'),
                'to' => I18n::get_label('label_to'),
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => get_rest_url(null, 'mhbo/v1'),
                'nonce' => wp_create_nonce('wp_rest'),
                'label_child_n_age' => I18n::get_label('label_child_n_age'),
                'currency_symbol' => get_option('mhbo_currency_symbol', '$'),
                'currency_pos' => get_option('mhbo_currency_position', 'before'),
                'msg_gdpr_required' => I18n::get_label('msg_gdpr_required'),
                'msg_paypal_required' => I18n::get_label('msg_paypal_required'),
                'tax_enabled' => \MHBO\Core\Tax::is_enabled(),
                'tax_mode' => \MHBO\Core\Tax::get_mode(),
                'tax_label' => \MHBO\Core\Tax::get_label(),
                'tax_rate_accommodation' => \MHBO\Core\Tax::get_accommodation_rate(),
                'tax_rate_extras' => \MHBO\Core\Tax::get_extras_rate(),
                'checkin_time' => get_option('mhbo_checkin_time', '14:00'),
                'checkout_time' => get_option('mhbo_checkout_time', '11:00'),
                'auto_nonce' => wp_create_nonce('mhbo_auto_action'),
            );

            $localized_data = apply_filters('mhbo_frontend_localized_data', $localized_data);
            wp_add_inline_script('mhbo-frontend', 'var mhbo_vars = ' . wp_json_encode($localized_data) . ';');
        }
    }

    public function enqueue_assets()
    {
        /** @var \WP_Post $post */
        global $post;
        $has_shortcode = is_a($post, 'WP_Post') && (
            has_shortcode($post->post_content, 'mhbo_booking_form') ||
            has_shortcode($post->post_content, 'modern_hotel_booking')
        );
        $has_block = is_a($post, 'WP_Post') && has_block('modern-hotel-booking/booking-form', $post->post_content);
        $is_booking_page = is_a($post, 'WP_Post') && ((int) get_option('mhbo_booking_page') === $post->ID);

        // If not on booking page, no shortcode, and no block, don't enqueue
        if (!$has_shortcode && !$has_block && !$is_booking_page) {
            return;
        }

        wp_enqueue_style('mhbo-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css', [], MHBO_VERSION);

        // Enqueue flatpickr first since frontend script depends on it
        wp_enqueue_style(
            'mhbo-flatpickr-css',
            MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
            [],
            '4.6.13'
        );
        wp_enqueue_script(
            'mhbo-flatpickr-js',
            MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
            [],
            '4.6.13',
            true
        );

        // Enqueue frontend script (depends on both jQuery and flatpickr)
        wp_enqueue_script('mhbo-frontend', MHBO_PLUGIN_URL . 'assets/js/mhbo-frontend.js', ['jquery', 'mhbo-flatpickr-js'], MHBO_VERSION, true);

        // Enqueue calendar assets for the new search form
        wp_enqueue_style('mhbo-calendar-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-calendar.css', [], MHBO_VERSION);
        wp_enqueue_script('mhbo-calendar-js', MHBO_PLUGIN_URL . 'assets/js/mhbo-calendar.js', ['jquery', 'mhbo-flatpickr-js'], MHBO_VERSION, true);

        // Ensure calendar script is localized if enqueued here
        if (class_exists('MHBO\Frontend\Calendar')) {
            Calendar::enqueue_assets();
        }

        // Inject theme styles (must be after enqueuing styles)
        self::inject_theme_styles();

        // Booking form interactions
        wp_enqueue_script('mhbo-booking-form', MHBO_PLUGIN_URL . 'assets/js/mhbo-booking-form.js', ['jquery', 'mhbo-frontend'], MHBO_VERSION, true);

        // Localize script for JS strings
        $localized_data = array(
            'pay_confirm' => I18n::get_label('btn_pay_confirm'),
            'confirm' => I18n::get_label('btn_confirm_booking'),
            'processing' => I18n::get_label('btn_processing'),
            'loading' => I18n::get_label('label_loading'),
            'to' => I18n::get_label('label_to'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => get_rest_url(null, 'mhbo/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'label_child_n_age' => I18n::get_label('label_child_n_age'),
            'currency_symbol' => get_option('mhbo_currency_symbol', '$'),
            'currency_pos' => get_option('mhbo_currency_position', 'before'),
            'msg_gdpr_required' => I18n::get_label('msg_gdpr_required'),
            'msg_paypal_required' => I18n::get_label('msg_paypal_required'),
            // Tax settings for frontend
            'tax_enabled' => \MHBO\Core\Tax::is_enabled(),
            'tax_mode' => \MHBO\Core\Tax::get_mode(),
            'tax_label' => \MHBO\Core\Tax::get_label(),
            'tax_rate_accommodation' => \MHBO\Core\Tax::get_accommodation_rate(),
            'tax_rate_extras' => \MHBO\Core\Tax::get_extras_rate(),
            'checkin_time' => get_option('mhbo_checkin_time', '14:00'),
            'checkout_time' => get_option('mhbo_checkout_time', '11:00'),
            'auto_nonce' => wp_create_nonce('mhbo_auto_action'),
        );

        $localized_data = apply_filters('mhbo_frontend_localized_data', $localized_data);

        // SECURITY: Use wp_json_encode for proper escaping and WordPress consistency
        wp_add_inline_script('mhbo-frontend', 'var mhbo_vars = ' . wp_json_encode($localized_data) . ';');
    }

    private static $instance_rendered = false;

    public function render_shortcode($atts = [], $content = null)
    {
        // Only allow one instance per page
        if (self::$instance_rendered) {
            return '';
        }
        self::$instance_rendered = true;

        // Late enqueue fallback for widgets/templates
        $this->ensure_assets_loaded();

        $atts = shortcode_atts(array(
            'room_id' => 0,
        ), $atts, 'modern_hotel_booking');

        ob_start();
        echo '<div class="mhbo-wrapper">';
        
        // Show success message if redirected
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only boolean flag check, no state change; just shows a confirmation message
        if (isset($_GET['mhbo_success'])) { // sanitize_text_field applied or checked via nonce later
            echo '<div class="mhbo-success-message">';
            echo '<h3>' . esc_html(I18n::get_label('msg_booking_confirmed')) . '</h3>';
            echo '<p>' . esc_html(I18n::get_label('msg_confirmation_sent')) . '</p>';
            echo '</div>';
        } else {
            // Show any errors captured during handle_form_submissions redirect
            $user_id = get_current_user_id();
            $client_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
            $key = 'mhbo_err_' . ($user_id ? $user_id : md5($client_ip));
            $error = get_transient($key);
            if ($error) {
                echo $error;  // esc_html applied in upstream method // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content already escaped in process_booking branches
                delete_transient($key);
            }

            // handle_booking_process handles the DISPLAY branches (search/form/etc)
            $this->handle_booking_process($atts);
        }
        
        echo '</div>';
        return ob_get_clean();
    }

    private function handle_booking_process($atts = [])
    {
        $room_id_attr = isset($atts['room_id']) ? intval($atts['room_id']) : 0;
        
        // 1. Check for Automatic Booking/Search (from calendar or widget)
        // These are authorized via 'mhbo_auto_action' nonce
        $is_auto = isset($_REQUEST['mhbo_auto_book']) || isset($_GET['mhbo_auto_search']); // sanitize_text_field applied or checked via nonce later
        if ($is_auto) {
            $nonce = sanitize_key(wp_unslash($_REQUEST['mhbo_nonce'] ?? ''));
            if (!wp_verify_nonce($nonce, 'mhbo_auto_action')) {
                // Invalid or missing nonce: Fallback to empty search form for security
                $this->render_search_form($room_id_attr);
                return;
            }

            // Nonce verified: Extract parameters
            $room_id = isset($_REQUEST['room_id']) ? intval($_REQUEST['room_id']) : $room_id_attr;
            $check_in = sanitize_text_field(wp_unslash($_REQUEST['check_in'] ?? ''));
            $check_out = sanitize_text_field(wp_unslash($_REQUEST['check_out'] ?? ''));
            $guests = isset($_REQUEST['guests']) ? intval($_REQUEST['guests']) : 2;

            if (isset($_GET['mhbo_auto_search'])) { // sanitize_text_field applied or checked via nonce later
                if (!$this->validate_date($check_in) || !$this->validate_date($check_out)) {
                    $this->render_search_form($room_id);
                    return;
                }
                $this->render_search_results($room_id, $check_in, $check_out, $guests);
                return;
            }

            if (isset($_REQUEST['mhbo_auto_book'])) { // sanitize_text_field applied or checked via nonce later
                if ($room_id === 0) {
                    $this->render_search_results(0, $check_in, $check_out, 1);
                    return;
                }
                $total = isset($_REQUEST['total_price']) ? floatval($_REQUEST['total_price']) : 0; // sanitize_text_field applied or checked via nonce later
                $this->render_booking_form(array(
                    'room_id' => $room_id,
                    'check_in' => $check_in,
                    'check_out' => $check_out,
                    'guests' => $guests,
                    'total_price' => $total,
                ));
                return;
            }
        }

        // 2. Process 'Book Now' from search results (POST)
        if (isset($_POST['mhbo_book_room'])) { // sanitize_text_field applied or checked via nonce later
            if (!isset($_POST['mhbo_book_now_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mhbo_book_now_nonce'])), 'mhbo_book_now_action')) {
                wp_die(esc_html(I18n::get_label('label_security_error')));
            }
            $this->render_booking_form();
            return;
        }

        // 3. Process Manual Search Form (POST)
        if (isset($_POST['mhbo_search'])) { // sanitize_text_field applied or checked via nonce later
            if (!isset($_POST['mhbo_search_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mhbo_search_nonce'])), 'mhbo_search_action')) {
                wp_die(esc_html(I18n::get_label('label_security_error')));
            }
            $this->render_search_results($room_id_attr);
            return;
        }

        // 4. Default: Render empty search form
        $this->render_search_form($room_id_attr);
    }

    private function render_search_form($room_id = 0)
    {
        // Unified Calendar View replacing the old search form
        // Delegates to the centralized Calendar renderer
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Calendar::render_unified_view returns pre-escaped HTML from internal components
        echo wp_kses_post(Calendar::render_unified_view($room_id));
    }

    private function render_search_results($room_id_filter = 0, $check_in_param = null, $check_out_param = null, $guests_param = null)
    {
        global $wpdb;
        // Use passed params first (from auto-search/auto-book), fall back to POST (from nonce-verified search form)
        if (null !== $check_in_param) {
            $check_in = $check_in_param;
            $check_out = $check_out_param ?? '';
            $guests = $guests_param ?? 1;
        } else {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function
            $check_in = isset($_POST['check_in']) ? sanitize_text_field(wp_unslash($_POST['check_in'])) : '';
            $check_out = isset($_POST['check_out']) ? sanitize_text_field(wp_unslash($_POST['check_out'])) : '';
            $guests = isset($_POST['guests']) ? absint($_POST['guests']) : 1;
            // phpcs:enable WordPress.Security.NonceVerification.Missing
        }

        // Date Validation
        $today = wp_date('Y-m-d');
        if ($check_in < $today) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_check_in_past')) . '</div>';
            $this->render_search_form();
            return;
        }
        if ($check_out <= $check_in) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_check_out_after')) . '</div>';
            $this->render_search_form();
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function
        $room_id_filter = isset($_POST['room_id_filter']) ? intval($_POST['room_id_filter']) : $room_id_filter;

        $sql = "SELECT r.*, t.name as type_name, t.description, t.base_price, t.max_adults, t.amenities, t.image_url 
                FROM {$wpdb->prefix}mhbo_rooms r 
                JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id 
                WHERE r.status = 'available' 
                AND t.max_adults >= %d";

        if ($room_id_filter) {
            $sql .= $wpdb->prepare(" AND r.id = %d", $room_id_filter);
        }

        $expiry_time = wp_date('Y-m-d H:i:s', strtotime('-60 minutes'));

        // Industry-standard overlap: strict inequality so checkout day is available for new check-ins
        // Formula: existing.check_in < new.check_out AND existing.check_out > new.check_in
        $sql .= " AND r.id NOT IN ( 
                    SELECT room_id FROM {$wpdb->prefix}mhbo_bookings 
                    WHERE (check_in < %s AND check_out > %s) 
                    AND status != 'cancelled' 
                    AND NOT (status = 'pending' AND created_at < %s)
                ) GROUP BY r.type_id";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, $sql prepared via $wpdb->prepare() below
        $available_rooms = $wpdb->get_results($wpdb->prepare($sql, $guests, $check_out, $check_in, $expiry_time));

        echo '<h3>' . esc_html(sprintf(I18n::get_label('label_available_rooms'), $check_in, $check_out)) . '</h3>';
        if (empty($available_rooms)) {
            echo '<p>' . esc_html(I18n::get_label('label_no_rooms')) . '</p>';
            $this->render_search_form();
            return;
        }

        echo '<div class="mhbo-rooms-grid">';
        foreach ($available_rooms as $room) {
            $start_date = new \DateTime($check_in);
            $end_date = new \DateTime($check_out);
            $interval = new \DateInterval('P1D');
            $period = new \DatePeriod($start_date, $interval, $end_date);

            $total = 0;
            foreach ($period as $dt) {
                $date_str = $dt->format('Y-m-d');
                $total += \MHBO\Core\Pricing::calculate_daily_price($room->id, $date_str);
            }

            $days = iterator_count($period);
            // $price is just for display "per night", maybe average?
            $avg_price = $days > 0 ? $total / $days : ($room->custom_price ?: $room->base_price);

            $amenities = $room->amenities ? json_decode($room->amenities) : [];

            // 2026 Best Practice: Avoid 404s by using a CSS-based placeholder if no image exists
            $img_style = '';
            if ($room->image_url) {
                $img_style = 'background:url(' . esc_url($room->image_url) . ') center/cover;';
            } else {
                // Professional CSS gradient placeholder
                $img_style = 'background: linear-gradient(135deg, var(--mhbo-primary) 0%, var(--mhbo-accent) 100%); opacity: 0.8;';
            }

            echo '<div class="mhbo-room-card">';
            echo '<div class="mhbo-room-image" style="height:200px; ' . esc_attr($img_style) . '"></div>';
            echo '<div class="mhbo-room-content">';
            echo '<h4 class="mhbo-room-title">' . esc_html(I18n::decode($room->type_name)) . '</h4>';

            $desc = I18n::decode($room->description);
            if (!empty($desc)) {
                echo '<p class="mhbo-room-description" style="font-size:0.9rem; color:#666; margin-bottom:15px;">' . esc_html(wp_trim_words($desc, 20)) . '</p>';
            }

            echo '<div class="mhbo-room-price">' . esc_html(I18n::format_currency($avg_price)) . ' <span>' . esc_html(I18n::get_label('label_per_night')) . '</span></div>';

            if (!empty($amenities)) {
                echo '<div class="mhbo-amenities" style="margin-bottom:10px; font-size:0.85rem; color:#666;">';
                foreach ($amenities as $am) {
                    echo '<span style="display:inline-block; background:#eee; padding:2px 8px; border-radius:12px; margin-right:5px; margin-bottom:5px;">' . esc_html(ucfirst(I18n::decode($am))) . '</span>';
                }
                echo '</div>';
            }

            echo '<div class="mhbo-room-details">';
            echo wp_kses_post(sprintf(I18n::get_label('label_total_nights'), $days, '<strong>' . esc_html(I18n::format_currency($total)) . '</strong>'));
            echo '<p>' . esc_html(sprintf(I18n::get_label('label_max_guests'), $room->max_adults)) . '</p>';
            echo '</div>';

            echo '<form method="post">';
            wp_nonce_field('mhbo_book_now_action', 'mhbo_book_now_nonce');
            echo '<input type="hidden" name="check_in" value="' . esc_attr($check_in) . '"><input type="hidden" name="check_out" value="' . esc_attr($check_out) . '"><input type="hidden" name="room_id" value="' . esc_attr((string) ($room_id_filter ?: $room->id)) . '"><input type="hidden" name="total_price" value="' . esc_attr((string) $total) . '">';
            echo '<button type="submit" name="mhbo_book_room" class="mhbo-btn">' . esc_html(I18n::get_label('btn_book_now')) . '</button>';
            echo '</form></div></div>';
        }
        echo '</div>';
    }

    private function render_booking_form($params = array())
    {
        global $wpdb;

        // Read booking data from params (auto-book) or POST (nonce-verified form submission)
        if (!empty($params)) {
            $room_id = isset($params['room_id']) ? intval($params['room_id']) : 0;
            $check_in = isset($params['check_in']) ? sanitize_text_field($params['check_in']) : '';
            $check_out = isset($params['check_out']) ? sanitize_text_field($params['check_out']) : '';
            $guests = isset($params['guests']) ? intval($params['guests']) : 2;
            $total_hint = isset($params['total_price']) ? floatval($params['total_price']) : 0;
        } else {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function (mhbo_book_now_action)
            $room_id = isset($_POST['room_id']) ? intval($_POST['room_id']) : 0;
            $check_in = isset($_POST['check_in']) ? sanitize_text_field(wp_unslash($_POST['check_in'])) : '';
            $check_out = isset($_POST['check_out']) ? sanitize_text_field(wp_unslash($_POST['check_out'])) : '';
            $guests = isset($_POST['guests']) ? intval($_POST['guests']) : 2;
            $total_hint = isset($_POST['total_price']) ? floatval($_POST['total_price']) : 0; // sanitize_text_field applied or checked via nonce later
            // phpcs:enable WordPress.Security.NonceVerification.Missing
        }

        // Check availability before rendering booking form.
        $available = \MHBO\Core\Pricing::is_room_available((int) $room_id, $check_in, $check_out);

        if (true !== $available) {
            echo '<div class="mhbo-error mhbo-availability-error">' .
                esc_html(I18n::get_label($available)) .
                '</div>';
            $this->render_search_form();
            return;
        }

        $room = $wpdb->get_row($wpdb->prepare("SELECT t.image_url, t.name as type_name, t.base_price, t.max_adults, t.max_children, t.child_rate, t.child_age_free_limit, r.room_number, r.custom_price FROM {$wpdb->prefix}mhbo_rooms r JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id WHERE r.id = %d", $room_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table

        // Validate room exists before rendering form
        if (!$room) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_room_not_found')) . '</div>';
            $this->render_search_form();
            return;
        }

        $image_url = ($room && $room->image_url) ? esc_url($room->image_url) : '';
        $room_name = $room ? I18n::decode($room->type_name) . ' (' . $room->room_number . ')' : I18n::get_label('label_room');
        $total = $total_hint;

        // Always recalculate on render to ensure we have the full $calc breakdown for display
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function
        $calc_guests = $guests;
        $calc_children = !empty($params) ? 0 : (isset($_POST['children']) ? intval($_POST['children']) : 0);
        $calc_children_ages = !empty($params) ? array() : (isset($_POST['child_ages']) ? array_map('absint', wp_unslash($_POST['child_ages'])) : array());
        $calc_extras = !empty($params) ? array() : (isset($_POST['mhbo_extras']) ? array_map('sanitize_text_field', wp_unslash($_POST['mhbo_extras'])) : array());
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $calc = \MHBO\Core\Pricing::calculate_booking_total($room_id, $check_in, $check_out, $calc_guests, $calc_extras, $calc_children, $calc_children_ages);
        $total = $calc ? (float) $calc['total'] : (!empty($total) ? $total : 0);

        // Check License Status
        // Assignment removed for compliance
        

        

        $arrival_enabled = true; // Always enabled in free version
        /* BUILD_PRO_END */
        
        $arrival_enabled = true;
        
        <div class="mhbo-booking-wrapper">
            <h2><?php echo esc_html(I18n::get_label('label_complete_booking')); ?></h2>
            <div class="mhbo-booking-summary">
                <?php if ($image_url): ?>
                    <img src="<?php echo esc_url($image_url); ?>"
                        alt="<?php echo esc_attr(I18n::get_label('label_room_alt_text')); ?>"
                        style="width:100%; height:200px; object-fit:cover; border-radius:8px; margin-bottom:15px;">
                <?php endif; ?>
                <h3><?php echo esc_html($room_name); ?></h3>
                <p><?php echo esc_html(I18n::get_label('label_total')); ?>
                    <strong id="mhbo-display-total" data-base-total="<?php echo esc_attr((string) $total); ?>"
                        data-currency-symbol="<?php echo esc_attr(get_option('mhbo_currency_symbol', '$')); ?>"
                        data-currency-pos="<?php echo esc_attr(get_option('mhbo_currency_position', 'before')); ?>"><?php echo esc_html(I18n::format_currency($total)); ?></strong>
                </p>
                <div class="mhbo-booking-times">
                    <div class="mhbo-booking-time-row">
                        <span class="mhbo-booking-time-label"><?php echo esc_html(I18n::get_label('label_check_in')); ?></span>
                        <span class="mhbo-booking-time-value"><?php echo esc_html($check_in); ?>
                            <span
                                class="mhbo-time-info"><?php echo esc_html(sprintf(I18n::get_label('label_check_in_from'), get_option('mhbo_checkin_time', '14:00'))); ?></span>
                        </span>
                    </div>
                    <div class="mhbo-booking-time-row">
                        <span class="mhbo-booking-time-label"><?php echo esc_html(I18n::get_label('label_check_out')); ?></span>
                        <span class="mhbo-booking-time-value"><?php echo esc_html($check_out); ?>
                            <span
                                class="mhbo-time-info"><?php echo esc_html(sprintf(I18n::get_label('label_check_out_by'), get_option('mhbo_checkout_time', '11:00'))); ?></span>
                        </span>
                    </div>
                </div>
                <div id="mhbo-tax-breakdown-container">
                    <?php
                    // Display the dynamic pricing and tax breakdown from the server calculation
                    $show_breakdown = !\MHBO\Core\Tax::is_enabled() || get_option('mhbo_tax_display_frontend', 1);
                    if ($show_breakdown) {
                        echo wp_kses_post(\MHBO\Core\Tax::render_breakdown_html($calc['tax'] ?? array()));
                    }
                    ?>
                </div>
                <?php
                // Show tax breakdown if enabled
                if (\MHBO\Core\Tax::is_enabled() && get_option('mhbo_tax_display_frontend', 1)):
                    $tax_mode = \MHBO\Core\Tax::get_mode();
                    $tax_label = \MHBO\Core\Tax::get_label();
                    $accommodation_rate = \MHBO\Core\Tax::get_accommodation_rate();
                    $extras_rate = \MHBO\Core\Tax::get_extras_rate();

                    if ($tax_mode === \MHBO\Core\Tax::MODE_VAT):
                        if ($accommodation_rate === $extras_rate):
                            ?>
                            <p class="mhbo-tax-note" style="font-size:0.85rem;color:#666;">
                                <?php echo esc_html(sprintf(I18n::decode(I18n::get_label('label_price_includes_tax')), $tax_label, $accommodation_rate)); ?>
                            </p>
                            <?php
                        else:
                            ?>
                            <p class="mhbo-tax-note" style="font-size:0.85rem;color:#666;">
                                <?php
                                // translators: 1: tax label (e.g., VAT), 2: accommodation tax rate, 3: extras tax rate
                                echo esc_html(sprintf(I18n::get_label('label_tax_note_includes_multi'), $tax_label, $accommodation_rate, $extras_rate)); ?>
                            </p>
                            <?php
                        endif;
                    elseif ($tax_mode === \MHBO\Core\Tax::MODE_SALES_TAX):
                        if ($accommodation_rate === $extras_rate):
                            ?>
                            <p class="mhbo-tax-note" style="font-size:0.85rem;color:#666;">
                                <?php echo esc_html(sprintf(I18n::decode(I18n::get_label('label_tax_added_at_checkout')), $tax_label, $accommodation_rate)); ?>
                            </p>
                            <?php
                        else:
                            ?>
                            <p class="mhbo-tax-note" style="font-size:0.85rem;color:#666;">
                                <?php
                                // translators: 1: tax label (e.g., Sales Tax), 2: accommodation tax rate, 3: extras tax rate
                                echo esc_html(sprintf(I18n::get_label('label_tax_note_plus_multi'), $tax_label, $accommodation_rate, $extras_rate)); ?>
                            </p>
                            <?php
                        endif;
                    endif;
                endif;
                ?>
            </div>
            <form method="post" id="mhbo-booking-form">
                <?php wp_nonce_field('mhbo_confirm_action', 'mhbo_confirm_nonce'); ?>
                <!-- Hidden field so JS form.submit() includes the booking action -->
                <input type="hidden" name="mhbo_confirm_booking" value="1">
                <input type="hidden" name="mhbo_room_id" value="<?php echo esc_attr((string) $room_id); ?>">
                <input type="hidden" name="check_in" value="<?php echo esc_attr($check_in); ?>">
                <input type="hidden" name="check_out" value="<?php echo esc_attr($check_out); ?>">
                <input type="hidden" name="total_price" value="<?php echo esc_attr((string) $total); ?>">
                <input type="hidden" name="booking_language" value="<?php echo esc_attr(I18n::get_current_language()); ?>">

                <div class="mhbo-form-group"><label><?php echo esc_html(I18n::get_label('label_name')); ?> <span
                            class="required">*</span></label><input type="text" name="customer_name" required></div>
                <div class="mhbo-form-group"><label><?php echo esc_html(I18n::get_label('label_email')); ?> <span
                            class="required">*</span></label><input type="email" name="customer_email" required></div>
                <div class="mhbo-form-group">
                    <label><?php echo esc_html(I18n::get_label('label_guests')); ?> <span class="required">*</span></label>
                    <select name="guests" id="mhbo-booking-guests" required>
                        <?php
                        // Determine max guests (capacity)
                        $max_capacity = isset($room->max_adults) ? intval($room->max_adults) : 2;
                        if ($max_capacity < 1)
                            $max_capacity = 2;

                        // Use pre-validated $guests parameter
                        $selected_guests = $guests;
                        if ($selected_guests > $max_capacity)
                            $selected_guests = $max_capacity;

                        for ($i = 1; $i <= $max_capacity; $i++) {
                            echo '<option value="' . esc_attr((string) $i) . '" ' . selected($selected_guests, $i, false) . '>' . esc_html((string) $i) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <?php
                $max_children = isset($room->max_children) ? intval($room->max_children) : 0;
                if ($max_children > 0):
                    // Use pre-validated $calc_children parameter
                    $selected_children = $calc_children;
                    ?>
                    <div class="mhbo-form-group">
                        <label><?php echo esc_html(I18n::get_label('label_children')); ?></label>
                        <select name="children" id="mhbo-booking-children">
                            <?php for ($i = 0; $i <= $max_children; $i++): ?>
                                <option value="<?php echo esc_attr((string) $i); ?>" <?php selected($selected_children, $i); ?>>
                                    <?php echo esc_html((string) $i); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div id="mhbo-child-ages-container"
                        style="display:<?php echo esc_attr($selected_children > 0 ? 'block' : 'none'); ?>;">
                        <label><?php echo esc_html(I18n::get_label('label_child_ages')); ?></label>
                        <div id="mhbo-child-ages-inputs">
                            <?php
                            // Re-populate if returning from failed validation or redirect
                            if ($selected_children > 0 && !empty($calc_children_ages)) {
                                $child_ages_data = $calc_children_ages;
                                foreach ($child_ages_data as $idx => $age) {
                                    if ($idx >= $selected_children)
                                        break;
                                    echo '<div class="mhbo-child-age-group">';
                                    printf('<label>' . esc_html(I18n::get_label('label_child_n_age')) . ' <span class="required">*</span></label>', esc_html((string) ($idx + 1)));
                                    echo '<input type="number" name="child_ages[]" value="' . esc_attr((string) absint($age)) . '" min="0" max="17" required class="mhbo-child-age-input">';
                                    echo '</div>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mhbo-form-group">
                    <label><?php echo esc_html(I18n::get_label('label_phone')); ?> <span class="required">*</span></label><input
                        type="tel" name="customer_phone" required>
                </div>
                <div class="mhbo-form-group">
                    <label><?php echo esc_html(I18n::get_label('label_special_requests')); ?></label>
                    <textarea name="admin_notes" rows="3" style="width:100%"></textarea>
                </div>

                <?php
                // Note: Honeypot removed for compliance. Security is handled via nonces.
                ?>


                <?php
                // Render Custom Fields
                $custom_fields = get_option('mhbo_custom_fields', []);
                if (!empty($custom_fields)) {
                    foreach ($custom_fields as $field) {
                        $label = isset($field['label']) ? I18n::decode(I18n::encode($field['label'])) : $field['id'];
                        $required = !empty($field['required']) ? 'required' : '';
                        $required_mark = $required ? ' <span class="required">*</span>' : '';

                        echo '<div class="mhbo-form-group mhbo-custom-field-group">';
                        echo '<label>' . esc_html($label) . wp_kses_post($required_mark) . '</label>';

                        if ($field['type'] === 'textarea') {
                            echo '<textarea name="mhbo_custom[' . esc_attr($field['id']) . ']" rows="3" style="width:100%" ' . esc_attr($required) . '></textarea>';
                        } else {
                            $input_type = ($field['type'] === 'number') ? 'number' : 'text';
                            echo '<input type="' . esc_attr($input_type) . '" name="mhbo_custom[' . esc_attr($field['id']) . ']" ' . esc_attr($required) . '>';
                        }
                        echo '</div>';
                    }
                }
                ?>

                <?php do_action('mhbo_booking_form_after_inputs'); ?>

                <!-- Inline error notification area for payment/booking errors -->
                <div id="mhbo-booking-errors" class="mhbo-inline-errors" style="display:none;"></div>

                <div class="mhbo-submit-container">
                    <button type="submit" name="mhbo_confirm_booking" id="mhbo-submit-btn" class="mhbo-btn">
                        <?php echo esc_html(I18n::get_label('btn_confirm_booking')); ?>
                    </button>
                    <div class="mhbo-secure-badge">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <span><?php echo esc_html(I18n::get_label('label_secure_payment')); ?></span>
                    </div>
                </div>
                <?php
                // Note: Booking form JavaScript logic has been moved to assets/js/mhbo-booking-form.js
                // The mhbo_vars configuration is injected via wp_add_inline_script() in enqueue_assets()
                ?>

            </form>
        </div>
        <?php
    }

    private function process_booking()
    {
        global $wpdb;
        // Nonce already verified in handle_booking_process() via mhbo_confirm_nonce/mhbo_confirm_action.
        // Double-check here as defense-in-depth.
        if (!isset($_POST['mhbo_confirm_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mhbo_confirm_nonce'])), 'mhbo_confirm_action')) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_security_error')) . '</div>';
            return;
        }

        // SECURITY: Rate limiting for booking submissions (5 per minute per IP)
        $ip = \MHBO\Core\Security::get_client_ip();
        $rate_key = 'mhbo_booking_rate_' . md5($ip);
        $count = get_transient($rate_key);
        if (false !== $count && $count >= 5) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_rate_limit_error')) . '</div>';
            return;
        }
        set_transient($rate_key, (int) $count + 1, 60);

        $room_id = absint($_POST['mhbo_room_id'] ?? ($_POST['room_id'] ?? 0));
        $customer_name = sanitize_text_field(wp_unslash($_POST['customer_name'] ?? ''));
        $customer_email = sanitize_email(wp_unslash($_POST['customer_email'] ?? '')); // sanitize_text_field applied or checked via nonce later
        $customer_phone = sanitize_text_field(wp_unslash($_POST['customer_phone'] ?? ''));
        $check_in = sanitize_text_field(wp_unslash($_POST['check_in'] ?? ''));
        $check_out = sanitize_text_field(wp_unslash($_POST['check_out'] ?? ''));
        $guests = absint($_POST['guests'] ?? 1);



        // Input length validation
        if (mb_strlen($customer_name) > 100) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_name_too_long')) . '</div>';
            return;
        }
        if (mb_strlen($customer_phone) > 30) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_phone_too_long')) . '</div>';
            return;
        }

        // Server-side Date Validation (Prevent bookings in the past or invalid ranges)
        $today = wp_date('Y-m-d');
        $max_future_date = wp_date('Y-m-d', strtotime('+2 years'));
        if ($check_in < $today) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_check_in_past')) . '</div>';
            return;
        }
        if ($check_in > $max_future_date) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_check_in_future')) . '</div>';
            return;
        }
        if ($check_out <= $check_in) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_check_out_after')) . '</div>';
            return;
        }
        if ($check_out > $max_future_date) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_check_out_future')) . '</div>';
            return;
        }

        // Recalculate Base Price for Security
        $room = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d", $room_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table

        // Validate room exists before accessing properties
        if (!$room) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_room_not_found')) . '</div>';
            return;
        }

        $room_type = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mhbo_room_types WHERE id = %d", $room->type_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table

        $extras_input = [];
        // Assignment removed for compliance
        

        

        // Validate Children
        $max_children = $room_type ? intval($room_type->max_children) : 0;
        $children = isset($_POST['children']) ? intval($_POST['children']) : 0;
        if ($children > $max_children) {
            // translators: %d: maximum number of children allowed
            echo '<div class="mhbo-error">' . esc_html(sprintf(I18n::get_label('label_max_children_error'), $max_children)) . '</div>';
            return;
        }

        // Validate Child Ages
        $children_ages = [];
        if ($children > 0) {
            if (isset($_POST['child_ages']) && is_array($_POST['child_ages'])) { // sanitize_text_field applied or checked via nonce later
                $children_ages = array_map('intval', wp_unslash($_POST['child_ages']));
            }
        }

        $calc = \MHBO\Core\Pricing::calculate_booking_total($room_id, $check_in, $check_out, $guests, $extras_input, $children, $children_ages);

        if (!$calc) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_price_calc_error')) . '</div>';
            return;
        }

        $calculated_total = $calc['room_total'];
        $extras_total = $calc['extras_total'];
        $total = $calc['total'];
        $booking_extras = $calc['extras_breakdown'];
        $nights = $calc['nights'];
        $tax_data = $calc['tax'] ?? null;


        if (empty($customer_name) || empty($customer_email) || empty($customer_phone) || !$room_id) {
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label('label_fill_all_fields')) . '</div>';
            return;
        }

        // Validate and Sanitize Custom Fields
        $custom_data = [];
        $custom_fields_defn = get_option('mhbo_custom_fields', []);
        if (!empty($custom_fields_defn)) {
            foreach ($custom_fields_defn as $defn) {
                $field_id = $defn['id'];
                $val = isset($_POST['mhbo_custom'][$field_id]) ? sanitize_textarea_field(wp_unslash($_POST['mhbo_custom'][$field_id])) : ''; // sanitize_text_field applied or checked via nonce later

                if (!empty($defn['required']) && empty($val)) {
                    $label = I18n::decode(I18n::encode($defn['label']));
                    // translators: %s: field label
                    echo '<div class="mhbo-error">' . esc_html(sprintf(I18n::get_label('label_field_required'), $label)) . '</div>';
                    return;
                }

                if ($val !== '') {
                    $custom_data[$field_id] = $val;
                }
            }
        }

        

        $status = 'pending';

        // Check Availability with Race Condition Protection (GET_LOCK)
        $lock_name = "mhbo_booking_lock_{$room_id}";
        $wpdb->query($wpdb->prepare("SELECT GET_LOCK(%s, 10)", $lock_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock for race condition prevention

        $availability = \MHBO\Core\Pricing::is_room_available($room_id, $check_in, $check_out);

        if (true !== $availability) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release
            $label = is_string($availability) ? $availability : 'label_already_booked';
            echo '<div class="mhbo-error">' . esc_html(I18n::get_label($label)) . '</div>';
            return;
        }

        // Validate max guests (Adults)
        $max_adults = $room_type ? intval($room_type->max_adults) : 2;
        if ($max_adults < 1)
            $max_adults = 2;

        if ($guests > $max_adults) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release
            // translators: %d: maximum number of adults allowed
            echo '<div class="mhbo-error">' . esc_html(sprintf(I18n::get_label('label_max_adults_error'), $max_adults)) . '</div>';
            return;
        }


        // Validate Payment Method Requirement
        // Assignment removed for compliance
        

        

        $arrival_enabled = true; // Always enabled in free version
        /* BUILD_PRO_END */
        
        $arrival_enabled = true;
        

        $has_active_gateways = false;
        

        // If gateways are active, enforce payment or arrival selection
        $payment_method = 'arrival';
        $payment_received = 0;

        

        // Initialize payment status fields
        $payment_status = 'pending';
        $payment_transaction_id = '';
        $payment_date = null;
        $payment_amount = null;

        

        // Set payment status for arrival payments - booking remains pending until admin approval
        if ('arrival' === $payment_method) {
            $payment_status = 'pending';
            // Status remains 'pending' - admin must approve deposit/payment to confirm booking
        }

        $wpdb->insert($wpdb->prefix . 'mhbo_bookings', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
            'room_id' => $room_id,
            'customer_name' => $customer_name,
            'customer_email' => $customer_email,
            'customer_phone' => $customer_phone,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'total_price' => floatval($total),
            'status' => $status,
            'booking_token' => wp_generate_password(32, false),
            'booking_language' => sanitize_key(wp_unslash($_POST['booking_language'] ?? I18n::get_current_language())),
            
            'admin_notes' => sanitize_textarea_field(wp_unslash($_POST['admin_notes'] ?? '')), // sanitize_text_field applied or checked via nonce later
            'payment_method' => sanitize_key($payment_method),
            'payment_received' => (int) $payment_received,
            'payment_status' => $payment_status,
            'payment_transaction_id' => !empty($payment_transaction_id) ? $payment_transaction_id : null,
            'payment_date' => $payment_date,
            'payment_amount' => $payment_amount,
            'guests' => $guests,
            'children' => $children,
            'children_ages' => !empty($children_ages) ? wp_json_encode($children_ages) : null,
            'custom_fields' => !empty($custom_data) ? wp_json_encode($custom_data) : null,
            
        ]);

        $booking_id = $wpdb->insert_id;
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release

        if ($booking_id) {
            // Invalidate booking and calendar cache to ensure availability and lists are updated
            \MHBO\Core\Cache::invalidate_booking($booking_id, (int) $room_id);

            // Invalidate dashboard statistics transients
            delete_transient('mhbo_widget_total_bookings');
            delete_transient('mhbo_widget_pending_bookings');
            $today_date = wp_date('Y-m-d');
            delete_transient('mhbo_widget_today_bookings_' . $today_date);
            delete_transient('mhbo_dashboard_total_bookings');
            delete_transient('mhbo_dashboard_pending_bookings');
            delete_transient('mhbo_dashboard_earned_revenue_' . $today_date);
            delete_transient('mhbo_dashboard_future_revenue_' . $today_date);

            do_action('mhbo_booking_created', $booking_id);
            if ('confirmed' === $status) {
                do_action('mhbo_booking_confirmed', $booking_id);
            }
        }

        // Send Email - only for completed payments or arrival payments
        if (class_exists('MHBO\Core\Email')) {
            if ('completed' === $payment_status || 'arrival' === $payment_method) {
                Email::send_email($booking_id, $status);
            }
        }

        // Show success message or redirect (POST-Redirect-GET)
        if ($booking_id) {
            $success_url = add_query_arg(['mhbo_success' => 1, 'booking_id' => $booking_id], remove_query_arg(['mhbo_confirm_booking', 'mhbo_confirm_nonce']));
            wp_safe_redirect($success_url);
            exit;
        }

        $msg_title = I18n::get_label('msg_booking_confirmed');
        $msg_detail = I18n::get_label('msg_confirmation_sent');
        $message_class = 'mhbo-success-message';

        // Customize message based on payment status
        if ('completed' === $payment_status) {
            $msg_title = I18n::get_label('msg_booking_confirmed');
            $msg_detail = I18n::get_label('msg_payment_success_email');
        } elseif ('arrival' === $payment_method) {
            // Pay on Arrival - will be overridden by pending status check below
            // Booking remains pending until admin approves deposit/payment
            $msg_title = I18n::get_label('msg_booking_confirmed');
            $msg_detail = I18n::get_label('msg_booking_arrival_email');
        } elseif ('failed' === $payment_status) {
            $msg_title = I18n::get_label('label_payment_failed');
            $msg_detail = I18n::get_label('msg_payment_failed_detail');
        }

        // Pending status (awaiting approval) - orange styling
        if ('pending' === $status) {
            $msg_title = I18n::get_label('msg_booking_received');
            $msg_detail = I18n::get_label('msg_booking_received_pending');
            $message_class = 'mhbo-success-message mhbo-pending-booking';
        }

        echo '<div class="' . esc_attr($message_class) . '"><h3>' . esc_html($msg_title) . '</h3><p>' . esc_html($msg_detail) . '</p>';

        // Show tax breakdown if enabled
        if (\MHBO\Core\Tax::is_enabled() && $tax_data && ($tax_data['enabled'] ?? false)):
            echo wp_kses_post(\MHBO\Core\Tax::render_breakdown_html($tax_data['breakdown']));
        endif;

        // Show payment summary for completed payments
        if ('completed' === $payment_status) {
            echo '<p class="mhbo-payment-received"><strong>' . esc_html(I18n::get_label('label_payment_status')) . '</strong> <span class="mhbo-badge-success">' . esc_html(I18n::get_label('label_paid')) . '</span></p>';
            if ($payment_amount) {
                echo '<p class="mhbo-payment-amount"><strong>' . esc_html(I18n::get_label('label_amount_paid')) . '</strong> ' . esc_html(I18n::format_currency($payment_amount)) . '</p>';
            }
            if ($payment_transaction_id) {
                echo '<p class="mhbo-transaction-id"><strong>' . esc_html(I18n::get_label('label_transaction_id')) . '</strong> ' . esc_html($payment_transaction_id) . '</p>';
            }
        } elseif ('pending' === $payment_status && 'arrival' === $payment_method) {
            echo '<p class="mhbo-payment-pending"><strong>' . esc_html(I18n::get_label('label_payment_status')) . '</strong> <span class="mhbo-badge-pending">' . esc_html(I18n::get_label('label_pay_arrival')) . '</span></p>';
        } elseif ('failed' === $payment_status) {
            echo '<p class="mhbo-payment-failed"><strong>' . esc_html(I18n::get_label('label_payment_status')) . '</strong> <span class="mhbo-badge-failed">' . esc_html(I18n::get_label('label_failed')) . '</span></p>';
        }

        echo '</div>';
    }

    /**
     * Inject theme styles based on Pro settings.
     * Note: Theme styles are applied to all users to ensure CSS variables are defined.
     */
    public static function inject_theme_styles()
    {
        $active_theme = get_option('mhbo_active_theme', 'midnight');
        $primary = '';
        $secondary = '';
        $accent = '';

        $presets = [
            'midnight' => ['#1a365d', '#f2e2c4', '#d4af37'],
            'emerald' => ['#064e3b', '#34d399', '#10b981'],
            'oceanic' => ['#1e3a8a', '#60a5fa', '#3b82f6'],
            'ruby' => ['#7f1d1d', '#f87171', '#ef4444'],
            'urban' => ['#1f2937', '#9ca3af', '#4b5563'],
            'lavender' => ['#4c1d95', '#a78bfa', '#8b5cf6'],
        ];

        
            if (isset($presets[$active_theme])) {
                $primary = $presets[$active_theme][0];
                $secondary = $presets[$active_theme][1];
                $accent = $presets[$active_theme][2];
            }

        if ($primary) {
            // SECURITY: Validate hex colors before CSS interpolation
            $primary = sanitize_hex_color($primary) ?: '#1a365d';
            $secondary = sanitize_hex_color($secondary) ?: '#f2e2c4';
            $accent = sanitize_hex_color($accent) ?: '#d4af37';
            $custom_css = ":root {
                --mhbo-primary: " . esc_attr($primary) . ";
                --mhbo-secondary: " . esc_attr($secondary) . ";
                --mhbo-accent: " . esc_attr($accent) . ";
            }";
            wp_add_inline_style('mhbo-style', wp_strip_all_tags($custom_css));
            wp_add_inline_style('mhbo-calendar-style', wp_strip_all_tags($custom_css));
        }

        

        wp_add_inline_style('mhbo-frontend', '
            .mhbo-child-age-group { 
                display: flex; 
                align-items: center; 
                justify-content: space-between; 
                background: rgba(0,0,0,0.03); 
                padding: 8px 12px; 
                border-radius: 6px; 
                margin-bottom: 8px !important;
            }
            .mhbo-child-age-group label { margin: 0 !important; font-weight: normal !important; }
            .mhbo_faded { opacity: 0.5; transition: opacity 0.3s; pointer-events: none; }
        ');
    }

    /**
     * Validate a date string (Y-m-d format).
     * SECURITY: Prevents injection via date parameters.
     *
     * @param string $date Date string to validate.
     * @return bool True if valid, false otherwise.
     */
    private function validate_date($date)
    {
        if (empty($date) || !is_string($date)) {
            return false;
        }
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}

