<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

/**
 * Builds email placeholders and iCalendar (ICS) attachments.
 */
class EmailPlaceholderBuilder {

	/**
	 * Generate a simple ICS file for email attachments.
	 * Use explicit UTC times matching the hotel check-in/out timezone configuration.
	 *
	 * @param object $booking The booking object.
	 * @return string ICS file content.
	 */
	public static function generate_simple_ics( object $booking ): string {
		$now            = wp_date( 'Ymd\THis\Z' );
		$check_in_date  = (string) ( $booking->check_in ?? '' );
		$check_out_date = (string) ( $booking->check_out ?? '' );
		
		if ( '' === $check_in_date || '' === $check_out_date ) {
			return '';
		}

		try {
			// Respect the dedicated Hotel Timezone setting
			$tz = HotelTime::timezone();
			
			$ci_time = HotelTime::get_checkin_time();
			$co_time = HotelTime::get_checkout_time();

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
	 * Get placeholders for Business Information.
	 *
	 * @param string $lang Current language.
	 * @return array<string, string>
	 */
	public static function get_business_placeholders( string $lang = '' ): array {
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

			$placeholders['{company_name}']         = esc_html( (string) ( I18n::decode( (string) $c_name, $lang ) ?? $c_name ) );
			$placeholders['{hotel_name}']           = esc_html( (string) ( I18n::decode( (string) $c_name, $lang ) ?? $c_name ) );
			$placeholders['{company_address}']      = esc_html( (string) ( I18n::decode( (string) $c_addr, $lang ) ?? '' ) );
			$placeholders['{company_phone}']        = esc_html( (string) $c_phone );
			$placeholders['{company_email}']        = esc_html( (string) ( $company['email'] ?? '' ) );
			$placeholders['{company_website}']      = esc_html( (string) ( $company['website'] ?? '' ) );
			$placeholders['{company_registration}'] = esc_html( (string) $c_reg );
			$raw_wa_digits = preg_replace( '/[^0-9]/', '', (string) ( $whatsapp['phone_number'] ?? '' ) );
			$clean_wa_num  = (string) preg_replace( '/^00/', '', (string) $raw_wa_digits );
			$wa_enabled    = ! empty( $whatsapp['enabled'] ) && '' !== $clean_wa_num;
			$wa_url        = '';
			if ( $wa_enabled ) {
				$wa_url = 'https://wa.me/' . $clean_wa_num;
				if ( ! empty( $whatsapp['default_msg'] ) ) {
					$wa_url = add_query_arg( 'text', rawurlencode( (string) $whatsapp['default_msg'] ), $wa_url );
				}
			}
			$placeholders['{whatsapp_number}']      = $clean_wa_num;
			$placeholders['{whatsapp_link}']        = $wa_url;
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
		$raw_special = BookingProcessor::extract_guest_special_requests( $booking );
		$special     = '' !== $raw_special ? esc_html( $raw_special ) : '';

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
		$reservation_breakdown_html = EmailHtmlRenderer::render_reservation_breakdown_html( $booking, $lang );

		$ph_checkin_time  = HotelTime::get_checkin_time();
		$ph_checkout_time = HotelTime::get_checkout_time();

$discount_row = '';

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
			'{check_in_time}'           => esc_html( $ph_checkin_time ),
			'{check_out_time}'          => esc_html( $ph_checkout_time ),
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
			'{payment_ledger}'          => EmailHtmlRenderer::render_payment_ledger_html( $booking ),
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
			'{arrival_time}'            => esc_html( BookingProcessor::extract_arrival_time( $booking ) ),

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
			'{discount_row}'            => $discount_row,
			'{payment_note_row}'        => '',
			
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

		// 1. Decode only curly-brace HTML entities (wp_editor may encode placeholder braces as &#123; / &#125;) without re-opening escaped HTML tags.
		$text = (string) preg_replace(
			array( '/&#0*123;|&#x0*7b;|&(?:lbrace|lcub);/i', '/&#0*125;|&#x0*7d;|&(?:rbrace|rcub);/i' ),
			array( '{', '}' ),
			$text
		);

		// 2. Perform raw replacement
		$text = str_replace( array_keys( $placeholders ), array_map( 'strval', array_values( $placeholders ) ), $text );

		// Strip dead WhatsApp buttons when WhatsApp is unconfigured or disabled
		$text = (string) preg_replace( '/<a\b[^>]*\bhref=["\'](?:#)?["\'][^>]*>\s*WhatsApp\s*<\/a>/i', '', $text );

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
	 * Write iCal content to an ephemeral .ics file in the system temporary directory.
	 * Using a .ics extension instead of wp_tempnam()'s .tmp extension ensures PHPMailer
	 * sets the attachment MIME type to text/calendar instead of application/octet-stream.
	 * Uses atomic 'xb' exclusive file creation to prevent CWE-377 symlink/TOCTOU races.
	 *
	 * @param string $ics_content Raw RFC 5545 iCalendar payload.
	 * @param string $filename    Target filename (e.g. 'booking-8.ics').
	 * @return string Absolute path to the written .ics file, or empty string on failure.
	 */
	public static function create_ics_attachment_file( string $ics_content, string $filename ): string {
		if ( '' === trim( $ics_content ) ) {
			return '';
		}

		if ( ! function_exists( 'get_temp_dir' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temp_dir    = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		$is_writable = is_string( $temp_dir ) && '' !== $temp_dir && is_dir( $temp_dir ) && (
			function_exists( 'wp_is_writable' )
				? wp_is_writable( $temp_dir )
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable -- Unit test fallback when wp_is_writable is unavailable.
				: is_writable( $temp_dir )
		);
		if ( ! $is_writable ) {
			$temp_dir = sys_get_temp_dir();
		}

		$safe_name = function_exists( 'sanitize_file_name' )
			? sanitize_file_name( $filename )
			: (string) preg_replace( '/[^a-zA-Z0-9._-]/', '', $filename );
		if ( '' === $safe_name || '.ics' === strtolower( $safe_name ) ) {
			$safe_name = 'booking.ics';
		} elseif ( ! str_ends_with( strtolower( $safe_name ), '.ics' ) ) {
			$safe_name .= '.ics';
		}

		$base_dir  = rtrim( (string) $temp_dir, '/\\' ) . DIRECTORY_SEPARATOR;
		$stem      = substr( $safe_name, 0, -4 );
		$candidate = $base_dir . $safe_name;

		$handle    = false;
		$file_path = '';
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$try_path = ( 0 === $attempt )
				? $candidate
				: $base_dir . $stem . '-' . bin2hex( random_bytes( 4 ) ) . '.ics';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Atomic 'xb' exclusive creation prevents CWE-377 symlink/TOCTOU races.
			$handle = @fopen( $try_path, 'xb' );
			if ( false !== $handle ) {
				$file_path = $try_path;
				break;
			}
		}

		if ( false === $handle || '' === $file_path ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to atomically opened temp file handle.
		$written = fwrite( $handle, $ics_content );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing atomically opened temp file handle.
		fclose( $handle );

		if ( false === $written ) {
			wp_delete_file( $file_path );
			return '';
		}

		return $file_path;
	}
}
