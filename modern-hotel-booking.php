<?php
/**
 * Modern Hotel Booking — Direct Booking Engine, Availability Calendar & Vacation Rental PMS.
 *
 * Plugin Name:       Modern Hotel Booking — Direct Booking Engine, Availability Calendar & Vacation Rental PMS
 * Plugin URI:        https://modernhotelwp.com/
 * Description:       Hotel booking plugin & availability calendar for vacation rentals, B&Bs & accommodations. Direct booking engine, hotel reservations & guest portal.
 * Version:           3.0.1
 * Requires at least: 6.6
 * Tested up to:      7.1
 * Requires PHP:      8.0
 * Author:            StartMySuccess
 * Author URI:        https://modernhotelwp.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       modern-hotel-booking
 * Domain Path:       /languages
 *
 * @package ModernHotelBooking
 */

declare(strict_types=1);

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// PHP version check — must be at least 8.0.
if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p></div>',
				esc_html__( 'Modern Hotel Booking Error', 'modern-hotel-booking' ),
				sprintf(
				// translators: %s: current PHP version number.
					esc_html__( 'This plugin requires PHP 8.0 or higher. You are running PHP %s. Please upgrade your PHP version.', 'modern-hotel-booking' ),
					esc_html( PHP_VERSION )
				)
			);
		}
	);
	return;
}

// 2026 BP: Informational notice for administrators if BCMath extension is not loaded (fallbacks are active).
if ( ! extension_loaded( 'bcmath' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'manage_options' ) ) {
				printf(
					'<div class="notice notice-warning is-dismissible"><p><strong>%s:</strong> %s</p></div>',
					esc_html__( 'Modern Hotel Booking Notice', 'modern-hotel-booking' ),
					esc_html__( 'The PHP BCMath extension is not enabled on this server. The booking engine will use high-precision string fallback arithmetic, but enabling BCMath in your PHP configuration is recommended for optimal performance.', 'modern-hotel-booking' )
				);
			}
		}
	);
}

define( 'MHBO_VERSION', '3.0.1' );
define( 'MHBO_IS_PRO', false );
if ( ! defined( 'MHBO_PRODUCT_TIER' ) ) {
	define( 'MHBO_PRODUCT_TIER', 'free' );
}
define( 'MHBO_PLUGIN_FILE', __FILE__ );
define( 'MHBO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MHBO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MHBO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Require Composer Autoloader with PSR-4 SPL fallback.
 */
if ( file_exists( MHBO_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once MHBO_PLUGIN_DIR . 'vendor/autoload.php';
}

// Resilient fallback autoloader for MHBO namespace (prevents classmap-authoritative cache misses).
spl_autoload_register(
	function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'MHBO\\' ) ) {
			$relative_class = substr( $class_name, 5 );
			$file           = MHBO_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Dynamic autoloader resolution within plugin directory.
				require_once $file;
			}
		}
	}
);

/**
 * Explicitly load critical Pro classes to avoid autoloader cache misses.
 */
if ( defined( 'MHBO_IS_PRO' ) && MHBO_IS_PRO ) {
	if ( file_exists( MHBO_PLUGIN_DIR . 'includes/Pro/BalancePayment.php' ) ) {
		require_once MHBO_PLUGIN_DIR . 'includes/Pro/BalancePayment.php';
	}
	if ( file_exists( MHBO_PLUGIN_DIR . 'includes/Pro/HousekeepingManifest.php' ) ) {
		require_once MHBO_PLUGIN_DIR . 'includes/Pro/HousekeepingManifest.php';
	}
	if ( file_exists( MHBO_PLUGIN_DIR . 'includes/Pro/TabletReservationMode.php' ) ) {
		require_once MHBO_PLUGIN_DIR . 'includes/Pro/TabletReservationMode.php';
	}
}

/**
 * Activation hook.
 */
function mhbo_activate(): void {
	$activator_file = MHBO_PLUGIN_DIR . 'includes/Core/Activator.php';
	if ( file_exists( $activator_file ) ) {
		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Static path to plugin activator.
		require_once $activator_file;
		if ( class_exists( 'MHBO\Core\Activator' ) ) {
			\MHBO\Core\Activator::activate();
		}
	}
	do_action( 'mhbo_activated' );
}

/**
 * Deactivation hook.
 */
function mhbo_deactivate(): void {
	$deactivator_file = MHBO_PLUGIN_DIR . 'includes/Core/Deactivator.php';
	if ( file_exists( $deactivator_file ) ) {
		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Static path to plugin deactivator.
		require_once $deactivator_file;
		if ( class_exists( 'MHBO\Core\Deactivator' ) ) {
			\MHBO\Core\Deactivator::deactivate();
		}
	}
	do_action( 'mhbo_deactivated' );
}

register_activation_hook( __FILE__, 'mhbo_activate' );
register_deactivation_hook( __FILE__, 'mhbo_deactivate' );

/**
 * Boot the plugin.
 */
function mhbo_run(): void {
	// Guard against double-execution within the same request.
	// WP 6.9 can trigger init callbacks more than once in edge cases,
	// causing fatal PUC slug conflicts and duplicate block registration.
	static $did_run = false;
	if ( $did_run ) {
		return;
	}
	$did_run = true;

	try {
		// Rule 13: Register capabilities as early as possible in the run sequence.
		if ( class_exists( 'MHBO\Core\Capabilities' ) ) {
			\MHBO\Core\Capabilities::register();
		}

		if ( ! class_exists( 'MHBO\Core\Plugin' ) ) {
			return;
		}

		// Auto-upgrade DB schema when version or installed tier changes (e.g. same-version ZIP replacement upgrade).
		$stored       = get_option( 'mhbo_db_version' );
		$stored_tier  = get_option( 'mhbo_installed_tier', '' );
		$current_tier = class_exists( 'MHBO\Core\Tier' ) ? \MHBO\Core\Tier::get_package_tier() : ( defined( 'MHBO_PRODUCT_TIER' ) ? MHBO_PRODUCT_TIER : 'free' );

		if ( MHBO_VERSION !== $stored || $current_tier !== $stored_tier ) {
			if ( class_exists( 'MHBO\Core\Activator' ) ) {
				if ( false !== $stored && MHBO_VERSION !== $stored ) {
					\MHBO\Core\Activator::migrate( is_string( $stored ) ? $stored : (string) $stored, MHBO_VERSION );
				} else {
					\MHBO\Core\Activator::activate();
				}
			}
			update_option( 'mhbo_db_version', MHBO_VERSION );
			update_option( 'mhbo_installed_tier', $current_tier );
		}

		// Rule 13: Initialize table versions for caching.
		foreach ( array( 'bookings', 'rooms', 'room_types', 'pricing_rules', 'ical_connections', 'settings', 'calendar_overrides', 'guests' ) as $table ) {
			if ( false === get_option( "mhbo_v_{$table}" ) ) {
				add_option( "mhbo_v_{$table}", 1 );
			}
		}

		// Instantiate the main plugin class.
		$plugin = new \MHBO\Core\Plugin();
		$plugin->run();
	} catch ( Throwable $e ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[MHBO Boot Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() );

		if ( class_exists( 'MHBO\Core\Capabilities' ) && \MHBO\Core\Capabilities::current_user_can( \MHBO\Core\Capabilities::MANAGE_SETTINGS ) ) {
			add_action(
				'admin_notices',
				function () use ( $e ) {
					echo '<div class="notice notice-error">';
					echo '<p><strong>' . esc_html__( 'Modern Hotel Booking Error', 'modern-hotel-booking' ) . '</strong></p>';
					echo '<p>' . esc_html__( 'An error occurred while loading the plugin:', 'modern-hotel-booking' ) . '</p>';
					echo '<p style="font-family:monospace;font-size:12px;color:#c00;">' . esc_html( $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ) . '</p>';
					echo '</div>';
				}
			);
		}
	}
}

// Require Action Scheduler if bundled, before plugins_loaded priority 0.
$mhbo_action_scheduler_file = MHBO_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( file_exists( $mhbo_action_scheduler_file ) ) {
	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Static path to bundled action scheduler.
	require_once $mhbo_action_scheduler_file;
}

add_action( 'plugins_loaded', 'mhbo_run', 10 );

/**
 * Boot the AI Concierge + MCP Server subsystem.
 * Runs on plugins_loaded (priority 1) to register hooks early.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( 'MHBO\AI\Loader' ) ) {
			\MHBO\AI\Loader::init();
		}
	},
	1
);

/**
 * Boot the Elementor Integration.
 */
add_action(
	'elementor/elements/categories_registered',
	static function ( $elements_manager ): void {
		if ( class_exists( 'MHBO\Integrations\Elementor' ) ) {
			$elementor = new \MHBO\Integrations\Elementor();
			$elementor->register_categories( $elements_manager );
		}
	}
);

add_action(
	'elementor/widgets/register',
	static function ( $widgets_manager ): void {
		if ( class_exists( 'MHBO\Integrations\Elementor' ) ) {
			$elementor = new \MHBO\Integrations\Elementor();
			$elementor->register_widgets( $widgets_manager );
		}
	}
);

if ( ! function_exists( 'mhbo_get_tier' ) ) {
	/**
	 * Get the current effective tier.
	 *
	 * @return string 'free' | 'starter' | 'pro'
	 */
	function mhbo_get_tier(): string {
		if ( class_exists( 'MHBO\Core\Tier' ) ) {
			return \MHBO\Core\Tier::get_effective_tier();
		}
		return defined( 'MHBO_PRODUCT_TIER' ) ? MHBO_PRODUCT_TIER : 'free';
	}
}

if ( ! function_exists( 'mhbo_has_feature' ) ) {
	/**
	 * Check whether a feature is enabled for the current tier and license state.
	 *
	 * @param string $feature Feature slug from feature manifest.
	 * @return bool
	 */
	function mhbo_has_feature( string $feature ): bool {
		if ( class_exists( 'MHBO\Core\Tier' ) ) {
			return \MHBO\Core\Tier::has_feature( $feature );
		}
		return false;
	}
}

if ( ! function_exists( 'mhbo_is_pro' ) ) {
	/**
	 * Check whether commercial features are active.
	 *
	 * @return bool True if commercial features are active, false otherwise.
	 */
	function mhbo_is_pro(): bool {
		
		return defined( 'MHBO_IS_PRO' ) && MHBO_IS_PRO;
	}
}
