<?php
/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @link       https://example.com
 * @since      1.0.0
 *
 * @package    Friends_Plugin
 * @subpackage Friends_Plugin/admin/partials
 */
?>

<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <?php wp_nonce_field( 'friends_plugin_ajax_nonce', '_ajax_nonce', false ); ?>

    <h2 class="nav-tab-wrapper">
        <a href="#tab-1" class="nav-tab nav-tab-active">Manage Links</a>
        <a href="#tab-2" class="nav-tab">Settings</a>
        <a href="#tab-3" class="nav-tab">Export</a>
    </h2>

    <div id="tab-1" class="tab-content active">
        <form method="post" action="options.php">
            <?php
            // settings_fields( $this->plugin_name . '_links_options_group' );
            // do_settings_sections( $this->plugin_name . '-admin' );
            ?>
            <h3>Add New Friend Link</h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Site Name *</th>
                    <td><input type="text" name="friend_name" value="" class="regular-text" required/></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Site URL *</th>
                    <td><input type="url" name="friend_url" value="" class="regular-text" required/></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Site Icon URL *</th>
                    <td><input type="url" name="friend_icon_url" value="" class="regular-text" required/> <button class="button upload_image_button">Upload Image</button></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Site Description</th>
                    <td><textarea name="friend_description" rows="3" cols="50" class="large-text"></textarea></td>
                </tr>
                <tr valign="top">
                    <th scope="row">RSS Feed URL</th>
                    <td><input type="url" name="friend_rss_url" value="" class="regular-text"/></td>
                </tr>
            </table>
            <?php submit_button(__('Add Friend Link', 'friends-plugin'), 'primary', 'submit_add_friend_link'); ?>
        </form>

        <h3>Current Friend Links</h3>
        <p>Drag and drop to reorder links.</p>
        <table class="wp-list-table widefat fixed striped posts" id="friends-links-table">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-name">Site Name</th>
                    <th scope="col" class="manage-column column-url">Site URL</th>
                    <th scope="col" class="manage-column column-description">Description</th>
                    <th scope="col" class="manage-column column-rss">RSS URL</th>
                    <th scope="col" class="manage-column column-latest-post">Latest Post</th>
                    <th scope="col" class="manage-column column-actions">Actions</th>
                </tr>
            </thead>
            <tbody id="the-list" data-wp-lists="list:friend_link">
                <?php // Friend links will be loaded here by PHP or JavaScript ?>
                <tr id="no-items" class="no-items">
                    <td class="colspanchange" colspan="6"><?php _e('No friend links found.', 'friends-plugin'); ?></td>
                </tr>
            </tbody>
        </table>
        <p><button class="button" id="save-links-order"><?php _e('Save Links Order', 'friends-plugin'); ?></button></p>

    </div>

    <!-- Edit Link Modal -->
    <div id="edit-link-modal" class="friends-modal" style="display: none;">
        <div class="friends-modal-content">
            <div class="friends-modal-header">
                <h3>Edit Friend Link</h3>
                <span class="friends-modal-close">&times;</span>
            </div>
            <div class="friends-modal-body">
                <form id="edit-link-form">
                    <input type="hidden" id="edit_link_id" name="edit_link_id" value="" />
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">Site Name *</th>
                            <td><input type="text" id="edit_friend_name" name="edit_friend_name" value="" class="regular-text" required/></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Site URL *</th>
                            <td><input type="url" id="edit_friend_url" name="edit_friend_url" value="" class="regular-text" required/></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Site Icon URL *</th>
                            <td><input type="url" id="edit_friend_icon_url" name="edit_friend_icon_url" value="" class="regular-text" required/> <button type="button" class="button upload_image_button">Upload Image</button></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Site Description</th>
                            <td><textarea id="edit_friend_description" name="edit_friend_description" rows="3" cols="50" class="large-text"></textarea></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">RSS Feed URL</th>
                            <td><input type="url" id="edit_friend_rss_url" name="edit_friend_rss_url" value="" class="regular-text"/></td>
                        </tr>
                    </table>
                </form>
            </div>
            <div class="friends-modal-footer">
                <button type="button" class="button button-primary" id="save-edit-link"><?php _e('Update Link', 'friends-plugin'); ?></button>
                <button type="button" class="button" id="cancel-edit-link"><?php _e('Cancel', 'friends-plugin'); ?></button>
            </div>
        </div>
    </div>

    <div id="tab-2" class="tab-content">
        <h3>Plugin Settings</h3>
        <form method="post" action="options.php">
            <?php
            // settings_fields( $this->plugin_name . '_settings_options_group' );
            // do_settings_sections( $this->plugin_name . '-settings' );
            $update_interval = get_option('friends_plugin_rss_update_interval', 24);
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">RSS Update Interval (hours)</th>
                    <td>
                        <input type="number" name="friends_plugin_rss_update_interval" value="<?php echo esc_attr($update_interval); ?>" min="1" class="small-text"/>
                        <?php
                        $last_update_time = get_option('friends_plugin_last_rss_update_time', 0);
                        $interval_seconds = $update_interval * HOUR_IN_SECONDS;
                        
                        if ($last_update_time > 0) {
                            $last_update_formatted = date_i18n(get_option('date_format') . ' H:i', $last_update_time);
                            $next_update_time = $last_update_time + $interval_seconds;
                            $next_update_formatted = date_i18n(get_option('date_format') . ' H:i', $next_update_time);
                            
                            echo '<br><small style="color: #666;">';
                            printf(__('Last automatic update: %s', 'friends-plugin'), '<strong>' . $last_update_formatted . '</strong>');
                            echo '<br>';
                            printf(__('Next scheduled update: %s', 'friends-plugin'), '<strong>' . $next_update_formatted . '</strong>');
                            echo '</small>';
                        } else {
                            echo '<br><small style="color: #666;">' . __('No automatic updates have been performed yet.', 'friends-plugin') . '</small>';
                        }
                        ?>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Enable Glow Animation for Recent Posts</th>
                    <td>
                        <?php $glow_animation_enabled = get_option('friends_plugin_glow_animation_enabled', 1); ?>
                        <label>
                            <input type="checkbox" name="friends_plugin_glow_animation_enabled" value="1" <?php checked($glow_animation_enabled, 1); ?> />
                            <?php _e('Show glow animation for friend links with posts updated within the last week', 'friends-plugin'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save Settings', 'friends-plugin'), 'primary', 'submit_save_settings'); ?>
        </form>
        <h3>Manual RSS Update</h3>
        <p>Immediately fetch the latest posts for all friend links.</p>
        <button class="button button-secondary" id="fetch-rss-now">Update All RSS Feeds Now</button>
        <div id="rss-update-feedback" style="margin-top: 10px;"></div>
    </div>

    <div id="tab-3" class="tab-content">
        <h3>Export Friend Links</h3>
        <p>Export all your friend links data as a CSV file.</p>
        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
            <input type="hidden" name="action" value="friends_plugin_export_links">
            <?php wp_nonce_field( 'friends_plugin_export_links_nonce', 'friends_plugin_export_nonce' ); ?>
            <?php submit_button(__('Export Links to CSV', 'friends-plugin'), 'secondary', 'submit_export_links'); ?>
        </form>
    </div>

</div>