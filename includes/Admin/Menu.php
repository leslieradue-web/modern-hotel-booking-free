<?php declare(strict_types=1);

namespace MHBO\Admin;

use MHBO\Admin\AdminUI;
use MHBO\Admin\Settings;
use MHBO\Core\Capabilities;
use MHBO\Core\HotelTime;
use MHBO\Core\I18n;
use MHBO\Core\License;
use MHBO\Core\Security;
use MHBO\Core\GuestManager;

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
	private const FULLCALENDAR_VERSION = '7.1.5';

	/**
	 * Public FullCalendar handles. Consumers declare these as dependencies; WordPress
	 * resolves the full vendor chain (core -> classic theme, skeleton -> theme -> palette).
	 * Prefixed with `mhbo-` so a third-party plugin registering a generic `fullcalendar`
	 * handle (e.g. a v6 build) can never shadow our V7 bundle.
	 */
	private const FULLCALENDAR_SCRIPT_HANDLE = 'mhbo-fullcalendar-classic-theme';
	private const FULLCALENDAR_STYLE_HANDLE  = 'mhbo-fullcalendar-classic-palette';

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widgets' ) );

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

/**
	 * Handle printable manifest early on admin_init before headers/HTML are sent.
	 *
	 * @since 2.6.0
	 */

public function add_dashboard_widgets(): void {
		if (
			Capabilities::current_user_can( Capabilities::VIEW_BOOKINGS )
			|| Capabilities::current_user_can( Capabilities::MANAGE_LHBO )
			|| Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS )
		) {
			wp_add_dashboard_widget( 'mhbo_dashboard_overview', I18n::get_label( 'dash_title' ), array( $this, 'render_dashboard_widget' ) );
		}
	}

	/**
	 * Lazy-initialized dashboard controller.
	 *
	 * @var DashboardController|null
	 */
	private ?DashboardController $dashboard_controller = null;

	/**
	 * Lazy-initialized bookings controller.
	 *
	 * @var BookingsController|null
	 */
	private ?BookingsController $bookings_controller = null;

	/**
	 * Lazy-initialized inventory controller.
	 *
	 * @var InventoryController|null
	 */
	private ?InventoryController $inventory_controller = null;

/**
	 * Get the dashboard controller instance.
	 *
	 * @return DashboardController
	 */
	private function get_dashboard_controller(): DashboardController {
		if ( null === $this->dashboard_controller ) {
			$this->dashboard_controller = new DashboardController();
		}
		return $this->dashboard_controller;
	}

	/**
	 * Get the bookings controller instance.
	 *
	 * @return BookingsController
	 */
	private function get_bookings_controller(): BookingsController {
		if ( null === $this->bookings_controller ) {
			$this->bookings_controller = new BookingsController();
		}
		return $this->bookings_controller;
	}

	/**
	 * Get the inventory controller instance.
	 *
	 * @return InventoryController
	 */
	private function get_inventory_controller(): InventoryController {
		if ( null === $this->inventory_controller ) {
			$this->inventory_controller = new InventoryController();
		}
		return $this->inventory_controller;
	}

	/**
	 * Render the WordPress Dashboard overview widget.
	 *
	 * @return void
	 */
	public function render_dashboard_widget(): void {
		$this->get_dashboard_controller()->render_dashboard_widget();
	}

	/**
	 * Register the bundled FullCalendar V7 (Vanilla JS global build + classic theme).
	 *
	 * V7 ships CSS separately (skeleton + classic theme + palette). Assets are only
	 * registered here; they are enqueued on demand when a consumer lists
	 * FULLCALENDAR_SCRIPT_HANDLE / FULLCALENDAR_STYLE_HANDLE as a dependency.
	 */
	private function register_fullcalendar_assets(): void {
		$css = MHBO_PLUGIN_URL . 'assets/css/vendor/';
		$js  = MHBO_PLUGIN_URL . 'assets/js/vendor/';

		wp_register_style( 'mhbo-fullcalendar-skeleton', $css . 'fullcalendar-skeleton.min.css', array(), self::FULLCALENDAR_VERSION );
		wp_register_style( 'mhbo-fullcalendar-classic-theme-css', $css . 'fullcalendar-classic-theme.min.css', array( 'mhbo-fullcalendar-skeleton' ), self::FULLCALENDAR_VERSION );
		wp_register_style( self::FULLCALENDAR_STYLE_HANDLE, $css . 'fullcalendar-classic-palette.min.css', array( 'mhbo-fullcalendar-classic-theme-css' ), self::FULLCALENDAR_VERSION );

		wp_register_script( 'mhbo-fullcalendar', $js . 'fullcalendar.global.min.js', array(), self::FULLCALENDAR_VERSION, true );
		wp_register_script( self::FULLCALENDAR_SCRIPT_HANDLE, $js . 'fullcalendar-classic-theme.global.min.js', array( 'mhbo-fullcalendar' ), self::FULLCALENDAR_VERSION, true );
	}

	public function enqueue_admin_assets( mixed $hook = '' ): void {
		$hook = is_string( $hook ) ? $hook : '';
		if ( 'index.php' === $hook || 'dashboard' === $hook ) {
			// Dedicated lightweight dashboard widgets stylesheet (4 KB); avoids loading 229 KB admin CSS suite on index.php.
			wp_enqueue_style(
				'mhbo-dashboard-css',
				\MHBO\Core\Plugin::asset_url( 'assets/css/mhbo-dashboard.css' ),
				array( 'dashicons' ),
				\MHBO\Core\Plugin::asset_ver( 'assets/css/mhbo-dashboard.css' )
			);
			return;
		}

		if ( false === strpos( $hook, 'mhbo-hotel-booking' ) && false === strpos( $hook, 'mhbo-' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen tab routing check
		$current_tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$admin_css_ver = \MHBO\Core\Plugin::asset_ver( 'assets/css/mhbo-admin.css' );
		wp_enqueue_style( 'mhbo-admin-css', \MHBO\Core\Plugin::asset_url( 'assets/css/mhbo-admin.css' ), array(), $admin_css_ver );
		wp_enqueue_script(
			'mhbo-admin-js',
			\MHBO\Core\Plugin::asset_url( 'assets/js/mhbo-admin.js' ),
			array( 'wp-api-fetch' ),
			\MHBO\Core\Plugin::asset_ver( 'assets/js/mhbo-admin.js' ),
			true
		);

// Enqueue React DataViews bundle strictly on pages that mount #mhbo-dataviews-root (Guest CRM).
		if ( false !== strpos( $hook, 'mhbo-guests' ) ) {
			$asset_file_path = MHBO_PLUGIN_DIR . 'build/admin/dataviews.asset.php';
			if ( file_exists( $asset_file_path ) ) {
				// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
				/** @var array{dependencies: list<non-empty-string>, version: string} $asset_file */
				$asset_file = (array) include $asset_file_path;
				wp_enqueue_script(
					'mhbo-dataviews',
					MHBO_PLUGIN_URL . 'build/admin/dataviews.js',
					$asset_file['dependencies'],
					$asset_file['version'],
					true
				);
				$is_pro_active = class_exists( License::class ) ? MHBO_IS_PRO : ( defined( 'MHBO_IS_PRO' ) && MHBO_IS_PRO );
				wp_localize_script(
					'mhbo-dataviews',
					'mhboDataViewsData',
					array(
						'isPro'              => MHBO_IS_PRO,
						'buildUrl'           => MHBO_PLUGIN_URL . 'build/',
						'exportUrl'          => esc_url_raw( rest_url( 'mhbo/v1/admin/guests/export' ) ),
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
					wp_style_add_data( 'mhbo-dataviews-css', 'rtl', 'replace' );
				}

				wp_add_inline_script( 'wp-api-fetch', sprintf( 'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( "%s" ) );', wp_create_nonce( 'wp_rest' ) ), 'after' );
			}
		}

		if ( false !== strpos( $hook, 'mhbo-bookings' ) || false !== strpos( $hook, 'mhbo-calendar' ) ) {
			// Flatpickr date range picker assets for add/edit workstations
			wp_enqueue_style( 'mhbo-flatpickr-css', MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css', array(), '4.6.13' );
			wp_enqueue_script( 'mhbo-flatpickr-js', MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js', array(), '4.6.13', true );
			wp_enqueue_style(
				'mhbo-calendar-style',
				\MHBO\Core\Plugin::asset_url( 'assets/css/mhbo-calendar.css' ),
				array( 'mhbo-flatpickr-css' ),
				\MHBO\Core\Plugin::asset_ver( 'assets/css/mhbo-calendar.css' )
			);

			// FullCalendar V7 vendor chain is pulled in via the dependency handles below.
			$this->register_fullcalendar_assets();

			// Deregister mhbo-admin-css and re-enqueue AFTER vendor styles to ensure proper override
			$bookings_css_ver = \MHBO\Core\Plugin::asset_ver( 'assets/css/mhbo-admin.css' );
			$bookings_js_ver  = \MHBO\Core\Plugin::asset_ver( 'assets/js/mhbo-admin-bookings.js' );
			wp_deregister_style( 'mhbo-admin-css' );
			wp_enqueue_style( 'mhbo-admin-css', \MHBO\Core\Plugin::asset_url( 'assets/css/mhbo-admin.css' ), array( self::FULLCALENDAR_STYLE_HANDLE, 'mhbo-flatpickr-css' ), $bookings_css_ver );

			// Enqueue admin bookings script (Pure Vanilla JS)
			wp_enqueue_script(
				'mhbo-admin-bookings',
				\MHBO\Core\Plugin::asset_url( 'assets/js/mhbo-admin-bookings.js' ),
				array( 'mhbo-admin-js', 'wp-api-fetch', 'mhbo-flatpickr-js', self::FULLCALENDAR_SCRIPT_HANDLE ),
				$bookings_js_ver,
				true
			);

			// Inject configuration
			$config = array(
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'currencySymbol'  => (string) get_option( 'mhbo_currency_symbol', '$' ),
				'currencyCode'    => strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) ),
				'extrasCount'     => 0,
				'preventTurnover' => ( class_exists( '\MHBO\Core\Tier' ) && \MHBO\Core\Tier::has_feature( 'prevent_same_day_turnover' ) ) && (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1,
				'minNights'       => (int) get_option( 'mhbo_global_min_stay', 0 ),
				'maxNights'       => (int) get_option( 'mhbo_global_max_stay', 0 ),
				'gapAutocorrect'  => ( class_exists( '\MHBO\Core\Tier' ) && \MHBO\Core\Tier::has_feature( 'orphan_gap_optimization' ) ) && (int) get_option( 'mhbo_orphan_gap_autocorrection', 0 ) === 1,
				'gapMinNights'    => (int) get_option( 'mhbo_orphan_gap_min_stay', 1 ),
				'dateFormat'      => (string) get_option( 'date_format', 'Y-m-d' ),
				'depositsEnabled' => ( class_exists( '\MHBO\Core\Tier' ) && \MHBO\Core\Tier::has_feature( 'deposits' ) ) && (int) get_option( 'mhbo_deposits_enabled', 0 ) === 1,
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
				\MHBO\Core\Plugin::asset_url( 'assets/js/mhbo-admin-media-upload.js' ),
				array(),
				\MHBO\Core\Plugin::asset_ver( 'assets/js/mhbo-admin-media-upload.js' ),
				true
			);
		}

}

	public function add_plugin_admin_menu(): void {
		$manage_cap       = Capabilities::MANAGE_LHBO;
		$view_cap         = Capabilities::VIEW_BOOKINGS;
		$set_cap          = Capabilities::MANAGE_SETTINGS;
		$rooms_cap        = Capabilities::MANAGE_ROOMS;
		$pricing_cap      = Capabilities::MANAGE_PRICING;
		$housekeeping_cap = Capabilities::MANAGE_HOUSEKEEPING;
		$analytics_cap    = Capabilities::VIEW_ANALYTICS;

		add_menu_page( I18n::get_label( 'menu_main' ), I18n::get_label( 'menu_main' ), $view_cap, 'mhbo-hotel-booking', array( $this, 'display_dashboard_page' ), 'dashicons-building', 26 );
		add_submenu_page( 'mhbo-hotel-booking', I18n::get_label( 'menu_bookings' ), I18n::get_label( 'menu_bookings' ), $view_cap, 'mhbo-bookings', array( $this, 'display_bookings_page' ) );
		if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) {
			add_submenu_page( 'mhbo-hotel-booking', __( 'Guests', 'modern-hotel-booking' ), __( 'Guests', 'modern-hotel-booking' ), Capabilities::MANAGE_GUESTS, 'mhbo-guests', array( $this, 'display_dataviews_page' ) );
			
		}
		add_submenu_page( 'mhbo-hotel-booking', I18n::get_label( 'menu_room_types' ), I18n::get_label( 'menu_room_types' ), $rooms_cap, 'mhbo-room-types', array( $this, 'display_room_types_page' ) );
		add_submenu_page( 'mhbo-hotel-booking', I18n::get_label( 'menu_rooms' ), I18n::get_label( 'menu_rooms' ), $rooms_cap, 'mhbo-rooms', array( $this, 'display_rooms_page' ) );
		add_submenu_page( 'mhbo-hotel-booking', I18n::get_label( 'menu_advanced_pricing' ), I18n::get_label( 'menu_pricing' ), $pricing_cap, 'mhbo-pricing-rules', array( 'MHBO\\Admin\\PricingController', 'render' ) );
		add_submenu_page( 'mhbo-hotel-booking', I18n::get_label( 'menu_settings' ), I18n::get_label( 'menu_settings' ), $set_cap, 'mhbo-settings', array( 'MHBO\\Admin\\Settings', 'render' ) );

}

/**
	 * Render the Executive Command Center dashboard page.
	 *
	 * @return void
	 */
	public function display_dashboard_page(): void {
		$this->get_dashboard_controller()->display_dashboard_page();
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

	/**
	 * Render the Bookings management workstation page.
	 *
	 * @return void
	 */
	public function display_bookings_page(): void {
		$this->get_bookings_controller()->display_bookings_page();
	}

/**
	 * Render the Room Types management page.
	 *
	 * @return void
	 */
	public function display_room_types_page(): void {
		$this->get_inventory_controller()->display_room_types_page();
	}

	/**
	 * Render the Rooms management page.
	 *
	 * @return void
	 */
	public function display_rooms_page(): void {
		$this->get_inventory_controller()->display_rooms_page();
	}

	/**
	 * Render the Extra Services management page.
	 *
	 * @return void
	 */
	public function display_extras_page(): void {
		$this->get_inventory_controller()->display_extras_page();
	}

	/**
	 * Fix the missing page title in admin-header.php for hidden submenus.
	 * Prevents "strip_tags(null)" and strict TypeError crashes when core passes null $title.
	 *
	 * @param mixed $admin_title Full page title with context.
	 * @param mixed $title       Base page title (may be null on hidden submenus).
	 * @return string
	 */
	public function fix_hidden_page_titles( mixed $admin_title, mixed $title = '' ): string {
		unset( $title );
		$admin_title_str = is_string( $admin_title ) ? $admin_title : '';
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
		return $admin_title_str;
	}
}
