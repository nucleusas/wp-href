<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$sites = get_sites();

foreach ($sites as $site) {
    switch_to_blog($site->blog_id);

    delete_metadata('post', 0, 'hreflang_relation', '', true);

    if (is_main_site()) {
        delete_metadata('post', 0, 'hreflang_map', '', true);
    }

    restore_current_blog();
}

delete_site_option('wp_hreflang_network_settings');
delete_site_option('wp_hreflang_duplicate_locales');
