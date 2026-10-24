<?php declare(strict_types=1);

namespace MHBO\Core;

use MHBO\Core\HotelTime;
// AUDITOR: This file uses MHBO\Core\Money for all production financial calculations.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pricing
 * Handles pricing calculations, seasonal rules, and caching.
 */
class Pricing {

	/**
	 * Default lock timeout in seconds for atomic booking operations.
	 */
	public const LOCK_TIMEOUT = 10;

	/**
	 * Runtime static cache for get_booking_payment_amounts() to avoid redundant ledger parsing.
	 *
	 * @var array<string, array{total: Money, deposit: Money, payment: Money, received: Money, remaining: Money, deposit_received: bool, is_paid: bool, due_now: Money, due_type: string, is_overdue: bool, is_urgent_unpaid: bool, effective_payment_status: string, effective_payment_status_label: string}>
	 */
	private static array $payment_amounts_cache = array();

	/**
	 * Get room pricing data with caching.
	 *
	 * @param int $room_id Room ID.
	 * @return \stdClass|null Room data object.
	 */
	public static function get_room_pricing_data( int $room_id ): ?\stdClass {
		$version   = Cache::get_prices_version();
		$cache_key = 'mhbo_room_policy_' . $room_id . '_' . $version;
		$cached    = wp_cache_get( $cache_key, 'mhbo' );

		if ( false !== $cached ) {
			return ( $cached instanceof \stdClass ) ? $cached : null;
		}

		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- result is cached above via wp_cache_get/set
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.*, t.base_price, t.max_adults, t.max_children, t.child_age_free_limit, t.child_rate
             FROM {$wpdb->prefix}mhbo_rooms r
             JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
             WHERE r.id = %d",
				$room_id
			)
		);

		if ( $row ) {
			wp_cache_set( $cache_key, $row, 'mhbo', 86400 );
		}

		return $row;
	}

	/**
	 * Get the configured number of decimal places for displaying prices and monetary totals.
	 *
	 * Supports:
	 * - '2': 2 Decimal Places (Default, e.g. 100.00)
	 * - '0': 0 Decimal Places (Whole numbers, e.g. 100, JPY)
	 * - '3': 3 Decimal Places (e.g. 100.000, KWD/BHD)
	 * - '4': 4 Decimal Places (Accounting / Micro, e.g. 100.0000)
	 * - 'auto': Matches the ISO-4217 currency specification.
	 *
	 * @param string|null $currency Currency code. Defaults to site currency.
	 * @return int Number of decimal places.
	 */
	public static function get_price_decimals( ?string $currency = null ): int {
		$currency = $currency ?? self::get_currency_code();
		$setting  = (string) get_option( 'mhbo_price_decimals', '2' );

		if ( 'auto' === $setting ) {
			$decimals = Money::get_precision( $currency );
		} elseif ( is_numeric( $setting ) ) {
			$decimals = max( 0, min( 6, (int) $setting ) );
		} else {
			$decimals = 2;
		}

		return (int) apply_filters( 'mhbo_price_decimals', $decimals, $currency );
	}

	/**
	 * Format a price amount according to site-wide currency, position, and decimal settings.
	 * Precision-safe: processes Money objects and string decimals without floating-point degradation.
	 *
	 * @param float|string|Money $amount           The price amount.
	 * @param bool               $include_tax_note Whether to append tax notes if applicable.
	 * @param int|null           $decimals_override Optional override for decimal places.
	 * @return string Formatted price string.
	 */
	public static function format_price( float|string|Money $amount, bool $include_tax_note = false, ?int $decimals_override = null ): string {
		$currency_symbol = get_option( 'mhbo_currency_symbol', '$' );
		$position        = get_option( 'mhbo_currency_position', 'before' );
		$currency        = ( $amount instanceof Money ) ? $amount->getCurrency() : self::get_currency_code();
		$decimals        = $decimals_override ?? self::get_price_decimals( $currency );

		if ( $amount instanceof Money ) {
			$decimal_str = $amount->toDecimal( $decimals );
		} elseif ( is_string( $amount ) || is_numeric( $amount ) ) {
			$clean_str = str_replace( ',', '.', trim( (string) $amount ) );
			if ( '' !== $clean_str && is_numeric( $clean_str ) ) {
				$temp_money  = Money::fromDecimal( $clean_str, $currency );
				$decimal_str = $temp_money->toDecimal( $decimals );
			} else {
				$decimal_str = number_format( 0, $decimals, '.', '' );
			}
		} else {
			$decimal_str = number_format( 0, $decimals, '.', '' );
		}

		$decimal_separator  = (string) apply_filters( 'mhbo_currency_decimal_separator', '.' );
		$thousand_separator = (string) apply_filters( 'mhbo_currency_thousand_separator', ',' );

		$is_neg    = str_starts_with( $decimal_str, '-' );
		$abs_dec   = ltrim( $decimal_str, '-' );
		$parts     = explode( '.', $abs_dec );
		$int_part  = $parts[0] !== '' ? $parts[0] : '0';
		$frac_part = $parts[1] ?? '';

		// Format integer part with thousands separator without float casting
		$rev           = strrev( $int_part );
		$chunks        = str_split( $rev, 3 );
		$int_formatted = strrev( implode( $thousand_separator, $chunks ) );

		$formatted = ( $decimals > 0 && '' !== $frac_part )
			? $int_formatted . $decimal_separator . $frac_part
			: $int_formatted;

		if ( $is_neg && '0' !== $int_formatted ) {
			$formatted = '-' . $formatted;
		}

		if ( $include_tax_note ) {
			$tax_note = (string) get_option( 'mhbo_tax_note', '' );
			if ( '' !== $tax_note ) {
				$formatted .= ' ' . $tax_note;
			}
		}

		// Smart Space Logic: Add space if symbol is multi-character or alphanumeric (e.g. RON)
		$add_space = strlen( $currency_symbol ) > 1 || preg_match( '/^[a-zA-Z0-9]+$/', $currency_symbol );
		$add_space = (bool) apply_filters( 'mhbo_currency_add_space', $add_space, $currency_symbol );
		$space     = $add_space ? ' ' : '';

		if ( 'before' === $position ) {
			return $currency_symbol . $space . $formatted;
		}

		return $formatted . $space . $currency_symbol;
	}

	/**
	 * Get currency position.
	 *
	 * @return string 'before' or 'after'.
	 */
	public static function get_currency_position(): string {
		return get_option( 'mhbo_currency_position', 'before' );
	}

	/**
	 * Get currency symbol.
	 *
	 * @return string Currency symbol.
	 */
	public static function get_currency_symbol(): string {
		return get_option( 'mhbo_currency_symbol', '$' );
	}

	/**
	 * Clear pricing cache.
	 */
	public static function clear_cache(): void {
		Cache::bump( Cache::TABLE_PRICING_RULES );
	}

	/**
	 * Force-initialize Pro pricing rules.
	 *
	 * Used during AJAX and submission request lifecycles where the main plugin 
	 * init might have missed specific Pro feature hooks due to late loading 
	 * or build-level conditional logic.
	 */
	public static function ensure_pro_init(): void {
		/**
		 * Fires when the pricing system needs to ensure all Pro rules (seasonal, weekend)
		 * are correctly hooked and ready for calculation.
		 *
		 * @since 2.2.8
		 */
		do_action( 'mhbo_pro_pricing_init' );
	}

	/**
	 * Find an available room for a given type and date range.
	 *
	 * @param int    $type_id   Room type ID.
	 * @param string $check_in  Check-in date (YYYY-MM-DD).
	 * @param string $check_out Check-out date (YYYY-MM-DD).
	 * @param int    $guests    Number of guests (adults + children).
	 * @return int|false Room ID if found, false otherwise.
	 */
	/**
	 * Get all available rooms of a type for a date range.
	 *
	 * @param int    $type_id   Type ID.
	 * @param string $check_in  Check-in date.
	 * @param string $check_out Check-out date.
	 * @return int[] Array of room IDs.
	 */
	public static function get_available_unit_ids_of_type( int $type_id, string $check_in, string $check_out ): array {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rooms     = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}mhbo_rooms WHERE type_id = %d ORDER BY id ASC", $type_id ) );
		$available = array();
		foreach ( $rooms as $r ) {
			if ( true === self::is_room_available( (int) $r->id, $check_in, $check_out ) ) {
				$available[] = (int) $r->id;
			}
		}
		return $available;
	}

	/**
	 * Find a single available room of a type. (Legacy/Simple)
	 */
	public static function find_available_room( int $type_id, string $check_in, string $check_out, int $guests = 1 ): int|false {
		$available = self::get_available_unit_ids_of_type( $type_id, $check_in, $check_out );
		return array() !== $available ? $available[0] : false;
	}

/**
	 * Bulk prime the room cache for a list of IDs.
	 *
	 * @param array<int> $room_ids Array of room IDs.
	 */
	public static function prime_room_cache( array $room_ids ): void {
		global $wpdb;
		if ( array() === $room_ids ) {
			return;
		}

		$room_ids = array_map( 'absint', $room_ids );
		$version  = Cache::get_prices_version();

		// 2026 BP: Rule 13 - No dynamic SQL templates. Use placeholders for the IN clause.
		$in_clause = implode( ',', array_fill( 0, count( $room_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in_clause is a deterministic '%d' token string via array_fill.
		$query = $wpdb->prepare( 'SELECT r.*, t.base_price, t.max_adults, t.max_children, t.child_age_free_limit, t.child_rate FROM ' . $wpdb->prefix . 'mhbo_rooms r JOIN ' . $wpdb->prefix . 'mhbo_room_types t ON r.type_id = t.id WHERE r.id IN (' . $in_clause . ')', ...$room_ids );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $query );

		foreach ( $results as $room ) {
			$key = 'mhbo_room_policy_' . $room->id . '_' . $version;
			wp_cache_set( $key, $room, 'mhbo', 86400 );
		}
	}

	/**
	 * Get currency code.
	 *
	 * @return string Currency code.
	 */
	public static function get_currency_code(): string {
		return (string) get_option( 'mhbo_currency_code', 'USD' );
	}

	/**
	 * Get a single day's price for a room as a Money object.
	 * Prefers room-level custom_price over type base_price.
	 * Applies the 'mhbo_calculate_stay_price_money' filter so Pro seasonal rules
	 * (SeasonalRates) can modify the price without touching this Core class.
	 *
	 * @param int    $room_id Room ID.
	 * @param string $date    Date (YYYY-MM-DD).
	 * @return Money Money object.
	 */
	public static function calculate_daily_price_money( int $room_id, string $date ): Money {
		$room = self::get_room_pricing_data( $room_id );
		if ( ! $room ) {
			return Money::fromCents( 0, self::get_currency_code() );
		}

		// Prefer room-level custom price when set (> 0)
		$has_custom = isset( $room->custom_price ) && '' !== (string) $room->custom_price && Money::fromDecimal( (string) $room->custom_price, self::get_currency_code() )->isPositive();
		$raw = $has_custom
			? (string) $room->custom_price
			: (string) $room->base_price;

		$price = Money::fromDecimal( $raw, self::get_currency_code() );

		// Allow Pro seasonal/weekend rules to hook in without touching Core
		/** @var Money $price */
		$filtered = apply_filters( 'mhbo_calculate_stay_price_money', $price, $room_id, $date );

		return ( $filtered instanceof Money ) ? $filtered : $price;
	}

	// -------------------------------------------------------------------------
	// Availability & Concurrency
	// -------------------------------------------------------------------------

	/**
	 * Resolve effective minimum stay for a room and check-in date, considering Orphan Gap Auto-Correction.
	 *
	 * @param int    $room_id   Room ID.
	 * @param string $check_in  Check-in date (Y-m-d).
	 * @return int|null Effective minimum stay in nights.
	 */
	public static function get_effective_min_stay( int $room_id, string $check_in ): ?int {
		$min_stay = null;
		if ( class_exists( 'ProRemoved' ) ) {
			$min_stay = ProRemoved::resolve_min_stay( $room_id, $check_in );
		} else {
			$min_stay = (int) get_option( 'mhbo_global_min_stay', 0 );
			if ( $min_stay <= 0 ) {
				$min_stay = null;
			}
		}

		if ( $min_stay === null ) {
			return null;
		}

		$gap_autocorrect = (int) get_option( 'mhbo_orphan_gap_autocorrection', 0 );
		if ( $gap_autocorrect !== 1 ) {
			return $min_stay;
		}

		$gap_min = (int) get_option( 'mhbo_orphan_gap_min_stay', 1 );

		// Count contiguous available nights starting at $check_in
		$dt               = new \DateTime( $check_in );
		$remaining_in_gap = 0;
		$max_lookahead    = 60;
		$today            = wp_date( 'Y-m-d' );

		for ( $k = 0; $k < $max_lookahead; $k++ ) {
			$curr_date = $dt->format( 'Y-m-d' );
			$next_date = ( clone $dt )->modify( '+1 day' )->format( 'Y-m-d' );

			if ( $curr_date < $today ) {
				break;
			}

			$avail = self::is_room_available( $room_id, $curr_date, $next_date );
			if ( true !== $avail ) {
				break;
			}

			++$remaining_in_gap;
			$dt->modify( '+1 day' );
		}

		if ( $min_stay > $remaining_in_gap && $remaining_in_gap >= $gap_min && $remaining_in_gap > 0 ) {
			return $remaining_in_gap;
		}

		return $min_stay;
	}

	/**
	 * Check if a room is available for a date range.
	 * Must bypass object cache — live read only (prevents double-booking).
	 *
	 * @param int    $room_id            Room ID.
	 * @param string $check_in           Check-in date (YYYY-MM-DD).
	 * @param string $check_out          Check-out date (YYYY-MM-DD).
	 * @param int    $exclude_booking_id Booking ID to exclude (for edit flows).
	 * @return bool|string True if available; I18n label key on conflict.
	 */
	public static function is_room_available( int $room_id, string $check_in, string $check_out, int $exclude_booking_id = 0 ): bool|string {
		global $wpdb;

		// Fast pre-flight: a room marked 'disabled' (Out of Order) is never bookable,
		// regardless of whether any bookings exist for the date range.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$room_status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d LIMIT 1",
				$room_id
			)
		);
		if ( 'disabled' === $room_status ) {
			return 'label_room_not_available';
		}

		// 2026 BP: Respect same-day turnover setting (Synchronization Fix)
		$prevent_same_day = (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1;

		if ( $exclude_booking_id > 0 ) {
			if ( $prevent_same_day ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$conflict = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND (status != 'pending_payment' OR created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AND check_in <= DATE(%s) AND check_out >= DATE(%s) AND id != %d",
						$room_id,
						$check_out,
						$check_in,
						$exclude_booking_id
					)
				);
			} else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$conflict = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND (status != 'pending_payment' OR created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AND check_in < DATE(%s) AND check_out > DATE(%s) AND id != %d",
						$room_id,
						$check_out,
						$check_in,
						$exclude_booking_id
					)
				);
			}
		} elseif ( $prevent_same_day ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$conflict = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND (status != 'pending_payment' OR created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AND check_in <= DATE(%s) AND check_out >= DATE(%s)",
						$room_id,
						$check_out,
						$check_in
					)
				);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$conflict = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE room_id = %d AND status != 'cancelled' AND (status != 'pending_payment' OR created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AND check_in < DATE(%s) AND check_out > DATE(%s)",
					$room_id,
					$check_out,
					$check_in
				)
			);
		}

		if ( $conflict ) {
			return 'label_room_not_available';
		}

return true;
	}

	/**
	 * Acquire a MySQL advisory lock for atomic booking.
	 *
	 * @param int $room_id Room ID.
	 * @param int $timeout Timeout in seconds.
	 * @return bool True if lock acquired.
	 */
	public static function acquire_booking_lock( int $room_id, int $timeout = 10 ): bool {
		global $wpdb;

		// Detect SQLite/Playground where GET_LOCK is unsupported.
		// In these environments, we bypass the advisory lock as concurrency is typically handled
		$wpdb_class = is_object( $wpdb ) ? get_class( $wpdb ) : '';
		$is_sqlite  = ( str_contains( strtolower( $wpdb_class ), 'sqlite' ) );
		if ( $is_sqlite ) {
			return true;
		}

		$lock_name = 'mhbo_booking_lock_' . $room_id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock, not a data query.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, $timeout ) );

		// If the DB doesn't support GET_LOCK (returns NULL or errors), fallback to true.
		if ( null === $result ) {
			return true;
		}

		return '1' === (string) $result;
	}

	/**
	 * Release a MySQL advisory lock.
	 *
	 * @param int $room_id Room ID.
	 */
	public static function release_booking_lock( int $room_id ): void {
		global $wpdb;

		$wpdb_class = is_object( $wpdb ) ? get_class( $wpdb ) : '';
		$is_sqlite  = ( str_contains( strtolower( $wpdb_class ), 'sqlite' ) );
		if ( $is_sqlite ) {
			return;
		}

		$lock_name = 'mhbo_booking_lock_' . $room_id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	}

	// -------------------------------------------------------------------------
	// Core Pricing Engine (Money-Native, 2026 BP)
	// -------------------------------------------------------------------------

	/**
	 * Normalize extras payload from various frontend representations into a canonical map.
	 *
	 * Handles:
	 * - Associative map: ['extra_1' => '1', 'extra_2' => '2']
	 * - Form prefixed map: ['mhbo_extras[extra_1]' => '1']
	 * - List of active IDs: ['extra_1', 'extra_2']
	 * - JSON string representation
	 *
	 * @param mixed $raw Raw input.
	 * @return array<string, string> Normalized map of extra_id => quantity string.
	 */
	public static function normalize_extras( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				$raw = array();
			}
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$normalized = array();
		$is_list    = array_is_list( $raw );

		foreach ( $raw as $key => $val ) {
			if ( $is_list ) {
				// Form: ['extra_id_1', 'extra_id_2']
				$clean_id = sanitize_key( (string) $val );
				if ( '' !== $clean_id ) {
					$normalized[ $clean_id ] = '1';
				}
			} else {
				// Form: ['extra_id' => '1'] or ['mhbo_extras[extra_id]' => '1']
				$str_key = (string) $key;
				if ( preg_match( '/(?:mhbo_extras\[)?([^\]]+)\]?/', $str_key, $m ) ) {
					$clean_id = sanitize_key( $m[1] );
				} else {
					$clean_id = sanitize_key( $str_key );
				}

				if ( '' === $clean_id ) {
					continue;
				}

				if ( is_bool( $val ) ) {
					$clean_val = $val ? '1' : '0';
				} elseif ( is_numeric( $val ) ) {
					$clean_val = (string) max( 0, absint( $val ) );
				} else {
					$clean_val = sanitize_text_field( (string) $val );
				}

				if ( '0' !== $clean_val && '' !== $clean_val ) {
					$normalized[ $clean_id ] = $clean_val;
				}
			}
		}

		return $normalized;
	}

	/**
	 * Decode a stored `booking_extras` column value into a list/map of extras objects.
	 *
	 * The column has historically been written in two incompatible shapes:
	 *  - canonical object shape: [{name, price, quantity, total}] or {id: {name, quantity, ...}}
	 *  - legacy id=>qty map: {"5": "1"} (flattened by normalize_extras() for pricing math)
	 *
	 * Only the canonical object shape is readable by the admin edit form and the email/invvoice
	 * formatters. A legacy id=>qty map cannot be reconstructed (no name/price history), so it is
	 * dropped and logged rather than fataling the page.
	 *
	 * @param mixed $raw Raw column value (string JSON, null, or already-decoded value).
	 * @return array<string|int, array<string, mixed>> Map/array of extras objects keyed by id (or sequential), each with at least a `name` key.
	 */
	public static function decode_stored_extras( mixed $raw ): array {
		if ( empty( $raw ) ) {
			return array();
		}

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
		} else {
			$decoded = $raw;
		}

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$result     = array();
		$has_legacy = false;

		foreach ( $decoded as $key => $item ) {
			if ( is_array( $item ) && isset( $item['name'] ) ) {
				$result[ $key ] = $item;
			} elseif ( is_string( $item ) || is_int( $item ) || is_float( $item ) ) {
				$has_legacy = true;
			}
		}

		if ( $has_legacy ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for corrupt legacy data
			error_log( 'MHBO: booking_extras stored in legacy id=>qty map shape; ignored and not displayed.' );
		}

		return $result;
	}

	/**
	 * Compute the amount received and outstanding balance for a booking from its
	 * payment flags/amounts. This is the single canonical source of truth used by
	 * emails and invoices so totals always reflect deposits/balance payments that
	 * have actually landed. The `remaining_balance` column is only written at booking
	 * creation and on manual reconciliation, so reading it directly produces stale
	 * totals after a deposit or balance payment is collected via webhook.
	 *
	 * @param object $booking Booking row (stdClass) with total_price, deposit_amount,
	 *                         payment_amount, payment_received, deposit_received, payment_status.
	 * @return array{total: Money, deposit: Money, payment: Money, received: Money, remaining: Money, deposit_received: bool, is_paid: bool, due_now: Money, due_type: string, is_overdue: bool, is_urgent_unpaid: bool, effective_payment_status: string, effective_payment_status_label: string}
	 */
	public static function get_booking_payment_amounts( object $booking ): array {
		$bk_id     = (int) ( $booking->id ?? 0 );
		$cache_key = '';
		if ( $bk_id > 0 ) {
			$cache_key = $bk_id . '_' . md5(
				(string) ( $booking->payment_ledger ?? '' ) .
				(string) ( $booking->payment_amount ?? '' ) .
				(string) ( $booking->remaining_balance ?? '' ) .
				(string) ( $booking->payment_status ?? '' ) .
				(string) ( $booking->deposit_received ?? '' ) .
				(string) ( $booking->total_price ?? '' )
			);
			if ( isset( self::$payment_amounts_cache[ $cache_key ] ) ) {
				return self::$payment_amounts_cache[ $cache_key ];
			}
		}

		$currency       = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$total          = Money::fromDecimal( (string) ( $booking->total_price ?? '0' ), $currency );
		$deposit        = Money::fromDecimal( (string) ( $booking->deposit_amount ?? '0' ), $currency );
		$payment        = Money::fromDecimal( (string) ( $booking->payment_amount ?? '0' ), $currency );
		$payment_type   = (string) ( $booking->payment_type ?? 'full' );
		$payment_status = (string) ( $booking->payment_status ?? 'pending' );
		$balance_status = (string) ( $booking->balance_status ?? 'pending' );

		// Inspect payment ledger for real recorded transactions
		$raw_ledger         = $booking->payment_ledger ?? null;
		$ledger_total       = Money::fromCents( 0, $currency );
		$has_ledger_entries = false;
		$ledger_has_deposit = false;

		if ( ! empty( $raw_ledger ) ) {
			$entries = self::get_payment_ledger( $booking );
			if ( ! empty( $entries ) ) {
				$has_ledger_entries = true;
				foreach ( $entries as $entry ) {
					$entry_amt = Money::fromDecimal( (string) ( $entry['amount'] ?? 0 ), $currency );
					$e_type    = strtolower( (string) ( $entry['type'] ?? '' ) );
					if ( in_array( $e_type, array( 'refund', 'credit_refund' ), true ) || $entry_amt->isNegative() ) {
						$ledger_total = $ledger_total->subtract( $entry_amt->abs() );
					} else {
						$ledger_total = $ledger_total->add( $entry_amt );
					}
					if ( in_array( $e_type, array( 'deposit', 'initial_deposit' ), true ) ) {
						$ledger_has_deposit = true;
					}
				}
				if ( $ledger_total->isNegative() ) {
					$ledger_total = Money::fromCents( 0, $currency );
				}
			}
		}

		// Detect whether this booking involves a partial deposit
		$is_deposit_booking = ( $deposit->isPositive() && $deposit->lessThan( $total ) )
			|| ( 'deposit' === $payment_type && $deposit->lessThan( $total ) )
			|| ( (bool) ( $booking->deposit_received ?? false ) && $payment->lessThan( $total ) );

		// Detect if deposit was truly received with an audit trace
		$deposit_received = false;
		if ( $has_ledger_entries ) {
			$deposit_received = $ledger_has_deposit || ( $is_deposit_booking && $ledger_total->greaterThanOrEqual( $deposit ) );
		} elseif ( $payment->isPositive() && ( $payment->greaterThanOrEqual( $deposit ) || 'deposit_paid' === $payment_status ) ) {
			$deposit_received = true;
		} elseif ( (bool) ( $booking->deposit_received ?? false ) ) {
			$deposit_received = true;
		} elseif ( ! empty( $booking->payment_transaction_id ) && in_array( $payment_status, array( 'completed', 'deposit_paid' ), true ) ) {
			$deposit_received = true;
		}

		// Determine received amount according to USALI Folio Truth (Law 59)
		if ( $has_ledger_entries ) {
			$received = $ledger_total;
		} elseif ( $payment->isPositive() ) {
			$received = $payment;
		} elseif ( (bool) ( $booking->deposit_received ?? false ) && $deposit->isPositive() ) {
			// Legacy row explicitly marked deposit_received = 1
			$received = $deposit;
		} else {
			$received = Money::fromCents( 0, $currency );
		}

		// Determine if balance was truly collected/paid (guard against table schema default balance_status = 'collected')
		$balance_really_paid = false;
		if ( $received->greaterThanOrEqual( $total ) && $total->isPositive() ) {
			$balance_really_paid = true;
		} elseif ( ! empty( $booking->balance_payment_amount ) && Money::fromDecimal( (string) $booking->balance_payment_amount, $currency )->isPositive() && ( ! isset( $booking->remaining_balance ) || Money::fromDecimal( (string) $booking->remaining_balance, $currency )->isZero() ) && $received->greaterThanOrEqual( $total ) ) {
			$balance_really_paid = true;
		} elseif ( ! empty( $booking->balance_collected_via ) && in_array( $balance_status, array( 'collected', 'paid' ), true ) && ( ! isset( $booking->remaining_balance ) || Money::fromDecimal( (string) $booking->remaining_balance, $currency )->isZero() ) && $received->greaterThanOrEqual( $total ) ) {
			$balance_really_paid = true;
		} elseif ( 'paid' === $balance_status && ( ! isset( $booking->remaining_balance ) || Money::fromDecimal( (string) $booking->remaining_balance, $currency )->isZero() ) && $received->greaterThanOrEqual( $total ) ) {
			$balance_really_paid = true;
		} elseif ( 'completed' === $payment_status && ( (bool) ( $booking->payment_received ?? false ) ) && $received->greaterThanOrEqual( $total ) ) {
			$balance_really_paid = true;
		}

		if ( $balance_really_paid ) {
			$remaining = Money::fromCents( 0, $currency );
			$is_paid   = true;
		} else {
			$remaining = $total->subtract( $received );
			if ( $remaining->isNegative() ) {
				$remaining = Money::fromCents( 0, $currency );
			}
			$is_paid = ! $remaining->isPositive();
		}

		$today     = HotelTime::today();
		$check_in  = (string) ( $booking->check_in ?? '' );
		$check_out = (string) ( $booking->check_out ?? '' );

		$is_overdue       = false;
		$is_urgent_unpaid = false;

		if ( ! $is_paid && ! in_array( (string) ( $booking->status ?? '' ), array( 'cancelled', 'refunded' ), true ) ) {
			if ( '' !== $check_out ) {
				if ( $today > $check_out ) {
					$is_urgent_unpaid = true;
				} elseif ( $today === $check_out ) {
					$is_overdue = true;
				}
			}
		}

		// Amount Due Now calculation based on date/time and stay progression
		$due_now  = $remaining;
		$due_type = 'full';

		if ( $is_paid ) {
			$due_now  = Money::fromCents( 0, $currency );
			$due_type = 'none';
		} elseif ( '' !== $check_out && $today >= $check_out ) {
			// Stay completed: Full remaining balance is overdue/urgent
			$due_now  = $remaining;
			$due_type = 'full';
		} elseif ( $is_deposit_booking && ! $deposit_received && $deposit->isPositive() && $deposit->lessThan( $total ) ) {
			// Deposit has not been received yet: Required deposit is due now to confirm/secure the booking
			$due_now  = $deposit;
			$due_type = 'deposit';
		} elseif ( '' !== $check_in && $today >= $check_in ) {
			// Stay started and deposit was already paid: remaining balance is due
			$due_now  = $remaining;
			$due_type = 'full';
		} else {
			// Stay in the future:
			$due_now  = $remaining;
			$due_type = $deposit_received ? 'balance' : 'full';
		}

		// Effective Payment Status
		$effective_payment_status = $payment_status;
		if ( $is_paid ) {
			$effective_payment_status = 'completed';
		} elseif ( $is_urgent_unpaid ) {
			$effective_payment_status = 'urgent_unpaid';
		} elseif ( $is_overdue ) {
			$effective_payment_status = 'overdue';
		} elseif ( $deposit_received ) {
			$effective_payment_status = 'deposit_paid';
		} else {
			$effective_payment_status = 'pending';
		}

		$result = array(
			'total'                          => $total,
			'deposit'                        => $deposit,
			'payment'                        => $payment,
			'received'                       => $received,
			'remaining'                      => $remaining,
			'deposit_received'               => $deposit_received,
			'is_paid'                        => $is_paid,
			'due_now'                        => $due_now,
			'due_type'                       => $due_type,
			'is_overdue'                     => $is_overdue,
			'is_urgent_unpaid'               => $is_urgent_unpaid,
			'effective_payment_status'       => $effective_payment_status,
			'effective_payment_status_label' => I18n::translate_payment_status( $effective_payment_status ),
		);

		if ( '' !== $cache_key ) {
			self::$payment_amounts_cache[ $cache_key ] = $result;
		}

		return $result;
	}

	/**
	 * Invalidate the runtime payment amounts cache.
	 *
	 * @return void
	 */
	public static function clear_payment_amounts_cache(): void {
		self::$payment_amounts_cache = array();
	}

	/**
	 * Get active sibling bookings sharing a multi-room parent group token.
	 *
	 * @param \stdClass|object $booking Booking row.
	 * @return list<\stdClass> Sibling booking rows ordered by ID (empty when not grouped).
	 */
	public static function get_multi_room_siblings( object $booking ): array {
		global $wpdb;

		$parent = trim( (string) ( $booking->multi_room_parent ?? '' ) );
		if ( '' === $parent ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, t.name AS room_name, r.room_number, r.type_id AS room_type_id
				 FROM {$wpdb->prefix}mhbo_bookings b
				 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
				 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
				 WHERE b.multi_room_parent = %s
				   AND b.status NOT IN ('cancelled', 'refunded', 'deleted')
				 ORDER BY b.id ASC",
				$parent
			)
		);

		/** @var list<\stdClass> $result */
		$result = is_array( $rows ) ? array_values( $rows ) : array();
		return $result;
	}

	/**
	 * Aggregate payment amounts across a multi-room group reservation.
	 *
	 * Returns null when the booking is not part of a multi-room group
	 * (fewer than two active sibling bookings). Otherwise the aggregates
	 * mirror get_booking_payment_amounts() semantics across all rooms so
	 * invoices, balance portals, and notifications bill the whole stay.
	 *
	 * @param object $booking Booking row (any member of the group).
	 * @return array{
	 *     bookings: array<int, object>,
	 *     ids: array<int, int>,
	 *     rooms_count: int,
	 *     parent_token: string,
	 *     total: Money,
	 *     deposit: Money,
	 *     received: Money,
	 *     remaining: Money,
	 *     due_now: Money,
	 *     due_type: string,
	 *     is_paid: bool,
	 *     is_overdue: bool,
	 *     is_urgent_unpaid: bool,
	 *     transactions: array<string, array<string, mixed>>
	 * }|null Aggregated group amounts, or null when not a group booking.
	 */
	public static function get_group_payment_amounts( object $booking ): ?array {
		$siblings = self::get_multi_room_siblings( $booking );

		if ( count( $siblings ) < 2 ) {
			return null;
		}

		$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );

		$total             = Money::fromCents( 0, $currency );
		$deposit           = Money::fromCents( 0, $currency );
		$received          = Money::fromCents( 0, $currency );
		$remaining         = Money::fromCents( 0, $currency );
		$deposit_due_sum   = Money::fromCents( 0, $currency );

		$is_paid       = true;
		$is_overdue    = false;
		$is_urgent     = false;
		$has_full_due  = false;

		$ids = array();

		foreach ( $siblings as $sib ) {
			$ids[] = (int) $sib->id;
			$amts  = self::get_booking_payment_amounts( $sib );

			$total     = $total->add( $amts['total'] );
			$deposit   = $deposit->add( $amts['deposit'] );
			$received  = $received->add( $amts['received'] );
			$remaining = $remaining->add( $amts['remaining'] );

			if ( ! $amts['is_paid'] ) {
				$is_paid = false;
				if ( 'deposit' === $amts['due_type'] && $amts['due_now']->isPositive() ) {
					$deposit_due_sum = $deposit_due_sum->add( $amts['due_now'] );
				} elseif ( 'none' !== $amts['due_type'] ) {
					$has_full_due = true;
				}
			}

			if ( $amts['is_overdue'] ) {
				$is_overdue = true;
			}
			if ( $amts['is_urgent_unpaid'] ) {
				$is_urgent = true;
			}
		}

		if ( $is_paid || ( ! $remaining->isPositive() ) ) {
			$is_paid   = true;
			$due_now   = Money::fromCents( 0, $currency );
			$due_type  = 'none';
		} elseif ( $has_full_due || ! $deposit_due_sum->isPositive() ) {
			$due_now   = $remaining;
			$due_type  = 'full';
		} else {
			$due_now   = $deposit_due_sum;
			$due_type  = 'deposit';
		}

		return array(
			'bookings'         => $siblings,
			'ids'              => $ids,
			'rooms_count'      => count( $siblings ),
			'parent_token'     => trim( (string) ( $booking->multi_room_parent ?? '' ) ),
			'total'            => $total,
			'deposit'          => $deposit,
			'received'         => $received,
			'remaining'        => $remaining,
			'due_now'          => $due_now,
			'due_type'         => $due_type,
			'is_paid'          => $is_paid,
			'is_overdue'       => $is_overdue,
			'is_urgent_unpaid' => $is_urgent,
			'transactions'     => self::get_group_transaction_allocations( $siblings ),
		);
	}

	/**
	 * Map payments and transactions across a multi-room group reservation.
	 *
	 * Detects transactions shared/split across multiple rooms (e.g. single Stripe PaymentIntent
	 * paying balances across multiple rooms).
	 *
	 * @param array<int, object> $siblings Sibling booking objects.
	 * @return array<string, array{transaction_id: string, method: string, total_amount: float, currency: string, is_split: bool, allocations: array<int, array{booking_id: int, room_number: string, room_name: string, amount: float, type: string}>}>
	 */
	public static function get_group_transaction_allocations( array $siblings ): array {
		$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$txn_map  = array();

		foreach ( $siblings as $sib ) {
			$ledger = self::get_payment_ledger( $sib );
			foreach ( $ledger as $entry ) {
				$txn = trim( (string) ( $entry['transaction_id'] ?? '' ) );
				if ( '' === $txn ) {
					continue;
				}
				if ( ! isset( $txn_map[ $txn ] ) ) {
					$txn_map[ $txn ] = array(
						'transaction_id' => $txn,
						'method'         => (string) ( $entry['method'] ?? '' ),
						'total_amount'   => 0.0,
						'total_amount_m' => Money::fromCents( 0, $currency ),
						'currency'       => (string) ( $entry['currency'] ?? $currency ),
						'is_split'       => false,
						'allocations'    => array(),
					);
				}
				$entry_m = Money::fromDecimal( (string) ( $entry['amount'] ?? 0 ), $currency );
				/** @var Money $curr_total */
				$curr_total = $txn_map[ $txn ]['total_amount_m'];
				$txn_map[ $txn ]['total_amount_m'] = $curr_total->add( $entry_m );
				$txn_map[ $txn ]['allocations'][]  = array(
					'booking_id'  => (int) ( $sib->id ?? 0 ),
					'room_number' => (string) ( $sib->room_number ?? '' ),
					'room_name'   => class_exists( I18n::class ) ? ( (string) ( I18n::decode( (string) ( $sib->room_name ?? '' ) ) ?? '' ) ) : (string) ( $sib->room_name ?? '' ),
					'amount'      => floatval( $entry_m->toDecimal() ),
					'type'        => (string) ( $entry['type'] ?? 'payment' ),
				);
			}
		}

		foreach ( $txn_map as &$item ) {
			/** @var Money $tot_m */
			$tot_m               = $item['total_amount_m'];
			$item['total_amount'] = floatval( $tot_m->toDecimal() );
			unset( $item['total_amount_m'] );
			$item['is_split']    = count( $item['allocations'] ) > 1;
		}
		unset( $item );

		return $txn_map;
	}

	/**
	 * Get the itemized payment ledger for a booking.
	 *
	 * Decodes `payment_ledger` JSON or synthesizes from legacy payment fields.
	 *
	 * @param object $booking
	 * @return array<int, array{id: string, type: string, amount: string, currency: string, method: string, transaction_id: string, date: string, note: string, staff?: string}>
	 */
	public static function get_payment_ledger( object $booking ): array {
		$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );

		if ( ! empty( $booking->payment_ledger ) ) {
			$raw = is_string( $booking->payment_ledger ) ? json_decode( $booking->payment_ledger, true ) : $booking->payment_ledger;
			if ( is_array( $raw ) && ! empty( $raw ) ) {
				// Self-healing guard (Law 59): strip any phantom pre-payment intent entry
				// (e.g. an unpaid Stripe pi_... or synthetic fallback entry recorded alongside a verified gateway payment).
				if ( count( $raw ) > 1 ) {
					$has_verified_gateway = false;
					foreach ( $raw as $candidate ) {
						if ( ! empty( $candidate['staff'] ) && 'System' !== (string) $candidate['staff'] ) {
							$has_verified_gateway = true;
							break;
						}
					}
					$filtered = array();
					foreach ( $raw as $entry ) {
						$e_txn    = trim( (string) ( $entry['transaction_id'] ?? '' ) );
						$e_method = strtolower( trim( (string) ( $entry['method'] ?? '' ) ) );
						$e_staff  = trim( (string) ( $entry['staff'] ?? '' ) );
						$e_note   = trim( (string) ( $entry['note'] ?? '' ) );

						// A Stripe PaymentIntent ID ('pi_...') on a non-Stripe method ('braintree', 'paypal', 'arrival') is an unpaid pre-checkout intent.
						if ( str_starts_with( $e_txn, 'pi_' ) && '' !== $e_method && 'stripe' !== $e_method ) {
							continue;
						}
						// A legacy synthesized entry (missing 'staff' attribute and generic 'Deposit Payment' / 'Payment Received' note)
						// that co-exists with a verified gateway entry is a duplicate pre-payment artifact.
						if ( $has_verified_gateway && '' === $e_staff && in_array( $e_note, array( 'Deposit Payment', 'Payment Received', __( 'Deposit Payment', 'modern-hotel-booking' ), __( 'Payment Received', 'modern-hotel-booking' ) ), true ) ) {
							continue;
						}
						$filtered[] = $entry;
					}
					if ( ! empty( $filtered ) ) {
						$cleaned = $filtered;
						if ( count( $cleaned ) < count( $raw ) && ! empty( $booking->id ) ) {
							global $wpdb;
							$healed_paid_m = Money::fromCents( 0, $currency );
							foreach ( $cleaned as $c_entry ) {
								$c_amt  = Money::fromDecimal( (string) ( $c_entry['amount'] ?? 0 ), $currency );
								$c_type = strtolower( (string) ( $c_entry['type'] ?? '' ) );
								if ( in_array( $c_type, array( 'refund', 'credit_refund' ), true ) || $c_amt->isNegative() ) {
									$healed_paid_m = $healed_paid_m->subtract( $c_amt->abs() );
								} else {
									$healed_paid_m = $healed_paid_m->add( $c_amt );
								}
							}
							if ( $healed_paid_m->isNegative() ) {
								$healed_paid_m = Money::fromCents( 0, $currency );
							}
							$tot_m = Money::fromDecimal( (string) ( $booking->total_price ?? 0 ), $currency );
							$rem_m = $tot_m->subtract( $healed_paid_m );
							if ( $rem_m->isNegative() ) {
								$rem_m = Money::fromCents( 0, $currency );
							}
							$encoded_cleaned            = (string) wp_json_encode( $cleaned );
							$booking->payment_ledger    = $encoded_cleaned;
							$booking->payment_amount    = $healed_paid_m->toDecimal();
							$booking->remaining_balance = $rem_m->toDecimal();
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
							$wpdb->update(
								"{$wpdb->prefix}mhbo_bookings",
								array(
									'payment_ledger'          => $encoded_cleaned,
									'payment_amount'          => $healed_paid_m->toDecimal(),
									'remaining_balance'       => $rem_m->toDecimal(),
									'balance_expected_amount' => $rem_m->toDecimal(),
								),
								array( 'id' => (int) $booking->id )
							);
						}
						return $cleaned;
					}
				}
				return $raw;
			}
		}

		// Synthesize ledger from legacy payment fields for backward compatibility
		$ledger         = array();
		$b_id           = (int) ( $booking->id ?? 0 );
		$paid_m         = Money::fromDecimal( (string) ( $booking->payment_amount ?? 0 ), $currency );
		$payment_status = strtolower( (string) ( $booking->payment_status ?? '' ) );

		$dep_rcvd = (bool) ( $booking->deposit_received ?? false );
		$dep_m    = Money::fromDecimal( (string) ( $booking->deposit_amount ?? 0 ), $currency );

		// Fallback for bookings where payment_status = completed but payment_amount was never populated
		if ( ! $paid_m->isPositive() && ( 'completed' === $payment_status || ! empty( $booking->payment_received ) ) ) {
			$paid_m = Money::fromDecimal( (string) ( $booking->total_price ?? 0 ), $currency );
		}

		// Fallback for legacy bookings where deposit_received is explicitly 1 or payment_status is deposit_paid, but payment_amount is 0.
		// Law 46 & Law 59: NEVER treat an unpaid payment_transaction_id (e.g. pending Stripe pi_... or PayPal order ID) as paid cash.
		if ( ! $paid_m->isPositive() && ( $dep_rcvd || 'deposit_paid' === $payment_status ) && $dep_m->isPositive() ) {
			$paid_m   = $dep_m;
			$dep_rcvd = true;
		}

		if ( $paid_m->isPositive() ) {
			$bal_amt_m = Money::fromDecimal( (string) ( $booking->balance_payment_amount ?? 0 ), $currency );

			if ( $bal_amt_m->isPositive() && $dep_rcvd && $paid_m->greaterThan( $bal_amt_m ) ) {
				// First payment: deposit
				$dep_amt_m = $paid_m->subtract( $bal_amt_m );
				$ledger[]  = array(
					'id'             => 'pay_' . $b_id . '_1',
					'type'           => 'deposit',
					'amount'         => $dep_amt_m->toDecimal(),
					'currency'       => $currency,
					'method'         => (string) ( $booking->payment_method ?? 'arrival' ),
					'transaction_id' => (string) ( $booking->payment_transaction_id ?? '' ),
					'date'           => (string) ( $booking->payment_date ?? ( $booking->created_at ?? '' ) ),
					'note'           => __( 'Deposit Payment', 'modern-hotel-booking' ),
				);
				// Second payment: balance
				$ledger[]  = array(
					'id'             => 'pay_' . $b_id . '_2',
					'type'           => 'balance',
					'amount'         => $bal_amt_m->toDecimal(),
					'currency'       => $currency,
					'method'         => (string) ( $booking->balance_collected_via ?? ( $booking->payment_method ?? 'arrival' ) ),
					'transaction_id' => (string) ( $booking->balance_intent_id ?? '' ),
					'date'           => (string) ( $booking->balance_payment_date ?? '' ),
					'note'           => __( 'Balance Settlement', 'modern-hotel-booking' ),
				);
			} else {
				$type = ( $dep_rcvd && 'completed' !== ( $booking->payment_status ?? '' ) ) ? 'deposit' : 'payment';
				$note = ( 'deposit' === $type ) ? __( 'Deposit Payment', 'modern-hotel-booking' ) : __( 'Payment Received', 'modern-hotel-booking' );
				$ledger[] = array(
					'id'             => 'pay_' . $b_id . '_1',
					'type'           => $type,
					'amount'         => $paid_m->toDecimal(),
					'currency'       => $currency,
					'method'         => (string) ( $booking->payment_method ?? 'arrival' ),
					'transaction_id' => (string) ( $booking->payment_transaction_id ?? '' ),
					'date'           => (string) ( $booking->payment_date ?? ( $booking->created_at ?? '' ) ),
					'note'           => $note,
				);
			}
		}

		return $ledger;
	}

	/**
	 * Atomically append a payment entry to the booking's payment ledger.
	 *
	 * @param int          $booking_id Booking ID.
	 * @param float|string $amount     Payment amount.
	 * @param string       $method     Payment method.
	 * @param string       $type       Payment type ('deposit', 'balance', 'charge', 'manual').
	 * @param string       $txn_id     Transaction or reference ID.
	 * @param string       $note       Administrative note.
	 * @param string       $staff      Staff member or origin attribution.
	 * @return bool
	 */
	public static function record_payment( int $booking_id, float|string $amount, string $method, string $type, string $txn_id = '', string $note = '', string $staff = '' ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'mhbo_bookings';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $booking_id ) );
		if ( ! $booking ) {
			return false;
		}

		$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$amount_m = Money::fromDecimal( (string) $amount, $currency );
		if ( $amount_m->isZero() ) {
			return false;
		}

		if ( '' === $staff ) {
			$current_user = wp_get_current_user();
			$staff        = ( $current_user && ! empty( $current_user->display_name ) ) ? $current_user->display_name : __( 'Front Desk / Staff', 'modern-hotel-booking' );
		}

		$ledger     = self::get_payment_ledger( $booking );
		$sanitized_type = sanitize_key( $type );
		$is_refund      = in_array( $sanitized_type, array( 'refund', 'credit_refund' ), true ) || $amount_m->isNegative();
		$entry_amt_str  = $is_refund ? '-' . $amount_m->abs()->toDecimal() : $amount_m->toDecimal();

		$new_entry = array(
			'id'             => 'pay_' . $booking_id . '_' . ( count( $ledger ) + 1 ),
			'type'           => $sanitized_type,
			'amount'         => $entry_amt_str,
			'currency'       => $currency,
			'method'         => sanitize_text_field( $method ),
			'transaction_id' => sanitize_text_field( $txn_id ),
			'date'           => HotelTime::now_mysql(),
			'note'           => sanitize_text_field( $note ),
			'staff'          => sanitize_text_field( $staff ),
		);
		$ledger[]  = $new_entry;

		// Recompute net payment amount from ledger (deducting refunds)
		$total_paid_m = Money::fromCents( 0, $currency );
		foreach ( $ledger as $entry ) {
			$e_amt  = Money::fromDecimal( (string) ( $entry['amount'] ?? 0 ), $currency );
			$e_type = strtolower( (string) ( $entry['type'] ?? '' ) );
			if ( in_array( $e_type, array( 'refund', 'credit_refund' ), true ) || $e_amt->isNegative() ) {
				$total_paid_m = $total_paid_m->subtract( $e_amt->abs() );
			} else {
				$total_paid_m = $total_paid_m->add( $e_amt );
			}
		}
		if ( $total_paid_m->isNegative() ) {
			$total_paid_m = Money::fromCents( 0, $currency );
		}

		$total_price_m = Money::fromDecimal( (string) ( $booking->total_price ?? '0' ), $currency );
		$is_full       = $total_paid_m->greaterThanOrEqual( $total_price_m ) && $total_price_m->isPositive();
		$remaining_m   = $total_price_m->subtract( $total_paid_m );
		if ( $remaining_m->isNegative() ) {
			$remaining_m = Money::fromCents( 0, $currency );
		}

		$existing_txns = array_filter(
			array_map( 'trim', explode( ',', (string) ( $booking->payment_transaction_id ?? '' ) ) ),
			static fn( string $txn ): bool => '' !== $txn
		);
		if ( '' !== $txn_id && ! in_array( $txn_id, $existing_txns, true ) ) {
			$existing_txns[] = $txn_id;
		}

		$calc_status = $is_full
			? 'completed'
			: ( 'deposit' === $sanitized_type
				? 'deposit_paid'
				: ( $total_paid_m->isPositive()
					? 'deposit_paid'
					: ( $is_refund ? 'refunded' : 'pending' )
				)
			);

		$old_status    = (string) ( $booking->status ?? '' );
		$update_fields = array(
			'payment_ledger'          => wp_json_encode( $ledger ),
			'payment_amount'          => $total_paid_m->toDecimal(),
			'remaining_balance'       => $remaining_m->toDecimal(),
			'balance_expected_amount' => $remaining_m->toDecimal(),
			'balance_status'          => $is_full ? 'collected' : 'pending',
			'payment_status'          => $calc_status,
			'payment_received'        => $is_full ? 1 : 0,
			'deposit_received'        => ( 'deposit' === $sanitized_type || ! empty( $booking->deposit_received ) || $total_paid_m->isPositive() ) ? 1 : 0,
			'payment_transaction_id'  => implode( ', ', $existing_txns ),
			'payment_date'            => empty( $booking->payment_date ) ? HotelTime::now_mysql() : $booking->payment_date,
		);

		// If booking was pending and a positive payment is verified, transition to confirmed (Law 46)
		if ( 'pending' === $old_status && $total_paid_m->isPositive() ) {
			$update_fields['status'] = 'confirmed';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			$update_fields,
			array( 'id' => $booking_id )
		);

		Cache::invalidate_booking( $booking_id, (int) ( $booking->room_id ?? 0 ) );
		self::clear_payment_amounts_cache();

		if ( 'pending' === $old_status && $total_paid_m->isPositive() ) {
			do_action( 'mhbo_booking_confirmed', $booking_id );
			do_action( 'mhbo_booking_status_changed', $booking_id, 'confirmed', 'pending' );
		}

		do_action( 'mhbo_payment_recorded', $booking_id, $new_entry, $booking );
		return true;
	}

	/**
	 * Build an authoritative, USALI-compliant folio breakdown for a booking.
	 *
	 * Single source of truth for the admin booking workstation, printable/email
	 * invoices, and guest portals. Reconciles itemized charges (room nights, daily rates,
	 * children, early/late checkin, extras, compulsory fees, service fee, discounts/coupons,
	 * and folio adjustments) with verified payment transactions, balance remaining, and credit balances.
	 *
	 * @param object $booking Booking object (stdClass).
	 * @param string $lang    Optional language code for localized labels.
	 * @return array{
	 *     booking_id: int,
	 *     customer_name: string,
	 *     customer_email: string,
	 *     customer_phone: string,
	 *     check_in: string,
	 *     check_out: string,
	 *     check_in_formatted: string,
	 *     check_out_formatted: string,
	 *     nights: int,
	 *     room_id: int,
	 *     room_name: string,
	 *     room_number: string,
	 *     guests: int,
	 *     children: int,
	 *     children_ages: array<int, int>,
	 *     currency: string,
	 *     charges: array<int, array{
	 *         id: string,
	 *         label: string,
	 *         name?: string,
	 *         category: string,
	 *         category_label?: string,
	 *         qty: int|float,
	 *         quantity?: int|float,
	 *         unit_price: float|string,
	 *         unit_price_formatted: string,
	 *         total: float|string,
	 *         amount?: float|string,
	 *         total_formatted: string,
	 *         amount_formatted?: string,
	 *         tax_rate: float,
	 *         tax_amount: float|string,
	 *         tax_formatted: string,
	 *         is_negative: bool,
	 *         details: string
	 *     }>,
	 *     daily_prices: array<int, array{date: string, amount: float, formatted: string}>,
	 *     subtotal_net: float,
	 *     subtotal_net_formatted: string,
	 *     tax_enabled: bool,
	 *     tax_mode: string,
	 *     tax_label: string,
	 *     tax_included: bool,
	 *     tax_registration_number: string,
	 *     accommodation_tax: float,
	 *     accommodation_tax_formatted: string,
	 *     extras_tax: float,
	 *     extras_tax_formatted: string,
	 *     total_tax: float,
	 *     total_tax_formatted: string,
	 *     total_charges: float,
	 *     total_charges_formatted: string,
	 *     total_price: float,
	 *     total_price_formatted: string,
	 *     payments: array<int, array{
	 *         id: string,
	 *         date: string,
	 *         date_formatted: string,
	 *         type: string,
	 *         type_label: string,
	 *         method: string,
	 *         method_label: string,
	 *         transaction_id: string,
	 *         staff: string,
	 *         note: string,
	 *         is_refund: bool,
	 *         amount: float,
	 *         amount_formatted: string
	 *     }>,
	 *     total_paid: float,
	 *     total_paid_formatted: string,
	 *     balance_remaining: float,
	 *     balance_remaining_formatted: string,
	 *     amount_remaining: float,
	 *     amount_remaining_formatted: string,
	 *     credit_balance: float,
	 *     credit_balance_formatted: string,
	 *     is_overpaid: bool,
	 *     is_paid_in_full: bool,
	 *     deposit_amount: float,
	 *     deposit_amount_formatted: string,
	 *     deposit_required: bool,
	 *     deposit_received: bool,
	 *     due_now: float,
	 *     due_now_formatted: string,
	 *     due_type: string,
	 *     is_overdue: bool,
	 *     is_urgent_unpaid: bool,
	 *     status_key: string,
	 *     status_label: string
	 * }
	 */
	public static function get_folio_breakdown( object $booking, string $lang = '', ?string $tax_mode = null ): array {
		global $wpdb;

		if ( '' === $lang && class_exists( I18n::class ) ) {
			$lang = ! empty( $booking->booking_language ) ? (string) $booking->booking_language : I18n::get_current_language();
		}

		$currency   = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$booking_id = (int) ( $booking->id ?? 0 );
		$check_in   = (string) ( $booking->check_in ?? '' );
		$check_out  = (string) ( $booking->check_out ?? '' );
		$ts_in      = strtotime( $check_in );
		$ts_out     = strtotime( $check_out );
		$nights     = ( $ts_in && $ts_out && $ts_out > $ts_in ) ? (int) round( ( $ts_out - $ts_in ) / DAY_IN_SECONDS ) : 1;

		$room_id     = (int) ( $booking->room_id ?? 0 );
		$room_number = (string) ( $booking->room_number ?? '' );
		$room_name   = (string) ( $booking->room_name ?? '' );

		if ( '' === $room_name && $room_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$r_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT r.room_number, t.name AS room_name
					 FROM {$wpdb->prefix}mhbo_rooms r
					 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
					 WHERE r.id = %d",
					$room_id
				)
			);
			if ( $r_row ) {
				$room_number = (string) ( $r_row->room_number ?? '' );
				$room_name   = (string) ( $r_row->room_name ?? '' );
			}
		}
		if ( class_exists( I18n::class ) && '' !== $room_name ) {
			$room_name = (string) ( I18n::decode( $room_name, $lang ) ?? $room_name );
		}

		// Tax mode and rates
		$global_tax_enabled = class_exists( Tax::class ) && Tax::is_enabled();
		if ( null === $tax_mode || '' === $tax_mode ) {
			if ( ! $global_tax_enabled ) {
				$tax_mode = class_exists( Tax::class ) ? Tax::MODE_DISABLED : 'disabled';
			} else {
				$tax_mode = sanitize_key( $booking->tax_mode ?? Tax::get_mode() );
				if ( '' === $tax_mode ) {
					$tax_mode = Tax::get_mode();
				}
			}
		} else {
			$tax_mode = sanitize_key( $tax_mode );
		}
		$tax_enabled    = class_exists( Tax::class ) && Tax::MODE_DISABLED !== $tax_mode;
		$is_tax_vat     = in_array( $tax_mode, array( 'vat', 'inclusive' ), true );
		$base_tax_label = class_exists( Tax::class ) ? Tax::get_label( $lang, $tax_mode ) : 'Tax';
		$tax_label      = $is_tax_vat ? sprintf( I18n::get_label( 'label_tax_included' ), $base_tax_label ) : $base_tax_label;
		$acc_rate       = $tax_enabled ? ( isset( $booking->tax_rate_accommodation ) && '' !== (string) $booking->tax_rate_accommodation ? floatval( $booking->tax_rate_accommodation ) : ( class_exists( Tax::class ) ? (float) Tax::get_accommodation_rate() : 0.0 ) ) : 0.0;
		$ext_rate       = $tax_enabled ? ( isset( $booking->tax_rate_extras ) && '' !== (string) $booking->tax_rate_extras ? floatval( $booking->tax_rate_extras ) : ( class_exists( Tax::class ) ? (float) Tax::get_extras_rate() : 0.0 ) ) : 0.0;

		// 1. Room accommodation & daily rates
		$daily_prices = array();
		$room_money   = Money::fromCents( 0, $currency );

		if ( $room_id > 0 && $ts_in && $ts_out && $ts_out > $ts_in ) {
			for ( $i = 0; $i < $nights; $i++ ) {
				$cur_date    = HotelTime::date( 'Y-m-d', HotelTime::midnight( $check_in ) + $i * DAY_IN_SECONDS );
				$day_price_m = self::calculate_daily_price_money( $room_id, $cur_date );
				$daily_prices[] = array(
					'date'      => $cur_date,
					'amount'    => (float) $day_price_m->toDecimal(),
					'formatted' => I18n::format_currency( $day_price_m ),
				);
				$room_money = $room_money->add( $day_price_m );
			}
		}

		// Stored booking-time breakdown is the audit trail; decode once for room and children reuse.
		$tb_decoded = array();
		if ( ! empty( $booking->tax_breakdown ) ) {
			$tb_raw = is_string( $booking->tax_breakdown ) ? json_decode( $booking->tax_breakdown, true ) : (array) $booking->tax_breakdown;
			if ( is_array( $tb_raw ) ) {
				$tb_decoded = $tb_raw;
			}
		}

		// Prefer the room amount stored at booking time: live rates/overrides may have changed since, which would
		// otherwise fabricate "concession"/"adjustment" lines. Stored room is post-coupon, so add the coupon back
		// (the coupon is itemised as its own negative line below).
		$stored_room_net_m = Money::fromDecimal( (string) ( $booking->room_total_net ?? '0' ), $currency );
		$stored_room_tax_m = Money::fromDecimal( (string) ( $booking->room_tax ?? '0' ), $currency );
		$stored_mode_vat   = in_array( sanitize_key( (string) ( $booking->tax_mode ?? '' ) ), array( 'vat', 'inclusive' ), true );
		if ( $stored_room_net_m->isPositive() && ( $stored_mode_vat === $is_tax_vat || ! $stored_room_tax_m->isPositive() ) ) {
			$room_money = $is_tax_vat ? $stored_room_net_m->add( $stored_room_tax_m ) : $stored_room_net_m;
			$room_money = $room_money->add( Money::fromDecimal( (string) ( $tb_decoded['coupon_reductions']['room_reduction'] ?? '0' ), $currency ) );
		}
		if ( ! $room_money->isPositive() && ! empty( $booking->total_price ) && Money::fromDecimal( (string) $booking->total_price, $currency )->isPositive() ) {
			$room_money = Money::fromDecimal( (string) $booking->total_price, $currency );
		}

		$charges         = array();
		/* translators: %s: room number */
		$room_unit_str   = '' !== $room_number ? sprintf( __( 'Room #%s', 'modern-hotel-booking' ), $room_number ) : '';
		$room_title_full = trim( $room_name . ( '' !== $room_unit_str ? ' (' . $room_unit_str . ')' : '' ) );
		if ( '' === $room_title_full ) {
			$room_title_full = __( 'Accommodation', 'modern-hotel-booking' );
		}

		$room_calc   = class_exists( Tax::class ) ? Tax::calculate_tax_money( $room_money, $acc_rate, $tax_mode ) : array( 'net' => $room_money, 'tax' => Money::fromCents( 0, $currency ), 'gross' => $room_money );
		$room_avg_m  = ( $nights > 0 ) ? $room_money->divide( (string) $nights ) : $room_money;

		$charges[] = array(
			'id'                   => 'room_stay',
			'label'                => sprintf(
				/* translators: 1: Room title, 2: Number of nights */
				__( '%1$s (%2$d nights)', 'modern-hotel-booking' ),
				$room_title_full,
				$nights
			),
			'category'             => 'room',
			'qty'                  => $nights,
			'unit_price'           => floatval( $room_avg_m->toDecimal() ),
			'unit_price_formatted' => I18n::format_currency( $room_avg_m ),
			'total'                => (float) $room_money->toDecimal(),
			'total_formatted'      => I18n::format_currency( $room_money ),
			'tax_rate'             => $acc_rate,
			'tax_amount'           => (float) $room_calc['tax']->toDecimal(),
			'tax_formatted'        => I18n::format_currency( $room_calc['tax'] ),
			'is_negative'          => false,
			'details'              => sprintf(
				/* translators: 1: Check-in date, 2: Check-out date */
				__( '%1$s to %2$s', 'modern-hotel-booking' ),
				HotelTime::format_date( $check_in ),
				HotelTime::format_date( $check_out )
			),
		);

		// 2. Children
		$children_count = (int) ( $booking->children ?? 0 );
		$children_ages  = ! empty( $booking->children_ages ) ? ( is_array( $booking->children_ages ) ? $booking->children_ages : json_decode( (string) $booking->children_ages, true ) ) : array();
		if ( ! is_array( $children_ages ) ) {
			$children_ages = array();
		}
		$children_net_m   = Money::fromDecimal( (string) ( $booking->children_total_net ?? '0' ), $currency );
		$children_tax_col = Money::fromDecimal( (string) ( $booking->children_tax ?? '0' ), $currency );
		$children_total_m = ( $is_tax_vat && $children_tax_col->isPositive() )
			? $children_net_m->add( $children_tax_col )
			: $children_net_m;
		if ( isset( $tb_decoded['coupon_reductions']['children_reduction'] ) ) {
			$ch_red_m = Money::fromDecimal( (string) $tb_decoded['coupon_reductions']['children_reduction'], $currency );
			if ( $ch_red_m->isPositive() ) {
				$children_total_m = $children_total_m->add( $ch_red_m );
			}
		}

		if ( $children_count > 0 ) {
			/* translators: %s: Comma-separated list of child ages */
			$ages_str = ! empty( $children_ages ) ? sprintf( __( 'Ages: %s', 'modern-hotel-booking' ), implode( ', ', $children_ages ) ) : '';
			$ch_calc  = class_exists( Tax::class ) ? Tax::calculate_tax_money( $children_total_m, $acc_rate, $tax_mode ) : array( 'net' => $children_total_m, 'tax' => Money::fromCents( 0, $currency ), 'gross' => $children_total_m );

			$ch_details = '' !== $ages_str ? $ages_str : '';
			if ( ! $children_total_m->isPositive() ) {
				$ch_details .= ( '' !== $ch_details ? ' • ' : '' ) . __( 'Complimentary under hotel family policy', 'modern-hotel-booking' );
			}

			$ch_unit_m = $children_total_m->isPositive() ? $children_total_m->divide( (string) $children_count ) : Money::fromCents( 0, $currency );
			$charges[] = array(
				'id'                   => 'children_occupancy',
				'label'                => sprintf(
					/* translators: %d: number of children */
					_n( 'Children Occupancy (%d child)', 'Children Occupancy (%d children)', $children_count, 'modern-hotel-booking' ),
					$children_count
				),
				'category'             => 'children',
				'qty'                  => $children_count,
				'unit_price'           => floatval( $ch_unit_m->toDecimal() ),
				'unit_price_formatted' => I18n::format_currency( $ch_unit_m ),
				'total'                => (float) $children_total_m->toDecimal(),
				'total_formatted'      => I18n::format_currency( $children_total_m ),
				'tax_rate'             => $acc_rate,
				'tax_amount'           => (float) $ch_calc['tax']->toDecimal(),
				'tax_formatted'        => I18n::format_currency( $ch_calc['tax'] ),
				'is_negative'          => false,
				'details'              => $ch_details,
			);
		}

		// 3. Extras & Early Check-in / Late Check-out
		$extras_breakdown = self::decode_stored_extras( $booking->booking_extras ?? null );
		$saved_custom     = ! empty( $booking->custom_fields ) ? ( is_array( $booking->custom_fields ) ? $booking->custom_fields : json_decode( (string) $booking->custom_fields, true ) ) : array();

		if ( is_array( $saved_custom ) ) {
			if ( ! empty( $saved_custom['_early_checkin'] ) && ! isset( $extras_breakdown['_early_checkin'] ) ) {
				$early_fee_m = Money::fromDecimal( (string) get_option( 'mhbo_early_checkin_fee', '0' ), $currency );
				if ( $early_fee_m->isPositive() ) {
					$extras_breakdown['_early_checkin'] = array(
						'id'       => '_early_checkin',
						'name'     => I18n::get_label( 'label_early_checkin' ) ?: __( 'Early Check-in', 'modern-hotel-booking' ),
						'total'    => $early_fee_m->toDecimal(),
						'price'    => $early_fee_m->toDecimal(),
						'quantity' => 1,
					);
				}
			}
			if ( ! empty( $saved_custom['_late_checkout'] ) && ! isset( $extras_breakdown['_late_checkout'] ) ) {
				$late_fee_m = Money::fromDecimal( (string) get_option( 'mhbo_late_checkout_fee', '0' ), $currency );
				if ( $late_fee_m->isPositive() ) {
					$extras_breakdown['_late_checkout'] = array(
						'id'       => '_late_checkout',
						'name'     => I18n::get_label( 'label_late_checkout' ) ?: __( 'Late Check-out', 'modern-hotel-booking' ),
						'total'    => $late_fee_m->toDecimal(),
						'price'    => $late_fee_m->toDecimal(),
						'quantity' => 1,
					);
				}
			}
		}

		foreach ( $extras_breakdown as $ex_k => $ex_item ) {
			$ex_id   = (string) ( $ex_item['id'] ?? $ex_k );
			$ex_name = (string) ( $ex_item['name'] ?? '' );
			if ( '' === $ex_name ) {
				$ex_name = __( 'Extra Service', 'modern-hotel-booking' );
			}
			$ex_name = class_exists( I18n::class ) ? ( (string) ( I18n::decode( $ex_name, $lang ) ?? $ex_name ) ) : (string) $ex_name;

			$ex_amt_str = isset( $ex_item['total'] ) ? (string) $ex_item['total'] : (string) ( $ex_item['price'] ?? 0 );
			$ex_qty     = isset( $ex_item['quantity'] ) ? max( 1, (int) $ex_item['quantity'] ) : 1;
			$ex_money   = Money::fromDecimal( $ex_amt_str, $currency );
			if ( ! $ex_money->isPositive() ) {
				continue;
			}

			$is_acc_ex     = in_array( $ex_id, array( '_early_checkin', '_late_checkout' ), true );
			$item_tax_rate = $is_acc_ex ? $acc_rate : $ext_rate;
			$item_calc     = class_exists( Tax::class ) ? Tax::calculate_tax_money( $ex_money, $item_tax_rate, $tax_mode ) : array( 'net' => $ex_money, 'tax' => Money::fromCents( 0, $currency ), 'gross' => $ex_money );

			$unit_price_m = ( $ex_qty > 0 ) ? $ex_money->divide( (string) $ex_qty ) : $ex_money;
			$charges[]    = array(
				'id'                   => 'extra_' . sanitize_key( $ex_id ),
				'label'                => $ex_name,
				'category'             => $is_acc_ex ? 'early_late' : 'extra',
				'qty'                  => $ex_qty,
				'unit_price'           => floatval( $unit_price_m->toDecimal() ),
				'unit_price_formatted' => I18n::format_currency( $unit_price_m ),
				'total'                => (float) $ex_money->toDecimal(),
				'total_formatted'      => I18n::format_currency( $ex_money ),
				'tax_rate'             => $item_tax_rate,
				'tax_amount'           => (float) $item_calc['tax']->toDecimal(),
				'tax_formatted'        => I18n::format_currency( $item_calc['tax'] ),
				'is_negative'          => false,
				'details'              => $is_acc_ex ? __( 'Stay schedule extension', 'modern-hotel-booking' ) : ( isset( $ex_item['pricing_type'] ) ? ucfirst( str_replace( '_', ' ', (string) $ex_item['pricing_type'] ) ) : '' ),
			);
		}

		// 4. Service Fee
		$sf_money = Money::fromDecimal( (string) ( $booking->service_fee_amount ?? '0' ), $currency );
		if ( $sf_money->isPositive() ) {
			$sf_label = (string) get_option( 'mhbo_service_fee_label', I18n::get_label( 'label_service_fee' ) );
			if ( '' === $sf_label ) {
				$sf_label = __( 'Service Fee', 'modern-hotel-booking' );
			}
			$sf_calc   = class_exists( Tax::class ) ? Tax::calculate_tax_money( $sf_money, $ext_rate, $tax_mode ) : array( 'net' => $sf_money, 'tax' => Money::fromCents( 0, $currency ), 'gross' => $sf_money );
			$charges[] = array(
				'id'                   => 'service_fee',
				'label'                => $sf_label,
				'category'             => 'fee',
				'qty'                  => 1,
				'unit_price'           => (float) $sf_money->toDecimal(),
				'unit_price_formatted' => I18n::format_currency( $sf_money ),
				'total'                => (float) $sf_money->toDecimal(),
				'total_formatted'      => I18n::format_currency( $sf_money ),
				'tax_rate'             => $ext_rate,
				'tax_amount'           => (float) $sf_calc['tax']->toDecimal(),
				'tax_formatted'        => I18n::format_currency( $sf_calc['tax'] ),
				'is_negative'          => false,
				'details'              => __( 'Hotel standard service charge', 'modern-hotel-booking' ),
			);
		}

		// 5. Coupon / Promotional Discount
		$coupon_code = trim( (string) ( $booking->coupon_code ?? '' ) );
		$disc_col_m  = Money::fromDecimal( (string) ( $booking->discount_amount ?? '0' ), $currency );
		$coup_col_m  = Money::fromDecimal( (string) ( $booking->coupon_discount ?? '0' ), $currency );
		$discount_m  = $disc_col_m->greaterThan( $coup_col_m ) ? $disc_col_m : $coup_col_m;
		if ( $discount_m->isPositive() ) {
			$disc_tax_m = Money::fromCents( 0, $currency );
			if ( $tax_enabled && class_exists( Tax::class ) && $acc_rate > 0 ) {
				$disc_on_room      = Money::fromCents( (string) min( (int) $discount_m->getAmount(), (int) $room_money->getAmount() ), $currency );
				$after_room_m      = $room_money->subtract( $disc_on_room );
				$after_room_calc   = Tax::calculate_tax_money( $after_room_m, $acc_rate, $tax_mode );
				$room_tax_red_m    = $room_calc['tax']->subtract( $after_room_calc['tax'] );
				$rem_disc_m        = $discount_m->subtract( $disc_on_room );
				$child_tax_red_m   = Money::fromCents( 0, $currency );
				if ( $rem_disc_m->isPositive() && $children_total_m->isPositive() ) {
					$disc_on_child   = Money::fromCents( (string) min( (int) $rem_disc_m->getAmount(), (int) $children_total_m->getAmount() ), $currency );
					$after_child_m   = $children_total_m->subtract( $disc_on_child );
					$orig_ch_calc    = Tax::calculate_tax_money( $children_total_m, $acc_rate, $tax_mode );
					$after_ch_calc   = Tax::calculate_tax_money( $after_child_m, $acc_rate, $tax_mode );
					$child_tax_red_m = $orig_ch_calc['tax']->subtract( $after_ch_calc['tax'] );
					$rem_disc_m      = $rem_disc_m->subtract( $disc_on_child );
				}
				$extra_tax_red_m = Money::fromCents( 0, $currency );
				if ( $rem_disc_m->isPositive() && $ext_rate > 0 ) {
					$ex_disc_calc    = Tax::calculate_tax_money( $rem_disc_m, $ext_rate, $tax_mode );
					$extra_tax_red_m = $ex_disc_calc['tax'];
				}
				$disc_tax_m = $room_tax_red_m->add( $child_tax_red_m )->add( $extra_tax_red_m );
			}
			/* translators: %s: Promotional coupon code */
			$disc_label = '' !== $coupon_code ? sprintf( __( 'Coupon / Promo Discount (%s)', 'modern-hotel-booking' ), $coupon_code ) : __( 'Promotional Discount', 'modern-hotel-booking' );
			$charges[]  = array(
				'id'                   => 'discount_coupon',
				'label'                => $disc_label,
				'category'             => 'discount',
				'qty'                  => 1,
				'unit_price'           => - floatval( $discount_m->toDecimal() ),
				'unit_price_formatted' => '−' . I18n::format_currency( $discount_m ),
				'total'                => - floatval( $discount_m->toDecimal() ),
				'total_formatted'      => '−' . I18n::format_currency( $discount_m ),
				'tax_rate'             => $disc_tax_m->isPositive() ? $acc_rate : 0.0,
				'tax_amount'           => $disc_tax_m->isPositive() ? - floatval( $disc_tax_m->toDecimal() ) : 0.0,
				'tax_formatted'        => $disc_tax_m->isPositive() ? ( '−' . I18n::format_currency( $disc_tax_m ) ) : '',
				'is_negative'          => true,
				/* translators: %s: Promotional coupon code */
				'details'              => '' !== $coupon_code ? sprintf( __( 'Voucher code %s applied', 'modern-hotel-booking' ), $coupon_code ) : __( 'Rate reduction concession', 'modern-hotel-booking' ),
			);
		}

		// 6. Mathematical Folio Reconciliation against total_price
		$total_price_m = Money::fromDecimal( (string) ( $booking->total_price ?? '0' ), $currency );
		$sum_charges_m = Money::fromCents( 0, $currency );
		$sum_tax_m     = Money::fromCents( 0, $currency );
		foreach ( $charges as $c_item ) {
			$c_amt = Money::fromDecimal( ltrim( (string) $c_item['total'], '-' ), $currency );
			$c_tax = Money::fromDecimal( ltrim( (string) $c_item['tax_amount'], '-' ), $currency );
			if ( $c_item['is_negative'] ) {
				$sum_charges_m = $sum_charges_m->subtract( $c_amt );
				$sum_tax_m     = $sum_tax_m->subtract( $c_tax );
			} else {
				$sum_charges_m = $sum_charges_m->add( $c_amt );
				$sum_tax_m     = $sum_tax_m->add( $c_tax );
			}
		}

		$is_tax_inclusive        = in_array( $tax_mode, array( 'vat', 'inclusive' ), true );
		$effective_charges_sum_m = ( $tax_enabled && ! $is_tax_inclusive )
			? $sum_charges_m->add( $sum_tax_m )
			: $sum_charges_m;

		$diff_m = $total_price_m->subtract( $effective_charges_sum_m );
		if ( $diff_m->isPositive() ) {
			$adj_calc     = ( $tax_enabled && class_exists( Tax::class ) && ! $is_tax_inclusive && $ext_rate > 0 )
				? Tax::calculate_tax_money( $diff_m, $ext_rate, Tax::MODE_VAT )
				: ( class_exists( Tax::class ) ? Tax::calculate_tax_money( $diff_m, $ext_rate, $tax_mode ) : array( 'net' => $diff_m, 'tax' => Money::fromCents( 0, $currency ), 'gross' => $diff_m ) );
			$adj_line_amt = ( $tax_enabled && ! $is_tax_inclusive ) ? $adj_calc['net'] : $diff_m;
			$charges[] = array(
				'id'                   => 'folio_adjustment',
				'label'                => __( 'Additional Folio Charges / Incidentals', 'modern-hotel-booking' ),
				'category'             => 'adjustment',
				'qty'                  => 1,
				'unit_price'           => floatval( $adj_line_amt->toDecimal() ),
				'unit_price_formatted' => I18n::format_currency( $adj_line_amt ),
				'total'                => floatval( $adj_line_amt->toDecimal() ),
				'total_formatted'      => I18n::format_currency( $adj_line_amt ),
				'tax_rate'             => $ext_rate,
				'tax_amount'           => floatval( $adj_calc['tax']->toDecimal() ),
				'tax_formatted'        => I18n::format_currency( $adj_calc['tax'] ),
				'is_negative'          => false,
				'details'              => __( 'Post-reservation charges or customized stay adjustment', 'modern-hotel-booking' ),
			);
		} elseif ( $diff_m->isNegative() ) {
			$gross_neg_val = $effective_charges_sum_m->subtract( $total_price_m );
			$neg_calc      = ( $tax_enabled && class_exists( Tax::class ) && $acc_rate > 0 )
				? Tax::calculate_tax_money( $gross_neg_val, $acc_rate, Tax::MODE_VAT )
				: array( 'net' => $gross_neg_val, 'tax' => Money::fromCents( 0, $currency ), 'gross' => $gross_neg_val );
			$neg_line_amt  = ( $tax_enabled && ! $is_tax_inclusive ) ? $neg_calc['net'] : $gross_neg_val;
			$neg_tax_amt   = $neg_calc['tax'];
			$charges[]     = array(
				'id'                   => 'folio_concession',
				'label'                => __( 'Special Rate Concession / Folio Adjustment', 'modern-hotel-booking' ),
				'category'             => 'discount',
				'qty'                  => 1,
				'unit_price'           => - floatval( $neg_line_amt->toDecimal() ),
				'unit_price_formatted' => '−' . I18n::format_currency( $neg_line_amt ),
				'total'                => - floatval( $neg_line_amt->toDecimal() ),
				'total_formatted'      => '−' . I18n::format_currency( $neg_line_amt ),
				'tax_rate'             => $neg_tax_amt->isPositive() ? $acc_rate : 0.0,
				'tax_amount'           => $neg_tax_amt->isPositive() ? - floatval( $neg_tax_amt->toDecimal() ) : 0.0,
				'tax_formatted'        => $neg_tax_amt->isPositive() ? ( '−' . I18n::format_currency( $neg_tax_amt ) ) : '',
				'is_negative'          => true,
				'details'              => __( 'Managerial rate concession / customized pricing credit', 'modern-hotel-booking' ),
			);
		}

		// Enrich all charges with normalized alias keys for robust template consumption
		foreach ( $charges as &$c_item ) {
			$c_item['name']             = $c_item['label'];
			$c_item['quantity']         = $c_item['qty'];
			$c_item['amount']           = $c_item['total'];
			$c_item['amount_formatted'] = $c_item['total_formatted'];
			$c_item['category_label']   = match ( $c_item['category'] ) {
				'room'       => __( 'Accommodation', 'modern-hotel-booking' ),
				'children'   => __( 'Child Supplement', 'modern-hotel-booking' ),
				'early_late' => __( 'Stay Extension', 'modern-hotel-booking' ),
				'extra'      => __( 'Stay Enhancement', 'modern-hotel-booking' ),
				'fee'        => __( 'Service Fee', 'modern-hotel-booking' ),
				'discount'   => __( 'Discount / Concession', 'modern-hotel-booking' ),
				default      => __( 'Folio Adjustment', 'modern-hotel-booking' ),
			};
		}
		unset( $c_item );

		// 7. Calculate Net Subtotal and Tax totals
		$tot_tax_m = Money::fromCents( 0, $currency );
		$acc_tax_m = Money::fromCents( 0, $currency );
		$ext_tax_m = Money::fromCents( 0, $currency );

		if ( $tax_enabled ) {
			foreach ( $charges as $c ) {
				$c_tax     = Money::fromDecimal( (string) $c['tax_amount'], $currency );
				$tot_tax_m = $tot_tax_m->add( $c_tax );
				if ( in_array( $c['category'], array( 'room', 'children', 'early_late' ), true ) ) {
					$acc_tax_m = $acc_tax_m->add( $c_tax );
				} elseif ( 'discount' === $c['category'] ) {
					$abs_disc_tax = Money::fromDecimal( ltrim( (string) $c['tax_amount'], '-' ), $currency );
					$on_acc       = Money::fromCents( (string) min( (int) $abs_disc_tax->getAmount(), max( 0, (int) $acc_tax_m->getAmount() ) ), $currency );
					$acc_tax_m    = $acc_tax_m->subtract( $on_acc );
					$on_ext       = $abs_disc_tax->subtract( $on_acc );
					if ( $on_ext->isPositive() ) {
						$ext_tax_m = $ext_tax_m->subtract( $on_ext );
					}
				} else {
					$ext_tax_m = $ext_tax_m->add( $c_tax );
				}
			}
			if ( $tot_tax_m->isNegative() ) {
				$tot_tax_m = Money::fromCents( 0, $currency );
			}
			if ( $acc_tax_m->isNegative() ) {
				$acc_tax_m = Money::fromCents( 0, $currency );
			}
			if ( $ext_tax_m->isNegative() ) {
				$ext_tax_m = Money::fromCents( 0, $currency );
			}
			if ( $tot_tax_m->isZero() ) {
				$tax_enabled = false;
				$is_tax_vat  = false;
			}
		}

		$subtotal_net_m = $tax_enabled ? $total_price_m->subtract( $tot_tax_m ) : $total_price_m;
		if ( ! $tax_enabled ) {
			$tot_tax_m = Money::fromCents( 0, $currency );
			$acc_tax_m = Money::fromCents( 0, $currency );
			$ext_tax_m = Money::fromCents( 0, $currency );
		}
		if ( $subtotal_net_m->isNegative() ) {
			$subtotal_net_m = Money::fromCents( 0, $currency );
		}

		// 8. Payment Ledger & Credits Applied
		$raw_ledger   = self::get_payment_ledger( $booking );
		$payments     = array();
		$total_paid_m = Money::fromCents( 0, $currency );

		foreach ( $raw_ledger as $entry ) {
			$e_amt_m   = Money::fromDecimal( (string) ( $entry['amount'] ?? 0 ), $currency );
			$e_type    = strtolower( (string) ( $entry['type'] ?? 'payment' ) );
			$is_refund = in_array( $e_type, array( 'refund', 'credit_refund' ), true ) || $e_amt_m->isNegative();

			if ( $is_refund ) {
				$abs_amt       = $e_amt_m->abs();
				$total_paid_m  = $total_paid_m->subtract( $abs_amt );
				$amt_formatted = '−' . I18n::format_currency( $abs_amt );
			} else {
				$total_paid_m  = $total_paid_m->add( $e_amt_m );
				$amt_formatted = I18n::format_currency( $e_amt_m );
			}

			$type_label = match ( $e_type ) {
				'deposit' => __( 'Deposit', 'modern-hotel-booking' ),
				'balance' => __( 'Balance Settlement', 'modern-hotel-booking' ),
				'refund', 'credit_refund' => __( 'Refund / Credit', 'modern-hotel-booking' ),
				'incidentals' => __( 'Incidentals / Extras', 'modern-hotel-booking' ),
				default => __( 'Payment', 'modern-hotel-booking' ),
			};

			$payments[] = array(
				'id'               => (string) ( $entry['id'] ?? '' ),
				'date'             => (string) ( $entry['date'] ?? '' ),
				'date_formatted'   => ! empty( $entry['date'] ) ? HotelTime::format_datetime( $entry['date'] ) : '—',
				'type'             => $e_type,
				'type_label'       => $type_label,
				'is_refund'        => $is_refund,
				'method'           => (string) ( $entry['method'] ?? 'arrival' ),
				'method_label'     => I18n::get_payment_method_label( (string) ( $entry['method'] ?? 'arrival' ) ),
				'transaction_id'   => (string) ( $entry['transaction_id'] ?? '' ),
				'staff'            => (string) ( $entry['staff'] ?? __( 'System', 'modern-hotel-booking' ) ),
				'note'             => (string) ( $entry['note'] ?? '' ),
				'amount'           => (float) $e_amt_m->toDecimal(),
				'amount_formatted' => $amt_formatted,
			);
		}

		if ( $total_paid_m->isNegative() ) {
			$total_paid_m = Money::fromCents( 0, $currency );
		}

		// 9. Balance remaining & credit balance
		$is_overpaid     = false;
		$is_paid_in_full = false;
		$credit_bal_m    = Money::fromCents( 0, $currency );
		$balance_rem_m   = $total_price_m->subtract( $total_paid_m );

		if ( $balance_rem_m->isNegative() ) {
			$is_overpaid     = true;
			$credit_bal_m    = $total_paid_m->subtract( $total_price_m );
			$balance_rem_m   = Money::fromCents( 0, $currency );
			$is_paid_in_full = true;
		} elseif ( $balance_rem_m->isZero() && $total_price_m->isPositive() ) {
			$is_paid_in_full = true;
		}

		$deposit_m       = Money::fromDecimal( (string) ( $booking->deposit_amount ?? 0 ), $currency );
		$payment_amounts = self::get_booking_payment_amounts( $booking );

		$status_key   = $payment_amounts['effective_payment_status'];
		$status_label = $payment_amounts['effective_payment_status_label'];
		if ( $is_overpaid ) {
			$status_key   = 'overpaid';
			$status_label = __( 'Overpaid / Credit Balance', 'modern-hotel-booking' );
		}

		return array(
			'booking_id'                  => $booking_id,
			'customer_name'               => (string) ( $booking->customer_name ?? '' ),
			'customer_email'              => (string) ( $booking->customer_email ?? '' ),
			'customer_phone'              => (string) ( $booking->customer_phone ?? '' ),
			'check_in'                    => $check_in,
			'check_out'                   => $check_out,
			'check_in_formatted'          => HotelTime::format_date( $check_in ),
			'check_out_formatted'         => HotelTime::format_date( $check_out ),
			'nights'                      => $nights,
			'room_id'                     => $room_id,
			'room_name'                   => $room_name,
			'room_number'                 => $room_number,
			'guests'                      => (int) ( $booking->guests ?? 1 ),
			'children'                    => $children_count,
			'children_ages'               => $children_ages,
			'currency'                    => $currency,
			'charges'                     => $charges,
			'daily_prices'                => $daily_prices,
			'subtotal_net'                => (float) $subtotal_net_m->toDecimal(),
			'subtotal_net_formatted'      => I18n::format_currency( $subtotal_net_m ),
			'tax_enabled'                 => $tax_enabled,
			'tax_mode'                    => $tax_mode,
			'tax_label'                   => $tax_label,
			'base_tax_label'              => $base_tax_label,
			'is_vat'                      => $is_tax_vat,
			'tax_included'                => in_array( $tax_mode, array( 'vat', 'inclusive' ), true ),
			'tax_registration_number'     => ( class_exists( Tax::class ) ? (string) Tax::get_registration_number() : '' ),
			'accommodation_tax'           => (float) $acc_tax_m->toDecimal(),
			'accommodation_tax_formatted' => I18n::format_currency( $acc_tax_m ),
			'extras_tax'                  => (float) $ext_tax_m->toDecimal(),
			'extras_tax_formatted'        => I18n::format_currency( $ext_tax_m ),
			'total_tax'                   => (float) $tot_tax_m->toDecimal(),
			'total_tax_formatted'         => I18n::format_currency( $tot_tax_m ),
			'total_charges'               => (float) $total_price_m->toDecimal(),
			'total_charges_formatted'     => I18n::format_currency( $total_price_m ),
			'total_price'                 => (float) $total_price_m->toDecimal(),
			'total_price_formatted'       => I18n::format_currency( $total_price_m ),
			'payments'                    => $payments,
			'total_paid'                  => (float) $total_paid_m->toDecimal(),
			'total_paid_formatted'        => I18n::format_currency( $total_paid_m ),
			'balance_remaining'           => (float) $balance_rem_m->toDecimal(),
			'balance_remaining_formatted' => I18n::format_currency( $balance_rem_m ),
			'amount_remaining'            => (float) $balance_rem_m->toDecimal(),
			'amount_remaining_formatted'  => I18n::format_currency( $balance_rem_m ),
			'credit_balance'              => (float) $credit_bal_m->toDecimal(),
			'credit_balance_formatted'    => I18n::format_currency( $credit_bal_m ),
			'is_overpaid'                 => $is_overpaid,
			'is_paid_in_full'             => $is_paid_in_full,
			'deposit_amount'              => (float) $deposit_m->toDecimal(),
			'deposit_amount_formatted'    => I18n::format_currency( $deposit_m ),
			'deposit_required'            => $deposit_m->isPositive() && $deposit_m->lessThan( $total_price_m ),
			'deposit_received'            => (bool) $payment_amounts['deposit_received'],
			'due_now'                     => (float) $payment_amounts['due_now']->toDecimal(),
			'due_now_formatted'           => I18n::format_currency( $payment_amounts['due_now'] ),
			'due_type'                    => $payment_amounts['due_type'],
			'is_overdue'                  => $payment_amounts['is_overdue'],
			'is_urgent_unpaid'            => $payment_amounts['is_urgent_unpaid'],
			'status_key'                  => $status_key,
			'status_label'                => $status_label,
		);
	}

	/**
	 * Calculate complete booking pricing server-side.
	 *
	 * @param int                      $room_id    Room ID.
	 * @param string                   $check_in   Check-in date (Y-m-d).
	 * @param string                   $check_out  Check-out date (Y-m-d).
	 * @param int                      $adults     Number of adults.
	 * @param array<string|int, mixed> $extras     Booking extras.
	 * @param int                      $children   Number of children.
	 * @param array<int, int>          $child_ages Ages of children.
	 * @param string|null              $tax_mode   Optional tax mode override.
	 * @return array<string, mixed>|false
	 */
	public static function calculate_booking_money(
		int $room_id,
		string $check_in,
		string $check_out,
		int $adults,
		array $extras = array(),
		int $children = 0,
		array $child_ages = array(),
		?string $tax_mode = null
	): array|false {
		
		$extras = self::normalize_extras( $extras );
		$room   = self::get_room_pricing_data( $room_id );
		if ( ! $room ) {
			return false;
		}

		$currency = self::get_currency_code();
		$ts_in    = strtotime( $check_in );
		$ts_out   = strtotime( $check_out );

		if ( ! $ts_in || ! $ts_out || $ts_out <= $ts_in ) {
			return false;
		}

		$nights = (int) round( ( $ts_out - $ts_in ) / 86400 );

		// --- Per-night room pricing ---
		$room_total_money = Money::fromCents( 0, $currency );
		$daily_prices     = array();

		for ( $i = 0; $i < $nights; $i++ ) {
			$date             = HotelTime::date( 'Y-m-d', HotelTime::midnight( $check_in ) + $i * DAY_IN_SECONDS );
			$day_price        = self::calculate_daily_price_money( $room_id, $date );
			$daily_prices[]   = $day_price;
			$room_total_money = $room_total_money->add( $day_price );
		}

// --- Occupancy Validation (2026 BP) ---
		$max_adults   = (int) ( $room->max_adults ?? 0 );
		$max_children = (int) ( $room->max_children ?? 0 );

		if ( $adults > $max_adults || $children > $max_children ) {
			return false; // Over-occupancy
		}

		// --- Children pricing (Starter/Pro) ---
		$children_total_money = Money::fromCents( 0, $currency );

// --- Extras pricing (Pro) ---
		$extras_total_money = Money::fromCents( 0, $currency );
		$extras_breakdown   = array();

// --- Pre-fee subtotal (room + children + extras, pre-tax) ---
		$pre_fee_subtotal_money = $room_total_money->add( $children_total_money )->add( $extras_total_money );

		// --- Service Fee (Pro) ---
		$service_fee_money = Money::fromCents( 0, $currency );
		$service_fee_label = '';

// Full subtotal includes service fee (used when tax is disabled)
		$subtotal_money = $pre_fee_subtotal_money->add( $service_fee_money );

		// Build extras list for tax engine (Tax expects indexed array with id/name/total/tax_class)
		$extras_for_tax = array_values(
			array_map(
				static fn( string $id, array $item ): array => array(
					'id'        => $id,
					'name'      => $item['name'],
					'total'     => $item['total']->toDecimal(),
					'tax_class' => $item['tax_class'] ?? 'extras',
				),
				array_keys( $extras_breakdown ),
				$extras_breakdown
			)
		);

		// --- Tax ---
		$tax_data = Tax::calculate_booking_tax(
			array(
				'room_total'        => $room_total_money->toDecimal(),
				'children_total'    => $children_total_money->toDecimal(),
				'extras_total'      => $extras_total_money->toDecimal(),
				'extras'            => $extras_for_tax,
				'service_fee'       => $service_fee_money->toDecimal(),
				'service_fee_label' => $service_fee_label,
			),
			$tax_mode
		);

		// Final total: gross when Sales Tax is active; gross equals subtotal under VAT/disabled
		$total_money = ( $tax_data['totals']['total_gross'] instanceof Money )
			? $tax_data['totals']['total_gross']
			: $subtotal_money;

		return array(
			'total'             => $total_money,
			'subtotal'          => $subtotal_money,
			'room_total'        => $room_total_money,
			'children_total'    => $children_total_money,
			'extras_total'      => $extras_total_money,
			'service_fee'       => $service_fee_money,
			'service_fee_label' => $service_fee_label,
			'daily_prices'      => $daily_prices,
			'nights'            => $nights,
			'extras_breakdown'  => $extras_breakdown,
			'tax'               => $tax_data,
			'is_pro'            => array() !== $extras_breakdown || $children_total_money->isPositive() || $service_fee_money->isPositive(),
		);
	}

	/**
	 * Calculate deposit from a booking total.
	 *
	 * Deposit Tax Lag fix (DREAM_STATE fault #3): deposit is computed on the
	 * post-tax $total, so Sales Tax is already baked in. The $first_night Money
	 * passed by callers is also the post-tax first-night value, ensuring the
	 * "first_night" deposit type never under-collects in Sales Tax mode.
	 *
	 * @param Money $total       Full booking total (post-tax).
	 * @param Money $first_night First night room rate (post-tax, no one-time extras).
	 * @return array{deposit_money: Money, remaining_money: Money, deposit_type: string, deposit_value: float, deposit_type_label: string, is_non_refundable: bool}|false
	 */
	public static function calculate_deposit_money( Money $total, Money $first_night ): array|false {
		if ( $total->isZero() ) {
			return false;
		}

		$deposit_type      = (string) get_option( 'mhbo_deposit_type', 'percentage' );
		$deposit_val_raw   = (string) get_option( 'mhbo_deposit_value', '20' );
		$deposit_value     = is_numeric( $deposit_val_raw ) ? (float) $deposit_val_raw : 20.0;
		$is_non_refundable = (bool) get_option( 'mhbo_deposit_non_refundable', 0 );
		$currency          = $total->getCurrency();

		switch ( $deposit_type ) {
			case 'first_night':
				$deposit_money      = $first_night;
				$deposit_type_label = I18n::get_label( 'label_first_night_rate' );
				break;
			case 'fixed':
				$fixed_m            = Money::fromDecimal( '' !== $deposit_val_raw ? $deposit_val_raw : '20.00', $currency );
				$deposit_money      = $fixed_m->isPositive() ? $fixed_m : Money::fromCents( 1, $currency );
				$deposit_type_label = $deposit_money->format();
				break;
			default: // percentage
				$clamped            = max( 1, min( 100, (int) round( $deposit_value ) ) );
				$multiplier         = function_exists( 'bcdiv' ) ? bcdiv( (string) $clamped, '100', 4 ) : number_format( $clamped / 100, 4, '.', '' );
				$deposit_money      = $total->multiply( $multiplier );
				$deposit_type_label = $clamped . '%';
				break;
		}

		// Clamp: deposit cannot exceed total
		if ( $deposit_money->compare( $total ) > 0 ) {
			$deposit_money = $total;
		}

		$remaining_money = $total->subtract( $deposit_money );

		return array(
			'deposit_money'      => $deposit_money,
			'remaining_money'    => $remaining_money,
			'deposit_type'       => $deposit_type,
			'deposit_value'      => $deposit_value,
			'deposit_type_label' => $deposit_type_label,
			'is_non_refundable'  => $is_non_refundable,
		);
	}

	/**
	 * Resolve the first night Money amount for deposit calculations.
	 *
	 * For the 'first_night' deposit type, calculates 1-night room lodging cost
	 * (including child supplements and applicable accommodation tax, but excluding
	 * one-time extras). For percentage or fixed types, returns zero Money as the
	 * first night rate is not required for percentage/fixed calculations.
	 *
	 * @param int                  $room_id      Room ID or Room Type ID.
	 * @param string               $check_in     Check-in date (YYYY-MM-DD).
	 * @param int                  $guests       Number of adults.
	 * @param string|null          $deposit_type Deposit type ('first_night', 'percentage', 'fixed').
	 * @param array<string, mixed> $extras       Selected extras.
	 * @param int                  $children     Number of children.
	 * @param array<int, int>      $child_ages   Child ages array.
	 * @param string|null          $currency     ISO currency code.
	 * @return Money
	 */
	public static function resolve_first_night_money(
		int $room_id,
		string $check_in,
		int $guests = 1,
		?string $deposit_type = null,
		array $extras = array(),
		int $children = 0,
		array $child_ages = array(),
		?string $currency = null
	): Money {
		$deposit_type = $deposit_type ?? (string) get_option( 'mhbo_deposit_type', 'percentage' );
		$currency     = $currency ?? self::get_currency_code();

		if ( 'first_night' !== $deposit_type ) {
			return Money::fromCents( 0, $currency );
		}

		$first_night_end  = HotelTime::add_days( $check_in, 1 );
		$first_night_calc = self::calculate_booking_money(
			$room_id,
			$check_in,
			$first_night_end,
			$guests,
			array(), // Exclude one-time extras for 1-night room deposit hold
			$children,
			$child_ages
		);

		if ( is_array( $first_night_calc ) && isset( $first_night_calc['total'] ) && $first_night_calc['total'] instanceof Money ) {
			return $first_night_calc['total'];
		}

		return Money::fromCents( 0, $currency );
	}

	/**
	 * Render the Professional Deposit Selection UI.
	 *
	 * @param Money                $total The total gross amount.
	 * @param array<string, mixed> $calc  The calculation results from the booking form.
	 * @return void
	 */
	public static function render_deposit_selection_html( Money $total, array $calc ): void {
		if ( ! get_option( 'mhbo_deposits_enabled', 0 ) ) {
			return;
		}

		// No deposit for 1-night bookings — no time to collect the remaining balance before checkout.
		$nights = isset( $calc['nights'] ) ? (int) $calc['nights'] : 0;
		if ( $nights === 1 ) {
			return;
		}

		$currency  = $total->getCurrency();
		$has_total = ! $total->isZero();

		// Prefer the children-inclusive first-night total if pre-computed by the caller.
		if ( isset( $calc['first_night_total'] ) && $calc['first_night_total'] instanceof Money ) {
			$first_night_money = $calc['first_night_total'];
		} else {
			$first_night_money = Money::fromCents( 0, $currency );
			if ( isset( $calc['daily_prices'] ) && array() !== $calc['daily_prices'] ) {
				$first             = reset( $calc['daily_prices'] );
				$first_night_money = $first instanceof Money
					? $first
					: Money::fromDecimal( (string) $first, $currency );
				if ( class_exists( Tax::class ) && Tax::is_sales_tax() ) {
					$fn_tax            = Tax::calculate_tax_money( $first_night_money, Tax::get_accommodation_rate(), Tax::MODE_SALES_TAX );
					$first_night_money = $fn_tax['gross'];
				}
			}
		}

		// Validate deposit settings are usable (bail for nonsense configs, not for zero initial total).
		$deposit_type_check  = (string) get_option( 'mhbo_deposit_type', 'percentage' );
		$deposit_val_raw     = (string) get_option( 'mhbo_deposit_value', '20' );
		$is_val_pos          = is_numeric( $deposit_val_raw ) && Money::fromDecimal( $deposit_val_raw, $currency )->isPositive();
		if ( ( 'percentage' === $deposit_type_check || 'fixed' === $deposit_type_check ) && ! $is_val_pos ) {
			return;
		}

		// Only validate deposit vs total when we actually have a real total.
		// When total is 0 (no dates yet), we still render the skeleton so the DOM
		// elements exist for the JS to update once dates are selected.
		$deposit_data = $has_total ? self::calculate_deposit_money( $total, $first_night_money ) : null;
		if ( $has_total && ( ! $deposit_data || $deposit_data['deposit_money']->isZero() || $deposit_data['deposit_money']->compare( $total ) >= 0 ) ) {
			return;
		}

		$deposit_money   = $deposit_data ? $deposit_data['deposit_money'] : Money::fromCents( 0, $currency );
		$remaining_money = $deposit_data ? $deposit_data['remaining_money'] : Money::fromCents( 0, $currency );
		$allow_choice    = get_option( 'mhbo_deposit_allow_guest_choice', 1 );

		// Hide wrapper until JS populates amounts from the REST price response.
		$wrapper_style = $has_total ? 'margin-bottom: 32px;' : 'margin-bottom: 32px; display: none;';
		?>
		<div class="mhbo-deposit-options-wrapper" style="<?php echo esc_attr( $wrapper_style ); ?>">
			<h4 style="margin-top:25px; margin-bottom:15px;"><?php echo esc_html( I18n::get_label( 'label_payment_options' ) ); ?></h4>
			<div class="mhbo-deposit-options">
				<!-- Pay in Full Card -->
				<div class="mhbo-payment-card <?php echo $allow_choice ? 'active' : 'disabled'; ?>" data-payment-type="full" data-amount="<?php echo esc_attr( $total->toDecimal() ); ?>">
					<input type="radio" name="mhbo_payment_type" value="full" <?php checked( true ); ?> <?php disabled( ! $allow_choice ); ?>>
					<div class="mhbo-card-header">
						<div class="mhbo-card-icon">
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
						</div>
						<span class="mhbo-card-title"><?php echo esc_html( I18n::get_label( 'label_pay_full' ) ); ?></span>
					</div>
					<div class="mhbo-card-amount mhbo-full-amount"><?php echo esc_html( $total->format() ); ?></div>
					<div class="mhbo-card-description mhbo-full-description"><?php echo esc_html( I18n::get_label( 'label_pay_full_desc' ) ); ?></div>
					<div class="mhbo-card-selected-indicator"></div>
				</div>

				<!-- Pay Deposit Card -->
				<div class="mhbo-payment-card <?php echo esc_attr( ! $allow_choice ? 'active' : '' ); ?>"
					data-payment-type="deposit"
					data-amount="<?php echo esc_attr( $deposit_money->toDecimal() ); ?>"
					data-balance="<?php echo esc_attr( $remaining_money->toDecimal() ); ?>"
					data-deposit-type="<?php echo esc_attr( (string) get_option( 'mhbo_deposit_type', 'percentage' ) ); ?>"
					data-deposit-value="<?php echo esc_attr( (string) get_option( 'mhbo_deposit_value', 20 ) ); ?>"
					data-first-night-amount="<?php echo esc_attr( $first_night_money->toDecimal() ); ?>">
					<input type="radio" name="mhbo_payment_type" value="deposit" <?php checked( ! $allow_choice ); ?>>
					<?php if ( ! $allow_choice ) : ?>
						<div class="mhbo-card-badge"><?php echo esc_html( I18n::get_label( 'label_required' ) ); ?></div>
					<?php endif; ?>
					<div class="mhbo-card-header">
						<div class="mhbo-card-icon">
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
						</div>
						<span class="mhbo-card-title"><?php echo esc_html( I18n::get_label( 'label_pay_deposit' ) ); ?></span>
					</div>
					<div class="mhbo-card-amount mhbo-deposit-amount"><?php echo esc_html( $deposit_money->format() ); ?></div>
					<div class="mhbo-card-description mhbo-deposit-description">
						<?php
						printf(
							esc_html( I18n::get_label( 'label_pay_deposit_desc' ) ),
							'<strong class="mhbo-deposit-balance-display">' . esc_html( I18n::format_currency( $remaining_money ) ) . '</strong>'
						);
						if ( $deposit_data && $deposit_data['is_non_refundable'] ) {
							echo ' <div class="mhbo-deposit-note">' . esc_html( I18n::get_label( 'msg_deposit_non_refundable' ) ) . '</div>';
						}
						if ( 'percentage' === ( $deposit_data['deposit_type'] ?? '' ) && class_exists( Tax::class ) && Tax::is_enabled() && Tax::MODE_SALES_TAX === Tax::get_mode() ) {
							echo ' <div class="mhbo-deposit-tax-note" style="font-size: 11px; color: #64748b; margin-top: 4px;">' . esc_html( __( 'Deposit calculated on total including applicable taxes.', 'modern-hotel-booking' ) ) . '</div>';
						}
						?>
					</div>
					<div class="mhbo-card-selected-indicator"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Check if the currently configured deposit is non-refundable.
	 *
	 * @return bool
	 */
	public static function is_deposit_non_refundable(): bool {
		return (bool) get_option( 'mhbo_deposit_non_refundable', 0 );
	}
}
