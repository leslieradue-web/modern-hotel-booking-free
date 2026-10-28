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
use WP_REST_Request;
use WP_REST_Response;

class AiSettings {

	private const OPTION_GROUP = 'mhbo_ai_settings';
	private const NONCE_SAVE   = 'mhbo_ai_save_settings';
	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Register hooks. Called from the AI Loader.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
		
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
	 * REST Callback: Test Connection.
	 */
	public static function rest_test_connection( WP_REST_Request $request ): WP_REST_Response {
		$start        = microtime( true );
		$diag_timeout = 12;
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
			$response = Client::prompt(
				array(
					array(
						'role'    => 'user',
						'content' => 'Reply with only the word: CONNECTED',
					),
				),
				'You are a connection test bot. Reply with exactly one word: CONNECTED.',
				array()
			);
		} finally {
			remove_filter( 'http_request_args', $short_http, 9999 );
			remove_filter( 'wp_ai_client_default_request_timeout', $short_ai, 9999 );
		}

		$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( $response['error'] ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'message'    => $response['error'],
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
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$api_key  = sanitize_text_field( (string) $request->get_param( 'api_key' ) );

		if ( ! $provider ) {
			$provider = (string) get_option( 'mhbo_ai_provider', 'gemini' );
		}
		if ( ! $api_key ) {
			$api_key = (string) get_option( 'mhbo_ai_api_key', '' );
		}

		$discovered = ModelCatalog::discover_live_models( $provider, $api_key );
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
	 * REST Callback: Clear Lock.
	 */
	public static function rest_clear_lock( WP_REST_Request $request ): WP_REST_Response {
		\delete_transient( 'mhbo_ai_quota_lock' );
		\delete_transient( 'mhbo_ai_error_score' );

		// Clear model-specific failure flags — must match get_dynamic_fallback_chain() list.
		$models_to_clear = array(
			'gemini-3.8-flash',              // GA frontier (October 2026)
			'gemini-4-argon',                // GA supreme agentic reasoning flagship
			'gemini-3.8-pro',                // GA supreme reasoning flagship
			'gemini-3.7-flash',              // GA high-efficiency workhorse
			'gemini-3.6-flash',              // GA balanced workhorse
			'gemini-3.5-flash',              // GA legacy fast
			'gemini-3.5-flash-lite',         // GA ultralight
			'gemini-3.1-flash-lite',         // GA stable
			'gemini-3-flash-preview',        // Preview (Computer Use)
			'gemini-3.1-pro-preview',        // Preview flagship
			'gemini-2.5-flash-lite',         // Legacy
			'gemini-2.5-flash',              // Legacy
		);
		foreach ( $models_to_clear as $m ) {
			\delete_transient( "mhbo_ai_model_fail_{$m}" );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => \__( 'AI Resilience Engine reset. All failure scores and cooldowns cleared.', 'modern-hotel-booking' ),
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
			'mhbo_ai_custom_instructions',
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
			
			'mhbo_ai_discovery_enabled',
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
		$int_opts = array(
			'mhbo_ai_proactive_trigger_seconds',
			'mhbo_ai_context_budget',
		);
		foreach ( $opts as $opt ) {
			if ( in_array( $opt, $url_opts, true ) ) {
				$callback = 'esc_url_raw';
			} elseif ( in_array( $opt, $int_opts, true ) ) {
				$callback = 'absint';
			} else {
				$callback = 'sanitize_text_field';
			}
			register_setting( self::OPTION_GROUP, $opt, array( 'sanitize_callback' => $callback ) );
		}
		register_setting( self::OPTION_GROUP, 'mhbo_ai_custom_instructions', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( self::OPTION_GROUP, 'mhbo_ai_local_guide', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
		
	}

	/**
	 * Enqueue admin JS/CSS only on the MHBO settings page, AI tab.
	 *
	 * @param string $hook
	 */
	public static function enqueue_assets( string $hook ): void {
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

		// Handle form save.
		if ( isset( $_POST['mhbo_ai_save'] ) ) {
			$nonce = isset( $_POST['mhbo_ai_nonce'] ) ? sanitize_key( wp_unslash( $_POST['mhbo_ai_nonce'] ) ) : '';
			if ( wp_verify_nonce( $nonce, self::NONCE_SAVE ) ) {
				self::handle_save();
			}
		}

		$provider = (string) get_option( 'mhbo_ai_provider', 'gemini' );
		$api_key  = (string) get_option( 'mhbo_ai_api_key', '' );
		$model    = (string) get_option( 'mhbo_ai_model', 'gemini-3.8-flash' );
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
											<p class="mhbo-switch-desc"><?php esc_html_e( 'Stream AI tokens in real-time with live typing animations and thinking indicators via Server-Sent Events (SSE).', 'modern-hotel-booking' ); ?></p>
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
									$err_score  = (int) \get_transient( 'mhbo_ai_error_score' );
									$quota_lock = (int) \get_transient( 'mhbo_ai_quota_lock' );
									$is_cooling = $quota_lock > \time();
									$remaining  = $is_cooling ? ( $quota_lock - \time() ) : 0;
									
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
										<span style="font-size: 13.5px; font-weight: 600; color: <?php echo esc_attr( $status_color ); ?>;">
											<?php echo esc_html( $status_icon ); ?> <?php echo esc_html( $status_label ); ?>
											<?php if ( $is_cooling ) : ?>
												<small style="color: #64748b; font-weight: 400; display: block; margin-top: 2px;">
													<?php
													// translators: %1$d: seconds remaining before AI concierge resumes
													printf( esc_html__( 'Resuming in %1$d seconds...', 'modern-hotel-booking' ), (int) $remaining );
													?>
												</small>
											<?php endif; ?>
										</span>
										<span class="mhbo-rate-badge" style="font-size: 11.5px; font-weight: 700; background: #e2e8f0; color: #334155; padding: 2px 8px; border-radius: 4px;">
											<?php esc_html_e( 'Error Score:', 'modern-hotel-booking' ); ?> <?php echo (int) $err_score; ?> / 10
										</span>
									</div>
									<div style="display: flex; align-items: center; gap: 10px;">
										<button type="button" id="mhbo_ai_clear_lock" class="button button-secondary">
											<?php esc_html_e( 'Reset Engine Lock', 'modern-hotel-booking' ); ?>
										</button>
										<p class="mhbo-form-help" style="margin: 0;"><?php esc_html_e( 'Manually clear demand cooldown.', 'modern-hotel-booking' ); ?></p>
									</div>
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
								<div style="background: #f0fdf4; border-left: 4px solid #16a34a; padding: 14px 18px; border-radius: 6px; margin-bottom: 20px;">
									<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
										<div>
											<strong style="color: #166534; font-size: 13.5px;">✅ <?php esc_html_e( 'WordPress 7.0+ AI Client Detected', 'modern-hotel-booking' ); ?></strong>
											<p style="margin: 4px 0 0; color: #14532d; font-size: 12.5px;"><?php esc_html_e( 'Using site-wide AI provider from Settings → Connectors. Direct plugin API credentials below serve as resilient automatic fallback.', 'modern-hotel-booking' ); ?></p>
										</div>
										<button type="button" id="mhbo_ai_test_connector_btn" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
											<span class="dashicons dashicons-rest-api" style="margin-top:2px;"></span>
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

								<div class="mhbo-form-group" id="mhbo_ai_api_key_wrapper">
									<label class="mhbo-form-label" for="mhbo_ai_api_key"><?php esc_html_e( 'API Key', 'modern-hotel-booking' ); ?></label>
									<input type="password" name="mhbo_ai_api_key" id="mhbo_ai_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="mhbo-form-input" autocomplete="new-password" placeholder="AIzaSy... / sk-...">
									<p class="mhbo-form-help"><?php esc_html_e( 'Your private API secret key. Never shared or exposed in client frontend code.', 'modern-hotel-booking' ); ?></p>
								</div>

								<div class="mhbo-form-group mhbo-span-full">
									<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 6px;">
										<label class="mhbo-form-label" style="margin: 0;"><?php esc_html_e( 'Active AI Model Selection', 'modern-hotel-booking' ); ?></label>
										<div style="display: flex; align-items: center; gap: 8px;">
											<span id="mhbo_ai_discover_models_result" style="font-size: 12px; font-weight: 600;"></span>
											<button type="button" id="mhbo_ai_discover_models_btn" class="button button-secondary" style="font-size: 12px; min-height: 28px; line-height: 26px; padding: 0 10px;">
												<span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span>
												<span><?php esc_html_e( 'Discover Live Models', 'modern-hotel-booking' ); ?></span>
											</button>
										</div>
									</div>

									<div id="mhbo_wp_connector_model_wrapper" style="display: <?php echo ( $provider === 'wp_connector' ) ? 'block' : 'none'; ?>; padding: 12px 16px; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px; margin-bottom: 8px;">
										<p style="margin: 0; color: #166534; font-size: 13px;">
											<strong>🌐 <?php esc_html_e( 'Automated Model Resolution Active', 'modern-hotel-booking' ); ?>:</strong>
											<?php esc_html_e( 'The WordPress AI Connector selects and routes requests to connected providers configured in Settings → Connectors.', 'modern-hotel-booking' ); ?>
										</p>
									</div>
									
									<div id="mhbo_gemini_model_wrapper" class="mhbo-provider-model-wrapper" style="display: <?php echo ( $provider === 'gemini' ) ? 'block' : 'none'; ?>;">
										<?php
										$gemini_presets = ModelCatalog::get_presets( Client::PROVIDER_GEMINI );
										$gemini_models  = array_merge(
											$gemini_presets,
											array( 'custom' => __( '— Manual Override / Custom —', 'modern-hotel-booking' ) )
										);
										$is_gemini_preset  = array_key_exists( $model, $gemini_models ) && $model !== 'custom';
										$gemini_preset_val = $is_gemini_preset ? $model : 'custom';
										?>
										<select id="mhbo_ai_gemini_model_select" class="mhbo-form-input" style="margin-bottom: 8px;">
											<?php foreach ( $gemini_models as $val => $label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $gemini_preset_val, $val ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>

									<div id="mhbo_openai_model_wrapper" class="mhbo-provider-model-wrapper" style="display: <?php echo ( $provider === 'openai' ) ? 'block' : 'none'; ?>;">
										<?php
										$openai_presets = ModelCatalog::get_presets( Client::PROVIDER_OPENAI );
										$openai_models  = array_merge(
											$openai_presets,
											array( 'custom' => __( '— Manual Override / Custom —', 'modern-hotel-booking' ) )
										);
										$is_openai_preset  = array_key_exists( $model, $openai_models ) && $model !== 'custom';
										$openai_preset_val = $is_openai_preset ? $model : 'custom';
										?>
										<select id="mhbo_ai_openai_model_select" class="mhbo-form-input" style="margin-bottom: 8px;">
											<?php foreach ( $openai_models as $val => $label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $openai_preset_val, $val ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>

									<div id="mhbo_anthropic_model_wrapper" class="mhbo-provider-model-wrapper" style="display: <?php echo ( $provider === 'anthropic' ) ? 'block' : 'none'; ?>;">
										<?php
										$anthropic_presets = ModelCatalog::get_presets( Client::PROVIDER_ANTHROPIC );
										$anthropic_models  = array_merge(
											$anthropic_presets,
											array( 'custom' => __( '— Manual Override / Custom —', 'modern-hotel-booking' ) )
										);
										$is_anthropic_preset  = array_key_exists( $model, $anthropic_models ) && $model !== 'custom';
										$anthropic_preset_val = $is_anthropic_preset ? $model : 'custom';
										?>
										<select id="mhbo_ai_anthropic_model_select" class="mhbo-form-input" style="margin-bottom: 8px;">
											<?php foreach ( $anthropic_models as $val => $label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $anthropic_preset_val, $val ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>

									<div id="mhbo_openrouter_model_wrapper" class="mhbo-provider-model-wrapper" style="display: <?php echo ( $provider === 'openrouter' ) ? 'block' : 'none'; ?>;">
										<?php
										$openrouter_presets = ModelCatalog::get_presets( Client::PROVIDER_OPENROUTER );
										$openrouter_models  = array_merge(
											$openrouter_presets,
											array( 'custom' => __( '— Manual Override / Custom —', 'modern-hotel-booking' ) )
										);
										$is_openrouter_preset  = array_key_exists( $model, $openrouter_models ) && $model !== 'custom';
										$openrouter_preset_val = $is_openrouter_preset ? $model : 'custom';
										?>
										<select id="mhbo_ai_openrouter_model_select" class="mhbo-form-input" style="margin-bottom: 8px;">
											<?php foreach ( $openrouter_models as $val => $label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $openrouter_preset_val, $val ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>

									<div id="mhbo_ollama_model_wrapper" class="mhbo-provider-model-wrapper" style="display: <?php echo ( $provider === 'ollama' ) ? 'block' : 'none'; ?>;">
										<?php
										$ollama_presets = ModelCatalog::get_presets( Client::PROVIDER_OLLAMA );
										$ollama_models  = array_merge(
											$ollama_presets,
											array( 'custom' => __( '— Manual Override / Custom —', 'modern-hotel-booking' ) )
										);
										$is_ollama_preset  = array_key_exists( $model, $ollama_models ) && $model !== 'custom';
										$ollama_preset_val = $is_ollama_preset ? $model : 'custom';
										?>
										<select id="mhbo_ai_ollama_model_select" class="mhbo-form-input" style="margin-bottom: 8px;">
											<?php foreach ( $ollama_models as $val => $label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $ollama_preset_val, $val ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>

									<div id="mhbo_manual_model_wrapper" style="display: 
									<?php 
										$any_preset = (
											( $provider === 'gemini' && $is_gemini_preset ) ||
											( $provider === 'openai' && $is_openai_preset ) ||
											( $provider === 'anthropic' && $is_anthropic_preset ) ||
											( $provider === 'openrouter' && $is_openrouter_preset ) ||
											( $provider === 'ollama' && $is_ollama_preset ) ||
											( $provider === 'wp_connector' )
										);
										echo ( ! $any_preset ) ? 'block' : 'none'; 
									?>
									;">
										<input type="text" name="mhbo_ai_model" id="mhbo_ai_model_input" value="<?php echo esc_attr( $model ); ?>" class="mhbo-form-input" placeholder="e.g. gemini-3.8-flash / gemini-4-argon / gpt-6.1-sol / claude-sonnet-5-5">
										<p class="mhbo-form-help"><?php esc_html_e( 'Enter a specific model identifier if not listed in the presets above.', 'modern-hotel-booking' ); ?></p>
									</div>
								</div>

<div class="mhbo-form-group" id="mhbo_custom_url_row" style="display:<?php echo in_array( $provider, array( 'ollama', 'custom' ), true ) ? 'block' : 'none'; ?>;">
									<label class="mhbo-form-label" for="mhbo_ai_custom_url"><?php esc_html_e( 'Custom Endpoint Base URL', 'modern-hotel-booking' ); ?></label>
									<input type="url" name="mhbo_ai_custom_url" id="mhbo_ai_custom_url" value="<?php echo esc_url( $custom_url ); ?>" class="mhbo-form-input" placeholder="https://api.example.com/v1">
									<p class="mhbo-form-help"><?php esc_html_e( 'Base URL for custom or local OpenAI-compatible inference servers.', 'modern-hotel-booking' ); ?></p>
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
										<code style="background: #f1f5f9; color: #334155; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 13px;"><?php echo esc_html( strtoupper( $accent_color ) ); ?></code>
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
										<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(document.getElementById('mhbo_mcp_endpoint_input').value); alert('<?php echo esc_js( __( 'MCP URL copied to clipboard!', 'modern-hotel-booking' ) ); ?>');">
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
										<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(document.getElementById('mhbo_openapi_endpoint_input').value); alert('<?php echo esc_js( __( 'OpenAPI URL copied to clipboard!', 'modern-hotel-booking' ) ); ?>');">
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

				<div class="mhbo-form-actions-dock" style="margin-top: 24px; max-width: 1200px;">
					<?php submit_button( __( 'Apply AI Configuration', 'modern-hotel-booking' ), 'primary large', 'submit', false, array( 'style' => 'height: 42px; padding: 0 28px; font-weight: 600;' ) ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Save Handler
	// -------------------------------------------------------------------------

	private static function handle_save(): void {
		if ( ! isset( $_POST['mhbo_ai_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mhbo_ai_nonce'] ) ), self::NONCE_SAVE ) ) {
			return;
		}
		if ( ! self::check_rest_permission() ) {
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
				'mhbo_ai_reasoning_effort',
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
				'mhbo_ai_theme',
				'mhbo_ai_proactive_trigger_seconds',
				'mhbo_ai_mcp_enabled',
				'mhbo_ai_voice_input_enabled',
				'mhbo_ai_voice_output_enabled',
				'mhbo_ai_elevenlabs_key',
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
		$post_data = wp_unslash( $_POST );

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
				$val_str = (string) $raw_val;
				if ( 'mhbo_ai_proactive_trigger_seconds' === $key ) {
					update_option( $key, absint( $val_str ) );
				} elseif ( 'mhbo_ai_context_budget' === $key ) {
					update_option( $key, max( 1000, min( 10000, absint( $val_str ) ) ) );
				} elseif ( in_array( $key, array( 'mhbo_ai_sitemap_url', 'mhbo_ai_custom_url', 'mhbo_ai_fallback_custom_url' ), true ) ) {
					update_option( $key, esc_url_raw( $val_str, array( 'http', 'https' ) ) );
				} else {
					update_option( $key, sanitize_text_field( $val_str ) );
				}
			}
		}

		if ( 'general' === $active_tab ) {
			Client::flush_native_availability();
		}

add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'AI Concierge settings saved.', 'modern-hotel-booking' ) . '</p></div>';
			} 
		);
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
		global $q_config;
		if ( isset( $q_config['language'] ) && '' !== $q_config['language'] ) {
			$qlang = (string) $q_config['language'];
			/** @var string $qlocale */
			$qlocale = $q_config['locale'][ $qlang ] ?? $qlang;
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

    // 1. Knowledge Base Sync
    var kbBtn = document.getElementById('mhbo_ai_kb_refresh');
    var kbResult = document.getElementById('mhbo_ai_kb_result');
    if (kbBtn && apiFetch) {
        kbBtn.addEventListener('click', function(e) {
            e.preventDefault();
            kbBtn.disabled = true;
            kbBtn.textContent = 'Syncing…';
            if (kbResult) kbResult.textContent = '';
            apiFetch({ path: '/mhbo/v1/ai/sync-kb', method: 'POST' })
                .then(function(res) {
                    location.reload();
                })
                .catch(function(err) {
                    kbBtn.disabled = false;
                    kbBtn.textContent = 'Sync Now';
                    if (kbResult) {
                        kbResult.innerHTML = '<span style=\"color:#dc2626; font-weight:600;\">✗ ' + (err.message || 'Sync failed') + '</span>';
                    }
                });
        });
    }

    // 2. Primary Connection Test
    var testBtn = document.getElementById('mhbo_ai_test_btn');
    var testResult = document.getElementById('mhbo_ai_test_result');
    if (testBtn && apiFetch) {
        testBtn.addEventListener('click', function(e) {
            e.preventDefault();
            testBtn.disabled = true;
            testBtn.textContent = 'Testing…';
            if (testResult) testResult.textContent = '';
            apiFetch({ path: '/mhbo/v1/ai/test-connection', method: 'POST' })
                .then(function(res) {
                    testBtn.disabled = false;
                    testBtn.textContent = 'Test Connection';
                    if (testResult) {
                        var timing = res.latency_ms ? ' (' + res.latency_ms + 'ms)' : '';
                        testResult.innerHTML = '<span style=\"color:#16a34a; font-weight:600;\">✅ ' + (res.message || 'Connected!') + timing + '</span>';
                    }
                })
                .catch(function(err) {
                    testBtn.disabled = false;
                    testBtn.textContent = 'Test Connection';
                    if (testResult) {
                        testResult.innerHTML = '<span style=\"color:#dc2626; font-weight:600;\">❌ ' + (err.message || 'Connection failed') + '</span>';
                    }
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
            connBtn.textContent = 'Testing Connector…';
            if (connResult) connResult.innerHTML = '<span style=\"color:#64748b;\">Connecting to WordPress AI Connector…</span>';
            apiFetch({ path: '/mhbo/v1/ai/test-connector', method: 'POST' })
                .then(function(res) {
                    connBtn.disabled = false;
                    connBtn.textContent = 'Test WordPress AI Connector';
                    if (connResult) {
                        var prov = res.provider ? ' (' + res.provider + ' / ' + res.model + ', ' + res.latency_ms + 'ms)' : '';
                        connResult.innerHTML = '<span style=\"color:#16a34a; font-weight:600;\">✅ ' + (res.message || 'Connected!') + prov + '</span>';
                    }
                })
                .catch(function(err) {
                    connBtn.disabled = false;
                    connBtn.textContent = 'Test WordPress AI Connector';
                    if (connResult) {
                        connResult.innerHTML = '<span style=\"color:#dc2626; font-weight:600;\">❌ ' + (err.message || 'Connector test failed') + '</span>';
                    }
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
            mcpBtn.textContent = 'Probing…';
            if (mcpResult) mcpResult.textContent = '';
            apiFetch({ path: '/mhbo/v1/ai/mcp-status', method: 'POST' })
            .then(function(res) {
                mcpBtn.disabled = false;
                mcpBtn.textContent = 'Probe MCP Endpoint';
                if (res && res.success) {
                    if (mcpResult) mcpResult.innerHTML = '<span style=\"color:#16a34a; font-weight:600;\">✅ ' + (res.message || 'Connected!') + '</span>';
                } else {
                    if (mcpResult) mcpResult.innerHTML = '<span style=\"color:#f59e0b; font-weight:600;\">⚠️ ' + (res.message || 'MCP Adapter required') + '</span>';
                }
            })
            .catch(function(err) {
                mcpBtn.disabled = false;
                mcpBtn.textContent = 'Probe MCP Endpoint';
                if (mcpResult) mcpResult.innerHTML = '<span style=\"color:#dc2626; font-weight:600;\">❌ ' + (err.message || 'Probe failed') + '</span>';
            });
        });
    }

    // 5. Discovery Sync
    var discSyncBtn = document.getElementById('mhbo_ai_discovery_sync');
    if (discSyncBtn && apiFetch) {
        discSyncBtn.addEventListener('click', function(e) {
            e.preventDefault();
            discSyncBtn.disabled = true;
            discSyncBtn.textContent = 'Syncing…';
            apiFetch({ path: '/mhbo/v1/ai/sync-discovery', method: 'POST' })
                .then(function() { location.reload(); })
                .catch(function(err) {
                    discSyncBtn.disabled = false;
                    discSyncBtn.textContent = 'Sync Discovery';
                    alert((err && err.message) || 'Sync failed');
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
            apiFetch({ path: '/mhbo/v1/ai/cleanup-discovery', method: 'POST' })
                .then(function() { location.reload(); })
                .catch(function(err) {
                    discCleanBtn.disabled = false;
                    discCleanBtn.textContent = 'Remove Files';
                });
        });
    }

    // 6b. Hotel Feeds Sync
    var feedsSyncBtn = document.getElementById('mhbo_ai_feeds_sync');
    if (feedsSyncBtn && apiFetch) {
        feedsSyncBtn.addEventListener('click', function(e) {
            e.preventDefault();
            feedsSyncBtn.disabled = true;
            feedsSyncBtn.textContent = 'Generating Feeds…';
            apiFetch({ path: '/mhbo/v1/ai/sync-hotel-feeds', method: 'POST' })
                .then(function(res) {
                    location.reload();
                })
                .catch(function(err) {
                    feedsSyncBtn.disabled = false;
                    feedsSyncBtn.textContent = 'Regenerate Now';
                    alert((err && err.message) || 'Feed generation failed');
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
            apiFetch({ path: '/mhbo/v1/ai/cleanup-hotel-feeds', method: 'POST' })
                .then(function() { location.reload(); })
                .catch(function(err) {
                    feedsCleanBtn.disabled = false;
                    feedsCleanBtn.textContent = 'Remove Feed Files';
                });
        });
    }

    // 7. Clear Quota Lock
    var clearLockBtn = document.getElementById('mhbo_ai_clear_lock');
    if (clearLockBtn && apiFetch) {
        clearLockBtn.addEventListener('click', function(e) {
            e.preventDefault();
            clearLockBtn.disabled = true;
            clearLockBtn.textContent = 'Clearing…';
            apiFetch({ path: '/mhbo/v1/ai/clear-lock', method: 'POST' })
                .then(function() {
                    clearLockBtn.disabled = false;
                    clearLockBtn.textContent = 'Engine Reset ✅';
                    setTimeout(function() { location.reload(); }, 1200);
                })
                .catch(function(err) {
                    clearLockBtn.disabled = false;
                    clearLockBtn.textContent = 'Reset Engine Lock';
                    alert((err && err.message) || 'Failed to clear lock');
                });
        });
    }

    // 8. Create Analytics Table
    var createTableBtn = document.getElementById('mhbo_ai_create_table_btn');
    if (createTableBtn && apiFetch) {
        createTableBtn.addEventListener('click', function(e) {
            e.preventDefault();
            createTableBtn.disabled = true;
            createTableBtn.textContent = 'Creating…';
            apiFetch({ path: '/mhbo/v1/ai/create-analytics-table', method: 'POST' })
                .then(function() { location.reload(); })
                .catch(function(err) {
                    createTableBtn.disabled = false;
                    createTableBtn.textContent = 'Create Table';
                    alert((err && err.message) || 'Table creation failed');
                });
        });
    }

    // 9. Provider Row Toggles & Model Preset Sync
    var providerSelect = document.getElementById('mhbo_ai_provider');
    var apiKeyInput = document.getElementById('mhbo_ai_api_key');
    var modelInput = document.getElementById('mhbo_ai_model_input');
    var connectorWrap = document.getElementById('mhbo_wp_connector_model_wrapper');
    var geminiWrap = document.getElementById('mhbo_gemini_model_wrapper');
    var openaiWrap = document.getElementById('mhbo_openai_model_wrapper');
    var anthropicWrap = document.getElementById('mhbo_anthropic_model_wrapper');
    var openrouterWrap = document.getElementById('mhbo_openrouter_model_wrapper');
    var ollamaWrap = document.getElementById('mhbo_ollama_model_wrapper');
    var manualWrap = document.getElementById('mhbo_manual_model_wrapper');
    var customUrlRow = document.getElementById('mhbo_custom_url_row');
    var reasoningRow = document.getElementById('mhbo_ai_reasoning_effort_row');

    function getActiveModelSelect(p) {
        if (p === 'gemini') return document.getElementById('mhbo_ai_gemini_model_select');
        if (p === 'openai') return document.getElementById('mhbo_ai_openai_model_select');
        if (p === 'anthropic') return document.getElementById('mhbo_ai_anthropic_model_select');
        if (p === 'openrouter') return document.getElementById('mhbo_ai_openrouter_model_select');
        if (p === 'ollama') return document.getElementById('mhbo_ai_ollama_model_select');
        return null;
    }

    function syncProviderModels() {
        if (!providerSelect) return;
        var p = providerSelect.value;
        if (connectorWrap) connectorWrap.style.display = (p === 'wp_connector') ? 'block' : 'none';
        if (geminiWrap) geminiWrap.style.display = (p === 'gemini') ? 'block' : 'none';
        if (openaiWrap) openaiWrap.style.display = (p === 'openai') ? 'block' : 'none';
        if (anthropicWrap) anthropicWrap.style.display = (p === 'anthropic') ? 'block' : 'none';
        if (openrouterWrap) openrouterWrap.style.display = (p === 'openrouter') ? 'block' : 'none';
        if (ollamaWrap) ollamaWrap.style.display = (p === 'ollama') ? 'block' : 'none';
        if (customUrlRow) customUrlRow.style.display = (p === 'ollama' || p === 'custom') ? 'block' : 'none';
        if (reasoningRow) reasoningRow.style.display = (p === 'gemini' || p === 'openai' || p === 'anthropic' || p === 'openrouter') ? 'block' : 'none';

        if (p === 'wp_connector') {
            if (manualWrap) manualWrap.style.display = 'none';
            return;
        }

        var activeSelect = getActiveModelSelect(p);

        if (manualWrap) {
            if (activeSelect && activeSelect.value !== 'custom') {
                manualWrap.style.display = 'none';
                if (modelInput) modelInput.value = activeSelect.value;
            } else {
                manualWrap.style.display = 'block';
            }
        }
    }

    if (providerSelect) {
        providerSelect.addEventListener('change', syncProviderModels);
    }

    [
        'mhbo_ai_gemini_model_select',
        'mhbo_ai_openai_model_select',
        'mhbo_ai_anthropic_model_select',
        'mhbo_ai_openrouter_model_select',
        'mhbo_ai_ollama_model_select'
    ].forEach(function(id) {
        var sel = document.getElementById(id);
        if (sel) {
            sel.addEventListener('change', function() {
                if (sel.value === 'custom') {
                    if (manualWrap) manualWrap.style.display = 'block';
                    if (modelInput) modelInput.focus();
                } else {
                    if (manualWrap) manualWrap.style.display = 'none';
                    if (modelInput) modelInput.value = sel.value;
                }
            });
        }
    });

    var discoverBtn = document.getElementById('mhbo_ai_discover_models_btn');
    var discoverResult = document.getElementById('mhbo_ai_discover_models_result');
    if (discoverBtn && apiFetch) {
        discoverBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var p = providerSelect ? providerSelect.value : 'gemini';
            var k = apiKeyInput ? apiKeyInput.value : '';
            discoverBtn.disabled = true;
            if (discoverResult) {
                discoverResult.style.color = '#64748b';
                discoverResult.textContent = 'Querying provider catalog…';
            }
            apiFetch({
                path: '/mhbo/v1/ai/discover-models',
                method: 'POST',
                data: { provider: p, api_key: k }
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
                    while (activeSel.firstChild) {
                        activeSel.removeChild(activeSel.firstChild);
                    }
                    Object.keys(res.presets).forEach(function(mId) {
                        var opt = document.createElement('option');
                        opt.value = mId;
                        opt.textContent = res.presets[mId];
                        if (mId === currentVal) opt.selected = true;
                        activeSel.appendChild(opt);
                    });
                    var customOpt = document.createElement('option');
                    customOpt.value = 'custom';
                    customOpt.textContent = '— Manual Override / Custom —';
                    activeSel.appendChild(customOpt);
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

});
";
	}
}
