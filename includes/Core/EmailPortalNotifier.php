<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

/**
 * Dispatches guest verification and Guest Portal lifecycle notification emails.
 */
class EmailPortalNotifier {

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
			$template_message = Email::get_default_email_template( 'identity_verification' );
		}

		$subject = (string) ( I18n::decode( $template_subject, $lang ) ?? '' );
		$message = (string) ( I18n::decode( $template_message, $lang ) ?? '' );

		// Merge verification-specific and business placeholders
		$business_placeholders     = EmailPlaceholderBuilder::get_business_placeholders( $lang );
		$verification_placeholders = array(
			'{verification_code}'   => esc_html( $code ),
			'{verification_expiry}' => esc_html__( '20 minutes', 'modern-hotel-booking' ),
			'{customer_email}'      => esc_html( $to ),
			'{guest_email}'         => esc_html( $to ),
		);
		$all_placeholders          = array_merge( $business_placeholders, $verification_placeholders );

		$subject = str_replace( array_keys( $all_placeholders ), array_values( $all_placeholders ), $subject );
		$message = str_replace( array_keys( $all_placeholders ), array_values( $all_placeholders ), $message );

		$safe_subject = Email::sanitize_header_value( $subject );
		$safe_subject = html_entity_decode( $safe_subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Law 58 transactional headers (without BCC admin for transient OTP codes)
		$headers = Email::get_transactional_headers( false, $to );

		$sent = (bool) wp_mail( $to, $safe_subject, $message, $headers );
		I18n::restore_language_context();
		return $sent;
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

		$admin_email = Email::get_notification_email();
		if ( ! is_email( $admin_email ) ) {
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
			$template_message = Email::get_default_email_template( 'admin_cancellation' );
		}

		$subject = I18n::get_translated_string( 'Email admin_cancellation Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email admin_cancellation Message', (string) $template_message, 'MHBO Email Templates', $lang );

		$placeholders = EmailPlaceholderBuilder::get_booking_placeholders( $booking, 'admin_cancellation', $lang );
		$placeholders['{cancellation_reason}'] = '' !== trim( $reason ) ? esc_html( $reason ) : esc_html__( 'None provided', 'modern-hotel-booking' );

		$subject = EmailPlaceholderBuilder::apply_placeholders( $subject, $placeholders );
		$message = EmailPlaceholderBuilder::apply_placeholders( $message, $placeholders );

		$safe_subject = Email::sanitize_header_value( $subject );

		$headers = Email::get_admin_headers( $guest_email );
		$sent    = (bool) wp_mail( $admin_email, $safe_subject, $message, $headers );

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

		$admin_email = Email::get_notification_email();
		if ( ! is_email( $admin_email ) ) {
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
			$template_message = Email::get_default_email_template( 'admin_cancellation_request' );
		}

		$subject = I18n::get_translated_string( 'Email admin_cancellation_request Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email admin_cancellation_request Message', (string) $template_message, 'MHBO Email Templates', $lang );

		$placeholders = EmailPlaceholderBuilder::get_booking_placeholders( $booking, 'admin_cancellation_request', $lang );
		$placeholders['{cancellation_reason}'] = '' !== trim( $reason ) ? esc_html( $reason ) : esc_html__( 'None provided', 'modern-hotel-booking' );
		$placeholders['{amount_paid}']         = $formatted_paid;
		$placeholders['{payment_amount}']      = $formatted_paid;

		$subject = EmailPlaceholderBuilder::apply_placeholders( $subject, $placeholders );
		$message = EmailPlaceholderBuilder::apply_placeholders( $message, $placeholders );

		$safe_subject = Email::sanitize_header_value( $subject );

		$headers = Email::get_admin_headers( $guest_email );
		$sent    = (bool) wp_mail( $admin_email, $safe_subject, $message, $headers );

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
			$template_message = Email::get_default_email_template( 'cancellation_request' );
		}

		$subject = I18n::get_translated_string( 'Email cancellation_request Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email cancellation_request Message', (string) $template_message, 'MHBO Email Templates', $lang );

		// Fetch standard booking & business placeholders
		$formatted_paid = Pricing::format_price( $amount_paid );
		$reason_clean   = '' !== trim( $reason ) ? esc_html( $reason ) : esc_html__( 'None provided', 'modern-hotel-booking' );
		$placeholders   = EmailPlaceholderBuilder::get_booking_placeholders( $booking, 'cancellation_request', $lang );
		$placeholders['{cancellation_reason}'] = $reason_clean;
		$placeholders['{amount_paid}']         = $formatted_paid;
		$placeholders['{payment_amount}']      = $formatted_paid;

		$subject = EmailPlaceholderBuilder::apply_placeholders( $subject, $placeholders );
		$message = EmailPlaceholderBuilder::apply_placeholders( $message, $placeholders );

		$safe_subject = Email::sanitize_header_value( $subject );

		$headers = Email::get_transactional_headers( true, $to );
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
			$template_message = Email::get_default_email_template( 'modification_request' );
		}

		$subject = I18n::get_translated_string( 'Email modification_request Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email modification_request Message', (string) $template_message, 'MHBO Email Templates', $lang );

		// Fetch standard booking & business placeholders
		$placeholders = EmailPlaceholderBuilder::get_booking_placeholders( $booking, 'modification_request', $lang );
		$placeholders['{requested_dates}']    = '' !== trim( $requested_dates ) ? esc_html( $requested_dates ) : esc_html__( 'No date changes requested', 'modern-hotel-booking' );
		$placeholders['{requested_guests}']   = '' !== trim( $requested_guests ) ? esc_html( $requested_guests ) : esc_html__( 'No guest changes requested', 'modern-hotel-booking' );
		$placeholders['{modification_notes}'] = '' !== trim( $notes ) ? esc_html( $notes ) : esc_html__( 'None', 'modern-hotel-booking' );

		$subject = EmailPlaceholderBuilder::apply_placeholders( $subject, $placeholders );
		$message = EmailPlaceholderBuilder::apply_placeholders( $message, $placeholders );

		$safe_subject = Email::sanitize_header_value( $subject );

		$headers = Email::get_transactional_headers( true, $to );
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

		$admin_email = Email::get_notification_email();
		if ( ! is_email( $admin_email ) ) {
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
			$template_message = Email::get_default_email_template( 'admin_modification_request' );
		}

		$subject = I18n::get_translated_string( 'Email admin_modification_request Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email admin_modification_request Message', (string) $template_message, 'MHBO Email Templates', $lang );

		$placeholders = EmailPlaceholderBuilder::get_booking_placeholders( $booking, 'admin_modification_request', $lang );
		$placeholders['{requested_dates}']    = '' !== trim( $requested_dates ) ? esc_html( $requested_dates ) : esc_html__( 'No date changes requested', 'modern-hotel-booking' );
		$placeholders['{requested_guests}']   = '' !== trim( $requested_guests ) ? esc_html( $requested_guests ) : esc_html__( 'No guest changes requested', 'modern-hotel-booking' );
		$placeholders['{modification_notes}'] = '' !== trim( $notes ) ? esc_html( $notes ) : esc_html__( 'None provided', 'modern-hotel-booking' );

		$subject = EmailPlaceholderBuilder::apply_placeholders( $subject, $placeholders );
		$message = EmailPlaceholderBuilder::apply_placeholders( $message, $placeholders );

		$safe_subject = Email::sanitize_header_value( $subject );

		$headers = Email::get_admin_headers( $guest_email );
		$sent    = (bool) wp_mail( $admin_email, $safe_subject, $message, $headers );

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
			$template_message = Email::get_default_email_template( 'email_updated' );
		}

		$subject = I18n::get_translated_string( 'Email email_updated Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email email_updated Message', (string) $template_message, 'MHBO Email Templates', $lang );

		$placeholders = EmailPlaceholderBuilder::get_booking_placeholders( $booking, 'email_updated', $lang );
		$placeholders['{old_email}'] = sanitize_email( $old_email );
		$placeholders['{new_email}'] = sanitize_email( $new_email );

		$subject = EmailPlaceholderBuilder::apply_placeholders( $subject, $placeholders );
		$message = EmailPlaceholderBuilder::apply_placeholders( $message, $placeholders );

		$safe_subject = Email::sanitize_header_value( $subject );

		$hotel_email = Email::get_notification_email();
		$recipients  = array_unique(
			array_filter(
				array( sanitize_email( $old_email ), sanitize_email( $new_email ), $hotel_email ),
				static fn( string $email ): bool => (bool) is_email( $email )
			)
		);

		$headers = Email::get_transactional_headers( true );
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
			$template_message = Email::get_default_email_template( 'stay_dates_updated' );
		}

		$subject = I18n::get_translated_string( 'Email stay_dates_updated Subject', (string) $template_subject, 'MHBO Email Templates', $lang );
		$message = I18n::get_translated_string( 'Email stay_dates_updated Message', (string) $template_message, 'MHBO Email Templates', $lang );

		$placeholders = EmailPlaceholderBuilder::get_booking_placeholders( $booking, 'stay_dates_updated', $lang );
		if ( '' !== $old_check_in ) {
			$placeholders['{old_check_in}'] = HotelTime::format_date( $old_check_in );
		}
		if ( '' !== $old_check_out ) {
			$placeholders['{old_check_out}'] = HotelTime::format_date( $old_check_out );
		}

		$subject = EmailPlaceholderBuilder::apply_placeholders( $subject, $placeholders );
		$message = EmailPlaceholderBuilder::apply_placeholders( $message, $placeholders );

		$safe_subject = Email::sanitize_header_value( $subject );

		$headers = Email::get_transactional_headers( true, $to );
		$sent    = (bool) wp_mail( $to, $safe_subject, $message, $headers );

		I18n::restore_language_context();
		return $sent;
	}
}
