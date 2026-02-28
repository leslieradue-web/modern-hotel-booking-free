<?php declare(strict_types=1);

namespace MHB\Core;

if (!defined('ABSPATH')) {
	exit;
}

class Activator
{
	public static function activate()
	{
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		// Custom tables are necessary for booking logic and historical data integrity.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Necessary for plugin install
		$sql_room_types = "CREATE TABLE {$wpdb->prefix}mhb_room_types (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text,
			base_price decimal(10,2) NOT NULL DEFAULT '0.00',
			max_adults tinyint(4) NOT NULL DEFAULT 2,
			max_children tinyint(4) NOT NULL DEFAULT 0,
			child_age_free_limit tinyint(4) NOT NULL DEFAULT 0,
			child_rate decimal(10,2) NOT NULL DEFAULT 0.00,
			total_rooms mediumint(9) NOT NULL DEFAULT 1,
			amenities text DEFAULT NULL,
			image_url varchar(255) DEFAULT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";
		dbDelta($sql_room_types);


		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Necessary for plugin install
		$sql_rooms = "CREATE TABLE {$wpdb->prefix}mhb_rooms (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			type_id mediumint(9) NOT NULL,
			room_number varchar(50) NOT NULL,
			status varchar(20) DEFAULT 'available',
			custom_price decimal(10,2) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY type_id (type_id)
		) $charset_collate;";
		dbDelta($sql_rooms);


		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Necessary for plugin install
		$sql_bookings = "CREATE TABLE {$wpdb->prefix}mhb_bookings (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			room_id mediumint(9) NOT NULL,
			customer_name varchar(255) NOT NULL,
			customer_email varchar(255) NOT NULL,
			customer_phone varchar(50) DEFAULT NULL,
			check_in date NOT NULL,
			check_out date NOT NULL,
			total_price decimal(10,2) NOT NULL,
			status varchar(20) DEFAULT 'pending',
			booking_token varchar(64) NOT NULL,
			booking_language varchar(10) DEFAULT 'en',
			admin_notes text DEFAULT NULL,
			booking_extras text DEFAULT NULL,
			discount_amount decimal(10,2) DEFAULT '0.00',
			deposit_amount decimal(10,2) DEFAULT '0.00',
			deposit_received tinyint(1) DEFAULT 0,
			payment_method varchar(50) DEFAULT 'onsite',
			payment_received tinyint(1) DEFAULT 0,
			payment_status varchar(20) DEFAULT 'pending',
			payment_transaction_id varchar(255) DEFAULT NULL,
			payment_date datetime DEFAULT NULL,
			payment_error text DEFAULT NULL,
			payment_amount decimal(10,2) DEFAULT NULL,
			source varchar(50) DEFAULT 'direct',
			guests tinyint(4) DEFAULT 1,
			children int(11) DEFAULT 0,
			children_ages text DEFAULT NULL,
			ical_uid varchar(255) DEFAULT NULL,
			external_id varchar(255) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			custom_fields text DEFAULT NULL,
			tax_enabled tinyint(1) DEFAULT 0,
			tax_mode varchar(20) DEFAULT 'disabled',
			tax_rate_accommodation decimal(5,2) DEFAULT 0.00,
			tax_rate_extras decimal(5,2) DEFAULT 0.00,
			room_total_net decimal(10,2) DEFAULT 0.00,
			children_total_net decimal(10,2) DEFAULT 0.00,
			extras_total_net decimal(10,2) DEFAULT 0.00,
			room_tax decimal(10,2) DEFAULT 0.00,
			children_tax decimal(10,2) DEFAULT 0.00,
			extras_tax decimal(10,2) DEFAULT 0.00,
			subtotal_net decimal(10,2) DEFAULT 0.00,
			total_tax decimal(10,2) DEFAULT 0.00,
			total_gross decimal(10,2) DEFAULT 0.00,
			tax_breakdown text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY room_id (room_id),
			KEY ical_uid (ical_uid),
			KEY external_id (external_id),
			KEY payment_status (payment_status),
			KEY payment_transaction_id (payment_transaction_id),
			KEY check_in_out (check_in, check_out),
			KEY status_payment (status, payment_status)
		) $charset_collate;";
		dbDelta($sql_bookings);


		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Necessary for pro features
		$sql_ical_connections = "CREATE TABLE {$wpdb->prefix}mhb_ical_connections (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			room_id mediumint(9) NOT NULL,
			platform varchar(50) DEFAULT 'custom',
			name varchar(255) DEFAULT NULL,
			ical_url text NOT NULL,
			sync_direction varchar(20) DEFAULT 'import',
			last_sync datetime DEFAULT NULL,
			sync_status varchar(20) DEFAULT 'pending',
			last_error text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			events_count int(11) DEFAULT 0,
			sync_token varchar(255) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY room_id (room_id),
			KEY sync_status (sync_status),
			KEY platform (platform)
		) $charset_collate;";
		dbDelta($sql_ical_connections);

		$sql_ical = "CREATE TABLE {$wpdb->prefix}mhb_ical_feeds (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			room_id mediumint(9) NOT NULL,
			feed_url text NOT NULL,
			feed_name varchar(100) DEFAULT NULL,
			platform varchar(50) DEFAULT 'custom',
			last_synced datetime DEFAULT NULL,
			last_error text DEFAULT NULL,
			events_count int(11) DEFAULT 0,
			PRIMARY KEY  (id),
			KEY room_id (room_id)
		) $charset_collate;";
		dbDelta($sql_ical);


		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Necessary for pro features
		$sql_pricing = "CREATE TABLE {$wpdb->prefix}mhb_pricing_rules (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			room_id mediumint(9) NOT NULL DEFAULT 0,
			type_id mediumint(9) NOT NULL DEFAULT 0,
			name varchar(100) NOT NULL,
			start_date date NOT NULL,
			end_date date NOT NULL,
			amount decimal(10,2) NOT NULL DEFAULT 0.00,
			rule_type varchar(20) DEFAULT 'seasonal',
			operation varchar(20) DEFAULT 'increase',
			priority tinyint(4) DEFAULT 10,
			PRIMARY KEY  (id),
			KEY type_id (type_id),
			KEY dates (start_date, end_date)
		) $charset_collate;";
		dbDelta($sql_pricing);

		// Add new options for multilingual and currency
		add_option('mhb_db_version', MHB_VERSION);
		add_option('mhb_currency_code', 'USD');
		add_option('mhb_currency_symbol', '$');
		add_option('mhb_currency_position', 'before');
		// License option removed for Free version
		add_option('mhb_gateway_stripe_enabled', 0);
		add_option('mhb_gateway_paypal_enabled', 0);
		add_option('mhb_gateway_onsite_enabled', 0);
		add_option('mhb_stripe_mode', 'test');
		add_option('mhb_paypal_mode', 'sandbox');
		add_option('mhb_ical_token', wp_generate_password(32, false));

		// Tax System Options
		add_option('mhb_tax_mode', 'disabled');
		add_option('mhb_tax_rate_accommodation', 0.00);
		add_option('mhb_tax_rate_extras', 0.00);
		add_option('mhb_tax_label', '[:en]VAT[:ro]TVA[:]');
		add_option('mhb_tax_registration_number', '');
		add_option('mhb_tax_display_frontend', 1);
		add_option('mhb_tax_display_email', 1);
		add_option('mhb_tax_rounding_mode', 'per_total');
		add_option('mhb_tax_decimal_places', 2);
		add_option('mhb_tax_zero_rate_label', '[:en]Zero Rate[:ro]Cotă Zero[:]');

		// iCal Sync Settings
		add_option('mhb_ical_auto_sync_enabled', 1);
		add_option('mhb_ical_sync_interval', '6hours');
		add_option('mhb_ical_retry_enabled', 1);
		add_option('mhb_ical_email_notifications', 0);
		add_option('mhb_ical_notification_email', get_option('admin_email'));
		add_option('mhb_ical_sync_lock_timeout', 30);

		// Cache Settings (optional/configurable)
		add_option('mhb_cache_enabled', 1);

		// License API Credentials — only seed when Pro classes are available
		// Obfuscated to prevent casual source reading; server-side domain validation is the real security layer
		if (class_exists('MHB\Core\LicenseManager')) {
			// License option removed for Free version
			// License option removed for Free version
		}
	}

	/**
	 * Migrate database schema for existing installations.
	 * Called during plugin updates.
	 *
	 * @param string $old_version Previous version
	 * @param string $new_version New version
	 */
	public static function migrate($old_version, $new_version)
	{
		// Run activate to ensure all tables and columns are up to date via dbDelta
		self::activate();

		// Add tax options if they don't exist
		add_option('mhb_tax_mode', 'disabled');
		add_option('mhb_tax_rate_accommodation', 0.00);
		add_option('mhb_tax_rate_extras', 0.00);
		add_option('mhb_tax_label', '[:en]VAT[:ro]TVA[:]');
		add_option('mhb_tax_registration_number', '');
		add_option('mhb_tax_display_frontend', 1);
		add_option('mhb_tax_display_email', 1);
		add_option('mhb_tax_rounding_mode', 'per_total');
		add_option('mhb_tax_decimal_places', 2);
		add_option('mhb_tax_zero_rate_label', '[:en]Zero Rate[:ro]Cotă Zero[:]');

		// License API Credentials (for existing installations)
		if (class_exists('MHB\Core\LicenseManager')) {
			// License option removed for Free version
			// License option removed for Free version
		}

		// Cache Settings (for existing installations)
		add_option('mhb_cache_enabled', 1);

		// Migrate iCal feeds to new connections table
		self::migrate_ical_feeds_to_connections();

		// Add iCal sync options
		add_option('mhb_ical_auto_sync_enabled', 1);
		add_option('mhb_ical_sync_interval', '6hours');
		add_option('mhb_ical_retry_enabled', 1);
		add_option('mhb_ical_email_notifications', 0);
		add_option('mhb_ical_notification_email', get_option('admin_email'));
		add_option('mhb_ical_sync_lock_timeout', 30);

		// Add new indexes for performance (for existing installations)
		self::add_performance_indexes();

		update_option('mhb_db_version', $new_version);
	}

	/**
	 * Migrate old iCal feeds to new connections table.
	 */
	private static function migrate_ical_feeds_to_connections()
	{
		global $wpdb;
		// Check if old table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration
		$old_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", "{$wpdb->prefix}mhb_ical_feeds"));
		if (!$old_exists) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration
		$new_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mhb_ical_connections");
		if (0 < (int) $new_count) {
			return; // Already migrated
		}

		// Migrate data
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration
		$feeds = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mhb_ical_feeds");
		foreach ($feeds as $feed) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Schema migration
				"{$wpdb->prefix}mhb_ical_connections",
				array(
					'room_id' => $feed->room_id,
					'platform' => $feed->platform ?? 'custom',
					'name' => $feed->feed_name ?? '',
					'ical_url' => $feed->feed_url,
					'sync_direction' => 'import',
					'last_sync' => $feed->last_synced,
					'sync_status' => $feed->last_error ? 'failed' : 'pending',
					'last_error' => $feed->last_error,
					'created_at' => current_time('mysql'),
					'events_count' => $feed->events_count ?? 0,
				),
				array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
			);
		}
	}

	/**
	 * Add performance indexes to existing tables.
	 * Called during migration to improve query performance.
	 */
	private static function add_performance_indexes()
	{
		global $wpdb;

		// Add composite index for booking date range queries
		// Add composite index for booking date range queries
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration, table name hardcoded
		$index_check = $wpdb->get_var("SHOW INDEX FROM {$wpdb->prefix}mhb_bookings WHERE Key_name = 'idx_check_in_out'");
		if (empty($index_check)) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name hardcoded
			$wpdb->query("ALTER TABLE {$wpdb->prefix}mhb_bookings ADD INDEX idx_check_in_out (check_in, check_out)");
		}

		// Add composite index for status/payment filtering
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration
		$index_check = $wpdb->get_var("SHOW INDEX FROM {$wpdb->prefix}mhb_bookings WHERE Key_name = 'idx_status_payment'");
		if (empty($index_check)) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration
			$wpdb->query("ALTER TABLE {$wpdb->prefix}mhb_bookings ADD INDEX idx_status_payment (status, payment_status)");
		}

		// Add index for pricing rules date queries
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration
		$index_check = $wpdb->get_var("SHOW INDEX FROM {$wpdb->prefix}mhb_pricing_rules WHERE Key_name = 'idx_dates'");
		if (empty($index_check)) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration
			$wpdb->query("ALTER TABLE {$wpdb->prefix}mhb_pricing_rules ADD INDEX idx_dates (start_date, end_date)");
		}
	}
}
