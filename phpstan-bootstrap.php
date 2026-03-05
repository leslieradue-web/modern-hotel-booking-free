<?php
/**
 * PHPStan Bootstrap File
 * Defines constants and environment for static analysis.
 */

define('MHB_VERSION', '2.2.6.0');
define('MHB_PLUGIN_DIR', __DIR__ . '/');
define('MHB_PLUGIN_URL', 'https://example.com/wp-content/plugins/modern-hotel-booking/');
define('MHB_PLUGIN_BASENAME', 'modern-hotel-booking/modern-hotel-booking.php');

// Ensure ABSPATH is defined if not already by the extension
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
