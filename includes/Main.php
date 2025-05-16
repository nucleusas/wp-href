<?php

namespace WP_Hreflang;

class Main
{
    private $post;
    private $rest_post;
    private $rest_admin;
    private $hreflang_tags;
    private $settings;
    private $site_archive_settings;

    public function init()
    {
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        $this->init_components();
    }

    public function load_textdomain()
    {
        load_plugin_textdomain('wp-hreflang', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    private function init_components()
    {
        $this->settings = new Admin\Network_Settings();
        $this->settings->init();

        $this->site_archive_settings = new Admin\Site_Archive_Settings();
        $this->site_archive_settings->init();

        $this->post = new Admin\Post();
        $this->post->init();

        $this->rest_post = new API\REST_Post();
        $this->rest_post->init();

        $this->rest_admin = new API\REST_Admin();
        $this->rest_admin->init();

        $this->hreflang_tags = new Frontend\Hreflang_Tags();
        $this->hreflang_tags->init();
    }
}
