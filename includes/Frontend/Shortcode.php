<?php declare(strict_types=1);

namespace MHBO\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Tax;
use MHBO\Core\License;
use MHBO\Core\Security;
use MHBO\Core\Cache;
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
		
		// Phase 5: jQuery Purge. Late priority to check dependencies.
		add_action( 'wp_enqueue_scripts', array( $this, 'purge_jquery' ), 9999 );

// Premium Typography: Standard Google Fonts (Inter) for 2026 aesthetics via enqueue_assets
		add_filter(
			'wp_resource_hints',
			function ( $urls, $relation_type ) {
				if ( 'preconnect' === $relation_type ) {
					$urls[] = 'https://fonts.googleapis.com';
					$urls[] = array(
						'href' => 'https://fonts.gstatic.com',
						'crossorigin',
					);
				}
				return $urls;
			},
			10,
			2
		);

		// SECURITY: Handle booking submissions immediately.
		// CRITICAL FIX: This code runs inside init priority 20 (via Plugin::run()).
		// Using add_action('init', ..., 10) here would NEVER fire because priority 10
		// has already passed. We must call these directly.
		$this->handle_form_submissions();
		
	}

/**
	 * Entry point for standard POST form submissions.
	 * All actions are strictly guarded by mhbo_confirm_action nonces.
	 */
	public function handle_form_submissions(): void {
		// Only act on POST requests that include our form fields
		if ( empty( $_POST ) ) {
			return;
		}

		// 1. Security Check First: Verify nonce if any POST data exists.
		if ( ! isset( $_POST['mhbo_confirm_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mhbo_confirm_nonce'] ) ), 'mhbo_confirm_action' ) ) {
			return;
		}

		// 2. Intent Check: Ensure it's our specific form action.
		if ( ! isset( $_POST['mhbo_confirm_booking'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			return;
		}

		// Proceed with booking

		// SECURITY: Capture output to prevent "headers already sent" during redirect.
		// We use a specific flag to detect REAL validation errors vs benign warnings/notices.
		ob_start();
		$this->process_booking();
		$output = (string) ( ob_get_clean() ?: '' );

		// If we have output AND it contains a specific MHBO error class, then it's a real failure.
		// This makes the process resilient against PHP 8.2+ Deprecation warnings in the buffer.
		$has_real_error = str_contains( $output, 'mhbo-error' );

		// Log unexpected output (PHP errors, warnings) that aren't MHBO validation errors.
		// This prevents silent failures where process_booking() encounters a PHP error but doesn't redirect.
		// Unexpected output that is not an MHBO error indicates a silent PHP/DB failure.

		if ( $has_real_error ) {
			// Store error in transient to show after redirect (valid for 1 minute)
			$user_id   = get_current_user_id();
			$client_ip = Security::get_client_ip();
			$key       = 'mhbo_err_' . ( $user_id ? $user_id : md5( (string) $client_ip ) );
			set_transient( $key, $output, 60 );
			
			// Redirect back to same page but with parameters to restore the booking form state.
			// This prevents the "Redirect Loop" by ensuring the shortcode re-enters the 'Booking Form' stage (Stage 3).
			$referer      = wp_get_referer();
			$redirect_url = $referer ? $referer : $this->get_booking_page_url();

			// Resolve room_id from type_id if it's missing (0) before redirecting back on error
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified at the start of handle_form_submissions.
			$room_id = isset( $_POST['mhbo_room_id'] ) ? absint( wp_unslash( $_POST['mhbo_room_id'] ) ) : ( isset( $_POST['room_id'] ) ? absint( wp_unslash( $_POST['room_id'] ) ) : 0 );
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$type_id = isset( $_POST['mhbo_type_id'] ) ? absint( wp_unslash( $_POST['mhbo_type_id'] ) ) : ( isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0 );
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$check_in = isset( $_POST['check_in'] ) ? sanitize_text_field( wp_unslash( $_POST['check_in'] ) ) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$check_out = isset( $_POST['check_out'] ) ? sanitize_text_field( wp_unslash( $_POST['check_out'] ) ) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$guests = isset( $_POST['guests'] ) ? absint( wp_unslash( $_POST['guests'] ) ) : 1;

			if ( 0 === $room_id && $type_id > 0 && '' !== $check_in && '' !== $check_out ) {
				$resolved = Pricing::find_available_room( $type_id, $check_in, $check_out, $guests );
				if ( $resolved ) {
					$room_id = $resolved;
				}
			}

			$args = array(
				'room_id'        => $room_id,
				'type_id'        => isset( $_POST['mhbo_type_id'] ) ? absint( wp_unslash( $_POST['mhbo_type_id'] ) ) : ( isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0 ),
				'check_in'       => isset( $_POST['check_in'] ) ? sanitize_text_field( wp_unslash( $_POST['check_in'] ) ) : '',
				'check_out'      => isset( $_POST['check_out'] ) ? sanitize_text_field( wp_unslash( $_POST['check_out'] ) ) : '',
				'guests'         => isset( $_POST['guests'] ) ? absint( wp_unslash( $_POST['guests'] ) ) : 1,
				'total_price'    => isset( $_POST['total_price'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['total_price'] ) ) : 0.0,
				'customer_name'  => isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '',
				'customer_email' => isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '',
				'customer_phone' => isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '',
				'admin_notes'    => isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '',
			);

			// Add payment type if present (Stripe/PayPal flows)
			if ( isset( $_POST['mhbo_payment_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified earlier in handle_form_submissions()
				$args['mhbo_payment_type'] = sanitize_key( wp_unslash( $_POST['mhbo_payment_type'] ) );
			}

			// CLEAN REDIRECT: Remove existing parameters to avoid stacking and potential loop triggers
			// This is the "Neural Damper" - we MUST NOT redirect back with mhbo_auto_book enabled on failure.
			$redirect_url = $this->remove_mhbo_query_args( $redirect_url );
			
			// Add error flag to suppress auto-submit on the next load
			$args['mhbo_error'] = 1;
			$redirect_url       = add_query_arg( $args, $redirect_url );
			
			// SECURITY: Ensure we don't carry over the auto-book flag into the next session
			$redirect_url = remove_query_arg( array( 'mhbo_auto_book', 'mhbo_nonce' ), $redirect_url );
			
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Form error output helper for standard POST submissions.
	 *
	 * @param string $html_error_div Full error div HTML.
	 * @return void
	 */
	private function booking_fail( string $html_error_div ): void {
		echo wp_kses_post( $html_error_div ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- content already escaped at call sites
	}

	/**
	 * Form success redirect helper for standard POST submissions.
	 *
	 * @param string $url Fully-qualified redirect URL.
	 * @return void
	 */
	private function booking_done( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Ensure assets are loaded (late enqueue fallback for widgets/templates).
	 * 
	 * This method is called during shortcode rendering to ensure assets
	 * are loaded even when the shortcode is used in widgets, footers, or
	 * other areas not checked by has_shortcode().
	 */
	private function ensure_assets_loaded(): void {
		// Enqueue main styles
		if ( ! wp_style_is( 'mhbo-style', 'enqueued' ) ) {
			wp_enqueue_style( 'mhbo-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css', array(), MHBO_VERSION );
		}

		// Enqueue flatpickr first since frontend script depends on it
		if ( ! wp_style_is( 'mhbo-flatpickr-css', 'enqueued' ) ) {
			wp_enqueue_style(
				'mhbo-flatpickr-css',
				MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
				array(),
				'4.6.13'
			);
		}
		if ( ! wp_script_is( 'mhbo-flatpickr-js', 'enqueued' ) ) {
			wp_enqueue_script(
				'mhbo-flatpickr-js',
				MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
				array(),
				'4.6.13',
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
		}

		// Enqueue frontend script
		if ( ! wp_script_is( 'mhbo-frontend', 'enqueued' ) ) {
			wp_enqueue_script(
				'mhbo-frontend',
				MHBO_PLUGIN_URL . 'assets/js/mhbo-frontend.js',
				array( 'mhbo-flatpickr-js' ),
				MHBO_VERSION,
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
		}

		// Enqueue calendar assets via centralized handler
		if ( class_exists( 'MHBO\Frontend\Calendar' ) ) {
			Calendar::enqueue_assets();
		}

		// Inject theme styles (must be after enqueuing styles)
		self::inject_theme_styles();

		// Enqueue booking form script
		if ( ! wp_script_is( 'mhbo-booking-form', 'enqueued' ) ) {
			wp_enqueue_script(
				'mhbo-booking-form',
				MHBO_PLUGIN_URL . 'assets/js/mhbo-booking-form.js',
				array( 'mhbo-frontend' ),
				MHBO_VERSION,
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
		}

		// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine

// Add localization data (only once)
		self::localize_frontend_assets();
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
			'prevent_turnover'                => (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1,
			'label_child_n_age'               => I18n::get_label( 'label_child_n_age' ),
			'currency_symbol'                 => get_option( 'mhbo_currency_symbol', '$' ),
			'currency_pos'                    => get_option( 'mhbo_currency_position', 'before' ),
			'msg_gdpr_required'               => I18n::get_label( 'msg_gdpr_required' ),
			'msg_paypal_required'             => I18n::get_label( 'msg_paypal_required' ),
			'msg_fill_all_fields'             => I18n::get_label( 'msg_fill_all_fields' ),
			'msg_field_required'              => I18n::get_label( 'msg_field_required' ),
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

		wp_enqueue_style( 'mhbo-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap', array(), MHBO_VERSION );
		wp_enqueue_style( 'mhbo-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css', array( 'mhbo-google-fonts' ), MHBO_VERSION );

		// Enqueue flatpickr first since frontend script depends on it
		wp_enqueue_style(
			'mhbo-flatpickr-css',
			MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css',
			array(),
			'4.6.13'
		);
		wp_enqueue_script(
			'mhbo-flatpickr-js',
			MHBO_PLUGIN_URL . 'assets/js/vendor/flatpickr.min.js',
			array(),
			'4.6.13',
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		// Enqueue frontend script
		wp_enqueue_script(
			'mhbo-frontend',
			MHBO_PLUGIN_URL . 'assets/js/mhbo-frontend.js',
			array( 'mhbo-flatpickr-js' ),
			MHBO_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		// Enqueue calendar assets via centralized handler (with proper dependency chains)
		if ( class_exists( 'MHBO\Frontend\Calendar' ) ) {
			Calendar::enqueue_assets();
		}

		// Inject theme styles (must be after enqueuing styles)
		self::inject_theme_styles();

		// Booking form interactions
		wp_enqueue_script(
			'mhbo-booking-form',
			MHBO_PLUGIN_URL . 'assets/js/mhbo-booking-form.js',
			array( 'mhbo-frontend' ),
			MHBO_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine

// Localize script for JS strings
		self::localize_frontend_assets();
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

		// Main plugin stylesheet — required for the booking form UI.
		if ( ! wp_style_is( 'mhbo-style', 'enqueued' ) ) {
			if ( ! wp_style_is( 'mhbo-style', 'registered' ) ) {
				wp_register_style( 'mhbo-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css', array(), MHBO_VERSION );
			}
			wp_enqueue_style( 'mhbo-style' );
		}

		if ( ! wp_script_is( 'mhbo-flatpickr-js', 'enqueued' ) ) {
			if ( ! wp_script_is( 'mhbo-flatpickr-js', 'registered' ) ) {
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
				wp_register_style( 'mhbo-flatpickr-css', MHBO_PLUGIN_URL . 'assets/css/vendor/flatpickr.min.css', array(), '4.6.13' );
			}
			wp_enqueue_script( 'mhbo-flatpickr-js' );
			wp_enqueue_style( 'mhbo-flatpickr-css' );
		}

		wp_enqueue_script(
			'mhbo-frontend',
			MHBO_PLUGIN_URL . 'assets/js/mhbo-frontend.js',
			array( 'mhbo-flatpickr-js' ),
			MHBO_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		self::localize_frontend_assets();
		wp_enqueue_script(
			'mhbo-booking-form',
			MHBO_PLUGIN_URL . 'assets/js/mhbo-booking-form.js',
			array( 'mhbo-frontend' ),
			MHBO_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

}

	/**
	 * Dynamically deregister jQuery on frontend checkout pages if no third-party plugins require it.
	 */
	public function purge_jquery(): void {
		global $post;
		
		// 1. Only act if we are on a checkout page.
		$has_shortcode = is_a( $post, 'WP_Post' ) && (
			has_shortcode( $post->post_content, 'mhbo_booking_form' ) ||
			has_shortcode( $post->post_content, 'modern_hotel_booking' )
		);
		$has_block     = is_a( $post, 'WP_Post' ) && has_block( 'modern-hotel-booking/booking-form', $post->post_content );
		
		if ( ! $has_shortcode && ! $has_block ) {
			return;
		}

		// 2. Check if any scripts require jQuery.
		$scripts      = wp_scripts();
		$needs_jquery = false;
		
		foreach ( $scripts->queue as $handle ) {
			if ( isset( $scripts->registered[ $handle ] ) && is_array( $scripts->registered[ $handle ]->deps ) ) {
				if ( in_array( 'jquery', $scripts->registered[ $handle ]->deps, true ) || in_array( 'jquery-core', $scripts->registered[ $handle ]->deps, true ) ) {
					$needs_jquery = true;
					break;
				}
			}
		}

		if ( ! $needs_jquery ) {
			wp_deregister_script( 'jquery' );
		}
	}

	/**
	 * Render the booking form HTML as a string for the inline modal.
	 * Wraps render_booking_form() output with the modal context wrapper.
	 *
	 * @param array<string, mixed> $params Booking parameters.
	 * @return string HTML string.
	 */
	public function render_booking_form_html( array $params ): string {
		ob_start();
		echo '<div class="mhbo-wrapper mhbo-booking-form-wrapper mhbo-modal-form" data-instance-id="modal" data-modal-context="1">';
		$this->render_booking_form( $params );
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Render the booking confirmation panel as a string for the inline modal.
	 * Looks up the booking by its unguessable token — no nonce required.
	 *
	 * @param string $token  The booking_token (reference) value.
	 * @param string $status mhbo_status: 'confirmed', 'pending', or 'failed'.
	 * @return string HTML string, or empty string if token is invalid.
	 */
	public function render_confirmation_html( string $token, string $status ): string {
		if ( '' === $token ) {
			return '';
		}

		global $wpdb;

		$cache_key = 'mhbo_booking_ref_' . md5( $token );
		$booking   = wp_cache_get( $cache_key, 'mhbo_bookings' );

		if ( false === $booking ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$booking = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}mhbo_bookings WHERE booking_token = %s",
					$token
				)
			);
			if ( $booking ) {
				wp_cache_set( $cache_key, $booking, 'mhbo_bookings', 300 );
			}
		}

		if ( null === $booking ) {
			return '';
		}

		$msg_title    = I18n::get_label( 'msg_booking_confirmed' );
		$msg_detail   = I18n::get_label( 'msg_confirmation_sent' );
		$status_class = 'mhbo-status-confirmed';
		$icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';

		if ( 'pending' === $status ) {
			$msg_title    = I18n::get_label( 'msg_booking_confirmed_received' );
			$msg_detail   = I18n::get_label( 'msg_booking_received_pending' );
			$status_class = 'mhbo-status-pending';
			$icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
		} elseif ( 'failed' === $status ) {
			$msg_title    = I18n::get_label( 'label_failed' );
			$msg_detail   = I18n::get_label( 'label_payment_capture_failed' );
			$status_class = 'mhbo-status-failed';
			$icon_html    = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
		}

		if ( '' !== (string) ( $booking->customer_email ?? '' ) ) {
			$email_label = ( 'pending' === $status ) ? 'msg_pending_sent_to' : 'msg_confirmation_sent_to';
			$msg_detail  = sprintf(
				// translators: %s: Customer email address
				I18n::get_label( $email_label ),
				'<strong>' . esc_html( $booking->customer_email ) . '</strong>'
			);
		}

		if ( 'arrival' === $booking->payment_method && 'pending' !== $status ) {
			$msg_detail = I18n::get_label( 'msg_booking_received_detail' );
		}

		$booking_id     = (int) $booking->id;
		$check_in_time  = (string) get_option( 'mhbo_checkin_time', '14:00' );
		$check_out_time = (string) get_option( 'mhbo_checkout_time', '11:00' );

$nights = 0;

		try {
			$start  = new \DateTime( $booking->check_in );
			$end    = new \DateTime( $booking->check_out );
			$nights = $start->diff( $end )->days;
		} catch ( \Exception ) {
			$nights = 0;
		}

		$nights_label = ( $nights === 1 )
			? I18n::get_label( 'label_nights_count_single' )
			: sprintf( I18n::get_label( 'label_nights_count' ), $nights );

		ob_start();
		?>
		<div class="mhbo-success-message <?php echo esc_attr( $status_class ); ?>">
			<div class="mhbo-success-icon"><?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG is hardcoded ?></div>
			<h3><?php echo esc_html( $msg_title ); ?></h3>
			<p><?php echo wp_kses_post( $msg_detail ); ?></p>
			<p class="mhbo-reservation-id"><strong><?php echo esc_html( I18n::get_label( 'label_reservation' ) ); ?>:</strong> <?php echo esc_html( (string) $booking_id ); ?></p>

			<div class="mhbo-stay-details">
				<div class="mhbo-stay-row">
					<div class="mhbo-stay-col">
						<strong><?php echo esc_html( I18n::get_label( 'label_check_in' ) ); ?></strong>
						<div class="mhbo-stay-value">
							<span class="mhbo-stay-date"><?php echo esc_html( I18n::format_date( $booking->check_in ) ); ?></span>
							<span class="mhbo-stay-label"><?php echo esc_html( sprintf( I18n::get_label( 'label_check_in_from' ), '' ) ); ?></span>
							<span class="mhbo-stay-time"><?php echo esc_html( $check_in_time ); ?></span>
						</div>
					</div>
					<div class="mhbo-stay-col">
						<strong><?php echo esc_html( I18n::get_label( 'label_check_out' ) ); ?></strong>
						<div class="mhbo-stay-value">
							<span class="mhbo-stay-date"><?php echo esc_html( I18n::format_date( $booking->check_out ) ); ?></span>
							<span class="mhbo-stay-label"><?php echo esc_html( sprintf( I18n::get_label( 'label_check_out_by' ), '' ) ); ?></span>
							<span class="mhbo-stay-time"><?php echo esc_html( $check_out_time ); ?></span>
						</div>
					</div>
					<div class="mhbo-stay-col">
						<strong><?php echo esc_html( I18n::get_label( 'label_nights' ) ); ?></strong>
						<div class="mhbo-stay-value"><?php echo esc_html( (string) $nights_label ); ?></div>
					</div>
				</div>
			</div>

			<?php echo self::render_confirmation_receipt_details( $booking ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is safely constructed and escaped within render_confirmation_receipt_details() ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Instance counter for multiple booking forms on the same page.
	 *
	 * @var int
	 */
	private static int $instance_count = 0;

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

		$atts = shortcode_atts(
			array(
				'room_id'       => 0,
				'show_calendar' => 'no',
			),
			$atts,
			'modern_hotel_booking'
		);

		$show_calendar = ( strtolower( (string) $atts['show_calendar'] ) === 'yes' || (bool) $atts['show_calendar'] );

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
					if ( $booking ) {
						$nonce_valid = true;
					}
				}
			}
		}

		if ( $show_calendar && (int) $atts['room_id'] > 0 && ! $is_success ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is safely constructed and escaped within the Calendar component.
			echo Calendar::render_unified_view( (int) $atts['room_id'] );
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
					$check_in_time  = (string) get_option( 'mhbo_checkin_time', '14:00' );
					$check_out_time = (string) get_option( 'mhbo_checkout_time', '11:00' );

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

					echo self::render_confirmation_receipt_details( $booking ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is safely constructed and escaped within render_confirmation_receipt_details()
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
				$this->handle_booking_process( $atts );
			}
		}
		
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Orchestrate the booking flow based on request parameters.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return void
	 */
	private function handle_booking_process( array $atts = array() ): void {

		$room_id_attr = isset( $atts['room_id'] ) ? absint( $atts['room_id'] ) : 0;

		// 1. Process 'Book Now' from search results (GET form submission)
		if ( isset( $_GET['mhbo_book_room'] ) ) {
			$nonce = isset( $_GET['mhbo_book_now_nonce'] ) ? sanitize_key( wp_unslash( $_GET['mhbo_book_now_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'mhbo_book_now_action' ) ) {
				echo '<div class="mhbo-message mhbo-error">' . esc_html( I18n::get_label( 'label_security_error' ) ) . '</div>';
				return;
			}

			// Extract values and redirect to Stage 3 (GET) to keep URL clean and unique
			$redirect_args = array(
				'mhbo_auto_book' => 1,
				'mhbo_nonce'     => wp_create_nonce( 'mhbo_auto_action' ),
				'room_id'        => isset( $_GET['room_id'] ) ? absint( wp_unslash( $_GET['room_id'] ) ) : 0,
				'type_id'        => isset( $_GET['type_id'] ) ? absint( wp_unslash( $_GET['type_id'] ) ) : 0,
				'check_in'       => isset( $_GET['check_in'] ) ? sanitize_text_field( wp_unslash( $_GET['check_in'] ) ) : '',
				'check_out'      => isset( $_GET['check_out'] ) ? sanitize_text_field( wp_unslash( $_GET['check_out'] ) ) : '',
				'guests'         => isset( $_GET['guests'] ) ? absint( wp_unslash( $_GET['guests'] ) ) : 1,
				'children'       => isset( $_GET['children'] ) ? absint( wp_unslash( $_GET['children'] ) ) : 0,
				'total_price'    => isset( $_GET['total_price'] ) ? (float) sanitize_text_field( wp_unslash( $_GET['total_price'] ) ) : 0.0,
			);

			// 2026 BP: Persist child_ages across the PRG redirect via a short-lived transient.
			$child_ages_redirect = isset( $_GET['child_ages'] ) && is_array( $_GET['child_ages'] )
				? array_map( 'absint', wp_unslash( $_GET['child_ages'] ) ) : array();
			if ( array() !== $child_ages_redirect ) {
				set_transient(
					'mhbo_child_ages_' . $redirect_args['mhbo_nonce'],
					wp_json_encode( $child_ages_redirect ),
					300
				);
			}

			wp_safe_redirect( add_query_arg( $redirect_args, $this->get_booking_page_url() ) );
			exit;
		}

		// 2. Process Manual Search Form (POST -> GET Redirect)
		if ( isset( $_POST['mhbo_search'] ) ) {
			$nonce = isset( $_POST['mhbo_search_nonce'] ) ? sanitize_key( wp_unslash( $_POST['mhbo_search_nonce'] ) ) : '';
			if ( wp_verify_nonce( $nonce, 'mhbo_search_action' ) ) {
				$search_args = array(
					'check_in'         => isset( $_POST['check_in'] ) ? sanitize_text_field( wp_unslash( $_POST['check_in'] ) ) : '',
					'check_out'        => isset( $_POST['check_out'] ) ? sanitize_text_field( wp_unslash( $_POST['check_out'] ) ) : '',
					'guests'           => isset( $_POST['guests'] ) ? absint( wp_unslash( $_POST['guests'] ) ) : 2,
					'children'         => isset( $_POST['children'] ) ? absint( wp_unslash( $_POST['children'] ) ) : 0,
					'mhbo_auto_search' => 1,
				);
				$rid         = isset( $_POST['room_id_filter'] ) ? intval( wp_unslash( $_POST['room_id_filter'] ) ) : $room_id_attr;
				if ( $rid > 0 ) {
					$search_args['room_id'] = $rid; }

				global $wp;
				wp_safe_redirect( add_query_arg( $search_args, home_url( add_query_arg( array(), $wp->request ) ) ) );
				exit;
			}
		}

		// 3. Check for Automatic Booking/Search (GET)
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Reading QUERY_STRING to unescape entity-encoded ampersands before individual parameter sanitization below.
		if ( isset( $_SERVER['QUERY_STRING'] ) ) {
			$raw_qs = function_exists( 'wp_unslash' ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : $_SERVER['QUERY_STRING'];
			if ( is_string( $raw_qs ) && ( str_contains( $raw_qs, '&#038;' ) || str_contains( $raw_qs, '&amp;' ) ) ) {
				$clean_query = str_replace( array( '&#038;', '&amp;' ), '&', $raw_qs );
				parse_str( $clean_query, $parsed_get );
				if ( is_array( $parsed_get ) ) {
					$_GET = array_merge( $_GET, $parsed_get );
				}
			}
		}
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Search parameters do not require nonce verification.
		$is_auto_book   = isset( $_GET['mhbo_auto_book'] );
		$is_auto_search = isset( $_GET['mhbo_auto_search'] ) || ( isset( $_GET['check_in'] ) && isset( $_GET['check_out'] ) );

		if ( $is_auto_book || $is_auto_search ) {
			$room_id    = isset( $_GET['room_id'] ) ? absint( wp_unslash( $_GET['room_id'] ) ) : $room_id_attr;
			$type_id    = isset( $_GET['type_id'] ) ? absint( wp_unslash( $_GET['type_id'] ) ) : 0;
			$check_in   = isset( $_GET['check_in'] ) ? sanitize_text_field( wp_unslash( $_GET['check_in'] ) ) : '';
			$check_out  = isset( $_GET['check_out'] ) ? sanitize_text_field( wp_unslash( $_GET['check_out'] ) ) : '';
			$guests     = isset( $_GET['guests'] ) ? absint( wp_unslash( $_GET['guests'] ) ) : 2;
			$children   = isset( $_GET['children'] ) ? absint( wp_unslash( $_GET['children'] ) ) : 0;
			$exclude_id = isset( $_GET['exclude_id'] ) ? absint( wp_unslash( $_GET['exclude_id'] ) ) : 0;
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( $is_auto_book ) {
				$nonce = isset( $_GET['mhbo_nonce'] ) ? sanitize_key( wp_unslash( $_GET['mhbo_nonce'] ) ) : '';
				if ( wp_verify_nonce( $nonce, 'mhbo_auto_action' ) ) {
					if ( $room_id === 0 && $type_id > 0 && '' !== $check_in && '' !== $check_out ) {
						$resolved_room_id = Pricing::find_available_room( $type_id, $check_in, $check_out, $guests );
						if ( $resolved_room_id > 0 ) {
							$redirect_url = add_query_arg(
								array(
									'mhbo_auto_book' => 1,
									'mhbo_nonce'     => $nonce,
									'room_id'        => $resolved_room_id,
									'type_id'        => $type_id,
									'check_in'       => $check_in,
									'check_out'      => $check_out,
									'guests'         => $guests,
									'children'       => $children,
									'total_price'    => isset( $_GET['total_price'] ) ? (float) sanitize_text_field( wp_unslash( $_GET['total_price'] ) ) : 0.0,
								),
								$this->get_booking_page_url()
							);
							wp_safe_redirect( $redirect_url );
							exit;
						}
					}

					if ( $room_id > 0 ) {
						$recovered_child_ages = array();
						$nonce_key            = isset( $_GET['mhbo_nonce'] ) ? sanitize_key( wp_unslash( $_GET['mhbo_nonce'] ) ) : '';
						if ( '' !== $nonce_key ) {
							$raw_ages = get_transient( 'mhbo_child_ages_' . $nonce_key );
							if ( false !== $raw_ages ) {
								delete_transient( 'mhbo_child_ages_' . $nonce_key );
								$decoded              = json_decode( (string) $raw_ages, true );
								$recovered_child_ages = is_array( $decoded ) ? array_map( 'absint', $decoded ) : array();
							}
						}

						$calc_preview = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, max( 1, $guests ), array(), $children, $recovered_child_ages );
						$total        = ( is_array( $calc_preview ) && isset( $calc_preview['total'] ) ) ? $calc_preview['total'] : Money::fromCents( 0, Pricing::get_currency_code() );
						
						$this->render_booking_form(
							array(
								'room_id'        => $room_id,
								'type_id'        => $type_id,
								'check_in'       => $check_in,
								'check_out'      => $check_out,
								'guests'         => max( 1, $guests ),
								'children'       => $children,
								'exclude_id'     => $exclude_id,
								'child_ages'     => $recovered_child_ages,
								'total_price'    => $total,
								'customer_name'  => isset( $_GET['customer_name'] ) ? sanitize_text_field( wp_unslash( $_GET['customer_name'] ) ) : '',
								'customer_email' => isset( $_GET['customer_email'] ) ? sanitize_email( wp_unslash( $_GET['customer_email'] ) ) : '',
								'customer_phone' => isset( $_GET['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_GET['customer_phone'] ) ) : '',
								'admin_notes'    => isset( $_GET['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_GET['admin_notes'] ) ) : '',
							)
						);
						return;
					} elseif ( ! $is_auto_search ) {
							echo '<div class="mhbo-error mhbo-message mhbo-error">' . esc_html( I18n::get_label( 'label_no_room_available_auto' ) ) . '</div>';
					}
				}
			}

			if ( $this->validate_date( $check_in ) && $this->validate_date( $check_out ) ) {
				$this->render_search_results( $room_id, $check_in, $check_out, $guests, $type_id, $children );
				return;
			}
		}

		// 4. Default: Render empty search form
		$this->render_search_form( $room_id_attr );
	}

	/**
	 * Render the search form (unified view).
	 *
	 * @param int $room_id Optional room ID to focus on.
	 * @return void
	 */
	private function render_search_form( int $room_id = 0 ): void {
		// Unified Calendar View replacing the old search form
		// Delegates to the centralized Calendar renderer
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Calendar::render_unified_view returns pre-escaped HTML from internal components
		echo wp_kses_post( Calendar::render_unified_view( $room_id ) );
	}

	/**
	 * Render search results based on provided parameters.
	 *
	 * @param int    $room_id_filter Room ID filter.
	 * @param string $check_in       Check-in date (Y-m-d).
	 * @param string $check_out      Check-out date (Y-m-d).
	 * @param int    $guests         Number of adult guests.
	 * @param int    $type_id_filter Room type ID filter.
	 * @param int    $children       Number of children.
	 * @return void
	 */
	private function render_search_results( int $room_id_filter = 0, string $check_in = '', string $check_out = '', int $guests = 1, int $type_id_filter = 0, int $children = 0 ): void {
		global $wpdb;

		// Ensure we have minimal valid data (Already verified if from POST, or sanitized if from GET)
		if ( '' === $check_in || '' === $check_out ) {
			$this->render_search_form( $room_id_filter );
			return;
		}

		// Date Validation
		$today = wp_date( 'Y-m-d' );
		if ( $check_in < $today ) {
			echo '<div class="mhbo-error">' . esc_html( I18n::get_label( 'label_check_in_past' ) ) . '</div>';
			$this->render_search_form();
			return;
		}
		if ( $check_out <= $check_in ) {
			echo '<div class="mhbo-error">' . esc_html( I18n::get_label( 'label_check_out_after' ) ) . '</div>';
			$this->render_search_form();
			return;
		}

		// $room_id_filter is now passed as an argument, no longer fall back to POST here

		$query_args = array();
		$sql        = "SELECT r.id, r.type_id, r.room_number, r.status, r.custom_price,
                       r.image_url as room_image_url, t.image_url as type_image_url,
                       t.name as type_name, t.base_price, t.max_adults, t.max_children,
                       t.description as description, t.amenities as amenities,
                       t.description as type_description, t.amenities as type_amenities
                FROM {$wpdb->prefix}mhbo_rooms r
                JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id
                WHERE r.status = 'available'";

		if ( 0 !== $room_id_filter ) {
			$sql         .= ' AND r.id = %d';
			$query_args[] = $room_id_filter;
		}

		if ( 0 !== $type_id_filter ) {
			$sql         .= ' AND r.type_id = %d';
			$query_args[] = $type_id_filter;
		}

		// Same-day Turnover Setting
		$prevent_same_day = (int) get_option( 'mhbo_prevent_same_day_turnover', 0 ) === 1;

		// Industry-standard overlap logic (dynamically settings-aware)
		// Refactored to avoid dynamic condition interpolation for scanner compliance
		if ( $prevent_same_day ) {
			$sql .= " AND r.id NOT IN ( 
                        SELECT room_id FROM {$wpdb->prefix}mhbo_bookings 
                        WHERE (check_in <= DATE(%s) AND check_out >= DATE(%s))
                        AND status != 'cancelled' 
                        AND room_id IS NOT NULL 
                        AND room_id > 0
                    )";
		} else {
			$sql .= " AND r.id NOT IN ( 
                        SELECT room_id FROM {$wpdb->prefix}mhbo_bookings 
                        WHERE (check_in < DATE(%s) AND check_out > DATE(%s))
                        AND status != 'cancelled' 
                        AND room_id IS NOT NULL 
                        AND room_id > 0
                    )";
		}

		// Remove SQL GROUP BY to prevent ONLY_FULL_GROUP_BY mode failures in MySQL 5.7+
		// $sql .= " GROUP BY r.type_id";
		
		$query_args[] = $check_out; 
		$query_args[] = $check_in;

		// Implement versioned caching for search results (Rule 13 / 2026 BP)
		$version_hash = Cache::get_version( Cache::TABLE_BOOKINGS ) . '_' . 
						Cache::get_version( Cache::TABLE_ROOMS ) . '_' . 
						Cache::get_version( Cache::TABLE_PRICING_RULES );
		$cache_key    = 'mhbo_avail_rooms_v6_' . md5( $sql . wp_json_encode( $query_args ) . $version_hash );
		$all_rooms    = wp_cache_get( $cache_key, 'mhbo_bookings' );

		if ( false === $all_rooms ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: High-performance room availability search across custom relational tables. Query is safely assembled using $wpdb->prepare placeholders
			$all_rooms = $wpdb->get_results( $wpdb->prepare( $sql, ...$query_args ) );
			if ( ! is_array( $all_rooms ) ) {
				$all_rooms = array();
			}
			wp_cache_set( $cache_key, $all_rooms, 'mhbo_bookings', 300 ); // 5 min cache, auto-invalidated on booking changes via version hash
		}

// Deduplicate by room type ID. We only need to show one result per room type.
		// Doing this AFTER Pro filtering ensures that if Room A is blocked but Room B (same type) is free, the type still shows.
		$available_rooms = array();
		$seen_types      = array();
		if ( is_array( $all_rooms ) && array() !== $all_rooms ) {
			foreach ( $all_rooms as $room ) {
				if ( ! isset( $seen_types[ $room->type_id ] ) ) {
					$available_rooms[]            = $room;
					$seen_types[ $room->type_id ] = true;
				}
			}
		}

		echo '<h3>' . esc_html( sprintf( I18n::get_label( 'label_available_rooms' ), $check_in, $check_out ) ) . '</h3>';
		if ( ! is_array( $available_rooms ) || array() === $available_rooms ) {
			echo '<p>' . esc_html( I18n::get_label( 'label_no_rooms' ) ) . '</p>';
			$this->render_search_form();
			return;
		}

		echo '<div class="mhbo-rooms-grid">';
		foreach ( $available_rooms as $room ) {
			$start_date = new \DateTime( $check_in );
			$end_date   = new \DateTime( $check_out );
			$interval   = new \DateInterval( 'P1D' );
			$period     = new \DatePeriod( $start_date, $interval, $end_date );

			$currency = Pricing::get_currency_code();
			$total    = Money::fromCents( 0, $currency );
			foreach ( $period as $dt ) {
				$date_str = $dt->format( 'Y-m-d' );
				$total    = $total->add( Pricing::calculate_daily_price_money( (int) $room->id, $date_str ) );
			}

			$days = iterator_count( $period );
			// Average price per night calculated with Money BCMath division
			$avg_price_money = $days > 0 ? $total->divide( $days ) : Money::fromDecimal( (string) ( $room->custom_price ?: $room->base_price ), $total->getCurrency() );

			$amenities = $room->amenities ? json_decode( $room->amenities ) : array();

			// Room-specific image takes priority; falls back to room type image.
			$resolved_img = ( '' !== (string) ( $room->room_image_url ?? '' ) ) ? $room->room_image_url : ( $room->type_image_url ?? '' );
			$img_style    = '';
			if ( '' !== (string) $resolved_img ) {
				$img_style = 'background:url(' . esc_url( $resolved_img ) . ') center/cover;';
			} else {
				$img_style = 'background: linear-gradient(135deg, var(--mhbo-primary) 0%, var(--mhbo-accent) 100%); opacity: 0.8;';
			}

			echo '<div class="mhbo-room-card">';
			echo '<div class="mhbo-room-image" style="height:200px; ' . esc_attr( (string) $img_style ) . '"></div>';
			echo '<div class="mhbo-room-content">';
			echo '<h4 class="mhbo-room-title">' . esc_html( (string) I18n::decode( $room->type_name ) ) . '</h4>';

			$desc = I18n::decode( $room->description ?: ( $room->type_description ?? '' ) );
			if ( '' !== $desc ) {
				echo '<p class="mhbo-room-description" style="font-size:0.9rem; color:#666; margin-bottom:15px;">' . esc_html( wp_trim_words( (string) $desc, 20 ) ) . '</p>';
			}

			echo '<div class="mhbo-room-price">' . esc_html( I18n::format_currency( $avg_price_money ) ) . ' <span>' . esc_html( I18n::get_label( 'label_per_night' ) ) . '</span></div>';

			$amenities_raw = $room->amenities ?: ( $room->type_amenities ?? '[]' );
			$amenities     = $amenities_raw ? json_decode( (string) $amenities_raw ) : array();

			if ( is_array( $amenities ) && array() !== $amenities ) {
				echo '<div class="mhbo-amenities" style="margin-bottom:10px; font-size:0.85rem; color:#666;">';
				foreach ( $amenities as $am ) {
					echo '<span style="display:inline-block; background:#eee; padding:2px 8px; border-radius:12px; margin-right:5px; margin-bottom:5px;">' . esc_html( ucfirst( (string) I18n::decode( $am ) ) ) . '</span>';
				}
				echo '</div>';
			}

			echo '<div class="mhbo-room-details">';
			echo wp_kses_post( sprintf( I18n::get_label( 'label_total_nights' ), $days, '<strong>' . esc_html( I18n::format_currency( $total ) ) . '</strong>' ) );
			echo '<p>' . esc_html( sprintf( I18n::get_label( 'label_max_guests' ), $room->max_adults ) ) . '</p>';
			echo '</div>';

			// Fix: If no specific room_id was requested (category search), use the available $room->id found by the query.
			// This prevents "Room 1" from being hardcoded into all results if the user is on the Room 1 page.
			$assigned_room_id = ( $room_id_filter > 0 && (int) $room->id === (int) $room_id_filter ) ? $room_id_filter : $room->id;

			$checkout_url = $this->get_booking_page_url();
			// Clean URL to prevent parameter pollution/duplication in the GET form
			$clean_checkout_url = $this->remove_mhbo_query_args( $checkout_url );
			
			echo '<form method="get" action="' . esc_url( $clean_checkout_url ) . '">';
			self::render_url_hidden_inputs( $clean_checkout_url );
			
			// Critical: Add the auto-book flag and correct nonce for GET-based processing
			echo '<input type="hidden" name="mhbo_auto_book" value="1">';
			echo '<input type="hidden" name="mhbo_nonce" value="' . esc_attr( wp_create_nonce( 'mhbo_auto_action' ) ) . '">';
			
			echo '<input type="hidden" name="check_in" value="' . esc_attr( $check_in ) . '">';
			echo '<input type="hidden" name="check_out" value="' . esc_attr( $check_out ) . '">';
			echo '<input type="hidden" name="room_id" value="' . esc_attr( (string) $assigned_room_id ) . '">';
			echo '<input type="hidden" name="type_id" value="' . esc_attr( (string) $room->type_id ) . '">';
			echo '<input type="hidden" name="guests" value="' . esc_attr( (string) max( 1, $guests ) ) . '">';
			echo '<input type="hidden" name="children" value="' . esc_attr( (string) max( 0, $children ) ) . '">';
			echo '<input type="hidden" name="total_price" value="' . esc_attr( (string) $total->toDecimal() ) . '">';
			
			echo '<button type="submit" class="mhbo-btn">' . esc_html( I18n::get_label( 'btn_book_now' ) ) . '</button>';
			echo '</form></div></div>';
		}
		echo '</div>';
	}

	/**
	 * Render the final booking details and customer info form.
	 *
	 * @param array<string, mixed> $params Booking parameters (room_id, dates, etc.).
	 * @return void
	 */
	public function render_booking_form( array $params = array() ): void {
		global $wpdb;

		// Read booking data exclusively from params (verified in handle_booking_process)
		$room_id       = isset( $params['room_id'] ) ? intval( $params['room_id'] ) : 0;
		$type_id       = isset( $params['type_id'] ) ? intval( $params['type_id'] ) : 0;
		$check_in      = isset( $params['check_in'] ) ? sanitize_text_field( $params['check_in'] ) : '';
		$check_out     = isset( $params['check_out'] ) ? sanitize_text_field( $params['check_out'] ) : '';
		$guests        = isset( $params['guests'] ) ? intval( $params['guests'] ) : 2;
		$currency_code = Pricing::get_currency_code();
		$total_hint    = ( isset( $params['total_price'] ) && $params['total_price'] instanceof Money ) ? $params['total_price'] : Money::fromDecimal( (string) ( $params['total_price'] ?? '0' ), $currency_code );
		
		// Customer Details (for re-population)
		$customer_name  = isset( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : '';
		$customer_email = isset( $params['customer_email'] ) ? sanitize_email( $params['customer_email'] ) : '';
		$customer_phone = isset( $params['customer_phone'] ) ? sanitize_text_field( $params['customer_phone'] ) : '';
		$admin_notes    = isset( $params['admin_notes'] ) ? sanitize_textarea_field( $params['admin_notes'] ) : '';

		$exclude_id = isset( $params['exclude_id'] ) ? intval( $params['exclude_id'] ) : 0;
		$update_id  = isset( $params['mhbo_update_id'] ) ? intval( $params['mhbo_update_id'] ) : 0;
		if ( $update_id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $update_id ) );
			if ( 'pending_payment' !== $existing_status ) {
				$update_id = 0;
			}
		}

		// Resolve room_id from type_id if room_id is 0 or if the specified room_id is not available for these dates
		if ( $type_id > 0 ) {
			$is_specified_avail = ( $room_id > 0 ) ? Pricing::is_room_available( (int) $room_id, $check_in, $check_out, $exclude_id ) : false;
			if ( true !== $is_specified_avail ) {
				$resolved_room = Pricing::find_available_room( $type_id, $check_in, $check_out, $guests );
				if ( false !== $resolved_room && 0 !== $resolved_room ) {
					$room_id = (int) $resolved_room;
				}
			}
		}

		// Check availability before rendering booking form.
		$available = Pricing::is_room_available( (int) $room_id, $check_in, $check_out, $exclude_id );

		if ( true !== $available ) {
			$error_key = is_string( $available ) && '' !== $available ? $available : 'msg_room_unavailable';
			echo '<div class="mhbo-error mhbo-availability-error">' .
				esc_html( I18n::get_label( $error_key ) ) .
				'</div>';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $_GET['mhbo_auto_book'] ) && ! isset( $params['modal_context'] ) ) {
				$this->render_search_form();
			}
			return;
		}

$cache_key = 'mhbo_room_details_' . md5( (string) $room_id );
		$room      = wp_cache_get( $cache_key, 'mhbo_bookings' );
		if ( false === $room ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table
			$room = $wpdb->get_row( $wpdb->prepare( "SELECT t.id as type_id, t.image_url as type_image_url, r.image_url as room_image_url, t.name as type_name, t.base_price, t.max_adults, t.max_children, t.child_rate, t.child_age_free_limit, r.room_number, r.custom_price FROM {$wpdb->prefix}mhbo_rooms r JOIN {$wpdb->prefix}mhbo_room_types t ON r.type_id = t.id WHERE r.id = %d", $room_id ) );
			if ( null !== $room ) {
				wp_cache_set( $cache_key, $room, 'mhbo_bookings', 300 );
			}
		}

		$room_type_id = $room ? $room->type_id : $type_id;

		// Validate room exists before rendering form
		if ( null === $room ) {
			if ( current_user_can( 'manage_options' ) ) {
				echo '<div class="mhbo-setup-notice" style="background:#fff8e5; border-left:4px solid #dba617; padding:18px 20px; border-radius:6px; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; margin:20px 0; color:#1d2327;">' .
					'<h4 style="margin:0 0 8px 0; font-size:16px; color:#1d2327;">⚠️ ' . sprintf(
						// translators: %d: ID of the room that was not found.
						esc_html__( 'Room ID %d Not Found', 'modern-hotel-booking' ),
						absint( $room_id )
					) . '</h4>' .
					'<p style="margin:0 0 12px 0; font-size:14px; color:#50575e;">' . esc_html__( 'No room matching this ID was found in the database. Please verify your room configuration in the dashboard.', 'modern-hotel-booking' ) . '</p>' .
					'<a href="' . esc_url( admin_url( 'admin.php?page=mhbo-rooms' ) ) . '" style="background:#2271b1; color:#fff; padding:6px 14px; text-decoration:none; border-radius:3px; font-size:13px; display:inline-block;">' . esc_html__( 'Manage Rooms in Admin →', 'modern-hotel-booking' ) . '</a>' .
				'</div>';
			} else {
				echo '<div class="mhbo-guest-notice" style="padding:20px; text-align:center; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; color:#4b5563;">' .
					'<p style="margin:0; font-size:15px;">' . esc_html( I18n::get_label( 'label_room_not_found' ) ) . '</p>' .
				'</div>';
			}
			$this->render_search_form();
			return;
		}

		$resolved_room_img = ( $room && $room->room_image_url ) ? $room->room_image_url : ( ( $room && $room->type_image_url ) ? $room->type_image_url : '' );
		$image_url         = $resolved_room_img ? esc_url( $resolved_room_img ) : '';
		$room_name         = $room ? I18n::decode( $room->type_name ) . ' (' . $room->room_number . ')' : I18n::get_label( 'label_room' );
		$total             = $total_hint; // Set total to Money object from hint

		// Always recalculate on render to ensure we have the full $calc breakdown for display
		$calc_guests     = $guests;
		$calc_children   = isset( $params['children'] ) ? intval( $params['children'] ) : 0;
		$calc_child_ages = isset( $params['child_ages'] ) ? array_map( 'absint', (array) $params['child_ages'] ) : array();
		$calc_extras     = isset( $params['extras'] ) ? array_map( 'sanitize_text_field', (array) $params['extras'] ) : array();

		$calc = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, $calc_guests, $calc_extras, $calc_children, $calc_child_ages );
		
		$is_invalid_occupancy = ( false === $calc );
		if ( $is_invalid_occupancy ) {
			echo '<div class="mhbo-error">' . esc_html( _x( 'Over-occupancy: Please check guest limits for this room.', 'error message', 'modern-hotel-booking' ) ) . '</div>';
			$total = $total_hint;
		} else {
			$total = $calc['total'];
		}

		$is_pro_active = false;

$deposit_data = null;
		if ( MHBO_IS_PRO && get_option( 'mhbo_deposits_enabled', 0 ) ) {
			$currency          = $total->getCurrency();
			$first_night_money = Pricing::resolve_first_night_money( $room_id, $check_in, $calc_guests, null, $calc_extras, $calc_children, $calc_child_ages, $currency );
			// Store in $calc so render_deposit_selection_html uses the correct first-night amount.
			if ( is_array( $calc ) ) {
				$calc['first_night_total'] = $first_night_money;
			}
			$deposit_data = Pricing::calculate_deposit_money( $total, $first_night_money );
		}

		?>
		<div class="mhbo-booking-wrapper">
				<div class="mhbo-booking-summary">
					<h3 class="mhbo-summary-title"><?php echo esc_html( I18n::get_label( 'label_booking_summary' ) ); ?></h3>
					
					<div class="mhbo-summary-content" style="display:flex; align-items:center; gap:16px; margin-bottom:24px;">
						<?php if ( '' !== $image_url ) : ?>
							<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( I18n::get_label( 'label_room_alt_text' ) ); ?>" style="width:100px; height:70px; object-fit:cover; border-radius:8px; flex-shrink:0;">
						<?php endif; ?>
						<div>
							<strong style="color:var(--mhbo-primary); font-size:1.15rem; display:block; margin-bottom:6px;"><?php echo esc_html( $room_name ); ?></strong>
							<span class="mhbo-room-number-badge"><?php echo esc_html( I18n::get_label( 'label_room_number' ) ); ?> <?php echo esc_html( $room->room_number ); ?></span>
						</div>
					</div>

					<?php
					$ci_date = \DateTime::createFromFormat( 'Y-m-d', $check_in );
					$co_date = \DateTime::createFromFormat( 'Y-m-d', $check_out );
					$nights  = ( $ci_date && $co_date ) ? max( 1, $ci_date->diff( $co_date )->days ) : 1;
					$ci_time = get_option( 'mhbo_checkin_time', '14:00' );
					$co_time = get_option( 'mhbo_checkout_time', '11:00' );
					?>
					
					<div class="mhbo-booking-dates">
						<div class="mhbo-booking-dates-col">
							<div class="mhbo-date-title"><?php echo esc_html( __( 'CHECK IN', 'modern-hotel-booking' ) ); ?></div>
							<div class="mhbo-date-val"><?php echo esc_html( $ci_date ? $ci_date->format( 'M j, Y' ) : $check_in ); ?></div>
							<div class="mhbo-stay-time"><span class="mhbo-stay-time-prefix"><?php echo esc_html( _x( 'FROM', 'Check-in time prefix', 'modern-hotel-booking' ) ); ?></span> <span class="mhbo-checkin-time-val" data-standard="<?php echo esc_attr( $ci_time ); ?>" data-early="<?php echo esc_attr( HotelTime::get_early_checkin_time() ); ?>"><?php echo esc_html( $ci_time ); ?></span></div>
						</div>
						<div class="mhbo-booking-dates-col">
							<div class="mhbo-date-title"><?php echo esc_html( __( 'CHECK-OUT', 'modern-hotel-booking' ) ); ?></div>
							<div class="mhbo-date-val"><?php echo esc_html( $co_date ? $co_date->format( 'M j, Y' ) : $check_out ); ?></div>
							<div class="mhbo-stay-time"><span class="mhbo-stay-time-prefix"><?php echo esc_html( _x( 'BY', 'Check-out time prefix', 'modern-hotel-booking' ) ); ?></span> <span class="mhbo-checkout-time-val" data-standard="<?php echo esc_attr( $co_time ); ?>" data-late="<?php echo esc_attr( HotelTime::get_late_checkout_time() ); ?>"><?php echo esc_html( $co_time ); ?></span></div>
						</div>
						<div class="mhbo-booking-dates-nights">
							<div class="mhbo-date-title"><?php echo esc_html( __( 'NIGHTS', 'modern-hotel-booking' ) ); ?></div>
							<div class="mhbo-nights-count"><?php echo esc_html( (string) (int) $nights ); ?></div>
							<div class="mhbo-nights-label"><?php echo esc_html( I18n::get_label( 'label_nights' ) ); ?></div>
						</div>
					</div>

					<div class="mhbo-summary-grid">
						<div class="mhbo-summary-header">
							<span><?php echo esc_html( _x( 'Item', 'cart item', 'modern-hotel-booking' ) ); ?></span>
							<span><?php echo esc_html( __( 'Amount', 'modern-hotel-booking' ) ); ?></span>
						</div>
						
						<?php
						$room_total_init = ( $calc && isset( $calc['room_total'] ) && $calc['room_total'] instanceof Money ) ? $calc['room_total'] : $total;
						?>
						<div class="mhbo-summary-row mhbo-room-cost-row">
							<span><?php echo esc_html( _x( 'Room', 'accommodation unit', 'modern-hotel-booking' ) ); ?></span>
							<span class="mhbo-room-total-display"><?php echo esc_html( I18n::format_currency( $room_total_init ) ); ?></span>
						</div>

<div class="mhbo-extras-summary-rows">
							<?php
							if ( $calc && ! empty( $calc['extras_breakdown'] ) ) {
								foreach ( $calc['extras_breakdown'] as $ex_item ) {
									$ex_name  = (string) I18n::decode( (string) ( $ex_item['name'] ?? '' ) );
									$ex_total = isset( $ex_item['total'] ) && $ex_item['total'] instanceof Money ? $ex_item['total']->format() : I18n::format_currency( $ex_item['total'] ?? $ex_item['price'] ?? 0 );
									echo '<div class="mhbo-summary-row mhbo-extra-summary-row" data-extra-id="' . esc_attr( (string) $ex_item['id'] ) . '">';
									echo '<span>' . esc_html( $ex_name ) . '</span>';
									echo '<span class="mhbo-extra-item-total">' . esc_html( $ex_total ) . '</span>';
									echo '</div>';
								}
							}
							?>
						</div>

<div class="mhbo-summary-divider"></div>
						
						<div class="mhbo-summary-total">
							<span><?php echo esc_html( I18n::get_label( 'label_total' ) ); ?></span>
							<span class="mhbo-display-total"
								data-base-total="<?php echo esc_attr( (string) $total->toDecimal() ); ?>"
								data-currency-symbol="<?php echo esc_attr( get_option( 'mhbo_currency_symbol', '$' ) ); ?>"
								data-currency-pos="<?php echo esc_attr( get_option( 'mhbo_currency_position', 'before' ) ); ?>">
								<?php echo esc_html( I18n::format_currency( $total ) ); ?>
							</span>
						</div>
					</div>
				</div>
			<h2 class="mhbo-form-heading"><?php echo esc_html( I18n::get_label( 'label_complete_booking' ) ); ?></h2>
			<form method="post" class="mhbo-booking-form" id="mhbo-booking-form">
				<?php wp_nonce_field( 'mhbo_confirm_action', 'mhbo_confirm_nonce' ); ?>
				<?php
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is rigorously verified in the process_payment flow.
					$url_token      = isset( $_GET['booking_token'] ) ? sanitize_text_field( wp_unslash( $_GET['booking_token'] ) ) : '';
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Querying custom mhbo_bookings table; token fetched for form rehydration.
					$existing_token = ( $update_id > 0 ) ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT booking_token FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d", $update_id ) ) : '';
					$final_token    = $url_token ?: $existing_token;
				?>
				<input type="hidden" name="mhbo_booking_token" value="<?php echo esc_attr( $final_token ); ?>">
				<!-- Hidden field so JS form.submit() includes the booking action -->
				<input type="hidden" name="mhbo_confirm_booking" value="1">
				<input type="hidden" name="mhbo_room_id" value="<?php echo esc_attr( (string) $room_id ); ?>">
				<input type="hidden" name="mhbo_exclude_id" value="<?php echo esc_attr( (string) $exclude_id ); ?>">
				<input type="hidden" name="mhbo_update_id" value="<?php echo esc_attr( (string) $update_id ); ?>">
				<input type="hidden" name="mhbo_type_id" value="<?php echo esc_attr( (string) ( $room_type_id ?? 0 ) ); ?>">
				<input type="hidden" name="check_in" value="<?php echo esc_attr( $check_in ); ?>">
				<input type="hidden" name="check_out" value="<?php echo esc_attr( $check_out ); ?>">
				<input type="hidden" name="total_price" value="<?php echo esc_attr( $total->toDecimal() ); ?>">
				<input type="hidden" name="booking_language" value="<?php echo esc_attr( I18n::get_current_language() ); ?>">
				<input type="hidden" name="mhbo_page_url" value="<?php echo esc_attr( get_permalink() ?: ( isset( $_SERVER['REQUEST_URI'] ) ? home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : home_url( '/' ) ) ); ?>">

				<div class="mhbo-form-group"><label><?php echo esc_html( I18n::get_label( 'label_name' ) ); ?> <span
							class="required">*</span></label><input type="text" name="customer_name" value="<?php echo esc_attr( $customer_name ); ?>" required></div>
				<div class="mhbo-form-group"><label><?php echo esc_html( I18n::get_label( 'label_email' ) ); ?> <span
							class="required">*</span></label><input type="email" name="customer_email" value="<?php echo esc_attr( $customer_email ); ?>" required></div>
				<div class="mhbo-form-group">
					<label><?php echo esc_html( I18n::get_label( 'label_guests' ) ); ?> <span class="required">*</span></label>
					<select name="guests" class="mhbo-booking-guests" required>
						<?php
						// Determine max guests (capacity)
						$max_capacity = isset( $room->max_adults ) ? intval( $room->max_adults ) : 2;
						if ( $max_capacity < 1 ) {
							$max_capacity = 2;
						}

						// Use pre-validated $guests parameter
						$selected_guests = $guests;
						if ( $selected_guests > $max_capacity ) {
							$selected_guests = $max_capacity;
						}

						for ( $i = 1; $i <= $max_capacity; $i++ ) {
							echo '<option value="' . esc_attr( (string) $i ) . '" ' . selected( $selected_guests, $i, false ) . '>' . esc_html( (string) $i ) . '</option>';
						}
						?>
					</select>
				</div>

<div class="mhbo-form-group">
					<label><?php echo esc_html( I18n::get_label( 'label_phone' ) ); ?> <span class="required">*</span></label><input
						type="tel" name="customer_phone" value="<?php echo esc_attr( $customer_phone ); ?>" required>
				</div>
				<div class="mhbo-form-group mhbo-notes-form-group">
					<label for="mhbo_admin_notes"><?php echo esc_html( I18n::get_label( 'label_special_requests' ) ); ?></label>
					<textarea id="mhbo_admin_notes" name="admin_notes" rows="3" class="mhbo-admin-notes-textarea" style="width:100%; box-sizing:border-box; max-width:100%;"><?php echo esc_textarea( $admin_notes ); ?></textarea>
				</div>

<?php
				// Note: Honeypot removed for compliance. Security is handled via nonces.
				?>

<?php
				// Render Custom Fields
				$custom_fields = get_option( 'mhbo_custom_fields', array() );
				if ( array() !== $custom_fields ) {
					foreach ( $custom_fields as $field ) {
						$label         = isset( $field['label'] ) ? I18n::decode( I18n::encode( $field['label'] ) ) : $field['id'];
						$required      = ( isset( $field['required'] ) && (bool) $field['required'] ) ? 'required' : '';
						$required_mark = $required ? ' <span class="required">*</span>' : '';

						echo '<div class="mhbo-form-group mhbo-custom-field-group">';
						echo '<label>' . esc_html( $label ) . wp_kses_post( $required_mark ) . '</label>';

						if ( $field['type'] === 'textarea' ) {
							echo '<textarea name="mhbo_custom[' . esc_attr( $field['id'] ) . ']" rows="3" style="width:100%" ' . esc_attr( $required ) . '></textarea>';
						} else {
							$input_type = ( $field['type'] === 'number' ) ? 'number' : 'text';
							echo '<input type="' . esc_attr( $input_type ) . '" name="mhbo_custom[' . esc_attr( $field['id'] ) . ']" ' . esc_attr( $required ) . '>';
						}
						echo '</div>';
					}
				}
				?>

<?php
				// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine
				
				?>

				<?php do_action( 'mhbo_booking_form_after_inputs', $total, $calc ); ?>

				<!-- Inline error notification area for payment/booking errors -->
				<div class="mhbo-booking-errors mhbo-inline-errors" style="display:none;"></div>

				<div class="mhbo-tax-breakdown-container">
					<?php
					// Display the dynamic pricing and tax breakdown from the server calculation
					$show_breakdown = ! Tax::is_enabled() || get_option( 'mhbo_tax_display_frontend', 1 );
					if ( $show_breakdown ) {
						$currency = $total->getCurrency();
						$tax_data = isset( $calc['tax'] ) ? $calc['tax'] : array(
							'enabled' => false,
							'totals'  => array(
								'subtotal_net' => isset( $calc['total'] ) ? $calc['total'] : Money::fromCents( 0, $currency ),
								'total_tax'    => Money::fromCents( 0, $currency ),
								'total_gross'  => isset( $calc['total'] ) ? $calc['total'] : Money::fromCents( 0, $currency ),
							),
						);

						// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine

echo wp_kses_post( Tax::render_breakdown_html( $tax_data, null, false, array(), false ) );
					}
					?>
				</div>
				<?php
				// VAT notes removed from booking page per user request.
				?>

<div class="mhbo-submit-container">
					<button type="submit" name="mhbo_confirm_booking" class="mhbo-btn mhbo-submit-btn">
						<?php echo esc_html( I18n::get_label( 'btn_confirm_booking' ) ); ?>
					</button>
					<div class="mhbo-secure-badge">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
								d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
						</svg>
						<span><?php echo esc_html( I18n::get_label( 'label_secure_payment' ) ); ?></span>
					</div>
				</div>

				<div class="mhbo-mobile-sticky-bar" aria-hidden="true">
					<div class="mhbo-mobile-sticky-bar-info">
						<span class="mhbo-mobile-sticky-bar-label"><?php echo esc_html( I18n::get_label( 'label_total' ) ); ?></span>
						<span class="mhbo-mobile-sticky-bar-total mhbo-display-total"><?php echo esc_html( I18n::format_currency( $total ) ); ?></span>
					</div>
					<button type="button" class="mhbo-mobile-sticky-bar-btn mhbo-mobile-scroll-btn">
						<span><?php echo esc_html( I18n::get_label( 'btn_confirm_booking' ) ); ?></span>
						<svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
							<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-11.25a.75.75 0 00-1.5 0v4.59L7.3 9.39a.75.75 0 10-1.06 1.06l3.25 3.25a.75.75 0 001.06 0l3.25-3.25a.75.75 0 10-1.06-1.06l-1.95 1.95V6.75z" clip-rule="evenodd" />
						</svg>
					</button>
				</div>

				<?php
				// Note: Booking form JavaScript logic has been moved to assets/js/mhbo-booking-form.js
				// The mhbo_vars configuration is injected via wp_add_inline_script() in enqueue_assets()
				?>

			</form>
		</div>
		<?php
	}

	/**
	 * Handle the server-side booking creation process.
	 *
	 * @return void
	 */
	private function process_booking(): void {

// SECURITY: Rate limiting for booking submissions (5 per minute per IP)
		$ip       = Security::get_client_ip();
		$rate_key = 'mhbo_booking_rate_' . md5( (string) $ip );
		$count    = get_transient( $rate_key );
		if ( false !== $count && $count >= 5 ) {
			$this->booking_fail( '<div class="mhbo-error">' . esc_html( I18n::get_label( 'label_rate_limit_error' ) ) . '</div>' );
			return;
		}
		set_transient( $rate_key, (int) $count + 1, 60 );

		// Nonce is verified by handle_booking_form_submission.
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$data = array(
			'room_id'             => isset( $_POST['mhbo_room_id'] ) ? absint( wp_unslash( $_POST['mhbo_room_id'] ) ) : ( isset( $_POST['room_id'] ) ? absint( wp_unslash( $_POST['room_id'] ) ) : 0 ),
			'type_id'             => isset( $_POST['mhbo_type_id'] ) ? absint( wp_unslash( $_POST['mhbo_type_id'] ) ) : 0,
			'check_in'            => isset( $_POST['check_in'] ) ? sanitize_text_field( wp_unslash( $_POST['check_in'] ) ) : '',
			'check_out'           => isset( $_POST['check_out'] ) ? sanitize_text_field( wp_unslash( $_POST['check_out'] ) ) : '',
			'customer_name'       => isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '',
			'customer_email'      => isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '',
			'customer_phone'      => isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '',
			'guests'              => isset( $_POST['guests'] ) ? absint( wp_unslash( $_POST['guests'] ) ) : 1,
			'children'            => isset( $_POST['children'] ) ? absint( wp_unslash( $_POST['children'] ) ) : 0,
			'child_ages'          => isset( $_POST['child_ages'] ) && is_array( $_POST['child_ages'] ) ? array_map( 'absint', wp_unslash( $_POST['child_ages'] ) ) : array(),
			'extras'              => isset( $_POST['mhbo_extras'] ) && is_array( $_POST['mhbo_extras'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_extras'] ) ) : array(),
			'payment_method'      => isset( $_POST['mhbo_payment_method'] ) ? sanitize_key( wp_unslash( $_POST['mhbo_payment_method'] ) ) : 'arrival',
			'payment_type'        => isset( $_POST['mhbo_payment_type'] ) ? sanitize_key( wp_unslash( $_POST['mhbo_payment_type'] ) ) : 'full',
			'stripe_pi'           => isset( $_POST['mhbo_stripe_payment_intent'] ) ? sanitize_text_field( wp_unslash( $_POST['mhbo_stripe_payment_intent'] ) ) : ( isset( $_GET['payment_intent'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_intent'] ) ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'paypal_order_id'     => isset( $_POST['mhbo_paypal_order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mhbo_paypal_order_id'] ) ) : '',
			'paypal_capture_id'   => isset( $_POST['mhbo_paypal_capture_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mhbo_paypal_capture_id'] ) ) : '',
			'custom_fields'       => isset( $_POST['mhbo_custom'] ) && is_array( $_POST['mhbo_custom'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mhbo_custom'] ) ) : array(),
			'admin_notes'         => isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '',
			'update_id'           => isset( $_POST['mhbo_update_id'] ) ? absint( wp_unslash( $_POST['mhbo_update_id'] ) ) : 0,
			'session_token'       => isset( $_POST['mhbo_session_token'] ) ? sanitize_text_field( wp_unslash( $_POST['mhbo_session_token'] ) ) : '',
			'page_url'            => $this->get_booking_page_url(),
			'language'            => isset( $_POST['booking_language'] ) ? sanitize_key( wp_unslash( $_POST['booking_language'] ) ) : I18n::get_current_language(),
			'consent'             => isset( $_POST['mhbo_consent'] ) ? (bool) sanitize_text_field( wp_unslash( $_POST['mhbo_consent'] ) ) : false,
			
		);
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		// Delegate to the centralized BookingProcessor
		$result = \MHBO\Core\BookingProcessor::process( $data );

		if ( is_wp_error( $result ) ) {
			$this->booking_fail( '<div class="mhbo-error">' . esc_html( $result->get_error_message() ) . '</div>' );
			return;
		}

		// Handle success
		$this->booking_done( $result['redirect_url'] );
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

		$active_theme = get_option( 'mhbo_active_theme', 'midnight' );
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

if ( '' === $primary && isset( $presets[ $active_theme ] ) ) {
			$primary   = $presets[ $active_theme ][0];
			$secondary = $presets[ $active_theme ][1];
			$accent    = $presets[ $active_theme ][2];
		}

		if ( '' !== $primary ) {
			// SECURITY: Validate hex colors before CSS interpolation
			$primary   = sanitize_hex_color( $primary ) ?: '#1a365d';
			$secondary = sanitize_hex_color( $secondary ) ?: '#f2e2c4';
			$accent    = sanitize_hex_color( $accent ) ?: '#d4af37';
			
			// SECURITY: Using printf for clean CSS variable construction with pre-sanitized values
			$custom_css = sprintf(
				':root, .mhbo-calendar-wrapper, .mhbo-booking-form-wrapper, .mhbo-deposit-options-wrapper, .mhbo-success-message, .mhbo-guest-portal-wrapper {
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
		}

		// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine

wp_add_inline_style(
			'mhbo-frontend',
			'
            .mhbo-child-age-group { 
                display: flex; 
                align-items: center; 
                justify-content: space-between; 
                background: rgba(0,0,0,0.03); 
                padding: 8px 12px; 
                border-radius: 6px; 
                margin-bottom: 8px !important;
            }
            .mhbo-child-age-group label { margin: 0 !important; font-weight: normal !important; }
            .mhbo_faded { opacity: 0.5; transition: opacity 0.3s; pointer-events: none; }
        '
		);
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
				'mhbo_confirm_booking',
				'mhbo_confirm_nonce',
				'mhbo_payment_return',
				'payment_intent',
				'payment_intent_client_secret',
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
		// Enqueue styles
		$this->ensure_assets_loaded();
		if ( 1 === (int) get_option( 'mhbo_modal_enabled', 1 ) ) {
			self::enqueue_for_modal();
		}

		$atts = (array) $atts;
		$atts = shortcode_atts(
			array(
				'columns' => 3,
				'limit'   => 6,
			),
			$atts,
			'mhbo_rooms'
		);

		$columns = absint( is_array( $atts ) && isset( $atts['columns'] ) ? $atts['columns'] : 3 );
		$limit   = absint( is_array( $atts ) && isset( $atts['limit'] ) ? $atts['limit'] : 6 );

		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Safe interpolations for table names. Limit is safely cast to integer.
		$room_types = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, description, image_url, base_price, max_adults, amenities FROM {$wpdb->prefix}mhbo_room_types WHERE 1=1 ORDER BY id DESC LIMIT %d", $limit ) );

		ob_start();
		echo '<div class="mhbo-wrapper mhbo-room-grid-shortcode" data-columns="' . esc_attr( (string) $columns ) . '">';
		
		if ( is_array( $room_types ) && array() !== $room_types ) {
			echo '<div class="mhbo-rooms-grid" style="grid-template-columns: repeat(' . esc_attr( (string) $columns ) . ', 1fr);">';
			foreach ( $room_types as $type ) {
				$img_style = '';
				if ( isset( $type->image_url ) && '' !== (string) $type->image_url ) {
					$img_style = 'background:url(' . esc_url( $type->image_url ) . ') center/cover;';
				} else {
					$img_style = 'background: linear-gradient(135deg, var(--mhbo-primary) 0%, var(--mhbo-accent) 100%); opacity: 0.8;';
				}

				echo '<div class="mhbo-room-card">';
				echo '<div class="mhbo-room-image" style="height:200px; ' . esc_attr( (string) $img_style ) . '"></div>';
				echo '<div class="mhbo-room-content">';
				echo '<h4 class="mhbo-room-title">' . esc_html( (string) I18n::decode( $type->name ) ) . '</h4>';

				$desc = I18n::decode( $type->description );
				if ( '' !== $desc ) {
					echo '<p class="mhbo-room-description" style="font-size:0.9rem; color:#666; margin-bottom:15px;">' . esc_html( wp_trim_words( (string) $desc, 20 ) ) . '</p>';
				}

				echo '<div class="mhbo-room-price">' . esc_html( I18n::format_currency( $type->base_price ) ) . ' <span>' . esc_html( I18n::get_label( 'label_per_night' ) ) . '</span></div>';

				$amenities = $type->amenities ? json_decode( (string) $type->amenities ) : array();
				if ( is_array( $amenities ) && array() !== $amenities ) {
					echo '<div class="mhbo-amenities" style="margin-bottom:10px; font-size:0.85rem; color:#666;">';
					foreach ( $amenities as $am ) {
						echo '<span style="display:inline-block; background:#eee; padding:2px 8px; border-radius:12px; margin-right:5px; margin-bottom:5px;">' . esc_html( ucfirst( (string) I18n::decode( $am ) ) ) . '</span>';
					}
					echo '</div>';
				}

				echo '<div class="mhbo-room-details">';
				echo '<p>' . esc_html( sprintf( I18n::get_label( 'label_max_guests' ), $type->max_adults ) ) . '</p>';
				echo '</div>';

				$checkout_url       = $this->get_booking_page_url();
				$clean_checkout_url = $this->remove_mhbo_query_args( $checkout_url );
				
				echo '<form method="get" action="' . esc_url( $clean_checkout_url ) . '">';
				self::render_url_hidden_inputs( $clean_checkout_url );
				echo '<input type="hidden" name="type_id" value="' . esc_attr( (string) $type->id ) . '">';
				echo '<button type="submit" class="mhbo-btn">' . esc_html( I18n::get_label( 'btn_view_room' ) ) . '</button>';
				echo '</form></div></div>';
			}
			echo '</div>';
		} else {
			echo '<p>' . esc_html( I18n::get_label( 'label_no_rooms' ) ) . '</p>';
		}

		echo '</div>';
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

/**
	 * Render the itemized receipt and transaction details on booking confirmation.
	 *
	 * @param object $booking The booking object.
	 * @return string Rendered HTML.
	 */
	private static function render_confirmation_receipt_details( object $booking ): string {
		global $wpdb;
		$currency         = Pricing::get_currency_code();
		$total_price      = Money::fromDecimal( (string) ( $booking->total_price ?? '0' ), $currency );
		$coupon_discount  = Money::fromDecimal( (string) ( $booking->coupon_discount ?? ( $booking->discount_amount ?? '0' ) ), $currency );
		$service_fee_cost = Money::fromDecimal( (string) ( $booking->service_fee_amount ?? '0' ), $currency );
		$extras_breakdown = Pricing::decode_stored_extras( $booking->booking_extras ?? null );

$extras_sum = Money::fromCents( 0, $currency );
		foreach ( $extras_breakdown as $ex ) {
			$amt        = isset( $ex['total'] ) ? (string) $ex['total'] : (string) ( $ex['price'] ?? '0' );
			$extras_sum = $extras_sum->add( Money::fromDecimal( $amt, $currency ) );
		}

		$tax_breakdown_data = null;
		if ( ! empty( $booking->tax_breakdown ) ) {
			$tax_breakdown_data = is_array( $booking->tax_breakdown ) ? $booking->tax_breakdown : json_decode( (string) $booking->tax_breakdown, true );
		} elseif ( ! empty( $booking->id ) && class_exists( Tax::class ) ) {
			$tax_breakdown_data = Tax::get_tax_breakdown( (int) $booking->id );
		}

		$raw_tax_mode  = (string) ( $booking->tax_mode ?? ( is_array( $tax_breakdown_data ) ? ( $tax_breakdown_data['mode'] ?? '' ) : '' ) );
		$tax_mode_norm = class_exists( Tax::class ) ? Tax::normalize_mode( '' !== $raw_tax_mode ? $raw_tax_mode : Tax::get_mode() ) : 'disabled';
		$is_vat_mode   = ( 'vat' === $tax_mode_norm );

		$room_net_m  = Money::fromDecimal( (string) ( $booking->room_total_net ?? '0' ), $currency );
		$room_tax_m  = Money::fromDecimal( (string) ( $booking->room_tax ?? '0' ), $currency );
		$child_net_m = Money::fromDecimal( (string) ( $booking->children_total_net ?? '0' ), $currency );
		$child_tax_m = Money::fromDecimal( (string) ( $booking->children_tax ?? '0' ), $currency );

		$children_cost = ( $is_vat_mode && $child_tax_m->isPositive() ) ? $child_net_m->add( $child_tax_m ) : $child_net_m;
		$room_base_m   = ( $is_vat_mode && $room_tax_m->isPositive() ) ? $room_net_m->add( $room_tax_m ) : $room_net_m;

		$coupon_reds = ( is_array( $tax_breakdown_data ) && isset( $tax_breakdown_data['coupon_reductions'] ) && is_array( $tax_breakdown_data['coupon_reductions'] ) )
			? $tax_breakdown_data['coupon_reductions']
			: null;
		$room_red_m  = null !== $coupon_reds
			? Money::fromDecimal( (string) ( $coupon_reds['room_reduction'] ?? '0' ), $currency )
			: $coupon_discount;
		$child_red_m = null !== $coupon_reds
			? Money::fromDecimal( (string) ( $coupon_reds['children_reduction'] ?? '0' ), $currency )
			: Money::fromCents( 0, $currency );

		if ( $child_red_m->isPositive() ) {
			$children_cost = $children_cost->add( $child_red_m );
		}

		if ( $room_base_m->isPositive() ) {
			$room_cost = $room_base_m->add( $room_red_m );
		} else {
			$subtotal_net_m = Money::fromDecimal( (string) ( $booking->subtotal_net ?? '0' ), $currency );
			if ( ! $is_vat_mode && $subtotal_net_m->isPositive() ) {
				$room_cost = $subtotal_net_m
					->add( $coupon_discount )
					->subtract( $children_cost )
					->subtract( $extras_sum )
					->subtract( $service_fee_cost );
			} else {
				$room_cost = $total_price
					->add( $coupon_discount )
					->subtract( $children_cost )
					->subtract( $extras_sum )
					->subtract( $service_fee_cost );
			}
		}
		if ( $room_cost->isNegative() ) {
			$room_cost = Money::fromCents( 0, $currency );
		}

		// Tax breakdown details
		$tax_enabled    = false;
		$formatted_tax  = null;
		$acc_tax_m      = Money::fromCents( 0, $currency );
		$ext_tax_m      = Money::fromCents( 0, $currency );
		$show_split_tax = false;
		$acc_rate_str   = '0';
		$ext_rate_str   = '0';

		if ( class_exists( Tax::class ) && ( Tax::is_enabled() || ! empty( $tax_breakdown_data['enabled'] ) ) ) {
			if ( is_array( $tax_breakdown_data ) && ! empty( $tax_breakdown_data['enabled'] ) ) {
				$tax_meta      = array(
					'guests'   => $booking->guests ?? 1,
					'children' => $booking->children ?? 0,
				);
				$formatted_tax = Tax::format_tax_breakdown( $tax_breakdown_data, null, $tax_meta );
				$tax_enabled   = (bool) ( $formatted_tax['enabled'] ?? false );

				if ( $tax_enabled ) {
					$acc_rate_str = Tax::format_rate( $tax_breakdown_data['rates']['accommodation'] ?? Tax::get_accommodation_rate() );
					$ext_rate_str = Tax::format_rate( $tax_breakdown_data['rates']['extras'] ?? Tax::get_extras_rate() );

					$totals    = $tax_breakdown_data['totals'] ?? array();
					$r_tax_m   = Money::fromDecimal( (string) ( $totals['room_tax'] ?? '0' ), $currency );
					$c_tax_m   = Money::fromDecimal( (string) ( $totals['children_tax'] ?? '0' ), $currency );
					$e_tax_m   = Money::fromDecimal( (string) ( $totals['extras_tax'] ?? '0' ), $currency );
					$sf_tax_m  = Money::fromDecimal( (string) ( $totals['service_fee_tax'] ?? '0' ), $currency );
					$acc_tax_m = isset( $totals['accommodation_tax'] )
						? Money::fromDecimal( (string) $totals['accommodation_tax'], $currency )
						: $r_tax_m->add( $c_tax_m );
					$ext_tax_m = $e_tax_m->add( $sf_tax_m );

					$show_split_tax = ( $acc_rate_str !== $ext_rate_str ) && ( $acc_tax_m->isPositive() || $ext_tax_m->isPositive() );
				}
			}
		}

		// Get Room Type Name
		$room_name = '';
		if ( ! empty( $booking->room_id ) ) {
			$room_id   = (int) $booking->room_id;
			$cache_key = 'mhbo_receipt_room_' . $room_id;
			$room_row  = wp_cache_get( $cache_key, 'mhbo' );

			if ( false === $room_row ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Result cached in object cache above
				$room_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT r.room_number, rt.name as type_name FROM {$wpdb->prefix}mhbo_rooms r LEFT JOIN {$wpdb->prefix}mhbo_room_types rt ON r.type_id = rt.id WHERE r.id = %d",
						$room_id
					)
				);
				if ( $room_row ) {
					wp_cache_set( $cache_key, $room_row, 'mhbo', 3600 );
				}
			}

			if ( $room_row && isset( $room_row->type_name ) ) {
				$room_name = (string) ( I18n::decode( (string) $room_row->type_name ) ?? '' );
			}
		}
		if ( empty( $room_name ) ) {
			$room_name = _x( 'Room', 'accommodation unit', 'modern-hotel-booking' );
		}

		ob_start();
		?>
		<div class="mhbo-confirmation-receipt">
			<h4 class="mhbo-confirmation-receipt-title"><?php echo esc_html( __( 'Reservation Breakdown', 'modern-hotel-booking' ) ); ?></h4>
			<div class="mhbo-summary-grid">
				<div class="mhbo-summary-header">
					<span><?php echo esc_html( _x( 'Item', 'cart item', 'modern-hotel-booking' ) ); ?></span>
					<span><?php echo esc_html( __( 'Amount', 'modern-hotel-booking' ) ); ?></span>
				</div>

				<div class="mhbo-summary-row">
					<span><?php echo esc_html( $room_name ); ?></span>
					<span><?php echo esc_html( $room_cost->format() ); ?></span>
				</div>

<?php
				foreach ( $extras_breakdown as $ex_item ) :
					$ex_name  = (string) I18n::decode( (string) ( $ex_item['name'] ?? '' ) );
					$ex_amt   = isset( $ex_item['total'] ) ? (string) $ex_item['total'] : (string) ( $ex_item['price'] ?? '0' );
					$ex_money = Money::fromDecimal( $ex_amt, $currency );
					$qty      = isset( $ex_item['quantity'] ) ? (int) $ex_item['quantity'] : 1;
					if ( $ex_money->isPositive() && '' !== $ex_name ) :
						?>
					<div class="mhbo-summary-row">
						<span><?php echo esc_html( $ex_name ); ?><?php echo ( $qty > 1 ) ? ' × ' . (int) $qty : ''; ?></span>
						<span><?php echo esc_html( $ex_money->format() ); ?></span>
					</div>
						<?php 
					endif;
				endforeach; 
				?>

<?php if ( $coupon_discount->isPositive() ) : ?>
					<div class="mhbo-summary-row" style="color:#16a34a; font-weight:700;">
						<span>
						<?php 
							/* translators: %s: coupon discount code */
							printf( esc_html__( 'Coupon (%s)', 'modern-hotel-booking' ), esc_html( strtoupper( (string) $booking->coupon_code ) ) ); 
						?>
						</span>
						<span>-<?php echo esc_html( $coupon_discount->format() ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( $tax_enabled && isset( $formatted_tax['totals']['subtotal_net_formatted'] ) ) : ?>
					<div class="mhbo-summary-row" style="font-weight:600; color:#475569; border-top:1px solid #e2e8f0; padding-top:6px;">
						<span><?php echo esc_html( I18n::get_label( 'label_subtotal' ) ); ?></span>
						<span><?php echo esc_html( $formatted_tax['totals']['subtotal_net_formatted'] ); ?></span>
					</div>

					<?php if ( $show_split_tax ) : ?>
						<?php if ( $acc_tax_m->isPositive() ) : ?>
							<div class="mhbo-summary-row" style="color:#64748b; font-size:13px;">
								<span><?php echo esc_html( sprintf( I18n::get_label( 'label_tax_accommodation' ), $formatted_tax['label'], $acc_rate_str ) ); ?></span>
								<span><?php echo esc_html( I18n::format_currency( $acc_tax_m ) ); ?></span>
							</div>
						<?php endif; ?>
						<?php if ( $ext_tax_m->isPositive() ) : ?>
							<div class="mhbo-summary-row" style="color:#64748b; font-size:13px;">
								<span><?php echo esc_html( sprintf( I18n::get_label( 'label_tax_extras' ), $formatted_tax['label'], $ext_rate_str ) ); ?></span>
								<span><?php echo esc_html( I18n::format_currency( $ext_tax_m ) ); ?></span>
							</div>
						<?php endif; ?>
					<?php else : ?>
						<div class="mhbo-summary-row" style="color:#64748b; font-size:13px;">
							<span><?php echo esc_html( sprintf( I18n::get_label( 'label_tax_rate' ), $formatted_tax['label'], $acc_rate_str ) ); ?></span>
							<span><?php echo esc_html( $formatted_tax['totals']['total_tax_formatted'] ); ?></span>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<div class="mhbo-summary-divider"></div>

				<div class="mhbo-summary-total">
					<span><?php echo esc_html( I18n::get_label( 'label_total' ) ); ?></span>
					<span class="mhbo-display-total"><?php echo esc_html( $total_price->format() ); ?></span>
				</div>
			</div>
		</div>

		<div class="mhbo-transaction-details">
			<?php
			$amts            = class_exists( Pricing::class ) ? Pricing::get_booking_payment_amounts( $booking ) : null;
			$total_m         = $amts ? $amts['total'] : Money::fromDecimal( (string) ( $booking->total_price ?? 0 ), $currency );
			$received_m      = $amts ? $amts['received'] : Money::fromDecimal( (string) ( $booking->payment_amount ?? 0 ), $currency );
			$remaining_m     = $amts ? $amts['remaining'] : Money::fromDecimal( (string) ( $booking->remaining_balance ?? 0 ), $currency );
			$deposit_m       = $amts ? $amts['deposit'] : Money::fromDecimal( (string) ( $booking->deposit_amount ?? 0 ), $currency );
			$is_deposit_rcvd = $amts ? $amts['deposit_received'] : ( (bool) ( $booking->deposit_received ?? false ) );
			$is_full_paid    = $amts ? $amts['is_paid'] : ( $booking->payment_status === 'completed' || (bool) ( $booking->payment_received ?? false ) );

			$status_label = I18n::get_label( 'label_paid' );
			if ( 'pending' === $booking->payment_status && 'arrival' === $booking->payment_method ) {
				$status_label = I18n::get_label( 'label_pay_arrival' );
			} elseif ( 'pending' === $booking->payment_status ) {
				$status_label = I18n::translate_status( 'pending' );
			} elseif ( 'deposit_paid' === $booking->payment_status || $is_deposit_rcvd ) {
				$status_label = __( 'Deposit Paid', 'modern-hotel-booking' );
			} elseif ( 'failed' === $booking->payment_status ) {
				$status_label = I18n::get_label( 'label_failed' );
			}
			?>
			<p><span><strong><?php echo esc_html( I18n::get_label( 'label_payment_status' ) ); ?>:</strong></span> <span><?php echo esc_html( $status_label ); ?></span></p>

			<?php if ( $is_full_paid ) : ?>
				<p><span><strong><?php echo esc_html( I18n::get_label( 'label_amount_paid' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $received_m->isPositive() ? $received_m : $total_m ) ); ?></span></p>
			<?php elseif ( $is_deposit_rcvd ) : ?>
				<p><span><strong><?php echo esc_html( __( 'Deposit Paid', 'modern-hotel-booking' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $received_m ) ); ?></span></p>
				<p><span><strong><?php echo esc_html( __( 'Remaining Balance (Due at Check-in)', 'modern-hotel-booking' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $remaining_m ) ); ?></span></p>
			<?php elseif ( 'arrival' === $booking->payment_method ) : ?>
				<?php if ( $deposit_m->isPositive() ) : ?>
					<p><span><strong><?php echo esc_html( I18n::get_label( 'label_deposit_required' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $deposit_m ) ); ?></span></p>
				<?php endif; ?>
				<p><span><strong><?php echo esc_html( __( 'Total Due on Arrival', 'modern-hotel-booking' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $total_m ) ); ?></span></p>
			<?php else : ?>
				<?php if ( $received_m->isPositive() ) : ?>
					<p><span><strong><?php echo esc_html( I18n::get_label( 'label_amount_paid' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $received_m ) ); ?></span></p>
				<?php endif; ?>
				<?php if ( $deposit_m->isPositive() ) : ?>
					<p><span><strong><?php echo esc_html( I18n::get_label( 'label_deposit_required' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $deposit_m ) ); ?></span></p>
				<?php endif; ?>
				<p><span><strong><?php echo esc_html( __( 'Total Amount Due', 'modern-hotel-booking' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::format_currency( $remaining_m ) ); ?></span></p>
			<?php endif; ?>

			<?php if ( '' !== (string) ( $booking->payment_method ?? '' ) ) : ?>
				<p><span><strong><?php echo esc_html( I18n::get_label( 'label_payment_method' ) ); ?>:</strong></span> <span><?php echo esc_html( I18n::translate_payment_method( (string) $booking->payment_method ) ); ?></span></p>
			<?php endif; ?>

			<?php if ( '' !== (string) ( $booking->payment_transaction_id ?? '' ) ) : ?>
				<p><span><strong><?php echo esc_html( I18n::get_label( 'label_transaction_id' ) ); ?>:</strong></span> <span style="font-family:monospace;"><?php echo esc_html( $booking->payment_transaction_id ); ?></span></p>
			<?php endif; ?>
		</div>

		<?php
		$portal_url = null;
		if ( class_exists( '\\MHBO\\Api\\GuestPortalController' ) && ! empty( $booking->booking_token ) ) {
			$portal_url = \MHBO\Api\GuestPortalController::get_portal_url( (string) $booking->booking_token, (int) $booking->id, (string) $booking->customer_email );
		}
		$conf_btn_enabled = (int) get_option( 'mhbo_confirmation_btn_enabled', 0 ) === 1;
		if ( $portal_url || $conf_btn_enabled ) :
			?>
			<div class="mhbo-confirmation-actions" style="margin-top:28px; display:flex; flex-wrap:wrap; justify-content:center; gap:12px; text-align:center;">
				<?php if ( $portal_url ) : ?>
					<a href="<?php echo esc_url( $portal_url ); ?>" class="mhbo-btn mhbo-btn-primary mhbo-confirmation-portal-btn" style="min-height:44px; display:inline-flex; align-items:center; justify-content:center; padding:12px 24px; border-radius:8px; font-weight:700; text-decoration:none;">
						<?php esc_html_e( 'View / Manage in Guest Portal &rarr;', 'modern-hotel-booking' ); ?>
					</a>
				<?php endif; ?>
				<?php
				if ( $conf_btn_enabled ) :
					$btn_text = (string) get_option( 'mhbo_confirmation_btn_text', __( 'Make Another Booking', 'modern-hotel-booking' ) );
					$btn_url  = (string) get_option( 'mhbo_confirmation_btn_url', '' );
					if ( '' === trim( $btn_url ) ) {
						$btn_url = home_url( '/' );
					}
					?>
					<a href="<?php echo esc_url( $btn_url ); ?>" class="mhbo-btn mhbo-btn-secondary mhbo-confirmation-cta-btn" style="min-height:44px; display:inline-flex; align-items:center; justify-content:center; padding:12px 24px; border-radius:8px; font-weight:600; text-decoration:none; background:#ffffff; border:1px solid #cbd5e1; color:#334155;">
						<?php echo esc_html( (string) I18n::decode( $btn_text ) ); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the Guest Self-Service Portal shortcode [mhbo_guest_portal].
	 *
	 * Allows guests to view their reservation, check payment status,
	 * cancel within policy window, and submit stay modifications.
	 *
	 * @param array<string, mixed>|string|false $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_guest_portal_shortcode( array|string|false $atts ): string {
		$parsed_atts = shortcode_atts(
			array(
				'token'      => '',
				'booking_id' => 0,
				'email'      => '',
			),
			is_array( $atts ) ? $atts : array(),
			'mhbo_guest_portal'
		);

		$initial_token = ! empty( $parsed_atts['token'] )
			? sanitize_text_field( (string) $parsed_atts['token'] )
			: ( isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ( isset( $_GET['booking_token'] ) ? sanitize_text_field( wp_unslash( $_GET['booking_token'] ) ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$initial_id    = ! empty( $parsed_atts['booking_id'] )
			? absint( $parsed_atts['booking_id'] )
			: ( isset( $_GET['booking_id'] ) ? absint( wp_unslash( $_GET['booking_id'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$initial_email = ! empty( $parsed_atts['email'] )
			? sanitize_email( (string) $parsed_atts['email'] )
			: ( isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$rest_url = rest_url( 'mhbo/v1/portal' );
		$nonce    = wp_create_nonce( 'wp_rest' );

		// Ensure core mhbo-style and guest portal assets are enqueued.
		if ( function_exists( 'wp_enqueue_style' ) && defined( 'MHBO_PLUGIN_URL' ) && defined( 'MHBO_VERSION' ) ) {
			wp_enqueue_style( 'mhbo-style', MHBO_PLUGIN_URL . 'assets/css/mhbo-style.css', array(), MHBO_VERSION );
			if ( ! wp_style_is( 'mhbo-guest-portal', 'enqueued' ) ) {
				$portal_css_ver = (string) ( defined( 'MHBO_PLUGIN_DIR' ) && file_exists( MHBO_PLUGIN_DIR . 'assets/css/mhbo-guest-portal.css' ) ? filemtime( MHBO_PLUGIN_DIR . 'assets/css/mhbo-guest-portal.css' ) : MHBO_VERSION );
				wp_enqueue_style( 'mhbo-guest-portal', MHBO_PLUGIN_URL . 'assets/css/mhbo-guest-portal.css', array( 'mhbo-style' ), $portal_css_ver );
			}
			if ( function_exists( 'wp_enqueue_script' ) && ! wp_script_is( 'mhbo-guest-portal', 'enqueued' ) ) {
				$portal_js_ver = (string) ( defined( 'MHBO_PLUGIN_DIR' ) && file_exists( MHBO_PLUGIN_DIR . 'assets/js/mhbo-guest-portal.js' ) ? filemtime( MHBO_PLUGIN_DIR . 'assets/js/mhbo-guest-portal.js' ) : MHBO_VERSION );
				wp_enqueue_script(
					'mhbo-guest-portal',
					MHBO_PLUGIN_URL . 'assets/js/mhbo-guest-portal.js',
					array(),
					$portal_js_ver,
					array(
						'strategy'  => 'defer',
						'in_footer' => true,
					)
				);
				if ( function_exists( 'wp_localize_script' ) ) {
					wp_localize_script(
						'mhbo-guest-portal',
						'mhbo_portal_i18n',
						array(
							'copied'               => __( 'Copied!', 'modern-hotel-booking' ),
							'copy'                 => I18n::get_label( 'label_copy' ),
							'copy_password'        => __( 'Copy Password', 'modern-hotel-booking' ),
							'submitting'           => __( 'Submitting...', 'modern-hotel-booking' ),
							'submit_request'       => __( 'Submit Request', 'modern-hotel-booking' ),
							'processing'           => __( 'Processing...', 'modern-hotel-booking' ),
							'confirm_cancellation' => __( 'Confirm Cancellation', 'modern-hotel-booking' ),
							'network_error'        => __( 'Network error while communicating with server. Please try again.', 'modern-hotel-booking' ),
							'render_error'         => __( 'An error occurred displaying your reservation details.', 'modern-hotel-booking' ),
							'invalid_details'      => __( 'Unable to find reservation. Please verify your details.', 'modern-hotel-booking' ),
							'empty_fields'         => __( 'Please enter either a booking token or both your Booking ID and Email address.', 'modern-hotel-booking' ),
							'empty_notes'          => __( 'Please provide details for your modification request.', 'modern-hotel-booking' ),
							'cancel_failed'        => __( 'Failed to process cancellation request.', 'modern-hotel-booking' ),
							'modify_failed'        => __( 'Failed to submit request.', 'modern-hotel-booking' ),
							'deposit_paid'         => __( 'Deposit Paid', 'modern-hotel-booking' ),
							'due_at_booking'       => __( 'Due at booking', 'modern-hotel-booking' ),
						)
					);
				}
			}
		}

ob_start();
		?>
		<div id="mhbo-guest-portal" class="mhbo-guest-portal-wrapper" 
			 data-rest-url="<?php echo esc_url( $rest_url ); ?>"
			 data-nonce="<?php echo esc_attr( $nonce ); ?>"
			 data-token="<?php echo esc_attr( $initial_token ); ?>"
			 data-id="<?php echo esc_attr( $initial_id > 0 ? (string) $initial_id : '' ); ?>"
			 data-email="<?php echo esc_attr( $initial_email ); ?>">

			<div id="mhbo-portal-alert" class="mhbo-portal-alert" role="alert" aria-live="polite"></div>

			<!-- Lookup View -->
			<div id="mhbo-portal-lookup-view" class="mhbo-portal-card">
				<div class="mhbo-portal-header">
					<h2 class="mhbo-portal-title"><?php esc_html_e( 'Guest Self-Service Portal', 'modern-hotel-booking' ); ?></h2>
					<p class="mhbo-portal-desc"><?php esc_html_e( 'Inspect your stay details, review billing receipts, pay outstanding balances, or submit reservation requests securely.', 'modern-hotel-booking' ); ?></p>
				</div>
				<form id="mhbo-portal-lookup-form" class="mhbo-portal-form">
					<div class="mhbo-portal-field-full">
						<label class="mhbo-portal-label" for="mhbo-lookup-token"><?php esc_html_e( 'Booking Security Token (from confirmation email)', 'modern-hotel-booking' ); ?></label>
						<input type="text" id="mhbo-lookup-token" class="mhbo-portal-input" placeholder="e.g. 7c9a62... (1-click direct link in your email)">
					</div>
					<div style="grid-column: 1 / -1; text-align: center; color: #94a3b8; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin: 6px 0;">
						— <?php esc_html_e( 'OR LOOK UP BY DETAILS', 'modern-hotel-booking' ); ?> —
					</div>
					<div>
						<label class="mhbo-portal-label" for="mhbo-lookup-id"><?php esc_html_e( 'Booking ID / Reference #', 'modern-hotel-booking' ); ?></label>
						<input type="number" id="mhbo-lookup-id" class="mhbo-portal-input" placeholder="e.g. 104">
					</div>
					<div>
						<label class="mhbo-portal-label" for="mhbo-lookup-email"><?php esc_html_e( 'Guest Email Address', 'modern-hotel-booking' ); ?></label>
						<input type="email" id="mhbo-lookup-email" class="mhbo-portal-input" placeholder="guest@example.com">
					</div>
					<div class="mhbo-portal-field-full" style="margin-top: 12px;">
						<button type="submit" id="mhbo-lookup-btn" class="mhbo-portal-btn mhbo-portal-btn-primary" style="width: 100%;">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
							<?php esc_html_e( 'Find My Reservation', 'modern-hotel-booking' ); ?>
						</button>
					</div>
				</form>
			</div>

			<!-- Details View -->
			<div id="mhbo-portal-details-view" class="mhbo-portal-card" style="display: none;">
				
				<!-- Official Print Header -->
				<div class="mhbo-print-header">
					<div style="display: flex; justify-content: space-between; align-items: flex-start;">
						<div>
							<h1 style="margin: 0; font-size: 24px; color: #0f172a;"><?php esc_html_e( 'Hotel Stay Itinerary & Folio Voucher', 'modern-hotel-booking' ); ?></h1>
							<p style="margin: 4px 0 0 0; font-size: 13px; color: #64748b;"><?php esc_html_e( 'Reservation Reference:', 'modern-hotel-booking' ); ?> #<span class="mhbo-print-res-id"></span> &bull; <span id="mhbo-print-hotel-name"></span></p>
						</div>
						<div style="text-align: right; font-size: 12px; color: #64748b;">
							<div><?php esc_html_e( 'Printed:', 'modern-hotel-booking' ); ?> <?php echo esc_html( date_i18n( get_option( 'date_format', 'F j, Y' ) ) ); ?></div>
							<div id="mhbo-print-hotel-address" style="margin-top: 2px;"></div>
							<div id="mhbo-print-hotel-contact"></div>
						</div>
					</div>
				</div>

				<!-- Personalized Hospitality Hero Banner -->
				<div id="mhbo-guest-hero" class="mhbo-guest-hero">
					<div id="mhbo-guest-avatar" class="mhbo-guest-avatar">G</div>
					<div style="flex: 1 1 auto;">
						<div style="display: flex; align-items: center; flex-wrap: wrap; gap: 8px;">
							<h3 id="mhbo-guest-greeting" class="mhbo-guest-greeting" style="margin: 0;"></h3>
							<span id="mhbo-guest-loyalty-badge" class="mhbo-vip-badge" style="display: none; background: #e0f2fe; color: #0369a1; border-color: #bae6fd;"></span>
						</div>
						<p id="mhbo-guest-stage-msg" class="mhbo-guest-stage-msg"></p>
					</div>
					<div id="mhbo-res-room-thumb-wrap" style="display: none;">
						<img id="mhbo-res-room-thumb" src="" alt="Room Preview" style="width: 64px; height: 64px; object-fit: cover; border-radius: 10px; border: 1px solid var(--gp-border);">
					</div>
				</div>

				<!-- At Your Service Concierge Quick-Actions Strip -->
				<div id="mhbo-qa-strip" class="mhbo-qa-strip">
					<a id="mhbo-qa-whatsapp" href="#" target="_blank" rel="noopener" class="mhbo-qa-pill" style="display: none; color: #15803d;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
						<?php esc_html_e( 'Chat with Front Desk', 'modern-hotel-booking' ); ?>
					</a>
					<a id="mhbo-qa-phone" href="#" class="mhbo-qa-pill" style="display: none;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
						<?php esc_html_e( 'Call Front Desk', 'modern-hotel-booking' ); ?>
					</a>
					<a id="mhbo-qa-directions" href="#" target="_blank" rel="noopener" class="mhbo-qa-pill" style="display: none;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"></polygon></svg>
						<?php esc_html_e( 'Get Directions', 'modern-hotel-booking' ); ?>
					</a>
					<a id="mhbo-qa-invoice" href="#" target="_blank" rel="noopener" class="mhbo-qa-pill" style="display: none;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
						<?php esc_html_e( 'Download Tax Invoice', 'modern-hotel-booking' ); ?>
					</a>
					<div class="mhbo-qa-dropdown-wrap" style="position: relative; display: inline-flex;">
						<button type="button" id="mhbo-qa-calendar-btn" class="mhbo-qa-pill">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
							<?php esc_html_e( 'Add to Calendar', 'modern-hotel-booking' ); ?> ▾
						</button>
						<div id="mhbo-qa-calendar-menu" class="mhbo-qa-dropdown-menu" style="display: none;">
							<a id="mhbo-cal-google" href="#" target="_blank" rel="noopener" class="mhbo-qa-dropdown-item"><?php esc_html_e( 'Google Calendar', 'modern-hotel-booking' ); ?></a>
							<a id="mhbo-cal-ics" href="#" class="mhbo-qa-dropdown-item"><?php esc_html_e( 'Apple / Outlook (.ics)', 'modern-hotel-booking' ); ?></a>
						</div>
					</div>
					<button type="button" id="mhbo-qa-share-btn" class="mhbo-qa-pill">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path><polyline points="16 6 12 2 8 6"></polyline><line x1="12" y1="2" x2="12" y2="15"></line></svg>
						<span id="mhbo-qa-share-text"><?php esc_html_e( 'Share Portal Link', 'modern-hotel-booking' ); ?></span>
					</button>
				</div>

				<!-- Multi-Room Group Reservation Banner -->
				<div id="mhbo-portal-group-card" class="mhbo-portal-group-card" style="display: none;">
					<div class="mhbo-portal-group-header">
						<div class="mhbo-portal-group-title">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
							<span><?php esc_html_e( 'Group Reservation', 'modern-hotel-booking' ); ?>: <strong id="mhbo-group-id-label"></strong></span>
						</div>
						<a id="mhbo-group-pay-btn" href="#" class="mhbo-portal-btn mhbo-portal-btn-primary mhbo-portal-btn-sm" style="display: none;">
							<?php esc_html_e( 'Pay Entire Group Balance', 'modern-hotel-booking' ); ?>
						</a>
					</div>
					<p style="font-size: 12.5px; color: #475569; margin: 6px 0 10px 0;"><?php esc_html_e( 'This reservation includes multiple rooms booked together. Click any room below to view its itinerary or individual charges.', 'modern-hotel-booking' ); ?></p>
					<div id="mhbo-group-siblings-list" class="mhbo-group-chips"></div>
				</div>

				<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 22px;">
					<div>
						<div style="font-size: 13px; color: #64748b; font-weight: 600;"><?php esc_html_e( 'Reservation', 'modern-hotel-booking' ); ?> #<span id="mhbo-res-id"></span></div>
						<h2 id="mhbo-res-room" class="mhbo-portal-title" style="margin-top: 4px; margin-bottom: 2px;"></h2>
						<div id="mhbo-res-room-type" style="font-size: 13px; color: #64748b; font-weight: 500;"></div>
					</div>
					<div style="text-align: right;">
						<span id="mhbo-res-status-badge" class="mhbo-portal-badge"></span>
						<div style="margin-top: 6px;"><span id="mhbo-res-payment-badge" class="mhbo-portal-badge"></span></div>
					</div>
				</div>

				<div class="mhbo-portal-timeline">
					<div class="mhbo-timeline-col">
						<div class="mhbo-timeline-label"><?php echo esc_html( __( 'CHECK IN', 'modern-hotel-booking' ) ); ?></div>
						<div id="mhbo-res-checkin" class="mhbo-timeline-val"></div>
						<div class="mhbo-stay-time"><span class="mhbo-stay-time-prefix"><?php echo esc_html( _x( 'FROM', 'Check-in time prefix', 'modern-hotel-booking' ) ); ?></span> <span id="mhbo-res-checkin-time"></span></div>
					</div>
					<div class="mhbo-timeline-col">
						<div class="mhbo-timeline-label"><?php echo esc_html( __( 'CHECK-OUT', 'modern-hotel-booking' ) ); ?></div>
						<div id="mhbo-res-checkout" class="mhbo-timeline-val"></div>
						<div class="mhbo-stay-time"><span class="mhbo-stay-time-prefix"><?php echo esc_html( _x( 'BY', 'Check-out time prefix', 'modern-hotel-booking' ) ); ?></span> <span id="mhbo-res-checkout-time"></span></div>
					</div>
					<div class="mhbo-timeline-col mhbo-timeline-col-nights">
						<div class="mhbo-timeline-label"><?php echo esc_html( __( 'NIGHTS', 'modern-hotel-booking' ) ); ?></div>
						<div id="mhbo-res-nights" class="mhbo-nights-count"></div>
						<div class="mhbo-nights-label"><?php echo esc_html( I18n::get_label( 'label_nights' ) ); ?></div>
					</div>
				</div>

				<!-- Guest & Contact Info Section -->
				<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 24px; margin-bottom: 8px;">
					<h4 style="margin: 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 700;"><?php esc_html_e( 'Guest & Contact Information', 'modern-hotel-booking' ); ?></h4>
					<button type="button" id="mhbo-portal-edit-profile-btn" class="mhbo-copy-btn" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 600;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 13px; height: 13px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
						<?php esc_html_e( 'Edit Contact Details', 'modern-hotel-booking' ); ?>
					</button>
				</div>
				<div class="mhbo-portal-guest-info">
					<div class="mhbo-portal-info-item">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Guest Name', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-name" class="mhbo-portal-info-val"></div>
					</div>
					<div class="mhbo-portal-info-item">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Email Address', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-email" class="mhbo-portal-info-val"></div>
					</div>
					<div class="mhbo-portal-info-item">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Guests', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-guests" class="mhbo-portal-info-val"></div>
					</div>
					<div id="mhbo-res-phone-wrap" class="mhbo-portal-info-item">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Phone Number', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-phone" class="mhbo-portal-info-val"></div>
					</div>
					<div id="mhbo-res-arrival-wrap" class="mhbo-portal-info-item" style="display: none;">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Estimated Arrival Time', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-arrival" class="mhbo-portal-info-val"></div>
					</div>
					<div id="mhbo-res-requests-wrap" class="mhbo-portal-info-item" style="display: none; grid-column: 1 / -1;">
						<div class="mhbo-portal-info-label"><?php esc_html_e( 'Special Requests & Preferences', 'modern-hotel-booking' ); ?></div>
						<div id="mhbo-res-requests" class="mhbo-portal-info-val" style="font-size: 13.5px; line-height: 1.5;"></div>
					</div>
				</div>

				<div class="mhbo-portal-finances">
					<div class="mhbo-finance-row">
						<span><?php esc_html_e( 'Total Reservation Charges', 'modern-hotel-booking' ); ?>:</span>
						<strong id="mhbo-res-total"></strong>
					</div>
					<div id="mhbo-res-coupon-row" class="mhbo-finance-row" style="display: none; color: #16a34a;">
						<span><?php esc_html_e( 'Promotional Coupon Discount', 'modern-hotel-booking' ); ?> (<strong id="mhbo-res-coupon-code"></strong>):</span>
						<span id="mhbo-res-coupon-discount" style="font-weight: 600;"></span>
					</div>
					<div id="mhbo-res-service-fee-row" class="mhbo-finance-row" style="display: none;">
						<span><span id="mhbo-res-service-fee-label"><?php esc_html_e( 'Service Fee', 'modern-hotel-booking' ); ?></span>:</span>
						<span id="mhbo-res-service-fee-amount" style="font-weight: 600;"></span>
					</div>
					<div id="mhbo-res-tax-row" class="mhbo-finance-row" style="display: none;">
						<span><span id="mhbo-res-tax-label"><?php esc_html_e( 'Tax', 'modern-hotel-booking' ); ?></span>:</span>
						<span id="mhbo-res-tax-amount" style="font-weight: 600;"></span>
					</div>
					<div id="mhbo-res-deposit-row" class="mhbo-finance-row" style="display: none;">
						<span>
							<?php esc_html_e( 'Required Deposit', 'modern-hotel-booking' ); ?>
							<span id="mhbo-res-deposit-status-note" class="mhbo-deposit-status-note"></span>:
						</span>
						<strong id="mhbo-res-deposit-val"></strong>
					</div>
					<div class="mhbo-finance-row">
						<span><?php esc_html_e( 'Total Paid', 'modern-hotel-booking' ); ?>:</span>
						<span id="mhbo-res-paid" style="color: #166534; font-weight: 600;"></span>
					</div>
					<div class="mhbo-finance-row mhbo-finance-row-total">
						<span><?php esc_html_e( 'Balance Remaining Due', 'modern-hotel-booking' ); ?>:</span>
						<span id="mhbo-res-balance" style="color: var(--gp-accent);"></span>
					</div>
					<div id="mhbo-res-settled-badge" style="display: none; margin-top: 10px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 12.5px; color: #166534; font-weight: 600; text-align: center;">
						✓ <?php esc_html_e( 'Reservation Folio Fully Settled & Confirmed', 'modern-hotel-booking' ); ?>
					</div>
				</div>

				<!-- Property Contact & Location Card -->
				<div id="mhbo-hotel-contact-card" class="mhbo-hotel-card">
					<div class="mhbo-hotel-header">
						<div class="mhbo-hotel-title">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
							<?php esc_html_e( 'Property Details & Front Desk Contact', 'modern-hotel-booking' ); ?>
						</div>
					</div>
					<div class="mhbo-hotel-details-grid">
						<div id="mhbo-hotel-addr-wrap">
							<span class="mhbo-hotel-label"><?php esc_html_e( 'Address & Location', 'modern-hotel-booking' ); ?>:</span>
							<div id="mhbo-hotel-address" style="font-weight: 600; color: #1e293b; margin-top: 2px;"></div>
							<a id="mhbo-hotel-map-link" href="#" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--gp-primary); font-weight: 600; margin-top: 4px;">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 12px; height: 12px;"><polygon points="3 11 22 2 13 21 11 13 3 11"></polygon></svg>
								<?php esc_html_e( 'Open in Google Maps', 'modern-hotel-booking' ); ?> ↗
							</a>
						</div>
						<div>
							<span class="mhbo-hotel-label"><?php esc_html_e( 'Direct Front Desk Contact', 'modern-hotel-booking' ); ?>:</span>
							<div style="margin-top: 2px;">
								<a id="mhbo-hotel-phone-link" href="#" style="font-weight: 600; color: #1e293b; text-decoration: none;"></a>
							</div>
							<div style="margin-top: 2px;">
								<a id="mhbo-hotel-email-link" href="#" style="font-size: 12.5px; color: var(--gp-primary); font-weight: 500; text-decoration: none;"></a>
							</div>
							<div id="mhbo-hotel-wa-wrap" style="margin-top: 4px; display: none;">
								<a id="mhbo-hotel-wa-link" href="#" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: #15803d; font-weight: 600;">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 12px; height: 12px;"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
									<?php esc_html_e( 'WhatsApp Concierge Available', 'modern-hotel-booking' ); ?>
								</a>
							</div>
						</div>
					</div>
				</div>

				<!-- Wi-Fi Access Card -->
				<div id="mhbo-wifi-card" class="mhbo-wifi-card">
					<div class="mhbo-wifi-header">
						<div class="mhbo-wifi-title">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><path d="M5 12.55a11 11 0 0 1 14.08 0"></path><path d="M1.42 9a16 16 0 0 1 21.16 0"></path><path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path><line x1="12" y1="20" x2="12.01" y2="20"></line></svg>
							<?php esc_html_e( 'Complimentary Guest Wi-Fi', 'modern-hotel-booking' ); ?>
						</div>
					</div>
					<div class="mhbo-wifi-details">
						<div>
							<span style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700;"><?php esc_html_e( 'Network (SSID)', 'modern-hotel-booking' ); ?>:</span>
							<strong id="mhbo-wifi-ssid" style="margin-left: 4px;"></strong>
						</div>
						<div id="mhbo-wifi-pass-wrap">
							<span style="color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700;"><?php esc_html_e( 'Password', 'modern-hotel-booking' ); ?>:</span>
							<code id="mhbo-wifi-pass" style="margin-left: 4px; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: 700;"></code>
							<button type="button" id="mhbo-wifi-copy-btn" class="mhbo-copy-btn" style="margin-left: 6px;"><?php esc_html_e( 'Copy Password', 'modern-hotel-booking' ); ?></button>
						</div>
					</div>
				</div>

				<!-- Digital Guest Compendium & House Rules -->
				<div id="mhbo-guide-card" class="mhbo-guide-card">
					<div id="mhbo-guide-header" class="mhbo-guide-header">
						<span style="display: flex; align-items: center; gap: 8px;">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 17px; height: 17px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
							<?php esc_html_e( 'Property Guide & House Rules', 'modern-hotel-booking' ); ?>
						</span>
						<span id="mhbo-guide-arrow" style="font-size: 12px; color: #64748b;">▼</span>
					</div>
					<div id="mhbo-guide-content" class="mhbo-guide-content" style="display: none;"></div>
				</div>

<div class="mhbo-portal-actions">
					<button type="button" id="mhbo-portal-modify-btn" class="mhbo-portal-btn mhbo-portal-btn-secondary">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
						<?php esc_html_e( 'Request Stay Change', 'modern-hotel-booking' ); ?>
					</button>

					<button type="button" id="mhbo-portal-cancel-btn" class="mhbo-portal-btn mhbo-portal-btn-danger">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
						<span id="mhbo-cancel-btn-text"><?php esc_html_e( 'Cancel Reservation', 'modern-hotel-booking' ); ?></span>
					</button>

					<button type="button" onclick="window.print()" class="mhbo-portal-btn mhbo-portal-btn-secondary">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
						<?php esc_html_e( 'Print Itinerary', 'modern-hotel-booking' ); ?>
					</button>

<button type="button" id="mhbo-portal-reset-btn" class="mhbo-portal-btn mhbo-portal-btn-secondary" style="margin-left: auto;">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
						<?php esc_html_e( 'Look Up Another', 'modern-hotel-booking' ); ?>
					</button>
				</div>
			</div>

			<!-- Cancel Modal -->
			<div id="mhbo-portal-cancel-modal" class="mhbo-portal-modal" role="dialog" aria-modal="true" aria-labelledby="mhbo-cancel-modal-title">
				<div class="mhbo-modal-content">
					<h3 id="mhbo-cancel-modal-title" style="margin: 0 0 12px 0; color: #991b1b; font-size: 18px; font-weight: 700;"><?php esc_html_e( 'Cancel Reservation', 'modern-hotel-booking' ); ?></h3>
					<div id="mhbo-cancel-paid-warning" style="display: none; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; font-size: 13px; color: #991b1b;">
						<strong><?php esc_html_e( 'Payment on File:', 'modern-hotel-booking' ); ?></strong> <span id="mhbo-cancel-paid-amount"></span><br>
						<?php esc_html_e( 'Because a payment has been received, this cancellation requires front desk review to process any applicable refund according to policy.', 'modern-hotel-booking' ); ?>
					</div>
					<p id="mhbo-cancel-policy-notice" style="font-size: 13px; color: #64748b; margin-bottom: 16px; line-height: 1.5;"></p>
					<label class="mhbo-portal-label" for="mhbo-cancel-reason"><?php esc_html_e( 'Reason for cancellation (optional)', 'modern-hotel-booking' ); ?></label>
					<textarea id="mhbo-cancel-reason" class="mhbo-portal-input" rows="3" style="margin-bottom: 20px;" placeholder="<?php esc_attr_e( 'Please let us know why you need to cancel...', 'modern-hotel-booking' ); ?>"></textarea>
					<div style="display: flex; justify-content: flex-end; gap: 12px;">
						<button type="button" id="mhbo-cancel-modal-close" class="mhbo-portal-btn mhbo-portal-btn-secondary" aria-label="<?php esc_attr_e( 'Keep Reservation and close dialog', 'modern-hotel-booking' ); ?>"><?php esc_html_e( 'Keep Reservation', 'modern-hotel-booking' ); ?></button>
						<button type="button" id="mhbo-cancel-modal-confirm" class="mhbo-portal-btn mhbo-portal-btn-danger"><?php esc_html_e( 'Confirm Cancellation', 'modern-hotel-booking' ); ?></button>
					</div>
				</div>
			</div>

			<!-- Modify Modal -->
			<div id="mhbo-portal-modify-modal" class="mhbo-portal-modal" role="dialog" aria-modal="true" aria-labelledby="mhbo-mod-modal-title">
				<div class="mhbo-modal-content">
					<h3 id="mhbo-mod-modal-title" style="margin: 0 0 12px 0; color: #0f172a; font-size: 18px; font-weight: 700;"><?php esc_html_e( 'Change Reservation Dates & Guests', 'modern-hotel-booking' ); ?></h3>
					<div id="mhbo-mod-paid-info" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 12.5px; color: #1e40af;">
						<?php esc_html_e( 'A payment is already recorded for this booking. Your requested dates will be submitted to our front desk team to calculate any rate difference or refund before finalizing.', 'modern-hotel-booking' ); ?>
					</div>
					<div id="mhbo-mod-modal-error" style="display:none; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:13px; color:#991b1b;"></div>
					<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
						<div>
							<label class="mhbo-portal-label" for="mhbo-mod-checkin"><?php esc_html_e( 'New Check-In Date', 'modern-hotel-booking' ); ?></label>
							<input type="date" id="mhbo-mod-checkin" class="mhbo-portal-input" required>
						</div>
						<div>
							<label class="mhbo-portal-label" for="mhbo-mod-checkout"><?php esc_html_e( 'New Check-Out Date', 'modern-hotel-booking' ); ?></label>
							<input type="date" id="mhbo-mod-checkout" class="mhbo-portal-input" required>
						</div>
					</div>
					<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 6px;">
						<div>
							<label class="mhbo-portal-label" for="mhbo-mod-adults"><?php esc_html_e( 'Adults (Current Occupancy)', 'modern-hotel-booking' ); ?></label>
							<input type="number" id="mhbo-mod-adults" class="mhbo-portal-input" readonly style="background:#f8fafc; cursor:not-allowed;" value="1">
						</div>
						<div>
							<label class="mhbo-portal-label" for="mhbo-mod-children"><?php esc_html_e( 'Children (Current Occupancy)', 'modern-hotel-booking' ); ?></label>
							<input type="number" id="mhbo-mod-children" class="mhbo-portal-input" readonly style="background:#f8fafc; cursor:not-allowed;" value="0">
						</div>
					</div>
					<p style="font-size: 11.5px; color: #64748b; margin: 0 0 14px 0;"><?php esc_html_e( 'Guest occupancy remains the same as your confirmed reservation.', 'modern-hotel-booking' ); ?></p>
					<div style="margin-bottom: 20px;">
						<label class="mhbo-portal-label" for="mhbo-mod-notes"><?php esc_html_e( 'Special Requests / Reason for Change', 'modern-hotel-booking' ); ?></label>
						<textarea id="mhbo-mod-notes" class="mhbo-portal-input" rows="2" placeholder="<?php esc_attr_e( 'Additional notes for the front desk...', 'modern-hotel-booking' ); ?>"></textarea>
					</div>
					<div style="display: flex; justify-content: flex-end; gap: 12px;">
						<button type="button" id="mhbo-mod-modal-close" class="mhbo-portal-btn mhbo-portal-btn-secondary" aria-label="<?php esc_attr_e( 'Cancel and close modification dialog', 'modern-hotel-booking' ); ?>"><?php esc_html_e( 'Cancel', 'modern-hotel-booking' ); ?></button>
						<button type="button" id="mhbo-mod-modal-submit" class="mhbo-portal-btn mhbo-portal-btn-primary"><?php esc_html_e( 'Update Reservation', 'modern-hotel-booking' ); ?></button>
					</div>
				</div>
			</div>

			<!-- Edit Guest Profile Modal -->
			<div id="mhbo-portal-edit-profile-modal" class="mhbo-portal-modal" role="dialog" aria-modal="true" aria-labelledby="mhbo-edit-profile-modal-title">
				<div class="mhbo-modal-content">
					<h3 id="mhbo-edit-profile-modal-title" style="margin: 0 0 12px 0; color: #0f172a; font-size: 18px; font-weight: 700;"><?php esc_html_e( 'Update Contact Details', 'modern-hotel-booking' ); ?></h3>
					<p style="font-size: 13px; color: #64748b; margin-bottom: 16px; line-height: 1.5;"><?php esc_html_e( 'Keep your contact information up to date to ensure seamless check-in communications and receipts.', 'modern-hotel-booking' ); ?></p>
					<div id="mhbo-profile-modal-error" style="display:none; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:13px; color:#991b1b;"></div>
					<div style="margin-bottom: 12px;">
						<label class="mhbo-portal-label" for="mhbo-profile-name"><?php esc_html_e( 'Full Guest Name', 'modern-hotel-booking' ); ?></label>
						<input type="text" id="mhbo-profile-name" class="mhbo-portal-input" required>
					</div>
					<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
						<div>
							<label class="mhbo-portal-label" for="mhbo-profile-phone"><?php esc_html_e( 'Phone Number', 'modern-hotel-booking' ); ?></label>
							<input type="tel" id="mhbo-profile-phone" class="mhbo-portal-input" placeholder="+1 555-0199">
						</div>
						<div>
							<label class="mhbo-portal-label" for="mhbo-profile-email"><?php esc_html_e( 'Email Address', 'modern-hotel-booking' ); ?></label>
							<input type="email" id="mhbo-profile-email" class="mhbo-portal-input" required>
						</div>
					</div>
					<div style="margin-bottom: 12px;">
						<label class="mhbo-portal-label" for="mhbo-profile-arrival"><?php esc_html_e( 'Estimated Arrival Time', 'modern-hotel-booking' ); ?></label>
						<input type="text" id="mhbo-profile-arrival" class="mhbo-portal-input" placeholder="e.g. 15:00 / 3:00 PM">
					</div>
					<div style="margin-bottom: 20px;">
						<label class="mhbo-portal-label" for="mhbo-profile-requests"><?php esc_html_e( 'Special Requests & Preferences', 'modern-hotel-booking' ); ?></label>
						<textarea id="mhbo-profile-requests" class="mhbo-portal-input" rows="2" placeholder="<?php esc_attr_e( 'Dietary preferences, quiet room, late check-in notes...', 'modern-hotel-booking' ); ?>"></textarea>
					</div>
					<div style="display: flex; justify-content: flex-end; gap: 12px;">
						<button type="button" id="mhbo-profile-modal-close" class="mhbo-portal-btn mhbo-portal-btn-secondary"><?php esc_html_e( 'Cancel', 'modern-hotel-booking' ); ?></button>
						<button type="button" id="mhbo-profile-modal-submit" class="mhbo-portal-btn mhbo-portal-btn-primary"><?php esc_html_e( 'Save Changes', 'modern-hotel-booking' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
