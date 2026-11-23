<?php
/**
 * Ability: Get Price Breakdown
 *
 * Provides an itemized pricing breakdown for a stay including base nightly rates,
 * seasonal adjustments, extra guest fees, taxes, deposits, and promo discounts.
 *
 * @package MHBO\AI\Abilities
 * @since   2.6.0
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\Pricing;
use MHBO\Core\HotelTime;
use MHBO\Core\Money;
use MHBO\Core\Tax;
use MHBO\Core\Tier;

use function __;
use function absint;
use function sanitize_email;
use function sanitize_text_field;
use function strtotime;
use function count;
use function is_array;
use function sprintf;
use function gmdate;
use function get_option;

/**
 * Get Price Breakdown Ability.
 */
class GetPriceBreakdown {

	/**
	 * Return the WP Ability / MCP tool definition.
	 *
	 * @return array<mixed>
	 */
	public static function get_definition(): array {
		$properties = array(
			'check_in'  => array(
				'type'        => 'string',
				'format'      => 'date',
				'description' => 'Check-in date (YYYY-MM-DD).',
			),
			'check_out' => array(
				'type'        => 'string',
				'format'      => 'date',
				'description' => 'Check-out date (YYYY-MM-DD).',
			),
			'room_id'   => array(
				'type'        => 'integer',
				'description' => 'Optional specific physical room ID.',
			),
			'type_id'   => array(
				'type'        => 'integer',
				'description' => 'Optional room type ID used when a specific physical room ID is not provided.',
			),
			'adults'    => array(
				'type'        => 'integer',
				'default'     => 2,
				'minimum'     => 1,
				'description' => 'Number of adults.',
			),
			'children'  => array(
				'type'        => 'integer',
				'default'     => 0,
				'minimum'     => 0,
				'description' => 'Number of children.',
			),
		);

return array(
			'label'               => __( 'Get Price Breakdown', 'modern-hotel-booking' ),
			'description'         => 'Get an itemized price breakdown for a stay including nightly rates, taxes, deposits, and discounts.',
			'category'            => 'booking-management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => $properties,
				'required'   => array( 'check_in', 'check_out' ),
			),
			'output_schema'       => array(
				'type' => 'object',
			),
			'permission_callback' => '__return_true',
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array(
					'public' => true,
				),
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		);
	}

	/**
	 * Execute price breakdown calculation using modern Money-based engine.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>
	 */
	public static function execute( array $args ): array {
		$check_in_raw  = sanitize_text_field( (string) ( $args['check_in'] ?? '' ) );
		$check_out_raw = sanitize_text_field( (string) ( $args['check_out'] ?? '' ) );

		if ( '' === $check_in_raw || '' === $check_out_raw ) {
			return array(
				'error'   => true,
				'message' => 'Both check_in and check_out dates are required.',
			);
		}

		$range_valid = HotelTime::validate_date_range( $check_in_raw, $check_out_raw, true );
		if ( is_wp_error( $range_valid ) ) {
			return array(
				'error'   => true,
				'message' => $range_valid->get_error_message(),
			);
		}
		$today_ymd = HotelTime::today();
		if ( $check_in_raw < $today_ymd ) {
			return array(
				'error'   => true,
				'message' => 'Check-in date cannot be in the past.',
			);
		}
		$nights_count = $range_valid;

		$adults   = absint( $args['adults'] ?? 2 );
		$children = absint( $args['children'] ?? 0 );

		$room_id = 0;
		if ( isset( $args['room_id'] ) && absint( $args['room_id'] ) > 0 ) {
			$room_id = absint( $args['room_id'] );
		} elseif ( isset( $args['type_id'] ) && absint( $args['type_id'] ) > 0 ) {
			$type_id = absint( $args['type_id'] );
			$found   = Pricing::find_available_room( $type_id, $check_in_raw, $check_out_raw, $adults + $children );
			if ( false !== $found && (int) $found > 0 ) {
				$room_id = (int) $found;
			} else {
				global $wpdb;
				$fallback_key = 'price_type_room_' . $type_id;
				$fallback_id  = \MHBO\Core\Cache::get_query( $fallback_key, \MHBO\Core\Cache::TABLE_ROOMS );
				if ( false === $fallback_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached via Cache::set_query immediately below.
					$fallback_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$wpdb->prefix}mhbo_rooms WHERE type_id = %d ORDER BY id ASC LIMIT 1",
							$type_id
						)
					);
					\MHBO\Core\Cache::set_query( $fallback_key, (int) $fallback_id, \MHBO\Core\Cache::TABLE_ROOMS, 3600 );
				}
				$room_id = absint( $fallback_id );
			}
		}
		if ( $room_id <= 0 ) {
			return array(
				'error'   => true,
				'message' => 'A valid room_id or type_id is required to calculate a price breakdown.',
			);
		}

		$child_ages = array();
		$promo_code = '';

$extras      = array();
		$stay_timing = null;

// 2026 BP: Use calculate_booking_money for precise financial calculation
		$calc = Pricing::calculate_booking_money( $room_id, $check_in_raw, $check_out_raw, $adults, $extras, $children, $child_ages );
		if ( ! is_array( $calc ) ) {
			return array(
				'error'   => true,
				'message' => 'Unable to calculate price for the specified room and dates.',
			);
		}

		$currency = Pricing::get_currency_code();

		$daily_breakdown = array();
		if ( isset( $calc['daily_prices'] ) && is_array( $calc['daily_prices'] ) ) {
			foreach ( $calc['daily_prices'] as $i => $m ) {
				$day_date                     = HotelTime::add_days( $check_in_raw, (int) $i );
				$daily_breakdown[ $day_date ] = $m instanceof Money ? (float) $m->toDecimal() : (float) $m;
			}
		}

		$discount_amount = 0.0;
		$accom_ctx       = array(
			'discount' => null,
			'base'     => null,
		);

$room_total_m     = ( isset( $calc['room_total'] ) && $calc['room_total'] instanceof Money ) ? $calc['room_total'] : Money::fromCents( 0, $currency );
		$children_total_m = ( isset( $calc['children_total'] ) && $calc['children_total'] instanceof Money ) ? $calc['children_total'] : Money::fromCents( 0, $currency );
		$extras_total_m   = ( isset( $calc['extras_total'] ) && $calc['extras_total'] instanceof Money ) ? $calc['extras_total'] : Money::fromCents( 0, $currency );
		$service_fee_m    = ( isset( $calc['service_fee'] ) && $calc['service_fee'] instanceof Money ) ? $calc['service_fee'] : Money::fromCents( 0, $currency );
		$total_money      = $calc['total'] instanceof Money ? $calc['total'] : Money::fromCents( 0, $currency );
		$subtotal         = $calc['subtotal'] instanceof Money ? (float) $calc['subtotal']->toDecimal() : 0.0;
		$total            = (float) $total_money->toDecimal();
		$tax_enabled      = Tax::is_enabled();
		$tax_mode         = $tax_enabled ? Tax::get_mode() : 'disabled';

		$itemized = array(
			'room_id'       => $room_id,
			'check_in'      => $check_in_raw,
			'check_out'     => $check_out_raw,
			'nights_count'  => $calc['nights'] ?? $nights_count,
			'adults'        => $adults,
			'children'      => $children,
			'child_ages'    => $child_ages,
			'currency'      => $currency,
			'pricing'       => array(
				'room_total'        => (float) $room_total_m->toDecimal(),
				'children_total'    => (float) $children_total_m->toDecimal(),
				'extras_total'      => (float) $extras_total_m->toDecimal(),
				'subtotal'          => $subtotal,
				'service_fee'       => (float) $service_fee_m->toDecimal(),
				'service_fee_label' => $service_fee_m->isPositive() ? (string) get_option( 'mhbo_service_fee_label', 'Service Fee' ) : '',
				'discount_amount'   => $discount_amount,
				'coupon_code'       => $discount_amount > 0 ? $promo_code : '',
				'tax_total'         => ( isset( $calc['tax']['totals']['total_tax'] ) && $calc['tax']['totals']['total_tax'] instanceof Money ) ? (float) $calc['tax']['totals']['total_tax']->toDecimal() : (float) ( $calc['tax']['tax_amount'] ?? 0.0 ),
				'tax_mode'          => $tax_mode,
				'tax_label'         => $tax_enabled ? Tax::get_label() : '',
				'is_vat'            => $tax_enabled && Tax::MODE_VAT === $tax_mode,
				'total_price'       => $total,
				'price_formatted'   => $total_money->format(),
			),
			'nightly_rates' => $daily_breakdown,
		);

return $itemized;
	}

	/**
	 * WP 7.0 native ability registration.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		\wp_register_ability( 'mhbo/get-price-breakdown', self::get_definition() );
	}
}
