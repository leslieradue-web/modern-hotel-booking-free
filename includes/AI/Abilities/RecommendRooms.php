<?php

/**
 * Ability: Recommend Rooms
 *
 * Recommends optimal room types based on stay dates, guest count, budget constraints,
 * and preferred amenities, returning ranked options with match explanations.
 *
 * @package MHBO\AI\Abilities
 * @since   2.6.0
 */

declare(strict_types=1);

namespace MHBO\AI\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\Pricing;
use MHBO\Core\HotelTime;

use function __;
use function absint;
use function floatval;
use function sanitize_text_field;
use function strtotime;
use function count;
use function is_array;
use function usort;
use function gmdate;
use function array_values;

class RecommendRooms {

	/**
	 * Return the WP Ability / MCP tool definition.
	 *
	 * @return array<mixed>
	 */
	public static function get_definition(): array {
		$properties = array(
			'check_in'            => array(
				'type'        => 'string',
				'format'      => 'date',
				'description' => 'Check-in date (YYYY-MM-DD).',
			),
			'check_out'           => array(
				'type'        => 'string',
				'format'      => 'date',
				'description' => 'Check-out date (YYYY-MM-DD).',
			),
			'adults'              => array(
				'type'        => 'integer',
				'default'     => 2,
				'minimum'     => 1,
				'description' => 'Number of adults.',
			),
			'children'            => array(
				'type'        => 'integer',
				'default'     => 0,
				'minimum'     => 0,
				'description' => 'Number of children.',
			),
			'max_budget'          => array(
				'type'        => 'number',
				'description' => 'Optional maximum budget cap in hotel currency.',
			),
			'preferred_amenities' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Optional list of preferred amenities (e.g. WiFi, Sea View, Balcony).',
			),
		);

return array(
			'label'               => __( 'Recommend Rooms', 'modern-hotel-booking' ),
			'description'         => 'Find and rank the best room options for a stay based on dates, guest count, budget, and amenity preferences.',
			'category'            => 'booking-management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => $properties,
				'required'   => array( 'check_in', 'check_out' ),
			),
			'output_schema'       => array(
				'type' => 'object',
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
	 * Execute room recommendation logic.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function execute( array $args ): array {
		$check_in_raw  = sanitize_text_field( (string) ( $args['check_in'] ?? '' ) );
		$check_out_raw = sanitize_text_field( (string) ( $args['check_out'] ?? '' ) );

		if ( '' === $check_in_raw || '' === $check_out_raw ) {
			return array(
				'error'   => true,
				'message' => 'Both check_in and check_out dates are required.',
			);
		}

		$t_in  = strtotime( $check_in_raw );
		$t_out = strtotime( $check_out_raw );

		if ( ! $t_in || ! $t_out || $t_in >= $t_out ) {
			return array(
				'error'   => true,
				'message' => 'Invalid date range provided.',
			);
		}

		$adults   = absint( $args['adults'] ?? 2 );
		$children = absint( $args['children'] ?? 0 );
		$budget   = isset( $args['max_budget'] ) ? floatval( $args['max_budget'] ) : 0.0;

		$child_ages = array();

// Call Availability search
		$avail = CheckAvailability::execute(
			array(
				'check_in'   => $check_in_raw,
				'check_out'  => $check_out_raw,
				'adults'     => $adults,
				'children'   => $children,
				'child_ages' => $child_ages,
			)
		);

		if ( ! isset( $avail['available'] ) || ! $avail['available'] || ! isset( $avail['rooms'] ) || array() === $avail['rooms'] ) {
			return array(
				'recommendations_found' => false,
				'message'               => $avail['message'] ?? 'No room options available for the specified dates and guest count.',
				'recommendations'       => array(),
			);
		}

		$recommendations = array();
		$total_guests    = $adults + $children;

		foreach ( $avail['rooms'] as $room ) {
			$total_price = floatval( $room['total_price'] ?? 0 );

			// Budget filter
			if ( $budget > 0.0 && $total_price > $budget ) {
				continue;
			}

			// Calculate match score
			$capacity  = absint( $room['max_occupancy'] ?? $room['capacity'] ?? $total_guests );
			$fit_score = 100;

			// Ideal capacity match
			$diff = $capacity - $total_guests;
			if ( $diff < 0 ) {
				continue; // Cannot fit
			}
			$fit_score -= ( $diff * 10 ); // Slightly penalize overly large rooms for small groups

			$why = sprintf( 'Fits %d guests comfortably with a total stay price of %s %s.', $total_guests, number_format( $total_price, 2 ), $avail['currency'] ?? 'RON' );

			$recommendations[] = array(
				'room_type_id'    => $room['type_id'] ?? $room['id'] ?? 0,
				'room_name'       => $room['name'] ?? 'Standard Room',
				'total_price'     => $total_price,
				'price_per_night' => $room['price_per_night'] ?? 0,
				'currency'        => $avail['currency'] ?? 'RON',
				'max_capacity'    => $capacity,
				'match_score'     => $fit_score,
				'why_recommended' => $why,
			);
		}

		// Sort recommendations by match_score descending, then price ascending
		usort(
			$recommendations,
			function ( $a, $b ) {
				if ( $a['match_score'] === $b['match_score'] ) {
					return $a['total_price'] <=> $b['total_price'];
				}
				return $b['match_score'] <=> $a['match_score'];
			}
		);

		// Add 1-based rank
		$ranked = array();
		$rank   = 1;
		foreach ( $recommendations as $item ) {
			$item['rank'] = $rank++;
			$ranked[]     = $item;
		}

		return array(
			'recommendations_found' => count( $ranked ) > 0,
			'count'                 => count( $ranked ),
			'check_in'              => $check_in_raw,
			'check_out'             => $check_out_raw,
			'guests'                => array(
				'adults'   => $adults,
				'children' => $children,
			),
			'recommendations'       => $ranked,
		);
	}

	/**
	 * WP 7.0 native ability registration.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		call_user_func( 'wp_register_ability', 'mhbo/recommend-rooms', self::get_definition() );
	}
}
