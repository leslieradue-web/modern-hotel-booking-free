<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes guest identities and resolves bookings to guest profiles
 * with concurrency protection and shared-mailbox safeguards.
 *
 * @package MHBO\Core
 * @since   2.6.0
 */
class GuestIdentityResolver {

	/**
	 * Normalize an email address for canonical comparison.
	 * Preserves internationalized valid UTF-8 characters without destruction.
	 *
	 * @param string $email
	 * @return string
	 */
	public static function normalize_email( string $email ): string {
		$email = trim( $email );
		if ( function_exists( 'mb_strtolower' ) ) {
			$email = mb_strtolower( $email, 'UTF-8' );
		} else {
			$email = strtolower( $email );
		}
		return sanitize_email( $email );
	}

	/**
	 * Normalize a phone number to standard E.164-like digits representation.
	 *
	 * @param string $phone
	 * @return string
	 */
	public static function normalize_phone( string $phone ): string {
		$phone = trim( $phone );
		if ( '' === $phone ) {
			return '';
		}

		$has_plus = str_starts_with( $phone, '+' );
		$digits   = (string) preg_replace( '/[^\d]/', '', $phone );

		if ( '' === $digits ) {
			return '';
		}

		return $has_plus ? '+' . $digits : $digits;
	}

	/**
	 * Resolves or creates a guest profile for a booking under a MySQL named advisory lock.
	 *
	 * @param array<string, mixed> $booking_data
	 * @return int Guest ID (or 0 if email cannot be resolved)
	 */
	public static function resolve_and_link_guest( array $booking_data ): int {
		global $wpdb;

		$raw_email        = (string) ( $booking_data['customer_email'] ?? '' );
		$email_normalized = self::normalize_email( $raw_email );

		if ( '' === $email_normalized ) {
			return 0;
		}

		$lock_name = 'mhbo_guest_' . md5( $email_normalized );

		// Acquire MySQL Named Advisory Lock with 5s timeout
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$lock_acquired = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name )
		);

		try {
			$first_name = sanitize_text_field( (string) ( $booking_data['first_name'] ?? '' ) );
			$last_name  = sanitize_text_field( (string) ( $booking_data['last_name'] ?? '' ) );

			if ( '' === $first_name && isset( $booking_data['customer_name'] ) && '' !== trim( (string) $booking_data['customer_name'] ) ) {
				$parts      = explode( ' ', trim( (string) $booking_data['customer_name'] ), 2 );
				$first_name = $parts[0] ?? '';
				$last_name  = $parts[1] ?? '';
			}

			$raw_phone        = (string) ( $booking_data['customer_phone'] ?? '' );
			$phone_normalized = self::normalize_phone( $raw_phone );

			// Lookup existing active profiles with this normalized email
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}mhbo_guests WHERE email_normalized = %s AND status = 'active' ORDER BY id ASC",
					$email_normalized
				)
			);

			if ( array() !== $candidates ) {
				foreach ( $candidates as $candidate ) {
					if ( self::is_compatible_identity( $candidate, $first_name, $last_name ) ) {
						// Compatible identity: update missing phone if available
						if ( '' === (string) ( $candidate->phone ?? '' ) && '' !== $raw_phone ) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
							$wpdb->update(
								$wpdb->prefix . 'mhbo_guests',
								array(
									'phone'            => sanitize_text_field( $raw_phone ),
									'phone_normalized' => $phone_normalized,
								),
								array( 'id' => (int) $candidate->id )
							);
							wp_cache_delete( 'mhbo_guest_' . $candidate->id, 'mhbo_guests' );
							Cache::invalidate_table( 'guests' );
						}
						return (int) $candidate->id;
					}
				}

				// Shared mailbox with different person: create distinct profile & flag suggested merge
				$new_guest_id = self::insert_guest(
					$first_name,
					$last_name,
					$raw_email,
					$email_normalized,
					$raw_phone,
					$phone_normalized
				);

				self::flag_suggested_merge( $new_guest_id, (int) $candidates[0]->id, 'shared_email_different_name' );
				return $new_guest_id;
			}

			// No existing guest: create new profile
			return self::insert_guest(
				$first_name,
				$last_name,
				$raw_email,
				$email_normalized,
				$raw_phone,
				$phone_normalized
			);
		} finally {
			if ( 1 === $lock_acquired ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
	}

	/**
	 * Determines whether an incoming booking name is compatible with an existing profile.
	 *
	 * @param object $candidate
	 * @param string $first_name
	 * @param string $last_name
	 * @return bool
	 */
	public static function is_compatible_identity( object $candidate, string $first_name, string $last_name ): bool {
		$cand_first = trim( (string) ( $candidate->first_name ?? '' ) );
		$cand_last  = trim( (string) ( $candidate->last_name ?? '' ) );

		// If either name record is empty, assume compatible
		if ( '' === $cand_first || '' === $first_name ) {
			return true;
		}

		$cand_full = strtolower( $cand_first . ' ' . $cand_last );
		$new_full  = strtolower( $first_name . ' ' . $last_name );

		if ( $cand_full === $new_full ) {
			return true;
		}

		// Compare first names
		$first_match = strtolower( $cand_first ) === strtolower( $first_name );
		$last_match  = strtolower( $cand_last ) === strtolower( $last_name );

		if ( $first_match && $last_match ) {
			return true;
		}

		// Levenshtein similarity check for minor typos (e.g. Michael vs Micheal)
		if ( levenshtein( strtolower( $cand_full ), strtolower( $new_full ) ) <= 3 ) {
			return true;
		}

		return false;
	}

	/**
	 * Insert a new guest record.
	 *
	 * @param string $first_name
	 * @param string $last_name
	 * @param string $email
	 * @param string $email_normalized
	 * @param string $phone
	 * @param string $phone_normalized
	 * @return int Inserted Guest ID
	 */
	public static function insert_guest(
		string $first_name,
		string $last_name,
		string $email,
		string $email_normalized,
		string $phone,
		string $phone_normalized
	): int {
		global $wpdb;

		$table = $wpdb->prefix . 'mhbo_guests';
		$data  = array(
			'first_name'       => sanitize_text_field( $first_name ),
			'last_name'        => sanitize_text_field( $last_name ),
			'email'            => sanitize_email( $email ),
			'email_normalized' => $email_normalized,
			'phone'            => sanitize_text_field( $phone ),
			'phone_normalized' => $phone_normalized,
			'vip_status'       => 'none',
			'caution_flag'     => 0,
			'total_stays'      => 0,
			'total_spend'      => '0.0000',
			'status'           => 'active',
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $table, $data );

		if ( false === $inserted ) {
			return 0;
		}

		$guest_id = (int) $wpdb->insert_id;
		Cache::invalidate_table( 'guests' );
		return $guest_id;
	}

	/**
	 * Record a suggested merge candidate for administrator review.
	 *
	 * @param int    $guest_a
	 * @param int    $guest_b
	 * @param string $reason
	 * @return void
	 */
	public static function flag_suggested_merge( int $guest_a, int $guest_b, string $reason ): void {
		if ( $guest_a <= 0 || $guest_b <= 0 || $guest_a === $guest_b ) {
			return;
		}

		$merges = get_option( 'mhbo_suggested_merges', array() );
		if ( ! is_array( $merges ) ) {
			$merges = array();
		}

		$key            = min( $guest_a, $guest_b ) . '_' . max( $guest_a, $guest_b );
		$merges[ $key ] = array(
			'guest_a'    => min( $guest_a, $guest_b ),
			'guest_b'    => max( $guest_a, $guest_b ),
			'reason'     => sanitize_key( $reason ),
			'created_at' => current_time( 'mysql' ),
		);

		update_option( 'mhbo_suggested_merges', $merges, false );
	}
}
