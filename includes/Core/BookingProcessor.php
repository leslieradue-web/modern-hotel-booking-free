<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Tax;
use MHBO\Core\License;
use MHBO\Core\Cache;
use MHBO\Core\HotelTime;

/**
 * BookingProcessor Class
 * 
 * Centralized service for processing and finalising bookings.
 * This class handles validation, availability checks, pricing recalculation,
 * payment verification, and database insertion.
 * 
 * @package MHBO\Core
 * @since 2.3.8
 */
class BookingProcessor {

	/**
	 * Process a booking submission.
	 * 
	 * @param array                $data {
	 *     @type int    $room_id         Room ID.
	 *     @type string $check_in        Check-in date (Y-m-d).
	 *     @type string $check_out       Check-out date (Y-m-d).
	 *     @type string $customer_name   Customer name.
	 *     @type string $customer_email  Customer email.
	 *     @type string $customer_phone  Customer phone.
	 *     @type int    $guests          Number of adults.
	 *     @type int    $children        Number of children.
	 *     @type array  $child_ages      Ages of children (Starter/Pro).
	 *     @type array  $extras          Map of extra ID => quantity (Pro).
	 *     @type string $payment_method  'arrival', 'stripe', or 'paypal'.
	 *     @type string $payment_type    'full' or 'deposit' (Pro).
	 *     @type string $stripe_pi       Stripe PaymentIntent ID (Starter/Pro).
	 *     @type string $paypal_order_id PayPal Order ID (Starter/Pro).
	 *     @type array  $custom_fields   Map of custom field ID => value.
	 *     @type string $admin_notes     Optional admin notes.
	 *     @type int    $update_id       Optional booking ID to update (resumption).
	 *     @type string $page_url        The URL of the booking page for redirects.
	 *     @type string $language        Booking language.
	 *     @type string $source          Booking source (public, admin, ai_concierge, ical, airbnb, etc.).
	 *     @type string $external_id     Platform UID for iCal/OTA sync.
	 *     @type string $parent_token    Parent token for multi-room linking.
	 *     @type bool   $bypass_past     Whether to bypass past-date validation (Admin/iCal).
	 * }
	 * }
	 * @param array<string, mixed> $data
	 * @param bool                 $bypass_lock When true the caller already holds all advisory locks
	 *                                          for this room and is responsible for releasing them.
	 *                                          MUST only be set via this parameter — never via $data —
	 *                                          to prevent HTTP clients from bypassing concurrency guards.
	 * @return array<string, mixed>|\WP_Error Result array on success, WP_Error on failure.
	 */
	public static function process( array $data, bool $bypass_lock = false ): array|\WP_Error {
		global $wpdb;

		// 1. Sanitize Inputs
		$room_id        = absint( $data['room_id'] ?? 0 );
		$type_id        = absint( $data['type_id'] ?? 0 );
		$check_in       = sanitize_text_field( $data['check_in'] ?? '' );
		$check_out      = sanitize_text_field( $data['check_out'] ?? '' );
		$customer_name  = sanitize_text_field( $data['customer_name'] ?? '' );
		$customer_email = sanitize_email( $data['customer_email'] ?? '' );
		$customer_phone = sanitize_text_field( $data['customer_phone'] ?? '' );
		$guests         = max( 1, absint( $data['guests'] ?? 1 ) );
		$payment_method = sanitize_key( $data['payment_method'] ?? 'arrival' ) ?: 'arrival';
		$language       = sanitize_key( $data['language'] ?? I18n::get_current_language() );
		$update_id      = absint( $data['update_id'] ?? 0 );
		$source         = sanitize_text_field( $data['source'] ?? 'public' );
		$external_id    = sanitize_text_field( $data['external_id'] ?? '' );
		$parent_token   = sanitize_text_field( $data['parent_token'] ?? '' );
		$bypass_past    = (bool) ( $data['bypass_past'] ?? false );
		$session_token  = sanitize_text_field( $data['session_token'] ?? $data['mhbo_session_token'] ?? '' );

if ( 0 === $update_id && '' !== $session_token ) {
			$session_meta = get_transient( 'mhbo_booking_session_' . $session_token ) ?: get_transient( 'mhbo_session_' . $session_token );
			if ( is_array( $session_meta ) && ! empty( $session_meta['booking_id'] ) ) {
				$update_id = (int) $session_meta['booking_id'];
			}
		}

		if ( 0 === $update_id ) {
			$raw_sess = Privacy::get_cookie_session_id();
			if ( ! empty( $raw_sess ) ) {
				$session_pending = (int) get_transient( 'mhbo_session_pending_booking_' . $raw_sess );
				if ( $session_pending > 0 ) {
					$update_id = $session_pending;
				}
			}
		}

		// 2026 BP Guard: If update_id is set, inspect existing record state.
		// Only provisional checkout drafts ('pending_payment') can be updated.
		// Completed, confirmed, or pending arrival reservations are strictly immutable.
		if ( $update_id > 0 ) {
			// Direct DB query required: inspecting existing booking record state. Table name is internal and interpolated.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing_booking = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, booking_token, status, payment_status FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
					$update_id
				)
			);
			if ( $existing_booking ) {
				if ( 'pending_payment' !== $existing_booking->status ) {
					// Existing booking is already completed or confirmed — must not overwrite!
					$update_id = 0;
					$raw_sess  = Privacy::get_cookie_session_id();
					if ( ! empty( $raw_sess ) ) {
						delete_transient( 'mhbo_session_pending_booking_' . $raw_sess );
					}
				}
			} else {
				$update_id = 0;
			}
		}

		// Auto-resolve physical room_id from type_id if room_id is 0 or if the requested room is already booked
		if ( $type_id > 0 ) {
			$is_current_room_avail = ( $room_id > 0 ) ? Pricing::is_room_available( $room_id, $check_in, $check_out, $update_id ) : false;
			if ( true !== $is_current_room_avail ) {
				$resolved_room = Pricing::find_available_room( $type_id, $check_in, $check_out, $guests );
				if ( false !== $resolved_room && $resolved_room > 0 ) {
					$room_id = (int) $resolved_room;
				}
			}
		}

		// 2026 BP: Early privilege determination for internal/automated sources.
		// Privileged sources bypass non-essential validation gates (phone,
		// stay restrictions, GDPR, custom fields) that don't apply to
		// automated sync imports or back-office staff workstations. Uses a filter so
		// ICalManager (and any future OTA integration) can register platforms dynamically.
		$privileged_sources = apply_filters( 'mhbo_privileged_booking_sources', array( 'admin', 'manual', 'ical', 'tablet' ) );
		$is_staff_user      = function_exists( 'is_user_logged_in' ) && is_user_logged_in() && (
			current_user_can( 'manage_options' ) ||
			current_user_can( Capabilities::MANAGE_LHBO ) ||
			current_user_can( Capabilities::VIEW_BOOKINGS ) ||
			current_user_can( Capabilities::MANAGE_SETTINGS )
		);
		$is_privileged      = in_array( strtolower( $source ), $privileged_sources, true ) || $is_staff_user;

		// Resolve room_id from type_id if it's 0 (category booking)
		if ( 0 === $room_id && 0 !== $type_id && '' !== $check_in && '' !== $check_out ) {
			$resolved_room = Pricing::find_available_room( $type_id, $check_in, $check_out, $guests );
			if ( $resolved_room ) {
				$room_id = $resolved_room;
			}
		}

		// 2026 BP: Privileged sources (iCal/OTA imports) may lack phone/email —
		// only enforce those fields for public-facing form submissions.
		$missing_public_fields = ( '' === $customer_email || '' === $customer_phone );
		if ( 0 === $room_id || '' === $check_in || '' === $check_out || '' === $customer_name || ( ! $is_privileged && $missing_public_fields ) ) {
			return new \WP_Error( 'mhbo_missing_fields', I18n::get_label( 'label_fill_all_fields' ) );
		}

		// 2. Input Validation
		if ( mb_strlen( $customer_name ) > 100 ) {
			return new \WP_Error( 'mhbo_name_too_long', I18n::get_label( 'label_name_too_long' ) );
		}
		if ( mb_strlen( $customer_phone ) > 30 ) {
			return new \WP_Error( 'mhbo_phone_too_long', I18n::get_label( 'label_phone_too_long' ) );
		}

if ( ! $bypass_past ) {
			$today = wp_date( 'Y-m-d' );
			if ( $check_in < $today ) {
				return new \WP_Error( 'mhbo_past_date', I18n::get_label( 'label_check_in_past' ) );
			}
		}
		if ( ! $bypass_past && ! $is_privileged && ! HotelTime::is_within_advance_booking_window( $check_in ) ) {
			$max_window_human = HotelTime::format_advance_window_human( HotelTime::get_max_advance_booking_days() );
			return new \WP_Error(
				'mhbo_advance_limit_exceeded',
				sprintf( I18n::get_label( 'label_advance_limit_exceeded' ), $max_window_human ),
				array( 'status' => 400 )
			);
		}
		if ( $check_out <= $check_in ) {
			return new \WP_Error( 'mhbo_invalid_range', I18n::get_label( 'label_check_out_after' ) );
		}

		// 3. Pro Features Check
		$is_pro_active = false;

$children     = 0;
		$child_ages   = array();
		$extras_input = array();
		$payment_type = 'full';

// Room capacity validation (Adults in Core, Children in Starter/Pro)
		$room_obj = Pricing::get_room_pricing_data( $room_id );
		if ( $room_obj ) {
			
			if ( $guests > (int) $room_obj->max_adults ) {
				return new \WP_Error( 'mhbo_max_adults', sprintf( I18n::get_label( 'label_max_adults_error' ), $room_obj->max_adults ) );
			}
		}

		// Stay Restrictions (Global Min/Max Stay in Core; Date-specific rules in Pro)
		// 2026 BP: Privileged sources bypass stay restrictions — external
		// platforms manage their own minimum/maximum stay rules.
		if ( ! $is_privileged ) {
			$dt_in  = new \DateTime( $check_in );
			$dt_out = new \DateTime( $check_out );
			$nights = (int) $dt_in->diff( $dt_out )->format( '%a' );

			$min_stay = Pricing::get_effective_min_stay( $room_id, $check_in );
			$max_stay = null;
			
			if ( null === $max_stay ) {
				$global_max = (int) get_option( 'mhbo_global_max_stay', 0 );
				if ( $global_max > 0 ) {
					$max_stay = $global_max;
				}
			}

			if ( null !== $min_stay && $nights < $min_stay ) {
				return new \WP_Error( 'mhbo_min_stay', sprintf( I18n::get_label( 'api_err_min_stay' ), $min_stay ) );
			}
			if ( null !== $max_stay && $nights > $max_stay ) {
				return new \WP_Error( 'mhbo_max_stay', sprintf( I18n::get_label( 'api_err_max_stay' ), $max_stay ) );
			}
		}

		// 4. Room & Availability Logic
		if ( ! $bypass_lock && ! Pricing::acquire_booking_lock( $room_id, 10 ) ) {
			return new \WP_Error( 'mhbo_lock_failed', I18n::get_label( 'label_booking_busy' ) );
		}

		try {
			$availability = Pricing::is_room_available( $room_id, $check_in, $check_out, $update_id );
			if ( true !== $availability ) {
				$label = is_string( $availability ) ? $availability : 'label_already_booked';
				return new \WP_Error( 'mhbo_unavailable', I18n::get_label( $label ) );
			}

			// 4. Pricing Calculation
			$tax_mode = isset( $data['tax_mode'] ) && '' !== (string) $data['tax_mode'] ? sanitize_key( (string) $data['tax_mode'] ) : null;
			$calc     = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, $guests, $extras_input, $children, $child_ages, $tax_mode );
			if ( ! $calc ) {
				return new \WP_Error( 'mhbo_pricing_failed', I18n::get_label( 'label_price_calc_error' ) );
			}

			$total = $calc['total'];

			// 5a. $is_privileged was determined early (before validation gates)
			// to support both the price-override guard and validation bypasses.
			// See the privileged_sources array defined above line 96.

			// 2026 BP: Ensure public/chatbot bookings cannot be placed for free/0 if no price has been set.
			if ( ! $is_privileged && ( $calc['room_total']->isZero() || $calc['room_total']->isNegative() ) ) {
				return new \WP_Error( 'mhbo_invalid_price', I18n::get_label( 'label_invalid_price' ) );
			}

			// Allow manual price override ONLY for privileged/internal sources (admin, iCal, OTA).
			// 2026 BP: Public bookings MUST always use server-recalculated pricing to prevent
			// URL/POST price tampering.
			if ( $is_privileged && isset( $data['total_price'] ) ) {
				$total = Money::fromDecimal( (string) $data['total_price'], Pricing::get_currency_code() );
			}
			$booking_extras = $calc['extras_breakdown'] ?? array();
			$tax_data       = $calc['tax'] ?? null;

$charge_amount = $total;
			$deposit_data  = null;

// 5. Payment Verification
			// Only privileged internal sources may preset status/payment_status.
			$status           = $is_privileged ? sanitize_key( $data['status'] ?? 'pending' ) : 'pending';
			$payment_status   = $is_privileged ? sanitize_key( $data['payment_status'] ?? 'pending' ) : 'pending';
			$payment_received = ( $is_privileged && (bool) ( $data['payment_received'] ?? false ) ) ? 1 : 0;
			$transaction_id   = sanitize_text_field( $data['transaction_id'] ?? '' );
			$capture_id       = sanitize_text_field( $data['capture_id'] ?? '' );
			$payment_date     = isset( $data['payment_date'] ) ? sanitize_text_field( $data['payment_date'] ) : null;
			if ( $payment_received && ! $payment_date ) {
				$payment_date = current_time( 'mysql' );
			}

			$is_deposit_charge = ( 'deposit' === $payment_type && $deposit_data !== null );

// Canonical Invariant: If deposit mode is active and deposit charge was selected,
			// the booking status cannot be 'confirmed' unless full payment was received or
			// deposit payment was verified.
			// Exception: Manual bookings via admin booking editor ('manual' or 'admin') allow manual staff overrides.
			if ( ! in_array( $source, array( 'manual', 'admin' ), true ) && $is_deposit_charge && ! $payment_received && 'deposit_paid' !== $payment_status && 'checked_in' !== $status ) {
				$status = 'pending';
			}

			// 5. Custom Fields & GDPR
			$custom_data        = array();
			$custom_fields_defn = get_option( 'mhbo_custom_fields', array() );
			if ( is_array( $custom_fields_defn ) && array() !== $custom_fields_defn ) {
				$post_custom = $data['custom_fields'] ?? array();
				foreach ( $custom_fields_defn as $defn ) {
					$field_id = $defn['id'] ?? '';
					if ( ! $field_id ) {
						continue;
					}

					$val = sanitize_textarea_field( (string) ( $post_custom[ $field_id ] ?? '' ) );
					
					// 2026 BP: Skip required custom field checks for privileged sources
					// (iCal/OTA imports) — external feeds never include custom form data.
					if ( ! $is_privileged && (bool) ( $defn['required'] ?? false ) && '' === $val ) {
						$label = I18n::decode( I18n::encode( $defn['label'] ?? $field_id ) );
						return new \WP_Error( 'mhbo_field_required', sprintf( I18n::get_label( 'label_field_required' ), $label ) );
					}

					if ( '' !== $val ) {
						$custom_data[ $field_id ] = $val;
					}
				}
			}

// 6. Database Insertion
			$insert_data = array(
				'room_id'                => $room_id,
				'customer_name'          => $customer_name,
				'customer_email'         => $customer_email,
				'customer_phone'         => $customer_phone,
				'check_in'               => $check_in,
				'check_out'              => $check_out,
				'total_price'            => (string) $total->toDecimal(),
				'status'                 => $status,
				'booking_token'          => ( function () use ( $wpdb, $update_id ) {
					if ( $update_id > 0 ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$existing_tok = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT booking_token FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d LIMIT 1",
								$update_id
							)
						);
						if ( ! empty( $existing_tok ) ) {
							return (string) $existing_tok;
						}
					}
					return wp_generate_password( 32, false, false );
				} )(),
				'booking_language'       => $language,
				'payment_method'         => $payment_method,
				'payment_received'       => $payment_received,
				'payment_status'         => $payment_status,
				'payment_transaction_id' => $transaction_id ?: null,
				'payment_date'           => $payment_date,
				'payment_amount'         => ( ( $payment_received || 'deposit_paid' === $payment_status ) && isset( $charge_amount ) && $charge_amount instanceof Money ) ? (string) $charge_amount->toDecimal() : null,
				'guests'                 => $guests,
				'children'               => $children,
				'children_ages'          => $children > 0 ? wp_json_encode( $child_ages ) : null,
				'admin_notes'            => sanitize_textarea_field( $data['admin_notes'] ?? '' ),
				'custom_fields'          => array() !== $custom_data ? wp_json_encode( $custom_data ) : null,
				'source'                 => $source,
				'external_id'            => $external_id ?: null,
				'ical_uid'               => $source === 'ical' ? $external_id : null,
			);

			// Guest CRM Linking (v2.6.0)
			if ( GuestManager::is_enabled() ) {
				$resolved_guest_id = GuestManager::resolve_and_link_guest(
					array(
						'customer_name'  => $customer_name,
						'customer_email' => $customer_email,
						'customer_phone' => $customer_phone,
						'first_name'     => $data['first_name'] ?? '',
						'last_name'      => $data['last_name'] ?? '',
					)
				);
				if ( $resolved_guest_id > 0 ) {
					$insert_data['guest_id'] = $resolved_guest_id;
				}
			}

			if ( $tax_data ) {
				$insert_data['tax_enabled']            = ( $tax_data['enabled'] ?? false ) ? 1 : 0;
				$insert_data['tax_mode']               = sanitize_key( $tax_data['mode'] ?? 'disabled' );
				$insert_data['tax_rate_accommodation'] = $tax_data['rates']['accommodation'] ?? 0;
				$insert_data['tax_rate_extras']        = $tax_data['rates']['extras'] ?? 0;
				$insert_data['room_total_net']         = ( $tax_data['totals']['room_net'] instanceof Money ) ? $tax_data['totals']['room_net']->toDecimal() : ( $tax_data['totals']['room_net'] ?? ( $tax_data['breakdown']['room']['net'] ?? '0.00' ) );
				$insert_data['room_tax']               = ( $tax_data['totals']['room_tax'] instanceof Money ) ? $tax_data['totals']['room_tax']->toDecimal() : ( $tax_data['totals']['room_tax'] ?? ( $tax_data['breakdown']['room']['tax'] ?? '0.00' ) );
				$insert_data['children_total_net']     = ( $tax_data['totals']['children_net'] instanceof Money ) ? $tax_data['totals']['children_net']->toDecimal() : ( $tax_data['totals']['children_net'] ?? ( $tax_data['breakdown']['children']['net'] ?? '0.00' ) );
				$insert_data['children_tax']           = ( $tax_data['totals']['children_tax'] instanceof Money ) ? $tax_data['totals']['children_tax']->toDecimal() : ( $tax_data['totals']['children_tax'] ?? ( $tax_data['breakdown']['children']['tax'] ?? '0.00' ) );
				$insert_data['extras_total_net']       = ( $tax_data['totals']['extras_net'] instanceof Money ) ? $tax_data['totals']['extras_net']->toDecimal() : ( $tax_data['totals']['extras_net'] ?? '0.00' );
				$insert_data['extras_tax']             = ( $tax_data['totals']['extras_tax'] instanceof Money ) ? $tax_data['totals']['extras_tax']->toDecimal() : ( $tax_data['totals']['extras_tax'] ?? '0.00' );
				$insert_data['total_tax']              = ( $tax_data['totals']['total_tax'] instanceof Money ) ? $tax_data['totals']['total_tax']->toDecimal() : ( $tax_data['totals']['total_tax'] ?? '0.00' );
				$insert_data['subtotal_net']           = ( $tax_data['totals']['subtotal_net'] instanceof Money ) ? $tax_data['totals']['subtotal_net']->toDecimal() : ( $tax_data['totals']['subtotal_net'] ?? $total->toDecimal() );
				$insert_data['total_gross']            = ( $tax_data['totals']['total_gross'] instanceof Money ) ? $tax_data['totals']['total_gross']->toDecimal() : ( $tax_data['totals']['total_gross'] ?? $total->toDecimal() );
				$insert_data['tax_breakdown']          = wp_json_encode( $tax_data );
				
			}

// Construct initial payment ledger entry if a payment was confirmed
			$bp_currency    = Pricing::get_currency_code();
			$final_paid_m   = isset( $insert_data['payment_amount'] ) ? Money::fromDecimal( (string) $insert_data['payment_amount'], $bp_currency ) : Money::fromCents( 0, $bp_currency );
			if ( $final_paid_m->isPositive() && ( $payment_received || ! empty( $insert_data['deposit_received'] ) || in_array( (string) ( $insert_data['payment_status'] ?? '' ), array( 'completed', 'deposit_paid' ), true ) ) ) {
				$entry_type = ( ! empty( $insert_data['deposit_received'] ) && 0 === (int) ( $insert_data['payment_received'] ?? 0 ) ) ? 'deposit' : 'payment';
				$entry_note = ( 'tablet' === $source )
					? ( 'deposit' === $entry_type ? __( 'Front Desk Tablet deposit confirmed', 'modern-hotel-booking' ) : __( 'Front Desk Tablet payment confirmed', 'modern-hotel-booking' ) )
					: ( ! empty( $transaction_id ) ? ( 'deposit' === $entry_type ? __( 'Online payment gateway deposit confirmed', 'modern-hotel-booking' ) : __( 'Online payment gateway transaction confirmed', 'modern-hotel-booking' ) ) : ( 'deposit' === $entry_type ? __( 'Initial booking deposit payment', 'modern-hotel-booking' ) : __( 'Initial booking payment confirmed', 'modern-hotel-booking' ) ) );

				$cur_user     = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
				$staff_origin = ( 'tablet' === $source )
					? ( ( $cur_user && ! empty( $cur_user->display_name ) ) ? $cur_user->display_name : __( 'Front Desk', 'modern-hotel-booking' ) )
					: ( ! empty( $transaction_id ) ? __( 'Online Gateway', 'modern-hotel-booking' ) : ( ( $cur_user && ! empty( $cur_user->display_name ) ) ? $cur_user->display_name : __( 'Online Gateway', 'modern-hotel-booking' ) ) );

				$insert_data['payment_ledger'] = wp_json_encode(
					array(
						array(
							'id'             => 'pay_' . ( $insert_data['booking_token'] ?? wp_generate_password( 8, false ) ) . '_1',
							'type'           => $entry_type,
							'amount'         => $final_paid_m->toDecimal(),
							'currency'       => Pricing::get_currency_code(),
							'method'         => sanitize_text_field( $insert_data['payment_method'] ?? 'arrival' ),
							'transaction_id' => sanitize_text_field( $transaction_id ?: '' ),
							'date'           => $payment_date ?: HotelTime::now_mysql(),
							'note'           => $entry_note,
							'staff'          => $staff_origin,
						),
					)
				);
			} else {
				$insert_data['payment_ledger'] = null;
			}

			if ( $update_id > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated via Cache::invalidate_booking() below.
				$wpdb->update( "{$wpdb->prefix}mhbo_bookings", $insert_data, array( 'id' => $update_id ) );
				$booking_id = $update_id;
			} else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert; cache invalidated via Cache::invalidate_booking() below.
				$wpdb->insert( "{$wpdb->prefix}mhbo_bookings", $insert_data );
				$booking_id = (int) $wpdb->insert_id;
			}

			if ( ! $booking_id ) {
				return new \WP_Error( 'mhbo_insert_failed', I18n::get_label( 'label_booking_error' ) );
			}

			// 7. Post-Processing
			Cache::invalidate_booking( $booking_id, $room_id );

// Clean up transients
			if ( isset( $data['stripe_pi'] ) ) {
				delete_transient( 'mhbo_pi_amount_' . $data['stripe_pi'] );
				delete_transient( 'mhbo_pi_params_' . $data['stripe_pi'] );
			}
			$raw_pending_session = Privacy::get_cookie_session_id();
			if ( ! empty( $raw_pending_session ) ) {
				delete_transient( 'mhbo_session_pending_booking_' . $raw_pending_session );
			}

			// Hooks
			do_action( 'mhbo_booking_created', $booking_id );
			if ( 'confirmed' === $status ) {
				do_action( 'mhbo_booking_confirmed', $booking_id );
			}

			// Guest CRM Async Stats Recalculation (v2.6.0)
			if ( GuestManager::is_enabled() && ! empty( $insert_data['guest_id'] ) ) {
				GuestAsyncTasks::queue_stats_recalculation( (int) $insert_data['guest_id'] );
			}

			// Bind booking to secure session for guest checkout ownership validation
			$raw_session = Privacy::get_cookie_session_id();
			$session_id  = '' !== $raw_session ? $raw_session : bin2hex( random_bytes( 32 ) );
			if ( ! headers_sent() ) {
				setcookie(
					Privacy::get_cookie_name( 'session' ),
					$session_id,
					array(
						'expires'  => time() + HOUR_IN_SECONDS * 2,
						'path'     => '/',
						'domain'   => '',
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
			set_transient( 'mhbo_session_booking_' . $session_id, $booking_id, HOUR_IN_SECONDS * 2 );

			// 8. Prepare Success Response
			$success_nonce = wp_create_nonce( 'mhbo_success_display' );
			$token         = $insert_data['booking_token'];
			
			$success_url = add_query_arg(
				array(
					'mhbo_success'       => 1,
					'mhbo_success_nonce' => $success_nonce,
					'mhbo_status'        => $status,
					'reference'          => $token,
				),
				remove_query_arg( array( 'mhbo_auto_book', 'mhbo_nonce', 'mhbo_confirm_booking' ), $data['page_url'] ?? home_url( '/' ) )
			);

			return array(
				'booking_id'   => $booking_id,
				'status'       => $status,
				'token'        => $token,
				'redirect_url' => $success_url,
				'message'      => I18n::get_label( 'label_booking_success' ),
			);

		} catch ( \Throwable $e ) {
			return new \WP_Error( 'mhbo_exception', $e->getMessage() );
		} finally {
			// 2026 BP: Zero-Leak guarantee — lock released unless caller owns it (bypass_lock).
			if ( ! $bypass_lock ) {
				Pricing::release_booking_lock( $room_id );
			}
		}
	}
}
