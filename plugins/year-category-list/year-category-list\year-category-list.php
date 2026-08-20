<?php
/**
 * Plugin Name: Year Category List
 * Description: Provides [year_category_list] to render four-digit top-level year categories in descending order with their descendants.
 * Version: 1.0.0
 * Author: Site Customization
 * License: GPL-2.0-or-later
 * Text Domain: year-category-list
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render year categories and their descendants as a theme-compatible list.
 *
 * @return string
 */
function ycl_render_year_category_list() {
	$years = get_terms(
		array(
			'taxonomy'   => 'category',
			'parent'     => 0,
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $years ) || empty( $years ) ) {
		return '';
	}

	$years = array_values(
		array_filter(
			$years,
			static function ( $term ) {
				return 1 === preg_match( '/^\d{4}$/', $term->name );
			}
		)
	);

	usort(
		$years,
		static function ( $left, $right ) {
			return strnatcasecmp( $right->name, $left->name );
		}
	);

	if ( empty( $years ) ) {
		return '';
	}

	$output = '<ul class="wp-block-categories-list wp-block-categories-taxonomy-category wp-block-categories">';

	foreach ( $years as $year ) {
		$year_link = get_category_link( $year->term_id );

		if ( is_wp_error( $year_link ) ) {
			continue;
		}

		$output .= sprintf(
			'<li class="cat-item cat-item-%1$d"><a href="%2$s">%3$s</a>',
			(int) $year->term_id,
			esc_url( $year_link ),
			esc_html( $year->name )
		);

		$children = wp_list_categories(
			array(
				'child_of'            => (int) $year->term_id,
				'depth'               => 0,
				'echo'                => false,
				'hide_empty'          => false,
				'hierarchical'        => true,
				'orderby'             => 'name',
				'order'               => 'ASC',
				'show_option_none'    => '',
				'title_li'            => '',
				'use_desc_for_title'  => false,
			)
		);

		if ( '' !== trim( $children ) ) {
			$output .= '<ul class="children">' . $children . '</ul>';
		}

		$output .= '</li>';
	}

	$output .= '</ul>';

	return $output;
}

add_shortcode( 'year_category_list', 'ycl_render_year_category_list' );
