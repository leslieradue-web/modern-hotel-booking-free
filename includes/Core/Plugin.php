<?php declare(strict_types=1);

namespace MHBO\Core;

use MHBO\Core\ICal;
use MHBO\Core\License;
use MHBO\Core\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Admin\Menu;
use MHBO\Admin\Settings;
use MHBO\Frontend\Shortcode;
use MHBO\Frontend\Calendar;
use MHBO\Frontend\BookingWidget;
use MHBO\Frontend\Block;
use MHBO\Api\RestApi;
use MHBO\Api\ModalApi;
use MHBO\Core\Privacy;
use MHBO\Core\SeasonalRates;

class Plugin {

public function __construct() {
		// Translations are auto-loaded by WordPress for plugins hosted on WordPress.org since WP 4.6.
	}

	/**
	 * Wire up all hooks and load subsystems.
	 */
	public function run(): void {
		// Initialize I18n filters
		I18n::init();
		HotelTime::init();
		Email::init();
		Capabilities::register();

		// Initialize Seasonal & Dynamic Pricing Rules Engine (Free + Pro)
		$seasonal_rates = new SeasonalRates();
		$seasonal_rates->init();

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			Cli::register();
		}

		/* ---- iCal Export (public endpoint) ---- */
		
		add_action(
			'init',
			function () {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- External API endpoint without nonce
				$mhbo_action = isset( $_GET['mhbo_action'] ) ? sanitize_key( wp_unslash( $_GET['mhbo_action'] ) ) : '';
				$room_id     = isset( $_GET['room_id'] ) ? absint( wp_unslash( $_GET['room_id'] ) ) : 0;
				if (
					'ical_export' === $mhbo_action
					&& $room_id > 0
					&& ! MHBO_IS_PRO // Pro Manager handles this at template_redirect; fallback here for edge cases
				) {
					if ( ! MHBO_IS_PRO ) {
						wp_die( esc_html( I18n::get_label( 'label_pro_required_ical' ) ), '', array( 'response' => 403 ) );
					}
					$token       = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
					$valid_token = get_option( 'mhbo_ical_token' );
					if ( $token === '' || ! is_string( $valid_token ) || $valid_token === '' || ! hash_equals( (string) $valid_token, $token ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_token' ) ), '', array( 'response' => 403 ) );
					}
					if ( $room_id > 0 ) {
						ICal::generate_ics( $room_id, $token );
					}
				}
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
			}
		);

// Note: The specialized Pro scheduler handles intervals (5m, 15m, hourly, etc.)
		// in ICalManager::maybe_schedule_sync().

/* ---- REST API ---- */
		add_action(
			'rest_api_init',
			function () {
				$api = new RestApi();
				$api->register_routes();

				if ( (int) get_option( 'mhbo_modal_enabled', 1 ) === 1 ) {
					$modal_api = new ModalApi();
					$modal_api->register_routes();
				}

if ( class_exists( 'MHBO\Api\PaymentsController' ) ) {
					( new \MHBO\Api\PaymentsController() )->register_routes();
				}
				if ( class_exists( 'MHBO\Api\CouponsController' ) ) {
					( new \MHBO\Api\CouponsController() )->register_routes();
				}
				if ( class_exists( 'MHBO\Api\AdminSystemController' ) ) {
					( new \MHBO\Api\AdminSystemController() )->register_routes();
				}
				if ( class_exists( 'MHBO\Api\AdminWebhooksController' ) ) {
					( new \MHBO\Api\AdminWebhooksController() )->register_routes();
				}
				if ( class_exists( 'MHBO\Api\AdminIcalController' ) ) {
					( new \MHBO\Api\AdminIcalController() )->register_routes();
				}
				if ( class_exists( 'MHBO\Api\AdminCalendarController' ) ) {
					( new \MHBO\Api\AdminCalendarController() )->register_routes();
				}
				if ( class_exists( 'MHBO\Api\AdminGatewaysController' ) ) {
					( new \MHBO\Api\AdminGatewaysController() )->register_routes();
				}
				if ( GuestManager::is_enabled() && class_exists( 'MHBO\Api\AdminGuestsController' ) ) {
					( new \MHBO\Api\AdminGuestsController() )->register_routes();
				}

				if ( class_exists( 'MHBO\Api\MarketingController' ) ) {
					( new \MHBO\Api\MarketingController() )->register_routes();
				}

				if ( class_exists( 'MHBO\Api\BalancePaymentController' ) ) {
					( new \MHBO\Api\BalancePaymentController() )->register_routes();
				}

				if ( class_exists( 'MHBO\Api\GuestPortalController' ) ) {
					( new \MHBO\Api\GuestPortalController() )->register_routes();
				}

}
		);

		/* ---- Guest CRM Async Tasks ---- */
		if ( class_exists( 'MHBO\Core\GuestAsyncTasks' ) ) {
			\MHBO\Core\GuestAsyncTasks::init();
		}

/* ---- Privacy & Consent Integrations (Complianz / WP Consent API) ---- */
		if ( class_exists( 'MHBO\Integrations\Complianz' ) ) {
			\MHBO\Integrations\Complianz::init();
		}
		if ( class_exists( 'MHBO\Integrations\Consent' ) ) {
			\MHBO\Integrations\Consent::init();
		}

		/* ---- GDPR / Privacy & Security Headers ---- */
		$privacy = new Privacy();
		$privacy->init();

		add_filter(
			'wp_headers',
			function ( array $headers ): array {
				if ( ! isset( $headers['Referrer-Policy'] ) ) {
					$headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
				}
				return $headers;
			}
		);

		/* ---- Block Editor support ---- */
		$block = new Block();
		$block->init();

		/* ---- Elementor Integration is handled early in modern-hotel-booking.php ---- */

if ( is_admin() ) {
			$this->load_admin();
		}

		// Always initialize frontend modules, shortcodes, and public assets.
		$this->load_frontend();

		// Initialize frontend calendar hooks and shortcode.
		$calendar = new Calendar();
		$calendar->init();

		// Register Widget (Must be global to show in Admin > Widgets)
		add_action(
			'widgets_init',
			function () {
				register_widget( BookingWidget::class );
			}
		);

		/* ---- Business Info Module ---- */
		$this->load_business();

		add_filter(
			'plugin_action_links_' . MHBO_PLUGIN_BASENAME,
			function ( $links ) {
				$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=mhbo-settings' ) ) . '">' . esc_html( I18n::get_label( 'label_settings' ) ) . '</a>';
				array_unshift( $links, $settings_link );
				return $links;
			}
		);

		// SECURITY: Bind guest nonces to the session ID to prevent cross-session nonce reuse.
		// WordPress normally treats all logged-out users as UID 0, meaning nonces are shared.
		add_filter(
			'nonce_user_logged_out',
			function ( int $uid, string $action ): int {
				if ( str_starts_with( $action, 'mhbo_' ) ) {
					$session_id = isset( $_COOKIE['mhbo_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['mhbo_session'] ) ) : '';
					if ( $session_id !== '' ) {
						// Truncate SHA-256 to 32 bits — vastly better collision resistance than crc32()
						return (int) hexdec( substr( hash( 'sha256', $session_id ), 0, 8 ) );
					}
				}
				return $uid;
			},
			10,
			2
		);

		// Ensure IPv4 resolution for external APIs (Stripe, PayPal, AI) on local environments (e.g. Windows Local IPv6 route timeouts)
		if ( wp_get_environment_type() === 'local' || ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local' ) ) {
			add_action(
				'http_api_curl',
				function ( $handle, array $r, string $url ) {
					if ( str_contains( $url, 'stripe.com' ) || str_contains( $url, 'paypal.com' ) || str_contains( $url, 'braintreegateway.com' ) || str_contains( $url, 'googleapis.com' ) || str_contains( $url, 'openai.com' ) || str_contains( $url, 'startmysuccess.com' ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- 2026 BP: Low-level cURL handle configuration via WordPress core http_api_curl action hook for local IPv4 resolution fallback.
						curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
					}
				},
				10,
				3
			);
		}

		$this->schedule_cron();
	}

	private function load_admin(): void {
		$menu = new Menu();
		$menu->init();
	}

	private function load_frontend(): void {
		$shortcode = new Shortcode();
		$shortcode->init();
	}

/**
	 * Load Business Information module.
	 */
	private function load_business(): void {
		\MHBO\Business\Info::get_instance();
		\MHBO\Business\Shortcodes::get_instance();
	}

	/**
	 * Schedule maintenance cron jobs.
	 */
	private function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'mhbo_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'mhbo_daily_maintenance' );
		}
		if ( ! wp_next_scheduled( 'mhbo_hourly_maintenance' ) ) {
			wp_schedule_event( time(), 'hourly', 'mhbo_hourly_maintenance' );
		}
		add_action( 'mhbo_hourly_maintenance', array( $this, 'cleanup_stale_pending_payments' ) );
	}

	/**
	 * Cancel stale pending_payment bookings older than 1 hour to release inventory.
	 *
	 * Skips bookings with any payment evidence to avoid canceling fully-paid
	 * or partially-paid reservations whose webhook/status transition is delayed.
	 */
	public function cleanup_stale_pending_payments(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mhbo_bookings';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Scheduled batch cancellation of stale pending_payment reservations.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'cancelled' WHERE status = 'pending_payment' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR) AND COALESCE(payment_transaction_id, '') = '' AND COALESCE(deposit_received, 0) = 0 AND COALESCE(balance_collected_via, '') = '' AND payment_status != 'completed'",
				$table
			)
		);
		if ( $affected ) {
			Cache::invalidate_booking();
		}
	}
}
