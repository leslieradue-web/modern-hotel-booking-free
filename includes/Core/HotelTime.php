<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hotel timezone-aware date helpers.
 *
 * Centralises all date operations so every system (pricing, iCal, availability,
 * calendar UI) uses the admin-configured hotel timezone rather than raw UTC
 * (gmdate) or the WordPress site timezone.
 *
 * Option key: mhbo_hotel_timezone (IANA identifier, e.g. "Europe/Bucharest")
 * Default:    Falls back to WP timezone_string, then numeric gmt_offset, then UTC.
 *
 * IMPORTANT: Call HotelTime::reset() any time the mhbo_hotel_timezone option
 * is saved so the cached \DateTimeZone object is refreshed.
 *
 * @package MHBO\Core
 * @since 2.3.8
 */
class HotelTime {

	/** @var \DateTimeZone|null Runtime-cached timezone object (process-scoped). */
	private static ?\DateTimeZone $tz = null;

	/**
	 * Initialize automatic hook listeners for timezone invalidation.
	 */
	public static function init(): void {
		add_action( 'update_option_mhbo_hotel_timezone', array( self::class, 'reset' ) );
		add_action( 'update_option_timezone_string', array( self::class, 'reset' ) );
		add_action( 'update_option_gmt_offset', array( self::class, 'reset' ) );
	}

	// ------------------------------------------------------------------ //
	// Public API
	// ------------------------------------------------------------------ //

	/**
	 * Get the hotel's configured \DateTimeZone object.
	 * Falls back to WordPress site timezone (wp_timezone / timezone_string / gmt_offset), then UTC.
	 */
	public static function timezone(): \DateTimeZone {
		if ( null !== self::$tz ) {
			return self::$tz;
		}

		$tz_string = trim( (string) get_option( 'mhbo_hotel_timezone', '' ) );

		// If blank or not set, fall back to WordPress site timezone
		if ( '' === $tz_string ) {
			if ( function_exists( 'wp_timezone' ) ) {
				try {
					self::$tz = wp_timezone();
					return self::$tz;
				} catch ( \Throwable $e ) {
					// Continue to string fallback
				}
			}
			if ( function_exists( 'wp_timezone_string' ) ) {
				$tz_string = wp_timezone_string();
			}
		}

		// Fall back to WordPress site timezone option
		if ( '' === $tz_string ) {
			$tz_string = (string) get_option( 'timezone_string', '' );
		}

		// Fall back to numeric offset (e.g. gmt_offset = 5.5 → "+05:30")
		if ( '' === $tz_string ) {
			$offset    = (float) get_option( 'gmt_offset', 0 );
			$h         = (int) $offset;
			$m         = abs( (int) round( ( $offset - $h ) * 60 ) );
			$tz_string = sprintf( '%+03d:%02d', $h, $m );
		}

		try {
			self::$tz = new \DateTimeZone( $tz_string ?: 'UTC' );
		} catch ( \Throwable $e ) {
			self::$tz = new \DateTimeZone( 'UTC' );
		}

		return self::$tz;
	}

	/**
	 * Invalidate the cached timezone object.
	 */
	public static function reset(): void {
		self::$tz = null;
	}

	/**
	 * Get current \DateTimeImmutable in hotel timezone.
	 */
	public static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', self::timezone() );
	}

	/**
	 * Current MySQL datetime string in hotel timezone ('Y-m-d H:i:s').
	 * Safe, timezone-exact drop-in replacement for current_time('mysql').
	 */
	public static function now_mysql(): string {
		return self::format( 'Y-m-d H:i:s', time() );
	}

	/**
	 * Format a date string, timestamp, or DateTimeInterface in the hotel's timezone.
	 *
	 * @param string                             $format   PHP date format (defaults to WP date_format).
	 * @param int|string|\DateTimeInterface|null $datetime Date/time value ('now', timestamp, or string).
	 * @return string Formatted string in hotel timezone.
	 */
	public static function format( string $format = '', mixed $datetime = null ): string {
		if ( '' === $format ) {
			$format = (string) get_option( 'date_format', 'Y-m-d' );
		}

		if ( null === $datetime || 'now' === $datetime || '' === $datetime ) {
			return (string) wp_date( $format, time(), self::timezone() );
		}

		if ( $datetime instanceof \DateTimeInterface ) {
			return (string) wp_date( $format, $datetime->getTimestamp(), self::timezone() );
		}

		if ( is_numeric( $datetime ) ) {
			return (string) wp_date( $format, (int) $datetime, self::timezone() );
		}

		// Parse date string in hotel timezone (or explicit offset if provided)
		try {
			$str = (string) $datetime;
			if ( preg_match( '/[Zz]|[\+\-]\d{2}:?\d{2}$/', $str ) ) {
				$dt = new \DateTimeImmutable( $str );
			} else {
				$dt = new \DateTimeImmutable( $str, self::timezone() );
			}
			return (string) wp_date( $format, $dt->getTimestamp(), self::timezone() );
		} catch ( \Throwable $e ) {
			$ts = strtotime( (string) $datetime );
			return $ts ? (string) wp_date( $format, $ts, self::timezone() ) : (string) $datetime;
		}
	}

	/**
	 * Format date using site's date_format in hotel timezone.
	 */
	public static function format_date( mixed $datetime = null, string $format = '' ): string {
		if ( '' === $format ) {
			$format = (string) get_option( 'date_format', 'Y-m-d' );
		}
		return self::format( $format, $datetime );
	}

	/**
	 * Format date and time combined (site date_format + time_format) in hotel timezone.
	 */
	public static function format_datetime( mixed $datetime = null, string $format = '' ): string {
		if ( '' === $format ) {
			$date_fmt = (string) get_option( 'date_format', 'Y-m-d' );
			$time_fmt = (string) get_option( 'time_format', 'H:i' );
			$format   = $date_fmt . ' ' . $time_fmt;
		}
		return self::format( $format, $datetime );
	}

	/**
	 * Format time using site's time_format in hotel timezone.
	 */
	public static function format_time( mixed $datetime = null, string $format = '' ): string {
		if ( '' === $format ) {
			$format = (string) get_option( 'time_format', 'H:i' );
		}
		return self::format( $format, $datetime );
	}

	/**
	 * Timezone-aware replacement for gmdate() or date().
	 *
	 * @param string $format    PHP date format string.
	 * @param int    $timestamp Unix timestamp.
	 * @return string Formatted date string in hotel timezone.
	 */
	public static function date( string $format, int $timestamp ): string {
		return (string) wp_date( $format, $timestamp, self::timezone() );
	}

	/**
	 * Current date in hotel timezone as Y-m-d.
	 */
	public static function today(): string {
		return self::date( 'Y-m-d', time() );
	}

	/**
	 * Convert a Y-m-d string to a Unix timestamp for midnight in hotel timezone.
	 *
	 * @param string $ymd Date in Y-m-d format.
	 * @return int Unix timestamp for midnight of that date in hotel timezone.
	 */
	public static function midnight( string $ymd ): int {
		try {
			$dt = new \DateTime( $ymd . ' 00:00:00', self::timezone() );
			return (int) $dt->getTimestamp();
		} catch ( \Exception $e ) {
			return (int) strtotime( $ymd );
		}
	}

	/**
	 * Lowercase day name (e.g. 'friday') for a date in hotel timezone.
	 *
	 * @param string $ymd Date in Y-m-d format.
	 * @return string Lowercase English day name ('monday'…'sunday').
	 */
	public static function day_name( string $ymd ): string {
		return strtolower( self::date( 'l', self::midnight( $ymd ) ) );
	}

	/**
	 * ISO 8601 day-of-week number (1=Mon … 7=Sun) in hotel timezone.
	 *
	 * @param string $ymd Date in Y-m-d format.
	 * @return int 1 (Monday) through 7 (Sunday).
	 */
	public static function day_of_week( string $ymd ): int {
		return (int) self::date( 'N', self::midnight( $ymd ) );
	}

	/**
	 * Add or subtract days from a Y-m-d date in hotel timezone.
	 *
	 * @param string $ymd  Base date (Y-m-d).
	 * @param int    $days Days to add (positive) or subtract (negative).
	 * @return string Resulting Y-m-d string.
	 */
	public static function add_days( string $ymd, int $days ): string {
		try {
			$dt       = new \DateTimeImmutable( $ymd . ' 00:00:00', self::timezone() );
			$modifier = ( $days >= 0 ? '+' : '' ) . $days . ' days';
			return $dt->modify( $modifier )->format( 'Y-m-d' );
		} catch ( \Throwable $e ) {
			$ts = strtotime( $ymd . ' ' . ( $days >= 0 ? '+' : '' ) . $days . ' days' );
			return $ts ? gmdate( 'Y-m-d', $ts ) : $ymd;
		}
	}

	/**
	 * Exact day count between two dates.
	 *
	 * @param string $start_ymd Start date (Y-m-d).
	 * @param string $end_ymd   End date (Y-m-d).
	 * @return int Number of nights/days.
	 */
	public static function diff_days( string $start_ymd, string $end_ymd ): int {
		try {
			$d1 = new \DateTimeImmutable( $start_ymd . ' 00:00:00', self::timezone() );
			$d2 = new \DateTimeImmutable( $end_ymd . ' 00:00:00', self::timezone() );
			return (int) $d1->diff( $d2 )->format( '%r%a' );
		} catch ( \Throwable $e ) {
			$t1 = strtotime( $start_ymd );
			$t2 = strtotime( $end_ymd );
			return ( $t1 && $t2 ) ? (int) round( ( $t2 - $t1 ) / DAY_IN_SECONDS ) : 0;
		}
	}

	/**
	 * Accurate, timezone-safe human time difference string.
	 *
	 * @param int|string $from Past or future timestamp or date string.
	 * @param int|string $to   Reference timestamp or date string (default: current time).
	 * @return string Localized human time diff (e.g. "5 mins", "2 hours").
	 */
	public static function human_diff( mixed $from, mixed $to = 'now' ): string {
		$from_ts = is_numeric( $from ) ? (int) $from : strtotime( (string) $from );
		$to_ts   = ( 'now' === $to || null === $to ) ? time() : ( is_numeric( $to ) ? (int) $to : strtotime( (string) $to ) );

		if ( ! $from_ts || ! $to_ts ) {
			return '';
		}

		return human_time_diff( $from_ts, $to_ts );
	}

	/**
	 * Validate and normalize a 24-hour HH:MM time string.
	 *
	 * @param string $raw      Raw time string.
	 * @param string $fallback Fallback HH:MM if invalid.
	 * @return string Normalized HH:MM string.
	 */
	public static function sanitize_hhmm( string $raw, string $fallback = '14:00' ): string {
		$trimmed = trim( $raw );
		if ( 1 === preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $trimmed, $m ) ) {
			return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		}
		return $fallback;
	}

	/**
	 * Convert a 24-hour HH:MM string into integer minutes since midnight (0-1439).
	 *
	 * @param string $hhmm     Time string.
	 * @param int    $fallback Fallback minutes if invalid.
	 * @return int Minutes since midnight.
	 */
	public static function parse_hhmm_minutes( string $hhmm, int $fallback = 840 ): int {
		$norm = self::sanitize_hhmm( $hhmm, '' );
		if ( '' === $norm ) {
			return max( 0, min( 1439, $fallback ) );
		}
		$parts = explode( ':', $norm );
		return ( (int) $parts[0] * 60 ) + (int) $parts[1];
	}

	/**
	 * Format integer minutes since midnight (0-1439) as zero-padded HH:MM.
	 *
	 * @param int $minutes Minutes since midnight.
	 * @return string Formatted HH:MM string.
	 */
	public static function format_minutes_hhmm( int $minutes ): string {
		$clamped = max( 0, min( 1439, $minutes ) );
		$hours   = intdiv( $clamped, 60 );
		$mins    = $clamped % 60;
		return sprintf( '%02d:%02d', $hours, $mins );
	}

	/**
	 * Get configured standard check-in time (e.g. "14:00").
	 */
	public static function get_checkin_time(): string {
		return self::sanitize_hhmm( (string) get_option( 'mhbo_checkin_time', '14:00' ), '14:00' );
	}

	/**
	 * Get configured standard check-out time (e.g. "11:00").
	 */
	public static function get_checkout_time(): string {
		return self::sanitize_hhmm( (string) get_option( 'mhbo_checkout_time', '11:00' ), '11:00' );
	}

/**
	 * Get the configured maximum advance booking window in days.
	 *
	 * Clamped between 14 days and 730 days (2 years). Default is 365 (1 year).
	 * Filterable via 'mhbo_max_advance_booking_days'.
	 *
	 * @return int Number of days in advance guests can book.
	 */
	public static function get_max_advance_booking_days(): int {
		$days = (int) get_option( 'mhbo_max_advance_booking_days', 365 );
		if ( $days < 14 || $days > 730 ) {
			$days = 365;
		}
		return (int) apply_filters( 'mhbo_max_advance_booking_days', $days );
	}

	/**
	 * Get initial fast-load calendar horizon in days (Tier 1 progressive preload).
	 *
	 * Defaults to 120 days (~4 months), or the max advance window if smaller.
	 *
	 * @return int Number of days to generate for the initial fast calendar render.
	 */
	public static function get_initial_calendar_days(): int {
		$max_days = self::get_max_advance_booking_days();
		$initial  = min( 120, $max_days );
		return (int) apply_filters( 'mhbo_initial_calendar_days', $initial, $max_days );
	}

	/**
	 * Get the maximum allowable check-in date in the hotel timezone.
	 *
	 * @return \DateTimeImmutable Maximum check-in date (inclusive).
	 */
	public static function get_max_advance_booking_date(): \DateTimeImmutable {
		$days = self::get_max_advance_booking_days();
		return ( new \DateTimeImmutable( self::today(), self::timezone() ) )->modify( "+{$days} days" );
	}

	/**
	 * Validate whether a check-in date is within the allowable advance booking window.
	 *
	 * @param string $check_in Check-in date in Y-m-d format.
	 * @return bool True if date is on or before the maximum advance booking date.
	 */
	public static function is_within_advance_booking_window( string $check_in ): bool {
		if ( '' === $check_in ) {
			return false;
		}
		$max_date = self::get_max_advance_booking_date()->format( 'Y-m-d' );
		return $check_in <= $max_date;
	}

	/**
	 * Strictly validate a booking check-in / check-out date range against calendar format,
	 * chronological ordering, hard maximum stay nights (DoS guard), and advance booking window.
	 *
	 * @param string $check_in               Check-in date (Y-m-d).
	 * @param string $check_out              Check-out date (Y-m-d).
	 * @param bool   $enforce_advance_window Whether to enforce the property advance booking window.
	 * @return int|\WP_Error Positive night count on success, WP_Error on invalid or unbounded range.
	 */
	public static function validate_date_range( string $check_in, string $check_out, bool $enforce_advance_window = true ): int|\WP_Error {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $check_in ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $check_out ) ) {
			return new \WP_Error( 'mhbo_invalid_dates', __( 'Please provide valid check-in and check-out dates in YYYY-MM-DD format.', 'modern-hotel-booking' ), array( 'status' => 400 ) );
		}

		$utc    = new \DateTimeZone( 'UTC' );
		$dt_in  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $check_in, $utc );
		$dt_out = \DateTimeImmutable::createFromFormat( '!Y-m-d', $check_out, $utc );

		if ( ! $dt_in instanceof \DateTimeImmutable || ! $dt_out instanceof \DateTimeImmutable
			|| $dt_in->format( 'Y-m-d' ) !== $check_in || $dt_out->format( 'Y-m-d' ) !== $check_out ) {
			return new \WP_Error( 'mhbo_invalid_dates', __( 'Invalid calendar date supplied.', 'modern-hotel-booking' ), array( 'status' => 400 ) );
		}

		if ( $dt_out <= $dt_in ) {
			return new \WP_Error( 'mhbo_invalid_range', __( 'Check-out date must be after check-in date.', 'modern-hotel-booking' ), array( 'status' => 400 ) );
		}

		$nights    = (int) $dt_in->diff( $dt_out )->days;
		$max_stays = Pricing::get_max_stay_nights();
		if ( $nights <= 0 || $nights > $max_stays ) {
			return new \WP_Error(
				'mhbo_stay_too_long',
				sprintf(
					/* translators: %d: maximum stay nights */
					__( 'Stays cannot exceed %d nights per reservation.', 'modern-hotel-booking' ),
					absint( $max_stays )
				),
				array( 'status' => 400 )
			);
		}

		if ( $enforce_advance_window && ! self::is_within_advance_booking_window( $check_in ) ) {
			$max_window_human = self::format_advance_window_human( self::get_max_advance_booking_days() );
			return new \WP_Error(
				'mhbo_advance_limit_exceeded',
				sprintf( I18n::get_label( 'label_advance_limit_exceeded' ), $max_window_human ),
				array( 'status' => 400 )
			);
		}

		return $nights;
	}

	/**
	 * Get a human-readable localized string for an advance window in days.
	 *
	 * @param int $days Number of days.
	 * @return string Formatted string (e.g. "1 year", "6 months", "30 days").
	 */
	public static function format_advance_window_human( int $days ): string {
		switch ( $days ) {
			case 730:
				return __( '2 years', 'modern-hotel-booking' );
			case 540:
				return __( '18 months', 'modern-hotel-booking' );
			case 365:
				return __( '1 year', 'modern-hotel-booking' );
			case 270:
				return __( '9 months', 'modern-hotel-booking' );
			case 180:
				return __( '6 months', 'modern-hotel-booking' );
			case 90:
				return __( '3 months', 'modern-hotel-booking' );
			case 60:
				return __( '2 months', 'modern-hotel-booking' );
			case 30:
				return __( '1 month', 'modern-hotel-booking' );
		}
		if ( 0 === $days % 365 ) {
			$years = (int) ( $days / 365 );
			return sprintf(
				/* translators: %d: Number of years */
				_n( '%d year', '%d years', $years, 'modern-hotel-booking' ),
				$years
			);
		}
		if ( 0 === $days % 30 ) {
			$months = (int) ( $days / 30 );
			return sprintf(
				/* translators: %d: Number of months */
				_n( '%d month', '%d months', $months, 'modern-hotel-booking' ),
				$months
			);
		}
		return sprintf(
			/* translators: %d: Number of days */
			_n( '%d day', '%d days', $days, 'modern-hotel-booking' ),
			$days
		);
	}
}
