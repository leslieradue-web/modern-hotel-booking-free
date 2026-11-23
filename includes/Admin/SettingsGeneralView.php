<?php declare(strict_types=1);

namespace MHBO\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\HotelTime;
use MHBO\Core\I18n;
use MHBO\Core\Tier;

/**
 * General Settings Views
 *
 * Renders the General, Custom Labels, Amenities, Shortcode Reference, and
 * Room Inventory Summary sections inside MHBO Settings.
 */
class SettingsGeneralView {

    /**
     * Render the shortcode setup guide info box at the bottom of the General tab.
     */
    public static function render_shortcode_info(): void {
        echo '<div class="mhbo-settings-section-card" style="margin-top: 24px; border-left: 4px solid #2563eb;">';
        echo '<div class="mhbo-settings-section-title-wrap" style="margin-bottom: 12px;">';
        echo '<div class="mhbo-settings-section-icon" style="background:#eff6ff;color:#2563eb;"><span class="dashicons dashicons-shortcode"></span></div>';
        echo '<div><h4 style="margin:0;font-size:15px;font-weight:700;color:#0f172a;">' . esc_html(I18n::get_label('setup_guide_title')) . '</h4>';
        echo '<p style="margin:2px 0 0 0;font-size:12.5px;color:#64748b;">' . esc_html(__('Publishing calendars and booking flows across your WordPress pages using Gutenberg blocks or shortcodes.', 'modern-hotel-booking')) . '</p></div>';
        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:12px;margin-top:14px;">';

        // 1. Full Multi-Room Booking Form
        echo '<div style="background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0;">';
        echo '<div style="font-weight:700;color:#0f172a;font-size:13px;display:flex;align-items:center;gap:6px;margin-bottom:4px;"><span style="color:#2563eb;">🔍</span> ' . esc_html(__('1. Full Accommodation Search Form', 'modern-hotel-booking')) . '</div>';
        echo '<p style="margin:0 0 8px 0;font-size:12px;color:#64748b;line-height:1.5;">' . esc_html(__('Searches all available room categories by dates and party size. Best for your primary Booking / Reservations page.', 'modern-hotel-booking')) . '</p>';
        echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;"><span style="font-size:11px;color:#475569;font-weight:600;min-width:68px;">' . esc_html(__('Shortcode:', 'modern-hotel-booking')) . '</span> <code style="user-select:all;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-size:11.5px;font-weight:700;color:#0f172a;">[mhbo_booking_form]</code> <button type="button" class="button button-small" onclick="mhboCopyCode(this, \'[mhbo_booking_form]\');" title="' . esc_attr__('Copy shortcode', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button></div>';
        echo '<div style="display:flex;align-items:center;gap:6px;"><span style="font-size:11px;color:#475569;font-weight:600;min-width:68px;">' . esc_html(__('Block Code:', 'modern-hotel-booking')) . '</span> <code style="user-select:all;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-size:11px;color:#0369a1;">&lt;!-- wp:modern-hotel-booking/booking-form /--&gt;</code> <button type="button" class="button button-small" onclick="mhboCopyCode(this, \'<!-- wp:modern-hotel-booking/booking-form /-->\');" title="' . esc_attr__('Copy block markup', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button></div>';
        echo '</div>';

        // 2. Direct Single-Room Availability & Price Calendar
        echo '<div style="background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0;">';
        echo '<div style="font-weight:700;color:#0f172a;font-size:13px;display:flex;align-items:center;gap:6px;margin-bottom:4px;"><span style="color:#16a34a;">📅</span> ' . esc_html(__('2. Direct Room Availability Calendar', 'modern-hotel-booking')) . '</div>';
        echo '<p style="margin:0 0 8px 0;font-size:12px;color:#64748b;line-height:1.5;">' . esc_html(__('Displays a direct visual calendar for a specific room with daily rates and open/booked dates. Replace X with numeric Room ID.', 'modern-hotel-booking')) . '</p>';
        echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;"><span style="font-size:11px;color:#475569;font-weight:600;min-width:68px;">' . esc_html(__('Shortcode:', 'modern-hotel-booking')) . '</span> <code style="user-select:all;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-size:11.5px;font-weight:700;color:#0f172a;">[mhbo_room_calendar room_id="1"]</code> <button type="button" class="button button-small" onclick="mhboCopyCode(this, \'[mhbo_room_calendar room_id=&quot;1&quot;]\');" title="' . esc_attr__('Copy sample shortcode', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button></div>';
        echo '<div style="display:flex;align-items:center;gap:6px;"><span style="font-size:11px;color:#475569;font-weight:600;min-width:68px;">' . esc_html(__('Block Code:', 'modern-hotel-booking')) . '</span> <code style="user-select:all;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-size:11px;color:#0369a1;">&lt;!-- wp:modern-hotel-booking/room-calendar {&quot;roomId&quot;:1} /--&gt;</code> <button type="button" class="button button-small" onclick="mhboCopyCode(this, \'<!-- wp:modern-hotel-booking/room-calendar {&quot;roomId&quot;:1} /-->\');" title="' . esc_attr__('Copy sample block markup', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button></div>';
        echo '</div>';

        // 3. Guest Self-Service Portal & Balances
        $portal_guide_desc   = __('Guest portal for viewing reservations, arrival cards, Wi-Fi info, and modification requests.', 'modern-hotel-booking');
        $shortcode_dir_count = 11;
        
        $shortcode_dir_label = sprintf(
            /* translators: %d: number of available shortcodes */
            __('View Full %d-Shortcode Directory &rarr;', 'modern-hotel-booking'),
            $shortcode_dir_count
        );
        echo '<div style="background:#f8fafc;padding:12px 14px;border-radius:8px;border:1px solid #e2e8f0;">';
        echo '<div style="font-weight:700;color:#0f172a;font-size:13px;display:flex;align-items:center;gap:6px;margin-bottom:4px;"><span style="color:#9333ea;">🔑</span> ' . esc_html(__('3. Guest Self-Service Workstation', 'modern-hotel-booking')) . '</div>';
        echo '<p style="margin:0 0 8px 0;font-size:12px;color:#64748b;line-height:1.5;">' . esc_html($portal_guide_desc) . '</p>';
        echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;"><span style="font-size:11px;color:#475569;font-weight:600;min-width:68px;">' . esc_html(__('Portal Code:', 'modern-hotel-booking')) . '</span> <code style="user-select:all;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #cbd5e1;font-size:11.5px;font-weight:700;color:#0f172a;">[mhbo_guest_portal]</code> <button type="button" class="button button-small" onclick="mhboCopyCode(this, \'[mhbo_guest_portal]\');" title="' . esc_attr__('Copy portal shortcode', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button></div>';
        echo '<div><a href="' . esc_url(admin_url('admin.php?page=mhbo-settings&tab=business&subtab=shortcodes')) . '" style="text-decoration:underline;color:#2563eb;font-size:11.5px;font-weight:600;">' . esc_html($shortcode_dir_label) . '</a></div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    /**
     * Render the configured rooms reference table (shown after Save Changes).
     */
    public static function render_rooms_table(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rooms = $wpdb->get_results(
            "SELECT r.id, r.room_number, t.name AS type_name
             FROM {$wpdb->prefix}mhbo_rooms r
             LEFT JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
             ORDER BY (r.room_number + 0) ASC, r.room_number ASC, r.id ASC
             LIMIT 100"
        );
        if (is_array($rooms)) {
            usort($rooms, function ( $a, $b ) {
                $cmp = strnatcasecmp( (string) $a->room_number, (string) $b->room_number);
                if (0 !== $cmp) {
                    return $cmp;
                }
                return ( (int) $a->id ) <=> ( (int) $b->id );
            });
        }

        if (is_array($rooms) && array() !== $rooms) {
            echo '<div class="mhbo-settings-section-card" style="margin-top: 24px;">';
            echo '<details style="cursor:pointer;" open>';
            echo '<summary style="font-weight:700;font-size:14.5px;color:#0f172a;display:flex;align-items:center;justify-content:space-between;">'
               . '<div style="display:flex;align-items:center;gap:8px;"><span class="dashicons dashicons-list-view" style="color:#2563eb;"></span> '
               . esc_html(I18n::get_label('setup_guide_your_rooms')) . ' (' . count($rooms) . ')</div>'
               . '<span style="font-size:12px;color:#64748b;font-weight:normal;">' . esc_html(__('Click to expand / collapse numeric Room IDs & embed codes', 'modern-hotel-booking')) . '</span>'
               . '</summary>';
            echo '<p style="margin:10px 0 14px 0;font-size:12.5px;color:#64748b;line-height:1.5;">'
               . esc_html(__('Each physical room below belongs to a parent Room Type and has a unique numeric Room ID. Use the 1-click copy buttons to embed a direct live calendar for any room into WordPress posts, pages, or block layouts.', 'modern-hotel-booking'))
               . '</p>';
            echo '<div class="mhbo-table-responsive">';
            echo '<table class="widefat striped mhbo-setup-guide-table" style="border-radius:6px;border:1px solid #e2e8f0;margin:0;">';
            echo '<thead><tr>';
            echo '<th scope="col" style="width:80px;">' . esc_html(I18n::get_label('setup_guide_col_id')) . '</th>';
            echo '<th scope="col" style="width:140px;">' . esc_html(I18n::get_label('setup_guide_col_name')) . '</th>';
            echo '<th scope="col" style="width:160px;">' . esc_html(I18n::get_label('setup_guide_col_type')) . '</th>';
            echo '<th scope="col">' . esc_html(__('Direct Calendar Shortcode', 'modern-hotel-booking')) . '</th>';
            echo '<th scope="col">' . esc_html(__('Gutenberg Block Code', 'modern-hotel-booking')) . '</th>';
            echo '<th scope="col" style="width:100px;text-align:right;">' . esc_html(__('Action', 'modern-hotel-booking')) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($rooms as $room) {
                $room_id   = (int) $room->id;
                $shortcode = '[mhbo_room_calendar room_id="' . $room_id . '"]';
                $blockcode = '<!-- wp:modern-hotel-booking/room-calendar {"roomId":' . $room_id . '} /-->';
                $edit_url  = wp_nonce_url(
                    add_query_arg(
                        array(
                            'page'   => 'mhbo-rooms',
                            'action' => 'edit',
                            'id'     => $room_id,
                        ),
                        admin_url('admin.php')
                    ),
                    'mhbo_edit_room_' . $room_id
                );
                echo '<tr>';
                echo '<td><span class="mhbo-badge" style="background:#f1f5f9;color:#0f172a;font-weight:700;padding:3px 8px;border-radius:4px;border:1px solid #cbd5e1;font-size:12px;">#' . esc_html( (string) $room_id) . '</span></td>';
                echo '<td><strong>' . esc_html( (string) ( $room->room_number ?? '—' )) . '</strong></td>';
                echo '<td><span style="color:#475569;font-weight:500;">' . esc_html( (string) ( I18n::decode($room->type_name ?? '') ?: '—' )) . '</span></td>';
                echo '<td><div style="display:flex;align-items:center;gap:6px;">';
                echo '<code style="background:#f8fafc;padding:3px 7px;border-radius:4px;border:1px solid #e2e8f0;font-size:12px;user-select:all;color:#0f172a;font-weight:600;">' . esc_html($shortcode) . '</code>';
                echo '<button type="button" class="button button-small" onclick="mhboCopyCode(this, \'' . esc_js($shortcode) . '\');" title="' . esc_attr__('Copy shortcode to clipboard', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button>';
                echo '</div></td>';
                echo '<td><div style="display:flex;align-items:center;gap:6px;">';
                echo '<code style="background:#f8fafc;padding:3px 7px;border-radius:4px;border:1px solid #e2e8f0;font-size:11.5px;user-select:all;color:#0369a1;">' . esc_html($blockcode) . '</code>';
                echo '<button type="button" class="button button-small" onclick="mhboCopyCode(this, \'' . esc_js($blockcode) . '\');" title="' . esc_attr__('Copy block markup to clipboard', 'modern-hotel-booking') . '">' . esc_html__('Copy', 'modern-hotel-booking') . '</button>';
                echo '</div></td>';
                echo '<td style="text-align:right;"><a href="' . esc_url($edit_url) . '" class="button button-small" style="text-decoration:none;">' . esc_html__('Edit Room', 'modern-hotel-booking') . '</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
            echo '</details>';
            echo '</div>';
        } else {
            echo '<div class="mhbo-settings-section-card" style="margin-top: 24px;">';
            echo '<div style="padding: 24px; text-align: center; background: #f8fafc; border-radius: 8px; border: 1.5px dashed #cbd5e1;">';
            echo '<div style="font-size: 32px; line-height: 1; margin-bottom: 8px;">🛏️</div>';
            echo '<h4 style="margin: 0 0 6px 0; font-size: 15px; font-weight: 700; color: #0f172a;">' . esc_html(__('No Physical Rooms Configured Yet', 'modern-hotel-booking')) . '</h4>';
            echo '<p style="margin: 0 0 14px 0; font-size: 12.5px; color: #64748b; max-width: 520px; margin-inline: auto; line-height: 1.5;">' . esc_html(__('To generate direct room booking calendar shortcodes and block embed codes, first define your Room Types (Step 1 above), then create your physical rooms and keys (Step 2).', 'modern-hotel-booking')) . '</p>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=mhbo-rooms&action=add')) . '" class="button button-primary" style="font-weight: 600;">' . esc_html__('+ Add Your First Physical Room', 'modern-hotel-booking') . '</a>';
            echo '</div>';
            echo '</div>';
        }

        echo '<script>
        function mhboCopyCode(btn, code) {
            if (!btn || !code) return;
            var orig = btn.getAttribute("data-orig") || btn.innerText;
            btn.setAttribute("data-orig", orig);
            function done() {
                btn.innerText = "✓ ' . \esc_js(__('Copied!', 'modern-hotel-booking')) . '";
                btn.style.color = "#15803d";
                btn.style.borderColor = "#86efac";
                setTimeout(function() {
                    btn.innerText = orig;
                    btn.style.color = "";
                    btn.style.borderColor = "";
                }, 1500);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(done).catch(fallback);
            } else {
                fallback();
            }
            function fallback() {
                var ta = document.createElement("textarea");
                ta.value = code;
                ta.setAttribute("readonly", "");
                ta.style.position = "fixed";
                ta.style.top = "-9999px";
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand("copy");
                    done();
                } catch (e) {}
                document.body.removeChild(ta);
            }
        }
        </script>';
    }

    /**
     * Render the Modern General Settings Tab (2026 BP).
     *
     * 9 Card-Based Architectural Sections with instant declarative Vanilla JS reactivity.
     */
    public static function render_general_tab(): void {
        // 1. Property & Stay Timing
        $tz_current = (string) get_option('mhbo_hotel_timezone', '');
        $wp_tz      = (string) get_option('timezone_string', '');
        $active_tz  = HotelTime::timezone();
        $ci_time    = (string) get_option('mhbo_checkin_time', '14:00');
        $co_time    = (string) get_option('mhbo_checkout_time', '11:00');
        
        $children_en = (int) get_option('mhbo_children_enabled', 0);
        $global_min  = (int) get_option('mhbo_global_min_stay', 0);
        $global_max  = (int) get_option('mhbo_global_max_stay', 0);

        // 2. Early Check-in & Late Check-out

// 3. Revenue & Stay Optimization

// 4. Booking Flow & Frontend Experience
        $b_page     = (int) get_option('mhbo_booking_page', 0);
        $b_url      = (string) get_option('mhbo_booking_page_url', '');
        $modal_en   = (int) get_option('mhbo_modal_enabled', 1);
        $cal_title_en        = (int) get_option('mhbo_calendar_show_title', 1);
        $cal_badge_en        = (int) get_option('mhbo_calendar_show_badge', 1);
        $book_title_en       = (int) get_option('mhbo_booking_show_title', 1);
        $book_badge_en       = (int) get_option('mhbo_booking_show_badge', 1);
        $rooms_title_en      = (int) get_option('mhbo_rooms_show_title', 1);
        $rooms_badge_en      = (int) get_option('mhbo_rooms_show_badge', 1);
        $room_cards_title_en = (int) get_option('mhbo_room_cards_show_title', 1);
        $room_cards_badge_en = (int) get_option('mhbo_room_cards_show_badge', 1);
        $portal_title_en     = (int) get_option('mhbo_portal_show_title', 1);
        $portal_badge_en     = (int) get_option('mhbo_portal_show_badge', 1);

// 5. Operational Notifications & Housekeeping
        $notif_email   = \MHBO\Core\Email::get_notification_email();
        $cc_email      = (string) get_option('mhbo_additional_notification_email', '');

// 6. Guest CRM & Intelligence
        $crm_en     = (int) get_option('mhbo_guest_crm_enabled', 0);

// 8. Currency & Formatting
        $curr_code  = (string) get_option('mhbo_currency_code', 'USD');
        $curr_sym   = (string) get_option('mhbo_currency_symbol', '$');
        $curr_pos       = (string) get_option('mhbo_currency_position', 'before');
        $price_decimals = (string) get_option('mhbo_price_decimals', '2');
        $decimals       = (int) get_option('mhbo_calendar_show_decimals', 0);
        $powered_by     = (int) get_option('mhbo_powered_by_link', 0);

        // 9. Guest Portal & Digital Compendium
        $wifi_ssid    = (string) get_option('mhbo_hotel_wifi_ssid', '');
        $wifi_pass    = (string) get_option('mhbo_hotel_wifi_password', '');
        $guest_guide  = (string) get_option('mhbo_hotel_guest_instructions', '');
        $portal_page  = (int) get_option('mhbo_guest_portal_page', 0);

// 10. Security & System
        $proxies    = (string) get_option('mhbo_trusted_proxies', '');
        $keep_data  = (int) get_option('mhbo_save_data_on_uninstall', 1);
        ?>

        <?php
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $type_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_room_types");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $room_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_rooms");
        $company_info = class_exists('\MHBO\Business\Info') ? \MHBO\Business\Info::get_company() : array();
        $is_company_set = ! empty($company_info['company_name']);
        $company_display_name = $is_company_set ? (string) $company_info['company_name'] : (string) get_option('mhbo_hotel_name', '');
        $is_booking_page_linked = $b_page > 0;
        ?>

        <!-- Pro Lean Mode: Reopen Pill Wrapper (visible when guide is dismissed) -->
        <div id="mhbo-guide-pill-wrapper" style="display: none; justify-content: flex-end; margin-bottom: 16px;">
            <button type="button" class="button button-secondary" onclick="mhboShowOnboardingGuide();" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; border-radius: 8px; border-color: #cbd5e1; background: #ffffff; color: #0f172a; padding: 6px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); font-size: 13px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.borderColor='#94a3b8'; this.style.boxShadow='0 2px 6px rgba(0,0,0,0.1)';" onmouseout="this.style.borderColor='#cbd5e1'; this.style.boxShadow='0 1px 3px rgba(0,0,0,0.06)';">
                <span style="font-size: 15px;">✨</span>
                <span><?php esc_html_e('Property Setup & Shortcode Guide', 'modern-hotel-booking'); ?></span>
                <span class="mhbo-badge" style="background: #f1f5f9; color: #475569; font-size: 11px; padding: 2px 6px; border-radius: 4px; border: 1px solid #e2e8f0;"><?php esc_html_e('Quick Reference', 'modern-hotel-booking'); ?></span>
            </button>
        </div>

        <!-- Onboarding & Property Setup Guidance Banner -->
        <div class="mhbo-onboarding-welcome-banner" id="mhbo-general-onboarding-banner" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 12px; padding: 24px 28px; margin-bottom: 24px; color: #f8fafc; border: 1px solid #334155; box-shadow: 0 6px 20px rgba(0,0,0,0.15); position: relative;">
            <!-- Top Action Row -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px;">
                <div style="display: flex; gap: 14px; align-items: center;">
                    <span style="font-size: 32px; line-height: 1;">🏨</span>
                    <div>
                        <h3 style="margin: 0; color: #38bdf8; font-size: 1.25rem; font-weight: 700; letter-spacing: -0.01em;">
                            <?php esc_html_e('Modern Hotel Booking — Quick Start & Operational Setup Guide', 'modern-hotel-booking'); ?>
                        </h3>
                        <p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 0.9rem;">
                            <?php esc_html_e('Follow this 4-step roadmap to configure accommodation categories, keys, public calendars, and front desk cashiering.', 'modern-hotel-booking'); ?>
                        </p>
                    </div>
                </div>
                <button type="button" class="button" onclick="mhboDismissOnboardingGuide();" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: #cbd5e1; border-radius: 6px; font-size: 12px; padding: 4px 10px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.18)'; this.style.color='#fff';" onmouseout="this.style.background='rgba(255,255,255,0.08)'; this.style.color='#cbd5e1;'" title="<?php esc_attr_e('Dismiss this guide (Pro Lean Mode)', 'modern-hotel-booking'); ?>">
                    <span>&times;</span> <?php esc_html_e('Dismiss Guide (Lean Mode)', 'modern-hotel-booking'); ?>
                </button>
            </div>

            <!-- Live Readiness Meter Bar -->
            <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 10px 14px; margin-bottom: 20px;">
                <span style="font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-right: 4px;">
                    <?php esc_html_e('Live Setup Status:', 'modern-hotel-booking'); ?>
                </span>
                <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-room-types')); ?>" style="text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: <?php echo $type_count > 0 ? 'rgba(34,197,94,0.15)' : 'rgba(234,179,8,0.15)'; ?>; color: <?php echo $type_count > 0 ? '#4ade80' : '#facc15'; ?>; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid <?php echo $type_count > 0 ? 'rgba(34,197,94,0.3)' : 'rgba(234,179,8,0.3)'; ?>;">
                    📋 1. Room Types: <?php echo $type_count > 0 ? esc_html($type_count . ' Active') : esc_html__('0 Set (Action Needed)', 'modern-hotel-booking'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-rooms')); ?>" style="text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: <?php echo $room_count > 0 ? 'rgba(34,197,94,0.15)' : 'rgba(234,179,8,0.15)'; ?>; color: <?php echo $room_count > 0 ? '#4ade80' : '#facc15'; ?>; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid <?php echo $room_count > 0 ? 'rgba(34,197,94,0.3)' : 'rgba(234,179,8,0.3)'; ?>;">
                    🛏️ 2. Physical Rooms: <?php echo $room_count > 0 ? esc_html($room_count . ' Active') : esc_html__('0 Set (Action Needed)', 'modern-hotel-booking'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business')); ?>" style="text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: <?php echo ! empty($company_display_name) ? 'rgba(34,197,94,0.15)' : 'rgba(234,179,8,0.15)'; ?>; color: <?php echo ! empty($company_display_name) ? '#4ade80' : '#facc15'; ?>; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid <?php echo ! empty($company_display_name) ? 'rgba(34,197,94,0.3)' : 'rgba(234,179,8,0.3)'; ?>;">
                    🏢 3. Business Info: <?php echo ! empty($company_display_name) ? esc_html($company_display_name) : esc_html__('Incomplete', 'modern-hotel-booking'); ?>
                </a>
                <a href="#mhbo_booking_page" style="text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: <?php echo $is_booking_page_linked ? 'rgba(56,189,248,0.15)' : 'rgba(234,179,8,0.15)'; ?>; color: <?php echo $is_booking_page_linked ? '#38bdf8' : '#facc15'; ?>; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid <?php echo $is_booking_page_linked ? 'rgba(56,189,248,0.3)' : 'rgba(234,179,8,0.3)'; ?>;">
                    🚀 4. Booking Page: <?php echo $is_booking_page_linked ? esc_html__('Page Linked', 'modern-hotel-booking') : esc_html__('Select in Card 4', 'modern-hotel-booking'); ?>
                </a>
            </div>

            <!-- 4-Step Action Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
                <!-- Step 1: Room Types First -->
                <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 16px 18px; display: flex; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 700; color: #38bdf8; text-transform: uppercase; letter-spacing: 0.05em;">
                            <?php esc_html_e('Step 1 (First)', 'modern-hotel-booking'); ?>
                        </span>
                        <span class="mhbo-badge" style="background: rgba(56,189,248,0.15); color: #38bdf8; font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 4px;">
                            <?php echo esc_html($type_count . ' Types'); ?>
                        </span>
                    </div>
                    <h4 style="margin: 0 0 6px 0; color: #f8fafc; font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                        <span>📋</span> <?php esc_html_e('Define Room Types', 'modern-hotel-booking'); ?>
                    </h4>
                    <p style="margin: 0 0 12px 0; font-size: 12px; color: #cbd5e1; line-height: 1.55; flex: 1;">
                        <?php esc_html_e('Always create your Room Types first (e.g. Deluxe Suite, Double Room, Villa). Room Types define base prices, guest capacity (standard & max adults/children), extra guest charges, bed setups, photos, and amenities.', 'modern-hotel-booking'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-room-types')); ?>" class="button button-small" style="text-align: center; font-weight: 600; text-decoration: none; border-radius: 6px; padding: 4px 10px; background: #0284c7; color: #fff; border-color: #0284c7;">
                        <?php esc_html_e('Manage Room Types &rarr;', 'modern-hotel-booking'); ?>
                    </a>
                </div>

                <!-- Step 2: Physical Rooms Second -->
                <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 16px 18px; display: flex; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 700; color: #c084fc; text-transform: uppercase; letter-spacing: 0.05em;">
                            <?php esc_html_e('Step 2 (Second)', 'modern-hotel-booking'); ?>
                        </span>
                        <span class="mhbo-badge" style="background: rgba(168,85,247,0.15); color: #c084fc; font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 4px;">
                            <?php echo esc_html($room_count . ' Rooms'); ?>
                        </span>
                    </div>
                    <h4 style="margin: 0 0 6px 0; color: #f8fafc; font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                        <span>🛏️</span> <?php esc_html_e('Add Physical Rooms', 'modern-hotel-booking'); ?>
                    </h4>
                    <p style="margin: 0 0 12px 0; font-size: 12px; color: #cbd5e1; line-height: 1.55; flex: 1;">
                        <?php esc_html_e('Create physical units/keys (e.g. Room 101, Villa 4) and assign each to its parent Room Type. Every room receives a unique numeric Room ID (e.g. #1, #2), visible in the expandable "Your Configured Rooms" section below.', 'modern-hotel-booking'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-rooms')); ?>" class="button button-small" style="text-align: center; font-weight: 600; text-decoration: none; border-radius: 6px; padding: 4px 10px; background: #7c3aed; color: #fff; border-color: #7c3aed;">
                        <?php esc_html_e('Manage Physical Rooms &rarr;', 'modern-hotel-booking'); ?>
                    </a>
                </div>

                <!-- Step 3: Publish Your Booking Flow -->
                <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 16px 18px; display: flex; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 700; color: #4ade80; text-transform: uppercase; letter-spacing: 0.05em;">
                            <?php esc_html_e('Step 3 (Publishing)', 'modern-hotel-booking'); ?>
                        </span>
                        <span class="mhbo-badge" style="background: rgba(34,197,94,0.15); color: #4ade80; font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 4px;">
                            <?php esc_html_e('Blocks & Codes', 'modern-hotel-booking'); ?>
                        </span>
                    </div>
                    <h4 style="margin: 0 0 6px 0; color: #f8fafc; font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                        <span>🚀</span> <?php esc_html_e('Publish Booking Calendars', 'modern-hotel-booking'); ?>
                    </h4>
                    <div style="margin: 0 0 12px 0; font-size: 12px; color: #cbd5e1; line-height: 1.55; flex: 1;">
                        <div style="margin-bottom: 6px;">
                            <strong style="color: #38bdf8;">• Full Search Form:</strong> <code>[mhbo_booking_form]</code> or block <code>booking-form</code> searches all room types. Place on your main Booking Page.
                        </div>
                        <div style="margin-bottom: 6px;">
                            <strong style="color: #4ade80;">• Direct Room Calendar:</strong> <code>[mhbo_room_calendar room_id="X"]</code> or block <code>room-calendar</code> embeds daily rates & dates for a single room.
                        </div>
                        <div>
                            <strong style="color: #fbbf24;">• Modal vs Page:</strong> In Card 4 below, choose slide-in drawer (zero reload) or redirecting to your Booking Page.
                        </div>
                    </div>
                    <a href="#mhbo_booking_page" class="button button-small" style="text-align: center; font-weight: 600; text-decoration: none; border-radius: 6px; padding: 4px 10px; background: #16a34a; color: #fff; border-color: #16a34a;">
                        <?php esc_html_e('Configure Booking Flow &darr;', 'modern-hotel-booking'); ?>
                    </a>
                </div>

                <!-- Step 4: Front Desk & Manual Bookings -->
                <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 16px 18px; display: flex; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 700; color: #fbbf24; text-transform: uppercase; letter-spacing: 0.05em;">
                            <?php esc_html_e('Step 4 (Operations)', 'modern-hotel-booking'); ?>
                        </span>
                        <span class="mhbo-badge" style="background: rgba(251,191,36,0.15); color: #fbbf24; font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 4px;">
                            <?php esc_html_e('PMS Workstation', 'modern-hotel-booking'); ?>
                        </span>
                    </div>
                    <h4 style="margin: 0 0 6px 0; color: #f8fafc; font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 6px;">
                        <span>🛎️</span> <?php esc_html_e('Front Desk & Cashiering', 'modern-hotel-booking'); ?>
                    </h4>
                    <p style="margin: 0 0 12px 0; font-size: 12px; color: #cbd5e1; line-height: 1.55; flex: 1;">
                        <?php esc_html_e('For walk-ins or phone reservations, go to Bookings > Add New Booking. CRM Guest Quick-Fill auto-completes repeat guests with VIP/caution notes. Price breakdowns calculate live, and 1-click tender shortcuts record Cash, Card Terminal, or Bank payments instantly.', 'modern-hotel-booking'); ?>
                    </p>
                    <div style="display: flex; gap: 8px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-bookings&action=add')); ?>" class="button button-small" style="flex: 1; text-align: center; font-weight: 600; text-decoration: none; border-radius: 6px; padding: 4px 6px; background: #d97706; color: #fff; border-color: #d97706;">
                            <?php esc_html_e('Add Booking &rarr;', 'modern-hotel-booking'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-bookings')); ?>" class="button button-small" style="text-align: center; font-weight: 600; text-decoration: none; border-radius: 6px; padding: 4px 8px; background: rgba(255,255,255,0.1); color: #cbd5e1; border-color: rgba(255,255,255,0.2);">
                            <?php esc_html_e('All Bookings', 'modern-hotel-booking'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function mhboSafeStorage() {
            try {
                if (typeof window !== 'undefined' && 'localStorage' in window && window.localStorage !== null) {
                    return window.localStorage;
                }
            } catch (e) {}
            return null;
        }
        function mhboDismissOnboardingGuide() {
            var banner = document.getElementById('mhbo-general-onboarding-banner');
            var pill   = document.getElementById('mhbo-guide-pill-wrapper');
            if (banner) banner.style.display = 'none';
            if (pill) pill.style.display = 'flex';
            var storage = mhboSafeStorage();
            if (storage) {
                try {
                    storage.setItem('mhbo_general_onboarding_dismissed', '1');
                } catch (e) {}
            }
        }
        function mhboShowOnboardingGuide() {
            var banner = document.getElementById('mhbo-general-onboarding-banner');
            var pill   = document.getElementById('mhbo-guide-pill-wrapper');
            if (banner) banner.style.display = 'block';
            if (pill) pill.style.display = 'none';
            var storage = mhboSafeStorage();
            if (storage) {
                try {
                    storage.removeItem('mhbo_general_onboarding_dismissed');
                } catch (e) {}
            }
        }
        (function() {
            var storage = mhboSafeStorage();
            if (storage) {
                try {
                    if (storage.getItem('mhbo_general_onboarding_dismissed') === '1') {
                        var banner = document.getElementById('mhbo-general-onboarding-banner');
                        var pill   = document.getElementById('mhbo-guide-pill-wrapper');
                        if (banner) banner.style.display = 'none';
                        if (pill) pill.style.display = 'flex';
                    }
                } catch (e) {}
            }
        })();
        </script>

        <div class="mhbo-settings-card-container">

            <!-- Card 1: Property & Stay Timing -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-building"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(__('Property Stay Times & Timezone', 'modern-hotel-booking')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Define standard arrival/departure times, your property operational timezone, and booking limits.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group mhbo-span-full">
                        <label class="mhbo-form-label" for="mhbo_hotel_timezone">
                            <?php echo esc_html(__('Hotel Operational Timezone', 'modern-hotel-booking')); ?>
                        </label>
                        <select id="mhbo_hotel_timezone" name="mhbo_hotel_timezone" class="mhbo-form-input">
                            <option value="" <?php selected($tz_current, '', true); ?>>
                                <?php
                                /* translators: %s: WordPress site timezone string (e.g. UTC, Europe/London) */
                                echo esc_html(sprintf(__('— Same as WordPress site (%s) —', 'modern-hotel-booking'), (string) $wp_tz ?: 'UTC'));
                                ?>
                            </option>
                            <?php echo wp_timezone_choice($tz_current ?: $wp_tz, get_user_locale()); ?>
                        </select>
                        <div class="mhbo-tz-live-badge">
                            <span class="mhbo-tz-live-pulse"></span>
                            <span><?php echo esc_html(__('Active Hotel Time:', 'modern-hotel-booking')); ?> <strong class="mhbo-tz-live-time" data-tz="<?php echo esc_attr($active_tz->getName()); ?>"><?php echo esc_html(HotelTime::now()->format('H:i:s T')); ?></strong></span>
                        </div>
                        <p class="mhbo-form-help"><?php echo esc_html(__('All calendar dates, pricing rules, email timestamps, housekeeping manifests, and check-in workflows operate in this timezone.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_checkin_time"><?php echo esc_html(I18n::get_label('settings_label_checkin')); ?></label>
                        <input type="time" id="mhbo_checkin_time" name="mhbo_checkin_time" value="<?php echo esc_attr(HotelTime::sanitize_hhmm($ci_time, '14:00')); ?>" class="mhbo-form-input" placeholder="14:00">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Standard guest check-in arrival time (24-hour HH:MM format).', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_checkout_time"><?php echo esc_html(I18n::get_label('settings_label_checkout')); ?></label>
                        <input type="time" id="mhbo_checkout_time" name="mhbo_checkout_time" value="<?php echo esc_attr(HotelTime::sanitize_hhmm($co_time, '11:00')); ?>" class="mhbo-form-input" placeholder="11:00">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Standard guest check-out departure time (24-hour HH:MM format).', 'modern-hotel-booking')); ?></p>
                    </div>

<div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_global_min_stay"><?php echo esc_html(I18n::get_label('settings_label_global_min_nights')); ?></label>
                        <input type="number" id="mhbo_global_min_stay" name="mhbo_global_min_stay" value="<?php echo esc_attr( (string) $global_min); ?>" min="0" max="365" class="mhbo-form-input" placeholder="0">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Global minimum length of stay across all rooms (0 to disable).', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_global_max_stay"><?php echo esc_html(I18n::get_label('settings_label_global_max_nights')); ?></label>
                        <input type="number" id="mhbo_global_max_stay" name="mhbo_global_max_stay" value="<?php echo esc_attr( (string) $global_max); ?>" min="0" max="365" class="mhbo-form-input" placeholder="0">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Global maximum length of stay across all rooms (0 to disable).', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group mhbo-span-full">
                        <div class="mhbo-switch-row">
                            <div class="mhbo-switch-info">
                                <span class="mhbo-switch-title"><?php echo esc_html(I18n::get_label('settings_label_children')); ?></span>
                                <p class="mhbo-switch-desc"><?php echo esc_html(I18n::get_label('settings_desc_children')); ?></p>
                            </div>
                            <label class="mhbo-toggle-switch">
                                <input type="hidden" name="mhbo_children_enabled" value="0">
                                <input type="checkbox" id="mhbo_children_enabled" name="mhbo_children_enabled" value="1" <?php checked(1, $children_en); ?>>
                                <span class="mhbo-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

<!-- Card 4: Booking Flow & Frontend Experience -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-desktop"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(__('Booking Flow & Frontend Experience', 'modern-hotel-booking')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Manage public booking page routing, drawer modal presentation, and post-booking actions.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_booking_page"><?php echo esc_html(I18n::get_label('settings_label_booking_page')); ?></label>
                        <?php
                        $b_page_dropdown = wp_dropdown_pages(array(
                            'name'             => 'mhbo_booking_page',
                            'id'               => 'mhbo_booking_page',
                            'selected'         => absint($b_page),
                            'show_option_none' => esc_html(I18n::get_label('settings_opt_none_page')),
                            'class'            => 'mhbo-form-input',
                            'echo'             => 0,
                        ));
                        echo wp_kses(
                            (string) $b_page_dropdown,
                            array(
                                'select' => array(
                                    'name'  => true,
                                    'id'    => true,
                                    'class' => true,
                                ),
                                'option' => array(
                                    'value'    => true,
                                    'selected' => true,
                                    'class'    => true,
                                ),
                            )
                        );
                        ?>
                        <p class="mhbo-form-help"><?php echo esc_html(I18n::get_label('settings_desc_booking_shortcode')); ?></p>
                        <?php
                        $booking_page_post = $b_page > 0 ? get_post($b_page) : null;
                        $has_booking_shortcode = false;
                        if ($booking_page_post instanceof \WP_Post) {
                            $b_content = (string) $booking_page_post->post_content;
                            $has_booking_shortcode = has_shortcode($b_content, 'mhbo_booking_form') || has_shortcode($b_content, 'modern_hotel_booking') || has_block('modern-hotel-booking/booking-form', $b_content);
                        }
                        ?>
                        <?php if ($b_page > 0) : ?>
                            <?php if ($has_booking_shortcode) : ?>
                                <div style="margin-top: 6px; font-size: 11.5px; color: #16a34a; font-weight: 600; display: flex; align-items: center; gap: 4px;">
                                    <span>✓</span> <?php esc_html_e('Booking search shortcode [mhbo_booking_form] or Modern Hotel Booking block detected on this page.', 'modern-hotel-booking'); ?>
                                </div>
                            <?php else : ?>
                                <div style="margin-top: 6px; font-size: 11.5px; color: #d97706; font-weight: 500; display: flex; align-items: center; gap: 4px;">
                                    <span>⚠️</span> <?php esc_html_e('Notice: Selected page does not contain [mhbo_booking_form] or the Modern Hotel Booking block yet.', 'modern-hotel-booking'); ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_booking_page_url"><?php echo esc_html(I18n::get_label('settings_label_booking_override')); ?></label>
                        <input type="text" id="mhbo_booking_page_url" name="mhbo_booking_page_url" value="<?php echo esc_attr($b_url); ?>" class="mhbo-form-input" placeholder="e.g. /reservations or https://yoursite.com/book">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Optional. Overrides the standard booking page selection with a custom relative path or full URL.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group mhbo-span-full">
                        <div class="mhbo-switch-row">
                            <div class="mhbo-switch-info">
                                <span class="mhbo-switch-title"><?php echo esc_html(__('Enable Inline Booking Modal Drawer', 'modern-hotel-booking')); ?></span>
                                <p class="mhbo-switch-desc"><?php echo esc_html(__('Open the booking flow in an interactive slide-in drawer instead of navigating away.', 'modern-hotel-booking')); ?></p>
                            </div>
                            <label class="mhbo-toggle-switch">
                                <input type="hidden" name="mhbo_modal_enabled" value="0">
                                <input type="checkbox" id="mhbo_modal_enabled" name="mhbo_modal_enabled" value="1" <?php checked(1, $modal_en); ?>>
                                <span class="mhbo-toggle-slider"></span>
                            </label>
                        </div>
                        <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-top: 10px; font-size: 12px; color: #475569; line-height: 1.5;">
                            <div style="font-weight: 700; color: #0f172a; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                                <span>💡</span> <?php esc_html_e('How Booking Presentation Works (Modal Drawer vs. Page Redirect)', 'modern-hotel-booking'); ?>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 10px;">
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid <?php echo $modal_en ? '#93c5fd' : '#e2e8f0'; ?>;">
                                    <div style="font-weight: 700; color: #2563eb; margin-bottom: 3px;">
                                        <?php esc_html_e('1. Inline Modal Drawer (Active when toggled ON)', 'modern-hotel-booking'); ?>
                                    </div>
                                    <p style="margin: 0; font-size: 11.5px; color: #64748b; line-height: 1.5;">
                                        <?php esc_html_e('When guests select dates on a direct room calendar ([mhbo_room_calendar]) or click "Book Now", a slide-out drawer opens smoothly on the current screen. Guests complete reservations without ever leaving the page. Ideal for modern conversion rates.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid <?php echo ! $modal_en ? '#93c5fd' : '#e2e8f0'; ?>;">
                                    <div style="font-weight: 700; color: #475569; margin-bottom: 3px;">
                                        <?php esc_html_e('2. Dedicated Page Redirect (Active when toggled OFF)', 'modern-hotel-booking'); ?>
                                    </div>
                                    <p style="margin: 0; font-size: 11.5px; color: #64748b; line-height: 1.5;">
                                        <?php esc_html_e('When dates are chosen on a room calendar, guests are redirected to your primary Booking Page (set above) with dates pre-filled into the full accommodation search form ([mhbo_booking_form]). Ideal for traditional multi-step checkout funnels.', 'modern-hotel-booking'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Frontend Titles & Badges Visibility Matrix -->
                    <div class="mhbo-form-group mhbo-span-full" style="margin-top: 8px; padding-top: 16px; border-top: 1px dashed #e2e8f0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                            <div>
                                <span class="mhbo-switch-title" style="display: flex; align-items: center; gap: 6px;">
                                    <span class="dashicons dashicons-visibility" style="color: #2563eb; font-size: 18px; width: 18px; height: 18px;"></span>
                                    <?php esc_html_e('Frontend Titles & Badges Visibility (Global Visual Controls)', 'modern-hotel-booking'); ?>
                                </span>
                                <p class="mhbo-switch-desc" style="margin-top: 2px;">
                                    <?php esc_html_e('Enable or disable header titles and pill badges across all frontend shortcodes and Gutenberg blocks. Individual room categories and physical rooms also have their own override checkboxes.', 'modern-hotel-booking'); ?>
                                </p>
                            </div>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-room-types')); ?>" class="button button-small" style="font-weight: 600;">
                                    <?php esc_html_e('Per-Category Checkboxes &rarr;', 'modern-hotel-booking'); ?>
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-rooms')); ?>" class="button button-small" style="font-weight: 600;">
                                    <?php esc_html_e('Per-Room Checkboxes &rarr;', 'modern-hotel-booking'); ?>
                                </a>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px;">
                            <!-- 1. Single Room Calendars -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                                <div style="font-weight: 700; color: #0f172a; font-size: 13px; margin-bottom: 4px;">
                                    📅 <?php esc_html_e('Room Calendars ([mhbo_room_calendar])', 'modern-hotel-booking'); ?>
                                </div>
                                <p style="margin: 0 0 10px 0; font-size: 11.5px; color: #64748b;">
                                    <?php esc_html_e('Controls the room type title and room number badge on single-room calendar pages (e.g. /room-1/).', 'modern-hotel-booking'); ?>
                                </p>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #1e293b; cursor: pointer;">
                                        <input type="hidden" name="mhbo_calendar_show_title" value="0">
                                        <input type="checkbox" id="mhbo_calendar_show_title" name="mhbo_calendar_show_title" value="1" <?php checked(1, $cal_title_en); ?>>
                                        <span><?php esc_html_e('Show Room Title Heading', 'modern-hotel-booking'); ?></span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #1e293b; cursor: pointer;">
                                        <input type="hidden" name="mhbo_calendar_show_badge" value="0">
                                        <input type="checkbox" id="mhbo_calendar_show_badge" name="mhbo_calendar_show_badge" value="1" <?php checked(1, $cal_badge_en); ?>>
                                        <span><?php esc_html_e('Show Room Number Badge', 'modern-hotel-booking'); ?></span>
                                    </label>
                                </div>
                            </div>

                            <!-- 2. Main Booking Page -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                                <div style="font-weight: 700; color: #0f172a; font-size: 13px; margin-bottom: 4px;">
                                    🔍 <?php esc_html_e('Booking Page ([mhbo_booking_form])', 'modern-hotel-booking'); ?>
                                </div>
                                <p style="margin: 0 0 10px 0; font-size: 11.5px; color: #64748b;">
                                    <?php esc_html_e('Controls the header title and badge above the main multi-room booking search calendar (e.g. /booking/).', 'modern-hotel-booking'); ?>
                                </p>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #1e293b; cursor: pointer;">
                                        <input type="hidden" name="mhbo_booking_show_title" value="0">
                                        <input type="checkbox" id="mhbo_booking_show_title" name="mhbo_booking_show_title" value="1" <?php checked(1, $book_title_en); ?>>
                                        <span><?php esc_html_e('Show Booking Page Title', 'modern-hotel-booking'); ?></span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #1e293b; cursor: pointer;">
                                        <input type="hidden" name="mhbo_booking_show_badge" value="0">
                                        <input type="checkbox" id="mhbo_booking_show_badge" name="mhbo_booking_show_badge" value="1" <?php checked(1, $book_badge_en); ?>>
                                        <span><?php esc_html_e('Show Booking Page Badge', 'modern-hotel-booking'); ?></span>
                                    </label>
                                </div>
                            </div>

                            <!-- 3. Rooms Showcase Grid -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                                <div style="font-weight: 700; color: #0f172a; font-size: 13px; margin-bottom: 4px;">
                                    🛏️ <?php esc_html_e('Rooms Grid ([mhbo_rooms])', 'modern-hotel-booking'); ?>
                                </div>
                                <p style="margin: 0 0 10px 0; font-size: 11.5px; color: #64748b;">
                                    <?php esc_html_e('Controls section header and individual room card titles & capacity badges (e.g. /rooms/).', 'modern-hotel-booking'); ?>
                                </p>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #1e293b; cursor: pointer;">
                                        <input type="hidden" name="mhbo_rooms_show_title" value="0">
                                        <input type="checkbox" id="mhbo_rooms_show_title" name="mhbo_rooms_show_title" value="1" <?php checked(1, $rooms_title_en); ?>>
                                        <span><?php esc_html_e('Show Grid Section Title', 'modern-hotel-booking'); ?></span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #1e293b; cursor: pointer;">
                                        <input type="hidden" name="mhbo_rooms_show_badge" value="0">
                                        <input type="checkbox" id="mhbo_rooms_show_badge" name="mhbo_rooms_show_badge" value="1" <?php checked(1, $rooms_badge_en); ?>>
                                        <span><?php esc_html_e('Show Grid Section Badge', 'modern-hotel-booking'); ?></span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #1e293b; cursor: pointer;">
                                        <input type="hidden" name="mhbo_room_cards_show_title" value="0">
                                        <input type="checkbox" id="mhbo_room_cards_show_title" name="mhbo_room_cards_show_title" value="1" <?php checked(1, $room_cards_title_en); ?>>
                                        <span><?php esc_html_e('Show Individual Card Titles', 'modern-hotel-booking'); ?></span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #1e293b; cursor: pointer;">
                                        <input type="hidden" name="mhbo_room_cards_show_badge" value="0">
                                        <input type="checkbox" id="mhbo_room_cards_show_badge" name="mhbo_room_cards_show_badge" value="1" <?php checked(1, $room_cards_badge_en); ?>>
                                        <span><?php esc_html_e('Show Card Capacity Badges', 'modern-hotel-booking'); ?></span>
                                    </label>
                                </div>
                            </div>

                            <!-- 4. Guest Portal & Balance Payment Pages -->
                            <?php
                            $portal_pay_card_title = __('Guest Portal Page', 'modern-hotel-booking');
                            $portal_pay_card_desc  = __('Controls header titles and badges on [mhbo_guest_portal].', 'modern-hotel-booking');
                            
                            ?>
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                                <div style="font-weight: 700; color: #0f172a; font-size: 13px; margin-bottom: 4px;">
                                    🔑 <?php echo esc_html($portal_pay_card_title); ?>
                                </div>
                                <p style="margin: 0 0 10px 0; font-size: 11.5px; color: #64748b;">
                                    <?php echo esc_html($portal_pay_card_desc); ?>
                                </p>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #1e293b; cursor: pointer;">
                                        <input type="hidden" name="mhbo_portal_show_title" value="0">
                                        <input type="checkbox" id="mhbo_portal_show_title" name="mhbo_portal_show_title" value="1" <?php checked(1, $portal_title_en); ?>>
                                        <span><?php esc_html_e('Show Guest Portal Title', 'modern-hotel-booking'); ?></span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #1e293b; cursor: pointer;">
                                        <input type="hidden" name="mhbo_portal_show_badge" value="0">
                                        <input type="checkbox" id="mhbo_portal_show_badge" name="mhbo_portal_show_badge" value="1" <?php checked(1, $portal_badge_en); ?>>
                                        <span><?php esc_html_e('Show Guest Portal Badge', 'modern-hotel-booking'); ?></span>
                                    </label>
                                    
                                </div>
                            </div>
                        </div>
                    </div>

</div>
            </div>

            <!-- Card 5: Operational Notifications & Housekeeping -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-email-alt"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title">
                                <?php echo esc_html(__('Operational Notifications & Housekeeping', 'modern-hotel-booking')); ?>
                            </h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Admin notification inboxes and daily housekeeping turnover manifest automation.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_notification_email"><?php echo esc_html(I18n::get_label('settings_label_notification')); ?></label>
                        <input type="email" id="mhbo_notification_email" name="mhbo_notification_email" value="<?php echo esc_attr($notif_email); ?>" class="mhbo-form-input" placeholder="admin@example.com">
                        <p class="mhbo-form-help">
                            <?php echo esc_html(__('Primary admin inbox for new booking alerts and reservation updates.', 'modern-hotel-booking')); ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=emails#mhbo-email-health-card')); ?>" style="display: inline-block; margin-left: 6px; font-weight: 600; text-decoration: none; color: #2563eb;">
                                <?php esc_html_e('Email Health Inspector & Test Probe →', 'modern-hotel-booking'); ?>
                            </a>
                        </p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_additional_notification_email"><?php echo esc_html(__('Additional Notification Email (CC)', 'modern-hotel-booking')); ?></label>
                        <input type="text" id="mhbo_additional_notification_email" name="mhbo_additional_notification_email" value="<?php echo esc_attr($cc_email); ?>" class="mhbo-form-input" placeholder="manager@example.com, accountant@example.com">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Optional secondary email address(es) copied on every admin booking alert. Multiple addresses can be separated by commas.', 'modern-hotel-booking')); ?></p>
                    </div>

</div>
            </div>

            <!-- Card 6: Guest CRM & Intelligence -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-admin-users"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(__('Guest CRM & Intelligence', 'modern-hotel-booking')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Guest profile unification, lifetime stay history, staff notes, and VIP thresholds.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group mhbo-span-full">
                        <div class="mhbo-switch-row">
                            <div class="mhbo-switch-info">
                                <span class="mhbo-switch-title"><?php echo esc_html(__('Enable Guest CRM & Profiles', 'modern-hotel-booking')); ?></span>
                                <p class="mhbo-switch-desc"><?php echo esc_html(__('Centralize guest profiles, lifetime stay metrics, staff ratings, and customer intelligence.', 'modern-hotel-booking')); ?></p>
                            </div>
                            <label class="mhbo-toggle-switch">
                                <input type="hidden" name="mhbo_guest_crm_enabled" value="0">
                                <input type="checkbox" id="mhbo_guest_crm_enabled" name="mhbo_guest_crm_enabled" value="1" <?php checked(1, $crm_en); ?>>
                                <span class="mhbo-toggle-slider"></span>
                            </label>
                        </div>

</div>

</div>
            </div>

            <!-- Card 7: Custom Guest Checkout Fields -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-forms"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(I18n::get_label('settings_label_custom_fields')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Add extra required or optional fields to the booking checkout form.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div>
                    <?php Settings::render_custom_fields_repeater(array('label_for' => 'mhbo_custom_fields')); ?>
                </div>
            </div>

            <!-- Card 8: Currency & Display Formatting -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-money-alt"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(I18n::get_label('settings_section_currency')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Configure the property display currency, symbol format, and price decimals.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_currency_code"><?php echo esc_html(I18n::get_label('settings_label_currency_code')); ?></label>
                        <input type="text" id="mhbo_currency_code" name="mhbo_currency_code" value="<?php echo esc_attr($curr_code); ?>" class="mhbo-form-input" maxlength="3" style="text-transform: uppercase;">
                        <p class="mhbo-form-help"><?php echo esc_html(__('3-letter ISO code (e.g. USD, EUR, GBP, RON).', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_currency_symbol"><?php echo esc_html(I18n::get_label('settings_label_currency_symbol')); ?></label>
                        <input type="text" id="mhbo_currency_symbol" name="mhbo_currency_symbol" value="<?php echo esc_attr($curr_sym); ?>" class="mhbo-form-input">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Symbol displayed next to rates (e.g. $, €, £, lei).', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_currency_position"><?php echo esc_html(I18n::get_label('settings_label_currency_pos')); ?></label>
                        <select id="mhbo_currency_position" name="mhbo_currency_position" class="mhbo-form-input">
                            <option value="before" <?php selected($curr_pos, 'before'); ?>><?php echo esc_html(I18n::get_label('settings_opt_before')); ?></option>
                            <option value="after" <?php selected($curr_pos, 'after'); ?>><?php echo esc_html(I18n::get_label('settings_opt_after')); ?></option>
                        </select>
                        <p class="mhbo-form-help"><?php echo esc_html(__('Position of currency symbol relative to amount.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_price_decimals"><?php echo esc_html(__('Price Decimals (Display & Gateway Totals)', 'modern-hotel-booking')); ?></label>
                        <select id="mhbo_price_decimals" name="mhbo_price_decimals" class="mhbo-form-input">
                            <option value="2" <?php selected($price_decimals, '2'); ?>><?php echo esc_html(__('2 Decimal Places (Standard — e.g. $120.00)', 'modern-hotel-booking')); ?></option>
                            <option value="0" <?php selected($price_decimals, '0'); ?>><?php echo esc_html(__('0 Decimal Places (Whole numbers / JPY — e.g. ¥12,000)', 'modern-hotel-booking')); ?></option>
                            <option value="3" <?php selected($price_decimals, '3'); ?>><?php echo esc_html(__('3 Decimal Places (e.g. KWD / BHD 12.500)', 'modern-hotel-booking')); ?></option>
                            <option value="4" <?php selected($price_decimals, '4'); ?>><?php echo esc_html(__('4 Decimal Places (Accounting / Micro-rates)', 'modern-hotel-booking')); ?></option>
                            <option value="auto" <?php selected($price_decimals, 'auto'); ?>><?php echo esc_html(__('Automatic (Based on ISO 4217 Currency Code)', 'modern-hotel-booking')); ?></option>
                        </select>
                        <p class="mhbo-form-help"><?php echo esc_html(__('Standard precision used across booking checkout, totals, deposits, taxes, emails, and invoices. Defaults to 2 decimals for exact cent matching.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group mhbo-span-full">
                        <div class="mhbo-switch-row">
                            <div class="mhbo-switch-info">
                                <span class="mhbo-switch-title"><?php echo esc_html(__('Show Decimals on Calendar Date Cells', 'modern-hotel-booking')); ?></span>
                                <p class="mhbo-switch-desc"><?php echo esc_html(__('When disabled (default), daily price labels inside calendar date cells are shown as uncluttered whole numbers. When enabled, date cells display full decimals according to the setting above.', 'modern-hotel-booking')); ?></p>
                            </div>
                            <label class="mhbo-toggle-switch">
                                <input type="hidden" name="mhbo_calendar_show_decimals" value="0">
                                <input type="checkbox" id="mhbo_calendar_show_decimals" name="mhbo_calendar_show_decimals" value="1" <?php checked(1, $decimals); ?>>
                                <span class="mhbo-toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="mhbo-form-group mhbo-span-full">
                        <div class="mhbo-switch-row">
                            <div class="mhbo-switch-info">
                                <span class="mhbo-switch-title"><?php echo esc_html(I18n::get_label('settings_label_powered_by')); ?></span>
                                <p class="mhbo-switch-desc"><?php echo esc_html(I18n::get_label('settings_desc_powered_by')); ?></p>
                            </div>
                            <label class="mhbo-toggle-switch">
                                <input type="hidden" name="mhbo_powered_by_link" value="0">
                                <input type="checkbox" id="mhbo_powered_by_link" name="mhbo_powered_by_link" value="1" <?php checked(1, $powered_by); ?>>
                                <span class="mhbo-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 9: Guest Portal & Digital Compendium -->
            <div class="mhbo-settings-section-card" id="mhbo-guest-portal-section">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-welcome-learn-more"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(__('Guest Portal & Digital Compendium', 'modern-hotel-booking')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Configure your guest self-service portal, property Wi-Fi access, and house rules. Guests access the portal to review stay details, copy Wi-Fi passwords, request stay changes, and make balance payments.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group mhbo-span-full">
                        <label class="mhbo-form-label" for="mhbo_guest_portal_page">
                            <?php echo esc_html(__('Guest Self-Service Portal Page', 'modern-hotel-booking')); ?>
                        </label>
                        <select name="mhbo_guest_portal_page" id="mhbo_guest_portal_page" class="mhbo-form-input">
                            <option value="0"><?php echo esc_html__('— Select WordPress Page hosting [mhbo_guest_portal] —', 'modern-hotel-booking'); ?></option>
                            <?php
                            $wp_pages            = get_pages();
                            $selected_portal_obj = null;
                            if ( is_array( $wp_pages ) ) {
                                foreach ( $wp_pages as $p ) {
                                    if ( (int) $p->ID === $portal_page ) {
                                        $selected_portal_obj = $p;
                                    }
                                    printf(
                                        '<option value="%d" %s>%s</option>',
                                        esc_attr( (string) $p->ID ),
                                        selected( $portal_page, $p->ID, false ),
                                        esc_html( $p->post_title )
                                    );
                                }
                            }
                            ?>
                        </select>
                        <p class="mhbo-form-help">
                            <?php esc_html_e('Select the page hosting the [mhbo_guest_portal] shortcode. All booking confirmation emails automatically generate verified 1-click access links for guests to manage their reservation.', 'modern-hotel-booking'); ?>
                            <?php if ( $selected_portal_obj ) : ?>
                                <?php $has_portal_sc = str_contains( (string) $selected_portal_obj->post_content, '[mhbo_guest_portal]' ); ?>
                                <span style="display: block; margin-top: 6px;">
                                    <?php if ( $has_portal_sc ) : ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; color: #166534; background: #dcfce7; padding: 2px 8px; border-radius: 9999px;">✓ <?php esc_html_e( 'Shortcode [mhbo_guest_portal] active', 'modern-hotel-booking' ); ?></span>
                                        <a href="<?php echo esc_url( (string) get_permalink( $selected_portal_obj->ID ) ); ?>" target="_blank" rel="noopener noreferrer" style="font-size: 12px; margin-left: 6px; text-decoration: underline; color: #2563eb;"><?php esc_html_e( 'View Portal Page &rarr;', 'modern-hotel-booking' ); ?></a>
                                    <?php else : ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; color: #b45309; background: #fef3c7; padding: 2px 8px; border-radius: 9999px;">⚠️ <?php esc_html_e( '[mhbo_guest_portal] shortcode not detected on this page', 'modern-hotel-booking' ); ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php else : ?>
                                <span style="display: block; margin-top: 6px; color: #d97706; font-size: 12px; font-weight: 500;">
                                    ℹ️ <?php esc_html_e('To enable guest self-service, create a page (e.g. "My Booking" or "Guest Portal"), insert the [mhbo_guest_portal] shortcode, and select it above.', 'modern-hotel-booking'); ?>
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>

<!-- Connected Property Data & Experience Readiness Checklist -->
                    <div class="mhbo-form-group mhbo-span-full">
                        <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 18px 20px;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                                <span style="font-size: 18px;">✨</span>
                                <strong style="font-size: 14px; color: #0f172a;"><?php esc_html_e('Connected Property Data & Arrival Experience', 'modern-hotel-booking'); ?></strong>
                            </div>
                            <p style="margin: 0 0 14px 0; font-size: 12.5px; color: #64748b; line-height: 1.5;">
                                <?php esc_html_e('The Guest Self-Service Portal dynamically synthesizes essential details configured across your settings to create a seamless digital arrival card. When guests access their portal or read confirmation emails, these fields are automatically displayed:', 'modern-hotel-booking'); ?>
                            </p>
                            <?php
                            $company_data = class_exists('\MHBO\Business\Info') ? \MHBO\Business\Info::get_company() : array();
                            $banking_data = class_exists('\MHBO\Business\Info') ? \MHBO\Business\Info::get_banking() : array();
                            $revolut_data = class_exists('\MHBO\Business\Info') ? \MHBO\Business\Info::get_revolut() : array();

                            $hotel_name  = ! empty( $company_data['company_name'] ) ? (string) $company_data['company_name'] : (string) get_option('mhbo_hotel_name', '');
                            $hotel_email = ! empty( $company_data['email'] ) ? (string) $company_data['email'] : (string) get_option('admin_email', '');
                            $hotel_addr  = ! empty( $company_data['address_line_1'] ) ? (string) $company_data['address_line_1'] : (string) get_option('mhbo_hotel_address', '');
                            $hotel_maps  = ! empty( $company_data['google_maps_url'] ) ? (string) $company_data['google_maps_url'] : (string) get_option('mhbo_hotel_google_maps_url', '');

                            $is_bank_active  = ! empty( $banking_data['enabled'] ) && ( ! empty( $banking_data['iban'] ) || ! empty( $banking_data['bank_name'] ) || ! empty( $banking_data['account_number'] ) );
                            $is_rev_active   = ! empty( $revolut_data['enabled'] ) && ( ! empty( $revolut_data['revolut_tag'] ) || ! empty( $revolut_data['revolut_link'] ) || ! empty( $revolut_data['revolut_iban'] ) );
                            $hotel_bank      = (string) get_option('mhbo_bank_details', '');
                            $hotel_rev       = (string) get_option('mhbo_revolut_revtag', '');
                            $has_offline_pay = $is_bank_active || $is_rev_active || ! empty( $hotel_bank ) || ! empty( $hotel_rev );

                            $is_name_ready = ! empty( $hotel_name );
                            $is_addr_ready = ! empty( $hotel_addr );
                            $is_maps_ready = ! empty( $hotel_maps );
                            $is_wifi_ready = ! empty( $wifi_ssid );
                            $is_all_ready  = $is_name_ready && $is_addr_ready && $is_maps_ready && $is_wifi_ready;
                            ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; font-size: 12.5px;">
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <strong><?php esc_html_e('Property Name & Contact', 'modern-hotel-booking'); ?></strong>
                                        <?php if ( $is_name_ready ) : ?>
                                            <span style="color: #16a34a; font-weight: 700;">✓ Ready</span>
                                        <?php else : ?>
                                            <span style="color: #d97706; font-weight: 600;">⚠️ Incomplete</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $is_name_ready ) : ?>
                                        <div style="color: #0f172a; font-weight: 600; font-size: 12px;"><?php echo esc_html($hotel_name); ?></div>
                                        <?php if ( ! empty($hotel_email) ) : ?>
                                            <div style="color: #64748b; font-size: 11.5px;"><?php echo esc_html($hotel_email); ?></div>
                                        <?php endif; ?>
                                        <div style="margin-top: 4px;"><a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business')); ?>" style="text-decoration: underline; color: #2563eb;"><?php esc_html_e('Edit in Business Info &rarr;', 'modern-hotel-booking'); ?></a></div>
                                    <?php else : ?>
                                        <span style="color: #64748b; font-size: 11.5px;"><?php esc_html_e('Shown in portal header & email footers.', 'modern-hotel-booking'); ?></span>
                                        <div style="margin-top: 4px;"><a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business')); ?>" style="text-decoration: underline; color: #2563eb;"><?php esc_html_e('Set in Business Info &rarr;', 'modern-hotel-booking'); ?></a></div>
                                    <?php endif; ?>
                                </div>
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <strong><?php esc_html_e('Google Maps & Directions', 'modern-hotel-booking'); ?></strong>
                                        <?php if ( $is_maps_ready ) : ?>
                                            <span style="color: #16a34a; font-weight: 700;">✓ Ready</span>
                                        <?php else : ?>
                                            <span style="color: #64748b; font-weight: 500;"><?php esc_html_e('Optional', 'modern-hotel-booking'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <span style="color: #64748b; font-size: 11.5px;"><?php esc_html_e('Renders 1-click navigation in portal.', 'modern-hotel-booking'); ?></span>
                                    <div style="margin-top: 4px;"><a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business')); ?>" style="text-decoration: underline; color: #2563eb;"><?php echo $is_maps_ready ? esc_html__('Edit Maps Link &rarr;', 'modern-hotel-booking') : esc_html__('Add Maps URL &rarr;', 'modern-hotel-booking'); ?></a></div>
                                </div>
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <strong><?php esc_html_e('Bank & Revolut Pay', 'modern-hotel-booking'); ?></strong>
                                        <?php if ( $has_offline_pay ) : ?>
                                            <span style="color: #16a34a; font-weight: 700;">✓ Configured</span>
                                        <?php else : ?>
                                            <span style="color: #64748b; font-weight: 500;"><?php esc_html_e('Optional', 'modern-hotel-booking'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $has_offline_pay ) : ?>
                                        <div style="display: flex; gap: 4px; margin-top: 2px; margin-bottom: 4px; flex-wrap: wrap;">
                                            <?php if ( $is_bank_active || ! empty($hotel_bank) ) : ?>
                                                <span style="background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 4px; font-size: 11px; font-weight: 600;">Bank</span>
                                            <?php endif; ?>
                                            <?php if ( $is_rev_active || ! empty($hotel_rev) ) : ?>
                                                <span style="background: #f3e8ff; color: #7e22ce; padding: 1px 6px; border-radius: 4px; font-size: 11px; font-weight: 600;">Revolut</span>
                                            <?php endif; ?>
                                        </div>
                                        <div><a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business&subtab=banking')); ?>" style="text-decoration: underline; color: #2563eb;"><?php esc_html_e('Manage Offline Pay &rarr;', 'modern-hotel-booking'); ?></a></div>
                                    <?php else : ?>
                                        <span style="color: #64748b; font-size: 11.5px;"><?php esc_html_e('Instructs offline/direct paying guests.', 'modern-hotel-booking'); ?></span>
                                        <div style="margin-top: 4px;"><a href="<?php echo esc_url(admin_url('admin.php?page=mhbo-settings&tab=business&subtab=banking')); ?>" style="text-decoration: underline; color: #2563eb;"><?php esc_html_e('Configure in Business Info &rarr;', 'modern-hotel-booking'); ?></a></div>
                                    <?php endif; ?>
                                </div>
                                <div style="background: #ffffff; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <strong><?php esc_html_e('Guest Wi-Fi Credentials', 'modern-hotel-booking'); ?></strong>
                                        <?php if ( $is_wifi_ready ) : ?>
                                            <span style="color: #16a34a; font-weight: 700;">✓ Ready</span>
                                        <?php else : ?>
                                            <span style="color: #64748b; font-weight: 500;"><?php esc_html_e('Optional', 'modern-hotel-booking'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $is_wifi_ready ) : ?>
                                        <div style="color: #0f172a; font-weight: 600; font-size: 12px;"><?php echo esc_html($wifi_ssid); ?></div>
                                    <?php endif; ?>
                                    <span style="color: #64748b; font-size: 11.5px;"><?php esc_html_e('Instant 1-click password copy for guests.', 'modern-hotel-booking'); ?></span>
                                </div>
                            </div>
                            <?php if ( $is_all_ready ) : ?>
                                <div style="margin-top: 12px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 8px 12px; color: #15803d; font-size: 12px; display: flex; align-items: center; gap: 6px;">
                                    <span>🎉</span>
                                    <span><?php esc_html_e('All core property details are configured! Your Guest Portal is fully equipped to deliver an outstanding arrival experience.', 'modern-hotel-booking'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_hotel_wifi_ssid"><?php echo esc_html(__('Guest Wi-Fi Network Name (SSID)', 'modern-hotel-booking')); ?></label>
                        <input type="text" id="mhbo_hotel_wifi_ssid" name="mhbo_hotel_wifi_ssid" value="<?php echo esc_attr($wifi_ssid); ?>" class="mhbo-form-input" placeholder="e.g. GrandHotel_Guest">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Leave blank to hide the Wi-Fi card in the guest self-service portal.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group">
                        <label class="mhbo-form-label" for="mhbo_hotel_wifi_password"><?php echo esc_html(__('Guest Wi-Fi Password', 'modern-hotel-booking')); ?></label>
                        <input type="text" id="mhbo_hotel_wifi_password" name="mhbo_hotel_wifi_password" value="<?php echo esc_attr($wifi_pass); ?>" class="mhbo-form-input" placeholder="e.g. Welcome2026!">
                        <p class="mhbo-form-help"><?php echo esc_html(__('Guests can copy this password with 1 click from their verified portal screen.', 'modern-hotel-booking')); ?></p>
                    </div>

                    <div class="mhbo-form-group mhbo-span-full">
                        <label class="mhbo-form-label" for="mhbo_hotel_guest_instructions"><?php echo esc_html(__('Digital House Rules & Check-In Guide', 'modern-hotel-booking')); ?></label>
                        <textarea id="mhbo_hotel_guest_instructions" name="mhbo_hotel_guest_instructions" rows="4" class="large-text" placeholder="<?php echo esc_attr(__('e.g. Breakfast is served 7:00-10:30 AM in the Garden Pavilion. Front desk is staffed 24/7. Quiet hours begin at 10:00 PM.', 'modern-hotel-booking')); ?>"><?php echo esc_textarea($guest_guide); ?></textarea>
                        <p class="mhbo-form-help"><?php echo esc_html(__('Helpful instructions, breakfast hours, parking directions, and house rules. Rendered conditionally in the guest portal.', 'modern-hotel-booking')); ?></p>
                    </div>
                </div>
            </div>

            <!-- Card 10: System & Security -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-shield"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php echo esc_html(I18n::get_label('settings_section_security')); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php echo esc_html(__('Reverse proxy trust list for client IP detection and plugin data retention.', 'modern-hotel-booking')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid mhbo-grid-2col">
                    <div class="mhbo-form-group mhbo-span-full">
                        <label class="mhbo-form-label" for="mhbo_trusted_proxies"><?php echo esc_html(I18n::get_label('settings_label_proxies')); ?></label>
                        <textarea id="mhbo_trusted_proxies" name="mhbo_trusted_proxies" rows="3" class="large-text code" placeholder="127.0.0.1&#10;10.0.0.0/8&#10;172.16.0.0/12"><?php echo esc_textarea($proxies); ?></textarea>
                        <p class="mhbo-form-help"><?php echo esc_html(I18n::get_label('settings_desc_proxies')); ?></p>
                    </div>

                    <div class="mhbo-form-group mhbo-span-full">
                        <div class="mhbo-switch-row">
                            <div class="mhbo-switch-info">
                                <span class="mhbo-switch-title"><?php echo esc_html(I18n::get_label('settings_label_uninstall')); ?></span>
                                <p class="mhbo-switch-desc"><?php echo esc_html(I18n::get_label('settings_desc_uninstall')); ?></p>
                            </div>
                            <label class="mhbo-toggle-switch">
                                <input type="hidden" name="mhbo_save_data_on_uninstall" value="0">
                                <input type="checkbox" id="mhbo_save_data_on_uninstall" name="mhbo_save_data_on_uninstall" value="1" <?php checked(1, $keep_data); ?>>
                                <span class="mhbo-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    public static function render_labels_tab(): void {
        $langs   = I18n::get_available_languages();
        $catalog = I18n::get_labels_catalog();

        // Add dynamic amenities to labels if configured.
        $amenities = get_option( 'mhbo_amenities_list' );
        if ( false === $amenities ) {
            $amenities = array(
                'wifi'      => I18n::__( 'amenity_free_wifi' ),
                'ac'        => I18n::__( 'amenity_air_conditioning' ),
                'tv'        => I18n::__( 'amenity_smart_tv' ),
                'breakfast' => I18n::__( 'amenity_breakfast_included' ),
                'pool'      => I18n::__( 'amenity_pool_view' ),
            );
        }
        $amenities = is_array( $amenities ) ? $amenities : array();
        if ( isset( $catalog['amenities']['labels'] ) && is_array( $catalog['amenities']['labels'] ) ) {
            foreach ( $amenities as $key => $label ) {
                if ( is_string( $label ) && '' !== $label ) {
                    $catalog['amenities']['labels'][ $key ] = $label;
                }
            }
        }

        // Tier Gating (Law 7 & User Specification):
        // Free keeps only Free; Starter keeps Free + Starter; Pro keeps all.
        $package_tier = class_exists( Tier::class ) ? Tier::get_package_tier() : 'free';
        $tier_order   = class_exists( Tier::class ) ? Tier::ORDER : array(
            'free'    => 0,
            'starter' => 1,
            'pro'     => 2,
        );
        $pkg_rank     = $tier_order[ $package_tier ] ?? 0;
        foreach ( $catalog as $cat_id => $cat_data ) {
            $cat_tier = $cat_data['tier'] ?? 'free';
            $cat_rank = $tier_order[ $cat_tier ] ?? 0;
            if ( $cat_rank > $pkg_rank ) {
                unset( $catalog[ $cat_id ] );
            }
        }

        $total_labels        = 0;
        $customized_count    = 0;
        $free_label_count    = 0;
        $starter_label_count = 0;
        $pro_label_count     = 0;
        $cat_custom_counts   = array();

        foreach ( $catalog as $cat_id => $cat_data ) {
            $cat_tier   = $cat_data['tier'] ?? 'free';
            $num_in_cat = count( $cat_data['labels'] );
            $total_labels += $num_in_cat;
            $cat_custom_counts[ $cat_id ] = 0;

            if ( 'free' === $cat_tier ) {
                $free_label_count += $num_in_cat;
            } elseif ( 'starter' === $cat_tier ) {
                $starter_label_count += $num_in_cat;
            } elseif ( 'pro' === $cat_tier ) {
                $pro_label_count += $num_in_cat;
            }

            foreach ( $cat_data['labels'] as $k => $desc ) {
                $existing_val = get_option( "mhbo_label_{$k}" );
                if ( false !== $existing_val && '' !== trim( (string) $existing_val ) ) {
                    ++$customized_count;
                    ++$cat_custom_counts[ $cat_id ];
                }
            }
        }

        $total_categories   = count( $catalog );
        $active_langs_count = count( $langs );
        ?>
        <div class="mhbo-settings-card-container">

            <!-- Search, Filter & Metrics Ribbon -->
            <div class="mhbo-settings-section-card" style="padding: 18px 24px; margin-bottom: 0;">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                    <div>
                        <h3 class="mhbo-settings-section-title" style="margin-bottom: 2px !important;"><?php echo esc_html( I18n::get_label( 'tab_labels' ) ); ?></h3>
                        <p class="mhbo-settings-section-desc">
                            <?php
                            /* translators: 1: placeholder example %s, 2: placeholder example %d */
                            printf( esc_html( I18n::get_label( 'settings_labels_desc' ) ), '<code>%s</code>', '<code>%d</code>' );
                            ?>
                        </p>
                        <div style="display: flex; align-items: center; gap: 8px; margin-top: 8px; flex-wrap: wrap;">
                            <span class="mhbo-badge" style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px;">
                                <?php
                                /* translators: %d: Total count of translatable labels. */
                                printf( esc_html__( '%d Total Labels', 'modern-hotel-booking' ), (int) $total_labels );
                                ?>
                            </span>
                            <span class="mhbo-badge" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px;">
                                <?php
                                /* translators: %d: Total count of label categories. */
                                printf( esc_html__( '%d Categories', 'modern-hotel-booking' ), (int) $total_categories );
                                ?>
                            </span>
                            <span class="mhbo-badge" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px;">
                                <?php
                                /* translators: %d: Count of active languages. */
                                printf( esc_html__( '%d Active Languages', 'modern-hotel-booking' ), (int) $active_langs_count );
                                ?>
                            </span>
                            <?php if ( $customized_count > 0 ) : ?>
                                <span class="mhbo-badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px;">
                                    <?php
                                    /* translators: %d: Count of customized labels. */
                                    printf( esc_html__( '%d Customized', 'modern-hotel-booking' ), (int) $customized_count );
                                    ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( 'free' !== $package_tier ) : ?>
                                <span class="mhbo-badge" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 999px;">
                                    <?php
                                    if ( 'starter' === $package_tier ) {
                                        /* translators: 1: Count of Core labels, 2: Count of Starter labels. */
                                        echo esc_html( sprintf( __( 'Core: %1$d | Starter: %2$d', 'modern-hotel-booking' ), $free_label_count, $starter_label_count ) );
                                    } else {
                                        /* translators: 1: Count of Core labels, 2: Count of Starter labels, 3: Count of Pro labels. */
                                        echo esc_html( sprintf( __( 'Core: %1$d | Starter: %2$d | Pro: %3$d', 'modern-hotel-booking' ), $free_label_count, $starter_label_count, $pro_label_count ) );
                                    }
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; min-width: 340px; flex: 1; max-width: 720px; justify-content: flex-end; flex-wrap: wrap;">
                        <button type="button" id="mhbo_toggle_all_cats" class="button button-secondary" onclick="mhboToggleAllCategories();" style="height: 36px; line-height: 34px; padding: 0 10px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;" aria-expanded="true">
                            <span class="dashicons dashicons-arrow-up-alt2" id="mhbo_toggle_icon" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px;"></span>
                            <span id="mhbo_toggle_label"><?php esc_html_e( 'Collapse All', 'modern-hotel-booking' ); ?></span>
                        </button>
                        <?php if ( 'free' !== $package_tier ) : ?>
                        <select id="mhbo_label_tier_select" class="mhbo-form-input" style="height: 36px; font-size: 13px; max-width: 135px;" onchange="mhboFilterLabels();" aria-label="<?php esc_attr_e( 'Filter by edition tier', 'modern-hotel-booking' ); ?>">
                            <option value="all"><?php esc_html_e( 'All Tiers', 'modern-hotel-booking' ); ?></option>
                            <option value="free"><?php esc_html_e( 'Core / Free', 'modern-hotel-booking' ); ?> (<?php echo (int) $free_label_count; ?>)</option>
                            <option value="starter"><?php esc_html_e( 'Starter Tier', 'modern-hotel-booking' ); ?> (<?php echo (int) $starter_label_count; ?>)</option>
                            <?php if ( 'pro' === $package_tier ) : ?>
                            <option value="pro"><?php esc_html_e( 'Pro Tier', 'modern-hotel-booking' ); ?> (<?php echo (int) $pro_label_count; ?>)</option>
                            <?php endif; ?>
                        </select>
                        <?php endif; ?>
                        <select id="mhbo_label_category_select" class="mhbo-form-input" style="height: 36px; font-size: 13px; max-width: 220px;" onchange="mhboFilterLabels();" aria-label="<?php esc_attr_e( 'Filter by label category', 'modern-hotel-booking' ); ?>">
                            <option value="all"><?php esc_html_e( 'All Categories', 'modern-hotel-booking' ); ?> (<?php echo (int) $total_categories; ?>)</option>
                            <?php foreach ( $catalog as $cat_id => $cat_data ) : ?>
                                <option value="<?php echo esc_attr( $cat_id ); ?>" data-tier="<?php echo esc_attr( $cat_data['tier'] ); ?>">
                                    <?php echo esc_html( $cat_data['title'] ); ?> (<?php echo count( $cat_data['labels'] ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="display: flex; align-items: center; gap: 6px; flex: 1; min-width: 170px;">
                            <span class="dashicons dashicons-search" style="color: #64748b;"></span>
                            <input type="search" id="mhbo_label_search_input" class="mhbo-form-input" placeholder="<?php esc_attr_e( 'Filter labels by key or text...', 'modern-hotel-booking' ); ?>" style="height: 36px; font-size: 13px;" oninput="mhboFilterLabels();" aria-label="<?php esc_attr_e( 'Search labels', 'modern-hotel-booking' ); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <?php
            foreach ( $catalog as $cat_id => $cat_data ) :
                $cat_tier   = $cat_data['tier'] ?? 'free';
                $card_icon  = ! empty( $cat_data['icon'] ) ? $cat_data['icon'] : 'dashicons-translation';
                $cat_custom = $cat_custom_counts[ $cat_id ] ?? 0;
                ?>
                <div class="mhbo-settings-section-card mhbo-label-group-card" data-group="<?php echo esc_attr( $cat_id ); ?>" data-tier="<?php echo esc_attr( $cat_tier ); ?>">
                    <div class="mhbo-settings-section-header" onclick="mhboToggleCategory(this.closest('.mhbo-label-group-card'));" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault(); mhboToggleCategory(this.closest('.mhbo-label-group-card'));}" style="cursor: pointer; user-select: none;" role="button" tabindex="0" aria-expanded="true">
                        <div class="mhbo-settings-section-title-wrap" style="flex: 1;">
                            <div class="mhbo-settings-section-icon"><span class="dashicons <?php echo esc_attr( $card_icon ); ?>"></span></div>
                            <div style="flex: 1;">
                                <h3 class="mhbo-settings-section-title" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <span><?php echo esc_html( $cat_data['title'] ); ?></span>
                                    <?php if ( 'pro' === $cat_tier ) : ?>
                                        <span class="mhbo-tier-badge mhbo-badge-pro" style="display: inline-flex; align-items: center; gap: 3px; font-size: 11px; padding: 2px 8px; border-radius: 999px; font-weight: 700; background: #fef3c7; color: #92400e; border: 1px solid #fde68a;">
                                            <span class="dashicons dashicons-awards" style="font-size: 12px; width: 12px; height: 12px; line-height: 12px;"></span> PRO
                                        </span>
                                    <?php elseif ( 'starter' === $cat_tier ) : ?>
                                        <span class="mhbo-tier-badge mhbo-badge-starter" style="display: inline-flex; align-items: center; gap: 3px; font-size: 11px; padding: 2px 8px; border-radius: 999px; font-weight: 700; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;">
                                            <span class="dashicons dashicons-star-filled" style="font-size: 12px; width: 12px; height: 12px; line-height: 12px;"></span> STARTER
                                        </span>
                                    <?php else : ?>
                                        <span class="mhbo-tier-badge mhbo-badge-core" style="display: inline-flex; align-items: center; gap: 3px; font-size: 11px; padding: 2px 8px; border-radius: 999px; font-weight: 700; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">
                                            <span class="dashicons dashicons-admin-generic" style="font-size: 12px; width: 12px; height: 12px; line-height: 12px;"></span> CORE
                                        </span>
                                    <?php endif; ?>
                                    <?php if ( $cat_custom > 0 ) : ?>
                                        <span class="mhbo-badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 999px;">
                                            <?php
                                            /* translators: %d: count of customized labels in category */
                                            printf( esc_html__( '%d customized', 'modern-hotel-booking' ), (int) $cat_custom );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </h3>
                                <p class="mhbo-settings-section-desc">
                                <?php
                                    /* translators: %d: Number of labels in this category. */
                                    printf( esc_html__( '%d labels in this category', 'modern-hotel-booking' ), count( $cat_data['labels'] ) );
                                ?>
                                </p>
                            </div>
                            <span class="dashicons dashicons-arrow-down-alt2 mhbo-cat-chevron" style="transition: transform 0.2s ease; margin-left: 12px; color: #64748b; font-size: 18px; width: 18px; height: 18px;"></span>
                        </div>
                    </div>

                    <div class="mhbo-settings-grid mhbo-cat-grid" style="gap: 16px;">
                        <?php
                        foreach ( $cat_data['labels'] as $key => $label_desc ) :
                            $val             = get_option( "mhbo_label_{$key}" );
                            $has_custom      = ( false !== $val && '' !== trim( (string) $val ) );
                            $search_haystack = strtolower( $key . ' ' . $label_desc . ' ' . $cat_tier );
                            $default_label   = I18n::get_label( $key );
                            $placeholder_txt = ( '' !== $default_label && $default_label !== $key ) ? $default_label : $label_desc;
                            ?>
                            <div class="mhbo-form-group mhbo-span-full mhbo-label-row" data-search="<?php echo esc_attr( $search_haystack ); ?>" data-tier="<?php echo esc_attr( $cat_tier ); ?>" style="padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                                    <strong style="font-size: 13.5px; color: #0f172a;"><?php echo esc_html( $label_desc ); ?></strong>
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <?php if ( 'pro' === $cat_tier ) : ?>
                                            <span class="mhbo-tier-badge mhbo-badge-pro" style="font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; display: inline-flex; align-items: center; gap: 2px;">
                                                <span class="dashicons dashicons-awards" style="font-size: 11px; width: 11px; height: 11px; line-height: 11px;"></span> PRO
                                            </span>
                                        <?php elseif ( 'starter' === $cat_tier ) : ?>
                                            <span class="mhbo-tier-badge mhbo-badge-starter" style="font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; display: inline-flex; align-items: center; gap: 2px;">
                                                <span class="dashicons dashicons-star-filled" style="font-size: 11px; width: 11px; height: 11px; line-height: 11px;"></span> STARTER
                                            </span>
                                        <?php else : ?>
                                            <span class="mhbo-tier-badge mhbo-badge-core" style="font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; display: inline-flex; align-items: center; gap: 2px;">
                                                <span class="dashicons dashicons-admin-generic" style="font-size: 11px; width: 11px; height: 11px; line-height: 11px;"></span> CORE
                                            </span>
                                        <?php endif; ?>

                                        <?php if ( $has_custom ) : ?>
                                            <span class="mhbo-badge mhbo-status-badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 10.5px; font-weight: 700; padding: 2px 6px; border-radius: 4px;">
                                                <?php esc_html_e( 'Customized', 'modern-hotel-booking' ); ?>
                                            </span>
                                            <button type="button" class="button button-small" onclick="var p = this.closest('.mhbo-label-row'); p.querySelectorAll('input.mhbo-form-input').forEach(function(i){i.value=''; i.dataset.modified='1';}); this.style.display='none'; var n = p.querySelector('.mhbo-revert-badge'); if(n) n.style.display=''; var b = p.querySelector('.mhbo-status-badge'); if(b) b.style.display='none'; mhboShowFloatingSave();" title="<?php esc_attr_e( 'Clear overrides and revert to default translation', 'modern-hotel-booking' ); ?>" style="font-size: 11px; height: 24px; min-height: 24px; line-height: 22px; padding: 0 8px;">
                                                <?php esc_html_e( 'Revert', 'modern-hotel-booking' ); ?>
                                            </button>
                                        <?php else : ?>
                                            <span class="mhbo-badge" style="background: #f1f5f9; color: #64748b; font-size: 10.5px; font-weight: 600; padding: 2px 6px; border-radius: 4px;">
                                                <?php esc_html_e( 'Default', 'modern-hotel-booking' ); ?>
                                            </span>
                                        <?php endif; ?>
                                        <span class="mhbo-badge mhbo-revert-badge" style="display:none; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px;">
                                            ✓ <?php esc_html_e( 'Reverted (Save to apply)', 'modern-hotel-booking' ); ?>
                                        </span>
                                        <code style="font-size: 11px; color: #64748b; background: #e2e8f0; padding: 2px 6px; border-radius: 4px;"><?php echo esc_html( $key ); ?></code>
                                    </div>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 6px;">
                                    <?php
                                    foreach ( $langs as $lang ) :
                                        $lang_val = (string) ( I18n::decode( $val, $lang ) ?? '' );
                                        ?>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span class="mhbo-badge" style="width: 34px; text-align: center; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-size: 11px; font-weight: 800; padding: 2px 4px; border-radius: 4px; flex-shrink: 0;">
                                                <?php echo esc_html( strtoupper( $lang ) ); ?>
                                            </span>
                                            <input type="text" name="mhbo_label_templates[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $lang ); ?>]" value="<?php echo esc_attr( $lang_val ); ?>" placeholder="<?php echo esc_attr( $placeholder_txt ); ?>" class="mhbo-form-input" style="flex: 1; height: 34px; font-size: 13px;" oninput="this.dataset.modified='1'; mhboShowFloatingSave();">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Floating Unsaved Changes Shortcut Bar -->
            <div id="mhbo-floating-save-bar" style="display:none; position: fixed; bottom: 24px; right: 28px; z-index: 99999; background: #0f172a; color: #ffffff; padding: 12px 20px; border-radius: 12px; box-shadow: 0 12px 30px rgba(0,0,0,0.35); align-items: center; gap: 14px; border: 1px solid #334155;">
                <span style="font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                    <span class="dashicons dashicons-saved" style="color: #38bdf8; font-size: 18px; width: 18px; height: 18px; line-height: 18px;"></span>
                    <?php esc_html_e( 'You have unsaved label modifications.', 'modern-hotel-booking' ); ?>
                </span>
                <button type="button" class="button button-primary" onclick="var f = document.querySelector('.mhbo-settings-card-container').closest('form'); if(f) f.requestSubmit();" style="background: #2563eb; border-color: #1d4ed8; height: 32px; line-height: 30px; font-weight: 700; padding: 0 16px; border-radius: 6px;">
                    <?php esc_html_e( 'Save Changes', 'modern-hotel-booking' ); ?>
                </button>
            </div>

        </div>
        <script>
        function mhboToggleCategory(card) {
            if (!card) return;
            var grid = card.querySelector('.mhbo-cat-grid');
            var chevron = card.querySelector('.mhbo-cat-chevron');
            var header = card.querySelector('.mhbo-settings-section-header');
            if (!grid) return;
            var isHidden = grid.style.display === 'none';
            grid.style.display = isHidden ? '' : 'none';
            if (chevron) {
                chevron.style.transform = isHidden ? '' : 'rotate(-90deg)';
            }
            if (header) {
                header.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
            }
        }

        function mhboToggleAllCategories() {
            var btn = document.getElementById('mhbo_toggle_all_cats');
            var icon = document.getElementById('mhbo_toggle_icon');
            var label = document.getElementById('mhbo_toggle_label');
            var isCurrentlyExpanded = btn && btn.getAttribute('aria-expanded') === 'true';
            var nextExpanded = !isCurrentlyExpanded;

            document.querySelectorAll('.mhbo-label-group-card').forEach(function(card) {
                var grid = card.querySelector('.mhbo-cat-grid');
                var chevron = card.querySelector('.mhbo-cat-chevron');
                var header = card.querySelector('.mhbo-settings-section-header');
                if (grid) {
                    grid.style.display = nextExpanded ? '' : 'none';
                }
                if (chevron) {
                    chevron.style.transform = nextExpanded ? '' : 'rotate(-90deg)';
                }
                if (header) {
                    header.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
                }
            });

            if (btn) btn.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
            if (icon) {
                icon.className = nextExpanded ? 'dashicons dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2';
            }
            if (label) {
                label.textContent = nextExpanded ? '<?php echo esc_js( __( 'Collapse All', 'modern-hotel-booking' ) ); ?>' : '<?php echo esc_js( __( 'Expand All', 'modern-hotel-booking' ) ); ?>';
            }
        }

        function mhboShowFloatingSave() {
            var bar = document.getElementById('mhbo-floating-save-bar');
            if (bar) bar.style.display = 'flex';
        }

        function mhboFilterLabels() {
            var searchInput = document.getElementById('mhbo_label_search_input');
            var catSelect   = document.getElementById('mhbo_label_category_select');
            var tierSelect  = document.getElementById('mhbo_label_tier_select');
            var searchVal   = searchInput ? (searchInput.value || '').toLowerCase().trim() : '';
            var catVal      = catSelect ? catSelect.value : 'all';
            var tierVal     = tierSelect ? tierSelect.value : 'all';

            document.querySelectorAll('.mhbo-label-group-card').forEach(function(card) {
                var cardGroup = card.getAttribute('data-group') || '';
                var cardTier  = card.getAttribute('data-tier') || 'free';
                var grid      = card.querySelector('.mhbo-cat-grid');
                var chevron   = card.querySelector('.mhbo-cat-chevron');
                var header    = card.querySelector('.mhbo-settings-section-header');

                if (catVal && catVal !== 'all' && cardGroup !== catVal) {
                    card.style.display = 'none';
                    return;
                }
                if (tierVal && tierVal !== 'all' && cardTier !== tierVal) {
                    card.style.display = 'none';
                    return;
                }

                var visibleCount = 0;
                card.querySelectorAll('.mhbo-label-row').forEach(function(row) {
                    var searchData = (row.getAttribute('data-search') || '').toLowerCase();
                    var rowTier    = row.getAttribute('data-tier') || 'free';

                    var matchesTier   = (tierVal === 'all' || rowTier === tierVal);
                    var matchesSearch = (!searchVal || searchData.indexOf(searchVal) !== -1);
                    var isVisible     = matchesTier && matchesSearch;

                    row.style.display = isVisible ? '' : 'none';
                    if (isVisible) visibleCount++;
                });

                card.style.display = (visibleCount > 0) ? '' : 'none';

                // If searching or filtering specific category, automatically expand matching cards
                if (visibleCount > 0 && (searchVal || catVal !== 'all')) {
                    if (grid) grid.style.display = '';
                    if (chevron) chevron.style.transform = '';
                    if (header) header.setAttribute('aria-expanded', 'true');
                }
            });
        }

        // max_input_vars protection: Prune clean empty inputs at submit time so PHP doesn't drop vars
        (function() {
            var initSubmitPruning = function() {
                var form = document.querySelector('.mhbo-settings-card-container') ? document.querySelector('.mhbo-settings-card-container').closest('form') : null;
                if (!form) return;
                form.addEventListener('submit', function() {
                    form.querySelectorAll('.mhbo-label-row input.mhbo-form-input').forEach(function(input) {
                        if (!input.value && !input.dataset.modified) {
                            input.disabled = true;
                        }
                    });
                });
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initSubmitPruning);
            } else {
                initSubmitPruning();
            }
        })();
        </script>
        <?php
    }

    public static function render_amenities_tab(): void {
        $amenities = get_option('mhbo_amenities_list');
        if (false === $amenities) {
            $amenities = array(
                'wifi'      => I18n::get_label('label_free_wifi'),
                'ac'        => I18n::get_label('label_air_conditioning'),
                'tv'        => I18n::get_label('label_smart_tv'),
                'breakfast' => I18n::get_label('label_breakfast_included'),
                'pool'      => I18n::get_label('label_pool_view'),
            );
        }
        $amenities = is_array($amenities) ? $amenities : array();
        ?>
        <div class="mhbo-settings-card-container">

            <!-- Card 1: Add New Amenity -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-plus-alt2"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php I18n::esc_html_e('label_add_new_amenity'); ?></h3>
                            <p class="mhbo-settings-section-desc"><?php I18n::esc_html_e('msg_room_amenities_desc'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid">
                    <div class="mhbo-form-group mhbo-span-full">
                        <div style="display: flex; gap: 12px; align-items: center; max-width: 600px; flex-wrap: wrap;">
                            <input type="text" name="mhbo_new_amenity" placeholder="<?php esc_attr_e('e.g. Hot Tub, Ocean View, Mini Bar', 'modern-hotel-booking'); ?>" class="mhbo-form-input" style="flex: 1; min-width: 240px; height: 38px;">
                            <button type="submit" name="mhbo_add_amenity" value="1" class="button button-primary" style="height: 38px; padding: 0 20px; font-weight: 600;">
                                <?php I18n::esc_html_e('btn_add'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: Configured Amenities Grid -->
            <div class="mhbo-settings-section-card">
                <div class="mhbo-settings-section-header">
                    <div class="mhbo-settings-section-title-wrap">
                        <div class="mhbo-settings-section-icon"><span class="dashicons dashicons-tag"></span></div>
                        <div>
                            <h3 class="mhbo-settings-section-title"><?php I18n::esc_html_e('label_room_amenities_title'); ?></h3>
                            <p class="mhbo-settings-section-desc">
                            <?php 
                                /* translators: %d: Number of active room amenities. */
                                printf( esc_html__('%d active amenities available for rooms & filters', 'modern-hotel-booking'), count($amenities) ); 
                            ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mhbo-settings-grid">
                    <div class="mhbo-form-group mhbo-span-full">
                        <?php if (count($amenities) === 0) : ?>
                            <div style="padding: 24px; text-align: center; color: #64748b; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                                <?php I18n::esc_html_e('label_no_amenities_found'); ?>
                            </div>
                        <?php else : ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
                                <?php foreach ($amenities as $key => $label) : ?>
                                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                                        <div>
                                            <strong style="font-size: 13.5px; color: #0f172a; display: block;"><?php echo esc_html($label); ?></strong>
                                            <code style="font-size: 11px; color: #64748b; background: #e2e8f0; padding: 1px 6px; border-radius: 4px; margin-top: 3px; display: inline-block;"><?php echo esc_html($key); ?></code>
                                        </div>
                                        <button type="submit" name="mhbo_remove_amenity" value="<?php echo esc_attr($key); ?>"
                                            class="button button-link-delete"
                                            style="color: #ef4444; text-decoration: none; font-size: 13px; font-weight: 600; padding: 4px 8px;"
                                            onclick="return confirm('<?php echo esc_js(I18n::get_label('label_are_you_sure')); ?>');">
                                            <span class="dashicons dashicons-trash" style="font-size: 16px; width: 16px; height: 16px; margin-top: 2px;"></span>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }
}
