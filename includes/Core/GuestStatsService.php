<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculates financial LTV and stay projections for Guest CRM.
 * Consumes authoritative captured payments net of refunds using Money minor-unit precision.
 *
 * @package MHBO\Core
 * @since   2.6.0
 */
class GuestStatsService {

	/**
	 * Recalculate lifetime stay and spend projection for a guest.
	 *
	 * @param int $guest_id
	 * @return array<string, mixed>
	 */
	public static function recalculate_guest_stats( int $guest_id ): array {
		if ( $guest_id <= 0 ) {
			return array(
				'total_stays' => 0,
				'total_spend' => '0.0000',
				'vip_status'  => 'none',
			);
		}

		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.* FROM {$wpdb->prefix}mhbo_bookings b WHERE b.guest_id = %d ORDER BY b.id ASC",
				$guest_id
			)
		);

		$currency    = class_exists( Pricing::class ) ? Pricing::get_currency_code() : strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$spend_money = Money::fromCents( 0, $currency );
		$stay_keys   = array();

		foreach ( (array) $rows as $b ) {
			if ( ! is_object( $b ) ) {
				continue;
			}
			$status     = strtolower( trim( (string) ( $b->status ?? '' ) ) );
			$pay_status = strtolower( trim( (string) ( $b->payment_status ?? '' ) ) );
			$cust_email = strtolower( trim( (string) ( $b->customer_email ?? '' ) ) );

			if ( 'guest@pending.local' === $cust_email || str_ends_with( $cust_email, '@local.invalid' ) ) {
				continue;
			}
			if ( 'pending_payment' === $status && 'pending' === $pay_status ) {
				continue;
			}

			if ( ! in_array( $status, array( 'cancelled', 'refunded', 'deleted' ), true ) && 'refunded' !== $pay_status ) {
				if ( class_exists( Pricing::class ) ) {
					$b_amts      = Pricing::get_booking_payment_amounts( $b );
					$spend_money = $spend_money->add( $b_amts['received'] );
				} else {
					$spend_money = $spend_money->add( Money::fromDecimal( (string) ( $b->payment_amount ?? 0 ), $currency ) );
				}
			}

			if ( in_array( $status, array( 'confirmed', 'checked_in', 'checked_out', 'completed', 'paid' ), true ) ) {
				$grp_tok               = trim( (string) ( $b->multi_room_parent ?? '' ) );
				$stay_key              = '' !== $grp_tok ? ( 'grp:' . $grp_tok ) : ( 'single:' . (int) ( $b->id ?? 0 ) );
				$stay_keys[ $stay_key ] = true;
			}
		}

		$total_spend_decimal = $spend_money->toDecimal();
		$total_stays         = count( $stay_keys );

		// Fetch current guest profile to respect manual VIP overrides
		$current_guest = GuestManager::get_guest( $guest_id );
		$current_vip   = (string) ( $current_guest->vip_status ?? 'none' );

		// Evaluate automated VIP rule if not manually locked to 'custom'
		$new_vip = $current_vip;
		if ( 'custom' !== $current_vip ) {
			$new_vip = self::evaluate_vip_status( $spend_money, $total_stays );
		}

		// Update projected stats on the guest record
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'mhbo_guests',
			array(
				'total_spend' => $total_spend_decimal,
				'total_stays' => $total_stays,
				'vip_status'  => $new_vip,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $guest_id )
		);

		wp_cache_delete( 'mhbo_guest_' . $guest_id, 'mhbo_guests' );
		Cache::invalidate_table( 'guests' );

		return array(
			'total_stays' => $total_stays,
			'total_spend' => $total_spend_decimal,
			'vip_status'  => $new_vip,
		);
	}

	/**
	 * Evaluates VIP status based on site-configured multi-currency thresholds.
	 *
	 * @param Money $spend_money
	 * @param int   $total_stays
	 * @return string 'vip' | 'repeat' | 'none'
	 */
	public static function evaluate_vip_status( Money $spend_money, int $total_stays ): string {
		$spend_threshold_raw = (float) get_option( 'mhbo_crm_vip_spend_threshold', 0 );
		$stays_threshold     = (int) get_option( 'mhbo_crm_vip_stays_threshold', 3 );

		$currency = $spend_money->getCurrency();

		$qualifies_by_spend = false;
		if ( $spend_threshold_raw > 0 ) {
			$threshold_money    = Money::fromDecimal( (string) $spend_threshold_raw, $currency );
			$qualifies_by_spend = $spend_money->isGreaterThanOrEqual( $threshold_money );
		}

		$qualifies_by_stays = $total_stays >= $stays_threshold && $stays_threshold > 0;

		if ( $qualifies_by_spend || $qualifies_by_stays ) {
			return 'vip';
		}

		if ( $total_stays >= 2 ) {
			return 'repeat';
		}

		return 'none';
	}
}
