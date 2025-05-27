<?php

namespace WP_Hreflang;

class Helpers
{
    public static function get_site_locale($format = 'raw', $blog_id = null)
    {
        $locale = '';
        $current_blog_id = $blog_id ?: get_current_blog_id();
        $locale = get_blog_option($current_blog_id, 'WPLANG');

        if (empty($locale)) {
            $locale = 'en_US'; // if WPLANG is empty, it's set to WordPress default en_US
        }

        $settings = get_site_option('wp_hreflang_network_settings', array(
            'locales' => array()
        ));

        // Check if there's a locale override set in network settings for current site
        if (isset($settings['locales'][$current_blog_id])) {
            $locale = $settings['locales'][$current_blog_id];
        }

        if ($format === 'key') {
            $locale = self::format_locale_key($locale);
        }

        if ($format === 'pretty') {
            $locale = self::format_locale_pretty($locale);
        }

        return $locale;
    }

    public static function format_locale_key($locale)
    {
        return strtolower(str_replace('_', '-', $locale));
    }

    public static function format_locale_pretty($locale)
    {
        $locale = self::format_locale_key($locale);

        if (strpos($locale, '-') !== false) {
            list($lang, $region) = explode('-', $locale);

            // if regional, capitalize region code (e.g. en-US)
            if (strlen($lang) === 2 && strlen($region) === 2) {
                $locale = $lang . '-' . strtoupper($region);
            }
        }

        return $locale;
    }

    public static function get_permalink_via_rest($site_id, $post_id)
    {
        $site_url = get_site_url($site_id);
        if (!$site_url) {
            return false;
        }

        $api_url = trailingslashit($site_url);
        $api_url .= 'wp-json/wp-hreflang/v1/get-permalink/' . $post_id;

        $response = wp_remote_get(esc_url_raw($api_url), array(
            'timeout' => 15,
            'sslverify' => false,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            if (is_wp_error($response)) {
                error_log(sprintf(
                    'WP Hreflang: Failed to fetch permalink from site %d for post %d. Error: %s',
                    $site_id,
                    $post_id,
                    $response->get_error_message()
                ));
            } else {
                error_log(sprintf(
                    'WP Hreflang: Failed to fetch permalink from site %d for post %d. Status code: %d',
                    $site_id,
                    $post_id,
                    wp_remote_retrieve_response_code($response)
                ));
            }
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['permalink'])) {
            error_log(sprintf(
                'WP Hreflang: Invalid permalink response from site %d for post %d. Response: %s',
                $site_id,
                $post_id,
                wp_remote_retrieve_body($response)
            ));
            return false;
        }

        return $body['permalink'];
    }

    public static function get_permalinks_via_rest($site_id, $post_ids)
    {
        if (empty($post_ids) || !is_array($post_ids)) {
            return array();
        }

        $site_url = get_site_url($site_id);
        if (!$site_url) {
            return array();
        }

        $api_url = trailingslashit($site_url);
        $api_url .= 'wp-json/wp-hreflang/v1/get-permalinks';

        $response = wp_remote_post(esc_url_raw($api_url), array(
            'timeout' => 15,
            'sslverify' => false,
            'body' => wp_json_encode(array(
                'ids' => array_map('intval', $post_ids)
            )),
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            if (is_wp_error($response)) {
                error_log(sprintf(
                    'WP Hreflang: Failed to fetch permalinks from site %d. Error: %s',
                    $site_id,
                    $response->get_error_message()
                ));
            } else {
                error_log(sprintf(
                    'WP Hreflang: Failed to fetch permalinks from site %d. Status code: %d',
                    $site_id,
                    wp_remote_retrieve_response_code($response)
                ));
            }
            return array();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['permalinks']) || !is_array($body['permalinks'])) {
            error_log(sprintf(
                'WP Hreflang: Invalid permalinks response from site %d. Response: %s',
                $site_id,
                wp_remote_retrieve_body($response)
            ));
            return array();
        }

        return $body['permalinks'];
    }

    public static function update_hreflang_map($main_site_post_id, $locale, $permalink)
    {
        $main_site_id = get_main_site_id();

        switch_to_blog($main_site_id);

        $hreflang_map = get_post_meta($main_site_post_id, 'hreflang_map', true) ?: [];
        $hreflang_map[$locale] = $permalink;
        $hreflang_map = self::ensure_main_site_entries($hreflang_map, $main_site_post_id, false);
        $result = update_post_meta($main_site_post_id, 'hreflang_map', $hreflang_map);

        restore_current_blog();

        return $result;
    }

    public static function remove_from_hreflang_map($main_site_post_id, $locale)
    {
        $main_site_id = get_main_site_id();

        switch_to_blog($main_site_id);
        $hreflang_map = get_post_meta($main_site_post_id, 'hreflang_map', true) ?: [];

        if (isset($hreflang_map[$locale])) {
            unset($hreflang_map[$locale]);
            $result = update_post_meta($main_site_post_id, 'hreflang_map', $hreflang_map);
        } else {
            $result = true; // Nothing to remove
        }

        restore_current_blog();

        return $result;
    }

    public static function ensure_main_site_entries($hreflang_map, $main_site_post_id, $update_existing = false)
    {
        $main_site_id = get_network_option(get_current_network_id(), 'main_site');
        $main_site_locale = self::get_site_locale('key', $main_site_id);
        $main_site_locale_alt = explode('-', $main_site_locale)[0] ?? null;

        $update_main_site_permalink = false;

        if (!isset($hreflang_map[$main_site_locale]) || $update_existing) {
            $update_main_site_permalink = true;
        }

        if (($main_site_locale_alt && !isset($hreflang_map[$main_site_locale_alt]) || $update_existing)) {
            $update_main_site_permalink = true;
        }

        if (!isset($hreflang_map['x-default']) || $update_existing) {
            $update_main_site_permalink = true;
        }

        if ($update_main_site_permalink) {
            $main_site_permalink = self::get_permalink_via_rest($main_site_id, $main_site_post_id);

            if ($main_site_permalink) {
                // Add or update main site locale entry
                if (!isset($hreflang_map[$main_site_locale]) || $update_existing) {
                    $hreflang_map[$main_site_locale] = $main_site_permalink;
                }

                // Add or update language-only code if applicable
                if ($main_site_locale_alt && (!isset($hreflang_map[$main_site_locale_alt]) || $update_existing)) {
                    $hreflang_map[$main_site_locale_alt] = $main_site_permalink;
                }

                // Add or update x-default entry
                if (!isset($hreflang_map['x-default']) || $update_existing) {
                    $hreflang_map['x-default'] = $main_site_permalink;
                }
            } else {
                // Couldn't get the permalink
                error_log(sprintf(
                    'WP Hreflang: Failed to get permalink for main site post %d',
                    $main_site_post_id
                ));
            }
        }

        return $hreflang_map;
    }
}
