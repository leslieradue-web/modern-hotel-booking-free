<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles non-blocking asynchronous task execution for CRM rollups and migrations.
 * Uses Action Scheduler when available with graceful WP-Cron fallback.
 *
 * @package MHBO\Core
 * @since   2.6.0
 */
class GuestAsyncTasks {

	/**
	 * Initialize asynchronous action hooks.
	 */
	public static function init(): void {
		add_action( 'mhbo_recalculate_guest_stats', array( self::class, 'handle_recalculate_stats' ), 10, 1 );
		add_action( 'mhbo_migrate_guests_batch', array( self::class, 'handle_migrate_batch' ), 10, 2 );
		add_action( 'mhbo_booking_created', array( self::class, 'on_booking_updated' ), 10, 1 );
		add_action( 'mhbo_booking_status_changed', array( self::class, 'on_booking_updated' ), 10, 1 );
		add_action( 'mhbo_balance_collected', array( self::class, 'on_booking_updated' ), 10, 1 );
		add_action( 'mhbo_booking_cancelled', array( self::class, 'on_booking_updated' ), 10, 1 );
		add_action( 'mhbo_booking_confirmed', array( self::class, 'on_booking_updated' ), 10, 1 );
	}

	/**
	 * Automatically trigger guest stats recalculation when a booking is modified.
	 *
	 * @param int $booking_id
	 * @return void
	 */
	public static function on_booking_updated( int $booking_id ): void {
		if ( $booking_id <= 0 || ! GuestManager::is_enabled() ) {
			return;
		}

		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, guest_id, customer_name, customer_email, customer_phone FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
				$booking_id
			)
		);

		if ( ! $booking ) {
			return;
		}

		$guest_id = (int) ( $booking->guest_id ?? 0 );

		// Self-healing: Resolve and link guest if missing but customer email is present
		if ( $guest_id <= 0 && ! empty( $booking->customer_email ) && 'guest@pending.local' !== $booking->customer_email ) {
			$guest_id = GuestIdentityResolver::resolve_and_link_guest(
				array(
					'customer_name'  => $booking->customer_name ?? '',
					'customer_email' => $booking->customer_email,
					'customer_phone' => $booking->customer_phone ?? '',
				)
			);

			if ( $guest_id > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'mhbo_bookings',
					array( 'guest_id' => $guest_id ),
					array( 'id' => $booking_id )
				);
				Cache::invalidate_booking( $booking_id );
			}
		}

		if ( $guest_id > 0 ) {
			self::queue_stats_recalculation( $guest_id );
		}
	}

	/**
	 * Enqueue an asynchronous stats recalculation job.
	 *
	 * @param int $guest_id
	 * @return void
	 */
	public static function queue_stats_recalculation( int $guest_id ): void {
		if ( $guest_id <= 0 || ! GuestManager::is_enabled() ) {
			return;
		}

		// Instant synchronous calculation for zero-lag CRM accuracy
		GuestStatsService::recalculate_guest_stats( $guest_id );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			\as_enqueue_async_action(
				'mhbo_recalculate_guest_stats',
				array( 'guest_id' => $guest_id ),
				'mhbo-crm'
			);
		}
	}

	/**
	 * Action handler for recalculating guest statistics.
	 *
	 * @param int $guest_id
	 * @return void
	 */
	public static function handle_recalculate_stats( int $guest_id ): void {
		if ( $guest_id > 0 ) {
			GuestStatsService::recalculate_guest_stats( $guest_id );
		}
	}

	/**
	 * Enqueue a batch migration job for historical bookings.
	 *
	 * @param int $last_id
	 * @param int $batch_size
	 * @return void
	 */
	public static function queue_migrate_batch( int $last_id = 0, int $batch_size = 250 ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			\as_enqueue_async_action(
				'mhbo_migrate_guests_batch',
				array(
					'last_id'    => $last_id,
					'batch_size' => $batch_size,
				),
				'mhbo-crm-migration'
			);
		} else {
			wp_schedule_single_event(
				time() + 2,
				'mhbo_migrate_guests_batch',
				array(
					$last_id,
					$batch_size,
				)
			);
		}
	}

	/**
	 * Action handler for batch historical bookings migration.
	 *
	 * @param int $last_id
	 * @param int $batch_size
	 * @return void
	 */
	public static function handle_migrate_batch( int $last_id = 0, int $batch_size = 250 ): void {
		GuestMigration::migrate_batch( $last_id, $batch_size );
	}
}
