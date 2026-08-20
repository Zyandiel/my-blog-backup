<?php
/**
 * Plugin Name:       用户身份标签
 * Description:       为 WordPress 用户创建独立的身份标签，并显示在用户 ID 或昵称下方；不会使用或影响文章标签。
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Codex
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       user-identity-labels
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UIL_VERSION', '1.0.0' );
define( 'UIL_FILE', __FILE__ );
define( 'UIL_PATH', plugin_dir_path( __FILE__ ) );
define( 'UIL_URL', plugin_dir_url( __FILE__ ) );

require_once UIL_PATH . 'includes/class-uil-plugin.php';
require_once UIL_PATH . 'includes/class-uil-admin.php';

/**
 * Returns the plugin instance.
 *
 * @return UIL_Plugin
 */
function uil_plugin() {
	return UIL_Plugin::instance();
}

add_action( 'plugins_loaded', 'uil_plugin' );

/**
 * Returns the identity-label markup for a user.
 *
 * @param int   $user_id User ID. Zero uses the current page context.
 * @param array $args    Optional rendering arguments.
 * @return string
 */
function uil_get_user_identity_labels( $user_id = 0, $args = array() ) {
	return uil_plugin()->render_labels( $user_id, $args );
}

/**
 * Echoes the identity labels for a user.
 *
 * @param int   $user_id User ID. Zero uses the current page context.
 * @param array $args    Optional rendering arguments.
 * @return void
 */
function uil_user_identity_labels( $user_id = 0, $args = array() ) {
	echo wp_kses( uil_get_user_identity_labels( $user_id, $args ), uil_plugin()->get_allowed_label_html() );
}

/**
 * Adds the initial options without deleting existing data.
 *
 * @return void
 */
function uil_activate() {
	add_option( UIL_Plugin::LABELS_OPTION, array(), '', false );
	add_option( UIL_Plugin::SETTINGS_OPTION, UIL_Plugin::get_default_settings(), '', false );
}

register_activation_hook( __FILE__, 'uil_activate' );
