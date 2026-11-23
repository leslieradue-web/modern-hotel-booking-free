<?php declare(strict_types=1);

namespace MHBO\Api;

use MHBO\Core\HotelTime;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Security;
use MHBO\Frontend\Shortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for the inline booking modal.
 *
 * GET /mhbo/v1/modal/booking-form    — returns form HTML for a specific room + dates.
 * GET /mhbo/v1/modal/confirmation    — returns confirmation panel HTML for a completed booking.
 */
class ModalApi {

	public function register_routes(): void {
		register_rest_route(
			'mhbo/v1',
			'/modal/booking-form',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_booking_form' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'room_id'        => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'check_in'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'check_out'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'guests'         => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 2,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'children'       => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'child_ages'     => array(
						'required'          => false,
						'type'              => 'array',
						'default'           => array(),
						'items'             => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
					'total_price'    => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '0',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page_url'       => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'esc_url_raw',
					),
					'customer_name'  => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'customer_email' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_email',
					),
					'customer_phone' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'mhbo/v1',
			'/modal/confirmation',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_confirmation' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'booking_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'        => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'confirmed',
						'enum'              => array( 'confirmed', 'pending', 'failed' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Return the booking form HTML for injection into the modal.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_booking_form( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ip       = Security::get_client_ip();
		$rate_key = 'mhbo_modal_form_rl_' . md5( $ip );
		$rl       = Security::consume_fixed_window_rate_limit( $rate_key, 60, MINUTE_IN_SECONDS );
		if ( ! $rl['allowed'] ) {
			return new \WP_Error( 'mhbo_rate_limit', __( 'Too many requests. Please wait a moment and try again.', 'modern-hotel-booking' ), array( 'status' => 429 ) );
		}

		// Validate date format, chronological ordering, max-stay nights, and advance window.
		$check_in        = sanitize_text_field( (string) $request->get_param( 'check_in' ) );
		$check_out       = sanitize_text_field( (string) $request->get_param( 'check_out' ) );
		$date_validation = HotelTime::validate_date_range( $check_in, $check_out, true );
		if ( is_wp_error( $date_validation ) ) {
			return $date_validation;
		}

		$room_id        = (int) $request->get_param( 'room_id' );
		$guests         = max( 1, (int) $request->get_param( 'guests' ) );
		$children       = max( 0, (int) $request->get_param( 'children' ) );
		$child_ages     = Pricing::sanitize_child_ages( $request->get_param( 'child_ages' ) );
		$page_url       = (string) $request->get_param( 'page_url' );
		$customer_name  = (string) $request->get_param( 'customer_name' );
		$customer_email = (string) $request->get_param( 'customer_email' );
		$customer_phone = (string) $request->get_param( 'customer_phone' );

		$raw_price   = (float) $request->get_param( 'total_price' );
		$currency    = Pricing::get_currency_code();
		$total_money = Money::fromDecimal( (string) $raw_price, $currency );
		if ( $room_id > 0 ) {
			$calc = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, $guests, array(), $children, $child_ages );
			if ( is_array( $calc ) && isset( $calc['total'] ) && $calc['total'] instanceof Money ) {
				$total_money = $calc['total'];
			}
		}

		$shortcode = new Shortcode();
		$html      = $shortcode->render_booking_form_html(
			array(
				'room_id'        => $room_id,
				'type_id'        => 0,
				'check_in'       => $check_in,
				'check_out'      => $check_out,
				'guests'         => $guests,
				'children'       => $children,
				'child_ages'     => $child_ages,
				'total_price'    => $total_money,
				'page_url'       => $page_url,
				'customer_name'  => $customer_name,
				'customer_email' => $customer_email,
				'customer_phone' => $customer_phone,
			)
		);

		if ( '' === $html ) {
			return new \WP_Error( 'mhbo_render_failed', __( 'Unable to render booking form.', 'modern-hotel-booking' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response(
			array(
				'html'  => $html,
				'nonce' => wp_create_nonce( 'mhbo_confirm_action' ),
			),
			200
		);
	}

	/**
	 * Return the confirmation panel HTML for a completed booking.
	 * The booking_token is the security credential — no additional nonce needed.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_confirmation( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ip       = Security::get_client_ip();
		$rate_key = 'mhbo_modal_conf_rl_' . md5( $ip );
		$rl       = Security::consume_fixed_window_rate_limit( $rate_key, 60, MINUTE_IN_SECONDS );
		if ( ! $rl['allowed'] ) {
			return new \WP_Error( 'mhbo_rate_limit', __( 'Too many requests. Please wait a moment and try again.', 'modern-hotel-booking' ), array( 'status' => 429 ) );
		}

		$token  = (string) $request->get_param( 'booking_token' );
		$status = (string) $request->get_param( 'status' );

		if ( '' === $token ) {
			return new \WP_Error( 'mhbo_missing_token', __( 'Booking token is required.', 'modern-hotel-booking' ), array( 'status' => 400 ) );
		}

		$shortcode = new Shortcode();
		$html      = $shortcode->render_confirmation_html( $token, $status );

		if ( '' === $html ) {
			return new \WP_Error( 'mhbo_not_found', __( 'Booking not found.', 'modern-hotel-booking' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( array( 'html' => $html ), 200 );
	}
}
