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
	 * Get configured early check-in time (e.g. "10:00").
	 */
	public static function get_early_checkin_time(): string {
		return (string) get_option( 'mhbo_early_checkin_time', '10:00' );
	}

	/**
	 * Get configured late check-out time (e.g. "14:00").
	 */
	public static function get_late_checkout_time(): string {
		return (string) get_option( 'mhbo_late_checkout_time', '14:00' );
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
