<?php declare(strict_types=1);

namespace MHB\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

use MHB\Core\I18n;

class Calendar
{
    public function init()
    {
        add_shortcode('mhb_room_calendar', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'load_assets']);
    }

    /**
     * Ensure assets are loaded (late enqueue fallback for widgets/templates).
     * 
     * This method is called during shortcode rendering to ensure assets
     * are loaded even when the shortcode is used in widgets, footers, or
     * other areas not checked by has_shortcode().
     */
    public static function enqueue_assets(): void
    {
        // Register assets if they aren't already (e.g. if Block.php didn't run or we need them early)
        // We register them here to ensure they exist before enqueueing
        if (!wp_style_is('mhb-calendar-style', 'registered')) {
            wp_register_style(
                'mhb-calendar-style',
                MHB_PLUGIN_URL . 'assets/css/mhb-calendar.css',
                ['mhb-flatpickr-css', 'mhb-style'],
                MHB_VERSION
            );
        }
        if (!wp_style_is('mhb-flatpickr-css', 'registered')) {
            wp_register_style(
                'mhb-flatpickr-css',
                MHB_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
                [],
                '4.6.13'
            );
        }
        if (!wp_style_is('mhb-style', 'registered')) {
            wp_register_style(
                'mhb-style',
                MHB_PLUGIN_URL . 'assets/css/mhb-style.css',
                [],
                MHB_VERSION
            );
        }
        if (!wp_script_is('mhb-calendar-js', 'registered')) {
            wp_register_script(
                'mhb-calendar-js',
                MHB_PLUGIN_URL . 'assets/js/mhb-calendar.js',
                ['jquery', 'mhb-flatpickr-js'],
                MHB_VERSION,
                true
            );
        }
        if (!wp_script_is('mhb-flatpickr-js', 'registered')) {
            wp_register_script(
                'mhb-flatpickr-js',
                MHB_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
                [],
                '4.6.13',
                true
            );
        }

        // Enqueue flatpickr first (dependency for calendar)
        if (!wp_style_is('mhb-flatpickr-css', 'enqueued')) {
            wp_enqueue_style('mhb-flatpickr-css');
        }
        if (!wp_script_is('mhb-flatpickr-js', 'enqueued')) {
            wp_enqueue_script('mhb-flatpickr-js');
        }

        // Enqueue calendar assets
        if (!wp_style_is('mhb-calendar-style', 'enqueued')) {
            wp_enqueue_style('mhb-calendar-style');
        }
        if (!wp_script_is('mhb-calendar-js', 'enqueued')) {
            wp_enqueue_script('mhb-calendar-js');
        }

        // Inject theme styles if available (must be after enqueueing styles)
        if (class_exists('MHB\\Frontend\\Shortcode')) {
            Shortcode::inject_theme_styles();
        }

        // Load locale-specific Flatpickr scripts dynamically based on availability
        $current_lang = I18n::get_current_language();
        // Check if a locale file exists for the current language
        $locale_path = MHB_PLUGIN_DIR . 'assets/js/vendor/flatpickr.' . $current_lang . '.js';

        if (file_exists($locale_path)) {
            if (!wp_script_is('mhb-flatpickr-' . $current_lang, 'registered')) {
                wp_register_script(
                    'mhb-flatpickr-' . $current_lang,
                    MHB_PLUGIN_URL . 'assets/js/vendor/flatpickr.' . $current_lang . '.js',
                    ['mhb-flatpickr-js'],
                    '4.6.13',
                    true
                );
            }
            if (!wp_script_is('mhb-flatpickr-' . $current_lang, 'enqueued')) {
                wp_enqueue_script('mhb-flatpickr-' . $current_lang);
            }
        } elseif (strlen($current_lang) > 2) {
            // Try 2-letter code if full locale (e.g. pt_BR -> pt) not found
            $short_lang = substr($current_lang, 0, 2);
            $locale_path = MHB_PLUGIN_DIR . 'assets/js/vendor/flatpickr.' . $short_lang . '.js';
            if (file_exists($locale_path)) {
                if (!wp_script_is('mhb-flatpickr-' . $short_lang, 'registered')) {
                    wp_register_script(
                        'mhb-flatpickr-' . $short_lang,
                        MHB_PLUGIN_URL . 'assets/js/vendor/flatpickr.' . $short_lang . '.js',
                        ['mhb-flatpickr-js'],
                        '4.6.13',
                        true
                    );
                }
                if (!wp_script_is('mhb-flatpickr-' . $short_lang, 'enqueued')) {
                    wp_enqueue_script('mhb-flatpickr-' . $short_lang);
                }
                // Update current_lang passed to JS to match the loaded locale
                $current_lang = $short_lang;
            }
        }

        // Add localization data for the calendar script (only once)
        if (!wp_script_is('mhb-calendar-js', 'done')) {
            wp_localize_script('mhb-calendar-js', 'mhb_calendar', [
                'rest_url' => get_rest_url(null, 'mhb/v1/calendar-data'),
                'nonce' => wp_create_nonce('wp_rest'),
                'settings' => [
                    'currency_symbol' => get_option('mhb_currency_symbol', '$'),
                    'currency_pos' => get_option('mhb_currency_position', 'before'),
                    'currency_decimals' => apply_filters('mhb_currency_decimals', 0),
                    'currency_space_before' => 0,
                    'currency_space_after' => 0,
                    'checkin_time' => get_option('mhb_checkin_time', '14:00'),
                    'checkout_time' => get_option('mhb_checkout_time', '11:00'),
                ],
                'i18n' => [
                    'your_selection' => I18n::get_label('label_your_selection'),
                    'check_in' => I18n::get_label('label_check_in'),
                    'check_out' => I18n::get_label('label_check_out'),
                    'total' => I18n::get_label('label_total'),
                    'select_checkout' => I18n::get_label('label_select_check_out'),
                    'dates_selected' => I18n::get_label('label_dates_selected'),
                    'select_dates_error' => I18n::get_label('label_select_dates_error'),
                    'check_in_from' => I18n::get_label('label_check_in_from'),
                    'check_out_by' => I18n::get_label('label_check_out_by'),
                    'continue_booking' => I18n::get_label('label_continue_booking'),
                ],
                'current_lang' => $current_lang
            ]);
        }
    }

    public function load_assets()
    {
        /** @var \WP_Post $post */
        global $post;
        $has_shortcode = is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'mhb_room_calendar');
        $has_block = is_a($post, 'WP_Post') && has_block('modern-hotel-booking/room-calendar', $post->post_content);

        if (!$has_shortcode && !$has_block) {
            return;
        }

        self::enqueue_assets();
    }

    private static $calendar_instance_rendered = false;

    public function render_shortcode($atts)
    {
        $atts = shortcode_atts(['room_id' => 0], $atts);
        $room_id = absint($atts['room_id']);

        if (!$room_id) {
            return '<p>' . esc_html(I18n::get_label('label_calendar_no_id')) . '</p>';
        }

        return self::render_unified_view($room_id);
    }

    /**
     * Render the unified calendar view (used by Shortcode, Widget, and Block).
     * 
     * @param int $room_id Room ID to display (0 for aggregated view).
     * @return string HTML content.
     */
    public static function render_unified_view($room_id = 0)
    {
        // Ensure assets are loaded
        self::enqueue_assets();

        ob_start();
        // Flag to control whether pricing should be shown in the calendar UI.
        // Room-specific calendars (room_id > 0) show prices, aggregated views (room_id = 0) do not.
        $show_pricing = ($room_id > 0);
        ?>
        <div class="mhb-calendar-container mhb-calendar-wrapper" data-room-id="<?php echo esc_attr((string) $room_id); ?>"
            data-show-price="<?php echo $show_pricing ? '1' : '0'; ?>">
            <div class="mhb-calendar-guide">
                <?php echo esc_html(I18n::get_label('label_select_check_in')); ?>
            </div>

            <!-- Booking Status Legend -->
            <div class="mhb-calendar-legend">
                <div class="mhb-legend-item">
                    <span class="mhb-legend-color mhb-legend-confirmed"></span>
                    <span class="mhb-legend-text"><?php echo esc_html(I18n::get_label('label_legend_confirmed')); ?></span>
                </div>
                <div class="mhb-legend-item">
                    <span class="mhb-legend-color mhb-legend-pending"></span>
                    <span class="mhb-legend-text"><?php echo esc_html(I18n::get_label('label_legend_pending')); ?></span>
                </div>
            </div>

            <div class="mhb-calendar-inline"></div>

            <div class="mhb-selection-box" style="display:none;">
                <div class="mhb-selection-header">
                    <h3><?php echo esc_html(I18n::get_label('label_your_selection')); ?></h3>
                </div>

                <div class="mhb-selection-details">
                    <div class="mhb-selection-dates">
                        <div class="mhb-selection-item">
                            <span class="mhb-label"><?php echo esc_html(I18n::get_label('label_check_in')); ?></span>
                            <span class="mhb-value mhb-display-check-in">-</span>
                            <span
                                class="mhb-time-info mhb-check-in-time"><?php echo esc_html(sprintf(I18n::get_label('label_check_in_from'), esc_html(get_option('mhb_checkin_time', '14:00')))); ?></span>
                        </div>
                        <div class="mhb-selection-item">
                            <span class="mhb-label"><?php echo esc_html(I18n::get_label('label_check_out')); ?></span>
                            <span class="mhb-value mhb-display-check-out">-</span>
                            <span
                                class="mhb-time-info mhb-check-out-time"><?php echo esc_html(sprintf(I18n::get_label('label_check_out_by'), esc_html(get_option('mhb_checkout_time', '11:00')))); ?></span>
                        </div>
                    </div>

                    <?php if ($show_pricing): ?>
                        <div class="mhb-selection-price">
                            <span class="mhb-label"><?php echo esc_html(I18n::get_label('label_total_starting_from')); ?></span>
                            <span class="mhb-price-value mhb-display-price">-</span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php
                // Build the action URL for the booking form submission
                $action_url = I18n::decode(get_option('mhb_booking_page_url'), null, false);
                if (!$action_url) {
                    $b_page_id = get_option('mhb_booking_page');
                    if ($b_page_id) {
                        $action_url = get_permalink($b_page_id);
                    }
                }
                if (!$action_url) {
                    $action_url = home_url('/');
                }

                // Button label depends on context
                $btn_label = ($room_id > 0) ? I18n::get_label('btn_book_now') : I18n::get_label('btn_search_rooms');
                ?>
                <form action="<?php echo esc_url($action_url); ?>" method="get" class="mhb-selection-form">
                    <input type="hidden" name="room_id" value="<?php echo esc_attr((string) $room_id); ?>">
                    <input type="hidden" name="check_in" class="mhb-cal-check-in">
                    <input type="hidden" name="check_out" class="mhb-cal-check-out">
                    <!-- For aggregated view, total price is just an estimate/starting from -->
                    <input type="hidden" name="total_price" class="mhb-cal-total-price">
                    <input type="hidden" name="mhb_auto_book" value="1">
                    <button type="button" style="position:relative; z-index:10;"
                        class="mhb-btn-primary mhb-booking-btn-submit"><?php echo esc_html($btn_label); ?></button>
                </form>
            </div>

            <?php if (get_option('mhb_powered_by_link', 1)): ?>
                <div class="mhb-powered-by" style="text-align: right; margin-top: 10px; font-size: 11px; opacity: 0.7;">
                    <a href="https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/" target="_blank"
                        rel="noopener noreferrer" style="color: inherit; text-decoration: none;">
                        Powered by <strong>Modern Hotel Booking</strong>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
