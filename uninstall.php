<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Modern_Hotel_Booking
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if we should save data on uninstall (default to 1 for safety).
// Data is PRESERVED unless mhbo_save_data_on_uninstall is EXPLICITLY set to 0.
$mhbo_option_val = get_option( 'mhbo_save_data_on_uninstall', 1 );
$mhbo_save_data  = ( false === $mhbo_option_val || '' === $mhbo_option_val || null === $mhbo_option_val ) ? 1 : (int) $mhbo_option_val;

$mhbo_cron_hooks = array(
	'mhbo_hourly_sync',
	'mhbo_daily_maintenance',
	'mhbo_hourly_maintenance',
	'mhbo_ical_scheduled_sync',
	'mhbo_ical_scheduled_sync_single',
	'mhbo_recalculate_guest_stats',
	'mhbo_migrate_guests_batch',
	'mhbo_daily_housekeeping_manifest_cron',
	'mhbo_dispatch_review_invitations',
	'mhbo_daily_review_email_cron',
	'mhbo_send_balance_link',
	'mhbo_webhook_deliver',
	'check_updates-modern-hotel-booking',
	
	'mhbo_generate_hotel_feeds',
	'mhbo_ai_weekly_deep_sync',
	'mhbo_marketing_process_queue',
	'mhbo_purge_stale_tokens',
	'check_updates-modern-hotel-booking-pro',
);

$mhbo_clear_crons = static function () use ( $mhbo_cron_hooks ): void {
	foreach ( $mhbo_cron_hooks as $mhbo_hook ) {
		wp_clear_scheduled_hook( $mhbo_hook );
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		\as_unschedule_all_actions( 'mhbo_analytics_nightly_rollup' );
		\as_unschedule_all_actions( 'mhbo_migrate_guests_batch' );
		\as_unschedule_all_actions( 'mhbo_recalculate_guest_stats' );
		\as_unschedule_all_actions( 'mhbo_send_balance_link' );
		\as_unschedule_all_actions( 'mhbo_ical_sync_async' );
	}
};

if ( 0 !== $mhbo_save_data ) {
	if ( function_exists( 'is_multisite' ) && is_multisite() ) {
		$mhbo_sites = function_exists( 'get_sites' ) ? get_sites( array( 'number' => 0 ) ) : array();
		foreach ( $mhbo_sites as $mhbo_site ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Multisite database context switch required for cron cleanup.
			switch_to_blog( (int) $mhbo_site->blog_id );
			$mhbo_clear_crons();
			restore_current_blog();
		}
	} else {
		$mhbo_clear_crons();
	}
	return; // Let script complete normally for WordPress
}

/** @var \wpdb $wpdb */
global $wpdb;

$mhbo_purge_single_blog = static function () use ( $wpdb, $mhbo_clear_crons ): void {
	// Drop custom tables (13 canonical tables + legacy ical feeds)
	$mhbo_tables = array(
		$wpdb->prefix . 'mhbo_room_types',
		$wpdb->prefix . 'mhbo_rooms',
		$wpdb->prefix . 'mhbo_bookings',
		$wpdb->prefix . 'mhbo_ical_feeds',
		$wpdb->prefix . 'mhbo_ical_connections',
		$wpdb->prefix . 'mhbo_pricing_rules',
		$wpdb->prefix . 'mhbo_calendar_overrides',
		$wpdb->prefix . 'mhbo_idempotency',
		$wpdb->prefix . 'mhbo_guests',
		$wpdb->prefix . 'mhbo_ical_logs',
		$wpdb->prefix . 'mhbo_coupons',
		$wpdb->prefix . 'mhbo_chat_sessions',
		$wpdb->prefix . 'mhbo_vector_cache',
		$wpdb->prefix . 'mhbo_communication_logs',
	);

	foreach ( $mhbo_tables as $mhbo_table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$mhbo_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	// Delete ALL mhbo_ options (comprehensive cleanup)
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Cleanup query, hardcoded strings
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mhbo_%'"
	);

	// Clear scheduled background events
	$mhbo_clear_crons();

	// Clear any remaining transients and transient timeouts
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Cleanup query, hardcoded strings
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mhbo_%' OR option_name LIKE '_site_transient_mhbo_%' OR option_name LIKE '_transient_timeout_mhbo_%' OR option_name LIKE '_site_transient_timeout_mhbo_%'"
	);

	// Delete user meta set by plugin (e.g. mhbo_bookings_per_page)
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'mhbo_%'"
	);

	// Delete post meta set by plugin
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'mhbo_%' OR meta_key LIKE '_mhbo_%'"
	);

	// Clean up roles and capabilities
	global $wp_roles;
	$mhbo_all_roles = function_exists( 'wp_roles' ) ? wp_roles() : ( isset( $wp_roles ) ? $wp_roles : null );

	if ( $mhbo_all_roles ) {
		$mhbo_capabilities = array(
			'mhbo_manage_bookings',
			'mhbo_view_bookings',
			'mhbo_manage_rooms',
			'mhbo_manage_housekeeping',
			'mhbo_manage_pricing',
			'mhbo_view_analytics',
			'mhbo_manage_settings',
			'mhbo_create_booking',
			'mhbo_manage_guests',
			'mhbo_export_guests',
			'mhbo_process_payments',
		);

		foreach ( $mhbo_all_roles->roles as $mhbo_role_name => $mhbo_role_info ) {
			$mhbo_role = get_role( $mhbo_role_name );
			if ( $mhbo_role ) {
				foreach ( $mhbo_capabilities as $mhbo_cap ) {
					if ( $mhbo_role->has_cap( $mhbo_cap ) ) {
						$mhbo_role->remove_cap( $mhbo_cap );
					}
				}
			}
		}

		// Remove custom plugin staff roles completely
		if ( get_role( 'mhbo_hotel_manager' ) ) {
			remove_role( 'mhbo_hotel_manager' );
		}
		if ( get_role( 'mhbo_front_desk' ) ) {
			remove_role( 'mhbo_front_desk' );
		}
		if ( get_role( 'mhbo_housekeeper' ) ) {
			remove_role( 'mhbo_housekeeper' );
		}
		if ( get_role( 'mhbo_ai_guest' ) ) {
			remove_role( 'mhbo_ai_guest' );
		}
	}

	// Remove marketing email template posts if any exist
	if ( function_exists( 'get_posts' ) && function_exists( 'wp_delete_post' ) ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- suppress_filters is false, used for uninstall cleanup.
		$mhbo_tpl_posts = get_posts(
			array(
				'post_type'        => 'mhbo_email_template',
				'posts_per_page'   => -1,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		if ( is_array( $mhbo_tpl_posts ) ) {
			foreach ( $mhbo_tpl_posts as $mhbo_tpl_id ) {
				wp_delete_post( (int) $mhbo_tpl_id, true );
			}
		}
	}
};

if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	$mhbo_sites = function_exists( 'get_sites' ) ? get_sites( array( 'number' => 0 ) ) : array();
	foreach ( $mhbo_sites as $mhbo_site ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Multisite database context switch required for table and options cleanup.
		switch_to_blog( (int) $mhbo_site->blog_id );
		$mhbo_purge_single_blog();
		restore_current_blog();
	}
	if ( isset( $wpdb->sitemeta ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'mhbo_%' OR meta_key LIKE '_site_transient_mhbo_%' OR meta_key LIKE '_site_transient_timeout_mhbo_%'"
		);
	}
} else {
	$mhbo_purge_single_blog();
}

// Remove generated hotel feed files and clean AI discovery markers from filesystem
if ( ! function_exists( 'WP_Filesystem' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
if ( WP_Filesystem() ) {
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->delete( trailingslashit( (string) ABSPATH ) . 'google-hotels.xml' );
		$wp_filesystem->delete( trailingslashit( (string) ABSPATH ) . 'google-prices.xml' );

		// Clean up MHBO AI discovery markers from root if present
		foreach ( array( 'llms.txt', 'llms-full.txt' ) as $mhbo_llm_file ) {
			$mhbo_llm_path = trailingslashit( (string) ABSPATH ) . $mhbo_llm_file;
			if ( $wp_filesystem->exists( $mhbo_llm_path ) ) {
				$mhbo_llm_content = $wp_filesystem->get_contents( $mhbo_llm_path );
				if ( false !== $mhbo_llm_content ) {
					$mhbo_start = strpos( $mhbo_llm_content, '<!-- MHBO_AI_DISCOVERY_START -->' );
					$mhbo_end   = strpos( $mhbo_llm_content, '<!-- MHBO_AI_DISCOVERY_END -->' );
					if ( false !== $mhbo_start && false !== $mhbo_end && $mhbo_end > $mhbo_start ) {
						$mhbo_rem = trim( substr( $mhbo_llm_content, 0, $mhbo_start ) . substr( $mhbo_llm_content, $mhbo_end + strlen( '<!-- MHBO_AI_DISCOVERY_END -->' ) ) );
						if ( '' === $mhbo_rem ) {
							$wp_filesystem->delete( $mhbo_llm_path );
						} else {
							$wp_filesystem->put_contents( $mhbo_llm_path, $mhbo_rem . "\n" );
						}
					}
				}
			}
		}
	}
}
