<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    Friends_Plugin
 * @subpackage Friends_Plugin/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Friends_Plugin
 * @subpackage Friends_Plugin/admin
 * @author     Your Name <email@example.com>
 */
class Friends_Plugin_Admin {

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
     * @param      string    $plugin_name       The name of this plugin.
     * @param      string    $version    The version of this plugin.
     */
    public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

		// AJAX actions
		add_action( 'wp_ajax_load_links', array( $this, 'ajax_load_links' ) );
        add_action( 'wp_ajax_add_link', array( $this, 'ajax_add_link' ) );
        add_action( 'wp_ajax_update_link', array( $this, 'ajax_update_link' ) );
        add_action( 'wp_ajax_delete_link', array( $this, 'ajax_delete_link' ) );
        add_action( 'wp_ajax_save_order', array( $this, 'ajax_save_order' ) );
        add_action( 'wp_ajax_get_link_data', array( $this, 'ajax_get_link_data' ) );
        add_action( 'wp_ajax_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_fetch_rss', array( $this, 'ajax_fetch_rss' ) );

		// Admin post action for CSV export
		add_action( 'admin_post_friends_plugin_export_links', array( $this, 'export_links_csv' ) );

		// Cron job for scheduled RSS updates
		add_action( 'friends_plugin_scheduled_rss_update', array( $this, 'perform_scheduled_rss_updates' ) );

		// Ensure the cron is scheduled on plugin activation or if not already scheduled
		if ( ! wp_next_scheduled( 'friends_plugin_scheduled_rss_update' ) ) {
		    $this->reschedule_rss_updates();
		}
	}

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles() {

        wp_enqueue_style( $this->plugin_name, FRIENDS_PLUGIN_URL . 'admin/css/friends-plugin-admin.css', array(), $this->version, 'all' );

    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts() {

        wp_enqueue_script( $this->plugin_name, FRIENDS_PLUGIN_URL . 'admin/js/friends-plugin-admin.js', array( 'jquery', 'jquery-ui-sortable' ), $this->version, false );
        
        // Localize the script with translation strings
        wp_localize_script( $this->plugin_name, 'friendsPluginAjax', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'friends_plugin_ajax_nonce' ),
            'noLinksText' => __('No friend links found.', 'friends-plugin'),
            'errorLoadingText' => __('Error loading links:', 'friends-plugin'),
            'ajaxErrorText' => __('AJAX error loading links:', 'friends-plugin'),
            'unknownErrorText' => __('Unknown error', 'friends-plugin'),
            'processingText' => __('Processing...', 'friends-plugin')
        ));
        wp_localize_script( $this->plugin_name, 'friends_plugin_ajax_obj', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            '_ajax_nonce'   => wp_create_nonce( 'friends_plugin_ajax_nonce' )
        ) );

    }

    /**
     * Add options page.
     */
    public function add_plugin_admin_menu() {
        add_menu_page(
            __( 'Friend Links Management', 'friends-plugin' ),
            __( 'Friend Links', 'friends-plugin' ),
            'manage_options',
            $this->plugin_name,
            array( $this, 'display_plugin_setup_page' ),
            'dashicons-groups',
            26
        );
    }

    /**
     * Display the plugin setup page
     */
    public function display_plugin_setup_page() {
        include_once( FRIENDS_PLUGIN_PATH . 'admin/partials/friends-plugin-admin-display.php' );
    }

    // AJAX handler to load links
    public function ajax_load_links() {
        check_ajax_referer( 'friends_plugin_ajax_nonce', '_ajax_nonce' );

        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';
        $links = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} ORDER BY sort_order ASC" ), ARRAY_A );

        if ( $links === false ) {
            wp_send_json_error( 'Error fetching links from database.' );
        }

        wp_send_json_success( $links );
    }

    // AJAX handler to add a new link
    public function ajax_add_link() {
        check_ajax_referer( 'friends_plugin_ajax_nonce', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $name = isset($_POST['name']) ? sanitize_text_field( $_POST['name'] ) : '';
        $url = isset($_POST['url']) ? esc_url_raw( $_POST['url'] ) : '';
        $icon_url = isset($_POST['icon_url']) ? esc_url_raw( $_POST['icon_url'] ) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field( $_POST['description'] ) : '';
        $rss_url = isset($_POST['rss_url']) ? esc_url_raw( $_POST['rss_url'] ) : '';

        if ( empty( $name ) || empty( $url ) || empty( $icon_url ) ) {
            wp_send_json_error( 'Site Name, Site URL, and Site Icon URL are required.' );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';

        // Check if table exists
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) {
            wp_send_json_error( __('Database table does not exist. Please deactivate and reactivate the plugin.', 'friends-plugin') );
        }

        $max_sort_order = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$table_name}" ) );
        $sort_order = ( $max_sort_order === null ) ? 0 : $max_sort_order + 1;

        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => $name,
                'url' => $url,
                'icon_url' => $icon_url,
                'description' => $description,
                'rss_url' => $rss_url,
                'sort_order' => $sort_order
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d' )
        );

        if ( $result ) {
            $link_id = $wpdb->insert_id;
            if( !empty($rss_url) ) {
                $this->fetch_single_rss_feed($link_id, $rss_url);
            }
            wp_send_json_success( array('message' => __('Link added successfully.', 'friends-plugin'), 'link_id' => $link_id) );
        } else {
            // Get the last database error for debugging
            $db_error = $wpdb->last_error ? $wpdb->last_error : __('Unknown database error', 'friends-plugin');
            error_log( 'Friends Plugin DB Error: ' . $db_error );
            wp_send_json_error( __('Error adding link to database:', 'friends-plugin') . ' ' . $db_error );
        }
    }

    // AJAX handler to update an existing link
    public function ajax_update_link() {
        check_ajax_referer( 'friends_plugin_ajax_nonce', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $id = isset($_POST['id']) ? intval( $_POST['id'] ) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field( $_POST['name'] ) : '';
        $url = isset($_POST['url']) ? esc_url_raw( $_POST['url'] ) : '';
        $icon_url = isset($_POST['icon_url']) ? esc_url_raw( $_POST['icon_url'] ) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field( $_POST['description'] ) : '';
        $rss_url = isset($_POST['rss_url']) ? esc_url_raw( $_POST['rss_url'] ) : '';

        if ( empty($id) || empty( $name ) || empty( $url ) || empty( $icon_url ) ) {
            wp_send_json_error( 'Link ID, Site Name, Site URL, and Site Icon URL are required.' );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';

        $result = $wpdb->update(
            $table_name,
            array(
                'name' => $name,
                'url' => $url,
                'icon_url' => $icon_url,
                'description' => $description,
                'rss_url' => $rss_url,
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( $result !== false ) {
            // Always clear old RSS data first, then fetch new data if RSS URL is provided
            $this->clear_rss_data($id);
            if( !empty($rss_url) ) {
                $this->fetch_single_rss_feed($id, $rss_url);
            }
            wp_send_json_success( 'Link updated successfully.' );
        } else {
            wp_send_json_error( 'Error updating link or no changes made.' );
        }
    }

    // AJAX handler to delete a link
    public function ajax_delete_link() {
        check_ajax_referer( 'friends_plugin_ajax_nonce', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $id = isset($_POST['id']) ? intval( $_POST['id'] ) : 0;
        if ( empty( $id ) ) {
            wp_send_json_error( 'Link ID is required.' );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';
        $result = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );

        if ( $result ) {
            wp_send_json_success( 'Link deleted successfully.' );
        } else {
            wp_send_json_error( 'Error deleting link.' );
        }
    }

    // AJAX handler to save links order
    public function ajax_save_order() {
        check_ajax_referer( 'friends_plugin_ajax_nonce', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $order = isset($_POST['order']) ? $_POST['order'] : array();
        if ( empty( $order ) || ! is_array( $order ) ) {
            wp_send_json_error( 'Invalid order data.' );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';
        $success_count = 0;

        foreach ( $order as $index => $id ) {
            $result = $wpdb->update(
                $table_name,
                array( 'sort_order' => intval( $index ) ),
                array( 'id' => intval( $id ) ),
                array( '%d' ),
                array( '%d' )
            );
            if ( $result !== false ) {
                $success_count++;
            }
        }

        if ( $success_count == count($order) ) {
            wp_send_json_success( 'Links order saved.' );
        } else {
            wp_send_json_error( 'Error saving links order for some items.' );
        }
    }

    // AJAX handler to get single link data
    public function ajax_get_link_data() {
        check_ajax_referer( 'friends_plugin_ajax_nonce', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $id = isset($_POST['id']) ? intval( $_POST['id'] ) : 0;
        if ( empty( $id ) ) {
            wp_send_json_error( 'Link ID is required.' );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';
        $link = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $id ), ARRAY_A );

        if ( $link ) {
            wp_send_json_success( $link );
        } else {
            wp_send_json_error( 'Link not found.' );
        }
    }

    // AJAX handler to save settings
    public function ajax_save_settings() {
        check_ajax_referer( 'friends_plugin_ajax_nonce', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $interval = isset($_POST['interval']) ? intval( $_POST['interval'] ) : 24;
        if ( $interval < 1 ) {
            $interval = 24; 
        }

        $glow_animation = isset($_POST['glow_animation']) ? 1 : 0;

        update_option( 'friends_plugin_rss_update_interval', $interval );
        update_option( 'friends_plugin_glow_animation_enabled', $glow_animation );
        
        // Check if the next scheduled update time would be in the past
        $last_update_time = get_option('friends_plugin_last_rss_update_time', 0);
        if ($last_update_time > 0) {
            $interval_seconds = $interval * HOUR_IN_SECONDS;
            $next_update_time = $last_update_time + $interval_seconds;
            
            // If the calculated next update time is in the past, reset the last update time
            if ($next_update_time < current_time('timestamp')) {
                update_option('friends_plugin_last_rss_update_time', current_time('timestamp'));
            }
        }
        
        $this->reschedule_rss_updates(); 
        wp_send_json_success( 'Settings saved. RSS update interval is now ' . $interval . ' hours.' );
    }

    // AJAX handler to fetch all RSS feeds manually
    public function ajax_fetch_rss() {
        check_ajax_referer( 'friends_plugin_ajax_nonce', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';
        $links = $wpdb->get_results( $wpdb->prepare( "SELECT id, rss_url FROM {$table_name} WHERE rss_url != '' AND rss_url IS NOT NULL" ), ARRAY_A );

        $success_count = 0;
        $failure_count = 0;
        $detailed_errors = array();

        if ( empty( $links ) ) {
            wp_send_json_success( array( 'message' => __('No links with RSS URLs found to update.', 'friends-plugin'), 'success_count' => 0, 'failure_count' => 0, 'errors' => $detailed_errors ) );
            return;
        }

        // First, clear all RSS data for links that will be updated
        foreach ( $links as $link_item ) {
            $this->clear_rss_data( $link_item['id'] );
        }
        
        foreach ( $links as $link_item ) { // Renamed $link to $link_item to avoid conflict with $link used in fetch_single_rss_feed context if any
            $result = $this->fetch_single_rss_feed( $link_item['id'], $link_item['rss_url'] );
            if ( $result === true ) {
                $success_count++;
            } else {
                $failure_count++;
                // $result will contain the error message string if fetching failed
                $detailed_errors[] = array('id' => $link_item['id'], 'rss_url' => $link_item['rss_url'], 'error' => $result);
            }
            sleep(1); // Small delay between fetches
        }

        // Update the last update time for manual updates too
        update_option('friends_plugin_last_rss_update_time', current_time('timestamp'));
        
        wp_send_json_success( array(
            'message' => sprintf( __('RSS feeds update process finished. %d succeeded, %d failed.', 'friends-plugin'), $success_count, $failure_count ),
            'success_count' => $success_count,
            'failure_count' => $failure_count,
            'errors' => $detailed_errors
        ) );
    }

    // Helper function to clear RSS data for a specific link
    private function clear_rss_data( $link_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';
        
        $clear_data = array(
            'latest_post_title' => '',
            'latest_post_url'   => '',
            'latest_post_date'  => '1970-01-01 00:00:00'
        );
        
        $wpdb->update( $table_name, $clear_data, array( 'id' => $link_id ), array('%s', '%s', '%s'), array('%d') );
    }

    // Helper function to fetch and update a single RSS feed
    private function fetch_single_rss_feed( $link_id, $rss_url = null ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';

        if ( is_null( $rss_url ) ) {
            $rss_url = $wpdb->get_var( $wpdb->prepare( "SELECT rss_url FROM {$table_name} WHERE id = %d", $link_id ) );
        }

        // Clear old RSS data first
        $this->clear_rss_data( $link_id );

        $update_data = array(
            'latest_post_title' => '',
            'latest_post_url'   => '',
            'latest_post_date'  => '1970-01-01 00:00:00'
        );

        if ( empty( $rss_url ) ) {
            $wpdb->update( $table_name, $update_data, array( 'id' => $link_id ), array('%s', '%s', '%s'), array('%d') );
            return 'RSS URL is empty.';
        }

        include_once( ABSPATH . WPINC . '/feed.php' );
        
        // Add HTTP request filters for better compatibility in both manual and cron environments
        $http_filter = function( $args, $url ) use ( $rss_url ) {
            if ( $url === $rss_url ) {
                $args['timeout'] = 30;
                $args['user-agent'] = 'Mozilla/5.0 (compatible; WordPress RSS Reader)';
                $args['headers'] = array(
                    'Accept' => 'application/rss+xml, application/xml, text/xml',
                    'Accept-Encoding' => 'gzip, deflate'
                );
            }
            return $args;
        };
        
        add_filter( 'http_request_args', $http_filter, 10, 2 );
        add_filter( 'wp_feed_cache_transient_lifetime', function() { return 300; } ); // 5 minutes cache
        
        $feed = fetch_feed( $rss_url );
        
        // Remove the filter after use
        remove_filter( 'http_request_args', $http_filter, 10 );

        if ( is_wp_error( $feed ) ) {
            // If WordPress fetch_feed fails, try alternative method for XML parsing issues
            $error_message = $feed->get_error_message();
            
            if ( strpos( $error_message, 'XML error' ) !== false || strpos( $error_message, 'invalid XML' ) !== false ) {
                // Try to fetch and clean the RSS content manually
                $cleaned_feed = $this->fetch_and_clean_rss( $rss_url );
                if ( $cleaned_feed !== false && ! $cleaned_feed->error() ) {
                    $feed = $cleaned_feed;
                } else {
                    $wpdb->update( $table_name, $update_data, array( 'id' => $link_id ), array('%s', '%s', '%s'), array('%d') );
                    $additional_error = $cleaned_feed !== false ? ' Alternative parsing also failed: ' . $cleaned_feed->error() : ' Alternative parsing method failed.';
                    return $error_message . $additional_error . ' This is usually caused by invalid characters or formatting in the RSS feed. Please check the RSS URL: ' . $rss_url;
                }
            } else {
                $wpdb->update( $table_name, $update_data, array( 'id' => $link_id ), array('%s', '%s', '%s'), array('%d') );
                return $error_message;
            }
        }

        $maxitems = $feed->get_item_quantity( 1 );
        $rss_items = $feed->get_items( 0, $maxitems );

        if ( empty( $rss_items ) ) {
            $wpdb->update( $table_name, $update_data, array( 'id' => $link_id ), array('%s', '%s', '%s'), array('%d') );
            $feed->__destruct();
            unset($feed);
            return 'No items found in RSS feed.';
        }

        $latest_item = $rss_items[0];
        $update_data['latest_post_title'] = sanitize_text_field( $latest_item->get_title() );
        $update_data['latest_post_url'] = esc_url_raw( $latest_item->get_permalink() );
        $update_data['latest_post_date'] = $latest_item->get_date( 'Y-m-d H:i:s' );

        $wpdb->update( $table_name, $update_data, array( 'id' => $link_id ), array('%s', '%s', '%s'), array('%d') );
        $feed->__destruct(); 
        unset($feed);
        return true;
    }

    // Clear and Reschedule RSS updates cron
    public function reschedule_rss_updates() {
        $hook = 'friends_plugin_scheduled_rss_update';
        wp_clear_scheduled_hook( $hook );

        $interval_hours = get_option( 'friends_plugin_rss_update_interval', 24 );
        
        // Ensure custom schedules are registered by triggering the cron_schedules filter
        wp_get_schedules();
        
        // Determine the appropriate schedule based on the interval
        $schedule = 'hourly'; // Default fallback
        
        if ( $interval_hours == 1 ) {
            $schedule = 'hourly';
        } elseif ( $interval_hours == 12 ) {
            $schedule = 'twicedaily';
        } elseif ( $interval_hours == 24 ) {
            $schedule = 'daily';
        } else {
            // For custom intervals, use the dynamic schedule key
            $schedule = 'hours_' . $interval_hours;
            
            // Check again after ensuring schedules are loaded
            $schedules = wp_get_schedules();
            if ( ! isset( $schedules[ $schedule ] ) ) {
                // If custom schedule still doesn't exist, fall back to hourly
                $schedule = 'hourly';
            }
        }
        
        wp_schedule_event( time(), $schedule, $hook );
    }

    // This method will be hooked to the cron event.
    public function perform_scheduled_rss_updates() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';
        $links = $wpdb->get_results( $wpdb->prepare( "SELECT id, rss_url FROM {$table_name} WHERE rss_url != '' AND rss_url IS NOT NULL" ), ARRAY_A );
        
        if (!empty($links)) {
            foreach($links as $link){
                $this->fetch_single_rss_feed($link['id'], $link['rss_url']);
                sleep(1); // Small delay
            }
        }
        
        // Update the last update time
        update_option('friends_plugin_last_rss_update_time', current_time('timestamp'));
    }

    // Export links to CSV
    public function export_links_csv() {
        if ( ! isset( $_POST['friends_plugin_export_nonce'] ) || ! wp_verify_nonce( $_POST['friends_plugin_export_nonce'], 'friends_plugin_export_links_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.' );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'friends_links';
        $links = $wpdb->get_results( $wpdb->prepare( "SELECT name, url, icon_url, description, rss_url, latest_post_title, latest_post_url, latest_post_date FROM {$table_name} ORDER BY sort_order ASC" ), ARRAY_A );

        if ( empty( $links ) ) {
            // Provide a message on the admin page instead of wp_die if possible, or redirect back with a notice.
            // For now, wp_die is simple for a direct admin_post action.
            wp_redirect(add_query_arg('fp_export_status', 'no_data', wp_get_referer()));
            exit;
        }

        $filename = 'friend_links_export_' . date( 'Y-m-d_H-i-s' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $output = fopen( 'php://output', 'w' );
        
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for UTF-8 in Excel

        fputcsv( $output, array( 'Site Name', 'Site URL', 'Icon URL', 'Description', 'RSS URL', 'Latest Post Title', 'Latest Post URL', 'Latest Post Date' ) );

        foreach ( $links as $link ) {
            fputcsv( $output, $link );
        }

        fclose( $output );
        exit;
    }

    /**
     * Alternative RSS fetching method with XML cleaning for problematic feeds
     * 
     * @param string $rss_url The RSS URL to fetch
     * @return mixed SimplePie feed object on success, false on failure
     */
    private function fetch_and_clean_rss( $rss_url ) {
        // Use WordPress HTTP API to fetch the RSS content
        $response = wp_remote_get( $rss_url, array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress RSS Reader)',
            'headers' => array(
                'Accept' => 'application/rss+xml, application/xml, text/xml, */*'
            )
        ) );
        
        if ( is_wp_error( $response ) ) {
            return false;
        }
        
        // Check HTTP status code - only proceed with 200 OK responses
        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return false;
        }
        
        // Check if this looks like RSS content even if content-type is wrong/empty
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        $is_likely_rss = ( strpos( $body, '<rss' ) !== false || 
                          strpos( $body, '<feed' ) !== false || 
                          strpos( $body, '<channel' ) !== false );
        
        // If content-type is empty but content looks like RSS, proceed anyway
        if ( empty( $content_type ) && ! $is_likely_rss ) {
            return false;
        }
        
        // Clean the XML content to remove problematic characters
        $cleaned_body = $this->clean_xml_content( $body );
        
        // Try to parse the cleaned content with SimplePie
        include_once( ABSPATH . WPINC . '/class-simplepie.php' );
        
        $feed = new SimplePie();
        $feed->set_raw_data( $cleaned_body );
        $feed->enable_cache( false ); // Disable cache for this manual fetch
        $feed->init();
        
        // If standard cleaning failed, try enhanced error recovery
        if ( $feed->error() ) {
            $enhanced_cleaned_body = $this->enhanced_clean_xml_content( $cleaned_body );
            
            $enhanced_feed = new SimplePie();
            $enhanced_feed->set_raw_data( $enhanced_cleaned_body );
            $enhanced_feed->enable_cache( false );
            $enhanced_feed->init();
            
            if ( ! $enhanced_feed->error() ) {
                return $enhanced_feed;
            }
            
            // If enhanced cleaning also failed, but the original feed had items, return the original
            if ( $feed->get_item_quantity() > 0 ) {
                return $feed;
            }
            
            return false;
        }
        
        return $feed;
    }
    
    /**
     * Clean XML content to remove invalid characters and fix structural issues
     * 
     * @param string $xml_content The raw XML content
     * @return string Cleaned XML content
     */
    private function clean_xml_content( $xml_content ) {
        // Remove BOM if present (UTF-8, UTF-16, UTF-32)
        $xml_content = preg_replace( '/^\xEF\xBB\xBF|^\xFF\xFE|^\xFE\xFF|^\x00\x00\xFE\xFF|^\xFF\xFE\x00\x00/', '', $xml_content );
        
        // Remove any leading whitespace before XML declaration - this is the main cause of "Reserved XML Name" error
        $xml_content = ltrim( $xml_content );
        
        // Find the XML declaration and ensure it's at the very beginning
        if ( preg_match( '/<\?xml[^>]*\?>/', $xml_content, $matches, PREG_OFFSET_CAPTURE ) ) {
            $xml_declaration = $matches[0][0];
            $xml_position = $matches[0][1];
            
            // If XML declaration is not at position 0, move it to the beginning
            if ( $xml_position > 0 ) {
                $xml_content = substr_replace( $xml_content, '', $xml_position, strlen( $xml_declaration ) );
                $xml_content = $xml_declaration . ltrim( $xml_content );
            }
        }
        
        // Fix duplicate XML tags that can cause parsing errors
        // Remove duplicate opening tags
        $xml_content = preg_replace( '/<(rss[^>]*)>\s*<\1>/', '<$1>', $xml_content );
        $xml_content = preg_replace( '/<(channel[^>]*)>\s*<\1>/', '<$1>', $xml_content );
        $xml_content = preg_replace( '/<(item[^>]*)>\s*<\1>/', '<$1>', $xml_content );
        
        // Remove duplicate closing tags
        $xml_content = preg_replace( '/<\/rss>\s*<\/rss>/', '</rss>', $xml_content );
        $xml_content = preg_replace( '/<\/channel>\s*<\/channel>/', '</channel>', $xml_content );
        $xml_content = preg_replace( '/<\/item>\s*<\/item>/', '</item>', $xml_content );
        
        // Fix duplicate content:encoded and other CDATA sections
        $xml_content = preg_replace( '/]]><\/content:encoded>\s*<content:encoded><\!\[CDATA\[/', '', $xml_content );
        
        // Fix duplicate other common RSS elements
        $xml_content = preg_replace( '/<(title|description|link|pubDate|guid)([^>]*)>([^<]*)<\/\1>\s*<\1\2>\3<\/\1>/', '<$1$2>$3</$1>', $xml_content );
        
        // Fix duplicate wfw:commentRss and slash:comments
        $xml_content = preg_replace( '/<(wfw:commentRss)>([^<]*)<\/\1>\s*<\1>\2<\/\1>/', '<$1>$2</$1>', $xml_content );
        $xml_content = preg_replace( '/<(slash:comments)>([^<]*)<\/\1>\s*<\1>\2<\/\1>/', '<$1>$2</$1>', $xml_content );
        
        // Remove control characters except tab, newline, and carriage return
        $xml_content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $xml_content );
        
        // Fix common XML encoding issues
        $xml_content = str_replace( array( '&amp;amp;', '&amp;#' ), array( '&amp;', '&#' ), $xml_content );
        
        // Ensure proper encoding
        if ( ! mb_check_encoding( $xml_content, 'UTF-8' ) ) {
            $xml_content = mb_convert_encoding( $xml_content, 'UTF-8', 'auto' );
        }
        
        return $xml_content;
    }
    
    /**
     * Enhanced XML content cleaning for severely malformed feeds
     * Only used when standard cleaning fails
     * 
     * @param string $xml_content The XML content that failed standard cleaning
     * @return string Enhanced cleaned XML content
     */
    private function enhanced_clean_xml_content( $xml_content ) {
        // Start with the already cleaned content from clean_xml_content
        
        // Fix incomplete XML structure - add missing closing tags
        if ( strpos( $xml_content, '<rss' ) !== false && strpos( $xml_content, '</rss>' ) === false ) {
            $xml_content .= '</rss>';
        }
        
        if ( strpos( $xml_content, '<channel' ) !== false && strpos( $xml_content, '</channel>' ) === false ) {
            $xml_content = str_replace( '</rss>', '</channel></rss>', $xml_content );
        }
        
        // Add missing RSS namespace if not present
        if ( strpos( $xml_content, '<rss' ) !== false && strpos( $xml_content, 'version=' ) !== false ) {
            if ( strpos( $xml_content, 'xmlns=' ) === false ) {
                $xml_content = preg_replace( '/<rss([^>]*)>/', '<rss$1 xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom">', $xml_content );
            }
        }
        
        // Fix truncated CDATA sections
        $xml_content = preg_replace( '/<\!\[CDATA\[([^\]]*?)(?!]]>)$/', '<![CDATA[$1]]>', $xml_content );
        
        // Fix unclosed CDATA sections in the middle of content
        $xml_content = preg_replace( '/<\!\[CDATA\[([^\]]*?)(<[^>]+>)/', '<![CDATA[$1]]>$2', $xml_content );
        
        // Remove any trailing incomplete tags
        $xml_content = preg_replace( '/<[^>]*$/', '', $xml_content );
        
        // Fix malformed entities
        $xml_content = preg_replace( '/&(?![a-zA-Z0-9#]{1,7};)/', '&amp;', $xml_content );
        
        // Fix unescaped angle brackets in content (simplified approach)
        // First protect valid XML tags, CDATA sections, and comments
        $protected_content = array();
        $placeholder_index = 0;
        
        // Protect CDATA sections
        $xml_content = preg_replace_callback('/<![CDATA[.*?]]>/s', function($matches) use (&$protected_content, &$placeholder_index) {
            $placeholder = "__PROTECTED_CDATA_{$placeholder_index}__";
            $protected_content[$placeholder] = $matches[0];
            $placeholder_index++;
            return $placeholder;
        }, $xml_content);
        
        // Protect HTML comments
        $xml_content = preg_replace_callback('/<!--.*?-->/s', function($matches) use (&$protected_content, &$placeholder_index) {
            $placeholder = "__PROTECTED_COMMENT_{$placeholder_index}__";
            $protected_content[$placeholder] = $matches[0];
            $placeholder_index++;
            return $placeholder;
        }, $xml_content);
        
        // Protect XML tags
        $xml_content = preg_replace_callback('/<\/?[a-zA-Z0-9:]+[^>]*>/', function($matches) use (&$protected_content, &$placeholder_index) {
            $placeholder = "__PROTECTED_TAG_{$placeholder_index}__";
            $protected_content[$placeholder] = $matches[0];
            $placeholder_index++;
            return $placeholder;
        }, $xml_content);
        
        // Now escape remaining < and > characters
        $xml_content = str_replace('<', '&lt;', $xml_content);
        $xml_content = str_replace('>', '&gt;', $xml_content);
        
        // Restore protected content
        foreach ($protected_content as $placeholder => $original) {
            $xml_content = str_replace($placeholder, $original, $xml_content);
        }
        
        // Ensure proper XML structure by wrapping orphaned content
        if ( strpos( $xml_content, '<rss' ) === false && strpos( $xml_content, '<channel' ) !== false ) {
            $xml_content = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0">' . $xml_content . '</rss>';
        }
        
        return $xml_content;
    }

}