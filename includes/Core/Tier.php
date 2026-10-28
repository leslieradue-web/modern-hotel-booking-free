<?php
/**
 * Tier Infrastructure & Feature Gating Engine.
 *
 * @package ModernHotelBooking
 * @since   2.6.0
 */

declare( strict_types=1 );

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical Tier Resolver & Feature Gate Engine.
 *
 * Implements the 3-tier hierarchy (Free, Starter, Pro) and computes the
 * Effective Tier based on the installed package bounds and validated license entitlements.
 *
 * Effective Tier = min(Package Tier, Validated License Tier).
 *
 * @since 2.6.0
 */
final class Tier {

	/**
	 * Tier slug constants.
	 */
	public const TIER_NONE    = 'none';
	public const TIER_FREE    = 'free';
	public const TIER_STARTER = 'starter';
	public const TIER_PRO     = 'pro';

	/**
	 * Numeric rank for deterministic tier hierarchy comparison.
	 */
	public const ORDER = array(
		self::TIER_NONE    => -1,
		self::TIER_FREE    => 0,
		self::TIER_STARTER => 1,
		self::TIER_PRO     => 2,
	);

	/**
	 * Canonical feature-to-tier entitlement mapping.
	 * Only commercial tiers (Starter, Pro) require entries here.
	 * Free features are implicitly available in all distributions.
	 *
	 * @var array<string, string>
	 */
	private const FEATURE_TIERS = array(
		// Starter features (7 confirmed).
		'payments_stripe'        => self::TIER_STARTER,
		'payments_paypal'        => self::TIER_STARTER,
		'payments_braintree'     => self::TIER_STARTER,
		'payment_webhooks'       => self::TIER_STARTER,
		'children_advanced'      => self::TIER_STARTER,
		'tax_advanced'           => self::TIER_STARTER,
		'invoices'               => self::TIER_STARTER,
		'license_system'         => self::TIER_STARTER,
		'auto_updater'           => self::TIER_STARTER,

		// Pro-only features (27 confirmed).
		'deposits'               => self::TIER_PRO,
		'balance_payments'       => self::TIER_PRO,
		'service_fee'            => self::TIER_PRO,
		'minimum_stay_advanced'  => self::TIER_PRO,
		'ical_export'            => self::TIER_PRO,
		'ical_sync'              => self::TIER_PRO,
		'coupons'                => self::TIER_PRO,
		'booking_extras'         => self::TIER_PRO,
		'seasonal_policies'      => self::TIER_PRO,
		'email_templates'        => self::TIER_PRO,
		'analytics'              => self::TIER_PRO,
		'housekeeping'           => self::TIER_PRO,
		'marketing'              => self::TIER_PRO,
		'guest_crm_advanced'     => self::TIER_PRO,
		'review_emails'          => self::TIER_PRO,
		'hotel_feeds'            => self::TIER_PRO,
		'admin_calendar'         => self::TIER_PRO,
		'webhooks_outbound'      => self::TIER_PRO,
		'data_transfer'          => self::TIER_PRO,
		'wpcli_pro'              => self::TIER_PRO,
		'email_logger'           => self::TIER_PRO,
		'gdpr_pro'               => self::TIER_PRO,
		'local_attractions'      => self::TIER_PRO,
		'ai_abilities_pro'       => self::TIER_PRO,
		'ai_voice'               => self::TIER_PRO,
		'ai_mcp_server'          => self::TIER_PRO,
		'ai_theme_customization' => self::TIER_PRO,
		'tablet_mode'            => self::TIER_PRO,
		'group_folios'           => self::TIER_PRO,
		'multi_room_bookings'    => self::TIER_PRO,
	);

	/**
	 * Recognized core Free features (from machine-readable manifest).
	 *
	 * @var array<int, string>
	 */
	private const KNOWN_FREE_FEATURES = array(
		'rooms',
		'children_basic',
		'minimum_stay_global',
		'booking_processor',
		'availability_calendar',
		'booking_form',
		'base_pricing',
		'email_basic',
		'guest_crm_basic',
		'admin_bookings_list',
		'admin_dashboard',
		'business_info',
		'gutenberg_blocks',
		'booking_widget',
		'privacy_gdpr',
		'i18n',
		'money',
		'tax_basic',
		'hotel_time',
		'cache',
		'security',
		'capabilities',
		'wpcli_basic',
		'admin_settings',
		'seasonal_rates_basic',
		'ai_chat_basic',
		'ai_abilities_free',
		'ai_voice_input',
		'elementor',
		'complianz',
	);

	/**
	 * Allowed actions when a commercial package license is expired (historical read-only access).
	 *
	 * @var array<int, string>
	 */
	private const EXPIRED_ALLOWED_ACTIONS = array(
		'payments.read',
		'payments.webhook_existing',
		'bookings.read_payment_metadata',
	);

	/**
	 * Actions blocked when a commercial package license is expired.
	 *
	 * @var array<int, string>
	 */
	private const EXPIRED_BLOCKED_ACTIONS = array(
		'payments.create',
		'balance_payments.create_link',
		'gateway_settings.update',
	);

	/**
	 * Check if running within automated test suite.
	 *
	 * @return bool
	 */
	public static function is_test_environment(): bool {
		$is_test = false;
		
		return $is_test;
	}

	/**
	 * Get the product package tier.
	 *
	 * Reads from MHBO_PRODUCT_TIER constant (or legacy MHBO_IS_PRO fallback).
	 *
	 * @return string 'free' | 'starter' | 'pro'
	 */
	public static function get_package_tier(): string {

if ( defined( 'MHBO_PRODUCT_TIER' ) ) {
			$tier = (string) constant( 'MHBO_PRODUCT_TIER' );
			if ( isset( self::ORDER[ $tier ] ) && self::TIER_NONE !== $tier ) {
				return $tier;
			}
		}

		if ( defined( 'MHBO_IS_PRO' ) && constant( 'MHBO_IS_PRO' ) ) {
			return self::TIER_PRO;
		}

		return self::TIER_FREE;
	}

	/**
	 * Get the validated license tier.
	 *
	 * Thin, non-mutating reader. Checks LicenseValidator or active license state.
	 *
	 * @return string 'free' | 'starter' | 'pro' | 'none'
	 */
	public static function get_license_tier(): string {
		$resolved_tier = self::TIER_NONE;

		// phpcs:disable Squiz.Commenting.BlockComment.NoNewLine
		
		// phpcs:enable Squiz.Commenting.BlockComment.NoNewLine

		return $resolved_tier;
	}

	/**
	 * Compute the effective tier.
	 *
	 * Keeps the installed package tier active (Pro stays Pro, Starter stays Starter,
	 * Free stays Free) so paid commercial installations are never degraded to
	 * Starter or Free when a license is unvalidated, inactive, or deactivated.
	 *
	 * @return string 'free' | 'starter' | 'pro'
	 */
	public static function get_effective_tier(): string {
		$package_tier = self::get_package_tier();

		// Free build has no commercial code physically present.
		if ( self::TIER_FREE === $package_tier ) {
			return self::TIER_FREE;
		}

		$effective = $package_tier;

return $effective;
	}

	/**
	 * Check if the effective tier is at least the specified tier.
	 *
	 * @param string $tier Target tier ('free', 'starter', 'pro').
	 * @return bool True if effective tier meets or exceeds target tier.
	 */
	public static function is_effective_at_least( string $tier ): bool {
		$effective       = self::get_effective_tier();
		$effective_order = self::ORDER[ $effective ] ?? 0;
		$target_order    = self::ORDER[ $tier ] ?? 0;

		return $effective_order >= $target_order;
	}

	/**
	 * Check if the plugin is operating in a commercial tier (Starter or Pro).
	 *
	 * @return bool True if Starter or Pro is effectively active.
	 */
	public static function is_commercial(): bool {
		return self::is_effective_at_least( self::TIER_STARTER );
	}

	/**
	 * Check if the plugin is operating in at least the Starter tier.
	 *
	 * @return bool True if Starter is effectively active.
	 */
	public static function is_starter(): bool {
		return self::is_effective_at_least( self::TIER_STARTER );
	}

	/**
	 * Check if the plugin is operating in the Pro tier.
	 *
	 * @return bool True if Pro is effectively active.
	 */
	public static function is_pro(): bool {
		return self::is_effective_at_least( self::TIER_PRO );
	}

	/**
	 * Check if a specific feature is available under the current effective tier.
	 *
	 * @param string $feature Feature slug (e.g. 'payments_stripe', 'rooms').
	 * @return bool True if available, false otherwise.
	 */
	public static function has_feature( string $feature ): bool {
		if ( isset( self::FEATURE_TIERS[ $feature ] ) ) {
			$required_tier = self::FEATURE_TIERS[ $feature ];
			return self::is_effective_at_least( $required_tier );
		}

		if ( in_array( $feature, self::KNOWN_FREE_FEATURES, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Capability-style permission check for action execution.
	 *
	 * Supports historical read-only access when license is expired.
	 *
	 * @param string $action Action slug (e.g. 'payments.read', 'payments.create').
	 * @return bool True if allowed.
	 */
	public static function can( string $action ): bool {
		// Commercial tier active: all actions allowed unless pro-restricted.
		if ( self::is_commercial() ) {
			if ( str_starts_with( $action, 'pro.' ) ) {
				return self::is_effective_at_least( self::TIER_PRO );
			}
			return true;
		}

		// Expired state on commercial package: read-only access to historical data.
		$package_tier = self::get_package_tier();
		if ( self::TIER_FREE !== $package_tier ) {
			if ( in_array( $action, self::EXPIRED_ALLOWED_ACTIONS, true ) ) {
				return true;
			}
			if ( in_array( $action, self::EXPIRED_BLOCKED_ACTIONS, true ) ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Get an array of all active feature slugs under the effective tier.
	 *
	 * @return array<int, string> List of active feature slugs.
	 */
	public static function get_features(): array {
		$features = self::KNOWN_FREE_FEATURES;

		foreach ( self::FEATURE_TIERS as $feature => $tier ) {
			if ( self::is_effective_at_least( $tier ) ) {
				$features[] = $feature;
			}
		}

		return array_values( array_unique( $features ) );
	}

	/**
	 * Flush tier-related transients and object cache entries.
	 * Targeted invalidation (does NOT use aggressive wp_cache_flush).
	 */
	public static function flush_cache(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'mhbo_effective_tier' );
			
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'mhbo_effective_tier', 'mhbo' );
			
		}
	}
}
