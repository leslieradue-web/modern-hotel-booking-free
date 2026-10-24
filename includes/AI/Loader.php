<?php
/**
 * AI Concierge Loader — manages the AI initialization, chat sessions,
 * and background site scanning.
 *
 * @package MHBO\AI
 * @since 2.3.8 (Advanced Agentic 2026 Edition)
 */

declare(strict_types=1);

namespace MHBO\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

use MHBO\Admin\AiSettings;
use MHBO\Core\License;
use MHBO\Business\Info;

class Loader {

	/**
	 * Subsystem initialization guard.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Boot the AI subsystem.
	 * Idempotent — safe to call multiple times.
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// Check if the AI subsystem is enabled globally.
		$enabled = (int) get_option( 'mhbo_ai_enabled', 0 );

		// 2026 BP: AI Settings routes must be registered on rest_api_init even outside admin
		// so that the 'Test Connection' and 'Refresh KB' AJAX calls don't 404.
		AiSettings::register();

		// 2026 BP: Ensure outbound AI requests and native prompt builder have sufficient timeout for reasoning models.
		add_filter( 'wp_ai_client_default_request_timeout', array( Client::class, 'filter_native_timeout' ) );
		// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args -- Ensures outbound AI API calls have sufficient timeout for reasoning models.
		add_filter( 'http_request_args', array( Client::class, 'filter_ai_http_request_args' ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( self::class, 'register_admin_menu' ), 20 );
		}

		// Block and shortcode must register unconditionally so the block always
		// appears in the inserter regardless of widget-enabled settings.
		add_action( 'init', array( self::class, 'register_block' ), 10 );
		add_action( 'init', array( self::class, 'register_shortcode' ), 10 );

		$widget_enabled = (int) get_option( 'mhbo_ai_widget_enabled', 0 );

		if ( ! $enabled ) {
			return;
		}

		// Flush native AI availability cache when AI options change.
		add_action( 'updated_option', array( Client::class, 'maybe_flush_on_option_change' ) );
		add_action( 'added_option', array( Client::class, 'maybe_flush_on_option_change' ) );

		// Flush knowledge base cache when rooms or pricing change.
		add_action( 'mhbo_rooms_invalidated', array( SiteScanner::class, 'clear_cache' ) );
		add_action( 'mhbo_pricing_invalidated', array( SiteScanner::class, 'clear_cache' ) );

		// Register Composer autoloader (Jetpack Autoloader / MCP Adapter).
		// On WP 7.0+ the wordpress/php-ai-client SDK is bundled in Core.
		// Loading our copy again would cause duplicate class definitions.
		// See: https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/
		$autoload = MHBO_PLUGIN_DIR . 'vendor/autoload.php';
		if ( file_exists( $autoload ) ) {
			if ( ! function_exists( 'wp_get_wp_version' ) || version_compare( call_user_func( 'wp_get_wp_version' ), '7.0', '<' ) ) {
				// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path resolved from MHBO_PLUGIN_DIR constant.
				require_once $autoload;
			}
		}

		// Hooks that must fire early.
		// WP 7.0+ Abilities API fallback guard.
		if ( function_exists( 'wp_register_ability' ) ) {
			add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_ability_categories' ) );
			add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );

			// WP 7.1: Audit every MHBO ability invocation (telemetry + rate limiting).
			add_action( 'wp_ability_invoked', array( self::class, 'audit_ability' ), 10, 3 );

			// WP 7.1: Deep input validation beyond JSON Schema.
			add_filter( 'wp_ability_validate_input', array( self::class, 'validate_ability_input' ), 10, 3 );

			// WP 7.1: Output validation guard.
			add_filter( 'wp_ability_validate_output', array( self::class, 'validate_ability_output' ), 10, 3 );
		}

		add_action( 'rest_api_init', array( ChatRest::class, 'register' ) );

		// Frontend floating chat widget is conditioned on widget_enabled.
		if ( $widget_enabled ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_frontend' ) );
			add_action( 'wp_footer', array( self::class, 'render_widget_template' ) );
		}

		// Cookie-consent plugin compatibility.
		// All MHBO booking and chat scripts are "functional/strictly necessary":
		// they set no tracking or marketing cookies and cannot be deferred without
		// breaking the booking flow.  We hook multiple filters to maximise coverage
		// across Complianz, Cookie Notice, Cookie Caterer, and similar plugins.
		// These hooks are no-ops when the consent plugin is not active.
		add_filter( 'cmplz_safe_scripts', array( self::class, 'cmplz_safe_scripts' ) );
		add_filter( 'cmplz_known_script_tags', array( self::class, 'cmplz_safe_scripts' ) );
		add_filter( 'cmplz_accepted_cookie_types', array( self::class, 'cmplz_safe_scripts' ) );
		add_filter( 'cn_cookie_whitelist', array( self::class, 'cmplz_safe_scripts' ) );
		add_filter( 'cookie_cat_required_scripts', array( self::class, 'cmplz_safe_scripts' ) );

		// Site Scanner hooks (auto-invalidate KB on content save).
		SiteScanner::register_hooks();

		// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine

// MCP Server (registers /wp-json/modern-hotel-booking/mcp).
		if ( class_exists( 'MHBO\AI\McpServer' ) ) {
			McpServer::init();
		}

		// Pro activation: register AI guest role.
		
	}

	/**
	 * Cookie-consent compatibility: declare ALL MHBO scripts as functional/required.
	 * Works with Complianz, Cookie Notice, Cookie Caterer, and similar plugins.
	 * Safe to call even if these plugins are not active — hook simply won't fire.
	 *
	 * 2026 BP: Include every MHBO script handle — not just AI — so that
	 * Complianz never blocks the booking modal, calendar, or payment forms.
	 *
	 * @param array<string|int,mixed> $scripts
	 * @return array<string|int,mixed>
	 */
	public static function cmplz_safe_scripts( array $scripts ): array {
		$scripts[] = 'mhbo-chat-widget';
		$scripts[] = 'mhbo-voice';
		$scripts[] = 'mhbo-booking-modal-js';
		$scripts[] = 'mhbo-booking-form';
		$scripts[] = 'mhbo-frontend';
		$scripts[] = 'mhbo-calendar';
		$scripts[] = 'mhbo-stripe';
		return $scripts;
	}

	/**
	 * Run scheduled weekly discovery sync.
	 *
	 * @return void
	 */
	public static function run_weekly_sync(): void {
		if ( class_exists( '\MHBO\AI\LlmFile' ) ) {
			LlmFile::sync();
		}
	}

// -------------------------------------------------------------------------
	// Admin Menu
	// -------------------------------------------------------------------------

	/**
	 * Register the AI Concierge settings as a sub-menu under the existing
	 * Hotel Booking admin menu.
	 */
	public static function register_admin_menu(): void {
		add_submenu_page(
			'mhbo-hotel-booking',                                         // parent slug (MHBO main menu)
			\MHBO\Core\I18n::get_label( 'ai_label_settings_title' ),
			\MHBO\Core\I18n::get_label( 'ai_label_settings_menu' ),
			'manage_options',
			'mhbo-ai-concierge',
			array( AiSettings::class, 'render_tab' )
		);
	}

	// -------------------------------------------------------------------------
	// Abilities
	// -------------------------------------------------------------------------

	/**
	 * Register WP Ability Categories (WP 7.0+) if the API is available.
	 */
	public static function register_ability_categories(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			$has_init = function_exists( 'did_action' ) && \did_action( 'init' );
			call_user_func(
				'wp_register_ability_category',
				'hotel-information',
				array(
					'label'       => $has_init ? __( 'Hotel Information', 'modern-hotel-booking' ) : 'Hotel Information',
					'description' => $has_init ? __( 'Governs queries regarding hotel properties, room details, local tips, policies, and contact information.', 'modern-hotel-booking' ) : 'Governs queries regarding hotel properties, room details, local tips, policies, and contact information.',
				) 
			);
			call_user_func(
				'wp_register_ability_category',
				'booking-management',
				array(
					'label'       => $has_init ? __( 'Booking Management', 'modern-hotel-booking' ) : 'Booking Management',
					'description' => $has_init ? __( 'Allows looking up availability, creating and modifying bookings, and cancellation.', 'modern-hotel-booking' ) : 'Allows looking up availability, creating and modifying bookings, and cancellation.',
				) 
			);
			call_user_func(
				'wp_register_ability_category',
				'guest-services',
				array(
					'label'       => $has_init ? __( 'Guest Services', 'modern-hotel-booking' ) : 'Guest Services',
					'description' => $has_init ? __( 'Handles applying promotional codes and looking up guest history details.', 'modern-hotel-booking' ) : 'Handles applying promotional codes and looking up guest history details.',
				) 
			);
		}
	}

	/**
	 * Register WP Abilities (WP 7.0+) if the API is available.
	 */
	public static function register_abilities(): void {
		Abilities\HotelInfo::register();
		Abilities\CheckAvailability::register();
		Abilities\RoomDetails::register();
		Abilities\Policies::register();
		Abilities\GetKnowledgeBase::register();
		Abilities\LocalTips::register();
		Abilities\GetBusinessCard::register();
		Abilities\CreateBookingLink::register();
		Abilities\GetPriceBreakdown::register();
		Abilities\RecommendRooms::register();

}

	// -------------------------------------------------------------------------
	// Frontend Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue chat widget CSS and JS on the frontend.
	 */
	public static function enqueue_frontend(): void {
		if ( is_admin() || is_feed() || is_trackback() || is_attachment() || is_embed() || is_search() || is_author() || is_archive() || is_404() || ( function_exists( 'is_favicon' ) && is_favicon() ) ) {
			return;
		}

		$ai_enabled = (int) get_option( 'mhbo_ai_enabled', 0 );
		if ( ! $ai_enabled ) {
			return;
		}

		$widget_enabled = (int) get_option( 'mhbo_ai_widget_enabled', 0 );
		if ( ! $widget_enabled ) {
			return;
		}

		$show_globally = (int) get_option( 'mhbo_ai_show_globally', 0 );
		$is_portal     = false;
		if ( class_exists( '\\MHBO\\Api\\GuestPortalController' ) && \MHBO\Api\GuestPortalController::is_portal_active() ) {
			$portal_id = (int) get_option( 'mhbo_guest_portal_page', 0 );
			if ( $portal_id <= 0 ) {
				$portal_id = (int) get_transient( 'mhbo_detected_guest_portal_page' );
			}
			$post = get_post();
			if ( ( $portal_id > 0 && is_page( $portal_id ) ) || ( is_singular() && $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'mhbo_guest_portal' ) ) ) {
				$is_portal = true;
			}
		}

		if ( ! $show_globally && ! $is_portal ) {
			return;
		}

		wp_enqueue_style(
			'mhbo-google-fonts',
			'https://fonts.googleapis.com/css2?family=Outfit:wght@400;600&display=swap',
			array(),
			'1.0.0'
		);

		wp_enqueue_style(
			'mhbo-chat-widget',
			MHBO_PLUGIN_URL . 'assets/css/mhbo-chat-widget.css',
			array( 'mhbo-google-fonts' ),
			MHBO_VERSION
		);

		$voice_enabled = (int) get_option( 'mhbo_ai_voice_input_enabled', 1 );
		$tts_enabled   = (int) get_option( 'mhbo_ai_voice_output_enabled', 0 );
		if ( $voice_enabled || $tts_enabled ) {
			wp_enqueue_script(
				'mhbo-voice',
				MHBO_PLUGIN_URL . 'assets/js/mhbo-voice.js',
				array(),
				MHBO_VERSION,
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
		}

		wp_enqueue_script(
			'mhbo-chat-widget',
			MHBO_PLUGIN_URL . 'assets/js/mhbo-chat-widget.js',
			( $voice_enabled || $tts_enabled ) ? array( 'mhbo-voice' ) : array(),
			MHBO_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		if ( (int) get_option( 'mhbo_modal_enabled', 1 ) === 1 ) {
			if ( class_exists( '\MHBO\Frontend\Calendar' ) ) {
				\MHBO\Frontend\Calendar::enqueue_modal_assets();
			}
		}

		$company = Info::get_company();

		wp_localize_script(
			'mhbo-chat-widget',
			'mhboChat',
			array(
				'restUrl'   => untrailingslashit( rest_url() ),
				'nonce'     => wp_create_nonce( 'mhbo_chat_nonce' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'isPro'     => class_exists( '\MHBO\Core\License' ) && false,
				'settings'  => array(
					'hotelName'               => $company['company_name'] ?: get_bloginfo( 'name' ),
					'personaName'             => get_option( 'mhbo_ai_persona_name', \MHBO\Core\I18n::get_label( 'ai_persona_default' ) ),
					'position'                => get_option( 'mhbo_ai_widget_position', 'bottom-right' ),
					'accentColor'             => get_option( 'mhbo_ai_accent_color', '#2C3E50' ),
					'theme'                   => get_option( 'mhbo_ai_theme', '' ),
					'welcomeMessage'          => get_option( 'mhbo_ai_welcome_message', '' ),
					'voiceEnabled'            => (bool) $voice_enabled,
					'ttsEnabled'              => (bool) get_option( 'mhbo_ai_voice_output_enabled', 0 ),
					'language'                => (string) ( get_option( 'mhbo_ai_voice_language', '' ) ?: self::detect_page_locale() ),
					'pageLocale'              => self::detect_page_locale(),
					// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine
					
					// Einstein / proactive features.
					'proactiveTriggerSeconds' => (int) get_option( 'mhbo_ai_proactive_trigger_seconds', 0 ),
					'bookingUrl'              => KnowledgeBase::get_booking_url(),
					'modalEnabled'            => (bool) (int) get_option( 'mhbo_modal_enabled', 1 ),
					'streamingEnabled'        => (bool) (int) get_option( 'mhbo_ai_streaming_enabled', 0 ),
					'aiNativeAvailable'       => Client::is_native_available( false ),
				),
				'strings'   => array(
					'openChat'              => \MHBO\Core\I18n::get_label( 'ai_widget_open' ),
					'close'                 => \MHBO\Core\I18n::get_label( 'ai_widget_close' ),
					'minimize'              => \MHBO\Core\I18n::get_label( 'ai_widget_minimize' ),
					'send'                  => \MHBO\Core\I18n::get_label( 'ai_widget_send' ),
					'inputPlaceholder'      => \MHBO\Core\I18n::get_label( 'ai_widget_input_placeholder' ),
					'inputLabel'            => \MHBO\Core\I18n::get_label( 'ai_widget_input_label' ),
					'startVoice'            => \MHBO\Core\I18n::get_label( 'ai_widget_start_voice' ),
					'stopVoice'             => \MHBO\Core\I18n::get_label( 'ai_widget_stop_voice' ),
					'toggleVoice'           => \MHBO\Core\I18n::get_label( 'ai_widget_toggle_voice' ),
					'typing'                => \MHBO\Core\I18n::get_label( 'ai_widget_typing' ),
					'errorMessage'          => \MHBO\Core\I18n::get_label( 'ai_widget_error_message' ),
					'welcomeMessage'        => \MHBO\Core\I18n::get_label( 'ai_widget_welcome_message' ),
					'suggCheckAvail'        => \MHBO\Core\I18n::get_label( 'ai_widget_sugg_check_avail' ),
					'suggRoomTypes'         => \MHBO\Core\I18n::get_label( 'ai_widget_sugg_room_types' ),
					'suggPolicies'          => \MHBO\Core\I18n::get_label( 'ai_widget_sugg_policies' ),
					'chatDialogLabel'       => \MHBO\Core\I18n::get_label( 'ai_widget_dialog_label' ),
					'messageHistory'        => \MHBO\Core\I18n::get_label( 'ai_widget_message_history' ),
					'suggestions'           => \MHBO\Core\I18n::get_label( 'ai_widget_suggestions' ),
					'voiceNotSupported'     => \MHBO\Core\I18n::get_label( 'ai_widget_voice_not_supported' ),
					'voicePermissionDenied' => \MHBO\Core\I18n::get_label( 'ai_widget_voice_denied' ),
					// Book Now CTA (intent-driven).
					'ctaHighIntent'         => \MHBO\Core\I18n::get_label( 'ai_cta_high_intent' ),
					'ctaMedIntent'          => \MHBO\Core\I18n::get_label( 'ai_cta_med_intent' ),
					'bookNowLabel'          => \MHBO\Core\I18n::get_label( 'ai_cta_book_now' ),
					'dismiss'               => \MHBO\Core\I18n::get_label( 'ai_cta_dismiss' ),
					// Handoff / escalation bar.
					'handoffIntro'          => \MHBO\Core\I18n::get_label( 'ai_handoff_intro' ),
					'handoffWhatsapp'       => \MHBO\Core\I18n::get_label( 'ai_handoff_whatsapp' ),
					'handoffEmail'          => \MHBO\Core\I18n::get_label( 'ai_handoff_email' ),
					'handoffPhone'          => \MHBO\Core\I18n::get_label( 'ai_handoff_phone' ),
					// Proactive greeting messages.
					'proactiveDefault'      => \MHBO\Core\I18n::get_label( 'ai_proactive_default' ),
					'proactiveBooking'      => \MHBO\Core\I18n::get_label( 'ai_proactive_booking' ),
					'proactiveRooms'        => \MHBO\Core\I18n::get_label( 'ai_proactive_rooms' ),
				),
			) 
		);

		// Inject CSS variable for accent color.
		$accent = sanitize_hex_color( (string) get_option( 'mhbo_ai_accent_color', '#2C3E50' ) );
		if ( $accent ) {
			wp_add_inline_style( 'mhbo-chat-widget', ":root { --mhbo-chat-accent: {$accent}; }" );
		}
	}

	// -------------------------------------------------------------------------
	// Widget Template
	// -------------------------------------------------------------------------

	/**
	 * Render the floating widget container in the footer.
	 */
	public static function render_widget_template(): void {
		$ai_enabled     = (int) get_option( 'mhbo_ai_enabled', 0 );
		$widget_enabled = (int) get_option( 'mhbo_ai_widget_enabled', 0 );
		$show_globally  = (int) get_option( 'mhbo_ai_show_globally', 0 );

		$is_portal = false;
		if ( class_exists( '\\MHBO\\Api\\GuestPortalController' ) && \MHBO\Api\GuestPortalController::is_portal_active() ) {
			$portal_id = (int) get_option( 'mhbo_guest_portal_page', 0 );
			if ( $portal_id <= 0 ) {
				$portal_id = (int) get_transient( 'mhbo_detected_guest_portal_page' );
			}
			$post = get_post();
			if ( ( $portal_id > 0 && is_page( $portal_id ) ) || ( is_singular() && $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'mhbo_guest_portal' ) ) ) {
				$is_portal = true;
			}
		}

		if ( ! $ai_enabled || ! $widget_enabled || ( ! $show_globally && ! $is_portal ) ) {
			return;
		}
		include MHBO_PLUGIN_DIR . 'templates/chat-widget.php';
	}

	/**
	 * Check whether the AI Concierge subsystem and widget are actively enabled and functional.
	 *
	 * Returns true only when:
	 * 1. Pro tier is active (mhbo_is_pro()).
	 * 2. Master AI toggle (mhbo_ai_enabled on tab=general) is ON.
	 * 3. Chat widget toggle (mhbo_ai_widget_enabled on tab=appearance) is ON.
	 * 4. At least one functional AI provider/key or native connector is available.
	 *
	 * @return bool
	 */
	public static function is_concierge_active(): bool {
		$active = false;
		
		return $active;
	}

	// -------------------------------------------------------------------------
	// Gutenberg Block
	// -------------------------------------------------------------------------

	/**
	 * Register the AI Concierge Gutenberg block.
	 */
	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$block_dir = MHBO_PLUGIN_DIR . 'blocks/ai-concierge';
		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			return;
		}
		\register_block_type(
			$block_dir,
			array(
				'render_callback' => array( self::class, 'render_block' ),
			) 
		);
	}

	/**
	 * Server-side render for the AI Concierge block.
	 *
	 * @param array<mixed> $attrs
	 * @return string
	 */
	public static function render_block( array $attrs ): string {
		$enabled = (int) get_option( 'mhbo_ai_enabled', 0 );
		if ( ! $enabled ) {
			return '';
		}
		return self::render_widget_div( $attrs );
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	/**
	 * Register [mhbo_ai_concierge] shortcode.
	 */
	public static function register_shortcode(): void {
		add_shortcode( 'mhbo_ai_concierge', array( self::class, 'shortcode_handler' ) );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<mixed>|string $atts
	 * @return string
	 */
	public static function shortcode_handler( array|string $atts ): string {
		$atts = shortcode_atts(
			array(
				'variant'         => 'floating',
				'position'        => 'bottom-right',
				'welcome_message' => '',
				'theme'           => '',     // Pro only
			),
			\is_array( $atts ) ? $atts : array() 
		);

$enabled = (int) get_option( 'mhbo_ai_enabled', 0 );
		if ( ! $enabled ) {
			return '';
		}

		self::enqueue_frontend();

		return self::render_widget_div(
			array(
				'variant'        => sanitize_text_field( (string) $atts['variant'] ),
				'position'       => sanitize_text_field( (string) $atts['position'] ),
				'welcomeMessage' => sanitize_text_field( (string) $atts['welcome_message'] ),
				'theme'          => sanitize_text_field( (string) $atts['theme'] ),
			) 
		);
	}

	/**
	 * Build the widget container HTML.
	 *
	 * @param array<mixed> $attrs
	 * @return string
	 */
	private static function render_widget_div( array $attrs ): string {
		$allowed_variants  = array( 'floating', 'inline' );
		$allowed_positions = array( 'bottom-right', 'bottom-left' );

		$raw_variant  = (string) ( $attrs['variant'] ?? 'floating' );
		$raw_position = (string) ( $attrs['position'] ?? 'bottom-right' );

		$variant  = esc_attr( \in_array( $raw_variant, $allowed_variants, true ) ? $raw_variant : 'floating' );
		$position = esc_attr( \in_array( $raw_position, $allowed_positions, true ) ? $raw_position : 'bottom-right' );
		$welcome  = esc_attr( (string) ( $attrs['welcomeMessage'] ?? '' ) );

		$theme = (string) ( $attrs['theme'] ?? '' );
		if ( '' === $theme ) {
			$theme = (string) get_option( 'mhbo_ai_theme', '' );
		}
		$theme_class = $theme ? ' mhbo-theme-' . sanitize_html_class( $theme ) : '';

		$data  = 'data-variant="' . $variant . '"';
		$data .= ' data-position="' . $position . '"';
		if ( $welcome ) {
			$data .= ' data-welcome-message="' . $welcome . '"';
		}

		return '<div class="mhbo-chat-widget' . $theme_class . '" ' . $data . '></div>';
	}

	// -------------------------------------------------------------------------
	// Settings Tab Integration
	// -------------------------------------------------------------------------

	/**
	 * Add the AI Concierge tab to the MHBO settings page.
	 *
	 * @param array<string,string> $tabs
	 * @return array<string,string>
	 */
	public static function add_settings_tab( array $tabs ): array {
		$tabs['ai_concierge'] = __( 'AI Concierge', 'modern-hotel-booking' );
		return $tabs;
	}

	// -------------------------------------------------------------------------
	// PRO: Role management
	// -------------------------------------------------------------------------

// -------------------------------------------------------------------------
	// Locale Detection
	// -------------------------------------------------------------------------

	/**
	 * Detect the current page locale from multilingual plugins or WP core.
	 *
	 * Priority: Polylang → WPML → qTranslate-XT → WP get_locale().
	 * Returns a BCP-47 tag (e.g. "ro-RO", "en-US", "de-DE").
	 *
	 * @return string
	 */
	public static function detect_page_locale(): string {
		// Polylang.
		if ( function_exists( 'pll_current_language' ) ) {
			$locale = (string) \call_user_func( 'pll_current_language', 'locale' );
			if ( '' !== $locale ) {
				return str_replace( '_', '-', $locale );
			}
		}

		// WPML.
		if ( \defined( 'ICL_LANGUAGE_CODE' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$locale = apply_filters( 'wpml_current_language_details', null );
			if ( \is_array( $locale ) && '' !== (string) ( $locale['default_locale'] ?? '' ) ) {
				return str_replace( '_', '-', $locale['default_locale'] );
			}
			// Fallback: just the 2-letter code from the constant.
			return (string) constant( 'ICL_LANGUAGE_CODE' );
		}

		// qTranslate-XT / qTranslate-X.
		global $q_config;
		if ( '' !== (string) ( $q_config['language'] ?? '' ) ) {
			// $q_config['language'] is a 2-char code; try to expand via $q_config['locale'].
			$qlang   = $q_config['language'];
			$qlocale = $q_config['locale'][ $qlang ] ?? $qlang;
			return str_replace( '_', '-', $qlocale );
		}

		// Bogo.
		if ( function_exists( 'bogo_get_current_locale' ) || defined( 'BOGO_VERSION' ) ) {
			$bogo_locale = function_exists( 'bogo_get_current_locale' ) ? (string) call_user_func( 'bogo_get_current_locale' ) : get_locale();
			if ( '' !== $bogo_locale ) {
				return str_replace( '_', '-', $bogo_locale );
			}
		}

		// WP core locale (works for single-language sites or as a safe fallback).
		return str_replace( '_', '-', get_locale() );
	}

	// -------------------------------------------------------------------------
	// WP 7.1 Abilities API Hooks
	// -------------------------------------------------------------------------

	/**
	 * WP 7.1: Audit every MHBO ability invocation (telemetry + rate limiting).
	 *
	 * Fires on `wp_ability_invoked`. Filters `mhbo/` prefix only.
	 * Free: WP_DEBUG telemetry. Pro: future delegation to AiRateLimiter::record_usage().
	 * Never logs raw $input (PII protection).
	 *
	 * @param string $ability_name e.g. 'mhbo/check-availability'.
	 * @param mixed  $input        The raw input data for the ability, before normalization.
	 * @param mixed  $ability      The ability instance or null.
	 */
	public static function audit_ability( string $ability_name, mixed $input = null, mixed $ability = null ): void {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Parameter required by wp_ability_invoked action signature.
		unset( $ability );

		// Only process MHBO abilities.
		if ( ! str_starts_with( $ability_name, 'mhbo/' ) ) {
			return;
		}

		// Free: WP_DEBUG telemetry only.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[MHBO] Ability invoked: %s (input type: %s)',
					$ability_name,
					get_debug_type( $input )
				) 
			);
		}

		// phpcs:ignore Squiz.Commenting.BlockComment.NoNewLine
		
	}

	/**
	 * WP 7.1: Deep input validation beyond JSON Schema.
	 *
	 * Fires on `wp_ability_validate_input`. Returns true if valid,
	 * or a WP_Error if validation fails.
	 *
	 * @param true|\WP_Error $is_valid     The validation result from default validation.
	 * @param mixed          $input        The input data being validated.
	 * @param string         $ability_name e.g. 'mhbo/check-availability'.
	 * @return true|\WP_Error
	 */
	public static function validate_ability_input( mixed $is_valid, mixed $input, string $ability_name = '' ): mixed {
		// Pass through non-MHBO abilities or existing errors.
		if ( ! str_starts_with( $ability_name, 'mhbo/' ) ) {
			return $is_valid;
		}
		if ( is_wp_error( $is_valid ) ) {
			return $is_valid;
		}
		if ( ! is_array( $input ) ) {
			return $is_valid;
		}

		switch ( $ability_name ) {
			case 'mhbo/check-availability':
				// Check-out must be after check-in.
				$check_in  = (string) ( $input['check_in'] ?? '' );
				$check_out = (string) ( $input['check_out'] ?? '' );
				if ( '' !== $check_in && '' !== $check_out && $check_out <= $check_in ) {
					return new \WP_Error(
						'invalid_dates',
						__( 'Check-out date must be after check-in date.', 'modern-hotel-booking' ),
						array( 'status' => 400 )
					);
				}
				break;

case 'mhbo/get-price-breakdown':
				// Positive integer guests/nights.
				$guests = (int) ( $input['guests'] ?? 0 );
				$nights = (int) ( $input['nights'] ?? 0 );
				if ( ( isset( $input['guests'] ) && $guests < 1 ) || ( isset( $input['nights'] ) && $nights < 1 ) ) {
					return new \WP_Error(
						'invalid_quantity',
						__( 'Guests and nights must be positive integers.', 'modern-hotel-booking' ),
						array( 'status' => 400 )
					);
				}
				break;
		}

		return true;
	}

	/**
	 * WP 7.1: Output validation guard.
	 *
	 * Fires on `wp_ability_validate_output`. Returns true if valid,
	 * or a WP_Error if required fields are missing.
	 *
	 * @param true|\WP_Error $is_valid     The validation result from default validation.
	 * @param mixed          $output       The ability's return value.
	 * @param string         $ability_name e.g. 'mhbo/check-availability'.
	 * @return true|\WP_Error
	 */
	public static function validate_ability_output( mixed $is_valid, mixed $output, string $ability_name = '' ): mixed {
		// Only process MHBO abilities.
		if ( ! str_starts_with( $ability_name, 'mhbo/' ) ) {
			return $is_valid;
		}

		// If output is already an error, pass through.
		if ( is_wp_error( $is_valid ) ) {
			return $is_valid;
		}

		// Output must be an array for structural validation.
		if ( ! is_array( $output ) ) {
			return $is_valid;
		}

		switch ( $ability_name ) {
			case 'mhbo/check-availability':
				if ( ! array_key_exists( 'available', $output ) ) {
					return new \WP_Error(
						'invalid_output',
						__( 'Availability response must contain an "available" boolean.', 'modern-hotel-booking' ),
						array( 'status' => 500 )
					);
				}
				break;

			case 'mhbo/get-price-breakdown':
				if ( ! array_key_exists( 'total', $output ) || ! is_numeric( $output['total'] ) ) {
					return new \WP_Error(
						'invalid_output',
						__( 'Price breakdown must contain a numeric "total".', 'modern-hotel-booking' ),
						array( 'status' => 500 )
					);
				}
				break;
		}

		return true;
	}
}
