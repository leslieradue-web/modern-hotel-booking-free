<?php declare(strict_types=1);

namespace MHB\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

use MHB\Core\Email;
use MHB\Core\I18n;

class Shortcode
{

    public function init()
    {
        add_shortcode('modern_hotel_booking', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
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
        if (!wp_style_is('mhb-style', 'enqueued')) {
            wp_enqueue_style('mhb-style', MHB_PLUGIN_URL . 'assets/css/mhb-style.css', [], MHB_VERSION);
        }

        // Enqueue flatpickr first since frontend script depends on it
        if (!wp_style_is('mhb-flatpickr-css', 'enqueued')) {
            wp_enqueue_style(
                'mhb-flatpickr-css',
                MHB_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
                [],
                '4.6.13'
            );
        }
        if (!wp_script_is('mhb-flatpickr-js', 'enqueued')) {
            wp_enqueue_script(
                'mhb-flatpickr-js',
                MHB_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
                [],
                '4.6.13',
                true
            );
        }

        // Enqueue frontend script (depends on both jQuery and flatpickr)
        if (!wp_script_is('mhb-frontend', 'enqueued')) {
            wp_enqueue_script('mhb-frontend', MHB_PLUGIN_URL . 'assets/js/mhb-frontend.js', ['jquery', 'mhb-flatpickr-js'], MHB_VERSION, true);
        }

        // Enqueue calendar assets via centralized handler
        if (class_exists('MHB\Frontend\Calendar')) {
            Calendar::enqueue_assets();
        }

        // Inject theme styles (must be after enqueuing styles)
        self::inject_theme_styles();

        // Enqueue booking form script
        if (!wp_script_is('mhb-booking-form', 'enqueued')) {
            wp_enqueue_script('mhb-booking-form', MHB_PLUGIN_URL . 'assets/js/mhb-booking-form.js', ['jquery', 'mhb-frontend'], MHB_VERSION, true);
        }

        // Add localization data (only once)
        if (!wp_script_is('mhb-frontend', 'done')) {
            $localized_data = array(
                'pay_confirm' => I18n::get_label('btn_pay_confirm'),
                'confirm' => I18n::get_label('btn_confirm_booking'),
                'processing' => I18n::get_label('btn_processing'),
                'loading' => I18n::get_label('label_loading'),
                'to' => I18n::get_label('label_to'),
                'ajax_url' => admin_url('admin-ajax.php'),
                'rest_url' => get_rest_url(null, 'mhb/v1'),
                'nonce' => wp_create_nonce('wp_rest'),
                'label_child_n_age' => I18n::get_label('label_child_n_age'),
                'currency_symbol' => get_option('mhb_currency_symbol', '$'),
                'currency_pos' => get_option('mhb_currency_position', 'before'),
                'msg_gdpr_required' => I18n::get_label('msg_gdpr_required'),
                'msg_paypal_required' => I18n::get_label('msg_paypal_required'),
                'tax_enabled' => \MHB\Core\Tax::is_enabled(),
                'tax_mode' => \MHB\Core\Tax::get_mode(),
                'tax_label' => \MHB\Core\Tax::get_label(),
                'tax_rate_accommodation' => \MHB\Core\Tax::get_accommodation_rate(),
                'tax_rate_extras' => \MHB\Core\Tax::get_extras_rate(),
                'checkin_time' => get_option('mhb_checkin_time', '14:00'),
                'checkout_time' => get_option('mhb_checkout_time', '11:00'),
            );

            $localized_data = apply_filters('mhb_frontend_localized_data', $localized_data);
            wp_add_inline_script('mhb-frontend', 'var mhb_vars = ' . wp_json_encode($localized_data) . ';');
        }
    }

    public function enqueue_assets()
    {
        /** @var \WP_Post $post */
        global $post;
        $has_shortcode = is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'modern_hotel_booking');
        $has_block = is_a($post, 'WP_Post') && has_block('modern-hotel-booking/booking-form', $post->post_content);
        $is_booking_page = (get_option('mhb_booking_page') == ($post->ID ?? 0));

        // If not on booking page, no shortcode, and no block, don't enqueue
        if (!$has_shortcode && !$has_block && !$is_booking_page) {
            return;
        }

        wp_enqueue_style('mhb-style', MHB_PLUGIN_URL . 'assets/css/mhb-style.css', [], MHB_VERSION);

        // Enqueue flatpickr first since frontend script depends on it
        wp_enqueue_style(
            'mhb-flatpickr-css',
            MHB_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
            [],
            '4.6.13'
        );
        wp_enqueue_script(
            'mhb-flatpickr-js',
            MHB_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
            [],
            '4.6.13',
            true
        );

        // Enqueue frontend script (depends on both jQuery and flatpickr)
        wp_enqueue_script('mhb-frontend', MHB_PLUGIN_URL . 'assets/js/mhb-frontend.js', ['jquery', 'mhb-flatpickr-js'], MHB_VERSION, true);

        // Enqueue calendar assets for the new search form
        wp_enqueue_style('mhb-calendar-style', MHB_PLUGIN_URL . 'assets/css/mhb-calendar.css', [], MHB_VERSION);
        wp_enqueue_script('mhb-calendar-js', MHB_PLUGIN_URL . 'assets/js/mhb-calendar.js', ['jquery', 'mhb-flatpickr-js'], MHB_VERSION, true);

        // Inject theme styles (must be after enqueuing styles)
        self::inject_theme_styles();

        // Booking form interactions
        wp_enqueue_script('mhb-booking-form', MHB_PLUGIN_URL . 'assets/js/mhb-booking-form.js', ['jquery', 'mhb-frontend'], MHB_VERSION, true);

        // Localize script for JS strings
        $localized_data = array(
            'pay_confirm' => I18n::get_label('btn_pay_confirm'),
            'confirm' => I18n::get_label('btn_confirm_booking'),
            'processing' => I18n::get_label('btn_processing'),
            'loading' => I18n::get_label('label_loading'),
            'to' => I18n::get_label('label_to'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => get_rest_url(null, 'mhb/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'label_child_n_age' => I18n::get_label('label_child_n_age'),
            'currency_symbol' => get_option('mhb_currency_symbol', '$'),
            'currency_pos' => get_option('mhb_currency_position', 'before'),
            'msg_gdpr_required' => I18n::get_label('msg_gdpr_required'),
            'msg_paypal_required' => I18n::get_label('msg_paypal_required'),
            // Tax settings for frontend
            'tax_enabled' => \MHB\Core\Tax::is_enabled(),
            'tax_mode' => \MHB\Core\Tax::get_mode(),
            'tax_label' => \MHB\Core\Tax::get_label(),
            'tax_rate_accommodation' => \MHB\Core\Tax::get_accommodation_rate(),
            'tax_rate_extras' => \MHB\Core\Tax::get_extras_rate(),
            'checkin_time' => get_option('mhb_checkin_time', '14:00'),
            'checkout_time' => get_option('mhb_checkout_time', '11:00'),
        );

        $localized_data = apply_filters('mhb_frontend_localized_data', $localized_data);

        // SECURITY: Use wp_json_encode for proper escaping and WordPress consistency
        wp_add_inline_script('mhb-frontend', 'var mhb_vars = ' . wp_json_encode($localized_data) . ';');
    }

    private static $instance_rendered = false;

    public function render_shortcode($atts)
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
        echo '<div class="mhb-wrapper">';
        $this->handle_booking_process($atts);
        echo '</div>';
        return ob_get_clean();
    }

    private function handle_booking_process($atts = [])
    {
        $room_id_attr = isset($atts['room_id']) ? intval($atts['room_id']) : 0;

        // Support for pre-filled parameters via GET or POST
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter used to pre-select a room, no state change
        $request_room_id = isset($_REQUEST['room_id']) ? intval($_REQUEST['room_id']) : 0;
        $active_room_id = $room_id_attr ? $room_id_attr : $request_room_id;

        // Handle Automatic Search (from Widget submission via GET)
        // SECURITY: Auto-search is a "read" action (shows search results), not a "write" action.
        // We render search results directly without nonce verification since no data is modified.
        // Actual booking submission still requires proper nonce verification.
        if (isset($_GET['mhb_auto_search']) && !isset($_POST['mhb_search']) && !isset($_POST['mhb_book_room']) && !isset($_POST['mhb_confirm_booking']) && !isset($_REQUEST['mhb_auto_book']) && isset($_GET['check_in']) && isset($_GET['check_out'])) {
            // SECURITY: Validate dates to prevent injection
            $check_in = sanitize_text_field(wp_unslash($_GET['check_in']));
            $check_out = sanitize_text_field(wp_unslash($_GET['check_out']));

            // Validate date format (Y-m-d)
            if (!$this->validate_date($check_in) || !$this->validate_date($check_out)) {
                $this->render_search_form($active_room_id);
                return;
            }

            // Store sanitized values for search form
            $_POST['check_in'] = $check_in;
            $_POST['check_out'] = $check_out;
            $_POST['guests'] = isset($_GET['guests']) ? intval($_GET['guests']) : 2;

            // SECURITY: Do NOT set mhb_search or create nonce here
            // Instead, render search results directly as a read-only action
            $this->render_search_results($active_room_id);
            return;
        }

        if (isset($_POST['mhb_search'])) {
            if (!isset($_POST['mhb_search_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mhb_search_nonce'])), 'mhb_search_action')) {
                wp_die(esc_html(I18n::get_label('label_security_error')));
            }
            // Sanitize inputs for search results
            $this->render_search_results($active_room_id);
            return;
        }

        // If it's an automated redirect from the room calendar
        if (isset($_REQUEST['mhb_auto_book'])) {
            // If room_id is 0 (aggregated calendar), treat as a search
            if ($active_room_id === 0) {
                $_POST['check_in'] = isset($_REQUEST['check_in']) ? sanitize_text_field(wp_unslash($_REQUEST['check_in'])) : '';
                $_POST['check_out'] = isset($_REQUEST['check_out']) ? sanitize_text_field(wp_unslash($_REQUEST['check_out'])) : '';
                $_POST['guests'] = 1; // Default to 1 to show all available rooms
                $_POST['mhb_search'] = '1';
                $_POST['mhb_search_nonce'] = wp_create_nonce('mhb_search_action');

                $this->render_search_results(0);
                return;
            }

            // Nonce is not required for auto-book GET redirect as it's a "read" action to show form
            $_POST['room_id'] = $active_room_id;
            $_POST['check_in'] = isset($_REQUEST['check_in']) ? sanitize_text_field(wp_unslash($_REQUEST['check_in'])) : '';
            $_POST['check_out'] = isset($_REQUEST['check_out']) ? sanitize_text_field(wp_unslash($_REQUEST['check_out'])) : '';
            $_POST['guests'] = isset($_REQUEST['guests']) ? intval($_REQUEST['guests']) : 2;
            $_POST['total_price'] = isset($_REQUEST['total_price']) ? floatval($_REQUEST['total_price']) : 0;
            $_POST['mhb_book_room'] = '1';
        }

        if (isset($_POST['mhb_confirm_booking'])) {
            if (!isset($_POST['mhb_confirm_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mhb_confirm_nonce'])), 'mhb_confirm_action')) {
                wp_die(esc_html(I18n::get_label('label_security_error')));
            }
            $this->process_booking();
            return;
        }

        if (isset($_POST['mhb_book_room'])) {
            // Verify nonce if it's coming from the search results page (not for auto-book redirects)
            if (!isset($_REQUEST['mhb_auto_book']) && isset($_POST['mhb_book_now_nonce']) && !wp_verify_nonce(sanitize_key(wp_unslash($_POST['mhb_book_now_nonce'])), 'mhb_book_now_action')) {
                wp_die(esc_html(I18n::get_label('label_security_error')));
            }
            $this->render_booking_form();
            return;
        }
        $this->render_search_form($active_room_id);
    }

    private function render_search_form($room_id = 0)
    {
        // Unified Calendar View replacing the old search form
        // Delegates to the centralized Calendar renderer
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Calendar::render_unified_view returns pre-escaped HTML from internal components
        echo Calendar::render_unified_view($room_id);
    }

    private function render_search_results($room_id_filter = 0)
    {
        global $wpdb;
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function
        $check_in = isset($_POST['check_in']) ? sanitize_text_field(wp_unslash($_POST['check_in'])) : '';
        $check_out = isset($_POST['check_out']) ? sanitize_text_field(wp_unslash($_POST['check_out'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function
        $guests = isset($_POST['guests']) ? absint($_POST['guests']) : 1; // Default to 1 to show all available rooms

        // Date Validation
        $today = wp_date('Y-m-d');
        if ($check_in < $today) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_check_in_past')) . '</div>';
            $this->render_search_form();
            return;
        }
        if ($check_out <= $check_in) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_check_out_after')) . '</div>';
            $this->render_search_form();
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function
        $room_id_filter = isset($_POST['room_id_filter']) ? intval($_POST['room_id_filter']) : $room_id_filter;

        $sql = "SELECT r.*, t.name as type_name, t.description, t.base_price, t.max_adults, t.amenities, t.image_url 
                FROM {$wpdb->prefix}mhb_rooms r 
                JOIN {$wpdb->prefix}mhb_room_types t ON r.type_id = t.id 
                WHERE r.status = 'available' 
                AND t.max_adults >= %d";

        if ($room_id_filter) {
            $sql .= $wpdb->prepare(" AND r.id = %d", $room_id_filter);
        }

        $expiry_time = wp_date('Y-m-d H:i:s', strtotime('-60 minutes'));

        // Industry-standard overlap: strict inequality so checkout day is available for new check-ins
        // Formula: existing.check_in < new.check_out AND existing.check_out > new.check_in
        $sql .= " AND r.id NOT IN ( 
                    SELECT room_id FROM {$wpdb->prefix}mhb_bookings 
                    WHERE (check_in < %s AND check_out > %s) 
                    AND status != 'cancelled' 
                    AND NOT (status = 'pending' AND created_at < %s)
                ) GROUP BY r.type_id";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, $sql prepared via $wpdb->prepare() below
        $available_rooms = $wpdb->get_results($wpdb->prepare($sql, $guests, $check_out, $check_in, $expiry_time));

        echo '<h3>' . sprintf(wp_kses_post(I18n::get_label('label_available_rooms')), esc_html($check_in), esc_html($check_out)) . '</h3>';
        if (empty($available_rooms)) {
            echo '<p>' . esc_html(I18n::get_label('label_no_rooms')) . '</p>';
            $this->render_search_form();
            return;
        }

        echo '<div class="mhb-rooms-grid">';
        foreach ($available_rooms as $room) {
            $start_date = new \DateTime($check_in);
            $end_date = new \DateTime($check_out);
            $interval = new \DateInterval('P1D');
            $period = new \DatePeriod($start_date, $interval, $end_date);

            $total = 0;
            foreach ($period as $dt) {
                $date_str = $dt->format('Y-m-d');
                $total += \MHB\Core\Pricing::calculate_daily_price($room->id, $date_str);
            }

            $days = iterator_count($period);
            // $price is just for display "per night", maybe average?
            $avg_price = $days > 0 ? $total / $days : ($room->custom_price ?: $room->base_price);

            $amenities = $room->amenities ? json_decode($room->amenities) : [];
            $img = $room->image_url ? esc_url($room->image_url) : esc_url(MHB_PLUGIN_URL . 'assets/default-room.jpg');

            echo '<div class="mhb-room-card">';
            echo '<div class="mhb-room-image" style="height:200px; background:url(' . esc_url($img) . ') center/cover;"></div>';
            echo '<div class="mhb-room-content">';
            echo '<h4 class="mhb-room-title">' . esc_html(I18n::decode($room->type_name)) . '</h4>';

            $desc = I18n::decode($room->description);
            if (!empty($desc)) {
                echo '<p class="mhb-room-description" style="font-size:0.9rem; color:#666; margin-bottom:15px;">' . esc_html(wp_trim_words($desc, 20)) . '</p>';
            }

            echo '<div class="mhb-room-price">' . esc_html(I18n::format_currency($avg_price)) . ' <span>' . esc_html(I18n::get_label('label_per_night')) . '</span></div>';

            if (!empty($amenities)) {
                echo '<div class="mhb-amenities" style="margin-bottom:10px; font-size:0.85rem; color:#666;">';
                foreach ($amenities as $am) {
                    echo '<span style="display:inline-block; background:#eee; padding:2px 8px; border-radius:12px; margin-right:5px; margin-bottom:5px;">' . esc_html(ucfirst(I18n::decode($am))) . '</span>';
                }
                echo '</div>';
            }

            echo '<div class="mhb-room-details">';
            echo wp_kses_post(sprintf(I18n::get_label('label_total_nights'), $days, '<strong>' . esc_html(I18n::format_currency($total)) . '</strong>'));
            echo '<p>' . sprintf(esc_html(I18n::get_label('label_max_guests')), esc_html($room->max_adults)) . '</p>';
            echo '</div>';

            echo '<form method="post">';
            wp_nonce_field('mhb_book_now_action', 'mhb_book_now_nonce');
            echo '<input type="hidden" name="check_in" value="' . esc_attr($check_in) . '"><input type="hidden" name="check_out" value="' . esc_attr($check_out) . '"><input type="hidden" name="room_id" value="' . esc_attr((string) ($room_id_filter ?: $room->id)) . '"><input type="hidden" name="total_price" value="' . esc_attr((string) $total) . '">';
            echo '<button type="submit" name="mhb_book_room" class="mhb-btn">' . esc_html(I18n::get_label('btn_book_now')) . '</button>';
            echo '</form></div></div>';
        }
        echo '</div>';
    }

    private function render_booking_form()
    {
        global $wpdb;
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function
        $room_id = isset($_POST['room_id']) ? intval($_POST['room_id']) : 0;
        $check_in = isset($_POST['check_in']) ? sanitize_text_field(wp_unslash($_POST['check_in'])) : '';
        $check_out = isset($_POST['check_out']) ? sanitize_text_field(wp_unslash($_POST['check_out'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Check availability before rendering booking form.
        $available = \MHB\Core\Pricing::is_room_available((int) $room_id, $check_in, $check_out);

        if (true !== $available) {
            echo '<div class="mhb-error mhb-availability-error">' .
                esc_html(I18n::get_label($available)) .
                '</div>';
            $this->render_search_form();
            return;
        }

        $room = $wpdb->get_row($wpdb->prepare("SELECT t.image_url, t.name as type_name, t.base_price, t.max_adults, t.max_children, t.child_rate, t.child_age_free_limit, r.room_number, r.custom_price FROM {$wpdb->prefix}mhb_rooms r JOIN {$wpdb->prefix}mhb_room_types t ON r.type_id = t.id WHERE r.id = %d", $room_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table

        // Validate room exists before rendering form
        if (!$room) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_room_not_found')) . '</div>';
            $this->render_search_form();
            return;
        }

        $image_url = ($room && $room->image_url) ? esc_url($room->image_url) : '';
        $room_name = $room ? I18n::decode($room->type_name) . ' (' . $room->room_number . ')' : I18n::get_label('label_room');
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function
        $total = isset($_POST['total_price']) ? floatval($_POST['total_price']) : 0;
        $guests = isset($_POST['guests']) ? intval($_POST['guests']) : 2; // Default to 2 if missing
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Always recalculate on render to ensure we have the full $calc breakdown for display
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function
        $calc_guests = isset($_POST['guests']) ? intval($_POST['guests']) : 2;
        $calc_children = isset($_POST['children']) ? intval($_POST['children']) : 0;
        $calc_children_ages = isset($_POST['child_ages']) ? array_map('absint', wp_unslash($_POST['child_ages'])) : array();
        $calc_extras = isset($_POST['mhb_extras']) ? array_map('sanitize_text_field', wp_unslash($_POST['mhb_extras'])) : array();
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $calc = \MHB\Core\Pricing::calculate_booking_total($room_id, $check_in, $check_out, $calc_guests, $calc_extras, $calc_children, $calc_children_ages);
        $total = $calc ? (float) $calc['total'] : (!empty($total) ? $total : 0);

        // Check License Status
        $is_pro_active = false;

        // Get Stripe settings - use correct option names matching PaymentGateways.php
        // Note: Options are saved as 1/0 (integers), not 'yes'/'no'
        $stripe_enabled = $is_pro_active && get_option('mhb_gateway_stripe_enabled', 0);
        $stripe_mode = get_option('mhb_stripe_mode', 'test');
        $stripe_key = 'live' === $stripe_mode
            ? get_option('mhb_stripe_live_publishable_key', '')
            : get_option('mhb_stripe_test_publishable_key', '');

        // Get PayPal settings - use correct option names matching PaymentGateways.php
        // Note: Options are saved as 1/0 (integers), not 'yes'/'no'
        $paypal_enabled = $is_pro_active && get_option('mhb_gateway_paypal_enabled', 0);
        $paypal_mode = get_option('mhb_paypal_mode', 'sandbox');
        $paypal_client = 'live' === $paypal_mode
            ? get_option('mhb_paypal_live_client_id', '')
            : get_option('mhb_paypal_sandbox_client_id', '');

        // Get On-site settings - use correct option name matching PaymentGateways.php
        // Note: Options are saved as 1/0 (integers), not 'yes'/'no'
        $arrival_enabled = $is_pro_active && get_option('mhb_gateway_onsite_enabled', 0);
        ?>
        <div class="mhb-booking-wrapper">
            <h2><?php echo esc_html(I18n::get_label('label_complete_booking')); ?></h2>
            <div class="mhb-booking-summary">
                <?php if ($image_url): ?>
                    <img src="<?php echo esc_url($image_url); ?>"
                        alt="<?php echo esc_attr(I18n::get_label('label_room_alt_text')); ?>"
                        style="width:100%; height:200px; object-fit:cover; border-radius:8px; margin-bottom:15px;">
                <?php endif; ?>
                <h3><?php echo esc_html($room_name); ?></h3>
                <p><?php echo esc_html(I18n::get_label('label_total')); ?>
                    <strong id="mhb-display-total" data-base-total="<?php echo esc_attr((string) $total); ?>"
                        data-currency-symbol="<?php echo esc_attr(get_option('mhb_currency_symbol', '$')); ?>"
                        data-currency-pos="<?php echo esc_attr(get_option('mhb_currency_position', 'before')); ?>"><?php echo esc_html(I18n::format_currency($total)); ?></strong>
                </p>
                <div class="mhb-booking-times">
                    <div class="mhb-booking-time-row">
                        <span class="mhb-booking-time-label"><?php echo esc_html(I18n::get_label('label_check_in')); ?></span>
                        <span class="mhb-booking-time-value"><?php echo esc_html($check_in); ?>
                            <span
                                class="mhb-time-info"><?php echo esc_html(sprintf(I18n::get_label('label_check_in_from'), get_option('mhb_checkin_time', '14:00'))); ?></span>
                        </span>
                    </div>
                    <div class="mhb-booking-time-row">
                        <span class="mhb-booking-time-label"><?php echo esc_html(I18n::get_label('label_check_out')); ?></span>
                        <span class="mhb-booking-time-value"><?php echo esc_html($check_out); ?>
                            <span
                                class="mhb-time-info"><?php echo esc_html(sprintf(I18n::get_label('label_check_out_by'), get_option('mhb_checkout_time', '11:00'))); ?></span>
                        </span>
                    </div>
                </div>
                <div id="mhb-tax-breakdown-container">
                    <?php
                    // Display the dynamic pricing and tax breakdown from the server calculation
                    $show_breakdown = !\MHB\Core\Tax::is_enabled() || get_option('mhb_tax_display_frontend', 1);
                    if ($show_breakdown) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method returns sanitized HTML
                        echo \MHB\Core\Tax::render_breakdown_html($calc['tax'] ?? array());
                    }
                    ?>
                </div>
                <?php
                // Show tax breakdown if enabled
                if (\MHB\Core\Tax::is_enabled() && get_option('mhb_tax_display_frontend', 1)):
                    $tax_mode = \MHB\Core\Tax::get_mode();
                    $tax_label = \MHB\Core\Tax::get_label();
                    $accommodation_rate = \MHB\Core\Tax::get_accommodation_rate();
                    $extras_rate = \MHB\Core\Tax::get_extras_rate();

                    if ($tax_mode === \MHB\Core\Tax::MODE_VAT):
                        if ($accommodation_rate === $extras_rate):
                            ?>
                            <p class="mhb-tax-note" style="font-size:0.85rem;color:#666;">
                                <?php echo esc_html(sprintf(I18n::decode(I18n::get_label('label_price_includes_tax')), $tax_label, $accommodation_rate)); ?>
                            </p>
                            <?php
                        else:
                            ?>
                            <p class="mhb-tax-note" style="font-size:0.85rem;color:#666;">
                                <?php
                                // translators: 1: tax label (e.g., VAT), 2: accommodation tax rate, 3: extras tax rate
                                echo esc_html(sprintf(I18n::get_label('label_tax_note_includes_multi'), $tax_label, $accommodation_rate, $extras_rate)); ?>
                            </p>
                            <?php
                        endif;
                    elseif ($tax_mode === \MHB\Core\Tax::MODE_SALES_TAX):
                        if ($accommodation_rate === $extras_rate):
                            ?>
                            <p class="mhb-tax-note" style="font-size:0.85rem;color:#666;">
                                <?php echo esc_html(sprintf(I18n::decode(I18n::get_label('label_tax_added_at_checkout')), $tax_label, $accommodation_rate)); ?>
                            </p>
                            <?php
                        else:
                            ?>
                            <p class="mhb-tax-note" style="font-size:0.85rem;color:#666;">
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
            <form method="post" id="mhb-booking-form">
                <?php wp_nonce_field('mhb_process_booking'); ?>
                <?php wp_nonce_field('mhb_confirm_action', 'mhb_confirm_nonce'); ?>
                <!-- Hidden field so JS form.submit() includes the booking action -->
                <input type="hidden" name="mhb_confirm_booking" value="1">
                <input type="hidden" name="room_id" value="<?php echo esc_attr((string) $room_id); ?>">
                <input type="hidden" name="check_in" value="<?php echo esc_attr($check_in); ?>">
                <input type="hidden" name="check_out" value="<?php echo esc_attr($check_out); ?>">
                <input type="hidden" name="total_price" value="<?php echo esc_attr((string) $total); ?>">
                <input type="hidden" name="booking_language" value="<?php echo esc_attr(I18n::get_current_language()); ?>">

                <div class="mhb-form-group"><label><?php echo esc_html(I18n::get_label('label_name')); ?> <span
                            class="required">*</span></label><input type="text" name="customer_name" required></div>
                <div class="mhb-form-group"><label><?php echo esc_html(I18n::get_label('label_email')); ?> <span
                            class="required">*</span></label><input type="email" name="customer_email" required></div>
                <div class="mhb-form-group">
                    <label><?php echo esc_html(I18n::get_label('label_guests')); ?> <span class="required">*</span></label>
                    <select name="guests" id="mhb-booking-guests" required>
                        <?php
                        // Determine max guests (capacity)
                        $max_capacity = isset($room->max_adults) ? intval($room->max_adults) : 2;
                        if ($max_capacity < 1)
                            $max_capacity = 2;

                        // Pass guests from request if available, otherwise default to 2 (or max if less than 2)
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of pre-selected value, no state change
                        $selected_guests = isset($_REQUEST['guests']) ? intval(wp_unslash($_REQUEST['guests'])) : 2;
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
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of pre-selected value, no state change
                    $selected_children = isset($_REQUEST['children']) ? intval(wp_unslash($_REQUEST['children'])) : 0;
                    ?>
                    <div class="mhb-form-group">
                        <label><?php echo esc_html(I18n::get_label('label_children')); ?></label>
                        <select name="children" id="mhb-booking-children">
                            <?php for ($i = 0; $i <= $max_children; $i++): ?>
                                <option value="<?php echo esc_attr((string) $i); ?>" <?php selected($selected_children, $i); ?>>
                                    <?php echo esc_html((string) $i); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div id="mhb-child-ages-container" style="display:<?php echo $selected_children > 0 ? 'block' : 'none'; ?>;">
                        <label><?php echo esc_html(I18n::get_label('label_child_ages')); ?></label>
                        <div id="mhb-child-ages-inputs">
                            <?php
                            // Re-populate if returning from failed validation or redirect
                            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of pre-selected values, no state change
                            if ($selected_children > 0 && isset($_REQUEST['child_ages']) && is_array($_REQUEST['child_ages'])) {
                                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display, sanitized with intval
                                $child_ages_data = array_map('intval', wp_unslash($_REQUEST['child_ages']));
                                foreach ($child_ages_data as $idx => $age) {
                                    if ($idx >= $selected_children)
                                        break;
                                    echo '<div class="mhb-child-age-group">';
                                    printf('<label>' . esc_html(I18n::get_label('label_child_n_age')) . ' <span class="required">*</span></label>', esc_html((string) ($idx + 1)));
                                    echo '<input type="number" name="child_ages[]" value="' . esc_attr((string) absint($age)) . '" min="0" max="17" required class="mhb-child-age-input">';
                                    echo '</div>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mhb-form-group">
                    <label><?php echo esc_html(I18n::get_label('label_phone')); ?> <span class="required">*</span></label><input
                        type="tel" name="customer_phone" required>
                </div>
                <div class="mhb-form-group">
                    <label><?php echo esc_html(I18n::get_label('label_special_requests')); ?></label>
                    <textarea name="admin_notes" rows="3" style="width:100%"></textarea>
                </div>

                <!-- Honeypot field for spam prevention -->
                <div style="display:none !important;">
                    <label><?php echo esc_html(I18n::get_label('label_spam_honeypot')); ?></label>
                    <input type="text" name="mhb_honeypot" tabindex="-1" autocomplete="off">
                </div>

                <?php
                // Render Custom Fields
                $custom_fields = get_option('mhb_custom_fields', []);
                if (!empty($custom_fields)) {
                    foreach ($custom_fields as $field) {
                        $label = isset($field['label']) ? I18n::decode(I18n::encode($field['label'])) : $field['id'];
                        $required = !empty($field['required']) ? 'required' : '';
                        $required_mark = $required ? ' <span class="required">*</span>' : '';

                        echo '<div class="mhb-form-group mhb-custom-field-group">';
                        echo '<label>' . esc_html($label) . wp_kses_post($required_mark) . '</label>';

                        if ($field['type'] === 'textarea') {
                            echo '<textarea name="mhb_custom[' . esc_attr($field['id']) . ']" rows="3" style="width:100%" ' . esc_attr($required) . '></textarea>';
                        } else {
                            $input_type = ($field['type'] === 'number') ? 'number' : 'text';
                            echo '<input type="' . esc_attr($input_type) . '" name="mhb_custom[' . esc_attr($field['id']) . ']" ' . esc_attr($required) . '>';
                        }
                        echo '</div>';
                    }
                }
                ?>

                <?php do_action('mhb_booking_form_after_inputs'); ?>

                <!-- Inline error notification area for payment/booking errors -->
                <div id="mhb-booking-errors" class="mhb-inline-errors" style="display:none;"></div>

                <div class="mhb-submit-container">
                    <button type="submit" name="mhb_confirm_booking" id="mhb-submit-btn" class="mhb-btn">
                        <?php echo esc_html(I18n::get_label('btn_confirm_booking')); ?>
                    </button>
                    <div class="mhb-secure-badge">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <span><?php echo esc_html(I18n::get_label('label_secure_payment')); ?></span>
                    </div>
                </div>
                <?php
                // Note: Booking form JavaScript logic has been moved to assets/js/mhb-booking-form.js
                // The mhb_vars configuration is injected via wp_add_inline_script() in enqueue_assets()
                ?>

            </form>
        </div>
        <?php
    }

    private function process_booking()
    {
        global $wpdb;
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['_wpnonce'])), 'mhb_process_booking')) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_security_error')) . '</div>';
            return;
        }

        // SECURITY: Rate limiting for booking submissions (5 per minute per IP)
        $ip = \MHB\Core\Security::get_client_ip();
        $rate_key = 'mhb_booking_rate_' . md5($ip);
        $count = get_transient($rate_key);
        if (false !== $count && $count >= 5) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_rate_limit_error')) . '</div>';
            return;
        }
        set_transient($rate_key, (int) $count + 1, 60);

        $room_id = absint($_POST['mhb_room_id'] ?? ($_POST['room_id'] ?? 0));
        $customer_name = sanitize_text_field(wp_unslash($_POST['customer_name'] ?? ''));
        $customer_email = sanitize_email(wp_unslash($_POST['customer_email'] ?? ''));
        $customer_phone = sanitize_text_field(wp_unslash($_POST['customer_phone'] ?? ''));
        $check_in = sanitize_text_field(wp_unslash($_POST['check_in'] ?? ''));
        $check_out = sanitize_text_field(wp_unslash($_POST['check_out'] ?? ''));
        $guests = absint($_POST['guests'] ?? 1);

        // Honeypot validation
        if (!empty($_POST['mhb_honeypot'])) {
            wp_die(esc_html(I18n::get_label('label_spam_detected')));
        }

        // Input length validation
        if (mb_strlen($customer_name) > 100) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_name_too_long')) . '</div>';
            return;
        }
        if (mb_strlen($customer_phone) > 30) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_phone_too_long')) . '</div>';
            return;
        }

        // Server-side Date Validation (Prevent bookings in the past or invalid ranges)
        $today = wp_date('Y-m-d');
        $max_future_date = wp_date('Y-m-d', strtotime('+2 years'));
        if ($check_in < $today) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_check_in_past')) . '</div>';
            return;
        }
        if ($check_in > $max_future_date) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_check_in_future')) . '</div>';
            return;
        }
        if ($check_out <= $check_in) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_check_out_after')) . '</div>';
            return;
        }
        if ($check_out > $max_future_date) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_check_out_future')) . '</div>';
            return;
        }

        // Recalculate Base Price for Security
        $room = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mhb_rooms WHERE id = %d", $room_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table

        // Validate room exists before accessing properties
        if (!$room) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_room_not_found')) . '</div>';
            return;
        }

        $room_type = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mhb_room_types WHERE id = %d", $room->type_id)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table

        $extras_input = [];
        $is_pro_active = false;

        if ($is_pro_active && isset($_POST['mhb_extras']) && is_array($_POST['mhb_extras'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via array_map below
            $extras_raw = wp_unslash($_POST['mhb_extras']);
            $extras_input = array_map('sanitize_text_field', $extras_raw);
        }

        // Validate Children
        $max_children = $room_type ? intval($room_type->max_children) : 0;
        $children = isset($_POST['children']) ? intval($_POST['children']) : 0;
        if ($children > $max_children) {
            // translators: %d: maximum number of children allowed
            echo '<div class="mhb-error">' . sprintf(esc_html(I18n::get_label('label_max_children_error')), esc_html((string) $max_children)) . '</div>';
            return;
        }

        // Validate Child Ages
        $children_ages = [];
        if ($children > 0) {
            if (isset($_POST['child_ages']) && is_array($_POST['child_ages'])) {
                $children_ages = array_map('intval', wp_unslash($_POST['child_ages']));
            }
        }

        $calc = \MHB\Core\Pricing::calculate_booking_total($room_id, $check_in, $check_out, $guests, $extras_input, $children, $children_ages);

        if (!$calc) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_price_calc_error')) . '</div>';
            return;
        }

        $calculated_total = $calc['room_total'];
        $extras_total = $calc['extras_total'];
        $total = $calc['total'];
        $booking_extras = $calc['extras_breakdown'];
        $nights = $calc['nights'];
        $tax_data = $calc['tax'] ?? null;


        if (empty($customer_name) || empty($customer_email) || empty($customer_phone) || !$room_id) {
            echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_fill_all_fields')) . '</div>';
            return;
        }

        // Validate and Sanitize Custom Fields
        $custom_data = [];
        $custom_fields_defn = get_option('mhb_custom_fields', []);
        if (!empty($custom_fields_defn)) {
            foreach ($custom_fields_defn as $defn) {
                $field_id = $defn['id'];
                $val = isset($_POST['mhb_custom'][$field_id]) ? sanitize_textarea_field(wp_unslash($_POST['mhb_custom'][$field_id])) : '';

                if (!empty($defn['required']) && empty($val)) {
                    $label = I18n::decode(I18n::encode($defn['label']));
                    // translators: %s: field label
                    echo '<div class="mhb-error">' . sprintf(esc_html(I18n::get_label('label_field_required')), esc_html($label)) . '</div>';
                    return;
                }

                if ($val !== '') {
                    $custom_data[$field_id] = $val;
                }
            }
        }

        // GDPR Consent Validation (Pro)
        if (false && get_option('mhb_gdpr_enabled', 0) && get_option('mhb_gdpr_checkbox_enabled', 0)) {
            if (!isset($_POST['mhb_consent'])) {
                echo '<div class="mhb-error">' . esc_html(I18n::get_label('msg_gdpr_required')) . '</div>';
                return;
            }
        }

        $status = 'pending';

        // Check Availability with Race Condition Protection (GET_LOCK)
        $lock_name = "mhb_booking_lock_{$room_id}";
        $wpdb->query($wpdb->prepare("SELECT GET_LOCK(%s, 10)", $lock_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock for race condition prevention

        $availability = \MHB\Core\Pricing::is_room_available($room_id, $check_in, $check_out);

        if (true !== $availability) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release
            $label = is_string($availability) ? $availability : 'label_already_booked';
            echo '<div class="mhb-error">' . esc_html(I18n::get_label($label)) . '</div>';
            return;
        }

        // Validate max guests (Adults)
        $max_adults = $room_type ? intval($room_type->max_adults) : 2;
        if ($max_adults < 1)
            $max_adults = 2;

        if ($guests > $max_adults) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release
            // translators: %d: maximum number of adults allowed
            echo '<div class="mhb-error">' . sprintf(esc_html(I18n::get_label('label_max_adults_error')), esc_html((string) $max_adults)) . '</div>';
            return;
        }


        // Validate Payment Method Requirement
        $is_pro_active = false;

        // Get Stripe settings - use correct option names matching PaymentGateways.php
        // Note: Options are saved as 1/0 (integers), not 'yes'/'no'
        $stripe_enabled = $is_pro_active && get_option('mhb_gateway_stripe_enabled', 0);
        $stripe_mode = get_option('mhb_stripe_mode', 'test');
        $stripe_key = 'live' === $stripe_mode
            ? get_option('mhb_stripe_live_publishable_key', '')
            : get_option('mhb_stripe_test_publishable_key', '');

        // Get PayPal settings - use correct option names matching PaymentGateways.php
        // Note: Options are saved as 1/0 (integers), not 'yes'/'no'
        $paypal_enabled = $is_pro_active && get_option('mhb_gateway_paypal_enabled', 0);
        $paypal_mode = get_option('mhb_paypal_mode', 'sandbox');
        $paypal_client = 'live' === $paypal_mode
            ? get_option('mhb_paypal_live_client_id', '')
            : get_option('mhb_paypal_sandbox_client_id', '');

        // Get On-site settings - use correct option name matching PaymentGateways.php
        // Note: Options are saved as 1/0 (integers), not 'yes'/'no'
        $arrival_enabled = $is_pro_active && get_option('mhb_gateway_onsite_enabled', 0);

        $has_active_gateways = ($stripe_enabled && !empty($stripe_key)) || ($paypal_enabled && !empty($paypal_client));

        // If gateways are active, enforce payment or arrival selection
        $payment_method = 'arrival';
        $payment_received = 0;

        if ($has_active_gateways) {
            $payment_method = sanitize_key(wp_unslash($_POST['mhb_payment_method'] ?? ''));
            $stripe_payment_intent = isset($_POST['mhb_stripe_payment_intent']) ? sanitize_text_field(wp_unslash($_POST['mhb_stripe_payment_intent'])) : '';
            $paypal_order_id = isset($_POST['mhb_paypal_order_id']) ? sanitize_text_field(wp_unslash($_POST['mhb_paypal_order_id'])) : '';

            // Check if payment method is actually valid and has required data
            if ('onsite' === $payment_method && $arrival_enabled) {
                // Allowed - pay on site
                $payment_method = 'arrival'; // Normalize for database
            } elseif ('arrival' === $payment_method && $arrival_enabled) {
                // Allowed - pay on arrival (alternate name)
            } elseif ('stripe' === $payment_method) {
                if (!empty($stripe_payment_intent)) {
                    // Stripe payment - will be verified below
                } else {
                    $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release
                    echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_stripe_intent_missing')) . '</div>';
                    return;
                }
            } elseif ('paypal' === $payment_method) {
                if (!empty($paypal_order_id)) {
                    // PayPal payment - will be verified below
                } else {
                    $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release
                    echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_paypal_id_missing')) . '</div>';
                    return;
                }
            } else {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release
                echo '<div class="mhb-error">' . esc_html(I18n::get_label('label_payment_required')) . '</div>';
                return;
            }
        } else {
            // Default to arrival if no gateways active
            $payment_method = 'arrival';
        }

        // Initialize payment status fields
        $payment_status = 'pending';
        $payment_transaction_id = '';
        $payment_date = null;
        $payment_amount = null;

        // Stripe Payment - Verify payment intent and mark as confirmed
        if ('stripe' === $payment_method && !empty($_POST['mhb_stripe_payment_intent'])) {
            $stripe_payment_intent = sanitize_text_field(wp_unslash($_POST['mhb_stripe_payment_intent']));
            $payment_transaction_id = $stripe_payment_intent;

            // Verify the payment intent with Stripe API
            $stripe_mode = get_option('mhb_stripe_mode', 'test');
            $secret_key = 'live' === $stripe_mode
                ? get_option('mhb_stripe_live_secret_key', '')
                : get_option('mhb_stripe_test_secret_key', '');

            // Decrypt the secret key if it's encrypted
            // Pro feature: secret key decryption not available in Free version

            if (!empty($secret_key)) {
                $response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $stripe_payment_intent, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $secret_key,
                        'Stripe-Version' => '2023-10-16',
                    ],
                    'timeout' => 30,
                ]);

                if (!is_wp_error($response)) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    // Check if payment was successful
                    if (isset($body['status']) && $body['status'] === 'succeeded') {
                        // SECURITY: Verify payment amount matches booking total
                        $stripe_amount = isset($body['amount']) ? floatval($body['amount']) / 100 : 0;
                        $expected_amount = floatval($total);
                        $currency = strtolower(get_option('mhb_currency_code', 'USD'));
                        $stripe_currency = strtolower($body['currency'] ?? '');

                        // Verify amount matches (with 1 cent tolerance for rounding)
                        // Also verify currency matches
                        if (abs($stripe_amount - $expected_amount) > 0.01 || $stripe_currency !== $currency) {
                            // SECURITY: Amount or currency mismatch - potential fraud
                            $payment_status = 'failed';
                            $admin_notes = sprintf(
                                'Payment verification failed: expected %s %.2f, received %s %.2f',
                                $currency,
                                $expected_amount,
                                $stripe_currency,
                                $stripe_amount
                            );
                        } else {
                            $status = 'confirmed';
                            $payment_status = 'completed';
                            $payment_received = 1;
                            $payment_date = current_time('mysql');
                            $payment_amount = $stripe_amount;
                        }
                    } else {
                        $payment_status = 'failed';
                    }
                } else {
                    $payment_status = 'failed';
                }
            }
        }

        // PayPal Payment - SECURITY: Verify server-side with PayPal API
        if ('paypal' === $payment_method && !empty($_POST['mhb_paypal_order_id'])) {
            $payment_transaction_id = sanitize_text_field(wp_unslash($_POST['mhb_paypal_order_id']));

            // SECURITY: Verify PayPal order server-side
            $paypal_verified = false;
            // Pro feature: PayPal verification not available in Free version

            if ($paypal_verified) {
                $status = 'confirmed';
                $payment_status = 'completed';
                $payment_received = 1;
                $payment_date = current_time('mysql');
                $payment_amount = floatval($total);
            } else {
                // SECURITY: PayPal verification failed
                $payment_status = 'failed';
                $status = 'pending';
                $admin_notes = 'PayPal payment verification failed. Order ID: ' . $payment_transaction_id;
            }
        }

        // Set payment status for arrival payments - booking remains pending until admin approval
        if ('arrival' === $payment_method) {
            $payment_status = 'pending';
            // Status remains 'pending' - admin must approve deposit/payment to confirm booking
        }

        $wpdb->insert($wpdb->prefix . 'mhb_bookings', [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
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
            'booking_extras' => !empty($booking_extras) ? json_encode($booking_extras) : null,
            'admin_notes' => sanitize_textarea_field(wp_unslash($_POST['admin_notes'] ?? '')),
            'payment_method' => sanitize_key($payment_method),
            'payment_received' => (int) $payment_received,
            'payment_status' => $payment_status,
            'payment_transaction_id' => !empty($payment_transaction_id) ? $payment_transaction_id : null,
            'payment_date' => $payment_date,
            'payment_amount' => $payment_amount,
            'guests' => $guests,
            'children' => $children,
            'children_ages' => json_encode($children_ages),
            'custom_fields' => !empty($custom_data) ? json_encode($custom_data) : null,
            // Tax fields
            'tax_enabled' => ($tax_data && $tax_data['enabled']) ? 1 : 0,
            'tax_mode' => $tax_data['mode'] ?? 'disabled',
            'tax_rate_accommodation' => $tax_data['breakdown']['rates']['accommodation'] ?? 0,
            'tax_rate_extras' => $tax_data['breakdown']['rates']['extras'] ?? 0,
            'room_total_net' => $tax_data['breakdown']['totals']['room_net'] ?? 0,
            'room_tax' => $tax_data['breakdown']['totals']['room_tax'] ?? 0,
            'children_total_net' => $tax_data['breakdown']['totals']['children_net'] ?? 0,
            'children_tax' => $tax_data['breakdown']['totals']['children_tax'] ?? 0,
            'extras_total_net' => $tax_data['breakdown']['totals']['extras_net'] ?? 0,
            'extras_tax' => $tax_data['breakdown']['totals']['extras_tax'] ?? 0,
            'subtotal_net' => $tax_data['breakdown']['totals']['subtotal_net'] ?? floatval($total),
            'total_tax' => $tax_data['breakdown']['totals']['total_tax'] ?? 0,
            'total_gross' => $tax_data['breakdown']['totals']['total_gross'] ?? floatval($total),
            'tax_breakdown' => $tax_data ? json_encode($tax_data['breakdown']) : null,
        ]);

        $booking_id = $wpdb->insert_id;
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release

        if ($booking_id) {
            // Invalidate booking and calendar cache to ensure availability and lists are updated
            \MHB\Core\Cache::invalidate_booking($booking_id, (int) $room_id);
            do_action('mhb_booking_created', $booking_id);
            if ('confirmed' === $status) {
                do_action('mhb_booking_confirmed', $booking_id);
            }
        }

        // Send Email - only for completed payments or arrival payments
        if (class_exists('MHB\Core\Email')) {
            if ('completed' === $payment_status || 'arrival' === $payment_method) {
                Email::send_email($booking_id, $status);
            }
        }

        // Show success message with payment-specific feedback
        $msg_title = I18n::get_label('msg_booking_confirmed');
        $msg_detail = I18n::get_label('msg_confirmation_sent');
        $message_class = 'mhb-success-message';

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
            $message_class = 'mhb-success-message mhb-pending-booking';
        }

        echo '<div class="' . esc_attr($message_class) . '"><h3>' . esc_html($msg_title) . '</h3><p>' . esc_html($msg_detail) . '</p>';

        // Show tax breakdown if enabled
        if (\MHB\Core\Tax::is_enabled() && $tax_data && ($tax_data['enabled'] ?? false)):
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method returns sanitized HTML
            echo \MHB\Core\Tax::render_breakdown_html($tax_data['breakdown']);
        endif;

        // Show payment summary for completed payments
        if ('completed' === $payment_status) {
            echo '<p class="mhb-payment-received"><strong>' . esc_html(I18n::get_label('label_payment_status')) . '</strong> <span class="mhb-badge-success">' . esc_html(I18n::get_label('label_paid')) . '</span></p>';
            if ($payment_amount) {
                echo '<p class="mhb-payment-amount"><strong>' . esc_html(I18n::get_label('label_amount_paid')) . '</strong> ' . esc_html(I18n::format_currency($payment_amount)) . '</p>';
            }
            if ($payment_transaction_id) {
                echo '<p class="mhb-transaction-id"><strong>' . esc_html(I18n::get_label('label_transaction_id')) . '</strong> ' . esc_html($payment_transaction_id) . '</p>';
            }
        } elseif ('pending' === $payment_status && 'arrival' === $payment_method) {
            echo '<p class="mhb-payment-pending"><strong>' . esc_html(I18n::get_label('label_payment_status')) . '</strong> <span class="mhb-badge-pending">' . esc_html(I18n::get_label('label_pay_arrival')) . '</span></p>';
        } elseif ('failed' === $payment_status) {
            echo '<p class="mhb-payment-failed"><strong>' . esc_html(I18n::get_label('label_payment_status')) . '</strong> <span class="mhb-badge-failed">' . esc_html(I18n::get_label('label_failed')) . '</span></p>';
        }

        echo '</div>';
    }

    /**
     * Inject theme styles based on Pro settings.
     * Note: Theme styles are applied to all users to ensure CSS variables are defined.
     */
    public static function inject_theme_styles()
    {
        $active_theme = get_option('mhb_active_theme', 'midnight');
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

        if ('custom' === $active_theme) {
            $primary = get_option('mhb_custom_primary_color', '#1a365d');
            $secondary = get_option('mhb_custom_secondary_color', '#f2e2c4');
            $accent = get_option('mhb_custom_accent_color', '#d4af37');
        } elseif (isset($presets[$active_theme])) {
            $primary = $presets[$active_theme][0];
            $secondary = $presets[$active_theme][1];
            $accent = $presets[$active_theme][2];
        }

        if ($primary) {
            $custom_css = ":root {
                --mhb-primary: {$primary};
                --mhb-secondary: {$secondary};
                --mhb-accent: {$accent};
            }";
            wp_add_inline_style('mhb-style', $custom_css);
            wp_add_inline_style('mhb-calendar-style', $custom_css);
        }

        // SECURITY: Sanitize custom CSS to prevent injection attacks
        // wp_strip_all_tags removes HTML/JS that could be injected via admin settings
        $extra_css = get_option('mhb_custom_css');
        if ($extra_css) {
            $extra_css = wp_strip_all_tags($extra_css);
            // Additional safety: remove any potential JS execution patterns
            $extra_css = preg_replace('/\bexpression\s*\(/i', '', $extra_css);
            $extra_css = preg_replace('/\bjavascript\s*:/i', '', $extra_css);
            $extra_css = preg_replace('/\bbehavior\s*:/i', '', $extra_css);
            $extra_css = preg_replace('/\b-moz-binding\s*:/i', '', $extra_css);
            wp_add_inline_style('mhb-style', $extra_css);
            wp_add_inline_style('mhb-calendar-style', $extra_css);
        }

        wp_add_inline_style('mhb-frontend', '
            .mhb-child-age-group { 
                display: flex; 
                align-items: center; 
                justify-content: space-between; 
                background: rgba(0,0,0,0.03); 
                padding: 8px 12px; 
                border-radius: 6px; 
                margin-bottom: 8px !important;
            }
            .mhb-child-age-group label { margin: 0 !important; font-weight: normal !important; }
            .mhb_faded { opacity: 0.5; transition: opacity 0.3s; pointer-events: none; }
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

