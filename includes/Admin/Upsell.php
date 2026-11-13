<?php
namespace MHBO\Admin;

if (!defined('ABSPATH')) exit;

class Upsell {
    public static function render_pro_badge() {
        echo '<span class="mhbo-pro-badge" style="background:#f0ad4e;color:#fff;font-size:10px;padding:2px 5px;border-radius:3px;margin-left:5px;">PRO</span>';
    }
    public static function render_pro_feature_notice($feature) {
        echo '<div class="mhbo-upsell-notice">' . esc_html($feature) . ' Pro Feature</div>';
    }
    public static function render_card(string $title, string $description = ''): void {
        if (class_exists(AdminUI::class)) {
            AdminUI::render_card_start($title);
        } else {
            echo '<div class="mhbo-card"><h2>' . esc_html($title) . '</h2>';
        }
        if ('' !== $description) {
            echo '<p>' . esc_html($description) . '</p>';
        }
        echo '<p><a href="' . esc_url('https://startmysuccess.com/modern-hotel-booking-wordpress-plugin/') . '" target="_blank" rel="noopener noreferrer" class="button button-primary">' . esc_html__('Learn More About Pro', 'modern-hotel-booking') . '</a></p>';
        if (class_exists(AdminUI::class)) {
            AdminUI::render_card_end();
        } else {
            echo '</div>';
        }
    }
}
