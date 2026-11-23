<?php declare(strict_types=1);

namespace MHBO\Admin;

use MHBO\Core\Cache;
use MHBO\Core\Capabilities;
use MHBO\Core\Email;
use MHBO\Core\GuestManager;
use MHBO\Core\HotelTime;
use MHBO\Core\I18n;

use MHBO\Core\Pricing;
use MHBO\Database\Queries\Booking_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard Controller
 *
 * Renders the WordPress Dashboard overview widget and the main Executive
 * Command Center dashboard page.
 */
class DashboardController {

	public function render_dashboard_widget(): void {
		// Explicit capability check for defense-in-depth
		if (
			! Capabilities::current_user_can( Capabilities::VIEW_BOOKINGS )
			&& ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO )
			&& ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS )
		) {
			return;
		}

		global $wpdb;
		$today_date = HotelTime::today();

		$cache_key = 'mhbo_widget_ops_v270_' . $today_date;
		$stats     = get_transient( $cache_key );

		if ( false === $stats || ! is_array( $stats ) ) {
			// Total physical rooms in inventory
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total_rooms = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_rooms" );

			$ops_metrics    = Booking_Query::get_operational_metrics( $today_date );
			$occupied_rooms = $ops_metrics['occupied_rooms'];
			$arrivals       = $ops_metrics['arrivals'];
			$departures     = $ops_metrics['departures'];
			$in_house       = $ops_metrics['in_house'];
			$total_bookings = $ops_metrics['total_bookings'];
			$pending_count  = $ops_metrics['pending_count'];

			// Guest CRM counts (exclude anonymized and synthetic pending/local placeholder profiles)
			$guests_table_exists = Cache::table_exists( $wpdb->prefix . 'mhbo_guests' );
			$total_guests        = 0;
			$vip_guests          = 0;
			if ( $guests_table_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$crm_stats = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) as total, COALESCE(SUM(CASE WHEN vip_status = 'vip' THEN 1 ELSE 0 END), 0) as vips
						 FROM %i
						 WHERE status != 'anonymized'
						   AND LOWER(COALESCE(email, '')) NOT LIKE %s
						   AND LOWER(COALESCE(email, '')) NOT LIKE %s",
						$wpdb->prefix . 'mhbo_guests',
						'%@pending.local',
						'%@local.invalid'
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
				
				?>

				<?php if ( Capabilities::current_user_can( Capabilities::MANAGE_GUESTS ) && class_exists( GuestManager::class ) && GuestManager::is_enabled() ) : ?>
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
				<?php if ( Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings&action=add' ) ); ?>" class="mhbo-widget-btn mhbo-widget-btn-primary">
					<span class="dashicons dashicons-plus-alt2" style="font-size: 14px; width: 14px; height: 14px;"></span>
					<?php echo esc_html( I18n::get_label( 'label_add_booking' ) ); ?>
				</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ); ?>" class="mhbo-widget-btn">
					<span class="dashicons dashicons-calendar-alt" style="font-size: 14px; width: 14px; height: 14px;"></span>
					<?php echo esc_html( I18n::get_label( 'menu_bookings' ) ); ?>
				</a>
				
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

	public function display_dashboard_page(): void {
		if (
			! Capabilities::current_user_can( Capabilities::VIEW_BOOKINGS )
			&& ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO )
			&& ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS )
		) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		global $wpdb;
		$today_date = HotelTime::today();

		$is_pro_active = false;

// Kairos Protocol: Cached Dashboard Operations & Analytics
		$cache_key = 'mhbo_dashboard_ops_v270_' . $today_date;
		$stats     = get_transient( $cache_key );

		if ( false === $stats || ! is_array( $stats ) ) {
			// Physical room counts
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total_rooms = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_rooms" );

			$ops_metrics      = Booking_Query::get_operational_metrics( $today_date );
			$occupied_rooms   = $ops_metrics['occupied_rooms'];
			$total_bookings   = $ops_metrics['total_bookings'];
			$pending_count    = $ops_metrics['pending_count'];
			$arrivals_count   = $ops_metrics['arrivals'];
			$departures_count = $ops_metrics['departures'];
			$in_house_count   = $ops_metrics['in_house'];

			$revenue_raw = array(
				array(
					'confirmed_revenue' => $ops_metrics['confirmed_revenue'],
					'confirmed_count'   => $ops_metrics['confirmed_count'],
					'total_pipeline'    => $ops_metrics['total_pipeline'],
					'total_collected'   => $ops_metrics['total_collected'],
				),
			);

			$arrivals_list   = Booking_Query::get_dashboard_bookings( 'arrivals', $today_date, 15 );
			$departures_list = Booking_Query::get_dashboard_bookings( 'departures', $today_date, 15 );
			$inhouse_list    = Booking_Query::get_dashboard_bookings( 'inhouse', $today_date, 15 );
			$pending_list    = Booking_Query::get_dashboard_bookings( 'pending', $today_date, 15 );
			$recent_list     = Booking_Query::get_dashboard_bookings( 'recent', $today_date, 10 );

			// Guest CRM counts (exclude anonymized and synthetic pending/local placeholder profiles)
			$guests_table_exists = Cache::table_exists( $wpdb->prefix . 'mhbo_guests' );
			$total_guests        = 0;
			$vip_guests          = 0;
			if ( $guests_table_exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$crm_stats = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) as total, COALESCE(SUM(CASE WHEN vip_status = 'vip' THEN 1 ELSE 0 END), 0) as vips
						 FROM %i
						 WHERE status != 'anonymized'
						   AND LOWER(COALESCE(email, '')) NOT LIKE %s
						   AND LOWER(COALESCE(email, '')) NOT LIKE %s",
						$wpdb->prefix . 'mhbo_guests',
						'%@pending.local',
						'%@local.invalid'
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

$stats = array(
				'total_rooms'        => $total_rooms,
				'occupied_rooms'     => $occupied_rooms,
				'available_rooms'    => max( 0, $total_rooms - $occupied_rooms ),
				'total_bookings'     => $total_bookings,
				'pending_count'      => $pending_count,
				'arrivals_count'     => $arrivals_count,
				'departures_count'   => $departures_count,
				'in_house_count'     => $in_house_count,
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
		$arrivals_count     = (int) ( $stats['arrivals_count'] ?? count( $arrivals_list ) );
		$departures_count   = (int) ( $stats['departures_count'] ?? count( $departures_list ) );
		$in_house_count     = (int) ( $stats['in_house_count'] ?? count( $inhouse_list ) );
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
					<div class="mhbo-hero-kpi-value"><?php echo esc_html( (string) $arrivals_count ); ?></div>
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
					<div class="mhbo-hero-kpi-value"><?php echo esc_html( (string) $departures_count ); ?></div>
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
					<div class="mhbo-hero-kpi-value"><?php echo esc_html( (string) $in_house_count ); ?></div>
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

				<?php if ( Capabilities::current_user_can( Capabilities::VIEW_ANALYTICS ) ) : ?>
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
				<?php endif; ?>
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
					<?php if ( Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) ) : ?>
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
					<?php endif; ?>

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

					<?php if ( Capabilities::current_user_can( Capabilities::MANAGE_ROOMS ) ) : ?>
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
					<?php endif; ?>

					<?php if ( Capabilities::current_user_can( Capabilities::MANAGE_PRICING ) ) : ?>
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
					<?php endif; ?>

					<!-- 5. Guest CRM (Core/Free when enabled & Pro) -->
					<?php if ( Capabilities::current_user_can( Capabilities::MANAGE_GUESTS ) && class_exists( GuestManager::class ) && GuestManager::is_enabled() ) : ?>
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
					<?php if ( current_user_can( 'manage_options' ) && class_exists( \MHBO\AI\Loader::class ) ) : ?>
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

<?php if ( Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) : ?>
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
					<?php endif; ?>
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

<div style="display: flex; justify-content: space-between;">
								<span class="mhbo-free-edition-row"><?php echo esc_html( I18n::get_label( 'status_edition' ) ); ?></span>
								<strong class="mhbo-free-edition-row" style="color: #2563eb;"><?php echo esc_html( I18n::get_label( 'status_free' ) ); ?></strong>
							</div>
							
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
							<?php if ( ! MHBO_IS_PRO && current_user_can( 'manage_options' ) ) : ?>
								<?php
								$cta_url   = \MHBO\Core\Tier::get_upgrade_url();
								$cta_label = '⚡ ' . I18n::get_label( 'pro_get_version' );
								$cta_class = 'button button-primary mhbo-btn-upgrade';
								
								?>
								<a href="<?php echo esc_url( $cta_url ); ?>"
									target="_blank" rel="noopener noreferrer" class="<?php echo esc_attr( $cta_class ); ?>"
									style="justify-content: center; font-size: 12.5px; padding: 6px 14px; border-radius: 6px;">
									<span><?php echo esc_html( $cta_label ); ?></span>
								</a>
							<?php endif; ?>

							<div style="display: flex; justify-content: space-between; align-items: center; padding-top: 4px;">
								<a href="<?php echo esc_url( 'https://github.com/leslieradue-web/modern-hotel-booking-free/issues' ); ?>"
									target="_blank" rel="noopener noreferrer"
									style="font-size: 12px; color: #64748b; text-decoration: none; font-weight: 600; display: flex; align-items: center; gap: 4px;">
									<span class="dashicons dashicons-editor-help" style="font-size: 14px; width: 14px; height: 14px;"></span>
									<?php echo esc_html( I18n::get_label( 'pro_report_issues' ) ); ?>
								</a>
								<a href="<?php echo esc_url( 'https://modernhotelwp.com/docs/' ); ?>"
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
}
