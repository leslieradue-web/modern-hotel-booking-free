<?php
/**
 * Block Bindings Integration.
 *
 * Integrates Modern Hotel Booking dynamic values into Gutenberg Block Bindings (WP 6.5+).
 *
 * @package MHBO\Frontend
 * @since   2.6.0
 */

declare(strict_types=1);

namespace MHBO\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BlockBindings
 *
 * Exposes hotel booking data to core blocks via the WordPress Block Bindings API.
 */
class BlockBindings {

	/**
	 * Initialize block binding registrations.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_bindings' ) );
	}

	/**
	 * Register custom block binding sources.
	 */
	public function register_bindings(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			'mhbo/booking-total',
			array(
				'label'              => 'MHBO Booking Total',
				'get_value_callback' => array( $this, 'get_booking_total_value' ),
			)
		);

		// Register attributes for pattern overrides (WP 7.0+).
		add_filter( 'block_bindings_supported_attributes', array( $this, 'supported_attributes' ), 10, 2 );
	}

	/**
	 * Value callback for the mhbo/booking-total source.
	 *
	 * @param mixed $source_args    Binding source arguments.
	 * @param mixed $block_instance Block instance object.
	 * @param mixed $attribute_name Attribute name bound to the source.
	 * @return string Formatted booking total string.
	 */
	public function get_booking_total_value( mixed $source_args, mixed $block_instance, mixed $attribute_name = '' ): string {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		unset( $source_args, $block_instance, $attribute_name );

		$total = '0.00';

		// Retrieve total from a secure server-side session or transient (e.g., WC()->session if WooCommerce is used).
		if ( function_exists( 'WC' ) ) {
			$wc = \WC();
			if ( is_object( $wc->session ) && method_exists( $wc->session, 'get' ) ) {
				$total = $wc->session->get( 'mhbo_booking_total', '0.00' );
			}
		} else {
			// Fallback for non-WC environments: read from a validated booking transient using a session ID.
			$session_id = isset( $_COOKIE['mhbo_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['mhbo_session'] ) ) : '';
			if ( '' !== $session_id ) {
				$cached_val = get_transient( 'mhbo_booking_total_' . $session_id );
				$total      = is_string( $cached_val ) ? $cached_val : '0.00';
			}
		}

		// Ensure mathematical structure.
		$total = number_format( (float) $total, 2, '.', '' );

		$currency = (string) get_option( 'mhbo_currency_symbol', '$' );
		return esc_html( $currency . $total );
	}

	/**
	 * Filter supported block attributes for bindings.
	 *
	 * @param mixed $supported_attributes Array of supported block attribute names.
	 * @param mixed $block_type           Optional block type slug (WordPress core may pass null for unnamed blocks).
	 * @return array<int|string, mixed> Updated array of supported block attribute names.
	 */
	public function supported_attributes( mixed $supported_attributes, mixed $block_type = null ): array {
		if ( ! is_array( $supported_attributes ) ) {
			$supported_attributes = array();
		}

		// Ensure core text blocks retain bindable `content` attribute support while tolerating null $block_type.
		if ( is_string( $block_type ) && in_array( $block_type, array( 'core/paragraph', 'core/heading' ), true ) && ! in_array( 'content', $supported_attributes, true ) ) {
			$supported_attributes[] = 'content';
		}

		return $supported_attributes;
	}
}
