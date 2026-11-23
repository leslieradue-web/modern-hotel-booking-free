<?php declare(strict_types=1);

namespace MHBO\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Consent API Standard Integration (2026 Edition)
 *
 * Implements the official WordPress WP Consent API specification.
 * Declares plugin consent categories and provides server-side consent state helpers.
 *
 * @since 2.6.0
 */
class Consent {

	/**
	 * Standard consent categories.
	 */
	public const CATEGORY_FUNCTIONAL  = 'functional';
	public const CATEGORY_PREFERENCES = 'preferences';
	public const CATEGORY_STATISTICS  = 'statistics';
	public const CATEGORY_MARKETING   = 'marketing';

	/**
	 * Initialize WP Consent API hooks.
	 */
	public static function init(): void {
		if ( defined( 'MHBO_PLUGIN_FILE' ) && function_exists( 'plugin_basename' ) ) {
			$plugin = plugin_basename( MHBO_PLUGIN_FILE );
			add_filter( "wp_consent_api_registered_{$plugin}", array( self::class, 'filter_consent_api_registered' ) );
		}
	}

	/**
	 * Check if WP Consent API is installed and registered.
	 *
	 * @return bool
	 */
	public static function is_detected(): bool {
		return function_exists( 'wp_has_consent' )
			|| defined( 'WP_CONSENT_API_VERSION' );
	}

	/**
	 * Check if WP Consent API integration is enabled in MHBO settings.
	 * In Pro: Controlled by the explicit toggle in Settings > GDPR (default: 0 / off).
	 * In Free: Passively active if WP Consent API is detected to ensure compatibility.
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
	 * Declare Modern Hotel Booking's compatibility with the WP Consent API.
	 *
	 * @param bool $registered Existing registration flag.
	 * @return bool
	 */
	public static function filter_consent_api_registered( bool $registered ): bool {
		return self::is_enabled() ? true : $registered;
	}

	/**
	 * Check if the visitor has granted consent for a given category.
	 * If WP Consent API is not active, returns true (unrestricted).
	 *
	 * @param string $category The consent category (functional, preferences, statistics, marketing).
	 * @return bool True if consent is granted or not required.
	 */
	public static function has_consent( string $category = self::CATEGORY_FUNCTIONAL ): bool {
		if ( self::CATEGORY_FUNCTIONAL === $category ) {
			return true;
		}

		if ( function_exists( 'wp_has_consent' ) ) {
			return (bool) \wp_has_consent( $category );
		}

		return true;
	}

	/**
	 * Get a snapshot of current consent states across all categories.
	 *
	 * @return array<string, bool>
	 */
	public static function get_consent_state(): array {
		return array(
			self::CATEGORY_FUNCTIONAL  => self::has_consent( self::CATEGORY_FUNCTIONAL ),
			self::CATEGORY_PREFERENCES => self::has_consent( self::CATEGORY_PREFERENCES ),
			self::CATEGORY_STATISTICS  => self::has_consent( self::CATEGORY_STATISTICS ),
			self::CATEGORY_MARKETING   => self::has_consent( self::CATEGORY_MARKETING ),
		);
	}
}
