<?php
declare(strict_types=1);

/**
 * Admin Settings — AI Concierge Tab
 *
 * Adds an "AI Concierge" tab to the existing MHBO settings page.
 *
 * @package   MHBO\Admin
 * @version   2.3.8 (Advanced Agentic 2026 Edition)
 */

namespace MHBO\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\AI\McpServer;
use MHBO\AI\SiteScanner;
use MHBO\AI\Client;
use MHBO\AI\ChatSession;
use MHBO\AI\LlmFile;
use MHBO\AI\ModelCatalog;
use MHBO\Core\Cache;
use MHBO\Core\License;
use MHBO\Core\I18n;
use MHBO\Core\Security;
use WP_REST_Request;
use WP_REST_Response;

class AiSettings {

	private const OPTION_GROUP    = 'mhbo_ai_settings';
	private const NONCE_SAVE      = 'mhbo_ai_save_settings';
	public const SECRET_SENTINEL  = 'REDACTED';

	/**
	 * Preserve an existing secret option when the masked sentinel is submitted,
	 * sanitize and encrypt new key submissions, or clear the option when empty string is posted.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return string Sanitized secret value.
	 */
	public static function sanitize_secret_option( string $option, mixed $value ): string {
		$val = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		if ( self::SECRET_SENTINEL === $val ) {
			return (string) get_option( $option, '' );
		}
		if ( '' === $val ) {
			return '';
		}
		if ( Security::is_encrypted_secret( $val ) ) {
			return $val;
		}
		return Security::encrypt_secret( $val, 'mhbo_ai_salt' );
	}

	/**
	 * Sanitize a geographic coordinate (latitude/longitude) bounded by min..max.
	 */
	public static function sanitize_coordinate( mixed $value, float $min, float $max ): string {
		$raw = is_scalar( $value ) ? trim( str_replace( ',', '.', (string) $value ) ) : '';
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return '';
		}
		$num = (float) $raw;
		if ( $num < $min || $num > $max ) {
			return '';
		}
		return (string) round( $num, 6 );
	}

	/**
	 * Sanitize AI chat widget position ('bottom-right' or 'bottom-left').
	 */
	public static function sanitize_widget_position( mixed $value ): string {
		$pos = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
		return 'bottom-left' === $pos ? 'bottom-left' : 'bottom-right';
	}

	/**
	 * Sanitize AI reasoning effort level.
	 */
	public static function sanitize_reasoning_effort( mixed $value ): string {
		$effort = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
		return in_array( $effort, array( 'none', 'low', 'medium', 'high', 'xhigh' ), true ) ? $effort : 'none';
	}

	/**
	 * Sanitize RAG context budget (1000..10000 characters).
	 */
	public static function sanitize_context_budget( mixed $value ): int {
		return max( 1000, min( 10000, absint( $value ) ) );
	}

	/**
	 * Sanitize Google Hotel Feed lookahead days (30, 60, 90, or 180).
	 */
	public static function sanitize_feed_days( mixed $value ): int {
		$days = absint( $value );
		return in_array( $days, array( 30, 60, 90, 180 ), true ) ? $days : 90;
	}

	/**
	 * Sanitize boolean-like checkbox option as integer 0 or 1.
	 */
	public static function sanitize_bool_int( mixed $value ): int {
		return ! empty( $value ) ? 1 : 0;
	}

	/**
	 * Sanitize an outbound URL option for settings registration.
	 * Loopback URLs are only permitted for Ollama custom endpoint options.
	 */
	public static function sanitize_url_option( string $option, mixed $value ): string {
		$raw       = is_scalar( $value ) ? trim( (string) $value ) : '';
		$clean_url = '' === $raw ? '' : esc_url_raw( $raw, array( 'http', 'https' ) );
		if ( '' === $clean_url ) {
			return '';
		}
		if ( 'mhbo_ai_custom_url' === $option || 'mhbo_ai_fallback_custom_url' === $option ) {
			$provider_opt = ( 'mhbo_ai_fallback_custom_url' === $option ) ? 'mhbo_ai_fallback_provider' : 'mhbo_ai_provider';
			$provider     = (string) get_option( $provider_opt, '' );
			if ( Security::is_safe_url( $clean_url ) || ( 'ollama' === $provider && Security::is_loopback_url( $clean_url ) ) ) {
				return $clean_url;
			}
			return (string) get_option( $option, '' );
		}
		return Security::is_safe_url( $clean_url ) ? $clean_url : (string) get_option( $option, '' );
	}
	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Register hooks. Called from the AI Loader.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( Security::class, 'maybe_migrate_legacy_secrets' ), 5 );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_init', array( self::class, 'maybe_handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
		
	}

	/**
	 * Handle AI Settings form submissions cleanly before HTTP headers are sent (PRG pattern).
	 */
	public static function maybe_handle_save(): void {
		if ( ! is_admin() || ! isset( $_POST['mhbo_ai_save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below via check_admin_referer().
			return;
		}
		if ( ! self::check_rest_permission() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'modern-hotel-booking' ), '', array( 'response' => 403 ) );
		}
		\check_admin_referer( self::NONCE_SAVE, 'mhbo_ai_nonce' );
		self::handle_save();

		if ( ! defined( 'MHBO_TESTING' ) && ! defined( 'DOING_PHPUNIT' ) ) {
			$tab = isset( $_POST['active_tab'] ) ? sanitize_key( wp_unslash( $_POST['active_tab'] ) ) : 'general';
			if ( '' === $tab ) {
				$tab = 'general';
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'mhbo-ai-concierge',
						'tab'  => $tab,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Permission callback for AI admin REST endpoints (Law 55).
	 */
	public static function check_rest_permission(): bool {
		return current_user_can( 'manage_options' ) || ( class_exists( '\MHBO\Core\Capabilities' ) && \MHBO\Core\Capabilities::current_user_can( \MHBO\Core\Capabilities::MANAGE_SETTINGS ) );
	}

	/**
	 * Register REST API routes for AI actions (2026 BP).
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'mhbo/v1',
			'/ai/sync-kb',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_refresh_kb' ),
				'permission_callback' => array( self::class, 'check_rest_permission' ),
			) 
		);
		register_rest_route(
			'mhbo/v1',
			'/ai/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_test_connection' ),
				'permission_callback' => array( self::class, 'check_rest_permission' ),
			) 
		);
		
		register_rest_route(
			'mhbo/v1',
			'/ai/test-connector',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_test_connector' ),
				'permission_callback' => array( self::class, 'check_rest_permission' ),
			) 
		);
		register_rest_route(
			'mhbo/v1',
			'/ai/discover-models',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_discover_models' ),
				'permission_callback' => array( self::class, 'check_rest_permission' ),
			) 
		);
		register_rest_route(
			'mhbo/v1',
			'/ai/mcp-status',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( self::class, 'rest_mcp_status' ),
				'permission_callback' => array( self::class, 'check_rest_permission' ),
			) 
		);
		register_rest_route(
			'mhbo/v1',
			'/ai/sync-discovery',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_sync_discovery' ),
				'permission_callback' => array( self::class, 'check_rest_permission' ),
			) 
		);
		register_rest_route(
			'mhbo/v1',
			'/ai/cleanup-discovery',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_cleanup_discovery' ),
				'permission_callback' => array( self::class, 'check_rest_permission' ),
			) 
		);
		
		register_rest_route(
			'mhbo/v1',
			'/ai/clear-lock',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_clear_lock' ),
				'permission_callback' => array( self::class, 'check_rest_permission' ),
			) 
		);
		register_rest_route(
			'mhbo/v1',
			'/ai/health-status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_health_status' ),
				'permission_callback' => array( self::class, 'check_rest_permission' ),
			) 
		);
	}

	/**
	 * REST Callback: Refresh KB.
	 */
	public static function rest_refresh_kb( WP_REST_Request $request ): WP_REST_Response {
		$kb = SiteScanner::get_or_build( true );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'message'    => __( 'Knowledge base refreshed successfully.', 'modern-hotel-booking' ),
				'char_count' => strlen( $kb ),
			),
			200 
		);
	}

	/**
	 * Build ephemeral option overrides from a diagnostic REST request so admins
	 * can verify unsaved dropdown/input selections before clicking Save.
	 *
	 * @param WP_REST_Request $request      Incoming REST request.
	 * @param bool            $use_fallback Whether probing the secondary fallback connection.
	 * @return array<string, string>
	 */
	private static function extract_probe_overrides( WP_REST_Request $request, bool $use_fallback ): array {
		$prefix    = $use_fallback ? 'mhbo_ai_fallback_' : 'mhbo_ai_';
		$overrides = array();

		$provider_param = $request->get_param( 'provider' );
		$provider       = '';
		if ( is_string( $provider_param ) ) {
			$provider                          = sanitize_key( $provider_param );
			$overrides[ $prefix . 'provider' ] = $provider;
		}

		$model_param = $request->get_param( 'model' );
		if ( is_string( $model_param ) ) {
			$model = sanitize_text_field( trim( $model_param ) );
			if ( '' !== $model && 'custom' !== $model ) {
				$overrides[ $prefix . 'model' ] = $model;
			}
		}

		$key_param = $request->get_param( 'api_key' );
		if ( is_string( $key_param ) ) {
			$raw_key        = sanitize_text_field( trim( $key_param ) );
			$saved_provider = (string) get_option( $prefix . 'provider', '' );
			if ( '' === $raw_key || self::SECRET_SENTINEL === $raw_key ) {
				if ( '' !== $provider && $provider !== $saved_provider ) {
					$overrides[ $prefix . 'api_key' ] = '';
				}
			} else {
				$overrides[ $prefix . 'api_key' ] = $raw_key;
			}
		}

		$url_param = $request->get_param( 'custom_url' );
		if ( is_string( $url_param ) ) {
			$clean_url        = '' === trim( $url_param ) ? '' : esc_url_raw( trim( $url_param ), array( 'http', 'https' ) );
			$active_provider  = '' !== $provider ? $provider : (string) get_option( $prefix . 'provider', '' );
			$allow_loopback   = ( 'ollama' === $active_provider ) && Security::is_loopback_url( $clean_url );
			if ( '' === $clean_url || Security::is_safe_url( $clean_url ) || $allow_loopback ) {
				$overrides[ $prefix . 'custom_url' ] = $clean_url;
			}
		}

		if ( ! $use_fallback ) {
			$effort_param = $request->get_param( 'reasoning_effort' );
			if ( is_string( $effort_param ) ) {
				$effort = sanitize_key( $effort_param );
				if ( in_array( $effort, array( 'none', 'low', 'medium', 'high', 'xhigh' ), true ) ) {
					$overrides['mhbo_ai_reasoning_effort'] = $effort;
				}
			}
		}

		return $overrides;
	}

	/**
	 * Execute a bounded primary or fallback connection diagnostic probe within FastCGI ceilings.
	 *
	 * @param bool                  $use_fallback Whether to invoke Client::fallback_prompt() instead of Client::prompt().
	 * @param string                $prompt_word  Expected sentinel word in the test response.
	 * @param array<string, string> $overrides    Optional ephemeral option overrides for unsaved form state.
	 * @return array{response:array{content:string,tool_calls:array<mixed>,finish_reason:string,error:string|null,fallback_model?:string,original_model?:string,model?:string,thought?:string,thought_signature?:string,raw_parts?:array<mixed>,usage?:array<string,mixed>},latency_ms:int}
	 */
	private static function run_diagnostic_probe( bool $use_fallback, string $prompt_word, array $overrides = array() ): array {
		$start        = microtime( true );
		$diag_timeout = 7;
		$short_http   = static function ( array $args ) use ( &$diag_timeout ): array {
			$rem             = Client::remaining_time();
			$cap             = min( (float) $diag_timeout, max( 3.0, \PHP_FLOAT_MAX === $rem ? (float) $diag_timeout : $rem - 1.0 ) );
			$args['timeout'] = isset( $args['timeout'] ) ? min( (float) $args['timeout'], $cap ) : $cap;
			return $args;
		};
		$short_ai     = static function () use ( &$diag_timeout ): float {
			$rem = Client::remaining_time();
			return min( (float) $diag_timeout, max( 3.0, \PHP_FLOAT_MAX === $rem ? (float) $diag_timeout : $rem - 1.0 ) );
		};

		$model_key    = $use_fallback ? 'mhbo_ai_fallback_model' : 'mhbo_ai_model';
		$tested_model = $overrides[ $model_key ] ?? (string) get_option( $model_key, '' );
		if ( '' !== $tested_model && ! in_array( $tested_model, array( 'auto', 'custom' ), true ) ) {
			Client::clear_model_failure( $tested_model );
		}

		if ( array() !== $overrides ) {
			Client::set_diagnostic_overrides( $overrides );
		}

		add_filter( 'http_request_args', $short_http, 9999 );
		add_filter( 'wp_ai_client_default_request_timeout', $short_ai, 9999 );
		Client::set_deadline( 20.0 );

		try {
			$messages = array(
				array(
					'role'    => 'user',
					'content' => 'Reply with only the word: ' . $prompt_word,
				),
			);
			$system   = 'You are a connection test bot. Reply with exactly one word: ' . $prompt_word . '.';
			$response = $use_fallback
				? Client::fallback_prompt( $messages, $system, array() )
				: Client::prompt( $messages, $system, array(), false );
		} finally {
			Client::set_diagnostic_overrides( array() );
			Client::set_deadline( 0.0 );
			remove_filter( 'http_request_args', $short_http, 9999 );
			remove_filter( 'wp_ai_client_default_request_timeout', $short_ai, 9999 );
		}

		return array(
			'response'   => $response,
			'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	}

	private static function verify_same_origin_probe( WP_REST_Request $request ): bool {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) {
			return true;
		}
		$orig_parts = wp_parse_url( $origin );
		$home_parts = wp_parse_url( home_url() );
		if ( ! is_array( $orig_parts ) || ! is_array( $home_parts ) ) {
			return false;
		}
		return strtolower( (string) ( $orig_parts['host'] ?? '' ) ) === strtolower( (string) ( $home_parts['host'] ?? '' ) )
			&& strtolower( (string) ( $orig_parts['scheme'] ?? '' ) ) === strtolower( (string) ( $home_parts['scheme'] ?? '' ) );
	}

	private static function redact_probe_error( string $error ): string {
		return (string) preg_replace( '/(AIza[0-9A-Za-z\-_]{10,}|sk-[a-zA-Z0-9\-_]{10,}|Bearer\s+\S+)/i', '[REDACTED]', $error );
	}

	/**
	 * REST Callback: Test AI Connection.
	 */
	public static function rest_test_connection( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::verify_same_origin_probe( $request ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Cross-origin diagnostic request rejected.', 'modern-hotel-booking' ) ), 403 );
		}
		$overrides = self::extract_probe_overrides( $request, false );
		$probe     = self::run_diagnostic_probe( false, 'CONNECTED', $overrides );
		$response  = $probe['response'];
		$latency   = $probe['latency_ms'];

		if ( $response['error'] ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'message'    => self::redact_probe_error( (string) $response['error'] ),
					'latency_ms' => $latency,
				),
				400 
			);
		}

		$message = __( 'Connection successful!', 'modern-hotel-booking' );
		if ( ! empty( $response['fallback_model'] ) && ! empty( $response['original_model'] ) ) {
			$message = sprintf(
				/* translators: 1: original requested model, 2: actual fallback model used */
				__( 'Connection successful! Note: Primary model "%1$s" is temporarily experiencing high demand/limits on Google, but verified operational via "%2$s". Live chats automatically cascade across models to ensure 100%% uptime.', 'modern-hotel-booking' ),
				(string) $response['original_model'],
				(string) $response['fallback_model']
			);
		}

		return new WP_REST_Response(
			array(
				'success'    => true,
				'message'    => $message,
				'response'   => $response['content'],
				'latency_ms' => $latency,
			),
			200 
		);
	}

/**
	 * REST Callback: Test WordPress AI Connector.
	 */
	public static function rest_test_connector( WP_REST_Request $request ): WP_REST_Response {
		$result = Client::test_native_connector();

		return new WP_REST_Response(
			$result,
			$result['success'] ? 200 : 400
		);
	}

	/**
	 * REST Callback: Discover Live Models from provider.
	 */
	public static function rest_discover_models( WP_REST_Request $request ): WP_REST_Response {
		$provider       = sanitize_key( (string) $request->get_param( 'provider' ) );
		$api_key        = sanitize_text_field( (string) $request->get_param( 'api_key' ) );
		$custom_url_raw = (string) ( $request->get_param( 'custom_url' ) ?? '' );
		$custom_url     = '' === trim( $custom_url_raw ) ? '' : esc_url_raw( trim( $custom_url_raw ), array( 'http', 'https' ) );
		$saved_provider = (string) get_option( 'mhbo_ai_provider', 'gemini' );

		if ( ! $provider ) {
			$provider = $saved_provider;
		}
		if ( '' === $api_key || self::SECRET_SENTINEL === $api_key ) {
			$raw_stored = ( $provider === $saved_provider ) ? (string) get_option( 'mhbo_ai_api_key', '' ) : '';
			$api_key    = '' !== $raw_stored ? Security::decrypt_ai_secret( $raw_stored ) : '';
		}

		$discovered = ModelCatalog::discover_live_models( $provider, $api_key, $custom_url );
		$presets    = ModelCatalog::get_presets( $provider );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'provider'   => $provider,
				'presets'    => $presets,
				'discovered' => $discovered,
			),
			200
		);
	}

	/**
	 * REST Callback: Get MCP server and registered abilities status (Law 62).
	 */
	public static function rest_mcp_status( WP_REST_Request $request ): WP_REST_Response {
		$status = class_exists( '\MHBO\AI\McpServer' ) ? McpServer::get_status() : array(
			'active'            => false,
			'adapter_installed' => false,
			'mcp_enabled'       => false,
			'abilities_count'   => 0,
			'guest_tools_count' => 0,
			'endpoint'          => '',
			'openapi_url'       => '',
			'mode'              => '',
			'tier'              => 'free',
		);

		if ( ! $status['mcp_enabled'] ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'MCP Server gateway is currently disabled. Toggle the switch above and save settings to activate the endpoint.', 'modern-hotel-booking' ),
					'status'  => $status,
				),
				200
			);
		}

		$mode_name = ! empty( $status['mode'] ) ? (string) $status['mode'] : __( 'Hybrid Native Streamable HTTP Engine', 'modern-hotel-booking' );

		return new WP_REST_Response(
			array(
				'success' => true,
				/* translators: 1: mode, 2: number of abilities, 3: endpoint URL */
				'message' => sprintf( __( 'MCP Server active (%1$s)! %2$d hotel abilities exposed via %3$s', 'modern-hotel-booking' ), $mode_name, (int) $status['abilities_count'], (string) $status['endpoint'] ),
				'status'  => $status,
			),
			200
		);
	}

	/**
	 * REST Callback: Sync Discovery.
	 */
	public static function rest_sync_discovery( WP_REST_Request $request ): WP_REST_Response {
		$result = class_exists( '\MHBO\AI\LlmFile' ) ? LlmFile::sync() : array(
			'success' => false,
			'message' => 'Pro required.',
		);
		if ( $result['success'] ) {
			return new WP_REST_Response( $result, 200 );
		}
		return new WP_REST_Response( $result, 400 );
	}

	/**
	 * REST Callback: Cleanup Discovery.
	 */
	public static function rest_cleanup_discovery( WP_REST_Request $request ): WP_REST_Response {
		if ( class_exists( '\MHBO\AI\LlmFile' ) ) {
			LlmFile::cleanup();
		}
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Discovery files removed.', 'modern-hotel-booking' ),
			),
			200 
		);
	}

/**
	 * REST Callback: Read AI Resilience & Error Score Health Status.
	 */
	public static function rest_health_status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response(
			array_merge(
				array( 'success' => true ),
				Client::get_resilience_status()
			),
			200
		);
	}

	/**
	 * REST Callback: Clear Lock.
	 */
	public static function rest_clear_lock( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		Client::reset_failure_score();
		Client::flush_native_availability();

		foreach ( ModelCatalog::get_all_seed_model_ids() as $m ) {
			\delete_transient( "mhbo_ai_model_fail_{$m}" );
		}
		Client::clear_model_failure( (string) get_option( 'mhbo_ai_model', '' ) );
		Client::clear_model_failure( (string) get_option( 'mhbo_ai_fallback_model', '' ) );

		return new WP_REST_Response(
			array_merge(
				array(
					'success' => true,
					'message' => \__( 'AI Resilience Engine reset. All failure scores and cooldowns cleared.', 'modern-hotel-booking' ),
				),
				Client::get_resilience_status()
			),
			200
		);
	}

	/**
	 * Register WordPress settings API options.
	 */
	public static function register_settings(): void {
		$opts = array(
			'mhbo_ai_provider',
			'mhbo_ai_api_key',
			'mhbo_ai_model',
			'mhbo_ai_custom_url',
			'mhbo_ai_persona_name',
			'mhbo_ai_welcome_message',
			'mhbo_ai_enabled',
			'mhbo_ai_show_globally',
			'mhbo_ai_widget_enabled',
			'mhbo_ai_widget_position',
			'mhbo_ai_accent_color',
			'mhbo_ai_theme',
			'mhbo_ai_mcp_enabled',
			'mhbo_ai_mcp_hybrid_bridge',
			'mhbo_ai_voice_input_enabled',
			'mhbo_ai_voice_output_enabled',
			'mhbo_ai_voice_language',  // empty string = follow auto-detected locale
			'mhbo_ai_elevenlabs_key',
			'mhbo_ai_proactive_trigger_seconds',
			
			'mhbo_ai_discovery_auto_sync',
			'mhbo_ai_sitemap_url',
			'mhbo_ai_streaming_enabled',
			'mhbo_ai_reasoning_effort',
			'mhbo_ai_context_budget',
		);
		$url_opts = array(
			'mhbo_ai_custom_url',
			'mhbo_ai_fallback_custom_url',
			'mhbo_ai_sitemap_url',
		);
		$bool_opts = array(
			'mhbo_ai_enabled',
			'mhbo_ai_show_globally',
			'mhbo_ai_widget_enabled',
			'mhbo_ai_mcp_enabled',
			'mhbo_ai_mcp_hybrid_bridge',
			'mhbo_ai_voice_input_enabled',
			'mhbo_ai_voice_output_enabled',
			'mhbo_ai_discovery_auto_sync',
			'mhbo_ai_streaming_enabled',
		);
		$key_opts = array(
			'mhbo_ai_provider',
			'mhbo_ai_fallback_provider',
			'mhbo_ai_theme',
		);
		$secret_opts = array(
			'mhbo_ai_api_key',
			'mhbo_ai_elevenlabs_key',
			'mhbo_ai_fallback_api_key',
		);
		foreach ( $opts as $opt ) {
			$type = 'string';
			if ( in_array( $opt, $secret_opts, true ) ) {
				$callback = static fn( mixed $val ): string => self::sanitize_secret_option( $opt, $val );
			} elseif ( in_array( $opt, $url_opts, true ) ) {
				$callback = static fn( mixed $val ): string => self::sanitize_url_option( $opt, $val );
			} elseif ( in_array( $opt, $bool_opts, true ) ) {
				$type     = 'integer';
				$callback = array( self::class, 'sanitize_bool_int' );
			} elseif ( 'mhbo_ai_proactive_trigger_seconds' === $opt ) {
				$type     = 'integer';
				$callback = 'absint';
			} elseif ( 'mhbo_ai_context_budget' === $opt ) {
				$type     = 'integer';
				$callback = array( self::class, 'sanitize_context_budget' );
			} elseif ( 'mhbo_ai_accent_color' === $opt ) {
				$callback = static function ( mixed $val ): string {
					$hex = sanitize_hex_color( is_scalar( $val ) ? (string) $val : '' );
					return is_string( $hex ) && '' !== $hex ? $hex : '#2C3E50';
				};
			} elseif ( 'mhbo_ai_widget_position' === $opt ) {
				$callback = array( self::class, 'sanitize_widget_position' );
			} elseif ( 'mhbo_ai_reasoning_effort' === $opt ) {
				$callback = array( self::class, 'sanitize_reasoning_effort' );
			} elseif ( in_array( $opt, $key_opts, true ) ) {
				$callback = 'sanitize_key';
			} else {
				$callback = 'sanitize_text_field';
			}
			register_setting(
				self::OPTION_GROUP,
				$opt,
				array(
					'type'              => $type,
					'sanitize_callback' => $callback,
				)
			);
		}
		register_setting( self::OPTION_GROUP, 'mhbo_ai_custom_instructions', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( self::OPTION_GROUP, 'mhbo_ai_local_guide', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ) );
		
	}

	/**
	 * Enqueue admin JS/CSS only on the MHBO settings page, AI tab.
	 *
	 * @param mixed $hook Admin page hook suffix.
	 */
	public static function enqueue_assets( mixed $hook = '' ): void {
		$hook = is_string( $hook ) ? $hook : '';
		if ( strpos( $hook, 'mhbo-ai-concierge' ) === false && strpos( $hook, 'mhbo-settings' ) === false ) {
			return;
		}
		wp_enqueue_script( 'wp-api-fetch' );
		wp_add_inline_script( 'wp-api-fetch', self::get_inline_js(), 'after' );
	}

	// -------------------------------------------------------------------------
	// Tab Renderer
	// -------------------------------------------------------------------------

	/**
	 * Helper to get the current settings tab.
	 */
	private static function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		return '' !== $tab ? $tab : 'general';
	}

	/**
	 * Render the full AI Concierge settings tab.
	 * Called from the main Settings class when tab === 'ai_concierge'.
	 */
	public static function render_tab(): void {
		if ( ! self::check_rest_permission() ) {
			return;
		}

		$provider        = (string) get_option( 'mhbo_ai_provider', 'gemini' );
		$api_key         = (string) get_option( 'mhbo_ai_api_key', '' );
		$api_key_masked  = '' !== $api_key ? self::SECRET_SENTINEL : '';
		$model           = (string) get_option( 'mhbo_ai_model', 'gemini-3.8-flash' );
		$reasoning_effort = 'none';
		
		$custom_url        = (string) get_option( 'mhbo_ai_custom_url', '' );
		$persona_name      = (string) get_option( 'mhbo_ai_persona_name', __( 'AI Concierge', 'modern-hotel-booking' ) );
		$custom_instr      = (string) get_option( 'mhbo_ai_custom_instructions', '' );
		$welcome_msg       = (string) get_option( 'mhbo_ai_welcome_message', '' );
		$ai_enabled        = (int) get_option( 'mhbo_ai_enabled', 0 );
		$show_globally     = (int) get_option( 'mhbo_ai_show_globally', 0 );
		$widget_enabled    = (int) get_option( 'mhbo_ai_widget_enabled', 0 );
		$position          = (string) get_option( 'mhbo_ai_widget_position', 'bottom-right' );
		$accent_color      = (string) get_option( 'mhbo_ai_accent_color', '#2C3E50' );
		$mcp_enabled       = (int) get_option( 'mhbo_ai_mcp_enabled', 0 );
		$voice_input       = (int) get_option( 'mhbo_ai_voice_input_enabled', 1 );
		$voice_output      = (int) get_option( 'mhbo_ai_voice_output_enabled', 0 );
		$voice_lang        = (string) get_option( 'mhbo_ai_voice_language', '' ); // empty = auto
		$elevenlabs        = (string) get_option( 'mhbo_ai_elevenlabs_key', '' );
		$elevenlabs_masked = '' !== $elevenlabs ? self::SECRET_SENTINEL : '';
		$proactive_trigger = (int) get_option( 'mhbo_ai_proactive_trigger_seconds', 0 );

$discovery_sync = (string) get_option( 'mhbo_ai_discovery_last_sync', '' );
		$discovery_auto = (int) get_option( 'mhbo_ai_discovery_auto_sync', 0 );
		$mcp_enabled    = (int) get_option( 'mhbo_ai_mcp_enabled', 0 );

		// Language detection — runs on every page load so the badge is live.
		$multilang_status  = self::get_multilang_status();
		$detected_locale   = $multilang_status['locale'];
		$lang_source_label = $multilang_status['label'];

		$is_pro = class_exists( '\MHBO\Core\License' ) && false;

		// KB info.
		$kb_snapshot_time = (string) get_option( 'mhbo_kb_snapshot_updated', '' );
		$current_tab      = self::get_current_tab();

		$tabs = array(
			'general'    => __( 'General', 'modern-hotel-booking' ),
			'persona'    => __( 'Assistant Persona', 'modern-hotel-booking' ),
			'appearance' => __( 'Appearance', 'modern-hotel-booking' ),
			'discovery'  => __( 'AI Discovery & MCP', 'modern-hotel-booking' ),
			
		);

		?>
		<div class="wrap mhbo-admin-wrap mhbo-animate-in">
			<?php
			AdminUI::render_header(
				__( 'AI Concierge', 'modern-hotel-booking' ),
				__( 'Manage your AI guest assistant, knowledge base, and voice integrations.', 'modern-hotel-booking' ),
				array(),
				array(
					array(
						'label' => I18n::get_label( 'menu_main' ),
						'url'   => admin_url( 'admin.php?page=mhbo-hotel-booking' ),
					),
					array(
						'label' => __( 'Settings', 'modern-hotel-booking' ),
						'url'   => admin_url( 'admin.php?page=mhbo-settings' ),
					),
				)
			);

			AdminUI::render_tabs(
				$tabs,
				$current_tab,
				admin_url( 'admin.php?page=mhbo-ai-concierge' ),
				array(
					'general'    => 'dashicons-admin-generic',
					'persona'    => 'dashicons-admin-users',
					'appearance' => 'dashicons-format-image',
					'discovery'  => 'dashicons-cloud',
					
				) 
			);

			$uid            = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			$notice_key     = 'mhbo_ai_notices_' . $uid;
			$stored_notices = get_transient( $notice_key );
			if ( is_array( $stored_notices ) ) {
				delete_transient( $notice_key );
				foreach ( $stored_notices as $notice ) {
					if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
						$n_type = in_array( (string) ( $notice['type'] ?? 'success' ), array( 'success', 'warning', 'error', 'info' ), true )
							? (string) $notice['type']
							: 'success';
						echo '<div class="notice notice-' . esc_attr( $n_type ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
					}
				}
			}

			$is_effective_pro = false;
			
			?>

			<form method="post" action="" style="max-width: 1200px;">
				<?php wp_nonce_field( self::NONCE_SAVE, 'mhbo_ai_nonce' ); ?>
				<input type="hidden" name="mhbo_ai_save" value="1">
				<input type="hidden" name="active_tab" value="<?php echo esc_attr( $current_tab ); ?>">
				<input type="text" name="username" autocomplete="username" style="display:none;" aria-hidden="true" tabindex="-1">

				<div class="mhbo-settings-card-container" style="max-width: 1200px;">
					
					<?php if ( $current_tab === 'general' ) : ?>
						<!-- ── System Status & Engine ────────────────────────────────────────── -->
						<div class="mhbo-settings-section-card">
							<div class="mhbo-settings-section-header">
								<div class="mhbo-settings-section-title-wrap">
									<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-shield"></span></div>
									<div>
										<h3 class="mhbo-settings-section-title"><?php esc_html_e( 'System Status & Service Resilience', 'modern-hotel-booking' ); ?></h3>
										<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Monitor AI service status, real-time streaming, knowledge snapshot freshness, and operational health.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>
							</div>

							<div class="mhbo-settings-grid mhbo-grid-2col">
								<div class="mhbo-form-group mhbo-span-full">
									<div class="mhbo-switch-row" style="background: <?php echo $ai_enabled ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $ai_enabled ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 8px; padding: 14px 16px;">
										<div class="mhbo-switch-info">
											<div style="display: flex; align-items: center; gap: 8px;">
												<span style="font-size: 16px;"><?php echo $ai_enabled ? '🟢' : '🔴'; ?></span>
												<span class="mhbo-switch-title" style="font-size: 15px; font-weight: 700; color: #0f172a;"><?php esc_html_e( 'AI Service Engine (Master Switch)', 'modern-hotel-booking' ); ?></span>
												<span class="mhbo-rate-badge" style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; <?php echo $ai_enabled ? 'background: #dcfce7; color: #166534;' : 'background: #fee2e2; color: #991b1b;'; ?>">
													<?php echo $ai_enabled ? esc_html__( 'Active', 'modern-hotel-booking' ) : esc_html__( 'Offline', 'modern-hotel-booking' ); ?>
												</span>
											</div>
											<p class="mhbo-switch-desc" style="margin-top: 4px;"><?php echo $ai_enabled ? esc_html__( 'All AI subsystems, conversational booking tools, and API connectors are active and ready.', 'modern-hotel-booking' ) : esc_html__( 'AI is currently offline. No background syncs or external AI API calls will be made.', 'modern-hotel-booking' ); ?></p>
										</div>
										<label class="mhbo-toggle-switch" for="ai_master_switch">
											<input type="hidden" name="mhbo_ai_enabled" value="0">
											<input type="checkbox" name="mhbo_ai_enabled" value="1" <?php checked( $ai_enabled, 1 ); ?> id="ai_master_switch">
											<span class="mhbo-toggle-slider"></span>
										</label>
									</div>
								</div>

								<div class="mhbo-form-group mhbo-span-full">
									<div class="mhbo-switch-row">
										<div class="mhbo-switch-info">
											<span class="mhbo-switch-title"><?php esc_html_e( 'Real-Time Streaming Response', 'modern-hotel-booking' ); ?></span>
											<p class="mhbo-switch-desc"><?php esc_html_e( 'Show live AI thinking and tool-progress indicators via Server-Sent Events (SSE) with smooth real-time typing animations.', 'modern-hotel-booking' ); ?></p>
										</div>
										<label class="mhbo-toggle-switch">
											<input type="hidden" name="mhbo_ai_streaming_enabled" value="0">
											<input type="checkbox" name="mhbo_ai_streaming_enabled" value="1" <?php checked( (int) get_option( 'mhbo_ai_streaming_enabled', 0 ), 1 ); ?>>
											<span class="mhbo-toggle-slider"></span>
										</label>
									</div>
								</div>

								<div class="mhbo-form-group" style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
									<label class="mhbo-form-label" style="text-transform: uppercase; font-size: 11px; letter-spacing: 0.05em; color: #64748b;"><?php esc_html_e( 'Knowledge Base Snapshot', 'modern-hotel-booking' ); ?></label>
									<div style="margin: 8px 0 12px; font-size: 13.5px; font-weight: 600;">
										<?php 
										if ( $kb_snapshot_time ) : 
											$snapshot_ts  = (int) ( strtotime( $kb_snapshot_time ) ?: time() );
											$days_ago     = (int) ( ( time() - $snapshot_ts ) / 86400 );
											$status_color = $days_ago > 30 ? '#ef4444' : '#16a34a';
											$status_icon  = $days_ago > 30 ? '⚠️' : '✅';
											?>
											<span style="color: <?php echo esc_attr( $status_color ); ?>;">
												<?php echo esc_html( $status_icon ); ?> 
												<?php 
												// translators: %s: last update date and time
												printf( esc_html__( 'Last synced: %s', 'modern-hotel-booking' ), esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $snapshot_ts ) ) );
												?>
											</span>
											<?php if ( $days_ago > 30 ) : ?>
												<p style="color: #64748b; font-size: 12px; font-weight: 400; margin: 4px 0 0;"><?php esc_html_e( 'Snapshot is over 30 days old. Refresh recommended.', 'modern-hotel-booking' ); ?></p>
											<?php endif; ?>
										<?php else : ?>
											<span style="color: #f59e0b;">⚠️ <?php esc_html_e( 'Not indexed yet.', 'modern-hotel-booking' ); ?></span>
										<?php endif; ?>
									</div>
									<div style="display: flex; align-items: center; gap: 10px;">
										<button type="button" id="mhbo_ai_kb_refresh" class="button button-secondary">
											<span class="dashicons dashicons-update" aria-hidden="true"></span>
											<span><?php esc_html_e( 'Sync KB Now', 'modern-hotel-booking' ); ?></span>
										</button>
										<span id="mhbo_ai_kb_result" style="font-size: 12px;"></span>
									</div>
								</div>

								<div class="mhbo-form-group" style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
									<?php
									$resilience = Client::get_resilience_status();
									$err_score  = $resilience['error_score'];
									$threshold  = $resilience['threshold'];
									$is_cooling = $resilience['is_cooling'];
									$remaining  = $resilience['remaining_seconds'];

									$status_label = \__( 'Operational', 'modern-hotel-booking' );
									$status_color = '#16a34a';
									$status_icon  = '✅';

									if ( $is_cooling ) {
										$status_label = \__( 'Cooling Down (Demand Spike)', 'modern-hotel-booking' );
										$status_color = '#ef4444';
										$status_icon  = '❄️';
									} elseif ( $err_score > 0 ) {
										$status_label = \__( 'High Demand (Degraded)', 'modern-hotel-booking' );
										$status_color = '#f59e0b';
										$status_icon  = '⚠️';
									}
									?>
									<label class="mhbo-form-label" style="text-transform: uppercase; font-size: 11px; letter-spacing: 0.05em; color: #64748b;"><?php esc_html_e( 'Service Health & Quota Resilience', 'modern-hotel-booking' ); ?></label>
									<div style="display: flex; justify-content: space-between; align-items: center; margin: 8px 0 12px;">
										<span id="mhbo_ai_health_status_text" style="font-size: 13.5px; font-weight: 600; color: <?php echo esc_attr( $status_color ); ?>;">
											<?php echo esc_html( $status_icon . ' ' . $status_label ); ?>
											<small id="mhbo_ai_cooldown_countdown" data-remaining="<?php echo (int) $remaining; ?>" style="color: #64748b; font-weight: 400; display: <?php echo $is_cooling ? 'block' : 'none'; ?>; margin-top: 2px;">
												<?php
												if ( $is_cooling ) {
													// translators: %1$d: seconds remaining before AI concierge resumes
													printf( esc_html__( 'Resuming in %1$d seconds...', 'modern-hotel-booking' ), (int) $remaining );
												}
												?>
											</small>
										</span>
										<span id="mhbo_ai_error_score_badge" class="mhbo-rate-badge" title="<?php echo esc_attr__( 'Circuit breaker error score: 0 = healthy, 1 = degraded (retry allowed), 2 = 30s cooldown, 3+ = 15m circuit breaker.', 'modern-hotel-booking' ); ?>" style="font-size: 11.5px; font-weight: 700; background: #e2e8f0; color: #334155; padding: 2px 8px; border-radius: 4px;">
											<?php esc_html_e( 'Error Score:', 'modern-hotel-booking' ); ?> <?php echo (int) $err_score; ?> / <?php echo (int) $threshold; ?>
										</span>
									</div>
									<div style="display: flex; align-items: center; gap: 10px;">
										<button type="button" id="mhbo_ai_clear_lock" class="button button-secondary">
											<?php esc_html_e( 'Reset Engine Lock', 'modern-hotel-booking' ); ?>
										</button>
										<p class="mhbo-form-help" style="margin: 0;"><?php esc_html_e( 'Manually clear demand cooldown.', 'modern-hotel-booking' ); ?></p>
									</div>
									<span id="mhbo_ai_clear_lock_result" style="display: block; margin-top: 6px; font-size: 12px;"></span>
								</div>
							</div>
						</div>

						<!-- ── Primary AI Connection ─────────────────────────────────── -->
						<div class="mhbo-settings-section-card">
							<div class="mhbo-settings-section-header">
								<div class="mhbo-settings-section-title-wrap">
									<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-rest-api"></span></div>
									<div>
										<h3 class="mhbo-settings-section-title"><?php esc_html_e( 'Primary AI Provider & Connection', 'modern-hotel-booking' ); ?></h3>
										<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Configure your primary LLM provider, API credentials, and default model intelligence.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>
							</div>

							<?php if ( function_exists( 'wp_ai_client_prompt' ) ) : ?>
								<?php
								$native_ids        = Client::get_configured_native_provider_ids();
								$has_native_conn   = array() !== $native_ids;
								$banner_bg         = $has_native_conn ? '#f0fdf4' : '#fffbeb';
								$banner_border     = $has_native_conn ? '#16a34a' : '#f59e0b';
								$banner_title_col  = $has_native_conn ? '#166534' : '#92400e';
								$banner_body_col   = $has_native_conn ? '#14532d' : '#78350f';
								$native_labels_str = implode( ', ', array_map( 'ucfirst', $native_ids ) );
								?>
								<div style="background: <?php echo esc_attr( $banner_bg ); ?>; border-left: 4px solid <?php echo esc_attr( $banner_border ); ?>; padding: 14px 18px; border-radius: 6px; margin-bottom: 20px;">
									<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
										<div>
											<strong style="color: <?php echo esc_attr( $banner_title_col ); ?>; font-size: 13.5px;">
												<?php echo $has_native_conn ? '✅' : '🔌'; ?>
												<?php esc_html_e( 'WordPress 7.0+ AI Client & Connectors', 'modern-hotel-booking' ); ?>
												<?php if ( $has_native_conn ) : ?>
													<span style="font-weight: 500; font-size: 12px;">(<?php echo esc_html( $native_labels_str ); ?>)</span>
												<?php endif; ?>
											</strong>
											<p style="margin: 4px 0 0; color: <?php echo esc_attr( $banner_body_col ); ?>; font-size: 12.5px;">
												<?php if ( $has_native_conn ) : ?>
													<?php
													printf(
														/* translators: 1: configured provider list, 2: Settings -> Connectors URL, 3: AI Approvals URL */
														wp_kses_post( __( 'Connected site-wide providers: <strong>%1$s</strong>. Manage keys in <a href="%2$s">Settings &rarr; Connectors</a> &middot; <a href="%3$s">AI Approvals</a>.', 'modern-hotel-booking' ) ),
														esc_html( $native_labels_str ),
														esc_url( admin_url( 'options-connectors.php' ) ),
														esc_url( admin_url( 'tools.php?page=ai-connector-approval' ) )
													);
													?>
												<?php else : ?>
													<?php
													printf(
														/* translators: %s: Settings -> Connectors URL */
														wp_kses_post( __( 'No site-wide AI Connector keys detected yet. Add keys in <a href="%s">Settings &rarr; Connectors</a> or configure a direct API provider below.', 'modern-hotel-booking' ) ),
														esc_url( admin_url( 'options-connectors.php' ) )
													);
													?>
												<?php endif; ?>
											</p>
										</div>
										<button type="button" id="mhbo_ai_test_connector_btn" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
											<span class="dashicons dashicons-rest-api" style="margin-top:2px;" aria-hidden="true"></span>
											<span><?php esc_html_e( 'Test WordPress AI Connector', 'modern-hotel-booking' ); ?></span>
										</button>
									</div>
									<div id="mhbo_ai_test_connector_result" style="margin-top: 8px; font-weight: 600; font-size: 13px;"></div>
								</div>
							<?php endif; ?>

							<div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px;">
								<strong style="color: #92400e; font-size: 13px;">💡 <?php esc_html_e( 'Cost Resilience & Auto-Fallback', 'modern-hotel-booking' ); ?></strong>
								<p style="margin: 4px 0 0; color: #78350f; font-size: 12px; line-height: 1.5;"><?php esc_html_e( 'To guarantee 100% uptime for guests, the system automatically falls back to secondary models if your chosen provider experiences rate limiting or downtime.', 'modern-hotel-booking' ); ?></p>
							</div>

							<div class="mhbo-settings-grid mhbo-grid-2col">
								<div class="mhbo-form-group">
									<label class="mhbo-form-label" for="mhbo_ai_provider"><?php esc_html_e( 'Primary AI Provider', 'modern-hotel-booking' ); ?></label>
									<select name="mhbo_ai_provider" id="mhbo_ai_provider" class="mhbo-form-input">
										<option value=""><?php esc_html_e( '— Select Provider —', 'modern-hotel-booking' ); ?></option>
										<option value="wp_connector" <?php selected( $provider, 'wp_connector' ); ?>><?php esc_html_e( 'WordPress AI Connector (Core / Site-Wide)', 'modern-hotel-booking' ); ?></option>
										<option value="gemini"    <?php selected( $provider, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'modern-hotel-booking' ); ?></option>
										<option value="openai"    <?php selected( $provider, 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'modern-hotel-booking' ); ?></option>
										<option value="openrouter" <?php selected( $provider, 'openrouter' ); ?>><?php esc_html_e( 'OpenRouter.ai', 'modern-hotel-booking' ); ?></option>
										<option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude)', 'modern-hotel-booking' ); ?></option>
										<option value="ollama"    <?php selected( $provider, 'ollama' ); ?>><?php esc_html_e( 'Ollama (Local Host)', 'modern-hotel-booking' ); ?></option>
										<option value="custom"    <?php selected( $provider, 'custom' ); ?>><?php esc_html_e( 'Custom (OpenAI-compatible)', 'modern-hotel-booking' ); ?></option>
									</select>
									<p class="mhbo-form-help"><?php esc_html_e( 'Select the primary model provider for guest concierge inquiries.', 'modern-hotel-booking' ); ?></p>
								</div>

								<div class="mhbo-form-group" id="mhbo_ai_api_key_wrapper" style="display:<?php echo in_array( $provider, array( 'wp_connector', 'ollama', '' ), true ) ? 'none' : 'block'; ?>;">
									<label class="mhbo-form-label" for="mhbo_ai_api_key"><?php esc_html_e( 'API Key', 'modern-hotel-booking' ); ?></label>
									<input type="password" name="mhbo_ai_api_key" id="mhbo_ai_api_key" value="<?php echo esc_attr( $api_key_masked ); ?>" class="mhbo-form-input" autocomplete="new-password" placeholder="AIzaSy... / sk-...">
									<p class="mhbo-form-help"><?php esc_html_e( 'Your private API secret key. Never shared or exposed in client frontend code.', 'modern-hotel-booking' ); ?></p>
								</div>

								<div class="mhbo-form-group mhbo-span-full" id="mhbo_primary_model_row" style="display:<?php echo '' === $provider ? 'none' : 'block'; ?>;">
									<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 6px;">
										<label class="mhbo-form-label" style="margin: 0;"><?php esc_html_e( 'Active AI Model Selection', 'modern-hotel-booking' ); ?></label>
										<div id="mhbo_ai_discover_models_wrap" style="display: flex; align-items: center; gap: 8px;">
											<span id="mhbo_ai_discover_models_result" style="font-size: 12px; font-weight: 600;"></span>
											<button type="button" id="mhbo_ai_discover_models_btn" class="button button-secondary" style="font-size: 12px; min-height: 28px; line-height: 26px; padding: 0 10px;">
												<span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;" aria-hidden="true"></span>
												<span><?php esc_html_e( 'Discover Live Models', 'modern-hotel-booking' ); ?></span>
											</button>
										</div>
									</div>

									<div id="mhbo_wp_connector_info" style="display: <?php echo ( $provider === 'wp_connector' ) ? 'block' : 'none'; ?>; padding: 10px 14px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; margin-bottom: 8px;">
										<p style="margin: 0; color: #166534; font-size: 12.5px;">
											<strong>🌐 <?php esc_html_e( 'Site-Wide WordPress Connector Routing', 'modern-hotel-booking' ); ?>:</strong>
											<?php esc_html_e( 'Choose Auto Priority or pin a preferred model across your connected providers in Settings → Connectors.', 'modern-hotel-booking' ); ?>
										</p>
									</div>

									<?php
									$preset_providers = array(
										Client::PROVIDER_WP_CONNECTOR,
										Client::PROVIDER_GEMINI,
										Client::PROVIDER_OPENAI,
										Client::PROVIDER_ANTHROPIC,
										Client::PROVIDER_OPENROUTER,
										Client::PROVIDER_OLLAMA,
									);
									$any_preset = false;
									foreach ( $preset_providers as $p_key ) :
										$p_presets       = ModelCatalog::get_presets( $p_key );
										$p_models        = array_merge(
											$p_presets,
											array( 'custom' => __( '— Manual Override / Custom —', 'modern-hotel-booking' ) )
										);
										$is_saved_for_p  = ( $provider === $p_key );
										$effective_model = ( $is_saved_for_p && '' === $model && Client::PROVIDER_WP_CONNECTOR === $p_key ) ? 'auto' : $model;
										$is_preset_match = $is_saved_for_p && array_key_exists( $effective_model, $p_presets );
										if ( $is_preset_match ) {
											$any_preset = true;
										}
										// Pre-select recommended default for inactive providers so switching never leaks a previous provider slug.
										$p_selected = $is_saved_for_p
											? ( $is_preset_match ? $effective_model : 'custom' )
											: ( (string) array_key_first( $p_presets ) ?: 'custom' );
										?>
										<div id="<?php echo esc_attr( 'mhbo_' . $p_key . '_model_wrapper' ); ?>" class="mhbo-provider-model-wrapper" style="display: <?php echo $is_saved_for_p ? 'block' : 'none'; ?>;">
											<select id="<?php echo esc_attr( 'mhbo_ai_' . $p_key . '_model_select' ); ?>" class="mhbo-form-input" style="margin-bottom: 8px;">
												<?php foreach ( $p_models as $val => $label ) : ?>
													<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $p_selected, $val ); ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
										</div>
									<?php endforeach; ?>

									<div id="mhbo_manual_model_wrapper" style="display: <?php echo ( ! $any_preset && '' !== $provider ) ? 'block' : 'none'; ?>;">
										<input type="text" name="mhbo_ai_model" id="mhbo_ai_model_input" value="<?php echo esc_attr( $model ); ?>" data-saved-provider="<?php echo esc_attr( $provider ); ?>" data-saved-model="<?php echo esc_attr( $model ); ?>" class="mhbo-form-input" placeholder="e.g. gemini-3.8-flash / gemini-4-carbon / gpt-6.2-sol / claude-sonnet-5-5">
										<p class="mhbo-form-help"><?php esc_html_e( 'Enter a specific model identifier if not listed in the presets above.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>

<div class="mhbo-form-group" id="mhbo_custom_url_row" style="display:<?php echo in_array( $provider, array( 'ollama', 'custom' ), true ) ? 'block' : 'none'; ?>;">
									<label class="mhbo-form-label" for="mhbo_ai_custom_url"><?php esc_html_e( 'Custom Endpoint Base URL', 'modern-hotel-booking' ); ?></label>
									<input type="url" name="mhbo_ai_custom_url" id="mhbo_ai_custom_url" value="<?php echo esc_url( $custom_url ); ?>" class="mhbo-form-input" placeholder="<?php echo esc_attr( 'ollama' === $provider ? 'http://localhost:11434/v1/chat/completions' : 'https://api.example.com/v1/chat/completions' ); ?>">
									<p class="mhbo-form-help"><?php esc_html_e( 'Base or /v1/chat/completions URL for custom or local OpenAI-compatible inference servers.', 'modern-hotel-booking' ); ?></p>
								</div>

								<div class="mhbo-form-group mhbo-span-full" style="display: flex; align-items: center; gap: 12px; margin-top: 6px;">
									<button type="button" id="mhbo_ai_test_btn" class="button button-secondary">
										<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
										<span><?php esc_html_e( 'Test Primary Connection', 'modern-hotel-booking' ); ?></span>
									</button>
									<span id="mhbo_ai_test_result" style="font-weight: 600; font-size: 13px;"></span>
								</div>
							</div>
						</div>

<?php elseif ( $current_tab === 'persona' ) : ?>
						<!-- ── Persona & Rules ────────────────────────────────────────── -->
						<div class="mhbo-settings-section-card">
							<div class="mhbo-settings-section-header">
								<div class="mhbo-settings-section-title-wrap">
									<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-admin-users"></span></div>
									<div>
										<h3 class="mhbo-settings-section-title"><?php esc_html_e( 'Assistant Persona & Custom Instructions', 'modern-hotel-booking' ); ?></h3>
										<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Define your digital front-desk identity, behavioral rules, and welcome greetings.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>
							</div>

							<div class="mhbo-settings-grid mhbo-grid-2col">
								<div class="mhbo-form-group">
									<label class="mhbo-form-label" for="mhbo_ai_persona_name"><?php esc_html_e( 'Bot Persona Name', 'modern-hotel-booking' ); ?></label>
									<input type="text" name="mhbo_ai_persona_name" id="mhbo_ai_persona_name" value="<?php echo esc_attr( $persona_name ); ?>" class="mhbo-form-input" placeholder="e.g. Concierge Alex">
									<p class="mhbo-form-help"><?php esc_html_e( 'Displayed in the chat widget header and message greetings.', 'modern-hotel-booking' ); ?></p>
								</div>

								<div class="mhbo-form-group">
									<label class="mhbo-form-label" for="mhbo_ai_welcome_message"><?php esc_html_e( 'Initial Welcome Greeting', 'modern-hotel-booking' ); ?></label>
									<input type="text" name="mhbo_ai_welcome_message" id="mhbo_ai_welcome_message" value="<?php echo esc_attr( $welcome_msg ); ?>" class="mhbo-form-input" placeholder="<?php esc_attr_e( 'How can I assist your stay today?', 'modern-hotel-booking' ); ?>">
									<p class="mhbo-form-help"><?php esc_html_e( 'The first automated greeting sent when the guest opens the chat widget.', 'modern-hotel-booking' ); ?></p>
								</div>

								<div class="mhbo-form-group mhbo-span-full">
									<label class="mhbo-form-label" for="mhbo_ai_custom_instructions"><?php esc_html_e( 'Custom Directives & House Rules', 'modern-hotel-booking' ); ?></label>
									<textarea name="mhbo_ai_custom_instructions" id="mhbo_ai_custom_instructions" rows="6" class="mhbo-form-input" style="font-family: inherit; line-height: 1.5;"><?php echo esc_textarea( $custom_instr ); ?></textarea>
									<p class="mhbo-form-help"><?php esc_html_e( 'Provide specific operational instructions, e.g. "Always mention complimentary breakfast buffet if guests inquire about morning dining."', 'modern-hotel-booking' ); ?></p>
								</div>
							</div>
						</div>

						<!-- ── Language & Localization ─────────────────────────────────── -->
						<div class="mhbo-settings-section-card">
							<div class="mhbo-settings-section-header">
								<div class="mhbo-settings-section-title-wrap">
									<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-translation"></span></div>
									<div>
										<h3 class="mhbo-settings-section-title"><?php esc_html_e( 'Language Adaptation & Voice Locale', 'modern-hotel-booking' ); ?></h3>
										<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Configure international multilingual adaptation and voice recognition locales.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>
							</div>

							<div class="mhbo-settings-grid mhbo-grid-2col">
								<div class="mhbo-form-group mhbo-span-full">
									<div style="padding: 14px 16px; background: #eff6ff; border-radius: 8px; border-left: 4px solid #3b82f6; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
										<div>
											<strong style="color: #1e40af; font-size: 14px;"><?php esc_html_e( 'Active Site Locale:', 'modern-hotel-booking' ); ?></strong>
											<p style="margin: 4px 0 0; font-size: 12.5px; color: #1e3a8a;"><?php esc_html_e( 'The AI dynamically replies in the guest language based on their active browsing session.', 'modern-hotel-booking' ); ?></p>
										</div>
										<span class="mhbo-rate-badge" style="background: #3b82f6; color: #fff; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 99px;">
											<?php echo esc_html( $detected_locale ); ?> (<?php echo esc_html( $lang_source_label ); ?>)
										</span>
									</div>
								</div>

								<div class="mhbo-form-group">
									<label class="mhbo-form-label" for="mhbo_ai_voice_language"><?php esc_html_e( 'Force Voice Recognition Locale', 'modern-hotel-booking' ); ?></label>
									<input type="text" name="mhbo_ai_voice_language" id="mhbo_ai_voice_language" value="<?php echo esc_attr( $voice_lang ); ?>" class="mhbo-form-input" placeholder="auto">
									<p class="mhbo-form-help"><?php esc_html_e( 'Optional BCP-47 locale tag (e.g. en-US, de-DE, ro-RO). Leave blank for auto-detection.', 'modern-hotel-booking' ); ?></p>
								</div>
							</div>
						</div>

						<!-- ── Local Guide ─────────────────────────────────────────────── -->
						<div class="mhbo-settings-section-card">
							<div class="mhbo-settings-section-header">
								<div class="mhbo-settings-section-title-wrap">
									<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-location-alt"></span></div>
									<div>
										<h3 class="mhbo-settings-section-title"><?php esc_html_e( 'Local Concierge Guide & Recommendations', 'modern-hotel-booking' ); ?></h3>
										<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Information on local restaurants, public transport, airport transfers, and sights.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>
							</div>

							<div class="mhbo-settings-grid">
								<div class="mhbo-form-group mhbo-span-full">
									<?php $local_guide = (string) get_option( 'mhbo_ai_local_guide', '' ); ?>
									<label class="mhbo-form-label" for="mhbo_ai_local_guide"><?php esc_html_e( 'Local Recommendations Knowledge', 'modern-hotel-booking' ); ?></label>
									<textarea name="mhbo_ai_local_guide" id="mhbo_ai_local_guide" rows="8" class="mhbo-form-input" style="font-family: inherit; line-height: 1.5;" placeholder="<?php esc_attr_e( 'e.g. Dining: Mario\'s Trattoria (2 blocks away, best pasta), Transit: Bus Line 4 directly to Main Plaza...', 'modern-hotel-booking' ); ?>"><?php echo esc_textarea( $local_guide ); ?></textarea>
									<p class="mhbo-form-help"><?php esc_html_e( 'Injected into the conversational context to answer tourist, dining, and transit inquiries.', 'modern-hotel-booking' ); ?></p>
								</div>
							</div>
						</div>

					<?php elseif ( $current_tab === 'appearance' ) : ?>
						<!-- ── Widget Display & Controls ─────────────────────────────────── -->
						<div class="mhbo-settings-section-card">
							<div class="mhbo-settings-section-header">
								<div class="mhbo-settings-section-title-wrap">
									<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-format-image"></span></div>
									<div>
										<h3 class="mhbo-settings-section-title"><?php esc_html_e( 'Chat Widget Display & Visual Styling', 'modern-hotel-booking' ); ?></h3>
										<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Configure frontend floating widget visibility, brand colors, and screen positioning.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>
							</div>

							<div class="mhbo-settings-grid mhbo-grid-2col">
								<div class="mhbo-form-group mhbo-span-full">
									<div class="mhbo-switch-row">
										<div class="mhbo-switch-info">
											<span class="mhbo-switch-title"><?php esc_html_e( 'Enable Floating Chat Widget', 'modern-hotel-booking' ); ?></span>
											<p class="mhbo-switch-desc"><?php esc_html_e( 'Displays the interactive floating AI assistant bubble in the corner of your website.', 'modern-hotel-booking' ); ?></p>
										</div>
										<label class="mhbo-toggle-switch" for="ai_widget_enabled">
											<input type="hidden" name="mhbo_ai_widget_enabled" value="0">
											<input type="checkbox" name="mhbo_ai_widget_enabled" value="1" <?php checked( $widget_enabled, 1 ); ?> id="ai_widget_enabled">
											<span class="mhbo-toggle-slider"></span>
										</label>
									</div>
								</div>

								<div class="mhbo-form-group mhbo-span-full">
									<div class="mhbo-switch-row">
										<div class="mhbo-switch-info">
											<span class="mhbo-switch-title"><?php esc_html_e( 'Show Globally on All Public Pages', 'modern-hotel-booking' ); ?></span>
											<p class="mhbo-switch-desc"><?php esc_html_e( 'When unchecked, the widget only loads on pages containing the shortcode or block.', 'modern-hotel-booking' ); ?></p>
										</div>
										<label class="mhbo-toggle-switch" for="ai_show_globally">
											<input type="hidden" name="mhbo_ai_show_globally" value="0">
											<input type="checkbox" name="mhbo_ai_show_globally" value="1" <?php checked( $show_globally, 1 ); ?> id="ai_show_globally">
											<span class="mhbo-toggle-slider"></span>
										</label>
									</div>
								</div>

								<div class="mhbo-form-group">
									<label class="mhbo-form-label" for="mhbo_ai_widget_position"><?php esc_html_e( 'Screen Position', 'modern-hotel-booking' ); ?></label>
									<select name="mhbo_ai_widget_position" id="mhbo_ai_widget_position" class="mhbo-form-input">
										<option value="bottom-right" <?php selected( $position, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right (Default)', 'modern-hotel-booking' ); ?></option>
										<option value="bottom-left"  <?php selected( $position, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom Left', 'modern-hotel-booking' ); ?></option>
									</select>
									<p class="mhbo-form-help"><?php esc_html_e( 'Choose which corner of the viewport the floating bubble docks to.', 'modern-hotel-booking' ); ?></p>
								</div>

								<div class="mhbo-form-group">
									<label class="mhbo-form-label" for="mhbo_ai_accent_color"><?php esc_html_e( 'Brand Accent Color', 'modern-hotel-booking' ); ?></label>
									<div style="display: flex; align-items: center; gap: 12px;">
										<input type="color" name="mhbo_ai_accent_color" id="mhbo_ai_accent_color" value="<?php echo esc_attr( $accent_color ); ?>" style="width: 44px; height: 40px; padding: 2px; border: 1px solid #cbd5e1; border-radius: 6px; cursor: pointer;">
										<code id="mhbo_ai_accent_color_hex" style="background: #f1f5f9; color: #334155; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 13px;"><?php echo esc_html( strtoupper( $accent_color ) ); ?></code>
									</div>
									<p class="mhbo-form-help"><?php esc_html_e( 'Used for button gradients, user chat bubbles, and interactive badges.', 'modern-hotel-booking' ); ?></p>
								</div>

								<?php if ( $is_pro ) : ?>
									
								<?php endif; ?>

								<div class="mhbo-form-group">
									<label class="mhbo-form-label" for="mhbo_ai_proactive_trigger_seconds"><?php esc_html_e( 'Proactive Suggestion Delay', 'modern-hotel-booking' ); ?></label>
									<div style="display: flex; align-items: center; gap: 10px;">
										<input type="number" name="mhbo_ai_proactive_trigger_seconds" id="mhbo_ai_proactive_trigger_seconds" value="<?php echo (int) $proactive_trigger; ?>" class="mhbo-form-input" min="0" style="max-width: 120px;">
										<span style="font-size: 13px; color: #64748b;"><?php esc_html_e( 'seconds of page inactivity before greeting popover appears (0 to disable).', 'modern-hotel-booking' ); ?></span>
									</div>
									<p class="mhbo-form-help"><?php esc_html_e( 'Gently prompts hesitating visitors to ask questions about booking availability.', 'modern-hotel-booking' ); ?></p>
								</div>
							</div>
						</div>

						<!-- ── Voice & Audio Hub ────────────────────────────────────────── -->
						<div class="mhbo-settings-section-card">
							<div class="mhbo-settings-section-header">
								<div class="mhbo-settings-section-title-wrap">
									<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-controls-volumeon"></span></div>
									<div>
										<h3 class="mhbo-settings-section-title"><?php esc_html_e( 'Voice Input & Audio Interactions Hub', 'modern-hotel-booking' ); ?></h3>
										<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Enable microphone dictation and neural text-to-speech spoken audio.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>
							</div>

							<div class="mhbo-settings-grid mhbo-grid-2col">
								<div class="mhbo-form-group mhbo-span-full">
									<div class="mhbo-switch-row">
										<div class="mhbo-switch-info">
											<span class="mhbo-switch-title"><?php esc_html_e( 'Speech-to-Text (Voice Dictation Input)', 'modern-hotel-booking' ); ?></span>
											<p class="mhbo-switch-desc"><?php esc_html_e( 'Allows guests to speak questions into their browser microphone with live speech recognition.', 'modern-hotel-booking' ); ?></p>
											<p class="mhbo-form-help" style="margin-top: 6px;">
												<?php
												printf(
													/* translators: 1: allowed Permissions-Policy header directive, 2: blocked Permissions-Policy header directive */
													esc_html__( 'Server Security Header Note: If your web server, CDN, or security plugin sends a Permissions-Policy header, ensure it allows same-origin microphone access (%1$s) rather than blocking it (%2$s).', 'modern-hotel-booking' ),
													'<code>Permissions-Policy: microphone=(self)</code>',
													'<code>microphone=()</code>'
												);
												?>
											</p>
										</div>
										<label class="mhbo-toggle-switch">
											<input type="hidden" name="mhbo_ai_voice_input_enabled" value="0">
											<input type="checkbox" name="mhbo_ai_voice_input_enabled" value="1" <?php checked( $voice_input, 1 ); ?>>
											<span class="mhbo-toggle-slider"></span>
										</label>
									</div>
								</div>

								<div class="mhbo-form-group mhbo-span-full">
									<div class="mhbo-switch-row">
										<div class="mhbo-switch-info">
											<span class="mhbo-switch-title"><?php esc_html_e( 'Text-to-Speech (Spoken Audio Output)', 'modern-hotel-booking' ); ?></span>
											<p class="mhbo-switch-desc"><?php esc_html_e( 'Plays assistant answers aloud using Web Speech API synthesis or ElevenLabs neural voice.', 'modern-hotel-booking' ); ?></p>
										</div>
										<label class="mhbo-toggle-switch">
											<input type="hidden" name="mhbo_ai_voice_output_enabled" value="0">
											<input type="checkbox" name="mhbo_ai_voice_output_enabled" value="1" <?php checked( $voice_output, 1 ); ?>>
											<span class="mhbo-toggle-slider"></span>
										</label>
									</div>
								</div>

</div>
						</div>

						<!-- ── Embedding Guide ─────────────────────────────────────────── -->
						<div class="mhbo-settings-section-card">
							<div class="mhbo-settings-section-header">
								<div class="mhbo-settings-section-title-wrap">
									<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-editor-code"></span></div>
									<div>
										<h3 class="mhbo-settings-section-title"><?php esc_html_e( 'Page Embedding & Shortcodes Reference', 'modern-hotel-booking' ); ?></h3>
										<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Embed the AI concierge directly into full-page layouts or custom landing pages.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>
							</div>

							<div class="mhbo-settings-grid mhbo-grid-2col">
								<div class="mhbo-form-group">
									<label class="mhbo-form-label"><?php esc_html_e( 'Gutenberg Block', 'modern-hotel-booking' ); ?></label>
									<p style="font-size: 13px; color: #475569; margin: 0 0 8px;"><?php esc_html_e( 'Search for the "Hotel: AI Concierge" block in the block editor to embed an inline or modal assistant widget anywhere.', 'modern-hotel-booking' ); ?></p>
								</div>

								<div class="mhbo-form-group">
									<label class="mhbo-form-label"><?php esc_html_e( 'Shortcode Snippet', 'modern-hotel-booking' ); ?></label>
									<code style="display: block; padding: 10px 14px; background: #f1f5f9; border-radius: 6px; font-size: 13px; font-weight: 700; color: #0f172a;">[mhbo_ai_concierge variant="inline"]</code>
									<p class="mhbo-form-help"><?php esc_html_e( 'Supports variant="inline" (embedded in page flow) or variant="floating" (corner bubble).', 'modern-hotel-booking' ); ?></p>
								</div>
							</div>
						</div>

					<?php endif; ?>

					<?php if ( $current_tab === 'discovery' ) : ?>
						<!-- ── AI Discovery (GEO) ────────────────────────────────────────── -->
						<div class="mhbo-settings-section-card">
							<div class="mhbo-settings-section-header">
								<div class="mhbo-settings-section-title-wrap">
									<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-cloud"></span></div>
									<div>
										<h3 class="mhbo-settings-section-title"><?php esc_html_e( 'Generative Engine Optimization (GEO) & llms.txt', 'modern-hotel-booking' ); ?></h3>
										<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Publish structured property discovery manifests so AI search engines can cite direct rates and amenities.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>
							</div>

							<?php 
							$discovery_status = class_exists( '\MHBO\AI\LlmFile' ) ? LlmFile::get_status() : array(
								'total_files' => 0,
								'summary'     => false,
								'full'        => false,
							); 
							$is_live          = ! empty( $discovery_status['summary'] ) && ! empty( $discovery_status['full'] );
							?>

							<div class="mhbo-settings-grid mhbo-grid-2col">
								<div class="mhbo-form-group mhbo-span-full">
									<div style="padding: 16px 20px; background: <?php echo $is_live ? '#f0fdf4' : '#fff7ed'; ?>; border-radius: 8px; border: 1px solid <?php echo $is_live ? '#bbf7d0' : '#ffedd5'; ?>; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
										<div>
											<div style="display: flex; align-items: center; gap: 8px;">
												<span style="font-size: 18px;"><?php echo $is_live ? '✅' : '⚠️'; ?></span>
												<strong style="font-size: 16px; color: <?php echo $is_live ? '#166534' : '#9a3412'; ?>;">
													<?php echo $is_live ? esc_html__( 'Manifest is Live & Indexable', 'modern-hotel-booking' ) : esc_html__( 'Manifest Not Indexable Yet', 'modern-hotel-booking' ); ?>
												</strong>
											</div>
											<p style="margin: 6px 0 0; font-size: 13px; color: #64748b;">
												<?php if ( $discovery_sync ) : ?>
													<?php 
													// translators: %s: date of the manifest publication
													printf( esc_html__( 'Published to root: %s', 'modern-hotel-booking' ), esc_html( $discovery_sync ) ); 
													?>
												<?php else : ?>
													<?php esc_html_e( 'Property discovery files (llms.txt) are not currently published to your site root.', 'modern-hotel-booking' ); ?>
												<?php endif; ?>
											</p>
										</div>
										
										<div style="display: flex; gap: 10px;">
											<button type="button" id="mhbo_ai_discovery_sync" class="button button-primary">
												<span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
												<span><?php echo $is_live ? esc_html__( 'Update Manifest Now', 'modern-hotel-booking' ) : esc_html__( 'Publish Discovery Files', 'modern-hotel-booking' ); ?></span>
											</button>
											<?php if ( $is_live ) : ?>
												<button type="button" id="mhbo_ai_discovery_cleanup" class="button button-link-delete" style="color: #dc2626;">
													<?php esc_html_e( 'Takedown Manifest', 'modern-hotel-booking' ); ?>
												</button>
											<?php endif; ?>
										</div>
									</div>
									<span id="mhbo_ai_discovery_status_msg" style="display: block; margin-top: 6px; font-size: 12.5px; font-weight: 600;"></span>
								</div>

								<div class="mhbo-form-group">
									<label class="mhbo-form-label" for="mhbo_ai_sitemap_url"><?php esc_html_e( 'Sitemap XML URL', 'modern-hotel-booking' ); ?></label>
									<input type="url" name="mhbo_ai_sitemap_url" id="mhbo_ai_sitemap_url" value="<?php echo esc_url( get_option( 'mhbo_ai_sitemap_url', '' ) ); ?>" class="mhbo-form-input" placeholder="https://example.com/sitemap.xml">
									<p class="mhbo-form-help"><?php esc_html_e( 'Optional. Appended to llms.txt for enhanced search engine indexing.', 'modern-hotel-booking' ); ?></p>
								</div>

								<div class="mhbo-form-group">
									<div class="mhbo-switch-row" style="margin-top: 6px;">
										<div class="mhbo-switch-info">
											<span class="mhbo-switch-title"><?php esc_html_e( 'Auto-Sync on Room/Rate Updates', 'modern-hotel-booking' ); ?></span>
											<p class="mhbo-switch-desc"><?php esc_html_e( 'Automatically re-indexes discovery manifests when rooms, rates, or settings change.', 'modern-hotel-booking' ); ?></p>
										</div>
										<label class="mhbo-toggle-switch">
											<input type="hidden" name="mhbo_ai_discovery_auto_sync" value="0">
											<input type="checkbox" name="mhbo_ai_discovery_auto_sync" value="1" <?php checked( $discovery_auto, 1 ); ?>>
											<span class="mhbo-toggle-slider"></span>
										</label>
									</div>
								</div>

</div>
						</div>

						<!-- ── MCP Server Engine ────────────────────────────────────────── -->
						<div class="mhbo-settings-section-card">
							<div class="mhbo-settings-section-header">
								<div class="mhbo-settings-section-title-wrap">
									<div class="mhbo-settings-section-icon"><span class="dashicons dashicons-rest-api"></span></div>
									<div>
										<h3 class="mhbo-settings-section-title"><?php esc_html_e( 'Model Context Protocol (MCP) Server Engine', 'modern-hotel-booking' ); ?></h3>
										<p class="mhbo-settings-section-desc"><?php esc_html_e( 'Expose real-time room availability, policy lookups, and direct booking links via JSON-RPC 2.0 and OpenAPI 3.1.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>
							</div>

							<?php
							$mcp_status        = class_exists( '\MHBO\AI\McpServer' ) ? McpServer::get_status() : array();
							$is_bridged        = ! empty( $mcp_status['is_bridged'] );
							$adapter_installed = ! empty( $mcp_status['adapter_installed'] );
							$mcp_hybrid        = (int) get_option( 'mhbo_ai_mcp_hybrid_bridge', 0 );
							$mcp_tier          = (string) ( $mcp_status['tier'] ?? 'free' );
							$abilities_count   = (int) ( $mcp_status['abilities_count'] ?? 10 );
							$guest_tools_count = (int) ( $mcp_status['guest_tools_count'] ?? 10 );
							?>

							<div class="mhbo-settings-grid">
								<!-- Hybrid Architecture Status Card -->
								<div class="mhbo-form-group mhbo-span-full">
									<div style="padding: 16px 20px; background: <?php echo $mcp_enabled ? ( $is_bridged ? '#f0fdf4' : '#f8fafc' ) : '#fef2f2'; ?>; border: 1px solid <?php echo $mcp_enabled ? ( $is_bridged ? '#bbf7d0' : '#cbd5e1' ) : '#fecaca'; ?>; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
										<div>
											<div style="display: flex; align-items: center; gap: 8px;">
												<span style="font-size: 18px;"><?php echo $mcp_enabled ? ( $is_bridged ? '🟢' : '⚡' ) : '🔴'; ?></span>
												<strong style="font-size: 15px; color: <?php echo $mcp_enabled ? ( $is_bridged ? '#166534' : '#0f172a' ) : '#991b1b'; ?>;">
													<?php
													if ( ! $mcp_enabled ) {
														esc_html_e( 'MCP Server is Inactive (Gateway Disabled)', 'modern-hotel-booking' );
													} elseif ( $is_bridged ) {
														esc_html_e( 'WordPress MCP Adapter Bridge Connected (Unified Route Active)', 'modern-hotel-booking' );
													} else {
														esc_html_e( 'Hybrid Native Streamable HTTP Engine Active (Standalone)', 'modern-hotel-booking' );
													}
													?>
												</strong>
											</div>
											<div style="display: flex; gap: 8px; align-items: center; margin-top: 8px; flex-wrap: wrap; font-size: 12px;">
												<span class="mhbo-badge" style="background: #eff6ff; color: #1e40af; font-weight: 600; padding: 3px 8px; border-radius: 4px;">
													<?php esc_html_e( 'Transport: Streamable HTTP (JSON-RPC 2.0)', 'modern-hotel-booking' ); ?>
												</span>
												<span class="mhbo-badge" style="background: #f1f5f9; color: #334155; font-weight: 600; padding: 3px 8px; border-radius: 4px;">
													<?php esc_html_e( 'Specs: 2025-03-26, 2024-11-05', 'modern-hotel-booking' ); ?>
												</span>
												<span class="mhbo-badge" style="background: <?php echo 'pro' === $mcp_tier ? '#f3e8ff' : '#ecfdf5'; ?>; color: <?php echo 'pro' === $mcp_tier ? '#7c3aed' : '#047857'; ?>; font-weight: 700; padding: 3px 8px; border-radius: 4px;">
													<?php
													if ( 'pro' === $mcp_tier ) {
														/* translators: %d: Total number of tools registered in the Pro suite */
														echo esc_html( sprintf( __( 'Pro Suite: %d Tools (10 Guest + 7 Operator) + Feeds', 'modern-hotel-booking' ), $abilities_count ) );
													} elseif ( 'starter' === $mcp_tier ) {
														/* translators: %d: Number of guest discovery tools */
														echo esc_html( sprintf( __( 'Starter Edition: %d Guest Discovery Tools Active', 'modern-hotel-booking' ), $guest_tools_count ) );
													} else {
														/* translators: %d: Number of guest discovery tools */
														echo esc_html( sprintf( __( 'Free Edition: %d Guest Discovery Tools Active', 'modern-hotel-booking' ), $guest_tools_count ) );
													}
													?>
												</span>
											</div>
										</div>
										<button type="button" id="mhbo_ai_test_mcp_btn" class="button button-primary">
											<span class="dashicons dashicons-search" aria-hidden="true"></span>
											<span><?php esc_html_e( 'Probe Live Endpoint', 'modern-hotel-booking' ); ?></span>
										</button>
									</div>
									<div id="mhbo_ai_test_mcp_result" style="margin-top: 6px; font-weight: 600; font-size: 13px;"></div>
								</div>

								<!-- 1. Master Enable -->
								<div class="mhbo-form-group mhbo-span-full">
									<div class="mhbo-switch-row">
										<div class="mhbo-switch-info">
											<span class="mhbo-switch-title"><?php esc_html_e( 'Enable External MCP Server Gateway', 'modern-hotel-booking' ); ?></span>
											<p class="mhbo-switch-desc"><?php esc_html_e( 'Allows Claude Desktop, Cursor, ChatGPT Actions, and autonomous AI agents to query real-time live hotel availability.', 'modern-hotel-booking' ); ?></p>
										</div>
										<label class="mhbo-toggle-switch">
											<input type="hidden" name="mhbo_ai_mcp_enabled" value="0">
											<input type="checkbox" name="mhbo_ai_mcp_enabled" value="1" <?php checked( $mcp_enabled, 1 ); ?>>
											<span class="mhbo-toggle-slider"></span>
										</label>
									</div>
								</div>

								<!-- 2. Hybrid Dual-Engine Bridge Toggle -->
								<div class="mhbo-form-group mhbo-span-full">
									<div class="mhbo-switch-row">
										<div class="mhbo-switch-info">
											<span class="mhbo-switch-title"><?php esc_html_e( 'Connect to WordPress Core MCP Adapter (Hybrid Dual-Engine)', 'modern-hotel-booking' ); ?></span>
											<p class="mhbo-switch-desc">
												<?php esc_html_e( 'When enabled and the official WordPress/mcp-adapter plugin or WordPress 7.0+ Connectors API is installed, MHBO automatically exposes its abilities through the global WordPress MCP endpoint (/wp-json/mcp/mcp-adapter-default-server). When disabled or running standalone, MHBO operates via its high-performance native Streamable HTTP server.', 'modern-hotel-booking' ); ?>
												<?php if ( $adapter_installed ) : ?>
													<strong style="color: #166534; display: block; margin-top: 4px;">✓ <?php esc_html_e( 'WordPress MCP Adapter plugin detected and ready.', 'modern-hotel-booking' ); ?></strong>
												<?php else : ?>
													<span style="color: #64748b; display: block; margin-top: 4px;">ℹ️ <?php esc_html_e( 'WordPress MCP Adapter plugin is not currently active. Native Standalone Streamable HTTP server is active with automatic fallback route alias.', 'modern-hotel-booking' ); ?></span>
												<?php endif; ?>
											</p>
										</div>
										<label class="mhbo-toggle-switch">
											<input type="hidden" name="mhbo_ai_mcp_hybrid_bridge" value="0">
											<input type="checkbox" name="mhbo_ai_mcp_hybrid_bridge" value="1" <?php checked( $mcp_hybrid, 1 ); ?>>
											<span class="mhbo-toggle-slider"></span>
										</label>
									</div>
								</div>

								<!-- 3. Primary Streamable HTTP Endpoint -->
								<div class="mhbo-form-group mhbo-span-full">
									<label class="mhbo-form-label"><?php esc_html_e( 'Streamable HTTP MCP Endpoint URL (Claude Desktop / Cursor)', 'modern-hotel-booking' ); ?></label>
									<div style="display: flex; gap: 10px; align-items: center;">
										<input type="text" readonly value="<?php echo esc_url( method_exists( '\MHBO\AI\McpServer', 'get_endpoint_url' ) ? McpServer::get_endpoint_url() : '' ); ?>" class="mhbo-form-input" style="font-family: monospace; background: #f8fafc;" id="mhbo_mcp_endpoint_input">
										<button type="button" class="button button-secondary" data-mhbo-copy-target="mhbo_mcp_endpoint_input" data-mhbo-copied-label="<?php echo esc_attr__( 'Copied!', 'modern-hotel-booking' ); ?>">
											<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
											<span><?php esc_html_e( 'Copy URL', 'modern-hotel-booking' ); ?></span>
										</button>
									</div>
									<p class="mhbo-form-help"><?php esc_html_e( 'Native Streamable HTTP JSON-RPC 2.0 endpoint. Compatible with Claude Desktop, Cursor, and agent frameworks.', 'modern-hotel-booking' ); ?></p>
								</div>

								<!-- 4. OpenAPI Specification URL -->
								<div class="mhbo-form-group mhbo-span-full">
									<label class="mhbo-form-label"><?php esc_html_e( 'OpenAPI 3.1 Specification URL (ChatGPT Custom Actions / Custom GPTs)', 'modern-hotel-booking' ); ?></label>
									<div style="display: flex; gap: 10px; align-items: center;">
										<input type="text" readonly value="<?php echo esc_url( method_exists( '\MHBO\AI\McpServer', 'get_openapi_url' ) ? McpServer::get_openapi_url() : '' ); ?>" class="mhbo-form-input" style="font-family: monospace; background: #f8fafc;" id="mhbo_openapi_endpoint_input">
										<button type="button" class="button button-secondary" data-mhbo-copy-target="mhbo_openapi_endpoint_input" data-mhbo-copied-label="<?php echo esc_attr__( 'Copied!', 'modern-hotel-booking' ); ?>">
											<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
											<span><?php esc_html_e( 'Copy URL', 'modern-hotel-booking' ); ?></span>
										</button>
									</div>
									<p class="mhbo-form-help"><?php esc_html_e( 'Import directly into ChatGPT Custom GPTs (Actions > Import from URL) to allow OpenAI models to query live availability.', 'modern-hotel-booking' ); ?></p>
								</div>

								<!-- 5. Client Setup Instructions -->
								<div class="mhbo-form-group mhbo-span-full" style="margin-top: 10px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
									<strong style="font-size: 13px; color: #1e293b;">📘 <?php esc_html_e( 'Client Setup Instructions', 'modern-hotel-booking' ); ?></strong>
									<div style="margin-top: 8px; font-size: 12.5px; color: #475569; line-height: 1.5;">
										<p style="margin: 0 0 6px 0;"><strong><?php esc_html_e( 'Claude Desktop Configuration:', 'modern-hotel-booking' ); ?></strong> <?php esc_html_e( 'In your claude_desktop_config.json, add a server entry targeting the MCP URL above. For hotel operator tasks (guest CRM, reservation holds), add your WordPress Application Password in the Authorization header.', 'modern-hotel-booking' ); ?></p>
										<p style="margin: 0;"><strong><?php esc_html_e( 'ChatGPT Actions & Travel Bots:', 'modern-hotel-booking' ); ?></strong> <?php esc_html_e( 'In your Custom GPT configure Actions using the OpenAPI URL. Public travel bots can freely query room availability and rates without needing a password.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>
							</div>
						</div>

					<?php endif; ?>

</div>

				<?php if ( 'analytics' !== $current_tab ) : ?>
					<div class="mhbo-form-actions-dock" style="margin-top: 24px; max-width: 1200px;">
						<?php submit_button( __( 'Apply AI Configuration', 'modern-hotel-booking' ), 'primary large', 'submit', false, array( 'style' => 'height: 42px; padding: 0 28px; font-weight: 600;' ) ); ?>
					</div>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Save Handler
	// -------------------------------------------------------------------------

	private static function handle_save(): void {
		if ( ! self::check_rest_permission() ) {
			return;
		}
		if ( ! isset( $_POST['mhbo_ai_nonce'] ) ) {
			return;
		}
		if ( false === \check_admin_referer( self::NONCE_SAVE, 'mhbo_ai_nonce' ) ) {
			return;
		}

		$active_tab = isset( $_POST['active_tab'] ) ? sanitize_key( wp_unslash( $_POST['active_tab'] ) ) : '';

		$tab_fields = array(
			'general'    => array(
				'mhbo_ai_enabled',
				'mhbo_ai_provider',
				'mhbo_ai_api_key',
				'mhbo_ai_model', 
				'mhbo_ai_custom_url',
				
				'mhbo_ai_streaming_enabled',
				
			),
			'persona'    => array(
				'mhbo_ai_persona_name',
				'mhbo_ai_custom_instructions',
				'mhbo_ai_welcome_message',
				'mhbo_ai_voice_language',
				'mhbo_ai_local_guide',
			),
			'appearance' => array(
				'mhbo_ai_widget_enabled',
				'mhbo_ai_show_globally',
				'mhbo_ai_widget_position', 
				'mhbo_ai_accent_color',
				
				'mhbo_ai_proactive_trigger_seconds',
				'mhbo_ai_voice_input_enabled',
				'mhbo_ai_voice_output_enabled',
				
			),
			'discovery'  => array(
				'mhbo_ai_discovery_auto_sync',
				'mhbo_ai_sitemap_url',
				'mhbo_ai_mcp_enabled',
				'mhbo_ai_mcp_hybrid_bridge',
			),
			
		);

		// 1. Text/Password/Select fields.
		$text_opts  = $tab_fields[ $active_tab ] ?? array();
		$checkboxes = array( 
			'mhbo_ai_enabled',
			'mhbo_ai_show_globally',
			'mhbo_ai_widget_enabled', 
			'mhbo_ai_mcp_enabled',
			'mhbo_ai_mcp_hybrid_bridge',
			'mhbo_ai_voice_input_enabled',
			'mhbo_ai_voice_output_enabled',
			'mhbo_ai_discovery_auto_sync',
			'mhbo_ai_feeds_enabled',
			'mhbo_ai_streaming_enabled',
		);

		// 0. Extract all relevant POST data once (Gated by Nonce).
		$post_data     = wp_unslash( $_POST );
		$rejected_urls = array();

foreach ( $text_opts as $key ) {
			if ( ! isset( $post_data[ $key ] ) && ! in_array( $key, $checkboxes, true ) ) {
				continue;
			}

			$raw_val = $post_data[ $key ] ?? null;

			// Textarea special handling.
			if ( in_array( $key, array( 'mhbo_ai_custom_instructions', 'mhbo_ai_local_guide' ), true ) ) {
				$val = is_string( $raw_val ) ? sanitize_textarea_field( $raw_val ) : '';
				update_option( $key, $val );
				continue;
			}

// Checkbox logic: Only update if it's a checkbox AND it's in the current tab's scope.
			if ( in_array( $key, $checkboxes, true ) ) {
				$val = ! empty( $raw_val ) ? 1 : 0;
				update_option( $key, $val );
				continue;
			}

			// Standard text/select.
			if ( null !== $raw_val ) {
				$val_str = is_scalar( $raw_val ) ? (string) $raw_val : '';
				if ( 'mhbo_ai_proactive_trigger_seconds' === $key ) {
					update_option( $key, absint( $val_str ) );
				} elseif ( 'mhbo_ai_context_budget' === $key ) {
					update_option( $key, self::sanitize_context_budget( $val_str ) );
				} elseif ( 'mhbo_ai_accent_color' === $key ) {
					$clean_hex = sanitize_hex_color( $val_str );
					update_option( $key, is_string( $clean_hex ) && '' !== $clean_hex ? $clean_hex : '#2C3E50' );
				} elseif ( 'mhbo_ai_widget_position' === $key ) {
					update_option( $key, self::sanitize_widget_position( $val_str ) );
				} elseif ( 'mhbo_ai_reasoning_effort' === $key ) {
					update_option( $key, self::sanitize_reasoning_effort( $val_str ) );
				} elseif ( in_array( $key, array( 'mhbo_ai_provider', 'mhbo_ai_fallback_provider', 'mhbo_ai_theme' ), true ) ) {
					update_option( $key, sanitize_key( $val_str ) );
				} elseif ( 'mhbo_hotel_latitude' === $key ) {
					update_option( $key, self::sanitize_coordinate( $val_str, -90.0, 90.0 ) );
				} elseif ( 'mhbo_hotel_longitude' === $key ) {
					update_option( $key, self::sanitize_coordinate( $val_str, -180.0, 180.0 ) );
				} elseif ( 'mhbo_google_hotel_id' === $key ) {
					update_option( $key, sanitize_title( $val_str ) );
				} elseif ( 'mhbo_google_hotel_category' === $key ) {
					update_option( $key, sanitize_key( $val_str ) );
				} elseif ( 'mhbo_hotel_feed_days' === $key ) {
					update_option( $key, self::sanitize_feed_days( $val_str ) );
				} elseif ( 'mhbo_ai_sitemap_url' === $key ) {
					$clean_url = '' === trim( $val_str ) ? '' : esc_url_raw( $val_str, array( 'http', 'https' ) );
					if ( '' === $clean_url || Security::is_safe_url( $clean_url ) ) {
						update_option( $key, $clean_url );
					} else {
						$rejected_urls[] = $key;
					}
				} elseif ( in_array( $key, array( 'mhbo_ai_custom_url', 'mhbo_ai_fallback_custom_url' ), true ) ) {
					$clean_url     = '' === trim( $val_str ) ? '' : esc_url_raw( $val_str, array( 'http', 'https' ) );
					$provider_opt  = ( 'mhbo_ai_fallback_custom_url' === $key ) ? 'mhbo_ai_fallback_provider' : 'mhbo_ai_provider';
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at top of handle_save().
					$posted_prov   = isset( $_POST[ $provider_opt ] ) ? sanitize_key( wp_unslash( (string) $_POST[ $provider_opt ] ) ) : (string) get_option( $provider_opt, '' );
					$allow_loop    = ( 'ollama' === $posted_prov ) && Security::is_loopback_url( $clean_url );
					if ( '' === $clean_url || Security::is_safe_url( $clean_url ) || $allow_loop ) {
						update_option( $key, $clean_url );
					} else {
						$rejected_urls[] = $key;
					}
				} elseif ( in_array( $key, array( 'mhbo_ai_api_key', 'mhbo_ai_fallback_api_key', 'mhbo_ai_elevenlabs_key' ), true ) ) {
					update_option( $key, self::sanitize_secret_option( $key, $val_str ), false );
				} else {
					update_option( $key, sanitize_text_field( $val_str ) );
				}
			}
		}

		if ( 'general' === $active_tab ) {
			Client::flush_native_availability();
			Client::reset_failure_score();
			Client::clear_model_failure( (string) get_option( 'mhbo_ai_model', '' ) );
			Client::clear_model_failure( (string) get_option( 'mhbo_ai_fallback_model', '' ) );
		}

$notices = array(
			array(
				'type'    => 'success',
				'message' => __( 'AI Concierge settings saved.', 'modern-hotel-booking' ),
			),
		);

		if ( array() !== $rejected_urls ) {
			$notices[] = array(
				'type'    => 'warning',
				'message' => __( 'Some endpoint URLs were not saved because they point to a private, internal, or non-standard-port address. Ollama must use a loopback address (localhost, 127.0.0.1, or ::1); custom and sitemap endpoints must be public HTTP(S) URLs on port 80 or 443. Rejected fields:', 'modern-hotel-booking' ) . ' ' . implode( ', ', $rejected_urls ),
			);
		}

$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		set_transient( 'mhbo_ai_notices_' . $uid, $notices, 30 );
	}

	// -------------------------------------------------------------------------
	// Language Detection Helper
	// -------------------------------------------------------------------------

	/**
	 * Return the currently active locale and which plugin/system provided it.
	 *
	 * @return array{ locale: string, source: string, label: string }
	 */
	private static function get_multilang_status(): array {
		// Polylang.
		if ( function_exists( 'pll_current_language' ) ) {
			/** @var string|false $locale */
			$locale = call_user_func( 'pll_current_language', 'locale' );
			if ( $locale ) {
				return array(
					'locale' => str_replace( '_', '-', $locale ),
					'source' => 'polylang',
					'label'  => 'Polylang',
				);
			}
		}

		// WPML.
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$details = apply_filters( 'wpml_current_language_details', null );
			$locale  = ( is_array( $details ) && isset( $details['default_locale'] ) && '' !== $details['default_locale'] )
				? str_replace( '_', '-', (string) $details['default_locale'] )
				: (string) constant( 'ICL_LANGUAGE_CODE' );
			return array(
				'locale' => $locale,
				'source' => 'wpml',
				'label'  => 'WPML',
			);
		}

		// qTranslate-XT / qTranslate-X.
		$q_config_arr = isset( $GLOBALS['q_config'] ) && is_array( $GLOBALS['q_config'] ) ? $GLOBALS['q_config'] : array();
		$qlang        = function_exists( 'qtranxf_getLanguage' )
			? (string) call_user_func( 'qtranxf_getLanguage' )
			: (string) ( $q_config_arr['language'] ?? '' );
		if ( '' !== $qlang ) {
			$qlocale = (string) ( $q_config_arr['locale'][ $qlang ] ?? $qlang );
			return array(
				'locale' => str_replace( '_', '-', $qlocale ),
				'source' => 'qtranslate',
				'label'  => 'qTranslate-XT',
			);
		}

		// Bogo.
		if ( function_exists( 'bogo_get_current_locale' ) || defined( 'BOGO_VERSION' ) ) {
			$bogo_locale = function_exists( 'bogo_get_current_locale' ) ? (string) call_user_func( 'bogo_get_current_locale' ) : get_locale();
			return array(
				'locale' => str_replace( '_', '-', $bogo_locale ),
				'source' => 'bogo',
				'label'  => 'Bogo',
			);
		}

		return array(
			'locale' => str_replace( '_', '-', get_locale() ),
			'source' => 'wp_core',
			'label'  => __( 'WordPress site language', 'modern-hotel-booking' ),
		);
	}

// -------------------------------------------------------------------------
	// Inline JS
	// -------------------------------------------------------------------------

	private static function get_inline_js(): string {
		return "
document.addEventListener('DOMContentLoaded', function() {
    var apiFetch = (window.wp && window.wp.apiFetch) ? window.wp.apiFetch : null;

    function renderAiStatus(el, color, text) {
        if (!el) return;
        el.textContent = '';
        var span = document.createElement('span');
        span.style.color = color;
        span.style.fontWeight = '600';
        span.textContent = text;
        el.appendChild(span);
    }

    function setButtonLabel(btn, text) {
        if (!btn) return;
        var span = btn.querySelector('span:not(.dashicons)');
        if (span) {
            span.textContent = text;
        } else {
            btn.textContent = text;
        }
    }

    function bindKbSyncButton(btnId, resultId, defaultLabel) {
        var btn = document.getElementById(btnId);
        var result = document.getElementById(resultId);
        if (!btn || !apiFetch) return;
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            btn.disabled = true;
            setButtonLabel(btn, 'Syncing…');
            if (result) result.textContent = '';
            apiFetch({ path: '/mhbo/v1/ai/sync-kb', method: 'POST' })
                .then(function() {
                    location.reload();
                })
                .catch(function(err) {
                    btn.disabled = false;
                    setButtonLabel(btn, defaultLabel);
                    renderAiStatus(result, '#dc2626', '✗ ' + (err.message || 'Sync failed'));
                });
        });
    }

    // 1. Knowledge Base Sync buttons
    bindKbSyncButton('mhbo_ai_kb_refresh', 'mhbo_ai_kb_result', 'Sync KB Now');
    bindKbSyncButton('mhbo_ai_kb_sync', 'mhbo_ai_kb_sync_result', 'Sync Now');

    function getFieldVal(id) {
        var el = document.getElementById(id);
        return el ? el.value : '';
    }

    function getSelectedPrimaryPayload() {
        var provider = getFieldVal('mhbo_ai_provider');
        var presetSel = provider ? document.getElementById('mhbo_ai_' + provider + '_model_select') : null;
        var model = (presetSel && presetSel.value && presetSel.value !== 'custom')
            ? presetSel.value
            : getFieldVal('mhbo_ai_model_input');
        return {
            provider: provider,
            model: model,
            api_key: getFieldVal('mhbo_ai_api_key'),
            custom_url: getFieldVal('mhbo_ai_custom_url'),
            reasoning_effort: getFieldVal('mhbo_ai_reasoning_effort')
        };
    }

    // 2. Primary Connection Test
    var testBtn = document.getElementById('mhbo_ai_test_btn');
    var testResult = document.getElementById('mhbo_ai_test_result');
    if (testBtn && apiFetch) {
        testBtn.addEventListener('click', function(e) {
            e.preventDefault();
            testBtn.disabled = true;
            setButtonLabel(testBtn, 'Testing…');
            if (testResult) testResult.textContent = '';
            apiFetch({ path: '/mhbo/v1/ai/test-connection', method: 'POST', data: getSelectedPrimaryPayload() })
                .then(function(res) {
                    testBtn.disabled = false;
                    setButtonLabel(testBtn, 'Test Primary Connection');
                    var timing = res.latency_ms ? ' (' + res.latency_ms + 'ms)' : '';
                    renderAiStatus(testResult, '#16a34a', '✅ ' + (res.message || 'Connected!') + timing);
                    refreshAiHealthStatus();
                })
                .catch(function(err) {
                    testBtn.disabled = false;
                    setButtonLabel(testBtn, 'Test Primary Connection');
                    renderAiStatus(testResult, '#dc2626', '❌ ' + (err.message || 'Connection failed'));
                    refreshAiHealthStatus();
                });
        });
    }

// 3b. Test WordPress AI Connector
    var connBtn = document.getElementById('mhbo_ai_test_connector_btn');
    var connResult = document.getElementById('mhbo_ai_test_connector_result');
    if (connBtn && apiFetch) {
        connBtn.addEventListener('click', function(e) {
            e.preventDefault();
            connBtn.disabled = true;
            setButtonLabel(connBtn, 'Testing Connector…');
            renderAiStatus(connResult, '#64748b', 'Connecting to WordPress AI Connector…');
            apiFetch({ path: '/mhbo/v1/ai/test-connector', method: 'POST' })
                .then(function(res) {
                    connBtn.disabled = false;
                    setButtonLabel(connBtn, 'Test WordPress AI Connector');
                    var prov = res.provider ? ' (' + res.provider + ' / ' + res.model + ', ' + res.latency_ms + 'ms)' : '';
                    renderAiStatus(connResult, '#16a34a', '✅ ' + (res.message || 'Connected!') + prov);
                })
                .catch(function(err) {
                    connBtn.disabled = false;
                    setButtonLabel(connBtn, 'Test WordPress AI Connector');
                    renderAiStatus(connResult, '#dc2626', '❌ ' + (err.message || 'Connector test failed'));
                });
        });
    }

    // 4. Test MCP Endpoint
    var mcpBtn = document.getElementById('mhbo_ai_test_mcp_btn');
    var mcpResult = document.getElementById('mhbo_ai_test_mcp_result');
    if (mcpBtn && apiFetch) {
        mcpBtn.addEventListener('click', function(e) {
            e.preventDefault();
            mcpBtn.disabled = true;
            setButtonLabel(mcpBtn, 'Probing…');
            if (mcpResult) mcpResult.textContent = '';
            apiFetch({ path: '/mhbo/v1/ai/mcp-status', method: 'POST' })
            .then(function(res) {
                mcpBtn.disabled = false;
                setButtonLabel(mcpBtn, 'Probe Live Endpoint');
                if (res && res.success) {
                    renderAiStatus(mcpResult, '#16a34a', '✅ ' + (res.message || 'Connected!'));
                } else {
                    renderAiStatus(mcpResult, '#f59e0b', '⚠️ ' + (res.message || 'MCP Adapter required'));
                }
            })
            .catch(function(err) {
                mcpBtn.disabled = false;
                setButtonLabel(mcpBtn, 'Probe Live Endpoint');
                renderAiStatus(mcpResult, '#dc2626', '❌ ' + (err.message || 'Probe failed'));
            });
        });
    }

    // 5. Discovery Sync
    var discSyncBtn = document.getElementById('mhbo_ai_discovery_sync');
    var discStatusEl = document.getElementById('mhbo_ai_discovery_status_msg');
    if (discSyncBtn && apiFetch) {
        var discSyncSpan = discSyncBtn.querySelector('span:not(.dashicons)');
        var discSyncOrigLabel = discSyncSpan ? discSyncSpan.textContent : 'Update Manifest Now';
        discSyncBtn.addEventListener('click', function(e) {
            e.preventDefault();
            discSyncBtn.disabled = true;
            setButtonLabel(discSyncBtn, 'Syncing…');
            if (discStatusEl) discStatusEl.textContent = '';
            apiFetch({ path: '/mhbo/v1/ai/sync-discovery', method: 'POST' })
                .then(function() { location.reload(); })
                .catch(function(err) {
                    discSyncBtn.disabled = false;
                    setButtonLabel(discSyncBtn, discSyncOrigLabel);
                    renderAiStatus(discStatusEl, '#dc2626', '❌ ' + ((err && err.message) || 'Sync failed'));
                });
        });
    }

    // 6. Discovery Cleanup
    var discCleanBtn = document.getElementById('mhbo_ai_discovery_cleanup');
    if (discCleanBtn && apiFetch) {
        discCleanBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to remove discovery files?')) return;
            discCleanBtn.disabled = true;
            discCleanBtn.textContent = 'Cleaning…';
            if (discStatusEl) discStatusEl.textContent = '';
            apiFetch({ path: '/mhbo/v1/ai/cleanup-discovery', method: 'POST' })
                .then(function() { location.reload(); })
                .catch(function(err) {
                    discCleanBtn.disabled = false;
                    discCleanBtn.textContent = 'Takedown Manifest';
                    renderAiStatus(discStatusEl, '#dc2626', '❌ ' + ((err && err.message) || 'Cleanup failed'));
                });
        });
    }

    // 6b. Hotel Feeds Sync
    var feedsSyncBtn = document.getElementById('mhbo_ai_feeds_sync');
    var feedsStatusEl = document.getElementById('mhbo_ai_feeds_status_msg');
    if (feedsSyncBtn && apiFetch) {
        feedsSyncBtn.addEventListener('click', function(e) {
            e.preventDefault();
            feedsSyncBtn.disabled = true;
            setButtonLabel(feedsSyncBtn, 'Generating Feeds…');
            if (feedsStatusEl) feedsStatusEl.textContent = '';
            apiFetch({ path: '/mhbo/v1/ai/sync-hotel-feeds', method: 'POST' })
                .then(function(res) {
                    location.reload();
                })
                .catch(function(err) {
                    feedsSyncBtn.disabled = false;
                    setButtonLabel(feedsSyncBtn, 'Regenerate Feeds Now');
                    renderAiStatus(feedsStatusEl, '#dc2626', '❌ ' + ((err && err.message) || 'Feed generation failed'));
                });
        });
    }

    // 6c. Hotel Feeds Cleanup
    var feedsCleanBtn = document.getElementById('mhbo_ai_feeds_cleanup');
    if (feedsCleanBtn && apiFetch) {
        feedsCleanBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to remove the Google Hotel feed files from your site root?')) return;
            feedsCleanBtn.disabled = true;
            feedsCleanBtn.textContent = 'Cleaning…';
            if (feedsStatusEl) feedsStatusEl.textContent = '';
            apiFetch({ path: '/mhbo/v1/ai/cleanup-hotel-feeds', method: 'POST' })
                .then(function() { location.reload(); })
                .catch(function(err) {
                    feedsCleanBtn.disabled = false;
                    feedsCleanBtn.textContent = 'Remove Feed Files';
                    renderAiStatus(feedsStatusEl, '#dc2626', '❌ ' + ((err && err.message) || 'Cleanup failed'));
                });
        });
    }

    // 7. Live Service Health, Cooldown Countdown & Clear Quota Lock
    var healthStatusEl = document.getElementById('mhbo_ai_health_status_text');
    var cooldownEl = document.getElementById('mhbo_ai_cooldown_countdown');
    var errorBadgeEl = document.getElementById('mhbo_ai_error_score_badge');
    var cooldownTimer = null;

    function startCooldownTimer(seconds) {
        if (cooldownTimer) {
            clearInterval(cooldownTimer);
            cooldownTimer = null;
        }
        if (!cooldownEl || seconds <= 0) {
            if (cooldownEl) {
                cooldownEl.textContent = '';
                cooldownEl.style.display = 'none';
                cooldownEl.setAttribute('data-remaining', '0');
            }
            return;
        }
        var rem = seconds;
        cooldownEl.style.display = 'block';
        cooldownEl.setAttribute('data-remaining', String(rem));
        cooldownEl.textContent = 'Resuming in ' + rem + ' seconds...';
        cooldownTimer = setInterval(function() {
            rem -= 1;
            if (rem <= 0) {
                clearInterval(cooldownTimer);
                cooldownTimer = null;
                refreshAiHealthStatus();
                return;
            }
            cooldownEl.setAttribute('data-remaining', String(rem));
            cooldownEl.textContent = 'Resuming in ' + rem + ' seconds...';
        }, 1000);
    }

    function updateAiHealthCard(data) {
        if (!data || typeof data.error_score === 'undefined') return;
        var score = parseInt(data.error_score, 10) || 0;
        var threshold = parseInt(data.threshold, 10) || 3;
        var cooling = !!data.is_cooling;
        var remaining = parseInt(data.remaining_seconds, 10) || 0;

        if (errorBadgeEl) {
            errorBadgeEl.textContent = 'Error Score: ' + score + ' / ' + threshold;
        }

        if (healthStatusEl) {
            var labelText = '✅ Operational';
            var color = '#16a34a';
            if (cooling) {
                labelText = '❄️ Cooling Down (Demand Spike)';
                color = '#ef4444';
            } else if (score > 0) {
                labelText = '⚠️ High Demand (Degraded)';
                color = '#f59e0b';
            }
            healthStatusEl.style.color = color;
            var firstNode = healthStatusEl.firstChild;
            if (firstNode && firstNode.nodeType === 3) {
                firstNode.nodeValue = labelText + ' ';
            } else {
                healthStatusEl.insertBefore(document.createTextNode(labelText + ' '), healthStatusEl.firstChild);
            }
        }

        startCooldownTimer(cooling ? remaining : 0);
    }

    function refreshAiHealthStatus() {
        if (!apiFetch || (!healthStatusEl && !errorBadgeEl)) return;
        apiFetch({ path: '/mhbo/v1/ai/health-status', method: 'GET' })
            .then(function(res) {
                updateAiHealthCard(res);
            })
            .catch(function() {});
    }

    if (cooldownEl) {
        var initialRemaining = parseInt(cooldownEl.getAttribute('data-remaining') || '0', 10);
        if (initialRemaining > 0) {
            startCooldownTimer(initialRemaining);
        }
    }

    var clearLockBtn = document.getElementById('mhbo_ai_clear_lock');
    var clearLockResult = document.getElementById('mhbo_ai_clear_lock_result');
    if (clearLockBtn && apiFetch) {
        clearLockBtn.addEventListener('click', function(e) {
            e.preventDefault();
            clearLockBtn.disabled = true;
            clearLockBtn.textContent = 'Clearing…';
            if (clearLockResult) clearLockResult.textContent = '';
            apiFetch({ path: '/mhbo/v1/ai/clear-lock', method: 'POST' })
                .then(function(res) {
                    clearLockBtn.disabled = false;
                    clearLockBtn.textContent = 'Engine Reset ✅';
                    updateAiHealthCard(res);
                    setTimeout(function() {
                        clearLockBtn.textContent = 'Reset Engine Lock';
                    }, 2000);
                })
                .catch(function(err) {
                    clearLockBtn.disabled = false;
                    clearLockBtn.textContent = 'Reset Engine Lock';
                    renderAiStatus(clearLockResult, '#dc2626', '❌ ' + ((err && err.message) || 'Failed to clear lock'));
                });
        });
    }

    // 8. Provider Row Toggles & Model Preset Sync
    var providerSelect = document.getElementById('mhbo_ai_provider');
    var apiKeyWrapper = document.getElementById('mhbo_ai_api_key_wrapper');
    var apiKeyInput = document.getElementById('mhbo_ai_api_key');
    var primaryModelRow = document.getElementById('mhbo_primary_model_row');
    var discoverWrap = document.getElementById('mhbo_ai_discover_models_wrap');
    var connectorInfo = document.getElementById('mhbo_wp_connector_info');
    var modelInput = document.getElementById('mhbo_ai_model_input');
    var manualWrap = document.getElementById('mhbo_manual_model_wrapper');
    var customUrlRow = document.getElementById('mhbo_custom_url_row');
    var customUrlInput = document.getElementById('mhbo_ai_custom_url');
    var reasoningRow = document.getElementById('mhbo_ai_reasoning_effort_row');

    var presetProviders = ['wp_connector', 'gemini', 'openai', 'anthropic', 'openrouter', 'ollama'];

    function getActiveModelSelect(p) {
        if (presetProviders.indexOf(p) === -1) return null;
        return document.getElementById('mhbo_ai_' + p + '_model_select');
    }

    function syncProviderModels() {
        if (!providerSelect) return;
        var p = providerSelect.value;

        if (apiKeyWrapper) {
            apiKeyWrapper.style.display = (p === 'wp_connector' || p === 'ollama' || p === '') ? 'none' : 'block';
        }
        if (primaryModelRow) {
            primaryModelRow.style.display = (p === '') ? 'none' : 'block';
        }
        if (discoverWrap) {
            discoverWrap.style.display = (p === '' || p === 'custom') ? 'none' : 'flex';
        }
        if (connectorInfo) {
            connectorInfo.style.display = (p === 'wp_connector') ? 'block' : 'none';
        }
        presetProviders.forEach(function(key) {
            var wrap = document.getElementById('mhbo_' + key + '_model_wrapper');
            if (wrap) wrap.style.display = (p === key) ? 'block' : 'none';
        });
        if (customUrlRow) {
            customUrlRow.style.display = (p === 'ollama' || p === 'custom') ? 'block' : 'none';
        }
        if (customUrlInput) {
            customUrlInput.placeholder = (p === 'ollama')
                ? 'http://localhost:11434/v1/chat/completions'
                : 'https://api.example.com/v1/chat/completions';
        }
        if (reasoningRow) {
            reasoningRow.style.display = (p === 'wp_connector' || p === 'gemini' || p === 'openai' || p === 'anthropic' || p === 'openrouter') ? 'block' : 'none';
        }

        var activeSelect = getActiveModelSelect(p);
        if (manualWrap) {
            if (p === '') {
                manualWrap.style.display = 'none';
                if (modelInput) modelInput.value = '';
            } else if (activeSelect && activeSelect.value !== 'custom') {
                manualWrap.style.display = 'none';
                if (modelInput) modelInput.value = activeSelect.value;
            } else {
                manualWrap.style.display = 'block';
                if (modelInput && activeSelect && activeSelect.value === 'custom') {
                    var savedP = modelInput.getAttribute('data-saved-provider') || '';
                    var savedM = modelInput.getAttribute('data-saved-model') || '';
                    if (p === savedP && savedM) {
                        modelInput.value = savedM;
                    }
                }
            }
        }
    }

    if (providerSelect) {
        providerSelect.addEventListener('change', syncProviderModels);
        syncProviderModels();
    }

    presetProviders.forEach(function(key) {
        var sel = document.getElementById('mhbo_ai_' + key + '_model_select');
        if (sel) {
            sel.addEventListener('change', function() {
                if (sel.value === 'custom') {
                    if (manualWrap) manualWrap.style.display = 'block';
                    if (modelInput) {
                        var savedP = modelInput.getAttribute('data-saved-provider') || '';
                        var savedM = modelInput.getAttribute('data-saved-model') || '';
                        modelInput.value = (providerSelect && providerSelect.value === savedP) ? savedM : '';
                        modelInput.focus();
                    }
                } else {
                    if (manualWrap) manualWrap.style.display = 'none';
                    if (modelInput) modelInput.value = sel.value;
                }
            });
        }
    });

    function populateModelPresetSelect(selectEl, presets, targetVal, autoSelectFirst) {
        if (!selectEl) return;
        while (selectEl.firstChild) {
            selectEl.removeChild(selectEl.firstChild);
        }
        var matched = false;
        if (presets && Object.keys(presets).length > 0) {
            Object.keys(presets).forEach(function(mId) {
                var opt = document.createElement('option');
                opt.value = mId;
                opt.textContent = presets[mId];
                if (targetVal && mId === targetVal) {
                    opt.selected = true;
                    matched = true;
                }
                selectEl.appendChild(opt);
            });
        }
        var customOpt = document.createElement('option');
        customOpt.value = 'custom';
        customOpt.textContent = '— Manual Override / Custom —';
        if (!matched && targetVal && targetVal !== 'auto') {
            customOpt.selected = true;
        }
        selectEl.appendChild(customOpt);
        if (autoSelectFirst && !targetVal && selectEl.options.length > 0) {
            selectEl.options[0].selected = true;
        }
    }

    var discoverBtn = document.getElementById('mhbo_ai_discover_models_btn');
    var discoverResult = document.getElementById('mhbo_ai_discover_models_result');
    if (discoverBtn && apiFetch) {
        discoverBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var p = providerSelect ? providerSelect.value : 'gemini';
            var k = (apiKeyInput && apiKeyInput.value !== 'REDACTED') ? apiKeyInput.value : '';
            discoverBtn.disabled = true;
            if (discoverResult) {
                discoverResult.style.color = '#64748b';
                discoverResult.textContent = 'Querying provider catalog…';
            }
            apiFetch({
                path: '/mhbo/v1/ai/discover-models',
                method: 'POST',
                data: { provider: p, api_key: k, custom_url: getFieldVal('mhbo_ai_custom_url') }
            })
            .then(function(res) {
                discoverBtn.disabled = false;
                var disc = res && res.discovered ? res.discovered : {};
                if (discoverResult) {
                    discoverResult.style.color = disc.success ? '#16a34a' : '#d97706';
                    discoverResult.textContent = disc.message || 'Presets updated.';
                }
                var activeSel = getActiveModelSelect(p);
                if (activeSel && res && res.presets) {
                    var currentVal = modelInput ? modelInput.value : activeSel.value;
                    populateModelPresetSelect(activeSel, res.presets, currentVal, false);
                    syncProviderModels();
                }
                
            })
            .catch(function(err) {
                discoverBtn.disabled = false;
                if (discoverResult) {
                    discoverResult.style.color = '#dc2626';
                    discoverResult.textContent = (err && err.message) || 'Discovery failed.';
                }
            });
        });
    }

// 11. Live Brand Accent Color Hex Preview
    var colorInput = document.getElementById('mhbo_ai_accent_color');
    var colorHexEl = document.getElementById('mhbo_ai_accent_color_hex');
    if (colorInput && colorHexEl) {
        colorInput.addEventListener('input', function() {
            colorHexEl.textContent = String(colorInput.value || '').toUpperCase();
        });
    }

    // 12. Accessible Non-Blocking Clipboard Copy Buttons
    function copyTextToClipboard(text, onDone) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(text).then(onDone).catch(function() {
                fallbackCopyText(text, onDone);
            });
            return;
        }
        fallbackCopyText(text, onDone);
    }

    function fallbackCopyText(text, onDone) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            onDone();
        } catch (err) {}
        document.body.removeChild(ta);
    }

    document.querySelectorAll('[data-mhbo-copy-target], [data-mhbo-copy-value]').forEach(function(copyBtn) {
        copyBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var targetId = copyBtn.getAttribute('data-mhbo-copy-target');
            var textToCopy = targetId
                ? getFieldVal(targetId)
                : (copyBtn.getAttribute('data-mhbo-copy-value') || '');
            if (!textToCopy) return;
            var span = copyBtn.querySelector('span:not(.dashicons)');
            var origLabel = copyBtn.getAttribute('data-mhbo-orig-label') || (span ? span.textContent : 'Copy URL');
            copyBtn.setAttribute('data-mhbo-orig-label', origLabel);
            var copiedLabel = copyBtn.getAttribute('data-mhbo-copied-label') || 'Copied!';
            copyTextToClipboard(textToCopy, function() {
                setButtonLabel(copyBtn, copiedLabel);
                setTimeout(function() {
                    setButtonLabel(copyBtn, origLabel);
                }, 2000);
            });
        });
    });
});
";
	}
}
