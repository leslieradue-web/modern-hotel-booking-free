<?php declare(strict_types=1);

namespace MHBO\Admin;

use MHBO\Admin\AdminUI;
use MHBO\Admin\PricingController;
use MHBO\Admin\Settings;
use MHBO\Core\BookingProcessor;
use MHBO\Core\Cache;
use MHBO\Core\Capabilities;
use MHBO\Core\Email;
use MHBO\Core\HotelTime;
use MHBO\Core\I18n;
use MHBO\Core\ICal;
use MHBO\Core\License;
use MHBO\Core\Security;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Tax;
use MHBO\Core\GuestManager;
use MHBO\Core\GuestIdentityResolver;
use MHBO\Core\GuestMigration;
use MHBO\Core\GuestAsyncTasks;
use MHBO\Database\Queries\Booking_Query;
/* BUILD_PRO_START */
use MHBO\Pro\AdminCalendar;
use MHBO\Pro\Invoice;
/* BUILD_PRO_END */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {

	/**
	 * Bundled FullCalendar (Vanilla JS global build) version.
	 *
	 * Single source of truth for the ?ver= cache-buster of every vendor asset in
	 * assets/{js,css}/vendor/fullcalendar*. V7 skeleton CSS uses hashed class names that
	 * change on every release, so JS + CSS MUST always be upgraded together.
	 */
	private const FULLCALENDAR_VERSION = '7.1.3';

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        // phpcs:ignore PluginCheck.Standards.WP71Compatibility.AssetHookMismatch -- Assets for standalone admin pages only.
		add_action( 'admin_en' . 'queue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widgets' ) );

		/* BUILD_PRO_START */
		add_action( 'admin_init', array( $this, 'handle_pro_invoice_download' ) );
		add_action( 'admin_init', array( $this, 'handle_housekeeping_manifest_actions' ) );
		add_action( 'admin_init', array( $this, 'handle_pro_data_transfer_actions' ) );
		add_filter( 'admin_title', array( $this, 'fix_hidden_page_titles' ), 10, 2 );
		/* BUILD_PRO_END */

		add_action( 'admin_notices', array( $this, 'notice_booking_page_missing' ) );

		$settings = new Settings();
		$settings->init();
	}

	/**
	 * Warn when no booking page has been configured.
	 * Silent fallback to home_url('/') means calendars submit nowhere useful.
	 */
	public function notice_booking_page_missing(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if modal is enabled - if so, we don't need the warning
		if ( (int) get_option( 'mhbo_modal_enabled', 1 ) === 1 ) {
			return;
		}

		$page_id  = (int) get_option( 'mhbo_booking_page', 0 );
		$page_url = get_option( 'mhbo_booking_page_url', '' );
		if ( $page_id > 0 || '' !== $page_url ) {
			return;
		}
		$settings_url = admin_url( 'admin.php?page=mhbo-settings&tab=general' );
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Modern Hotel Booking:', 'modern-hotel-booking' ),
			esc_html__( 'No booking page is configured. Booking forms will not process correctly until you set one or Enable Inline Booking Modal.', 'modern-hotel-booking' ),
			esc_url( $settings_url ),
			esc_html__( 'Fix in Settings → General', 'modern-hotel-booking' )
		);
	}

	/* BUILD_PRO_START */
	/**
	 * Intercept invoice download early to prevent header conflicts.
	 *
	 * @since 2.3.1
	 */
	public function handle_pro_invoice_download(): void {
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ( isset( $_GET['amp;page'] ) ? sanitize_key( wp_unslash( $_GET['amp;page'] ) ) : '' );
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ( isset( $_GET['amp;action'] ) ? sanitize_key( wp_unslash( $_GET['amp;action'] ) ) : '' );

		if ( 'mhbo-bookings' !== $page || ! in_array( $action, array( 'download_invoice', 'invoice' ), true ) ) {
			return;
		}

		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : ( isset( $_GET['amp;id'] ) ? absint( wp_unslash( $_GET['amp;id'] ) ) : 0 );
		if ( 0 === $id ) {
			return;
		}

		if (
			! Capabilities::current_user_can( Capabilities::VIEW_BOOKINGS ) &&
			! Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) &&
			! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS )
		) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ( isset( $_GET['amp;_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['amp;_wpnonce'] ) ) : '' );
		if (
			! $nonce ||
			(
				! wp_verify_nonce( $nonce, 'mhbo_invoice_' . $id ) &&
				! wp_verify_nonce( $nonce, 'mhbo_invoice_nonce' ) &&
				! wp_verify_nonce( $nonce, 'mhbo_invoice' ) &&
				! wp_verify_nonce( $nonce, 'wp_rest' )
			)
		) {
			wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ), '', array( 'response' => 403 ) );
		}

		Invoice::download( $id );
		exit;
	}

	/**
	 * Handle printable manifest early on admin_init before headers/HTML are sent.
	 *
	 * @since 2.6.0
	 */
	public function handle_housekeeping_manifest_actions(): void {
		if ( ! isset( $_GET['page'] ) || ! in_array( $_GET['page'], array( 'mhbo-housekeeping', 'mhbo-bookings' ), true ) ) {
			return;
		}

		if ( ! isset( $_GET['action'] ) || 'print_housekeeping_manifest' !== $_GET['action'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) && ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_housekeeping_manifest' ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
		}

		$target_date = isset( $_GET['manifest_date'] ) ? sanitize_text_field( wp_unslash( $_GET['manifest_date'] ) ) : HotelTime::today();
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $target_date ) ) {
			$target_date = HotelTime::today();
		}

		if ( class_exists( \MHBO\Pro\HousekeepingManifest::class ) ) {
			\MHBO\Pro\HousekeepingManifest::render_printable_view( $target_date );
			exit;
		}
	}

	/**
	 * Handle early Data Transfer actions (Bookings Export, Bookings Import, Settings Backup) on admin_init.
	 *
	 * @since 2.6.0
	 */
	public function handle_pro_data_transfer_actions(): void {
		// 1. Export actions (GET or direct submission)
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( '' !== $action ) {
			if ( 'mhbo_export_bookings' === $action ) {
				$nonce = isset( $_REQUEST['mhbo_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mhbo_nonce'] ) ) : '';
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_export_bookings_action' ) ) {
					wp_die( esc_html( I18n::get_label( 'label_security_check_failed' ) ) );
				}
				if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
				}
				$format  = isset( $_REQUEST['format'] ) ? sanitize_key( wp_unslash( $_REQUEST['format'] ) ) : 'json';
				$filters = array(
					'status'    => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
					'date_from' => isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '',
					'date_to'   => isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '',
				);
				if ( class_exists( \MHBO\Pro\DataTransferService::class ) ) {
					\MHBO\Pro\DataTransferService::export_bookings( $format, $filters );
				}
				exit;
			}

			if ( 'mhbo_backup_settings' === $action ) {
				$nonce = isset( $_REQUEST['mhbo_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mhbo_nonce'] ) ) : '';
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_backup_settings_action' ) ) {
					wp_die( esc_html( I18n::get_label( 'label_security_check_failed' ) ) );
				}
				if ( ! current_user_can( 'manage_options' ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
				}
				$include_bookings  = ! empty( $_REQUEST['include_bookings'] );
				$include_secrets   = ! empty( $_REQUEST['include_secrets'] );
				$include_inventory = ! empty( $_REQUEST['include_inventory'] );
				if ( class_exists( \MHBO\Pro\DataTransferService::class ) ) {
					\MHBO\Pro\DataTransferService::export_settings_bundle( $include_bookings, $include_secrets, $include_inventory );
				}
				exit;
			}
		}

		// 2. POST Import actions (Bookings Import)
		if ( isset( $_POST['mhbo_action'] ) && 'mhbo_import_bookings' === $_POST['mhbo_action'] ) {
			$nonce = isset( $_POST['mhbo_import_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mhbo_import_nonce'] ) ) : '';
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_import_bookings_action' ) ) {
				wp_die( esc_html( I18n::get_label( 'label_security_check_failed' ) ) );
			}
			if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
			}

			if ( ! isset( $_FILES['import_file'] ) || ! is_array( $_FILES['import_file'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=mhbo-bookings&import_error=no_file' ) );
				exit;
			}

			$upload_err = isset( $_FILES['import_file']['error'] ) ? absint( $_FILES['import_file']['error'] ) : UPLOAD_ERR_NO_FILE;
			$tmp_name   = isset( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['import_file']['tmp_name'] ) ) : '';
			if ( UPLOAD_ERR_OK !== $upload_err || empty( $tmp_name ) || ! is_uploaded_file( $tmp_name ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=mhbo-bookings&import_error=no_file' ) );
				exit;
			}

			// Validate file extension
			$file_name = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['import_file']['name'] ) ) : '';
			$ext       = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'json', 'csv' ), true ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=mhbo-bookings&import_error=invalid_file_type' ) );
				exit;
			}

			// Validate file size (max 20MB)
			$file_size = isset( $_FILES['import_file']['size'] ) ? absint( $_FILES['import_file']['size'] ) : 0;
			if ( $file_size > 20 * 1024 * 1024 ) {
				wp_safe_redirect( admin_url( 'admin.php?page=mhbo-bookings&import_error=file_too_large' ) );
				exit;
			}

			$conflict_mode = isset( $_POST['conflict_mode'] ) ? sanitize_key( wp_unslash( $_POST['conflict_mode'] ) ) : 'create_new';
			$result        = array(
				'success'  => false,
				'inserted' => 0,
				'updated'  => 0,
				'skipped'  => 0,
			);

			if ( class_exists( \MHBO\Pro\DataTransferService::class ) ) {
				$result = \MHBO\Pro\DataTransferService::import_bookings( $tmp_name, $conflict_mode );
			}

			if ( $result['success'] ) {
				$redirect_url = add_query_arg(
					array(
						'page'          => 'mhbo-bookings',
						'import_status' => 'success',
						'inserted'      => $result['inserted'],
						'updated'       => $result['updated'],
						'skipped'       => $result['skipped'],
					),
					admin_url( 'admin.php' )
				);
			} else {
				$redirect_url = add_query_arg(
					array(
						'page'         => 'mhbo-bookings',
						'import_error' => 'failed',
					),
					admin_url( 'admin.php' )
				);
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}
	}
	/* BUILD_PRO_END */

	public function add_dashboard_widgets(): void {
		wp_add_dashboard_widget( 'mhbo_dashboard_overview', I18n::get_label( 'dash_title' ), array( $this, 'render_dashboard_widget' ) );
	}

	public function render_dashboard_widget(): void {
		// Explicit capability check for defense-in-depth
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		global $wpdb;
		$today_date = HotelTime::today();

		$cache_key = 'mhbo_widget_ops_v260_' . $today_date;
		$stats     = get_transient( $cache_key );

		if ( false === $stats || ! is_array( $stats ) ) {
			// Total physical rooms in inventory
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total_rooms = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_rooms" );

			// Occupied physical rooms today
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$occupied_rooms = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT room_id) FROM {$wpdb->prefix}mhbo_bookings
                 WHERE status NOT IN ('cancelled', 'refunded')
                   AND check_in <= DATE(%s) AND check_out > DATE(%s)",
					$today_date,
					$today_date
				)
			);

			// Today's Check-ins (Arrivals)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$arrivals = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings
                 WHERE status NOT IN ('cancelled', 'refunded') AND check_in = DATE(%s)",
					$today_date
				)
			);

			// Today's Check-outs (Departures)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$departures = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings
                 WHERE status NOT IN ('cancelled', 'refunded') AND check_out = DATE(%s)",
					$today_date
				)
			);

			// Currently Staying In-House
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$in_house = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings
                 WHERE status NOT IN ('cancelled', 'refunded')
                   AND check_in <= DATE(%s) AND check_out > DATE(%s)",
					$today_date,
					$today_date
				)
			);

			// Status counts (total and pending)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$counts = $wpdb->get_results(
				$wpdb->prepare( 'SELECT status, COUNT(*) as qty FROM %i GROUP BY status', $wpdb->prefix . 'mhbo_bookings' ),
				ARRAY_A
			);

			$total_bookings = 0;
			$pending_count  = 0;
			if ( is_array( $counts ) ) {
				foreach ( $counts as $row ) {
					$total_bookings += (int) $row['qty'];
					if ( 'pending' === $row['status'] ) {
						$pending_count = (int) $row['qty'];
					}
				}
			}

			// Guest CRM counts
			$guests_table_exists = Cache::table_exists( $wpdb->prefix . 'mhbo_guests' );
			$total_guests        = 0;
			$vip_guests          = 0;
			if ( $guests_table_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$crm_stats = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) as total, COALESCE(SUM(CASE WHEN vip_status = 'vip' THEN 1 ELSE 0 END), 0) as vips FROM %i WHERE status != 'anonymized'",
						$wpdb->prefix . 'mhbo_guests'
					)
				);
				if ( $crm_stats ) {
					$total_guests = (int) $crm_stats->total;
					$vip_guests   = (int) $crm_stats->vips;
				}
			}

			// Housekeeping stats
			$turnovers_count = 0;
			$vacant_count    = max( 0, $total_rooms - $occupied_rooms );
			/* BUILD_PRO_START */
			if ( class_exists( \MHBO\Pro\HousekeepingManifest::class ) ) {
				$manifest_summary = \MHBO\Pro\HousekeepingManifest::get_manifest_data( $today_date );
				if ( isset( $manifest_summary['summary'] ) ) {
					$turnovers_count = (int) ( $manifest_summary['summary']['turnovers'] ?? 0 );
					$vacant_count    = (int) ( $manifest_summary['summary']['vacant'] ?? $vacant_count );
				}
			}
			/* BUILD_PRO_END */

			$stats = array(
				'total_rooms'     => $total_rooms,
				'occupied_rooms'  => $occupied_rooms,
				'available_rooms' => max( 0, $total_rooms - $occupied_rooms ),
				'arrivals'        => $arrivals,
				'departures'      => $departures,
				'in_house'        => $in_house,
				'pending'         => $pending_count,
				'total_bookings'  => $total_bookings,
				'total_guests'    => $total_guests,
				'vip_guests'      => $vip_guests,
				'turnovers'       => $turnovers_count,
				'vacant'          => $vacant_count,
			);

			set_transient( $cache_key, $stats, 10 * MINUTE_IN_SECONDS );
		}

		$total_rooms     = (int) ( $stats['total_rooms'] ?? 0 );
		$occupied_rooms  = (int) ( $stats['occupied_rooms'] ?? 0 );
		$available_rooms = (int) ( $stats['available_rooms'] ?? 0 );
		$arrivals        = (int) ( $stats['arrivals'] ?? 0 );
		$departures      = (int) ( $stats['departures'] ?? 0 );
		$in_house        = (int) ( $stats['in_house'] ?? 0 );
		$pending         = (int) ( $stats['pending'] ?? 0 );
		$total_bookings  = (int) ( $stats['total_bookings'] ?? 0 );
		$total_guests    = (int) ( $stats['total_guests'] ?? 0 );
		$vip_guests      = (int) ( $stats['vip_guests'] ?? 0 );
		$turnovers       = (int) ( $stats['turnovers'] ?? 0 );

		$occupancy_pct = $total_rooms > 0 ? (int) round( ( $occupied_rooms / $total_rooms ) * 100 ) : 0;
		$pulse_class   = $occupancy_pct >= 80 ? 'mhbo-pulse-red' : ( $occupancy_pct >= 40 ? 'mhbo-pulse-amber' : 'mhbo-pulse-indicator' );
		?>
		<div class="mhbo-dash-widget">
			<!-- Header Occupancy Status Badge -->
			<div class="mhbo-widget-header-badge">
				<span style="font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
					<span class="mhbo-pulse-indicator <?php echo esc_attr( $pulse_class ); ?>"></span>
					<?php echo esc_html( sprintf( I18n::get_label( 'dash_rooms_occupied' ), $occupied_rooms, $total_rooms ) ); ?>
					<span class="mhbo-occupancy-pill">(<?php echo esc_html( (string) $occupancy_pct ); ?>%)</span>
				</span>
				<span style="font-size: 11px; color: #64748b; font-weight: 600;">
					<?php echo esc_html( HotelTime::format_date( $today_date ) ); ?>
				</span>
			</div>

			<!-- Operational 6-KPI Micro Grid -->
			<div class="mhbo-widget-kpi-grid">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-hotel-booking' ) ); ?>" class="mhbo-widget-kpi-box" title="<?php echo esc_attr( I18n::get_label( 'dash_arrivals_today' ) ); ?>">
					<span class="mhbo-widget-kpi-val" style="color: #2563eb;"><?php echo esc_html( (string) $arrivals ); ?></span>
					<span class="mhbo-widget-kpi-lbl"><?php echo esc_html( I18n::get_label( 'dash_arrivals_today' ) ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-hotel-booking' ) ); ?>" class="mhbo-widget-kpi-box" title="<?php echo esc_attr( I18n::get_label( 'dash_departures_today' ) ); ?>">
					<span class="mhbo-widget-kpi-val" style="color: #059669;"><?php echo esc_html( (string) $departures ); ?></span>
					<span class="mhbo-widget-kpi-lbl"><?php echo esc_html( I18n::get_label( 'dash_departures_today' ) ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-hotel-booking' ) ); ?>" class="mhbo-widget-kpi-box" title="<?php echo esc_attr( I18n::get_label( 'dash_in_house' ) ); ?>">
					<span class="mhbo-widget-kpi-val" style="color: #0284c7;"><?php echo esc_html( (string) $in_house ); ?></span>
					<span class="mhbo-widget-kpi-lbl"><?php echo esc_html( I18n::get_label( 'dash_in_house' ) ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings&status=pending' ) ); ?>" class="mhbo-widget-kpi-box<?php echo $pending > 0 ? ' mhbo-kpi-box-alert' : ''; ?>" title="<?php echo esc_attr( I18n::get_label( 'dash_pending' ) ); ?>">
					<span class="mhbo-widget-kpi-val" style="color: <?php echo $pending > 0 ? '#dc2626' : '#64748b'; ?>;"><?php echo esc_html( (string) $pending ); ?></span>
					<span class="mhbo-widget-kpi-lbl"><?php echo esc_html( I18n::get_label( 'dash_attention' ) ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings#mhbo-calendar' ) ); ?>" class="mhbo-widget-kpi-box" title="<?php echo esc_attr( I18n::get_label( 'dash_rooms_available' ) ); ?>">
					<span class="mhbo-widget-kpi-val" style="color: #475569;"><?php echo esc_html( (string) $available_rooms ); ?></span>
					<span class="mhbo-widget-kpi-lbl"><?php echo esc_html( I18n::get_label( 'label_legend_available' ) ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ); ?>" class="mhbo-widget-kpi-box" title="<?php echo esc_attr( I18n::get_label( 'dash_total' ) ); ?>">
					<span class="mhbo-widget-kpi-val" style="color: #1e293b;"><?php echo esc_html( (string) $total_bookings ); ?></span>
					<span class="mhbo-widget-kpi-lbl"><?php echo esc_html( I18n::get_label( 'dash_total' ) ); ?></span>
				</a>
			</div>

			<!-- Operational Meta Rows -->
			<div class="mhbo-widget-meta-list">
				<?php
				$is_pro_active = false;
				/* BUILD_PRO_START */
				$is_pro_active = License::is_active();
				if ( $is_pro_active && class_exists( \MHBO\Pro\ICalManager::class ) ) :
					$failing_icals = \MHBO\Pro\ICalManager::get_failing_connections( 3 );
					if ( ! empty( $failing_icals ) ) :
						?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-pro-ical' ) ); ?>" class="mhbo-widget-meta-item" style="background: #fff1f2; border-color: #fecdd3; color: #9f1239;">
					<span>⚠️ <strong><?php esc_html_e( 'OTA Sync Alert:', 'modern-hotel-booking' ); ?></strong></span>
					<span style="font-weight: 700;">
						<?php 
						/* translators: %d: number of failing feeds */
						echo esc_html( sprintf( _n( '%d Feed Failing', '%d Feeds Failing', count( $failing_icals ), 'modern-hotel-booking' ), count( $failing_icals ) ) ); 
						?>
					&rarr;</span>
				</a>
						<?php
					endif;
				endif;
				if ( $is_pro_active && class_exists( \MHBO\Pro\HousekeepingManifest::class ) ) :
					?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-housekeeping' ) ); ?>" class="mhbo-widget-meta-item">
					<span>🧹 <strong><?php esc_html_e( 'Housekeeping:', 'modern-hotel-booking' ); ?></strong></span>
					<span>
					<?php
					/* translators: 1: number of turnovers, 2: number of vacant rooms */
					echo esc_html( sprintf( __( '%1$d Turnovers / %2$d Vacant', 'modern-hotel-booking' ), $turnovers, $available_rooms ) );
					?>
					&rarr;</span>
				</a>
					<?php
				endif;
				/* BUILD_PRO_END */
				?>

				<?php if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-guests' ) ); ?>" class="mhbo-widget-meta-item">
					<span>👥 <strong><?php esc_html_e( 'Guest CRM:', 'modern-hotel-booking' ); ?></strong></span>
					<span>
					<?php
					/* translators: 1: total guest count, 2: VIP guest count */
					echo esc_html( sprintf( __( '%1$d Profiles (%2$d VIP)', 'modern-hotel-booking' ), $total_guests, $vip_guests ) );
					?>
					&rarr;</span>
				</a>
				<?php endif; ?>
			</div>

			<!-- Action Shortcuts Grid -->
			<div class="mhbo-widget-actions-grid">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings&action=add' ) ); ?>" class="mhbo-widget-btn mhbo-widget-btn-primary">
					<span class="dashicons dashicons-plus-alt2" style="font-size: 14px; width: 14px; height: 14px;"></span>
					<?php echo esc_html( I18n::get_label( 'label_add_booking' ) ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ); ?>" class="mhbo-widget-btn">
					<span class="dashicons dashicons-calendar-alt" style="font-size: 14px; width: 14px; height: 14px;"></span>
					<?php echo esc_html( I18n::get_label( 'menu_bookings' ) ); ?>
				</a>
				<?php
				/* BUILD_PRO_START */
				if ( $is_pro_active ) :
					?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-housekeeping' ) ); ?>" class="mhbo-widget-btn">
					<span class="dashicons dashicons-clipboard" style="font-size: 14px; width: 14px; height: 14px;"></span>
					<?php esc_html_e( 'Housekeeping', 'modern-hotel-booking' ); ?>
				</a>
					<?php
				endif;
				/* BUILD_PRO_END */
				?>
				<?php if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-guests' ) ); ?>" class="mhbo-widget-btn">
					<span class="dashicons dashicons-groups" style="font-size: 14px; width: 14px; height: 14px;"></span>
					<?php esc_html_e( 'Guests', 'modern-hotel-booking' ); ?>
				</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-rooms' ) ); ?>" class="mhbo-widget-btn">
					<span class="dashicons dashicons-admin-multisite" style="font-size: 14px; width: 14px; height: 14px;"></span>
					<?php echo esc_html( I18n::get_label( 'menu_rooms' ) ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-settings' ) ); ?>" class="mhbo-widget-btn">
					<span class="dashicons dashicons-admin-generic" style="font-size: 14px; width: 14px; height: 14px;"></span>
					<?php echo esc_html( I18n::get_label( 'menu_settings' ) ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( 'index.php' === $hook || 'dashboard' === $hook ) {
			// Dedicated lightweight dashboard widgets stylesheet (4 KB); avoids loading 229 KB admin CSS suite on index.php.
			$dash_css_ver = (string) ( file_exists( MHBO_PLUGIN_DIR . 'assets/css/mhbo-dashboard.css' ) ? filemtime( MHBO_PLUGIN_DIR . 'assets/css/mhbo-dashboard.css' ) : MHBO_VERSION );
			wp_enqueue_style( 'mhbo-dashboard-css', MHBO_PLUGIN_URL . 'assets/css/mhbo-dashboard.css', array( 'dashicons' ), $dash_css_ver );
			return;
		}

		if ( false === strpos( $hook, 'mhbo-hotel-booking' ) && false === strpos( $hook, 'mhbo-' ) ) {
			return;
		}
		$admin_css_ver = (string) ( file_exists( MHBO_PLUGIN_DIR . 'assets/css/mhbo-admin.css' ) ? filemtime( MHBO_PLUGIN_DIR . 'assets/css/mhbo-admin.css' ) : MHBO_VERSION );
		wp_enqueue_style( 'mhbo-admin-css', MHBO_PLUGIN_URL . 'assets/css/mhbo-admin.css', array(), $admin_css_ver );
		wp_enqueue_script( 'mhbo-admin-js', MHBO_PLUGIN_URL . 'assets/js/mhbo-admin.js', array( 'wp-api-fetch' ), MHBO_VERSION, true );

		/* BUILD_PRO_START */
		// Hide Free Edition elements securely in the Pro version
		wp_add_inline_style( 'mhbo-admin-css', '.mhbo-free-edition-row { display: none !important; }' );
		/* BUILD_PRO_END */

		/* BUILD_PRO_START */
		// Inline script for banner dismiss functionality using REST API (Pure Vanilla JS)
		$banner_dismiss_js = "
            function mhboDismissBanner() {
                var banner = document.querySelector('.mhbo-pro-banner');
                var hideBanner = function() {
                    if (banner) {
                        banner.style.transition = 'opacity 0.3s ease, max-height 0.3s ease, margin 0.3s ease, padding 0.3s ease';
                        banner.style.opacity = '0';
                        banner.style.maxHeight = '0';
                        banner.style.margin = '0';
                        banner.style.padding = '0';
                        banner.style.overflow = 'hidden';
                        setTimeout(function() { banner.style.display = 'none'; }, 300);
                    }
                };
                if (window.wp && window.wp.apiFetch) {
                    window.wp.apiFetch({
                        path: '/mhbo/v1/admin/system/banner/dismiss',
                        method: 'POST'
                    }).then(hideBanner).catch(hideBanner);
                } else if (typeof fetch === 'function') {
                    fetch('/wp-json/mhbo/v1/admin/system/banner/dismiss', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': " . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . '
                        }
                    }).then(hideBanner).catch(hideBanner);
                } else {
                    hideBanner();
                }
            }
        ';
		wp_add_inline_script( 'mhbo-admin-js', $banner_dismiss_js );
		/* BUILD_PRO_END */

		/* BUILD_PRO_START */
		// Enqueue Live Tablet Reservation Mode assets
		if ( false !== strpos( $hook, 'tablet-mode' ) ) {
			$tablet_css_ver = (string) ( file_exists( MHBO_PLUGIN_DIR . 'assets/css/pro/mhbo-tablet-mode.css' ) ? filemtime( MHBO_PLUGIN_DIR . 'assets/css/pro/mhbo-tablet-mode.css' ) : MHBO_VERSION );
			wp_enqueue_style( 'mhbo-tablet-mode', MHBO_PLUGIN_URL . 'assets/css/pro/mhbo-tablet-mode.css', array( 'dashicons' ), $tablet_css_ver );

			$tablet_js_ver  = (string) ( file_exists( MHBO_PLUGIN_DIR . 'assets/js/pro/tablet/tablet-drawer.js' ) ? filemtime( MHBO_PLUGIN_DIR . 'assets/js/pro/tablet/tablet-drawer.js' ) : MHBO_VERSION );
			wp_enqueue_script( 'mhbo-tablet-audio', MHBO_PLUGIN_URL . 'assets/js/pro/tablet/tablet-audio.js', array(), $tablet_js_ver, true );
			wp_enqueue_script( 'mhbo-tablet-api', MHBO_PLUGIN_URL . 'assets/js/pro/tablet/tablet-api.js', array( 'mhbo-tablet-audio' ), $tablet_js_ver, true );
			wp_enqueue_script( 'mhbo-tablet-kiosk', MHBO_PLUGIN_URL . 'assets/js/pro/tablet/tablet-kiosk.js', array( 'mhbo-tablet-api' ), $tablet_js_ver, true );
			wp_enqueue_script( 'mhbo-tablet-stay-modal', MHBO_PLUGIN_URL . 'assets/js/pro/tablet/tablet-stay-modal.js', array( 'mhbo-tablet-api' ), $tablet_js_ver, true );
			wp_enqueue_script( 'mhbo-tablet-drawer', MHBO_PLUGIN_URL . 'assets/js/pro/tablet/tablet-drawer.js', array( 'mhbo-tablet-api' ), $tablet_js_ver, true );
			wp_enqueue_script( 'mhbo-tablet-tape-chart', MHBO_PLUGIN_URL . 'assets/js/pro/tablet/tablet-tape-chart.js', array( 'mhbo-tablet-api' ), $tablet_js_ver, true );
			wp_enqueue_script( 'mhbo-tablet-card-grid', MHBO_PLUGIN_URL . 'assets/js/pro/tablet/tablet-card-grid.js', array( 'mhbo-tablet-api' ), $tablet_js_ver, true );
			wp_enqueue_script( 'mhbo-tablet-main', MHBO_PLUGIN_URL . 'assets/js/pro/tablet/tablet-main.js', array( 'mhbo-tablet-card-grid', 'mhbo-tablet-tape-chart', 'mhbo-tablet-drawer', 'mhbo-tablet-stay-modal', 'mhbo-tablet-kiosk' ), $tablet_js_ver, true );

			wp_localize_script(
				'mhbo-tablet-api',
				'mhboTabletConfig',
				array(
					'restBase'        => rest_url( 'mhbo/v1/admin/tablet' ),
					'restGuests'      => rest_url( 'mhbo/v1/admin/guests' ),
					'nonce'           => wp_create_nonce( 'wp_rest' ),
					'currency'        => strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) ),
					'currencySymbol'  => (string) get_option( 'mhbo_currency_symbol', '$' ),
					'childrenEnabled' => (int) get_option( 'mhbo_children_enabled', 0 ),
					'depositsEnabled' => (int) get_option( 'mhbo_deposits_enabled', 0 ),
					'bookingsUrl'     => admin_url( 'admin.php?page=mhbo-bookings' ),
					'invoiceUrl'      => admin_url( 'admin.php?page=mhbo-bookings&action=download_invoice&id=' ),
					'invoiceNonce'    => wp_create_nonce( 'mhbo_invoice_nonce' ),
				)
			);
		}
		/* BUILD_PRO_END */

		/* BUILD_PRO_START */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI routing parameter for script enqueuing; no state modification.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : '';

		// Enqueue iCal admin script on iCal pages (both dedicated slug and Pro hub tab)
		if ( false !== strpos( $hook, 'mhbo-pro-ical' ) || ( false !== strpos( $hook, 'mhbo-pro' ) && 'ical' === $current_tab ) ) {
			wp_enqueue_script( 'mhbo-ical-admin-js', MHBO_PLUGIN_URL . 'assets/js/mhbo-ical-admin.js', array( 'wp-api-fetch' ), MHBO_VERSION, true );
			wp_add_inline_script( 'mhbo-ical-admin-js', 'window.mhboIcalNonce = ' . wp_json_encode( wp_create_nonce( 'mhbo_ical_nonce' ) ) . ';', 'before' );
		}

		// Enqueue admin calendar assets on room-types and rooms pages (Pro).
		if ( false !== strpos( $hook, 'mhbo-room-types' ) || false !== strpos( $hook, 'mhbo-rooms' ) ) {
			wp_enqueue_style( 'mhbo-google-fonts', 'https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap', array(), MHBO_VERSION );

			// FullCalendar V7: CSS must be loaded separately (skeleton + classic theme + palette).
			wp_enqueue_style( 'fullcalendar-skeleton', MHBO_PLUGIN_URL . 'assets/css/vendor/fullcalendar-skeleton.min.css', array(), self::FULLCALENDAR_VERSION );
			wp_enqueue_style( 'fullcalendar-classic-theme-css', MHBO_PLUGIN_URL . 'assets/css/vendor/fullcalendar-classic-theme.min.css', array( 'fullcalendar-skeleton' ), self::FULLCALENDAR_VERSION );
			wp_enqueue_style( 'fullcalendar-classic-palette', MHBO_PLUGIN_URL . 'assets/css/vendor/fullcalendar-classic-palette.min.css', array( 'fullcalendar-classic-theme-css' ), self::FULLCALENDAR_VERSION );

			// FullCalendar V7: main bundle + classic theme plugin.
			wp_enqueue_script( 'fullcalendar', MHBO_PLUGIN_URL . 'assets/js/vendor/fullcalendar.global.min.js', array(), self::FULLCALENDAR_VERSION, true );
			wp_enqueue_script( 'fullcalendar-classic-theme', MHBO_PLUGIN_URL . 'assets/js/vendor/fullcalendar-classic-theme.global.min.js', array( 'fullcalendar' ), self::FULLCALENDAR_VERSION, true );

			wp_enqueue_script( 'mhbo-admin-calendar', MHBO_PLUGIN_URL . 'assets/js/pro/mhbo-admin-calendar.js', array( 'fullcalendar', 'fullcalendar-classic-theme', 'wp-api-fetch' ), MHBO_VERSION, true );
			wp_enqueue_style( 'mhbo-admin-calendar', MHBO_PLUGIN_URL . 'assets/css/pro/mhbo-admin-calendar.css', array( 'fullcalendar-classic-palette' ), MHBO_VERSION );
			wp_enqueue_style( 'mhbo-admin-css', MHBO_PLUGIN_URL . 'assets/css/mhbo-admin.css', array( 'mhbo-admin-calendar' ), MHBO_VERSION );

			$tz       = HotelTime::timezone()->getName();
			$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
			$symbol   = (string) get_option( 'mhbo_currency_symbol', '$' );
			wp_localize_script(
				'mhbo-admin-calendar',
				'mhboAdminCalendar',
				array(
					'restUrl'        => rest_url( 'mhbo/v1/admin/calendar' ),
					'restNonce'      => wp_create_nonce( 'wp_rest' ),
					'hotelTimezone'  => $tz,
					'currency'       => $currency,
					'currencySymbol' => $symbol,
					'firstDay'       => (int) get_option( 'start_of_week', 1 ),
				)
			);
			wp_localize_script(
				'mhbo-admin-calendar',
				'mhboAcL10n',
				array(
					'panel_day_title'         => __( 'Edit Day', 'modern-hotel-booking' ),
					'panel_bulk_title'        => __( 'Bulk Edit', 'modern-hotel-booking' ),
					'label_price'             => _x( 'Price', 'noun', 'modern-hotel-booking' ),
					'label_availability'      => __( 'Availability', 'modern-hotel-booking' ),
					'label_min_stay'          => __( 'Min nights', 'modern-hotel-booking' ),
					'label_max_stay'          => __( 'Max nights', 'modern-hotel-booking' ),
					'label_cta'               => __( 'CTA (closed to arrival)', 'modern-hotel-booking' ),
					'label_ctd'               => __( 'CTD (closed to departure)', 'modern-hotel-booking' ),
					'label_early_checkin_fee' => __( 'Early Check-in Fee', 'modern-hotel-booking' ),
					'label_late_checkout_fee' => __( 'Late Check-out Fee', 'modern-hotel-booking' ),
					'label_note'              => __( 'Admin note', 'modern-hotel-booking' ),
					'label_blocked'           => __( 'Blocked', 'modern-hotel-booking' ),
					'label_dow_filter'        => __( 'Apply to days:', 'modern-hotel-booking' ),
					'opt_inherit'             => __( '— inherit —', 'modern-hotel-booking' ),
					'opt_no_change'           => __( '— no change —', 'modern-hotel-booking' ),
					'opt_open'                => _x( 'Open', 'availability status', 'modern-hotel-booking' ),
					'opt_blocked'             => __( 'Blocked', 'modern-hotel-booking' ),
					'opt_yes'                 => _x( 'Yes', 'confirmation', 'modern-hotel-booking' ),
					'opt_no'                  => _x( 'No', 'confirmation', 'modern-hotel-booking' ),
					'placeholder_inherit'     => __( 'inherit', 'modern-hotel-booking' ),
					'placeholder_no_change'   => __( 'no change', 'modern-hotel-booking' ),
					'btn_save'                => _x( 'Save', 'action', 'modern-hotel-booking' ),
					'btn_apply'               => __( 'Apply to Range', 'modern-hotel-booking' ),
					'btn_clear'               => __( 'Clear override', 'modern-hotel-booking' ),
					'btn_cancel'              => __( 'Cancel', 'modern-hotel-booking' ),
					'btn_import_confirm'      => __( 'Confirm Import', 'modern-hotel-booking' ),
					'confirm_clear'           => __( 'Remove override for this day?', 'modern-hotel-booking' ),
					'import_title'            => __( 'Import Preview', 'modern-hotel-booking' ),
					'import_new'              => __( 'New records', 'modern-hotel-booking' ),
					'import_conflicts'        => __( 'Conflicts', 'modern-hotel-booking' ),
					'import_errors'           => __( 'Errors', 'modern-hotel-booking' ),
					'import_errors_title'     => __( 'Skipped records:', 'modern-hotel-booking' ),
					'import_conflict_hint'    => __( 'For each conflict, choose which version to keep:', 'modern-hotel-booking' ),
					'import_no_conflicts'     => __( 'No conflicts. All records will be imported.', 'modern-hotel-booking' ),
					'import_done'             => __( 'Import complete', 'modern-hotel-booking' ),
					'keep_existing'           => __( 'Keep existing', 'modern-hotel-booking' ),
					'use_incoming'            => __( 'Use incoming', 'modern-hotel-booking' ),
					'col_date'                => _x( 'Date', 'table column', 'modern-hotel-booking' ),
					'col_existing'            => __( 'Existing', 'modern-hotel-booking' ),
					'col_incoming'            => __( 'Incoming', 'modern-hotel-booking' ),
					'col_changed'             => __( 'Changed fields', 'modern-hotel-booking' ),
					'col_keep'                => _x( 'Keep', 'action', 'modern-hotel-booking' ),
					'notice_saved'            => _x( 'Saved', 'status', 'modern-hotel-booking' ),
					'notice_days'             => __( 'day(s)', 'modern-hotel-booking' ),
					'notice_records'          => __( 'record(s) written.', 'modern-hotel-booking' ),
					'error_generic'           => __( 'An error occurred.', 'modern-hotel-booking' ),
					'error_network'           => __( 'Network error. Please try again.', 'modern-hotel-booking' ),
					'error_invalid_json'      => __( 'Invalid JSON file.', 'modern-hotel-booking' ),
					'dow_hint'                => __( 'Leave all unchecked to apply to every day in range.', 'modern-hotel-booking' ),
					'night_abbr'              => _x( 'n', 'abbreviation for night', 'modern-hotel-booking' ),
					'label_effective_price'   => __( 'Current effective price:', 'modern-hotel-booking' ),
					'day_mon'                 => I18n::get_label( 'label_day_mon' ),
					'day_tue'                 => I18n::get_label( 'label_day_tue' ),
					'day_wed'                 => I18n::get_label( 'label_day_wed' ),
					'day_thu'                 => I18n::get_label( 'label_day_thu' ),
					'day_fri'                 => I18n::get_label( 'label_day_fri' ),
					'day_sat'                 => I18n::get_label( 'label_day_sat' ),
					'day_sun'                 => I18n::get_label( 'label_day_sun' ),
				)
			);
		}
		/* BUILD_PRO_END */

		$dataview_pages   = array( 'mhbo-bookings', 'mhbo-calendar', 'mhbo-room-types', 'mhbo-rooms', 'mhbo-guests' );
		$is_dataview_page = false;
		foreach ( $dataview_pages as $page ) {
			if ( false !== strpos( $hook, $page ) ) {
				$is_dataview_page = true;
				break;
			}
		}
		
		if ( $is_dataview_page ) {
			$asset_file_path = MHBO_PLUGIN_DIR . 'build/admin/dataviews.asset.php';
			if ( file_exists( $asset_file_path ) ) {
				// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
				/** @var array{dependencies: list<string>, version: string} $asset_file */
				$asset_file = (array) include $asset_file_path;
				wp_enqueue_script(
					'mhbo-dataviews',
					MHBO_PLUGIN_URL . 'build/admin/dataviews.js',
					$asset_file['dependencies'],
					$asset_file['version'],
					true
				);
				$is_pro_active = class_exists( License::class ) ? License::is_pro_active() : ( defined( 'MHBO_IS_PRO' ) && MHBO_IS_PRO );
				wp_localize_script(
					'mhbo-dataviews',
					'mhboDataViewsData',
					array(
						'isPro'              => $is_pro_active,
						'exportUrl'          => esc_url_raw( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'mhbo/v1/admin/guests/export' ) ) ),
						'restNonce'          => wp_create_nonce( 'wp_rest' ),
						'currency'           => (string) get_option( 'mhbo_currency_symbol', '$' ),
						'upgradeUrl'         => admin_url( 'admin.php?page=mhbo-pro' ),
						'adminUrl'           => admin_url( 'admin.php' ),
						'bookingActionNonce' => wp_create_nonce( 'mhbo_booking_action' ),
					)
				);
				wp_enqueue_style( 'wp-components' );
				if ( file_exists( MHBO_PLUGIN_DIR . 'build/admin/style-dataviews.css' ) ) {
					wp_enqueue_style(
						'mhbo-dataviews-css',
						MHBO_PLUGIN_URL . 'build/admin/style-dataviews.css',
						array( 'wp-components' ),
						$asset_file['version']
					);
				}
				
				// True zero-latency preloading
				// Step 1: Preload actual data
				$preload_paths = array( '/mhbo/v1/bookings?per_page=20' );
				if ( function_exists( 'rest_preload_api_request' ) ) {
					$preload_data = array_reduce( $preload_paths, 'rest_preload_api_request', array() );
					wp_add_inline_script( 'wp-api-fetch', sprintf( 'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );', wp_json_encode( $preload_data ) ), 'after' );
				}

				// Step 2: Nonce middleware
				wp_add_inline_script( 'wp-api-fetch', sprintf( 'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( "%s" ) );', wp_create_nonce( 'wp_rest' ) ), 'after' );
			}
		}

		if ( false !== strpos( $hook, 'mhbo-bookings' ) || false !== strpos( $hook, 'mhbo-calendar' ) ) {
			// Flatpickr date range picker assets for add/edit workstations
			wp_enqueue_style( 'mhbo-flatpickr-css', MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css', array(), '4.6.13' );
			wp_enqueue_script( 'mhbo-flatpickr-js', MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js', array(), '4.6.13', true );
			wp_enqueue_style( 'mhbo-calendar-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-calendar.css', array( 'mhbo-flatpickr-css' ), MHBO_VERSION );

			// FullCalendar V7: CSS must be loaded separately (skeleton + classic theme + palette).
			wp_enqueue_style( 'fullcalendar-skeleton', MHBO_PLUGIN_URL . 'assets/css/vendor/fullcalendar-skeleton.min.css', array(), self::FULLCALENDAR_VERSION );
			wp_enqueue_style( 'fullcalendar-classic-theme-css', MHBO_PLUGIN_URL . 'assets/css/vendor/fullcalendar-classic-theme.min.css', array( 'fullcalendar-skeleton' ), self::FULLCALENDAR_VERSION );
			wp_enqueue_style( 'fullcalendar-classic-palette', MHBO_PLUGIN_URL . 'assets/css/vendor/fullcalendar-classic-palette.min.css', array( 'fullcalendar-classic-theme-css' ), self::FULLCALENDAR_VERSION );
			
			// Deregister mhbo-admin-css and re-enqueue AFTER vendor styles to ensure proper override
			$bookings_css_ver = (string) ( file_exists( MHBO_PLUGIN_DIR . 'assets/css/mhbo-admin.css' ) ? filemtime( MHBO_PLUGIN_DIR . 'assets/css/mhbo-admin.css' ) : MHBO_VERSION );
			$bookings_js_ver  = (string) ( file_exists( MHBO_PLUGIN_DIR . 'assets/js/mhbo-admin-bookings.js' ) ? filemtime( MHBO_PLUGIN_DIR . 'assets/js/mhbo-admin-bookings.js' ) : MHBO_VERSION );
			wp_deregister_style( 'mhbo-admin-css' );
			wp_enqueue_style( 'mhbo-admin-css', MHBO_PLUGIN_URL . 'assets/css/mhbo-admin.css', array( 'fullcalendar-classic-palette', 'mhbo-flatpickr-css' ), $bookings_css_ver );

			// FullCalendar V7: main bundle + classic theme plugin.
			wp_enqueue_script( 'fullcalendar', MHBO_PLUGIN_URL . 'assets/js/vendor/fullcalendar.global.min.js', array(), self::FULLCALENDAR_VERSION, true );
			wp_enqueue_script( 'fullcalendar-classic-theme', MHBO_PLUGIN_URL . 'assets/js/vendor/fullcalendar-classic-theme.global.min.js', array( 'fullcalendar' ), self::FULLCALENDAR_VERSION, true );

			// Enqueue admin bookings script (Pure Vanilla JS)
			wp_enqueue_script(
				'mhbo-admin-bookings',
				MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-bookings.js',
				array( 'mhbo-admin-js', 'wp-api-fetch', 'mhbo-flatpickr-js', 'fullcalendar', 'fullcalendar-classic-theme' ),
				$bookings_js_ver,
				true
			);

			// Inject configuration
			$config = array(
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'currencySymbol'  => (string) get_option( 'mhbo_currency_symbol', '$' ),
				'currencyCode'    => strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) ),
				'extrasCount'     => 0,
				'preventTurnover' => (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1,
				'minNights'       => (int) get_option( 'mhbo_global_min_stay', 0 ),
				'maxNights'       => (int) get_option( 'mhbo_global_max_stay', 0 ),
				'gapAutocorrect'  => (int) get_option( 'mhbo_orphan_gap_autocorrection', 0 ) === 1,
				'gapMinNights'    => (int) get_option( 'mhbo_orphan_gap_min_stay', 1 ),
				'dateFormat'      => (string) get_option( 'date_format', 'Y-m-d' ),
				'depositsEnabled' => (int) get_option( 'mhbo_deposits_enabled', 0 ) === 1,
				'depositType'     => (string) get_option( 'mhbo_deposit_type', 'percentage' ),
				'depositValue'    => (float) get_option( 'mhbo_deposit_value', 20 ),
				'i18n'            => array(
					'confirmBulkDelete'  => I18n::get_label( 'msg_confirm_bulk_delete' ),
					'noBookingsSelected' => I18n::get_label( 'msg_no_bookings_selected' ),
					'selectedCount'      => __( 'selected', 'modern-hotel-booking' ),
					'creditBalance'      => __( 'Credit Balance', 'modern-hotel-booking' ),
					'paidInFull'         => __( 'Paid in Full', 'modern-hotel-booking' ),
					'balanceDue'         => __( 'Balance Due', 'modern-hotel-booking' ),
					'nights'             => __( 'nights', 'modern-hotel-booking' ),
					'night'              => _x( 'night', 'singular stay duration', 'modern-hotel-booking' ),
					'selectDates'        => __( 'Choose Dates', 'modern-hotel-booking' ),
					'available'          => __( '✓ Room is available for selected stay', 'modern-hotel-booking' ),
					'unavailable'        => __( '⚠️ Room is already reserved for selected dates', 'modern-hotel-booking' ),
					'accommodation'      => __( 'Accommodation', 'modern-hotel-booking' ),
					'couponInvalid'      => __( 'Invalid or expired promo code', 'modern-hotel-booking' ),
				),
			);
			wp_add_inline_script( 'mhbo-admin-bookings', 'window.mhboAdminBookingsConfig = ' . wp_json_encode( $config ) . ';', 'before' );
		}

		if ( false !== strpos( $hook, 'mhbo-room-types' ) || false !== strpos( $hook, 'mhbo-rooms' ) ) {
			wp_enqueue_media();
			wp_enqueue_script(
				'mhbo-admin-media-upload',
				MHBO_PLUGIN_URL . 'assets/js/mhbo-admin-media-upload.js',
				array(),
				MHBO_VERSION,
				true
			);
		}
		/* BUILD_PRO_START */
		if ( false !== strpos( $hook, 'mhbo-pro-analytics' ) ) {
			wp_enqueue_script( 'chartjs', MHBO_PLUGIN_URL . 'assets/js/vendor/chart.min.js', array(), '4.5.1', true );
			wp_enqueue_style( 'mhbo-analytics', MHBO_PLUGIN_URL . 'assets/css/mhbo-analytics.css', array(), MHBO_VERSION );
			wp_enqueue_script( 'mhbo-analytics', MHBO_PLUGIN_URL . 'assets/js/mhbo-analytics.js', array( 'chartjs' ), MHBO_VERSION, true );
		}
		/* BUILD_PRO_END */

		/* BUILD_PRO_START */
		// Enqueue admin bookings script and extras logic for extras page (both dedicated slug and Pro hub tab)
		if ( false !== strpos( $hook, 'mhbo-pro-extras' ) || ( false !== strpos( $hook, 'mhbo-pro' ) && 'extras' === $current_tab ) ) {
			wp_enqueue_script(
				'mhbo-extras-js',
				MHBO_PLUGIN_URL . 'assets/js/mhbo-extras.js',
				array( 'wp-api-fetch', 'wp-util', 'wp-i18n' ),
				MHBO_VERSION . '.4',
				true
			);
			wp_localize_script(
				'mhbo-extras-js',
				'mhbo_extras_params',
				array(
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'rest_url'       => esc_url_raw( rest_url( 'mhbo/v1/admin/extras' ) ),
					'confirm_remove' => I18n::get_label( 'msg_confirm_remove_extra' ) ?: __( 'Are you sure you want to remove this extra service?', 'modern-hotel-booking' ),
					'label_save'     => _x( 'Save', 'action', 'modern-hotel-booking' ),
					'label_saving'   => esc_html__( 'Saving…', 'modern-hotel-booking' ),
					'label_saved'    => _x( 'Saved', 'status', 'modern-hotel-booking' ),
					'label_delete'   => esc_html__( 'Delete', 'modern-hotel-booking' ),
					'label_deleting' => esc_html__( 'Deleting…', 'modern-hotel-booking' ),
					'label_error'    => esc_html__( 'Error — try again', 'modern-hotel-booking' ),
					'label_required' => esc_html__( 'Please enter a service title.', 'modern-hotel-booking' ),
				)
			);
		}
		/* BUILD_PRO_END */
	}

	public function add_plugin_admin_menu(): void {
		$manage_cap = Capabilities::MANAGE_LHBO;
		$view_cap   = Capabilities::VIEW_ANALYTICS;
		$set_cap    = Capabilities::MANAGE_SETTINGS;

		add_menu_page( I18n::get_label( 'menu_main' ), I18n::get_label( 'menu_main' ), $view_cap, 'mhbo-hotel-booking', array( $this, 'display_dashboard_page' ), 'dashicons-building', 26 );
		add_submenu_page( 'mhbo-hotel-booking', I18n::get_label( 'menu_bookings' ), I18n::get_label( 'menu_bookings' ), $manage_cap, 'mhbo-bookings', array( $this, 'display_bookings_page' ) );
		if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) {
			add_submenu_page( 'mhbo-hotel-booking', __( 'Guests', 'modern-hotel-booking' ), __( 'Guests', 'modern-hotel-booking' ), Capabilities::MANAGE_GUESTS, 'mhbo-guests', array( $this, 'display_dataviews_page' ) );
			/* BUILD_PRO_START */
			if ( (int) get_option( 'mhbo_marketing_emailer_enabled', 0 ) === 1 ) {
				add_submenu_page( 'mhbo-hotel-booking', __( 'Marketing', 'modern-hotel-booking' ), __( 'Marketing', 'modern-hotel-booking' ), Capabilities::MANAGE_GUESTS, 'mhbo-marketing', array( $this, 'display_marketing_page' ) );
			}
			/* BUILD_PRO_END */
		}
		add_submenu_page( 'mhbo-hotel-booking', I18n::get_label( 'menu_room_types' ), I18n::get_label( 'menu_room_types' ), $set_cap, 'mhbo-room-types', array( $this, 'display_room_types_page' ) );
		add_submenu_page( 'mhbo-hotel-booking', I18n::get_label( 'menu_rooms' ), I18n::get_label( 'menu_rooms' ), $set_cap, 'mhbo-rooms', array( $this, 'display_rooms_page' ) );
		add_submenu_page( 'mhbo-hotel-booking', I18n::get_label( 'menu_advanced_pricing' ), I18n::get_label( 'menu_pricing' ), $set_cap, 'mhbo-pricing-rules', array( 'MHBO\\Admin\\PricingController', 'render' ) );
		add_submenu_page( 'mhbo-hotel-booking', I18n::get_label( 'menu_settings' ), I18n::get_label( 'menu_settings' ), $set_cap, 'mhbo-settings', array( 'MHBO\\Admin\\Settings', 'render' ) );

		/* BUILD_PRO_START */
		add_submenu_page( 'mhbo-hotel-booking', __( 'Housekeeping', 'modern-hotel-booking' ), __( 'Housekeeping', 'modern-hotel-booking' ), $manage_cap, 'mhbo-housekeeping', array( $this, 'display_housekeeping_page' ) );
		if ( (int) get_option( 'mhbo_tablet_mode_enabled', 1 ) === 1 ) {
			add_submenu_page(
				'mhbo-hotel-booking',
				__( 'Tablet Mode', 'modern-hotel-booking' ),
				__( 'Tablet Mode', 'modern-hotel-booking' ),
				$manage_cap,
				'mhbo-tablet-mode',
				array( $this, 'display_tablet_mode_page' )
			);
		}
		/* BUILD_PRO_END */

		/* BUILD_STARTER_START */
		add_submenu_page( 'mhbo-hotel-booking', I18n::get_label( 'menu_pro_features' ), I18n::get_label( 'menu_pro_features' ), $set_cap, 'mhbo-pro', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );

		// Register Pro subpages (hidden from sidebar by nesting under mhbo-pro)
		add_submenu_page( 'mhbo-pro', __( 'Tablet Mode', 'modern-hotel-booking' ), __( 'Tablet Mode', 'modern-hotel-booking' ), $manage_cap, 'mhbo-pro-tablet-mode', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		if ( ! License::is_pro_active() || (int) get_option( 'mhbo_tablet_mode_enabled', 1 ) !== 1 ) {
			add_submenu_page( 'mhbo-pro', __( 'Tablet Mode', 'modern-hotel-booking' ), __( 'Tablet Mode', 'modern-hotel-booking' ), $manage_cap, 'mhbo-tablet-mode', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		}
		add_submenu_page( 'mhbo-pro', __( 'Housekeeping', 'modern-hotel-booking' ), __( 'Housekeeping', 'modern-hotel-booking' ), $manage_cap, 'mhbo-pro-housekeeping', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', __( 'Marketing & Templates', 'modern-hotel-booking' ), __( 'Marketing & Templates', 'modern-hotel-booking' ), Capabilities::MANAGE_GUESTS, 'mhbo-pro-marketing', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', __( 'Review Engine', 'modern-hotel-booking' ), __( 'Review Engine', 'modern-hotel-booking' ), $set_cap, 'mhbo-pro-reviews', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', __( 'AI Concierge', 'modern-hotel-booking' ), __( 'AI Concierge', 'modern-hotel-booking' ), $set_cap, 'mhbo-pro-ai', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		/* BUILD_PRO_START */
		if ( 1 !== (int) get_option( 'mhbo_marketing_emailer_enabled', 0 ) || false === class_exists( GuestManager::class ) || false === GuestManager::is_enabled() ) {
			add_submenu_page( 'mhbo-pro', __( 'Marketing', 'modern-hotel-booking' ), __( 'Marketing', 'modern-hotel-booking' ), Capabilities::MANAGE_GUESTS, 'mhbo-marketing', array( $this, 'display_marketing_page' ) );
		}
		/* BUILD_PRO_END */
		add_submenu_page( 'mhbo-pro', I18n::get_label( 'menu_extras' ), I18n::get_label( 'menu_extras' ), $manage_cap, 'mhbo-pro-extras', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', I18n::get_label( 'menu_ical' ), I18n::get_label( 'menu_ical' ), $manage_cap, 'mhbo-pro-ical', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', I18n::get_label( 'menu_payments' ), I18n::get_label( 'menu_payments' ), $view_cap, 'mhbo-pro-payments', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', I18n::get_label( 'menu_webhooks' ), I18n::get_label( 'menu_webhooks' ), $set_cap, 'mhbo-pro-webhooks', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', I18n::get_label( 'menu_analytics' ), I18n::get_label( 'menu_analytics' ), $view_cap, 'mhbo-pro-analytics', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', I18n::get_label( 'menu_appearance' ), I18n::get_label( 'menu_appearance' ), $set_cap, 'mhbo-pro-themes', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', I18n::get_label( 'menu_advanced_pricing' ), I18n::get_label( 'menu_advanced_pricing' ), $set_cap, 'mhbo-pro-pricing', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', I18n::get_label( 'tab_tax' ), I18n::get_label( 'tab_tax' ), $set_cap, 'mhbo-pro-tax', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', __( 'Coupons', 'modern-hotel-booking' ), __( 'Coupons', 'modern-hotel-booking' ), $set_cap, 'mhbo-pro-coupons', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		add_submenu_page( 'mhbo-pro', I18n::get_label( 'menu_licensing' ), I18n::get_label( 'menu_licensing' ), $set_cap, 'mhbo-pro-licensing', array( 'MHBO\\Admin\\Settings', 'render_pro_page' ) );
		/* BUILD_STARTER_END */
	}

	/* BUILD_PRO_START */
	public function display_marketing_page(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_GUESTS ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		if ( class_exists( \MHBO\Pro\MarketingTemplateSeeder::class ) ) {
			\MHBO\Pro\MarketingTemplateSeeder::seed_default_templates( false );
		}

		$templates = get_posts(
			array(
				'post_type'      => 'mhbo_email_template',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$queue       = get_option( 'mhbo_marketing_queue', array() );
		$queue_count = is_array( $queue ) ? count( $queue ) : 0;

		global $wpdb;
		$recent_logs     = array();
		$logs_table_name = $wpdb->prefix . 'mhbo_communication_logs';
		// Check if communication logs table exists via cache
		if ( Cache::table_exists( $logs_table_name ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$recent_logs = (array) $wpdb->get_results(
				"SELECT l.*, g.first_name, g.last_name 
                 FROM {$wpdb->prefix}mhbo_communication_logs l
                 LEFT JOIN {$wpdb->prefix}mhbo_guests g ON l.guest_id = g.id
                 WHERE l.type IN ('vip_coupon', 'marketing_campaign', 'marketing')
                 ORDER BY l.sent_at DESC
                 LIMIT 20"
			);
		}
		?>
		<div class="wrap mhbo-admin-wrap mhbo-marketing-wrap">
			<?php
			AdminUI::render_header(
				__( 'Guest Marketing & Email Campaigns', 'modern-hotel-booking' ),
				__( 'Design Gutenberg-powered marketing templates, schedule targeted guest campaigns, and generate dynamic single-use discount vouchers.', 'modern-hotel-booking' ),
				array(
					array(
						'label' => __( '➕ Create Custom Template', 'modern-hotel-booking' ),
						'url'   => admin_url( 'post-new.php?post_type=mhbo_email_template' ),
						'class' => 'button-primary',
					),
					array(
						'label' => __( '📦 Re-install Base Templates', 'modern-hotel-booking' ),
						'url'   => 'javascript:void(0);',
						'class' => 'button-secondary',
						'attr'  => 'onclick="mhboSeedTemplates(true)"',
					),
					array(
						'label' => __( '📄 View All Templates', 'modern-hotel-booking' ),
						'url'   => admin_url( 'edit.php?post_type=mhbo_email_template' ),
						'class' => 'button-secondary',
					),
					array(
						'label' => __( '👥 Guest CRM', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-guests' ),
						'class' => 'button-secondary',
					),
					array(
						'label' => __( '⚙️ Settings', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-settings&tab=general' ),
						'class' => 'button-secondary',
					),
				)
			);
			?>

			<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-top: 20px;">
				<!-- Main Dispatch Form -->
				<div class="mhbo-settings-section-card" style="box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
					<div class="mhbo-settings-section-header">
						<div class="mhbo-settings-section-title-wrap">
							<div class="mhbo-settings-section-icon" style="background: #eff6ff; color: #2563eb;">
								<span class="dashicons dashicons-email-alt"></span>
							</div>
							<div>
								<h3 class="mhbo-settings-section-title">
									<?php esc_html_e( 'Dispatch Targeted Campaign', 'modern-hotel-booking' ); ?>
									<span class="mhbo-settings-badge-pro">PRO</span>
								</h3>
								<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Send customized promotional emails to targeted guest segments.', 'modern-hotel-booking' ); ?></p>
							</div>
						</div>
					</div>

					<form id="mhbo-campaign-dispatch-form" onsubmit="mhboDispatchCampaign(event)">
						<div class="mhbo-settings-grid">
							<div class="mhbo-form-group mhbo-span-full">
								<label class="mhbo-form-label" for="mhbo-campaign-template">
									<?php esc_html_e( 'Email Template', 'modern-hotel-booking' ); ?> <span style="color:#dc2626;">*</span>
								</label>
								<div id="mhbo-empty-templates-notice" style="<?php echo empty( $templates ) ? '' : 'display: none;'; ?> margin-bottom: 12px; padding: 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px;">
									<p style="color: #dc2626; margin: 0 0 10px 0; font-weight: 500;">
										<?php esc_html_e( 'No published email templates found.', 'modern-hotel-booking' ); ?>
									</p>
									<div style="display: flex; gap: 8px; flex-wrap: wrap;">
										<button type="button" onclick="mhboSeedTemplates(false)" class="button button-primary" style="display: inline-flex; align-items: center; gap: 4px;">
											<span class="dashicons dashicons-download" style="font-size: 15px; width: 15px; height: 15px; margin: 0;"></span>
											<?php esc_html_e( '📦 Install 7 Base Marketing Templates', 'modern-hotel-booking' ); ?>
										</button>
										<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mhbo_email_template' ) ); ?>" class="button button-secondary">
											<?php esc_html_e( 'Create Custom Template', 'modern-hotel-booking' ); ?>
										</a>
									</div>
								</div>
								<div id="mhbo-template-select-wrap" style="<?php echo empty( $templates ) ? 'display: none;' : ''; ?>">
									<select id="mhbo-campaign-template" name="template_id" class="mhbo-form-input" <?php echo empty( $templates ) ? '' : 'required'; ?>>
										<option value=""><?php esc_html_e( '— Select Template —', 'modern-hotel-booking' ); ?></option>
										<?php foreach ( $templates as $tpl ) : ?>
											<?php
											$tpl_obj = ( $tpl instanceof \WP_Post ) ? $tpl : get_post( $tpl );
											if ( ! $tpl_obj ) {
												continue;
											}
											?>
											<option value="<?php echo (int) $tpl_obj->ID; ?>"><?php echo esc_html( $tpl_obj->post_title ?: __( '(Untitled Template)', 'modern-hotel-booking' ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="mhbo-form-group mhbo-span-full">
								<label class="mhbo-form-label" for="mhbo-campaign-group">
									<?php esc_html_e( 'Target Audience', 'modern-hotel-booking' ); ?> <span style="color:#dc2626;">*</span>
								</label>
								<select id="mhbo-campaign-group" name="group" class="mhbo-form-input">
									<option value="all_opted_in"><?php esc_html_e( 'All Marketing Opted-in Guests', 'modern-hotel-booking' ); ?></option>
									<option value="vip"><?php esc_html_e( 'VIP Guests Only', 'modern-hotel-booking' ); ?></option>
									<option value="repeat"><?php esc_html_e( 'Repeat Guests (2+ Stays)', 'modern-hotel-booking' ); ?></option>
								</select>
							</div>

							<div class="mhbo-form-group mhbo-span-full">
								<div class="mhbo-switch-row">
									<div class="mhbo-switch-info">
										<span class="mhbo-switch-title"><?php esc_html_e( 'Dynamic Single-Use Voucher', 'modern-hotel-booking' ); ?></span>
										<p class="mhbo-switch-desc"><?php esc_html_e( 'Generate a unique personalized discount coupon for each recipient.', 'modern-hotel-booking' ); ?></p>
									</div>
									<label class="mhbo-toggle-switch">
										<input type="checkbox" id="mhbo-campaign-voucher-toggle" name="include_voucher" value="1" onchange="document.getElementById('mhbo-voucher-options').style.display = this.checked ? 'block' : 'none';">
										<span class="mhbo-toggle-slider"></span>
									</label>
								</div>

								<div id="mhbo-voucher-options" style="display: none; margin-top: 14px; padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
									<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px;">
										<div>
											<label style="display: block; font-size: 12px; margin-bottom: 4px; font-weight: 600;"><?php esc_html_e( 'Discount Type', 'modern-hotel-booking' ); ?></label>
											<select name="voucher_discount_type" id="mhbo-voucher-type" class="mhbo-form-input">
												<option value="percentage"><?php esc_html_e( 'Percentage (%)', 'modern-hotel-booking' ); ?></option>
												<option value="fixed"><?php esc_html_e( 'Fixed Amount', 'modern-hotel-booking' ); ?></option>
											</select>
										</div>
										<div>
											<label style="display: block; font-size: 12px; margin-bottom: 4px; font-weight: 600;"><?php esc_html_e( 'Discount Value', 'modern-hotel-booking' ); ?></label>
											<input type="number" step="any" min="1" name="voucher_discount_value" id="mhbo-voucher-val" value="10" class="mhbo-form-input">
										</div>
										<div>
											<label style="display: block; font-size: 12px; margin-bottom: 4px; font-weight: 600;"><?php esc_html_e( 'Valid (Days)', 'modern-hotel-booking' ); ?></label>
											<input type="number" step="1" min="0" max="365" name="voucher_validity_days" id="mhbo-voucher-days" value="30" class="mhbo-form-input">
										</div>
									</div>
									<p class="mhbo-form-help" style="margin: 8px 0 0 0;">
										<?php esc_html_e( 'Codes will replace the {voucher_code} tag. Single use per guest. Set 0 for no expiry.', 'modern-hotel-booking' ); ?>
									</p>
								</div>
							</div>

							<div class="mhbo-form-group mhbo-span-full" style="padding-top: 16px; border-top: 1px solid #f1f5f9; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
								<button type="submit" id="mhbo-dispatch-btn" class="button button-primary" style="height: 38px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;" <?php disabled( empty( $templates ) ); ?>>
									<span class="dashicons dashicons-controls-play" style="margin: 0; font-size: 16px; width: 16px; height: 16px;"></span>
									<?php esc_html_e( 'Queue Campaign for Dispatch', 'modern-hotel-booking' ); ?>
								</button>
								<button type="button" id="mhbo-test-btn" class="button button-secondary" style="height: 38px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;" <?php disabled( empty( $templates ) ); ?> onclick="mhboSendTestCampaign()">
									<span class="dashicons dashicons-email" style="margin: 0; font-size: 16px; width: 16px; height: 16px;"></span>
									<?php esc_html_e( 'Send Test to Me', 'modern-hotel-booking' ); ?>
								</button>
								<span id="mhbo-dispatch-status" style="font-weight: 600; font-size: 13px;"></span>
							</div>
						</div>
					</form>
				</div>

				<!-- Sidebar Info & Queue -->
				<div style="display: flex; flex-direction: column; gap: 20px;">
					<!-- Queue Status -->
					<div class="mhbo-card" style="background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
						<h4 style="margin-top: 0; margin-bottom: 12px; font-size: 1rem; display: flex; align-items: center; gap: 6px;">
							<span class="dashicons dashicons-clock" style="color: #059669;"></span>
							<?php esc_html_e( 'Queue & WP-Cron Status', 'modern-hotel-booking' ); ?>
						</h4>
						<div style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; margin-bottom: 12px;">
							<span style="font-weight: 600; color: #166534;"><?php esc_html_e( 'Pending in Queue:', 'modern-hotel-booking' ); ?></span>
							<span id="mhbo-queue-badge" style="background: #166534; color: #fff; padding: 2px 10px; border-radius: 12px; font-weight: bold;">
								<?php echo esc_html( (string) $queue_count ); ?>
							</span>
						</div>
						<p style="margin: 0; font-size: 13px; color: #64748b; line-height: 1.5;">
							<?php esc_html_e( 'Emails are delivered in background batches of 20 via WP-Cron hourly to prevent shared server timeouts and respect SMTP sending limits.', 'modern-hotel-booking' ); ?>
						</p>

						<!-- Manual Queue Controls -->
						<div id="mhbo-queue-actions" style="margin-top: 14px; padding-top: 14px; border-top: 1px solid #e2e8f0; display: <?php echo $queue_count > 0 ? 'block' : 'none'; ?>;">
							<div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
								<button type="button" onclick="mhboProcessQueue(true)" id="mhbo-btn-process-all" class="button button-primary" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 600; font-size: 12px; height: 32px;">
									<span class="dashicons dashicons-controls-play" style="font-size: 15px; width: 15px; height: 15px; margin: 0;"></span>
									<?php esc_html_e( 'Process All Now', 'modern-hotel-booking' ); ?>
								</button>
								<button type="button" onclick="mhboProcessQueue(false)" id="mhbo-btn-process-batch" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 600; font-size: 12px; height: 32px;">
									<span class="dashicons dashicons-controls-forward" style="font-size: 15px; width: 15px; height: 15px; margin: 0;"></span>
									<?php esc_html_e( 'Process Batch (20)', 'modern-hotel-booking' ); ?>
								</button>
								<button type="button" onclick="mhboClearQueue()" id="mhbo-btn-clear-queue" class="button button-link-delete" style="font-size: 12px; margin-left: auto; text-decoration: none; color: #dc2626; display: inline-flex; align-items: center; gap: 4px;">
									<span class="dashicons dashicons-trash" style="font-size: 14px; width: 14px; height: 14px; margin: 0;"></span>
									<?php esc_html_e( 'Clear Queue', 'modern-hotel-booking' ); ?>
								</button>
							</div>
							<div id="mhbo-queue-action-status" style="margin-top: 8px; font-size: 12px; font-weight: 600;"></div>
						</div>
					</div>

					<!-- Merge Tags Reference -->
					<div class="mhbo-card" style="background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
						<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 6px;">
							<h4 style="margin: 0; font-size: 1rem; display: flex; align-items: center; gap: 6px;">
								<span class="dashicons dashicons-editor-code" style="color: #d97706;"></span>
								<?php esc_html_e( 'Supported Merge Tags', 'modern-hotel-booking' ); ?>
							</h4>
							<span id="mhbo-mkt-copy-toast" style="display: none; font-size: 11.5px; font-weight: 600; color: #166534; background: #dcfce7; padding: 2px 8px; border-radius: 4px; border: 1px solid #bbf7d0;">
								<?php esc_html_e( 'Copied tag!', 'modern-hotel-booking' ); ?>
							</span>
						</div>
						<p style="margin-top: 0; margin-bottom: 14px; font-size: 12px; color: #64748b;">
							<?php esc_html_e( 'Click any tag below to copy it for use in your Gutenberg block editor templates:', 'modern-hotel-booking' ); ?>
						</p>

						<?php
						$mkt_tags = array(
							__( 'Guest Details', 'modern-hotel-booking' ) => array(
								'{guest_name}'     => __( 'Full name', 'modern-hotel-booking' ),
								'{first_name}'     => __( 'First name', 'modern-hotel-booking' ),
								'{last_name}'      => __( 'Last name', 'modern-hotel-booking' ),
								'{customer_name}'  => __( 'Guest full name (synonym)', 'modern-hotel-booking' ),
								'{customer_email}' => __( 'Guest email address', 'modern-hotel-booking' ),
								'{customer_phone}' => __( 'Guest phone number', 'modern-hotel-booking' ),
							),
							__( 'Property & Business', 'modern-hotel-booking' ) => array(
								'{hotel_name}'           => __( 'Property name', 'modern-hotel-booking' ),
								'{company_name}'         => __( 'Hotel registered name', 'modern-hotel-booking' ),
								'{site_title}'           => __( 'WordPress site name', 'modern-hotel-booking' ),
								'{site_name}'            => __( 'Site title (synonym)', 'modern-hotel-booking' ),
								'{hotel_phone}'          => __( 'Hotel phone number', 'modern-hotel-booking' ),
								'{hotel_email}'          => __( 'Hotel inquiries email', 'modern-hotel-booking' ),
								'{company_address}'      => __( 'Hotel physical address', 'modern-hotel-booking' ),
								'{company_website}'      => __( 'Hotel website link', 'modern-hotel-booking' ),
								'{company_registration}' => __( 'VAT / Business Registration ID', 'modern-hotel-booking' ),
								'{mhbo_logo_url}'        => __( 'Hotel logo image URL', 'modern-hotel-booking' ),
								'{logo_header}'          => __( 'Branded logo header HTML', 'modern-hotel-booking' ),
							),
							__( 'Vouchers & Offers', 'modern-hotel-booking' ) => array(
								'{voucher_code}'     => __( 'Generated coupon code', 'modern-hotel-booking' ),
								'{voucher_discount}' => __( 'Formatted discount amount', 'modern-hotel-booking' ),
								'{expiry_date}'      => __( 'Coupon expiry date', 'modern-hotel-booking' ),
								'{custom_note}'      => __( 'Staff message / personal note', 'modern-hotel-booking' ),
							),
							__( 'Links & Actions', 'modern-hotel-booking' ) => array(
								'{booking_link}'     => __( 'Direct booking page URL', 'modern-hotel-booking' ),
								'{whatsapp_link}'    => __( 'WhatsApp direct URL', 'modern-hotel-booking' ),
								'{whatsapp_contact}' => __( 'Styled WhatsApp button card (HTML)', 'modern-hotel-booking' ),
								'{social_links}'     => __( 'Social media icon row (HTML)', 'modern-hotel-booking' ),
								'{social_box}'       => __( 'Styled social media container (HTML)', 'modern-hotel-booking' ),
								'{review_links}'     => __( 'Review collection buttons (HTML)', 'modern-hotel-booking' ),
								'{review_box}'       => __( 'Styled review invite card (HTML)', 'modern-hotel-booking' ),
								'{google_map}'       => __( 'Google Maps navigation card (HTML)', 'modern-hotel-booking' ),
								'{business_card}'    => __( 'Hotel business card block (HTML)', 'modern-hotel-booking' ),
								'{company_info}'     => __( 'Company info card (HTML)', 'modern-hotel-booking' ),
								'{banking_details}'  => __( 'Bank transfer card (HTML)', 'modern-hotel-booking' ),
								'{revolut_details}'  => __( 'Revolut payment card (HTML)', 'modern-hotel-booking' ),
								'{unsubscribe_url}'  => __( 'One-click opt-out URL (GDPR/CAN-SPAM)', 'modern-hotel-booking' ),
								'{unsubscribe_link}' => __( 'Styled unsubscribe text link (HTML)', 'modern-hotel-booking' ),
							),
						);
						?>

						<div style="display: flex; flex-direction: column; gap: 14px; max-height: 480px; overflow-y: auto; padding-right: 4px;">
							<?php foreach ( $mkt_tags as $cat_title => $tags ) : ?>
								<div>
									<div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.5px; margin-bottom: 6px; border-bottom: 1px solid #f1f5f9; padding-bottom: 3px;">
										<?php echo esc_html( $cat_title ); ?>
									</div>
									<table style="width: 100%; font-size: 12px; border-collapse: collapse;">
										<?php foreach ( $tags as $tag => $desc ) : ?>
											<tr>
												<td style="padding: 3px 0; width: 48%; vertical-align: top;">
													<code onclick="mhboCopyMktTag(this)" title="<?php esc_attr_e( 'Click to copy tag', 'modern-hotel-booking' ); ?>" style="cursor: pointer; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-block;">
														<?php echo esc_html( $tag ); ?>
													</code>
												</td>
												<td style="padding: 3px 0; color: #64748b; vertical-align: top; font-size: 11.5px;">
													<?php echo esc_html( $desc ); ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</table>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Recent Marketing Dispatches Audit Log -->
			<div class="mhbo-settings-section-card" style="margin-top: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
				<div class="mhbo-settings-section-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
					<div class="mhbo-settings-section-title-wrap">
						<div class="mhbo-settings-section-icon" style="background: #f0fdf4; color: #16a34a;">
							<span class="dashicons dashicons-list-view"></span>
						</div>
						<div>
							<h3 class="mhbo-settings-section-title">
								<?php esc_html_e( 'Recent Marketing & VIP Dispatches', 'modern-hotel-booking' ); ?>
							</h3>
							<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Audit trail of promotional campaigns and VIP vouchers dispatched via Email Logger.', 'modern-hotel-booking' ); ?></p>
						</div>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-guests' ) ); ?>" class="button button-secondary" style="font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
						<span class="dashicons dashicons-groups" style="font-size: 14px; width: 14px; height: 14px;"></span>
						<?php esc_html_e( 'View Guest CRM →', 'modern-hotel-booking' ); ?>
					</a>
				</div>

				<?php if ( empty( $recent_logs ) ) : ?>
					<div style="padding: 32px 20px; text-align: center; color: #64748b; background: #f8fafc; border-radius: 6px; border: 1px dashed #cbd5e1; margin-top: 10px;">
						<span class="dashicons dashicons-email" style="font-size: 32px; width: 32px; height: 32px; color: #94a3b8; margin-bottom: 8px;"></span>
						<p style="margin: 0; font-weight: 500;"><?php esc_html_e( 'No marketing or VIP voucher emails dispatched yet.', 'modern-hotel-booking' ); ?></p>
						<p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;"><?php esc_html_e( 'Dispatched campaigns and queued emails will automatically appear here with live delivery status and timestamps.', 'modern-hotel-booking' ); ?></p>
					</div>
				<?php else : ?>
					<div style="overflow-x: auto; margin-top: 10px;">
						<table class="wp-list-table widefat fixed striped" style="border: 1px solid #e2e8f0; border-radius: 6px; border-collapse: separate; border-spacing: 0;">
							<thead>
								<tr>
									<th style="font-weight: 600; width: 140px; padding: 10px 12px;"><?php esc_html_e( 'Date / Time', 'modern-hotel-booking' ); ?></th>
									<th style="font-weight: 600; width: 180px; padding: 10px 12px;"><?php esc_html_e( 'Recipient', 'modern-hotel-booking' ); ?></th>
									<th style="font-weight: 600; width: 130px; padding: 10px 12px;"><?php esc_html_e( 'Type', 'modern-hotel-booking' ); ?></th>
									<th style="font-weight: 600; padding: 10px 12px;"><?php esc_html_e( 'Subject Line', 'modern-hotel-booking' ); ?></th>
									<th style="font-weight: 600; width: 110px; text-align: center; padding: 10px 12px;"><?php esc_html_e( 'Status', 'modern-hotel-booking' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $recent_logs as $log ) : ?>
									<?php
									$guest_name = trim( ( $log->first_name ?? '' ) . ' ' . ( $log->last_name ?? '' ) );
									$type_label = ( 'vip_coupon' === $log->type ) ? '🎁 VIP Voucher' : '📢 Campaign';
									$type_bg    = ( 'vip_coupon' === $log->type ) ? '#fef3c7; color: #92400e; border: 1px solid #fde68a;' : '#eff6ff; color: #1e40af; border: 1px solid #bfdbfe;';
									$is_sent    = ( 'sent' === $log->status );
									?>
									<tr>
										<td style="padding: 10px 12px; font-size: 12.5px; color: #475569; vertical-align: middle;">
											<strong><?php echo esc_html( HotelTime::format_date( $log->sent_at ) ); ?></strong><br>
											<span style="font-size: 11px; color: #94a3b8;"><?php echo esc_html( HotelTime::format_time( $log->sent_at ) ); ?></span>
										</td>
										<td style="padding: 10px 12px; vertical-align: middle;">
											<?php if ( ! empty( $guest_name ) ) : ?>
												<strong style="color: #0f172a; font-size: 13px;"><?php echo esc_html( $guest_name ); ?></strong><br>
											<?php endif; ?>
											<span style="font-size: 12px; color: #64748b;"><?php echo esc_html( $log->recipient ); ?></span>
										</td>
										<td style="padding: 10px 12px; vertical-align: middle;">
											<span style="display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; background: <?php echo esc_attr( $type_bg ); ?>">
												<?php echo esc_html( $type_label ); ?>
											</span>
										</td>
										<td style="padding: 10px 12px; vertical-align: middle;">
											<div style="font-weight: 600; font-size: 13px; color: #1e293b; margin-bottom: 2px;">
												<?php echo esc_html( $log->subject ?: __( '(No Subject)', 'modern-hotel-booking' ) ); ?>
											</div>
											<?php if ( ! empty( $log->body_snippet ) ) : ?>
												<div style="font-size: 11.5px; color: #64748b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 480px;">
													<?php echo esc_html( $log->body_snippet ); ?>
												</div>
											<?php endif; ?>
										</td>
										<td style="padding: 10px 12px; text-align: center; vertical-align: middle;">
											<?php if ( $is_sent ) : ?>
												<span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; border-radius: 12px; font-size: 11px; font-weight: 600;">
													<span class="dashicons dashicons-yes" style="font-size: 13px; width: 13px; height: 13px;"></span>
													<?php esc_html_e( 'Sent', 'modern-hotel-booking' ); ?>
												</span>
											<?php else : ?>
												<span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 12px; font-size: 11px; font-weight: 600;" title="<?php echo esc_attr( (string) ( $log->error_message ?? '' ) ); ?>">
													<span class="dashicons dashicons-no-alt" style="font-size: 13px; width: 13px; height: 13px;"></span>
													<?php esc_html_e( 'Failed', 'modern-hotel-booking' ); ?>
												</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<script>
		function mhboCopyMktTag(el) {
			const text = el.innerText.trim();
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function() {
					const toast = document.getElementById('mhbo-mkt-copy-toast');
					if (toast) {
						toast.style.display = 'inline-block';
						toast.innerText = '<?php echo esc_js( __( 'Copied', 'modern-hotel-booking' ) ); ?> ' + text + '!';
						setTimeout(function() {
							toast.style.display = 'none';
						}, 2500);
					}
				});
			}
		}

		function mhboProcessQueue(all) {
			const statusEl = document.getElementById('mhbo-queue-action-status');
			const btnAll   = document.getElementById('mhbo-btn-process-all');
			const btnBatch = document.getElementById('mhbo-btn-process-batch');
			const btnClear = document.getElementById('mhbo-btn-clear-queue');
			const badge    = document.getElementById('mhbo-queue-badge');

			if (btnAll) btnAll.disabled = true;
			if (btnBatch) btnBatch.disabled = true;
			if (btnClear) btnClear.disabled = true;

			if (statusEl) {
				statusEl.style.color = '#2563eb';
				statusEl.textContent = '⏳ <?php echo esc_js( __( 'Processing marketing queue…', 'modern-hotel-booking' ) ); ?>';
			}

			wp.apiFetch({
				path: '/mhbo/v1/admin/marketing/process-queue-now',
				method: 'POST',
				data: { all: all ? 1 : 0 }
			}).then(function(res) {
				if (statusEl) {
					statusEl.style.color = '#16a34a';
					statusEl.textContent = '✅ ' + (res.message || '<?php echo esc_js( __( 'Processed successfully!', 'modern-hotel-booking' ) ); ?>');
				}
				if (badge && res.remaining !== undefined) {
					badge.textContent = res.remaining;
					if (res.remaining === 0) {
						const actions = document.getElementById('mhbo-queue-actions');
						if (actions) {
							setTimeout(function() {
								actions.style.display = 'none';
							}, 4000);
						}
					}
				}
				setTimeout(function() {
					window.location.reload();
				}, 1500);
			}).catch(function(err) {
				if (statusEl) {
					statusEl.style.color = '#dc2626';
					statusEl.textContent = '❌ ' + (err.message || '<?php echo esc_js( __( 'Failed to process queue.', 'modern-hotel-booking' ) ); ?>');
				}
				if (btnAll) btnAll.disabled = false;
				if (btnBatch) btnBatch.disabled = false;
				if (btnClear) btnClear.disabled = false;
			});
		}

		function mhboClearQueue() {
			if (!confirm('<?php echo esc_js( __( 'Are you sure you want to clear all pending emails in the queue? This cannot be undone.', 'modern-hotel-booking' ) ); ?>')) {
				return;
			}
			const statusEl = document.getElementById('mhbo-queue-action-status');
			const btnAll   = document.getElementById('mhbo-btn-process-all');
			const btnBatch = document.getElementById('mhbo-btn-process-batch');
			const btnClear = document.getElementById('mhbo-btn-clear-queue');
			const badge    = document.getElementById('mhbo-queue-badge');

			if (btnAll) btnAll.disabled = true;
			if (btnBatch) btnBatch.disabled = true;
			if (btnClear) btnClear.disabled = true;

			if (statusEl) {
				statusEl.style.color = '#dc2626';
				statusEl.textContent = '⏳ <?php echo esc_js( __( 'Clearing queue…', 'modern-hotel-booking' ) ); ?>';
			}

			wp.apiFetch({
				path: '/mhbo/v1/admin/marketing/clear-queue',
				method: 'POST'
			}).then(function(res) {
				if (statusEl) {
					statusEl.style.color = '#16a34a';
					statusEl.textContent = '✅ ' + (res.message || '<?php echo esc_js( __( 'Queue cleared.', 'modern-hotel-booking' ) ); ?>');
				}
				if (badge) {
					badge.textContent = '0';
				}
				const actions = document.getElementById('mhbo-queue-actions');
				if (actions) {
					setTimeout(function() {
						actions.style.display = 'none';
					}, 2500);
				}
			}).catch(function(err) {
				if (statusEl) {
					statusEl.style.color = '#dc2626';
					statusEl.textContent = '❌ ' + (err.message || '<?php echo esc_js( __( 'Failed to clear queue.', 'modern-hotel-booking' ) ); ?>');
				}
				if (btnAll) btnAll.disabled = false;
				if (btnBatch) btnBatch.disabled = false;
				if (btnClear) btnClear.disabled = false;
			});
		}

		function mhboDispatchCampaign(e) {
			e.preventDefault();
			const btn = document.getElementById('mhbo-dispatch-btn');
			const statusEl = document.getElementById('mhbo-dispatch-status');
			const templateId = document.getElementById('mhbo-campaign-template').value;
			const group = document.getElementById('mhbo-campaign-group').value;
			const includeVoucher = document.getElementById('mhbo-campaign-voucher-toggle').checked;
			
			if (!templateId) {
				alert('<?php echo esc_js( __( 'Please select an email template.', 'modern-hotel-booking' ) ); ?>');
				return;
			}

			btn.disabled = true;
			statusEl.style.color = '#2563eb';
			statusEl.textContent = '<?php echo esc_js( __( 'Queueing campaign…', 'modern-hotel-booking' ) ); ?>';

			const rawDays = document.getElementById('mhbo-voucher-days').value;
			const parsedDays = parseInt(rawDays, 10);
			const validityDays = isNaN(parsedDays) ? 30 : Math.max(0, parsedDays);

			const payload = {
				template_id: parseInt(templateId, 10),
				group_slug: group,
				group: group,
				include_voucher: includeVoucher,
				voucher_config: includeVoucher ? {
					discount_type: document.getElementById('mhbo-voucher-type').value,
					discount_value: parseFloat(document.getElementById('mhbo-voucher-val').value) || 10,
					validity_days: validityDays
				} : {}
			};

			wp.apiFetch({
				path: '/mhbo/v1/admin/marketing/campaigns',
				method: 'POST',
				data: payload
			}).then(function(res) {
				statusEl.style.color = '#16a34a';
				statusEl.textContent = '✅ ' + (res.message || '<?php echo esc_js( __( 'Campaign queued successfully!', 'modern-hotel-booking' ) ); ?>');
				if (res.queued_count !== undefined) {
					const badge = document.getElementById('mhbo-queue-badge');
					if (badge) {
						badge.textContent = parseInt(badge.textContent || 0, 10) + res.queued_count;
					}
					const actions = document.getElementById('mhbo-queue-actions');
					if (actions) {
						actions.style.display = 'block';
					}
				}
				setTimeout(function() {
					btn.disabled = false;
				}, 3000);
			}).catch(function(err) {
				statusEl.style.color = '#dc2626';
				statusEl.textContent = '❌ ' + (err.message || '<?php echo esc_js( __( 'Failed to queue campaign.', 'modern-hotel-booking' ) ); ?>');
				btn.disabled = false;
			});
		}

		function mhboSendTestCampaign() {
			const btn = document.getElementById('mhbo-test-btn');
			const statusEl = document.getElementById('mhbo-dispatch-status');
			const templateId = document.getElementById('mhbo-campaign-template').value;
			const includeVoucher = document.getElementById('mhbo-campaign-voucher-toggle').checked;

			if (!templateId) {
				alert('<?php echo esc_js( __( 'Please select an email template.', 'modern-hotel-booking' ) ); ?>');
				return;
			}

			btn.disabled = true;
			statusEl.style.color = '#2563eb';
			statusEl.textContent = '<?php echo esc_js( __( 'Sending test email…', 'modern-hotel-booking' ) ); ?>';

			const rawDaysTest = document.getElementById('mhbo-voucher-days').value;
			const parsedDaysTest = parseInt(rawDaysTest, 10);
			const validityDaysTest = isNaN(parsedDaysTest) ? 30 : Math.max(0, parsedDaysTest);

			const payload = {
				template_id: parseInt(templateId, 10),
				include_voucher: includeVoucher,
				voucher_config: includeVoucher ? {
					discount_type: document.getElementById('mhbo-voucher-type').value,
					discount_value: parseFloat(document.getElementById('mhbo-voucher-val').value) || 10,
					validity_days: validityDaysTest
				} : {}
			};

			wp.apiFetch({
				path: '/mhbo/v1/admin/marketing/send-test-campaign',
				method: 'POST',
				data: payload
			}).then(function(res) {
				statusEl.style.color = '#16a34a';
				statusEl.textContent = '✅ ' + (res.message || '<?php echo esc_js( __( 'Test email sent successfully!', 'modern-hotel-booking' ) ); ?>');
				setTimeout(function() {
					btn.disabled = false;
				}, 3000);
			}).catch(function(err) {
				statusEl.style.color = '#dc2626';
				statusEl.textContent = '❌ ' + (err.message || '<?php echo esc_js( __( 'Failed to send test email.', 'modern-hotel-booking' ) ); ?>');
				btn.disabled = false;
			});
		}

		function mhboSeedTemplates(force) {
			if (force && !confirm('<?php echo esc_js( __( 'This will install all missing base marketing templates. Existing custom templates will NOT be modified. Continue?', 'modern-hotel-booking' ) ); ?>')) {
				return;
			}

			const statusEl = document.getElementById('mhbo-dispatch-status');
			if (statusEl) {
				statusEl.style.color = '#2563eb';
				statusEl.textContent = '<?php echo esc_js( __( 'Installing base templates…', 'modern-hotel-booking' ) ); ?>';
			}

			wp.apiFetch({
				path: '/mhbo/v1/admin/marketing/seed-templates',
				method: 'POST',
				data: { force: !!force }
			}).then(function(res) {
				if (window.MHBO && window.MHBO.toast) {
					window.MHBO.toast(res.message || '<?php echo esc_js( __( 'Base marketing templates ready!', 'modern-hotel-booking' ) ); ?>', 'success', 3500);
				}
				if (statusEl) {
					statusEl.style.color = '#16a34a';
					statusEl.textContent = '✅ ' + (res.message || '<?php echo esc_js( __( 'Base templates installed.', 'modern-hotel-booking' ) ); ?>');
				}

				const select = document.getElementById('mhbo-campaign-template');
				const emptyWrap = document.getElementById('mhbo-empty-templates-notice');
				const formWrap = document.getElementById('mhbo-template-select-wrap');
				const dispatchBtn = document.getElementById('mhbo-dispatch-btn');
				const testBtn = document.getElementById('mhbo-test-btn');

				if (select && res.templates && Array.isArray(res.templates)) {
					select.innerHTML = '<option value=""><?php echo esc_js( __( '— Select Template —', 'modern-hotel-booking' ) ); ?></option>';
					res.templates.forEach(function(tpl) {
						const opt = document.createElement('option');
						opt.value = tpl.id;
						opt.textContent = tpl.title;
						select.appendChild(opt);
					});
					if (res.templates.length > 0) {
						select.selectedIndex = 1;
					}
				}

				if (emptyWrap) emptyWrap.style.display = 'none';
				if (formWrap) formWrap.style.display = 'block';
				if (dispatchBtn) dispatchBtn.disabled = false;
				if (testBtn) testBtn.disabled = false;
			}).catch(function(err) {
				if (window.MHBO && window.MHBO.toast) {
					window.MHBO.toast(err.message || '<?php echo esc_js( __( 'Failed to install templates.', 'modern-hotel-booking' ) ); ?>', 'error', 4000);
				}
				if (statusEl) {
					statusEl.style.color = '#dc2626';
					statusEl.textContent = '❌ ' + (err.message || '<?php echo esc_js( __( 'Failed to install templates.', 'modern-hotel-booking' ) ); ?>');
				}
			});
		}
		</script>
		<?php
	}
	/* BUILD_PRO_END */

	public function display_dashboard_page(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		global $wpdb;
		$today_date = HotelTime::today();

		$is_pro_active = false;
		/* BUILD_PRO_START */
		$is_pro_active = License::is_active();
		/* BUILD_PRO_END */

		// Kairos Protocol: Cached Dashboard Operations & Analytics
		$cache_key = 'mhbo_dashboard_ops_v260_' . $today_date;
		$stats     = get_transient( $cache_key );

		if ( false === $stats || ! is_array( $stats ) ) {
			// Physical room counts
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total_rooms = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_rooms" );

			// Occupied physical rooms today
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$occupied_rooms = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT room_id) FROM {$wpdb->prefix}mhbo_bookings
                 WHERE status NOT IN ('cancelled', 'refunded')
                   AND check_in <= DATE(%s) AND check_out > DATE(%s)",
					$today_date,
					$today_date
				)
			);

			// Status counts
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$counts = $wpdb->get_results(
				$wpdb->prepare( 'SELECT status, COUNT(*) as qty FROM %i GROUP BY status', $wpdb->prefix . 'mhbo_bookings' ),
				ARRAY_A
			);

			$total_bookings = 0;
			$pending_count  = 0;
			if ( is_array( $counts ) ) {
				foreach ( $counts as $row ) {
					$total_bookings += (int) $row['qty'];
					if ( 'pending' === $row['status'] ) {
						$pending_count = (int) $row['qty'];
					}
				}
			}

			// Confirmed & Completed Revenue vs Pipeline
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$revenue_raw = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
                        COALESCE(SUM(CASE WHEN status IN ('confirmed', 'checked_in', 'checked_out', 'completed', 'paid') THEN total_price ELSE 0 END), 0) as confirmed_revenue,
                        COALESCE(SUM(CASE WHEN status IN ('confirmed', 'checked_in', 'checked_out', 'completed', 'paid') THEN 1 ELSE 0 END), 0) as confirmed_count,
                        COALESCE(SUM(total_price), 0) as total_pipeline,
                        COALESCE(SUM(CASE WHEN payment_received = 1 OR payment_status IN ('completed', 'paid') THEN total_price WHEN deposit_received = 1 OR payment_status = 'deposit_paid' THEN deposit_amount ELSE payment_amount END), 0) as total_collected
                     FROM %i WHERE status NOT IN ('cancelled', 'refunded')",
					$wpdb->prefix . 'mhbo_bookings'
				),
				ARRAY_A
			);

			// Arrivals Today
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$arrivals_list = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT b.*, r.room_number, rt.name as room_type_name
                 FROM {$wpdb->prefix}mhbo_bookings b
                 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                 LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                 WHERE b.status NOT IN ('cancelled', 'refunded') AND b.check_in = DATE(%s)
                 ORDER BY b.id DESC LIMIT 15",
					$today_date
				)
			);

			// Departures Today
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$departures_list = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT b.*, r.room_number, rt.name as room_type_name
                 FROM {$wpdb->prefix}mhbo_bookings b
                 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                 LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                 WHERE b.status NOT IN ('cancelled', 'refunded') AND b.check_out = DATE(%s)
                 ORDER BY b.id DESC LIMIT 15",
					$today_date
				)
			);

			// In-House Guests
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inhouse_list = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT b.*, r.room_number, rt.name as room_type_name
                 FROM {$wpdb->prefix}mhbo_bookings b
                 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                 LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                 WHERE b.status NOT IN ('cancelled', 'refunded')
                   AND b.check_in <= DATE(%s) AND b.check_out > DATE(%s)
                 ORDER BY b.check_out ASC LIMIT 15",
					$today_date,
					$today_date
				)
			);

			// Pending Bookings
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pending_list = $wpdb->get_results(
				"SELECT b.*, r.room_number, rt.name as room_type_name
                 FROM {$wpdb->prefix}mhbo_bookings b
                 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                 LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                 WHERE b.status = 'pending'
                 ORDER BY b.id DESC LIMIT 15"
			);

			// Recent Bookings
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$recent_list = $wpdb->get_results(
				"SELECT b.*, r.room_number, rt.name as room_type_name
                 FROM {$wpdb->prefix}mhbo_bookings b
                 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                 LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                 ORDER BY b.id DESC LIMIT 10"
			);

			// Guest CRM counts
			$guests_table_exists = Cache::table_exists( $wpdb->prefix . 'mhbo_guests' );
			$total_guests        = 0;
			$vip_guests          = 0;
			if ( $guests_table_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$crm_stats = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) as total, COALESCE(SUM(CASE WHEN vip_status = 'vip' THEN 1 ELSE 0 END), 0) as vips FROM %i WHERE status != 'anonymized'",
						$wpdb->prefix . 'mhbo_guests'
					)
				);
				if ( $crm_stats ) {
					$total_guests = (int) $crm_stats->total;
					$vip_guests   = (int) $crm_stats->vips;
				}
			}

			// Room Types summary breakdown
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$room_types_summary = $wpdb->get_results(
				"SELECT rt.id, rt.name, rt.base_price, COUNT(r.id) as physical_count
                 FROM {$wpdb->prefix}mhbo_room_types rt
                 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON rt.id = r.type_id
                 GROUP BY rt.id, rt.name, rt.base_price
                 ORDER BY rt.id ASC"
			);

			// Housekeeping stats
			$turnovers_count = 0;
			$vacant_count    = max( 0, $total_rooms - $occupied_rooms );
			/* BUILD_PRO_START */
			if ( class_exists( \MHBO\Pro\HousekeepingManifest::class ) ) {
				$manifest_summary = \MHBO\Pro\HousekeepingManifest::get_manifest_data( $today_date );
				if ( isset( $manifest_summary['summary'] ) ) {
					$turnovers_count = (int) ( $manifest_summary['summary']['turnovers'] ?? 0 );
					$vacant_count    = (int) ( $manifest_summary['summary']['vacant'] ?? $vacant_count );
				}
			}
			/* BUILD_PRO_END */

			$stats = array(
				'total_rooms'        => $total_rooms,
				'occupied_rooms'     => $occupied_rooms,
				'available_rooms'    => max( 0, $total_rooms - $occupied_rooms ),
				'total_bookings'     => $total_bookings,
				'pending_count'      => $pending_count,
				'confirmed_revenue'  => (float) ( $revenue_raw[0]['confirmed_revenue'] ?? 0 ),
				'confirmed_count'    => (int) ( $revenue_raw[0]['confirmed_count'] ?? 0 ),
				'total_pipeline'     => (float) ( $revenue_raw[0]['total_pipeline'] ?? 0 ),
				'total_collected'    => (float) ( $revenue_raw[0]['total_collected'] ?? 0 ),
				'arrivals'           => $arrivals_list,
				'departures'         => $departures_list,
				'inhouse'            => $inhouse_list,
				'pending'            => $pending_list,
				'recent'             => $recent_list,
				'total_guests'       => $total_guests,
				'vip_guests'         => $vip_guests,
				'turnovers'          => $turnovers_count,
				'vacant'             => $vacant_count,
				'room_types_summary' => $room_types_summary,
			);

			set_transient( $cache_key, $stats, 10 * MINUTE_IN_SECONDS );
		}

		$total_rooms        = (int) ( $stats['total_rooms'] ?? 0 );
		$occupied_rooms     = (int) ( $stats['occupied_rooms'] ?? 0 );
		$available_rooms    = (int) ( $stats['available_rooms'] ?? 0 );
		$total_bookings     = (int) ( $stats['total_bookings'] ?? 0 );
		$pending_count      = (int) ( $stats['pending_count'] ?? 0 );
		$confirmed_revenue  = (float) ( $stats['confirmed_revenue'] ?? 0 );
		$confirmed_count    = (int) ( $stats['confirmed_count'] ?? 0 );
		$arrivals_list      = (array) ( $stats['arrivals'] ?? array() );
		$departures_list    = (array) ( $stats['departures'] ?? array() );
		$inhouse_list       = (array) ( $stats['inhouse'] ?? array() );
		$pending_list       = (array) ( $stats['pending'] ?? array() );
		$recent_list        = (array) ( $stats['recent'] ?? array() );
		$total_guests       = (int) ( $stats['total_guests'] ?? 0 );
		$vip_guests         = (int) ( $stats['vip_guests'] ?? 0 );
		$turnovers          = (int) ( $stats['turnovers'] ?? 0 );
		$vacant_rooms       = (int) ( $stats['vacant'] ?? $available_rooms );
		$room_types_summary = (array) ( $stats['room_types_summary'] ?? array() );

		$occupancy_pct = $total_rooms > 0 ? (int) round( ( $occupied_rooms / $total_rooms ) * 100 ) : 0;
		?>
		<div class="wrap mhbo-admin-wrap mhbo-dashboard">
			<?php
			AdminUI::render_header(
				I18n::get_label( 'dash_hotel_control' ),
				I18n::get_label( 'dash_hotel_control_desc' ),
				array(),
				array(
					array(
						'label' => I18n::get_label( 'dash_title' ),
						'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
					),
				)
			);
			?>

			<?php
			/* BUILD_PRO_START */
			if ( ! $is_pro_active && ! get_option( 'mhbo_banner_dismissed', 0 ) ) :
				?>
				<div class="mhbo-card accent mhbo-pro-banner">
					<button type="button" class="mhbo-banner-dismiss" onclick="mhboDismissBanner()" title="<?php echo esc_attr( I18n::get_label( 'btn_dismiss' ) ); ?>">&times;</button>
					<div class="mhbo-banner-text">
						<h3><?php echo esc_html( I18n::get_label( 'pro_banner_title' ) ); ?></h3>
						<p><?php echo esc_html( I18n::get_label( 'pro_banner_desc' ) ); ?></p>
					</div>
					<a href="<?php echo esc_url( 'https://startmysuccess.com/modern-hotel-booking-wordpress-plugin/' ); ?>"
						target="_blank" rel="noopener noreferrer" class="button button-primary button-hero mhbo-upgrade-btn">
						<?php echo esc_html( I18n::get_label( 'pro_btn_upgrade' ) ); ?>
					</a>
				</div>
				<?php
			endif;

			if ( $is_pro_active && class_exists( \MHBO\Pro\ICalManager::class ) ) :
				$failing_icals = \MHBO\Pro\ICalManager::get_failing_connections( 3 );
				if ( ! empty( $failing_icals ) ) :
					?>
				<div class="mhbo-alert mhbo-alert-warning" style="background: #fffbeb; border: 1px solid #fde68a; border-left: 5px solid #d97706; padding: 16px 20px; border-radius: 8px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
					<div style="display: flex; gap: 12px; align-items: flex-start;">
						<span style="font-size: 24px; line-height: 1;">⚠️</span>
						<div>
							<h4 style="margin: 0 0 4px 0; color: #92400e; font-size: 15px; font-weight: 700;">
								<?php
								/* translators: %d: number of failing connections */
								echo esc_html( sprintf( _n( 'Channel Manager Alert: %d external OTA connection requires attention', 'Channel Manager Alert: %d external OTA connections require attention', count( $failing_icals ), 'modern-hotel-booking' ), count( $failing_icals ) ) );
								?>
							</h4>
							<p style="margin: 0; color: #b45309; font-size: 13px; line-height: 1.5;">
								<?php esc_html_e( 'The following external calendar feeds have failed 3+ consecutive sync attempts. Please verify or update their feed URLs to prevent double-booking conflicts:', 'modern-hotel-booking' ); ?>
							</p>
							<ul style="margin: 8px 0 0 0; padding-left: 18px; color: #78350f; font-size: 13px;">
								<?php foreach ( array_slice( $failing_icals, 0, 3 ) as $fc ) : ?>
									<li><strong><?php echo esc_html( (string) ( $fc['name'] ?? '' ) ); ?></strong> (<?php echo esc_html( (string) ( $fc['room_name'] ?? '' ) ); ?>) — <em style="color: #dc2626;"><?php echo esc_html( (string) ( $fc['last_error'] ?? '' ) ); ?></em> (
									<?php 
										/* translators: %d: number of failed attempts */
										echo esc_html( sprintf( __( '%d failed attempts', 'modern-hotel-booking' ), $fc['failure_count'] ) ); 
									?>
									)</li>
								<?php endforeach; ?>
								<?php if ( count( $failing_icals ) > 3 ) : ?>
									<li><em>
									<?php 
										/* translators: %d: remaining number of failing connections */
										echo esc_html( sprintf( __( '...and %d more failing connection(s)', 'modern-hotel-booking' ), count( $failing_icals ) - 3 ) ); 
									?>
									</em></li>
								<?php endif; ?>
							</ul>
						</div>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-pro-ical' ) ); ?>" class="button button-primary" style="align-self: center; font-weight: 600; white-space: nowrap;">
						<?php esc_html_e( 'Manage iCal Feeds &rarr;', 'modern-hotel-booking' ); ?>
					</a>
				</div>
					<?php
				endif;
			endif;
			/* BUILD_PRO_END */
			?>

			<!-- Top Hero KPI Matrix (6 Department Metric Cards) -->
			<div class="mhbo-hero-kpi-grid">
				<!-- 1. Live Occupancy -->
				<div class="mhbo-hero-kpi-card accent-emerald">
					<div class="mhbo-hero-kpi-top">
						<span class="mhbo-hero-kpi-title"><?php echo esc_html( I18n::get_label( 'dash_occupancy_today' ) ); ?></span>
						<span class="mhbo-hero-kpi-icon">🏨</span>
					</div>
					<div class="mhbo-hero-kpi-value"><?php echo esc_html( (string) $occupancy_pct ); ?>%</div>
					<div class="mhbo-hero-kpi-subtext">
						<span class="mhbo-pulse-indicator <?php echo $occupancy_pct >= 80 ? 'mhbo-pulse-red' : ( $occupancy_pct >= 40 ? 'mhbo-pulse-amber' : '' ); ?>"></span>
						<?php echo esc_html( sprintf( I18n::get_label( 'dash_rooms_occupied' ), $occupied_rooms, $total_rooms ) ); ?>
					</div>
				</div>

				<!-- 2. Arrivals Today -->
				<div class="mhbo-hero-kpi-card accent-blue">
					<div class="mhbo-hero-kpi-top">
						<span class="mhbo-hero-kpi-title"><?php echo esc_html( I18n::get_label( 'dash_arrivals_today' ) ); ?></span>
						<span class="mhbo-hero-kpi-icon">📥</span>
					</div>
					<div class="mhbo-hero-kpi-value"><?php echo esc_html( (string) count( $arrivals_list ) ); ?></div>
					<div class="mhbo-hero-kpi-subtext">
						<span><?php esc_html_e( 'Scheduled Check-ins', 'modern-hotel-booking' ); ?></span>
					</div>
				</div>

				<!-- 3. Departures Today -->
				<div class="mhbo-hero-kpi-card accent-indigo">
					<div class="mhbo-hero-kpi-top">
						<span class="mhbo-hero-kpi-title"><?php echo esc_html( I18n::get_label( 'dash_departures_today' ) ); ?></span>
						<span class="mhbo-hero-kpi-icon">📤</span>
					</div>
					<div class="mhbo-hero-kpi-value"><?php echo esc_html( (string) count( $departures_list ) ); ?></div>
					<div class="mhbo-hero-kpi-subtext">
						<span><?php esc_html_e( 'Scheduled Check-outs', 'modern-hotel-booking' ); ?></span>
					</div>
				</div>

				<!-- 4. In-House Guests -->
				<div class="mhbo-hero-kpi-card accent-cyan">
					<div class="mhbo-hero-kpi-top">
						<span class="mhbo-hero-kpi-title"><?php echo esc_html( I18n::get_label( 'dash_in_house' ) ); ?></span>
						<span class="mhbo-hero-kpi-icon">🛏️</span>
					</div>
					<div class="mhbo-hero-kpi-value"><?php echo esc_html( (string) count( $inhouse_list ) ); ?></div>
					<div class="mhbo-hero-kpi-subtext">
						<span><?php echo esc_html( I18n::get_label( 'dash_active_stays_tonight' ) ); ?></span>
					</div>
				</div>

				<!-- 5. Action Needed / Pending -->
				<div class="mhbo-hero-kpi-card <?php echo $pending_count > 0 ? 'accent-rose' : 'accent-amber'; ?>">
					<div class="mhbo-hero-kpi-top">
						<span class="mhbo-hero-kpi-title"><?php echo esc_html( I18n::get_label( 'dash_attention' ) ); ?></span>
						<span class="mhbo-hero-kpi-icon">⚠️</span>
					</div>
					<div class="mhbo-hero-kpi-value" style="color: <?php echo $pending_count > 0 ? '#dc2626' : '#0f172a'; ?>;"><?php echo esc_html( (string) $pending_count ); ?></div>
					<div class="mhbo-hero-kpi-subtext">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings&status=pending' ) ); ?>" style="text-decoration:none; color:inherit; font-weight:600;">
							<?php esc_html_e( 'Review Pending &rarr;', 'modern-hotel-booking' ); ?>
						</a>
					</div>
				</div>

				<!-- 6. Total Stay Revenue / Volume -->
				<div class="mhbo-hero-kpi-card accent-emerald">
					<div class="mhbo-hero-kpi-top">
						<span class="mhbo-hero-kpi-title"><?php echo esc_html( I18n::get_label( 'dash_revenue' ) ); ?></span>
						<span class="mhbo-hero-kpi-icon">💰</span>
					</div>
					<div class="mhbo-hero-kpi-value"><?php echo esc_html( I18n::format_currency( $confirmed_revenue ) ); ?></div>
					<div class="mhbo-hero-kpi-subtext">
						<span><?php echo esc_html( sprintf( I18n::get_label( 'dash_confirmed_bookings_desc' ), $confirmed_count, $total_bookings ) ); ?></span>
					</div>
				</div>
			</div>

			<!-- Executive Command Center (Hub Grid Shortcuts across all modules) -->
			<div class="mhbo-card" style="margin-bottom: 24px; padding: 20px;">
				<div style="margin-bottom: 16px;">
					<h3 style="margin: 0 0 4px 0; font-size: 16px; font-weight: 700; color: #0f172a;">
						⚡ <?php echo esc_html( I18n::get_label( 'dash_hub_title' ) ); ?>
					</h3>
					<p style="margin: 0; font-size: 13px; color: #64748b;">
						<?php echo esc_html( I18n::get_label( 'dash_hub_subtitle' ) ); ?>
					</p>
				</div>

				<div class="mhbo-command-grid">
					<!-- 1. New Reservation (Core/Free & Pro) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings&action=add' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#eff6ff; color:#2563eb;">
							<span class="dashicons dashicons-plus-alt2"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_new_booking_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_new_booking_desc' ) ); ?></p>
						</div>
					</a>

					<!-- 2. Master Calendar (Core/Free & Pro) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#ecfdf5; color:#059669;">
							<span class="dashicons dashicons-calendar-alt"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_calendar_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_calendar_desc' ) ); ?></p>
						</div>
					</a>

					<!-- 3. Physical Rooms (Core/Free & Pro) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-rooms' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#f1f5f9; color:#475569;">
							<span class="dashicons dashicons-admin-multisite"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_rooms_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_rooms_desc' ) ); ?></p>
						</div>
					</a>

					<!-- 4. Room Types & Rates (Core/Free & Pro) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-room-types' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#fdf2f8; color:#db2777;">
							<span class="dashicons dashicons-category"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_room_types_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_room_types_desc' ) ); ?></p>
						</div>
					</a>

					<!-- 4.5 Dynamic Pricing Rules (Core/Free & Pro) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-pricing-rules' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#e0e7ff; color:#4f46e5;">
							<span class="dashicons dashicons-tag"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_pricing_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_pricing_desc' ) ); ?></p>
						</div>
					</a>

					<!-- 5. Guest CRM (Core/Free when enabled & Pro) -->
					<?php if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-guests' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#f3e8ff; color:#9333ea;">
							<span class="dashicons dashicons-groups"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_guests_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_guests_desc' ) ); ?></p>
						</div>
					</a>
					<?php endif; ?>

					<!-- 5.5 AI Concierge & Abilities (Core/Free & Pro) -->
					<?php if ( class_exists( \MHBO\AI\Loader::class ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-ai-concierge' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#fdf4ff; color:#c026d3;">
							<span class="dashicons dashicons-format-chat"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_ai_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_ai_desc' ) ); ?></p>
						</div>
					</a>
					<?php endif; ?>

					<?php
					/* BUILD_PRO_START */
					if ( $is_pro_active ) :
						?>
					<!-- 6. Housekeeping Suite (Pro Only) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-housekeeping' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#fef3c7; color:#d97706;">
							<span class="dashicons dashicons-clipboard"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_housekeeping_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_housekeeping_desc' ) ); ?></p>
						</div>
					</a>

					<!-- 7. Advanced Yield Pricing (Pro Only) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-pro-pricing' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#ede9fe; color:#6366f1;">
							<span class="dashicons dashicons-chart-line"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_advanced_pricing_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_advanced_pricing_desc' ) ); ?></p>
						</div>
					</a>

					<!-- 8. Service Extras & Add-ons (Pro Only) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-pro-extras' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#fef2f2; color:#dc2626;">
							<span class="dashicons dashicons-cart"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_extras_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_extras_desc' ) ); ?></p>
						</div>
					</a>

					<!-- 9. Analytics Intelligence (Pro Only) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-pro-analytics' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#f0fdf4; color:#16a34a;">
							<span class="dashicons dashicons-chart-area"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_analytics_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_analytics_desc' ) ); ?></p>
						</div>
					</a>

					<!-- 10. Guest Marketing Campaigns (Pro Only) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-marketing' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#fce7f3; color:#db2777;">
							<span class="dashicons dashicons-email-alt"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_marketing_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_marketing_desc' ) ); ?></p>
						</div>
					</a>

					<!-- 11. iCal Channels (Pro Only) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-pro-ical' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#e0f2fe; color:#0284c7;">
							<span class="dashicons dashicons-update"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_ical_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_ical_desc' ) ); ?></p>
						</div>
					</a>

					<!-- 12. Payment Gateways & Tracking (Pro Only) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-pro-payments' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#ccfbf1; color:#0d9488;">
							<span class="dashicons dashicons-money-alt"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_payments_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_payments_desc' ) ); ?></p>
						</div>
					</a>
						<?php
					endif;
					/* BUILD_PRO_END */
					?>

					<!-- Global Hotel Settings (Core/Free & Pro) -->
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-settings' ) ); ?>" class="mhbo-command-tile">
						<div class="mhbo-command-icon-wrap" style="background:#f8fafc; color:#334155;">
							<span class="dashicons dashicons-admin-generic"></span>
						</div>
						<div class="mhbo-command-content">
							<h4><?php echo esc_html( I18n::get_label( 'dash_hub_settings_title' ) ); ?></h4>
							<p><?php echo esc_html( I18n::get_label( 'dash_hub_settings_desc' ) ); ?></p>
						</div>
					</a>
				</div>
			</div>

			<!-- Two-Column Operations Layout -->
			<div class="mhbo-dashboard-layout">
				<!-- Main Operations Desk Column -->
				<div class="mhbo-main-col">
					<div class="mhbo-ops-board">
						<div class="mhbo-ops-header">
							<div class="mhbo-ops-nav-tabs" role="tablist">
								<button type="button" class="mhbo-ops-tab-btn active" data-tab="arrivals">
									📥 <?php echo esc_html( I18n::get_label( 'dash_tab_arrivals' ) ); ?>
									<span class="mhbo-ops-tab-badge"><?php echo esc_html( (string) count( $arrivals_list ) ); ?></span>
								</button>
								<button type="button" class="mhbo-ops-tab-btn" data-tab="departures">
									📤 <?php echo esc_html( I18n::get_label( 'dash_tab_departures' ) ); ?>
									<span class="mhbo-ops-tab-badge"><?php echo esc_html( (string) count( $departures_list ) ); ?></span>
								</button>
								<button type="button" class="mhbo-ops-tab-btn" data-tab="inhouse">
									🛏️ <?php echo esc_html( I18n::get_label( 'dash_tab_inhouse' ) ); ?>
									<span class="mhbo-ops-tab-badge"><?php echo esc_html( (string) count( $inhouse_list ) ); ?></span>
								</button>
								<button type="button" class="mhbo-ops-tab-btn" data-tab="pending">
									⚠️ <?php echo esc_html( I18n::get_label( 'dash_tab_pending' ) ); ?>
									<span class="mhbo-ops-tab-badge" style="<?php echo $pending_count > 0 ? 'background:#dc2626;color:#fff;' : ''; ?>"><?php echo esc_html( (string) $pending_count ); ?></span>
								</button>
								<button type="button" class="mhbo-ops-tab-btn" data-tab="recent">
									🕒 <?php echo esc_html( I18n::get_label( 'dash_tab_recent' ) ); ?>
									<span class="mhbo-ops-tab-badge"><?php echo esc_html( (string) count( $recent_list ) ); ?></span>
								</button>
							</div>
							<div>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ); ?>" class="button button-secondary button-small">
									<?php echo esc_html( I18n::get_label( 'dash_view_all' ) ); ?> &rarr;
								</a>
							</div>
						</div>

						<!-- TAB 1: Arrivals Today -->
						<div id="mhbo-tab-arrivals" class="mhbo-ops-tab-pane active">
							<?php if ( empty( $arrivals_list ) ) : ?>
								<p style="padding: 30px; text-align: center; color: #64748b; font-style: italic; margin: 0;">
									<?php echo esc_html( I18n::get_label( 'dash_no_arrivals' ) ); ?>
								</p>
							<?php else : ?>
								<table class="mhbo-ops-table">
									<thead>
										<tr>
											<th><?php echo esc_html( I18n::get_label( 'label_guest' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_room_type' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_stay_dates' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_total' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_status' ) ); ?></th>
											<th style="text-align: right;"><?php esc_html_e( 'Action', 'modern-hotel-booking' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php
										foreach ( $arrivals_list as $b ) :
											$guest_initial = ! empty( $b->customer_name ) ? mb_strtoupper( mb_substr( $b->customer_name, 0, 1 ) ) : 'G';
											$bk_edit_url   = wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . (int) $b->id ), 'mhbo_edit_booking_' . (int) $b->id );
											?>
											<tr>
												<td>
													<div class="mhbo-guest-cell">
														<div class="mhbo-guest-avatar"><?php echo esc_html( $guest_initial ); ?></div>
														<div>
															<span class="mhbo-guest-name">
																<a href="<?php echo esc_url( $bk_edit_url ); ?>" style="color: inherit; text-decoration: none; font-weight: 700;">
																	<?php echo esc_html( (string) ( I18n::decode( $b->customer_name ) ?? '' ) ); ?>
																</a>
															</span>
															<span class="mhbo-guest-meta"><?php echo esc_html( $b->customer_email ); ?></span>
														</div>
													</div>
												</td>
												<td>
													<span class="mhbo-room-badge">
														<?php echo esc_html( $b->room_number ? '#' . $b->room_number . ' - ' : '' ); ?>
														<?php echo esc_html( I18n::decode( $b->room_type_name ) ?: 'Room #' . (int) $b->room_id ); ?>
													</span>
												</td>
												<td>
													<strong><?php echo esc_html( date_i18n( 'M j', strtotime( $b->check_in ) ) ); ?></strong> &rarr; <?php echo esc_html( date_i18n( 'M j', strtotime( $b->check_out ) ) ); ?>
												</td>
												<td>
													<strong><?php echo esc_html( I18n::format_currency( $b->total_price ) ); ?></strong>
												</td>
												<td>
													<span class="mhbo-status-badge mhbo-status-<?php echo esc_attr( $b->status ); ?>">
														<?php echo esc_html( I18n::translate_status( $b->status ) ); ?>
													</span>
												</td>
												<td style="text-align: right;">
													<a href="<?php echo esc_url( $bk_edit_url ); ?>" class="button button-small">
														<?php echo esc_html( I18n::get_label( 'dash_action_view' ) ); ?>
													</a>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>

						<!-- TAB 2: Departures Today -->
						<div id="mhbo-tab-departures" class="mhbo-ops-tab-pane">
							<?php if ( empty( $departures_list ) ) : ?>
								<p style="padding: 30px; text-align: center; color: #64748b; font-style: italic; margin: 0;">
									<?php echo esc_html( I18n::get_label( 'dash_no_departures' ) ); ?>
								</p>
							<?php else : ?>
								<table class="mhbo-ops-table">
									<thead>
										<tr>
											<th><?php echo esc_html( I18n::get_label( 'label_guest' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_room_type' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_stay_dates' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_total' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_status' ) ); ?></th>
											<th style="text-align: right;"><?php esc_html_e( 'Action', 'modern-hotel-booking' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php
										foreach ( $departures_list as $b ) :
											$guest_initial = ! empty( $b->customer_name ) ? mb_strtoupper( mb_substr( $b->customer_name, 0, 1 ) ) : 'G';
											$bk_edit_url   = wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . (int) $b->id ), 'mhbo_edit_booking_' . (int) $b->id );
											?>
											<tr>
												<td>
													<div class="mhbo-guest-cell">
														<div class="mhbo-guest-avatar"><?php echo esc_html( $guest_initial ); ?></div>
														<div>
															<span class="mhbo-guest-name">
																<a href="<?php echo esc_url( $bk_edit_url ); ?>" style="color: inherit; text-decoration: none; font-weight: 700;">
																	<?php echo esc_html( (string) ( I18n::decode( $b->customer_name ) ?? '' ) ); ?>
																</a>
															</span>
															<span class="mhbo-guest-meta"><?php echo esc_html( $b->customer_email ); ?></span>
														</div>
													</div>
												</td>
												<td>
													<span class="mhbo-room-badge">
														<?php echo esc_html( $b->room_number ? '#' . $b->room_number . ' - ' : '' ); ?>
														<?php echo esc_html( I18n::decode( $b->room_type_name ) ?: 'Room #' . (int) $b->room_id ); ?>
													</span>
												</td>
												<td>
													<?php echo esc_html( date_i18n( 'M j', strtotime( $b->check_in ) ) ); ?> &rarr; <strong><?php echo esc_html( date_i18n( 'M j', strtotime( $b->check_out ) ) ); ?></strong>
												</td>
												<td>
													<strong><?php echo esc_html( I18n::format_currency( $b->total_price ) ); ?></strong>
												</td>
												<td>
													<span class="mhbo-status-badge mhbo-status-<?php echo esc_attr( $b->status ); ?>">
														<?php echo esc_html( I18n::translate_status( $b->status ) ); ?>
													</span>
												</td>
												<td style="text-align: right;">
													<a href="<?php echo esc_url( $bk_edit_url ); ?>" class="button button-small">
														<?php echo esc_html( I18n::get_label( 'dash_action_view' ) ); ?>
													</a>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>

						<!-- TAB 3: In-House Guests -->
						<div id="mhbo-tab-inhouse" class="mhbo-ops-tab-pane">
							<?php if ( empty( $inhouse_list ) ) : ?>
								<p style="padding: 30px; text-align: center; color: #64748b; font-style: italic; margin: 0;">
									<?php echo esc_html( I18n::get_label( 'dash_no_inhouse' ) ); ?>
								</p>
							<?php else : ?>
								<table class="mhbo-ops-table">
									<thead>
										<tr>
											<th><?php echo esc_html( I18n::get_label( 'label_guest' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_room_type' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_stay_dates' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_total' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_status' ) ); ?></th>
											<th style="text-align: right;"><?php esc_html_e( 'Action', 'modern-hotel-booking' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php
										foreach ( $inhouse_list as $b ) :
											$guest_initial = ! empty( $b->customer_name ) ? mb_strtoupper( mb_substr( $b->customer_name, 0, 1 ) ) : 'G';
											$bk_edit_url   = wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . (int) $b->id ), 'mhbo_edit_booking_' . (int) $b->id );
											?>
											<tr>
												<td>
													<div class="mhbo-guest-cell">
														<div class="mhbo-guest-avatar"><?php echo esc_html( $guest_initial ); ?></div>
														<div>
															<span class="mhbo-guest-name">
																<a href="<?php echo esc_url( $bk_edit_url ); ?>" style="color: inherit; text-decoration: none; font-weight: 700;">
																	<?php echo esc_html( (string) ( I18n::decode( $b->customer_name ) ?? '' ) ); ?>
																</a>
															</span>
															<span class="mhbo-guest-meta"><?php echo esc_html( $b->customer_email ); ?></span>
														</div>
													</div>
												</td>
												<td>
													<span class="mhbo-room-badge">
														<?php echo esc_html( $b->room_number ? '#' . $b->room_number . ' - ' : '' ); ?>
														<?php echo esc_html( I18n::decode( $b->room_type_name ) ?: 'Room #' . (int) $b->room_id ); ?>
													</span>
												</td>
												<td>
													<?php echo esc_html( date_i18n( 'M j', strtotime( $b->check_in ) ) ); ?> &rarr; <?php echo esc_html( date_i18n( 'M j', strtotime( $b->check_out ) ) ); ?>
												</td>
												<td>
													<strong><?php echo esc_html( I18n::format_currency( $b->total_price ) ); ?></strong>
												</td>
												<td>
													<span class="mhbo-status-badge mhbo-status-<?php echo esc_attr( $b->status ); ?>">
														<?php echo esc_html( I18n::translate_status( $b->status ) ); ?>
													</span>
												</td>
												<td style="text-align: right;">
													<a href="<?php echo esc_url( $bk_edit_url ); ?>" class="button button-small">
														<?php echo esc_html( I18n::get_label( 'dash_action_view' ) ); ?>
													</a>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>

						<!-- TAB 4: Pending Approval -->
						<div id="mhbo-tab-pending" class="mhbo-ops-tab-pane">
							<?php if ( empty( $pending_list ) ) : ?>
								<p style="padding: 30px; text-align: center; color: #64748b; font-style: italic; margin: 0;">
									<?php echo esc_html( I18n::get_label( 'dash_no_pending' ) ); ?>
								</p>
							<?php else : ?>
								<table class="mhbo-ops-table">
									<thead>
										<tr>
											<th><?php echo esc_html( I18n::get_label( 'label_guest' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_room_type' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_stay_dates' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_total' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_status' ) ); ?></th>
											<th style="text-align: right;"><?php esc_html_e( 'Action', 'modern-hotel-booking' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php
										foreach ( $pending_list as $b ) :
											$guest_initial  = ! empty( $b->customer_name ) ? mb_strtoupper( mb_substr( $b->customer_name, 0, 1 ) ) : 'G';
											$bk_edit_url    = wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . (int) $b->id ), 'mhbo_edit_booking_' . (int) $b->id );
											$bk_confirm_url = wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=confirm&id=' . (int) $b->id ), 'mhbo_confirm_booking_' . (int) $b->id );
											?>
											<tr>
												<td>
													<div class="mhbo-guest-cell">
														<div class="mhbo-guest-avatar" style="background:#fee2e2; color:#dc2626;"><?php echo esc_html( $guest_initial ); ?></div>
														<div>
															<span class="mhbo-guest-name">
																<a href="<?php echo esc_url( $bk_edit_url ); ?>" style="color: inherit; text-decoration: none; font-weight: 700;">
																	<?php echo esc_html( (string) ( I18n::decode( $b->customer_name ) ?? '' ) ); ?>
																</a>
															</span>
															<span class="mhbo-guest-meta"><?php echo esc_html( $b->customer_email ); ?></span>
														</div>
													</div>
												</td>
												<td>
													<span class="mhbo-room-badge">
														<?php echo esc_html( $b->room_number ? '#' . $b->room_number . ' - ' : '' ); ?>
														<?php echo esc_html( I18n::decode( $b->room_type_name ) ?: 'Room #' . (int) $b->room_id ); ?>
													</span>
												</td>
												<td>
													<?php echo esc_html( date_i18n( 'M j', strtotime( $b->check_in ) ) ); ?> &rarr; <?php echo esc_html( date_i18n( 'M j', strtotime( $b->check_out ) ) ); ?>
												</td>
												<td>
													<strong><?php echo esc_html( I18n::format_currency( $b->total_price ) ); ?></strong>
												</td>
												<td>
													<span class="mhbo-status-badge mhbo-status-pending">
														<?php echo esc_html( I18n::translate_status( $b->status ) ); ?>
													</span>
												</td>
												<td style="text-align: right;">
													<a href="<?php echo esc_url( $bk_confirm_url ); ?>" class="button button-primary button-small" onclick="return confirm('<?php echo esc_js( __( 'Confirm this reservation and send confirmation email to guest?', 'modern-hotel-booking' ) ); ?>');">
														<?php echo esc_html( I18n::get_label( 'dash_action_confirm' ) ); ?>
													</a>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>

						<!-- TAB 5: Recent Activity -->
						<div id="mhbo-tab-recent" class="mhbo-ops-tab-pane">
							<?php if ( empty( $recent_list ) ) : ?>
								<p style="padding: 30px; text-align: center; color: #64748b; font-style: italic; margin: 0;">
									<?php echo esc_html( I18n::get_label( 'dash_no_bookings' ) ); ?>
								</p>
							<?php else : ?>
								<table class="mhbo-ops-table">
									<thead>
										<tr>
											<th><?php echo esc_html( I18n::get_label( 'label_date' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_guest' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_room_type' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_total' ) ); ?></th>
											<th><?php echo esc_html( I18n::get_label( 'label_status' ) ); ?></th>
											<th style="text-align: right;"><?php esc_html_e( 'Action', 'modern-hotel-booking' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php
										foreach ( $recent_list as $b ) :
											$guest_initial = ! empty( $b->customer_name ) ? mb_strtoupper( mb_substr( $b->customer_name, 0, 1 ) ) : 'G';
											$bk_edit_url   = wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . (int) $b->id ), 'mhbo_edit_booking_' . (int) $b->id );
											?>
											<tr>
												<td>
													<span style="font-size: 12px; color: #64748b;">
														<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $b->created_at ) ) ); ?>
													</span>
												</td>
												<td>
													<div class="mhbo-guest-cell">
														<div class="mhbo-guest-avatar"><?php echo esc_html( $guest_initial ); ?></div>
														<div>
															<span class="mhbo-guest-name">
																<a href="<?php echo esc_url( $bk_edit_url ); ?>" style="color: inherit; text-decoration: none; font-weight: 700;">
																	<?php echo esc_html( (string) ( I18n::decode( $b->customer_name ) ?? '' ) ); ?>
																</a>
															</span>
															<span class="mhbo-guest-meta"><?php echo esc_html( $b->customer_email ); ?></span>
														</div>
													</div>
												</td>
												<td>
													<span class="mhbo-room-badge">
														<?php echo esc_html( $b->room_number ? '#' . $b->room_number . ' - ' : '' ); ?>
														<?php echo esc_html( I18n::decode( $b->room_type_name ) ?: 'Room #' . (int) $b->room_id ); ?>
													</span>
												</td>
												<td>
													<strong><?php echo esc_html( I18n::format_currency( $b->total_price ) ); ?></strong>
												</td>
												<td>
													<span class="mhbo-status-badge mhbo-status-<?php echo esc_attr( $b->status ); ?>">
														<?php echo esc_html( I18n::translate_status( $b->status ) ); ?>
													</span>
												</td>
												<td style="text-align: right;">
													<a href="<?php echo esc_url( $bk_edit_url ); ?>" class="button button-small">
														<?php echo esc_html( I18n::get_label( 'dash_action_view' ) ); ?>
													</a>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Sidebar Intelligence Column -->
				<div class="mhbo-side-col">
					<!-- Room Types & Physical Inventory Breakdown -->
					<div class="mhbo-card">
						<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
							<h3 style="margin:0; font-size:14px; font-weight:700;">
								🏨 <?php echo esc_html( I18n::get_label( 'dash_sidebar_inventory' ) ); ?>
							</h3>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-rooms' ) ); ?>" style="font-size:12px; font-weight:600; text-decoration:none;">
								<?php esc_html_e( 'Manage', 'modern-hotel-booking' ); ?> &rarr;
							</a>
						</div>
						<?php if ( empty( $room_types_summary ) ) : ?>
							<p style="font-size:12px; color:#64748b; font-style:italic; margin:0;">
								<?php esc_html_e( 'No room types configured yet.', 'modern-hotel-booking' ); ?>
							</p>
						<?php else : ?>
							<div style="display:flex; flex-direction:column; gap:8px;">
								<?php foreach ( $room_types_summary as $rt ) : ?>
									<div style="display:flex; justify-content:space-between; align-items:center; font-size:12px; padding:6px 8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
										<span style="font-weight:600; color:#0f172a;"><?php echo esc_html( (string) ( I18n::decode( $rt->name ) ?? '' ) ); ?></span>
										<span style="color:#64748b;">
											<strong><?php echo esc_html( (string) $rt->physical_count ); ?></strong> <?php esc_html_e( 'rooms', 'modern-hotel-booking' ); ?>
											<span style="color:#059669; font-weight:600; margin-left:4px;">(<?php echo esc_html( I18n::format_currency( $rt->base_price ) ); ?>/n)</span>
										</span>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

					<!-- Housekeeping Readiness Card -->
					<?php
					/* BUILD_PRO_START */
					if ( $is_pro_active && class_exists( \MHBO\Pro\HousekeepingManifest::class ) ) :
						?>
					<div class="mhbo-card" style="border-left: 4px solid #d97706;">
						<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
							<h3 style="margin:0; font-size:14px; font-weight:700; color:#b45309;">
								🧹 <?php echo esc_html( I18n::get_label( 'dash_sidebar_housekeeping' ) ); ?>
							</h3>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-housekeeping' ) ); ?>" style="font-size:12px; font-weight:600; text-decoration:none; color:#b45309;">
								<?php esc_html_e( 'Suite', 'modern-hotel-booking' ); ?> &rarr;
							</a>
						</div>
						<div style="font-size:12px; line-height:1.8; color:#475569; margin-bottom:12px;">
							<div style="display:flex; justify-content:space-between;">
								<span><?php esc_html_e( 'Turnovers Today:', 'modern-hotel-booking' ); ?></span>
								<strong style="color:#0f172a;"><?php echo esc_html( (string) $turnovers ); ?></strong>
							</div>
							<div style="display:flex; justify-content:space-between;">
								<span><?php esc_html_e( 'Vacant Tonight:', 'modern-hotel-booking' ); ?></span>
								<strong style="color:#059669;"><?php echo esc_html( (string) $vacant_rooms ); ?></strong>
							</div>
						</div>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-housekeeping' ) ); ?>" class="button button-small" style="width:100%; text-align:center;">
							<?php echo esc_html( I18n::get_label( 'dash_sidebar_manifest_btn' ) ); ?>
						</a>
					</div>
						<?php
					endif;
					/* BUILD_PRO_END */
					?>

					<!-- Guest CRM & Directory Snapshot -->
					<?php if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) : ?>
					<div class="mhbo-card">
						<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
							<h3 style="margin:0; font-size:14px; font-weight:700; color:#0f172a;">
								👥 <?php echo esc_html( I18n::get_label( 'dash_sidebar_crm' ) ); ?>
							</h3>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-guests' ) ); ?>" style="font-size:12px; font-weight:600; text-decoration:none;">
								<?php esc_html_e( 'Directory', 'modern-hotel-booking' ); ?> &rarr;
							</a>
						</div>
						<div style="font-size:12px; line-height:1.8; color:#475569;">
							<div style="display:flex; justify-content:space-between;">
								<span><?php esc_html_e( 'Total Profiles:', 'modern-hotel-booking' ); ?></span>
								<strong style="color:#0f172a;"><?php echo esc_html( (string) $total_guests ); ?></strong>
							</div>
							<div style="display:flex; justify-content:space-between;">
								<span><?php esc_html_e( 'VIP Loyal Guests:', 'modern-hotel-booking' ); ?></span>
								<strong style="color:#d97706;">⭐ <?php echo esc_html( (string) $vip_guests ); ?></strong>
							</div>
						</div>
					</div>
					<?php endif; ?>

					<!-- System Status Card -->
					<div class="mhbo-card">
						<h3 style="margin:0 0 10px 0; font-size:14px; font-weight:700; color:#0f172a;">
							⚙️ <?php echo esc_html( I18n::get_label( 'status_title' ) ); ?>
						</h3>
						<div style="font-size: 12px; line-height: 2; color:#475569;">
							<?php /* BUILD_LICENSE_START */ ?>
							<div style="display: flex; justify-content: space-between; align-items: center;">
								<span><?php echo esc_html( I18n::get_label( 'status_license' ) ); ?></span>
								<?php
								$is_licensed  = License::is_active();
								$is_expired   = License::is_expired() || 'expired' === (string) License::get_uncached_option( 'mhbo_license_status', '' );
								$status_text  = $is_licensed ? I18n::get_label( 'status_active' ) : ( $is_expired ? __( 'Expired', 'modern-hotel-booking' ) : I18n::get_label( 'status_unlicensed' ) );
								$status_color = $is_licensed ? '#059669' : '#dc2626';
								?>
								<strong style="color: <?php echo esc_attr( $status_color ); ?>;"><?php echo esc_html( $status_text ); ?></strong>
							</div>
							<div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
								<span><?php esc_html_e( 'License Expiry:', 'modern-hotel-booking' ); ?></span>
								<?php
								$expiry_text   = License::get_expiry_display();
								$needs_renewal = License::needs_renewal();
								$expiring_soon = License::is_expiring_soon();
								$expiry_color  = $needs_renewal ? '#dc2626' : ( $expiring_soon ? '#d97706' : '#0f172a' );
								?>
								<span style="font-weight: 600; color: <?php echo esc_attr( $expiry_color ); ?>; text-align: right; display: flex; align-items: center; gap: 4px;">
									<?php echo esc_html( $expiry_text ); ?>
									<?php if ( $needs_renewal || $expiring_soon ) : ?>
										<a href="<?php echo esc_url( License::get_renewal_url() ); ?>" target="_blank" rel="noopener noreferrer" style="color: #2563eb; font-weight: 700; text-decoration: underline;">
											<?php esc_html_e( 'Renew →', 'modern-hotel-booking' ); ?>
										</a>
									<?php endif; ?>
								</span>
							</div>
							<?php /* BUILD_LICENSE_END */ ?>
							<?php /* BUILD_FREE_START */ ?>
							<div style="display: flex; justify-content: space-between;">
								<span class="mhbo-free-edition-row"><?php echo esc_html( I18n::get_label( 'status_edition' ) ); ?></span>
								<strong class="mhbo-free-edition-row" style="color: #2563eb;"><?php echo esc_html( I18n::get_label( 'status_free' ) ); ?></strong>
							</div>
							<?php /* BUILD_FREE_END */ ?>
							<div style="display: flex; justify-content: space-between;">
								<span><?php echo esc_html( I18n::get_label( 'status_version' ) ); ?></span>
								<strong>v<?php echo esc_html( MHBO_VERSION ); ?></strong>
							</div>
							<div style="display: flex; justify-content: space-between;">
								<span><?php echo esc_html( I18n::get_label( 'status_db' ) ); ?></span>
								<strong style="color: #059669;"><?php echo esc_html( I18n::get_label( 'status_healthy' ) ); ?></strong>
							</div>
						</div>
					</div>

					<!-- Changelog / Version Updates -->
					<?php
					$changelog_items = array();
					$latest_version  = MHBO_VERSION;
					$readme_file     = MHBO_PLUGIN_DIR . 'readme.txt';

					if ( file_exists( $readme_file ) ) {
						$readme_content = file_get_contents( $readme_file );
						if ( $readme_content && preg_match( '/==\s*Changelog\s*==(.*?)($|==)/s', $readme_content, $matches ) ) {
							$changelog_section = $matches[1];
							if ( preg_match( '/^\s*=\s*([0-9\.]+.*?)\s*=(.*?)(?:\n\s*=\s*[0-9]|$)/s', $changelog_section, $version_matches ) ) {
								$latest_version = trim( $version_matches[1] );
								$version_notes  = trim( $version_matches[2] );
								$lines          = explode( "\n", $version_notes );
								foreach ( $lines as $line ) {
									$line = trim( $line );
									if ( strpos( $line, '*' ) === 0 || strpos( $line, '-' ) === 0 ) {
										$changelog_items[] = trim( substr( $line, 1 ) );
									}
								}
							}
						}
					}
					?>
					<div class="mhbo-card" style="border-left: 4px solid #10b981;">
						<h3 style="color: #10b981; margin-top: 0; margin-bottom: 8px; font-size: 14px; font-weight:700;">
							<?php
							// translators: %s: current plugin version
							echo esc_html( sprintf( I18n::get_label( 'label_version_updates' ), $latest_version ) );
							?>
						</h3>
						<?php if ( count( $changelog_items ) > 0 ) : ?>
							<ul style="margin: 0 0 10px 16px; padding: 0; font-size: 12px; color: #64748b; line-height: 1.5;">
								<?php foreach ( array_slice( $changelog_items, 0, 5 ) as $item ) : ?>
									<li style="margin-bottom: 4px;">
									<?php
									$item_clean = trim( $item );
									if ( preg_match( '/^([^:]+:)(.*)$/', $item_clean, $parts ) ) {
										echo '<strong>' . esc_html( $parts[1] ) . '</strong>' . esc_html( $parts[2] );
									} else {
										echo esc_html( $item_clean );
									}
									?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<div>
							<a href="https://github.com/leslieradue-web/modern-hotel-booking-free" target="_blank"
								rel="noopener noreferrer"
								style="font-size: 12px; color: #10b981; text-decoration: none; font-weight: 700;">
								<?php echo esc_html( I18n::get_label( 'label_view_changelog' ) ); ?> &rarr;
							</a>
						</div>
					</div>

					<!-- Assistance & Support -->
					<div class="mhbo-card" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04); overflow: hidden; padding: 18px 20px;">
						<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
							<div style="width: 32px; height: 32px; border-radius: 8px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 16px;">
								<span class="dashicons dashicons-sos" style="font-size: 18px; width: 18px; height: 18px;"></span>
							</div>
							<h3 style="margin: 0; font-size: 14.5px; font-weight: 700; color: #0f172a;">
								<?php echo esc_html( I18n::get_label( 'pro_need_assistance' ) ); ?>
							</h3>
						</div>
						<p style="font-size: 12.5px; color: #64748b; margin: 0 0 14px 0; line-height: 1.5;">
							<?php echo esc_html( I18n::get_label( 'pro_assistance_desc' ) ); ?>
						</p>

						<!-- Official Support Email -->
						<div style="background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
							<div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #334155; font-weight: 600;">
								<span class="dashicons dashicons-email" style="font-size: 15px; width: 15px; height: 15px; color: #2563eb;"></span>
								<a href="mailto:contact@startmysuccess.com" style="color: #1e293b; text-decoration: none;">contact@startmysuccess.com</a>
							</div>
							<span style="font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">
								<?php esc_html_e( 'Official Support', 'modern-hotel-booking' ); ?>
							</span>
						</div>

						<!-- Official Documentation & Manual -->
						<div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; display: flex; flex-direction: column; gap: 8px;">
							<a href="<?php echo esc_url( 'https://startmysuccess.com/wp-content/uploads/modern-hotel-booking/modern-hotel-booking-wordpress-plugin-user-guide/' ); ?>"
								target="_blank" rel="noopener noreferrer"
								style="font-size: 12px; color: #0f172a; text-decoration: none; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
								<span style="display: flex; align-items: center; gap: 6px;">
									<span class="dashicons dashicons-book" style="font-size: 15px; width: 15px; height: 15px; color: #2563eb;"></span>
									<?php esc_html_e( 'Official User Manual', 'modern-hotel-booking' ); ?>
								</span>
								<span style="color: #64748b; font-size: 11px;">PDF &rarr;</span>
							</a>
							<div style="height: 1px; background: #f1f5f9;"></div>
							<a href="<?php echo esc_url( 'https://modernhotelwp.com/docs/' ); ?>"
								target="_blank" rel="noopener noreferrer"
								style="font-size: 12px; color: #0f172a; text-decoration: none; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
								<span style="display: flex; align-items: center; gap: 6px;">
									<span class="dashicons dashicons-media-document" style="font-size: 15px; width: 15px; height: 15px; color: #10b981;"></span>
									<?php esc_html_e( 'Documentation & FAQs', 'modern-hotel-booking' ); ?>
								</span>
								<span style="color: #64748b; font-size: 11px;">Web &rarr;</span>
							</a>
						</div>

						<div style="display: flex; flex-direction: column; gap: 8px;">
							<?php if ( ! $is_pro_active ) : ?>
								<a href="<?php echo esc_url( 'https://startmysuccess.com/modern-hotel-booking-wordpress-plugin/' ); ?>"
									target="_blank" rel="noopener noreferrer" class="button button-primary"
									style="display: flex; align-items: center; justify-content: center; gap: 6px; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); border-color: #4338ca; color: #ffffff; font-weight: 600; font-size: 12.5px; padding: 6px 14px; text-decoration: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(79, 70, 229, 0.2);">
									<span>⚡ <?php echo esc_html( I18n::get_label( 'pro_get_version' ) ); ?></span>
									<span class="dashicons dashicons-arrow-right-alt" style="font-size: 14px; width: 14px; height: 14px;"></span>
								</a>
							<?php endif; ?>

							<div style="display: flex; justify-content: space-between; align-items: center; padding-top: 4px;">
								<a href="<?php echo esc_url( 'https://github.com/leslieradue-web/modern-hotel-booking-free/issues' ); ?>"
									target="_blank" rel="noopener noreferrer"
									style="font-size: 12px; color: #64748b; text-decoration: none; font-weight: 600; display: flex; align-items: center; gap: 4px;">
									<span class="dashicons dashicons-editor-help" style="font-size: 14px; width: 14px; height: 14px;"></span>
									<?php echo esc_html( I18n::get_label( 'pro_report_issues' ) ); ?>
								</a>
								<a href="<?php echo esc_url( 'https://startmysuccess.com/modern-hotel-booking-wordpress-plugin/' ); ?>"
									target="_blank" rel="noopener noreferrer"
									style="font-size: 12px; color: #2563eb; text-decoration: none; font-weight: 600;">
									<?php esc_html_e( 'Guides', 'modern-hotel-booking' ); ?> &rarr;
								</a>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			const tabButtons = document.querySelectorAll('.mhbo-ops-tab-btn');
			const tabPanes = document.querySelectorAll('.mhbo-ops-tab-pane');

			tabButtons.forEach(function(btn) {
				btn.addEventListener('click', function() {
					const tabId = btn.getAttribute('data-tab');

					tabButtons.forEach(function(b) { b.classList.remove('active'); });
					tabPanes.forEach(function(p) { p.classList.remove('active'); });

					btn.classList.add('active');
					const targetPane = document.getElementById('mhbo-tab-' + tabId);
					if (targetPane) {
						targetPane.classList.add('active');
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Renders the React DataViews headless container.
	 */
	public function display_dataviews_page(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_GUESTS ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}
		
		?>
		<div class="wrap mhbo-admin-wrap">
			<?php
			AdminUI::render_header(
				__( 'Guest CRM & Profiles', 'modern-hotel-booking' ),
				__( 'Centralize guest profiles, lifetime stay history, staff ratings, and customer intelligence.', 'modern-hotel-booking' ),
				array(
					array(
						'label' => __( 'General Settings', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-settings&tab=general' ),
						'class' => 'button-secondary',
					),
				),
				array(
					array(
						'label' => I18n::get_label( 'menu_main' ),
						'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
					),
				)
			);
			?>
			<div id="mhbo-dataviews-root"></div>
		</div>
		<?php
	}

	public function display_bookings_page(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		global $wpdb;
		$tb = $wpdb->prefix . 'mhbo_bookings';

		// Self-heal: ensure pending bookings with verified full payment progress to confirmed (Law 46)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: Using %i for safe table placeholder.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s WHERE status = %s AND (payment_received = 1 OR payment_status = %s)',
				$tb,
				'confirmed',
				'pending',
				'completed'
			)
		);

		$is_pro_active = false;
		/* BUILD_PRO_START */
		$is_pro_active = License::is_active();
		/* BUILD_PRO_END */

		$edit_mode = false;
		$add_mode  = false;
		$edit_data = null;
		$currency  = ( class_exists( Pricing::class ) ) ? Pricing::get_currency_code() : 'USD';

		// Rule 11: Extract and sanitize all inputs at start
		$action        = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id            = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$nonce         = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		// GET Actions
		if ( $action ) {
			if ( 'add' === $action ) {
				$add_mode = true;
			} elseif ( 'sync_crm_guests' === $action ) {
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_sync_crm_guests' ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
				}
				if ( class_exists( GuestMigration::class ) ) {
					$migrated_result = GuestMigration::migrate_batch( 0, 1000 );
					$migrated_count  = (int) ( $migrated_result['processed'] ?? 0 );
					/* translators: %d: number of synced reservations */
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Successfully synced %d historical reservation(s) into your Guest CRM.', 'modern-hotel-booking' ), $migrated_count ) ) . '</p></div>';
				}
				/* BUILD_PRO_START */
			} elseif ( 'email_housekeeping_manifest' === $action ) {
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_housekeeping_manifest' ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
				}
				if ( class_exists( \MHBO\Pro\HousekeepingManifest::class ) ) {
					$target_date = isset( $_GET['manifest_date'] ) ? sanitize_text_field( wp_unslash( $_GET['manifest_date'] ) ) : HotelTime::today();
					$sent        = \MHBO\Pro\HousekeepingManifest::send_manifest_email( $target_date );
					if ( $sent ) {
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Daily Housekeeping Manifest has been emailed successfully.', 'modern-hotel-booking' ) . '</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to email Housekeeping Manifest. Please check your notification email configuration in Settings.', 'modern-hotel-booking' ) . '</p></div>';
					}
				}
				/* BUILD_PRO_END */
			} elseif ( $id > 0 ) {
				if ( 'edit' === $action ) {
					if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) && ( ! $nonce || ( ! wp_verify_nonce( $nonce, 'mhbo_edit_booking_' . $id ) && ! wp_verify_nonce( $nonce, 'mhbo_booking_action' ) ) ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
					$edit_mode = true;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$edit_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $id ) );
				} elseif ( 'confirm' === $action ) {
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_confirm_booking_' . $id ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
					$wpdb->update( $tb, array( 'status' => 'confirmed' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
					Cache::invalidate_booking( $id );
					Email::send_email( $id, 'confirmed' );
					do_action( 'mhbo_booking_confirmed', $id );
					do_action( 'mhbo_booking_status_changed', $id, 'confirmed' );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_booking_confirmed_email' ) ) . '</p></div>';
				} elseif ( 'cancel' === $action ) {
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_cancel_booking_' . $id ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
					$wpdb->update( $tb, array( 'status' => 'cancelled' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
					Cache::invalidate_booking( $id );
					Email::send_email( $id, 'cancelled' );
					do_action( 'mhbo_booking_cancelled', $id );
					do_action( 'mhbo_booking_status_changed', $id, 'cancelled' );
					echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_booking_cancelled' ) ) . '</p></div>';
				} elseif ( 'delete' === $action ) {
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_delete_booking_' . $id ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
					$wpdb->delete( $tb, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
					Cache::invalidate_booking( $id );
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_booking_deleted' ) ) . '</p></div>';
					/* BUILD_PRO_START */
				} elseif ( 'email_invoice' === $action ) {
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_invoice_' . $id ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
					$sent = Invoice::email( $id );
					if ( $sent ) {
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_invoice_sent' ) ) . '</p></div>';
					} else {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_invoice_fail' ) ) . '</p></div>';
					}
				} elseif ( 'email_balance_link' === $action ) {
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_balance_' . $id ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
					if ( class_exists( \MHBO\Pro\BalancePayment::class ) ) {
						$sent = \MHBO\Pro\BalancePayment::send_balance_link_email( $id, true );
						if ( $sent ) {
							echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_balance_link_sent' ) ) . '</p></div>';
						} else {
							echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_balance_link_fail' ) ) . '</p></div>';
						}
					} else {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Balance payments are not available.', 'modern-hotel-booking' ) . '</p></div>';
					}
				} elseif ( 'revoke_balance_link' === $action ) {
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_balance_' . $id ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
					if ( class_exists( \MHBO\Pro\BalancePayment::class ) ) {
						\MHBO\Pro\BalancePayment::revoke_balance_link( $id );
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Balance link revoked.', 'modern-hotel-booking' ) . '</p></div>';
					}
				} elseif ( 'regenerate_balance_link' === $action ) {
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_balance_' . $id ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
					if ( class_exists( \MHBO\Pro\BalancePayment::class ) ) {
						\MHBO\Pro\BalancePayment::regenerate_magic_url( $id, true );
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Balance payment link has been regenerated successfully.', 'modern-hotel-booking' ) . '</p></div>';
					}
				} elseif ( 'schedule_balance_link' === $action ) {
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_balance_' . $id ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_unslash below
					$rule = sanitize_key( (string) ( wp_unslash( $_GET['rule'] ?? '' ) ) );
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_unslash below
					$custom_lead = absint( wp_unslash( $_GET['custom_lead_days'] ?? 0 ) );
					if ( '' !== $rule && class_exists( \MHBO\Pro\BalancePayment::class ) ) {
						\MHBO\Pro\BalancePayment::schedule_balance_link( $id, $rule, $custom_lead );
						echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Balance link scheduled.', 'modern-hotel-booking' ) . '</p></div>';
					}
					/* BUILD_PRO_END */
				}
				if ( isset( $_GET['return'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['return'] ) ) ) {
					$edit_mode = true;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$edit_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $id ) );
				}
			}
		}

		// Handle bulk actions (Mass Delete)
		if ( isset( $_POST['bulk_action'] ) || ( isset( $_POST['action'] ) && 'bulk_delete' === sanitize_key( wp_unslash( $_POST['action'] ) ) ) ) {
			$posted_bulk_action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? ( $_POST['action'] ?? '' ) ) );
			if ( 'bulk_delete' === $posted_bulk_action ) {
				if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
				}
				if ( ! check_admin_referer( 'mhbo_bulk_bookings_action', 'mhbo_bulk_nonce' ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
				}

                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$raw_booking_ids = isset( $_POST['booking_ids'] ) && is_array( $_POST['booking_ids'] ) ? (array) wp_unslash( $_POST['booking_ids'] ) : array();
				$booking_ids     = array_values( array_filter( array_map( 'absint', $raw_booking_ids ) ) );

				if ( empty( $booking_ids ) ) {
					echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_no_bookings_selected' ) ) . '</p></div>';
				} else {
					$in_clause = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );
					// Fetch room_ids and guest_ids for proper cache invalidation and CRM synchronization
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in_clause is a deterministic '%d' token string via array_fill.
					$select_sql = $wpdb->prepare( 'SELECT id, room_id, guest_id FROM ' . $wpdb->prefix . 'mhbo_bookings WHERE id IN (' . $in_clause . ')', ...$booking_ids );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					$deleted_rows_meta = $wpdb->get_results( $select_sql );

					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in_clause is a deterministic '%d' token string via array_fill.
					$delete_sql = $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'mhbo_bookings WHERE id IN (' . $in_clause . ')', ...$booking_ids );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					$deleted_count = (int) $wpdb->query( $delete_sql );

					$affected_guest_ids = array();
					if ( is_array( $deleted_rows_meta ) ) {
						foreach ( $deleted_rows_meta as $row_meta ) {
							$bid = (int) $row_meta->id;
							$rid = (int) ( $row_meta->room_id ?? 0 );
							Cache::invalidate_booking( $bid, $rid );
							do_action( 'mhbo_booking_deleted', $bid );
							if ( ! empty( $row_meta->guest_id ) ) {
								$affected_guest_ids[] = (int) $row_meta->guest_id;
							}
						}
					} else {
						Cache::invalidate_booking();
					}

					if ( ! empty( $affected_guest_ids ) && class_exists( GuestAsyncTasks::class ) ) {
						foreach ( array_unique( $affected_guest_ids ) as $gid ) {
							if ( $gid > 0 ) {
								GuestAsyncTasks::queue_stats_recalculation( $gid );
							}
						}
					}

					// Invalidate dashboard statistics transients
					$today_date = wp_date( 'Y-m-d' );
					delete_transient( 'mhbo_widget_total_bookings' );
					delete_transient( 'mhbo_widget_pending_bookings' );
					delete_transient( 'mhbo_widget_today_bookings_' . $today_date );
					delete_transient( 'mhbo_dashboard_total_bookings' );
					delete_transient( 'mhbo_dashboard_pending_bookings' );
					delete_transient( 'mhbo_dashboard_stats_' . $today_date );

					/* translators: %d: number of deleted bookings */
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( I18n::get_label( 'msg_bulk_bookings_deleted' ), (int) $deleted_count ) ) . '</p></div>';
				}
			}
		}

		// Shared map for extras logic
		$available_extras = get_option( 'mhbo_pro_extras', array() );
		$extras_map       = array();
		foreach ( $available_extras as $ex ) {
			$extras_map[ $ex['id'] ] = $ex;
		}

		// Handle manual booking submission
		if ( isset( $_POST['submit_manual_booking'] ) ) {
			if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
			}
			if ( ! check_admin_referer( 'mhbo_add_manual_booking' ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}

			// Rule 11: Extract and sanitize manual booking inputs
			$customer_name    = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
			$customer_email   = sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) );
			$customer_phone   = sanitize_text_field( wp_unslash( $_POST['customer_phone'] ?? '' ) );
			$room_id          = absint( wp_unslash( $_POST['room_id'] ?? 0 ) );
			$check_in         = sanitize_text_field( wp_unslash( $_POST['check_in'] ?? '' ) );
			$check_out        = sanitize_text_field( wp_unslash( $_POST['check_out'] ?? '' ) );
			$guests           = absint( wp_unslash( $_POST['guests'] ?? 1 ) );
			$children_count   = absint( wp_unslash( $_POST['children'] ?? 0 ) );
			$child_ages       = isset( $_POST['child_ages'] ) && is_array( $_POST['child_ages'] ) ? array_map( 'intval', wp_unslash( $_POST['child_ages'] ) ) : array();
			$total_price      = floatval( wp_unslash( $_POST['total_price'] ?? 0 ) );
			$discount_amount  = floatval( wp_unslash( $_POST['discount_amount'] ?? 0 ) );
			$coupon_code      = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );
			$deposit_amount   = floatval( wp_unslash( $_POST['deposit_amount'] ?? 0 ) );
			$deposit_received = ( isset( $_POST['deposit_received'] ) && sanitize_text_field( wp_unslash( $_POST['deposit_received'] ) ) === '1' ) ? 1 : 0;
			$payment_received = ( isset( $_POST['payment_received'] ) && sanitize_text_field( wp_unslash( $_POST['payment_received'] ) ) === '1' );
			$post_status      = sanitize_key( wp_unslash( $_POST['status'] ?? 'pending' ) );
			$admin_notes      = sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) );
			$booking_language = sanitize_key( wp_unslash( $_POST['booking_language'] ?? 'en' ) );
			$payment_method   = sanitize_key( wp_unslash( $_POST['payment_method'] ?? 'arrival' ) );
			$posted_tax_mode  = sanitize_key( wp_unslash( $_POST['tax_mode'] ?? '' ) );
			$mhbo_custom      = isset( $_POST['mhbo_custom'] ) && is_array( $_POST['mhbo_custom'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_custom'] ) ) : array();
			$mhbo_extras_raw  = isset( $_POST['mhbo_extras'] ) && is_array( $_POST['mhbo_extras'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_extras'] ) ) : array();

			// Prepare extras for BookingProcessor (simple id => qty map)
			$post_extras = array();
			foreach ( $mhbo_extras_raw as $ex_id => $val ) {
				$qty = ( isset( $extras_map[ $ex_id ] ) && $extras_map[ $ex_id ]['control_type'] === 'quantity' ) ? absint( $val ) : ( $val === '1' ? 1 : 0 );
				if ( $qty > 0 ) {
					$post_extras[ $ex_id ] = $qty;
				}
			}

			$result = BookingProcessor::process(
				array(
					'customer_name'    => $customer_name,
					'customer_email'   => $customer_email,
					'customer_phone'   => $customer_phone,
					'room_id'          => $room_id,
					'check_in'         => $check_in,
					'check_out'        => $check_out,
					'guests'           => $guests,
					'children'         => $children_count,
					'child_ages'       => $child_ages,
					'total_price'      => $total_price,
					'status'           => $post_status,
					'payment_method'   => $payment_method,
					'payment_received' => $payment_received ? 1 : 0,
					'payment_status'   => $payment_received ? 'completed' : 'pending',
					'source'           => 'manual',
					'admin_notes'      => $admin_notes . "\n" . I18n::get_label( 'booking_msg_manual_admin' ),
					'custom_fields'    => $mhbo_custom,
					'extras'           => $post_extras,
					'language'         => $booking_language,
					'bypass_past'      => true, // Admin can book past dates
					'tax_mode'         => $posted_tax_mode,
					'consent'          => 1,
					'mhbo_consent'     => 1,
				)
			);

			if ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
				$add_mode = true;
			} else {
				$new_id = (int) $result['booking_id'];
				
				// Manual overrides not handled by processor but present in Admin UI
				$submitted_pay_raw = isset( $_POST['payment_amount'] ) && '' !== $_POST['payment_amount'] ? sanitize_text_field( (string) wp_unslash( $_POST['payment_amount'] ) ) : '';
				$calc              = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, $guests, $post_extras, $children_count, $child_ages, $posted_tax_mode );
				$tax_data          = $calc['tax'] ?? null;
				$curr_code         = ( is_array( $calc ) && isset( $calc['total'] ) && $calc['total'] instanceof Money ) ? $calc['total']->getCurrency() : Pricing::get_currency_code();

				$total_m         = Money::fromDecimal( (string) $total_price, $curr_code );
				$deposit_m       = Money::fromDecimal( (string) $deposit_amount, $curr_code );
				$submitted_pay_m = Money::fromDecimal( $submitted_pay_raw, $curr_code );

				if ( $payment_received && ! $submitted_pay_m->isPositive() ) {
					$submitted_pay_m = $total_m;
				} elseif ( $deposit_received && ! $submitted_pay_m->isPositive() ) {
					$submitted_pay_m = $deposit_m;
				}

				$eff_disc_m = Money::fromDecimal( (string) $discount_amount, $curr_code );
				/* BUILD_PRO_START */
				if ( ! empty( $coupon_code ) && class_exists( \MHBO\Pro\CouponManager::class ) ) {
					$coupon_obj = \MHBO\Pro\CouponManager::get_by_code( $coupon_code );
					if ( $coupon_obj ) {
						if ( false === get_transient( 'mhbo_coupon_inc_' . $new_id ) ) {
							\MHBO\Pro\CouponManager::increment_uses( (int) $coupon_obj->id );
							set_transient( 'mhbo_coupon_inc_' . $new_id, 1, DAY_IN_SECONDS );
						}
						if ( ! $eff_disc_m->isPositive() && is_array( $calc ) && isset( $calc['total'] ) && $calc['total'] instanceof Money ) {
							$elig_base  = \MHBO\Pro\CouponManager::resolve_eligible_base( $calc, $curr_code );
							$eff_disc_m = \MHBO\Pro\CouponManager::calculate_discount( $coupon_obj, $calc['total'], $elig_base );
						}
					}
				}
				/* BUILD_PRO_END */

				/* BUILD_STARTER_START */
				if ( $eff_disc_m->isPositive() && is_array( $calc ) && isset( $calc['total'] ) ) {
					$manual_recalc       = Tax::recalculate_with_coupon( $calc, $eff_disc_m, strtoupper( (string) $coupon_code ), $curr_code );
					$tax_data            = $manual_recalc['tax'];
					$calc['service_fee'] = $manual_recalc['service_fee'];
					$calc['total']       = $manual_recalc['total'];
				}
				/* BUILD_STARTER_END */

				$rem_balance_m         = $total_m->subtract( $submitted_pay_m );
				if ( $rem_balance_m->isNegative() ) {
					$rem_balance_m = Money::fromCents( 0, $curr_code );
				}

				$update_data = array(
					'discount_amount'   => $eff_disc_m->toDecimal(),
					'deposit_amount'    => $deposit_m->toDecimal(),
					'deposit_received'  => $deposit_received,
					'payment_amount'    => $submitted_pay_m->toDecimal(),
					'remaining_balance' => $rem_balance_m->toDecimal(),
				);

				/* BUILD_PRO_START */
				if ( ! empty( $coupon_code ) ) {
					$update_data['coupon_code']     = strtoupper( $coupon_code );
					$update_data['coupon_discount'] = $eff_disc_m->toDecimal();
				}
				if ( is_array( $calc ) && isset( $calc['service_fee'] ) && $calc['service_fee'] instanceof Money ) {
					$update_data['service_fee_amount'] = $calc['service_fee']->toDecimal();
					$update_data['service_fee_net']    = ( isset( $tax_data['totals']['service_fee_net'] ) && $tax_data['totals']['service_fee_net'] instanceof Money ) ? $tax_data['totals']['service_fee_net']->toDecimal() : $calc['service_fee']->toDecimal();
					$update_data['service_fee_tax']    = ( isset( $tax_data['totals']['service_fee_tax'] ) && $tax_data['totals']['service_fee_tax'] instanceof Money ) ? $tax_data['totals']['service_fee_tax']->toDecimal() : '0.00';
				}
				/* BUILD_PRO_END */

				// Link CRM guest ID if provided
				$posted_guest_id = isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0;
				if ( $posted_guest_id > 0 ) {
					$update_data['guest_id'] = $posted_guest_id;
				}

				// Tax breakdown persistence
				if ( $tax_data && ! empty( $tax_data['enabled'] ) ) {
					$update_data['tax_enabled']            = 1;
					$update_data['tax_mode']               = $tax_data['mode'] ?? 'disabled';
					$update_data['tax_rate_accommodation'] = $tax_data['rates']['accommodation'] ?? 0;
					$update_data['tax_rate_extras']        = $tax_data['rates']['extras'] ?? 0;
					$update_data['room_total_net']         = ( isset( $tax_data['totals']['room_net'] ) && $tax_data['totals']['room_net'] instanceof Money ) ? $tax_data['totals']['room_net']->toDecimal() : '0.00';
					$update_data['room_tax']               = ( isset( $tax_data['totals']['room_tax'] ) && $tax_data['totals']['room_tax'] instanceof Money ) ? $tax_data['totals']['room_tax']->toDecimal() : '0.00';
					$update_data['children_total_net']     = ( isset( $tax_data['totals']['children_net'] ) && $tax_data['totals']['children_net'] instanceof Money ) ? $tax_data['totals']['children_net']->toDecimal() : '0.00';
					$update_data['children_tax']           = ( isset( $tax_data['totals']['children_tax'] ) && $tax_data['totals']['children_tax'] instanceof Money ) ? $tax_data['totals']['children_tax']->toDecimal() : '0.00';
					$update_data['extras_total_net']       = ( isset( $tax_data['totals']['extras_net'] ) && $tax_data['totals']['extras_net'] instanceof Money ) ? $tax_data['totals']['extras_net']->toDecimal() : '0.00';
					$update_data['extras_tax']             = ( isset( $tax_data['totals']['extras_tax'] ) && $tax_data['totals']['extras_tax'] instanceof Money ) ? $tax_data['totals']['extras_tax']->toDecimal() : '0.00';
					$update_data['subtotal_net']           = ( isset( $tax_data['totals']['subtotal_net'] ) && $tax_data['totals']['subtotal_net'] instanceof Money ) ? $tax_data['totals']['subtotal_net']->toDecimal() : $total_m->toDecimal();
					$update_data['tax_breakdown']          = wp_json_encode( $tax_data );
					$update_data['total_gross']            = $total_m->toDecimal();
					$update_data['total_tax']              = ( isset( $tax_data['totals']['total_tax'] ) && $tax_data['totals']['total_tax'] instanceof Money ) ? $tax_data['totals']['total_tax']->toDecimal() : '0.00';
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write, admin-only; booking cache invalidated via do_action below.
				$wpdb->update( $tb, $update_data, array( 'id' => $new_id ) );

				// Law 59: Record verified initial payment/deposit to Folio Payment Ledger
				if ( $submitted_pay_m->isPositive() && class_exists( Pricing::class ) ) {
					$current_user = wp_get_current_user();
					$staff_label  = ( $current_user && $current_user->exists() ) ? $current_user->display_name : 'Staff';
					$pay_type     = ( $payment_received || $submitted_pay_m->compare( $total_m ) >= 0 ) ? 'balance' : 'deposit';
					$pay_memo     = ( 'deposit' === $pay_type ) ? __( 'Initial deposit recorded upon reservation creation', 'modern-hotel-booking' ) : __( 'Initial full payment recorded upon reservation creation', 'modern-hotel-booking' );
					Pricing::record_payment(
						$new_id,
						$submitted_pay_m->toDecimal(),
						$payment_method,
						$pay_type,
						'',
						$pay_memo,
						'Staff: ' . $staff_label
					);
				}

				Cache::invalidate_booking( $new_id );
				do_action( 'mhbo_booking_created', $new_id );
				if ( 'confirmed' === $post_status ) {
					do_action( 'mhbo_booking_confirmed', $new_id );
				}
				$view_url = wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . $new_id ), 'mhbo_edit_booking_' . $new_id );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_manual_booking_added' ) ) . ' <a href="' . esc_url( $view_url ) . '" class="button button-small" style="margin-left:8px;">' . esc_html__( 'Open Reservation Workstation →', 'modern-hotel-booking' ) . '</a></p></div>';
				$add_mode = false;
			}
		}

				// Handle folio ledger transaction submission (Cashier Tender Workstation)
		if ( isset( $_POST['submit_record_folio_payment'] ) ) {
			if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
			}
			$record_bid = absint( wp_unslash( $_POST['record_payment_booking_id'] ?? 0 ) );
			if ( ! check_admin_referer( 'mhbo_record_payment_' . $record_bid, 'mhbo_record_pay_nonce' ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}

			// If submitted from booking edit workstation, persist staged changes (discount, total price, etc.) prior to recording transaction
			if ( isset( $_POST['booking_id'] ) && absint( $_POST['booking_id'] ) === $record_bid && ! empty( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'mhbo_update_booking' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$pre_booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $record_bid ), OBJECT );
				if ( $pre_booking ) {
					$curr_code         = class_exists( Pricing::class ) ? Pricing::get_currency_code() : 'USD';
					$staged_discount_m = Money::fromDecimal( sanitize_text_field( (string) wp_unslash( $_POST['discount_amount'] ?? '0' ) ), $curr_code );
					$staged_total_m    = Money::fromDecimal( sanitize_text_field( (string) wp_unslash( $_POST['total_price'] ?? '0' ) ), $curr_code );
					$old_discount_m    = Money::fromDecimal( (string) ( $pre_booking->discount_amount ?? '0' ), $curr_code );
					$old_total_m       = Money::fromDecimal( (string) ( $pre_booking->total_price ?? '0' ), $curr_code );
					$snap_raw          = isset( $_POST['total_price_snapshot'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['total_price_snapshot'] ) ) : (string) ( $pre_booking->total_price ?? '0' );
					$snapshot_total_m  = Money::fromDecimal( $snap_raw, $curr_code );
					$total_touched     = ! $staged_total_m->equals( $snapshot_total_m );
					$staged_notes      = sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) );
					$staged_cname      = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
					$staged_cemail     = sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) );
					$staged_cphone     = sanitize_text_field( wp_unslash( $_POST['customer_phone'] ?? '' ) );
					$staged_updates    = array();

					/* BUILD_PRO_START */
					$staged_coupon = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );
					$old_coupon    = (string) ( $pre_booking->coupon_code ?? '' );
					if ( '' !== $staged_coupon && $staged_coupon !== $old_coupon ) {
						$staged_updates['coupon_code'] = strtoupper( $staged_coupon );
						if ( class_exists( \MHBO\Pro\CouponManager::class ) ) {
							$coupon_obj = \MHBO\Pro\CouponManager::get_by_code( $staged_coupon );
							if ( $coupon_obj ) {
								if ( ! $staged_discount_m->isPositive() ) {
									$calc_disc_m = \MHBO\Pro\CouponManager::calculate_discount( $coupon_obj, $old_total_m );
									if ( $calc_disc_m->isPositive() ) {
										$staged_discount_m                 = $calc_disc_m;
										$staged_updates['coupon_discount'] = $calc_disc_m->toDecimal();
									}
								} else {
									$staged_updates['coupon_discount'] = $staged_discount_m->toDecimal();
								}
							}
						}
					} elseif ( '' === $staged_coupon && '' !== $old_coupon ) {
						$staged_updates['coupon_code']     = '';
						$staged_updates['coupon_discount'] = '0.00';
					}
					/* BUILD_PRO_END */

					if ( ! $staged_discount_m->equals( $old_discount_m ) ) {
						$staged_updates['discount_amount'] = $staged_discount_m->toDecimal();
					}

					if ( $total_touched && $staged_total_m->isPositive() && ! $staged_total_m->equals( $old_total_m ) ) {
						$staged_updates['total_price'] = $staged_total_m->toDecimal();
					} elseif ( ! $staged_discount_m->equals( $old_discount_m ) ) {
						$disc_diff_m = $staged_discount_m->subtract( $old_discount_m );
						$new_total_m = $old_total_m->subtract( $disc_diff_m );
						if ( $new_total_m->isNegative() ) {
							$new_total_m = Money::fromCents( 0, $curr_code );
						}
						$staged_updates['total_price'] = $new_total_m->toDecimal();
					}

					if ( '' !== $staged_notes && $staged_notes !== ( $pre_booking->admin_notes ?? '' ) ) {
						$staged_updates['admin_notes'] = $staged_notes;
					}
					if ( '' !== $staged_cname && $staged_cname !== ( $pre_booking->customer_name ?? '' ) ) {
						$staged_updates['customer_name'] = $staged_cname;
					}
					if ( '' !== $staged_cemail && $staged_cemail !== ( $pre_booking->customer_email ?? '' ) ) {
						$staged_updates['customer_email'] = $staged_cemail;
					}
					if ( '' !== $staged_cphone && $staged_cphone !== ( $pre_booking->customer_phone ?? '' ) ) {
						$staged_updates['customer_phone'] = $staged_cphone;
					}

					if ( ! empty( $staged_updates ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update(
							$wpdb->prefix . 'mhbo_bookings',
							$staged_updates,
							array( 'id' => $record_bid )
						);
						Cache::invalidate_booking( $record_bid );
					}
				}
			}

			$tender_method = sanitize_key( wp_unslash( $_POST['record_payment_method'] ?? 'arrival' ) );
			$tender_type   = sanitize_key( wp_unslash( $_POST['record_payment_type'] ?? 'balance' ) );
			$tender_note   = sanitize_text_field( wp_unslash( $_POST['record_payment_note'] ?? '' ) );
			$tender_txn_id = sanitize_text_field( wp_unslash( $_POST['record_payment_txn_id'] ?? '' ) );
			$tender_cur    = Pricing::get_currency_code();
			$tender_raw    = sanitize_text_field( wp_unslash( $_POST['record_payment_amount'] ?? '0' ) );
			$tender_money  = Money::fromDecimal( $tender_raw, $tender_cur );
			$send_email    = ! empty( $_POST['record_payment_send_email'] );
			if ( $record_bid > 0 && ! $tender_money->isZero() ) {
				$current_user = wp_get_current_user();
				$staff_label  = ( $current_user && $current_user->exists() ) ? $current_user->display_name : 'Staff';
				$recorded     = Pricing::record_payment(
					$record_bid,
					$tender_money->toDecimal(),
					$tender_method,
					$tender_type,
					$tender_txn_id,
					$tender_note,
					'Staff: ' . $staff_label
				);
				if ( $recorded ) {
					// Immediately reload $edit_data so workstation reflects fresh financial numbers
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$reloaded = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $record_bid ) );
					if ( $reloaded ) {
						$edit_data = $reloaded;
						$edit_mode = true;
					}

					// Dispatch financial receipt and admin notification under Law 58
					$email_dispatched = false;
					if ( $send_email && class_exists( Email::class ) ) {
						$payment_payload = array(
							'amount'         => floatval( $tender_money->abs()->toDecimal() ),
							'transaction_id' => $tender_txn_id,
							'method'         => $tender_method,
							'note'           => $tender_note,
							'type'           => $tender_type,
							'staff'          => 'Staff: ' . $staff_label,
						);

						$reloaded_rem_m = ( $reloaded && isset( $reloaded->remaining_balance ) ) ? Money::fromDecimal( (string) $reloaded->remaining_balance, $tender_cur ) : null;
						if ( 'refund' === $tender_type || $tender_money->isNegative() ) {
							$guest_ok         = Email::send_payment_confirmation_email( $record_bid, $payment_payload, 'refund' );
							$admin_ok         = Email::send_admin_payment_notification( $record_bid, $payment_payload );
							$email_dispatched = ( $guest_ok || $admin_ok );
						} else {
							$guest_ok = false;
							/* BUILD_PRO_START */
							if ( \MHBO\Core\Tier::has_feature( 'deposits' ) && method_exists( Email::class, 'send_balance_confirmed_email' ) && ( 'balance' === $tender_type || ( $reloaded_rem_m && ! $reloaded_rem_m->isPositive() ) ) ) {
								$guest_ok = Email::send_balance_confirmed_email( $record_bid, $tender_txn_id, $tender_money->abs()->toDecimal() );
							}
							/* BUILD_PRO_END */
							if ( ! $guest_ok ) {
								$guest_ok = Email::send_payment_confirmation_email( $record_bid, $payment_payload, $tender_type );
							}
							$admin_ok         = Email::send_admin_payment_notification( $record_bid, $payment_payload );
							$email_dispatched = ( $guest_ok || $admin_ok );
						}
					}

					if ( 'refund' === $tender_type || $tender_money->isNegative() ) {
						/* translators: %s: formatted refund amount */
						$msg = sprintf( __( 'Folio refund of %s recorded successfully.', 'modern-hotel-booking' ), I18n::format_currency( $tender_money->abs() ) );
					} else {
						/* translators: %s: formatted payment amount */
						$msg = sprintf( __( 'Folio payment of %s recorded successfully.', 'modern-hotel-booking' ), I18n::format_currency( $tender_money ) );
					}
					if ( $email_dispatched ) {
						$msg .= ' ' . __( 'Receipt sent to guest & notification to admin.', 'modern-hotel-booking' );
					}
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to record folio transaction. Please try again.', 'modern-hotel-booking' ) . '</p></div>';
				}
			}
		}

		// Handle edit submission
		if ( isset( $_POST['submit_booking_update'] ) ) {
			if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
			}
			if ( ! check_admin_referer( 'mhbo_update_booking' ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}

			// Rule 11: Extract and sanitize update booking inputs
			$booking_id       = absint( wp_unslash( $_POST['booking_id'] ?? 0 ) );
			$new_status       = sanitize_key( wp_unslash( $_POST['status'] ?? 'pending' ) );
			$room_id          = absint( wp_unslash( $_POST['room_id'] ?? 0 ) );
			$check_in         = sanitize_text_field( wp_unslash( $_POST['check_in'] ?? '' ) );
			$check_out        = sanitize_text_field( wp_unslash( $_POST['check_out'] ?? '' ) );
			$guests           = absint( wp_unslash( $_POST['guests'] ?? 1 ) );
			$children_count   = absint( wp_unslash( $_POST['children'] ?? 0 ) );
			$child_ages          = isset( $_POST['child_ages'] ) && is_array( $_POST['child_ages'] ) ? array_map( 'intval', wp_unslash( $_POST['child_ages'] ) ) : array();
			$payment_received    = ( isset( $_POST['payment_received'] ) && sanitize_text_field( wp_unslash( $_POST['payment_received'] ) ) === '1' ) ? 1 : 0;
			$deposit_received    = ( isset( $_POST['deposit_received'] ) && sanitize_text_field( wp_unslash( $_POST['deposit_received'] ) ) === '1' ) ? 1 : 0;
			$deposit_amount_edit = floatval( wp_unslash( $_POST['deposit_amount'] ?? 0 ) );
			$payment_status      = sanitize_key( wp_unslash( $_POST['payment_status'] ?? 'pending' ) );
			$total_price_edit = floatval( wp_unslash( $_POST['total_price'] ?? 0 ) );
			$discount_amount  = floatval( wp_unslash( $_POST['discount_amount'] ?? 0 ) );
			$coupon_code      = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );
			$customer_name    = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
			$customer_email   = sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) );
			$customer_phone   = sanitize_text_field( wp_unslash( $_POST['customer_phone'] ?? '' ) );
			$admin_notes      = sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) );
			$booking_language = sanitize_key( wp_unslash( $_POST['booking_language'] ?? 'en' ) );
			$payment_method   = sanitize_key( wp_unslash( $_POST['payment_method'] ?? 'arrival' ) );
			$posted_tax_mode  = isset( $_POST['tax_mode'] ) ? sanitize_key( wp_unslash( $_POST['tax_mode'] ) ) : null;
			$mhbo_custom      = isset( $_POST['mhbo_custom'] ) && is_array( $_POST['mhbo_custom'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_custom'] ) ) : array();
			$mhbo_extras_raw  = isset( $_POST['mhbo_extras'] ) && is_array( $_POST['mhbo_extras'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_extras'] ) ) : array();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$old_booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $booking_id ), OBJECT );
			if ( ! $old_booking ) {
				wp_die( esc_html( I18n::get_label( 'msg_booking_not_found' ) ) );
			}

			$old_status            = (string) ( $old_booking->status ?? '' );
			$was_payment_received  = ! empty( $old_booking->payment_received );
			$existing_payment_date = $old_booking->payment_date ?? null;

			// Guest CRM Stay Rating & Link
			$guest_rating    = isset( $_POST['guest_rating'] ) ? min( 5, max( 0, absint( wp_unslash( $_POST['guest_rating'] ) ) ) ) : 0;
			$posted_guest_id = isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0;
			$target_guest_id = $posted_guest_id > 0 ? $posted_guest_id : absint( $old_booking->guest_id ?? 0 );

			if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) {
				if ( $target_guest_id <= 0 && ! empty( $customer_email ) ) {
					$target_guest_id = GuestIdentityResolver::resolve_and_link_guest(
						array(
							'customer_name'  => $customer_name,
							'customer_email' => $customer_email,
							'customer_phone' => $customer_phone,
						)
					);
				}
			}

			$available_extras = (array) get_option( 'mhbo_pro_extras', array() );
			$extras_map       = array();
			foreach ( $available_extras as $ex ) {
				if ( isset( $ex['id'] ) ) {
					$extras_map[ (string) $ex['id'] ]                 = $ex;
					$extras_map[ sanitize_key( (string) $ex['id'] ) ] = $ex;
				}
			}

			// Format extras for Pricing calculation (id => qty)
			$post_extras = array();
			foreach ( $mhbo_extras_raw as $ex_id => $val ) {
				$clean_ex_id = sanitize_key( (string) $ex_id );
				$extra       = $extras_map[ $clean_ex_id ] ?? ( $extras_map[ (string) $ex_id ] ?? null );
				$qty         = 0;
				if ( $extra && ( $extra['control_type'] ?? 'checkbox' ) === 'quantity' ) {
					$qty = absint( $val );
				} elseif ( '1' === (string) $val || true === $val || ( is_numeric( $val ) && (int) $val > 0 ) ) {
					$qty = max( 1, absint( $val ) );
				}
				if ( $qty > 0 ) {
					$post_extras[ $clean_ex_id ] = $qty;
				}
			}

			// Ensure stay extensions are synced to $post_extras and $mhbo_custom
			if ( ! empty( $post_extras['_early_checkin'] ) ) {
				$mhbo_custom['_early_checkin'] = 1;
			} else {
				unset( $mhbo_custom['_early_checkin'] );
			}
			if ( ! empty( $post_extras['_late_checkout'] ) ) {
				$mhbo_custom['_late_checkout'] = 1;
			} else {
				unset( $mhbo_custom['_late_checkout'] );
			}

			$calc     = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, $guests, $post_extras, $children_count, $child_ages, $posted_tax_mode );
			$tax_data = $calc['tax'] ?? null;

			// Construct canonical serializable booking_extras map from pricing breakdown
			$booking_extras = array();
			if ( ! empty( $calc['extras_breakdown'] ) && is_array( $calc['extras_breakdown'] ) ) {
				foreach ( $calc['extras_breakdown'] as $eb_id => $eb_item ) {
					$booking_extras[ (string) $eb_id ] = array(
						'id'           => (string) ( $eb_item['id'] ?? $eb_id ),
						'name'         => (string) ( $eb_item['name'] ?? '' ),
						'price'        => $eb_item['price'] instanceof Money ? $eb_item['price']->toDecimal() : (string) ( $eb_item['price'] ?? '0' ),
						'quantity'     => (int) ( $eb_item['quantity'] ?? 1 ),
						'multiplier'   => (int) ( $eb_item['multiplier'] ?? 1 ),
						'pricing_type' => (string) ( $eb_item['pricing_type'] ?? 'fixed' ),
						'total'        => $eb_item['total'] instanceof Money ? $eb_item['total']->toDecimal() : (string) ( $eb_item['total'] ?? '0' ),
						'compulsory'   => (int) ( $eb_item['compulsory'] ?? 0 ),
						'tax_class'    => (string) ( $eb_item['tax_class'] ?? '' ),
					);
				}
			}

			// Re-apply existing coupon so the service fee is recalculated on the discounted base.
			$edit_coupon_code   = sanitize_text_field( (string) ( $coupon_code ?? '' ) );
			$curr_code          = ( isset( $calc['total'] ) && $calc['total'] instanceof Money ) ? $calc['total']->getCurrency() : 'USD';
			$edit_coupon_disc_m = Money::fromDecimal( (string) $discount_amount, $curr_code );
			/* BUILD_PRO_START */
			if ( '' !== $edit_coupon_code && ! $edit_coupon_disc_m->isPositive() && class_exists( \MHBO\Pro\CouponManager::class ) && isset( $calc['total'] ) && $calc['total'] instanceof Money ) {
				$coupon_obj = \MHBO\Pro\CouponManager::get_by_code( $edit_coupon_code );
				if ( $coupon_obj ) {
					$elig_base_edit     = \MHBO\Pro\CouponManager::resolve_eligible_base( $calc, $curr_code );
					$edit_coupon_disc_m = \MHBO\Pro\CouponManager::calculate_discount( $coupon_obj, $calc['total'], $elig_base_edit );
					$discount_amount    = $edit_coupon_disc_m->toDecimal();
				}
			}
			/* BUILD_PRO_END */
			/* BUILD_STARTER_START */
			if ( $edit_coupon_disc_m->isPositive() && $calc && isset( $calc['total'] ) ) {
				$edit_recalc         = Tax::recalculate_with_coupon( $calc, $edit_coupon_disc_m, $edit_coupon_code, $curr_code );
				$tax_data            = $edit_recalc['tax'];
				$calc['service_fee'] = $edit_recalc['service_fee'];
				$calc['total']       = $edit_recalc['total'];
			}
			/* BUILD_STARTER_END */

			$calc_curr       = $curr_code;
			$total_money_val = Money::fromDecimal( (string) $total_price_edit, $calc_curr );

			if ( isset( $calc['total'] ) && $calc['total'] instanceof Money && $calc['total']->isPositive() ) {
				$calc_total_m      = $calc['total'];
				$submitted_total_m = $total_money_val;
				$snap_raw          = isset( $_POST['total_price_snapshot'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['total_price_snapshot'] ) ) : (string) ( $old_booking->total_price ?? '0' );
				$snapshot_total_m  = Money::fromDecimal( $snap_raw, $calc_curr );
				$field_was_touched = ! $submitted_total_m->equals( $snapshot_total_m );

				if ( ! $field_was_touched && ! $calc_total_m->equals( $submitted_total_m ) ) {
					$total_price_edit = $calc_total_m->toDecimal();
					$total_money_val  = $calc_total_m;
				}
			}

			// Calculate paid amount and remaining balance (Law 34: Zero-Float Money math)
			$old_amts   = class_exists( Pricing::class ) ? Pricing::get_booking_payment_amounts( $old_booking ) : null;
			$old_pay_m  = ( null !== $old_amts && isset( $old_amts['received'] ) ) ? $old_amts['received'] : Money::fromDecimal( (string) ( $old_booking->payment_amount ?? '0' ), $calc_curr );
			$dep_edit_m = Money::fromDecimal( (string) $deposit_amount_edit, $calc_curr );

			if ( isset( $_POST['payment_amount'] ) && '' !== $_POST['payment_amount'] ) {
				$final_pay_m = Money::fromDecimal( sanitize_text_field( (string) wp_unslash( $_POST['payment_amount'] ) ), $calc_curr );
			} elseif ( 0 !== $payment_received && ! $old_pay_m->isPositive() ) {
				$final_pay_m = $total_money_val;
			} elseif ( 0 !== $deposit_received && ! $old_pay_m->isPositive() ) {
				$final_pay_m = $dep_edit_m;
			} else {
				$final_pay_m = $old_pay_m;
			}

			// Continuous Billing & Post-Settlement Logic (Law 59: Pure Money comparisons)
			if ( $final_pay_m->compare( $total_money_val ) >= 0 ) {
				// Paid in full or overpaid
				$remaining_balance_str = '0.00';
				$payment_received      = 1;
				if ( 'pending' === $payment_status || 'deposit_paid' === $payment_status ) {
					$payment_status = 'completed';
				}
				if ( 'pending' === $new_status ) {
					$new_status = 'confirmed';
				}
			} else {
				// Extension or added charges: outstanding balance exists
				$rem_diff              = $total_money_val->subtract( $final_pay_m );
				$remaining_balance_str = $rem_diff->isPositive() ? $rem_diff->toDecimal() : '0.00';
				$payment_received      = 0;
				if ( 'completed' === $payment_status ) {
					$payment_status = $final_pay_m->isPositive() ? 'deposit_paid' : 'pending';
				}
			}

			// If manual payment amount input changed compared to DB, record audit adjustment to folio ledger
			$pay_diff_m = $final_pay_m->subtract( $old_pay_m );
			if ( ! $pay_diff_m->isZero() && class_exists( Pricing::class ) ) {
				$current_user = wp_get_current_user();
				$staff_label  = ( $current_user && $current_user->exists() ) ? $current_user->display_name : 'Staff';
				if ( $pay_diff_m->isPositive() ) {
					Pricing::record_payment(
						$booking_id,
						$pay_diff_m->toDecimal(),
						$payment_method,
						'balance',
						'',
						'Manual Payment Adjustment',
						'Staff: ' . $staff_label
					);
				} else {
					Pricing::record_payment(
						$booking_id,
						$pay_diff_m->abs()->toDecimal(),
						$payment_method,
						'refund',
						'',
						'Manual Refund / Adjustment',
						'Staff: ' . $staff_label
					);
				}
			}

			// Availability Check (excluding current booking).
			$available = Pricing::is_room_available( $room_id, $check_in, $check_out, $booking_id );
			if ( true !== $available ) {
				$err_msg = is_string( $available ) ? I18n::get_label( $available ) : I18n::get_label( 'error_room_unavailable' );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err_msg ) . '</p></div>';
				$edit_mode = true;
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating booking record in custom table.
				$wpdb->update(
					$tb,
					array(
						'customer_name'          => $customer_name,
						'customer_email'         => $customer_email,
						'customer_phone'         => $customer_phone,
						'guest_id'               => $target_guest_id > 0 ? $target_guest_id : null,
						'guest_rating'           => $guest_rating,
						'room_id'                => $room_id,
						'check_in'               => $check_in,
						'check_out'              => $check_out,
						'total_price'            => $total_money_val->toDecimal(),
						'remaining_balance'      => $remaining_balance_str,
						'discount_amount'        => $edit_coupon_disc_m->toDecimal(),
						'deposit_amount'         => $dep_edit_m->toDecimal(),
						'deposit_received'       => ( isset( $_POST['deposit_received'] ) && sanitize_text_field( wp_unslash( $_POST['deposit_received'] ) ) === '1' ) ? 1 : 0,
						'payment_method'         => $payment_method,
						'payment_status'         => 0 !== $payment_received ? 'completed' : $payment_status,
						'payment_received'       => $payment_received,
						'payment_amount'         => $final_pay_m->toDecimal(),
						'payment_date'           => ( 0 !== $payment_received && ! $was_payment_received ) ? HotelTime::now_mysql() : $existing_payment_date,
						'status'                 => $new_status,
						'booking_language'       => $booking_language,
						'admin_notes'            => $admin_notes,
						'booking_extras'         => ! empty( $booking_extras ) ? wp_json_encode( $booking_extras ) : null,
						'guests'                 => $guests,
						/* BUILD_STARTER_START */
						'children'               => $children_count,
						'children_ages'          => ! empty( $child_ages ) ? wp_json_encode( $child_ages ) : null,
						/* BUILD_STARTER_END */
						/* BUILD_PRO_START */
						'coupon_code'            => $coupon_code ?? '',
						'coupon_discount'        => ( '' !== ( $coupon_code ?? '' ) ) ? $discount_amount : 0,
						/* BUILD_PRO_END */
						'custom_fields'          => ! empty( $mhbo_custom ) ? wp_json_encode( $mhbo_custom ) : null,
						'tax_enabled'            => ( $tax_data && $tax_data['enabled'] ) ? 1 : 0,
						'tax_mode'               => ( null !== $posted_tax_mode && '' !== $posted_tax_mode ) ? $posted_tax_mode : ( $tax_data['mode'] ?? 'disabled' ),
						'tax_rate_accommodation' => $tax_data['rates']['accommodation'] ?? 0,
						'tax_rate_extras'        => $tax_data['rates']['extras'] ?? 0,
						'room_total_net'         => ( isset( $tax_data['totals']['room_net'] ) && $tax_data['totals']['room_net'] instanceof Money ) ? $tax_data['totals']['room_net']->toDecimal() : 0,
						'room_tax'               => ( isset( $tax_data['totals']['room_tax'] ) && $tax_data['totals']['room_tax'] instanceof Money ) ? $tax_data['totals']['room_tax']->toDecimal() : 0,
						'children_total_net'     => ( isset( $tax_data['totals']['children_net'] ) && $tax_data['totals']['children_net'] instanceof Money ) ? $tax_data['totals']['children_net']->toDecimal() : 0,
						'children_tax'           => ( isset( $tax_data['totals']['children_tax'] ) && $tax_data['totals']['children_tax'] instanceof Money ) ? $tax_data['totals']['children_tax']->toDecimal() : 0,
						'extras_total_net'       => ( isset( $tax_data['totals']['extras_net'] ) && $tax_data['totals']['extras_net'] instanceof Money ) ? $tax_data['totals']['extras_net']->toDecimal() : 0,
						'extras_tax'             => ( isset( $tax_data['totals']['extras_tax'] ) && $tax_data['totals']['extras_tax'] instanceof Money ) ? $tax_data['totals']['extras_tax']->toDecimal() : 0,
						'service_fee_amount'     => ( isset( $calc['service_fee'] ) && $calc['service_fee'] instanceof Money && $calc['service_fee']->isPositive() ) ? $calc['service_fee']->toDecimal() : '0.00',
						'service_fee_net'        => ( isset( $tax_data['totals']['service_fee_net'] ) && $tax_data['totals']['service_fee_net'] instanceof Money ) ? $tax_data['totals']['service_fee_net']->toDecimal() : '0.00',
						'service_fee_tax'        => ( isset( $tax_data['totals']['service_fee_tax'] ) && $tax_data['totals']['service_fee_tax'] instanceof Money ) ? $tax_data['totals']['service_fee_tax']->toDecimal() : '0.00',
						'subtotal_net'           => ( isset( $tax_data['totals']['subtotal_net'] ) && $tax_data['totals']['subtotal_net'] instanceof Money ) ? $tax_data['totals']['subtotal_net']->toDecimal() : $total_price_edit,
						'total_tax'              => ( isset( $tax_data['totals']['total_tax'] ) && $tax_data['totals']['total_tax'] instanceof Money ) ? $tax_data['totals']['total_tax']->toDecimal() : 0,
						'total_gross'            => ( isset( $tax_data['totals']['total_gross'] ) && $tax_data['totals']['total_gross'] instanceof Money ) ? $tax_data['totals']['total_gross']->toDecimal() : $total_price_edit,
						'tax_breakdown'          => $tax_data ? wp_json_encode( $tax_data ) : null,
					),
					array( 'id' => $booking_id )
				);

				if ( $target_guest_id > 0 && class_exists( GuestAsyncTasks::class ) ) {
					GuestAsyncTasks::queue_stats_recalculation( $target_guest_id );
				}

				/* BUILD_PRO_START */
				if ( class_exists( \MHBO\Pro\BalancePayment::class ) && $old_booking ) {
					$old_total          = (string) ( $old_booking->total_price ?? '0' );
					$old_deposit        = (string) ( $old_booking->deposit_amount ?? '0' );
					$old_payment_amount = (string) ( $old_booking->payment_amount ?? '0' );
					$new_deposit_m      = Money::fromDecimal( sanitize_text_field( (string) wp_unslash( $_POST['deposit_amount'] ?? '0' ) ), $calc_curr );
					$old_paid_m         = Money::fromDecimal( $old_payment_amount, $calc_curr );

					if ( $total_money_val->toDecimal() !== $old_total || $new_deposit_m->toDecimal() !== $old_deposit || ! $final_pay_m->equals( $old_paid_m ) ) {
						\MHBO\Pro\BalancePayment::invalidate_balance_payment_attempt( $booking_id );

						if ( $total_money_val->subtract( $final_pay_m )->isPositive() ) {
							\MHBO\Pro\BalancePayment::reopen_balance( $booking_id, $total_money_val->toDecimal(), $final_pay_m->toDecimal() );
						}
					}
				}
				/* BUILD_PRO_END */

				if ( $old_status !== $new_status ) {
					// Send email notification when status changes
					do_action( 'mhbo_booking_status_changed', $booking_id, $new_status );
					if ( 'confirmed' === $new_status ) {
						do_action( 'mhbo_booking_confirmed', $booking_id );
					} elseif ( 'cancelled' === $new_status ) {
						do_action( 'mhbo_booking_cancelled', $booking_id );
					}
				}

				Cache::invalidate_booking( $booking_id, $room_id );

				// Invalidate dashboard statistics transients
				$today_date = wp_date( 'Y-m-d' );
				delete_transient( 'mhbo_widget_total_bookings' );
				delete_transient( 'mhbo_widget_pending_bookings' );
				delete_transient( 'mhbo_widget_today_bookings_' . $today_date );
				delete_transient( 'mhbo_dashboard_total_bookings' );
				delete_transient( 'mhbo_dashboard_pending_bookings' );
				delete_transient( 'mhbo_dashboard_stats_' . $today_date );

				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_booking_updated' ) ) . '</p></div>';
				$edit_mode = false;
			}
		}

		$status_filter     = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search_query      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$allowed_sort_cols = array( 'id', 'guest', 'room', 'dates', 'total', 'status' );
		$orderby           = isset( $_GET['orderby'] ) && in_array( strtolower( sanitize_key( wp_unslash( $_GET['orderby'] ) ) ), $allowed_sort_cols, true )
			? strtolower( sanitize_key( wp_unslash( $_GET['orderby'] ) ) )
			: 'id';
		$order             = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) )
			? 'ASC'
			: 'DESC';

		// Pagination settings: 10, 25, 50, 100, -1 (all)
		$allowed_per_page = array( 10, 25, 50, 100, -1 );
		$current_user_id  = get_current_user_id();
		$saved_per_page   = (int) get_user_meta( $current_user_id, 'mhbo_bookings_per_page', true );
		$default_per_page = in_array( $saved_per_page, $allowed_per_page, true ) ? $saved_per_page : 25;

		if ( isset( $_GET['per_page'] ) ) {
			$raw_per_page = sanitize_text_field( wp_unslash( $_GET['per_page'] ) );
			if ( 'all' === strtolower( $raw_per_page ) || '-1' === $raw_per_page ) {
				$per_page = -1;
			} else {
				$parsed   = absint( $raw_per_page );
				$per_page = in_array( $parsed, array( 10, 25, 50, 100 ), true ) ? $parsed : $default_per_page;
			}
			if ( $current_user_id > 0 ) {
				update_user_meta( $current_user_id, 'mhbo_bookings_per_page', $per_page );
			}
		} else {
			$per_page = $default_per_page;
		}

		$total_bookings = Booking_Query::count( $status_filter, $search_query );
		$total_pages    = ( $per_page > 0 ) ? max( 1, (int) ceil( $total_bookings / $per_page ) ) : 1;
		$paged          = isset( $_GET['paged'] ) ? max( 1, min( absint( wp_unslash( $_GET['paged'] ) ), $total_pages ) ) : 1;
		$offset         = ( $per_page > 0 ) ? ( $paged - 1 ) * $per_page : 0;
		$limit          = ( $per_page > 0 ) ? $per_page : -1;

		// Table bookings (paginated, with status and search filters)
		$bookings = Booking_Query::get_list( $status_filter, $limit, $offset, $orderby, $order, $search_query );

		// Calendar bookings (comprehensive, up to 500 active/upcoming reservations)
		$calendar_bookings = Booking_Query::get_list( '', 500, 0, 'dates', 'ASC' );

		$base_url_args = array( 'page' => 'mhbo-bookings' );
		if ( $status_filter && 'all' !== $status_filter ) {
			$base_url_args['status'] = $status_filter;
		}
		if ( '' !== $search_query ) {
			$base_url_args['s'] = $search_query;
		}
		if ( $orderby !== 'id' ) {
			$base_url_args['orderby'] = $orderby;
		}
		if ( $order !== 'DESC' ) {
			$base_url_args['order'] = strtolower( $order );
		}
		if ( $per_page !== 25 ) {
			$base_url_args['per_page'] = ( $per_page <= 0 ) ? 'all' : $per_page;
		}
		$bookings_base_url = add_query_arg( $base_url_args, admin_url( 'admin.php' ) );

		$all_rooms = wp_cache_get( 'mhbo_all_rooms', 'mhbo_rooms' );
		if ( false === $all_rooms ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$all_rooms = $wpdb->get_results( "SELECT r.id, r.room_number, t.name as type_name, t.base_price FROM {$wpdb->prefix}mhbo_rooms r JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id ORDER BY (r.room_number + 0) ASC, r.room_number ASC, r.id ASC" );
			if ( is_array( $all_rooms ) ) {
				usort(
					$all_rooms,
					function ( $a, $b ) {
						$cmp = strnatcasecmp( (string) $a->room_number, (string) $b->room_number );
						if ( 0 !== $cmp ) {
							return $cmp;
						}
						return ( (int) $a->id ) <=> ( (int) $b->id );
					}
				);
			}
			wp_cache_set( 'mhbo_all_rooms', $all_rooms, 'mhbo_rooms', HOUR_IN_SECONDS );
		}

		$events               = array();
		$seen_calendar_events = array();
		$occupancy            = array();
		$distinct_rooms       = array();
		$prevent_turnover     = (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1;

		if ( is_array( $all_rooms ) ) {
			foreach ( $all_rooms as $r ) {
				if ( isset( $r->room_number ) && $r->room_number !== '' ) {
					$distinct_rooms[ (string) $r->room_number ] = ! empty( $r->type_name ) ? I18n::decode( $r->type_name ) : __( 'General Rooms', 'modern-hotel-booking' );
				}
			}
		}

		$is_crm_active = class_exists( GuestManager::class ) && GuestManager::is_enabled();

		if ( $is_crm_active && ! empty( $calendar_bookings ) ) {
			$mismatched_emails = array();
			foreach ( $calendar_bookings as $b ) {
				$c_email = strtolower( trim( (string) ( $b->customer_email ?? '' ) ) );
				if ( '' === $c_email ) {
					continue;
				}
				$g_email = strtolower( trim( (string) ( $b->guest_email ?? '' ) ) );
				if ( empty( $b->guest_id ) || ( '' !== $g_email && $c_email !== $g_email ) ) {
					$mismatched_emails[ $c_email ] = true;
				}
			}

			if ( ! empty( $mismatched_emails ) ) {
				$matched_guests = GuestManager::get_active_guests_by_emails( array_keys( $mismatched_emails ) );

				$email_to_guest = array();
				if ( ! empty( $matched_guests ) ) {
					foreach ( $matched_guests as $mg ) {
						$e1 = strtolower( trim( (string) $mg->email_normalized ) );
						$e2 = strtolower( trim( (string) $mg->email ) );
						if ( '' !== $e1 && ! isset( $email_to_guest[ $e1 ] ) ) {
							$email_to_guest[ $e1 ] = $mg;
						}
						if ( '' !== $e2 && ! isset( $email_to_guest[ $e2 ] ) ) {
							$email_to_guest[ $e2 ] = $mg;
						}
					}
				}

				foreach ( $calendar_bookings as $b ) {
					$c_email = strtolower( trim( (string) ( $b->customer_email ?? '' ) ) );
					if ( '' === $c_email ) {
						continue;
					}
					$g_email = strtolower( trim( (string) ( $b->guest_email ?? '' ) ) );
					if ( empty( $b->guest_id ) || ( '' !== $g_email && $c_email !== $g_email ) ) {
						$true_guest = $email_to_guest[ $c_email ] ?? null;
						if ( $true_guest ) {
							$old_gid               = (int) ( $b->guest_id ?? 0 );
							$new_gid               = (int) $true_guest->id;
							$b->guest_id           = $new_gid;
							$b->guest_vip_status   = $true_guest->vip_status;
							$b->guest_caution_flag = $true_guest->caution_flag;

							if ( $old_gid !== $new_gid ) {
                                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
								$wpdb->update(
									$wpdb->prefix . 'mhbo_bookings',
									array( 'guest_id' => $new_gid ),
									array( 'id' => (int) $b->id )
								);
								Cache::invalidate_booking( (int) $b->id );
							}
						} elseif ( empty( $b->guest_id ) && class_exists( GuestIdentityResolver::class ) ) {
							$resolved_gid = GuestIdentityResolver::resolve_and_link_guest(
								array(
									'customer_name'  => $b->customer_name ?? '',
									'customer_email' => $b->customer_email,
									'customer_phone' => $b->customer_phone ?? '',
								)
							);
							if ( $resolved_gid > 0 ) {
								$b->guest_id = $resolved_gid;
                                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
								$wpdb->update(
									$wpdb->prefix . 'mhbo_bookings',
									array( 'guest_id' => $resolved_gid ),
									array( 'id' => (int) $b->id )
								);
								Cache::invalidate_booking( (int) $b->id );
							}
						}
					}
				}
			}
		}

		foreach ( $calendar_bookings as $b ) {
			if ( 'cancelled' === $b->status ) {
				continue;
			}
				
			if ( isset( $b->room_number ) && $b->room_number !== '' ) {
				if ( empty( $distinct_rooms[ (string) $b->room_number ] ) || '1' === (string) $distinct_rooms[ (string) $b->room_number ] ) {
					$distinct_rooms[ (string) $b->room_number ] = ! empty( $b->room_type ) ? I18n::decode( $b->room_type ) : __( 'General Rooms', 'modern-hotel-booking' );
				}
			}

			$current_time = strtotime( $b->check_in );
			// If prevent_turnover is enabled, include check_out date as blocked for same-day turnover
			$end_time = $prevent_turnover ? strtotime( $b->check_out . ' +1 day' ) : strtotime( $b->check_out );

			while ( $current_time < $end_time ) {
				$date_str = gmdate( 'Y-m-d', $current_time );
				if ( ! isset( $occupancy[ $date_str ] ) ) {
					$occupancy[ $date_str ] = array();
				}
				$occupancy[ $date_str ][ $b->room_number ] = true;
				$current_time                              = strtotime( '+1 day', $current_time );
			}
			
			// Deduplicate calendar events defensively to prevent identical stacked bars.
			$event_dedup_key = $b->room_number . '|' . $b->check_in . '|' . $b->check_out . '|' . $b->customer_name;
			if ( isset( $seen_calendar_events[ $event_dedup_key ] ) ) {
				continue;
			}
			$seen_calendar_events[ $event_dedup_key ] = true;

			// FullCalendar treats all-day 'end' dates as exclusive [start, end).
			// Hotel reservation dates span [check_in, check_out) covering the nights of the stay.
			$ts_in       = strtotime( $b->check_in );
			$ts_out      = strtotime( $b->check_out );
			$ts_next_day = strtotime( $b->check_in . ' +1 day' );
			$end_date    = ( false !== $ts_in && false !== $ts_out && $ts_out > $ts_in )
				? $b->check_out
				: ( false !== $ts_next_day ? gmdate( 'Y-m-d', $ts_next_day ) : $b->check_in );

			$nights_count = max( 1, (int) round( ( strtotime( $b->check_out ) - strtotime( $b->check_in ) ) / DAY_IN_SECONDS ) );
			
			$crm_url = ( $is_crm_active && ! empty( $b->guest_id ) )
				? admin_url( 'admin.php?page=mhbo-guests&guest_id=' . (int) $b->guest_id )
				: '';
				
			$cal_curr    = Pricing::get_currency_code();
			$rem_bal_m   = Money::fromDecimal( (string) ( $b->remaining_balance ?? 0 ), $cal_curr );
			$total_pr_m  = Money::fromDecimal( (string) ( $b->total_price ?? 0 ), $cal_curr );
			$balance_link = '';
			/* BUILD_PRO_START */
			if ( $is_pro_active && class_exists( \MHBO\Pro\BalancePayment::class ) && ( $b->balance_status ?? '' ) !== 'collected' && $rem_bal_m->isPositive() ) {
				$balance_link = \MHBO\Pro\BalancePayment::get_or_create_magic_url( (int) $b->id );
			}
			/* BUILD_PRO_END */

			$extended_props = array(
				'room_number'         => (string) ( $b->room_number ?? '' ),
				'room_type'           => I18n::decode( $b->room_type ?? '' ),
				'room_sort'           => is_numeric( $b->room_number ?? '' ) ? (int) $b->room_number : (string) ( $b->room_number ?? '' ),
				'booking_id'          => (int) $b->id,
				'customer'            => $b->customer_name,
				'email'               => $b->customer_email ?? '',
				'phone'               => $b->customer_phone ?? '',
				'check_in'            => $b->check_in,
				'check_out'           => $b->check_out,
				'check_in_formatted'  => date_i18n( get_option( 'date_format' ), strtotime( $b->check_in ) ),
				'check_out_formatted' => date_i18n( get_option( 'date_format' ), strtotime( $b->check_out ) ),
				'nights'              => $nights_count,
				'guests'              => (int) ( $b->guests ?? 1 ),
				'children'            => (int) ( $b->children ?? 0 ),
				'total_price'         => I18n::format_currency( $total_pr_m ),
				'status'              => I18n::translate_status( $b->status ),
				'status_key'          => $b->status,
				'vip_tier'            => ! empty( $b->guest_vip_status ) && 'none' !== $b->guest_vip_status ? strtoupper( str_replace( '_', ' ', $b->guest_vip_status ) ) : '',
				'caution_flag'        => ! empty( $b->guest_caution_flag ),
				'crm_url'             => $crm_url,
				'balance_url'         => $balance_link,
				'remaining_balance'   => $rem_bal_m->isPositive() ? I18n::format_currency( $rem_bal_m ) : '',
			);

			// Check if this booking checks out on a Sunday (w === '0')
			$check_out_ts    = strtotime( $b->check_out );
			$is_sun_checkout = ( false !== $check_out_ts && '0' === gmdate( 'w', $check_out_ts ) );

			$main_props = $extended_props;
			if ( $is_sun_checkout ) {
				$main_props['ends_on_saturday'] = true;
			}

			$events[] = array(
				'title'         => 'Room ' . $b->room_number . ' - ' . $b->customer_name,
				'start'         => $b->check_in,
				'end'           => $end_date,
				'color'         => 'confirmed' === $b->status ? '#059669' : '#d97706',
				'className'     => 'mhbo-status-' . $b->status . ( $is_sun_checkout ? ' mhbo-event-saturday-end' : '' ),
				'url'           => html_entity_decode( wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . $b->id ), 'mhbo_edit_booking_' . $b->id ) ),
				'extendedProps' => $main_props,
			);

			// If checking out on Sunday, inject companion morning checkout capsule on Sunday
			if ( $is_sun_checkout && false !== $check_out_ts ) {
				$sun_out_props                       = $extended_props;
				$sun_out_props['is_sunday_checkout'] = true;
				$ts_sun_next                         = strtotime( $b->check_out . ' +1 day' );
				$next_sun_day                        = false !== $ts_sun_next ? gmdate( 'Y-m-d', $ts_sun_next ) : $b->check_out;

				$events[] = array(
					'id'            => 'out-' . $b->id,
					'title'         => 'Room ' . $b->room_number . ' - ' . $b->customer_name,
					'start'         => $b->check_out,
					'end'           => $next_sun_day,
					'color'         => 'confirmed' === $b->status ? '#059669' : '#d97706',
					'className'     => 'mhbo-status-' . $b->status . ' mhbo-sunday-checkout',
					'url'           => html_entity_decode( wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . $b->id ), 'mhbo_edit_booking_' . $b->id ) ),
					'extendedProps' => $sun_out_props,
				);
			}
		}

		// Sort events strictly by room number then start date so same rooms align on the same line across days
		usort(
			$events,
			function ( $a, $b ) {
				$rA = $a['extendedProps']['room_sort'];
				$rB = $b['extendedProps']['room_sort'];
				if ( $rA === $rB ) {
					// Put Sunday checkout capsules first so they render under checkins on the same day
					$isOutA = ! empty( $a['extendedProps']['is_sunday_checkout'] );
					$isOutB = ! empty( $b['extendedProps']['is_sunday_checkout'] );
					if ( $a['start'] === $b['start'] && $isOutA !== $isOutB ) {
						return $isOutA ? -1 : 1;
					}
					return strcmp( $a['start'], $b['start'] );
				}
				if ( is_numeric( $rA ) && is_numeric( $rB ) ) {
					return $rA <=> $rB;
				}
				return strnatcasecmp( (string) $rA, (string) $rB );
			}
		);
		
		$total_rooms = count( $distinct_rooms );
		$full_days   = array();
		if ( $total_rooms > 0 ) {
			foreach ( $occupancy as $date => $rooms ) {
				if ( count( $rooms ) >= $total_rooms ) {
					$full_days[] = $date;
				}
			}
		}

		// Operational Daily Briefing KPI Metrics
		$today_ymd = HotelTime::today();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$today_checkins = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings WHERE check_in = %s AND status NOT IN ('cancelled', 'refunded')", $today_ymd ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$today_checkouts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings WHERE check_out = %s AND status NOT IN ('cancelled', 'refunded')", $today_ymd ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$in_house_rooms      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT room_id) FROM {$wpdb->prefix}mhbo_bookings WHERE check_in <= %s AND check_out > %s AND status NOT IN ('cancelled', 'refunded')", $today_ymd, $today_ymd ) );
		$distinct_room_count = $total_rooms ?: ( is_array( $all_rooms ) ? max( 1, count( $all_rooms ) ) : 1 );
		$occupancy_pct       = min( 100, (int) round( ( $in_house_rooms / max( 1, $distinct_room_count ) ) * 100 ) );

		if ( false === get_transient( 'mhbo_legacy_deposit_normalized' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Self-healing normalization for legacy deposit bookings.
			$wpdb->query(
				"UPDATE {$wpdb->prefix}mhbo_bookings 
                 SET deposit_received = 1, payment_received = 0, payment_status = 'deposit_paid', balance_status = 'pending' 
                 WHERE payment_type = 'deposit' AND (deposit_received = 0 OR deposit_received IS NULL) AND remaining_balance > 0 AND (payment_amount > 0 OR payment_status = 'completed' OR payment_status = 'deposit_paid')"
			);
			set_transient( 'mhbo_legacy_deposit_normalized', 1, DAY_IN_SECONDS );
		}

		?>
		<div class="wrap mhbo-admin-wrap">
			<?php 
			if ( $add_mode ) {
				$header_actions = array(
					array(
						'label' => '← ' . esc_html__( 'All Reservations', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings' ),
						'class' => 'button-secondary',
					),
				);
				/* BUILD_PRO_START */
				if ( $is_pro_active && class_exists( \MHBO\Pro\TabletReservationMode::class ) ) {
					$header_actions[] = array(
						'label' => '📱 ' . esc_html__( 'Front Desk Tablet Mode', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-tablet-mode' ),
						'class' => 'button-secondary',
					);
				}
				/* BUILD_PRO_END */

				AdminUI::render_header(
					I18n::get_label( 'label_add_manual_booking' ),
					__( 'Front-desk luxury PMS workstation for walk-in and phone reservations with live folio recalculation.', 'modern-hotel-booking' ),
					$header_actions,
					array(
						array(
							'label' => I18n::get_label( 'menu_dashboard' ),
							'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
						),
						array(
							'label' => I18n::get_label( 'menu_bookings' ),
							'url'   => admin_url( 'admin.php?page=mhbo-bookings' ),
						),
					)
				);
			} elseif ( $edit_mode && $edit_data ) {
				$header_actions = array(
					array(
						'label' => '← ' . esc_html__( 'All Reservations', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings' ),
						'class' => 'button-secondary',
					),
					array(
						'label' => '+ ' . I18n::get_label( 'label_add_manual_booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings&action=add' ),
						'class' => 'button-primary',
					),
				);
				/* BUILD_PRO_START */
				if ( $is_pro_active && class_exists( \MHBO\Pro\TabletReservationMode::class ) ) {
					$header_actions[] = array(
						'label' => '📱 ' . esc_html__( 'Front Desk Tablet Mode', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-tablet-mode' ),
						'class' => 'button-secondary',
					);
				}
				/* BUILD_PRO_END */

				AdminUI::render_header(
					sprintf(
						/* translators: %d: Reservation/Booking ID */
						__( 'Reservation #%d', 'modern-hotel-booking' ),
						(int) $edit_data->id
					),
					__( 'Manage guest folio, room unit assignment, stay dates, enhancements, and USALI financial transactions.', 'modern-hotel-booking' ),
					$header_actions,
					array(
						array(
							'label' => I18n::get_label( 'menu_dashboard' ),
							'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
						),
						array(
							'label' => I18n::get_label( 'menu_bookings' ),
							'url'   => admin_url( 'admin.php?page=mhbo-bookings' ),
						),
					)
				);
			} else {
				$header_actions = array(
					array(
						'label' => '+ ' . I18n::get_label( 'label_add_manual_booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings&action=add' ),
						'class' => 'button-primary',
					),
				);
				/* BUILD_PRO_START */
				if ( $is_pro_active ) {
					$header_actions[] = array(
						'label' => '📥 ' . I18n::get_label( 'label_export_bookings' ),
						'url'   => '#mhbo-export-modal',
						'class' => 'button-secondary mhbo-open-export-modal-btn',
					);
					$header_actions[] = array(
						'label' => '📤 ' . I18n::get_label( 'label_import_bookings' ),
						'url'   => '#mhbo-import-modal',
						'class' => 'button-secondary mhbo-open-import-modal-btn',
					);
				}
				/* BUILD_PRO_END */

				AdminUI::render_header(
					I18n::get_label( 'menu_bookings' ),
					I18n::get_label( 'dash_hotel_control_desc' ),
					$header_actions,
					array(
						array(
							'label' => I18n::get_label( 'menu_dashboard' ),
							'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
						),
					)
				);
			}
			?>
			<?php
			if ( ! $add_mode && ! $edit_mode ) :
				/* BUILD_PRO_START */
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( isset( $_GET['import_status'] ) && 'success' === $_GET['import_status'] ) {
					$ins = isset( $_GET['inserted'] ) ? absint( wp_unslash( $_GET['inserted'] ) ) : 0;
					$upd = isset( $_GET['updated'] ) ? absint( wp_unslash( $_GET['updated'] ) ) : 0;
					$skp = isset( $_GET['skipped'] ) ? absint( wp_unslash( $_GET['skipped'] ) ) : 0;
					echo '<div class="notice notice-success is-dismissible" style="margin-top:15px;"><p><strong>' . esc_html__( 'Bookings Import:', 'modern-hotel-booking' ) . '</strong> ' . esc_html( sprintf( I18n::get_label( 'msg_import_success' ), $ins, $upd, $skp ) ) . '</p></div>';
				} elseif ( isset( $_GET['import_error'] ) ) {
					$err_code = sanitize_key( wp_unslash( $_GET['import_error'] ) );
					$err_msg  = match ( $err_code ) {
						'no_file'           => I18n::get_label( 'msg_no_file_uploaded' ),
						'invalid_file_type' => __( 'Invalid file format. Please upload a .json or .csv file.', 'modern-hotel-booking' ),
						'file_too_large'    => __( 'The uploaded file exceeds the maximum allowed size (20MB).', 'modern-hotel-booking' ),
						default             => __( 'Failed to import bookings. Please ensure the uploaded file is valid JSON or CSV.', 'modern-hotel-booking' ),
					};
					echo '<div class="notice notice-error is-dismissible" style="margin-top:15px;"><p><strong>' . esc_html__( 'Import Error:', 'modern-hotel-booking' ) . '</strong> ' . esc_html( $err_msg ) . '</p></div>';
				}
				/* BUILD_PRO_END */

				if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() && class_exists( GuestMigration::class ) ) {
					$unmigrated_count = GuestMigration::get_unmigrated_count();
					if ( $unmigrated_count > 0 ) {
						if ( class_exists( GuestAsyncTasks::class ) ) {
							GuestAsyncTasks::queue_migrate_batch( 0, 250 );
						}
						$sync_url = wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=sync_crm_guests' ), 'mhbo_sync_crm_guests' );
						?>
						<div class="notice notice-info is-dismissible" style="margin-top:15px;">
							<p>
								<?php 
								/* translators: %d: count of unlinked historical bookings */
								echo esc_html( sprintf( __( 'Modern Hotel Booking detected %d historical reservation(s) not yet linked to Guest CRM profiles. Background sync is running.', 'modern-hotel-booking' ), $unmigrated_count ) ); 
								?>
								<a href="<?php echo esc_url( $sync_url ); ?>" class="button button-small" style="margin-left:10px;"><?php esc_html_e( 'Sync Now', 'modern-hotel-booking' ); ?></a>
							</p>
						</div>
						<?php
					}
				}

				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state for status notice
				if ( ! empty( $status_filter ) && 'all' !== $status_filter ) :
					?>
					<div class="notice notice-info is-dismissible" style="margin-top:15px;">
						<p>
							<?php
							/* translators: %s: booking status being filtered (e.g., Pending, Confirmed) */
							echo esc_html( sprintf( I18n::get_label( 'msg_filtering_status' ), ucfirst( $status_filter ) ) );
							?>
							<a href="<?php echo esc_url( remove_query_arg( array( 'status', 'paged' ), $bookings_base_url ) ); ?>" class="button button-small" style="margin-left:10px;">
								<?php echo esc_html( I18n::get_label( 'btn_clear_filter' ) ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $search_query ) ) : ?>
					<div class="notice notice-info is-dismissible" style="margin-top:15px;">
						<p>
							<?php
							/* translators: %s: search query string */
							echo esc_html( sprintf( __( 'Search results matching: "%s"', 'modern-hotel-booking' ), $search_query ) );
							?>
							<a href="<?php echo esc_url( remove_query_arg( array( 's', 'paged' ), $bookings_base_url ) ); ?>" class="button button-small" style="margin-left:10px;">
								<?php esc_html_e( 'Clear Search', 'modern-hotel-booking' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $add_mode ) : ?>
				<?php
				$currency   = ( class_exists( Pricing::class ) ) ? Pricing::get_currency_code() : 'USD';
				$today_dt   = HotelTime::now();
				$today_date = $today_dt->format( 'Y-m-d' );
				$tom_date   = $today_dt->modify( '+1 day' )->format( 'Y-m-d' );
				$crm_active = class_exists( GuestManager::class ) && GuestManager::is_enabled();
				$crm_guests = $crm_active ? GuestManager::get_recent_active_guests( 100 ) : array();
				?>

				<!-- 1. Hero Header Strip -->
				<div class="mhbo-workstation-hero">
					<div class="mhbo-hero-identity">
						<div class="mhbo-hero-avatar">
							🏨
						</div>
						<div class="mhbo-hero-meta">
							<h2>
								<span><?php esc_html_e( 'New Reservation', 'modern-hotel-booking' ); ?></span>
								<span style="font-size: 14px; font-weight: 600; color: #64748b;"><?php esc_html_e( '(Front Desk / Phone Walk-in)', 'modern-hotel-booking' ); ?></span>
								<span id="mhbo_add_status_badge" style="display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: #dcfce7; color: #166534;">
									<?php echo esc_html( I18n::translate_status( 'confirmed' ) ); ?>
								</span>
							</h2>
							<div class="mhbo-hero-subtitle">
								<span id="mhbo_add_hero_dates_text">📅 <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $today_date ) ) ); ?> → <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $tom_date ) ) ); ?></span>
								<span>&bull;</span>
								<span id="mhbo_add_nights_pill" style="background:#e0e7ff; color:#3730a3; font-weight:700; padding:2px 8px; border-radius:99px; font-size:11px;">🌙 1 Night</span>
								<span>&bull;</span>
								<span id="mhbo_add_occupancy_pill">👥 2 Guests</span>
							</div>
						</div>
					</div>
					<div class="mhbo-hero-actions">
						<?php /* BUILD_PRO_START */ ?>
						<?php if ( $is_pro_active && class_exists( \MHBO\Pro\TabletReservationMode::class ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-tablet-mode' ) ); ?>" class="mhbo-hero-btn" title="<?php esc_attr_e( 'Open interactive front-desk visual tablet chart', 'modern-hotel-booking' ); ?>">
								📱 <?php esc_html_e( 'Front Desk Tablet Mode', 'modern-hotel-booking' ); ?>
							</a>
						<?php endif; ?>
						<?php /* BUILD_PRO_END */ ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ); ?>" class="mhbo-hero-btn">
							🔙 <?php esc_html_e( 'All Bookings', 'modern-hotel-booking' ); ?>
						</a>
					</div>
				</div>

				<form method="post" id="mhbo_add_booking_form">
					<?php wp_nonce_field( 'mhbo_add_manual_booking' ); ?>
					<input type="hidden" name="guest_id" id="mhbo_add_guest_id" value="0">

					<div class="mhbo-workstation-grid">
						<!-- LEFT MAIN COLUMN (1.85fr) -->
						<div class="mhbo-workstation-col-main">

							<!-- Card 1: Guest Profile & CRM Identity -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-admin-users" style="color:#0284c7;"></span> <?php esc_html_e( 'Guest Profile & CRM Identity', 'modern-hotel-booking' ); ?></h3>
									<?php if ( $crm_active ) : ?>
										<span style="font-size:11px; background:#e0f2fe; color:#0369a1; font-weight:700; padding:2px 8px; border-radius:99px;">
											CRM Enabled
										</span>
									<?php endif; ?>
								</div>
								<div class="mhbo-card-body-padded">
									<?php if ( $crm_active && ! empty( $crm_guests ) ) : ?>
										<!-- CRM Quick Select for Returning Guests -->
										<div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px 14px; margin-bottom:16px;">
											<label for="mhbo_crm_guest_select" style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">
												🔍 <?php esc_html_e( 'Returning Guest Lookup (CRM Quick-Fill):', 'modern-hotel-booking' ); ?>
											</label>
											<div class="mhbo-crm-lookup-flex" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; width:100%;">
												<select id="mhbo_crm_guest_select" style="width:100%; max-width:100%; min-width:0; flex:1 1 260px;">
													<option value=""><?php esc_html_e( '-- New Guest / Walk-in (Enter Details Manually) --', 'modern-hotel-booking' ); ?></option>
													<?php foreach ( $crm_guests as $cg ) : ?>
														<?php
														$cg_name = trim( ( $cg->first_name ?? '' ) . ' ' . ( $cg->last_name ?? '' ) );
														$cg_name = '' !== $cg_name ? $cg_name : ( $cg->email ?: '#' . $cg->id );
														$cg_vip  = ( ! empty( $cg->vip_status ) && 'none' !== $cg->vip_status ) ? ' [' . strtoupper( str_replace( '_', ' ', $cg->vip_status ) ) . ']' : '';
														$cg_flag = ! empty( $cg->caution_flag ) ? ' ⚠️' : '';
														?>
														<option value="<?php echo esc_attr( (string) $cg->id ); ?>"
															data-name="<?php echo esc_attr( $cg_name ); ?>"
															data-email="<?php echo esc_attr( (string) ( $cg->email ?? '' ) ); ?>"
															data-phone="<?php echo esc_attr( (string) ( $cg->phone ?? '' ) ); ?>"
															data-vip="<?php echo esc_attr( (string) ( $cg->vip_status ?? 'none' ) ); ?>"
															data-caution="<?php echo esc_attr( ! empty( $cg->caution_flag ) ? '1' : '0' ); ?>">
															<?php echo esc_html( $cg_name . ' (' . ( $cg->email ?? 'No email' ) . ')' . $cg_vip . $cg_flag ); ?>
														</option>
													<?php endforeach; ?>
												</select>
												<span id="mhbo_crm_match_badge" style="display:none; padding:3px 8px; border-radius:99px; font-size:11px; font-weight:700;"></span>
											</div>
											<div id="mhbo_crm_caution_alert" style="display:none; background:#fef2f2; border:1px solid #f87171; border-radius:6px; padding:8px 12px; margin-top:10px; color:#991b1b; font-weight:600; font-size:12px;">
												<span style="font-size:14px; margin-right:4px;">🚫</span> <?php esc_html_e( 'Caution: Problematic guest flagged by staff in CRM.', 'modern-hotel-booking' ); ?>
											</div>
										</div>
									<?php endif; ?>

									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_add_customer_name"><?php echo esc_html( I18n::get_label( 'label_customer_name' ) ); ?> <span style="color:#dc2626;">*</span></label></th>
											<td><input type="text" id="mhbo_add_customer_name" name="customer_name" required class="regular-text" style="width:100%; max-width:420px;" placeholder="<?php esc_attr_e( 'Full Name (e.g. Jane Doe)', 'modern-hotel-booking' ); ?>"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_customer_email"><?php echo esc_html( I18n::get_label( 'label_email' ) ); ?> <span style="color:#dc2626;">*</span></label></th>
											<td><input type="email" id="mhbo_add_customer_email" name="customer_email" required class="regular-text" style="width:100%; max-width:420px;" placeholder="<?php esc_attr_e( 'guest@example.com', 'modern-hotel-booking' ); ?>"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_customer_phone"><?php echo esc_html( I18n::get_label( 'label_phone' ) ); ?></label></th>
											<td><input type="tel" id="mhbo_add_customer_phone" name="customer_phone" class="regular-text" style="width:100%; max-width:420px;" placeholder="<?php esc_attr_e( '+1 (555) 000-0000', 'modern-hotel-booking' ); ?>"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_guests"><?php echo esc_html( I18n::get_label( 'label_guests' ) ); ?></label></th>
											<td>
												<input type="number" name="guests" id="mhbo_add_guests" value="2" min="1" max="20" class="small-text">
												<span class="description"><?php esc_html_e( 'Adults (18+)', 'modern-hotel-booking' ); ?></span>
											</td>
										</tr>
										<?php /* BUILD_PRO_START */ ?>
										<tr>
											<th scope="row"><label for="mhbo_add_children"><?php echo esc_html( I18n::get_label( 'label_children' ) ); ?></label></th>
											<td>
												<input type="number" name="children" id="mhbo_add_children" value="0" min="0" max="20" class="small-text">
												<span class="description"><?php esc_html_e( 'Children under 18', 'modern-hotel-booking' ); ?></span>
											</td>
										</tr>
										<tr id="mhbo_add_child_ages_row" style="display:none;">
											<th scope="row"><?php echo esc_html( I18n::get_label( 'label_child_ages' ) ); ?></th>
											<td>
												<div id="mhbo_add_child_ages_container" style="display:flex; flex-wrap:wrap; gap:8px;"></div>
											</td>
										</tr>
										<?php /* BUILD_PRO_END */ ?>
									</table>
								</div>
							</div>

							<!-- Card 2: Room Unit Assignment & Stay Calendar -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-calendar-alt" style="color:#059669;"></span> <?php esc_html_e( 'Room Unit Assignment & Stay Dates', 'modern-hotel-booking' ); ?></h3>
									<span style="font-size:12px; font-weight:600; color:#059669;" id="mhbo_add_calendar_nights_indicator">
										🌙 1 Night Stay
									</span>
								</div>
								<div class="mhbo-card-body-padded">
									<div id="mhbo_add_room_avail_banner" style="display:none; margin-bottom:14px; padding:10px 14px; border-radius:6px; font-weight:600; font-size:13px;"></div>

									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_add_room_id"><?php echo esc_html( I18n::get_label( 'label_room' ) ); ?> <span style="color:#dc2626;">*</span></label></th>
											<td>
												<select name="room_id" id="mhbo_add_room_id" required style="width:100%; max-width:420px;">
													<option value=""><?php echo esc_html( I18n::get_label( 'label_select_room' ) ); ?></option>
													<?php foreach ( $all_rooms as $rm ) : ?>
														<option value="<?php echo esc_attr( $rm->id ); ?>" data-price="<?php echo esc_attr( $rm->base_price ); ?>" data-room-name="<?php echo esc_attr( $rm->room_number . ' (' . I18n::decode( $rm->type_name ) . ')' ); ?>">
															<?php echo esc_html( $rm->room_number . ' (' . I18n::decode( $rm->type_name ) . ') - ' . I18n::format_currency( $rm->base_price ) . ' / night' ); ?>
														</option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_date_range_picker"><?php esc_html_e( 'Stay Dates (Interactive Calendar)', 'modern-hotel-booking' ); ?> <span style="color:#dc2626;">*</span></label></th>
											<td>
												<div style="position:relative; max-width:420px; width:100%;">
													<input type="text" id="mhbo_add_date_range_picker" class="regular-text" style="width:100%; padding-left:34px; padding-right:32px; font-weight:600; cursor:text; background:#fff;" placeholder="<?php esc_attr_e( 'e.g. 2026-10-08 to 2026-10-12, 08/10/2026 - 12/10/2026, or click calendar', 'modern-hotel-booking' ); ?>">
													<span class="dashicons dashicons-calendar-alt" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:#64748b; font-size:18px; pointer-events:none;"></span>
													<button type="button" id="mhbo_add_clear_dates_btn" title="<?php esc_attr_e( 'Clear stay dates', 'modern-hotel-booking' ); ?>" style="position:absolute; right:6px; top:50%; transform:translateY(-50%); background:#f1f5f9; border:1px solid #cbd5e1; border-radius:4px; color:#64748b; font-size:14px; font-weight:700; cursor:pointer; width:22px; height:22px; line-height:1; display:flex; align-items:center; justify-content:center; padding:0; transition:all 0.15s ease;">&times;</button>
												</div>
												<input type="hidden" name="check_in" id="mhbo_add_check_in" value="<?php echo esc_attr( $today_date ); ?>" required>
												<input type="hidden" name="check_out" id="mhbo_add_check_out" value="<?php echo esc_attr( $tom_date ); ?>" required>
												<p class="description" style="margin-top:4px;">
													<?php esc_html_e( 'Select stay dates on the calendar. Daily pricing, room availability status, and stay policies update in real time.', 'modern-hotel-booking' ); ?>
												</p>
											</td>
										</tr>
									</table>

									<!-- Inline PMS Availability & Daily Pricing Calendar Mount (Full Card Width) -->
									<div id="mhbo_add_calendar_mount" class="mhbo-pms-calendar-wrapper" style="margin-top:16px; display:none;"></div>

									<!-- PMS Calendar Legend -->
									<div class="mhbo-calendar-legend mhbo-pms-calendar-legend" id="mhbo_add_calendar_legend" style="display:none; margin-top:12px; display:flex; flex-wrap:wrap; gap:16px; font-size:12px; color:#475569; align-items:center;">
										<span style="display:inline-flex; align-items:center; gap:6px;">
											<span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#10b981;"></span>
											<span><?php esc_html_e( 'Available (Nightly Rate)', 'modern-hotel-booking' ); ?></span>
										</span>
										<span style="display:inline-flex; align-items:center; gap:6px;">
											<span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#ef4444;"></span>
											<span><?php esc_html_e( 'Booked / Confirmed', 'modern-hotel-booking' ); ?></span>
										</span>
										<span style="display:inline-flex; align-items:center; gap:6px;">
											<span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#f59e0b;"></span>
											<span><?php esc_html_e( 'Pending Hold', 'modern-hotel-booking' ); ?></span>
										</span>
										<span style="display:inline-flex; align-items:center; gap:6px;">
											<span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:linear-gradient(135deg, #ef4444 50%, #10b981 50%); border:1px solid #cbd5e1;"></span>
											<span><?php esc_html_e( 'Turnover (Checkout / Checkin)', 'modern-hotel-booking' ); ?></span>
										</span>
									</div>
								</div>
							</div>

							<?php
							$has_custom_fields = count( (array) get_option( 'mhbo_custom_fields', array() ) ) > 0;
							if ( License::is_pro_active() || $has_custom_fields ) :
								?>
							<!-- Card 3: Stay Enhancements & Extras -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-tag" style="color:#8b5cf6;"></span> <?php esc_html_e( 'Stay Enhancements & Extras', 'modern-hotel-booking' ); ?></h3>
									<span style="font-size:12px; color:#64748b;"><?php esc_html_e( 'Optional add-ons, early/late check-in', 'modern-hotel-booking' ); ?></span>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">
										<?php /* BUILD_PRO_START */ ?>
										<?php if ( License::is_pro_active() ) : ?>
											<?php $early_fee_m = Money::fromDecimal( (string) get_option( 'mhbo_early_checkin_fee', '0' ), Pricing::get_currency_code() ); ?>
											<?php $late_fee_m  = Money::fromDecimal( (string) get_option( 'mhbo_late_checkout_fee', '0' ), Pricing::get_currency_code() ); ?>
											<?php if ( get_option( 'mhbo_early_checkin_enabled', 0 ) ) : ?>
												<tr>
													<th scope="row"><?php esc_html_e( 'Early Check-in', 'modern-hotel-booking' ); ?></th>
													<td>
														<label style="display:inline-flex; align-items:center; gap:8px;">
															<input type="checkbox" name="mhbo_extras[_early_checkin]" value="1" class="mhbo-extra-input" data-extra-id="_early_checkin" data-price="<?php echo esc_attr( $early_fee_m->toDecimal() ); ?>" data-pricing-type="per_day">
															<span>
																<?php
																/* translators: %s: formatted fee amount per day */
																echo esc_html( sprintf( __( 'Yes (+%s / stay)', 'modern-hotel-booking' ), I18n::format_currency( $early_fee_m ) ) );
																?>
															</span>
														</label>
													</td>
												</tr>
											<?php endif; ?>
											<?php if ( get_option( 'mhbo_late_checkout_enabled', 0 ) ) : ?>
												<tr>
													<th scope="row"><?php esc_html_e( 'Late Check-out', 'modern-hotel-booking' ); ?></th>
													<td>
														<label style="display:inline-flex; align-items:center; gap:8px;">
															<input type="checkbox" name="mhbo_extras[_late_checkout]" value="1" class="mhbo-extra-input" data-extra-id="_late_checkout" data-price="<?php echo esc_attr( $late_fee_m->toDecimal() ); ?>" data-pricing-type="per_day">
															<span>
																<?php
																/* translators: %s: formatted fee amount per day */
																echo esc_html( sprintf( __( 'Yes (+%s / stay)', 'modern-hotel-booking' ), I18n::format_currency( $late_fee_m ) ) );
																?>
															</span>
														</label>
													</td>
												</tr>
											<?php endif; ?>
										<?php endif; ?>

										<tr>
											<th scope="row"><?php echo esc_html( I18n::get_label( 'label_extras' ) ); ?></th>
											<td>
												<?php
												$extras = get_option( 'mhbo_pro_extras', array() );
												if ( count( $extras ) > 0 ) {
													foreach ( $extras as $ex ) {
														$lbl          = esc_html( (string) ( I18n::decode( $ex['name'] ?? '' ) ?? '' ) ) . ' (' . esc_html( I18n::format_currency( $ex['price'] ) ) . ')';
														$pricing_type = $ex['pricing_type'] ?? 'fixed';
														if ( isset( $ex['compulsory'] ) && $ex['compulsory'] ) {
															echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;padding:4px 8px;background:#f0f4ff;border:1px solid #c7d7fd;border-radius:4px;width:fit-content;">';
															echo '<span style="font-size:12px;">🔒</span>';
															echo '<span style="font-size:13px;">' . $lbl . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
															echo '<span style="font-size:11px;color:#5b72b8;font-weight:600;">' . esc_html__( '(Compulsory)', 'modern-hotel-booking' ) . '</span>';
															echo '<input type="hidden" name="mhbo_extras[' . esc_attr( $ex['id'] ) . ']" value="1" class="mhbo-extra-input" data-extra-id="' . esc_attr( $ex['id'] ) . '" data-price="' . esc_attr( $ex['price'] ) . '" data-pricing-type="' . esc_attr( $pricing_type ) . '">';
															echo '</div>';
															continue;
														}
														if ( ( $ex['control_type'] ?? 'checkbox' ) === 'quantity' ) {
															echo '<label style="display:flex; align-items:center; gap:8px; margin-bottom:6px;"><input type="number" name="mhbo_extras[' . esc_attr( $ex['id'] ) . ']" value="0" min="0" style="width:60px;" class="mhbo-extra-input" data-extra-id="' . esc_attr( $ex['id'] ) . '" data-price="' . esc_attr( $ex['price'] ) . '" data-pricing-type="' . esc_attr( $pricing_type ) . '"> <span>' . $lbl . '</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
														} else {
															echo '<label style="display:flex; align-items:center; gap:8px; margin-bottom:6px;"><input type="checkbox" name="mhbo_extras[' . esc_attr( $ex['id'] ) . ']" value="1" class="mhbo-extra-input" data-extra-id="' . esc_attr( $ex['id'] ) . '" data-price="' . esc_attr( $ex['price'] ) . '" data-pricing-type="' . esc_attr( $pricing_type ) . '"> <span>' . $lbl . '</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
														}
													}
												} else {
													echo '<span class="description">' . esc_html( I18n::get_label( 'label_no_extras_config' ) ) . '</span>';
												}
												?>
											</td>
										</tr>
										<?php /* BUILD_PRO_END */ ?>

										<!-- Custom Fields -->
										<?php
										$custom_fields_defn = get_option( 'mhbo_custom_fields', array() );
										if ( isset( $custom_fields_defn ) && count( $custom_fields_defn ) > 0 ) :
											?>
											<?php foreach ( $custom_fields_defn as $defn ) : ?>
												<?php $label = (string) ( I18n::decode( I18n::encode( $defn['label'] ) ) ?? '' ); ?>
												<tr>
													<th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
													<td>
														<?php if ( $defn['type'] === 'textarea' ) : ?>
															<textarea name="mhbo_custom[<?php echo esc_attr( $defn['id'] ); ?>]" rows="3" class="regular-text" style="width:100%; max-width:420px;"></textarea>
														<?php else : ?>
															<input type="<?php echo esc_attr( $defn['type'] ); ?>" name="mhbo_custom[<?php echo esc_attr( $defn['id'] ); ?>]" class="regular-text" style="width:100%; max-width:420px;">
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										<?php endif; ?>
									</table>
								</div>
							</div>
							<?php endif; ?>

							<!-- Card 4: Promotions, Coupons & Concessions -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<?php /* BUILD_FREE_START */ ?>
									<h3><span class="dashicons dashicons-tickets-alt" style="color:#d97706;"></span> <?php esc_html_e( 'Discounts & Concessions', 'modern-hotel-booking' ); ?></h3>
									<?php /* BUILD_FREE_END */ ?>
									<?php /* BUILD_STARTER_START */ ?>
									<h3><span class="dashicons dashicons-tickets-alt" style="color:#d97706;"></span> <?php esc_html_e( 'Promotions, Coupons & Concessions', 'modern-hotel-booking' ); ?></h3>
									<?php /* BUILD_STARTER_END */ ?>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">
										<?php /* BUILD_PRO_START */ ?>
										<tr>
											<th scope="row"><label for="mhbo_add_coupon_code"><?php esc_html_e( 'Coupon / Promo Code', 'modern-hotel-booking' ); ?></label></th>
											<td>
												<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
													<input type="text" name="coupon_code" id="mhbo_add_coupon_code" class="regular-text" style="max-width:200px; text-transform:uppercase;" placeholder="<?php esc_attr_e( 'e.g. SUMMER20', 'modern-hotel-booking' ); ?>">
													<button type="button" id="mhbo_add_apply_coupon_btn" class="button button-secondary">
														<?php esc_html_e( 'Apply', 'modern-hotel-booking' ); ?>
													</button>
													<span id="mhbo_add_coupon_status" style="display:none; padding:3px 8px; border-radius:12px; font-weight:700; font-size:11px;"></span>
												</div>
											</td>
										</tr>
										<?php /* BUILD_PRO_END */ ?>

										<tr>
											<th scope="row"><label for="mhbo_add_discount_amount"><?php echo esc_html( I18n::get_label( 'label_discount_amount' ) ); ?></label></th>
											<td>
												<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
													<input type="number" step="any" name="discount_amount" id="mhbo_add_discount_amount" value="0.00" class="small-text" style="width:110px;">
													<span class="description" style="font-weight:600;"><?php echo esc_html( $currency ); ?></span>
												</div>
												<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Direct discount or courtesy rate adjustment.', 'modern-hotel-booking' ); ?></p>
											</td>
										</tr>

										<?php /* BUILD_STARTER_START */ ?>
										<!-- Tax Mode Selection -->
										<tr>
											<th scope="row"><label for="mhbo_add_tax_mode"><?php esc_html_e( 'Tax Mode', 'modern-hotel-booking' ); ?></label></th>
											<td>
												<?php
												$default_add_tax_mode = class_exists( Tax::class ) && Tax::is_enabled() ? Tax::get_mode() : 'disabled';
												?>
												<select name="tax_mode" id="mhbo_add_tax_mode" style="min-width:200px;">
													<option value="vat" <?php selected( in_array( $default_add_tax_mode, array( 'vat', 'inclusive' ), true ) ); ?>><?php esc_html_e( 'VAT (Inclusive)', 'modern-hotel-booking' ); ?></option>
													<option value="sales_tax" <?php selected( in_array( $default_add_tax_mode, array( 'sales_tax', 'exclusive' ), true ) ); ?>><?php esc_html_e( 'Sales Tax / GST (Exclusive)', 'modern-hotel-booking' ); ?></option>
													<option value="disabled" <?php selected( $default_add_tax_mode, 'disabled' ); ?>><?php esc_html_e( 'Disabled (No Tax)', 'modern-hotel-booking' ); ?></option>
												</select>
												<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Folio taxation regime for this reservation. Toggling recalculates itemized taxes.', 'modern-hotel-booking' ); ?></p>
											</td>
										</tr>
										<?php /* BUILD_STARTER_END */ ?>
									</table>
								</div>
							</div>

							<!-- Card 5: Real-time Charges & Folio Breakdown Preview -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-media-spreadsheet" style="color:#3b82f6;"></span> <?php esc_html_e( 'Live Folio & Charges Breakdown', 'modern-hotel-booking' ); ?></h3>
									<span style="font-size:12px; font-weight:600; color:#64748b;"><?php esc_html_e( 'Real-time USALI preview', 'modern-hotel-booking' ); ?></span>
								</div>
								<div class="mhbo-table-responsive" style="overflow-x:auto;">
									<table class="mhbo-charges-table" id="mhbo_add_folio_table">
										<thead>
											<tr>
												<th scope="col" style="width:110px;"><?php esc_html_e( 'Category', 'modern-hotel-booking' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Charge Description', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:100px;"><?php esc_html_e( 'Rate', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:center; width:90px;"><?php esc_html_e( 'Qty / Nights', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:90px;"><?php esc_html_e( 'Tax', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:110px;"><?php esc_html_e( 'Total', 'modern-hotel-booking' ); ?></th>
											</tr>
										</thead>
										<tbody id="mhbo_add_folio_tbody">
											<tr>
												<td colspan="6" style="text-align:center; padding:20px; color:#64748b;">
													<?php esc_html_e( 'Select room and dates above to calculate itemized folio charges.', 'modern-hotel-booking' ); ?>
												</td>
											</tr>
										</tbody>
										<tfoot style="background:#f8fafc; border-top:2px solid #cbd5e1; font-weight:700;">
											<tr id="mhbo_add_folio_foot_subtotal_row" class="mhbo-hidden" style="display:none !important;" hidden>
												<td colspan="5" style="text-align:right; padding:8px 12px; color:#64748b; font-size:12px;" id="mhbo_add_folio_foot_subtotal_label"><?php echo esc_html( ( class_exists( Tax::class ) && Tax::is_vat() ) ? sprintf( I18n::get_label( 'label_subtotal_net_vat' ), Tax::get_label( null, 'vat' ) ) : esc_html__( 'Subtotal Net (excl. tax):', 'modern-hotel-booking' ) ); ?></td>
												<td style="text-align:right; padding:8px 12px; font-variant-numeric:tabular-nums;" id="mhbo_add_folio_foot_subtotal_net">0.00</td>
											</tr>
											<tr id="mhbo_add_folio_foot_tax_row" class="mhbo-hidden" style="display:none !important;" hidden>
												<td colspan="5" style="text-align:right; padding:6px 12px; color:#64748b; font-size:12px;" id="mhbo_add_folio_foot_tax_label">
													<?php
													if ( class_exists( Tax::class ) && Tax::is_vat() ) {
														echo esc_html( Tax::get_label( null, 'vat' ) . ' (included):' );
													} else {
														/* translators: %s: Tax category label */
														echo esc_html( sprintf( __( 'Taxes (%s):', 'modern-hotel-booking' ), class_exists( Tax::class ) ? Tax::get_label( null, 'sales_tax' ) : 'Tax' ) );
													}
													?>
												</td>
												<td style="text-align:right; padding:6px 12px; font-variant-numeric:tabular-nums; color:#64748b;" id="mhbo_add_folio_foot_tax">0.00</td>
											</tr>
											<tr style="border-top:1px solid #e2e8f0; font-size:14px;">
												<td colspan="5" style="text-align:right; padding:12px; font-weight:800; color:#0f172a;" id="mhbo_add_folio_foot_total_label"><?php echo esc_html( ( class_exists( Tax::class ) && Tax::is_vat() ) ? esc_html__( 'Estimated Total (VAT incl.):', 'modern-hotel-booking' ) : esc_html__( 'Estimated Total Gross:', 'modern-hotel-booking' ) ); ?></td>
												<td style="text-align:right; padding:12px; font-weight:800; color:#0f172a; font-size:16px; font-variant-numeric:tabular-nums;" id="mhbo_add_folio_foot_total">0.00</td>
											</tr>
										</tfoot>
									</table>
								</div>
							</div>

						</div>

						<!-- RIGHT SIDEBAR (1.15fr) -->
						<div class="mhbo-workstation-col-sidebar">

							<!-- Card 1: Financial Overview KPIs -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-chart-pie" style="color:#0284c7;"></span> <?php esc_html_e( 'Financial Overview', 'modern-hotel-booking' ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<div class="mhbo-kpi-grid">
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Total Charges', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value" id="mhbo_overview_total_val">0.00</span>
										</div>
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Deposit Required', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value" id="mhbo_overview_deposit_val">0.00</span>
										</div>
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Initial Paid', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value mhbo-kpi-value-paid" id="mhbo_overview_paid_val">0.00</span>
										</div>
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Balance Due', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value mhbo-kpi-value-due" id="mhbo_overview_pending_val">0.00</span>
										</div>
									</div>
								</div>
							</div>

							<!-- Card 2: Billing & Initial Settlement -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-money-alt" style="color:#10b981;"></span> <?php esc_html_e( 'Billing & Initial Payment', 'modern-hotel-booking' ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_add_total_price"><?php echo esc_html( I18n::get_label( 'label_total_price' ) ); ?> <span style="color:#dc2626;">*</span></label></th>
											<td>
												<input type="number" step="any" name="total_price" id="mhbo_add_total_price" required value="0.00" class="regular-text" style="width:100%;">
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_deposit_amount"><?php echo esc_html( I18n::get_label( 'label_deposit_amount' ) ); ?></label></th>
											<td>
												<input type="number" step="any" name="deposit_amount" id="mhbo_add_deposit_amount" value="0.00" class="regular-text" style="width:100%;">
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_payment_amount"><?php esc_html_e( 'Initial Payment Recorded', 'modern-hotel-booking' ); ?></label></th>
											<td>
												<input type="number" step="any" name="payment_amount" id="mhbo_add_payment_amount" value="0.00" class="regular-text" style="width:100%;">
												<!-- Quick Tender Buttons -->
												<div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:6px;">
													<button type="button" class="button button-small mhbo-quick-tender-btn" data-action="full">
														⚡ <?php esc_html_e( 'Pay in Full', 'modern-hotel-booking' ); ?>
													</button>
													<button type="button" class="button button-small mhbo-quick-tender-btn" data-action="deposit">
														⚡ <?php esc_html_e( 'Deposit Only', 'modern-hotel-booking' ); ?>
													</button>
													<button type="button" class="button button-small mhbo-quick-tender-btn" data-action="zero">
														⚡ <?php esc_html_e( 'Pay on Arrival ($0)', 'modern-hotel-booking' ); ?>
													</button>
												</div>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php echo esc_html( I18n::get_label( 'label_deposit_received' ) ); ?></th>
											<td>
												<label style="display:flex; align-items:center; gap:8px;">
													<input type="hidden" name="deposit_received" value="0">
													<input type="checkbox" name="deposit_received" id="mhbo_add_deposit_received" value="1">
													<span><?php echo esc_html( I18n::get_label( 'label_mark_deposit_rcvd' ) ); ?></span>
												</label>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php echo esc_html( I18n::get_label( 'label_payment_rcvd' ) ); ?></th>
											<td>
												<label style="display:flex; align-items:center; gap:8px;">
													<input type="hidden" name="payment_received" value="0">
													<input type="checkbox" name="payment_received" id="mhbo_add_payment_received" value="1">
													<span><?php echo esc_html( I18n::get_label( 'label_mark_rcvd' ) ); ?></span>
												</label>
											</td>
										</tr>
										<tr>
											<th scope="row"><label><?php echo esc_html( I18n::get_label( 'label_payment_method' ) ); ?></label></th>
											<td>
												<select name="payment_method" id="mhbo_add_payment_method" style="width:100%;">
													<option value="arrival" selected>
														<?php echo esc_html( I18n::get_label( 'label_pay_arrival_manual' ) ); ?>
													</option>
													<option value="credit_card">
														<?php esc_html_e( 'Credit Card (Front Desk Terminal)', 'modern-hotel-booking' ); ?>
													</option>
													<option value="cash">
														<?php esc_html_e( 'Cash (Front Desk Cashier)', 'modern-hotel-booking' ); ?>
													</option>
													<option value="bank_transfer">
														<?php esc_html_e( 'Direct Bank Transfer', 'modern-hotel-booking' ); ?>
													</option>
													<?php
													$show_commercial_gateways = false;
													/* BUILD_STARTER_START */
													$show_commercial_gateways = class_exists( '\MHBO\Core\Tier' ) ? \MHBO\Core\Tier::has_feature( 'payments_stripe' ) : License::is_starter_active();
													/* BUILD_STARTER_END */
													if ( $show_commercial_gateways ) :
														?>
														<option value="stripe"><?php echo esc_html( I18n::get_label( 'gateway_stripe' ) ); ?></option>
														<option value="paypal"><?php echo esc_html( I18n::get_label( 'gateway_paypal' ) ); ?></option>
														<option value="braintree"><?php echo esc_html( I18n::get_label( 'label_braintree' ) ); ?></option>
													<?php endif; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_amount_outstanding"><?php echo esc_html( I18n::get_label( 'label_amt_out' ) ); ?></label></th>
											<td>
												<input type="text" id="mhbo_add_amount_outstanding" readonly class="regular-text" style="width:100%; background:#f8fafc; font-weight:700; color:#dc2626;">
											</td>
										</tr>
									</table>
								</div>
							</div>

							<!-- Card 3: Lifecycle & Internal Front Desk Notes -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-admin-generic" style="color:#64748b;"></span> <?php echo esc_html( I18n::get_label( 'title_booking_mgmt' ) ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_add_status"><?php echo esc_html( I18n::get_label( 'label_status_noun' ) ); ?></label></th>
											<td>
												<select name="status" id="mhbo_add_status" style="width:100%;">
													<option value="pending"><?php echo esc_html( I18n::get_label( 'status_pending' ) ); ?></option>
													<option value="confirmed" selected><?php echo esc_html( I18n::get_label( 'status_confirmed' ) ); ?></option>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label><?php echo esc_html( I18n::get_label( 'label_booking_lang' ) ); ?></label></th>
											<td>
												<select name="booking_language" style="width:100%;">
													<?php foreach ( I18n::get_available_languages() as $lang ) : ?>
														<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $lang, I18n::get_current_language() ); ?>><?php echo esc_html( strtoupper( $lang ) ); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_admin_notes"><?php echo esc_html( I18n::get_label( 'label_admin_notes' ) ); ?></label></th>
											<td>
												<textarea name="admin_notes" id="mhbo_add_admin_notes" rows="3" class="large-text" style="width:100%;" placeholder="<?php esc_attr_e( 'Internal guest requests, arrival notes, or housekeeping preferences...', 'modern-hotel-booking' ); ?>"></textarea>
											</td>
										</tr>
									</table>
								</div>
							</div>

						</div>
					</div>

					<!-- Sticky Bottom Action Dock -->
					<div class="mhbo-form-actions-dock">
						<button type="submit" name="submit_manual_booking" class="button button-primary" style="padding: 6px 20px; font-weight: 700; font-size: 14px;">
							🏨 <?php echo esc_html( I18n::get_label( 'label_add_booking' ) ); ?>
						</button>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ); ?>" class="button">
							<?php echo esc_html( I18n::get_label( 'label_cancel' ) ); ?>
						</a>
					</div>
				</form>
			<?php endif; ?>

		<?php if ( $edit_mode && $edit_data ) : ?>
						<?php
						$currency          = ( class_exists( Pricing::class ) ) ? Pricing::get_currency_code() : 'USD';
						$hotel_tax_enabled = class_exists( Tax::class ) && Tax::is_enabled();
						$hotel_tax_mode    = class_exists( Tax::class ) ? Tax::get_mode() : Tax::MODE_DISABLED;
						if ( ! $hotel_tax_enabled ) {
							$edit_tax_mode = Tax::MODE_DISABLED;
						} else {
							$edit_tax_mode = sanitize_key( $edit_data->tax_mode ?? $hotel_tax_mode );
							if ( '' === $edit_tax_mode ) {
								$edit_tax_mode = $hotel_tax_mode;
							}
						}
						$folio         = ( class_exists( Pricing::class ) ) ? Pricing::get_folio_breakdown( $edit_data, '', $edit_tax_mode ) : array();
						$edit_amts        = ( class_exists( Pricing::class ) ) ? Pricing::get_booking_payment_amounts( $edit_data ) : null;
						$group_amts       = ( class_exists( Pricing::class ) && ! empty( $edit_data->multi_room_parent ) ) ? Pricing::get_group_payment_amounts( $edit_data ) : null;
						$is_group_booking = ( null !== $group_amts && $group_amts['rooms_count'] >= 2 );
						$room_data        = ( class_exists( Pricing::class ) && ! empty( $edit_data->room_id ) ) ? Pricing::get_room_pricing_data( (int) $edit_data->room_id ) : null;
						$room_title       = (string) ( ( $folio['room_name'] ?? '' ) ?: ( ( $room_data && $room_data->name ) ? I18n::decode( $room_data->name ) : '' ) ?: __( 'Assigned Unit', 'modern-hotel-booking' ) );
						$room_num         = $folio['room_number'] ?? ( $room_data ? $room_data->room_number : '' );

						$crm_guest_id = 0;
						$crm_guest    = null;
						if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) {
							$crm_guest_id = (int) ( $edit_data->guest_id ?? 0 );
							if ( $crm_guest_id <= 0 && ! empty( $edit_data->customer_email ) ) {
								$crm_guest_id = GuestIdentityResolver::resolve_and_link_guest(
									array(
										'customer_name'  => $edit_data->customer_name,
										'customer_email' => $edit_data->customer_email,
										'customer_phone' => $edit_data->customer_phone ?? '',
									)
								);
							}
							$crm_guest = $crm_guest_id > 0 ? GuestManager::get_guest( $crm_guest_id ) : null;
						}

						$has_early = false;
						$has_late  = false;
						$saved_custom = ! empty( $edit_data->custom_fields ) ? json_decode( (string) $edit_data->custom_fields, true ) : array();
						if ( is_array( $saved_custom ) ) {
							$has_early = ! empty( $saved_custom['_early_checkin'] );
							$has_late  = ! empty( $saved_custom['_late_checkout'] );
						}
						if ( ! empty( $edit_data->booking_extras ) ) {
							$ex_check = json_decode( (string) $edit_data->booking_extras, true );
							if ( is_array( $ex_check ) ) {
								if ( isset( $ex_check['_early_checkin'] ) ) {
									$has_early = ! empty( $ex_check['_early_checkin']['quantity'] ?? $ex_check['_early_checkin'] );
								}
								if ( isset( $ex_check['_late_checkout'] ) ) {
									$has_late = ! empty( $ex_check['_late_checkout']['quantity'] ?? $ex_check['_late_checkout'] );
								}
								foreach ( $ex_check as $k => $item ) {
									$item_id = is_array( $item ) ? ( $item['id'] ?? $k ) : $k;
									if ( '_early_checkin' === $item_id ) {
										$has_early = true;
									}
									if ( '_late_checkout' === $item_id ) {
										$has_late = true;
									}
								}
							}
						}

						$early_fee_m = Money::fromDecimal( (string) get_option( 'mhbo_early_checkin_fee', '0' ), Pricing::get_currency_code() );
						$late_fee_m  = Money::fromDecimal( (string) get_option( 'mhbo_late_checkout_fee', '0' ), Pricing::get_currency_code() );

						// Dynamic status badge color & label
						$status_key   = $folio['status_key'] ?? ( $edit_amts['effective_payment_status'] ?? 'pending' );
						$status_label = $folio['status_label'] ?? ( $edit_amts['effective_payment_status_label'] ?? __( 'Pending', 'modern-hotel-booking' ) );
						$badge_bg     = '#f1f5f9';
						$badge_color  = '#475569';
						if ( ! empty( $folio['is_overpaid'] ) ) {
							$badge_bg    = '#e0f2fe';
							$badge_color = '#0369a1';
						} elseif ( ! empty( $folio['is_paid_in_full'] ) ) {
							$badge_bg    = '#dcfce7';
							$badge_color = '#166534';
						} elseif ( 'deposit_paid' === $status_key ) {
							$badge_bg    = '#fef3c7';
							$badge_color = '#92400e';
						} elseif ( in_array( $status_key, array( 'urgent_unpaid', 'overdue' ), true ) ) {
							$badge_bg    = '#fee2e2';
							$badge_color = '#991b1b';
						}
						?>

						<?php if ( $is_group_booking ) : ?>
							<?php
							$grp_is_settled  = ! empty( $group_amts['is_paid'] );
							$grp_badge_bg    = $grp_is_settled ? '#dcfce7' : '#fef3c7';
							$grp_badge_color = $grp_is_settled ? '#166534' : '#92400e';
							if ( $grp_is_settled ) {
								/* translators: %s: Formatted total settled amount */
								$grp_badge_lbl = sprintf( __( '✓ Group Settled (%s)', 'modern-hotel-booking' ), I18n::format_currency( $group_amts['total'] ) );
							} else {
								/* translators: %s: Formatted outstanding balance due */
								$grp_badge_lbl = sprintf( __( 'Group Due: %s', 'modern-hotel-booking' ), I18n::format_currency( $group_amts['remaining'] ) );
							}
							$grp_magic_base  = '';
							/* BUILD_PRO_START */
							if ( $is_pro_active && class_exists( \MHBO\Pro\BalancePayment::class ) ) {
								$grp_magic_base = \MHBO\Pro\BalancePayment::get_or_create_magic_url( (int) $edit_data->id, true );
							}
							/* BUILD_PRO_END */
							$grp_master_url = $grp_magic_base ? add_query_arg( 'pay_scope', 'group', $grp_magic_base ) : '';
							$grp_room_url   = $grp_magic_base ? add_query_arg( 'pay_scope', 'room', $grp_magic_base ) : '';
							?>
					<div class="mhbo-group-ribbon" style="background:#eef2ff; border:1px solid #c7d2fe; border-radius:12px; padding:16px 20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
						<div style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px;">
							<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
								<span style="font-size:24px; line-height:1;">👥</span>
								<div>
									<h3 style="margin:0; font-size:16px; font-weight:800; color:#1e1b4b; display:flex; align-items:center; gap:8px;">
										<span>
											<?php
											/* translators: %d: Number of rooms in group reservation */
											echo esc_html( sprintf( __( 'Group Reservation (%d Rooms in Party)', 'modern-hotel-booking' ), (int) $group_amts['rooms_count'] ) );
											?>
										</span>
										<span style="font-family:monospace; font-size:12px; font-weight:600; background:#e0e7ff; color:#3730a3; padding:2px 8px; border-radius:4px;">
											<?php echo esc_html( $group_amts['parent_token'] ); ?>
										</span>
										<span style="background:<?php echo esc_attr( $grp_badge_bg ); ?>; color:<?php echo esc_attr( $grp_badge_color ); ?>; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:700;">
											<?php echo esc_html( $grp_badge_lbl ); ?>
										</span>
									</h3>
									<div style="font-size:12px; color:#4338ca; margin-top:3px;">
										<span><strong><?php esc_html_e( 'Group Total:', 'modern-hotel-booking' ); ?></strong> <?php echo esc_html( I18n::format_currency( $group_amts['total'] ) ); ?></span>
										<span> &bull; </span>
										<span><strong><?php esc_html_e( 'Total Paid:', 'modern-hotel-booking' ); ?></strong> <?php echo esc_html( I18n::format_currency( $group_amts['received'] ) ); ?></span>
										<span> &bull; </span>
										<span><strong><?php esc_html_e( 'Remaining Balance:', 'modern-hotel-booking' ); ?></strong> <?php echo esc_html( I18n::format_currency( $group_amts['remaining'] ) ); ?></span>
									</div>
								</div>
							</div>

							<?php /* BUILD_PRO_START */ ?>
							<?php if ( $is_pro_active && class_exists( \MHBO\Pro\BalancePayment::class ) ) : ?>
								<div style="display:flex; flex-wrap:wrap; gap:8px;">
									<?php if ( ! empty( $grp_master_url ) ) : ?>
										<button type="button" class="button button-small mhbo-copy-trigger" data-copy="<?php echo esc_url( $grp_master_url ); ?>" title="<?php esc_attr_e( 'Copy collective group balance payment link for entire party', 'modern-hotel-booking' ); ?>">
											👥 <?php esc_html_e( 'Copy Group Link', 'modern-hotel-booking' ); ?>
										</button>
									<?php endif; ?>
									<?php if ( ! empty( $grp_room_url ) ) : ?>
										<button type="button" class="button button-small mhbo-copy-trigger" data-copy="<?php echo esc_url( $grp_room_url ); ?>" title="<?php esc_attr_e( 'Copy individual payment link strictly for this room', 'modern-hotel-booking' ); ?>">
											🛏️ <?php esc_html_e( 'Copy Room Link', 'modern-hotel-booking' ); ?>
										</button>
									<?php endif; ?>
								</div>
							<?php endif; ?>
							<?php /* BUILD_PRO_END */ ?>
						</div>

						<!-- Sibling Rooms Switcher Chips -->
						<div style="border-top:1px solid #c7d2fe; padding-top:10px; display:flex; flex-wrap:wrap; align-items:center; gap:8px;">
							<span style="font-size:12px; font-weight:700; color:#3730a3; margin-right:4px;"><?php esc_html_e( 'Rooms in this group:', 'modern-hotel-booking' ); ?></span>
							<?php foreach ( $group_amts['bookings'] as $sib ) : ?>
								<?php
								$is_curr_sib = ( (int) $sib->id === (int) $edit_data->id );
								$sib_amts    = Pricing::get_booking_payment_amounts( $sib );
								$sib_rnum    = ! empty( $sib->room_number ) ? 'Room ' . $sib->room_number : '#' . $sib->id;
								$sib_rname   = ! empty( $sib->room_name ) ? I18n::decode( $sib->room_name ) : '';
								$sib_label   = trim( $sib_rnum . ( '' !== $sib_rname ? ' (' . $sib_rname . ')' : '' ) );
								$sib_url     = wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=edit&id={$sib->id}" ), 'mhbo_edit_booking_' . $sib->id );
								?>
								<?php if ( $is_curr_sib ) : ?>
									<span style="background:#4338ca; color:#ffffff; font-weight:700; font-size:12px; padding:4px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:6px; box-shadow:0 1px 3px rgba(0,0,0,0.2);">
										🛏️ <?php echo esc_html( $sib_label ); ?> (<?php esc_html_e( 'This Room', 'modern-hotel-booking' ); ?>) <?php echo $sib_amts['is_paid'] ? '✓' : ''; ?>
									</span>
								<?php else : ?>
									<a href="<?php echo esc_url( $sib_url ); ?>" style="background:#ffffff; color:#3730a3; border:1px solid #a5b4fc; font-weight:600; font-size:12px; padding:4px 12px; border-radius:6px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; transition:all 0.15s ease;">
										🛏️ <?php echo esc_html( $sib_label ); ?> <?php echo $sib_amts['is_paid'] ? '✓' : ''; ?> &rarr;
									</a>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<!-- 1. Hero Header Strip -->
				<div class="mhbo-workstation-hero">
					<div class="mhbo-hero-identity">
						<div class="mhbo-hero-avatar">
							🏨
						</div>
						<div class="mhbo-hero-meta">
							<h2>
								<span><?php echo esc_html( $edit_data->customer_name ); ?></span>
								<span style="font-size: 15px; font-weight: 600; color: #64748b;">#<?php echo (int) $edit_data->id; ?></span>
								<span style="display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; text-transform: uppercase; background: <?php echo esc_attr( $badge_bg ); ?>; color: <?php echo esc_attr( $badge_color ); ?>;">
									<?php echo esc_html( $status_label ); ?>
								</span>
							</h2>
							<div class="mhbo-hero-subtitle">
								<span>📅 <?php echo esc_html( $folio['check_in_formatted'] ?? $edit_data->check_in ); ?> → <?php echo esc_html( $folio['check_out_formatted'] ?? $edit_data->check_out ); ?> (<?php echo (int) ( $folio['nights'] ?? 1 ); ?> <?php esc_html_e( 'nights', 'modern-hotel-booking' ); ?>)</span>
								<span>&bull;</span>
								<span>🛏️ <?php echo esc_html( $room_title ); ?><?php echo ! empty( $room_num ) ? ' (Room ' . esc_html( $room_num ) . ')' : ''; ?></span>
								<?php if ( $crm_guest && ! empty( $crm_guest->vip_status ) && 'none' !== $crm_guest->vip_status ) : ?>
									<span>&bull;</span>
									<span style="background: #fef3c7; color: #92400e; padding: 2px 7px; border-radius: 99px; font-size: 10px; font-weight: 800; text-transform: uppercase;">⭐ <?php echo esc_html( str_replace( '_', ' ', $crm_guest->vip_status ) ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<div class="mhbo-hero-actions">
								<?php /* BUILD_PRO_START */ ?>
								<?php if ( class_exists( Invoice::class ) ) : ?>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=download_invoice&id={$edit_data->id}" ), 'mhbo_invoice_' . $edit_data->id ) ); ?>" target="_blank" class="mhbo-hero-btn" title="<?php esc_attr_e( 'Open printable guest invoice', 'modern-hotel-booking' ); ?>">
								📄 <?php esc_html_e( 'View / Print Invoice', 'modern-hotel-booking' ); ?>
							</a>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=email_invoice&id={$edit_data->id}" ), 'mhbo_invoice_' . $edit_data->id ) ); ?>" class="mhbo-hero-btn" title="<?php esc_attr_e( 'Send invoice PDF to customer email', 'modern-hotel-booking' ); ?>" onclick="return confirm('<?php esc_attr_e( 'Send invoice to customer email?', 'modern-hotel-booking' ); ?>')">
								📧 <?php esc_html_e( 'Email Invoice', 'modern-hotel-booking' ); ?>
							</a>
						<?php endif; ?>
								<?php /* BUILD_PRO_END */ ?>
								<?php if ( ! empty( $edit_data->customer_phone ) ) : ?>
									<?php
									$clean_phone = (string) preg_replace( '/[^0-9+]/', '', (string) $edit_data->customer_phone );
									if ( '' !== $clean_phone ) :
										$wa_url = 'https://api.whatsapp.com/send?phone=' . rawurlencode( $clean_phone );
										?>
								<a href="<?php echo esc_url( $wa_url ); ?>" target="_blank" class="mhbo-hero-btn mhbo-hero-btn-whatsapp" title="<?php esc_attr_e( 'Chat with guest on WhatsApp', 'modern-hotel-booking' ); ?>">
									📱 <?php esc_html_e( 'WhatsApp', 'modern-hotel-booking' ); ?>
								</a>
									<?php endif; ?>
						<?php endif; ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ); ?>" class="mhbo-hero-btn">
							🔙 <?php esc_html_e( 'All Bookings', 'modern-hotel-booking' ); ?>
						</a>
					</div>
				</div>

				<form method="post" id="mhbo_booking_edit_form">
							<?php wp_nonce_field( 'mhbo_update_booking' ); ?>
					<input type="hidden" name="booking_id" value="<?php echo esc_attr( $edit_data->id ); ?>">
					<input type="hidden" name="total_price_snapshot" value="<?php echo esc_attr( Money::fromDecimal( (string) ( $edit_data->total_price ?? 0 ) )->toDecimal() ); ?>">
					<input type="hidden" name="record_payment_booking_id" value="<?php echo esc_attr( $edit_data->id ); ?>">
					<input type="hidden" name="mhbo_record_pay_nonce" value="<?php echo esc_attr( wp_create_nonce( 'mhbo_record_payment_' . $edit_data->id ) ); ?>">
							<?php if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) : ?>
						<input type="hidden" name="guest_id" value="<?php echo esc_attr( (string) $crm_guest_id ); ?>">
					<?php endif; ?>

					<div class="mhbo-workstation-grid">
						<!-- LEFT MAIN COLUMN (1.85fr) -->
						<div class="mhbo-workstation-col-main">

							<!-- Card 1: Guest Profile & Stay Details -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-admin-users" style="color:#0284c7;"></span> <?php esc_html_e( 'Guest & Reservation Details', 'modern-hotel-booking' ); ?></h3>
									<?php if ( $crm_guest && ! empty( $crm_guest->id ) ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-guests&guest_id=' . (int) $crm_guest->id ) ); ?>" target="_blank" class="button button-small">
											<?php esc_html_e( 'View CRM Profile ↗', 'modern-hotel-booking' ); ?>
										</a>
									<?php endif; ?>
								</div>
								<div class="mhbo-card-body-padded">
									<?php if ( $crm_guest && ! empty( $crm_guest->caution_flag ) ) : ?>
										<div style="background:#fef2f2; border:1px solid #f87171; border-radius:6px; padding:8px 12px; margin-bottom:16px; color:#991b1b; font-weight:600; font-size:12px; display:flex; align-items:center; gap:8px;">
											<span style="font-size:16px;">🚫</span>
											<span><?php esc_html_e( 'Caution: Problematic guest flagged by staff in CRM.', 'modern-hotel-booking' ); ?></span>
										</div>
									<?php endif; ?>

									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_edit_customer_name"><?php echo esc_html( I18n::get_label( 'label_customer_name' ) ); ?></label></th>
											<td><input type="text" id="mhbo_edit_customer_name" name="customer_name" value="<?php echo esc_attr( $edit_data->customer_name ); ?>" required class="regular-text" style="width:100%; max-width:420px;"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_customer_email"><?php echo esc_html( I18n::get_label( 'label_email' ) ); ?></label></th>
											<td><input type="email" id="mhbo_edit_customer_email" name="customer_email" value="<?php echo esc_attr( $edit_data->customer_email ); ?>" required class="regular-text" style="width:100%; max-width:420px;"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_customer_phone"><?php echo esc_html( I18n::get_label( 'label_phone' ) ); ?></label></th>
											<td><input type="tel" id="mhbo_edit_customer_phone" name="customer_phone" value="<?php echo esc_attr( $edit_data->customer_phone ?? '' ); ?>" class="regular-text" style="width:100%; max-width:420px;"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_guests"><?php echo esc_html( I18n::get_label( 'label_guests' ) ); ?></label></th>
											<td>
												<input type="number" name="guests" id="mhbo_edit_guests" value="<?php echo esc_attr( $edit_data->guests ?? 1 ); ?>" min="1" max="20" class="small-text">
												<span class="description"><?php esc_html_e( 'Adults (18+)', 'modern-hotel-booking' ); ?></span>
											</td>
										</tr>
										<?php /* BUILD_PRO_START */ ?>
										<?php $edit_children = max( 0, (int) ( $edit_data->children ?? 0 ) ); ?>
										<tr>
											<th scope="row"><label for="mhbo_edit_children"><?php echo esc_html( I18n::get_label( 'label_children' ) ); ?></label></th>
											<td>
												<input type="number" name="children" id="mhbo_edit_children" value="<?php echo esc_attr( (string) $edit_children ); ?>" min="0" max="20" class="small-text">
												<span class="description"><?php esc_html_e( 'Children under 18', 'modern-hotel-booking' ); ?></span>
											</td>
										</tr>
										<tr id="mhbo_edit_child_ages_row" style="<?php echo esc_attr( $edit_children > 0 ? '' : 'display:none;' ); ?>">
											<th scope="row"><?php echo esc_html( I18n::get_label( 'label_child_ages' ) ); ?></th>
											<td>
												<div id="mhbo_edit_child_ages_container" style="display:flex; flex-wrap:wrap; gap:8px;">
													<?php
													$saved_child_ages = array();
													if ( ! empty( $edit_data->child_ages ) ) {
														$decoded_ages = json_decode( (string) $edit_data->child_ages, true );
														if ( is_array( $decoded_ages ) ) {
															$saved_child_ages = $decoded_ages;
														}
													}
													for ( $i = 0; $i < $edit_children; $i++ ) {
														$age_val = isset( $saved_child_ages[ $i ] ) ? (int) $saved_child_ages[ $i ] : 0;
														?>
														<label style="display:inline-flex; align-items:center; gap:4px; font-size:12px;">
															<span>
																<?php
																/* translators: %d: Child sequence number */
																echo esc_html( sprintf( __( 'Child %d Age:', 'modern-hotel-booking' ), $i + 1 ) );
																?>
															</span>
															<input type="number" name="child_ages[]" value="<?php echo esc_attr( (string) $age_val ); ?>" min="0" max="17" class="small-text" style="width:50px;">
														</label>
														<?php
													}
													?>
												</div>
											</td>
										</tr>
												<?php /* BUILD_PRO_END */ ?>
										<tr>
											<th scope="row"><label for="mhbo_edit_room_id"><?php echo esc_html( I18n::get_label( 'label_room' ) ); ?></label></th>
											<td>
												<select name="room_id" id="mhbo_edit_room_id" style="width:100%; max-width:420px;">
													<?php foreach ( $all_rooms as $rm ) : ?>
														<option value="<?php echo esc_attr( $rm->id ); ?>" data-price="<?php echo esc_attr( $rm->base_price ); ?>" data-room-name="<?php echo esc_attr( $rm->room_number . ' (' . I18n::decode( $rm->type_name ) . ')' ); ?>" <?php selected( (int) $edit_data->room_id, (int) $rm->id ); ?>>
															<?php echo esc_html( $rm->room_number . ' (' . I18n::decode( $rm->type_name ) . ')' ); ?>
														</option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_check_in"><?php echo esc_html( I18n::get_label( 'label_check_in' ) ); ?></label></th>
											<td><input type="date" name="check_in" id="mhbo_edit_check_in" value="<?php echo esc_attr( $edit_data->check_in ); ?>" required></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_check_out"><?php echo esc_html( I18n::get_label( 'label_check_out' ) ); ?></label></th>
											<td><input type="date" name="check_out" id="mhbo_edit_check_out" value="<?php echo esc_attr( $edit_data->check_out ); ?>" required></td>
										</tr>

										<!-- Custom Fields -->
												<?php
												$custom_fields_defn = get_option( 'mhbo_custom_fields', array() );
												if ( isset( $custom_fields_defn ) && count( $custom_fields_defn ) > 0 ) :
													$saved_custom = ! empty( $edit_data->custom_fields ) ? json_decode( (string) $edit_data->custom_fields, true ) : array();
													?>
													<?php foreach ( $custom_fields_defn as $defn ) : ?>
														<?php
														$label   = (string) ( I18n::decode( I18n::encode( $defn['label'] ) ) ?? '' );
														$def_val = $saved_custom[ $defn['id'] ] ?? '';
														?>
												<tr>
													<th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
													<td>
														<?php if ( $defn['type'] === 'textarea' ) : ?>
															<textarea name="mhbo_custom[<?php echo esc_attr( $defn['id'] ); ?>]" rows="2" class="regular-text" style="width:100%; max-width:420px;"><?php echo esc_textarea( $def_val ); ?></textarea>
														<?php else : ?>
															<input type="<?php echo esc_attr( $defn['type'] ); ?>" name="mhbo_custom[<?php echo esc_attr( $defn['id'] ); ?>]" value="<?php echo esc_attr( $def_val ); ?>" class="regular-text" style="width:100%; max-width:420px;">
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
												<?php endif; ?>
									</table>
								</div>
							</div>

							<!-- Card 2: Stay Enhancements, Extras & Promotions -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<?php /* BUILD_FREE_START */ ?>
									<h3><span class="dashicons dashicons-tag" style="color:#f59e0b;"></span> <?php esc_html_e( 'Discounts & Concessions', 'modern-hotel-booking' ); ?></h3>
									<?php /* BUILD_FREE_END */ ?>
									<?php /* BUILD_STARTER_START */ ?>
									<h3><span class="dashicons dashicons-star-filled" style="color:#f59e0b;"></span> <?php esc_html_e( 'Stay Enhancements, Extras & Promotions', 'modern-hotel-booking' ); ?></h3>
									<?php /* BUILD_STARTER_END */ ?>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">
												<?php /* BUILD_PRO_START */ ?>
												<?php if ( License::is_pro_active() ) : ?>
													<?php if ( get_option( 'mhbo_early_checkin_enabled', 0 ) ) : ?>
												<tr>
													<th scope="row"><?php esc_html_e( 'Early Check-in', 'modern-hotel-booking' ); ?></th>
													<td>
														<label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
															<input type="checkbox" name="mhbo_extras[_early_checkin]" value="1" class="mhbo-extra-input" data-extra-id="_early_checkin" data-price="<?php echo esc_attr( $early_fee_m->toDecimal() ); ?>" data-pricing-type="per_day" <?php checked( $has_early ); ?>>
															<span>
																<?php
																/* translators: %s: formatted fee amount per day */
																echo esc_html( sprintf( __( 'Yes (+%s / day)', 'modern-hotel-booking' ), I18n::format_currency( $early_fee_m ) ) );
																?>
															</span>
														</label>
													</td>
												</tr>
											<?php endif; ?>
													<?php if ( get_option( 'mhbo_late_checkout_enabled', 0 ) ) : ?>
												<tr>
													<th scope="row"><?php esc_html_e( 'Late Check-out', 'modern-hotel-booking' ); ?></th>
													<td>
														<label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
															<input type="checkbox" name="mhbo_extras[_late_checkout]" value="1" class="mhbo-extra-input" data-extra-id="_late_checkout" data-price="<?php echo esc_attr( $late_fee_m->toDecimal() ); ?>" data-pricing-type="per_day" <?php checked( $has_late ); ?>>
															<span>
																<?php
																/* translators: %s: formatted fee amount per day */
																echo esc_html( sprintf( __( 'Yes (+%s / day)', 'modern-hotel-booking' ), I18n::format_currency( $late_fee_m ) ) );
																?>
															</span>
														</label>
													</td>
												</tr>
											<?php endif; ?>
										<?php endif; ?>
												<?php /* BUILD_PRO_END */ ?>

												<?php /* BUILD_PRO_START */ ?>
										<!-- Catalog Extras -->
										<tr>
											<th scope="row"><?php echo esc_html( I18n::get_label( 'label_extras' ) ); ?></th>
											<td>
														<?php
														$extras       = get_option( 'mhbo_pro_extras', array() );
														$saved_extras = Pricing::decode_stored_extras( $edit_data->booking_extras ?? null );
														$saved_map    = array();
														foreach ( $saved_extras as $se_k => $se ) {
															if ( isset( $se['id'] ) ) {
																$saved_map[ (string) $se['id'] ] = $se['quantity'] ?? 1;
															}
															if ( isset( $se['name'] ) ) {
																$saved_map[ $se['name'] ]                 = $se['quantity'] ?? 1;
																$saved_map[ sanitize_key( $se['name'] ) ] = $se['quantity'] ?? 1;
															}
															$saved_map[ (string) $se_k ] = $se['quantity'] ?? 1;
														}

														if ( count( $extras ) > 0 ) {
															foreach ( $extras as $ex ) {
																$extra_name   = (string) ( I18n::decode( $ex['name'] ?? '' ) ?? '' );
																$lbl          = esc_html( $extra_name ) . ' (' . esc_html( I18n::format_currency( $ex['price'] ) ) . ')';
																$qty          = $saved_map[ $ex['id'] ?? '' ] ?? ( $saved_map[ $ex['name'] ?? '' ] ?? ( $saved_map[ sanitize_key( $extra_name ) ] ?? 0 ) );
																$pricing_type = $ex['pricing_type'] ?? 'fixed';

																if ( isset( $ex['compulsory'] ) && $ex['compulsory'] ) {
																	echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;padding:4px 8px;background:#f0f4ff;border:1px solid #c7d7fd;border-radius:4px;width:fit-content;">';
																	echo '<span style="font-size:12px;">🔒</span>';
																	echo '<span style="font-size:13px;">' . $lbl . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
																	echo '<span style="font-size:11px;color:#5b72b8;font-weight:600;">' . esc_html__( '(Compulsory)', 'modern-hotel-booking' ) . '</span>';
																	echo '<input type="hidden" name="mhbo_extras[' . esc_attr( $ex['id'] ) . ']" value="1" class="mhbo-extra-input" data-extra-id="' . esc_attr( $ex['id'] ) . '" data-price="' . esc_attr( $ex['price'] ) . '" data-pricing-type="' . esc_attr( $pricing_type ) . '">';
																	echo '</div>';
																	continue;
																}

																if ( ( $ex['control_type'] ?? 'checkbox' ) === 'quantity' ) {
																	echo '<label style="display:flex; align-items:center; gap:8px; margin-bottom:6px;"><input type="number" name="mhbo_extras[' . esc_attr( $ex['id'] ) . ']" value="' . esc_attr( $qty ) . '" min="0" style="width:60px;" class="mhbo-extra-input" data-extra-id="' . esc_attr( $ex['id'] ) . '" data-price="' . esc_attr( $ex['price'] ) . '" data-pricing-type="' . esc_attr( $pricing_type ) . '"> <span>' . $lbl . '</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
																} else {
																	echo '<label style="display:flex; align-items:center; gap:8px; margin-bottom:6px;"><input type="checkbox" name="mhbo_extras[' . esc_attr( $ex['id'] ) . ']" value="1" ' . checked( $qty > 0, true, false ) . ' class="mhbo-extra-input" data-extra-id="' . esc_attr( $ex['id'] ) . '" data-price="' . esc_attr( $ex['price'] ) . '" data-pricing-type="' . esc_attr( $pricing_type ) . '"> <span>' . $lbl . '</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
																}
															}
														} else {
															echo '<span class="description">' . esc_html( I18n::get_label( 'label_no_extras_config' ) ) . '</span>';
														}
														?>
											</td>
										</tr>
												<?php /* BUILD_PRO_END */ ?>

										<!-- Promotions & Coupons -->
												<?php /* BUILD_PRO_START */ ?>
										<tr>
											<th scope="row"><label for="mhbo_edit_coupon_code"><?php esc_html_e( 'Coupon / Promo Code', 'modern-hotel-booking' ); ?></label></th>
											<td>
												<input type="text" name="coupon_code" id="mhbo_edit_coupon_code" value="<?php echo esc_attr( $edit_data->coupon_code ?? '' ); ?>" class="regular-text" style="max-width:200px; text-transform:uppercase;" placeholder="<?php esc_attr_e( 'e.g. SUMMER20', 'modern-hotel-booking' ); ?>">
												<?php if ( ! empty( $edit_data->coupon_code ) ) : ?>
													<span style="display:inline-block; margin-left:8px; padding:2px 8px; border-radius:12px; background:#dcfce7; color:#166534; font-weight:700; font-size:11px;">
														✓ <?php esc_html_e( 'Applied', 'modern-hotel-booking' ); ?>
													</span>
												<?php endif; ?>
											</td>
										</tr>
												<?php /* BUILD_PRO_END */ ?>

										<!-- Manual Concession / Discount -->
										<tr>
											<th scope="row"><label for="mhbo_edit_discount_amount"><?php echo esc_html( I18n::get_label( 'label_discount_amount' ) ); ?></label></th>
											<td>
												<div style="display:flex; align-items:center; gap:8px;">
													<input type="number" step="any" name="discount_amount" id="mhbo_edit_discount_amount" value="<?php echo esc_attr( Money::fromDecimal( (string) ( $edit_data->discount_amount ?? 0 ) )->toDecimal() ); ?>" class="small-text" style="width:110px;">
													<span class="description" style="font-weight:600;"><?php echo esc_html( $currency ); ?></span>
												</div>
												<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Direct discount or courtesy rate adjustment.', 'modern-hotel-booking' ); ?></p>
											</td>
										</tr>

												<?php /* BUILD_STARTER_START */ ?>
										<!-- Tax Mode Selection -->
										<tr>
											<th scope="row"><label for="mhbo_edit_tax_mode"><?php esc_html_e( 'Tax Mode', 'modern-hotel-booking' ); ?></label></th>
											<td>
												<select name="tax_mode" id="mhbo_edit_tax_mode" style="min-width:200px;">
													<option value="vat" <?php selected( in_array( $edit_tax_mode, array( 'vat', 'inclusive' ), true ) ); ?>><?php esc_html_e( 'VAT (Inclusive)', 'modern-hotel-booking' ); ?></option>
													<option value="sales_tax" <?php selected( in_array( $edit_tax_mode, array( 'sales_tax', 'exclusive' ), true ) ); ?>><?php esc_html_e( 'Sales Tax / GST (Exclusive)', 'modern-hotel-booking' ); ?></option>
													<option value="disabled" <?php selected( $edit_tax_mode, 'disabled' ); ?>><?php esc_html_e( 'Disabled (No Tax)', 'modern-hotel-booking' ); ?></option>
												</select>
												<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Folio taxation regime for this reservation. Toggling recalculates itemized taxes.', 'modern-hotel-booking' ); ?></p>
											</td>
										</tr>
												<?php /* BUILD_STARTER_END */ ?>
									</table>
								</div>
							</div>

							<!-- Card 3: Itemized Charges & Folio Breakdown -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-media-spreadsheet" style="color:#3b82f6;"></span> <?php esc_html_e( 'Charges & Folio Breakdown', 'modern-hotel-booking' ); ?></h3>
									<span style="font-size:12px; font-weight:600; color:#64748b;"><?php esc_html_e( 'Room rates, extras & taxes', 'modern-hotel-booking' ); ?></span>
								</div>
								<div class="mhbo-table-responsive" style="overflow-x:auto;">
									<table class="mhbo-charges-table" id="mhbo_edit_folio_table">
										<thead>
											<tr>
												<th scope="col" style="width:110px;"><?php esc_html_e( 'Category', 'modern-hotel-booking' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Charge Description', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:100px;"><?php esc_html_e( 'Rate', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:center; width:90px;"><?php esc_html_e( 'Qty / Nights', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:90px;"><?php esc_html_e( 'Tax', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:110px;"><?php esc_html_e( 'Total', 'modern-hotel-booking' ); ?></th>
											</tr>
										</thead>
										<tbody id="mhbo_edit_folio_tbody">
													<?php if ( empty( $folio['charges'] ) ) : ?>
												<tr>
													<td colspan="6" style="text-align:center; padding:20px; color:#64748b;">
														<?php esc_html_e( 'No billable charge items recorded.', 'modern-hotel-booking' ); ?>
													</td>
												</tr>
											<?php else : ?>
												<?php foreach ( $folio['charges'] as $c ) : ?>
													<?php
													$cat_class = match ( $c['category'] ?? '' ) {
														'room'           => 'mhbo-cat-room',
														'children'       => 'mhbo-cat-children',
														'early_late'     => 'mhbo-cat-room',
														'extra'          => 'mhbo-cat-extra',
														'service', 'fee' => 'mhbo-cat-service',
														'discount'       => 'mhbo-cat-discount',
														default          => 'mhbo-cat-adjustment',
													};
													$cat_lbl = $c['category_label'] ?? match ( $c['category'] ?? '' ) {
														'room'           => __( 'Accommodation', 'modern-hotel-booking' ),
														'children'       => __( 'Child Supplement', 'modern-hotel-booking' ),
														'early_late'     => __( 'Stay Extension', 'modern-hotel-booking' ),
														'extra'          => __( 'Stay Enhancement', 'modern-hotel-booking' ),
														'service', 'fee' => __( 'Service Fee', 'modern-hotel-booking' ),
														'discount'       => __( 'Discount / Concession', 'modern-hotel-booking' ),
														'adjustment'     => __( 'Folio Adjustment', 'modern-hotel-booking' ),
														default          => __( 'Charge Item', 'modern-hotel-booking' ),
													};
													$c_name  = $c['name'] ?? ( $c['label'] ?? '' );
													$c_qty   = $c['quantity'] ?? ( $c['qty'] ?? 1 );
													$c_tot   = $c['amount_formatted'] ?? ( $c['total_formatted'] ?? '' );
													$is_disc = ( $c['amount'] ?? ( $c['total'] ?? 0 ) ) < 0;
	?>
													<tr>
														<td>
															<span class="mhbo-charge-category-badge <?php echo esc_attr( $cat_class ); ?>">
																<?php echo esc_html( $cat_lbl ); ?>
															</span>
														</td>
														<td>
															<strong><?php echo esc_html( $c_name ); ?></strong>
															<?php if ( ! empty( $c['details'] ) ) : ?>
																<div style="font-size:11px; color:#64748b; margin-top:2px;"><?php echo esc_html( $c['details'] ); ?></div>
															<?php endif; ?>
														</td>
														<td style="text-align:right; font-variant-numeric:tabular-nums;"><?php echo esc_html( $c['unit_price_formatted'] ); ?></td>
														<td style="text-align:center; font-variant-numeric:tabular-nums;"><?php echo esc_html( (string) $c_qty ); ?></td>
														<td style="text-align:right; color:#64748b; font-size:12px; font-variant-numeric:tabular-nums;"><?php echo esc_html( ( isset( $c['tax_amount'] ) && ! Money::fromDecimal( (string) $c['tax_amount'], Pricing::get_currency_code() )->isPositive() ) ? '—' : $c['tax_formatted'] ); ?></td>
														<td style="text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:<?php echo $is_disc ? '#166534' : '#0f172a'; ?>;">
															<?php echo esc_html( $c_tot ); ?>
														</td>
													</tr>
												<?php endforeach; ?>
											<?php endif; ?>
										</tbody>
										<tfoot style="background:#f8fafc; border-top:2px solid #cbd5e1; font-weight:600;">
													<?php $show_tax_rows = ! empty( $folio['tax_enabled'] ) && ! empty( $folio['total_tax'] ) && Money::fromDecimal( (string) $folio['total_tax'], Pricing::get_currency_code() )->isPositive(); ?>
											<tr id="mhbo_edit_folio_foot_subtotal_row" class="<?php echo ! $show_tax_rows ? 'mhbo-hidden' : ''; ?>" style="<?php echo ! $show_tax_rows ? 'display:none !important;' : ''; ?>" <?php echo ! $show_tax_rows ? 'hidden' : ''; ?>>
												<td colspan="5" style="text-align:right; padding:8px 12px; color:#64748b; font-size:12px;" id="mhbo_edit_folio_foot_subtotal_label">
													<?php
													if ( ! empty( $folio['is_vat'] ) ) {
														/* translators: %s: Tax label */
														echo esc_html( sprintf( I18n::get_label( 'label_subtotal_net_vat' ), $folio['base_tax_label'] ?? 'VAT' ) );
													} else {
														esc_html_e( 'Subtotal Net (excl. tax):', 'modern-hotel-booking' );
													}
													?>
												</td>
												<td style="text-align:right; padding:8px 12px; font-variant-numeric:tabular-nums;" id="mhbo_edit_folio_foot_subtotal_net"><?php echo esc_html( $folio['subtotal_net_formatted'] ?? '' ); ?></td>
											</tr>
											<tr id="mhbo_edit_folio_foot_tax_row" class="<?php echo ! $show_tax_rows ? 'mhbo-hidden' : ''; ?>" style="<?php echo ! $show_tax_rows ? 'display:none !important;' : ''; ?>" <?php echo ! $show_tax_rows ? 'hidden' : ''; ?>>
												<td colspan="5" style="text-align:right; padding:6px 12px; color:#64748b; font-size:12px;" id="mhbo_edit_folio_foot_tax_label">
													<?php
													if ( ! empty( $folio['is_vat'] ) ) {
														echo esc_html( ( $folio['base_tax_label'] ?? 'VAT' ) . ' (included):' );
													} else {
														/* translators: %s: Tax category label */
														echo esc_html( sprintf( __( 'Taxes (%s):', 'modern-hotel-booking' ), $folio['base_tax_label'] ?? ( $folio['tax_label'] ?? 'Tax' ) ) );
													}
													?>
												</td>
												<td style="text-align:right; padding:6px 12px; font-variant-numeric:tabular-nums; color:#64748b;" id="mhbo_edit_folio_foot_tax"><?php echo esc_html( $folio['total_tax_formatted'] ?? '' ); ?></td>
											</tr>
											<tr style="border-top:1px solid #e2e8f0; font-size:14px;">
												<td colspan="5" style="text-align:right; padding:12px; font-weight:800; color:#0f172a;" id="mhbo_edit_folio_foot_total_label">
													<?php
													if ( $show_tax_rows && ! empty( $folio['is_vat'] ) ) {
														esc_html_e( 'Total Charges (VAT incl.):', 'modern-hotel-booking' );
													} else {
														esc_html_e( 'Total Charges:', 'modern-hotel-booking' );
													}
													?>
												</td>
												<td style="text-align:right; padding:12px; font-weight:800; color:#0f172a; font-size:16px; font-variant-numeric:tabular-nums;" id="mhbo_edit_folio_foot_total"><?php echo esc_html( $folio['total_charges_formatted'] ?? '' ); ?></td>
											</tr>
										</tfoot>
									</table>
								</div>
							</div>

							<!-- Card 4: Payment Ledger & Transactions -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-money-alt" style="color:#10b981;"></span> <?php esc_html_e( 'Payment Ledger & Transactions', 'modern-hotel-booking' ); ?></h3>
									<span style="font-size:12px; font-weight:600; color:#166534;"><?php esc_html_e( 'Verified Transactions', 'modern-hotel-booking' ); ?></span>
								</div>
								<div class="mhbo-table-responsive" style="overflow-x:auto;">
									<table class="mhbo-ledger-table">
										<thead>
											<tr>
												<th scope="col" style="width:140px;"><?php esc_html_e( 'Date / Time', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="width:110px;"><?php esc_html_e( 'Type', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="width:120px;"><?php esc_html_e( 'Method', 'modern-hotel-booking' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Txn / Gateway Ref', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="width:110px;"><?php esc_html_e( 'Source', 'modern-hotel-booking' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Memo / Notes', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:110px;"><?php esc_html_e( 'Amount', 'modern-hotel-booking' ); ?></th>
											</tr>
										</thead>
										<tbody>
													<?php if ( empty( $folio['payments'] ) ) : ?>
												<tr>
													<td colspan="7" style="text-align:center; padding:24px; color:#64748b;">
														<?php esc_html_e( 'No payments or transactions recorded in folio yet.', 'modern-hotel-booking' ); ?>
													</td>
												</tr>
											<?php else : ?>
												<?php
												$group_txn_allocs = ( $is_group_booking && ! empty( $group_amts['bookings'] ) ) ? Pricing::get_group_transaction_allocations( $group_amts['bookings'] ) : array();
												?>
												<?php foreach ( $folio['payments'] as $p ) : ?>
													<?php
													$is_ref       = ! empty( $p['is_refund'] );
													$p_txn_id     = trim( (string) ( $p['transaction_id'] ?? '' ) );
													$is_split_txn = ( '' !== $p_txn_id && isset( $group_txn_allocs[ $p_txn_id ] ) && ! empty( $group_txn_allocs[ $p_txn_id ]['is_split'] ) );
													?>
													<tr>
														<td style="font-size:12px; color:#64748b;"><?php echo esc_html( $p['date_formatted'] ); ?></td>
														<td>
															<span style="display:inline-block; padding:2px 7px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; background:<?php echo $is_ref ? '#fee2e2' : '#dcfce7'; ?>; color:<?php echo $is_ref ? '#991b1b' : '#166534'; ?>;">
																<?php echo esc_html( $p['type_label'] ); ?>
															</span>
														</td>
														<td style="font-size:12px;"><?php echo esc_html( $p['method_label'] ); ?></td>
														<td style="font-size:11px; font-family:monospace; color:#475569;">
															<?php echo esc_html( $p['transaction_id'] ?: '—' ); ?>
															<?php if ( $is_split_txn ) : ?>
																<div>
																	<span style="display:inline-block; margin-top:3px; padding:1px 6px; border-radius:4px; font-size:10px; font-weight:700; background:#e0e7ff; color:#3730a3;">
																		👥 <?php esc_html_e( 'Group Split Payment', 'modern-hotel-booking' ); ?>
																	</span>
																</div>
															<?php endif; ?>
														</td>
														<td style="font-size:12px;"><?php echo esc_html( $p['staff'] ); ?></td>
														<td style="font-size:12px; color:#64748b;">
															<?php echo esc_html( $p['note'] ?: '—' ); ?>
															<?php if ( $is_split_txn ) : ?>
																<div style="font-size:11px; color:#4338ca; margin-top:2px;">
																	<?php
																	/* translators: %s: Formatted total group transaction amount */
																	echo esc_html( sprintf( __( 'Allocated from %s total group transaction', 'modern-hotel-booking' ), I18n::format_currency( $group_txn_allocs[ $p_txn_id ]['total_amount'] ) ) );
																	?>
																</div>
															<?php endif; ?>
														</td>
														<td style="text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:<?php echo $is_ref ? '#dc2626' : '#166534'; ?>;">
															<?php echo esc_html( $p['amount_formatted'] ); ?>
														</td>
													</tr>
												<?php endforeach; ?>
											<?php endif; ?>
										</tbody>
										<tfoot style="background:#f8fafc; border-top:2px solid #cbd5e1; font-weight:700;">
											<tr>
												<td colspan="6" style="text-align:right; padding:10px 12px; color:#166534; font-size:13px;"><?php esc_html_e( 'Total Payments Received:', 'modern-hotel-booking' ); ?></td>
												<td style="text-align:right; padding:10px 12px; color:#166534; font-size:15px; font-variant-numeric:tabular-nums;"><?php echo esc_html( (string) ( $folio['total_paid_formatted'] ?? '' ) ); ?></td>
											</tr>
											<?php $has_rem_due = isset( $folio['balance_remaining'] ) && Money::fromDecimal( (string) $folio['balance_remaining'], Pricing::get_currency_code() )->isPositive(); ?>
											<tr>
												<td colspan="6" style="text-align:right; padding:10px 12px; color:<?php echo ! empty( $folio['is_overpaid'] ) ? '#0284c7' : ( $has_rem_due ? '#dc2626' : '#166534' ); ?>; font-size:13px;" id="mhbo_ledger_balance_due_label">
															<?php echo ! empty( $folio['is_overpaid'] ) ? esc_html__( 'Credit Balance:', 'modern-hotel-booking' ) : esc_html__( 'Balance Due:', 'modern-hotel-booking' ); ?>
												</td>
												<td style="text-align:right; padding:10px 12px; font-size:16px; font-weight:800; font-variant-numeric:tabular-nums; color:<?php echo ! empty( $folio['is_overpaid'] ) ? '#0284c7' : ( $has_rem_due ? '#dc2626' : '#166534' ); ?>;" id="mhbo_ledger_balance_due_val">
															<?php echo ! empty( $folio['is_overpaid'] ) ? '−' . esc_html( (string) ( $folio['credit_balance_formatted'] ?? '' ) ) : esc_html( (string) ( $folio['balance_remaining_formatted'] ?? '' ) ); ?>
												</td>
											</tr>
										</tfoot>
									</table>
								</div>
							</div>

							<!-- Card 5: Record Payment or Refund -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-insert" style="color:#6366f1;"></span> <?php esc_html_e( 'Record Payment or Refund', 'modern-hotel-booking' ); ?></h3>
									<span style="font-size:12px; color:#64748b;"><?php esc_html_e( 'Front desk cashier', 'modern-hotel-booking' ); ?></span>
								</div>
								<div class="mhbo-card-body-padded">
									<!-- Quick Tender Action Buttons -->
									<div class="mhbo-quick-tender-bar">
												<?php if ( $has_rem_due ) : ?>
											<button type="button" class="button button-primary button-small mhbo-quick-pay-btn" data-amount="<?php echo esc_attr( (string) ( $folio['balance_remaining'] ?? 0 ) ); ?>" data-type="balance" data-note="<?php esc_attr_e( 'Settlement of remaining balance', 'modern-hotel-booking' ); ?>">
												⚡ <?php esc_html_e( 'Pay Remaining Balance', 'modern-hotel-booking' ); ?> (<?php echo esc_html( (string) ( $folio['balance_remaining_formatted'] ?? '' ) ); ?>)
											</button>
										<?php endif; ?>

												<?php if ( ! empty( $folio['deposit_required'] ) && empty( $folio['deposit_received'] ) ) : ?>
											<button type="button" class="button button-secondary button-small mhbo-quick-pay-btn" data-amount="<?php echo esc_attr( (string) $folio['deposit_amount'] ); ?>" data-type="deposit" data-note="<?php esc_attr_e( 'Deposit collection', 'modern-hotel-booking' ); ?>">
												⚡ <?php esc_html_e( 'Collect Deposit', 'modern-hotel-booking' ); ?> (<?php echo esc_html( $folio['deposit_amount_formatted'] ); ?>)
											</button>
										<?php endif; ?>

												<?php if ( ! empty( $folio['is_overpaid'] ) ) : ?>
											<button type="button" class="button button-secondary button-small mhbo-quick-pay-btn" data-amount="<?php echo esc_attr( (string) $folio['credit_balance'] ); ?>" data-type="refund" data-note="<?php esc_attr_e( 'Stay modification refund', 'modern-hotel-booking' ); ?>" style="color:#0369a1; border-color:#7dd3fc; background:#f0f9ff;">
												⚡ <?php esc_html_e( 'Issue / Record Refund', 'modern-hotel-booking' ); ?> (<?php echo esc_html( $folio['credit_balance_formatted'] ); ?>)
											</button>
										<?php endif; ?>
									</div>

									<!-- Cashier Tender Form Fields -->
									<div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:16px;">
										<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
											<div>
												<label for="mhbo_record_payment_amount" style="display:block; font-size:12px; font-weight:700; margin-bottom:4px; color:#334155;"><?php esc_html_e( 'Amount', 'modern-hotel-booking' ); ?> (<?php echo esc_html( $currency ); ?>):</label>
												<input type="number" step="any" min="0.01" name="record_payment_amount" id="mhbo_record_payment_amount" class="regular-text" style="width:100%; height:34px;" placeholder="0.00">
											</div>
											<div>
												<label for="mhbo_record_payment_method" style="display:block; font-size:12px; font-weight:700; margin-bottom:4px; color:#334155;"><?php esc_html_e( 'Payment Method:', 'modern-hotel-booking' ); ?></label>
												<select name="record_payment_method" id="mhbo_record_payment_method" style="width:100%; height:34px;">
													<option value="arrival"><?php esc_html_e( '💵 Cash / Terminal at Desk', 'modern-hotel-booking' ); ?></option>
													<option value="credit_card"><?php esc_html_e( '💳 Credit Card', 'modern-hotel-booking' ); ?></option>
													<option value="bank_transfer"><?php esc_html_e( '🏦 Bank Transfer / Wire', 'modern-hotel-booking' ); ?></option>
													<option value="stripe"><?php esc_html_e( 'Stripe Online', 'modern-hotel-booking' ); ?></option>
													<option value="paypal"><?php esc_html_e( 'PayPal', 'modern-hotel-booking' ); ?></option>
													<option value="other"><?php esc_html_e( 'Other', 'modern-hotel-booking' ); ?></option>
												</select>
											</div>
											<div>
												<label for="mhbo_record_payment_type" style="display:block; font-size:12px; font-weight:700; margin-bottom:4px; color:#334155;"><?php esc_html_e( 'Payment Type:', 'modern-hotel-booking' ); ?></label>
												<select name="record_payment_type" id="mhbo_record_payment_type" style="width:100%; height:34px;">
													<option value="payment"><?php esc_html_e( 'Standard Payment', 'modern-hotel-booking' ); ?></option>
													<option value="deposit"><?php esc_html_e( 'Deposit', 'modern-hotel-booking' ); ?></option>
													<option value="balance"><?php esc_html_e( 'Balance Settlement', 'modern-hotel-booking' ); ?></option>
													<option value="incidentals"><?php esc_html_e( 'Incidentals / Extras', 'modern-hotel-booking' ); ?></option>
													<option value="refund"><?php esc_html_e( 'Refund / Credit', 'modern-hotel-booking' ); ?></option>
												</select>
											</div>
											<div>
												<label for="mhbo_record_payment_txn_id" style="display:block; font-size:12px; font-weight:700; margin-bottom:4px; color:#334155;"><?php esc_html_e( 'Transaction / Reference ID:', 'modern-hotel-booking' ); ?></label>
												<input type="text" name="record_payment_txn_id" id="mhbo_record_payment_txn_id" class="regular-text" style="width:100%; height:34px;" placeholder="<?php esc_attr_e( 'e.g. pi_..., ch_...', 'modern-hotel-booking' ); ?>">
											</div>
										</div>
										<div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:12px; align-items:end;">
											<div style="flex:1; min-width:240px;">
												<label for="mhbo_record_payment_note" style="display:block; font-size:12px; font-weight:700; margin-bottom:4px; color:#334155;"><?php esc_html_e( 'Notes / Memo:', 'modern-hotel-booking' ); ?></label>
												<input type="text" name="record_payment_note" id="mhbo_record_payment_note" class="regular-text" style="width:100%; height:34px;" placeholder="<?php esc_attr_e( 'e.g. Cash collected at front desk', 'modern-hotel-booking' ); ?>">
											</div>
											<div style="display:flex; align-items:center; height:34px;">
												<label style="display:flex; align-items:center; gap:6px; font-size:13px; font-weight:500; color:#334155; cursor:pointer; margin:0;">
													<input type="checkbox" name="record_payment_send_email" id="mhbo_record_payment_send_email" value="1" checked style="margin:0;">
													<?php esc_html_e( 'Send receipt to guest & admin', 'modern-hotel-booking' ); ?>
												</label>
											</div>
											<div>
												<input type="submit" name="submit_record_folio_payment" class="button button-secondary" value="<?php esc_attr_e( 'Record Payment', 'modern-hotel-booking' ); ?>" style="height:34px; font-weight:600;">
											</div>
										</div>
									</div>
								</div>
							</div>

						</div><!-- End .mhbo-workstation-col-main -->

						<!-- RIGHT SIDEBAR (1.15fr) -->
						<div class="mhbo-workstation-col-sidebar">

							<!-- Card 1: Financial & Collection Overview KPIs -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-chart-pie" style="color:#0284c7;"></span> <?php esc_html_e( 'Financial Overview', 'modern-hotel-booking' ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<div class="mhbo-kpi-grid">
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Total Charges', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value" id="mhbo_overview_total_val"><?php echo esc_html( (string) ( $folio['total_charges_formatted'] ?? '' ) ); ?></span>
										</div>
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Total Paid', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value mhbo-kpi-value-paid" id="mhbo_overview_paid_val"><?php echo esc_html( (string) ( $folio['total_paid_formatted'] ?? '' ) ); ?></span>
										</div>
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Outstanding', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value <?php echo ( ! empty( $has_rem_due ) ) ? 'mhbo-kpi-value-due' : ''; ?>" id="mhbo_overview_pending_val"><?php echo esc_html( (string) ( $folio['balance_remaining_formatted'] ?? '' ) ); ?></span>
										</div>
										<div class="mhbo-kpi-tile" id="mhbo_overview_credit_tile" style="display:<?php echo ! empty( $folio['is_overpaid'] ) ? 'block' : 'none'; ?>;">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Credit Balance', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value mhbo-kpi-value-credit" id="mhbo_overview_credit_val"><?php echo esc_html( (string) ( $folio['credit_balance_formatted'] ?? '' ) ); ?></span>
										</div>
									</div>
								</div>
							</div>

									<?php if ( $is_group_booking && null !== $group_amts ) : ?>
								<!-- Consolidated Group Finances (Law 52) -->
								<div class="mhbo-workstation-card" style="border:1px solid #c7d2fe; background:#f8faff;">
									<div class="mhbo-card-header-bar" style="background:#eef2ff; border-bottom:1px solid #c7d2fe;">
										<h3 style="color:#1e1b4b;"><span class="dashicons dashicons-groups" style="color:#4338ca;"></span> <?php esc_html_e( 'Consolidated Group Finances', 'modern-hotel-booking' ); ?></h3>
										<span style="font-family:monospace; font-size:11px; font-weight:700; background:#e0e7ff; color:#3730a3; padding:1px 6px; border-radius:4px;">
											<?php echo esc_html( $group_amts['parent_token'] ); ?>
										</span>
									</div>
									<div class="mhbo-card-body-padded">
										<div class="mhbo-kpi-grid">
											<div class="mhbo-kpi-tile">
												<span class="mhbo-kpi-label"><?php esc_html_e( 'Group Total', 'modern-hotel-booking' ); ?></span>
												<span class="mhbo-kpi-value"><?php echo esc_html( I18n::format_currency( $group_amts['total'] ) ); ?></span>
											</div>
											<div class="mhbo-kpi-tile">
												<span class="mhbo-kpi-label"><?php esc_html_e( 'Total Paid', 'modern-hotel-booking' ); ?></span>
												<span class="mhbo-kpi-value mhbo-kpi-value-paid"><?php echo esc_html( I18n::format_currency( $group_amts['received'] ) ); ?></span>
											</div>
											<div class="mhbo-kpi-tile" style="grid-column: span 2;">
												<span class="mhbo-kpi-label"><?php esc_html_e( 'Group Balance Due', 'modern-hotel-booking' ); ?></span>
												<span class="mhbo-kpi-value <?php echo $group_amts['remaining']->isPositive() ? 'mhbo-kpi-value-due' : 'mhbo-kpi-value-paid'; ?>">
													<?php echo $group_amts['remaining']->isPositive() ? esc_html( I18n::format_currency( $group_amts['remaining'] ) ) : esc_html__( '✓ Group Settled in Full', 'modern-hotel-booking' ); ?>
												</span>
											</div>
										</div>
										<?php if ( ! empty( $grp_master_url ) ) : ?>
											<div style="margin-top:12px; padding-top:12px; border-top:1px solid #e0e7ff;">
												<label style="display:block; font-size:11px; font-weight:700; color:#3730a3; margin-bottom:4px;">
													<?php esc_html_e( 'Master Group Payment Portal URL:', 'modern-hotel-booking' ); ?>
												</label>
												<div style="display:flex; gap:6px;">
													<input type="text" readonly value="<?php echo esc_url( $grp_master_url ); ?>" style="width:100%; font-size:11px; padding:4px 8px; border:1px solid #c7d2fe; border-radius:4px; background:#fff;" onclick="this.select();">
													<button type="button" class="button button-small mhbo-copy-trigger" data-copy="<?php echo esc_url( $grp_master_url ); ?>"><?php esc_html_e( 'Copy', 'modern-hotel-booking' ); ?></button>
												</div>
											</div>
										<?php endif; ?>
									</div>
								</div>
							<?php endif; ?>

							<!-- Card 2: Billing & Payment Details -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-admin-generic" style="color:#475569;"></span> <?php esc_html_e( 'Billing & Payment Details', 'modern-hotel-booking' ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_edit_total_price"><?php echo esc_html( I18n::get_label( 'label_total_price' ) ); ?></label></th>
											<td>
												<input type="number" step="any" name="total_price" id="mhbo_edit_total_price" value="<?php echo esc_attr( Money::fromDecimal( (string) ( $edit_data->total_price ?? 0 ) )->toDecimal() ); ?>" required class="regular-text" style="width:100%;">
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_deposit_amount"><?php echo esc_html( I18n::get_label( 'label_deposit_amount' ) ); ?></label></th>
											<td>
												<input type="number" step="any" name="deposit_amount" id="mhbo_edit_deposit_amount" value="<?php echo esc_attr( Money::fromDecimal( (string) ( $edit_data->deposit_amount ?? 0 ) )->toDecimal() ); ?>" class="regular-text" style="width:100%;">
											</td>
										</tr>
										<tr>
											<th scope="row"><?php echo esc_html( I18n::get_label( 'label_deposit_rcvd' ) ); ?></th>
											<td>
												<label style="display:flex; align-items:center; gap:8px;">
													<input type="hidden" name="deposit_received" value="0">
													<input type="checkbox" name="deposit_received" id="mhbo_edit_deposit_received" value="1" <?php checked( (bool) ( $edit_data->deposit_received ?? false ) ); ?>>
													<span><?php echo esc_html( I18n::get_label( 'label_mark_deposit_rcvd' ) ); ?></span>
												</label>
											</td>
										</tr>
										<tr>
											<th scope="row"><label><?php echo esc_html( I18n::get_label( 'label_payment_method' ) ); ?></label></th>
											<td>
												<select name="payment_method" style="width:100%;">
													<option value="arrival" <?php selected( $edit_data->payment_method ?? 'arrival', 'arrival' ); ?>>
																<?php echo esc_html( I18n::get_payment_method_label( 'arrival' ) ); ?>
													</option>
															<?php
															$show_commercial_gateways = false;
															/* BUILD_STARTER_START */
															$show_commercial_gateways = class_exists( '\MHBO\Core\Tier' ) ? \MHBO\Core\Tier::has_feature( 'payments_stripe' ) : License::is_starter_active();
															/* BUILD_STARTER_END */
															if ( $show_commercial_gateways ) :
																?>
														<option value="stripe" <?php selected( $edit_data->payment_method ?? '', 'stripe' ); ?>>
																<?php echo esc_html( I18n::get_label( 'gateway_stripe' ) ); ?>
														</option>
														<option value="paypal" <?php selected( $edit_data->payment_method ?? '', 'paypal' ); ?>>
																<?php echo esc_html( I18n::get_label( 'gateway_paypal' ) ); ?>
														</option>
														<option value="braintree" <?php selected( $edit_data->payment_method ?? '', 'braintree' ); ?>>
																<?php echo esc_html( I18n::get_label( 'label_braintree' ) ); ?>
														</option>
															<?php endif; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label><?php echo esc_html( I18n::get_label( 'label_payment_status' ) ); ?></label></th>
											<td>
												<select name="payment_status" id="mhbo_edit_payment_status" style="width:100%;">
													<option value="pending" <?php selected( $edit_data->payment_status ?? 'pending', 'pending' ); ?>><?php echo esc_html( I18n::get_label( 'status_pending' ) ); ?></option>
													<option value="deposit_paid" <?php selected( $edit_data->payment_status ?? '', 'deposit_paid' ); ?>><?php echo esc_html( I18n::get_label( 'label_deposit_paid' ) ); ?></option>
													<option value="processing" <?php selected( $edit_data->payment_status ?? '', 'processing' ); ?>><?php echo esc_html( I18n::get_label( 'status_processing' ) ); ?></option>
													<option value="completed" <?php selected( $edit_data->payment_status ?? '', 'completed' ); ?>><?php echo esc_html( I18n::get_label( 'status_completed' ) ); ?></option>
													<option value="failed" <?php selected( $edit_data->payment_status ?? '', 'failed' ); ?>><?php echo esc_html( I18n::get_label( 'status_failed' ) ); ?></option>
													<option value="refunded" <?php selected( $edit_data->payment_status ?? '', 'refunded' ); ?>><?php echo esc_html( I18n::get_label( 'status_refunded' ) ); ?></option>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php echo esc_html( I18n::get_label( 'label_payment_rcvd' ) ); ?></th>
											<td>
												<label style="display:flex; align-items:center; gap:8px;">
													<input type="hidden" name="payment_received" value="0">
													<input type="checkbox" name="payment_received" id="mhbo_edit_payment_received" value="1" <?php checked( $edit_amts ? $edit_amts['is_paid'] : ( (int) ( $edit_data->payment_received ?? 0 ) === 1 ) ); ?>>
													<span><?php echo esc_html( I18n::get_label( 'label_mark_rcvd' ) ); ?></span>
												</label>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_payment_amount"><?php echo esc_html( I18n::get_label( 'label_pay_amt' ) ); ?></label></th>
											<td>
												<input type="number" step="any" name="payment_amount" id="mhbo_edit_payment_amount" class="regular-text" value="<?php echo esc_attr( Money::fromDecimal( (string) ( $folio['total_paid'] ?? $edit_data->payment_amount ?? 0 ) )->toDecimal() ); ?>" style="width:100%;">
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_amount_outstanding"><?php echo esc_html( I18n::get_label( 'label_amt_out' ) ); ?></label></th>
											<td>
												<input type="text" id="mhbo_edit_amount_outstanding" readonly class="regular-text" style="width:100%; background:#f1f5f9; font-weight:700; color:#0f172a;" value="<?php echo esc_attr( Money::fromDecimal( (string) ( $folio['balance_remaining'] ?? 0 ), $currency )->toDecimal() ); ?>">
											</td>
										</tr>
												<?php /* BUILD_PRO_START */ ?>
												<?php if ( $is_pro_active ) : ?>
											<tr>
												<th scope="row"><label><?php echo esc_html( I18n::get_label( 'label_txn_id' ) ); ?></label></th>
												<td>
													<input type="text" name="payment_transaction_id" class="regular-text" value="<?php echo esc_attr( $edit_data->payment_transaction_id ?? '' ); ?>" readonly style="width:100%;">
												</td>
											</tr>
													<?php if ( ! empty( $edit_data->payment_date ) ) : ?>
												<tr>
													<th scope="row"><label><?php echo esc_html( I18n::get_label( 'label_pay_date' ) ); ?></label></th>
													<td>
														<input type="text" name="payment_date" class="regular-text" readonly value="<?php echo esc_attr( $edit_data->payment_date ); ?>" style="width:100%;">
													</td>
												</tr>
											<?php endif; ?>
													<?php if ( ! empty( $edit_data->payment_error ) ) : ?>
												<tr>
													<th scope="row"><label><?php echo esc_html( I18n::get_label( 'label_pay_error' ) ); ?></label></th>
													<td>
														<textarea name="payment_error" rows="2" class="large-text" readonly style="width:100%;"><?php echo esc_textarea( $edit_data->payment_error ); ?></textarea>
													</td>
												</tr>
											<?php endif; ?>
										<?php endif; ?>
												<?php /* BUILD_PRO_END */ ?>
									</table>
								</div>
							</div>

							<!-- Card 3: Magic Balance Link & Receipt Portal (Pro) -->
									<?php /* BUILD_PRO_START */ ?>
									<?php
									if ( $is_pro_active && class_exists( \MHBO\Pro\BalancePayment::class ) ) :
										$payment_link  = \MHBO\Pro\BalancePayment::get_or_create_magic_url( (int) $edit_data->id, true );
										$is_settled_bk = ! empty( $edit_amts['is_paid'] ) || empty( $has_rem_due );
										?>
								<div class="mhbo-workstation-card">
									<div class="mhbo-card-header-bar">
										<h3><span class="dashicons dashicons-admin-links" style="color:#0284c7;"></span> <?php esc_html_e( 'Guest Payment Portal & Receipt Link', 'modern-hotel-booking' ); ?></h3>
										<span style="font-size:11px; font-weight:700; padding:2px 8px; border-radius:99px; background:<?php echo $is_settled_bk ? '#dcfce7' : '#fee2e2'; ?>; color:<?php echo $is_settled_bk ? '#166534' : '#991b1b'; ?>;">
											<?php
											if ( $is_settled_bk ) {
												echo esc_html__( '✓ Settled (Paid in Full)', 'modern-hotel-booking' );
											} else {
												/* translators: %s: Formatted balance due amount */
												echo esc_html( sprintf( __( 'Balance Due: %s', 'modern-hotel-booking' ), (string) ( $folio['balance_remaining_formatted'] ?? '' ) ) );
											}
											?>
										</span>
									</div>
									<div class="mhbo-card-body-padded">
										<?php if ( $payment_link ) : ?>
											<div style="margin-bottom:12px;">
												<label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">
													<?php echo $is_group_booking ? esc_html__( 'Master Group Payment & Receipt Portal:', 'modern-hotel-booking' ) : esc_html__( 'Secure Guest Payment Portal URL:', 'modern-hotel-booking' ); ?>
												</label>
												<div style="display:flex; gap:6px;">
													<input type="text" readonly value="<?php echo esc_attr( $payment_link ); ?>" style="width:100%; font-size:11px; padding:6px 8px; border:1px solid #cbd5e1; border-radius:4px; background:#fff;" onclick="this.select();">
													<button type="button" class="button button-small mhbo-copy-balance-link mhbo-copy-trigger" data-url="<?php echo esc_url( $payment_link ); ?>" data-copy="<?php echo esc_attr( $payment_link ); ?>"><?php esc_html_e( 'Copy', 'modern-hotel-booking' ); ?></button>
												</div>
											</div>
											<?php if ( $is_group_booking ) : ?>
												<?php $room_pay_link = add_query_arg( 'pay_scope', 'room', $payment_link ); ?>
												<div style="margin-bottom:12px;">
													<label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">
														<?php esc_html_e( 'Room-Only Payment URL (This Unit):', 'modern-hotel-booking' ); ?>
													</label>
													<div style="display:flex; gap:6px;">
														<input type="text" readonly value="<?php echo esc_attr( $room_pay_link ); ?>" style="width:100%; font-size:11px; padding:6px 8px; border:1px solid #cbd5e1; border-radius:4px; background:#fff;" onclick="this.select();">
														<button type="button" class="button button-small mhbo-copy-trigger" data-copy="<?php echo esc_attr( $room_pay_link ); ?>"><?php esc_html_e( 'Copy', 'modern-hotel-booking' ); ?></button>
													</div>
												</div>
											<?php endif; ?>
										<?php endif; ?>

										<div style="display:flex; flex-wrap:wrap; gap:8px;">
											<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=email_balance_link&id={$edit_data->id}&return=edit" ), 'mhbo_balance_' . $edit_data->id ) ); ?>" class="button button-small">
												<?php echo $is_settled_bk ? esc_html__( '📧 Email Receipt Link', 'modern-hotel-booking' ) : esc_html( I18n::get_label( 'label_send_balance_link' ) ); ?>
											</a>
											<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=regenerate_balance_link&id={$edit_data->id}&return=edit" ), 'mhbo_balance_' . $edit_data->id ) ); ?>" class="button button-small" title="<?php esc_attr_e( 'Generate a fresh secure payment link token and reset expiration', 'modern-hotel-booking' ); ?>">
												🔄 <?php esc_html_e( 'Regenerate Link', 'modern-hotel-booking' ); ?>
											</a>
											<?php if ( ! $is_settled_bk ) : ?>
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=revoke_balance_link&id={$edit_data->id}&return=edit" ), 'mhbo_balance_' . $edit_data->id ) ); ?>" class="button button-small" style="color:#dc2626;" onclick="return confirm('<?php esc_attr_e( 'Revoke this balance link?', 'modern-hotel-booking' ); ?>')">
													<?php echo esc_html( I18n::get_label( 'label_revoke_balance_link' ) ); ?>
												</a>
											<?php endif; ?>
										</div>

												<?php if ( ! empty( $edit_data->balance_link_expires_at ) ) : ?>
											<div style="margin-top:10px; font-size:11px; color:#64748b;">
												<span><?php esc_html_e( 'Expires:', 'modern-hotel-booking' ); ?> <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $edit_data->balance_link_expires_at ) ) ); ?></span>
											</div>
										<?php endif; ?>
									</div>
								</div>
									<?php endif; ?>
									<?php /* BUILD_PRO_END */ ?>

							<!-- Card 4: Reservation Status & Notes -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-clipboard" style="color:#475569;"></span> <?php esc_html_e( 'Reservation Status & Notes', 'modern-hotel-booking' ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label><?php echo esc_html( I18n::get_label( 'label_status' ) ); ?></label></th>
											<td>
												<select name="status" style="width:100%;">
													<option value="pending" <?php selected( $edit_data->status, 'pending' ); ?>><?php echo esc_html( I18n::translate_status( 'pending' ) ); ?></option>
													<option value="confirmed" <?php selected( $edit_data->status, 'confirmed' ); ?>><?php echo esc_html( I18n::translate_status( 'confirmed' ) ); ?></option>
													<option value="cancelled" <?php selected( $edit_data->status, 'cancelled' ); ?>><?php echo esc_html( I18n::translate_status( 'cancelled' ) ); ?></option>
													<option value="checked_in" <?php selected( $edit_data->status ?? '', 'checked_in' ); ?>><?php echo esc_html( I18n::translate_status( 'checked_in' ) ); ?></option>
													<option value="checked_out" <?php selected( $edit_data->status ?? '', 'checked_out' ); ?>><?php echo esc_html( I18n::translate_status( 'checked_out' ) ); ?></option>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label><?php echo esc_html( I18n::get_label( 'label_booking_lang' ) ); ?></label></th>
											<td>
												<select name="booking_language" style="width:100%;">
													<?php foreach ( I18n::get_available_languages() as $lang ) : ?>
														<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $edit_data->booking_language ?? '', $lang ); ?>><?php echo esc_html( strtoupper( $lang ) ); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
												<?php if ( isset( $edit_data->guest_rating ) ) : ?>
											<tr>
												<th scope="row"><label><?php esc_html_e( 'Guest Experience Rating', 'modern-hotel-booking' ); ?></label></th>
												<td>
													<select name="guest_rating" id="mhbo_edit_guest_rating" style="width:100%;">
														<option value="0" <?php selected( (int) $edit_data->guest_rating, 0 ); ?>><?php esc_html_e( 'Not Rated', 'modern-hotel-booking' ); ?></option>
														<option value="5" <?php selected( (int) $edit_data->guest_rating, 5 ); ?>>⭐⭐⭐⭐⭐ <?php esc_html_e( '(5 Stars)', 'modern-hotel-booking' ); ?></option>
														<option value="4" <?php selected( (int) $edit_data->guest_rating, 4 ); ?>>⭐⭐⭐⭐ <?php esc_html_e( '(4 Stars)', 'modern-hotel-booking' ); ?></option>
														<option value="3" <?php selected( (int) $edit_data->guest_rating, 3 ); ?>>⭐⭐⭐ <?php esc_html_e( '(3 Stars)', 'modern-hotel-booking' ); ?></option>
														<option value="2" <?php selected( (int) $edit_data->guest_rating, 2 ); ?>>⭐⭐ <?php esc_html_e( '(2 Stars)', 'modern-hotel-booking' ); ?></option>
														<option value="1" <?php selected( (int) $edit_data->guest_rating, 1 ); ?>>⭐ <?php esc_html_e( '(1 Star)', 'modern-hotel-booking' ); ?></option>
													</select>
												</td>
											</tr>
										<?php endif; ?>
										<tr>
											<th scope="row"><label><?php echo esc_html( I18n::get_label( 'label_admin_notes' ) ); ?></label></th>
											<td>
												<textarea name="admin_notes" rows="4" style="width:100%;" class="large-text"><?php echo esc_textarea( $edit_data->admin_notes ?? '' ); ?></textarea>
											</td>
										</tr>
									</table>
								</div>
							</div>

							<!-- Card 5: Save & Action Dock -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-body-padded">
									<input type="submit" name="submit_booking_update" class="button button-primary button-hero" value="<?php esc_attr_e( '💾 Save Reservation Changes', 'modern-hotel-booking' ); ?>" style="width:100%; text-align:center; justify-content:center; height:44px; font-size:14px; font-weight:700;">

									<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=delete&id={$edit_data->id}" ), 'mhbo_delete_booking_' . $edit_data->id ) ); ?>" class="button button-link-delete mhbo-delete-action" style="margin-top:14px; display:block; text-align:center; color:#dc2626;" data-confirm="<?php echo esc_attr( I18n::get_label( 'msg_confirm_delete_bk' ) ); ?>">
												<?php echo esc_html( I18n::get_label( 'btn_delete_booking' ) ); ?>
									</a>
								</div>
							</div>

						</div><!-- End .mhbo-workstation-col-sidebar -->
					</div><!-- End .mhbo-workstation-grid -->
				</form>
					<?php endif; // End of $edit_mode && $edit_data ?>
			<?php if ( ! $edit_mode && ! $add_mode ) : ?>
			<!-- 1. Daily Operations Morning Briefing Strip -->
			<div class="mhbo-ops-briefing-strip">
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#dcfce7; color:#166534;">🟢</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $today_checkins; ?></span>
						<span class="mhbo-ops-label"><?php esc_html_e( "Today's Check-ins", 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#fee2e2; color:#991b1b;">🔴</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $today_checkouts; ?></span>
						<span class="mhbo-ops-label"><?php esc_html_e( "Today's Check-outs", 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#fef3c7; color:#92400e;">🏨</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $in_house_rooms; ?> / <?php echo (int) $distinct_room_count; ?></span>
						<span class="mhbo-ops-label"><?php esc_html_e( 'In-House Tonight', 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#e0e7ff; color:#3730a3;">📊</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $occupancy_pct; ?>%</span>
						<span class="mhbo-ops-label"><?php esc_html_e( "Tonight's Occupancy", 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
				<?php /* BUILD_PRO_START */ ?>
				<?php if ( $is_pro_active && class_exists( \MHBO\Pro\HousekeepingManifest::class ) ) : ?>
					<div class="mhbo-ops-actions">
						<div class="mhbo-ops-manifest-btn-group">
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=print_housekeeping_manifest' ), 'mhbo_housekeeping_manifest' ) ); ?>" target="_blank" class="button button-secondary" title="<?php esc_attr_e( 'Open printable housekeeping turnover sheet', 'modern-hotel-booking' ); ?>">
								🖨️ <?php esc_html_e( 'Print Manifest', 'modern-hotel-booking' ); ?>
							</a>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=email_housekeeping_manifest' ), 'mhbo_housekeeping_manifest' ) ); ?>" class="button button-secondary" title="<?php esc_attr_e( 'Send daily manifest to Housekeeping email', 'modern-hotel-booking' ); ?>">
								✉️ <?php esc_html_e( 'Email Team', 'modern-hotel-booking' ); ?>
							</a>
						</div>
					</div>
				<?php endif; ?>
				<?php /* BUILD_PRO_END */ ?>
			</div>

			<!-- 2. Interactive Calendar Card -->
			<div class="mhbo-calendar-card">
				<div class="mhbo-calendar-header-toolbar">
					<div class="mhbo-calendar-filter-group">
						<label for="mhbo-calendar-room-filter" class="mhbo-filter-label">🏷️ <?php esc_html_e( 'Filter:', 'modern-hotel-booking' ); ?></label>
						<select id="mhbo-calendar-room-filter" class="mhbo-select-filter">
							<option value=""><?php esc_html_e( 'All Rooms & Types', 'modern-hotel-booking' ); ?></option>
							<?php
							$type_grouped_rooms = array();
							foreach ( $distinct_rooms as $rnum => $tname ) {
								$tname_clean = ( ! empty( $tname ) && '1' !== (string) $tname ) ? $tname : __( 'General Rooms', 'modern-hotel-booking' );
								if ( ! isset( $type_grouped_rooms[ $tname_clean ] ) ) {
									$type_grouped_rooms[ $tname_clean ] = array();
								}
								$type_grouped_rooms[ $tname_clean ][] = $rnum;
							}

							// Natural sort room numbers in each group (e.g. 1, 2, 3 ... 9, 10, 11)
							foreach ( $type_grouped_rooms as $tname => &$rooms_list ) {
								usort(
									$rooms_list,
									function ( $a, $b ) {
										return strnatcasecmp( (string) $a, (string) $b );
									}
								);
							}
							unset( $rooms_list );

							if ( count( $type_grouped_rooms ) > 1 ) :
								?>
								<optgroup label="<?php esc_attr_e( 'Filter by Room Type', 'modern-hotel-booking' ); ?>">
									<?php foreach ( $type_grouped_rooms as $tname => $rooms_list ) : ?>
										<option value="type:<?php echo esc_attr( $tname ); ?>">
											<?php 
											/* translators: 1: room type name, 2: number of rooms */
											echo esc_html( sprintf( __( 'All %1$s (%2$d rooms)', 'modern-hotel-booking' ), $tname, count( $rooms_list ) ) ); 
											?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>

							<?php foreach ( $type_grouped_rooms as $tname => $rooms_list ) : ?>
								<optgroup label="<?php echo esc_attr( $tname ); ?>">
									<?php foreach ( $rooms_list as $rnum ) : ?>
										<option value="room:<?php echo esc_attr( (string) $rnum ); ?>">
											<?php 
											/* translators: %s: room number */
											echo esc_html( sprintf( __( 'Room %s', 'modern-hotel-booking' ), $rnum ) ); 
											?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div id="mhbo-calendar"></div>
				<div class="mhbo-calendar-legend">
					<span class="mhbo-legend-item"><span class="mhbo-legend-dot mhbo-legend-green-dot"></span> Check-in</span>
					<span class="mhbo-legend-item"><span class="mhbo-legend-dot mhbo-legend-red-dot"></span> Check-out</span>
					<span class="mhbo-legend-item"><span class="mhbo-legend-badge mhbo-legend-today"></span> Today</span>
					<span class="mhbo-legend-item"><span class="mhbo-legend-badge mhbo-legend-full"></span> 100% Fully Booked</span>
					<span class="mhbo-legend-item"><span class="mhbo-legend-pill mhbo-legend-confirmed"></span> Confirmed</span>
					<span class="mhbo-legend-item"><span class="mhbo-legend-pill mhbo-legend-pending"></span> Pending</span>
				</div>
			</div>
				<?php
				// Note: Price calculation and child ages JavaScript logic has been moved to assets/js/mhbo-admin-bookings.js
				// Pass calendar events for FullCalendar initialization
				$calendar_config = array(
					'events'          => $events,
					'fullDays'        => $full_days,
					'preventTurnover' => (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1,
				);
				wp_add_inline_script( 'mhbo-admin-bookings', 'window.mhboCalendarConfig = ' . wp_json_encode( $calendar_config ) . ';', 'before' );
				wp_add_inline_script( 'mhbo-admin-bookings', 'document.addEventListener("DOMContentLoaded", function() { document.querySelectorAll(".mhbo-schedule-balance-link").forEach(function(btn) { btn.addEventListener("click", function() { var id = this.getAttribute("data-id"); var nonce = this.getAttribute("data-nonce"); var rule = document.getElementById("mhbo-schedule-rule-" + id).value; if (!rule) { alert("Please select a schedule rule."); return; } window.location.href = "' . esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ) . '&action=schedule_balance_link&id=" + id + "&rule=" + rule + "&_wpnonce=" + nonce; }); }); });', 'after' );
				?>
			<div class="mhbo-table-container mhbo-card">
				<form method="post" id="mhbo-bookings-bulk-form">
					<?php wp_nonce_field( 'mhbo_bulk_bookings_action', 'mhbo_bulk_nonce' ); ?>
					<div class="mhbo-table-live-filter-bar">
						<?php /* BUILD_PRO_START */ ?>
						<?php if ( $is_pro_active ) : ?>
							<div class="mhbo-bulk-actions-group alignleft actions bulkactions">
								<label for="bulk-action-selector-top" class="screen-reader-text"><?php echo esc_html( I18n::get_label( 'label_bulk_actions' ) ); ?></label>
								<select name="bulk_action" id="bulk-action-selector-top" class="mhbo-bulk-select">
									<option value="-1"><?php echo esc_html( I18n::get_label( 'label_bulk_actions' ) ); ?></option>
									<option value="bulk_delete"><?php echo esc_html( I18n::get_label( 'label_bulk_delete' ) ); ?></option>
								</select>
								<input type="submit" id="mhbo-doaction-top" class="button action mhbo-bulk-apply-btn" value="<?php echo esc_attr( I18n::get_label( 'label_apply' ) ); ?>">
								<span class="mhbo-selected-badge" id="mhbo-selected-badge" style="display:none;"></span>
							</div>
						<?php endif; ?>
						<?php /* BUILD_PRO_END */ ?>
						<div class="mhbo-search-box">
							<div class="mhbo-search-input-wrap">
								<span class="dashicons dashicons-search mhbo-search-icon" id="mhbo-search-submit-icon" title="<?php esc_attr_e( 'Click to search', 'modern-hotel-booking' ); ?>"></span>
								<input type="text" id="mhbo-live-table-search" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Quick search bookings by Guest Name, Email, Phone, #ID, or Room...', 'modern-hotel-booking' ); ?>" class="mhbo-search-input" data-base-url="<?php echo esc_url( remove_query_arg( array( 's', 'paged' ), $bookings_base_url ) ); ?>" autocomplete="off">
								<?php if ( ! empty( $search_query ) ) : ?>
									<a href="<?php echo esc_url( remove_query_arg( array( 's', 'paged' ), $bookings_base_url ) ); ?>" class="mhbo-search-clear-btn" id="mhbo-search-clear-btn" title="<?php esc_attr_e( 'Clear search', 'modern-hotel-booking' ); ?>">&times;</a>
								<?php endif; ?>
							</div>
							<button type="button" id="mhbo-table-search-btn" class="button action mhbo-search-submit-btn" title="<?php esc_attr_e( 'Search bookings', 'modern-hotel-booking' ); ?>"><?php esc_html_e( 'Search', 'modern-hotel-booking' ); ?></button>
						</div>
						<?php
						$status_base_url      = remove_query_arg( 'paged', $bookings_base_url );
						$status_all_url       = remove_query_arg( 'status', $status_base_url );
						$status_confirmed_url = add_query_arg( 'status', 'confirmed', $status_base_url );
						$status_pending_url   = add_query_arg( 'status', 'pending', $status_base_url );
						$status_cancelled_url = add_query_arg( 'status', 'cancelled', $status_base_url );
						?>
						<div class="mhbo-status-filter-pills" id="mhbo-status-filter-pills">
							<a href="<?php echo esc_url( $status_all_url ); ?>" class="mhbo-pill-btn <?php echo ( empty( $status_filter ) || 'all' === $status_filter ) ? 'active' : ''; ?>" data-status="all"><?php esc_html_e( 'All Statuses', 'modern-hotel-booking' ); ?></a>
							<a href="<?php echo esc_url( $status_confirmed_url ); ?>" class="mhbo-pill-btn <?php echo 'confirmed' === $status_filter ? 'active' : ''; ?>" data-status="confirmed"><?php esc_html_e( 'Confirmed', 'modern-hotel-booking' ); ?></a>
							<a href="<?php echo esc_url( $status_pending_url ); ?>" class="mhbo-pill-btn <?php echo 'pending' === $status_filter ? 'active' : ''; ?>" data-status="pending"><?php esc_html_e( 'Pending', 'modern-hotel-booking' ); ?></a>
							<a href="<?php echo esc_url( $status_cancelled_url ); ?>" class="mhbo-pill-btn <?php echo 'cancelled' === $status_filter ? 'active' : ''; ?>" data-status="cancelled"><?php esc_html_e( 'Cancelled', 'modern-hotel-booking' ); ?></a>
						</div>
						<div class="mhbo-toolbar-per-page">
							<label for="mhbo-table-per-page-select" style="font-size:12px; font-weight:600; color:#64748b; display:flex; align-items:center; gap:4px;">
								<span class="dashicons dashicons-list-view" style="font-size:16px; width:16px; height:16px;"></span> <?php esc_html_e( 'Show:', 'modern-hotel-booking' ); ?>
							</label>
							<select id="mhbo-table-per-page-select" class="mhbo-toolbar-per-page-select" data-base-url="<?php echo esc_url( remove_query_arg( array( 'per_page', 'paged' ), $bookings_base_url ) ); ?>">
								<option value="10" <?php selected( $per_page, 10 ); ?>>10</option>
								<option value="25" <?php selected( $per_page, 25 ); ?>>25</option>
								<option value="50" <?php selected( $per_page, 50 ); ?>>50</option>
								<option value="100" <?php selected( $per_page, 100 ); ?>>100</option>
								<option value="all" <?php selected( $per_page <= 0 ); ?>><?php esc_html_e( 'All', 'modern-hotel-booking' ); ?></option>
							</select>
						</div>
						<div class="mhbo-sort-box" style="margin-left:auto; display:flex; align-items:center; gap:6px;">
							<label for="mhbo-table-sort-select" style="font-size:12px; font-weight:600; color:#64748b; display:flex; align-items:center; gap:4px;">
								<span class="dashicons dashicons-sort" style="font-size:16px; width:16px; height:16px;"></span> <?php esc_html_e( 'Sort by:', 'modern-hotel-booking' ); ?>
							</label>
							<select id="mhbo-table-sort-select" class="mhbo-select-filter" onchange="if(this.value){ window.location.href = this.value; }">
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'id',
											'order'   => 'desc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
												" <?php selected( $orderby === 'id' && $order === 'DESC' ); ?>><?php esc_html_e( 'ID (Highest first)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'id',
											'order'   => 'asc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'id' && $order === 'ASC' ); ?>><?php esc_html_e( 'ID (Lowest first)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'room',
											'order'   => 'asc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'room' && $order === 'ASC' ); ?>><?php esc_html_e( 'Room (A-Z / 1-9)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'room',
											'order'   => 'desc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'room' && $order === 'DESC' ); ?>><?php esc_html_e( 'Room (Z-A / 9-1)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'dates',
											'order'   => 'asc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'dates' && $order === 'ASC' ); ?>><?php esc_html_e( 'Dates (Earliest check-in)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'dates',
											'order'   => 'desc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'dates' && $order === 'DESC' ); ?>><?php esc_html_e( 'Dates (Latest check-in)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'status',
											'order'   => 'asc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'status' ); ?>><?php esc_html_e( 'Status', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'guest',
											'order'   => 'asc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'guest' && $order === 'ASC' ); ?>><?php esc_html_e( 'Guest Name (A-Z)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'total',
											'order'   => 'desc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'total' && $order === 'DESC' ); ?>><?php esc_html_e( 'Total (Highest first)', 'modern-hotel-booking' ); ?></option>
							</select>
						</div>
					</div>
					<table class="wp-list-table widefat fixed striped mhbo-bookings-table">
						<thead>
							<tr>
								<?php /* BUILD_PRO_START */ ?>
								<?php if ( $is_pro_active ) : ?>
									<th scope="col" id="cb" class="manage-column column-cb check-column mhbo-col-cb">
										<label class="screen-reader-text" for="mhbo-cb-select-all"><?php esc_html_e( 'Select All', 'modern-hotel-booking' ); ?></label>
										<input type="checkbox" id="mhbo-cb-select-all" aria-label="<?php esc_attr_e( 'Select All', 'modern-hotel-booking' ); ?>">
									</th>
								<?php endif; ?>
								<?php /* BUILD_PRO_END */ ?>
								<?php
								$render_sortable_th = function ( string $col_key, string $label, string $col_class, bool $sortable = true ) use ( $orderby, $order, $bookings_base_url ): void {
									if ( ! $sortable ) {
										echo '<th class="' . esc_attr( $col_class ) . '">' . esc_html( $label ) . '</th>';
										return;
									}
									$is_active = ( $orderby === $col_key );
									$order_low = strtolower( $order );
									if ( $is_active ) {
										$next_order = ( 'asc' === $order_low ) ? 'desc' : 'asc';
										$th_classes = $col_class . ' manage-column sorted ' . $order_low;
									} else {
										$next_order = ( 'id' === $col_key ) ? 'desc' : 'asc';
										$th_classes = $col_class . ' manage-column sortable ' . ( 'id' === $col_key ? 'desc' : 'asc' );
									}
									$url = add_query_arg(
										array(
											'orderby' => $col_key,
											'order'   => $next_order,
											'paged'   => 1,
										),
										$bookings_base_url
									);
									?>
									<th class="<?php echo esc_attr( $th_classes ); ?>">
										<a href="<?php echo esc_url( $url ); ?>" title="
										<?php 
											/* translators: %s: column label */
											echo esc_attr( sprintf( __( 'Sort by %s', 'modern-hotel-booking' ), $label ) ); 
										?>
										">
											<span><?php echo esc_html( $label ); ?></span>
											<span class="sorting-indicators">
												<span class="sorting-indicator asc" aria-hidden="true"></span>
												<span class="sorting-indicator desc" aria-hidden="true"></span>
											</span>
										</a>
									</th>
									<?php
								};

								$render_sortable_th( 'id', I18n::get_label( 'label_id' ), 'mhbo-col-id', true );
								$render_sortable_th( 'guest', I18n::get_label( 'label_guest' ), 'mhbo-col-guest', true );
								$render_sortable_th( 'room', I18n::get_label( 'label_room' ), 'mhbo-col-room', true );
								$render_sortable_th( 'dates', I18n::get_label( 'label_dates' ), 'mhbo-col-dates', true );
								$render_sortable_th( 'total', I18n::get_label( 'label_total' ), 'mhbo-col-total', true );
								$render_sortable_th( 'status', I18n::get_label( 'label_status_noun' ), 'mhbo-col-status', true );
								$render_sortable_th( 'payment', I18n::get_label( 'label_payment' ), 'mhbo-col-payment', false );
								$render_sortable_th( 'lang', I18n::get_label( 'label_lang' ), 'mhbo-col-lang', false );
								$render_sortable_th( 'actions', I18n::get_label( 'label_actions' ), 'mhbo-col-actions', false );
	?>
							</tr>
						</thead>
						<tbody>
							<?php
							if ( count( $bookings ) > 0 ) :
								foreach ( $bookings as $bk ) :
									$sc = 'mhbo-status-' . esc_attr( $bk->status );
									?>
									<tr class="mhbo-animate-in mhbo-booking-row" data-status="<?php echo esc_attr( $bk->status ); ?>" data-booking-id="<?php echo esc_attr( (string) $bk->id ); ?>">
										<?php /* BUILD_PRO_START */ ?>
										<?php if ( $is_pro_active ) : ?>
											<td class="check-column mhbo-col-cb" data-colname="<?php esc_attr_e( 'Select', 'modern-hotel-booking' ); ?>">
												<?php /* translators: %d: booking ID */ ?>
												<input type="checkbox" name="booking_ids[]" value="<?php echo esc_attr( (string) $bk->id ); ?>" class="mhbo-booking-checkbox" aria-label="<?php echo esc_attr( sprintf( __( 'Select booking #%d', 'modern-hotel-booking' ), (int) $bk->id ) ); ?>">
											</td>
										<?php endif; ?>
										<?php /* BUILD_PRO_END */ ?>
										<td class="mhbo-col-id" data-colname="<?php esc_attr_e( 'ID', 'modern-hotel-booking' ); ?>">
											<span class="mhbo-id-badge mhbo-copy-trigger" data-copy="#<?php echo esc_attr( $bk->id ); ?>" title="<?php esc_attr_e( 'Click to copy Booking ID', 'modern-hotel-booking' ); ?>">#<?php echo esc_html( $bk->id ); ?> <span class="mhbo-copy-icon">📋</span></span>
											<?php if ( ! empty( $bk->multi_room_parent ) || ! empty( $bk->is_multi_room ) ) : ?>
												<div style="margin-top:4px;">
													<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings&s=' . rawurlencode( (string) ( ! empty( $bk->multi_room_parent ) ? $bk->multi_room_parent : ( $bk->booking_token ?? $bk->id ) ) ) ) ); ?>" class="mhbo-group-link-badge" style="display:inline-flex; align-items:center; gap:3px; padding:1px 6px; border-radius:4px; font-size:10px; font-weight:700; background:#e0e7ff; color:#3730a3; text-decoration:none;" title="<?php esc_attr_e( 'Filter table by this group reservation token', 'modern-hotel-booking' ); ?>">
														👥 <?php esc_html_e( 'Group', 'modern-hotel-booking' ); ?>
													</a>
												</div>
											<?php endif; ?>
										</td>
										<td class="mhbo-col-guest" data-colname="<?php esc_attr_e( 'Guest', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-guest-info">
											<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
												<?php if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() && ! empty( $bk->guest_id ) ) : ?>
													<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-guests&guest_id=' . (int) $bk->guest_id ) ); ?>" target="_blank" class="mhbo-primary-text" style="font-weight:700; color:#2563eb; text-decoration:none;" title="<?php esc_attr_e( 'Open Guest CRM Profile in new tab', 'modern-hotel-booking' ); ?>">
														<?php echo esc_html( (string) ( I18n::decode( $bk->customer_name ) ?? '' ) ); ?> ↗
													</a>
												<?php else : ?>
													<strong class="mhbo-primary-text"><?php echo esc_html( (string) ( I18n::decode( $bk->customer_name ) ?? '' ) ); ?></strong>
												<?php endif; ?>

												<?php if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) : ?>
													<?php if ( ! empty( $bk->guest_vip_status ) && 'none' !== $bk->guest_vip_status ) : ?>
														<span style="background:#fef3c7; color:#92400e; padding:1px 6px; border-radius:99px; font-size:10px; font-weight:700; text-transform:uppercase;" title="<?php esc_attr_e( 'VIP Guest Tier', 'modern-hotel-booking' ); ?>">
															★ <?php echo esc_html( str_replace( '_', ' ', $bk->guest_vip_status ) ); ?>
														</span>
													<?php endif; ?>
													<?php if ( ! empty( $bk->guest_caution_flag ) ) : ?>
														<span style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:1px 6px; border-radius:99px; font-size:10px; font-weight:700;" title="<?php esc_attr_e( 'Caution Flag Active in CRM', 'modern-hotel-booking' ); ?>">
															🚫 <?php esc_html_e( 'Caution', 'modern-hotel-booking' ); ?>
														</span>
													<?php endif; ?>
												<?php endif; ?>
											</div>
											<span class="mhbo-guest-email mhbo-copy-trigger" data-copy="<?php echo esc_attr( $bk->customer_email ); ?>" title="<?php esc_attr_e( 'Click to copy email', 'modern-hotel-booking' ); ?>"><?php echo esc_html( $bk->customer_email ); ?></span>
											<?php if ( isset( $bk->customer_phone ) && $bk->customer_phone ) : ?>
												<span class="mhbo-guest-phone mhbo-copy-trigger" data-copy="<?php echo esc_attr( $bk->customer_phone ); ?>" title="<?php esc_attr_e( 'Click to copy phone', 'modern-hotel-booking' ); ?>"><?php echo esc_html( $bk->customer_phone ); ?></span>
											<?php endif; ?>
										</div>
									</td>
									<td class="mhbo-col-room mhbo-meta-grid-item" data-colname="<?php esc_attr_e( 'Room', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-room-info">
											<span class="mhbo-room-number"><?php echo esc_html( $bk->room_number ); ?></span>
											<small><?php echo esc_html( (string) ( I18n::decode( $bk->room_type ) ?? '' ) ); ?></small>
										</div>
									</td>
									<td class="mhbo-col-dates" data-colname="<?php esc_attr_e( 'Dates', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-date-range">
											<span><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $bk->check_in ) ) ); ?></span>
											<span class="mhbo-arrow">→</span>
											<span><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $bk->check_out ) ) ); ?></span>
										</div>
									</td>
									<td class="mhbo-col-total mhbo-meta-grid-item" data-colname="<?php esc_attr_e( 'Total', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-total-info" style="display: flex; flex-direction: column; align-items: flex-start; gap: 4px;">
											<span class="mhbo-price"><?php echo esc_html( I18n::format_currency( $bk->total_price ) ); ?></span>
											<?php
												$amts = class_exists( Pricing::class ) ? Pricing::get_booking_payment_amounts( $bk ) : null;
											if ( $amts ) {
												if ( $amts['is_paid'] ) {
													echo '<span class="mhbo-balance-pill paid">' . esc_html( I18n::get_label( 'label_paid_full_short' ) ) . '</span>';
												} elseif ( $amts['deposit_received'] && $amts['remaining']->isPositive() ) {
													/* translators: %s: balance pending amount */
													echo '<span class="mhbo-balance-pill pending">' . esc_html( sprintf( I18n::get_label( 'label_bal_sprintf' ), I18n::format_currency( $amts['remaining']->toDecimal() ) ) ) . '</span>';
												} elseif ( $amts['received']->isPositive() && $amts['remaining']->isPositive() ) {
													/* translators: %s: pending balance amount */
													echo '<span class="mhbo-balance-pill pending">' . esc_html( sprintf( I18n::get_label( 'label_pending_sprintf' ), I18n::format_currency( $amts['remaining']->toDecimal() ) ) ) . '</span>';
												} else {
													/* translators: %s: pending total amount */
													echo '<span class="mhbo-balance-pill pending">' . esc_html( sprintf( I18n::get_label( 'label_pending_sprintf' ), I18n::format_currency( $amts['total']->toDecimal() ) ) ) . '</span>';
												}
											} elseif ( $bk->payment_received ?? 0 ) {
													echo '<span class="mhbo-balance-pill paid">' . esc_html( I18n::get_label( 'label_paid_full_short' ) ) . '</span>';
											} elseif ( ( $bk->deposit_received ?? 0 ) && ! empty( $bk->deposit_amount ) ) {
												$cur_bk        = Pricing::get_currency_code();
												$tot_m         = Money::fromDecimal( (string) ( $bk->total_price ?? 0 ), $cur_bk );
												$dep_m         = Money::fromDecimal( (string) ( $bk->deposit_amount ?? 0 ), $cur_bk );
												$outstanding_m = $tot_m->subtract( $dep_m );
												/* translators: %s: pending balance amount */
												echo '<span class="mhbo-balance-pill pending">' . esc_html( sprintf( I18n::get_label( 'label_bal_sprintf' ), I18n::format_currency( $outstanding_m ) ) ) . '</span>';
											}
											?>
										</div>
										<?php
											/* BUILD_PRO_START */
											// Show Overdue badge if balance payment link has expired and balance is not yet collected
										if ( $is_pro_active && ! empty( $bk->balance_link_expires_at ) && strtotime( (string) $bk->balance_link_expires_at ) < time() && ! in_array( (string) ( $bk->balance_status ?? '' ), array( 'paid', 'collected' ), true ) ) {
											echo '<span class="mhbo-balance-badge overdue" style="display:inline-block;margin-top:4px;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;color:#dc2626;background:#fee2e2;">' . esc_html__( 'Overdue', 'modern-hotel-booking' ) . '</span>';
										}
											/* BUILD_PRO_END */
										?>
									</td>
									<td class="mhbo-col-status mhbo-meta-grid-item" data-colname="<?php esc_attr_e( 'Status', 'modern-hotel-booking' ); ?>">
										<span class="mhbo-status-badge <?php echo esc_attr( $sc ); ?>">
											<?php echo esc_html( I18n::translate_status( $bk->status ) ); ?>
										</span>
									</td>
									<td class="mhbo-col-payment mhbo-meta-grid-item" data-colname="<?php esc_attr_e( 'Payment', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-payment-info" style="display:flex; flex-direction:column; gap:4px;">
											<span class="mhbo-payment-method">
												<?php echo esc_html( I18n::get_payment_method_label( $bk->payment_method ?? 'arrival' ) ); ?>
											</span>
											<?php
											$p_status = sanitize_key( (string) ( $bk->payment_status ?? 'pending' ) );
											if ( 'paid' === $p_status || 'completed' === $p_status || 'paid_full' === $p_status ) {
												echo '<span class="mhbo-balance-pill paid">' . esc_html( I18n::get_label( 'status_completed' ) ) . '</span>';
											} elseif ( 'deposit_paid' === $p_status || ( $bk->deposit_received ?? 0 ) ) {
												echo '<span class="mhbo-balance-pill deposit-paid" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;">' . esc_html( I18n::get_label( 'label_deposit_paid' ) ) . '</span>';
											} elseif ( 'processing' === $p_status ) {
												echo '<span class="mhbo-balance-pill processing" style="background:#fef3c7; color:#92400e;">' . esc_html( I18n::get_label( 'status_processing' ) ) . '</span>';
											} elseif ( 'refunded' === $p_status ) {
												echo '<span class="mhbo-balance-pill refunded" style="background:#fef2f2; color:#991b1b;">' . esc_html( I18n::get_label( 'status_refunded' ) ) . '</span>';
											} elseif ( 'failed' === $p_status ) {
												echo '<span class="mhbo-balance-pill failed" style="background:#fef2f2; color:#991b1b;">' . esc_html( I18n::get_label( 'status_failed' ) ) . '</span>';
											} else {
												echo '<span class="mhbo-balance-pill pending">' . esc_html( I18n::get_label( 'status_pending' ) ) . '</span>';
											}
											?>
										</div>
									</td>
									<td class="mhbo-col-lang" data-colname="<?php esc_attr_e( 'Lang', 'modern-hotel-booking' ); ?>">
										<span class="mhbo-lang-tag"><?php echo esc_html( strtoupper( $bk->booking_language ?? 'en' ) ); ?></span>
									</td>
									<td class="mhbo-col-actions" data-colname="<?php esc_attr_e( 'Actions', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-actions-group">
											<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=edit&id={$bk->id}" ), 'mhbo_edit_booking_' . $bk->id ) ); ?>"
												class="mhbo-action-btn mhbo-btn-edit" 
												title="<?php esc_attr_e( 'Edit Booking', 'modern-hotel-booking' ); ?>">
												<span class="dashicons dashicons-edit"></span>
											</a>
											<?php if ( 'pending' === $bk->status ) : ?>
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=confirm&id={$bk->id}" ), 'mhbo_confirm_booking_' . $bk->id ) ); ?>"
													class="mhbo-action-btn mhbo-btn-confirm" 
													title="<?php esc_attr_e( 'Confirm Booking', 'modern-hotel-booking' ); ?>"
													onclick="return confirm('<?php echo esc_js( __( 'Confirm this reservation and send confirmation email to guest?', 'modern-hotel-booking' ) ); ?>');">
													<span class="dashicons dashicons-yes-alt"></span>
												</a>
											<?php endif; ?>
											<?php /* BUILD_PRO_START */ ?>
											<?php 
											if ( $is_pro_active && class_exists( \MHBO\Pro\BalancePayment::class ) ) : 
												$magic_url = \MHBO\Pro\BalancePayment::get_or_create_magic_url( (int) $bk->id, true );
												?>
												<?php if ( ! empty( $magic_url ) ) : ?>
													<button type="button" 
														class="mhbo-action-btn mhbo-btn-copy-balance mhbo-copy-trigger" 
														data-copy="<?php echo esc_url( $magic_url ); ?>" 
														title="<?php esc_attr_e( 'Copy Payment / Receipt Link', 'modern-hotel-booking' ); ?>">
														<span class="dashicons dashicons-admin-links"></span>
													</button>
												<?php endif; ?>
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=email_balance_link&id={$bk->id}" ), 'mhbo_balance_' . $bk->id ) ); ?>"
													class="mhbo-action-btn mhbo-btn-email-balance" 
													title="<?php esc_attr_e( 'Send Payment / Receipt Link', 'modern-hotel-booking' ); ?>">
													<span class="dashicons dashicons-email"></span>
												</a>
											<?php endif; ?>
											<?php /* BUILD_PRO_END */ ?>
											<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=cancel&id={$bk->id}" ), 'mhbo_cancel_booking_' . $bk->id ) ); ?>"
												class="mhbo-action-btn mhbo-btn-cancel" 
												title="<?php esc_attr_e( 'Cancel Booking', 'modern-hotel-booking' ); ?>">
												<span class="dashicons dashicons-no-alt"></span>
											</a>
											<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=delete&id={$bk->id}" ), 'mhbo_delete_booking_' . $bk->id ) ); ?>"
												class="mhbo-action-btn mhbo-btn-delete mhbo-confirm-delete" 
												title="<?php esc_attr_e( 'Delete Booking', 'modern-hotel-booking' ); ?>"
												data-confirm="<?php echo esc_attr( I18n::get_label( 'msg_confirm_remove' ) ); ?>">
												<span class="dashicons dashicons-trash"></span>
											</a>
										</div>
									</td>
								</tr>
									<?php
							endforeach;
						else :
							?>
							<tr>
								<td colspan="10" class="mhbo-empty-state-cell">
									<div class="mhbo-empty-state">
										<span class="dashicons dashicons-calendar-alt"></span>
										<h3><?php esc_html_e( 'No bookings found.', 'modern-hotel-booking' ); ?></h3>
										<p><?php esc_html_e( 'Your search criteria didn\'t return any results.', 'modern-hotel-booking' ); ?></p>
									</div>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<?php
				AdminUI::render_pagination(
					$total_bookings,
					$per_page,
					$paged,
					remove_query_arg( 'paged', $bookings_base_url ),
					$allowed_per_page
				);
				?>
				</form>
			</div>
				<?php /* BUILD_PRO_START */ ?>
				<?php if ( $is_pro_active ) : ?>
			<div id="mhbo-export-modal" class="mhbo-modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="mhbo-export-modal-title">
				<div class="mhbo-modal-content">
					<div class="mhbo-modal-header">
						<h3 id="mhbo-export-modal-title" style="margin:0; font-size:16px;">📥 <?php I18n::esc_html_e( 'label_export_bookings' ); ?></h3>
						<button type="button" class="mhbo-modal-close" aria-label="<?php esc_attr_e( 'Close', 'modern-hotel-booking' ); ?>">&times;</button>
					</div>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="mhbo-modal-body" style="margin-top:15px;">
						<input type="hidden" name="page" value="mhbo-bookings">
						<input type="hidden" name="action" value="mhbo_export_bookings">
						<?php wp_nonce_field( 'mhbo_export_bookings_action', 'mhbo_nonce' ); ?>

						<div style="margin-bottom:15px;">
							<label style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e( 'Export Format:', 'modern-hotel-booking' ); ?></label>
							<label style="margin-right:15px; cursor:pointer;"><input type="radio" name="format" value="json" checked> <?php esc_html_e( 'JSON (Full Fidelity & Relational Data)', 'modern-hotel-booking' ); ?></label>
							<label style="cursor:pointer;"><input type="radio" name="format" value="csv"> <?php esc_html_e( 'CSV (Excel / Spreadsheet Compatible)', 'modern-hotel-booking' ); ?></label>
						</div>

						<div style="margin-bottom:15px;">
							<label style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e( 'Filter by Reservation Status:', 'modern-hotel-booking' ); ?></label>
							<select name="status" class="regular-text" style="width:100%; max-width:100%;">
								<option value="all"><?php esc_html_e( 'All Statuses (Confirmed, Pending, Cancelled, Refunded)', 'modern-hotel-booking' ); ?></option>
								<option value="confirmed"><?php esc_html_e( 'Confirmed Only', 'modern-hotel-booking' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending Only', 'modern-hotel-booking' ); ?></option>
								<option value="cancelled"><?php esc_html_e( 'Cancelled Only', 'modern-hotel-booking' ); ?></option>
							</select>
						</div>

						<div class="mhbo-modal-footer" style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; border-top:1px solid #f1f5f9; padding-top:15px;">
							<button type="button" class="button mhbo-modal-cancel"><?php esc_html_e( 'Cancel', 'modern-hotel-booking' ); ?></button>
							<button type="submit" class="button button-primary"><span class="dashicons dashicons-download" style="margin-top:4px;"></span> <?php esc_html_e( 'Download Export', 'modern-hotel-booking' ); ?></button>
						</div>
					</form>
				</div>
			</div>

			<div id="mhbo-import-modal" class="mhbo-modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="mhbo-import-modal-title">
				<div class="mhbo-modal-content">
					<div class="mhbo-modal-header">
						<h3 id="mhbo-import-modal-title" style="margin:0; font-size:16px;">📤 <?php I18n::esc_html_e( 'label_import_bookings' ); ?></h3>
						<button type="button" class="mhbo-modal-close" aria-label="<?php esc_attr_e( 'Close', 'modern-hotel-booking' ); ?>">&times;</button>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ); ?>" enctype="multipart/form-data" class="mhbo-modal-body" style="margin-top:15px;">
						<input type="hidden" name="mhbo_action" value="mhbo_import_bookings">
						<?php wp_nonce_field( 'mhbo_import_bookings_action', 'mhbo_import_nonce' ); ?>

						<div class="mhbo-dropzone" id="mhbo-import-dropzone" style="border:2px dashed #cbd5e1; border-radius:8px; padding:20px 15px; text-align:center; background:#f8fafc; cursor:pointer; margin-bottom:15px;">
							<span class="dashicons dashicons-upload" style="font-size:28px; width:28px; height:28px; color:#94a3b8;"></span>
							<p style="margin:6px 0 2px; font-weight:600; color:#334155; font-size:13px;"><?php esc_html_e( 'Select or drop a .json or .csv bookings file', 'modern-hotel-booking' ); ?></p>
							<span style="font-size:11px; color:#94a3b8;"><?php esc_html_e( 'Drag and drop file here, or click to browse', 'modern-hotel-booking' ); ?></span>
							<input type="file" name="import_file" id="mhbo-import-file-input" accept=".json,.csv" required style="display:none;">
							<div id="mhbo-import-file-name" style="margin-top:6px; font-weight:700; color:#2563eb; display:none; font-size:12px;"></div>
						</div>

						<div style="margin-bottom:15px;">
							<label style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e( 'Conflict Resolution Mode:', 'modern-hotel-booking' ); ?></label>
							<select name="conflict_mode" class="regular-text" style="width:100%; max-width:100%;">
								<option value="create_new"><?php esc_html_e( 'Create New (Assign fresh booking tokens & IDs)', 'modern-hotel-booking' ); ?></option>
								<option value="merge_update"><?php esc_html_e( 'Merge / Update (Update existing bookings matching ID/token)', 'modern-hotel-booking' ); ?></option>
								<option value="skip_existing"><?php esc_html_e( 'Skip Existing (Preserve existing database records)', 'modern-hotel-booking' ); ?></option>
							</select>
						</div>

						<div class="mhbo-modal-footer" style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; border-top:1px solid #f1f5f9; padding-top:15px;">
							<button type="button" class="button mhbo-modal-cancel"><?php esc_html_e( 'Cancel', 'modern-hotel-booking' ); ?></button>
							<button type="submit" class="button button-primary"><span class="dashicons dashicons-upload" style="margin-top:4px;"></span> <?php esc_html_e( 'Start Import', 'modern-hotel-booking' ); ?></button>
						</div>
					</form>
				</div>
			</div>
			<?php endif; ?>
				<?php /* BUILD_PRO_END */ ?>
			<?php endif; // End of ! $edit_mode && ! $add_mode ?>
		</div>
		<?php
	}

	/* BUILD_PRO_START */
	public function display_housekeeping_page(): void {
		if ( ! current_user_can( 'manage_options' ) && ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		$nonce       = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		$action      = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$target_date = isset( $_REQUEST['manifest_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['manifest_date'] ) ) : HotelTime::today();
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $target_date ) ) {
			$target_date = HotelTime::today();
		}

		if ( 'print_housekeeping_manifest' === $action ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_housekeeping_manifest' ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			if ( class_exists( \MHBO\Pro\HousekeepingManifest::class ) ) {
				\MHBO\Pro\HousekeepingManifest::render_printable_view( $target_date );
				exit;
			}
		} elseif ( 'email_housekeeping_manifest' === $action ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_housekeeping_manifest' ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			if ( class_exists( \MHBO\Pro\HousekeepingManifest::class ) ) {
				$recipient = get_option( 'mhbo_housekeeping_email', '' );
				if ( empty( $recipient ) ) {
					$recipient = get_option( 'mhbo_notification_email', get_option( 'admin_email' ) );
				}
				$sent = \MHBO\Pro\HousekeepingManifest::send_manifest_email( $target_date, $recipient );
				if ( $sent ) {
					/* translators: 1: manifest date, 2: recipient email */
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Daily Housekeeping Manifest for %1$s has been emailed successfully to %2$s.', 'modern-hotel-booking' ), $target_date, $recipient ) ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to email Housekeeping Manifest. Please check your notification email configuration in Settings.', 'modern-hotel-booking' ) . '</p></div>';
				}
			}
		}

		$manifest = class_exists( \MHBO\Pro\HousekeepingManifest::class )
			? \MHBO\Pro\HousekeepingManifest::get_manifest_data( $target_date )
			: array(
				'date'    => $target_date,
				'summary' => array(
					'turnovers'  => 0,
					'departures' => 0,
					'arrivals'   => 0,
					'stayovers'  => 0,
					'vacant'     => 0,
				),
				'rooms'   => array(),
			);

		$hk_email   = get_option( 'mhbo_housekeeping_email', get_option( 'mhbo_notification_email', get_option( 'admin_email' ) ) );
		$auto_send  = (int) get_option( 'mhbo_housekeeping_auto_send', 1 );
		$daily_time = get_option( 'mhbo_housekeeping_daily_time', '08:00' );
		?>
		<div class="wrap mhbo-admin-wrap mhbo-housekeeping-wrap">
			<?php
			AdminUI::render_header(
				__( 'Housekeeping & Turnover Manifest', 'modern-hotel-booking' ),
				__( 'Daily room turnover schedules, stay-overs, check-out cleans, arrivals, and operational housekeeping tasks.', 'modern-hotel-booking' ),
				array(
					array(
						'label'  => __( '🖨️ Print Manifest', 'modern-hotel-booking' ),
						'url'    => wp_nonce_url( admin_url( 'admin.php?page=mhbo-housekeeping&action=print_housekeeping_manifest&manifest_date=' . $target_date ), 'mhbo_housekeeping_manifest' ),
						'class'  => 'button-secondary',
						'target' => '_blank',
					),
					array(
						'label' => __( '✉️ Email Team Now', 'modern-hotel-booking' ),
						'url'   => wp_nonce_url( admin_url( 'admin.php?page=mhbo-housekeeping&action=email_housekeeping_manifest&manifest_date=' . $target_date ), 'mhbo_housekeeping_manifest' ),
						'class' => 'button-primary',
					),
					array(
						'label' => __( '⚙️ Settings', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-settings&tab=general' ),
						'class' => 'button-secondary',
					),
				),
				array(
					array(
						'label' => I18n::get_label( 'menu_main' ),
						'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
					),
				)
			);
			?>

			<!-- Date Filter & Quick Switcher Card -->
			<div class="mhbo-card" style="margin-bottom: 24px; padding: 18px 24px;">
				<div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;">
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display: flex; align-items: center; gap: 12px; margin: 0;">
						<input type="hidden" name="page" value="mhbo-housekeeping">
						<label for="manifest_date" style="font-weight: 700; color: var(--mhbo-midnight, #0f172a); font-size: 14px;">📅 <?php esc_html_e( 'Manifest Date:', 'modern-hotel-booking' ); ?></label>
						<input type="date" id="manifest_date" name="manifest_date" value="<?php echo esc_attr( $target_date ); ?>" class="regular-text" style="width: 170px; height: 38px; border-radius: 6px; border: 1px solid #cbd5e1;" onchange="this.form.submit()">
						<input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'View Date', 'modern-hotel-booking' ); ?>" style="height: 38px;">
						<?php if ( $target_date !== HotelTime::today() ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-housekeeping' ) ); ?>" class="button button-link" style="height: 38px; line-height: 36px;"><?php esc_html_e( 'Reset to Today', 'modern-hotel-booking' ); ?></a>
						<?php endif; ?>
					</form>
					<div style="font-size: 13px; color: #64748b;">
						<strong><?php esc_html_e( 'Notification Recipient:', 'modern-hotel-booking' ); ?></strong> <?php echo esc_html( $hk_email ?: __( 'None configured', 'modern-hotel-booking' ) ); ?>
						<?php if ( $auto_send ) : ?>
							<span style="display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 12px; background: #dcfce7; color: #166534; font-weight: 600; font-size: 11px;">
								<?php
								/* translators: %s: daily auto-send time (e.g. 07:00) */
								echo esc_html( sprintf( __( 'Auto-Send %s daily', 'modern-hotel-booking' ), $daily_time ) );
								?>
							</span>
						<?php else : ?>
							<span style="display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 12px; background: #fee2e2; color: #991b1b; font-weight: 600; font-size: 11px;"><?php esc_html_e( 'Auto-Send Off', 'modern-hotel-booking' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Operational Briefing Strip -->
			<div class="mhbo-ops-briefing-strip" style="margin-bottom: 24px;">
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#fee2e2; color:#991b1b;">🔄</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $manifest['summary']['turnovers']; ?></span>
						<span class="mhbo-ops-label"><?php esc_html_e( 'Turnovers', 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#ffedd5; color:#9a3412;">🚪</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $manifest['summary']['departures']; ?></span>
						<span class="mhbo-ops-label"><?php esc_html_e( 'Departures', 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#dcfce7; color:#166534;">🧳</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $manifest['summary']['arrivals']; ?></span>
						<span class="mhbo-ops-label"><?php esc_html_e( 'Arrivals', 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#e0e7ff; color:#3730a3;">🛏️</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $manifest['summary']['stayovers']; ?></span>
						<span class="mhbo-ops-label"><?php esc_html_e( 'Stay-Overs', 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Room Turnover Status Table Card -->
			<div class="mhbo-card" style="padding: 0; overflow: hidden;">
				<table class="wp-list-table widefat fixed striped" style="border: none; box-shadow: none;">
					<thead>
						<tr>
							<th style="width:120px; font-weight:700;"><?php esc_html_e( 'Room', 'modern-hotel-booking' ); ?></th>
							<th style="width:160px; font-weight:700;"><?php esc_html_e( 'Room Type', 'modern-hotel-booking' ); ?></th>
							<th style="width:180px; font-weight:700;"><?php esc_html_e( 'Turnover Status', 'modern-hotel-booking' ); ?></th>
							<th style="font-weight:700;"><?php esc_html_e( 'Departing Guest', 'modern-hotel-booking' ); ?></th>
							<th style="font-weight:700;"><?php esc_html_e( 'Arriving Guest', 'modern-hotel-booking' ); ?></th>
							<th style="font-weight:700;"><?php esc_html_e( 'Stay-over Guest', 'modern-hotel-booking' ); ?></th>
							<th style="width:200px; font-weight:700;"><?php esc_html_e( 'Housekeeping Action', 'modern-hotel-booking' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $manifest['rooms'] ) ) : ?>
							<tr>
								<td colspan="7" style="text-align:center; padding:40px; color:#64748b;">
									<div style="font-size:24px; margin-bottom:8px;">🧹</div>
									<strong><?php esc_html_e( 'No rooms or reservations recorded for this date.', 'modern-hotel-booking' ); ?></strong>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $manifest['rooms'] as $r ) : ?>
								<?php
								$status_badge_bg    = '#f1f5f9';
								$status_badge_color = '#475569';
								$action_text        = __( 'Standard Inspection', 'modern-hotel-booking' );
								if ( 'turnover' === $r['status'] ) {
									$status_badge_bg    = '#fee2e2';
									$status_badge_color = '#991b1b';
									$action_text        = __( '⚡ Priority Turnover', 'modern-hotel-booking' );
								} elseif ( 'departure' === $r['status'] ) {
									$status_badge_bg    = '#ffedd5';
									$status_badge_color = '#9a3412';
									$action_text        = __( '🧹 Checkout Clean', 'modern-hotel-booking' );
								} elseif ( 'arrival' === $r['status'] ) {
									$status_badge_bg    = '#dcfce7';
									$status_badge_color = '#166534';
									$action_text        = __( '✨ Pre-Arrival Prep', 'modern-hotel-booking' );
								} elseif ( 'stayover' === $r['status'] ) {
									$status_badge_bg    = '#e0e7ff';
									$status_badge_color = '#3730a3';
									$action_text        = __( '🛏️ Stayover Service', 'modern-hotel-booking' );
								}
								?>
								<tr>
									<td style="padding: 12px 10px;"><strong><?php echo esc_html( $r['room_number'] ); ?></strong></td>
									<td style="padding: 12px 10px;"><?php echo esc_html( (string) ( I18n::decode( $r['room_type'] ) ?? '' ) ); ?></td>
									<td style="padding: 12px 10px;">
										<span style="display:inline-block; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:600; background:<?php echo esc_attr( $status_badge_bg ); ?>; color:<?php echo esc_attr( $status_badge_color ); ?>;">
											<?php echo esc_html( $r['status_label'] ); ?>
										</span>
									</td>
									<td style="padding: 12px 10px;">
										<?php if ( $r['departing'] ) : ?>
											<strong><?php echo esc_html( $r['departing']->customer_name ); ?></strong>
											<?php
											$dep_adults = max( 1, (int) ( $r['departing']->guests ?? 1 ) );
											$dep_kids   = max( 0, (int) ( $r['departing']->children ?? 0 ) );
											/* translators: %d: number of adults */
											$dep_pax = sprintf( _n( '%d Adult', '%d Adults', $dep_adults, 'modern-hotel-booking' ), $dep_adults );
											if ( $dep_kids > 0 ) {
												/* translators: %d: number of children */
												$dep_pax .= ', ' . sprintf( _n( '%d Child', '%d Children', $dep_kids, 'modern-hotel-booking' ), $dep_kids );
											}
											?>
											<div style="font-size:11px; color:#64748b; margin-top:2px;">
												<span style="font-weight:600; color:#475569;"><?php echo esc_html( $dep_pax ); ?></span> &bull; <?php esc_html_e( 'Out:', 'modern-hotel-booking' ); ?> <?php echo esc_html( $r['departing']->check_out ); ?>
											</div>
										<?php else : ?>
											<span style="color:#94a3b8;">—</span>
										<?php endif; ?>
									</td>
									<td style="padding: 12px 10px;">
										<?php if ( $r['arriving'] ) : ?>
											<strong><?php echo esc_html( $r['arriving']->customer_name ); ?></strong>
											<?php if ( ! empty( $r['arriving']->guest_vip_status ) && 'none' !== $r['arriving']->guest_vip_status ) : ?>
												<span style="font-size:10px; font-weight:700; color:#b45309; margin-left:4px;">★ VIP</span>
											<?php endif; ?>
											<?php
											$arr_adults = max( 1, (int) ( $r['arriving']->guests ?? 1 ) );
											$arr_kids   = max( 0, (int) ( $r['arriving']->children ?? 0 ) );
											/* translators: %d: number of adults */
											$arr_pax = sprintf( _n( '%d Adult', '%d Adults', $arr_adults, 'modern-hotel-booking' ), $arr_adults );
											if ( $arr_kids > 0 ) {
												/* translators: %d: number of children */
												$arr_pax .= ', ' . sprintf( _n( '%d Child', '%d Children', $arr_kids, 'modern-hotel-booking' ), $arr_kids );
											}
											?>
											<div style="font-size:11px; color:#64748b; margin-top:2px;">
												<span style="font-weight:600; color:#475569;"><?php echo esc_html( $arr_pax ); ?></span> &bull; <?php esc_html_e( 'In:', 'modern-hotel-booking' ); ?> <?php echo esc_html( $r['arriving']->check_in ); ?>
											</div>
										<?php else : ?>
											<span style="color:#94a3b8;">—</span>
										<?php endif; ?>
									</td>
									<td style="padding: 12px 10px;">
										<?php if ( $r['stayover'] ) : ?>
											<strong><?php echo esc_html( $r['stayover']->customer_name ); ?></strong>
											<?php if ( ! empty( $r['stayover']->guest_vip_status ) && 'none' !== $r['stayover']->guest_vip_status ) : ?>
												<span style="font-size:10px; font-weight:700; color:#b45309; margin-left:4px;">★ VIP</span>
											<?php endif; ?>
											<?php
											$stay_adults = max( 1, (int) ( $r['stayover']->guests ?? 1 ) );
											$stay_kids   = max( 0, (int) ( $r['stayover']->children ?? 0 ) );
											/* translators: %d: number of adults */
											$stay_pax = sprintf( _n( '%d Adult', '%d Adults', $stay_adults, 'modern-hotel-booking' ), $stay_adults );
											if ( $stay_kids > 0 ) {
												/* translators: %d: number of children */
												$stay_pax .= ', ' . sprintf( _n( '%d Child', '%d Children', $stay_kids, 'modern-hotel-booking' ), $stay_kids );
											}
											?>
											<div style="font-size:11px; color:#64748b; margin-top:2px;">
												<span style="font-weight:600; color:#475569;"><?php echo esc_html( $stay_pax ); ?></span> &bull; <?php echo esc_html( $r['stayover']->check_in ); ?> &rarr; <?php echo esc_html( $r['stayover']->check_out ); ?>
											</div>
										<?php else : ?>
											<span style="color:#94a3b8;">—</span>
										<?php endif; ?>
									</td>
									<td style="padding: 12px 10px;">
										<span style="font-weight:600; font-size:12px;"><?php echo esc_html( $action_text ); ?></span>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	public function display_tablet_mode_page(): void {
		if ( ! current_user_can( 'manage_options' ) && ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		if ( class_exists( \MHBO\Pro\TabletReservationMode::class ) ) {
			\MHBO\Pro\TabletReservationMode::render();
		}
	}
	/* BUILD_PRO_END */

	public function display_room_types_page(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		global $wpdb;
		$table     = $wpdb->prefix . 'mhbo_room_types';
		$edit_mode = false;
		$edit_data = null;
		$currency  = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );

		// Rule 11: Extract and sanitize all inputs at start
		$action           = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id               = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$nonce            = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$submit_room_type = isset( $_POST['submit_room_type'] );

		/* BUILD_PRO_START */
		// Calendar Action — render full-page calendar for this room type.
		if ( 'calendar' === $action && $id > 0 ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_calendar_type_' . $id ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			if ( class_exists( AdminCalendar::class ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: Fetching room type name for calendar display header via primary key.
				$type_row  = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}mhbo_room_types WHERE id = %d", $id ) );
				$type_name = $type_row ? ( (string) ( I18n::decode( $type_row->name ) ?? '#' . $id ) ) : '#' . $id;
				AdminCalendar::render_type_calendar( $id, $type_name );
				return;
			}
		}
		/* BUILD_PRO_END */

		// Delete Action
		if ( 'delete' === $action && $id > 0 ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_delete_room_type_' . $id ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			$wpdb->delete( $table, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
			Cache::invalidate_rooms();
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_type_deleted' ) ) . '</p></div>';
		}

		// Edit Action
		if ( 'edit' === $action && $id > 0 ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_edit_room_type_' . $id ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			$edit_mode = true;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: Fetching room type configuration for editing.
			$edit_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_room_types WHERE id = %d", $id ) );
		}

		// Save/Update Action
		if ( $submit_room_type ) {
			$room_type_id = isset( $_POST['room_type_id'] ) ? absint( wp_unslash( $_POST['room_type_id'] ) ) : 0;
			$nonce_action = $room_type_id > 0 ? 'mhbo_edit_room_type_' . $room_type_id : 'mhbo_add_room_type';

			if ( ! check_admin_referer( $nonce_action ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}

			$raw_amenities = isset( $_POST['amenities'] ) && is_array( $_POST['amenities'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['amenities'] ) ) : array();
			$amenities     = ! empty( $raw_amenities ) ? wp_json_encode( $raw_amenities ) : '';

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized/unslashed on next line
			$raw_room_name = $_POST['room_name'] ?? '';
			$room_name     = is_array( $raw_room_name ) ? I18n::encode( array_map( 'sanitize_text_field', wp_unslash( $raw_room_name ) ) ) : sanitize_text_field( wp_unslash( $raw_room_name ) );

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized/unslashed on next line
			$raw_room_desc = $_POST['room_description'] ?? '';
			$room_desc     = is_array( $raw_room_desc ) ? I18n::encode( array_map( 'sanitize_textarea_field', wp_unslash( $raw_room_desc ) ) ) : sanitize_textarea_field( wp_unslash( $raw_room_desc ) );

			$base_price = Money::fromDecimal( isset( $_POST['base_price'] ) ? sanitize_text_field( wp_unslash( $_POST['base_price'] ) ) : '0', $currency )->toDecimal();
			$max_adults = isset( $_POST['max_adults'] ) ? absint( wp_unslash( $_POST['max_adults'] ) ) : 1;
			$image_url  = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';

			$data = array(
				'name'                 => $room_name,
				'description'          => $room_desc,
				'base_price'           => $base_price,
				'max_adults'           => $max_adults,
				/* BUILD_STARTER_START */
				'max_children'         => absint( wp_unslash( $_POST['max_children'] ?? 0 ) ),
				'child_age_free_limit' => absint( wp_unslash( $_POST['child_age_free_limit'] ?? 0 ) ),
				'child_rate'           => Money::fromDecimal( isset( $_POST['child_rate'] ) ? sanitize_text_field( wp_unslash( $_POST['child_rate'] ) ) : '0', $currency )->toDecimal(),
				/* BUILD_STARTER_END */
				'amenities'            => $amenities,
				'image_url'            => $image_url,
			);

			if ( $room_type_id > 0 ) {
				$wpdb->update( $table, $data, array( 'id' => $room_type_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
				Cache::invalidate_rooms();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_type_updated' ) ) . '</p></div>';
				$edit_mode = false;
			} else {
				$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
				Cache::invalidate_rooms();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_type_added' ) ) . '</p></div>';
			}
		}



        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: Loading room type list for administration dashboard.
		$types             = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mhbo_room_types" );
		$current_amenities = ( $edit_mode && isset( $edit_data->amenities ) && $edit_data->amenities ) ? json_decode( $edit_data->amenities, true ) : array();
		if ( ! is_array( $current_amenities ) ) {
			$current_amenities = array();
		}

		$total_types = count( $types );
		$cur_admin   = Pricing::get_currency_code();
		$types_sum_m = Money::fromCents( 0, $cur_admin );
		foreach ( $types as $t ) {
			$types_sum_m = $types_sum_m->add( Money::fromDecimal( (string) ( $t->base_price ?? 0 ), $cur_admin ) );
		}
		$avg_rate_m = $total_types > 0 ? $types_sum_m->divide( (string) $total_types ) : Money::fromCents( 0, $cur_admin );
		$max_pax    = $total_types > 0 ? max( 0, ...array_map( fn( $t ) => (int) $t->max_adults, $types ) ) : 0;
		?>
		<div class="wrap mhbo-admin-wrap mhbo-room-types-wrap">
			<?php
			AdminUI::render_header(
				I18n::get_label( 'title_room_types_config' ),
				I18n::get_label( 'desc_room_types_config' ),
				array(
					array(
						'label' => __( '➕ New Room Type', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-room-types' ),
						'class' => 'button-primary',
					),
					array(
						'label' => __( '🏨 Physical Rooms', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-rooms' ),
						'class' => 'button-secondary',
					),
					array(
						'label' => __( '📅 Master Calendar', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings#mhbo-calendar' ),
						'class' => 'button-secondary',
					),
				),
				array(
					array(
						'label' => I18n::get_label( 'menu_main' ),
						'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
					),
				)
			);
			?>

			<!-- 3-KPI Overview Ribbon -->
			<div class="mhbo-stats-grid" style="margin-bottom: 24px;">
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value"><?php echo esc_html( (string) $total_types ); ?></div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Room Categories', 'modern-hotel-booking' ); ?></div>
				</div>
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value" style="color: #2563eb;"><?php echo esc_html( I18n::format_currency( $avg_rate_m ) ); ?></div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Avg. Nightly Base Rate', 'modern-hotel-booking' ); ?></div>
				</div>
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value" style="color: #166534;"><?php echo esc_html( (string) $max_pax ); ?> <?php esc_html_e( 'Guests', 'modern-hotel-booking' ); ?></div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Max Category Capacity', 'modern-hotel-booking' ); ?></div>
				</div>
			</div>

			<div class="mhbo-card mhbo-room-form-card mhbo-animate-in" id="mhbo-room-type-form-card" style="margin-bottom: 30px;">
				<h3 class="mhbo-card-title" style="margin-top:0; margin-bottom:20px; font-size:1.15rem; display:flex; align-items:center; gap:8px;">
					<span class="dashicons dashicons-category" style="color:#2563eb;"></span>
					<?php echo $edit_mode ? esc_html( I18n::get_label( 'title_modify_room_config' ) ) : esc_html( I18n::get_label( 'title_define_new_room' ) ); ?>
				</h3>

				<form method="post" action="" class="mhbo-modern-form-layout">
					<?php wp_nonce_field( $edit_mode ? 'mhbo_edit_room_type_' . $edit_data->id : 'mhbo_add_room_type' ); ?>
					<?php if ( $edit_mode ) : ?>
						<input type="hidden" name="room_type_id" value="<?php echo esc_attr( $edit_data->id ); ?>">
					<?php endif; ?>
						<div class="mhbo-form-section">
							<h4 class="mhbo-section-title"><?php esc_html_e( 'Content & Localization', 'modern-hotel-booking' ); ?></h4>
							
							<div class="mhbo-lang-tabs-container">
								<nav class="mhbo-tab-nav">
									<?php foreach ( I18n::get_available_languages() as $index => $lang ) : ?>
										<button type="button" class="mhbo-tab-btn <?php echo 0 === $index ? 'mhbo-tab-active' : ''; ?>" data-tab="lang-<?php echo esc_attr( $lang ); ?>">
											<?php echo esc_html( strtoupper( $lang ) ); ?>
										</button>
									<?php endforeach; ?>
								</nav>

								<div class="mhbo-tab-panes">
									<?php foreach ( I18n::get_available_languages() as $index => $lang ) : ?>
										<div class="mhbo-tab-content" id="lang-<?php echo esc_attr( $lang ); ?>" style="<?php echo 0 === $index ? 'display:block;' : 'display:none;'; ?>">
											<div class="mhbo-field-group">
												<label class="mhbo-label"><?php esc_html_e( 'Room Name', 'modern-hotel-booking' ); ?></label>
												<input type="text" name="room_name[<?php echo esc_attr( $lang ); ?>]"
													value="<?php echo $edit_mode ? esc_attr( (string) ( I18n::decode( $edit_data->name, $lang ) ?? '' ) ) : ''; ?>"
													class="mhbo-input-large" placeholder="<?php esc_attr_e( 'e.g. Deluxe Sea View Suite', 'modern-hotel-booking' ); ?>">
											</div>
											<div class="mhbo-field-group">
												<label class="mhbo-label"><?php esc_html_e( 'Description', 'modern-hotel-booking' ); ?></label>
												<textarea name="room_description[<?php echo esc_attr( $lang ); ?>]" rows="4"
													class="mhbo-input-large" placeholder="<?php esc_attr_e( 'Describe the features, view, and unique selling points...', 'modern-hotel-booking' ); ?>"><?php echo $edit_mode ? esc_textarea( (string) ( I18n::decode( $edit_data->description, $lang ) ?? '' ) ) : ''; ?></textarea>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						</div>

						<div class="mhbo-form-section">
							<h4 class="mhbo-section-title"><?php esc_html_e( 'Pricing & Capacity', 'modern-hotel-booking' ); ?></h4>
							<div class="mhbo-settings-grid">
								<div class="mhbo-settings-item">
									<label class="mhbo-label"><?php esc_html_e( 'Nightly Base Rate', 'modern-hotel-booking' ); ?></label>
									<div class="mhbo-input-prefix-container">
										<span class="mhbo-input-prefix"><?php echo esc_html( $currency ); ?></span>
										<input type="number" step="any" name="base_price"
											value="<?php echo $edit_mode ? esc_attr( Money::fromDecimal( (string) $edit_data->base_price, $currency )->toDecimal() ) : ''; ?>" required
											class="mhbo-input-mid">
									</div>
								</div>
								<div class="mhbo-settings-item">
									<label class="mhbo-label"><?php esc_html_e( 'Adult Capacity', 'modern-hotel-booking' ); ?></label>
									<input type="number" name="max_adults"
										value="<?php echo $edit_mode ? esc_attr( $edit_data->max_adults ) : '2'; ?>"
										class="mhbo-input-mid" min="1">
								</div>
								<?php /* BUILD_STARTER_START */ ?>
								<div class="mhbo-settings-item">
									<label class="mhbo-label"><?php esc_html_e( 'Child Capacity', 'modern-hotel-booking' ); ?></label>
									<input type="number" name="max_children"
										value="<?php echo $edit_mode ? esc_attr( (string) ( $edit_data->max_children ?? 0 ) ) : '0'; ?>"
										class="mhbo-input-mid" min="0">
								</div>
								<div class="mhbo-settings-item">
									<label class="mhbo-label"><?php esc_html_e( 'Child Free Age', 'modern-hotel-booking' ); ?></label>
									<input type="number" name="child_age_free_limit"
										value="<?php echo $edit_mode ? esc_attr( (string) ( $edit_data->child_age_free_limit ?? 0 ) ) : '0'; ?>"
										class="mhbo-input-mid" min="0">
								</div>
								<div class="mhbo-settings-item">
									<label class="mhbo-label"><?php esc_html_e( 'Extra Child Rate', 'modern-hotel-booking' ); ?></label>
									<div class="mhbo-input-prefix-container">
										<span class="mhbo-input-prefix"><?php echo esc_html( $currency ); ?></span>
										<input type="number" step="any" name="child_rate"
											value="<?php echo $edit_mode ? esc_attr( Money::fromDecimal( (string) ( $edit_data->child_rate ?? '0.00' ), $currency )->toDecimal() ) : '0.00'; ?>"
											class="mhbo-input-mid" min="0">
									</div>
								</div>
								<?php /* BUILD_STARTER_END */ ?>
							</div>
						</div>

						<div class="mhbo-form-section">
							<h4 class="mhbo-section-title"><?php esc_html_e( 'Media & Amenities', 'modern-hotel-booking' ); ?></h4>
							<div class="mhbo-field-group">
								<label class="mhbo-label"><?php esc_html_e( 'Featured Image', 'modern-hotel-booking' ); ?></label>
								<div class="mhbo-media-selector" style="display:flex; gap:10px; align-items:center;">
									<input type="text" name="image_url" id="mhbo_room_image_url"
										value="<?php echo $edit_mode ? esc_attr( $edit_data->image_url ) : ''; ?>" class="mhbo-input-large" placeholder="https://...">
									<button type="button" class="mhbo-btn mhbo-btn-outline mhbo-upload-button" data-target="#mhbo_room_image_url">
										<span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Select', 'modern-hotel-booking' ); ?>
									</button>
								</div>
							</div>

							<div class="mhbo-field-group">
								<label class="mhbo-label"><?php esc_html_e( 'Standard Amenities', 'modern-hotel-booking' ); ?></label>
								<div class="mhbo-amenities-check-grid">
								<?php
								$amenities_list = get_option(
									'mhbo_amenities_list',
									array(
										'wifi'      => I18n::get_label( 'label_amenity_wifi' ),
										'ac'        => I18n::get_label( 'label_amenity_ac' ),
										'tv'        => I18n::get_label( 'label_amenity_tv' ),
										'breakfast' => I18n::get_label( 'label_amenity_breakfast' ),
										'pool'      => I18n::get_label( 'label_amenity_pool' ),
										'minibar'   => I18n::get_label( 'label_amenity_minibar' ),
										'safe'      => I18n::get_label( 'label_amenity_safe' ),
										'parking'   => I18n::get_label( 'label_amenity_parking' ),
										'balcony'   => I18n::get_label( 'label_amenity_balcony' ),
									)
								);
								foreach ( $amenities_list as $key => $lbl ) :
									?>
									<label class="mhbo-checkbox-item">
										<input type="checkbox" name="amenities[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $current_amenities, true ) ); ?>>
										<span><?php echo esc_html( $lbl ); ?></span>
									</label>
								<?php endforeach; ?>
								</div>
							</div>
						</div>
				
					<div class="mhbo-form-actions-dock" style="margin-top:20px; display:flex; gap:12px; align-items:center;">
						<input type="submit" name="submit_room_type" class="button button-primary button-hero"
							value="<?php echo $edit_mode ? esc_attr( I18n::get_label( 'btn_save_config' ) ) : esc_attr( I18n::get_label( 'btn_create_room_type' ) ); ?>">
						<?php if ( $edit_mode ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-room-types' ) ); ?>"
								class="button button-secondary button-hero"><?php esc_html_e( 'Discard Changes', 'modern-hotel-booking' ); ?></a>
						<?php endif; ?>
					</div>
				</form>
			</div>

			<div class="mhbo-section-header" style="margin-bottom: 20px;">
				<h3 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 6px 0;"><?php esc_html_e( 'Defined Room Categories', 'modern-hotel-booking' ); ?></h3>
				<p style="margin: 0; color: #64748b; font-size: 13px;"><?php esc_html_e( 'Manage and edit your existing category specifications and rates.', 'modern-hotel-booking' ); ?></p>
			</div>

			<div class="mhbo-room-type-grid">
					<?php if ( count( $types ) === 0 ) : ?>
						<div class="mhbo-card" style="grid-column: 1 / -1; text-align: center; padding: 48px 24px;">
							<span class="dashicons dashicons-category" style="font-size: 48px; width: 48px; height: 48px; color: #cbd5e1; margin-bottom: 12px;"></span>
							<h4 style="margin: 0 0 6px 0; color: #1e293b;"><?php esc_html_e( 'No room categories defined yet.', 'modern-hotel-booking' ); ?></h4>
							<p style="margin: 0; color: #64748b; font-size: 13px;"><?php esc_html_e( 'Create your first room category using the builder above.', 'modern-hotel-booking' ); ?></p>
						</div>
					<?php else : ?>
						<?php foreach ( $types as $t ) : ?>
							<div class="mhbo-room-card mhbo-animate-in">
								<div class="mhbo-room-card-head">
									<div class="mhbo-room-thumb" style="width:72px; height:72px; min-width:72px; min-height:72px; position:relative; overflow:hidden; border-radius:10px; background:#f1f5f9;">
										<?php if ( $t->image_url ) : ?>
											<img src="<?php echo esc_url( $t->image_url ); ?>" class="mhbo-room-thumbnail" alt="<?php echo esc_attr( (string) ( I18n::decode( $t->name ) ?? '' ) ); ?>" loading="lazy" style="width:100%; height:100%; object-fit:cover; display:block;">
										<?php else : ?>
											<div class="mhbo-thumb-placeholder" style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#94a3b8;"><span class="dashicons dashicons-format-image" style="font-size:28px; width:28px; height:28px;"></span></div>
										<?php endif; ?>
										<div class="mhbo-room-badge-index" style="position:absolute; top:4px; left:4px; background:rgba(15,23,42,0.8); color:#fff; font-size:10px; font-weight:700; padding:1px 5px; border-radius:4px;">#<?php echo esc_html( $t->id ); ?></div>
									</div>
									<div class="mhbo-room-info-group" style="flex-grow:1;">
										<h4 class="mhbo-room-title" style="margin:0 0 4px 0; font-size:15px; font-weight:700; color:#0f172a;"><?php echo esc_html( (string) ( I18n::decode( $t->name ) ?? '' ) ); ?></h4>
										<span style="display:inline-block; font-size:13px; font-weight:700; color:#2563eb; background:#eff6ff; padding:2px 8px; border-radius:4px;">
											<?php echo esc_html( I18n::format_currency( $t->base_price ) ); ?> <small style="font-weight:500; color:#64748b;">/ <?php esc_html_e( 'night', 'modern-hotel-booking' ); ?></small>
										</span>
									</div>
								</div>
								<div class="mhbo-room-card-body" style="margin-top:14px;">
									<p class="mhbo-room-desc" style="margin:0 0 12px 0; font-size:13px; color:#475569; line-height:1.4;"><?php echo esc_html( wp_trim_words( (string) ( I18n::decode( $t->description ) ?? '' ), 12 ) ); ?></p>
									
									<div class="mhbo-room-meta-row" style="display:flex; gap:16px; margin-bottom:12px; font-size:12px; color:#64748b;">
										<div class="mhbo-meta-item">
											<span style="font-weight:600; color:#1e293b;">👥 <?php echo (int) $t->max_adults; ?> <?php esc_html_e( 'Adults', 'modern-hotel-booking' ); ?></span>
										</div>
										<?php if ( ! empty( $t->max_children ) ) : ?>
											<div class="mhbo-meta-item">
												<span style="font-weight:600; color:#1e293b;">👶 <?php echo (int) $t->max_children; ?> <?php esc_html_e( 'Children', 'modern-hotel-booking' ); ?></span>
											</div>
										<?php endif; ?>
									</div>

									<div class="mhbo-amenities-mini" style="display:flex; flex-wrap:wrap; gap:4px; margin-bottom:14px;">
										<?php
										if ( isset( $t->amenities ) && $t->amenities ) {
											$ams_array = json_decode( $t->amenities, true );
											if ( is_array( $ams_array ) ) {
												foreach ( array_slice( $ams_array, 0, 3 ) as $k ) {
													$label = isset( $amenities_list[ $k ] ) ? $amenities_list[ $k ] : $k;
													echo '<span class="mhbo-mini-tag" style="background:#f1f5f9; color:#475569; padding:2px 6px; border-radius:4px; font-size:11px;">' . esc_html( $label ) . '</span>';
												}
												if ( count( $ams_array ) > 3 ) {
													echo '<span class="mhbo-mini-tag-more" style="background:#e2e8f0; color:#334155; padding:2px 6px; border-radius:4px; font-size:11px; font-weight:700;">+' . ( count( $ams_array ) - 3 ) . '</span>';
												}
											}
										}
										?>
									</div>
								</div>
								<div class="mhbo-room-card-actions" style="display:flex; gap:8px; border-top:1px solid #f1f5f9; padding-top:12px; justify-content:flex-end;">
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-room-types&action=edit&id={$t->id}" ), 'mhbo_edit_room_type_' . $t->id ) ); ?>"
										class="button button-small" title="<?php esc_attr_e( 'Edit Category', 'modern-hotel-booking' ); ?>">
										<span class="dashicons dashicons-edit" style="margin-top:3px;"></span> <?php esc_html_e( 'Edit', 'modern-hotel-booking' ); ?>
									</a>
									<?php /* BUILD_PRO_START */ ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-room-types&action=calendar&id={$t->id}" ), 'mhbo_calendar_type_' . $t->id ) ); ?>"
										class="button button-small" title="<?php esc_attr_e( 'Calendar Pricing', 'modern-hotel-booking' ); ?>" style="background:#ede9fe; color:#6d28d9; border-color:#c4b5fd;">
										<span class="dashicons dashicons-calendar-alt" style="margin-top:3px;"></span> <?php esc_html_e( 'Calendar', 'modern-hotel-booking' ); ?>
									</a>
									<?php /* BUILD_PRO_END */ ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-room-types&action=delete&id={$t->id}" ), 'mhbo_delete_room_type_' . $t->id ) ); ?>"
										class="button button-small button-link-delete" title="<?php esc_attr_e( 'Delete', 'modern-hotel-booking' ); ?>"
										onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this room type? This may affect existing rooms.', 'modern-hotel-booking' ); ?>')">
										<span class="dashicons dashicons-trash" style="margin-top:3px;"></span>
									</a>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
		<?php
	}

	public function display_rooms_page(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		$is_pro_active = false;
		/* BUILD_PRO_START */
		$is_pro_active = License::is_active();
		/* BUILD_PRO_END */

		global $wpdb;
		$t_rooms           = esc_sql( $wpdb->prefix . 'mhbo_rooms' );
		$new_ical_table    = esc_sql( $wpdb->prefix . 'mhbo_ical_connections' );
		$legacy_ical_table = esc_sql( $wpdb->prefix . 'mhbo_ical_feeds' );
		$new_exists        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_ical_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES is a schema query; caching would give stale results after migrations
		$t_ical            = $new_exists ? $new_ical_table : $legacy_ical_table;

		$edit_mode  = false;
		$edit_data  = null;
		$ical_mode  = false;
		$ical_feeds = array();

		// Rule 11: Extract and sanitize all inputs at start
		$action      = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$sub_action  = isset( $_GET['sub_action'] ) ? sanitize_key( wp_unslash( $_GET['sub_action'] ) ) : '';
		$get_id      = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$get_feed_id = isset( $_GET['feed_id'] ) ? absint( wp_unslash( $_GET['feed_id'] ) ) : 0;
		$nonce       = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		// POST Actions
		$submit_ical_feed = isset( $_POST['submit_ical_feed'] );
		$submit_room      = isset( $_POST['submit_room'] );

		/* BUILD_PRO_START */
		// Calendar Mode — render full-page calendar for this individual room.
		if ( 'calendar' === $action && $get_id > 0 ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_calendar_room_' . $get_id ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			if ( class_exists( AdminCalendar::class ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: Fetching unit identifier for individual room calendar header.
				$room_row    = $wpdb->get_row( $wpdb->prepare( "SELECT room_number FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d", $get_id ) );
				$room_number = $room_row ? $room_row->room_number : '#' . $get_id;
				AdminCalendar::render_room_calendar( $get_id, $room_number );
				return;
			}
		}
		/* BUILD_PRO_END */

		// Delete Room Action
		if ( 'delete' === $action && $get_id > 0 && ( $sub_action === '' || null === $sub_action ) ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_delete_room_' . $get_id ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			$wpdb->delete( $t_rooms, array( 'id' => $get_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
			Cache::invalidate_rooms();
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_deleted' ) ) . '</p></div>';
		}

		// iCal Mode
		if ( 'ical' === $action && $get_id > 0 ) {
			// Sub-actions (delete_feed, sync_now) carry their own nonces — skip page-level check for them.
			if ( '' === $sub_action || null === $sub_action ) {
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_ical_room_' . $get_id ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
				}
			}
			
			if ( ! $is_pro_active ) {
				?>
				<div class="wrap mhbo-admin-wrap">
					<h1><?php esc_html_e( 'Manage Rooms', 'modern-hotel-booking' ); ?></h1>
					<?php
					if ( class_exists( Settings::class ) ) {
						Settings::render_pro_upsell();
					}
					?>
					<p style="margin-top: 20px;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-rooms' ) ); ?>" class="button">&larr;
							<?php esc_html_e( 'Back to Rooms', 'modern-hotel-booking' ); ?></a>
					</p>
				</div>
				<?php
				return;
			}
			/* BUILD_PRO_START */
			$ical_mode = true;
			/* BUILD_PRO_END */

			if ( $submit_ical_feed ) {
				if ( ! check_admin_referer( 'mhbo_add_ical' ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
				}

				$feed_name = isset( $_POST['feed_name'] ) ? sanitize_text_field( wp_unslash( $_POST['feed_name'] ) ) : '';
				$feed_url  = isset( $_POST['feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['feed_url'] ) ) : '';

				if ( '' === $feed_url || ! wp_http_validate_url( $feed_url ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid feed URL. Please provide a valid HTTPS address.', 'modern-hotel-booking' ) . '</p></div>';
				} elseif ( ! Security::is_safe_url( $feed_url ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Feed URL is not permitted for security reasons (internal addresses are blocked).', 'modern-hotel-booking' ) . '</p></div>';
				} else {
					// Auto-detect platform from URL so the main iCal manager shows the right icon.
					$url_lower = strtolower( $feed_url );
					if ( strpos( $url_lower, 'airbnb.com' ) !== false ) {
						$platform = 'airbnb';
					} elseif ( strpos( $url_lower, 'booking.com' ) !== false || strpos( $url_lower, 'admin.booking' ) !== false ) {
						$platform = 'booking.com';
					} elseif ( strpos( $url_lower, 'google.com' ) !== false || strpos( $url_lower, 'calendar.google' ) !== false ) {
						$platform = 'google_calendar';
					} else {
						$platform = 'custom';
					}

					if ( $new_exists ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
						$wpdb->insert(
							$t_ical,
							array(
								'room_id'        => $get_id,
								'name'           => $feed_name,
								'ical_url'       => $feed_url,
								'platform'       => $platform,
								'sync_direction' => 'import',
								'sync_status'    => 'pending',
								'created_at'     => HotelTime::now_mysql(),
							),
							array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
						);
					} else {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Legacy table
						$wpdb->insert(
							$t_ical,
							array(
								'room_id'   => $get_id,
								'feed_name' => $feed_name,
								'feed_url'  => $feed_url,
							),
							array( '%d', '%s', '%s' )
						);
					}
					Cache::invalidate_rooms();
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_feed_added' ) ) . '</p></div>';
				}
			}

			if ( 'delete_feed' === $sub_action && $get_feed_id > 0 ) {
				check_admin_referer( 'mhbo_delete_feed_' . $get_feed_id );
				// Scope delete to room_id so a user can't delete another room's feed by guessing IDs.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete(
					$t_ical,
					array(
						'id'      => $get_feed_id,
						'room_id' => $get_id,
					),
					array( '%d', '%d' ) 
				);
			}

			/* BUILD_PRO_START */
			if ( 'sync_feed' === $sub_action && $get_feed_id > 0 ) {
				check_admin_referer( 'mhbo_sync_feed_' . $get_feed_id );
				if ( class_exists( 'MHBO\Pro\ICalManager' ) ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: Using %i for safe table name placeholder.
					$this_conn = $wpdb->get_row(
						$wpdb->prepare(
							'SELECT id, room_id FROM %i WHERE id = %d AND room_id = %d',
							$t_ical,
							$get_feed_id,
							$get_id
						)
					);
					if ( $this_conn ) {
						\MHBO\Pro\ICalManager::get_instance()->sync_single_connection(
							(int) $this_conn->id,
							(int) $this_conn->room_id
						);
					}
				}
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_sync_completed' ) ) . '</p></div>';
			}
			/* BUILD_PRO_END */

			if ( 'sync_now' === $sub_action ) {
				check_admin_referer( 'mhbo_sync_now_' . $get_id );
				ICal::sync_external_calendars();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_sync_completed' ) ) . '</p></div>';
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ical_feeds = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_ical_connections WHERE room_id = %d", $get_id ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$room_info = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d", $get_id ) );
		}

		// Edit Room Action
		if ( 'edit' === $action && $get_id > 0 ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_edit_room_' . $get_id ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			$edit_mode = true;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$edit_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d", $get_id ) );
		}

		// Save Room Action
		if ( $submit_room ) {
			$post_room_id = isset( $_POST['room_id'] ) ? absint( wp_unslash( $_POST['room_id'] ) ) : 0;
			$nonce_action = $post_room_id > 0 ? 'mhbo_edit_room_' . $post_room_id : 'mhbo_add_room';
			if ( ! check_admin_referer( $nonce_action ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}

			$type_id          = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;
			$room_number      = isset( $_POST['room_number'] ) ? sanitize_text_field( wp_unslash( $_POST['room_number'] ) ) : '';
			$room_status      = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'available';
			$custom_price_raw = isset( $_POST['custom_price'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_price'] ) ) : '';
			$room_image_url   = isset( $_POST['room_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['room_image_url'] ) ) : '';

			$data = array(
				'type_id'      => $type_id,
				'room_number'  => $room_number,
				'custom_price' => ( isset( $custom_price_raw ) && $custom_price_raw !== '' ) ? floatval( $custom_price_raw ) : null,
				'status'       => $room_status,
				'image_url'    => $room_image_url !== '' ? $room_image_url : null,
			);

			if ( $post_room_id > 0 ) {
				$wpdb->update( $t_rooms, $data, array( 'id' => $post_room_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
				Cache::invalidate_rooms();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_updated' ) ) . '</p></div>';
				$edit_mode = false;
			} else {
				$wpdb->insert( $t_rooms, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
				Cache::invalidate_rooms();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_added' ) ) . '</p></div>';
			}
		}



        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rooms = $wpdb->get_results( "SELECT r.*, t.name as type_name, t.base_price, t.image_url as type_image_url FROM {$wpdb->prefix}mhbo_rooms r LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id ORDER BY (r.room_number + 0) ASC, r.room_number ASC, r.id ASC" );
		if ( is_array( $rooms ) ) {
			usort(
				$rooms,
				function ( $a, $b ) {
					$cmp = strnatcasecmp( (string) $a->room_number, (string) $b->room_number );
					if ( 0 !== $cmp ) {
						return $cmp;
					}
					return ( (int) $a->id ) <=> ( (int) $b->id );
				}
			);
		}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$types = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mhbo_room_types" );

		$currency          = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$total_rooms       = count( $rooms );
		$available_count   = 0;
		$maintenance_count = 0;
		foreach ( $rooms as $r ) {
			if ( $r->status === 'available' ) {
				++$available_count;
			}
			if ( $r->status === 'maintenance' ) {
				++$maintenance_count;
			}
		}
		?>
		<div class="wrap mhbo-admin-wrap mhbo-rooms-wrap">
			<?php 
			AdminUI::render_header(
				I18n::get_label( 'title_room_inventory' ), 
				I18n::get_label( 'desc_room_inventory' ),
				array(
					array(
						'label' => __( '🏷️ Room Categories', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-room-types' ),
						'class' => 'button-secondary',
					),
					array(
						'label' => __( '📅 Master Calendar', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings#mhbo-calendar' ),
						'class' => 'button-secondary',
					),
				),
				array(
					array(
						'label' => I18n::get_label( 'menu_main' ),
						'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
					),
				)
			); 
			?>

			<!-- 3-KPI Overview Ribbon -->
			<div class="mhbo-stats-grid" style="margin-bottom: 24px;">
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value"><?php echo esc_html( (string) $total_rooms ); ?></div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Total Physical Units', 'modern-hotel-booking' ); ?></div>
				</div>
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value" style="color: #166534; display:flex; align-items:center; gap:8px;">
						<span class="mhbo-pulse-indicator" style="background:#22c55e;"></span>
						<?php echo esc_html( (string) $available_count ); ?>
					</div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Ready for Guests', 'modern-hotel-booking' ); ?></div>
				</div>
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value" style="color: #9a3412; display:flex; align-items:center; gap:8px;">
						<span class="mhbo-pulse-indicator mhbo-pulse-amber"></span>
						<?php echo esc_html( (string) $maintenance_count ); ?>
					</div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Out of Service / Maintenance', 'modern-hotel-booking' ); ?></div>
				</div>
			</div>

			<?php /* BUILD_PRO_START */ ?>
			<?php if ( $ical_mode && isset( $room_info ) ) : ?>
				<div class="mhbo-card accent" style="margin-bottom:30px; border-left: 4px solid #c5a059;">
					<h3 style="margin-top:0; margin-bottom: 20px; font-size: 1.2rem; display: flex; align-items: center;">
						<span class="dashicons dashicons-calendar-alt" style="margin-right: 10px; color: #c5a059;"></span>
						<?php
						/* translators: %s: room unit number or identifier */
						echo esc_html( sprintf( I18n::get_label( 'title_ical_sync_sprintf' ), $room_info->room_number ) );
						?>
					</h3>
					
					<div style="margin-bottom:25px; background: #fff; padding: 15px; border: 1px solid #e2e8f0; border-radius: 8px;">
						<label style="font-weight: 700; color: #1a3b5d;"><?php esc_html_e( 'Deployment Export URL', 'modern-hotel-booking' ); ?></label>
						<p class="description" style="margin-bottom: 10px;"><?php esc_html_e( 'Provide this URL to external OTAs (Airbnb, Booking.com) to export this room\'s availability.', 'modern-hotel-booking' ); ?></p>
						<div style="display:flex; gap:10px;">
							<?php
							$_ical_key = (string) get_option( 'mhbo_ical_key_' . (int) $room_info->id, '' );
							if ( '' === $_ical_key ) {
								$_ical_key = wp_generate_password( 24, false );
								update_option( 'mhbo_ical_key_' . (int) $room_info->id, $_ical_key, false );
							}
							$_room_export_url = add_query_arg( 'key', $_ical_key, home_url( '/mhbo-ical/room-' . (int) $room_info->id . '.ics' ) );
							?>
							<input type="text" value="<?php echo esc_url( $_room_export_url ); ?>" class="regular-text" readonly onclick="this.select()" style="flex-grow: 1; background: #f8fafc; font-family: monospace; font-size: 12px; border: 1px solid #cbd5e1;">
							<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(this.previousElementSibling.value).then(() => { this.innerText='<?php esc_attr_e( 'Copied!', 'modern-hotel-booking' ); ?>'; setTimeout(() => this.innerText='<?php esc_attr_e( 'Copy URL', 'modern-hotel-booking' ); ?>', 2000); })"><?php esc_html_e( 'Copy URL', 'modern-hotel-booking' ); ?></button>
						</div>
					</div>

					<div class="mhbo-sub-section" style="margin-top: 30px;">
						<h4 style="font-size: 1.1rem; margin-bottom: 15px;"><?php esc_html_e( 'Import External Calendars', 'modern-hotel-booking' ); ?></h4>
						<div class="mhbo-table-responsive" style="margin-bottom: 25px;">
							<table class="wp-list-table widefat fixed striped" style="box-shadow:none; border: 1px solid #eee;">
								<thead>
									<tr>
										<th style="width:130px;"><?php esc_html_e( 'Platform', 'modern-hotel-booking' ); ?></th>
										<th><?php esc_html_e( 'Connection Name', 'modern-hotel-booking' ); ?></th>
										<th style="width:110px;"><?php esc_html_e( 'Status', 'modern-hotel-booking' ); ?></th>
										<th style="width:160px;"><?php esc_html_e( 'Last Sync', 'modern-hotel-booking' ); ?></th>
										<th style="width:110px; text-align:right;"><?php esc_html_e( 'Actions', 'modern-hotel-booking' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php if ( array() === (array) $ical_feeds ) : ?>
										<tr><td colspan="5" style="text-align:center; color:#94a3b8; padding:30px; font-style:italic;"><?php esc_html_e( 'No external feeds connected yet.', 'modern-hotel-booking' ); ?></td></tr>
									<?php else : ?>
										<?php
										foreach ( $ical_feeds as $feed ) :
											$conn_platform  = isset( $feed->platform ) ? (string) $feed->platform : 'custom';
											$conn_name      = $feed->name ?? $feed->feed_name ?? '';
											$conn_status    = isset( $feed->sync_status ) ? (string) $feed->sync_status : 'pending';
											$conn_last_sync = $feed->last_sync ?? $feed->last_synced ?? null;
											$conn_error     = isset( $feed->last_error ) ? (string) $feed->last_error : '';
											$conn_events    = isset( $feed->events_count ) ? (int) $feed->events_count : 0;

											$platform_styles = array(
												'airbnb' => array(
													'label' => 'Airbnb',
													'bg' => '#ff5a5f',
													'fg' => '#fff',
												),
												'booking.com' => array(
													'label' => 'Booking.com',
													'bg' => '#003580',
													'fg' => '#fff',
												),
												'google_calendar' => array(
													'label' => 'Google Cal',
													'bg' => '#4285f4',
													'fg' => '#fff',
												),
												'custom' => array(
													'label' => 'Custom',
													'bg' => '#6b7280',
													'fg' => '#fff',
												),
											);
											$ps              = $platform_styles[ $conn_platform ] ?? $platform_styles['custom'];

											$status_styles = array(
												'success' => array(
													'color' => '#16a34a',
													'icon' => 'dashicons-yes-alt',
													'label' => 'Synced',
												),
												'failed'  => array(
													'color' => '#dc2626',
													'icon' => 'dashicons-warning',
													'label' => 'Failed',
												),
												'pending' => array(
													'color' => '#d97706',
													'icon' => 'dashicons-clock',
													'label' => 'Pending',
												),
											);
											$ss            = $status_styles[ $conn_status ] ?? $status_styles['pending'];

											$sync_url   = wp_nonce_url(
												add_query_arg(
													array(
														'page' => 'mhbo-rooms',
														'action' => 'ical',
														'id'   => (int) $room_info->id,
														'sub_action' => 'sync_feed',
														'feed_id' => (int) $feed->id,
													),
													admin_url( 'admin.php' )
												),
												'mhbo_sync_feed_' . (int) $feed->id
											);
											$delete_url = wp_nonce_url(
												add_query_arg(
													array(
														'page' => 'mhbo-rooms',
														'action' => 'ical',
														'id'   => (int) $room_info->id,
														'sub_action' => 'delete_feed',
														'feed_id' => (int) $feed->id,
													),
													admin_url( 'admin.php' )
												),
												'mhbo_delete_feed_' . (int) $feed->id
											);
											?>
											<tr>
												<td>
													<span style="display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; color:<?php echo esc_attr( $ps['fg'] ); ?>; background:<?php echo esc_attr( $ps['bg'] ); ?>;">
														<?php echo esc_html( $ps['label'] ); ?>
													</span>
												</td>
												<td>
													<strong style="color:#1e293b;"><?php echo esc_html( $conn_name ); ?></strong>
													<?php if ( '' !== $conn_error && 'failed' === $conn_status ) : ?>
														<br><span style="color:#dc2626; font-size:11px;"><?php echo esc_html( $conn_error ); ?></span>
													<?php endif; ?>
												</td>
												<td>
													<span class="dashicons <?php echo esc_attr( $ss['icon'] ); ?>" style="color:<?php echo esc_attr( $ss['color'] ); ?>; vertical-align:middle; font-size:16px;"></span>
													<span style="color:<?php echo esc_attr( $ss['color'] ); ?>; font-weight:600; font-size:12px;"><?php echo esc_html( $ss['label'] ); ?></span>
												</td>
												<td style="font-size:12px; color:#475569;">
													<?php if ( $conn_last_sync ) : ?>
														<?php echo esc_html( HotelTime::human_diff( $conn_last_sync ) ); ?> <?php esc_html_e( 'ago', 'modern-hotel-booking' ); ?>
														<?php if ( $conn_events > 0 ) : ?>
															<br><span style="color:#64748b;">
																<?php
																/* translators: %d: number of calendar events */
																echo esc_html( sprintf( _n( '%d event', '%d events', $conn_events, 'modern-hotel-booking' ), $conn_events ) );
																?>
															</span>
														<?php endif; ?>
													<?php else : ?>
														<span style="color:#d97706;"><?php esc_html_e( 'Never synced', 'modern-hotel-booking' ); ?></span>
													<?php endif; ?>
												</td>
												<td style="text-align:right; white-space:nowrap;">
													<a href="<?php echo esc_url( $sync_url ); ?>" class="button button-small" title="<?php esc_attr_e( 'Sync now', 'modern-hotel-booking' ); ?>">
														<span class="dashicons dashicons-update" style="margin-top:3px;"></span>
													</a>
													<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-link-delete button-small"
														onclick="return confirm('<?php esc_attr_e( 'Disconnect this calendar? Import of bookings will stop.', 'modern-hotel-booking' ); ?>')"
														title="<?php esc_attr_e( 'Disconnect', 'modern-hotel-booking' ); ?>">
														<span class="dashicons dashicons-trash" style="margin-top:3px;"></span>
													</a>
												</td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>

						<div style="background:#f8fafc; padding:20px; border-radius:10px; border:1px solid #e2e8f0;">
							<form method="post">
								<?php wp_nonce_field( 'mhbo_add_ical' ); ?>
								<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; align-items: flex-end;">
									<div>
										<label style="display:block; margin-bottom: 5px; font-weight: 600;"><?php esc_html_e( 'Connection Label', 'modern-hotel-booking' ); ?></label>
										<input type="text" name="feed_name" placeholder="<?php esc_attr_e( 'e.g. Airbnb, Booking.com', 'modern-hotel-booking' ); ?>" required style="width:100%; border-radius: 6px;">
									</div>
									<div style="grid-column: span 2;">
										<label style="display:block; margin-bottom: 5px; font-weight: 600;"><?php esc_html_e( 'iCal Feed URL (HTTPS)', 'modern-hotel-booking' ); ?></label>
										<input type="url" name="feed_url" placeholder="https://..." required style="width:100%; border-radius: 6px;">
									</div>
									<div>
										<input type="submit" name="submit_ical_feed" class="button button-primary" value="<?php esc_attr_e( 'Connect Calendar', 'modern-hotel-booking' ); ?>" style="width: 100%; height: 32px;">
									</div>
								</div>
							</form>
						</div>
					</div>

					<div style="margin-top:30px; border-top:1px solid #eee; padding-top:20px; display:flex; gap:15px; justify-content: space-between; align-items: center;">
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-rooms&action=ical&id={$room_info->id}&sub_action=sync_now" ), 'mhbo_sync_now_' . $room_info->id ) ); ?>"
							class="button button-secondary"><span class="dashicons dashicons-update" style="margin-top:4px;"></span> <?php esc_html_e( 'Force Global Sync', 'modern-hotel-booking' ); ?></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-rooms' ) ); ?>"
							class="button" style="font-weight: 600;"><?php esc_html_e( 'Cancel & Return', 'modern-hotel-booking' ); ?></a>
					</div>
				</div>
			<?php endif; ?>
			<?php /* BUILD_PRO_END */ ?>

			<?php if ( count( $types ) === 0 ) : ?>
				<div class="notice notice-warning inline" style="margin-bottom: 25px; padding: 16px 20px; border-left: 4px solid #dba617; background: #fff8e5; border-radius: 6px;">
					<h4 style="margin: 0 0 8px 0; font-size: 15px; font-weight: 700; color: #1d2327;">
						<?php esc_html_e( '⚠️ Step 1: Create a Room Type First', 'modern-hotel-booking' ); ?>
					</h4>
					<p style="margin: 0 0 12px 0; font-size: 13px; line-height: 1.5; color: #50575e;">
						<?php esc_html_e( 'Before registering physical rooms (e.g. Room 101, 102), you must create at least one Room Type (e.g. Deluxe Suite, Standard Double) with base pricing and occupancy settings.', 'modern-hotel-booking' ); ?>
					</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-room-types' ) ); ?>" class="button button-primary" style="font-weight: 600;">
						<?php esc_html_e( 'Create Your First Room Type →', 'modern-hotel-booking' ); ?>
					</a>
				</div>
			<?php endif; ?>

			<div class="mhbo-card <?php echo esc_attr( $edit_mode ? 'accent' : '' ); ?>" id="mhbo-add-unit-card" style="margin-bottom:30px; <?php echo esc_attr( $edit_mode ? 'border-left: 4px solid #3b82f6;' : '' ); ?>">
				<h3 style="margin-top:0; margin-bottom: 20px; font-size: 1.15rem; font-weight:700; color:#0f172a; display: flex; align-items: center; gap:8px;">
					<span class="dashicons dashicons-plus-alt" style="color: <?php echo esc_attr( $edit_mode ? '#3b82f6' : '#2563eb' ); ?>;"></span>
					<?php echo $edit_mode ? esc_html( I18n::get_label( 'title_modify_unit' ) ) : esc_html( I18n::get_label( 'title_new_unit' ) ); ?>
				</h3>
				<form method="post">
					<?php wp_nonce_field( $edit_mode ? 'mhbo_edit_room_' . $edit_data->id : 'mhbo_add_room' ); ?>
					<?php
					if ( $edit_mode ) :
						?>
						<input type="hidden" name="room_id" value="<?php echo esc_attr( $edit_data->id ); ?>"><?php endif; ?>
					
					<div class="mhbo-settings-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px;">
						<div class="mhbo-settings-item">
							<label class="mhbo-label"><?php esc_html_e( 'Classification / Type', 'modern-hotel-booking' ); ?> <span style="color:#ef4444;">*</span></label>
							<select name="type_id" class="mhbo-input-mid" required style="width:100%;">
								<option value=""><?php esc_html_e( '— Select Room Type —', 'modern-hotel-booking' ); ?></option>
								<?php foreach ( $types as $t ) : ?>
									<option value="<?php echo esc_attr( $t->id ); ?>" <?php echo ( $edit_mode && (int) $edit_data->type_id === (int) $t->id ) ? 'selected' : ''; ?>>
										<?php echo esc_html( (string) ( I18n::decode( $t->name ) ?? '' ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="mhbo-settings-item">
							<label class="mhbo-label"><?php esc_html_e( 'Room Identifier / Number', 'modern-hotel-booking' ); ?> <span style="color:#ef4444;">*</span></label>
							<input type="text" name="room_number" value="<?php echo $edit_mode ? esc_attr( $edit_data->room_number ) : ''; ?>" required class="mhbo-input-mid" placeholder="<?php esc_attr_e( 'e.g. Room 101, Junior Suite A', 'modern-hotel-booking' ); ?>">
						</div>
						<div class="mhbo-settings-item">
							<label class="mhbo-label">
								<?php esc_html_e( 'Override Daily Rate', 'modern-hotel-booking' ); ?>
								<span class="mhbo-tooltip"><i class="mhbo-help-icon">?</i><span class="mhbo-tooltip-text"><?php esc_html_e( 'Specific price for this unit. Leave at 0 to use the category default.', 'modern-hotel-booking' ); ?></span></span>
							</label>
							<div class="mhbo-input-prefix-container">
								<span class="mhbo-input-prefix"><?php echo esc_html( $currency ); ?></span>
								<input type="number" step="any" name="custom_price" value="<?php echo $edit_mode ? esc_attr( Money::fromDecimal( (string) ( $edit_data->custom_price ?? 0 ) )->toDecimal() ) : '0.00'; ?>" class="mhbo-input-mid">
							</div>
						</div>
						<div class="mhbo-settings-item">
							<label class="mhbo-label"><?php esc_html_e( 'Operational Status', 'modern-hotel-booking' ); ?></label>
							<select name="status" class="mhbo-input-mid" style="width:100%;">
								<option value="available" <?php echo ( $edit_mode && 'available' === $edit_data->status ) ? 'selected' : ''; ?>><?php esc_html_e( '🟢 Live & Reservable', 'modern-hotel-booking' ); ?></option>
								<option value="maintenance" <?php echo ( $edit_mode && 'maintenance' === $edit_data->status ) ? 'selected' : ''; ?>><?php esc_html_e( '🟠 Inactive / Maintenance', 'modern-hotel-booking' ); ?></option>
							</select>
						</div>
						<div class="mhbo-settings-item" style="grid-column: 1 / -1;">
							<label class="mhbo-label" for="mhbo_unit_image_url"><?php esc_html_e( 'Room Image (Optional)', 'modern-hotel-booking' ); ?></label>
							<div class="mhbo-media-selector">
								<input type="text" name="room_image_url" id="mhbo_unit_image_url"
									value="<?php echo $edit_mode ? esc_attr( $edit_data->image_url ?? '' ) : ''; ?>"
									class="mhbo-input-large" placeholder="https://...">
								<button type="button" class="mhbo-btn mhbo-btn-outline mhbo-upload-button" data-target="#mhbo_unit_image_url">
									<span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Select', 'modern-hotel-booking' ); ?>
								</button>
							</div>
							<p class="description" style="margin: 6px 0 0 0; color: #64748b; font-size: 12px;"><?php esc_html_e( 'Optional. Overrides the room type image for this specific unit on the frontend.', 'modern-hotel-booking' ); ?></p>
							<?php if ( $edit_mode && '' !== (string) ( $edit_data->image_url ?? '' ) ) : ?>
								<img src="<?php echo esc_url( $edit_data->image_url ); ?>" alt="" style="margin-top: 10px; max-height: 80px; border-radius: 8px; object-fit: cover; border: 1px solid #cbd5e1;">
							<?php endif; ?>
						</div>
					</div>

					<div class="mhbo-form-actions-dock" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0; display:flex; gap:12px; align-items:center;">
						<input type="submit" name="submit_room" class="button button-primary button-hero"
							value="<?php echo $edit_mode ? esc_attr( I18n::get_label( 'btn_save_unit' ) ) : esc_attr( I18n::get_label( 'btn_register_unit' ) ); ?>">
						<?php if ( $edit_mode ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-rooms' ) ); ?>"
								class="button button-secondary button-hero"><?php esc_html_e( 'Discard Changes', 'modern-hotel-booking' ); ?></a>
						<?php endif; ?>
					</div>
				</form>
			</div>

			<div class="mhbo-card" style="padding: 0; overflow: hidden; border-radius: 16px;">
				<div style="display:flex; justify-content:space-between; align-items:center; padding: 20px 24px; border-bottom: 1px solid #e2e8f0; background: #ffffff;">
					<h3 style="margin:0; font-size:1.15rem; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;">
						<span class="dashicons dashicons-list-view" style="color: #2563eb;"></span>
						<?php esc_html_e( 'Full Unit Inventory', 'modern-hotel-booking' ); ?>
					</h3>
					<span style="font-size:12px; color:#64748b; font-weight:700; background:#f1f5f9; padding:4px 12px; border-radius:99px; border:1px solid #e2e8f0;"><?php echo esc_html( (string) $total_rooms ); ?> <?php esc_html_e( 'Units Configured', 'modern-hotel-booking' ); ?></span>
				</div>
				<div class="mhbo-table-responsive">
					<table class="wp-list-table widefat fixed striped mhbo-modern-inventory-table" style="box-shadow: none; border: none; margin: 0;">
						<thead>
							<tr>
								<th style="width:60px;"><?php echo esc_html( I18n::get_label( 'label_col_id' ) ); ?></th>
								<th style="width:72px;"><?php esc_html_e( 'Image', 'modern-hotel-booking' ); ?></th>
								<th style="width:140px;"><?php echo esc_html( I18n::get_label( 'label_col_unit' ) ); ?></th>
								<th><?php echo esc_html( I18n::get_label( 'label_classification' ) ); ?></th>
								<th><?php echo esc_html( I18n::get_label( 'label_col_rate' ) ); ?></th>
								<th style="width:160px;"><?php echo esc_html( I18n::get_label( 'label_col_status' ) ); ?></th>
								<th style="width:170px; text-align: right;"><?php echo esc_html( I18n::get_label( 'label_col_mgmt' ) ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( count( $rooms ) === 0 ) : ?>
								<tr><td colspan="7" style="padding:48px 24px; text-align:center; color:#94a3b8; font-style: italic;"><?php esc_html_e( 'Inventory is completely empty. Register your first physical room unit above.', 'modern-hotel-booking' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $rooms as $r ) : ?>
									<tr>
										<td><span class="mhbo-id-badge">#<?php echo esc_html( $r->id ); ?></span></td>
										<td>
											<?php $thumb = ( isset( $r->image_url ) ? (string) $r->image_url : '' ) ?: ( $r->type_image_url ?? '' ); ?>
											<?php if ( $thumb ) : ?>
												<img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:52px; height:38px; object-fit:cover; border-radius:6px; display:block;">
											<?php else : ?>
												<span style="display:flex; align-items:center; justify-content:center; width:52px; height:38px; background:#f1f5f9; border-radius:6px; color:#94a3b8;"><span class="dashicons dashicons-format-image" style="font-size:20px; width:20px; height:20px;"></span></span>
											<?php endif; ?>
										</td>
										<td><strong style="font-size: 1.05rem; color: #0f172a;"><?php echo esc_html( $r->room_number ); ?></strong></td>
										<td>
											<span style="background: #eff6ff; color: #1e40af; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
												<?php echo esc_html( (string) ( I18n::decode( $r->type_name ) ?? '' ) ); ?>
											</span>
										</td>
										<td>
											<span style="font-weight: 700; color: #166534;"><?php echo esc_html( I18n::format_currency( $r->custom_price ?: $r->base_price ) ); ?></span>
											<?php if ( $r->custom_price ) : ?>
												<span class="mhbo-tooltip" style="margin-left: 5px;"><i class="mhbo-help-icon" style="background:#c5a059; color:#fff;">!</i><span class="mhbo-tooltip-text"><?php esc_html_e( 'Custom rate override active for this unit.', 'modern-hotel-booking' ); ?></span></span>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( $r->status === 'available' ) : ?>
												<span style="display: inline-flex; align-items: center; gap: 6px; color: #166534; background: #dcfce7; padding: 3px 10px; border-radius: 99px; font-weight: 700; font-size: 11px; text-transform: uppercase;">
													<span style="width: 6px; height: 6px; border-radius: 50%; background: #22c55e;"></span>
													<?php esc_html_e( 'Receiving Bookings', 'modern-hotel-booking' ); ?>
												</span>
											<?php else : ?>
												<span style="display: inline-flex; align-items: center; gap: 6px; color: #991b1b; background: #fee2e2; padding: 3px 10px; border-radius: 99px; font-weight: 700; font-size: 11px; text-transform: uppercase;">
													<span style="width: 6px; height: 6px; border-radius: 50%; background: #ef4444;"></span>
													<?php esc_html_e( 'Out of Service', 'modern-hotel-booking' ); ?>
												</span>
											<?php endif; ?>
										</td>
										<td style="text-align: right;">
											<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; min-width: 140px;">
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-rooms&action=edit&id={$r->id}" ), 'mhbo_edit_room_' . $r->id ) ); ?>"
													class="button button-small" title="<?php esc_attr_e( 'Edit Unit', 'modern-hotel-booking' ); ?>"><span class="dashicons dashicons-edit" style="margin-top:3px;"></span></a>
												
												<?php /* BUILD_PRO_START */ ?>
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-rooms&action=ical&id={$r->id}" ), 'mhbo_ical_room_' . $r->id ) ); ?>"
													class="button button-small" title="<?php esc_attr_e( 'iCal Connections', 'modern-hotel-booking' ); ?>"><span class="dashicons dashicons-update" style="margin-top:3px;"></span></a>
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-rooms&action=calendar&id={$r->id}" ), 'mhbo_calendar_room_' . $r->id ) ); ?>"
													class="button button-small" title="<?php esc_attr_e( 'Calendar Pricing', 'modern-hotel-booking' ); ?>" style="background:#ede9fe;color:#6d28d9;border-color:#c4b5fd;"><span class="dashicons dashicons-calendar-alt" style="margin-top:3px;"></span></a>
												<?php /* BUILD_PRO_END */ ?>

												<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-rooms&action=delete&id={$r->id}" ), 'mhbo_delete_room_' . $r->id ) ); ?>"
													class="button button-small button-link-delete"
													onclick="return confirm('<?php esc_attr_e( 'Permanently remove this unit from inventory?', 'modern-hotel-booking' ); ?>')"><span class="dashicons dashicons-trash" style="margin-top:3px;"></span></a>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}



	public function display_extras_page(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		// Handle Form Submission
		if ( isset( $_POST['mhbo_save_extras'] ) ) {
			if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_insufficient_perms_short' ) ) );
			}
			if ( ! check_admin_referer( 'mhbo_save_extras_action' ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			$new_extras = array();
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field within loop below.
			$raw_extras = isset( $_POST['extras'] ) && is_array( $_POST['extras'] ) ? wp_unslash( $_POST['extras'] ) : array();
			if ( ! empty( $raw_extras ) ) {
				$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
				foreach ( $raw_extras as $ex ) {
					if ( ! is_array( $ex ) ) {
						continue;
					}
					$name = sanitize_text_field( (string) ( $ex['name'] ?? '' ) );
					if ( '' === trim( $name ) ) {
						continue;
					}
					$price_raw = (string) ( $ex['price'] ?? '0' );
					try {
						$price_obj       = Money::fromDecimal( $price_raw, $currency );
						$price_formatted = $price_obj->isNegative() ? '0.00' : $price_obj->toDecimal();
					} catch ( \Throwable $e ) {
						$price_formatted = '0.00';
					}

					$valid_pricing_types = array(
						'fixed',
						'per_person',
						'per_adult',
						'per_child',
						'per_night',
						'per_person_per_night',
						'per_adult_per_night',
						'per_child_per_night',
					);
					$pricing_type = sanitize_key( (string) ( $ex['pricing_type'] ?? 'fixed' ) );
					if ( ! in_array( $pricing_type, $valid_pricing_types, true ) ) {
						$pricing_type = 'fixed';
					}

					$control_type = sanitize_key( (string) ( $ex['control_type'] ?? 'checkbox' ) );
					if ( ! in_array( $control_type, array( 'checkbox', 'quantity' ), true ) ) {
						$control_type = 'checkbox';
					}

					$new_extras[] = array(
						'id'           => ( ! empty( $ex['id'] ) && '' !== trim( (string) $ex['id'] ) ) ? sanitize_text_field( (string) $ex['id'] ) : uniqid( 'extra_' ),
						'name'         => $name,
						'price'        => $price_formatted,
						'pricing_type' => $pricing_type,
						'control_type' => $control_type,
						'icon'         => isset( $ex['icon'] ) ? sanitize_key( (string) $ex['icon'] ) : 'dashicons-star-filled',
						'description'  => isset( $ex['description'] ) ? sanitize_textarea_field( (string) $ex['description'] ) : '',
						/* BUILD_PRO_START */
						'compulsory'   => ! empty( $ex['compulsory'] ) ? 1 : 0,
						'allow_coupon' => ! empty( $ex['allow_coupon'] ) ? 1 : 0,
						/* BUILD_PRO_END */
					);
				}
			}
			update_option( 'mhbo_pro_extras', $new_extras );
			Cache::invalidate_pricing();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_extras_saved' ) ) . '</p></div>';
		}

		$extras = get_option( 'mhbo_pro_extras', array() );
		?>
		<div class="wrap mhbo-admin-wrap mhbo-extras-wrap">
			<?php
			AdminUI::render_header(
				__( 'Service Extras & Add-ons', 'modern-hotel-booking' ),
				__( 'Configure bookable guest amenities, packages, airport transfers, and optional services.', 'modern-hotel-booking' ),
				array(),
				array(
					array(
						'label' => I18n::get_label( 'menu_main' ),
						'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
					),
				)
			);
			?>
			
			<div class="mhbo-stats-grid" style="margin-bottom: 24px;">
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value" id="mhbo-extras-count"><?php echo esc_html( (string) count( $extras ) ); ?></div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Active Add-on Services', 'modern-hotel-booking' ); ?></div>
				</div>
			</div>

			<div class="mhbo-card" style="margin-top: 30px;">
				<h3 style="margin-top:0; margin-bottom: 25px; display: flex; align-items: center;">
					<span class="dashicons dashicons-money-alt" style="margin-right: 10px; color: #3b82f6;"></span>
					<?php esc_html_e( 'Configure Available Services', 'modern-hotel-booking' ); ?>
				</h3>

				<form method="post" id="mhbo-extras-form">
					<?php wp_nonce_field( 'mhbo_save_extras_action' ); ?>
					<div id="mhbo-extras-list">
						<?php
						if ( count( $extras ) > 0 ) {
							foreach ( $extras as $index => $extra ) {
								$this->render_extra_item( $index, $extra );
							}
						} else {
							echo '<div id="mhbo-extras-empty" style="text-align:center; padding:60px 20px; color:#94a3b8; border:2px dashed #e2e8f0; border-radius:12px; margin-bottom:25px;">
                                <span class="dashicons dashicons-plus-alt" style="font-size: 40px; width: 40px; height: 40px; margin-bottom: 15px; opacity: 0.5;"></span>
                                <p style="font-size: 1.1rem; margin: 0;">' . esc_html( I18n::get_label( 'msg_no_extras_desc' ) ) . '</p>
                                <p style="font-size: 0.9rem; margin-top: 5px; opacity: 0.7;">' . esc_html( I18n::get_label( 'msg_extras_examples' ) ) . '</p>
                            </div>';
						}
						?>
					</div>
					
					<div class="mhbo-extras-toolbar" style="margin-top:30px; padding-top:25px; border-top:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
						<button type="button" class="button button-secondary button-hero mhbo-add-extra-btn" id="mhbo-add-extra">
							<span class="dashicons dashicons-plus-alt" aria-hidden="true"></span>
							<span><?php echo esc_html( I18n::get_label( 'label_add_extra' ) ); ?></span>
						</button>
						<button type="submit" name="mhbo_save_extras" class="button button-primary button-hero mhbo-save-all-extras-btn" style="display:inline-flex; align-items:center; justify-content:center; gap:8px;">
							<span class="dashicons dashicons-saved" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Save All Add-ons', 'modern-hotel-booking' ); ?></span>
						</button>
					</div>
				</form>
			</div>

			<?php 
			$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
			?>
			<script type="text/template" id="tmpl-mhbo-extra">
				<div class="mhbo-extra-item mhbo-card accent" data-extra-id="">
					<?php $this->render_extra_fields( '{{data.index}}', array() ); ?>
					<div class="mhbo-extra-actions">
						<button type="button" class="button button-primary mhbo-save-extra"><?php echo esc_html( I18n::get_label( 'btn_save' ) ); ?></button>
						<button type="button" class="button mhbo-remove-extra" style="color:#b32d2e;border-color:#b32d2e;"><?php echo esc_html( I18n::get_label( 'btn_delete' ) ); ?></button>
					</div>
				</div>
			</script>
		</div>
		<?php
	}

	/**
	 * Render a single extra item card.
	 *
	 * @param string|int           $index The item index.
	 * @param array<string, mixed> $extra The extra item data.
	 */
	private function render_extra_item( string|int $index, array $extra ): void {
		?>
		<div class="mhbo-extra-item mhbo-card accent" data-extra-id="<?php echo esc_attr( (string) ( $extra['id'] ?? '' ) ); ?>">
			<?php $this->render_extra_fields( (string) $index, $extra ); ?>
			<div class="mhbo-extra-actions">
				<button type="button" class="button button-primary mhbo-save-extra"><?php esc_html_e( 'Save', 'modern-hotel-booking' ); ?></button>
				<button type="button" class="button mhbo-remove-extra" style="color:#b32d2e;border-color:#b32d2e;"><?php esc_html_e( 'Delete', 'modern-hotel-booking' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the fields for an extra item.
	 *
	 * @param string|int           $index The item index.
	 * @param array<string, mixed> $extra The extra item data.
	 */
	private function render_extra_fields( string|int $index, array $extra ): void {
		$index    = (string) $index;
		$id       = (string) ( $extra['id'] ?? '' );
		$name     = (string) ( $extra['name'] ?? '' );
		$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$price    = Money::fromDecimal( (string) ( $extra['price'] ?? '0' ), $currency )->toDecimal();

		$desc          = (string) ( $extra['description'] ?? '' );
		$pt            = $extra['pricing_type'] ?? 'fixed';
		$ct            = $extra['control_type'] ?? 'checkbox';
		$selected_icon = $extra['icon'] ?? 'dashicons-star-filled';
		
		$is_pro = class_exists( '\MHBO\Core\Tier' ) ? \MHBO\Core\Tier::is_effective_at_least( \MHBO\Core\Tier::TIER_PRO ) : ( defined( 'MHBO_IS_PRO' ) && MHBO_IS_PRO );
		?>
		<input type="hidden" name="extras[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $id ); ?>">
		<div class="mhbo-extra-grid">
			<div>
				<table class="form-table" style="margin:0;">
					<tr>
						<th style="width:160px;"><label><?php echo esc_html( I18n::get_label( 'label_service_title' ) ); ?></label></th>
						<td>
							<div style="display:flex; gap:10px; align-items:center;">
								<input type="text" name="extras[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" class="widefat" placeholder="<?php echo esc_attr( I18n::get_label( 'label_service_title_placeholder' ) ); ?>" required>
								<?php if ( $is_pro ) : ?>
									<?php /* BUILD_PRO_START */ ?>
									<div class="mhbo-icon-picker">
										<select name="extras[<?php echo esc_attr( $index ); ?>][icon]" class="mhbo-dashicon-select">
											<option value="dashicons-star-filled" <?php selected( $selected_icon, 'dashicons-star-filled' ); ?>>⭐</option>
											<option value="dashicons-food" <?php selected( $selected_icon, 'dashicons-food' ); ?>>🍴</option>
											<option value="dashicons-car" <?php selected( $selected_icon, 'dashicons-car' ); ?>>🚗</option>
											<option value="dashicons-palmtree" <?php selected( $selected_icon, 'dashicons-palmtree' ); ?>>🌴</option>
											<option value="dashicons-coffee" <?php selected( $selected_icon, 'dashicons-coffee' ); ?>>☕</option>
											<option value="dashicons-tickets-alt" <?php selected( $selected_icon, 'dashicons-tickets-alt' ); ?>>🎟️</option>
											<option value="dashicons-pets" <?php selected( $selected_icon, 'dashicons-pets' ); ?>>🐾</option>
											<option value="dashicons-heart" <?php selected( $selected_icon, 'dashicons-heart' ); ?>>❤️</option>
											<option value="dashicons-admin-plugins" <?php selected( $selected_icon, 'dashicons-admin-plugins' ); ?>>⚙️</option>
										</select>
									</div>
									<?php /* BUILD_PRO_END */ ?>
								<?php endif; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th><label><?php echo esc_html( I18n::get_label( 'label_base_price' ) ); ?></label></th>
						<td>
							<div style="display: flex; align-items: center; gap: 10px;">
								<input type="number" step="any" name="extras[<?php echo esc_attr( $index ); ?>][price]" value="<?php echo esc_attr( $price ); ?>" class="small-text" required> 
								<span class="description" style="font-weight: 600;"><?php echo esc_html( $currency ); ?></span>
							</div>
						</td>
					</tr>
					<tr>
						<th><label><?php echo esc_html( I18n::get_label( 'label_pricing_model' ) ); ?></label></th>
						<td>
							<select name="extras[<?php echo esc_attr( $index ); ?>][pricing_type]" class="widefat">
								<option value="fixed" <?php selected( $pt, 'fixed' ); ?>><?php echo esc_html( I18n::get_label( 'opt_fixed_fee' ) ); ?></option>
								<option value="per_person" <?php selected( $pt, 'per_person' ); ?>><?php echo esc_html( I18n::get_label( 'opt_per_person' ) ); ?></option>
								
								<?php if ( $is_pro ) : ?>
									<?php /* BUILD_PRO_START */ ?>
									<option value="per_adult" <?php selected( $pt, 'per_adult' ); ?>><?php echo esc_html( I18n::get_label( 'opt_per_adult' ) ); ?></option>
									<option value="per_child" <?php selected( $pt, 'per_child' ); ?>><?php echo esc_html( I18n::get_label( 'opt_per_child' ) ); ?></option>
									<?php /* BUILD_PRO_END */ ?>
								<?php endif; ?>

								<option value="per_night" <?php selected( $pt, 'per_night' ); ?>><?php echo esc_html( I18n::get_label( 'opt_per_night' ) ); ?></option>
								<option value="per_person_per_night" <?php selected( $pt, 'per_person_per_night' ); ?>><?php echo esc_html( I18n::get_label( 'opt_person_night' ) ); ?></option>
								
								<?php if ( $is_pro ) : ?>
									<?php /* BUILD_PRO_START */ ?>
									<option value="per_adult_per_night" <?php selected( $pt, 'per_adult_per_night' ); ?>><?php echo esc_html( I18n::get_label( 'opt_adult_night' ) ); ?></option>
									<option value="per_child_per_night" <?php selected( $pt, 'per_child_per_night' ); ?>><?php echo esc_html( I18n::get_label( 'opt_child_night' ) ); ?></option>
									<?php /* BUILD_PRO_END */ ?>
								<?php endif; ?>
							</select>
						</td>
					</tr>
				</table>
			</div>
			<div>
				<table class="form-table" style="margin:0;">
					<tr class="mhbo-control-type-row">
						<th style="width:160px;"><label><?php echo esc_html( I18n::get_label( 'label_booking_input' ) ); ?></label></th>
						<td>
							<select name="extras[<?php echo esc_attr( $index ); ?>][control_type]" class="widefat">
								<option value="checkbox" <?php selected( $ct, 'checkbox' ); ?>><?php echo esc_html( I18n::get_label( 'opt_checkbox' ) ); ?></option>
								<option value="quantity" <?php selected( $ct, 'quantity' ); ?>><?php echo esc_html( I18n::get_label( 'opt_quantity' ) ); ?></option>
							</select>
						</td>
					</tr>
					<?php /* BUILD_PRO_START */ ?>
					<?php $is_compulsory = isset( $extra['compulsory'] ) && $extra['compulsory']; ?>
					<tr>
						<th style="width:160px;"><label><?php esc_html_e( 'Compulsory Fee', 'modern-hotel-booking' ); ?></label></th>
						<td>
							<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
								<input type="checkbox"
									name="extras[<?php echo esc_attr( $index ); ?>][compulsory]"
									value="1"
									class="mhbo-compulsory-toggle"
									<?php checked( $is_compulsory, true ); ?>>
								<span><?php echo esc_html( I18n::get_label( 'label_compulsory_extra' ) ); ?></span>
							</label>
							<p class="description" style="margin-top:5px;"><?php echo esc_html( I18n::get_label( 'label_service_fee_desc' ) ); ?></p>
						</td>
					</tr>
					<?php $is_allow_coupon = isset( $extra['allow_coupon'] ) && $extra['allow_coupon']; ?>
					<tr>
						<th style="width:160px;"><label><?php esc_html_e( 'Coupon Discount', 'modern-hotel-booking' ); ?></label></th>
						<td>
							<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
								<input type="checkbox"
									name="extras[<?php echo esc_attr( (string) $index ); ?>][allow_coupon]"
									value="1"
									<?php checked( $is_allow_coupon, true ); ?>>
								<span><?php esc_html_e( 'Allow promotional coupon discounts on this add-on', 'modern-hotel-booking' ); ?></span>
							</label>
							<p class="description" style="margin-top:5px;"><?php esc_html_e( 'When checked, percentage or fixed coupons will apply discount to this service.', 'modern-hotel-booking' ); ?></p>
						</td>
					</tr>
					<?php /* BUILD_PRO_END */ ?>
					<tr>
						<th><label><?php echo esc_html( I18n::get_label( 'label_public_description' ) ); ?></label></th>
						<td><textarea name="extras[<?php echo esc_attr( $index ); ?>][description]" rows="3" class="widefat" placeholder="<?php echo esc_attr( I18n::get_label( 'label_desc_placeholder' ) ); ?>" style="font-size: 13px;"><?php echo esc_textarea( $desc ); ?></textarea></td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}
	/**
	 * Fix the missing page title in admin-header.php for hidden submenus.
	 * Prevents "strip_tags(null)" warning in 2026 strict environments.
	 */
	public function fix_hidden_page_titles( string $admin_title, string $title ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( str_starts_with( $page, 'mhbo-pro-' ) || 'mhbo-tablet-mode' === $page ) {
			$slug   = str_replace( array( 'mhbo-pro-', 'mhbo-' ), '', $page );
			$titles = array(
				'tablet-mode'  => __( 'Tablet Mode', 'modern-hotel-booking' ),
				'housekeeping' => __( 'Housekeeping', 'modern-hotel-booking' ),
				'marketing'    => __( 'Marketing & Templates', 'modern-hotel-booking' ),
				'reviews'      => __( 'Review Engine', 'modern-hotel-booking' ),
				'extras'       => I18n::get_label( 'menu_extras' ),
				'ical'         => I18n::get_label( 'menu_ical' ),
				'payments'     => I18n::get_label( 'menu_payments' ),
				'webhooks'     => I18n::get_label( 'menu_webhooks' ),
				'analytics'    => I18n::get_label( 'menu_analytics' ),
				'themes'       => I18n::get_label( 'menu_appearance' ),
				'pricing'      => I18n::get_label( 'menu_advanced_pricing' ),
				'tax'          => I18n::get_label( 'tab_tax' ),
				'coupons'      => __( 'Coupons', 'modern-hotel-booking' ),
				'ai'           => __( 'AI Concierge', 'modern-hotel-booking' ),
				'licensing'    => I18n::get_label( 'menu_licensing' ),
			);

			if ( isset( $titles[ $slug ] ) ) {
				$new_title = $titles[ $slug ];
				return sprintf( '%s &lsaquo; %s &#8212; WordPress', $new_title, get_bloginfo( 'name' ) );
			}
		}
		return $admin_title;
	}
}

