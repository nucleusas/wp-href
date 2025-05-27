<?php

namespace WP_Hreflang\API;

use WP_Hreflang\Helpers;

class REST_Debug
{
    public function init()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        register_rest_route('wp-hreflang/v1', '/debug-main-site', array(
            'methods' => 'GET',
            'callback' => array($this, 'debug_main_site'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('wp-hreflang/v1', '/test-permalink-fetch', array(
            'methods' => 'GET',
            'callback' => array($this, 'test_permalink_fetch'),
            'permission_callback' => '__return_true'
        ));
    }

    public function debug_main_site()
    {
        $main_site_id_1 = get_main_site_id();
        $main_site_id_2 = get_network_option(get_current_network_id(), 'main_site');
        
        $sites = get_sites();
        $site_info = [];
        
        foreach ($sites as $site) {
            $site_info[] = [
                'id' => $site->blog_id,
                'domain' => $site->domain,
                'path' => $site->path,
                'url' => get_site_url($site->blog_id),
                'name' => get_blog_details($site->blog_id)->blogname,
                'locale' => Helpers::get_site_locale('key', $site->blog_id),
                'is_main' => $site->blog_id == $main_site_id_1 ? 'YES' : 'NO'
            ];
        }
        
        return [
            'get_main_site_id' => $main_site_id_1,
            'get_network_option' => $main_site_id_2,
            'main_site_url' => get_site_url($main_site_id_1),
            'main_site_exists' => get_site($main_site_id_1) ? true : false,
            'current_blog_id' => get_current_blog_id(),
            'network_id' => get_current_network_id(),
            'sites' => $site_info
        ];
    }

    public function test_permalink_fetch()
    {
        $main_site_id = get_main_site_id();
        $test_post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 696;
        
        // Test direct method
        switch_to_blog($main_site_id);
        $direct_permalink = get_permalink($test_post_id);
        restore_current_blog();
        
        // Test REST method
        $rest_permalink = Helpers::get_permalink_via_rest($main_site_id, $test_post_id);
        
        // Test REST URL construction
        $site_url = get_site_url($main_site_id);
        $api_url = trailingslashit($site_url) . 'wp-json/wp-hreflang/v1/get-permalink/' . $test_post_id;
        
        // Make test request
        $response = wp_remote_get(esc_url_raw($api_url), array(
            'timeout' => 15,
            'sslverify' => false,
        ));
        
        $response_details = [
            'is_error' => is_wp_error($response),
            'error_message' => is_wp_error($response) ? $response->get_error_message() : null,
            'response_code' => is_wp_error($response) ? null : wp_remote_retrieve_response_code($response),
            'response_body' => is_wp_error($response) ? null : wp_remote_retrieve_body($response),
            'decoded_body' => is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true),
            'json_error' => is_wp_error($response) ? null : json_last_error_msg()
        ];
        
        return [
            'main_site_id' => $main_site_id,
            'test_post_id' => $test_post_id,
            'site_url' => $site_url,
            'api_url' => $api_url,
            'direct_permalink' => $direct_permalink,
            'rest_permalink' => $rest_permalink,
            'current_blog_id' => get_current_blog_id(),
            'response_details' => $response_details
        ];
    }
}
