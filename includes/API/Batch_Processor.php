<?php

namespace WP_Hreflang\API;

use WP_Hreflang\Helpers;

class Batch_Processor
{
    private $batch_size = 25;
    private $progress_option = 'wp_hreflang_rebuild_progress';

    public function init()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        register_rest_route('wp-hreflang/v1', '/rebuild/start', array(
            'methods' => 'POST',
            'callback' => array($this, 'start_rebuild'),
            'permission_callback' => array($this, 'check_admin_permissions'),
        ));

        register_rest_route('wp-hreflang/v1', '/rebuild/process', array(
            'methods' => 'POST',
            'callback' => array($this, 'process_batch'),
            'permission_callback' => array($this, 'check_admin_permissions'),
        ));

        register_rest_route('wp-hreflang/v1', '/rebuild/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_status'),
            'permission_callback' => array($this, 'check_admin_permissions'),
        ));
    }

    public function check_admin_permissions()
    {
        return current_user_can('manage_network_options');
    }

    /**
     * Start the rebuild process
     */
    public function start_rebuild($request)
    {
        $main_site_id = get_main_site_id();

        // Get all sites except main site
        $sites = array_values(array_filter(get_sites(['fields' => 'ids']), function ($site_id) use ($main_site_id) {
            return (int) $site_id !== (int) $main_site_id;
        }));

        // Clear existing maps on main site
        switch_to_blog($main_site_id);
        delete_post_meta_by_key('hreflang_map');
        restore_current_blog();

        // Initialize progress data
        $progress = array(
            'total_sites' => count($sites),
            'current_site_index' => 0,
            'sites' => $sites,
            'current_site_id' => $sites[0] ?? null,
            'current_site_posts' => [],
            'current_post_index' => 0,
            'total_posts' => 0,
            'completed' => false,
            'started_at' => time(),
        );

        // If we have sites to process, get posts for the first site
        if (!empty($sites)) {
            switch_to_blog($sites[0]);

            $posts = $this->get_posts_with_relations();
            $progress['current_site_posts'] = $posts;
            $progress['total_posts'] = count($posts);

            restore_current_blog();
        } else {
            $progress['completed'] = true;
        }

        update_site_option($this->progress_option, $progress);

        return rest_ensure_response([
            'success' => true,
            'status' => $progress,
            'message' => 'Rebuild started',
        ]);
    }

    /**
     * Process the next batch
     */
    public function process_batch($request)
    {
        $progress = get_site_option($this->progress_option);

        if (empty($progress) || $progress['completed']) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'No rebuild in progress or already completed',
            ]);
        }

        $main_site_id = get_main_site_id();
        $current_site_id = $progress['current_site_id'];
        $site_posts = $progress['current_site_posts'];
        $current_index = $progress['current_post_index'];

        // If we have posts to process for this site
        if (!empty($site_posts) && isset($site_posts[$current_index])) {
            $batch_end = min($current_index + $this->batch_size, count($site_posts));
            $batch_posts = array_slice($site_posts, $current_index, $this->batch_size);

            switch_to_blog($current_site_id);
            $locale = Helpers::get_site_locale('key', $current_site_id);

            // Process each post in the batch
            foreach ($batch_posts as $post_id) {
                $post = get_post($post_id);
                if (!$post || $post->post_status !== 'publish') {
                    continue;
                }

                $main_site_post_id = get_post_meta($post_id, 'hreflang_relation', true);
                restore_current_blog(); // Temporarily restore before REST call

                $permalink = Helpers::get_permalink_via_rest($current_site_id, $post_id);

                if ($permalink && $main_site_post_id) {
                    Helpers::update_hreflang_map($main_site_post_id, $locale, $permalink);
                }

                switch_to_blog($current_site_id); // Switch back to continue
            }

            restore_current_blog();

            // Update progress
            $progress['current_post_index'] = $batch_end;

            // If we've finished all posts for this site, move to the next site
            if ($batch_end >= count($site_posts)) {
                $progress['current_site_index']++;

                // If there are more sites to process
                if ($progress['current_site_index'] < count($progress['sites'])) {
                    $next_site_id = $progress['sites'][$progress['current_site_index']];
                    $progress['current_site_id'] = $next_site_id;
                    $progress['current_post_index'] = 0;

                    // Get posts for the next site
                    switch_to_blog($next_site_id);

                    $posts = $this->get_posts_with_relations();
                    $progress['current_site_posts'] = $posts;
                    $progress['total_posts'] = count($posts);

                    restore_current_blog();
                } else {
                    // All sites processed
                    $progress['completed'] = true;
                }
            }

            update_site_option($this->progress_option, $progress);

            return rest_ensure_response([
                'success' => true,
                'status' => $progress,
                'message' => 'Batch processed',
            ]);
        } else {
            // No posts to process for this site, move to the next
            $progress['current_site_index']++;

            if ($progress['current_site_index'] < count($progress['sites'])) {
                $next_site_id = $progress['sites'][$progress['current_site_index']];
                $progress['current_site_id'] = $next_site_id;
                $progress['current_post_index'] = 0;

                // Get posts for the next site
                switch_to_blog($next_site_id);

                $posts = $this->get_posts_with_relations();
                $progress['current_site_posts'] = $posts;
                $progress['total_posts'] = count($posts);

                restore_current_blog();
            } else {
                // All sites processed
                $progress['completed'] = true;
            }

            update_site_option($this->progress_option, $progress);

            return rest_ensure_response([
                'success' => true,
                'status' => $progress,
                'message' => 'Moving to next site',
            ]);
        }
    }

    /**
     * Get the current progress status
     */
    public function get_status($request)
    {
        $progress = get_site_option($this->progress_option);

        if (empty($progress)) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'No rebuild in progress',
            ]);
        }

        return rest_ensure_response([
            'success' => true,
            'status' => $progress,
        ]);
    }

    /**
     * Get posts with hreflang relations for the current site
     */
    private function get_posts_with_relations()
    {
        return get_posts(array(
            'post_type' => 'any',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'lang' => '',
            'suppress_filters' => true,
            'meta_query' => array(
                array(
                    'key' => 'hreflang_relation',
                    'compare' => 'EXISTS'
                )
            ),
            'fields' => 'ids'
        ));
    }
}
