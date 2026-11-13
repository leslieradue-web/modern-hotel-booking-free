<?php declare(strict_types=1);

/**
 * REST API endpoints for Modern Hotel Booking.
 *
 * Namespace: mhbo/v1
 * Endpoints:
 *   GET  /rooms         — list room types
 *   GET  /availability  — check availability for date range
 *   POST /bookings      — create a booking (API key required)
 *   GET  /bookings/{id} — get booking details (API key required)
 *
 * @package MHBO\Api
 * @since   2.0.1
 */

namespace MHBO\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\Cache;
use MHBO\Core\I18n;
use MHBO\Core\Pricing;
use MHBO\Core\Privacy;
use MHBO\Core\HotelTime;
use MHBO\Core\Capabilities;
use MHBO\Core\License;
use MHBO\Core\Security;
use MHBO\Core\Money;
use MHBO\Core\Tax;

/**
 * REST API endpoints for Modern Hotel Booking.
 *
 * @package MHBO\Api
 * @since   2.0.1
 */
class RestApi {

/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		$namespace = 'mhbo/v1';

		register_rest_route(
			$namespace,
			'/rooms',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_rooms' ),
				'permission_callback' => function ( $request ) {
					
					return $this->check_read_access( $request );
				},
			)
		);

		register_rest_route(
			$namespace,
			'/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_availability' ),
				'permission_callback' => function ( $request ) {
					return $this->check_read_access( $request );
				},
				'args'                => array(
					'check_in'  => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_date' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'check_out' => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_date' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

register_rest_route(
			$namespace,
			'/bookings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_bookings' ),
				'permission_callback' => array( $this, 'check_api_key' ),
				'args'                => array(
					'status'   => array(
						'required'          => false,
						'validate_callback' => function ( $value ) {
							return '' === $value || in_array( $value, array( 'pending', 'confirmed', 'cancelled', 'completed', 'deposit_paid', 'refunded' ), true );
						},
						'sanitize_callback' => 'sanitize_key',
					),
					'per_page' => array(
						'required'          => false,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 100;
						},
						'sanitize_callback' => 'absint',
						'default'           => 20,
					),
					'page'     => array(
						'required'          => false,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && (int) $value >= 1;
						},
						'sanitize_callback' => 'absint',
						'default'           => 1,
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/bookings/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_booking' ),
				'permission_callback' => function ( $request ) {
					// Allow administrators OR valid API Key holders
					if ( Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) ) {
						return true;
					}
					return $this->check_api_key( $request );
				},
			)
		);

		register_rest_route(
			$namespace,
			'/bookings/(?P<reference>[a-zA-Z0-9]{24,64})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_booking' ),
				'permission_callback' => '__return_true', // Authorization handled inside get_booking via verify_booking_access
			)
		);

		register_rest_route(
			$namespace,
			'/calendar-data',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_calendar_data' ),
				'permission_callback' => array( $this, 'check_public_read_access' ),
				'args'                => array(
					'room_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'year'    => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'month'   => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'offset_days' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'days_limit'  => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/recalculate-price',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'recalculate_price' ),
				'permission_callback' => array( $this, 'check_read_access' ),
				'args'                => array(
					'room_id'     => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && intval( $value ) > 0;
						},
						'sanitize_callback' => 'absint',
					),
					'check_in'    => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_date' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'check_out'   => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_date' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'guests'      => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 1,
					),
					'children'    => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'child_ages'  => array(
						'required'          => false,
						'validate_callback' => function ( $value ) {
							return is_array( $value );
						},
						'sanitize_callback' => function ( $value ) {
							return Pricing::sanitize_child_ages( $value );
						},
						'default'           => array(),
					),
					'exclude_id'  => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'update_id'   => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'booking_token' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'extras'      => array(
						'required'          => false,
						'validate_callback' => function ( $value ) {
							return is_array( $value );
						},
						'sanitize_callback' => function ( $value ) {
							if ( ! is_array( $value ) ) {
								return array();
							}
							$sanitized = array();
							foreach ( $value as $k => $v ) {
								$sanitized[ sanitize_key( $k ) ] = sanitize_text_field( $v );
							}
							return $sanitized;
						},
						'default'           => array(),
					),
					'coupon_code' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);

// 2026 BP: Modernized public booking completion endpoint.
		// Replaces legacy admin-ajax.php logic to ensure output isolation and performance.
		register_rest_route(
			$namespace,
			'/booking/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_public_booking_submission' ),
				'permission_callback' => function ( $request ) {
					return $this->check_read_access( $request );
				},
				'args'                => array(
					'room_id'        => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && intval( $value ) > 0;
						},
						'sanitize_callback' => 'absint',
					),
					'check_in'       => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_date' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'check_out'      => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_date' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_name'  => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && mb_strlen( trim( $value ) ) > 0 && mb_strlen( $value ) <= 100;
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_email' => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_email( $value );
						},
						'sanitize_callback' => 'sanitize_email',
					),
					'customer_phone' => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && mb_strlen( $value ) <= 30;
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'guests'         => array(
						'required'          => false,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && (int) $value >= 1;
						},
						'sanitize_callback' => 'absint',
						'default'           => 1,
					),
					'payment_method' => array(
						'required'          => false,
						'validate_callback' => function ( $value ) {
							return in_array( $value, array( 'arrival', 'stripe', 'paypal', 'braintree' ), true );
						},
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'arrival',
					),
				),
			)
		);
	}

	/**
	 * Validate a date string (Y-m-d).
	 *
	 * @param string $value Date string.
	 * @return bool
	 */
	public function validate_date( $value ) {
		$d = \DateTime::createFromFormat( 'Y-m-d', $value );
		return $d && $d->format( 'Y-m-d' ) === $value;
	}

	/**
	 * General check if REST API is allowed (Pro only).
	 *
	 * @return bool|\WP_Error
	 */
	public function check_pro_access() {
		
	}

	/**
	 * Check for read access - Verify nonce (CSRF protection) and rate limit.
	 * Use for non-authenticated public endpoints that need protection.
	 *
	 * @param \WP_REST_Request|null $request Request object.
	 * @return bool|\WP_Error
	 */
	public function check_read_access( $request = null ) {
		// If a nonce is supplied in header or query parameter, verify it
		if ( $request !== null ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( null === $nonce || '' === (string) $nonce ) {
				$nonce = $request->get_param( '_wpnonce' ) ?? $request->get_param( 'nonce' );
			}
			if ( null !== $nonce && '' !== (string) $nonce ) {
				$nonce_str   = (string) $nonce;
				$valid_rest  = wp_verify_nonce( $nonce_str, 'wp_rest' );
				$valid_front = wp_verify_nonce( $nonce_str, 'mhbo_frontend' );
				if ( false === $valid_rest && false === $valid_front ) {
					return new \WP_Error(
						'mhbo_unauthorized',
						esc_html( I18n::get_label( 'label_invalid_nonce' ) ),
						array( 'status' => 403 )
					);
				}
			}
		}

		// Apply rate limiting for public endpoints
		$rate_limit = $this->check_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		return true;
	}

	/**
	 * Check access for public read endpoints (e.g. calendar data, countries).
	 * Bypasses strict nonce validation because aggressive page caching can cause 
	 * frontend JS to send stale nonces for public guests. 
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error True if allowed, WP_Error otherwise.
	 */
	public function check_public_read_access( $request = null ) {
		// Apply rate limiting for public endpoints
		$rate_limit = $this->check_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		return true;
	}

	/**
	 * Check rate limit for API requests.
	 * Limits to 60 requests per minute per IP address.
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error if rate limited.
	 */
	private function check_rate_limit() {
		$ip = Security::get_client_ip();
		
		// 2026 BP: Rule 11 - Individual extraction/sanitization from superglobals.
		// Rule 11: Individually extract and sanitize to avoid contamination.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized/unslashed on next line
		$raw_api_key = isset( $_SERVER['HTTP_X_MHBO_API_KEY'] ) ? wp_unslash( $_SERVER['HTTP_X_MHBO_API_KEY'] ) : '';
		$api_key     = sanitize_text_field( (string) $raw_api_key );
		
		// Use API Key for bucket only if key is genuinely authentic; unverified headers fallback to verified client IP
		$is_valid_key  = ( '' !== $api_key ) && $this->is_valid_api_key_string( $api_key );
		$identifier    = $is_valid_key ? 'key_' . md5( $api_key ) : 'ip_' . md5( $ip );
		
		$transient_key = 'mhbo_api_rate_' . $identifier;
		$request_count = get_transient( $transient_key );
		
		// Higher limit for authentic API Key holders (300), 180 req/min default for public read requests
		$default_limit = $is_valid_key ? 300 : 180;
		$limit         = apply_filters( 'mhbo_api_rate_limit', $default_limit, $api_key ); 
		$window        = apply_filters( 'mhbo_api_rate_window', 60 ); 

		if ( false === $request_count ) {
			set_transient( $transient_key, 1, $window );
			return true;
		}

		if ( $request_count >= $limit ) {
			return new \WP_Error(
				'mhbo_rate_limit_exceeded',
				esc_html( I18n::get_label( 'label_api_rate_limit' ) ),
				array( 'status' => 429 )
			);
		}

		set_transient( $transient_key, (int) $request_count + 1, $window );
		return true;
	}

	/**
	 * Verify if an API key string matches the decrypted stored key.
	 *
	 * @param string $api_key Plaintext key candidate.
	 * @return bool True if authentic.
	 */
	public function is_valid_api_key_string( string $api_key ): bool {
		if ( '' === $api_key ) {
			return false;
		}

		$is_valid = false;

return $is_valid;
	}

/**
	 * Verify webhook permission - check for valid webhook signature.
	 * SECURITY: This prevents unauthorized webhook submissions.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public function verify_webhook_permission( $request ) {
		$headers = $request->get_headers();
		$payload = $request->get_body();

// SECURITY: Reject webhooks without proper signatures
		return new \WP_Error(
			'mhbo_webhook_unauthorized',
			esc_html( I18n::get_label( 'label_webhook_sig_required' ) ),
			array( 'status' => 401 )
		);
	}

/**
	 * GET /rooms — List all room types.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_rooms() {
		global $wpdb;
		$cache_key  = 'all_types';
		$room_types = Cache::get_query( $cache_key, Cache::TABLE_ROOM_TYPES );

		if ( false === $room_types ) {
			// RATIONALE: Required to list room types for public rooms REST endpoint.
			// Read-only; result is cached via Cache::set_query with versioned salt.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, caching implemented via Cache class
			$room_types = $wpdb->get_results(
				"SELECT id, name, description, base_price, max_adults, max_children, total_rooms, amenities, image_url
                 FROM {$wpdb->prefix}mhbo_room_types ORDER BY id ASC"
			);
			Cache::set_query( $cache_key, $room_types, Cache::TABLE_ROOM_TYPES, HOUR_IN_SECONDS );
		}

		$data = array();
		foreach ( $room_types as $type ) {
			$data[] = array(
				'id'           => (int) $type->id,
				'name'         => I18n::decode( $type->name ),
				'description'  => I18n::decode( $type->description ),
				'base_price'   => (float) $type->base_price,
				'max_adults'   => (int) $type->max_adults,
				'max_children' => (int) $type->max_children,
				'total_rooms'  => (int) $type->total_rooms,
				'amenities'    => $type->amenities ? json_decode( $type->amenities, true ) : array(),
				'image_url'    => esc_url( $type->image_url ),
			);
		}

		return rest_ensure_response( $data );
	}

	/**
	 * GET /availability — Check room availability.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_availability( $request ) {
		global $wpdb;

		// Rule 11: Extract and sanitize all inputs at start
		$check_in  = sanitize_text_field( $request->get_param( 'check_in' ) );
		$check_out = sanitize_text_field( $request->get_param( 'check_out' ) );

if ( $check_in >= $check_out ) {
			return new \WP_Error(
				'mhbo_invalid_dates',
				esc_html( I18n::get_label( 'label_check_out_after' ) ),
				array( 'status' => 400 )
			);
		}

		if ( ! HotelTime::is_within_advance_booking_window( $check_in ) ) {
			$max_window_human = HotelTime::format_advance_window_human( HotelTime::get_max_advance_booking_days() );
			return new \WP_Error(
				'mhbo_advance_limit_exceeded',
				sprintf( I18n::get_label( 'label_advance_limit_exceeded' ), $max_window_human ),
				array( 'status' => 400 )
			);
		}

		// Get all rooms - cache this as room configuration rarely changes
		$rooms_cache_key = 'rooms_with_types';
		$rooms           = Cache::get_query( $rooms_cache_key, Cache::TABLE_ROOMS );

		if ( false === $rooms ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- RATIONALE: Room Type lookup uses a custom table. Result is cached above using a unique key to prevent redundant schema-level JOINS under high REST traffic.
			$rooms = $wpdb->get_results(
				"SELECT r.id AS room_id, r.room_number, r.status, r.custom_price,
                        t.id AS type_id, t.name AS type_name, t.base_price, t.max_adults, t.max_children
                 FROM {$wpdb->prefix}mhbo_rooms r
                 JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
                 ORDER BY t.id, r.id"
			);
			Cache::set_query( $rooms_cache_key, $rooms, Cache::TABLE_ROOMS, HOUR_IN_SECONDS );
		}

		// 2026 BP: Bulk pre-fetch all room metadata and policies in a single query 
		// to eliminate the N+1 problem during availability calculations.
		$room_ids = array_map(
			function ( $r ) {
				return (int) $r->room_id;
			},
			$rooms 
		);
		Pricing::prime_room_cache( $room_ids );

$available = array();
		$nights    = 0;
		try {
			$nights = (int) ( new \DateTime( $check_in ) )->diff( new \DateTime( $check_out ) )->format( '%a' );
		} catch ( \Exception ) {
			$nights = 0;
		}

		foreach ( $rooms as $room ) {
			$rid = (int) $room->room_id;
			// Use the centralized availability check (Consolidated SQL)
			$availability_status = Pricing::is_room_available( $rid, $check_in, $check_out );

			if ( true === $availability_status && $nights > 0 ) {
				if ( Pricing::is_closed_to_arrival( $rid, $check_in ) || Pricing::is_closed_to_departure( $rid, $check_out ) ) {
					continue;
				}
				$eff_min = Pricing::get_effective_min_stay( $rid, $check_in );
				$eff_max = Pricing::get_effective_max_stay( $rid, $check_in, $check_out );
				if ( null !== $eff_min && $nights < $eff_min ) {
					continue;
				}
				if ( null !== $eff_max && $nights > $eff_max ) {
					continue;
				}

				// Calculate total price for the stay using Money precision
				$currency    = Pricing::get_currency_code();
				$total_money = Money::fromCents( 0, $currency );
				$period      = new \DatePeriod(
					new \DateTime( $check_in ),
					new \DateInterval( 'P1D' ),
					new \DateTime( $check_out )
				);
				foreach ( $period as $date ) {
					$total_money = $total_money->add( Pricing::calculate_daily_price_money( $rid, $date->format( 'Y-m-d' ) ) );
				}

				$available[] = array(
					'room_id'         => $rid,
					'room_number'     => $room->room_number,
					'type_id'         => (int) $room->type_id,
					'type_name'       => I18n::decode( $room->type_name ),
					'max_adults'      => (int) $room->max_adults,
					'max_children'    => (int) $room->max_children,
					'total_price'     => (float) $total_money->toDecimal(),
					'price_formatted' => $total_money->format(),
				);
			}
		}

		return rest_ensure_response(
			array(
				'check_in'  => $check_in,
				'check_out' => $check_out,
				'available' => $available,
				'count'     => count( $available ),
			)
		);
	}

/**
	 * Build deterministic cache version hash for calendar payloads across SSR and REST.
	 *
	 * @return string Raw version string before MD5 hashing.
	 */
	public static function get_calendar_version_hash(): string {
		$parts = array(
			'v4',
			(string) Cache::get_version( Cache::TABLE_BOOKINGS ),
			(string) Cache::get_version( Cache::TABLE_CALENDAR_OVERRIDES ),
			(string) Cache::get_version( Cache::TABLE_ROOMS ),
			(string) Cache::get_version( Cache::TABLE_PRICING_RULES ),
			(string) Cache::get_version( Cache::TABLE_SETTINGS ),
			(string) ( ( class_exists( '\MHBO\Core\Tier' ) && \MHBO\Core\Tier::has_feature( 'prevent_same_day_turnover' ) ) ? (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) : 0 ),
			(string) (int) get_option( 'mhbo_global_min_stay', 0 ),
			(string) (int) get_option( 'mhbo_global_max_stay', 0 ),
			(string) (int) get_option( 'mhbo_calendar_show_decimals', 0 ),
		);

return implode( '_', $parts );
	}

	/**
	 * GET /calendar-data — Get availability data for calendar display.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_calendar_data( $request ) {
		// Rule 11: Extract and sanitize all inputs at start
		$raw_room_id = $request->get_param( 'room_id' );
		$room_id     = ( $raw_room_id !== null && '' !== $raw_room_id ) ? absint( $raw_room_id ) : -1;
		$year        = absint( $request->get_param( 'year' ) ?: wp_date( 'Y' ) );
		$month       = absint( $request->get_param( 'month' ) ?: wp_date( 'm' ) );
		$offset_days = absint( $request->get_param( 'offset_days' ) ?: 0 );
		$days_limit  = absint( $request->get_param( 'days_limit' ) ?: 0 );

		if ( $room_id < 0 ) {
			return new \WP_Error( 'mhbo_missing_room_id', I18n::get_label( 'api_err_room_id_required' ), array( 'status' => 400 ) );
		}

		// BP 2026: Transient Payload Caching (Skip all SQL and formatting loops)
		// Auto-invalidates when bookings, overrides, rooms, or settings change.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe debugging flag; gated behind admin capability.
		if ( isset( $_GET['flush_cal'] ) && current_user_can( 'manage_options' ) ) {
			Cache::bump( Cache::TABLE_BOOKINGS );
			Cache::bump( Cache::TABLE_SETTINGS );
		}
		$version_hash = self::get_calendar_version_hash();
		
		$transient_key  = 'mhbo_cal_payload_' . $room_id . '_' . $year . '_' . $month . '_o' . $offset_days . '_l' . $days_limit . '_v' . md5( $version_hash );
		$cached_payload = get_transient( $transient_key );

		if ( false !== $cached_payload ) {
			$cached_response = rest_ensure_response( $cached_payload );
			if ( $cached_response instanceof \WP_REST_Response ) {
				$cached_response->header( 'X-MHBO-Calendar-Hash', md5( $version_hash ) );
			}
			return $cached_response;
		}

		// Validate room_id - If 0, we serve aggregated data for the search page
		if ( 0 === $room_id ) {
			$data     = self::generate_aggregated_calendar_payload( $year, $month, $days_limit, $offset_days );
			$response = rest_ensure_response( $data );
			$response->header( 'X-MHBO-Calendar-Hash', md5( $version_hash ) );

			if ( is_array( $data ) && array() !== $data ) {
				set_transient( $transient_key, $data, 12 * HOUR_IN_SECONDS );
			}

			return $response;
		}

$data = self::generate_calendar_payload( $room_id, $year, $month, $days_limit, $offset_days );
		
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$response = rest_ensure_response( $data );

// BP 2026: Emit version hash header for Stale-While-Revalidate.
		// The JS reads this to detect whether preloaded DOM data is still valid.
		$response->header( 'X-MHBO-Calendar-Hash', md5( $version_hash ) );
		
		// Save to transient for caching ONLY if payload is a non-empty array
		if ( is_array( $data ) && array() !== $data ) {
			set_transient( $transient_key, $data, 12 * HOUR_IN_SECONDS );
		}
		
		return $response;
	}

	/**
	 * Generate aggregated calendar payload for all rooms.
	 *
	 * Extracted from get_calendar_data() to allow server-side DOM preloading
	 * in Calendar.php for aggregated views (room_id = 0).
	 *
	 * @since 2.4.9
	 *
	 * @param int $year        Calendar start year.
	 * @param int $month       Calendar start month.
	 * @param int $days_limit  Optional chunk length in days (0 for full advance window).
	 * @param int $offset_days Optional day offset from start of month.
	 * @return array<int, array<string, mixed>> Calendar day entries.
	 */
	public static function generate_aggregated_calendar_payload( int $year, int $month, int $days_limit = 0, int $offset_days = 0 ): array {
		$max_advance    = HotelTime::get_max_advance_booking_days();
		$base_date      = new \DateTime( sprintf( '%04d-%02d-01', $year, $month ) );
		$dt_start       = $offset_days > 0 ? ( clone $base_date )->modify( "+{$offset_days} days" ) : $base_date;
		$effective_days = $days_limit > 0 ? min( $days_limit, max( 0, $max_advance - $offset_days ) ) : max( 0, $max_advance - $offset_days );
		$dt_end         = ( clone $dt_start )->modify( "+{$effective_days} days" );

		$instance = new self();
		$response = $instance->get_aggregated_calendar_data( $dt_start->format( 'Y-m-d' ), $dt_end->format( 'Y-m-d' ) );

		$payload = $response->get_data();
		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Normalize booking status for calendar display.
	 *
	 * Maps various operational booking statuses into either 'pending' (orange)
	 * or 'confirmed' (red) for calendar display.
	 *
	 * @param string|null $status Raw booking status.
	 * @return string|null Normalized status ('pending', 'confirmed', or null).
	 */
	public static function normalize_booking_status( ?string $status ): ?string {
		if ( null === $status || '' === $status ) {
			return null;
		}

		$status = sanitize_key( $status );

		if ( in_array( $status, array( 'pending', 'pending_payment', 'processing' ), true ) ) {
			return 'pending';
		}

		if ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			return null;
		}

		return 'confirmed';
	}

	/**
	 * Generate calendar payload for a specific room.
	 *
	 * @param int $room_id     Room ID (must be > 0).
	 * @param int $year        Calendar start year.
	 * @param int $month       Calendar start month.
	 * @param int $days_limit  Optional chunk length in days (0 for full advance window).
	 * @param int $offset_days Optional day offset from start of month.
	 * @return array<int, array<string, mixed>>|\WP_Error Calendar day entries or error.
	 */
	public static function generate_calendar_payload( int $room_id, int $year, int $month, int $days_limit = 0, int $offset_days = 0 ): array|\WP_Error {
		global $wpdb;

		$max_advance      = HotelTime::get_max_advance_booking_days();
		$base_date        = new \DateTime( sprintf( '%04d-%02d-01', $year, $month ) );
		$start_date       = $offset_days > 0 ? ( clone $base_date )->modify( "+{$offset_days} days" ) : $base_date;
		$effective_days   = $days_limit > 0 ? min( $days_limit, max( 0, $max_advance - $offset_days ) ) : max( 0, $max_advance - $offset_days );
		$end_date         = ( clone $start_date )->modify( "+{$effective_days} days" );
		$target_start_str = $start_date->format( 'Y-m-d' );
		$target_end_str   = $end_date->format( 'Y-m-d' );

		// Extended window ensures exact prior-night checkout eligibility and boundary-aware orphan-gap detection across chunk edges.
		$ext_start = ( clone $start_date )->modify( '-35 days' );
		$ext_end   = ( clone $end_date )->modify( '+35 days' );

		$pending_cutoff     = Pricing::get_pending_payment_cutoff();
		$bookings_cache_key = 'calendar_bookings_v4_' . $room_id . '_' . substr( $pending_cutoff, 0, 16 );
		$bookings           = Cache::get_query( $bookings_cache_key, Cache::TABLE_BOOKINGS );

		if ( false === $bookings ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom tables, versioned caching implemented above
			$bookings = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT check_in, check_out, status FROM {$wpdb->prefix}mhbo_bookings 
                 WHERE room_id = %d 
                 AND status NOT IN ('cancelled', 'refunded', 'failed')
                 AND (status != 'pending_payment' OR COALESCE(payment_status, 'pending') != 'pending' OR COALESCE(deposit_received, 0) = 1 OR COALESCE(payment_received, 0) = 1 OR COALESCE(payment_amount, 0) > 0 OR created_at >= %s)",
					$room_id,
					$pending_cutoff
				)
			);
			Cache::set_query( $bookings_cache_key, $bookings, Cache::TABLE_BOOKINGS, 300 );
		}

		// Get room status to mark as unbookable if maintenance/hidden/disabled
		$room_status = Cache::get_row( $room_id, Cache::TABLE_ROOMS );

		if ( false === $room_status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, row caching implemented above
			$room_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d",
					$room_id
				)
			) ?: 'available';
			Cache::set_row( $room_id, $room_status, Cache::TABLE_ROOMS, HOUR_IN_SECONDS );
		}

		// Map each date to its booking status (confirmed takes priority over pending)
		$booked_dates            = array();
		$raw_booked_dates        = array();
		$check_ins               = array();
		$raw_check_ins           = array();
		$check_outs              = array();
		$checkout_booking_status = array();

		if ( is_array( $bookings ) ) {
			foreach ( $bookings as $b ) {
				$norm_status = self::normalize_booking_status( $b->status );
				if ( null === $norm_status ) {
					continue;
				}
				if ( ! isset( $check_ins[ $b->check_in ] ) || 'confirmed' === $norm_status ) {
					$check_ins[ $b->check_in ]     = $norm_status;
					$raw_check_ins[ $b->check_in ] = (string) $b->status;
				}
				if ( ! isset( $check_outs[ $b->check_out ] ) || 'confirmed' === $norm_status ) {
					$check_outs[ $b->check_out ]              = $norm_status;
					$checkout_booking_status[ $b->check_out ] = (string) $b->status;
				}
				try {
					$period = new \DatePeriod(
						new \DateTime( $b->check_in ),
						new \DateInterval( 'P1D' ),
						new \DateTime( $b->check_out )
					);
					foreach ( $period as $date ) {
						$date_str = $date->format( 'Y-m-d' );
						if ( ! isset( $booked_dates[ $date_str ] ) || 'confirmed' === $norm_status ) {
							$booked_dates[ $date_str ]     = $norm_status;
							$raw_booked_dates[ $date_str ] = (string) $b->status;
						}
					}
				} catch ( \Exception $e ) {
					unset( $e );
				}
			}
		}

		// Hoist options that are loop-invariant out of the per-date iteration.
		$prevent_turnover = ( class_exists( '\MHBO\Core\Tier' ) && \MHBO\Core\Tier::has_feature( 'prevent_same_day_turnover' ) ) && (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1;
		$show_decimals    = (int) get_option( 'mhbo_calendar_show_decimals', 0 ) === 1;
		$global_min_stay  = (int) get_option( 'mhbo_global_min_stay', 0 );
		$global_max_stay  = (int) get_option( 'mhbo_global_max_stay', 0 );

// Seed prior-night bookability for exact AM checkout eligibility on $ext_start.
		$seed_date_str       = ( clone $ext_start )->modify( '-1 day' )->format( 'Y-m-d' );
		$seed_next_str       = $ext_start->format( 'Y-m-d' );
		$seed_manual_block   = false;
		
		$seed_price          = (float) Pricing::calculate_daily_price_money( $room_id, $seed_date_str )->toDecimal();
		$prev_night_bookable = ( 'available' === $room_status )
			&& ! isset( $booked_dates[ $seed_date_str ] )
			&& ! $seed_manual_block
			&& ! ( $prevent_turnover && isset( $check_outs[ $seed_date_str ] ) )
			&& ! ( $prevent_turnover && isset( $check_ins[ $seed_next_str ] ) )
			&& ( $seed_price > 0 );

		$ext_data = array();
		try {
			$period = new \DatePeriod( $ext_start, new \DateInterval( 'P1D' ), $ext_end );
			foreach ( $period as $dt ) {
				$date_str     = $dt->format( 'Y-m-d' );
				$next_day_str = ( clone $dt )->modify( '+1 day' )->format( 'Y-m-d' );
				$price_money  = Pricing::calculate_daily_price_money( $room_id, $date_str );
				$price        = (float) $price_money->toDecimal();

				$has_real_booking = isset( $booked_dates[ $date_str ] );
				$is_manual_block  = false;
				$is_cta           = false;
				$is_ctd           = false;

$is_occupied_night    = $has_real_booking || $is_manual_block;
				$is_check_in_day      = isset( $check_ins[ $date_str ] );
				$is_check_out_day     = isset( $check_outs[ $date_str ] );
				$is_turnover_co_block = $prevent_turnover && $is_check_out_day;
				$is_turnover_ci_block = $prevent_turnover && isset( $check_ins[ $next_day_str ] );
				$is_unbookable        = ( 'available' !== $room_status ) || ( ! $is_occupied_night && $price <= 0 );
				$night_stayable       = ! $is_occupied_night && ! $is_turnover_co_block && ! $is_turnover_ci_block && ! $is_unbookable;

				$can_check_in  = $night_stayable && ! $is_cta;
				$can_check_out = $prev_night_bookable && ! $is_ctd;

				$final_status = 'available';
				if ( $is_occupied_night || $is_turnover_co_block || $is_turnover_ci_block ) {
					$final_status = 'booked';
				} elseif ( $is_unbookable ) {
					$final_status = 'unbookable';
				}

				$b_status       = null;
				$norm_b_status  = null;
				$co_status      = $is_check_out_day ? ( $checkout_booking_status[ $date_str ] ?? null ) : null;
				$norm_co_status = $is_check_out_day ? $check_outs[ $date_str ] : null;

				if ( $has_real_booking ) {
					$b_status      = $raw_booked_dates[ $date_str ] ?? $booked_dates[ $date_str ];
					$norm_b_status = $booked_dates[ $date_str ];
				} elseif ( $is_manual_block ) {
					$b_status      = 'confirmed';
					$norm_b_status = 'confirmed';
				} elseif ( $is_turnover_co_block ) {
					$b_status      = $checkout_booking_status[ $date_str ] ?? $check_outs[ $date_str ];
					$norm_b_status = $check_outs[ $date_str ];
				} elseif ( $is_turnover_ci_block ) {
					$b_status      = $raw_check_ins[ $next_day_str ] ?? $check_ins[ $next_day_str ];
					$norm_b_status = $check_ins[ $next_day_str ];
				} elseif ( $is_check_out_day ) {
					$b_status      = $co_status;
					$norm_b_status = $norm_co_status;
				}

				$vis_is_checkin  = false;
				$vis_is_checkout = false;
				if ( $prevent_turnover ) {
					// With same-day turnover prevented:
					// - Actual check-in, interior, and check-out days are solid blocked squares (neither AM checkout nor PM checkin).
					// - The day BEFORE an arrival (when prior night was bookable) allows morning checkout and blocks afternoon turnover.
					if ( $is_turnover_ci_block && ! $is_occupied_night && ! $is_turnover_co_block && $can_check_out ) {
						$vis_is_checkin = true;
					}
				} else {
					$vis_is_checkin  = $is_check_in_day && ( $can_check_out || $is_check_out_day );
					$vis_is_checkout = $is_check_out_day && ( ! $is_occupied_night || $is_check_in_day );
				}

				$min_stay_resolved = $global_min_stay > 0 ? $global_min_stay : null;
				$max_stay_resolved = $global_max_stay > 0 ? $global_max_stay : null;

$reason = null;
				if ( $is_manual_block ) {
					$reason = 'manual';
				} elseif ( $has_real_booking ) {
					$reason = 'booked';
				} elseif ( 'available' !== $room_status ) {
					$reason = 'maintenance';
				} elseif ( $is_turnover_co_block || $is_turnover_ci_block ) {
					$reason = 'turnover';
				} elseif ( $is_cta ) {
					$reason = 'cta';
				}

				$ext_data[] = array(
					'date'                    => $date_str,
					'status'                  => $final_status,
					'booking_status'          => $norm_b_status,
					'raw_booking_status'      => $b_status,
					'checkout_booking_status' => $norm_co_status,
					'is_checkin'              => $vis_is_checkin,
					'is_checkout'             => $vis_is_checkout,
					'can_checkin'             => $can_check_in,
					'can_checkout'            => $can_check_out,
					'is_cta'                  => $is_cta,
					'is_ctd'                  => $is_ctd,
					'price'                   => $price,
					'price_formatted'         => $price_money->format( false, $show_decimals ? null : 0 ),
					'reason'                  => $reason,
					'min_stay'                => $min_stay_resolved,
					'max_stay'                => $max_stay_resolved,
				);

				$prev_night_bookable = $night_stayable && ( ! $is_cta || $prev_night_bookable );
			}
		} catch ( \Exception $e ) {
			unset( $e );
			return new \WP_Error( 'mhbo_calendar_error', I18n::get_label( 'api_err_calendar_gen' ), array( 'status' => 500 ) );
		}

$data = array();
		foreach ( $ext_data as $entry ) {
			if ( $entry['date'] >= $target_start_str && $entry['date'] < $target_end_str ) {
				$data[] = $entry;
			}
		}

		return $data;
	}

	/**
	 * Get aggregated calendar data for all rooms.
	 *
	 * @param string $start_str Start date string (Y-m-d).
	 * @param string $end_str End date string (Y-m-d).
	 * @return \WP_REST_Response
	 */
	private function get_aggregated_calendar_data( string $start_str, string $end_str ): \WP_REST_Response {
		global $wpdb;

		// Get all rooms with caching using Rule 13 patterns
		$cache_key = 'mhbo_available_rooms_ids';
		$rooms     = Cache::get_query( $cache_key, Cache::TABLE_ROOMS );

		if ( false === $rooms ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, caching implemented via Cache::set_query
			$rooms = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'available'", $wpdb->prefix . 'mhbo_rooms' ) );
			Cache::set_query( $cache_key, $rooms, Cache::TABLE_ROOMS, 3600 );
		}

		if ( count( $rooms ) === 0 ) {
			return rest_ensure_response( array() );
		}

		$room_ids = array_map( 'intval', array_column( $rooms, 'id' ) );
		Pricing::prime_room_cache( $room_ids );

		// Same-day Turnover & Core Stay Settings (turnover is Starter+, min/max global stay across all tiers)
		$prevent_turnover = ( class_exists( '\MHBO\Core\Tier' ) && \MHBO\Core\Tier::has_feature( 'prevent_same_day_turnover' ) ) && (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1;
		$show_decimals    = (int) get_option( 'mhbo_calendar_show_decimals', 0 ) === 1;
		$global_min_stay  = (int) get_option( 'mhbo_global_min_stay', 0 );
		$global_max_stay  = (int) get_option( 'mhbo_global_max_stay', 0 );

		$ext_start     = ( new \DateTime( $start_str ) )->modify( '-35 days' );
		$ext_end       = ( new \DateTime( $end_str ) )->modify( '+35 days' );
		$ext_start_str = $ext_start->format( 'Y-m-d' );
		$ext_end_str   = $ext_end->format( 'Y-m-d' );
		$query_start   = ( clone $ext_start )->modify( '-1 day' )->format( 'Y-m-d' );
		$query_end     = ( clone $ext_end )->modify( '+1 day' )->format( 'Y-m-d' );

		$pending_cutoff = Pricing::get_pending_payment_cutoff();
		$cache_key      = 'mhbo_avail_agg_v4_' . md5( implode( ',', $room_ids ) . $ext_start_str . $ext_end_str . (int) $prevent_turnover . substr( $pending_cutoff, 0, 16 ) );
		$bookings       = Cache::get_query( $cache_key, Cache::TABLE_BOOKINGS );

		if ( false === $bookings ) {
			$room_in_clause = implode( ',', array_fill( 0, count( $room_ids ), '%d' ) );
			$params         = array_merge( $room_ids, array( $pending_cutoff, $query_end, $query_start ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			if ( $prevent_turnover ) {
				$bookings = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT room_id, check_in, check_out, status FROM ' . $wpdb->prefix . 'mhbo_bookings WHERE room_id IN (' . $room_in_clause . ") AND status NOT IN ('cancelled', 'refunded', 'failed') AND (status != 'pending_payment' OR COALESCE(payment_status, 'pending') != 'pending' OR COALESCE(deposit_received, 0) = 1 OR COALESCE(payment_received, 0) = 1 OR COALESCE(payment_amount, 0) > 0 OR created_at >= %s) AND (check_in <= DATE(%s) AND check_out >= DATE(%s))",
						...$params
					)
				);
			} else {
				$bookings = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT room_id, check_in, check_out, status FROM ' . $wpdb->prefix . 'mhbo_bookings WHERE room_id IN (' . $room_in_clause . ") AND status NOT IN ('cancelled', 'refunded', 'failed') AND (status != 'pending_payment' OR COALESCE(payment_status, 'pending') != 'pending' OR COALESCE(deposit_received, 0) = 1 OR COALESCE(payment_received, 0) = 1 OR COALESCE(payment_amount, 0) > 0 OR created_at >= %s) AND (check_in < DATE(%s) AND check_out > DATE(%s))",
						...$params
					)
				);
			}
			// phpcs:enable

			Cache::set_query( $cache_key, $bookings, Cache::TABLE_BOOKINGS, 300 );
		}

		// Organize bookings by room
		$room_bookings = array();
		foreach ( $room_ids as $rid ) {
			$room_bookings[ $rid ] = array();
		}
		if ( is_array( $bookings ) ) {
			foreach ( $bookings as $b ) {
				$rid = (int) $b->room_id;
				if ( isset( $room_bookings[ $rid ] ) ) {
					$room_bookings[ $rid ][] = array(
						'check_in'  => (string) $b->check_in,
						'check_out' => (string) $b->check_out,
						'status'    => (string) $b->status,
					);
				}
			}
		}

$per_room_data = array();
		foreach ( $room_ids as $rid ) {
			$per_room_data[ $rid ] = array();
		}

		// Seed prior-night per-room state on ($ext_start - 1 day) for exact AM eligibility & visual changeover.
		$prev_room_bookable_pm     = array();
		$prev_room_occupied_visual = array();
		$prev_room_occupied_status = array();

		$seed_str      = $query_start;
		$seed_next_str = $ext_start_str;
		foreach ( $room_ids as $rid ) {
			$s_occ_pm  = false;
			$s_blk_pm  = false;
			$s_man_blk = false;
			$s_status  = null;

foreach ( $room_bookings[ $rid ] as $b ) {
				$b_norm = self::normalize_booking_status( $b['status'] );
				if ( null === $b_norm ) {
					continue;
				}
				if ( $seed_str >= $b['check_in'] && $seed_str < $b['check_out'] ) {
					$s_occ_pm = true;
					if ( null === $s_status || 'confirmed' === $b_norm ) {
						$s_status = $b_norm;
					}
				}
				if ( $prevent_turnover && ( $seed_str === $b['check_out'] || $seed_next_str === $b['check_in'] ) ) {
					$s_blk_pm = true;
				}
			}

			$prev_room_bookable_pm[ $rid ]     = ! $s_occ_pm && ! $s_blk_pm && ! $s_man_blk;
			$prev_room_occupied_visual[ $rid ] = $s_occ_pm || $s_man_blk;
			$prev_room_occupied_status[ $rid ] = $s_occ_pm ? ( $s_status ?: 'confirmed' ) : ( $s_man_blk ? 'confirmed' : null );
		}

		$ext_data = array();
		$period   = new \DatePeriod( $ext_start, new \DateInterval( 'P1D' ), $ext_end );

		foreach ( $period as $dt ) {
			$date_str     = $dt->format( 'Y-m-d' );
			$next_day_str = ( clone $dt )->modify( '+1 day' )->format( 'Y-m-d' );

			$total_rooms            = count( $room_ids );
			$rooms_free_pm          = 0;
			$rooms_can_checkin_pm   = 0;
			$rooms_can_checkout_am  = 0;
			$rooms_occupied_am_vis  = 0;
			$min_price              = null;
			$has_pending_pm         = false;
			$has_pending_am         = false;
			$has_unlimited_max_room = false;

			$daily_min_stays    = array();
			$daily_max_stays    = array();
			$free_rooms_pm_list = array();
			$room_statuses_map  = array();
			$room_min_stays_map = array();
			$room_max_stays_map = array();
			$room_cta_map       = array();
			$room_ctd_map       = array();

			foreach ( $room_ids as $rid ) {
				$is_occupied_pm   = false;
				$is_blocked_pm    = false;
				$is_manual_block  = false;
				$room_is_checkin  = false;
				$room_is_checkout = false;
				$room_b_status    = null;
				$room_co_status   = null;

				$r_min = null;
				$r_max = null;
				$r_cta = false;
				$r_ctd = false;

foreach ( $room_bookings[ $rid ] as $b ) {
					$b_norm = self::normalize_booking_status( $b['status'] );
					if ( null === $b_norm ) {
						continue;
					}

					// Night Stay Occupancy
					if ( $date_str >= $b['check_in'] && $date_str < $b['check_out'] ) {
						$is_occupied_pm = true;
						if ( null === $room_b_status || 'confirmed' === $b_norm ) {
							$room_b_status = $b_norm;
						}
					}

					// Prevent same-day turnover block (Afternoon of checkout)
					if ( $prevent_turnover && $date_str === $b['check_out'] ) {
						$is_blocked_pm = true;
						if ( null === $room_b_status || 'confirmed' === $b_norm ) {
							$room_b_status = $b_norm;
						}
					}

					// Prevent same-day turnover block (Day before Check-In)
					if ( $prevent_turnover && $next_day_str === $b['check_in'] ) {
						$is_blocked_pm = true;
						if ( null === $room_b_status || 'confirmed' === $b_norm ) {
							$room_b_status = $b_norm;
						}
					}

					if ( $date_str > $b['check_in'] && $date_str <= $b['check_out'] ) {
						if ( null === $room_co_status || 'confirmed' === $b_norm ) {
							$room_co_status = $b_norm;
						}
					}

					if ( $date_str === $b['check_in'] ) {
						$room_is_checkin = true;
					}

					if ( $date_str === $b['check_out'] ) {
						$room_is_checkout = true;
					}
				}

				$room_bookable_pm = ! $is_occupied_pm && ! $is_blocked_pm && ! $is_manual_block;
				$eff_r_min        = null !== $r_min ? max( 1, $r_min ) : ( $global_min_stay > 0 ? $global_min_stay : null );
				$eff_r_max        = null !== $r_max ? $r_max : ( $global_max_stay > 0 ? $global_max_stay : null );

				if ( $room_bookable_pm ) {
					$price_money = Pricing::calculate_daily_price_money( $rid, $date_str );
					$price       = (float) $price_money->toDecimal();
					if ( $price > 0 ) {
						++$rooms_free_pm;
						$free_rooms_pm_list[] = $rid;
						if ( null === $min_price || $price < $min_price ) {
							$min_price = $price;
						}
						if ( $r_cta ) {
							$room_cta_map[ $rid ] = true;
						} else {
							++$rooms_can_checkin_pm;
							$eff_r_min_val     = null !== $eff_r_min ? $eff_r_min : 1;
							$daily_min_stays[] = $eff_r_min_val;
							if ( $eff_r_min_val > 1 ) {
								$room_min_stays_map[ $rid ] = $eff_r_min_val;
							}
						}
						if ( null !== $eff_r_max ) {
							$daily_max_stays[]          = $eff_r_max;
							$room_max_stays_map[ $rid ] = $eff_r_max;
						} else {
							$has_unlimited_max_room = true;
						}
					} else {
						$room_bookable_pm = false;
					}
				} else {
					$pm_eff_status = $is_manual_block ? 'confirmed' : ( $room_b_status ?: 'confirmed' );
					if ( 'pending' === $pm_eff_status ) {
						$has_pending_pm = true;
					}
				}

				if ( $r_ctd ) {
					$room_ctd_map[ $rid ] = true;
				}
				$room_can_co_am = ! empty( $prev_room_bookable_pm[ $rid ] ) && ! $r_ctd;
				if ( $room_can_co_am ) {
					++$rooms_can_checkout_am;
				}
				if ( ! empty( $prev_room_occupied_visual[ $rid ] ) ) {
					++$rooms_occupied_am_vis;
					if ( 'pending' === ( $prev_room_occupied_status[ $rid ] ?? null ) ) {
						$has_pending_am = true;
					}
				}

				$r_resolved_status = 'available';
				if ( $is_occupied_pm || $is_manual_block ) {
					$r_resolved_status = $is_manual_block ? 'confirmed' : ( $room_b_status ?: 'confirmed' );
				} elseif ( $is_blocked_pm ) {
					$r_resolved_status = 'turnover';
				} elseif ( ! $room_bookable_pm ) {
					$r_resolved_status = 'unbookable';
				}

				if ( 'available' !== $r_resolved_status || $room_is_checkin || $room_is_checkout ) {
					$r_entry = array(
						'status' => $r_resolved_status,
					);
					if ( null !== $room_b_status ) {
						$r_entry['b_status'] = $room_b_status;
					}
					if ( null !== $room_co_status ) {
						$r_entry['co_status'] = $room_co_status;
					}
					if ( $room_is_checkin && ! $prevent_turnover ) {
						$r_entry['is_checkin'] = true;
					}
					if ( $room_is_checkout && ! $prevent_turnover ) {
						$r_entry['is_checkout'] = true;
					}
					$room_statuses_map[ $rid ] = $r_entry;
				}

				$room_boundary_status = 'none';
				if ( ! $room_bookable_pm ) {
					$room_boundary_status = ( ! $is_manual_block && 'pending' === $room_b_status ) ? 'pending' : 'confirmed';
				}
				$per_room_data[ $rid ][] = array(
					'night_stayable' => $room_bookable_pm,
					'can_checkin'    => $room_bookable_pm && ! $r_cta,
					'can_checkout'   => $room_can_co_am,
					'booking_status' => $room_boundary_status,
					'min_stay'       => null !== $eff_r_min ? $eff_r_min : 1,
					'max_stay'       => $eff_r_max,
				);

				// Advance prior-night state for next day's AM evaluation (accounting for CTA reachability)
				$prev_room_bookable_pm[ $rid ]     = $room_bookable_pm && ( ! $r_cta || ! empty( $prev_room_bookable_pm[ $rid ] ) );
				$prev_room_occupied_visual[ $rid ] = $is_occupied_pm || $is_manual_block;
				$prev_room_occupied_status[ $rid ] = $is_occupied_pm ? ( $room_b_status ?: 'confirmed' ) : ( $is_manual_block ? 'confirmed' : null );
			}

			// Aggregated Status & Changeover Triangles
			$status         = 'available';
			$is_checkin     = false; // White AM (checkout allowed) / Colored PM (hotel full PM)
			$is_checkout    = false; // Colored AM (all rooms departing) / White PM (bookable PM)
			$booking_status = null;
			$agg_reason     = null;

			if ( 0 === $rooms_free_pm ) {
				$status         = 'booked';
				$booking_status = $has_pending_pm ? 'pending' : 'confirmed';
				if ( $rooms_can_checkout_am > 0 ) {
					$is_checkin = true;
				}
			} elseif ( ! $prevent_turnover && $rooms_occupied_am_vis === $total_rooms ) {
				$is_checkout    = true;
				$booking_status = $has_pending_am ? 'pending' : 'confirmed';
			}

			if ( $rooms_free_pm > 0 && 0 === $rooms_can_checkin_pm ) {
				$agg_reason = 'cta';
			}

			$resolved_agg_min = count( $daily_min_stays ) > 0 ? min( $daily_min_stays ) : null;
			if ( 1 === $resolved_agg_min && 0 === $global_min_stay ) {
				$resolved_agg_min = null;
			}
			$resolved_agg_max = ( ! $has_unlimited_max_room && count( $daily_max_stays ) > 0 ) ? max( $daily_max_stays ) : null;

			$day_entry = array(
				'date'                    => $date_str,
				'status'                  => $status,
				'booking_status'          => $booking_status,
				'raw_booking_status'      => $booking_status,
				'checkout_booking_status' => $is_checkout ? ( $has_pending_am ? 'pending' : 'confirmed' ) : null,
				'price'                   => null !== $min_price ? $min_price : 0,
				'price_formatted'         => null !== $min_price ? I18n::format_currency( $min_price, $show_decimals ? null : 0 ) : '-',
				'is_checkin'              => $is_checkin,
				'is_checkout'             => $is_checkout,
				'can_checkin'             => $rooms_can_checkin_pm > 0,
				'can_checkout'            => $rooms_can_checkout_am > 0,
				'free_rooms'              => $free_rooms_pm_list,
				'reason'                  => $agg_reason,
				'min_stay'                => $resolved_agg_min,
				'max_stay'                => $resolved_agg_max,
			);
			if ( array() !== $room_statuses_map ) {
				$day_entry['room_statuses'] = $room_statuses_map;
			}
			if ( array() !== $room_min_stays_map ) {
				$day_entry['room_min_stays'] = $room_min_stays_map;
			}
			if ( array() !== $room_max_stays_map ) {
				$day_entry['room_max_stays'] = $room_max_stays_map;
			}
			if ( array() !== $room_cta_map ) {
				$day_entry['room_cta'] = $room_cta_map;
			}
			if ( array() !== $room_ctd_map ) {
				$day_entry['room_ctd'] = $room_ctd_map;
			}
			$ext_data[] = $day_entry;
		}

$data = array();
		foreach ( $ext_data as $entry ) {
			if ( $entry['date'] >= $start_str && $entry['date'] < $end_str ) {
				$data[] = $entry;
			}
		}

		return rest_ensure_response( $data );
	}

	/**
	 * POST /recalculate-price — Calculate total price with children and extras.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function recalculate_price( $request ) {
		// Rule 11: Extract and sanitize all inputs at start
		$room_id        = absint( $request->get_param( 'room_id' ) );
		$check_in       = sanitize_text_field( $request->get_param( 'check_in' ) );
		$check_out      = sanitize_text_field( $request->get_param( 'check_out' ) );
		$guests         = absint( $request->get_param( 'guests' ) ?: 1 );
		$children       = absint( $request->get_param( 'children' ) ?: 0 );
		$child_ages_raw = $request->get_param( 'child_ages' ) ?: $request->get_param( 'children_ages' );
		$child_ages     = Pricing::sanitize_child_ages( $child_ages_raw );
		$raw_extras     = $request->get_param( 'extras' ) ?: $request->get_param( 'mhbo_extras' ) ?: array();
		$extras         = Pricing::normalize_extras( $raw_extras );
		$payment_type   = sanitize_text_field( $request->get_param( 'mhbo_payment_type' ) ?: 'full' );
		$payment_method = sanitize_text_field( $request->get_param( 'mhbo_payment_method' ) ?: '' );
		$tax_mode       = Capabilities::current_user_can( Capabilities::MANAGE_LHBO )
			? sanitize_key( (string) ( $request->get_param( 'tax_mode' ) ?: '' ) )
			: '';

		// Terminology Normalization: Map 'onsite' (Pro) to 'arrival' (Core)
		if ( 'onsite' === $payment_method ) {
			$payment_method = 'arrival';
		}

if ( ! HotelTime::is_within_advance_booking_window( $check_in ) ) {
			$max_window_human = HotelTime::format_advance_window_human( HotelTime::get_max_advance_booking_days() );
			return new \WP_Error(
				'mhbo_advance_limit_exceeded',
				sprintf( I18n::get_label( 'label_advance_limit_exceeded' ), $max_window_human ),
				array( 'status' => 400 )
			);
		}

		// Validation: Stay Restrictions (Global in Free/Starter; Date-specific + Orphan Gap in Pro)
		$calc_source       = sanitize_key( (string) ( $request->get_param( 'source' ) ?: '' ) );
		$is_admin_override = ( 'admin' === $calc_source ) && Capabilities::current_user_can( Capabilities::VIEW_BOOKINGS );

		if ( ! $is_admin_override && $room_id > 0 && '' !== $check_in && '' !== $check_out ) {
			$exclude_id  = $this->resolve_authorized_recalculate_exclude_id( $request );
			$avail_check = Pricing::is_room_available( $room_id, $check_in, $check_out, $exclude_id );
			if ( true !== $avail_check ) {
				$avail_label = is_string( $avail_check ) && '' !== $avail_check ? $avail_check : 'label_room_not_available';
				return new \WP_Error(
					'mhbo_unavailable',
					esc_html( I18n::get_label( $avail_label ) ),
					array( 'status' => 400 )
				);
			}

			if ( Pricing::is_closed_to_arrival( $room_id, $check_in ) ) {
				return new \WP_Error(
					'mhbo_cta_restricted',
					esc_html( I18n::get_label( 'api_err_cta' ) ),
					array( 'status' => 400 )
				);
			}

			if ( Pricing::is_closed_to_departure( $room_id, $check_out ) ) {
				return new \WP_Error(
					'mhbo_ctd_restricted',
					esc_html( I18n::get_label( 'api_err_ctd' ) ),
					array( 'status' => 400 )
				);
			}

			$min_stay_rule = Pricing::get_effective_min_stay( $room_id, $check_in );
			$max_stay_rule = Pricing::get_effective_max_stay( $room_id, $check_in, $check_out );

			$dt_in  = new \DateTime( $check_in );
			$dt_out = new \DateTime( $check_out );
			$nights = (int) $dt_in->diff( $dt_out )->format( '%a' );

			if ( null !== $min_stay_rule && $nights < $min_stay_rule ) {
				return new \WP_Error(
					'mhbo_min_stay',
					esc_html(
						sprintf(
							// translators: %1$d: minimum number of nights required
							I18n::get_label( 'api_err_min_stay' ),
							(int) $min_stay_rule
						)
					),
					array( 'status' => 400 )
				);
			}

			if ( null !== $max_stay_rule && $nights > $max_stay_rule ) {
				return new \WP_Error(
					'mhbo_max_stay',
					esc_html(
						sprintf(
							// translators: %1$d: maximum number of nights allowed
							I18n::get_label( 'api_err_max_stay' ),
							(int) $max_stay_rule
						)
					),
					array( 'status' => 400 )
				);
			}
		}

		$calc = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, (int) $guests, $extras, (int) $children, $child_ages, '' !== $tax_mode ? $tax_mode : null );

		$coupon_applied            = '';
		$coupon_discount_value     = '0.00';
		$coupon_discount_formatted = '';
		$first_night_money         = null;

if ( ! $calc ) {
			return new \WP_Error(
				'mhbo_calculation_failed',
				I18n::get_label( 'api_err_price_calc' ),
				array( 'status' => 400 )
			);
		}

		$tax_data = $calc['tax'] ?? array(
			'enabled' => false,
			'mode'    => 'disabled',
			'totals'  => array(
				'subtotal_net' => $calc['total'],
				'total_tax'    => Money::fromCents( 0, Pricing::get_currency_code() ),
				'total_gross'  => $calc['total'],
			),
		);

		// Extract typed local vars so the static analyser can narrow Money vs scalar — $calc is mixed[].
		$deposit_money_obj   = ( isset( $calc['deposit_money'] ) && $calc['deposit_money'] instanceof Money ) ? $calc['deposit_money'] : null;
		$remaining_money_obj = ( isset( $calc['remaining_money'] ) && $calc['remaining_money'] instanceof Money ) ? $calc['remaining_money'] : null;

		// Include deposit info for HTML rendering if present
		if ( null !== $deposit_money_obj ) {
			$tax_data['deposit_amount']    = $deposit_money_obj->toDecimal();
			$tax_data['remaining_balance'] = null !== $remaining_money_obj ? $remaining_money_obj->toDecimal() : '0.00';
		}

		// payable_now reflects what the guest actually pays at checkout — only switch to
		// deposit amount when the guest has explicitly selected the deposit payment option.
		$payable_now = $calc['total'];
		if ( 'deposit' === $payment_type && null !== $deposit_money_obj ) {
			$payable_now = $deposit_money_obj;
		}

		$children_money = $calc['children_total'] ?? Money::fromCents( 0, Pricing::get_currency_code() );
		$response       = rest_ensure_response(
			array(
				'success'                     => true,
				'total'                       => $calc['total']->toDecimal(),
				'total_formatted'             => $calc['total']->format(),
				'payable_now'                 => $payable_now->toDecimal(),
				'payable_now_formatted'       => $payable_now->format(),
				'room_total'                  => $calc['room_total']->toDecimal(),
				'room_total_formatted'        => $calc['room_total']->format(),
				'children_total'              => $children_money->toDecimal(),
				'children_total_formatted'    => $children_money->isPositive() ? $children_money->format() : '',
				'extras_total'                => $calc['extras_total']->toDecimal(),
				'service_fee'                 => ( isset( $calc['service_fee'] ) && $calc['service_fee'] instanceof Money ) ? $calc['service_fee']->toDecimal() : '0.00',
				'service_fee_formatted'       => ( isset( $calc['service_fee'] ) && $calc['service_fee'] instanceof Money && $calc['service_fee']->isPositive() ) ? $calc['service_fee']->format() : '',
				'service_fee_label'           => (string) ( $calc['service_fee_label'] ?? get_option( 'mhbo_service_fee_label', __( 'Service Fee', 'modern-hotel-booking' ) ) ),
				'deposit_amount'              => null !== $deposit_money_obj ? $deposit_money_obj->toDecimal() : '0.00',
				'deposit_amount_formatted'    => null !== $deposit_money_obj ? $deposit_money_obj->format() : '',
				'remaining_balance'           => null !== $remaining_money_obj ? $remaining_money_obj->toDecimal() : '0.00',
				'remaining_balance_formatted' => null !== $remaining_money_obj ? $remaining_money_obj->format() : '',
				'first_night_total'           => ( $first_night_money instanceof Money ) ? $first_night_money->toDecimal() : '0.00',
				'extras_breakdown'            => array_reduce(
					$calc['extras_breakdown'] ?? array(),
					function ( $carry, $item ) {
						$carry[ (string) $item['id'] ] = array(
							'value'                => $item['total']->toDecimal(),
							'formatted'            => $item['total']->format(),
							'unit_price_value'     => $item['price']->toDecimal(),
							'unit_price_formatted' => $item['price']->format(),
							'multiplier'           => (int) ( $item['multiplier'] ?? 1 ),
							'pricing_type'         => $item['pricing_type'] ?? 'fixed',
							'name'                 => $item['name'],
							'quantity'             => $item['quantity'],
							'compulsory'           => ( $item['compulsory'] ?? false ) ? 1 : 0,
						);
						return $carry;
					},
					array()
				),
				'breakdown'                   => $calc,
				'tax'                         => $tax_data,
				'tax_breakdown_html'          => get_option( 'mhbo_tax_display_frontend', false )
					? Tax::render_breakdown_html(
						$tax_data,
						null,
						false,
						array(
							'guests'          => (int) $guests,
							'children'        => (int) $children,
							'payment_type'    => $payment_type,
							'coupon_code'     => $coupon_applied,
							'coupon_discount' => $coupon_discount_value,
						),
						false
					)
					: '',
				'coupon_applied'              => $coupon_applied,
				'coupon_discount'             => $coupon_discount_value,
				'coupon_discount_formatted'   => $coupon_discount_formatted,
			)
		);

return $response;
	}

	/**
	 * Resolve an authorized booking ID to exclude from availability checks during /recalculate-price.
	 *
	 * Prevents a guest's active provisional pending_payment draft from blocking their own
	 * live price recalculations when editing children/extras, while rejecting arbitrary
	 * booking IDs that belong to confirmed bookings or other guests.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Incoming REST request.
	 * @return int Authorized booking ID to exclude, or 0 if none.
	 */
	private function resolve_authorized_recalculate_exclude_id( \WP_REST_Request $request ): int {
		global $wpdb;

		$requested_id = 0;
		foreach ( array( 'exclude_id', 'mhbo_exclude_id', 'update_id', 'mhbo_update_id' ) as $id_key ) {
			$raw_id    = $request->get_param( $id_key );
			$candidate = is_scalar( $raw_id ) ? absint( $raw_id ) : 0;
			if ( $candidate > 0 ) {
				$requested_id = $candidate;
				break;
			}
		}

		if ( $requested_id > 0 && Capabilities::current_user_can( Capabilities::VIEW_BOOKINGS ) ) {
			return $requested_id;
		}

		$session_id         = Privacy::get_cookie_session_id();
		$raw_session_id     = '' !== $session_id ? get_transient( 'mhbo_session_pending_booking_' . $session_id ) : 0;
		$session_pending_id = is_scalar( $raw_session_id ) ? absint( $raw_session_id ) : 0;

		$booking_token = '';
		foreach ( array( 'booking_token', 'mhbo_booking_token', 'session_token', 'mhbo_session_token' ) as $token_key ) {
			$raw_token = $request->get_param( $token_key );
			if ( is_string( $raw_token ) && '' !== trim( $raw_token ) ) {
				$booking_token = sanitize_text_field( $raw_token );
				break;
			}
		}

		if ( $requested_id > 0 ) {
			if ( $session_pending_id === $requested_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$verified_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d AND status = 'pending_payment' LIMIT 1",
						$requested_id
					)
				);
				if ( $verified_id > 0 ) {
					return $verified_id;
				}
			}

			if ( '' !== $booking_token ) {
				$raw_token_meta   = get_transient( 'mhbo_booking_session_' . $booking_token );
				if ( ! is_array( $raw_token_meta ) && ! is_scalar( $raw_token_meta ) ) {
					$raw_token_meta = get_transient( 'mhbo_session_' . $booking_token );
				}
				$token_session_id = 0;
				$token_cookie_ok  = true;
				if ( is_array( $raw_token_meta ) ) {
					$token_session_id = absint( $raw_token_meta['booking_id'] ?? 0 );
					$bound_sess       = (string) ( $raw_token_meta['session_id'] ?? '' );
					if ( '' !== $bound_sess && ( '' === $session_id || ! hash_equals( $bound_sess, $session_id ) ) ) {
						$token_cookie_ok = false;
					}
				} elseif ( is_scalar( $raw_token_meta ) ) {
					$token_session_id = absint( $raw_token_meta );
				}
				if ( ! $token_cookie_ok ) {
					$token_session_id = 0;
				}
				if ( $token_session_id === $requested_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$verified_id = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d AND status = 'pending_payment' LIMIT 1",
							$requested_id
						)
					);
					if ( $verified_id > 0 ) {
						return $verified_id;
					}
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$verified_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d AND booking_token = %s AND status = 'pending_payment' LIMIT 1",
						$requested_id,
						$booking_token
					)
				);
				if ( $verified_id > 0 ) {
					return $verified_id;
				}
			}

			return 0;
		}

		if ( $session_pending_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$verified_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d AND status = 'pending_payment' LIMIT 1",
					$session_pending_id
				)
			);
			if ( $verified_id > 0 ) {
				return $verified_id;
			}
		}

		if ( '' !== $booking_token ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$verified_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE booking_token = %s AND status = 'pending_payment' ORDER BY id DESC LIMIT 1",
					$booking_token
				)
			);
			if ( $verified_id > 0 ) {
				return $verified_id;
			}
		}

		return 0;
	}

	/**
	 * GET /tax-settings — Get current tax settings for frontend.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_tax_settings() {
		$tax = Tax::get_settings();

		return rest_ensure_response(
			array(
				'enabled'             => $tax['enabled'],
				'mode'                => $tax['mode'],
				'label'               => $tax['label'],
				'accommodation_rate'  => (float) $tax['accommodation_rate'],
				'extras_rate'         => (float) $tax['extras_rate'],
				'registration_number' => $tax['registration_number'],
				'display_frontend'    => (bool) $tax['display_frontend'],
				'prices_include_tax'  => (bool) $tax['prices_include_tax'],
			)
		);
	}

/**
         * Prepare a booking object for REST response.
         *
         * @param object           $booking Booking row.
         * @param \WP_REST_Request $request Request object.
         * @param mixed            $access  Access level (true, error, or 'owner').
         * @return array<string, mixed>
         */
	private function prepare_booking_for_response( object $booking, \WP_REST_Request $request, mixed $access = true ): array {
		// Return limited data for non-admin access
		$is_admin = Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS );
		
		// Treat verified API key holders as admins for data visibility
		$api_key_header = (string) ( $request->get_header( 'X-MHBO-API-KEY' ) ?? '' );
		$is_api_client  = ( '' !== $api_key_header ) && $this->is_valid_api_key_string( $api_key_header );

		$response_data = array(
			'id'                        => (int) $booking->id,
			'room_id'                   => (int) $booking->room_id,
			'check_in'                  => $booking->check_in,
			'check_out'                 => $booking->check_out,
			'total_price'               => (float) $booking->total_price,
			'status'                    => $booking->status,
			'booking_language'          => $booking->booking_language,
			'source'                    => $booking->source,
			'created_at'                => $booking->created_at,
			'payment_type'              => $booking->payment_type ?? 'full',
			'deposit_amount'            => (float) ( $booking->deposit_amount ?? 0 ),
			'remaining_balance'         => (float) ( $booking->remaining_balance ?? 0 ),
			'balance_status'            => $booking->balance_status ?? 'collected',
			'deposit_is_non_refundable' => (bool) ( $booking->deposit_is_non_refundable ?? 0 ),
			'refund_deadline_date'      => $booking->refund_deadline_date ?? null,
		);

		// Include PII only for admin access, API clients, or verified owners
		if ( $is_admin || $is_api_client || 'owner' === $access ) {
			$response_data['customer_name']  = $booking->customer_name;
			$response_data['customer_email'] = $booking->customer_email;
			$response_data['customer_phone'] = $booking->customer_phone;
			
			$breakdown = $booking->tax_breakdown ? json_decode( $booking->tax_breakdown, true ) : null;
			if ( isset( $breakdown['extras'] ) && is_array( $breakdown['extras'] ) ) {
				foreach ( $breakdown['extras'] as &$extra ) {
					if ( isset( $extra['name'] ) ) {
						$extra['name'] = I18n::decode( $extra['name'], $booking->booking_language );
					}
				}
			}

			$response_data['tax'] = array(
				'enabled'      => (bool) ( $booking->tax_enabled ?? 0 ),
				'mode'         => $booking->tax_mode ?? 'disabled',
				'subtotal_net' => (float) ( $booking->subtotal_net ?? $booking->total_price ),
				'total_tax'    => (float) ( $booking->total_tax ?? 0 ),
				'total_gross'  => (float) ( $booking->total_gross ?? $booking->total_price ),
				'breakdown'    => $breakdown,
			);
		}

		return $response_data;
	}

/**
	 * Handle public booking submission from the frontend.
	 * 2026 Modernized Flow: Replaces legacy AJAX with clean REST API.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_public_booking_submission( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// SECURITY: Rate limiting for public booking submissions (5 per minute per IP)
		$ip       = Security::get_client_ip();
		$rate_key = 'mhbo_booking_rate_' . md5( (string) $ip );
		$count    = get_transient( $rate_key );
		if ( false !== $count && (int) $count >= 5 ) {
			return new \WP_Error(
				'mhbo_rate_limit',
				esc_html( I18n::get_label( 'label_rate_limit_error' ) ),
				array( 'status' => 429 )
			);
		}
		set_transient( $rate_key, (int) $count + 1, 60 );

		// 1. Prepare data for processor and scrub privileged internal keys (Law 3 Anti-Tamper).
		// Preserve guest special requests submitted via legacy `admin_notes` textarea before scrubbing.
		$params = array_merge( $request->get_params(), (array) $request->get_json_params() );
		if ( ! isset( $params['special_requests'] ) && isset( $params['admin_notes'] ) ) {
			$params['special_requests'] = $params['admin_notes'];
		}
		unset(
			$params['status'],
			$params['payment_status'],
			$params['payment_received'],
			$params['deposit_received'],
			$params['total_price'],
			$params['tax_mode'],
			$params['admin_notes'],
			$params['external_id']
		);
		$params['source']      = 'public';
		$params['bypass_past'] = false;

		// 2. Delegate to central processor
		$result = \MHBO\Core\BookingProcessor::process( $params );

		if ( is_wp_error( $result ) ) {
			// Map common error codes to HTTP statuses
			$status = 400;
			switch ( $result->get_error_code() ) {
				case 'mhbo_lock_failed':
					$status = 409;
					break;
				case 'mhbo_unauthorized':
					$status = 403;
					break;
			}
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}

		return rest_ensure_response( $result );
	}

}
