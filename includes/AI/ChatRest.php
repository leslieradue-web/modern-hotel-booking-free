<?php
/**
 * Chat REST Endpoint
 *
 * POST /wp-json/mhbo/v1/chat
 *
 * Rate-limited (30 req/hr free, 200/hr pro).
 * Supports tool calling with automatic tool execution.
 * Optionally streams via SSE.
 *
 * @package MHBO\AI
 * @since   2.4.0
 */

declare(strict_types=1);

namespace MHBO\AI;

use MHBO\AI\Abilities\CheckAvailability;
use MHBO\AI\Abilities\HotelInfo;
use MHBO\AI\Abilities\Policies;
use MHBO\AI\Abilities\RoomDetails;
use MHBO\AI\Abilities\GetKnowledgeBase;
use MHBO\AI\Abilities\GetBusinessCard;
use MHBO\AI\Abilities\CreateBookingLink;
use MHBO\Core\License;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ChatRest {

    private const REST_NAMESPACE = 'mhbo/v1';
    private const RATE_FREE      = 30;
    private const RATE_PRO       = 200;
    private const RATE_WINDOW    = HOUR_IN_SECONDS;
    private const MAX_TOOL_TURNS = 5;   // prevent infinite tool loops

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register the REST route. Called on rest_api_init.
     */
    public static function register(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/chat',
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'handle' ],
                'permission_callback' => '__return_true', // Public concierge access
                'args'                => [
                    'message'    => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                    'nonce'      => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
                    'lang'       => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * Handle the POST /mhbo/v1/chat request.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        // 0. Ensure PHP has enough time for slow AI models (2026 BP).
        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
            @set_time_limit( 120 );
        }
        // 1. Nonce verification.
        $nonce = (string) ( $request->get_param( 'nonce' ) ?? $request->get_header( 'X-WP-Nonce' ) ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'mhbo_chat_nonce' ) ) {
            return new \WP_Error( 'mhbo_forbidden', \MHBO\Core\I18n::get_label( 'ai_error_invalid_nonce' ), [ 'status' => 403 ] );
        }

        // 2. Rate limiting.
        $ip     = self::get_client_ip();
        $ip_key = 'mhbo_rl_' . md5( (string) $ip );
        $rate   = false ? self::RATE_PRO : self::RATE_FREE;

        $count = (int) get_transient( $ip_key );
        if ( $count >= $rate ) {
            return new \WP_Error(
                'mhbo_rate_limit',
                \MHBO\Core\I18n::get_label( 'ai_error_rate_limit' ),
                [ 'status' => 429 ]
            );
        }
        set_transient( $ip_key, $count + 1, (int) self::RATE_WINDOW );

        // 3. Streaming Support (SSE).
        $is_streaming = ( $request->get_param( 'stream' ) === 'true' ) || ( str_contains( (string) $request->get_header( 'Accept' ), 'text/event-stream' ) );
        if ( $is_streaming && ! headers_sent() ) {
            header( 'Content-Type: text/event-stream' );
            header( 'Cache-Control: no-cache' );
            header( 'X-Accel-Buffering: no' );
            header( 'Connection: keep-alive' );
            echo "retry: 1000\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }

        // 3. Fail-Fast: Check if AI Circuit Breaker is currently tripped.
        if ( Client::is_circuit_broken() ) {
            $score = (int) \get_transient( 'mhbo_ai_error_score' );
            $lock  = (int) \get_transient( 'mhbo_ai_quota_lock' );
            $delay = \max( 15, $lock - \time() );

            return new \WP_REST_Response( [
                'content'     => \sprintf(
                    // translators: %1$d: circuit breaker tier score, %2$d: seconds remaining before retry
                    \MHBO\Core\I18n::get_label( 'ai_status_cooling_down_tier' ),
                    $score,
                    $delay
                ),
                'session_id'  => (string) $request->get_param( 'session_id' ),
                'suggestions' => [],
            ], 503 ); // Return 503 Service Unavailable for circuit breaks.
        }

        // 4. Input processing.
        $raw_message = (string) $request->get_param( 'message' );
        if ( mb_strlen( (string) $raw_message ) > 1000 ) {
            return new \WP_Error( 'mhbo_message_too_long', \MHBO\Core\I18n::get_label( 'ai_error_message_too_long' ), [ 'status' => 400 ] );
        }

        try {

        // 4. Session.
        $session_id = ChatSession::get_session_id();
        $history    = ChatSession::get_history( $session_id );

        // 5. Add user message to history.
        $history[] = [ 'role' => 'user', 'content' => $raw_message ];
        ChatSession::add_message( $session_id, 'user', $raw_message );

        // 6. System prompt + tools.
        $lang          = (string) ( $request->get_param( 'lang' ) ?? '' );
        $system_prompt = KnowledgeBase::get_system_prompt( $lang );
        $tools         = KnowledgeBase::get_tool_definitions( false );

        // 7. Dynamic Session Context (2026 BP).
        //    Inject known session state directly into the prompt to ensure the AI
        //    never misses a guest's identity or current scarcity constraints.
        $today         = \MHBO\Core\HotelTime::today();
        $guest_name    = ChatSession::get_guest_name( $session_id );
        $guest_email   = ChatSession::get_guest_email( $session_id );
        $guest_phone   = ChatSession::get_guest_phone( $session_id );

        $lang_block    = $lang ? sprintf( \MHBO\Core\I18n::get_label( 'ai_prompt_lang_rule' ), $lang ) : '';
        
        $context_name  = $guest_name ? sprintf( \MHBO\Core\I18n::get_label( 'ai_prompt_guest_name' ), $guest_name ) : "";
        $context_email = $guest_email ? sprintf( \MHBO\Core\I18n::get_label( 'ai_prompt_guest_email' ), $guest_email ) : "";
        $context_phone = $guest_phone ? sprintf( \MHBO\Core\I18n::get_label( 'ai_prompt_guest_phone' ), $guest_phone ) : "";
        
        $date_context  = sprintf( 
            \MHBO\Core\I18n::get_label( 'ai_prompt_date_context' ), 
            $today, 
            gmdate( 'l', strtotime( (string) $today ) ) 
        );
        $scarcity_rule = \MHBO\Core\I18n::get_label( 'ai_prompt_scarcity_rule' ) . "\n";
        $decisive_rule = sprintf( 
            \MHBO\Core\I18n::get_label( 'ai_prompt_decisive_rule' ), 
            false ? '`create_booking_link` (or `create_booking_draft` if is_pro)' : '`create_booking_link`'
        );
        
        $system_prompt .= "\n" . $lang_block . "\n" . $context_name . " " . $context_email . " " . $context_phone . "\n" . $date_context . "\n" . $scarcity_rule . $decisive_rule;

        // 8. AI call — may require multiple turns for tool use.
        //
        // NOTE on finish_reason: OpenAI uses 'tool_calls' to signal the model wants
        // another turn; Gemini always returns 'STOP' (even when it calls functions).
        // We therefore drive the loop purely on whether tool_calls are present:
        //   • No tool_calls  → text content is the final answer; break.
        //   • Has tool_calls → execute them, append results, continue to next turn.
        $final_response = '';
        $tool_calls_log = [];
        $messages       = $history;

        for ( $turn = 0; $turn < self::MAX_TOOL_TURNS; $turn++ ) {
            $ai_response     = [ 'error' => null ];
            $max_retries     = 3; // 2026 BP: Standard REST retry threshold.
            $retry_backoff_s = 2; // exponential base

            for ( $attempt = 0; $attempt < $max_retries; $attempt++ ) {
                // 2026 BP: Emit 'thinking' event for real-time guest feedback.
                $step_text = $turn === 0 ? \MHBO\Core\I18n::get_label( 'ai_status_thinking_analyzing' ) : \MHBO\Core\I18n::get_label( 'ai_status_thinking_refining' );
                if ( $attempt > 0 ) {
                    // translators: %d: current retry attempt number
                    $step_text = sprintf( \MHBO\Core\I18n::get_label( 'ai_status_thinking_retrying' ), (int) $attempt + 1 );
                }
                self::emit_thought( $step_text, $is_streaming );

                $ai_response = Client::prompt( $messages, $system_prompt, $tools );

                // 2026 BP: Rule 15 explicit check for non-error state.
                if ( null === ( $ai_response['error'] ?? null ) ) {
                    break; // Success!
                }

                $is_transient = self::is_transient_error( (string) $ai_response['error'] );

                if ( ! $is_transient || $attempt === $max_retries - 1 ) {
                    break; // Final failure or non-transient error.
                }

                // Wait before next attempt.
                sleep( (int) pow( $retry_backoff_s, $attempt + 1 ) );
            }

            if ( $ai_response['error'] ) {
                $is_transient = self::is_transient_error( (string) $ai_response['error'] );

                if ( $is_transient ) {
                    return new \WP_REST_Response( [
                        'response'    => \MHBO\Core\I18n::get_label( 'ai_status_cooling_down' ),
                        'session_id'  => $session_id,
                        'suggestions' => [],
                    ], 200 );
                }

                return new \WP_Error( 'mhbo_ai_error', (string) $ai_response['error'], [ 'status' => 502 ] );
            }

            $tool_calls = $ai_response['tool_calls'] ?? [];
            $thought    = (string) ( $ai_response['thought'] ?? '' );

            // 2026 BP: Rule 15 explicit check for empty toolset.
            if ( [] === (array) $tool_calls ) {
                $final_response = (string) $ai_response['content'];
                break;
            }

            $assistant_meta = [
                'tool_calls' => $tool_calls,
                'thought'    => $thought,
                'raw_parts'  => $ai_response['raw_parts'] ?? null,
            ];
            $messages[] = [
                'role'              => 'assistant',
                'content'           => '' === $ai_response['content'] ? null : $ai_response['content'],
                'thought'           => $thought,
                'thought_signature' => $ai_response['thought_signature'] ?? null,
                'tool_calls'        => $tool_calls,
            ];

            ChatSession::add_message( $session_id, 'assistant', (string) $ai_response['content'], $assistant_meta );

            foreach ( $ai_response['tool_calls'] as $tc ) {
                $tc_id     = $tc['id'] ?? '';
                $tc_name   = $tc['function']['name'] ?? '';
                // 2026 BP: Emit tool-specific progress event.
                $step_name = str_replace( '_', ' ', $tc_name );
                self::emit_thought( ucfirst( $step_name ) . '...', $is_streaming );

                $fn_name  = (string) ( $tc['function']['name'] ?? '' );
                $fn_args  = (array) ( json_decode( (string) ( $tc['function']['arguments'] ?? '{}' ), true ) ?: [] );
                $tc_id    = (string) ( $tc['id'] ?? 'call_' . wp_generate_password( 8, false ) );

                $tool_result      = self::execute_tool( $fn_name, $fn_args );
                $tool_calls_log[] = [ 'tool' => $fn_name, 'args' => $fn_args, 'result' => $tool_result ];

                $tc_msg = [
                    'role'             => 'tool',
                    'name'             => $fn_name,
                    'tool_call_id'     => $tc_id,
                    'content'          => wp_json_encode( $tool_result ),
                    'thought_signature' => $tc['thought_signature'] ?? null,
                ];

                $messages[] = $tc_msg;

                $tool_meta = [ 'name' => $fn_name ];
                if ( isset( $tc['thought_signature'] ) && '' !== (string) $tc['thought_signature'] ) {
                    $tool_meta['thought_signature'] = $tc['thought_signature'];
                }
                
                if ( $session_id ) {
                    $b_id = $tool_result['booking_id'] ?? 0;
                    if ( 0 !== $b_id ) {
                        // 2026 BP: Safeguard for Free build where link_booking method is stripped.
                        if ( method_exists( '\MHBO\AI\ChatSession', 'link_booking' ) ) {
                            ChatSession::link_booking( $session_id, (int) $b_id );
                        }
                    }
                    $g_name = (string) ( $tool_result['guest_name'] ?? '' );
                    $g_email = '';
                    $g_phone = '';
                    if ( isset($tc['arguments']['booking_id']) && '' !== (string) $tc['arguments']['booking_id'] ) {
                        $b_id = (string)$tc['arguments']['booking_id'];
                    }
                    if ( isset($tc['arguments']['guest_name']) && '' !== (string) $tc['arguments']['guest_name'] ) {
                        $g_name = (string)$tc['arguments']['guest_name'];
                    }
                    if ( isset($tc['arguments']['guest_email']) && '' !== (string) $tc['arguments']['guest_email'] ) {
                        $g_email = (string)$tc['arguments']['guest_email'];
                    }
                    if ( isset($tc['arguments']['guest_phone']) && '' !== (string) $tc['arguments']['guest_phone'] ) {
                        $g_phone = (string)$tc['arguments']['guest_phone'];
                    }
                    if ( '' !== $g_name ) {
                        \MHBO\AI\ChatSession::set_guest_name( $session_id, $g_name );
                    }
                    if ( '' !== $g_email ) {
                        \MHBO\AI\ChatSession::set_guest_email( $session_id, $g_email );
                    }
                    if ( '' !== $g_phone ) {
                        \MHBO\AI\ChatSession::set_guest_phone( $session_id, $g_phone );
                    }
                }
                
                ChatSession::add_message( $session_id, 'tool', (string) $tc_msg['content'], $tool_meta );
            }
        }

        if ( '' === $final_response ) {

            return new \WP_Error(
                'mhbo_ai_empty',
                \MHBO\Core\I18n::get_label( 'ai_error_empty_response' ),
                [ 'status' => 502 ]
            );
        }

        // 8a. If a booking card was successfully rendered, the card contains all the
        //     information the guest needs. Drop the AI text response so the card is
        //     the only output — prevents the duplicate summary the guest sees otherwise.
        $card_tools = [ 'create_booking_link', 'create_booking_draft' ];
        foreach ( $tool_calls_log as $tc_entry ) {
            if (
                in_array( $tc_entry['tool'], $card_tools, true ) &&
                isset( $tc_entry['result'] ) &&
                ! isset( $tc_entry['result']['error'] )
            ) {
                $final_response = '';
                break;
            }
        }

        // 8b. Save assistant response to history.
        if ( $final_response ) {
            ChatSession::add_message( $session_id, 'assistant', $final_response, [ 'tool_calls_summary' => $tool_calls_log ] );
        }

        // 9. Generate suggestions.
        $suggestions = self::generate_suggestions( $tool_calls_log );

        // 10. Booking intent score (Einstein Next Best Action-style signal).
        //     Returned to the frontend so the UI can surface a "Book Now" CTA
        //     or escalation options at the right moment.
        $intent      = self::score_booking_intent( $messages, $tool_calls_log );
        $handoff     = self::build_handoff_options();

        // 11. Handle SSE streaming if requested.
        $accept = (string) $request->get_header( 'Accept' );
        if ( str_contains( (string) $accept, 'text/event-stream' ) ) {
            self::stream_response( (string) $final_response, $session_id, $tool_calls_log, $suggestions );
            exit; // SSE never returns a WP_REST_Response
        }

        return rest_ensure_response( [
            'response'         => $final_response,
            'session_id'       => $session_id,
            'tool_calls_made'  => $tool_calls_log,
            'suggestions'      => $suggestions,
            'booking_intent'   => $intent,
            'handoff'          => $handoff,
        ] );

        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( '[MHBO Chat] Exception (' . get_class( $e ) . '): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional error telemetry, guarded by WP_DEBUG_LOG.
            }
            $session_id = isset( $session_id ) ? $session_id : '';
            return new \WP_REST_Response( [
                'response'    => \MHBO\Core\I18n::get_label( 'ai_status_cooling_down' ),
                'session_id'  => $session_id,
                'suggestions' => [],
            ], 200 );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Whether an AI error string represents a transient (retryable) failure.
     */
    private static function is_transient_error( string $error ): bool {
        $phrases = [
            'cooling', 'quota', '503', '504', 'demand', 'overloaded',
            'capacity', 'retry', 'limit', 'busy', 'unavailable',
            'no models', 'available', 'high demand',
        ];
        $lower = strtolower( $error );
        foreach ( $phrases as $p ) {
            if ( str_contains( $lower, $p ) ) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Tool Execution
    // -------------------------------------------------------------------------

    /**
     * Execute a tool by name and return its result.
     *
     * @param string       $name
     * @param array<mixed> $args
     * @return array<mixed>
     */
    private static function execute_tool( string $name, array $args ): array {
        return match ( $name ) {
            'check_availability'  => CheckAvailability::execute( $args ),
            'get_room_details'    => RoomDetails::execute( $args ),
            'get_policies'        => Policies::execute( $args ),
            'get_hotel_info'      => HotelInfo::execute( $args ),
            'get_knowledge_base'  => GetKnowledgeBase::execute( $args ),
            'get_local_tips'      => Abilities\LocalTips::execute( $args ),
            'get_business_card'   => GetBusinessCard::execute( $args ),
            'create_booking_link' => CreateBookingLink::execute( $args ),
            
            default => [ 'error' => "Unknown tool: {$name}" ],
        };
    }

    // -------------------------------------------------------------------------
    // SSE Streaming
    // -------------------------------------------------------------------------

    /**
     * Emit a 'thought' event mid-stream to update the guest on AI progress.
     */
    private static function emit_thought( string $content, bool $enabled ): void {
        if ( ! $enabled ) {
            return;
        }
        echo 'data: ' . wp_json_encode( [ 'type' => 'thought', 'content' => $content ] ) . "\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Stream a final text response with metadata.
     *
     * @param string $text
     * @param string $session_id
     * @param array<int, array<string, mixed>> $tool_calls
     * @param string[] $suggestions
     */
    private static function stream_response( string $text, string $session_id, array $tool_calls, array $suggestions ): void {
        if ( ! headers_sent() ) {
            header( 'Content-Type: text/event-stream' );
            header( 'Cache-Control: no-cache' );
            header( 'X-Accel-Buffering: no' );
        }

        // Stream the text word-by-word (visual typing).
        $words = preg_split( '/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( is_array( $words ) ) {
            foreach ( $words as $chunk ) {
                echo 'data: ' . wp_json_encode( [ 'delta' => $chunk ] ) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                // 2026 BP: Micro-delay for natural typing feel (20-40ms).
                usleep( 25000 ); 
            }
        }

        // Send final metadata event.
        echo 'data: ' . wp_json_encode( [
            'done'            => true,
            'session_id'      => $session_id,
            'tool_calls_made' => $tool_calls,
            'suggestions'     => $suggestions,
        ] ) . "\n\n";
        echo "data: [DONE]\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Generate 2–3 quick-reply suggestions based on conversation context.
     *
     * Reads actual tool call results so suggestions are specific to the data
     * the AI just retrieved (e.g. room names from a real availability query).
     *
     * @param array<mixed> $tool_calls Each entry: ['tool'=>string, 'args'=>array, 'result'=>array]
     * @return string[]
     */
    private static function generate_suggestions( array $tool_calls ): array {
        $tools_used  = array_column( $tool_calls, 'tool' );
        $suggestions = [];

        if ( in_array( 'check_availability', $tools_used, true ) ) {
            // Try to surface the first available room name for a more specific CTA.
            $avail_result = null;
            foreach ( $tool_calls as $tc ) {
                if ( 'check_availability' === $tc['tool'] ) {
                    $avail_result = $tc['result'] ?? null;
                    break;
                }
            }

            $rooms = $avail_result['rooms'] ?? [];
            if ( [] !== (array) $rooms && isset( $rooms[0]['room_name'] ) ) {
                $first_room    = (string) $rooms[0]['room_name'];
                $suggestions[] = sprintf(
                    // translators: %s: room type name
                    \MHBO\Core\I18n::get_label( 'ai_suggestion_room_more' ),
                    $first_room
                );
            } else {
                $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_rooms_info' );
            }
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_cancellation' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_complete_booking' );

        } elseif ( in_array( 'get_room_details', $tools_used, true ) ) {
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_check_availability' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_amenities' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_cancellation' );

        } elseif ( in_array( 'create_booking_draft', $tools_used, true ) ) {
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_included' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_directions' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_modify' );

        } elseif ( in_array( 'cancel_booking', $tools_used, true ) || in_array( 'modify_booking', $tools_used, true ) ) {
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_new_dates' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_refund' );

        } elseif ( in_array( 'get_business_card', $tools_used, true ) ) {
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_availability' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_about_hotel' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_payment' );

        } elseif ( in_array( 'create_booking_link', $tools_used, true ) ) {
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_payment_options' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_cancellation_info' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_extras' );

        } else {
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_availability' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_room_types' );
            $suggestions[] = \MHBO\Core\I18n::get_label( 'ai_suggestion_about_hotel' );
        }

        return array_slice( $suggestions, 0, 3 );
    }

    /**
     * Einstein-style booking intent score (0–100).
     *
     * Scores the conversation based on signals that indicate purchase readiness:
     *   +40  Availability checked and rooms found
     *   +15  Room details viewed
     *   +15  Payment / pricing question asked (policies tool called)
     *   +15  Extras / add-ons asked
     *   +10  Multiple rounds of conversation (engagement depth)
     *   +5   Booking draft started
     *
     * The score is sent with every response. The JS widget shows a "Book Now"
     * CTA button when it crosses the HIGH_INTENT threshold (≥ 60).
     *
     * @param array<mixed> $messages      Full conversation including tool messages.
     * @param array<mixed> $tool_calls    Calls made in this request.
     * @return array{score:int,label:string}
     */
    private static function score_booking_intent( array $messages, array $tool_calls ): array {
        $score      = 0;
        $tools_used = array_column( $tool_calls, 'tool' );

        // Availability checked with rooms available.
        if ( in_array( 'check_availability', $tools_used, true ) ) {
            foreach ( $tool_calls as $tc ) {
                if ( 'check_availability' === $tc['tool'] && [] !== (array) ( $tc['result']['rooms'] ?? [] ) ) {
                    $score += 40;
                    break;
                }
            }
            // Checked but no rooms — still some intent.
            if ( $score === 0 ) {
                $score += 10;
            }
        }

        if ( in_array( 'get_room_details', $tools_used, true ) )   { $score += 15; }
        if ( in_array( 'get_policies', $tools_used, true ) )        { $score += 15; }
        if ( in_array( 'get_hotel_info', $tools_used, true ) )      { $score += 5;  }
        if ( in_array( 'create_booking_draft', $tools_used, true ) ) { $score += 5; }

        $has_extras_question = false;
        foreach ( $messages as $msg ) {
            if ( 'user' === (string) ( $msg['role'] ?? '' ) ) {
                $lower = strtolower( (string) ( $msg['content'] ?? '' ) );
                if ( str_contains( (string) $lower, 'extra' ) || str_contains( (string) $lower, 'breakfast' )
                    || str_contains( (string) $lower, 'add-on' ) || str_contains( (string) $lower, 'addon' )
                    || str_contains( (string) $lower, 'include' ) ) {
                    $has_extras_question = true;
                    break;
                }
            }
        }
        if ( $has_extras_question ) { $score += 15; }

        // Conversation depth — more than 3 user turns = engaged guest.
        $user_turns = count( array_filter( $messages, fn( $m ) => 'user' === ( (string) ( $m['role'] ?? '' ) ) ) );
        if ( $user_turns >= 3 ) { $score += 10; }

        $score = min( 100, $score );

        $label = match ( true ) {
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default      => 'low',
        };

        return [ 'score' => $score, 'label' => $label ];
    }

    /**
     * Build the handoff options array from plugin settings.
     *
     * Returned with every response so the JS can show context-aware escalation
     * buttons (WhatsApp, email, phone) when the guest needs human help.
     *
     * @return array<string,string>   Keys: type ('whatsapp'|'email'|'phone'), value: contact string.
     */
    private static function build_handoff_options(): array {
        $options = [];

        $wa = \MHBO\Business\Info::get_whatsapp();
        if ( ( $wa['enabled'] ?? false ) && '' !== (string) ( $wa['phone_number'] ?? '' ) ) {
            $options[ \MHBO\Core\I18n::get_label( 'ai_handoff_whatsapp' ) ] = sanitize_text_field( $wa['phone_number'] );
        }

        $company = \MHBO\Business\Info::get_company();
        if ( '' !== (string) ( $company['email'] ?? '' ) ) {
            $options[ \MHBO\Core\I18n::get_label( 'ai_handoff_email' ) ] = sanitize_email( $company['email'] );
        }
        if ( '' !== (string) ( $company['telephone'] ?? '' ) ) {
            $options[ \MHBO\Core\I18n::get_label( 'ai_handoff_phone' ) ] = sanitize_text_field( $company['telephone'] );
        }

        return $options;
    }

    /**
     * Get the client IP address safely.
     *
     * @return string
     */
    private static function get_client_ip(): string {
        $headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
        foreach ( $headers as $h ) {
            $raw = (string) ( filter_input( INPUT_SERVER, $h, FILTER_DEFAULT ) ?? '' );
            if ( '' !== $raw ) {
                $ip = sanitize_text_field( wp_unslash( $raw ) );
                // Take first IP if comma-separated.
                $ip = explode( ',', $ip )[0];
                $ip = trim( $ip );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
