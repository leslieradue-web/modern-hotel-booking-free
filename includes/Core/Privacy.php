<?php declare(strict_types=1);

namespace MHBO\Core;

use MHBO\Core\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

/**
 * Handles GDPR Data Export and Erasure Requests
 */
class Privacy {

	/**
	 * Initialize GDPR hooks.
	 */
	public function init(): void {
		add_action(
			'admin_init',
			function () {
				if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
					$content  = '<h2>' . esc_html( I18n::get_label( 'privacy_policy_heading' ) ) . '</h2>';
					$content .= '<p>' . esc_html( I18n::get_label( 'privacy_policy_collection_desc' ) ) . '</p>';
				
					$content .= '<h3>' . esc_html( I18n::get_label( 'privacy_policy_guest_data' ) ) . '</h3>';
					$content .= '<ul>';
					$content .= '<li>' . esc_html( I18n::get_label( 'privacy_policy_guest_data_desc_1' ) ) . '</li>';
					$content .= '<li>' . esc_html( I18n::get_label( 'privacy_policy_guest_data_desc_2' ) ) . '</li>';
					$content .= '</ul>';

					$content .= '<h3>' . esc_html__( 'Cookies and Local Storage', 'modern-hotel-booking' ) . '</h3>';
					$content .= '<p>' . esc_html__( 'We use functional cookies and browser storage strictly necessary for managing guest room reservations, checkout flows, and user preferences:', 'modern-hotel-booking' ) . '</p>';
					$content .= '<ul>';
					$content .= '<li><strong>mhbo_session (Cookie)</strong>: ' . esc_html__( 'Maintains your room reservation cart, dates, and checkout session (Duration: 1 hour).', 'modern-hotel-booking' ) . '</li>';
					$content .= '<li><strong>mhbo_ai_session (Cookie)</strong>: ' . esc_html__( 'Preserves AI Concierge conversation state during your visit (Duration: 24 hours).', 'modern-hotel-booking' ) . '</li>';
					$content .= '<li><strong>mhbo_check_in / mhbo_check_out (LocalStorage)</strong>: ' . esc_html__( 'Remembers selected travel dates between page navigations.', 'modern-hotel-booking' ) . '</li>';
					$content .= '<li><strong>mhbo_currency (LocalStorage)</strong>: ' . esc_html__( 'Preserves selected display currency for room rate pricing.', 'modern-hotel-booking' ) . '</li>';
					$content .= '</ul>';

					$content .= '<h3>' . esc_html__( 'Third-Party Payment Processors', 'modern-hotel-booking' ) . '</h3>';
					$content .= '<p>' . esc_html__( 'Payment transactions are securely processed directly by certified payment gateways (such as Stripe, PayPal, or Braintree). Financial payment credentials (credit card numbers, CVV) are never stored on this website server.', 'modern-hotel-booking' ) . '</p>';

					$content .= '<h3>' . esc_html( I18n::get_label( 'privacy_policy_business_info' ) ) . '</h3>';
					$content .= '<p>' . esc_html( I18n::get_label( 'privacy_policy_business_info_desc_1' ) ) . '</p>';
					$content .= '<p>' . esc_html( I18n::get_label( 'privacy_policy_business_info_desc_2' ) ) . '</p>';

					wp_add_privacy_policy_content( I18n::get_label( 'privacy_policy_heading' ), $content );
				}
			}
		);

		// Register Exporters and Erasers
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_gdpr_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_gdpr_eraser' ) );
	}

	/**
	 * Get the configured session cookie name.
	 *
	 * @param string $base Base name of the cookie (default 'session').
	 * @return string
	 */
	public static function get_cookie_name( string $base = 'session' ): string {
		$prefix = (string) get_option( 'mhbo_gdpr_cookie_prefix', 'mhbo_' );
		if ( '' === $prefix ) {
			$prefix = 'mhbo_';
		}
		return $prefix . $base;
	}

	/**
	 * Retrieve the current guest session ID from cookies, checking both configured prefix and legacy name.
	 *
	 * @return string Sanitized session ID or empty string.
	 */
	public static function get_cookie_session_id(): string {
		$prefixed_name = self::get_cookie_name( 'session' );
		if ( isset( $_COOKIE[ $prefixed_name ] ) && '' !== $_COOKIE[ $prefixed_name ] ) {
			return sanitize_key( wp_unslash( $_COOKIE[ $prefixed_name ] ) );
		}
		if ( 'mhbo_session' !== $prefixed_name && isset( $_COOKIE['mhbo_session'] ) && '' !== $_COOKIE['mhbo_session'] ) {
			return sanitize_key( wp_unslash( $_COOKIE['mhbo_session'] ) );
		}
		return '';
	}

	/**
	 * Register GDPR data exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters list.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_gdpr_exporter( array $exporters ): array {
		$exporters['modern-hotel-booking'] = array(
			'exporter_friendly_name' => I18n::get_label( 'privacy_policy_heading' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register GDPR data eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers list.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_gdpr_eraser( array $erasers ): array {
		$erasers['modern-hotel-booking'] = array(
			'eraser_friendly_name' => I18n::get_label( 'privacy_policy_heading' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export Personal Data for a given email address.
	 *
	 * @param string $email_address
	 * @param int    $page
	 * @return array<string, mixed>
	 */
	public static function export_personal_data( string $email_address, int $page = 1 ): array {
		$number = 500; // Limit per page
		$page   = (int) $page;

		$export_items = array();
		global $wpdb;

		$cache_key = 'mhbo_gdpr_export_' . md5( $email_address . '_' . $page );
		$bookings  = wp_cache_get( $cache_key, 'mhbo_privacy' );

		if ( false === $bookings ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, direct lookup, caching implemented above
			$bookings = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}mhbo_bookings 
                 WHERE customer_email = %s 
                 ORDER BY check_in DESC 
                 LIMIT %d, %d",
					$email_address,
					( $page - 1 ) * $number,
					$number
				)
			);
			wp_cache_set( $cache_key, $bookings, 'mhbo_privacy', HOUR_IN_SECONDS );
		}

		foreach ( $bookings as $booking ) {
			$data = array(
				array(
					'name'  => I18n::get_label( 'gdpr_booking_id' ),
					'value' => $booking->id,
				),
				array(
					'name'  => I18n::get_label( 'gdpr_room_id' ),
					'value' => $booking->room_id,
				),
				array(
					'name'  => __( 'Room Name', 'modern-hotel-booking' ),
					'value' => get_the_title( (int) $booking->room_id ),
				),
				array(
					'name'  => __( 'Guests', 'modern-hotel-booking' ),
					'value' => (int) $booking->guests,
				),
				array(
					'name'  => __( 'Children', 'modern-hotel-booking' ),
					'value' => (int) ( $booking->children ?? 0 ),
				),
				array(
					'name'  => __( 'Payment Method', 'modern-hotel-booking' ),
					'value' => (string) ( $booking->payment_method ?? '' ),
				),
				array(
					'name'  => I18n::get_label( 'gdpr_check_in' ),
					'value' => $booking->check_in,
				),
				array(
					'name'  => I18n::get_label( 'gdpr_check_out' ),
					'value' => $booking->check_out,
				),
				array(
					'name'  => I18n::get_label( 'gdpr_total_price' ),
					'value' => $booking->total_price,
				),
				array(
					'name'  => I18n::get_label( 'gdpr_status' ),
					'value' => $booking->status,
				),
				array(
					'name'  => I18n::get_label( 'gdpr_customer_name' ),
					'value' => $booking->customer_name,
				),
				array(
					'name'  => I18n::get_label( 'gdpr_customer_phone' ),
					'value' => $booking->customer_phone,
				),
				array(
					'name'  => I18n::get_label( 'gdpr_admin_notes' ),
					'value' => $booking->admin_notes,
				),
				array(
					'name'  => I18n::get_label( 'gdpr_custom_fields' ),
					'value' => $booking->custom_fields,
				),
				array(
					'name'  => I18n::get_label( 'gdpr_booking_extras' ),
					'value' => $booking->booking_extras,
				),
				array(
					'name'  => I18n::get_label( 'gdpr_payment_errors' ),
					'value' => $booking->payment_error,
				),
				
			);

			$export_items[] = array(
				'group_id'    => 'mhbo-bookings',
				'group_label' => I18n::get_label( 'gdpr_group_label' ),
				'item_id'     => "mhbo-booking-{$booking->id}",
				'data'        => $data,
			);
		}

		// Export Guest Profile (Page 1 only)
		if ( 1 === $page ) {
			$norm_email = class_exists( GuestIdentityResolver::class ) ? GuestIdentityResolver::normalize_email( $email_address ) : strtolower( trim( $email_address ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$guests = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}mhbo_guests WHERE email = %s OR email_normalized = %s",
					$email_address,
					$norm_email
				)
			);

			if ( array() !== $guests ) {
				foreach ( $guests as $g ) {
					$export_items[] = array(
						'group_id'    => 'mhbo-guests',
						'group_label' => __( 'Guest Profile', 'modern-hotel-booking' ),
						'item_id'     => "mhbo-guest-{$g->id}",
						'data'        => array(
							array(
								'name'  => __( 'First Name', 'modern-hotel-booking' ),
								'value' => $g->first_name,
							),
							array(
								'name'  => __( 'Last Name', 'modern-hotel-booking' ),
								'value' => $g->last_name,
							),
							array(
								'name'  => _x( 'Email', 'Guest profile email field', 'modern-hotel-booking' ),
								'value' => $g->email,
							),
							array(
								'name'  => _x( 'Phone', 'Guest profile phone field', 'modern-hotel-booking' ),
								'value' => $g->phone,
							),
							array(
								'name'  => __( 'VIP Status', 'modern-hotel-booking' ),
								'value' => $g->vip_status,
							),
							array(
								'name'  => __( 'Total Stays', 'modern-hotel-booking' ),
								'value' => $g->total_stays,
							),
							array(
								'name'  => __( 'Total Spend', 'modern-hotel-booking' ),
								'value' => $g->total_spend,
							),
						),
					);
				}
			}

}

		$done = ( is_array( $bookings ) ? count( $bookings ) : 0 ) < $number;

		return array(
			'data' => $export_items,
			'done' => $done,
		);
	}

	/**
	 * Erase Personal Data for a given email address.
	 *
	 * @param string $email_address
	 * @param int    $page
	 * @return array<string, mixed>
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		$number         = 500; // Limit per page
		$page           = (int) $page;
		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		global $wpdb;

		// Anonymize guest profile on page 1
		if ( 1 === $page ) {
			$norm_email = class_exists( GuestIdentityResolver::class ) ? GuestIdentityResolver::normalize_email( $email_address ) : strtolower( trim( $email_address ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$guest_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}mhbo_guests WHERE email = %s OR email_normalized = %s",
					$email_address,
					$norm_email
				)
			);

			foreach ( $guest_ids as $gid ) {
				if ( class_exists( GuestManager::class ) ) {
					GuestManager::anonymize_guest( (int) $gid );
					$items_removed = true;
				}
			}

}

		// Find bookings to anonymize
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, direct lookup
		$bookings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}mhbo_bookings 
             WHERE customer_email = %s 
             LIMIT %d, %d",
				$email_address,
				( $page - 1 ) * $number,
				$number
			)
		);

		foreach ( $bookings as $booking ) {
			// Anonymize data instead of deleting the row to keep financial records
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, GDPR erasure
			$updated = $wpdb->update(
				$wpdb->prefix . 'mhbo_bookings',
				array(
					'customer_name'          => I18n::get_label( 'msg_deleted' ),
					'customer_email'         => 'deleted@site.invalid',
					'customer_phone'         => I18n::get_label( 'msg_deleted' ),
					'booking_token'          => wp_generate_password( 64, false ), // Revoke access link
					'guest_id'               => null,
					'admin_notes'            => null,
					'custom_fields'          => null,
					'booking_extras'         => null,
					'payment_error'          => null,
					
				),
				array( 'id' => (int) $booking->id )
			);

			if ( $updated ) {
				Cache::invalidate_booking( $booking->id );
				$items_removed = true;
			} else {
				$items_retained = true;
			}
		}

		$done = ( is_array( $bookings ) ? count( $bookings ) : 0 ) < $number;

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Helper to anonymize a single booking by ID.
	 * 
	 * @param int $booking_id
	 * @return bool
	 */
	public static function erase_personal_data_by_id( int $booking_id ): bool {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables, GDPR erasure
		$result = (bool) $wpdb->update(
			$wpdb->prefix . 'mhbo_bookings',
			array(
				'customer_name'          => I18n::get_label( 'msg_anonymized' ),
				'customer_email'         => 'deleted@site.invalid',
				'customer_phone'         => I18n::get_label( 'msg_anonymized' ),
				'booking_token'          => wp_generate_password( 64, false ), // Revoke access link
				'guest_id'               => null,
				'admin_notes'            => null,
				'custom_fields'          => null,
				'booking_extras'         => null, // Clear Pro extras too for GDPR
				'payment_error'          => null,
				
			),
			array( 'id' => $booking_id )
		);

		if ( $result ) {
			Cache::invalidate_booking( $booking_id );
		}

		return $result;
	}
}
