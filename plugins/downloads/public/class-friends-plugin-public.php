<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    Friends_Plugin
 * @subpackage Friends_Plugin/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Friends_Plugin
 * @subpackage Friends_Plugin/public
 * @author     Your Name <email@example.com>
 */
class Friends_Plugin_Public {

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param      string    $plugin_name       The name of the plugin.
     * @param      string    $version    The version of this plugin.
     */
    public function __construct( $plugin_name, $version ) {

        $this->plugin_name = $plugin_name;
        $this->version = $version;

    }

    /**
     * Register the stylesheets for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_styles() {

        wp_enqueue_style( $this->plugin_name, FRIENDS_PLUGIN_URL . 'public/css/friends-plugin-public.css', array(), $this->version, 'all' );

    }

    /**
     * Register the JavaScript for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts() {

        wp_enqueue_script( $this->plugin_name, FRIENDS_PLUGIN_URL . 'public/js/friends-plugin-public.js', array( 'jquery' ), $this->version, false );

    }

    /**
     * Display the friends page using the [friends_page] shortcode.
     *
     * @since    1.0.0
     * @param array $atts Shortcode attributes.
     * @return string HTML output for the friends page.
     */
    public function display_friends_page( $atts ) {
        ob_start();
        include_once FRIENDS_PLUGIN_PATH . 'public/partials/friends-plugin-public-display.php';
        return ob_get_clean();
    }

}