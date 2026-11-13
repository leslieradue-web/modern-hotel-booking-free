<?php declare(strict_types=1);

namespace MHBO\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use MHBO\Core\Capabilities;
use MHBO\Core\I18n;

/**
 * Base REST Controller for MHBO Admin Endpoints.
 *
 * Provides standardized permission checks, response helpers, and schema validation
 * for all administrative REST controllers in the mhbo/v1/admin namespace.
 *
 * @package MHBO\Api
 * @since   2.5.0
 */
abstract class AbstractAdminRestController extends WP_REST_Controller {

	/**
	 * REST API Namespace.
	 *
	 * @var string
	 */
	public const API_NAMESPACE = 'mhbo/v1';

	/**
	 * REST API Version.
	 *
	 * @var string
	 */
	public const API_VERSION = 'v1';

	/**
	 * Namespace for endpoints (Requirement 2).
	 *
	 * @var non-falsy-string
	 */
	protected $namespace = self::API_NAMESPACE;

	/**
	 * Base route segment for admin controllers (Requirement 2).
	 *
	 * @var string
	 */
	protected $rest_base = 'admin';

	/**
	 * Register REST API routes for this controller.
	 * Child classes must override this method.
	 */
	public function register_routes(): void {
		// Child classes must override this method.
	}

	/**
	 * Requirement 1: Centralized Capability & Permission Check.
	 *
	 * Enforces administrative capability at the base controller level.
	 * Returns WP_Error on failure to produce a descriptive HTTP 403 Forbidden
	 * response with a localized message rather than a generic 401.
	 *
	 * @param WP_REST_Request|null $request Request object (optional).
	 * @return bool|WP_Error True if permitted, WP_Error with 403 status otherwise.
	 */
	public function check_admin_permission( ?WP_REST_Request $request = null ): bool|WP_Error {
		return $this->require_any_capability(
			array(
				Capabilities::MANAGE_SETTINGS,
				Capabilities::MANAGE_LHBO,
			)
		);
	}

	/**
	 * Requirement 1b: Strict System Administration Capability Check.
	 *
	 * Enforces strict site/plugin administration capability (manage_options or MANAGE_SETTINGS).
	 * Used for licensing, backups, payment gateway credentials, and webhook endpoints.
	 * Blocks operational staff (Front Desk, Housekeeping) who only hold MANAGE_LHBO.
	 *
	 * @param WP_REST_Request|null $request Request object (optional).
	 * @return bool|WP_Error True if permitted, WP_Error with 403 status otherwise.
	 */
	public function check_system_admin_permission( ?WP_REST_Request $request = null ): bool|WP_Error {
		return $this->require_any_capability( array( Capabilities::MANAGE_SETTINGS ) );
	}

	/**
	 * Requirement 1c: Pricing, Rate Overrides, Extras, and Coupons Capability Check.
	 *
	 * Permits administrators and Hotel Managers (MANAGE_PRICING or MANAGE_SETTINGS),
	 * blocking Front Desk Receptionists (MANAGE_LHBO only).
	 *
	 * @param WP_REST_Request|null $request Request object (optional).
	 * @return bool|WP_Error True if permitted, WP_Error with 403 status otherwise.
	 */
	public function check_pricing_permission( ?WP_REST_Request $request = null ): bool|WP_Error {
		return $this->require_any_capability(
			array(
				Capabilities::MANAGE_PRICING,
				Capabilities::MANAGE_SETTINGS,
			)
		);
	}

	/**
	 * Requirement 1d: Room Inventory Capability Check.
	 *
	 * @param WP_REST_Request|null $request Request object (optional).
	 * @return bool|WP_Error True if permitted, WP_Error with 403 status otherwise.
	 */
	public function check_rooms_permission( ?WP_REST_Request $request = null ): bool|WP_Error {
		return $this->require_any_capability(
			array(
				Capabilities::MANAGE_ROOMS,
				Capabilities::MANAGE_SETTINGS,
			)
		);
	}

	/**
	 * Requirement 1e: Financial Analytics Capability Check.
	 *
	 * @param WP_REST_Request|null $request Request object (optional).
	 * @return bool|WP_Error True if permitted, WP_Error with 403 status otherwise.
	 */
	public function check_analytics_permission( ?WP_REST_Request $request = null ): bool|WP_Error {
		return $this->require_any_capability(
			array(
				Capabilities::VIEW_ANALYTICS,
				Capabilities::MANAGE_SETTINGS,
			)
		);
	}

	/**
	 * Requirement 1f: Housekeeping & Front Desk Turnovers Capability Check.
	 *
	 * @param WP_REST_Request|null $request Request object (optional).
	 * @return bool|WP_Error True if permitted, WP_Error with 403 status otherwise.
	 */
	public function check_housekeeping_or_desk_permission( ?WP_REST_Request $request = null ): bool|WP_Error {
		return $this->require_any_capability(
			array(
				Capabilities::MANAGE_HOUSEKEEPING,
				Capabilities::MANAGE_LHBO,
				Capabilities::MANAGE_SETTINGS,
			)
		);
	}

	/**
	 * Requirement 1g: Cashier Payment & Desk Balance Link Operations Capability Check.
	 *
	 * @param WP_REST_Request|null $request Request object (optional).
	 * @return bool|WP_Error True if permitted, WP_Error with 403 status otherwise.
	 */
	public function check_payments_or_desk_permission( ?WP_REST_Request $request = null ): bool|WP_Error {
		return $this->require_any_capability(
			array(
				Capabilities::PROCESS_PAYMENTS,
				Capabilities::MANAGE_LHBO,
				Capabilities::MANAGE_SETTINGS,
			)
		);
	}

	/**
	 * Shared capability evaluation helper returning standardized HTTP 403 WP_Error on denial.
	 *
	 * @param list<string> $capabilities Allowed MHBO capability keys.
	 * @return bool|WP_Error True if current user holds any required capability or manage_options.
	 */
	protected function require_any_capability( array $capabilities ): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		foreach ( $capabilities as $capability ) {
			if ( Capabilities::current_user_can( $capability ) ) {
				return true;
			}
		}

		return new WP_Error(
			'rest_forbidden',
			(string) I18n::get_label( 'label_permission_denied' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Requirement 3: Standardized typed success response helper.
	 *
	 * @param mixed $data   Response payload.
	 * @param int   $status HTTP status code (defaults to 200 OK).
	 * @return WP_REST_Response Formatted REST response.
	 */
	protected function success( mixed $data = null, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			$status
		);
	}

	/**
	 * Requirement 3: Standardized typed error response helper.
	 *
	 * @param string $code    Unique error code string.
	 * @param string $message Localized error description.
	 * @param int    $status  HTTP status code (e.g. 400, 403, 404, 500).
	 * @param mixed  $data    Optional supplemental error context.
	 * @return WP_Error Configured WordPress error with status code.
	 */
	protected function error( string $code, string $message, int $status = 400, mixed $data = null ): WP_Error {
		$error_data = array( 'status' => $status );
		if ( null !== $data ) {
			$error_data['data'] = $data;
		}

		return new WP_Error( $code, $message, $error_data );
	}

	/**
	 * Requirement 4: Schema validation helper.
	 *
	 * Iterates over field definitions and validates request parameters using
	 * WordPress Core rest_validate_value_from_schema.
	 *
	 * @param WP_REST_Request      $request Request object.
	 * @param array<string, mixed> $schema  Map of parameter names to schema args.
	 * @return bool|WP_Error True if valid, WP_Error with 400 status if invalid.
	 */
	protected function validate_schema( WP_REST_Request $request, array $schema ): bool|WP_Error {
		foreach ( $schema as $param => $args ) {
			$value = $request->get_param( (string) $param );

			// Skip optional parameters that were not supplied
			if ( null === $value && empty( $args['required'] ) ) {
				continue;
			}

			$valid = rest_validate_value_from_schema( $value, (array) $args, (string) $param );
			if ( is_wp_error( $valid ) ) {
				return $this->error(
					'invalid_param',
					$valid->get_error_message(),
					400
				);
			}
		}

		return true;
	}
}
