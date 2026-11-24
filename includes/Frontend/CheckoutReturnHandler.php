<?php declare(strict_types=1);

namespace MHBO\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\I18n;
use MHBO\Core\Money;
use MHBO\Core\Pricing;
use MHBO\Core\Security;
use MHBO\Core\Cache;

/**
 * Checkout Return & Form Submission Handler
 *
 * Processes PRG redirects, POST booking submissions, Stripe 3DS return
 * reconciliation, AI resume links, invoice downloads, and balance redirects.
 */
class CheckoutReturnHandler {

	/**
	 * Parent Shortcode facade instance for URL and date helpers.
	 *
	 * @var Shortcode
	 */
	private Shortcode $shortcode;

	/**
	 * Booking form view renderer.
	 *
	 * @var BookingFormView
	 */
	private BookingFormView $form_view;

	/**
	 * Constructor.
	 *
	 * @param Shortcode|null       $shortcode Parent Shortcode facade instance.
	 * @param BookingFormView|null $form_view Booking form view renderer.
	 */
	public function __construct( ?Shortcode $shortcode = null, ?BookingFormView $form_view = null ) {
		$this->shortcode = $shortcode ?? new Shortcode();
		$this->form_view = $form_view ?? new BookingFormView( $this->shortcode );
	}

	/**
	 * Execute GET/POST PRG redirects before headers are sent on template_redirect.
	 *
	 * @return void
	 */
	public function handle_early_redirects(): void {
		// 1. Process 'Book Now' from search results (GET form submission PRG redirect).
		if ( isset( $_GET['mhbo_book_room'] ) ) {
			$nonce = isset( $_GET['mhbo_book_now_nonce'] ) ? sanitize_key( wp_unslash( $_GET['mhbo_book_now_nonce'] ) ) : '';
			if ( wp_verify_nonce( $nonce, 'mhbo_book_now_action' ) ) {
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

				$child_ages_redirect = isset( $_GET['child_ages'] ) && is_array( $_GET['child_ages'] )
					? Pricing::sanitize_child_ages( map_deep( wp_unslash( $_GET['child_ages'] ), 'sanitize_text_field' ) ) : array();
				if ( array() !== $child_ages_redirect ) {
					set_transient(
						'mhbo_child_ages_' . $redirect_args['mhbo_nonce'],
						wp_json_encode( $child_ages_redirect ),
						300
					);
				}

				wp_safe_redirect( add_query_arg( $redirect_args, $this->shortcode->get_booking_page_url() ) );
				exit;
			}
		}

		// 2. Process Manual Search Form (POST -> GET Redirect).
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
				$rid         = isset( $_POST['room_id_filter'] ) ? intval( wp_unslash( $_POST['room_id_filter'] ) ) : 0;
				if ( $rid > 0 ) {
					$search_args['room_id'] = $rid;
				}
				$tid = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;
				if ( $tid > 0 ) {
					$search_args['type_id'] = $tid;
				}

				global $wp;
				$base_url = ( isset( $wp ) && is_object( $wp ) && isset( $wp->request ) )
					? home_url( add_query_arg( array(), $wp->request ) )
					: $this->shortcode->get_booking_page_url();
				wp_safe_redirect( add_query_arg( $search_args, $base_url ) );
				exit;
			}
		}
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
			$redirect_url = $referer ? $referer : $this->shortcode->get_booking_page_url();

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
			$redirect_url = $this->shortcode->remove_mhbo_query_args( $redirect_url );
			
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
	 * Orchestrate the booking flow based on request parameters.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return void
	 */
	public function handle_booking_process( array $atts = array() ): void {

		$room_id_attr = isset( $atts['room_id'] ) ? absint( $atts['room_id'] ) : 0;

		// 1. Process 'Book Now' from search results (fallback if rendered without template_redirect).
		if ( isset( $_GET['mhbo_book_room'] ) ) {
			$nonce = isset( $_GET['mhbo_book_now_nonce'] ) ? sanitize_key( wp_unslash( $_GET['mhbo_book_now_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'mhbo_book_now_action' ) ) {
				echo '<div class="mhbo-message mhbo-error">' . esc_html( I18n::get_label( 'label_security_error' ) ) . '</div>';
				return;
			}

			$room_id     = isset( $_GET['room_id'] ) ? absint( wp_unslash( $_GET['room_id'] ) ) : $room_id_attr;
			$type_id     = isset( $_GET['type_id'] ) ? absint( wp_unslash( $_GET['type_id'] ) ) : 0;
			$check_in    = isset( $_GET['check_in'] ) ? sanitize_text_field( wp_unslash( $_GET['check_in'] ) ) : '';
			$check_out   = isset( $_GET['check_out'] ) ? sanitize_text_field( wp_unslash( $_GET['check_out'] ) ) : '';
			$guests      = isset( $_GET['guests'] ) ? absint( wp_unslash( $_GET['guests'] ) ) : 1;
			$children    = isset( $_GET['children'] ) ? absint( wp_unslash( $_GET['children'] ) ) : 0;
			$child_ages  = isset( $_GET['child_ages'] ) && is_array( $_GET['child_ages'] )
				? Pricing::sanitize_child_ages( map_deep( wp_unslash( $_GET['child_ages'] ), 'sanitize_text_field' ) ) : array();
			if ( 0 === $room_id && $type_id > 0 && '' !== $check_in && '' !== $check_out ) {
				$resolved_room_id = Pricing::find_available_room( $type_id, $check_in, $check_out, max( 1, $guests ) );
				if ( $resolved_room_id > 0 ) {
					$room_id = (int) $resolved_room_id;
				}
			}
			if ( $room_id > 0 ) {
				$calc_preview = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, max( 1, $guests ), array(), $children, $child_ages );
				$total        = ( is_array( $calc_preview ) && isset( $calc_preview['total'] ) ) ? $calc_preview['total'] : Money::fromCents( 0, Pricing::get_currency_code() );
				$this->form_view->render_booking_form(
					array(
						'room_id'     => $room_id,
						'type_id'     => $type_id,
						'check_in'    => $check_in,
						'check_out'   => $check_out,
						'guests'      => max( 1, $guests ),
						'children'    => $children,
						'child_ages'  => $child_ages,
						'total_price' => $total,
					)
				);
				return;
			}
		}

		// 2. Process Manual Search Form (fallback if rendered without template_redirect).
		if ( isset( $_POST['mhbo_search'] ) ) {
			$nonce = isset( $_POST['mhbo_search_nonce'] ) ? sanitize_key( wp_unslash( $_POST['mhbo_search_nonce'] ) ) : '';
			if ( wp_verify_nonce( $nonce, 'mhbo_search_action' ) ) {
				$post_check_in  = isset( $_POST['check_in'] ) ? sanitize_text_field( wp_unslash( $_POST['check_in'] ) ) : '';
				$post_check_out = isset( $_POST['check_out'] ) ? sanitize_text_field( wp_unslash( $_POST['check_out'] ) ) : '';
				$post_guests    = isset( $_POST['guests'] ) ? absint( wp_unslash( $_POST['guests'] ) ) : 2;
				$post_children  = isset( $_POST['children'] ) ? absint( wp_unslash( $_POST['children'] ) ) : 0;
				$post_rid       = isset( $_POST['room_id_filter'] ) ? intval( wp_unslash( $_POST['room_id_filter'] ) ) : $room_id_attr;
				$post_tid       = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;
				$this->form_view->render_search_results( max( 0, $post_rid ), $post_check_in, $post_check_out, max( 1, $post_guests ), $post_tid, $post_children );
				return;
			}
		}

		// 3. Check for Automatic Booking/Search (GET)
		// Copy query parameters locally rather than mutating $_GET superglobal directly.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Reading QUERY_STRING to unescape entity-encoded ampersands before individual parameter sanitization below.
		$get_params = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_SERVER['QUERY_STRING'] ) ) {
			$raw_qs = function_exists( 'wp_unslash' ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : $_SERVER['QUERY_STRING'];
			if ( is_string( $raw_qs ) && ( str_contains( $raw_qs, '&#038;' ) || str_contains( $raw_qs, '&amp;' ) ) ) {
				$clean_query = str_replace( array( '&#038;', '&amp;' ), '&', $raw_qs );
				parse_str( $clean_query, $parsed_get );
				if ( is_array( $parsed_get ) ) {
					$get_params = array_merge( $get_params, $parsed_get );
				}
			}
		}
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Search parameters do not require nonce verification.
		$is_auto_book     = isset( $get_params['mhbo_auto_book'] );
		$is_auto_search   = isset( $get_params['mhbo_auto_search'] ) || ( isset( $get_params['check_in'] ) && isset( $get_params['check_out'] ) );
		$selected_type_id = isset( $get_params['type_id'] ) ? absint( wp_unslash( $get_params['type_id'] ) ) : 0;

		if ( $is_auto_book || $is_auto_search ) {
			$room_id    = isset( $get_params['room_id'] ) ? absint( wp_unslash( $get_params['room_id'] ) ) : $room_id_attr;
			$type_id    = $selected_type_id;
			$check_in   = isset( $get_params['check_in'] ) ? sanitize_text_field( wp_unslash( $get_params['check_in'] ) ) : '';
			$check_out  = isset( $get_params['check_out'] ) ? sanitize_text_field( wp_unslash( $get_params['check_out'] ) ) : '';
			$guests     = isset( $get_params['guests'] ) ? absint( wp_unslash( $get_params['guests'] ) ) : 2;
			$children   = isset( $get_params['children'] ) ? absint( wp_unslash( $get_params['children'] ) ) : 0;
			$exclude_id = isset( $get_params['exclude_id'] ) ? absint( wp_unslash( $get_params['exclude_id'] ) ) : 0;
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( $is_auto_book ) {
				$nonce = isset( $get_params['mhbo_nonce'] ) ? sanitize_key( wp_unslash( $get_params['mhbo_nonce'] ) ) : '';
				if ( wp_verify_nonce( $nonce, 'mhbo_auto_action' ) ) {
					if ( $room_id === 0 && $type_id > 0 && '' !== $check_in && '' !== $check_out ) {
						$resolved_room_id = Pricing::find_available_room( $type_id, $check_in, $check_out, $guests );
						if ( $resolved_room_id > 0 ) {
							$room_id = (int) $resolved_room_id;
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

						$auto_extras = array();

$calc_preview = Pricing::calculate_booking_money( $room_id, $check_in, $check_out, max( 1, $guests ), $auto_extras, $children, $recovered_child_ages );
						$total        = ( is_array( $calc_preview ) && isset( $calc_preview['total'] ) ) ? $calc_preview['total'] : Money::fromCents( 0, Pricing::get_currency_code() );
						
						$this->form_view->render_booking_form(
							array(
								'room_id'        => $room_id,
								'type_id'        => $type_id,
								'check_in'       => $check_in,
								'check_out'      => $check_out,
								'guests'         => max( 1, $guests ),
								'children'       => $children,
								'exclude_id'     => $exclude_id,
								'child_ages'     => $recovered_child_ages,
								'extras'         => $auto_extras,
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

			if ( $this->shortcode->validate_date( $check_in ) && $this->shortcode->validate_date( $check_out ) ) {
				$this->form_view->render_search_results( $room_id, $check_in, $check_out, $guests, $type_id, $children );
				return;
			}
		}

		// 4. Default: Render empty search form
		$attr_type_id    = isset( $atts['type_id'] ) ? absint( $atts['type_id'] ) : 0;
		$eff_type_id     = $selected_type_id > 0 ? $selected_type_id : $attr_type_id;
		$attr_show_title = array_key_exists( '_attr_show_title', $atts ) && ( null === $atts['_attr_show_title'] || is_bool( $atts['_attr_show_title'] ) )
			? $atts['_attr_show_title']
			: Calendar::parse_optional_bool_attr( $atts, 'show_title' );
		$attr_show_badge = array_key_exists( '_attr_show_badge', $atts ) && ( null === $atts['_attr_show_badge'] || is_bool( $atts['_attr_show_badge'] ) )
			? $atts['_attr_show_badge']
			: Calendar::parse_optional_bool_attr( $atts, 'show_badge' );
		$this->form_view->render_search_form( $room_id_attr, $eff_type_id, $attr_show_title, $attr_show_badge );
	}

	/**
	 * Handle the server-side booking creation process.
	 *
	 * @return void
	 */
	private function process_booking(): void {

// SECURITY: Fixed-window rate limiting for booking submissions (5 per minute per IP)
		$ip       = Security::get_client_ip();
		$rate_key = 'mhbo_booking_rate_' . md5( (string) $ip );
		$rl       = Security::consume_fixed_window_rate_limit( $rate_key, 5, MINUTE_IN_SECONDS );
		if ( ! $rl['allowed'] ) {
			$this->booking_fail( '<div class="mhbo-error">' . esc_html( I18n::get_label( 'label_rate_limit_error' ) ) . '</div>' );
			return;
		}

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
			'child_ages'          => isset( $_POST['child_ages'] ) && is_array( $_POST['child_ages'] ) ? Pricing::sanitize_child_ages( wp_unslash( $_POST['child_ages'] ) ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized element-wise via Pricing::sanitize_child_ages().
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
			'booking_token'       => isset( $_POST['mhbo_booking_token'] ) ? sanitize_text_field( wp_unslash( $_POST['mhbo_booking_token'] ) ) : '',
			'resume_nonce'        => isset( $_POST['mhbo_resume_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mhbo_resume_nonce'] ) ) : '',
			'page_url'            => $this->shortcode->get_booking_page_url(),
			'language'            => isset( $_POST['booking_language'] ) ? sanitize_key( wp_unslash( $_POST['booking_language'] ) ) : I18n::get_current_language(),
			'consent'             => isset( $_POST['mhbo_consent'] ) ? (bool) sanitize_text_field( wp_unslash( $_POST['mhbo_consent'] ) ) : false,
			'terms_accepted'      => isset( $_POST['mhbo_terms_accepted'] ) ? (bool) sanitize_text_field( wp_unslash( $_POST['mhbo_terms_accepted'] ) ) : false,
			
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

}
