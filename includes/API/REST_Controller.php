<?php

namespace WP_Hreflang\API;

class REST_Controller
{
    public function init()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        register_rest_route('wp-hreflang/v1', '/search', array(
            'methods' => 'GET',
            'callback' => array($this, 'search_main_site'),
            'permission_callback' => array($this, 'check_permissions'),
        ));

        register_rest_route('wp-hreflang/v1', '/main-site-post/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_main_site_post'),
            'permission_callback' => array($this, 'check_permissions'),
        ));

        register_rest_route('wp-hreflang/v1', '/get-permalink/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_post_permalink'),
            'permission_callback' => '__return_true', // Allow public access for cross-site requests
        ));

        register_rest_route('wp-hreflang/v1', '/update-settings', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_settings'),
            'permission_callback' => array($this, 'check_admin_permissions'),
        ));
    }

    public function search_main_site($request)
    {
        try {
            $search_query = $request->get_param('query');

            switch_to_blog(get_main_site_id());

            $args = array(
                's' => $search_query,
                'post_type' => 'any',
                'post_status' => 'publish',
                'posts_per_page' => 10,
            );

            $query = new \WP_Query($args);
            $results = array();

            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $results[] = array(
                        'id' => get_the_ID(),
                        'title' => get_the_title(),
                    );
                }
            }

            restore_current_blog();
            wp_reset_postdata();

            return rest_ensure_response($results);
        } catch (\Exception $e) {
            return new \WP_Error(
                'rest_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    public function get_main_site_post($request)
    {
        try {
            switch_to_blog(get_main_site_id());
            $post = get_post($request->get_param('id'));
            restore_current_blog();

            if (!$post) {
                return new \WP_Error(
                    'rest_error',
                    'Post not found',
                    array('status' => 404)
                );
            }

            return [
                'id' => $post->ID,
                'title' => $post->post_title,
            ];
        } catch (\Exception $e) {
            return new \WP_Error(
                'rest_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    public function get_post_permalink($request)
    {
        try {
            $post_id = $request->get_param('id');
            $post = get_post($post_id);
            
            if (!$post || $post->post_status !== 'publish') {
                return new \WP_Error(
                    'rest_error',
                    'Post not found or not published',
                    array('status' => 404)
                );
            }

            return rest_ensure_response([
                'permalink' => get_permalink($post_id)
            ]);
        } catch (\Exception $e) {
            return new \WP_Error(
                'rest_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    public function check_permissions()
    {
        return current_user_can('edit_posts');
    }

    public function check_admin_permissions()
    {
        return current_user_can('manage_network_options');
    }

    public function update_settings($request)
    {
        try {
            $params = $request->get_json_params();
            
            if (!isset($params['nonce']) || !wp_verify_nonce($params['nonce'], 'wp_hreflang_network_settings')) {
                return new \WP_Error(
                    'invalid_nonce',
                    'Invalid nonce',
                    array('status' => 403)
                );
            }
            
            $site_ids = $params['locales']['site_id'] ?? array();
            $locales = $params['locales']['locale'] ?? array();
            
            $locale_map = array();
            foreach ($site_ids as $index => $site_id) {
                if (!empty($site_id) && !empty($locales[$index])) {
                    $locale_map[$site_id] = \WP_Hreflang\Helpers::format_locale_key(sanitize_text_field($locales[$index]));
                }
            }
            
            $settings = array(
                'locales' => $locale_map,
                'post_types' => array_filter(array_map('sanitize_text_field', $params['post_types'] ?? array())),
                'ignore_query_params' => isset($params['ignore_query_params']) ? 1 : 0
            );
            
            update_site_option('wp_hreflang_network_settings', $settings);
            
            // Handle archive pages
            $archive_pages = array();
            $archive_names = $params['archive_pages']['name'] ?? array();
            $archive_ids = $params['archive_pages']['id'] ?? array();
            
            foreach ($archive_names as $index => $name) {
                if (!empty($name)) {
                    $id = !empty($archive_ids[$index]) ? sanitize_title($archive_ids[$index]) : sanitize_title($name);
                    $archive_pages[] = array(
                        'id' => $id,
                        'name' => sanitize_text_field($name)
                    );
                }
            }
            
            update_site_option('wp_hreflang_archive_pages', $archive_pages);
            
            // Trigger duplicate locales check
            do_action('update_site_option_wp_hreflang_network_settings', $settings, []);
            
            return rest_ensure_response([
                'success' => true,
                'message' => 'Settings updated successfully',
            ]);
        } catch (\Exception $e) {
            return new \WP_Error(
                'rest_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
}
