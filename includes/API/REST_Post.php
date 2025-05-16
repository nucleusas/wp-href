<?php

namespace WP_Hreflang\API;

class REST_Post
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
            'permission_callback' => '__return_true', // Allow public access to permalinks
        ));

        register_rest_route('wp-hreflang/v1', '/get-permalinks', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_multiple_permalinks'),
            'permission_callback' => '__return_true', // Allow public access to permalinks
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

    public function get_multiple_permalinks($request)
    {
        try {
            $params = $request->get_json_params();

            if (!isset($params['ids']) || !is_array($params['ids'])) {
                return new \WP_Error(
                    'rest_error',
                    'Missing or invalid post IDs',
                    array('status' => 400)
                );
            }

            $post_ids = array_map('intval', $params['ids']);
            $permalinks = array();

            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);

                if ($post && $post->post_status === 'publish') {
                    $permalinks[$post_id] = get_permalink($post_id);
                } else {
                    $permalinks[$post_id] = null;
                }
            }

            return rest_ensure_response([
                'permalinks' => $permalinks
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
