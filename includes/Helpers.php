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
}
