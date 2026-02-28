<?php
/**
 * Upsell Component for Free Version
 *
 * @package MHB\Admin
 * @since 2.2.3
 */

declare(strict_types=1);

namespace MHB\Admin;

/**
 * Class Upsell
 *
 * Renders Pro feature upsell notices and badges.
 */
class Upsell {

    /**
     * Pro version upgrade URL.
     */
    private const PRO_URL = 'https://startmysuccess.com/modern-hotel-booking-pro';

    /**
     * Render a Pro feature notice.
     *
     * @param string $feature_name Feature name to display.
     * @param string $description  Optional feature description.
     */
    public static function render_pro_feature_notice(string $feature_name, string $description = ''): void {
        ?>
        <div class="mhb-upsell-notice">
            <span class="dashicons dashicons-lock"></span>
            <h3><?php echo esc_html($feature_name); ?> - <?php esc_html_e('Pro Feature', 'modern-hotel-booking'); ?></h3>
            <?php if ($description): ?>
                <p><?php echo esc_html($description); ?></p>
            <?php endif; ?>
            <p><?php esc_html_e('Upgrade to Pro to unlock this feature.', 'modern-hotel-booking'); ?></p>
            <a href="<?php echo esc_url(self::PRO_URL); ?>" 
               class="button button-primary" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Upgrade to Pro', 'modern-hotel-booking'); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Render a Pro badge next to menu items.
     *
     * @return void
     */
    public static function render_pro_badge(): void {
        echo '<span class="mhb-pro-badge" style="background:#f0ad4e;color:#fff;font-size:10px;padding:2px 5px;border-radius:3px;margin-left:5px;">PRO</span>';
    }

    /**
     * Render an inline upsell link.
     *
     * @param string $text Link text.
     * @return void
     */
    public static function render_upgrade_link(string $text = ''): void {
        if (empty($text)) {
            $text = __('Upgrade to Pro', 'modern-hotel-booking');
        }
        ?>
        <a href="<?php echo esc_url(self::PRO_URL); ?>" 
           target="_blank" 
           rel="noopener noreferrer"
           class="mhb-upgrade-link">
            <?php echo esc_html($text); ?>
        </a>
        <?php
    }

    /**
     * Get the Pro version URL.
     *
     * @return string
     */
    public static function get_pro_url(): string {
        return self::PRO_URL;
    }

    /**
     * Check if a feature should show upsell.
     *
     * @param string $feature Feature identifier.
     * @return bool Always true in Free version.
     */
    public static function should_show_upsell(string $feature): bool {
        return true;
    }
}