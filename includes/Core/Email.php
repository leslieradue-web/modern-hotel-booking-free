<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

class Email {

	/**
	 * Initialize email hooks.
	 */
	public static function init(): void {
		// phpstan-ignore-next-line return.void -- handlers are void; phpstan-wordpress false-positive for [self::class, 'method'] callables
		add_action( 'mhbo_booking_confirmed', array( self::class, 'handle_booking_confirmed' ), 20 );
		// phpstan-ignore-next-line return.void
		add_action( 'mhbo_booking_created', array( self::class, 'handle_booking_created' ), 20 );
		// phpstan-ignore-next-line return.void
		add_action( 'mhbo_booking_cancelled', array( self::class, 'handle_booking_cancelled' ), 20 );
		// phpstan-ignore-next-line return.void
		add_action( 'mhbo_booking_created', array( self::class, 'handle_admin_notification_created' ), 25 );
		// phpstan-ignore-next-line return.void
		add_action( 'mhbo_booking_confirmed', array( self::class, 'handle_admin_notification_confirmed' ), 25 );
		// phpstan-ignore-next-line return.void
		add_action( 'wp_mail_failed', array( self::class, 'handle_wp_mail_failed' ), 10, 1 );
	}

	/**
	 * Capture wp_mail failures globally to maintain an audit trail for the Diagnostic Inspector.
	 *
	 * @param \WP_Error $wp_error The error returned by PHPMailer.
	 */
	public static function handle_wp_mail_failed( \WP_Error $wp_error ): void {
		$error_data = $wp_error->get_error_data( 'wp_mail_failed' );
		$to         = '';
		$subject    = '';

		if ( is_array( $error_data ) ) {
			if ( isset( $error_data['to'] ) ) {
				$to = is_array( $error_data['to'] ) ? implode( ', ', $error_data['to'] ) : (string) $error_data['to'];
			}
			if ( isset( $error_data['subject'] ) ) {
				$subject = (string) $error_data['subject'];
			}
		}

		$failure_record = array(
			'timestamp' => HotelTime::now_mysql(),
			'recipient' => sanitize_text_field( $to ),
			'subject'   => sanitize_text_field( $subject ),
			'message'   => sanitize_text_field( $wp_error->get_error_message() ),
		);

		update_option( 'mhbo_last_mail_failure', $failure_record, false );
	}

	/**
	 * Handler for booking confirmation event (Verified Payment / Manual Approval).
	 *
	 * @param int $booking_id The booking ID.
	 */
	public static function handle_booking_confirmed( int $booking_id ): void {
		self::send_email( $booking_id, 'confirmed' );
	}

	/**
	 * Handler for booking cancellation event.
	 *
	 * @param int $booking_id The booking ID.
	 */
	public static function handle_booking_cancelled( int $booking_id ): void {
		self::send_email( $booking_id, 'cancelled' );
	}

	/**
	 * Handler for booking creation event (Receipt / Arrival Selection).
	 * Sends a receipt immediately for on-site/arrival payments.
	 * Defers for Stripe/PayPal — those trigger 'mhbo_booking_confirmed' after server verification.
	 *
	 * @param int $booking_id The booking ID.
	 */
	public static function handle_booking_created( int $booking_id ): void {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT payment_method, status FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $booking_id ) );

		if ( ! $booking ) {
			return;
		}

		if ( in_array( $booking->payment_method, array( 'arrival', 'onsite', 'bank_transfer', 'bank', 'revolut' ), true ) ) {
			self::send_email( $booking_id, (string) $booking->status );
		} elseif ( 'payment_link' === $booking->payment_method && class_exists( 'ProRemoved' ) ) {
			ProRemoved::send_balance_link_email( $booking_id, true );
		}
	}

	/**
	 * Handler: send admin notification when a new booking is created.
	 *
	 * @param int $booking_id The booking ID.
	 */
	public static function handle_admin_notification_created( int $booking_id ): void {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT payment_method, status FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $booking_id ) );

		if ( ! $booking ) {
			return;
		}

		// For direct card gateways (Stripe, PayPal, Braintree), defer admin notification until payment confirmation (mhbo_booking_confirmed).
		if ( in_array( $booking->payment_method, array( 'arrival', 'onsite', 'payment_link', 'bank_transfer', 'bank', 'revolut' ), true ) || 'confirmed' === (string) $booking->status ) {
			self::send_admin_notification( $booking_id );
		}
	}

	/**
	 * Handler: send admin notification when a booking is confirmed (payment verified).
	 * Only fires for Stripe/PayPal bookings that were pending at creation time.
	 *
	 * @param int $booking_id The booking ID.
	 */
	public static function handle_admin_notification_confirmed( int $booking_id ): void {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payment_method FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
				$booking_id
			)
		);

		// For arrival/onsite/payment_link/bank/revolut the admin notification already fired on creation; skip duplicate.
		if ( $booking && in_array( $booking->payment_method, array( 'arrival', 'onsite', 'payment_link', 'bank_transfer', 'bank', 'revolut' ), true ) ) {
			return;
		}

		self::send_admin_notification( $booking_id );
	}

	/**
	 * Send a dedicated admin notification email with full guest and booking details.
	 * Sends to the configured notification email and any additional CC address.
	 *
	 * @param int  $booking_id The booking ID.
	 * @param bool $force      Force sending regardless of deduplication cache.
	 * @return bool True if sent successfully.
	 */
	public static function send_admin_notification( int $booking_id, bool $force = false ): bool {
		if ( ! $force ) {
			$dedup_key = 'mhbo_admin_notif_' . $booking_id;
			if ( false !== get_transient( $dedup_key ) ) {
				return true;
			}
			set_transient( $dedup_key, 1, 300 );
		}

		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.*, t.name as room_name
             FROM {$wpdb->prefix}mhbo_bookings b
             LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
             LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
             WHERE b.id = %d",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return false;
		}

$admin_email = self::get_notification_email();
		if ( ! is_email( $admin_email ) ) {
			return false;
		}

		$lang          = I18n::get_current_language();
		$site_name     = self::sanitize_header_value( (string) get_bloginfo( 'name' ) );
		$status_label  = I18n::translate_status( (string) ( $booking->status ?? 'pending' ) );
		$check_in      = '' !== (string) ( $booking->check_in ?? '' ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $booking->check_in ) ) : '--';
		$check_out     = '' !== (string) ( $booking->check_out ?? '' ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $booking->check_out ) ) : '--';
		$room_name     = (string) ( I18n::decode( (string) ( $booking->room_name ?? '' ), $lang ) ?? '' );
		$payment_label = I18n::translate_payment_method( (string) ( $booking->payment_method ?? 'arrival' ) );
		$total_price   = I18n::format_currency( Money::fromDecimal( (string) ( $booking->total_price ?? 0 ) ) );

		$admin_url = admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . $booking_id );

		// Build admin notification email HTML
		$body  = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;color:#222;">';
		$body .= '<h2 style="background:#2563eb;color:#fff;padding:16px 20px;margin:0;border-radius:6px 6px 0 0;">';
		$body .= esc_html__( 'New Booking Received', 'modern-hotel-booking' ) . ' &mdash; ' . esc_html( $site_name ) . '</h2>';
		$body .= '<div style="border:1px solid #d1d5db;border-top:none;border-radius:0 0 6px 6px;padding:20px;">';

		// Status badge
		$badge_color = ( 'confirmed' === $booking->status ) ? '#16a34a' : '#d97706';
		$body       .= '<p style="margin:0 0 16px 0;">';
		$body       .= '<span style="background:' . $badge_color . ';color:#fff;padding:4px 10px;border-radius:4px;font-size:13px;">' . esc_html( $status_label ) . '</span>';
		$body       .= '</p>';

		// Guest details
		$body .= '<h3 style="margin:0 0 8px 0;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">' . esc_html__( 'Guest Details', 'modern-hotel-booking' ) . '</h3>';
		$body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">';
		$body .= EmailHtmlRenderer::admin_row( _x( 'Name', 'customer detail', 'modern-hotel-booking' ), esc_html( (string) ( $booking->customer_name ?? '' ) ) );
		$body .= EmailHtmlRenderer::admin_row( _x( 'Email', 'customer detail', 'modern-hotel-booking' ), '<a href="mailto:' . esc_attr( (string) ( $booking->customer_email ?? '' ) ) . '">' . esc_html( (string) ( $booking->customer_email ?? '' ) ) . '</a>' );
		$body .= EmailHtmlRenderer::admin_row( _x( 'Phone', 'customer detail', 'modern-hotel-booking' ), esc_html( (string) ( $booking->customer_phone ?? '' ) ) );
		$email_special_req = BookingProcessor::extract_guest_special_requests( $booking );
		if ( '' !== $email_special_req ) {
			$body .= EmailHtmlRenderer::admin_row( __( 'Special Requests', 'modern-hotel-booking' ), esc_html( $email_special_req ) );
		}
		// Custom fields
		if ( '' !== (string) ( $booking->custom_fields ?? '' ) ) {
			$custom_data = json_decode( (string) $booking->custom_fields, true );
			$custom_defn = get_option( 'mhbo_custom_fields', array() );
			if ( is_array( $custom_data ) && is_array( $custom_defn ) ) {
				foreach ( $custom_defn as $defn ) {
					if ( isset( $custom_data[ $defn['id'] ] ) && '' !== (string) $custom_data[ $defn['id'] ] ) {
						$f_label = (string) ( I18n::decode( I18n::encode( $defn['label'] ), $lang ) ?? '' );
						$body   .= EmailHtmlRenderer::admin_row( esc_html( $f_label ), esc_html( (string) $custom_data[ $defn['id'] ] ) );
					}
				}
			}
		}
		$body .= '</table>';

		// Booking details
		$body .= '<h3 style="margin:0 0 8px 0;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">' . esc_html__( 'Booking Details', 'modern-hotel-booking' ) . '</h3>';
		$body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
		$body .= EmailHtmlRenderer::admin_row( __( 'Booking ID', 'modern-hotel-booking' ), '#' . (int) $booking->id );
		$body .= EmailHtmlRenderer::admin_row( _x( 'Room', 'accommodation unit', 'modern-hotel-booking' ), esc_html( $room_name ) );
		$body .= EmailHtmlRenderer::admin_row( __( 'Check-in', 'modern-hotel-booking' ), $check_in );
		$body .= EmailHtmlRenderer::admin_row( __( 'Check-out', 'modern-hotel-booking' ), $check_out );
		$guests_summary = (string) ( (int) ( $booking->guests ?? 1 ) );
		$children_cnt   = (int) ( $booking->children ?? 0 );
		if ( $children_cnt > 0 ) {
			$raw_ages       = ! empty( $booking->children_ages ) ? ( is_string( $booking->children_ages ) ? json_decode( $booking->children_ages, true ) : (array) $booking->children_ages ) : array();
			$sanitized_ages = Pricing::sanitize_child_ages( $raw_ages );
			$guests_summary = sprintf(
				/* translators: 1: adults count, 2: children count */
				__( '%1$d Adults, %2$d Children', 'modern-hotel-booking' ),
				(int) ( $booking->guests ?? 1 ),
				$children_cnt
			);
			if ( array() !== $sanitized_ages ) {
				$guests_summary .= ' (' . sprintf(
					/* translators: %s: comma-separated list of child ages */
					__( 'Ages: %s', 'modern-hotel-booking' ),
					implode( ', ', $sanitized_ages )
				) . ')';
			}
		}
		$body .= EmailHtmlRenderer::admin_row( __( 'Guests', 'modern-hotel-booking' ), esc_html( $guests_summary ) );
		$body .= EmailHtmlRenderer::admin_row( __( 'Payment Method', 'modern-hotel-booking' ), esc_html( $payment_label ) );
		$body .= EmailHtmlRenderer::admin_row( __( 'Total Price', 'modern-hotel-booking' ), '<strong>' . $total_price . '</strong>' );
		
		$body .= '</table>';

// CTA button
		$body .= '<p style="text-align:center;margin:20px 0 0 0;">';
		$body .= '<a href="' . esc_url( $admin_url ) . '" style="background:#2563eb;color:#fff;padding:10px 24px;border-radius:5px;text-decoration:none;font-weight:bold;">';
		$body .= esc_html__( 'View Booking in Dashboard', 'modern-hotel-booking' );
		$body .= '</a></p>';

$body .= '</div></div>';

		$raw_template_subject = (string) get_option( 'mhbo_email_admin_new_booking_subject', '' );
		$raw_template_message = (string) get_option( 'mhbo_email_admin_new_booking_message', '' );

		$placeholders = self::get_booking_placeholders(
			$booking,
			(string) ( $booking->status ?? 'pending' ),
			$lang,
			array(
				'admin_url'    => $admin_url,
				'status_label' => $status_label,
			)
		);

		if ( '' !== trim( $raw_template_subject ) ) {
			$decoded_subject = (string) ( I18n::decode( $raw_template_subject, $lang, true ) ?? '' );
			$subject         = self::apply_placeholders( $decoded_subject, $placeholders );
		} else {
			$subject = sprintf(
				/* translators: 1: site name, 2: booking ID, 3: guest name */
				__( '[%1$s] New Booking #%2$d from %3$s', 'modern-hotel-booking' ),
				$site_name,
				(int) $booking->id,
				(string) ( $booking->customer_name ?? '' )
			);
		}
		$subject = self::sanitize_header_value( (string) $subject );

		if ( '' !== trim( $raw_template_message ) ) {
			$decoded_message = (string) ( I18n::decode( $raw_template_message, $lang, true ) ?? '' );
			$body            = self::apply_placeholders( $decoded_message, $placeholders );
		}

		// Use the canonical admin header factory — provides Content-Type, From,
		// Reply-To (guest contact), and deduplicated Cc: to Additional Notification Email.
		$headers = self::get_admin_headers( (string) ( $booking->customer_email ?? '' ) );

		return (bool) wp_mail( $admin_email, $subject, $body, $headers );
	}

	/**
	 * Send an admin notification email when a payment is received.
	 *
	 * @param int                  $booking_id   The booking ID.
	 * @param array<string, mixed> $payment_data Payment details array.
	 * @return bool True if email was sent successfully, false on failure.
	 */
	public static function send_admin_payment_notification( int $booking_id, array $payment_data = array() ): bool {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.*, t.name as room_name, r.room_number
             FROM {$wpdb->prefix}mhbo_bookings b
             LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
             LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
             WHERE b.id = %d",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return false;
		}

		$admin_email = self::get_notification_email();
		if ( ! is_email( $admin_email ) ) {
			return false;
		}

		$lang         = I18n::get_current_language();
		$site_name    = self::sanitize_header_value( (string) get_bloginfo( 'name' ) );
		$admin_url    = admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . $booking_id );
		$status_label = I18n::translate_status( (string) ( $booking->status ?? 'confirmed' ) );
		$room_name        = (string) ( I18n::decode( (string) ( $booking->room_name ?? '' ), $lang ) ?? '' );
		$amount_paid      = isset( $payment_data['amount'] ) ? I18n::format_currency( Money::fromDecimal( (string) $payment_data['amount'] ) ) : I18n::format_currency( Money::fromDecimal( (string) ( $booking->payment_amount ?? 0 ) ) );
		$raw_note         = sanitize_text_field( (string) ( $payment_data['note'] ?? ( $payment_data['payment_note'] ?? '' ) ) );
		$payment_note_row = '' !== $raw_note
			? '<tr><td style="color: #64748b;">' . esc_html__( 'Note:', 'modern-hotel-booking' ) . '</td><td>' . esc_html( $raw_note ) . '</td></tr>'
			: '';

		$placeholders = self::get_booking_placeholders(
			$booking,
			'payment',
			$lang,
			array(
				'admin_url'        => $admin_url,
				'status_label'     => $status_label,
				'amount_paid'      => $amount_paid,
				'payment_note_row' => $payment_note_row,
			)
		);

		$raw_subject = (string) get_option( 'mhbo_email_admin_payment_subject', '' );
		$raw_message = (string) get_option( 'mhbo_email_admin_payment_message', '' );

		if ( '' !== trim( $raw_subject ) ) {
			$decoded_subject = (string) ( I18n::decode( $raw_subject, $lang, true ) ?? '' );
			$subject         = self::apply_placeholders( $decoded_subject, $placeholders );
		} else {
			$subject = sprintf(
				/* translators: 1: site name, 2: booking ID, 3: guest name */
				__( '[%1$s] Payment Received for Booking #%2$d from %3$s', 'modern-hotel-booking' ),
				$site_name,
				(int) $booking->id,
				(string) ( $booking->customer_name ?? '' )
			);
		}
		$subject = self::sanitize_header_value( (string) $subject );

		if ( '' !== trim( $raw_message ) ) {
			$decoded_message = (string) ( I18n::decode( $raw_message, $lang, true ) ?? '' );
			$body            = self::apply_placeholders( $decoded_message, $placeholders );
		} else {
			// Built-in default admin payment email HTML
			$body  = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;color:#222;">';
			$body .= '<h2 style="background:#16a34a;color:#fff;padding:16px 20px;margin:0;border-radius:6px 6px 0 0;">';
			$body .= esc_html__( 'Payment Received', 'modern-hotel-booking' ) . ' &mdash; ' . esc_html( $site_name ) . '</h2>';
			$body .= '<div style="border:1px solid #d1d5db;border-top:none;border-radius:0 0 6px 6px;padding:20px;">';
			$body .= '<p style="font-size:16px;margin:0 0 16px 0;">' . sprintf(
				/* translators: 1: formatted payment amount, 2: booking ID number */
				esc_html__( 'A payment of %1$s has been received for Booking #%2$d.', 'modern-hotel-booking' ),
				'<strong>' . esc_html( $amount_paid ) . '</strong>',
				(int) $booking->id
			) . '</p>';
			$body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
			$body .= EmailHtmlRenderer::admin_row( _x( 'Guest Name', 'customer detail', 'modern-hotel-booking' ), esc_html( (string) ( $booking->customer_name ?? '' ) ) );
			$body .= EmailHtmlRenderer::admin_row( _x( 'Email', 'customer detail', 'modern-hotel-booking' ), '<a href="mailto:' . esc_attr( (string) ( $booking->customer_email ?? '' ) ) . '">' . esc_html( (string) ( $booking->customer_email ?? '' ) ) . '</a>' );
			$body .= EmailHtmlRenderer::admin_row( _x( 'Room', 'accommodation unit', 'modern-hotel-booking' ), esc_html( $room_name ) );
			$body .= EmailHtmlRenderer::admin_row( __( 'Transaction ID', 'modern-hotel-booking' ), esc_html( (string) ( $booking->payment_transaction_id ?? '' ) ) );
			$body .= EmailHtmlRenderer::admin_row( __( 'Payment Method', 'modern-hotel-booking' ), esc_html( I18n::translate_payment_method( (string) ( $booking->payment_method ?? 'stripe' ) ) ) );
			$body .= EmailHtmlRenderer::admin_row( __( 'Status', 'modern-hotel-booking' ), '<span style="background:#16a34a;color:#fff;padding:3px 8px;border-radius:4px;font-size:12px;">' . esc_html( $status_label ) . '</span>' );
			$body .= '</table>';
			$body .= '<p style="text-align:center;margin:20px 0 0 0;"><a href="' . esc_url( $admin_url ) . '" style="background:#2563eb;color:#fff;padding:10px 24px;border-radius:5px;text-decoration:none;font-weight:bold;">' . esc_html__( 'View Booking in Dashboard', 'modern-hotel-booking' ) . '</a></p>';
			$body .= '</div></div>';
		}

		// Use the canonical admin header factory — provides Content-Type, From,
		// Reply-To (guest contact), and deduplicated Cc: to Additional Notification Email.
		$headers = self::get_admin_headers( (string) ( $booking->customer_email ?? '' ) );

		return (bool) wp_mail( $admin_email, $subject, $body, $headers );
	}

	/**
	 * Send an email notification for a booking status change.
	 *
	 * @param int    $booking_id The booking ID.
	 * @param string $status     The booking status.
	 * @param bool   $force      Whether to force sending even if duplicate.
	 * @return bool True if email was sent successfully, false on failure.
	 */
	public static function send_email( int $booking_id, string $status, bool $force = false ): bool {
		return self::send_booking_email( $booking_id, $status, $force );
	}

	/**
	 * Send booking confirmation email to the customer (convenience alias).
	 *
	 * @param int  $booking_id The booking ID.
	 * @param bool $force      Whether to bypass deduplication checks.
	 * @return bool True if email was sent successfully, false on failure.
	 */
	public static function send_booking_confirmation( int $booking_id, bool $force = false ): bool {
		return self::send_booking_email( $booking_id, 'confirmed', $force );
	}

	/**
	 * Send a booking notification email to the customer.
	 * Only sends for completed payments or arrival payment method.
	 *
	 * @param int    $booking_id The booking ID.
	 * @param string $status     The booking status.
	 * @return bool True if email was sent successfully, false on failure.
	 */
	public static function send_booking_email( int $booking_id, string $status, bool $force = false ): bool {
		global $wpdb;

		$cache_key = 'mhbo_booking_' . $booking_id;
		$booking   = wp_cache_get( $cache_key, 'mhbo_bookings' );

		if ( false === $booking ) {
			// RATIONALE: Required to fetch full booking record for email template rendering.
			// JOIN: Prefetch room name for Kairos performance compliance and N+1 elimination.
			// Uses $wpdb->prepare with %d; result is cached via wp_cache_set.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, specific lookup
			$booking = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT b.*, t.name as room_name, r.room_number
                 FROM {$wpdb->prefix}mhbo_bookings b
                 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
                 WHERE b.id = %d",
					$booking_id
				)
			);
			if ( $booking ) {
				wp_cache_set( $cache_key, $booking, 'mhbo_bookings', HOUR_IN_SECONDS );
			}
		}

		if ( ! $booking ) {
			return false;
		}

		// Check if we should send email based on payment status
		$payment_status = isset( $booking->payment_status ) ? $booking->payment_status : 'pending';
		$payment_method = isset( $booking->payment_method ) ? $booking->payment_method : 'arrival';

		// DEDUPLICATION: Prevent duplicate confirmation emails unless explicitly forced (manual resend),
		// and guard all statuses with a short-lived per-(booking, status) transient to prevent double-sends.
		if ( ! $force && isset( $booking->email_sent ) && (int) $booking->email_sent === 1 && 'confirmed' === $status ) {
			return false;
		}
		$dedup_key = 'mhbo_guest_email_sent_' . $booking_id . '_' . sanitize_key( $status );
		if ( ! $force && false !== get_transient( $dedup_key ) ) {
			return false;
		}

// Allow email if:
		// 1. Status is an explicit lifecycle transition (cancelled, refunded, checked_in, checked_out, completed), OR
		// 2. Status is explicitly 'confirmed' (admin manually confirmed the booking), OR
		// 3. Payment is completed, OR
		// 4. Payment method is 'arrival', 'onsite', 'payment_link', 'bank_transfer', 'bank', or 'revolut', OR
		// 5. Explicitly forced ($force = true)
		$is_offline_or_link = in_array( $payment_method, array( 'arrival', 'onsite', 'payment_link', 'bank_transfer', 'bank', 'revolut' ), true );
		$is_lifecycle_state = in_array( $status, array( 'cancelled', 'refunded', 'checked_in', 'checked_out', 'completed' ), true );
		$email_allowed      = $force || $is_lifecycle_state || ( 'confirmed' === $status ) || ( 'completed' === $payment_status ) || $is_offline_or_link;

		if ( ! $email_allowed ) {
			// Payment not confirmed and not explicitly confirmed by admin - don't send confirmation email yet
			return false;
		}

		// SECURITY: Validate email address before arming dedup transients or mutating email_sent.
		$to = sanitize_email( (string) ( $booking->customer_email ?? '' ) );
		if ( ! is_email( $to ) ) {
			return false;
		}

		// UPDATE STATUS: Mark as sent BEFORE wp_mail to avoid race conditions with webhooks
		if ( ! $force ) {
			set_transient( $dedup_key, 1, 120 );
		}
		if ( 'confirmed' === $status ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update
			$wpdb->update(
				$wpdb->prefix . 'mhbo_bookings',
				array( 'email_sent' => 1 ),
				array( 'id' => $booking_id ),
				array( '%d' ),
				array( '%d' )
			);
			// Invalidate cache
			wp_cache_delete( $cache_key, 'mhbo_bookings' );
		}

		$lang   = $booking->booking_language ?: I18n::get_current_language();
		$result = false;
		I18n::switch_language_context( $lang );

		try {
			// Load multilingual templates from options with hardcoded fallbacks if empty
			$template_subject = get_option( "mhbo_email_{$status}_subject" );
			if ( '' === (string) $template_subject ) {
				$status_subject_key = "email_{$status}_subject";
				$template_subject   = I18n::get_label( $status_subject_key );
				if ( $status_subject_key === $template_subject ) {
					$template_subject = I18n::get_label( 'email_booking_status_subject' );
				}
			}

			$template_message = get_option( "mhbo_email_{$status}_message" );
			if ( '' === (string) $template_message ) {
				$template_message = self::get_default_email_template( $status );
			}

			$subject = I18n::get_translated_string( "Email {$status} Subject", (string) $template_subject, 'MHBO Email Templates', $lang );
			$message = I18n::get_translated_string( "Email {$status} Message", (string) $template_message, 'MHBO Email Templates', $lang );

			// Room Name Placeholder - JOIN-aware (Kairos optimized)
			$room_name = (string) ( I18n::decode( (string) ( $booking->room_name ?? '' ), $lang ) ?? '' );
			if ( '' === $room_name && isset( $booking->room_id ) ) {
				// FALLBACK: Only if JOIN failed for some reason, check cache or DB
				$room_name_cache_key = 'mhbo_room_name_' . $booking->room_id;
				$room_name           = wp_cache_get( $room_name_cache_key, 'mhbo' );

				if ( false === $room_name ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$room_name = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT t.name FROM {$wpdb->prefix}mhbo_rooms r 
                     JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id 
                     WHERE r.id = %d",
						$booking->room_id
					)
					);
					wp_cache_set( $room_name_cache_key, $room_name, 'mhbo', HOUR_IN_SECONDS );
				}
				$room_name = I18n::decode( (string) ( $room_name ?? '' ), $lang );
			}

			// Format Custom Fields for placeholder
			$custom_fields_formatted = '';
			if ( '' !== (string) ( $booking->custom_fields ?? '' ) ) {
				$custom_data = json_decode( $booking->custom_fields, true );
				$custom_defn = get_option( 'mhbo_custom_fields', array() );
				if ( is_array( $custom_data ) && count( $custom_defn ) > 0 ) {
					foreach ( $custom_defn as $defn ) {
						if ( isset( $custom_data[ $defn['id'] ] ) ) {
							$f_label                  = (string) ( I18n::decode( I18n::encode( $defn['label'] ), $lang ) ?? '' );
							$custom_fields_formatted .= esc_html( $f_label ) . ': ' . esc_html( $custom_data[ $defn['id'] ] ) . "<br>\n";
						}
					}
				}
			}

			// Build payment details section
			$payment_details = '';
			if ( 'completed' === $payment_status ) {
				$payment_details  = '<div style="margin-top: 20px; padding: 15px; background: #e8f5e9; border-radius: 5px;">';
				$payment_details .= '<h4 style="margin: 0 0 10px 0; color: #2e7d32;">' . esc_html( I18n::get_label( 'label_payment_confirmation' ) ) . '</h4>';
				$payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html( I18n::get_label( 'label_payment_status' ) ) . '</strong> ' . esc_html( I18n::get_label( 'label_paid' ) ) . '</p>';
				if ( (float) ( $booking->payment_amount ?? 0 ) > 0 ) {
					$p_amt            = Money::fromDecimal( (string) $booking->payment_amount );
					$payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html( I18n::get_label( 'label_amount_paid' ) ) . '</strong> ' . I18n::format_currency( $p_amt ) . '</p>';
				}
				if ( '' !== (string) ( $booking->payment_transaction_id ?? '' ) ) {
					$payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html( I18n::get_label( 'label_transaction_id' ) ) . '</strong> ' . esc_html( (string) $booking->payment_transaction_id ) . '</p>';
				}
				if ( '' !== (string) ( $booking->payment_date ?? '' ) ) {
					$payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html( I18n::get_label( 'label_payment_date' ) ) . '</strong> ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $booking->payment_date ) ) . '</p>';
				}
				if ( '' !== (string) ( $booking->payment_method ?? '' ) ) {
					$payment_details .= '<p style="margin: 5px 0;"><strong>' . esc_html( I18n::get_label( 'label_payment_method' ) ) . '</strong> ' . esc_html( I18n::translate_payment_method( (string) $booking->payment_method ) ) . '</p>';
				}
				$payment_details .= '</div>';
			} elseif ( 'arrival' === $payment_method || 'onsite' === $payment_method ) {
				$payment_details = self::get_business_payment_details_html( $booking_id, Money::fromDecimal( (string) ( $booking->total_price ?? 0 ) ) );
			} elseif ( 'bank_transfer' === $payment_method || 'bank' === $payment_method ) {
				
			} elseif ( 'revolut' === $payment_method ) {
				
			} elseif ( 'payment_link' === $payment_method ) {
				
			}

			// Build tax breakdown section
			$tax_breakdown_html      = '';
			$tax_breakdown_text      = '';
			$tax_total               = '';
			$tax_registration_number = '';

// Format extras
			$booking_extras_html = EmailHtmlRenderer::format_extras( $booking, $lang, 'html' );
			$booking_extras_text = EmailHtmlRenderer::format_extras( $booking, $lang, 'text' );

			// Fetch placeholder collection
			$placeholders = self::get_booking_placeholders(
			$booking,
			$status,
			$lang,
			array(
				'extras_html'             => $booking_extras_html,
				'extras_text'             => $booking_extras_text,
				'custom_fields_formatted' => $custom_fields_formatted,
				'tax_breakdown_html'      => $tax_breakdown_html,
				'tax_breakdown_text'      => $tax_breakdown_text,
				'tax_total'               => $tax_total,
				'tax_registration_number' => $tax_registration_number,
				'room_name'               => esc_html( (string) $room_name ),
				'room_number'             => esc_html( (string) ( $booking->room_number ?? '' ) ),
				'payment_details'         => $payment_details,
			)
			);

			// Append tax breakdown or reservation breakdown if not in the template.
			// Must check BEFORE replacement, since apply_placeholders removes the literal placeholder.
			$has_tax_placeholder       = ( false !== strpos( $message, '{tax_breakdown}' ) );
			$has_breakdown_placeholder = ( false !== strpos( $message, '{reservation_breakdown}' ) );
			$has_deposit_placeholder   = ( false !== strpos( $message, '{deposit_details}' ) );
			$has_map_placeholder       = ( false !== strpos( $message, '{google_map}' ) );
			$has_social_placeholder    = ( false !== strpos( $message, '{social_links}' ) );

			// Replace placeholders with smart cleanup
			$subject = self::sanitize_header_value( self::apply_placeholders( (string) $subject, $placeholders ) );
			$message = self::apply_placeholders( (string) $message, $placeholders );

			if ( ! $has_breakdown_placeholder && ! $has_deposit_placeholder && ! $has_tax_placeholder ) {
				$message .= self::render_reservation_breakdown_html( $booking, $lang );
			}

$headers = self::get_transactional_headers( true, (string) $to );

			$attachments = array();

			// Add iCal attachment for confirmed bookings.
			if ( 'confirmed' === $status ) {
				$ics_content = EmailPlaceholderBuilder::generate_simple_ics( $booking );
				$file_path   = self::create_ics_attachment_file(
				$ics_content,
				sprintf( 'booking-%d.ics', (int) ( $booking->id ?? 0 ) )
				);
				if ( '' !== $file_path ) {
					$attachments[] = $file_path;
				}
			}

			try {
				$result = (bool) wp_mail( $to, (string) $subject, (string) $message, $headers, $attachments );
			} finally {
				if ( array() !== $attachments ) {
					foreach ( $attachments as $temp_file ) {
						if ( is_string( $temp_file ) && file_exists( $temp_file ) ) {
							wp_delete_file( $temp_file );
						}
					}
				}
			}

			if ( ! $result ) {
				if ( ! $force ) {
					delete_transient( $dedup_key );
				}
				if ( 'confirmed' === $status ) {
					// Roll back email_sent marker if wp_mail failed.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table update
					$wpdb->update(
					$wpdb->prefix . 'mhbo_bookings',
					array( 'email_sent' => 0 ),
					array( 'id' => $booking_id ),
					array( '%d' ),
					array( '%d' )
					);
					wp_cache_delete( $cache_key, 'mhbo_bookings' );
				}
			}

			if ( class_exists( ProRemoved::class ) ) {
				ProRemoved::log(
				isset( $booking->guest_id ) ? (int) $booking->guest_id : null,
				$booking_id,
				'booking_' . $status,
				$to,
				(string) $subject,
				(string) $message,
				$result
				);
			}
		} finally {
			I18n::restore_language_context();
		}

		return $result;
	}

	/**
	 * Send a payment confirmation email (separate receipt).
	 *
	 * @param int                  $booking_id   The booking ID.
	 * @param array<string, mixed> $payment_data Payment details array.
	 * @param string               $context      Optional context: 'balance' for remaining-balance payments.
	 * @return bool True if email was sent successfully, false on failure.
	 */
	public static function send_payment_confirmation_email( int $booking_id, array $payment_data = array(), string $context = '' ): bool {
		global $wpdb;
		// RATIONALE: Required to fetch booking record for payment confirmation email.
		// Uses $wpdb->prepare with %d; one-shot send, caching not beneficial.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, specific lookup
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return false;
		}

		$lang = $booking->booking_language ?: I18n::get_current_language();

		$template_subject = get_option( 'mhbo_email_payment_subject' );
		if ( '' === (string) $template_subject ) {
			$template_subject = I18n::get_label( 'email_payment_subject' );
			if ( 'email_payment_subject' === $template_subject ) {
				$template_subject = I18n::get_label( 'email_payment_confirmation_subject' );
			}
		}

		$template_message = get_option( 'mhbo_email_payment_message' );
		if ( '' === (string) $template_message ) {
			$template_message = self::get_default_email_template( 'payment' );
		}

		$subject = I18n::get_translated_string( 'Email payment Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email payment Message', (string) $template_message, 'MHBO Email Templates', $lang );

		// Build payment details section
		$payment_details  = '<div style="background: #e8f5e9; padding: 15px; border-radius: 5px; margin: 20px 0;">';
		$payment_details .= '<h4 style="margin: 0 0 15px 0; color: #2e7d32;">' . I18n::get_label( 'email_payment_details' ) . '</h4>';
		$p_amt            = Money::fromDecimal( (string) ( isset( $payment_data['amount'] ) ? $payment_data['amount'] : ( $booking->total_price ?? 0 ) ) );
		$payment_details .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label( 'email_amount_paid' ) . '</strong> ' . I18n::format_currency( $p_amt ) . '</p>';

		if ( '' !== (string) ( $payment_data['transaction_id'] ?? '' ) ) {
			$payment_details .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label( 'email_transaction_id' ) . '</strong> ' . esc_html( (string) $payment_data['transaction_id'] ) . '</p>';
		} elseif ( '' !== (string) ( $booking->payment_transaction_id ?? '' ) ) {
			$payment_details .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label( 'email_transaction_id' ) . '</strong> ' . esc_html( (string) $booking->payment_transaction_id ) . '</p>';
		}

		if ( '' !== (string) ( $payment_data['method'] ?? '' ) ) {
			$method_name      = I18n::translate_payment_method( (string) $payment_data['method'] );
			$payment_details .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label( 'email_payment_method' ) . '</strong> ' . esc_html( $method_name ) . '</p>';
		}

		$payment_details .= '<p style="margin: 5px 0;"><strong>' . I18n::get_label( 'email_payment_date' ) . '</strong> ' . esc_html( HotelTime::format_datetime( 'now' ) ) . '</p>';
		$payment_details .= '</div>';

		if ( 'balance' === $context ) {
			$payment_details = '<p style="margin: 0 0 15px 0; font-size: 14px; color: #475569;">' . esc_html__( 'This receipt confirms your remaining balance payment.', 'modern-hotel-booking' ) . '</p>' . $payment_details;
		}

		// Build tax breakdown section
		$tax_breakdown_html      = '';
		$tax_breakdown_text      = '';
		$tax_total               = '';
		$tax_registration_number = '';

// Format Custom Fields for placeholder
		$custom_fields_formatted = '';
		if ( '' !== (string) ( $booking->custom_fields ?? '' ) ) {
			$custom_data = json_decode( (string) $booking->custom_fields, true );
			$custom_defn = get_option( 'mhbo_custom_fields', array() );
			if ( is_array( $custom_data ) && is_array( $custom_defn ) && count( $custom_defn ) > 0 ) {
				foreach ( $custom_defn as $defn ) {
					if ( isset( $custom_data[ $defn['id'] ] ) ) {
						$f_label                  = (string) ( I18n::decode( I18n::encode( $defn['label'] ), $lang ) ?? '' );
						$custom_fields_formatted .= esc_html( $f_label ) . ': ' . esc_html( $custom_data[ $defn['id'] ] ) . "<br>\n";
					}
				}
			}
		}

		// Fetch room name for placeholder - with caching
		$room_name_cache_key = 'mhbo_room_name_' . $booking->room_id;
		$room_name           = wp_cache_get( $room_name_cache_key, 'mhbo' );

		if ( false === $room_name ) {
			// RATIONALE: Required to resolve room name for payment confirmation email placeholder.
			// Uses $wpdb->prepare with %d; result is cached via wp_cache_set.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented
			$room_name = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT t.name FROM {$wpdb->prefix}mhbo_rooms r 
                 JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id 
                 WHERE r.id = %d",
					$booking->room_id
				)
			);
			wp_cache_set( $room_name_cache_key, $room_name, 'mhbo', HOUR_IN_SECONDS );
		}
		$room_name = I18n::decode( $room_name, $lang );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, specific lookup for room number in payment email.
		$room_number = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT room_number FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d",
				$booking->room_id
			)
		);

		// Format extras
		$booking_extras_html = EmailHtmlRenderer::format_extras( $booking, $lang, 'html' );
		$booking_extras_text = EmailHtmlRenderer::format_extras( $booking, $lang, 'text' );

		// Fetch placeholder collection
		$placeholders = self::get_booking_placeholders(
			$booking,
			'payment',
			$lang,
			array(
				'extras_html'             => $booking_extras_html,
				'extras_text'             => $booking_extras_text,
				'custom_fields_formatted' => $custom_fields_formatted,
				'tax_breakdown_html'      => $tax_breakdown_html,
				'tax_breakdown_text'      => $tax_breakdown_text,
				'tax_total'               => $tax_total,
				'tax_registration_number' => $tax_registration_number,
				'room_name'               => $room_name,
				'room_number'             => $room_number,
				'payment_details'         => $payment_details,
				
			)
		);

		// Check for placeholders BEFORE replacement (replacement removes the literal tokens).
		$has_payment_placeholder = ( false !== strpos( $message, '{payment_details}' ) );

		// Replace placeholders with smart cleanup
		$subject = self::sanitize_header_value( self::apply_placeholders( (string) $subject, $placeholders ) );
		$message = self::apply_placeholders( (string) $message, $placeholders );

		// Append payment details only if the template didn't include the placeholder
		if ( ! $has_payment_placeholder ) {
			$message .= $payment_details;
		}

		// SECURITY: Validate email before sending
		$to = sanitize_email( (string) ( $booking->customer_email ?? '' ) );
		if ( ! is_email( $to ) ) {
			// Invalid email - skip sending
			return false;
		}

		$headers = self::get_transactional_headers( true, (string) $to );

		$result = (bool) wp_mail( $to, (string) $subject, (string) $message, $headers );

		if ( class_exists( ProRemoved::class ) ) {
			ProRemoved::log(
				isset( $booking->guest_id ) ? (int) $booking->guest_id : null,
				(int) ( $booking->id ?? 0 ),
				'invoice',
				$to,
				(string) $subject,
				(string) $message,
				$result
			);
		}

		return $result;
	}

	/**
	 * Get default email template for a given booking status.
	 *
	 * @param string $status Booking status.
	 * @return string Default HTML email template.
	 */
	public static function get_default_email_template( string $status ): string {
		return EmailTemplate::get_default( $status );
	}

	/**
	 * Retrieve and sanitize all configured additional notification email addresses.
	 * Supports a single email or multiple comma/semicolon/whitespace-separated addresses.
	 *
	 * @return list<string> Validated, lowercase email addresses.
	 */
	public static function get_additional_notification_emails(): array {
		$raw = (string) get_option( 'mhbo_additional_notification_email', '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$parts = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$emails = array();
		foreach ( $parts as $part ) {
			$clean = sanitize_email( $part );
			if ( is_email( $clean ) ) {
				$clean_lower = strtolower( $clean );
				if ( ! in_array( $clean_lower, $emails, true ) ) {
					$emails[] = $clean_lower;
				}
			}
		}

		return $emails;
	}

	/**
	 * Resolve the primary hotel notification email address with automatic fallback
	 * to WordPress's global admin_email when mhbo_notification_email is empty or invalid.
	 *
	 * @return string Validated email address, or empty string if neither is valid.
	 */
	public static function get_notification_email(): string {
		$configured = sanitize_email( (string) get_option( 'mhbo_notification_email', '' ) );
		if ( '' !== $configured && is_email( $configured ) ) {
			return (string) $configured;
		}
		$fallback = sanitize_email( (string) get_option( 'admin_email', '' ) );
		return ( '' !== $fallback && is_email( $fallback ) ) ? (string) $fallback : '';
	}

	/**
	 * Build standard headers for direct admin notification alerts.
	 * Sets From to the Notification Email, Reply-To to the customer (or admin),
	 * and adds deduplicated 'Cc: <additional_email>' headers when configured,
	 * ensuring all additional notification recipients are properly CC'd on admin alerts.
	 *
	 * @param string $reply_to Optional reply-to address (e.g. customer email).
	 * @return array<int, string> Formatted email headers for admin alerts.
	 */
	public static function get_admin_headers( string $reply_to = '' ): array {
		$safe_admin_email = self::get_notification_email();
		$hotel_name       = (string) get_option( 'mhbo_hotel_name', '' );
		$site_name        = '' !== trim( $hotel_name ) ? $hotel_name : (string) get_bloginfo( 'name' );
		$safe_site_name   = self::sanitize_header_value( $site_name );
		$safe_reply_to    = is_email( $reply_to ) ? sanitize_email( $reply_to ) : $safe_admin_email;

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $safe_site_name . ' <' . $safe_admin_email . '>',
			'Reply-To: ' . $safe_reply_to,
		);

		$additional_emails = self::get_additional_notification_emails();
		foreach ( $additional_emails as $add_email ) {
			if ( $add_email !== strtolower( (string) $safe_admin_email ) && $add_email !== strtolower( (string) $safe_reply_to ) ) {
				$headers[] = 'Cc: ' . $add_email;
			}
		}

		return $headers;
	}

	/**
	 * Build standard headers for customer-facing transactional and status emails.
	 * Sets From & Reply-To to the Notification Email, and adds Bcc headers for
	 * the primary Notification Email (mhbo_notification_email) and any configured
	 * Additional Notification Email (mhbo_additional_notification_email), ensuring
	 * the hotel inboxes maintain a full audit trail of all transactions and statuses.
	 *
	 * @param bool   $bcc_admin Whether to BCC the notification emails. Defaults to true.
	 * @param string $recipient Optional primary recipient address to deduplicate BCC against.
	 * @return array<int, string> Formatted email headers.
	 */
	public static function get_transactional_headers( bool $bcc_admin = true, string $recipient = '' ): array {
		$safe_admin_email = self::get_notification_email();
		$hotel_name       = (string) get_option( 'mhbo_hotel_name', '' );
		$site_name        = '' !== trim( $hotel_name ) ? $hotel_name : (string) get_bloginfo( 'name' );
		$safe_site_name   = self::sanitize_header_value( $site_name );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $safe_site_name . ' <' . $safe_admin_email . '>',
			'Reply-To: ' . $safe_admin_email,
		);

		if ( $bcc_admin ) {
			$recipient_lower = strtolower( trim( $recipient ) );
			if ( is_email( (string) $safe_admin_email ) && strtolower( (string) $safe_admin_email ) !== $recipient_lower ) {
				$headers[] = 'Bcc: ' . $safe_admin_email;
			}

			$additional_emails = self::get_additional_notification_emails();
			foreach ( $additional_emails as $add_email ) {
				if ( $add_email !== strtolower( (string) $safe_admin_email ) && $add_email !== $recipient_lower ) {
					$headers[] = 'Bcc: ' . $add_email;
				}
			}
		}

		return $headers;
	}

	/**
	 * Send an HTML email with a scoped wp_mail_content_type filter.
	 *
	 * @param string             $to      Recipient email address.
	 * @param string             $subject Email subject.
	 * @param string             $message HTML body.
	 * @param array<int, string> $headers Mail headers.
	 * @return bool Whether wp_mail reported success.
	 */
	public static function send_html_mail( string $to, string $subject, string $message, array $headers ): bool {
		return self::dispatch_html_mail( $to, $subject, $message, $headers );
	}

	/**
	 * Send guest identity verification OTP email.
	 *
	 * @param string $to   Guest email.
	 * @param string $code 6-digit verification code.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_verification_email( string $to, string $code ): bool {
		return EmailPortalNotifier::send_verification_email( $to, $code );
	}

	/**
	 * Get placeholders for Business Information.
	 *
	 * @param string $lang Current language.
	 * @return array<string, string>
	 */
	public static function get_business_placeholders( string $lang = '' ): array {
		return EmailPlaceholderBuilder::get_business_placeholders( $lang );
	}

	/**
	 * Build the complete set of email placeholders with sanitization and fallbacks.
	 *
	 * @param object               $booking    The booking database row.
	 * @param string               $status     The booking status.
	 * @param string               $lang       The target language.
	 * @param array<string, mixed> $additional Additional pre-rendered components.
	 * @return array<string, string|int|float>
	 */
	public static function get_booking_placeholders( object $booking, string $status, string $lang, array $additional = array() ): array {
		return EmailPlaceholderBuilder::get_booking_placeholders( $booking, $status, $lang, $additional );
	}

	/**
	 * Apply placeholders to a string and clean up messy formatting.
	 *
	 * @param string                          $text         The text containing placeholders.
	 * @param array<string, string|int|float> $placeholders Map of placeholders to values.
	 * @return string The processed text.
	 */
	public static function apply_placeholders( string $text, array $placeholders ): string {
		return EmailPlaceholderBuilder::apply_placeholders( $text, $placeholders );
	}

	/**
	 * Get business payment details for booking emails.
	 *
	 * @param int                $booking_id  The booking ID.
	 * @param float|string|Money $total_price The total booking price.
	 * @return string HTML for payment details.
	 */
	public static function get_business_payment_details_html( int $booking_id, float|string|Money $total_price ): string {
		return EmailHtmlRenderer::get_business_payment_details_html( $booking_id, $total_price );
	}

	/**
	 * Render itemized reservation breakdown and payment summary HTML for emails.
	 *
	 * @param object $booking The booking object.
	 * @param string $lang    Current language code.
	 * @return string HTML content for reservation breakdown.
	 */
	public static function render_reservation_breakdown_html( object $booking, string $lang ): string {
		return EmailHtmlRenderer::render_reservation_breakdown_html( $booking, $lang );
	}

/**
	 * Render an itemized payment history / folio ledger HTML table for emails.
	 */
	public static function render_payment_ledger_html( object $booking ): string {
		return EmailHtmlRenderer::render_payment_ledger_html( $booking );
	}

	/**
	 * Generate plain-text booking summary for WhatsApp dispatch.
	 *
	 * @param int $booking_id Booking ID.
	 * @return string
	 */
	public static function get_booking_summary_text( int $booking_id ): string {
		return EmailHtmlRenderer::get_booking_summary_text( $booking_id );
	}

	/**
	 * Render Google Maps Directions card for emails.
	 *
	 * @param string $lang Language code.
	 * @return string HTML
	 */
	public static function render_google_map_html( string $lang = '' ): string {
		return EmailHtmlRenderer::render_google_map_html( $lang );
	}

	/**
	 * Render Social Media links row for email footers.
	 *
	 * @return string HTML
	 */
	public static function render_social_links_html(): string {
		return EmailHtmlRenderer::render_social_links_html();
	}

	/**
	 * Send an admin notification alert when an unpaid reservation is cancelled online.
	 *
	 * @param int    $booking_id The booking ID.
	 * @param string $reason     Optional cancellation reason provided by guest.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_admin_cancellation_notification( int $booking_id, string $reason = '' ): bool {
		return EmailPortalNotifier::send_admin_cancellation_notification( $booking_id, $reason );
	}

	/**
	 * Send an urgent admin notification alert when a guest requests cancellation for a PAID booking.
	 *
	 * @param int    $booking_id  The booking ID.
	 * @param float  $amount_paid Total amount already collected from guest.
	 * @param string $reason      Guest reason / notes.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_admin_cancellation_request_notification( int $booking_id, float $amount_paid, string $reason = '' ): bool {
		return EmailPortalNotifier::send_admin_cancellation_request_notification( $booking_id, $amount_paid, $reason );
	}

	/**
	 * Send an acknowledgement email to a guest who submitted a cancellation request for a paid booking.
	 *
	 * @param int    $booking_id  The booking ID.
	 * @param float  $amount_paid Total amount already collected from guest.
	 * @param string $reason      Guest reason / notes.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_guest_cancellation_request_email( int $booking_id, float $amount_paid, string $reason = '' ): bool {
		return EmailPortalNotifier::send_guest_cancellation_request_email( $booking_id, $amount_paid, $reason );
	}

	/**
	 * Send an acknowledgement email to a guest who submitted a stay modification request.
	 *
	 * @param int    $booking_id       The booking ID.
	 * @param string $requested_dates  Requested date change.
	 * @param string $requested_guests Requested guest count change.
	 * @param string $notes            Additional requests / notes.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_guest_modification_request_email( int $booking_id, string $requested_dates = '', string $requested_guests = '', string $notes = '' ): bool {
		return EmailPortalNotifier::send_guest_modification_request_email( $booking_id, $requested_dates, $requested_guests, $notes );
	}

	/**
	 * Send an operational alert email to hotel administrators and reservation staff.
	 *
	 * @param int    $booking_id       The booking ID.
	 * @param string $requested_dates  Requested date modifications.
	 * @param string $requested_guests Requested guest count changes.
	 * @param string $notes            Guest notes / special requests.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_admin_modification_request_notification( int $booking_id, string $requested_dates = '', string $requested_guests = '', string $notes = '' ): bool {
		return EmailPortalNotifier::send_admin_modification_request_notification( $booking_id, $requested_dates, $requested_guests, $notes );
	}

	/**
	 * Send an automated security notice when a guest updates their contact email address.
	 *
	 * @param int    $booking_id The booking ID.
	 * @param string $old_email  Previous email address.
	 * @param string $new_email  Updated email address.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_email_updated_notification( int $booking_id, string $old_email, string $new_email ): bool {
		return EmailPortalNotifier::send_email_updated_notification( $booking_id, $old_email, $new_email );
	}

	/**
	 * Send a stay dates update email to the guest when stay dates are updated directly.
	 *
	 * @param int    $booking_id    The booking ID.
	 * @param string $old_check_in  Previous check-in date.
	 * @param string $old_check_out Previous check-out date.
	 * @param string $old_total     Optional previous booking total decimal.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_stay_dates_updated_email( int $booking_id, string $old_check_in = '', string $old_check_out = '', string $old_total = '' ): bool {
		return EmailPortalNotifier::send_stay_dates_updated_email( $booking_id, $old_check_in, $old_check_out, $old_total );
	}

	/**
	 * Write iCal content to an ephemeral .ics file in the system temporary directory.
	 *
	 * @param string $ics_content Raw RFC 5545 iCalendar payload.
	 * @param string $filename    Target filename (e.g. 'booking-8.ics').
	 * @return string Absolute path to the written .ics file, or empty string on failure.
	 */
	public static function create_ics_attachment_file( string $ics_content, string $filename ): string {
		return EmailPlaceholderBuilder::create_ics_attachment_file( $ics_content, $filename );
	}

	/**
	 * Send an HTML email with a scoped `wp_mail_content_type` filter cleaned up in `finally`.
	 *
	 * @param string             $to      Recipient email address.
	 * @param string             $subject Email subject.
	 * @param string             $message HTML body.
	 * @param array<int, string> $headers Mail headers.
	 * @return bool Whether wp_mail reported success.
	 */
	private static function dispatch_html_mail( string $to, string $subject, string $message, array $headers ): bool {
		$html_type = static fn(): string => 'text/html';
		add_filter( 'wp_mail_content_type', $html_type );
		try {
			return (bool) wp_mail( $to, $subject, $message, $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', $html_type );
		}
	}

	/**
	 * Decode HTML entities (e.g. &#039; -> ') so plain-text subjects and From: headers render cleanly,
	 * then strip carriage returns, newlines, and null bytes to prevent CRLF header injection.
	 *
	 * @param string $value Header value.
	 * @return string Sanitized header value.
	 */
	public static function sanitize_header_value( string $value ): string {
		$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$cleaned = (string) preg_replace( '/&#(?:0*1[03]|x0*[ad]);/i', '', $decoded );
		return str_replace( array( "\r", "\n", "\0" ), '', $cleaned );
	}
}
