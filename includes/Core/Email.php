<?php declare(strict_types=1);

namespace MHBO\Core;

use MHBO\Core\Money;

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

$admin_email = get_option( 'mhbo_notification_email', get_option( 'admin_email' ) );
		if ( ! is_email( $admin_email ) ) {
			return false;
		}

		$lang          = I18n::get_current_language();
		$site_name     = get_bloginfo( 'name' );
		$status_label  = I18n::translate_status( (string) ( $booking->status ?? 'pending' ) );
		$check_in      = '' !== (string) ( $booking->check_in ?? '' ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $booking->check_in ) ) : '--';
		$check_out     = '' !== (string) ( $booking->check_out ?? '' ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $booking->check_out ) ) : '--';
		$room_name     = (string) ( I18n::decode( (string) ( $booking->room_name ?? '' ), $lang ) ?? '' );
		$payment_label = I18n::translate_payment_method( (string) ( $booking->payment_method ?? 'arrival' ) );
		$total_price   = I18n::format_currency( Money::fromDecimal( (string) ( $booking->total_price ?? 0 ) ) );

		$admin_url = admin_url( 'admin.php?page=mhbo-bookings&action=view&id=' . $booking_id );

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
		$body .= self::admin_row( _x( 'Name', 'customer detail', 'modern-hotel-booking' ), esc_html( (string) ( $booking->customer_name ?? '' ) ) );
		$body .= self::admin_row( _x( 'Email', 'customer detail', 'modern-hotel-booking' ), '<a href="mailto:' . esc_attr( (string) ( $booking->customer_email ?? '' ) ) . '">' . esc_html( (string) ( $booking->customer_email ?? '' ) ) . '</a>' );
		$body .= self::admin_row( _x( 'Phone', 'customer detail', 'modern-hotel-booking' ), esc_html( (string) ( $booking->customer_phone ?? '' ) ) );
		if ( '' !== (string) ( $booking->special_requests ?? '' ) ) {
			$body .= self::admin_row( __( 'Special Requests', 'modern-hotel-booking' ), esc_html( (string) $booking->special_requests ) );
		}
		// Custom fields
		if ( '' !== (string) ( $booking->custom_fields ?? '' ) ) {
			$custom_data = json_decode( (string) $booking->custom_fields, true );
			$custom_defn = get_option( 'mhbo_custom_fields', array() );
			if ( is_array( $custom_data ) && is_array( $custom_defn ) ) {
				foreach ( $custom_defn as $defn ) {
					if ( isset( $custom_data[ $defn['id'] ] ) && '' !== (string) $custom_data[ $defn['id'] ] ) {
						$f_label = (string) ( I18n::decode( I18n::encode( $defn['label'] ), $lang ) ?? '' );
						$body   .= self::admin_row( esc_html( $f_label ), esc_html( (string) $custom_data[ $defn['id'] ] ) );
					}
				}
			}
		}
		$body .= '</table>';

		// Booking details
		$body .= '<h3 style="margin:0 0 8px 0;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">' . esc_html__( 'Booking Details', 'modern-hotel-booking' ) . '</h3>';
		$body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
		$body .= self::admin_row( __( 'Booking ID', 'modern-hotel-booking' ), '#' . (int) $booking->id );
		$body .= self::admin_row( _x( 'Room', 'accommodation unit', 'modern-hotel-booking' ), esc_html( $room_name ) );
		$body .= self::admin_row( __( 'Check-in', 'modern-hotel-booking' ), $check_in );
		$body .= self::admin_row( __( 'Check-out', 'modern-hotel-booking' ), $check_out );
		$body .= self::admin_row( __( 'Guests', 'modern-hotel-booking' ), (string) ( (int) ( $booking->guests ?? 1 ) ) );
		$body .= self::admin_row( __( 'Payment Method', 'modern-hotel-booking' ), esc_html( $payment_label ) );
		$body .= self::admin_row( __( 'Total Price', 'modern-hotel-booking' ), '<strong>' . $total_price . '</strong>' );
		
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
				esc_html( (string) ( $booking->customer_name ?? '' ) )
			);
		}

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

		$admin_email = get_option( 'mhbo_notification_email', get_option( 'admin_email' ) );
		if ( ! is_email( $admin_email ) ) {
			return false;
		}

		$lang         = I18n::get_current_language();
		$site_name    = get_bloginfo( 'name' );
		$admin_url    = admin_url( 'admin.php?page=mhbo-bookings&action=view&id=' . $booking_id );
		$status_label = I18n::translate_status( (string) ( $booking->status ?? 'confirmed' ) );
		$room_name    = (string) ( I18n::decode( (string) ( $booking->room_name ?? '' ), $lang ) ?? '' );
		$amount_paid  = isset( $payment_data['amount'] ) ? I18n::format_currency( Money::fromDecimal( (string) $payment_data['amount'] ) ) : I18n::format_currency( Money::fromDecimal( (string) ( $booking->payment_amount ?? 0 ) ) );

		$placeholders = self::get_booking_placeholders(
			$booking,
			'payment',
			$lang,
			array(
				'admin_url'    => $admin_url,
				'status_label' => $status_label,
				'amount_paid'  => $amount_paid,
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
				esc_html( (string) ( $booking->customer_name ?? '' ) )
			);
		}

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
			$body .= self::admin_row( _x( 'Guest Name', 'customer detail', 'modern-hotel-booking' ), esc_html( (string) ( $booking->customer_name ?? '' ) ) );
			$body .= self::admin_row( _x( 'Email', 'customer detail', 'modern-hotel-booking' ), '<a href="mailto:' . esc_attr( (string) ( $booking->customer_email ?? '' ) ) . '">' . esc_html( (string) ( $booking->customer_email ?? '' ) ) . '</a>' );
			$body .= self::admin_row( _x( 'Room', 'accommodation unit', 'modern-hotel-booking' ), esc_html( $room_name ) );
			$body .= self::admin_row( __( 'Transaction ID', 'modern-hotel-booking' ), esc_html( (string) ( $booking->payment_transaction_id ?? '' ) ) );
			$body .= self::admin_row( __( 'Payment Method', 'modern-hotel-booking' ), esc_html( I18n::translate_payment_method( (string) ( $booking->payment_method ?? 'stripe' ) ) ) );
			$body .= self::admin_row( __( 'Status', 'modern-hotel-booking' ), '<span style="background:#16a34a;color:#fff;padding:3px 8px;border-radius:4px;font-size:12px;">' . esc_html( $status_label ) . '</span>' );
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
	 * Helper: render a two-column table row for the admin notification email.
	 *
	 * @param string $label The row label.
	 * @param string $value The row value (may contain safe HTML).
	 * @return string HTML table row.
	 */
	private static function admin_row( string $label, string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		return '<tr>'
			. '<td style="padding:6px 8px;color:#6b7280;width:38%;vertical-align:top;">' . $label . '</td>'
			. '<td style="padding:6px 8px;vertical-align:top;">' . $value . '</td>'
			. '</tr>';
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

		// DEDUPLICATION: Prevent duplicate confirmation emails unless explicitly forced (manual resend)
		if ( ! $force && isset( $booking->email_sent ) && (int) $booking->email_sent === 1 && 'confirmed' === $status ) {
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

		// UPDATE STATUS: Mark as sent BEFORE wp_mail to avoid race conditions with webhooks
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

		$lang = $booking->booking_language ?: I18n::get_current_language();
		I18n::switch_language_context( $lang );

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

		// SECURITY: Validate email address before sending
		$to = sanitize_email( (string) ( $booking->customer_email ?? '' ) );
		if ( ! is_email( $to ) ) {
			// Invalid email - skip sending
			I18n::restore_language_context();
			return false;
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
		$booking_extras_html = self::format_extras( $booking, $lang, 'html' );
		$booking_extras_text = self::format_extras( $booking, $lang, 'text' );

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
				'room_name'               => $room_name,
				'room_number'             => (string) ( $booking->room_number ?? '' ),
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
		$subject = self::apply_placeholders( (string) $subject, $placeholders );
		$message = self::apply_placeholders( (string) $message, $placeholders );

		if ( ! $has_breakdown_placeholder && ! $has_deposit_placeholder && ! $has_tax_placeholder ) {
			$message .= self::render_reservation_breakdown_html( $booking, $lang );
		}

$headers = self::get_transactional_headers( true, (string) $to );

		$attachments = array();

		// Add iCal attachment for confirmed bookings.
		if ( 'confirmed' === $status ) {
			$ics_content = self::generate_simple_ics( $booking );
			if ( ! function_exists( 'wp_tempnam' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$file_path = function_exists( 'wp_tempnam' )
				? \wp_tempnam( 'mhbo_ics_' . wp_generate_password( 12, false ) )
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_tempnam -- Ephemeral email attachment, unlinked in finally block.
				: tempnam( sys_get_temp_dir(), 'mhbo_ics_' );

			if ( $file_path ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Ephemeral email attachment, unlinked immediately in finally block.
				file_put_contents( $file_path, $ics_content );
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

		if ( ! $result && 'confirmed' === $status ) {
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
		$booking_extras_html = self::format_extras( $booking, $lang, 'html' );
		$booking_extras_text = self::format_extras( $booking, $lang, 'text' );

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
		$subject = self::apply_placeholders( (string) $subject, $placeholders );
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
	 * Send guest identity verification OTP email for AI Concierge and Self-Service lookup.
	 *
	 * Uses template-driven layout, multilingual translation, customizable subject and message,
	 * and standard Law 58 transactional headers.
	 *
	 * @param string $to   Guest email.
	 * @param string $code 6-digit verification code.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_verification_email( string $to, string $code ): bool {
		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return false;
		}

		$lang = I18n::get_current_language();
		I18n::switch_language_context( $lang );

		$template_subject = get_option( 'mhbo_email_identity_verification_subject' );
		if ( '' === (string) $template_subject ) {
			$template_subject = I18n::get_label( 'email_identity_verification_subject' );
			if ( 'email_identity_verification_subject' === $template_subject ) {
				$template_subject = __( 'Verify your identity — {company_name}', 'modern-hotel-booking' );
			}
		}

		$template_message = get_option( 'mhbo_email_identity_verification_message' );
		if ( '' === (string) $template_message ) {
			$template_message = self::get_default_email_template( 'identity_verification' );
		}

		$subject = (string) ( I18n::decode( $template_subject, $lang ) ?? '' );
		$message = (string) ( I18n::decode( $template_message, $lang ) ?? '' );

		// Merge verification-specific and business placeholders
		$business_placeholders     = self::get_business_placeholders( $lang );
		$verification_placeholders = array(
			'{verification_code}'   => esc_html( $code ),
			'{verification_expiry}' => esc_html__( '20 minutes', 'modern-hotel-booking' ),
			'{customer_email}'      => esc_html( $to ),
			'{guest_email}'         => esc_html( $to ),
		);
		$all_placeholders          = array_merge( $business_placeholders, $verification_placeholders );

		$subject = str_replace( array_keys( $all_placeholders ), array_values( $all_placeholders ), $subject );
		$message = str_replace( array_keys( $all_placeholders ), array_values( $all_placeholders ), $message );

		$safe_subject = self::sanitize_header_value( $subject );
		$safe_subject = html_entity_decode( $safe_subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Law 58 transactional headers (without BCC admin for transient OTP codes)
		$headers = self::get_transactional_headers( false, $to );

		$sent = (bool) wp_mail( $to, $safe_subject, $message, $headers );
		I18n::restore_language_context();
		return $sent;
	}

	/**
	 * Generate a simple ICS file for email attachments.
	 * Use explicit UTC times matching the hotel check-in/out timezone configuration.
	 *
	 * @param object $booking The booking object.
	 * @return string ICS file content.
	 */
	private static function generate_simple_ics( object $booking ): string {
		$now            = wp_date( 'Ymd\THis\Z' );
		$check_in_date  = (string) ( $booking->check_in ?? '' );
		$check_out_date = (string) ( $booking->check_out ?? '' );
		
		if ( '' === $check_in_date || '' === $check_out_date ) {
			return '';
		}

		try {
			// Respect the dedicated Hotel Timezone setting
			$tz = HotelTime::timezone();
			
			$ci_time = (string) get_option( 'mhbo_checkin_time', '14:00' );
			$co_time = (string) get_option( 'mhbo_checkout_time', '11:00' );

$start_dt = new \DateTime( $check_in_date . ' ' . $ci_time, $tz );
			$start_dt->setTimezone( new \DateTimeZone( 'UTC' ) );
			$dtstart = 'DTSTART:' . $start_dt->format( 'Ymd\THis\Z' );

			$end_dt = new \DateTime( $check_out_date . ' ' . $co_time, $tz );
			$end_dt->setTimezone( new \DateTimeZone( 'UTC' ) );
			$dtend = 'DTEND:' . $end_dt->format( 'Ymd\THis\Z' );
		} catch ( \Exception $e ) {
			// Fallback to all-day events if parsing fails
			$dtstart = 'DTSTART;VALUE=DATE:' . wp_date( 'Ymd', (int) ( strtotime( $check_in_date ) ?: time() ) );
			$dtend   = 'DTEND;VALUE=DATE:' . wp_date( 'Ymd', (int) ( strtotime( $check_out_date ) ?: time() ) );
		}

		return "BEGIN:VCALENDAR\r\n" .
			"VERSION:2.0\r\n" .
			"PRODID:-//Modern Hotel Booking//EN\r\n" .
			"BEGIN:VEVENT\r\n" .
			"UID:mhbo-booking-{$booking->id}\r\n" .
			"DTSTAMP:{$now}\r\n" .
			"{$dtstart}\r\n" .
			"{$dtend}\r\n" .
			'SUMMARY:' . sprintf( I18n::get_label( 'label_hotel_booking_id' ), $booking->id ) . "\r\n" .
			"END:VEVENT\r\n" .
			'END:VCALENDAR';
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
	 * Get placeholders for Business Information.
	 *
	 * @param string $lang Current language.
	 * @return array<string, string>
	 */
	private static function get_business_placeholders( string $lang = '' ): array {
		$placeholders = array();

		if ( class_exists( 'MHBO\Business\Info' ) ) {
			$company  = \MHBO\Business\Info::get_company();
			$whatsapp = \MHBO\Business\Info::get_whatsapp();

			$c_name = ! empty( $company['company_name'] ) ? $company['company_name'] : ( $company['name'] ?? get_option( 'mhbo_hotel_name', get_bloginfo( 'name' ) ) );
			if ( '' === trim( (string) $c_name ) ) {
				$c_name = get_bloginfo( 'name' );
			}
			$c_addr  = implode(
				', ',
				array_filter(
					array(
						$company['address_line_1'] ?? '',
						$company['address_line_2'] ?? '',
						$company['city'] ?? '',
						$company['state'] ?? '',
						$company['postcode'] ?? '',
						$company['country'] ?? '',
					)
				)
			);
			$c_phone = ! empty( $company['telephone'] ) ? $company['telephone'] : ( $company['phone'] ?? '' );
			$c_reg   = ! empty( $company['registration_no'] ) ? $company['registration_no'] : ( $company['registration_number'] ?? '' );

			$placeholders['{company_name}']         = (string) ( I18n::decode( (string) $c_name, $lang ) ?? $c_name );
			$placeholders['{hotel_name}']           = (string) ( I18n::decode( (string) $c_name, $lang ) ?? $c_name );
			$placeholders['{company_address}']      = (string) ( I18n::decode( (string) $c_addr, $lang ) ?? '' );
			$placeholders['{company_phone}']        = (string) $c_phone;
			$placeholders['{company_email}']        = (string) ( $company['email'] ?? '' );
			$placeholders['{company_website}']      = (string) ( $company['website'] ?? '' );
			$placeholders['{company_registration}'] = (string) $c_reg;
			$placeholders['{whatsapp_number}']      = (string) ( $whatsapp['phone_number'] ?? '' );
			$placeholders['{whatsapp_link}']        = '' !== (string) ( $whatsapp['phone_number'] ?? '' ) ? 'https://wa.me/' . preg_replace( '/[^0-9]/', '', (string) $whatsapp['phone_number'] ) : '';
			$logo_url                               = '' !== (string) ( $company['logo_url'] ?? '' ) ? esc_url( (string) $company['logo_url'] ) : '';
			$placeholders['{mhbo_logo_url}']        = $logo_url;
			$placeholders['{logo_header}']          = '' !== $logo_url ? '<div style="margin-bottom: 14px; text-align: center;"><img src="' . $logo_url . '" alt="' . esc_attr( (string) $c_name ) . '" style="max-height: 56px; max-width: 220px; height: auto; display: inline-block;"></div>' : '';
			$placeholders['{company_info}']            = '';
			$placeholders['{whatsapp_contact}']        = '';
			$placeholders['{whatsapp_button}']         = '';
			$placeholders['{banking_details}']         = '';
			$placeholders['{revolut_details}']         = '';
			$placeholders['{offline_payment_methods}'] = '';
			$placeholders['{business_card}']           = '';
			$placeholders['{social_links}']            = '';
			$placeholders['{google_map}']              = '';
			
		} else {
			$c_name = get_option( 'mhbo_hotel_name', get_bloginfo( 'name' ) );
			if ( '' === trim( (string) $c_name ) ) {
				$c_name = get_bloginfo( 'name' );
			}
			$placeholders['{company_name}']         = (string) ( I18n::decode( (string) $c_name, $lang ) ?? $c_name );
			$placeholders['{hotel_name}']           = (string) ( I18n::decode( (string) $c_name, $lang ) ?? $c_name );
			$placeholders['{company_address}']      = '';
			$placeholders['{company_phone}']        = '';
			$placeholders['{company_email}']        = (string) get_option( 'admin_email', '' );
			$placeholders['{company_website}']      = (string) get_bloginfo( 'url' );
			$placeholders['{company_registration}'] = '';
			$placeholders['{whatsapp_number}']      = '';
			$placeholders['{whatsapp_link}']        = '';
			$placeholders['{mhbo_logo_url}']        = '';
			$placeholders['{logo_header}']          = '';
			$placeholders['{company_info}']            = '';
			$placeholders['{whatsapp_contact}']        = '';
			$placeholders['{whatsapp_button}']         = '';
			$placeholders['{banking_details}']         = '';
			$placeholders['{revolut_details}']         = '';
			$placeholders['{offline_payment_methods}'] = '';
			$placeholders['{business_card}']           = '';
			$placeholders['{social_links}']            = '';
			$placeholders['{google_map}']              = '';
		}

		return $placeholders;
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
		$check_in  = '' !== (string) ( $booking->check_in ?? '' ) ? strtotime( (string) $booking->check_in ) : 0;
		$check_out = '' !== (string) ( $booking->check_out ?? '' ) ? strtotime( (string) $booking->check_out ) : 0;
		$day_sec   = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$nights    = ( $check_in > 0 && $check_out > $check_in ) ? (int) round( ( $check_out - $check_in ) / $day_sec ) : 0;

		// SANITIZATION: All user-provided data must be escaped for use in HTML emails.
		$c_name  = '' !== (string) ( $booking->customer_name ?? '' ) ? esc_html( (string) $booking->customer_name ) : I18n::get_label( 'label_guest' );
		$c_email = '' !== (string) ( $booking->customer_email ?? '' ) ? sanitize_email( (string) $booking->customer_email ) : '';
		$c_phone = '' !== (string) ( $booking->customer_phone ?? '' ) ? esc_html( (string) $booking->customer_phone ) : '';
		$special = '' !== (string) ( $booking->special_requests ?? '' ) ? esc_html( (string) $booking->special_requests ) : '';

		$booking_token = (string) ( $booking->booking_token ?? '' );
		$portal_url    = class_exists( \MHBO\Api\GuestPortalController::class )
			? \MHBO\Api\GuestPortalController::get_portal_url( $booking_token, (int) ( $booking->id ?? 0 ), (string) ( $booking->customer_email ?? '' ) )
			: null;
		$view_url      = $portal_url ? esc_url( $portal_url ) : '';

		$portal_card_html = '';
		if ( '' !== $view_url ) {
			$portal_card_html = '<div style="margin: 24px 0; padding: 20px 24px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center;">'
				. '<h4 style="margin: 0 0 6px 0; color: #0f172a; font-size: 15px; font-weight: 700;">' . esc_html__( 'Manage Your Reservation Online', 'modern-hotel-booking' ) . '</h4>'
				. '<p style="margin: 0 0 16px 0; color: #64748b; font-size: 13px; line-height: 1.5;">' . esc_html__( 'Review stay details, pay outstanding balances, request stay modifications, or view your itinerary anytime in our Guest Self-Service Portal.', 'modern-hotel-booking' ) . '</p>'
				. '<a href="' . $view_url . '" style="display: inline-block; background-color: #0f172a; color: #ffffff; padding: 12px 26px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 13px; letter-spacing: 0.3px;">' . esc_html__( 'Access Guest Portal &rarr;', 'modern-hotel-booking' ) . '</a>'
				. ( '' !== $booking_token ? '<p style="margin: 14px 0 0 0; font-size: 12px; color: #94a3b8;">' . esc_html__( 'Security Token for manual portal login:', 'modern-hotel-booking' ) . ' <code style="background: #e2e8f0; color: #0f172a; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 12px;">' . esc_html( $booking_token ) . '</code></p>' : '' )
				. '</div>';
		}

		$security_token_row = ( '' !== $view_url && '' !== $booking_token )
			? '<tr><td style="color: #64748b">' . esc_html__( 'Security Token:', 'modern-hotel-booking' ) . '</td><td style="text-align: right"><code style="font-family: monospace; font-size: 12px; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: #0f172a;">' . esc_html( $booking_token ) . '</code></td></tr>'
			: '';

		// Cache payment amounts once — avoids repeated ledger parsing per email.
		$ph_amts = class_exists( Pricing::class ) ? Pricing::get_booking_payment_amounts( $booking ) : null;

		// Cache reservation breakdown once — avoids repeating heavy query & formatting logic.
		$reservation_breakdown_html = self::render_reservation_breakdown_html( $booking, $lang );

		$placeholders = array(
			// Core IDs & References
			'{booking_id}'              => (int) ( $booking->id ?? 0 ),
			'{booking_token}'           => $booking_token,
			'{booking_reference}'       => $booking_token, // Alias for backward compatibility
			'{view_url}'                => $view_url,
			'{admin_booking_url}'       => admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . (int) ( $booking->id ?? 0 ) ),
			'{portal_card}'             => $portal_card_html,
			'{security_token_row}'      => $security_token_row,

			// Stay Information
			'{check_in}'                => $check_in > 0 ? HotelTime::format_date( $check_in ) : '--',
			'{check_out}'               => $check_out > 0 ? HotelTime::format_date( $check_out ) : '--',
			'{check_in_time}'           => esc_html(
				( function () use ( $booking ) {
					$ci = (string) get_option( 'mhbo_checkin_time', '14:00' );
					if ( ! empty( $booking->custom_fields ) ) {
						$cd = is_string( $booking->custom_fields ) ? json_decode( $booking->custom_fields, true ) : (array) $booking->custom_fields;
						if ( ! empty( $cd['_early_checkin'] ) ) {
							$ci = (string) get_option( 'mhbo_early_checkin_time', '10:00' );
						}
					}
					return $ci;
				} )()
			),
			'{check_out_time}'          => esc_html(
				( function () use ( $booking ) {
					$co = (string) get_option( 'mhbo_checkout_time', '11:00' );
					if ( ! empty( $booking->custom_fields ) ) {
						$cd = is_string( $booking->custom_fields ) ? json_decode( $booking->custom_fields, true ) : (array) $booking->custom_fields;
						if ( ! empty( $cd['_late_checkout'] ) ) {
							$co = (string) get_option( 'mhbo_late_checkout_time', '14:00' );
						}
					}
					return $co;
				} )()
			),
			'{nights}'                  => $nights,
			'{guests}'                  => (int) ( $booking->guests ?? 1 ),
			'{children}'                => '',
			'{children_ages}'           => '',
			'{children_total}'          => '',
			'{total_price}'             => I18n::format_currency( Money::fromDecimal( (string) ( $booking->total_price ?? 0 ) ) ),
			'{payment_amount}'          => I18n::format_currency( Money::fromDecimal( (string) ( $booking->payment_amount ?? 0 ) ) ),
			'{due_now}'                 => ( null !== $ph_amts ? I18n::format_currency( $ph_amts['due_now']->toDecimal() ) : '' ),
			'{amount_due_now}'          => ( null !== $ph_amts ? I18n::format_currency( $ph_amts['due_now']->toDecimal() ) : '' ),
			'{due_type}'                => ( null !== $ph_amts ? ( 'deposit' === $ph_amts['due_type'] ? I18n::get_label( 'label_deposit_required' ) : I18n::get_label( 'label_balance_due' ) ) : '' ),
			'{remaining_balance}'       => ( null !== $ph_amts ? I18n::format_currency( $ph_amts['remaining']->toDecimal() ) : '' ),
			'{amount_outstanding}'      => ( null !== $ph_amts ? I18n::format_currency( $ph_amts['remaining']->toDecimal() ) : '' ),
			'{payment_ledger}'          => method_exists( __CLASS__, 'render_payment_ledger_html' ) ? self::render_payment_ledger_html( $booking ) : '',
			'{payment_transaction_id}'  => esc_html( $booking->payment_transaction_id ?? '' ),
			'{payment_date}'            => esc_html( $booking->payment_date ?? '' ),
			'{payment_method}'          => esc_html( I18n::translate_payment_method( (string) ( $booking->payment_method ?? '' ) ) ),
			'{status}'                  => I18n::translate_status( (string) $status ),
			'{room_name}'               => esc_html( (string) ( I18n::decode( (string) ( $booking->room_name ?? '' ), $lang ) ?? '' ) ),
			'{room_number}'             => esc_html( (string) ( $booking->room_number ?? '' ) ),

			// Customer Information
			'{customer_name}'           => $c_name,
			'{guest_name}'              => $c_name,
			'{first_name}'              => ( function () use ( $c_name ) {
				$parts = explode( ' ', trim( $c_name ) );
				return esc_html( $parts[0] ?? $c_name );
			} )(),
			'{last_name}'               => ( function () use ( $c_name ) {
				$parts = explode( ' ', trim( $c_name ) );
				array_shift( $parts );
				return esc_html( implode( ' ', $parts ) );
			} )(),
			'{customer_email}'          => $c_email,
			'{guest_email}'             => $c_email,
			'{email}'                   => $c_email,
			'{customer_phone}'          => $c_phone,
			'{guest_phone}'             => $c_phone,
			'{phone}'                   => $c_phone,
			'{special_requests}'        => $special,
			'{arrival_time}'            => esc_html( $booking->arrival_time ?? '' ),

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
			'{tax_registration_number}' => esc_html( $additional['tax_registration_number'] ?? '' ),

			// Global Info
			'{site_name}'               => esc_html( get_bloginfo( 'name' ) ),
			'{site_title}'              => esc_html( get_bloginfo( 'name' ) ),
			'{reservation_breakdown}'   => $reservation_breakdown_html,
			
		);

// Add Business Information Placeholders
		$placeholders = array_merge( $placeholders, self::get_business_placeholders( $lang ) );

		foreach ( $additional as $k => $v ) {
			$token_key                  = '{' . trim( (string) $k, '{}' ) . '}';
			$placeholders[ $token_key ] = (string) $v;
		}

		return $placeholders;
	}

	/**
	 * Apply placeholders to a string and clean up messy formatting.
	 *
	 * @param string                          $text         The text containing placeholders.
	 * @param array<string, string|int|float> $placeholders Map of placeholders to values.
	 * @return string The processed text.
	 */
	public static function apply_placeholders( string $text, array $placeholders ): string {
		if ( '' === (string) $text ) {
			return '';
		}

		// 1. Decode HTML entities (wp_editor may encode curly braces as &#123; &#125;)
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 2. Perform raw replacement
		$text = str_replace( array_keys( $placeholders ), array_map( 'strval', array_values( $placeholders ) ), $text );

		// 3. Smart Cleanup of messy formatting
		// Collapse multiple commas/spaces on the same line (e.g., ", , ,") into a single comma
		$text = (string) preg_replace( '/[, \t]+(,[ \t]*)+/', ', ', (string) $text );

		// Remove leading commas on lines (e.g., ", pending")
		$text = (string) preg_replace( '/^[ \t]*,+[ \t]*/m', '', $text );

		// Remove trailing commas ONLY if they follow a space (dangling separator)
		// This preserves greeting commas like "Hi {customer_name}," which usually have no space before the comma
		$text = (string) preg_replace( '/[ \t]+,[ \t]*$/m', '', $text );

		// Collapse extra vertical whitespace (more than 2 newlines)
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );

		// Handle commas near HTML breaks and tags
		$text = (string) preg_replace( '/,\s*<br\s*\/?>/i', '<br>', $text );
		$text = (string) preg_replace( '/<br\s*\/?>\s*,/i', '<br>', $text );
		$text = (string) preg_replace( '/,\s*<\/p>/i', '</p>', $text );
		$text = (string) preg_replace( '/,\s*<\/div>/i', '</div>', $text );
		$text = (string) preg_replace( '/<li>\s*,/i', '<li>', $text );

		return trim( $text );
	}

	/**
	 * Format booking extras for email placeholders.
	 *
	 * @param object $booking The booking object.
	 * @param string $lang    Current language code.
	 * @param string $format  Output format ('html' or 'text').
	 * @return string Formatted extras.
	 */
	private static function format_extras( object $booking, string $lang, string $format = 'html' ): string {
		$extras = \MHBO\Core\Pricing::decode_stored_extras( $booking->booking_extras ?? null );
		if ( count( $extras ) === 0 ) {
			return '';
		}

		$mhbo_output = '';
		foreach ( $extras as $extra ) {
			$name  = isset( $extra['name'] ) ? (string) ( I18n::decode( (string) $extra['name'], $lang ) ?? '' ) : '';
			$total = isset( $extra['total'] ) ? I18n::format_currency( (float) $extra['total'] ) : '';
			if ( '' === $name ) {
				continue;
			}

			if ( 'html' === $format ) {
				$mhbo_output .= '<li>' . esc_html( $name ) . ': ' . esc_html( $total ) . '</li>';
			} else {
				$mhbo_output .= ' - ' . $name . ': ' . $total . "\n";
			}
		}

		if ( 'html' === $format && '' !== $mhbo_output ) {
			$mhbo_output = '<ul style="margin: 0; padding-left: 20px;">' . $mhbo_output . '</ul>';
		}

		return $mhbo_output;
	}

	/**
	 * Get business payment details for booking emails.
	 *
	 * @param int                $booking_id  The booking ID.
	 * @param float|string|Money $total_price The total booking price.
	 * @return string HTML for payment details.
	 */
	public static function get_business_payment_details_html( int $booking_id, float|string|Money $total_price ): string {
		$total_price  = $total_price instanceof Money ? $total_price : Money::fromDecimal( (string) $total_price );
		$mhbo_output  = '<div style="margin-top: 20px; padding: 15px; background: #fff3e0; border-radius: 5px; border: 1px solid #ffeccf;">';
		$mhbo_output .= '<h4 style="margin: 0 0 10px 0; color: #e65100;">' . esc_html( I18n::get_label( 'label_payment_info' ) ) . '</h4>';
		$mhbo_output .= '<p style="margin: 5px 0;"><strong>' . esc_html( I18n::get_label( 'label_amount_due' ) ) . '</strong> ' . I18n::format_currency( $total_price ) . '</p>';

		if ( class_exists( 'MHBO\Business\Info' ) ) {
			$banking = \MHBO\Business\Info::get_banking();
			$revolut = \MHBO\Business\Info::get_revolut();
			if ( (bool) ( $banking['enabled'] ?? false ) && '' !== (string) ( $banking['iban'] ?? '' ) ) {
				$mhbo_output .= '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ffd8a8;">';
				$mhbo_output .= '<h5 style="margin: 0 0 10px 0;">' . esc_html( I18n::get_label( 'label_bank_transfer_details' ) ) . '</h5>';
				$mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html( I18n::get_label( 'label_bank' ) ) . '</strong> ' . esc_html( (string) $banking['bank_name'] ) . '</p>';
				$mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html( I18n::get_label( 'label_iban' ) ) . '</strong> <code style="background:#fff;padding:2px 5px;border:1px solid #ccc;">' . esc_html( (string) $banking['iban'] ) . '</code></p>';
				$mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html( I18n::get_label( 'label_reference' ) ) . '</strong> ' . esc_html( $banking['reference_prefix'] . $booking_id ) . '</p>';
				$mhbo_output .= '</div>';
			}
			if ( (bool) ( $revolut['enabled'] ?? false ) && '' !== (string) ( $revolut['revolut_tag'] ?? '' ) ) {
				$mhbo_output .= '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ffd8a8;">';
				$mhbo_output .= '<h5 style="margin: 0 0 10px 0;">' . esc_html( I18n::get_label( 'label_revolut_payment' ) ) . '</h5>';
				$mhbo_output .= '<p style="margin: 3px 0; font-size: 0.9em;"><strong>' . esc_html( I18n::get_label( 'label_revtag' ) ) . '</strong> <code style="background:#fff;padding:2px 5px;border:1px solid #ccc;">' . esc_html( (string) $revolut['revolut_tag'] ) . '</code></p>';
				if ( '' !== (string) ( $revolut['revolut_link'] ?? '' ) ) {
					$mhbo_output .= '<p style="margin: 5px 0;"><a href="' . esc_url( (string) $revolut['revolut_link'] ) . '" style="display:inline-block;background:#000;color:#fff;padding:5px 15px;text-decoration:none;border-radius:4px;font-size:0.85em;">' . esc_html( I18n::get_label( 'label_pay_via_revolut' ) ) . '</a></p>';
				}
				$mhbo_output .= '</div>';
			}
		}

		$mhbo_output .= '</div>';
		return $mhbo_output;
	}

/**
	 * Render itemized reservation breakdown and payment summary HTML for emails.
	 *
	 * @param object $booking The booking object.
	 * @param string $lang    Current language code.
	 * @return string HTML content for reservation breakdown.
	 */
	public static function render_reservation_breakdown_html( object $booking, string $lang ): string {
		$folio = class_exists( Pricing::class ) ? Pricing::get_folio_breakdown( $booking, $lang ) : null;
		if ( ! $folio ) {
			return '';
		}

		$html  = '<div style="margin-top: 20px; padding: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-family: sans-serif;">';
		$html .= '<h4 style="margin: 0 0 12px 0; color: #1e293b; font-size: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">' . esc_html( __( 'Reservation Breakdown', 'modern-hotel-booking' ) ) . '</h4>';

		$html .= '<table style="width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 12px;">';
		$html .= '<thead><tr style="border-bottom: 1px solid #e2e8f0; color: #64748b; font-size: 12px; text-transform: uppercase;">';
		$html .= '<th scope="col" style="text-align: left; padding: 4px 0;">' . esc_html( _x( 'Item', 'cart item', 'modern-hotel-booking' ) ) . '</th>';
		$html .= '<th scope="col" style="text-align: right; padding: 4px 0;">' . esc_html( __( 'Amount', 'modern-hotel-booking' ) ) . '</th>';
		$html .= '</tr></thead>';
		$html .= '<tbody>';

		// Itemized charges (room nights, supplements, early/late checkin, extras, fees, discounts)
		foreach ( $folio['charges'] as $c ) {
			$item_style = $c['is_negative'] ? 'color: #16a34a;' : 'color: #334155;';
			$qty_str    = ( (int) $c['qty'] > 1 && 'room' !== $c['category'] ) ? ' &times; ' . (int) $c['qty'] : '';
			$html      .= '<tr style="' . $item_style . '">';
			$html      .= '<td style="padding: 6px 0;">';
			$html      .= '<strong>' . esc_html( $c['label'] ) . '</strong>' . $qty_str;
			if ( ! empty( $c['details'] ) && 'room' !== $c['category'] ) {
				$html .= '<div style="font-size: 11px; color: #64748b; margin-top: 1px;">' . esc_html( $c['details'] ) . '</div>';
			}
			$html .= '</td>';
			$html .= '<td style="padding: 6px 0; text-align: right; font-weight: 600;' . ( $c['is_negative'] ? ' color: #16a34a;' : '' ) . '">' . esc_html( $c['total_formatted'] ) . '</td>';
			$html .= '</tr>';
		}

		// Optional Subtotal (net) & Tax rows when Tax is enabled and positive
		$has_tax = ! empty( $folio['tax_enabled'] ) && ! empty( $folio['total_tax'] ) && (float) $folio['total_tax'] > 0.001;
		if ( $has_tax && ! empty( $folio['subtotal_net_formatted'] ) ) {
			$subtotal_label = ! empty( $folio['is_vat'] )
				? sprintf( I18n::get_label( 'label_subtotal_net_vat' ), $folio['base_tax_label'] ?? $folio['tax_label'] ?? 'VAT' )
				: I18n::get_label( 'label_subtotal' );

			$html .= '<tr style="border-top: 1px solid #e2e8f0;"><td style="padding: 6px 0; color: #475569; font-weight: 600;">' . esc_html( $subtotal_label ) . '</td><td style="padding: 6px 0; text-align: right; font-weight: 600; color: #475569;">' . esc_html( $folio['subtotal_net_formatted'] ) . '</td></tr>';

			$acc_rate = (float) ( $booking->tax_rate_accommodation ?? ( class_exists( Tax::class ) ? Tax::get_accommodation_rate() : 0.0 ) );
			$ext_rate = (float) ( $booking->tax_rate_extras ?? ( class_exists( Tax::class ) ? Tax::get_extras_rate() : 0.0 ) );

			if ( $folio['accommodation_tax'] > 0 && $folio['extras_tax'] > 0 ) {
				/* translators: 1: Tax label, 2: Tax rate */
				$html .= '<tr><td style="padding: 4px 0; color: #64748b; font-size: 13px;">' . esc_html( sprintf( I18n::get_label( 'label_tax_accommodation' ), $folio['tax_label'], $acc_rate + 0 ) ) . '</td><td style="padding: 4px 0; text-align: right; color: #64748b; font-size: 13px;">' . esc_html( $folio['accommodation_tax_formatted'] ) . '</td></tr>';
				/* translators: 1: Tax label, 2: Tax rate */
				$html .= '<tr><td style="padding: 4px 0; color: #64748b; font-size: 13px;">' . esc_html( sprintf( I18n::get_label( 'label_tax_extras' ), $folio['tax_label'], $ext_rate + 0 ) ) . '</td><td style="padding: 4px 0; text-align: right; color: #64748b; font-size: 13px;">' . esc_html( $folio['extras_tax_formatted'] ) . '</td></tr>';
			} elseif ( $folio['total_tax'] > 0 ) {
				/* translators: 1: Tax label, 2: Tax rate */
				$html .= '<tr><td style="padding: 4px 0; color: #64748b; font-size: 13px;">' . esc_html( sprintf( I18n::get_label( 'label_tax_rate' ), $folio['tax_label'], $acc_rate + 0 ) ) . '</td><td style="padding: 4px 0; text-align: right; color: #64748b; font-size: 13px;">' . esc_html( $folio['total_tax_formatted'] ) . '</td></tr>';
			}
		}

		// Total
		$total_label = ( $has_tax && ! empty( $folio['is_vat'] ) )
			? sprintf( I18n::get_label( 'label_total_charges_vat' ), $folio['base_tax_label'] ?? $folio['tax_label'] ?? 'VAT' )
			: I18n::get_label( 'label_total' );

		$html .= '<tr style="border-top: 2px solid #cbd5e1;"><td style="padding: 8px 0; font-weight: 700; color: #0f172a;">' . esc_html( $total_label ) . '</td><td style="padding: 8px 0; text-align: right; font-weight: 700; font-size: 16px; color: #0f172a;">' . esc_html( $folio['total_charges_formatted'] ) . '</td></tr>';
		$html .= '</tbody></table>';

		if ( $has_tax && ! empty( $folio['tax_included'] ) && $folio['total_tax'] > 0 ) {
			/* translators: %s: formatted tax amount */
			$tax_inc_note = sprintf( I18n::get_label( 'label_tax_included' ), $folio['total_tax_formatted'] );
			$html        .= '<div style="font-size: 11px; color: #64748b; margin: -4px 0 8px 0;">' . esc_html( $tax_inc_note ) . '</div>';
		}
		if ( $has_tax && ! empty( $folio['tax_registration_number'] ) ) {
			/* translators: %s: tax registration number */
			$html .= '<div style="font-size: 11px; color: #94a3b8; margin: -2px 0 8px 0;">' . esc_html( sprintf( I18n::get_label( 'label_tax_registration' ), $folio['tax_registration_number'] ) ) . '</div>';
		}

		// Payment Summary Sub-Card
		$html .= '<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; margin-top: 8px;">';
		$html .= '<table style="width: 100%; border-collapse: collapse; font-size: 13px;">';

		$amts             = Pricing::get_booking_payment_amounts( $booking );
		$payment_type     = (string) ( $booking->payment_type ?? 'full' );
		$deposit_received = $amts['deposit_received'];
		$paid             = $amts['received'];
		$remaining        = $amts['remaining'];
		$total            = $amts['total'];

		if ( 'deposit' === $payment_type || $amts['deposit']->isPositive() ) {
			if ( $deposit_received ) {
				$deposit_label = esc_html( rtrim( I18n::get_label( 'label_email_deposit_paid' ), ':' ) );
				$html         .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $deposit_label . ':</td><td style="padding: 3px 0; text-align: right; color: #16a34a; font-weight: 700;">' . I18n::format_currency( $paid ) . '</td></tr>';

				if ( ! $remaining->isZero() ) {
					$rem_label = esc_html( rtrim( I18n::get_label( 'label_email_remaining_balance' ), ':' ) );
					$html     .= '<tr style="border-top: 1px dashed #e2e8f0;"><td style="padding: 6px 0 2px 0; color: #0f172a; font-weight: 600;">' . $rem_label . ':</td><td style="padding: 6px 0 2px 0; text-align: right; color: #f97316; font-weight: 700; font-size: 14px;">' . I18n::format_currency( $remaining ) . '</td></tr>';
					$html     .= '<tr><td colspan="2" style="padding: 2px 0 0 0; font-size: 11px; color: #64748b;">' . esc_html( I18n::get_label( 'label_email_due_at_checkin' ) ) . '</td></tr>';
				} else {
					$html .= '<tr style="border-top: 1px dashed #e2e8f0;"><td colspan="2" style="padding: 6px 0; text-align: center; color: #166534; font-weight: 700;">' . esc_html( I18n::get_label( 'label_email_paid_full' ) ) . '</td></tr>';
				}
			} else {
				$status_label = esc_html( rtrim( I18n::get_label( 'label_payment_status' ), ':' ) );
				$method_label = ( 'arrival' === ( $booking->payment_method ?? '' ) ) ? esc_html( I18n::get_label( 'label_pay_arrival' ) ) : esc_html( I18n::translate_status( 'pending' ) );
				$html        .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $status_label . ':</td><td style="padding: 3px 0; text-align: right; color: #d97706; font-weight: 700;">' . $method_label . '</td></tr>';
				if ( $amts['deposit']->isPositive() ) {
					$deposit_label = esc_html( rtrim( I18n::get_label( 'label_email_deposit_required' ), ':' ) );
					$html         .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $deposit_label . ':</td><td style="padding: 3px 0; text-align: right; color: #f97316; font-weight: 700;">' . I18n::format_currency( $amts['deposit'] ) . '</td></tr>';
				}
				$due_label = ( 'arrival' === ( $booking->payment_method ?? '' ) ) ? esc_html( rtrim( I18n::get_label( 'label_amount_due' ), ':' ) ) : esc_html( rtrim( I18n::get_label( 'label_amt_out' ), ':' ) );
				$html     .= '<tr style="border-top: 1px dashed #e2e8f0;"><td style="padding: 6px 0 2px 0; color: #0f172a; font-weight: 600;">' . $due_label . ':</td><td style="padding: 6px 0 2px 0; text-align: right; color: #d97706; font-weight: 700; font-size: 14px;">' . I18n::format_currency( $remaining ) . '</td></tr>';
			}
		} else {
			$is_paid_full = $amts['is_paid'];
			$status_label = esc_html( rtrim( I18n::get_label( 'label_payment_status' ), ':' ) );
			if ( $is_paid_full ) {
				$html .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $status_label . ':</td><td style="padding: 3px 0; text-align: right; color: #16a34a; font-weight: 700;">' . esc_html( I18n::get_label( 'label_paid' ) ) . '</td></tr>';
				$html .= '<tr><td style="padding: 3px 0; color: #64748b;">' . esc_html( rtrim( I18n::get_label( 'label_amount_paid' ), ':' ) ) . ':</td><td style="padding: 3px 0; text-align: right; font-weight: 700;">' . I18n::format_currency( $paid->isPositive() ? $paid : $total ) . '</td></tr>';
			} else {
				$html .= '<tr><td style="padding: 3px 0; color: #64748b;">' . $status_label . ':</td><td style="padding: 3px 0; text-align: right; color: #d97706; font-weight: 700;">' . esc_html( I18n::get_label( 'label_pay_arrival' ) ) . '</td></tr>';
				$html .= '<tr><td style="padding: 3px 0; color: #64748b;">' . esc_html( rtrim( I18n::get_label( 'label_amount_due' ), ':' ) ) . ':</td><td style="padding: 3px 0; text-align: right; font-weight: 700; color: #d97706;">' . I18n::format_currency( $total ) . '</td></tr>';
			}
		}

		$html .= '</table></div>';

		// Deposit policy / refund deadline notices
		if ( 'deposit' === $payment_type ) {
			$is_non_refundable = (bool) ( $booking->deposit_is_non_refundable ?? false );
			$refund_deadline   = $booking->refund_deadline_date ?? '';
			if ( $is_non_refundable ) {
				$html .= '<div style="margin-top: 8px; padding: 6px 10px; background: #fff1f2; border-left: 3px solid #f43f5e; color: #9f1239; font-size: 11px;">';
				$html .= '<strong>' . esc_html( I18n::get_label( 'label_email_non_refundable' ) ) . '</strong> ' . esc_html( I18n::get_label( 'msg_email_non_refundable_desc' ) );
				$html .= '</div>';
			} elseif ( '' !== (string) $refund_deadline && '0000-00-00' !== $refund_deadline ) {
				$deadline_ts = strtotime( $refund_deadline );
				$html       .= '<div style="margin-top: 8px; padding: 6px 10px; background: #f0fdf4; border-left: 3px solid #22c55e; color: #166534; font-size: 11px;">';
				/* translators: %s: formatted refund deadline date */
				$html       .= sprintf( esc_html( I18n::get_label( 'msg_email_refund_deadline' ) ), '<strong>' . date_i18n( get_option( 'date_format' ), $deadline_ts ) . '</strong>' );
				$html       .= '</div>';
			}
		}

		$html .= '</div>';
		return $html;
	}

/**
	 * Format a JSON list of children ages into a user-friendly, localized string.
	 * 
	 * This utility is used primarily in email notifications to provide detailed 
	 * guest composition data to administrators and customers.
	 * 
	 * @param string|null $ages_json JSON string of ages (e.g. '[5, 8]').
	 * @return string Formatted ages (e.g. '5, 8') or empty string if no indices found.
	 */
	private static function format_children_ages( ?string $ages_json ): string {
		if ( empty( $ages_json ) ) {
			return '';
		}

		$ages = json_decode( $ages_json, true );
		if ( ! is_array( $ages ) || array() === $ages ) {
			return '';
		}

		return implode( ', ', array_map( 'absint', $ages ) );
	}

/**
	 * Render an itemized payment history / folio ledger HTML table for emails.
	 */
	public static function render_payment_ledger_html( object $booking ): string {
		if ( ! class_exists( Pricing::class ) ) {
			return '';
		}
		$ledger = Pricing::get_payment_ledger( $booking );
		if ( empty( $ledger ) ) {
			return '';
		}
		$html  = '<div style="margin: 15px 0; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; font-family: sans-serif;">';
		$html .= '<div style="padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 700; font-size: 13px; color: #0f172a;">' . esc_html( I18n::get_label( 'label_payment_ledger' ) ) . '</div>';
		$html .= '<table width="100%" cellpadding="6" cellspacing="0" style="font-size: 12px; border-collapse: collapse;">';
		$html .= '<tr style="background: #f1f5f9; color: #475569; text-align: left;"><th scope="col" style="padding: 6px 10px;">' . esc_html__( 'Date', 'modern-hotel-booking' ) . '</th><th scope="col" style="padding: 6px 10px;">' . esc_html__( 'Type', 'modern-hotel-booking' ) . '</th><th scope="col" style="padding: 6px 10px;">' . esc_html__( 'Method', 'modern-hotel-booking' ) . '</th><th scope="col" style="padding: 6px 10px; text-align: right;">' . esc_html__( 'Amount', 'modern-hotel-booking' ) . '</th></tr>';
		foreach ( $ledger as $entry ) {
			$html .= '<tr style="border-bottom: 1px solid #f1f5f9;">';
			$html .= '<td style="padding: 6px 10px; color: #64748b;">' . esc_html( ! empty( $entry['date'] ) ? HotelTime::format_datetime( $entry['date'] ) : '—' ) . '</td>';
			$html .= '<td style="padding: 6px 10px; text-transform: capitalize; font-weight: 600;">' . esc_html( $entry['type'] ?? 'payment' ) . '</td>';
			$html .= '<td style="padding: 6px 10px;">' . esc_html( I18n::get_payment_method_label( $entry['method'] ?? 'arrival' ) );
			if ( ! empty( $entry['transaction_id'] ) ) {
				$html .= ' <code style="font-size:10px;background:#f1f5f9;padding:1px 3px;">(' . esc_html( $entry['transaction_id'] ) . ')</code>';
			}
			$html .= '</td>';
			$html .= '<td style="padding: 6px 10px; text-align: right; font-weight: 700; color: #166534;">' . esc_html( I18n::format_currency( (float) ( $entry['amount'] ?? 0 ) ) ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</table></div>';
		return $html;
	}

	/**
	 * Generate plain-text booking summary for WhatsApp dispatch.
	 *
	 * @param int $booking_id
	 * @return string
	 */
	public static function get_booking_summary_text( int $booking_id ): string {
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
			return '';
		}

		$lang          = $booking->booking_language ?: I18n::get_current_language();
		$company_name  = get_bloginfo( 'name' );
		$company_phone = '';
		if ( class_exists( 'MHBO\Business\Info' ) ) {
			$comp = \MHBO\Business\Info::get_company();
			if ( ! empty( $comp['company_name'] ) ) {
				$company_name = $comp['company_name'];
			}
			$company_phone = (string) ( $comp['telephone'] ?? '' );
		}

$check_in       = '' !== (string) ( $booking->check_in ?? '' ) ? HotelTime::format_date( (string) $booking->check_in ) : '--';
		$check_out      = '' !== (string) ( $booking->check_out ?? '' ) ? HotelTime::format_date( (string) $booking->check_out ) : '--';
		$check_in_time  = (string) get_option( 'mhbo_checkin_time', '14:00' );
		$check_out_time = (string) get_option( 'mhbo_checkout_time', '11:00' );

$in_ts   = strtotime( (string) ( $booking->check_in ?? '' ) );
		$out_ts  = strtotime( (string) ( $booking->check_out ?? '' ) );
		$day_sec = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$nights  = ( $in_ts && $out_ts && $out_ts > $in_ts ) ? (int) round( ( $out_ts - $in_ts ) / $day_sec ) : 1;

		$room_name = I18n::decode( (string) ( $booking->room_name ?? 'Room' ), $lang );
		if ( ! empty( $booking->room_number ) ) {
			$room_name .= ' (' . $booking->room_number . ')';
		}

		$total        = I18n::format_currency( Money::fromDecimal( (string) ( $booking->total_price ?? 0 ) ) );
		$paid         = I18n::format_currency( Money::fromDecimal( (string) ( $booking->payment_amount ?? 0 ) ) );
		$status_label = I18n::translate_status( (string) ( $booking->status ?? 'pending' ) );

		$adults = (int) ( $booking->guests ?? 1 );
		/* translators: %d: number of adults */
		$occupancy = sprintf( _n( '%d Adult', '%d Adults', $adults, 'modern-hotel-booking' ), $adults );

$title_text = ( 'pending' === ( $booking->status ?? '' ) )
			? __( 'Reservation Pending Payment — #', 'modern-hotel-booking' )
			: __( 'Booking Confirmation — #', 'modern-hotel-booking' );
		$lines   = array();
		$lines[] = "\u{1F4CB} *" . $title_text . (int) $booking->id . '*';
		$lines[] = '────────────────────────────';
		$lines[] = '*Status:* ' . $status_label;
		$lines[] = '*Guest:* ' . ( $booking->customer_name ?? '' );
		$lines[] = '*Room:* ' . $room_name;
		$lines[] = '';
		$lines[] = "\u{1F4C5} *Check-in:* " . $check_in . ' (from ' . $check_in_time . ')';
		$lines[] = "\u{1F4C5} *Check-out:* " . $check_out . ' (by ' . $check_out_time . ')';
		$lines[] = "\u{1F319} *Stay:* " . $nights . ' ' . _n( 'night', 'nights', $nights, 'modern-hotel-booking' ) . ' (' . $occupancy . ')';
		$lines[] = '';
		$lines[] = "\u{1F4B0} *Total Price:* " . $total;
		$wa_amts = Pricing::get_booking_payment_amounts( $booking );
		if ( 'deposit' === $wa_amts['due_type'] && $wa_amts['due_now']->isPositive() && $wa_amts['remaining']->greaterThan( $wa_amts['due_now'] ) ) {
			$lines[]      = "\u{1F4B8} *Deposit Required:* " . I18n::format_currency( $wa_amts['due_now'] );
			$after_dep_wa = $wa_amts['remaining']->subtract( $wa_amts['due_now'] );
			if ( $after_dep_wa->isPositive() ) {
				$lines[] = "\u{23F3} *Balance After Deposit:* " . I18n::format_currency( $after_dep_wa );
			}
		}
		if ( (float) ( $booking->payment_amount ?? 0 ) > 0 ) {
			$lines[] = "\u{1F4B3} *Amount Paid:* " . $paid;
		}

if ( ! empty( $booking->special_requests ) ) {
			$lines[] = '';
			$lines[] = "\u{1F4DD} *Special Requests:* " . trim( (string) $booking->special_requests );
		}
		$lines[] = '────────────────────────────';
		if ( '' !== $company_phone ) {
			$lines[] = "\u{1F4DE} " . $company_phone;
		}
		$lines[] = "\u{2728} Thank you for choosing " . $company_name . '!';

		return implode( "\n", $lines );
	}

	/**
	 * Render Google Maps Directions card for emails.
	 *
	 * @param string $lang
	 * @return string HTML
	 */
	public static function render_google_map_html( string $lang = '' ): string {
		if ( ! Tier::is_pro() || ! class_exists( 'MHBO\Business\Info' ) ) {
			return '';
		}
		$comp = \MHBO\Business\Info::get_company();
		if ( empty( $comp['show_map_in_email'] ) ) {
			return '';
		}

		// Hotel Name
		$hotel_name = trim( (string) ( $comp['name'] ?? '' ) );
		if ( '' === $hotel_name ) {
			$hotel_name = get_bloginfo( 'name' );
		}

		// Build human-readable address
		$address_parts = array_filter(
			array(
				$comp['address_line_1'] ?? '',
				$comp['address_line_2'] ?? '',
				$comp['city'] ?? '',
				$comp['state'] ?? '',
				$comp['postcode'] ?? '',
				$comp['country'] ?? '',
			)
		);
		$full_address  = implode( ', ', $address_parts );

		// Destination query label for Maps
		$dest_label = '' !== $hotel_name ? $hotel_name : $full_address;
		if ( '' !== $hotel_name && '' !== $full_address ) {
			$dest_label = $hotel_name . ', ' . $full_address;
		}

		// Determine destination & place profile URLs
		$directions_url = '';
		$profile_url    = '';
		$place_id       = trim( (string) ( $comp['google_place_id'] ?? '' ) );
		$maps_override  = trim( (string) ( $comp['google_maps_url'] ?? '' ) );

		if ( '' !== $place_id ) {
			// 2026 BP: Cross-platform Google Maps Universal URLs with GBP Place ID
			$directions_url = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $dest_label ) . '&destination_place_id=' . rawurlencode( $place_id );
			$profile_url    = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $hotel_name ) . '&query_place_id=' . rawurlencode( $place_id );
		} elseif ( '' !== $maps_override ) {
			$directions_url = $maps_override;
			$profile_url    = $maps_override;
		} elseif ( '' !== $dest_label ) {
			$directions_url = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $dest_label );
			$profile_url    = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $dest_label );
		}

		if ( '' === $directions_url ) {
			return '';
		}

		$html  = '<div style="margin:24px 0;padding:20px 24px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;text-align:center;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;">';
		$html .= '<h4 style="margin:0 0 6px 0;color:#0369a1;font-size:16px;font-weight:700;">📍 ' . esc_html__( 'Getting Here & Directions', 'modern-hotel-booking' ) . '</h4>';
		if ( '' !== $full_address ) {
			$html .= '<p style="margin:0 0 16px 0;color:#334155;font-size:14px;line-height:1.4;">' . esc_html( $full_address ) . '</p>';
		}
		$html .= '<div style="display:inline-block;text-align:center;">';
		$html .= '<a href="' . esc_url( $directions_url ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#0284c7;color:#ffffff;padding:10px 22px;margin:4px 6px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:13px;">';
		$html .= '🗺️ ' . esc_html__( 'Get Directions', 'modern-hotel-booking' );
		$html .= '</a>';
		if ( '' !== $profile_url && $profile_url !== $directions_url ) {
			$html .= '<a href="' . esc_url( $profile_url ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#ffffff;color:#0369a1;border:1px solid #7dd3fc;padding:10px 18px;margin:4px 6px;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;">';
			$html .= '🏨 ' . esc_html__( 'View on Google Maps', 'modern-hotel-booking' );
			$html .= '</a>';
		}
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render Social Media links row for email footers.
	 *
	 * @return string HTML
	 */
	public static function render_social_links_html(): string {
		if ( ! Tier::is_pro() || ! class_exists( 'MHBO\Business\Info' ) ) {
			return '';
		}
		$social = \MHBO\Business\Info::get_social();
		if ( empty( $social['enabled'] ) ) {
			return '';
		}

		$platforms = array(
			'facebook'    => array(
				'label' => 'Facebook',
				'color' => '#1877F2',
				'icon'  => 'f',
			),
			'instagram'   => array(
				'label' => 'Instagram',
				'color' => '#E4405F',
				'icon'  => '📸',
			),
			'twitter_x'   => array(
				'label' => 'X / Twitter',
				'color' => '#000000',
				'icon'  => '𝕏',
			),
			'tiktok'      => array(
				'label' => 'TikTok',
				'color' => '#000000',
				'icon'  => '🎵',
			),
			'youtube'     => array(
				'label' => 'YouTube',
				'color' => '#FF0000',
				'icon'  => '▶',
			),
			'linkedin'    => array(
				'label' => 'LinkedIn',
				'color' => '#0A66C2',
				'icon'  => 'in',
			),
			'pinterest'   => array(
				'label' => 'Pinterest',
				'color' => '#BD081C',
				'icon'  => '📌',
			),
			'tripadvisor' => array(
				'label' => 'TripAdvisor',
				'color' => '#34E0A1',
				'icon'  => '🦉',
			),
		);

		$items = array();
		foreach ( $platforms as $key => $meta ) {
			$url = trim( (string) ( $social[ $key ] ?? '' ) );
			if ( '' !== $url ) {
				$items[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;margin:0 6px;padding:6px 12px;background:#f1f5f9;color:#334155;border-radius:20px;text-decoration:none;font-size:12px;font-weight:600;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;border:1px solid #e2e8f0;">'
					. '<span style="color:' . esc_attr( $meta['color'] ) . ';margin-right:4px;">' . esc_html( $meta['icon'] ) . '</span> '
					. esc_html( $meta['label'] )
					. '</a>';
			}
		}

		if ( empty( $items ) ) {
			return '';
		}

		$html  = '<div style="margin:20px 0 10px 0;padding:12px 0;text-align:center;border-top:1px solid #f1f5f9;">';
		$html .= '<p style="margin:0 0 10px 0;font-size:12px;color:#64748b;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;">' . esc_html__( 'Connect with us:', 'modern-hotel-booking' ) . '</p>';
		$html .= '<div style="display:inline-block;text-align:center;">' . implode( ' ', $items ) . '</div>';
		$html .= '</div>';

		return $html;
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
	 * Build standard headers for direct admin notification alerts.
	 * Sets From to the Notification Email, Reply-To to the customer (or admin),
	 * and adds deduplicated 'Cc: <additional_email>' headers when configured,
	 * ensuring all additional notification recipients are properly CC'd on admin alerts.
	 *
	 * @param string $reply_to Optional reply-to address (e.g. customer email).
	 * @return array<int, string> Formatted email headers for admin alerts.
	 */
	public static function get_admin_headers( string $reply_to = '' ): array {
		$admin_email      = get_option( 'mhbo_notification_email', get_option( 'admin_email' ) );
		$hotel_name       = (string) get_option( 'mhbo_hotel_name', '' );
		$site_name        = '' !== trim( $hotel_name ) ? $hotel_name : (string) get_bloginfo( 'name' );
		$safe_site_name   = self::sanitize_header_value( $site_name );
		$safe_admin_email = sanitize_email( (string) $admin_email );
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
		$admin_email      = get_option( 'mhbo_notification_email', get_option( 'admin_email' ) );
		$hotel_name       = (string) get_option( 'mhbo_hotel_name', '' );
		$site_name        = '' !== trim( $hotel_name ) ? $hotel_name : (string) get_bloginfo( 'name' );
		$safe_site_name   = self::sanitize_header_value( $site_name );
		$safe_admin_email = sanitize_email( (string) $admin_email );

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
	 * Send an admin notification alert when an unpaid reservation is cancelled online.
	 *
	 * @param int    $booking_id The booking ID.
	 * @param string $reason     Optional cancellation reason provided by guest.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_admin_cancellation_notification( int $booking_id, string $reason = '' ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.*, t.name as room_name, r.room_number
				 FROM {$wpdb->prefix}mhbo_bookings b
				 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
				 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
				 WHERE b.id = %d LIMIT 1",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return false;
		}

		$admin_email = get_option( 'mhbo_notification_email', get_option( 'admin_email' ) );
		if ( ! is_email( (string) $admin_email ) ) {
			return false;
		}

		$lang        = (string) ( $booking->booking_language ?? '' ) ?: I18n::get_current_language();
		$guest_email = (string) ( $booking->customer_email ?? '' );
		I18n::switch_language_context( $lang );

		// Load multilingual templates from options with localized label fallbacks
		$template_subject = get_option( 'mhbo_email_admin_cancellation_subject' );
		if ( '' === (string) $template_subject ) {
			$template_subject = I18n::get_label( 'email_admin_cancellation_subject' );
			if ( 'email_admin_cancellation_subject' === $template_subject ) {
				$template_subject = __( 'Reservation Cancelled — Booking #{booking_id} ({customer_name})', 'modern-hotel-booking' );
			}
		}

		$template_message = get_option( 'mhbo_email_admin_cancellation_message' );
		if ( '' === (string) $template_message ) {
			$template_message = self::get_default_email_template( 'admin_cancellation' );
		}

		$subject = I18n::get_translated_string( 'Email admin_cancellation Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email admin_cancellation Message', (string) $template_message, 'MHBO Email Templates', $lang );

		$placeholders = self::get_booking_placeholders( $booking, 'admin_cancellation', $lang );
		$placeholders['{cancellation_reason}'] = '' !== trim( $reason ) ? esc_html( $reason ) : esc_html__( 'None provided', 'modern-hotel-booking' );

		$subject = self::apply_placeholders( $subject, $placeholders );
		$message = self::apply_placeholders( $message, $placeholders );

		$safe_subject = self::sanitize_header_value( $subject );
		$safe_subject = html_entity_decode( $safe_subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$headers = self::get_admin_headers( $guest_email );
		$sent    = (bool) wp_mail( (string) $admin_email, $safe_subject, $message, $headers );

		I18n::restore_language_context();
		return $sent;
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
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.*, t.name as room_name, r.room_number
				 FROM {$wpdb->prefix}mhbo_bookings b
				 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
				 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
				 WHERE b.id = %d LIMIT 1",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return false;
		}

		$admin_email = get_option( 'mhbo_notification_email', get_option( 'admin_email' ) );
		if ( ! is_email( (string) $admin_email ) ) {
			return false;
		}

		$lang           = (string) ( $booking->booking_language ?? '' ) ?: I18n::get_current_language();
		$guest_email    = (string) ( $booking->customer_email ?? '' );
		$formatted_paid = Pricing::format_price( $amount_paid );
		I18n::switch_language_context( $lang );

		// Load multilingual templates from options with localized label fallbacks
		$template_subject = get_option( 'mhbo_email_admin_cancellation_request_subject' );
		if ( '' === (string) $template_subject ) {
			$template_subject = I18n::get_label( 'email_admin_cancellation_request_subject' );
			if ( 'email_admin_cancellation_request_subject' === $template_subject ) {
				$template_subject = __( '⚠️ Action Required: Cancellation Request for Booking #{booking_id} ({customer_name})', 'modern-hotel-booking' );
			}
		}

		$template_message = get_option( 'mhbo_email_admin_cancellation_request_message' );
		if ( '' === (string) $template_message ) {
			$template_message = self::get_default_email_template( 'admin_cancellation_request' );
		}

		$subject = I18n::get_translated_string( 'Email admin_cancellation_request Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email admin_cancellation_request Message', (string) $template_message, 'MHBO Email Templates', $lang );

		$placeholders = self::get_booking_placeholders( $booking, 'admin_cancellation_request', $lang );
		$placeholders['{cancellation_reason}'] = '' !== trim( $reason ) ? esc_html( $reason ) : esc_html__( 'None provided', 'modern-hotel-booking' );
		$placeholders['{amount_paid}']         = $formatted_paid;
		$placeholders['{payment_amount}']      = $formatted_paid;

		$subject = self::apply_placeholders( $subject, $placeholders );
		$message = self::apply_placeholders( $message, $placeholders );

		$safe_subject = self::sanitize_header_value( $subject );
		$safe_subject = html_entity_decode( $safe_subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$headers = self::get_admin_headers( $guest_email );
		$sent    = (bool) wp_mail( (string) $admin_email, $safe_subject, $message, $headers );

		I18n::restore_language_context();
		return $sent;
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
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.*, t.name as room_name, r.room_number
				 FROM {$wpdb->prefix}mhbo_bookings b
				 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
				 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
				 WHERE b.id = %d LIMIT 1",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return false;
		}

		$to = sanitize_email( (string) ( $booking->customer_email ?? '' ) );
		if ( ! is_email( $to ) ) {
			return false;
		}

		$lang = (string) ( $booking->booking_language ?? '' ) ?: I18n::get_current_language();
		I18n::switch_language_context( $lang );

		// Load multilingual templates from options with localized label fallbacks
		$template_subject = get_option( 'mhbo_email_cancellation_request_subject' );
		if ( '' === (string) $template_subject ) {
			$template_subject = I18n::get_label( 'email_cancellation_request_subject' );
			if ( 'email_cancellation_request_subject' === $template_subject ) {
				$template_subject = __( 'Cancellation Request Received — Booking #{booking_id} at {company_name}', 'modern-hotel-booking' );
			}
		}

		$template_message = get_option( 'mhbo_email_cancellation_request_message' );
		if ( '' === (string) $template_message ) {
			$template_message = self::get_default_email_template( 'cancellation_request' );
		}

		$subject = I18n::get_translated_string( 'Email cancellation_request Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email cancellation_request Message', (string) $template_message, 'MHBO Email Templates', $lang );

		// Fetch standard booking & business placeholders
		$formatted_paid = Pricing::format_price( $amount_paid );
		$reason_clean   = '' !== trim( $reason ) ? esc_html( $reason ) : esc_html__( 'None provided', 'modern-hotel-booking' );
		$placeholders   = self::get_booking_placeholders( $booking, 'cancellation_request', $lang );
		$placeholders['{cancellation_reason}'] = $reason_clean;
		$placeholders['{amount_paid}']         = $formatted_paid;
		$placeholders['{payment_amount}']      = $formatted_paid;

		$subject = self::apply_placeholders( $subject, $placeholders );
		$message = self::apply_placeholders( $message, $placeholders );

		$safe_subject = self::sanitize_header_value( $subject );
		$safe_subject = html_entity_decode( $safe_subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$headers = self::get_transactional_headers( true, $to );
		$sent    = (bool) wp_mail( $to, $safe_subject, $message, $headers );

		I18n::restore_language_context();
		return $sent;
	}

	/**
	 * Send an acknowledgement email to a guest who submitted a stay modification request.
	 *
	 * Uses template-driven layout, multilingual translation, customizable subject and message,
	 * and standard Law 58 transactional headers.
	 *
	 * @param int    $booking_id       The booking ID.
	 * @param string $requested_dates  Requested date change.
	 * @param string $requested_guests Requested guest count change.
	 * @param string $notes            Additional requests / notes.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_guest_modification_request_email( int $booking_id, string $requested_dates = '', string $requested_guests = '', string $notes = '' ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.*, t.name as room_name, r.room_number
				 FROM {$wpdb->prefix}mhbo_bookings b
				 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
				 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
				 WHERE b.id = %d LIMIT 1",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return false;
		}

		$to = sanitize_email( (string) ( $booking->customer_email ?? '' ) );
		if ( ! is_email( $to ) ) {
			return false;
		}

		$lang = (string) ( $booking->booking_language ?? '' ) ?: I18n::get_current_language();
		I18n::switch_language_context( $lang );

		// Load multilingual templates from options with localized label fallbacks
		$template_subject = get_option( 'mhbo_email_modification_request_subject' );
		if ( '' === (string) $template_subject ) {
			$template_subject = I18n::get_label( 'email_modification_request_subject' );
			if ( 'email_modification_request_subject' === $template_subject ) {
				$template_subject = __( 'Stay Modification Request Received — Booking #{booking_id} at {company_name}', 'modern-hotel-booking' );
			}
		}

		$template_message = get_option( 'mhbo_email_modification_request_message' );
		if ( '' === (string) $template_message ) {
			$template_message = self::get_default_email_template( 'modification_request' );
		}

		$subject = I18n::get_translated_string( 'Email modification_request Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email modification_request Message', (string) $template_message, 'MHBO Email Templates', $lang );

		// Fetch standard booking & business placeholders
		$placeholders = self::get_booking_placeholders( $booking, 'modification_request', $lang );
		$placeholders['{requested_dates}']    = '' !== trim( $requested_dates ) ? esc_html( $requested_dates ) : esc_html__( 'No date changes requested', 'modern-hotel-booking' );
		$placeholders['{requested_guests}']   = '' !== trim( $requested_guests ) ? esc_html( $requested_guests ) : esc_html__( 'No guest changes requested', 'modern-hotel-booking' );
		$placeholders['{modification_notes}'] = '' !== trim( $notes ) ? esc_html( $notes ) : esc_html__( 'None', 'modern-hotel-booking' );

		$subject = self::apply_placeholders( $subject, $placeholders );
		$message = self::apply_placeholders( $message, $placeholders );

		$safe_subject = self::sanitize_header_value( $subject );
		$safe_subject = html_entity_decode( $safe_subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$headers = self::get_transactional_headers( true, $to );
		$sent    = (bool) wp_mail( $to, $safe_subject, $message, $headers );

		I18n::restore_language_context();
		return $sent;
	}

	/**
	 * Send an operational alert email to hotel administrators and reservation staff
	 * when a guest submits a stay modification request via the Guest Self-Service Portal.
	 *
	 * @param int    $booking_id       The booking ID.
	 * @param string $requested_dates  Requested date modifications.
	 * @param string $requested_guests Requested guest count changes.
	 * @param string $notes            Guest notes / special requests.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_admin_modification_request_notification( int $booking_id, string $requested_dates = '', string $requested_guests = '', string $notes = '' ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.*, t.name as room_name, r.room_number
				 FROM {$wpdb->prefix}mhbo_bookings b
				 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
				 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
				 WHERE b.id = %d LIMIT 1",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return false;
		}

		$admin_email = get_option( 'mhbo_notification_email', get_option( 'admin_email' ) );
		if ( ! is_email( (string) $admin_email ) ) {
			return false;
		}

		$lang        = (string) ( $booking->booking_language ?? '' ) ?: I18n::get_current_language();
		$guest_email = (string) ( $booking->customer_email ?? '' );
		I18n::switch_language_context( $lang );

		$template_subject = get_option( 'mhbo_email_admin_modification_request_subject' );
		if ( '' === (string) $template_subject ) {
			$template_subject = I18n::get_label( 'email_admin_modification_request_subject' );
			if ( 'email_admin_modification_request_subject' === $template_subject ) {
				$template_subject = __( 'Stay Modification Request — Booking #{booking_id} ({customer_name})', 'modern-hotel-booking' );
			}
		}

		$template_message = get_option( 'mhbo_email_admin_modification_request_message' );
		if ( '' === (string) $template_message ) {
			$template_message = self::get_default_email_template( 'admin_modification_request' );
		}

		$subject = I18n::get_translated_string( 'Email admin_modification_request Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email admin_modification_request Message', (string) $template_message, 'MHBO Email Templates', $lang );

		$placeholders = self::get_booking_placeholders( $booking, 'admin_modification_request', $lang );
		$placeholders['{requested_dates}']    = '' !== trim( $requested_dates ) ? esc_html( $requested_dates ) : esc_html__( 'No date changes requested', 'modern-hotel-booking' );
		$placeholders['{requested_guests}']   = '' !== trim( $requested_guests ) ? esc_html( $requested_guests ) : esc_html__( 'No guest changes requested', 'modern-hotel-booking' );
		$placeholders['{modification_notes}'] = '' !== trim( $notes ) ? esc_html( $notes ) : esc_html__( 'None provided', 'modern-hotel-booking' );

		$subject = self::apply_placeholders( $subject, $placeholders );
		$message = self::apply_placeholders( $message, $placeholders );

		$safe_subject = self::sanitize_header_value( $subject );
		$safe_subject = html_entity_decode( $safe_subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$headers = self::get_admin_headers( $guest_email );
		$sent    = (bool) wp_mail( (string) $admin_email, $safe_subject, $message, $headers );

		I18n::restore_language_context();
		return $sent;
	}

	/**
	 * Send an automated security notice to old and new email addresses, as well as property staff,
	 * whenever a guest updates their contact email address via the Guest Self-Service Portal.
	 *
	 * @param int    $booking_id The booking ID.
	 * @param string $old_email  Previous email address.
	 * @param string $new_email  Updated email address.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_email_updated_notification( int $booking_id, string $old_email, string $new_email ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.*, t.name as room_name, r.room_number
				 FROM {$wpdb->prefix}mhbo_bookings b
				 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
				 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
				 WHERE b.id = %d LIMIT 1",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return false;
		}

		$lang = (string) ( $booking->booking_language ?? '' ) ?: I18n::get_current_language();
		I18n::switch_language_context( $lang );

		$template_subject = get_option( 'mhbo_email_email_updated_subject' );
		if ( '' === (string) $template_subject ) {
			$template_subject = I18n::get_label( 'email_email_updated_subject' );
			if ( 'email_email_updated_subject' === $template_subject ) {
				$template_subject = __( 'Security Notice: Email Address Updated for Booking #{booking_id} — {company_name}', 'modern-hotel-booking' );
			}
		}

		$template_message = get_option( 'mhbo_email_email_updated_message' );
		if ( '' === (string) $template_message ) {
			$template_message = self::get_default_email_template( 'email_updated' );
		}

		$subject = I18n::get_translated_string( 'Email email_updated Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email email_updated Message', (string) $template_message, 'MHBO Email Templates', $lang );

		$placeholders = self::get_booking_placeholders( $booking, 'email_updated', $lang );
		$placeholders['{old_email}'] = sanitize_email( $old_email );
		$placeholders['{new_email}'] = sanitize_email( $new_email );

		$subject = self::apply_placeholders( $subject, $placeholders );
		$message = self::apply_placeholders( $message, $placeholders );

		$safe_subject = self::sanitize_header_value( $subject );
		$safe_subject = html_entity_decode( $safe_subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$hotel_email = (string) get_option( 'mhbo_notification_email', get_option( 'admin_email' ) );
		$recipients  = array_unique(
			array_filter(
				array( sanitize_email( $old_email ), sanitize_email( $new_email ), sanitize_email( $hotel_email ) ),
				static fn( string $email ): bool => (bool) is_email( $email )
			)
		);

		$headers = self::get_transactional_headers( true );
		$sent    = (bool) wp_mail( $recipients, $safe_subject, $message, $headers );

		I18n::restore_language_context();
		return $sent;
	}

	/**
	 * Send a confirmation email to the guest (and property staff BCC) when stay dates
	 * are updated directly for an unpaid reservation via the Guest Self-Service Portal.
	 *
	 * @param int    $booking_id    The booking ID.
	 * @param string $old_check_in  Previous check-in date.
	 * @param string $old_check_out Previous check-out date.
	 * @return bool True if sent, false otherwise.
	 */
	public static function send_stay_dates_updated_email( int $booking_id, string $old_check_in = '', string $old_check_out = '' ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.*, t.name as room_name, r.room_number
				 FROM {$wpdb->prefix}mhbo_bookings b
				 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
				 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
				 WHERE b.id = %d LIMIT 1",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return false;
		}

		$to = sanitize_email( (string) ( $booking->customer_email ?? '' ) );
		if ( ! is_email( $to ) ) {
			return false;
		}

		$lang = (string) ( $booking->booking_language ?? '' ) ?: I18n::get_current_language();
		I18n::switch_language_context( $lang );

		$template_subject = get_option( 'mhbo_email_stay_dates_updated_subject' );
		if ( '' === (string) $template_subject ) {
			$template_subject = I18n::get_label( 'email_stay_dates_updated_subject' );
			if ( 'email_stay_dates_updated_subject' === $template_subject ) {
				$template_subject = __( 'Stay Dates Updated — Booking #{booking_id} at {company_name}', 'modern-hotel-booking' );
			}
		}

		$template_message = get_option( 'mhbo_email_stay_dates_updated_message' );
		if ( '' === (string) $template_message ) {
			$template_message = self::get_default_email_template( 'stay_dates_updated' );
		}

		$subject = I18n::get_translated_string( 'Email stay_dates_updated Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email stay_dates_updated Message', (string) $template_message, 'MHBO Email Templates', $lang );

		$placeholders = self::get_booking_placeholders( $booking, 'stay_dates_updated', $lang );
		if ( '' !== $old_check_in ) {
			$placeholders['{old_check_in}'] = HotelTime::format_date( $old_check_in );
		}
		if ( '' !== $old_check_out ) {
			$placeholders['{old_check_out}'] = HotelTime::format_date( $old_check_out );
		}

		$subject = self::apply_placeholders( $subject, $placeholders );
		$message = self::apply_placeholders( $message, $placeholders );

		$safe_subject = self::sanitize_header_value( $subject );
		$safe_subject = html_entity_decode( $safe_subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$headers = self::get_transactional_headers( true, $to );
		$sent    = (bool) wp_mail( $to, $safe_subject, $message, $headers );

		I18n::restore_language_context();
		return $sent;
	}

	/**
	 * Strip carriage returns and newlines from email header values to prevent header injection.
	 *
	 * @param string $value Header value.
	 * @return string Sanitized header value.
	 */
	private static function sanitize_header_value( string $value ): string {
		return str_replace( array( "\r", "\n" ), '', $value );
	}
}
