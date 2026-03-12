<?php
declare(strict_types=1);
// Free version build constant
define('MHBO_IS_PRO', false);

// Free version build constant


/*
 * Plugin Name:       Modern Hotel Booking
 * Plugin URI:        https://github.com/leslieradue-web/modern-hotel-booking-free
 * Description:       Hotel Booking System for WordPress. Manage rooms, reservations and availability.
 * Version:           2.2.7.1
 * Tested up to:      6.9
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            StartMySuccess
 * Author URI:        https://startmysuccess.com/modern-hotel-booking-wordpress-plugin/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       modern-hotel-booking
 * Domain Path:       /languages 
 */

if (!defined('ABSPATH')) {
    exit;
}

// PHP version check - must be at least 7.4
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error">';
        echo '<p><strong>' . esc_html__('Modern Hotel Booking Error', 'modern-hotel-booking') . '</strong></p>';
        echo '<p>' . sprintf(
            // translators: %s: current PHP version number
            esc_html__('This plugin requires PHP 7.4 or higher. You are running PHP %s. Please upgrade your PHP version.', 'modern-hotel-booking'),
            PHP_VERSION
        ) . '</p>';
        echo '</div>';
    });
    return;
}

define('MHBO_VERSION', '2.2.7.1');

define('MHBO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MHBO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MHBO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * PSR-4-style autoloader for MHBO\ namespace.
 */
spl_autoload_register(function ($class) {
    $prefix = 'MHBO\\';
    $base_dir = MHBO_PLUGIN_DIR . 'includes/';

    // Remove leading backslash if present
    $class = ltrim($class, '\\');

    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    $file = str_replace('//', '/', $file);

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Activation hook.
 */
function mhbo_activate(): void
{
    $activator_file = MHBO_PLUGIN_DIR . 'includes/Core/Activator.php';
    if (file_exists($activator_file)) {
        require_once $activator_file;
        if (class_exists('MHBO\Core\Activator')) {
            \MHBO\Core\Activator::activate();
        }
    }
}

/**
 * Deactivation hook.
 */
function mhbo_deactivate(): void
{
    $deactivator_file = MHBO_PLUGIN_DIR . 'includes/Core/Deactivator.php';
    if (file_exists($deactivator_file)) {
        require_once $deactivator_file;
        if (class_exists('MHBO\Core\Deactivator')) {
            \MHBO\Core\Deactivator::deactivate();
        }
    }
}

register_activation_hook(__FILE__, 'mhbo_activate');
register_deactivation_hook(__FILE__, 'mhbo_deactivate');

/**
 * Boot the plugin.
 */
function mhbo_run(): void
{
    try {
        if (!class_exists('MHBO\Core\Plugin')) {
            return;
        }

        // Auto-upgrade DB schema when version changes.
        $stored = get_option('mhbo_db_version');
        if (MHBO_VERSION !== $stored) {
            if (class_exists('MHBO\Core\Activator')) {
                if (false !== $stored) {
                    \MHBO\Core\Activator::migrate(is_string($stored) ? $stored : (string) $stored, MHBO_VERSION);
                } else {
                    \MHBO\Core\Activator::activate();
                }
            }
            update_option('mhbo_db_version', MHBO_VERSION);
        }

        $plugin = new \MHBO\Core\Plugin();
        $plugin->run();
    } catch (Throwable $e) {
        if (WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Modern Hotel Booking Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }

        if (current_user_can('manage_options')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error">';
                echo '<p><strong>' . esc_html__('Modern Hotel Booking Error', 'modern-hotel-booking') . '</strong></p>';
                echo '<p>' . esc_html__('An error occurred while loading the plugin. Please check the debug log for details.', 'modern-hotel-booking') . '</p>';
                echo '</div>';
            });
        }
    }
}

add_action('plugins_loaded', 'mhbo_run');