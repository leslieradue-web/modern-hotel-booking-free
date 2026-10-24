<?php
/**
 * Ability: Get Hotel Policies
 *
 * Returns hotel policies (cancellation, check-in/out, pets, smoking, etc.)
 *
 * @package MHBO\AI\Abilities
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Policies {

	private const POLICY_KEYS = array(
		'cancellation',
		'checkin',
		'checkout',
		'pets',
		'smoking',
		'children',
		'payment',
	);

	/**
	 * Return the WP Ability / MCP tool definition.
	 *
	 * @return array<mixed>
	 */
	public static function get_definition(): array {
		return array(
			'label'               => __( 'Get Hotel Policies', 'modern-hotel-booking' ),
			'description'         => __( 'Retrieve hotel policies including cancellation, check-in/out, pets, smoking, and payment terms.', 'modern-hotel-booking' ),
			'category'            => 'hotel-information',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'type' => array(
						'type'        => 'string',
						'enum'        => array_merge( self::POLICY_KEYS, array( 'all' ) ),
						'default'     => 'all',
						'description' => 'Which policy to retrieve.',
					),
				),
				'required'   => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'policies' => array( 'type' => 'object' ),
				),
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
	 * Execute the ability.
	 *
	 * @param array<mixed> $args
	 * @return array<mixed>
	 */
	public static function execute( array $args = array() ): array {
		$type = sanitize_text_field( (string) ( $args['type'] ?? 'all' ) );

		// Map policy key → option name.
		$option_map = array(
			'cancellation' => 'mhbo_cancellation_policy',
			'checkin'      => 'mhbo_checkin_policy',
			'checkout'     => 'mhbo_checkout_policy',
			'pets'         => 'mhbo_pet_policy',
			'smoking'      => 'mhbo_smoking_policy',
			'children'     => 'mhbo_children_policy',
			'payment'      => 'mhbo_payment_policy',
		);

		// Fallback: synthesize checkin/checkout from times if no explicit policy.
		$checkin_time  = (string) get_option( 'mhbo_checkin_time', '14:00' );
		$checkout_time = (string) get_option( 'mhbo_checkout_time', '11:00' );

		$all_policies = array();
		foreach ( $option_map as $key => $option ) {
			$value = (string) get_option( $option, '' );
			if ( '' === $value ) {
				$value = match ( (string) $key ) {
					// translators: %s: check-in time
					'checkin'      => sprintf( __( 'Check-in is from %s.', 'modern-hotel-booking' ), (string) $checkin_time ),
					// translators: %s: check-out time
					'checkout'     => sprintf( __( 'Check-out is by %s.', 'modern-hotel-booking' ), (string) $checkout_time ),
					'payment'      => self::synthesize_payment_policy(),
					'cancellation' => self::synthesize_cancellation_policy(),
					default        => __( 'Please contact the hotel for details.', 'modern-hotel-booking' ),
				};
			}
			$all_policies[ $key ] = $value;
		}

		if ( 'all' === $type || ! in_array( $type, self::POLICY_KEYS, true ) ) {
			return array( 'policies' => $all_policies );
		}

		return array( 'policies' => array( $type => $all_policies[ $type ] ?? '' ) );
	}

	/**
	 * Synthesize payment and deposit terms from active options if no explicit policy is configured.
	 */
	private static function synthesize_payment_policy(): string {
		$deposit_enabled = (bool) get_option( 'mhbo_deposits_enabled', 0 );
		if ( ! $deposit_enabled ) {
			return __( 'Full payment is due at the time of booking or upon check-in.', 'modern-hotel-booking' );
		}
		$type = (string) get_option( 'mhbo_deposit_type', 'percentage' );
		$val  = (float) get_option( 'mhbo_deposit_value', 20 );
		if ( 'first_night' === $type ) {
			return __( 'A deposit equivalent to the first night\'s rate is required at booking.', 'modern-hotel-booking' );
		}

		if ( 'fixed' === $type ) {
			/* translators: %s: formatted deposit amount */
			return sprintf( __( 'A fixed deposit of %s is required at booking.', 'modern-hotel-booking' ), (string) $val );
		}

		/* translators: %d: percentage deposit amount */
		return sprintf( __( 'A %d%% deposit is required at booking.', 'modern-hotel-booking' ), (int) $val );
	}

	/**
	 * Synthesize cancellation rules from deposit settings if no explicit policy is configured.
	 */
	private static function synthesize_cancellation_policy(): string {
		$deposit_enabled = (bool) get_option( 'mhbo_deposits_enabled', 0 );
		if ( ! $deposit_enabled ) {
			return __( 'Standard hotel cancellation terms apply. Please contact the hotel directly for cancellations.', 'modern-hotel-booking' );
		}
		$non_ref = (bool) get_option( 'mhbo_deposit_non_refundable', 0 );
		if ( $non_ref ) {
			return __( 'Deposits are non-refundable. Please contact the hotel directly regarding date changes or cancellations.', 'modern-hotel-booking' );
		}
		$days = (int) get_option( 'mhbo_deposit_refund_deadline_days', 7 );
		if ( $days > 0 ) {
			/* translators: %d: number of days before check-in */
			return sprintf( __( 'Cancellations received up to %d days prior to check-in are eligible for a deposit refund.', 'modern-hotel-booking' ), $days );
		}
		return __( 'Cancellations are eligible for deposit refund prior to arrival. Please contact the hotel for details.', 'modern-hotel-booking' );
	}

	/**
	 * Register as a WordPress Ability (WP 7.0+).
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		call_user_func( 'wp_register_ability', 'mhbo/get-policies', self::get_definition() );
	}
}
