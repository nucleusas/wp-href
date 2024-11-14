<?php

namespace WP_Hreflang\Admin;

use WP_Hreflang\Helpers;

class Network_Settings
{
    private $option_name = 'wp_hreflang_network_settings';

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
            'post_types' => array_filter(array_map('sanitize_text_field', $_POST['post_types'] ?? array()))
        );

        update_site_option($this->option_name, $settings);
        $this->update_all_site_hreflangs();

        wp_redirect(add_query_arg(array(
            'page' => 'wp-hreflang-settings',
            'updated' => 'true'
        ), network_admin_url('settings.php')));
        exit;
    }

    private function update_all_site_hreflangs()
    {
        // Rebuild all hreflang maps whenever locales change
        $main_site_id = get_main_site_id();

        switch_to_blog($main_site_id);
        delete_post_meta_by_key('hreflang_map');
        restore_current_blog();

        $sites = array_filter(get_sites(), function ($site) use ($main_site_id) {
            return $site->blog_id !== $main_site_id;
        });

        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            $locale = Helpers::get_site_locale('key');

            $posts_with_relations = get_posts(array(
                'post_type' => 'any',
                'posts_per_page' => -1,
                'meta_key' => 'hreflang_relation',
                'meta_query' => array(
                    array(
                        'key' => 'hreflang_relation',
                        'compare' => 'EXISTS'
                    )
                )
            ));

            foreach ($posts_with_relations as $post) {
                $main_site_post_id = get_post_meta($post->ID, 'hreflang_relation', true);
                $current_permalink = get_permalink($post->ID);

                switch_to_blog($main_site_id);
                $hreflang_map = get_post_meta($main_site_post_id, 'hreflang_map', true) ?: [];
                $hreflang_map[$locale] = $current_permalink;
                update_post_meta($main_site_post_id, 'hreflang_map', $hreflang_map);
                restore_current_blog();
            }

            restore_current_blog();
        }
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
            'post_types' => array('post', 'page')
        ));

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
            });
        </script>
<?php
    }
}
