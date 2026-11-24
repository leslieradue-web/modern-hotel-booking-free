<?php declare(strict_types=1);

namespace MHBO\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\BookingProcessor;
use MHBO\Core\Cache;
use MHBO\Core\Email;
use MHBO\Core\HotelTime;
use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Security;
use MHBO\Core\Tax;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) && file_exists( ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-controller.php' ) ) {
		require_once ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-controller.php';
	}
}

/**
 * REST API Controller for Guest Self-Service Portal.
 *
 * Provides endpoints for guests to securely inspect, cancel, and
 * request modifications to their reservations without front desk calls.
 *
 * @package MHBO\Api
 * @since   2.6.2
 */
class GuestPortalController extends WP_REST_Controller {

	/**
	 * REST API Namespace.
	 *
	 * @var non-falsy-string
	 */
	protected $namespace = 'mhbo/v1';

	/**
	 * Initialize the Guest Portal REST Controller.
	 */
	public function __construct() {
		$this->namespace = 'mhbo/v1';
		$this->rest_base = 'portal';
	}

	/**
	 * Register the guest portal REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /mhbo/v1/portal/booking — Retrieve booking details by token or ID+email
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/booking',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_booking' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'      => array(
						'description'       => 'Secret booking token.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'booking_id' => array(
						'description'       => 'Booking database ID.',
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'email'      => array(
						'description'       => 'Customer email address.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);

		// POST /mhbo/v1/portal/cancel — Guest self-service cancellation
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_booking' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'booking_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'email'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_email',
					),
					'reason'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'phone'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /mhbo/v1/portal/modify — Request booking date or detail modification
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/modify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'request_modification' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'            => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'booking_id'       => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'email'            => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_email',
					),
					'requested_dates'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'requested_guests' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'notes'            => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// POST /mhbo/v1/portal/update-profile — Update guest contact details safely
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/update-profile',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_profile' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'            => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'booking_id'       => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'email'            => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_email',
					),
					'customer_name'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_phone'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_email'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'arrival_time'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'special_requests' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// POST /mhbo/v1/portal/update-stay — Direct stay date update for unpaid stays (with staff review fallback for paid)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/update-stay',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_stay' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'booking_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'email'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_email',
					),
					'check_in'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'new_check_in'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'check_out'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'new_check_out' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'guests'        => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'new_guests'    => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'children'      => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'new_children'  => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'notes'         => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'confirmed_total' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /mhbo/v1/portal/preview-stay — Read-only quote & eligibility preview for stay date changes
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/preview-stay',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview_stay' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'         => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'booking_id'    => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'email'         => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_email',
					),
					'check_in'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'new_check_in'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'check_out'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'new_check_out' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'notes'         => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

}

	/**
	 * Sanitize a Guest Portal self-service policy mode option value.
	 *
	 * @param mixed $value Raw option value.
	 * @return string One of 'request_only', 'auto_unpaid', or 'disabled'.
	 */
	public static function sanitize_policy_mode( mixed $value ): string {
		$mode = sanitize_key( (string) $value );
		return in_array( $mode, array( 'request_only', 'auto_unpaid', 'disabled' ), true ) ? $mode : 'request_only';
	}

	/**
	 * Determine whether the property cancellation & modification policy notice is enabled.
	 *
	 * Off by default; when disabled, the notice is hidden in the Guest Portal and omitted from
	 * checkout previews, {cancellation_policy} email placeholders, and AI policy lookups.
	 *
	 * @return bool
	 */
	public static function is_cancellation_policy_enabled(): bool {
		return 1 === (int) get_option( 'mhbo_cancellation_policy_enabled', 0 );
	}

	/**
	 * Return the active decoded cancellation & modification policy notice, or empty string when disabled.
	 *
	 * @param string|null $lang Optional explicit language code for I18n::decode().
	 * @return string
	 */
	public static function get_active_cancellation_policy( ?string $lang = null ): string {
		if ( ! self::is_cancellation_policy_enabled() ) {
			return '';
		}
		$raw = (string) get_option( 'mhbo_cancellation_policy', '' );
		if ( '' === trim( $raw ) ) {
			return '';
		}
		$decoded = null !== $lang
			? (string) ( I18n::decode( $raw, $lang ) ?? '' )
			: (string) ( I18n::decode( $raw ) ?? '' );
		return trim( $decoded );
	}

	/**
	 * Determine whether Guest Portal self-service changes/cancellations are enabled.
	 *
	 * Defaults to disabled (false) on fresh installs to protect property calendars.
	 * On legacy upgrades where mhbo_portal_self_service_enabled has never been saved,
	 * preserves active behavior if a non-disabled modify/cancel mode was previously stored.
	 *
	 * @return bool
	 */
	public static function is_self_service_enabled(): bool {
		$sentinel = '__mhbo_unset__';
		$raw      = get_option( 'mhbo_portal_self_service_enabled', $sentinel );
		if ( $sentinel !== $raw ) {
			return 1 === (int) $raw;
		}

		// Legacy upgrade compatibility: if an existing install has stored non-disabled modes,
		// keep self-service enabled until the hotelier explicitly toggles it off.
		$stored_modify = get_option( 'mhbo_portal_modify_mode', $sentinel );
		$stored_cancel = get_option( 'mhbo_portal_cancel_mode', $sentinel );
		if ( $sentinel !== $stored_modify || $sentinel !== $stored_cancel ) {
			$mod_active = is_string( $stored_modify ) && in_array( sanitize_key( $stored_modify ), array( 'request_only', 'auto_unpaid' ), true );
			$can_active = is_string( $stored_cancel ) && in_array( sanitize_key( $stored_cancel ), array( 'request_only', 'auto_unpaid' ), true );
			return $mod_active || $can_active;
		}

		return false;
	}

	/**
	 * Resolve stored Guest Portal stay modification policy mode (ignoring master switch).
	 *
	 * @return string One of 'request_only', 'auto_unpaid', or 'disabled'.
	 */
	public static function get_stored_modify_mode(): string {
		$mode = sanitize_key( (string) get_option( 'mhbo_portal_modify_mode', 'request_only' ) );
		return in_array( $mode, array( 'request_only', 'auto_unpaid', 'disabled' ), true ) ? $mode : 'request_only';
	}

	/**
	 * Resolve stored Guest Portal cancellation policy mode (ignoring master switch).
	 *
	 * @return string One of 'request_only', 'auto_unpaid', or 'disabled'.
	 */
	public static function get_stored_cancel_mode(): string {
		$mode = sanitize_key( (string) get_option( 'mhbo_portal_cancel_mode', 'request_only' ) );
		return in_array( $mode, array( 'request_only', 'auto_unpaid', 'disabled' ), true ) ? $mode : 'request_only';
	}

	/**
	 * Resolve configured Guest Portal stay modification policy mode.
	 *
	 * @return string One of 'request_only', 'auto_unpaid', or 'disabled'.
	 */
	public static function get_modify_mode(): string {
		if ( ! self::is_self_service_enabled() ) {
			return 'disabled';
		}
		return self::get_stored_modify_mode();
	}

	/**
	 * Resolve configured Guest Portal cancellation policy mode.
	 *
	 * @return string One of 'request_only', 'auto_unpaid', or 'disabled'.
	 */
	public static function get_cancel_mode(): string {
		if ( ! self::is_self_service_enabled() ) {
			return 'disabled';
		}
		return self::get_stored_cancel_mode();
	}

	/**
	 * Resolve the primary front-desk contact string (phone preferred, falling back to email).
	 *
	 * @return string
	 */
	public static function get_front_desk_contact(): string {
		$company_info = class_exists( '\\MHBO\\Business\\Info' ) ? \MHBO\Business\Info::get_company() : array();
		$hotel_phone  = (string) get_option( 'mhbo_phone', get_option( 'mhbo_business_phone', '' ) );
		if ( '' === $hotel_phone && ! empty( $company_info['telephone'] ) ) {
			$hotel_phone = (string) $company_info['telephone'];
		}
		if ( '' !== trim( $hotel_phone ) ) {
			return trim( $hotel_phone );
		}

		$hotel_email = (string) get_option( 'mhbo_notification_email', '' );
		if ( '' === $hotel_email && ! empty( $company_info['email'] ) ) {
			$hotel_email = (string) $company_info['email'];
		}
		if ( '' === $hotel_email ) {
			$hotel_email = (string) get_option( 'admin_email', '' );
		}
		return trim( $hotel_email );
	}

	/**
	 * Resolve configured maximum self-service modifications per booking (0 = unlimited).
	 *
	 * @return int
	 */
	public static function get_max_modifications(): int {
		return max( 0, min( 20, (int) get_option( 'mhbo_portal_max_modifications', 2 ) ) );
	}

	/**
	 * Decode booking custom_fields JSON safely into an associative array.
	 *
	 * @param object $booking Booking database row.
	 * @return array<string, mixed>
	 */
	private function decode_booking_custom_fields( object $booking ): array {
		if ( empty( $booking->custom_fields ) ) {
			return array();
		}
		$decoded = is_string( $booking->custom_fields )
			? json_decode( $booking->custom_fields, true )
			: (array) $booking->custom_fields;
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Check duplicate staff-review request cooldown for a booking (10 minutes).
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $type       Request type ('cancel' or 'modify').
	 * @return WP_Error|null
	 */
	private function check_staff_request_cooldown( int $booking_id, string $type ): ?WP_Error {
		$key = 'mhbo_portal_' . sanitize_key( $type ) . '_req_' . $booking_id;
		if ( false !== get_transient( $key ) ) {
			return new WP_Error(
				'mhbo_duplicate_request_cooldown',
				__( 'A request for this reservation was already submitted recently. Please allow our front desk team a few minutes to review it before submitting another request.', 'modern-hotel-booking' ),
				array( 'status' => 429 )
			);
		}
		return null;
	}

	/**
	 * Record staff-review request cooldown transient (10 minutes).
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $type       Request type ('cancel' or 'modify').
	 * @return void
	 */
	private function set_staff_request_cooldown( int $booking_id, string $type ): void {
		$key = 'mhbo_portal_' . sanitize_key( $type ) . '_req_' . $booking_id;
		set_transient( $key, time(), 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Format a signed Money delta (e.g. +$120.00, −$50.00, $0.00).
	 *
	 * @param Money $delta_m Money difference.
	 * @return string
	 */
	private static function format_signed_delta( Money $delta_m ): string {
		$delta_val = (float) $delta_m->toDecimal();
		if ( $delta_m->isPositive() ) {
			return '+' . Pricing::format_price( $delta_val );
		}
		if ( $delta_m->isNegative() ) {
			return '−' . Pricing::format_price( abs( $delta_val ) );
		}
		return Pricing::format_price( 0.0 );
	}

	/**
	 * Evaluate whether a booking is eligible for automatic self-service date modification.
	 *
	 * @param object                    $booking         Booking row object.
	 * @param float                     $paid_val        Total verified amount paid.
	 * @param array<string, mixed>|null $recalc_fin      Optional post-recalculation financials.
	 * @param array<string, mixed>      $original_custom Original decoded custom_fields.
	 * @param array<string, mixed>      $updated_custom  Updated decoded custom_fields.
	 * @return array{eligible: bool, reason_code: string, reason_message: string}
	 */
	public static function evaluate_auto_modify_eligibility(
		object $booking,
		float $paid_val,
		?array $recalc_fin = null,
		array $original_custom = array(),
		array $updated_custom = array()
	): array {
		if ( 'auto_unpaid' !== self::get_modify_mode() ) {
			return array(
				'eligible'       => false,
				'reason_code'    => 'policy_staff_approval',
				'reason_message' => __( 'Property policy requires front desk approval for stay date changes. Your request will be reviewed by our reservations team.', 'modern-hotel-booking' ),
			);
		}

		$status = (string) ( $booking->status ?? '' );
		if ( ! in_array( $status, array( 'confirmed', 'pending' ), true ) ) {
			return array(
				'eligible'       => false,
				'reason_code'    => 'status_requires_staff',
				'reason_message' => __( 'Reservations in this status require front desk review to modify stay dates.', 'modern-hotel-booking' ),
			);
		}

		$today = HotelTime::today();
		if ( $today >= (string) ( $booking->check_in ?? '' ) ) {
			return array(
				'eligible'       => false,
				'reason_code'    => 'stay_already_started',
				'reason_message' => __( 'Same-day or in-house stay date changes require front desk review.', 'modern-hotel-booking' ),
			);
		}

		if ( $paid_val > 0.001 ) {
			return array(
				'eligible'       => false,
				'reason_code'    => 'payment_on_file',
				'reason_message' => __( 'A payment is already recorded for this booking. Your requested dates will be submitted to our front desk team to calculate any rate difference or refund before finalizing.', 'modern-hotel-booking' ),
			);
		}

		if ( ! empty( $booking->is_multi_room ) || ! empty( $booking->multi_room_parent ) ) {
			return array(
				'eligible'       => false,
				'reason_code'    => 'group_booking',
				'reason_message' => __( 'Multi-room group reservations require front desk review to keep all rooms synchronized.', 'modern-hotel-booking' ),
			);
		}

		$lead_hours = max( 0, (int) get_option( 'mhbo_cancellation_lead_hours', 24 ) );
		if ( $lead_hours > 0 ) {
			$in_ts     = HotelTime::midnight( (string) ( $booking->check_in ?? '' ) );
			$cutoff_ts = $in_ts - ( $lead_hours * 3600 );
			if ( HotelTime::now()->getTimestamp() > $cutoff_ts ) {
				return array(
					'eligible'       => false,
					'reason_code'    => 'inside_cutoff_window',
					'reason_message' => sprintf(
						/* translators: %d: cutoff window hours */
						__( 'This reservation is within %d hours of check-in, so date changes require front desk approval.', 'modern-hotel-booking' ),
						$lead_hours
					),
				);
			}
		}

		$max_mods  = self::get_max_modifications();
		$mod_count = (int) ( $original_custom['_portal_mod_count'] ?? 0 );
		if ( $max_mods > 0 && $mod_count >= $max_mods ) {
			return array(
				'eligible'       => false,
				'reason_code'    => 'max_modifications_reached',
				'reason_message' => __( 'You have reached the self-service date change limit for this reservation. Further changes require front desk approval.', 'modern-hotel-booking' ),
			);
		}

		if ( (float) ( $booking->discount_amount ?? 0 ) > 0.001 && '' === trim( (string) ( $booking->coupon_code ?? '' ) ) ) {
			return array(
				'eligible'       => false,
				'reason_code'    => 'manual_discount_present',
				'reason_message' => __( 'This reservation includes a custom discount and requires front desk review to modify stay dates.', 'modern-hotel-booking' ),
			);
		}

		if ( ! empty( $booking->tax_enabled ) && class_exists( Tax::class ) ) {
			$tax_override = false;
			if ( ! empty( $booking->tax_mode ) && Tax::normalize_mode( (string) $booking->tax_mode ) !== Tax::get_mode() ) {
				$tax_override = true;
			}
			if ( ! $tax_override && isset( $booking->tax_rate_accommodation ) && '' !== (string) $booking->tax_rate_accommodation ) {
				if ( abs( (float) $booking->tax_rate_accommodation - Tax::get_accommodation_rate() ) > 0.0001 ) {
					$tax_override = true;
				}
			}
			if ( ! $tax_override && isset( $booking->tax_rate_extras ) && '' !== (string) $booking->tax_rate_extras ) {
				if ( abs( (float) $booking->tax_rate_extras - Tax::get_extras_rate() ) > 0.0001 ) {
					$tax_override = true;
				}
			}
			if ( $tax_override ) {
				return array(
					'eligible'       => false,
					'reason_code'    => 'custom_tax_override',
					'reason_message' => __( 'This reservation uses custom tax rules and requires front desk review to recalculate charges.', 'modern-hotel-booking' ),
				);
			}
		}

		if ( null !== $recalc_fin ) {
			$coupon_code = trim( (string) ( $booking->coupon_code ?? '' ) );
			$orig_disc   = max( (float) ( $booking->coupon_discount ?? 0 ), (float) ( $booking->discount_amount ?? 0 ) );
			if ( '' !== $coupon_code && $orig_disc > 0.001 && isset( $recalc_fin['discount'] ) && $recalc_fin['discount'] instanceof Money && ! $recalc_fin['discount']->isPositive() ) {
				return array(
					'eligible'       => false,
					'reason_code'    => 'coupon_invalidated',
					'reason_message' => __( 'Your applied promotional coupon does not apply to the new dates. Please submit a request so our front desk can assist.', 'modern-hotel-booking' ),
				);
			}

			if ( ( ! empty( $original_custom['_early_checkin'] ) && empty( $updated_custom['_early_checkin'] ) )
				|| ( ! empty( $original_custom['_late_checkout'] ) && empty( $updated_custom['_late_checkout'] ) ) ) {
				return array(
					'eligible'       => false,
					'reason_code'    => 'stay_timing_addon_removed',
					'reason_message' => __( 'Your early check-in or late check-out option is unavailable on the selected dates, so this change requires front desk review.', 'modern-hotel-booking' ),
				);
			}
		}

		return array(
			'eligible'       => true,
			'reason_code'    => 'auto_eligible',
			'reason_message' => '',
		);
	}

	/**
	 * Check if the Guest Self-Service Portal is active and published on the site.
	 *
	 * @return bool True if active, false otherwise.
	 */
	public static function is_portal_active(): bool {
		return null !== self::get_portal_url();
	}

	/**
	 * Retrieve the canonical Guest Self-Service Portal URL if active on the site.
	 *
	 * Checks explicitly configured mhbo_guest_portal_url, then mhbo_guest_portal_page,
	 * then auto-detects any published WordPress page containing [mhbo_guest_portal].
	 * Returns null if the guest portal is not active on this site.
	 *
	 * @param string|null $token      Optional booking security token.
	 * @param int         $booking_id Optional booking reference ID.
	 * @param string      $email      Optional customer email address.
	 * @return string|null Absolute portal URL, or null if portal is not active.
	 */
	public static function get_portal_url( ?string $token = null, int $booking_id = 0, string $email = '' ): ?string {
		$override_url = (string) I18n::decode( (string) get_option( 'mhbo_guest_portal_url', '' ), null, false );
		$portal_url   = '';

		if ( '' !== trim( $override_url ) ) {
			$portal_url = trim( $override_url );
		} else {
			$page_id = (int) get_option( 'mhbo_guest_portal_page', 0 );

			if ( $page_id <= 0 ) {
				$cached_id = get_transient( 'mhbo_detected_guest_portal_page' );
				if ( false !== $cached_id && is_numeric( $cached_id ) ) {
					$page_id = (int) $cached_id;
				} else {
					/**
					 * WordPress database abstraction object.
					 *
					 * @var \wpdb $wpdb
					 */
					global $wpdb;
					if ( $wpdb instanceof \wpdb ) {
						$posts_table = ! empty( $wpdb->posts ) ? $wpdb->posts : ( $wpdb->prefix . 'posts' );
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$found_id = (int) $wpdb->get_var(
							$wpdb->prepare(
								"SELECT ID FROM %i 
								 WHERE post_status = 'publish' 
								   AND post_type = 'page' 
								   AND post_content LIKE %s 
								 ORDER BY ID DESC LIMIT 1",
								$posts_table,
								'%[mhbo_guest_portal%'
							)
						);
						$page_id = $found_id > 0 ? $found_id : 0;
						set_transient( 'mhbo_detected_guest_portal_page', $page_id, DAY_IN_SECONDS );
					}
				}
			}

			if ( $page_id > 0 ) {
				$page_id = I18n::get_translated_object_id( $page_id, 'page' );
				$status  = get_post_status( $page_id );
				if ( 'publish' === $status ) {
					$permalink = get_permalink( $page_id );
					if ( is_string( $permalink ) && '' !== $permalink ) {
						$portal_url = $permalink;
					}
				}
			}
		}

		if ( '' === $portal_url ) {
			return null;
		}

		// Attach token or id+email query arguments if supplied
		if ( ! empty( $token ) ) {
			return add_query_arg( 'token', $token, $portal_url );
		}

		if ( $booking_id > 0 && '' !== $email ) {
			return add_query_arg(
				array(
					'booking_id' => $booking_id,
					'email'      => $email,
				),
				$portal_url
			);
		}

		return $portal_url;
	}

	/**
	 * Determine whether a booking row uses a synthetic placeholder email or non-guest origin
	 * that must never authenticate via predictable (booking_id, email) lookup.
	 *
	 * @param object $row Booking database row.
	 * @return bool True if disallowed for (booking_id, email) portal login.
	 */
	private function is_placeholder_or_non_guest_booking( object $row ): bool {
		$status = strtolower( trim( (string) ( $row->status ?? '' ) ) );
		if ( 'pending_payment' === $status ) {
			return true;
		}

		$source = strtolower( trim( (string) ( $row->source ?? '' ) ) );
		if ( in_array( $source, array( 'ical', 'ical_import' ), true ) ) {
			return true;
		}

		$email = strtolower( trim( (string) ( $row->customer_email ?? '' ) ) );
		if ( '' === $email ) {
			return true;
		}

		if ( in_array( $email, array( 'guest@pending.local', 'ical@external.local', 'deleted@site.invalid' ), true ) ) {
			return true;
		}

		foreach ( array( '.local', '.import', '.invalid' ) as $synthetic_tld ) {
			if ( str_ends_with( $email, $synthetic_tld ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Authenticate and fetch a booking from request parameters.
	 *
	 * Supports lookup by cryptographically secure booking_token OR
	 * verification by matching Booking ID + Customer Email.
	 *
	 * @param WP_REST_Request $request
	 * @return \stdClass|WP_Error Booking row object or WP_Error on authentication failure.
	 */
	private function authenticate_booking( WP_REST_Request $request ): \stdClass|WP_Error {
		global $wpdb;

		$ip              = Security::get_client_ip();
		$fail_key        = 'mhbo_portal_fails_' . md5( $ip );
		$lookup_fail_key = 'mhbo_portal_lookup_fails_' . md5( $ip );
		$fails           = (int) get_transient( $fail_key );
		if ( $fails >= 10 ) {
			return new WP_Error(
				'mhbo_portal_rate_limit',
				__( 'Too many failed lookup attempts. Please wait 15 minutes before trying again.', 'modern-hotel-booking' ),
				array( 'status' => 429 )
			);
		}

		$token      = (string) $request->get_param( 'token' );
		$booking_id = (int) $request->get_param( 'booking_id' );
		$email      = (string) $request->get_param( 'email' );

		$table = $wpdb->prefix . 'mhbo_bookings';

		// 1. Token-based authentication
		if ( '' !== $token ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE booking_token = %s LIMIT 1',
					$table,
					$token
				)
			);

			if ( $row && hash_equals( (string) $row->booking_token, $token ) ) {
				delete_transient( $fail_key );
				return $row;
			}
		}

		// 2. ID + Email authentication (never cleared by valid token requests)
		$lookup_fails = (int) get_transient( $lookup_fail_key );
		if ( $booking_id > 0 && '' !== $email ) {
			if ( $lookup_fails >= 10 ) {
				return new WP_Error(
					'mhbo_portal_rate_limit',
					__( 'Too many failed lookup attempts. Please wait 15 minutes before trying again.', 'modern-hotel-booking' ),
					array( 'status' => 429 )
				);
			}

			$bk_fail_key = 'mhbo_portal_bk_fails_' . $booking_id;
			$bk_fails    = (int) get_transient( $bk_fail_key );
			if ( $bk_fails >= 5 ) {
				return new WP_Error(
					'mhbo_portal_rate_limit',
					__( 'Too many failed lookup attempts. Please wait 15 minutes before trying again.', 'modern-hotel-booking' ),
					array( 'status' => 429 )
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE id = %d LIMIT 1',
					$table,
					$booking_id
				)
			);

			if ( $row && ! $this->is_placeholder_or_non_guest_booking( $row ) ) {
				$stored_email   = strtolower( trim( (string) $row->customer_email ) );
				$supplied_email = strtolower( trim( $email ) );
				if ( '' !== $stored_email && hash_equals( $stored_email, $supplied_email ) ) {
					return $row;
				}
			}

			set_transient( $bk_fail_key, $bk_fails + 1, 15 * MINUTE_IN_SECONDS );
			set_transient( $lookup_fail_key, $lookup_fails + 1, 15 * MINUTE_IN_SECONDS );
		}

		set_transient( $fail_key, $fails + 1, 15 * MINUTE_IN_SECONDS );

		return new WP_Error(
			'mhbo_portal_auth_failed',
			__( 'Unable to find reservation. Please verify your booking reference and email address.', 'modern-hotel-booking' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Get booking details for guest portal display.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_booking( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = $this->authenticate_booking( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		global $wpdb;

		// Fetch room and room type information with strict multilingual decoding
		$room_id   = (int) $booking->room_id;
		$room_name = __( 'Reserved Room', 'modern-hotel-booking' );
		$type_name = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$room_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.room_number, r.image_url AS room_image_url, t.name AS type_name, t.image_url AS type_image_url 
				 FROM {$wpdb->prefix}mhbo_rooms r 
				 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id 
				 WHERE r.id = %d LIMIT 1",
				$room_id
			)
		);

		$room_image_url = '';
		if ( $room_row ) {
			$raw_type_name = ! empty( $room_row->type_name ) ? (string) $room_row->type_name : '';
			$type_name     = (string) ( I18n::decode( $raw_type_name ) ?? $raw_type_name );
			$room_num      = ! empty( $room_row->room_number ) ? (string) $room_row->room_number : '';
			$room_name     = $type_name ? "{$type_name} (Room {$room_num})" : "Room {$room_num}";

			$raw_img = ! empty( $room_row->room_image_url ) ? (string) $room_row->room_image_url : ( ! empty( $room_row->type_image_url ) ? (string) $room_row->type_image_url : '' );
			if ( '' !== $raw_img ) {
				if ( is_numeric( $raw_img ) ) {
					$img = wp_get_attachment_image_url( (int) $raw_img, 'large' );
					if ( is_string( $img ) && '' !== $img ) {
						$room_image_url = $img;
					}
				} else {
					$room_image_url = esc_url_raw( $raw_img );
				}
			}
		}
		$room_name = (string) ( I18n::decode( $room_name ) ?? $room_name );

		// Calculate stay duration
		$in_ts  = HotelTime::midnight( (string) $booking->check_in );
		$out_ts = HotelTime::midnight( (string) $booking->check_out );
		$nights = max( 1, (int) round( ( $out_ts - $in_ts ) / DAY_IN_SECONDS ) );

		$check_in_time      = HotelTime::get_checkin_time();
		$check_out_time     = HotelTime::get_checkout_time();
		$is_early_checkin   = false;
		$is_late_checkout   = false;
		$is_checkin_shifted = false;
		$is_request_only    = false;

// Currency & amounts
		$currency    = Pricing::get_currency_code();
		$total_money = Money::fromDecimal( (string) ( $booking->total_price ?? 0 ), $currency );
		$paid_money  = Money::fromDecimal( (string) ( $booking->payment_amount ?? 0 ), $currency );

		if ( class_exists( Pricing::class ) && method_exists( Pricing::class, 'get_booking_payment_amounts' ) ) {
			$payment_data    = Pricing::get_booking_payment_amounts( $booking );
			$total_money     = $payment_data['total'];
			$paid_money      = $payment_data['received'];
			$remaining_money = $payment_data['remaining'];
		} else {
			$remaining_money = $total_money->subtract( $paid_money );
			if ( $remaining_money->isNegative() ) {
				$remaining_money = Money::fromCents( 0, $currency );
			}
		}

		$total_val                = (float) $total_money->toDecimal();
		$paid_val                 = (float) $paid_money->toDecimal();
		$is_cancelled_or_refunded = in_array( (string) $booking->status, array( 'cancelled', 'refunded' ), true );
		$remaining_val            = $is_cancelled_or_refunded ? 0.0 : (float) $remaining_money->toDecimal();

		// Cancellation eligibility & policy calculation
		$lead_hours       = max( 0, (int) get_option( 'mhbo_cancellation_lead_hours', 24 ) );
		$policy_text      = self::get_active_cancellation_policy();
		$current_ts       = HotelTime::now()->getTimestamp();
		$cancel_cutoff_ts = $in_ts - ( $lead_hours * 3600 );
		$is_future_stay   = $in_ts > $current_ts;
		$is_within_window = ( 0 === $lead_hours ) ? $is_future_stay : ( $current_ts <= $cancel_cutoff_ts );
		$is_active_status = in_array( (string) $booking->status, array( 'confirmed', 'pending', 'pending_payment' ), true );
		$has_paid         = $paid_val > 0.001;
		$cancel_mode      = self::get_cancel_mode();

		// Hotel info
		$company_info = class_exists( '\\MHBO\\Business\\Info' ) ? \MHBO\Business\Info::get_company() : array();
		$hotel_name   = (string) ( I18n::decode( (string) get_option( 'mhbo_hotel_name', '' ) ) ?? '' );
		if ( '' === $hotel_name ) {
			$hotel_name = ! empty( $company_info['company_name'] ) ? (string) $company_info['company_name'] : get_bloginfo( 'name' );
		}
		$hotel_phone  = (string) get_option( 'mhbo_phone', get_option( 'mhbo_business_phone', '' ) );
		if ( '' === $hotel_phone && ! empty( $company_info['telephone'] ) ) {
			$hotel_phone = (string) $company_info['telephone'];
		}
		$hotel_email  = (string) get_option( 'mhbo_notification_email', '' );
		if ( '' === $hotel_email && ! empty( $company_info['email'] ) ) {
			$hotel_email = (string) $company_info['email'];
		}
		if ( '' === $hotel_email ) {
			$hotel_email = (string) get_option( 'admin_email', '' );
		}

		$contact_info = '' !== $hotel_phone ? $hotel_phone : $hotel_email;

		if ( 'disabled' === $cancel_mode ) {
			$cancellation_requires_staff  = false;
			$cancellation_allowed         = false;
			$cancellation_request_allowed = false;
			$cancellation_mode            = $is_active_status ? 'disabled' : 'closed';
		} elseif ( 'request_only' === $cancel_mode ) {
			$cancellation_requires_staff  = true;
			$cancellation_allowed         = false;
			$cancellation_request_allowed = $is_active_status && $is_future_stay;
			$cancellation_mode            = ! $is_active_status ? 'closed' : ( $is_future_stay ? 'manual_review' : 'window_closed' );
		} else {
			// 'auto_unpaid': funds collected route to staff review; unpaid within cutoff window allow instant cancellation.
			$cancellation_requires_staff  = $has_paid;
			$cancellation_allowed         = ! $has_paid && $is_future_stay && $is_within_window && $is_active_status;
			$cancellation_request_allowed = $has_paid && $is_active_status;

			if ( ! $is_active_status ) {
				$cancellation_mode = 'closed';
			} elseif ( $has_paid ) {
				$cancellation_mode = 'manual_review';
			} elseif ( $cancellation_allowed ) {
				$cancellation_mode = 'immediate';
			} else {
				$cancellation_mode = 'window_closed';
			}
		}

		$formatted_paid = Pricing::format_price( $paid_val );
		$cancellation_notice = '';
		if ( 'disabled' === $cancel_mode ) {
			$disabled_msg = '' !== $contact_info
				? sprintf(
					/* translators: %s: hotel phone or email */
					__( 'Online cancellations are disabled. Please contact our front desk directly at %s for assistance.', 'modern-hotel-booking' ),
					$contact_info
				)
				: __( 'Online cancellations are disabled. Please contact our front desk directly for assistance.', 'modern-hotel-booking' );
			$cancellation_notice = '' !== $policy_text ? ( $policy_text . ' ' . $disabled_msg ) : $disabled_msg;
		} elseif ( $has_paid ) {
			$paid_notice = sprintf(
				/* translators: 1: formatted amount paid, 2: hotel phone/email */
				__( 'A payment of %1$s has been received for this reservation. To ensure accurate refund processing in accordance with our cancellation policy, cancellations must be handled directly with our front desk. Please contact us at %2$s or submit a cancellation request.', 'modern-hotel-booking' ),
				$formatted_paid,
				$contact_info
			);
			$cancellation_notice = '' !== $policy_text ? ( $policy_text . ' ' . $paid_notice ) : $paid_notice;
		} elseif ( 'request_only' === $cancel_mode ) {
			$base_notice = __( 'Cancellations require front desk review before being finalized. Submit a cancellation request below and our team will confirm promptly.', 'modern-hotel-booking' );
			$cancellation_notice = '' !== $policy_text ? ( $policy_text . ' ' . $base_notice ) : $base_notice;
		} elseif ( ! $is_within_window || ! $is_future_stay ) {
			$closed_notice = __( 'The standard cancellation window for this reservation has closed. Please contact the front desk directly for assistance.', 'modern-hotel-booking' );
			$cancellation_notice = '' !== $policy_text ? ( $policy_text . ' ' . $closed_notice ) : $closed_notice;
		} else {
			$cancellation_notice = $policy_text ?: sprintf(
				/* translators: %d: hours */
				__( 'Reservations may be cancelled free of charge up to %d hours prior to check-in.', 'modern-hotel-booking' ),
				$lead_hours
			);
		}

		$req_token     = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$is_token_auth = ( '' !== $req_token && '' !== (string) ( $booking->booking_token ?? '' ) && hash_equals( (string) $booking->booking_token, $req_token ) );

		// Balance link URL if balance due (read-only lookup on GET; new tokens are minted via POST /portal/balance-link)
		$balance_payment_url = '';

// Extras breakdown
		$extras_list = array();
		if ( ! empty( $booking->booking_extras ) ) {
			$decoded = json_decode( (string) $booking->booking_extras, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $item ) {
					if ( is_array( $item ) && isset( $item['name'] ) ) {
						$raw_extra_name = (string) $item['name'];
						$clean_extra    = (string) ( I18n::decode( $raw_extra_name ) ?? $raw_extra_name );
						$extras_list[]  = array(
							'name'  => sanitize_text_field( $clean_extra ),
							'price' => isset( $item['price'] ) ? Pricing::format_price( (float) $item['price'] ) : '',
						);
					}
				}
			}
		}

		// Applied coupon on this booking
		$coupon_code_val           = (string) ( $booking->coupon_code ?? '' );
		$coupon_discount_val       = max( (float) ( $booking->discount_amount ?? 0 ), (float) ( $booking->coupon_discount ?? 0 ) );
		$coupon_discount_formatted = $coupon_discount_val > 0.001 ? Pricing::format_price( $coupon_discount_val ) : '';

		// Available active promotional coupons & vouchers for this guest (Pro)
		$available_coupons = array();
		
		$ai_enabled = class_exists( '\\MHBO\\AI\\Loader' ) && \MHBO\AI\Loader::is_concierge_active();

		$portal_custom_fields = $this->decode_booking_custom_fields( $booking );
		$modify_mode_setting  = self::get_modify_mode();
		$modification_allowed = ( 'disabled' !== $modify_mode_setting )
			&& ! in_array( (string) $booking->status, array( 'cancelled', 'checked_out', 'refunded', 'completed', 'no_show', 'pending_payment' ), true );
		$mod_elig             = self::evaluate_auto_modify_eligibility( $booking, $paid_val, null, $portal_custom_fields );
		$modification_mode    = 'disabled';
		if ( $modification_allowed ) {
			$modification_mode = $mod_elig['eligible'] ? 'auto' : 'staff_review';
		}
		$modification_requires_staff = ! $mod_elig['eligible'];
		$modification_policy_notice  = $mod_elig['reason_message'];
		$modification_count          = (int) ( $portal_custom_fields['_portal_mod_count'] ?? 0 );
		$modification_limit          = self::get_max_modifications();
		$has_pending_cancel_req      = ! empty( $portal_custom_fields['_mhbo_cancellation_requested'] );
		$has_pending_modify_req      = ! empty( $portal_custom_fields['_mhbo_modification_requested'] );

		// 1. Guest Name & First Name Personalization
		$clean_full_name = trim( (string) $booking->customer_name );
		$name_parts      = preg_split( '/\s+/', $clean_full_name );
		$first_name      = ! empty( $name_parts[0] ) ? $name_parts[0] : $clean_full_name;

		$initials = '';
		if ( ! empty( $name_parts ) ) {
			foreach ( $name_parts as $w ) {
				if ( '' !== $w ) {
					$initials .= mb_strtoupper( mb_substr( $w, 0, 1 ) );
				}
				if ( mb_strlen( $initials ) >= 2 ) {
					break;
				}
			}
		}
		if ( '' === $initials ) {
			$initials = 'G';
		}

		// 2. VIP & Loyalty Recognition
		$is_vip          = false;
		$is_repeat_guest = false;
		$stay_count      = 1;

		if ( class_exists( '\\MHBO\\Core\\GuestManager' ) && \MHBO\Core\GuestManager::is_enabled() ) {
			$gid = (int) ( $booking->guest_id ?? 0 );
			if ( $gid <= 0 && ! empty( $booking->customer_email ) ) {
				$active_guests = \MHBO\Core\GuestManager::get_active_guests_by_emails( array( (string) $booking->customer_email ) );
				if ( ! empty( $active_guests ) ) {
					$gid = (int) ( $active_guests[0]->id ?? 0 );
				}
			}
			if ( $gid > 0 ) {
				$guest_record = \MHBO\Core\GuestManager::get_guest( $gid );
				if ( $guest_record ) {
					$is_vip          = ! empty( $guest_record->is_vip ) || ( isset( $guest_record->vip_status ) && 'vip' === $guest_record->vip_status );
					$stay_count      = ! empty( $guest_record->total_stays ) ? (int) $guest_record->total_stays : 1;
					$is_repeat_guest = $stay_count > 1;
				}
			}
		}

		if ( ! $is_repeat_guest && ! empty( $booking->customer_email ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$past_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(id) FROM {$wpdb->prefix}mhbo_bookings 
					 WHERE customer_email = %s 
					   AND status IN ('confirmed', 'checked_out') 
					   AND id != %d",
					(string) $booking->customer_email,
					(int) $booking->id
				)
			);
			if ( $past_count > 0 ) {
				$is_repeat_guest = true;
				$stay_count      = $past_count + 1;
			}
		}

		$loyalty_badge = '';
		if ( $is_vip ) {
			$loyalty_badge = __( 'VIP Guest', 'modern-hotel-booking' );
		} elseif ( $is_repeat_guest ) {
			$loyalty_badge = sprintf(
				/* translators: %d: number of stays */
				__( 'Returning Guest (%d stays)', 'modern-hotel-booking' ),
				$stay_count
			);
		}

		// 3. Time of Day Hospitality Greeting
		$hotel_hour = (int) HotelTime::now()->format( 'G' );
		if ( $hotel_hour >= 5 && $hotel_hour < 12 ) {
			$time_greeting = sprintf(
				/* translators: %s: guest first name */
				__( 'Good morning, %s', 'modern-hotel-booking' ),
				$first_name
			);
		} elseif ( $hotel_hour >= 12 && $hotel_hour < 18 ) {
			$time_greeting = sprintf(
				/* translators: %s: guest first name */
				__( 'Good afternoon, %s', 'modern-hotel-booking' ),
				$first_name
			);
		} else {
			$time_greeting = sprintf(
				/* translators: %s: guest first name */
				__( 'Good evening, %s', 'modern-hotel-booking' ),
				$first_name
			);
		}

		// 4. Stay Stage & Warm Hospitality Message
		$today_str          = HotelTime::today();
		$check_in_str       = (string) $booking->check_in;
		$check_out_str      = (string) $booking->check_out;
		$days_until_checkin = max( 0, (int) round( ( $in_ts - HotelTime::midnight( HotelTime::today() ) ) / DAY_IN_SECONDS ) );

		if ( in_array( (string) $booking->status, array( 'cancelled', 'refunded' ), true ) ) {
			$stay_stage         = 'cancelled';
			$stay_stage_message = __( 'This reservation has been cancelled.', 'modern-hotel-booking' );
		} elseif ( 'checked_out' === (string) $booking->status || $today_str > $check_out_str ) {
			$stay_stage         = 'completed';
			$stay_stage_message = sprintf(
				/* translators: %s: guest first name */
				__( 'Thank you for staying with us, %s! We look forward to welcoming you back.', 'modern-hotel-booking' ),
				$first_name
			);
		} elseif ( $today_str === $check_out_str ) {
			$stay_stage         = 'departing_today';
			$stay_stage_message = sprintf(
				/* translators: 1: guest first name, 2: check-out time */
				__( 'We hope you enjoyed your stay, %1$s! Check-out is scheduled by %2$s today.', 'modern-hotel-booking' ),
				$first_name,
				$check_out_time
			);
		} elseif ( $today_str >= $check_in_str && $today_str < $check_out_str ) {
			$stay_stage         = 'in_house';
			$stay_stage_message = sprintf(
				/* translators: 1: hotel name, 2: guest first name */
				__( 'Welcome to %1$s, %2$s! We hope you are having a wonderful stay.', 'modern-hotel-booking' ),
				$hotel_name,
				$first_name
			);
		} elseif ( $today_str === $check_in_str ) {
			$stay_stage         = 'arriving_today';
			$stay_stage_message = sprintf(
				/* translators: 1: guest first name, 2: check-in time */
				__( 'Welcome, %1$s! Your room will be ready for check-in starting at %2$s today.', 'modern-hotel-booking' ),
				$first_name,
				$check_in_time
			);
		} else {
			$stay_stage = 'upcoming';
			if ( 1 === $days_until_checkin ) {
				$stay_stage_message = sprintf(
					/* translators: 1: hotel name, 2: guest first name */
					__( 'Your stay at %1$s begins tomorrow! We cannot wait to welcome you, %2$s.', 'modern-hotel-booking' ),
					$hotel_name,
					$first_name
				);
			} else {
				$stay_stage_message = sprintf(
					/* translators: 1: number of days, 2: hotel name, 3: guest first name */
					__( 'Your stay begins in %1$d days. We look forward to hosting you at %2$s, %3$s!', 'modern-hotel-booking' ),
					$days_until_checkin,
					$hotel_name,
					$first_name
				);
			}
		}

		// Add line break between greeting and follow-up sentence for hospitality presentation.
		if ( ! empty( $stay_stage_message ) && ! str_contains( $stay_stage_message, "\n" ) ) {
			$stay_stage_message = (string) preg_replace( '/([!?.])\s+/', "$1\n", $stay_stage_message, 1 );
		}

		// 5. Hospitality Direct Actions (WhatsApp, Directions)
		$whatsapp_info       = class_exists( '\\MHBO\\Business\\Info' ) ? \MHBO\Business\Info::get_whatsapp() : array();
		$hotel_address_parts = array_filter(
			array(
				$company_info['address_line_1'] ?? '',
				$company_info['city'] ?? '',
				$company_info['state'] ?? '',
				$company_info['postcode'] ?? '',
				$company_info['country'] ?? '',
			)
		);
		$hotel_address       = implode( ', ', $hotel_address_parts );

		$google_maps_url = '';
		
		if ( '' === $google_maps_url && '' !== $hotel_address ) {
			$google_maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $hotel_name . ' ' . $hotel_address );
		}

		$whatsapp_url = '';
		if ( ! empty( $whatsapp_info['enabled'] ) && ! empty( $whatsapp_info['phone_number'] ) ) {
			$clean_wa_phone = preg_replace( '/[^\d]/', '', (string) $whatsapp_info['phone_number'] );
			if ( '' !== $clean_wa_phone ) {
				$wa_prefill   = sprintf(
					/* translators: 1: guest name, 2: booking ID */
					__( 'Hello, this is %1$s regarding reservation #%2$s.', 'modern-hotel-booking' ),
					$first_name,
					(string) $booking->id
				);
				$whatsapp_url = 'https://wa.me/' . $clean_wa_phone . '?text=' . rawurlencode( $wa_prefill );
			}
		}

		// 6. Property Amenities: Wi-Fi & Guest Compendium (Only disclosed for active confirmed/checked-in stays)
		$is_wifi_eligible = in_array( (string) $booking->status, array( 'confirmed', 'checked_in' ), true );
		$wifi_ssid        = $is_wifi_eligible ? sanitize_text_field( (string) get_option( 'mhbo_hotel_wifi_ssid', '' ) ) : '';
		$wifi_password    = $is_wifi_eligible ? sanitize_text_field( (string) get_option( 'mhbo_hotel_wifi_password', '' ) ) : '';
		$guest_guide      = $is_wifi_eligible ? wp_kses_post( (string) get_option( 'mhbo_hotel_guest_instructions', '' ) ) : '';

		// 7. Formal Tax Invoice Download URL (Starter & Pro)
		$invoice_url = '';

// 8. Communication Log History (Pro & GDPR Audited)
		$email_history = array();

// 9. Deposit & Tax Breakdown Awareness (Law 13 & Law 59)
		$deposit_amount    = 0.0;
		$deposit_formatted = '';
		$is_deposit_paid   = false;
		$tax_amount        = 0.0;
		$tax_formatted     = '';
		$booking_tax_mode  = ( ! empty( $booking->tax_enabled ) && class_exists( Tax::class ) ) ? Tax::normalize_mode( (string) ( $booking->tax_mode ?? '' ) ) : 'disabled';
		$tax_mode          = $booking_tax_mode;
		$tax_label         = ( class_exists( Tax::class ) && 'disabled' !== $tax_mode ) ? Tax::get_display_label( null, $tax_mode ) : I18n::get_label( 'label_tax' );

		if ( isset( $booking->deposit_amount ) ) {
			$deposit_amount    = (float) $booking->deposit_amount;
			$deposit_formatted = Pricing::format_price( $deposit_amount );
		}
		$has_deposit_terms = $deposit_amount > 0.001 || 'deposit' === (string) ( $booking->payment_type ?? '' );
		if ( $has_deposit_terms && ( in_array( (string) $booking->payment_status, array( 'completed', 'deposit_paid', 'paid' ), true )
			|| ( $deposit_amount > 0.001 && $paid_val >= ( $deposit_amount - 0.01 ) ) ) ) {
			$is_deposit_paid = true;
		}

		if ( 'disabled' !== $tax_mode && isset( $booking->total_tax ) && (float) $booking->total_tax > 0.001 ) {
			$tax_amount    = (float) $booking->total_tax;
			$tax_formatted = Pricing::format_price( $tax_amount );
		}
		$portal_tb        = ! empty( $booking->tax_breakdown )
			? ( is_string( $booking->tax_breakdown ) ? json_decode( $booking->tax_breakdown, true ) : (array) $booking->tax_breakdown )
			: array();
		$portal_tb        = is_array( $portal_tb ) ? $portal_tb : array();
		$portal_tb_totals = is_array( $portal_tb['totals'] ?? null ) ? $portal_tb['totals'] : array();
		$portal_tb_rates  = is_array( $portal_tb['rates'] ?? null ) ? $portal_tb['rates'] : array();
		$is_vat_portal    = in_array( $tax_mode, array( 'vat', 'inclusive' ), true );
		$subtotal_net_val = (float) ( $booking->subtotal_net ?? ( $portal_tb_totals['subtotal_net'] ?? 0 ) );
		$accom_tax_val    = (float) ( $portal_tb_totals['accommodation_tax'] ?? ( (float) ( $booking->room_tax ?? 0 ) + (float) ( $booking->children_tax ?? 0 ) ) );
		$extras_tax_val   = (float) ( (float) ( $portal_tb_totals['extras_tax'] ?? ( $booking->extras_tax ?? 0 ) ) + (float) ( $portal_tb_totals['service_fee_tax'] ?? ( $booking->service_fee_tax ?? 0 ) ) );
		$acc_rate_val     = (float) ( $booking->tax_rate_accommodation ?? ( $portal_tb_rates['accommodation'] ?? 0 ) );
		$ext_rate_val     = (float) ( $booking->tax_rate_extras ?? ( $portal_tb_rates['extras'] ?? 0 ) );

		// Group Reservation Metadata (Law 52 & Law 59)
		$is_group_booking      = ! empty( $booking->is_multi_room ) || ! empty( $booking->multi_room_parent );
		$group_parent_ref      = ! empty( $booking->multi_room_parent ) ? (string) $booking->multi_room_parent : (string) $booking->id;
		$group_siblings        = array();
		$group_total_val       = $total_val;
		$group_paid_val        = $paid_val;
		$group_remaining_val   = $remaining_val;
		$group_rooms_count     = 1;
		$group_is_paid         = $remaining_val <= 0.001;
		$first_unpaid_sibling  = null;

		if ( $is_group_booking ) {
			$group_parent_numeric = ctype_digit( $group_parent_ref ) ? (int) $group_parent_ref : 0;
			// Query all sibling rooms in this group reservation with USALI ledger fields.
			// Legacy numeric parent refs may only match the parent row itself when it is a genuine group row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$sibling_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT b.id, b.room_id, b.customer_name, b.customer_email, b.check_in, b.check_out, b.status, b.payment_status, b.payment_type, b.balance_status, b.total_price, b.deposit_amount, b.payment_amount, b.payment_ledger, b.payment_received, b.deposit_received, b.payment_transaction_id, b.remaining_balance, b.balance_link_token, r.room_number, t.name as type_name
					 FROM {$wpdb->prefix}mhbo_bookings b
					 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
					 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
					 WHERE (b.multi_room_parent = %s OR (b.id > 0 AND b.id = %d AND b.is_multi_room = 1))
					   AND b.status NOT IN ('cancelled', 'refunded', 'deleted')
					 ORDER BY b.id ASC",
					$group_parent_ref,
					$group_parent_numeric
				)
			);

			// Object-level authorization: only expose sibling rooms owned by the same guest identity.
			// (The portal sibling switcher authenticates with this booking's email, so other rows are unreachable anyway.)
			$owner_email  = strtolower( trim( (string) $booking->customer_email ) );
			$sibling_rows = array_values(
				array_filter(
					(array) $sibling_rows,
					static fn( $s_row ): bool => is_object( $s_row ) && strtolower( trim( (string) ( $s_row->customer_email ?? '' ) ) ) === $owner_email
				)
			);

			if ( ! empty( $sibling_rows ) ) {
				$group_rooms_count   = count( $sibling_rows );
				$portal_currency     = Pricing::get_currency_code();
				$group_total_m       = Money::fromCents( 0, $portal_currency );
				$group_paid_m        = Money::fromCents( 0, $portal_currency );
				$group_remaining_m   = Money::fromCents( 0, $portal_currency );

				foreach ( $sibling_rows as $s_row ) {
					$s_amts = Pricing::get_booking_payment_amounts( $s_row );
					$s_tot  = (float) $s_amts['total']->toDecimal();
					$s_paid = (float) $s_amts['received']->toDecimal();
					$s_rem  = (float) $s_amts['remaining']->toDecimal();

					$group_total_m     = $group_total_m->add( $s_amts['total'] );
					$group_paid_m      = $group_paid_m->add( $s_amts['received'] );
					$group_remaining_m = $group_remaining_m->add( $s_amts['remaining'] );

					if ( null === $first_unpaid_sibling && $s_amts['remaining']->isPositive() ) {
						$first_unpaid_sibling = $s_row;
					}

					$s_type = ! empty( $s_row->type_name ) ? (string) ( I18n::decode( (string) $s_row->type_name ) ?? $s_row->type_name ) : I18n::get_label( 'label_room' );
					$s_lbl  = ! empty( $s_row->room_number ) ? "{$s_type} #{$s_row->room_number}" : $s_type;

					$group_siblings[] = array(
						'booking_id'        => (int) $s_row->id,
						'room_label'        => $s_lbl,
						'room_name'         => $s_lbl,
						'guest_name'        => (string) $s_row->customer_name,
						'status'            => (string) $s_row->status,
						'total_amount'      => $s_tot,
						'paid_amount'       => $s_paid,
						'remaining_balance' => $s_rem,
						'total_formatted'   => Pricing::format_price( $s_tot ),
						'paid_formatted'    => Pricing::format_price( $s_paid ),
						'balance_formatted' => Pricing::format_price( $s_rem ),
						'is_paid'           => ! $s_amts['remaining']->isPositive(),
						'is_current'        => ( (int) $s_row->id === (int) $booking->id ),
					);
				}

				$group_amounts = Pricing::get_group_payment_amounts( $booking );
				if ( null !== $group_amounts ) {
					$group_rooms_count   = (int) $group_amounts['rooms_count'];
					$group_total_val     = (float) $group_amounts['total']->toDecimal();
					$group_paid_val      = (float) $group_amounts['received']->toDecimal();
					$group_remaining_val = (float) $group_amounts['remaining']->toDecimal();
					$group_is_paid       = (bool) $group_amounts['is_paid'];
				} else {
					$group_total_val     = (float) $group_total_m->toDecimal();
					$group_paid_val      = (float) $group_paid_m->toDecimal();
					$group_remaining_val = (float) $group_remaining_m->toDecimal();
					$group_is_paid       = ! $group_remaining_m->isPositive();
				}
			}
		}

		$is_balance_payable_status = ! in_array( (string) $booking->status, array( 'cancelled', 'refunded', 'pending_payment' ), true );
		$group_balance_payment_url = '';
		$room_balance_payment_url  = '';
		if ( $is_balance_payable_status && '' !== $balance_payment_url ) {
			$room_balance_payment_url = add_query_arg( 'pay_scope', 'room', $balance_payment_url );
			if ( ! $group_is_paid ) {
				$group_balance_payment_url = add_query_arg( 'pay_scope', 'group', $balance_payment_url );
			}
		} elseif ( $is_balance_payable_status && $is_group_booking && ! $group_is_paid ) {
			
		}

		$can_pay_balance       = false;
		$group_can_pay_balance = false;

$attractions = array();

// Strip internal staff/administrative notes and audit entries from guest-facing special requests.
		$guest_special_requests = BookingProcessor::extract_guest_special_requests( $booking );

		$response_data = array(
			'booking_id'                   => (int) $booking->id,
			'auth_method'                  => $is_token_auth ? 'token' : 'credentials',
			'booking_token'                => $is_token_auth ? (string) $booking->booking_token : '',
			'customer_name'                => (string) $booking->customer_name,
			'first_name'                   => $first_name,
			'greeting'                     => $time_greeting,
			'guest_initials'               => $initials,
			'is_vip'                       => $is_vip,
			'is_repeat_guest'              => $is_repeat_guest,
			'loyalty_badge'                => $loyalty_badge,
			'stay_stage'                   => $stay_stage,
			'stay_stage_message'           => $stay_stage_message,
			'days_until_checkin'           => $days_until_checkin,
			'room_image_url'               => $room_image_url,
			'hotel_address'                => $hotel_address,
			'google_maps_url'              => $google_maps_url,
			'whatsapp_url'                 => $whatsapp_url,
			'customer_email'               => (string) $booking->customer_email,
			'customer_phone'               => (string) ( $booking->customer_phone ?? '' ),
			'arrival_time'                 => BookingProcessor::extract_arrival_time( $booking ),
			'special_requests'             => $guest_special_requests,
			'room_id'                      => $room_id,
			'room_name'                    => $room_name,
			'room_type'                    => $type_name,
			'check_in'                     => (string) $booking->check_in,
			'check_out'                    => (string) $booking->check_out,
			'check_in_formatted'           => HotelTime::date( get_option( 'date_format', 'F j, Y' ), $in_ts ),
			'check_out_formatted'          => HotelTime::date( get_option( 'date_format', 'F j, Y' ), $out_ts ),
			'check_in_time'                => $check_in_time,
			'check_out_time'               => $check_out_time,
			'is_early_checkin'             => $is_early_checkin,
			'is_late_checkout'             => $is_late_checkout,
			'is_checkin_shifted'           => $is_checkin_shifted,
			'is_request_only'              => $is_request_only,
			'nights'                       => $nights,
			'guests'                       => (int) ( $booking->guests ?? 1 ),
			'children'                     => (int) ( $booking->children ?? 0 ),
			'status'                       => (string) $booking->status,
			'status_label'                 => $this->get_status_label( (string) $booking->status ),
			'payment_status'               => ( $remaining_val <= 0.001 && ! in_array( (string) ( $booking->status ?? '' ), array( 'cancelled', 'refunded' ), true ) && ! in_array( (string) ( $booking->payment_status ?? '' ), array( 'refunded', 'failed' ), true ) )
				? 'completed'
				: ( ( $paid_val > 0.001 && ! in_array( (string) ( $booking->payment_status ?? '' ), array( 'refunded', 'failed' ), true ) )
					? ( $has_deposit_terms ? 'deposit_paid' : 'partially_paid' )
					: (string) ( $booking->payment_status ?? 'pending' ) ),
			'payment_status_label'         => $this->get_payment_status_label( (string) ( $booking->payment_status ?? 'pending' ), $remaining_val, (string) $booking->status, $paid_val, $deposit_amount, (string) ( $booking->payment_type ?? '' ) ),
			'total_price'                  => $total_val,
			'total_price_formatted'        => Pricing::format_price( $total_val ),
			'total_paid'                   => $paid_val,
			'total_paid_formatted'         => Pricing::format_price( $paid_val ),
			'balance_remaining'            => $remaining_val,
			'balance_remaining_formatted'  => Pricing::format_price( $remaining_val ),
			'balance_payment_url'          => $balance_payment_url,
			'coupon_code'                  => $coupon_code_val,
			'coupon_discount'              => $coupon_discount_val,
			'coupon_discount_formatted'    => $coupon_discount_formatted,
			'available_coupons'            => $available_coupons,
			'extras'                       => $extras_list,
			'cancellation_allowed'         => $cancellation_allowed,
			'cancellation_request_allowed' => $cancellation_request_allowed,
			'cancellation_requires_staff'  => $cancellation_requires_staff,
			'cancellation_mode'            => $cancellation_mode,
			'cancellation_lead_hours'      => $lead_hours,
			'cancellation_deadline'        => HotelTime::date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i A' ), $cancel_cutoff_ts ),
			'cancellation_policy_enabled'  => self::is_cancellation_policy_enabled(),
			'cancellation_policy_notice'   => $cancellation_notice,
			'property_cancellation_policy' => $policy_text,
			'hotel_name'                      => $hotel_name,
			'hotel_phone'                     => $hotel_phone,
			'hotel_email'                     => $hotel_email,
			'modification_allowed'            => $modification_allowed,
			'modification_mode'               => $modification_mode,
			'modification_requires_staff'     => $modification_requires_staff,
			'modification_policy_notice'      => $modification_policy_notice,
			'modification_count'              => $modification_count,
			'modification_limit'              => $modification_limit,
			'has_pending_cancellation_request' => $has_pending_cancel_req,
			'has_pending_modification_request' => $has_pending_modify_req,
			'ai_concierge_enabled'            => $ai_enabled,
			'wifi_ssid'                    => $wifi_ssid,
			'wifi_password'                => $wifi_password,
			'guest_guide'                  => $guest_guide,
			'invoice_url'                  => $invoice_url,
			'email_history'                => $email_history,
			'deposit_amount'               => $deposit_amount,
			'deposit_formatted'            => $deposit_formatted,
			'is_deposit_paid'              => $is_deposit_paid,
			'service_fee_amount'           => (float) ( $booking->service_fee_amount ?? 0 ),
			'service_fee_formatted'        => ( (float) ( $booking->service_fee_amount ?? 0 ) > 0.001 ) ? Pricing::format_price( (float) $booking->service_fee_amount ) : '',
			'service_fee_label'            => (string) get_option( 'mhbo_service_fee_label', __( 'Service Fee', 'modern-hotel-booking' ) ),
			'tax_amount'                   => $tax_amount,
			'tax_formatted'                => $tax_formatted,
			'tax_mode'                     => $tax_mode,
			'tax_label'                    => $tax_label,
			'is_vat'                       => $is_vat_portal,
			'subtotal_net'                 => $subtotal_net_val,
			'subtotal_net_formatted'       => Pricing::format_price( $subtotal_net_val ),
			'accommodation_tax'            => $accom_tax_val,
			'accommodation_tax_formatted'  => Pricing::format_price( $accom_tax_val ),
			'extras_tax'                   => $extras_tax_val,
			'extras_tax_formatted'         => Pricing::format_price( $extras_tax_val ),
			'tax_rate_accommodation'       => class_exists( Tax::class ) ? Tax::format_rate( $acc_rate_val ) : (string) $acc_rate_val,
			'tax_rate_extras'              => class_exists( Tax::class ) ? Tax::format_rate( $ext_rate_val ) : (string) $ext_rate_val,
			'is_group_booking'             => $is_group_booking,
			'group_id'                     => $group_parent_ref,
			'group_parent_ref'             => $group_parent_ref,
			'group_rooms_count'            => $group_rooms_count,
			'group_total_formatted'        => Pricing::format_price( $group_total_val ),
			'group_paid_formatted'         => Pricing::format_price( $group_paid_val ),
			'group_balance_formatted'      => Pricing::format_price( $group_remaining_val ),
			'group_remaining_balance'      => $group_remaining_val,
			'group_is_paid'                => $group_is_paid,
			'group_siblings'               => $group_siblings,
			'group_balance_payment_url'    => $group_balance_payment_url,
			'room_balance_payment_url'     => $room_balance_payment_url,
			'can_pay_balance'              => $can_pay_balance,
			'group_can_pay_balance'        => $group_can_pay_balance,
			'attractions'                  => $attractions,
		);

		return new WP_REST_Response( array( 'success' => true, 'booking' => $response_data ), 200 );
	}

	/**
	 * Process guest self-service reservation cancellation or staff cancellation request.
	 *
	 * When payments have already been collected ($paid > 0), automated cancellation is disallowed.
	 * A cancellation request is recorded and dispatched to front desk staff for refund processing.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_booking( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = $this->authenticate_booking( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$booking_id = (int) $booking->id;
		$status     = (string) $booking->status;

		if ( 'cancelled' === $status ) {
			return new WP_Error(
				'mhbo_already_cancelled',
				__( 'This reservation is already cancelled.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $status, array( 'confirmed', 'pending', 'pending_payment' ), true ) ) {
			return new WP_Error(
				'mhbo_cancellation_invalid_status',
				__( 'This reservation cannot be cancelled in its current status.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		$cancel_mode = self::get_cancel_mode();
		if ( 'disabled' === $cancel_mode ) {
			return new WP_Error(
				'mhbo_cancellation_disabled',
				__( 'Online cancellations are disabled for this property. Please contact the front desk directly.', 'modern-hotel-booking' ),
				array( 'status' => 403 )
			);
		}

		// Calculate live financial amount paid
		$paid_val = (float) ( $booking->payment_amount ?? 0 );
		if ( class_exists( Pricing::class ) && method_exists( Pricing::class, 'get_booking_payment_amounts' ) ) {
			$payment_data = Pricing::get_booking_payment_amounts( $booking );
			$paid_val     = (float) $payment_data['received']->toDecimal();
		}

		$reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );
		$phone  = sanitize_text_field( (string) $request->get_param( 'phone' ) );

		global $wpdb;

		// 1. Paid / Partially Paid Bookings OR Staff-Approval Policy -> Process as Staff Cancellation Request
		if ( $paid_val > 0.001 || 'request_only' === $cancel_mode ) {
			if ( $paid_val <= 0.001 && 'request_only' === $cancel_mode ) {
				$in_ts_req = HotelTime::midnight( (string) $booking->check_in );
				if ( $in_ts_req <= HotelTime::now()->getTimestamp() ) {
					return new WP_Error(
						'mhbo_cancellation_window_closed',
						__( 'The standard cancellation window for this reservation has closed. Please contact the front desk directly for assistance.', 'modern-hotel-booking' ),
						array( 'status' => 400 )
					);
				}
			}

			$cooldown_err = $this->check_staff_request_cooldown( $booking_id, 'cancel' );
			if ( null !== $cooldown_err ) {
				return $cooldown_err;
			}

			$timestamp = HotelTime::now_mysql();
			$header    = $paid_val > 0.001
				? 'Guest Portal Cancellation Request - Payment on File: ' . Pricing::format_price( $paid_val )
				: 'Guest Portal Cancellation Request (Staff Approval Policy)';
			$log_entry = "\n[" . $timestamp . ' ' . $header . "]\n";
			if ( '' !== $reason ) {
				$log_entry .= "Reason: {$reason}\n";
			}
			if ( '' !== $phone ) {
				$log_entry .= "Guest Contact Phone: {$phone}\n";
			}
			$existing_notes = (string) ( $booking->admin_notes ?? '' );
			$new_notes      = trim( $existing_notes . "\n" . $log_entry );

			$custom_fields = $this->decode_booking_custom_fields( $booking );
			$custom_fields['_mhbo_cancellation_requested'] = array(
				'requested_at' => $timestamp,
				'reason'       => $reason,
				'paid'         => $paid_val,
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'mhbo_bookings',
				array(
					'admin_notes'   => $new_notes,
					'custom_fields' => (string) wp_json_encode( $custom_fields ),
					'updated_at'    => $timestamp,
				),
				array( 'id' => $booking_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			$this->set_staff_request_cooldown( $booking_id, 'cancel' );
			Cache::invalidate_booking( $booking_id );

			// Send high-priority alert email to Admin & CC, and acknowledgement to guest
			if ( class_exists( Email::class ) ) {
				Email::send_admin_cancellation_request_notification( $booking_id, $paid_val, $reason );
				Email::send_guest_cancellation_request_email( $booking_id, $paid_val, $reason );
			}

			$response_message = $paid_val > 0.001
				? sprintf(
					/* translators: %s: amount paid */
					__( 'Your cancellation request has been submitted to the front desk. Because a payment of %s has been received, our team will review your booking and contact you regarding any applicable refund.', 'modern-hotel-booking' ),
					Pricing::format_price( $paid_val )
				)
				: __( 'Your cancellation request has been submitted to the front desk. Our team will review your reservation and confirm your cancellation shortly.', 'modern-hotel-booking' );

			return new WP_REST_Response(
				array(
					'success'           => true,
					'cancellation_type' => 'requested',
					'message'           => $response_message,
					'status'            => (string) $booking->status,
				),
				200
			);
		}

		// 2. Unpaid Bookings (auto_unpaid mode) -> Validate cancellation window and execute immediate cancellation
		$lead_hours       = max( 0, (int) get_option( 'mhbo_cancellation_lead_hours', 24 ) );
		$in_ts            = HotelTime::midnight( (string) $booking->check_in );
		$current_ts       = HotelTime::now()->getTimestamp();
		$cancel_cutoff_ts = $in_ts - ( $lead_hours * 3600 );

		if ( $in_ts <= $current_ts || ( $lead_hours > 0 && $current_ts > $cancel_cutoff_ts ) ) {
			return new WP_Error(
				'mhbo_cancellation_window_closed',
				__( 'The standard cancellation window for this reservation has closed. Please contact the front desk directly for assistance.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		$lock_room_id = (int) ( $booking->room_id ?? 0 );
		if ( $lock_room_id > 0 && ! Pricing::acquire_booking_lock( $lock_room_id, 10 ) ) {
			return new WP_Error(
				'mhbo_lock_failed',
				I18n::get_label( 'label_booking_busy' ),
				array( 'status' => 409 )
			);
		}

		try {
			// Re-fetch authoritative booking state inside lock to prevent concurrent payment/cancel races.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$fresh_booking = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE id = %d',
					$wpdb->prefix . 'mhbo_bookings',
					$booking_id
				)
			);
			if ( is_object( $fresh_booking ) ) {
				$booking = $fresh_booking;
				$fresh_paid = (float) ( $booking->payment_amount ?? 0 );
				if ( class_exists( Pricing::class ) && method_exists( Pricing::class, 'get_booking_payment_amounts' ) ) {
					$fresh_payment_data = Pricing::get_booking_payment_amounts( $booking );
					$fresh_paid         = (float) $fresh_payment_data['received']->toDecimal();
				}
				if ( $fresh_paid > 0.001 || in_array( (string) ( $booking->status ?? '' ), array( 'cancelled', 'refunded', 'checked_in', 'checked_out', 'completed', 'no_show' ), true ) ) {
					return new WP_Error(
						'mhbo_cancellation_conflict',
						__( 'Failed to update reservation status. Please try again.', 'modern-hotel-booking' ),
						array( 'status' => 409 )
					);
				}
			}

			$now_mysql     = HotelTime::now_mysql();
			$update_data   = array(
				'status'     => 'cancelled',
				'updated_at' => $now_mysql,
			);
			$update_format = array( '%s', '%s' );
			$cancel_cf     = $this->decode_booking_custom_fields( $booking );
			if ( isset( $cancel_cf['_mhbo_cancellation_requested'] ) || isset( $cancel_cf['_mhbo_modification_requested'] ) ) {
				unset( $cancel_cf['_mhbo_cancellation_requested'], $cancel_cf['_mhbo_modification_requested'] );
				$update_data['custom_fields'] = (string) wp_json_encode( $cancel_cf );
				$update_format[]              = '%s';
			}
			if ( '' !== $reason ) {
				$existing_notes             = (string) ( $booking->admin_notes ?? '' );
				$reason_note                = '[' . $now_mysql . ' Guest Cancellation Reason]: ' . sanitize_text_field( $reason );
				$update_data['admin_notes'] = trim( $existing_notes . ( '' !== $existing_notes ? "\n" : '' ) . $reason_note );
				$update_format[]            = '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$wpdb->prefix . 'mhbo_bookings',
				$update_data,
				array(
					'id'               => $booking_id,
					'status'           => (string) ( $booking->status ?? 'confirmed' ),
					'payment_received' => 0,
					'deposit_received' => 0,
				),
				$update_format,
				array( '%d', '%s', '%d', '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error(
					'mhbo_cancellation_failed',
					__( 'Failed to update reservation status. Please try again.', 'modern-hotel-booking' ),
					array( 'status' => 500 )
				);
			}
			if ( 0 === $updated ) {
				return new WP_Error(
					'mhbo_cancellation_conflict',
					__( 'Failed to update reservation status. Please try again.', 'modern-hotel-booking' ),
					array( 'status' => 409 )
				);
			}
		} finally {
			if ( $lock_room_id > 0 ) {
				Pricing::release_booking_lock( $lock_room_id );
			}
		}

		// Invalidate availability and booking transients
		Cache::invalidate_booking( $booking_id, (int) $booking->room_id );

		// Increment iCal sequence for RFC 5545 sync
		$seq = (int) get_option( 'mhbo_bseq_' . $booking_id, 0 );
		update_option( 'mhbo_bseq_' . $booking_id, $seq + 1, false );

		// Fire booking cancellation actions & send notifications
		do_action( 'mhbo_booking_cancelled', $booking_id );
		if ( class_exists( Email::class ) ) {
			Email::send_admin_cancellation_notification( $booking_id, $reason );
		}

		return new WP_REST_Response(
			array(
				'success'           => true,
				'cancellation_type' => 'immediate',
				'message'           => __( 'Your reservation has been successfully cancelled. A confirmation email has been dispatched to your address.', 'modern-hotel-booking' ),
				'status'            => 'cancelled',
			),
			200
		);
	}

	/**
	 * Process guest modification or special request.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function request_modification( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = $this->authenticate_booking( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$booking_id = (int) $booking->id;
		$status     = (string) $booking->status;

		if ( 'disabled' === self::get_modify_mode() ) {
			return new WP_Error(
				'mhbo_modification_disabled',
				__( 'Online stay modifications are disabled for this property. Please contact the front desk directly.', 'modern-hotel-booking' ),
				array( 'status' => 403 )
			);
		}

		if ( in_array( $status, array( 'cancelled', 'checked_out', 'refunded', 'completed', 'no_show', 'pending_payment' ), true ) ) {
			return new WP_Error(
				'mhbo_modification_invalid_status',
				__( 'Stay modifications cannot be requested for reservations in this status.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		$notes            = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		$requested_dates  = sanitize_text_field( (string) $request->get_param( 'requested_dates' ) );
		$requested_guests = sanitize_text_field( (string) $request->get_param( 'requested_guests' ) );

		if ( '' === trim( $notes ) ) {
			return new WP_Error(
				'mhbo_missing_notes',
				__( 'Please provide details of your modification request.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		$cooldown_err = $this->check_staff_request_cooldown( $booking_id, 'modify' );
		if ( null !== $cooldown_err ) {
			return $cooldown_err;
		}

		global $wpdb;

		// Format request for admin notes log
		$timestamp    = HotelTime::now_mysql();
		$log_entry    = "\n[" . $timestamp . " Guest Portal Modification Request]\n";
		if ( '' !== $requested_dates ) {
			$log_entry .= "Requested Dates: {$requested_dates}\n";
		}
		if ( '' !== $requested_guests ) {
			$log_entry .= "Requested Guests: {$requested_guests}\n";
		}
		$log_entry   .= "Guest Notes: {$notes}\n";

		$existing_notes = (string) ( $booking->admin_notes ?? '' );
		$new_notes      = trim( $existing_notes . "\n" . $log_entry );

		$custom_fields = $this->decode_booking_custom_fields( $booking );
		$custom_fields['_mhbo_modification_requested'] = array(
			'requested_at'     => $timestamp,
			'requested_dates'  => $requested_dates,
			'requested_guests' => $requested_guests,
			'notes'            => $notes,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'mhbo_bookings',
			array(
				'admin_notes'   => $new_notes,
				'custom_fields' => (string) wp_json_encode( $custom_fields ),
				'updated_at'    => $timestamp,
			),
			array( 'id' => $booking_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$this->set_staff_request_cooldown( $booking_id, 'modify' );
		Cache::invalidate_booking( $booking_id );

		// Send alert email to hotel management and confirmation acknowledgement to guest
		if ( class_exists( Email::class ) ) {
			Email::send_admin_modification_request_notification( $booking_id, $requested_dates, $requested_guests, $notes );
			Email::send_guest_modification_request_email( $booking_id, $requested_dates, $requested_guests, $notes );
		}

		return new WP_REST_Response(
			array(
				'success'           => true,
				'modification_type' => 'requested',
				'message'           => __( 'Your modification request has been submitted to the front desk. A team member will review your request and contact you shortly.', 'modern-hotel-booking' ),
				'status'            => $status,
			),
			200
		);
	}

	/**
	 * Update guest profile information (name, phone, email, arrival time, special requests).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = $this->authenticate_booking( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$booking_id = (int) $booking->id;
		if ( in_array( (string) $booking->status, array( 'cancelled', 'refunded', 'checked_out', 'completed', 'no_show' ), true ) ) {
			return new WP_Error(
				'mhbo_profile_closed',
				__( 'Profile details cannot be modified for completed or cancelled reservations.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		$profile_rl_key  = 'mhbo_portal_profile_rl_' . $booking_id;
		$profile_rl_hits = (int) get_transient( $profile_rl_key );
		if ( $profile_rl_hits >= 5 ) {
			return new WP_Error(
				'mhbo_profile_rate_limit',
				__( 'Too many profile updates in a short period. Please wait a few minutes before trying again.', 'modern-hotel-booking' ),
				array( 'status' => 429 )
			);
		}

		$customer_name  = sanitize_text_field( (string) $request->get_param( 'customer_name' ) );
		$customer_phone = sanitize_text_field( (string) $request->get_param( 'customer_phone' ) );
		$customer_email = sanitize_email( (string) $request->get_param( 'customer_email' ) );
		$arrival_time   = sanitize_text_field( (string) $request->get_param( 'arrival_time' ) );
		$special_reqs   = sanitize_textarea_field( (string) $request->get_param( 'special_requests' ) );

		if ( mb_strlen( $customer_name ) < 2 ) {
			return new WP_Error(
				'mhbo_invalid_name',
				__( 'Please provide a valid full name.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}
		if ( ! is_email( $customer_email ) ) {
			return new WP_Error(
				'mhbo_invalid_email',
				__( 'Please provide a valid email address.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}
		if ( mb_strlen( $customer_phone ) < 5 ) {
			return new WP_Error(
				'mhbo_invalid_phone',
				__( 'Please provide a valid phone number.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;
		$old_email     = (string) $booking->customer_email;
		$email_changed = strtolower( trim( $old_email ) ) !== strtolower( trim( $customer_email ) );

		if ( $email_changed ) {
			$req_token = sanitize_text_field( (string) $request->get_param( 'token' ) );
			if ( '' === $req_token || '' === (string) ( $booking->booking_token ?? '' ) || ! hash_equals( (string) $booking->booking_token, $req_token ) ) {
				return new WP_Error(
					'mhbo_email_change_requires_token',
					__( 'Changing the reservation email address requires opening the portal via your secure booking link.', 'modern-hotel-booking' ),
					array( 'status' => 403 )
				);
			}
		}

		$timestamp = HotelTime::now_mysql();
		$audit_msg = sprintf(
			"[%s Guest Portal Profile Update]\nName: %s | Phone: %s | Email: %s%s%s",
			$timestamp,
			$customer_name,
			$customer_phone,
			$customer_email,
			( '' !== $arrival_time ? " | Arrival: {$arrival_time}" : '' ),
			( '' !== $special_reqs ? "\nSpecial Requests: {$special_reqs}" : '' )
		);

		$existing_notes = (string) ( $booking->admin_notes ?? '' );
		$new_notes      = trim( $existing_notes . "\n\n" . $audit_msg );

		// Custom fields update for arrival time
		$custom_fields = array();
		if ( ! empty( $booking->custom_fields ) ) {
			$custom_fields = is_string( $booking->custom_fields ) ? (array) json_decode( $booking->custom_fields, true ) : (array) $booking->custom_fields;
		}
		if ( '' !== $arrival_time ) {
			$custom_fields['_arrival_time'] = $arrival_time;
		}

		$update_data = array(
			'customer_name'  => $customer_name,
			'customer_phone' => $customer_phone,
			'customer_email' => $customer_email,
			'admin_notes'    => $new_notes,
			'custom_fields'  => wp_json_encode( $custom_fields ),
			'updated_at'     => $timestamp,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'mhbo_bookings',
			$update_data,
			array( 'id' => $booking_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		set_transient( $profile_rl_key, $profile_rl_hits + 1, 15 * MINUTE_IN_SECONDS );

		// Synchronize CRM Guest record if GuestManager is available
		if ( class_exists( '\\MHBO\\Core\\GuestManager' ) && \MHBO\Core\GuestManager::is_enabled() ) {
			$gid = (int) ( $booking->guest_id ?? 0 );
			if ( $gid > 0 ) {
				$name_parts = explode( ' ', trim( $customer_name ), 2 );
				$fname      = $name_parts[0];
				$lname      = $name_parts[1] ?? '';
				$crm_update = array(
					'first_name' => $fname,
					'last_name'  => $lname,
					'phone'      => $customer_phone,
				);
				if ( ! $email_changed ) {
					$crm_update['email'] = $customer_email;
				}
				\MHBO\Core\GuestManager::update_guest( $gid, $crm_update );
			}
		}

		Cache::invalidate_booking( $booking_id, (int) $booking->room_id );

		// If email changed, send security notice with Law 58 headers
		if ( $email_changed && class_exists( Email::class ) ) {
			Email::send_email_updated_notification( $booking_id, $old_email, $customer_email );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Your contact details have been successfully updated.', 'modern-hotel-booking' ),
			),
			200
		);
	}

	/**
	 * Validate stay modification request dates, occupancy, stay limits, and CTA/CTD rules.
	 *
	 * @param object $booking       Booking database row.
	 * @param string $new_check_in  Requested YYYY-MM-DD check-in date.
	 * @param string $new_check_out Requested YYYY-MM-DD check-out date.
	 * @return array{room_id: int, guests: int, children: int, nights_new: int}|WP_Error
	 */
	private function validate_stay_modification_request( object $booking, string $new_check_in, string $new_check_out ): array|WP_Error {
		$status = (string) ( $booking->status ?? '' );

		if ( 'disabled' === self::get_modify_mode() ) {
			return new WP_Error(
				'mhbo_modification_disabled',
				__( 'Online stay modifications are disabled for this property. Please contact the front desk directly.', 'modern-hotel-booking' ),
				array( 'status' => 403 )
			);
		}

		if ( in_array( $status, array( 'cancelled', 'checked_out', 'refunded', 'completed', 'no_show', 'pending_payment' ), true ) ) {
			return new WP_Error(
				'mhbo_stay_invalid_status',
				__( 'Stay dates cannot be modified for reservations in this status.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_check_in ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_check_out ) ) {
			return new WP_Error(
				'mhbo_invalid_dates',
				__( 'Please provide valid dates in YYYY-MM-DD format.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		if ( $new_check_out <= $new_check_in ) {
			return new WP_Error(
				'mhbo_invalid_dates',
				__( 'Check-out date must be after check-in date.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		$today = HotelTime::today();
		if ( $new_check_in < $today ) {
			return new WP_Error(
				'mhbo_past_dates',
				__( 'Check-in date cannot be in the past.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		$date_validation = HotelTime::validate_date_range( $new_check_in, $new_check_out, true );
		if ( is_wp_error( $date_validation ) ) {
			return $date_validation;
		}

		$new_guests   = (int) ( $booking->guests ?? 1 );
		$new_children = (int) ( $booking->children ?? 0 );
		$room_id      = (int) $booking->room_id;
		$room_obj     = Pricing::get_room_pricing_data( $room_id );
		if ( $room_obj ) {
			
			if ( $new_guests > (int) $room_obj->max_adults ) {
				return new WP_Error(
					'mhbo_max_adults',
					sprintf( I18n::get_label( 'label_max_adults_error' ), $room_obj->max_adults ),
					array( 'status' => 400 )
				);
			}
		}

		$dt_in  = new \DateTime( $new_check_in );
		$dt_out = new \DateTime( $new_check_out );
		$nights = (int) $dt_in->diff( $dt_out )->format( '%a' );

		$min_stay = Pricing::get_effective_min_stay( $room_id, $new_check_in );
		if ( null !== $min_stay && $nights < $min_stay ) {
			return new WP_Error(
				'mhbo_min_stay',
				sprintf( I18n::get_label( 'api_err_min_stay' ), $min_stay ),
				array( 'status' => 400 )
			);
		}

		$max_stay = null;
		
		if ( null === $max_stay ) {
			$global_max = (int) get_option( 'mhbo_global_max_stay', 0 );
			if ( $global_max > 0 ) {
				$max_stay = $global_max;
			}
		}
		if ( null !== $max_stay && $nights > $max_stay ) {
			return new WP_Error(
				'mhbo_max_stay',
				sprintf( I18n::get_label( 'api_err_max_stay' ), $max_stay ),
				array( 'status' => 400 )
			);
		}

		if ( $new_check_in !== (string) $booking->check_in && Pricing::is_closed_to_arrival( $room_id, $new_check_in ) ) {
			return new WP_Error( 'mhbo_cta_restricted', I18n::get_label( 'api_err_cta' ), array( 'status' => 400 ) );
		}
		if ( $new_check_out !== (string) $booking->check_out && Pricing::is_closed_to_departure( $room_id, $new_check_out ) ) {
			return new WP_Error( 'mhbo_ctd_restricted', I18n::get_label( 'api_err_ctd' ), array( 'status' => 400 ) );
		}

		return array(
			'room_id'    => $room_id,
			'guests'     => $new_guests,
			'children'   => $new_children,
			'nights_new' => $nights,
		);
	}

	/**
	 * Evaluate Pro early check-in / late check-out timing compatibility for new dates without mutating DB.
	 *
	 * @param int                  $room_id       Room ID.
	 * @param string               $new_check_in  Proposed check-in date.
	 * @param string               $new_check_out Proposed check-out date.
	 * @param int                  $booking_id    Booking ID.
	 * @param array<string, mixed> $portal_custom Existing custom_fields map.
	 * @return array<string, mixed> Updated custom_fields map.
	 */
	private function evaluate_stay_timing_for_dates( int $room_id, string $new_check_in, string $new_check_out, int $booking_id, array $portal_custom ): array {
		
		return $portal_custom;
	}

	/**
	 * Delegate a stay date change to front desk staff review.
	 *
	 * @param object                    $booking      Booking row.
	 * @param string                    $new_check_in Proposed check-in date.
	 * @param string                    $new_check_out Proposed check-out date.
	 * @param int                       $new_guests   Adults count.
	 * @param int                       $new_children Children count.
	 * @param string                    $notes        Guest notes.
	 * @param array<string, mixed>|null $recalc_est   Optional recalculated price quote.
	 * @return WP_REST_Response|WP_Error
	 */
	private function delegate_stay_modification_to_staff(
		object $booking,
		string $new_check_in,
		string $new_check_out,
		int $new_guests,
		int $new_children,
		string $notes,
		?array $recalc_est = null
	): WP_REST_Response|WP_Error {
		$booking_id    = (int) $booking->id;
		$date_req_str  = "{$new_check_in} to {$new_check_out}";
		$guest_req_str = sprintf( '%d adults, %d children', $new_guests, $new_children );
		$full_notes    = '' !== trim( $notes ) ? $notes : __( 'Stay modification requested via guest portal.', 'modern-hotel-booking' );

		if ( null !== $recalc_est && isset( $recalc_est['total'] ) && $recalc_est['total'] instanceof Money ) {
			$currency    = Pricing::get_currency_code();
			$old_total_m = Money::fromDecimal( (string) ( $booking->total_price ?? '0' ), $currency );
			$new_total_m = $recalc_est['total'];
			$full_notes .= "\n" . sprintf(
				/* translators: 1: previous formatted total, 2: estimated new formatted total */
				__( 'Estimated Price Quote: %1$s -> %2$s', 'modern-hotel-booking' ),
				Pricing::format_price( (float) $old_total_m->toDecimal() ),
				Pricing::format_price( (float) $new_total_m->toDecimal() )
			);
		}

		$fake_req = new WP_REST_Request( 'POST', '/mhbo/v1/portal/modify' );
		$fake_req->set_param( 'token', (string) ( $booking->booking_token ?? '' ) );
		$fake_req->set_param( 'booking_id', $booking_id );
		$fake_req->set_param( 'email', (string) $booking->customer_email );
		$fake_req->set_param( 'requested_dates', $date_req_str );
		$fake_req->set_param( 'requested_guests', $guest_req_str );
		$fake_req->set_param( 'notes', $full_notes );

		return $this->request_modification( $fake_req );
	}

	/**
	 * Read-only quote & eligibility preview for proposed stay date changes.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview_stay( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = $this->authenticate_booking( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		if ( 'disabled' === self::get_modify_mode() ) {
			return new WP_Error(
				'mhbo_modification_disabled',
				__( 'Online stay date changes are disabled for this property. Please contact the front desk directly.', 'modern-hotel-booking' ),
				array( 'status' => 403 )
			);
		}

		$booking_id = (int) $booking->id;

		$preview_rl_key = 'mhbo_portal_preview_rl_' . $booking_id;
		$preview_hits   = (int) get_transient( $preview_rl_key );
		if ( $preview_hits >= 30 ) {
			return new WP_Error(
				'mhbo_preview_rate_limit',
				__( 'Too many quote preview requests. Please wait a few minutes before trying again.', 'modern-hotel-booking' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $preview_rl_key, $preview_hits + 1, 5 * MINUTE_IN_SECONDS );

		$new_check_in  = sanitize_text_field( (string) ( $request->get_param( 'check_in' ) ?: $request->get_param( 'new_check_in' ) ) );
		$new_check_out = sanitize_text_field( (string) ( $request->get_param( 'check_out' ) ?: $request->get_param( 'new_check_out' ) ) );

		$validated = $this->validate_stay_modification_request( $booking, $new_check_in, $new_check_out );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$room_id      = $validated['room_id'];
		$new_guests   = $validated['guests'];
		$new_children = $validated['children'];
		$nights_new   = $validated['nights_new'];

		$available = Pricing::is_room_available( $room_id, $new_check_in, $new_check_out, $booking_id );
		if ( true !== $available ) {
			$msg = is_string( $available ) ? I18n::get_label( $available ) : __( 'The requested dates are not available for this room. Please choose different dates.', 'modern-hotel-booking' );
			return new WP_Error( 'mhbo_dates_unavailable', $msg, array( 'status' => 400 ) );
		}

		$original_custom = $this->decode_booking_custom_fields( $booking );
		$preview_custom  = $this->evaluate_stay_timing_for_dates( $room_id, $new_check_in, $new_check_out, $booking_id, $original_custom );

		$recalc_fin = Pricing::recalculate_booking_financials( $booking, $room_id, $new_check_in, $new_check_out, $new_guests, $new_children, $preview_custom );

		$paid_val = (float) ( $booking->payment_amount ?? 0 );
		if ( class_exists( Pricing::class ) && method_exists( Pricing::class, 'get_booking_payment_amounts' ) ) {
			$payment_data = Pricing::get_booking_payment_amounts( $booking );
			$paid_val     = (float) $payment_data['received']->toDecimal();
		}

		$eligibility = self::evaluate_auto_modify_eligibility( $booking, $paid_val, $recalc_fin, $original_custom, $preview_custom );

		$currency    = Pricing::get_currency_code();
		$old_total_m = Money::fromDecimal( (string) ( $booking->total_price ?? '0' ), $currency );
		$new_total_m = ( is_array( $recalc_fin ) && isset( $recalc_fin['total'] ) && $recalc_fin['total'] instanceof Money )
			? $recalc_fin['total']
			: $old_total_m;
		$delta_m       = $new_total_m->subtract( $old_total_m );
		$price_changed = ! $new_total_m->equals( $old_total_m );

		$old_total_val = (float) $old_total_m->toDecimal();
		$new_total_val = (float) $new_total_m->toDecimal();
		$delta_val     = (float) $delta_m->toDecimal();

		$delta_formatted = self::format_signed_delta( $delta_m );

		$old_in_ts  = HotelTime::midnight( (string) $booking->check_in );
		$old_out_ts = HotelTime::midnight( (string) $booking->check_out );
		$nights_old = max( 1, (int) round( ( $old_out_ts - $old_in_ts ) / DAY_IN_SECONDS ) );

		$current_status       = (string) $booking->status;
		$current_status_label = $this->get_status_label( $current_status );
		$status_notice        = '';
		if ( 'pending' === $current_status ) {
			$status_notice = __( 'Note: Your reservation is currently Pending Confirmation. Updating your stay dates keeps it pending until confirmed by our property team.', 'modern-hotel-booking' );
		}

		return new WP_REST_Response(
			array(
				'success'               => true,
				'available'             => true,
				'mode'                  => $eligibility['eligible'] ? 'auto' : 'staff_review',
				'review_reason'         => $eligibility['reason_message'],
				'review_code'           => $eligibility['reason_code'],
				'nights_old'            => $nights_old,
				'nights_new'            => $nights_new,
				'old_total'             => $old_total_val,
				'old_total_decimal'     => $old_total_m->toDecimal(),
				'old_total_formatted'   => Pricing::format_price( $old_total_val ),
				'new_total'             => $new_total_val,
				'new_total_decimal'     => $new_total_m->toDecimal(),
				'new_total_formatted'   => Pricing::format_price( $new_total_val ),
				'price_delta'           => $delta_val,
				'price_delta_formatted' => $delta_formatted,
				'price_changed'         => $price_changed,
				'current_status'        => $current_status,
				'current_status_label'  => $current_status_label,
				'status_notice'         => $status_notice,
			),
			200
		);
	}

	/**
	 * Update stay dates and guests.
	 * Enforces policy mode, cutoff window, modification limits, financial/tax/coupon guards,
	 * and explicit guest price-change confirmation before applying updates.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_stay( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = $this->authenticate_booking( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$booking_id    = (int) $booking->id;
		$status        = (string) $booking->status;
		$new_check_in  = sanitize_text_field( (string) ( $request->get_param( 'check_in' ) ?: $request->get_param( 'new_check_in' ) ) );
		$new_check_out = sanitize_text_field( (string) ( $request->get_param( 'check_out' ) ?: $request->get_param( 'new_check_out' ) ) );
		$notes         = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );

		$validated = $this->validate_stay_modification_request( $booking, $new_check_in, $new_check_out );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$room_id      = $validated['room_id'];
		$new_guests   = $validated['guests'];
		$new_children = $validated['children'];

		if ( $new_check_in === (string) $booking->check_in && $new_check_out === (string) $booking->check_out ) {
			if ( '' !== trim( $notes ) ) {
				return $this->delegate_stay_modification_to_staff( $booking, $new_check_in, $new_check_out, $new_guests, $new_children, $notes );
			}
			return new WP_Error(
				'mhbo_no_date_change',
				__( 'Please select different check-in or check-out dates to modify your stay.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		$paid_val = (float) ( $booking->payment_amount ?? 0 );
		if ( class_exists( Pricing::class ) && method_exists( Pricing::class, 'get_booking_payment_amounts' ) ) {
			$payment_data = Pricing::get_booking_payment_amounts( $booking );
			$paid_val     = (float) $payment_data['received']->toDecimal();
		}

		$original_custom = $this->decode_booking_custom_fields( $booking );
		$pre_eligibility = self::evaluate_auto_modify_eligibility( $booking, $paid_val, null, $original_custom );

		// Route to staff review when policy or pre-lock financial/abuse guardrails require it
		if ( ! $pre_eligibility['eligible'] ) {
			$available = Pricing::is_room_available( $room_id, $new_check_in, $new_check_out, $booking_id );
			if ( true !== $available ) {
				$msg = is_string( $available ) ? I18n::get_label( $available ) : __( 'The requested dates are not available for this room. Please choose different dates.', 'modern-hotel-booking' );
				return new WP_Error(
					'mhbo_dates_unavailable',
					$msg,
					array( 'status' => 400 )
				);
			}

			$recalc_est = Pricing::recalculate_booking_financials( $booking, $room_id, $new_check_in, $new_check_out, $new_guests, $new_children, $original_custom );
			return $this->delegate_stay_modification_to_staff( $booking, $new_check_in, $new_check_out, $new_guests, $new_children, $notes, $recalc_est );
		}

		// Law 5: Acquire atomic booking lock before checking availability and mutating dates
		if ( ! Pricing::acquire_booking_lock( $room_id, 10 ) ) {
			return new WP_Error(
				'mhbo_lock_failed',
				I18n::get_label( 'label_booking_busy' ),
				array( 'status' => 409 )
			);
		}

		$route_to_staff_after_lock = false;
		$recalc_fin                = null;
		$old_check_in              = (string) $booking->check_in;
		$old_check_out             = (string) $booking->check_out;
		$old_total_decimal         = '0.00';

		try {
			global $wpdb;
			// Re-fetch authoritative booking state inside lock to prevent concurrent payment/modification races.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$fresh_booking = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE id = %d',
					$wpdb->prefix . 'mhbo_bookings',
					$booking_id
				)
			);
			if ( is_object( $fresh_booking ) ) {
				$booking       = $fresh_booking;
				$status        = (string) ( $booking->status ?? '' );
				$old_check_in  = (string) ( $booking->check_in ?? $old_check_in );
				$old_check_out = (string) ( $booking->check_out ?? $old_check_out );
				if ( in_array( $status, array( 'cancelled', 'refunded', 'checked_in', 'checked_out', 'completed', 'no_show', 'pending_payment' ), true ) ) {
					return new WP_Error(
						'mhbo_modification_invalid_status',
						__( 'Stay modifications cannot be requested for reservations in this status.', 'modern-hotel-booking' ),
						array( 'status' => 409 )
					);
				}
				$paid_val = (float) ( $booking->payment_amount ?? 0 );
				if ( class_exists( Pricing::class ) && method_exists( Pricing::class, 'get_booking_payment_amounts' ) ) {
					$payment_data = Pricing::get_booking_payment_amounts( $booking );
					$paid_val     = (float) $payment_data['received']->toDecimal();
				}
				$original_custom    = $this->decode_booking_custom_fields( $booking );
				$locked_eligibility = self::evaluate_auto_modify_eligibility( $booking, $paid_val, null, $original_custom );
				if ( ! $locked_eligibility['eligible'] ) {
					$available = Pricing::is_room_available( $room_id, $new_check_in, $new_check_out, $booking_id );
					if ( true !== $available ) {
						$msg = is_string( $available ) ? I18n::get_label( $available ) : __( 'The requested dates are not available for this room. Please choose different dates.', 'modern-hotel-booking' );
						return new WP_Error(
							'mhbo_dates_unavailable',
							$msg,
							array( 'status' => 400 )
						);
					}
					$recalc_fin                = Pricing::recalculate_booking_financials( $booking, $room_id, $new_check_in, $new_check_out, $new_guests, $new_children, $original_custom );
					$route_to_staff_after_lock = true;
				}
			}

			if ( ! $route_to_staff_after_lock ) {
				// Room Availability & Turnover / Gap Guard Check
				$available = Pricing::is_room_available( $room_id, $new_check_in, $new_check_out, $booking_id );
				if ( true !== $available ) {
					$msg = is_string( $available ) ? I18n::get_label( $available ) : __( 'The requested dates are not available for this room. Please choose different dates.', 'modern-hotel-booking' );
					return new WP_Error(
						'mhbo_dates_unavailable',
						$msg,
						array( 'status' => 400 )
					);
				}

				$portal_custom = $this->evaluate_stay_timing_for_dates( $room_id, $new_check_in, $new_check_out, $booking_id, $original_custom );

				// Recalculate full stay financials (child ages, extras, coupon discount, taxes, remaining balance).
				$recalc_fin = Pricing::recalculate_booking_financials( $booking, $room_id, $new_check_in, $new_check_out, $new_guests, $new_children, $portal_custom );

				if ( null === $recalc_fin ) {
					return new WP_Error(
						'mhbo_calc_error',
						__( 'Could not recalculate stay price for the selected dates.', 'modern-hotel-booking' ),
						array( 'status' => 400 )
					);
				}

				$post_eligibility = self::evaluate_auto_modify_eligibility( $booking, $paid_val, $recalc_fin, $original_custom, $portal_custom );
				if ( ! $post_eligibility['eligible'] ) {
					$route_to_staff_after_lock = true;
				} else {
					$currency          = Pricing::get_currency_code();
					$old_total_money   = Money::fromDecimal( (string) ( $booking->total_price ?? '0' ), $currency );
					$old_total_decimal = $old_total_money->toDecimal();
					$new_total_money   = $recalc_fin['total'];
					$new_total_val     = (float) $new_total_money->toDecimal();

					if ( ! $new_total_money->equals( $old_total_money ) ) {
						$confirmed_raw = trim( (string) $request->get_param( 'confirmed_total' ) );
						if ( '' === $confirmed_raw || ! is_numeric( $confirmed_raw ) || abs( (float) $confirmed_raw - $new_total_val ) > 0.01 ) {
							$delta_m   = $new_total_money->subtract( $old_total_money );
							$delta_val = (float) $delta_m->toDecimal();
							return new WP_Error(
								'mhbo_price_confirmation_required',
								sprintf(
									/* translators: 1: previous formatted total, 2: new formatted total */
									__( 'The reservation total changes from %1$s to %2$s for the selected dates. Please review and confirm the updated total to proceed.', 'modern-hotel-booking' ),
									Pricing::format_price( (float) $old_total_money->toDecimal() ),
									Pricing::format_price( $new_total_val )
								),
								array(
									'status'                => 409,
									'old_total'             => (float) $old_total_money->toDecimal(),
									'new_total'             => $new_total_val,
									'new_total_decimal'     => $new_total_money->toDecimal(),
									'old_total_formatted'   => Pricing::format_price( (float) $old_total_money->toDecimal() ),
									'new_total_formatted'   => Pricing::format_price( $new_total_val ),
									'price_delta'           => $delta_val,
									'price_delta_formatted' => self::format_signed_delta( $delta_m ),
								)
							);
						}
					}

					$timestamp                            = HotelTime::now_mysql();
					unset( $portal_custom['_mhbo_modification_requested'] );
					$portal_custom['_portal_mod_count']   = (int) ( $original_custom['_portal_mod_count'] ?? 0 ) + 1;
					$portal_custom['_portal_last_mod_at'] = $timestamp;

					$audit_msg = sprintf(
						"[%s Guest Portal Stay Date Update]\nDates changed from %s to %s -> %s to %s. Total: %s -> %s",
						$timestamp,
						$old_check_in,
						$old_check_out,
						$new_check_in,
						$new_check_out,
						Pricing::format_price( (float) $old_total_money->toDecimal() ),
						Pricing::format_price( $new_total_val )
					);

					$existing_notes = (string) ( $booking->admin_notes ?? '' );
					$new_notes      = trim( $existing_notes . "\n\n" . $audit_msg );

					$update_db_fields = $recalc_fin['db_fields'];
					if ( isset( $update_db_fields['remaining_balance'] ) && ! empty( $booking->balance_link_token ) ) {
						$update_db_fields['balance_expected_amount'] = $update_db_fields['remaining_balance'];
					}

					$update_cols = array_merge(
						array(
							'check_in'      => $new_check_in,
							'check_out'     => $new_check_out,
							'guests'        => $new_guests,
							'children'      => $new_children,
							'custom_fields' => (string) wp_json_encode( $portal_custom ),
						),
						$update_db_fields,
						array(
							'admin_notes' => $new_notes,
							'updated_at'  => $timestamp,
						)
					);
					$update_fmts = array_merge(
						array( '%s', '%s', '%d', '%d', '%s' ),
						array_fill( 0, count( $update_db_fields ), '%s' ),
						array( '%s', '%s' )
					);

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$wpdb->prefix . 'mhbo_bookings',
						$update_cols,
						array( 'id' => $booking_id ),
						$update_fmts,
						array( '%d' )
					);
				}
			}
		} finally {
			Pricing::release_booking_lock( $room_id );
		}

		if ( $route_to_staff_after_lock ) {
			return $this->delegate_stay_modification_to_staff( $booking, $new_check_in, $new_check_out, $new_guests, $new_children, $notes, $recalc_fin );
		}

Cache::invalidate_booking( $booking_id, $room_id );
		Cache::invalidate_date_range( $old_check_in, $old_check_out, $room_id );
		Cache::invalidate_date_range( $new_check_in, $new_check_out, $room_id );

		// Fire booking modification action & increment iCal sequence
		$seq = (int) get_option( 'mhbo_bseq_' . $booking_id, 0 );
		update_option( 'mhbo_bseq_' . $booking_id, $seq + 1, false );
		do_action( 'mhbo_booking_dates_updated', $booking_id, $new_check_in, $new_check_out );

		// Dispatch stay update email to guest and property staff
		if ( class_exists( Email::class ) ) {
			Email::send_stay_dates_updated_email( $booking_id, $old_check_in, $old_check_out, $old_total_decimal );
		}

		$success_msg = 'pending' === $status
			? __( 'Your requested stay dates have been updated. Your reservation remains Pending Confirmation until approved by our property team.', 'modern-hotel-booking' )
			: __( 'Your stay dates have been updated successfully.', 'modern-hotel-booking' );

		return new WP_REST_Response(
			array(
				'success'           => true,
				'modification_type' => 'immediate',
				'status'            => $status,
				'message'           => $success_msg,
			),
			200
		);
	}

/**
	 * Translate booking status to human-readable label.
	 *
	 * @param string $status
	 * @return string
	 */
	private function get_status_label( string $status ): string {
		return match ( $status ) {
			'confirmed'       => __( 'Confirmed', 'modern-hotel-booking' ),
			'pending'         => __( 'Pending Confirmation', 'modern-hotel-booking' ),
			'pending_payment' => __( 'Awaiting Payment', 'modern-hotel-booking' ),
			'checked_in'      => __( 'Checked In', 'modern-hotel-booking' ),
			'checked_out'     => __( 'Checked Out', 'modern-hotel-booking' ),
			'cancelled'       => __( 'Cancelled', 'modern-hotel-booking' ),
			'refunded'        => __( 'Refunded', 'modern-hotel-booking' ),
			'no_show'         => __( 'No-Show', 'modern-hotel-booking' ),
			'completed'       => __( 'Checked Out', 'modern-hotel-booking' ),
			default           => ucfirst( $status ),
		};
	}

	/**
	 * Translate payment status to human-readable label.
	 *
	 * @param string $payment_status    Stored payment status.
	 * @param float  $remaining_balance Guest-facing remaining balance.
	 * @param string $booking_status    Booking lifecycle status.
	 * @param float  $total_paid        Net verified amount paid.
	 * @param float  $deposit_amount    Configured deposit amount.
	 * @param string $payment_type      Booking payment type ('full' or 'deposit').
	 * @return string
	 */
	private function get_payment_status_label( string $payment_status, float $remaining_balance, string $booking_status = '', float $total_paid = 0.0, float $deposit_amount = 0.0, string $payment_type = '' ): string {
		if ( 'refunded' === $booking_status || 'refunded' === $payment_status ) {
			return __( 'Refunded', 'modern-hotel-booking' );
		}

		if ( 'cancelled' === $booking_status ) {
			if ( $total_paid > 0.001 ) {
				return __( 'Payment on File', 'modern-hotel-booking' );
			}
			return __( 'No Balance Due', 'modern-hotel-booking' );
		}

		if ( $remaining_balance <= 0.001 ) {
			return __( 'Paid in Full', 'modern-hotel-booking' );
		}

		$has_deposit_terms = $deposit_amount > 0.001 || 'deposit' === $payment_type;
		if ( 'deposit_paid' === $payment_status && $has_deposit_terms ) {
			return __( 'Deposit Paid (Balance Due)', 'modern-hotel-booking' );
		}

		if ( 'partially_paid' === $payment_status || 'deposit_paid' === $payment_status || $total_paid > 0.001 ) {
			return __( 'Partially Paid (Balance Due)', 'modern-hotel-booking' );
		}

		return __( 'Payment Due', 'modern-hotel-booking' );
	}
}
