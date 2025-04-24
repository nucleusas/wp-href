<?php

namespace WP_Hreflang\Admin;

use WP_Hreflang\Helpers;
use WP_Hreflang\Assets;

class Network_Settings
{
    private $option_name = 'wp_hreflang_network_settings';
    private $archive_pages_option = 'wp_hreflang_archive_pages';

    public function init()
    {
        add_action('network_admin_menu', array($this, 'add_network_settings_page'));
        add_action('admin_notices', array($this, 'display_duplicate_locale_notice'));
        add_action('network_admin_notices', array($this, 'display_duplicate_locale_notice'));

        add_action('update_site_option_' . $this->option_name, array($this, 'check_duplicate_locales'));
        add_action('update_option_WPLANG', array($this, 'check_duplicate_locales'));
        
        // Add admin scripts for the settings page
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function enqueue_admin_scripts($hook)
    {
        if ($hook !== 'settings_page_wp-hreflang-settings') {
            return;
        }
        
        // Enqueue the admin settings script
        Assets::enqueue_script(
            'wp-hreflang-admin-settings',
            'admin-settings',
            ['jquery'],
            true
        );
        
        // Get sites for the locales dropdown
        $sites = array_map(function($site) {
            return [
                'id' => $site->blog_id,
                'name' => get_blog_details($site->blog_id)->blogname
            ];
        }, get_sites());
        
        wp_localize_script('wp-hreflang-admin-settings', 'wpHreflangAdminSettings', [
            'nonce' => wp_create_nonce('wp_hreflang_network_settings'),
            'api' => [
                'root' => esc_url_raw(rest_url()),
                'nonce' => wp_create_nonce('wp_rest'),
            ],
            'sites' => $sites,
            'i18n' => [
                'processing' => __('Processing...', 'wp-hreflang'),
                'save_settings' => __('Save Settings', 'wp-hreflang'),
                'error' => __('Error', 'wp-hreflang'),
                'success' => __('Settings saved successfully', 'wp-hreflang'),
                'rebuild_title' => __('Rebuilding Hreflang Maps', 'wp-hreflang'),
                'rebuild_desc' => __('This process may take several minutes for large sites.', 'wp-hreflang'),
                'site_progress' => __('Site Progress:', 'wp-hreflang'),
                'post_progress' => __('Post Progress (Current Site):', 'wp-hreflang'),
                'complete' => __('Complete!', 'wp-hreflang'),
                'cancel' => __('Cancel', 'wp-hreflang'),
                'close' => __('Close', 'wp-hreflang'),
                'start_rebuild' => __('Start Rebuild', 'wp-hreflang'),
                'select_site' => __('Select a site', 'wp-hreflang'),
                'locale_placeholder' => __('Locale (e.g., en-US)', 'wp-hreflang'),
                'archive_name' => __('Archive Name', 'wp-hreflang'),
                'archive_id' => __('Archive ID (optional)', 'wp-hreflang'),
                'remove' => __('Remove', 'wp-hreflang')
            ]
        ]);
        
        // Enqueue the admin settings styles
        Assets::enqueue_style(
            'wp-hreflang-admin-settings',
            'admin-settings',
            []
        );
    }

    public function add_network_settings_page()
    {
        add_submenu_page(
            'settings.php',
            __('Hreflang', 'wp-hreflang'),
            __('Hreflang', 'wp-hreflang'),
            'manage_network_options',
            'wp-hreflang-settings',
            array($this, 'render_settings_page')
        );
    }

    public function update_network_settings()
    {
        check_admin_referer('wp_hreflang_network_settings');

        if (!current_user_can('manage_network_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $site_ids = $_POST['locales']['site_id'] ?? array();
        $locales = $_POST['locales']['locale'] ?? array();

        $locale_map = array();
        foreach ($site_ids as $index => $site_id) {
            if (!empty($site_id) && !empty($locales[$index])) {
                $locale_map[$site_id] = Helpers::format_locale_key(sanitize_text_field($locales[$index]));
            }
        }

        $settings = array(
            'locales' => $locale_map,
            'post_types' => array_filter(array_map('sanitize_text_field', $_POST['post_types'] ?? array())),
            'ignore_query_params' => isset($_POST['ignore_query_params']) ? 1 : 0
        );

        update_site_option($this->option_name, $settings);

        // Handle archive pages
        $archive_pages = array();
        $archive_names = $_POST['archive_pages']['name'] ?? array();
        $archive_ids = $_POST['archive_pages']['id'] ?? array();

        foreach ($archive_names as $index => $name) {
            if (!empty($name)) {
                $id = !empty($archive_ids[$index]) ? sanitize_title($archive_ids[$index]) : sanitize_title($name);
                $archive_pages[] = array(
                    'id' => $id,
                    'name' => sanitize_text_field($name)
                );
            }
        }

        update_site_option($this->archive_pages_option, $archive_pages);
        
        Helpers::rebuild_hreflang_maps();

        wp_redirect(add_query_arg(array(
            'page' => 'wp-hreflang-settings',
            'updated' => 'true'
        ), network_admin_url('settings.php')));
        exit;
    }

    public function check_duplicate_locales()
    {
        $all_locales = array();
        $duplicates = array();
        $sites = get_sites();

        foreach ($sites as $site) {
            $site_id = $site->blog_id;
            $locale = Helpers::get_site_locale('key', $site_id);

            if (isset($all_locales[$locale])) {
                if (!isset($duplicates[$locale])) {
                    $duplicates[$locale] = array($all_locales[$locale]);
                }
                $duplicates[$locale][] = $site_id;
            }

            $all_locales[$locale] = $site_id;
        }

        if (!empty($duplicates)) {
            update_site_option('wp_hreflang_duplicate_locales', $duplicates);
        } else {
            delete_site_option('wp_hreflang_duplicate_locales');
        }
    }

    public function display_duplicate_locale_notice()
    {
        $duplicates = get_site_option('wp_hreflang_duplicate_locales', array());

        if (empty($duplicates)) {
            return;
        }

        $message = '';

        if (current_user_can('manage_network_options')) {
            // Message for network admins
            $message = '<div class="notice notice-warning is-dismissible"><p>';
            $message .= __('Warning: The following locales are used by multiple sites:', 'wp-hreflang') . '</p><ul>';

            foreach ($duplicates as $locale => $site_ids) {
                $site_names = array_map(function ($site_id) {
                    return get_blog_details($site_id)->blogname;
                }, $site_ids);

                $message .= sprintf(
                    '<li>' . __('Locale "%1$s" is used by: %2$s', 'wp-hreflang') . '</li>',
                    esc_html($locale),
                    esc_html(implode(', ', $site_names))
                );
            }

            $message .= '</ul><p>';
            $message .= sprintf(
                __('Please <a href="%1$s">update the hreflang settings</a> or change the WordPress locale for the affected sites.', 'wp-hreflang'),
                network_admin_url('settings.php?page=wp-hreflang-settings')
            );
            $message .= '</p></div>';
        } else {
            // Message for sites
            $current_site_id = get_current_blog_id();
            $affected_site = false;

            // Check if current site is affected
            foreach ($duplicates as $locale => $site_ids) {
                if (in_array($current_site_id, $site_ids)) {
                    $affected_site = true;
                    break;
                }
            }

            if ($affected_site) {
                $message = '<div class="notice notice-warning is-dismissible"><p>';
                $message .= __('Warning: This site uses a locale that conflicts with another site in the network. Please contact your network administrator to resolve this issue.', 'wp-hreflang');
                $message .= '</p></div>';
            } else {
                return;
            }
        }

        echo $message;
    }

    public function render_settings_page()
    {
        $settings = get_site_option($this->option_name, array(
            'locales' => array(),
            'post_types' => array('post', 'page'),
            'ignore_query_params' => 0
        ));

        $archive_pages = get_site_option($this->archive_pages_option, array());
        $sites = get_sites();
?>
        <div class="wrap">
            <h1><?php _e('Hreflang Network Settings', 'wp-hreflang'); ?></h1>
            <div id="wp-hreflang-settings-message" class="notice" style="display: none;"></div>
            
            <form id="wp-hreflang-settings-form" method="post">
                <?php wp_nonce_field('wp_hreflang_network_settings'); ?>

                <div class="wp-hreflang-section">
                    <h2><?php _e('Locales', 'wp-hreflang'); ?></h2>
                    <p class="description"><?php _e('Override locale used for hreflang tags for individual sites (e.g., en-SG, en-GB). This does not affect the WordPress locale.', 'wp-hreflang'); ?></p>

                    <div id="wp-hreflang-locales">
                        <?php foreach ($settings['locales'] as $site_id => $locale): ?>
                            <div class="locale-row">
                                <select name="locales[site_id][]" class="site-select">
                                    <option value=""><?php _e('Select a site', 'wp-hreflang'); ?></option>
                                    <?php foreach ($sites as $site): ?>
                                        <option value="<?php echo esc_attr($site->blog_id); ?>"
                                            <?php selected($site->blog_id, $site_id); ?>>
                                            <?php echo esc_html(get_blog_details($site->blog_id)->blogname); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text"
                                    name="locales[locale][]"
                                    value="<?php echo esc_attr($locale); ?>"
                                    class="locale-input"
                                    placeholder="<?php esc_attr_e('Locale (e.g., en-US)', 'wp-hreflang'); ?>" />
                                <button type="button" class="remove-locale button"><?php _e('Remove', 'wp-hreflang'); ?></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="add-locale" class="button"><?php _e('Add Locale', 'wp-hreflang'); ?></button>
                </div>

                <div class="wp-hreflang-section">
                    <h2><?php _e('Archive Pages', 'wp-hreflang'); ?></h2>
                    <p class="description"><?php _e('Define archive pages that can be linked between sites.', 'wp-hreflang'); ?></p>

                    <div id="wp-hreflang-archive-pages">
                        <?php foreach ($archive_pages as $page): ?>
                            <div class="archive-page-row">
                                <input type="text"
                                    name="archive_pages[name][]"
                                    value="<?php echo esc_attr($page['name']); ?>"
                                    class="archive-name-input"
                                    placeholder="<?php esc_attr_e('Archive Name', 'wp-hreflang'); ?>" />
                                <input type="text"
                                    name="archive_pages[id][]"
                                    value="<?php echo esc_attr($page['id']); ?>"
                                    class="archive-id-input"
                                    placeholder="<?php esc_attr_e('Archive ID (optional)', 'wp-hreflang'); ?>" />
                                <button type="button" class="remove-archive button"><?php _e('Remove', 'wp-hreflang'); ?></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="add-archive-page" class="button"><?php _e('Add Archive Page', 'wp-hreflang'); ?></button>
                </div>

                <div class="wp-hreflang-section">
                    <h2><?php _e('Post Types', 'wp-hreflang'); ?></h2>
                    <p class="description"><?php _e('Select which post types should support hreflang tags.', 'wp-hreflang'); ?></p>

                    <div class="post-types-grid">
                        <?php
                        $post_types = get_post_types(array('public' => true), 'objects');
                        foreach ($post_types as $post_type): ?>
                            <label class="post-type-label">
                                <input type="checkbox"
                                    name="post_types[]"
                                    value="<?php echo esc_attr($post_type->name); ?>"
                                    <?php checked(in_array($post_type->name, $settings['post_types'])); ?>>
                                <?php echo esc_html($post_type->label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="wp-hreflang-section">
                    <h2><?php _e('Advanced Options', 'wp-hreflang'); ?></h2>
                    
                    <p>
                        <label>
                            <input type="checkbox" 
                                name="ignore_query_params" 
                                value="1" 
                                <?php checked($settings['ignore_query_params'], 1); ?>>
                            <?php _e('Ignore hreflang tags on URLs with query parameters', 'wp-hreflang'); ?>
                        </label>
                        <p class="description"><?php _e('When enabled, hreflang tags will not be output on pages that have query parameters in the URL (e.g., ?param=value).', 'wp-hreflang'); ?></p>
                    </p>
                </div>

                <div class="wp-hreflang-buttons">
                    <button type="submit" id="save-settings-button" class="button button-primary"><?php _e('Save Settings', 'wp-hreflang'); ?></button>
                </div>
            </form>
            
            <!-- Progress Modal -->
            <div id="wp-hreflang-progress-modal" class="wp-hreflang-modal" style="display: none;">
                <div class="wp-hreflang-modal-content">
                    <h2 id="wp-hreflang-progress-title"></h2>
                    <p id="wp-hreflang-progress-desc"></p>
                    
                    <div class="wp-hreflang-progress-section">
                        <h3 id="wp-hreflang-site-progress-label"></h3>
                        <div class="wp-hreflang-progress-text">
                            <span id="wp-hreflang-current-site">0</span> / <span id="wp-hreflang-total-sites">0</span>
                        </div>
                        <div class="wp-hreflang-progress-bar">
                            <div id="wp-hreflang-site-progress" class="wp-hreflang-progress-bar-inner"></div>
                        </div>
                    </div>
                    
                    <div class="wp-hreflang-progress-section">
                        <h3 id="wp-hreflang-post-progress-label"></h3>
                        <div class="wp-hreflang-progress-text">
                            <span id="wp-hreflang-current-post">0</span> / <span id="wp-hreflang-total-posts">0</span>
                        </div>
                        <div class="wp-hreflang-progress-bar">
                            <div id="wp-hreflang-post-progress" class="wp-hreflang-progress-bar-inner"></div>
                        </div>
                    </div>
                    
                    <div class="wp-hreflang-modal-buttons">
                        <button id="wp-hreflang-cancel-button" class="button"><?php _e('Cancel', 'wp-hreflang'); ?></button>
                        <button id="wp-hreflang-start-button" class="button button-primary"><?php _e('Start Rebuild', 'wp-hreflang'); ?></button>
                        <button id="wp-hreflang-close-button" class="button button-primary" style="display: none;"><?php _e('Close', 'wp-hreflang'); ?></button>
                    </div>
                </div>
            </div>
        </div>
<?php
    }

    public function get_archive_pages()
    {
        return get_site_option($this->archive_pages_option, array());
    }
}
