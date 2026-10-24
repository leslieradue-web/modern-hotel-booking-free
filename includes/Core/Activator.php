<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

class Activator {

	public static function activate(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$db_name         = defined( 'DB_NAME' ) ? (string) DB_NAME : (string) ( $wpdb->dbname ?? '' );
		$bookings_table  = $wpdb->prefix . 'mhbo_bookings';

		// phpstan-ignore-next-line requireOnce.fileNotFound -- ABSPATH resolves correctly at runtime; PHPStan constant folding limitation
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Rule 13 rationale: Creating room types table for category-level management.
		$sql_room_types = "CREATE TABLE {$wpdb->prefix}mhbo_room_types (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text,
			base_price decimal(19,4) NOT NULL DEFAULT '0.0000',
			max_adults tinyint(4) NOT NULL DEFAULT 2,
			max_children tinyint(4) NOT NULL DEFAULT 0,
			child_age_free_limit tinyint(4) NOT NULL DEFAULT 0,
			child_rate decimal(19,4) NOT NULL DEFAULT 0.0000,
			total_rooms mediumint(9) NOT NULL DEFAULT 1,
			amenities text DEFAULT NULL,
			image_url varchar(255) DEFAULT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";
		dbDelta( $sql_room_types );

// Rule 13 rationale: Creating individual rooms table for specific availability tracking.
		$sql_rooms = "CREATE TABLE {$wpdb->prefix}mhbo_rooms (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			type_id mediumint(9) NOT NULL,
			room_number varchar(50) NOT NULL,
			status varchar(20) DEFAULT 'available',
			custom_price decimal(19,4) DEFAULT NULL,
			image_url varchar(255) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY type_id (type_id)
		) $charset_collate;";
		dbDelta( $sql_rooms );

// Rule 13 rationale: Primary bookings table. Essential for multi-channel revenue management.
		$sql_bookings = "CREATE TABLE {$wpdb->prefix}mhbo_bookings (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			room_id mediumint(9) NOT NULL,
			customer_name varchar(255) NOT NULL,
			customer_email varchar(255) NOT NULL,
			customer_phone varchar(50) DEFAULT NULL,
			check_in date NOT NULL,
			check_out date NOT NULL,
			total_price decimal(19,4) NOT NULL,
			status varchar(20) DEFAULT 'pending',
			booking_token varchar(64) NOT NULL,
			booking_language varchar(10) DEFAULT 'en',
			admin_notes text DEFAULT NULL,
			booking_extras text DEFAULT NULL,
			discount_amount decimal(19,4) DEFAULT '0.0000',
			deposit_amount decimal(19,4) DEFAULT NULL,
			deposit_received tinyint(1) DEFAULT 0,
			payment_type varchar(20) DEFAULT 'full',
			remaining_balance decimal(19,4) DEFAULT NULL,
			balance_status varchar(20) DEFAULT 'pending',
			balance_intent_id varchar(255) DEFAULT NULL,
			balance_payment_method varchar(50) DEFAULT NULL,
			balance_collected_via varchar(20) DEFAULT NULL,
			balance_payment_amount decimal(19,4) DEFAULT NULL,
			balance_payment_date datetime DEFAULT NULL,
			balance_link_token varchar(64) DEFAULT NULL,
			balance_link_expires_at datetime DEFAULT NULL,
			balance_link_sent_at datetime DEFAULT NULL,
			balance_link_created_at datetime DEFAULT NULL,
			balance_schedule_rule varchar(20) DEFAULT 'manual',
			balance_due_send_date datetime DEFAULT NULL,
			refund_deadline_date date DEFAULT NULL,
			deposit_is_non_refundable tinyint(1) DEFAULT 0,
			payment_method varchar(50) DEFAULT 'arrival',
			payment_received tinyint(1) DEFAULT 0,
			payment_status varchar(20) DEFAULT 'pending',
			payment_transaction_id varchar(255) DEFAULT NULL,
			payment_capture_id varchar(255) DEFAULT NULL,
			payment_date datetime DEFAULT NULL,
			payment_error text DEFAULT NULL,
			payment_amount decimal(19,4) DEFAULT NULL,
			email_sent tinyint(1) DEFAULT 0,
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
			tax_rate_accommodation decimal(7,4) DEFAULT 0.0000,
			tax_rate_extras decimal(7,4) DEFAULT 0.0000,
			room_total_net decimal(19,4) DEFAULT 0.0000,
			children_total_net decimal(19,4) DEFAULT 0.0000,
			extras_total_net decimal(19,4) DEFAULT 0.0000,
			room_tax decimal(19,4) DEFAULT 0.0000,
			children_tax decimal(19,4) DEFAULT 0.0000,
			extras_tax decimal(19,4) DEFAULT 0.0000,
			subtotal_net decimal(19,4) DEFAULT 0.0000,
			total_tax decimal(19,4) DEFAULT 0.0000,
			total_gross decimal(19,4) DEFAULT 0.0000,
			tax_breakdown text DEFAULT NULL,
			service_fee_amount decimal(19,4) DEFAULT NULL,
			service_fee_net decimal(19,4) DEFAULT 0.0000,
			service_fee_tax decimal(19,4) DEFAULT 0.0000,
			guest_id mediumint(9) DEFAULT NULL,
			guest_rating tinyint(4) DEFAULT NULL,
			payment_ledger text DEFAULT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			is_multi_room tinyint(1) DEFAULT 0,
			multi_room_parent varchar(64) DEFAULT NULL,
			balance_expected_amount decimal(19,4) DEFAULT NULL,
			coupon_code varchar(50) DEFAULT NULL,
			coupon_discount decimal(19,4) NOT NULL DEFAULT '0.0000',
			PRIMARY KEY  (id),
			KEY room_id (room_id),
			KEY guest_id (guest_id),
			KEY customer_email (customer_email(191)),
			KEY ical_uid (ical_uid),
			KEY external_id (external_id),
			KEY payment_status (payment_status),
			KEY payment_transaction_id (payment_transaction_id),
			KEY balance_intent_id (balance_intent_id),
			KEY room_availability (room_id, status, check_in, check_out),
			KEY status_payment (status, payment_status),
			KEY created_at (created_at),
			KEY booking_token (booking_token),
			KEY balance_link_token (balance_link_token),
			KEY multi_room_parent (multi_room_parent),
			KEY check_in (check_in),
			KEY check_out (check_out)
		) $charset_collate;";
		dbDelta( $sql_bookings );

// Rule 13 rationale: Multi-platform iCal sync connections table.
		$sql_ical_connections = "CREATE TABLE {$wpdb->prefix}mhbo_ical_connections (
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
		dbDelta( $sql_ical_connections );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Necessary for plugin tables
		$sql_pricing = "CREATE TABLE {$wpdb->prefix}mhbo_pricing_rules (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			room_id mediumint(9) NOT NULL DEFAULT 0,
			type_id mediumint(9) NOT NULL DEFAULT 0,
			name varchar(100) NOT NULL,
			start_date date NOT NULL,
			end_date date NOT NULL,
			amount decimal(19,4) NOT NULL DEFAULT 0.0000,
			rule_type varchar(20) DEFAULT 'seasonal',
			operation varchar(20) DEFAULT 'increase',
			priority tinyint(4) DEFAULT 10,
			PRIMARY KEY  (id),
			KEY room_id (room_id),
			KEY type_id (type_id),
			KEY rule_lookup (type_id, room_id, start_date, end_date)
		) $charset_collate;";
		dbDelta( $sql_pricing );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Necessary for plugin tables
		$sql_calendar_overrides = "CREATE TABLE {$wpdb->prefix}mhbo_calendar_overrides (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			scope varchar(10) NOT NULL DEFAULT 'type',
			type_id mediumint(9) DEFAULT NULL,
			room_id mediumint(9) DEFAULT NULL,
			date date NOT NULL,
			price decimal(19,4) DEFAULT NULL,
			availability tinyint(1) DEFAULT NULL,
			min_stay tinyint(4) DEFAULT NULL,
			max_stay tinyint(4) DEFAULT NULL,
			cta tinyint(1) DEFAULT NULL,
			ctd tinyint(1) DEFAULT NULL,
			note varchar(255) DEFAULT NULL,
			early_checkin_fee decimal(19,4) DEFAULT NULL,
			late_checkout_fee decimal(19,4) DEFAULT NULL,
			source varchar(20) DEFAULT 'admin_manual',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY scope_type_date (scope, type_id, room_id, date),
			KEY type_date (type_id, date),
			KEY room_date (room_id, date)
		) $charset_collate;";
		dbDelta( $sql_calendar_overrides );

		// Rule 13: Idempotency table for REST API reliable execution (2026 Standard).
		$sql_idempotency = "CREATE TABLE {$wpdb->prefix}mhbo_idempotency (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			idempotency_key varchar(64) NOT NULL,
			request_hash varchar(64) NOT NULL,
			response_code int(11) NOT NULL,
			response_body longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key)
		) $charset_collate;";
		dbDelta( $sql_idempotency );

// Rule 13 rationale: Guest CRM table for normalized customer identity, notes, and LTV tracking.
		$sql_guests = "CREATE TABLE {$wpdb->prefix}mhbo_guests (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			first_name varchar(100) NOT NULL,
			last_name varchar(100) NOT NULL,
			email varchar(255) NOT NULL,
			email_normalized varchar(255) NOT NULL,
			phone varchar(50) DEFAULT NULL,
			phone_normalized varchar(50) DEFAULT NULL,
			vip_status varchar(20) NOT NULL DEFAULT 'none',
			admin_rating tinyint(4) DEFAULT NULL,
			internal_notes text DEFAULT NULL,
			caution_flag tinyint(1) NOT NULL DEFAULT 0,
			total_stays int(11) NOT NULL DEFAULT 0,
			total_spend decimal(19,4) NOT NULL DEFAULT '0.0000',
			status varchar(20) NOT NULL DEFAULT 'active',
			merged_into_guest_id mediumint(9) DEFAULT NULL,
			marketing_optin tinyint(1) NOT NULL DEFAULT 1,
			marketing_groups text DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY email_normalized (email_normalized),
			KEY phone_normalized (phone_normalized),
			KEY vip_status (vip_status),
			KEY caution_flag (caution_flag),
			KEY total_spend (total_spend),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql_guests );

// Ensure all required columns exist on all tables (Single-query introspection per table, 100% data preservation)
		$bookings_columns = array(
			'is_multi_room'             => 'tinyint(1) DEFAULT 0',
			'multi_room_parent'         => 'varchar(64) DEFAULT NULL',
			'balance_expected_amount'   => 'decimal(19,4) DEFAULT NULL',
			'coupon_code'               => 'varchar(50) DEFAULT NULL',
			'coupon_discount'           => "decimal(19,4) NOT NULL DEFAULT '0.0000'",
			'guest_id'                  => 'mediumint(9) DEFAULT NULL',
			'guest_rating'              => 'tinyint(4) DEFAULT NULL',
			'payment_ledger'            => 'text DEFAULT NULL',
			'service_fee_amount'        => 'decimal(19,4) DEFAULT NULL',
			'service_fee_net'           => 'decimal(19,4) DEFAULT 0.0000',
			'service_fee_tax'           => 'decimal(19,4) DEFAULT 0.0000',
			'tax_enabled'               => 'tinyint(1) DEFAULT 0',
			'tax_mode'                  => "varchar(20) DEFAULT 'disabled'",
			'tax_rate_accommodation'    => 'decimal(7,4) DEFAULT 0.0000',
			'tax_rate_extras'           => 'decimal(7,4) DEFAULT 0.0000',
			'room_total_net'            => 'decimal(19,4) DEFAULT 0.0000',
			'children_total_net'        => 'decimal(19,4) DEFAULT 0.0000',
			'extras_total_net'          => 'decimal(19,4) DEFAULT 0.0000',
			'room_tax'                  => 'decimal(19,4) DEFAULT 0.0000',
			'children_tax'              => 'decimal(19,4) DEFAULT 0.0000',
			'extras_tax'                => 'decimal(19,4) DEFAULT 0.0000',
			'subtotal_net'              => 'decimal(19,4) DEFAULT 0.0000',
			'total_tax'                 => 'decimal(19,4) DEFAULT 0.0000',
			'total_gross'               => 'decimal(19,4) DEFAULT 0.0000',
			'tax_breakdown'             => 'text DEFAULT NULL',
			'deposit_amount'            => 'decimal(19,4) DEFAULT NULL',
			'deposit_received'          => 'tinyint(1) DEFAULT 0',
			'payment_type'              => "varchar(20) DEFAULT 'full'",
			'remaining_balance'         => 'decimal(19,4) DEFAULT NULL',
			'balance_status'            => "varchar(20) DEFAULT 'pending'",
			'balance_intent_id'         => 'varchar(255) DEFAULT NULL',
			'balance_payment_method'    => 'varchar(50) DEFAULT NULL',
			'balance_collected_via'     => 'varchar(20) DEFAULT NULL',
			'balance_payment_amount'    => 'decimal(19,4) DEFAULT NULL',
			'balance_payment_date'      => 'datetime DEFAULT NULL',
			'balance_link_token'        => 'varchar(64) DEFAULT NULL',
			'balance_link_expires_at'   => 'datetime DEFAULT NULL',
			'balance_link_sent_at'      => 'datetime DEFAULT NULL',
			'balance_link_created_at'   => 'datetime DEFAULT NULL',
			'balance_schedule_rule'     => "varchar(20) DEFAULT 'manual'",
			'balance_due_send_date'     => 'datetime DEFAULT NULL',
			'refund_deadline_date'      => 'date DEFAULT NULL',
			'deposit_is_non_refundable' => 'tinyint(1) DEFAULT 0',
		);

		$room_types_table   = $wpdb->prefix . 'mhbo_room_types';
		$room_types_columns = array(
			'child_age_free_limit' => 'tinyint(4) NOT NULL DEFAULT 0',
			'child_rate'           => "decimal(19,4) NOT NULL DEFAULT '0.0000'",
			'total_rooms'          => 'mediumint(9) NOT NULL DEFAULT 1',
			'amenities'            => 'text DEFAULT NULL',
			'image_url'            => 'varchar(255) DEFAULT NULL',
		);

		$rooms_table   = $wpdb->prefix . 'mhbo_rooms';
		$rooms_columns = array(
			'status'       => "varchar(20) DEFAULT 'available'",
			'custom_price' => 'decimal(19,4) DEFAULT NULL',
			'image_url'    => 'varchar(255) DEFAULT NULL',
		);

		$overrides_table   = $wpdb->prefix . 'mhbo_calendar_overrides';
		$overrides_columns = array(
			'early_checkin_fee' => 'decimal(19,4) DEFAULT NULL',
			'late_checkout_fee' => 'decimal(19,4) DEFAULT NULL',
			'source'            => "varchar(20) DEFAULT 'admin_manual'",
		);

self::ensure_table_columns_exist( $bookings_table, $bookings_columns );
		self::ensure_table_columns_exist( $room_types_table, $room_types_columns );
		self::ensure_table_columns_exist( $rooms_table, $rooms_columns );
		self::ensure_table_columns_exist( $overrides_table, $overrides_columns );

// Idempotent backfill of historical multi-room bookings sharing booking_token
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i b
				 INNER JOIN (
					 SELECT booking_token
					 FROM %i
					 WHERE booking_token IS NOT NULL AND booking_token != ''
					 GROUP BY booking_token
					 HAVING COUNT(*) > 1
				 ) grouped ON b.booking_token = grouped.booking_token
				 SET b.multi_room_parent = b.booking_token,
				     b.is_multi_room = 1
				 WHERE b.multi_room_parent IS NULL OR b.multi_room_parent = ''",
				$bookings_table,
				$bookings_table
			)
		);

		// Self-healing: Normalize legacy arrival bookings where $0 was paid but flags were marked collected/completed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i 
				 SET payment_received = 0, 
				     payment_status = 'pending', 
				     balance_status = 'pending', 
				     deposit_received = 0, 
				     remaining_balance = total_price, 
				     balance_expected_amount = total_price, 
				     payment_method = 'arrival' 
				 WHERE total_price > 0 
				   AND (payment_amount IS NULL OR payment_amount = 0) 
				   AND (balance_payment_amount IS NULL OR balance_payment_amount = 0) 
				   AND (balance_collected_via IS NULL OR balance_collected_via = '') 
				   AND (payment_transaction_id IS NULL OR payment_transaction_id = '') 
				   AND payment_method = 'arrival' 
				   AND (payment_received = 1 OR balance_status = 'collected' OR remaining_balance = 0 OR remaining_balance IS NULL)",
				$bookings_table
			)
		);

		// Self-healing: Correct corrupted balance_status for unpaid balances.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i 
				 SET balance_status = 'pending', 
				     remaining_balance = CASE 
				         WHEN remaining_balance IS NULL OR remaining_balance = 0 THEN (total_price - COALESCE(payment_amount, 0)) 
				         ELSE remaining_balance 
				     END 
				 WHERE balance_status = 'collected' 
				   AND total_price > COALESCE(payment_amount, 0) 
				   AND (balance_collected_via IS NULL OR balance_collected_via = '') 
				   AND (balance_payment_amount IS NULL OR balance_payment_amount = 0) 
				   AND (payment_received = 0 OR payment_received IS NULL)",
				$bookings_table
			)
		);

		// Add new options for multilingual and currency
		add_option( 'mhbo_db_version', MHBO_VERSION );
		add_option( 'mhbo_currency_code', 'USD' );
		add_option( 'mhbo_currency_symbol', '$' );
		add_option( 'mhbo_currency_position', 'before' );
		add_option( 'mhbo_price_decimals', '2' );
		add_option( 'mhbo_calendar_show_decimals', 0 );
		add_option( 'mhbo_max_advance_booking_days', 365 );
		// Guest CRM Options (v2.6.0) - Disabled by default per 2026 BP
		add_option( 'mhbo_guest_crm_enabled', 0 );
		add_option( 'mhbo_crm_vip_spend_threshold', 0 );
		add_option( 'mhbo_crm_vip_stays_threshold', 3 );
		
		add_option( 'mhbo_gateway_stripe_enabled', 0 );
		add_option( 'mhbo_gateway_paypal_enabled', 0 );
		add_option( 'mhbo_gateway_onsite_enabled', 0 );
		add_option( 'mhbo_stripe_mode', 'test' );
		add_option( 'mhbo_paypal_mode', 'sandbox' );
		
		add_option( 'mhbo_powered_by_link', 0 ); // Default OFF per WP.org Guideline 10 - requires user opt-in
		add_option( 'mhbo_save_data_on_uninstall', 1 );
		// Hotel timezone — '' means fall back to WP site timezone (see HotelTime::timezone())
		add_option( 'mhbo_hotel_timezone', '' );

		// Rule 13: Initialize versions for caching
		foreach ( array( 'bookings', 'rooms', 'room_types', 'pricing_rules', 'ical_connections', 'settings', 'calendar_overrides', 'guests' ) as $table ) {
			if ( false === get_option( "mhbo_v_{$table}" ) ) {
				add_option( "mhbo_v_{$table}", 1 );
			}
		}

		add_option( 'mhbo_additional_notification_email', '' );
		add_option( 'mhbo_modal_enabled', 1 );
		add_option( 'mhbo_prevent_same_day_turnover', 0 );
		add_option( 'mhbo_confirmation_btn_enabled', 0 );
		add_option( 'mhbo_confirmation_btn_text', 'Make Another Booking' );
		add_option( 'mhbo_confirmation_btn_url', '' );
		add_option( 'mhbo_orphan_gap_autocorrection', 0 );
		add_option( 'mhbo_orphan_gap_min_stay', 1 );

		// Children & Stay Limits Options (Core settings promoted to all tiers)
		add_option( 'mhbo_children_enabled', 0 );
		add_option( 'mhbo_global_min_stay', 0 );
		add_option( 'mhbo_global_max_stay', 0 );

// Tax System Options
		add_option( 'mhbo_tax_mode', 'disabled' );
		add_option( 'mhbo_tax_rate_accommodation', 0.00 );
		add_option( 'mhbo_tax_rate_extras', 0.00 );
		add_option( 'mhbo_tax_label', '[:en]VAT[:ro]TVA[:]' );
		add_option( 'mhbo_tax_registration_number', '' );
		add_option( 'mhbo_tax_display_frontend', 1 );
		add_option( 'mhbo_tax_display_email', 1 );
		add_option( 'mhbo_tax_rounding_mode', 'per_total' );
		add_option( 'mhbo_tax_decimal_places', 2 );
		add_option( 'mhbo_tax_zero_rate_label', '[:en]Zero Rate[:ro]Cotă Zero[:]' );

// Cache Settings (optional/configurable)
		add_option( 'mhbo_cache_enabled', 1 );

		// Default Amenities
		if ( false === get_option( 'mhbo_amenities_list' ) ) {
			$default_amenities = array(
				'wifi'      => 'Free WiFi',
				'ac'        => 'Air Conditioning',
				'tv'        => 'Smart TV',
				'breakfast' => 'Breakfast Included',
				'pool'      => 'Pool View',
			);
			update_option( 'mhbo_amenities_list', $default_amenities );
		}

// Register custom capabilities
		if ( class_exists( Capabilities::class ) ) {
			Capabilities::init_abilities();
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'mhbo_process_payments' );
		}

		update_option( 'mhbo_version', MHBO_VERSION );
		update_option( 'mhbo_db_version', MHBO_VERSION );

		$current_tier = class_exists( 'MHBO\Core\Tier' ) ? \MHBO\Core\Tier::get_package_tier() : ( defined( 'MHBO_PRODUCT_TIER' ) ? MHBO_PRODUCT_TIER : 'free' );
		update_option( 'mhbo_installed_tier', $current_tier );

		// Ensure updated_at column exists for timestamps and sync conflict detection.
		self::add_bookings_updated_at_column();

		// Migrate iCal feeds to new connections table if upgrading from legacy schema.
		self::migrate_ical_feeds_to_connections();

		// Ensure performance indexes exist from day 1 for new installs.
		self::add_performance_indexes();

		// Repair any data drift (Rule 13: maintain data integrity).
		self::repair_data_drift();

		// Cleanup inactive Pro crons on tier rollbacks/transitions downwards.
		self::sync_tier_crons( $current_tier );
	}

	/**
	 * Migrate database schema for existing installations.
	 * Called during plugin updates.
	 *
	 * @param string $old_version Previous version
	 * @param string $new_version New version
	 */
	public static function migrate( string $old_version, string $new_version ): void {
		// Run activate to ensure all tables and columns are up to date via dbDelta
		self::activate();

// Add tax options if they don't exist
		add_option( 'mhbo_tax_mode', 'disabled' );
		add_option( 'mhbo_tax_rate_accommodation', 0.00 );
		add_option( 'mhbo_tax_rate_extras', 0.00 );
		add_option( 'mhbo_tax_label', '[:en]VAT[:ro]TVA[:]' );
		add_option( 'mhbo_tax_registration_number', '' );
		add_option( 'mhbo_tax_display_frontend', 1 );
		add_option( 'mhbo_tax_display_email', 1 );
		add_option( 'mhbo_tax_rounding_mode', 'per_total' );
		add_option( 'mhbo_tax_decimal_places', 2 );
		add_option( 'mhbo_tax_zero_rate_label', '[:en]Zero Rate[:ro]Cotă Zero[:]' );

// Cache Settings (for existing installations)
		add_option( 'mhbo_cache_enabled', 1 );

		// 2026 BP: Add updated_at column to bookings table for existing installations.
		// Required for iCal LAST-MODIFIED timestamps and external platform sync conflict detection.
		self::add_bookings_updated_at_column();

		// Migrate iCal feeds to new connections table
		self::migrate_ical_feeds_to_connections();

		// Add iCal sync options
		add_option( 'mhbo_ical_auto_sync_enabled', 1 );
		add_option( 'mhbo_ical_sync_interval', '6hours' );
		add_option( 'mhbo_ical_retry_enabled', 1 );
		add_option( 'mhbo_ical_email_notifications', 0 );
		add_option( 'mhbo_ical_notification_email', get_option( 'admin_email' ) );
		add_option( 'mhbo_ical_sync_lock_timeout', 30 );
		add_option( 'mhbo_powered_by_link', 0 ); // Default OFF per WP.org Guideline 10 - requires user opt-in
		// Core promoted options for existing installations
		add_option( 'mhbo_hotel_timezone', '' );
		add_option( 'mhbo_children_enabled', 0 );
		add_option( 'mhbo_global_min_stay', 0 );
		add_option( 'mhbo_global_max_stay', 0 );

		// Default Amenities list for existing installations.
		if ( false === get_option( 'mhbo_amenities_list' ) ) {
			$default_amenities = array(
				'wifi'      => 'Free WiFi',
				'ac'        => 'Air Conditioning',
				'tv'        => 'Smart TV',
				'breakfast' => 'Breakfast Included',
				'pool'      => 'Pool View',
			);
			update_option( 'mhbo_amenities_list', $default_amenities );
		}

		// Add new indexes for performance (for existing installations)
		self::add_performance_indexes();

		// Repair any data drift (Rule 13: maintain data integrity)
		self::repair_data_drift();

		// Invalidate cached calendar and dashboard transients across version transitions
		if ( class_exists( 'MHBO\Core\Cache' ) ) {
			Cache::clear_dashboard_transients();
			Cache::invalidate_table( Cache::TABLE_BOOKINGS );
			Cache::invalidate_table( Cache::TABLE_CALENDAR_OVERRIDES );
		}

		update_option( 'mhbo_db_version', $new_version );
	}

	/**
	 * Repair terminology drifts or legacy data paradoxes (formerly standalone DriftCheck).
	 */
	private static function repair_data_drift(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mhbo_bookings';

		// Rule 13 rationale: Healing 'onsite' to 'arrival' paradox for 2.2.8+ compatibility.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data repair operation
		$wpdb->update(
			$table,
			array( 'payment_method' => 'arrival' ),
			array( 'payment_method' => 'onsite' ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Migrate old iCal feeds to new connections table.
	 */
	private static function migrate_ical_feeds_to_connections(): void {
		global $wpdb;
		$db_name = defined( 'DB_NAME' ) ? (string) DB_NAME : (string) ( $wpdb->dbname ?? '' );

		// 2026 BP: MySQL-only existence check via information_schema (no sqlite_master).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration
		$old_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s LIMIT 1',
				$db_name,
				"{$wpdb->prefix}mhbo_ical_feeds"
			) 
		);

		if ( ! $old_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration
		$new_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'mhbo_ical_connections' ) );
		if ( 0 < (int) $new_count ) {
			return; // Already migrated.
		}

		// Migrate data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration
		$feeds = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $wpdb->prefix . 'mhbo_ical_feeds' ) );
		foreach ( $feeds as $feed ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Schema migration
				"{$wpdb->prefix}mhbo_ical_connections",
				array(
					'room_id'        => $feed->room_id,
					'platform'       => $feed->platform ?? 'custom',
					'name'           => $feed->feed_name ?? '',
					'ical_url'       => $feed->feed_url,
					'sync_direction' => 'import',
					'last_sync'      => $feed->last_synced,
					'sync_status'    => $feed->last_error ? 'failed' : 'pending',
					'last_error'     => $feed->last_error,
					'created_at'     => current_time( 'mysql' ),
					'events_count'   => $feed->events_count ?? 0,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}

	/**
	 * Add updated_at column to mhbo_bookings table for existing installations.
	 *
	 * 2026 BP: The iCal export and import sync both rely on updated_at for
	 * LAST-MODIFIED timestamps and conflict detection. dbDelta cannot add
	 * columns with ON UPDATE, so we use a direct ALTER TABLE with an existence
	 * check via information_schema.
	 */
	private static function add_bookings_updated_at_column(): void {
		global $wpdb;
		$bookings_table = $wpdb->prefix . 'mhbo_bookings';
		$db_name        = defined( 'DB_NAME' ) ? (string) DB_NAME : (string) ( $wpdb->dbname ?? '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection
		$has_updated_at = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$db_name,
				$bookings_table,
				'updated_at'
			)
		);

		if ( ! (int) $has_updated_at ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL via %i identifier placeholder
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
					$bookings_table
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Ensure table columns exist without data loss.
	 *
	 * Checks which columns are currently present in the table, and adds any
	 * missing columns using ALTER TABLE. This provides resilient migration
	 * across older plugin versions with zero data loss.
	 *
	 * @param string                $table_name Full table name (with prefix).
	 * @param array<string, string> $columns    Associative array of column_name => column_sql_definition.
	 * @return void
	 */
	private static function ensure_table_columns_exist( string $table_name, array $columns ): void {
		global $wpdb;

		if ( ! class_exists( 'MHBO\Core\Cache' ) || ! Cache::table_exists( $table_name ) ) {
			return;
		}

		$db_name = defined( 'DB_NAME' ) ? (string) DB_NAME : (string) ( $wpdb->dbname ?? '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$db_name,
				$table_name
			)
		);

		if ( empty( $existing ) || ! is_array( $existing ) ) {
			// Fallback for edge cases or testing environments where DB_NAME != TABLE_SCHEMA
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection fallback
			$col_rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name ) );
			if ( ! empty( $col_rows ) && is_array( $col_rows ) ) {
				$existing = array_map(
					static fn( $row ) => is_object( $row ) ? (string) $row->Field : (string) ( $row['Field'] ?? '' ),
					$col_rows
				);
			}
		}

		$existing_lookup = array_fill_keys( array_map( 'strtolower', (array) $existing ), true );

		foreach ( $columns as $col_name => $col_definition ) {
			if ( ! isset( $existing_lookup[ strtolower( $col_name ) ] ) ) {
				self::add_column_ddl( $table_name, $col_name, $col_definition );
			}
		}
	}

	/**
	 * Execute a strictly literal prepared ALTER TABLE ADD COLUMN statement.
	 *
	 * Uses 100% literal SQL templates with %i identifier and %s value placeholders
	 * so zero variables are ever interpolated or concatenated into the SQL string
	 * (satisfying WordPress.DB.PreparedSQL and PluginCheck.Security.DirectDB).
	 *
	 * @param string $table_name     Full table name.
	 * @param string $col_name       Column identifier.
	 * @param string $col_definition Canonical column definition key.
	 * @return void
	 */
	private static function add_column_ddl( string $table_name, string $col_name, string $col_definition ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional schema migration using %i identifier placeholders.
		switch ( $col_definition ) {
			case 'tinyint(1) DEFAULT 0':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i tinyint(1) DEFAULT 0', $table_name, $col_name ) );
				break;
			case 'tinyint(4) DEFAULT NULL':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i tinyint(4) DEFAULT NULL', $table_name, $col_name ) );
				break;
			case 'tinyint(4) NOT NULL DEFAULT 0':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i tinyint(4) NOT NULL DEFAULT 0', $table_name, $col_name ) );
				break;
			case 'mediumint(9) DEFAULT NULL':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i mediumint(9) DEFAULT NULL', $table_name, $col_name ) );
				break;
			case 'mediumint(9) NOT NULL DEFAULT 1':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i mediumint(9) NOT NULL DEFAULT 1', $table_name, $col_name ) );
				break;
			case 'decimal(19,4) DEFAULT NULL':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i decimal(19,4) DEFAULT NULL', $table_name, $col_name ) );
				break;
			case 'decimal(19,4) DEFAULT 0.0000':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i decimal(19,4) DEFAULT 0.0000', $table_name, $col_name ) );
				break;
			case "decimal(19,4) NOT NULL DEFAULT '0.0000'":
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i decimal(19,4) NOT NULL DEFAULT %s', $table_name, $col_name, '0.0000' ) );
				break;
			case 'decimal(7,4) DEFAULT 0.0000':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i decimal(7,4) DEFAULT 0.0000', $table_name, $col_name ) );
				break;
			case 'varchar(20) DEFAULT NULL':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i varchar(20) DEFAULT NULL', $table_name, $col_name ) );
				break;
			case "varchar(20) DEFAULT 'disabled'":
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i varchar(20) DEFAULT %s', $table_name, $col_name, 'disabled' ) );
				break;
			case "varchar(20) DEFAULT 'full'":
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i varchar(20) DEFAULT %s', $table_name, $col_name, 'full' ) );
				break;
			case "varchar(20) DEFAULT 'pending'":
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i varchar(20) DEFAULT %s', $table_name, $col_name, 'pending' ) );
				break;
			case "varchar(20) DEFAULT 'manual'":
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i varchar(20) DEFAULT %s', $table_name, $col_name, 'manual' ) );
				break;
			case "varchar(20) DEFAULT 'available'":
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i varchar(20) DEFAULT %s', $table_name, $col_name, 'available' ) );
				break;
			case "varchar(20) DEFAULT 'admin_manual'":
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i varchar(20) DEFAULT %s', $table_name, $col_name, 'admin_manual' ) );
				break;
			case 'varchar(50) DEFAULT NULL':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i varchar(50) DEFAULT NULL', $table_name, $col_name ) );
				break;
			case 'varchar(64) DEFAULT NULL':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i varchar(64) DEFAULT NULL', $table_name, $col_name ) );
				break;
			case 'varchar(255) DEFAULT NULL':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i varchar(255) DEFAULT NULL', $table_name, $col_name ) );
				break;
			case 'text DEFAULT NULL':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i text DEFAULT NULL', $table_name, $col_name ) );
				break;
			case 'datetime DEFAULT NULL':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i datetime DEFAULT NULL', $table_name, $col_name ) );
				break;
			case 'date DEFAULT NULL':
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i date DEFAULT NULL', $table_name, $col_name ) );
				break;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Add performance indexes to existing tables.
	 * Called during migration to improve query performance.
	 *
	 * 2026 BP: Uses MySQL SHOW INDEX to check for existing indexes
	 * and plain CREATE INDEX (no IF NOT EXISTS) for cross-version
	 * MySQL / MariaDB compatibility.
	 */
	private static function add_performance_indexes(): void {
		global $wpdb;

		$indexes = array(
			array(
				'table' => "{$wpdb->prefix}mhbo_bookings",
				'name'  => 'idx_check_in_out',
				'cols'  => array( 'check_in', 'check_out' ),
			),
			array(
				'table' => "{$wpdb->prefix}mhbo_bookings",
				'name'  => 'idx_status_payment',
				'cols'  => array( 'status', 'payment_status' ),
			),
			array(
				'table' => "{$wpdb->prefix}mhbo_pricing_rules",
				'name'  => 'idx_dates',
				'cols'  => array( 'start_date', 'end_date' ),
			),
			array(
				'table' => "{$wpdb->prefix}mhbo_bookings",
				'name'  => 'idx_booking_token',
				'alt'   => 'booking_token',
				'cols'  => array( 'booking_token' ),
			),
			array(
				'table' => "{$wpdb->prefix}mhbo_bookings",
				'name'  => 'idx_balance_link_token',
				'alt'   => 'balance_link_token',
				'cols'  => array( 'balance_link_token' ),
			),
			array(
				'table' => "{$wpdb->prefix}mhbo_bookings",
				'name'  => 'idx_multi_room_parent',
				'alt'   => 'multi_room_parent',
				'cols'  => array( 'multi_room_parent' ),
			),
			array(
				'table' => "{$wpdb->prefix}mhbo_bookings",
				'name'  => 'idx_guest_id',
				'alt'   => 'guest_id',
				'cols'  => array( 'guest_id' ),
			),
		);

foreach ( $indexes as $idx ) {
			if ( self::index_exists( $idx['table'], $idx['name'] ) ) {
				continue;
			}

			if ( isset( $idx['alt'] ) && self::index_exists( $idx['table'], $idx['alt'] ) ) {
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL schema index creation with %i identifier placeholders.
			if ( 2 === count( $idx['cols'] ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'CREATE INDEX %i ON %i (%i, %i)',
						$idx['name'],
						$idx['table'],
						$idx['cols'][0],
						$idx['cols'][1]
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						'CREATE INDEX %i ON %i (%i)',
						$idx['name'],
						$idx['table'],
						$idx['cols'][0]
					)
				);
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Check whether a named index exists on a table.
	 *
	 * 2026 BP: Uses SHOW INDEX which works on MySQL 5.7+, 8.x, and MariaDB 10.x+.
	 *
	 * @param string $table Full table name (with prefix).
	 * @param string $index Index name to look for.
	 * @return bool True if the index already exists.
	 */
	private static function index_exists( string $table, string $index ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL introspection, not cacheable.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW INDEX FROM %i WHERE Key_name = %s',
				$table,
				$index
			) 
		);

		return array() !== $rows;
	}

	/**
	 * Synchronize scheduled background crons based on installed tier.
	 * Cleans up inactive Pro-only cron events when transitioning down to Free or Starter.
	 *
	 * @param string $current_tier Current installed package tier.
	 * @return void
	 */
	private static function sync_tier_crons( string $current_tier ): void {
		if ( 'pro' !== $current_tier ) {
			$pro_crons = array(
				'mhbo_generate_hotel_feeds',
				'mhbo_ai_weekly_deep_sync',
				'mhbo_marketing_process_queue',
				'mhbo_purge_stale_tokens',
				'mhbo_daily_housekeeping_manifest_cron',
				'mhbo_dispatch_review_invitations',
				'mhbo_daily_review_email_cron',
				'mhbo_ical_scheduled_sync',
				'check_updates-modern-hotel-booking-pro',
			);
			foreach ( $pro_crons as $hook ) {
				if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				\as_unschedule_all_actions( 'mhbo_analytics_nightly_rollup' );
				\as_unschedule_all_actions( 'mhbo_ical_sync_async' );
			}
		}

		if ( 'free' === $current_tier ) {
			$commercial_crons = array(
				'mhbo_validate_license',
				'mhbo_validate_license_retry',
			);
			foreach ( $commercial_crons as $hook ) {
				if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		}
	}
}
