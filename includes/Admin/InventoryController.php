<?php declare(strict_types=1);

namespace MHBO\Admin;

use MHBO\Core\Cache;
use MHBO\Core\Capabilities;
use MHBO\Core\HotelTime;
use MHBO\Core\I18n;

use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Security;
use MHBO\Core\Tier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventory Controller
 *
 * Manages room types, physical rooms (and iCal feed UI in Pro), and extra
 * services catalog administration.
 */
class InventoryController {

	public function display_room_types_page(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_ROOMS ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		global $wpdb;
		$table     = $wpdb->prefix . 'mhbo_room_types';
		$edit_mode = false;
		$edit_data = null;
		$currency  = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );

		// Rule 11: Extract and sanitize all inputs at start
		$action           = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id               = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$nonce            = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$submit_room_type = isset( $_POST['submit_room_type'] );

// Delete Action
		if ( 'delete' === $action && $id > 0 ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_delete_room_type_' . $id ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			$wpdb->delete( $table, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
			$type_disp_opts = get_option( 'mhbo_room_type_display_opts', array() );
			if ( is_array( $type_disp_opts ) && isset( $type_disp_opts[ $id ] ) ) {
				unset( $type_disp_opts[ $id ] );
				update_option( 'mhbo_room_type_display_opts', $type_disp_opts );
			}
			Cache::invalidate_rooms();
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_type_deleted' ) ) . '</p></div>';
		}

		// Edit Action
		if ( 'edit' === $action && $id > 0 ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_edit_room_type_' . $id ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: Fetching room type configuration for editing.
			$edit_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_room_types WHERE id = %d", $id ) );
			$edit_mode = ( null !== $edit_data );
		}

		// Save/Update Action
		if ( $submit_room_type ) {
			$room_type_id = isset( $_POST['room_type_id'] ) ? absint( wp_unslash( $_POST['room_type_id'] ) ) : 0;
			$nonce_action = $room_type_id > 0 ? 'mhbo_edit_room_type_' . $room_type_id : 'mhbo_add_room_type';

			check_admin_referer( $nonce_action );

			$raw_amenities = isset( $_POST['amenities'] ) && is_array( $_POST['amenities'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['amenities'] ) ) : array();
			$amenities     = ! empty( $raw_amenities ) ? wp_json_encode( $raw_amenities ) : '';

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized/unslashed on next line
			$raw_room_name = $_POST['room_name'] ?? '';
			$room_name     = is_array( $raw_room_name ) ? I18n::encode( array_map( 'sanitize_text_field', wp_unslash( $raw_room_name ) ) ) : sanitize_text_field( wp_unslash( $raw_room_name ) );

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized/unslashed on next line
			$raw_room_desc = $_POST['room_description'] ?? '';
			$room_desc     = is_array( $raw_room_desc ) ? I18n::encode( array_map( 'sanitize_textarea_field', wp_unslash( $raw_room_desc ) ) ) : sanitize_textarea_field( wp_unslash( $raw_room_desc ) );

			$base_price = Money::fromDecimal( isset( $_POST['base_price'] ) ? sanitize_text_field( wp_unslash( $_POST['base_price'] ) ) : '0', $currency )->toDecimal();
			$max_adults = isset( $_POST['max_adults'] ) ? absint( wp_unslash( $_POST['max_adults'] ) ) : 1;
			$image_url  = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';

			$data = array(
				'name'                 => $room_name,
				'description'          => $room_desc,
				'base_price'           => $base_price,
				'max_adults'           => $max_adults,
				
				'amenities'            => $amenities,
				'image_url'            => $image_url,
			);

			$type_show_title = isset( $_POST['type_show_title'] ) && 1 === absint( wp_unslash( $_POST['type_show_title'] ) ) ? 1 : 0;
			$type_show_badge = isset( $_POST['type_show_badge'] ) && 1 === absint( wp_unslash( $_POST['type_show_badge'] ) ) ? 1 : 0;

			if ( $room_type_id > 0 ) {
				$wpdb->update( $table, $data, array( 'id' => $room_type_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
				$saved_type_id = $room_type_id;
				Cache::invalidate_rooms();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_type_updated' ) ) . '</p></div>';
				$edit_mode = false;
			} else {
				$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
				$saved_type_id = (int) $wpdb->insert_id;
				Cache::invalidate_rooms();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_type_added' ) ) . '</p></div>';
			}

			if ( $saved_type_id > 0 ) {
				$type_disp_opts = get_option( 'mhbo_room_type_display_opts', array() );
				if ( ! is_array( $type_disp_opts ) ) {
					$type_disp_opts = array();
				}
				$type_disp_opts[ $saved_type_id ] = array(
					'show_title' => $type_show_title,
					'show_badge' => $type_show_badge,
				);
				update_option( 'mhbo_room_type_display_opts', $type_disp_opts );
				Cache::bump( Cache::TABLE_SETTINGS );
			}
		}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rationale: Loading room type list for administration dashboard.
		$types             = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mhbo_room_types" );
		$current_amenities = ( $edit_mode && isset( $edit_data->amenities ) && $edit_data->amenities ) ? json_decode( $edit_data->amenities, true ) : array();
		if ( ! is_array( $current_amenities ) ) {
			$current_amenities = array();
		}

		$total_types = count( $types );
		$cur_admin   = Pricing::get_currency_code();
		$types_sum_m = Money::fromCents( 0, $cur_admin );
		foreach ( $types as $t ) {
			$types_sum_m = $types_sum_m->add( Money::fromDecimal( (string) ( $t->base_price ?? 0 ), $cur_admin ) );
		}
		$avg_rate_m = $total_types > 0 ? $types_sum_m->divide( (string) $total_types ) : Money::fromCents( 0, $cur_admin );
		$max_pax    = $total_types > 0 ? max( 0, ...array_map( fn( $t ) => (int) $t->max_adults, $types ) ) : 0;
		?>
		<div class="wrap mhbo-admin-wrap mhbo-room-types-wrap">
			<?php
			AdminUI::render_header(
				I18n::get_label( 'title_room_types_config' ),
				I18n::get_label( 'desc_room_types_config' ),
				array(
					array(
						'label' => __( '➕ New Room Type', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-room-types' ),
						'class' => 'button-primary',
					),
					array(
						'label' => __( '🏨 Physical Rooms', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-rooms' ),
						'class' => 'button-secondary',
					),
					array(
						'label' => __( '📅 Master Calendar', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings#mhbo-calendar' ),
						'class' => 'button-secondary',
					),
				),
				array(
					array(
						'label' => I18n::get_label( 'menu_main' ),
						'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
					),
				)
			);
			?>

			<!-- 3-KPI Overview Ribbon -->
			<div class="mhbo-stats-grid" style="margin-bottom: 24px;">
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value"><?php echo esc_html( (string) $total_types ); ?></div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Room Categories', 'modern-hotel-booking' ); ?></div>
				</div>
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value" style="color: #2563eb;"><?php echo esc_html( I18n::format_currency( $avg_rate_m ) ); ?></div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Avg. Nightly Base Rate', 'modern-hotel-booking' ); ?></div>
				</div>
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value" style="color: #166534;"><?php echo esc_html( (string) $max_pax ); ?> <?php esc_html_e( 'Guests', 'modern-hotel-booking' ); ?></div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Max Category Capacity', 'modern-hotel-booking' ); ?></div>
				</div>
			</div>

			<div class="mhbo-card mhbo-room-form-card mhbo-animate-in" id="mhbo-room-type-form-card" style="margin-bottom: 30px;">
				<h3 class="mhbo-card-title" style="margin-top:0; margin-bottom:20px; font-size:1.15rem; display:flex; align-items:center; gap:8px;">
					<span class="dashicons dashicons-category" style="color:#2563eb;"></span>
					<?php echo $edit_mode ? esc_html( I18n::get_label( 'title_modify_room_config' ) ) : esc_html( I18n::get_label( 'title_define_new_room' ) ); ?>
				</h3>

				<form method="post" action="" class="mhbo-modern-form-layout">
					<?php wp_nonce_field( $edit_mode ? 'mhbo_edit_room_type_' . $edit_data->id : 'mhbo_add_room_type' ); ?>
					<?php if ( $edit_mode ) : ?>
						<input type="hidden" name="room_type_id" value="<?php echo esc_attr( $edit_data->id ); ?>">
					<?php endif; ?>
						<div class="mhbo-form-section">
							<h4 class="mhbo-section-title"><?php esc_html_e( 'Content & Localization', 'modern-hotel-booking' ); ?></h4>
							
							<div class="mhbo-lang-tabs-container">
								<nav class="mhbo-tab-nav">
									<?php foreach ( I18n::get_available_languages() as $index => $lang ) : ?>
										<button type="button" class="mhbo-tab-btn <?php echo 0 === $index ? 'mhbo-tab-active' : ''; ?>" data-tab="lang-<?php echo esc_attr( $lang ); ?>">
											<?php echo esc_html( strtoupper( $lang ) ); ?>
										</button>
									<?php endforeach; ?>
								</nav>

								<div class="mhbo-tab-panes">
									<?php foreach ( I18n::get_available_languages() as $index => $lang ) : ?>
										<div class="mhbo-tab-content" id="lang-<?php echo esc_attr( $lang ); ?>" style="<?php echo 0 === $index ? 'display:block;' : 'display:none;'; ?>">
											<div class="mhbo-field-group">
												<label class="mhbo-label"><?php esc_html_e( 'Room Name', 'modern-hotel-booking' ); ?></label>
												<input type="text" name="room_name[<?php echo esc_attr( $lang ); ?>]"
													value="<?php echo $edit_mode ? esc_attr( (string) ( I18n::decode( $edit_data->name, $lang ) ?? '' ) ) : ''; ?>"
													class="mhbo-input-large" placeholder="<?php esc_attr_e( 'e.g. Deluxe Sea View Suite', 'modern-hotel-booking' ); ?>">
											</div>
											<div class="mhbo-field-group">
												<label class="mhbo-label"><?php esc_html_e( 'Description', 'modern-hotel-booking' ); ?></label>
												<textarea name="room_description[<?php echo esc_attr( $lang ); ?>]" rows="4"
													class="mhbo-input-large" placeholder="<?php esc_attr_e( 'Describe the features, view, and unique selling points...', 'modern-hotel-booking' ); ?>"><?php echo $edit_mode ? esc_textarea( (string) ( I18n::decode( $edit_data->description, $lang ) ?? '' ) ) : ''; ?></textarea>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						</div>

						<div class="mhbo-form-section">
							<h4 class="mhbo-section-title"><?php esc_html_e( 'Pricing & Capacity', 'modern-hotel-booking' ); ?></h4>
							<div class="mhbo-settings-grid">
								<div class="mhbo-settings-item">
									<label class="mhbo-label"><?php esc_html_e( 'Nightly Base Rate', 'modern-hotel-booking' ); ?></label>
									<div class="mhbo-input-prefix-container">
										<span class="mhbo-input-prefix"><?php echo esc_html( $currency ); ?></span>
										<input type="number" step="any" name="base_price"
											value="<?php echo $edit_mode ? esc_attr( Money::fromDecimal( (string) $edit_data->base_price, $currency )->toDecimal() ) : ''; ?>" required
											class="mhbo-input-mid">
									</div>
								</div>
								<div class="mhbo-settings-item">
									<label class="mhbo-label"><?php esc_html_e( 'Adult Capacity', 'modern-hotel-booking' ); ?></label>
									<input type="number" name="max_adults"
										value="<?php echo $edit_mode ? esc_attr( $edit_data->max_adults ) : '2'; ?>"
										class="mhbo-input-mid" min="1">
								</div>
								
							</div>
						</div>

						<div class="mhbo-form-section">
							<h4 class="mhbo-section-title"><?php esc_html_e( 'Media & Amenities', 'modern-hotel-booking' ); ?></h4>
							<div class="mhbo-field-group">
								<label class="mhbo-label"><?php esc_html_e( 'Featured Image', 'modern-hotel-booking' ); ?></label>
								<div class="mhbo-media-selector" style="display:flex; gap:10px; align-items:center;">
									<input type="text" name="image_url" id="mhbo_room_image_url"
										value="<?php echo $edit_mode ? esc_attr( $edit_data->image_url ) : ''; ?>" class="mhbo-input-large" placeholder="https://...">
									<button type="button" class="mhbo-btn mhbo-btn-outline mhbo-upload-button" data-target="#mhbo_room_image_url">
										<span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Select', 'modern-hotel-booking' ); ?>
									</button>
								</div>
							</div>

							<div class="mhbo-field-group">
								<label class="mhbo-label"><?php esc_html_e( 'Standard Amenities', 'modern-hotel-booking' ); ?></label>
								<div class="mhbo-amenities-check-grid">
								<?php
								$amenities_list = get_option(
									'mhbo_amenities_list',
									array(
										'wifi'      => I18n::get_label( 'label_amenity_wifi' ),
										'ac'        => I18n::get_label( 'label_amenity_ac' ),
										'tv'        => I18n::get_label( 'label_amenity_tv' ),
										'breakfast' => I18n::get_label( 'label_amenity_breakfast' ),
										'pool'      => I18n::get_label( 'label_amenity_pool' ),
										'minibar'   => I18n::get_label( 'label_amenity_minibar' ),
										'safe'      => I18n::get_label( 'label_amenity_safe' ),
										'parking'   => I18n::get_label( 'label_amenity_parking' ),
										'balcony'   => I18n::get_label( 'label_amenity_balcony' ),
									)
								);
								foreach ( $amenities_list as $key => $lbl ) :
									?>
									<label class="mhbo-checkbox-item">
										<input type="checkbox" name="amenities[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $current_amenities, true ) ); ?>>
										<span><?php echo esc_html( $lbl ); ?></span>
									</label>
								<?php endforeach; ?>
								</div>
							</div>

							<?php
							$edit_type_disp = ( $edit_mode && isset( $edit_data->id ) )
								? \MHBO\Frontend\Calendar::get_room_type_display_settings( (int) $edit_data->id )
								: array(
									'show_title' => true,
									'show_badge' => true,
								);
							?>
							<div class="mhbo-field-group" style="margin-top: 16px; padding-top: 16px; border-top: 1px dashed #e2e8f0;">
								<label class="mhbo-label"><?php esc_html_e( 'Frontend Display Options (Title & Badge)', 'modern-hotel-booking' ); ?></label>
								<p class="description" style="margin: 0 0 10px 0; font-size: 12px; color: #64748b;">
									<?php esc_html_e( 'Control whether this room category displays its title heading and badge pill on frontend calendars, booking pages, and room showcase grids.', 'modern-hotel-booking' ); ?>
								</p>
								<div style="display: flex; flex-wrap: wrap; gap: 18px;">
									<label class="mhbo-checkbox-item" style="background: #f8fafc; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
										<input type="hidden" name="type_show_title" value="0">
										<input type="checkbox" name="type_show_title" value="1" <?php checked( $edit_type_disp['show_title'] ); ?>>
										<span><strong><?php esc_html_e( 'Show Category Title on Frontend', 'modern-hotel-booking' ); ?></strong></span>
									</label>
									<label class="mhbo-checkbox-item" style="background: #f8fafc; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
										<input type="hidden" name="type_show_badge" value="0">
										<input type="checkbox" name="type_show_badge" value="1" <?php checked( $edit_type_disp['show_badge'] ); ?>>
										<span><strong><?php esc_html_e( 'Show Category / Capacity Badge on Frontend', 'modern-hotel-booking' ); ?></strong></span>
									</label>
								</div>
							</div>
						</div>
				
					<div class="mhbo-form-actions-dock" style="margin-top:20px; display:flex; gap:12px; align-items:center;">
						<input type="submit" name="submit_room_type" class="button button-primary button-hero"
							value="<?php echo $edit_mode ? esc_attr( I18n::get_label( 'btn_save_config' ) ) : esc_attr( I18n::get_label( 'btn_create_room_type' ) ); ?>">
						<?php if ( $edit_mode ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-room-types' ) ); ?>"
								class="button button-secondary button-hero"><?php esc_html_e( 'Discard Changes', 'modern-hotel-booking' ); ?></a>
						<?php endif; ?>
					</div>
				</form>
			</div>

			<div class="mhbo-section-header" style="margin-bottom: 20px;">
				<h3 style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin: 0 0 6px 0;"><?php esc_html_e( 'Defined Room Categories', 'modern-hotel-booking' ); ?></h3>
				<p style="margin: 0; color: #64748b; font-size: 13px;"><?php esc_html_e( 'Manage and edit your existing category specifications and rates.', 'modern-hotel-booking' ); ?></p>
			</div>

			<div class="mhbo-room-type-grid">
					<?php if ( count( $types ) === 0 ) : ?>
						<div class="mhbo-card" style="grid-column: 1 / -1; text-align: center; padding: 48px 24px;">
							<span class="dashicons dashicons-category" style="font-size: 48px; width: 48px; height: 48px; color: #cbd5e1; margin-bottom: 12px;"></span>
							<h4 style="margin: 0 0 6px 0; color: #1e293b;"><?php esc_html_e( 'No room categories defined yet.', 'modern-hotel-booking' ); ?></h4>
							<p style="margin: 0; color: #64748b; font-size: 13px;"><?php esc_html_e( 'Create your first room category using the builder above.', 'modern-hotel-booking' ); ?></p>
						</div>
					<?php else : ?>
						<?php foreach ( $types as $t ) : ?>
							<div class="mhbo-room-card mhbo-animate-in">
								<div class="mhbo-room-card-head">
									<div class="mhbo-room-thumb" style="width:72px; height:72px; min-width:72px; min-height:72px; position:relative; overflow:hidden; border-radius:10px; background:#f1f5f9;">
										<?php if ( $t->image_url ) : ?>
											<img src="<?php echo esc_url( $t->image_url ); ?>" class="mhbo-room-thumbnail" alt="<?php echo esc_attr( (string) ( I18n::decode( $t->name ) ?? '' ) ); ?>" loading="lazy" style="width:100%; height:100%; object-fit:cover; display:block;">
										<?php else : ?>
											<div class="mhbo-thumb-placeholder" style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#94a3b8;"><span class="dashicons dashicons-format-image" style="font-size:28px; width:28px; height:28px;"></span></div>
										<?php endif; ?>
										<div class="mhbo-room-badge-index">#<?php echo esc_html( $t->id ); ?></div>
									</div>
									<div class="mhbo-room-info-group" style="flex-grow:1;">
										<h4 class="mhbo-room-title" style="margin:0 0 4px 0; font-size:15px; font-weight:700; color:#0f172a;"><?php echo esc_html( (string) ( I18n::decode( $t->name ) ?? '' ) ); ?></h4>
										<span style="display:inline-block; font-size:13px; font-weight:700; color:#2563eb; background:#eff6ff; padding:2px 8px; border-radius:4px;">
											<?php echo esc_html( I18n::format_currency( $t->base_price ) ); ?> <small style="font-weight:500; color:#64748b;">/ <?php esc_html_e( 'night', 'modern-hotel-booking' ); ?></small>
										</span>
									</div>
								</div>
								<div class="mhbo-room-card-body" style="margin-top:14px;">
									<p class="mhbo-room-desc" style="margin:0 0 12px 0; font-size:13px; color:#475569; line-height:1.4;"><?php echo esc_html( wp_trim_words( (string) ( I18n::decode( $t->description ) ?? '' ), 12 ) ); ?></p>
									
									<div class="mhbo-room-meta-row" style="display:flex; gap:16px; margin-bottom:12px; font-size:12px; color:#64748b;">
										<div class="mhbo-meta-item">
											<span style="font-weight:600; color:#1e293b;">👥 <?php echo (int) $t->max_adults; ?> <?php esc_html_e( 'Adults', 'modern-hotel-booking' ); ?></span>
										</div>
										<?php if ( ! empty( $t->max_children ) ) : ?>
											<div class="mhbo-meta-item">
												<span style="font-weight:600; color:#1e293b;">👶 <?php echo (int) $t->max_children; ?> <?php esc_html_e( 'Children', 'modern-hotel-booking' ); ?></span>
											</div>
										<?php endif; ?>
									</div>
									<?php
									$t_disp = \MHBO\Frontend\Calendar::get_room_type_display_settings( (int) $t->id );
									if ( ! $t_disp['show_title'] || ! $t_disp['show_badge'] ) :
										?>
										<div style="display:flex; flex-wrap:wrap; gap:4px; margin-bottom:10px;">
											<?php if ( ! $t_disp['show_title'] ) : ?>
												<span style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:1px 6px; border-radius:4px; font-size:10.5px; font-weight:600;"><?php esc_html_e( 'Title Hidden', 'modern-hotel-booking' ); ?></span>
											<?php endif; ?>
											<?php if ( ! $t_disp['show_badge'] ) : ?>
												<span style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:1px 6px; border-radius:4px; font-size:10.5px; font-weight:600;"><?php esc_html_e( 'Badge Hidden', 'modern-hotel-booking' ); ?></span>
											<?php endif; ?>
										</div>
									<?php endif; ?>

									<div class="mhbo-amenities-mini" style="display:flex; flex-wrap:wrap; gap:4px; margin-bottom:14px;">
										<?php
										if ( isset( $t->amenities ) && $t->amenities ) {
											$ams_array = json_decode( $t->amenities, true );
											if ( is_array( $ams_array ) ) {
												foreach ( array_slice( $ams_array, 0, 3 ) as $k ) {
													$label = isset( $amenities_list[ $k ] ) ? $amenities_list[ $k ] : $k;
													echo '<span class="mhbo-mini-tag" style="background:#f1f5f9; color:#475569; padding:2px 6px; border-radius:4px; font-size:11px;">' . esc_html( $label ) . '</span>';
												}
												if ( count( $ams_array ) > 3 ) {
													echo '<span class="mhbo-mini-tag-more" style="background:#e2e8f0; color:#334155; padding:2px 6px; border-radius:4px; font-size:11px; font-weight:700;">+' . ( count( $ams_array ) - 3 ) . '</span>';
												}
											}
										}
										?>
									</div>
								</div>
								<div class="mhbo-room-card-actions" style="display:flex; gap:8px; border-top:1px solid #f1f5f9; padding-top:12px; justify-content:flex-end;">
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-room-types&action=edit&id={$t->id}" ), 'mhbo_edit_room_type_' . $t->id ) ); ?>"
										class="button button-small" title="<?php esc_attr_e( 'Edit Category', 'modern-hotel-booking' ); ?>">
										<span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e( 'Edit', 'modern-hotel-booking' ); ?>
									</a>
									
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-room-types&action=delete&id={$t->id}" ), 'mhbo_delete_room_type_' . $t->id ) ); ?>"
										class="button button-small button-link-delete" title="<?php esc_attr_e( 'Delete', 'modern-hotel-booking' ); ?>" aria-label="<?php esc_attr_e( 'Delete Room Type', 'modern-hotel-booking' ); ?>"
										onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this room type? This may affect existing rooms.', 'modern-hotel-booking' ); ?>')">
										<span class="dashicons dashicons-trash" aria-hidden="true"></span>
									</a>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
		<?php
	}

	public function display_rooms_page(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_ROOMS ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		$is_pro_active = false;

global $wpdb;
		$t_rooms           = esc_sql( $wpdb->prefix . 'mhbo_rooms' );
		$new_ical_table    = $wpdb->prefix . 'mhbo_ical_connections';
		$legacy_ical_table = $wpdb->prefix . 'mhbo_ical_feeds';
		$new_exists        = Cache::table_exists( $new_ical_table );
		$t_ical            = $new_exists ? $new_ical_table : $legacy_ical_table;

		$edit_mode  = false;
		$edit_data  = null;
		$ical_mode  = false;
		$ical_feeds = array();

		// Rule 11: Extract and sanitize all inputs at start
		$action      = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$sub_action  = isset( $_GET['sub_action'] ) ? sanitize_key( wp_unslash( $_GET['sub_action'] ) ) : '';
		$get_id      = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$get_feed_id = isset( $_GET['feed_id'] ) ? absint( wp_unslash( $_GET['feed_id'] ) ) : 0;
		$nonce       = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		// POST Actions
		$submit_ical_feed = isset( $_POST['submit_ical_feed'] );
		$submit_room      = isset( $_POST['submit_room'] );

// Delete Room Action
		if ( 'delete' === $action && $get_id > 0 && '' === $sub_action ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_delete_room_' . $get_id ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			$wpdb->delete( $t_rooms, array( 'id' => $get_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
			$room_disp_opts = get_option( 'mhbo_room_display_opts', array() );
			if ( is_array( $room_disp_opts ) && isset( $room_disp_opts[ $get_id ] ) ) {
				unset( $room_disp_opts[ $get_id ] );
				update_option( 'mhbo_room_display_opts', $room_disp_opts );
			}
			Cache::invalidate_rooms();
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_deleted' ) ) . '</p></div>';
		}

		// iCal Mode
		if ( 'ical' === $action && $get_id > 0 ) {
			if ( ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ), '', array( 'response' => 403 ) );
			}
			// Sub-actions (delete_feed, sync_now) carry their own nonces — skip page-level check for them.
			if ( '' === $sub_action ) {
				if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_ical_room_' . $get_id ) ) {
					wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
				}
			}
			
			if ( ! MHBO_IS_PRO ) {
				?>
				<div class="wrap mhbo-admin-wrap">
					<h1><?php esc_html_e( 'Manage Rooms', 'modern-hotel-booking' ); ?></h1>
					<?php
					if ( class_exists( Settings::class ) && method_exists( Settings::class, 'render_pro_upsell' ) ) {
						Settings::render_pro_upsell();
					} elseif ( class_exists( Upsell::class ) ) {
						Upsell::render_card(
							__( 'iCal Two-Way Channel Synchronization', 'modern-hotel-booking' ),
							__( 'Sync room availability automatically with Airbnb, Booking.com, and Vrbo using real-time iCal feeds.', 'modern-hotel-booking' )
						);
					} else {
						AdminUI::render_card_start( __( 'iCal Two-Way Channel Synchronization', 'modern-hotel-booking' ) );
						echo '<p>' . esc_html__( 'Two-way iCal synchronization is available in Modern Hotel Booking Pro.', 'modern-hotel-booking' ) . '</p>';
						AdminUI::render_card_end();
					}
					?>
					<p style="margin-top: 20px;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-rooms' ) ); ?>" class="button">&larr;
							<?php esc_html_e( 'Back to Rooms', 'modern-hotel-booking' ); ?></a>
					</p>
				</div>
				<?php
				return;
			}

if ( $submit_ical_feed ) {
				check_admin_referer( 'mhbo_add_ical' );

				$feed_name = isset( $_POST['feed_name'] ) ? sanitize_text_field( wp_unslash( $_POST['feed_name'] ) ) : '';
				$feed_url  = isset( $_POST['feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['feed_url'] ) ) : '';

				if ( '' === $feed_url || ! wp_http_validate_url( $feed_url ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid feed URL. Please provide a valid HTTPS address.', 'modern-hotel-booking' ) . '</p></div>';
				} elseif ( ! Security::is_safe_url( $feed_url ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Feed URL is not permitted for security reasons (internal addresses are blocked).', 'modern-hotel-booking' ) . '</p></div>';
				} else {
					// Auto-detect platform from URL so the main iCal manager shows the right icon.
					$url_lower = strtolower( $feed_url );
					if ( strpos( $url_lower, 'airbnb.com' ) !== false ) {
						$platform = 'airbnb';
					} elseif ( strpos( $url_lower, 'booking.com' ) !== false || strpos( $url_lower, 'admin.booking' ) !== false ) {
						$platform = 'booking.com';
					} elseif ( strpos( $url_lower, 'google.com' ) !== false || strpos( $url_lower, 'calendar.google' ) !== false ) {
						$platform = 'google_calendar';
					} else {
						$platform = 'custom';
					}

					if ( $new_exists ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
						$wpdb->insert(
							$t_ical,
							array(
								'room_id'        => $get_id,
								'name'           => $feed_name,
								'ical_url'       => $feed_url,
								'platform'       => $platform,
								'sync_direction' => 'import',
								'sync_status'    => 'pending',
								'created_at'     => HotelTime::now_mysql(),
							),
							array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
						);
					} else {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Legacy table
						$wpdb->insert(
							$t_ical,
							array(
								'room_id'   => $get_id,
								'feed_name' => $feed_name,
								'feed_url'  => $feed_url,
							),
							array( '%d', '%s', '%s' )
						);
					}
					Cache::invalidate_rooms();
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_feed_added' ) ) . '</p></div>';
				}
			}

			if ( 'delete_feed' === $sub_action && $get_feed_id > 0 ) {
				check_admin_referer( 'mhbo_delete_feed_' . $get_feed_id );
				// Scope delete to room_id so a user can't delete another room's feed by guessing IDs.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete(
					$t_ical,
					array(
						'id'      => $get_feed_id,
						'room_id' => $get_id,
					),
					array( '%d', '%d' ) 
				);
				Cache::invalidate_rooms();
			}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ical_feeds = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_ical_connections WHERE room_id = %d", $get_id ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$room_info = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d", $get_id ) );
		}

		// Edit Room Action
		if ( 'edit' === $action && $get_id > 0 ) {
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'mhbo_edit_room_' . $get_id ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$edit_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_rooms WHERE id = %d", $get_id ) );
			$edit_mode = ( null !== $edit_data );
		}

		// Save Room Action
		if ( $submit_room ) {
			$post_room_id = isset( $_POST['room_id'] ) ? absint( wp_unslash( $_POST['room_id'] ) ) : 0;
			$nonce_action = $post_room_id > 0 ? 'mhbo_edit_room_' . $post_room_id : 'mhbo_add_room';
			check_admin_referer( $nonce_action );

			$type_id          = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;
			$room_number      = isset( $_POST['room_number'] ) ? sanitize_text_field( wp_unslash( $_POST['room_number'] ) ) : '';
			$room_status      = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'available';
			$custom_price_raw = isset( $_POST['custom_price'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_price'] ) ) : '';
			$room_image_url   = isset( $_POST['room_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['room_image_url'] ) ) : '';

			$data = array(
				'type_id'      => $type_id,
				'room_number'  => $room_number,
				'custom_price' => ( isset( $custom_price_raw ) && $custom_price_raw !== '' ) ? floatval( $custom_price_raw ) : null,
				'status'       => $room_status,
				'image_url'    => $room_image_url !== '' ? $room_image_url : null,
			);

			$room_show_title = isset( $_POST['room_show_title'] ) && 1 === absint( wp_unslash( $_POST['room_show_title'] ) ) ? 1 : 0;
			$room_show_badge = isset( $_POST['room_show_badge'] ) && 1 === absint( wp_unslash( $_POST['room_show_badge'] ) ) ? 1 : 0;

			if ( $post_room_id > 0 ) {
				$wpdb->update( $t_rooms, $data, array( 'id' => $post_room_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table
				$saved_room_id = $post_room_id;
				Cache::invalidate_rooms();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_updated' ) ) . '</p></div>';
				$edit_mode = false;
			} else {
				$wpdb->insert( $t_rooms, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
				$saved_room_id = (int) $wpdb->insert_id;
				Cache::invalidate_rooms();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_room_added' ) ) . '</p></div>';
			}

			if ( $saved_room_id > 0 ) {
				$room_disp_opts = get_option( 'mhbo_room_display_opts', array() );
				if ( ! is_array( $room_disp_opts ) ) {
					$room_disp_opts = array();
				}
				$room_disp_opts[ $saved_room_id ] = array(
					'show_title' => $room_show_title,
					'show_badge' => $room_show_badge,
				);
				update_option( 'mhbo_room_display_opts', $room_disp_opts );
				Cache::bump( Cache::TABLE_SETTINGS );
			}
		}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rooms = $wpdb->get_results( "SELECT r.*, t.name as type_name, t.base_price, t.image_url as type_image_url FROM {$wpdb->prefix}mhbo_rooms r LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id ORDER BY (r.room_number + 0) ASC, r.room_number ASC, r.id ASC" );
		if ( is_array( $rooms ) ) {
			usort(
				$rooms,
				function ( $a, $b ) {
					$cmp = strnatcasecmp( (string) $a->room_number, (string) $b->room_number );
					if ( 0 !== $cmp ) {
						return $cmp;
					}
					return ( (int) $a->id ) <=> ( (int) $b->id );
				}
			);
		}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$types = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mhbo_room_types" );

		$currency          = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$total_rooms       = count( $rooms );
		$available_count   = 0;
		$maintenance_count = 0;
		foreach ( $rooms as $r ) {
			if ( $r->status === 'available' ) {
				++$available_count;
			}
			if ( $r->status === 'maintenance' ) {
				++$maintenance_count;
			}
		}
		?>
		<div class="wrap mhbo-admin-wrap mhbo-rooms-wrap">
			<?php 
			AdminUI::render_header(
				I18n::get_label( 'title_room_inventory' ), 
				I18n::get_label( 'desc_room_inventory' ),
				array(
					array(
						'label' => __( '🏷️ Room Categories', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-room-types' ),
						'class' => 'button-secondary',
					),
					array(
						'label' => __( '📅 Master Calendar', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-bookings#mhbo-calendar' ),
						'class' => 'button-secondary',
					),
				),
				array(
					array(
						'label' => I18n::get_label( 'menu_main' ),
						'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
					),
				)
			); 
			?>

			<!-- 3-KPI Overview Ribbon -->
			<div class="mhbo-stats-grid" style="margin-bottom: 24px;">
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value"><?php echo esc_html( (string) $total_rooms ); ?></div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Total Physical Units', 'modern-hotel-booking' ); ?></div>
				</div>
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value" style="color: #166534; display:flex; align-items:center; gap:8px;">
						<span class="mhbo-pulse-indicator" style="background:#22c55e;"></span>
						<?php echo esc_html( (string) $available_count ); ?>
					</div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Ready for Guests', 'modern-hotel-booking' ); ?></div>
				</div>
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value" style="color: #9a3412; display:flex; align-items:center; gap:8px;">
						<span class="mhbo-pulse-indicator mhbo-pulse-amber"></span>
						<?php echo esc_html( (string) $maintenance_count ); ?>
					</div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Out of Service / Maintenance', 'modern-hotel-booking' ); ?></div>
				</div>
			</div>

<?php if ( count( $types ) === 0 ) : ?>
				<div class="notice notice-warning inline" style="margin-bottom: 25px; padding: 16px 20px; border-left: 4px solid #dba617; background: #fff8e5; border-radius: 6px;">
					<h4 style="margin: 0 0 8px 0; font-size: 15px; font-weight: 700; color: #1d2327;">
						<?php esc_html_e( '⚠️ Step 1: Create a Room Type First', 'modern-hotel-booking' ); ?>
					</h4>
					<p style="margin: 0 0 12px 0; font-size: 13px; line-height: 1.5; color: #50575e;">
						<?php esc_html_e( 'Before registering physical rooms (e.g. Room 101, 102), you must create at least one Room Type (e.g. Deluxe Suite, Standard Double) with base pricing and occupancy settings.', 'modern-hotel-booking' ); ?>
					</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-room-types' ) ); ?>" class="button button-primary" style="font-weight: 600;">
						<?php esc_html_e( 'Create Your First Room Type →', 'modern-hotel-booking' ); ?>
					</a>
				</div>
			<?php endif; ?>

			<div class="mhbo-card <?php echo esc_attr( $edit_mode ? 'accent' : '' ); ?>" id="mhbo-add-unit-card" style="margin-bottom:30px; <?php echo esc_attr( $edit_mode ? 'border-left: 4px solid #3b82f6;' : '' ); ?>">
				<h3 style="margin-top:0; margin-bottom: 20px; font-size: 1.15rem; font-weight:700; color:#0f172a; display: flex; align-items: center; gap:8px;">
					<span class="dashicons dashicons-plus-alt" style="color: <?php echo esc_attr( $edit_mode ? '#3b82f6' : '#2563eb' ); ?>;"></span>
					<?php echo $edit_mode ? esc_html( I18n::get_label( 'title_modify_unit' ) ) : esc_html( I18n::get_label( 'title_new_unit' ) ); ?>
				</h3>
				<form method="post">
					<?php wp_nonce_field( $edit_mode ? 'mhbo_edit_room_' . $edit_data->id : 'mhbo_add_room' ); ?>
					<?php
					if ( $edit_mode ) :
						?>
						<input type="hidden" name="room_id" value="<?php echo esc_attr( $edit_data->id ); ?>"><?php endif; ?>
					
					<div class="mhbo-settings-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px;">
						<div class="mhbo-settings-item">
							<label class="mhbo-label"><?php esc_html_e( 'Classification / Type', 'modern-hotel-booking' ); ?> <span style="color:#ef4444;">*</span></label>
							<select name="type_id" class="mhbo-input-mid" required style="width:100%;">
								<option value=""><?php esc_html_e( '— Select Room Type —', 'modern-hotel-booking' ); ?></option>
								<?php foreach ( $types as $t ) : ?>
									<option value="<?php echo esc_attr( $t->id ); ?>" <?php echo ( $edit_mode && (int) $edit_data->type_id === (int) $t->id ) ? 'selected' : ''; ?>>
										<?php echo esc_html( (string) ( I18n::decode( $t->name ) ?? '' ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="mhbo-settings-item">
							<label class="mhbo-label"><?php esc_html_e( 'Room Identifier / Number', 'modern-hotel-booking' ); ?> <span style="color:#ef4444;">*</span></label>
							<input type="text" name="room_number" value="<?php echo $edit_mode ? esc_attr( $edit_data->room_number ) : ''; ?>" required class="mhbo-input-mid" placeholder="<?php esc_attr_e( 'e.g. Room 101, Junior Suite A', 'modern-hotel-booking' ); ?>">
						</div>
						<div class="mhbo-settings-item">
							<label class="mhbo-label">
								<?php esc_html_e( 'Override Daily Rate', 'modern-hotel-booking' ); ?>
								<span class="mhbo-tooltip"><i class="mhbo-help-icon">?</i><span class="mhbo-tooltip-text"><?php esc_html_e( 'Specific price for this unit. Leave at 0 to use the category default.', 'modern-hotel-booking' ); ?></span></span>
							</label>
							<div class="mhbo-input-prefix-container">
								<span class="mhbo-input-prefix"><?php echo esc_html( $currency ); ?></span>
								<input type="number" step="any" name="custom_price" value="<?php echo $edit_mode ? esc_attr( Money::fromDecimal( (string) ( $edit_data->custom_price ?? 0 ) )->toDecimal() ) : '0.00'; ?>" class="mhbo-input-mid">
							</div>
						</div>
						<div class="mhbo-settings-item">
							<label class="mhbo-label"><?php esc_html_e( 'Operational Status', 'modern-hotel-booking' ); ?></label>
							<select name="status" class="mhbo-input-mid" style="width:100%;">
								<option value="available" <?php echo ( $edit_mode && 'available' === $edit_data->status ) ? 'selected' : ''; ?>><?php esc_html_e( '🟢 Live & Reservable', 'modern-hotel-booking' ); ?></option>
								<option value="maintenance" <?php echo ( $edit_mode && 'maintenance' === $edit_data->status ) ? 'selected' : ''; ?>><?php esc_html_e( '🟠 Inactive / Maintenance', 'modern-hotel-booking' ); ?></option>
							</select>
						</div>
						<div class="mhbo-settings-item" style="grid-column: 1 / -1;">
							<label class="mhbo-label" for="mhbo_unit_image_url"><?php esc_html_e( 'Room Image (Optional)', 'modern-hotel-booking' ); ?></label>
							<div class="mhbo-media-selector">
								<input type="text" name="room_image_url" id="mhbo_unit_image_url"
									value="<?php echo $edit_mode ? esc_attr( $edit_data->image_url ?? '' ) : ''; ?>"
									class="mhbo-input-large" placeholder="https://...">
								<button type="button" class="mhbo-btn mhbo-btn-outline mhbo-upload-button" data-target="#mhbo_unit_image_url">
									<span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Select', 'modern-hotel-booking' ); ?>
								</button>
							</div>
							<p class="description" style="margin: 6px 0 0 0; color: #64748b; font-size: 12px;"><?php esc_html_e( 'Optional. Overrides the room type image for this specific unit on the frontend.', 'modern-hotel-booking' ); ?></p>
							<?php if ( $edit_mode && '' !== (string) ( $edit_data->image_url ?? '' ) ) : ?>
								<img src="<?php echo esc_url( $edit_data->image_url ); ?>" alt="" style="margin-top: 10px; max-height: 80px; border-radius: 8px; object-fit: cover; border: 1px solid #cbd5e1;">
							<?php endif; ?>
						</div>
						<?php
						$edit_room_disp = ( $edit_mode && isset( $edit_data->id ) )
							? \MHBO\Frontend\Calendar::get_room_display_settings( (int) $edit_data->id )
							: array(
								'show_title' => true,
								'show_badge' => true,
							);
						?>
						<div class="mhbo-settings-item" style="grid-column: 1 / -1; padding-top: 14px; border-top: 1px dashed #e2e8f0;">
							<label class="mhbo-label"><?php esc_html_e( 'Frontend Display Options (Calendar Title & Room Badge)', 'modern-hotel-booking' ); ?></label>
							<p class="description" style="margin: 0 0 10px 0; color: #64748b; font-size: 12px;">
								<?php esc_html_e( 'Visually enable or disable the room title heading and physical room number badge on this room\'s availability calendar and checkout summary (no shortcode parameters needed).', 'modern-hotel-booking' ); ?>
							</p>
							<div style="display: flex; flex-wrap: wrap; gap: 18px;">
								<label class="mhbo-checkbox-item" style="display: inline-flex; align-items: center; gap: 8px; background: #f8fafc; padding: 8px 14px; border: 1px solid #cbd5e1; border-radius: 6px; cursor: pointer;">
									<input type="hidden" name="room_show_title" value="0">
									<input type="checkbox" name="room_show_title" value="1" <?php checked( $edit_room_disp['show_title'] ); ?>>
									<span><strong><?php esc_html_e( 'Show Room Title on Frontend Calendar', 'modern-hotel-booking' ); ?></strong></span>
								</label>
								<label class="mhbo-checkbox-item" style="display: inline-flex; align-items: center; gap: 8px; background: #f8fafc; padding: 8px 14px; border: 1px solid #cbd5e1; border-radius: 6px; cursor: pointer;">
									<input type="hidden" name="room_show_badge" value="0">
									<input type="checkbox" name="room_show_badge" value="1" <?php checked( $edit_room_disp['show_badge'] ); ?>>
									<span><strong><?php esc_html_e( 'Show Room Number Badge on Frontend', 'modern-hotel-booking' ); ?></strong></span>
								</label>
							</div>
						</div>
					</div>

					<div class="mhbo-form-actions-dock" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0; display:flex; gap:12px; align-items:center;">
						<input type="submit" name="submit_room" class="button button-primary button-hero"
							value="<?php echo $edit_mode ? esc_attr( I18n::get_label( 'btn_save_unit' ) ) : esc_attr( I18n::get_label( 'btn_register_unit' ) ); ?>">
						<?php if ( $edit_mode ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mhbo-rooms' ) ); ?>"
								class="button button-secondary button-hero"><?php esc_html_e( 'Discard Changes', 'modern-hotel-booking' ); ?></a>
						<?php endif; ?>
					</div>
				</form>
			</div>

			<div class="mhbo-card mhbo-card--flush">
				<div style="display:flex; justify-content:space-between; align-items:center; padding: 20px 24px; border-bottom: 1px solid #e2e8f0; background: #ffffff; border-top-left-radius: 12px; border-top-right-radius: 12px;">
					<h3 style="margin:0; font-size:1.15rem; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;">
						<span class="dashicons dashicons-list-view" style="color: #2563eb;"></span>
						<?php esc_html_e( 'Full Unit Inventory', 'modern-hotel-booking' ); ?>
					</h3>
					<span style="font-size:12px; color:#64748b; font-weight:700; background:#f1f5f9; padding:4px 12px; border-radius:99px; border:1px solid #e2e8f0;"><?php echo esc_html( (string) $total_rooms ); ?> <?php esc_html_e( 'Units Configured', 'modern-hotel-booking' ); ?></span>
				</div>
				<div class="mhbo-table-responsive">
					<table class="wp-list-table widefat fixed striped mhbo-modern-inventory-table" style="box-shadow: none; border: none; margin: 0;">
						<thead>
							<tr>
								<th style="width:60px;"><?php echo esc_html( I18n::get_label( 'label_col_id' ) ); ?></th>
								<th style="width:72px;"><?php esc_html_e( 'Image', 'modern-hotel-booking' ); ?></th>
								<th style="width:140px;"><?php echo esc_html( I18n::get_label( 'label_col_unit' ) ); ?></th>
								<th><?php echo esc_html( I18n::get_label( 'label_classification' ) ); ?></th>
								<th><?php echo esc_html( I18n::get_label( 'label_col_rate' ) ); ?></th>
								<th style="width:160px;"><?php echo esc_html( I18n::get_label( 'label_col_status' ) ); ?></th>
								<th style="width:170px; text-align: right;"><?php echo esc_html( I18n::get_label( 'label_col_mgmt' ) ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( count( $rooms ) === 0 ) : ?>
								<tr><td colspan="7" style="padding:48px 24px; text-align:center; color:#475569; font-style: italic;"><?php esc_html_e( 'Inventory is completely empty. Register your first physical room unit above.', 'modern-hotel-booking' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $rooms as $r ) : ?>
									<tr>
										<td data-colname="<?php echo esc_attr( I18n::get_label( 'label_col_id' ) ); ?>"><span class="mhbo-id-badge">#<?php echo esc_html( $r->id ); ?></span></td>
										<td data-colname="<?php esc_attr_e( 'Image', 'modern-hotel-booking' ); ?>">
											<?php $thumb = ( isset( $r->image_url ) ? (string) $r->image_url : '' ) ?: ( $r->type_image_url ?? '' ); ?>
											<?php if ( $thumb ) : ?>
												<img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:52px; height:38px; object-fit:cover; border-radius:6px; display:block;">
											<?php else : ?>
												<span style="display:flex; align-items:center; justify-content:center; width:52px; height:38px; background:#f1f5f9; border-radius:6px; color:#64748b;"><span class="dashicons dashicons-format-image" style="font-size:20px; width:20px; height:20px;" aria-hidden="true"></span></span>
											<?php endif; ?>
										</td>
										<td data-colname="<?php echo esc_attr( I18n::get_label( 'label_col_unit' ) ); ?>">
											<strong style="font-size: 1.05rem; color: #0f172a;"><?php echo esc_html( $r->room_number ); ?></strong>
											<?php
											$r_disp = \MHBO\Frontend\Calendar::get_room_display_settings( (int) $r->id );
											if ( ! $r_disp['show_title'] || ! $r_disp['show_badge'] ) :
												?>
												<div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:4px;">
													<?php if ( ! $r_disp['show_title'] ) : ?>
														<span style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:1px 5px; border-radius:4px; font-size:10px; font-weight:600;"><?php esc_html_e( 'Title Hidden', 'modern-hotel-booking' ); ?></span>
													<?php endif; ?>
													<?php if ( ! $r_disp['show_badge'] ) : ?>
														<span style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:1px 5px; border-radius:4px; font-size:10px; font-weight:600;"><?php esc_html_e( 'Badge Hidden', 'modern-hotel-booking' ); ?></span>
													<?php endif; ?>
												</div>
											<?php endif; ?>
										</td>
										<td data-colname="<?php echo esc_attr( I18n::get_label( 'label_classification' ) ); ?>">
											<span style="background: #eff6ff; color: #1e40af; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
												<?php echo esc_html( (string) ( I18n::decode( $r->type_name ) ?? '' ) ); ?>
											</span>
										</td>
										<td data-colname="<?php echo esc_attr( I18n::get_label( 'label_col_rate' ) ); ?>">
											<span style="font-weight: 700; color: #166534;"><?php echo esc_html( I18n::format_currency( $r->custom_price ?: $r->base_price ) ); ?></span>
											<?php if ( $r->custom_price ) : ?>
												<span class="mhbo-tooltip" style="margin-left: 5px;"><i class="mhbo-help-icon">!</i><span class="mhbo-tooltip-text"><?php esc_html_e( 'Custom rate override active for this unit.', 'modern-hotel-booking' ); ?></span></span>
											<?php endif; ?>
										</td>
										<td data-colname="<?php echo esc_attr( I18n::get_label( 'label_col_status' ) ); ?>">
											<?php if ( $r->status === 'available' ) : ?>
												<span style="display: inline-flex; align-items: center; gap: 6px; color: #166534; background: #dcfce7; padding: 3px 10px; border-radius: 99px; font-weight: 700; font-size: 11px; text-transform: uppercase;">
													<span style="width: 6px; height: 6px; border-radius: 50%; background: #22c55e;"></span>
													<?php esc_html_e( 'Receiving Bookings', 'modern-hotel-booking' ); ?>
												</span>
											<?php else : ?>
												<span style="display: inline-flex; align-items: center; gap: 6px; color: #991b1b; background: #fee2e2; padding: 3px 10px; border-radius: 99px; font-weight: 700; font-size: 11px; text-transform: uppercase;">
													<span style="width: 6px; height: 6px; border-radius: 50%; background: #ef4444;"></span>
													<?php esc_html_e( 'Out of Service', 'modern-hotel-booking' ); ?>
												</span>
											<?php endif; ?>
										</td>
										<td data-colname="<?php echo esc_attr( I18n::get_label( 'label_col_mgmt' ) ); ?>" style="text-align: right;">
											<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; min-width: 140px;">
												<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-rooms&action=edit&id={$r->id}" ), 'mhbo_edit_room_' . $r->id ) ); ?>"
													class="button button-small" title="<?php esc_attr_e( 'Edit Unit', 'modern-hotel-booking' ); ?>" aria-label="<?php esc_attr_e( 'Edit Unit', 'modern-hotel-booking' ); ?>"><span class="dashicons dashicons-edit" aria-hidden="true"></span></a>

<a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=mhbo-rooms&action=delete&id={$r->id}" ), 'mhbo_delete_room_' . $r->id ) ); ?>"
													class="button button-small button-link-delete"
													title="<?php esc_attr_e( 'Delete Unit', 'modern-hotel-booking' ); ?>"
													aria-label="<?php esc_attr_e( 'Delete Unit', 'modern-hotel-booking' ); ?>"
													onclick="return confirm('<?php esc_attr_e( 'Permanently remove this unit from inventory?', 'modern-hotel-booking' ); ?>')"><span class="dashicons dashicons-trash" aria-hidden="true"></span></a>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	public function display_extras_page(): void {
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE_PRICING ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html( I18n::get_label( 'msg_insufficient_permissions' ) ) );
		}

		// Handle Form Submission
		if ( isset( $_POST['mhbo_save_extras'] ) ) {
			if ( ! Capabilities::current_user_can( Capabilities::MANAGE_PRICING ) && ! Capabilities::current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_insufficient_perms_short' ) ) );
			}
			if ( ! check_admin_referer( 'mhbo_save_extras_action' ) ) {
				wp_die( esc_html( I18n::get_label( 'msg_security_check_failed' ) ) );
			}
			$new_extras = array();
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field within loop below.
			$raw_extras = isset( $_POST['extras'] ) && is_array( $_POST['extras'] ) ? wp_unslash( $_POST['extras'] ) : array();
			if ( ! empty( $raw_extras ) ) {
				$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
				foreach ( $raw_extras as $ex ) {
					if ( ! is_array( $ex ) ) {
						continue;
					}
					$name = sanitize_text_field( (string) ( $ex['name'] ?? '' ) );
					if ( '' === trim( $name ) ) {
						continue;
					}
					$price_raw = (string) ( $ex['price'] ?? '0' );
					try {
						$price_obj       = Money::fromDecimal( $price_raw, $currency );
						$price_formatted = $price_obj->isNegative() ? '0.00' : $price_obj->toDecimal();
					} catch ( \Throwable $e ) {
						$price_formatted = '0.00';
					}

					$valid_pricing_types = array(
						'fixed',
						'per_person',
						'per_adult',
						'per_child',
						'per_night',
						'per_person_per_night',
						'per_adult_per_night',
						'per_child_per_night',
					);
					$pricing_type = sanitize_key( (string) ( $ex['pricing_type'] ?? 'fixed' ) );
					if ( ! in_array( $pricing_type, $valid_pricing_types, true ) ) {
						$pricing_type = 'fixed';
					}

					$control_type = sanitize_key( (string) ( $ex['control_type'] ?? 'checkbox' ) );
					if ( ! in_array( $control_type, array( 'checkbox', 'quantity' ), true ) ) {
						$control_type = 'checkbox';
					}

					$new_extras[] = array(
						'id'           => ( ! empty( $ex['id'] ) && '' !== trim( (string) $ex['id'] ) ) ? sanitize_text_field( (string) $ex['id'] ) : uniqid( 'extra_' ),
						'name'         => $name,
						'price'        => $price_formatted,
						'pricing_type' => $pricing_type,
						'control_type' => $control_type,
						'icon'         => isset( $ex['icon'] ) ? sanitize_key( (string) $ex['icon'] ) : 'dashicons-star-filled',
						'description'  => isset( $ex['description'] ) ? sanitize_textarea_field( (string) $ex['description'] ) : '',
						
					);
				}
			}
			update_option( 'mhbo_pro_extras', $new_extras );
			Cache::invalidate_pricing();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( I18n::get_label( 'msg_extras_saved' ) ) . '</p></div>';
		}

		$extras = get_option( 'mhbo_pro_extras', array() );
		?>
		<div class="wrap mhbo-admin-wrap mhbo-extras-wrap">
			<?php
			AdminUI::render_header(
				__( 'Service Extras & Add-ons', 'modern-hotel-booking' ),
				__( 'Configure bookable guest amenities, packages, airport transfers, and optional services.', 'modern-hotel-booking' ),
				array(),
				array(
					array(
						'label' => I18n::get_label( 'menu_main' ),
						'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
					),
				)
			);
			?>
			
			<div class="mhbo-stats-grid" style="margin-bottom: 24px;">
				<div class="mhbo-stat-card">
					<div class="mhbo-stat-value" id="mhbo-extras-count"><?php echo esc_html( (string) count( $extras ) ); ?></div>
					<div class="mhbo-stat-label"><?php esc_html_e( 'Active Add-on Services', 'modern-hotel-booking' ); ?></div>
				</div>
			</div>

			<div class="mhbo-card" style="margin-top: 30px;">
				<h3 style="margin-top:0; margin-bottom: 25px; display: flex; align-items: center;">
					<span class="dashicons dashicons-money-alt" style="margin-right: 10px; color: #3b82f6;"></span>
					<?php esc_html_e( 'Configure Available Services', 'modern-hotel-booking' ); ?>
				</h3>

				<form method="post" id="mhbo-extras-form">
					<?php wp_nonce_field( 'mhbo_save_extras_action' ); ?>
					<div id="mhbo-extras-list">
						<?php
						if ( count( $extras ) > 0 ) {
							foreach ( $extras as $index => $extra ) {
								$this->render_extra_item( $index, $extra );
							}
						} else {
							echo '<div id="mhbo-extras-empty" style="text-align:center; padding:60px 20px; color:#94a3b8; border:2px dashed #e2e8f0; border-radius:12px; margin-bottom:25px;">
                                <span class="dashicons dashicons-plus-alt" style="font-size: 40px; width: 40px; height: 40px; margin-bottom: 15px; opacity: 0.5;"></span>
                                <p style="font-size: 1.1rem; margin: 0;">' . esc_html( I18n::get_label( 'msg_no_extras_desc' ) ) . '</p>
                                <p style="font-size: 0.9rem; margin-top: 5px; opacity: 0.7;">' . esc_html( I18n::get_label( 'msg_extras_examples' ) ) . '</p>
                            </div>';
						}
						?>
					</div>
					
					<div class="mhbo-extras-toolbar" style="margin-top:30px; padding-top:25px; border-top:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
						<button type="button" class="button button-secondary button-hero mhbo-add-extra-btn" id="mhbo-add-extra">
							<span class="dashicons dashicons-plus-alt" aria-hidden="true"></span>
							<span><?php echo esc_html( I18n::get_label( 'label_add_extra' ) ); ?></span>
						</button>
						<button type="submit" name="mhbo_save_extras" class="button button-primary button-hero mhbo-save-all-extras-btn" style="display:inline-flex; align-items:center; justify-content:center; gap:8px;">
							<span class="dashicons dashicons-saved" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Save All Add-ons', 'modern-hotel-booking' ); ?></span>
						</button>
					</div>
				</form>
			</div>

			<?php 
			$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
			?>
			<script type="text/template" id="tmpl-mhbo-extra">
				<div class="mhbo-extra-item mhbo-card accent" data-extra-id="">
					<?php $this->render_extra_fields( '{{data.index}}', array() ); ?>
					<div class="mhbo-extra-actions">
						<button type="button" class="button button-primary mhbo-save-extra"><?php echo esc_html( I18n::get_label( 'btn_save' ) ); ?></button>
						<button type="button" class="button mhbo-remove-extra" style="color:#b32d2e;border-color:#b32d2e;"><?php echo esc_html( I18n::get_label( 'btn_delete' ) ); ?></button>
					</div>
				</div>
			</script>
		</div>
		<?php
	}

	/**
	 * Render a single extra item card.
	 *
	 * @param string|int           $index The item index.
	 * @param array<string, mixed> $extra The extra item data.
	 */
	private function render_extra_item( string|int $index, array $extra ): void {
		?>
		<div class="mhbo-extra-item mhbo-card accent" data-extra-id="<?php echo esc_attr( (string) ( $extra['id'] ?? '' ) ); ?>">
			<?php $this->render_extra_fields( (string) $index, $extra ); ?>
			<div class="mhbo-extra-actions">
				<button type="button" class="button button-primary mhbo-save-extra"><?php esc_html_e( 'Save', 'modern-hotel-booking' ); ?></button>
				<button type="button" class="button mhbo-remove-extra" style="color:#b32d2e;border-color:#b32d2e;"><?php esc_html_e( 'Delete', 'modern-hotel-booking' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the fields for an extra item.
	 *
	 * @param string|int           $index The item index.
	 * @param array<string, mixed> $extra The extra item data.
	 */
	private function render_extra_fields( string|int $index, array $extra ): void {
		$index    = (string) $index;
		$id       = (string) ( $extra['id'] ?? '' );
		$name     = (string) ( $extra['name'] ?? '' );
		$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		$price    = Money::fromDecimal( (string) ( $extra['price'] ?? '0' ), $currency )->toDecimal();

		$desc          = (string) ( $extra['description'] ?? '' );
		$pt            = $extra['pricing_type'] ?? 'fixed';
		$ct            = $extra['control_type'] ?? 'checkbox';
		$selected_icon = $extra['icon'] ?? 'dashicons-star-filled';
		
		$is_pro = class_exists( Tier::class ) && Tier::is_effective_at_least( Tier::TIER_PRO );
		?>
		<input type="hidden" name="extras[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $id ); ?>">
		<div class="mhbo-extra-grid">
			<div>
				<table class="form-table" style="margin:0;">
					<tr>
						<th style="width:160px;"><label><?php echo esc_html( I18n::get_label( 'label_service_title' ) ); ?></label></th>
						<td>
							<div style="display:flex; gap:10px; align-items:center;">
								<input type="text" name="extras[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" class="widefat" placeholder="<?php echo esc_attr( I18n::get_label( 'label_service_title_placeholder' ) ); ?>" required>
								<?php if ( $is_pro ) : ?>
									
								<?php endif; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th><label><?php echo esc_html( I18n::get_label( 'label_base_price' ) ); ?></label></th>
						<td>
							<div style="display: flex; align-items: center; gap: 10px;">
								<input type="number" step="any" name="extras[<?php echo esc_attr( $index ); ?>][price]" value="<?php echo esc_attr( $price ); ?>" class="small-text" required> 
								<span class="description" style="font-weight: 600;"><?php echo esc_html( $currency ); ?></span>
							</div>
						</td>
					</tr>
					<tr>
						<th><label><?php echo esc_html( I18n::get_label( 'label_pricing_model' ) ); ?></label></th>
						<td>
							<select name="extras[<?php echo esc_attr( $index ); ?>][pricing_type]" class="widefat">
								<option value="fixed" <?php selected( $pt, 'fixed' ); ?>><?php echo esc_html( I18n::get_label( 'opt_fixed_fee' ) ); ?></option>
								<option value="per_person" <?php selected( $pt, 'per_person' ); ?>><?php echo esc_html( I18n::get_label( 'opt_per_person' ) ); ?></option>
								
								<?php if ( $is_pro ) : ?>
									
								<?php endif; ?>

								<option value="per_night" <?php selected( $pt, 'per_night' ); ?>><?php echo esc_html( I18n::get_label( 'opt_per_night' ) ); ?></option>
								<option value="per_person_per_night" <?php selected( $pt, 'per_person_per_night' ); ?>><?php echo esc_html( I18n::get_label( 'opt_person_night' ) ); ?></option>
								
								<?php if ( $is_pro ) : ?>
									
								<?php endif; ?>
							</select>
						</td>
					</tr>
				</table>
			</div>
			<div>
				<table class="form-table" style="margin:0;">
					<tr class="mhbo-control-type-row">
						<th style="width:160px;"><label><?php echo esc_html( I18n::get_label( 'label_booking_input' ) ); ?></label></th>
						<td>
							<select name="extras[<?php echo esc_attr( $index ); ?>][control_type]" class="widefat">
								<option value="checkbox" <?php selected( $ct, 'checkbox' ); ?>><?php echo esc_html( I18n::get_label( 'opt_checkbox' ) ); ?></option>
								<option value="quantity" <?php selected( $ct, 'quantity' ); ?>><?php echo esc_html( I18n::get_label( 'opt_quantity' ) ); ?></option>
							</select>
						</td>
					</tr>
					
					<tr>
						<th><label><?php echo esc_html( I18n::get_label( 'label_public_description' ) ); ?></label></th>
						<td><textarea name="extras[<?php echo esc_attr( $index ); ?>][description]" rows="3" class="widefat" placeholder="<?php echo esc_attr( I18n::get_label( 'label_desc_placeholder' ) ); ?>" style="font-size: 13px;"><?php echo esc_textarea( $desc ); ?></textarea></td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}
}
