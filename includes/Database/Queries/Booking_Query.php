<?php declare(strict_types=1);

namespace MHBO\Database\Queries;

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
	 * Get recent bookings with room details in a single JOIN.
	 *
	 * @param int $limit Number of bookings to retrieve.
	 * @return list<\stdClass> List of booking objects.
	 */
	public static function get_recent( int $limit = 5 ): array {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; %i handles identifier escaping (WP 6.2+).
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.*, r.room_number as room_name FROM %i b LEFT JOIN %i r ON b.room_id = r.id ORDER BY b.created_at DESC LIMIT %d',
				$wpdb->prefix . 'mhbo_bookings',
				$wpdb->prefix . 'mhbo_rooms',
				$limit
			)
		);
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

		$where  = '1=1';
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

		$where  = '1=1';
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
