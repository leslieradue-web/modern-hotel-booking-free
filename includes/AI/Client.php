<?php
/**
 * AI Client — unified gateway to any configured AI provider.
 *
 * Supports:
 *  - WP 7.0+ native wp_ai_client_prompt() builder API (when available).
 *  - WP 6.x standalone wordpress/ai-client SDK (when installed via Composer).
 *  - Direct HTTP fallback using the stored API key from plugin settings.
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

	/**
	 * Return a configured AI client object.
	 *
	 * On WP 7.0+ the recommended path is wp_ai_client_prompt() (the builder API),
	 * not a raw client object. This method is preserved for Path 2 (standalone SDK)
	 * backward compatibility. Callers should prefer the prompt() method.
	 *
	 * @return mixed|null
	 */
	public static function get_client(): mixed {
		// Optimization 2026: Use dynamic class name to silence IDE "Undefined type" for optional SDKs.
		if ( class_exists( '\WordPress\AI\Client' ) ) {
			$provider        = self::get_configured_provider();
			$api_key         = self::get_fallback_api_key();
			$wp_client_class = '\WordPress\AI\Client';
			if ( $provider && $api_key ) {
				return new $wp_client_class( (string) $provider, array( 'api_key' => $api_key ) );
			}
		}

		return null;
	}

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
	 * Build the model preference for the WP AI Client from plugin settings.
	 *
	 * Uses the `[provider_id, model_id]` tuple form when the provider is known so
	 * identical model IDs on different providers cannot collide. A preference that
	 * the connector does not offer is harmless: core falls back to the first
	 * available candidate (PromptBuilder::getConfiguredModel()).
	 *
	 * @return array{0:string,1:string}|string|null
	 */
	private static function get_native_model_preference(): array|string|null {
		$model = trim( (string) get_option( self::OPT_PREFIX . 'model', '' ) );
		if ( '' === $model || 'custom' === $model ) {
			return null;
		}

		$provider = self::get_configured_provider();
		if ( is_string( $provider ) && isset( self::NATIVE_PROVIDER_IDS[ $provider ] ) ) {
			return array( self::NATIVE_PROVIDER_IDS[ $provider ], $model );
		}

		return $model;
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

		$start = microtime( true );

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
			// Use 256 tokens so diagnostic ping completes quickly without unnecessary reasoning overhead.
			$builder = \wp_ai_client_prompt( 'Reply with only the word: CONNECTOR_OK' )
				->using_max_tokens( 256 )
				->using_temperature( 0.1 );

			// Diagnostics always probe live and refresh the cache.
			if ( ! $builder->is_supported_for_text_generation() ) {
				self::remember_native_availability( false );
				return array(
					'success'    => false,
					'message'    => __( 'No WordPress AI Connector provider is available for text generation. Either no provider is connected (Settings → Connectors), or the provider could not be reached from this server (slow or blocked outbound HTTPS).', 'modern-hotel-booking' ),
					'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
				);
			}

			$preference = self::get_native_model_preference();
			if ( null !== $preference ) {
				$builder->using_model_preference( $preference );
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
							__( 'The WordPress AI Connector has not been approved for Modern Hotel Booking yet. Please visit <a href="%s" target="_blank" rel="noopener">Tools → AI Connector Approvals</a> to allow access.', 'modern-hotel-booking' ),
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

				if ( $is_transient && null !== $preference ) {
					$pref_model = is_array( $preference ) ? (string) ( $preference[1] ?? '' ) : (string) $preference;
					// If the primary model is gemini-3.8-flash, probe with gemini-3.5-flash-lite to verify connector health.
					$fallback_model = ( 'gemini-3.8-flash' === $pref_model )
						? ( is_array( $preference ) ? array( $preference[0], 'gemini-3.5-flash-lite' ) : 'gemini-3.5-flash-lite' )
						: null;

					if ( null !== $fallback_model ) {
						try {
							$diag_timeout = 8;
							$fb_builder   = \wp_ai_client_prompt( 'Reply with only the word: CONNECTOR_OK' )
								->using_max_tokens( 128 )
								->using_temperature( 0.1 )
								->using_model_preference( $fallback_model );
							$fb_result    = $fb_builder->generate_text_result();

							if ( ! is_wp_error( $fb_result ) ) {
								self::remember_native_availability( true );
								$fb_latency = (int) round( ( microtime( true ) - $start ) * 1000 );
								return array(
									'success'    => true,
									'message'    => sprintf(
										/* translators: 1: primary model name, 2: fallback model name */
										__( 'WordPress AI Connector is active and connected! Note: Primary model "%1$s" is temporarily experiencing high demand on Google (503/timeout), but verified operational via "%2$s". Live chats automatically cascade across models to ensure 100%% uptime.', 'modern-hotel-booking' ),
										$pref_model,
										is_array( $fallback_model ) ? $fallback_model[1] : (string) $fallback_model
									),
									'latency_ms' => $fb_latency,
									'response'   => trim( (string) $fb_result->toText() ),
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

			return array(
				'success'    => true,
				'message'    => __( 'WordPress AI Connector is active and operational!', 'modern-hotel-booking' ),
				'latency_ms' => $latency,
				'response'   => trim( (string) $result->toText() ),
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

		// Decide whether to run Path 1 (WordPress Core AI Client / Connectors)
		// Availability is cached (see is_native_available()) so a misconfigured or
		// unreachable connector costs at most one probe per cache window.
		$try_native = function_exists( 'wp_ai_client_prompt' ) && (
			self::PROVIDER_WP_CONNECTOR === $configured_provider
			|| false === $configured_provider
			|| ! $has_direct_key
		) && self::is_native_available();

		// ── Path 1: WP 7.0+ native AI Client (prompt builder API) ─────────
		if ( $try_native ) {
			try {
				// Build the last user message as the prompt text.
				$last_message = end( $messages );
				$prompt_text  = is_array( $last_message ) ? (string) ( $last_message['content'] ?? '' ) : '';

				// Build conversation history (everything except the final message).
				$history_slice = count( $messages ) > 1 ? array_slice( $messages, 0, -1 ) : array();

				$builder = \wp_ai_client_prompt( $prompt_text )
					->using_system_instruction( $system_prompt )
					->using_max_tokens( 2048 )
					->using_temperature( 0.7 );

				// Attach conversation history using proper Message DTOs
				if ( array() !== $history_slice && class_exists( '\WordPress\AiClient\Messages\DTO\Message' ) && class_exists( '\WordPress\AiClient\Messages\DTO\MessagePart' ) && class_exists( '\WordPress\AiClient\Messages\Enums\MessageRoleEnum' ) ) {
					$message_objs = array();
					foreach ( $history_slice as $hist_item ) {
						$role_str = (string) ( $hist_item['role'] ?? 'user' );
						$content  = (string) ( $hist_item['content'] ?? '' );
						if ( '' === trim( $content ) ) {
							continue;
						}
						$role = ( 'assistant' === $role_str )
							? \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model()
							: \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user();
						$message_objs[] = new \WordPress\AiClient\Messages\DTO\Message( $role, array( new \WordPress\AiClient\Messages\DTO\MessagePart( $content ) ) );
					}
					if ( array() !== $message_objs ) {
						$builder->with_history( ...$message_objs );
					}
				}

				// Model preference (provider-scoped tuple when known). Never rewritten.
				$preference = self::get_native_model_preference();
				if ( null !== $preference ) {
					$builder->using_model_preference( $preference );
				}

				$text = $builder->generate_text();

				if ( is_wp_error( $text ) ) {
					$code = (string) $text->get_error_code();
					// Provider vanished or became unreachable: stop probing for a while.
					if ( in_array( $code, array( 'prompt_invalid_argument', 'prompt_network_error', 'prompt_prevented' ), true ) ) {
						self::remember_native_availability( false );
					}
					self::log_error( 'wp_ai_client_prompt error [' . $code . ']: ' . $text->get_error_message() . '. Cascading to direct API...' );
				} else {
					return self::parse_native_text( (string) $text );
				}
			} catch ( Throwable $e ) {
				self::log_error( 'wp_ai_client_prompt exception: ' . $e->getMessage() . '. Cascading to direct API...' );
				// Fall through to Path 2/3 for resilience.
			}
		}

		// ── Path 2: Standalone wordpress/ai-client SDK ────────────────────────
		if ( class_exists( '\WordPress\AI\Client' ) ) {
			try {
				$c = self::get_client();
				if ( $c ) {
					$result = $c->chat(
						$messages,
						array(
							'system' => $system_prompt,
							'tools'  => $tools,
						) 
					);
					return self::parse_sdk_response( $result );
				}
			} catch ( Throwable $e ) {
				self::log_error( 'AI SDK error: ' . $e->getMessage() );
				// Fall through to HTTP path.
			}
		}

		// ── Path 3: Direct HTTP call ──────────────────────────────────────────
		$result = self::http_prompt( $messages, $system_prompt, $tools );

		// ── Fallback Path A: If direct HTTP failed and Native was not tried, try Native ──
		if ( null !== ( $result['error'] ?? null ) && ! $try_native && function_exists( 'wp_ai_client_prompt' ) && self::is_native_available() ) {
			self::log_error( 'Direct AI provider failed. Attempting fallback via WordPress AI Connector...' );
			try {
				// The native connector is text-only: prompt with the latest *guest* message,
				// never a trailing tool-result JSON blob from an in-progress tool loop.
				$p_text = self::get_last_user_text( $messages );
				$native_b = \wp_ai_client_prompt( $p_text )
					->using_system_instruction( $system_prompt )
					->using_max_tokens( 2048 )
					->using_temperature( 0.7 );

				$native_out = $native_b->generate_text();
				if ( ! is_wp_error( $native_out ) ) {
					$res                   = self::parse_native_text( (string) $native_out );
					$res['fallback_model'] = 'WordPress AI Connector';
					$res['original_model'] = (string) ( self::get_configured_provider() ?: 'primary' );
					return $res;
				}
				self::log_error( 'WordPress AI Connector fallback error [' . (string) $native_out->get_error_code() . ']: ' . $native_out->get_error_message() );
			} catch ( Throwable $e ) {
				self::log_error( 'Fallback to native AI connector exception: ' . $e->getMessage() );
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
	 * Parse a plain text response from the WP 7.0 native prompt builder
	 * into our standard response array.
	 *
	 * The builder's generate_text() returns a string — no tool calls,
	 * no structured metadata. This is the simplest response path.
	 *
	 * @since 2.4.0
	 * @param string $text
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
	 */
	private static function parse_native_text( string $text ): array {
		return array(
			'content'       => $text,
			'tool_calls'    => array(),
			'finish_reason' => 'stop',
			'error'         => null,
		);
	}

	/**
	 * Parse a response from the standalone wordpress/ai-client SDK (Path 2)
	 * or legacy native client into our standard format.
	 *
	 * @param mixed $response
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
	 */
	private static function parse_native_response( mixed $response ): array {
		if ( is_wp_error( $response ) ) {
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $response->get_error_message(),
			);
		}
		if ( is_array( $response ) ) {
			return array(
				'content'       => (string) ( $response['content'] ?? $response['message']['content'] ?? '' ),
				'tool_calls'    => (array) ( $response['tool_calls'] ?? array() ),
				'finish_reason' => (string) ( $response['finish_reason'] ?? 'stop' ),
				'error'         => null,
			);
		}
		return array(
			'content'       => (string) $response,
			'tool_calls'    => array(),
			'finish_reason' => 'stop',
			'error'         => null,
		);
	}

	/**
	 * Parse a response from the standalone wordpress/ai-client SDK.
	 *
	 * @param mixed $result
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
	 */
	private static function parse_sdk_response( mixed $result ): array {
		if ( is_wp_error( $result ) ) {
			return array(
				'content'       => '',
				'tool_calls'    => array(),
				'finish_reason' => 'error',
				'error'         => $result->get_error_message(),
			);
		}
		return self::parse_native_response( $result );
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
				return self::http_openai( $api_key, $resolved_model, $messages, $system_prompt, $tools, $endpoint );

			case self::PROVIDER_OPENROUTER:
				$resolved_model = $model ?: ModelCatalog::get_recommended_model( self::PROVIDER_OPENROUTER );
				return self::http_openai( $api_key, $resolved_model, $messages, $system_prompt, $tools, self::get_provider_endpoint( self::PROVIDER_OPENROUTER, $resolved_model ) );

			case self::PROVIDER_ANTHROPIC:
				return self::http_anthropic( $api_key, $model ?: ModelCatalog::get_recommended_model( self::PROVIDER_ANTHROPIC ), $messages, $system_prompt, $tools );
 
			case self::PROVIDER_OLLAMA:
				// Ollama is local-only by design; localhost is intentional.
				$base_url = (string) get_option( self::OPT_PREFIX . 'custom_url', 'http://localhost:11434/v1/chat/completions' );
				return self::http_openai( '', $model ?: ModelCatalog::get_recommended_model( self::PROVIDER_OLLAMA ), $messages, $system_prompt, $tools, $base_url );
 
			case self::PROVIDER_CUSTOM:
				$custom_url = (string) get_option( self::OPT_PREFIX . 'custom_url', '' );
				if ( ! $custom_url || ! wp_http_validate_url( $custom_url ) ) {
					return array(
						'content'       => '',
						'tool_calls'    => array(),
						'finish_reason' => 'error',
						'error'         => \MHBO\Core\I18n::get_label( 'ai_error_bad_custom_url' ),
					);
				}
				return self::http_openai( $api_key, $model, $messages, $system_prompt, $tools, $custom_url );
 
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
	 * we cascade through cheaper, more available models before giving up.
	 *
	 * API Version: v1beta is intentionally used (not v1 stable).
	 * Reason: v1beta is the ONLY endpoint that supports system_instruction,
	 * tools/function_declarations, and all Gemini 3.x preview models. Google's
	 * stable v1 endpoint lags behind and does not expose these agentic features.
	 * v1beta itself is NOT deprecated (verified October 2026).
	 *
	 * Verified Fallback Chain (October 2026):
	 *   gemini-3.8-flash → gemini-3.8-pro → gemini-3.7-flash → gemini-3.6-flash
	 *   → gemini-3.5-flash → gemini-3.5-flash-lite → gemini-3.1-flash-lite
	 *   → gemini-3-flash-preview → gemini-3.1-pro-preview
	 *
	 * EOL models removed: gemini-2.5-flash (shut down 2026-10-16),
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
		// Normalize retired model names to stable 2026 equivalents.
		// Retired: gemini-1.5-*, gemini-2.0-*, gemini-2.5-flash, gemini-2.5-flash-lite-preview, gemini-3.1-flash-lite-preview.
		$model = str_replace( '-latest', '', $model ?: 'gemini-3.8-flash' );

		$retired_prefixes = array(
			'gemini-1.5',
			'gemini-2.0',
		);
		$retired_exact    = array(
			'gemini-2.5-flash',              // EOL: 2026-10-16
			'gemini-2.5-pro',                // EOL: 2026-10-16
			'gemini-3.1-flash-lite-preview', // EOL: 2026-05-25
			'gemini-2.5-flash-lite-preview', // EOL: 2026-03-31
			'gemini-1.5-flash',              // EOL: 2026-03-31
			'gemini-1.5-pro',                // EOL: 2026-03-31
			'gemini-3-pro-preview',          // EOL: 2026-03-09
		);

		if ( in_array( $model, $retired_exact, true ) ) {
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

			// Gemini 3 adaptive-thinking models default to dynamic HIGH thinking, which
			// doubled latency on the concierge payload (5.2s vs 2.4s measured). Non-thinking
			// models are left untouched: sending a level would *enable* thinking on them.
			$attempt_body = $body;
			if ( null !== $thinking_level && 'adaptive' === ModelCatalog::get_thinking_mode( self::PROVIDER_GEMINI, $try_model ) ) {
				$attempt_body['generationConfig']['thinkingConfig'] = array( 'thinkingLevel' => $thinking_level );
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
	 * October 2026 Verified Active Models:
	 *   GA       : gemini-3.8-flash — best value, newest frontier
	 *              gemini-3.8-pro — supreme reasoning flagship
	 *              gemini-3.7-flash — high-efficiency workhorse
	 *              gemini-3.6-flash — balanced workhorse
	 *              gemini-3.5-flash-lite — cheapest stable
	 *   PREVIEW  : gemini-3-flash-preview — Computer Use support
	 *              gemini-3.1-pro-preview — flagship preview
	 *
	 * @param string $primary
	 * @return list<string>
	 */
	private static function get_dynamic_fallback_chain( string $primary ): array {
		// Alias map: allow shorthand admin selections to resolve to real model IDs.
		$blanket_map = array(
			'gemini-stable-primary' => 'gemini-3.8-flash',
			'gemini-stable-high'    => 'gemini-3.8-flash',
			'gemini-flash-latest'   => 'gemini-3.8-flash',
			'gemini-pro-latest'     => 'gemini-3.7-flash',
			'gemini-3.8-pro'        => 'gemini-3.7-flash',
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
	 * Return the text of the most recent guest (user-role) message.
	 *
	 * @param array<int|string, mixed> $messages Conversation history.
	 * @return string
	 */
	private static function get_last_user_text( array $messages ): string {
		for ( $i = \count( $messages ) - 1; $i >= 0; $i-- ) {
			$msg = $messages[ $i ] ?? null;
			if ( \is_array( $msg ) && 'user' === ( $msg['role'] ?? '' ) && \is_string( $msg['content'] ?? null ) && '' !== \trim( $msg['content'] ) ) {
				return $msg['content'];
			}
		}
		$last = \end( $messages );
		return \is_array( $last ) ? (string) ( $last['content'] ?? '' ) : '';
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
			if ( isset( $part['text'] ) ) {
				$text          .= (string) $part['text'];
				$text_signature = $part['thought_signature'] ?? $part['thoughtSignature'] ?? $text_signature;
			}

			// FIX: Capture native 'thought' blocks (Reasoning).
			if ( isset( $part['thought'] ) ) {
				$thought .= (string) $part['thought'];
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

				// FIX: Capture thought_signature/thoughtSignature from Part level.
				$signature = $part['thought_signature'] ?? $part['thoughtSignature'] ?? '';
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
	private static function build_gemini_contents( array $messages ): array {
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
					$parts[] = array( 'thought' => (string) $msg['thought'] );
				}
				if ( '' !== (string) ( $msg['content'] ?? '' ) ) {
					$part = array( 'text' => (string) $msg['content'] );
					if ( '' !== (string) ( $msg['thought_signature'] ?? '' ) && array() === ( $msg['tool_calls'] ?? array() ) ) {
						$part['thought_signature'] = $msg['thought_signature'];
						$part['thoughtSignature']  = $msg['thought_signature'];
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

					// FIX: thought_signature belongs to the Part, not the functionCall.
					if ( '' !== (string) ( $tc['thought_signature'] ?? '' ) ) {
						// Send both to be safe, as API vs Documentation differ on case.
						$part['thought_signature'] = $tc['thought_signature'];
						$part['thoughtSignature']  = $tc['thought_signature'];
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
	 * HTTP call to an OpenAI-compatible API (OpenAI, Ollama, custom).
	 *
	 * @param string       $api_key
	 * @param string       $model
	 * @param array<mixed> $messages
	 * @param string       $system_prompt
	 * @param array<mixed> $tools
	 * @param string       $endpoint
	 * @return array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null}
	 */
	private static function http_openai( string $api_key, string $model, array $messages, string $system_prompt, array $tools, string $endpoint ): array {
		// Auto-migrate retired models (August–October 2026 EOL).
		if ( 'gpt-5.4-mini' === $model ) {
			self::log_error( 'Model gpt-5.4-mini retired (August 31, 2026). Auto-migrating to gpt-5.6-luna.' );
			$model = 'gpt-5.6-luna';
		} elseif ( 'gpt-5.4' === $model ) {
			self::log_error( 'Model gpt-5.4 retired (August 31, 2026). Auto-migrating to gpt-5.6-terra.' );
			$model = 'gpt-5.6-terra';
		} elseif ( in_array( $model, array( 'gpt-4o', 'gpt-4o-mini' ), true ) && gmdate( 'Y-m-d' ) >= '2026-10-23' ) {
			self::log_error( "Model {$model} retired (October 23, 2026). Auto-migrating to gpt-5.6-luna." );
			$model    = 'gpt-5.6-luna';
			$endpoint = str_replace( '/v1/chat/completions', '/v1/responses', $endpoint );
		}

		$is_responses_api = str_contains( $endpoint, '/v1/responses' );

		if ( $is_responses_api ) {
			// Modern 2026 'Responses' payload
			$input_text = 'Context: ' . $system_prompt . "\n\n";
			foreach ( $messages as $msg ) {
				$role        = $msg['role'] ?? 'user';
				$val         = $msg['content'] ?? '';
				$input_text .= ucfirst( (string) $role ) . ': ' . (string) $val . "\n";
			}

			// store:false — never persist guest conversations (PII) on OpenAI servers (GDPR).
			$body = array(
				'model' => $model,
				'input' => $input_text,
				'store' => false,
			);
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

		$args     = (array) apply_filters( 'mhbo_ai_http_args', $args, $endpoint, self::PROVIDER_OPENAI );
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

		return self::parse_openai_response( $response, $endpoint, $api_key, $model, $messages, $system_prompt, $tools );
	}

	/**
	 * Parse the response from an OpenAI-compatible endpoint.
	 *
	 * @param array<string, mixed>|\WP_Error   $response
	 * @param array<int, array<string, mixed>> $messages
	 * @param array<int, array<string, mixed>> $tools
	 * @return array{content:string,thought?:string,tool_calls:array<mixed>,usage?:array<string,mixed>,finish_reason:string,error:string|null}
	 */
	private static function parse_openai_response( $response, string $endpoint, string $api_key, string $model, array $messages, string $system_prompt, array $tools ): array {
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
				return self::http_openai( $api_key, $model, $messages, $system_prompt, $tools, $chat_endpoint );
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
				
				// Reasoning/Thought content (similar to Gemini's thought parts)
				if ( 'reasoning' === $type ) {
					$thought .= $part['text'] ?? $part['content'] ?? $part['reasoning_content'] ?? '';
				}

				// Tool calls (if present in modern format)
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
		}
		// Anthropic deprecated Claude 4/Opus 4.x. Current flagship is Claude Opus 5.5.
		if ( 'claude-opus-4-7' === $model || 'claude-opus-4-8' === $model ) {
			self::log_error( "Model {$model} is deprecated. Auto-migrating to claude-opus-5-5." );
			$model = 'claude-opus-5-5';
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => 4096, // 2026 BP: increased for reasoning models
			'system'     => $system_prompt,
			'messages'   => array_map(
				function ( $m ) {
					$out = array(
						'role'    => $m['role'],
						'content' => $m['content'],
					);
					// 2026 BP: Reconstruct thinking blocks for multi-turn tool persistence.
					if ( 'assistant' === $m['role'] && '' !== (string) ( $m['thought'] ?? '' ) ) {
						$out['content'] = array(
							array(
								'type'      => 'thinking',
								'thinking'  => $m['thought'],
								'signature' => $m['thought_signature'] ?? '',
							),
							array(
								'type' => 'text',
								'text' => $m['content'] ?: '',
							),
						);
					}
					return $out;
				},
				$messages 
			),
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
