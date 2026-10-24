<?php declare(strict_types=1);

namespace MHBO\Core;

use MHBO\Core\Cache;
use MHBO\Core\HotelTime;
use MHBO\Core\Money;
use MHBO\Core\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seasonal Rates - Apply seasonal pricing rules to bookings.
 * Handles rate adjustments from wp_mhbo_pricing_rules across all plugin editions.
 * Weekend and holiday rate adjustments are handled when commercial features are active.
 *
 * @package MHBO\Core
 * @since   2.6.0
 */
class SeasonalRates {

	/**
	 * Wire up pricing hooks.
	 */
	public function init(): void {
		// Preferred precision-safe filter
		add_filter( 'mhbo_calculate_stay_price_money', array( $this, 'apply_seasonal_rules_money' ), 10, 3 );

		// Allow on-demand initialization for AJAX/REST/POST requests
		add_action( 'mhbo_pro_pricing_init', array( $this, 'init' ), 1 );
	}

	/**
	 * Apply seasonal pricing rules to the base price using Money objects.
	 * Also applies weekend and holiday pricing if Pro is active.
	 *
	 * Conflict resolution (when a day is both a weekend and a holiday in Pro):
	 *  - mhbo_apply_weekend_to_holidays = 1 (default): use the larger of the two adjustments.
	 *  - mhbo_apply_weekend_to_holidays = 0: stack both adjustments.
	 *
	 * @param Money  $price   Current price object.
	 * @param int    $room_id The room ID.
	 * @param string $date    Date string (YYYY-MM-DD).
	 * @return Money Modified price.
	 */
	public function apply_seasonal_rules_money( Money $price, int $room_id, string $date ): Money {
		// Cache key is room+date specific; the query already resolves global and type-targeted rules.
		$cache_key = sprintf( 'seasonal_rules_%d_%s', $room_id, $date );
		$rules     = Cache::get_query( $cache_key, Cache::TABLE_PRICING_RULES );

		if ( false === $rules || ! is_array( $rules ) ) {
			global $wpdb;
			// JOIN resolves the room's type_id so we can match:
			// - room-specific rules  (pr.room_id = this room)
			// - type-targeted rules  (pr.room_id = 0, pr.type_id = room's type)
			// - global rules         (pr.room_id = 0, pr.type_id = 0)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query; caching handled by Cache::get_query()/set_query() above.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pr.* FROM {$wpdb->prefix}mhbo_pricing_rules pr
                 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON r.id = %d
                 WHERE (pr.room_id = 0 OR pr.room_id = %d)
                   AND (pr.type_id = 0 OR (r.type_id IS NOT NULL AND pr.type_id = r.type_id))
                   AND pr.start_date <= DATE(%s) AND pr.end_date >= DATE(%s)
                 ORDER BY pr.priority ASC",
					(int) $room_id,
					(int) $room_id,
					$date,
					$date
				)
			);
			$rules   = is_array( $results ) ? $results : array();
			Cache::set_query( $cache_key, $rules, Cache::TABLE_PRICING_RULES, 3600 );
		}

		foreach ( $rules as $rule ) {
			// Only process seasonal rules (the default rule_type for all admin-created rules).
			if ( isset( $rule->rule_type ) && 'seasonal' !== $rule->rule_type ) {
				continue;
			}

			$operation    = $rule->operation ?? '';
			$amount_float = (float) ( $rule->amount ?? '0' );

			if ( 0.0 === $amount_float ) {
				continue;
			}

			$abs_amount  = (string) abs( $amount_float );
			$is_discount = $amount_float < 0;

			// Support both legacy operations ('increase'/'decrease'/'percentage')
			// and current admin-saved values ('fixed'/'percent').
			// 'fixed' uses the amount sign to distinguish surcharge vs. discount.
			if ( 'increase' === $operation || ( 'fixed' === $operation && ! $is_discount ) ) {
				$price = $price->add( Money::fromDecimal( $abs_amount, $price->getCurrency() ) );
			} elseif ( 'decrease' === $operation || ( 'fixed' === $operation && $is_discount ) ) {
				$price = $price->subtract( Money::fromDecimal( $abs_amount, $price->getCurrency() ) );
			} elseif ( 'percentage' === $operation || 'percent' === $operation ) {
				// Use Money::multiply for safe percentage math; respect sign for discounts.
				$multiplier = function_exists( 'bcdiv' )
					? bcdiv( $abs_amount, '100', 4 )
					: (string) ( (float) $abs_amount / 100 );
				$delta      = $price->multiply( $multiplier );
				$price      = $is_discount ? $price->subtract( $delta ) : $price->add( $delta );
			}
		}

		// --- Weekend & Holiday pricing (Pro-only feature) ---
		if ( class_exists( 'MHBO\Core\License' ) && false ) {
			$price = $this->apply_weekend_holiday_pricing( $price, $date );
		}

		// Ensure price never goes below zero (floor at 1 cent).
		if ( $price->isNegative() || $price->isZero() ) {
			return Money::fromDecimal( '0.01', $price->getCurrency() );
		}

		return $price;
	}

	/**
	 * Apply weekend and/or holiday pricing adjustments for a single day (Pro feature).
	 *
	 * @param Money  $price Current day price (after seasonal DB rules).
	 * @param string $date  Date string (YYYY-MM-DD).
	 * @return Money Adjusted price.
	 */
	private function apply_weekend_holiday_pricing( Money $price, string $date ): Money {
		$weekend_enabled = (bool) get_option( 'mhbo_weekend_pricing_enabled', 0 );
		$holiday_enabled = (bool) get_option( 'mhbo_holiday_pricing_enabled', 0 );

		if ( ! $weekend_enabled && ! $holiday_enabled ) {
			return $price;
		}

		$is_weekend = false;
		$is_holiday = false;

		// Check weekend match.
		if ( $weekend_enabled ) {
			$weekend_days = get_option( 'mhbo_weekend_days', array( 'friday', 'saturday', 'sunday' ) );
			if ( ! is_array( $weekend_days ) ) {
				$weekend_days = ( is_string( $weekend_days ) && '' !== $weekend_days )
					? explode( ',', $weekend_days )
					: array();
			}
			$day_name   = HotelTime::day_name( $date );
			$is_weekend = in_array( $day_name, $weekend_days, true );
		}

		// Check holiday match.
		if ( $holiday_enabled ) {
			$holiday_dates_raw = (string) get_option( 'mhbo_holiday_dates', '' );
			if ( '' !== $holiday_dates_raw ) {
				$holiday_dates = array_filter( array_map( 'trim', explode( ',', $holiday_dates_raw ) ), static fn( $date ): bool => '' !== $date );
				$is_holiday    = in_array( $date, $holiday_dates, true );
			}
		}

		if ( ! $is_weekend && ! $is_holiday ) {
			return $price;
		}

		// Conflict resolution when a day is both a weekend and a holiday.
		if ( $is_weekend && $is_holiday ) {
			$use_larger = (bool) get_option( 'mhbo_apply_weekend_to_holidays', 1 );

			$weekend_price = self::apply_modifier(
				$price,
				(float) get_option( 'mhbo_weekend_rate_multiplier', 1.2 ),
				(string) get_option( 'mhbo_weekend_modifier_type', 'multiplier' )
			);
			$holiday_price = self::apply_modifier(
				$price,
				(float) get_option( 'mhbo_holiday_rate_modifier', 1.2 ),
				(string) get_option( 'mhbo_holiday_modifier_type', 'multiplier' )
			);

			if ( $use_larger ) {
				// Use whichever adjustment yields the higher price.
				return $weekend_price->compare( $holiday_price ) >= 0 ? $weekend_price : $holiday_price;
			}

			// Stack both: apply weekend first, then holiday on top.
			return self::apply_modifier(
				$weekend_price,
				(float) get_option( 'mhbo_holiday_rate_modifier', 1.2 ),
				(string) get_option( 'mhbo_holiday_modifier_type', 'multiplier' )
			);
		}

		if ( $is_weekend ) {
			return self::apply_modifier(
				$price,
				(float) get_option( 'mhbo_weekend_rate_multiplier', 1.2 ),
				(string) get_option( 'mhbo_weekend_modifier_type', 'multiplier' )
			);
		}

		// Holiday only.
		return self::apply_modifier(
			$price,
			(float) get_option( 'mhbo_holiday_rate_modifier', 1.2 ),
			(string) get_option( 'mhbo_holiday_modifier_type', 'multiplier' )
		);
	}

	/**
	 * Apply a single pricing modifier (multiplier / percent / fixed) to a Money value.
	 *
	 * @param Money  $price Current price.
	 * @param float  $value Modifier value (e.g. 1.2 for multiplier, 20 for percent/fixed).
	 * @param string $type  'multiplier' | 'percent' | 'fixed'.
	 * @return Money Adjusted price (guaranteed >= 0.01).
	 */
	public static function apply_modifier( Money $price, float $value, string $type ): Money {
		if ( 0.0 === $value ) {
			return $price;
		}

		$result = match ( $type ) {
			'multiplier' => ( $value <= 0.0 )
				? $price
				: $price->multiply( (string) $value ),

			'percent'    => ( function () use ( $price, $value ): Money {
				$clamped_val = $value < -100.0 ? -100.0 : $value;
				$is_discount = $clamped_val < 0.0;
				$abs_val     = abs( $clamped_val );
				$multiplier  = function_exists( 'bcdiv' )
					? bcdiv( (string) $abs_val, '100', 4 )
					: (string) ( $abs_val / 100 );
				$delta       = $price->multiply( $multiplier );
				return $is_discount ? $price->subtract( $delta ) : $price->add( $delta );
			} )(),

			'fixed'      => ( function () use ( $price, $value ): Money {
				$is_discount = $value < 0.0;
				$abs_val     = abs( $value );
				$money_delta = Money::fromDecimal( (string) $abs_val, $price->getCurrency() );
				return $is_discount ? $price->subtract( $money_delta ) : $price->add( $money_delta );
			} )(),

			default      => $price,
		};

		$floor = Money::fromDecimal( '0.01', $price->getCurrency() );
		if ( $result->lessThan( $floor ) ) {
			$result = $floor;
		}

		return $result;
	}
}
