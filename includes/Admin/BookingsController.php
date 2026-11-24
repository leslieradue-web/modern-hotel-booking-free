<?php declare(strict_types=1);

namespace MHBO\Admin;

use MHBO\Core\BookingProcessor;
use MHBO\Core\Cache;
use MHBO\Core\Capabilities;
use MHBO\Core\Email;
use MHBO\Core\GuestAsyncTasks;
use MHBO\Core\GuestIdentityResolver;
use MHBO\Core\GuestManager;
use MHBO\Core\GuestMigration;
use MHBO\Core\HotelTime;
use MHBO\Core\I18n;

use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Security;
use MHBO\Core\Tax;
use MHBO\Core\Tier;
use MHBO\Database\Queries\Booking_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bookings Controller
 *
 * Orchestrates reservation listing, edit/create dispatch, folio tender
 * submissions, calendar event projection, and workstation rendering.
 */
class BookingsController {

	/**
	 * Lazy-initialized booking workstation view renderer.
	 *
	 * @var BookingWorkstationView|null
	 */
	private ?BookingWorkstationView $workstation_view = null;

	/**
	 * Get the booking workstation view instance.
	 *
	 * @return BookingWorkstationView
	 */
	private function get_workstation_view(): BookingWorkstationView {
		if ( null === $this->workstation_view ) {
			$this->workstation_view = new BookingWorkstationView();
		}
		return $this->workstation_view;
	}

	public function display_bookings_page(): void {
		if (
			! Capabilities::current_user_can( Capabilities::VIEW_BOOKINGS )
			&& ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO )
			&& ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS )
		) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		global $wpdb;
		$tb = $wpdb->prefix . 'mhbo_bookings';

		// Self-heal: ensure pending bookings with verified full payment progress to confirmed (Law 46)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: Using %i for safe table placeholder.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s WHERE status = %s AND (payment_received = 1 OR payment_status = %s)',
				$tb,
				'confirmed',
				'pending',
				'completed'
			)
		);

		$edit_mode = false;
		$add_mode  = false;
		$edit_data = null;

		// Rule 11: Extract and sanitize all inputs at start
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		// GET Actions
		if ( $action ) {
			if ( 'add' === $action ) {
				if ( ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
				}
				$add_mode = true;
			} elseif ( 'sync_crm_guests' === $action ) {
				if ( ! Capabilities::current_user_can( Capabilities::MANAGE_GUESTS ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
				}
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_sync_crm_guests' ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
				}
				if ( class_exists( GuestMigration::class ) ) {
					$migrated_result = GuestMigration::migrate_batch( 0, 1000 );
					$migrated_count  = (int) ( $migrated_result['processed'] ?? 0 );
					/* translators: %d: number of synced reservations */
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Successfully synced %d historical reservation(s) into your Guest CRM.', 'modern-hotel-booking' ), $migrated_count ) ) . '</p></div>';
				}
				
			} elseif ( $id > 0 ) {
				$verified_booking_action = false;
				if ( 'edit' === $action || 'view' === $action ) {
					if ( ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
					}
					// Opening the reservation editor is a read-only GET view (matching WP core post.php?post=ID&action=edit),
					// enabling admin email, iCal, and bookmark deep links. All state mutations enforce check_admin_referer() / nonces.
					$edit_mode = true;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$edit_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $id ) );
				} elseif ( 'confirm' === $action ) {
					if ( ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
					}
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_confirm_booking_' . $id ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
					$verified_booking_action = true;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read room_id for cache invalidation.
					$confirm_room_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT room_id FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $id ) );
					$wpdb->update( $tb, array( 'status' => 'confirmed' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
					Cache::invalidate_booking( $id, $confirm_room_id );
					Pricing::clear_payment_amounts_cache();
					do_action( 'mhbo_booking_confirmed', $id );
					do_action( 'mhbo_booking_status_changed', $id, 'confirmed' );
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_booking_confirmed_email' ) ) . '</p></div>';
				} elseif ( 'cancel' === $action ) {
					if ( ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
					}
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_cancel_booking_' . $id ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
					$verified_booking_action = true;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read room_id and custom_fields to clear pending portal requests cleanly.
					$cancel_meta     = $wpdb->get_row( $wpdb->prepare( "SELECT room_id, custom_fields FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $id ) );
					$cancel_room_id  = $cancel_meta ? (int) ( $cancel_meta->room_id ?? 0 ) : 0;
					$cancel_update   = array( 'status' => 'cancelled' );
					if ( $cancel_meta && ! empty( $cancel_meta->custom_fields ) ) {
						$cancel_cf = json_decode( (string) $cancel_meta->custom_fields, true );
						if ( is_array( $cancel_cf ) && ( isset( $cancel_cf['_mhbo_cancellation_requested'] ) || isset( $cancel_cf['_mhbo_modification_requested'] ) ) ) {
							unset( $cancel_cf['_mhbo_cancellation_requested'], $cancel_cf['_mhbo_modification_requested'] );
							$cancel_update['custom_fields'] = array() !== $cancel_cf ? wp_json_encode( $cancel_cf ) : null;
						}
					}
					$wpdb->update( $tb, $cancel_update, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
					Cache::invalidate_booking( $id, $cancel_room_id );
					Pricing::clear_payment_amounts_cache();
					do_action( 'mhbo_booking_cancelled', $id );
					do_action( 'mhbo_booking_status_changed', $id, 'cancelled' );
					echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_booking_cancelled' ) ) . '</p></div>';
				} elseif ( 'delete' === $action ) {
					if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
					}
					if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_delete_booking_' . $id ) ) {
						wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
					$del_meta = $wpdb->get_row( $wpdb->prepare( "SELECT room_id, guest_id FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $id ) );
					$wpdb->delete( $tb, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
					Cache::invalidate_booking( $id, (int) ( $del_meta->room_id ?? 0 ) );
					do_action( 'mhbo_booking_deleted', $id );
					if ( ! empty( $del_meta->guest_id ) && class_exists( GuestAsyncTasks::class ) ) {
						GuestAsyncTasks::queue_stats_recalculation( (int) $del_meta->guest_id );
					}
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_booking_deleted' ) ) . '</p></div>';

}
				if ( $verified_booking_action
					&& isset( $_GET['return'] )
					&& 'edit' === sanitize_key( wp_unslash( $_GET['return'] ) )
					&& ( Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) || Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) )
				) {
					$edit_mode = true;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$edit_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $id ) );
				}
			}
		}

		// Handle bulk actions (Mass Delete)
		if ( isset( $_POST['bulk_action'] ) || ( isset( $_POST['action'] ) && 'bulk_delete' === sanitize_key( wp_unslash( $_POST['action'] ) ) ) ) {
			$posted_bulk_action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? ( $_POST['action'] ?? '' ) ) );
			if ( 'bulk_delete' === $posted_bulk_action ) {
				if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
				}
				check_admin_referer( 'mhbo_bulk_bookings_action', 'mhbo_bulk_nonce' );

                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$raw_booking_ids = isset( $_POST['booking_ids'] ) && is_array( $_POST['booking_ids'] ) ? (array) wp_unslash( $_POST['booking_ids'] ) : array();
				$booking_ids     = array_values( array_filter( array_map( 'absint', $raw_booking_ids ) ) );

				if ( empty( $booking_ids ) ) {
					echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_no_bookings_selected' ) ) . '</p></div>';
				} else {
					$in_clause = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );
					// Fetch room_ids and guest_ids for proper cache invalidation and CRM synchronization
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in_clause is a deterministic '%d' token string via array_fill.
					$select_sql = $wpdb->prepare( 'SELECT id, room_id, guest_id FROM ' . $wpdb->prefix . 'mhbo_bookings WHERE id IN (' . $in_clause . ')', ...$booking_ids );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					$deleted_rows_meta = $wpdb->get_results( $select_sql );

					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in_clause is a deterministic '%d' token string via array_fill.
					$delete_sql = $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'mhbo_bookings WHERE id IN (' . $in_clause . ')', ...$booking_ids );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
					$deleted_count = (int) $wpdb->query( $delete_sql );

					$affected_guest_ids = array();
					if ( is_array( $deleted_rows_meta ) ) {
						foreach ( $deleted_rows_meta as $row_meta ) {
							$bid = (int) $row_meta->id;
							$rid = (int) ( $row_meta->room_id ?? 0 );
							Cache::invalidate_booking( $bid, $rid );
							do_action( 'mhbo_booking_deleted', $bid );
							if ( ! empty( $row_meta->guest_id ) ) {
								$affected_guest_ids[] = (int) $row_meta->guest_id;
							}
						}
					} else {
						Cache::invalidate_booking();
					}

					if ( ! empty( $affected_guest_ids ) && class_exists( GuestAsyncTasks::class ) ) {
						foreach ( array_unique( $affected_guest_ids ) as $gid ) {
							if ( $gid > 0 ) {
								GuestAsyncTasks::queue_stats_recalculation( $gid );
							}
						}
					}

					// Invalidate dashboard statistics transients
					$today_date = wp_date( 'Y-m-d' );
					delete_transient( 'mhbo_widget_total_bookings' );
					delete_transient( 'mhbo_widget_pending_bookings' );
					delete_transient( 'mhbo_widget_today_bookings_' . $today_date );
					delete_transient( 'mhbo_dashboard_total_bookings' );
					delete_transient( 'mhbo_dashboard_pending_bookings' );
					delete_transient( 'mhbo_dashboard_stats_' . $today_date );
					delete_transient( 'mhbo_dashboard_ops_v260_' . $today_date );

					/* translators: %d: number of deleted bookings */
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( I18n::get_label( 'msg_bulk_bookings_deleted' ), (int) $deleted_count ) ) . '</p></div>';
				}
			}
		}

		// Shared map for extras logic
		$available_extras = get_option( 'mhbo_pro_extras', array() );
		$extras_map       = array();
		foreach ( $available_extras as $ex ) {
			$extras_map[ $ex['id'] ] = $ex;
		}

		// Handle manual booking submission
		if ( isset( $_POST['submit_manual_booking'] ) ) {
			if ( ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
			}
			check_admin_referer( 'mhbo_add_manual_booking' );

			$can_override_tax = false;
			
			$can_override_pricing = Capabilities::can_override_booking_pricing();
			$can_process_payments = Capabilities::can_process_payments();

			// Rule 11: Extract and sanitize manual booking inputs
			$customer_name    = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
			$customer_email   = sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) );
			$customer_phone   = sanitize_text_field( wp_unslash( $_POST['customer_phone'] ?? '' ) );
			$room_id          = absint( wp_unslash( $_POST['room_id'] ?? 0 ) );
			$check_in         = sanitize_text_field( wp_unslash( $_POST['check_in'] ?? '' ) );
			$check_out        = sanitize_text_field( wp_unslash( $_POST['check_out'] ?? '' ) );
			$guests           = absint( wp_unslash( $_POST['guests'] ?? 1 ) );
			$children_count   = absint( wp_unslash( $_POST['children'] ?? 0 ) );
			$child_ages       = isset( $_POST['child_ages'] ) && is_array( $_POST['child_ages'] ) ? Pricing::sanitize_child_ages( map_deep( wp_unslash( $_POST['child_ages'] ), 'sanitize_text_field' ) ) : array();
			$total_price      = $can_override_pricing ? floatval( wp_unslash( $_POST['total_price'] ?? 0 ) ) : 0.0;
			$discount_amount  = $can_override_pricing ? floatval( wp_unslash( $_POST['discount_amount'] ?? 0 ) ) : 0.0;
			$coupon_code      = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );
			$deposit_amount   = 0.0;
			$deposit_received = 0;
			
			$payment_received = ( $can_process_payments && isset( $_POST['payment_received'] ) && sanitize_text_field( wp_unslash( $_POST['payment_received'] ) ) === '1' );
			$post_status      = sanitize_key( wp_unslash( $_POST['status'] ?? 'pending' ) );
			$admin_notes      = sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) );
			$booking_language = sanitize_key( wp_unslash( $_POST['booking_language'] ?? 'en' ) );
			$payment_method   = $can_process_payments ? sanitize_key( wp_unslash( $_POST['payment_method'] ?? 'arrival' ) ) : 'arrival';
			$posted_tax_mode  = '';
			$manual_tax_rates = null;
			
			$mhbo_custom      = isset( $_POST['mhbo_custom'] ) && is_array( $_POST['mhbo_custom'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_custom'] ) ) : array();
			$mhbo_extras_raw  = isset( $_POST['mhbo_extras'] ) && is_array( $_POST['mhbo_extras'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_extras'] ) ) : array();

			// Prepare extras for BookingProcessor (simple id => qty map)
			$post_extras = array();
			foreach ( $mhbo_extras_raw as $ex_id => $val ) {
				$qty = ( isset( $extras_map[ $ex_id ] ) && $extras_map[ $ex_id ]['control_type'] === 'quantity' ) ? absint( $val ) : ( $val === '1' ? 1 : 0 );
				if ( $qty > 0 ) {
					$post_extras[ $ex_id ] = $qty;
				}
			}

			$raw_payment_amount  = ( $can_process_payments && isset( $_POST['payment_amount'] ) ) ? sanitize_text_field( (string) wp_unslash( $_POST['payment_amount'] ) ) : '';
			$has_initial_payment = $can_process_payments && (
				$payment_received
				|| ( '' !== $raw_payment_amount && floatval( $raw_payment_amount ) > 0 )
				|| ( 0 !== $deposit_received && ( $deposit_amount > 0 || ( Tier::is_feature_enabled( 'deposits' ) && (bool) get_option( 'mhbo_deposits_enabled', 0 ) ) ) )
			);
			if ( $has_initial_payment && 'pending' === $post_status ) {
				$post_status = 'confirmed';
			}

			$process_payload = array(
				'customer_name'       => $customer_name,
				'customer_email'      => $customer_email,
				'customer_phone'      => $customer_phone,
				'room_id'             => $room_id,
				'check_in'            => $check_in,
				'check_out'           => $check_out,
				'guests'              => $guests,
				'children'            => $children_count,
				'child_ages'          => $child_ages,
				'status'              => $post_status,
				'payment_method'      => $payment_method,
				'payment_received'    => 0,
				'payment_status'      => 'pending',
				'source'              => 'manual',
				'admin_notes'         => $admin_notes . "\n" . I18n::get_label( 'booking_msg_manual_admin' ),
				'custom_fields'       => $mhbo_custom,
				'extras'              => $post_extras,
				'language'            => $booking_language,
				'bypass_past'         => true, // Admin can book past dates
				'tax_mode'            => $posted_tax_mode,
				'consent'             => 1,
				'mhbo_consent'        => 1,
				'terms_accepted'      => 1,
				'mhbo_terms_accepted' => 1,
			);
			if ( $can_override_pricing ) {
				$process_payload['total_price'] = $total_price;
			}
			if ( is_array( $manual_tax_rates ) ) {
				if ( isset( $manual_tax_rates['accommodation'] ) ) {
					$process_payload['tax_rate_accommodation'] = $manual_tax_rates['accommodation'];
				}
				if ( isset( $manual_tax_rates['extras'] ) ) {
					$process_payload['tax_rate_extras'] = $manual_tax_rates['extras'];
				}
			}
			$result = BookingProcessor::process( $process_payload, false, false, true );

			if ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
				$add_mode = true;
			} else {
				$new_id = (int) $result['booking_id'];
				
				// Manual overrides not handled by processor but present in Admin UI
				$submitted_pay_raw = ( $can_process_payments && isset( $_POST['payment_amount'] ) && '' !== $_POST['payment_amount'] )
					? sanitize_text_field( (string) wp_unslash( $_POST['payment_amount'] ) )
					: '';
				$calc              = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, $guests, $post_extras, $children_count, $child_ages, '' !== $posted_tax_mode ? $posted_tax_mode : null, $manual_tax_rates );
				$tax_data          = $calc['tax'] ?? null;
				$curr_code         = ( is_array( $calc ) && isset( $calc['total'] ) && $calc['total'] instanceof Money ) ? $calc['total']->getCurrency() : Pricing::get_currency_code();

				$calc_total_m = ( is_array( $calc ) && isset( $calc['total'] ) && $calc['total'] instanceof Money )
					? $calc['total']
					: Money::fromCents( 0, $curr_code );
				$total_m         = $can_override_pricing ? Money::fromDecimal( (string) $total_price, $curr_code ) : $calc_total_m;
				$deposit_m       = $can_override_pricing ? Money::fromDecimal( (string) $deposit_amount, $curr_code ) : Money::fromCents( 0, $curr_code );
				$submitted_pay_m = $can_process_payments ? Money::fromDecimal( $submitted_pay_raw, $curr_code ) : Money::fromCents( 0, $curr_code );

				$eff_disc_m = Money::fromDecimal( (string) $discount_amount, $curr_code );

if ( ! $can_override_pricing ) {
					if ( is_array( $calc ) && isset( $calc['total'] ) && $calc['total'] instanceof Money ) {
						$total_m = $calc['total'];
					}
					
				}

				if ( $payment_received && ! $submitted_pay_m->isPositive() ) {
					$submitted_pay_m = $total_m;
				} elseif ( $deposit_received && ! $submitted_pay_m->isPositive() ) {
					$submitted_pay_m = $deposit_m;
				}

				$rem_balance_m         = $total_m->subtract( $submitted_pay_m );
				if ( $rem_balance_m->isNegative() ) {
					$rem_balance_m = Money::fromCents( 0, $curr_code );
				}

				$update_data = array(
					'total_price'       => $total_m->toDecimal(),
					'discount_amount'   => $eff_disc_m->toDecimal(),
					'deposit_amount'    => $deposit_m->toDecimal(),
					'deposit_received'  => 0,
					'payment_amount'    => '0.00',
					'remaining_balance' => $total_m->toDecimal(),
					'payment_received'  => 0,
					'payment_status'    => 'pending',
				);

// Link CRM guest ID if provided
				$posted_guest_id = isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0;
				if ( $posted_guest_id > 0 ) {
					$update_data['guest_id'] = $posted_guest_id;
				}

				// Tax breakdown persistence
				if ( $tax_data && ! empty( $tax_data['enabled'] ) ) {
					$update_data['tax_enabled']            = 1;
					$update_data['tax_mode']               = $tax_data['mode'] ?? 'disabled';
					$update_data['tax_rate_accommodation'] = $tax_data['rates']['accommodation'] ?? 0;
					$update_data['tax_rate_extras']        = $tax_data['rates']['extras'] ?? 0;
					$update_data['room_total_net']         = ( isset( $tax_data['totals']['room_net'] ) && $tax_data['totals']['room_net'] instanceof Money ) ? $tax_data['totals']['room_net']->toDecimal() : '0.00';
					$update_data['room_tax']               = ( isset( $tax_data['totals']['room_tax'] ) && $tax_data['totals']['room_tax'] instanceof Money ) ? $tax_data['totals']['room_tax']->toDecimal() : '0.00';
					$update_data['children_total_net']     = ( isset( $tax_data['totals']['children_net'] ) && $tax_data['totals']['children_net'] instanceof Money ) ? $tax_data['totals']['children_net']->toDecimal() : '0.00';
					$update_data['children_tax']           = ( isset( $tax_data['totals']['children_tax'] ) && $tax_data['totals']['children_tax'] instanceof Money ) ? $tax_data['totals']['children_tax']->toDecimal() : '0.00';
					$update_data['extras_total_net']       = ( isset( $tax_data['totals']['extras_net'] ) && $tax_data['totals']['extras_net'] instanceof Money ) ? $tax_data['totals']['extras_net']->toDecimal() : '0.00';
					$update_data['extras_tax']             = ( isset( $tax_data['totals']['extras_tax'] ) && $tax_data['totals']['extras_tax'] instanceof Money ) ? $tax_data['totals']['extras_tax']->toDecimal() : '0.00';
					$update_data['subtotal_net']           = ( isset( $tax_data['totals']['subtotal_net'] ) && $tax_data['totals']['subtotal_net'] instanceof Money ) ? $tax_data['totals']['subtotal_net']->toDecimal() : $total_m->toDecimal();
					$update_data['tax_breakdown']          = wp_json_encode( $tax_data );
					$update_data['total_gross']            = $total_m->toDecimal();
					$update_data['total_tax']              = ( isset( $tax_data['totals']['total_tax'] ) && $tax_data['totals']['total_tax'] instanceof Money ) ? $tax_data['totals']['total_tax']->toDecimal() : '0.00';
				} elseif ( $tax_data ) {
					$update_data['tax_enabled']            = 0;
					$update_data['tax_mode']               = 'disabled';
					$update_data['tax_rate_accommodation'] = 0;
					$update_data['tax_rate_extras']        = 0;
					$update_data['room_total_net']         = ( isset( $tax_data['totals']['room_net'] ) && $tax_data['totals']['room_net'] instanceof Money ) ? $tax_data['totals']['room_net']->toDecimal() : $total_m->toDecimal();
					$update_data['room_tax']               = '0.00';
					$update_data['children_total_net']     = ( isset( $tax_data['totals']['children_net'] ) && $tax_data['totals']['children_net'] instanceof Money ) ? $tax_data['totals']['children_net']->toDecimal() : '0.00';
					$update_data['children_tax']           = '0.00';
					$update_data['extras_total_net']       = ( isset( $tax_data['totals']['extras_net'] ) && $tax_data['totals']['extras_net'] instanceof Money ) ? $tax_data['totals']['extras_net']->toDecimal() : '0.00';
					$update_data['extras_tax']             = '0.00';
					$update_data['subtotal_net']           = ( isset( $tax_data['totals']['subtotal_net'] ) && $tax_data['totals']['subtotal_net'] instanceof Money ) ? $tax_data['totals']['subtotal_net']->toDecimal() : $total_m->toDecimal();
					$update_data['tax_breakdown']          = wp_json_encode( $tax_data );
					$update_data['total_gross']            = $total_m->toDecimal();
					$update_data['total_tax']              = '0.00';
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write, admin-only; booking cache invalidated via do_action below.
				$wpdb->update( $tb, $update_data, array( 'id' => $new_id ) );

				// Law 59: Record verified initial payment/deposit to Folio Payment Ledger
				if ( $submitted_pay_m->isPositive() && class_exists( Pricing::class ) ) {
					$current_user = wp_get_current_user();
					$staff_label  = ( $current_user && $current_user->exists() ) ? $current_user->display_name : 'Staff';
					$pay_type     = ( $payment_received || $submitted_pay_m->compare( $total_m ) >= 0 ) ? 'balance' : 'deposit';
					$pay_memo     = ( 'deposit' === $pay_type ) ? __( 'Initial deposit recorded upon reservation creation', 'modern-hotel-booking' ) : __( 'Initial full payment recorded upon reservation creation', 'modern-hotel-booking' );
					Pricing::record_payment(
						$new_id,
						$submitted_pay_m->toDecimal(),
						$payment_method,
						$pay_type,
						'',
						$pay_memo,
						'Staff: ' . $staff_label
					);
				}

				Cache::invalidate_booking( $new_id, $room_id );
				Pricing::clear_payment_amounts_cache();
				do_action( 'mhbo_booking_created', $new_id );
				if ( 'confirmed' === $post_status ) {
					do_action( 'mhbo_booking_confirmed', $new_id );
				}
				$view_url = wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . $new_id ), 'mhbo_edit_booking_' . $new_id );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_manual_booking_added' ) ) . ' <a href="' . esc_url( $view_url ) . '" class="button button-small" style="margin-left:8px;">' . esc_html__( 'Open Reservation Workstation →', 'modern-hotel-booking' ) . '</a></p></div>';
				$add_mode = false;
			}
		}

		$this->process_booking_edit_and_render_view( $tb, $edit_mode, $add_mode, $edit_data );
	}

	/**
	 * Process booking edit/folio submissions and render the active bookings view.
	 *
	 * @param string      $tb        Bookings table name.
	 * @param bool        $edit_mode Whether edit mode is active.
	 * @param bool        $add_mode  Whether manual add mode is active.
	 * @param object|null $edit_data Active booking record when editing.
	 */
	private function process_booking_edit_and_render_view( string $tb, bool $edit_mode, bool $add_mode, ?object $edit_data ): void {
		global $wpdb;

		$is_pro_active = false;

// Handle folio ledger transaction submission (Cashier Tender Workstation)
		if ( isset( $_POST['submit_record_folio_payment'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified inside handle_booking_folio_payment_submission().
			$this->handle_booking_folio_payment_submission( $edit_mode, $edit_data );
		}

		// Handle edit submission
		if ( isset( $_POST['submit_booking_update'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified inside handle_booking_update_submission().
			$this->handle_booking_update_submission( $tb, $edit_mode );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin table filter, sort, and pagination query parameters.
		$status_filter         = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$raw_payment_filter    = isset( $_GET['payment_status'] ) ? sanitize_key( wp_unslash( $_GET['payment_status'] ) ) : '';
		$allowed_pay_filters   = array( 'partial', 'unpaid', 'deposit_paid', 'partially_paid', 'completed', 'pending', 'processing', 'refunded', 'failed' );
		$payment_status_filter = in_array( $raw_payment_filter, $allowed_pay_filters, true ) ? $raw_payment_filter : '';
		$search_query          = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$allowed_sort_cols = array( 'id', 'guest', 'room', 'dates', 'total', 'status' );
		$orderby           = isset( $_GET['orderby'] ) && in_array( strtolower( sanitize_key( wp_unslash( $_GET['orderby'] ) ) ), $allowed_sort_cols, true )
			? strtolower( sanitize_key( wp_unslash( $_GET['orderby'] ) ) )
			: 'id';
		$order             = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) )
			? 'ASC'
			: 'DESC';

		// Pagination settings: 10, 25, 50, 100, -1 (all)
		$allowed_per_page = array( 10, 25, 50, 100, -1 );
		$current_user_id  = get_current_user_id();
		$saved_per_page   = (int) get_user_meta( $current_user_id, 'mhbo_bookings_per_page', true );
		$default_per_page = in_array( $saved_per_page, $allowed_per_page, true ) ? $saved_per_page : 25;

		if ( isset( $_GET['per_page'] ) ) {
			$raw_per_page = sanitize_text_field( wp_unslash( $_GET['per_page'] ) );
			if ( 'all' === strtolower( $raw_per_page ) || '-1' === $raw_per_page ) {
				$per_page = -1;
			} else {
				$parsed   = absint( $raw_per_page );
				$per_page = in_array( $parsed, array( 10, 25, 50, 100 ), true ) ? $parsed : $default_per_page;
			}
			if ( $current_user_id > 0 ) {
				update_user_meta( $current_user_id, 'mhbo_bookings_per_page', $per_page );
			}
		} else {
			$per_page = $default_per_page;
		}

		$total_bookings = Booking_Query::count( $status_filter, $search_query, $payment_status_filter );
		$total_pages    = ( $per_page > 0 ) ? max( 1, (int) ceil( $total_bookings / $per_page ) ) : 1;
		$paged          = isset( $_GET['paged'] ) ? max( 1, min( absint( wp_unslash( $_GET['paged'] ) ), $total_pages ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$offset         = ( $per_page > 0 ) ? ( $paged - 1 ) * $per_page : 0;
		$limit          = ( $per_page > 0 ) ? $per_page : -1;

		// Table bookings (paginated, with status and search filters)
		$bookings = Booking_Query::get_list( $status_filter, $limit, $offset, $orderby, $order, $search_query, $payment_status_filter );

		// Calendar bookings (comprehensive, up to 500 active/upcoming reservations)
		$calendar_bookings = Booking_Query::get_list( '', 500, 0, 'dates', 'ASC' );

		$base_url_args = array( 'page' => 'mhbo-bookings' );
		if ( $status_filter && 'all' !== $status_filter ) {
			$base_url_args['status'] = $status_filter;
		}
		if ( '' !== $payment_status_filter ) {
			$base_url_args['payment_status'] = $payment_status_filter;
		}
		if ( '' !== $search_query ) {
			$base_url_args['s'] = $search_query;
		}
		if ( $orderby !== 'id' ) {
			$base_url_args['orderby'] = $orderby;
		}
		if ( $order !== 'DESC' ) {
			$base_url_args['order'] = strtolower( $order );
		}
		if ( $per_page !== 25 ) {
			$base_url_args['per_page'] = ( $per_page <= 0 ) ? 'all' : $per_page;
		}
		$bookings_base_url = add_query_arg( $base_url_args, admin_url( 'admin.php' ) );

		$all_rooms = Cache::is_enabled() ? wp_cache_get( 'mhbo_all_rooms', 'mhbo_rooms' ) : false;
		if ( false === $all_rooms ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$all_rooms = $wpdb->get_results( "SELECT r.id, r.room_number, t.name as type_name, t.base_price FROM {$wpdb->prefix}mhbo_rooms r JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id ORDER BY (r.room_number + 0) ASC, r.room_number ASC, r.id ASC" );
			if ( is_array( $all_rooms ) ) {
				usort(
					$all_rooms,
					function ( $a, $b ) {
						$cmp = strnatcasecmp( (string) $a->room_number, (string) $b->room_number );
						if ( 0 !== $cmp ) {
							return $cmp;
						}
						return ( (int) $a->id ) <=> ( (int) $b->id );
					}
				);
			}
			if ( Cache::is_enabled() ) {
				wp_cache_set( 'mhbo_all_rooms', $all_rooms, 'mhbo_rooms', HOUR_IN_SECONDS );
			}
		}

		$calendar_data  = $this->build_bookings_calendar_data( is_array( $all_rooms ) ? $all_rooms : array(), is_array( $calendar_bookings ) ? $calendar_bookings : array(), MHBO_IS_PRO );
		$events         = $calendar_data['events'];
		$distinct_rooms = $calendar_data['distinct_rooms'];
		$full_days      = $calendar_data['full_days'];
		$total_rooms    = $calendar_data['total_rooms'];

		// Operational Daily Briefing KPI Metrics
		$today_ymd = HotelTime::today();
		$ops_metrics     = Booking_Query::get_operational_metrics( $today_ymd );
		$today_checkins  = $ops_metrics['arrivals'];
		$today_checkouts = $ops_metrics['departures'];
		$in_house_rooms  = $ops_metrics['occupied_rooms'];
		$distinct_room_count = $total_rooms ?: ( is_array( $all_rooms ) ? max( 1, count( $all_rooms ) ) : 1 );
		$occupancy_pct       = min( 100, (int) round( ( $in_house_rooms / max( 1, $distinct_room_count ) ) * 100 ) );

		if ( false === get_transient( 'mhbo_legacy_deposit_normalized' ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Self-healing normalization for legacy deposit bookings.
			$wpdb->query(
				"UPDATE {$wpdb->prefix}mhbo_bookings 
                 SET deposit_received = 1, payment_received = 0, payment_status = 'deposit_paid', balance_status = 'pending' 
                 WHERE payment_type = 'deposit' AND (deposit_received = 0 OR deposit_received IS NULL) AND remaining_balance > 0 AND (payment_amount > 0 OR payment_status = 'completed' OR payment_status = 'deposit_paid')"
			);
			set_transient( 'mhbo_legacy_deposit_normalized', 1, DAY_IN_SECONDS );
		}

		?>
		<div class="wrap mhbo-admin-wrap">
			<?php 
			if ( $add_mode ) {
				$header_actions = array(
					array(
						'label' => __( 'All Reservations', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings' ),
						'class' => 'button-secondary',
					),
				);

AdminUI::render_header(
					I18n::get_label( 'label_add_manual_booking' ),
					__( 'Front-desk luxury PMS workstation for walk-in and phone reservations with live folio recalculation.', 'modern-hotel-booking' ),
					$header_actions,
					array(
						array(
							'label' => I18n::get_label( 'menu_dashboard' ),
							'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
						),
						array(
							'label' => I18n::get_label( 'menu_bookings' ),
							'url'   => admin_url( 'admin.php?page=mhbo-bookings' ),
						),
					)
				);
			} elseif ( $edit_mode && $edit_data ) {
				$header_actions = array(
					array(
						'label' => __( 'All Reservations', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings' ),
						'class' => 'button-secondary',
					),
					array(
						'label' => '+ ' . I18n::get_label( 'label_add_manual_booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings&action=add' ),
						'class' => 'button-primary',
					),
				);

AdminUI::render_header(
					sprintf(
						/* translators: %d: Reservation/Booking ID */
						__( 'Reservation #%d', 'modern-hotel-booking' ),
						(int) $edit_data->id
					),
					__( 'Manage guest folio, room unit assignment, stay dates, enhancements, and USALI financial transactions.', 'modern-hotel-booking' ),
					$header_actions,
					array(
						array(
							'label' => I18n::get_label( 'menu_dashboard' ),
							'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
						),
						array(
							'label' => I18n::get_label( 'menu_bookings' ),
							'url'   => admin_url( 'admin.php?page=mhbo-bookings' ),
						),
					)
				);
			} else {
				$header_actions = array();
				if ( Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) ) {
					$header_actions[] = array(
						'label' => '+ ' . I18n::get_label( 'label_add_manual_booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings&action=add' ),
						'class' => 'button-primary',
					);
				}

AdminUI::render_header(
					I18n::get_label( 'menu_bookings' ),
					I18n::get_label( 'dash_hotel_control_desc' ),
					$header_actions,
					array(
						array(
							'label' => I18n::get_label( 'menu_dashboard' ),
							'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
						),
					)
				);
			}
			?>
			<?php
			if ( ! $add_mode && ! $edit_mode ) :

if ( Capabilities::current_user_can( Capabilities::MANAGE_GUESTS ) && class_exists( GuestManager::class ) && GuestManager::is_enabled() && class_exists( GuestMigration::class ) ) {
					$unmigrated_count = GuestMigration::get_unmigrated_count();
					if ( $unmigrated_count > 0 ) {
						if ( class_exists( GuestAsyncTasks::class ) ) {
							GuestAsyncTasks::queue_migrate_batch( 0, 250 );
						}
						$sync_url = wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=sync_crm_guests' ), 'mhbo_sync_crm_guests' );
						?>
						<div class="notice notice-info is-dismissible" style="margin-top:15px;">
							<p>
								<?php 
								/* translators: %d: count of unlinked historical bookings */
								echo esc_html( sprintf( __( 'Modern Hotel Booking detected %d historical reservation(s) not yet linked to Guest CRM profiles. Background sync is running.', 'modern-hotel-booking' ), $unmigrated_count ) ); 
								?>
								<a href="<?php echo esc_url( $sync_url ); ?>" class="button button-small" style="margin-left:10px;"><?php esc_html_e( 'Sync Now', 'modern-hotel-booking' ); ?></a>
							</p>
						</div>
						<?php
					}
				}

				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state for status notice
				if ( ! empty( $status_filter ) && 'all' !== $status_filter ) :
					?>
					<div class="notice notice-info is-dismissible" style="margin-top:15px;">
						<p>
							<?php
							/* translators: %s: booking status being filtered (e.g., Pending, Confirmed) */
							echo esc_html( sprintf( I18n::get_label( 'msg_filtering_status' ), ucfirst( $status_filter ) ) );
							?>
							<a href="<?php echo esc_url( remove_query_arg( array( 'status', 'paged' ), $bookings_base_url ) ); ?>" class="button button-small" style="margin-left:10px;">
								<?php echo esc_html( I18n::get_label( 'btn_clear_filter' ) ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>
				<?php if ( '' !== $payment_status_filter ) : ?>
					<?php
					$pay_filter_label = match ( $payment_status_filter ) {
						'unpaid'  => I18n::get_label( 'label_outstanding_balance' ),
						'partial' => I18n::get_label( 'label_status_partially_paid' ),
						default   => I18n::translate_payment_status( $payment_status_filter ),
					};
					?>
					<div class="notice notice-info is-dismissible" style="margin-top:15px;">
						<p>
							<?php
							/* translators: %s: payment status filter label */
							echo esc_html( sprintf( I18n::get_label( 'msg_filtering_status' ), $pay_filter_label ) );
							?>
							<a href="<?php echo esc_url( remove_query_arg( array( 'payment_status', 'paged' ), $bookings_base_url ) ); ?>" class="button button-small" style="margin-left:10px;">
								<?php echo esc_html( I18n::get_label( 'btn_clear_filter' ) ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $search_query ) ) : ?>
					<div class="notice notice-info is-dismissible" style="margin-top:15px;">
						<p>
							<?php
							/* translators: %s: search query string */
							echo esc_html( sprintf( __( 'Search results matching: "%s"', 'modern-hotel-booking' ), $search_query ) );
							?>
							<a href="<?php echo esc_url( remove_query_arg( array( 's', 'paged' ), $bookings_base_url ) ); ?>" class="button button-small" style="margin-left:10px;">
								<?php esc_html_e( 'Clear Search', 'modern-hotel-booking' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php
			$all_rooms_list = is_array( $all_rooms ) ? $all_rooms : array();
			if ( $add_mode ) {
				$this->get_workstation_view()->render_add_booking_workstation( $all_rooms_list );
			}
			if ( $edit_mode && null !== $edit_data ) {
				$this->get_workstation_view()->render_edit_booking_workstation( $edit_data, $all_rooms_list );
			}
			if ( ! $edit_mode && ! $add_mode ) {
				$this->get_workstation_view()->render_bookings_list_and_calendar_section(
					$today_checkins,
					$today_checkouts,
					$in_house_rooms,
					$distinct_room_count,
					$occupancy_pct,
					$distinct_rooms,
					$events,
					$full_days,
					$search_query,
					$bookings_base_url,
					$status_filter,
					$per_page,
					$orderby,
					$order,
					$bookings,
					$total_bookings,
					$paged,
					$allowed_per_page
				);
			}
			?>
		</div>
		<?php
	}

	/**
	 * Handle cashier tender folio payment submission for the bookings workstation.
	 *
	 * @param bool        $edit_mode Active edit mode flag, updated when reloading booking data.
	 * @param object|null $edit_data Active booking record, updated after recording payment.
	 */
	private function handle_booking_folio_payment_submission( bool &$edit_mode, ?object &$edit_data ): void {
		global $wpdb;

		if ( ! Capabilities::can_process_payments() ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}
		$record_bid = absint( wp_unslash( $_POST['record_payment_booking_id'] ?? 0 ) );
		check_admin_referer( 'mhbo_record_payment_' . $record_bid, 'mhbo_record_pay_nonce' );

		$tender_method = sanitize_key( wp_unslash( $_POST['record_payment_method'] ?? 'arrival' ) );
		$tender_type   = sanitize_key( wp_unslash( $_POST['record_payment_type'] ?? 'balance' ) );
		$tender_note   = sanitize_text_field( wp_unslash( $_POST['record_payment_note'] ?? '' ) );
		$tender_txn_id = sanitize_text_field( wp_unslash( $_POST['record_payment_txn_id'] ?? '' ) );
		$tender_cur    = Pricing::get_currency_code();
		$tender_raw    = sanitize_text_field( wp_unslash( $_POST['record_payment_amount'] ?? '0' ) );
		$tender_money  = Money::fromDecimal( $tender_raw, $tender_cur );
		$send_email    = ! empty( $_POST['record_payment_send_email'] );

		$is_refund_submission = ( 'refund' === $tender_type || $tender_money->isNegative() );
		if ( $is_refund_submission && ! Capabilities::can_issue_refunds() ) {
			if ( $record_bid > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$reloaded = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $record_bid ), OBJECT );
				if ( is_object( $reloaded ) ) {
					$edit_data = $reloaded;
					$edit_mode = true;
				}
			}
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Recording folio refunds requires Hotel Manager or Administrator permissions.', 'modern-hotel-booking' ) . '</p></div>';
			return;
		}

		$can_override_pricing = Capabilities::can_override_booking_pricing();

		// If submitted from booking edit workstation, persist staged changes (discount, total price, etc.) prior to recording transaction
		$staged_nonce           = ! empty( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$staged_nonce_valid     = ( '' !== $staged_nonce ) && (bool) wp_verify_nonce( $staged_nonce, 'mhbo_update_booking' );
		$staged_booking_matches = isset( $_POST['booking_id'] ) && absint( wp_unslash( $_POST['booking_id'] ) ) === $record_bid;
		if ( $staged_nonce_valid && $staged_booking_matches ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pre_booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $record_bid ), OBJECT );
			if ( $pre_booking ) {
				$curr_code         = class_exists( Pricing::class ) ? Pricing::get_currency_code() : 'USD';
				$staged_discount_m = $can_override_pricing
					? Money::fromDecimal( sanitize_text_field( (string) wp_unslash( $_POST['discount_amount'] ?? '0' ) ), $curr_code )
					: Money::fromDecimal( (string) ( $pre_booking->discount_amount ?? '0' ), $curr_code );
				$staged_total_m    = Money::fromDecimal( sanitize_text_field( (string) wp_unslash( $_POST['total_price'] ?? '0' ) ), $curr_code );
				$old_discount_m    = Money::fromDecimal( (string) ( $pre_booking->discount_amount ?? '0' ), $curr_code );
				$old_total_m       = Money::fromDecimal( (string) ( $pre_booking->total_price ?? '0' ), $curr_code );
				$snap_raw          = isset( $_POST['total_price_snapshot'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['total_price_snapshot'] ) ) : (string) ( $pre_booking->total_price ?? '0' );
				$snapshot_total_m  = Money::fromDecimal( $snap_raw, $curr_code );
				$total_touched     = $can_override_pricing && ! $staged_total_m->equals( $snapshot_total_m );
				$staged_notes      = sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) );
				$staged_cname      = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
				$staged_cemail     = sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) );
				$staged_cphone     = sanitize_text_field( wp_unslash( $_POST['customer_phone'] ?? '' ) );
				$staged_updates    = array();

				$staged_coupon = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );
				$old_coupon    = (string) ( $pre_booking->coupon_code ?? '' );
				$recalc_staged = false;
				if ( '' !== $staged_coupon && $staged_coupon !== $old_coupon && ! $can_override_pricing ) {
					$staged_discount_m = Money::fromCents( 0, $curr_code );
				}

if ( ! $staged_discount_m->equals( $old_discount_m ) ) {
					$staged_updates['discount_amount'] = $staged_discount_m->toDecimal();
				}

				if ( $total_touched && $staged_total_m->isPositive() && ! $staged_total_m->equals( $old_total_m ) ) {
					$staged_updates['total_price'] = $staged_total_m->toDecimal();
				} elseif ( ! $recalc_staged && ! $staged_discount_m->equals( $old_discount_m ) ) {
					$disc_diff_m = $staged_discount_m->subtract( $old_discount_m );
					$new_total_m = $old_total_m->subtract( $disc_diff_m );
					if ( $new_total_m->isNegative() ) {
						$new_total_m = Money::fromCents( 0, $curr_code );
					}
					$staged_updates['total_price'] = $new_total_m->toDecimal();
				}

				if ( '' !== $staged_notes && $staged_notes !== ( $pre_booking->admin_notes ?? '' ) ) {
					$staged_updates['admin_notes'] = $staged_notes;
				}
				if ( '' !== $staged_cname && $staged_cname !== ( $pre_booking->customer_name ?? '' ) ) {
					$staged_updates['customer_name'] = $staged_cname;
				}
				if ( '' !== $staged_cemail && $staged_cemail !== ( $pre_booking->customer_email ?? '' ) ) {
					$staged_updates['customer_email'] = $staged_cemail;
				}
				if ( '' !== $staged_cphone && $staged_cphone !== ( $pre_booking->customer_phone ?? '' ) ) {
					$staged_updates['customer_phone'] = $staged_cphone;
				}

				if ( ! empty( $staged_updates ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$wpdb->prefix . 'mhbo_bookings',
						$staged_updates,
						array( 'id' => $record_bid )
					);
					Cache::invalidate_booking( $record_bid );
				}
			}
		}

		if ( $record_bid > 0 && ! $tender_money->isZero() ) {
			$current_user = wp_get_current_user();
			$staff_label  = ( $current_user && $current_user->exists() ) ? $current_user->display_name : 'Staff';
			$recorded     = Pricing::record_payment(
				$record_bid,
				$tender_money->toDecimal(),
				$tender_method,
				$tender_type,
				$tender_txn_id,
				$tender_note,
				'Staff: ' . $staff_label
			);
			if ( $recorded ) {
				// Immediately reload $edit_data so workstation reflects fresh financial numbers
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$reloaded = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $record_bid ), OBJECT );
				if ( is_object( $reloaded ) ) {
					$edit_data = $reloaded;
					$edit_mode = true;
				}

				// Dispatch financial receipt and admin notification under Law 58
				$email_dispatched = false;
				if ( $send_email && class_exists( Email::class ) ) {
					$payment_payload = array(
						'amount'         => floatval( $tender_money->abs()->toDecimal() ),
						'transaction_id' => $tender_txn_id,
						'method'         => $tender_method,
						'note'           => $tender_note,
						'type'           => $tender_type,
						'staff'          => 'Staff: ' . $staff_label,
					);

					$reloaded_rem_m = ( is_object( $reloaded ) && isset( $reloaded->remaining_balance ) ) ? Money::fromDecimal( (string) $reloaded->remaining_balance, $tender_cur ) : null;
					try {
						if ( 'refund' === $tender_type || $tender_money->isNegative() ) {
							$guest_ok         = Email::send_payment_confirmation_email( $record_bid, $payment_payload, 'refund' );
							$admin_ok         = Email::send_admin_payment_notification( $record_bid, $payment_payload );
							$email_dispatched = ( $guest_ok || $admin_ok );
						} else {
							$guest_ok = false;
							
							if ( ! $guest_ok ) {
								$guest_ok = Email::send_payment_confirmation_email( $record_bid, $payment_payload, $tender_type );
							}
							$admin_ok         = Email::send_admin_payment_notification( $record_bid, $payment_payload );
							$email_dispatched = ( $guest_ok || $admin_ok );
						}
					} catch ( \Throwable $e ) {
						$email_dispatched = false;
					}
				}

				if ( 'refund' === $tender_type || $tender_money->isNegative() ) {
					/* translators: %s: formatted refund amount */
					$msg = sprintf( __( 'Folio refund of %s recorded successfully.', 'modern-hotel-booking' ), I18n::format_currency( $tender_money->abs() ) );
				} else {
					/* translators: %s: formatted payment amount */
					$msg = sprintf( __( 'Folio payment of %s recorded successfully.', 'modern-hotel-booking' ), I18n::format_currency( $tender_money ) );
				}
				if ( $email_dispatched ) {
					$msg .= ' ' . __( 'Receipt sent to guest & notification to admin.', 'modern-hotel-booking' );
				}
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to record folio transaction. Please try again.', 'modern-hotel-booking' ) . '</p></div>';
			}
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please enter a valid payment amount greater than zero.', 'modern-hotel-booking' ) . '</p></div>';
		}
	}

	/**
	 * Determine whether any pricing-affecting stay parameters changed compared to the stored booking.
	 *
	 * @param object               $old_booking    Existing booking database row.
	 * @param int                  $room_id        Submitted room ID.
	 * @param string               $check_in       Submitted check-in date (Y-m-d).
	 * @param string               $check_out      Submitted check-out date (Y-m-d).
	 * @param int                  $guests         Submitted adult count.
	 * @param int                  $children_count Submitted children count.
	 * @param list<int>            $child_ages     Sanitized submitted child ages.
	 * @param array<string, int>   $post_extras    Normalized submitted extras map (`id => qty`).
	 * @param string               $coupon_code    Submitted coupon code.
	 * @return bool True if stay pricing inputs changed.
	 */
	private function did_booking_pricing_inputs_change(
		object $old_booking,
		int $room_id,
		string $check_in,
		string $check_out,
		int $guests,
		int $children_count,
		array $child_ages,
		array $post_extras,
		string $coupon_code
	): bool {
		if ( (int) ( $old_booking->room_id ?? 0 ) !== $room_id ) {
			return true;
		}
		if ( (string) ( $old_booking->check_in ?? '' ) !== $check_in || (string) ( $old_booking->check_out ?? '' ) !== $check_out ) {
			return true;
		}
		if ( max( 1, (int) ( $old_booking->guests ?? 1 ) ) !== $guests ) {
			return true;
		}
		if ( max( 0, (int) ( $old_booking->children ?? 0 ) ) !== $children_count ) {
			return true;
		}

		$old_ages_raw = ! empty( $old_booking->children_ages ) ? $old_booking->children_ages : ( $old_booking->child_ages ?? array() );
		$old_ages_dec = is_string( $old_ages_raw ) ? json_decode( $old_ages_raw, true ) : (array) $old_ages_raw;
		$old_ages     = Pricing::sanitize_child_ages( is_array( $old_ages_dec ) ? $old_ages_dec : array() );
		sort( $old_ages, SORT_NUMERIC );
		$new_ages = Pricing::sanitize_child_ages( $child_ages );
		sort( $new_ages, SORT_NUMERIC );
		if ( $old_ages !== $new_ages ) {
			return true;
		}

		$old_extras_map = array();
		foreach ( Pricing::decode_stored_extras( $old_booking->booking_extras ?? null ) as $ek => $ev ) {
			$eid = sanitize_key( (string) ( $ev['id'] ?? $ek ) );
			$qty = max( 0, (int) ( $ev['quantity'] ?? 1 ) );
			if ( '' !== $eid && $qty > 0 ) {
				$old_extras_map[ $eid ] = $qty;
			}
		}
		$old_cf = ! empty( $old_booking->custom_fields ) ? json_decode( (string) $old_booking->custom_fields, true ) : array();
		if ( is_array( $old_cf ) ) {
			if ( ! empty( $old_cf['_early_checkin'] ) && ! isset( $old_extras_map['_early_checkin'] ) ) {
				$old_extras_map['_early_checkin'] = 1;
			}
			if ( ! empty( $old_cf['_late_checkout'] ) && ! isset( $old_extras_map['_late_checkout'] ) ) {
				$old_extras_map['_late_checkout'] = 1;
			}
		}
		$new_extras_map = array();
		foreach ( $post_extras as $nk => $nq ) {
			$nid = sanitize_key( (string) $nk );
			$nqv = max( 0, (int) $nq );
			if ( '' !== $nid && $nqv > 0 ) {
				$new_extras_map[ $nid ] = $nqv;
			}
		}
		ksort( $old_extras_map );
		ksort( $new_extras_map );
		if ( $old_extras_map !== $new_extras_map ) {
			return true;
		}

		$old_coupon_norm = strtoupper( trim( (string) ( $old_booking->coupon_code ?? '' ) ) );
		$new_coupon_norm = strtoupper( trim( $coupon_code ) );
		if ( $old_coupon_norm !== $new_coupon_norm ) {
			return true;
		}

		return false;
	}

	/**
	 * Handle booking edit form submission and persist financial/status updates.
	 *
	 * @param string $tb        Bookings database table name.
	 * @param bool   $edit_mode Edit mode flag, reset to false on success or kept true on availability conflict.
	 */
	private function handle_booking_update_submission( string $tb, bool &$edit_mode ): void {
		global $wpdb;

		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}
		check_admin_referer( 'mhbo_update_booking' );

		$can_override_tax = false;
		
		$can_override_pricing = Capabilities::can_override_booking_pricing();
		$can_process_payments = Capabilities::can_process_payments();
		$can_issue_refunds    = Capabilities::can_issue_refunds();

		// Rule 11: Extract and sanitize update booking inputs
		$booking_id          = absint( wp_unslash( $_POST['booking_id'] ?? 0 ) );
		$new_status          = sanitize_key( wp_unslash( $_POST['status'] ?? 'pending' ) );
		$room_id             = absint( wp_unslash( $_POST['room_id'] ?? 0 ) );
		$check_in            = sanitize_text_field( wp_unslash( $_POST['check_in'] ?? '' ) );
		$check_out           = sanitize_text_field( wp_unslash( $_POST['check_out'] ?? '' ) );
		$guests              = absint( wp_unslash( $_POST['guests'] ?? 1 ) );
		$children_count      = absint( wp_unslash( $_POST['children'] ?? 0 ) );
		$child_ages          = isset( $_POST['child_ages'] ) && is_array( $_POST['child_ages'] ) ? Pricing::sanitize_child_ages( map_deep( wp_unslash( $_POST['child_ages'] ), 'sanitize_text_field' ) ) : array();
		$payment_received    = ( $can_process_payments && isset( $_POST['payment_received'] ) && sanitize_text_field( wp_unslash( $_POST['payment_received'] ) ) === '1' ) ? 1 : 0;
		$deposit_received    = 0;
		$deposit_amount_edit = 0.0;
		$can_use_deposits_ed = false;
		
		$payment_status      = sanitize_key( wp_unslash( $_POST['payment_status'] ?? 'pending' ) );
		$total_price_edit    = $can_override_pricing ? floatval( wp_unslash( $_POST['total_price'] ?? 0 ) ) : 0.0;
		$discount_amount     = $can_override_pricing ? floatval( wp_unslash( $_POST['discount_amount'] ?? 0 ) ) : 0.0;
		$coupon_code         = sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) );
		$customer_name       = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
		$customer_email      = sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) );
		$customer_phone      = sanitize_text_field( wp_unslash( $_POST['customer_phone'] ?? '' ) );
		$admin_notes         = sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) );
		$booking_language    = sanitize_key( wp_unslash( $_POST['booking_language'] ?? 'en' ) );
		$payment_method      = $can_process_payments ? sanitize_key( wp_unslash( $_POST['payment_method'] ?? 'arrival' ) ) : 'arrival';
		$posted_tax_mode     = null;
		
		$raw_posted_custom   = isset( $_POST['mhbo_custom'] ) && is_array( $_POST['mhbo_custom'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_custom'] ) ) : array();
		$mhbo_extras_raw     = isset( $_POST['mhbo_extras'] ) && is_array( $_POST['mhbo_extras'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_extras'] ) ) : array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$old_booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $booking_id ), OBJECT );
		if ( ! $old_booking ) {
			wp_die( esc_html( I18n::get_label( 'msg_booking_not_found' ) ) );
		}

		$old_status            = (string) ( $old_booking->status ?? '' );
		$old_check_in          = (string) ( $old_booking->check_in ?? '' );
		$old_check_out         = (string) ( $old_booking->check_out ?? '' );
		$old_room_id           = (int) ( $old_booking->room_id ?? 0 );
		$old_total_str         = Money::fromDecimal( (string) ( $old_booking->total_price ?? '0' ) )->toDecimal();
		$was_payment_received  = ! empty( $old_booking->payment_received );
		$existing_payment_date = $old_booking->payment_date ?? null;

		// Preserve internal (_-prefixed) portal/system metadata while updating user-editable custom fields.
		$existing_custom = array();
		if ( ! empty( $old_booking->custom_fields ) ) {
			$decoded_existing_custom = is_string( $old_booking->custom_fields )
				? json_decode( $old_booking->custom_fields, true )
				: (array) $old_booking->custom_fields;
			if ( is_array( $decoded_existing_custom ) ) {
				$existing_custom = $decoded_existing_custom;
			}
		}

		$mhbo_custom = array();
		foreach ( $existing_custom as $cf_key => $cf_val ) {
			$cf_key_str = (string) $cf_key;
			if ( str_starts_with( $cf_key_str, '_' ) ) {
				$mhbo_custom[ $cf_key_str ] = $cf_val;
			}
		}
		foreach ( $raw_posted_custom as $cf_key => $cf_val ) {
			$cf_key_str = (string) $cf_key;
			if ( ! str_starts_with( $cf_key_str, '_' ) ) {
				$mhbo_custom[ $cf_key_str ] = $cf_val;
			}
		}

		$dates_changed        = ( $old_check_in !== $check_in || $old_check_out !== $check_out );
		$stay_details_changed = $dates_changed
			|| ( $room_id !== $old_room_id )
			|| ( $guests !== (int) ( $old_booking->guests ?? 1 ) )
			|| ( $children_count !== (int) ( $old_booking->children ?? 0 ) );
		$clear_portal_reqs    = ! empty( $_POST['mhbo_clear_portal_requests'] ) && '1' === sanitize_text_field( (string) wp_unslash( $_POST['mhbo_clear_portal_requests'] ) );

		if ( $stay_details_changed || $clear_portal_reqs ) {
			unset( $mhbo_custom['_mhbo_modification_requested'] );
		}
		if ( in_array( $new_status, array( 'cancelled', 'refunded' ), true ) || $clear_portal_reqs ) {
			unset( $mhbo_custom['_mhbo_cancellation_requested'], $mhbo_custom['_mhbo_modification_requested'] );
		}

		// Guest CRM Stay Rating & Link
		$guest_rating    = isset( $_POST['guest_rating'] ) ? min( 5, max( 0, absint( wp_unslash( $_POST['guest_rating'] ) ) ) ) : 0;
		$posted_guest_id = isset( $_POST['guest_id'] ) ? absint( wp_unslash( $_POST['guest_id'] ) ) : 0;
		$target_guest_id = $posted_guest_id > 0 ? $posted_guest_id : absint( $old_booking->guest_id ?? 0 );

		if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) {
			if ( $target_guest_id <= 0 && ! empty( $customer_email ) ) {
				$target_guest_id = GuestIdentityResolver::resolve_and_link_guest(
					array(
						'customer_name'  => $customer_name,
						'customer_email' => $customer_email,
						'customer_phone' => $customer_phone,
					)
				);
			}
		}

		$available_extras = (array) get_option( 'mhbo_pro_extras', array() );
		$extras_map       = array();
		foreach ( $available_extras as $ex ) {
			if ( isset( $ex['id'] ) ) {
				$extras_map[ (string) $ex['id'] ]                 = $ex;
				$extras_map[ sanitize_key( (string) $ex['id'] ) ] = $ex;
			}
		}

		// Format extras for Pricing calculation (id => qty)
		$post_extras = array();
		foreach ( $mhbo_extras_raw as $ex_id => $val ) {
			$clean_ex_id = sanitize_key( (string) $ex_id );
			$extra       = $extras_map[ $clean_ex_id ] ?? ( $extras_map[ (string) $ex_id ] ?? null );
			$qty         = 0;
			if ( $extra && ( $extra['control_type'] ?? 'checkbox' ) === 'quantity' ) {
				$qty = absint( $val );
			} elseif ( '1' === $val || ( is_numeric( $val ) && (int) $val > 0 ) ) {
				$qty = max( 1, absint( $val ) );
			}
			if ( $qty > 0 ) {
				$post_extras[ $clean_ex_id ] = $qty;
			}
		}

		// Ensure stay extensions are synced to $post_extras and $mhbo_custom
		if ( ! empty( $post_extras['_early_checkin'] ) ) {
			$mhbo_custom['_early_checkin'] = 1;
		} else {
			unset( $mhbo_custom['_early_checkin'] );
		}
		if ( ! empty( $post_extras['_late_checkout'] ) ) {
			$mhbo_custom['_late_checkout'] = 1;
		} else {
			unset( $mhbo_custom['_late_checkout'] );
		}

		$old_tax_mode        = 'disabled';
		$effective_tax_mode  = 'disabled';
		$effective_tax_rates = null;

$pricing_inputs_changed = $this->did_booking_pricing_inputs_change(
			$old_booking,
			$room_id,
			$check_in,
			$check_out,
			$guests,
			$children_count,
			$child_ages,
			$post_extras,
			$coupon_code
		);

		$calc     = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, $guests, $post_extras, $children_count, $child_ages, $effective_tax_mode, $effective_tax_rates, true );
		$tax_data = $calc['tax'] ?? null;

// Construct canonical serializable booking_extras map from pricing breakdown
		$booking_extras = array();
		if ( ! empty( $calc['extras_breakdown'] ) && is_array( $calc['extras_breakdown'] ) ) {
			foreach ( $calc['extras_breakdown'] as $eb_id => $eb_item ) {
				$booking_extras[ (string) $eb_id ] = array(
					'id'            => (string) ( $eb_item['id'] ?? $eb_id ),
					'name'          => (string) ( $eb_item['name'] ?? '' ),
					'price'         => $eb_item['price'] instanceof Money ? $eb_item['price']->toDecimal() : (string) ( $eb_item['price'] ?? '0' ),
					'quantity'      => (int) ( $eb_item['quantity'] ?? 1 ),
					'multiplier'    => (int) ( $eb_item['multiplier'] ?? 1 ),
					'pricing_type'  => (string) ( $eb_item['pricing_type'] ?? 'fixed' ),
					'total'         => $eb_item['total'] instanceof Money ? $eb_item['total']->toDecimal() : (string) ( $eb_item['total'] ?? '0' ),
					'compulsory'    => (int) ( $eb_item['compulsory'] ?? 0 ),
					'complimentary' => (int) ( $eb_item['complimentary'] ?? 0 ),
					'allow_coupon'  => (int) ( $eb_item['allow_coupon'] ?? 0 ),
					'tax_class'     => (string) ( $eb_item['tax_class'] ?? '' ),
				);
			}
		}

		// Re-apply existing coupon so the service fee is recalculated on the discounted base.
		$edit_coupon_code = sanitize_text_field( (string) ( $coupon_code ?? '' ) );
		$curr_code        = ( isset( $calc['total'] ) && $calc['total'] instanceof Money ) ? $calc['total']->getCurrency() : 'USD';
		if ( ! $can_override_pricing ) {
			$old_coupon_norm = strtoupper( trim( (string) ( $old_booking->coupon_code ?? '' ) ) );
			$new_coupon_norm = strtoupper( trim( $edit_coupon_code ) );
			if ( $new_coupon_norm !== $old_coupon_norm ) {
				$discount_amount    = 0.0;
				$edit_coupon_disc_m = Money::fromCents( 0, $curr_code );
			} else {
				$discount_amount    = floatval( $old_booking->discount_amount ?? 0 );
				$edit_coupon_disc_m = Money::fromDecimal( (string) ( $old_booking->discount_amount ?? '0' ), $curr_code );
			}
		} else {
			$edit_coupon_disc_m = Money::fromDecimal( (string) $discount_amount, $curr_code );
		}

$calc_curr = $curr_code;
		if ( ! $can_override_pricing ) {
			if ( $pricing_inputs_changed && isset( $calc['total'] ) && $calc['total'] instanceof Money && $calc['total']->isPositive() ) {
				$total_money_val  = $calc['total'];
				$total_price_edit = $total_money_val->toDecimal();
			} else {
				$total_money_val  = Money::fromDecimal( (string) ( $old_booking->total_price ?? '0' ), $calc_curr );
				$total_price_edit = $total_money_val->toDecimal();
			}
		} else {
			$total_money_val = Money::fromDecimal( (string) $total_price_edit, $calc_curr );

			if ( isset( $calc['total'] ) && $calc['total'] instanceof Money && $calc['total']->isPositive() ) {
				$calc_total_m      = $calc['total'];
				$submitted_total_m = $total_money_val;
				$snap_raw          = isset( $_POST['total_price_snapshot'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['total_price_snapshot'] ) ) : (string) ( $old_booking->total_price ?? '0' );
				$snapshot_total_m  = Money::fromDecimal( $snap_raw, $calc_curr );
				$field_was_touched = ! $submitted_total_m->equals( $snapshot_total_m );

				if ( ! $field_was_touched && ! $calc_total_m->equals( $submitted_total_m ) ) {
					$total_price_edit = $calc_total_m->toDecimal();
					$total_money_val  = $calc_total_m;
				}
			}
		}

		// Calculate paid amount and remaining balance (Law 34: Zero-Float Money math)
		$old_amts         = class_exists( Pricing::class ) ? Pricing::get_booking_payment_amounts( $old_booking ) : null;
		$old_pay_m        = ( null !== $old_amts && isset( $old_amts['received'] ) ) ? $old_amts['received'] : Money::fromDecimal( (string) ( $old_booking->payment_amount ?? '0' ), $calc_curr );
		$dep_edit_m       = Money::fromDecimal( (string) ( $old_booking->deposit_amount ?? '0' ), $calc_curr );
		$deposit_received = (int) ( $old_booking->deposit_received ?? 0 );

/** @var array{amount: string, type: string, note: string, staff: string}|null $pending_ledger_adj */
		$pending_ledger_adj = null;
		$is_deposit_booking_edit = ( 'deposit' === (string) ( $old_booking->payment_type ?? '' ) )
			|| $dep_edit_m->isPositive()
			|| ( $can_use_deposits_ed && 0 !== $deposit_received );

		if ( ! $can_process_payments ) {
			$final_pay_m      = $old_pay_m;
			$payment_method   = (string) ( $old_booking->payment_method ?? 'arrival' );
			$payment_status   = (string) ( $old_booking->payment_status ?? 'pending' );
			$payment_received = (int) ( $old_booking->payment_received ?? 0 );
			$deposit_received = (int) ( $old_booking->deposit_received ?? 0 );
			$rem_diff         = $total_money_val->subtract( $final_pay_m );
			$remaining_balance_str = $rem_diff->isPositive() ? $rem_diff->toDecimal() : '0.00';
			if ( $rem_diff->isPositive() ) {
				$payment_received = 0;
				if ( in_array( $payment_status, array( 'completed', 'deposit_paid', 'partially_paid' ), true ) ) {
					$payment_status = $final_pay_m->isPositive()
						? ( $is_deposit_booking_edit ? 'deposit_paid' : 'partially_paid' )
						: 'pending';
				}
				if ( ! $is_deposit_booking_edit ) {
					$deposit_received = 0;
				}
			}
		} else {
			if ( ! $can_issue_refunds && 'refunded' === $payment_status && 'refunded' !== (string) ( $old_booking->payment_status ?? '' ) ) {
				$payment_status = (string) ( $old_booking->payment_status ?? 'pending' );
			}

			$submitted_pay_raw = isset( $_POST['payment_amount'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['payment_amount'] ) ) : '';
			$has_submitted_pay = '' !== $submitted_pay_raw;
			$submitted_pay_m   = $has_submitted_pay ? Money::fromDecimal( $submitted_pay_raw, $calc_curr ) : Money::fromCents( 0, $calc_curr );

			if ( 0 !== $payment_received ) {
				// Admin explicitly checked "Mark full payment as received"
				if ( $submitted_pay_m->greaterThanOrEqual( $total_money_val ) && $total_money_val->isPositive() ) {
					$final_pay_m = $submitted_pay_m;
				} else {
					$final_pay_m = $total_money_val;
				}
			} elseif ( 0 !== $deposit_received ) {
				// Admin explicitly checked "Mark full deposit as received"
				if ( $submitted_pay_m->isPositive() && $submitted_pay_m->lessThan( $total_money_val ) ) {
					$final_pay_m = $submitted_pay_m;
				} else {
					$final_pay_m = $dep_edit_m;
				}
			} elseif ( $has_submitted_pay ) {
				// Admin manually entered a specific payment amount
				$final_pay_m = $submitted_pay_m;
			} else {
				$final_pay_m = $old_pay_m;
			}

			if ( ! $can_issue_refunds && $final_pay_m->lessThan( $old_pay_m ) ) {
				$final_pay_m = $old_pay_m;
			}

			// Continuous Billing & Post-Settlement Logic (Law 59: Pure Money comparisons)
			if ( $final_pay_m->compare( $total_money_val ) >= 0 ) {
				// Paid in full or overpaid
				$remaining_balance_str = '0.00';
				$payment_received      = 1;
				$deposit_received      = 0;
				if ( in_array( $payment_status, array( 'pending', 'deposit_paid', 'partially_paid' ), true ) ) {
					$payment_status = 'completed';
				}
				if ( 'pending' === $new_status ) {
					$new_status = 'confirmed';
				}
			} else {
				// Extension or added charges: outstanding balance exists
				$rem_diff              = $total_money_val->subtract( $final_pay_m );
				$remaining_balance_str = $rem_diff->isPositive() ? $rem_diff->toDecimal() : '0.00';
				$payment_received      = 0;
				if ( $is_deposit_booking_edit && ( 0 !== $deposit_received || $final_pay_m->isPositive() ) ) {
					$deposit_received = 1;
					if ( in_array( $payment_status, array( 'pending', 'completed', 'partially_paid' ), true ) ) {
						$payment_status = 'deposit_paid';
					}
					if ( 'pending' === $new_status ) {
						$new_status = 'confirmed';
					}
				} elseif ( $final_pay_m->isPositive() ) {
					$deposit_received = 0;
					if ( in_array( $payment_status, array( 'pending', 'completed', 'deposit_paid' ), true ) ) {
						$payment_status = 'partially_paid';
					}
					if ( 'pending' === $new_status ) {
						$new_status = 'confirmed';
					}
				} else {
					$deposit_received = 0;
					if ( in_array( $payment_status, array( 'completed', 'deposit_paid', 'partially_paid' ), true ) ) {
						$payment_status = 'pending';
					}
				}
			}

			// Stage ledger adjustment to commit AFTER room lock + availability check + DB update succeed.
			$existing_ledger_txns  = class_exists( Pricing::class ) ? Pricing::get_payment_ledger( $old_booking ) : array();
			$has_ledger_txns       = ! empty( $existing_ledger_txns );
			$should_record_initial = ! $has_ledger_txns && $final_pay_m->isPositive() && ( 1 === $payment_received || 1 === $deposit_received );

			$pay_diff_m = $final_pay_m->subtract( $old_pay_m );
			if ( ( ! $pay_diff_m->isZero() || $should_record_initial ) && class_exists( Pricing::class ) ) {
				$current_user = wp_get_current_user();
				$staff_label  = ( $current_user && $current_user->exists() ) ? $current_user->display_name : 'Staff';
				$rec_amount_m = ( $should_record_initial && $pay_diff_m->isZero() ) ? $final_pay_m : $pay_diff_m;
				if ( $rec_amount_m->isPositive() ) {
					$pending_ledger_adj = array(
						'amount' => $rec_amount_m->toDecimal(),
						'type'   => ( 1 === $payment_received ) ? 'balance' : ( $is_deposit_booking_edit ? 'deposit' : 'payment' ),
						'note'   => 'Manual Payment Adjustment',
						'staff'  => 'Staff: ' . $staff_label,
					);
				} elseif ( $can_issue_refunds && $rec_amount_m->isNegative() ) {
					$pending_ledger_adj = array(
						'amount' => $rec_amount_m->abs()->toDecimal(),
						'type'   => 'refund',
						'note'   => 'Manual Refund / Adjustment',
						'staff'  => 'Staff: ' . $staff_label,
					);
				}
			}
		}

		// Concurrency Guard (Law 5): acquire MySQL advisory lock when changing room or stay dates.
		$needs_room_lock = $dates_changed || ( $room_id !== $old_room_id );
		$lock_acquired   = false;
		if ( $needs_room_lock && $room_id > 0 && class_exists( Pricing::class ) ) {
			$lock_acquired = Pricing::acquire_booking_lock( $room_id, 10 );
			if ( ! $lock_acquired ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'This room is currently being booked or modified by another request. Please try again.', 'modern-hotel-booking' ) . '</p></div>';
				$edit_mode = true;
				return;
			}
		}

		try {
			// Availability Check (excluding current booking).
			$available = Pricing::is_room_available( $room_id, $check_in, $check_out, $booking_id );
			if ( true !== $available ) {
				$err_msg = is_string( $available ) ? I18n::get_label( $available ) : I18n::get_label( 'error_room_unavailable' );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err_msg ) . '</p></div>';
				$edit_mode = true;
				return;
			}

			$preserve_financial_ledger = ! $can_override_pricing && ! $can_override_tax && ! $pricing_inputs_changed;
			$update_booking_fields     = array(
				'customer_name'           => $customer_name,
				'customer_email'          => $customer_email,
				'customer_phone'          => $customer_phone,
				'guest_id'                => $target_guest_id > 0 ? $target_guest_id : null,
				'guest_rating'            => $guest_rating,
				'room_id'                 => $room_id,
				'check_in'                => $check_in,
				'check_out'               => $check_out,
				'total_price'             => $total_money_val->toDecimal(),
				'remaining_balance'       => $remaining_balance_str,
				'balance_expected_amount' => $remaining_balance_str,
				'balance_status'          => ( $final_pay_m->compare( $total_money_val ) >= 0 ) ? 'collected' : 'pending',
				'discount_amount'         => $edit_coupon_disc_m->toDecimal(),
				'deposit_amount'          => $dep_edit_m->toDecimal(),
				'deposit_received'        => $deposit_received,
				'payment_method'          => $payment_method,
				'payment_status'          => 0 !== $payment_received ? 'completed' : $payment_status,
				'payment_received'        => $payment_received,
				'payment_amount'          => $final_pay_m->toDecimal(),
				'payment_date'            => ( 0 !== $payment_received && ! $was_payment_received ) ? HotelTime::now_mysql() : $existing_payment_date,
				'status'                  => $new_status,
				'booking_language'        => $booking_language,
				'admin_notes'             => $admin_notes,
				'booking_extras'          => ! empty( $booking_extras ) ? wp_json_encode( $booking_extras ) : null,
				'guests'                  => $guests,

'custom_fields'           => ! empty( $mhbo_custom ) ? wp_json_encode( $mhbo_custom ) : null,
				'tax_enabled'             => $preserve_financial_ledger ? (int) ( $old_booking->tax_enabled ?? 0 ) : ( ( $tax_data && $tax_data['enabled'] ) ? 1 : 0 ),
				'tax_mode'                => $preserve_financial_ledger ? (string) ( $old_booking->tax_mode ?? $effective_tax_mode ) : $effective_tax_mode,
				'tax_rate_accommodation'  => $preserve_financial_ledger ? ( $old_booking->tax_rate_accommodation ?? 0 ) : ( $tax_data['rates']['accommodation'] ?? 0 ),
				'tax_rate_extras'         => $preserve_financial_ledger ? ( $old_booking->tax_rate_extras ?? 0 ) : ( $tax_data['rates']['extras'] ?? 0 ),
				'room_total_net'          => $preserve_financial_ledger ? ( $old_booking->room_total_net ?? 0 ) : ( ( isset( $tax_data['totals']['room_net'] ) && $tax_data['totals']['room_net'] instanceof Money ) ? $tax_data['totals']['room_net']->toDecimal() : 0 ),
				'room_tax'                => $preserve_financial_ledger ? ( $old_booking->room_tax ?? 0 ) : ( ( isset( $tax_data['totals']['room_tax'] ) && $tax_data['totals']['room_tax'] instanceof Money ) ? $tax_data['totals']['room_tax']->toDecimal() : 0 ),
				'children_total_net'      => $preserve_financial_ledger ? ( $old_booking->children_total_net ?? 0 ) : ( ( isset( $tax_data['totals']['children_net'] ) && $tax_data['totals']['children_net'] instanceof Money ) ? $tax_data['totals']['children_net']->toDecimal() : 0 ),
				'children_tax'            => $preserve_financial_ledger ? ( $old_booking->children_tax ?? 0 ) : ( ( isset( $tax_data['totals']['children_tax'] ) && $tax_data['totals']['children_tax'] instanceof Money ) ? $tax_data['totals']['children_tax']->toDecimal() : 0 ),
				'extras_total_net'        => $preserve_financial_ledger ? ( $old_booking->extras_total_net ?? 0 ) : ( ( isset( $tax_data['totals']['extras_net'] ) && $tax_data['totals']['extras_net'] instanceof Money ) ? $tax_data['totals']['extras_net']->toDecimal() : 0 ),
				'extras_tax'              => $preserve_financial_ledger ? ( $old_booking->extras_tax ?? 0 ) : ( ( isset( $tax_data['totals']['extras_tax'] ) && $tax_data['totals']['extras_tax'] instanceof Money ) ? $tax_data['totals']['extras_tax']->toDecimal() : 0 ),
				'service_fee_amount'      => $preserve_financial_ledger ? ( $old_booking->service_fee_amount ?? '0.00' ) : ( ( isset( $calc['service_fee'] ) && $calc['service_fee'] instanceof Money && $calc['service_fee']->isPositive() ) ? $calc['service_fee']->toDecimal() : '0.00' ),
				'service_fee_net'         => $preserve_financial_ledger ? ( $old_booking->service_fee_net ?? '0.00' ) : ( ( isset( $tax_data['totals']['service_fee_net'] ) && $tax_data['totals']['service_fee_net'] instanceof Money ) ? $tax_data['totals']['service_fee_net']->toDecimal() : '0.00' ),
				'service_fee_tax'         => $preserve_financial_ledger ? ( $old_booking->service_fee_tax ?? '0.00' ) : ( ( isset( $tax_data['totals']['service_fee_tax'] ) && $tax_data['totals']['service_fee_tax'] instanceof Money ) ? $tax_data['totals']['service_fee_tax']->toDecimal() : '0.00' ),
				'subtotal_net'            => $preserve_financial_ledger ? ( $old_booking->subtotal_net ?? $total_price_edit ) : ( ( isset( $tax_data['totals']['subtotal_net'] ) && $tax_data['totals']['subtotal_net'] instanceof Money ) ? $tax_data['totals']['subtotal_net']->toDecimal() : $total_price_edit ),
				'total_tax'               => $preserve_financial_ledger ? ( $old_booking->total_tax ?? 0 ) : ( ( isset( $tax_data['totals']['total_tax'] ) && $tax_data['totals']['total_tax'] instanceof Money ) ? $tax_data['totals']['total_tax']->toDecimal() : 0 ),
				'total_gross'             => $preserve_financial_ledger ? ( $old_booking->total_gross ?? $total_price_edit ) : ( ( isset( $tax_data['totals']['total_gross'] ) && $tax_data['totals']['total_gross'] instanceof Money ) ? $tax_data['totals']['total_gross']->toDecimal() : $total_price_edit ),
				'tax_breakdown'           => $preserve_financial_ledger ? ( $old_booking->tax_breakdown ?? null ) : ( $tax_data ? wp_json_encode( $tax_data ) : null ),
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating booking record in custom table.
			$wpdb->update(
				$tb,
				$update_booking_fields,
				array( 'id' => $booking_id )
			);

			if ( is_array( $pending_ledger_adj ) && class_exists( Pricing::class ) ) {
				Pricing::record_payment(
					$booking_id,
					$pending_ledger_adj['amount'],
					$payment_method,
					$pending_ledger_adj['type'],
					'',
					$pending_ledger_adj['note'],
					$pending_ledger_adj['staff']
				);
			}

			if ( class_exists( GuestAsyncTasks::class ) ) {
				if ( $target_guest_id > 0 ) {
					GuestAsyncTasks::queue_stats_recalculation( $target_guest_id );
				}
				if ( $old_booking && ! empty( $old_booking->guest_id ) && (int) $old_booking->guest_id !== (int) $target_guest_id ) {
					GuestAsyncTasks::queue_stats_recalculation( (int) $old_booking->guest_id );
				}
			}

// Invalidate booking, date-range & payment caches BEFORE firing email hooks so templates read fresh DB data.
			Cache::invalidate_booking( $booking_id, $room_id );
			if ( $old_room_id > 0 && $old_room_id !== $room_id ) {
				Cache::invalidate_booking( $booking_id, $old_room_id );
			}
			if ( $dates_changed || ( $old_room_id > 0 && $old_room_id !== $room_id ) ) {
				Cache::invalidate_date_range( $old_check_in, $old_check_out, $old_room_id > 0 ? $old_room_id : $room_id );
				Cache::invalidate_date_range( $check_in, $check_out, $room_id );
			}
			Pricing::clear_payment_amounts_cache();

			if ( $dates_changed ) {
				$bseq = (int) get_option( 'mhbo_bseq_' . $booking_id, 0 );
				update_option( 'mhbo_bseq_' . $booking_id, $bseq + 1, false );
				do_action( 'mhbo_booking_dates_updated', $booking_id, $check_in, $check_out );
			}

			$status_email_dispatched = false;
			if ( $old_status !== $new_status ) {
				// Send email notification when status changes
				do_action( 'mhbo_booking_status_changed', $booking_id, $new_status );
				if ( 'confirmed' === $new_status ) {
					do_action( 'mhbo_booking_confirmed', $booking_id );
					$status_email_dispatched = ( 0 === (int) ( $old_booking->email_sent ?? 0 ) );
				} elseif ( 'cancelled' === $new_status ) {
					do_action( 'mhbo_booking_cancelled', $booking_id );
					$status_email_dispatched = true;
				}
			}

			if (
				$dates_changed
				&& ! $status_email_dispatched
				&& in_array( $new_status, array( 'pending', 'confirmed', 'checked_in' ), true )
				&& class_exists( Email::class )
			) {
				Email::send_stay_dates_updated_email( $booking_id, $old_check_in, $old_check_out, $old_total_str );
			}

			// Invalidate dashboard statistics transients
			$today_date = wp_date( 'Y-m-d' );
			delete_transient( 'mhbo_widget_total_bookings' );
			delete_transient( 'mhbo_widget_pending_bookings' );
			delete_transient( 'mhbo_widget_today_bookings_' . $today_date );
			delete_transient( 'mhbo_dashboard_total_bookings' );
			delete_transient( 'mhbo_dashboard_pending_bookings' );
			delete_transient( 'mhbo_dashboard_stats_' . $today_date );

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_booking_updated' ) ) . '</p></div>';
			$edit_mode = false;
		} finally {
			if ( $lock_acquired && class_exists( Pricing::class ) ) {
				Pricing::release_booking_lock( $room_id );
			}
		}
	}

	/**
	 * Build normalized FullCalendar events, room map, and occupancy full-days list.
	 *
	 * @param array<int, object> $all_rooms         Physical room records.
	 * @param array<int, object> $calendar_bookings Active/upcoming booking rows for the calendar.
	 * @param bool               $pro_enabled       Whether Pro features are active.
	 * @return array{events: list<array<string, mixed>>, distinct_rooms: array<string, string|null>, full_days: list<string>, total_rooms: int}
	 */
	private function build_bookings_calendar_data( array $all_rooms, array $calendar_bookings, bool $pro_enabled ): array {
		global $wpdb;

		/** @var list<array<string, mixed>> $events */
		$events               = array();
		$seen_calendar_events = array();
		$occupancy            = array();
		$distinct_rooms       = array();
		$prevent_turnover     = ( class_exists( Tier::class ) && Tier::has_feature( 'prevent_same_day_turnover' ) ) && (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1;

		foreach ( $all_rooms as $r ) {
			if ( isset( $r->room_number ) && $r->room_number !== '' ) {
				$distinct_rooms[ (string) $r->room_number ] = ! empty( $r->type_name ) ? I18n::decode( $r->type_name ) : __( 'General Rooms', 'modern-hotel-booking' );
			}
		}

		$is_crm_active = class_exists( GuestManager::class ) && GuestManager::is_enabled();

		if ( $is_crm_active && ! empty( $calendar_bookings ) ) {
			$mismatched_emails = array();
			foreach ( $calendar_bookings as $b ) {
				$c_email = strtolower( trim( (string) ( $b->customer_email ?? '' ) ) );
				if ( '' === $c_email ) {
					continue;
				}
				$g_email = strtolower( trim( (string) ( $b->guest_email ?? '' ) ) );
				if ( empty( $b->guest_id ) || ( '' !== $g_email && $c_email !== $g_email ) ) {
					$mismatched_emails[ $c_email ] = true;
				}
			}

			if ( ! empty( $mismatched_emails ) ) {
				$matched_guests = GuestManager::get_active_guests_by_emails( array_keys( $mismatched_emails ) );

				$email_to_guest = array();
				if ( ! empty( $matched_guests ) ) {
					foreach ( $matched_guests as $mg ) {
						$e1 = strtolower( trim( (string) $mg->email_normalized ) );
						$e2 = strtolower( trim( (string) $mg->email ) );
						if ( '' !== $e1 && ! isset( $email_to_guest[ $e1 ] ) ) {
							$email_to_guest[ $e1 ] = $mg;
						}
						if ( '' !== $e2 && ! isset( $email_to_guest[ $e2 ] ) ) {
							$email_to_guest[ $e2 ] = $mg;
						}
					}
				}

				foreach ( $calendar_bookings as $b ) {
					$c_email = strtolower( trim( (string) ( $b->customer_email ?? '' ) ) );
					if ( '' === $c_email ) {
						continue;
					}
					$g_email = strtolower( trim( (string) ( $b->guest_email ?? '' ) ) );
					if ( empty( $b->guest_id ) || ( '' !== $g_email && $c_email !== $g_email ) ) {
						$true_guest = $email_to_guest[ $c_email ] ?? null;
						if ( $true_guest ) {
							$old_gid               = (int) ( $b->guest_id ?? 0 );
							$new_gid               = (int) $true_guest->id;
							$b->guest_id           = $new_gid;
							$b->guest_vip_status   = $true_guest->vip_status;
							$b->guest_caution_flag = $true_guest->caution_flag;

							if ( $old_gid !== $new_gid ) {
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
								$wpdb->update(
									$wpdb->prefix . 'mhbo_bookings',
									array( 'guest_id' => $new_gid ),
									array( 'id' => (int) $b->id )
								);
								Cache::invalidate_booking( (int) $b->id );
							}
						} elseif ( empty( $b->guest_id ) && class_exists( GuestIdentityResolver::class ) ) {
							$resolved_gid = GuestIdentityResolver::resolve_and_link_guest(
								array(
									'customer_name'  => $b->customer_name ?? '',
									'customer_email' => $b->customer_email,
									'customer_phone' => $b->customer_phone ?? '',
								)
							);
							if ( $resolved_gid > 0 ) {
								$b->guest_id = $resolved_gid;
								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
								$wpdb->update(
									$wpdb->prefix . 'mhbo_bookings',
									array( 'guest_id' => $resolved_gid ),
									array( 'id' => (int) $b->id )
								);
								Cache::invalidate_booking( (int) $b->id );
							}
						}
					}
				}
			}
		}

		foreach ( $calendar_bookings as $b ) {
			if ( 'cancelled' === $b->status ) {
				continue;
			}

			$room_key = (string) ( $b->room_number ?? '' );
			if ( '' !== $room_key ) {
				if ( empty( $distinct_rooms[ $room_key ] ) || '1' === (string) $distinct_rooms[ $room_key ] ) {
					$distinct_rooms[ $room_key ] = ! empty( $b->room_type ) ? I18n::decode( $b->room_type ) : __( 'General Rooms', 'modern-hotel-booking' );
				}
			}

			$current_time = strtotime( $b->check_in );
			// If prevent_turnover is enabled, include check_out date as blocked for same-day turnover
			$end_time = $prevent_turnover ? strtotime( $b->check_out . ' +1 day' ) : strtotime( $b->check_out );

			while ( $current_time < $end_time ) {
				$date_str = gmdate( 'Y-m-d', $current_time );
				if ( ! isset( $occupancy[ $date_str ] ) ) {
					$occupancy[ $date_str ] = array();
				}
				$occupancy[ $date_str ][ $room_key ] = true;
				$current_time                        = strtotime( '+1 day', $current_time );
			}

			// Deduplicate calendar events defensively to prevent identical stacked bars.
			$event_dedup_key = $b->room_number . '|' . $b->check_in . '|' . $b->check_out . '|' . $b->customer_name;
			if ( isset( $seen_calendar_events[ $event_dedup_key ] ) ) {
				continue;
			}
			$seen_calendar_events[ $event_dedup_key ] = true;

			// FullCalendar treats all-day 'end' dates as exclusive [start, end).
			// Hotel reservation dates span [check_in, check_out) covering the nights of the stay.
			$ts_in       = strtotime( $b->check_in );
			$ts_out      = strtotime( $b->check_out );
			$ts_next_day = strtotime( $b->check_in . ' +1 day' );
			$end_date    = ( false !== $ts_in && false !== $ts_out && $ts_out > $ts_in )
				? $b->check_out
				: ( false !== $ts_next_day ? gmdate( 'Y-m-d', $ts_next_day ) : $b->check_in );

			$nights_count = max( 1, (int) round( ( strtotime( $b->check_out ) - strtotime( $b->check_in ) ) / DAY_IN_SECONDS ) );

			$crm_url = ( $is_crm_active && ! empty( $b->guest_id ) )
				? admin_url( 'admin.php?page=mhbo-guests&guest_id=' . (int) $b->guest_id )
				: '';

			$cal_curr     = Pricing::get_currency_code();
			$rem_bal_m    = Money::fromDecimal( (string) ( $b->remaining_balance ?? 0 ), $cal_curr );
			$total_pr_m   = Money::fromDecimal( (string) ( $b->total_price ?? 0 ), $cal_curr );
			$balance_link = '';

$extended_props = array(
				'room_number'         => $room_key,
				'room_type'           => I18n::decode( $b->room_type ?? '' ),
				'room_sort'           => is_numeric( $b->room_number ?? '' ) ? (int) $b->room_number : $room_key,
				'booking_id'          => (int) $b->id,
				'customer'            => $b->customer_name,
				'email'               => $b->customer_email ?? '',
				'phone'               => $b->customer_phone ?? '',
				'check_in'            => $b->check_in,
				'check_out'           => $b->check_out,
				'check_in_formatted'  => date_i18n( get_option( 'date_format' ), strtotime( $b->check_in ) ),
				'check_out_formatted' => date_i18n( get_option( 'date_format' ), strtotime( $b->check_out ) ),
				'nights'              => $nights_count,
				'guests'              => (int) ( $b->guests ?? 1 ),
				'children'            => (int) ( $b->children ?? 0 ),
				'total_price'         => I18n::format_currency( $total_pr_m ),
				'status'              => I18n::translate_status( $b->status ),
				'status_key'          => $b->status,
				'vip_tier'            => ! empty( $b->guest_vip_status ) && 'none' !== $b->guest_vip_status ? strtoupper( str_replace( '_', ' ', $b->guest_vip_status ) ) : '',
				'caution_flag'        => ! empty( $b->guest_caution_flag ),
				'crm_url'             => $crm_url,
				'balance_url'         => $balance_link,
				'remaining_balance'   => $rem_bal_m->isPositive() ? I18n::format_currency( $rem_bal_m ) : '',
			);

			// Check if this booking checks out on a Sunday (w === '0')
			$check_out_ts    = strtotime( $b->check_out );
			$is_sun_checkout = ( false !== $check_out_ts && '0' === gmdate( 'w', $check_out_ts ) );

			$main_props = $extended_props;
			if ( $is_sun_checkout ) {
				$main_props['ends_on_saturday'] = true;
			}

			$events[] = array(
				'title'         => 'Room ' . $b->room_number . ' - ' . $b->customer_name,
				'start'         => $b->check_in,
				'end'           => $end_date,
				'color'         => 'confirmed' === $b->status ? '#059669' : '#d97706',
				'className'     => 'mhbo-status-' . $b->status . ( $is_sun_checkout ? ' mhbo-event-saturday-end' : '' ),
				'url'           => html_entity_decode( wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . $b->id ), 'mhbo_edit_booking_' . $b->id ) ),
				'extendedProps' => $main_props,
			);

			// If checking out on Sunday, inject companion morning checkout capsule on Sunday
			if ( $is_sun_checkout && false !== $check_out_ts ) {
				$sun_out_props                       = $extended_props;
				$sun_out_props['is_sunday_checkout'] = true;
				$ts_sun_next                         = strtotime( $b->check_out . ' +1 day' );
				$next_sun_day                        = false !== $ts_sun_next ? gmdate( 'Y-m-d', $ts_sun_next ) : $b->check_out;

				$events[] = array(
					'id'            => 'out-' . $b->id,
					'title'         => 'Room ' . $b->room_number . ' - ' . $b->customer_name,
					'start'         => $b->check_out,
					'end'           => $next_sun_day,
					'color'         => 'confirmed' === $b->status ? '#059669' : '#d97706',
					'className'     => 'mhbo-status-' . $b->status . ' mhbo-sunday-checkout',
					'url'           => html_entity_decode( wp_nonce_url( admin_url( 'admin.php?page=mhbo-bookings&action=edit&id=' . $b->id ), 'mhbo_edit_booking_' . $b->id ) ),
					'extendedProps' => $sun_out_props,
				);
			}
		}

		$events = $this->sort_calendar_events( $events );

		$total_rooms = count( $distinct_rooms );
		$full_days   = array();
		if ( $total_rooms > 0 ) {
			foreach ( $occupancy as $date => $rooms ) {
				if ( count( $rooms ) >= $total_rooms ) {
					$full_days[] = (string) $date;
				}
			}
		}

		return array(
			'events'         => $events,
			'distinct_rooms' => $distinct_rooms,
			'full_days'      => $full_days,
			'total_rooms'    => $total_rooms,
		);
	}

	/**
	 * Sort FullCalendar events strictly by room number then start date.
	 *
	 * @param list<array<string, mixed>> $events Unsorted FullCalendar event items.
	 * @return list<array<string, mixed>> Sorted event items.
	 */
	private function sort_calendar_events( array $events ): array {
		usort(
			$events,
			static function ( array $a, array $b ): int {
				/** @var array<string, mixed> $propsA */
				$propsA = is_array( $a['extendedProps'] ?? null ) ? $a['extendedProps'] : array();
				/** @var array<string, mixed> $propsB */
				$propsB = is_array( $b['extendedProps'] ?? null ) ? $b['extendedProps'] : array();
				$rA     = $propsA['room_sort'] ?? '';
				$rB     = $propsB['room_sort'] ?? '';
				$startA = (string) ( $a['start'] ?? '' );
				$startB = (string) ( $b['start'] ?? '' );
				if ( $rA === $rB ) {
					// Put Sunday checkout capsules first so they render under checkins on the same day
					$isOutA = ! empty( $propsA['is_sunday_checkout'] );
					$isOutB = ! empty( $propsB['is_sunday_checkout'] );
					if ( $startA === $startB && $isOutA !== $isOutB ) {
						return $isOutA ? -1 : 1;
					}
					return strcmp( $startA, $startB );
				}
				if ( is_numeric( $rA ) && is_numeric( $rB ) ) {
					return (float) $rA <=> (float) $rB;
				}
				return strnatcasecmp( (string) $rA, (string) $rB );
			}
		);

		return $events;
	}
}
