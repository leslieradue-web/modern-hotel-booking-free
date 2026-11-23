<?php declare(strict_types=1);

namespace MHBO\Database\Queries;

use MHBO\Core\Cache;
use MHBO\Core\Money;
use MHBO\Core\Pricing;

/**
 * Booking_Query
 *
 * Centralized query handler for MHBO Bookings to resolve N+1 performance issues
 * and provide a clean data access layer compliant with 2026 standards.
 *
 * @package MHBO\Database\Queries
 * @since 2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Query {

	/**
	 * Build SQL WHERE fragment excluding uncompleted provisional checkout drafts.
	 *
	 * Provisional checkout rows (status = 'pending_payment' or guest@pending.local)
	 * that have zero payment evidence are abandoned/in-flight gateway drafts and
	 * must not appear as bookings in admin lists, KPIs, or calendars.
	 *
	 * @param string $alias Table alias ('b' or '' for unaliased queries).
	 * @return literal-string SQL condition starting with ' AND NOT (...)'.
	 */
	public static function get_completed_draft_exclusion_sql( string $alias = 'b' ): string {
		if ( '' === $alias ) {
			return " AND NOT ( ( status = 'pending_payment' OR LOWER(COALESCE(customer_email, '')) = 'guest@pending.local' ) AND COALESCE(payment_status, 'pending') = 'pending' AND COALESCE(deposit_received, 0) = 0 AND COALESCE(payment_received, 0) = 0 AND COALESCE(payment_amount, 0) <= 0 AND COALESCE(payment_capture_id, '') = '' AND COALESCE(balance_collected_via, '') = '' )";
		}

		return " AND NOT ( ( b.status = 'pending_payment' OR LOWER(COALESCE(b.customer_email, '')) = 'guest@pending.local' ) AND COALESCE(b.payment_status, 'pending') = 'pending' AND COALESCE(b.deposit_received, 0) = 0 AND COALESCE(b.payment_received, 0) = 0 AND COALESCE(b.payment_amount, 0) <= 0 AND COALESCE(b.payment_capture_id, '') = '' AND COALESCE(b.balance_collected_via, '') = '' )";
	}

	/**
	 * Get recent bookings with room details in a single JOIN.
	 *
	 * @param int $limit Number of bookings to retrieve.
	 * @return list<\stdClass> List of booking objects.
	 */
	public static function get_recent( int $limit = 5 ): array {
		global $wpdb;

		/** @var list<\stdClass>|null $rows */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; %i handles identifier escaping with a compile-time literal SQL template.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, r.room_number as room_name
				 FROM %i b
				 LEFT JOIN %i r ON b.room_id = r.id
				 WHERE 1=1
				   AND NOT ( ( b.status = 'pending_payment' OR LOWER(COALESCE(b.customer_email, '')) = 'guest@pending.local' ) AND COALESCE(b.payment_status, 'pending') = 'pending' AND COALESCE(b.deposit_received, 0) = 0 AND COALESCE(b.payment_received, 0) = 0 AND COALESCE(b.payment_amount, 0) <= 0 AND COALESCE(b.payment_capture_id, '') = '' AND COALESCE(b.balance_collected_via, '') = '' )
				 ORDER BY b.created_at DESC
				 LIMIT %d",
				$wpdb->prefix . 'mhbo_bookings',
				$wpdb->prefix . 'mhbo_rooms',
				max( 1, $limit )
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch aggregated operational KPI metrics for dashboards and front-desk snapshots.
	 *
	 * Consolidates room occupancy, arrival/departure counters, status totals, and pipeline
	 * revenue into a single literal prepared query using WP 6.2+ identifier placeholders.
	 *
	 * @param string $today_ymd Hotel local date in Y-m-d format.
	 * @return array{
	 *     occupied_rooms: int,
	 *     arrivals: int,
	 *     departures: int,
	 *     in_house: int,
	 *     total_bookings: int,
	 *     pending_count: int,
	 *     confirmed_count: int,
	 *     confirmed_revenue: float,
	 *     total_pipeline: float,
	 *     total_collected: float
	 * }
	 */
	public static function get_operational_metrics( string $today_ymd ): array {
		global $wpdb;

		$cache_key = 'ops_metrics_' . $today_ymd;
		if ( class_exists( Cache::class ) ) {
			$cached = Cache::get_query( $cache_key, Cache::TABLE_BOOKINGS );
			if ( is_array( $cached ) ) {
				/**
				 * @var array{
				 *     occupied_rooms: int,
				 *     arrivals: int,
				 *     departures: int,
				 *     in_house: int,
				 *     total_bookings: int,
				 *     pending_count: int,
				 *     confirmed_count: int,
				 *     confirmed_revenue: float,
				 *     total_pipeline: float,
				 *     total_collected: float
				 * } $cached
				 */
				return $cached;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; operational dashboard aggregates use live database state and %i identifier escaping.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(DISTINCT CASE WHEN status NOT IN ('cancelled', 'refunded', 'deleted') AND check_in <= DATE(%s) AND check_out > DATE(%s) THEN room_id END) AS occupied_rooms,
					COALESCE(SUM(CASE WHEN status NOT IN ('cancelled', 'refunded', 'deleted') AND check_in = DATE(%s) THEN 1 ELSE 0 END), 0) AS arrivals,
					COALESCE(SUM(CASE WHEN status NOT IN ('cancelled', 'refunded', 'deleted') AND check_out = DATE(%s) THEN 1 ELSE 0 END), 0) AS departures,
					COALESCE(SUM(CASE WHEN status NOT IN ('cancelled', 'refunded', 'deleted') AND check_in <= DATE(%s) AND check_out > DATE(%s) THEN 1 ELSE 0 END), 0) AS in_house,
					COUNT(*) AS total_bookings,
					COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
					COALESCE(SUM(CASE WHEN status IN ('confirmed', 'checked_in', 'checked_out', 'completed', 'paid') THEN 1 ELSE 0 END), 0) AS confirmed_count,
					COALESCE(SUM(CASE WHEN status IN ('confirmed', 'checked_in', 'checked_out', 'completed', 'paid') THEN COALESCE(NULLIF(total_gross, 0), total_price) ELSE 0 END), 0) AS confirmed_revenue,
					COALESCE(SUM(CASE WHEN status NOT IN ('cancelled', 'refunded', 'deleted') THEN COALESCE(NULLIF(total_gross, 0), total_price) ELSE 0 END), 0) AS total_pipeline,
					COALESCE(SUM(CASE
						WHEN status NOT IN ('cancelled', 'refunded', 'deleted') THEN (
							CASE
								WHEN payment_status = 'refunded' THEN 0
								WHEN payment_amount IS NOT NULL AND payment_amount > 0 THEN payment_amount
								WHEN payment_received = 1 OR payment_status IN ('completed', 'paid') THEN COALESCE(NULLIF(total_gross, 0), total_price)
								WHEN (deposit_received = 1 OR payment_status = 'deposit_paid') AND deposit_amount > 0 THEN deposit_amount
								ELSE 0
							END
						)
						ELSE 0
					END), 0) AS total_collected,
					COALESCE(SUM(CASE WHEN status NOT IN ('cancelled', 'refunded', 'deleted') AND payment_ledger IS NOT NULL AND payment_ledger != '' THEN 1 ELSE 0 END), 0) AS ledger_count
				 FROM %i
				 WHERE NOT ( ( status = 'pending_payment' OR LOWER(COALESCE(customer_email, '')) = 'guest@pending.local' ) AND COALESCE(payment_status, 'pending') = 'pending' AND COALESCE(deposit_received, 0) = 0 AND COALESCE(payment_received, 0) = 0 AND COALESCE(payment_amount, 0) <= 0 AND COALESCE(payment_capture_id, '') = '' AND COALESCE(balance_collected_via, '') = '' )",
				$today_ymd,
				$today_ymd,
				$today_ymd,
				$today_ymd,
				$today_ymd,
				$today_ymd,
				$wpdb->prefix . 'mhbo_bookings'
			),
			ARRAY_A
		);

		$total_collected = is_array( $row ) ? (float) ( $row['total_collected'] ?? 0 ) : 0.0;
		$ledger_count    = is_array( $row ) ? (int) ( $row['ledger_count'] ?? 0 ) : 0;

		if ( $ledger_count > 0 && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) && class_exists( Pricing::class ) && class_exists( Money::class ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight USALI ledger reconciliation for rows with payment_ledger entries.
			$ledger_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, status, payment_status, payment_type, balance_status, total_price, total_gross, payment_amount, deposit_amount, payment_received, deposit_received, remaining_balance, payment_ledger
					 FROM %i
					 WHERE status NOT IN ('cancelled', 'refunded', 'deleted')
					   AND payment_ledger IS NOT NULL
					   AND payment_ledger != ''
					   AND NOT ( ( status = 'pending_payment' OR LOWER(COALESCE(customer_email, '')) = 'guest@pending.local' ) AND COALESCE(payment_status, 'pending') = 'pending' AND COALESCE(deposit_received, 0) = 0 AND COALESCE(payment_received, 0) = 0 AND COALESCE(payment_amount, 0) <= 0 AND COALESCE(payment_capture_id, '') = '' AND COALESCE(balance_collected_via, '') = '' )",
					$wpdb->prefix . 'mhbo_bookings'
				)
			);
			if ( is_array( $ledger_rows ) && array() !== $ledger_rows ) {
				$currency    = Pricing::get_currency_code();
				$collected_m = Money::fromDecimal( number_format( $total_collected, 4, '.', '' ), $currency );
				foreach ( $ledger_rows as $lb ) {
					if ( ! is_object( $lb ) ) {
						continue;
					}
					$eff_gross = ( (float) ( $lb->total_gross ?? 0 ) > 0 ) ? (string) $lb->total_gross : (string) ( $lb->total_price ?? '0' );
					if ( (float) ( $lb->total_price ?? 0 ) <= 0 && (float) $eff_gross > 0 ) {
						$lb->total_price = $eff_gross;
					}
					$p_status = (string) ( $lb->payment_status ?? 'pending' );
					$p_amt    = (float) ( $lb->payment_amount ?? 0 );
					$d_amt    = (float) ( $lb->deposit_amount ?? 0 );
					if ( 'refunded' === $p_status ) {
						$sql_row_m = Money::fromCents( 0, $currency );
					} elseif ( $p_amt > 0 ) {
						$sql_row_m = Money::fromDecimal( (string) $lb->payment_amount, $currency );
					} elseif ( ! empty( $lb->payment_received ) || in_array( $p_status, array( 'completed', 'paid' ), true ) ) {
						$sql_row_m = Money::fromDecimal( $eff_gross, $currency );
					} elseif ( ( ! empty( $lb->deposit_received ) || 'deposit_paid' === $p_status ) && $d_amt > 0 ) {
						$sql_row_m = Money::fromDecimal( (string) $lb->deposit_amount, $currency );
					} else {
						$sql_row_m = Money::fromCents( 0, $currency );
					}

					$actual_m    = Pricing::get_booking_payment_amounts( $lb )['received'];
					$collected_m = $collected_m->add( $actual_m->subtract( $sql_row_m ) );
				}
				if ( $collected_m->isNegative() ) {
					$collected_m = Money::fromCents( 0, $currency );
				}
				$total_collected = (float) $collected_m->toDecimal();
			}
		}

		$metrics = array(
			'occupied_rooms'    => is_array( $row ) ? (int) ( $row['occupied_rooms'] ?? 0 ) : 0,
			'arrivals'          => is_array( $row ) ? (int) ( $row['arrivals'] ?? 0 ) : 0,
			'departures'        => is_array( $row ) ? (int) ( $row['departures'] ?? 0 ) : 0,
			'in_house'          => is_array( $row ) ? (int) ( $row['in_house'] ?? 0 ) : 0,
			'total_bookings'    => is_array( $row ) ? (int) ( $row['total_bookings'] ?? 0 ) : 0,
			'pending_count'     => is_array( $row ) ? (int) ( $row['pending_count'] ?? 0 ) : 0,
			'confirmed_count'   => is_array( $row ) ? (int) ( $row['confirmed_count'] ?? 0 ) : 0,
			'confirmed_revenue' => is_array( $row ) ? (float) ( $row['confirmed_revenue'] ?? 0 ) : 0.0,
			'total_pipeline'    => is_array( $row ) ? (float) ( $row['total_pipeline'] ?? 0 ) : 0.0,
			'total_collected'   => $total_collected,
		);

		if ( class_exists( Cache::class ) ) {
			Cache::set_query( $cache_key, $metrics, Cache::TABLE_BOOKINGS, 600 );
		}

		return $metrics;
	}

	/**
	 * Fetch dashboard booking operational lists by bucket using branched literal SQL.
	 *
	 * @param string $bucket    One of 'arrivals', 'departures', 'inhouse', 'pending', 'recent'.
	 * @param string $today_ymd Hotel local date in Y-m-d format.
	 * @param int    $limit     Maximum rows to return.
	 * @return list<\stdClass>
	 */
	public static function get_dashboard_bookings( string $bucket, string $today_ymd = '', int $limit = 15 ): array {
		global $wpdb;

		$safe_limit = max( 1, $limit );
		$bookings_t = $wpdb->prefix . 'mhbo_bookings';
		$rooms_t    = $wpdb->prefix . 'mhbo_rooms';
		$types_t    = $wpdb->prefix . 'mhbo_room_types';

		switch ( $bucket ) {
			case 'arrivals':
				/** @var list<\stdClass>|null $rows */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; %i escapes identifiers.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT b.*, r.room_number, rt.name as room_type_name
						 FROM %i b
						 LEFT JOIN %i r ON b.room_id = r.id
						 LEFT JOIN %i rt ON r.type_id = rt.id
						 WHERE b.status NOT IN ('cancelled', 'refunded', 'deleted')
						   AND b.check_in = DATE(%s)
						   AND NOT ( ( b.status = 'pending_payment' OR LOWER(COALESCE(b.customer_email, '')) = 'guest@pending.local' ) AND COALESCE(b.payment_status, 'pending') = 'pending' AND COALESCE(b.deposit_received, 0) = 0 AND COALESCE(b.payment_received, 0) = 0 AND COALESCE(b.payment_amount, 0) <= 0 AND COALESCE(b.payment_capture_id, '') = '' AND COALESCE(b.balance_collected_via, '') = '' )
						 ORDER BY b.id DESC
						 LIMIT %d",
						$bookings_t,
						$rooms_t,
						$types_t,
						$today_ymd,
						$safe_limit
					)
				);
				break;

			case 'departures':
				/** @var list<\stdClass>|null $rows */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; %i escapes identifiers.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT b.*, r.room_number, rt.name as room_type_name
						 FROM %i b
						 LEFT JOIN %i r ON b.room_id = r.id
						 LEFT JOIN %i rt ON r.type_id = rt.id
						 WHERE b.status NOT IN ('cancelled', 'refunded', 'deleted')
						   AND b.check_out = DATE(%s)
						   AND NOT ( ( b.status = 'pending_payment' OR LOWER(COALESCE(b.customer_email, '')) = 'guest@pending.local' ) AND COALESCE(b.payment_status, 'pending') = 'pending' AND COALESCE(b.deposit_received, 0) = 0 AND COALESCE(b.payment_received, 0) = 0 AND COALESCE(b.payment_amount, 0) <= 0 AND COALESCE(b.payment_capture_id, '') = '' AND COALESCE(b.balance_collected_via, '') = '' )
						 ORDER BY b.id DESC
						 LIMIT %d",
						$bookings_t,
						$rooms_t,
						$types_t,
						$today_ymd,
						$safe_limit
					)
				);
				break;

			case 'inhouse':
				/** @var list<\stdClass>|null $rows */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; %i escapes identifiers.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT b.*, r.room_number, rt.name as room_type_name
						 FROM %i b
						 LEFT JOIN %i r ON b.room_id = r.id
						 LEFT JOIN %i rt ON r.type_id = rt.id
						 WHERE b.status NOT IN ('cancelled', 'refunded', 'deleted')
						   AND b.check_in <= DATE(%s) AND b.check_out > DATE(%s)
						   AND NOT ( ( b.status = 'pending_payment' OR LOWER(COALESCE(b.customer_email, '')) = 'guest@pending.local' ) AND COALESCE(b.payment_status, 'pending') = 'pending' AND COALESCE(b.deposit_received, 0) = 0 AND COALESCE(b.payment_received, 0) = 0 AND COALESCE(b.payment_amount, 0) <= 0 AND COALESCE(b.payment_capture_id, '') = '' AND COALESCE(b.balance_collected_via, '') = '' )
						 ORDER BY b.check_out ASC
						 LIMIT %d",
						$bookings_t,
						$rooms_t,
						$types_t,
						$today_ymd,
						$today_ymd,
						$safe_limit
					)
				);
				break;

			case 'pending':
				/** @var list<\stdClass>|null $rows */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; %i escapes identifiers.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT b.*, r.room_number, rt.name as room_type_name
						 FROM %i b
						 LEFT JOIN %i r ON b.room_id = r.id
						 LEFT JOIN %i rt ON r.type_id = rt.id
						 WHERE b.status = 'pending'
						   AND NOT ( ( b.status = 'pending_payment' OR LOWER(COALESCE(b.customer_email, '')) = 'guest@pending.local' ) AND COALESCE(b.payment_status, 'pending') = 'pending' AND COALESCE(b.deposit_received, 0) = 0 AND COALESCE(b.payment_received, 0) = 0 AND COALESCE(b.payment_amount, 0) <= 0 AND COALESCE(b.payment_capture_id, '') = '' AND COALESCE(b.balance_collected_via, '') = '' )
						 ORDER BY b.id DESC
						 LIMIT %d",
						$bookings_t,
						$rooms_t,
						$types_t,
						$safe_limit
					)
				);
				break;

			case 'recent':
			default:
				/** @var list<\stdClass>|null $rows */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; %i escapes identifiers.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT b.*, r.room_number, rt.name as room_type_name
						 FROM %i b
						 LEFT JOIN %i r ON b.room_id = r.id
						 LEFT JOIN %i rt ON r.type_id = rt.id
						 WHERE NOT ( ( b.status = 'pending_payment' OR LOWER(COALESCE(b.customer_email, '')) = 'guest@pending.local' ) AND COALESCE(b.payment_status, 'pending') = 'pending' AND COALESCE(b.deposit_received, 0) = 0 AND COALESCE(b.payment_received, 0) = 0 AND COALESCE(b.payment_amount, 0) <= 0 AND COALESCE(b.payment_capture_id, '') = '' AND COALESCE(b.balance_collected_via, '') = '' )
						 ORDER BY b.id DESC
						 LIMIT %d",
						$bookings_t,
						$rooms_t,
						$types_t,
						$safe_limit
					)
				);
				break;
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get total count of bookings, optionally filtered by status and search query.
	 *
	 * @param string $status Filter by status (optional).
	 * @param string $search Search query for guest name, email, phone, ID, or room (optional).
	 * @return int Total number of bookings.
	 */
	public static function count( string $status = '', string $search = '' ): int {
		global $wpdb;

		$where  = '1=1' . self::get_completed_draft_exclusion_sql( 'b' );
		$params = array();
		if ( $status && 'all' !== $status ) {
			$where   .= ' AND b.status = %s';
			$params[] = $status;
		}

		if ( '' !== trim( $search ) ) {
			$clean_search = trim( $search );
			$id_search    = ltrim( $clean_search, '#' );
			$search_like  = '%' . $wpdb->esc_like( $clean_search ) . '%';

			if ( is_numeric( $id_search ) && (int) $id_search > 0 ) {
				$where   .= ' AND ( b.id = %d OR b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.customer_phone LIKE %s OR r.room_number LIKE %s OR t.name LIKE %s OR b.multi_room_parent LIKE %s )';
				$params[] = (int) $id_search;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
			} else {
				$where   .= ' AND ( b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.customer_phone LIKE %s OR r.room_number LIKE %s OR t.name LIKE %s OR b.multi_room_parent LIKE %s )';
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
			}
		}

		$sql        = 'SELECT COUNT(*) FROM %i b LEFT JOIN %i r ON b.room_id = r.id LEFT JOIN %i t ON r.type_id = t.id WHERE ' . $where;
		$all_params = array_merge(
			array( $wpdb->prefix . 'mhbo_bookings', $wpdb->prefix . 'mhbo_rooms', $wpdb->prefix . 'mhbo_room_types' ),
			$params
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$all_params ) );
	}

	/**
	 * Get bookings with full details (Room and Room Type) for admin lists.
	 *
	 * @param string $status Filter by status (optional).
	 * @param int    $limit Max results (<= 0 or -1 retrieves all).
	 * @param int    $offset Pagination offset (default 0).
	 * @param string $orderby Column to sort by (id, guest, room, dates, total, status, created).
	 * @param string $order Sort direction (ASC or DESC).
	 * @param string $search Search query for guest name, email, phone, ID, or room (optional).
	 * @return list<\stdClass>
	 */
	public static function get_list( string $status = '', int $limit = 25, int $offset = 0, string $orderby = 'id', string $order = 'DESC', string $search = '' ): array {
		global $wpdb;

		$where  = '1=1' . self::get_completed_draft_exclusion_sql( 'b' );
		$params = array();
		if ( $status && 'all' !== $status ) {
			$where   .= ' AND b.status = %s';
			$params[] = $status;
		}

		if ( '' !== trim( $search ) ) {
			$clean_search = trim( $search );
			$id_search    = ltrim( $clean_search, '#' );
			$search_like  = '%' . $wpdb->esc_like( $clean_search ) . '%';

			if ( is_numeric( $id_search ) && (int) $id_search > 0 ) {
				$where   .= ' AND ( b.id = %d OR b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.customer_phone LIKE %s OR r.room_number LIKE %s OR t.name LIKE %s OR b.multi_room_parent LIKE %s )';
				$params[] = (int) $id_search;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
			} else {
				$where   .= ' AND ( b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.customer_phone LIKE %s OR r.room_number LIKE %s OR t.name LIKE %s OR b.multi_room_parent LIKE %s )';
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
				$params[] = $search_like;
			}
		}

		$order   = ( 'ASC' === strtoupper( $order ) ) ? 'ASC' : 'DESC';
		$orderby = strtolower( $orderby );

		$orderby_map = array(
			'id'      => "b.id {$order}",
			'guest'   => "b.customer_name {$order}, b.id DESC",
			'room'    => "r.room_number {$order}, t.name {$order}, b.id DESC",
			'dates'   => "b.check_in {$order}, b.check_out {$order}, b.id DESC",
			'total'   => "b.total_price {$order}, b.id DESC",
			'status'  => "b.status {$order}, b.id DESC",
			'created' => "b.created_at {$order}, b.id DESC",
		);

		$order_clause = $orderby_map[ $orderby ] ?? "b.id {$order}";

		$limit_clause = '';
		$limit_params = array();
		if ( $limit > 0 ) {
			$limit_clause = ' LIMIT %d OFFSET %d';
			$limit_params = array( $limit, max( 0, $offset ) );
		}

		// No string interpolation: table names use %i (WP 6.2+ identifier placeholder).
		// $where is built solely from string literals and %s/%d placeholders — no user data.
		// ORDER BY clause is strictly resolved from a hardcoded whitelist map.
		$sql = 'SELECT b.*, r.room_number, t.name as room_type, g.email as guest_email, g.vip_status as guest_vip_status, g.caution_flag as guest_caution_flag'
			. ' FROM %i b'
			. ' LEFT JOIN %i r ON b.room_id = r.id'
			. ' LEFT JOIN %i t ON r.type_id = t.id'
			. ' LEFT JOIN %i g ON b.guest_id = g.id'
			. ' WHERE ' . $where
			. ' ORDER BY ' . $order_clause . $limit_clause;

		$all_params = array_merge(
			array( $wpdb->prefix . 'mhbo_bookings', $wpdb->prefix . 'mhbo_rooms', $wpdb->prefix . 'mhbo_room_types', $wpdb->prefix . 'mhbo_guests' ),
			$params,
			$limit_params
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is a variable because WHERE is dynamic and ORDER BY uses strict whitelist mapping; no user data is interpolated. Passed through prepare() before get_results().
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$all_params ) );
	}

	/**
	 * Fetch a single booking with room details.
	 *
	 * @param int $id Booking ID.
	 * @return \stdClass|null
	 */
	public static function get_one( int $id ): ?\stdClass {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; %i handles identifier escaping (WP 6.2+).
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT b.*, r.room_number as room_name FROM %i b LEFT JOIN %i r ON b.room_id = r.id WHERE b.id = %d',
				$wpdb->prefix . 'mhbo_bookings',
				$wpdb->prefix . 'mhbo_rooms',
				$id
			)
		);

		return $row ?: null;
	}

}
