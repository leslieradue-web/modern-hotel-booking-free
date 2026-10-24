<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cursor-based, idempotent historical booking migration to normalized Guest CRM.
 *
 * @package MHBO\Core
 * @since   2.6.0
 */
class GuestMigration {

	/**
	 * Process a cursor-based batch of unmigrated historical bookings.
	 *
	 * @param int $last_processed_id
	 * @param int $batch_size
	 * @return array<string, mixed>
	 */
	public static function migrate_batch( int $last_processed_id = 0, int $batch_size = 250 ): array {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$bookings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, customer_name, customer_email, customer_phone, status, payment_status, payment_amount, total_price
                 FROM {$wpdb->prefix}mhbo_bookings
                 WHERE id > %d AND (guest_id IS NULL OR guest_id = 0) AND customer_email IS NOT NULL AND customer_email != ''
                 ORDER BY id ASC
                 LIMIT %d",
				$last_processed_id,
				$batch_size
			)
		);

		if ( array() === $bookings ) {
			return array(
				'processed' => 0,
				'last_id'   => $last_processed_id,
				'has_more'  => false,
			);
		}

		$processed_count = 0;
		$max_id          = $last_processed_id;
		$affected_guests = array();

		foreach ( $bookings as $booking ) {
			$booking_id = (int) $booking->id;
			$max_id     = max( $max_id, $booking_id );

			$booking_data = array(
				'customer_name'  => $booking->customer_name,
				'customer_email' => $booking->customer_email,
				'customer_phone' => $booking->customer_phone,
			);

			$guest_id = GuestIdentityResolver::resolve_and_link_guest( $booking_data );

			if ( $guest_id > 0 ) {
				// Link booking to resolved guest
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'mhbo_bookings',
					array( 'guest_id' => $guest_id ),
					array( 'id' => $booking_id )
				);
				Cache::invalidate_booking( $booking_id );

				$affected_guests[ $guest_id ] = true;
				++$processed_count;
			}
		}

		// Recalculate projections for all touched guests
		foreach ( array_keys( $affected_guests ) as $gid ) {
			GuestStatsService::recalculate_guest_stats( (int) $gid );
		}

		$has_more = count( $bookings ) >= $batch_size;

		if ( $has_more ) {
			GuestAsyncTasks::queue_migrate_batch( $max_id, $batch_size );
		}

		return array(
			'processed' => $processed_count,
			'last_id'   => $max_id,
			'has_more'  => $has_more,
		);
	}

	/**
	 * Count remaining unlinked bookings.
	 *
	 * @return int
	 */
	public static function get_unmigrated_count(): int {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) 
             FROM {$wpdb->prefix}mhbo_bookings 
             WHERE (guest_id IS NULL OR guest_id = 0) 
               AND customer_email IS NOT NULL 
               AND customer_email != ''"
		);
	}
}
