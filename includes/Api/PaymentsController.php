<?php declare(strict_types=1);

namespace MHBO\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use MHBO\Database\Queries\Booking_Query;
use MHBO\Core\Pricing;
use MHBO\Core\Money;
use MHBO\Core\I18n;
use MHBO\Core\Cache;
use MHBO\Core\Privacy;
use MHBO\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PaymentsController (WP 7.1 / 2026 Standard)
 *
 * Implements Option B: Server-Side Session Pricing.
 * Prevents client-side price manipulation by calculating prices exclusively server-side
 * and binding temporary payment sessions to the guest's mhbo_session cookie.
 *
 * Booking ID Reconciliation Architecture Note:
 * When creating a PaymentIntent before a booking record exists in the database,
 * the PaymentIntent ID is attached to the form and submitted to /booking/complete.
 * If the user completes payment on Stripe but abandons /booking/complete, or if the
 * Stripe webhook fires before /booking/complete completes, the webhook handler
 * queries by session_token/payment_intent_id to reconcile and ensure no orphan payments.
 */
class PaymentsController extends WP_REST_Controller {

	/**
	 * REST API Namespace.
	 *
	 * @var non-falsy-string
	 */
	protected $namespace = 'mhbo/v1';

	public function __construct() {
		$this->namespace = 'mhbo/v1';
		$this->rest_base = 'payments';
	}

	public function register_routes(): void {
		// 1. Calculate endpoint (Option B: Server-Side Session Pricing)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/calculate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'calculate_session' ),
				'permission_callback' => '__return_true', // Rate limiting and nonces verified inside callback
			)
		);

}

	private function check_https(): ?WP_Error {
		if ( ! is_ssl() && wp_get_environment_type() === 'production' ) {
			return new WP_Error( 'rest_forbidden', 'HTTPS is required for payment endpoints.', array( 'status' => 403 ) );
		}
		return null;
	}

	/**
	 * Verify that a nonce is valid against one or more acceptable action contexts.
	 * Avoids compound boolean short-circuit evaluation.
	 *
	 * @param string          $nonce   The nonce value to verify.
	 * @param string|string[] $actions Action or list of actions.
	 * @return bool True if valid, false otherwise.
	 */
	private function verify_action_nonce( string $nonce, string|array $actions ): bool {
		if ( '' === $nonce ) {
			return false;
		}
		$actions_list = (array) $actions;
		foreach ( $actions_list as $action ) {
			if ( wp_verify_nonce( $nonce, $action ) ) {
				return true;
			}
		}
		return false;
	}

	private function check_rate_limit(): ?WP_Error {
		// 2026 BP: Exempt administrators and declared local/development environments (safe, environment-type based)
		$is_exempt = current_user_can( 'manage_options' ) ||
			( function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) );

		if ( $is_exempt ) {
			return null;
		}

		static $incremented = false;
		
		$client_ip  = Security::get_client_ip();
		$rate_key   = 'mhbo_intent_rate_' . md5( sanitize_text_field( $client_ip ) );
		$rate_count = (int) get_transient( $rate_key );
		
		if ( $rate_count >= 10 ) {
			return new WP_Error( 'rate_limited', 'Too many requests. Please try again later.', array( 'status' => 429 ) );
		}

		if ( ! $incremented ) {
			set_transient( $rate_key, $rate_count + 1, MINUTE_IN_SECONDS * 15 );
			$incremented = true;
		}
		
		return null;
	}

	/**
	 * Generate an idempotency key.
	 *
	 * @param string                            $gateway    Gateway slug.
	 * @param int|string                        $identifier Unique identifier.
	 * @param array<string|int, mixed>          $params     Additional parameters.
	 * @return string
	 */
	private function get_idempotency_key( string $gateway, int|string $identifier, array $params = array() ): string {
		$hash_payload = (string) $identifier;
		if ( ! empty( $params ) ) {
			$hash_payload .= '|' . implode( '|', $params );
		}
		return 'mhbo_' . substr( $gateway, 0, 2 ) . '_' . md5( $hash_payload );
	}

	/**
	 * Calculate session pricing server-side and issue a bound session token.
	 */
	public function calculate_session( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$https_check = $this->check_https();
		if ( $https_check ) {
			return $https_check;
		}

		// Gap 2: Strict Rate Limiting on calculation endpoint
		$rate_check = $this->check_rate_limit();
		if ( $rate_check ) {
			return $rate_check;
		}

		// Nonce check
		$nonce = (string) ( $request->get_param( 'nonce' ) ?: $request->get_param( 'security' ) ?: '' );
		if ( ! $this->verify_action_nonce( $nonce, array( 'mhbo_stripe_nonce', 'mhbo_paypal_nonce', 'mhbo_braintree_nonce', 'wp_rest' ) ) ) {
			return new WP_Error( 'rest_forbidden', 'Invalid security token.', array( 'status' => 403 ) );
		}

		$room_id      = (int) $request->get_param( 'room_id' );
		$type_id      = (int) $request->get_param( 'type_id' );
		$check_in     = sanitize_text_field( (string) $request->get_param( 'check_in' ) );
		$check_out    = sanitize_text_field( (string) $request->get_param( 'check_out' ) );
		$guests       = (int) ( $request->get_param( 'guests' ) ?: 1 );
		$children     = 0;
		$child_ages   = array();
		
		$extras = array();
		
		$payment_type = sanitize_text_field( (string) ( $request->get_param( 'mhbo_payment_type' ) ?: 'full' ) );

		// Resolve room_id from type_id if category booking
		if ( 0 === $room_id && 0 !== $type_id ) {
			if ( class_exists( Pricing::class ) && method_exists( Pricing::class, 'find_available_room' ) ) {
				$resolved_room = Pricing::find_available_room( $type_id, $check_in, $check_out );
				if ( $resolved_room ) {
					$room_id = (int) $resolved_room;
				}
			}
		}

		if ( 0 === $room_id ) {
			return new WP_Error( 'rest_not_found', 'Room not found or unavailable.', array( 'status' => 404 ) );
		}

		global $wpdb;

		// Gap 1: Bind session to cookie early
		$session_id = Privacy::get_cookie_session_id();
		if ( empty( $session_id ) ) {
			$session_id = bin2hex( random_bytes( 32 ) );
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
		}

		// Check if the current user session already holds a pending provisional draft (e.g. switching payment gateways or re-calculating)
		$existing_pending_id = (int) get_transient( 'mhbo_session_pending_booking_' . $session_id );
		if ( $existing_pending_id > 0 ) {
			// Direct DB query required: verify whether the provisional record is still in draft state.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pending_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $existing_pending_id ) );
			if ( 'pending_payment' !== $pending_status ) {
				// Not a draft — stale or already completed reservation; discard and start fresh!
				delete_transient( 'mhbo_session_pending_booking_' . $session_id );
				$existing_pending_id = 0;
			}
		}

		if ( class_exists( Pricing::class ) && method_exists( Pricing::class, 'ensure_pro_init' ) ) {
			Pricing::ensure_pro_init();
		}

		// Availability check (exclude the user's current pending booking if re-calculating)
		if ( class_exists( Pricing::class ) && method_exists( Pricing::class, 'is_room_available' ) ) {
			$availability = Pricing::is_room_available( $room_id, $check_in, $check_out, $existing_pending_id );
			if ( $availability !== true ) {
				return new WP_Error( 'availability_conflict', 'Selected dates are no longer available.', array( 'status' => 409 ) );
			}
		}

		$calc        = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, $guests, $extras, $children, $child_ages );
		$total_money = $calc['total'] ?? null;

		// Coupon code handling if provided
		$coupon_code             = sanitize_text_field( (string) ( $request->get_param( 'mhbo_coupon_code' ) ?: $request->get_param( 'mhbo_coupon_applied' ) ?: '' ) );
		$applied_coupon_code     = '';
		$applied_coupon_discount = null;

if ( $total_money === null || $total_money->isZero() ) {
			return new WP_Error( 'rest_error', 'Invalid booking calculation.', array( 'status' => 400 ) );
		}

		// Deposit handling
		$deposit_money   = null;
		$remaining_money = null;
		$payable_money   = $total_money;

// Cryptographically secure 64-char booking token
		$booking_token   = bin2hex( random_bytes( 32 ) );
		$total_decimal   = $total_money->toDecimal();
		$payable_decimal = $payable_money->toDecimal();
		$currency_code   = $total_money->getCurrency();

		// Customer details if provided during calculation
		$customer_name  = sanitize_text_field( (string) ( $request->get_param( 'customer_name' ) ?: 'Guest' ) );
		$customer_email = sanitize_email( (string) ( $request->get_param( 'customer_email' ) ?: 'guest@pending.local' ) );
		$customer_phone = sanitize_text_field( (string) ( $request->get_param( 'customer_phone' ) ?: '' ) );

		// Insert durable booking in pending_payment status (prevents webhook timing gap)
		$table = $wpdb->prefix . 'mhbo_bookings';

		$booking_data = array(
			'room_id'           => $room_id,
			'customer_name'     => $customer_name,
			'customer_email'    => $customer_email,
			'customer_phone'    => $customer_phone,
			'check_in'          => $check_in,
			'check_out'         => $check_out,
			'guests'            => $guests,
			'children'          => $children,
			'children_ages'     => ! empty( $child_ages ) ? wp_json_encode( $child_ages ) : null,
			// Store the canonical extras breakdown (objects with name/quantity), NOT the raw
			// id=>qty map produced by normalize_extras(), which downstream readers (admin edit
			// form, email/invoice formatters) cannot consume and would fatal on.
			'booking_extras'    => ! empty( $calc['extras_breakdown'] ) ? wp_json_encode( $calc['extras_breakdown'] ) : null,
			'total_price'       => $total_decimal,
			'deposit_amount'    => $deposit_money ? (string) $deposit_money->toDecimal() : null,
			'remaining_balance' => $remaining_money ? (string) $remaining_money->toDecimal() : null,
			'status'            => 'pending_payment',
			'booking_token'     => $booking_token,
			'payment_status'    => 'pending',
			'payment_type'      => $payment_type,
			'payment_method'    => sanitize_key( (string) ( $request->get_param( 'gateway' ) ?: $request->get_param( 'mhbo_payment_method' ) ?: 'stripe' ) ),
			'created_at'        => current_time( 'mysql' ),
		);

		// Store tax breakdown and net columns if tax data is present
		if ( ! empty( $calc['tax'] ) ) {
			$tax_obj                                = $calc['tax'];
			$booking_data['tax_enabled']            = ( $tax_obj['enabled'] ?? false ) ? 1 : 0;
			$booking_data['tax_mode']               = sanitize_key( $tax_obj['mode'] ?? 'disabled' );
			$booking_data['tax_rate_accommodation'] = $tax_obj['rates']['accommodation'] ?? 0;
			$booking_data['tax_rate_extras']        = $tax_obj['rates']['extras'] ?? 0;
			$booking_data['room_total_net']         = ( $tax_obj['totals']['room_net'] instanceof Money ) ? $tax_obj['totals']['room_net']->toDecimal() : ( $tax_obj['totals']['room_net'] ?? ( $tax_obj['breakdown']['room']['net'] ?? '0.00' ) );
			$booking_data['room_tax']               = ( $tax_obj['totals']['room_tax'] instanceof Money ) ? $tax_obj['totals']['room_tax']->toDecimal() : ( $tax_obj['totals']['room_tax'] ?? ( $tax_obj['breakdown']['room']['tax'] ?? '0.00' ) );
			$booking_data['children_total_net']     = ( $tax_obj['totals']['children_net'] instanceof Money ) ? $tax_obj['totals']['children_net']->toDecimal() : ( $tax_obj['totals']['children_net'] ?? ( $tax_obj['breakdown']['children']['net'] ?? '0.00' ) );
			$booking_data['children_tax']           = ( $tax_obj['totals']['children_tax'] instanceof Money ) ? $tax_obj['totals']['children_tax']->toDecimal() : ( $tax_obj['totals']['children_tax'] ?? ( $tax_obj['breakdown']['children']['tax'] ?? '0.00' ) );
			$booking_data['extras_total_net']       = ( $tax_obj['totals']['extras_net'] instanceof Money ) ? $tax_obj['totals']['extras_net']->toDecimal() : ( $tax_obj['totals']['extras_net'] ?? '0.00' );
			$booking_data['extras_tax']             = ( $tax_obj['totals']['extras_tax'] instanceof Money ) ? $tax_obj['totals']['extras_tax']->toDecimal() : ( $tax_obj['totals']['extras_tax'] ?? '0.00' );
			$booking_data['total_tax']              = ( $tax_obj['totals']['total_tax'] instanceof Money ) ? $tax_obj['totals']['total_tax']->toDecimal() : ( $tax_obj['totals']['total_tax'] ?? '0.00' );
			$booking_data['subtotal_net']           = ( $tax_obj['totals']['subtotal_net'] instanceof Money ) ? $tax_obj['totals']['subtotal_net']->toDecimal() : ( $tax_obj['totals']['subtotal_net'] ?? $total_decimal );
			$booking_data['total_gross']            = ( $tax_obj['totals']['total_gross'] instanceof Money ) ? $tax_obj['totals']['total_gross']->toDecimal() : ( $tax_obj['totals']['total_gross'] ?? $total_decimal );
			$booking_data['tax_breakdown']          = wp_json_encode( $tax_obj );
		}

// Auto-resolve guest profile for session if customer email is known
		if ( \MHBO\Core\GuestManager::is_enabled() && ! empty( $customer_email ) && 'guest@pending.local' !== $customer_email ) {
			$resolved_guest_id = \MHBO\Core\GuestManager::resolve_and_link_guest(
				array(
					'customer_name'  => $customer_name,
					'customer_email' => $customer_email,
					'customer_phone' => $customer_phone,
				)
			);
			if ( $resolved_guest_id > 0 ) {
				$booking_data['guest_id'] = $resolved_guest_id;
			}
		}

		if ( $existing_pending_id > 0 ) {
			// Direct DB update required: provisional reservation state must reflect real-time session parameters.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $booking_data, array( 'id' => $existing_pending_id ) );
			$booking_id = $existing_pending_id;
		} else {
			// Direct DB insert required: creating a provisional booking record to bind against payment intents.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $table, $booking_data );
			$booking_id = (int) $wpdb->insert_id;
			if ( $booking_id > 0 ) {
				set_transient( 'mhbo_session_pending_booking_' . $session_id, $booking_id, HOUR_IN_SECONDS );
			}
		}

		if ( $booking_id <= 0 ) {
			return new WP_Error( 'rest_error', 'Failed to initialize pending booking.', array( 'status' => 500 ) );
		}

		// Lightweight session binding transient (1 hour TTL)
		set_transient(
			'mhbo_booking_session_' . $booking_token,
			array(
				'booking_id' => $booking_id,
				'session_id' => $session_id,
			),
			HOUR_IN_SECONDS
		);

		// Backward compatibility alias for session_token
		set_transient(
			'mhbo_session_' . $booking_token,
			array(
				'price'      => $total_decimal,
				'currency'   => $currency_code,
				'booking_id' => $booking_id,
				'session_id' => $session_id,
			),
			HOUR_IN_SECONDS
		);

		return new WP_REST_Response(
			array(
				'success'       => true,
				'amount'        => $payable_decimal,
				'total_amount'  => $total_decimal,
				'currency'      => $currency_code,
				'booking_id'    => $booking_id,
				'booking_token' => $booking_token,
				'session_token' => $booking_token, // Alias for backward compatibility
				'data'          => array(
					'amount'        => $payable_decimal,
					'total_amount'  => $total_decimal,
					'currency'      => $currency_code,
					'booking_id'    => $booking_id,
					'booking_token' => $booking_token,
					'session_token' => $booking_token,
				),
			)
		);
	}

}
