<?php declare(strict_types=1);
/**
 * Pricing Rules Controller for the Modern Hotel Booking Admin.
 * Handles the management of seasonal and custom pricing rules.
 *
 * @package    MHBO\Admin
 * @author     StartMySuccess
 * @since      2.2.8.0
 */

namespace MHBO\Admin;

use MHBO\Core\Cache;
use MHBO\Core\Capabilities;
use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller class for the Pricing Rules admin screen.
 */
class PricingController {

	/**
	 * Entry point for rendering the Pricing Rules page.
	 * Delegates to handle_request() for processing actions before rendering.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) && ! Capabilities::current_user_can( Capabilities::MANAGE_PRICING ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_perms' ) ) );
		}

		$instance = new self();
		$instance->handle_request();
		$instance->display_view();
	}

	/**
	 * Process POST and GET requests for pricing rule actions.
	 *
	 * @return void
	 */
	private function handle_request(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mhbo_pricing_rules';

		// 1. ADD RULE
		if ( isset( $_POST['submit_pricing'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() called immediately inside
			if ( ! check_admin_referer( 'mhbo_add_pricing' ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_fail' ) ) );
			}

			$rule_name            = isset( $_POST['rule_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_name'] ) ) : '';
			$start_date           = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
			$end_date             = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
			$price_modifier_input = isset( $_POST['price_modifier'] ) ? sanitize_text_field( wp_unslash( $_POST['price_modifier'] ) ) : '0';
			$modifier_type        = isset( $_POST['modifier_type'] ) ? sanitize_key( wp_unslash( $_POST['modifier_type'] ) ) : 'fixed';
			if ( ! in_array( $modifier_type, array( 'fixed', 'percent' ), true ) ) {
				$modifier_type = 'fixed';
			}
			$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );

			if ( empty( $rule_name ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
				add_settings_error( 'mhbo_settings', 'invalid_data', esc_html__( 'Please provide a valid campaign name and date range (YYYY-MM-DD).', 'modern-hotel-booking' ), 'error' );
				return;
			}

			if ( $start_date > $end_date ) {
				add_settings_error( 'mhbo_settings', 'invalid_dates', esc_html__( 'Start date cannot be after end date.', 'modern-hotel-booking' ), 'error' );
				return;
			}

			// Rule 13: Using Money service for precision-safe modifier storage
			$amount_to_save = $price_modifier_input;
			if ( 'fixed' === $modifier_type ) {
				try {
					$amount_to_save = Money::fromDecimal( $price_modifier_input, $currency )->toDecimal();
				} catch ( \Throwable $e ) {
					$amount_to_save = '0.00';
				}
			} else {
				$float_val = floatval( $price_modifier_input );
				if ( $float_val < -100.0 ) {
					$float_val = -100.0;
				}
				$amount_to_save = (string) $float_val;
			}

			$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
			$wpdb->insert(
				$table,
				array(
					'name'       => $rule_name,
					'start_date' => $start_date,
					'end_date'   => $end_date,
					'amount'     => $amount_to_save,
					'operation'  => $modifier_type,
					'type_id'    => $type_id,
					'room_id'    => 0,
					'rule_type'  => 'seasonal',
					'priority'   => 10,
				)
			);

			Cache::invalidate_pricing();
			
			add_settings_error( 'mhbo_settings', 'rule_added', I18n::get_label( 'pricing_msg_added' ), 'success' );
		}

		// 2. DELETE RULE
		if ( isset( $_GET['action'] ) ) {
			if ( 'delete' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp_verify_nonce() called immediately inside
				$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
				if ( 0 === $id ) {
					return;
				}

				if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'mhbo_delete_pricing_' . $id ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_security_fail' ) ) );
				}

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
				$wpdb->delete( $table, array( 'id' => $id ) );
				Cache::invalidate_pricing();
				
				add_settings_error( 'mhbo_settings', 'rule_deleted', I18n::get_label( 'pricing_msg_deleted' ), 'success' );
			}
		}
	}

	/**
	 * Render the HTML view for the Pricing Rules page.
	 *
	 * @return void
	 */
	private function display_view(): void {
		global $wpdb;
		$table_rules = $wpdb->prefix . 'mhbo_pricing_rules';
		$table_types = $wpdb->prefix . 'mhbo_room_types';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
		$rules = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY start_date ASC, priority ASC', $table_rules ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
		$types         = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table_types ) );
		$currency_code = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		?>
		<div class="wrap mhbo-admin-wrap mhbo-animate-in">
			<?php
			$header_actions = array();

AdminUI::render_header(
				I18n::get_label( 'pricing_title' ),
				I18n::get_label( 'pricing_desc' ),
				$header_actions,
				array(
					array(
						'label' => I18n::get_label( 'dash_title' ),
						'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
					),
				)
			);
			?>

			<?php settings_errors( 'mhbo_settings' ); ?>

			<div class="mhbo-settings-card-container" style="max-width: 1200px;">

				<!-- Card 1: Add Dynamic Pricing Rule -->
				<div class="mhbo-settings-section-card">
					<div class="mhbo-settings-section-header">
						<div class="mhbo-settings-section-title-wrap">
							<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
							<div>
								<h3 class="mhbo-settings-section-title"><?php echo esc_html( I18n::get_label( 'pricing_add_title' ) ); ?></h3>
								<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Schedule seasonal price adjustments, holiday surges, or promotional discounts for specific room types.', 'modern-hotel-booking' ); ?></p>
							</div>
						</div>
					</div>

					<form method="post">
						<?php wp_nonce_field( 'mhbo_add_pricing' ); ?>

						<div class="mhbo-settings-grid mhbo-grid-2col">
							<div class="mhbo-form-group">
								<label class="mhbo-form-label" for="rule_name"><?php echo esc_html( I18n::get_label( 'pricing_campaign' ) ); ?></label>
								<input type="text" name="rule_name" id="rule_name" class="mhbo-form-input" required placeholder="<?php echo esc_attr( I18n::get_label( 'pricing_campaign_placeholder' ) ); ?>">
							</div>

							<div class="mhbo-form-group">
								<label class="mhbo-form-label" for="type_id"><?php echo esc_html( I18n::get_label( 'pricing_target_room' ) ); ?></label>
								<select name="type_id" id="type_id" class="mhbo-form-input">
									<option value="0"><?php echo esc_html( I18n::get_label( 'pricing_all_rooms' ) ); ?></option>
									<?php foreach ( $types as $t ) : ?>
										<option value="<?php echo esc_attr( (string) $t->id ); ?>">
											<?php echo esc_html( (string) I18n::decode( $t->name ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="mhbo-form-group">
								<label class="mhbo-form-label" for="start_date"><?php echo esc_html( I18n::get_label( 'pricing_active_period' ) ); ?></label>
								<div class="mhbo-pricing-date-range">
									<div class="mhbo-date-input-wrap">
										<input type="date" name="start_date" id="start_date" class="mhbo-form-input" required aria-label="<?php esc_attr_e( 'Start Date', 'modern-hotel-booking' ); ?>">
									</div>
									<span class="mhbo-pricing-date-sep"><?php echo esc_html( I18n::get_label( 'pricing_until' ) ); ?></span>
									<div class="mhbo-date-input-wrap">
										<input type="date" name="end_date" id="end_date" class="mhbo-form-input" required aria-label="<?php esc_attr_e( 'End Date', 'modern-hotel-booking' ); ?>">
									</div>
								</div>
							</div>

							<div class="mhbo-form-group">
								<label class="mhbo-form-label" for="price_modifier"><?php echo esc_html( I18n::get_label( 'pricing_change' ) ); ?></label>
								<div class="mhbo-pricing-change-row">
									<div class="mhbo-pricing-change-input-wrap">
										<input type="number" step="any" name="price_modifier" id="price_modifier" class="mhbo-form-input" placeholder="<?php esc_attr_e( 'e.g. 25 or -10', 'modern-hotel-booking' ); ?>" required>
									</div>
									<div class="mhbo-pricing-change-unit-wrap">
										<select name="modifier_type" id="modifier_type" class="mhbo-form-input mhbo-pricing-unit-select">
											<option value="fixed"><?php echo esc_html( $currency_code ); ?> (<?php esc_html_e( 'Fixed', 'modern-hotel-booking' ); ?>)</option>
											<option value="percent"><?php echo esc_html( I18n::get_label( 'pricing_percent' ) ); ?> (%)</option>
										</select>
									</div>
								</div>
								<p class="mhbo-form-help"><?php echo esc_html( I18n::get_label( 'pricing_change_desc' ) ); ?></p>
							</div>

							<div class="mhbo-form-group mhbo-span-full mhbo-form-actions-wrap" style="margin-top: 8px;">
								<input type="hidden" name="submit_pricing" value="1">
								<button type="submit" class="button button-primary button-large mhbo-btn-create-rule">
									<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
									<span><?php echo esc_html( I18n::get_label( 'pricing_btn_create' ) ); ?></span>
								</button>
							</div>
						</div>
					</form>
				</div>

				<!-- Card 2: Active Pricing Campaign Rules -->
				<div class="mhbo-settings-section-card">
					<div class="mhbo-settings-section-header">
						<div class="mhbo-settings-section-title-wrap">
							<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-list-view"></span></div>
							<div>
								<h3 class="mhbo-settings-section-title"><?php echo esc_html( I18n::get_label( 'pricing_active_rules' ) ); ?></h3>
								<p class="mhbo-settings-section-desc">
								<?php 
									/* translators: %d: Number of active rate modifiers. */
									printf( esc_html__( '%d active dynamic rate modifiers scheduled in database', 'modern-hotel-booking' ), count( $rules ) ); 
								?>
								</p>
							</div>
						</div>
					</div>

					<?php if ( count( $rules ) === 0 ) : ?>
						<div class="mhbo-pricing-empty-state">
							<div class="mhbo-pricing-empty-icon-wrap">
								<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
							</div>
							<p class="mhbo-pricing-empty-title"><?php echo esc_html( I18n::get_label( 'pricing_no_rules' ) ); ?></p>
							<p class="mhbo-pricing-empty-desc"><?php esc_html_e( 'Use the form above to add seasonal adjustments, holiday surcharges, or promotional discounts.', 'modern-hotel-booking' ); ?></p>
						</div>
					<?php else : ?>
						<div class="mhbo-table-responsive" style="margin-top: 4px;">
							<table class="wp-list-table widefat striped mhbo-pricing-rules-table" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; box-shadow: none; margin: 0; width: 100%;">
										<thead>
											<tr>
												<th scope="col" class="mhbo-col-pricing-name"><?php echo esc_html( I18n::get_label( 'pricing_rule_name' ) ); ?></th>
												<th scope="col" class="mhbo-col-pricing-validity"><?php echo esc_html( I18n::get_label( 'pricing_validity' ) ); ?></th>
												<th scope="col" class="mhbo-col-pricing-impact"><?php echo esc_html( I18n::get_label( 'pricing_impact' ) ); ?></th>
												<th scope="col" class="mhbo-col-pricing-app"><?php echo esc_html( I18n::get_label( 'pricing_applicability' ) ); ?></th>
												<th scope="col" class="mhbo-col-pricing-actions"><?php echo esc_html( I18n::get_label( 'label_actions' ) ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php
											foreach ( $rules as $rule ) : 
												$rule_type = I18n::get_label( 'pricing_global_label' );
												if ( $rule->type_id > 0 ) {
													foreach ( $types as $t ) {
														if ( (int) $t->id === (int) $rule->type_id ) {
															$rule_type = I18n::decode( $t->name ) ?? I18n::get_label( 'pricing_global_label' );
															break;
														}
													}
												}
												$rule_amt_m  = Money::fromDecimal( (string) $rule->amount, Pricing::get_currency_code() );
												$is_discount = $rule_amt_m->isNegative();
												$prefix      = $rule_amt_m->isPositive() ? '+' : '';
												$rule_today  = gmdate( 'Y-m-d' );
												$is_expired  = $rule->end_date < $rule_today;
												$is_upcoming = $rule->start_date > $rule_today;
												?>
												<tr style="<?php echo $is_expired ? 'opacity: 0.65;' : ''; ?>">
													<td class="mhbo-col-pricing-name" data-colname="<?php echo esc_attr( I18n::get_label( 'pricing_rule_name' ) ); ?>" style="font-weight: 600; color: #0f172a;">
														<?php echo esc_html( (string) I18n::decode( $rule->name ) ); ?>
														<?php if ( $is_expired ) : ?>
															<span class="mhbo-badge" style="background:#f1f5f9;color:#64748b;font-size:11px;padding:2px 8px;border-radius:9999px;margin-left:6px;font-weight:500;"><?php esc_html_e( 'Expired', 'modern-hotel-booking' ); ?></span>
														<?php elseif ( $is_upcoming ) : ?>
															<span class="mhbo-badge" style="background:#e0f2fe;color:#0369a1;font-size:11px;padding:2px 8px;border-radius:9999px;margin-left:6px;font-weight:500;"><?php esc_html_e( 'Upcoming', 'modern-hotel-booking' ); ?></span>
														<?php else : ?>
															<span class="mhbo-badge" style="background:#ecfdf5;color:#047857;font-size:11px;padding:2px 8px;border-radius:9999px;margin-left:6px;font-weight:500;"><?php esc_html_e( 'Active', 'modern-hotel-booking' ); ?></span>
														<?php endif; ?>
													</td>
													<td class="mhbo-col-pricing-validity" data-colname="<?php echo esc_attr( I18n::get_label( 'pricing_validity' ) ); ?>">
														<div class="mhbo-pricing-date-pill">
															<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
															<span><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $rule->start_date ) ) ); ?></span>
															<span class="mhbo-pricing-date-arr">&rarr;</span>
															<span><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $rule->end_date ) ) ); ?></span>
														</div>
													</td>
													<td class="mhbo-col-pricing-impact" data-colname="<?php echo esc_attr( I18n::get_label( 'pricing_impact' ) ); ?>">
														<span class="mhbo-rate-badge <?php echo $is_discount ? 'mhbo-badge-discount' : 'mhbo-badge-surge'; ?>">
															<?php 
															if ( 'fixed' === $rule->operation ) {
																echo esc_html( $prefix . I18n::format_currency( Money::fromDecimal( $rule->amount, get_option( 'mhbo_currency_code', 'USD' ) ) ) );
															} else {
																echo esc_html( $prefix . $rule->amount . '%' );
															}
															?>
														</span>
													</td>
													<td class="mhbo-col-pricing-app" data-colname="<?php echo esc_attr( I18n::get_label( 'pricing_applicability' ) ); ?>">
														<span class="mhbo-pricing-room-tag"><?php echo esc_html( $rule_type ); ?></span>
													</td>
													<td class="mhbo-col-pricing-actions" data-colname="<?php echo esc_attr( I18n::get_label( 'label_actions' ) ); ?>">
														<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=mhbo-pricing-rules&action=delete&id=' . $rule->id ), 'mhbo_delete_pricing_' . $rule->id ) ); ?>" 
															class="button button-small button-link-delete mhbo-pricing-delete-btn" 
															title="<?php echo esc_attr( I18n::get_label( 'pricing_delete_confirm' ) ); ?>"
															aria-label="<?php echo esc_attr( I18n::get_label( 'pricing_delete_confirm' ) ); ?>"
															onclick="return confirm(<?php echo esc_attr( (string) wp_json_encode( I18n::get_label( 'pricing_delete_confirm' ) ) ); ?>);">
															<span class="dashicons dashicons-trash" aria-hidden="true"></span>
														</a>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}
}
