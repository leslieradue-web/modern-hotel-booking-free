<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for locating, loading, and rendering email templates.
 *
 * Supports child theme overrides via:
 *   - {child-theme}/modern-hotel-booking/emails/{template}.php
 *   - {child-theme}/mhbo/emails/{template}.php
 *
 * Falls back safely to built-in plugin templates under templates/emails/
 * and inline hardcoded defaults if template files are missing.
 *
 * @package MHBO\Core
 * @since   2.6.2
 */
class EmailTemplate {

	/**
	 * Map of status keys to template filenames.
	 *
	 * @var array<string, string>
	 */
	private const TEMPLATE_MAP = array(
		'confirmed'                  => 'default-confirmed.php',
		'pending'                    => 'default-pending.php',
		'cancelled'                  => 'default-cancelled.php',
		'payment'                    => 'default-payment.php',
		'stay_dates_updated'         => 'default-stay-dates-updated.php',
		'cancellation_request'       => 'default-cancellation-request.php',
		'modification_request'       => 'default-modification-request.php',
		'email_updated'              => 'default-email-updated.php',
		'identity_verification'      => 'default-identity-verification.php',
		'admin_new_booking'          => 'default-admin-new-booking.php',
		'admin_payment'              => 'default-admin-payment.php',
		'admin_modification_request' => 'default-admin-modification-request.php',
		'admin_cancellation_request' => 'default-admin-cancellation-request.php',
		'admin_cancellation'         => 'default-admin-cancellation.php',
		
	);

	/**
	 * Get the default email template HTML for a given status.
	 *
	 * @param string $status Booking status or template key.
	 * @return string HTML template string with placeholders intact.
	 */
	public static function get_default( string $status ): string {
		$template_file = self::TEMPLATE_MAP[ $status ] ?? 'default-fallback.php';
		return self::render( $template_file, array( 'status' => $status ) );
	}

	/**
	 * Locate and render an email template partial.
	 *
	 * @param string               $template_name Filename within templates/emails/
	 * @param array<string, mixed> $args          Context variables extracted in template.
	 * @return string Rendered template string.
	 */
	public static function render( string $template_name, array $args = array() ): string {
		$located = self::locate( $template_name );
		if ( '' === $located || ! file_exists( $located ) ) {
			return self::get_hardcoded_fallback( (string) ( $args['status'] ?? '' ) );
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Explicit template scope extraction
		extract( $args, EXTR_SKIP );

		ob_start();
		include $located;
		$output = ob_get_clean();

		return false !== $output ? $output : '';
	}

	/**
	 * Locate template file, checking theme hierarchy first, then plugin directory.
	 *
	 * @param string $template_name Filename to locate.
	 * @return string Absolute file path or empty string if not found.
	 */
	public static function locate( string $template_name ): string {
		// 1. Check active theme & child theme
		if ( function_exists( 'locate_template' ) ) {
			$theme_file = locate_template(
				array(
					'modern-hotel-booking/emails/' . $template_name,
					'mhbo/emails/' . $template_name,
				)
			);
			if ( '' !== $theme_file && file_exists( $theme_file ) ) {
				return $theme_file;
			}
		}

		// 2. Fall back to plugin templates directory
		$plugin_path = defined( 'MHBO_PLUGIN_DIR' )
			? MHBO_PLUGIN_DIR . 'templates/emails/' . $template_name
			: dirname( __DIR__, 2 ) . '/templates/emails/' . $template_name;

		if ( file_exists( $plugin_path ) ) {
			return $plugin_path;
		}

		return '';
	}

	/**
	 * Fail-safe minimal fallback if template files are missing or unreadable.
	 *
	 * @param string $status Booking status.
	 * @return string Minimal HTML fallback.
	 */
	public static function get_hardcoded_fallback( string $status ): string {
		$guests_line = '<p>Guests: {guests}</p>';

return '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">'
			. '<p>Hello {customer_name},</p>'
			. '<p>Your booking #{booking_id} for {room_name} is now ' . esc_html( $status ) . '.</p>'
			. '<p>Check-in: {check_in} from {check_in_time}<br>Check-out: {check_out} by {check_out_time}</p>'
			. $guests_line
			. '<p>Best regards,<br>{company_name}</p>'
			. '</div>';
	}
}
