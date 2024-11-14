<?php

namespace WP_Hreflang\Frontend;

use WP_Hreflang\Helpers;

class Hreflang_Tags
{
    public function init()
    {
        add_action('wp_head', array($this, 'output_hreflang_tags'));
        add_filter('language_attributes', [$this, 'override_html_lang'], 10, 1);
    }

    public function output_hreflang_tags()
    {
        global $post;
        if (!$post) return;

        $post_id = is_main_site() ? $post->ID : intval(get_post_meta($post->ID, 'hreflang_relation', true));
        $main_site_id = get_main_site_id();

        switch_to_blog($main_site_id);
        $hreflang_map = get_post_meta($post_id, 'hreflang_map', true) ?: [];
        $main_site_permalink = get_permalink($post_id);
        restore_current_blog();

        if (!empty($hreflang_map)) {
            $localeKey = Helpers::get_site_locale('key', $main_site_id);
            $localeKeyAlt = explode('-', $localeKey)[0] ?? null;

            if (!isset($hreflang_map[$localeKey])) {
                // Add main site's full locale (e.g. en-us) to hreflang map if not already used by another site
                $hreflang_map[$localeKey] = $main_site_permalink;
            } else if ($localeKeyAlt && !isset($hreflang_map[$localeKeyAlt])) {
                // Add language-only code (e.g. en) pointing to main site if not already used by another site
                $hreflang_map[$localeKeyAlt] = $main_site_permalink;
            }

            // Set main site URL as the default version
            $hreflang_map['x-default'] = $main_site_permalink;
        }

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
