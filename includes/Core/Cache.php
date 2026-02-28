<?php

declare(strict_types=1);

namespace MHB\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Modern Hotel Booking Cache Handler.
 *
 * Provides a unified interface for WP Object Cache and Transients.
 */
class Cache
{
    /** @var string Cache group for MHB */
    private const GROUP = 'mhb';

    /** @var int Default expiration in seconds (1 hour) */
    private const DEFAULT_EXPIRY = 3600;

    /**
     * Check if object cache is available.
     *
     * @return bool True if object cache is active.
     */
    public static function is_object_cache_available(): bool
    {
        return (bool) wp_using_ext_object_cache();
    }

    /**
     * Set a cache value.
     *
     * @param string $key Cache key.
     * @param mixed  $value Value to cache.
     * @param int    $expiry Expiration in seconds.
     * @return bool True on success.
     */
    public static function set(string $key, $value, int $expiry = self::DEFAULT_EXPIRY): bool
    {
        $prefixed_key = self::get_cache_key($key);

        // Always try object cache first
        $result = wp_cache_set($prefixed_key, $value, self::GROUP, $expiry);

        // Fallback to transients if object cache is not available
        if (!self::is_object_cache_available()) {
            set_transient(self::get_transient_key($key), $value, $expiry);
        }

        return $result;
    }

    /**
     * Get a cache value.
     *
     * @param string $key Cache key.
     * @return mixed Cached value or false on failure.
     */
    public static function get(string $key)
    {
        $prefixed_key = self::get_cache_key($key);

        $value = wp_cache_get($prefixed_key, self::GROUP);

        if (false === $value && !self::is_object_cache_available()) {
            $value = get_transient(self::get_transient_key($key));
        }

        return $value;
    }

    /**
     * Delete a cache value.
     *
     * @param string $key Cache key.
     * @return bool True on success.
     */
    public static function delete(string $key): bool
    {
        $prefixed_key = self::get_cache_key($key);

        wp_cache_delete($prefixed_key, self::GROUP);

        if (!self::is_object_cache_available()) {
            delete_transient(self::get_transient_key($key));
        }

        return true;
    }

    /**
     * Flush all MHB-related cache and transients.
     *
     * @return bool True on success.
     */
    public static function flush(): bool
    {
        global $wpdb;

        // Delete all MHB transients
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache flush operation, patterns are hardcoded
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_mhb_cache_%' 
             OR option_name LIKE '_site_transient_mhb_cache_%'"
        );

        // Flush object cache group if available
        if (self::is_object_cache_available() && function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::GROUP);
        }

        return false !== $deleted;
    }

    /**
     * IMPORTANT: Room availability should NEVER be cached.
     * 
     * Availability must always be checked in real-time to prevent double bookings.
     */

    /**
     * Get pricing rules with caching.
     */
    public static function get_pricing_rules(int $room_id, string $date)
    {
        $key = sprintf('pricing_rules_%d_%s', $room_id, $date);
        return self::get($key);
    }

    /**
     * Set pricing rules cache.
     */
    public static function set_pricing_rules(int $room_id, string $date, array $rules, int $expiry = 3600): bool
    {
        $key = sprintf('pricing_rules_%d_%s', $room_id, $date);
        return self::set($key, $rules, $expiry);
    }

    /**
     * Invalidate room-related caches.
     *
     * @param int $room_id Room ID.
     */
    public static function invalidate_room(int $room_id): void
    {
        global $wpdb;

        // Delete pricing rules for this room
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient cleanup
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             OR option_name LIKE %s",
            '_transient_mhb_cache_pricing_rules_' . $room_id . '_%',
            '_site_transient_mhb_cache_pricing_rules_' . $room_id . '_%'
        ));

        self::invalidate_calendar_cache($room_id);
    }

    /**
     * Invalidate all cached data related to a specific booking.
     *
     * @param int|string $booking_id Booking ID or transaction ID.
     * @param int|null   $room_id    Optional Room ID to also clear relevant room/calendar caches.
     */
    public static function invalidate_booking($booking_id, ?int $room_id = null): void
    {
        // Clear direct ID cache
        wp_cache_delete('mhb_booking_' . $booking_id, 'mhb_bookings');

        // Clear transaction lookup cache if it looks like a TX ID
        if (is_string($booking_id) && strlen($booking_id) > 10) {
            wp_cache_delete('mhb_booking_tx_' . md5($booking_id), 'mhb_bookings');
        }

        // Clear all bookings list
        self::invalidate_all_bookings();

        // Clear related room availability
        if ($room_id) {
            self::invalidate_calendar_cache((int) $room_id);
        }
    }

    /**
     * Invalidate all booking lists.
     */
    public static function invalidate_all_bookings(): void
    {
        if (self::is_object_cache_available() && function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('mhb_bookings');
        }
    }

    /**
     * Invalidate calendar booking cache for a room.
     *
     * @param int $room_id Room ID.
     */
    public static function invalidate_calendar_cache(int $room_id): void
    {
        wp_cache_delete('mhb_calendar_bookings_' . $room_id, self::GROUP);

        // Also clear room status/availability cache from Pricing.php
        $today = gmdate('Y-m-d');
        wp_cache_delete('room_status_' . $room_id . '_' . $today, 'mhb_rooms');

        // Clear general room cache
        wp_cache_delete('mhb_all_rooms', 'mhb_rooms');
    }

    /**
     * Generate cache key with prefix.
     */
    private static function get_cache_key(string $key): string
    {
        return 'mhb_' . $key;
    }

    /**
     * Generate transient key with prefix.
     */
    private static function get_transient_key(string $key): string
    {
        return 'mhb_cache_' . $key;
    }
}
