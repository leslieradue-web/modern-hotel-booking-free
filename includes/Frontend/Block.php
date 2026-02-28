<?php declare(strict_types=1);

namespace MHB\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

use MHB\Core\I18n;

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
            'mhb-flatpickr-css',
            MHB_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
            [],
            '4.6.13'
        );

        // Register MHB base style
        wp_register_style(
            'mhb-style',
            MHB_PLUGIN_URL . 'assets/css/mhb-style.css',
            [],
            MHB_VERSION
        );

        // Register Calendar CSS (depends on flatpickr and base style)
        wp_register_style(
            'mhb-calendar-style',
            MHB_PLUGIN_URL . 'assets/css/mhb-calendar.css',
            ['mhb-flatpickr-css', 'mhb-style'],
            MHB_VERSION
        );

        // Register Flatpickr JS
        wp_register_script(
            'mhb-flatpickr-js',
            MHB_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
            [],
            '4.6.13',
            true
        );

        // Register Calendar JS (depends on jQuery and Flatpickr)
        wp_register_script(
            'mhb-calendar-js',
            MHB_PLUGIN_URL . 'assets/js/mhb-calendar.js',
            ['jquery', 'mhb-flatpickr-js'],
            MHB_VERSION,
            true
        );

        // Register locale scripts (conditionally loaded)
        wp_register_script(
            'mhb-flatpickr-ro',
            MHB_PLUGIN_URL . 'assets/js/vendor/flatpickr.ro.js',
            ['mhb-flatpickr-js'],
            '4.6.13',
            true
        );

        wp_register_script(
            'mhb-flatpickr-de',
            MHB_PLUGIN_URL . 'assets/js/vendor/flatpickr.de.js',
            ['mhb-flatpickr-js'],
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
        register_block_type(MHB_PLUGIN_DIR . 'assets/block/booking-form', [
            'render_callback' => [$this, 'render_booking_block'],
        ]);

        // Register Room Calendar using metadata
        register_block_type(MHB_PLUGIN_DIR . 'assets/block/room-calendar', [
            'render_callback' => [$this, 'render_calendar_block'],
        ]);
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
            return '<div class="mhb-block-error">' . esc_html(I18n::get_label('label_block_no_room')) . '</div>';
        }

        return do_shortcode('[mhb_room_calendar room_id="' . $room_id . '"]');
    }
}
