<?php
declare(strict_types=1);

/**
 * I18n — Multilingual Abstraction Layer
 *
 * Supports qTranslate-X, WPML, Polylang, and vanilla WordPress.
 * Internal storage format: [:en]English[:ro]Romanian[:de]German[:]
 *
 * IMPORTANT — WordPress i18n compliance:
 * All gettext calls (__(), esc_html__(), etc.) MUST use literal string
 * arguments for both the text and the text domain. Never pass variables
 * or constants to these functions — the translation parser reads code
 * statically and cannot resolve runtime values.
 *
 * Correct:   _x( 'Hello', 'greeting', 'modern-hotel-booking' )
 * Incorrect: __ ( ' . variable . ' )
 *
 * If you need to include dynamic values, use printf/sprintf with placeholders:
 *   /* translators: %s: guest name *\/
 *   echo esc_html( sprintf( esc_html__( 'Welcome %s', 'modern-hotel-booking' ), $name ) );
 *
 * @package MHBO\Core
 * @since   2.0.0
 */

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
	 * I18n class handles all localization and translation logic.
	 */
class I18n {

	/**
	 * Language override for REST/AJAX contexts where multilingual plugins
	 * cannot detect the visitor's language (e.g. Polylang on REST requests).
	 *
	 * @var string|null 2-letter language code, or null to use auto-detection.
	 */
	private static ?string $language_override = null;

	/**
	 * Stack of previous language contexts for nesting.
	 *
	 * @var array<int, array{override: ?string, locale: string, switched_wp: bool}>
	 */
	private static array $context_stack = array();

	/**
	 * Force all subsequent get_current_language() calls to return the given code.
	 *
	 * Intended for REST/AJAX endpoints that receive a `lang` parameter from
	 * the frontend.  Must be called **before** any I18n::get_label() output.
	 *
	 * @param string $lang 2-letter ISO 639-1 language code.
	 */
	public static function set_language_override( string $lang ): void {
		self::$language_override = sanitize_key( substr( $lang, 0, 5 ) );
	}

	/**
	 * Remove any active language override (restore auto-detection).
	 */
	public static function clear_language_override(): void {
		self::$language_override = null;
	}

	/**
	 * Switch full multilingual execution context (WordPress locale, Polylang, WPML, qTranslate, Bogo/WPLANG).
	 *
	 * Essential for transactional emails (booking confirmations, admin alerts, receipts, reminders)
	 * so that all template tags, gettext labels, and dates render in the recipient's language.
	 *
	 * @param string $lang_or_locale Language code (e.g. 'nl', 'es') or full locale (e.g. 'nl_NL', 'es_ES').
	 * @return void
	 */
	public static function switch_language_context( string $lang_or_locale ): void {
		$lang_clean = sanitize_key( trim( $lang_or_locale ) );
		if ( '' === $lang_clean ) {
			return;
		}

		$current_override = self::$language_override;
		$current_locale   = function_exists( 'get_locale' ) ? get_locale() : 'en_US';

		$map_to_full = array(
			'cs' => 'cs_CZ',
			'da' => 'da_DK',
			'de' => 'de_DE',
			'el' => 'el',
			'en' => 'en_US',
			'es' => 'es_ES',
			'fr' => 'fr_FR',
			'hu' => 'hu_HU',
			'it' => 'it_IT',
			'nb' => 'nb_NO',
			'no' => 'nb_NO',
			'nl' => 'nl_NL',
			'pl' => 'pl_PL',
			'pt' => 'pt_BR',
			'ro' => 'ro_RO',
			'ru' => 'ru_RU',
			'sv' => 'sv_SE',
		);

		$short_code  = substr( $lang_clean, 0, 2 );
		$full_locale = str_contains( $lang_clean, '_' ) ? $lang_clean : ( $map_to_full[ $short_code ] ?? $lang_clean );

		self::$context_stack[] = array(
			'override'    => $current_override,
			'locale'      => $current_locale,
			'switched_wp' => function_exists( 'switch_to_locale' ),
		);

		self::set_language_override( $short_code );

		// 1. Polylang support
		if ( function_exists( 'PLL' ) ) {
			/** @phpstan-ignore argument.type */
			$pll = call_user_func( 'PLL' );
			if ( is_object( $pll ) && isset( $pll->model ) && is_object( $pll->model ) && method_exists( $pll->model, 'get_language' ) ) {
				$desired_lang = $pll->model->get_language( $short_code );
				if ( false !== $desired_lang ) {
					$pll->curlang = $desired_lang;
				}
			}
		}

		// 2. WPML support
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'wpml_switch_language', $short_code );

		// 3. qTranslate / qTranslate-XT support
		if ( function_exists( 'qtranxf_useLanguage' ) ) {
			call_user_func( 'qtranxf_useLanguage', $short_code );
		}

		// 4. Core WordPress gettext / Bogo / WPLANG support
		if ( function_exists( 'switch_to_locale' ) ) {
			switch_to_locale( $full_locale );
		}
	}

	/**
	 * Restore previously active language execution context.
	 *
	 * @return void
	 */
	public static function restore_language_context(): void {
		if ( empty( self::$context_stack ) ) {
			self::clear_language_override();
			return;
		}

		$prev = array_pop( self::$context_stack );
		self::$language_override = $prev['override'];

		if ( ! empty( $prev['switched_wp'] ) && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}

		$prev_short = $prev['override'] ? substr( $prev['override'], 0, 2 ) : substr( $prev['locale'], 0, 2 );
		if ( function_exists( 'PLL' ) ) {
			/** @phpstan-ignore argument.type */
			$pll = call_user_func( 'PLL' );
			if ( is_object( $pll ) && isset( $pll->model ) && is_object( $pll->model ) && method_exists( $pll->model, 'get_language' ) ) {
				$desired = $pll->model->get_language( $prev_short );
				if ( false !== $desired ) {
					$pll->curlang = $desired;
				}
			}
		}

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'wpml_switch_language', $prev_short );

		if ( function_exists( 'qtranxf_useLanguage' ) ) {
			call_user_func( 'qtranxf_useLanguage', $prev_short );
		}
	}

	/**
	 * Initialize translation filters.
	 *
	 * @return void
	 */
	public static function init(): void {
		// WordPress 4.6+ auto-loads translations for plugins hosted on WP.org.
		add_filter( 'gettext_modern-hotel-booking', array( self::class, 'filter_gettext' ), 10, 3 );

		// 1. Dynamic REST API override passed from JS (e.g. Polylang/WPML modal requests).
		// Hooked at init to switch the global locale before REST callbacks fire.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mhbo_locale = isset( $_REQUEST['mhbo_locale'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mhbo_locale'] ) ) : '';
		if ( '' !== $mhbo_locale && preg_match( '/^[a-zA-Z_]{2,10}$/', $mhbo_locale ) && self::is_frontend_request() ) {
			$lang_slug = substr( $mhbo_locale, 0, 2 );
			self::set_language_override( $lang_slug );

			// Force Polylang to switch its internal current language for registered string translation.
			if ( function_exists( 'PLL' ) ) {
				/** @phpstan-ignore argument.type */
				$pll = call_user_func( 'PLL' );
				if ( is_object( $pll ) && isset( $pll->model ) && is_object( $pll->model ) && method_exists( $pll->model, 'get_language' ) ) {
					$desired_lang = $pll->model->get_language( $lang_slug );
					if ( false !== $desired_lang ) {
						$pll->curlang = $desired_lang;
					}
				}
			}

			if ( function_exists( 'switch_to_locale' ) ) {
				if ( function_exists( 'did_action' ) && \did_action( 'init' ) ) {
					switch_to_locale( $mhbo_locale );
				} else {
					add_action(
						'init',
						static function () use ( $mhbo_locale ): void {
							switch_to_locale( $mhbo_locale );
						},
						1
					);
				}
			}

			// Force WPML to switch its internal language.
            // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
			do_action( 'wpml_switch_language', $lang_slug );

			// Force qTranslate-X/XT to switch its active language.
			if ( function_exists( 'qtranxf_useLanguage' ) ) {
				call_user_func( 'qtranxf_useLanguage', $lang_slug );
			}
			
			// In WP 6.5+, Performant Translations natively handles switch_to_locale() 
			// and uses JIT loading with .php arrays. We do not need to manually
			// unload or reload the textdomain with a .mo file.
		}

		// 2. Allow admins to force a specific locale on the public frontend via settings.
		add_filter( 'plugin_locale', array( self::class, 'maybe_force_site_locale' ), 10, 2 );

		// If a forced locale is set via settings, explicitly load the text domain
		if ( self::is_frontend_request() ) {
			$forced = (string) get_option( 'mhbo_force_frontend_locale', '' );
			if ( '' !== $forced && 'en_US' !== $forced ) {
				// In WP 6.5+, switch_to_locale() correctly handles JIT unloading/reloading.
				if ( function_exists( 'switch_to_locale' ) ) {
					if ( function_exists( 'did_action' ) && \did_action( 'init' ) ) {
						switch_to_locale( $forced );
					} else {
						add_action(
							'init',
							static function () use ( $forced ): void {
								switch_to_locale( $forced );
							},
							1
						);
					}
				}
			}
		}
	}

	/**
	 * Check if we are currently serving a public frontend request.
	 *
	 * @return bool
	 */
	public static function is_frontend_request(): bool {
		if ( wp_doing_ajax() ) {
			$referer = wp_get_referer();
			return $referer && false === strpos( (string) $referer, '/wp-admin/' );
		}

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$route        = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$is_mhbo_rest = strpos( $route, '/wp-json/mhbo/' ) !== false
				|| strpos( $route, '/wp-json/modern-hotel-booking/' ) !== false
				|| strpos( $route, '?rest_route=/mhbo/' ) !== false
				|| strpos( $route, '?rest_route=/modern-hotel-booking/' ) !== false;
			if ( $is_mhbo_rest ) {
				return false === strpos( $route, '/admin/' );
			}
		}

		return ! is_admin();
	}

	/**
	 * Filter plugin translations to prevent blank strings.
	 *
	 * If a translation is found to be empty or just whitespace in the .mo file,
	 * WP returns it as is. We force it to fallback to the original English string.
	 *
	 * @param string $translated
	 * @param string $text
	 * @param string $domain
	 * @return string
	 */
	public static function filter_gettext( string $translated, string $text, string $domain ): string {
		if ( '' === trim( $translated ) ) {
			return $text;
		}

		// If a forced frontend locale is active and we're on the public frontend,
		// ensure the original (English) string is returned when en_US is forced.
		// This guards against the .mo file being loaded before our plugin_locale filter.
		if ( self::is_frontend_request() ) {
			// Cache the option value per-request for performance (gettext fires hundreds of times).
			static $forced_locale = null;
			if ( null === $forced_locale ) {
				$forced_locale = (string) get_option( 'mhbo_force_frontend_locale', '' );
			}
			if ( 'en_US' === $forced_locale ) {
				return $text;
			}
		}

		return $translated;
	}

	/**
	 * Force the plugin locale on the public frontend when a specific language is configured.
	 *
	 * Hooked to the `plugin_locale` filter. Only applies to our text domain and only on
	 * non-admin requests. When left blank (default), existing behaviour is preserved.
	 *
	 * @param string $locale The determined locale.
	 * @param string $domain The text domain.
	 * @return string
	 */
	public static function maybe_force_site_locale( string $locale, string $domain ): string {
		if ( 'modern-hotel-booking' !== $domain ) {
			return $locale;
		}

		// 1. Dynamic REST API override passed from JS (e.g. Polylang/WPML modal requests)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mhbo_locale = isset( $_REQUEST['mhbo_locale'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mhbo_locale'] ) ) : '';
		if ( '' !== $mhbo_locale && self::is_frontend_request() ) {
			return $mhbo_locale;
		}

		// 2. Static admin option override
		$forced = (string) get_option( 'mhbo_force_frontend_locale', '' );
		if ( '' === $forced ) {
			return $locale; // Disabled — preserve default WP behaviour.
		}

		// Only override on the public frontend (not wp-admin, not AJAX, not REST).
		if ( ! self::is_frontend_request() ) {
			return $locale;
		}

		return $forced;
	}

	/**
	 * Get the list of locales supported by the plugin's translation files.
	 *
	 * Returns an associative array of locale code => native name.
	 * Used by the Settings dropdown for the "Force Frontend Language" option.
	 *
	 * @return array<string, string>
	 */
	public static function get_supported_locales(): array {
		return array(
			'en_US' => 'English',
			'cs_CZ' => 'Čeština',
			'da_DK' => 'Dansk',
			'de_DE' => 'Deutsch',
			'el'    => 'Ελληνικά',
			'es_ES' => 'Español',
			'fr_FR' => 'Français',
			'hu_HU' => 'Magyar',
			'it_IT' => 'Italiano',
			'nb_NO' => 'Norsk Bokmål',
			'nl_NL' => 'Nederlands',
			'pl_PL' => 'Polski',
			'pt_BR' => 'Português (Brasil)',
			'ro_RO' => 'Română',
			'ru_RU' => 'Русский',
			'sv_SE' => 'Svenska',
		);
	}

	/**
	 * Detect which multilingual plugin is active.
	 *
	 * @return string 'qtranslate'|'wpml'|'polylang'|'none'
	 */
	public static function detect_plugin(): string {
		if ( defined( 'QTX_VERSION' ) || function_exists( 'qtranxf_getLanguage' ) ) {
			return 'qtranslate';
		}
		// Check Polylang BEFORE WPML — Polylang Pro ships a WPML compatibility
		// layer that defines ICL_SITEPRESS_VERSION, which would cause
		// misdetection if the WPML check came first.
		if ( function_exists( 'pll_current_language' ) || defined( 'POLYLANG_VERSION' ) ) {
			return 'polylang';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'icl_get_languages' ) ) {
			return 'wpml';
		}
		if ( defined( 'BOGO_VERSION' ) || function_exists( 'bogo_get_post_translations' ) ) {
			return 'bogo';
		}
		return 'none';
	}

	/**
	 * Helper to get the 2-letter locale code.
	 *
	 * @return string
	 */
	private static function locale_code(): string {
		if ( self::is_frontend_request() ) {
			$forced = (string) get_option( 'mhbo_force_frontend_locale', '' );
			if ( '' !== $forced ) {
				return substr( $forced, 0, 2 );
			}
		}
		return substr( get_locale(), 0, 2 );
	}

	/**
	 * Get current front-end/admin language code (2-letter).
	 *
	 * @return string
	 */
	public static function get_current_language(): string {
		// REST/AJAX language override (set by endpoints that receive a `lang` param).
		if ( null !== self::$language_override && '' !== self::$language_override ) {
			return self::$language_override;
		}

		// Handle admin side language selection via URL param (e.g., ?lang=ro).
		// Uses filter_input() to avoid nonce-verification warnings — this is a
		// read-only display parameter that does not change state.
		$lang_param = filter_input( INPUT_GET, 'lang', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( is_admin() && '' !== (string) $lang_param ) {
			return sanitize_key( $lang_param );
		}

		switch ( self::detect_plugin() ) {
			case 'qtranslate':
				return function_exists( 'qtranxf_getLanguage' ) ? call_user_func( 'qtranxf_getLanguage' ) : self::locale_code();
			case 'wpml':
				$wpml_lang = apply_filters( 'wpml_current_language', self::locale_code() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook
				return is_string( $wpml_lang ) && '' !== $wpml_lang ? $wpml_lang : self::locale_code();
			case 'polylang':
				$lang = function_exists( 'pll_current_language' ) ? call_user_func( 'pll_current_language' ) : self::locale_code();
				return $lang ? $lang : self::locale_code();
			case 'bogo':
				if ( function_exists( 'bogo_get_current_locale' ) ) {
					$bogo_loc = (string) call_user_func( 'bogo_get_current_locale' );
					if ( function_exists( 'bogo_language_slug' ) ) {
						return (string) call_user_func( 'bogo_language_slug', $bogo_loc );
					}
					return substr( $bogo_loc, 0, 2 );
				}
				return substr( get_locale(), 0, 2 );
			default:
				return self::locale_code();
		}
	}

	/**
	 * Get default site language code.
	 *
	 * @return string
	 */
	public static function get_default_language(): string {
		switch ( self::detect_plugin() ) {
			case 'qtranslate':
				$qtranslate_config = self::get_q_config();
				return isset( $qtranslate_config['default_language'] ) ? $qtranslate_config['default_language'] : 'en';
			case 'wpml':
				return apply_filters( 'wpml_default_language', 'en' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook
			case 'polylang':
				$pll_default = function_exists( 'pll_default_language' ) ? call_user_func( 'pll_default_language' ) : false;
				return is_string( $pll_default ) && '' !== $pll_default ? $pll_default : 'en';
			case 'bogo':
				$default_locale = get_option( 'WPLANG', 'en_US' );
				return substr( (string) ( $default_locale ?: 'en_US' ), 0, 2 );
			default:
				return 'en';
		}
	}

	/**
	 * Get all available languages as an array of 2-letter codes.
	 *
	 * @return string[]
	 */
	public static function get_available_languages(): array {
		switch ( self::detect_plugin() ) {
			case 'qtranslate':
				$qtranslate_config = self::get_q_config();
				return isset( $qtranslate_config['enabled_languages'] ) ? $qtranslate_config['enabled_languages'] : array( self::locale_code() );

			case 'wpml':
				$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML hook
				return is_array( $langs ) ? array_keys( $langs ) : array( self::locale_code() );

			case 'polylang':
				if ( function_exists( 'pll_languages_list' ) ) {
					$list = call_user_func( 'pll_languages_list', array( 'fields' => 'slug' ) );
					return array() !== $list ? $list : array( self::locale_code() );
				}
				return array( self::locale_code() );

			case 'bogo':
				if ( function_exists( 'bogo_available_languages' ) ) {
					$bogo_langs = (array) call_user_func( 'bogo_available_languages' );
					$slugs      = array();
					foreach ( $bogo_langs as $loc ) {
						$slug = function_exists( 'bogo_language_slug' ) ? (string) call_user_func( 'bogo_language_slug', $loc ) : substr( (string) $loc, 0, 2 );
						if ( '' !== $slug ) {
							$slugs[] = strtolower( $slug );
						}
					}
					return ! empty( $slugs ) ? array_values( array_unique( $slugs ) ) : array( self::locale_code() );
				}
				return array( self::locale_code() );

			default:
				$langs = array_unique( array( 'en', self::locale_code() ) );
				return (array) apply_filters( 'mhbo_i18n_get_available_languages', array_values( $langs ) );
		}
	}

	/**
	 * Get native language name for a code.
	 *
	 * @param string $code 2-letter code.
	 * @return string
	 */
	public static function get_language_name( string $code ): string {
		$names = array(
			'en' => 'English',
			'ro' => 'Română',
			'fr' => 'Français',
			'de' => 'Deutsch',
			'it' => 'Italiano',
			'es' => 'Español',
			'bg' => 'Български',
			'pl' => 'Polski',
			'hu' => 'Magyar',
			'cs' => 'Čeština',
			'sk' => 'Slovenčina',
			'da' => 'Dansk',
			'sv' => 'Svenska',
			'fi' => 'Suomi',
			'el' => 'Ελληνικά',
			'nl' => 'Nederlands',
			'pt' => 'Português',
			'nb' => 'Norsk Bokmål',
			'no' => 'Norsk',
			'ru' => 'Русский',
		);
		return isset( $names[ $code ] ) ? $names[ $code ] : strtoupper( $code );
	}

	/**
	 * Get qTranslate-XT configuration (third-party global).
	 *
	 * @return array<string, mixed>
	 */
	private static function get_q_config(): array {
		// Use $GLOBALS to avoid WP repo scanner false-positives for unprefixed globals (this belongs to qTranslate)
		return isset( $GLOBALS['q_config'] ) ? $GLOBALS['q_config'] : array();
	}

	/**
	 * Decode a multilingual string.
	 * Format: [:en]Hello[:ro]Salut[:]
	 *
	 * @param mixed       $text The value to decode.
	 * @param string|null $lang Optional language code.
	 * @param bool        $fallback Whether to fallback to other languages if requested is missing.
	 * @return string|null
	 */
	public static function decode( mixed $text, ?string $lang = null, bool $fallback = true ): ?string {
		if ( $text === '' || ! is_string( $text ) ) {
			return is_scalar( $text ) ? (string) $text : null;
		}

		if ( ! $lang ) {
			$lang = self::get_current_language();
		}

		// qTranslate style parsing - leverage native function if available (only if fallback is enabled)
		if ( $fallback ) {
			if ( function_exists( 'qtranxf_use' ) ) {
				return (string) \call_user_func( 'qtranxf_use', $lang, $text );
			}
			if ( function_exists( 'qtrans_use' ) ) {
				return (string) \call_user_func( 'qtrans_use', $lang, $text );
			}
		}

		// Normalize qTranslate HTML comments syntax <!--:en-->...<!--:--> into standard [:en]...[:]
		if ( false !== strpos( $text, '<!--:' ) ) {
			$text = preg_replace( '/<!--:([a-z]{2}(?:_[a-z]{2})?)-->/i', '[:$1]', $text );
			$text = str_replace( '<!--:-->', '[:]', (string) $text );
		}

		// Normalize qTranslate curly braces syntax {:en}...{:} into standard [:en]...[:]
		if ( false !== strpos( $text, '{:' ) ) {
			$text = preg_replace( '/\{:([a-z]{2}(?:_[a-z]{2})?)\}/i', '[:$1]', $text );
			$text = str_replace( '{:}', '[:]', (string) $text );
		}

		// Detect if this is a plain string (no multilingual tags)
		$is_plain = false === strpos( $text, '[:' );

		// Handle plain strings on multilingual sites
		if ( $is_plain && 'none' !== self::detect_plugin() && ! $fallback ) {
			return trim( $text );
		}

		// Manual parsing fallback for single-language strings or when fallback is allowed
		if ( $is_plain ) {
			return $text;
		}

		// qTranslate style parsing
		$blocks = preg_split( '/\[:([a-z]{2}(?:_[a-z]{2})?)\]/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE );

		$block_count = is_array( $blocks ) ? count( $blocks ) : 0;
		if ( $block_count < 3 ) {
			return $text;
		}

		// Build a map of languages to content
		$map = array();
		for ( $i = 1; $i < $block_count; $i += 2 ) {
			if ( isset( $blocks[ $i + 1 ] ) ) {
				$lang_key = strtolower( $blocks[ $i ] );
				$content  = $blocks[ $i + 1 ];

				if ( substr( $content, -3 ) === '[:]' ) {
					$content = substr( $content, 0, -3 );
				}

				$map[ $lang_key ] = $content;
			}
		}

		// Normalize requested language
		$lang       = strtolower( $lang );
		$lang_short = substr( $lang, 0, 2 );

		// 1. Try exact match
		if ( isset( $map[ $lang ] ) && '' !== $map[ $lang ] ) {
			return $map[ $lang ];
		}

		// 2. Try short code match (e.g. 'en' for 'en_US')
		if ( isset( $map[ $lang_short ] ) && '' !== $map[ $lang_short ] ) {
			return $map[ $lang_short ];
		}

		// 3. Fallback logic (WP 7.0+ Future-Proofing)
		if ( $fallback ) {
			$default       = strtolower( self::get_default_language() );
			$default_short = substr( $default, 0, 2 );

			// Try site default (full then short)
			if ( isset( $map[ $default ] ) ) {
				return apply_filters( 'mhbo_i18n_decode', $map[ $default ], $text, $lang );
			}
			if ( isset( $map[ $default_short ] ) ) {
				return apply_filters( 'mhbo_i18n_decode', $map[ $default_short ], $text, $lang );
			}

			// Fallback to English (Global Standard)
			if ( isset( $map['en'] ) ) {
				return apply_filters( 'mhbo_i18n_decode', $map['en'], $text, $lang );
			}

			// Final fallback to any first available non-empty translation
			foreach ( $map as $val ) {
				if ( '' !== $val ) {
					return apply_filters( 'mhbo_i18n_decode', $val, $text, $lang );
				}
			}
		}

		// 4. Last resort: strip qTranslate tags and return result
		$result = preg_replace( '/\[:([a-z]{2}(?:_[a-z]{2})?)\]/i', '', $text );
		$result = str_replace( '[:]', '', (string) $result );

		return apply_filters( 'mhbo_i18n_decode', trim( (string) $result ) ?: $text, $text, $lang );
	}

	/**
	 * Encode an array of [lang => text] into a multilingual string.
	 *
	 * @param mixed $values The values to encode (typically an associative array).
	 * @return string The encoded multilingual string.
	 */
	public static function encode( mixed $values ): string {
		if ( ! is_array( $values ) ) {
			return (string) $values;
		}
		$out = '';
		foreach ( $values as $lang => $text ) {
			if ( '' !== $text ) {
				$out .= "[:{$lang}]{$text}";
			}
		}
		if ( '' !== $out ) {
			$out .= '[:]';
		}
		return $out;
	}

	/**
	 * Decode an already-translated string for multilingual (qTranslate-style) support.
	 *
	 * -------------------------------------------------------------------------
	 * IMPORTANT — Do NOT use this method for gettext string extraction.
	 * Always call WordPress __() / esc_html__() etc. with LITERAL strings
	 * and a LITERAL text domain FIRST, then pass the result here when you
	 * need multilingual (qTranslate-style [:xx]) decoding.
	 *
	 * Correct usage:
	 *   $translated = __( 'Search Rooms', 'modern-hotel-booking' );
	 *   $decoded    = I18n::translate_and_decode( $translated, 'ro' );
	 *
	 * Incorrect (blocks translation parser):
	 *   $decoded = I18n::translate_and_decode( $variable );
	 * -------------------------------------------------------------------------
	 *
	 * @param string      $translated Already-translated text (from __() or similar).
	 * @param string|null $language   Optional language code for multilingual strings.
	 * @return string Decoded text.
	 */
	public static function translate_and_decode( string $translated, ?string $language = null ): string {
		// If translation is empty, return as-is
		if ( '' === $translated ) {
			return $translated;
		}

		// If the translated string contains multilingual format, decode it
		if ( false !== strpos( $translated, '[:' ) ) {
			$decoded = self::decode( $translated, $language );
			if ( null !== $decoded && '' !== $decoded ) {
				return $decoded;
			}
		}

		return $translated;
	}

	/**
	 * Format currency based on site settings.
	 *
	 * @param float|int|string|Money $amount The numeric amount to format.
	 * @return string The formatted currency string.
	 */
	public static function format_currency( mixed $amount, ?int $decimals_override = null ): string {
		$symbol   = (string) get_option( 'mhbo_currency_symbol', '$' );
		$position = (string) get_option( 'mhbo_currency_position', 'before' );

		// Handle arrays from legacy or malformed JSON serialization
		if ( is_array( $amount ) ) {
			if ( isset( $amount['amount_in_cents'] ) && isset( $amount['currency'] ) ) {
				$amount = Money::fromCents( $amount['amount_in_cents'], $amount['currency'] );
			} else {
				$amount = 0; // Default fallback for entirely invalid data
			}
		}

		if ( $amount instanceof Money ) {
			$formatted_res = Pricing::format_price( $amount, false, $decimals_override );
		} else {
			$formatted_res = Pricing::format_price( is_numeric( $amount ) ? (string) $amount : '0', false, $decimals_override );
		}

		return (string) apply_filters( 'mhbo_currency_format', $formatted_res, $amount, $symbol, $position );
	}

	/**
	 * Format a date string based on WP settings.
	 *
	 * @param string $date_string The date string to format (Y-m-d).
	 * @return string The localized date string.
	 */
	public static function format_date( string $date_string ): string {
		if ( '' === $date_string ) {
			return '';
		}
		return HotelTime::format_date( $date_string );
	}

/**
	 * Get a localized label for a payment method.
	 *
	 * @param string|null $method The payment method key.
	 * @return string The localized label.
	 */
	public static function get_payment_method_label( ?string $method ): string {
		if ( null === $method || '' === $method ) {
			$method = 'arrival';
		}
		return apply_filters(
			'mhbo_payment_method_label',
			match ( $method ) {
			'stripe'            => __( 'Stripe', 'modern-hotel-booking' ),
			'paypal'            => __( 'PayPal', 'modern-hotel-booking' ),
			'braintree'         => __( 'Braintree', 'modern-hotel-booking' ),
			'arrival', 'onsite' => __( 'Pay on Arrival / Manual', 'modern-hotel-booking' ),
			default             => ucfirst( $method )
			},
			$method
		);
	}

	/**
	 * Get a localized label for front-end/admin use.
	 *
	 * @param string $key The label key.
	 * @return string The localized label.
	 */
	public static function get_label( string $key ): string {
		// Check for database override first
		$override = get_option( "mhbo_label_{$key}" );

		// Prevent early translation loading notice in WP 6.7+ before init action.
		if ( function_exists( 'did_action' ) && ! \did_action( 'init' ) ) {
			return ( false !== $override && '' !== $override && is_string( $override ) ) ? (string) $override : $key;
		}

		$labels      = self::get_all_default_labels();
		$default_val = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;

		$value = ( false !== $override && '' !== $override && is_string( $override ) ) ? (string) $override : $default_val;

		// Check for translated string via WPML/Polylang
		$translated = self::get_translated_string( "Label: {$key}", $value, 'MHBO Frontend Labels' );

		// If translation is found and not empty, decode it (it might still be qTranslate format)
		if ( '' !== $translated ) {
			$decoded = self::decode( $translated );
			if ( null !== $decoded && '' !== $decoded ) {
				return $decoded;
			}
		}

		// Fallback to English if the result is empty
		if ( isset( $labels[ $key ] ) ) {
			return self::decode( $labels[ $key ], 'en' ) ?? $key;
		}

		return $key;
	}

	/**
	 * Shortcut for get_label().
	 *
	 * @param string $key
	 * @return string
	 */
	public static function __( string $key ): string {
		return self::get_label( $key );
	}

	/**
	 * Echo localized label.
	 *
	 * @param string $key
	 * @return void
	 */
	public static function _e( string $key ): void {
		echo wp_kses_post( self::get_label( $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional raw echo; labels may contain controlled HTML; sibling esc_html_e() handles plain-text contexts.
	}

	/**
	 * Echo escaped localized label for HTML.
	 *
	 * @param string $key
	 * @return void
	 */
	public static function esc_html_e( string $key ): void {
		echo esc_html( self::get_label( $key ) );
	}

	/**
	 * Echo escaped localized label for attributes.
	 *
	 * @param string $key
	 * @return void
	 */
	public static function esc_attr_e( string $key ): void {
		echo esc_attr( self::get_label( $key ) );
	}

	/**
	 * Get all default labels in multilingual format.
	 *
	 * NOTE: Every __() call below uses a LITERAL string and the LITERAL
	 * text domain 'modern-hotel-booking' so that the WordPress translation
	 * parser can extract them. Never replace these with variables.
	 *
	 * @return array
	 */
	/**
	 * Cached default labels in memory.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $cached_default_labels = null;

	/**
	 * Get all default labels in multilingual format.
	 *
	 * Delegated to I18nLabels to avoid loading 270 KB of strings into memory on normal requests.
	 *
	 * @return array<string, string>
	 */
	public static function get_all_default_labels(): array {
		if ( null !== self::$cached_default_labels ) {
			return self::$cached_default_labels;
		}

		// Avoid premature catalog loading before init action.
		if ( function_exists( 'did_action' ) && ! \did_action( 'init' ) ) {
			return array();
		}

		self::$cached_default_labels = class_exists( I18nLabels::class ) ? I18nLabels::get_all() : array();
		return self::$cached_default_labels;
	}

	/**
	 * Get the structured translation labels catalog grouped by category and tier.
	 *
	 * @return array<string, array{
	 *     title: string,
	 *     tier: string,
	 *     icon: string,
	 *     labels: array<string, string>
	 * }>
	 */
	public static function get_labels_catalog(): array {
		return class_exists( I18nLabels::class ) ? I18nLabels::get_catalog() : array();
	}

/**
	 * Register a string for translation with WPML/Polylang.
	 *
	 * @param string $name    String name/identifier.
	 * @param string $value   String value.
	 * @param string $context Context/package name.
	 * @return void
	 */
	public static function register_string( string $name, string $value, string $context = 'Modern Hotel Booking' ): void {
		$plugin = self::detect_plugin();

		if ( 'wpml' === $plugin ) {
			do_action( 'wpml_register_single_string', $context, $name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML action
		} elseif ( 'polylang' === $plugin ) {
			if ( function_exists( 'pll_register_string' ) ) {
				call_user_func( 'pll_register_string', $name, $value, $context );
			}
		}
	}

	/**
	 * Get a translated string from WPML/Polylang
	 *
	 * @param string      $name String name/identifier
	 * @param string      $default Default value if not translated
	 * @param string      $context Context/package name
	 * @param string|null $language Language code (optional)
	 * @return string Translated string
	 */
	public static function get_translated_string( string $name, string $default = '', string $context = 'Modern Hotel Booking', ?string $language = null ): string {
		$plugin = self::detect_plugin();

		if ( null === $language ) {
			$language = self::get_current_language();
		}

		if ( 'wpml' === $plugin ) {
			$translated = apply_filters( 'wpml_translate_single_string', $default, $context, $name, $language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML filter
			return '' !== $translated ? $translated : $default;
		} elseif ( 'polylang' === $plugin ) {
			if ( function_exists( 'pll_translate_string' ) && '' !== $language ) {
				/** @phpstan-ignore argument.type */
				$translated = call_user_func( 'pll_translate_string', $default, $language );
				if ( is_string( $translated ) && '' !== $translated ) {
					return $translated;
				}
			}
			if ( function_exists( 'pll__' ) ) {
				$translated = call_user_func( 'pll__', $default );
				return is_string( $translated ) && '' !== $translated ? $translated : $default;
			}
		}

		$decoded = self::decode( $default, $language );
		return ( null !== $decoded && '' !== $decoded ) ? $decoded : $default;
	}

	/**
	 * Register all plugin strings for translation
	 * Should be called on plugin activation or admin init
	 */
	public static function register_plugin_strings(): void {
		// Register tax-related strings
		$tax_label = get_option( 'mhbo_tax_label', 'VAT' );
		self::register_string( 'Tax Label', $tax_label, 'MHBO Tax Settings' );

		// Register email template subjects and messages (All standard, admin, balance & VIP coupon statuses)
		$statuses = array(
			'pending',
			'confirmed',
			'cancelled',
			'payment',
			'stay_dates_updated',
			'cancellation_request',
			'modification_request',
			'email_updated',
			'identity_verification',
			'admin_new_booking',
			'admin_payment',
			'admin_modification_request',
			'admin_cancellation_request',
			'admin_cancellation',
			
		);
		foreach ( $statuses as $status ) {
			$subject = get_option( "mhbo_email_{$status}_subject", '' );
			if ( '' !== $subject ) {
				self::register_string( "Email {$status} Subject", $subject, 'MHBO Email Templates' );
			} else {
				$default_subject_key = "email_{$status}_subject";
				$default_sub         = self::get_label( $default_subject_key );
				if ( $default_subject_key !== $default_sub && '' !== $default_sub ) {
					self::register_string( "Email {$status} Subject", $default_sub, 'MHBO Email Templates' );
				}
			}
			$message = get_option( "mhbo_email_{$status}_message", '' );
			if ( '' !== $message ) {
				self::register_string( "Email {$status} Message", $message, 'MHBO Email Templates' );
			}
		}

		$footer = get_option( 'mhbo_email_footer_text', '' );
		if ( '' !== $footer ) {
			self::register_string( 'Email Footer Text', $footer, 'MHBO Email Templates' );
		}

		// Register frontend labels
		$labels = self::get_all_default_labels();
		foreach ( $labels as $key => $default_val ) {
			$label = get_option( "mhbo_label_{$key}", '' );
			if ( '' !== $label ) {
				self::register_string( "Label: {$key}", $label, 'MHBO Frontend Labels' );
			} else {
				self::register_string( "Label: {$key}", $default_val, 'MHBO Frontend Labels' );
			}
		}
	}

	/**
	 * Translate a booking status slug.
	 *
	 * @param string $status The status slug.
	 * @return string The translated status.
	 */
	public static function translate_status( string $status ): string {
		switch ( $status ) {
			case 'pending':
				return self::get_label( 'status_pending' );
			case 'confirmed':
				return self::get_label( 'status_confirmed' );
			case 'pending_payment':
				return self::get_label( 'status_pending_payment' );
			case 'checked_in':
				return self::get_label( 'status_checked_in' );
			case 'checked_out':
				return self::get_label( 'status_checked_out' );
			case 'cancelled':
				return self::get_label( 'status_cancelled' );
			case 'deposit_paid':
			case 'status_deposit_paid':
				return self::get_label( 'label_deposit_paid' );
			case 'completed':
			case 'paid':
				return self::get_label( 'status_completed' );
			case 'no_show':
				return self::get_label( 'status_no_show' );
			case 'refunded':
				return self::get_label( 'status_refunded' );
			case 'failed':
				return self::get_label( 'status_failed' );
			default:
				$key   = "status_{$status}";
				$label = self::get_label( $key );
				return $label !== $key ? $label : ucwords( str_replace( '_', ' ', $status ) );
		}
	}

	/**
	 * Translate a payment method slug.
	 *
	 * @param string $method The payment method slug.
	 * @return string The translated payment method.
	 */
	public static function translate_payment_method( string $method ): string {
		switch ( $method ) {
			case 'onsite':
			case 'arrival':
				return self::get_label( 'label_pay_arrival' );
			case 'stripe':
				return self::get_label( 'gateway_stripe' );
			case 'paypal':
				return self::get_label( 'gateway_paypal' );
			case 'braintree':
				return self::get_label( 'label_braintree' );
			default:
				return ucfirst( $method );
		}
	}

	/**
	 * Translate a payment status slug.
	 *
	 * @param string $status
	 * @return string
	 */
	public static function translate_payment_status( $status ): string {
		switch ( (string) $status ) {
			case 'paid':
			case 'completed':
			case 'paid_full':
				return self::get_label( 'status_completed' );
			case 'deposit_paid':
			case 'status_deposit_paid':
				return self::get_label( 'label_deposit_paid' );
			case 'overdue':
				return self::get_label( 'status_overdue' );
			case 'urgent_unpaid':
				return self::get_label( 'status_urgent_unpaid' );
			case 'processing':
				return self::get_label( 'status_processing' );
			case 'failed':
				return self::get_label( 'status_failed' );
			case 'refunded':
				return self::get_label( 'status_refunded' );
			case 'pending':
			case 'pending_payment':
			default:
				return self::get_label( 'status_pending' );
		}
	}

	/**
	 * Get the translated ID for any WordPress object (post, CPT, page, template) based on language context.
	 * Supports WPML, Polylang, and Bogo.
	 *
	 * @param int    $object_id The original object ID.
	 * @param string $type      The object type (e.g. 'page', 'mhbo_email_template', 'post').
	 * @param string $lang      Target language code (defaults to current language context).
	 * @return int The translated object ID, or the original ID.
	 */
	public static function get_translated_object_id( int $object_id, string $type = 'post', string $lang = '' ): int {
		if ( $object_id <= 0 ) {
			return 0;
		}

		if ( '' === $lang ) {
			$lang = self::get_current_language();
		}

		// 1. Polylang native
		if ( in_array( $type, array( 'term', 'category', 'post_tag', 'mhbo_room_type' ), true ) && function_exists( 'pll_get_term' ) ) {
			/** @phpstan-ignore argument.type */
			$translated_id = call_user_func( 'pll_get_term', $object_id, $lang );
			if ( $translated_id && is_numeric( $translated_id ) && (int) $translated_id > 0 ) {
				return (int) $translated_id;
			}
		} elseif ( function_exists( 'pll_get_post' ) ) {
			/** @phpstan-ignore argument.type */
			$translated_id = call_user_func( 'pll_get_post', $object_id, $lang );
			if ( $translated_id && is_numeric( $translated_id ) && (int) $translated_id > 0 ) {
				return (int) $translated_id;
			}
		}

		// 2. WPML compatibility layer
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
		if ( function_exists( 'has_filter' ) && has_filter( 'wpml_object_id' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
			$translated_id = apply_filters( 'wpml_object_id', $object_id, $type, true, $lang );
			if ( $translated_id && is_numeric( $translated_id ) && (int) $translated_id > 0 ) {
				return (int) $translated_id;
			}
		}

		// 3. Bogo native compatibility
		if ( function_exists( 'bogo_get_post_translations' ) ) {
			$translations = call_user_func( 'bogo_get_post_translations', $object_id );
			if ( is_array( $translations ) ) {
				/** @var array<string, \WP_Post> $translations */
				$current_locale = get_locale();
				if ( isset( $translations[ $current_locale ] ) ) {
					return (int) $translations[ $current_locale ]->ID;
				}
				foreach ( $translations as $locale_key => $trans_post ) {
					if ( substr( $locale_key, 0, 2 ) === $lang ) {
						return (int) $trans_post->ID;
					}
				}
			}
		}

		return $object_id;
	}

	/**
	 * Get the translated ID for a given WordPress Page ID based on the current language context.
	 * Supports WPML and Polylang via `wpml_object_id` filter and `pll_get_post`.
	 *
	 * @param int $page_id The original Page ID.
	 * @return int The translated Page ID, or the original if no translation is found.
	 */
	public static function get_translated_page_id( int $page_id ): int {
		if ( $page_id <= 0 ) {
			return 0;
		}

		$current_lang = self::get_current_language();

		// 0. Explicit per-language booking page setting override
		if ( $current_lang !== self::get_default_language() ) {
			$lang_specific_page = (int) get_option( 'mhbo_booking_page_' . $current_lang, 0 );
			if ( $lang_specific_page > 0 ) {
				return $lang_specific_page;
			}
		}

		return self::get_translated_object_id( $page_id, 'page', $current_lang );
	}

	/**
	 * Check if a currency code is a valid ISO-4217 code.
	 *
	 * This list includes common currencies supported by major payment processors (Stripe/PayPal).
	 *
	 * @param string $code 3-letter currency code.
	 * @return bool
	 */
	public static function is_valid_currency( string $code ): bool {
		$code        = strtoupper( trim( $code ) );
		$valid_codes = array(
			'USD',
			'EUR',
			'GBP',
			'JPY',
			'CAD',
			'AUD',
			'CHF',
			'CNY',
			'SEK',
			'NZD',
			'KRW',
			'SGD',
			'NOK',
			'MXN',
			'INR',
			'RUB',
			'ZAR',
			'TRY',
			'BRL',
			'TWD',
			'DKK',
			'PLN',
			'THB',
			'IDR',
			'HUF',
			'CZK',
			'ILS',
			'CLP',
			'PHP',
			'AED',
			'COP',
			'SAR',
			'MYR',
			'RON',
			'VND',
			'ARS',
			'EGP',
			'IRR',
			'KWD',
			'LKR',
			'UAH',
			'VEF',
			'HNL',
			'GTQ',
			'CRC',
			'DOP',
			'PEN',
			'UYU',
			'PYG',
			'BOB',
			'NIO',
			'ISK',
			'HRK',
			'BGN',
			'EEK',
			'SKK',
			'SIT',
			'CYP',
			'MTL',
			'TZS',
			'UGX',
			'KES',
			'GHS',
			'NGN',
			'ZMW',
			'MUR',
			'SCR',
			'MGA',
			'MAD',
			'TND',
			'OMR',
			'BHD',
			'JOD',
			'LBP',
			'AMD',
			'AZN',
			'GEL',
			'KZT',
			'UZS',
			'TJS',
			'KGS',
			'AFN',
			'PKR',
			'BDT',
			'NPR',
			'MVR',
			'MMK',
			'LAK',
			'KHR',
			'MOP',
			'HKD',
			'FJD',
			'XPF',
			'XAF',
			'XOF',
			'XCD',
			'ANG',
			'AWG',
			'BBD',
			'BSD',
			'BZD',
			'BMD',
			'GIP',
			'JMD',
			'KYD',
			'LRD',
			'SBD',
			'SRD',
			'TOP',
			'TTD',
			'VUV',
			'WST',
			'XDR',
		);
		return in_array( $code, $valid_codes, true );
	}
}
