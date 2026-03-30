<?php declare(strict_types=1);

namespace MHBO\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

use MHBO\Core\I18n;

/**
 * Handles Gutenberg Block registration for 2026 compliance.
 */
class Block
{
    /**
     * Initialize block hooks.
     */
    public function init()
    {
        add_action('init', [$this, 'register_frontend_assets'], 5);
        add_action('init', [$this, 'register_blocks']);
    }

    /**
     * Register frontend scripts and styles for block.json viewScript/style references.
     * Must run before register_blocks() so handles are available.
     */
    public function register_frontend_assets()
    {
        // Register Flatpickr CSS
        wp_register_style(
            'mhbo-flatpickr-css',
            MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
            [],
            '4.6.13'
        );

        // Register MHBO base style
        wp_register_style(
            'mhbo-style',
            MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css',
            [],
            MHBO_VERSION
        );

        // Register Calendar CSS (depends on flatpickr and base style)
        wp_register_style(
            'mhbo-calendar-style',
            MHBO_PLUGIN_URL . 'assets/css/mhbo-calendar.css',
            ['mhbo-flatpickr-css', 'mhbo-style'],
            MHBO_VERSION
        );

        // Register Flatpickr JS
        wp_register_script(
            'mhbo-flatpickr-js',
            MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
            [],
            '4.6.13',
            true
        );

        // Register Calendar JS (depends on jQuery and Flatpickr)
        wp_register_script(
            'mhbo-calendar-js',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-calendar.js',
            ['jquery', 'mhbo-flatpickr-js'],
            MHBO_VERSION,
            true
        );

        // Register locale scripts (conditionally loaded)
        wp_register_script(
            'mhbo-flatpickr-ro',
            MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.ro.js',
            ['mhbo-flatpickr-js'],
            '4.6.13',
            true
        );

        wp_register_script(
            'mhbo-flatpickr-de',
            MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.de.js',
            ['mhbo-flatpickr-js'],
            '4.6.13',
            true
        );
    }

    /**
     * Register the blocks.
     */
    public function register_blocks()
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        // Register Booking Form using metadata
        $booking_form_block = register_block_type(MHBO_PLUGIN_DIR . 'assets/block/booking-form', [
            'render_callback' => [$this, 'render_booking_block'],
        ]);

        if ($booking_form_block && !empty($booking_form_block->editor_script)) {
            wp_set_script_translations($booking_form_block->editor_script, 'modern-hotel-booking', MHBO_PLUGIN_DIR . 'languages');
        }

        // Register Room Calendar using metadata
        $room_calendar_block = register_block_type(MHBO_PLUGIN_DIR . 'assets/block/room-calendar', [
            'render_callback' => [$this, 'render_calendar_block'],
        ]);

        if ($room_calendar_block && !empty($room_calendar_block->editor_script)) {
            wp_set_script_translations($room_calendar_block->editor_script, 'modern-hotel-booking', MHBO_PLUGIN_DIR . 'languages');
        }
    }

    /**
     * Server-side render callback for the booking form block.
     * 
     * @param array $attributes Block attributes.
     * @return string Block HTML.
     */
    public function render_booking_block($attributes)
    {
        $room_id = !empty($attributes['roomId']) ? absint($attributes['roomId']) : 0;
        return do_shortcode('[modern_hotel_booking room_id="' . $room_id . '"]');
    }

    /**
     * Server-side render callback for the room calendar block.
     * 
     * @param array $attributes Block attributes.
     * @return string Block HTML.
     */
    public function render_calendar_block($attributes)
    {
        $room_id = !empty($attributes['roomId']) ? absint($attributes['roomId']) : 0;

        if (!$room_id) {
            return '<div class="mhbo-block-error">' . esc_html(I18n::get_label('label_block_no_room')) . '</div>';
        }

        return do_shortcode('[mhbo_room_calendar room_id="' . $room_id . '"]');
    }
}
