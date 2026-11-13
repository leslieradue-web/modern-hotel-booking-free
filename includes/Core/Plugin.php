<?php declare(strict_types=1);

namespace MHBO\Core;

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
				if ( \MHBO\Core\Tier::is_effective_at_least( \MHBO\Core\Tier::TIER_PRO ) && class_exists( 'MHBO\Api\CouponsController' ) ) {
					( new \MHBO\Api\CouponsController() )->register_routes();
				}
				if ( class_exists( 'MHBO\Api\AdminSystemController' ) ) {
					( new \MHBO\Api\AdminSystemController() )->register_routes();
				}
				if ( \MHBO\Core\Tier::is_effective_at_least( \MHBO\Core\Tier::TIER_PRO ) && class_exists( 'MHBO\Api\AdminWebhooksController' ) ) {
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

				if ( \MHBO\Core\Tier::is_effective_at_least( \MHBO\Core\Tier::TIER_PRO ) && class_exists( 'MHBO\Api\MarketingController' ) ) {
					( new \MHBO\Api\MarketingController() )->register_routes();
				}

				if ( \MHBO\Core\Tier::is_effective_at_least( \MHBO\Core\Tier::TIER_PRO ) && class_exists( 'MHBO\Api\BalancePaymentController' ) ) {
					( new \MHBO\Api\BalancePaymentController() )->register_routes();
				}

				if ( class_exists( 'MHBO\Api\GuestPortalController' ) ) {
					( new \MHBO\Api\GuestPortalController() )->register_routes();
				}

}
		);

		/* ---- Guest CRM Async Tasks ---- */
		if ( GuestManager::is_enabled() && class_exists( 'MHBO\Core\GuestAsyncTasks' ) ) {
			\MHBO\Core\GuestAsyncTasks::init();
		}

/* ---- Privacy & Consent Integrations (Complianz / WP Consent API) ---- */
		if ( class_exists( 'MHBO\Integrations\Complianz' ) ) {
			\MHBO\Integrations\Complianz::init();
		}
		if ( class_exists( 'MHBO\Integrations\Consent' ) ) {
			\MHBO\Integrations\Consent::init();
		}

		add_action( 'updated_option', array( __CLASS__, 'maybe_bump_settings_version' ), 10, 1 );
		add_action( 'added_option', array( __CLASS__, 'maybe_bump_settings_version' ), 10, 1 );
		add_action( 'deleted_option', array( __CLASS__, 'maybe_bump_settings_version' ), 10, 1 );

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
	 * Schedule maintenance cron jobs (idempotent, throttled to admin/cron contexts).
	 */
	private function schedule_cron(): void {
		add_action(
			'mhbo_daily_maintenance',
			static function (): void {
				Cache::prune_expired_transients();
				RestApi::purge_expired_idempotency_keys();
			}
		);
		add_action( 'mhbo_hourly_maintenance', array( $this, 'cleanup_stale_pending_payments' ) );

		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		$target_version = defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '3.0.3';
		if (
			get_option( 'mhbo_cron_version', '' ) !== $target_version
			|| false === get_transient( 'mhbo_cron_verified' )
		) {
			self::ensure_cron_scheduled();
		}
	}

	/**
	 * Ensure core maintenance cron events are scheduled without running wp_next_scheduled on frontend requests.
	 *
	 * @return void
	 */
	public static function ensure_cron_scheduled(): void {
		if ( ! wp_next_scheduled( 'mhbo_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'mhbo_daily_maintenance' );
		}
		if ( ! wp_next_scheduled( 'mhbo_hourly_maintenance' ) ) {
			wp_schedule_event( time(), 'hourly', 'mhbo_hourly_maintenance' );
		}

		update_option( 'mhbo_cron_version', defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '3.0.3', false );
		set_transient( 'mhbo_cron_verified', '1', 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Cancel stale pending_payment checkout holds past the expiry window to release inventory.
	 *
	 * Skips bookings with any payment evidence to avoid canceling fully-paid
	 * or partially-paid reservations whose webhook/status transition is delayed.
	 */
	public function cleanup_stale_pending_payments(): void {
		Pricing::cleanup_stale_pending_payments_now();
	}

	/**
	 * Bump TABLE_SETTINGS generation when operational/pricing MHBO options change.
	 * Prevents cache drift without incurring recursive updates on mhbo_v_* counters.
	 *
	 * @param mixed $option Option name mutated.
	 */
	public static function maybe_bump_settings_version( mixed $option ): void {
		static $bumping = false;
		if ( $bumping || ! is_string( $option ) || ! str_starts_with( $option, 'mhbo_' ) ) {
			return;
		}

		// Skip internal version counters and transient/sequence/queue state.
		if (
			str_starts_with( $option, 'mhbo_v_' )
			|| str_starts_with( $option, 'mhbo_bseq_' )
			|| str_starts_with( $option, 'mhbo_ical_key_' )
			|| str_starts_with( $option, 'mhbo_marketing_queue' )
		) {
			return;
		}

		$bumping = true;
		try {
			Cache::bump( Cache::TABLE_SETTINGS );
		} finally {
			$bumping = false;
		}
	}

	/**
	 * Resolve a plugin-relative source asset path (`assets/...`) to its compiled/minified
	 * `build/...` path when available and `SCRIPT_DEBUG` is disabled.
	 *
	 * @param string $relative_path Relative path from plugin root (e.g. 'assets/js/mhbo-admin.js').
	 * @return string Normalized relative path to serve.
	 */
	public static function resolve_asset_relative( string $relative_path ): string {
		$rel = ltrim( str_replace( '\\', '/', $relative_path ), '/' );

		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return $rel;
		}

		$candidate = '';
		if ( str_starts_with( $rel, 'assets/js/pro/' ) && str_ends_with( $rel, '.js' ) ) {
			$candidate = 'build/js/pro/' . substr( $rel, strlen( 'assets/js/pro/' ) );
		} elseif ( str_starts_with( $rel, 'assets/js/' ) && ! str_contains( substr( $rel, strlen( 'assets/js/' ) ), '/' ) && str_ends_with( $rel, '.js' ) ) {
			$candidate = 'build/' . substr( $rel, strlen( 'assets/js/' ) );
		} elseif ( str_starts_with( $rel, 'assets/css/' ) && ! str_starts_with( $rel, 'assets/css/vendor/' ) && str_ends_with( $rel, '.css' ) ) {
			$candidate = 'build/css/' . substr( $rel, strlen( 'assets/css/' ) );
		}

		if ( '' === $candidate ) {
			return $rel;
		}

		$src_full   = MHBO_PLUGIN_DIR . $rel;
		$build_full = MHBO_PLUGIN_DIR . $candidate;

		if ( ! file_exists( $build_full ) ) {
			return $rel;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $src_full ) ) {
			$src_mtime   = filemtime( $src_full );
			$build_mtime = filemtime( $build_full );
			if ( false !== $src_mtime && false !== $build_mtime && $src_mtime > ( $build_mtime + 5 ) ) {
				return $rel;
			}
		}

		return $candidate;
	}

	/**
	 * Resolve public URL for a plugin asset, preferring compiled/minified `build/` outputs.
	 *
	 * @param string $relative_path Relative path from plugin root (e.g. 'assets/css/mhbo-style.css').
	 * @return string Full URL to the resolved asset.
	 */
	public static function asset_url( string $relative_path ): string {
		return MHBO_PLUGIN_URL . self::resolve_asset_relative( $relative_path );
	}

	/**
	 * Compute cache-busting asset version string using filemtime of the resolved asset.
	 *
	 * @param string $relative_path Relative path from plugin root.
	 * @return string File modification timestamp or plugin version fallback.
	 */
	public static function asset_ver( string $relative_path ): string {
		$resolved = self::resolve_asset_relative( $relative_path );
		$full     = MHBO_PLUGIN_DIR . $resolved;
		if ( file_exists( $full ) ) {
			$mtime = filemtime( $full );
			if ( false !== $mtime ) {
				return (string) $mtime;
			}
		}

		return defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '3.0.3';
	}

	/**
	 * Resolve self-hosted GDPR-compliant webfont stylesheet URL (OFL-1.1).
	 *
	 * @param string $family Font family slug ('inter' or 'outfit').
	 * @return string Stylesheet URL under `assets/fonts/`.
	 */
	public static function get_font_url( string $family ): string {
		$slug = ( 'outfit' === strtolower( trim( $family ) ) ) ? 'outfit' : 'inter';
		return MHBO_PLUGIN_URL . 'assets/fonts/' . $slug . '.css';
	}
}
