<?php

namespace WP_Hreflang\Admin;

use WP_Hreflang\Helpers;

class Network_Settings
{
    private $option_name = 'wp_hreflang_network_settings';
    private $archive_pages_option = 'wp_hreflang_archive_pages';

    public function init()
    {
        add_action('network_admin_menu', array($this, 'add_network_settings_page'));
        add_action('network_admin_edit_wp_hreflang_update_network_settings', array($this, 'update_network_settings'));
        add_action('admin_notices', array($this, 'display_duplicate_locale_notice'));
        add_action('network_admin_notices', array($this, 'display_duplicate_locale_notice'));

        add_action('update_site_option_' . $this->option_name, array($this, 'check_duplicate_locales'));
        add_action('update_option_WPLANG', array($this, 'check_duplicate_locales'));
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
            <form method="post" action="edit.php?action=wp_hreflang_update_network_settings">
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

                <?php submit_button(); ?>
            </form>
        </div>

        <style>
            .wp-hreflang-section {
                margin: 2em 0;
                padding: 1.5em;
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
            }

            .wp-hreflang-section h2 {
                margin-top: 0;
                padding-bottom: 0.5em;
                border-bottom: 1px solid #eee;
            }

            .locale-row {
                display: flex;
                gap: 1em;
                align-items: center;
                margin-bottom: 1em;
            }

            .site-select {
                min-width: 250px;
            }

            .locale-input {
                width: 150px;
            }

            #add-locale {
                margin: 1em 0;
            }

            .post-types-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 1em;
                margin-top: 1em;
            }

            .post-type-label {
                display: flex;
                align-items: center;
                gap: 0.5em;
            }

            #wp-hreflang-locales {
                margin-top: 1em;
            }

            .archive-page-row {
                margin-bottom: 10px;
                display: flex;
                gap: 10px;
            }

            .archive-name-input {
                width: 250px;
            }

            .archive-id-input {
                width: 200px;
            }

            #wp-hreflang-archive-pages {
                margin-bottom: 15px;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                $('#add-locale').on('click', function() {
                    var sites = <?php echo json_encode(array_map(function ($site) {
                                    return [
                                        'id' => $site->blog_id,
                                        'name' => get_blog_details($site->blog_id)->blogname
                                    ];
                                }, $sites)); ?>;

                    var siteOptions = sites.map(function(site) {
                        return '<option value="' + site.id + '">' + site.name + '</option>';
                    }).join('');

                    var row = $('<div class="locale-row">' +
                        '<select name="locales[site_id][]" class="site-select">' +
                        '<option value=""><?php _e('Select a site', 'wp-hreflang'); ?></option>' +
                        siteOptions +
                        '</select>' +
                        '<input type="text" name="locales[locale][]" value="" class="locale-input" placeholder="<?php esc_attr_e('Locale (e.g., en-US)', 'wp-hreflang'); ?>" />' +
                        '<button type="button" class="remove-locale button"><?php _e('Remove', 'wp-hreflang'); ?></button>' +
                        '</div>');
                    $('#wp-hreflang-locales').append(row);
                });

                $(document).on('click', '.remove-locale', function() {
                    $(this).closest('.locale-row').remove();
                });

                // Archive pages handling
                $('#add-archive-page').on('click', function() {
                    var template = `
                        <div class="archive-page-row">
                            <input type="text"
                                name="archive_pages[name][]"
                                class="archive-name-input"
                                placeholder="<?php esc_attr_e('Archive Name', 'wp-hreflang'); ?>" />
                            <input type="text"
                                name="archive_pages[id][]"
                                class="archive-id-input"
                                placeholder="<?php esc_attr_e('Archive ID (optional)', 'wp-hreflang'); ?>" />
                            <button type="button" class="remove-archive button"><?php _e('Remove', 'wp-hreflang'); ?></button>
                        </div>
                    `;
                    $('#wp-hreflang-archive-pages').append(template);
                });

                $(document).on('click', '.remove-archive', function() {
                    $(this).closest('.archive-page-row').remove();
                });
                // Auto-generate ID from name on blur
                $(document).on('blur', '.archive-name-input', function() {
                    var idInput = $(this).siblings('.archive-id-input');
                    if (idInput.val() === '') {
                        idInput.val($(this).val().toLowerCase()
                            .replace(/[^a-z0-9]+/g, '-')
                            .replace(/^-+|-+$/g, ''));
                    }
                });
            });
        </script>
<?php
    }

    public function get_archive_pages()
    {
        return get_site_option($this->archive_pages_option, array());
    }
}
