<?php declare(strict_types=1);

namespace MHBO\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper class for WordPress Playground specific optimizations and debugging.
 */
class Playground
{
    /**
     * Initialize playground specific hooks.
     */
    public static function init(): void
    {
        // Detect if we are in a playground-like environment
        $http_host = sanitize_text_field(wp_unslash($GLOBALS['_SERVER']['HTTP_HOST'] ?? ''));
        $is_playground = defined('WPP_IS_PLAYGROUND') || str_contains($http_host, 'playground');

        if ($is_playground || (defined('WP_DEBUG') && WP_DEBUG)) {
            add_filter('wp_mail', [self::class, 'log_wp_mail']);
            
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- 2026 BP: Stability & CORS for Playground/WASM isolation.
            set_error_handler(function ($errno, $errstr, $errfile, $errline) {
                if ($errno === E_DEPRECATED) {
                    if (str_contains($errstr, 'DateTime::__construct()') || str_contains($errstr, 'strip_tags()')) {
                        return true;
                    }
                }
                return false;
            });

            // 2026 BP: Stability & CORS for Playground
            if ($is_playground) {
                add_action('init', [self::class, 'send_cors_headers'], 1);
                add_action('init', [self::class, 'ensure_stable_setup'], 5);
                
                // Mock Payment Gateways to prevent connection errors in isolated WASM/SQLite
                add_action('wp_ajax_mhbo_create_payment_intent', [self::class, 'mock_stripe_intent'], 1);
                add_action('wp_ajax_nopriv_mhbo_create_payment_intent', [self::class, 'mock_stripe_intent'], 1);
                add_action('wp_ajax_mhbo_create_paypal_order', [self::class, 'mock_paypal_order'], 1);
                add_action('wp_ajax_nopriv_mhbo_create_paypal_order', [self::class, 'mock_paypal_order'], 1);
            }
        }
    }

    /**
     * Resolve Cross-Origin errors in Playground (localhost:9400 or playground.wordpress.net)
     */
    public static function send_cors_headers(): void
    {
        if (headers_sent()) return;
        
        $origin = sanitize_text_field(wp_unslash($GLOBALS['_SERVER']['HTTP_ORIGIN'] ?? '*'));
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce");
        header("Access-Control-Allow-Credentials: true");

        $method = sanitize_text_field(wp_unslash($GLOBALS['_SERVER']['REQUEST_METHOD'] ?? ''));
        if ($method === 'OPTIONS') {
            status_header(200);
            exit;
        }
    }

    /**
     * Ensure database and basic options are present for a 'Stable' experience.
     */
    public static function ensure_stable_setup(): void
    {
        global $wpdb;
        
        // Ensure tables exist (SQLite resets often in Playground)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Testing environment initialization; safe for Playground/Local use.
        $table_check = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}mhbo_rooms'");
        if (!$table_check) {
            Activator::activate();
            
            // Seed a default room if empty
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 2026 BP: Seed count check for mock data persistence.
            $room_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mhbo_rooms");
            if ($room_count === 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- 2026 BP: Database seeding for isolated Playground testing.
                $wpdb->insert("{$wpdb->prefix}mhbo_room_types", [
                    'name' => 'Paradise Suite (Playground)',
                    'base_price' => 150.00,
                    'max_adults' => 2,
                    'total_rooms' => 1
                ]);
                $type_id = $wpdb->insert_id;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- 2026 BP: Database seeding for isolated Playground testing.
                $wpdb->insert("{$wpdb->prefix}mhbo_rooms", [
                    'type_id' => $type_id,
                    'room_number' => '101',
                    'status' => 'available'
                ]);
            }
        }

        // Set stable defaults for Currency if missing
        if (!get_option('mhbo_currency_code')) {
            update_option('mhbo_currency_code', 'USD');
            update_option('mhbo_currency_symbol', '$');
        }
    }

    /**
     * Mock Stripe Payment Intent for local stable testing.
     */
    public static function mock_stripe_intent(): void
    {
        check_ajax_referer('mhbo_payment_nonce', 'nonce');
        
        wp_send_json_success([
            'id' => 'pi_mock_' . \md5(\uniqid((string)\wp_rand(), true)),
            'client_secret' => 'pi_mock_secret_' . \md5(\uniqid((string)\wp_rand(), true)),
            'amount' => absint(wp_unslash($_POST['amount'] ?? 15000)),
            'status' => 'requires_payment_method',
            'is_mock' => true
        ]);
    }

    /**
     * Mock PayPal Order for local stable testing.
     */
    public static function mock_paypal_order(): void
    {
        wp_send_json_success([
            'order_id' => 'PAYPAL-MOCK-' . \strtoupper(\substr(\md5(\uniqid((string)\wp_rand(), true)), 0, 12)),
            'status' => 'CREATED',
            'is_mock' => true
        ]);
    }

    /**
     * Log wp_mail calls to a file for visibility in environments without SMTP.
     *
     * @param array $args The wp_mail arguments.
     * @return array The original arguments.
     */
    public static function log_wp_mail(array $args): array
    {
        $log_dir = WP_CONTENT_DIR . '/uploads/mhbo-logs';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        $log_file = $log_dir . '/emails.log';
        $timestamp = current_time('mysql');
        
        $log_entry = sprintf(
            "[%s] To: %s\nSubject: %s\nHeaders: %s\nMessage:\n%s\n%s\n",
            $timestamp,
            $args['to'] ?? 'unknown',
            $args['subject'] ?? 'no-subject',
            is_array($args['headers']) ? implode(', ', $args['headers']) : ($args['headers'] ?? 'none'),
            $args['message'] ?? '',
            str_repeat('-', 40)
        );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($log_file, $log_entry, FILE_APPEND);

        return $args;
    }
}
