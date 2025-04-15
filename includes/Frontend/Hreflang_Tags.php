<?php

namespace WP_Hreflang\Frontend;

use WP_Hreflang\Helpers;

class Hreflang_Tags
{
    private $site_archives_paths_option = 'wp_hreflang_site_archive_paths';
    private $network_archives_map_option = 'wp_hreflang_network_archive_map';

    public function init()
    {
        add_action('wp_head', array($this, 'output_hreflang_tags'));
        add_filter('language_attributes', [$this, 'override_html_lang'], 10, 1);
    }

    public function output_hreflang_tags()
    {
        global $post;

        // Check if we should ignore URLs with query parameters
        $settings = get_site_option('wp_hreflang_network_settings', array(
            'ignore_query_params' => 0
        ));
        
        if (!empty($settings['ignore_query_params']) && !empty($_SERVER['QUERY_STRING'])) {
            return;
        }
        
        // First, try to match current URL to archive paths
        $success = $this->output_archive_hreflang_tags();

        if ($success) {
            return;
        }

        // If not, try to get hreflang from post/page
        $this->output_post_hreflang_tags($post);
    }

    private function output_post_hreflang_tags($post)
    {
        if (!$post) {
            return false;
        }
        
        $post_id = is_main_site() ? $post->ID : intval(get_post_meta($post->ID, 'hreflang_relation', true));

        if (!$post_id) {
            return false;
        }

        $main_site_id = get_main_site_id();

        switch_to_blog($main_site_id);
        $hreflang_map = get_post_meta($post_id, 'hreflang_map', true) ?: [];
        restore_current_blog();

        if (empty($hreflang_map)) {
            return false;
        }

        $this->output_hreflang_links($hreflang_map);
        return true;
    }

    private function output_archive_hreflang_tags()
    {
        $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $site_prefix = get_blog_details()->path;

        // Remove site prefix from current path if it exists
        if (strpos($current_path, $site_prefix) === 0) {
            $current_path = substr($current_path, strlen($site_prefix));
        }
        $current_path = '/' . ltrim($current_path, '/');

        // Get current site's path mappings for lookup
        $path_lookup = get_option($this->site_archives_paths_option, array());
        if (empty($path_lookup)) {
            return false;
        }

        // Find matching archive page for current path
        $matched_archive_id = null;

        // Direct path matching (no wildcards)
        if (isset($path_lookup[$current_path])) {
            $matched_archive_id = $path_lookup[$current_path];
        }

        if (!$matched_archive_id) {
            return false;
        }

        // Get network-wide mappings
        $network_mappings = get_site_option($this->network_archives_map_option, array());
        if (!isset($network_mappings[$matched_archive_id])) {
            return false;
        }

        // Build hreflang map
        $hreflang_map = array();
        $main_site_locale = Helpers::get_site_locale('key', get_main_site_id());

        foreach ($network_mappings[$matched_archive_id] as $locale => $url) {
            $hreflang_map[$locale] = $url;

            // Set x-default to the main site's URL if it exists
            if ($locale === $main_site_locale) {
                $hreflang_map['x-default'] = $url;
            }
        }

        if (!empty($hreflang_map)) {
            $this->output_hreflang_links($hreflang_map);
            return true;
        }

        return false;
    }

    private function output_hreflang_links($hreflang_map)
    {
        foreach ($hreflang_map as $lang => $permalink) {
            echo '<link rel="alternate" href="' . esc_url($permalink) . '" hreflang="' . esc_attr(Helpers::format_locale_pretty($lang)) . '" />';
        }
    }

    public function override_html_lang($language_attributes)
    {
        // Replace the existing lang attribute with our override
        return preg_replace('/lang="[^"]*"/', 'lang="' . esc_attr(Helpers::get_site_locale('pretty')) . '"', $language_attributes);
    }
}
