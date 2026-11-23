<?php

/**
 * Chat Session — stores conversation history per visitor session.
 *
 * FREE:  WP transient (2-hour TTL, last 10 turns).
 * PRO:   Custom DB table with permanent storage and guest-email linking.
 *
 * @package MHBO\AI
 * @since 2.3.8
 */

declare(strict_types=1);

namespace MHBO\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\Cache;
use MHBO\Core\License;

use function sanitize_text_field;
use function wp_unslash;
use function get_transient;
use function set_transient;
use function delete_transient;
use function dbDelta;
use function wp_json_encode;
use function defined;
use function random_bytes;

use const HOUR_IN_SECONDS;
use const MINUTE_IN_SECONDS;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class ChatSession {

	private const COOKIE_NAME   = 'mhbo_chat_session';
	private const FREE_TTL      = 2 * \HOUR_IN_SECONDS;
	private const FREE_MAX_TURN = 10;       // max message pairs stored free
	private const COOKIE_TTL    = 8 * \HOUR_IN_SECONDS;

	/**
	 * Request-scoped active session ID so in-process ability calls resolve the
	 * same session even when sessionStorage is passed via JSON body or on turn 1.
	 *
	 * @var string
	 */
	private static string $active_session_id = '';

	// -------------------------------------------------------------------------
	// Session ID
	// -------------------------------------------------------------------------

	/**
	 * Explicitly set or reset the request-scoped active session ID.
	 *
	 * @param string $session_id 64-char hex session ID or empty string to reset.
	 */
	public static function set_active_session_id( string $session_id = '' ): void {
		self::$active_session_id = \preg_match( '/^[a-f0-9]{64}$/', $session_id ) ? $session_id : '';
	}

	/**
	 * Return the current session ID, creating one if needed.
	 * Prioritizes active request ID and HttpOnly cookie before verified client-supplied IDs.
	 *
	 * @param ?string $requested_id Optional session ID sent from client storage.
	 * @return string
	 */
	public static function get_session_id( ?string $requested_id = null ): string {
		// 1. Reuse the session resolved earlier in the same PHP request lifecycle.
		if ( '' !== self::$active_session_id ) {
			return self::$active_session_id;
		}

		// 2. Check existing HttpOnly cookie first (resists client sessionStorage fixation).
		$raw      = (string) ( \filter_input( \INPUT_COOKIE, self::COOKIE_NAME, \FILTER_UNSAFE_RAW ) ?? '' );
		$existing = '' !== $raw ? \sanitize_text_field( \wp_unslash( (string) $raw ) ) : '';

		if ( '' !== $existing && \preg_match( '/^[a-f0-9]{64}$/', (string) $existing ) ) {
			self::$active_session_id = (string) $existing;
			return (string) $existing;
		}

		// 3. Fallback to requested ID from client storage only if server-issued (or in PHPUnit tests).
		if ( null !== $requested_id && '' !== $requested_id ) {
			$clean_requested = \sanitize_text_field( \wp_unslash( $requested_id ) );
			if ( 1 === \preg_match( '/^[a-f0-9]{64}$/', $clean_requested ) ) {
				$issued = false !== \get_transient( 'mhbo_chat_sid_' . self::session_hash( $clean_requested ) );
				if ( $issued || \defined( 'MHBO_TESTING' ) ) {
					self::$active_session_id = $clean_requested;
					return $clean_requested;
				}
			}
		}

		// Generate a cryptographically secure session ID (random_bytes — PHP 7.0+ built-in).
		$session_id              = \bin2hex( \random_bytes( 32 ) );
		self::$active_session_id = (string) $session_id;
		\set_transient( 'mhbo_chat_sid_' . self::session_hash( $session_id ), 1, (int) self::COOKIE_TTL );

		self::send_session_cookie( (string) $session_id );

		return (string) $session_id;
	}

	/**
	 * Issue the HttpOnly chat session cookie when headers have not yet been sent.
	 *
	 * @param string $session_id 64-hex session identifier.
	 */
	private static function send_session_cookie( string $session_id ): void {
		if ( ! \headers_sent() ) {
			\setcookie(
				(string) self::COOKIE_NAME,
				$session_id,
				array(
					'expires'  => \time() + (int) self::COOKIE_TTL,
					'path'     => '/',
					'domain'   => '',
					'secure'   => \is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}

	/**
	 * Rotate an active chat session ID upon privilege escalation (e.g., OTP verification).
	 *
	 * @param string $old_session_id Current session ID.
	 * @return string Newly generated 64-hex session ID.
	 */
	public static function rotate_session( string $old_session_id ): string {
		$old_hash = self::session_hash( $old_session_id );
		$new_id   = \bin2hex( \random_bytes( 32 ) );
		$new_hash = self::session_hash( $new_id );

		// Migrate guest profile & multi-room transients to the rotated session.
		foreach ( array( 'mhbo_chat_name_', 'mhbo_chat_email_', 'mhbo_chat_phone_', 'mhbo_chat_mrp_', 'mhbo_chat_' ) as $prefix ) {
			$val = \get_transient( $prefix . $old_hash );
			if ( false !== $val ) {
				\set_transient( $prefix . $new_hash, $val, (int) self::FREE_TTL );
				\delete_transient( $prefix . $old_hash );
			}
		}

		// Migrate email verification status if present before deletion.
		$old_email = \get_transient( 'mhbo_chat_email_' . $new_hash );
		if ( \is_string( $old_email ) && '' !== $old_email ) {
			$email_md5 = \md5( \strtolower( $old_email ) );
			$old_ver   = \get_transient( 'mhbo_chat_verified_' . $old_hash . '_' . $email_md5 );
			if ( false !== $old_ver ) {
				\set_transient( 'mhbo_chat_verified_' . $new_hash . '_' . $email_md5, true, 30 * \MINUTE_IN_SECONDS );
				\delete_transient( 'mhbo_chat_verified_' . $old_hash . '_' . $email_md5 );
			}
		}

		\delete_transient( 'mhbo_chat_sid_' . $old_hash );
		\set_transient( 'mhbo_chat_sid_' . $new_hash, 1, (int) self::COOKIE_TTL );

self::$active_session_id = $new_id;
		self::send_session_cookie( $new_id );

		return $new_id;
	}

	// -------------------------------------------------------------------------
	// History
	// -------------------------------------------------------------------------

	/**
	 * Get conversation history for a session.
	 *
	 * @param string $session_id
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_history( string $session_id ): array {
		
		$transient_key = self::transient_key( $session_id );
		$history       = \get_transient( $transient_key );

		return self::normalize_window( \is_array( $history ) ? $history : array() );
	}

	/**
	 * Normalize a windowed conversation history to prevent orphaned tool calls or responses.
	 *
	 * Slicing history can sever an assistant's tool_calls from its tool response messages.
	 * Both OpenAI and Gemini return 400 Bad Request if an orphaned tool response or
	 * unfulfilled assistant tool call is included in the messages payload.
	 *
	 * @param array<int, array<string, mixed>> $history
	 * @return array<int, array<string, mixed>>
	 */
	public static function normalize_window( array $history ): array {
		if ( array() === $history ) {
			return array();
		}

		$history = \array_values( $history );

		// 1. Strip leading non-'user' messages (orphaned 'tool' or 'assistant' turns where the preceding 'user' was sliced off).
		while ( ! empty( $history ) && 'user' !== ( $history[0]['role'] ?? '' ) ) {
			\array_shift( $history );
		}

		// 2. Strip trailing unfulfilled assistant messages with tool_calls (no tool response followed).
		while ( ! empty( $history ) ) {
			$last = \end( $history );
			if ( 'assistant' === ( $last['role'] ?? '' ) && ! empty( $last['tool_calls'] ) ) {
				\array_pop( $history );
			} else {
				break;
			}
		}

		// 3. Re-verify leading messages: ensure history still starts with a 'user' turn.
		while ( ! empty( $history ) && 'user' !== ( $history[0]['role'] ?? '' ) ) {
			\array_shift( $history );
		}

		return $history;
	}

	/**
	 * Append a message to the session history.
	 *
	 * @param string       $session_id
	 * @param string       $role    'user' | 'assistant' | 'tool'
	 * @param string       $content
	 * @param array<mixed> $meta Optional metadata (tool_calls, raw_parts, thought_signature).
	 */
	public static function add_message( string $session_id, string $role, string $content, array $meta = array() ): void {

// Free path: transient.
		$transient_key = self::transient_key( $session_id );
		$history       = \get_transient( $transient_key );
		if ( ! \is_array( $history ) ) {
			$history = array();
		}

		$msg = array(
			'role'    => (string) $role,
			'content' => (string) $content,
		);
		if ( array() !== (array) $meta ) {
			$msg = \array_merge( $msg, (array) $meta );
		}
		$history[] = $msg;

		// Enforce free-tier limit (keep last N pairs of user+assistant).
		$limit = (int) self::FREE_MAX_TURN * 2;
		if ( \count( $history ) > $limit ) {
			$history = \array_slice( $history, - $limit );
		}
		\set_transient( $transient_key, $history, (int) self::FREE_TTL );
	}

	/**
	 * Store the guest's name in the session for proactive personalization.
	 *
	 * @param string $session_id
	 * @param string $name
	 */
	public static function set_guest_name( string $session_id, string $name ): void {
		\set_transient( 'mhbo_chat_name_' . self::session_hash( $session_id ), $name, (int) self::FREE_TTL );
	}

	/**
	 * Store the guest's email in the session.
	 *
	 * @param string $session_id
	 * @param string $email
	 */
	public static function set_guest_email( string $session_id, string $email ): void {
		\set_transient( 'mhbo_chat_email_' . self::session_hash( $session_id ), $email, (int) self::FREE_TTL );
	}

	/**
	 * Store the guest's phone in the session.
	 *
	 * @param string $session_id
	 * @param string $phone
	 */
	public static function set_guest_phone( string $session_id, string $phone ): void {
		\set_transient( 'mhbo_chat_phone_' . self::session_hash( $session_id ), $phone, (int) self::FREE_TTL );
	}

	// -------------------------------------------------------------------------
	// Multi-Room Plan State (2026 BP: Sequential One-Card-Per-Turn)
	// -------------------------------------------------------------------------

	/**
	 * Store a multi-room booking plan in the session.
	 *
	 * Called after CheckAvailability returns is_multi_room=true AND the
	 * pre-flight availability check confirms all rooms are available.
	 *
	 * @param string              $session_id
	 * @param array<string,mixed> $plan {
	 *     @type int      $total_rooms    Total rooms required (e.g. 3).
	 *     @type int      $current_index  0-based index of the next room to book.
	 *     @type string   $room_type_name Display name of the room type.
	 *     @type int      $type_id        Room type ID.
	 *     @type string   $check_in       Check-in date (Y-m-d).
	 *     @type string   $check_out      Check-out date (Y-m-d).
	 *     @type array[]  $distribution   Per-room split: [{room_id, adults, children}].
	 * }
	 */
	public static function set_multi_room_plan( string $session_id, array $plan ): void {
		$key = 'mhbo_chat_mrp_' . self::session_hash( $session_id );
		\set_transient( $key, $plan, (int) self::FREE_TTL );
	}

	/**
	 * Retrieve the active multi-room plan for this session.
	 *
	 * @param string $session_id
	 * @return array<string,mixed> The plan array, or empty array if none active.
	 */
	public static function get_multi_room_plan( string $session_id ): array {
		$key  = 'mhbo_chat_mrp_' . self::session_hash( $session_id );
		$plan = \get_transient( $key );
		return \is_array( $plan ) ? $plan : array();
	}

	/**
	 * Advance the multi-room plan to the next room.
	 *
	 * Increments current_index. If all rooms are booked, clears the plan.
	 *
	 * @param string $session_id
	 */
	public static function advance_multi_room( string $session_id ): void {
		$plan = self::get_multi_room_plan( $session_id );
		if ( array() === $plan ) {
			return;
		}

		$plan['current_index'] = ( (int) ( $plan['current_index'] ?? 0 ) ) + 1;

		if ( $plan['current_index'] >= (int) ( $plan['total_rooms'] ?? 0 ) ) {
			self::clear_multi_room_plan( $session_id );
			return;
		}

		self::set_multi_room_plan( $session_id, $plan );
	}

	/**
	 * Update a specific room in the distribution (e.g. after an availability swap).
	 *
	 * @param string $session_id
	 * @param int    $index       0-based index in the distribution array.
	 * @param int    $new_room_id Replacement room ID.
	 */
	public static function swap_multi_room_id( string $session_id, int $index, int $new_room_id ): void {
		$plan = self::get_multi_room_plan( $session_id );
		if ( array() === $plan || ! isset( $plan['distribution'][ $index ] ) ) {
			return;
		}

		$plan['distribution'][ $index ]['room_id'] = $new_room_id;
		self::set_multi_room_plan( $session_id, $plan );
	}

	/**
	 * Clear the multi-room plan (booking complete or abandoned).
	 *
	 * @param string $session_id
	 */
	public static function clear_multi_room_plan( string $session_id ): void {
		\delete_transient( 'mhbo_chat_mrp_' . self::session_hash( $session_id ) );
	}

	/**
	 * Set a verification code for the current session.
	 * 2026 BP: Uses transients for temporary, session-linked high-entropy codes.
	 *
	 * @param string $session_id
	 * @param string $code  6-digit numeric code.
	 * @param string $email The email address being verified.
	 */
	public static function set_verification_code( string $session_id, string $code, string $email ): void {
		$key = 'mhbo_chat_otp_' . self::session_hash( $session_id );
		\set_transient(
			$key,
			array(
				'code'  => (string) $code,
				'email' => (string) $email,
			),
			10 * \MINUTE_IN_SECONDS
		);
	}

	/**
	 * Verify a code for the current session.
	 * Mandatory brute-force protection: Lockout after 10 failed attempts (session or target email).
	 *
	 * @param string $session_id
	 * @param string $code
	 * @param bool   $rotate     Whether to rotate the session ID upon successful verification.
	 * @return array{success:bool,message:string}
	 */
	public static function verify_code( string $session_id, string $code, bool $rotate = false ): array {
		$short_id = self::session_hash( $session_id );
		$key      = 'mhbo_chat_otp_' . $short_id;
		$fail_key = 'mhbo_chat_otp_failed_' . $short_id;
		$stored   = \get_transient( $key );
		$attempts = (int) \get_transient( $fail_key );

		if ( $attempts >= 10 ) {
			\delete_transient( $key );
			return array(
				'success' => false,
				'message' => \MHBO\Core\I18n::get_label( 'ai_error_otp_lockout' ),
			);
		}

		if ( ! \is_array( $stored ) || '' === (string) ( $stored['code'] ?? '' ) ) {
			return array(
				'success' => false,
				'message' => \MHBO\Core\I18n::get_label( 'ai_error_otp_expired' ),
			);
		}

		$target_email    = \strtolower( (string) ( $stored['email'] ?? '' ) );
		$email_fail_key  = 'mhbo_chat_otp_efail_' . \md5( $target_email );
		$email_attempts  = (int) \get_transient( $email_fail_key );
		if ( $email_attempts >= 10 ) {
			\delete_transient( $key );
			return array(
				'success' => false,
				'message' => \MHBO\Core\I18n::get_label( 'ai_error_otp_lockout' ),
			);
		}

		if ( ! \hash_equals( (string) $stored['code'], (string) $code ) ) {
			$new_sess_attempts  = $attempts + 1;
			$new_email_attempts = $email_attempts + 1;
			\set_transient( $fail_key, $new_sess_attempts, 1 * \HOUR_IN_SECONDS );
			\set_transient( $email_fail_key, $new_email_attempts, 1 * \HOUR_IN_SECONDS );
			if ( $new_sess_attempts >= 10 || $new_email_attempts >= 10 ) {
				\delete_transient( $key );
			}
			return array(
				'success' => false,
				'message' => \sprintf(
					\MHBO\Core\I18n::get_label( 'ai_error_otp_invalid' ),
					max( 0, 10 - max( $new_sess_attempts, $new_email_attempts ) )
				),
			);
		}

		// Clear the OTP and failed counts.
		\delete_transient( $key );
		\delete_transient( $fail_key );
		\delete_transient( $email_fail_key );

		$target_hash = $short_id;
		if ( $rotate ) {
			$rotated     = self::rotate_session( $session_id );
			$target_hash = self::session_hash( $rotated );
		}

		// Mark as verified for this specific email (30-min verified window).
		$verified_key = 'mhbo_chat_verified_' . $target_hash . '_' . \md5( $target_email );
		\set_transient( $verified_key, true, 30 * \MINUTE_IN_SECONDS );

		return array(
			'success' => true,
			'message' => \MHBO\Core\I18n::get_label( 'ai_msg_otp_verified' ),
		);
	}

	/**
	 * Check if a specific email has been verified in the current session.
	 *
	 * @param string $session_id
	 * @param string $email
	 * @return bool
	 */
	public static function is_email_verified( string $session_id, string $email ): bool {
		$short_id     = self::session_hash( $session_id );
		$verified_key = 'mhbo_chat_verified_' . $short_id . '_' . \md5( \strtolower( (string) $email ) );
		return (bool) \get_transient( $verified_key );
	}

	/**
	 * Retrieve the guest's name if known for this session.
	 *
	 * @param string $session_id
	 * @return string
	 */
	public static function get_guest_name( string $session_id ): string {
		$name = \get_transient( 'mhbo_chat_name_' . self::session_hash( $session_id ) );
		return \is_string( $name ) ? $name : '';
	}

	/**
	 * Retrieve the guest's email if known for this session.
	 *
	 * @param string $session_id
	 * @return string
	 */
	public static function get_guest_email( string $session_id ): string {
		$email = \get_transient( 'mhbo_chat_email_' . self::session_hash( $session_id ) );
		return \is_string( $email ) ? $email : '';
	}

	/**
	 * Retrieve the guest's phone if known for this session.
	 *
	 * @param string $session_id
	 * @return string
	 */
	public static function get_guest_phone( string $session_id ): string {
		$phone = \get_transient( 'mhbo_chat_phone_' . self::session_hash( $session_id ) );
		return \is_string( $phone ) ? $phone : '';
	}

	/**
	 * Clear a session's history.
	 *
	 * @param string $session_id
	 */
	public static function clear_session( string $session_id ): void {
		\delete_transient( self::transient_key( $session_id ) );

}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Compute a fixed 32-char SHA-256 digest of a session ID for transient keys.
	 * Eliminates 32-char prefix collision when DB stores full 64-char session IDs.
	 *
	 * @param string $session_id
	 * @return string
	 */
	private static function session_hash( string $session_id ): string {
		return \substr( \hash( 'sha256', $session_id ), 0, 32 );
	}

	/**
	 * @param string $session_id
	 * @return string
	 */
	private static function transient_key( string $session_id ): string {
		return 'mhbo_chat_' . self::session_hash( $session_id );
	}

	// -------------------------------------------------------------------------
	// PRO: DB storage
	// -------------------------------------------------------------------------

}
