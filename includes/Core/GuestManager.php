<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Primary domain manager and service for Guest CRM profiles.
 *
 * @package MHBO\Core
 * @since   2.6.0
 */
class GuestManager {

	/**
	 * Master feature toggle gate.
	 *
	 * @return bool True if Guest CRM is enabled, false otherwise.
	 */
	public static function is_enabled(): bool {
		return (int) get_option( 'mhbo_guest_crm_enabled', 0 ) === 1;
	}

	/**
	 * Resolve a booking payload to an existing or newly created guest profile.
	 *
	 * @param array<string, mixed> $booking_data
	 * @return int Guest ID (or 0 if CRM is disabled or cannot be resolved)
	 */
	public static function resolve_and_link_guest( array $booking_data ): int {
		if ( ! self::is_enabled() ) {
			return 0;
		}

		return GuestIdentityResolver::resolve_and_link_guest( $booking_data );
	}

	/**
	 * Retrieve a guest profile by ID.
	 *
	 * @param int $id
	 * @return \stdClass|null
	 */
	public static function get_guest( int $id ): ?\stdClass {
		if ( $id <= 0 ) {
			return null;
		}

		global $wpdb;
		$cache_key = 'mhbo_guest_' . $id;
		$guest     = wp_cache_get( $cache_key, 'mhbo_guests' );

		if ( false === $guest ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$guest = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}mhbo_guests WHERE id = %d",
					$id
				)
			);

			if ( $guest ) {
				wp_cache_set( $cache_key, $guest, 'mhbo_guests', HOUR_IN_SECONDS );
			}
		}

		return ( $guest instanceof \stdClass ) ? $guest : null;
	}

	/**
	 * Update editable fields on a guest profile.
	 *
	 * @param int                  $id
	 * @param array<string, mixed> $data
	 * @return bool
	 */
	public static function update_guest( int $id, array $data ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;
		$update = array();

		if ( isset( $data['first_name'] ) ) {
			$update['first_name'] = sanitize_text_field( (string) $data['first_name'] );
		}
		if ( isset( $data['last_name'] ) ) {
			$update['last_name'] = sanitize_text_field( (string) $data['last_name'] );
		}
		if ( isset( $data['email'] ) ) {
			$email                      = sanitize_email( (string) $data['email'] );
			$update['email']            = $email;
			$update['email_normalized'] = GuestIdentityResolver::normalize_email( $email );
		}
		if ( isset( $data['phone'] ) ) {
			$phone                      = sanitize_text_field( (string) $data['phone'] );
			$update['phone']            = $phone;
			$update['phone_normalized'] = GuestIdentityResolver::normalize_phone( $phone );
		}
		if ( isset( $data['vip_status'] ) ) {
			$vip = sanitize_key( (string) $data['vip_status'] );
			if ( in_array( $vip, array( 'none', 'vip', 'repeat', 'high_spender', 'custom' ), true ) ) {
				$update['vip_status'] = $vip;
			}
		}
		if ( array_key_exists( 'admin_rating', $data ) ) {
			$rating = null !== $data['admin_rating'] ? absint( $data['admin_rating'] ) : null;
			if ( null === $rating || ( $rating >= 1 && $rating <= 5 ) ) {
				$update['admin_rating'] = $rating;
			}
		}
		if ( isset( $data['internal_notes'] ) ) {
			$update['internal_notes'] = sanitize_textarea_field( (string) $data['internal_notes'] );
		}
		if ( isset( $data['caution_flag'] ) ) {
			$update['caution_flag'] = (bool) $data['caution_flag'] ? 1 : 0;
		}
		if ( isset( $data['status'] ) ) {
			$status = sanitize_key( (string) $data['status'] );
			if ( in_array( $status, array( 'active', 'archived', 'merged', 'anonymized' ), true ) ) {
				$update['status'] = $status;
			}
		}
		if ( isset( $data['marketing_optin'] ) ) {
			$update['marketing_optin'] = (bool) $data['marketing_optin'] ? 1 : 0;
		}
		if ( isset( $data['marketing_groups'] ) ) {
			// Arrays of strings or JSON strings
			if ( is_array( $data['marketing_groups'] ) ) {
				$update['marketing_groups'] = wp_json_encode( array_map( 'sanitize_text_field', $data['marketing_groups'] ) );
			} else {
				$update['marketing_groups'] = sanitize_text_field( (string) $data['marketing_groups'] );
			}
		}

		if ( array() === $update ) {
			return true;
		}

		$update['updated_at'] = current_time( 'mysql' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$wpdb->prefix . 'mhbo_guests',
			$update,
			array( 'id' => $id )
		);

		wp_cache_delete( 'mhbo_guest_' . $id, 'mhbo_guests' );
		Cache::invalidate_table( 'guests' );

		return false !== $result;
	}

	/**
	 * Soft-delete / archive a guest profile.
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function archive_guest( int $id ): bool {
		return self::update_guest( $id, array( 'status' => 'archived' ) );
	}

	/**
	 * Permanently anonymize a guest profile (GDPR erasure).
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function anonymize_guest( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;
		$anonymized_label = sprintf( 'Anonymized Guest #%d', $id );
		$anonymized_email = sprintf( 'anonymized_%d@local.invalid', $id );

		$update = array(
			'first_name'       => $anonymized_label,
			'last_name'        => '',
			'email'            => $anonymized_email,
			'email_normalized' => $anonymized_email,
			'phone'            => '',
			'phone_normalized' => '',
			'vip_status'       => 'none',
			'admin_rating'     => null,
			'internal_notes'   => '',
			'caution_flag'     => 0,
			'status'           => 'anonymized',
			'marketing_optin'  => 0,
			'marketing_groups' => '',
			'updated_at'       => current_time( 'mysql' ),
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$wpdb->prefix . 'mhbo_guests',
			$update,
			array( 'id' => $id )
		);

		// phpcs:ignore Squiz.Commenting.BlockComment

wp_cache_delete( 'mhbo_guest_' . $id, 'mhbo_guests' );
		Cache::invalidate_table( 'guests' );

		return false !== $result;
	}

	/**
	 * Privacy-safe allowlisted projection of guest facts for AI Concierge prompts.
	 * Strictly excludes internal staff notes, caution flags, staff ratings, and billing details.
	 *
	 * @param int $id
	 * @return array<string, mixed>
	 */
	public static function get_concierge_context( int $id ): array {
		$guest = self::get_guest( $id );
		if ( ! $guest || 'active' !== ( $guest->status ?? '' ) ) {
			return array();
		}

		return array(
			'guest_id'    => (int) $guest->id,
			'first_name'  => (string) $guest->first_name,
			'vip_status'  => (string) $guest->vip_status,
			'total_stays' => (int) $guest->total_stays,
		);
	}

	/**
	 * Retrieve paginated booking history timeline for a guest.
	 *
	 * @param int    $guest_id
	 * @param int    $page
	 * @param int    $per_page
	 * @param string $search Optional search filter by booking ID, group token, or room
	 * @return array<string, mixed>
	 */
	public static function get_guest_history( int $guest_id, int $page = 1, int $per_page = 20, string $search = '' ): array {
		if ( $guest_id <= 0 ) {
			return array(
				'items' => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		global $wpdb;
		$offset = max( 0, ( $page - 1 ) * $per_page );

		// Self-healing: Check if the guest has unlinked or unassigned bookings matching their email or phone
		$guest = self::get_guest( $guest_id );
		if ( $guest ) {
			$email_raw  = strtolower( trim( (string) ( $guest->email ?? '' ) ) );
			$email_norm = ! empty( $guest->email_normalized ) ? (string) $guest->email_normalized : ( '' !== $email_raw ? GuestIdentityResolver::normalize_email( $email_raw ) : '' );
			$phone_raw  = trim( (string) ( $guest->phone ?? '' ) );
			$phone_norm = ! empty( $guest->phone_normalized ) ? (string) $guest->phone_normalized : ( '' !== $phone_raw ? GuestIdentityResolver::normalize_phone( $phone_raw ) : '' );

			$unlinked_ids = array();

			if ( '' !== $email_norm ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$email_matches = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}mhbo_bookings WHERE (LOWER(TRIM(customer_email)) = %s OR LOWER(TRIM(customer_email)) = %s) AND (guest_id IS NULL OR guest_id = 0)",
						$email_norm,
						$email_raw ?: $email_norm
					)
				);
				if ( ! empty( $email_matches ) ) {
					$unlinked_ids = array_merge( $unlinked_ids, $email_matches );
				}
			}

			if ( '' !== $phone_norm && strlen( $phone_norm ) >= 6 ) {
				$clean_digits = (string) preg_replace( '/[^\d]/', '', $phone_norm );
				if ( strlen( $clean_digits ) >= 6 ) {
					$search_suffix = '%' . substr( $clean_digits, -7 );
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$phone_candidate_rows = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT id, customer_name, customer_email FROM {$wpdb->prefix}mhbo_bookings WHERE REPLACE(REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE %s AND (guest_id IS NULL OR guest_id = 0)",
							$search_suffix
						)
					);
					if ( ! empty( $phone_candidate_rows ) ) {
						foreach ( $phone_candidate_rows as $candidate ) {
							$cand_email = strtolower( trim( (string) ( $candidate->customer_email ?? '' ) ) );
							// If booking has an email, ONLY link if it matches this guest's email
							if ( '' !== $cand_email ) {
								if ( $cand_email === $email_norm || $cand_email === $email_raw ) {
									$unlinked_ids[] = (int) $candidate->id;
								}
								continue;
							}
							// If booking has NO email, only link if customer name is compatible
							$parts      = explode( ' ', trim( (string) ( $candidate->customer_name ?? '' ) ), 2 );
							$cand_first = $parts[0] ?? '';
							$cand_last  = $parts[1] ?? '';
							if ( GuestIdentityResolver::is_compatible_identity( $guest, $cand_first, $cand_last ) ) {
								$unlinked_ids[] = (int) $candidate->id;
							}
						}
					}
				}
			}

			if ( ! empty( $unlinked_ids ) ) {
				$unlinked_ids = array_unique( array_map( 'intval', $unlinked_ids ) );
				foreach ( $unlinked_ids as $unlinked_id ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$wpdb->prefix . 'mhbo_bookings',
						array( 'guest_id' => $guest_id ),
						array( 'id' => $unlinked_id )
					);
					Cache::invalidate_booking( $unlinked_id );
				}
				GuestStatsService::recalculate_guest_stats( $guest_id );
			}
		}

		$search = trim( $search );

		if ( '' === $search ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings b
                     LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt_direct ON b.room_id = rt_direct.id
                     WHERE b.guest_id = %d",
					$guest_id
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT b.id, b.check_in, b.check_out, b.total_price, b.payment_amount, b.deposit_amount, b.deposit_received, b.payment_received, b.status, b.payment_status, b.payment_method, b.balance_link_token, b.balance_link_expires_at, b.balance_status, b.remaining_balance, b.created_at, b.room_id, b.multi_room_parent, b.is_multi_room, b.coupon_code, b.coupon_discount, r.room_number, COALESCE(NULLIF(rt.name, ''), NULLIF(rt_direct.name, ''), '') as room_type_name
                     FROM {$wpdb->prefix}mhbo_bookings b
                     LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt_direct ON b.room_id = rt_direct.id
                     WHERE b.guest_id = %d
                     ORDER BY b.check_in DESC
                     LIMIT %d, %d",
					$guest_id,
					$offset,
					$per_page
				)
			);
		} elseif ( is_numeric( $search ) ) {
			$search_int = (int) $search;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings b
                     LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt_direct ON b.room_id = rt_direct.id
                     WHERE b.guest_id = %d AND (b.id = %d OR r.room_number = %s)",
					$guest_id,
					$search_int,
					$search
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT b.id, b.check_in, b.check_out, b.total_price, b.payment_amount, b.deposit_amount, b.deposit_received, b.payment_received, b.status, b.payment_status, b.payment_method, b.balance_link_token, b.balance_link_expires_at, b.balance_status, b.remaining_balance, b.created_at, b.room_id, b.multi_room_parent, b.is_multi_room, b.coupon_code, b.coupon_discount, r.room_number, COALESCE(NULLIF(rt.name, ''), NULLIF(rt_direct.name, ''), '') as room_type_name
                     FROM {$wpdb->prefix}mhbo_bookings b
                     LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt_direct ON b.room_id = rt_direct.id
                     WHERE b.guest_id = %d AND (b.id = %d OR r.room_number = %s)
                     ORDER BY b.check_in DESC
                     LIMIT %d, %d",
					$guest_id,
					$search_int,
					$search,
					$offset,
					$per_page
				)
			);
		} else {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_bookings b
                     LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt_direct ON b.room_id = rt_direct.id
                     WHERE b.guest_id = %d AND (b.multi_room_parent LIKE %s OR r.room_number LIKE %s OR rt.name LIKE %s OR rt_direct.name LIKE %s)",
					$guest_id,
					$like,
					$like,
					$like,
					$like
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT b.id, b.check_in, b.check_out, b.total_price, b.payment_amount, b.deposit_amount, b.deposit_received, b.payment_received, b.status, b.payment_status, b.payment_method, b.balance_link_token, b.balance_link_expires_at, b.balance_status, b.remaining_balance, b.created_at, b.room_id, b.multi_room_parent, b.is_multi_room, b.coupon_code, b.coupon_discount, r.room_number, COALESCE(NULLIF(rt.name, ''), NULLIF(rt_direct.name, ''), '') as room_type_name
                     FROM {$wpdb->prefix}mhbo_bookings b
                     LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                     LEFT JOIN {$wpdb->prefix}mhbo_room_types rt_direct ON b.room_id = rt_direct.id
                     WHERE b.guest_id = %d AND (b.multi_room_parent LIKE %s OR r.room_number LIKE %s OR rt.name LIKE %s OR rt_direct.name LIKE %s)
                     ORDER BY b.check_in DESC
                     LIMIT %d, %d",
					$guest_id,
					$like,
					$like,
					$like,
					$like,
					$offset,
					$per_page
				)
			);
		}

		// Batch-load group reservation metadata for multi-room groups in this result page.
		$group_tokens = array();
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $r_item ) {
				$mrp = trim( (string) ( $r_item->multi_room_parent ?? '' ) );
				if ( '' !== $mrp ) {
					$group_tokens[ $mrp ] = true;
				}
			}
		}

		$groups_map = array();
		if ( ! empty( $group_tokens ) ) {
			$tokens_list        = array_keys( $group_tokens );
			$escaped_tokens_arr = array();
			foreach ( $tokens_list as $single_token ) {
				$escaped_tokens_arr[] = $wpdb->prepare( '%s', $single_token );
			}
			$escaped_tokens_str = implode( ',', $escaped_tokens_arr );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$all_group_siblings = $wpdb->get_results(
				"SELECT b.id, b.multi_room_parent, b.check_in, b.check_out, b.total_price, b.payment_amount, b.remaining_balance, b.status, b.payment_status, b.payment_received, b.room_id, r.room_number, COALESCE(NULLIF(rt.name, ''), '') as room_type_name
                 FROM {$wpdb->prefix}mhbo_bookings b
                 LEFT JOIN {$wpdb->prefix}mhbo_rooms r ON b.room_id = r.id
                 LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id
                 WHERE b.multi_room_parent IN ({$escaped_tokens_str})
                   AND b.status NOT IN ('cancelled', 'refunded', 'deleted')
                 ORDER BY b.id ASC"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! empty( $all_group_siblings ) ) {
				foreach ( $all_group_siblings as $gsib ) {
					$gtok = (string) ( $gsib->multi_room_parent ?? '' );
					if ( ! isset( $groups_map[ $gtok ] ) ) {
						$groups_map[ $gtok ] = array(
							'token'       => $gtok,
							'total'       => 0.0,
							'paid'        => 0.0,
							'remaining'   => 0.0,
							'is_paid'     => true,
							'rooms_count' => 0,
							'siblings'    => array(),
						);
					}
					$s_total = (float) ( $gsib->total_price ?? 0 );
					$s_paid  = (float) ( $gsib->payment_amount ?? 0 );
					$s_rem   = max( 0.0, (float) ( $gsib->remaining_balance ?? ( $s_total - $s_paid ) ) );
					if ( (bool) ( $gsib->payment_received ?? false ) || 'completed' === ( $gsib->payment_status ?? '' ) ) {
						$s_rem = 0.0;
					}

					$groups_map[ $gtok ]['total']     += $s_total;
					$groups_map[ $gtok ]['paid']      += $s_paid;
					$groups_map[ $gtok ]['remaining'] += $s_rem;
					if ( $s_rem > 0.005 ) {
						$groups_map[ $gtok ]['is_paid'] = false;
					}
					$groups_map[ $gtok ]['rooms_count'] += 1;
					$groups_map[ $gtok ]['siblings'][]   = array(
						'id'             => (int) $gsib->id,
						'room_number'    => (string) ( $gsib->room_number ?? '' ),
						'room_type_name' => class_exists( I18n::class ) ? I18n::decode( (string) ( $gsib->room_type_name ?? '' ) ) : (string) ( $gsib->room_type_name ?? '' ),
						'check_in'       => HotelTime::format_date( (string) $gsib->check_in ),
						'check_out'      => HotelTime::format_date( (string) $gsib->check_out ),
						'total_price'    => $s_total,
						'payment_amount' => $s_paid,
						'remaining'      => $s_rem,
						'status'         => (string) ( $gsib->status ?? 'pending' ),
						'edit_url'       => add_query_arg( '_wpnonce', wp_create_nonce( 'mhbo_edit_booking_' . (int) $gsib->id ), admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . (int) $gsib->id ) ),
					);
				}
			}
		}

		// Fetch review request sent timestamps from communication logs if table exists.
		$review_logs_map = array();
		if ( ! empty( $rows ) ) {
			$booking_ids = array_values(
				array_filter(
					array_unique(
						array_map(
							'intval',
							array_map(
								function ( $r ) {
									return (int) $r->id;
								},
								$rows 
							) 
						) 
					) 
				) 
			);
			$comm_table  = $wpdb->prefix . 'mhbo_communication_logs';
			if ( ! empty( $booking_ids ) && Cache::table_exists( $comm_table ) ) {
				$ids_string = implode( ',', array_map( 'intval', $booking_ids ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$review_logs = $wpdb->get_results(
					$wpdb->prepare(
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_string is strictly sanitized via array_map('intval').
						"SELECT booking_id, MAX(sent_at) as last_sent FROM {$wpdb->prefix}mhbo_communication_logs WHERE booking_id IN ({$ids_string}) AND type = %s AND status = %s GROUP BY booking_id",
						'review_request',
						'sent'
					)
				);
				if ( ! empty( $review_logs ) ) {
					foreach ( $review_logs as $rl ) {
						$review_logs_map[ (int) $rl->booking_id ] = $rl->last_sent;
					}
				}
			}
		}

		if ( ! empty( $rows ) ) {
			foreach ( $rows as &$row ) {
				$row->id                  = (int) $row->id;
				$decoded_rt               = class_exists( I18n::class ) ? I18n::decode( (string) ( $row->room_type_name ?? '' ) ) : (string) ( $row->room_type_name ?? '' );
				$row->room_type_name      = '' !== $decoded_rt ? $decoded_rt : (string) ( $row->room_type_name ?? '' );
				$row->total_price         = (float) ( $row->total_price ?? 0 );
				$row->payment_amount      = (float) ( $row->payment_amount ?? 0 );
				$row->coupon_code         = (string) ( $row->coupon_code ?? '' );
				$row->coupon_discount     = (float) ( $row->coupon_discount ?? 0 );
				$row->check_in_formatted  = HotelTime::format_date( $row->check_in );
				$row->check_out_formatted = HotelTime::format_date( $row->check_out );
				$row->edit_url            = add_query_arg( '_wpnonce', wp_create_nonce( 'mhbo_edit_booking_' . (int) $row->id ), admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . (int) $row->id ) );
				
				$row->balance_email_url = add_query_arg( '_wpnonce', wp_create_nonce( 'mhbo_balance_' . (int) $row->id ), admin_url( 'admin.php?page=mhbo-bookings&action=email_balance_link&id=' . (int) $row->id ) );
				
				// Group reservation enrichment.
				$m_parent = trim( (string) ( $row->multi_room_parent ?? '' ) );
				if ( '' !== $m_parent && isset( $groups_map[ $m_parent ] ) && $groups_map[ $m_parent ]['rooms_count'] > 1 ) {
					$grp                    = $groups_map[ $m_parent ];
					$row->is_group_booking  = true;
					$row->group_parent      = $m_parent;
					$row->group_rooms_count = $grp['rooms_count'];
					$row->group_total       = number_format( $grp['total'], 2, '.', '' );
					$row->group_paid        = number_format( $grp['paid'], 2, '.', '' );
					$row->group_remaining   = number_format( $grp['remaining'], 2, '.', '' );
					$row->group_is_paid     = $grp['is_paid'];
					$row->group_siblings    = $grp['siblings'];
				} else {
					$row->is_group_booking  = false;
					$row->group_parent      = '';
					$row->group_rooms_count = 1;
					$row->group_total       = number_format( $row->total_price, 2, '.', '' );
					$row->group_paid        = number_format( $row->payment_amount, 2, '.', '' );
					$row->group_remaining   = '0.00';
					$row->group_is_paid     = true;
					$row->group_siblings    = array();
				}

				// Determine unpaid balance status.
				$amts       = class_exists( Pricing::class ) ? Pricing::get_booking_payment_amounts( $row ) : null;
				$has_unpaid = false;
				$remaining  = 0.0;

				if ( $amts ) {
					$row->payment_amount = (float) $amts['received']->toDecimal();
					$has_unpaid          = ! $amts['is_paid'] && ! in_array( $row->status, array( 'cancelled', 'refunded' ), true );
					$remaining           = (float) $amts['remaining']->toDecimal();
				} else {
					$remaining = (float) max( 0, (float) ( $row->total_price ?? 0 ) - (float) ( $row->payment_amount ?? 0 ) );
					if ( $remaining > 0.0 && ! in_array( $row->status, array( 'cancelled', 'refunded' ), true ) && ! (bool) ( $row->payment_received ?? 0 ) ) {
						$has_unpaid = true;
					} else {
						$remaining = 0.0;
					}
				}

				// Defensive fallback: If total exceeds payment amount on active bookings without collected balance.
				if ( (float) ( $row->total_price ?? 0 ) > ( (float) $row->payment_amount + 0.005 ) && ! in_array( $row->status, array( 'cancelled', 'refunded' ), true ) ) {
					if ( empty( $row->balance_collected_via ) && ( empty( $row->balance_payment_amount ) || (float) $row->balance_payment_amount <= 0 ) ) {
						$has_unpaid = true;
						$remaining  = max( $remaining, (float) ( $row->total_price ?? 0 ) - (float) $row->payment_amount );
					}
				}
				
				$row->has_unpaid_balance             = $has_unpaid;
				$row->remaining_amount               = number_format( (float) $remaining, 2, '.', '' );
				$row->is_balance_link_expired        = ! empty( $row->balance_link_expires_at ) && strtotime( (string) $row->balance_link_expires_at ) < time();
				$row->due_now                        = $amts ? $amts['due_now']->toDecimal() : $row->remaining_amount;
				$row->due_type                       = $amts ? $amts['due_type'] : 'full';
				$row->is_overdue                     = $amts ? (bool) $amts['is_overdue'] : false;
				$row->is_urgent_unpaid               = $amts ? (bool) $amts['is_urgent_unpaid'] : false;
				$row->effective_payment_status       = $amts ? $amts['effective_payment_status'] : ( $row->payment_status ?? 'pending' );
				$row->effective_payment_status_label = $amts ? $amts['effective_payment_status_label'] : I18n::translate_payment_status( $row->payment_status ?? 'pending' );
				$row->payment_ledger                 = class_exists( Pricing::class ) ? Pricing::get_payment_ledger( $row ) : array();
				
				// Build public balance payment URL if token is available or unpaid.
				$row->balance_payment_url      = '';
				$row->room_balance_payment_url = '';
				if ( $row->has_unpaid_balance && class_exists( 'ProRemoved' ) ) {
					$row->balance_payment_url = ProRemoved::get_or_create_magic_url( (int) $row->id );
				} elseif ( ! empty( $row->balance_link_token ) && class_exists( 'ProRemoved' ) ) {
					$row->balance_payment_url = ProRemoved::get_balance_payment_url( (string) $row->balance_link_token );
				}
				if ( '' !== $row->balance_payment_url && ! empty( $row->is_multi_room ) ) {
					$row->room_balance_payment_url = add_query_arg( 'pay_scope', 'room', $row->balance_payment_url );
				}
			}
			unset( $row );
		}

		$pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;

		return array(
			'items'    => ! empty( $rows ) ? $rows : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => $pages,
		);
	}

	/**
	 * Retrieve active guest profiles matching any of the provided email addresses.
	 *
	 * Matches against both raw email and normalized email.
	 *
	 * @param string[] $emails List of raw or normalized email addresses.
	 * @return \stdClass[] Array of guest database records.
	 */
	public static function get_active_guests_by_emails( array $emails ): array {
		if ( empty( $emails ) ) {
			return array();
		}

		global $wpdb;

		// Deduplicate and normalize emails.
		$sanitized_emails = array();
		foreach ( $emails as $email ) {
			$email_clean = strtolower( trim( (string) $email ) );
			if ( '' !== $email_clean && is_email( $email_clean ) ) {
				$sanitized_emails[ $email_clean ] = true;
			}
		}

		if ( empty( $sanitized_emails ) ) {
			return array();
		}

		$email_list   = array_keys( $sanitized_emails );
		$count        = count( $email_list );
		$in_clause    = implode( ',', array_fill( 0, $count, '%s' ) );
		$prepare_args = array_merge( $email_list, $email_list );

		$all_args = array_merge( $prepare_args, array( 'active' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in_clause is a deterministic string of '%s' tokens built via array_fill; no user input.
			'SELECT id, email, email_normalized, vip_status, caution_flag FROM ' . $wpdb->prefix . 'mhbo_guests WHERE (email_normalized IN (' . $in_clause . ') OR email IN (' . $in_clause . ')) AND status = %s ORDER BY id ASC',
			...$all_args
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $query );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Retrieve a list of recent active guests for front-desk reservation selectors.
	 *
	 * @param int $limit Maximum number of guests to retrieve.
	 * @return \stdClass[]
	 */
	public static function get_recent_active_guests( int $limit = 100 ): array {
		if ( ! self::is_enabled() ) {
			return array();
		}

		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$guests = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, first_name, last_name, email, phone, vip_status, caution_flag FROM {$wpdb->prefix}mhbo_guests WHERE status = 'active' ORDER BY updated_at DESC, id DESC LIMIT %d",
				$limit
			)
		);

		return is_array( $guests ) ? $guests : array();
	}

}
