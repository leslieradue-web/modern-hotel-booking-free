<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for Modern Hotel Booking (Guest CRM & Core Operations).
 *
 * @package MHBO\Core
 * @since   2.6.0
 */
class Cli {

	/**
	 * Register WP-CLI commands.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && \WP_CLI && class_exists( \WP_CLI::class ) ) {
			\WP_CLI::add_command( 'mhbo guests migrate', array( self::class, 'migrate_guests' ) );
			\WP_CLI::add_command( 'mhbo guests recalculate', array( self::class, 'recalculate_guests' ) );
			\WP_CLI::add_command( 'mhbo bookings list', array( self::class, 'list_bookings' ) );
			\WP_CLI::add_command( 'mhbo cache flush', array( self::class, 'flush_cache' ) );
			\WP_CLI::add_command( 'mhbo ical sync', array( self::class, 'sync_ical' ) );
		}
	}

	/**
	 * List bookings with optional status or date filtering.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by booking status (confirmed, pending, cancelled, etc.)
	 *
	 * [--date=<date>]
	 * : Filter by check-in date or 'today' (YYYY-MM-DD)
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv, ids, yaml). Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp mhbo bookings list --status=confirmed
	 *     wp mhbo bookings list --date=today --format=json
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 */
	public static function list_bookings( array $args, array $assoc_args ): void {
		global $wpdb;

		$has_status = isset( $assoc_args['status'] ) && '' !== trim( (string) $assoc_args['status'] );
		$has_date   = isset( $assoc_args['date'] ) && '' !== trim( (string) $assoc_args['date'] );

		$status = $has_status ? sanitize_key( (string) $assoc_args['status'] ) : '';
		$date   = '';
		if ( $has_date ) {
			$date = 'today' === $assoc_args['date'] ? wp_date( 'Y-m-d' ) : sanitize_text_field( (string) $assoc_args['date'] );
		}

		if ( $has_status && $has_date ) {
			$sql = $wpdb->prepare(
				"SELECT id, customer_name, customer_email, check_in, check_out, total_price, status, payment_status FROM {$wpdb->prefix}mhbo_bookings WHERE status = %s AND (check_in <= %s AND check_out >= %s) ORDER BY id DESC LIMIT 100",
				$status,
				$date,
				$date
			);
		} elseif ( $has_status ) {
			$sql = $wpdb->prepare(
				"SELECT id, customer_name, customer_email, check_in, check_out, total_price, status, payment_status FROM {$wpdb->prefix}mhbo_bookings WHERE status = %s ORDER BY id DESC LIMIT 100",
				$status
			);
		} elseif ( $has_date ) {
			$sql = $wpdb->prepare(
				"SELECT id, customer_name, customer_email, check_in, check_out, total_price, status, payment_status FROM {$wpdb->prefix}mhbo_bookings WHERE (check_in <= %s AND check_out >= %s) ORDER BY id DESC LIMIT 100",
				$date,
				$date
			);
		} else {
			$sql = "SELECT id, customer_name, customer_email, check_in, check_out, total_price, status, payment_status FROM {$wpdb->prefix}mhbo_bookings WHERE 1=1 ORDER BY id DESC LIMIT 100";
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- CLI utility query safely prepared with explicit placeholders above
		$bookings = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $bookings ) || array() === $bookings ) {
			\WP_CLI::log( 'No bookings found matching criteria.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		\WP_CLI\Utils\format_items( $format, $bookings, array( 'id', 'customer_name', 'customer_email', 'check_in', 'check_out', 'total_price', 'status', 'payment_status' ) );
	}

	/**
	 * Flush all MHBO transient and query caches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mhbo cache flush
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 */
	public static function flush_cache( array $args, array $assoc_args ): void {
		Cache::flush_all();
		\WP_CLI::success( 'All Modern Hotel Booking caches and dashboard transients flushed successfully.' );
	}

	/**
	 * Trigger iCal synchronization across external calendars.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Force sync all connections
	 *
	 * ## EXAMPLES
	 *
	 *     wp mhbo ical sync --all
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 */
	public static function sync_ical( array $args, array $assoc_args ): void {

\WP_CLI::log( 'iCal multi-feed automated synchronization is a Pro feature.' );
	}

	/**
	 * Migrate unlinked historical bookings into normalized Guest CRM records.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<int>]
	 * : Number of records to process per batch (default: 250).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mhbo guests migrate --batch-size=500
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 * @return void
	 */
	public static function migrate_guests( array $args, array $assoc_args ): void {
		$batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 250;
		$unmigrated = GuestMigration::get_unmigrated_count();

		\WP_CLI::log( sprintf( 'Starting Guest CRM migration. Found %d unlinked booking records.', $unmigrated ) );

		if ( $unmigrated === 0 ) {
			\WP_CLI::success( 'All bookings are already linked to guest profiles.' );
			return;
		}

		$progress        = \WP_CLI\Utils\make_progress_bar( 'Migrating bookings', $unmigrated );
		$last_id         = 0;
		$total_processed = 0;

		do {
			$result    = GuestMigration::migrate_batch( $last_id, $batch_size );
			$processed = (int) ( $result['processed'] ?? 0 );
			$last_id   = (int) ( $result['last_id'] ?? 0 );
			$has_more  = (bool) ( $result['has_more'] ?? false );

			$total_processed += $processed;
			$progress->tick( $processed );
		} while ( $has_more && $processed > 0 );

		$progress->finish();
		\WP_CLI::success( sprintf( 'Successfully migrated %d bookings into normalized guest profiles.', $total_processed ) );
	}

	/**
	 * Recalculate lifetime stays and spend projection for guests.
	 *
	 * ## OPTIONS
	 *
	 * [--guest-id=<int>]
	 * : Specific Guest ID to recalculate. If omitted, recalculates all active guests.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mhbo guests recalculate --guest-id=42
	 *     wp mhbo guests recalculate
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc_args
	 * @return void
	 */
	public static function recalculate_guests( array $args, array $assoc_args ): void {
		global $wpdb;

		if ( isset( $assoc_args['guest-id'] ) ) {
			$guest_id = (int) $assoc_args['guest-id'];
			$stats    = GuestStatsService::recalculate_guest_stats( $guest_id );
			\WP_CLI::success(
				sprintf(
					'Recalculated Guest #%d: %d stays, %s total spend, VIP: %s',
					$guest_id,
					$stats['total_stays'],
					$stats['total_spend'],
					$stats['vip_status']
				)
			);
			return;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$guest_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}mhbo_guests WHERE status = 'active'" );
		$total     = count( $guest_ids );

		\WP_CLI::log( sprintf( 'Recalculating stats for %d guests...', $total ) );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Updating stats', $total );

		foreach ( $guest_ids as $gid ) {
			GuestStatsService::recalculate_guest_stats( (int) $gid );
			$progress->tick();
		}

		$progress->finish();
		\WP_CLI::success( 'All guest statistics recalculated successfully.' );
	}
}
