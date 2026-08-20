<?php
/**
 * Remove settings and the locally cached font when the plugin is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dieyu_font_accelerator_settings' );
delete_option( 'dieyu_font_accelerator_font_meta' );
delete_option( 'dieyu_font_accelerator_version' );

$uploads = wp_upload_dir();
if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
	$directory = trailingslashit( $uploads['basedir'] ) . 'dieyu-font-accelerator';
	$font_file = trailingslashit( $directory ) . 'dieyu-echo.woff2';

	if ( is_file( $font_file ) ) {
		@unlink( $font_file );
	}

	if ( is_dir( $directory ) ) {
		@rmdir( $directory );
	}
}
