<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache Management Utility
 * 
 * Implements Rule 13: Row-level caching with separate version tracking 
 * for each data table to ensure maximum performance and data integrity.
 */
class Cache {

	/**
	 * Runtime static cache for single-request performance.
	 *
	 * @var array<string, mixed>
	 */
	private static array $runtime_cache = array();

	/**
	 * Get a memoized option value for single-request performance.
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Default fallback if option does not exist.
	 * @return mixed
	 */
	public static function get_option( string $option, mixed $default_value = null ): mixed {
		if ( array_key_exists( $option, self::$runtime_cache ) ) {
			return self::$runtime_cache[ $option ];
		}

		$val                            = get_option( $option, $default_value );
		self::$runtime_cache[ $option ] = $val;
		return $val;
	}

	/**
	 * Check if MHBO caching is enabled via settings.
	 */
	public static function is_enabled(): bool {
		return (bool) self::get_option( 'mhbo_cache_enabled', 1 );
	}

	/**
	 * Runtime memory cache for table existence checks.
	 *
	 * @var array<string, bool>
	 */
	private static array $tables_exist = array();

	/**
	 * Check if a custom table exists, using static memory and transient caching.
	 * Dramatically cuts redundant MySQL metadata dictionary queries across admin and front-end requests.
	 *
	 * @param string $table_name Full table name (e.g. {$wpdb->prefix}mhbo_guests).
	 * @return bool
	 */
	public static function table_exists( string $table_name ): bool {
		if ( isset( self::$tables_exist[ $table_name ] ) ) {
			return self::$tables_exist[ $table_name ];
		}

		$transient_key = 'mhbo_tbl_ok_' . md5( $table_name );
		$cached        = get_transient( $transient_key );
		if ( '1' === $cached ) {
			self::$tables_exist[ $table_name ] = true;
			return true;
		}

		global $wpdb;
		$escaped_like = ( is_object( $wpdb ) && method_exists( $wpdb, 'esc_like' ) ) ? $wpdb->esc_like( $table_name ) : addcslashes( $table_name, '_%\\' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached via transient & memory above.
		$found        = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $escaped_like ) ) === $table_name );
		if ( $found ) {
			set_transient( $transient_key, '1', DAY_IN_SECONDS );
		}

		self::$tables_exist[ $table_name ] = $found;
		return $found;
	}

	/**
	 * Mark a table as existing (e.g. right after creation).
	 *
	 * @param string $table_name
	 */
	public static function mark_table_exists( string $table_name ): void {
		self::$tables_exist[ $table_name ] = true;
		set_transient( 'mhbo_tbl_ok_' . md5( $table_name ), '1', DAY_IN_SECONDS );
	}

	/**
	 * Invalidate table existence cache (e.g. on table drop).
	 *
	 * @param string $table_name
	 */
	public static function forget_table_exists( string $table_name ): void {
		unset( self::$tables_exist[ $table_name ] );
		delete_transient( 'mhbo_tbl_ok_' . md5( $table_name ) );
	}

	/**
	 * Cache group for all MHBO data.
	 */
	const GROUP = 'mhbo_cache';

	/**
	 * Last changed salt for Rule 13.
	 */
	const LAST_CHANGED_KEY = 'bookings_last_changed';

	/**
	 * Available tables for versioning.
	 */
	const TABLE_BOOKINGS           = 'bookings';
	const TABLE_ROOM_TYPES         = 'room_types';
	const TABLE_ROOMS              = 'rooms';
	const TABLE_PRICING_RULES      = 'pricing_rules';
	const TABLE_ICAL_CONNECTIONS   = 'ical_connections';
	const TABLE_SETTINGS           = 'settings';
	const TABLE_CALENDAR_OVERRIDES = 'calendar_overrides';
	const TABLE_GUESTS             = 'guests';

	/**
	 * Get the current version of a data table.
	 *
	 * @param string $table The table identifier (use class constants).
	 * @return int The current version number.
	 */
	public static function get_version( string $table ): int {
		// 2026 BP: Request-level static caching to avoid redundant get_option calls.
		if ( isset( self::$runtime_cache[ 'v_' . $table ] ) ) {
			return (int) self::$runtime_cache[ 'v_' . $table ];
		}

		$version = (int) get_option( "mhbo_v_{$table}", 1 );
		if ( $version < 1 ) {
			$version = 1;
			update_option( "mhbo_v_{$table}", 1 );
		}

		self::$runtime_cache[ 'v_' . $table ] = $version;
		return $version;
	}

	/**
	 * Increment the version of a data table.
	 * This effectively invalidates all cached queries related to this table.
	 *
	 * @param string $table The table identifier (use class constants).
	 */
	public static function bump( string $table ): void {
		$version = self::get_version( $table ) + 1;
		update_option( "mhbo_v_{$table}", $version );
		
		// Clear non-persistent local object cache and immediately memoize new version in runtime cache
		wp_cache_delete( "mhbo_v_{$table}", self::GROUP );
		self::$runtime_cache[ 'v_' . $table ] = $version;
		
		// Ensure all versioned transients are effectively "cleared" by the new version number
		// for any process checking them subsequently.
	}

	/**
	 * Invalidate all cached queries for a given table.
	 *
	 * @param string $table The table identifier (use class constants).
	 */
	public static function invalidate_table( string $table ): void {
		self::bump( $table );
		if ( in_array( $table, array( self::TABLE_BOOKINGS, self::TABLE_ROOMS, self::TABLE_ROOM_TYPES, self::TABLE_GUESTS ), true ) ) {
			self::clear_dashboard_transients();
		}
	}

	/**
	 * Purge all MHBO caches, bump table generations, flush WP 7.1+ object cache groups, and clean transient DB records.
	 *
	 * @return bool Always true on completion.
	 */
	public static function flush_all(): bool {
		$tables = array(
			self::TABLE_CALENDAR_OVERRIDES,
			self::TABLE_BOOKINGS,
			self::TABLE_ROOM_TYPES,
			self::TABLE_ROOMS,
			self::TABLE_PRICING_RULES,
			self::TABLE_ICAL_CONNECTIONS,
			self::TABLE_SETTINGS,
			self::TABLE_GUESTS,
		);

		// 1. Increment generation numbers for all data tables.
		foreach ( $tables as $table ) {
			self::bump( $table );
		}

		// 2. Clear static in-memory caches for the current PHP lifecycle.
		self::$runtime_cache = array();
		self::$tables_exist  = array();

		// 3. WordPress 6.1+ / 7.1+ Object Cache Group Flushing.
		$groups = array(
			self::GROUP,
			'mhbo',
			'mhbo_bookings',
			'mhbo_rooms',
			'mhbo_guests',
			'mhbo_privacy',
			'mhbo_coupons',
		);
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			foreach ( $groups as $group ) {
				wp_cache_flush_group( $group );
			}
		}

		// 4. Fallback manual key deletion for persistent drop-ins lacking group flush support.
		$global_keys = array(
			array( 'mhbo_all_rooms', 'mhbo_rooms' ),
			array( 'mhbo_room_types_all', 'mhbo' ),
			array( 'mhbo_pricing_rules_all', 'mhbo' ),
			array( 'mhbo_coupons_table_ok', 'mhbo_coupons' ),
		);
		foreach ( $global_keys as $item ) {
			wp_cache_delete( $item[0], $item[1] );
		}

		// 5. Clear all dashboard, widget, and availability transients.
		self::clear_dashboard_transients();
		self::clear_database_transients();

		// 6. Invalidate third-party external page caches (LiteSpeed, WP Rocket, W3TC, etc.).
		self::flush_page_caches();

		// 7. Fire standard WordPress action hooks.
		do_action( 'mhbo_cache_flushed' );
		do_action( 'mhbo_cache_purged' );

		return true;
	}

	/**
	 * Delete cached transient records from wp_options table when external object cache is not in use.
	 */
	public static function clear_database_transients(): void {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb || empty( $wpdb->options ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient cleanup during administrative cache purge.
		$wpdb->query(
			"DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_mhbo_%' 
             OR option_name LIKE '_transient_timeout_mhbo_%'
             OR option_name LIKE '_site_transient_mhbo_%'
             OR option_name LIKE '_site_transient_timeout_mhbo_%'"
		);
	}

	/**
	 * Invalidate external page caches (LiteSpeed, WP Rocket, W3TC, SG Optimizer, WP Super Cache, Autoptimize).
	 */
	public static function flush_page_caches(): void {
		// LiteSpeed Cache
		if ( has_action( 'litespeed_purge_all' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache hook.
			do_action( 'litespeed_purge_all' );
		}

		// WP Rocket
		if ( function_exists( 'rocket_clean_domain' ) ) {
			\call_user_func( 'rocket_clean_domain' );
		}

		// W3 Total Cache
		if ( function_exists( 'w3tc_flush_all' ) ) {
			\call_user_func( 'w3tc_flush_all' );
		}

		// WP Super Cache
		if ( function_exists( 'wp_cache_clean_cache' ) ) {
			global $file_prefix;
			\call_user_func( 'wp_cache_clean_cache', is_string( $file_prefix ) ? $file_prefix : '', true );
		}

		// SiteGround Optimizer
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			\call_user_func( 'sg_cachepress_purge_cache' );
		}

		// Autoptimize
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			\autoptimizeCache::clearall();
		}
	}

	/**
	 * Rule 13: Clear all dashboard and analytics transients.
	 * 
	 * @since 2.3.8
	 */
	public static function clear_dashboard_transients(): void {
		$dates = array_unique( array( HotelTime::today(), wp_date( 'Y-m-d' ) ) );
		
		delete_transient( 'mhbo_widget_batch_counts' );
		delete_transient( 'mhbo_widget_total_bookings' );
		delete_transient( 'mhbo_widget_pending_bookings' );
		delete_transient( 'mhbo_dashboard_total_bookings' );
		delete_transient( 'mhbo_dashboard_pending_bookings' );

		foreach ( $dates as $today ) {
			// Dashboard Widget Transients
			delete_transient( 'mhbo_widget_today_bookings_' . $today );
			delete_transient( 'mhbo_widget_ops_' . $today );
			delete_transient( 'mhbo_widget_ops_v260_' . $today );
			delete_transient( 'mhbo_widget_pro_' . $today );
			
			// Full Dashboard Stats
			delete_transient( 'mhbo_dashboard_stats_' . $today );
			delete_transient( 'mhbo_dashboard_ops_' . $today );
			delete_transient( 'mhbo_dashboard_ops_v260_' . $today );

			// Legacy transients (Cleanup)
			delete_transient( 'mhbo_dashboard_earned_revenue_' . $today );
			delete_transient( 'mhbo_dashboard_future_revenue_' . $today );
		}
	}

	/**
	 * Alias for flush_all() to support legacy calls.
	 * 
	 * @return bool Always true on completion.
	 */
	public static function flush(): bool {
		return self::flush_all();
	}

	/**
	 * Get a cached query result.
	 *
	 * @param string $key Unique query key (e.g. sql hash).
	 * @param string $table Table to track version against.
	 * @return mixed Cached data or false on miss.
	 */
	public static function get_query( string $key, string $table ): mixed {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$version = self::get_version( $table );
		return wp_cache_get( "q_{$key}_v{$version}", self::GROUP );
	}

	/**
	 * Cache a query result.
	 *
	 * @param string $key Unique query key.
	 * @param mixed  $data Data to cache.
	 * @param string $table Table to track version against.
	 * @param int    $expire Expiration in seconds (default 1 hour).
	 * @return bool True on success, false on failure.
	 */
	public static function set_query( string $key, mixed $data, string $table, int $expire = 3600 ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$version = self::get_version( $table );
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- Dynamic cache expiry parameter.
		return wp_cache_set( "q_{$key}_v{$version}", $data, self::GROUP, $expire );
	}

	/**
	 * Get a cached single row (row-level caching).
	 *
	 * @param string|int $id Unique identifier for the row.
	 * @param string     $table Table to track version against.
	 * @return mixed Cached data or false on miss.
	 */
	public static function get_row( string|int $id, string $table ): mixed {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$version = self::get_version( $table );
		return wp_cache_get( "r_{$table}_{$id}_v{$version}", self::GROUP );
	}

	/**
	 * Cache a single row (row-level caching).
	 *
	 * @param string|int $id Unique identifier for the row.
	 * @param mixed      $data Data to cache.
	 * @param string     $table Table to track version against.
	 * @param int        $expire Expiration in seconds (default 1 hour).
	 * @return bool True on success, false on failure.
	 */
	public static function set_row( string|int $id, mixed $data, string $table, int $expire = 3600 ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$version = self::get_version( $table );
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- Dynamic cache expiry parameter.
		return wp_cache_set( "r_{$table}_{$id}_v{$version}", $data, self::GROUP, $expire );
	}

	/**
	 * Get a versioned transient. 
	 * RATIONALE: Persistent caching even without object cache (Redis/Memcached).
	 *
	 * @param string $key   Unique cache key.
	 * @param string $table Controlling table version.
	 * @return mixed Data or false.
	 */
	public static function get_transient_versioned( string $key, string $table ): mixed {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$version = self::get_version( $table );
		return get_transient( "mhbo_{$key}_v{$version}" );
	}

	/**
	 * Set a versioned transient.
	 *
	 * @param string $key    Unique cache key.
	 * @param mixed  $data   Value to cache.
	 * @param string $table  Controlling table version.
	 * @param int    $expire Expiration (default 12 hours for persistent cache).
	 * @return bool
	 */
	public static function set_transient_versioned( string $key, mixed $data, string $table, int $expire = 43200 ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$version = self::get_version( $table );
		return set_transient( "mhbo_{$key}_v{$version}", $data, $expire );
	}

	/**
	 * Get monthly availability cache transient.
	 *
	 * @param int $year    Year (e.g. 2026)
	 * @param int $month   Month (1-12)
	 * @param int $room_id Room ID (0 for all rooms)
	 * @return mixed Cached data or false
	 */
	public static function get_month_availability( int $year, int $month, int $room_id = 0 ): mixed {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$key = sprintf( 'avail_%04d_%02d_r%d', $year, $month, $room_id );
		return self::get_transient_versioned( $key, self::TABLE_BOOKINGS );
	}

	/**
	 * Set monthly availability cache transient.
	 *
	 * @param int   $year    Year
	 * @param int   $month   Month
	 * @param int   $room_id Room ID
	 * @param mixed $data    Availability data
	 * @param int   $expire  Expiration in seconds (default 12 hours)
	 * @return bool
	 */
	public static function set_month_availability( int $year, int $month, int $room_id, mixed $data, int $expire = 43200 ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$key = sprintf( 'avail_%04d_%02d_r%d', $year, $month, $room_id );
		return self::set_transient_versioned( $key, $data, self::TABLE_BOOKINGS, $expire );
	}

	/**
	 * Invalidate transient caches for a specific date range without dropping unrelated months.
	 *
	 * @param string $check_in  YYYY-MM-DD
	 * @param string $check_out YYYY-MM-DD
	 * @param int    $room_id   Room ID (0 for all)
	 */
	public static function invalidate_date_range( string $check_in, string $check_out, int $room_id = 0 ): void {
		try {
			$start = new \DateTime( $check_in );
			$end   = new \DateTime( $check_out );
			$end->modify( '+1 day' );

			$interval = new \DateInterval( 'P1M' );
			$period   = new \DatePeriod( new \DateTime( $start->format( 'Y-m-01' ) ), $interval, $end );

			$version = self::get_version( self::TABLE_BOOKINGS );
			foreach ( $period as $dt ) {
				$y = (int) $dt->format( 'Y' );
				$m = (int) $dt->format( 'm' );
				delete_transient( sprintf( 'mhbo_avail_%04d_%02d_r%d_v%d', $y, $m, $room_id, $version ) );
				delete_transient( sprintf( 'mhbo_avail_%04d_%02d_r0_v%d', $y, $m, $version ) );
			}
		} catch ( \Exception ) {
			// Fallback to table invalidation
			self::bump( self::TABLE_BOOKINGS );
		}
	}

	/**
	 * Invalidate a specific row cache without bumping the whole table.
	 * Not recommended for queries involving multiple rows, but useful for 
	 * precise row updates if the versioning pattern is strictly row-id based.
	 *
	 * NOTE: In Rule 13 implementation, we prefer bumping the entire table 
	 * version to ensure all dependent complex queries (like availability) are cleared.
	 */
	public static function delete_row( string|int $id, string $table ): void {
		$version = self::get_version( $table );
		wp_cache_delete( "r_{$table}_{$id}_v{$version}", self::GROUP );
	}

	/**
	 * Rule 13: Invalidate all booking-related caches.
	 * Bumps bookings version and rooms version (as bookings affect room availability).
	 */
	public static function invalidate_booking( int $booking_id = 0, int $room_id = 0 ): void {
		self::bump( self::TABLE_BOOKINGS );
		self::bump( self::TABLE_ROOMS ); // Availability changes

		if ( $booking_id > 0 ) {
			wp_cache_delete( 'mhbo_booking_' . $booking_id, 'mhbo_bookings' );
		}

		if ( $room_id > 0 ) {
			wp_cache_delete( 'mhbo_room_' . $room_id, 'mhbo_rooms' );
		}

		// Clear dashboard/widget transients via comprehensive routine
		self::clear_dashboard_transients();
	}

	/**
	 * Rule 13: Invalidate room-related caches.
	 */
	public static function invalidate_rooms(): void {
		self::bump( self::TABLE_ROOMS );
		self::bump( self::TABLE_ROOM_TYPES );
		wp_cache_delete( 'mhbo_all_rooms', 'mhbo_rooms' );
		wp_cache_delete( 'mhbo_room_types_all', 'mhbo' );
		do_action( 'mhbo_rooms_invalidated' );
	}

	/**
	 * Rule 13: Invalidate pricing-related caches.
	 */
	public static function invalidate_pricing(): void {
		self::bump( self::TABLE_PRICING_RULES );
		wp_cache_delete( 'mhbo_pricing_rules_all', 'mhbo' );
		do_action( 'mhbo_pricing_invalidated' );
	}

	/**
	 * Invalidate calendar overrides cache.
	 */
	public static function invalidate_calendar_overrides(): void {
		self::bump( self::TABLE_CALENDAR_OVERRIDES );
	}

	/**
	 * COMPATIBILITY: Legacy calendar cache invalidation.
	 */
	/**
	 * COMPATIBILITY: Legacy pricing version retrieval.
	 */
	public static function get_prices_version(): int {
		return self::get_version( self::TABLE_PRICING_RULES );
	}

	/**
	 * COMPATIBILITY: Legacy rooms version retrieval.
	 */
	public static function get_availability_version(): int {
		return self::get_version( self::TABLE_ROOMS );
	}

	/**
	 * COMPATIBILITY: Legacy settings version retrieval.
	 */
	public static function get_settings_version(): int {
		return self::get_version( self::TABLE_SETTINGS );
	}

	/**
	 * COMPATIBILITY: Legacy availability retrieval used by RestApi.php.
	 * Redirects to Rule 13 versioning for 'bookings' table.
	 */
	public static function get_bookings_last_changed(): int {
		return self::get_version( self::TABLE_BOOKINGS );
	}
}
