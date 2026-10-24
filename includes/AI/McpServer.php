<?php
declare(strict_types=1);

namespace MHBO\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MHBO\Core\Security;
use MHBO\Core\I18n;
use MHBO\AI\SiteScanner;
use MHBO\AI\Abilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Throwable;

/**
 * Model Context Protocol (MCP) Server — Hybrid Dual-Engine Architecture (2026 Edition).
 *
 * Implements standard MCP Streamable HTTP / JSON-RPC 2.0 transport for seamless
 * integration with Claude Desktop, Cursor, ChatGPT Actions, and autonomous AI agents.
 *
 * Zero-dependency standalone execution out-of-the-box with optional bridge
 * to the official WordPress/mcp-adapter if installed.
 *
 * @package MHBO\AI
 * @since   2.3.8
 * @version 3.0.0 (Streamable HTTP 2026 Edition)
 */
class McpServer {

	public const SERVER_ID    = 'modern-hotel-booking-mcp';
	public const SERVER_NS    = 'modern-hotel-booking/v1';
	public const SERVER_ROUTE = 'mcp';

	/**
	 * Supported MCP protocol versions in chronological order.
	 *
	 * @var array<string>
	 */
	public const SUPPORTED_VERSIONS = array(
		'2024-11-05',
		'2025-03-26',
	);

	/**
	 * Canonical negotiated protocol version default.
	 */
	public const LATEST_VERSION = '2025-03-26';

	/**
	 * Client identifier captured during initialize handshake (for booking attribution).
	 *
	 * @var string
	 */
	private static string $client_name = 'ai_agent';

	/**
	 * Initialise the MCP server hooks.
	 *
	 * Follows strict opt-in attack surface reduction: routes are only registered
	 * when mhbo_ai_mcp_enabled is truthy.
	 */
	public static function init(): void {
		$mcp_enabled = (bool) get_option( 'mhbo_ai_mcp_enabled', 0 );

		if ( ! $mcp_enabled ) {
			return;
		}

		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

		// WP 7.0 native Connectors API forward compatibility.
		$hybrid_enabled = (bool) get_option( 'mhbo_ai_mcp_hybrid_bridge', 0 );

		if ( $hybrid_enabled && function_exists( 'wp_register_mcp_server' ) ) {
			self::register_native();
		}

		// Official WordPress/mcp-adapter plugin bridge compatibility.
		if ( $hybrid_enabled && class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			add_action( 'mcp_adapter_init', array( self::class, 'register_adapter' ) );
		}
	}

	/**
	 * Register the REST API routes for MCP Streamable HTTP and OpenAPI.
	 */
	public static function register_rest_routes(): void {
		// 1. Canonical MCP Streamable HTTP endpoint.
		register_rest_route(
			self::SERVER_NS,
			'/' . self::SERVER_ROUTE,
			array(
				array(
					'methods'             => array( 'GET', 'POST', 'OPTIONS' ),
					'callback'            => array( self::class, 'handle_request' ),
					'permission_callback' => '__return_true', // Public access with internal method-level capability enforcement.
				),
			)
		);

		// 2. OpenAPI 3.1.0 specification for ChatGPT Actions & Custom GPTs.
		register_rest_route(
			self::SERVER_NS,
			'/openapi.json',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'handle_openapi' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// 3. Direct REST Endpoints (matching OpenAPI paths for ChatGPT Custom Actions).
		$public_endpoints = array(
			'availability' => array( self::class, 'rest_availability' ),
			'hotel-info'   => array( self::class, 'rest_hotel_info' ),
			'rooms'        => array( self::class, 'rest_rooms' ),
			'policies'     => array( self::class, 'rest_policies' ),
			'quote'        => array( self::class, 'rest_quote' ),
			'booking-link' => array( self::class, 'rest_booking_link' ),
		);

		foreach ( $public_endpoints as $route => $handler ) {
			register_rest_route(
				self::SERVER_NS,
				'/' . $route,
				array(
					array(
						'methods'             => array( 'GET', 'POST' ),
						'callback'            => $handler,
						'permission_callback' => '__return_true',
					),
				)
			);
		}

		// 4. Official Adapter route alias fallback (only if official adapter is NOT actively bridged).
		$hybrid_enabled = (bool) get_option( 'mhbo_ai_mcp_hybrid_bridge', 0 );
		if ( ! ( $hybrid_enabled && class_exists( '\WP\MCP\Core\McpAdapter' ) ) ) {
			register_rest_route(
				'mcp',
				'/mcp-adapter-default-server',
				array(
					array(
						'methods'             => array( 'GET', 'POST', 'OPTIONS' ),
						'callback'            => array( self::class, 'handle_request' ),
						'permission_callback' => '__return_true',
					),
				)
			);
		}
	}

	/**
	 * Dispatch incoming MCP Streamable HTTP requests (GET, POST, OPTIONS).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_request( WP_REST_Request $request ): WP_REST_Response {
		self::set_cors_headers();

		$method = $request->get_method();

		// 1. Preflight OPTIONS request handler.
		if ( 'OPTIONS' === $method ) {
			return new WP_REST_Response( null, 200 );
		}

		// 2. HTTP GET request handler.
		if ( 'GET' === $method ) {
			$accept = (string) $request->get_header( 'accept' );

			// Reject long-lived SSE requests with HTTP 405 (PHP-FPM worker protection).
			if ( false !== stripos( $accept, 'text/event-stream' ) ) {
				return new WP_REST_Response(
					array(
						'error' => __( 'Server-Sent Events (SSE) is not supported. Please use Streamable HTTP via POST requests with JSON-RPC 2.0.', 'modern-hotel-booking' ),
					),
					405,
					array( 'Allow' => 'POST, OPTIONS' )
				);
			}

			// Diagnostic status JSON report for browser inspection.
			return new WP_REST_Response(
				array(
					'name'                => 'Modern Hotel Booking MCP Server',
					'server_id'           => self::SERVER_ID,
					'version'             => defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '3.0.0',
					'status'              => 'active',
					'transport'           => 'Streamable HTTP (JSON-RPC 2.0 over POST)',
					'supported_protocols' => self::SUPPORTED_VERSIONS,
					'endpoint'            => self::get_endpoint_url(),
					'openapi_url'         => self::get_openapi_url(),
					'llms_txt'            => home_url( '/llms.txt' ),
					'capabilities'        => array(
						'tools'     => count( self::get_tools_list( self::is_operator_authenticated() ) ),
						'resources' => self::is_pro_active() ? 1 : 0,
					),
					'tier'                => self::is_pro_active() ? 'pro' : 'free',
				),
				200
			);
		}

		// 3. POST JSON-RPC 2.0 Request processing.
		$raw_body = $request->get_body();
		if ( strlen( $raw_body ) > 65536 ) {
			return self::json_rpc_error( null, -32600, __( 'Request payload exceeds 64KB maximum size.', 'modern-hotel-booking' ), 400 );
		}

		/**
		 * Decoded JSON-RPC request body.
		 *
		 * @var array<string, mixed>|null $body
		 */
		$body = json_decode( $raw_body, true );
		if ( ! is_array( $body ) || array() === $body ) {
			return self::json_rpc_error( null, -32700, __( 'Parse error: invalid JSON payload.', 'modern-hotel-booking' ), 400 );
		}

		$jsonrpc_ver = (string) ( $body['jsonrpc'] ?? '' );
		$rpc_method  = (string) ( $body['method'] ?? '' );
		$rpc_id      = $body['id'] ?? null;
		$params      = is_array( $body['params'] ?? null ) ? $body['params'] : array();

		// Validate JSON-RPC 2.0 structure.
		if ( '2.0' !== $jsonrpc_ver || '' === $rpc_method ) {
			return self::json_rpc_error( $rpc_id, -32600, __( 'Invalid Request: jsonrpc must be "2.0" with a valid method.', 'modern-hotel-booking' ), 400 );
		}

		// Handle notifications (no id expected, return HTTP 202 Accepted with empty body).
		if ( null === $rpc_id || 'notifications/initialized' === $rpc_method ) {
			return new WP_REST_Response( null, 202 );
		}

		// Dispatch JSON-RPC methods.
		switch ( $rpc_method ) {
			case 'initialize':
				return self::handle_initialize( $rpc_id, $params );

			case 'ping':
				return new WP_REST_Response(
					array(
						'jsonrpc' => '2.0',
						'id'      => $rpc_id,
						'result'  => (object) array(),
					),
					200
				);

			case 'tools/list':
				return self::handle_tools_list( $rpc_id );

			case 'tools/call':
				return self::handle_tools_call( $rpc_id, $params );

			case 'resources/list':
				return self::handle_resources_list( $rpc_id );

			case 'resources/read':
				return self::handle_resources_read( $rpc_id, $params );

			default:
				return self::json_rpc_error(
					$rpc_id,
					-32601,
					sprintf(
						/* translators: %s: JSON-RPC method name. */
						__( 'Method not found: %s', 'modern-hotel-booking' ),
						$rpc_method
					),
					404
				);
		}
	}

	/**
	 * Handle initialize JSON-RPC request with protocol version negotiation.
	 *
	 * @param mixed $rpc_id
	 * @param array<string, mixed> $params
	 * @return WP_REST_Response
	 */
	private static function handle_initialize( mixed $rpc_id, array $params ): WP_REST_Response {
		$requested_version = (string) ( $params['protocolVersion'] ?? '' );
		$negotiated_version = in_array( $requested_version, self::SUPPORTED_VERSIONS, true ) ? $requested_version : self::LATEST_VERSION;

		// Extract client info for attribution.
		$client_info = is_array( $params['clientInfo'] ?? null ) ? $params['clientInfo'] : array();
		if ( ! empty( $client_info['name'] ) && is_string( $client_info['name'] ) ) {
			self::$client_name = sanitize_key( $client_info['name'] );
		}

		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'result'  => array(
					'protocolVersion' => $negotiated_version,
					'capabilities'    => array(
						'tools'     => (object) array( 'listChanged' => false ),
						'resources' => (object) array( 'subscribe' => false, 'listChanged' => false ),
					),
					'serverInfo'      => array(
						'name'    => 'Modern Hotel Booking MCP Server',
						'version' => defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '3.0.0',
					),
					'instructions'    => __( 'Official Model Context Protocol server for Modern Hotel Booking. Query real-time room availability, policies, rates, and direct booking links.', 'modern-hotel-booking' ),
				),
			),
			200
		);
	}

	/**
	 * Handle tools/list JSON-RPC request (caller-scoped filtering).
	 *
	 * @param mixed $rpc_id
	 * @return WP_REST_Response
	 */
	private static function handle_tools_list( mixed $rpc_id ): WP_REST_Response {
		$is_operator = self::is_operator_authenticated();
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'result'  => array(
					'tools' => self::get_tools_list( $is_operator ),
				),
			),
			200
		);
	}

	/**
	 * Handle tools/call JSON-RPC request with rate limiting and security gates.
	 *
	 * @param mixed $rpc_id
	 * @param array<string, mixed> $params
	 * @return WP_REST_Response
	 */
	private static function handle_tools_call( mixed $rpc_id, array $params ): WP_REST_Response {
		$tool_name = (string) ( $params['name'] ?? '' );
		$arguments = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array();

		// 1. Rate Limiting Check (only tools/call counts towards quota).
		$ip       = Security::get_client_ip();
		$ip_key   = 'mhbo_mcp_rl_' . md5( $ip );
		$is_pro   = self::is_pro_active();
		$max_rate = $is_pro ? 200 : ( ( class_exists( '\MHBO\Core\Tier' ) && \MHBO\Core\Tier::get_package_tier() === 'starter' ) ? 60 : 30 );

		$count = (int) get_transient( $ip_key );
		if ( $count >= $max_rate ) {
			return self::json_rpc_error(
				$rpc_id,
				-32000,
				__( 'Rate limit exceeded. Please wait before executing more tool calls.', 'modern-hotel-booking' ),
				429,
				array( 'Retry-After' => '3600' )
			);
		}
		set_transient( $ip_key, $count + 1, HOUR_IN_SECONDS );

		// 2. Oracle Attack Protection: extra sub-limit on coupon guessing.
		if ( in_array( $tool_name, array( 'apply_promo_code', 'mhbo_apply_promo_code', 'mhbo/apply-promo-code' ), true ) ) {
			$promo_key   = 'mhbo_mcp_promo_' . md5( $ip );
			$promo_count = (int) get_transient( $promo_key );
			if ( $promo_count >= 10 ) {
				return self::json_rpc_error(
					$rpc_id,
					-32000,
					__( 'Promotional code rate limit exceeded. Try again in one hour.', 'modern-hotel-booking' ),
					429,
					array( 'Retry-After' => '3600' )
				);
			}
			set_transient( $promo_key, $promo_count + 1, HOUR_IN_SECONDS );
		}

		// 3. Resolve tool implementation class.
		$map = self::get_ability_map();
		if ( ! isset( $map[ $tool_name ] ) ) {
			return self::json_rpc_error(
				$rpc_id,
				-32601,
				sprintf(
					/* translators: %s: MCP tool name. */
					__( 'Tool not found: %s', 'modern-hotel-booking' ),
					$tool_name
				),
				404
			);
		}

		$class_name = $map[ $tool_name ];

		try {
			if ( ! method_exists( $class_name, 'execute' ) ) {
				return self::json_rpc_error( $rpc_id, -32603, __( 'Tool implementation error: missing execute method.', 'modern-hotel-booking' ), 500 );
			}

			// 4. Enforce permission checks defined on the ability class.
			if ( method_exists( $class_name, 'get_definition' ) ) {
				$def = $class_name::get_definition();
				if ( isset( $def['permission_callback'] ) && is_callable( $def['permission_callback'] ) ) {
					$permitted = call_user_func( $def['permission_callback'] );
					if ( ! $permitted ) {
						return self::json_rpc_error(
							$rpc_id,
							-32001,
							__( 'Permission denied: Operator credentials (WordPress Application Password) required for this tool.', 'modern-hotel-booking' ),
							401,
							array( 'WWW-Authenticate' => 'Basic realm="Modern Hotel Booking MCP"' )
						);
					}
				}
			}

			// Inject UTM attribution into booking links.
			if ( in_array( $tool_name, array( 'create_booking_link', 'mhbo_create_booking_link', 'mhbo/create-booking-link' ), true ) ) {
				$arguments['utm_source']   = 'mcp';
				$arguments['utm_medium']   = 'ai_concierge';
				$arguments['utm_campaign'] = self::$client_name;
			}

/**
			 * Result payload from tool execution.
			 *
			 * @var array<string, mixed>|string $output
			 */
			$output = $class_name::execute( $arguments );

			// Check for domain-level business errors (dates unavailable, invalid params).
			if ( is_array( $output ) && ! empty( $output['error'] ) ) {
				return new WP_REST_Response(
					array(
						'jsonrpc' => '2.0',
						'id'      => $rpc_id,
						'result'  => array(
							'content'           => array(
								array(
									'type' => 'text',
									'text' => (string) $output['error'],
								),
							),
							'structuredContent' => $output,
							'isError'           => true,
						),
					),
					200
				);
			}

			// Formatted successful result.
			$text_content = is_string( $output ) ? $output : (string) wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $rpc_id,
					'result'  => array(
						'content'           => array(
							array(
								'type' => 'text',
								'text' => $text_content,
							),
						),
						'structuredContent' => is_array( $output ) ? $output : null,
					),
				),
				200
			);
		} catch ( Throwable $e ) {
			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => $rpc_id,
					'result'  => array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Execution error: ' . $e->getMessage(),
							),
						),
						'isError' => true,
					),
				),
				200
			);
		}
	}

	/**
	 * Handle resources/list JSON-RPC request.
	 *
	 * @param mixed $rpc_id
	 * @return WP_REST_Response
	 */
	private static function handle_resources_list( mixed $rpc_id ): WP_REST_Response {
		$resources = array();

return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'result'  => array(
					'resources' => $resources,
				),
			),
			200
		);
	}

	/**
	 * Handle resources/read JSON-RPC request.
	 *
	 * @param mixed $rpc_id
	 * @param array<string, mixed> $params
	 * @return WP_REST_Response
	 */
	private static function handle_resources_read( mixed $rpc_id, array $params ): WP_REST_Response {
		$uri = (string) ( $params['uri'] ?? '' );

return self::json_rpc_error(
			$rpc_id,
			-32602,
			sprintf(
				/* translators: %s: Resource URI. */
				__( 'Resource not found: %s', 'modern-hotel-booking' ),
				$uri
			),
			404
		);
	}

	/**
	 * Return list of MCP tools formatted for tools/list.
	 *
	 * @param bool $include_operator Whether to include operator-scope tools.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_tools_list( bool $include_operator = false ): array {
		$tools         = array();
		$added_classes = array();

		// 1. Guest Scope Tools (Public discovery & booking).
		$guest_tools = array(
			'check_availability'  => Abilities\CheckAvailability::class,
			'get_hotel_info'      => Abilities\HotelInfo::class,
			'get_room_details'    => Abilities\RoomDetails::class,
			'get_policies'        => Abilities\Policies::class,
			'get_knowledge_base'  => Abilities\GetKnowledgeBase::class,
			'create_booking_link' => Abilities\CreateBookingLink::class,
			'get_business_card'   => Abilities\GetBusinessCard::class,
			'local_tips'          => Abilities\LocalTips::class,
			'get_price_breakdown' => Abilities\GetPriceBreakdown::class,
			'recommend_rooms'     => Abilities\RecommendRooms::class,
		);

		// 2. Operator Scope Tools (Pro only).
		$operator_tools = array();

$all_tools = array_merge( $guest_tools, $operator_tools );

		foreach ( $all_tools as $slug => $class_name ) {
			if ( in_array( $class_name, $added_classes, true ) || ! class_exists( $class_name ) ) {
				continue;
			}
			$added_classes[] = $class_name;

			if ( method_exists( $class_name, 'get_definition' ) ) {
				$def = $class_name::get_definition();
				$tools[] = array(
					'name'        => $slug,
					'description' => (string) ( $def['description'] ?? '' ),
					'inputSchema' => $def['input_schema'] ?? (object) array(),
					'annotations' => array(
						'readOnlyHint'    => ! in_array( $slug, array( 'create_booking_draft', 'modify_booking', 'cancel_booking' ), true ),
						'destructiveHint' => in_array( $slug, array( 'cancel_booking', 'modify_booking' ), true ),
						'idempotentHint'  => ! in_array( $slug, array( 'create_booking_draft', 'cancel_booking' ), true ),
					),
				);
			}
		}

		return $tools;
	}

	/**
	 * Map of registered ability slugs/names to their implementing PHP classes.
	 *
	 * @return array<string, class-string>
	 */
	public static function get_ability_map(): array {
		$map = array(
			'mhbo_check_availability'  => Abilities\CheckAvailability::class,
			'mhbo/check-availability'  => Abilities\CheckAvailability::class,
			'check_availability'       => Abilities\CheckAvailability::class,

			'mhbo_get_hotel_info'      => Abilities\HotelInfo::class,
			'mhbo/get-hotel-info'      => Abilities\HotelInfo::class,
			'get_hotel_info'           => Abilities\HotelInfo::class,

			'mhbo_get_room_details'    => Abilities\RoomDetails::class,
			'mhbo/get-room-details'    => Abilities\RoomDetails::class,
			'get_room_details'         => Abilities\RoomDetails::class,

			'mhbo_get_policies'        => Abilities\Policies::class,
			'mhbo/get-policies'        => Abilities\Policies::class,
			'get_policies'             => Abilities\Policies::class,

			'mhbo_get_knowledge_base'  => Abilities\GetKnowledgeBase::class,
			'mhbo/get-knowledge-base'  => Abilities\GetKnowledgeBase::class,
			'get_knowledge_base'       => Abilities\GetKnowledgeBase::class,

			'mhbo_create_booking_link' => Abilities\CreateBookingLink::class,
			'mhbo/create-booking-link' => Abilities\CreateBookingLink::class,
			'create_booking_link'      => Abilities\CreateBookingLink::class,

			'mhbo_get_business_card'   => Abilities\GetBusinessCard::class,
			'mhbo/get-business-card'   => Abilities\GetBusinessCard::class,
			'get_business_card'        => Abilities\GetBusinessCard::class,

			'mhbo_local_tips'          => Abilities\LocalTips::class,
			'mhbo/local-tips'          => Abilities\LocalTips::class,
			'local_tips'               => Abilities\LocalTips::class,

			'mhbo_get_price_breakdown' => Abilities\GetPriceBreakdown::class,
			'mhbo/get-price-breakdown' => Abilities\GetPriceBreakdown::class,
			'get_price_breakdown'      => Abilities\GetPriceBreakdown::class,

			'mhbo_recommend_rooms'     => Abilities\RecommendRooms::class,
			'mhbo/recommend-rooms'     => Abilities\RecommendRooms::class,
			'recommend_rooms'          => Abilities\RecommendRooms::class,
		);

return $map;
	}

	/**
	 * Output OpenAPI 3.1.0 specification for ChatGPT Custom Actions.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_openapi(): WP_REST_Response {
		self::set_cors_headers();

		$spec = array(
			'openapi' => '3.1.0',
			'info'    => array(
				'title'       => __( 'Modern Hotel Booking AI Assistant API', 'modern-hotel-booking' ),
				'description' => __( 'Public machine-readable API for room availability, rates, policies, and direct booking links.', 'modern-hotel-booking' ),
				'version'     => defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '3.0.0',
			),
			'servers' => array(
				array(
					'url'         => rest_url( self::SERVER_NS ),
					'description' => __( 'Hotel REST API Endpoint', 'modern-hotel-booking' ),
				),
			),
			'paths'   => array(
				'/availability' => array(
					'post' => array(
						'operationId' => 'checkAvailability',
						'summary'     => __( 'Check live room availability and rates', 'modern-hotel-booking' ),
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => Abilities\CheckAvailability::get_definition()['input_schema'],
								),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => __( 'Availability results with room rates and booking URLs', 'modern-hotel-booking' ),
							),
						),
					),
				),
				'/hotel-info'   => array(
					'get' => array(
						'operationId' => 'getHotelInfo',
						'summary'     => __( 'Get hotel contact, hours, and property information', 'modern-hotel-booking' ),
						'responses'   => array(
							'200' => array(
								'description' => __( 'Hotel information', 'modern-hotel-booking' ),
							),
						),
					),
				),
				'/rooms'        => array(
					'get' => array(
						'operationId' => 'getRooms',
						'summary'     => __( 'Get room categories, amenities, and specifications', 'modern-hotel-booking' ),
						'responses'   => array(
							'200' => array(
								'description' => __( 'Room details list', 'modern-hotel-booking' ),
							),
						),
					),
				),
				'/policies'     => array(
					'get' => array(
						'operationId' => 'getPolicies',
						'summary'     => __( 'Get hotel cancellation and check-in policies', 'modern-hotel-booking' ),
						'responses'   => array(
							'200' => array(
								'description' => __( 'Hotel policies', 'modern-hotel-booking' ),
							),
						),
					),
				),
				'/booking-link' => array(
					'post' => array(
						'operationId' => 'createBookingLink',
						'summary'     => __( 'Generate direct reservation checkout URL', 'modern-hotel-booking' ),
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => Abilities\CreateBookingLink::get_definition()['input_schema'],
								),
							),
						),
						'responses'   => array(
							'200' => array(
								'description' => __( 'Pre-filled booking link URL', 'modern-hotel-booking' ),
							),
						),
					),
				),
			),
		);

		return new WP_REST_Response( $spec, 200 );
	}

	// ── Public REST Action Callbacks (for OpenAPI / ChatGPT Actions) ──────────

	/**
	 * Handle REST check-availability action for OpenAPI / ChatGPT.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	public static function rest_availability( WP_REST_Request $request ): WP_REST_Response {
		self::set_cors_headers();
		$args   = $request->get_json_params() ?: $request->get_params();
		$output = Abilities\CheckAvailability::execute( is_array( $args ) ? $args : array() );
		return new WP_REST_Response( $output, 200 );
	}

	/**
	 * Handle REST hotel information action for OpenAPI / ChatGPT.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	public static function rest_hotel_info( WP_REST_Request $request ): WP_REST_Response {
		self::set_cors_headers();
		$args   = $request->get_json_params() ?: $request->get_params();
		$output = Abilities\HotelInfo::execute( is_array( $args ) ? $args : array() );
		return new WP_REST_Response( $output, 200 );
	}

	/**
	 * Handle REST room catalog action for OpenAPI / ChatGPT.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	public static function rest_rooms( WP_REST_Request $request ): WP_REST_Response {
		self::set_cors_headers();
		$args   = $request->get_json_params() ?: $request->get_params();
		$output = Abilities\RoomDetails::execute( is_array( $args ) ? $args : array() );
		return new WP_REST_Response( $output, 200 );
	}

	/**
	 * Handle REST hotel policies action for OpenAPI / ChatGPT.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	public static function rest_policies( WP_REST_Request $request ): WP_REST_Response {
		self::set_cors_headers();
		$args   = $request->get_json_params() ?: $request->get_params();
		$output = Abilities\Policies::execute( is_array( $args ) ? $args : array() );
		return new WP_REST_Response( $output, 200 );
	}

	/**
	 * Handle REST price quotation action for OpenAPI / ChatGPT.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	public static function rest_quote( WP_REST_Request $request ): WP_REST_Response {
		self::set_cors_headers();
		$args   = $request->get_json_params() ?: $request->get_params();
		$output = Abilities\GetPriceBreakdown::execute( is_array( $args ) ? $args : array() );
		return new WP_REST_Response( $output, 200 );
	}

	/**
	 * Handle REST direct booking link generation for OpenAPI / ChatGPT.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	public static function rest_booking_link( WP_REST_Request $request ): WP_REST_Response {
		self::set_cors_headers();
		$args                       = $request->get_json_params() ?: $request->get_params();
		$clean_args                 = is_array( $args ) ? $args : array();
		$clean_args['utm_source']   = 'chatgpt_action';
		$clean_args['utm_medium']   = 'ai_concierge';
		$clean_args['utm_campaign'] = 'openai';
		$output                     = Abilities\CreateBookingLink::execute( $clean_args );
		return new WP_REST_Response( $output, 200 );
	}

	// ── Forward Compatibility Registrations ──────────────────────────────────

	/**
	 * WP 7.0 native Connectors registration.
	 */
	private static function register_native(): void {
		if ( ! function_exists( 'wp_register_mcp_server' ) ) {
			return;
		}

		\call_user_func(
			'wp_register_mcp_server',
			array(
				'id'          => self::SERVER_ID,
				'name'        => I18n::get_label( 'ai_mcp_name' ),
				'description' => I18n::get_label( 'ai_mcp_description' ),
				'version'     => defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '3.0.0',
				'abilities'   => array_keys( self::get_ability_map() ),
				'resources'   => array( self::SERVER_ID . '/site-knowledge-base' ),
			)
		);
	}

	/**
	 * Official WordPress/mcp-adapter registration.
	 *
	 * @param mixed $adapter The McpAdapter instance.
	 */
	public static function register_adapter( mixed $adapter ): void {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		try {
			$adapter->create_server(
				self::SERVER_ID,
				self::SERVER_NS,
				self::SERVER_ROUTE,
				I18n::get_label( 'ai_mcp_name' ),
				I18n::get_label( 'ai_mcp_description' ),
				defined( 'MHBO_VERSION' ) ? MHBO_VERSION : '3.0.0',
				array( 'WP\\MCP\\Transport\\Http\\RestTransport' ),
				null,
				null,
				array_keys( self::get_ability_map() )
			);
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Rationale: Critical exception logging for 3rd-party MCP integrations.
				error_log( 'MHBO MCP Adapter Registration Error: ' . $e->getMessage() );
			}
		}
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Determine if current caller has operator administrative credentials.
	 *
	 * @return bool
	 */
	public static function is_operator_authenticated(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( 'manage_options' )
			|| current_user_can( 'mhbo_manage_bookings' )
			|| current_user_can( 'mhbo_view_bookings' );
	}

	/**
	 * Check if Pro license is active.
	 *
	 * @return bool
	 */
	public static function is_pro_active(): bool {
		$is_pro = false;

return $is_pro;
	}

	/**
	 * Set permissive CORS headers for AI clients and browsers.
	 */
	private static function set_cors_headers(): void {
		if ( ! headers_sent() ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Version, Mcp-Protocol-Version, X-WP-Nonce' );
			header( 'Access-Control-Max-Age: 86400' );
		}
	}

	/**
	 * Format a standard JSON-RPC 2.0 error response with proper HTTP status code.
	 *
	 * @param mixed $rpc_id
	 * @param int $code
	 * @param string $message
	 * @param int $http_status
	 * @param array<string, string> $extra_headers
	 * @return WP_REST_Response
	 */
	private static function json_rpc_error( mixed $rpc_id, int $code, string $message, int $http_status = 200, array $extra_headers = array() ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$http_status
		);

		foreach ( $extra_headers as $header_name => $header_value ) {
			$response->header( $header_name, $header_value );
		}

		return $response;
	}

	/**
	 * Get the active MCP server REST endpoint URL.
	 *
	 * @return string
	 */
	public static function get_endpoint_url(): string {
		$hybrid_enabled = (bool) get_option( 'mhbo_ai_mcp_hybrid_bridge', 0 );
		if ( $hybrid_enabled && class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return rest_url( 'mcp/mcp-adapter-default-server' );
		}
		return rest_url( self::SERVER_NS . '/' . self::SERVER_ROUTE );
	}

	/**
	 * Get the OpenAPI 3.1 specification URL.
	 *
	 * @return string
	 */
	public static function get_openapi_url(): string {
		return rest_url( self::SERVER_NS . '/openapi.json' );
	}

	/**
	 * Get current status of MCP & Abilities integration.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status(): array {
		$mcp_enabled       = (bool) get_option( 'mhbo_ai_mcp_enabled', 0 );
		$hybrid_enabled    = (bool) get_option( 'mhbo_ai_mcp_hybrid_bridge', 0 );
		$adapter_installed = class_exists( '\WP\MCP\Core\McpAdapter' );
		$is_bridged        = $adapter_installed && $hybrid_enabled;
		$is_pro            = self::is_pro_active();
		$tools_count       = count( self::get_tools_list( true ) );

		return array(
			'active'            => $mcp_enabled,
			'mcp_enabled'       => $mcp_enabled,
			'hybrid_enabled'    => $hybrid_enabled,
			'adapter_installed' => $adapter_installed,
			'is_bridged'        => $is_bridged,
			'abilities_count'   => $tools_count,
			'guest_tools_count' => count( self::get_tools_list( false ) ),
			'endpoint'          => self::get_endpoint_url(),
			'native_endpoint'   => rest_url( self::SERVER_NS . '/' . self::SERVER_ROUTE ),
			'adapter_endpoint'  => rest_url( 'mcp/mcp-adapter-default-server' ),
			'openapi_url'       => self::get_openapi_url(),
			'mode'              => $is_bridged ? __( 'WordPress MCP Adapter Bridge (Unified)', 'modern-hotel-booking' ) : __( 'Hybrid Native Streamable HTTP Engine (Standalone)', 'modern-hotel-booking' ),
			'tier'              => $is_pro ? 'pro' : ( ( class_exists( '\MHBO\Core\Tier' ) && \MHBO\Core\Tier::get_package_tier() === 'starter' ) ? 'starter' : 'free' ),
		);
	}
}
