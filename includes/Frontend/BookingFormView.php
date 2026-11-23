<?php declare(strict_types=1);

namespace MHBO\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Tax;
use MHBO\Core\Tier;

use MHBO\Core\Security;
use MHBO\Core\Cache;
use MHBO\Core\HotelTime;
use MHBO\Core\Capabilities;

/**
 * Booking Form & Confirmation View Renderer
 *
 * Handles rendering of search results, interactive booking forms,
 * confirmation panels, itemized folio receipts, and room grids.
 */
class BookingFormView {

	/**
	 * Parent Shortcode facade instance for URL and asset helpers.
	 *
	 * @var Shortcode
	 */
	private Shortcode $shortcode;

	/**
	 * Constructor.
	 *
	 * @param Shortcode|null $shortcode Parent Shortcode facade instance.
	 */
	public function __construct( ?Shortcode $shortcode = null ) {
		$this->shortcode = $shortcode ?? new Shortcode();
	}

	/**
	 * Render the booking confirmation panel as a string for the inline modal.
	 * Looks up the booking by its unguessable token — no nonce required.
	 *
	 * @param string $token  The booking_token (reference) value.
	 * @param string $status mhbo_status: 'confirmed', 'pending', or 'failed'.
	 * @return string HTML string, or empty string if token is invalid.
	 */
	public function render_confirmation_html( string $token, string $status ): string {
		if ( '' === $token ) {
			return '';
		}

		global $wpdb;

		$cache_key = 'mhbo_booking_ref_' . md5( $token );
		$booking   = wp_cache_get( $cache_key, 'mhbo_bookings' );

		if ( false === $booking ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$booking = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE booking_token = %s",
					$token
				)
			);
			if ( $booking ) {
				wp_cache_set( $cache_key, $booking, 'mhbo_bookings', 300 );
			}
		}

		if ( null === $booking || '' === (string) ( $booking->booking_token ?? '' ) || ! hash_equals( (string) $booking->booking_token, $token ) ) {
			return '';
		}

		$msg_title    = I18n::get_label( 'msg_booking_confirmed' );
		$msg_detail   = I18n::get_label( 'msg_confirmation_sent' );
		$status_class = 'mhbo-status-confirmed';
		$icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';

		if ( 'pending' === $status ) {
			$msg_title    = I18n::get_label( 'msg_booking_confirmed_received' );
			$msg_detail   = I18n::get_label( 'msg_booking_received_pending' );
			$status_class = 'mhbo-status-pending';
			$icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
		} elseif ( 'failed' === $status ) {
			$msg_title    = I18n::get_label( 'label_failed' );
			$msg_detail   = I18n::get_label( 'label_payment_capture_failed' );
			$status_class = 'mhbo-status-failed';
			$icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
		}

		if ( '' !== (string) ( $booking->customer_email ?? '' ) ) {
			$email_label = ( 'pending' === $status ) ? 'msg_pending_sent_to' : 'msg_confirmation_sent_to';
			$msg_detail  = sprintf(
				// translators: %s: Customer email address
				I18n::get_label( $email_label ),
				'<strong>' . esc_html( $booking->customer_email ) . '</strong>'
			);
		}

		if ( 'arrival' === $booking->payment_method && 'pending' !== $status ) {
			$msg_detail = I18n::get_label( 'msg_booking_received_detail' );
		}

		$booking_id     = (int) $booking->id;
		$check_in_time  = HotelTime::get_checkin_time();
		$check_out_time = HotelTime::get_checkout_time();

$nights = 0;

		try {
			$start  = new \DateTime( $booking->check_in );
			$end    = new \DateTime( $booking->check_out );
			$nights = $start->diff( $end )->days;
		} catch ( \Exception ) {
			$nights = 0;
		}

		$nights_label = ( $nights === 1 )
			? I18n::get_label( 'label_nights_count_single' )
			: sprintf( I18n::get_label( 'label_nights_count' ), $nights );

		ob_start();
		?>
		<div class="mhbo-success-message <?php echo esc_attr( $status_class ); ?>">
			<div class="mhbo-success-icon"><?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is hardcoded ?></div>
			<h3><?php echo esc_html( $msg_title ); ?></h3>
			<p><?php echo wp_kses_post( $msg_detail ); ?></p>
			<p class="mhbo-reservation-id"><strong><?php echo esc_html( I18n::get_label( 'label_reservation' ) ); ?>:</strong> <?php echo esc_html( (string) $booking_id ); ?></p>

			<div class="mhbo-stay-details">
				<div class="mhbo-stay-row">
					<div class="mhbo-stay-col">
						<strong><?php echo esc_html( I18n::get_label( 'label_check_in' ) ); ?></strong>
						<div class="mhbo-stay-value">
							<span class="mhbo-stay-date"><?php echo esc_html( I18n::format_date( $booking->check_in ) ); ?></span>
							<span class="mhbo-stay-label"><?php echo esc_html( sprintf( I18n::get_label( 'label_check_in_from' ), '' ) ); ?></span>
							<span class="mhbo-stay-time"><?php echo esc_html( $check_in_time ); ?></span>
						</div>
					</div>
					<div class="mhbo-stay-col">
						<strong><?php echo esc_html( I18n::get_label( 'label_check_out' ) ); ?></strong>
						<div class="mhbo-stay-value">
							<span class="mhbo-stay-date"><?php echo esc_html( I18n::format_date( $booking->check_out ) ); ?></span>
							<span class="mhbo-stay-label"><?php echo esc_html( sprintf( I18n::get_label( 'label_check_out_by' ), '' ) ); ?></span>
							<span class="mhbo-stay-time"><?php echo esc_html( $check_out_time ); ?></span>
						</div>
					</div>
					<div class="mhbo-stay-col">
						<strong><?php echo esc_html( I18n::get_label( 'label_nights' ) ); ?></strong>
						<div class="mhbo-stay-value"><?php echo esc_html( (string) $nights_label ); ?></div>
					</div>
				</div>
			</div>

			<?php echo self::render_confirmation_receipt_details( $booking ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is safely constructed and escaped within render_confirmation_receipt_details() ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the search form (unified view).
	 *
	 * @param int       $room_id    Optional room ID to focus on.
	 * @param int       $type_id    Optional room type ID pre-selection filter.
	 * @param bool|null $show_title Optional header title visibility override.
	 * @param bool|null $show_badge Optional header badge visibility override.
	 * @return void
	 */
	public function render_search_form( int $room_id = 0, int $type_id = 0, ?bool $show_title = null, ?bool $show_badge = null ): void {
		// Unified Calendar View replacing the old search form
		// Delegates to the centralized Calendar renderer
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Calendar::render_unified_view returns pre-escaped HTML from internal components
		echo Calendar::render_unified_view( $room_id, $show_title, $type_id, $show_badge, true );
	}

	/**
	 * Render search results based on provided parameters.
	 *
	 * @param int    $room_id_filter Room ID filter.
	 * @param string $check_in       Check-in date (Y-m-d).
	 * @param string $check_out      Check-out date (Y-m-d).
	 * @param int    $guests         Number of adult guests.
	 * @param int    $type_id_filter Room type ID filter.
	 * @param int    $children       Number of children.
	 * @return void
	 */
	public function render_search_results( int $room_id_filter = 0, string $check_in = '', string $check_out = '', int $guests = 1, int $type_id_filter = 0, int $children = 0 ): void {
		global $wpdb;

		// Ensure we have minimal valid data (Already verified if from POST, or sanitized if from GET)
		if ( '' === $check_in || '' === $check_out ) {
			$this->render_search_form( $room_id_filter, $type_id_filter );
			return;
		}

		// Date Validation
		$today = HotelTime::today();
		if ( $check_in < $today ) {
			echo '<div class="mhbo-error">' . esc_html( I18n::get_label( 'label_check_in_past' ) ) . '</div>';
			$this->render_search_form( $room_id_filter, $type_id_filter );
			return;
		}
		if ( $check_out <= $check_in ) {
			echo '<div class="mhbo-error">' . esc_html( I18n::get_label( 'label_check_out_after' ) ) . '</div>';
			$this->render_search_form( $room_id_filter, $type_id_filter );
			return;
		}
		if ( ! HotelTime::is_within_advance_booking_window( $check_in ) ) {
			$max_window_human = HotelTime::format_advance_window_human( HotelTime::get_max_advance_booking_days() );
			echo '<div class="mhbo-error">' . esc_html( sprintf( I18n::get_label( 'label_advance_limit_exceeded' ), $max_window_human ) ) . '</div>';
			$this->render_search_form( $room_id_filter, $type_id_filter );
			return;
		}

		// $room_id_filter is now passed as an argument, no longer fall back to POST here

		$query_args = array();
		$sql        = "SELECT r.id, r.type_id, r.room_number, r.status, r.custom_price,
                       r.image_url as room_image_url, t.image_url as type_image_url,
                       t.name as type_name, t.base_price, t.max_adults, t.max_children,
                       t.description as description, t.amenities as amenities,
                       t.description as type_description, t.amenities as type_amenities
                FROM {$wpdb->prefix}mhbo_rooms r
                JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
                WHERE r.status = 'available'";

		if ( 0 !== $room_id_filter ) {
			$sql         .= ' AND r.id = %d';
			$query_args[] = $room_id_filter;
		}

		if ( 0 !== $type_id_filter ) {
			$sql         .= ' AND r.type_id = %d';
			$query_args[] = $type_id_filter;
		}

		// Same-day Turnover & Active Pending-Payment Cutoff
		$prevent_same_day = ( class_exists( Tier::class ) && Tier::has_feature( 'prevent_same_day_turnover' ) ) && (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1;
		$pending_cutoff   = Pricing::get_pending_payment_cutoff();

		// Industry-standard overlap logic (dynamically settings-aware across all tiers)
		if ( $prevent_same_day ) {
			$sql .= " AND r.id NOT IN ( 
                        SELECT room_id FROM {$wpdb->prefix}mhbo_bookings 
                        WHERE (check_in <= DATE(%s) AND check_out >= DATE(%s))
                        AND status NOT IN ('cancelled', 'refunded', 'failed')
                        AND (status != 'pending_payment' OR COALESCE(payment_status, 'pending') != 'pending' OR COALESCE(deposit_received, 0) = 1 OR COALESCE(payment_received, 0) = 1 OR COALESCE(payment_amount, 0) > 0 OR created_at >= %s)
                        AND room_id IS NOT NULL 
                        AND room_id > 0
                    )";
		} else {
			$sql .= " AND r.id NOT IN ( 
                        SELECT room_id FROM {$wpdb->prefix}mhbo_bookings 
                        WHERE (check_in < DATE(%s) AND check_out > DATE(%s))
                        AND status NOT IN ('cancelled', 'refunded', 'failed')
                        AND (status != 'pending_payment' OR COALESCE(payment_status, 'pending') != 'pending' OR COALESCE(deposit_received, 0) = 1 OR COALESCE(payment_received, 0) = 1 OR COALESCE(payment_amount, 0) > 0 OR created_at >= %s)
                        AND room_id IS NOT NULL 
                        AND room_id > 0
                    )";
		}

		$query_args[] = $check_out;
		$query_args[] = $check_in;
		$query_args[] = $pending_cutoff;

		// Implement versioned caching for search results (Rule 13 / 2026 BP)
		$version_hash = Cache::get_version( Cache::TABLE_BOOKINGS ) . '_' .
						Cache::get_version( Cache::TABLE_ROOMS ) . '_' .
						Cache::get_version( Cache::TABLE_PRICING_RULES ) . '_' .
						substr( $pending_cutoff, 0, 16 );
		$cache_key    = 'mhbo_avail_rooms_v7_' . md5( $sql . wp_json_encode( $query_args ) . $version_hash );
		$all_rooms    = wp_cache_get( $cache_key, 'mhbo_bookings' );

		if ( false === $all_rooms ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: High-performance room availability search across custom relational tables. Query is safely assembled using $wpdb->prepare placeholders
			$all_rooms = $wpdb->get_results( $wpdb->prepare( $sql, ...$query_args ) );
			if ( ! is_array( $all_rooms ) ) {
				$all_rooms = array();
			}
			wp_cache_set( $cache_key, $all_rooms, 'mhbo_bookings', 300 );
		}

		// Stay restrictions & Pro calendar overrides filter (Global min/max stay active in Free/Starter/Pro)
		if ( array() !== $all_rooms ) {
			$room_ids_to_prime = array_values( array_unique( array_map( static fn( $r ): int => (int) ( $r->id ?? 0 ), $all_rooms ) ) );
			if ( array() !== $room_ids_to_prime ) {
				Pricing::prime_room_cache( $room_ids_to_prime );
				
			}
			try {
				$start_date        = new \DateTime( $check_in );
				$end_date_limit    = new \DateTime( $check_out );
				$nights_for_filter = (int) $start_date->diff( $end_date_limit )->format( '%a' );

				$stay_dates = array();

$all_rooms = array_values(
					array_filter(
						$all_rooms,
						function ( $room ) use ( $check_in, $check_out, $stay_dates, $nights_for_filter ) {
							$rid = (int) $room->id;

if ( Pricing::is_closed_to_arrival( $rid, $check_in ) || Pricing::is_closed_to_departure( $rid, $check_out ) ) {
								return false;
							}

							$eff_min = Pricing::get_effective_min_stay( $rid, $check_in );
							$eff_max = Pricing::get_effective_max_stay( $rid, $check_in, $check_out );
							if ( null !== $eff_min && $nights_for_filter < $eff_min ) {
								return false;
							}
							if ( null !== $eff_max && $nights_for_filter > $eff_max ) {
								return false;
							}
							return true;
						}
					)
				);
			} catch ( \Exception ) {
				$all_rooms = (array) $all_rooms;
			}
		}

		// Deduplicate by room type ID. We only need to show one result per room type.
		// Doing this AFTER Pro filtering ensures that if Room A is blocked but Room B (same type) is free, the type still shows.
		$available_rooms = array();
		$seen_types      = array();
		if ( is_array( $all_rooms ) && array() !== $all_rooms ) {
			foreach ( $all_rooms as $room ) {
				if ( ! isset( $seen_types[ $room->type_id ] ) ) {
					$available_rooms[]            = $room;
					$seen_types[ $room->type_id ] = true;
				}
			}
		}

		echo '<h3>' . esc_html( sprintf( I18n::get_label( 'label_available_rooms' ), $check_in, $check_out ) ) . '</h3>';
		if ( ! is_array( $available_rooms ) || array() === $available_rooms ) {
			echo '<p>' . esc_html( I18n::get_label( 'label_no_rooms' ) ) . '</p>';
			$this->render_search_form();
			return;
		}

		echo '<div class="mhbo-rooms-grid">';
		foreach ( $available_rooms as $room ) {
			$start_date = new \DateTime( $check_in );
			$end_date   = new \DateTime( $check_out );
			$interval   = new \DateInterval( 'P1D' );
			$period     = new \DatePeriod( $start_date, $interval, $end_date );

			$currency = Pricing::get_currency_code();
			$total    = Money::fromCents( 0, $currency );
			foreach ( $period as $dt ) {
				$date_str = $dt->format( 'Y-m-d' );
				$total    = $total->add( Pricing::calculate_daily_price_money( (int) $room->id, $date_str ) );
			}

			$days = iterator_count( $period );
			// Average price per night calculated with Money BCMath division
			$avg_price_money = $days > 0 ? $total->divide( $days ) : Money::fromDecimal( (string) ( $room->custom_price ?: $room->base_price ), $total->getCurrency() );

			$amenities = $room->amenities ? json_decode( $room->amenities ) : array();

			// Room-specific image takes priority; falls back to room type image.
			$resolved_img = ( '' !== (string) ( $room->room_image_url ?? '' ) ) ? $room->room_image_url : ( $room->type_image_url ?? '' );
			$img_style    = '';
			if ( '' !== (string) $resolved_img ) {
				$img_style = 'background:url(' . esc_url( $resolved_img ) . ') center/cover;';
			} else {
				$img_style = 'background: linear-gradient(135deg, var(--mhbo-primary) 0%, var(--mhbo-accent) 100%); opacity: 0.8;';
			}

			$type_display = Calendar::get_room_type_display_settings( (int) ( $room->type_id ?? 0 ) );
			$card_title_on = $type_display['show_title'] && (int) get_option( 'mhbo_room_cards_show_title', 1 ) === 1;
			$card_badge_on = $type_display['show_badge'] && (int) get_option( 'mhbo_room_cards_show_badge', 1 ) === 1;

			echo '<div class="mhbo-room-card">';
			echo '<div class="mhbo-room-image" style="height:200px; ' . esc_attr( (string) $img_style ) . '"></div>';
			echo '<div class="mhbo-room-content">';
			if ( $card_title_on ) {
				echo '<h4 class="mhbo-room-title">' . esc_html( (string) I18n::decode( $room->type_name ) ) . '</h4>';
			}
			if ( $card_badge_on ) {
				echo '<div style="margin-bottom:10px;"><span class="mhbo-calendar-room-badge mhbo-room-card-badge">' . esc_html( sprintf( I18n::get_label( 'label_max_guests' ), $room->max_adults ) ) . '</span></div>';
			}

			$desc = I18n::decode( $room->description ?: ( $room->type_description ?? '' ) );
			if ( '' !== $desc ) {
				echo '<p class="mhbo-room-description" style="font-size:0.9rem; color:#666; margin-bottom:15px;">' . esc_html( wp_trim_words( (string) $desc, 20 ) ) . '</p>';
			}

			echo '<div class="mhbo-room-price">' . esc_html( I18n::format_currency( $avg_price_money ) ) . ' <span>' . esc_html( I18n::get_label( 'label_per_night' ) ) . '</span></div>';

			$amenities_raw = $room->amenities ?: ( $room->type_amenities ?? '[]' );
			$amenities     = $amenities_raw ? json_decode( (string) $amenities_raw ) : array();

			if ( is_array( $amenities ) && array() !== $amenities ) {
				echo '<div class="mhbo-amenities" style="margin-bottom:10px; font-size:0.85rem; color:#666;">';
				foreach ( $amenities as $am ) {
					echo '<span style="display:inline-block; background:#eee; padding:2px 8px; border-radius:12px; margin-right:5px; margin-bottom:5px;">' . esc_html( ucfirst( (string) I18n::decode( $am ) ) ) . '</span>';
				}
				echo '</div>';
			}

			$total_nights_label = ( 1 === $days ) ? I18n::get_label( 'label_total_night_single' ) : I18n::get_label( 'label_total_nights' );
			echo '<div class="mhbo-room-details">';
			echo wp_kses_post( sprintf( $total_nights_label, $days, '<strong>' . esc_html( I18n::format_currency( $total ) ) . '</strong>' ) );
			echo '<p>' . esc_html( sprintf( I18n::get_label( 'label_max_guests' ), $room->max_adults ) ) . '</p>';
			echo '</div>';

			// Fix: If no specific room_id was requested (category search), use the available $room->id found by the query.
			// This prevents "Room 1" from being hardcoded into all results if the user is on the Room 1 page.
			$assigned_room_id = ( $room_id_filter > 0 && (int) $room->id === (int) $room_id_filter ) ? $room_id_filter : $room->id;

			$checkout_url = $this->shortcode->get_booking_page_url();
			// Clean URL to prevent parameter pollution/duplication in the GET form
			$clean_checkout_url = $this->shortcode->remove_mhbo_query_args( $checkout_url );
			
			echo '<form method="get" action="' . esc_url( $clean_checkout_url ) . '">';
			Shortcode::render_url_hidden_inputs( $clean_checkout_url );
			
			// Critical: Add the auto-book flag and correct nonce for GET-based processing
			echo '<input type="hidden" name="mhbo_auto_book" value="1">';
			echo '<input type="hidden" name="mhbo_nonce" value="' . esc_attr( wp_create_nonce( 'mhbo_auto_action' ) ) . '">';
			
			echo '<input type="hidden" name="check_in" value="' . esc_attr( $check_in ) . '">';
			echo '<input type="hidden" name="check_out" value="' . esc_attr( $check_out ) . '">';
			echo '<input type="hidden" name="room_id" value="' . esc_attr( (string) $assigned_room_id ) . '">';
			echo '<input type="hidden" name="type_id" value="' . esc_attr( (string) $room->type_id ) . '">';
			echo '<input type="hidden" name="guests" value="' . esc_attr( (string) max( 1, $guests ) ) . '">';
			echo '<input type="hidden" name="children" value="' . esc_attr( (string) max( 0, $children ) ) . '">';
			echo '<input type="hidden" name="total_price" value="' . esc_attr( (string) $total->toDecimal() ) . '">';
			
			echo '<button type="submit" class="mhbo-btn">' . esc_html( I18n::get_label( 'btn_book_now' ) ) . '</button>';
			echo '</form></div></div>';
		}
		echo '</div>';
	}

	/**
	 * Render the final booking details and customer info form.
	 *
	 * @param array<string, mixed> $params Booking parameters (room_id, dates, etc.).
	 * @return void
	 */
	public function render_booking_form( array $params = array() ): void {
		global $wpdb;

		// Read booking data exclusively from params (verified in handle_booking_process)
		$room_id       = isset( $params['room_id'] ) ? intval( $params['room_id'] ) : 0;
		$type_id       = isset( $params['type_id'] ) ? intval( $params['type_id'] ) : 0;
		$check_in      = isset( $params['check_in'] ) ? sanitize_text_field( $params['check_in'] ) : '';
		$check_out     = isset( $params['check_out'] ) ? sanitize_text_field( $params['check_out'] ) : '';
		$guests        = isset( $params['guests'] ) ? intval( $params['guests'] ) : 2;
		$currency_code = Pricing::get_currency_code();
		$total_hint    = ( isset( $params['total_price'] ) && $params['total_price'] instanceof Money ) ? $params['total_price'] : Money::fromDecimal( (string) ( $params['total_price'] ?? '0' ), $currency_code );
		
		// Customer Details (for re-population)
		$customer_name  = isset( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : '';
		$customer_email = isset( $params['customer_email'] ) ? sanitize_email( $params['customer_email'] ) : '';
		$customer_phone = isset( $params['customer_phone'] ) ? sanitize_text_field( $params['customer_phone'] ) : '';
		$admin_notes    = isset( $params['admin_notes'] ) ? sanitize_textarea_field( $params['admin_notes'] ) : '';

		$exclude_id       = isset( $params['exclude_id'] ) ? intval( $params['exclude_id'] ) : 0;
		$update_id        = isset( $params['mhbo_update_id'] ) ? intval( $params['mhbo_update_id'] ) : 0;
		$caller_url_token = isset( $_GET['booking_token'] ) ? sanitize_text_field( wp_unslash( $_GET['booking_token'] ) ) : ( isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ( isset( $params['booking_token'] ) ? sanitize_text_field( (string) $params['booking_token'] ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$auto_nonce       = isset( $params['mhbo_nonce'] ) ? sanitize_key( (string) $params['mhbo_nonce'] ) : ( isset( $_GET['mhbo_nonce'] ) ? sanitize_key( wp_unslash( $_GET['mhbo_nonce'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sess_tok         = isset( $params['mhbo_session_token'] ) ? sanitize_text_field( (string) $params['mhbo_session_token'] ) : ( isset( $_GET['session_token'] ) ? sanitize_text_field( wp_unslash( $_GET['session_token'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cookie_sid       = \MHBO\Core\Privacy::get_cookie_session_id();

		$verify_target_ownership = static function ( int $target_id ) use ( $wpdb, $caller_url_token, $auto_nonce, $sess_tok, $cookie_sid ): ?object {
			if ( $target_id <= 0 ) {
				return null;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, booking_token FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $target_id ) );
			if ( ! $row ) {
				return null;
			}
			if ( current_user_can( Capabilities::MANAGE_LHBO ) ) {
				return $row;
			}
			if ( '' !== $caller_url_token && '' !== (string) $row->booking_token && hash_equals( (string) $row->booking_token, $caller_url_token ) ) {
				return $row;
			}
			if ( '' !== $auto_nonce && wp_verify_nonce( $auto_nonce, 'mhbo_auto_action' ) ) {
				$resume_meta = get_transient( 'mhbo_resume_nonce_' . $auto_nonce );
				if ( is_array( $resume_meta ) && (int) ( $resume_meta['booking_id'] ?? 0 ) === $target_id ) {
					return $row;
				}
			}
			if ( '' !== $sess_tok ) {
				$s_meta = get_transient( 'mhbo_booking_session_' . $sess_tok );
				if ( ! is_array( $s_meta ) ) {
					$s_meta = get_transient( 'mhbo_session_' . $sess_tok );
				}
				if ( is_array( $s_meta ) && (int) ( $s_meta['booking_id'] ?? 0 ) === $target_id ) {
					$bound = (string) ( $s_meta['session_id'] ?? '' );
					if ( '' === $bound || ( '' !== $cookie_sid && hash_equals( $bound, $cookie_sid ) ) ) {
						return $row;
					}
				}
			}
			if ( '' !== $cookie_sid && (int) get_transient( 'mhbo_session_pending_booking_' . $cookie_sid ) === $target_id ) {
				return $row;
			}
			return null;
		};

		if ( $update_id > 0 ) {
			$existing_row = $verify_target_ownership( $update_id );
			if ( ! $existing_row || 'pending_payment' !== $existing_row->status ) {
				$update_id = 0;
			}
		}
		if ( $exclude_id > 0 && null === $verify_target_ownership( $exclude_id ) ) {
			$exclude_id = 0;
		}

		if ( '' !== $check_in && ! HotelTime::is_within_advance_booking_window( $check_in ) ) {
			$max_window_human = HotelTime::format_advance_window_human( HotelTime::get_max_advance_booking_days() );
			echo '<div class="mhbo-error">' . esc_html( sprintf( I18n::get_label( 'label_advance_limit_exceeded' ), $max_window_human ) ) . '</div>';
			if ( empty( $params['modal_context'] ) ) {
				$this->render_search_form( $room_id, $type_id );
			}
			return;
		}

		// Resolve room_id from type_id if room_id is 0 or if the specified room_id fails availability or stay/CTA/CTD rules
		if ( $type_id > 0 ) {
			$is_specified_avail = ( $room_id > 0 ) ? Pricing::is_room_available( (int) $room_id, $check_in, $check_out, $exclude_id ) : false;
			$needs_reassignment = ( true !== $is_specified_avail );
			if ( ! $needs_reassignment && $room_id > 0 && '' !== $check_in && '' !== $check_out ) {
				$spec_nights = (int) ( new \DateTime( $check_in ) )->diff( new \DateTime( $check_out ) )->format( '%a' );
				$spec_min    = Pricing::get_effective_min_stay( $room_id, $check_in );
				$spec_max    = Pricing::get_effective_max_stay( $room_id, $check_in, $check_out );
				if (
					Pricing::is_closed_to_arrival( $room_id, $check_in ) ||
					Pricing::is_closed_to_departure( $room_id, $check_out ) ||
					( null !== $spec_min && $spec_nights < $spec_min ) ||
					( null !== $spec_max && $spec_nights > $spec_max )
				) {
					$needs_reassignment = true;
				}
			}
			if ( $needs_reassignment ) {
				$resolved_room = Pricing::find_available_room( $type_id, $check_in, $check_out, $guests );
				if ( false !== $resolved_room && 0 !== $resolved_room ) {
					$room_id = (int) $resolved_room;
				}
			}
		}

		// Check availability before rendering booking form.
		$available = Pricing::is_room_available( (int) $room_id, $check_in, $check_out, $exclude_id );

		if ( true !== $available ) {
			$error_key = is_string( $available ) && '' !== $available ? $available : 'error_room_unavailable';
			echo '<div class="mhbo-error mhbo-availability-error">' .
				esc_html( I18n::get_label( $error_key ) ) .
				'</div>';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $_GET['mhbo_auto_book'] ) && empty( $params['modal_context'] ) ) {
				$this->render_search_form( $room_id, $type_id );
			}
			return;
		}

		if ( $room_id > 0 && '' !== $check_in && '' !== $check_out ) {
			if ( Pricing::is_closed_to_arrival( $room_id, $check_in ) ) {
				echo '<div class="mhbo-error">' . esc_html( I18n::get_label( 'api_err_cta' ) ) . '</div>';
				if ( empty( $params['modal_context'] ) ) {
					$this->render_search_form( $room_id, $type_id );
				}
				return;
			}
			if ( Pricing::is_closed_to_departure( $room_id, $check_out ) ) {
				echo '<div class="mhbo-error">' . esc_html( I18n::get_label( 'api_err_ctd' ) ) . '</div>';
				if ( empty( $params['modal_context'] ) ) {
					$this->render_search_form( $room_id, $type_id );
				}
				return;
			}

			$nights_count = (int) ( new \DateTime( $check_in ) )->diff( new \DateTime( $check_out ) )->format( '%a' );
			$eff_min      = Pricing::get_effective_min_stay( $room_id, $check_in );
			$eff_max      = Pricing::get_effective_max_stay( $room_id, $check_in, $check_out );

			if ( null !== $eff_min && $nights_count < $eff_min ) {
				echo '<div class="mhbo-error">' . esc_html( sprintf( I18n::get_label( 'api_err_min_stay' ), $eff_min ) ) . '</div>';
				if ( empty( $params['modal_context'] ) ) {
					$this->render_search_form( $room_id, $type_id );
				}
				return;
			}
			if ( null !== $eff_max && $nights_count > $eff_max ) {
				echo '<div class="mhbo-error">' . esc_html( sprintf( I18n::get_label( 'api_err_max_stay' ), $eff_max ) ) . '</div>';
				if ( empty( $params['modal_context'] ) ) {
					$this->render_search_form( $room_id, $type_id );
				}
				return;
			}
		}

		$cache_key = 'mhbo_room_details_' . md5( (string) $room_id );
		$room      = wp_cache_get( $cache_key, 'mhbo_bookings' );
		if ( false === $room ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
			$room = $wpdb->get_row( $wpdb->prepare( "SELECT t.id as type_id, t.image_url as type_image_url, r.image_url as room_image_url, t.name as type_name, t.base_price, t.max_adults, t.max_children, t.child_rate, t.child_age_free_limit, r.room_number, r.custom_price FROM {$wpdb->prefix}mhbo_rooms r JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id WHERE r.id = %d", $room_id ) );
			if ( null !== $room ) {
				wp_cache_set( $cache_key, $room, 'mhbo_bookings', 300 );
			}
		}

		$room_type_id = $room ? $room->type_id : $type_id;

		// Validate room exists before rendering form
		if ( null === $room ) {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<div class="mhbo-setup-notice" style="background:#fff8e5; border-left:4px solid #dba617; padding:18px 20px; border-radius:6px; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; margin:20px 0; color:#1d2327;">' .
					'<h4 style="margin:0 0 8px 0; font-size:16px; color:#1d2327;">⚠️ ' . sprintf(
						// translators: %d: ID of the room that was not found.
						esc_html__( 'Room ID %d Not Found', 'modern-hotel-booking' ),
						absint( $room_id )
					) . '</h4>' .
					'<p style="margin:0 0 12px 0; font-size:14px; color:#50575e;">' . esc_html__( 'No room matching this ID was found in the database. Please verify your room configuration in the dashboard.', 'modern-hotel-booking' ) . '</p>' .
					'<a href="' . esc_url( admin_url( 'admin.php?page=mhbo-rooms' ) ) . '" style="background:#2271b1; color:#fff; padding:6px 14px; text-decoration:none; border-radius:3px; font-size:13px; display:inline-block;">' . esc_html__( 'Manage Rooms in Admin →', 'modern-hotel-booking' ) . '</a>' .
				'</div>';
			} else {
				echo '<div class="mhbo-guest-notice" style="padding:20px; text-align:center; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; color:#4b5563;">' .
					'<p style="margin:0; font-size:15px;">' . esc_html( I18n::get_label( 'label_room_not_found' ) ) . '</p>' .
				'</div>';
			}
			if ( empty( $params['modal_context'] ) ) {
				$this->render_search_form();
			}
			return;
		}

		$resolved_room_img = ( $room && $room->room_image_url ) ? $room->room_image_url : ( ( $room && $room->type_image_url ) ? $room->type_image_url : '' );
		$image_url         = $resolved_room_img ? esc_url( $resolved_room_img ) : '';
		$summary_flags     = Calendar::resolve_calendar_display_flags( (int) $room_id, (int) $room_type_id, null, null, false );
		$decoded_type_title = $room ? trim( (string) I18n::decode( (string) $room->type_name ) ) : '';
		$room_name         = '' !== $decoded_type_title ? $decoded_type_title : ( $room ? (string) $room->room_number : I18n::get_label( 'label_room' ) );
		$total             = $total_hint; // Set total to Money object from hint

		// Always recalculate on render to ensure we have the full $calc breakdown for display
		$calc_guests     = $guests;
		$calc_children   = isset( $params['children'] ) ? intval( $params['children'] ) : 0;
		$calc_child_ages = isset( $params['child_ages'] ) ? Pricing::sanitize_child_ages( $params['child_ages'] ) : array();
		$calc_extras     = isset( $params['extras'] ) ? array_map( 'sanitize_text_field', (array) $params['extras'] ) : array();

		$calc = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, $calc_guests, $calc_extras, $calc_children, $calc_child_ages );
		
		$is_invalid_occupancy = ( false === $calc );
		if ( $is_invalid_occupancy ) {
			echo '<div class="mhbo-error">' . esc_html( _x( 'Over-occupancy: Please check guest limits for this room.', 'error message', 'modern-hotel-booking' ) ) . '</div>';
			$total = $total_hint;
		} else {
			$total = $calc['total'];
		}

		$is_pro_active = false;

$deposit_data = null;
		if ( MHBO_IS_PRO && get_option( 'mhbo_deposits_enabled', 0 ) ) {
			$currency          = $total->getCurrency();
			$first_night_money = Pricing::resolve_first_night_money( $room_id, $check_in, $calc_guests, null, $calc_extras, $calc_children, $calc_child_ages, $currency );
			// Store in $calc so render_deposit_selection_html uses the correct first-night amount.
			if ( is_array( $calc ) ) {
				$calc['first_night_total'] = $first_night_money;
			}
			$deposit_data = Pricing::calculate_deposit_money( $total, $first_night_money );
		}

		?>
		<div class="mhbo-booking-wrapper">
				<div class="mhbo-booking-summary">
					<h3 class="mhbo-summary-title"><?php echo esc_html( I18n::get_label( 'label_booking_summary' ) ); ?></h3>
					
					<?php if ( '' !== $image_url || $summary_flags['show_title'] || $summary_flags['show_badge'] ) : ?>
					<div class="mhbo-summary-content" style="display:flex; align-items:center; gap:16px; margin-bottom:24px;">
						<?php if ( '' !== $image_url ) : ?>
							<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( I18n::get_label( 'label_room_alt_text' ) ); ?>" style="width:100px; height:70px; object-fit:cover; border-radius:8px; flex-shrink:0;">
						<?php endif; ?>
						<?php if ( $summary_flags['show_title'] || $summary_flags['show_badge'] ) : ?>
						<div>
							<?php if ( $summary_flags['show_title'] ) : ?>
								<strong style="color:var(--mhbo-primary); font-size:1.15rem; display:block; margin-bottom:6px;"><?php echo esc_html( $room_name ); ?></strong>
							<?php endif; ?>
							<?php if ( $summary_flags['show_badge'] && '' !== trim( (string) $room->room_number ) ) : ?>
								<span class="mhbo-room-number-badge"><?php echo esc_html( I18n::get_label( 'label_room_number' ) ); ?> <?php echo esc_html( $room->room_number ); ?></span>
							<?php endif; ?>
						</div>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<?php
					$ci_date          = \DateTime::createFromFormat( 'Y-m-d', $check_in );
					$co_date          = \DateTime::createFromFormat( 'Y-m-d', $check_out );
					$nights           = ( $ci_date && $co_date ) ? max( 1, $ci_date->diff( $co_date )->days ) : 1;
					$ci_std_time      = HotelTime::get_checkin_time();
					$co_std_time      = HotelTime::get_checkout_time();
					$ci_time          = $ci_std_time;
					$co_time          = $co_std_time;
					$ec_time_attr     = '';
					$lc_time_attr     = '';
					$checkin_shifted  = false;
					$shift_notice_txt = '';
					$stay_timing      = null;

$ec_preselected  = ! empty( $calc_extras['_early_checkin'] ) && '' !== $ec_time_attr && ( ! is_array( $stay_timing ) || ! empty( $stay_timing['early_checkin']['available'] ) );
					$lc_preselected  = ! empty( $calc_extras['_late_checkout'] ) && '' !== $lc_time_attr && ( ! is_array( $stay_timing ) || ! empty( $stay_timing['late_checkout']['available'] ) );
					$ci_display_time = $ec_preselected ? $ec_time_attr : $ci_time;
					$co_display_time = $lc_preselected ? $lc_time_attr : $co_time;
					?>
					
					<div class="mhbo-booking-dates">
						<div class="mhbo-booking-dates-col">
							<div class="mhbo-date-title"><?php echo esc_html( __( 'CHECK IN', 'modern-hotel-booking' ) ); ?></div>
							<div class="mhbo-date-val"><?php echo esc_html( $ci_date ? $ci_date->format( 'M j, Y' ) : $check_in ); ?></div>
							<div class="mhbo-stay-time"><span class="mhbo-stay-time-prefix"><?php echo esc_html( _x( 'FROM', 'Check-in time prefix', 'modern-hotel-booking' ) ); ?></span> <span class="mhbo-checkin-time-val" data-standard="<?php echo esc_attr( $ci_time ); ?>" data-base-standard="<?php echo esc_attr( $ci_std_time ); ?>"<?php echo '' !== $ec_time_attr ? ' data-early="' . esc_attr( $ec_time_attr ) . '"' : ''; ?>><?php echo esc_html( $ci_display_time ); ?></span></div>
							<div class="mhbo-checkin-shift-notice"<?php echo $checkin_shifted ? '' : ' hidden'; ?>><?php echo esc_html( $shift_notice_txt ); ?></div>
						</div>
						<div class="mhbo-booking-dates-col">
							<div class="mhbo-date-title"><?php echo esc_html( __( 'CHECK-OUT', 'modern-hotel-booking' ) ); ?></div>
							<div class="mhbo-date-val"><?php echo esc_html( $co_date ? $co_date->format( 'M j, Y' ) : $check_out ); ?></div>
							<div class="mhbo-stay-time"><span class="mhbo-stay-time-prefix"><?php echo esc_html( _x( 'BY', 'Check-out time prefix', 'modern-hotel-booking' ) ); ?></span> <span class="mhbo-checkout-time-val" data-standard="<?php echo esc_attr( $co_time ); ?>"<?php echo '' !== $lc_time_attr ? ' data-late="' . esc_attr( $lc_time_attr ) . '"' : ''; ?>><?php echo esc_html( $co_display_time ); ?></span></div>
						</div>
						<div class="mhbo-booking-dates-nights">
							<div class="mhbo-date-title"><?php echo esc_html( __( 'NIGHTS', 'modern-hotel-booking' ) ); ?></div>
							<div class="mhbo-nights-count"><?php echo esc_html( (string) (int) $nights ); ?></div>
							<div class="mhbo-nights-label"><?php echo esc_html( I18n::get_label( 'label_nights' ) ); ?></div>
						</div>
					</div>

					<div class="mhbo-summary-grid">
						<div class="mhbo-summary-header">
							<span><?php echo esc_html( _x( 'Item', 'cart item', 'modern-hotel-booking' ) ); ?></span>
							<span><?php echo esc_html( __( 'Amount', 'modern-hotel-booking' ) ); ?></span>
						</div>
						
						<?php
						$room_total_init = ( $calc && isset( $calc['room_total'] ) && $calc['room_total'] instanceof Money ) ? $calc['room_total'] : $total;
						?>
						<div class="mhbo-summary-row mhbo-room-cost-row">
							<span><?php echo esc_html( _x( 'Room', 'accommodation unit', 'modern-hotel-booking' ) ); ?></span>
							<span class="mhbo-room-total-display"><?php echo esc_html( I18n::format_currency( $room_total_init ) ); ?></span>
						</div>

<div class="mhbo-extras-summary-rows">
							<?php
							if ( $calc && ! empty( $calc['extras_breakdown'] ) ) {
								foreach ( $calc['extras_breakdown'] as $ex_item ) {
									$ex_name  = (string) I18n::decode( (string) ( $ex_item['name'] ?? '' ) );
									$ex_total = isset( $ex_item['total'] ) && $ex_item['total'] instanceof Money ? $ex_item['total']->format() : I18n::format_currency( $ex_item['total'] ?? $ex_item['price'] ?? 0 );
									echo '<div class="mhbo-summary-row mhbo-extra-summary-row" data-extra-id="' . esc_attr( (string) $ex_item['id'] ) . '">';
									echo '<span>' . esc_html( $ex_name ) . '</span>';
									echo '<span class="mhbo-extra-item-total">' . esc_html( $ex_total ) . '</span>';
									echo '</div>';
								}
							}
							?>
						</div>

<div class="mhbo-summary-divider"></div>
						
						<div class="mhbo-summary-total">
							<span><?php echo esc_html( I18n::get_label( 'label_total' ) ); ?></span>
							<span class="mhbo-display-total"
								data-base-total="<?php echo esc_attr( (string) $total->toDecimal() ); ?>"
								data-currency-symbol="<?php echo esc_attr( get_option( 'mhbo_currency_symbol', '$' ) ); ?>"
								data-currency-pos="<?php echo esc_attr( get_option( 'mhbo_currency_position', 'before' ) ); ?>">
								<?php echo esc_html( I18n::format_currency( $total ) ); ?>
							</span>
						</div>
					</div>
				</div>
			<h2 class="mhbo-form-heading"><?php echo esc_html( I18n::get_label( 'label_complete_booking' ) ); ?></h2>
			<form method="post" class="mhbo-booking-form" id="mhbo-booking-form">
				<?php wp_nonce_field( 'mhbo_confirm_action', 'mhbo_confirm_nonce' ); ?>
				<?php
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is rigorously verified in the process_payment flow.
					$url_token      = isset( $_GET['booking_token'] ) ? sanitize_text_field( wp_unslash( $_GET['booking_token'] ) ) : '';
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Querying custom mhbo_bookings table; token fetched only after ownership verification.
					$existing_token = ( $update_id > 0 ) ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT booking_token FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $update_id ) ) : '';
					$final_token    = $existing_token ?: $url_token;
				?>
				<input type="hidden" name="mhbo_booking_token" value="<?php echo esc_attr( $final_token ); ?>">
				<?php if ( '' !== $auto_nonce ) : ?>
					<input type="hidden" name="mhbo_resume_nonce" value="<?php echo esc_attr( $auto_nonce ); ?>">
				<?php endif; ?>
				<!-- Hidden field so JS form.submit() includes the booking action -->
				<input type="hidden" name="mhbo_confirm_booking" value="1">
				<input type="hidden" name="mhbo_room_id" value="<?php echo esc_attr( (string) $room_id ); ?>">
				<input type="hidden" name="mhbo_exclude_id" value="<?php echo esc_attr( (string) $exclude_id ); ?>">
				<input type="hidden" name="mhbo_update_id" value="<?php echo esc_attr( (string) $update_id ); ?>">
				<input type="hidden" name="mhbo_type_id" value="<?php echo esc_attr( (string) ( $room_type_id ?? 0 ) ); ?>">
				<input type="hidden" name="check_in" value="<?php echo esc_attr( $check_in ); ?>">
				<input type="hidden" name="check_out" value="<?php echo esc_attr( $check_out ); ?>">
				<input type="hidden" name="total_price" value="<?php echo esc_attr( $total->toDecimal() ); ?>">
				<input type="hidden" name="booking_language" value="<?php echo esc_attr( I18n::get_current_language() ); ?>">
				<input type="hidden" name="mhbo_page_url" value="<?php echo esc_attr( get_permalink() ?: ( isset( $_SERVER['REQUEST_URI'] ) ? home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : home_url( '/' ) ) ); ?>">

				<div class="mhbo-form-group"><label><?php echo esc_html( I18n::get_label( 'label_name' ) ); ?> <span
							class="required">*</span></label><input type="text" name="customer_name" value="<?php echo esc_attr( $customer_name ); ?>" required></div>
				<div class="mhbo-form-group"><label><?php echo esc_html( I18n::get_label( 'label_email' ) ); ?> <span
							class="required">*</span></label><input type="email" name="customer_email" value="<?php echo esc_attr( $customer_email ); ?>" required></div>
				<div class="mhbo-form-group">
					<label><?php echo esc_html( I18n::get_label( 'label_guests' ) ); ?> <span class="required">*</span></label>
					<select name="guests" class="mhbo-booking-guests" required>
						<?php
						// Determine max guests (capacity)
						$max_capacity = isset( $room->max_adults ) ? intval( $room->max_adults ) : 2;
						if ( $max_capacity < 1 ) {
							$max_capacity = 2;
						}

						// Use pre-validated $guests parameter
						$selected_guests = $guests;
						if ( $selected_guests > $max_capacity ) {
							$selected_guests = $max_capacity;
						}

						for ( $i = 1; $i <= $max_capacity; $i++ ) {
							echo '<option value="' . esc_attr( (string) $i ) . '" ' . selected( $selected_guests, $i, false ) . '>' . esc_html( (string) $i ) . '</option>';
						}
						?>
					</select>
				</div>

<div class="mhbo-form-group">
					<label><?php echo esc_html( I18n::get_label( 'label_phone' ) ); ?> <span class="required">*</span></label><input
						type="tel" name="customer_phone" value="<?php echo esc_attr( $customer_phone ); ?>" required>
				</div>
				<div class="mhbo-form-group mhbo-notes-form-group">
					<label for="mhbo_admin_notes"><?php echo esc_html( I18n::get_label( 'label_special_requests' ) ); ?></label>
					<textarea id="mhbo_admin_notes" name="admin_notes" rows="3" class="mhbo-admin-notes-textarea" style="width:100%; box-sizing:border-box; max-width:100%;"><?php echo esc_textarea( $admin_notes ); ?></textarea>
				</div>

<?php
				// Note: Honeypot removed for compliance. Security is handled via nonces.
				?>

<?php
				// Render Custom Fields
				$custom_fields = get_option( 'mhbo_custom_fields', array() );
				if ( array() !== $custom_fields ) {
					foreach ( $custom_fields as $field ) {
						$label         = isset( $field['label'] ) ? I18n::decode( I18n::encode( $field['label'] ) ) : $field['id'];
						$required      = ( isset( $field['required'] ) && (bool) $field['required'] ) ? 'required' : '';
						$required_mark = $required ? ' <span class="required">*</span>' : '';

						echo '<div class="mhbo-form-group mhbo-custom-field-group">';
						echo '<label>' . esc_html( $label ) . wp_kses_post( $required_mark ) . '</label>';

						if ( $field['type'] === 'textarea' ) {
							echo '<textarea name="mhbo_custom[' . esc_attr( $field['id'] ) . ']" rows="3" style="width:100%" ' . esc_attr( $required ) . '></textarea>';
						} else {
							$input_type = ( $field['type'] === 'number' ) ? 'number' : 'text';
							echo '<input type="' . esc_attr( $input_type ) . '" name="mhbo_custom[' . esc_attr( $field['id'] ) . ']" ' . esc_attr( $required ) . '>';
						}
						echo '</div>';
					}
				}
				?>

<?php
				// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine
				
				?>

				<?php do_action( 'mhbo_booking_form_after_inputs', $total, $calc ); ?>

				<!-- Inline error notification area for payment/booking errors -->
				<div class="mhbo-booking-errors mhbo-inline-errors" style="display:none;"></div>

				<div class="mhbo-tax-breakdown-container">
					<?php
					// Display the dynamic pricing and tax breakdown from the server calculation
					$show_breakdown = ! Tax::is_enabled() || get_option( 'mhbo_tax_display_frontend', 1 );
					if ( $show_breakdown ) {
						$currency = $total->getCurrency();
						$tax_data = isset( $calc['tax'] ) ? $calc['tax'] : array(
							'enabled' => false,
							'totals'  => array(
								'subtotal_net' => isset( $calc['total'] ) ? $calc['total'] : Money::fromCents( 0, $currency ),
								'total_tax'    => Money::fromCents( 0, $currency ),
								'total_gross'  => isset( $calc['total'] ) ? $calc['total'] : Money::fromCents( 0, $currency ),
							),
						);

						// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine

$init_meta = array(
							'guests'       => $calc_guests,
							'children'     => $calc_children,
							'payment_type' => ( isset( $deposit_data ) && is_array( $deposit_data ) && ! get_option( 'mhbo_deposit_allow_guest_choice', 1 ) ) ? 'deposit' : 'full',
						);
						echo wp_kses_post( Tax::render_breakdown_html( $tax_data, null, false, $init_meta, false ) );
					}
					?>
				</div>
				<?php
				// VAT notes removed from booking page per user request.
				?>

<div class="mhbo-submit-container">
					<button type="submit" name="mhbo_confirm_booking" class="mhbo-btn mhbo-submit-btn">
						<?php echo esc_html( I18n::get_label( 'btn_confirm_booking' ) ); ?>
					</button>
					<div class="mhbo-secure-badge">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
								d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
						</svg>
						<span><?php echo esc_html( I18n::get_label( 'label_secure_payment' ) ); ?></span>
					</div>
				</div>

				<div class="mhbo-mobile-sticky-bar" aria-hidden="true">
					<div class="mhbo-mobile-sticky-bar-info">
						<span class="mhbo-mobile-sticky-bar-label"><?php echo esc_html( I18n::get_label( 'label_total' ) ); ?></span>
						<span class="mhbo-mobile-sticky-bar-total mhbo-display-total"><?php echo esc_html( I18n::format_currency( $total ) ); ?></span>
					</div>
					<button type="button" class="mhbo-mobile-sticky-bar-btn mhbo-mobile-scroll-btn">
						<span><?php echo esc_html( I18n::get_label( 'btn_confirm_booking' ) ); ?></span>
						<svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
							<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.25a.75.75 0 00-1.5 0v4.59L7.3 9.39a.75.75 0 10-1.06 1.06l3.25 3.25a.75.75 0 001.06 0l3.25-3.25a.75.75 0 10-1.06-1.06l-1.95 1.95V6.75z" clip-rule="evenodd" />
						</svg>
					</button>
				</div>

				<?php
				// Note: Booking form JavaScript logic has been moved to assets/js/mhbo-booking-form.js
				// The mhbo_vars configuration is injected via wp_add_inline_script() in enqueue_assets()
				?>

			</form>
		</div>
		<?php
	}

	/**
	 * Render the mhbo_rooms grid shortcode.
	 *
	 * @param array<string, mixed>|string|false $atts Shortcode attributes.
	 * @return string The rendered HTML.
	 */
	public function render_rooms_grid_shortcode( array|string|false $atts ): string {
		// Enqueue styles
		$this->shortcode->ensure_assets_loaded();
		if ( 1 === (int) get_option( 'mhbo_modal_enabled', 1 ) ) {
			Shortcode::enqueue_for_modal();
		}

		$raw_atts = is_array( $atts ) ? $atts : array();
		$atts     = shortcode_atts(
			array(
				'columns'         => 3,
				'limit'           => 6,
				'show_title'      => '',
				'show_badge'      => '',
				'show_card_title' => '',
				'show_card_badge' => '',
			),
			$raw_atts,
			'mhbo_rooms'
		);

		$columns              = absint( is_array( $atts ) && isset( $atts['columns'] ) ? $atts['columns'] : 3 );
		$limit                = absint( is_array( $atts ) && isset( $atts['limit'] ) ? $atts['limit'] : 6 );
		$attr_show_title      = Calendar::parse_optional_bool_attr( $raw_atts, 'show_title' );
		$attr_show_badge      = Calendar::parse_optional_bool_attr( $raw_atts, 'show_badge' );
		$attr_show_card_title = Calendar::parse_optional_bool_attr( $raw_atts, 'show_card_title' );
		$attr_show_card_badge = Calendar::parse_optional_bool_attr( $raw_atts, 'show_card_badge' );

		$eff_grid_title = ( (int) get_option( 'mhbo_rooms_show_title', 1 ) === 1 ) && ( false !== $attr_show_title );
		$eff_grid_badge = ( (int) get_option( 'mhbo_rooms_show_badge', 1 ) === 1 ) && ( false !== $attr_show_badge );
		$global_card_title = ( (int) get_option( 'mhbo_room_cards_show_title', 1 ) === 1 ) && ( false !== $attr_show_card_title );
		$global_card_badge = ( (int) get_option( 'mhbo_room_cards_show_badge', 1 ) === 1 ) && ( false !== $attr_show_card_badge );

		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe interpolations for table names. Limit is safely cast to integer.
		$room_types = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, description, image_url, base_price, max_adults, amenities FROM {$wpdb->prefix}mhbo_room_types WHERE 1=1 ORDER BY id DESC LIMIT %d", $limit ) );

		ob_start();
		echo '<div class="mhbo-wrapper mhbo-room-grid-shortcode" data-columns="' . esc_attr( (string) $columns ) . '">';

		$grid_header_title = I18n::get_label( 'label_rooms_grid_title' );
		$grid_header_badge = I18n::get_label( 'label_rooms_grid_badge' );
		if ( ( $eff_grid_title && '' !== $grid_header_title ) || ( $eff_grid_badge && '' !== $grid_header_badge ) ) {
			echo '<div class="mhbo-calendar-header mhbo-rooms-grid-header">';
			if ( $eff_grid_title && '' !== $grid_header_title ) {
				echo '<h2 class="mhbo-calendar-room-title">' . esc_html( $grid_header_title ) . '</h2>';
			}
			if ( $eff_grid_badge && '' !== $grid_header_badge ) {
				echo '<span class="mhbo-calendar-room-badge">' . esc_html( $grid_header_badge ) . '</span>';
			}
			echo '</div>';
		}

		if ( is_array( $room_types ) && array() !== $room_types ) {
			echo '<div class="mhbo-rooms-grid" style="grid-template-columns: repeat(' . esc_attr( (string) $columns ) . ', 1fr);">';
			foreach ( $room_types as $type ) {
				$type_opts     = Calendar::get_room_type_display_settings( (int) $type->id );
				$show_c_title  = $global_card_title && $type_opts['show_title'];
				$show_c_badge  = $global_card_badge && $type_opts['show_badge'];
				$img_style = '';
				if ( isset( $type->image_url ) && '' !== (string) $type->image_url ) {
					$img_style = 'background:url(' . esc_url( $type->image_url ) . ') center/cover;';
				} else {
					$img_style = 'background: linear-gradient(135deg, var(--mhbo-primary) 0%, var(--mhbo-accent) 100%); opacity: 0.8;';
				}

				echo '<div class="mhbo-room-card">';
				echo '<div class="mhbo-room-image" style="height:200px; ' . esc_attr( (string) $img_style ) . '"></div>';
				echo '<div class="mhbo-room-content">';
				if ( $show_c_title ) {
					echo '<h4 class="mhbo-room-title">' . esc_html( (string) I18n::decode( $type->name ) ) . '</h4>';
				}
				if ( $show_c_badge ) {
					echo '<div style="margin-bottom:10px;"><span class="mhbo-calendar-room-badge mhbo-room-card-badge">' . esc_html( sprintf( I18n::get_label( 'label_max_guests' ), $type->max_adults ) ) . '</span></div>';
				}

				$desc = I18n::decode( $type->description );
				if ( '' !== $desc ) {
					echo '<p class="mhbo-room-description" style="font-size:0.9rem; color:#666; margin-bottom:15px;">' . esc_html( wp_trim_words( (string) $desc, 20 ) ) . '</p>';
				}

				echo '<div class="mhbo-room-price">' . esc_html( I18n::format_currency( $type->base_price ) ) . ' <span>' . esc_html( I18n::get_label( 'label_per_night' ) ) . '</span></div>';

				$amenities = $type->amenities ? json_decode( (string) $type->amenities ) : array();
				if ( is_array( $amenities ) && array() !== $amenities ) {
					echo '<div class="mhbo-amenities" style="margin-bottom:10px; font-size:0.85rem; color:#666;">';
					foreach ( $amenities as $am ) {
						echo '<span style="display:inline-block; background:#eee; padding:2px 8px; border-radius:12px; margin-right:5px; margin-bottom:5px;">' . esc_html( ucfirst( (string) I18n::decode( $am ) ) ) . '</span>';
					}
					echo '</div>';
				}

				echo '<div class="mhbo-room-details">';
				echo '<p>' . esc_html( sprintf( I18n::get_label( 'label_max_guests' ), $type->max_adults ) ) . '</p>';
				echo '</div>';

				$checkout_url       = $this->shortcode->get_booking_page_url();
				$clean_checkout_url = $this->shortcode->remove_mhbo_query_args( $checkout_url );
				
				echo '<form method="get" action="' . esc_url( $clean_checkout_url ) . '">';
				Shortcode::render_url_hidden_inputs( $clean_checkout_url );
				echo '<input type="hidden" name="type_id" value="' . esc_attr( (string) $type->id ) . '">';
				echo '<button type="submit" class="mhbo-btn">' . esc_html( I18n::get_label( 'btn_view_room' ) ) . '</button>';
				echo '</form></div></div>';
			}
			echo '</div>';
		} else {
			echo '<p>' . esc_html( I18n::get_label( 'label_no_rooms' ) ) . '</p>';
		}

		echo '</div>';
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Render the itemized receipt and transaction details on booking confirmation.
	 *
	 * @param object $booking The booking object.
	 * @return string Rendered HTML.
	 */
	public static function render_confirmation_receipt_details( object $booking ): string {
		global $wpdb;
		$currency         = Pricing::get_currency_code();
		$total_price      = Money::fromDecimal( (string) ( $booking->total_price ?? '0' ), $currency );
		$coupon_discount  = Money::fromDecimal( (string) ( $booking->coupon_discount ?? ( $booking->discount_amount ?? '0' ) ), $currency );
		$service_fee_cost = Money::fromDecimal( (string) ( $booking->service_fee_amount ?? '0' ), $currency );
		$extras_breakdown = Pricing::decode_stored_extras( $booking->booking_extras ?? null );

$extras_sum = Money::fromCents( 0, $currency );
		foreach ( $extras_breakdown as $ex ) {
			$amt        = isset( $ex['total'] ) ? (string) $ex['total'] : (string) ( $ex['price'] ?? '0' );
			$extras_sum = $extras_sum->add( Money::fromDecimal( $amt, $currency ) );
		}

		$tax_breakdown_data = null;
		if ( ! empty( $booking->tax_breakdown ) ) {
			$tax_breakdown_data = is_array( $booking->tax_breakdown ) ? $booking->tax_breakdown : json_decode( (string) $booking->tax_breakdown, true );
		} elseif ( ! empty( $booking->id ) && class_exists( Tax::class ) ) {
			$tax_breakdown_data = Tax::get_tax_breakdown( (int) $booking->id );
		}

		$raw_tax_mode  = (string) ( $booking->tax_mode ?? ( is_array( $tax_breakdown_data ) ? ( $tax_breakdown_data['mode'] ?? '' ) : '' ) );
		$tax_mode_norm = class_exists( Tax::class ) ? Tax::normalize_mode( '' !== $raw_tax_mode ? $raw_tax_mode : Tax::get_mode() ) : 'disabled';
		$is_vat_mode   = ( 'vat' === $tax_mode_norm );

		$room_net_m  = Money::fromDecimal( (string) ( $booking->room_total_net ?? '0' ), $currency );
		$room_tax_m  = Money::fromDecimal( (string) ( $booking->room_tax ?? '0' ), $currency );
		$child_net_m = Money::fromDecimal( (string) ( $booking->children_total_net ?? '0' ), $currency );
		$child_tax_m = Money::fromDecimal( (string) ( $booking->children_tax ?? '0' ), $currency );

		$children_cost = ( $is_vat_mode && $child_tax_m->isPositive() ) ? $child_net_m->add( $child_tax_m ) : $child_net_m;
		$room_base_m   = ( $is_vat_mode && $room_tax_m->isPositive() ) ? $room_net_m->add( $room_tax_m ) : $room_net_m;

		$coupon_reds = ( is_array( $tax_breakdown_data ) && isset( $tax_breakdown_data['coupon_reductions'] ) && is_array( $tax_breakdown_data['coupon_reductions'] ) )
			? $tax_breakdown_data['coupon_reductions']
			: null;
		$room_red_m  = null !== $coupon_reds
			? Money::fromDecimal( (string) ( $coupon_reds['room_reduction'] ?? '0' ), $currency )
			: $coupon_discount;
		$child_red_m = null !== $coupon_reds
			? Money::fromDecimal( (string) ( $coupon_reds['children_reduction'] ?? '0' ), $currency )
			: Money::fromCents( 0, $currency );

		if ( $child_red_m->isPositive() ) {
			$children_cost = $children_cost->add( $child_red_m );
		}

		if ( $room_base_m->isPositive() ) {
			$room_cost = $room_base_m->add( $room_red_m );
		} elseif ( $room_red_m->isPositive() ) {
			$room_cost = $room_red_m;
		} else {
			$subtotal_net_m = Money::fromDecimal( (string) ( $booking->subtotal_net ?? '0' ), $currency );
			if ( ! $is_vat_mode && $subtotal_net_m->isPositive() ) {
				$room_cost = $subtotal_net_m
					->add( $coupon_discount )
					->subtract( $children_cost )
					->subtract( $extras_sum )
					->subtract( $service_fee_cost );
			} else {
				$room_cost = $total_price
					->add( $coupon_discount )
					->subtract( $children_cost )
					->subtract( $extras_sum )
					->subtract( $service_fee_cost );
			}
		}
		if ( $room_cost->isNegative() ) {
			$room_cost = Money::fromCents( 0, $currency );
		}

		// Tax breakdown details
		$tax_enabled    = false;
		$formatted_tax  = null;
		$acc_tax_m      = Money::fromCents( 0, $currency );
		$ext_tax_m      = Money::fromCents( 0, $currency );
		$show_split_tax = false;
		$acc_rate_str   = '0';
		$ext_rate_str   = '0';

		if ( class_exists( Tax::class ) && ( Tax::is_enabled() || ! empty( $tax_breakdown_data['enabled'] ) ) ) {
			if ( is_array( $tax_breakdown_data ) && ! empty( $tax_breakdown_data['enabled'] ) ) {
				$tax_meta      = array(
					'guests'   => $booking->guests ?? 1,
					'children' => $booking->children ?? 0,
				);
				$formatted_tax = Tax::format_tax_breakdown( $tax_breakdown_data, null, $tax_meta );
				$tax_enabled   = (bool) ( $formatted_tax['enabled'] ?? false );

				if ( $tax_enabled ) {
					$acc_rate_str = Tax::format_rate( $tax_breakdown_data['rates']['accommodation'] ?? Tax::get_accommodation_rate() );
					$ext_rate_str = Tax::format_rate( $tax_breakdown_data['rates']['extras'] ?? Tax::get_extras_rate() );

					$totals    = $tax_breakdown_data['totals'] ?? array();
					$r_tax_m   = Money::fromDecimal( (string) ( $totals['room_tax'] ?? '0' ), $currency );
					$c_tax_m   = Money::fromDecimal( (string) ( $totals['children_tax'] ?? '0' ), $currency );
					$e_tax_m   = Money::fromDecimal( (string) ( $totals['extras_tax'] ?? '0' ), $currency );
					$sf_tax_m  = Money::fromDecimal( (string) ( $totals['service_fee_tax'] ?? '0' ), $currency );
					$acc_tax_m = isset( $totals['accommodation_tax'] )
						? Money::fromDecimal( (string) $totals['accommodation_tax'], $currency )
						: $r_tax_m->add( $c_tax_m );
					$ext_tax_m = $e_tax_m->add( $sf_tax_m );

					$show_split_tax = ( $acc_rate_str !== $ext_rate_str ) && ( $acc_tax_m->isPositive() || $ext_tax_m->isPositive() );
				}
			}
		}

		// Get Room Type Name
		$room_name = '';
		if ( ! empty( $booking->room_id ) ) {
			$room_id   = (int) $booking->room_id;
			$cache_key = 'mhbo_receipt_room_' . $room_id;
			$room_row  = wp_cache_get( $cache_key, 'mhbo' );

			if ( false === $room_row ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Result cached in object cache above
				$room_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT r.room_number, rt.name as type_name FROM {$wpdb->prefix}mhbo_rooms r LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id WHERE r.id = %d",
						$room_id
					)
				);
				if ( $room_row ) {
					wp_cache_set( $cache_key, $room_row, 'mhbo', 3600 );
				}
			}

			if ( $room_row && isset( $room_row->type_name ) ) {
				$room_name = (string) ( I18n::decode( (string) $room_row->type_name ) ?? '' );
			}
		}
		if ( empty( $room_name ) ) {
			$room_name = _x( 'Room', 'accommodation unit', 'modern-hotel-booking' );
		}

		ob_start();
		?>
		<div class="mhbo-confirmation-receipt">
			<h4 class="mhbo-confirmation-receipt-title"><?php echo esc_html( __( 'Reservation Breakdown', 'modern-hotel-booking' ) ); ?></h4>
			<div class="mhbo-summary-grid">
				<div class="mhbo-summary-header">
					<span><?php echo esc_html( _x( 'Item', 'cart item', 'modern-hotel-booking' ) ); ?></span>
					<span><?php echo esc_html( __( 'Amount', 'modern-hotel-booking' ) ); ?></span>
				</div>

				<div class="mhbo-summary-row">
					<span><?php echo esc_html( $room_name ); ?></span>
					<span><?php echo esc_html( $room_cost->format() ); ?></span>
				</div>

<?php
				foreach ( $extras_breakdown as $ex_item ) :
					$ex_name  = (string) I18n::decode( (string) ( $ex_item['name'] ?? '' ) );
					$ex_amt   = isset( $ex_item['total'] ) ? (string) $ex_item['total'] : (string) ( $ex_item['price'] ?? '0' );
					$ex_money = Money::fromDecimal( $ex_amt, $currency );
					$qty      = isset( $ex_item['quantity'] ) ? (int) $ex_item['quantity'] : 1;
					if ( $ex_money->isPositive() && '' !== $ex_name ) :
						?>
					<div class="mhbo-summary-row">
						<span><?php echo esc_html( $ex_name ); ?><?php echo ( $qty > 1 ) ? ' × ' . (int) $qty : ''; ?></span>
						<span><?php echo esc_html( $ex_money->format() ); ?></span>
					</div>
						<?php 
					endif;
				endforeach; 
				?>

<?php if ( $coupon_discount->isPositive() ) : ?>
					<div class="mhbo-summary-row" style="color:#16a34a; font-weight:700;">
						<span>
						<?php 
							/* translators: %s: coupon discount code */
							printf( esc_html__( 'Coupon (%s)', 'modern-hotel-booking' ), esc_html( strtoupper( (string) $booking->coupon_code ) ) ); 
						?>
						</span>
						<span>-<?php echo esc_html( $coupon_discount->format() ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( $tax_enabled && isset( $formatted_tax['totals']['subtotal_net_formatted'] ) ) : ?>
					<?php
					$conf_subtotal_label = ! empty( $formatted_tax['is_vat'] )
						? sprintf( I18n::get_label( 'label_subtotal_net_vat' ), $formatted_tax['base_label'] ?? $formatted_tax['label'] ?? 'VAT' )
						: I18n::get_label( 'label_subtotal' );
					?>
					<div class="mhbo-summary-row" style="font-weight:600; color:#475569; border-top:1px solid #e2e8f0; padding-top:6px;">
						<span><?php echo esc_html( $conf_subtotal_label ); ?></span>
						<span><?php echo esc_html( $formatted_tax['totals']['subtotal_net_formatted'] ); ?></span>
					</div>

					<?php if ( $show_split_tax ) : ?>
						<?php if ( $acc_tax_m->isPositive() ) : ?>
							<div class="mhbo-summary-row" style="color:#64748b; font-size:13px;">
								<span><?php echo esc_html( sprintf( I18n::get_label( 'label_tax_accommodation' ), $formatted_tax['label'], $acc_rate_str ) ); ?></span>
								<span><?php echo esc_html( I18n::format_currency( $acc_tax_m ) ); ?></span>
							</div>
						<?php endif; ?>
						<?php if ( $ext_tax_m->isPositive() ) : ?>
							<div class="mhbo-summary-row" style="color:#64748b; font-size:13px;">
								<span><?php echo esc_html( sprintf( I18n::get_label( 'label_tax_extras' ), $formatted_tax['label'], $ext_rate_str ) ); ?></span>
								<span><?php echo esc_html( I18n::format_currency( $ext_tax_m ) ); ?></span>
							</div>
						<?php endif; ?>
					<?php else : ?>
						<?php $single_conf_rate = ( $acc_tax_m->isZero() && $ext_tax_m->isPositive() ) ? $ext_rate_str : $acc_rate_str; ?>
						<div class="mhbo-summary-row" style="color:#64748b; font-size:13px;">
							<span><?php echo esc_html( sprintf( I18n::get_label( 'label_tax_rate' ), $formatted_tax['label'], $single_conf_rate ) ); ?></span>
							<span><?php echo esc_html( $formatted_tax['totals']['total_tax_formatted'] ); ?></span>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<div class="mhbo-summary-divider"></div>

				<?php
				$conf_total_label = ( $tax_enabled && ! empty( $formatted_tax['is_vat'] ) )
					? sprintf( I18n::get_label( 'label_total_charges_vat' ), $formatted_tax['base_label'] ?? $formatted_tax['label'] ?? 'VAT' )
					: I18n::get_label( 'label_total' );
				?>
				<div class="mhbo-summary-total">
					<span><?php echo esc_html( $conf_total_label ); ?></span>
					<span class="mhbo-display-total"><?php echo esc_html( $total_price->format() ); ?></span>
				</div>
			</div>
		</div>

		<div class="mhbo-transaction-details">
			<?php
			$amts            = class_exists( Pricing::class ) ? Pricing::get_booking_payment_amounts( $booking ) : null;
			$total_m         = $amts ? $amts['total'] : Money::fromDecimal( (string) ( $booking->total_price ?? 0 ), $currency );
			$received_m      = $amts ? $amts['received'] : Money::fromDecimal( (string) ( $booking->payment_amount ?? 0 ), $currency );
			$remaining_m     = $amts ? $amts['remaining'] : Money::fromDecimal( (string) ( $booking->remaining_balance ?? 0 ), $currency );
			$deposit_m       = $amts ? $amts['deposit'] : Money::fromDecimal( (string) ( $booking->deposit_amount ?? 0 ), $currency );
			$is_deposit_rcvd = $amts ? $amts['deposit_received'] : ( (bool) ( $booking->deposit_received ?? false ) );
			$is_full_paid    = $amts ? $amts['is_paid'] : ( $booking->payment_status === 'completed' || (bool) ( $booking->payment_received ?? false ) );

			$status_label = I18n::get_label( 'label_paid' );
			if ( 'pending' === $booking->payment_status && 'arrival' === $booking->payment_method ) {
				$status_label = I18n::get_label( 'label_pay_arrival' );
			} elseif ( 'pending' === $booking->payment_status ) {
				$status_label = I18n::translate_status( 'pending' );
			} elseif ( 'deposit_paid' === $booking->payment_status || $is_deposit_rcvd ) {
				$status_label = __( 'Deposit Paid', 'modern-hotel-booking' );
			} elseif ( 'failed' === $booking->payment_status ) {
				$status_label = I18n::get_label( 'label_failed' );
			}
			?>
			<p><span><strong><?php echo esc_html( I18n::get_label( 'label_payment_status' ) ); ?>:</strong></span> <span><?php echo esc_html( $status_label ); ?></span></p>

			<?php if ( $is_full_paid ) : ?>
				<p><span><strong><?php echo esc_html( I18n::get_label( 'label_amount_paid' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $received_m->isPositive() ? $received_m : $total_m ) ); ?></span></p>
			<?php elseif ( $is_deposit_rcvd ) : ?>
				<p><span><strong><?php echo esc_html( __( 'Deposit Paid', 'modern-hotel-booking' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $received_m ) ); ?></span></p>
				<p><span><strong><?php echo esc_html( __( 'Remaining Balance (Due at Check-in)', 'modern-hotel-booking' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $remaining_m ) ); ?></span></p>
			<?php elseif ( 'arrival' === $booking->payment_method ) : ?>
				<?php if ( $deposit_m->isPositive() ) : ?>
					<p><span><strong><?php echo esc_html( I18n::get_label( 'label_deposit_required' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $deposit_m ) ); ?></span></p>
				<?php endif; ?>
				<p><span><strong><?php echo esc_html( __( 'Total Due on Arrival', 'modern-hotel-booking' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $total_m ) ); ?></span></p>
			<?php else : ?>
				<?php if ( $received_m->isPositive() ) : ?>
					<p><span><strong><?php echo esc_html( I18n::get_label( 'label_amount_paid' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $received_m ) ); ?></span></p>
				<?php endif; ?>
				<?php if ( $deposit_m->isPositive() ) : ?>
					<p><span><strong><?php echo esc_html( I18n::get_label( 'label_deposit_required' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $deposit_m ) ); ?></span></p>
				<?php endif; ?>
				<p><span><strong><?php echo esc_html( __( 'Total Amount Due', 'modern-hotel-booking' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $remaining_m ) ); ?></span></p>
			<?php endif; ?>

			<?php if ( '' !== (string) ( $booking->payment_method ?? '' ) ) : ?>
				<p><span><strong><?php echo esc_html( I18n::get_label( 'label_payment_method' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::translate_payment_method( (string) $booking->payment_method ) ); ?></span></p>
			<?php endif; ?>

			<?php if ( '' !== (string) ( $booking->payment_transaction_id ?? '' ) ) : ?>
				<p><span><strong><?php echo esc_html( I18n::get_label( 'label_transaction_id' ) ); ?>:</strong></span> <span style="font-family:monospace;"><?php echo esc_html( $booking->payment_transaction_id ); ?></span></p>
			<?php endif; ?>
		</div>

		<?php
		$portal_url = null;
		if ( class_exists( '\\MHBO\\Api\\GuestPortalController' ) && ! empty( $booking->booking_token ) ) {
			$portal_url = \MHBO\Api\GuestPortalController::get_portal_url( (string) $booking->booking_token, (int) $booking->id, (string) $booking->customer_email );
		}
		$conf_btn_enabled = ( class_exists( Tier::class ) && Tier::has_feature( 'confirmation_cta' ) ) && (int) get_option( 'mhbo_confirmation_btn_enabled', 0 ) === 1;
		if ( $portal_url || $conf_btn_enabled ) :
			?>
			<div class="mhbo-confirmation-actions" style="margin-top:28px; display:flex; flex-wrap:wrap; justify-content:center; gap:12px; text-align:center;">
				<?php if ( $portal_url ) : ?>
					<a href="<?php echo esc_url( $portal_url ); ?>" class="mhbo-btn mhbo-btn-primary mhbo-confirmation-portal-btn" style="min-height:44px; display:inline-flex; align-items:center; justify-content:center; padding:12px 24px; border-radius:8px; font-weight:700; text-decoration:none;">
						<?php esc_html_e( 'View / Manage in Guest Portal &rarr;', 'modern-hotel-booking' ); ?>
					</a>
				<?php endif; ?>
				<?php
				if ( $conf_btn_enabled ) :
					$btn_text = (string) get_option( 'mhbo_confirmation_btn_text', __( 'Make Another Booking', 'modern-hotel-booking' ) );
					$btn_url  = (string) get_option( 'mhbo_confirmation_btn_url', '' );
					if ( '' === trim( $btn_url ) ) {
						$btn_url = home_url( '/' );
					}
					?>
					<a href="<?php echo esc_url( $btn_url ); ?>" class="mhbo-btn mhbo-btn-secondary mhbo-confirmation-cta-btn" style="min-height:44px; display:inline-flex; align-items:center; justify-content:center; padding:12px 24px; border-radius:8px; font-weight:600; text-decoration:none; background:#ffffff; border:1px solid #cbd5e1; color:#334155;">
						<?php echo esc_html( (string) I18n::decode( $btn_text ) ); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}
}
