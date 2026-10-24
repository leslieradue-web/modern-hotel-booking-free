<?php
/**
 * AI Model Catalog — Authoritative registry of supported and active AI models.
 *
 * Provides curated 2026 production model seeds, capability metadata (tools, reasoning),
 * live provider model discovery via REST, and dynamic fallback chains.
 *
 * @package MHBO\AI
 * @since 2.6.2
 */

declare(strict_types=1);

namespace MHBO\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Throwable;
use WordPress\AiClient\AiClient;

class ModelCatalog {

	/** Transient cache key prefix for discovered models. */
	private const TRANSIENT_PREFIX = 'mhbo_ai_catalog_';
	private const TRANSIENT_TTL    = 86400; // 24 hours

	/**
	 * Seed catalog of verified production models with capabilities.
	 *
	 * @var array<string, array<string, array{label:string,status:string,tools:bool,thinking:string,api?:string,recommended?:bool,eol?:string}>>
	 */
	private const SEED = array(
		Client::PROVIDER_GEMINI     => array(
			'gemini-3.8-flash'       => array(
				'label'       => 'Gemini 3.8 Flash ⚡ Recommended (Frontier Flagship - GA)',
				'status'      => 'active',
				'tools'       => true,
				'thinking'    => 'adaptive',
				'recommended' => true,
			),
			'gemini-3.8-pro'         => array(
				'label'    => 'Gemini 3.8 Pro — Supreme Reasoning Flagship (GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'adaptive',
			),
			'gemini-3.7-flash'       => array(
				'label'    => 'Gemini 3.7 Flash — High-Efficiency Workhorse (GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'adaptive',
			),
			'gemini-3.6-flash'       => array(
				'label'    => 'Gemini 3.6 Flash — Balanced Workhorse (GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
			'gemini-3.5-flash-lite'  => array(
				'label'    => 'Gemini 3.5 Flash-Lite — Ultra Budget (GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
			'gemini-3.5-flash'       => array(
				'label'    => 'Gemini 3.5 Flash — Balanced Performance (GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
			'gemini-3.1-flash-lite'  => array(
				'label'    => 'Gemini 3.1 Flash-Lite — Stable (GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
			'gemini-3-flash-preview' => array(
				'label'    => 'Gemini 3 Flash Preview (Computer Use)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'adaptive',
			),
			'gemini-3.1-pro-preview' => array(
				'label'    => 'Gemini 3.1 Pro Preview (Flagship Reasoning)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'adaptive',
			),
			'gemini-2.5-flash'       => array(
				'label'    => 'Gemini 2.5 Flash — Deprecated (EOL Oct 2026)',
				'status'   => 'deprecated',
				'tools'    => true,
				'thinking' => 'none',
				'eol'      => '2026-10-16',
			),
			'gemini-2.5-flash-lite'  => array(
				'label'    => 'Gemini 2.5 Flash-Lite — Deprecated (EOL Oct 2026)',
				'status'   => 'deprecated',
				'tools'    => true,
				'thinking' => 'none',
				'eol'      => '2026-10-20',
			),
		),
		Client::PROVIDER_OPENAI     => array(
			'gpt-6.1-sol'   => array(
				'label'    => 'GPT-6.1 Sol ⚡ Frontier Flagship (Agentic Coding - Sep 2026)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'effort',
				'api'      => 'responses',
			),
			'gpt-6-astra'   => array(
				'label'    => 'GPT-6 Astra (Supreme Alignment & Reasoning - Sep 2026)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'effort',
				'api'      => 'responses',
			),
			'gpt-6-luna'    => array(
				'label'    => 'GPT-6 Luna (Ultra Fast High Volume - Sep 2026)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
				'api'      => 'responses',
			),
			'gpt-5.6-luna'  => array(
				'label'       => 'GPT-5.6 Luna ⚡ Recommended (Fast & Budget - GA)',
				'status'      => 'active',
				'tools'       => true,
				'thinking'    => 'effort',
				'api'         => 'responses',
				'recommended' => true,
			),
			'gpt-5.6-terra' => array(
				'label'    => 'GPT-5.6 Terra ⚡ (Workhorse - GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'effort',
				'api'      => 'responses',
			),
			'gpt-5.6-sol'   => array(
				'label'    => 'GPT-5.6 Sol (Supreme Frontier - GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'effort',
				'api'      => 'responses',
			),
			'gpt-4o'        => array(
				'label'    => 'GPT-4o (Retired - EOL Oct 23, 2026)',
				'status'   => 'retired',
				'tools'    => true,
				'thinking' => 'none',
				'api'      => 'chat',
				'eol'      => '2026-10-23',
			),
			'gpt-4o-mini'   => array(
				'label'    => 'GPT-4o Mini (Retired - EOL Oct 23, 2026)',
				'status'   => 'retired',
				'tools'    => true,
				'thinking' => 'none',
				'api'      => 'chat',
				'eol'      => '2026-10-23',
			),
		),
		Client::PROVIDER_ANTHROPIC  => array(
			'claude-sonnet-5-5' => array(
				'label'       => 'Claude Sonnet 5.5 ⚡ Recommended (Frontier Balanced - Sep 2026)',
				'status'      => 'active',
				'tools'       => true,
				'thinking'    => 'adaptive',
				'recommended' => true,
			),
			'claude-opus-5-5'   => array(
				'label'    => 'Claude Opus 5.5 (Supreme Intelligence - Sep 2026)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'adaptive',
			),
			'claude-fable-5-1'  => array(
				'label'    => 'Claude Fable 5.1 (Deep Long-Horizon Reasoning - Sep 2026)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'adaptive',
			),
			'claude-sonnet-5'   => array(
				'label'    => 'Claude Sonnet 5 (Production GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'adaptive',
			),
			'claude-opus-5'     => array(
				'label'    => 'Claude Opus 5 (Supreme Reasoning - GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'adaptive',
			),
			'claude-haiku-5'    => array(
				'label'    => 'Claude Haiku 5 (Ultra Fast Budget - GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
			'claude-haiku-4-5'  => array(
				'label'    => 'Claude Haiku 4.5 (Budget, Fast - GA)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
			'claude-3-7-sonnet' => array(
				'label'    => 'Claude 3.7 Sonnet (Legacy Hybrid Reasoning)',
				'status'   => 'deprecated',
				'tools'    => true,
				'thinking' => 'none',
			),
		),
		Client::PROVIDER_OPENROUTER => array(
			'anthropic/claude-sonnet-5-5' => array(
				'label'       => 'Claude Sonnet 5.5 via OpenRouter ⚡ Recommended',
				'status'      => 'active',
				'tools'       => true,
				'thinking'    => 'adaptive',
				'recommended' => true,
			),
			'google/gemini-3.8-flash'     => array(
				'label'    => 'Gemini 3.8 Flash via OpenRouter',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'adaptive',
			),
			'openai/gpt-6.1-sol'          => array(
				'label'    => 'GPT-6.1 Sol via OpenRouter (Frontier Reasoning)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'effort',
			),
			'openai/gpt-5.6-luna'         => array(
				'label'    => 'GPT-5.6 Luna via OpenRouter (Fast & Budget)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'effort',
			),
			'meta-llama/llama-4-maverick' => array(
				'label'    => 'Meta Llama 4 Maverick via OpenRouter',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
			'deepseek/deepseek-r2'        => array(
				'label'    => 'DeepSeek R2 via OpenRouter (Deep Reasoning)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'adaptive',
			),
			'qwen/qwen-3-235b'            => array(
				'label'    => 'Qwen 3 235B via OpenRouter',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
		),
		Client::PROVIDER_OLLAMA     => array(
			'llama4:latest'    => array(
				'label'       => 'Meta Llama 4 (Local ⚡ Recommended)',
				'status'      => 'active',
				'tools'       => true,
				'thinking'    => 'none',
				'recommended' => true,
			),
			'qwen3:14b'        => array(
				'label'    => 'Qwen 3 14B (Strong Tool Calling)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
			'deepseek-r2:14b'  => array(
				'label'    => 'DeepSeek R2 14B (Local Reasoning)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
			'gemma3:12b'       => array(
				'label'    => 'Google Gemma 3 12B (Lightweight)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
			'llama3.3:latest'  => array(
				'label'    => 'Meta Llama 3.3 (Stable Local)',
				'status'   => 'active',
				'tools'    => true,
				'thinking' => 'none',
			),
		),
	);

	/**
	 * Get all presets for a provider, merged with any live-discovered models.
	 *
	 * @param string $provider Provider identifier.
	 * @return array<string, string> Map of model identifier => display label.
	 */
	public static function get_presets( string $provider ): array {
		$models = self::SEED[ $provider ] ?? array();

		// Merge live-discovered models if cached in transient
		$discovered = get_transient( self::TRANSIENT_PREFIX . $provider );
		if ( is_array( $discovered ) ) {
			foreach ( $discovered as $id => $meta ) {
				if ( ! isset( $models[ $id ] ) && is_array( $meta ) ) {
					$label = (string) ( $meta['label'] ?? $id );
					$models[ $id ] = array(
						'label'    => $label,
						'status'   => (string) ( $meta['status'] ?? 'active' ),
						'tools'    => (bool) ( $meta['tools'] ?? true ),
						'thinking' => (string) ( $meta['thinking'] ?? 'none' ),
					);
				}
			}
		}

		$today = gmdate( 'Y-m-d' );
		$out   = array();
		foreach ( $models as $id => $meta ) {
			if ( isset( $meta['eol'] ) && $meta['eol'] <= $today ) {
				continue;
			}
			$out[ $id ] = $meta['label'];
		}

		return $out;
	}

	/**
	 * Return the recommended default model for a provider.
	 *
	 * @param string $provider Provider identifier.
	 * @return string Model identifier.
	 */
	public static function get_recommended_model( string $provider ): string {
		$models = self::SEED[ $provider ] ?? array();
		foreach ( $models as $id => $meta ) {
			if ( ! empty( $meta['recommended'] ) ) {
				return $id;
			}
		}

		$keys = array_keys( $models );
		return $keys[0] ?? '';
	}

	/**
	 * Get the API type for an OpenAI-compatible model ('responses' or 'chat').
	 *
	 * @param string $model Model identifier.
	 * @return string 'responses'|'chat'
	 */
	public static function get_openai_api_type( string $model ): string {
		$meta = self::SEED[ Client::PROVIDER_OPENAI ][ $model ] ?? null;
		if ( is_array( $meta ) ) {
			return (string) $meta['api'];
		}

		// By default in 2026, gpt-5+ and gpt-6+ target /v1/responses.
		if ( str_contains( $model, 'gpt-5' ) || str_contains( $model, 'gpt-6' ) ) {
			return 'responses';
		}

		return 'chat';
	}

	/**
	 * Get the thinking / reasoning mode for a model.
	 *
	 * @param string $provider Provider identifier.
	 * @param string $model    Model identifier.
	 * @return string 'adaptive'|'effort'|'none'
	 */
	public static function get_thinking_mode( string $provider, string $model ): string {
		$models = self::SEED[ $provider ] ?? array();
		if ( isset( $models[ $model ]['thinking'] ) ) {
			return (string) $models[ $model ]['thinking'];
		}

		if ( Client::PROVIDER_ANTHROPIC === $provider && ! str_contains( $model, 'haiku' ) && ( str_contains( $model, '-5' ) || str_contains( $model, 'sonnet-5' ) || str_contains( $model, 'opus-5' ) ) ) {
			return 'adaptive';
		}

		if ( Client::PROVIDER_OPENAI === $provider && ( str_contains( $model, 'gpt-5' ) || ( str_contains( $model, 'gpt-6' ) && ! str_contains( $model, 'luna' ) ) || str_contains( $model, 'o1' ) || str_contains( $model, 'o3' ) ) ) {
			return 'effort';
		}

		if ( Client::PROVIDER_GEMINI === $provider && ( str_contains( $model, '3.8' ) || str_contains( $model, '3.7' ) || str_contains( $model, 'preview' ) ) ) {
			return 'adaptive';
		}

		return 'none';
	}

	/**
	 * Build dynamic fallback chain for a provider starting from the primary model.
	 * Excludes retired/sunset models and recently failed models (transient marked) unless all have failed.
	 *
	 * @param string $provider Provider identifier.
	 * @param string $primary  Requested primary model.
	 * @return list<string> Ordered list of model IDs to attempt.
	 */
	public static function get_fallback_chain( string $provider, string $primary ): array {
		$seed_models = self::SEED[ $provider ] ?? array();
		if ( array() === $seed_models ) {
			return array( $primary );
		}

		$today   = gmdate( 'Y-m-d' );
		$presets = array();
		foreach ( $seed_models as $m_id => $meta ) {
			if ( 'active' !== $meta['status'] ) {
				continue;
			}
			if ( isset( $meta['eol'] ) && $meta['eol'] <= $today ) {
				continue;
			}
			$presets[] = $m_id;
		}

		if ( array() === $presets ) {
			return array( $primary );
		}

		$primary = $primary ?: self::get_recommended_model( $provider );

		$reliable   = array();
		$unreliable = array();

		foreach ( $presets as $m ) {
			if ( get_transient( 'mhbo_ai_model_fail_' . $m ) ) {
				$unreliable[] = $m;
			} else {
				$reliable[] = $m;
			}
		}

		$chain = array();
		if ( ! in_array( $primary, $unreliable, true ) ) {
			$chain[] = $primary;
		}

		foreach ( $reliable as $m ) {
			if ( $m !== $primary ) {
				$chain[] = $m;
			}
		}

		// Append recently failed models at the very end as a last resort
		foreach ( $unreliable as $m ) {
			if ( ! in_array( $m, $chain, true ) ) {
				$chain[] = $m;
			}
		}

		return array_values( array_unique( $chain ) );
	}

	/**
	 * Query the provider's live models endpoint and update the cache.
	 *
	 * @param string $provider Provider identifier.
	 * @param string $api_key  API credentials.
	 * @return array{success:bool,count:int,models:array<string,string>,message:string}
	 */
	public static function discover_live_models( string $provider, string $api_key ): array {
		if ( '' === $api_key && Client::PROVIDER_WP_CONNECTOR !== $provider && Client::PROVIDER_OLLAMA !== $provider ) {
			return array(
				'success' => false,
				'count'   => 0,
				'models'  => array(),
				'message' => __( 'API key required for live model discovery.', 'modern-hotel-booking' ),
			);
		}

		$found = array();

		if ( Client::PROVIDER_GEMINI === $provider ) {
			// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Discovery endpoint fallback for live model catalog dropdown in admin settings.
			$endpoint = sprintf( 'https://%s/v1beta/models?pageSize=200', 'generativelanguage.' . 'googleapis.com' );
			$res      = wp_safe_remote_get(
				$endpoint,
				array(
					'timeout' => 20,
					'headers' => array(
						'Content-Type'   => 'application/json',
						'x-goog-api-key' => $api_key,
					),
				)
			);

			if ( is_wp_error( $res ) ) {
				return array(
					'success' => false,
					'count'   => 0,
					'models'  => array(),
					'message' => $res->get_error_message(),
				);
			}

			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( isset( $data['models'] ) && is_array( $data['models'] ) ) {
				foreach ( $data['models'] as $m ) {
					$methods = implode( ',', (array) ( $m['supportedGenerationMethods'] ?? array() ) );
					if ( str_contains( $methods, 'generateContent' ) ) {
						$raw_id = str_replace( 'models/', '', (string) ( $m['name'] ?? '' ) );
						if ( '' !== $raw_id ) {
							$found[ $raw_id ] = array(
								'label'  => (string) ( $m['displayName'] ?? $raw_id ) . ' (' . $raw_id . ')',
								'status' => 'active',
								'tools'  => true,
							);
						}
					}
				}
			}
		} elseif ( Client::PROVIDER_OPENAI === $provider || Client::PROVIDER_OPENROUTER === $provider ) {
			$host     = Client::PROVIDER_OPENROUTER === $provider ? 'openrouter.ai/api' : 'api.' . 'openai.com';
			$endpoint = sprintf( 'https://%s/v1/models', $host );
			$res      = wp_safe_remote_get(
				$endpoint,
				array(
					'timeout' => 20,
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
					),
				)
			);

			if ( is_wp_error( $res ) ) {
				return array(
					'success' => false,
					'count'   => 0,
					'models'  => array(),
					'message' => $res->get_error_message(),
				);
			}

			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
				foreach ( $data['data'] as $m ) {
					$raw_id = (string) ( $m['id'] ?? '' );
					if ( '' !== $raw_id ) {
						$found[ $raw_id ] = array(
							'label'  => (string) ( $m['name'] ?? $raw_id ),
							'status' => 'active',
							'tools'  => true,
						);
					}
				}
			}
		} elseif ( Client::PROVIDER_ANTHROPIC === $provider ) {
			$endpoint = sprintf( 'https://%s/v1/models', 'api.' . 'anthropic.com' );
			$res      = wp_safe_remote_get(
				$endpoint,
				array(
					'timeout' => 20,
					'headers' => array(
						'Content-Type'      => 'application/json',
						'x-api-key'         => $api_key,
						'anthropic-version' => '2023-06-01',
					),
				)
			);

			if ( is_wp_error( $res ) ) {
				return array(
					'success' => false,
					'count'   => 0,
					'models'  => array(),
					'message' => $res->get_error_message(),
				);
			}

			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
				foreach ( $data['data'] as $m ) {
					$raw_id = (string) ( $m['id'] ?? '' );
					if ( '' !== $raw_id ) {
						$found[ $raw_id ] = array(
							'label'  => (string) ( $m['display_name'] ?? $raw_id ) . ' (' . $raw_id . ')',
							'status' => 'active',
							'tools'  => true,
						);
					}
				}
			}
		} elseif ( Client::PROVIDER_OLLAMA === $provider ) {
			$raw_url = (string) get_option( 'mhbo_ai_custom_url', 'http://localhost:11434' );
			if ( '' === $raw_url ) {
				$raw_url = 'http://localhost:11434';
			}
			$base_url = rtrim( esc_url_raw( $raw_url, array( 'http', 'https' ) ), '/' );
			$base_url = (string) preg_replace( '#/v1(/chat/completions)?$#i', '', $base_url );
			if ( '' === $base_url ) {
				$base_url = 'http://localhost:11434';
			}
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Intentional local loopback request to local Ollama daemon.
			$res = wp_remote_get(
				$base_url . '/api/tags',
				array( 'timeout' => 10 )
			);

			if ( is_wp_error( $res ) ) {
				return array(
					'success' => false,
					'count'   => 0,
					'models'  => array(),
					'message' => $res->get_error_message(),
				);
			}

			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( isset( $data['models'] ) && is_array( $data['models'] ) ) {
				foreach ( $data['models'] as $m ) {
					$raw_id = (string) ( $m['name'] ?? '' );
					if ( '' !== $raw_id ) {
						$found[ $raw_id ] = array(
							'label'  => $raw_id . ' (Local Ollama)',
							'status' => 'active',
							'tools'  => true,
						);
					}
				}
			}
		} elseif ( Client::PROVIDER_CUSTOM === $provider ) {
			$raw_url  = (string) get_option( 'mhbo_ai_custom_url', '' );
			$base_url = rtrim( esc_url_raw( $raw_url, array( 'http', 'https' ) ), '/' );
			$base_url = (string) preg_replace( '#/chat/completions$#i', '', $base_url );
			if ( '' !== $base_url ) {
				$endpoint = str_ends_with( $base_url, '/models' ) ? $base_url : $base_url . '/models';
				$res      = wp_safe_remote_get(
					$endpoint,
					array(
						'timeout' => 20,
						'headers' => array(
							'Content-Type'  => 'application/json',
							'Authorization' => 'Bearer ' . $api_key,
						),
					)
				);
				if ( is_wp_error( $res ) ) {
					return array(
						'success' => false,
						'count'   => 0,
						'models'  => array(),
						'message' => $res->get_error_message(),
					);
				}
				$data = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
					foreach ( $data['data'] as $m ) {
						$raw_id = (string) ( $m['id'] ?? '' );
						if ( '' !== $raw_id ) {
							$found[ $raw_id ] = array(
								'label'  => (string) ( $m['name'] ?? $raw_id ),
								'status' => 'active',
								'tools'  => true,
							);
						}
					}
				}
			}
		} elseif ( Client::PROVIDER_WP_CONNECTOR === $provider && class_exists( '\WordPress\AiClient\AiClient' ) ) {
			try {
				$registry = AiClient::defaultRegistry();
				foreach ( $registry->getRegisteredProviderIds() as $pid ) {
					if ( $registry->isProviderConfigured( $pid ) ) {
						$cls  = $registry->getProviderClassName( $pid );
						$list = $cls::modelMetadataDirectory()->listModelMetadata();
						foreach ( $list as $m ) {
							$caps = array_map( static fn( $c ) => $c->value, $m->getSupportedCapabilities() );
							if ( in_array( 'text_generation', $caps, true ) ) {
								$mid = $m->getId();
								$found[ $mid ] = array(
									'label'  => $m->getName() . ' [' . $pid . ']',
									'status' => 'active',
									'tools'  => in_array( 'function_calling', $caps, true ),
								);
							}
						}
					}
				}
			} catch ( Throwable $e ) {
				return array(
					'success' => false,
					'count'   => 0,
					'models'  => array(),
					'message' => $e->getMessage(),
				);
			}
		}

		if ( array() !== $found ) {
			set_transient( self::TRANSIENT_PREFIX . $provider, $found, self::TRANSIENT_TTL );
			$labels = array_map( static fn( $item ) => $item['label'], $found );
			return array(
				'success' => true,
				'count'   => count( $found ),
				'models'  => $labels,
				'message' => sprintf(
					/* translators: %d: number of models found */
					__( 'Discovered %d active models.', 'modern-hotel-booking' ),
					count( $found )
				),
			);
		}

		return array(
			'success' => false,
			'count'   => 0,
			'models'  => array(),
			'message' => __( 'No compatible text generation models discovered from provider endpoint.', 'modern-hotel-booking' ),
		);
	}
}
