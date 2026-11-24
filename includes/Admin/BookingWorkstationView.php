<?php declare(strict_types=1);

namespace MHBO\Admin;

use MHBO\Core\Capabilities;
use MHBO\Core\Email;
use MHBO\Core\GuestIdentityResolver;
use MHBO\Core\GuestManager;
use MHBO\Core\HotelTime;
use MHBO\Core\I18n;

use MHBO\Core\Money;
use MHBO\Core\Pricing;

use MHBO\Core\Tax;
use MHBO\Core\Tier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Booking Workstation View
 *
 * Renders the manual reservation workstation, edit/folio management
 * workstation, and the bookings list & FullCalendar section.
 */
class BookingWorkstationView {

	/**
	 * Render the manual reservation creation workstation.
	 *
	 * @param array<int, object> $all_rooms Available room records.
	 */
	public function render_add_booking_workstation( array $all_rooms ): void {
		$currency   = ( class_exists( Pricing::class ) ) ? Pricing::get_currency_code() : 'USD';
		$today_dt   = HotelTime::now();
		$today_date = $today_dt->format( 'Y-m-d' );
		$tom_date   = $today_dt->modify( '+1 day' )->format( 'Y-m-d' );
		$crm_active           = class_exists( GuestManager::class ) && GuestManager::is_enabled();
		$crm_guests           = $crm_active ? GuestManager::get_recent_active_guests( 100 ) : array();
		$can_use_tax_engine   = false;
		
		$can_use_deposits = false;
		
		$can_override_tax     = $can_use_tax_engine && Capabilities::can_override_booking_tax();
		$can_override_pricing = Capabilities::can_override_booking_pricing();
		$can_process_payments = Capabilities::can_process_payments();
		?>
				<div class="mhbo-booking-workstation">

				<!-- 1. Hero Header Strip -->
				<div class="mhbo-workstation-hero">
					<div class="mhbo-hero-identity">
						<div class="mhbo-hero-avatar">
							<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
						</div>
						<div class="mhbo-hero-meta">
							<h2>
								<span><?php esc_html_e( 'New Reservation', 'modern-hotel-booking' ); ?></span>
								<span class="mhbo-ws-hero-subtag"><?php esc_html_e( '(Front Desk / Phone Walk-in)', 'modern-hotel-booking' ); ?></span>
								<span id="mhbo_add_status_badge" class="mhbo-ws-status-pill is-confirmed">
									<?php echo esc_html( I18n::translate_status( 'confirmed' ) ); ?>
								</span>
							</h2>
							<div class="mhbo-hero-subtitle">
								<span id="mhbo_add_hero_dates_text"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $today_date ) ) ); ?> → <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $tom_date ) ) ); ?></span>
								<span aria-hidden="true">&bull;</span>
								<span id="mhbo_add_nights_pill" class="mhbo-ws-nights-pill">1 Night</span>
								<span aria-hidden="true">&bull;</span>
								<span id="mhbo_add_occupancy_pill">2 Guests</span>
							</div>
						</div>
					</div>
				</div>

				<form method="post" id="mhbo_add_booking_form">
					<?php wp_nonce_field( 'mhbo_add_manual_booking' ); ?>
					<input type="hidden" name="guest_id" id="mhbo_add_guest_id" value="0">

					<div class="mhbo-workstation-grid">
						<!-- LEFT MAIN COLUMN (1.75fr) -->
						<div class="mhbo-workstation-col-main">

							<!-- Card 1: Guest Profile & CRM Identity -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="mhbo-ws-step-badge" aria-hidden="true">1</span> <span class="dashicons dashicons-admin-users" style="color:#0369a1;" aria-hidden="true"></span> <?php esc_html_e( 'Guest Profile & CRM Identity', 'modern-hotel-booking' ); ?></h3>
									<?php if ( $crm_active ) : ?>
										<span class="mhbo-ws-crm-badge">
											<?php esc_html_e( 'CRM Enabled', 'modern-hotel-booking' ); ?>
										</span>
									<?php endif; ?>
								</div>
								<div class="mhbo-card-body-padded">
									<?php if ( $crm_active && ! empty( $crm_guests ) ) : ?>
										<!-- CRM Quick Select for Returning Guests -->
										<div class="mhbo-ws-crm-lookup-box">
											<label for="mhbo_crm_guest_select" class="mhbo-ws-crm-label">
												<span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'Returning Guest Lookup (CRM Quick-Fill):', 'modern-hotel-booking' ); ?>
											</label>
											<div class="mhbo-crm-lookup-flex">
												<select id="mhbo_crm_guest_select" class="mhbo-ws-crm-select">
													<option value=""><?php esc_html_e( '-- New Guest / Walk-in (Enter Details Manually) --', 'modern-hotel-booking' ); ?></option>
													<?php foreach ( $crm_guests as $cg ) : ?>
														<?php
														$cg_name = trim( ( $cg->first_name ?? '' ) . ' ' . ( $cg->last_name ?? '' ) );
														$cg_name = '' !== $cg_name ? $cg_name : ( $cg->email ?: '#' . $cg->id );
														$cg_vip  = ( ! empty( $cg->vip_status ) && 'none' !== $cg->vip_status ) ? ' [' . strtoupper( str_replace( '_', ' ', $cg->vip_status ) ) . ']' : '';
														$cg_flag = ! empty( $cg->caution_flag ) ? ' ⚠️' : '';
														?>
														<option value="<?php echo esc_attr( (string) $cg->id ); ?>"
															data-name="<?php echo esc_attr( $cg_name ); ?>"
															data-email="<?php echo esc_attr( (string) ( $cg->email ?? '' ) ); ?>"
															data-phone="<?php echo esc_attr( (string) ( $cg->phone ?? '' ) ); ?>"
															data-vip="<?php echo esc_attr( (string) ( $cg->vip_status ?? 'none' ) ); ?>"
															data-caution="<?php echo esc_attr( ! empty( $cg->caution_flag ) ? '1' : '0' ); ?>">
															<?php echo esc_html( $cg_name . ' (' . ( $cg->email ?? 'No email' ) . ')' . $cg_vip . $cg_flag ); ?>
														</option>
													<?php endforeach; ?>
												</select>
												<span id="mhbo_crm_match_badge" style="display:none; padding:3px 8px; border-radius:99px; font-size:11px; font-weight:700;" role="status" aria-live="polite"></span>
											</div>
											<div id="mhbo_crm_caution_alert" class="mhbo-ws-caution-alert" style="display:none;" role="alert" aria-live="assertive">
												<span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e( 'Caution: Problematic guest flagged by staff in CRM.', 'modern-hotel-booking' ); ?>
											</div>
										</div>
									<?php endif; ?>

									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_add_customer_name"><?php echo esc_html( I18n::get_label( 'label_customer_name' ) ); ?> <span style="color:#dc2626;">*</span></label></th>
											<td><input type="text" id="mhbo_add_customer_name" name="customer_name" required class="regular-text" style="width:100%; max-width:420px;" placeholder="<?php esc_attr_e( 'Full Name (e.g. Jane Doe)', 'modern-hotel-booking' ); ?>"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_customer_email"><?php echo esc_html( I18n::get_label( 'label_email' ) ); ?> <span style="color:#dc2626;">*</span></label></th>
											<td><input type="email" id="mhbo_add_customer_email" name="customer_email" required class="regular-text" style="width:100%; max-width:420px;" placeholder="<?php esc_attr_e( 'guest@example.com', 'modern-hotel-booking' ); ?>"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_customer_phone"><?php echo esc_html( I18n::get_label( 'label_phone' ) ); ?></label></th>
											<td><input type="tel" id="mhbo_add_customer_phone" name="customer_phone" class="regular-text" style="width:100%; max-width:420px;" placeholder="<?php esc_attr_e( '+1 (555) 000-0000', 'modern-hotel-booking' ); ?>"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_guests"><?php echo esc_html( I18n::get_label( 'label_guests' ) ); ?></label></th>
											<td>
												<input type="number" name="guests" id="mhbo_add_guests" value="2" min="1" max="20" class="small-text">
												<span class="description"><?php esc_html_e( 'Adults (18+)', 'modern-hotel-booking' ); ?></span>
											</td>
										</tr>
										
									</table>
								</div>
							</div>

							<!-- Card 2: Room Unit Assignment & Stay Calendar -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="mhbo-ws-step-badge" aria-hidden="true">2</span> <span class="dashicons dashicons-calendar-alt" style="color:#047857;" aria-hidden="true"></span> <?php esc_html_e( 'Room Unit Assignment & Stay Dates', 'modern-hotel-booking' ); ?></h3>
									<span class="mhbo-ws-nights-indicator" id="mhbo_add_calendar_nights_indicator">
										<?php esc_html_e( '1 Night Stay', 'modern-hotel-booking' ); ?>
									</span>
								</div>
								<div class="mhbo-card-body-padded">
									<div id="mhbo_add_room_avail_banner" style="display:none; margin-bottom:14px; padding:10px 14px; border-radius:6px; font-weight:600; font-size:13px;" role="status" aria-live="polite"></div>

									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_add_room_id"><?php echo esc_html( I18n::get_label( 'label_room' ) ); ?> <span style="color:#dc2626;">*</span></label></th>
											<td>
												<select name="room_id" id="mhbo_add_room_id" required class="mhbo-ws-input-lg">
													<option value=""><?php echo esc_html( I18n::get_label( 'label_select_room' ) ); ?></option>
													<?php foreach ( $all_rooms as $rm ) : ?>
														<option value="<?php echo esc_attr( $rm->id ); ?>" data-price="<?php echo esc_attr( $rm->base_price ); ?>" data-room-name="<?php echo esc_attr( $rm->room_number . ' (' . I18n::decode( $rm->type_name ) . ')' ); ?>">
															<?php echo esc_html( $rm->room_number . ' (' . I18n::decode( $rm->type_name ) . ') - ' . I18n::format_currency( $rm->base_price ) . ' / night' ); ?>
														</option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_date_range_picker"><?php esc_html_e( 'Stay Dates (Interactive Calendar)', 'modern-hotel-booking' ); ?> <span style="color:#dc2626;">*</span></label></th>
											<td>
												<div class="mhbo-ws-date-range-wrap">
													<input type="text" id="mhbo_add_date_range_picker" class="regular-text mhbo-ws-date-range-input" placeholder="<?php esc_attr_e( 'e.g. 2026-10-08 to 2026-10-12, 08/10/2026 - 12/10/2026, or click calendar', 'modern-hotel-booking' ); ?>">
													<span class="dashicons dashicons-calendar-alt mhbo-ws-date-range-icon" aria-hidden="true"></span>
													<button type="button" id="mhbo_add_clear_dates_btn" class="mhbo-ws-clear-dates-btn" aria-label="<?php esc_attr_e( 'Clear stay dates', 'modern-hotel-booking' ); ?>" title="<?php esc_attr_e( 'Clear stay dates', 'modern-hotel-booking' ); ?>"><span aria-hidden="true">&times;</span></button>
												</div>
												<input type="hidden" name="check_in" id="mhbo_add_check_in" value="<?php echo esc_attr( $today_date ); ?>" required>
												<input type="hidden" name="check_out" id="mhbo_add_check_out" value="<?php echo esc_attr( $tom_date ); ?>" required>
												<p class="description" style="margin-top:4px;">
													<?php esc_html_e( 'Select stay dates on the calendar. Daily pricing, room availability status, and stay policies update in real time.', 'modern-hotel-booking' ); ?>
												</p>
											</td>
										</tr>
									</table>

									<!-- Inline PMS Availability & Daily Pricing Calendar Mount (Full Card Width) -->
									<div id="mhbo_add_calendar_mount" class="mhbo-pms-calendar-wrapper" style="margin-top:16px; display:none;"></div>

									<!-- PMS Calendar Legend -->
									<div class="mhbo-calendar-legend mhbo-pms-calendar-legend" id="mhbo_add_calendar_legend" style="display:none;">
										<span style="display:inline-flex; align-items:center; gap:6px;">
											<span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#10b981;"></span>
											<span><?php esc_html_e( 'Available (Nightly Rate)', 'modern-hotel-booking' ); ?></span>
										</span>
										<span style="display:inline-flex; align-items:center; gap:6px;">
											<span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#ef4444;"></span>
											<span><?php esc_html_e( 'Booked / Confirmed', 'modern-hotel-booking' ); ?></span>
										</span>
										<span style="display:inline-flex; align-items:center; gap:6px;">
											<span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:#f59e0b;"></span>
											<span><?php esc_html_e( 'Pending Hold', 'modern-hotel-booking' ); ?></span>
										</span>
										<span style="display:inline-flex; align-items:center; gap:6px;">
											<span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:linear-gradient(135deg, #ef4444 50%, #10b981 50%); border:1px solid #cbd5e1;"></span>
											<span><?php esc_html_e( 'Turnover (Checkout / Checkin)', 'modern-hotel-booking' ); ?></span>
										</span>
									</div>
								</div>
							</div>

							<?php
							$has_custom_fields = count( (array) get_option( 'mhbo_custom_fields', array() ) ) > 0;
							$is_enhancements_pro = false;
							
							if ( $is_enhancements_pro || $has_custom_fields ) :
								?>
							<!-- Card 3: Stay Enhancements & Extras -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="mhbo-ws-step-badge" aria-hidden="true">3</span> <span class="dashicons dashicons-tag" style="color:#7c3aed;" aria-hidden="true"></span> <?php esc_html_e( 'Stay Enhancements & Extras', 'modern-hotel-booking' ); ?></h3>
									<span class="mhbo-ws-header-hint"><?php esc_html_e( 'Optional add-ons, early/late check-in', 'modern-hotel-booking' ); ?></span>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">

<!-- Custom Fields -->
										<?php
										$custom_fields_defn = get_option( 'mhbo_custom_fields', array() );
										if ( isset( $custom_fields_defn ) && count( $custom_fields_defn ) > 0 ) :
											?>
											<?php foreach ( $custom_fields_defn as $defn ) : ?>
												<?php
												$label    = (string) ( I18n::decode( I18n::encode( $defn['label'] ) ) ?? '' );
												$cf_id    = 'mhbo_add_custom_' . sanitize_html_class( (string) $defn['id'] );
												?>
												<tr>
													<th scope="row"><label for="<?php echo esc_attr( $cf_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
													<td>
														<?php if ( $defn['type'] === 'textarea' ) : ?>
															<textarea id="<?php echo esc_attr( $cf_id ); ?>" name="mhbo_custom[<?php echo esc_attr( $defn['id'] ); ?>]" rows="3" class="regular-text mhbo-ws-input-lg"></textarea>
														<?php else : ?>
															<input id="<?php echo esc_attr( $cf_id ); ?>" type="<?php echo esc_attr( $defn['type'] ); ?>" name="mhbo_custom[<?php echo esc_attr( $defn['id'] ); ?>]" class="regular-text mhbo-ws-input-lg">
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										<?php endif; ?>
									</table>
								</div>
							</div>
							<?php endif; ?>

							<!-- Card 4: Concessions & Promotions -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<?php
									$card4_title = __( 'Discounts & Concessions', 'modern-hotel-booking' );
									
									?>
									<h3><span class="mhbo-ws-step-badge" aria-hidden="true">4</span> <span class="dashicons dashicons-tickets-alt" style="color:#b45309;" aria-hidden="true"></span> <?php echo esc_html( $card4_title ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">

<tr>
											<th scope="row"><label for="mhbo_add_discount_amount"><?php echo esc_html( I18n::get_label( 'label_discount_amount' ) ); ?></label></th>
											<td>
												<div class="mhbo-ws-inline-pair">
													<input type="number" step="any" name="discount_amount" id="mhbo_add_discount_amount" value="0.00" class="small-text mhbo-ws-input-sm"<?php echo ! $can_override_pricing ? ' readonly' : ''; ?>>
													<span class="description" style="font-weight:600;"><?php echo esc_html( $currency ); ?></span>
												</div>
												<?php
												$add_disc_locked_desc = __( 'Manual courtesy discounts require Hotel Manager or Administrator permissions.', 'modern-hotel-booking' );
												
												?>
												<p class="description" style="margin-top:4px;"><?php echo esc_html( $can_override_pricing ? __( 'Direct discount or courtesy rate adjustment.', 'modern-hotel-booking' ) : $add_disc_locked_desc ); ?></p>
											</td>
										</tr>

</table>
								</div>
							</div>

							<!-- Card 5: Real-time Charges & Folio Breakdown Preview -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="mhbo-ws-step-badge" aria-hidden="true">5</span> <span class="dashicons dashicons-media-spreadsheet" style="color:#2563eb;" aria-hidden="true"></span> <?php esc_html_e( 'Live Folio & Charges Breakdown', 'modern-hotel-booking' ); ?></h3>
									<span class="mhbo-ws-header-hint"><?php esc_html_e( 'Real-time USALI preview', 'modern-hotel-booking' ); ?></span>
								</div>
								<div class="mhbo-table-responsive">
									<table class="mhbo-charges-table" id="mhbo_add_folio_table">
										<thead>
											<tr>
												<th scope="col" style="width:110px;"><?php esc_html_e( 'Category', 'modern-hotel-booking' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Charge Description', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:100px;"><?php esc_html_e( 'Rate', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:center; width:90px;"><?php esc_html_e( 'Qty / Nights', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:90px;"><?php esc_html_e( 'Tax', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:110px;"><?php esc_html_e( 'Total', 'modern-hotel-booking' ); ?></th>
											</tr>
										</thead>
										<tbody id="mhbo_add_folio_tbody">
											<tr class="mhbo-ws-empty-row">
												<td colspan="6" style="text-align:center; padding:20px; color:#475569;">
													<?php esc_html_e( 'Select room and dates above to calculate itemized folio charges.', 'modern-hotel-booking' ); ?>
												</td>
											</tr>
										</tbody>
										<tfoot style="background:#f8fafc; border-top:2px solid #cbd5e1; font-weight:700;">
											<tr id="mhbo_add_folio_foot_subtotal_row" class="mhbo-hidden" style="display:none !important;" hidden>
												<td colspan="5" style="text-align:right; padding:8px 12px; color:#475569; font-size:12px;" id="mhbo_add_folio_foot_subtotal_label"><?php echo esc_html( ( class_exists( Tax::class ) && Tax::is_vat() ) ? sprintf( I18n::get_label( 'label_subtotal_net_vat' ), Tax::get_label( null, 'vat' ) ) : esc_html__( 'Subtotal Net (excl. tax):', 'modern-hotel-booking' ) ); ?></td>
												<td style="text-align:right; padding:8px 12px; font-variant-numeric:tabular-nums;" id="mhbo_add_folio_foot_subtotal_net">0.00</td>
											</tr>
											<tr id="mhbo_add_folio_foot_tax_row" class="mhbo-hidden" style="display:none !important;" hidden>
												<td colspan="5" style="text-align:right; padding:6px 12px; color:#475569; font-size:12px;" id="mhbo_add_folio_foot_tax_label">
													<?php
													if ( class_exists( Tax::class ) && Tax::is_vat() ) {
														echo esc_html( Tax::get_label( null, 'vat' ) . ' (included):' );
													} else {
														/* translators: %s: Tax category label */
														echo esc_html( sprintf( __( 'Taxes (%s):', 'modern-hotel-booking' ), class_exists( Tax::class ) ? Tax::get_label( null, 'sales_tax' ) : 'Tax' ) );
													}
													?>
												</td>
												<td style="text-align:right; padding:6px 12px; font-variant-numeric:tabular-nums; color:#475569;" id="mhbo_add_folio_foot_tax">0.00</td>
											</tr>
											<tr style="border-top:1px solid #e2e8f0; font-size:14px;">
												<td colspan="5" style="text-align:right; padding:12px; font-weight:800; color:#0f172a;" id="mhbo_add_folio_foot_total_label"><?php echo esc_html( ( class_exists( Tax::class ) && Tax::is_vat() ) ? esc_html__( 'Estimated Total (VAT incl.):', 'modern-hotel-booking' ) : esc_html__( 'Estimated Total Gross:', 'modern-hotel-booking' ) ); ?></td>
												<td style="text-align:right; padding:12px; font-weight:800; color:#0f172a; font-size:16px; font-variant-numeric:tabular-nums;" id="mhbo_add_folio_foot_total">0.00</td>
											</tr>
										</tfoot>
									</table>
								</div>
							</div>

						</div>

						<!-- RIGHT SIDEBAR (1.15fr) -->
						<div class="mhbo-workstation-col-sidebar">

							<!-- Card 1: Financial Overview KPIs -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-chart-pie" style="color:#0369a1;" aria-hidden="true"></span> <?php esc_html_e( 'Financial Overview', 'modern-hotel-booking' ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<div class="mhbo-kpi-grid">
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Total Charges', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value" id="mhbo_overview_total_val">0.00</span>
										</div>
										
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Initial Paid', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value mhbo-kpi-value-paid" id="mhbo_overview_paid_val">0.00</span>
										</div>
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Balance Due', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value mhbo-kpi-value-due" id="mhbo_overview_pending_val">0.00</span>
										</div>
									</div>
								</div>
							</div>

							<!-- Card 2: Billing & Initial Settlement -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-money-alt" style="color:#047857;" aria-hidden="true"></span> <?php esc_html_e( 'Billing & Initial Payment', 'modern-hotel-booking' ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_add_total_price"><?php echo esc_html( I18n::get_label( 'label_total_price' ) ); ?> <span style="color:#dc2626;">*</span></label></th>
											<td>
												<input type="number" step="any" name="total_price" id="mhbo_add_total_price" required value="0.00" class="regular-text" style="width:100%;<?php echo ! $can_override_pricing ? ' background:#f1f5f9; cursor:not-allowed;' : ''; ?>"<?php echo ! $can_override_pricing ? ' readonly' : ''; ?>>
												<?php if ( ! $can_override_pricing ) : ?>
													<?php
													$add_total_locked_desc = __( 'Server-calculated from room rates. Manual rate overrides require Hotel Manager or Administrator permissions.', 'modern-hotel-booking' );
													
													?>
													<p class="description" style="margin-top:4px;"><?php echo esc_html( $add_total_locked_desc ); ?></p>
												<?php endif; ?>
											</td>
										</tr>
										
										<tr>
											<th scope="row"><label for="mhbo_add_payment_amount"><?php esc_html_e( 'Initial Payment Recorded', 'modern-hotel-booking' ); ?></label></th>
											<td>
												<input type="number" step="any" name="payment_amount" id="mhbo_add_payment_amount" value="0.00" class="regular-text" style="width:100%;<?php echo ! $can_process_payments ? ' background:#f1f5f9; cursor:not-allowed;' : ''; ?>"<?php echo ! $can_process_payments ? ' readonly' : ''; ?>>
												<?php if ( $can_process_payments ) : ?>
													<!-- Quick Tender Buttons -->
													<div class="mhbo-quick-tender-grid">
														<button type="button" class="button button-small mhbo-quick-tender-btn" data-action="full">
															<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Pay in Full', 'modern-hotel-booking' ); ?>
														</button>
														
														<button type="button" class="button button-small mhbo-quick-tender-btn" data-action="zero">
															<span class="dashicons dashicons-clock" aria-hidden="true"></span>
															<?php
															/* translators: %s: zero currency formatted amount */
															echo esc_html( sprintf( __( 'Pay on Arrival (%s)', 'modern-hotel-booking' ), I18n::format_currency( 0 ) ) );
															?>
														</button>
													</div>
												<?php else : ?>
													<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Recording initial payments requires the Process Payments capability.', 'modern-hotel-booking' ); ?></p>
												<?php endif; ?>
											</td>
										</tr>
										
										<tr>
											<th scope="row"><?php echo esc_html( I18n::get_label( 'label_payment_rcvd' ) ); ?></th>
											<td>
												<label class="mhbo-ws-checkbox-row">
													<input type="hidden" name="payment_received" value="0">
													<input type="checkbox" name="payment_received" id="mhbo_add_payment_received" value="1" <?php disabled( ! $can_process_payments ); ?>>
													<span><?php echo esc_html( I18n::get_label( 'label_mark_rcvd' ) ); ?></span>
												</label>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_payment_method"><?php echo esc_html( I18n::get_label( 'label_payment_method' ) ); ?></label></th>
											<td>
												<?php if ( ! $can_process_payments ) : ?>
													<input type="hidden" name="payment_method" value="arrival">
												<?php endif; ?>
												<select name="payment_method" id="mhbo_add_payment_method" style="width:100%;" <?php disabled( ! $can_process_payments ); ?>>
													<option value="arrival" selected>
														<?php echo esc_html( I18n::get_label( 'label_pay_arrival_manual' ) ); ?>
													</option>
													<option value="credit_card">
														<?php esc_html_e( 'Credit Card (Front Desk Terminal)', 'modern-hotel-booking' ); ?>
													</option>
													<option value="cash">
														<?php esc_html_e( 'Cash (Front Desk Cashier)', 'modern-hotel-booking' ); ?>
													</option>
													<option value="bank_transfer">
														<?php esc_html_e( 'Direct Bank Transfer', 'modern-hotel-booking' ); ?>
													</option>
													<?php
													$show_commercial_gateways = false;
													
													if ( $show_commercial_gateways ) :
														?>
														<option value="stripe"><?php echo esc_html( I18n::get_label( 'gateway_stripe' ) ); ?></option>
														<option value="paypal"><?php echo esc_html( I18n::get_label( 'gateway_paypal' ) ); ?></option>
														<option value="braintree"><?php echo esc_html( I18n::get_label( 'label_braintree' ) ); ?></option>
													<?php endif; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_amount_outstanding"><?php echo esc_html( I18n::get_label( 'label_amt_out' ) ); ?></label></th>
											<td>
												<input type="text" id="mhbo_add_amount_outstanding" readonly class="regular-text mhbo-ws-outstanding-input mhbo-ws-outstanding-due">
											</td>
										</tr>
									</table>
								</div>
							</div>

							<!-- Card 3: Lifecycle & Internal Front Desk Notes -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-admin-generic" style="color:#475569;" aria-hidden="true"></span> <?php echo esc_html( I18n::get_label( 'title_booking_mgmt' ) ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_add_status"><?php echo esc_html( I18n::get_label( 'label_status_noun' ) ); ?></label></th>
											<td>
												<select name="status" id="mhbo_add_status" style="width:100%;">
													<option value="pending"><?php echo esc_html( I18n::get_label( 'status_pending' ) ); ?></option>
													<option value="confirmed" selected><?php echo esc_html( I18n::get_label( 'status_confirmed' ) ); ?></option>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_booking_language"><?php echo esc_html( I18n::get_label( 'label_booking_lang' ) ); ?></label></th>
											<td>
												<select name="booking_language" id="mhbo_add_booking_language" style="width:100%;">
													<?php foreach ( I18n::get_available_languages() as $lang ) : ?>
														<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $lang, I18n::get_current_language() ); ?>><?php echo esc_html( strtoupper( $lang ) ); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_add_admin_notes"><?php echo esc_html( I18n::get_label( 'label_admin_notes' ) ); ?></label></th>
											<td>
												<textarea name="admin_notes" id="mhbo_add_admin_notes" rows="3" class="large-text" style="width:100%;" placeholder="<?php esc_attr_e( 'Internal guest requests, arrival notes, or housekeeping preferences...', 'modern-hotel-booking' ); ?>"></textarea>
											</td>
										</tr>
									</table>
								</div>
							</div>

						</div>
					</div>

					<!-- Sticky Bottom Action Dock -->
					<div class="mhbo-form-actions-dock">
						<div class="mhbo-ws-dock-summary">
							<span><?php esc_html_e( 'Total:', 'modern-hotel-booking' ); ?> <strong class="mhbo-ws-dock-total">0.00</strong></span>
							<span class="mhbo-ws-dock-sep" aria-hidden="true">&bull;</span>
							<span><?php esc_html_e( 'Balance:', 'modern-hotel-booking' ); ?> <strong class="mhbo-ws-dock-balance">0.00</strong></span>
						</div>
						<div class="mhbo-ws-dock-actions">
							<button type="submit" name="submit_manual_booking" class="button button-primary">
								<span class="dashicons dashicons-plus-alt" aria-hidden="true"></span>
								<span><?php echo esc_html( I18n::get_label( 'label_add_booking' ) ); ?></span>
							</button>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ); ?>" class="button button-secondary">
								<?php echo esc_html( I18n::get_label( 'label_cancel' ) ); ?>
							</a>
						</div>
					</div>
				</form>
				</div><!-- .mhbo-booking-workstation -->
		<?php
	}

	/**
	 * Render the reservation editing and folio management workstation.
	 *
	 * @param object             $edit_data Active booking record.
	 * @param array<int, object> $all_rooms Available room records.
	 */
	public function render_edit_booking_workstation( object $edit_data, array $all_rooms ): void {
		$is_pro_active        = false;

$can_use_tax_engine = false;
		
		$can_use_deposits = false;

$can_override_tax     = $can_use_tax_engine && Capabilities::can_override_booking_tax();
		$can_override_pricing = Capabilities::can_override_booking_pricing();
		$can_process_payments = Capabilities::can_process_payments();
		$can_issue_refunds    = Capabilities::can_issue_refunds();

		$currency                 = ( class_exists( Pricing::class ) ) ? Pricing::get_currency_code() : 'USD';
		$hotel_tax_enabled        = false;
		$hotel_tax_mode           = 'disabled';
		$edit_tax_mode            = 'disabled';
		$global_acc_rate          = 0.0;
		$global_ext_rate          = 0.0;
		$orig_acc_rate            = 0.0;
		$orig_ext_rate            = 0.0;
		$rates_differ_from_global = false;
		
		$folio            = ( class_exists( Pricing::class ) ) ? Pricing::get_folio_breakdown( $edit_data, '', $edit_tax_mode ) : array();
		$edit_amts        = ( class_exists( Pricing::class ) ) ? Pricing::get_booking_payment_amounts( $edit_data ) : null;
		$group_amts       = ( class_exists( Pricing::class ) && ! empty( $edit_data->multi_room_parent ) ) ? Pricing::get_group_payment_amounts( $edit_data ) : null;
		$is_group_booking = ( null !== $group_amts && $group_amts['rooms_count'] >= 2 );
		$room_data        = ( class_exists( Pricing::class ) && ! empty( $edit_data->room_id ) ) ? Pricing::get_room_pricing_data( (int) $edit_data->room_id ) : null;
		$room_title       = (string) ( ( $folio['room_name'] ?? '' ) ?: ( ( $room_data && $room_data->name ) ? I18n::decode( $room_data->name ) : '' ) ?: __( 'Assigned Unit', 'modern-hotel-booking' ) );
		$room_num         = $folio['room_number'] ?? ( $room_data ? $room_data->room_number : '' );

		$crm_guest_id = 0;
		$crm_guest    = null;
		if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) {
			$crm_guest_id = (int) ( $edit_data->guest_id ?? 0 );
			if ( $crm_guest_id <= 0 && ! empty( $edit_data->customer_email ) ) {
				$crm_guest_id = GuestIdentityResolver::resolve_and_link_guest(
					array(
						'customer_name'  => $edit_data->customer_name,
						'customer_email' => $edit_data->customer_email,
						'customer_phone' => $edit_data->customer_phone ?? '',
					)
				);
			}
			$crm_guest = $crm_guest_id > 0 ? GuestManager::get_guest( $crm_guest_id ) : null;
		}

		$has_early    = false;
		$has_late     = false;
		$saved_custom = ! empty( $edit_data->custom_fields ) ? json_decode( (string) $edit_data->custom_fields, true ) : array();
		if ( is_array( $saved_custom ) ) {
			$has_early = ! empty( $saved_custom['_early_checkin'] );
			$has_late  = ! empty( $saved_custom['_late_checkout'] );
		}
		if ( ! empty( $edit_data->booking_extras ) ) {
			$ex_check = json_decode( (string) $edit_data->booking_extras, true );
			if ( is_array( $ex_check ) ) {
				if ( isset( $ex_check['_early_checkin'] ) ) {
					$has_early = ! empty( $ex_check['_early_checkin']['quantity'] ?? $ex_check['_early_checkin'] );
				}
				if ( isset( $ex_check['_late_checkout'] ) ) {
					$has_late = ! empty( $ex_check['_late_checkout']['quantity'] ?? $ex_check['_late_checkout'] );
				}
				foreach ( $ex_check as $k => $item ) {
					$item_id = is_array( $item ) ? ( $item['id'] ?? $k ) : $k;
					if ( '_early_checkin' === $item_id ) {
						$has_early = true;
					}
					if ( '_late_checkout' === $item_id ) {
						$has_late = true;
					}
				}
			}
		}

		$edit_stay_timing = null;

$early_fee_raw = ( is_array( $saved_custom ) && array_key_exists( '_early_checkin_fee_locked', $saved_custom ) )
			? (string) $saved_custom['_early_checkin_fee_locked']
			: (string) ( $edit_stay_timing['early_checkin']['fee'] ?? get_option( 'mhbo_early_checkin_fee', '0' ) );
		$late_fee_raw  = ( is_array( $saved_custom ) && array_key_exists( '_late_checkout_fee_locked', $saved_custom ) )
			? (string) $saved_custom['_late_checkout_fee_locked']
			: (string) ( $edit_stay_timing['late_checkout']['fee'] ?? get_option( 'mhbo_late_checkout_fee', '0' ) );

		$early_fee_m = Money::fromDecimal( $early_fee_raw, Pricing::get_currency_code() );
		$late_fee_m  = Money::fromDecimal( $late_fee_raw, Pricing::get_currency_code() );

		// Dynamic status badge color & label
		$status_key   = $folio['status_key'] ?? ( $edit_amts['effective_payment_status'] ?? 'pending' );
		$status_label = $folio['status_label'] ?? ( $edit_amts['effective_payment_status_label'] ?? __( 'Pending', 'modern-hotel-booking' ) );
		$badge_bg     = '#f1f5f9';
		$badge_color  = '#475569';
		if ( ! empty( $folio['is_overpaid'] ) ) {
			$badge_bg    = '#e0f2fe';
			$badge_color = '#0369a1';
		} elseif ( ! empty( $folio['is_paid_in_full'] ) ) {
			$badge_bg    = '#dcfce7';
			$badge_color = '#166534';
		} elseif ( in_array( $status_key, array( 'deposit_paid', 'partially_paid' ), true ) ) {
			$badge_bg    = '#fef3c7';
			$badge_color = '#92400e';
		} elseif ( in_array( $status_key, array( 'urgent_unpaid', 'overdue' ), true ) ) {
			$badge_bg    = '#fee2e2';
			$badge_color = '#991b1b';
		}
		?>
				<div class="mhbo-booking-workstation">

						<?php if ( $is_group_booking ) : ?>
							<?php
							$grp_is_settled  = ! empty( $group_amts['is_paid'] );
							$grp_badge_bg    = $grp_is_settled ? '#dcfce7' : '#fef3c7';
							$grp_badge_color = $grp_is_settled ? '#166534' : '#92400e';
							if ( $grp_is_settled ) {
								/* translators: %s: Formatted total settled amount */
								$grp_badge_lbl = sprintf( __( '✓ Group Settled (%s)', 'modern-hotel-booking' ), I18n::format_currency( $group_amts['total'] ) );
							} else {
								/* translators: %s: Formatted outstanding balance due */
								$grp_badge_lbl = sprintf( __( 'Group Due: %s', 'modern-hotel-booking' ), I18n::format_currency( $group_amts['remaining'] ) );
							}
							$grp_magic_base  = '';
							
							$grp_master_url = $grp_magic_base ? add_query_arg( 'pay_scope', 'group', $grp_magic_base ) : '';
							$grp_room_url   = $grp_magic_base ? add_query_arg( 'pay_scope', 'room', $grp_magic_base ) : '';
							?>
					<div class="mhbo-group-ribbon">
						<div class="mhbo-group-ribbon-head">
							<div class="mhbo-group-ribbon-title-wrap">
								<span class="dashicons dashicons-groups mhbo-group-ribbon-icon" aria-hidden="true"></span>
								<div>
									<h3 class="mhbo-group-ribbon-title">
										<span>
											<?php
											/* translators: %d: Number of rooms in group reservation */
											echo esc_html( sprintf( __( 'Group Reservation (%d Rooms in Party)', 'modern-hotel-booking' ), (int) $group_amts['rooms_count'] ) );
											?>
										</span>
										<span class="mhbo-group-token-badge">
											<?php echo esc_html( $group_amts['parent_token'] ); ?>
										</span>
										<span style="background:<?php echo esc_attr( $grp_badge_bg ); ?>; color:<?php echo esc_attr( $grp_badge_color ); ?>; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:700;">
											<?php echo esc_html( $grp_badge_lbl ); ?>
										</span>
									</h3>
									<div class="mhbo-group-ribbon-totals">
										<span><strong><?php esc_html_e( 'Group Total:', 'modern-hotel-booking' ); ?></strong> <?php echo esc_html( I18n::format_currency( $group_amts['total'] ) ); ?></span>
										<span aria-hidden="true"> &bull; </span>
										<span><strong><?php esc_html_e( 'Total Paid:', 'modern-hotel-booking' ); ?></strong> <?php echo esc_html( I18n::format_currency( $group_amts['received'] ) ); ?></span>
										<span aria-hidden="true"> &bull; </span>
										<span><strong><?php esc_html_e( 'Remaining Balance:', 'modern-hotel-booking' ); ?></strong> <?php echo esc_html( I18n::format_currency( $group_amts['remaining'] ) ); ?></span>
									</div>
								</div>
							</div>

</div>

						<!-- Sibling Rooms Switcher Chips -->
						<div class="mhbo-group-chips-row">
							<span class="mhbo-group-chips-label"><?php esc_html_e( 'Rooms in this group:', 'modern-hotel-booking' ); ?></span>
							<?php foreach ( $group_amts['bookings'] as $sib ) : ?>
								<?php
								$is_curr_sib = ( (int) $sib->id === (int) $edit_data->id );
								$sib_amts    = Pricing::get_booking_payment_amounts( $sib );
								$sib_rnum    = ! empty( $sib->room_number ) ? 'Room ' . $sib->room_number : '#' . $sib->id;
								$sib_rname   = ! empty( $sib->room_name ) ? I18n::decode( $sib->room_name ) : '';
								$sib_label   = trim( $sib_rnum . ( '' !== $sib_rname ? ' (' . $sib_rname . ')' : '' ) );
								$sib_amt_str = I18n::format_currency( $sib_amts['total'] );
								$sib_state   = $sib_amts['is_paid'] ? '✓' : ( I18n::format_currency( $sib_amts['remaining'] ) . ' ' . __( 'due', 'modern-hotel-booking' ) );
								$sib_url     = wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=edit&id={$sib->id}" ), 'mhbo_edit_booking_' . $sib->id );
								?>
								<?php if ( $is_curr_sib ) : ?>
									<span class="mhbo-group-chip mhbo-group-chip--active">
										<span class="dashicons dashicons-building" aria-hidden="true"></span> <?php echo esc_html( $sib_label ); ?> (<?php esc_html_e( 'This Room', 'modern-hotel-booking' ); ?>) &bull; <?php echo esc_html( $sib_amt_str ); ?> (<?php echo esc_html( $sib_state ); ?>)
									</span>
								<?php else : ?>
									<a href="<?php echo esc_url( $sib_url ); ?>" class="mhbo-group-chip">
										<span class="dashicons dashicons-building" aria-hidden="true"></span> <?php echo esc_html( $sib_label ); ?> &bull; <?php echo esc_html( $sib_amt_str ); ?> (<?php echo esc_html( $sib_state ); ?>) &rarr;
									</a>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<!-- 1. Hero Header Strip -->
				<div class="mhbo-workstation-hero">
					<div class="mhbo-hero-identity">
						<div class="mhbo-hero-avatar">
							<span class="dashicons dashicons-id-alt" aria-hidden="true"></span>
						</div>
						<div class="mhbo-hero-meta">
							<h2>
								<span><?php echo esc_html( $edit_data->customer_name ); ?></span>
								<span class="mhbo-ws-hero-subtag">#<?php echo (int) $edit_data->id; ?></span>
								<span class="mhbo-ws-status-pill" style="background: <?php echo esc_attr( $badge_bg ); ?>; color: <?php echo esc_attr( $badge_color ); ?>;">
									<?php echo esc_html( $status_label ); ?>
								</span>
							</h2>
							<div class="mhbo-hero-subtitle">
								<span><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> <?php echo esc_html( $folio['check_in_formatted'] ?? $edit_data->check_in ); ?> → <?php echo esc_html( $folio['check_out_formatted'] ?? $edit_data->check_out ); ?> (<span id="mhbo_edit_hero_nights_pill"><?php echo (int) ( $folio['nights'] ?? 1 ); ?></span> <?php esc_html_e( 'nights', 'modern-hotel-booking' ); ?>)</span>
								<span aria-hidden="true">&bull;</span>
								<span><span class="dashicons dashicons-building" aria-hidden="true"></span> <?php echo esc_html( $room_title ); ?><?php echo ! empty( $room_num ) ? ' (Room ' . esc_html( $room_num ) . ')' : ''; ?></span>
								<?php if ( $crm_guest && ! empty( $crm_guest->vip_status ) && 'none' !== $crm_guest->vip_status ) : ?>
									<span aria-hidden="true">&bull;</span>
									<span class="mhbo-ws-vip-pill">⭐ <?php echo esc_html( str_replace( '_', ' ', $crm_guest->vip_status ) ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<div class="mhbo-hero-actions">
								
								<?php if ( ! empty( $edit_data->customer_phone ) ) : ?>
									<?php
									$clean_phone = (string) preg_replace( '/[^0-9+]/', '', (string) $edit_data->customer_phone );
									if ( '' !== $clean_phone ) :
										$wa_url = 'https://api.whatsapp.com/send?phone=' . rawurlencode( $clean_phone );
										?>
								<a href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer" class="mhbo-hero-btn mhbo-hero-btn-whatsapp" title="<?php esc_attr_e( 'Chat with guest on WhatsApp', 'modern-hotel-booking' ); ?>">
									<span class="dashicons dashicons-format-chat" aria-hidden="true"></span> <?php esc_html_e( 'WhatsApp', 'modern-hotel-booking' ); ?>
								</a>
									<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>

				<form method="post" id="mhbo_booking_edit_form">
							<?php wp_nonce_field( 'mhbo_update_booking' ); ?>
					<input type="hidden" name="booking_id" value="<?php echo esc_attr( $edit_data->id ); ?>">
					<input type="hidden" name="total_price_snapshot" value="<?php echo esc_attr( Money::fromDecimal( (string) ( $edit_data->total_price ?? 0 ) )->toDecimal() ); ?>">
					<input type="hidden" name="record_payment_booking_id" value="<?php echo esc_attr( $edit_data->id ); ?>">
					<input type="hidden" name="mhbo_record_pay_nonce" value="<?php echo esc_attr( wp_create_nonce( 'mhbo_record_payment_' . $edit_data->id ) ); ?>">
							<?php if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) : ?>
						<input type="hidden" name="guest_id" value="<?php echo esc_attr( (string) $crm_guest_id ); ?>">
					<?php endif; ?>
					<button type="submit" name="submit_booking_update" class="screen-reader-text" tabindex="-1"><?php esc_html_e( 'Save Reservation Changes', 'modern-hotel-booking' ); ?></button>

					<?php
					$ws_mod_req    = ( is_array( $saved_custom ) && ! empty( $saved_custom['_mhbo_modification_requested'] ) && is_array( $saved_custom['_mhbo_modification_requested'] ) ) ? $saved_custom['_mhbo_modification_requested'] : null;
					$ws_cancel_req = ( is_array( $saved_custom ) && ! empty( $saved_custom['_mhbo_cancellation_requested'] ) && is_array( $saved_custom['_mhbo_cancellation_requested'] ) ) ? $saved_custom['_mhbo_cancellation_requested'] : null;
					if ( ! in_array( (string) ( $edit_data->status ?? '' ), array( 'cancelled', 'refunded' ), true ) && ( null !== $ws_mod_req || null !== $ws_cancel_req ) ) :
						?>
					<div class="mhbo-ws-guest-request-banner" role="status">
						<div class="mhbo-ws-guest-request-head">
							<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
							<strong><?php esc_html_e( 'Pending Guest Portal Request', 'modern-hotel-booking' ); ?></strong>
						</div>
						<?php if ( null !== $ws_mod_req ) : ?>
							<div class="mhbo-ws-guest-request-item">
								<strong><?php esc_html_e( 'Stay Modification Request:', 'modern-hotel-booking' ); ?></strong>
								<?php if ( ! empty( $ws_mod_req['requested_dates'] ) ) : ?>
									<span><?php esc_html_e( 'Dates:', 'modern-hotel-booking' ); ?> <?php echo esc_html( (string) $ws_mod_req['requested_dates'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $ws_mod_req['requested_guests'] ) ) : ?>
									<span> &bull; <?php esc_html_e( 'Guests:', 'modern-hotel-booking' ); ?> <?php echo esc_html( (string) $ws_mod_req['requested_guests'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $ws_mod_req['notes'] ) ) : ?>
									<div class="mhbo-ws-guest-request-meta"><?php esc_html_e( 'Guest Notes:', 'modern-hotel-booking' ); ?> <?php echo esc_html( (string) $ws_mod_req['notes'] ); ?></div>
								<?php endif; ?>
								<div class="mhbo-ws-guest-request-meta">
									<?php esc_html_e( 'Updating check-in/check-out dates and clicking "Save Reservation Changes" automatically emails the guest their updated stay schedule and clears this notice.', 'modern-hotel-booking' ); ?>
								</div>
							</div>
						<?php endif; ?>
						<?php if ( null !== $ws_cancel_req ) : ?>
							<div class="mhbo-ws-guest-request-item">
								<strong><?php esc_html_e( 'Cancellation Request:', 'modern-hotel-booking' ); ?></strong>
								<?php if ( ! empty( $ws_cancel_req['reason'] ) ) : ?>
									<span><?php esc_html_e( 'Reason:', 'modern-hotel-booking' ); ?> <?php echo esc_html( (string) $ws_cancel_req['reason'] ); ?></span>
								<?php else : ?>
									<span><?php esc_html_e( 'Submitted via Guest Portal.', 'modern-hotel-booking' ); ?></span>
								<?php endif; ?>
								<div class="mhbo-ws-guest-request-meta">
									<?php esc_html_e( 'Changing status to Cancelled and saving automatically dispatches a cancellation email to the guest and clears this notice.', 'modern-hotel-booking' ); ?>
								</div>
							</div>
						<?php endif; ?>
						<label class="mhbo-ws-guest-request-resolve">
							<input type="hidden" name="mhbo_clear_portal_requests" value="0">
							<input type="checkbox" name="mhbo_clear_portal_requests" value="1">
							<span><?php esc_html_e( 'Mark guest portal request(s) as reviewed/resolved on save (if declining without changing dates or cancelling)', 'modern-hotel-booking' ); ?></span>
						</label>
					</div>
					<?php endif; ?>

					<div class="mhbo-workstation-grid">
						<!-- LEFT MAIN COLUMN (1.75fr) -->
						<div class="mhbo-workstation-col-main">

							<!-- Card 1: Guest Profile & Stay Details -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-admin-users" style="color:#0369a1;" aria-hidden="true"></span> <?php esc_html_e( 'Guest & Reservation Details', 'modern-hotel-booking' ); ?></h3>
									<?php if ( $crm_guest && ! empty( $crm_guest->id ) ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-guests&guest_id=' . (int) $crm_guest->id ) ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small">
											<?php esc_html_e( 'View CRM Profile ↗', 'modern-hotel-booking' ); ?>
										</a>
									<?php endif; ?>
								</div>
								<div class="mhbo-card-body-padded">
									<?php if ( $crm_guest && ! empty( $crm_guest->caution_flag ) ) : ?>
										<div class="mhbo-ws-caution-alert" style="margin-top:0; margin-bottom:16px;" role="alert">
											<span class="dashicons dashicons-warning" aria-hidden="true"></span>
											<span><?php esc_html_e( 'Caution: Problematic guest flagged by staff in CRM.', 'modern-hotel-booking' ); ?></span>
										</div>
									<?php endif; ?>

									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_edit_customer_name"><?php echo esc_html( I18n::get_label( 'label_customer_name' ) ); ?></label></th>
											<td><input type="text" id="mhbo_edit_customer_name" name="customer_name" value="<?php echo esc_attr( $edit_data->customer_name ); ?>" required class="regular-text" style="width:100%; max-width:420px;"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_customer_email"><?php echo esc_html( I18n::get_label( 'label_email' ) ); ?></label></th>
											<td><input type="email" id="mhbo_edit_customer_email" name="customer_email" value="<?php echo esc_attr( $edit_data->customer_email ); ?>" required class="regular-text" style="width:100%; max-width:420px;"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_customer_phone"><?php echo esc_html( I18n::get_label( 'label_phone' ) ); ?></label></th>
											<td><input type="tel" id="mhbo_edit_customer_phone" name="customer_phone" value="<?php echo esc_attr( $edit_data->customer_phone ?? '' ); ?>" class="regular-text" style="width:100%; max-width:420px;"></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_guests"><?php echo esc_html( I18n::get_label( 'label_guests' ) ); ?></label></th>
											<td>
												<input type="number" name="guests" id="mhbo_edit_guests" value="<?php echo esc_attr( $edit_data->guests ?? 1 ); ?>" min="1" max="20" class="small-text">
												<span class="description"><?php esc_html_e( 'Adults (18+)', 'modern-hotel-booking' ); ?></span>
											</td>
										</tr>
										
										<tr>
											<th scope="row"><label for="mhbo_edit_room_id"><?php echo esc_html( I18n::get_label( 'label_room' ) ); ?></label></th>
											<td>
												<select name="room_id" id="mhbo_edit_room_id" style="width:100%; max-width:420px;">
													<?php foreach ( $all_rooms as $rm ) : ?>
														<option value="<?php echo esc_attr( $rm->id ); ?>" data-price="<?php echo esc_attr( $rm->base_price ); ?>" data-room-name="<?php echo esc_attr( $rm->room_number . ' (' . I18n::decode( $rm->type_name ) . ')' ); ?>" <?php selected( (int) $edit_data->room_id, (int) $rm->id ); ?>>
															<?php echo esc_html( $rm->room_number . ' (' . I18n::decode( $rm->type_name ) . ')' ); ?>
														</option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_check_in"><?php echo esc_html( I18n::get_label( 'label_check_in' ) ); ?></label></th>
											<td><input type="date" name="check_in" id="mhbo_edit_check_in" class="mhbo-ws-date-input" value="<?php echo esc_attr( $edit_data->check_in ); ?>" required></td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_check_out"><?php echo esc_html( I18n::get_label( 'label_check_out' ) ); ?></label></th>
											<td><input type="date" name="check_out" id="mhbo_edit_check_out" class="mhbo-ws-date-input" value="<?php echo esc_attr( $edit_data->check_out ); ?>" required></td>
										</tr>

										<!-- Custom Fields -->
												<?php
												$custom_fields_defn = get_option( 'mhbo_custom_fields', array() );
												if ( isset( $custom_fields_defn ) && count( $custom_fields_defn ) > 0 ) :
													$saved_custom = ! empty( $edit_data->custom_fields ) ? json_decode( (string) $edit_data->custom_fields, true ) : array();
													?>
													<?php foreach ( $custom_fields_defn as $defn ) : ?>
														<?php
														$label   = (string) ( I18n::decode( I18n::encode( $defn['label'] ) ) ?? '' );
														$def_val = $saved_custom[ $defn['id'] ] ?? '';
														$cf_id   = 'mhbo_edit_custom_' . sanitize_html_class( (string) $defn['id'] );
														?>
												<tr>
													<th scope="row"><label for="<?php echo esc_attr( $cf_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
													<td>
														<?php if ( $defn['type'] === 'textarea' ) : ?>
															<textarea id="<?php echo esc_attr( $cf_id ); ?>" name="mhbo_custom[<?php echo esc_attr( $defn['id'] ); ?>]" rows="2" class="regular-text mhbo-ws-input-lg"><?php echo esc_textarea( $def_val ); ?></textarea>
														<?php else : ?>
															<input id="<?php echo esc_attr( $cf_id ); ?>" type="<?php echo esc_attr( $defn['type'] ); ?>" name="mhbo_custom[<?php echo esc_attr( $defn['id'] ); ?>]" value="<?php echo esc_attr( $def_val ); ?>" class="regular-text mhbo-ws-input-lg">
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
												<?php endif; ?>
									</table>
								</div>
							</div>

							<!-- Card 2: Concessions & Stay Enhancements -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<?php
									$edit_card2_icon  = 'dashicons-tag';
									$edit_card2_title = __( 'Discounts & Concessions', 'modern-hotel-booking' );
									
									?>
									<h3><span class="dashicons <?php echo esc_attr( $edit_card2_icon ); ?>" style="color:#b45309;" aria-hidden="true"></span> <?php echo esc_html( $edit_card2_title ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">

<!-- Promotions & Coupons -->

<!-- Manual Concession / Discount -->
										<tr>
											<th scope="row"><label for="mhbo_edit_discount_amount"><?php echo esc_html( I18n::get_label( 'label_discount_amount' ) ); ?></label></th>
											<td>
												<div class="mhbo-ws-inline-pair">
													<input type="number" step="any" name="discount_amount" id="mhbo_edit_discount_amount" value="<?php echo esc_attr( Money::fromDecimal( (string) ( $edit_data->discount_amount ?? 0 ) )->toDecimal() ); ?>" class="small-text mhbo-ws-input-sm"<?php echo ! $can_override_pricing ? ' readonly' : ''; ?>>
													<span class="description" style="font-weight:600;"><?php echo esc_html( $currency ); ?></span>
												</div>
												<?php
												$edit_disc_locked_desc = __( 'Manual courtesy discounts require Hotel Manager or Administrator permissions.', 'modern-hotel-booking' );
												
												?>
												<p class="description" style="margin-top:4px;"><?php echo esc_html( $can_override_pricing ? __( 'Direct discount or courtesy rate adjustment.', 'modern-hotel-booking' ) : $edit_disc_locked_desc ); ?></p>
											</td>
										</tr>

</table>
								</div>
							</div>

							<!-- Card 3: Itemized Charges & Folio Breakdown -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-media-spreadsheet" style="color:#2563eb;" aria-hidden="true"></span> <?php esc_html_e( 'Charges & Folio Breakdown', 'modern-hotel-booking' ); ?></h3>
									<span class="mhbo-ws-header-hint"><?php esc_html_e( 'Room rates, extras & taxes', 'modern-hotel-booking' ); ?></span>
								</div>
								<div class="mhbo-table-responsive">
									<table class="mhbo-charges-table" id="mhbo_edit_folio_table">
										<thead>
											<tr>
												<th scope="col" style="width:110px;"><?php esc_html_e( 'Category', 'modern-hotel-booking' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Charge Description', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:100px;"><?php esc_html_e( 'Rate', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:center; width:90px;"><?php esc_html_e( 'Qty / Nights', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:90px;"><?php esc_html_e( 'Tax', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:110px;"><?php esc_html_e( 'Total', 'modern-hotel-booking' ); ?></th>
											</tr>
										</thead>
										<tbody id="mhbo_edit_folio_tbody">
													<?php if ( empty( $folio['charges'] ) ) : ?>
												<tr class="mhbo-ws-empty-row">
													<td colspan="6" style="text-align:center; padding:20px; color:#475569;">
														<?php esc_html_e( 'No billable charge items recorded.', 'modern-hotel-booking' ); ?>
													</td>
												</tr>
											<?php else : ?>
												<?php foreach ( $folio['charges'] as $c ) : ?>
													<?php
													$cat_class = match ( $c['category'] ?? '' ) {
														'room'           => 'mhbo-cat-room',
														'children'       => 'mhbo-cat-children',
														'early_late'     => 'mhbo-cat-room',
														'extra'          => 'mhbo-cat-extra',
														'service', 'fee' => 'mhbo-cat-service',
														'discount'       => 'mhbo-cat-discount',
														default          => 'mhbo-cat-adjustment',
													};
													$cat_lbl = $c['category_label'] ?? match ( $c['category'] ?? '' ) {
														'room'           => __( 'Accommodation', 'modern-hotel-booking' ),
														'children'       => __( 'Child Supplement', 'modern-hotel-booking' ),
														'early_late'     => __( 'Stay Extension', 'modern-hotel-booking' ),
														'extra'          => __( 'Stay Enhancement', 'modern-hotel-booking' ),
														'service', 'fee' => __( 'Service Fee', 'modern-hotel-booking' ),
														'discount'       => __( 'Discount / Concession', 'modern-hotel-booking' ),
														'adjustment'     => __( 'Folio Adjustment', 'modern-hotel-booking' ),
														default          => __( 'Charge Item', 'modern-hotel-booking' ),
													};
													$c_name  = $c['name'] ?? ( $c['label'] ?? '' );
													$c_qty   = $c['quantity'] ?? ( $c['qty'] ?? 1 );
													$c_tot   = $c['amount_formatted'] ?? ( $c['total_formatted'] ?? '' );
													$is_disc = ( $c['amount'] ?? ( $c['total'] ?? 0 ) ) < 0;
	?>
													<tr>
														<td class="mhbo-ws-td-cat" data-label="<?php esc_attr_e( 'Category', 'modern-hotel-booking' ); ?>">
															<span class="mhbo-charge-category-badge <?php echo esc_attr( $cat_class ); ?>">
																<?php echo esc_html( $cat_lbl ); ?>
															</span>
														</td>
														<td class="mhbo-ws-td-desc" data-label="<?php esc_attr_e( 'Charge Description', 'modern-hotel-booking' ); ?>">
															<strong><?php echo esc_html( $c_name ); ?></strong>
															<?php if ( ! empty( $c['details'] ) ) : ?>
																<div style="font-size:11px; color:#475569; margin-top:2px;"><?php echo esc_html( $c['details'] ); ?></div>
															<?php endif; ?>
														</td>
														<td class="mhbo-ws-td-rate" data-label="<?php esc_attr_e( 'Rate', 'modern-hotel-booking' ); ?>" style="text-align:right; font-variant-numeric:tabular-nums;"><?php echo esc_html( $c['unit_price_formatted'] ); ?></td>
														<td class="mhbo-ws-td-qty" data-label="<?php esc_attr_e( 'Qty / Nights', 'modern-hotel-booking' ); ?>" style="text-align:center; font-variant-numeric:tabular-nums;"><?php echo esc_html( (string) $c_qty ); ?></td>
														<td class="mhbo-ws-td-tax" data-label="<?php esc_attr_e( 'Tax', 'modern-hotel-booking' ); ?>" style="text-align:right; color:#475569; font-size:12px; font-variant-numeric:tabular-nums;"><?php echo esc_html( ( isset( $c['tax_amount'] ) && ! Money::fromDecimal( (string) $c['tax_amount'], Pricing::get_currency_code() )->isPositive() ) ? '—' : $c['tax_formatted'] ); ?></td>
														<td class="mhbo-ws-td-total" data-label="<?php esc_attr_e( 'Total', 'modern-hotel-booking' ); ?>" style="text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:<?php echo $is_disc ? '#166534' : '#0f172a'; ?>;">
															<?php echo esc_html( $c_tot ); ?>
														</td>
													</tr>
												<?php endforeach; ?>
											<?php endif; ?>
										</tbody>
										<tfoot style="background:#f8fafc; border-top:2px solid #cbd5e1; font-weight:600;">
													<?php $show_tax_rows = ! empty( $folio['tax_enabled'] ) && ! empty( $folio['total_tax'] ) && Money::fromDecimal( (string) $folio['total_tax'], Pricing::get_currency_code() )->isPositive(); ?>
											<tr id="mhbo_edit_folio_foot_subtotal_row" class="<?php echo ! $show_tax_rows ? 'mhbo-hidden' : ''; ?>" style="<?php echo ! $show_tax_rows ? 'display:none !important;' : ''; ?>" <?php echo ! $show_tax_rows ? 'hidden' : ''; ?>>
												<td colspan="5" style="text-align:right; padding:8px 12px; color:#475569; font-size:12px;" id="mhbo_edit_folio_foot_subtotal_label">
													<?php
													if ( ! empty( $folio['is_vat'] ) ) {
														/* translators: %s: Tax label */
														echo esc_html( sprintf( I18n::get_label( 'label_subtotal_net_vat' ), $folio['base_tax_label'] ?? 'VAT' ) );
													} else {
														esc_html_e( 'Subtotal Net (excl. tax):', 'modern-hotel-booking' );
													}
													?>
												</td>
												<td style="text-align:right; padding:8px 12px; font-variant-numeric:tabular-nums;" id="mhbo_edit_folio_foot_subtotal_net"><?php echo esc_html( $folio['subtotal_net_formatted'] ?? '' ); ?></td>
											</tr>
											<tr id="mhbo_edit_folio_foot_tax_row" class="<?php echo ! $show_tax_rows ? 'mhbo-hidden' : ''; ?>" style="<?php echo ! $show_tax_rows ? 'display:none !important;' : ''; ?>" <?php echo ! $show_tax_rows ? 'hidden' : ''; ?>>
												<td colspan="5" style="text-align:right; padding:6px 12px; color:#475569; font-size:12px;" id="mhbo_edit_folio_foot_tax_label">
													<?php
													if ( ! empty( $folio['is_vat'] ) ) {
														echo esc_html( ( $folio['base_tax_label'] ?? 'VAT' ) . ' (included):' );
													} else {
														/* translators: %s: Tax category label */
														echo esc_html( sprintf( __( 'Taxes (%s):', 'modern-hotel-booking' ), $folio['base_tax_label'] ?? ( $folio['tax_label'] ?? 'Tax' ) ) );
													}
													?>
												</td>
												<td style="text-align:right; padding:6px 12px; font-variant-numeric:tabular-nums; color:#475569;" id="mhbo_edit_folio_foot_tax"><?php echo esc_html( $folio['total_tax_formatted'] ?? '' ); ?></td>
											</tr>
											<tr style="border-top:1px solid #e2e8f0; font-size:14px;">
												<td colspan="5" style="text-align:right; padding:12px; font-weight:800; color:#0f172a;" id="mhbo_edit_folio_foot_total_label">
													<?php
													if ( $show_tax_rows && ! empty( $folio['is_vat'] ) ) {
														/* translators: %s: Tax label */
														echo esc_html( sprintf( I18n::get_label( 'label_total_charges_vat' ), $folio['base_tax_label'] ?? 'VAT' ) );
													} else {
														esc_html_e( 'Total Charges:', 'modern-hotel-booking' );
													}
													?>
												</td>
												<td style="text-align:right; padding:12px; font-weight:800; color:#0f172a; font-size:16px; font-variant-numeric:tabular-nums;" id="mhbo_edit_folio_foot_total"><?php echo esc_html( $folio['total_charges_formatted'] ?? '' ); ?></td>
											</tr>
										</tfoot>
									</table>
								</div>
							</div>

							<!-- Card 4: Payment Ledger & Transactions -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-money-alt" style="color:#047857;" aria-hidden="true"></span> <?php esc_html_e( 'Payment Ledger & Transactions', 'modern-hotel-booking' ); ?></h3>
									<span style="font-size:12px; font-weight:600; color:#166534;"><?php esc_html_e( 'Verified Transactions', 'modern-hotel-booking' ); ?></span>
								</div>
								<div class="mhbo-table-responsive">
									<table class="mhbo-ledger-table">
										<thead>
											<tr>
												<th scope="col" style="width:140px;"><?php esc_html_e( 'Date / Time', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="width:110px;"><?php esc_html_e( 'Type', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="width:120px;"><?php esc_html_e( 'Method', 'modern-hotel-booking' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Txn / Gateway Ref', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="width:110px;"><?php esc_html_e( 'Source', 'modern-hotel-booking' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Memo / Notes', 'modern-hotel-booking' ); ?></th>
												<th scope="col" style="text-align:right; width:110px;"><?php esc_html_e( 'Amount', 'modern-hotel-booking' ); ?></th>
											</tr>
										</thead>
										<tbody>
													<?php if ( empty( $folio['payments'] ) ) : ?>
												<tr class="mhbo-ws-empty-row">
													<td colspan="7" style="text-align:center; padding:24px; color:#475569;">
														<?php esc_html_e( 'No payments or transactions recorded in folio yet.', 'modern-hotel-booking' ); ?>
													</td>
												</tr>
											<?php else : ?>
												<?php
												$group_txn_allocs = ( $is_group_booking && ! empty( $group_amts['bookings'] ) ) ? Pricing::get_group_transaction_allocations( $group_amts['bookings'] ) : array();
												?>
												<?php foreach ( $folio['payments'] as $p ) : ?>
													<?php
													$is_ref       = ! empty( $p['is_refund'] );
													$p_txn_id     = trim( (string) ( $p['transaction_id'] ?? '' ) );
													$is_split_txn = ( '' !== $p_txn_id && isset( $group_txn_allocs[ $p_txn_id ] ) && ! empty( $group_txn_allocs[ $p_txn_id ]['is_split'] ) );
													?>
													<tr>
														<td class="mhbo-ws-td-date" data-label="<?php esc_attr_e( 'Date / Time', 'modern-hotel-booking' ); ?>" style="font-size:12px; color:#475569;"><?php echo esc_html( $p['date_formatted'] ); ?></td>
														<td class="mhbo-ws-td-type" data-label="<?php esc_attr_e( 'Type', 'modern-hotel-booking' ); ?>">
															<span style="display:inline-block; padding:2px 7px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; background:<?php echo $is_ref ? '#fee2e2' : '#dcfce7'; ?>; color:<?php echo $is_ref ? '#991b1b' : '#166534'; ?>;">
																<?php echo esc_html( $p['type_label'] ); ?>
															</span>
														</td>
														<td class="mhbo-ws-td-method" data-label="<?php esc_attr_e( 'Method', 'modern-hotel-booking' ); ?>" style="font-size:12px;"><?php echo esc_html( $p['method_label'] ); ?></td>
														<td class="mhbo-ws-td-ref" data-label="<?php esc_attr_e( 'Txn / Gateway Ref', 'modern-hotel-booking' ); ?>" style="font-size:11px; font-family:monospace; color:#475569;">
															<?php echo esc_html( $p['transaction_id'] ?: '—' ); ?>
															<?php if ( $is_split_txn ) : ?>
																<div>
																	<span style="display:inline-block; margin-top:3px; padding:1px 6px; border-radius:4px; font-size:10px; font-weight:700; background:#e0e7ff; color:#3730a3;">
																		<span class="dashicons dashicons-groups" style="font-size:11px;width:11px;height:11px;vertical-align:text-bottom;" aria-hidden="true"></span> <?php esc_html_e( 'Group Split Payment', 'modern-hotel-booking' ); ?>
																	</span>
																</div>
															<?php endif; ?>
														</td>
														<td class="mhbo-ws-td-source" data-label="<?php esc_attr_e( 'Source', 'modern-hotel-booking' ); ?>" style="font-size:12px;"><?php echo esc_html( $p['staff'] ); ?></td>
														<td class="mhbo-ws-td-memo" data-label="<?php esc_attr_e( 'Memo / Notes', 'modern-hotel-booking' ); ?>" style="font-size:12px; color:#475569;">
															<?php echo esc_html( $p['note'] ?: '—' ); ?>
															<?php if ( $is_split_txn ) : ?>
																<div style="font-size:11px; color:#3730a3; margin-top:2px;">
																	<?php
																	/* translators: %s: Formatted total group transaction amount */
																	echo esc_html( sprintf( __( 'Allocated from %s total group transaction', 'modern-hotel-booking' ), I18n::format_currency( $group_txn_allocs[ $p_txn_id ]['total_amount'] ) ) );
																	?>
																</div>
															<?php endif; ?>
														</td>
														<td class="mhbo-ws-td-amount" data-label="<?php esc_attr_e( 'Amount', 'modern-hotel-booking' ); ?>" style="text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:<?php echo $is_ref ? '#dc2626' : '#166534'; ?>;">
															<?php echo esc_html( $p['amount_formatted'] ); ?>
														</td>
													</tr>
												<?php endforeach; ?>
											<?php endif; ?>
										</tbody>
										<tfoot style="background:#f8fafc; border-top:2px solid #cbd5e1; font-weight:700;">
											<tr>
												<td colspan="6" style="text-align:right; padding:10px 12px; color:#166534; font-size:13px;"><?php esc_html_e( 'Total Payments Received:', 'modern-hotel-booking' ); ?></td>
												<td style="text-align:right; padding:10px 12px; color:#166534; font-size:15px; font-variant-numeric:tabular-nums;"><?php echo esc_html( (string) ( $folio['total_paid_formatted'] ?? '' ) ); ?></td>
											</tr>
											<?php $has_rem_due = isset( $folio['balance_remaining'] ) && Money::fromDecimal( (string) $folio['balance_remaining'], Pricing::get_currency_code() )->isPositive(); ?>
											<tr>
												<td colspan="6" style="text-align:right; padding:10px 12px; color:<?php echo ! empty( $folio['is_overpaid'] ) ? '#0369a1' : ( $has_rem_due ? '#dc2626' : '#166534' ); ?>; font-size:13px;" id="mhbo_ledger_balance_due_label">
															<?php echo ! empty( $folio['is_overpaid'] ) ? esc_html__( 'Credit Balance:', 'modern-hotel-booking' ) : esc_html__( 'Balance Due:', 'modern-hotel-booking' ); ?>
												</td>
												<td style="text-align:right; padding:10px 12px; font-size:16px; font-weight:800; font-variant-numeric:tabular-nums; color:<?php echo ! empty( $folio['is_overpaid'] ) ? '#0369a1' : ( $has_rem_due ? '#dc2626' : '#166534' ); ?>;" id="mhbo_ledger_balance_due_val">
															<?php echo ! empty( $folio['is_overpaid'] ) ? '−' . esc_html( (string) ( $folio['credit_balance_formatted'] ?? '' ) ) : esc_html( (string) ( $folio['balance_remaining_formatted'] ?? '' ) ); ?>
												</td>
											</tr>
										</tfoot>
									</table>
								</div>
							</div>

							<!-- Card 5: Record Payment or Refund -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-insert" style="color:#4f46e5;" aria-hidden="true"></span> <?php echo $can_issue_refunds ? esc_html__( 'Record Payment or Refund', 'modern-hotel-booking' ) : esc_html__( 'Record Payment', 'modern-hotel-booking' ); ?></h3>
									<span class="mhbo-ws-header-hint"><?php esc_html_e( 'Front desk cashier', 'modern-hotel-booking' ); ?></span>
								</div>
								<div class="mhbo-card-body-padded">
									<?php if ( ! $can_process_payments ) : ?>
										<p class="description" style="margin:0;"><?php esc_html_e( 'Recording folio payments requires the Process Payments capability.', 'modern-hotel-booking' ); ?></p>
									<?php else : ?>
									<!-- Quick Tender Action Buttons -->
									<div class="mhbo-quick-tender-bar">
												<?php if ( $has_rem_due ) : ?>
											<button type="button" class="button button-primary button-small mhbo-quick-pay-btn" data-amount="<?php echo esc_attr( (string) ( $folio['balance_remaining'] ?? 0 ) ); ?>" data-type="balance" data-note="<?php esc_attr_e( 'Settlement of remaining balance', 'modern-hotel-booking' ); ?>">
												<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'Pay Remaining Balance', 'modern-hotel-booking' ); ?> (<?php echo esc_html( (string) ( $folio['balance_remaining_formatted'] ?? '' ) ); ?>)
											</button>
										<?php endif; ?>

												<?php if ( ! empty( $folio['deposit_required'] ) && empty( $folio['deposit_received'] ) ) : ?>
											<button type="button" class="button button-secondary button-small mhbo-quick-pay-btn" data-amount="<?php echo esc_attr( (string) $folio['deposit_amount'] ); ?>" data-type="deposit" data-note="<?php esc_attr_e( 'Deposit collection', 'modern-hotel-booking' ); ?>">
												<span class="dashicons dashicons-vault" aria-hidden="true"></span> <?php esc_html_e( 'Collect Deposit', 'modern-hotel-booking' ); ?> (<?php echo esc_html( $folio['deposit_amount_formatted'] ); ?>)
											</button>
										<?php endif; ?>

												<?php if ( ! empty( $folio['is_overpaid'] ) ) : ?>
													<?php if ( $can_issue_refunds ) : ?>
											<button type="button" class="button button-secondary button-small mhbo-quick-pay-btn" data-amount="<?php echo esc_attr( (string) $folio['credit_balance'] ); ?>" data-type="refund" data-note="<?php esc_attr_e( 'Stay modification refund', 'modern-hotel-booking' ); ?>" style="color:#0369a1; border-color:#7dd3fc; background:#f0f9ff;">
												<span class="dashicons dashicons-undo" aria-hidden="true"></span> <?php esc_html_e( 'Issue / Record Refund', 'modern-hotel-booking' ); ?> (<?php echo esc_html( $folio['credit_balance_formatted'] ); ?>)
											</button>
													<?php else : ?>
											<span style="font-size:12px; color:#0369a1; font-weight:600;"><?php esc_html_e( 'Credit balance detected — issuing or recording refunds requires Hotel Manager or Administrator permissions.', 'modern-hotel-booking' ); ?></span>
													<?php endif; ?>
										<?php endif; ?>
									</div>

									<!-- Cashier Tender Form Fields -->
									<div class="mhbo-ws-cashier-box">
										<div class="mhbo-ws-cashier-grid">
											<div>
												<label for="mhbo_record_payment_amount" class="mhbo-ws-cashier-label"><?php esc_html_e( 'Amount', 'modern-hotel-booking' ); ?> (<?php echo esc_html( $currency ); ?>):</label>
												<input type="number" step="any" min="0.01" name="record_payment_amount" id="mhbo_record_payment_amount" class="regular-text mhbo-ws-cashier-field" placeholder="0.00">
											</div>
											<div>
												<label for="mhbo_record_payment_method" class="mhbo-ws-cashier-label"><?php esc_html_e( 'Payment Method:', 'modern-hotel-booking' ); ?></label>
												<select name="record_payment_method" id="mhbo_record_payment_method" class="mhbo-ws-cashier-field">
													<option value="arrival"><?php esc_html_e( '💵 Cash / Terminal at Desk', 'modern-hotel-booking' ); ?></option>
													<option value="credit_card"><?php esc_html_e( '💳 Credit Card', 'modern-hotel-booking' ); ?></option>
													<option value="bank_transfer"><?php esc_html_e( '🏦 Bank Transfer / Wire', 'modern-hotel-booking' ); ?></option>
													<option value="stripe"><?php esc_html_e( 'Stripe Online', 'modern-hotel-booking' ); ?></option>
													<option value="paypal"><?php esc_html_e( 'PayPal', 'modern-hotel-booking' ); ?></option>
													<option value="other"><?php esc_html_e( 'Other', 'modern-hotel-booking' ); ?></option>
												</select>
											</div>
											<div>
												<label for="mhbo_record_payment_type" class="mhbo-ws-cashier-label"><?php esc_html_e( 'Payment Type:', 'modern-hotel-booking' ); ?></label>
												<select name="record_payment_type" id="mhbo_record_payment_type" class="mhbo-ws-cashier-field">
													<option value="payment"><?php esc_html_e( 'Standard Payment', 'modern-hotel-booking' ); ?></option>
													<option value="deposit"><?php esc_html_e( 'Deposit', 'modern-hotel-booking' ); ?></option>
													<option value="balance"><?php esc_html_e( 'Balance Settlement', 'modern-hotel-booking' ); ?></option>
													<option value="incidentals"><?php esc_html_e( 'Incidentals / Extras', 'modern-hotel-booking' ); ?></option>
													<?php if ( $can_issue_refunds ) : ?>
														<option value="refund"><?php esc_html_e( 'Refund / Credit', 'modern-hotel-booking' ); ?></option>
													<?php endif; ?>
												</select>
											</div>
											<div>
												<label for="mhbo_record_payment_txn_id" class="mhbo-ws-cashier-label"><?php esc_html_e( 'Transaction / Reference ID:', 'modern-hotel-booking' ); ?></label>
												<input type="text" name="record_payment_txn_id" id="mhbo_record_payment_txn_id" class="regular-text mhbo-ws-cashier-field" placeholder="<?php esc_attr_e( 'e.g. pi_..., ch_...', 'modern-hotel-booking' ); ?>">
											</div>
										</div>
										<div class="mhbo-ws-cashier-row">
											<div class="mhbo-ws-cashier-memo-col">
												<label for="mhbo_record_payment_note" class="mhbo-ws-cashier-label"><?php esc_html_e( 'Notes / Memo:', 'modern-hotel-booking' ); ?></label>
												<input type="text" name="record_payment_note" id="mhbo_record_payment_note" class="regular-text mhbo-ws-cashier-field" placeholder="<?php esc_attr_e( 'e.g. Cash collected at front desk', 'modern-hotel-booking' ); ?>">
											</div>
											<div class="mhbo-ws-cashier-check-col">
												<label class="mhbo-ws-checkbox-row">
													<input type="checkbox" name="record_payment_send_email" id="mhbo_record_payment_send_email" value="1" checked>
													<span><?php esc_html_e( 'Send receipt to guest & admin', 'modern-hotel-booking' ); ?></span>
												</label>
											</div>
											<div class="mhbo-ws-cashier-submit-col">
												<input type="submit" name="submit_record_folio_payment" class="button button-secondary mhbo-ws-cashier-btn" value="<?php esc_attr_e( 'Record Payment', 'modern-hotel-booking' ); ?>">
											</div>
										</div>
									</div>
									<?php endif; ?>
								</div>
							</div>

						</div><!-- End .mhbo-workstation-col-main -->

						<!-- RIGHT SIDEBAR (1.15fr) -->
						<div class="mhbo-workstation-col-sidebar">

							<!-- Card 1: Financial & Collection Overview KPIs -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-chart-pie" style="color:#0369a1;" aria-hidden="true"></span> <?php esc_html_e( 'Financial Overview', 'modern-hotel-booking' ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<div class="mhbo-kpi-grid">
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Total Charges', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value" id="mhbo_overview_total_val"><?php echo esc_html( (string) ( $folio['total_charges_formatted'] ?? '' ) ); ?></span>
										</div>
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Total Paid', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value mhbo-kpi-value-paid" id="mhbo_overview_paid_val"><?php echo esc_html( (string) ( $folio['total_paid_formatted'] ?? '' ) ); ?></span>
										</div>
										<div class="mhbo-kpi-tile">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Outstanding', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value <?php echo ( ! empty( $has_rem_due ) ) ? 'mhbo-kpi-value-due' : ''; ?>" id="mhbo_overview_pending_val"><?php echo esc_html( (string) ( $folio['balance_remaining_formatted'] ?? '' ) ); ?></span>
										</div>
										<div class="mhbo-kpi-tile" id="mhbo_overview_credit_tile" style="display:<?php echo ! empty( $folio['is_overpaid'] ) ? 'block' : 'none'; ?>;">
											<span class="mhbo-kpi-label"><?php esc_html_e( 'Credit Balance', 'modern-hotel-booking' ); ?></span>
											<span class="mhbo-kpi-value mhbo-kpi-value-credit" id="mhbo_overview_credit_val"><?php echo esc_html( (string) ( $folio['credit_balance_formatted'] ?? '' ) ); ?></span>
										</div>
									</div>
								</div>
							</div>

									<?php if ( $is_group_booking && null !== $group_amts ) : ?>
								<!-- Consolidated Group Finances (Law 52) -->
								<div class="mhbo-workstation-card" style="border:1px solid #c7d2fe; background:#f8faff;">
									<div class="mhbo-card-header-bar" style="background:#eef2ff; border-bottom:1px solid #c7d2fe;">
										<h3 style="color:#1e1b4b;"><span class="dashicons dashicons-groups" style="color:#4338ca;"></span> <?php esc_html_e( 'Consolidated Group Finances', 'modern-hotel-booking' ); ?></h3>
										<span style="font-family:monospace; font-size:11px; font-weight:700; background:#e0e7ff; color:#3730a3; padding:1px 6px; border-radius:4px;">
											<?php echo esc_html( $group_amts['parent_token'] ); ?>
										</span>
									</div>
									<div class="mhbo-card-body-padded">
										<div class="mhbo-kpi-grid">
											<div class="mhbo-kpi-tile">
												<span class="mhbo-kpi-label"><?php esc_html_e( 'Group Total', 'modern-hotel-booking' ); ?></span>
												<span class="mhbo-kpi-value"><?php echo esc_html( I18n::format_currency( $group_amts['total'] ) ); ?></span>
											</div>
											<div class="mhbo-kpi-tile">
												<span class="mhbo-kpi-label"><?php esc_html_e( 'Total Paid', 'modern-hotel-booking' ); ?></span>
												<span class="mhbo-kpi-value mhbo-kpi-value-paid"><?php echo esc_html( I18n::format_currency( $group_amts['received'] ) ); ?></span>
											</div>
											<?php if ( ! empty( $group_amts['deposit'] ) && $group_amts['deposit']->isPositive() ) : ?>
												<div class="mhbo-kpi-tile">
													<span class="mhbo-kpi-label"><?php esc_html_e( 'Combined Deposit', 'modern-hotel-booking' ); ?></span>
													<span class="mhbo-kpi-value"><?php echo esc_html( I18n::format_currency( $group_amts['deposit'] ) ); ?></span>
												</div>
												<div class="mhbo-kpi-tile">
													<span class="mhbo-kpi-label"><?php esc_html_e( 'Rooms in Group', 'modern-hotel-booking' ); ?></span>
													<span class="mhbo-kpi-value"><?php echo esc_html( (string) (int) $group_amts['rooms_count'] ); ?></span>
												</div>
											<?php endif; ?>
											<div class="mhbo-kpi-tile mhbo-kpi-tile--full">
												<span class="mhbo-kpi-label"><?php esc_html_e( 'Group Balance Due', 'modern-hotel-booking' ); ?></span>
												<span class="mhbo-kpi-value <?php echo $group_amts['remaining']->isPositive() ? 'mhbo-kpi-value-due' : 'mhbo-kpi-value-paid'; ?>">
													<?php echo $group_amts['remaining']->isPositive() ? esc_html( I18n::format_currency( $group_amts['remaining'] ) ) : esc_html__( '✓ Group Settled in Full', 'modern-hotel-booking' ); ?>
												</span>
											</div>
										</div>
										<?php if ( ! empty( $grp_master_url ) ) : ?>
											<div style="margin-top:12px; padding-top:12px; border-top:1px solid #e0e7ff;">
												<label for="mhbo_edit_group_portal_url" style="display:block; font-size:11px; font-weight:700; color:#3730a3; margin-bottom:4px;">
													<?php esc_html_e( 'Master Group Payment Portal URL:', 'modern-hotel-booking' ); ?>
												</label>
												<div style="display:flex; gap:6px;">
													<input type="text" id="mhbo_edit_group_portal_url" readonly value="<?php echo esc_url( $grp_master_url ); ?>" style="width:100%; font-size:11px; padding:4px 8px; border:1px solid #c7d2fe; border-radius:4px; background:#fff;" onclick="this.select();">
													<button type="button" class="button button-small mhbo-copy-trigger" data-copy="<?php echo esc_url( $grp_master_url ); ?>"><?php esc_html_e( 'Copy', 'modern-hotel-booking' ); ?></button>
												</div>
											</div>
										<?php endif; ?>
									</div>
								</div>
							<?php endif; ?>

							<!-- Card 2: Billing & Payment Details -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-admin-generic" style="color:#475569;" aria-hidden="true"></span> <?php esc_html_e( 'Billing & Payment Details', 'modern-hotel-booking' ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">
										<?php
										$curr_pay_method   = (string) ( $edit_data->payment_method ?? 'arrival' );
										$curr_pay_status   = (string) ( $edit_data->payment_status ?? 'pending' );
										$curr_paid_decimal = Money::fromDecimal( (string) ( $folio['total_paid'] ?? $edit_data->payment_amount ?? 0 ) )->toDecimal();
										$pay_status_locked = ! $can_process_payments || ( ! $can_issue_refunds && 'refunded' === $curr_pay_status );
										$show_edit_deposit = false;
										
										?>
										<tr>
											<th scope="row"><label for="mhbo_edit_total_price"><?php echo esc_html( I18n::get_label( 'label_total_price' ) ); ?></label></th>
											<td>
												<input type="number" step="any" name="total_price" id="mhbo_edit_total_price" value="<?php echo esc_attr( Money::fromDecimal( (string) ( $edit_data->total_price ?? 0 ) )->toDecimal() ); ?>" required class="regular-text" style="width:100%;<?php echo ! $can_override_pricing ? ' background:#f1f5f9; cursor:not-allowed;' : ''; ?>"<?php echo ! $can_override_pricing ? ' readonly' : ''; ?>>
												<?php if ( ! $can_override_pricing ) : ?>
													<?php
													$edit_total_locked_desc = __( 'Server-calculated from room rates. Manual rate overrides require Hotel Manager or Administrator permissions.', 'modern-hotel-booking' );
													
													?>
													<p class="description" style="margin-top:4px;"><?php echo esc_html( $edit_total_locked_desc ); ?></p>
												<?php endif; ?>
											</td>
										</tr>
										
										<tr>
											<th scope="row"><label for="mhbo_edit_payment_method"><?php echo esc_html( I18n::get_label( 'label_payment_method' ) ); ?></label></th>
											<td>
												<?php if ( ! $can_process_payments ) : ?>
													<input type="hidden" name="payment_method" value="<?php echo esc_attr( $curr_pay_method ); ?>">
												<?php endif; ?>
												<select name="payment_method" id="mhbo_edit_payment_method" style="width:100%;" <?php disabled( ! $can_process_payments ); ?>>
													<option value="arrival" <?php selected( $curr_pay_method, 'arrival' ); ?>>
																<?php echo esc_html( I18n::get_payment_method_label( 'arrival' ) ); ?>
													</option>
															<?php
															$show_commercial_gateways = false;
															
															if ( $show_commercial_gateways ) :
																?>
														<option value="stripe" <?php selected( $curr_pay_method, 'stripe' ); ?>>
																<?php echo esc_html( I18n::get_label( 'gateway_stripe' ) ); ?>
														</option>
														<option value="paypal" <?php selected( $curr_pay_method, 'paypal' ); ?>>
																<?php echo esc_html( I18n::get_label( 'gateway_paypal' ) ); ?>
														</option>
														<option value="braintree" <?php selected( $curr_pay_method, 'braintree' ); ?>>
																<?php echo esc_html( I18n::get_label( 'label_braintree' ) ); ?>
														</option>
															<?php endif; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_payment_status"><?php echo esc_html( I18n::get_label( 'label_payment_status' ) ); ?></label></th>
											<td>
												<?php if ( $pay_status_locked ) : ?>
													<input type="hidden" name="payment_status" value="<?php echo esc_attr( $curr_pay_status ); ?>">
												<?php endif; ?>
												<select name="payment_status" id="mhbo_edit_payment_status" style="width:100%;" <?php disabled( $pay_status_locked ); ?>>
													<option value="pending" <?php selected( $curr_pay_status, 'pending' ); ?>><?php echo esc_html( I18n::get_label( 'status_pending' ) ); ?></option>
													<option value="partially_paid" <?php selected( $curr_pay_status, 'partially_paid' ); ?>><?php echo esc_html( I18n::get_label( 'label_status_partially_paid' ) ); ?></option>
													
													<option value="processing" <?php selected( $curr_pay_status, 'processing' ); ?>><?php echo esc_html( I18n::get_label( 'status_processing' ) ); ?></option>
													<option value="completed" <?php selected( $curr_pay_status, 'completed' ); ?>><?php echo esc_html( I18n::get_label( 'status_completed' ) ); ?></option>
													<option value="failed" <?php selected( $curr_pay_status, 'failed' ); ?>><?php echo esc_html( I18n::get_label( 'status_failed' ) ); ?></option>
													<?php if ( $can_issue_refunds || 'refunded' === $curr_pay_status ) : ?>
														<option value="refunded" <?php selected( $curr_pay_status, 'refunded' ); ?>><?php echo esc_html( I18n::get_label( 'status_refunded' ) ); ?></option>
													<?php endif; ?>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><?php echo esc_html( I18n::get_label( 'label_payment_rcvd' ) ); ?></th>
											<td>
												<label class="mhbo-ws-checkbox-row">
													<input type="hidden" name="payment_received" value="0">
													<input type="checkbox" name="payment_received" id="mhbo_edit_payment_received" value="1" <?php checked( $edit_amts ? $edit_amts['is_paid'] : ( (int) ( $edit_data->payment_received ?? 0 ) === 1 ) ); ?> <?php disabled( ! $can_process_payments ); ?>>
													<span><?php echo esc_html( I18n::get_label( 'label_mark_rcvd' ) ); ?></span>
												</label>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_payment_amount"><?php echo esc_html( I18n::get_label( 'label_pay_amt' ) ); ?></label></th>
											<td>
												<input type="number" step="any" name="payment_amount" id="mhbo_edit_payment_amount" class="regular-text" value="<?php echo esc_attr( $curr_paid_decimal ); ?>"<?php echo ! $can_process_payments ? ' readonly' : ( ! $can_issue_refunds ? ' min="' . esc_attr( $curr_paid_decimal ) . '"' : '' ); ?> style="width:100%;<?php echo ! $can_process_payments ? ' background:#f1f5f9; cursor:not-allowed;' : ''; ?>">
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_amount_outstanding"><?php echo esc_html( I18n::get_label( 'label_amt_out' ) ); ?></label></th>
											<td>
												<input type="text" id="mhbo_edit_amount_outstanding" readonly class="regular-text mhbo-ws-outstanding-input" value="<?php echo esc_attr( Money::fromDecimal( (string) ( $folio['balance_remaining'] ?? 0 ), $currency )->toDecimal() ); ?>">
											</td>
										</tr>
									</table>
												
								</div>
							</div>

							<!-- Card 3: Magic Balance Link & Receipt Portal (Pro) -->

<!-- Card 4: Reservation Status & Notes -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-header-bar">
									<h3><span class="dashicons dashicons-clipboard" style="color:#475569;" aria-hidden="true"></span> <?php esc_html_e( 'Reservation Status & Notes', 'modern-hotel-booking' ); ?></h3>
								</div>
								<div class="mhbo-card-body-padded">
									<table class="form-table" style="margin: 0;">
										<tr>
											<th scope="row"><label for="mhbo_edit_status"><?php echo esc_html( I18n::get_label( 'label_status' ) ); ?></label></th>
											<td>
												<select name="status" id="mhbo_edit_status" style="width:100%;">
													<option value="pending" <?php selected( $edit_data->status, 'pending' ); ?>><?php echo esc_html( I18n::translate_status( 'pending' ) ); ?></option>
													<option value="confirmed" <?php selected( $edit_data->status, 'confirmed' ); ?>><?php echo esc_html( I18n::translate_status( 'confirmed' ) ); ?></option>
													<option value="cancelled" <?php selected( $edit_data->status, 'cancelled' ); ?>><?php echo esc_html( I18n::translate_status( 'cancelled' ) ); ?></option>
													<option value="checked_in" <?php selected( $edit_data->status ?? '', 'checked_in' ); ?>><?php echo esc_html( I18n::translate_status( 'checked_in' ) ); ?></option>
													<option value="checked_out" <?php selected( $edit_data->status ?? '', 'checked_out' ); ?>><?php echo esc_html( I18n::translate_status( 'checked_out' ) ); ?></option>
												</select>
											</td>
										</tr>
										<tr>
											<th scope="row"><label for="mhbo_edit_booking_language"><?php echo esc_html( I18n::get_label( 'label_booking_lang' ) ); ?></label></th>
											<td>
												<select name="booking_language" id="mhbo_edit_booking_language" style="width:100%;">
													<?php foreach ( I18n::get_available_languages() as $lang ) : ?>
														<option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $edit_data->booking_language ?? '', $lang ); ?>><?php echo esc_html( strtoupper( $lang ) ); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
												<?php if ( isset( $edit_data->guest_rating ) ) : ?>
											<tr>
												<th scope="row"><label for="mhbo_edit_guest_rating"><?php esc_html_e( 'Guest Experience Rating', 'modern-hotel-booking' ); ?></label></th>
												<td>
													<select name="guest_rating" id="mhbo_edit_guest_rating" style="width:100%;">
														<option value="0" <?php selected( (int) $edit_data->guest_rating, 0 ); ?>><?php esc_html_e( 'Not Rated', 'modern-hotel-booking' ); ?></option>
														<option value="5" <?php selected( (int) $edit_data->guest_rating, 5 ); ?>>⭐⭐⭐⭐⭐ <?php esc_html_e( '(5 Stars)', 'modern-hotel-booking' ); ?></option>
														<option value="4" <?php selected( (int) $edit_data->guest_rating, 4 ); ?>>⭐⭐⭐⭐ <?php esc_html_e( '(4 Stars)', 'modern-hotel-booking' ); ?></option>
														<option value="3" <?php selected( (int) $edit_data->guest_rating, 3 ); ?>>⭐⭐⭐ <?php esc_html_e( '(3 Stars)', 'modern-hotel-booking' ); ?></option>
														<option value="2" <?php selected( (int) $edit_data->guest_rating, 2 ); ?>>⭐⭐ <?php esc_html_e( '(2 Stars)', 'modern-hotel-booking' ); ?></option>
														<option value="1" <?php selected( (int) $edit_data->guest_rating, 1 ); ?>>⭐ <?php esc_html_e( '(1 Star)', 'modern-hotel-booking' ); ?></option>
													</select>
												</td>
											</tr>
										<?php endif; ?>
										<tr>
											<th scope="row"><label for="mhbo_edit_admin_notes"><?php echo esc_html( I18n::get_label( 'label_admin_notes' ) ); ?></label></th>
											<td>
												<textarea name="admin_notes" id="mhbo_edit_admin_notes" rows="4" style="width:100%;" class="large-text"><?php echo esc_textarea( $edit_data->admin_notes ?? '' ); ?></textarea>
											</td>
										</tr>
									</table>
								</div>
							</div>

							<!-- Card 5: Save & Action Dock -->
							<div class="mhbo-workstation-card">
								<div class="mhbo-card-body-padded">
									<input type="submit" name="submit_booking_update" class="button button-primary button-hero" value="<?php esc_attr_e( 'Save Reservation Changes', 'modern-hotel-booking' ); ?>" style="width:100%; text-align:center; justify-content:center; height:44px; font-size:14px; font-weight:700;">

									<?php if ( Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) : ?>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=delete&id={$edit_data->id}" ), 'mhbo_delete_booking_' . $edit_data->id ) ); ?>" class="button button-link-delete mhbo-delete-action" style="margin-top:14px; display:block; text-align:center; color:#dc2626;" data-confirm="<?php echo esc_attr( I18n::get_label( 'msg_confirm_delete_bk' ) ); ?>">
													<?php echo esc_html( I18n::get_label( 'btn_delete_booking' ) ); ?>
										</a>
									<?php endif; ?>
								</div>
							</div>

						</div><!-- End .mhbo-workstation-col-sidebar -->
					</div><!-- End .mhbo-workstation-grid -->

					<!-- Compact Sticky Action Dock for Tablet & Mobile (<= 1200px) -->
					<div class="mhbo-form-actions-dock mhbo-ws-mobile-dock">
						<div class="mhbo-ws-dock-summary">
							<span><?php esc_html_e( 'Total:', 'modern-hotel-booking' ); ?> <strong class="mhbo-ws-dock-total"><?php echo esc_html( (string) ( $folio['total_charges_formatted'] ?? '' ) ); ?></strong></span>
							<span class="mhbo-ws-dock-sep" aria-hidden="true">&bull;</span>
							<span><?php esc_html_e( 'Balance:', 'modern-hotel-booking' ); ?> <strong class="mhbo-ws-dock-balance"><?php echo esc_html( ! empty( $folio['is_overpaid'] ) ? '−' . (string) ( $folio['credit_balance_formatted'] ?? '' ) : (string) ( $folio['balance_remaining_formatted'] ?? '' ) ); ?></strong></span>
						</div>
						<div class="mhbo-ws-dock-actions">
							<button type="submit" name="submit_booking_update" class="button button-primary">
								<span class="dashicons dashicons-saved" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Save Changes', 'modern-hotel-booking' ); ?></span>
							</button>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ); ?>" class="button button-secondary">
								<?php echo esc_html( I18n::get_label( 'label_cancel' ) ); ?>
							</a>
						</div>
					</div>
				</form>
				</div><!-- .mhbo-booking-workstation -->
		<?php
	}

	/**
	 * Render the bookings operations briefing, calendar, table, and import/export modals.
	 *
	 * @param int                             $today_checkins      Today's check-in count.
	 * @param int                             $today_checkouts     Today's check-out count.
	 * @param int                             $in_house_rooms      Occupied room count tonight.
	 * @param int                             $distinct_room_count Total distinct rooms count.
	 * @param int                             $occupancy_pct       Occupancy percentage tonight.
	 * @param array<string, string|null>      $distinct_rooms      Map of room number to room type name.
	 * @param array<int, array<string,mixed>> $events              FullCalendar event payloads.
	 * @param list<string>                    $full_days           Fully booked date strings (Y-m-d).
	 * @param string                          $search_query        Active search query.
	 * @param string                          $bookings_base_url   Base admin URL for table links.
	 * @param string                          $status_filter       Active booking status filter.
	 * @param int                             $per_page            Items per page (-1 for all).
	 * @param string                          $orderby             Active sort column key.
	 * @param string                          $order               Active sort direction (ASC|DESC).
	 * @param array<int, object>              $bookings            Paginated booking records.
	 * @param int                             $total_bookings      Total matching bookings count.
	 * @param int                             $paged               Current pagination page number.
	 * @param list<int>                       $allowed_per_page    Allowed per-page options.
	 */
	public function render_bookings_list_and_calendar_section(
		int $today_checkins,
		int $today_checkouts,
		int $in_house_rooms,
		int $distinct_room_count,
		int $occupancy_pct,
		array $distinct_rooms,
		array $events,
		array $full_days,
		string $search_query,
		string $bookings_base_url,
		string $status_filter,
		int $per_page,
		string $orderby,
		string $order,
		array $bookings,
		int $total_bookings,
		int $paged,
		array $allowed_per_page
	): void {
		$is_pro_active       = false;
		$can_manage_bookings = Capabilities::current_user_can( Capabilities::MANAGE_LHBO );
		$can_pay_links       = Capabilities::can_process_payments();
		$can_delete_bookings = Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS );
		$can_view_guests     = Capabilities::current_user_can( Capabilities::MANAGE_GUESTS );
		$can_manifest        = Capabilities::current_user_can( Capabilities::MANAGE_HOUSEKEEPING ) || $can_manage_bookings || $can_delete_bookings;
		$can_bulk_delete     = false;
		
		?>
			<!-- 1. Daily Operations Morning Briefing Strip -->
			<div class="mhbo-ops-briefing-strip">
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#dcfce7; color:#166534;">🟢</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $today_checkins; ?></span>
						<span class="mhbo-ops-label"><?php esc_html_e( "Today's Check-ins", 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#fee2e2; color:#991b1b;">🔴</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $today_checkouts; ?></span>
						<span class="mhbo-ops-label"><?php esc_html_e( "Today's Check-outs", 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#fef3c7; color:#92400e;">🏨</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $in_house_rooms; ?> / <?php echo (int) $distinct_room_count; ?></span>
						<span class="mhbo-ops-label"><?php esc_html_e( 'In-House Tonight', 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
				<div class="mhbo-ops-card">
					<div class="mhbo-ops-icon" style="background:#e0e7ff; color:#3730a3;">📊</div>
					<div class="mhbo-ops-info">
						<span class="mhbo-ops-value"><?php echo (int) $occupancy_pct; ?>%</span>
						<span class="mhbo-ops-label"><?php esc_html_e( "Tonight's Occupancy", 'modern-hotel-booking' ); ?></span>
					</div>
				</div>
				
			</div>

			<!-- 2. Interactive Calendar Card -->
			<div class="mhbo-calendar-card">
				<div class="mhbo-calendar-header-toolbar">
					<div class="mhbo-calendar-filter-group">
						<label for="mhbo-calendar-room-filter" class="mhbo-filter-label">🏷️ <?php esc_html_e( 'Filter:', 'modern-hotel-booking' ); ?></label>
						<select id="mhbo-calendar-room-filter" class="mhbo-select-filter">
							<option value=""><?php esc_html_e( 'All Rooms & Types', 'modern-hotel-booking' ); ?></option>
							<?php
							$type_grouped_rooms = array();
							foreach ( $distinct_rooms as $rnum => $tname ) {
								$tname_clean = ( ! empty( $tname ) && '1' !== (string) $tname ) ? $tname : __( 'General Rooms', 'modern-hotel-booking' );
								if ( ! isset( $type_grouped_rooms[ $tname_clean ] ) ) {
									$type_grouped_rooms[ $tname_clean ] = array();
								}
								$type_grouped_rooms[ $tname_clean ][] = $rnum;
							}

							// Natural sort room numbers in each group (e.g. 1, 2, 3 ... 9, 10, 11)
							foreach ( $type_grouped_rooms as $tname => &$rooms_list ) {
								usort(
									$rooms_list,
									function ( $a, $b ) {
										return strnatcasecmp( (string) $a, (string) $b );
									}
								);
							}
							unset( $rooms_list );

							if ( count( $type_grouped_rooms ) > 1 ) :
								?>
								<optgroup label="<?php esc_attr_e( 'Filter by Room Type', 'modern-hotel-booking' ); ?>">
									<?php foreach ( $type_grouped_rooms as $tname => $rooms_list ) : ?>
										<option value="type:<?php echo esc_attr( $tname ); ?>">
											<?php 
											/* translators: 1: room type name, 2: number of rooms */
											echo esc_html( sprintf( __( 'All %1$s (%2$d rooms)', 'modern-hotel-booking' ), $tname, count( $rooms_list ) ) ); 
											?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>

							<?php foreach ( $type_grouped_rooms as $tname => $rooms_list ) : ?>
								<optgroup label="<?php echo esc_attr( $tname ); ?>">
									<?php foreach ( $rooms_list as $rnum ) : ?>
										<option value="room:<?php echo esc_attr( (string) $rnum ); ?>">
											<?php 
											/* translators: %s: room number */
											echo esc_html( sprintf( __( 'Room %s', 'modern-hotel-booking' ), $rnum ) ); 
											?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div id="mhbo-calendar"></div>
				<div class="mhbo-calendar-legend">
					<span class="mhbo-legend-item"><span class="mhbo-legend-dot mhbo-legend-green-dot"></span> Check-in</span>
					<span class="mhbo-legend-item"><span class="mhbo-legend-dot mhbo-legend-red-dot"></span> Check-out</span>
					<span class="mhbo-legend-item"><span class="mhbo-legend-badge mhbo-legend-today"></span> Today</span>
					<span class="mhbo-legend-item"><span class="mhbo-legend-badge mhbo-legend-full"></span> 100% Fully Booked</span>
					<span class="mhbo-legend-item"><span class="mhbo-legend-pill mhbo-legend-confirmed"></span> Confirmed</span>
					<span class="mhbo-legend-item"><span class="mhbo-legend-pill mhbo-legend-pending"></span> Pending</span>
				</div>
			</div>
				<?php
				// Note: Price calculation and child ages JavaScript logic has been moved to assets/js/mhbo-admin-bookings.js
				// Pass calendar events for FullCalendar initialization
				$calendar_config = array(
					'events'          => $events,
					'fullDays'        => $full_days,
					'preventTurnover' => ( class_exists( '\MHBO\Core\Tier' ) && \MHBO\Core\Tier::has_feature( 'prevent_same_day_turnover' ) ) && (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1,
				);
				wp_add_inline_script( 'mhbo-admin-bookings', 'window.mhboCalendarConfig = ' . wp_json_encode( $calendar_config ) . ';', 'before' );
				wp_add_inline_script( 'mhbo-admin-bookings', 'document.addEventListener("DOMContentLoaded", function() { document.querySelectorAll(".mhbo-schedule-balance-link").forEach(function(btn) { btn.addEventListener("click", function() { var id = this.getAttribute("data-id"); var nonce = this.getAttribute("data-nonce"); var ruleEl = document.getElementById("mhbo-schedule-rule-" + id); var rule = ruleEl ? ruleEl.value : ""; if (!rule) { if (ruleEl) { ruleEl.style.borderColor = "#dc2626"; ruleEl.focus(); ruleEl.addEventListener("change", function() { ruleEl.style.borderColor = ""; }, { once: true }); } return; } window.location.href = "' . esc_url( admin_url( 'admin.php?page=mhbo-bookings' ) ) . '&action=schedule_balance_link&id=" + id + "&rule=" + rule + "&_wpnonce=" + nonce; }); }); });', 'after' );
				?>
			<div class="mhbo-table-container mhbo-card mhbo-card--flush">
				<form method="post" id="mhbo-bookings-bulk-form">
					<?php wp_nonce_field( 'mhbo_bulk_bookings_action', 'mhbo_bulk_nonce' ); ?>
					<div class="mhbo-table-live-filter-bar">
						
						<div class="mhbo-search-box">
							<div class="mhbo-search-input-wrap">
								<span class="dashicons dashicons-search mhbo-search-icon" id="mhbo-search-submit-icon" title="<?php esc_attr_e( 'Click to search', 'modern-hotel-booking' ); ?>" aria-hidden="true"></span>
								<input type="text" id="mhbo-live-table-search" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Quick search bookings by Guest Name, Email, Phone, #ID, or Room...', 'modern-hotel-booking' ); ?>" aria-label="<?php esc_attr_e( 'Search bookings', 'modern-hotel-booking' ); ?>" class="mhbo-search-input" data-base-url="<?php echo esc_url( remove_query_arg( array( 's', 'paged' ), $bookings_base_url ) ); ?>" autocomplete="off">
								<?php if ( ! empty( $search_query ) ) : ?>
									<a href="<?php echo esc_url( remove_query_arg( array( 's', 'paged' ), $bookings_base_url ) ); ?>" class="mhbo-search-clear-btn" id="mhbo-search-clear-btn" aria-label="<?php esc_attr_e( 'Clear search', 'modern-hotel-booking' ); ?>" title="<?php esc_attr_e( 'Clear search', 'modern-hotel-booking' ); ?>"><span aria-hidden="true">&times;</span></a>
								<?php endif; ?>
							</div>
							<button type="button" id="mhbo-table-search-btn" class="button action mhbo-search-submit-btn" title="<?php esc_attr_e( 'Search bookings', 'modern-hotel-booking' ); ?>"><?php esc_html_e( 'Search', 'modern-hotel-booking' ); ?></button>
						</div>
						<?php
						$status_base_url      = remove_query_arg( 'paged', $bookings_base_url );
						$status_all_url       = remove_query_arg( 'status', $status_base_url );
						$status_confirmed_url = add_query_arg( 'status', 'confirmed', $status_base_url );
						$status_pending_url   = add_query_arg( 'status', 'pending', $status_base_url );
						$status_cancelled_url = add_query_arg( 'status', 'cancelled', $status_base_url );
						?>
						<div class="mhbo-status-filter-pills" id="mhbo-status-filter-pills">
							<a href="<?php echo esc_url( $status_all_url ); ?>" class="mhbo-pill-btn <?php echo ( empty( $status_filter ) || 'all' === $status_filter ) ? 'active' : ''; ?>" data-status="all"><?php esc_html_e( 'All Statuses', 'modern-hotel-booking' ); ?></a>
							<a href="<?php echo esc_url( $status_confirmed_url ); ?>" class="mhbo-pill-btn <?php echo 'confirmed' === $status_filter ? 'active' : ''; ?>" data-status="confirmed"><?php esc_html_e( 'Confirmed', 'modern-hotel-booking' ); ?></a>
							<a href="<?php echo esc_url( $status_pending_url ); ?>" class="mhbo-pill-btn <?php echo 'pending' === $status_filter ? 'active' : ''; ?>" data-status="pending"><?php esc_html_e( 'Pending', 'modern-hotel-booking' ); ?></a>
							<a href="<?php echo esc_url( $status_cancelled_url ); ?>" class="mhbo-pill-btn <?php echo 'cancelled' === $status_filter ? 'active' : ''; ?>" data-status="cancelled"><?php esc_html_e( 'Cancelled', 'modern-hotel-booking' ); ?></a>
						</div>
						<div class="mhbo-toolbar-per-page">
							<label for="mhbo-table-per-page-select" style="font-size:12px; font-weight:600; color:#64748b; display:flex; align-items:center; gap:4px;">
								<span class="dashicons dashicons-list-view" style="font-size:16px; width:16px; height:16px;"></span> <?php esc_html_e( 'Show:', 'modern-hotel-booking' ); ?>
							</label>
							<select id="mhbo-table-per-page-select" class="mhbo-toolbar-per-page-select" data-base-url="<?php echo esc_url( remove_query_arg( array( 'per_page', 'paged' ), $bookings_base_url ) ); ?>">
								<option value="10" <?php selected( $per_page, 10 ); ?>>10</option>
								<option value="25" <?php selected( $per_page, 25 ); ?>>25</option>
								<option value="50" <?php selected( $per_page, 50 ); ?>>50</option>
								<option value="100" <?php selected( $per_page, 100 ); ?>>100</option>
								<option value="all" <?php selected( $per_page <= 0 ); ?>><?php esc_html_e( 'All', 'modern-hotel-booking' ); ?></option>
							</select>
						</div>
						<div class="mhbo-sort-box" style="margin-left:auto; display:flex; align-items:center; gap:6px;">
							<label for="mhbo-table-sort-select" style="font-size:12px; font-weight:600; color:#64748b; display:flex; align-items:center; gap:4px;">
								<span class="dashicons dashicons-sort" style="font-size:16px; width:16px; height:16px;"></span> <?php esc_html_e( 'Sort by:', 'modern-hotel-booking' ); ?>
							</label>
							<select id="mhbo-table-sort-select" class="mhbo-select-filter" onchange="if(this.value){ window.location.href = this.value; }">
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'id',
											'order'   => 'desc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
												" <?php selected( $orderby === 'id' && $order === 'DESC' ); ?>><?php esc_html_e( 'ID (Highest first)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'id',
											'order'   => 'asc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'id' && $order === 'ASC' ); ?>><?php esc_html_e( 'ID (Lowest first)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'room',
											'order'   => 'asc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'room' && $order === 'ASC' ); ?>><?php esc_html_e( 'Room (A-Z / 1-9)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'room',
											'order'   => 'desc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'room' && $order === 'DESC' ); ?>><?php esc_html_e( 'Room (Z-A / 9-1)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'dates',
											'order'   => 'asc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'dates' && $order === 'ASC' ); ?>><?php esc_html_e( 'Dates (Earliest check-in)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'dates',
											'order'   => 'desc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'dates' && $order === 'DESC' ); ?>><?php esc_html_e( 'Dates (Latest check-in)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'status',
											'order'   => 'asc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'status' ); ?>><?php esc_html_e( 'Status', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'guest',
											'order'   => 'asc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'guest' && $order === 'ASC' ); ?>><?php esc_html_e( 'Guest Name (A-Z)', 'modern-hotel-booking' ); ?></option>
								<option value="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'orderby' => 'total',
											'order'   => 'desc',
											'paged'   => 1,
										),
										$bookings_base_url 
									) 
								);
								?>
								" <?php selected( $orderby === 'total' && $order === 'DESC' ); ?>><?php esc_html_e( 'Total (Highest first)', 'modern-hotel-booking' ); ?></option>
							</select>
						</div>
					</div>
					<table class="wp-list-table widefat fixed striped mhbo-bookings-table">
						<thead>
							<tr>
								
								<?php
								$render_sortable_th = function ( string $col_key, string $label, string $col_class, bool $sortable = true ) use ( $orderby, $order, $bookings_base_url ): void {
									if ( ! $sortable ) {
										echo '<th class="' . esc_attr( $col_class ) . '">' . esc_html( $label ) . '</th>';
										return;
									}
									$is_active = ( $orderby === $col_key );
									$order_low = strtolower( $order );
									if ( $is_active ) {
										$next_order = ( 'asc' === $order_low ) ? 'desc' : 'asc';
										$th_classes = $col_class . ' manage-column sorted ' . $order_low;
									} else {
										$next_order = ( 'id' === $col_key ) ? 'desc' : 'asc';
										$th_classes = $col_class . ' manage-column sortable ' . ( 'id' === $col_key ? 'desc' : 'asc' );
									}
									$url = add_query_arg(
										array(
											'orderby' => $col_key,
											'order'   => $next_order,
											'paged'   => 1,
										),
										$bookings_base_url
									);
									?>
									<th class="<?php echo esc_attr( $th_classes ); ?>">
										<a href="<?php echo esc_url( $url ); ?>" title="
										<?php 
											/* translators: %s: column label */
											echo esc_attr( sprintf( __( 'Sort by %s', 'modern-hotel-booking' ), $label ) ); 
										?>
										">
											<span><?php echo esc_html( $label ); ?></span>
											<span class="sorting-indicators">
												<span class="sorting-indicator asc" aria-hidden="true"></span>
												<span class="sorting-indicator desc" aria-hidden="true"></span>
											</span>
										</a>
									</th>
									<?php
								};

								$render_sortable_th( 'id', I18n::get_label( 'label_id' ), 'mhbo-col-id', true );
								$render_sortable_th( 'guest', I18n::get_label( 'label_guest' ), 'mhbo-col-guest', true );
								$render_sortable_th( 'room', I18n::get_label( 'label_room' ), 'mhbo-col-room', true );
								$render_sortable_th( 'dates', I18n::get_label( 'label_dates' ), 'mhbo-col-dates', true );
								$render_sortable_th( 'total', I18n::get_label( 'label_total' ), 'mhbo-col-total', true );
								$render_sortable_th( 'status', I18n::get_label( 'label_status_noun' ), 'mhbo-col-status', true );
								$render_sortable_th( 'payment', I18n::get_label( 'label_payment' ), 'mhbo-col-payment', false );
								$render_sortable_th( 'lang', I18n::get_label( 'label_lang' ), 'mhbo-col-lang', false );
								$render_sortable_th( 'actions', I18n::get_label( 'label_actions' ), 'mhbo-col-actions', false );
		?>
							</tr>
						</thead>
						<tbody>
							<?php
							if ( count( $bookings ) > 0 ) :
								$page_groups_map = array();
								if ( class_exists( Pricing::class ) ) {
									foreach ( $bookings as $bk_scan ) {
										$grp_tok = trim( (string) ( $bk_scan->multi_room_parent ?? '' ) );
										if ( '' !== $grp_tok && ! isset( $page_groups_map[ $grp_tok ] ) ) {
											$page_groups_map[ $grp_tok ] = Pricing::get_group_payment_amounts( $bk_scan );
										}
									}
								}
								foreach ( $bookings as $bk ) :
									$sc           = 'mhbo-status-' . esc_attr( $bk->status );
									$bk_grp_token = trim( (string) ( $bk->multi_room_parent ?? '' ) );
									$bk_group     = ( '' !== $bk_grp_token && isset( $page_groups_map[ $bk_grp_token ] ) ) ? $page_groups_map[ $bk_grp_token ] : null;
									$bk_is_group  = is_array( $bk_group ) && (int) ( $bk_group['rooms_count'] ?? 0 ) >= 2;
									?>
									<tr class="mhbo-animate-in mhbo-booking-row" data-status="<?php echo esc_attr( $bk->status ); ?>" data-booking-id="<?php echo esc_attr( (string) $bk->id ); ?>">
										
										<td class="mhbo-col-id" data-colname="<?php esc_attr_e( 'ID', 'modern-hotel-booking' ); ?>">
											<span class="mhbo-id-badge mhbo-copy-trigger" data-copy="#<?php echo esc_attr( $bk->id ); ?>" title="<?php esc_attr_e( 'Click to copy Booking ID', 'modern-hotel-booking' ); ?>">#<?php echo esc_html( $bk->id ); ?> <span class="mhbo-copy-icon">📋</span></span>
											<?php if ( $bk_is_group ) : ?>
												<div style="margin-top:4px;">
													<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-bookings&s=' . rawurlencode( $bk_grp_token ) ) ); ?>" class="mhbo-group-link-badge" style="display:inline-flex; align-items:center; gap:3px; padding:1px 6px; border-radius:4px; font-size:10px; font-weight:700; background:#e0e7ff; color:#3730a3; text-decoration:none;" title="<?php esc_attr_e( 'Filter table by this group reservation token', 'modern-hotel-booking' ); ?>">
														<?php
														/* translators: %d: number of rooms in group */
														$grp_badge_text = sprintf( __( 'Group (%d)', 'modern-hotel-booking' ), (int) $bk_group['rooms_count'] );
														?>
														👥 <?php echo esc_html( $grp_badge_text ); ?>
													</a>
												</div>
											<?php endif; ?>
										</td>
										<td class="mhbo-col-guest" data-colname="<?php esc_attr_e( 'Guest', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-guest-info">
											<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
												<?php if ( $can_view_guests && class_exists( GuestManager::class ) && GuestManager::is_enabled() && ! empty( $bk->guest_id ) ) : ?>
													<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-guests&guest_id=' . (int) $bk->guest_id ) ); ?>" target="_blank" rel="noopener noreferrer" class="mhbo-primary-text" style="font-weight:700; color:#2563eb; text-decoration:none;" title="<?php esc_attr_e( 'Open Guest CRM Profile in new tab', 'modern-hotel-booking' ); ?>">
														<?php echo esc_html( (string) ( I18n::decode( $bk->customer_name ) ?? '' ) ); ?> ↗
													</a>
												<?php else : ?>
													<strong class="mhbo-primary-text"><?php echo esc_html( (string) ( I18n::decode( $bk->customer_name ) ?? '' ) ); ?></strong>
												<?php endif; ?>

												<?php if ( class_exists( GuestManager::class ) && GuestManager::is_enabled() ) : ?>
													<?php if ( ! empty( $bk->guest_vip_status ) && 'none' !== $bk->guest_vip_status ) : ?>
														<span style="background:#fef3c7; color:#92400e; padding:1px 6px; border-radius:99px; font-size:10px; font-weight:700; text-transform:uppercase;" title="<?php esc_attr_e( 'VIP Guest Tier', 'modern-hotel-booking' ); ?>">
															★ <?php echo esc_html( str_replace( '_', ' ', $bk->guest_vip_status ) ); ?>
														</span>
													<?php endif; ?>
													<?php if ( ! empty( $bk->guest_caution_flag ) ) : ?>
														<span style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:1px 6px; border-radius:99px; font-size:10px; font-weight:700;" title="<?php esc_attr_e( 'Caution Flag Active in CRM', 'modern-hotel-booking' ); ?>">
															🚫 <?php esc_html_e( 'Caution', 'modern-hotel-booking' ); ?>
														</span>
													<?php endif; ?>
												<?php endif; ?>
											</div>
											<span class="mhbo-guest-email mhbo-copy-trigger" data-copy="<?php echo esc_attr( $bk->customer_email ); ?>" title="<?php esc_attr_e( 'Click to copy email', 'modern-hotel-booking' ); ?>"><?php echo esc_html( $bk->customer_email ); ?></span>
											<?php if ( isset( $bk->customer_phone ) && $bk->customer_phone ) : ?>
												<span class="mhbo-guest-phone mhbo-copy-trigger" data-copy="<?php echo esc_attr( $bk->customer_phone ); ?>" title="<?php esc_attr_e( 'Click to copy phone', 'modern-hotel-booking' ); ?>"><?php echo esc_html( $bk->customer_phone ); ?></span>
											<?php endif; ?>
										</div>
									</td>
									<td class="mhbo-col-room mhbo-meta-grid-item" data-colname="<?php esc_attr_e( 'Room', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-room-info">
											<span class="mhbo-room-number"><?php echo esc_html( $bk->room_number ); ?></span>
											<small><?php echo esc_html( (string) ( I18n::decode( $bk->room_type ) ?? '' ) ); ?></small>
										</div>
									</td>
									<td class="mhbo-col-dates" data-colname="<?php esc_attr_e( 'Dates', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-date-range">
											<span><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $bk->check_in ) ) ); ?></span>
											<span class="mhbo-arrow">→</span>
											<span><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $bk->check_out ) ) ); ?></span>
										</div>
									</td>
									<td class="mhbo-col-total mhbo-meta-grid-item" data-colname="<?php esc_attr_e( 'Total', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-total-info" style="display: flex; flex-direction: column; align-items: flex-start; gap: 4px;">
											<span class="mhbo-price"><?php echo esc_html( I18n::format_currency( $bk->total_price ) ); ?></span>
											<?php
												$amts = class_exists( Pricing::class ) ? Pricing::get_booking_payment_amounts( $bk ) : null;
											if ( $amts ) {
												if ( $amts['is_paid'] ) {
													echo '<span class="mhbo-balance-pill paid">' . esc_html( I18n::get_label( 'label_paid_full_short' ) ) . '</span>';
												} elseif ( ( $amts['deposit_received'] || $amts['received']->isPositive() ) && $amts['remaining']->isPositive() ) {
													/* translators: %s: balance pending amount */
													echo '<span class="mhbo-balance-pill pending">' . esc_html( sprintf( I18n::get_label( 'label_bal_sprintf' ), I18n::format_currency( $amts['remaining']->toDecimal() ) ) ) . '</span>';
												} else {
													/* translators: %s: pending total amount */
													echo '<span class="mhbo-balance-pill pending">' . esc_html( sprintf( I18n::get_label( 'label_pending_sprintf' ), I18n::format_currency( $amts['total']->toDecimal() ) ) ) . '</span>';
												}
											} elseif ( $bk->payment_received ?? 0 ) {
													echo '<span class="mhbo-balance-pill paid">' . esc_html( I18n::get_label( 'label_paid_full_short' ) ) . '</span>';
											} elseif ( ( $bk->deposit_received ?? 0 ) && ! empty( $bk->deposit_amount ) ) {
												$cur_bk        = Pricing::get_currency_code();
												$tot_m         = Money::fromDecimal( (string) ( $bk->total_price ?? 0 ), $cur_bk );
												$dep_m         = Money::fromDecimal( (string) ( $bk->deposit_amount ?? 0 ), $cur_bk );
												$outstanding_m = $tot_m->subtract( $dep_m );
												/* translators: %s: pending balance amount */
												echo '<span class="mhbo-balance-pill pending">' . esc_html( sprintf( I18n::get_label( 'label_bal_sprintf' ), I18n::format_currency( $outstanding_m ) ) ) . '</span>';
											}
											if ( $bk_is_group ) {
												if ( ! empty( $bk_group['is_paid'] ) ) {
													$grp_state_text = __( 'Settled', 'modern-hotel-booking' );
												} else {
													/* translators: %s: remaining group balance */
													$grp_state_text = sprintf( __( '%s due', 'modern-hotel-booking' ), I18n::format_currency( $bk_group['remaining'] ) );
												}
												$grp_sum_text = sprintf(
													/* translators: 1: group total formatted, 2: group remaining or settled text */
													__( 'Group: %1$s (%2$s)', 'modern-hotel-booking' ),
													I18n::format_currency( $bk_group['total'] ),
													$grp_state_text
												);
												echo '<span style="font-size:11px; color:#4338ca; font-weight:600;">' . esc_html( $grp_sum_text ) . '</span>';
											}
											?>
										</div>
										
									</td>
									<td class="mhbo-col-status mhbo-meta-grid-item" data-colname="<?php esc_attr_e( 'Status', 'modern-hotel-booking' ); ?>">
										<span class="mhbo-status-badge <?php echo esc_attr( $sc ); ?>">
											<?php echo esc_html( I18n::translate_status( $bk->status ) ); ?>
										</span>
									</td>
									<td class="mhbo-col-payment mhbo-meta-grid-item" data-colname="<?php esc_attr_e( 'Payment', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-payment-info" style="display:flex; flex-direction:column; gap:4px;">
											<span class="mhbo-payment-method">
												<?php echo esc_html( I18n::get_payment_method_label( $bk->payment_method ?? 'arrival' ) ); ?>
											</span>
											<?php
											$p_status       = sanitize_key( (string) ( $bk->payment_status ?? 'pending' ) );
											$eff_pay_status = $amts ? (string) $amts['effective_payment_status'] : $p_status;
											if ( 'refunded' === $p_status ) {
												echo '<span class="mhbo-balance-pill refunded" style="background:#fef2f2; color:#991b1b;">' . esc_html( I18n::get_label( 'status_refunded' ) ) . '</span>';
											} elseif ( 'failed' === $p_status ) {
												echo '<span class="mhbo-balance-pill failed" style="background:#fef2f2; color:#991b1b;">' . esc_html( I18n::get_label( 'status_failed' ) ) . '</span>';
											} elseif ( 'processing' === $p_status ) {
												echo '<span class="mhbo-balance-pill processing" style="background:#fef3c7; color:#92400e;">' . esc_html( I18n::get_label( 'status_processing' ) ) . '</span>';
											} elseif ( $amts ? $amts['is_paid'] : in_array( $p_status, array( 'paid', 'completed', 'paid_full' ), true ) ) {
												echo '<span class="mhbo-balance-pill paid">' . esc_html( I18n::get_label( 'status_completed' ) ) . '</span>';
											} elseif ( 'deposit_paid' === $eff_pay_status || ( $amts && $amts['deposit_received'] ) ) {
												echo '<span class="mhbo-balance-pill deposit-paid" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;">' . esc_html( I18n::get_label( 'label_deposit_paid' ) ) . '</span>';
											} elseif ( 'partially_paid' === $eff_pay_status || 'partially_paid' === $p_status || ( $amts && $amts['received']->isPositive() && $amts['remaining']->isPositive() ) ) {
												echo '<span class="mhbo-balance-pill partially-paid" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd;">' . esc_html( I18n::get_label( 'label_status_partially_paid' ) ) . '</span>';
											} else {
												echo '<span class="mhbo-balance-pill pending">' . esc_html( I18n::get_label( 'status_pending' ) ) . '</span>';
											}
											?>
										</div>
									</td>
									<td class="mhbo-col-lang" data-colname="<?php esc_attr_e( 'Lang', 'modern-hotel-booking' ); ?>">
										<span class="mhbo-lang-tag"><?php echo esc_html( strtoupper( $bk->booking_language ?? 'en' ) ); ?></span>
									</td>
									<td class="mhbo-col-actions" data-colname="<?php esc_attr_e( 'Actions', 'modern-hotel-booking' ); ?>">
										<div class="mhbo-actions-group">
											<?php if ( $can_manage_bookings ) : ?>
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=edit&id={$bk->id}" ), 'mhbo_edit_booking_' . $bk->id ) ); ?>"
													class="mhbo-action-btn mhbo-btn-edit"
													title="<?php esc_attr_e( 'Edit Booking', 'modern-hotel-booking' ); ?>"
													aria-label="<?php esc_attr_e( 'Edit Booking', 'modern-hotel-booking' ); ?>">
													<span class="dashicons dashicons-edit" aria-hidden="true"></span>
												</a>
												<?php if ( 'pending' === $bk->status ) : ?>
													<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=confirm&id={$bk->id}" ), 'mhbo_confirm_booking_' . $bk->id ) ); ?>"
														class="mhbo-action-btn mhbo-btn-confirm"
														title="<?php esc_attr_e( 'Confirm Booking', 'modern-hotel-booking' ); ?>"
														aria-label="<?php esc_attr_e( 'Confirm Booking', 'modern-hotel-booking' ); ?>"
														onclick="return confirm('<?php echo esc_js( __( 'Confirm this reservation and send confirmation email to guest?', 'modern-hotel-booking' ) ); ?>');">
														<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
													</a>
												<?php endif; ?>
											<?php endif; ?>
											
											<?php if ( $can_manage_bookings ) : ?>
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=cancel&id={$bk->id}" ), 'mhbo_cancel_booking_' . $bk->id ) ); ?>"
													class="mhbo-action-btn mhbo-btn-cancel"
													title="<?php esc_attr_e( 'Cancel Booking', 'modern-hotel-booking' ); ?>"
													aria-label="<?php esc_attr_e( 'Cancel Booking', 'modern-hotel-booking' ); ?>">
													<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
												</a>
											<?php endif; ?>
											<?php if ( $can_delete_bookings ) : ?>
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-bookings&action=delete&id={$bk->id}" ), 'mhbo_delete_booking_' . $bk->id ) ); ?>"
													class="mhbo-action-btn mhbo-btn-delete mhbo-confirm-delete"
													title="<?php esc_attr_e( 'Delete Booking', 'modern-hotel-booking' ); ?>"
													aria-label="<?php esc_attr_e( 'Delete Booking', 'modern-hotel-booking' ); ?>"
													data-confirm="<?php echo esc_attr( I18n::get_label( 'msg_confirm_remove' ) ); ?>">
													<span class="dashicons dashicons-trash" aria-hidden="true"></span>
												</a>
											<?php endif; ?>
										</div>
									</td>
								</tr>
									<?php
							endforeach;
						else :
							?>
							<tr>
								<td colspan="<?php echo $can_bulk_delete ? 10 : 9; ?>" class="mhbo-empty-state-cell">
									<div class="mhbo-empty-state">
										<span class="dashicons dashicons-calendar-alt"></span>
										<h3><?php esc_html_e( 'No bookings found.', 'modern-hotel-booking' ); ?></h3>
										<p><?php esc_html_e( 'Your search criteria didn\'t return any results.', 'modern-hotel-booking' ); ?></p>
									</div>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<?php
				AdminUI::render_pagination(
					$total_bookings,
					$per_page,
					$paged,
					remove_query_arg( 'paged', $bookings_base_url ),
					$allowed_per_page
				);
				?>
				</form>
			</div>
				
		<?php
	}
}
