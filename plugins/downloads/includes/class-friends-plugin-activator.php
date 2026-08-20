<?php

/**
 * Fired during plugin activation
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    Friends_Plugin
 * @subpackage Friends_Plugin/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Friends_Plugin
 * @subpackage Friends_Plugin/includes
 * @author     Your Name <email@example.com>
 */
class Friends_Plugin_Activator {

    /**
     * Short Description. (use period)
     *
     * Long Description.
     *
     * @since    1.0.0
     */
    public static function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name tinytext NOT NULL,
            url varchar(255) NOT NULL,
            icon_url varchar(255) DEFAULT '',
            description text,
            rss_url varchar(255) DEFAULT '',
            latest_post_title text,
            latest_post_url varchar(255) DEFAULT '',
            latest_post_date datetime DEFAULT '1970-01-01 00:00:00',
            sort_order mediumint(9) DEFAULT 0 NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        add_option( 'friends_plugin_db_version', FRIENDS_PLUGIN_VERSION );
        add_option( 'friends_plugin_rss_update_interval', 24 ); // Default to 24 hours
        add_option( 'friends_plugin_glow_animation_enabled', 1 ); // Default to enabled
    }

}