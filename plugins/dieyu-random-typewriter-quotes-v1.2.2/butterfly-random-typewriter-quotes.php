<?php
/**
 * Plugin Name: 蝶语随机打字机
 * Description: 在 Argon Banner 或指定展示位直接输出随机语录，并提供逐字打字动画和闪烁光标。
 * Version: 1.2.2
 * Author: 小蝶的树叶
 * License: GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: butterfly-random-typewriter-quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Butterfly_Random_Typewriter_Quotes {
	const VERSION    = '1.2.2';
	const OPTION_KEY = 'butterfly_typewriter_quotes';
	const PAGE_SLUG  = 'butterfly-typewriter-quotes';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_shortcode( 'butterfly_quote', array( $this, 'render_shortcode' ) );
		add_filter( 'argon_banner_title_html', array( $this, 'render_argon_banner_title' ), 20, 1 );
		add_filter( 'option_argon_enable_banner_title_typing_effect', array( $this, 'disable_argon_typing_effect' ), 20, 1 );
		add_action( 'updated_option', array( $this, 'purge_cache_after_settings_update' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Default plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults() {
		return array(
			'quotes'            => "一只挣脱缰绳的蝴蝶，一个记录自由与真诚的博客\n愿你永远自由，永远真诚\n风会记得每一只认真飞过的蝴蝶\n把生活写成文字，也把自己还给远方",
			'argon_integration' => 1,
			'home_only'         => 1,
			'display_selector'  => '',
			'typing_speed'      => 90,
			'start_delay'       => 350,
			'punctuation_pause' => 260,
			'cursor'            => '|',
		);
	}

	/**
	 * Get settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
	}

	/**
	 * Get non-empty quotes.
	 *
	 * @param array<string, mixed>|null $settings Optional settings array.
	 * @return string[]
	 */
	private function get_quotes( $settings = null ) {
		if ( null === $settings ) {
			$settings = $this->get_settings();
		}

		$lines  = preg_split( '/\R/u', (string) $settings['quotes'] );
		$quotes = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$quotes[] = $line;
			}
		}

		return array_values( array_unique( $quotes ) );
	}

	/**
	 * Register the option and its sanitization callback.
	 */
	public function register_settings() {
		register_setting(
			'butterfly_typewriter_quotes_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->defaults(),
			)
		);
	}

	/**
	 * Validate and sanitize all settings.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$old   = $this->get_settings();
		$out   = $this->defaults();

		$raw_quotes = isset( $input['quotes'] ) ? wp_unslash( (string) $input['quotes'] ) : '';
		$lines      = preg_split( '/\R/u', $raw_quotes );
		$quotes     = array();

		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) {
				continue;
			}

			if ( function_exists( 'mb_substr' ) ) {
				$line = mb_substr( $line, 0, 500 );
			} else {
				$line = substr( $line, 0, 1500 );
			}

			$quotes[] = $line;
			if ( count( $quotes ) >= 300 ) {
				break;
			}
		}

		$quotes = array_values( array_unique( $quotes ) );
		if ( empty( $quotes ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'btq_empty_quotes',
				'语录库不能为空，已保留上一次的内容。',
				'error'
			);
			$out['quotes'] = (string) $old['quotes'];
		} else {
			$out['quotes'] = implode( "\n", $quotes );
		}

		$out['argon_integration'] = empty( $input['argon_integration'] ) ? 0 : 1;
		$out['home_only']         = empty( $input['home_only'] ) ? 0 : 1;
		$out['display_selector']  = isset( $input['display_selector'] ) ? sanitize_text_field( wp_unslash( $input['display_selector'] ) ) : '';
		$out['typing_speed']      = $this->clamp( isset( $input['typing_speed'] ) ? absint( $input['typing_speed'] ) : 90, 10, 500 );
		$out['start_delay']       = $this->clamp( isset( $input['start_delay'] ) ? absint( $input['start_delay'] ) : 350, 0, 5000 );
		$out['punctuation_pause'] = $this->clamp( isset( $input['punctuation_pause'] ) ? absint( $input['punctuation_pause'] ) : 260, 0, 2000 );

		$cursor = isset( $input['cursor'] ) ? sanitize_text_field( wp_unslash( $input['cursor'] ) ) : '|';
		if ( function_exists( 'mb_substr' ) ) {
			$cursor = mb_substr( $cursor, 0, 3 );
		} else {
			$cursor = substr( $cursor, 0, 9 );
		}
		$out['cursor'] = '' === $cursor ? '|' : $cursor;

		add_settings_error(
			self::OPTION_KEY,
			'btq_settings_saved',
			'设置已保存。刷新博客页面即可查看新的随机打字效果。',
			'updated'
		);

		return $out;
	}

	/**
	 * Clamp an integer to a range.
	 *
	 * @param int $value Value.
	 * @param int $min Minimum.
	 * @param int $max Maximum.
	 * @return int
	 */
	private function clamp( $value, $min, $max ) {
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Purge common WordPress/page caches when this plugin is activated.
	 */
	public static function activate() {
		self::purge_known_caches();
	}

	/**
	 * Purge cached frontend HTML after this plugin's settings are saved.
	 *
	 * @param string $option    Updated option name.
	 * @param mixed  $old_value Previous value.
	 * @param mixed  $value     New value.
	 */
	public function purge_cache_after_settings_update( $option, $old_value, $value ) {
		unset( $old_value, $value );

		if ( self::OPTION_KEY === $option ) {
			self::purge_known_caches();
		}
	}

	/**
	 * Clear core object cache and popular full-page cache plugins.
	 */
	private static function purge_known_caches() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( class_exists( 'autoptimizeCache' ) && is_callable( array( 'autoptimizeCache', 'clearall' ) ) ) {
			autoptimizeCache::clearall();
		}

		/**
		 * LiteSpeed Cache listens for this action when installed.
		 */
		do_action( 'litespeed_purge_all' );
	}

	/**
	 * Add the plugin settings page.
	 */
	public function add_settings_page() {
		add_options_page(
			'蝶语随机打字机',
			'蝶语打字机',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Add a settings shortcut on the Plugins page.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function add_settings_link( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">设置</a>' );
		return $links;
	}

	/**
	 * Render settings UI.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = $this->get_settings();
		$quote_count = count( $this->get_quotes( $settings ) );
		?>
		<div class="wrap btq-admin-wrap">
			<h1>蝶语随机打字机</h1>
			<p class="description">每次打开页面时从语录库随机抽取一句，并像光标输入一样逐字显示。随机选择在浏览器中完成，因此启用页面缓存也仍然有效。</p>

			<?php settings_errors( self::OPTION_KEY ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'butterfly_typewriter_quotes_group' ); ?>

				<div class="btq-card">
					<h2>语录库 <span class="btq-count"><?php echo esc_html( $quote_count ); ?> 句</span></h2>
					<p>每行填写一句。支持中文、标点和 Emoji；最多保存 300 句，每句最多 500 个字符。</p>
					<textarea id="btq-quotes" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[quotes]" rows="12" class="large-text" spellcheck="false"><?php echo esc_textarea( $settings['quotes'] ); ?></textarea>
				</div>

				<div class="btq-card">
					<h2>Argon Banner（推荐）</h2>
					<label class="btq-check">
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[argon_integration]" value="1" <?php checked( $settings['argon_integration'], 1 ); ?>>
						<strong>直接把随机语录显示在 Argon 的 Banner 标题位置</strong>
					</label>
					<p class="description">开启后不需要修改 Argon 的“Banner 标题”，也不用填写短代码。插件通过 Argon 官方提供的标题接口直接渲染语录，并在前台自动停用 Argon 自带的 Banner 打字动画，避免两套动画冲突。停用插件后，原 Banner 标题会自动恢复。</p>
					<label class="btq-check">
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[home_only]" value="1" <?php checked( $settings['home_only'], 1 ); ?>>
						只在网站首页接管 Argon Banner 和 CSS 展示位（短代码不受影响）
					</label>

					<hr class="btq-divider">
					<h2>其他主题或自定义位置</h2>
					<div class="btq-shortcode-note">
						在 WordPress“短代码”区块中输入 <code>[butterfly_quote]</code>，即可在任意位置直接输出语录。
					</div>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="btq-selector">CSS 展示位（可选）</label></th>
							<td>
								<input id="btq-selector" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[display_selector]" type="text" class="regular-text code" placeholder="例如：.site-description" value="<?php echo esc_attr( $settings['display_selector'] ); ?>">
								<p class="description">仅当主题横幅无法插入短代码时使用。请填写专门用来展示语录的空元素选择器；插件不会搜索或比较任何原始文案。</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="btq-card">
					<h2>打字动画</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="btq-speed">每个字的速度</label></th>
							<td><input id="btq-speed" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[typing_speed]" type="number" min="10" max="500" step="10" value="<?php echo esc_attr( $settings['typing_speed'] ); ?>"> 毫秒</td>
						</tr>
						<tr>
							<th scope="row"><label for="btq-delay">开始前等待</label></th>
							<td><input id="btq-delay" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[start_delay]" type="number" min="0" max="5000" step="50" value="<?php echo esc_attr( $settings['start_delay'] ); ?>"> 毫秒</td>
						</tr>
						<tr>
							<th scope="row"><label for="btq-pause">标点额外停顿</label></th>
							<td><input id="btq-pause" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[punctuation_pause]" type="number" min="0" max="2000" step="20" value="<?php echo esc_attr( $settings['punctuation_pause'] ); ?>"> 毫秒</td>
						</tr>
						<tr>
							<th scope="row"><label for="btq-cursor">光标字符</label></th>
							<td>
								<input id="btq-cursor" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cursor]" type="text" class="small-text" maxlength="3" value="<?php echo esc_attr( $settings['cursor'] ); ?>">
								<span class="description">光标只在打字过程中显示，最后一个字完成后会自动消失。</span>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( '保存设置' ); ?>
			</form>
		</div>

		<style>
			.btq-admin-wrap { max-width: 980px; }
			.btq-admin-wrap > .description { max-width: 820px; font-size: 14px; margin-bottom: 18px; }
			.btq-card { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; box-shadow: 0 1px 2px rgba(0,0,0,.04); margin: 16px 0; padding: 20px 22px; }
			.btq-card h2 { margin: 0 0 10px; }
			.btq-count { background: #f0e8ff; border-radius: 999px; color: #6b3fb3; font-size: 12px; font-weight: 600; margin-left: 6px; padding: 3px 9px; vertical-align: 2px; }
			.btq-card textarea { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; line-height: 1.8; margin-top: 8px; resize: vertical; }
			.btq-check { display: block; margin: 10px 0; }
			.btq-divider { border: 0; border-top: 1px solid #dcdcde; margin: 22px 0; }
			.btq-shortcode-note { background: #f6f7f7; border-left: 4px solid #8c6ac8; margin-top: 8px; padding: 12px 14px; }
			@media (max-width: 782px) { .btq-card { padding: 16px; } }
		</style>
		<?php
	}

	/**
	 * Print a placeholder for the random quote.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		$quotes   = $this->get_quotes();
		$fallback = ! empty( $quotes ) ? $quotes[0] : '';

		return '<span class="btq-shortcode"><span class="btq-text" aria-hidden="true"></span></span><noscript>' . esc_html( $fallback ) . '</noscript>';
	}

	/**
	 * Whether the plugin should own Argon's Banner title on this request.
	 *
	 * @return bool
	 */
	private function should_use_argon_integration() {
		if ( is_admin() ) {
			return false;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['argon_integration'] ) ) {
			return false;
		}

		return empty( $settings['home_only'] ) || is_front_page();
	}

	/**
	 * Render the plugin directly through Argon's official Banner title filter.
	 *
	 * @param string $title Original Argon Banner title HTML.
	 * @return string
	 */
	public function render_argon_banner_title( $title ) {
		if ( ! $this->should_use_argon_integration() ) {
			return $title;
		}

		return $this->render_shortcode();
	}

	/**
	 * Prevent Argon's own typewriter from running on the same Banner title.
	 *
	 * Argon checks for the literal string "true", so "false" selects the
	 * filter-enabled title path while the plugin integration is active.
	 *
	 * @param mixed $value Saved Argon option value.
	 * @return mixed
	 */
	public function disable_argon_typing_effect( $value ) {
		return $this->should_use_argon_integration() ? 'false' : $value;
	}

	/**
	 * Add the small inline stylesheet and script used on the public site.
	 */
	public function enqueue_frontend_assets() {
		if ( is_admin() ) {
			return;
		}

		$settings = $this->get_settings();
		$quotes   = $this->get_quotes( $settings );
		if ( empty( $quotes ) ) {
			return;
		}

		$display_selector = (string) $settings['display_selector'];
		if ( ! empty( $settings['home_only'] ) && ! is_front_page() ) {
			$display_selector = '';
		}

		$config = array(
			'quotes'            => $quotes,
			'displaySelector'   => $display_selector,
			'typingSpeed'       => (int) $settings['typing_speed'],
			'startDelay'        => (int) $settings['start_delay'],
			'punctuationPause'  => (int) $settings['punctuation_pause'],
			'cursor'            => (string) $settings['cursor'],
		);

		$css = <<<'CSS'
.btq-shortcode {
	display: inline;
}
.btq-shortcode .btq-text,
.btq-display-target .btq-text {
	white-space: pre-wrap;
}
.btq-caret {
	display: inline-block;
	font: inherit;
	line-height: 1;
	margin-left: .035em;
	transform: translateY(.03em);
	animation: btq-caret-blink .82s steps(1, end) infinite;
}
@keyframes btq-caret-blink {
	0%, 47% { opacity: 1; }
	48%, 100% { opacity: 0; }
}
CSS;

		$javascript = "(function(config){\n" . <<<'JS'
'use strict';

if (!config || !Array.isArray(config.quotes) || !config.quotes.length) {
	return;
}

var pageQuote = config.quotes[Math.floor(Math.random() * config.quotes.length)];
var punctuation = /[，。！？、；：,.!?;:…—]/u;
var observerTimer = 0;

function splitCharacters(value) {
	if (window.Intl && Intl.Segmenter) {
		var segmenter = new Intl.Segmenter(undefined, { granularity: 'grapheme' });
		return Array.from(segmenter.segment(value), function(part) { return part.segment; });
	}

	return Array.from(value);
}

function isPluginManagedElement(element) {
	if (!element || element.nodeType !== 1) {
		return false;
	}

	return Boolean(element.closest('[data-btq-initialized="1"]'));
}

function finish(element, caret, quote) {
	element.classList.remove('btq-is-typing');
	element.classList.add('btq-is-complete');

	if (caret.parentNode) {
		caret.parentNode.removeChild(caret);
	}

	element.dispatchEvent(new CustomEvent('btq:complete', {
		bubbles: true,
		detail: { quote: quote }
	}));
}

function typeQuote(element, quote) {
	if (!element || element.dataset.btqInitialized === '1') {
		return;
	}

	element.dataset.btqInitialized = '1';
	element.classList.add('btq-display-target', 'btq-is-typing');
	element.setAttribute('aria-label', quote);

	while (element.firstChild) {
		element.removeChild(element.firstChild);
	}

	var text = document.createElement('span');
	text.className = 'btq-text';
	text.setAttribute('aria-hidden', 'true');

	var caret = document.createElement('span');
	caret.className = 'btq-caret';
	caret.setAttribute('aria-hidden', 'true');
	caret.textContent = config.cursor || '|';

	element.appendChild(text);
	element.appendChild(caret);

	var characters = splitCharacters(quote);
	var index = 0;

	function nextCharacter() {
		if (index >= characters.length) {
			finish(element, caret, quote);
			return;
		}

		var character = characters[index];
		text.appendChild(document.createTextNode(character));
		index += 1;

		if (index >= characters.length) {
			finish(element, caret, quote);
			return;
		}

		var delay = Number(config.typingSpeed) || 90;
		if (punctuation.test(character)) {
			delay += Number(config.punctuationPause) || 0;
		} else if (/\s/u.test(character)) {
			delay = Math.max(10, Math.round(delay * 0.55));
		}

		window.setTimeout(nextCharacter, delay);
	}

	window.setTimeout(nextCharacter, Math.max(0, Number(config.startDelay) || 0));
}

function findDisplayTargets() {
	var selector = String(config.displaySelector || '').trim();
	if (!selector) {
		return [];
	}

	try {
		return Array.from(document.querySelectorAll(selector)).filter(function(element) {
			return !isPluginManagedElement(element)
				&& !element.closest('.btq-shortcode')
				&& !element.querySelector('.btq-shortcode')
				&& element !== document.body
				&& element !== document.documentElement;
		});
	} catch (error) {
		if (window.console && console.warn) {
			console.warn('蝶语随机打字机：CSS 展示位选择器无效。', error);
		}
		return [];
	}
}

function initialize() {
	document.querySelectorAll('.btq-shortcode').forEach(function(element) {
		typeQuote(element, pageQuote);
	});

	findDisplayTargets().forEach(function(element) {
		typeQuote(element, pageQuote);
	});
}

function start() {
	initialize();

	if (!document.body || !window.MutationObserver) {
		return;
	}

	var observer = new MutationObserver(function(mutations) {
		var hasAddedNodes = mutations.some(function(mutation) {
			if (!mutation.addedNodes || mutation.addedNodes.length === 0) {
				return false;
			}

			// Typing adds one text node per character. Ignore those internal
			// mutations so the observer only reacts to content added by the
			// theme, a page builder, or client-side navigation.
			var mutationParent = mutation.target && mutation.target.nodeType === 1
				? mutation.target
				: mutation.target.parentElement;

			return !isPluginManagedElement(mutationParent);
		});

		if (!hasAddedNodes) {
			return;
		}

		window.clearTimeout(observerTimer);
		observerTimer = window.setTimeout(initialize, 80);
	});

	observer.observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
	start();
}
JS;
		$javascript .= "\n})(" . wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ');';

		wp_register_style( 'butterfly-random-typewriter-quotes', false, array(), self::VERSION );
		wp_enqueue_style( 'butterfly-random-typewriter-quotes' );
		wp_add_inline_style( 'butterfly-random-typewriter-quotes', $css );

		wp_register_script( 'butterfly-random-typewriter-quotes', false, array(), self::VERSION, true );
		wp_enqueue_script( 'butterfly-random-typewriter-quotes' );
		wp_add_inline_script( 'butterfly-random-typewriter-quotes', $javascript );
	}
}

register_activation_hook( __FILE__, array( 'Butterfly_Random_Typewriter_Quotes', 'activate' ) );
new Butterfly_Random_Typewriter_Quotes();
