<?php declare(strict_types=1);

namespace MHBO\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\I18n;
use MHBO\Core\Tax;
use MHBO\Core\Security;
use MHBO\Core\HotelTime;

/**
 * Guest Self-Service Portal Shortcode Renderer
 *
 * Handles rendering of the [mhbo_guest_portal] shortcode for guest lookup,
 * stay modification, cancellation, and communication history.
 */
class GuestPortalShortcode {

	/**
	 * Render the Guest Self-Service Portal shortcode [mhbo_guest_portal].
	 *
	 * Allows guests to view their reservation, check payment status,
	 * cancel within policy window, and submit stay modifications.
	 *
	 * @param array<string, mixed>|string|false $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render( array|string|false $atts ): string {
		$raw_atts    = is_array( $atts ) ? $atts : array();
		$parsed_atts = shortcode_atts(
			array(
				'token'       => '',
				'booking_id'  => 0,
				'email'       => '',
				'show_header' => 'yes',
				'show_title'  => '',
				'show_badge'  => '',
			),
			$raw_atts,
			'mhbo_guest_portal'
		);

		$show_header     = ! in_array( strtolower( trim( (string) $parsed_atts['show_header'] ) ), array( 'no', 'false', '0' ), true );
		$attr_show_title = Calendar::parse_optional_bool_attr( $raw_atts, 'show_title' );
		$attr_show_badge = Calendar::parse_optional_bool_attr( $raw_atts, 'show_badge' );
		$eff_show_title  = $show_header && ( (int) get_option( 'mhbo_portal_show_title', 1 ) === 1 ) && ( false !== $attr_show_title );
		$eff_show_badge  = $show_header && ( (int) get_option( 'mhbo_portal_show_badge', 1 ) === 1 ) && ( false !== $attr_show_badge );
		$initial_token = ! empty( $parsed_atts['token'] )
			? sanitize_text_field( (string) $parsed_atts['token'] )
			: ( isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ( isset( $_GET['booking_token'] ) ? sanitize_text_field( wp_unslash( $_GET['booking_token'] ) ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$initial_id    = ! empty( $parsed_atts['booking_id'] )
			? absint( $parsed_atts['booking_id'] )
			: ( isset( $_GET['booking_id'] ) ? absint( wp_unslash( $_GET['booking_id'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$initial_email = ! empty( $parsed_atts['email'] )
			? sanitize_email( (string) $parsed_atts['email'] )
			: ( isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$rest_url = rest_url( 'mhbo/v1/portal' );
		$nonce    = wp_create_nonce( 'wp_rest' );

		// Ensure core mhbo-style and guest portal assets are enqueued.
		if ( function_exists( 'wp_enqueue_style' ) && defined( 'MHBO_PLUGIN_URL' ) && defined( 'MHBO_VERSION' ) ) {
			wp_enqueue_style(
				'mhbo-style',
				\MHBO\Core\Plugin::asset_url( 'assets/css/mhbo-style.css' ),
				array(),
				\MHBO\Core\Plugin::asset_ver( 'assets/css/mhbo-style.css' )
			);
			wp_enqueue_style(
				'mhbo-calendar-style',
				\MHBO\Core\Plugin::asset_url( 'assets/css/mhbo-calendar.css' ),
				array( 'mhbo-style' ),
				\MHBO\Core\Plugin::asset_ver( 'assets/css/mhbo-calendar.css' )
			);
			if ( ! wp_style_is( 'mhbo-guest-portal', 'enqueued' ) ) {
				wp_enqueue_style(
					'mhbo-guest-portal',
					\MHBO\Core\Plugin::asset_url( 'assets/css/mhbo-guest-portal.css' ),
					array( 'mhbo-style' ),
					\MHBO\Core\Plugin::asset_ver( 'assets/css/mhbo-guest-portal.css' )
				);
			}
			if ( function_exists( 'wp_enqueue_script' ) && ! wp_script_is( 'mhbo-guest-portal', 'enqueued' ) ) {
				wp_enqueue_script(
					'mhbo-guest-portal',
					\MHBO\Core\Plugin::asset_url( 'assets/js/mhbo-guest-portal.js' ),
					array(),
					\MHBO\Core\Plugin::asset_ver( 'assets/js/mhbo-guest-portal.js' ),
					array(
						'strategy'  => 'defer',
						'in_footer' => true,
					)
				);
				if ( function_exists( 'wp_localize_script' ) ) {
					wp_localize_script(
						'mhbo-guest-portal',
						'mhbo_portal_i18n',
						array(
							'copied'               => __( 'Copied!', 'modern-hotel-booking' ),
							'copy'                 => I18n::get_label( 'label_copy' ),
							'copy_password'        => __( 'Copy Password', 'modern-hotel-booking' ),
							'submitting'           => __( 'Submitting...', 'modern-hotel-booking' ),
							'submit_request'       => __( 'Submit Request', 'modern-hotel-booking' ),
							'processing'           => __( 'Processing...', 'modern-hotel-booking' ),
							'confirm_cancellation' => __( 'Confirm Cancellation', 'modern-hotel-booking' ),
							'network_error'        => __( 'Network error while communicating with server. Please try again.', 'modern-hotel-booking' ),
							'render_error'         => __( 'An error occurred displaying your reservation details.', 'modern-hotel-booking' ),
							'invalid_details'      => __( 'Unable to find reservation. Please verify your details.', 'modern-hotel-booking' ),
							'empty_fields'         => __( 'Please enter either a booking token or both your Booking ID and Email address.', 'modern-hotel-booking' ),
							'empty_notes'          => __( 'Please provide details for your modification request.', 'modern-hotel-booking' ),
							'cancel_failed'        => __( 'Failed to process cancellation request.', 'modern-hotel-booking' ),
							'modify_failed'        => __( 'Failed to submit request.', 'modern-hotel-booking' ),
							'deposit_paid'         => __( 'Deposit Paid', 'modern-hotel-booking' ),
							'due_at_booking'       => __( 'Due at booking', 'modern-hotel-booking' ),
							'confirm_price_required' => __( 'Please check the box confirming the updated reservation total before proceeding.', 'modern-hotel-booking' ),
							'checking_availability'  => __( 'Checking availability and recalculating total...', 'modern-hotel-booking' ),
							'submit_mod_request'     => __( 'Submit Modification Request', 'modern-hotel-booking' ),
							'update_reservation'     => __( 'Update Reservation', 'modern-hotel-booking' ),
							'change_stay_dates'      => __( 'Change Stay Dates', 'modern-hotel-booking' ),
							'request_stay_change'    => __( 'Request Stay Change', 'modern-hotel-booking' ),
							'submit_cancel_request'  => __( 'Submit Cancellation Request', 'modern-hotel-booking' ),
							'cancel_reservation'     => __( 'Cancel Reservation', 'modern-hotel-booking' ),
							'request_cancellation'   => __( 'Request Cancellation', 'modern-hotel-booking' ),
							'pending_cancel_request' => __( 'A cancellation request is currently pending front desk review.', 'modern-hotel-booking' ),
							'pending_mod_request'    => __( 'A stay modification request is currently pending front desk review.', 'modern-hotel-booking' ),
							'available_label'        => __( 'Available', 'modern-hotel-booking' ),
							'review_by_front_desk'   => __( 'This request will be reviewed by our front desk team.', 'modern-hotel-booking' ),
							'night_singular'         => __( 'night', 'modern-hotel-booking' ),
							'night_plural'           => __( 'nights', 'modern-hotel-booking' ),
							'no_price_change'        => __( 'No price change', 'modern-hotel-booking' ),
							'choose_dates_required'  => __( 'Please choose both check-in and check-out dates.', 'modern-hotel-booking' ),
							'checkout_after_checkin' => __( 'Check-out date must be after check-in date.', 'modern-hotel-booking' ),
							/* translators: %s: front desk phone number or email address. */
							'need_changes_contact'   => __( 'Need to change or cancel your stay? Please contact our front desk directly at %s.', 'modern-hotel-booking' ),
							'need_changes_fallback'  => __( 'Need to change or cancel your stay? Please contact our front desk directly for assistance.', 'modern-hotel-booking' ),
						)
					);
				}
			}
		}

ob_start();
		?>
		<div id="mhbo-guest-portal" class="mhbo-guest-portal-wrapper" 
			 data-rest-url="<?php echo esc_url( $rest_url ); ?>"
			 data-nonce="<?php echo esc_attr( $nonce ); ?>"
			 data-token="<?php echo esc_attr( $initial_token ); ?>"
			 data-id="<?php echo esc_attr( $initial_id > 0 ? (string) $initial_id : '' ); ?>"
			 data-email="<?php echo esc_attr( $initial_email ); ?>">

			<div id="mhbo-portal-alert" class="mhbo-portal-alert" role="alert" aria-live="polite"></div>

			<!-- Lookup View -->
			<div id="mhbo-portal-lookup-view" class="mhbo-portal-card">
				<?php if ( $eff_show_title || $eff_show_badge ) : ?>
					<div class="mhbo-portal-header">
						<?php if ( $eff_show_title ) : ?>
							<h2 class="mhbo-portal-title"><?php echo esc_html( I18n::get_label( 'label_guest_portal_title' ) ); ?></h2>
						<?php endif; ?>
						<?php if ( $eff_show_badge ) : ?>
							<div style="margin: 6px 0 10px 0;">
								<span class="mhbo-calendar-room-badge"><?php echo esc_html( I18n::get_label( 'label_guest_portal_badge' ) ); ?></span>
							</div>
						<?php endif; ?>
						<?php if ( $eff_show_title ) : ?>
							<p class="mhbo-portal-desc"><?php echo esc_html( I18n::get_label( 'label_guest_portal_desc' ) ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<form id="mhbo-portal-lookup-form" class="mhbo-portal-form">
					<div class="mhbo-portal-field-full">
						<label class="mhbo-portal-label" for="mhbo-lookup-token"><?php esc_html_e( 'Booking Security Token (from confirmation email)', 'modern-hotel-booking' ); ?></label>
						<input type="text" id="mhbo-lookup-token" class="mhbo-portal-input" placeholder="e.g. 7c9a62... (1-click direct link in your email)">
					</div>
					<div style="grid-column: 1 / -1; text-align: center; color: #94a3b8; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin: 6px 0;">
						— <?php esc_html_e( 'OR LOOK UP BY DETAILS', 'modern-hotel-booking' ); ?> —
					</div>
					<div>
						<label class="mhbo-portal-label" for="mhbo-lookup-id"><?php esc_html_e( 'Booking ID / Reference #', 'modern-hotel-booking' ); ?></label>
						<input type="number" id="mhbo-lookup-id" class="mhbo-portal-input" placeholder="e.g. 104">
					</div>
					<div>
						<label class="mhbo-portal-label" for="mhbo-lookup-email"><?php esc_html_e( 'Guest Email Address', 'modern-hotel-booking' ); ?></label>
						<input type="email" id="mhbo-lookup-email" class="mhbo-portal-input" placeholder="guest@example.com">
					</div>
					<div class="mhbo-portal-field-full" style="margin-top: 12px;">
						<button type="submit" id="mhbo-lookup-btn" class="mhbo-portal-btn mhbo-portal-btn-primary" style="width: 100%;">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
							<?php esc_html_e( 'Find My Reservation', 'modern-hotel-booking' ); ?>
						</button>
					</div>
				</form>
			</div>

			<!-- Details View -->
			<div id="mhbo-portal-details-view" class="mhbo-portal-card" style="display: none;">
				
				<!-- Official Print Header -->
				<div class="mhbo-print-header">
					<div style="display: flex; justify-content: space-between; align-items: flex-start;">
						<div>
							<h2 style="margin: 0; font-size: 24px; color: #0f172a;"><?php esc_html_e( 'Hotel Stay Itinerary & Folio Voucher', 'modern-hotel-booking' ); ?></h2>
							<p style="margin: 4px 0 0 0; font-size: 13px; color: #64748b;"><?php esc_html_e( 'Reservation Reference:', 'modern-hotel-booking' ); ?> #<span class="mhbo-print-res-id"></span> &bull; <span id="mhbo-print-hotel-name"></span></p>
						</div>
						<div style="text-align: right; font-size: 12px; color: #64748b;">
							<div><?php esc_html_e( 'Printed:', 'modern-hotel-booking' ); ?> <?php echo esc_html( HotelTime::format_date( HotelTime::now(), (string) get_option( 'date_format', 'F j, Y' ) ) ); ?></div>
							<div id="mhbo-print-hotel-address" style="margin-top: 2px;"></div>
							<div id="mhbo-print-hotel-contact"></div>
						</div>
					</div>
				</div>

				<!-- Personalized Hospitality Hero Banner -->
				<div id="mhbo-guest-hero" class="mhbo-guest-hero">
					<div id="mhbo-guest-avatar" class="mhbo-guest-avatar">G</div>
					<div style="flex: 1 1 auto;">
						<div style="display: flex; align-items: center; flex-wrap: wrap; gap: 8px;">
							<h3 id="mhbo-guest-greeting" class="mhbo-guest-greeting" style="margin: 0;"></h3>
							<span id="mhbo-guest-loyalty-badge" class="mhbo-vip-badge" style="display: none; background: #e0f2fe; color: #0369a1; border-color: #bae6fd;"></span>
						</div>
						<p id="mhbo-guest-stage-msg" class="mhbo-guest-stage-msg"></p>
					</div>
					<div id="mhbo-res-room-thumb-wrap" style="display: none;">
						<img id="mhbo-res-room-thumb" src="" alt="Room Preview" style="width: 64px; height: 64px; object-fit: cover; border-radius: 10px; border: 1px solid var(--gp-border);">
					</div>
				</div>

				<!-- At Your Service Concierge Quick-Actions Strip -->
				<div id="mhbo-qa-strip" class="mhbo-qa-strip">
					<a id="mhbo-qa-whatsapp" href="#" target="_blank" rel="noopener" class="mhbo-qa-pill" style="display: none; color: #15803d;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
						<?php esc_html_e( 'Chat with Front Desk', 'modern-hotel-booking' ); ?>
					</a>
					<a id="mhbo-qa-phone" href="#" class="mhbo-qa-pill" style="display: none;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
						<?php esc_html_e( 'Call Front Desk', 'modern-hotel-booking' ); ?>
					</a>
					<a id="mhbo-qa-directions" href="#" target="_blank" rel="noopener" class="mhbo-qa-pill" style="display: none;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"></polygon></svg>
						<?php esc_html_e( 'Get Directions', 'modern-hotel-booking' ); ?>
					</a>
					<a id="mhbo-qa-invoice" href="#" target="_blank" rel="noopener" class="mhbo-qa-pill" style="display: none;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
						<?php esc_html_e( 'Download Tax Invoice', 'modern-hotel-booking' ); ?>
					</a>
					<div class="mhbo-qa-dropdown-wrap" style="position: relative; display: inline-flex;">
						<button type="button" id="mhbo-qa-calendar-btn" class="mhbo-qa-pill">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
							<?php esc_html_e( 'Add to Calendar', 'modern-hotel-booking' ); ?> ▾
						</button>
						<div id="mhbo-qa-calendar-menu" class="mhbo-qa-dropdown-menu" style="display: none;">
							<a id="mhbo-cal-google" href="#" target="_blank" rel="noopener" class="mhbo-qa-dropdown-item"><?php esc_html_e( 'Google Calendar', 'modern-hotel-booking' ); ?></a>
							<a id="mhbo-cal-ics" href="#" class="mhbo-qa-dropdown-item"><?php esc_html_e( 'Apple / Outlook (.ics)', 'modern-hotel-booking' ); ?></a>
						</div>
					</div>
					<button type="button" id="mhbo-qa-share-btn" class="mhbo-qa-pill">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path><polyline points="16 6 12 2 8 6"></polyline><line x1="12" y1="2" x2="12" y2="15"></line></svg>
						<span id="mhbo-qa-share-text"><?php esc_html_e( 'Share Portal Link', 'modern-hotel-booking' ); ?></span>
					</button>
				</div>

				<!-- Multi-Room Group Reservation Banner -->
				<div id="mhbo-portal-group-card" class="mhbo-portal-group-card" style="display: none;">
					<div class="mhbo-portal-group-header">
						<div class="mhbo-portal-group-title">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
							<span><?php esc_html_e( 'Group Reservation', 'modern-hotel-booking' ); ?>: <strong id="mhbo-group-id-label"></strong></span>
						</div>
						<a id="mhbo-group-pay-btn" href="#" class="mhbo-portal-btn mhbo-portal-btn-primary mhbo-portal-btn-sm" style="display: none;">
							<?php esc_html_e( 'Pay Entire Group Balance', 'modern-hotel-booking' ); ?>
						</a>
					</div>
					<p style="font-size: 12.5px; color: #475569; margin: 6px 0 10px 0;"><?php esc_html_e( 'This reservation includes multiple rooms booked together. Click any room below to view its itinerary or individual charges.', 'modern-hotel-booking' ); ?></p>
					<div id="mhbo-group-siblings-list" class="mhbo-group-chips"></div>
				</div>

				<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 22px;">
					<div>
						<div style="font-size: 13px; color: #64748b; font-weight: 600;"><?php esc_html_e( 'Reservation', 'modern-hotel-booking' ); ?> #<span id="mhbo-res-id"></span></div>
						<h2 id="mhbo-res-room" class="mhbo-portal-title" style="margin-top: 4px; margin-bottom: 2px;"></h2>
						<div id="mhbo-res-room-type" style="font-size: 13px; color: #64748b; font-weight: 500;"></div>
					</div>
					<div style="text-align: right;">
						<span id="mhbo-res-status-badge" class="mhbo-portal-badge"></span>
						<div style="margin-top: 6px;"><span id="mhbo-res-payment-badge" class="mhbo-portal-badge"></span></div>
					</div>
				</div>

				<div id="mhbo-portal-pending-req-banner" style="display: none; margin: 0 0 16px 0; padding: 10px 14px; background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; border-radius: 8px; font-size: 13px; color: #92400e;"></div>

				<div class="mhbo-portal-timeline">
					<div class="mhbo-timeline-col">
						<div class="mhbo-timeline-label"><?php echo esc_html( __( 'CHECK IN', 'modern-hotel-booking' ) ); ?></div>
						<div id="mhbo-res-checkin" class="mhbo-timeline-val"></div>
						<div class="mhbo-stay-time"><span class="mhbo-stay-time-prefix"><?php echo esc_html( _x( 'FROM', 'Check-in time prefix', 'modern-hotel-booking' ) ); ?></span> <span id="mhbo-res-checkin-time"></span></div>
					</div>
					<div class="mhbo-timeline-col">
						<div class="mhbo-timeline-label"><?php echo esc_html( __( 'CHECK-OUT', 'modern-hotel-booking' ) ); ?></div>
						<div id="mhbo-res-checkout" class="mhbo-timeline-val"></div>
						<div class="mhbo-stay-time"><span class="mhbo-stay-time-prefix"><?php echo esc_html( _x( 'BY', 'Check-out time prefix', 'modern-hotel-booking' ) ); ?></span> <span id="mhbo-res-checkout-time"></span></div>
					</div>
					<div class="mhbo-timeline-col mhbo-timeline-col-nights">
						<div class="mhbo-timeline-label"><?php echo esc_html( __( 'NIGHTS', 'modern-hotel-booking' ) ); ?></div>
						<div id="mhbo-res-nights" class="mhbo-nights-count"></div>
						<div class="mhbo-nights-label"><?php echo esc_html( I18n::get_label( 'label_nights' ) ); ?></div>
					</div>
				</div>

				<!-- Guest & Contact Info Section -->
				<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; margin-bottom: 8px;">
					<h4 style="margin: 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 700;"><?php esc_html_e( 'Guest & Contact Information', 'modern-hotel-booking' ); ?></h4>
					<button type="button" id="mhbo-portal-edit-profile-btn" class="mhbo-copy-btn" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 600;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 13px; height: 13px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
						<?php esc_html_e( 'Edit Contact Details', 'modern-hotel-booking' ); ?>
					</button>
				</div>
				<div class="mhbo-portal-guest-info">
					<div class="mhbo-portal-info-item">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Guest Name', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-name" class="mhbo-portal-info-val"></div>
					</div>
					<div class="mhbo-portal-info-item">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Email Address', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-email" class="mhbo-portal-info-val"></div>
					</div>
					<div class="mhbo-portal-info-item">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Guests', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-guests" class="mhbo-portal-info-val"></div>
					</div>
					<div id="mhbo-res-phone-wrap" class="mhbo-portal-info-item">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Phone Number', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-phone" class="mhbo-portal-info-val"></div>
					</div>
					<div id="mhbo-res-arrival-wrap" class="mhbo-portal-info-item" style="display: none;">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Estimated Arrival Time', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-arrival" class="mhbo-portal-info-val"></div>
					</div>
					<div id="mhbo-res-requests-wrap" class="mhbo-portal-info-item" style="display: none; grid-column: 1 / -1;">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Special Requests & Preferences', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-requests" class="mhbo-portal-info-val" style="font-size: 13.5px; line-height: 1.5;"></div>
					</div>
				</div>

				<div class="mhbo-portal-finances">
					<div class="mhbo-finance-row">
						<span><?php esc_html_e( 'Total Reservation Charges', 'modern-hotel-booking' ); ?>:</span>
						<strong id="mhbo-res-total"></strong>
					</div>
					<div id="mhbo-res-coupon-row" class="mhbo-finance-row" style="display: none; color: #16a34a;">
						<span><?php esc_html_e( 'Promotional Coupon Discount', 'modern-hotel-booking' ); ?> (<strong id="mhbo-res-coupon-code"></strong>):</span>
						<span id="mhbo-res-coupon-discount" style="font-weight: 600;"></span>
					</div>
					<div id="mhbo-res-service-fee-row" class="mhbo-finance-row" style="display: none;">
						<span><span id="mhbo-res-service-fee-label"><?php esc_html_e( 'Service Fee', 'modern-hotel-booking' ); ?></span>:</span>
						<span id="mhbo-res-service-fee-amount" style="font-weight: 600;"></span>
					</div>
					<div id="mhbo-res-tax-row" class="mhbo-finance-row" style="display: none;">
						<span><span id="mhbo-res-tax-label"><?php esc_html_e( 'Tax', 'modern-hotel-booking' ); ?></span>:</span>
						<span id="mhbo-res-tax-amount" style="font-weight: 600;"></span>
					</div>
					<div id="mhbo-res-deposit-row" class="mhbo-finance-row" style="display: none;">
						<span>
							<?php esc_html_e( 'Required Deposit', 'modern-hotel-booking' ); ?>
							<span id="mhbo-res-deposit-status-note" class="mhbo-deposit-status-note"></span>:
						</span>
						<strong id="mhbo-res-deposit-val"></strong>
					</div>
					<div class="mhbo-finance-row">
						<span><?php esc_html_e( 'Total Paid', 'modern-hotel-booking' ); ?>:</span>
						<span id="mhbo-res-paid" style="color: #166534; font-weight: 600;"></span>
					</div>
					<div class="mhbo-finance-row mhbo-finance-row-total">
						<span><?php esc_html_e( 'Amount Outstanding', 'modern-hotel-booking' ); ?>:</span>
						<span id="mhbo-res-balance" style="color: var(--gp-accent);"></span>
					</div>
					<div id="mhbo-res-settled-badge" style="display: none; margin-top: 10px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 12.5px; color: #166534; font-weight: 600; text-align: center;">
						✓ <?php esc_html_e( 'Reservation Folio Fully Settled', 'modern-hotel-booking' ); ?>
					</div>
				</div>

				<!-- Property Contact & Location Card -->
				<div id="mhbo-hotel-contact-card" class="mhbo-hotel-card">
					<div class="mhbo-hotel-header">
						<div class="mhbo-hotel-title">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
							<?php esc_html_e( 'Property Details & Front Desk Contact', 'modern-hotel-booking' ); ?>
						</div>
					</div>
					<div class="mhbo-hotel-details-grid">
						<div id="mhbo-hotel-addr-wrap">
							<span class="mhbo-hotel-label"><?php esc_html_e( 'Address & Location', 'modern-hotel-booking' ); ?>:</span>
							<div id="mhbo-hotel-address" style="font-weight: 600; color: #1e293b; margin-top: 2px;"></div>
							<a id="mhbo-hotel-map-link" href="#" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--gp-primary); font-weight: 600; margin-top: 4px;">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 12px; height: 12px;"><polygon points="3 11 22 2 13 21 11 13 3 11"></polygon></svg>
								<?php esc_html_e( 'Open in Google Maps', 'modern-hotel-booking' ); ?> ↗
							</a>
						</div>
						<div>
							<span class="mhbo-hotel-label"><?php esc_html_e( 'Direct Front Desk Contact', 'modern-hotel-booking' ); ?>:</span>
							<div style="margin-top: 2px;">
								<a id="mhbo-hotel-phone-link" href="#" style="font-weight: 600; color: #1e293b; text-decoration: none;"></a>
							</div>
							<div style="margin-top: 2px;">
								<a id="mhbo-hotel-email-link" href="#" style="font-size: 12.5px; color: var(--gp-primary); font-weight: 500; text-decoration: none;"></a>
							</div>
							<div id="mhbo-hotel-wa-wrap" style="margin-top: 4px; display: none;">
								<a id="mhbo-hotel-wa-link" href="#" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: #15803d; font-weight: 600;">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 12px; height: 12px;"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
									<?php esc_html_e( 'WhatsApp Concierge Available', 'modern-hotel-booking' ); ?>
								</a>
							</div>
						</div>
					</div>
				</div>

				<!-- Wi-Fi Access Card -->
				<div id="mhbo-wifi-card" class="mhbo-wifi-card">
					<div class="mhbo-wifi-header">
						<div class="mhbo-wifi-title">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M5 12.55a11 11 0 0 1 14.08 0"></path><path d="M1.42 9a16 16 0 0 1 21.16 0"></path><path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path><line x1="12" y1="20" x2="12.01" y2="20"></line></svg>
							<?php esc_html_e( 'Complimentary Guest Wi-Fi', 'modern-hotel-booking' ); ?>
						</div>
					</div>
					<div class="mhbo-wifi-details">
						<div>
							<span style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700;"><?php esc_html_e( 'Network (SSID)', 'modern-hotel-booking' ); ?>:</span>
							<strong id="mhbo-wifi-ssid" style="margin-left: 4px;"></strong>
						</div>
						<div id="mhbo-wifi-pass-wrap">
							<span style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700;"><?php esc_html_e( 'Password', 'modern-hotel-booking' ); ?>:</span>
							<code id="mhbo-wifi-pass" style="margin-left: 4px; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: 700;"></code>
							<button type="button" id="mhbo-wifi-copy-btn" class="mhbo-copy-btn" style="margin-left: 6px;"><?php esc_html_e( 'Copy Password', 'modern-hotel-booking' ); ?></button>
						</div>
					</div>
				</div>

				<!-- Digital Guest Compendium & House Rules -->
				<div id="mhbo-guide-card" class="mhbo-guide-card">
					<div id="mhbo-guide-header" class="mhbo-guide-header">
						<span style="display: flex; align-items: center; gap: 8px;">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 17px; height: 17px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
							<?php esc_html_e( 'Property Guide & House Rules', 'modern-hotel-booking' ); ?>
						</span>
						<span id="mhbo-guide-arrow" style="font-size: 12px; color: #64748b;">▼</span>
					</div>
					<div id="mhbo-guide-content" class="mhbo-guide-content" style="display: none;"></div>
				</div>

<!-- Cancellation & Modification Policy / Front Desk Guidance -->
				<div id="mhbo-portal-policy-card" class="mhbo-portal-policy-card" style="display: none;">
					<div class="mhbo-portal-policy-title">
						<?php esc_html_e( 'Cancellation & Modification Policy', 'modern-hotel-booking' ); ?>
					</div>
					<p id="mhbo-portal-policy-text" class="mhbo-portal-policy-text" style="display: none;"></p>
					<p id="mhbo-portal-policy-contact" class="mhbo-portal-policy-contact" style="display: none;"></p>
				</div>

				<div class="mhbo-portal-actions">
					<button type="button" id="mhbo-portal-modify-btn" class="mhbo-portal-btn mhbo-portal-btn-secondary" style="display: none;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
						<span class="mhbo-modify-btn-label"><?php esc_html_e( 'Request Stay Change', 'modern-hotel-booking' ); ?></span>
					</button>

					<button type="button" id="mhbo-portal-cancel-btn" class="mhbo-portal-btn mhbo-portal-btn-danger" style="display: none;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
						<span id="mhbo-cancel-btn-text"><?php esc_html_e( 'Cancel Reservation', 'modern-hotel-booking' ); ?></span>
					</button>

					<button type="button" onclick="window.print()" class="mhbo-portal-btn mhbo-portal-btn-secondary">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
						<?php esc_html_e( 'Print Itinerary', 'modern-hotel-booking' ); ?>
					</button>

<button type="button" id="mhbo-portal-reset-btn" class="mhbo-portal-btn mhbo-portal-btn-secondary" style="margin-left: auto;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
						<?php esc_html_e( 'Look Up Another', 'modern-hotel-booking' ); ?>
					</button>
				</div>
			</div>

			<!-- Cancel Modal -->
			<div id="mhbo-portal-cancel-modal" class="mhbo-portal-modal" role="dialog" aria-modal="true" aria-labelledby="mhbo-cancel-modal-title">
				<div class="mhbo-modal-content">
					<h3 id="mhbo-cancel-modal-title" style="margin: 0 0 12px 0; color: #991b1b; font-size: 18px; font-weight: 700;"><?php esc_html_e( 'Cancel Reservation', 'modern-hotel-booking' ); ?></h3>
					<div id="mhbo-cancel-paid-warning" style="display: none; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; font-size: 13px; color: #991b1b;">
						<strong><?php esc_html_e( 'Payment on File:', 'modern-hotel-booking' ); ?></strong> <span id="mhbo-cancel-paid-amount"></span><br>
						<?php esc_html_e( 'Because a payment has been received, this cancellation requires front desk review to process any applicable refund according to policy.', 'modern-hotel-booking' ); ?>
					</div>
					<div id="mhbo-cancel-review-info" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; font-size: 13px; color: #1e40af;">
						<?php esc_html_e( 'Under property policy, cancellations require front desk approval before your room is released. Submit your request below and our team will confirm promptly.', 'modern-hotel-booking' ); ?>
					</div>
					<p id="mhbo-cancel-policy-notice" style="font-size: 13px; color: #64748b; margin-bottom: 16px; line-height: 1.5;"></p>
					<label class="mhbo-portal-label" for="mhbo-cancel-reason"><?php esc_html_e( 'Reason for cancellation (optional)', 'modern-hotel-booking' ); ?></label>
					<textarea id="mhbo-cancel-reason" class="mhbo-portal-input" rows="3" style="margin-bottom: 20px;" placeholder="<?php esc_attr_e( 'Please let us know why you need to cancel...', 'modern-hotel-booking' ); ?>"></textarea>
					<div style="display: flex; justify-content: flex-end; gap: 12px;">
						<button type="button" id="mhbo-cancel-modal-close" class="mhbo-portal-btn mhbo-portal-btn-secondary" aria-label="<?php esc_attr_e( 'Keep Reservation and close dialog', 'modern-hotel-booking' ); ?>"><?php esc_html_e( 'Keep Reservation', 'modern-hotel-booking' ); ?></button>
						<button type="button" id="mhbo-cancel-modal-confirm" class="mhbo-portal-btn mhbo-portal-btn-danger"><?php esc_html_e( 'Confirm Cancellation', 'modern-hotel-booking' ); ?></button>
					</div>
				</div>
			</div>

			<!-- Modify Modal -->
			<div id="mhbo-portal-modify-modal" class="mhbo-portal-modal" role="dialog" aria-modal="true" aria-labelledby="mhbo-mod-modal-title">
				<div class="mhbo-modal-content">
					<h3 id="mhbo-mod-modal-title" style="margin: 0 0 12px 0; color: #0f172a; font-size: 18px; font-weight: 700;"><?php esc_html_e( 'Change Reservation Dates & Guests', 'modern-hotel-booking' ); ?></h3>
					<div id="mhbo-mod-paid-info" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 12.5px; color: #1e40af;">
						<?php esc_html_e( 'A payment is already recorded for this booking. Your requested dates will be submitted to our front desk team to calculate any rate difference or refund before finalizing.', 'modern-hotel-booking' ); ?>
					</div>
					<div id="mhbo-mod-modal-error" style="display:none; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:13px; color:#991b1b;"></div>
					<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
						<div>
							<label class="mhbo-portal-label" for="mhbo-mod-checkin"><?php esc_html_e( 'New Check-In Date', 'modern-hotel-booking' ); ?></label>
							<input type="date" id="mhbo-mod-checkin" class="mhbo-portal-input" required>
						</div>
						<div>
							<label class="mhbo-portal-label" for="mhbo-mod-checkout"><?php esc_html_e( 'New Check-Out Date', 'modern-hotel-booking' ); ?></label>
							<input type="date" id="mhbo-mod-checkout" class="mhbo-portal-input" required>
						</div>
					</div>
					<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 6px;">
						<div>
							<label class="mhbo-portal-label" for="mhbo-mod-adults"><?php esc_html_e( 'Adults (Current Occupancy)', 'modern-hotel-booking' ); ?></label>
							<input type="number" id="mhbo-mod-adults" class="mhbo-portal-input" readonly style="background:#f8fafc; cursor:not-allowed;" value="1">
						</div>
<input type="hidden" id="mhbo-mod-children" value="0">
</div>
					<p style="font-size: 11.5px; color: #64748b; margin: 0 0 14px 0;"><?php esc_html_e( 'Guest occupancy remains the same as your current reservation.', 'modern-hotel-booking' ); ?></p>
					<div id="mhbo-mod-preview-box" style="display: none; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; font-size: 13px;">
						<div id="mhbo-mod-preview-status" style="color: #475569; margin-bottom: 6px;"></div>
						<div id="mhbo-mod-preview-totals" style="display: flex; justify-content: space-between; align-items: center; gap: 10px; font-weight: 600; color: #0f172a;">
							<span><?php esc_html_e( 'Updated Stay Quote:', 'modern-hotel-booking' ); ?> <span id="mhbo-mod-preview-nights"></span></span>
							<span id="mhbo-mod-preview-price"></span>
						</div>
						<div id="mhbo-mod-preview-note" style="display: none; margin-top: 6px; font-size: 12px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 6px 10px;"></div>
						<label id="mhbo-mod-price-confirm-wrap" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px solid #e2e8f0; align-items: flex-start; gap: 8px; font-size: 12.5px; font-weight: 600; color: #0f172a; cursor: pointer;">
							<input type="checkbox" id="mhbo-mod-confirm-price" style="margin-top: 2px;">
							<span><?php esc_html_e( 'I confirm the updated reservation total above.', 'modern-hotel-booking' ); ?></span>
						</label>
					</div>
					<div style="margin-bottom: 20px;">
						<label class="mhbo-portal-label" for="mhbo-mod-notes"><?php esc_html_e( 'Special Requests / Reason for Change', 'modern-hotel-booking' ); ?></label>
						<textarea id="mhbo-mod-notes" class="mhbo-portal-input" rows="2" placeholder="<?php esc_attr_e( 'Additional notes for the front desk...', 'modern-hotel-booking' ); ?>"></textarea>
					</div>
					<div style="display: flex; justify-content: flex-end; gap: 12px;">
						<button type="button" id="mhbo-mod-modal-close" class="mhbo-portal-btn mhbo-portal-btn-secondary" aria-label="<?php esc_attr_e( 'Cancel and close modification dialog', 'modern-hotel-booking' ); ?>"><?php esc_html_e( 'Cancel', 'modern-hotel-booking' ); ?></button>
						<button type="button" id="mhbo-mod-modal-submit" class="mhbo-portal-btn mhbo-portal-btn-primary"><?php esc_html_e( 'Update Reservation', 'modern-hotel-booking' ); ?></button>
					</div>
				</div>
			</div>

			<!-- Edit Guest Profile Modal -->
			<div id="mhbo-portal-edit-profile-modal" class="mhbo-portal-modal" role="dialog" aria-modal="true" aria-labelledby="mhbo-edit-profile-modal-title">
				<div class="mhbo-modal-content">
					<h3 id="mhbo-edit-profile-modal-title" style="margin: 0 0 12px 0; color: #0f172a; font-size: 18px; font-weight: 700;"><?php esc_html_e( 'Update Contact Details', 'modern-hotel-booking' ); ?></h3>
					<p style="font-size: 13px; color: #64748b; margin-bottom: 16px; line-height: 1.5;"><?php esc_html_e( 'Keep your contact information up to date to ensure seamless check-in communications and receipts.', 'modern-hotel-booking' ); ?></p>
					<div id="mhbo-profile-modal-error" style="display:none; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:13px; color:#991b1b;"></div>
					<div style="margin-bottom: 12px;">
						<label class="mhbo-portal-label" for="mhbo-profile-name"><?php esc_html_e( 'Full Guest Name', 'modern-hotel-booking' ); ?></label>
						<input type="text" id="mhbo-profile-name" class="mhbo-portal-input" required>
					</div>
					<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
						<div>
							<label class="mhbo-portal-label" for="mhbo-profile-phone"><?php esc_html_e( 'Phone Number', 'modern-hotel-booking' ); ?></label>
							<input type="tel" id="mhbo-profile-phone" class="mhbo-portal-input" placeholder="+1 555-0199">
						</div>
						<div>
							<label class="mhbo-portal-label" for="mhbo-profile-email"><?php esc_html_e( 'Email Address', 'modern-hotel-booking' ); ?></label>
							<input type="email" id="mhbo-profile-email" class="mhbo-portal-input" required>
						</div>
					</div>
					<div style="margin-bottom: 12px;">
						<label class="mhbo-portal-label" for="mhbo-profile-arrival"><?php esc_html_e( 'Estimated Arrival Time', 'modern-hotel-booking' ); ?></label>
						<input type="text" id="mhbo-profile-arrival" class="mhbo-portal-input" placeholder="e.g. 15:00 / 3:00 PM">
					</div>
					<div style="margin-bottom: 20px;">
						<label class="mhbo-portal-label" for="mhbo-profile-requests"><?php esc_html_e( 'Special Requests & Preferences', 'modern-hotel-booking' ); ?></label>
						<textarea id="mhbo-profile-requests" class="mhbo-portal-input" rows="2" placeholder="<?php esc_attr_e( 'Dietary preferences, quiet room, late check-in notes...', 'modern-hotel-booking' ); ?>"></textarea>
					</div>
					<div style="display: flex; justify-content: flex-end; gap: 12px;">
						<button type="button" id="mhbo-profile-modal-close" class="mhbo-portal-btn mhbo-portal-btn-secondary"><?php esc_html_e( 'Cancel', 'modern-hotel-booking' ); ?></button>
						<button type="button" id="mhbo-profile-modal-submit" class="mhbo-portal-btn mhbo-portal-btn-primary"><?php esc_html_e( 'Save Changes', 'modern-hotel-booking' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
