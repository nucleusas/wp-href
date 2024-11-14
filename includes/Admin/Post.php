<?php

namespace WP_Hreflang\Admin;

use WP_Hreflang\Helpers;

class Post
{
    public function init()
    {
        // Stores the ID of the corresponding post from the main site
        // This creates a relationship between translated content across the network
        register_post_meta('', 'hreflang_relation', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'integer',
        ));

        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        add_action('add_post_meta', [$this, 'handle_add_meta'], 10, 3);
        add_action('update_post_meta', [$this, 'handle_update_meta'], 10, 4);
        add_action('delete_post_meta', [$this, 'handle_delete_meta'], 10, 3);
        add_action('wp_after_insert_post', [$this, 'handle_post_update'], 10, 3);
        add_action('wp_trash_post', [$this, 'handle_post_trash'], 10, 1);
        add_action('before_delete_post', [$this, 'handle_post_deletion'], 10, 1);
    }

    public function enqueue_scripts($hook)
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        // Only visible on sub sites
        if (is_main_site()) {
            return;
        }

        // Only visible on selected post types, defaults to post and page
        $settings = get_site_option('wp_hreflang_network_settings', array(
            'post_types' => array('post', 'page')
        ));

        $screen = get_current_screen();
        if (!in_array($screen->post_type, $settings['post_types'])) {
            return;
        }

        wp_enqueue_script(
            'wp-hreflang-admin',
            WP_HREFLANG_PLUGIN_URL . 'dist/js/admin.js',
            array(
                'wp-plugins',
                'wp-element',
                'wp-components',
                'wp-data',
                'wp-i18n',
                'wp-editor'
            ),
            WP_HREFLANG_VERSION,
            true
        );

        wp_localize_script('wp-hreflang-admin', 'wpHreflangSettings', [
            'nonce' => wp_create_nonce('wp_rest'),
            'root' => esc_url_raw(rest_url()) . 'wp-hreflang/v1',
        ]);
    }

    private function add_locale_to_hreflang_map($main_site_post_id, $permalink)
    {
        $locale = Helpers::get_site_locale('key');

        switch_to_blog(get_main_site_id());
        $hreflangMap = get_post_meta($main_site_post_id, 'hreflang_map', true) ?: [];
        $hreflangMap[$locale] = $permalink;
        update_post_meta($main_site_post_id, 'hreflang_map', $hreflangMap);
        restore_current_blog();
    }

    private function remove_locale_from_hreflang_map($main_site_post_id)
    {
        $locale = Helpers::get_site_locale('key');

        switch_to_blog(get_main_site_id());
        $hreflangMap = get_post_meta($main_site_post_id, 'hreflang_map', true) ?: [];
        unset($hreflangMap[$locale]);
        update_post_meta($main_site_post_id, 'hreflang_map', $hreflangMap);
        restore_current_blog();
    }

    public function handle_add_meta($post_id, $meta_key, $meta_value)
    {
        if ($meta_key !== 'hreflang_relation') {
            return;
        }

        $permalink = get_permalink($post_id);

        $this->add_locale_to_hreflang_map($meta_value, $permalink);
    }

    public function handle_update_meta($meta_id, $post_id, $meta_key, $meta_value)
    {
        if ($meta_key !== 'hreflang_relation') {
            return;
        }

        $permalink = get_permalink($post_id);

        $old_value = get_post_meta($post_id, 'hreflang_relation', true);
        if ($old_value) {
            $this->remove_locale_from_hreflang_map($old_value);
        }

        $this->add_locale_to_hreflang_map($meta_value, $permalink);
    }

    public function handle_delete_meta($meta_id, $post_id, $meta_key)
    {
        if ($meta_key !== 'hreflang_relation') {
            return;
        }

        $old_value = get_post_meta($post_id, 'hreflang_relation', true);
        if (!$old_value) {
            return;
        }

        $this->remove_locale_from_hreflang_map($old_value);
    }

    public function handle_delete_post($post_id)
    {
        $hreflang_relation = get_post_meta($post_id, 'hreflang_relation', true);
        if (!empty($hreflang_relation)) {
            $this->remove_locale_from_hreflang_map($hreflang_relation);
        }
    }

    public function handle_post_update($post_id, $post, $update)
    {
        $hreflang_relation = get_post_meta($post_id, 'hreflang_relation', true);
        if (empty($hreflang_relation)) {
            return;
        }

        if ($post->post_status === 'publish') {
            $permalink = get_permalink($post_id);
            $this->add_locale_to_hreflang_map($hreflang_relation, $permalink);
        } else {
            $this->remove_locale_from_hreflang_map($hreflang_relation);
        }
    }

    public function handle_post_trash($post_id)
    {
        $hreflang_relation = get_post_meta($post_id, 'hreflang_relation', true);
        if (!empty($hreflang_relation)) {
            $this->remove_locale_from_hreflang_map($hreflang_relation);
        }
    }

    public function handle_post_deletion($post_id)
    {
        $hreflang_relation = get_post_meta($post_id, 'hreflang_relation', true);
        if (!empty($hreflang_relation)) {
            $this->remove_locale_from_hreflang_map($hreflang_relation);
        }
    }
}
