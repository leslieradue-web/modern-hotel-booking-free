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
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
                    SUM(
                        CASE 
                            WHEN status = 'cancelled' OR payment_status = 'refunded' THEN 0
                            WHEN payment_received = 1 OR payment_status IN ('completed', 'paid') 
                                THEN GREATEST(CAST(COALESCE(total_price, 0) AS DECIMAL(10,2)), CAST(COALESCE(payment_amount, 0) AS DECIMAL(10,2)))
                            WHEN payment_status = 'deposit_paid' OR deposit_received = 1 
                                THEN CAST(COALESCE(NULLIF(payment_amount, 0), deposit_amount, 0) AS DECIMAL(10,2))
                            WHEN CAST(COALESCE(payment_amount, 0) AS DECIMAL(10,2)) > 0 
                                THEN CAST(payment_amount AS DECIMAL(10,2))
                            ELSE 0 
                        END
                    ) as total_spend,
                    COUNT(
                        CASE 
                            WHEN status IN ('confirmed', 'checked_in', 'checked_out', 'completed', 'paid') 
                             AND status != 'cancelled'
                            THEN 1 
                            ELSE NULL 
                        END
                    ) as total_stays
                 FROM {$wpdb->prefix}mhbo_bookings
                 WHERE guest_id = %d",
				$guest_id
			)
		);

		$currency            = (string) get_option( 'mhbo_currency_code', 'USD' );
		$raw_spend           = (string) ( $stats->total_spend ?? '0' );
		$spend_money         = Money::fromDecimal( $raw_spend, $currency );
		$total_spend_decimal = $spend_money->toDecimal();
		$total_stays         = (int) ( $stats->total_stays ?? 0 );

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
