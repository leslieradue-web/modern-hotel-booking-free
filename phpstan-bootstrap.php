<?php
/**
 * PHPStan Bootstrap File
 * Defines constants and environment for static analysis.
 */

define('MHBO_VERSION', '2.2.6.8');
define('MHBO_PLUGIN_DIR', __DIR__ . '/');
define('MHBO_PLUGIN_URL', 'https://example.com/wp-content/plugins/modern-hotel-booking/');
define('MHBO_PLUGIN_BASENAME', 'modern-hotel-booking/modern-hotel-booking.php');

// Ensure ABSPATH is defined if not already by the extension
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
