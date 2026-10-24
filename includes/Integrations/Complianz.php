<?php declare(strict_types=1);

namespace MHBO\Integrations;

use MHBO\Core\I18n;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Complianz Privacy Suite & Cookie Blocker Integration (2026 Standard)
 *
 * Provides self-gating compatibility with Complianz GDPR/CCPA Privacy Suite.
 * Whitelists core hotel booking and payment scripts to ensure zero checkout disruptions,
 * registers functional reservation cookies, and binds to Complianz script categories.
 *
 * @since 2.6.0
 */
class Complianz {

	/**
	 * Initialize Complianz integration hooks.
	 */
	public static function init(): void {
		add_filter( 'cmplz_integrations', array( self::class, 'register_integration' ) );
		add_filter( 'cmplz_whitelisted_script_tags', array( self::class, 'whitelist_scripts' ) );
		add_filter( 'cmplz_known_script_tags', array( self::class, 'register_known_scripts' ) );
	}

	/**
	 * Check if Complianz plugin is detected and active on the site.
	 *
	 * @return bool
	 */
	public static function is_detected(): bool {
		return defined( 'cmplz_version' )
			|| defined( 'cmplz_plugin' )
			|| class_exists( '\COMPLIANZ' )
			|| function_exists( 'cmplz_has_consent' )
			|| ( function_exists( 'did_action' ) && \did_action( 'cmplz_loaded' ) > 0 );
	}

	/**
	 * Check if the Complianz synchronization feature is enabled in MHBO settings.
	 * In Pro: Controlled by the explicit toggle in Settings > GDPR (default: 0 / off).
	 * In Free: Passively active if Complianz is detected (zero-UI) to prevent form blocking.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( \MHBO\Core\Tier::is_commercial() ) {
			$option = get_option( 'mhbo_complianz_sync_enabled', 1 );
			return (bool) (int) $option;
		}

		return self::is_detected();
	}

	/**
	 * Register Modern Hotel Booking in Complianz Integration Center.
	 *
	 * @param array<string, mixed> $integrations
	 * @return array<string, mixed>
	 */
	public static function register_integration( array $integrations ): array {
		$integrations['modern-hotel-booking'] = array(
			'constant_or_function' => 'MHBO_VERSION',
			'label'                => 'Modern Hotel Booking',
			'firstparty'           => true,
		);
		return $integrations;
	}

	/**
	 * Whitelist essential hotel booking, calendar, and payment scripts
	 * from Complianz automatic cookie blocker to prevent booking disruptions.
	 *
	 * @param array<int|string, string> $whitelisted_tags
	 * @return array<int|string, string>
	 */
	public static function whitelist_scripts( array $whitelisted_tags ): array {
		if ( ! self::is_enabled() ) {
			return $whitelisted_tags;
		}

		$mhbo_scripts = array(
			// Core functional frontend scripts
			'mhbo-frontend.js',
			'mhbo-booking-form.js',
			'mhbo-calendar.js',
			'mhbo-booking-modal.js',
			'mhbo-flatpickr',
			'mhbo-deposit-checkout.js',
			'mhbo-balance-pay.js',
			'mhbo-coupons.js',
			'mhbo-extras.js',
			'mhbo-payment-gateways.js',
			'mhbo-chat-widget.js',

			// Essential payment gateways (strictly necessary for checkout contract & fraud protection)
			'js.stripe.com/v3',
			'paypal.com/sdk/js',
			'js.braintreegateway.com',
			'api.razorpay.com',

			// Inline script signatures
			'mhbo_vars',
			'mhbo_calendar',
			'mhboFrontendData',
		);

		foreach ( $mhbo_scripts as $script ) {
			if ( ! in_array( $script, $whitelisted_tags, true ) ) {
				$whitelisted_tags[] = $script;
			}
		}

		return $whitelisted_tags;
	}

	/**
	 * Register known script tags and their categories for Complianz.
	 *
	 * @param array<int, array<string, mixed>> $tags
	 * @return array<int, array<string, mixed>>
	 */
	public static function register_known_scripts( array $tags ): array {
		if ( ! self::is_enabled() ) {
			return $tags;
		}

		$tags[] = array(
			'name'               => 'modern-hotel-booking',
			'category'           => 'functional',
			'urls'               => array(
				'mhbo-frontend.js',
				'mhbo-booking-form.js',
				'mhbo-calendar.js',
				'mhbo-booking-modal.js',
				'mhbo-payment-gateways.js',
			),
			'enable_placeholder' => '0',
		);

		return $tags;
	}

	/**
	 * Get the standardized catalog of cookies and storage keys used by Modern Hotel Booking.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_cookie_catalog(): array {
		$prefix = class_exists( '\MHBO\Core\Privacy' ) ? \MHBO\Core\Privacy::get_cookie_name( '' ) : 'mhbo_';

		return array(
			array(
				'name'      => $prefix . 'session',
				'type'      => 'Cookie',
				'category'  => 'Functional / Strictly Necessary',
				'retention' => '2 hours',
				'purpose'   => __( 'Stores the guest reservation session, selected room dates, and checkout progress.', 'modern-hotel-booking' ),
			),
			array(
				'name'      => $prefix . 'ai_session',
				'type'      => 'Cookie',
				'category'  => 'Functional',
				'retention' => '24 hours',
				'purpose'   => __( 'Maintains AI Concierge conversational state during a guest visit.', 'modern-hotel-booking' ),
			),
			array(
				'name'      => $prefix . 'check_in / ' . $prefix . 'check_out',
				'type'      => 'LocalStorage',
				'category'  => 'Preferences',
				'retention' => 'Persistent until cleared',
				'purpose'   => __( 'Remembers guest travel dates between page visits for convenience.', 'modern-hotel-booking' ),
			),
			array(
				'name'      => $prefix . 'currency',
				'type'      => 'LocalStorage',
				'category'  => 'Preferences',
				'retention' => 'Persistent until cleared',
				'purpose'   => __( 'Preserves guest-selected currency preference for room rates.', 'modern-hotel-booking' ),
			),
		);
	}
}
