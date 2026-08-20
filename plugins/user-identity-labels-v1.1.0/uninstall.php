<?php
/**
 * Optional data cleanup on uninstall.
 *
 * Data is retained unless the site owner explicitly defines
 * UIL_DELETE_DATA_ON_UNINSTALL as true in wp-config.php.
 *
 * @package UserIdentityLabels
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'UIL_DELETE_DATA_ON_UNINSTALL' ) || true !== UIL_DELETE_DATA_ON_UNINSTALL ) {
	return;
}

/**
 * Deletes this plugin's options and assignments for the current site.
 *
 * @return void
 */
function uil_delete_site_data() {
	global $wpdb;

	delete_option( 'uil_identity_labels' );
	delete_option( 'uil_settings' );
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'uil_identity_label_ids', '', true );
}

if ( is_multisite() ) {
	$uil_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $uil_site_ids as $uil_site_id ) {
		switch_to_blog( $uil_site_id );
		uil_delete_site_data();
		restore_current_blog();
	}
} else {
	uil_delete_site_data();
}

