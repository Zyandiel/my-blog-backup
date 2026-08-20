<?php
/**
 * Core plugin functionality.
 *
 * @package UserIdentityLabels
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates storage, rendering, shortcodes, blocks, and integrations.
 */
final class UIL_Plugin {

	/** Option holding the global identity-label library. */
	const LABELS_OPTION = 'uil_identity_labels';

	/** Option holding display and permission settings. */
	const SETTINGS_OPTION = 'uil_settings';

	/** User-meta key holding assigned label IDs. */
	const USER_META_KEY = 'uil_identity_label_ids';

	/** @var UIL_Plugin|null */
	private static $instance = null;

	/** @var UIL_Admin|null */
	private $admin = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return UIL_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'allow_self_edit'     => 0,
			'show_user_column'    => 1,
			'auto_argon_sidebar'  => 1,
			'argon_user_id'       => 1,
			'auto_author_archive' => 1,
			'auto_post_author'    => 1,
			'auto_buddypress'     => 1,
			'auto_bbpress'        => 1,
			'dom_selector'        => '',
			'dom_user_source'     => 'displayed',
			'dom_position'        => 'afterend',
		);
	}

	/**
	 * Registers hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_shortcodes_and_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_dom_template' ), 5 );
		add_filter( 'get_the_archive_title', array( $this, 'append_to_author_archive_title' ), 20 );
		add_filter( 'render_block_core/post-author-name', array( $this, 'append_to_post_author_block' ), 20, 3 );
		add_action( 'bp_profile_header_meta', array( $this, 'render_buddypress_labels' ), 20 );
		add_action( 'bbp_theme_after_reply_author_details', array( $this, 'render_bbpress_labels' ), 20 );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_eraser' ) );
		add_action( 'remove_user_from_blog', array( $this, 'remove_user_site_assignments' ), 10, 2 );

		if ( is_admin() ) {
			$this->admin = new UIL_Admin( $this );
		}
	}

	/**
	 * Returns normalized settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, self::get_default_settings() );
		foreach ( array( 'allow_self_edit', 'show_user_column', 'auto_argon_sidebar', 'auto_author_archive', 'auto_post_author', 'auto_buddypress', 'auto_bbpress' ) as $boolean_key ) {
			$settings[ $boolean_key ] = empty( $settings[ $boolean_key ] ) ? 0 : 1;
		}
		$settings['argon_user_id']    = absint( $settings['argon_user_id'] );
		$settings['dom_selector']    = is_scalar( $settings['dom_selector'] ) ? (string) $settings['dom_selector'] : '';
		$settings['dom_user_source'] = $this->sanitize_context( $settings['dom_user_source'], 'displayed' );
		$settings['dom_position']    = is_scalar( $settings['dom_position'] ) && in_array( $settings['dom_position'], array( 'afterend', 'beforeend' ), true )
			? $settings['dom_position']
			: 'afterend';

		return $settings;
	}

	/**
	 * Returns the sorted and normalized global label definitions.
	 *
	 * @param bool $apply_filter Whether to apply the public display/assignment filter.
	 * @return array
	 */
	public function get_label_definitions( $apply_filter = true ) {
		$stored = get_option( self::LABELS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$labels = array();
		foreach ( $stored as $id => $label ) {
			$id   = is_scalar( $id ) ? sanitize_key( $id ) : '';
			$name = is_array( $label ) && isset( $label['name'] ) && is_scalar( $label['name'] )
				? sanitize_text_field( $label['name'] )
				: '';
			if ( '' === $id || '' === $name ) {
				continue;
			}

			$background = isset( $label['color'] ) && is_scalar( $label['color'] ) ? sanitize_hex_color( $label['color'] ) : '';
			$text_color = isset( $label['text_color'] ) && is_scalar( $label['text_color'] ) ? sanitize_hex_color( $label['text_color'] ) : '';
			$labels[ $id ] = array(
				'id'          => $id,
				'name'        => $name,
				'description' => isset( $label['description'] ) && is_scalar( $label['description'] ) ? sanitize_textarea_field( $label['description'] ) : '',
				'color'       => $background ? $background : '#3858e9',
				'text_color'  => $text_color ? $text_color : '#ffffff',
				'order'       => isset( $label['order'] ) && is_scalar( $label['order'] ) ? (int) $label['order'] : 10,
			);
		}

		uasort(
			$labels,
			static function ( $first, $second ) {
				if ( $first['order'] === $second['order'] ) {
					return strnatcasecmp( $first['name'], $second['name'] );
				}
				return $first['order'] <=> $second['order'];
			}
		);

		if ( ! $apply_filter ) {
			return $labels;
		}

		/**
		 * Filters global identity-label definitions for display and assignment.
		 * Storage write paths always request the unfiltered definitions.
		 *
		 * @param array $labels Label definitions keyed by internal label ID.
		 */
		return apply_filters( 'uil_label_definitions', $labels );
	}

	/**
	 * Returns validated label IDs assigned to a user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_user_label_ids( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array();
		}
		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return array();
		}

		$assigned = get_user_option( self::USER_META_KEY, $user_id );
		if ( ! is_array( $assigned ) ) {
			return array();
		}

		$definitions = $this->get_label_definitions();
		$valid       = array();
		foreach ( $assigned as $label_id ) {
			if ( ! is_scalar( $label_id ) ) {
				continue;
			}
			$label_id = sanitize_key( $label_id );
			if ( isset( $definitions[ $label_id ] ) ) {
				$valid[] = $label_id;
			}
		}

		$valid = array_values( array_unique( $valid ) );

		/**
		 * Filters assigned identity-label IDs.
		 *
		 * @param array $valid   Valid label IDs.
		 * @param int   $user_id User ID.
		 */
		return apply_filters( 'uil_user_label_ids', $valid, $user_id );
	}

	/**
	 * Returns full label data for a user in global label order.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_user_labels( $user_id ) {
		$definitions = $this->get_label_definitions();
		$assigned    = array_flip( $this->get_user_label_ids( $user_id ) );
		$labels      = array();

		foreach ( $definitions as $label_id => $label ) {
			if ( isset( $assigned[ $label_id ] ) ) {
				$labels[ $label_id ] = $label;
			}
		}

		return $labels;
	}

	/**
	 * Determines the user represented by the current page.
	 *
	 * @param int    $explicit_user_id An explicitly supplied user ID.
	 * @param string $context          Resolution mode: auto, displayed, post_author, or current.
	 * @return int
	 */
	public function resolve_context_user_id( $explicit_user_id = 0, $context = 'auto' ) {
		$explicit_user_id = absint( $explicit_user_id );
		if ( $explicit_user_id ) {
			return get_userdata( $explicit_user_id ) ? $explicit_user_id : 0;
		}

		$allowed_contexts = array( 'auto', 'displayed', 'post_author', 'current' );
		$context          = in_array( $context, $allowed_contexts, true ) ? $context : 'auto';

		if ( 'current' === $context ) {
			return get_current_user_id();
		}

		if ( in_array( $context, array( 'auto', 'displayed' ), true ) && function_exists( 'bp_displayed_user_id' ) ) {
			$displayed_user_id = absint( bp_displayed_user_id() );
			if ( $displayed_user_id ) {
				return $displayed_user_id;
			}
		}

		if ( in_array( $context, array( 'auto', 'displayed' ), true ) && is_author() ) {
			$author_id = absint( get_queried_object_id() );
			if ( $author_id ) {
				return $author_id;
			}
		}

		if ( in_array( $context, array( 'auto', 'post_author' ), true ) && is_singular() ) {
			$post_id   = get_queried_object_id();
			$author_id = absint( get_post_field( 'post_author', $post_id ) );
			if ( $author_id ) {
				return $author_id;
			}
		}

		return 'auto' === $context ? get_current_user_id() : 0;
	}

	/**
	 * Returns the HTML allow-list used when echoing plugin-generated markup.
	 *
	 * @return array
	 */
	public function get_allowed_label_html() {
		return array(
			'div'  => array(
				'class'             => true,
				'data-uil-user-id'  => true,
				'data-uil-rendered' => true,
			),
			'span' => array(
				'class'             => true,
				'role'              => true,
				'aria-label'        => true,
				'style'             => true,
				'data-uil-user-id'  => true,
				'data-uil-rendered' => true,
			),
		);
	}

	/**
	 * Renders a user's identity labels.
	 *
	 * @param int   $user_id User ID, or zero for context resolution.
	 * @param array $args    Rendering arguments.
	 * @return string
	 */
	public function render_labels( $user_id = 0, $args = array() ) {
		$defaults = array(
			'class'      => '',
			'size'       => 'normal',
			'empty_text' => '',
			'context'    => 'auto',
		);
		$args     = wp_parse_args( $args, $defaults );
		$user_id  = $this->resolve_context_user_id( $user_id, $args['context'] );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return '';
		}

		$labels   = $this->get_user_labels( $user_id );

		if ( empty( $labels ) ) {
			if ( '' === trim( (string) $args['empty_text'] ) ) {
				return '';
			}

			return sprintf(
				'<span class="uil-labels uil-labels--empty" data-uil-user-id="%1$d" data-uil-rendered="1">%2$s</span>',
				$user_id,
				esc_html( $args['empty_text'] )
			);
		}

		$classes = array( 'uil-labels' );
		if ( 'compact' === $args['size'] ) {
			$classes[] = 'uil-labels--compact';
		}
		if ( ! empty( $args['class'] ) ) {
			foreach ( preg_split( '/\s+/', (string) $args['class'] ) as $class_name ) {
				$class_name = sanitize_html_class( $class_name );
				if ( $class_name ) {
					$classes[] = $class_name;
				}
			}
		}

		$html = sprintf(
			'<span class="%1$s" role="list" aria-label="%2$s" data-uil-user-id="%3$d" data-uil-rendered="1">',
			esc_attr( implode( ' ', array_unique( $classes ) ) ),
			esc_attr__( '身份标签', 'user-identity-labels' ),
			$user_id
		);

		foreach ( $labels as $label ) {
			$style = sprintf(
				'background-color:%1$s;color:%2$s;',
				$label['color'],
				$label['text_color']
			);
			$html .= sprintf(
				'<span class="uil-label" role="listitem" style="%1$s">%2$s</span>',
				esc_attr( $style ),
				esc_html( $label['name'] )
			);
		}

		$html .= '</span>';

		/**
		 * Filters final identity-label markup.
		 *
		 * @param string $html    Escaped label markup.
		 * @param int    $user_id User ID.
		 * @param array  $args    Rendering arguments.
		 */
		return apply_filters( 'uil_rendered_labels', $html, $user_id, $args );
	}

	/**
	 * Renders the numeric user ID with labels below it.
	 *
	 * @param int   $user_id User ID.
	 * @param array $args    Rendering arguments.
	 * @return string
	 */
	public function render_identity_card( $user_id = 0, $args = array() ) {
		$context = isset( $args['context'] ) ? $args['context'] : 'auto';
		$user_id = $this->resolve_context_user_id( $user_id, $context );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return '';
		}

		$labels = $this->render_labels( $user_id, $args );
		if ( '' === $labels && empty( $args['show_when_empty'] ) ) {
			return '';
		}

		return sprintf(
			'<div class="uil-identity-card" data-uil-user-id="%1$d"><span class="uil-user-id">%2$s</span>%3$s</div>',
			$user_id,
			esc_html( sprintf( /* translators: %d: numeric WordPress user ID. */ __( 'ID: %d', 'user-identity-labels' ), $user_id ) ),
			$labels
		);
	}

	/**
	 * Registers shortcodes and the dynamic block.
	 *
	 * @return void
	 */
	public function register_shortcodes_and_block() {
		add_shortcode( 'identity_labels', array( $this, 'shortcode_identity_labels' ) );
		add_shortcode( 'user_identity_labels', array( $this, 'shortcode_identity_labels' ) );
		add_shortcode( 'user_identity', array( $this, 'shortcode_identity_card' ) );

		if ( function_exists( 'register_block_type' ) ) {
			register_block_type(
				UIL_PATH . 'blocks/identity-labels',
				array( 'render_callback' => array( $this, 'render_identity_labels_block' ) )
			);
		}
	}

	/**
	 * Shortcode callback for labels only.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @return string
	 */
	public function shortcode_identity_labels( $attributes ) {
		$attributes = shortcode_atts(
			array(
				'user_id'    => 0,
				'id'         => 0,
				'size'       => 'normal',
				'class'      => '',
				'empty_text' => '',
				'context'    => 'auto',
			),
			(array) $attributes,
			'identity_labels'
		);
		$user_id    = absint( $attributes['user_id'] ? $attributes['user_id'] : $attributes['id'] );

		return $this->render_labels(
			$user_id,
			array(
				'size'       => $attributes['size'],
				'class'      => $attributes['class'],
				'empty_text' => $attributes['empty_text'],
				'context'    => $this->sanitize_context( $attributes['context'] ),
			)
		);
	}

	/**
	 * Shortcode callback for an ID-and-label card.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @return string
	 */
	public function shortcode_identity_card( $attributes ) {
		$attributes = shortcode_atts(
			array(
				'user_id'        => 0,
				'id'             => 0,
				'size'           => 'normal',
				'class'          => '',
				'empty_text'     => '',
				'show_when_empty' => 0,
				'context'         => 'auto',
			),
			(array) $attributes,
			'user_identity'
		);
		$user_id    = absint( $attributes['user_id'] ? $attributes['user_id'] : $attributes['id'] );

		return $this->render_identity_card(
			$user_id,
			array(
				'size'           => $attributes['size'],
				'class'          => $attributes['class'],
				'empty_text'     => $attributes['empty_text'],
				'show_when_empty' => rest_sanitize_boolean( $attributes['show_when_empty'] ),
				'context'         => $this->sanitize_context( $attributes['context'] ),
			)
		);
	}

	/**
	 * Dynamic block render callback.
	 *
	 * @param array         $attributes Block attributes.
	 * @param string        $content    Saved content (unused for dynamic block).
	 * @param WP_Block|null $block      Block instance.
	 * @return string
	 */
	public function render_identity_labels_block( $attributes, $content = '', $block = null ) {
		$user_id = ! empty( $attributes['userId'] ) ? absint( $attributes['userId'] ) : 0;
		if ( ! $user_id && $block instanceof WP_Block && ! empty( $block->context['postId'] ) ) {
			$user_id = absint( get_post_field( 'post_author', absint( $block->context['postId'] ) ) );
			if ( ! $user_id ) {
				return '';
			}
		}

		$args = array(
			'class'      => 'uil-labels--block',
			'empty_text' => isset( $attributes['emptyText'] ) ? sanitize_text_field( $attributes['emptyText'] ) : '',
			'context'    => $user_id ? 'auto' : 'post_author',
		);

		$content = ! empty( $attributes['showId'] )
			? $this->render_identity_card( $user_id, $args )
			: $this->render_labels( $user_id, $args );
		if ( '' === $content ) {
			return '';
		}

		return sprintf(
			'<div %1$s>%2$s</div>',
			get_block_wrapper_attributes( array( 'class' => 'uil-identity-labels-block' ) ),
			$content
		);
	}

	/**
	 * Enqueues the small public stylesheet and optional DOM-placement script.
	 *
	 * @return void
	 */
	public function enqueue_public_assets() {
		wp_enqueue_style( 'uil-public', UIL_URL . 'assets/css/public.css', array(), UIL_VERSION );

		$settings = $this->get_settings();
		if ( '' !== trim( $settings['dom_selector'] ) || ( ! empty( $settings['auto_argon_sidebar'] ) && $this->is_argon_theme() ) ) {
			wp_enqueue_script( 'uil-public', UIL_URL . 'assets/js/public.js', array(), UIL_VERSION, true );
		}
	}

	/**
	 * Adds labels below the title on author archive pages.
	 *
	 * @param string $title Archive title.
	 * @return string
	 */
	public function append_to_author_archive_title( $title ) {
		$settings = $this->get_settings();
		if ( is_admin() || empty( $settings['auto_author_archive'] ) || ! is_author() ) {
			return $title;
		}

		$user_id = absint( get_queried_object_id() );
		if ( ! $user_id ) {
			return $title;
		}

		$labels = $this->render_labels( $user_id, array( 'class' => 'uil-labels--archive' ) );
		if ( '' === $labels ) {
			return $title;
		}

		return $title . '<span class="uil-auto-wrap uil-auto-wrap--archive">' . $labels . '</span>';
	}

	/**
	 * Adds labels immediately after the core Post Author Name block.
	 *
	 * @param string        $block_content Rendered block HTML.
	 * @param array         $block         Parsed block.
	 * @param WP_Block|null $instance      Block instance when supplied by WordPress 5.9+.
	 * @return string
	 */
	public function append_to_post_author_block( $block_content, $block, $instance = null ) {
		$settings = $this->get_settings();
		if ( is_admin() || empty( $settings['auto_post_author'] ) ) {
			return $block_content;
		}

		$post_id = $instance instanceof WP_Block && ! empty( $instance->context['postId'] )
			? absint( $instance->context['postId'] )
			: ( isset( $block['attrs']['postId'] ) ? absint( $block['attrs']['postId'] ) : get_the_ID() );
		$user_id = absint( get_post_field( 'post_author', $post_id ) );
		if ( ! $user_id ) {
			return $block_content;
		}
		$labels  = $this->render_labels( $user_id, array( 'class' => 'uil-labels--post-author' ) );
		if ( '' === $labels ) {
			return $block_content;
		}

		return $block_content . '<span class="uil-auto-wrap uil-auto-wrap--post-author">' . $labels . '</span>';
	}

	/**
	 * Outputs a template that the selector-based fallback inserts below a theme element.
	 *
	 * @return void
	 */
	public function render_dom_template() {
		$settings = $this->get_settings();
		$selector = trim( (string) $settings['dom_selector'] );

		if ( '' !== $selector ) {
			$source  = isset( $settings['dom_user_source'] ) ? $this->sanitize_context( $settings['dom_user_source'], 'displayed' ) : 'displayed';
			$user_id = $this->resolve_context_user_id( 0, $source );
			$labels  = $this->render_labels(
				$user_id,
				array(
					'class'   => 'uil-labels--selector',
					'context' => $source,
				)
			);
			if ( '' !== $labels ) {
				$position = in_array( $settings['dom_position'], array( 'afterend', 'beforeend' ), true ) ? $settings['dom_position'] : 'afterend';
				$this->print_dom_template( 'custom', $selector, $position, $user_id, $labels, 'selector' );
			}
		}

		if ( ! empty( $settings['auto_argon_sidebar'] ) && $this->is_argon_theme() ) {
			$user_id = absint( $settings['argon_user_id'] );
			$labels  = $this->render_labels( $user_id, array( 'class' => 'uil-labels--argon' ) );
			if ( '' !== $labels ) {
				$this->print_dom_template( 'argon', '#leftbar_overview_author_name', 'afterend', $user_id, $labels, 'argon' );
			}
		}
	}

	/**
	 * Prints one safe placement template consumed by public.js.
	 *
	 * @param string $id       Unique template suffix.
	 * @param string $selector Target CSS selector.
	 * @param string $position insertAdjacentElement position.
	 * @param int    $user_id  Displayed user ID.
	 * @param string $labels   Rendered label markup.
	 * @param string $variant  Wrapper modifier.
	 * @return void
	 */
	private function print_dom_template( $id, $selector, $position, $user_id, $labels, $variant ) {
		printf(
			'<template id="uil-dom-template-%1$s" class="uil-dom-template" data-selector="%2$s" data-position="%3$s" data-user-id="%4$d"><span class="uil-auto-wrap uil-auto-wrap--%5$s">%6$s</span></template>',
			esc_attr( sanitize_html_class( $id ) ),
			esc_attr( $selector ),
			esc_attr( $position ),
			absint( $user_id ),
			esc_attr( sanitize_html_class( $variant ) ),
			wp_kses( $labels, $this->get_allowed_label_html() )
		);
	}

	/**
	 * Detects the Argon WordPress theme (including child themes).
	 *
	 * @return bool
	 */
	private function is_argon_theme() {
		$theme = wp_get_theme();
		$values = array(
			$theme->get( 'Name' ),
			$theme->get( 'TextDomain' ),
			$theme->get_stylesheet(),
			$theme->get_template(),
		);
		foreach ( $values as $value ) {
			if ( false !== stripos( (string) $value, 'argon' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * BuddyPress profile-header integration.
	 *
	 * @return void
	 */
	public function render_buddypress_labels() {
		$settings = $this->get_settings();
		if ( empty( $settings['auto_buddypress'] ) || ! function_exists( 'bp_displayed_user_id' ) ) {
			return;
		}

		$user_id = absint( bp_displayed_user_id() );
		if ( ! $user_id ) {
			return;
		}

		$labels = $this->render_labels( $user_id, array( 'class' => 'uil-labels--buddypress' ) );
		if ( $labels ) {
			echo '<div class="uil-auto-wrap uil-auto-wrap--buddypress">' . wp_kses( $labels, $this->get_allowed_label_html() ) . '</div>';
		}
	}

	/**
	 * bbPress reply-author integration.
	 *
	 * @return void
	 */
	public function render_bbpress_labels() {
		$settings = $this->get_settings();
		if ( empty( $settings['auto_bbpress'] ) || ! function_exists( 'bbp_get_reply_author_id' ) ) {
			return;
		}

		$user_id = absint( bbp_get_reply_author_id() );
		if ( ! $user_id ) {
			return;
		}

		$labels = $this->render_labels( $user_id, array( 'class' => 'uil-labels--bbpress uil-labels--compact' ) );
		if ( $labels ) {
			echo '<div class="uil-auto-wrap uil-auto-wrap--bbpress">' . wp_kses( $labels, $this->get_allowed_label_html() ) . '</div>';
		}
	}

	/**
	 * Registers identity labels with WordPress's personal-data exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_privacy_exporter( $exporters ) {
		$exporters['uil-identity-labels'] = array(
			'exporter_friendly_name' => __( '用户身份标签', 'user-identity-labels' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Exports a user's assigned, publicly visible identity-label names.
	 *
	 * @param string $email_address User email.
	 * @return array
	 */
	public function export_personal_data( $email_address ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}

		$labels = wp_list_pluck( $this->get_user_labels( $user->ID ), 'name' );
		if ( empty( $labels ) ) {
			return array( 'data' => array(), 'done' => true );
		}

		return array(
			'data' => array(
				array(
					'group_id'    => 'uil-identity-labels',
					'group_label' => __( '用户身份标签', 'user-identity-labels' ),
					'item_id'     => 'uil-user-' . $user->ID,
					'data'        => array(
						array(
							'name'  => __( '已分配的身份', 'user-identity-labels' ),
							'value' => implode( '、', $labels ),
						),
					),
				),
			),
			'done' => true,
		);
	}

	/**
	 * Registers identity assignments with the personal-data eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_privacy_eraser( $erasers ) {
		$erasers['uil-identity-labels'] = array(
			'eraser_friendly_name' => __( '用户身份标签', 'user-identity-labels' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Removes a user's assignments without deleting the shared label library.
	 *
	 * @param string $email_address User email.
	 * @return array
	 */
	public function erase_personal_data( $email_address ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$had_labels = ! empty( $this->get_user_label_ids( $user->ID ) );
		delete_user_option( $user->ID, self::USER_META_KEY, false );

		return array(
			'items_removed'  => $had_labels,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Removes this site's assignments when a user leaves a multisite site.
	 *
	 * @param int $user_id User being removed.
	 * @param int $blog_id Site the user is being removed from.
	 * @return void
	 */
	public function remove_user_site_assignments( $user_id, $blog_id ) {
		$user_id = absint( $user_id );
		$blog_id = absint( $blog_id );
		if ( ! $user_id ) {
			return;
		}
		if ( ! $blog_id ) {
			$blog_id = get_current_blog_id();
		}

		if ( get_current_blog_id() === $blog_id ) {
			delete_user_option( $user_id, self::USER_META_KEY, false );
			return;
		}

		switch_to_blog( $blog_id );
		delete_user_option( $user_id, self::USER_META_KEY, false );
		restore_current_blog();
	}

	/**
	 * Validates a public user-resolution mode.
	 *
	 * @param string $context  Requested context.
	 * @param string $fallback Fallback context.
	 * @return string
	 */
	private function sanitize_context( $context, $fallback = 'auto' ) {
		$context = is_scalar( $context ) ? sanitize_key( $context ) : '';
		return in_array( $context, array( 'auto', 'displayed', 'post_author', 'current' ), true ) ? $context : $fallback;
	}
}
