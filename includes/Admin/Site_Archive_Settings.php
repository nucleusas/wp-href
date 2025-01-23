<?php

namespace WP_Hreflang\Admin;

use WP_Hreflang\Helpers;

class Site_Archive_Settings
{
    private $site_archives_paths_option = 'wp_hreflang_site_archive_paths';
    private $network_archives_option = 'wp_hreflang_archive_pages';
    private $network_archives_map_option = 'wp_hreflang_network_archive_map';

    public function __construct()
    {
    }

    public function init()
    {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_post_wp_hreflang_update_site_archive_settings', array($this, 'update_settings'));
    }

    public function add_settings_page()
    {
        $archive_pages = get_site_option($this->network_archives_option, array());
        
        if (empty($archive_pages)) {
            return;
        }

        add_submenu_page(
            'options-general.php',
            __('Hreflang Archive Pages', 'wp-hreflang'),
            __('Hreflang Archives', 'wp-hreflang'),
            'manage_options',
            'wp-hreflang-archives',
            array($this, 'render_settings_page')
        );
    }

    public function update_settings()
    {
        check_admin_referer('wp_hreflang_site_archive_settings');

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $paths = array();
        $archive_paths = $_POST['archive_paths'] ?? array();
        $current_site_id = get_current_blog_id();

        foreach ($archive_paths as $id => $path) {
            if (!empty($path)) {
                $path = '/' . trim(sanitize_text_field($path), '/') . '/';
                $paths[$id] = $path;
            }
        }

        // Save local lookup paths
        $lookup_paths = array();
        foreach ($paths as $archive_id => $path) {
            $lookup_paths[$path] = $archive_id;
        }
        update_option($this->site_archives_paths_option, $lookup_paths);

        // Update network-wide mappings
        $this->update_network_mappings($current_site_id, $paths);

        wp_redirect(add_query_arg(array(
            'page' => 'wp-hreflang-archives',
            'updated' => 'true'
        ), admin_url('options-general.php')));
        exit;
    }

    private function update_network_mappings($current_site_id, $new_paths)
    {
        $main_site_id = get_main_site_id();
        $current_locale = Helpers::get_site_locale('key');

        switch_to_blog($main_site_id);

        $network_mappings = get_site_option($this->network_archives_map_option, array());

        // Remove all old paths for current site
        foreach ($network_mappings as $archive_id => $paths) {
            foreach ($paths as $locale => $path) {
                if ($locale === $current_locale) {
                    unset($network_mappings[$archive_id][$locale]);
                }
            }
        }

        // Add new paths with full URLs
        foreach ($new_paths as $archive_id => $path) {
            if (!isset($network_mappings[$archive_id])) {
                $network_mappings[$archive_id] = array();
            }
            $url = get_site_url($current_site_id, $path);
            $network_mappings[$archive_id][$current_locale] = $url;
        }

        // Clean up empty entries
        $network_mappings = array_filter($network_mappings, function($paths) {
            return !empty($paths);
        });

        update_site_option($this->network_archives_map_option, $network_mappings);
        restore_current_blog();
    }

    public function render_settings_page()
    {
        $archive_pages = get_site_option($this->network_archives_option, array());
        $current_paths = array();
        
        // Get paths from network mappings for current site
        $current_locale = Helpers::get_site_locale('key');
        $network_mappings = get_site_option($this->network_archives_map_option, array());
        $site_url = get_site_url();
        
        foreach ($network_mappings as $archive_id => $paths) {
            if (isset($paths[$current_locale])) {
                // Convert full URL back to relative path without leading slash
                $full_url = $paths[$current_locale];
                $path = ltrim(str_replace($site_url . '/', '', $full_url), '/');
                $current_paths[$archive_id] = $path;
            }
        }

        $site_prefix = rtrim(get_blog_details()->path, '/') . '/';
?>
        <div class="wrap">
            <h1><?php _e('Hreflang Archive Pages Settings', 'wp-hreflang'); ?></h1>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="wp_hreflang_update_site_archive_settings">
                <?php wp_nonce_field('wp_hreflang_site_archive_settings'); ?>

                <table class="form-table">
                    <tbody>
                        <?php foreach ($archive_pages as $page): ?>
                            <tr>
                                <th scope="row">
                                    <label for="archive-path-<?php echo esc_attr($page['id']); ?>">
                                        <?php echo esc_html($page['name']); ?>
                                    </label>
                                </th>
                                <td>
                                    <div class="archive-path-input-wrapper">
                                        <span class="site-prefix"><?php echo esc_html($site_prefix); ?></span>
                                        <input type="text"
                                            name="archive_paths[<?php echo esc_attr($page['id']); ?>]"
                                            id="archive-path-<?php echo esc_attr($page['id']); ?>"
                                            value="<?php echo esc_attr($current_paths[$page['id']] ?? ''); ?>"
                                            class="regular-text archive-path-input"
                                            placeholder="<?php esc_attr_e('e.g. news or news/page/*', 'wp-hreflang'); ?>" />
                                    </div>
                                    <p class="description">
                                        <?php _e('Enter the relative path for this archive page. You can use * for dynamic segments.', 'wp-hreflang'); ?>
                                    </p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>

        <style>
        .archive-path-input-wrapper {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .site-prefix {
            color: #666;
            padding: 5px;
            background: #f0f0f1;
            border: 1px solid #8c8f94;
            border-radius: 4px;
        }
        </style>
<?php
    }
} 