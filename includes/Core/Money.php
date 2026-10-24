<?php declare(strict_types=1);
/**
 * Money Service — Precision-Safe Financial Abstraction
 * 
 * Implements the Minor Unit Pattern (integer cents) using BCMath.
 * This class is the mandatory "Gate" for all financial logic in the 2026 Roadmap.
 * 
 * @package MHBO\Core
 * @since   2.1.0
 */

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Money implements \JsonSerializable {

	/**
	 * Internal amount in minor units (e.g. cents for USD, units for JPY).
	 * Stored as string to support BCMath and arbitrarily large values.
	 * 
	 * @var numeric-string
	 */
	private string $amount_in_cents;

	/**
	 * ISO-4217 Currency Precisions (Decimal Places).
	 * Sources: Stripe/PayPal Integration Standards.
	 * 
	 * @var array<string, int>
	 */
	private static array $CURRENCY_PRECISION = array(
		// Standard 2-decimal currencies (Majority of global currencies)
		'USD' => 2,
		'EUR' => 2,
		'GBP' => 2,
		'CAD' => 2,
		'AUD' => 2,
		'NZD' => 2,
		'CHF' => 2,
		'HKD' => 2,
		'SGD' => 2,
		'SEK' => 2,
		'DKK' => 2,
		'NOK' => 2,
		'ILS' => 2,
		'MXN' => 2,
		'BRL' => 2,
		'PLN' => 2,
		'CZK' => 2,
		'RON' => 2,
		'RUB' => 2,
		'TRY' => 2,
		'ZAR' => 2,
		'THB' => 2,
		'INR' => 2,
		'AED' => 2,
		'SAR' => 2,
		'QAR' => 2,
		'EGP' => 2,
		'MAD' => 2,
		'NGN' => 2,
		'KES' => 2,
		'MUR' => 2,
		'SCR' => 2,
		'MYR' => 2,
		'PHP' => 2,
 
		// Zero-decimal currencies (No cents/minor units)
		'JPY' => 0,
		'HUF' => 0,
		'TWD' => 0,
		'KRW' => 0,
		'CLP' => 0,
		'PYG' => 0,
		'VND' => 0,
		'IDR' => 0,
		'ISK' => 0,
		'UGX' => 0,
		'RWF' => 0,
 
		// Three-decimal currencies
		'KWD' => 3,
		'BHD' => 3,
		'OMR' => 3,
		'JOD' => 3,
		'LYD' => 3,
		'TND' => 3,
	);

	/**
	 * Currency code (e.g. 'USD').
	 * 
	 * @var string
	 */
	private string $currency;

	/**
	 * Constructor.
	 * 
	 * @param numeric-string $amount_in_cents Amount in minor units.
	 * @param string         $currency        Currency code.
	 */
	private function __construct( string $amount_in_cents, string $currency ) {
		$this->amount_in_cents = $amount_in_cents;
		$this->currency        = $currency;
	}

	/**
	 * Primary Factory: Create from integer/string cents.
	 * 
	 * @param int|string  $cents    The amount in minor units.
	 * @param string|null $currency Optional currency code.
	 * @return self
	 */
	public static function fromCents( int|string $cents, ?string $currency = null ): self {
		$cents_str = (string) $cents;
		if ( ! is_numeric( $cents_str ) ) {
			$cents_str = '0';
		}
		/** @var numeric-string $cents_str */
		$currency = $currency ?? Pricing::get_currency_code();

		return new self( $cents_str, $currency );
	}

	/**
	 * Primary Factory: Create from decimal string (e.g. "19.99").
	 * Uses BCMath to safely scale to minor units.
	 * 
	 * @param string      $decimal  The decimal string amount.
	 * @param string|null $currency Optional currency code.
	 * @return self
	 */
	public static function fromDecimal( string $decimal, ?string $currency = null ): self {
		$currency  = $currency ?? Pricing::get_currency_code();
		$precision = self::get_precision( $currency );
		
		// Locale-safe normalization: Ensure '.' is the decimal separator
		$decimal = str_replace( ',', '.', $decimal );
		if ( ! is_numeric( $decimal ) ) {
			$decimal = '0';
		}
		/** @var numeric-string $decimal */
		
		if ( ! function_exists( 'bcpow' ) || ! function_exists( 'bcmul' ) ) {
			// String-based fallback for environments missing bcmath (avoids floating point inaccuracy)
			$is_negative   = str_starts_with( $decimal, '-' );
			$clean_decimal = ltrim( $decimal, '-' );
			$parts         = explode( '.', $clean_decimal );
			$whole_str     = $parts[0] !== '' ? $parts[0] : '0';
			$frac_str      = $parts[1] ?? '';
			
			if ( $precision === 0 ) {
				$cents_int = (int) $whole_str;
				if ( isset( $frac_str[0] ) && (int) $frac_str[0] >= 5 ) {
					++$cents_int;
				}
			} elseif ( strlen( $frac_str ) > $precision ) {
					$keep       = substr( $frac_str, 0, $precision );
					$next       = (int) $frac_str[ $precision ];
					$cents_frac = (int) $keep;
				if ( $next >= 5 ) {
					++$cents_frac;
				}
					$cents_int = ( (int) $whole_str * ( 10 ** $precision ) ) + $cents_frac;
			} else {
				$padded_frac = str_pad( $frac_str, $precision, '0', STR_PAD_RIGHT );
				$cents_int   = ( (int) $whole_str * ( 10 ** $precision ) ) + (int) $padded_frac;
			}
			$cents = (string) $cents_int;
			if ( $is_negative && '0' !== $cents ) {
				$cents = '-' . $cents;
			}
			return self::fromCents( $cents, $currency );
		}

		/** @var numeric-string $factor */
		$factor = bcpow( '10', (string) $precision );

		// Scale to minor units with half-up rounding for any sub-cent/sub-minor unit precision
		/** @var numeric-string $scaled */
		$scaled = bcmul( $decimal, $factor, 4 );
		if ( str_contains( $scaled, '.' ) ) {
			$is_negative = str_starts_with( $scaled, '-' );
			/** @var numeric-string $abs_scaled */
			$abs_scaled  = ltrim( $scaled, '-' );
			/** @var numeric-string $rounded */
			$rounded     = bcadd( $abs_scaled, '0.5', 0 );
			$cents       = ( $is_negative && '0' !== $rounded ) ? '-' . $rounded : $rounded;
		} else {
			$cents = $scaled;
		}
		return self::fromCents( $cents, $currency );
	}

	/**
	 * Legacy Factory: Create from float.
	 *
	 * WARNING: This method exists solely for backward compatibility with pre-2.1.0
	 * code that stores prices as PHP floats.
	 *
	 * @deprecated 2.1.0 Use fromDecimal() or fromCents() for all new modules.
	 *
	 * @param float       $amount   The float amount.
	 * @param string|null $currency Optional currency code.
	 * @return self
	 */
	public static function fromFloat( float $amount, ?string $currency = null ): self {
		$currency  = $currency ?? Pricing::get_currency_code();
		$precision = self::get_precision( $currency );
		$factor    = pow( 10, $precision );

		$cents = (string) (int) round( $amount * $factor );
		return self::fromCents( $cents, $currency );
	}

	/**
	 * Precision-safe addition.
	 * 
	 * @param Money $other
	 * @return self
	 */
	public function add( self $other ): self {
		$this->ensure_same_currency( $other );

		if ( ! function_exists( 'bcadd' ) ) {
			$res = (string) ( (int) $this->amount_in_cents + (int) $other->getAmount() );
			return new self( $res, $this->currency );
		}

		/** @var numeric-string $res */
		$res = bcadd( $this->amount_in_cents, $other->getAmount(), 0 );
		return new self( $res, $this->currency );
	}

	/**
	 * Precision-safe subtraction.
	 * 
	 * @param Money $other
	 * @return self
	 */
	public function subtract( self $other ): self {
		$this->ensure_same_currency( $other );

		if ( ! function_exists( 'bcsub' ) ) {
			$res = (string) ( (int) $this->amount_in_cents - (int) $other->getAmount() );
			return new self( $res, $this->currency );
		}

		/** @var numeric-string $res */
		$res = bcsub( $this->amount_in_cents, $other->getAmount(), 0 );
		return new self( $res, $this->currency );
	}

	/**
	 * Precision-safe multiplication.
	 * 
	 * @param string|int|float $multiplier
	 * @return self
	 */
	public function multiply( string|int|float $multiplier ): self {
		$multiplier_str = str_replace( ',', '.', (string) $multiplier );
		if ( ! is_numeric( $multiplier_str ) ) {
			$multiplier_str = '0';
		}
		/** @var numeric-string $multiplier_str */
		
		if ( ! function_exists( 'bcmul' ) ) {
			$res = (string) (int) round( ( (float) $this->amount_in_cents ) * ( (float) $multiplier_str ) );
			return new self( $res, $this->currency );
		}

		// Use scale 4 for intermediate precision, then round to 0
		/** @var numeric-string $res */
		$res = bcmul( $this->amount_in_cents, $multiplier_str, 4 );
		
		// Pure BCMath Rounding (Round Half Up)
		$is_negative = strpos( $res, '-' ) === 0;
		/** @var numeric-string $abs_res */
		$abs_res     = $is_negative ? substr( $res, 1 ) : $res;
		/** @var numeric-string $rounded */
		$rounded     = bcadd( $abs_res, '0.5', 0 );
		$final       = ( $is_negative && '0' !== $rounded ) ? '-' . $rounded : $rounded;
		/** @var numeric-string $final */
		return new self( $final, $this->currency );
	}

	/**
	 * Precision-safe division.
	 * 
	 * @param string|int|float $divisor
	 * @return self
	 */
	public function divide( string|int|float $divisor ): self {
		$divisor_str = str_replace( ',', '.', (string) $divisor );
		if ( ! is_numeric( $divisor_str ) || (float) $divisor_str === 0.0 ) {
			throw new \InvalidArgumentException( 'Division by zero or invalid divisor.' );
		}
		/** @var numeric-string $divisor_str */
		
		if ( ! function_exists( 'bcdiv' ) ) {
			$res = (string) (int) round( ( (float) $this->amount_in_cents ) / ( (float) $divisor_str ) );
			return new self( $res, $this->currency );
		}

		// Use scale 4 for intermediate precision, then round to 0
		/** @var numeric-string $res */
		$res = bcdiv( $this->amount_in_cents, $divisor_str, 4 );
		
		// Pure BCMath Rounding (Round Half Up)
		$is_negative = strpos( $res, '-' ) === 0;
		/** @var numeric-string $abs_res */
		$abs_res     = $is_negative ? substr( $res, 1 ) : $res;
		/** @var numeric-string $rounded */
		$rounded     = bcadd( $abs_res, '0.5', 0 );
		$final       = ( $is_negative && '0' !== $rounded ) ? '-' . $rounded : $rounded;
		/** @var numeric-string $final */
		return new self( $final, $this->currency );
	}

	/**
	 * Get the raw amount in minor units (cents).
	 * 
	 * @return numeric-string
	 */
	public function getAmount(): string {
		return $this->amount_in_cents;
	}

	/**
	 * Get the number of decimal places for the current currency.
	 *
	 * @return int Number of decimal places.
	 */
	public function getCurrencyDecimals(): int {
		return self::$CURRENCY_PRECISION[ $this->currency ] ?? 2;
	}

	/**
	 * Get the amount in decimal format (e.g. "19.99").
	 * Supports optional target decimal override with BCMath half-up rounding.
	 * 
	 * @param int|null $decimals Optional target decimal places override.
	 * @return string
	 */
	public function toDecimal( ?int $decimals = null ): string {
		$currency_precision = self::get_precision( $this->currency );
		$factor             = function_exists( 'bcpow' ) ? bcpow( '10', (string) $currency_precision ) : (string) pow( 10, $currency_precision );
		/** @var numeric-string $factor */
		
		if ( ! function_exists( 'bcdiv' ) ) {
			$val             = ( (float) $this->amount_in_cents ) / (float) $factor;
			$target_decimals = $decimals ?? $currency_precision;
			return number_format( $val, $target_decimals, '.', '' );
		}

		/** @var numeric-string $base_decimal */
		$base_decimal = bcdiv( $this->amount_in_cents, $factor, $currency_precision );
		
		if ( null === $decimals || $decimals === $currency_precision ) {
			return $base_decimal;
		}

		if ( $decimals > $currency_precision ) {
			$parts    = explode( '.', $base_decimal );
			$fraction = isset( $parts[1] ) ? $parts[1] : '';
			return $parts[0] . '.' . str_pad( $fraction, $decimals, '0', STR_PAD_RIGHT );
		}

		// $decimals < $currency_precision: half-up rounding using BCMath
		$is_negative = str_starts_with( $base_decimal, '-' );
		$abs_val     = ltrim( $base_decimal, '-' );
		/** @var numeric-string $abs */
		$abs         = is_numeric( $abs_val ) ? $abs_val : '0';
		$half_str    = '0.' . str_repeat( '0', $decimals ) . '5';
		$half        = is_numeric( $half_str ) ? $half_str : '0.5';
		if ( ! function_exists( 'bcadd' ) ) {
			$val     = ( (float) $abs ) + ( (float) $half );
			$rounded = number_format( $val, $decimals, '.', '' );
		} else {
			$rounded = bcadd( $abs, $half, $decimals );
		}
		return ( $is_negative && '0' !== $rounded ) ? '-' . $rounded : $rounded;
	}
	
	/**
	 * Magic method for string conversion (PHP 8.0 support).
	 * Returns the decimal representation of the money.
	 * 
	 * @return string
	 */
	public function __toString(): string {
		try {
			return $this->toDecimal();
		} catch ( \Throwable $e ) {
			return '0.00';
		}
	}

	/**
	 * Get current currency code.
	 * 
	 * @return string
	 */
	public function getCurrency(): string {
		return $this->currency;
	}

	/**
	 * Format the amount using site-wide I18n settings.
	 * 
	 * @param bool     $include_tax_note  Whether to append tax notes if applicable.
	 * @param int|null $decimals_override Optional override for decimal places.
	 * @return string
	 */
	public function format( bool $include_tax_note = false, ?int $decimals_override = null ): string {
		return Pricing::format_price( $this, $include_tax_note, $decimals_override );
	}

	/**
	 * Validate currency match.
	 * 
	 * @param Money $other
	 * @throws \Exception If currencies do not match.
	 */
	private function ensure_same_currency( self $other ): void {
		if ( $this->currency !== $other->getCurrency() ) {
			throw new \Exception(
				sprintf(
					'Currency mismatch: %s vs %s',
					esc_html( $this->currency ),
					esc_html( $other->getCurrency() )
				)
			);
		}
	}

	/**
	 * Check equality (amount AND currency).
	 *
	 * @param Money $other
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return $this->amount_in_cents === $other->getAmount()
			&& $this->currency === $other->getCurrency();
	}

	/**
	 * Check if this amount is exactly zero.
	 *
	 * @return bool
	 */
	public function isZero(): bool {
		if ( ! function_exists( 'bccomp' ) ) {
			return (int) $this->amount_in_cents === 0;
		}
		return 0 === bccomp( $this->amount_in_cents, '0', 0 );
	}

	/**
	 * Check if this amount is strictly positive.
	 *
	 * @return bool
	 */
	public function isPositive(): bool {
		if ( ! function_exists( 'bccomp' ) ) {
			return (int) $this->amount_in_cents > 0;
		}
		return bccomp( $this->amount_in_cents, '0', 0 ) > 0;
	}

	/**
	 * Check if this amount is strictly negative.
	 *
	 * @return bool
	 */
	public function isNegative(): bool {
		if ( ! function_exists( 'bccomp' ) ) {
			return (int) $this->amount_in_cents < 0;
		}
		return bccomp( $this->amount_in_cents, '0', 0 ) < 0;
	}

	/**
	 * Compare this amount with another.
	 * 
	 * Returns:
	 * - 0 if amounts are equal.
	 * - 1 if this amount is greater.
	 * - -1 if this amount is less.
	 *
	 * @param Money $other
	 * @return int
	 * @throws \Exception If currencies do not match.
	 */
	public function compare( self $other ): int {
		$this->ensure_same_currency( $other );

		if ( ! function_exists( 'bccomp' ) ) {
			$a = (int) $this->amount_in_cents;
			$b = (int) $other->getAmount();
			return $a <=> $b;
		}

		return bccomp( $this->amount_in_cents, $other->getAmount(), 0 );
	}

	/**
	 * Check if this amount is strictly greater than another.
	 *
	 * @param Money $other
	 * @return bool
	 */
	public function greaterThan( self $other ): bool {
		return $this->compare( $other ) > 0;
	}

	/**
	 * Check if this amount is greater than or equal to another.
	 *
	 * @param Money $other
	 * @return bool
	 */
	public function greaterThanOrEqual( self $other ): bool {
		return $this->compare( $other ) >= 0;
	}

	/**
	 * Alias for greaterThanOrEqual.
	 *
	 * @param Money $other
	 * @return bool
	 */
	public function isGreaterThanOrEqual( self $other ): bool {
		return $this->greaterThanOrEqual( $other );
	}

	/**
	 * Check if this amount is strictly less than another.
	 *
	 * @param Money $other
	 * @return bool
	 */
	public function lessThan( self $other ): bool {
		return $this->compare( $other ) < 0;
	}

	/**
	 * Check if this amount is less than or equal to another.
	 *
	 * @param Money $other
	 * @return bool
	 */
	public function lessThanOrEqual( self $other ): bool {
		return $this->compare( $other ) <= 0;
	}

	/**
	 * Returns a new Money object with the absolute value of the amount.
	 *
	 * @return self
	 */
	public function abs(): self {
		if ( ! $this->isNegative() ) {
			return $this;
		}
		/** @var numeric-string $abs_cents */
		$abs_cents = ltrim( $this->amount_in_cents, '-' );
		return new self( $abs_cents, $this->currency );
	}

	/**
	 * Returns a new Money object with the negated amount.
	 *
	 * @return self
	 */
	public function negate(): self {
		if ( $this->isZero() ) {
			return $this;
		}
		/** @var numeric-string $cents */
		$cents = $this->isNegative() ? ltrim( $this->amount_in_cents, '-' ) : '-' . $this->amount_in_cents;
		return new self( $cents, $this->currency );
	}

	/**
	 * Get the decimal precision for a currency.
	 * 
	 * @param string $currency ISO-4217 code.
	 * @return int
	 */
	public static function get_precision( string $currency ): int {
		$currency = strtoupper( $currency );
		return self::$CURRENCY_PRECISION[ $currency ] ?? 2;
	}

	/**
	 * Specify data which should be serialized to JSON.
	 * Ensure Money objects are stored as strings for maximum precision and safe decoding.
	 *
	 * @return mixed data which can be serialized by json_encode, which is a value of any type other than a resource.
	 */
	public function jsonSerialize(): mixed {
		return $this->toDecimal();
	}
}
