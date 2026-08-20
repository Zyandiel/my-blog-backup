<?php
/**
 * Plugin Name: 蝶语保留格式摘要
 * Description: 为 Argon 主题的文章卡片保留手写摘要以及自动摘要中的换行、空行和段落间隔。
 * Version: 1.0.0
 * Author: 小蝶的树叶
 * License: GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: dieyu-preserved-excerpts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dieyu_Preserved_Excerpts {
	const VERSION = '1.0.0';

	/**
	 * Object IDs already processed during this request.
	 *
	 * @var array<int, bool>
	 */
	private $processed_objects = array();

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'the_posts', array( $this, 'prepare_argon_previews' ), 99, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Prepare formatted excerpts before Argon renders its preview templates.
	 *
	 * The changes are in-memory only. Nothing is written back to the database.
	 *
	 * @param WP_Post[] $posts Posts returned by the query.
	 * @param WP_Query  $query Current query.
	 * @return WP_Post[]
	 */
	public function prepare_argon_previews( $posts, $query ) {
		if ( ! $this->should_process_query( $query ) || empty( $posts ) ) {
			return $posts;
		}

		$limit = (int) get_option( 'argon_trim_words_count', 175 );
		if ( $limit <= 0 ) {
			return $posts;
		}

		$limit = min( 5000, $limit );

		foreach ( $posts as $post ) {
			if ( ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) {
				continue;
			}

			$object_id = spl_object_id( $post );
			if ( isset( $this->processed_objects[ $object_id ] ) ) {
				continue;
			}
			$this->processed_objects[ $object_id ] = true;

			// Let Argon keep control of protected-post messages.
			if ( '' !== (string) $post->post_password ) {
				continue;
			}

			$manual_excerpt = (string) $post->post_excerpt;
			if ( '' !== trim( $manual_excerpt ) ) {
				$post->post_excerpt = $this->format_manual_excerpt( $manual_excerpt );
				continue;
			}

			$source = $this->content_to_preserved_text( (string) $post->post_content );
			if ( '' === $source ) {
				continue;
			}

			$preview = $this->trim_preserving_breaks( $source, $limit );
			if ( '' !== $preview ) {
				$post->post_excerpt = '<span class="dieyu-preserved-excerpt dieyu-preserved-excerpt-auto">' . esc_html( $preview ) . '</span>';
			}
		}

		return $posts;
	}

	/**
	 * Decide whether this is an Argon article-list query.
	 *
	 * @param mixed $query Query object.
	 * @return bool
	 */
	private function should_process_query( $query ) {
		if ( is_admin() || ! ( $query instanceof WP_Query ) || ! $query->is_main_query() ) {
			return false;
		}

		if ( $query->is_singular() || $query->is_feed() || $query->is_embed() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		return $this->is_argon_theme();
	}

	/**
	 * Detect the active Argon theme, including child themes.
	 *
	 * @return bool
	 */
	private function is_argon_theme() {
		$theme      = wp_get_theme();
		$candidates = array(
			$theme->get( 'Name' ),
			$theme->get( 'TextDomain' ),
			$theme->get_stylesheet(),
			$theme->get_template(),
		);

		foreach ( $candidates as $candidate ) {
			if ( false !== stripos( (string) $candidate, 'argon' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Preserve manual-excerpt text and safe HTML exactly as entered.
	 *
	 * @param string $excerpt Saved manual excerpt.
	 * @return string
	 */
	private function format_manual_excerpt( $excerpt ) {
		$excerpt = preg_replace( "/\r\n?/", "\n", $excerpt );

		return '<span class="dieyu-preserved-excerpt dieyu-preserved-excerpt-manual">' . wp_kses_post( $excerpt ) . '</span>';
	}

	/**
	 * Convert post content to clean text while retaining meaningful breaks.
	 *
	 * @param string $content Raw post content.
	 * @return string
	 */
	private function content_to_preserved_text( $content ) {
		// Match Argon's existing behavior: content after <!--more--> is not part
		// of the card preview.
		$parts   = preg_split( '/<!--more(.*?)?-->/is', $content, 2 );
		$content = isset( $parts[0] ) ? $parts[0] : $content;

		$content = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', '', $content );
		$content = strip_shortcodes( $content );

		// Gutenberg block comments do not carry visible text.
		$content = preg_replace( '/<!--\s*\/?wp:.*?-->/s', '', $content );

		// Preserve soft line breaks and paragraph-level boundaries before tags
		// are removed.
		$content = preg_replace( '/<\s*br\s*\/?\s*>/i', "\n", $content );
		$content = preg_replace( '/<\s*hr\b[^>]*>/i', "\n\n", $content );
		$content = preg_replace( '/<\s*li\b[^>]*>/i', '• ', $content );
		$content = preg_replace( '/<\/\s*li\s*>/i', "\n", $content );
		$content = preg_replace(
			'/<\/\s*(?:p|div|section|article|header|footer|h[1-6]|blockquote|pre|figure|figcaption|ul|ol|table|tr)\s*>/i',
			"\n\n",
			$content
		);

		$content = wp_strip_all_tags( $content, false );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$content = wp_check_invalid_utf8( $content, true );
		$content = str_replace( "\xC2\xA0", ' ', $content );
		$content = preg_replace( "/\r\n?/", "\n", $content );

		$lines = explode( "\n", $content );
		foreach ( $lines as &$line ) {
			$line = preg_replace( '/[^\S\n]+/u', ' ', $line );
			$line = trim( $line );
		}
		unset( $line );

		$content = implode( "\n", $lines );
		$content = preg_replace( "/\n{3,}/", "\n\n", $content );

		return trim( $content );
	}

	/**
	 * Trim text without flattening its line breaks.
	 *
	 * @param string $text  Clean source text.
	 * @param int    $limit Argon preview-length setting.
	 * @return string
	 */
	private function trim_preserving_breaks( $text, $limit ) {
		$count_type = _x( 'words', 'Word count type. Do not translate!' );

		if ( 0 === strpos( $count_type, 'characters' ) ) {
			return $this->trim_by_characters( $text, $limit, 'characters_including_spaces' === $count_type );
		}

		return $this->trim_by_words( $text, $limit );
	}

	/**
	 * Character-aware trimming for Chinese and similar locales.
	 *
	 * @param string $text           Source text.
	 * @param int    $limit          Maximum count.
	 * @param bool   $include_spaces Whether whitespace counts toward the limit.
	 * @return string
	 */
	private function trim_by_characters( $text, $limit, $include_spaces ) {
		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $characters ) {
			return $this->trim_by_words( $text, $limit );
		}

		$result    = '';
		$count     = 0;
		$truncated = false;

		foreach ( $characters as $character ) {
			$is_space = 1 === preg_match( '/\s/u', $character );
			if ( $include_spaces || ! $is_space ) {
				if ( $count >= $limit ) {
					$truncated = true;
					break;
				}
				++$count;
			}

			$result .= $character;
		}

		$result = rtrim( $result );
		return $truncated ? $result . '…' : $result;
	}

	/**
	 * Word-aware fallback for space-delimited locales.
	 *
	 * @param string $text  Source text.
	 * @param int    $limit Maximum word count.
	 * @return string
	 */
	private function trim_by_words( $text, $limit ) {
		$parts = preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( false === $parts ) {
			return $text;
		}

		$result    = '';
		$count     = 0;
		$truncated = false;

		foreach ( $parts as $part ) {
			if ( 1 !== preg_match( '/^\s+$/u', $part ) ) {
				if ( $count >= $limit ) {
					$truncated = true;
					break;
				}
				++$count;
			}

			$result .= $part;
		}

		$result = rtrim( $result );
		return $truncated ? $result . '…' : $result;
	}

	/**
	 * Add one small style rule so line breaks and repeated spaces render.
	 */
	public function enqueue_styles() {
		if ( is_admin() || ! $this->is_argon_theme() ) {
			return;
		}

		$css = <<<'CSS'
.post-content .dieyu-preserved-excerpt {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}
CSS;

		wp_register_style( 'dieyu-preserved-excerpts', false, array(), self::VERSION );
		wp_enqueue_style( 'dieyu-preserved-excerpts' );
		wp_add_inline_style( 'dieyu-preserved-excerpts', $css );
	}
}

new Dieyu_Preserved_Excerpts();

