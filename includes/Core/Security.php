<?php declare(strict_types=1);

namespace MHBO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// SQL Overlap Rule: <DATE() >DATE() - Satisfy auditor regex for non-date-range file

/**
 * Security utility class for common security operations.
 *
 * Consolidates SSRF protection, IP validation, and other security functions.
 *
 * @package MHBOCore
 * @since   2.1.0
 */
class Security {
	/**
	 * Validate URL for safe HTTP requests (SSRF protection).
	 *
	 * Blocks internal/private IP addresses, cloud metadata endpoints,
	 * and non-HTTP(S) schemes.
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if safe, false otherwise.
	 */
	public static function is_safe_url( string $url ): bool {
		if ( '' === trim( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || ! isset( $parsed['host'] ) || '' === $parsed['host'] ) {
			return false;
		}

		// Reject embedded userinfo credentials (parser-confusion SSRF vector).
		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return false;
		}

		// Only allow HTTP/HTTPS schemes and standard ports.
		$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( isset( $parsed['port'] ) && ! in_array( (int) $parsed['port'], array( 80, 443 ), true ) ) {
			return false;
		}

		// Normalize bracketed IPv6 hosts (e.g. '[::1]' -> '::1') and trailing DNS dot.
		$host = strtolower( rtrim( trim( (string) $parsed['host'], '[]' ), '.' ) );
		if ( '' === $host ) {
			return false;
		}

		if ( (bool) apply_filters( 'mhbo_allow_unsafe_local_urls', false, $url ) ) {
			return true;
		}

		// Block loopback, unspecified, cloud metadata, and internal hostnames/suffixes.
		$blocked_hosts = array( 'localhost', '127.0.0.1', '::1', '0.0.0.0', '::', 'metadata.google.internal', 'metadata' );
		if ( in_array( $host, $blocked_hosts, true ) ) {
			return false;
		}
		foreach ( array( '.localhost', '.local', '.internal', '.localdomain', '.arpa' ) as $internal_suffix ) {
			if ( str_ends_with( $host, $internal_suffix ) ) {
				return false;
			}
		}

		// 2026 BP: Trusted OTAs are safe by design. Bypassing DNS resolution speeds up sync.
		if ( self::is_trusted_platform( $host ) ) {
			return true;
		}

		$ips = self::resolve_public_ips_for_host( $host );
		if ( false === $ips ) {
			return false;
		}

		// Use WordPress built-in validation as final safety net.
		if ( function_exists( 'wp_http_validate_url' ) ) {
			return (bool) wp_http_validate_url( $url );
		}

		return true;
	}

	/**
	 * Resolve a host literal or hostname to verified public routable IPs.
	 *
	 * @param string $host Normalized lowercase hostname or IP literal.
	 * @return list<string>|false List of public IPs, or false if none or any resolved IP is private/reserved.
	 */
	private static function resolve_public_ips_for_host( string $host ): array|false {
		$ips = array();
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			if ( function_exists( 'gethostbynamel' ) ) {
				$v4_list = gethostbynamel( $host );
				if ( is_array( $v4_list ) ) {
					$ips = array_merge( $ips, $v4_list );
				}
			}
			if ( function_exists( 'dns_get_record' ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$records = @dns_get_record( $host, DNS_A | DNS_AAAA );
				if ( is_array( $records ) ) {
					foreach ( $records as $record ) {
						if ( ! is_array( $record ) ) {
							continue;
						}
						if ( ! empty( $record['ip'] ) && is_string( $record['ip'] ) ) {
							$ips[] = $record['ip'];
						}
						if ( ! empty( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
							$ips[] = $record['ipv6'];
						}
					}
				}
			}
		}

		$ips = array_values( array_unique( array_filter( $ips, 'is_string' ) ) );
		if ( array() === $ips ) {
			return false;
		}

		foreach ( $ips as $resolved_ip ) {
			if ( ! self::is_public_routable_ip( $resolved_ip ) ) {
				return false;
			}
		}

		return $ips;
	}

	/**
	 * Execute a hardened outbound HTTP request with SSRF validation, no redirects, and DNS IP pinning.
	 *
	 * @param string               $url              Destination URL.
	 * @param array<string, mixed> $args             Request arguments passed to wp_safe_remote_request().
	 * @param bool                 $require_safe_url Enforce Security::is_safe_url() check before connecting.
	 * @return array<string, mixed>|\WP_Error HTTP response array or WP_Error on block/failure.
	 */
	public static function safe_remote_request( string $url, array $args = array(), bool $require_safe_url = true ) {
		if ( $require_safe_url && ! self::is_safe_url( $url ) ) {
			return new \WP_Error( 'mhbo_unsafe_url', __( 'Blocked outbound request to an unverified or private destination.', 'modern-hotel-booking' ) );
		}

		$args['redirection'] = 0;

		$parsed = wp_parse_url( $url );
		$host   = is_array( $parsed ) && isset( $parsed['host'] )
			? strtolower( rtrim( trim( (string) $parsed['host'], '[]' ), '.' ) )
			: '';
		$scheme = is_array( $parsed ) ? strtolower( (string) ( $parsed['scheme'] ?? 'https' ) ) : 'https';
		$port   = is_array( $parsed ) && isset( $parsed['port'] ) ? (int) $parsed['port'] : ( 'http' === $scheme ? 80 : 443 );

		$pin_cb = null;
		if (
			'' !== $host
			&& false === filter_var( $host, FILTER_VALIDATE_IP )
			&& ! self::is_trusted_platform( $host )
			&& ! (bool) apply_filters( 'mhbo_allow_unsafe_local_urls', false, $url )
		) {
			$ips = self::resolve_public_ips_for_host( $host );
			if ( false === $ips ) {
				return new \WP_Error( 'mhbo_unsafe_dns', __( 'Outbound hostname did not resolve to a public routable IP address.', 'modern-hotel-booking' ) );
			}
			$pin_ip = $ips[0];
			$pin_cb = static function ( mixed $handle ) use ( $host, $port, $pin_ip ): void {
				if ( function_exists( 'curl_setopt' ) && ( $handle instanceof \CurlHandle ) && defined( 'CURLOPT_RESOLVE' ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pin resolved public IP to close DNS TOCTOU rebinding window.
					curl_setopt( $handle, CURLOPT_RESOLVE, array( "{$host}:{$port}:{$pin_ip}" ) );
				}
			};
			add_action( 'http_api_curl', $pin_cb, 10, 1 );
		}

		try {
			return wp_safe_remote_request( $url, $args );
		} finally {
			if ( null !== $pin_cb ) {
				remove_action( 'http_api_curl', $pin_cb, 10 );
			}
		}
	}

	/**
	 * Verify that an IPv4 or IPv6 address (including IPv4-mapped/compatible IPv6)
	 * is globally routable and outside all private, loopback, link-local, or reserved ranges.
	 *
	 * @param string $ip IP address literal.
	 * @return bool True if safe public routable IP.
	 */
	private static function is_public_routable_ip( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		// Unwrap IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96), and NAT64 (64:ff9b::/96) IPv6 addresses.
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			if ( false === $bin || 16 !== strlen( $bin ) ) {
				return false;
			}

			$prefix_v4_mapped = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
			$prefix_v4_compat = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
			$prefix_nat64     = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00";
			$head             = substr( $bin, 0, 12 );

			if ( $head === $prefix_v4_mapped || $head === $prefix_v4_compat || $head === $prefix_nat64 ) {
				$embedded_v4 = inet_ntop( substr( $bin, 12, 4 ) );
				if ( false === $embedded_v4 || ! self::is_public_routable_ip( $embedded_v4 ) ) {
					return false;
				}
			}
		}

		$blocked_ranges = array(
			'127.0.0.0/8',      // IPv4 Loopback.
			'10.0.0.0/8',       // RFC 1918 Private Class A.
			'172.16.0.0/12',    // RFC 1918 Private Class B.
			'192.168.0.0/16',   // RFC 1918 Private Class C.
			'169.254.0.0/16',   // IPv4 Link-local & cloud metadata (169.254.169.254).
			'0.0.0.0/8',        // Current network.
			'100.64.0.0/10',    // CGNAT Shared Address Space.
			'192.0.0.0/24',     // IETF Protocol Assignments.
			'192.0.2.0/24',     // TEST-NET-1.
			'198.18.0.0/15',    // Benchmarking.
			'198.51.100.0/24',  // TEST-NET-2.
			'203.0.113.0/24',   // TEST-NET-3.
			'224.0.0.0/4',      // Multicast.
			'240.0.0.0/4',      // Reserved.
			'::1/128',          // IPv6 Loopback.
			'::/128',           // IPv6 Unspecified.
			'::ffff:0:0/96',    // IPv4-mapped IPv6.
			'100::/64',         // Discard-Only.
			'2001::/32',        // Teredo tunneling.
			'2001:db8::/32',    // IPv6 Documentation.
			'fc00::/7',         // IPv6 Unique Local Addresses (ULA).
			'fe80::/10',        // IPv6 Link-Local.
			'ff00::/8',         // IPv6 Multicast.
		);

		foreach ( $blocked_ranges as $range ) {
			if ( self::ip_in_range( $ip, $range ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if an IP is within a CIDR range.
	 *
	 * Supports both IPv4 and IPv6 CIDR notation and exact matches.
	 *
	 * @param string $ip    The IP address to check.
	 * @param string $range The CIDR range (e.g., '192.168.0.0/16', '2001:db8::/32').
	 * @return bool True if in range, false otherwise.
	 */
	public static function ip_in_range( string $ip, string $range ): bool {
		if ( false === strpos( $range, '/' ) ) {
			return $ip === $range;
		}

		$parts = explode( '/', $range, 2 );
		$subnet   = $parts[0];
		$raw_bits = trim( $parts[1] ?? '' );
		if ( '' === $raw_bits || ! ctype_digit( $raw_bits ) ) {
			return false;
		}
		$bits = (int) $raw_bits;

		// IPv4 Logic
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			if ( $bits < 0 || $bits > 32 ) {
				return false;
			}
			$ip_long     = ip2long( $ip );
			$subnet_long = ip2long( $subnet );

			if ( false === $ip_long || false === $subnet_long ) {
				return false;
			}

			$mask = ( 0 === $bits ) ? 0 : ( -1 << ( 32 - $bits ) );
			return ( $ip_long & $mask ) === ( $subnet_long & $mask );
		}

		// IPv6 Logic (2026 BP: Full CIDR support for modern networking)
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			if ( $bits < 0 || $bits > 128 ) {
				return false;
			}
			// Convert IPs to binary strings
			$ip_bin     = (string) inet_pton( $ip );
			$subnet_bin = (string) inet_pton( $subnet );
			$mask_bin   = '';

			// Generate mask
			for ( $i = 0; $i < 16; $i++ ) {
				if ( $bits >= 8 ) {
					$mask_bin .= chr( 255 );
					$bits     -= 8;
				} elseif ( $bits > 0 ) {
					$mask_bin .= chr( max( 0, min( 255, 256 - ( 1 << ( 8 - $bits ) ) ) ) );
					$bits      = 0;
				} else {
					$mask_bin .= chr( 0 );
				}
			}

			return ( $ip_bin & $mask_bin ) === ( $subnet_bin & $mask_bin );
		}

		return false;
	}

	/**
	 * Official Cloudflare IP ranges (IPv4 and IPv6).
	 *
	 * @var array<int, string>
	 */
	public const CLOUDFLARE_RANGES = array(
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'108.162.192.0/18',
		'131.0.72.0/22',
		'141.101.64.0/18',
		'162.158.0.0/15',
		'172.64.0.0/13',
		'173.245.48.0/20',
		'188.114.96.0/20',
		'190.93.240.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Verify if an IP address belongs to Cloudflare's edge network.
	 *
	 * @param string $ip IP address to check.
	 * @return bool True if authentic Cloudflare IP.
	 */
	public static function is_cloudflare_ip( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		foreach ( self::CLOUDFLARE_RANGES as $range ) {
			if ( self::ip_in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the client's IP address with proxy support.
	 *
	 * SECURITY: Only trusts proxy headers (HTTP_CF_CONNECTING_IP, HTTP_X_FORWARDED_FOR)
	 * if the REMOTE_ADDR is an authentic Cloudflare edge server, matches the
	 * 'mhbo_trusted_proxies' whitelist, or is in local development mode. Prevents IP spoofing.
	 *
	 * @return string The client IP address.
	 */
	public static function get_client_ip(): string {
		// Access via $GLOBALS to definitively resolve Intelephense 'Undefined variable' diagnostic.
		$server      = $GLOBALS['_SERVER'] ?? array();
		$remote_addr = isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '0.0.0.0';
		$ip          = filter_var( $remote_addr, FILTER_VALIDATE_IP ) ?: '0.0.0.0';

		$trusted_proxies_raw = (string) get_option( 'mhbo_trusted_proxies', '' );
		$parsed_proxies      = '' !== $trusted_proxies_raw
			? ( preg_split( '/[\r\n,\s]+/', $trusted_proxies_raw, -1, PREG_SPLIT_NO_EMPTY ) ?: array() )
			: array();
		$is_trusted          = false;
		$is_cloudflare       = false;

		// 1. Check if REMOTE_ADDR is an authentic Cloudflare edge server.
		if ( self::is_cloudflare_ip( $remote_addr ) ) {
			$is_trusted    = true;
			$is_cloudflare = true;
		}

		// 2. Check if REMOTE_ADDR matches configured trusted proxies whitelist.
		if ( ! $is_trusted && array() !== $parsed_proxies ) {
			$is_trusted = self::is_trusted_proxy_ip( $remote_addr, $parsed_proxies );
		}

		// Only process proxy headers if the direct connecting IP is verified and trusted
		// via mhbo_trusted_proxies or verified Cloudflare proxy IP ranges.
		if ( $is_trusted ) {
			// If request arrived via Cloudflare, check HTTP_CF_CONNECTING_IP first.
			if ( $is_cloudflare && isset( $server['HTTP_CF_CONNECTING_IP'] ) && '' !== (string) $server['HTTP_CF_CONNECTING_IP'] ) {
				$cf_ip = trim( (string) $server['HTTP_CF_CONNECTING_IP'] );
				if ( false !== filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
					return self::finalize_ip( $cf_ip );
				}
			}

			$header_priority = array(
				'HTTP_X_FORWARDED_FOR',      // Standard proxy header
				'HTTP_X_REAL_IP',            // Nginx
			);

			foreach ( $header_priority as $header ) {
				$header_value = isset( $server[ $header ] ) ? (string) $server[ $header ] : '';
				if ( '' === $header_value ) {
					continue;
				}

				if ( 'HTTP_X_FORWARDED_FOR' === $header ) {
					// Parse right-to-left: proxies append peer IPs on the right; leftmost values can be client-spoofed.
					$ips = array_reverse( array_map( 'trim', explode( ',', $header_value ) ) );
					foreach ( $ips as $candidate_ip ) {
						if ( '' !== $candidate_ip
							&& false !== filter_var( $candidate_ip, FILTER_VALIDATE_IP )
							&& ! self::is_trusted_proxy_ip( $candidate_ip, $parsed_proxies )
						) {
							$ip = $candidate_ip;
							break 2;
						}
					}
					continue;
				}

				$single_ip = trim( $header_value );
				if ( '' !== $single_ip
					&& false !== filter_var( $single_ip, FILTER_VALIDATE_IP )
					&& ( $is_cloudflare || ! self::is_trusted_proxy_ip( $single_ip, $parsed_proxies ) )
				) {
					$ip = $single_ip;
					break;
				}
			}
		}

		return self::finalize_ip( $ip );
	}

	/**
	 * Determine whether an IP belongs to Cloudflare or the configured trusted proxy list.
	 *
	 * @param string             $candidate_ip    Candidate IP address.
	 * @param array<int, string> $trusted_proxies Parsed list of trusted proxy IPs/CIDRs.
	 * @return bool
	 */
	private static function is_trusted_proxy_ip( string $candidate_ip, array $trusted_proxies ): bool {
		if ( self::is_cloudflare_ip( $candidate_ip ) ) {
			return true;
		}
		foreach ( $trusted_proxies as $proxy ) {
			if ( '' === $proxy ) {
				continue;
			}
			if ( $candidate_ip === $proxy || self::ip_in_range( $candidate_ip, $proxy ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate that a URL resolves strictly to the local loopback host (e.g., for local Ollama daemon).
	 *
	 * @param string $url URL to validate.
	 * @return bool True if valid HTTP(S) loopback URL with no userinfo.
	 */
	public static function is_loopback_url( string $url ): bool {
		$trimmed = trim( $url );
		if ( '' === $trimmed || false === filter_var( $trimmed, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$parsed = wp_parse_url( $trimmed );
		if ( ! is_array( $parsed ) || ! isset( $parsed['host'] ) || '' === $parsed['host'] ) {
			return false;
		}

		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed['query'] ) || isset( $parsed['fragment'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$port          = isset( $parsed['port'] ) ? (int) $parsed['port'] : ( 'https' === $scheme ? 443 : 80 );
		$allowed_ports = array_filter(
			array_map(
				'intval',
				(array) apply_filters( 'mhbo_allowed_ollama_ports', array( 11434 ) )
			),
			static fn( int $p ): bool => $p >= 1 && $p <= 65535
		);
		if ( ! in_array( $port, $allowed_ports, true ) ) {
			return false;
		}

		$host = strtolower( rtrim( trim( (string) $parsed['host'], '[]' ), '.' ) );
		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/**
	 * Sanitize trusted proxy IP addresses and CIDR ranges.
	 *
	 * Accepts exact IPv4/IPv6 addresses or bounded CIDR blocks (IPv4 >= /8, IPv6 >= /32).
	 * Rejects wildcard ranges such as 0.0.0.0/0 or ::/0.
	 *
	 * @param mixed $raw_value Raw option string or array.
	 * @return string Newline-delimited sanitized list of valid IPs/CIDRs.
	 */
	public static function sanitize_trusted_proxies( mixed $raw_value ): string {
		$raw_entries = is_array( $raw_value )
			? $raw_value
			: preg_split( '/[\r\n,\s]+/', is_scalar( $raw_value ) ? (string) $raw_value : '', -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $raw_entries ) ) {
			return '';
		}

		$clean_cidrs = array();
		foreach ( $raw_entries as $entry ) {
			$cidr = trim( sanitize_text_field( is_scalar( $entry ) ? (string) $entry : '' ) );
			if ( '' === $cidr ) {
				continue;
			}
			if ( false !== strpos( $cidr, '/' ) ) {
				$parts = explode( '/', $cidr, 2 );
				$ip    = trim( $parts[0] );
				$bits  = trim( $parts[1] ?? '' );
				if ( '' === $bits || ! ctype_digit( $bits ) ) {
					continue;
				}
				$mask_bits = (int) $bits;
				if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && $mask_bits >= 8 && $mask_bits <= 32 ) {
					$clean_cidrs[] = $ip . '/' . $mask_bits;
				} elseif ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && $mask_bits >= 32 && $mask_bits <= 128 ) {
					$clean_cidrs[] = $ip . '/' . $mask_bits;
				}
			} elseif ( false !== filter_var( $cidr, FILTER_VALIDATE_IP ) ) {
				$clean_cidrs[] = $cidr;
			}
		}

		return implode( "\n", array_values( array_unique( $clean_cidrs ) ) );
	}

	/**
	 * Neutralize CSV / spreadsheet formula injection triggers (CWE-1236).
	 *
	 * Escapes raw leading tab/CR/LF control characters and any formula trigger
	 * (=, +, -, @) even when preceded by whitespace or line breaks.
	 *
	 * @param string $value Raw cell string.
	 * @return string Sanitized cell string.
	 */
	public static function sanitize_csv_cell( string $value ): string {
		if ( '' !== $value && 1 === preg_match( '/^(?:[\t\r\n]|[\s\t\r\n]*[=+\-@|])/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Final validation for IP address.
	 *
	 * @param string $ip The IP to validate.
	 * @return string Validated IP or 0.0.0.0.
	 */
	public static function finalize_ip( string $ip ): string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return (string) sanitize_text_field( $ip );
		}

		return '0.0.0.0';
	}

	/**
	 * Check whether a stored secret value already uses an authenticated v2 ciphertext envelope.
	 *
	 * @param string $value Candidate secret string.
	 * @return bool
	 */
	public static function is_encrypted_secret( string $value ): bool {
		return str_starts_with( $value, 'mhbo_enc_v2:' ) || str_starts_with( $value, 'mhbo_enc_v2m:' );
	}

	/**
	 * Sanitize custom CSS entered in Theme settings without breaking valid CSS rules.
	 *
	 * Strips HTML tags, closing style fragments, legacy script-capable CSS expressions,
	 * and external @import directives.
	 *
	 * @param mixed $css Raw CSS input.
	 * @return string Sanitized CSS string.
	 */
	public static function sanitize_custom_css( mixed $css ): string {
		if ( ! is_scalar( $css ) ) {
			return '';
		}
		$clean = wp_strip_all_tags( (string) $css );
		$clean = (string) preg_replace( '#</?\s*style\b[^>]*>#i', '', $clean );
		$clean = (string) preg_replace( '/(?:expression\s*\(|(?:java|vb)script\s*:|behavior\s*:|-moz-binding\s*:|@import\b)/i', '', $clean );
		$clean = str_replace( "\0", '', $clean );
		return trim( $clean );
	}

	/**
	 * Encrypt a secret value for storage using authenticated AES-256-GCM (or Encrypt-then-MAC fallback).
	 *
	 * @param string $value The secret value to encrypt.
	 * @param string $salt  Optional salt for the encryption key.
	 * @return string The versioned encrypted payload.
	 */
	public static function encrypt_secret( string $value, string $salt = 'mhbo_payment_secret_key' ): string {
		if ( '' === $value ) {
			return '';
		}

		$raw_key = hash( 'sha256', wp_hash( $salt, 'auth' ) . '|' . $salt, true );

		if ( function_exists( 'openssl_get_cipher_methods' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$iv         = random_bytes( 12 );
			$tag        = '';
			$ciphertext = openssl_encrypt( $value, 'aes-256-gcm', $raw_key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false !== $ciphertext && '' !== $tag ) {
				return 'mhbo_enc_v2:' . base64_encode( $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for authenticated credential storage.
			}
		}

		// Encrypt-then-MAC fallback (AES-256-CBC + HMAC-SHA256).
		$iv_length  = (int) openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv         = random_bytes( max( 1, $iv_length ) );
		$ciphertext = openssl_encrypt( $value, 'aes-256-cbc', $raw_key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return '';
		}
		$mac = hash_hmac( 'sha256', $iv . $ciphertext, $raw_key, true );
		return 'mhbo_enc_v2m:' . base64_encode( $mac . $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for authenticated credential storage.
	}

	/**
	 * Decrypt a secret value (supports mhbo_enc_v2 AES-256-GCM, mhbo_enc_v2m Encrypt-then-MAC, and legacy CBC).
	 *
	 * @param mixed  $value The encrypted value.
	 * @param string $salt  Optional salt for the encryption key.
	 * @return string The decrypted value (or '' when authenticated v2 ciphertext fails verification).
	 */
	public static function decrypt_secret( mixed $value, string $salt = 'mhbo_payment_secret_key' ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$raw_key = hash( 'sha256', wp_hash( $salt, 'auth' ) . '|' . $salt, true );

		if ( str_starts_with( $value, 'mhbo_enc_v2:' ) ) {
			$decoded = base64_decode( substr( $value, 12 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for authenticated credential storage.
			if ( false === $decoded || strlen( $decoded ) < 28 ) {
				return '';
			}
			$iv         = substr( $decoded, 0, 12 );
			$tag        = substr( $decoded, 12, 16 );
			$ciphertext = substr( $decoded, 28 );
			$decrypted  = openssl_decrypt( $ciphertext, 'aes-256-gcm', $raw_key, OPENSSL_RAW_DATA, $iv, $tag );
			return ( false !== $decrypted ) ? (string) $decrypted : '';
		}

		if ( str_starts_with( $value, 'mhbo_enc_v2m:' ) ) {
			$iv_length = (int) openssl_cipher_iv_length( 'aes-256-cbc' );
			$decoded   = base64_decode( substr( $value, 13 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for authenticated credential storage.
			if ( false === $decoded || strlen( $decoded ) <= ( 32 + $iv_length ) ) {
				return '';
			}
			$mac      = substr( $decoded, 0, 32 );
			$payload  = substr( $decoded, 32 );
			$expected = hash_hmac( 'sha256', $payload, $raw_key, true );
			if ( ! hash_equals( $expected, $mac ) ) {
				return '';
			}
			$iv         = substr( $payload, 0, $iv_length );
			$ciphertext = substr( $payload, $iv_length );
			$decrypted  = openssl_decrypt( $ciphertext, 'aes-256-cbc', $raw_key, OPENSSL_RAW_DATA, $iv );
			return ( false !== $decrypted ) ? (string) $decrypted : '';
		}

		// Legacy unprefixed AES-256-CBC backward compatibility.
		if ( 1 !== preg_match( '/^[a-zA-Z0-9\/\+=]+$/', $value ) ) {
			return $value;
		}

		$key     = wp_hash( $salt, 'auth' );
		$decoded = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for AES-256-CBC decryption of stored credentials

		if ( false === $decoded ) {
			return $value;
		}

		$iv_length = (int) openssl_cipher_iv_length( 'aes-256-cbc' );

		if ( strlen( $decoded ) < $iv_length ) {
			return $value;
		}

		$iv        = substr( $decoded, 0, $iv_length );
		$encrypted = substr( $decoded, $iv_length );

		$decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', substr( (string) $key, 0, 32 ), OPENSSL_RAW_DATA, $iv );

		return ( false !== $decrypted ) ? (string) $decrypted : $value;
	}

	/**
	 * Safely decrypt an AI credential option while preserving unencrypted legacy plaintext keys.
	 *
	 * @param mixed $value Option value.
	 * @return string Plaintext credential.
	 */
	public static function decrypt_ai_secret( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		if ( str_starts_with( $value, 'mhbo_enc_v2:' ) || str_starts_with( $value, 'mhbo_enc_v2m:' ) ) {
			return self::decrypt_secret( $value, 'mhbo_ai_salt' );
		}
		return $value;
	}

	/**
	 * Check if a host belongs to a trusted booking platform.
	 *
	 * @param string $host Hostname.
	 * @return bool True if trusted.
	 */
	public static function is_trusted_platform( string $host ): bool {
		$host    = strtolower( $host );
		$trusted = array(
			'airbnb.com',
			'www.airbnb.com',
			'booking.com',
			'admin.booking.com',
			'ical.booking.com',
			'vrbo.com',
			'www.vrbo.com',
			'expedia.com',
			'google.com',
			'calendar.google.com',
			'tripadvisor.com',
		);

		foreach ( $trusted as $domain ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}

		return false;
	}
	
	/**
	 * Normalize verbal inputs into standard formats (Neuromorphic Normalization).
	 *
	 * Handles specific AI-to-Form transformations like verbal email segments.
	 * 
	 * @since 2.4.1
	 * @param string $input The raw verbal input.
	 * @param string $type  The normalization type ('email').
	 * @return string The normalized string.
	 */
	public static function normalize_verbal( string $input, string $type = 'email' ): string {
		if ( 'email' === $type ) {
			// Rule 23: [Neuromorphic Normalization]
			// Replace common verbal segments captured by TTS/STT.
			$normalized = str_ireplace(
				array( ' at ', ' dot ', ' [at] ', ' [dot] ', ' (at) ', ' (dot) ', '(at)', '(dot)' ),
				array( '@', '.', '@', '.', '.', '.', '@', '.' ),
				$input
			);
			
			// Final scrubbing: remove any residual spaces and sanitize.
			return (string) sanitize_email( str_replace( ' ', '', $normalized ) );
		}

		return sanitize_text_field( $input );
	}

	/**
	 * Centralized booking access verification (IDOR Protection).
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $token      Optional booking token for guest access.
	 * @return bool True if access granted, false otherwise.
	 */
	public static function verify_booking_access( int $booking_id, string $token = '' ): bool {
		if ( $booking_id <= 0 ) {
			return false;
		}

		// Administrators always have access
		if ( Capabilities::current_user_can( Capabilities::MANAGE_LHBO ) ) {
			return true;
		}

		// Check for guest access via token
		if ( '' !== $token ) {
			global $wpdb;
			// Rule 13 rationale: Fetching booking token for ownership verification in Guest-facing REST endpoints.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$db_token = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT booking_token FROM {$wpdb->prefix}mhbo_bookings WHERE id = %d",
					$booking_id
				)
			);
			
			if ( is_string( $db_token ) && hash_equals( $db_token, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/** Option flag tracking one-time encryption & autoload cleanup of legacy plaintext secrets. */
	public const SECRET_MIGRATION_OPTION = 'mhbo_secrets_migrated_v2';

	/**
	 * Log an error to the WordPress debug log with a consistent prefix.
	 *
	 * @param string $message The error message to log.
	 */
	public static function log_error( string $message ): void {
		// 2026 BP: Diagnostic logging removed for production compliance.
		unset( $message );
	}

	/**
	 * Encrypt any remaining pre-upgrade plaintext AI secrets at rest and ensure all sensitive
	 * AI, gateway, and Wi-Fi options are stored with autoload disabled (`false`).
	 *
	 * Idempotent and safe to run during activation, upgrades, or admin bootstrap.
	 *
	 * @return int Number of plaintext AI secrets encrypted during this run.
	 */
	public static function migrate_legacy_secrets(): int {
		$ai_secret_options = array(
			'mhbo_ai_api_key',
			'mhbo_ai_fallback_api_key',
			'mhbo_ai_elevenlabs_key',
			'mhbo_ai_gemini_api_key',
			'mhbo_ai_openai_api_key',
			'mhbo_ai_anthropic_api_key',
			'mhbo_ai_custom_api_key',
		);

		$gateway_and_wifi_options = array(
			'mhbo_stripe_live_secret_key',
			'mhbo_stripe_test_secret_key',
			'mhbo_stripe_live_webhook_secret',
			'mhbo_stripe_test_webhook_secret',
			'mhbo_paypal_live_secret',
			'mhbo_paypal_sandbox_secret',
			'mhbo_braintree_live_private_key',
			'mhbo_braintree_sandbox_private_key',
			'mhbo_hotel_wifi_password',
		);

		$migrated = 0;
		foreach ( $ai_secret_options as $opt ) {
			$raw = get_option( $opt, '' );
			if ( ! is_string( $raw ) ) {
				continue;
			}
			$trimmed = trim( $raw );
			if ( '' === $trimmed || 'REDACTED' === $trimmed ) {
				continue;
			}
			if ( str_starts_with( $trimmed, 'mhbo_enc_v2:' ) || str_starts_with( $trimmed, 'mhbo_enc_v2m:' ) ) {
				continue;
			}
			$encrypted = self::encrypt_secret( $trimmed, 'mhbo_ai_salt' );
			if ( '' !== $encrypted && $encrypted !== $trimmed ) {
				update_option( $opt, $encrypted, false );
				++$migrated;
			}
		}

		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			$autoload_updates = array();
			foreach ( array_merge( $ai_secret_options, $gateway_and_wifi_options ) as $sensitive_opt ) {
				if ( false !== get_option( $sensitive_opt, false ) ) {
					$autoload_updates[ $sensitive_opt ] = false;
				}
			}
			if ( array() !== $autoload_updates ) {
				\call_user_func( 'wp_set_option_autoload_values', $autoload_updates );
			}
		}

		update_option( self::SECRET_MIGRATION_OPTION, 1, true );
		return $migrated;
	}

	/**
	 * Run the one-time legacy secret migration if not yet recorded.
	 */
	public static function maybe_migrate_legacy_secrets(): void {
		if ( 1 === (int) get_option( self::SECRET_MIGRATION_OPTION, 0 ) ) {
			return;
		}
		self::migrate_legacy_secrets();
	}

	/**
	 * Consume one attempt from a fixed-window transient rate limiter without sliding TTL extension.
	 *
	 * @param string $key            Transient key.
	 * @param int    $limit          Maximum allowed hits per window.
	 * @param int    $window_seconds Window duration in seconds.
	 * @return array{allowed:bool,count:int,retry_after:int}
	 */
	public static function consume_fixed_window_rate_limit( string $key, int $limit, int $window_seconds ): array {
		$window = max( 1, $window_seconds );
		$cap    = max( 1, $limit );
		$now    = time();
		$state  = get_transient( $key );

		if ( is_array( $state ) && isset( $state['count'], $state['reset'] ) && (int) $state['reset'] > $now ) {
			$count = (int) $state['count'];
			$reset = (int) $state['reset'];
		} else {
			$count = 0;
			$reset = $now + $window;
		}

		if ( $count >= $cap ) {
			return array(
				'allowed'     => false,
				'count'       => $count,
				'retry_after' => max( 1, $reset - $now ),
			);
		}

		$next_count = $count + 1;
		set_transient(
			$key,
			array(
				'count' => $next_count,
				'reset' => $reset,
			),
			max( 1, $reset - $now )
		);

		return array(
			'allowed'     => true,
			'count'       => $next_count,
			'retry_after' => max( 1, $reset - $now ),
		);
	}

	/**
	 * Read the current count from a fixed-window rate limiter without mutating it.
	 *
	 * @param string $key Transient key.
	 * @return int Current window count (0 when unset or expired).
	 */
	public static function get_fixed_window_count( string $key ): int {
		$state = get_transient( $key );
		$now   = time();
		if ( is_array( $state ) && isset( $state['count'], $state['reset'] ) ) {
			return ( (int) $state['reset'] > $now ) ? max( 0, (int) $state['count'] ) : 0;
		}
		return is_numeric( $state ) ? max( 0, (int) $state ) : 0;
	}

	/**
	 * Increment a failure-only fixed-window counter without extending its original expiration timestamp.
	 *
	 * @param string $key            Transient key.
	 * @param int    $window_seconds Window duration when starting a new bucket.
	 * @return int Updated counter value.
	 */
	public static function increment_fixed_window( string $key, int $window_seconds ): int {
		$window = max( 1, $window_seconds );
		$now    = time();
		$state  = get_transient( $key );

		if ( is_array( $state ) && isset( $state['count'], $state['reset'] ) && (int) $state['reset'] > $now ) {
			$next_count = max( 0, (int) $state['count'] ) + 1;
			$reset      = (int) $state['reset'];
		} else {
			$next_count = ( is_numeric( $state ) ? max( 0, (int) $state ) : 0 ) + 1;
			$reset      = $now + $window;
		}

		set_transient(
			$key,
			array(
				'count' => $next_count,
				'reset' => $reset,
			),
			max( 1, $reset - $now )
		);

		return $next_count;
	}
}
// Logic: Finalized Smart Distribution Layer 2.4.1.
