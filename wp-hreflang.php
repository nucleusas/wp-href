<?php

/**
 * Plugin Name: WP Hreflang
 * Description: Manage hreflang tags across a WordPress multisite network, using the main site as a source of truth.
 * Version: 1.0.0
 * Author: Nucleus AS
 * License: GPLv2 or later
 * Text Domain: wp-hreflang
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('is_plugin_active_for_network')) {
    require_once(ABSPATH . '/wp-admin/includes/plugin.php');
}

register_activation_hook(__FILE__, function () {
    if (!is_multisite()) {
        wp_die(__('This plugin requires WordPress Multisite.', 'wp-hreflang'));
    }

    if (!is_network_admin()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin can only be activated network-wide.', 'wp-hreflang'));
    }

    // Check for duplicate locales on activation
    require_once WP_HREFLANG_PLUGIN_DIR . 'includes/Admin/Network_Settings.php';
    $network_settings = new WP_Hreflang\Admin\Network_Settings();
    $network_settings->check_duplicate_locales();
});

// Define plugin constants
define('WP_HREFLANG_VERSION', '1.0.0');
define('WP_HREFLANG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_HREFLANG_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'WP_Hreflang\\';
    $base_dir = WP_HREFLANG_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

if (class_exists('WP_Hreflang\\Main')) {
    $wp_hreflang = new WP_Hreflang\Main();
    $wp_hreflang->init();
}
