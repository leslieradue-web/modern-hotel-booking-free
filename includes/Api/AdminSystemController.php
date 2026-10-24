<?php declare(strict_types=1);

namespace MHBO\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;
use MHBO\Core\I18n;
use MHBO\Core\Cache;
use MHBO\Core\Money;
use MHBO\Core\License;
use MHBO\Core\LicenseManager;
use MHBO\Core\Email;
use MHBO\Core\HotelTime;
use MHBO\Core\Pricing;

/**
 * REST API Controller for Administrative System, License, Cache, and Extras Operations (Group 1).
 *
 * Exposes typed REST endpoints under /wp-json/mhbo/v1/admin/ replacing legacy AJAX handlers:
 * - System cache clearing & banner dismissal
 * - Commercial licensing operations (Pro only)
 * - Room extras CRUD operations (Pro only)
 * - Booking balance collection management
 *
 * @package MHBO\Api
 * @since   2.5.0
 */
class AdminSystemController extends AbstractAdminRestController {

	/**
	 * Register REST routes for Group 1 administrative endpoints.
	 */
	public function register_routes(): void {

// System Cache Flush
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/system/cache/clear',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_cache' ),
				'permission_callback' => array( $this, 'check_system_admin_permission' ),
			)
		);

		// Admin Upgrade Banner Dismissal
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/system/banner/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_banner' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

// Bookings: Mark balance as collected
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bookings/(?P<id>\d+)/balance-collected',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_balance_collected' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'id' => array(
						'description'       => 'Booking database ID.',
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && (int) $param > 0;
						},
					),
				),
			)
		);

		// Email: Deliverability & DNS Diagnostic
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/email/diagnostic',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_email_diagnostic' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Email: Send Diagnostic Test Probe
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/email/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_test_email' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => array(
					'recipient' => array(
						'description'       => 'Recipient email address for diagnostic test probe.',
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);

		// Email: Clear Last Recorded Delivery Failure
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/email/clear-failure',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_email_failure' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

/**
	 * POST /admin/system/cache/clear
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function clear_cache( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( class_exists( Cache::class ) ) {
			$result = Cache::flush_all();
			if ( $result ) {
				$ext_cache       = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
				$group_supported = function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' );

				return $this->success(
					array(
						'message'               => (string) I18n::get_label( 'msg_cache_cleared' ),
						'object_cache_active'   => $ext_cache,
						'group_flush_supported' => $group_supported,
						'versions'              => array(
							'bookings'           => Cache::get_version( Cache::TABLE_BOOKINGS ),
							'rooms'              => Cache::get_version( Cache::TABLE_ROOMS ),
							'pricing_rules'      => Cache::get_version( Cache::TABLE_PRICING_RULES ),
							'calendar_overrides' => Cache::get_version( Cache::TABLE_CALENDAR_OVERRIDES ),
							'settings'           => Cache::get_version( Cache::TABLE_SETTINGS ),
							'guests'             => Cache::get_version( Cache::TABLE_GUESTS ),
						),
						'timestamp'             => current_time( 'mysql' ),
					)
				);
			}

			return $this->error(
				'cache_clear_failed',
				(string) I18n::get_label( 'msg_cache_failed' ),
				500
			);
		}

		return $this->error(
			'cache_unavailable',
			(string) I18n::get_label( 'msg_cache_unavailable' ),
			500
		);
	}

	/**
	 * POST /admin/system/banner/dismiss
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function dismiss_banner( WP_REST_Request $request ): WP_REST_Response {
		update_option( 'mhbo_banner_dismissed', 1, false );

		return $this->success(
			array(
				'dismissed' => true,
			)
		);
	}

/**
	 * POST /admin/bookings/(?P<id>\d+)/balance-collected
	 *
	 * Idempotent, race-safe atomic state transition for booking balance collection.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_balance_collected( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking_id = (int) $request->get_param( 'id' );
		if ( $booking_id <= 0 ) {
			return $this->error( 'invalid_booking', (string) I18n::get_label( 'msg_invalid_booking' ), 400 );
		}

		global $wpdb;

		// Format zero balance consistently according to currency decimal precision with exception guard
		$currency = strtoupper( (string) get_option( 'mhbo_currency_code', 'USD' ) );
		try {
			$zero_balance = Money::fromDecimal( '0', $currency )->toDecimal();
		} catch ( \Throwable $e ) {
			return $this->error( 'invalid_currency_configuration', (string) I18n::get_label( 'msg_failed_update_booking' ), 500 );
		}

		$append_note = "\n" . sprintf( (string) I18n::get_label( 'msg_balance_collected' ), current_time( 'mysql' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$booking_before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'mhbo_bookings', $booking_id ) );

		$encoded_ledger = null;
		if ( $booking_before ) {
			$total_p     = Money::fromDecimal( (string) ( $booking_before->total_price ?? 0 ), $currency );
			$paid_b      = Money::fromDecimal( (string) ( $booking_before->payment_amount ?? 0 ), $currency );
			$rem_b       = $total_p->subtract( $paid_b );
			$collect_amt = $rem_b->isPositive() ? $rem_b->toDecimal() : $total_p->toDecimal();

			$ledger       = class_exists( Pricing::class ) ? Pricing::get_payment_ledger( $booking_before ) : array();
			$current_user = wp_get_current_user();
			$staff_name   = $current_user && ! empty( $current_user->display_name ) ? $current_user->display_name : 'Staff';
			$ledger[]     = array(
				'id'             => 'pay_' . $booking_id . '_' . ( count( $ledger ) + 1 ),
				'type'           => 'balance',
				'amount'         => $collect_amt,
				'currency'       => $currency,
				'method'         => 'manual',
				'transaction_id' => 'MANUAL-BAL-' . $booking_id,
				'date'           => current_time( 'mysql' ),
				/* translators: %s: Staff member display name */
				'note'           => sprintf( __( 'Balance marked as collected by %s via Admin Overview', 'modern-hotel-booking' ), $staff_name ),
			);
			$encoded_ledger = wp_json_encode( $ledger );
		}

		// Write-first atomic transition: update only if not already collected (NULL-safe via COALESCE)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: Using %i for safe table placeholder.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
                 SET balance_status = %s,
                     balance_collected_via = %s,
                     balance_payment_date = %s,
                     remaining_balance = %s,
                     payment_status = %s,
                     payment_received = %d,
                     payment_amount = total_price,
                     payment_date = %s,
                     status = %s,
                     payment_ledger = COALESCE(%s, payment_ledger),
                     admin_notes = CONCAT(COALESCE(admin_notes, %s), %s)
                 WHERE id = %d AND COALESCE(balance_status, %s) != %s',
				$wpdb->prefix . 'mhbo_bookings',
				'collected',
				'manual',
				current_time( 'mysql' ),
				$zero_balance,
				'completed',
				1,
				current_time( 'mysql' ),
				'confirmed',
				$encoded_ledger,
				'',
				$append_note,
				$booking_id,
				'',
				'collected'
			)
		);

		if ( false === $updated ) {
			return $this->error( 'update_failed', (string) I18n::get_label( 'msg_failed_update_booking' ), 500 );
		}

		if ( 0 === $updated ) {
			// Disambiguate between nonexistent booking and already-collected booking
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 2026 BP: Using %i for safe table placeholder.
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE id = %d',
					$wpdb->prefix . 'mhbo_bookings',
					$booking_id
				)
			);

			if ( ! $exists ) {
				return $this->error( 'booking_not_found', (string) I18n::get_label( 'msg_invalid_booking' ), 404 );
			}

			// Booking exists and was already collected — return idempotent 200 with zero duplicate side effects
			return $this->success(
				array(
					'booking_id'     => $booking_id,
					'balance_status' => 'collected',
				)
			);
		}

// Reached ONLY when this exact request successfully performed the state transition
		Cache::invalidate_booking( $booking_id );
		do_action( 'mhbo_balance_collected', $booking_id );
		do_action( 'mhbo_booking_balance_collected', $booking_id );

		return $this->success(
			array(
				'booking_id'     => $booking_id,
				'balance_status' => 'collected',
			)
		);
	}

	/**
	 * GET /admin/email/diagnostic
	 *
	 * Inspects mailer transport, sender domain alignment, and DNS TXT records (SPF, DMARC, MX).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_email_diagnostic( WP_REST_Request $request ): WP_REST_Response {
		$sender_email  = sanitize_email( (string) get_option( 'mhbo_notification_email', (string) get_option( 'admin_email' ) ) );
		$sender_domain = '';
		if ( str_contains( $sender_email, '@' ) ) {
			$parts         = explode( '@', $sender_email );
			$sender_domain = strtolower( trim( end( $parts ) ) );
		}

		$site_host   = strtolower( trim( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$site_domain = (string) preg_replace( '/^www\./', '', $site_host );

		// 1. Detect Active Mailer Engine
		$mailer_name = 'Default WordPress PHP mail()';
		$is_smtp     = false;

		if ( class_exists( 'WPMailSMTP\Core' ) || defined( 'WPMS_ON' ) ) {
			$mailer_name = 'WP Mail SMTP';
			$is_smtp     = true;
		} elseif ( defined( 'FLUENTMAIL' ) || function_exists( 'fluentMailGetSettings' ) ) {
			$mailer_name = 'FluentSMTP';
			$is_smtp     = true;
		} elseif ( class_exists( 'Postman' ) || defined( 'POST_SMTP_VER' ) ) {
			$mailer_name = 'Post SMTP';
			$is_smtp     = true;
		} elseif ( class_exists( 'EasyWPSMTP' ) ) {
			$mailer_name = 'Easy WP SMTP';
			$is_smtp     = true;
		} elseif ( defined( 'SMTP_HOST' ) || has_action( 'phpmailer_init' ) ) {
			$mailer_name = 'Custom SMTP / PHPMailer Hook';
			$is_smtp     = true;
		}

		// 2. Free Webmail Detection
		$free_webmail_providers = array(
			'gmail.com',
			'googlemail.com',
			'yahoo.com',
			'yahoo.co.uk',
			'hotmail.com',
			'outlook.com',
			'live.com',
			'msn.com',
			'icloud.com',
			'me.com',
			'mac.com',
			'aol.com',
			'protonmail.com',
			'proton.me',
			'zoho.com',
			'mail.com',
			'gmx.com',
		);
		$is_free_webmail = in_array( $sender_domain, $free_webmail_providers, true );

		// 3. Domain Alignment
		$domain_matches_site = ( '' !== $sender_domain && '' !== $site_domain && ( $sender_domain === $site_domain || str_ends_with( $sender_domain, '.' . $site_domain ) ) );

		// 4. DNS Records Preflight
		$spf_status   = 'unknown';
		$spf_record   = '';
		$dmarc_status = 'unknown';
		$dmarc_record = '';
		$dmarc_policy = '';
		$mx_found     = false;

		if ( ! empty( $sender_domain ) && ! $is_free_webmail && function_exists( 'dns_get_record' ) ) {
			// MX lookup
			if ( function_exists( 'checkdnsrr' ) ) {
				// phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden,WordPress.PHP.NoSilencedErrors.Discouraged -- Network calls may emit PHP warnings if DNS servers timeout.
				$mx_found = (bool) @checkdnsrr( $sender_domain, 'MX' );
			}

			// TXT lookup for SPF.
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden,WordPress.PHP.NoSilencedErrors.Discouraged -- Network calls may emit PHP warnings if DNS servers timeout.
			$txt_records = @dns_get_record( $sender_domain, DNS_TXT );
			if ( is_array( $txt_records ) ) {
				foreach ( $txt_records as $rec ) {
					$txt = (string) ( $rec['txt'] ?? '' );
					if ( str_starts_with( strtolower( trim( $txt ) ), 'v=spf1' ) ) {
						$spf_status = 'valid';
						$spf_record = $txt;
						break;
					}
				}
				if ( 'valid' !== $spf_status ) {
					$spf_status = 'missing';
				}
			}

			// DMARC lookup.
			$dmarc_host = '_dmarc.' . $sender_domain;
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Forbidden,WordPress.PHP.NoSilencedErrors.Discouraged -- Network calls may emit PHP warnings if DNS servers timeout.
			$dmarc_records = @dns_get_record( $dmarc_host, DNS_TXT );
			if ( is_array( $dmarc_records ) ) {
				foreach ( $dmarc_records as $rec ) {
					$txt = (string) ( $rec['txt'] ?? '' );
					if ( str_starts_with( strtolower( trim( $txt ) ), 'v=dmarc1' ) ) {
						$dmarc_status = 'valid';
						$dmarc_record = $txt;
						if ( preg_match( '/\bp=([a-z]+)\b/i', $txt, $matches ) ) {
							$dmarc_policy = strtolower( (string) $matches[1] );
						}
						break;
					}
				}
				if ( 'valid' !== $dmarc_status ) {
					$dmarc_status = 'missing';
				}
			}
		}

		// 5. Last Recorded Mail Failure
		$last_failure = get_option( 'mhbo_last_mail_failure', null );

		return $this->success(
			array(
				'sender_email'        => $sender_email,
				'sender_domain'       => $sender_domain,
				'site_domain'         => $site_domain,
				'mailer_name'         => $mailer_name,
				'is_smtp'             => $is_smtp,
				'is_free_webmail'     => $is_free_webmail,
				'domain_matches_site' => $domain_matches_site,
				'spf_status'          => $spf_status,
				'spf_record'          => $spf_record,
				'dmarc_status'        => $dmarc_status,
				'dmarc_record'        => $dmarc_record,
				'dmarc_policy'        => $dmarc_policy,
				'mx_found'            => $mx_found,
				'last_failure'        => is_array( $last_failure ) ? $last_failure : null,
			)
		);
	}

	/**
	 * POST /admin/email/test
	 *
	 * Dispatches a diagnostic test email and captures immediate error reasons via wp_mail_failed.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test_email( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$recipient = sanitize_email( (string) $request->get_param( 'recipient' ) );
		if ( empty( $recipient ) || ! is_email( $recipient ) ) {
			$current_user = wp_get_current_user();
			$recipient    = $current_user->user_email ?: (string) get_option( 'admin_email' );
		}

		if ( empty( $recipient ) || ! is_email( $recipient ) ) {
			return $this->error( 'invalid_recipient', __( 'Please provide a valid recipient email address for testing.', 'modern-hotel-booking' ), 400 );
		}

		$hotel_name   = (string) get_option( 'mhbo_hotel_name', get_bloginfo( 'name' ) );
		$sender_email = (string) get_option( 'mhbo_notification_email', get_option( 'admin_email' ) );

		// Capture wp_mail_failed
		$captured_error = null;
		$fail_callback  = function ( WP_Error $wp_error ) use ( &$captured_error ) {
			$captured_error = $wp_error;
		};
		add_action( 'wp_mail_failed', $fail_callback, 999 );

		/* translators: %s: Hotel or property name */
		$subject = sprintf( __( '[Modern Hotel Booking] Email Delivery Test for %s', 'modern-hotel-booking' ), $hotel_name );

		$headers = Email::get_transactional_headers( false ); // false = do not BCC admin on diagnostic test probe

		$mailer_hint = 'Default PHP mail()';
		if ( class_exists( 'WPMailSMTP\Core' ) || defined( 'WPMS_ON' ) ) {
			$mailer_hint = 'WP Mail SMTP';
		} elseif ( defined( 'FLUENTMAIL' ) || function_exists( 'fluentMailGetSettings' ) ) {
			$mailer_hint = 'FluentSMTP';
		} elseif ( class_exists( 'Postman' ) || defined( 'POST_SMTP_VER' ) ) {
			$mailer_hint = 'Post SMTP';
		} elseif ( class_exists( 'EasyWPSMTP' ) ) {
			$mailer_hint = 'Easy WP SMTP';
		} elseif ( defined( 'SMTP_HOST' ) || has_action( 'phpmailer_init' ) ) {
			$mailer_hint = 'Custom SMTP';
		}

		$probe_id = 'probe_' . wp_generate_password( 8, false );

		$body = '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>' . esc_html( $subject ) . '</title></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; padding: 30px; margin: 0; color: #1e293b;">
	<div style="max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
		<div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 24px; color: #ffffff;">
			<div style="font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin-bottom: 4px;">Modern Hotel Booking &bull; Diagnostics</div>
			<h2 style="margin: 0; font-size: 20px; font-weight: 700; color: #ffffff;">Email Delivery Test Successful &check;</h2>
		</div>
		<div style="padding: 24px;">
			<p style="font-size: 15px; line-height: 1.6; margin-top: 0;">This is a real-time diagnostic test message sent from <strong>' . esc_html( $hotel_name ) . '</strong> to verify that your WordPress server is capable of dispatching outgoing emails.</p>
			
			<table style="width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 13px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
				<tr><td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #64748b; width: 35%;">Sender Address:</td><td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0;"><code>' . esc_html( $sender_email ) . '</code></td></tr>
				<tr><td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #64748b;">Recipient:</td><td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0;"><code>' . esc_html( $recipient ) . '</code></td></tr>
				<tr><td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #64748b;">Active Mailer Engine:</td><td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0;"><strong style="color: #2563eb;">' . esc_html( $mailer_hint ) . '</strong></td></tr>
				<tr><td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #64748b;">Server Timestamp:</td><td style="padding: 10px 14px; border-bottom: 1px solid #e2e8f0;">' . esc_html( HotelTime::format_datetime( 'now' ) ) . '</td></tr>
				<tr><td style="padding: 10px 14px; font-weight: 600; color: #64748b;">Diagnostic Probe ID:</td><td style="padding: 10px 14px;"><code>' . esc_html( $probe_id ) . '</code></td></tr>
			</table>

			<div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 14px; border-radius: 4px; font-size: 13px; line-height: 1.5; color: #1e40af;">
				<strong>Delivery Notice:</strong> Since this email reached your inbox, your server was able to hand off the message. To ensure guest receipts never land in spam, verify that your domain has active SPF and DMARC DNS records.
			</div>
			' . ( class_exists( Email::class ) && method_exists( Email::class, 'render_social_links_html' ) ? Email::render_social_links_html() : '' ) . '
		</div>
		<div style="background: #f1f5f9; padding: 14px 24px; font-size: 12px; color: #64748b; text-align: center; border-top: 1px solid #e2e8f0;">
			Sent by Modern Hotel Booking &bull; ' . esc_html( home_url() ) . '
		</div>
	</div>
</body>
</html>';

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Diagnostic test probe dispatched explicitly by admin request.
		$sent = (bool) wp_mail( $recipient, $subject, $body, $headers );

		remove_action( 'wp_mail_failed', $fail_callback, 999 );

		if ( $sent ) {
			return $this->success(
				array(
					/* translators: %s: Recipient email address */
					'message'     => sprintf( __( 'Test email successfully dispatched to %s! Please check the recipient inbox (and Spam folder).', 'modern-hotel-booking' ), $recipient ),
					'recipient'   => $recipient,
					'mailer_name' => $mailer_hint,
					'probe_id'    => $probe_id,
				)
			);
		}

		$error_msg = $captured_error instanceof WP_Error ? $captured_error->get_error_message() : __( 'The server rejected the message (wp_mail returned false).', 'modern-hotel-booking' );

		return $this->error(
			'email_send_failed',
			$error_msg,
			500,
			array(
				'recipient'   => $recipient,
				'mailer_name' => $mailer_hint,
				'suggestion'  => __( 'Your web host is failing to send emails directly. We strongly recommend installing a free SMTP plugin like FluentSMTP or WP Mail SMTP to connect with Brevo, SendGrid, or Google Workspace.', 'modern-hotel-booking' ),
			)
		);
	}

	/**
	 * POST /admin/email/clear-failure
	 *
	 * Clears the last recorded email failure log.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function clear_email_failure( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		delete_option( 'mhbo_last_mail_failure' );
		return $this->success( array( 'cleared' => true ) );
	}
}
