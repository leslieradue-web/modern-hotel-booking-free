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
		$can_manage = current_user_can( 'manage_options' )
			|| Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS )
			|| Capabilities::current_user_can( Capabilities::MANAGE_LHBO );

		if ( ! $can_manage ) {
			return new WP_Error(
				'rest_forbidden',
				(string) I18n::get_label( 'label_permission_denied' ),
				array( 'status' => 403 )
			);
		}

		return true;
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
		$is_system_admin = current_user_can( 'manage_options' )
			|| Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS );

		if ( ! $is_system_admin ) {
			return new WP_Error(
				'rest_forbidden',
				(string) I18n::get_label( 'label_permission_denied' ),
				array( 'status' => 403 )
			);
		}

		return true;
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
