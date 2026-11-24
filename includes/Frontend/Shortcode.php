<?php declare(strict_types=1);

namespace MHBO\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\I18n;
use MHBO\Core\Pricing;
use MHBO\Core\Tax;
use MHBO\Core\Security;
use MHBO\Core\HotelTime;
use MHBO\Core\Capabilities;

/**
 * Shortcode Class
 *
 * Handles frontend shortcodes and UI rendering for modern-hotel-booking.
 */
class Shortcode {

	/**
	 * Flag indicating if frontend theme styles have been injected.
	 *
	 * @var bool
	 */
	private static bool $theme_styles_injected = false;

	/**
	 * Flag indicating if frontend assets have been localized.
	 *
	 * @var bool
	 */
	private static bool $frontend_localized = false;

	/**
	 * Flag indicating if frontend assets have been enqueued.
	 *
	 * @var bool
	 */
	private static bool $assets_enqueued = false;

	/**
	 * Initialize shortcodes and actions.
	 *
	 * @return void
	 */
	public function init(): void {
		// Primary shortcode
		add_shortcode( 'mhbo_booking_form', array( $this, 'render_shortcode' ) );
		// Backward compatibility
		add_shortcode( 'modern_hotel_booking', array( $this, 'render_shortcode' ) );
		
		// Elementor Room Grid shortcode
		add_shortcode( 'mhbo_rooms', array( $this, 'render_rooms_grid_shortcode' ) );

		// Guest Self-Service Portal shortcode
		add_shortcode( 'mhbo_guest_portal', array( $this, 'render_guest_portal_shortcode' ) );

add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'handle_early_redirects' ) );

		// SECURITY: Handle booking submissions immediately.
		// CRITICAL FIX: This code runs inside init priority 20 (via Plugin::run()).
		// Using add_action('init', ..., 10) here would NEVER fire because priority 10
		// has already passed. We must call these directly.
		$this->handle_form_submissions();

}

	/**
	 * Lazy-initialized checkout & redirect handler.
	 *
	 * @var CheckoutReturnHandler|null
	 */
	private ?CheckoutReturnHandler $checkout_handler = null;

	/**
	 * Lazy-initialized booking form view renderer.
	 *
	 * @var BookingFormView|null
	 */
	private ?BookingFormView $form_view = null;

	/**
	 * Get the checkout return & submission handler instance.
	 *
	 * @return CheckoutReturnHandler
	 */
	private function get_checkout_handler(): CheckoutReturnHandler {
		if ( null === $this->checkout_handler ) {
			$this->checkout_handler = new CheckoutReturnHandler( $this, $this->get_form_view() );
		}
		return $this->checkout_handler;
	}

	/**
	 * Get the booking form view renderer instance.
	 *
	 * @return BookingFormView
	 */
	private function get_form_view(): BookingFormView {
		if ( null === $this->form_view ) {
			$this->form_view = new BookingFormView( $this );
		}
		return $this->form_view;
	}

	/**
	 * Execute GET/POST PRG redirects before headers are sent on template_redirect.
	 *
	 * @return void
	 */
	public function handle_early_redirects(): void {
		$this->get_checkout_handler()->handle_early_redirects();
	}

/**
	 * Entry point for standard POST form submissions.
	 *
	 * @return void
	 */
	public function handle_form_submissions(): void {
		$this->get_checkout_handler()->handle_form_submissions();
	}

	/**
	 * Register and enqueue core booking styles, Flatpickr, frontend JS, and Pro checkout scripts.
	 *
	 * @param bool $include_calendar Whether to also enqueue calendar assets.
	 */
	private static function enqueue_booking_core_assets( bool $include_calendar = true ): void {
		if ( ! wp_style_is( 'mhbo-google-fonts-inter', 'registered' ) ) {
			wp_register_style(
				'mhbo-google-fonts-inter',
				\MHBO\Core\Plugin::get_font_url( 'inter' ),
				array(),
				\MHBO\Core\Plugin::asset_ver( 'assets/fonts/inter.css' )
			);
		}
		if ( ! wp_style_is( 'mhbo-google-fonts-inter', 'enqueued' ) ) {
			wp_enqueue_style( 'mhbo-google-fonts-inter' );
		}

		if ( ! wp_style_is( 'mhbo-style', 'registered' ) ) {
			wp_register_style(
				'mhbo-style',
				\MHBO\Core\Plugin::asset_url( 'assets/css/mhbo-style.css' ),
				array( 'mhbo-google-fonts-inter' ),
				\MHBO\Core\Plugin::asset_ver( 'assets/css/mhbo-style.css' )
			);
		}
		if ( ! wp_style_is( 'mhbo-style', 'enqueued' ) ) {
			wp_enqueue_style( 'mhbo-style' );
		}

		// Enqueue flatpickr first since frontend script depends on it.
		if ( ! wp_style_is( 'mhbo-flatpickr-css', 'registered' ) ) {
			wp_register_style( 'mhbo-flatpickr-css', MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css', array(), '4.6.13' );
		}
		if ( ! wp_style_is( 'mhbo-flatpickr-css', 'enqueued' ) ) {
			wp_enqueue_style( 'mhbo-flatpickr-css' );
		}

		$defer_args = array(
			'strategy'  => 'defer',
			'in_footer' => true,
		);
		if ( ! wp_script_is( 'mhbo-flatpickr-js', 'registered' ) ) {
			wp_register_script(
				'mhbo-flatpickr-js',
				MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
				array(),
				'4.6.13',
				$defer_args
			);
		}
		if ( ! wp_script_is( 'mhbo-flatpickr-js', 'enqueued' ) ) {
			wp_enqueue_script( 'mhbo-flatpickr-js' );
		}

		if ( ! wp_script_is( 'mhbo-frontend', 'enqueued' ) ) {
			wp_enqueue_script(
				'mhbo-frontend',
				\MHBO\Core\Plugin::asset_url( 'assets/js/mhbo-frontend.js' ),
				array( 'mhbo-flatpickr-js' ),
				\MHBO\Core\Plugin::asset_ver( 'assets/js/mhbo-frontend.js' ),
				$defer_args
			);
		}

		if ( $include_calendar && class_exists( 'MHBO\Frontend\Calendar' ) ) {
			Calendar::enqueue_assets();
		}

		self::localize_frontend_assets();

		if ( ! wp_script_is( 'mhbo-booking-form', 'enqueued' ) ) {
			wp_enqueue_script(
				'mhbo-booking-form',
				\MHBO\Core\Plugin::asset_url( 'assets/js/mhbo-booking-form.js' ),
				array( 'mhbo-frontend' ),
				\MHBO\Core\Plugin::asset_ver( 'assets/js/mhbo-booking-form.js' ),
				$defer_args
			);
		}

		// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine

// Inject theme styles after all stylesheets (including deposit-checkout) are enqueued.
		self::inject_theme_styles();
	}

/**
	 * Ensure assets are loaded (late enqueue fallback for widgets/templates).
	 * 
	 * This method is called during shortcode rendering to ensure assets
	 * are loaded even when the shortcode is used in widgets, footers, or
	 * other areas not checked by has_shortcode().
	 */
	public function ensure_assets_loaded(): void {
		if ( self::$assets_enqueued ) {
			return;
		}
		self::$assets_enqueued = true;
		self::enqueue_booking_core_assets( true );
	}

	/**
	 * Localize frontend script with required variables (shared by shortcode and block/modal paths).
	 * 
	 * @return void
	 */
	public static function localize_frontend_assets(): void {
		if ( self::$frontend_localized || wp_script_is( 'mhbo-frontend', 'done' ) ) {
			return;
		}
		self::$frontend_localized = true;

		$localized_data = array(
			'pay_confirm'                     => I18n::get_label( 'btn_pay_confirm' ),
			'confirm'                         => I18n::get_label( 'btn_confirm_booking' ),
			'processing'                      => I18n::get_label( 'btn_processing' ),
			'loading'                         => I18n::get_label( 'label_loading' ),
			'to'                              => I18n::get_label( 'label_to' ),
			'rest_url'                        => rest_url( 'mhbo/v1/frontend/book' ),
			'api_base'                        => get_rest_url( null, 'mhbo/v1' ),
			'nonce'                           => wp_create_nonce( 'wp_rest' ),
			'prevent_turnover'                => ( class_exists( '\MHBO\Core\Tier' ) && \MHBO\Core\Tier::has_feature( 'prevent_same_day_turnover' ) ) && (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1,
			'label_child_n_age'               => I18n::get_label( 'label_child_n_age' ),
			'label_child_age_placeholder'     => __( 'Age', 'modern-hotel-booking' ),
			'currency_symbol'                 => get_option( 'mhbo_currency_symbol', '$' ),
			'currency_pos'                    => get_option( 'mhbo_currency_position', 'before' ),
			'msg_gdpr_required'               => I18n::get_label( 'msg_gdpr_required' ),
			'msg_terms_required'              => I18n::get_label( 'msg_terms_required' ),
			'msg_paypal_required'             => I18n::get_label( 'msg_paypal_required' ),
			'msg_fill_all_fields'             => I18n::get_label( 'label_fill_all_fields' ),
			'msg_field_required'              => I18n::get_label( 'label_field_required' ),
			'tax_enabled'                     => Tax::is_enabled(),
			'tax_mode'                        => Tax::get_mode(),
			'tax_label'                       => Tax::get_label(),
			'tax_rate_accommodation'          => Tax::get_accommodation_rate(),
			'tax_rate_extras'                 => Tax::get_extras_rate(),
			'checkin_time'                    => get_option( 'mhbo_checkin_time', '14:00' ),
			'checkout_time'                   => get_option( 'mhbo_checkout_time', '11:00' ),
			'auto_nonce'                      => wp_create_nonce( 'mhbo_auto_action' ),
			'nonce_confirm'                   => wp_create_nonce( 'mhbo_confirm_action' ),
			'label_setup_failed'              => I18n::get_label( 'label_setup_failed' ),
			'label_payment_already_confirmed' => I18n::get_label( 'label_payment_already_confirmed' ),
			'label_finalizing'                => I18n::get_label( 'label_finalizing' ),
			'label_gateway_not_ready'         => I18n::get_label( 'label_gateway_not_ready' ),
			'label_payment_success_form_fail' => I18n::get_label( 'label_payment_success_form_fail' ),
			'label_payment_cancelled'         => I18n::get_label( 'label_payment_cancelled' ),
			'label_redirecting'               => I18n::get_label( 'label_redirecting' ),
			'label_loading_payment'           => I18n::get_label( 'label_loading_payment' ),
			'msg_card_details_required'       => I18n::get_label( 'msg_card_details_required' ),
			'label_payment_capture_failed'    => I18n::get_label( 'label_payment_capture_failed' ),
			'label_generic_error'             => I18n::get_label( 'api_err_generic' ),
			'label_network_error'             => I18n::get_label( 'api_err_network' ),
			'inline_modal'                    => (int) get_option( 'mhbo_modal_enabled', 1 ) === 1,
			'locale'                          => get_locale(),
			'language'                        => I18n::get_current_language(),
			'hotel_today'                     => HotelTime::today(),
			'max_advance_days'                => HotelTime::get_max_advance_booking_days(),
			'is_admin'                        => ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && ( current_user_can( 'manage_options' ) || current_user_can( Capabilities::MANAGE_LHBO ) || current_user_can( Capabilities::VIEW_BOOKINGS ) ) ),
		);

		$localized_data = apply_filters( 'mhbo_frontend_localized_data', $localized_data );
		wp_add_inline_script( 'mhbo-frontend', 'var mhbo_vars = ' . wp_json_encode( $localized_data ) . ';' );
	}

	/**
	 * Enqueue frontend assets for the booking form.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( self::$assets_enqueued ) {
			return;
		}

		/**
		 * Global WordPress post instance.
		 *
		 * @var \WP_Post $post
		 */
		global $post;
		$has_shortcode   = is_a( $post, 'WP_Post' ) && (
			has_shortcode( $post->post_content, 'mhbo_booking_form' ) ||
			has_shortcode( $post->post_content, 'modern_hotel_booking' )
		);
		$has_block       = is_a( $post, 'WP_Post' ) && has_block( 'modern-hotel-booking/booking-form', $post->post_content );
		$is_booking_page = is_a( $post, 'WP_Post' ) && self::is_mhbo_booking_page( $post->ID );

		// If not on booking page, no shortcode, and no block, don't enqueue
		if ( ! $has_shortcode && ! $has_block && ! $is_booking_page ) {
			return;
		}

		self::$assets_enqueued = true;
		self::enqueue_booking_core_assets( true );
	}

	/**
	 * Enqueue booking form assets on pages that don't have the shortcode
	 * (e.g. calendar-only pages using the inline modal).
	 * Skips if assets are already loaded via the normal shortcode path.
	 */
	public static function enqueue_for_modal(): void {
		if ( wp_script_is( 'mhbo-booking-form', 'enqueued' ) ) {
			return;
		}

		self::enqueue_booking_core_assets( false );

}

	/**
	 * Render the booking form HTML as a string for the inline modal.
	 * Wraps render_booking_form() output with the modal context wrapper.
	 *
	 * @param array<string, mixed> $params Booking parameters.
	 * @return string HTML string.
	 */
	public function render_booking_form_html( array $params ): string {
		$params['modal_context'] = true;
		ob_start();
		echo '<div class="mhbo-wrapper mhbo-booking-form-wrapper mhbo-modal-form" data-instance-id="modal" data-modal-context="1">';
		$this->get_form_view()->render_booking_form( $params );
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Render the booking confirmation panel as a string for the inline modal.
	 *
	 * @param string $token  The booking_token (reference) value.
	 * @param string $status mhbo_status: 'confirmed', 'pending', or 'failed'.
	 * @return string HTML string, or empty string if token is invalid.
	 */
	public function render_confirmation_html( string $token, string $status ): string {
		return $this->get_form_view()->render_confirmation_html( $token, $status );
	}

	/**
	 * Render booking form HTML directly for an already-selected room/dates context.
	 *
	 * @param array<string, mixed> $params Optional prefilled room/date/guest parameters.
	 * @return void
	 */
	public function render_booking_form( array $params = array() ): void {
		$this->get_form_view()->render_booking_form( $params );
	}

	/**
	 * Instance counter for multiple booking forms on the same page.
	 *
	 * @var int
	 */
	private static int $instance_count = 0;

	/**
	 * Parse a boolean shortcode attribute consistently across string/int inputs.
	 *
	 * @param mixed $value Raw attribute value.
	 * @return bool True for 'yes', '1', 'true', 'on' (case-insensitive), false otherwise.
	 */
	public static function is_truthy_attr( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( ! is_scalar( $value ) && ! ( $value instanceof \Stringable ) ) {
			return false;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( 'yes', '1', 'true', 'on' ), true );
	}

	/**
	 * Render the booking form shortcode.
	 *
	 * @param array<string, mixed> $atts    Shortcode attributes.
	 * @param string|null          $content Shortcode content.
	 * @return string The rendered HTML.
	 */
	public function render_shortcode( array $atts = array(), ?string $content = null ): string {
		global $wpdb;
		unset( $content );
		++self::$instance_count;

		// Late enqueue fallback for widgets/templates
		$this->ensure_assets_loaded();

		$raw_atts = $atts;
		$atts     = shortcode_atts(
			array(
				'room_id'       => 0,
				'type_id'       => 0,
				'show_calendar' => 'no',
				'show_title'    => '',
				'show_badge'    => '',
			),
			$atts,
			'modern_hotel_booking'
		);

		$show_calendar   = self::is_truthy_attr( $atts['show_calendar'] );
		$attr_show_title = Calendar::parse_optional_bool_attr( $raw_atts, 'show_title' );
		$attr_show_badge = Calendar::parse_optional_bool_attr( $raw_atts, 'show_badge' );
		$atts['_attr_show_title'] = $attr_show_title;
		$atts['_attr_show_badge'] = $attr_show_badge;

		ob_start();
		echo '<div class="mhbo-wrapper mhbo-booking-form-wrapper" data-instance-id="' . esc_attr( (string) self::$instance_count ) . '">';
		
		// Show success message if redirected (nonce-secured)
		$nonce_val     = isset( $_GET['mhbo_success_nonce'] ) ? sanitize_key( wp_unslash( $_GET['mhbo_success_nonce'] ) ) : '';
		$success_nonce = $nonce_val;

		$is_success  = isset( $_GET['mhbo_success'] );
		$nonce_valid = false;
		if ( $is_success ) {
			$nonce_valid = wp_verify_nonce( $success_nonce, 'mhbo_success_display' );
			
			// Secure fallback: If nonce verification fails (e.g. due to session/login sync differences 
			// between AJAX and redirect), check if we have a valid unguessable 32-character booking token.
			if ( ! $nonce_valid && isset( $_GET['reference'] ) && '' !== $_GET['reference'] ) {
				$ref = sanitize_text_field( wp_unslash( $_GET['reference'] ) );
				if ( strlen( $ref ) >= 32 && preg_match( '/^[a-zA-Z0-9]{32,64}$/', $ref ) ) {
					$cache_key = 'mhbo_booking_ref_' . md5( $ref );
					$booking   = wp_cache_get( $cache_key, 'mhbo_bookings' );
					if ( false === $booking ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE booking_token = %s", $ref ) );
						if ( $booking ) {
							wp_cache_set( $cache_key, $booking, 'mhbo_bookings', 300 );
						}
					}
					if ( $booking && '' !== (string) ( $booking->booking_token ?? '' ) && hash_equals( (string) $booking->booking_token, $ref ) ) {
						$nonce_valid = true;
					}
				}
			}
		}

		if ( $show_calendar && (int) $atts['room_id'] > 0 && ! $is_success ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is safely constructed and escaped within the Calendar component.
			echo Calendar::render_unified_view( (int) $atts['room_id'], $attr_show_title, (int) $atts['type_id'], $attr_show_badge, true );
		}

		if ( $is_success && $nonce_valid ) {
			if ( ! headers_sent() ) {
				header( 'Referrer-Policy: strict-origin-when-cross-origin' );
			}
			$mhbo_status = isset( $_GET['mhbo_status'] ) ? sanitize_key( wp_unslash( $_GET['mhbo_status'] ) ) : '';
			$booking_id  = isset( $_GET['booking_id'] ) ? absint( wp_unslash( $_GET['booking_id'] ) ) : 0;
			$reference   = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';
			
			$msg_title    = I18n::get_label( 'msg_booking_confirmed' );
			$msg_detail   = I18n::get_label( 'msg_confirmation_sent' );
			$status_class = 'mhbo-status-confirmed';
			$icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
			
			if ( 'pending' === $mhbo_status ) {
				$msg_title    = I18n::get_label( 'msg_booking_confirmed_received' );
				$msg_detail   = I18n::get_label( 'msg_booking_received_pending' );
				$status_class = 'mhbo-status-pending';
				$icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
			} elseif ( 'failed' === $mhbo_status ) {
				$msg_title    = I18n::get_label( 'label_failed' );
				$msg_detail   = I18n::get_label( 'label_payment_capture_failed' );
				$status_class = 'mhbo-status-failed';
				$icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
			}
			
			$message_class = 'mhbo-success-message ' . $status_class;
			
			echo '<div class="' . esc_attr( $message_class ) . '">';
			echo '<div class="mhbo-success-icon">' . $icon_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is hardcoded and safe
			echo '<h3>' . esc_html( $msg_title ) . '</h3>';
			
			// SECURITY: Only look up via the unguessable booking_token (reference). Never by
			// numeric booking_id — that path is IDOR-exploitable since the nonce is not
			// bound to a specific booking and booking IDs are sequential integers.
			if ( '' !== $reference ) {
				$cache_key = 'mhbo_booking_ref_' . md5( $reference );
				$booking   = wp_cache_get( $cache_key, 'mhbo_bookings' );

				if ( false === $booking ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Retrieving booking details for success page display from custom table.
					$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE booking_token = %s", $reference ) );

					if ( $booking ) {
						wp_cache_set( $cache_key, $booking, 'mhbo_bookings', 300 );
					}
				}

				if ( null !== $booking && ( '' === (string) ( $booking->booking_token ?? '' ) || ! hash_equals( (string) $booking->booking_token, $reference ) ) ) {
					$booking = null;
				}

				if ( null !== $booking ) {
					$booking_id = (int) $booking->id;
				}
				
				if ( null !== $booking ) {
					// Update confirmation message with email if available
					if ( $booking->customer_email ) {
						$email_label = ( 'pending' === $mhbo_status )
							? 'msg_pending_sent_to'
							: 'msg_confirmation_sent_to';
						$msg_detail  = sprintf(
							// translators: %s: Customer email address
							I18n::get_label( $email_label ),
							'<strong>' . esc_html( $booking->customer_email ) . '</strong>'
						);
					}

					if ( 'arrival' === $booking->payment_method && 'pending' !== $mhbo_status ) {
						$msg_detail = I18n::get_label( 'msg_booking_received_detail' );
					}
					
					echo '<p>' . wp_kses_post( $msg_detail ) . '</p>';
					echo '<p class="mhbo-reservation-id"><strong>' . esc_html( I18n::get_label( 'label_reservation' ) ) . ':</strong> ' . esc_html( (string) $booking_id ) . '</p>';

					// Stay Details
					$check_in_time  = HotelTime::get_checkin_time();
					$check_out_time = HotelTime::get_checkout_time();

$nights = 0;
					try {
						$start  = new \DateTime( $booking->check_in );
						$end    = new \DateTime( $booking->check_out );
						$nights = $start->diff( $end )->days;
					} catch ( \Exception ) {
						$nights = 0;
					}

					$nights_label = ( $nights === 1 ) ? I18n::get_label( 'label_nights_count_single' ) : sprintf( I18n::get_label( 'label_nights_count' ), $nights );

					echo '<div class="mhbo-stay-details">';
					echo '<div class="mhbo-stay-row">';
					// Check-in
					echo '<div class="mhbo-stay-col">';
					echo '<strong>' . esc_html( I18n::get_label( 'label_check_in' ) ) . '</strong>';
					echo '<div class="mhbo-stay-value">';
					echo '<span class="mhbo-stay-date">' . esc_html( I18n::format_date( $booking->check_in ) ) . '</span> ';
					echo '<span class="mhbo-stay-label">' . esc_html( sprintf( I18n::get_label( 'label_check_in_from' ), '' ) ) . '</span> ';
					echo '<span class="mhbo-stay-time">' . esc_html( $check_in_time ) . '</span>';
					echo '</div></div>';

					// Check-out
					echo '<div class="mhbo-stay-col">';
					echo '<strong>' . esc_html( I18n::get_label( 'label_check_out' ) ) . '</strong>';
					echo '<div class="mhbo-stay-value">';
					echo '<span class="mhbo-stay-date">' . esc_html( I18n::format_date( $booking->check_out ) ) . '</span> ';
					echo '<span class="mhbo-stay-label">' . esc_html( sprintf( I18n::get_label( 'label_check_out_by' ), '' ) ) . '</span> ';
					echo '<span class="mhbo-stay-time">' . esc_html( $check_out_time ) . '</span>';
					echo '</div></div>';

					// Duration
					echo '<div class="mhbo-stay-col">';
					echo '<strong>' . esc_html( I18n::get_label( 'label_nights' ) ) . '</strong>';
					echo '<div class="mhbo-stay-value">' . esc_html( (string) $nights_label ) . '</div>';
					echo '</div>';
					echo '</div>'; // .mhbo-stay-row
					echo '</div>'; // .mhbo-stay-details

					echo BookingFormView::render_confirmation_receipt_details( $booking ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is safely constructed and escaped within render_confirmation_receipt_details()
				}
			} else {
				// Fallback for when booking ID is missing but success flag is present
				echo '<p>' . esc_html( $msg_detail ) . '</p>';
			}

			echo '</div>';
		} else {
			// Show any errors captured during handle_form_submissions redirect
			$user_id   = get_current_user_id();
			$client_ip = Security::get_client_ip();
			$key       = 'mhbo_err_' . ( $user_id ? $user_id : md5( (string) $client_ip ) );
			$error     = get_transient( $key );
			if ( '' !== $error ) {
				echo wp_kses_post( (string) $error );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content already escaped in process_booking branches
				delete_transient( $key );
				// Scroll to error notice via WordPress inline script API.
				if ( function_exists( 'wp_add_inline_script' ) && wp_script_is( 'mhbo-booking-form', 'enqueued' ) ) {
					wp_add_inline_script( 'mhbo-booking-form', 'window.addEventListener("DOMContentLoaded",function(){var err=document.querySelector(".mhbo-error,.mhbo-message.mhbo-error");if(err)err.scrollIntoView({behavior:"smooth",block:"center"});});' );
				} elseif ( function_exists( 'wp_add_inline_script' ) && wp_script_is( 'mhbo-frontend', 'enqueued' ) ) {
					wp_add_inline_script( 'mhbo-frontend', 'window.addEventListener("DOMContentLoaded",function(){var err=document.querySelector(".mhbo-error,.mhbo-message.mhbo-error");if(err)err.scrollIntoView({behavior:"smooth",block:"center"});});' );
				}
			}

			// 2026 BP: Skip fallback rendering if the calendar was already requested for a specific room.
			// This prevents duplicate UI and redundant "Book Now" buttons on single-room pages.
			if ( ! ( $show_calendar && (int) $atts['room_id'] > 0 ) ) {
				$this->get_checkout_handler()->handle_booking_process( $atts );
			}
		}
		
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Inject theme styles based on Pro settings.
	 * Note: Theme styles are applied to all users to ensure CSS variables are defined.
	 *
	 * @return void
	 */
	public static function inject_theme_styles(): void {
		if ( self::$theme_styles_injected ) {
			return;
		}
		self::$theme_styles_injected = true;

		// Free & Starter baseline strictly locks to the original Midnight theme (#1a365d, #f2e2c4, #d4af37).
		$active_theme = 'midnight';
		$primary      = '';
		$secondary    = '';
		$accent       = '';

		$presets = array(
			'midnight' => array( '#1a365d', '#f2e2c4', '#d4af37' ),
			'emerald'  => array( '#065f46', '#34d399', '#10b981' ),
			'oceanic'  => array( '#1e3a8a', '#60a5fa', '#3b82f6' ),
			'ruby'     => array( '#7f1d1d', '#f87171', '#ef4444' ),
			'urban'    => array( '#1f2937', '#9ca3af', '#4b5563' ),
			'lavender' => array( '#4c1d95', '#a78bfa', '#8b5cf6' ),
		);

if ( '' === $primary ) {
			if ( isset( $presets[ $active_theme ] ) ) {
				$primary   = $presets[ $active_theme ][0];
				$secondary = $presets[ $active_theme ][1];
				$accent    = $presets[ $active_theme ][2];
			} else {
				$primary   = $presets['midnight'][0];
				$secondary = $presets['midnight'][1];
				$accent    = $presets['midnight'][2];
			}
		}

		// SECURITY: Validate hex colors before CSS interpolation
		$primary   = sanitize_hex_color( $primary ) ?: '#1a365d';
		$secondary = sanitize_hex_color( $secondary ) ?: '#f2e2c4';
		$accent    = sanitize_hex_color( $accent ) ?: '#d4af37';

		// SECURITY: Using printf for clean CSS variable construction with pre-sanitized values
		$custom_css = sprintf(
			':root, .mhbo-wrapper, .mhbo-calendar-wrapper, .mhbo-booking-form-wrapper, .mhbo-deposit-options-wrapper, .mhbo-success-message, .mhbo-guest-portal-wrapper, .mhbo-modal-overlay, .mhbo-modal-drawer, .mhbo-rooms-grid, .mhbo-room-card, .mhbo-business-card {
                --mhbo-primary: %s !important;
                --mhbo-secondary: %s !important;
                --mhbo-accent: %s !important;
                --mhbo-border: color-mix(in srgb, %s, transparent 85%%) !important;
                --mhbo-glass: color-mix(in srgb, %s, white 90%%) !important;
            }',
			$primary,
			$secondary,
			$accent,
			$primary,
			$primary
		);
		$handles    = array( 'mhbo-style', 'mhbo-calendar-style', 'mhbo-guest-portal', 'mhbo-booking-modal-css' );
		
		foreach ( $handles as $handle ) {
			wp_add_inline_style( $handle, $custom_css );
		}

		// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine
		
	}

	/**
	 * Get the booking page URL (Instance wrapper for static helper).
	 *
	 * @since 2.4.1
	 * @return string
	 */
	public function get_booking_page_url(): string {
		return self::get_checkout_url();
	}

	/**
	 * Remove MHBO specific query arguments from a URL to prevent redirect loops.
	 *
	 * @since 2.4.1
	 * @param string $url The URL to clean.
	 * @return string The cleaned URL.
	 */
	public function remove_mhbo_query_args( string $url ): string {
		return remove_query_arg(
			array(
				'mhbo_auto_book',
				'mhbo_nonce',
				'mhbo_early_checkin',
				'mhbo_late_checkout',
				'mhbo_confirm_booking',
				'mhbo_confirm_nonce',
				'mhbo_payment_return',
				'payment_intent',
				'payment_intent_client_secret',
				'redirect_status',
				'mhbo_modal_return',
				'mhbo_booking',
				'token',
				'mhbo_success',
				'mhbo_success_nonce',
				'mhbo_status',
				'reference',
				'mhbo_error',
			),
			$url
		);
	}

	/**
	 * Validate a date string (Y-m-d).
	 *
	 * @since 2.4.1
	 * @param mixed $value Date string to validate.
	 * @return bool True if valid Y-m-d, false otherwise.
	 */
	public function validate_date( $value ): bool {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		$d = \DateTime::createFromFormat( 'Y-m-d', $value );
		return $d && $d->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Get the URL of the designated booking page or fallback to current.
	 * 
	 * @since 2.4.1 Public Static for AI engine access.
	 * @return string The resolved booking page URL.
	 */
	public static function get_checkout_url(): string {
		if ( class_exists( Calendar::class ) ) {
			return Calendar::resolve_booking_page_url( 0 );
		}

		$booking_page_id  = (int) get_option( 'mhbo_booking_page' );
		$booking_page_url = get_option( 'mhbo_booking_page_url' );

		// 2026 BP: Resolve the translated page ID for Polylang/WPML when no
		// manual URL override is set.  This is the single fix that ensures
		// every PHP redirect, form action, and JS config value points to the
		// language-correct booking page throughout the entire booking flow.
		if ( ( '' === (string) $booking_page_url || false === $booking_page_url ) && $booking_page_id > 0 ) {
			$booking_page_id = I18n::get_translated_page_id( $booking_page_id );
		}

		$url = '';
		if ( is_string( $booking_page_url ) && '' !== $booking_page_url ) {
			$url = $booking_page_url;
		} elseif ( $booking_page_id > 0 ) {
			$permalink = get_permalink( $booking_page_id );
			if ( false !== $permalink ) {
				$url = $permalink;
			}
		}

		if ( '' === (string) $url ) {
			$url = home_url( '/' );
		}

		// 2026 BP: Playground/Local Domain and Path correction.
		// If the current site is being accessed via a different host (e.g. 127.0.0.1:9400 vs playground.wordpress.net),
		// we MUST ensure the checkout URL uses the current host and strip any Playground scope segments to avoid 404 errors.
		$current_host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$current_scheme = ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ) ? 'https://' : 'http://';
		if ( '' !== $current_host && ( str_contains( $url, 'playground.wordpress.net' ) || str_contains( $url, '127.0.0.1' ) ) ) {
			$parsed_url = wp_parse_url( $url );
			if ( isset( $parsed_url['host'] ) && $parsed_url['host'] !== $current_host ) {
				$url = str_replace( $parsed_url['host'], $current_host, $url );
			}
			// Strip Playground scope segment (e.g. /scope:excited-abandoned-ocean/) if present
			if ( str_contains( $url, '/scope:' ) ) {
				$url = (string) preg_replace( '/\/scope:[^\/]+\//', '/', $url );
			}
			// Force scheme to match current to avoid HTTPS errors on 127.0.0.1
			$url = str_replace( array( 'https://', 'http://' ), $current_scheme, $url );
		}

		return esc_url_raw( (string) $url );
	}

	/**
	 * Check if a given page ID is the booking page or a translation of it.
	 *
	 * Supports Polylang and WPML where translated pages have different IDs.
	 * Uses I18n::get_translated_page_id() to resolve language-specific pages.
	 *
	 * @since 2.4.4.2
	 * @param int $page_id The page ID to check.
	 * @return bool
	 */
	public static function is_mhbo_booking_page( int $page_id ): bool {
		$booking_page_id = (int) get_option( 'mhbo_booking_page' );
		if ( $booking_page_id <= 0 ) {
			return false;
		}
		// Direct match (default language or no translation plugin).
		if ( $booking_page_id === $page_id ) {
			return true;
		}
		// 2026 BP: Check if this page is a translation of the booking page.
		// I18n::get_translated_page_id() handles Polylang (pll_get_post),
		// WPML (wpml_object_id), and per-language overrides (mhbo_booking_page_{lang}).
		$translated_id = I18n::get_translated_page_id( $booking_page_id );
		if ( $translated_id === $page_id ) {
			return true;
		}

		// Quad-Core cross-translation array check (Polylang, Bogo, WPML, per-language option overrides)
		if ( function_exists( 'pll_get_post_translations' ) ) {
			// @phpstan-ignore argument.type
			$pll_translations = call_user_func( 'pll_get_post_translations', $booking_page_id );
			if ( is_array( $pll_translations ) && in_array( $page_id, array_map( 'intval', $pll_translations ), true ) ) {
				return true;
			}
		}

		if ( function_exists( 'bogo_get_post_translations' ) ) {
			/**
			 * Bogo translations post mapping.
			 *
			 * @var array<string, \WP_Post> $bogo_translations
			 */
			$bogo_translations = call_user_func( 'bogo_get_post_translations', $booking_page_id );
			if ( is_array( $bogo_translations ) ) {
				foreach ( $bogo_translations as $t_post ) {
					if ( (int) $t_post->ID === $page_id ) {
						return true;
					}
				}
			}
		}

		foreach ( I18n::get_available_languages() as $l ) {
			$lang_opt = (int) get_option( 'mhbo_booking_page_' . $l, 0 );
			if ( $lang_opt > 0 && $lang_opt === $page_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render hidden inputs for all query parameters in a URL.
	 * This is essential for GET forms in WordPress where the action URL's
	 * own query string is stripped by the browser.
	 *
	 * @since 2.3.8
	 * @param string $url The URL to extract parameters from.
	 * @return void Echoes hidden input fields.
	 */
	public static function render_url_hidden_inputs( string $url ): void {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! $query ) {
			return;
		}

		parse_str( $query, $params );
		if ( is_array( $params ) ) {
			foreach ( $params as $key => $val ) {
				if ( is_scalar( $val ) ) {
					echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $val ) . '">';
				}
			}
		}
	}

	/**
	 * Render the mhbo_rooms grid shortcode.
	 *
	 * @param array<string, mixed>|string|false $atts Shortcode attributes.
	 * @return string The rendered HTML.
	 */
	public function render_rooms_grid_shortcode( array|string|false $atts ): string {
		return $this->get_form_view()->render_rooms_grid_shortcode( $atts );
	}

/**
	 * Render the Guest Self-Service Portal shortcode [mhbo_guest_portal].
	 *
	 * @param array<string, mixed>|string|false $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_guest_portal_shortcode( array|string|false $atts ): string {
		return GuestPortalShortcode::render( $atts );
	}
}
