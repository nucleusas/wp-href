<?php

namespace WP_Hreflang;

class Helpers
{
    public static function get_site_locale($format = 'raw', $blog_id = null)
    {
        $locale = '';

        if ($blog_id) {
            switch_to_blog($blog_id);
            $locale = get_locale();
            restore_current_blog();
        } else {
            $locale = get_locale();
        }

        $settings = get_site_option('wp_hreflang_network_settings', array(
            'locales' => array()
        ));

        // Check if there's a locale override set in network settings for current site
        $current_blog_id = $blog_id ?: get_current_blog_id();
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
