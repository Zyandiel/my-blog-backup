<?php
/**
 * Plugin Name: 蝶语字体加速
 * Description: 将博客正在使用的网络字体缓存到本机、提前预加载，并避免无缓存访问时全站文字突然换字体。
 * Version: 1.0.1
 * Author: 小蝶的树叶
 * License: GPL-2.0-or-later
 * Text Domain: dieyu-font-accelerator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dieyu_Font_Accelerator {
	const VERSION        = '1.0.1';
	const OPTION_KEY     = 'dieyu_font_accelerator_settings';
	const VERSION_KEY    = 'dieyu_font_accelerator_version';
	const NOTICE_KEY     = 'dieyu_font_accelerator_notice_';
	const DIRECTORY_NAME = 'dieyu-font-accelerator';
	const FONT_FILE_NAME = 'dieyu-echo.woff2';
	const FONT_FAMILY    = 'DieyuEchoLocal';

	/**
	 * Start the plugin.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_preload' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'print_styles' ), 999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_remove_google_fonts' ), 999 );

		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_admin_notice' ) );
		add_action( 'admin_post_dieyu_font_accelerator_refresh', array( __CLASS__, 'handle_refresh' ) );
	}

	/**
	 * Plugin defaults.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			'source_url'           => 'https://fastly.jsdelivr.net/gh/huangwb8/bloghelper@latest/fonts/13.woff2',
			'display_strategy'     => 'swap',
			'enabled'              => 1,
			'preload'              => 1,
			'disable_google_fonts' => 0,
		);
	}

	/**
	 * Get normalized settings.
	 *
	 * @return array
	 */
	private static function settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Run once after activation.
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::defaults() );
		}

		self::maybe_upgrade();

		$result = self::download_font();
		self::store_download_notice( $result );
		self::purge_caches();
	}

	/**
	 * Upgrade settings saved by earlier versions.
	 *
	 * Version 1.0.0 used "optional" by default. That strategy deliberately
	 * refuses a late font swap, which means an uncached visit can keep the
	 * fallback font until the next refresh. Version 1.0.1 migrates that
	 * original default to "swap" so the current page always receives the
	 * locally cached font as soon as it is ready.
	 */
	public static function maybe_upgrade() {
		$installed_version = (string) get_option( self::VERSION_KEY, '' );
		if ( self::VERSION === $installed_version ) {
			return;
		}

		$settings = get_option( self::OPTION_KEY, array() );
		if ( is_array( $settings ) && empty( $installed_version ) && isset( $settings['display_strategy'] ) && 'optional' === $settings['display_strategy'] ) {
			$settings['display_strategy'] = 'swap';
			update_option( self::OPTION_KEY, $settings, false );
		}

		update_option( self::VERSION_KEY, self::VERSION, false );
		self::purge_caches();
	}

	/**
	 * Register settings.
	 */
	public static function register_settings() {
		register_setting(
			'dieyu_font_accelerator_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$url      = isset( $input['source_url'] ) ? esc_url_raw( trim( $input['source_url'] ) ) : $defaults['source_url'];
		$scheme   = wp_parse_url( $url, PHP_URL_SCHEME );

		if ( ! $url || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'dieyu_invalid_font_url',
				'字体地址无效，已恢复为默认地址。',
				'error'
			);
			$url = $defaults['source_url'];
		}

		$strategy = isset( $input['display_strategy'] ) ? sanitize_key( $input['display_strategy'] ) : 'swap';
		if ( ! in_array( $strategy, array( 'optional', 'fallback', 'swap' ), true ) ) {
			$strategy = 'optional';
		}

		$old_settings = self::settings();
		if ( $old_settings['source_url'] !== $url ) {
			self::set_notice(
				'字体来源地址已经改变。请点击设置页里的“重新下载并缓存字体”，让新地址生效。',
				'warning'
			);
		}

		self::purge_caches();

		return array(
			'source_url'           => $url,
			'display_strategy'     => $strategy,
			'enabled'              => empty( $input['enabled'] ) ? 0 : 1,
			'preload'              => empty( $input['preload'] ) ? 0 : 1,
			'disable_google_fonts' => empty( $input['disable_google_fonts'] ) ? 0 : 1,
		);
	}

	/**
	 * Add the settings screen.
	 */
	public static function add_settings_page() {
		add_options_page(
			'蝶语字体加速',
			'蝶语字体加速',
			'manage_options',
			'dieyu-font-accelerator',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Render the settings screen.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = self::settings();
		$font_path = self::font_path();
		$exists    = $font_path && is_readable( $font_path );
		$size      = $exists ? size_format( filesize( $font_path ), 2 ) : '';
		?>
		<div class="wrap">
			<h1>蝶语字体加速</h1>
			<p>把远程字体保存到你自己的 WordPress 上传目录，并提前开始加载，减少首次访问时的等待和字体跳变。</p>

			<div style="max-width:860px;margin:20px 0;padding:18px 22px;background:#fff;border:1px solid #dcdcde;border-radius:8px;">
				<h2 style="margin-top:0;">本地字体状态</h2>
				<?php if ( $exists ) : ?>
					<p><span style="color:#008a20;font-weight:600;">● 已成功缓存</span>（<?php echo esc_html( $size ); ?>）</p>
					<p>前台现在会优先从你自己的网站加载字体，不再依赖访客直接连接 jsDelivr。</p>
				<?php else : ?>
					<p><span style="color:#b32d2e;font-weight:600;">● 尚未缓存</span></p>
					<p>请点击下面的“重新下载并缓存字体”。下载成功前，插件会暂时使用原来的远程地址。</p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dieyu_font_accelerator_refresh">
					<?php wp_nonce_field( 'dieyu_font_accelerator_refresh' ); ?>
					<?php submit_button( '重新下载并缓存字体', 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'dieyu_font_accelerator_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">启用字体加速</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( 1, $settings['enabled'] ); ?>>
								在博客前台使用本地字体
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dieyu-font-source">字体来源地址</label></th>
						<td>
							<input id="dieyu-font-source" class="large-text code" type="url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[source_url]" value="<?php echo esc_attr( $settings['source_url'] ); ?>">
							<p class="description">默认就是你当前博客使用的字体。修改后请重新下载并缓存。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dieyu-display-strategy">首次显示方式</label></th>
						<td>
							<select id="dieyu-display-strategy" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[display_strategy]">
								<option value="swap" <?php selected( 'swap', $settings['display_strategy'] ); ?>>完整显示模式（推荐）</option>
								<option value="fallback" <?php selected( 'fallback', $settings['display_strategy'] ); ?>>折中模式</option>
								<option value="optional" <?php selected( 'optional', $settings['display_strategy'] ); ?>>系统字体优先模式</option>
							</select>
							<p class="description">
								<strong>完整显示模式：</strong>本地字体下载完成后，当前页面一定会全部换上字体，不需要再次刷新。<br>
								<strong>折中模式：</strong>给字体短暂加载机会，过慢时使用系统字体。<br>
								<strong>系统字体优先：</strong>无缓存时可能一直使用系统字体，直到下次刷新。
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">提前加载</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[preload]" value="1" <?php checked( 1, $settings['preload'] ); ?>>
								在页面顶部提前加载字体（推荐）
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Google Fonts</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[disable_google_fonts]" value="1" <?php checked( 1, $settings['disable_google_fonts'] ); ?>>
								停止加载 Argon 自带的 Google Fonts
							</label>
							<p class="description">默认关闭。确认网站没有其他地方依赖 Open Sans 或 Noto Serif SC 后再勾选，可再减少一次境外字体请求。</p>
						</td>
					</tr>
				</table>

				<?php submit_button( '保存设置' ); ?>
			</form>

			<div style="max-width:860px;margin-top:18px;padding:14px 18px;background:#f6f7f7;border-left:4px solid #72aee6;">
				<strong>重要：</strong>请保留 Argon 主题选项里原来的字体 CSS。插件会用自己的本地字体规则覆盖它，停用插件后原来的外观会自动恢复。
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the manual download button.
	 */
	public static function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有权限执行此操作。' );
		}

		check_admin_referer( 'dieyu_font_accelerator_refresh' );

		$result = self::download_font();
		self::store_download_notice( $result );
		self::purge_caches();

		wp_safe_redirect( admin_url( 'options-general.php?page=dieyu-font-accelerator' ) );
		exit;
	}

	/**
	 * Download and validate the configured WOFF2 font.
	 *
	 * @return true|WP_Error
	 */
	private static function download_font() {
		$settings = self::settings();
		$url      = $settings['source_url'];
		$path     = self::font_path();

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! $path ) {
			return new WP_Error( 'dieyu_upload_directory', '无法取得 WordPress 上传目录。' );
		}

		$directory = dirname( $path );
		if ( ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'dieyu_create_directory', '无法创建字体缓存目录，请检查 wp-content/uploads 的写入权限。' );
		}

		$temp_file = wp_tempnam( self::FONT_FILE_NAME );
		if ( ! $temp_file ) {
			return new WP_Error( 'dieyu_temp_file', '无法创建临时文件。' );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 45,
				'redirection' => 5,
				'stream'      => true,
				'filename'    => $temp_file,
				'user-agent'  => 'WordPress/Dieyu-Font-Accelerator-' . self::VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $temp_file );
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			@unlink( $temp_file );
			return new WP_Error( 'dieyu_http_status', '字体服务器返回了状态码 ' . $status_code . '。' );
		}

		$file_size = filesize( $temp_file );
		if ( ! $file_size || $file_size < 1000 ) {
			@unlink( $temp_file );
			return new WP_Error( 'dieyu_font_too_small', '下载到的文件过小，可能不是有效字体。' );
		}

		$handle    = fopen( $temp_file, 'rb' );
		$signature = $handle ? fread( $handle, 4 ) : '';
		if ( $handle ) {
			fclose( $handle );
		}

		if ( 'wOF2' !== $signature ) {
			@unlink( $temp_file );
			return new WP_Error( 'dieyu_invalid_woff2', '下载到的文件不是有效的 WOFF2 字体。' );
		}

		if ( ! @copy( $temp_file, $path ) ) {
			@unlink( $temp_file );
			return new WP_Error( 'dieyu_copy_failed', '无法把字体保存到上传目录，请检查目录写入权限。' );
		}

		@unlink( $temp_file );

		update_option(
			'dieyu_font_accelerator_font_meta',
			array(
				'source_url'    => $url,
				'downloaded_at' => time(),
				'size'          => (int) $file_size,
				'hash'          => hash_file( 'sha256', $path ),
			),
			false
		);

		return true;
	}

	/**
	 * Print an early preload tag.
	 */
	public static function print_preload() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['preload'] ) ) {
			return;
		}

		$url = self::active_font_url();
		if ( ! $url ) {
			return;
		}

		printf(
			"<link rel=\"preload\" href=\"%s\" as=\"font\" type=\"font/woff2\" crossorigin>\n",
			esc_url( $url )
		);
	}

	/**
	 * Print the local font override after theme CSS.
	 */
	public static function print_styles() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$url = self::active_font_url();
		if ( ! $url ) {
			return;
		}

		$strategy = in_array( $settings['display_strategy'], array( 'optional', 'fallback', 'swap' ), true )
			? $settings['display_strategy']
			: 'swap';
		?>
		<style id="dieyu-font-accelerator-css">
		@font-face {
			font-family: '<?php echo esc_html( self::FONT_FAMILY ); ?>';
			src: url('<?php echo esc_url( $url ); ?>') format('woff2');
			font-style: normal;
			font-weight: normal;
			font-display: <?php echo esc_html( $strategy ); ?>;
		}
		html body {
			font-family: '<?php echo esc_html( self::FONT_FAMILY ); ?>', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', 'WenQuanYi Micro Hei', sans-serif !important;
		}
		</style>
		<?php
	}

	/**
	 * Optionally stop Argon's Google Fonts request.
	 */
	public static function maybe_remove_google_fonts() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['disable_google_fonts'] ) ) {
			return;
		}

		wp_dequeue_style( 'googlefont' );
		wp_deregister_style( 'googlefont' );
	}

	/**
	 * Get the local font path.
	 *
	 * @return string
	 */
	private static function font_path() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . self::DIRECTORY_NAME . '/' . self::FONT_FILE_NAME;
	}

	/**
	 * Get the local font URL.
	 *
	 * @return string
	 */
	private static function local_font_url() {
		$uploads = wp_upload_dir();
		$path    = self::font_path();
		if ( ! $path || empty( $uploads['baseurl'] ) || ! is_readable( $path ) ) {
			return '';
		}

		$url = trailingslashit( $uploads['baseurl'] ) . self::DIRECTORY_NAME . '/' . self::FONT_FILE_NAME;
		return add_query_arg( 'ver', (string) filemtime( $path ), $url );
	}

	/**
	 * Use the local copy when available, otherwise retain the configured source.
	 *
	 * @return string
	 */
	private static function active_font_url() {
		$local_url = self::local_font_url();
		if ( $local_url ) {
			return $local_url;
		}

		$settings = self::settings();
		return esc_url_raw( $settings['source_url'] );
	}

	/**
	 * Convert a download result to a friendly notice.
	 *
	 * @param true|WP_Error $result Download result.
	 */
	private static function store_download_notice( $result ) {
		if ( is_wp_error( $result ) ) {
			self::set_notice(
				'字体下载失败：' . $result->get_error_message() . ' 插件会暂时使用原远程字体地址，你可以稍后到设置页重试。',
				'error'
			);
			return;
		}

		self::set_notice(
			'字体已经成功下载并缓存到你自己的网站。',
			'success'
		);
	}

	/**
	 * Save a per-user notice.
	 *
	 * @param string $message Notice text.
	 * @param string $type    Notice type.
	 */
	private static function set_notice( $message, $type ) {
		$user_id = get_current_user_id();
		set_transient(
			self::NOTICE_KEY . $user_id,
			array(
				'message' => sanitize_text_field( $message ),
				'type'    => sanitize_key( $type ),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Show a saved admin notice once.
	 */
	public static function show_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$key     = self::NOTICE_KEY . $user_id;
		$notice  = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );
		$type = isset( $notice['type'] ) && in_array( $notice['type'], array( 'success', 'warning', 'error', 'info' ), true )
			? $notice['type']
			: 'info';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>蝶语字体加速：</strong> %2$s</p></div>',
			esc_attr( $type ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Clear common WordPress/page caches after a configuration change.
	 */
	private static function purge_caches() {
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

		do_action( 'litespeed_purge_all' );
	}
}

Dieyu_Font_Accelerator::init();
register_activation_hook( __FILE__, array( 'Dieyu_Font_Accelerator', 'activate' ) );
