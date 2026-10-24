<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capabilities management class for MHBO.
 * 
 * Defines and registers granular abilities for the plugin using the modern 
 * WordPress 6.x Abilities API.
 * 
 * @package MHBOCore
 * @since   2.3.0
 */
class Capabilities {

	/**
	 * Ability to manage the entire booking system.
	 */
	public const MANAGE_LHBO = 'mhbo_manage_bookings';

	/**
	 * Ability to view bookings and calendar.
	 */
	public const VIEW_BOOKINGS = 'mhbo_view_bookings';

	/**
	 * Ability to manage room types and room inventory.
	 */
	public const MANAGE_ROOMS = 'mhbo_manage_rooms';

	/**
	 * Ability to manage housekeeping operations and manifests.
	 */
	public const MANAGE_HOUSEKEEPING = 'mhbo_manage_housekeeping';

	/**
	 * Ability to manage dynamic pricing and seasonal rules.
	 */
	public const MANAGE_PRICING = 'mhbo_manage_pricing';

	/**
	 * Ability to view analytics and reports.
	 */
	public const VIEW_ANALYTICS = 'mhbo_view_analytics';

	/**
	 * Ability to manage plugin settings.
	 */
	public const MANAGE_SETTINGS = 'mhbo_manage_settings';

	/**
	 * Ability to manage guest CRM profiles, notes, ratings, and view PII.
	 *
	 * @since 2.6.0
	 */
	public const MANAGE_GUESTS = 'mhbo_manage_guests';

	/**
	 * Ability to export guest records and CRM data.
	 *
	 * @since 2.6.0
	 */
	public const EXPORT_GUESTS = 'mhbo_export_guests';

	/**
	 * Ability to create bookings programmatically on behalf of guests.
	 *
	 * @since 2.6.2
	 */
	public const CREATE_BOOKING = 'mhbo_create_booking';

	/**
	 * Ability to process payments, folios, and refunds.
	 *
	 * @since 2.6.2
	 */
	public const PROCESS_PAYMENTS = 'mhbo_process_payments';

	/**
	 * Custom Role: Hotel Manager (General Manager / Operations / Revenue).
	 *
	 * @since 2.6.2
	 */
	public const ROLE_HOTEL_MANAGER = 'mhbo_hotel_manager';

	/**
	 * Custom Role: Hotel Front Desk (Reception / Night Audit / Walk-ins).
	 *
	 * @since 2.3.0
	 */
	public const ROLE_FRONT_DESK = 'mhbo_front_desk';

	/**
	 * Custom Role: Housekeeping Staff (Cleaning / Turnover / Inspection).
	 *
	 * @since 2.3.0
	 */
	public const ROLE_HOUSEKEEPER = 'mhbo_housekeeper';

	/**
	 * Register MHBO abilities, map roles, and filter editable roles on activation.
	 */
	public static function register(): void {
		// RATIONALE: We use the standard 'init' hook to ensure roles are updated 
		// if they were modified by other plugins.
		add_action( 'init', array( self::class, 'init_abilities' ), 1 );
		add_filter( 'editable_roles', array( self::class, 'filter_editable_roles' ) );
		add_filter( 'translate_user_role', array( self::class, 'translate_custom_role' ), 10, 2 );
	}

	/**
	 * Translate custom role display names at runtime when textdomain is loaded.
	 *
	 * Prevents early translation loading notices (_load_textdomain_just_in_time)
	 * during plugin bootstrap and activation before the 'init' action fires.
	 *
	 * @param string $translated_name Current translated role name.
	 * @param string $original_name   Original role name.
	 * @return string Localized role name.
	 */
	public static function translate_custom_role( string $translated_name, string $original_name ): string {
		if ( ! function_exists( 'did_action' ) || ! \did_action( 'init' ) ) {
			return $translated_name;
		}

		return match ( $original_name ) {
			'Hotel Manager'      => __( 'Hotel Manager', 'modern-hotel-booking' ),
			'Hotel Front Desk'   => __( 'Hotel Front Desk', 'modern-hotel-booking' ),
			'Housekeeping Staff' => __( 'Housekeeping Staff', 'modern-hotel-booking' ),
			'Hotel AI Guest'     => __( 'Hotel AI Guest', 'modern-hotel-booking' ),
			default              => $translated_name,
		};
	}

	/**
	 * Filter editable roles in WordPress user management screens to hide
	 * internal machine/AI service roles from the human role dropdown.
	 *
	 * @param array<string, array<string, mixed>> $roles Array of role definitions.
	 * @return array<string, array<string, mixed>> Filtered roles array.
	 */
	public static function filter_editable_roles( array $roles ): array {
		if ( isset( $roles['mhbo_ai_guest'] ) ) {
			unset( $roles['mhbo_ai_guest'] );
		}
		return $roles;
	}

	/**
	 * Initialize abilities and grant to Administrator role by default.
	 * Also registers and synchronizes Hotel Manager, Front Desk, and Housekeeper roles.
	 * 
	 * @internal
	 */
	public static function init_abilities(): void {
		$admin_role = get_role( 'administrator' );

		$all_abilities = array(
			self::MANAGE_LHBO,
			self::VIEW_BOOKINGS,
			self::MANAGE_ROOMS,
			self::MANAGE_HOUSEKEEPING,
			self::MANAGE_PRICING,
			self::VIEW_ANALYTICS,
			self::MANAGE_SETTINGS,
			self::MANAGE_GUESTS,
			self::EXPORT_GUESTS,
			self::CREATE_BOOKING,
			self::PROCESS_PAYMENTS,
		);

		if ( $admin_role ) {
			foreach ( $all_abilities as $ability ) {
				if ( ! $admin_role->has_cap( $ability ) ) {
					$admin_role->add_cap( $ability );
				}
			}
		}

		// 1. Hotel Manager Role (General Manager / Revenue Manager / Front Office Director)
		$manager_caps = array(
			'read'                    => true,
			self::VIEW_BOOKINGS       => true,
			self::MANAGE_LHBO         => true,
			self::MANAGE_ROOMS        => true,
			self::MANAGE_HOUSEKEEPING => true,
			self::MANAGE_PRICING      => true,
			self::VIEW_ANALYTICS      => true,
			self::MANAGE_GUESTS       => true,
			self::EXPORT_GUESTS       => true,
			self::CREATE_BOOKING      => true,
			self::PROCESS_PAYMENTS    => true,
		);

		$hotel_manager = get_role( self::ROLE_HOTEL_MANAGER );
		if ( ! $hotel_manager ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Standard WordPress role registration for non-VIP environments.
			add_role(
				self::ROLE_HOTEL_MANAGER,
				'Hotel Manager',
				$manager_caps
			);
		} else {
			foreach ( array_keys( $manager_caps ) as $cap ) {
				if ( ! $hotel_manager->has_cap( $cap ) ) {
					$hotel_manager->add_cap( $cap );
				}
			}
		}

		// 2. Hotel Front Desk Staff Role (Reception / Check-in / Folios / Walk-ins)
		$front_desk_caps = array(
			'read'                    => true,
			self::VIEW_BOOKINGS       => true,
			self::MANAGE_LHBO         => true,
			self::MANAGE_ROOMS        => true,
			self::MANAGE_HOUSEKEEPING => true,
			self::MANAGE_GUESTS       => true,
			self::PROCESS_PAYMENTS    => true,
		);

		$front_desk = get_role( self::ROLE_FRONT_DESK );
		if ( ! $front_desk ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Standard WordPress role registration for non-VIP environments.
			add_role(
				self::ROLE_FRONT_DESK,
				'Hotel Front Desk',
				$front_desk_caps
			);
		} else {
			foreach ( array_keys( $front_desk_caps ) as $cap ) {
				if ( ! $front_desk->has_cap( $cap ) ) {
					$front_desk->add_cap( $cap );
				}
			}
		}

		// 3. Housekeeping Staff Role (Turnover / Cleaning / Manifests)
		$housekeeper_caps = array(
			'read'                    => true,
			self::VIEW_BOOKINGS       => true,
			self::MANAGE_HOUSEKEEPING => true,
		);

		$housekeeper = get_role( self::ROLE_HOUSEKEEPER );
		if ( ! $housekeeper ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Standard WordPress role registration for non-VIP environments.
			add_role(
				self::ROLE_HOUSEKEEPER,
				'Housekeeping Staff',
				$housekeeper_caps
			);
		} else {
			foreach ( array_keys( $housekeeper_caps ) as $cap ) {
				if ( ! $housekeeper->has_cap( $cap ) ) {
					$housekeeper->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Get list of registered capabilities/abilities with metadata.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_registered_abilities(): array {
		return array(
			self::MANAGE_LHBO         => array(
				'label'      => 'Manage Bookings',
				'admin_only' => false,
			),
			self::VIEW_BOOKINGS       => array(
				'label'      => 'View Bookings',
				'admin_only' => false,
			),
			self::MANAGE_ROOMS        => array(
				'label'      => 'Manage Rooms',
				'admin_only' => false,
			),
			self::MANAGE_HOUSEKEEPING => array(
				'label'      => 'Manage Housekeeping',
				'admin_only' => false,
			),
			self::MANAGE_PRICING      => array(
				'label'      => 'Manage Pricing',
				'admin_only' => false,
			),
			self::VIEW_ANALYTICS      => array(
				'label'      => 'View Analytics',
				'admin_only' => false,
			),
			self::MANAGE_SETTINGS     => array(
				'label'      => 'Manage Settings',
				'admin_only' => true,
			),
			self::MANAGE_GUESTS       => array(
				'label'      => 'Manage Guests',
				'admin_only' => false,
			),
			self::EXPORT_GUESTS       => array(
				'label'      => 'Export Guests',
				'admin_only' => false,
			),
			self::CREATE_BOOKING      => array(
				'label'      => 'Create Booking via API',
				'admin_only' => false,
			),
			self::PROCESS_PAYMENTS    => array(
				'label'      => 'Process Payments',
				'admin_only' => false,
			),
		);
	}

	/**
	 * Helper to check if the current user can perform an MHBO action.
	 * 
	 * @param string $ability The ability to check.
	 * @return bool True if permitted, false otherwise.
	 */
	public static function current_user_can( string $ability ): bool {
		if ( \current_user_can( 'manage_options' ) ) {
			return true;
		}
		return \current_user_can( $ability );
	}
}
