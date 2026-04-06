<?php
declare(strict_types=1);

/**
 * Business Blocks Integration
 *
 * Registers Gutenberg blocks for the Business Information module.
 * Namespace-aligned with PSR-4 autoloader.
 *
 * @package ModernHotelBooking
 * @since   2.1.0
 */

namespace MHBO\Business;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blocks {

    /** @var self|null */
    private static $instance = null;

    /** @return self */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'register_blocks' ), 25 );
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
        add_filter( 'block_categories_all', array( $this, 'add_block_category' ), 10, 2 );
    }

    /**
     * Register dynamic blocks.
     */
    public function register_blocks(): void {
        $blocks = array(
            'company-info'    => 'render_company_block',
            'whatsapp-button' => 'render_whatsapp_block',
            'banking-details' => 'render_banking_block',
            'revolut-details' => 'render_revolut_block',
            'business-card'   => 'render_card_block',
        );

        foreach ( $blocks as $slug => $callback ) {
            register_block_type( 'mhbo/' . $slug, array(
                'render_callback' => array( $this, $callback ),
                'attributes'      => $this->get_block_attributes( $slug ),
            ) );
        }
    }

    /**
     * Enqueue block editor assets.
     */
    public function enqueue_block_editor_assets(): void {
        wp_enqueue_script(
            'mhbo-blocks-editor',
            MHBO_PLUGIN_URL . 'assets/js/mhbo-blocks-editor.js',
            array( 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-editor', 'wp-components', 'wp-server-side-render' ),
            '2.1.0',
            true
        );

        // Pass defaults/settings to editor if needed
        wp_localize_script( 'mhbo-blocks-editor', 'mhboBusiness', array(
            'logoUrl' => MHBO_PLUGIN_URL . 'assets/images/placeholder-logo.png',
        ) );
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @param \WP_Block_Editor_Context $context
     * @return array<int, array<string, mixed>>
     */
    public function add_block_category( $categories, $context ): array {
        return array_merge(
            $categories,
            array(
                array(
                    'slug'  => 'mhbo-hotel',
                    'title' => __( 'Modern Hotel Booking', 'modern-hotel-booking' ),
                    'icon'  => 'admin-home',
                ),
            )
        );
    }

    /**
     * @param string $slug
     * @return array<string, array<string, mixed>>
     */
    private function get_block_attributes( string $slug ): array {
        switch ( $slug ) {
            case 'company-info':
                return array(
                    'showLogo'         => array( 'type' => 'boolean', 'default' => true ),
                    'showAddress'      => array( 'type' => 'boolean', 'default' => true ),
                    'showContact'      => array( 'type' => 'boolean', 'default' => true ),
                    'showRegistration' => array( 'type' => 'boolean', 'default' => false ),
                    'layout'           => array( 'type' => 'string',  'default' => 'vertical' ),
                );
            case 'whatsapp-button':
                return array(
                    'style'   => array( 'type' => 'string', 'default' => 'button' ),
                    'text'    => array( 'type' => 'string', 'default' => '' ),
                    'message' => array( 'type' => 'string', 'default' => '' ),
                );
            case 'banking-details':
                return array(
                    'showInstructions' => array( 'type' => 'boolean', 'default' => true ),
                    'layout'           => array( 'type' => 'string',  'default' => 'card' ),
                );
            case 'revolut-details':
                return array(
                    'showQR'   => array( 'type' => 'boolean', 'default' => true ),
                    'showLink' => array( 'type' => 'boolean', 'default' => true ),
                    'layout'   => array( 'type' => 'string',  'default' => 'card' ),
                );
            case 'business-card':
                return array(
                    'sections' => array( 'type' => 'string', 'default' => 'company,whatsapp,banking,revolut' ),
                );
        }
        return array();
    }

    /**
     * @param array<string, mixed> $attributes
     * @return string
     */
    public function render_company_block( array $attributes ): string {
        return Shortcodes::get_instance()->render_company_info( array(
            'show_logo'         => $attributes['showLogo'] ? 'yes' : 'no',
            'show_address'      => $attributes['showAddress'] ? 'yes' : 'no',
            'show_contact'      => $attributes['showContact'] ? 'yes' : 'no',
            'show_registration' => $attributes['showRegistration'] ? 'yes' : 'no',
            'layout'            => $attributes['layout'],
        ) );
    }

    /**
     * @param array<string, mixed> $attributes
     * @return string
     */
    public function render_whatsapp_block( array $attributes ): string {
        return Shortcodes::get_instance()->render_whatsapp( array(
            'style'   => $attributes['style'],
            'text'    => $attributes['text'],
            'message' => $attributes['message'],
        ) );
    }

    /**
     * @param array<string, mixed> $attributes
     * @return string
     */
    public function render_banking_block( array $attributes ): string {
        return Shortcodes::get_instance()->render_banking( array(
            'show_instructions' => $attributes['showInstructions'] ? 'yes' : 'no',
            'layout'            => $attributes['layout'],
        ) );
    }

    /**
     * @param array<string, mixed> $attributes
     * @return string
     */
    public function render_revolut_block( array $attributes ): string {
        return Shortcodes::get_instance()->render_revolut( array(
            'show_qr'   => $attributes['showQR'] ? 'yes' : 'no',
            'show_link' => $attributes['showLink'] ? 'yes' : 'no',
            'layout'    => $attributes['layout'],
        ) );
    }

    /**
     * @param array<string, mixed> $attributes
     * @return string
     */
    public function render_card_block( array $attributes ): string {
        return Shortcodes::get_instance()->render_business_card( array(
            'sections' => $attributes['sections'],
        ) );
    }
}
