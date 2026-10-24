<?php declare(strict_types=1);

namespace MHBO\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use MHBO\Core\Capabilities;
use MHBO\Core\GuestManager;
use MHBO\Core\GuestIdentityResolver;
use MHBO\Core\GuestStatsService;
use MHBO\Core\GuestMigration;
use MHBO\Core\Cache;
use MHBO\Core\Money;
use MHBO\Core\Email;
use MHBO\Core\HotelTime;

/**
 * REST API Controller for Guest CRM Administration.
 *
 * @package MHBO\Api
 * @since   2.6.0
 */
class AdminGuestsController extends AbstractAdminRestController {

	/**
	 * Base route segment.
	 *
	 * @var string
	 */
	protected $rest_base = 'admin/guests';

	/**
	 * Register REST API routes for Guest CRM.
	 */
	public function register_routes(): void {
		// Zero CRM overhead: If CRM is disabled, do not register routes.
		if ( ! GuestManager::is_enabled() ) {
			return;
		}

		// List guests & Create guest
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_guest_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'check_guest_permission' ),
				),
			)
		);

		// Fast Search Route Alias: /mhbo/v1/guests (Direct high-velocity lookup for Command Center & Widgets)
		register_rest_route(
			$this->namespace,
			'/guests',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_guest_permission' ),
				),
			)
		);

		// Search Route Alias: /mhbo/v1/admin/guests/search
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_guest_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_csv' ),
				'permission_callback' => array( $this, 'check_export_permission' ),
			)
		);

register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/recalculate-all',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'recalculate_all_stats' ),
				'permission_callback' => array( $this, 'check_guest_permission' ),
			)
		);

		// Single guest routes: Get, Update, Soft-delete
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_guest_permission' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'check_guest_permission' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'check_guest_permission' ),
					'args'                => array(
						'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ),
					),
				),
			)
		);

		// GDPR Anonymize single guest
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/anonymize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'anonymize_item' ),
				'permission_callback' => array( $this, 'check_guest_permission' ),
				'args'                => array(
					'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ),
				),
			)
		);

		// Guest Stay History Timeline
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item_history' ),
				'permission_callback' => array( $this, 'check_guest_permission' ),
				'args'                => array(
					'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ),
				),
			)
		);

}

	/**
	 * Check if current user has permission to manage guest CRM.
	 *
	 * @param WP_REST_Request|null $request
	 * @return bool|WP_Error
	 */
	public function check_guest_permission( ?WP_REST_Request $request = null ): bool|WP_Error {
		if (
			Capabilities::current_user_can( Capabilities::MANAGE_GUESTS )
			|| Capabilities::current_user_can( Capabilities::MANAGE_LHBO )
			|| Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS )
			|| current_user_can( 'manage_options' )
		) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Insufficient permissions to manage guest CRM records.', 'modern-hotel-booking' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Check if current user has permission to export guest records.
	 *
	 * @param WP_REST_Request|null $request
	 * @return bool|WP_Error
	 */
	public function check_export_permission( ?WP_REST_Request $request = null ): bool|WP_Error {
		if (
			Capabilities::current_user_can( Capabilities::EXPORT_GUESTS )
			|| current_user_can( 'manage_options' )
		) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Insufficient permissions to export guest records.', 'modern-hotel-booking' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Get paginated list of guests for DataViews with server-side filters.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		global $wpdb;

		$page         = max( 1, (int) $request->get_param( 'page' ) );
		$req_per_page = $request->get_param( 'per_page' );
		if ( 'all' === strtolower( (string) $req_per_page ) || -1 === (int) $req_per_page ) {
			$per_page = 999999;
		} else {
			$per_page = min( 999999, max( 1, (int) ( $req_per_page ?: 25 ) ) );
		}
		$offset = ( $page - 1 ) * $per_page;

		$raw_search   = $request->get_param( 'search' ) ?: $request->get_param( 's' );
		$search       = sanitize_text_field( (string) ( $raw_search ?: '' ) );
		$vip_status   = sanitize_key( (string) ( $request->get_param( 'vip_status' ) ?: '' ) );
		$status       = sanitize_key( (string) ( $request->get_param( 'status' ) ?: '' ) );
		$caution_flag = $request->get_param( 'caution_flag' );

		$allowed_orderby = array(
			'id'          => 'id',
			'name'        => 'first_name',
			'email'       => 'email',
			'total_stays' => 'total_stays',
			'total_spend' => 'total_spend',
			'created_at'  => 'created_at',
		);

		$req_orderby = (string) ( $request->get_param( 'orderby' ) ?: 'id' );
		$orderby     = $allowed_orderby[ $req_orderby ] ?? 'id';
		$order       = 'ASC' === strtoupper( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC';

		$where_clauses = array();
		$query_params  = array();

		if ( '' !== $status ) {
			$where_clauses[] = 'status = %s';
			$query_params[]  = $status;
		} else {
			$where_clauses[] = "status != 'anonymized'";
		}

		if ( '' !== $search ) {
			$like            = '%' . $wpdb->esc_like( $search ) . '%';
			$where_clauses[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			$query_params[]  = $like;
			$query_params[]  = $like;
			$query_params[]  = $like;
			$query_params[]  = $like;
		}

		if ( '' !== $vip_status ) {
			$where_clauses[] = 'vip_status = %s';
			$query_params[]  = $vip_status;
		}

		if ( null !== $caution_flag && '' !== $caution_flag ) {
			$where_clauses[] = 'caution_flag = %d';
			$query_params[]  = (bool) $caution_flag ? 1 : 0;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Count Query
		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_guests WHERE {$where_sql}";
		if ( array() !== $query_params ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $query_params ) );
		} else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$order_clause = sanitize_sql_orderby( "{$orderby} {$order}" ) ?: 'id DESC';

		// Items Query
		if ( $per_page >= 999999 ) {
			$items_sql    = "SELECT id, first_name, last_name, email, phone, vip_status, admin_rating, internal_notes, caution_flag, total_stays, total_spend, created_at, updated_at
                          FROM {$wpdb->prefix}mhbo_guests
                          WHERE {$where_sql}
                          ORDER BY {$order_clause}";
			$items_params = $query_params;
		} else {
			$items_sql    = "SELECT id, first_name, last_name, email, phone, vip_status, admin_rating, internal_notes, caution_flag, total_stays, total_spend, created_at, updated_at
                          FROM {$wpdb->prefix}mhbo_guests
                          WHERE {$where_sql}
                          ORDER BY {$order_clause}
                          LIMIT %d, %d";
			$items_params = array_merge( $query_params, array( $offset, $per_page ) );
		}

		if ( array() !== $items_params ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$items = $wpdb->get_results( $wpdb->prepare( $items_sql, $items_params ) );
		} else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$items = $wpdb->get_results( $items_sql );
		}

		if ( ! empty( $items ) ) {
			foreach ( $items as &$it ) {
				$it->id           = (int) $it->id;
				$it->caution_flag = (int) $it->caution_flag;
				$it->total_stays  = (int) $it->total_stays;
				$it->total_spend  = (float) $it->total_spend;
				if ( null !== $it->admin_rating ) {
					$it->admin_rating = (int) $it->admin_rating;
				}
			}
			unset( $it );
		}

		$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;

		$response = new WP_REST_Response(
			array(
				'items'  => $items ?: array(),
				'guests' => $items ?: array(),
				'total'  => $total,
				'pages'  => $total_pages,
			),
			200
		);

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * Get single guest profile.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$id    = (int) $request->get_param( 'id' );
		$guest = GuestManager::get_guest( $id );

		if ( ! $guest ) {
			return $this->error( 'guest_not_found', __( 'Guest profile not found.', 'modern-hotel-booking' ), 404 );
		}

		$guest->id           = (int) $guest->id;
		$guest->caution_flag = (int) ( $guest->caution_flag ?? 0 );
		$guest->total_stays  = (int) ( $guest->total_stays ?? 0 );
		$guest->total_spend  = (float) ( $guest->total_spend ?? 0 );
		if ( null !== $guest->admin_rating ) {
			$guest->admin_rating = (int) $guest->admin_rating;
		}

return $this->success( $guest );
	}

	/**
	 * Create manual guest profile.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$first_name = sanitize_text_field( (string) $request->get_param( 'first_name' ) );
		$last_name  = sanitize_text_field( (string) $request->get_param( 'last_name' ) );
		$email      = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone      = sanitize_text_field( (string) $request->get_param( 'phone' ) );

		if ( '' === $email ) {
			return $this->error( 'missing_email', __( 'Guest email address is required.', 'modern-hotel-booking' ), 400 );
		}

		$email_normalized = GuestIdentityResolver::normalize_email( $email );
		$phone_normalized = GuestIdentityResolver::normalize_phone( $phone );

		$guest_id = GuestIdentityResolver::insert_guest(
			$first_name,
			$last_name,
			$email,
			$email_normalized,
			$phone,
			$phone_normalized
		);

		if ( $guest_id <= 0 ) {
			return $this->error( 'create_failed', __( 'Failed to create guest profile.', 'modern-hotel-booking' ), 500 );
		}

		$guest = GuestManager::get_guest( $guest_id );
		return $this->success( $guest, 201 );
	}

	/**
	 * Update guest profile fields.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$id    = (int) $request->get_param( 'id' );
		$guest = GuestManager::get_guest( $id );

		if ( ! $guest ) {
			return $this->error( 'guest_not_found', __( 'Guest profile not found.', 'modern-hotel-booking' ), 404 );
		}

		$params  = $request->get_params();
		$updated = GuestManager::update_guest( $id, $params );

		if ( ! $updated ) {
			return $this->error( 'update_failed', __( 'Failed to update guest profile.', 'modern-hotel-booking' ), 500 );
		}

		$fresh = GuestManager::get_guest( $id );
		return $this->success( $fresh );
	}

	/**
	 * Soft-delete / archive guest.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$id    = (int) $request->get_param( 'id' );
		$guest = GuestManager::get_guest( $id );

		if ( ! $guest ) {
			return $this->error( 'guest_not_found', __( 'Guest profile not found.', 'modern-hotel-booking' ), 404 );
		}

		$archived = GuestManager::archive_guest( $id );
		if ( ! $archived ) {
			return $this->error( 'delete_failed', __( 'Failed to archive guest profile.', 'modern-hotel-booking' ), 500 );
		}

		return $this->success(
			array(
				'archived' => true,
				'id'       => $id,
			) 
		);
	}

	/**
	 * Anonymize guest profile (GDPR irreversible erasure).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function anonymize_item( $request ): WP_REST_Response|WP_Error {
		$id    = (int) $request->get_param( 'id' );
		$guest = GuestManager::get_guest( $id );

		if ( ! $guest ) {
			return $this->error( 'guest_not_found', __( 'Guest profile not found.', 'modern-hotel-booking' ), 404 );
		}

		$anonymized = GuestManager::anonymize_guest( $id );
		if ( ! $anonymized ) {
			return $this->error( 'anonymize_failed', __( 'Failed to anonymize guest profile.', 'modern-hotel-booking' ), 500 );
		}

		return $this->success(
			array(
				'anonymized' => true,
				'id'         => $id,
			) 
		);
	}

	/**
	 * Get stay history for a single guest.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_item_history( $request ): WP_REST_Response {
		$id       = (int) $request->get_param( 'id' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );

		$history = GuestManager::get_guest_history( $id, $page, $per_page, $search );
		return $this->success( $history );
	}

/**
	 * Recalculate statistics and LTV for all active guests.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function recalculate_all_stats( $request ): WP_REST_Response {
		// First auto-migrate any unlinked historical bookings
		if ( class_exists( GuestMigration::class ) ) {
			GuestMigration::migrate_batch( 0, 1000 );
		}

		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$guest_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}mhbo_guests WHERE status != 'anonymized'" );

		$count = 0;
		if ( ! empty( $guest_ids ) ) {
			foreach ( $guest_ids as $gid ) {
				GuestStatsService::recalculate_guest_stats( (int) $gid );
				++$count;
			}
		}

		return $this->success(
			array(
				'recalculated' => $count,
				/* translators: %d: number of recalculated guests */
				'message'      => sprintf( __( 'Recalculated statistics for %d guests.', 'modern-hotel-booking' ), $count ),
			)
		);
	}

	/**
	 * Export guests to CSV.
	 *
	 * @param WP_REST_Request $request
	 * @return void
	 */
	public function export_csv( $request ): void {
		if ( class_exists( ProRemoved::class ) ) {
			ProRemoved::export_csv( $request->get_params() );
			return;
		}

		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely constructed from $wpdb->prefix, admin-only export query
		$guests = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, first_name, last_name, email, phone, vip_status, admin_rating, total_stays, total_spend, created_at 
                 FROM {$wpdb->prefix}mhbo_guests 
                 ORDER BY id DESC LIMIT %d",
				5000
			),
			ARRAY_A
		);

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mhbo-guests-' . HotelTime::today() . '.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct streaming of CSV export to php://output
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		// UTF-8 BOM for Excel
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Direct streaming of CSV BOM to php://output
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array( 'ID', 'First Name', 'Last Name', 'Email', 'Phone', 'VIP Status', 'Rating', 'Total Stays', 'Total Spend', 'Registration Date' ) );

		if ( ! empty( $guests ) ) {
			foreach ( $guests as $g ) {
				// Formula injection protection
				$sanitized_first = $this->sanitize_csv_cell( $g['first_name'] ?? '' );
				$sanitized_last  = $this->sanitize_csv_cell( $g['last_name'] ?? '' );
				$sanitized_email = $this->sanitize_csv_cell( $g['email'] ?? '' );
				$sanitized_phone = $this->sanitize_csv_cell( $g['phone'] ?? '' );

				fputcsv(
					$out,
					array(
						(int) $g['id'],
						$sanitized_first,
						$sanitized_last,
						$sanitized_email,
						$sanitized_phone,
						$g['vip_status'] ?? 'none',
						(int) ( $g['admin_rating'] ?? 0 ),
						(int) ( $g['total_stays'] ?? 0 ),
						number_format( (float) ( $g['total_spend'] ?? 0 ), 2, '.', '' ),
						! empty( $g['created_at'] ) ? HotelTime::format_datetime( $g['created_at'] ) : '',
					)
				);
			}
		}

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct streaming of CSV export to php://output
		fclose( $out );
		exit;
	}

	/**
	 * Sanitize cell value to prevent CSV / Excel formula injection.
	 *
	 * @param string $value
	 * @return string
	 */
	private function sanitize_csv_cell( string $value ): string {
		$value = trim( $value );
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

}
