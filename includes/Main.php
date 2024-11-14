<?php

namespace WP_Hreflang;

class Main
{
    private $post;
    private $rest_controller;
    private $hreflang_tags;
    private $settings;

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

        $this->post = new Admin\Post();
        $this->post->init();

        $this->rest_controller = new API\REST_Controller();
        $this->rest_controller->init();

        $this->hreflang_tags = new Frontend\Hreflang_Tags();
        $this->hreflang_tags->init();
    }
}
