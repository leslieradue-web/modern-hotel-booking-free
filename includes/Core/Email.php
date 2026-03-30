<?php declare(strict_types=1);

namespace MHBO\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Email
{
    /**
     * Initialize email hooks.
     */
    public static function init()
    {
        add_action('mhbo_booking_confirmed', [self::class, 'handle_booking_confirmed'], 20);
        add_action('mhbo_booking_created', [self::class, 'handle_booking_created'], 20);
        add_action('mhbo_booking_cancelled', [self::class, 'handle_booking_cancelled'], 20);
    }

    /**
     * Handler for booking confirmation event (Verified Payment / Manual Approval).
     */
    public static function handle_booking_confirmed($booking_id)
    {
        return self::send_email((int) $booking_id, 'confirmed');
    }

    /**
     * Handler for booking cancellation event.
     */
    public static function handle_booking_cancelled($booking_id)
    {
        return self::send_email((int) $booking_id, 'cancelled');
    }

    /**
     * Handler for booking creation event (Receipt / Arrival Selection).
     */
    public static function handle_booking_created($booking_id)
    {
        global $wpdb;
        // RATIONALE: Required to check payment method for initial 'created' email only for offline methods.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $booking = $wpdb->get_row($wpdb->prepare("SELECT payment_method, status FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", (int) $booking_id));

        if (!$booking) {
            return;
        }

        // Only send initial 'created' email for 'arrival'/'onsite' methods right away.
        // Stripe/PayPal bookings will wait for the 'confirmed' hook after verification.
        if (in_array($booking->payment_method, ['arrival', 'onsite'], true)) {
            return self::send_email((int) $booking_id, $booking->status);
        }
    }

    /**
     * Alias for send_booking_email for backward compatibility.
     */
    public static function send_email($booking_id, $status)
    {
        return self::send_booking_email($booking_id, $status);
    }

    /**
     * Send a booking notification email to the customer.
     * Only sends for completed payments or arrival payment method.
     */
    public static function send_booking_email($booking_id, $status)
    {
        global $wpdb;

        $cache_key = 'mhbo_booking_' . $booking_id;
        $booking = wp_cache_get($cache_key, 'mhbo_bookings');

        if (false === $booking) {
            // RATIONALE: Required to fetch full booking record for email template rendering.
            // Uses $wpdb->prepare with %d; result is cached via wp_cache_set.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, specific lookup
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
                $booking_id
            ));
            if ($booking) {
                wp_cache_set($cache_key, $booking, 'mhbo_bookings', HOUR_IN_SECONDS);
            }
        }

        if (!$booking) {
            return;
        }

        // Check if we should send email based on payment status
        $payment_status = isset($booking->payment_status) ? $booking->payment_status : 'pending';
        $payment_method = isset($booking->payment_method) ? $booking->payment_method : 'onsite';

        // DEDUPLICATION: Prevent duplicate confirmation emails
        if (isset($booking->email_sent) && (int) $booking->email_sent === 1 && 'confirmed' === $status) {
            return;
        }

        // Allow email if:
        // 1. Status is explicitly 'confirmed' (admin manually confirmed the booking), OR
        // 2. Payment is completed, OR
        // 3. Payment method is 'arrival' or 'onsite'
        $email_allowed = ('confirmed' === $status) || ('completed' === $payment_status) || ('arrival' === $payment_method) || ('onsite' === $payment_method);

        if (!$email_allowed) {
            // Payment not confirmed and not explicitly confirmed by admin - don't send confirmation email yet
            return;
        }

        // UPDATE STATUS: Mark as sent BEFORE wp_mail to avoid race conditions with webhooks
        if ('confirmed' === $status) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update
            $wpdb->update(
                $wpdb->prefix . 'mhbo_bookings',
                ['email_sent' => 1],
                ['id' => $booking_id],
                ['%d'],
                ['%d']
            );
            // Invalidate cache
            wp_cache_delete($cache_key, 'mhbo_bookings');
        }

        $lang = $booking->booking_language ?: I18n::get_current_language();

        // Load multilingual templates from options with hardcoded fallbacks if empty
        $template_subject = get_option("mhbo_email_{$status}_subject");
        if (empty($template_subject)) {
            $template_subject = "[:en]Booking {$status} - #{$booking_id}[:]";
        }

        $template_message = get_option("mhbo_email_{$status}_message");
        if (empty($template_message)) {
            $template_message = "[:en]Hello {customer_name}, your booking #{$booking_id} status is now {$status}.[:]";
        }

        // SECURITY: Validate email address before sending
        $to = sanitize_email($booking->customer_email);
        if (!is_email($to)) {
            // Invalid email - skip sending
            return;
        }
        $subject = I18n::decode($template_subject, $lang);
        $message = I18n::decode($template_message, $lang);

        // Fetch room name for placeholder - with caching
        $room_name_cache_key = 'mhbo_room_name_' . $booking->room_id;
        $room_name = wp_cache_get($room_name_cache_key, 'mhbo');

        if (false === $room_name) {
            // RATIONALE: Required to resolve room name by joining rooms+room_types for email placeholder.
            // Uses $wpdb->prepare with %d; result is cached via wp_cache_set.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented
            $room_name = $wpdb->get_var($wpdb->prepare(
                "SELECT t.name FROM {$wpdb->prefix}mhbo_rooms r 
                 JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id 
                 WHERE r.id = %d",
                $booking->room_id
            ));
            wp_cache_set($room_name_cache_key, $room_name, 'mhbo', HOUR_IN_SECONDS);
        }
        $room_name = I18n::decode($room_name, $lang);

        // Format Custom Fields for placeholder
        $custom_fields_formatted = '';
        if (!empty($booking->custom_fields)) {
            $custom_data = json_decode($booking->custom_fields, true);
            $custom_defn = get_option('mhbo_custom_fields', []);
            if (is_array($custom_data) && !empty($custom_defn)) {
                foreach ($custom_defn as $defn) {
                    if (isset($custom_data[$defn['id']])) {
                        $f_label = I18n::decode(I18n::encode($defn['label']), $lang);
                        $custom_fields_formatted .= esc_html($f_label) . ': ' . esc_html($custom_data[$defn['id']]) . "<br>\n";
                    }
                }
            }
        }

        // Build payment details section
        $payment_details = '';
        if ('completed' === $payment_status) {
            $payment_details = '<div style="margin-top: 20px; padding: 15px; background: #e8f5e9; border-radius: 5px;">';
            $payment_details .= '<h4 style="margin: 0 0 10px 0; color: #2e7d32;">' . esc_html(I18n::get_label('label_payment_confirmation')) . '</h4>';
            $payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_payment_status')) . '</strong> ' . esc_html(I18n::get_label('label_paid')) . '</p>';
            if (!empty($booking->payment_amount)) {
                $payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_amount_paid')) . '</strong> ' . I18n::format_currency($booking->payment_amount) . '</p>';
            }
            if (!empty($booking->payment_transaction_id)) {
                $payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_transaction_id')) . '</strong> ' . esc_html($booking->payment_transaction_id) . '</p>';
            }
            if (!empty($booking->payment_date)) {
                $payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_payment_date')) . '</strong> ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($booking->payment_date)) . '</p>';
            }
            if (!empty($booking->payment_method)) {
                $payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_payment_method')) . '</strong> ' . esc_html(ucfirst($booking->payment_method)) . '</p>';
            }
            $payment_details .= '</div>';
        } elseif ('arrival' === $payment_method || 'onsite' === $payment_method) {
            $payment_details = self::get_business_payment_details_html($booking_id, (float) $booking->total_price);
        }

        // Build tax breakdown section
        $tax_breakdown_html = '';
        $tax_breakdown_text = '';
        $tax_total = '';
        $tax_registration_number = '';

        if (!empty($booking->tax_breakdown)) {
            $tax_data = json_decode($booking->tax_breakdown, true);
            if ($tax_data && ($tax_data['enabled'] ?? false)) {
                // Use the new consolidated rendering methods
                $meta = [
                    'guests' => $booking->guests,
                    'children' => $booking->children,
                ];
                $tax_breakdown_html = Tax::render_breakdown_html($tax_data, $lang, true, $meta);
                $tax_breakdown_text = Tax::render_breakdown_text($tax_data, $lang, $meta);

                // Set individual placeholders for backward compatibility or custom templates
                $totals = $tax_data['totals'] ?? [];
                $tax_total = I18n::format_currency($totals['total_tax'] ?? 0);
                $tax_registration_number = $tax_data['registration_number'] ?? Tax::get_registration_number();
            }
        }

        // If tax is enabled but no breakdown stored (fallback), show basic info
        if (empty($tax_breakdown_html) && Tax::is_enabled()) {
            $tax_label = Tax::get_label($lang);
            $tax_mode = Tax::get_mode();
            $reg_number = Tax::get_registration_number();
            $accommodation_rate = Tax::get_accommodation_rate();
            $extras_rate = Tax::get_extras_rate();

            $tax_breakdown_html = '<div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 5px; font-family: Arial, sans-serif;">';
            if (Tax::MODE_VAT === $tax_mode) {
                if ($accommodation_rate === $extras_rate) {
                    $tax_breakdown_html .= '<p style="margin: 0; font-size: 14px; color: #666;">' . esc_html(sprintf(I18n::get_label('label_price_includes_tax'), $tax_label, $accommodation_rate)) . '</p>';
                } else {
                    // translators: 1: tax label (e.g., VAT), 2: accommodation tax rate, 3: extras tax rate
                    $tax_breakdown_html .= '<p style="margin: 0; font-size: 14px; color: #666;">' . esc_html(sprintf(__('Price includes %1$s - Accommodation: %2$s%%, Extras: %3$s%%', 'modern-hotel-booking'), $tax_label, $accommodation_rate, $extras_rate)) . '</p>';
                }
            } elseif (Tax::MODE_SALES_TAX === $tax_mode) {
                if ($accommodation_rate === $extras_rate) {
                    $tax_breakdown_html .= '<p style="margin: 0; font-size: 14px; color: #666;">' . esc_html(sprintf(I18n::get_label('label_tax_added_at_checkout'), $tax_label, $accommodation_rate)) . '</p>';
                } else {
                    // translators: 1: tax label (e.g., Sales Tax), 2: accommodation tax rate, 3: extras tax rate
                    $tax_breakdown_html .= '<p style="margin: 0; font-size: 14px; color: #666;">' . esc_html(sprintf(__('%1$s added at checkout - Accommodation: %2$s%%, Extras: %3$s%%', 'modern-hotel-booking'), $tax_label, $accommodation_rate, $extras_rate)) . '</p>';
                }
            }
            if (!empty($reg_number)) {
                $tax_breakdown_html .= '<p style="margin: 10px 0 0 0; font-size: 12px; color: #999;">' . esc_html(sprintf(I18n::get_label('label_tax_registration'), $reg_number)) . '</p>';
            }
            $tax_breakdown_html .= '</div>';

            $tax_registration_number = $reg_number;
        }

        // Format extras
        $booking_extras_html = self::format_extras($booking, $lang, 'html');
        $booking_extras_text = self::format_extras($booking, $lang, 'text');

        // Fetch placeholder collection
        $placeholders = self::get_booking_placeholders($booking, $status, $lang, [
            'extras_html' => $booking_extras_html,
            'extras_text' => $booking_extras_text,
            'custom_fields_formatted' => $custom_fields_formatted,
            'tax_breakdown_html' => $tax_breakdown_html,
            'tax_breakdown_text' => $tax_breakdown_text,
            'tax_total' => $tax_total,
            'tax_registration_number' => $tax_registration_number,
            'room_name' => $room_name
        ]);

        // Append tax breakdown if placeholder is NOT in the template and tax is enabled.
        // Must check BEFORE replacement, since apply_placeholders removes the literal placeholder.
        $has_tax_placeholder = (false !== strpos($message, '{tax_breakdown}'));

        // Replace placeholders with smart cleanup
        $subject = self::apply_placeholders((string) $subject, $placeholders);
        $message = self::apply_placeholders((string) $message, $placeholders);

        if (!$has_tax_placeholder && !empty($tax_breakdown_html)) {
            $message .= $tax_breakdown_html;
        }

$admin_email = get_option('mhbo_notification_email', get_option('admin_email'));
        $site_name = get_bloginfo('name');

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>',
            'Reply-To: ' . $admin_email,
            'Bcc: ' . $admin_email
        );
        $attachments = array();

        // Add iCal attachment for confirmed bookings
        if ('confirmed' === $status) {
            $ics_content = self::generate_simple_ics($booking);
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . '/booking-' . $booking_id . '.ics';
            file_put_contents($file_path, $ics_content);
            $attachments[] = $file_path;
        }

        wp_mail($to, $subject, $message, $headers, $attachments);

        // Clean up temporary iCal file
        if (!empty($attachments)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporary file cleanup
            @unlink($attachments[0]);
        }
    }

    /**
     * Send a payment confirmation email (separate receipt).
     *
     * @param int   $booking_id The booking ID.
     * @param array $payment_data Payment details array.
     */
    public static function send_payment_confirmation_email($booking_id, $payment_data = array())
    {
        global $wpdb;
        // RATIONALE: Required to fetch booking record for payment confirmation email.
        // Uses $wpdb->prepare with %d; one-shot send, caching not beneficial.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, specific lookup
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
            $booking_id
        ));

        if (!$booking) {
            return;
        }

        $lang = $booking->booking_language ?: I18n::get_current_language();

        // Load multilingual templates from options with hardcoded fallbacks if empty
        $template_subject = get_option("mhbo_email_payment_subject");
        if (empty($template_subject)) {
            $template_subject = "[:en]Payment Confirmation - Booking #{$booking_id}[:]";
        }

        $template_message = get_option("mhbo_email_payment_message");
        if (empty($template_message)) {
            $template_message = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
            $template_message .= '<h2 style="color: #2e7d32;">' . __('Payment Confirmation', 'modern-hotel-booking') . '</h2>';
            // translators: %s: customer name
            $template_message .= '<p>' . sprintf(__('Dear %s,', 'modern-hotel-booking'), '{customer_name}') . '</p>';
            $template_message .= '<p>' . __('Thank you for your payment. Your booking has been confirmed.', 'modern-hotel-booking') . '</p>';

            $template_message .= '<div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 20px 0;">';
            $template_message .= '<h4 style="margin: 0 0 15px 0;">' . __('Booking Details', 'modern-hotel-booking') . '</h4>';
            $template_message .= '<p style="margin: 5px 0;"><strong>' . __('Booking ID:', 'modern-hotel-booking') . '</strong> #{booking_id}</p>';
            $template_message .= '<p style="margin: 5px 0;"><strong>' . __('Check-in:', 'modern-hotel-booking') . '</strong> {check_in}</p>';
            $template_message .= '<p style="margin: 5px 0;"><strong>' . __('Check-out:', 'modern-hotel-booking') . '</strong> {check_out}</p>';
            $template_message .= '</div>';

            $template_message .= '{payment_details}';

            $template_message .= '<p>' . __('If you have any questions, please don\'t hesitate to contact us.', 'modern-hotel-booking') . '</p>';
            $template_message .= '<p>' . __('Best regards,', 'modern-hotel-booking') . '<br>' . get_bloginfo('name') . '</p>';
            $template_message .= '</div>';
        }

        $subject = I18n::decode($template_subject, $lang);
        $message = I18n::decode($template_message, $lang);

        // Build payment details section
        $payment_details = '<div style="background: #e8f5e9; padding: 15px; border-radius: 5px; margin: 20px 0;">';
        $payment_details .= '<h4 style="margin: 0 0 15px 0; color: #2e7d32;">' . __('Payment Details', 'modern-hotel-booking') . '</h4>';
        $payment_details .= '<p style="margin: 5px 0;"><strong>' . __('Amount Paid:', 'modern-hotel-booking') . '</strong> ' . I18n::format_currency(isset($payment_data['amount']) ? $payment_data['amount'] : $booking->total_price) . '</p>';

        if (!empty($payment_data['transaction_id'])) {
            $payment_details .= '<p style="margin: 5px 0;"><strong>' . __('Transaction ID:', 'modern-hotel-booking') . '</strong> ' . esc_html($payment_data['transaction_id']) . '</p>';
        } elseif (!empty($booking->payment_transaction_id)) {
            $payment_details .= '<p style="margin: 5px 0;"><strong>' . __('Transaction ID:', 'modern-hotel-booking') . '</strong> ' . esc_html($booking->payment_transaction_id) . '</p>';
        }

        if (!empty($payment_data['method'])) {
            $method_name = ucfirst($payment_data['method']);
            $payment_details .= '<p style="margin: 5px 0;"><strong>' . __('Payment Method:', 'modern-hotel-booking') . '</strong> ' . esc_html($method_name) . '</p>';
        }

        $payment_details .= '<p style="margin: 5px 0;"><strong>' . __('Payment Date:', 'modern-hotel-booking') . '</strong> ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime(current_time('mysql'))) . '</p>';
        $payment_details .= '</div>';

        // Build tax breakdown section
        $tax_breakdown_html = '';
        $tax_breakdown_text = '';
        $tax_total = '';
        $tax_registration_number = '';

        if (!empty($booking->tax_breakdown)) {
            $tax_data = json_decode($booking->tax_breakdown, true);
            if ($tax_data && ($tax_data['enabled'] ?? false)) {
                $meta = [
                    'guests' => $booking->guests,
                    'children' => $booking->children,
                ];
                $tax_breakdown_html = Tax::render_breakdown_html($tax_data, $lang, true, $meta);
                $tax_breakdown_text = Tax::render_breakdown_text($tax_data, $lang, $meta);
                $totals = $tax_data['totals'] ?? [];
                $tax_total = I18n::format_currency($totals['total_tax'] ?? 0);
                $tax_registration_number = $tax_data['registration_number'] ?? Tax::get_registration_number();
            }
        }

        // Format Custom Fields for placeholder
        $custom_fields_formatted = '';
        if (!empty($booking->custom_fields)) {
            $custom_data = json_decode($booking->custom_fields, true);
            $custom_defn = get_option('mhbo_custom_fields', []);
            if (is_array($custom_data) && !empty($custom_defn)) {
                foreach ($custom_defn as $defn) {
                    if (isset($custom_data[$defn['id']])) {
                        $f_label = I18n::decode(I18n::encode($defn['label']), $lang);
                        $custom_fields_formatted .= esc_html($f_label) . ': ' . esc_html($custom_data[$defn['id']]) . "<br>\n";
                    }
                }
            }
        }

        // Fetch room name for placeholder - with caching
        $room_name_cache_key = 'mhbo_room_name_' . $booking->room_id;
        $room_name = wp_cache_get($room_name_cache_key, 'mhbo');

        if (false === $room_name) {
            // RATIONALE: Required to resolve room name for payment confirmation email placeholder.
            // Uses $wpdb->prepare with %d; result is cached via wp_cache_set.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented
            $room_name = $wpdb->get_var($wpdb->prepare(
                "SELECT t.name FROM {$wpdb->prefix}mhbo_rooms r 
                 JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id 
                 WHERE r.id = %d",
                $booking->room_id
            ));
            wp_cache_set($room_name_cache_key, $room_name, 'mhbo', HOUR_IN_SECONDS);
        }
        $room_name = I18n::decode($room_name, $lang);

        // Format extras
        $booking_extras_html = self::format_extras($booking, $lang, 'html');
        $booking_extras_text = self::format_extras($booking, $lang, 'text');

        // Fetch placeholder collection
        $placeholders = self::get_booking_placeholders($booking, 'payment', $lang, [
            'extras_html' => $booking_extras_html,
            'extras_text' => $booking_extras_text,
            'custom_fields_formatted' => $custom_fields_formatted,
            'tax_breakdown_html' => $tax_breakdown_html,
            'tax_breakdown_text' => $tax_breakdown_text,
            'tax_total' => $tax_total,
            'tax_registration_number' => $tax_registration_number,
            'room_name' => $room_name,
            'payment_details' => $payment_details
        ]);

        // Check for placeholders BEFORE replacement (replacement removes the literal tokens).
        $has_payment_placeholder = (false !== strpos($message, '{payment_details}'));

        // Replace placeholders with smart cleanup
        $subject = self::apply_placeholders((string) $subject, $placeholders);
        $message = self::apply_placeholders((string) $message, $placeholders);

        // Append payment details only if the template didn't include the placeholder
        if (!$has_payment_placeholder) {
            $message .= $payment_details;
        }

        $admin_email = get_option('mhbo_notification_email', get_option('admin_email'));
        $site_name = get_bloginfo('name');

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>',
            'Reply-To: ' . $admin_email,
            'Bcc: ' . $admin_email
        );

        // SECURITY: Validate email before sending
        $to = sanitize_email($booking->customer_email);
        if (!is_email($to)) {
            // Invalid email - skip sending
            return;
        }

        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Generate a simple ICS file for email attachments.
     */
    private static function generate_simple_ics($booking)
    {
        $dtstart = wp_date('Ymd', strtotime($booking->check_in));
        $dtend = wp_date('Ymd', strtotime($booking->check_out));
        $now = wp_date('Ymd\THis\Z');

        return "BEGIN:VCALENDAR\r\n" .
            "VERSION:2.0\r\n" .
            "PRODID:-//Modern Hotel Booking//EN\r\n" .
            "BEGIN:VEVENT\r\n" .
            "UID:mhbo-booking-{$booking->id}\r\n" .
            "DTSTAMP:$now\r\n" .
            "DTSTART;VALUE=DATE:$dtstart\r\n" .
            "DTEND;VALUE=DATE:$dtend\r\n" .
            "SUMMARY:Hotel Booking #{$booking->id}\r\n" .
            "END:VEVENT\r\n" .
            "END:VCALENDAR";
    }

    /**
     * Get placeholders for Business Information.
     *
     * @param string $lang Current language.
     * @return array
     */
    private static function get_business_placeholders($lang = '')
    {
        $placeholders = [];

        if (class_exists('MHBO\Business\Info')) {
            $company  = \MHBO\Business\Info::get_company();
            $whatsapp = \MHBO\Business\Info::get_whatsapp();

            $placeholders['{company_name}']         = I18n::decode($company['name'] ?? '', $lang);
            $placeholders['{company_address}']      = I18n::decode($company['address'] ?? '', $lang);
            $placeholders['{company_phone}']        = $company['phone'] ?? '';
            $placeholders['{company_email}']        = $company['email'] ?? '';
            $placeholders['{company_website}']      = $company['website'] ?? '';
            $placeholders['{company_registration}'] = $company['registration_number'] ?? '';

            $placeholders['{whatsapp_number}']      = $whatsapp['number'] ?? '';
            $placeholders['{whatsapp_link}']        = !empty($whatsapp['number']) ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $whatsapp['number']) : '';
        }

        return $placeholders;
    }

    /**
     * Build the complete set of email placeholders with sanitization and fallbacks.
     *
     * @param object $booking    The booking database row.
     * @param string $status     The booking status.
     * @param string $lang       The target language.
     * @param array  $additional Additional pre-rendered components.
     * @return array
     */
    public static function get_booking_placeholders($booking, $status, $lang, $additional = [])
    {
        $check_in  = !empty($booking->check_in) ? strtotime($booking->check_in) : 0;
        $check_out = !empty($booking->check_out) ? strtotime($booking->check_out) : 0;
        $nights    = ($check_in > 0 && $check_out > $check_in) ? (int) round(($check_out - $check_in) / DAY_IN_SECONDS) : 0;

        // SANITIZATION: All user-provided data must be escaped for use in HTML emails.
        $c_name  = !empty($booking->customer_name) ? esc_html($booking->customer_name) : __('Guest', 'modern-hotel-booking');
        $c_email = !empty($booking->customer_email) ? sanitize_email($booking->customer_email) : '';
        $c_phone = !empty($booking->customer_phone) ? esc_html($booking->customer_phone) : '';
        $special = !empty($booking->special_requests) ? esc_html($booking->special_requests) : '';

        $booking_token = $booking->booking_token ?? '';
        $view_url = $booking_token ? get_rest_url(null, 'mhbo/v1/bookings/' . $booking_token) : '';

        $placeholders = [
            // Core IDs & References
            '{booking_id}'              => (int) ($booking->id ?? 0),
            '{booking_token}'           => $booking_token,
            '{booking_reference}'       => $booking_token, // Alias for backward compatibility
            '{view_url}'                => esc_url($view_url),

            // Stay Information
            '{check_in}'                => $check_in > 0 ? date_i18n(get_option('date_format'), $check_in) : '--',
            '{check_out}'               => $check_out > 0 ? date_i18n(get_option('date_format'), $check_out) : '--',
            '{check_in_time}'           => esc_html(get_option('mhbo_check_in_time', '14:00')),
            '{check_out_time}'           => esc_html(get_option('mhbo_check_out_time', '11:00')),
            '{nights}'                  => $nights,
            '{guests}'                  => (int) ($booking->guests ?? 1),
            '{children}'                => (int) ($booking->children ?? 0),
            '{total_price}'             => I18n::format_currency((float) ($booking->total_price ?? 0)),
            '{status}'                  => I18n::translate_status((string) $status),
            '{room_name}'               => esc_html($additional['room_name'] ?? ''),

            // Customer Information
            '{customer_name}'           => $c_name,
            '{customer_email}'          => $c_email,
            '{customer_phone}'          => $c_phone,
            '{special_requests}'        => $special,
            '{arrival_time}'            => esc_html($booking->arrival_time ?? ''),

            // Pre-rendered Components
            '{custom_fields}'           => $additional['custom_fields_formatted'] ?? '',
            '{booking_extras}'          => $additional['extras_html'] ?? '',
            '{extras}'                  => $additional['extras_html'] ?? '', // Alias
            '{extras_text}'             => $additional['extras_text'] ?? '',
            '{payment_details}'         => $additional['payment_details'] ?? '',

            // Tax Details
            '{tax_breakdown}'           => $additional['tax_breakdown_html'] ?? '',
            '{tax_breakdown_text}'      => $additional['tax_breakdown_text'] ?? '',
            '{tax_total}'               => $additional['tax_total'] ?? '',
            '{tax_registration_number}' => esc_html($additional['tax_registration_number'] ?? ''),

            // Global Info
            '{site_name}'               => esc_html(get_bloginfo('name')),
            
        ];

        // Add Business Information Placeholders
        $placeholders = array_merge($placeholders, self::get_business_placeholders($lang));

        return $placeholders;
    }

    /**
     * Apply placeholders to a string and clean up messy formatting.
     *
     * @param string $text The text containing placeholders.
     * @param array  $placeholders Map of placeholders to values.
     * @return string The processed text.
     */
    public static function apply_placeholders($text, $placeholders)
    {
        if (empty($text)) {
            return '';
        }

        // 1. Decode HTML entities (wp_editor may encode curly braces as &#123; &#125;)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Perform raw replacement
        $text = str_replace(array_keys($placeholders), array_values($placeholders), $text);

        // 3. Smart Cleanup of messy formatting
        // Collapse multiple commas on the same line (e.g., ", , ,") into a single comma
        $text = preg_replace('/,[ \t]*(,[ \t]*)+/', ', ', $text);

        // Remove leading commas on lines (e.g., ", pending")
        $text = preg_replace('/^[ \t]*,+[ \t]*/m', '', $text);

        // Remove trailing commas ONLY if they follow a space (dangling separator)
        // This preserves greeting commas like "Hi {customer_name}," which usually have no space before the comma
        $text = preg_replace('/[ \t]+,[ \t]*$/m', '', $text);

        // Collapse extra vertical whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Handle commas near HTML breaks
        $text = preg_replace('/,\s*<br\s*\/?>/i', '<br>', $text);
        $text = preg_replace('/<br\s*\/?>\s*,/i', '<br>', $text);

        return trim($text);
    }

    /**
     * Format booking extras for email placeholders.
     */
    private static function format_extras($booking, $lang, $format = 'html')
    {
        if (empty($booking->booking_extras)) {
            return '';
        }

        $extras = json_decode($booking->booking_extras, true);
        if (empty($extras) || !is_array($extras)) {
            return '';
        }

        $mhbo_output = '';
        foreach ($extras as $extra) {
            $name = isset($extra['name']) ? I18n::decode($extra['name'], $lang) : '';
            $total = isset($extra['total']) ? I18n::format_currency($extra['total']) : '';

            if (empty($name)) {
                continue;
            }

            if ('html' === $format) {
                $mhbo_output .= '<li>' . esc_html($name) . ': ' . esc_html($total) . '</li>';
            } else {
                $mhbo_output .= ' - ' . $name . ': ' . $total . "\n";
            }
        }

        if ('html' === $format && !empty($mhbo_output)) {
            $mhbo_output = '<ul style="margin: 0; padding-left: 20px;">' . $mhbo_output . '</ul>';
        }

        return $mhbo_output;
    }

    /**
     * Get business payment details for booking emails.
     *
     * @param int   $booking_id
     * @param float $total_price
     * @return string HTML
     */
    private static function get_business_payment_details_html($booking_id, $total_price)
    {
        $mhbo_output  = '<div style="margin-top: 20px; padding: 15px; background: #fff3e0; border-radius: 5px; border: 1px solid #ffeccf;">';
        $mhbo_output .= '<h4 style="margin: 0 0 10px 0; color: #e65100;">' . esc_html(I18n::get_label('label_payment_info')) . '</h4>';
        $mhbo_output .= '<p style="margin: 5px 0;"><strong>' . esc_html(I18n::get_label('label_amount_due')) . '</strong> ' . I18n::format_currency($total_price) . '</p>';

        if (class_exists('MHBO\Business\Info')) {
            $banking = \MHBO\Business\Info::get_banking();
            $revolut = \MHBO\Business\Info::get_revolut();

            if (!empty($banking['enabled']) && !empty($banking['iban'])) {
                $mhbo_output .= '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ffd8a8;">';
                $mhbo_output .= '<h5 style="margin: 0 0 10px 0;">' . esc_html__('Bank Transfer Details', 'modern-hotel-booking') . '</h5>';
                $mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html__('Bank:', 'modern-hotel-booking') . '</strong> ' . esc_html($banking['bank_name']) . '</p>';
                $mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html__('IBAN:', 'modern-hotel-booking') . '</strong> <code style="background:#fff;padding:2px 5px;border:1px solid #ccc;">' . esc_html($banking['iban']) . '</code></p>';
                $mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html__('Reference:', 'modern-hotel-booking') . '</strong> ' . esc_html($banking['reference_prefix'] . $booking_id) . '</p>';
                $mhbo_output .= '</div>';
            }

            if (!empty($revolut['enabled']) && !empty($revolut['revolut_tag'])) {
                $mhbo_output .= '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ffd8a8;">';
                $mhbo_output .= '<h5 style="margin: 0 0 10px 0;">' . esc_html__('Revolut Payment', 'modern-hotel-booking') . '</h5>';
                $mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html__('Revtag:', 'modern-hotel-booking') . '</strong> <code style="background:#fff;padding:2px 5px;border:1px solid #ccc;">' . esc_html($revolut['revolut_tag']) . '</code></p>';
                if (!empty($revolut['revolut_link'])) {
                    $mhbo_output .= '<p style="margin: 5px 0;"><a href="' . esc_url($revolut['revolut_link']) . '" style="display:inline-block;background:#000;color:#fff;padding:5px 15px;text-decoration:none;border-radius:4px;font-size:0.85em;">' . esc_html__('Pay via Revolut.me', 'modern-hotel-booking') . '</a></p>';
                }
                $mhbo_output .= '</div>';
            }
        }

        $mhbo_output .= '</div>';
        return $mhbo_output;
    }

}
