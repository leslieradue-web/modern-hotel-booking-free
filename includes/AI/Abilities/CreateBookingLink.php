<?php

/**
 * Ability: Create Booking Link
 *
 * Generates a pre-filled booking URL with complete price summary,
 * payment methods, deposit info, and contact details. The guest
 * clicks the link to complete their reservation on the standard form.
 *
 * Available in both Free and Pro tiers (no BUILD_PRO markers).
 * Does NOT insert into the database or send any email.
 *
 * @package MHBO\AI\Abilities
 * @since   2.6.0
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\AI\KnowledgeBase;
use MHBO\Business\Info;
use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Tax;

use function __;
use function _n;
use function absint;
use function add_query_arg;
use function esc_html;
use function esc_url;
use function esc_url_raw;
use function get_option;
use function number_format;
use function preg_replace;
use function round;
use function sanitize_email;
use function sanitize_text_field;
use function sprintf;
use function wp_create_nonce;
use function gmdate;
use function strtotime;
use function class_exists;
use function implode;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class CreateBookingLink {

	/**
	 * Return the WP Ability / MCP tool definition.
	 *
	 * @return array<mixed>
	 */
	public static function get_definition(): array {
		return array(
			'label'               => \__( 'Create Booking Link', 'modern-hotel-booking' ),
			'description'         => \__( 'Generate a pre-filled booking URL with complete pricing summary, deposit info, and guest details for reserving a room.', 'modern-hotel-booking' ),
			'category'            => 'booking-management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'room_id'          => array(
						'type'        => 'integer',
						'description' => 'Room ID from the availability check.',
					),
					'type_id'          => array(
						'type'        => 'integer',
						'description' => 'Room type ID from the availability check.',
					),
					'check_in'         => array(
						'type'        => 'string',
						'format'      => 'date',
						'description' => 'Check-in date (YYYY-MM-DD).',
					),
					'check_out'        => array(
						'type'        => 'string',
						'format'      => 'date',
						'description' => 'Check-out date (YYYY-MM-DD).',
					),
					'adults'           => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 2,
						'description' => 'Number of adults.',
					),
					'children'         => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => 'Number of children.',
					),
					'guest_name'       => array(
						'type'        => 'string',
						'description' => 'Full name of the primary guest.',
					),
					'guest_email'      => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => 'Guest email address.',
					),
					'guest_phone'      => array(
						'type'        => 'string',
						'description' => 'Guest phone number.',
					),
					'multi_room_index' => array(
						'type'        => 'integer',
						'description' => 'Sequential index (1-based) of this room in a multi-room group.',
					),
					'multi_room_total' => array(
						'type'        => 'integer',
						'description' => 'Total number of rooms required in the multi-room group.',
					),
					'promo_code'       => array(
						'type'        => 'string',
						'description' => 'Optional promotional discount code to apply to the booking link.',
					),
				),
				'required'   => array( 'room_id', 'check_in', 'check_out', 'adults' ),
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
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		);
	}

	/**
	 * Execute the tool and return booking link with price summary.
	 *
	 * @param array<mixed> $args
	 * @return array<mixed>
	 */
	public static function execute( array $args ): array {
		$room_id = \absint( $args['room_id'] ?? 0 );
		$type_id = \absint( $args['type_id'] ?? 0 );
		// Strip any non-date characters (AI sometimes appends punctuation like commas).
		$check_in         = \preg_replace( '/[^0-9\-]/', '', \sanitize_text_field( (string) ( $args['check_in'] ?? '' ) ) );
		$check_out        = \preg_replace( '/[^0-9\-]/', '', \sanitize_text_field( (string) ( $args['check_out'] ?? '' ) ) );
		$adults           = \absint( $args['adults'] ?? 2 );
		$children         = \absint( $args['children'] ?? 0 );
		$guest_name       = \sanitize_text_field( (string) ( $args['guest_name'] ?? '' ) );
		$guest_email_raw  = (string) ( $args['guest_email'] ?? '' );
		$guest_email      = \sanitize_email( $guest_email_raw );
		$guest_phone      = \sanitize_text_field( (string) ( $args['guest_phone'] ?? '' ) );
		$promo_code       = \sanitize_text_field( (string) ( $args['promo_code'] ?? $args['coupon_code'] ?? '' ) );
		$multi_room_idx   = \absint( $args['multi_room_index'] ?? 0 );
		$multi_room_total = \absint( $args['multi_room_total'] ?? 0 );

		// If the caller supplied an email but sanitize_email stripped it entirely, it is invalid.
		if ( $guest_email_raw !== '' && $guest_email === '' ) {
			return array(
				'error'            => \__( 'The email address provided is not valid. Please ask the guest to provide a valid email address (e.g. name@example.com).', 'modern-hotel-booking' ),
				'error_code'       => 'invalid_email',
				'internal_message' => 'Guest supplied "' . \esc_html( $guest_email_raw ) . '" which is not a valid email format. Ask them to correct it before generating the booking link.',
			);
		}

		// ── Validate dates ──────────────────────────────────────────
		if ( ! $check_in || ! $check_out ) {
			return array( 'error' => \__( 'Check-in and check-out dates are required.', 'modern-hotel-booking' ) );
		}

		$ci_ts = \strtotime( $check_in );
		$co_ts = \strtotime( $check_out );

		if ( ! $ci_ts || ! $co_ts || $co_ts <= $ci_ts ) {
			return array( 'error' => \__( 'Invalid date range. Check-out must be after check-in.', 'modern-hotel-booking' ) );
		}

		$today_ts = \strtotime( \gmdate( 'Y-m-d' ) );
		if ( $ci_ts < $today_ts ) {
			return array( 'error' => \__( 'Check-in date cannot be in the past.', 'modern-hotel-booking' ) );
		}

		if ( $room_id <= 0 ) {
			return array( 'error' => \__( 'A valid room ID is required.', 'modern-hotel-booking' ) );
		}

		$nights = (int) ( ( $co_ts - $ci_ts ) / \DAY_IN_SECONDS );

// ── Validate room exists ────────────────────────────────────
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$room = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.id AS room_id, r.room_number, r.type_id, t.name AS type_name, t.base_price, t.max_adults, t.max_children
             FROM {$wpdb->prefix}mhbo_rooms r
             JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
             WHERE r.id = %d",
				$room_id
			) 
		);

		if ( ! $room ) {
			return array( 'error' => \__( 'Room not found.', 'modern-hotel-booking' ) );
		}

		// 2026 BP: Mandatory live availability validation to prevent broken links.
		$available = Pricing::is_room_available( $room_id, $check_in, $check_out );
		if ( true !== $available ) {
			$resolved_type_id = $type_id > 0 ? $type_id : (int) $room->type_id;
			$alt_room_id      = Pricing::find_available_room( $resolved_type_id, $check_in, $check_out, $adults + $children );
			if ( false !== $alt_room_id && $alt_room_id > 0 ) {
				$room_id = (int) $alt_room_id;
			} else {
				return array(
					'error'            => \__( 'Room not Available', 'modern-hotel-booking' ),
					'error_code'       => 'room_occupied',
					'internal_message' => 'The selected room is no longer available for these dates. Please suggest an alternative room.',
				);
			}
		}

		// ── Calculate pricing (read-only, no DB insert) ─────────────
		$currency = Pricing::get_currency_code();
		$calc     = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, $adults, array(), $children, array() );

		if ( ! isset( $calc['total'] ) || ! ( $calc['total'] instanceof Money ) ) {
			return array( 'error' => \__( 'Unable to calculate pricing for this room and dates.', 'modern-hotel-booking' ) );
		}

		$total_money      = $calc['total'];
		$discount_money   = Money::fromCents( 0, $currency );
		$resolved_type_id = $type_id > 0 ? $type_id : (int) $room->type_id;

// ── Generate pre-filled booking URL ─────────────────────────
		$base_url  = KnowledgeBase::get_booking_url();
		$nonce     = \wp_create_nonce( 'mhbo_auto_action' );
		$url_args  = array(
			'mhbo_auto_book' => 1,
			'mhbo_nonce'     => $nonce,
			'check_in'       => $check_in,
			'check_out'      => $check_out,
			'guests'         => $adults,
			'children'       => $children,
			'room_id'        => $room_id,
			'type_id'        => $resolved_type_id,
			'total_price'    => $total_money->toDecimal(),
			'customer_name'  => $guest_name,
			'customer_email' => $guest_email,
			'customer_phone' => $guest_phone,
		);

$booking_url = \add_query_arg( $url_args, $base_url );

		$night_rate_str = ( $nights > 0 && isset( $calc['room_total'] ) && $calc['room_total'] instanceof Money )
			? ( function_exists( 'bcdiv' ) ? bcdiv( $calc['room_total']->toDecimal(), (string) $nights, 2 ) : $calc['room_total']->divide( $nights )->toDecimal() )
			: $total_money->toDecimal();

		// ── Build response ──────────────────────────────────────────
		$result = array(
			'booking_url'     => \esc_url_raw( $booking_url ),
			'room_id'         => $room_id,
			'room_number'     => (string) ( $room->room_number ?? '' ),
			'room_name'       => I18n::decode( (string) $room->type_name ),
			'check_in'        => $check_in,
			'check_out'       => $check_out,
			'nights'          => $nights,
			'adults'          => $adults,
			'children'        => $children,
			'total_price'     => $total_money->toDecimal(),
			'price_formatted' => $total_money->format(),
			'price_per_night' => $night_rate_str,
			'currency'        => $currency,
			'guest_name'      => $guest_name,
			'guest_email'     => $guest_email,
			'guest_phone'     => $guest_phone,
			
		);

		// ── Tax summary ─────────────────────────────────────────────
		if ( Tax::is_enabled() ) {
			$tax_label = I18n::decode( (string) \get_option( 'mhbo_tax_label', 'VAT' ) );
			$tax_mode  = (string) \get_option( 'mhbo_tax_mode', 'disabled' );
			$tax_rate  = (float) \get_option( 'mhbo_tax_rate_accommodation', 0 );
			$display   = 'vat' === $tax_mode ? 'inclusive' : 'exclusive';

			$result['tax_summary'] = array(
				'label' => $tax_label,
				'rate'  => $tax_rate,
				'mode'  => $display,
				'note'  => \sprintf( '%s %s%% (%s)', $tax_label, $tax_rate, $display ),
			);
		}

		// ── Deposit info ────────────────────────────────────────────
		if ( \get_option( 'mhbo_deposits_enabled', 0 ) ) {
			$first_night_money = Pricing::resolve_first_night_money( $room_id, $check_in, $adults, null, array(), $children, array(), $total_money->getCurrency() );
			$deposit_data      = Pricing::calculate_deposit_money( $total_money, $first_night_money );
			if ( $deposit_data && $deposit_data['deposit_money']->lessThan( $total_money ) ) {
				$dep_val = (float) \get_option( 'mhbo_deposit_value', 20 );
				$result['deposit'] = array(
					'required'       => true,
					'amount'         => $deposit_data['deposit_money']->toDecimal(),
					'balance_due'    => $deposit_data['remaining_money']->toDecimal(),
					'type'           => $deposit_data['deposit_type'],
					'non_refundable' => $deposit_data['is_non_refundable'],
					'label'          => self::format_deposit_label( $deposit_data['deposit_type'], $dep_val, $currency, $deposit_data['is_non_refundable'] ),
				);
			}
		}

		// ── Payment methods summary (Aligned for UI Card) ─────────────────
		$result['payment_methods'] = \implode( ', ', self::get_payment_summary() );

		// ── Banking details (if enabled) ────────────────────────────
		$banking = Info::get_banking();
		if ( isset( $banking['enabled'] ) && $banking['enabled'] && isset( $banking['bank_name'] ) && '' !== $banking['bank_name'] ) {
			$result['banking'] = array(
				'bank_name'        => $banking['bank_name'],
				'account_name'     => $banking['account_name'],
				'iban'             => $banking['iban'],
				'swift_bic'        => $banking['swift_bic'],
				'reference_prefix' => $banking['reference_prefix'],
				'instructions'     => $banking['instructions'],
			);
		}

		// ── Revolut (if enabled) ────────────────────────────────────
		$revolut = Info::get_revolut();
		if ( isset( $revolut['enabled'] ) && $revolut['enabled'] && isset( $revolut['revolut_tag'] ) && '' !== $revolut['revolut_tag'] ) {
			$result['revolut'] = array(
				'tag'          => $revolut['revolut_tag'],
				'payment_link' => $revolut['revolut_link'] ? \esc_url_raw( $revolut['revolut_link'] ) : '',
				'qr_code_url'  => $revolut['qr_code_url'] ? \esc_url_raw( $revolut['qr_code_url'] ) : '',
			);
		}

		// ── WhatsApp (if enabled) ───────────────────────────────────
		$wa = Info::get_whatsapp();
		if ( isset( $wa['enabled'] ) && $wa['enabled'] && isset( $wa['phone_number'] ) && '' !== $wa['phone_number'] ) {
			$phone_clean        = \preg_replace( '/[^0-9+]/', '', (string) $wa['phone_number'] );
			$result['whatsapp'] = array(
				'phone'    => $wa['phone_number'],
				'chat_url' => "https://wa.me/{$phone_clean}",
			);
		}

		// ── Extras teaser ───────────────────────────────────────────
		$extras = self::get_extras_summary();
		if ( array() !== $extras ) {
			$result['available_extras'] = $extras;
		}

		// ── Hotel contact fallback ──────────────────────────────────
		$company               = Info::get_company();
		$result['hotel_phone'] = $company['telephone'];
		$result['hotel_email'] = $company['email'];

		$result['message'] = \sprintf(
			/* translators: %1$s room name, %2$s formatted price */
			\__( '[SYSTEM] Booking card displayed to guest for %1$s at %2$s. Reply with 1–2 sentences ONLY: confirm room name, dates, and total. Do NOT mention a URL, do NOT say "click the link" or "use the link below" — the interactive card is already visible and contains the button.', 'modern-hotel-booking' ),
			I18n::decode( (string) $room->type_name ),
			$total_money->format()
		);

		// 2026 BP: Sequential Multi-Room Support
		if ( $multi_room_idx > 0 && $multi_room_total >= $multi_room_idx ) {
			/* translators: 1: room index, 2: total rooms */
			$result['multi_room_label']     = \sprintf( \__( 'Room %1$d of %2$d', 'modern-hotel-booking' ), $multi_room_idx, $multi_room_total );
			$result['multi_room_remaining'] = $multi_room_total - $multi_room_idx;
			
			if ( $result['multi_room_remaining'] > 0 ) {
				$result['message'] = \sprintf( 
					/* translators: 1: room index, 2: remaining count, 3: "room" or "rooms" label, 4: total rooms */
					\__( '[SYSTEM] Booking card generated for room %1$d. INSTRUCT THE GUEST: "This is booking %1$d of %4$d. Once you complete this reservation, PLEASE COME BACK TO THIS CHAT and say \'next room\' so I can prepare the remaining %2$d %3$s for you."', 'modern-hotel-booking' ),
					$multi_room_idx,
					$result['multi_room_remaining'],
					\_n( 'room', 'rooms', (int) $result['multi_room_remaining'], 'modern-hotel-booking' ),
					$multi_room_total
				);
			} else {
				$result['message'] = \sprintf( 
					/* translators: 1: room index, 2: total rooms */
					\__( '[SYSTEM] Final booking card generated (%1$d of %2$d). INSTRUCT THE GUEST: "All rooms are now ready! Please ensure you have completed every reservation using the cards above."', 'modern-hotel-booking' ),
					$multi_room_idx,
					$multi_room_total
				);
			}
		}

		return $result;
	}

	/**
	 * Build a short summary of accepted payment methods.
	 *
	 * @return string[]
	 */
	public static function get_payment_summary(): array {
		$methods = array();

		if ( (int) \get_option( 'mhbo_gateway_stripe_enabled', 0 ) ) {
			$methods[] = 'Credit/Debit Card (Stripe)';
		}
		if ( (int) \get_option( 'mhbo_gateway_paypal_enabled', 0 ) ) {
			$methods[] = 'PayPal';
		}
		if ( (int) \get_option( 'mhbo_gateway_onsite_enabled', 0 ) ) {
			$methods[] = 'Pay on Arrival';
		}

		$banking = Info::get_banking();
		if ( isset( $banking['enabled'] ) && $banking['enabled'] && isset( $banking['bank_name'] ) && '' !== $banking['bank_name'] ) {
			$methods[] = 'Bank Transfer';
		}

		$revolut = Info::get_revolut();
		if ( isset( $revolut['enabled'] ) && $revolut['enabled'] && isset( $revolut['revolut_tag'] ) && '' !== $revolut['revolut_tag'] ) {
			$methods[] = 'Revolut';
		}

		return $methods;
	}

	/**
	 * Get a brief list of available extras for upsell.
	 *
	 * @return array<array{name: string, price: float, pricing_type: string}>
	 */
	private static function get_extras_summary(): array {
		$raw = \get_option( 'mhbo_pro_extras', array() );
		if ( ! \is_array( $raw ) || array() === $raw ) {
			return array();
		}

		$currency = Pricing::get_currency_code();
		$extras   = array();

		foreach ( \array_slice( $raw, 0, 5 ) as $extra ) {
			if ( ! isset( $extra['name'] ) || '' === $extra['name'] ) {
				continue;
			}
			$pricing_type = (string) ( $extra['pricing_type'] ?? $extra['type'] ?? 'fixed' );
			$type_label   = match ( $pricing_type ) {
				'per_night'            => 'per night',
				'per_person'           => 'per person',
				'per_adult'            => 'per adult',
				'per_child'            => 'per child',
				'per_person_per_night' => 'per person per night',
				default                => 'fixed',
			};

			$extras[] = array(
				'name'         => \sanitize_text_field( (string) I18n::decode( (string) $extra['name'] ) ),
				'price'        => (float) ( $extra['price'] ?? 0 ),
				'pricing_type' => $type_label,
				'currency'     => $currency,
			);
		}

		return $extras;
	}

	/**
	 * Format deposit label for display.
	 *
	 * @param string $type     Deposit type.
	 * @param float  $value    Deposit value.
	 * @param string $currency Currency code.
	 * @param bool   $non_r    Non-refundable flag.
	 * @return string
	 */
	private static function format_deposit_label( string $type, float $value, string $currency, bool $non_r ): string {
		$refund = $non_r ? ' (non-refundable)' : '';
		return match ( $type ) {
			'first_night' => \__( 'First night\'s rate', 'modern-hotel-booking' ) . $refund,
			'fixed'       => \sprintf( '%s %s', \number_format( $value, 2 ), $currency ) . $refund,
			default       => \sprintf( '%d%% of total', (int) $value ) . $refund,
		};
	}

	/**
	 * Register as a WordPress Ability (WP 7.0+).
	 */
	public static function register(): void {
		if ( ! \function_exists( 'wp_register_ability' ) ) {
			return;
		}
		call_user_func( 'wp_register_ability', 'mhbo/create-booking-link', self::get_definition() );
	}
}
