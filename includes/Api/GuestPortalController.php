<?php declare(strict_types=1);

namespace MHBO\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
				),
			)
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
		$override_url = (string) get_option( 'mhbo_guest_portal_url', '' );
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
				$status = get_post_status( $page_id );
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

		$ip       = Security::get_client_ip();
		$fail_key = 'mhbo_portal_fails_' . md5( $ip );
		$fails    = (int) get_transient( $fail_key );
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

		// 2. ID + Email authentication
		if ( $booking_id > 0 && '' !== $email ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE id = %d LIMIT 1',
					$table,
					$booking_id
				)
			);

			if ( $row && strtolower( trim( (string) $row->customer_email ) ) === strtolower( trim( $email ) ) ) {
				delete_transient( $fail_key );
				return $row;
			}
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

		$check_in_time  = (string) get_option( 'mhbo_checkin_time', '14:00' );
		$check_out_time = (string) get_option( 'mhbo_checkout_time', '11:00' );

// Currency & amounts
		$currency    = Pricing::get_currency_code();
		$total_money = Money::fromDecimal( (string) ( $booking->total_price ?? 0 ), $currency );
		$paid_money  = Money::fromDecimal( (string) ( $booking->payment_amount ?? 0 ), $currency );

		if ( class_exists( '\\MHBO\\Core\\Pricing' ) && method_exists( '\\MHBO\\Core\\Pricing', 'get_booking_payment_amounts' ) ) {
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

		$total_val     = (float) $total_money->toDecimal();
		$paid_val      = (float) $paid_money->toDecimal();
		$remaining_val = (float) $remaining_money->toDecimal();

		// Cancellation eligibility & policy calculation
		$lead_hours       = (int) get_option( 'mhbo_cancellation_lead_hours', 24 );
		$policy_text      = (string) ( I18n::decode( (string) get_option( 'mhbo_cancellation_policy', '' ) ) ?? '' );
		$current_ts       = HotelTime::now()->getTimestamp();
		$cancel_cutoff_ts = $in_ts - ( $lead_hours * 3600 );
		$is_future_stay   = $in_ts > $current_ts;
		$is_within_window = $current_ts <= $cancel_cutoff_ts;
		$is_active_status = in_array( (string) $booking->status, array( 'confirmed', 'pending', 'pending_payment' ), true );
		$has_paid         = $paid_val > 0.001;

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

		// If funds have been collected, direct self-cancellation is disallowed.
		// A cancellation request must be submitted to the front desk / hotel team for policy & refund processing.
		$cancellation_requires_staff  = $has_paid;
		$cancellation_allowed         = ! $has_paid && $is_future_stay && $is_within_window && $is_active_status;
		$cancellation_request_allowed = $has_paid && $is_active_status;

		$cancellation_mode = 'none';
		if ( ! $is_active_status ) {
			$cancellation_mode = 'closed';
		} elseif ( $has_paid ) {
			$cancellation_mode = 'manual_review';
		} elseif ( $cancellation_allowed ) {
			$cancellation_mode = 'immediate';
		} else {
			$cancellation_mode = 'window_closed';
		}

		$formatted_paid = Pricing::format_price( $paid_val );
		$cancellation_notice = '';
		if ( $has_paid ) {
			$contact_info = '' !== $hotel_phone ? $hotel_phone : $hotel_email;
			$cancellation_notice = sprintf(
				/* translators: 1: formatted amount paid, 2: hotel phone/email */
				__( 'A payment of %1$s has been received for this reservation. To ensure accurate refund processing in accordance with our cancellation policy, cancellations must be handled directly with our front desk. Please contact us at %2$s or submit a cancellation request.', 'modern-hotel-booking' ),
				$formatted_paid,
				$contact_info
			);
		} elseif ( ! $is_within_window || ! $is_future_stay ) {
			$cancellation_notice = __( 'The standard cancellation window for this reservation has closed. Please contact the front desk directly for assistance.', 'modern-hotel-booking' );
		} else {
			$cancellation_notice = $policy_text ?: sprintf(
				/* translators: %d: hours */
				__( 'Reservations may be cancelled free of charge up to %d hours prior to check-in.', 'modern-hotel-booking' ),
				$lead_hours
			);
		}

		// Balance link URL if balance due (Commercial Pro feature: online balance settlements require Pro gateways)
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
		
		$ai_enabled = false;

$modification_allowed = ! in_array( (string) $booking->status, array( 'cancelled', 'checked_out', 'refunded' ), true );

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

		// 6. Property Amenities: Wi-Fi & Guest Compendium (Conditional to hotel configuration)
		$wifi_ssid     = sanitize_text_field( (string) get_option( 'mhbo_hotel_wifi_ssid', '' ) );
		$wifi_password = sanitize_text_field( (string) get_option( 'mhbo_hotel_wifi_password', '' ) );
		$guest_guide   = wp_kses_post( (string) get_option( 'mhbo_hotel_guest_instructions', '' ) );

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
		if ( in_array( (string) $booking->payment_status, array( 'completed', 'deposit_paid', 'paid' ), true )
			|| ( $deposit_amount > 0.001 && $paid_val >= ( $deposit_amount - 0.01 ) ) ) {
			$is_deposit_paid = true;
		}

		if ( 'disabled' !== $tax_mode && isset( $booking->total_tax ) && (float) $booking->total_tax > 0.001 ) {
			$tax_amount    = (float) $booking->total_tax;
			$tax_formatted = Pricing::format_price( $tax_amount );
		}

		// Group Reservation Metadata (Law 52 & Law 59)
		$is_group_booking    = ! empty( $booking->is_multi_room ) || ! empty( $booking->multi_room_parent );
		$group_parent_ref    = ! empty( $booking->multi_room_parent ) ? (string) $booking->multi_room_parent : (string) $booking->id;
		$group_siblings      = array();
		$group_total_val     = $total_val;
		$group_paid_val      = $paid_val;
		$group_remaining_val = $remaining_val;
		$group_rooms_count   = 1;
		$group_is_paid       = $remaining_val <= 0.001;

		if ( $is_group_booking ) {
			// Query all sibling rooms in this group reservation
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$sibling_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT b.id, b.booking_token, b.room_id, b.customer_name, b.check_in, b.check_out, b.status, b.payment_status, b.total_price, b.payment_amount, b.balance_link_token, r.room_number, t.name as type_name
					 FROM {$wpdb->prefix}mhbo_bookings b
					 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
					 LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
					 WHERE (b.multi_room_parent = %s OR b.id = %d)
					   AND b.status != 'cancelled'
					 ORDER BY b.id ASC",
					$group_parent_ref,
					(int) $group_parent_ref
				)
			);

			if ( ! empty( $sibling_rows ) ) {
				$group_rooms_count   = count( $sibling_rows );
				$group_total_sum     = 0.0;
				$group_paid_sum      = 0.0;
				$group_remaining_sum = 0.0;

				foreach ( $sibling_rows as $s_row ) {
					$s_tot  = (float) ( $s_row->total_price ?? 0 );
					$s_paid = (float) ( $s_row->payment_amount ?? 0 );
					$s_rem  = max( 0.0, $s_tot - $s_paid );

					$group_total_sum     += $s_tot;
					$group_paid_sum      += $s_paid;
					$group_remaining_sum += $s_rem;

					$s_type = ! empty( $s_row->type_name ) ? (string) ( I18n::decode( (string) $s_row->type_name ) ?? $s_row->type_name ) : I18n::get_label( 'label_room' );
					$s_lbl  = ! empty( $s_row->room_number ) ? "{$s_type} #{$s_row->room_number}" : $s_type;

					$group_siblings[] = array(
						'booking_id'        => (int) $s_row->id,
						'booking_token'     => (string) $s_row->booking_token,
						'room_label'        => $s_lbl,
						'room_name'         => $s_lbl,
						'guest_name'        => (string) $s_row->customer_name,
						'status'            => (string) $s_row->status,
						'total_formatted'   => Pricing::format_price( $s_tot ),
						'paid_formatted'    => Pricing::format_price( $s_paid ),
						'balance_formatted' => Pricing::format_price( $s_rem ),
						'is_paid'           => $s_rem <= 0.001,
						'is_current'        => ( (int) $s_row->id === (int) $booking->id ),
					);
				}

				$group_total_val     = $group_total_sum;
				$group_paid_val      = $group_paid_sum;
				$group_remaining_val = $group_remaining_sum;
				$group_is_paid       = $group_remaining_sum <= 0.001;
			}
		}

		$group_balance_payment_url = '';
		$room_balance_payment_url  = '';
		if ( '' !== $balance_payment_url ) {
			$group_balance_payment_url = add_query_arg( 'pay_scope', 'group', $balance_payment_url );
			$room_balance_payment_url  = add_query_arg( 'pay_scope', 'room', $balance_payment_url );
		}

		$attractions = array();

// Strip internal staff/administrative notes and audit entries from guest-facing special requests
		$raw_special_requests    = (string) ( $booking->admin_notes ?? '' );
		$filtered_requests_lines = array();
		foreach ( explode( "\n", $raw_special_requests ) as $req_line ) {
			$trimmed_req_line = trim( $req_line );
			if ( preg_match( '/^\[(Staff|Internal|Private|Admin|Guest Cancellation|Guest Modification|AI Assistant Cancellation)/i', $trimmed_req_line ) ) {
				continue;
			}
			if ( false !== stripos( $trimmed_req_line, 'Manually added by administrator' ) ) {
				continue;
			}
			$filtered_requests_lines[] = $req_line;
		}
		$guest_special_requests = trim( implode( "\n", $filtered_requests_lines ) );

		$response_data = array(
			'booking_id'                   => (int) $booking->id,
			'booking_token'                => (string) $booking->booking_token,
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
			'arrival_time'                 => ! empty( $portal_custom_data['_arrival_time'] ) ? (string) $portal_custom_data['_arrival_time'] : (string) ( $booking->arrival_time ?? '' ),
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
			'nights'                       => $nights,
			'guests'                       => (int) ( $booking->guests ?? 1 ),
			'children'                     => (int) ( $booking->children ?? 0 ),
			'status'                       => (string) $booking->status,
			'status_label'                 => $this->get_status_label( (string) $booking->status ),
			'payment_status'               => (string) ( $booking->payment_status ?? 'pending' ),
			'payment_status_label'         => $this->get_payment_status_label( (string) ( $booking->payment_status ?? 'pending' ), $remaining_val ),
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
			'cancellation_policy_notice'   => $cancellation_notice,
			'hotel_name'                   => $hotel_name,
			'hotel_phone'                  => $hotel_phone,
			'hotel_email'                  => $hotel_email,
			'modification_allowed'         => $modification_allowed,
			'ai_concierge_enabled'         => $ai_enabled,
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
			'is_group_booking'             => $is_group_booking,
			'group_id'                     => $group_parent_ref,
			'group_parent_ref'             => $group_parent_ref,
			'group_rooms_count'            => $group_rooms_count,
			'group_total_formatted'        => Pricing::format_price( $group_total_val ),
			'group_paid_formatted'         => Pricing::format_price( $group_paid_val ),
			'group_balance_formatted'      => Pricing::format_price( $group_remaining_val ),
			'group_is_paid'                => $group_is_paid,
			'group_siblings'               => $group_siblings,
			'group_balance_payment_url'    => $group_balance_payment_url,
			'room_balance_payment_url'     => $room_balance_payment_url,
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

		// Calculate live financial amount paid
		$paid_val = (float) ( $booking->payment_amount ?? 0 );
		if ( class_exists( '\\MHBO\\Core\\Pricing' ) && method_exists( '\\MHBO\\Core\\Pricing', 'get_booking_payment_amounts' ) ) {
			$payment_data = Pricing::get_booking_payment_amounts( $booking );
			$paid_val     = (float) $payment_data['received']->toDecimal();
		}

		$reason = (string) $request->get_param( 'reason' );
		$phone  = (string) $request->get_param( 'phone' );

		global $wpdb;

		// 1. Paid / Partially Paid Bookings -> Process as Staff Cancellation Request
		if ( $paid_val > 0.001 ) {
			$timestamp = current_time( 'mysql' );
			$log_entry = "\n[" . $timestamp . ' Guest Portal Cancellation Request - Payment on File: ' . Pricing::format_price( $paid_val ) . "]\n";
			if ( '' !== $reason ) {
				$log_entry .= "Reason: {$reason}\n";
			}
			if ( '' !== $phone ) {
				$log_entry .= "Guest Contact Phone: {$phone}\n";
			}
			$existing_notes = (string) ( $booking->admin_notes ?? '' );
			$new_notes      = trim( $existing_notes . "\n" . $log_entry );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'mhbo_bookings',
				array(
					'admin_notes' => $new_notes,
					'updated_at'  => $timestamp,
				),
				array( 'id' => $booking_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			Cache::invalidate_booking( $booking_id );

			// Send high-priority alert email to Admin & CC, and acknowledgement to guest
			if ( class_exists( '\\MHBO\\Core\\Email' ) ) {
				Email::send_admin_cancellation_request_notification( $booking_id, $paid_val, $reason );
				Email::send_guest_cancellation_request_email( $booking_id, $paid_val, $reason );
			}

			return new WP_REST_Response(
				array(
					'success'           => true,
					'cancellation_type' => 'requested',
					'message'           => sprintf(
						/* translators: %s: amount paid */
						__( 'Your cancellation request has been submitted to the front desk. Because a payment of %s has been received, our team will review your booking and contact you regarding any applicable refund.', 'modern-hotel-booking' ),
						Pricing::format_price( $paid_val )
					),
					'status'            => (string) $booking->status,
				),
				200
			);
		}

		// 2. Unpaid Bookings -> Validate cancellation window and execute immediate cancellation
		$lead_hours       = (int) get_option( 'mhbo_cancellation_lead_hours', 24 );
		$in_ts            = HotelTime::midnight( (string) $booking->check_in );
		$current_ts       = HotelTime::now()->getTimestamp();
		$cancel_cutoff_ts = $in_ts - ( $lead_hours * 3600 );

		if ( $current_ts > $cancel_cutoff_ts ) {
			return new WP_Error(
				'mhbo_cancellation_window_closed',
				__( 'The standard cancellation window for this reservation has closed. Please contact the front desk directly for assistance.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'mhbo_bookings',
			array(
				'status'     => 'cancelled',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'mhbo_cancellation_failed',
				__( 'Failed to update reservation status. Please try again.', 'modern-hotel-booking' ),
				array( 'status' => 500 )
			);
		}

		if ( '' !== $reason ) {
			update_post_meta( $booking_id, '_mhbo_cancellation_reason', sanitize_text_field( $reason ) );
		}

		// Invalidate availability and booking transients
		Cache::invalidate_booking( $booking_id, (int) $booking->room_id );

		// Increment iCal sequence for RFC 5545 sync
		$seq = (int) get_post_meta( $booking_id, '_mhbo_ical_sequence', true );
		update_post_meta( $booking_id, '_mhbo_ical_sequence', $seq + 1 );

		// Fire booking cancellation actions & send notifications
		do_action( 'mhbo_booking_cancelled', $booking_id );
		if ( class_exists( '\\MHBO\\Core\\Email' ) ) {
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

		$booking_id       = (int) $booking->id;
		$status           = (string) $booking->status;

		if ( in_array( $status, array( 'cancelled', 'checked_out', 'refunded' ), true ) ) {
			return new WP_Error(
				'mhbo_modification_invalid_status',
				__( 'Stay modifications cannot be requested for cancelled, refunded, or completed reservations.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		$notes            = (string) $request->get_param( 'notes' );
		$requested_dates  = (string) $request->get_param( 'requested_dates' );
		$requested_guests = (string) $request->get_param( 'requested_guests' );

		if ( '' === trim( $notes ) ) {
			return new WP_Error(
				'mhbo_missing_notes',
				__( 'Please provide details of your modification request.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;

		// Format request for admin notes log
		$timestamp    = current_time( 'mysql' );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'mhbo_bookings',
			array(
				'admin_notes' => $new_notes,
				'updated_at'  => $timestamp,
			),
			array( 'id' => $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		Cache::invalidate_booking( $booking_id );

		// Send alert email to hotel management and confirmation acknowledgement to guest
		if ( class_exists( Email::class ) ) {
			Email::send_admin_modification_request_notification( $booking_id, $requested_dates, $requested_guests, $notes );
			Email::send_guest_modification_request_email( $booking_id, $requested_dates, $requested_guests, $notes );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Your modification request has been submitted to the front desk. A team member will review your request and contact you shortly.', 'modern-hotel-booking' ),
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
		if ( in_array( (string) $booking->status, array( 'cancelled', 'refunded' ), true ) ) {
			return new WP_Error(
				'mhbo_profile_closed',
				__( 'Profile details cannot be modified for cancelled reservations.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
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

		$timestamp = current_time( 'mysql' );
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

		// Synchronize CRM Guest record if GuestManager is available
		if ( class_exists( '\\MHBO\\Core\\GuestManager' ) && \MHBO\Core\GuestManager::is_enabled() ) {
			$gid = (int) ( $booking->guest_id ?? 0 );
			if ( $gid > 0 ) {
				$name_parts = explode( ' ', trim( $customer_name ), 2 );
				$fname      = $name_parts[0];
				$lname      = $name_parts[1] ?? '';
				\MHBO\Core\GuestManager::update_guest(
					$gid,
					array(
						'first_name' => $fname,
						'last_name'  => $lname,
						'phone'      => $customer_phone,
						'email'      => $customer_email,
					)
				);
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
	 * Update stay dates and guests.
	 * If stay is unpaid ($paid <= 0.001), modifies dates directly after availability check.
	 * If stay has payments ($paid > 0.001), routes to front-desk staff review to protect financial integrity.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_stay( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = $this->authenticate_booking( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$booking_id = (int) $booking->id;
		$status     = (string) $booking->status;

		if ( in_array( $status, array( 'cancelled', 'checked_out', 'refunded' ), true ) ) {
			return new WP_Error(
				'mhbo_stay_invalid_status',
				__( 'Stay dates cannot be modified for cancelled or completed reservations.', 'modern-hotel-booking' ),
				array( 'status' => 400 )
			);
		}

		$paid_val = (float) ( $booking->payment_amount ?? 0 );
		if ( class_exists( '\\MHBO\\Core\\Pricing' ) && method_exists( '\\MHBO\\Core\\Pricing', 'get_booking_payment_amounts' ) ) {
			$payment_data = Pricing::get_booking_payment_amounts( $booking );
			$paid_val     = (float) $payment_data['received']->toDecimal();
		}

		$new_check_in  = sanitize_text_field( (string) ( $request->get_param( 'check_in' ) ?: $request->get_param( 'new_check_in' ) ) );
		$new_check_out = sanitize_text_field( (string) ( $request->get_param( 'check_out' ) ?: $request->get_param( 'new_check_out' ) ) );
		$notes         = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );

		// Date format verification
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

		// Advance booking window check
		if ( ! HotelTime::is_within_advance_booking_window( $new_check_in ) ) {
			$max_window_human = HotelTime::format_advance_window_human( HotelTime::get_max_advance_booking_days() );
			return new WP_Error(
				'mhbo_advance_limit_exceeded',
				sprintf( I18n::get_label( 'label_advance_limit_exceeded' ), $max_window_human ),
				array( 'status' => 400 )
			);
		}

		// Guest occupancy strictly remains the same as confirmed reservation
		$new_guests   = (int) $booking->guests;
		$new_children = (int) ( $booking->children ?? 0 );

		$room_id  = (int) $booking->room_id;
		$room_obj = Pricing::get_room_pricing_data( $room_id );
		if ( $room_obj ) {
			
			if ( $new_guests > (int) $room_obj->max_adults ) {
				return new WP_Error(
					'mhbo_max_adults',
					sprintf( I18n::get_label( 'label_max_adults_error' ), $room_obj->max_adults ),
					array( 'status' => 400 )
				);
			}
		}

		// Stay Restrictions (Min Stay, Max Stay, Orphan Gap Auto-Correction)
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

		// Financial Integrity Check: If payments have been collected, route to staff review
		if ( $paid_val > 0.001 ) {
			$available = Pricing::is_room_available( $room_id, $new_check_in, $new_check_out, $booking_id );
			if ( true !== $available ) {
				$msg = is_string( $available ) ? I18n::get_label( $available ) : __( 'The requested dates are not available for this room. Please choose different dates.', 'modern-hotel-booking' );
				return new WP_Error(
					'mhbo_dates_unavailable',
					$msg,
					array( 'status' => 400 )
				);
			}

			$date_req_str  = "{$new_check_in} to {$new_check_out}";
			$guest_req_str = sprintf( '%d adults, %d children', $new_guests, $new_children );
			$full_notes    = $notes ?: __( 'Stay modification requested via guest portal.', 'modern-hotel-booking' );

			$fake_req = new WP_REST_Request( 'POST', '/mhbo/v1/portal/modify' );
			$fake_req->set_param( 'token', (string) $booking->booking_token );
			$fake_req->set_param( 'booking_id', $booking_id );
			$fake_req->set_param( 'email', (string) $booking->customer_email );
			$fake_req->set_param( 'requested_dates', $date_req_str );
			$fake_req->set_param( 'requested_guests', $guest_req_str );
			$fake_req->set_param( 'notes', $full_notes );

			return $this->request_modification( $fake_req );
		}

		// Law 5: Acquire atomic booking lock before checking availability and mutating dates
		if ( ! Pricing::acquire_booking_lock( $room_id, 10 ) ) {
			return new WP_Error(
				'mhbo_lock_failed',
				I18n::get_label( 'label_booking_busy' ),
				array( 'status' => 409 )
			);
		}

		try {
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

			// Recalculate price
			$decoded_extras = ! empty( $booking->booking_extras ) ? json_decode( (string) $booking->booking_extras, true ) : array();
			/**
			 * Decoded extras payload.
			 *
			 * @var array<string, mixed> $existing_extras
			 */
			$existing_extras = is_array( $decoded_extras ) ? $decoded_extras : array();
			$calc            = Pricing::calculate_booking_money( $room_id, $new_check_in, $new_check_out, $new_guests, $existing_extras, $new_children );

			if ( false === $calc || ! isset( $calc['total'] ) || ! ( $calc['total'] instanceof Money ) ) {
				return new WP_Error(
					'mhbo_calc_error',
					__( 'Could not recalculate stay price for the selected dates.', 'modern-hotel-booking' ),
					array( 'status' => 400 )
				);
			}

			$new_total_money = $calc['total'];
			$new_total_val   = (float) $new_total_money->toDecimal();
			$old_check_in    = (string) $booking->check_in;
			$old_check_out   = (string) $booking->check_out;

			global $wpdb;
			$timestamp = current_time( 'mysql' );
			$audit_msg = sprintf(
				"[%s Guest Portal Stay Date Update]\nDates changed from %s to %s -> %s to %s. Total: %s",
				$timestamp,
				$old_check_in,
				$old_check_out,
				$new_check_in,
				$new_check_out,
				Pricing::format_price( $new_total_val )
			);

			$existing_notes = (string) ( $booking->admin_notes ?? '' );
			$new_notes      = trim( $existing_notes . "\n\n" . $audit_msg );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'mhbo_bookings',
				array(
					'check_in'          => $new_check_in,
					'check_out'         => $new_check_out,
					'guests'            => $new_guests,
					'children'          => $new_children,
					'total_price'       => $new_total_val,
					'remaining_balance' => $new_total_val,
					'admin_notes'       => $new_notes,
					'updated_at'        => $timestamp,
				),
				array( 'id' => $booking_id ),
				array( '%s', '%s', '%d', '%d', '%f', '%f', '%s', '%s' ),
				array( '%d' )
			);
		} finally {
			Pricing::release_booking_lock( $room_id );
		}

		Cache::invalidate_booking( $booking_id, $room_id );
		Cache::invalidate_date_range( $old_check_in, $old_check_out, $room_id );
		Cache::invalidate_date_range( $new_check_in, $new_check_out, $room_id );

		// Fire booking modification action & increment iCal sequence
		$seq = (int) get_post_meta( $booking_id, '_mhbo_ical_sequence', true );
		update_post_meta( $booking_id, '_mhbo_ical_sequence', $seq + 1 );
		do_action( 'mhbo_booking_dates_updated', $booking_id, $new_check_in, $new_check_out );

		// Dispatch confirmation email to guest and property staff
		if ( class_exists( Email::class ) ) {
			Email::send_stay_dates_updated_email( $booking_id, $old_check_in, $old_check_out );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Your stay dates have been updated successfully.', 'modern-hotel-booking' ),
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
			'cancelled'       => __( 'Cancelled', 'modern-hotel-booking' ),
			'completed'       => __( 'Checked Out', 'modern-hotel-booking' ),
			default           => ucfirst( $status ),
		};
	}

	/**
	 * Translate payment status to human-readable label.
	 *
	 * @param string $payment_status
	 * @param float  $remaining_balance
	 * @return string
	 */
	private function get_payment_status_label( string $payment_status, float $remaining_balance ): string {
		if ( $remaining_balance <= 0.001 ) {
			return __( 'Paid in Full', 'modern-hotel-booking' );
		}

		if ( 'deposit_paid' === $payment_status || 'partially_paid' === $payment_status ) {
			return __( 'Deposit Paid (Balance Due)', 'modern-hotel-booking' );
		}

		return __( 'Payment Due', 'modern-hotel-booking' );
	}
}
