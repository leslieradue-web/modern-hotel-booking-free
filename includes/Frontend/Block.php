<?php declare(strict_types=1);

namespace MHBO\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\I18n;
use MHBO\Business\Shortcodes as BusinessShortcodes;

/**
 * Handles Gutenberg Block registration for 2026 compliance.
 */
class Block {

	/**
	 * Initialize block hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'block_categories_all', array( $this, 'add_block_category' ), 10, 2 );
		add_action( 'init', array( $this, 'register_frontend_assets' ), 5 );
		add_action( 'init', array( $this, 'register_blocks' ), 25 );
		
		$bindings = new BlockBindings();
		$bindings->init();
	}

	/**
	 * Add Modern Hotel Booking category to the block inserter.
	 *
	 * @param array<int, array<string, mixed>> $categories Existing categories.
	 * @param mixed                            $post Current post.
	 * @return array<int, array<string, mixed>> Updated categories.
	 */
	public function add_block_category( array $categories, $post ): array {
		return array_merge(
			$categories,
			array(
				array(
					'slug'  => 'hotel-booking',
					'title' => I18n::get_label( 'label_block_business_info' ),
					'icon'  => 'admin-home',
				),
			)
		);
	}

	/**
	 * Register frontend scripts and styles for block.json viewScript/style references.
	 *
	 * @return void
	 */
	public function register_frontend_assets(): void {
		// Register Flatpickr CSS
		wp_register_style(
			'mhbo-flatpickr-css',
			MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
			array(),
			'4.6.13'
		);

		// Register MHBO base style
		wp_register_style(
			'mhbo-style',
			MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css',
			array(),
			MHBO_VERSION
		);

		// Register Calendar CSS
		wp_register_style(
			'mhbo-calendar-style',
			MHBO_PLUGIN_URL . 'assets/css/mhbo-calendar.css',
			array( 'mhbo-flatpickr-css', 'mhbo-style' ),
			MHBO_VERSION
		);

		// Register Flatpickr JS
		wp_register_script(
			'mhbo-flatpickr-js',
			MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
			array(),
			'4.6.13',
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		// Register Calendar JS
		wp_register_script(
			'mhbo-calendar-js',
			MHBO_PLUGIN_URL . 'assets/js/mhbo-calendar.js',
			array( 'mhbo-flatpickr-js' ),
			MHBO_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		// 2026 Best Practice: Register Script Modules for viewScriptModule support
		if ( function_exists( 'wp_register_script_module' ) ) {
			// Register Vendor Modules (Shimmed if necessary)
			wp_register_script_module(
				'mhbo-flatpickr-module',
				MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
				array(),
				'4.6.13'
			);

		}
	}

	/**
	 * Register all 7 blocks using metadata.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		$blocks = array(
			'booking-form'    => 'render_booking_block',
			'room-calendar'   => 'render_calendar_block',
			'company-info'    => 'render_company_block',
			'whatsapp-button' => 'render_whatsapp_block',
			'banking-details' => 'render_banking_block',
			'revolut-details' => 'render_revolut_block',
			'business-card'   => 'render_card_block',
			'ai-chat-widget'  => 'render_chat_widget_block',
		);

		foreach ( $blocks as $slug => $callback ) {
			$path = MHBO_PLUGIN_DIR . 'assets/block/' . $slug;
			
			if ( ! file_exists( $path . '/block.json' ) ) {
				continue;
			}

			// Register using block.json - WP 6.5+ handles most things from here
			$block = register_block_type(
				$path,
				array(
					'render_callback' => array( $this, $callback ),
				)
			);

			// Set translations for the editor script if present.
			// WP 6.7 BP: guard with has_translation() before calling wp_set_script_translations().
			if ( $block && property_exists( $block, 'editor_script' ) && '' !== ( $block->editor_script ?? '' ) && is_string( $block->editor_script ) ) {
				if ( ! \function_exists( 'has_translation' ) || \has_translation( 'modern-hotel-booking' ) ) {
					wp_set_script_translations( $block->editor_script, 'modern-hotel-booking', MHBO_PLUGIN_DIR . 'languages' );
				}
			}

			// 2026 BP: Set translations for viewScriptModule (WP 6.7+ supports wp_set_script_translations
			// for Script Module IDs, not just classic script handles).
			// viewScriptModule may be a string OR an array — iterate safely.
			if ( $block && property_exists( $block, 'view_script_module' ) && array() !== (array) ( $block->view_script_module ?? array() ) ) {
				$modules = is_array( $block->view_script_module )
					? $block->view_script_module
					: array( $block->view_script_module );

				$has_trans = ! \function_exists( 'has_translation' ) || \has_translation( 'modern-hotel-booking' );

				if ( $has_trans ) {
					foreach ( $modules as $module_id ) {
						if ( is_string( $module_id ) && '' !== $module_id ) {
							wp_set_script_translations( $module_id, 'modern-hotel-booking', MHBO_PLUGIN_DIR . 'languages' );
						}
					}
				}
			}
		}
	}

	/* ---- Render Callbacks (Dynamic) ---- */

	/**
	 * Safely coerce a block attribute to a bounded scalar string.
	 * Prevents PHP 8+ TypeError Stored DoS when malformed nested/array block attributes
	 * are parsed from post_content (WP 7.1.4 block/interactivity hardening).
	 *
	 * @param mixed  $value   Raw attribute value.
	 * @param string $default Default fallback string.
	 * @param int    $max_len Maximum character length.
	 * @return string
	 */
	private static function scalar_str( mixed $value, string $default = '', int $max_len = 500 ): string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return $default;
		}
		return substr( (string) $value, 0, $max_len );
	}

	/**
	 * Safely coerce a block attribute to a non-negative integer.
	 *
	 * @param mixed $value Raw attribute value.
	 * @return int
	 */
	private static function scalar_int( mixed $value ): int {
		return ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) )
			? absint( $value )
			: 0;
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render_booking_block( $attributes ): string {
		$raw_id  = $attributes['roomId'] ?? $attributes['room_id'] ?? $attributes['id'] ?? 0;
		$room_id = self::scalar_int( $raw_id );
		return do_shortcode( sprintf( '[modern_hotel_booking room_id="%d"]', $room_id ) );
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render_calendar_block( $attributes ): string {
		$raw_id  = $attributes['roomId'] ?? $attributes['room_id'] ?? $attributes['id'] ?? 0;
		$room_id = self::scalar_int( $raw_id );
		if ( ! $room_id ) {
			return '<div class="mhbo-block-error">' . esc_html( I18n::get_label( 'label_block_no_room' ) ) . '</div>';
		}
		return do_shortcode( sprintf( '[mhbo_room_calendar room_id="%d"]', $room_id ) );
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render_company_block( $attributes ): string {
		return BusinessShortcodes::get_instance()->render_company_info(
			array(
				'show_logo'         => ! empty( $attributes['showLogo'] ?? true ) ? 'yes' : 'no',
				'show_address'      => ! empty( $attributes['showAddress'] ?? true ) ? 'yes' : 'no',
				'show_contact'      => ! empty( $attributes['showContact'] ?? true ) ? 'yes' : 'no',
				'show_registration' => ! empty( $attributes['showRegistration'] ?? true ) ? 'yes' : 'no',
				'layout'            => self::scalar_str( $attributes['layout'] ?? 'default', 'default', 64 ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render_whatsapp_block( $attributes ): string {
		return BusinessShortcodes::get_instance()->render_whatsapp(
			array(
				'style'   => self::scalar_str( $attributes['style'] ?? 'button', 'button', 64 ),
				'text'    => self::scalar_str( $attributes['text'] ?? '', '', 200 ),
				'message' => self::scalar_str( $attributes['message'] ?? '', '', 500 ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render_banking_block( $attributes ): string {
		return BusinessShortcodes::get_instance()->render_banking(
			array(
				'show_instructions' => ! empty( $attributes['showInstructions'] ?? true ) ? 'yes' : 'no',
				'layout'            => self::scalar_str( $attributes['layout'] ?? 'default', 'default', 64 ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render_revolut_block( $attributes ): string {
		return BusinessShortcodes::get_instance()->render_revolut(
			array(
				'show_qr'   => ! empty( $attributes['showQR'] ?? true ) ? 'yes' : 'no',
				'show_link' => ! empty( $attributes['showLink'] ?? true ) ? 'yes' : 'no',
				'layout'    => self::scalar_str( $attributes['layout'] ?? 'default', 'default', 64 ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render_card_block( $attributes ): string {
		$raw_sections = $attributes['sections'] ?? 'company,whatsapp,banking,revolut';
		if ( is_array( $raw_sections ) ) {
			$scalar_items = array_filter( $raw_sections, 'is_scalar' );
			$sections_str = implode( ',', array_map( 'strval', array_slice( $scalar_items, 0, 10 ) ) );
		} else {
			$sections_str = self::scalar_str( $raw_sections, 'company,whatsapp,banking,revolut', 120 );
		}
		return BusinessShortcodes::get_instance()->render_business_card(
			array(
				'sections' => $sections_str,
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render_chat_widget_block( $attributes ): string {
		$raw_pos  = self::scalar_str( $attributes['position'] ?? 'bottom-right', 'bottom-right', 64 );
		$position = sanitize_html_class( $raw_pos, 'bottom-right' );
		$welcome  = esc_attr( self::scalar_str( $attributes['welcomeMessage'] ?? '', '', 500 ) );
		return sprintf( '<div class="mhbo-chat-widget" data-position="%s" data-welcome-message="%s"></div>', $position, $welcome );
	}
}
