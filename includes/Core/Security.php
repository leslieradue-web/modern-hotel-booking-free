<?php declare(strict_types=1);

namespace MHB\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Security utility class for common security operations.
 *
 * Consolidates SSRF protection, IP validation, and other security functions.
 *
 * @package MHB\Core
 * @since   2.1.0
 */
class Security
{
    /**
     * Validate URL for safe HTTP requests (SSRF protection).
     *
     * Blocks internal/private IP addresses, cloud metadata endpoints,
     * and non-HTTP(S) schemes.
     *
     * @param string $url The URL to validate.
     * @return bool True if safe, false otherwise.
     */
    public static function is_safe_url(string $url): bool
    {
        // Must be a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = wp_parse_url($url);
        if (empty($parsed['host'])) {
            return false;
        }

        // Only allow HTTP/HTTPS schemes
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = $parsed['host'];

        // Block localhost and common loopback variations
        $blocked_hosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        if (in_array(strtolower($host), $blocked_hosts, true)) {
            return false;
        }

        // Resolve hostname to IP
        $ip = gethostbyname($host);

        // If gethostbyname fails, it returns the original host
        if ($host === $ip && !filter_var($host, FILTER_VALIDATE_IP)) {
            // Could not resolve - allow (DNS may fail temporarily)
            return true;
        }

        // Use PHP's built-in filters for private/reserved ranges
        // FILTER_FLAG_NO_PRIV_RANGE - Blocks 10.x, 172.16-31.x, 192.168.x
        // FILTER_FLAG_NO_RES_RANGE - Blocks 169.254.x.x (cloud metadata), 0.x.x.x, etc.
        $valid_ip = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if (false === $valid_ip) {
            return false;
        }

        // Additional CIDR-based check for edge cases
        $blocked_ranges = [
            '127.0.0.0/8',     // Loopback
            '10.0.0.0/8',      // Private Class A
            '172.16.0.0/12',   // Private Class B
            '192.168.0.0/16',  // Private Class C
            '169.254.0.0/16',  // Link-local (AWS/GCP metadata)
            '0.0.0.0/8',       // Current network
        ];

        foreach ($blocked_ranges as $range) {
            if (self::ip_in_range($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an IP is within a CIDR range.
     *
     * @param string $ip    The IP address to check.
     * @param string $range The CIDR range (e.g., '192.168.0.0/16').
     * @return bool True if in range, false otherwise.
     */
    public static function ip_in_range(string $ip, string $range): bool
    {
        // Handle single IP (no CIDR notation)
        if (false === strpos($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range);
        $bits = (int) $bits;

        // Handle IPv6 - simplified check
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);

        if (false === $ip_long || false === $subnet_long) {
            return false;
        }

        $mask = -1 << (32 - $bits);
        return ($ip_long & $mask) === ($subnet_long & $mask);
    }

    /**
     * Get the client's IP address with proxy support.
     *
     * Checks multiple headers for proxied requests while validating
     * to prevent IP spoofing.
     *
     * @return string The client IP address.
     */
    public static function get_client_ip(): string
    {
        $ip = '';

        // Check for proxied IP in order of preference
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Standard proxy header
            'HTTP_X_REAL_IP',            // Nginx
            'HTTP_CLIENT_IP',            // General proxy
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $header_value = sanitize_text_field(wp_unslash($_SERVER[$header]));
                $ips = explode(',', $header_value);
                $ip = trim($ips[0]);
                break;
            }
        }

        // Fallback to direct connection
        if (empty($ip) && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        // Validate the IP
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return sanitize_text_field($ip);
        }

        return '0.0.0.0';
    }
}
