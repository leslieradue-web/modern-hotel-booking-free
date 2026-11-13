<?php
/**
 * AI Client — unified gateway to any configured AI provider.
 *
 * Supports:
 *  - WP 7.0+ native wp_ai_client_prompt() builder API (when available, with full tool declarations & history).
 *  - Direct HTTP provider APIs using the stored API key from plugin settings.
 *  - Pro Resilient Secondary Provider cascade (`fallback_prompt()`).
 *
 * @package MHBO\AI
 * @since 2.3.8
 * @since 2.4.0 Migrated Path 1 to WP 7.0 wp_ai_client_prompt() builder API.
 */

declare(strict_types=1);

namespace MHBO\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Throwable;

class Client {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/** Plugin-specific option prefix for AI settings. */
	private const OPT_PREFIX = 'mhbo_ai_';

	/** Provider identifiers. */
	public const PROVIDER_WP_CONNECTOR = 'wp_connector';
	public const PROVIDER_GEMINI       = 'gemini';
	public const PROVIDER_OPENAI       = 'openai';
	public const PROVIDER_OPENROUTER   = 'openrouter';
	public const PROVIDER_ANTHROPIC    = 'anthropic';
	public const PROVIDER_OLLAMA       = 'ollama';
	public const PROVIDER_CUSTOM       = 'custom';

	/** Seconds reserved for the secondary provider when the primary cascade runs out of time. */
	private const FALLBACK_RESERVE_SECONDS = 8.0;

	/** How long a model that returned 404 stays demoted (it will not reappear within minutes). */
	private const MODEL_MISSING_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Absolute request deadline (microtime) shared by all AI calls in this request.
	 *
	 * @var float|null
	 */
	private static ?float $deadline = null;

	/**
	 * Set a request-wide deadline so multi-turn tool loops and provider cascades
	 * never cross the webserver gateway ceiling (Apache mod_fcgid 40s).
	 *
	 * @param float $seconds Seconds from now; <= 0 clears the deadline.
	 */
	public static function set_deadline( float $seconds ): void {
		self::$deadline = $seconds > 0 ? \microtime( true ) + $seconds : null;
	}

	/**
	 * Seconds left before the request deadline (PHP_FLOAT_MAX when none is set).
	 */
	public static function remaining_time(): float {
		return null === self::$deadline ? \PHP_FLOAT_MAX : self::$deadline - \microtime( true );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/** Transient caching the (expensive) native connector availability probe. */
	private const NATIVE_AVAILABILITY_TRANSIENT = 'mhbo_ai_native_available';

	/** Cache lifetimes for the availability probe (seconds). */
	private const NATIVE_AVAILABLE_TTL   = 600;
	private const NATIVE_UNAVAILABLE_TTL = 120;

	/**
	 * Map MHBO provider slugs to WordPress AI Client provider IDs.
	 */
	private const NATIVE_PROVIDER_IDS = array(
		self::PROVIDER_GEMINI    => 'google',
		self::PROVIDER_OPENAI    => 'openai',
		self::PROVIDER_ANTHROPIC => 'anthropic',
	);

	/**
	 * Whether the WP 7.0+ native AI Client is available AND has at least
	 * one provider configured that supports text generation.
	 *
	 * NOT free: the core check lists provider models over HTTP and was measured
	 * at 5–10s when a provider is unreachable. The result is therefore cached.
	 * Respects the wp_supports_ai() kill switch.
	 *
	 * @since 2.4.0
	 * @since 2.6.2 Cached; `$allow_live_check = false` never performs network I/O.
	 *
	 * @param bool $allow_live_check Whether a live probe may run on cache miss.
	 * @return bool
	 */
	public static function is_native_available( bool $allow_live_check = true ): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return false;
		}

		$cached = get_transient( self::NATIVE_AVAILABILITY_TRANSIENT );
		if ( 'yes' === $cached || 'no' === $cached ) {
			return 'yes' === $cached;
		}
		if ( ! $allow_live_check ) {
			return false;
		}

		Loader::ensure_vendor_autoload();

		try {
			$available = (bool) \wp_ai_client_prompt( 'test' )->is_supported_for_text_generation();
		} catch ( Throwable ) {
			$available = false;
		}
		self::remember_native_availability( $available );

		return $available;
	}

	/**
	 * Persist the native availability result.
	 *
	 * @param bool $available Probe result.
	 */
	private static function remember_native_availability( bool $available ): void {
		set_transient(
			self::NATIVE_AVAILABILITY_TRANSIENT,
			$available ? 'yes' : 'no',
			$available ? self::NATIVE_AVAILABLE_TTL : self::NATIVE_UNAVAILABLE_TTL
		);
	}

	/**
	 * Flush the cached native availability (e.g. after AI or Connector settings change).
	 */
	public static function flush_native_availability(): void {
		delete_transient( self::NATIVE_AVAILABILITY_TRANSIENT );
	}

	/**
	 * Flush availability cache when an AI-relevant option changes.
	 *
	 * Hooked to `updated_option` / `added_option`.
	 *
	 * @param string $option Option name.
	 */
	public static function maybe_flush_on_option_change( string $option ): void {
		if ( str_starts_with( $option, 'connectors_ai_' ) || in_array( $option, array( 'mhbo_ai_provider', 'mhbo_ai_model', 'mhbo_ai_api_key' ), true ) ) {
			self::flush_native_availability();
		}
	}

	/**
	 * Return native WordPress AI Client provider IDs that have credentials configured locally.
	 *
	 * Zero network I/O: inspects the local ProviderRegistry configuration state only.
	 *
	 * @return list<string>
	 */
	public static function get_configured_native_provider_ids(): array {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return array();
		}
		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return array();
		}

		Loader::ensure_vendor_autoload();

		$configured    = array();
		$candidate_ids = array_values( self::NATIVE_PROVIDER_IDS );
		$registry      = null;

		if ( class_exists( '\WordPress\AiClient\AiClient' ) ) {
			try {
				$registry       = \WordPress\AiClient\AiClient::defaultRegistry();
				$registered_ids = $registry->getRegisteredProviderIds();
				if ( array() !== $registered_ids ) {
					$candidate_ids = $registered_ids;
				}
			} catch ( Throwable ) {
				$registry = null;
			}
		}

		// Inspect local auth bindings, WP Connectors DB options, and env/constant keys (zero HTTP I/O).
		foreach ( $candidate_ids as $pid ) {
			$pid = (string) $pid;
			if ( '' === $pid ) {
				continue;
			}

			if ( null !== $registry ) {
				try {
					if ( null !== $registry->getProviderRequestAuthentication( $pid ) ) {
						$configured[] = $pid;
						continue;
					}
				} catch ( Throwable ) {
					// Continue inspecting option/env credential stores below.
				}
			}

			$sanitized_id = str_replace( '-', '_', $pid );
			if ( '' !== trim( (string) get_option( 'connectors_ai_' . $sanitized_id . '_api_key', '' ) ) ) {
				$configured[] = $pid;
				continue;
			}

			$const_key = strtoupper( (string) preg_replace( '/([a-z])([A-Z])/', '$1_$2', $sanitized_id ) ) . '_API_KEY';
			$env_val   = getenv( $const_key );
			if ( ( is_string( $env_val ) && '' !== trim( $env_val ) ) || ( defined( $const_key ) && '' !== trim( (string) constant( $const_key ) ) ) ) {
				$configured[] = $pid;
			}
		}

		return array_values( array_unique( $configured ) );
	}

	/**
	 * Whether a specific MHBO provider has a configured native WordPress Connector.
	 *
	 * @param string $mhbo_provider MHBO provider constant.
	 * @return bool
	 */
	public static function is_native_provider_configured( string $mhbo_provider ): bool {
		if ( self::PROVIDER_WP_CONNECTOR === $mhbo_provider ) {
			return array() !== self::get_configured_native_provider_ids() || self::is_native_available( false );
		}
		if ( ! isset( self::NATIVE_PROVIDER_IDS[ $mhbo_provider ] ) ) {
			return false;
		}
		return in_array( self::NATIVE_PROVIDER_IDS[ $mhbo_provider ], self::get_configured_native_provider_ids(), true );
	}

	/**
	 * Infer a native WP Connector provider ID from a model slug when user selected wp_connector.
	 *
	 * @param string $model Model ID.
	 * @return string|null Native provider ID ('google'|'openai'|'anthropic') or null if unknown.
	 */
	private static function infer_native_provider_id( string $model ): ?string {
		$lower = strtolower( $model );
		if ( str_starts_with( $lower, 'gemini' ) || str_starts_with( $lower, 'gemma' ) ) {
			return 'google';
		}
		if ( str_starts_with( $lower, 'claude' ) ) {
			return 'anthropic';
		}
		if ( str_starts_with( $lower, 'gpt' ) || str_starts_with( $lower, 'o1' ) || str_starts_with( $lower, 'o3' ) || str_starts_with( $lower, 'o4' ) ) {
			return 'openai';
		}
		return null;
	}

	/**
	 * Normalize retired/phantom model identifiers to active 2026 replacements.
	 *
	 * @param string $model Model ID from settings.
	 * @return string
	 */
	private static function migrate_legacy_model_slug( string $model ): string {
		return match ( $model ) {
			'gemini-3.8-pro', 'gemini-stable-high' => 'gemini-4-carbon',
			'gemini-pro-latest'                    => 'gemini-4-argon',
			'gemini-2.5-flash', 'gemini-2.5-pro'   => 'gemini-3.8-flash',
			'gemini-2.5-flash-lite'                => 'gemini-3.5-flash-lite',
			'claude-fable-5-1'                     => 'claude-fable-5-2',
			'claude-3-7-sonnet', 'claude-sonnet-4' => 'claude-sonnet-5-5',
			'gpt-4o', 'gpt-4o-mini', 'gpt-5.4-mini'=> 'gpt-6-luna',
			'gpt-5.4'                              => 'gpt-6.1-terra',
			default                                => $model,
		};
	}

	/**
	 * Build ordered model preferences for the WP AI Client from plugin settings.
	 *
	 * Uses `[provider_id, model_id]` tuples first so identical model IDs across connectors
	 * cannot collide, followed by unscoped model IDs and recommended frontier fallbacks
	 * when `'auto'` or unset.
	 *
	 * @param string|false $provider_scope Optional MHBO provider scope override.
	 * @return array<int, array{0:string,1:string}|string>
	 */
	private static function get_native_model_preferences( string|false $provider_scope = false ): array {
		$provider = false !== $provider_scope ? $provider_scope : self::get_configured_provider();
		$model    = trim( (string) get_option( self::OPT_PREFIX . 'model', '' ) );

		if ( 'custom' === $model || 'auto' === $model ) {
			$model = '';
		}
		if ( '' !== $model ) {
			$model = self::migrate_legacy_model_slug( $model );
		}

		$preferences = array();
		if ( '' !== $model ) {
			if ( is_string( $provider ) && isset( self::NATIVE_PROVIDER_IDS[ $provider ] ) ) {
				$preferences[] = array( self::NATIVE_PROVIDER_IDS[ $provider ], $model );
			} else {
				$inferred = self::infer_native_provider_id( $model );
				if ( null !== $inferred ) {
					$preferences[] = array( $inferred, $model );
				}
			}
			$preferences[] = $model;
		} else {
			// Prefer current GA frontier workhorses over alphabetical/legacy connector defaults.
			$preferences = array(
				array( 'google', 'gemini-3.8-flash' ),
				array( 'openai', 'gpt-6.1-sol' ),
				array( 'anthropic', 'claude-sonnet-5-5' ),
				array( 'google', 'gemini-4-hydrogen' ),
				array( 'openai', 'gpt-6-astra' ),
				array( 'anthropic', 'claude-fable-5-2' ),
			);
		}

		return $preferences;
	}

	/**
	 * Test the native WordPress AI Client / Connectors configuration.
	 *
	 * @return array{success:bool,message:string,provider?:string,model?:string,latency_ms?:int,response?:string}
	 */
	public static function test_native_connector(): array {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return array(
				'success' => false,
				'message' => __( 'WordPress AI Client (wp_ai_client_prompt) is not available. WordPress 7.0+ or the WordPress/ai plugin is required.', 'modern-hotel-booking' ),
			);
		}

		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return array(
				'success' => false,
				'message' => __( 'WordPress AI features are currently disabled by the wp_supports_ai filter or WP_AI_SUPPORT constant.', 'modern-hotel-booking' ),
			);
		}

		$start        = microtime( true );
		$diag_timeout = 10;
		$short_http   = static function ( array $args ) use ( &$diag_timeout ): array {
			$args['timeout'] = $diag_timeout;
			return $args;
		};
		$short_ai     = static function () use ( &$diag_timeout ): float {
			return (float) $diag_timeout;
		};

		add_filter( 'http_request_args', $short_http, 9999 );
		add_filter( 'wp_ai_client_default_request_timeout', $short_ai, 9999 );

		try {
			// Use 256 tokens without forcing temperature (reasoning models like GPT-6/Gemini-4 reject custom temperatures).
			$builder = \wp_ai_client_prompt( 'Reply with only the word: CONNECTOR_OK' )
				->using_max_tokens( 256 );

			$configured_provider = self::get_configured_provider();
			if ( is_string( $configured_provider ) && isset( self::NATIVE_PROVIDER_IDS[ $configured_provider ] ) ) {
				$builder->using_provider( self::NATIVE_PROVIDER_IDS[ $configured_provider ] );
			}

			// Diagnostics always probe live and refresh the cache.
			if ( ! $builder->is_supported_for_text_generation() ) {
				self::remember_native_availability( false );
				return array(
					'success'    => false,
					'message'    => __( 'No WordPress AI Connector provider is available for text generation. Either no provider is connected (Settings -> Connectors), or the provider could not be reached from this server (slow or blocked outbound HTTPS).', 'modern-hotel-booking' ),
					'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
				);
			}

			$preferences = self::get_native_model_preferences();
			if ( array() !== $preferences ) {
				$builder->using_model_preference( ...$preferences );
			}

			$result  = $builder->generate_text_result();
			$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $result ) ) {
				$err_msg  = $result->get_error_message();
				$err_code = (string) $result->get_error_code();

				// Check for WordPress AI Connector Approval block (WordPress AI plugin 1.4.0+).
				if ( 'wpai_connector_not_approved' === $err_code || str_contains( strtolower( $err_msg ), 'not been approved for use' ) ) {
					$approval_url = admin_url( 'tools.php?page=ai-connector-approval' );
					return array(
						'success'    => false,
						'message'    => sprintf(
							/* translators: %s: URL to AI Connector Approvals screen */
							__( 'The WordPress AI Connector has not been approved for Modern Hotel Booking yet. Please visit <a href="%s" target="_blank" rel="noopener">Tools -> AI Connector Approvals</a> to allow access.', 'modern-hotel-booking' ),
							esc_url( $approval_url )
						),
						'latency_ms' => $latency,
					);
				}

				// Check for transient Google / provider spikes (503 high demand, 429 rate limit, cURL timeout).
				$is_transient = self::is_transient_gemini_error( $err_msg )
					|| str_contains( $err_msg, '503' )
					|| str_contains( $err_msg, '429' )
					|| str_contains( $err_msg, 'high demand' )
					|| str_contains( $err_msg, 'timed out' );

				$first_pref = $preferences[0] ?? null;
				if ( $is_transient && null !== $first_pref ) {
					$pref_model     = is_array( $first_pref ) ? (string) ( $first_pref[1] ?? '' ) : (string) $first_pref;
					$fallback_model = in_array( $pref_model, array( 'gemini-3.8-flash', 'gemini-4-hydrogen', 'gemini-4-carbon', 'gemini-4-argon' ), true )
						? ( is_array( $first_pref ) ? array( $first_pref[0], 'gemini-3.5-flash-lite' ) : 'gemini-3.5-flash-lite' )
						: null;

					if ( null !== $fallback_model ) {
						try {
							$diag_timeout = 8;
							$fb_builder   = \wp_ai_client_prompt( 'Reply with only the word: CONNECTOR_OK' )
								->using_max_tokens( 128 )
								->using_model_preference( $fallback_model );
							$fb_result    = $fb_builder->generate_text_result();

							if ( ! is_wp_error( $fb_result ) ) {
								self::remember_native_availability( true );
								$fb_latency = (int) round( ( microtime( true ) - $start ) * 1000 );
								$fb_parsed  = self::parse_generative_ai_result( $fb_result );
								return array(
									'success'    => true,
									'message'    => sprintf(
										/* translators: 1: primary model name, 2: fallback model name */
										__( 'WordPress AI Connector is active and connected! Note: Primary model "%1$s" is temporarily experiencing high demand on Google (503/timeout), but verified operational via "%2$s". Live chats automatically cascade across models to ensure 100%% uptime.', 'modern-hotel-booking' ),
										$pref_model,
										is_array( $fallback_model ) ? $fallback_model[1] : (string) $fallback_model
									),
									'latency_ms' => $fb_latency,
									'response'   => trim( $fb_parsed['content'] ),
									'provider'   => (string) $fb_result->getProviderMetadata()->getId(),
									'model'      => (string) $fb_result->getModelMetadata()->getId(),
								);
							}
						} catch ( Throwable ) {
							// Continue to default error return if fallback probe fails.
						}
					}
				}

				return array(
					'success'    => false,
					/* translators: %s: error message returned by the WordPress AI Client. */
					'message'    => sprintf( __( 'WordPress AI Connector error: %s', 'modern-hotel-booking' ), $result->get_error_message() ),
					'latency_ms' => $latency,
				);
			}

			self::remember_native_availability( true );

			$parsed_diag = self::parse_generative_ai_result( $result );
			return array(
				'success'    => true,
				'message'    => __( 'WordPress AI Connector is active and operational!', 'modern-hotel-booking' ),
				'latency_ms' => $latency,
				'response'   => trim( $parsed_diag['content'] ),
				'provider'   => (string) $result->getProviderMetadata()->getId(),
				'model'      => (string) $result->getModelMetadata()->getId(),
			);
		} catch ( Throwable $e ) {
			$latency = (int) round( ( microtime( true ) - $start ) * 1000 );
			return array(
				'success'    => false,
				/* translators: %s: exception message. */
				'message'    => sprintf( __( 'Connector exception: %s', 'modern-hotel-booking' ), $e->getMessage() ),
				'latency_ms' => $latency,
			);
		} finally {
			remove_filter( 'http_request_args', $short_http, 9999 );
			remove_filter( 'wp_ai_client_default_request_timeout', $short_ai, 9999 );
		}
	}

	/**
	 * Send a prompt to the configured AI provider.
	 *
	 * @param array<int, array<string, mixed>> $messages       Conversation history.
	 * @param string                            $system_prompt  System/context prompt.
	 * @param array<mixed>                      $tools          Tool definitions (for agentic flow).
	 *
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null,fallback_model?:string,original_model?:string,model?:string,thought?:string,signature?:string}
	 */
	public static function prompt( array $messages, string $system_prompt, array $tools = array() ): array {
		$start    = microtime( true );
		$was_open = self::is_circuit_broken();

		$result = self::do_prompt( $messages, $system_prompt, $tools );

		// Circuit breaker accounting happens once per turn, AFTER every tier
		// (primary cascade, WP connector, secondary provider) has had its chance.
		// Previously the primary cascade tripped the breaker even when the fallback
		// then succeeded, which blocked the very next tool-loop turn for 15s.
		if ( ! $was_open ) {
			$error = (string) ( $result['error'] ?? '' );
			if ( '' === $error ) {
				self::reset_failure_score();
			} elseif ( self::is_transport_error( $error ) || self::is_transient_gemini_error( $error ) ) {
				self::increment_failure_score();
			}
		}

		$latency = round( ( microtime( true ) - $start ) * 1000 );
		if ( ! headers_sent() ) {
			header( sprintf( 'Server-Timing: ai_latency;desc="AI Provider Latency";dur=%d', (int) $latency ), false );
		}

		return $result;
	}

	/**
	 * Internal prompt execution logic.
	 *
	 * @param array<int, array<string, mixed>> $messages       Conversation history.
	 * @param string                            $system_prompt  System/context prompt.
	 * @param array<mixed>                      $tools          Tool definitions (for agentic flow).
	 *
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null,fallback_model?:string,original_model?:string,model?:string,thought?:string,signature?:string}
	 */
	private static function do_prompt( array $messages, string $system_prompt, array $tools = array() ): array {
		$empty = array(
			'content'       => '',
			'tool_calls'    => array(),
			'finish_reason' => '',
			'error'         => null,
		);

		// Global Quota Protection (2026.4 BP)
		if ( self::is_circuit_broken() ) {
			$lock      = (int) get_transient( 'mhbo_ai_quota_lock' );
			$remaining = max( 1, $lock - time() );
			return array_merge(
				$empty,
				array(
					'error' => sprintf(
						// translators: %1$d: seconds remaining before the AI concierge becomes available again
						\MHBO\Core\I18n::get_label( 'ai_error_cooling_down_seconds' ),
						(int) $remaining
					),
				) 
			);
		}

		$configured_provider = self::get_configured_provider();
		$direct_api_key      = self::get_fallback_api_key();
		$has_direct_key      = ( '' !== $direct_api_key );

		// Decide whether to run Path 1 (WordPress Core AI Client / Connectors).
		// Only intercept non-connector providers if they have no direct key AND map to a
		// configured native Connector (never hijack Ollama, OpenRouter, or Custom endpoints).
		$supports_native_fallback = false === $configured_provider
			|| ( ! $has_direct_key && self::is_native_provider_configured( $configured_provider ) );

		$try_native   = function_exists( 'wp_ai_client_prompt' ) && (
			self::PROVIDER_WP_CONNECTOR === $configured_provider
			|| $supports_native_fallback
		) && self::is_native_available();
		$native_error = null;

		// ── Path 1: WP 7.0+ native AI Client (full tools, history & result DTO) ──
		if ( $try_native ) {
			$native_res = self::execute_native_prompt( $messages, $system_prompt, $tools, $configured_provider );
			if ( null === ( $native_res['error'] ?? null ) ) {
				return $native_res;
			}
			$native_error = (string) $native_res['error'];
		}

		// ── Path 2: Direct HTTP call ──────────────────────────────────────────
		$result = self::http_prompt( $messages, $system_prompt, $tools );
		if ( self::PROVIDER_WP_CONNECTOR === $configured_provider && null !== $native_error && null !== ( $result['error'] ?? null ) ) {
			$result['error'] = $native_error;
		}

		// ── Fallback Path A: If direct HTTP failed and Native was not tried, try Native ──
		if ( null !== ( $result['error'] ?? null ) && ! $try_native && self::PROVIDER_WP_CONNECTOR !== $configured_provider && function_exists( 'wp_ai_client_prompt' ) && self::is_native_available() ) {
			self::log_error( 'Direct AI provider failed. Attempting fallback via WordPress AI Connector...' );
			$native_res = self::execute_native_prompt( $messages, $system_prompt, $tools, self::PROVIDER_WP_CONNECTOR );
			if ( null === ( $native_res['error'] ?? null ) ) {
				$native_res['fallback_model'] = (string) ( $native_res['model'] ?? 'WordPress AI Connector' );
				$native_res['original_model'] = (string) ( self::get_configured_provider() ?: 'primary' );
				return $native_res;
			}
		}

		// ── Fallback Path B: Secondary Provider (Pro / Resilience) ────────────

return $result;
	}

	/**
	 * Return the configured AI provider slug.
	 *
	 * @return string|false
	 */
	public static function get_configured_provider(): string|false {
		$provider = (string) get_option( self::OPT_PREFIX . 'provider', '' );
		$allowed  = array( self::PROVIDER_WP_CONNECTOR, self::PROVIDER_GEMINI, self::PROVIDER_OPENAI, self::PROVIDER_OPENROUTER, self::PROVIDER_ANTHROPIC, self::PROVIDER_OLLAMA, self::PROVIDER_CUSTOM );
		return in_array( $provider, $allowed, true ) ? $provider : false;
	}

	/**
	 * Read the API key from plugin settings (never exposed to the frontend).
	 *
	 * @return string
	 */
	public static function get_fallback_api_key(): string {
		return (string) get_option( self::OPT_PREFIX . 'api_key', '' );
	}

	/**
	 * Return the fallback AI provider slug.
	 *
	 * @return string|false
	 */
	public static function get_configured_fallback_provider(): string|false {
		$provider = (string) get_option( self::OPT_PREFIX . 'fallback_provider', '' );
		$allowed  = array( self::PROVIDER_GEMINI, self::PROVIDER_OPENAI, self::PROVIDER_OPENROUTER, self::PROVIDER_ANTHROPIC, self::PROVIDER_OLLAMA, self::PROVIDER_CUSTOM );
		return in_array( $provider, $allowed, true ) ? $provider : false;
	}

	/**
	 * Read the fallback API key from plugin settings.
	 *
	 * @return string
	 */
	public static function get_fallback_configured_api_key(): string {
		return (string) get_option( self::OPT_PREFIX . 'fallback_api_key', '' );
	}

	/**
	 * Secondary fallback prompt — used when the primary connection fails.
	 *
	 * @param array<mixed> $messages
	 * @param string       $system_prompt
	 * @param array<mixed> $tools
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null,fallback_model?:string,original_model?:string,model?:string,thought?:string,signature?:string}
	 */
	public static function fallback_prompt( array $messages, string $system_prompt, array $tools = array() ): array {
		$result = array(
			'content'       => '',
			'tool_calls'    => array(),
			'finish_reason' => 'error',
			'error'         => 'Fallback AI provider is an enterprise Pro resilience feature.',
		);

return $result;
	}

	/**
	 * Resolves the provider API endpoint dynamically with filterable overrides.
	 *
	 * Adheres to modern WordPress 2026 AI architecture: native wp_ai_client_prompt()
	 * remains the preferred primary path, while this dynamic resolver provides standard
	 * fallback endpoints for direct API keys and proxy routing.
	 *
	 * @since 2.6.0
	 *
	 * @param string $provider Provider identifier.
	 * @param string $model    Model name.
	 * @return string Filtered endpoint URL.
	 */
	public static function get_provider_endpoint( string $provider, string $model = '' ): string {
		$scheme = 'https://';
		$hosts  = array(
			self::PROVIDER_OPENAI     => 'api.' . 'openai.com',
			self::PROVIDER_ANTHROPIC  => 'api.' . 'anthropic.com',
			self::PROVIDER_GEMINI     => 'generativelanguage.' . 'googleapis.com',
			self::PROVIDER_OPENROUTER => 'openrouter.' . 'ai',
		);

		$host = $hosts[ $provider ] ?? '';
		if ( '' === $host ) {
			return '';
		}

		$path = match ( $provider ) {
			self::PROVIDER_OPENAI     => 'responses' === ModelCatalog::get_openai_api_type( $model ) ? '/v1/responses' : '/v1/chat/completions',
			self::PROVIDER_ANTHROPIC  => '/v1/messages',
			self::PROVIDER_GEMINI     => sprintf( '/v1beta/models/%s:generateContent', $model ),
			self::PROVIDER_OPENROUTER => '/api/v1/chat/completions',
			default                   => '',
		};

		$endpoint = $scheme . $host . $path;

		/**
		 * Filters the HTTP fallback endpoint for the specified AI provider.
		 *
		 * @since 2.6.0
		 *
		 * @param string $endpoint The assembled endpoint URL.
		 * @param string $provider The AI provider key.
		 * @param string $model    The model name.
		 */
		return (string) apply_filters( 'mhbo_ai_provider_endpoint', $endpoint, $provider, $model );
	}

	/**
	 * Ensure the native WordPress AI Client has a sufficient timeout (25s)
	 * for frontier reasoning models that generate chain-of-thought tokens,
	 * while safely remaining within webserver proxy ceilings (30-40s).
	 *
	 * @since 2.6.2
	 * @param float|int $timeout Default timeout in seconds.
	 * @return float
	 */
	public static function filter_native_timeout( float|int $timeout ): float {
		return (float) min( max( (float) $timeout, 25.0 ), 30.0 );
	}

	/**
	 * Ensure AI API requests have adequate timeout for modern reasoning models,
	 * while safely bounding calls within webserver ceilings (30-40s).
	 *
	 * WordPress default HTTP timeout is 5-10 seconds, which frequently cuts off
	 * reasoning models (Gemini 3.8 Flash, GPT-5) during internal chain-of-thought.
	 *
	 * @param array<string, mixed> $args Request arguments.
	 * @param string               $url  Target URL.
	 * @return array<string, mixed>
	 */
	public static function filter_ai_http_request_args( array $args, string $url ): array {
		if ( str_contains( $url, 'googleapis.com' ) || str_contains( $url, 'openai.com' ) || str_contains( $url, 'anthropic.com' ) || str_contains( $url, 'openrouter.ai' ) ) {
			if ( ! isset( $args['timeout'] ) ) {
				$args['timeout'] = 20.0;
			} elseif ( (float) $args['timeout'] > 28.0 && 'cli' !== php_sapi_name() ) {
				// Prevent timeouts exceeding webserver gateway limits (Apache mod_fcgid 40s / FastCGI 30-45s).
				$args['timeout'] = 25.0;
			}
		}
		return $args;
	}

	// -------------------------------------------------------------------------
	// Private Helpers
	// -------------------------------------------------------------------------

/**
	 * Execute a prompt via WP 7.0+ native wp_ai_client_prompt() with full tool declarations,
	 * structured Message DTO history (including FunctionCall/FunctionResponse & thought signatures),
	 * ModelConfig options, and GenerativeAiResult extraction.
	 *
	 * @param array<int, array<string, mixed>> $messages       OpenAI-style conversation messages.
	 * @param string                            $system_prompt  System instruction.
	 * @param array<int, array<string, mixed>>  $tools          OpenAI-style tool schemas.
	 * @param string|false                      $provider_scope Optional MHBO provider slug scope.
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null,model?:string,thought?:string,thought_signature?:string}
	 */
	private static function execute_native_prompt( array $messages, string $system_prompt, array $tools = array(), string|false $provider_scope = false ): array {
		$empty = array(
			'content'       => '',
			'tool_calls'    => array(),
			'finish_reason' => 'error',
			'error'         => null,
		);

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return array_merge( $empty, array( 'error' => 'WordPress AI Client is unavailable.' ) );
		}

		try {
			Loader::ensure_vendor_autoload();

			$effort     = (string) get_option( 'mhbo_ai_reasoning_effort', 'none' );
			$max_tokens = match ( $effort ) {
				'high'   => 8192,
				'medium' => 4096,
				default  => 2048,
			};

			$configured_ids = self::get_configured_native_provider_ids();
			$target_pid     = null;
			if ( is_string( $provider_scope ) && isset( self::NATIVE_PROVIDER_IDS[ $provider_scope ] ) ) {
				$target_pid = self::NATIVE_PROVIDER_IDS[ $provider_scope ];
			}

			$preferences = self::get_native_model_preferences( $provider_scope );
			if ( null === $target_pid && isset( $preferences[0] ) && is_array( $preferences[0] ) && isset( $preferences[0][0] ) ) {
				$first_pid = (string) $preferences[0][0];
				if ( in_array( $first_pid, $configured_ids, true ) ) {
					$target_pid = $first_pid;
				}
			}
			if ( null === $target_pid && 1 === count( $configured_ids ) ) {
				$target_pid = $configured_ids[0];
			}

			$native_history = self::build_native_messages( $messages );
			$builder        = array() !== $native_history
				? \wp_ai_client_prompt( null )->with_history( ...$native_history )
				: \wp_ai_client_prompt( self::get_fallback_user_text( $messages ) );

			// Apply provider-specific custom ModelConfig BEFORE builder setters so builder fields take precedence.
			if ( 'google' === $target_pid && in_array( $effort, array( 'low', 'medium', 'high' ), true ) && class_exists( '\WordPress\AiClient\Providers\Models\DTO\ModelConfig' ) ) {
				$model_config = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig();
				$model_config->setCustomOptions(
					array(
						'generationConfig.thinkingConfig' => array(
							'thinkingLevel' => $effort,
						),
					)
				);
				$builder->using_model_config( $model_config );
			}

			$builder->using_system_instruction( $system_prompt )
				->using_max_tokens( $max_tokens );

			if ( null !== $target_pid && is_string( $provider_scope ) && isset( self::NATIVE_PROVIDER_IDS[ $provider_scope ] ) ) {
				$builder->using_provider( $target_pid );
			}

			if ( array() !== $preferences ) {
				$builder->using_model_preference( ...$preferences );
			}

			// Attach function calling declarations when tools are provided.
			if ( array() !== $tools && class_exists( '\WordPress\AiClient\Tools\DTO\FunctionDeclaration' ) ) {
				$declarations = array();
				foreach ( $tools as $tool ) {
					$fn     = ( isset( $tool['function'] ) && is_array( $tool['function'] ) ) ? $tool['function'] : $tool;
					$t_name = (string) ( $fn['name'] ?? '' );
					if ( '' === $t_name ) {
						continue;
					}
					$t_desc   = (string) ( $fn['description'] ?? '' );
					$t_params = ( isset( $fn['parameters'] ) && is_array( $fn['parameters'] ) ) ? $fn['parameters'] : null;
					if ( is_array( $t_params ) && isset( $t_params['properties'] ) && array() === $t_params['properties'] ) {
						$t_params['properties'] = new \stdClass();
					}
					$declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration( $t_name, $t_desc, $t_params );
				}
				if ( array() !== $declarations ) {
					$builder->using_function_declarations( ...$declarations );
				}
			}

			$result = $builder->generate_text_result();

			if ( is_wp_error( $result ) ) {
				$code         = (string) $result->get_error_code();
				$native_error = $result->get_error_message();
				if ( in_array( $code, array( 'prompt_invalid_argument', 'prompt_network_error', 'prompt_prevented' ), true ) ) {
					self::remember_native_availability( false );
				}
				self::log_error( 'wp_ai_client_prompt error [' . $code . ']: ' . $native_error );
				return array_merge( $empty, array( 'error' => $native_error ) );
			}

			return self::parse_generative_ai_result( $result );
		} catch ( Throwable $e ) {
			self::log_error( 'wp_ai_client_prompt exception: ' . $e->getMessage() );
			return array_merge( $empty, array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Append a role turn to normalized intermediate turn list, merging consecutive same-role turns.
	 *
	 * @param array<int, array{role: 'user'|'model', parts: non-empty-list<\WordPress\AiClient\Messages\DTO\MessagePart>}> $turns
	 * @param 'user'|'model'                                                                                             $role
	 * @param list<\WordPress\AiClient\Messages\DTO\MessagePart>                                                         $parts
	 * @return void
	 */
	private static function append_native_turn( array &$turns, string $role, array $parts ): void {
		if ( array() === $parts ) {
			return;
		}
		$last_idx = count( $turns ) - 1;
		if ( $last_idx >= 0 && $role === $turns[ $last_idx ]['role'] ) {
			$turns[ $last_idx ]['parts'] = array_merge( $turns[ $last_idx ]['parts'], $parts );
		} else {
			$turns[] = array(
				'role'  => $role,
				'parts' => $parts,
			);
		}
	}

	/**
	 * Convert OpenAI-style conversation history into normalized WordPress AI Client Message DTOs.
	 *
	 * Preserves multi-turn tool calls, tool responses, reasoning thoughts, and Gemini 3.8+/4
	 * `thoughtSignature` tokens while enforcing role alternation and call/response pairing.
	 *
	 * @param array<int, array<string, mixed>> $messages Conversation history.
	 * @return list<\WordPress\AiClient\Messages\DTO\Message>
	 */
	private static function build_native_messages( array $messages ): array {
		if ( ! class_exists( '\WordPress\AiClient\Messages\DTO\UserMessage' )
			|| ! class_exists( '\WordPress\AiClient\Messages\DTO\ModelMessage' )
			|| ! class_exists( '\WordPress\AiClient\Messages\DTO\MessagePart' )
			|| ! class_exists( '\WordPress\AiClient\Messages\Enums\MessagePartChannelEnum' )
		) {
			return array();
		}

		// Build a tool_call_id -> function_name lookup table for older tool turns.
		$tc_name_map = array();
		foreach ( $messages as $msg ) {
			if ( 'assistant' === ( $msg['role'] ?? '' ) && ! empty( $msg['tool_calls'] ) && is_array( $msg['tool_calls'] ) ) {
				foreach ( $msg['tool_calls'] as $tc ) {
					$tc_id   = (string) ( $tc['id'] ?? '' );
					$tc_name = (string) ( $tc['function']['name'] ?? $tc['name'] ?? '' );
					if ( '' !== $tc_id && '' !== $tc_name ) {
						$tc_name_map[ $tc_id ] = $tc_name;
					}
				}
			}
		}

		/** @var array<int, array{role: 'user'|'model', parts: non-empty-list<\WordPress\AiClient\Messages\DTO\MessagePart>}> $turns */
		$turns                = array();
		/** @var list<\WordPress\AiClient\Messages\DTO\MessagePart> $pending_fn_responses */
		$pending_fn_responses = array();

		foreach ( $messages as $msg ) {
			$role = (string) ( $msg['role'] ?? 'user' );

			if ( 'tool' === $role ) {
				if ( class_exists( '\WordPress\AiClient\Tools\DTO\FunctionResponse' ) ) {
					$tc_id   = (string) ( $msg['tool_call_id'] ?? '' );
					$tc_name = (string) ( $msg['name'] ?? '' );
					if ( '' === $tc_name && '' !== $tc_id && isset( $tc_name_map[ $tc_id ] ) ) {
						$tc_name = $tc_name_map[ $tc_id ];
					}
					if ( '' === $tc_name ) {
						$tc_name = 'unknown_tool_call';
					}
					$raw_content            = $msg['content'] ?? '';
					$response_data          = self::to_gemini_function_response( $raw_content );
					$fn_resp                = new \WordPress\AiClient\Tools\DTO\FunctionResponse(
						'' !== $tc_id ? $tc_id : null,
						$tc_name,
						$response_data
					);
					$pending_fn_responses[] = new \WordPress\AiClient\Messages\DTO\MessagePart( $fn_resp );
				}
				continue;
			}

			if ( array() !== $pending_fn_responses ) {
				self::append_native_turn( $turns, 'user', $pending_fn_responses );
				$pending_fn_responses = array();
			}

			if ( 'assistant' === $role || 'model' === $role ) {
				$parts = array();
				if ( '' !== (string) ( $msg['thought'] ?? '' ) ) {
					$parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart(
						(string) $msg['thought'],
						\WordPress\AiClient\Messages\Enums\MessagePartChannelEnum::thought()
					);
				}
				if ( '' !== (string) ( $msg['content'] ?? '' ) ) {
					$sig     = (string) ( $msg['thought_signature'] ?? $msg['thoughtSignature'] ?? '' );
					$has_tcs = ! empty( $msg['tool_calls'] );
					$parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart(
						(string) $msg['content'],
						\WordPress\AiClient\Messages\Enums\MessagePartChannelEnum::content(),
						( '' !== $sig && ! $has_tcs ) ? $sig : null
					);
				}
				if ( ! empty( $msg['tool_calls'] ) && is_array( $msg['tool_calls'] ) && class_exists( '\WordPress\AiClient\Tools\DTO\FunctionCall' ) ) {
					foreach ( $msg['tool_calls'] as $tc ) {
						$tc_name = (string) ( $tc['function']['name'] ?? $tc['name'] ?? '' );
						if ( '' === $tc_name ) {
							continue;
						}
						$tc_id    = (string) ( $tc['id'] ?? 'call_' . wp_generate_password( 8, false ) );
						$raw_args = $tc['function']['arguments'] ?? $tc['arguments'] ?? '{}';
						$decoded  = is_string( $raw_args ) ? json_decode( $raw_args, true ) : ( is_array( $raw_args ) ? $raw_args : array() );
						$tc_sig   = (string) ( $tc['thought_signature'] ?? $tc['thoughtSignature'] ?? '' );
						$fn_call  = new \WordPress\AiClient\Tools\DTO\FunctionCall(
							$tc_id,
							$tc_name,
							is_array( $decoded ) ? $decoded : array()
						);
						$parts[]  = new \WordPress\AiClient\Messages\DTO\MessagePart(
							$fn_call,
							\WordPress\AiClient\Messages\Enums\MessagePartChannelEnum::content(),
							'' !== $tc_sig ? $tc_sig : null
						);
					}
				}
				self::append_native_turn( $turns, 'model', $parts );
				continue;
			}

			$text = trim( (string) ( $msg['content'] ?? '' ) );
			if ( '' !== $text ) {
				self::append_native_turn(
					$turns,
					'user',
					array( new \WordPress\AiClient\Messages\DTO\MessagePart( $text ) )
				);
			}
		}

		if ( array() !== $pending_fn_responses ) {
			self::append_native_turn( $turns, 'user', $pending_fn_responses );
		}

		// Conversations must start with a user turn.
		while ( array() !== $turns && 'user' !== $turns[0]['role'] ) {
			array_shift( $turns );
		}

		/** @var list<\WordPress\AiClient\Messages\DTO\Message> $dto_messages */
		$dto_messages = array();
		foreach ( $turns as $turn ) {
			$dto_messages[] = 'model' === $turn['role']
				? new \WordPress\AiClient\Messages\DTO\ModelMessage( $turn['parts'] )
				: new \WordPress\AiClient\Messages\DTO\UserMessage( $turn['parts'] );
		}

		return $dto_messages;
	}

	/**
	 * Extract normalized response structure from a WordPress AI Client GenerativeAiResult DTO.
	 *
	 * @param \WordPress\AiClient\Results\DTO\GenerativeAiResult $result SDK result object.
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null,model?:string,thought?:string,thought_signature?:string}
	 */
	private static function parse_generative_ai_result( \WordPress\AiClient\Results\DTO\GenerativeAiResult $result ): array {
		$candidates = $result->getCandidates();
		$first      = $candidates[0] ?? null;

		$text           = '';
		$thought        = '';
		$text_signature = '';
		$tool_calls     = array();
		$finish_reason  = 'stop';

		if ( null !== $first ) {
			$finish_reason = (string) $first->getFinishReason()->value;
			foreach ( $first->getMessage()->getParts() as $part ) {
				$type = $part->getType();
				if ( $type->isText() ) {
					$part_text = (string) ( $part->getText() ?? '' );
					if ( $part->getChannel()->isThought() ) {
						$thought .= $part_text;
					} else {
						$text .= $part_text;
						$sig   = (string) ( $part->getThoughtSignature() ?? '' );
						if ( '' !== $sig ) {
							$text_signature = $sig;
						}
					}
				} elseif ( $type->isFunctionCall() ) {
					$fc = $part->getFunctionCall();
					if ( null !== $fc && null !== $fc->getName() && '' !== $fc->getName() ) {
						$args     = $fc->getArgs();
						$tc_entry = array(
							'id'       => (string) ( $fc->getId() ?: 'call_' . wp_generate_password( 8, false ) ),
							'type'     => 'function',
							'function' => array(
								'name'      => (string) $fc->getName(),
								'arguments' => wp_json_encode( null !== $args ? $args : (object) array() ) ?: '{}',
							),
						);
						$tc_sig = (string) ( $part->getThoughtSignature() ?? '' );
						if ( '' !== $tc_sig ) {
							$tc_entry['thought_signature'] = $tc_sig;
						}
						$tool_calls[] = $tc_entry;
					}
				}
			}
		}

		$provider_id = (string) $result->getProviderMetadata()->getId();
		$model_id    = (string) $result->getModelMetadata()->getId();

		return array(
			'content'           => $text,
			'thought'           => $thought,
			'thought_signature' => $text_signature,
			'tool_calls'        => $tool_calls,
			'finish_reason'     => $finish_reason,
			'error'             => null,
			'model'             => '' !== $provider_id && '' !== $model_id ? "{$provider_id}:{$model_id}" : $model_id,
		);
	}

	/**
	 * Return the last non-empty user message text as fallback prompt string.
	 *
	 * @param array<int, array<string, mixed>> $messages Conversation history.
	 * @return string
	 */
	private static function get_fallback_user_text( array $messages ): string {
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			if ( 'user' === ( $messages[ $i ]['role'] ?? '' ) && '' !== trim( (string) ( $messages[ $i ]['content'] ?? '' ) ) ) {
				return (string) $messages[ $i ]['content'];
			}
		}
		return 'Hello';
	}

	/**
	 * Normalize an OpenAI-compatible base/endpoint URL (such as local Ollama hosts).
	 *
	 * Ensures empty values fall back safely and bare host/base URLs receive `/v1/chat/completions`.
	 *
	 * @param string $raw_url     Raw URL from settings.
	 * @param string $default_url Fallback default URL when empty.
	 * @return string
	 */
	private static function normalize_openai_endpoint( string $raw_url, string $default_url = '' ): string {
		$url = trim( $raw_url );
		if ( '' === $url ) {
			return $default_url;
		}
		$url = rtrim( $url, '/' );
		if ( str_ends_with( $url, '/chat/completions' ) || str_ends_with( $url, '/responses' ) ) {
			return $url;
		}
		if ( str_ends_with( $url, '/v1' ) ) {
			return $url . '/chat/completions';
		}
		return $url . '/v1/chat/completions';
	}

	/**
	 * Direct HTTP call handler — determines provider and routes to specific implementations.
	 *
	 * @param array<mixed> $messages
	 * @param string       $system_prompt
	 * @param array<mixed> $tools
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
	 */
	private static function http_prompt( array $messages, string $system_prompt, array $tools ): array {
		$provider = self::get_configured_provider();
		$api_key  = self::get_fallback_api_key();
		$model    = (string) get_option( self::OPT_PREFIX . 'model', '' );
 
		if ( self::PROVIDER_WP_CONNECTOR === $provider ) {
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => __( 'WordPress AI Connector is unavailable or misconfigured for direct fallback. Verify Settings -> Connectors or select a direct AI provider.', 'modern-hotel-booking' ),
			);
		}

		if ( ! $provider || ( ! $api_key && self::PROVIDER_OLLAMA !== $provider ) ) {
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => \MHBO\Core\I18n::get_label( 'ai_error_provider_not_configured' ),
			);
		}
 
		switch ( $provider ) {
			case self::PROVIDER_GEMINI:
				return self::http_gemini( $api_key, $model ?: ModelCatalog::get_recommended_model( self::PROVIDER_GEMINI ), $messages, $system_prompt, $tools );
 
			case self::PROVIDER_OPENAI:
				$resolved_model = $model ?: ModelCatalog::get_recommended_model( self::PROVIDER_OPENAI );
				$endpoint       = self::get_provider_endpoint( self::PROVIDER_OPENAI, $resolved_model );
				return self::http_openai( $api_key, $resolved_model, $messages, $system_prompt, $tools, $endpoint, self::PROVIDER_OPENAI );

			case self::PROVIDER_OPENROUTER:
				$resolved_model = $model ?: ModelCatalog::get_recommended_model( self::PROVIDER_OPENROUTER );
				return self::http_openai( $api_key, $resolved_model, $messages, $system_prompt, $tools, self::get_provider_endpoint( self::PROVIDER_OPENROUTER, $resolved_model ), self::PROVIDER_OPENROUTER );

			case self::PROVIDER_ANTHROPIC:
				return self::http_anthropic( $api_key, $model ?: ModelCatalog::get_recommended_model( self::PROVIDER_ANTHROPIC ), $messages, $system_prompt, $tools );
 
			case self::PROVIDER_OLLAMA:
				// Ollama is local-only by design; localhost is intentional.
				$base_url = self::normalize_openai_endpoint( (string) get_option( self::OPT_PREFIX . 'custom_url', '' ), 'http://localhost:11434/v1/chat/completions' );
				return self::http_openai( '', $model ?: ModelCatalog::get_recommended_model( self::PROVIDER_OLLAMA ), $messages, $system_prompt, $tools, $base_url, self::PROVIDER_OLLAMA );
 
			case self::PROVIDER_CUSTOM:
				$custom_url = (string) get_option( self::OPT_PREFIX . 'custom_url', '' );
				if ( '' === trim( $custom_url ) || ! wp_http_validate_url( $custom_url ) ) {
					return array(
						'content'       => '',
						'tool_calls'    => array(),
						'finish_reason' => 'error',
						'error'         => \MHBO\Core\I18n::get_label( 'ai_error_bad_custom_url' ),
					);
				}
				return self::http_openai( $api_key, $model, $messages, $system_prompt, $tools, $custom_url, self::PROVIDER_CUSTOM );
 
			default:
				return array(
					'content'       => '',
					'tool_calls'    => array(),
					'finish_reason' => 'error',
					// translators: %s: name of the AI provider
					'error'         => sprintf( \MHBO\Core\I18n::get_label( 'ai_error_unknown_provider' ), esc_html( $provider ) ),
				);
		}
	}

	/**
	 * HTTP call to Google Gemini API — with automatic model fallback on overload.
	 *
	 * Primary model is tried first; on transient capacity errors (503 / "high demand")
	 * we cascade through available active models in the catalog before giving up.
	 *
	 * API Version: v1beta is intentionally used (not v1 stable).
	 * Reason: v1beta supports system_instruction, tools/function_declarations,
	 * thinkingConfig (thinkingLevel), and all current Gemini 3.x / 4.x models.
	 *
	 * Verified Fallback Chain (November 2026):
	 *   gemini-3.8-flash → gemini-4-hydrogen → gemini-4-carbon → gemini-4-argon
	 *   → gemini-3.7-flash → gemini-3.5-flash-lite → gemini-3-flash-preview
	 *   → gemini-3.1-pro-preview
	 *
	 * EOL models removed: gemini-2.5-flash / gemini-2.5-flash-lite / gemini-2.5-pro (EOL Oct 2026),
	 *                      gemini-3.1-flash-lite-preview (shut down 2026-05-25),
	 *                      gemini-2.5-flash-lite-preview (shut down 2026-03-31),
	 *                      gemini-1.5-flash (shut down 2026-03-31).
	 *
	 * @param string       $api_key
	 * @param string       $model
	 * @param array<mixed> $messages
	 * @param string       $system_prompt
	 * @param array<mixed> $tools
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
	 */
	private static function http_gemini( string $api_key, string $model, array $messages, string $system_prompt, array $tools ): array {
		// Normalize retired or superseded model names to verified November 2026 equivalents.
		$model = str_replace( '-latest', '', $model ?: 'gemini-3.8-flash' );

		$retired_prefixes = array(
			'gemini-1.5',
			'gemini-2.0',
		);
		$retired_exact    = array(
			'gemini-2.5-flash',              // EOL: 2026-10-20
			'gemini-2.5-flash-lite',         // EOL: 2026-10-20
			'gemini-2.5-pro',                // EOL: 2026-10-20
			'gemini-3.1-flash-lite-preview', // EOL: 2026-05-25
			'gemini-2.5-flash-lite-preview', // EOL: 2026-03-31
			'gemini-1.5-flash',              // EOL: 2026-03-31
			'gemini-1.5-pro',                // EOL: 2026-03-31
			'gemini-3-pro-preview',          // EOL: 2026-03-09
		);

		if ( 'gemini-3.8-pro' === $model ) {
			self::log_error( 'Model alias gemini-3.8-pro is non-canonical. Auto-migrating to gemini-4-carbon.' );
			$model = 'gemini-4-carbon';
		} elseif ( in_array( $model, $retired_exact, true ) ) {
			self::log_error( "Model {$model} is EOL. Auto-migrating to gemini-3.8-flash." );
			$model = 'gemini-3.8-flash';
		} elseif ( array() !== array_filter( $retired_prefixes, fn( $p ) => str_starts_with( $model, $p ) ) ) {
			self::log_error( "Model {$model} uses a retired prefix. Auto-migrating to gemini-3.8-flash." );
			$model = 'gemini-3.8-flash';
		}

		// Build the Gemini-format contents array (OpenAI → Gemini conversion).
		$contents = self::build_gemini_contents( $messages );

		$body = array(
			'system_instruction' => array( 'parts' => array( array( 'text' => $system_prompt ) ) ),
			'contents'           => $contents,
			'generationConfig'   => array( 'maxOutputTokens' => 2048 ),
		);

		if ( array() !== $tools ) {
			// WP 7.1: Sanitize tool schemas to Draft-4 for strict AI clients.
			$sanitized_tools = $tools;
			if ( \function_exists( 'wp_prepare_json_schema_for_client' ) ) {
				$sanitized_tools = array_map(
					function ( $t ) {
						$fn = $t['function'] ?? $t;
						if ( isset( $fn['parameters'] ) ) {
								$sanitized_params = \call_user_func( 'wp_prepare_json_schema_for_client', $fn['parameters'], 'draft-04' );
							if ( is_array( $sanitized_params ) ) {
								$fn['parameters'] = $sanitized_params;
							}
						}
						if ( isset( $t['function'] ) ) {
							$t['function'] = $fn;
						} else {
							$t = $fn;
						}
						return $t;
					},
					$sanitized_tools 
				);
			}
			$body['tools'] = array( array( 'function_declarations' => array_map( fn( $t ) => $t['function'] ?? $t, $sanitized_tools ) ) );
		}

		// 2026.4 BP: Cascade: primary model first, then cheaper/resilient fallbacks.
		// The chain is now dynamic, deprioritizing models with recent failures.
		$fallback_chain = self::get_dynamic_fallback_chain( $model );
		$last_result    = array(
			'content'       => '',
			'tool_calls'    => array(),
			'finish_reason' => 'error',
			'error'         => 'No models available.',
		);
		$cascade_start  = \microtime( true );
		$cascade_budget = ( 'cli' === \php_sapi_name() ) ? 50.0 : 38.0; // 2026 BP: Bound web requests within 38s (FastCGI 45-60s limit).

		// Respect the request-wide deadline, leaving time for the secondary provider.
		$reserve        = self::get_configured_fallback_provider() ? self::FALLBACK_RESERVE_SECONDS : 0.0;
		$cascade_budget = \min( $cascade_budget, \max( 4.0, self::remaining_time() - $reserve ) );
		$thinking_level = self::get_gemini_thinking_level();

		foreach ( $fallback_chain as $idx => $try_model ) {
			$elapsed = \microtime( true ) - $cascade_start;
			// Abort if we are already close to the proxy timeout wall.
			if ( $elapsed >= $cascade_budget - 2.0 ) {
				if ( $idx > 0 ) {
					self::log_error( "Gemini cascade wall-clock budget ({$cascade_budget}s) exceeded — aborting." );
					break;
				}
			}

			// Per-attempt timeout shrinks to whatever budget remains (floor 10s for model thinking/tools).
			$attempt_timeout = (int) \max( 10, \min( ( 'cli' === \php_sapi_name() ) ? 25 : 18, (int) \floor( $cascade_budget - $elapsed ) ) );

			// Gemini 3.8 & 4 Argon adaptive-thinking models share a unified maxOutputTokens pool across
			// internal thinking tokens, functionCall arguments, and final text output. Scale maxOutputTokens
			// with thinkingLevel so 'medium' and 'high' reasoning budgets never truncate mid-thought.
			$attempt_body = $body;
			if ( null !== $thinking_level && 'adaptive' === ModelCatalog::get_thinking_mode( self::PROVIDER_GEMINI, $try_model ) ) {
				$attempt_body['generationConfig']['thinkingConfig']  = array( 'thinkingLevel' => $thinking_level );
				$attempt_body['generationConfig']['maxOutputTokens'] = match ( $thinking_level ) {
					'high'   => 8192,
					'medium' => 4096,
					default  => 2048,
				};
			}

			// Always use v1beta — it supports system_instruction, tools/function_declarations,
			// and generationConfig across all model generations. The v1 stable endpoint uses
			// a stricter camelCase-only JSON schema that breaks these fields.
			// Key travels in the x-goog-api-key header, never in the URL (logs/proxies).
			$endpoint    = self::get_provider_endpoint( self::PROVIDER_GEMINI, $try_model );
			$last_result = self::http_gemini_request( $endpoint, $attempt_body, $try_model, $api_key, $attempt_timeout );

			// Self-heal: a model that rejects the thinking level is retried once without it.
			if ( null !== $last_result['error'] && isset( $attempt_body['generationConfig']['thinkingConfig'] ) && \str_contains( \strtolower( (string) $last_result['error'] ), 'thinking' ) ) {
				self::log_error( "Gemini model {$try_model} rejected thinkingConfig — retrying without it." );
				$last_result = self::http_gemini_request( $endpoint, $body, $try_model, $api_key, $attempt_timeout );
			}

			if ( null === $last_result['error'] ) {
				$last_result['model'] = $try_model;
				if ( $try_model !== $model ) {
					$last_result['fallback_model'] = $try_model;
					$last_result['original_model'] = $model;
				}
				return $last_result; // Success — done.
			}

			// Host unreachable: every model lives on the same host, so cascading only
			// multiplies the wait (was up to 40s). Fail over to the next provider tier.
			if ( self::is_transport_error( (string) $last_result['error'] ) ) {
				self::log_error( "Gemini host unreachable ({$last_result['error']}) — skipping model cascade." );
				return $last_result;
			}

			if ( ! self::is_transient_gemini_error( (string) $last_result['error'] ) ) {
				// Defensive 404 Resilience: If a model alias is not found, skip to next in chain immediately.
				if ( \str_contains( \strtolower( (string) $last_result['error'] ), '404' ) || \str_contains( \strtolower( (string) $last_result['error'] ), 'not found' ) ) {
					self::record_model_failure( $try_model, self::MODEL_MISSING_TTL );
					self::log_error( "Gemini model {$try_model} not found — skipping to next in chain." );
					continue;
				}
				// Permanent error (bad key, invalid request…) — stop cascading immediately.
				// The model itself is healthy, so it must NOT be marked as failed; doing so
				// silently demoted the configured primary model for 5 minutes.
				return $last_result;
			}

			// Transient capacity error: deprioritise this specific model for the next request
			// and move on immediately. Each model has its own capacity pool, so sleeping
			// before trying a *different* model only added up to 1.5s of dead time per hop.
			$ttl       = 300;
			$err_lower = \strtolower( (string) $last_result['error'] );
			if ( \preg_match( '/retry in\s+(\d+)h/i', (string) $last_result['error'], $m_matches ) ) {
				$ttl = \min( 86400, ( (int) $m_matches[1] ) * 3600 );
			} elseif ( \str_contains( $err_lower, 'free_tier_requests' ) || \str_contains( $err_lower, 'daily' ) ) {
				$ttl = 3600;
			}
			self::record_model_failure( $try_model, $ttl );
			self::log_error( "Gemini model {$try_model} error (transient, deprioritised for {$ttl}s) — marking as busy and moving to next in chain." );
		}

		// Entire cascade failed. Central prompt() handler will increment the global score.
		return $last_result;
	}

	/**
	 * Return the model fallback chain starting from the requested model.
	 *
	 * 2026.5 BP: Dynamic chain – models that failed in the last 5 minutes are deprioritized.
	 * Note: This cascade prioritizes "Service Continuity" (ensuring the guest gets a response)
	 * over "Absolute Minimum Cost". If the cheapest model is busy, the system will try
	 * more available tiers in the ecosystem.
	 *
	 * November 2026 Verified Active Models:
	 *   GA       : gemini-3.8-flash — recommended frontier workhorse
	 *              gemini-4-hydrogen — ultra-fast frontier agentic
	 *              gemini-4-carbon — high-intelligence reasoning flagship
	 *              gemini-4-argon — supreme deep reasoning flagship
	 *              gemini-3.7-flash — high-efficiency workhorse
	 *              gemini-3.5-flash-lite — ultra budget
	 *   PREVIEW  : gemini-3-flash-preview — Computer Use support
	 *              gemini-3.1-pro-preview — reasoning preview
	 *
	 * @param string $primary
	 * @return list<string>
	 */
	private static function get_dynamic_fallback_chain( string $primary ): array {
		// Alias map: allow shorthand or legacy admin selections to resolve to canonical model IDs.
		$blanket_map = array(
			'gemini-stable-primary' => 'gemini-3.8-flash',
			'gemini-stable-high'    => 'gemini-4-carbon',
			'gemini-flash-latest'   => 'gemini-3.8-flash',
			'gemini-pro-latest'     => 'gemini-4-argon',
			'gemini-3.8-pro'        => 'gemini-4-carbon',
		);

		if ( isset( $blanket_map[ $primary ] ) ) {
			$primary = $blanket_map[ $primary ];
		}

		// Law 62 Catalog Truth: ModelCatalog is the single source of active models,
		// EOL exclusion, and recent-failure deprioritisation (mhbo_ai_model_fail_*).
		$raw_chain   = ModelCatalog::get_fallback_chain( self::PROVIDER_GEMINI, $primary );
		$clean_chain = array();
		foreach ( $raw_chain as $m ) {
			$m = $blanket_map[ $m ] ?? $m;
			if ( ! in_array( $m, $clean_chain, true ) ) {
				$clean_chain[] = $m;
			}
		}

		return $clean_chain;
	}

	/**
	 * Detect a deterministic request-payload rejection (malformed body / schema).
	 *
	 * Such errors are independent of the model or API key, so retrying the same
	 * payload on the same provider can never succeed.
	 *
	 * @param string $error_message Error message returned by the provider.
	 * @return bool
	 */
	private static function is_request_payload_error( string $error_message ): bool {
		$lower   = \strtolower( $error_message );
		$phrases = array(
			'invalid json payload',
			'unknown name',
			'proto field',
			'cannot start list',
			'invalid value at',
		);
		foreach ( $phrases as $phrase ) {
			if ( \str_contains( $lower, $phrase ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Shape a tool result into a valid Gemini `functionResponse.response` value.
	 *
	 * Gemini types `response` as a protobuf Struct (JSON object). Tool results that
	 * encode as a JSON list (e.g. get_seasonal_policies, get_local_attractions),
	 * an empty array, a scalar, or non-JSON text trigger HTTP 400
	 * "Proto field is not repeating, cannot start list". Non-object payloads are
	 * therefore wrapped under a `result` key.
	 *
	 * @param mixed $content Raw tool message content (usually a JSON string).
	 * @return array<string, mixed>|\stdClass Object-shaped payload.
	 */
	public static function to_gemini_function_response( mixed $content ): array|\stdClass {
		if ( \is_array( $content ) ) {
			$decoded = $content;
		} else {
			$raw = \is_scalar( $content ) ? (string) $content : '';
			if ( '' === \trim( $raw ) ) {
				return new \stdClass();
			}
			$decoded = \json_decode( $raw, true );
			if ( null === $decoded && \JSON_ERROR_NONE !== \json_last_error() ) {
				return array( 'result' => $raw );
			}
		}

		if ( \is_array( $decoded ) && array() !== $decoded && ! \array_is_list( $decoded ) ) {
			/** @var array<string, mixed> $decoded */
			return $decoded;
		}

		return array( 'result' => $decoded );
	}

	/**
	 * Get the Gemini thinking level based on settings and speed optimization.
	 *
	 * Gemini 3 adaptive models support low, medium, and high thinking levels.
	 * For conversational concierges, 'low' dramatically cuts TTFT (measured 2.4s vs 5.2s)
	 * while preserving reasoning accuracy.
	 *
	 * @return string|null 'low'|'medium'|'high' or null if thinkingConfig is disabled.
	 */
	public static function get_gemini_thinking_level(): ?string {
		$effort = (string) get_option( 'mhbo_ai_reasoning_effort', 'low' );
		if ( 'none' === $effort ) {
			return 'low'; // Speed-first default for real-time guest concierge.
		}
		if ( in_array( $effort, array( 'low', 'medium', 'high' ), true ) ) {
			return $effort;
		}
		return 'low';
	}

	/**
	 * Reset the failure score and clear the active circuit breaker.
	 */
	public static function reset_failure_score(): void {
		delete_transient( 'mhbo_ai_error_score' );
		delete_transient( 'mhbo_ai_quota_lock' );
	}

	/**
	 * Mark a specific model as unreliable for a given duration.
	 *
	 * @param string $model
	 * @param int    $ttl Seconds to remember failure (default 300 = 5 minutes).
	 */
	private static function record_model_failure( string $model, int $ttl = 300 ): void {
		set_transient( 'mhbo_ai_model_fail_' . $model, 1, $ttl );
	}

	/**
	 * 2026.4 BP: Increment system-wide failure score and apply tiered locking.
	 */
	private static function increment_failure_score(): void {
		$score = (int) get_transient( 'mhbo_ai_error_score' ) + 1;
		
		// 2026.4 BP: Reset score if it was cold (transient naturally expires in 15m)
		set_transient( 'mhbo_ai_error_score', $score, 900 );

		// Tiered Lock Duration:
		// Score 1: 0s (single glitch allows immediate guest retry without lock)
		// Score 2: 30s (active outage)
		// Score 3+: 15m (hard block/circuit trip)
		$duration = match ( true ) {
			$score >= 3 => 900,
			$score >= 2 => 30,
			default     => 0,
		};

		if ( $duration > 0 ) {
			$new_lock = \time() + $duration;
			\set_transient( 'mhbo_ai_quota_lock', $new_lock, $duration + 60 );
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				\error_log( "[MHBO AI] Resilience: Circuit Breaker score expanded to {$score}. Cooling down for {$duration}s." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug telemetry, guarded by WP_DEBUG_LOG
			}
		} elseif ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			\error_log( "[MHBO AI] Resilience: Single failure registered (score {$score}). Allowing immediate guest retry." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug telemetry, guarded by WP_DEBUG_LOG
		}
	}

	/**
	 * Check if the AI Circuit Breaker is currently tripped.
	 *
	 * @return bool
	 */
	public static function is_circuit_broken(): bool {
		$lock = get_transient( 'mhbo_ai_quota_lock' );
		if ( ! $lock ) {
			return false;
		}

		return time() < (int) $lock;
	}

	/**
	 * Detect transport-level failures (DNS, TCP connect, TLS) — the provider host
	 * itself is unreachable, so trying another model on the same host is pointless.
	 *
	 * Note: "Operation timed out … bytes received" (slow response) is NOT transport;
	 * another model may answer faster, so it remains a transient model error.
	 *
	 * @param string $error_message Error message from WP_Http / provider.
	 * @return bool
	 */
	private static function is_transport_error( string $error_message ): bool {
		$lower   = \strtolower( $error_message );
		$phrases = array(
			'could not resolve',
			'name resolution',
			'connection timeout after',
			'connection timed out',
			'failed to connect',
			'connection refused',
			'network is unreachable',
			'no route to host',
			'ssl connect error',
			'curl error 6:',
			'curl error 7:',
			'curl error 35:',
		);
		foreach ( $phrases as $phrase ) {
			if ( \str_contains( $lower, $phrase ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect transient Gemini capacity / rate-limit errors that warrant a retry/fallback.
	 *
	 * @param string $error_message
	 * @return bool
	 */
	private static function is_transient_gemini_error( string $error_message ): bool {
		// 2026.6 BP: Added cURL timeout/network phrases. Without these, cURL error 28
		// ("Operation timed out") was classified as permanent, aborting the cascade
		// immediately instead of trying fallback models.
		$transient_phrases = array(
			'high demand',
			'temporarily unavailable',
			'overloaded',
			'resource exhausted',
			'quota exceeded',
			'rate limit',
			'request limit',
			'capacity',
			'502',
			'503',
			'504',
			'429',
			'curl error',
			'timed out',
			'timeout',
			'operation timed out',
			'connection reset',
		);
		$lower             = \strtolower( $error_message );
		foreach ( $transient_phrases as $phrase ) {
			if ( \str_contains( $lower, $phrase ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Execute a single HTTP request to the Gemini generateContent endpoint.
	 *
	 * @param string       $url             Full endpoint URL (model already embedded, no credentials).
	 * @param array<mixed> $body            JSON-serialisable request body.
	 * @param string       $model           Model name (for error logging only).
	 * @param string       $api_key         Gemini API key (sent as x-goog-api-key header).
	 * @param int|null     $timeout_seconds Custom attempt timeout.
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null,retry_after?:int}
	 */
	private static function http_gemini_request( string $url, array $body, string $model, string $api_key, ?int $timeout_seconds = null ): array {
		$attempt_timeout = $timeout_seconds ?? ( ( 'cli' === \php_sapi_name() ) ? 25 : 15 );
		$args = array(
			'timeout'        => $attempt_timeout, // 2026 BP: Dynamic attempt timeout enables fast failover across model cascade before webserver ceilings (30-40s).
			'connecttimeout' => 5,  // 2026 BP: Fast 5s connect timeout.
			'sslverify'      => true,
			'headers'        => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'           => (string) ( wp_json_encode( $body ) ?: '{}' ),
		);

		/**
		 * Filters outbound HTTP request arguments for AI API calls.
		 *
		 * @since 2.6.2
		 *
		 * @param array<string, mixed> $args     HTTP request arguments.
		 * @param string               $url      Request URL.
		 * @param string               $provider Provider slug.
		 */
		$args     = (array) apply_filters( 'mhbo_ai_http_args', $args, $url, self::PROVIDER_GEMINI );
		$response = wp_safe_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$err = $response->get_error_message();
			self::log_error( "Gemini HTTP error [{$model}]: {$err}" );
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $err,
			);
		}

		$http_code   = (int) wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );
		$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );

		if ( ! is_array( $data ) ) {
			// translators: %d: HTTP status code
			$err = sprintf( \MHBO\Core\I18n::get_label( 'ai_error_bad_json' ), (int) $http_code );
			self::log_error( $err );
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $err,
			);
		}

		if ( isset( $data['error'] ) ) {
			$err = $data['error']['message'] ?? \MHBO\Core\I18n::get_label( 'ai_error_unknown_gemini' );
			self::log_error( "Gemini API error [{$model}] HTTP {$http_code}: {$err}" );
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $err,
				'retry_after'   => $retry_after,
			);
		}

		$candidate = $data['candidates'][0] ?? array();
		$parts     = $candidate['content']['parts'] ?? array();

		$text           = '';
		$thought        = '';
		$text_signature = '';
		$tool_calls     = array();

		foreach ( $parts as $part ) {
			// Gemini v1beta marks internal reasoning blocks with boolean 'thought' => true alongside 'text'.
			// Never leak internal reasoning parts into guest-facing '$text'.
			if ( true === ( $part['thought'] ?? false ) ) {
				$thought       .= (string) ( $part['text'] ?? '' );
				$text_signature = $part['thoughtSignature'] ?? $part['thought_signature'] ?? $text_signature;
			} elseif ( isset( $part['thought'] ) && is_string( $part['thought'] ) && '' !== $part['thought'] ) {
				$thought .= $part['thought'];
			} elseif ( isset( $part['text'] ) ) {
				$text          .= (string) $part['text'];
				$text_signature = $part['thoughtSignature'] ?? $part['thought_signature'] ?? $text_signature;
			}

			if ( isset( $part['functionCall'] ) ) {
				$raw_args = $part['functionCall']['args'] ?? array();
				$tc_entry = array(
					'id'       => 'call_' . wp_generate_password( 8, false ),
					'type'     => 'function',
					'function' => array(
						'name'      => $part['functionCall']['name'] ?? '',
						'arguments' => ( is_array( $raw_args ) || is_object( $raw_args ) ) ? wp_json_encode( (object) $raw_args ) : '{}',
					),
				);

				// Capture thoughtSignature from Part level (mandatory for Gemini 3.8 & 4 Argon multi-turn tool calls).
				$signature = $part['thoughtSignature'] ?? $part['thought_signature'] ?? '';
				if ( $signature ) {
					$tc_entry['thought_signature'] = $signature;
				}

				$tool_calls[] = $tc_entry;
			}
		}

		return array(
			'content'           => $text,
			'thought'           => (string) ( ( $candidate['content']['thought'] ?? '' ) ?: $thought ),
			'thought_signature' => $text_signature,
			'tool_calls'        => $tool_calls,
			'raw_parts'         => $parts,
			'finish_reason'     => (string) ( $candidate['finishReason'] ?? 'stop' ),
			'error'             => null,
			'retry_after'       => $retry_after,
		);
	}

	/**
	 * Convert OpenAI-style message array to Gemini `contents` format.
	 *
	 * @param array<mixed> $messages
	 * @return array<mixed>
	 */
	public static function build_gemini_contents( array $messages ): array {
		// Build a tool_call_id → function_name map for functionResponse lookup.
		$tc_name_map = array();
		foreach ( $messages as $msg ) {
			if ( 'assistant' === ( $msg['role'] ?? '' ) && array() !== ( $msg['tool_calls'] ?? array() ) ) {
				foreach ( (array) $msg['tool_calls'] as $tc ) {
					$tc_id   = $tc['id'] ?? '';
					$tc_name = $tc['function']['name'] ?? '';
					if ( '' !== $tc_id && '' !== $tc_name ) {
						$tc_name_map[ $tc_id ] = $tc_name;
					}
				}
			}
		}

		$contents             = array();
		$pending_fn_responses = array(); // buffer consecutive tool results into one user turn

		foreach ( $messages as $i => $msg ) {
			$role = $msg['role'] ?? 'user';

			// Flush buffered functionResponses before any non-tool message.
			if ( 'tool' !== $role && array() !== $pending_fn_responses ) {
				$contents[]           = array(
					'role'  => 'user',
					'parts' => $pending_fn_responses,
				);
				$pending_fn_responses = array();
			}

			if ( 'model' === $role || 'assistant' === $role ) {
				$parts = array();
				if ( '' !== (string) ( $msg['thought'] ?? '' ) ) {
					$parts[] = array(
						'text'    => (string) $msg['thought'],
						'thought' => true,
					);
				}
				if ( '' !== (string) ( $msg['content'] ?? '' ) ) {
					$part = array( 'text' => (string) $msg['content'] );
					$sig  = (string) ( $msg['thought_signature'] ?? $msg['thoughtSignature'] ?? '' );
					if ( '' !== $sig && array() === ( $msg['tool_calls'] ?? array() ) ) {
						$part['thoughtSignature'] = $sig;
					}
					$parts[] = $part;
				}
				foreach ( (array) ( $msg['tool_calls'] ?? array() ) as $tc ) {
					$decoded = json_decode( $tc['function']['arguments'] ?? '{}' );
					if ( $decoded instanceof \stdClass ) {
						$args = $decoded;
					} elseif ( is_array( $decoded ) && array() !== $decoded ) {
						$args = (object) $decoded;
					} else {
						$args = new \stdClass();
					}

					$part = array(
						'functionCall' => array(
							'name' => $tc['function']['name'] ?? '',
							'args' => $args,
						),
					);

					// Canonical camelCase thoughtSignature on Part level (avoids duplicate protobuf field rejection).
					$tc_sig = (string) ( $tc['thought_signature'] ?? $tc['thoughtSignature'] ?? '' );
					if ( '' !== $tc_sig ) {
						$part['thoughtSignature'] = $tc_sig;
					}

					$parts[] = $part;
				}
				if ( array() !== $parts ) {
					$contents[] = array(
						'role'  => 'model',
						'parts' => $parts,
					);
				}
				continue;
			}

			if ( 'tool' === $role ) {
				$tc_name = (string) ( $msg['name'] ?? '' );
				$tc_id   = (string) ( $msg['tool_call_id'] ?? '' );

				// 2026 BP: Look-back logic for robustness. If name is missing (old sessions),
				// search preceding assistant messages for the matching call ID.
				if ( '' === $tc_name && '' !== $tc_id ) {
					for ( $j = $i - 1; $j >= 0; $j-- ) {
						$prev = $messages[ $j ];
						if ( ( 'assistant' === ( $prev['role'] ?? '' ) || 'model' === ( $prev['role'] ?? '' ) ) && array() !== ( $prev['tool_calls'] ?? array() ) ) {
							foreach ( $prev['tool_calls'] as $tc ) {
								if ( ( $tc['id'] ?? '' ) === $tc_id ) {
									$tc_name = (string) ( $tc['function']['name'] ?? '' );
									break 2;
								}
							}
						}
					}
				}

				// If still vacant (very old/corrupt), fallback to a generic marker 
				// but Gemini MIGHT still 400. Re-storage fix in ChatRest handles new turns.
				if ( '' === $tc_name ) {
					$tc_name = 'unknown_tool_call';
				}

				$pending_fn_responses[] = array(
					'functionResponse' => array(
						'name'     => $tc_name,
						'response' => self::to_gemini_function_response( $msg['content'] ?? '' ),
						'id'       => $tc_id ?: 'call_gen_' . wp_generate_password( 8, false ),
					),
				);
				continue;
			}

			// Regular user (or system) message.
			$contents[] = array(
				'role'  => 'user',
				'parts' => array( array( 'text' => (string) ( $msg['content'] ?? '' ) ) ),
			);
		}

		// Flush any remaining buffered functionResponses.
		if ( array() !== $pending_fn_responses ) {
			$contents[] = array(
				'role'  => 'user',
				'parts' => $pending_fn_responses,
			);
		}

		return self::normalize_gemini_contents( $contents );
	}

	/**
	 * Normalize Gemini conversation turns to guarantee API schema compliance:
	 * 1. Remove leading model turns (Gemini conversation must start with 'user').
	 * 2. Merge consecutive turns with the same role into a single turn with merged parts.
	 * 3. Enforce functionCall -> functionResponse pairing: if a model turn includes functionCall,
	 *    ensure the immediately following turn has role 'user' containing the matching functionResponse(s).
	 *    If missing (e.g. from an aborted/timed-out previous turn), synthesize a resolution response.
	 *
	 * @param array<int, array<string, mixed>> $contents
	 * @return list<array{role: string, parts: list<array<string, mixed>>}>
	 */
	public static function normalize_gemini_contents( array $contents ): array {
		if ( array() === $contents ) {
			return array();
		}

		// 1. Remove leading model turns (Gemini conversation must start with 'user').
		while ( array() !== $contents && 'user' !== (string) ( $contents[0]['role'] ?? '' ) ) {
			\array_shift( $contents );
		}

		if ( array() === $contents ) {
			return array();
		}

		// 2. Merge consecutive turns of identical role & ensure functionCall -> functionResponse pairing.
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort
		/** @var list<array{role: string, parts: list<array<string, mixed>>}> $normalized */
		$normalized = array();

		foreach ( $contents as $turn ) {
			$role      = (string) ( $turn['role'] ?? 'user' );
			$raw_parts = (array) ( $turn['parts'] ?? array() );
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort
			/** @var list<array<string, mixed>> $parts */
			$parts     = array_values( $raw_parts );
			if ( array() === $parts ) {
				continue;
			}

			$last_idx = \count( $normalized ) - 1;

			if ( $last_idx >= 0 && isset( $normalized[ $last_idx ]['role'] ) && $normalized[ $last_idx ]['role'] === $role ) {
				// Same role: merge parts into the preceding turn.
				$merged = \array_merge( $normalized[ $last_idx ]['parts'], $parts );
				$normalized[ $last_idx ]['parts'] = array_values( $merged );
			} else {
				// Different role.
				// Check if previous turn was 'model' with functionCall(s).
				if ( $last_idx >= 0 && isset( $normalized[ $last_idx ]['role'] ) && 'model' === $normalized[ $last_idx ]['role'] ) {
					$prev_fc_calls = array();
					foreach ( $normalized[ $last_idx ]['parts'] as $p ) {
						if ( isset( $p['functionCall'] ) && is_array( $p['functionCall'] ) ) {
							$prev_fc_calls[] = array(
								'name' => (string) ( $p['functionCall']['name'] ?? 'unknown_function' ),
								'id'   => (string) ( $p['functionCall']['id'] ?? '' ),
							);
						}
					}

					// If previous model turn had functionCall(s), this next turn MUST contain functionResponses for them.
					if ( array() !== $prev_fc_calls ) {
						$resolved_names = array();
						foreach ( $parts as $p ) {
							if ( isset( $p['functionResponse'] ) && is_array( $p['functionResponse'] ) ) {
								$resolved_names[] = (string) ( $p['functionResponse']['name'] ?? '' );
							}
						}

						// Synthesize resolution for any unfulfilled functionCall from previous turn.
						$synth_responses = array();
						foreach ( $prev_fc_calls as $fc ) {
							if ( ! \in_array( $fc['name'], $resolved_names, true ) ) {
								$synth_responses[] = array(
									'functionResponse' => array(
										'name'     => $fc['name'],
										'response' => array( 'output' => 'Tool call completed in prior turn or superseded.' ),
										'id'       => $fc['id'] ?: 'call_syn_' . \wp_generate_password( 8, false ),
									),
								);
							}
						}

						if ( array() !== $synth_responses ) {
							$merged = \array_merge( $synth_responses, $parts );
							// phpcs:ignore Generic.Commenting.DocComment.MissingShort
							/** @var list<array<string, mixed>> $parts */
							$parts  = array_values( $merged );
						}
					}
				}

				$normalized[] = array(
					'role'  => $role,
					'parts' => $parts,
				);
			}
		}

		return array_values( $normalized );
	}

	/**
	 * HTTP call to an OpenAI-compatible API (OpenAI, OpenRouter, Ollama, custom).
	 *
	 * @param string       $api_key
	 * @param string       $model
	 * @param array<mixed> $messages
	 * @param string       $system_prompt
	 * @param array<mixed> $tools
	 * @param string       $endpoint
	 * @param string       $provider      Resolved provider slug for capability & filter dispatch.
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
	 */
	private static function http_openai( string $api_key, string $model, array $messages, string $system_prompt, array $tools, string $endpoint, string $provider = self::PROVIDER_OPENAI ): array {
		// Auto-migrate retired models (August–October 2026 EOL).
		if ( 'gpt-5.4-mini' === $model ) {
			self::log_error( 'Model gpt-5.4-mini retired (August 31, 2026). Auto-migrating to gpt-6-luna.' );
			$model = 'gpt-6-luna';
			if ( self::PROVIDER_OPENAI === $provider ) {
				$endpoint = str_replace( '/v1/chat/completions', '/v1/responses', $endpoint );
			}
		} elseif ( 'gpt-5.4' === $model ) {
			self::log_error( 'Model gpt-5.4 retired (August 31, 2026). Auto-migrating to gpt-6.1-terra.' );
			$model = 'gpt-6.1-terra';
			if ( self::PROVIDER_OPENAI === $provider ) {
				$endpoint = str_replace( '/v1/chat/completions', '/v1/responses', $endpoint );
			}
		} elseif ( in_array( $model, array( 'gpt-4o', 'gpt-4o-mini' ), true ) && gmdate( 'Y-m-d' ) >= '2026-10-23' ) {
			self::log_error( "Model {$model} retired (October 23, 2026). Auto-migrating to gpt-6-luna." );
			$model    = 'gpt-6-luna';
			$endpoint = str_replace( '/v1/chat/completions', '/v1/responses', $endpoint );
		}

		$is_responses_api = str_contains( $endpoint, '/v1/responses' );

		if ( $is_responses_api ) {
			// Modern 2026 'Responses' payload with structured conversation & tool items.
			$input_items = array();
			foreach ( $messages as $msg ) {
				$role = (string) ( $msg['role'] ?? 'user' );
				if ( 'tool' === $role ) {
					$call_id       = (string) ( $msg['tool_call_id'] ?? $msg['name'] ?? '' );
					$input_items[] = array(
						'type'    => 'function_call_output',
						'call_id' => '' !== $call_id ? $call_id : 'call_' . wp_generate_password( 6, false ),
						'output'  => (string) ( $msg['content'] ?? '{}' ),
					);
					continue;
				}

				$content = (string) ( $msg['content'] ?? '' );
				if ( '' !== $content ) {
					$input_items[] = array(
						'role'    => ( 'assistant' === $role ) ? 'assistant' : 'user',
						'content' => $content,
					);
				}

				if ( 'assistant' === $role && ! empty( $msg['tool_calls'] ) && is_array( $msg['tool_calls'] ) ) {
					foreach ( $msg['tool_calls'] as $tc ) {
						$tc_name = (string) ( $tc['function']['name'] ?? $tc['name'] ?? '' );
						if ( '' === $tc_name ) {
							continue;
						}
						$tc_args       = $tc['function']['arguments'] ?? $tc['arguments'] ?? '{}';
						$input_items[] = array(
							'type'      => 'function_call',
							'call_id'   => (string) ( $tc['id'] ?? 'call_' . wp_generate_password( 8, false ) ),
							'name'      => $tc_name,
							'arguments' => is_string( $tc_args ) ? $tc_args : (string) ( wp_json_encode( $tc_args ) ?: '{}' ),
						);
					}
				}
			}

			// store:false — never persist guest conversations (PII) on OpenAI servers (GDPR).
			$body = array(
				'model'        => $model,
				'instructions' => $system_prompt,
				'input'        => $input_items,
				'store'        => false,
			);

			if ( array() !== $tools ) {
				$resp_tools = array();
				foreach ( $tools as $t ) {
					$t_fn = $t['function'] ?? $t;
					$name = (string) ( $t_fn['name'] ?? '' );
					if ( '' === $name ) {
						continue;
					}
					$params = $t_fn['parameters'] ?? array(
						'type'       => 'object',
						'properties' => (object) array(),
					);
					if ( \function_exists( 'wp_prepare_json_schema_for_client' ) ) {
						$prepared = \call_user_func( 'wp_prepare_json_schema_for_client', $params, 'draft-04' );
						if ( is_array( $prepared ) ) {
							$params = $prepared;
						}
					}
					$resp_tools[] = array(
						'type'        => 'function',
						'name'        => $name,
						'description' => (string) ( $t_fn['description'] ?? '' ),
						'parameters'  => $params,
					);
				}
				if ( array() !== $resp_tools ) {
					$body['tools'] = $resp_tools;
				}
			}
		} else {
			$body = array(
				'model'    => $model,
				'messages' => array_merge(
					array(
						array(
							'role'    => 'system',
							'content' => $system_prompt,
						),
					),
					$messages
				),
			);

			if ( array() !== $tools ) {
				$body['tools'] = $tools;
			}

			// 2026 BP: Standardize reasoning content for multi-turn persistence.
			foreach ( $body['messages'] as &$bm ) {
				if ( 'assistant' === ( $bm['role'] ?? '' ) && '' !== (string) ( $bm['thought'] ?? '' ) ) {
					$bm['reasoning_content'] = $bm['thought'];
				}
			}
		}

$headers = array( 'Content-Type' => 'application/json' );
		if ( $api_key ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		// OpenRouter-specific rankings/dashboard attribution (2026 BP).
		if ( str_contains( $endpoint, 'openrouter.ai' ) ) {
			$headers['HTTP-Referer'] = home_url();
			$headers['X-Title']      = 'Modern Hotel Booking (' . get_bloginfo( 'name' ) . ')';
		}

		$args = array(
			'timeout'        => 25, // 2026 BP: Generous 25s timeout for reasoning models.
			'connecttimeout' => 10,
			'sslverify'      => true,
			'headers'        => $headers,
			'body'           => (string) ( wp_json_encode( $body ) ?: '{}' ),
		);

		$args     = (array) apply_filters( 'mhbo_ai_http_args', $args, $endpoint, $provider );
		$response = wp_safe_remote_post( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			self::log_error( 'OpenAI-compatible HTTP error: ' . $response->get_error_message() );
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $response->get_error_message(),
			);
		}

		return self::parse_openai_response( $response, $endpoint, $api_key, $model, $messages, $system_prompt, $tools, $provider );
	}

	/**
	 * Parse the response from an OpenAI-compatible endpoint.
	 *
	 * @param array<string, mixed>|\WP_Error   $response
	 * @param array<int, array<string, mixed>> $messages
	 * @param array<int, array<string, mixed>> $tools
	 * @param string                           $provider
	 * @return array{content:string,thought?:string,tool_calls:array<mixed>,usage?:array<string,mixed>,finish_reason:string,error:string|null}
	 */
	private static function parse_openai_response( $response, string $endpoint, string $api_key, string $model, array $messages, string $system_prompt, array $tools, string $provider = self::PROVIDER_OPENAI ): array {
		if ( is_wp_error( $response ) ) {
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $response->get_error_message(),
			);
		}

		$http_code        = (int) wp_remote_retrieve_response_code( $response );
		$data             = json_decode( wp_remote_retrieve_body( $response ), true );
		$is_responses_api = str_contains( $endpoint, '/v1/responses' );

		if ( ! is_array( $data ) ) {
			// translators: %d: HTTP status code
			$err = sprintf( \MHBO\Core\I18n::get_label( 'ai_error_bad_json' ), (int) $http_code );
			self::log_error( $err );
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $err,
			);
		}

		if ( isset( $data['error'] ) ) {
			$err = $data['error']['message'] ?? \MHBO\Core\I18n::get_label( 'ai_error_unknown_gemini' );

			if ( $is_responses_api && ( 403 === $http_code || str_contains( strtolower( $err ), 'missing scopes' ) ) ) {
				self::log_error( 'OpenAI Scope Error (api.responses.write missing). Retrying via standard Chat Completions endpoint...' );
				$chat_endpoint = str_replace( '/v1/responses', '/v1/chat/completions', $endpoint );
				return self::http_openai( $api_key, $model, $messages, $system_prompt, $tools, $chat_endpoint, $provider );
			}

			self::log_error( 'OpenAI API error: ' . $err );
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $err,
			);
		}

		$text       = '';
		$thought    = '';
		$tool_calls = array();

		if ( isset( $data['output'] ) && is_array( $data['output'] ) ) {
			foreach ( $data['output'] as $part ) {
				$type = $part['type'] ?? '';
				
				if ( 'message' === $type && isset( $part['content'] ) && is_array( $part['content'] ) ) {
					foreach ( $part['content'] as $c ) {
						if ( 'output_text' === ( $c['type'] ?? '' ) ) {
							$text .= (string) ( $c['text'] ?? '' );
						}
					}
				}
				
				// Reasoning/Thought content (Responses API direct text or summary blocks).
				if ( 'reasoning' === $type ) {
					$thought .= (string) ( $part['text'] ?? $part['content'] ?? $part['reasoning_content'] ?? '' );
					if ( ! empty( $part['summary'] ) && is_array( $part['summary'] ) ) {
						foreach ( $part['summary'] as $sum ) {
							if ( is_array( $sum ) && '' !== (string) ( $sum['text'] ?? '' ) ) {
								$thought .= (string) $sum['text'];
							}
						}
					}
				}

				// Tool calls (Responses API function_call items & chat tool_calls wrapper).
				if ( 'function_call' === $type && '' !== (string) ( $part['name'] ?? '' ) ) {
					$tc_args      = $part['arguments'] ?? '{}';
					$tool_calls[] = array(
						'id'       => (string) ( $part['call_id'] ?? $part['id'] ?? 'call_' . wp_generate_password( 8, false ) ),
						'type'     => 'function',
						'function' => array(
							'name'      => (string) $part['name'],
							'arguments' => is_string( $tc_args ) ? $tc_args : (string) ( wp_json_encode( $tc_args ) ?: '{}' ),
						),
					);
				}
				if ( 'tool_calls' === $type && array() !== ( $part['tool_calls'] ?? array() ) ) {
					$tool_calls = array_merge( $tool_calls, (array) $part['tool_calls'] );
				}
			}
		} elseif ( isset( $data['choices'][0]['message'] ) ) {
			// Handle Legacy v1/chat/completions output.
			$msg        = $data['choices'][0]['message'];
			$text       = (string) ( $msg['content'] ?? '' );
			$thought    = (string) ( $msg['reasoning_content'] ?? '' ); // Capture GPT-5.4 reasoning
			$tool_calls = $msg['tool_calls'] ?? array();
		}

		$finish_reason = (string) ( $data['choices'][0]['finish_reason'] ?? $data['status'] ?? 'stop' );

		$usage = $data['usage'] ?? array();
		
		// OpenRouter / DeepSeek / Reasoning models often put reasoning tokens in usage.
		if ( isset( $usage['reasoning_tokens'] ) && ! isset( $usage['reasoning'] ) ) {
			$usage['reasoning'] = $usage['reasoning_tokens'];
		}

		return array(
			'content'       => $text,
			'thought'       => $thought, // Return separately
			'tool_calls'    => $tool_calls,
			'usage'         => $usage,
			'finish_reason' => $finish_reason,
			'error'         => null,
		);
	}

	/**
	 * HTTP call to Anthropic Claude API.
	 *
	 * @param string       $api_key
	 * @param string       $model
	 * @param array<mixed> $messages
	 * @param string       $system_prompt
	 * @param array<mixed> $tools
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
	 */
	private static function http_anthropic( string $api_key, string $model, array $messages, string $system_prompt, array $tools ): array {
		$model = $model ?: 'claude-sonnet-5-5';
		if ( in_array( $model, array( 'claude-3-5-sonnet-20240620', 'claude-3-5-sonnet', 'claude-3-7-sonnet' ), true ) ) {
			self::log_error( "Model {$model} is EOL/deprecated. Auto-migrating to claude-sonnet-5-5." );
			$model = 'claude-sonnet-5-5';
		} elseif ( 'claude-fable-5-1' === $model ) {
			self::log_error( "Model {$model} is superseded. Auto-migrating to claude-fable-5-2." );
			$model = 'claude-fable-5-2';
		}
		// Anthropic deprecated Claude 4/Opus 4.x. Current coding/high-intelligence flagship is Claude Opus 5.5.
		if ( 'claude-opus-4-7' === $model || 'claude-opus-4-8' === $model ) {
			self::log_error( "Model {$model} is deprecated. Auto-migrating to claude-opus-5-5." );
			$model = 'claude-opus-5-5';
		}

		$anthropic_messages = array();
		foreach ( $messages as $m ) {
			$role = (string) ( $m['role'] ?? 'user' );

			if ( 'tool' === $role ) {
				$tool_result_block = array(
					'type'        => 'tool_result',
					'tool_use_id' => (string) ( $m['tool_call_id'] ?? $m['name'] ?? '' ),
					'content'     => (string) ( $m['content'] ?? '{}' ),
				);
				$last_idx          = count( $anthropic_messages ) - 1;
				if ( $last_idx >= 0 && ( $anthropic_messages[ $last_idx ]['role'] ?? '' ) === 'user' && is_array( $anthropic_messages[ $last_idx ]['content'] ) ) {
					$anthropic_messages[ $last_idx ]['content'][] = $tool_result_block;
				} else {
					$anthropic_messages[] = array(
						'role'    => 'user',
						'content' => array( $tool_result_block ),
					);
				}
				continue;
			}

			if ( 'assistant' === $role ) {
				$blocks = array();
				if ( '' !== (string) ( $m['thought'] ?? '' ) ) {
					$blocks[] = array(
						'type'      => 'thinking',
						'thinking'  => (string) $m['thought'],
						'signature' => (string) ( $m['thought_signature'] ?? '' ),
					);
				}
				if ( null !== ( $m['content'] ?? null ) && '' !== (string) $m['content'] ) {
					$blocks[] = array(
						'type' => 'text',
						'text' => (string) $m['content'],
					);
				}
				if ( ! empty( $m['tool_calls'] ) && is_array( $m['tool_calls'] ) ) {
					foreach ( $m['tool_calls'] as $tc ) {
						$tc_name = (string) ( $tc['function']['name'] ?? $tc['name'] ?? '' );
						if ( '' === $tc_name ) {
							continue;
						}
						$raw_args = $tc['function']['arguments'] ?? $tc['arguments'] ?? '{}';
						$decoded  = is_string( $raw_args ) ? json_decode( $raw_args, true ) : ( is_array( $raw_args ) ? $raw_args : array() );
						$blocks[] = array(
							'type'  => 'tool_use',
							'id'    => (string) ( $tc['id'] ?? 'call_' . wp_generate_password( 8, false ) ),
							'name'  => $tc_name,
							'input' => ( is_array( $decoded ) && array() !== $decoded ) ? $decoded : (object) array(),
						);
					}
				}
				$anthropic_messages[] = array(
					'role'    => 'assistant',
					'content' => array() !== $blocks ? $blocks : (string) ( $m['content'] ?? '' ),
				);
				continue;
			}

			$text_content = (string) ( $m['content'] ?? '' );
			$last_idx     = count( $anthropic_messages ) - 1;
			if ( $last_idx >= 0 && ( $anthropic_messages[ $last_idx ]['role'] ?? '' ) === 'user' && is_array( $anthropic_messages[ $last_idx ]['content'] ) ) {
				$anthropic_messages[ $last_idx ]['content'][] = array(
					'type' => 'text',
					'text' => $text_content,
				);
			} else {
				$anthropic_messages[] = array(
					'role'    => 'user',
					'content' => $text_content,
				);
			}
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => 4096, // 2026 BP: increased for reasoning models
			'system'     => $system_prompt,
			'messages'   => $anthropic_messages,
		);

if ( array() !== $tools ) {
			$body['tools'] = array_map(
				function ( $t ) {
					$schema = $t['function']['parameters'] ?? $t['parameters'] ?? array(
						'type'       => 'object',
						'properties' => array(),
					);

					// WP 7.1: Sanitize schema to Draft-4 for strict AI clients.
					if ( \function_exists( 'wp_prepare_json_schema_for_client' ) ) {
						$prepared = \call_user_func( 'wp_prepare_json_schema_for_client', $schema, 'draft-04' );
						if ( is_array( $prepared ) ) {
							$schema = $prepared;
						}
					}

					return array(
						'name'         => $t['function']['name'] ?? $t['name'] ?? '',
						'description'  => $t['function']['description'] ?? $t['description'] ?? '',
						'input_schema' => $schema,
					);
				},
				$tools 
			);
		}

		// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- 2026 BP: Direct Anthropic API endpoint for adaptive reasoning and tool execution fallback when WP 7.0 wp_ai_client_prompt() is not present.
		$anthropic_endpoint = (string) apply_filters( 'mhbo_ai_anthropic_endpoint', self::get_provider_endpoint( self::PROVIDER_ANTHROPIC, $model ) );
		$anthropic_args     = array(
			'timeout'        => 25, // 2026 BP: Generous 25s timeout for reasoning models.
			'connecttimeout' => 10,
			'sslverify'      => true,
			'headers'        => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			),
			'body'           => (string) ( wp_json_encode( $body ) ?: '{}' ),
		);
		$anthropic_args     = (array) apply_filters( 'mhbo_ai_http_args', $anthropic_args, $anthropic_endpoint, self::PROVIDER_ANTHROPIC );
		$response           = wp_safe_remote_post( $anthropic_endpoint, $anthropic_args );

		if ( is_wp_error( $response ) ) {
			self::log_error( 'Anthropic HTTP error: ' . $response->get_error_message() );
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $response->get_error_message(),
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$data      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			$err = "Anthropic returned non-JSON response (HTTP {$http_code})";
			self::log_error( $err );
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $err,
			);
		}

		if ( isset( $data['error'] ) ) {
			$err = $data['error']['message'] ?? 'Unknown Anthropic error';
			self::log_error( 'Anthropic API error: ' . $err );
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $err,
			);
		}

		$text       = '';
		$thought    = '';
		$signature  = '';
		$tool_calls = array();

		foreach ( $data['content'] ?? array() as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
			if ( 'thinking' === ( $block['type'] ?? '' ) ) {
				$thought  .= $block['thinking'] ?? '';
				$signature = $block['signature'] ?? '';
			}
			if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$tool_calls[] = array(
					'id'       => $block['id'] ?? 'call_' . wp_generate_password( 8, false ),
					'type'     => 'function',
					'function' => array(
						'name'      => $block['name'],
						'arguments' => wp_json_encode( $block['input'] ?? array() ),
					),
				);
			}
		}

		return array(
			'content'           => $text,
			'thought'           => $thought,
			'thought_signature' => $signature,
			'tool_calls'        => $tool_calls,
			'finish_reason'     => (string) ( $data['stop_reason'] ?? 'stop' ),
			'error'             => null,
		);
	}

	/**
	 * Log an error message (only when WP_DEBUG_LOG is active — keys are never logged).
	 *
	 * @param string $message
	 */
	private static function log_error( string $message ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[MHBO AI] ' . $message );
		}
	}
}
