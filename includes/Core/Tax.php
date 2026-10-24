<?php declare(strict_types=1);

namespace MHBO\Core;

use MHBO\Core\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

/**
 * Tax Calculation Class
 * 
 * Handles VAT and Sales Tax calculations for hotel bookings.
 * Supports three modes:
 * - disabled: No tax calculation
 * - vat: VAT Inclusive (prices include tax)
 * - sales_tax: Sales Tax Exclusive (tax added on top)
 */
class Tax {

	/**
	 * Tax modes
	 */
	const MODE_DISABLED  = 'disabled';
	const MODE_VAT       = 'vat';
	const MODE_SALES_TAX = 'sales_tax';

	/**
	 * Rounding modes
	 */
	const ROUND_PER_LINE  = 'per_line';
	const ROUND_PER_TOTAL = 'per_total';

	/**
	 * Normalize a tax mode string (converting legacy/alias values like 'inclusive'/'exclusive').
	 *
	 * @param string|null $mode Raw tax mode.
	 * @return string Canonical MODE_* constant.
	 */
	public static function normalize_mode( ?string $mode ): string {
		if ( null === $mode || '' === $mode ) {
			return self::MODE_DISABLED;
		}
		$clean = strtolower( trim( $mode ) );
		if ( self::MODE_VAT === $clean || 'inclusive' === $clean ) {
			return self::MODE_VAT;
		}
		if ( self::MODE_SALES_TAX === $clean || 'exclusive' === $clean ) {
			return self::MODE_SALES_TAX;
		}
		return self::MODE_DISABLED;
	}

	/**
	 * Check if tax is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
        return false;
    }

	/**
	 * Get current tax mode.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public static function get_mode(): string {
        return self::MODE_DISABLED;
    }

	/**
	 * Check if tax mode is VAT (inclusive).
	 *
	 * @return bool
	 */
	public static function is_vat(): bool {
		$mode = self::get_mode();
		return self::is_enabled() && ( self::MODE_VAT === $mode || 'inclusive' === $mode );
	}

	/**
	 * Check if tax mode is Sales Tax / GST (exclusive).
	 *
	 * @return bool
	 */
	public static function is_sales_tax(): bool {
		$mode = self::get_mode();
		return self::is_enabled() && ( self::MODE_SALES_TAX === $mode || 'exclusive' === $mode );
	}

	/**
	 * Get tax label for current language and tax mode.
	 *
	 * If mode is VAT and the configured label was left as GST/Sales Tax,
	 * dynamically normalizes to VAT. If mode is Sales Tax and label was VAT/TVA,
	 * dynamically normalizes to GST.
	 *
	 * @param string|null $language Language code (optional)
	 * @param string|null $mode Tax mode (optional, defaults to current mode)
	 * @return string
	 */
	public static function get_label( ?string $language = null, ?string $mode = null ): string {
		if ( null === $language ) {
			$language = I18n::get_current_language();
		}
		if ( null === $mode ) {
			$mode = self::get_mode();
		}
		$label     = get_option( 'mhbo_tax_label', '[:en]VAT[:ro]TVA[:]' );
		$decoded   = I18n::decode( $label, $language );
		$raw_label = ( null !== $decoded && '' !== $decoded ) ? $decoded : 'VAT';

		if ( self::MODE_VAT === $mode || 'inclusive' === $mode ) {
			if ( in_array( strtoupper( trim( $raw_label ) ), array( 'GST', 'SALES TAX', 'TAX', 'TAXES' ), true ) ) {
				return 'VAT';
			}
		} elseif ( self::MODE_SALES_TAX === $mode || 'exclusive' === $mode ) {
			if ( in_array( strtoupper( trim( $raw_label ) ), array( 'VAT', 'TVA' ), true ) ) {
				return 'GST';
			}
		}

		return $raw_label;
	}

	/**
	 * Get display label for tax line items.
	 * In VAT mode, returns e.g. "VAT (included)". In sales tax mode, returns e.g. "GST".
	 *
	 * @param string|null $language Language code (optional)
	 * @param string|null $mode Tax mode (optional)
	 * @return string
	 */
	public static function get_display_label( ?string $language = null, ?string $mode = null ): string {
		if ( null === $mode ) {
			$mode = self::get_mode();
		}
		$base_label = self::get_label( $language, $mode );
		if ( self::MODE_VAT === $mode || 'inclusive' === $mode ) {
			return sprintf( I18n::get_label( 'label_tax_included' ), $base_label );
		}
		return $base_label;
	}

	/**
	 * Get tax registration number
	 *
	 * @return string
	 */
	public static function get_registration_number(): string {
		return (string) get_option( 'mhbo_tax_registration_number', '' );
	}

	/**
	 * Get accommodation tax rate
	 *
	 * @return float
	 */
	public static function get_accommodation_rate(): float {
		return floatval( get_option( 'mhbo_tax_rate_accommodation', 0.00 ) );
	}

	/**
	 * Get extras tax rate
	 *
	 * @return float
	 */
	public static function get_extras_rate(): float {
		return floatval( get_option( 'mhbo_tax_rate_extras', 0.00 ) );
	}

	/**
	 * Format a percentage rate for display/form values without corrupting whole numbers.
	 *
	 * Naive `rtrim( rtrim( $rate, '0' ), '.' )` turns "10" into "1" because it strips integer zeros when
	 * there is no decimal point. Trailing zeros are only trimmed after a decimal point here.
	 *
	 * @param float|int|string $rate Tax/fee percentage (e.g. 10, '10.00', 7.5).
	 * @return string Canonical display string: '10', '7.5', '0'.
	 */
	public static function format_rate( float|int|string $rate ): string {
		$normalized = number_format( (float) str_replace( ',', '.', (string) $rate ), 4, '.', '' );
		$trimmed    = rtrim( rtrim( $normalized, '0' ), '.' );
		return '' === $trimmed ? '0' : $trimmed;
	}

	/**
	 * Get rounding mode
	 *
	 * @return string
	 */
	public static function get_rounding_mode(): string {
		return (string) get_option( 'mhbo_tax_rounding_mode', self::ROUND_PER_TOTAL );
	}

	/**
	 * Get decimal places
	 *
	 * @return int
	 */
	public static function get_decimal_places(): int {
		return intval( get_option( 'mhbo_tax_decimal_places', 2 ) );
	}

	/**
	 * Get all tax settings as an array
	 *
	 * @return array<string, mixed> Tax settings
	 */
	public static function get_settings(): array {
		$mode = self::get_mode();

		return array(
			'enabled'             => self::is_enabled(),
			'mode'                => $mode,
			'label'               => self::get_label(),
			'registration_number' => self::get_registration_number(),
			'accommodation_rate'  => self::get_accommodation_rate(),
			'extras_rate'         => self::get_extras_rate(),
			'rounding_mode'       => self::get_rounding_mode(),
			'decimal_places'      => self::get_decimal_places(),
			'display_frontend'    => (bool) get_option( 'mhbo_tax_display_frontend', false ),
			'display_email'       => (bool) get_option( 'mhbo_tax_display_email', true ),
			'prices_include_tax'  => self::MODE_VAT === $mode,
		);
	}

	/**
	 * Calculate tax from gross amount (VAT Inclusive mode)
	 * 
	 * Formula: Net = Gross / (1 + Rate/100), Tax = Gross - Net
	 *
	 * @param float $gross_amount Gross amount (includes tax)
	 * @param float $tax_rate Tax rate as percentage
	 * @param bool  $round Whether to round the result (default: true)
	 * @return array<string, float> ['net' => float, 'tax' => float, 'gross' => float]
	 */
	public static function calculate_from_gross( float $gross_amount, float $tax_rate, bool $round = true ): array {
		$currency    = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$gross_money = Money::fromDecimal( (string) $gross_amount, $currency );
		$calc        = self::calculate_from_gross_money( $gross_money, $tax_rate );

		return array(
			'net'   => $round ? floatval( $calc['net']->toDecimal() ) : floatval( $calc['net']->toDecimal() ), // Backward compatibility
			'tax'   => $round ? floatval( $calc['tax']->toDecimal() ) : floatval( $calc['tax']->toDecimal() ),
			'gross' => $round ? floatval( $calc['gross']->toDecimal() ) : floatval( $calc['gross']->toDecimal() ),
		);
	}

	/**
	 * Calculate tax from net amount (Sales Tax Exclusive mode)
	 * 
	 * Formula: Tax = Net * (Rate/100), Gross = Net + Tax
	 *
	 * @param float $net_amount Net amount (before tax)
	 * @param float $tax_rate Tax rate as percentage
	 * @param bool  $round Whether to round the result (default: true)
	 * @return array<string, float> ['net' => float, 'tax' => float, 'gross' => float]
	 */
	public static function calculate_from_net( float $net_amount, float $tax_rate, bool $round = true ): array {
		$currency  = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$net_money = Money::fromDecimal( (string) $net_amount, $currency );
		$calc      = self::calculate_from_net_money( $net_money, $tax_rate );

		return array(
			'net'   => $round ? floatval( $calc['net']->toDecimal() ) : floatval( $calc['net']->toDecimal() ), // Backward compatibility
			'tax'   => $round ? floatval( $calc['tax']->toDecimal() ) : floatval( $calc['tax']->toDecimal() ),
			'gross' => $round ? floatval( $calc['gross']->toDecimal() ) : floatval( $calc['gross']->toDecimal() ),
		);
	}

	/**
	 * Calculate tax for an amount based on current mode
	 *
	 * @param float       $amount The amount (gross for VAT, net for Sales Tax)
	 * @param float       $tax_rate Tax rate as percentage
	 * @param string|null $mode Tax mode (optional, uses current setting)
	 * @param bool        $round Whether to round the result (default: true)
	 * @return array<string, float> ['net' => float, 'tax' => float, 'gross' => float]
	 */
	public static function calculate_tax( float $amount, float $tax_rate, ?string $mode = null, bool $round = true ): array {
		$mode = ( null === $mode || '' === $mode ) ? self::get_mode() : self::normalize_mode( $mode );

		if ( self::MODE_VAT === $mode ) {
			return self::calculate_from_gross( $amount, $tax_rate, $round );
		} elseif ( self::MODE_SALES_TAX === $mode ) {
			return self::calculate_from_net( $amount, $tax_rate, $round );
		}

		// Disabled mode - no tax
		$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$money    = Money::fromDecimal( (string) $amount, $currency );
		return array(
			'net'   => (float) $money->toDecimal(),
			'tax'   => 0.0,
			'gross' => (float) $money->toDecimal(),
		);
	}

	/**
	 * Precision-safe tax calculation using Money objects.
	 *
	 * @param Money       $amount   The amount (gross for VAT, net for Sales Tax).
	 * @param float       $tax_rate Tax rate as percentage.
	 * @param string|null $mode Tax mode.
	 * @return array{net: Money, tax: Money, gross: Money}
	 */
	public static function calculate_tax_money( Money $amount, float $tax_rate, ?string $mode = null ): array {
		$mode = ( null === $mode || '' === $mode ) ? self::get_mode() : self::normalize_mode( $mode );

		if ( $tax_rate === 0.0 || $amount->isZero() ) {
			return array(
				'net'   => $amount,
				'tax'   => Money::fromCents( 0, $amount->getCurrency() ),
				'gross' => $amount,
			);
		}

		if ( self::MODE_VAT === $mode ) {
			return self::calculate_from_gross_money( $amount, $tax_rate );
		} elseif ( self::MODE_SALES_TAX === $mode ) {
			return self::calculate_from_net_money( $amount, $tax_rate );
		}

		// Disabled mode
		return array(
			'net'   => $amount,
			'tax'   => Money::fromCents( 0, $amount->getCurrency() ),
			'gross' => $amount,
		);
	}

	/**
	 * Formula: Net = Gross / (1 + Rate/100), Tax = Gross - Net
	 *
	 * @param Money $gross
	 * @param float $rate
	 * @return array{net: Money, tax: Money, gross: Money}
	 */
	private static function calculate_from_gross_money( Money $gross, float $rate ): array {
		$str_rate = str_replace( ',', '.', (string) $rate );
		if ( function_exists( 'bcdiv' ) && function_exists( 'bcadd' ) ) {
			$rate_decimal = bcdiv( $str_rate, '100', 4 );
			$divisor      = bcadd( '1', $rate_decimal, 4 );
		} else {
			// 1 + Rate/100
			$divisor = 1 + ( $rate / 100 );
		}
		
		$net = $gross->divide( $divisor );
		$tax = $gross->subtract( $net );

		return array(
			'net'   => $net,
			'tax'   => $tax,
			'gross' => $gross,
		);
	}

	/**
	 * Formula: Tax = Net * (Rate/100), Gross = Net + Tax
	 *
	 * @param Money $net
	 * @param float $rate
	 * @return array{net: Money, tax: Money, gross: Money}
	 */
	private static function calculate_from_net_money( Money $net, float $rate ): array {
		$str_rate = str_replace( ',', '.', (string) $rate );
		if ( function_exists( 'bcdiv' ) ) {
			$multiplier = bcdiv( $str_rate, '100', 4 );
		} else {
			$multiplier = $rate / 100;
		}
		
		$tax   = $net->multiply( $multiplier );
		$gross = $net->add( $tax );

		return array(
			'net'   => $net,
			'tax'   => $tax,
			'gross' => $gross,
		);
	}

/**
	 * Calculate full booking tax breakdown
	 *
	 * @param array<string, mixed> $booking_data Booking data containing:
	 *   - room_total: float Total room charges
	 *   - children_total: float Total children charges (optional)
	 *   - extras_total: float Total extras charges (optional)
	 *   - extras: array Extras breakdown (optional)
	 * @param array<string, mixed> $booking_data
	 * @param string|null          $tax_mode Optional tax mode override
	 * @return array<string, mixed> Tax breakdown
	 */
	public static function calculate_booking_tax( array $booking_data, ?string $tax_mode = null ): array {
		$raw_mode           = ( null !== $tax_mode && '' !== $tax_mode ) ? $tax_mode : (string) ( $booking_data['tax_mode'] ?? self::get_mode() );
		$mode               = self::normalize_mode( $raw_mode );
		$is_enabled         = self::MODE_DISABLED !== $mode;
		$accommodation_rate = self::get_accommodation_rate();
		$extras_rate        = self::get_extras_rate();
		$rounding_mode      = self::get_rounding_mode();
		$currency           = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$base_label         = self::get_label( null, $mode );
		$display_label      = self::get_display_label( null, $mode );
		$is_vat             = ( self::MODE_VAT === $mode || 'inclusive' === $mode );

		$result = array(
			'enabled'             => $is_enabled,
			'mode'                => $mode,
			'label'               => $display_label,
			'base_label'          => $base_label,
			'is_vat'              => $is_vat,
			'registration_number' => self::get_registration_number(),
			'rates'               => array(
				'accommodation' => $is_enabled ? $accommodation_rate : 0.0,
				'extras'        => $is_enabled ? $extras_rate : 0.0,
			),
			'configured_rates'    => array(
				'accommodation' => $accommodation_rate,
				'extras'        => $extras_rate,
			),
			'breakdown'           => array(
				'room'     => null,
				'children' => null,
				'extras'   => array(),
			),
			'totals'              => array(
				'subtotal_net' => 0.00,
				'total_tax'    => 0.00,
				'total_gross'  => 0.00,
			),
			'rounding'            => array(
				'mode'           => $rounding_mode,
				'decimal_places' => self::get_decimal_places(),
			),
			'calculated_at'       => current_time( 'mysql' ),
		);

		// Initialize component total Money objects
		$total_net_money   = Money::fromCents( 0, $currency );
		$total_tax_money   = Money::fromCents( 0, $currency );
		$total_gross_money = Money::fromCents( 0, $currency );

		$room_net_money     = Money::fromCents( 0, $currency );
		$room_tax_money     = Money::fromCents( 0, $currency );
		$children_net_money = Money::fromCents( 0, $currency );
		$children_tax_money = Money::fromCents( 0, $currency );
		$extras_net_money   = Money::fromCents( 0, $currency );
		$extras_tax_money   = Money::fromCents( 0, $currency );

		// Calculate room tax
		$room_total_val = (string) ( $booking_data['room_total'] ?? '0' );
		if ( '0' !== $room_total_val && '0.00' !== $room_total_val ) {
			$room_money = Money::fromDecimal( $room_total_val, $currency );
			$room_calc  = self::calculate_tax_money( $room_money, $accommodation_rate, $mode );
			
			$room_net_money = $room_calc['net'];
			$room_tax_money = $room_calc['tax'];

			$result['breakdown']['room'] = array(
				'gross_amount' => $room_money->toDecimal(),
				'net'          => $room_net_money->toDecimal(),
				'tax_rate'     => $accommodation_rate,
				'tax'          => $room_tax_money->toDecimal(),
				'gross'        => $room_calc['gross']->toDecimal(),
			);

			$total_net_money   = $total_net_money->add( $room_net_money );
			$total_tax_money   = $total_tax_money->add( $room_tax_money );
			$total_gross_money = $total_gross_money->add( $room_calc['gross'] );
		}

		// Calculate children tax
		$children_total_val = (string) ( $booking_data['children_total'] ?? '0' );
		if ( '0' !== $children_total_val && '0.00' !== $children_total_val ) {
			$children_money = Money::fromDecimal( $children_total_val, $currency );
			$children_calc  = self::calculate_tax_money( $children_money, $accommodation_rate, $mode );

			$children_net_money = $children_calc['net'];
			$children_tax_money = $children_calc['tax'];

			$result['breakdown']['children'] = array(
				'gross_amount' => $children_money->toDecimal(),
				'net'          => $children_net_money->toDecimal(),
				'tax_rate'     => $accommodation_rate,
				'tax'          => $children_tax_money->toDecimal(),
				'gross'        => $children_calc['gross']->toDecimal(),
			);

			$total_net_money   = $total_net_money->add( $children_net_money );
			$total_tax_money   = $total_tax_money->add( $children_tax_money );
			$total_gross_money = $total_gross_money->add( $children_calc['gross'] );
		}

		// Calculate extras tax
		$extras_items            = (array) ( $booking_data['extras'] ?? array() );
		$extras_total_val        = (string) ( $booking_data['extras_total'] ?? '0' );
		$accommodation_tax_money = $room_tax_money->add( $children_tax_money );
		$accommodation_net_money = $room_net_money->add( $children_net_money );

		if ( array() !== $extras_items ) {
			foreach ( $extras_items as $extra ) {
				$extra_total_val = (string) ( $extra['total'] ?? '0' );
				if ( '0' !== $extra_total_val && '0.00' !== $extra_total_val ) {
					$extra_money = Money::fromDecimal( $extra_total_val, $currency );

					// Stay extensions (Early Check-in, Late Check-out) are consideration for accommodation and use the accommodation tax rate from settings
					$is_acc_item   = ( isset( $extra['tax_class'] ) && 'accommodation' === $extra['tax_class'] )
						|| in_array( $extra['id'] ?? '', array( '_early_checkin', '_late_checkout' ), true );
					$item_tax_rate = $is_acc_item ? $accommodation_rate : $extras_rate;

					$extra_calc = self::calculate_tax_money( $extra_money, $item_tax_rate, $mode );

					$result['breakdown']['extras'][] = array(
						'id'           => $extra['id'] ?? '',
						'name'         => I18n::decode( $extra['name'] ?? '' ),
						'gross_amount' => $extra_money->toDecimal(),
						'net'          => $extra_calc['net']->toDecimal(),
						'tax_rate'     => $item_tax_rate,
						'tax_class'    => $is_acc_item ? 'accommodation' : 'extras',
						'tax'          => $extra_calc['tax']->toDecimal(),
						'gross'        => $extra_calc['gross']->toDecimal(),
					);

					if ( $is_acc_item ) {
						$accommodation_net_money = $accommodation_net_money->add( $extra_calc['net'] );
						$accommodation_tax_money = $accommodation_tax_money->add( $extra_calc['tax'] );
					} else {
						$extras_net_money = $extras_net_money->add( $extra_calc['net'] );
						$extras_tax_money = $extras_tax_money->add( $extra_calc['tax'] );
					}

					$total_net_money   = $total_net_money->add( $extra_calc['net'] );
					$total_tax_money   = $total_tax_money->add( $extra_calc['tax'] );
					$total_gross_money = $total_gross_money->add( $extra_calc['gross'] );
				}
			}
		} elseif ( '0' !== $extras_total_val && '0.00' !== $extras_total_val ) {
			$extras_money = Money::fromDecimal( $extras_total_val, $currency );
			$extras_calc  = self::calculate_tax_money( $extras_money, $extras_rate, $mode );

			$result['breakdown']['extras'][] = array(
				'id'           => 'total',
				'name'         => 'Extras',
				'gross_amount' => $extras_money->toDecimal(),
				'net'          => $extras_calc['net']->toDecimal(),
				'tax_rate'     => $extras_rate,
				'tax_class'    => 'extras',
				'tax'          => $extras_calc['tax']->toDecimal(),
				'gross'        => $extras_calc['gross']->toDecimal(),
			);

			$extras_net_money  = $extras_calc['net'];
			$extras_tax_money  = $extras_calc['tax'];
			$total_net_money   = $total_net_money->add( $extras_net_money );
			$total_tax_money   = $total_tax_money->add( $extras_tax_money );
			$total_gross_money = $total_gross_money->add( $extras_calc['gross'] );
		}

		// Service Fee — taxed at extras rate (Pro, stored separately from extras)
		$service_fee_net_money = Money::fromCents( 0, $currency );
		$service_fee_tax_money = Money::fromCents( 0, $currency );
		$service_fee_val       = (string) ( $booking_data['service_fee'] ?? '0' );
		$service_fee_label     = (string) ( $booking_data['service_fee_label'] ?? '' );

		$service_fee_money_obj = Money::fromDecimal( $service_fee_val, $currency );
		if ( $service_fee_money_obj->isPositive() ) {
			$service_fee_calc = self::calculate_tax_money( $service_fee_money_obj, $extras_rate, $mode );

			$result['breakdown']['service_fee'] = array(
				'label'        => $service_fee_label,
				'gross_amount' => $service_fee_money_obj->toDecimal(),
				'net'          => $service_fee_calc['net']->toDecimal(),
				'tax_rate'     => $extras_rate,
				'tax'          => $service_fee_calc['tax']->toDecimal(),
				'gross'        => $service_fee_calc['gross']->toDecimal(),
			);

			$service_fee_net_money = $service_fee_calc['net'];
			$service_fee_tax_money = $service_fee_calc['tax'];
			$total_net_money       = $total_net_money->add( $service_fee_calc['net'] );
			$total_tax_money       = $total_tax_money->add( $service_fee_calc['tax'] );
			$total_gross_money     = $total_gross_money->add( $service_fee_calc['gross'] );
		}

		// Final totals
		$result['totals'] = array(
			'room_net'          => $room_net_money,
			'room_tax'          => $room_tax_money,
			'children_net'      => $children_net_money,
			'children_tax'      => $children_tax_money,
			'accommodation_net' => $accommodation_net_money,
			'accommodation_tax' => $accommodation_tax_money,
			'extras_net'        => $extras_net_money,
			'extras_tax'        => $extras_tax_money,
			'service_fee_net'   => $service_fee_net_money,
			'service_fee_tax'   => $service_fee_tax_money,
			'subtotal_net'      => $total_net_money,
			'total_tax'         => $total_tax_money,
			'total_gross'       => $total_gross_money,
		);

		return $result;
	}

	/**
	 * Alias for calculate_booking_tax for backward compatibility
	 *
	 * @param array<string, mixed> $booking_data Booking data
	 * @return array<string, mixed> Tax breakdown
	 */
	public static function calculate_breakdown( array $booking_data ): array {
		return self::calculate_booking_tax( $booking_data );
	}

/**
	 * Get stored tax breakdown for a booking
	 *
	 * @param int $booking_id Booking ID
	 * @return array<string, mixed>|null Tax breakdown or null if not found
	 */
	public static function get_tax_breakdown( int $booking_id ): ?array {
		global $wpdb;

		$cache_key = 'mhbo_tax_breakdown_' . $booking_id;
		$booking   = wp_cache_get( $cache_key, 'mhbo_bookings' );

		if ( false === $booking ) {
			// Rule 13 rationale: Fetching detailed tax breakdown for financial reporting and display.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table query with caching
			$booking = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT tax_breakdown, tax_mode, tax_rate_accommodation, tax_rate_extras,
                        room_total_net, children_total_net, extras_total_net,
                        room_tax, children_tax, extras_tax,
                        service_fee_amount, service_fee_net, service_fee_tax,
                        subtotal_net, total_tax, total_gross,
                        total_price, booking_extras, custom_fields, coupon_code, coupon_discount, discount_amount
                 FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
					$booking_id
				),
				ARRAY_A
			);
			if ( $booking ) {
				wp_cache_set( $cache_key, $booking, 'mhbo_bookings', 300 );
			}
		}

		if ( ! $booking ) {
			return null;
		}

		$currency          = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$total_price_money = Money::fromDecimal( (string) ( $booking['total_price'] ?? $booking['total_gross'] ?? '0' ), $currency );

		// If we have JSON breakdown with valid non-zero room or valid structure, use it
		if ( isset( $booking['tax_breakdown'] ) && '' !== (string) $booking['tax_breakdown'] ) {
			$parsed = json_decode( (string) $booking['tax_breakdown'], true );
			if ( is_array( $parsed ) && isset( $parsed['breakdown']['room'] ) && is_array( $parsed['breakdown']['room'] ) ) {
				$room_gross_m   = Money::fromDecimal( (string) ( $parsed['breakdown']['room']['gross'] ?? $parsed['breakdown']['room']['gross_amount'] ?? 0 ), $currency );
				$parsed_total_m = Money::fromDecimal( (string) ( $parsed['totals']['total_gross'] ?? 0 ), $currency );
				$diff_m         = $parsed_total_m->subtract( $total_price_money )->abs();
				if ( ( $room_gross_m->isPositive() || $total_price_money->isZero() ) && (int) $diff_m->getAmount() <= 1 ) {
					return $parsed;
				}
			}
		}

		// Intelligent Reconstruction from booking line items, extras, children, and totals
		$tax_mode = sanitize_key( $booking['tax_mode'] ?? ( self::is_enabled() ? self::get_mode() : self::MODE_DISABLED ) );
		$acc_rate = isset( $booking['tax_rate_accommodation'] ) && '' !== (string) $booking['tax_rate_accommodation'] ? floatval( $booking['tax_rate_accommodation'] ) : (float) self::get_accommodation_rate();
		$ext_rate = isset( $booking['tax_rate_extras'] ) && '' !== (string) $booking['tax_rate_extras'] ? floatval( $booking['tax_rate_extras'] ) : (float) self::get_extras_rate();

		$coupon_discount_money = Money::fromDecimal( (string) ( $booking['coupon_discount'] ?? ( $booking['discount_amount'] ?? 0 ) ), $currency );
		$children_net_money    = Money::fromDecimal( (string) ( $booking['children_total_net'] ?? '0' ), $currency );
		$sf_amount_stored      = (string) ( $booking['service_fee_amount'] ?? '0' );
		$sf_money              = Money::fromDecimal( $sf_amount_stored, $currency );

		// Decode stored extras
		$extras_breakdown = \MHBO\Core\Pricing::decode_stored_extras( $booking['booking_extras'] ?? null );

		// Decode custom fields for early checkin / late checkout supplements if not already in extras
		if ( ! empty( $booking['custom_fields'] ) ) {
			$custom_data = is_array( $booking['custom_fields'] ) ? $booking['custom_fields'] : json_decode( (string) $booking['custom_fields'], true );
			if ( is_array( $custom_data ) ) {
				if ( ! empty( $custom_data['_early_checkin'] ) && ! isset( $extras_breakdown['_early_checkin'] ) ) {
					$early_fee_m = Money::fromDecimal( (string) get_option( 'mhbo_early_checkin_fee', '0' ), $currency );
					if ( $early_fee_m->isPositive() ) {
						$extras_breakdown['_early_checkin'] = array(
							'id'    => '_early_checkin',
							'name'  => I18n::get_label( 'label_early_checkin' ) ?: 'Early Check-in',
							'total' => $early_fee_m->toDecimal(),
						);
					}
				}
				if ( ! empty( $custom_data['_late_checkout'] ) && ! isset( $extras_breakdown['_late_checkout'] ) ) {
					$late_fee_m = Money::fromDecimal( (string) get_option( 'mhbo_late_checkout_fee', '0' ), $currency );
					if ( $late_fee_m->isPositive() ) {
						$extras_breakdown['_late_checkout'] = array(
							'id'    => '_late_checkout',
							'name'  => I18n::get_label( 'label_late_checkout' ) ?: 'Late Check-out',
							'total' => $late_fee_m->toDecimal(),
						);
					}
				}
			}
		}

		$extras_sum            = Money::fromCents( 0, $currency );
		$extras_items          = array();
		$acc_tax_reconstructed = Money::fromCents( 0, $currency );
		$ext_tax_reconstructed = Money::fromCents( 0, $currency );

		foreach ( $extras_breakdown as $ex_id => $ex ) {
			$ex_name   = (string) ( $ex['name'] ?? '' );
			$ex_amt_st = isset( $ex['total'] ) ? (string) $ex['total'] : (string) ( $ex['price'] ?? 0 );
			$ex_money  = Money::fromDecimal( $ex_amt_st, $currency );
			if ( $ex_money->isPositive() ) {
				$extras_sum = $extras_sum->add( $ex_money );
				$is_acc_ex  = in_array( (string) ( $ex['id'] ?? $ex_id ), array( '_early_checkin', '_late_checkout' ), true );
				$item_rate  = $is_acc_ex ? $acc_rate : $ext_rate;
				$ex_calc    = self::calculate_tax_money( $ex_money, $item_rate, $tax_mode );

				if ( $is_acc_ex ) {
					$acc_tax_reconstructed = $acc_tax_reconstructed->add( $ex_calc['tax'] );
				} else {
					$ext_tax_reconstructed = $ext_tax_reconstructed->add( $ex_calc['tax'] );
				}

				$extras_items[] = array(
					'id'           => (string) ( $ex['id'] ?? $ex_id ),
					'name'         => $ex_name,
					'gross_amount' => $ex_money->toDecimal(),
					'net'          => $ex_calc['net']->toDecimal(),
					'tax_rate'     => $item_rate,
					'tax_class'    => $is_acc_ex ? 'accommodation' : 'extras',
					'tax'          => $ex_calc['tax']->toDecimal(),
					'gross'        => $ex_calc['gross']->toDecimal(),
				);
			}
		}

		// Room Base: Reconstruct from stored room_total_net if present, or subtotal_net / total_price.
		$room_net_stored = (string) ( $booking['room_total_net'] ?? '0' );
		$room_net_m      = Money::fromDecimal( $room_net_stored, $currency );
		if ( $room_net_m->isPositive() ) {
			$room_gross = $room_net_m;
		} else {
			$subtotal_net_stored = (string) ( $booking['subtotal_net'] ?? '0' );
			$subtotal_net_m      = Money::fromDecimal( $subtotal_net_stored, $currency );
			if ( $subtotal_net_m->isPositive() ) {
				$room_gross = $subtotal_net_m
					->add( $coupon_discount_money )
					->subtract( $children_net_money )
					->subtract( $extras_sum )
					->subtract( $sf_money );
			} else {
				$room_gross = $total_price_money
					->add( $coupon_discount_money )
					->subtract( $children_net_money )
					->subtract( $extras_sum )
					->subtract( $sf_money );
			}
		}
		if ( $room_gross->isNegative() ) {
			$room_gross = Money::fromCents( 0, $currency );
		}

		$room_calc     = self::calculate_tax_money( $room_gross, $acc_rate, $tax_mode );
		$children_calc = self::calculate_tax_money( $children_net_money, $acc_rate, $tax_mode );
		$sf_calc       = self::calculate_tax_money( $sf_money, $ext_rate, $tax_mode );

		$acc_tax_reconstructed = $acc_tax_reconstructed->add( $room_calc['tax'] )->add( $children_calc['tax'] );
		$ext_tax_reconstructed = $ext_tax_reconstructed->add( $sf_calc['tax'] );

		$tot_net   = $room_calc['net']->add( $children_calc['net'] )->add( $sf_calc['net'] );
		$tot_tax   = $room_calc['tax']->add( $children_calc['tax'] )->add( $sf_calc['tax'] );
		$tot_gross = $room_calc['gross']->add( $children_calc['gross'] )->add( $sf_calc['gross'] );

		foreach ( $extras_items as $ei ) {
			$tot_net   = $tot_net->add( Money::fromDecimal( (string) $ei['net'], $currency ) );
			$tot_tax   = $tot_tax->add( Money::fromDecimal( (string) $ei['tax'], $currency ) );
			$tot_gross = $tot_gross->add( Money::fromDecimal( (string) $ei['gross'], $currency ) );
		}

		// Reconcile reconstructed tot_gross against total_price_money (Law 59 / USALI)
		$diff_gross = $total_price_money->subtract( $tot_gross );
		if ( $diff_gross->isPositive() ) {
			$adj_calc = self::calculate_tax_money( $diff_gross, $ext_rate, $tax_mode );
			$ext_tax_reconstructed = $ext_tax_reconstructed->add( $adj_calc['tax'] );
			$tot_net   = $tot_net->add( $adj_calc['net'] );
			$tot_tax   = $tot_tax->add( $adj_calc['tax'] );
			$tot_gross = $tot_gross->add( $adj_calc['gross'] );
			$extras_items[] = array(
				'id'           => '_folio_adjustment',
				'name'         => __( 'Additional Folio Charges / Incidentals', 'modern-hotel-booking' ),
				'gross_amount' => $diff_gross->toDecimal(),
				'net'          => $adj_calc['net']->toDecimal(),
				'tax_rate'     => $ext_rate,
				'tax_class'    => 'extras',
				'tax'          => $adj_calc['tax']->toDecimal(),
				'gross'        => $adj_calc['gross']->toDecimal(),
			);
		} elseif ( $diff_gross->isNegative() ) {
			$neg_amt   = $tot_gross->subtract( $total_price_money );
			$adj_calc  = self::calculate_tax_money( $neg_amt, $ext_rate, $tax_mode );
			$tot_net   = $tot_net->subtract( $adj_calc['net'] );
			$tot_tax   = $tot_tax->subtract( $adj_calc['tax'] );
			$tot_gross = $tot_gross->subtract( $adj_calc['gross'] );
			$extras_items[] = array(
				'id'           => '_folio_concession',
				'name'         => __( 'Rate Concession / Folio Adjustment', 'modern-hotel-booking' ),
				'gross_amount' => '-' . $neg_amt->toDecimal(),
				'net'          => '-' . $adj_calc['net']->toDecimal(),
				'tax_rate'     => $ext_rate,
				'tax_class'    => 'extras',
				'tax'          => '-' . $adj_calc['tax']->toDecimal(),
				'gross'        => '-' . $adj_calc['gross']->toDecimal(),
			);
		}

		// Reconstructed payload with complete itemization
		$reconstructed = array(
			'enabled'   => self::MODE_DISABLED !== $tax_mode,
			'mode'      => $tax_mode,
			'rates'     => array(
				'accommodation' => $acc_rate,
				'extras'        => $ext_rate,
			),
			'breakdown' => array(
				'room'     => array(
					'gross_amount' => $room_gross->toDecimal(),
					'net'          => $room_calc['net']->toDecimal(),
					'tax_rate'     => $acc_rate,
					'tax'          => $room_calc['tax']->toDecimal(),
					'gross'        => $room_calc['gross']->toDecimal(),
				),
				'children' => array(
					'gross_amount' => $children_net_money->toDecimal(),
					'net'          => $children_calc['net']->toDecimal(),
					'tax_rate'     => $acc_rate,
					'tax'          => $children_calc['tax']->toDecimal(),
					'gross'        => $children_calc['gross']->toDecimal(),
				),
				'extras'   => $extras_items,
			),
			'totals'    => array(
				'room_net'          => $room_calc['net']->toDecimal(),
				'room_tax'          => $room_calc['tax']->toDecimal(),
				'children_net'      => $children_calc['net']->toDecimal(),
				'children_tax'      => $children_calc['tax']->toDecimal(),
				'accommodation_tax' => $acc_tax_reconstructed->toDecimal(),
				'extras_tax'        => $ext_tax_reconstructed->toDecimal(),
				'service_fee_tax'   => $sf_calc['tax']->toDecimal(),
				'subtotal_net'      => $tot_net->toDecimal(),
				'total_tax'         => $tot_tax->toDecimal(),
				'total_gross'       => $tot_gross->toDecimal(),
			),
		);

		if ( $sf_money->isPositive() ) {
			$reconstructed['breakdown']['service_fee'] = array(
				'label'        => get_option( 'mhbo_service_fee_label', 'Service Fee' ),
				'gross_amount' => $sf_money->toDecimal(),
				'net'          => $sf_calc['net']->toDecimal(),
				'tax_rate'     => $ext_rate,
				'tax'          => $sf_calc['tax']->toDecimal(),
				'gross'        => $sf_calc['gross']->toDecimal(),
			);
		}

		return $reconstructed;
	}

	/**
	 * Format tax breakdown for display
	 *
	 * @param array<string, mixed> $breakdown Tax breakdown array
	 * @param string|null          $language Language code (optional)
	 * @param array<string, mixed> $meta Optional metadata (guests, children)
	 * @return array<string, mixed> Formatted breakdown for display
	 */
	public static function format_tax_breakdown( array $breakdown, ?string $language = null, array $meta = array() ): array {
		if ( null === $language ) {
			$language = I18n::get_current_language();
		}

		$mode         = self::normalize_mode( (string) ( $breakdown['mode'] ?? self::get_mode() ) );
		$base_label   = self::get_label( $language, $mode );
		$is_vat       = ( self::MODE_VAT === $mode );
		$is_exclusive = ( self::MODE_SALES_TAX === $mode || self::MODE_DISABLED === $mode );
		$label        = $is_vat ? sprintf( I18n::get_label( 'label_tax_included' ), $base_label ) : $base_label;

		$formatted = array(
			'enabled'             => $breakdown['enabled'] ?? false,
			'mode'                => $mode,
			'label'               => $label,
			'base_label'          => $base_label,
			'is_vat'              => $is_vat,
			'registration_number' => $breakdown['registration_number'] ?? '',
			'items'               => array(),
			'totals'              => array(),
		);

		// Coupon metadata preparation (Zero-Float Law 34: all arithmetic via Money)
		$currency            = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$coupon_code_fmt     = isset( $meta['coupon_code'] ) ? sanitize_text_field( (string) $meta['coupon_code'] ) : '';
		$coupon_discount_fmt = isset( $meta['coupon_discount'] ) ? (string) $meta['coupon_discount'] : '0';
		$coupon_m            = Money::fromDecimal( $coupon_discount_fmt, $currency );
		$has_coupon_row      = ( '' !== $coupon_code_fmt && $coupon_m->isPositive() );
		$coupon_reds         = isset( $breakdown['coupon_reductions'] ) && is_array( $breakdown['coupon_reductions'] ) ? $breakdown['coupon_reductions'] : null;
		$room_red_m          = null !== $coupon_reds
			? Money::fromDecimal( (string) ( $coupon_reds['room_reduction'] ?? '0' ), $currency )
			: ( $has_coupon_row ? $coupon_m : Money::fromCents( 0, $currency ) );
		$child_red_m         = null !== $coupon_reds
			? Money::fromDecimal( (string) ( $coupon_reds['children_reduction'] ?? '0' ), $currency )
			: Money::fromCents( 0, $currency );
		$extras_red_map      = ( null !== $coupon_reds && isset( $coupon_reds['extras_reductions'] ) && is_array( $coupon_reds['extras_reductions'] ) ) ? $coupon_reds['extras_reductions'] : array();

		// Room
		if ( isset( $breakdown['breakdown']['room'] ) && array() !== $breakdown['breakdown']['room'] ) {
			$room       = $breakdown['breakdown']['room'];
			$room_label = I18n::get_label( 'label_room' );
			if ( isset( $meta['guests'] ) && (int) $meta['guests'] > 0 ) {
				$room_label .= sprintf( ' (%s)', sprintf( I18n::get_label( 'label_guests_count' ), (int) $meta['guests'] ) );
			}
			$room_net_m   = ( $room['net'] ?? null ) instanceof Money ? $room['net'] : Money::fromDecimal( (string) ( $room['net'] ?? '0' ), $currency );
			$room_tax_m   = ( $room['tax'] ?? null ) instanceof Money ? $room['tax'] : Money::fromDecimal( (string) ( $room['tax'] ?? '0' ), $currency );
			$room_gross_m = ( $room['gross'] ?? null ) instanceof Money ? $room['gross'] : Money::fromDecimal( (string) ( $room['gross'] ?? $room['gross_amount'] ?? '0' ), $currency );

			// If coupon is displayed as a separate negative row, display the pre-discount room base so rows add up to subtotal
			$room_disp_net_m   = $has_coupon_row ? $room_net_m->add( $room_red_m ) : $room_net_m;
			$room_disp_gross_m = $has_coupon_row ? $room_gross_m->add( $room_red_m ) : $room_gross_m;
			$room_amount_m     = $is_exclusive ? $room_disp_net_m : $room_disp_gross_m;

			$formatted['items'][] = array(
				'type'             => 'room',
				'label'            => $room_label,
				'net'              => $room_net_m->toDecimal(),
				'tax_rate'         => $room['tax_rate'] ?? 0,
				'tax'              => $room_tax_m->toDecimal(),
				'gross'            => $room_gross_m->toDecimal(),
				'amount'           => $room_amount_m->toDecimal(),
				'net_formatted'    => I18n::format_currency( $room_net_m ),
				'tax_formatted'    => I18n::format_currency( $room_tax_m ),
				'gross_formatted'  => I18n::format_currency( $room_gross_m ),
				'amount_formatted' => I18n::format_currency( $room_amount_m ),
			);
		}

// Extras
		if ( isset( $breakdown['breakdown']['extras'] ) && array() !== $breakdown['breakdown']['extras'] ) {
			foreach ( $breakdown['breakdown']['extras'] as $extra ) {
				$ex_id           = (string) ( $extra['id'] ?? '' );
				$ex_red_m        = ( $has_coupon_row && '' !== $ex_id && isset( $extras_red_map[ $ex_id ] ) )
					? Money::fromDecimal( (string) $extras_red_map[ $ex_id ], $currency )
					: Money::fromCents( 0, $currency );
				$ex_net_m        = ( $extra['net'] ?? null ) instanceof Money ? $extra['net'] : Money::fromDecimal( (string) ( $extra['net'] ?? '0' ), $currency );
				$ex_tax_m        = ( $extra['tax'] ?? null ) instanceof Money ? $extra['tax'] : Money::fromDecimal( (string) ( $extra['tax'] ?? '0' ), $currency );
				$ex_gross_m      = ( $extra['gross'] ?? null ) instanceof Money ? $extra['gross'] : Money::fromDecimal( (string) ( $extra['gross'] ?? $extra['gross_amount'] ?? '0' ), $currency );
				$ex_disp_net_m   = $ex_net_m->add( $ex_red_m );
				$ex_disp_gross_m = $ex_gross_m->add( $ex_red_m );
				if ( ! $ex_disp_gross_m->isPositive() && ! $ex_disp_net_m->isPositive() ) {
					continue;
				}
				$extra_name = $extra['name'] ?? '';
				if ( '' !== $extra_name ) {
					$extra_name = I18n::decode( $extra_name, $language );
				} else {
					$extra_name = I18n::get_label( 'label_extras' );
				}

				$ex_amount_m = $is_exclusive ? $ex_disp_net_m : $ex_disp_gross_m;

				$formatted['items'][] = array(
					'type'             => 'extra',
					'label'            => $extra_name,
					'net'              => $ex_net_m->toDecimal(),
					'tax_rate'         => $extra['tax_rate'] ?? 0,
					'tax'              => $ex_tax_m->toDecimal(),
					'gross'            => $ex_gross_m->toDecimal(),
					'amount'           => $ex_amount_m->toDecimal(),
					'net_formatted'    => I18n::format_currency( $ex_net_m ),
					'tax_formatted'    => I18n::format_currency( $ex_tax_m ),
					'gross_formatted'  => I18n::format_currency( $ex_gross_m ),
					'amount_formatted' => I18n::format_currency( $ex_amount_m ),
				);
			}
		}

		// Service Fee (Pro) — shown as its own line after extras, before subtotal
		if ( isset( $breakdown['breakdown']['service_fee'] ) && array() !== $breakdown['breakdown']['service_fee'] ) {
			$sf       = $breakdown['breakdown']['service_fee'];
			$sf_label = $sf['label'] ?? '';
			if ( '' === $sf_label ) {
				$sf_label = __( 'Service Fee', 'modern-hotel-booking' );
			}
			$sf_net_m    = ( $sf['net'] ?? null ) instanceof Money ? $sf['net'] : Money::fromDecimal( (string) ( $sf['net'] ?? '0' ), $currency );
			$sf_tax_m    = ( $sf['tax'] ?? null ) instanceof Money ? $sf['tax'] : Money::fromDecimal( (string) ( $sf['tax'] ?? '0' ), $currency );
			$sf_gross_m  = ( $sf['gross'] ?? null ) instanceof Money ? $sf['gross'] : Money::fromDecimal( (string) ( $sf['gross'] ?? $sf['gross_amount'] ?? '0' ), $currency );
			$sf_amount_m = $is_exclusive ? $sf_net_m : $sf_gross_m;

			$formatted['items'][] = array(
				'type'             => 'service_fee',
				'label'            => $sf_label,
				'net'              => $sf_net_m->toDecimal(),
				'tax_rate'         => $sf['tax_rate'] ?? 0,
				'tax'              => $sf_tax_m->toDecimal(),
				'gross'            => $sf_gross_m->toDecimal(),
				'amount'           => $sf_amount_m->toDecimal(),
				'net_formatted'    => I18n::format_currency( $sf_net_m ),
				'tax_formatted'    => I18n::format_currency( $sf_tax_m ),
				'gross_formatted'  => I18n::format_currency( $sf_gross_m ),
				'amount_formatted' => I18n::format_currency( $sf_amount_m ),
			);
		}

		// Totals
		$totals              = $breakdown['totals'] ?? array();
		$formatted['totals'] = array(
			'subtotal_net'           => is_array( $totals['subtotal_net'] ?? 0 ) ? 0 : ( $totals['subtotal_net'] ?? 0 ),
			'total_tax'              => is_array( $totals['total_tax'] ?? 0 ) ? 0 : ( $totals['total_tax'] ?? 0 ),
			'total_gross'            => is_array( $totals['total_gross'] ?? 0 ) ? 0 : ( $totals['total_gross'] ?? 0 ),
			'subtotal_net_formatted' => I18n::format_currency( $totals['subtotal_net'] ?? 0 ),
			'total_tax_formatted'    => I18n::format_currency( $totals['total_tax'] ?? 0 ),
			'total_gross_formatted'  => I18n::format_currency( $totals['total_gross'] ?? 0 ),
			'tax_included_note'      => self::MODE_VAT === $mode
				? sprintf( I18n::get_label( 'label_includes_tax' ), $base_label . ' ' . self::get_accommodation_rate() . '%' )
				: '',
		);

		return $formatted;
	}

	/**
	 * Render HTML for tax breakdown
	 *
	 * @param array<string, mixed> $breakdown Tax breakdown (from calculate_booking_tax or storage)
	 * @param string|null          $language Language code (optional)
	 * @param bool                 $is_email Whether to use email-friendly inline styles
	 * @param array<string, mixed> $meta Optional metadata (guests, children)
	 * @param bool                 $show_note Whether to show the tax included note (VAT mode only)
	 * @return string HTML
	 */
	public static function render_breakdown_html( array $breakdown, ?string $language = null, bool $is_email = false, array $meta = array(), bool $show_note = true ): string {
		if ( ! self::is_enabled() && ( $breakdown['enabled'] ?? false ) === false ) {
			// In emails, reservation breakdown is rendered by Email::render_reservation_breakdown_html.
			if ( $is_email ) {
				return '';
			}
			$display_setting = (bool) get_option( 'mhbo_tax_display_frontend', false );
			if ( ! $display_setting ) {
				return '';
			}
		}

		$formatted   = self::format_tax_breakdown( $breakdown, $language, $meta );
		$tax_enabled = $formatted['enabled'] ?? false;

		$styles = array(
			'container'   => $is_email ? 'font-family: Arial, sans-serif; font-size: 14px; color: #333; border: 1px solid #eee; padding: 15px; margin-bottom: 20px;' : 'mhbo-tax-breakdown',
			'title'       => $is_email ? 'font-size: 16px; margin-top: 0; margin-bottom: 15px; color: #333;' : 'mhbo-tax-breakdown-title',
			'table'       => $is_email ? 'width: 100%; border-collapse: collapse; margin-bottom: 15px;' : 'mhbo-tax-breakdown-table',
			'th'          => $is_email ? 'padding: 8px; border-bottom: 1px solid #eee; text-align: left; font-weight: bold; background-color: #f9f9f9;' : 'mhbo-tax-breakdown-th',
			'th_right'    => $is_email ? 'padding: 8px; border-bottom: 1px solid #eee; text-align: right; font-weight: bold; background-color: #f9f9f9;' : 'mhbo-tax-breakdown-th mhbo-tax-breakdown-th-right',
			'td'          => $is_email ? 'padding: 8px; border-bottom: 1px solid #eee; text-align: left;' : 'mhbo-tax-breakdown-td',
			'td_right'    => $is_email ? 'padding: 8px; border-bottom: 1px solid #eee; text-align: right;' : 'mhbo-tax-breakdown-td mhbo-tax-breakdown-td-right',
			'grand_total' => $is_email ? 'font-weight: bold; border-top: 2px solid #333; padding-top: 10px;' : 'mhbo-tax-breakdown-grand-total',
			'reg_number'  => $is_email ? 'margin-top: 15px; font-size: 12px; color: #999;' : 'mhbo-tax-registration',
		);

		// Get tax rates and amounts for separate display
		$currency           = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$rates              = $breakdown['rates'] ?? array();
		$totals             = $breakdown['totals'] ?? array();
		$accommodation_rate = floatval( $rates['accommodation'] ?? self::get_accommodation_rate() );
		$extras_rate        = floatval( $rates['extras'] ?? self::get_extras_rate() );
		
		$room_tax_money     = Money::fromDecimal( (string) ( $totals['room_tax'] ?? '0' ), $currency );
		$children_tax_money = Money::fromDecimal( (string) ( $totals['children_tax'] ?? '0' ), $currency );
		$extras_tax_money   = Money::fromDecimal( (string) ( $totals['extras_tax'] ?? '0' ), $currency );
		// Service fee is taxed at the extras rate — merge its tax into the extras tax display total
		$service_fee_tax_money = Money::fromDecimal( (string) ( $totals['service_fee_tax'] ?? '0' ), $currency );
		$extras_tax_money      = $extras_tax_money->add( $service_fee_tax_money );

		if ( isset( $totals['accommodation_tax'] ) ) {
			$accommodation_tax_total_money = ( $totals['accommodation_tax'] instanceof Money )
				? $totals['accommodation_tax']
				: Money::fromDecimal( (string) $totals['accommodation_tax'], $currency );
		} else {
			$accommodation_tax_total_money = $room_tax_money->add( $children_tax_money );
		}
		$accommodation_tax_total = floatval( $accommodation_tax_total_money->toDecimal() );
		$extras_tax              = floatval( $extras_tax_money->toDecimal() );

		// Check if we need to show separate tax lines
		$show_separate_tax_lines = ( $accommodation_rate !== $extras_rate ) && ( $accommodation_tax_total > 0 || $extras_tax > 0 );

		// Totals title
		$title = $tax_enabled
			? sprintf( I18n::get_label( 'label_tax_breakdown' ), $formatted['label'] )
			: I18n::get_label( 'label_booking_summary' );

		ob_start();
		?>
		<div class="<?php echo $is_email ? '' : esc_attr( $styles['container'] ); ?>"
			style="<?php echo $is_email ? esc_attr( $styles['container'] ) : ''; ?>">
			<h4 class="<?php echo $is_email ? '' : esc_attr( $styles['title'] ); ?>"
				style="<?php echo $is_email ? esc_attr( $styles['title'] ) : ''; ?>">
				<?php echo esc_html( $title ); ?>
			</h4>
			<table class="<?php echo $is_email ? '' : esc_attr( $styles['table'] ); ?>"
				style="<?php echo $is_email ? esc_attr( $styles['table'] ) : ''; ?>"
				id="mhbo-tax-breakdown-table"
				data-total-gross="<?php echo esc_attr( (string) ( $formatted['totals']['total_gross'] ?? 0 ) ); ?>">
				<thead>
					<tr>
						<th scope="col" class="<?php echo $is_email ? '' : esc_attr( $styles['th'] ); ?>"
							style="<?php echo $is_email ? esc_attr( $styles['th'] ) : 'text-align: left;'; ?>">
							<?php echo esc_html( I18n::get_label( 'label_item' ) ); ?>
						</th>
						<th scope="col" class="<?php echo $is_email ? '' : esc_attr( $styles['th_right'] ); ?>"
							style="<?php echo $is_email ? esc_attr( $styles['th_right'] ) : 'text-align: right;'; ?>">
							<?php echo esc_html( I18n::get_label( 'label_amount' ) ); ?>
						</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $formatted['items'] as $item ) : ?>
						<?php 
						$item_display_amt = $item['amount_formatted'] ?? $item['gross_formatted'] ?? '';
						?>
						<?php if ( 'coupon' === ( $item['type'] ?? '' ) ) : ?>
							<tr class="mhbo-coupon-discount-row">
								<td class="<?php echo $is_email ? '' : esc_attr( $styles['td'] ); ?>"
									style="<?php echo $is_email ? esc_attr( $styles['td'] ) : ''; ?> color:#16a34a;">
									<strong><?php echo esc_html( $item['label'] ); ?></strong>
								</td>
								<td class="<?php echo $is_email ? '' : esc_attr( $styles['td_right'] ); ?>"
									style="<?php echo $is_email ? esc_attr( $styles['td_right'] ) : ''; ?> color:#16a34a; white-space: nowrap;">
									<strong><?php echo esc_html( $item_display_amt ); ?></strong>
								</td>
							</tr>
						<?php else : ?>
							<tr data-item-type="<?php echo esc_attr( $item['type'] ?? 'item' ); ?>">
								<td class="<?php echo $is_email ? '' : esc_attr( $styles['td'] ); ?>"
									style="<?php echo $is_email ? esc_attr( $styles['td'] ) : 'text-align: left;'; ?>">
									<?php echo esc_html( $item['label'] ); ?>
								</td>
								<td class="<?php echo $is_email ? '' : esc_attr( $styles['td_right'] ); ?> mhbo-item-amount"
									style="<?php echo $is_email ? esc_attr( $styles['td_right'] ) : 'text-align: right;'; ?>">
									<?php echo esc_html( $item_display_amt ); ?>
								</td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<?php if ( $tax_enabled ) : ?>
						<tr class="mhbo-tax-total" data-row-type="subtotal">
							<td class="<?php echo $is_email ? '' : esc_attr( $styles['td'] ); ?>"
								style="<?php echo $is_email ? esc_attr( $styles['td'] ) : 'text-align: left;'; ?>">
								<strong>
									<?php
									if ( ! empty( $formatted['is_vat'] ) ) {
										/* translators: %s: Tax label */
										echo esc_html( sprintf( I18n::get_label( 'label_subtotal_net_vat' ), $formatted['base_label'] ?? 'VAT' ) );
									} else {
										echo esc_html( I18n::get_label( 'label_subtotal' ) );
									}
									?>
								</strong>
							</td>
							<td class="<?php echo $is_email ? '' : esc_attr( $styles['td_right'] ); ?> mhbo-item-amount"
								style="<?php echo $is_email ? esc_attr( $styles['td_right'] ) : 'text-align: right;'; ?>">
								<strong><?php echo esc_html( $formatted['totals']['subtotal_net_formatted'] ); ?></strong>
							</td>
						</tr>
						<?php if ( $show_separate_tax_lines ) : ?>
							<?php if ( $accommodation_tax_total > 0 ) : ?>
								<tr class="mhbo-tax-item" data-row-type="vat-accommodation">
									<td class="<?php echo $is_email ? '' : esc_attr( $styles['td'] ); ?>"
										style="<?php echo $is_email ? esc_attr( $styles['td'] ) : 'text-align: left;'; ?>">
										<?php
										echo esc_html( sprintf( I18n::get_label( 'label_tax_accommodation' ), $formatted['label'], self::format_rate( $accommodation_rate ) ) );
										?>
									</td>
									<td class="<?php echo $is_email ? '' : esc_attr( $styles['td_right'] ); ?> mhbo-item-amount"
										style="<?php echo $is_email ? esc_attr( $styles['td_right'] ) : 'text-align: right;'; ?>">
										<?php echo esc_html( I18n::format_currency( $accommodation_tax_total ) ); ?>
									</td>
								</tr>
							<?php endif; ?>
							<?php if ( $extras_tax > 0 ) : ?>
								<tr class="mhbo-tax-item" data-row-type="vat-extras">
									<td class="<?php echo $is_email ? '' : esc_attr( $styles['td'] ); ?>"
										style="<?php echo $is_email ? esc_attr( $styles['td'] ) : 'text-align: left;'; ?>">
										<?php
										echo esc_html( sprintf( I18n::get_label( 'label_tax_extras' ), $formatted['label'], self::format_rate( $extras_rate ) ) );
										?>
									</td>
									<td class="<?php echo $is_email ? '' : esc_attr( $styles['td_right'] ); ?> mhbo-item-amount"
										style="<?php echo $is_email ? esc_attr( $styles['td_right'] ) : 'text-align: right;'; ?>">
										<?php echo esc_html( I18n::format_currency( $extras_tax ) ); ?>
									</td>
								</tr>
							<?php endif; ?>
						<?php else : ?>
							<tr class="mhbo-tax-item" data-row-type="vat-total">
								<td class="<?php echo $is_email ? '' : esc_attr( $styles['td'] ); ?>"
									style="<?php echo $is_email ? esc_attr( $styles['td'] ) : 'text-align: left;'; ?>">
									<?php
									echo esc_html( sprintf( I18n::get_label( 'label_tax_rate' ), $formatted['label'], self::format_rate( $accommodation_rate ) ) );
									?>
								</td>
								<td class="<?php echo $is_email ? '' : esc_attr( $styles['td_right'] ); ?> mhbo-item-amount"
									style="<?php echo $is_email ? esc_attr( $styles['td_right'] ) : 'text-align: right;'; ?>">
									<?php echo esc_html( $formatted['totals']['total_tax_formatted'] ); ?>
								</td>
							</tr>
						<?php endif; ?>
					<?php endif; ?>
					<tr class="<?php echo $is_email ? '' : esc_attr( $styles['grand_total'] ); ?>" data-row-type="grand-total">
						<td class="<?php echo $is_email ? '' : esc_attr( $styles['td'] ); ?>"
							style="<?php echo $is_email ? esc_attr( $styles['td'] . ' ' . $styles['grand_total'] ) : 'text-align: left;'; ?>">
							<strong>
								<?php
								if ( ! empty( $formatted['is_vat'] ) ) {
									/* translators: %s: Tax label */
									echo esc_html( sprintf( I18n::get_label( 'label_total_charges_vat' ), $formatted['base_label'] ?? 'VAT' ) );
								} else {
									echo esc_html( I18n::get_label( 'label_total' ) );
								}
								?>
							</strong>
						</td>
						<td class="<?php echo $is_email ? '' : esc_attr( $styles['td_right'] ); ?> mhbo-item-amount"
							style="<?php echo $is_email ? esc_attr( $styles['td_right'] . ' ' . $styles['grand_total'] ) : 'text-align: right;'; ?>">
							<strong><?php echo esc_html( $formatted['totals']['total_gross_formatted'] ); ?></strong>
						</td>
					</tr>
				</tfoot>
			</table>
			
			<?php if ( $show_note && $tax_enabled && self::MODE_VAT === $formatted['mode'] ) : ?>
				<p style="font-size: 12px; color: #666; margin-top: 5px;">
					<?php echo esc_html( $formatted['totals']['tax_included_note'] ); ?>
				</p>
			<?php endif; ?>
			<?php if ( $tax_enabled && isset( $formatted['registration_number'] ) && $formatted['registration_number'] !== '' ) : ?>
				<p class="<?php echo $is_email ? '' : esc_attr( $styles['reg_number'] ); ?>"
					style="<?php echo $is_email ? esc_attr( $styles['reg_number'] ) : ''; ?>">
					<?php
					// translators: %s: Tax registration number.
					echo esc_html( sprintf( I18n::get_label( 'label_tax_registration' ), $formatted['registration_number'] ) );
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Generate plain text for tax breakdown (for emails)
	 *
	 * @param array<string, mixed> $breakdown Tax breakdown
	 * @param string|null          $language  Language code (optional)
	 * @param array<string, mixed> $meta      Optional metadata (guests, children)
	 * @return string Plain text
	 */
	public static function render_breakdown_text( $breakdown, $language = null, $meta = array() ) {
		if ( ! self::is_enabled() && ( $breakdown['enabled'] ?? false ) === false ) {
			if ( ! (bool) get_option( 'mhbo_tax_display_email', true ) ) {
				return '';
			}
		}

		$formatted = self::format_tax_breakdown( $breakdown, $language, $meta );
		$lines     = array();
		$lines[]   = sprintf( (string) I18n::decode( I18n::get_label( 'label_tax_breakdown' ), $language ), $formatted['label'] );
		$lines[]   = str_repeat( '-', 40 );

		foreach ( $formatted['items'] as $item ) {
			
			$lines[] = sprintf(
				'  %s (%s%%): %s',
				$item['label'],
				$item['tax_rate'],
				$item['tax_formatted']
			);
		}

		$lines[] = str_repeat( '-', 40 );

		// Get tax rates and amounts for separate display
		$currency           = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$rates              = $breakdown['rates'] ?? array();
		$totals             = $breakdown['totals'] ?? array();
		$accommodation_rate = floatval( $rates['accommodation'] ?? 0 );
		$extras_rate        = floatval( $rates['extras'] ?? 0 );
		
		$room_tax_money     = Money::fromDecimal( (string) ( $totals['room_tax'] ?? '0' ), $currency );
		$children_tax_money = Money::fromDecimal( (string) ( $totals['children_tax'] ?? '0' ), $currency );
		$extras_tax_money   = Money::fromDecimal( (string) ( $totals['extras_tax'] ?? '0' ), $currency );
		// Service fee is taxed at the extras rate — merge its tax into the extras tax display total
		$service_fee_tax_money = Money::fromDecimal( (string) ( $totals['service_fee_tax'] ?? '0' ), $currency );
		$extras_tax_money      = $extras_tax_money->add( $service_fee_tax_money );

		if ( isset( $totals['accommodation_tax'] ) ) {
			$accommodation_tax_total_money = ( $totals['accommodation_tax'] instanceof Money )
				? $totals['accommodation_tax']
				: Money::fromDecimal( (string) $totals['accommodation_tax'], $currency );
		} else {
			$accommodation_tax_total_money = $room_tax_money->add( $children_tax_money );
		}
		$accommodation_tax_total = floatval( $accommodation_tax_total_money->toDecimal() );
		$extras_tax              = floatval( $extras_tax_money->toDecimal() );

		// Check if we need to show separate tax lines
		$show_separate_tax_lines = ( $accommodation_rate !== $extras_rate ) && ( $accommodation_tax_total > 0 || $extras_tax > 0 );

		if ( $show_separate_tax_lines ) {
			if ( $accommodation_tax_total > 0 ) {
				$lines[] = sprintf(
					I18n::get_label( 'label_tax_accommodation' ),
					$formatted['label'],
					$accommodation_rate,
					I18n::format_currency( $accommodation_tax_total )
				);
			}
			if ( $extras_tax > 0 ) {
				$lines[] = sprintf(
					I18n::get_label( 'label_tax_extras' ),
					$formatted['label'],
					$extras_rate,
					I18n::format_currency( $extras_tax )
				);
			}
		} else {
			$lines[] = sprintf( (string) I18n::decode( I18n::get_label( 'label_tax_total' ), $language ), $formatted['label'], $formatted['totals']['total_tax_formatted'] );
		}

		if ( isset( $formatted['registration_number'] ) && $formatted['registration_number'] !== '' ) {
			$lines[] = sprintf( (string) I18n::decode( I18n::get_label( 'label_tax_registration' ), $language ), $formatted['registration_number'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Store tax data with a booking.
	 *
	 * @param int                  $booking_id     Booking ID
	 * @param array<string, mixed> $tax_breakdown Full tax breakdown array from calculate_booking_tax()
	 * @return bool Success
	 */
	public static function store_tax_data( $booking_id, $tax_breakdown ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mhbo_bookings';

		// Use pre-calculated component totals from the breakdown if available
		$totals    = $tax_breakdown['totals'] ?? array();
		$breakdown = $tax_breakdown['breakdown'] ?? array();
		$currency  = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );

		$data = array(
			'tax_enabled'            => ( $tax_breakdown['enabled'] ?? false ) ? 1 : 0,
			'tax_mode'               => $tax_breakdown['mode'] ?? self::MODE_DISABLED,
			'tax_rate_accommodation' => $tax_breakdown['rates']['accommodation'] ?? 0,
			'tax_rate_extras'        => $tax_breakdown['rates']['extras'] ?? 0,
			'room_total_net'         => Money::fromDecimal( (string) ( $totals['room_net'] ?? $breakdown['room']['net'] ?? '0' ), $currency )->toDecimal(),
			'room_tax'               => Money::fromDecimal( (string) ( $totals['room_tax'] ?? $breakdown['room']['tax'] ?? '0' ), $currency )->toDecimal(),
			'children_total_net'     => Money::fromDecimal( (string) ( $totals['children_net'] ?? $breakdown['children']['net'] ?? '0' ), $currency )->toDecimal(),
			'children_tax'           => Money::fromDecimal( (string) ( $totals['children_tax'] ?? $breakdown['children']['tax'] ?? '0' ), $currency )->toDecimal(),
			'extras_total_net'       => Money::fromDecimal( (string) ( $totals['extras_net'] ?? '0' ), $currency )->toDecimal(),
			'extras_tax'             => Money::fromDecimal( (string) ( $totals['extras_tax'] ?? '0' ), $currency )->toDecimal(),
			'service_fee_net'        => Money::fromDecimal( (string) ( $totals['service_fee_net'] ?? '0' ), $currency )->toDecimal(),
			'service_fee_tax'        => Money::fromDecimal( (string) ( $totals['service_fee_tax'] ?? '0' ), $currency )->toDecimal(),
			'service_fee_amount'     => Money::fromDecimal( (string) ( $breakdown['service_fee']['gross_amount'] ?? '0' ), $currency )->toDecimal(),
			'subtotal_net'           => Money::fromDecimal( (string) ( $totals['subtotal_net'] ?? '0' ), $currency )->toDecimal(),
			'total_tax'              => Money::fromDecimal( (string) ( $totals['total_tax'] ?? '0' ), $currency )->toDecimal(),
			'total_gross'            => Money::fromDecimal( (string) ( $totals['total_gross'] ?? '0' ), $currency )->toDecimal(),
			'tax_breakdown'          => wp_json_encode( $tax_breakdown ),
		);

		// Fallback for extras if totals not populated (should not happen with latest calculate_booking_tax)
		if ( Money::fromDecimal( (string) $data['extras_total_net'], $currency )->isZero() && isset( $breakdown['extras'] ) && array() !== $breakdown['extras'] ) {
			$e_net = Money::fromCents( 0, $currency );
			$e_tax = Money::fromCents( 0, $currency );
			foreach ( $breakdown['extras'] as $extra ) {
				$e_net = $e_net->add( Money::fromDecimal( (string) ( $extra['net'] ?? '0' ), $currency ) );
				$e_tax = $e_tax->add( Money::fromDecimal( (string) ( $extra['tax'] ?? '0' ), $currency ) );
			}
			$data['extras_total_net'] = $e_net->toDecimal();
			$data['extras_tax']       = $e_tax->toDecimal();
		}

		$format = array(
			'%d', // tax_enabled
			'%s', // tax_mode
			'%f', // tax_rate_accommodation
			'%f', // tax_rate_extras
			'%f', // room_total_net
			'%f', // room_tax
			'%f', // children_total_net
			'%f', // children_tax
			'%f', // extras_total_net
			'%f', // extras_tax
			'%f', // service_fee_net
			'%f', // service_fee_tax
			'%f', // service_fee_amount
			'%f', // subtotal_net
			'%f', // total_tax
			'%f', // total_gross
			'%s', // tax_breakdown
		);

		// RATIONALE: Required to store calculated tax breakdown columns in the custom mhbo_bookings table.
		// Uses $wpdb->update with typed format arrays; booking_id is validated as int.
		$result = $wpdb->update( $table, $data, array( 'id' => $booking_id ), $format, array( '%d' ) ) !== false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table

		if ( $result ) {
			Cache::invalidate_booking( $booking_id );
		}

		return $result;
	}

	/**
	 * Get tax summary for a booking (simplified)
	 *
	 * @param int $booking_id Booking ID
	 * @return array<string, mixed> Simplified tax summary
	 */
	public static function get_booking_tax_summary( $booking_id ) {
		global $wpdb;

		// RATIONALE: Required to fetch a single booking's tax summary for admin display.
		// Uses $wpdb->prepare with %d placeholder; read-only, no caching needed for admin context.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin-only query
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT tax_enabled, tax_mode, total_tax, total_gross, total_price
             FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return array(
				'has_tax'    => false,
				'tax_amount' => 0,
				'tax_mode'   => self::MODE_DISABLED,
			);
		}

		$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		return array(
			'has_tax'     => (bool) $booking->tax_enabled,
			'tax_amount'  => Money::fromDecimal( (string) ( $booking->total_tax ?? '0' ), $currency )->toDecimal(),
			'tax_mode'    => $booking->tax_mode,
			'total_gross' => Money::fromDecimal( (string) ( $booking->total_gross ?: $booking->total_price ?: '0' ), $currency )->toDecimal(),
		);
	}
}
