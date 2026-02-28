<?php declare(strict_types=1);

/**
 * License Stub for Free Version
 *
 * This file is used only in the free version build.
 * It provides stub methods to prevent fatal errors when Pro features are accessed.
 *
 * @package MHB\Core
 * @since 2.2.3
 */



namespace MHB\Core;

/**
 * Class License
 *
 * Stub implementation for Free version.
 * Provides safe fallbacks for Pro feature checks.
 */
class License
{
    /**
     * Check if license is active (always false in free version)
     *
     * @return bool Always returns false in free version.
     */
    public static function is_active(): bool
    {
        return false;
    }

    /**
     * Render upsell notice for Pro features
     *
     * @param string $feature_name Name of the Pro feature being accessed.
     * @return void
     */
    public static function render_upsell_notice(string $feature_name = ''): void
    {
        $feature_display = $feature_name ? $feature_name : __('Pro Feature', 'modern-hotel-booking');

        printf(
            '<div class="mhb-upsell-notice"><p><strong>%s</strong> %s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div>',
            /* translators: %s: Feature name */
            sprintf(esc_html__('%s is a Pro feature.', 'modern-hotel-booking'), esc_html($feature_display)),
            esc_html__('Upgrade to unlock this feature.', 'modern-hotel-booking'),
            esc_url('https://startmysuccess.com/shop/wordpress-plugins/hotel-booking-wordpress-plugin/'),
            esc_html__('Upgrade Now', 'modern-hotel-booking')
        );
    }

    /**
     * Get license status (always inactive in free version)
     *
     * @return string Always returns 'inactive'.
     */
    public static function get_status(): string
    {
        return 'inactive';
    }

    /**
     * Check license (always returns false in free version)
     *
     * @param string|null $license_key Optional license key (ignored).
     * @return bool Always returns false.
     */
    public static function check(?string $license_key = null): bool
    {
        return false;
    }

    /**
     * Check if in grace period (always false in free version)
     *
     * @return bool Always false.
     */
    public static function is_in_grace_period(): bool
    {
        return false;
    }

    /**
     * Check if grace period expired (always false in free version)
     *
     * @return bool Always false.
     */
    public static function is_grace_expired(): bool
    {
        return false;
    }

    /**
     * Get grace remaining (always 0 in free version)
     *
     * @return int Always 0.
     */
    public static function get_grace_remaining(): int
    {
        return 0;
    }

    /**
     * Get grace remaining text (always empty in free version)
     *
     * @return string Always empty.
     */
    public static function get_grace_remaining_text(): string
    {
        return '';
    }

    /**
     * Check if license is expired (always false in free version)
     *
     * @return bool Always false.
     */
    public static function is_expired(): bool
    {
        return false;
    }
}
