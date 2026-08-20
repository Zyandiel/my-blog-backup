<?php
/**
 * Dependencies for the unbundled block-editor script.
 *
 * @package UserIdentityLabels
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-components',
		'wp-block-editor',
		'wp-i18n',
	),
	'version'      => '1.0.0',
);
