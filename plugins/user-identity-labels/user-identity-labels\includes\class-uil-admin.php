<?php
/**
 * WordPress administration screens and profile fields.
 *
 * @package UserIdentityLabels
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles label management, settings, user assignment, and the Users column.
 */
final class UIL_Admin {

	/** @var UIL_Plugin */
	private $plugin;

	/**
	 * Registers admin hooks.
	 *
	 * @param UIL_Plugin $plugin Core plugin instance.
	 */
	public function __construct( UIL_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notice' ) );

		add_action( 'admin_post_uil_save_label', array( $this, 'handle_save_label' ) );
		add_action( 'admin_post_uil_delete_label', array( $this, 'handle_delete_label' ) );
		add_action( 'admin_post_uil_save_settings', array( $this, 'handle_save_settings' ) );

		add_action( 'show_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_fields' ) );
		add_action( 'user_new_form', array( $this, 'render_new_user_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_fields' ) );
		add_action( 'user_register', array( $this, 'save_new_user_fields' ) );

		add_filter( 'manage_users_columns', array( $this, 'add_users_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_users_column' ), 10, 3 );
	}

	/**
	 * Capability required to create, update, and delete global labels.
	 *
	 * @return string
	 */
	public function get_manage_capability() {
		/**
		 * Filters the capability required to manage the identity-label library.
		 *
		 * @param string $capability WordPress capability name.
		 */
		return apply_filters( 'uil_manage_labels_capability', 'manage_options' );
	}

	/**
	 * Whether the current user can assign labels to a target user.
	 *
	 * @param int $user_id Target user ID.
	 * @return bool
	 */
	private function can_assign_labels( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		if ( current_user_can( $this->get_manage_capability() ) ) {
			return true;
		}

		$settings = $this->plugin->get_settings();
		return ! empty( $settings['allow_self_edit'] ) && get_current_user_id() === $user_id;
	}

	/**
	 * Adds the management screen beneath Users.
	 *
	 * @return void
	 */
	public function add_admin_page() {
		add_users_page(
			__( '身份标签', 'user-identity-labels' ),
			__( '身份标签', 'user-identity-labels' ),
			$this->get_manage_capability(),
			'uil-identity-labels',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Loads namespaced styles only on affected admin screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$allowed = array(
			'profile.php',
			'user-edit.php',
			'user-new.php',
			'users.php',
			'users_page_uil-identity-labels',
		);
		if ( ! in_array( $hook_suffix, $allowed, true ) ) {
			return;
		}

		wp_enqueue_style( 'uil-admin', UIL_URL . 'assets/css/admin.css', array(), UIL_VERSION );
		wp_enqueue_style( 'uil-public', UIL_URL . 'assets/css/public.css', array(), UIL_VERSION );
	}

	/**
	 * Renders the management screen.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( $this->get_manage_capability() ) ) {
			wp_die( esc_html__( '你没有管理身份标签的权限。', 'user-identity-labels' ) );
		}

		$tab = sanitize_key( $this->get_scalar_request_value( $_GET, 'tab', 'labels' ) );
		if ( ! in_array( $tab, array( 'labels', 'settings', 'help' ), true ) ) {
			$tab = 'labels';
		}
		?>
		<div class="wrap uil-admin-wrap">
			<h1><?php esc_html_e( '用户身份标签', 'user-identity-labels' ); ?></h1>
			<p class="description"><?php esc_html_e( '这里的标签只属于用户，与 WordPress 的文章标签完全独立。', 'user-identity-labels' ); ?></p>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( '身份标签页面', 'user-identity-labels' ); ?>">
				<a class="nav-tab <?php echo 'labels' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'users.php?page=uil-identity-labels&tab=labels' ) ); ?>"><?php esc_html_e( '标签管理', 'user-identity-labels' ); ?></a>
				<a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'users.php?page=uil-identity-labels&tab=settings' ) ); ?>"><?php esc_html_e( '显示设置', 'user-identity-labels' ); ?></a>
				<a class="nav-tab <?php echo 'help' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'users.php?page=uil-identity-labels&tab=help' ) ); ?>"><?php esc_html_e( '使用方法', 'user-identity-labels' ); ?></a>
			</nav>

			<?php
			if ( 'settings' === $tab ) {
				$this->render_settings_tab();
			} elseif ( 'help' === $tab ) {
				$this->render_help_tab();
			} else {
				$this->render_labels_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders label creation and edit forms.
	 *
	 * @return void
	 */
	private function render_labels_tab() {
		$labels = $this->plugin->get_label_definitions( false );
		?>
		<div class="uil-admin-grid">
			<section class="uil-panel">
				<h2><?php esc_html_e( '新建身份标签', 'user-identity-labels' ); ?></h2>
				<?php $this->render_label_form(); ?>
			</section>

			<section class="uil-panel uil-panel--wide">
				<h2><?php esc_html_e( '已有标签', 'user-identity-labels' ); ?></h2>
				<?php if ( empty( $labels ) ) : ?>
					<p class="uil-empty-state"><?php esc_html_e( '还没有身份标签。先创建一个，例如“摄影师”“认证成员”或“志愿者”。', 'user-identity-labels' ); ?></p>
				<?php else : ?>
					<div class="uil-label-editor-list">
						<?php foreach ( $labels as $label ) : ?>
							<details class="uil-label-editor">
								<summary>
									<?php echo wp_kses( $this->render_admin_badge( $label ), $this->plugin->get_allowed_label_html() ); ?>
									<span class="uil-label-editor__hint"><?php esc_html_e( '点击编辑', 'user-identity-labels' ); ?></span>
								</summary>
								<div class="uil-label-editor__body">
									<?php $this->render_label_form( $label ); ?>
									<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" onsubmit="return window.confirm('<?php echo esc_js( __( '确定删除这个身份标签吗？所有用户都会失去此标签。', 'user-identity-labels' ) ); ?>');">
										<input type="hidden" name="action" value="uil_delete_label">
										<input type="hidden" name="label_id" value="<?php echo esc_attr( $label['id'] ); ?>">
										<?php wp_nonce_field( 'uil_delete_label_' . $label['id'] ); ?>
										<?php submit_button( __( '删除标签', 'user-identity-labels' ), 'delete small', 'submit', false ); ?>
									</form>
								</div>
							</details>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders a create/edit label form.
	 *
	 * @param array|null $label Existing label or null for a new label.
	 * @return void
	 */
	private function render_label_form( $label = null ) {
		$label = wp_parse_args(
			(array) $label,
			array(
				'id'          => '',
				'name'        => '',
				'description' => '',
				'color'       => '#3858e9',
				'text_color'  => '#ffffff',
				'order'       => 10,
			)
		);
		?>
		<form class="uil-label-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="uil_save_label">
			<input type="hidden" name="label_id" value="<?php echo esc_attr( $label['id'] ); ?>">
			<?php wp_nonce_field( 'uil_save_label' ); ?>

			<label>
				<span><?php esc_html_e( '标签名称', 'user-identity-labels' ); ?></span>
				<input name="label_name" type="text" maxlength="60" value="<?php echo esc_attr( $label['name'] ); ?>" required>
			</label>
			<div class="uil-field-row">
				<label>
					<span><?php esc_html_e( '背景色', 'user-identity-labels' ); ?></span>
					<input name="label_color" type="color" value="<?php echo esc_attr( $label['color'] ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( '文字色', 'user-identity-labels' ); ?></span>
					<input name="label_text_color" type="color" value="<?php echo esc_attr( $label['text_color'] ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( '排序', 'user-identity-labels' ); ?></span>
					<input name="label_order" type="number" min="0" max="9999" value="<?php echo esc_attr( $label['order'] ); ?>">
				</label>
			</div>
			<label>
				<span><?php esc_html_e( '说明（仅后台可见）', 'user-identity-labels' ); ?></span>
				<textarea name="label_description" rows="2" maxlength="200"><?php echo esc_textarea( $label['description'] ); ?></textarea>
			</label>
			<?php submit_button( $label['id'] ? __( '保存修改', 'user-identity-labels' ) : __( '创建标签', 'user-identity-labels' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Renders plugin settings.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		$settings = $this->plugin->get_settings();
		?>
		<section class="uil-panel uil-settings-panel">
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="uil_save_settings">
				<?php wp_nonce_field( 'uil_save_settings' ); ?>

				<h2><?php esc_html_e( '权限', 'user-identity-labels' ); ?></h2>
				<label class="uil-check-row">
					<input type="checkbox" name="allow_self_edit" value="1" <?php checked( $settings['allow_self_edit'] ); ?>>
					<span><strong><?php esc_html_e( '允许用户给自己选择身份标签', 'user-identity-labels' ); ?></strong><small><?php esc_html_e( '关闭时只有管理员能分配，适合“认证”“管理员”等不能自封的身份。', 'user-identity-labels' ); ?></small></span>
				</label>
				<label class="uil-check-row">
					<input type="checkbox" name="show_user_column" value="1" <?php checked( $settings['show_user_column'] ); ?>>
					<span><strong><?php esc_html_e( '在“用户”列表显示 ID 与身份标签', 'user-identity-labels' ); ?></strong><small><?php esc_html_e( '标签会直接显示在数字用户 ID 下方。', 'user-identity-labels' ); ?></small></span>
				</label>

				<h2><?php esc_html_e( '自动显示位置', 'user-identity-labels' ); ?></h2>
				<label class="uil-check-row">
					<input type="checkbox" name="auto_author_archive" value="1" <?php checked( $settings['auto_author_archive'] ); ?>>
					<span><strong><?php esc_html_e( '作者归档页标题下方', 'user-identity-labels' ); ?></strong></span>
				</label>
				<label class="uil-check-row">
					<input type="checkbox" name="auto_post_author" value="1" <?php checked( $settings['auto_post_author'] ); ?>>
					<span><strong><?php esc_html_e( '区块主题的“文章作者名称”区块下方', 'user-identity-labels' ); ?></strong></span>
				</label>
				<label class="uil-check-row">
					<input type="checkbox" name="auto_buddypress" value="1" <?php checked( $settings['auto_buddypress'] ); ?>>
					<span><strong><?php esc_html_e( 'BuddyPress 个人资料页', 'user-identity-labels' ); ?></strong></span>
				</label>
				<label class="uil-check-row">
					<input type="checkbox" name="auto_bbpress" value="1" <?php checked( $settings['auto_bbpress'] ); ?>>
					<span><strong><?php esc_html_e( 'bbPress 回复作者区域', 'user-identity-labels' ); ?></strong></span>
				</label>

				<h2><?php esc_html_e( '自定义主题定位', 'user-identity-labels' ); ?></h2>
				<p class="description"><?php esc_html_e( '如果主题已有显示 ID 或昵称的元素，填写它的 CSS 选择器，插件会把当前页面所属用户的标签插入其下方。留空则关闭。', 'user-identity-labels' ); ?></p>
				<label class="uil-full-field" for="uil-dom-selector">
					<span><?php esc_html_e( '目标 CSS 选择器', 'user-identity-labels' ); ?></span>
					<input id="uil-dom-selector" name="dom_selector" type="text" maxlength="300" value="<?php echo esc_attr( $settings['dom_selector'] ); ?>" placeholder=".profile-header .user-id">
				</label>
				<label class="uil-full-field" for="uil-dom-user-source">
					<span><?php esc_html_e( '标签所属用户', 'user-identity-labels' ); ?></span>
					<select id="uil-dom-user-source" name="dom_user_source">
						<option value="displayed" <?php selected( $settings['dom_user_source'], 'displayed' ); ?>><?php esc_html_e( '当前个人资料/作者页用户（推荐）', 'user-identity-labels' ); ?></option>
						<option value="post_author" <?php selected( $settings['dom_user_source'], 'post_author' ); ?>><?php esc_html_e( '当前文章作者', 'user-identity-labels' ); ?></option>
						<option value="current" <?php selected( $settings['dom_user_source'], 'current' ); ?>><?php esc_html_e( '当前登录用户', 'user-identity-labels' ); ?></option>
					</select>
					<small><?php esc_html_e( '自定义会员主题若没有公开接口，插件无法猜出正在查看谁；此时请选正确来源，或在模板中明确传入 user_id。选择器应只匹配一个 ID/昵称元素。', 'user-identity-labels' ); ?></small>
				</label>
				<label class="uil-full-field" for="uil-dom-position">
					<span><?php esc_html_e( '插入方式', 'user-identity-labels' ); ?></span>
					<select id="uil-dom-position" name="dom_position">
						<option value="afterend" <?php selected( $settings['dom_position'], 'afterend' ); ?>><?php esc_html_e( '紧跟在目标元素之后（推荐）', 'user-identity-labels' ); ?></option>
						<option value="beforeend" <?php selected( $settings['dom_position'], 'beforeend' ); ?>><?php esc_html_e( '放在目标元素内部末尾', 'user-identity-labels' ); ?></option>
					</select>
				</label>

				<?php submit_button( __( '保存设置', 'user-identity-labels' ) ); ?>
			</form>
		</section>
		<?php
	}

	/**
	 * Renders setup instructions.
	 *
	 * @return void
	 */
	private function render_help_tab() {
		?>
		<section class="uil-panel uil-help-panel">
			<h2><?php esc_html_e( '三种前台显示方法', 'user-identity-labels' ); ?></h2>
			<ol>
				<li><strong><?php esc_html_e( '区块：', 'user-identity-labels' ); ?></strong><?php esc_html_e( '在区块编辑器中搜索“用户身份标签”，放到 ID 或昵称区块下面。', 'user-identity-labels' ); ?></li>
				<li><strong><?php esc_html_e( '短代码：', 'user-identity-labels' ); ?></strong><code>[identity_labels]</code> <?php esc_html_e( '按页面上下文显示；也可指定', 'user-identity-labels' ); ?> <code>[identity_labels user_id="12"]</code>。</li>
				<li><strong><?php esc_html_e( 'CSS 定位：', 'user-identity-labels' ); ?></strong><?php esc_html_e( '在“显示设置”填入主题中 ID 元素的选择器。', 'user-identity-labels' ); ?></li>
			</ol>

			<h3><?php esc_html_e( '同时显示数字 ID 和标签', 'user-identity-labels' ); ?></h3>
			<p><code>[user_identity]</code></p>

			<h3><?php esc_html_e( '主题模板函数', 'user-identity-labels' ); ?></h3>
			<pre><code>&lt;?php uil_user_identity_labels( get_the_author_meta( 'ID' ) ); ?&gt;</code></pre>
			<p><?php esc_html_e( '把这一行放在主题输出用户名或 ID 的代码之后即可。', 'user-identity-labels' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Outputs profile fields for an existing user.
	 *
	 * @param WP_User $profile_user Edited user.
	 * @return void
	 */
	public function render_profile_fields( $profile_user ) {
		if ( is_network_admin() || ! $profile_user instanceof WP_User ) {
			return;
		}

		$this->render_assignment_fields( $profile_user->ID );
	}

	/**
	 * Outputs profile fields on the Add New User screen.
	 *
	 * @return void
	 */
	public function render_new_user_fields( $operation = 'add-new-user' ) {
		if ( is_network_admin() || 'add-new-user' !== $operation ) {
			return;
		}
		if ( ! current_user_can( $this->get_manage_capability() ) ) {
			return;
		}

		$this->render_assignment_fields( 0, true );
	}

	/**
	 * Renders the assignment checkbox grid.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $is_new  Whether this is the new-user screen.
	 * @return void
	 */
	private function render_assignment_fields( $user_id, $is_new = false ) {
		$can_edit = $is_new ? current_user_can( $this->get_manage_capability() ) : $this->can_assign_labels( $user_id );
		$labels   = $this->plugin->get_label_definitions();
		$assigned = $is_new ? array() : $this->plugin->get_user_label_ids( $user_id );
		?>
		<h2><?php esc_html_e( '身份标签', 'user-identity-labels' ); ?></h2>
		<table class="form-table uil-profile-table" role="presentation">
			<tr>
				<th><?php esc_html_e( '用户身份', 'user-identity-labels' ); ?></th>
				<td>
					<input type="hidden" name="uil_labels_present" value="1">
					<?php wp_nonce_field( 'uil_profile_labels', 'uil_profile_labels_nonce' ); ?>
					<?php if ( empty( $labels ) ) : ?>
						<p class="description">
							<?php esc_html_e( '管理员还没有创建身份标签。', 'user-identity-labels' ); ?>
							<?php if ( current_user_can( $this->get_manage_capability() ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'users.php?page=uil-identity-labels' ) ); ?>"><?php esc_html_e( '现在创建', 'user-identity-labels' ); ?></a>
							<?php endif; ?>
						</p>
					<?php else : ?>
						<div class="uil-assignment-grid">
							<?php foreach ( $labels as $label ) : ?>
								<label class="uil-assignment-item <?php echo $can_edit ? '' : 'uil-assignment-item--readonly'; ?>">
									<input type="checkbox" name="uil_label_ids[]" value="<?php echo esc_attr( $label['id'] ); ?>" <?php checked( in_array( $label['id'], $assigned, true ) ); ?> <?php disabled( ! $can_edit ); ?>>
									<?php echo wp_kses( $this->render_admin_badge( $label ), $this->plugin->get_allowed_label_html() ); ?>
									<?php if ( $label['description'] ) : ?><small><?php echo esc_html( $label['description'] ); ?></small><?php endif; ?>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<?php if ( ! $can_edit ) : ?><p class="description"><?php esc_html_e( '这些身份由管理员维护。', 'user-identity-labels' ); ?></p><?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Saves assignment fields on existing profile screens.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function save_profile_fields( $user_id ) {
		$this->maybe_save_assignments( $user_id );
	}

	/**
	 * Saves assignment fields after a new user is created.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public function save_new_user_fields( $user_id ) {
		$this->maybe_save_assignments( $user_id, true );
	}

	/**
	 * Validates and saves submitted user-label assignments.
	 *
	 * @param int  $user_id Target user ID.
	 * @param bool $is_new  Whether the user was just created.
	 * @return void
	 */
	private function maybe_save_assignments( $user_id, $is_new = false ) {
		if ( is_network_admin() ) {
			return;
		}
		if ( empty( $_POST['uil_labels_present'] ) || empty( $_POST['uil_profile_labels_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( $this->get_scalar_request_value( $_POST, 'uil_profile_labels_nonce' ) );
		if ( ! wp_verify_nonce( $nonce, 'uil_profile_labels' ) ) {
			return;
		}

		$can_edit = $is_new
			? current_user_can( $this->get_manage_capability() ) && current_user_can( 'edit_user', $user_id )
			: $this->can_assign_labels( $user_id );
		if ( ! $can_edit ) {
			return;
		}

		$submitted = isset( $_POST['uil_label_ids'] ) && is_array( $_POST['uil_label_ids'] )
			? wp_unslash( $_POST['uil_label_ids'] )
			: array();
		$known     = $this->plugin->get_label_definitions();
		$valid     = array();

		foreach ( array_slice( $submitted, 0, 100 ) as $label_id ) {
			if ( ! is_scalar( $label_id ) ) {
				continue;
			}
			$label_id = sanitize_key( $label_id );
			if ( isset( $known[ $label_id ] ) ) {
				$valid[] = $label_id;
			}
		}

		$valid = array_values( array_unique( $valid ) );
		if ( empty( $valid ) ) {
			delete_user_option( $user_id, UIL_Plugin::USER_META_KEY, false );
		} else {
			update_user_option( $user_id, UIL_Plugin::USER_META_KEY, $valid, false );
		}
	}

	/**
	 * Adds the ID/identity-label column to the Users table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_users_column( $columns ) {
		$settings = $this->plugin->get_settings();
		if ( ! empty( $settings['show_user_column'] ) ) {
			$columns['uil_identity'] = __( '用户 ID / 身份标签', 'user-identity-labels' );
		}

		return $columns;
	}

	/**
	 * Renders the custom Users table column.
	 *
	 * @param string $output      Existing column output.
	 * @param string $column_name Column key.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public function render_users_column( $output, $column_name, $user_id ) {
		if ( 'uil_identity' !== $column_name ) {
			return $output;
		}

		$labels = $this->plugin->render_labels( $user_id, array( 'size' => 'compact' ) );
		return sprintf(
			'<strong class="uil-admin-user-id">%1$d</strong>%2$s',
			absint( $user_id ),
			$labels ? '<div class="uil-admin-user-labels">' . $labels . '</div>' : '<div class="uil-admin-user-labels uil-admin-user-labels--empty">—</div>'
		);
	}

	/**
	 * Handles label create/update requests.
	 *
	 * @return void
	 */
	public function handle_save_label() {
		$this->assert_manage_permission();
		check_admin_referer( 'uil_save_label' );

		$label_id    = sanitize_key( $this->get_scalar_request_value( $_POST, 'label_id' ) );
		$name        = sanitize_text_field( $this->get_scalar_request_value( $_POST, 'label_name' ) );
		$background  = sanitize_hex_color( $this->get_scalar_request_value( $_POST, 'label_color' ) );
		$text_color  = sanitize_hex_color( $this->get_scalar_request_value( $_POST, 'label_text_color' ) );
		$description = sanitize_textarea_field( $this->get_scalar_request_value( $_POST, 'label_description' ) );
		$order       = min( 9999, max( 0, absint( $this->get_scalar_request_value( $_POST, 'label_order', '10' ) ) ) );

		$name        = $this->truncate_text( trim( $name ), 60 );
		$description = $this->truncate_text( trim( $description ), 200 );
		if ( '' === $name ) {
			$this->redirect_with_notice( 'empty_name' );
		}

		$labels = $this->plugin->get_label_definitions( false );
		if ( $label_id && ! isset( $labels[ $label_id ] ) ) {
			$this->redirect_with_notice( 'label_missing' );
		}

		$comparison_name = $this->normalize_name( $name );
		foreach ( $labels as $existing_id => $existing ) {
			if ( $existing_id !== $label_id && $this->normalize_name( $existing['name'] ) === $comparison_name ) {
				$this->redirect_with_notice( 'duplicate_name' );
			}
		}

		if ( ! $label_id ) {
			if ( count( $labels ) >= 100 ) {
				$this->redirect_with_notice( 'label_limit' );
			}
			$label_id = 'label_' . substr( md5( wp_generate_uuid4() . microtime( true ) ), 0, 20 );
		}

		$labels[ $label_id ] = array(
			'id'          => $label_id,
			'name'        => $name,
			'description' => $description,
			'color'       => $background ? $background : '#3858e9',
			'text_color'  => $text_color ? $text_color : '#ffffff',
			'order'       => $order,
		);
		update_option( UIL_Plugin::LABELS_OPTION, $labels, false );

		$this->redirect_with_notice( 'label_saved' );
	}

	/**
	 * Handles label deletion and removes its user-meta references.
	 *
	 * @return void
	 */
	public function handle_delete_label() {
		$this->assert_manage_permission();
		$label_id = sanitize_key( $this->get_scalar_request_value( $_POST, 'label_id' ) );
		check_admin_referer( 'uil_delete_label_' . $label_id );

		$labels = $this->plugin->get_label_definitions( false );
		if ( ! $label_id || ! isset( $labels[ $label_id ] ) ) {
			$this->redirect_with_notice( 'label_missing' );
		}

		unset( $labels[ $label_id ] );
		update_option( UIL_Plugin::LABELS_OPTION, $labels, false );
		$this->remove_label_from_all_users( $label_id );

		$this->redirect_with_notice( 'label_deleted' );
	}

	/**
	 * Handles settings updates.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		$this->assert_manage_permission();
		check_admin_referer( 'uil_save_settings' );

		$selector = wp_strip_all_tags( trim( $this->get_scalar_request_value( $_POST, 'dom_selector' ) ) );
		$selector = $this->truncate_text( $selector, 300 );
		$position = sanitize_key( $this->get_scalar_request_value( $_POST, 'dom_position', 'afterend' ) );
		if ( ! in_array( $position, array( 'afterend', 'beforeend' ), true ) ) {
			$position = 'afterend';
		}
		$source = sanitize_key( $this->get_scalar_request_value( $_POST, 'dom_user_source', 'displayed' ) );
		if ( ! in_array( $source, array( 'displayed', 'post_author', 'current' ), true ) ) {
			$source = 'displayed';
		}

		$settings = array(
			'allow_self_edit'     => empty( $_POST['allow_self_edit'] ) ? 0 : 1,
			'show_user_column'    => empty( $_POST['show_user_column'] ) ? 0 : 1,
			'auto_author_archive' => empty( $_POST['auto_author_archive'] ) ? 0 : 1,
			'auto_post_author'    => empty( $_POST['auto_post_author'] ) ? 0 : 1,
			'auto_buddypress'     => empty( $_POST['auto_buddypress'] ) ? 0 : 1,
			'auto_bbpress'        => empty( $_POST['auto_bbpress'] ) ? 0 : 1,
			'dom_selector'        => $selector,
			'dom_user_source'     => $source,
			'dom_position'        => $position,
		);
		update_option( UIL_Plugin::SETTINGS_OPTION, $settings, false );

		$this->redirect_with_notice( 'settings_saved', 'settings' );
	}

	/**
	 * Shows a translated status notice after a redirect.
	 *
	 * @return void
	 */
	public function show_admin_notice() {
		if ( 'uil-identity-labels' !== sanitize_key( $this->get_scalar_request_value( $_GET, 'page' ) ) || empty( $_GET['uil_notice'] ) ) {
			return;
		}

		$code = sanitize_key( $this->get_scalar_request_value( $_GET, 'uil_notice' ) );
		$messages = array(
			'label_saved'   => array( 'success', __( '身份标签已保存。', 'user-identity-labels' ) ),
			'label_deleted' => array( 'success', __( '身份标签已删除，并已从用户资料中移除。', 'user-identity-labels' ) ),
			'settings_saved' => array( 'success', __( '显示设置已保存。', 'user-identity-labels' ) ),
			'empty_name'    => array( 'error', __( '标签名称不能为空。', 'user-identity-labels' ) ),
			'duplicate_name' => array( 'error', __( '已经存在同名标签。', 'user-identity-labels' ) ),
			'label_missing' => array( 'error', __( '找不到要操作的标签。', 'user-identity-labels' ) ),
			'label_limit'   => array( 'error', __( '最多可创建 100 个身份标签。', 'user-identity-labels' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Returns a badge for admin screens.
	 *
	 * @param array $label Label definition.
	 * @return string
	 */
	private function render_admin_badge( $label ) {
		return sprintf(
			'<span class="uil-label" style="background-color:%1$s;color:%2$s;">%3$s</span>',
			esc_attr( $label['color'] ),
			esc_attr( $label['text_color'] ),
			esc_html( $label['name'] )
		);
	}

	/**
	 * Ensures the current request may manage the global library.
	 *
	 * @return void
	 */
	private function assert_manage_permission() {
		if ( ! current_user_can( $this->get_manage_capability() ) ) {
			wp_die(
				esc_html__( '你没有管理身份标签的权限。', 'user-identity-labels' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Redirects to the plugin screen with a fixed notice code.
	 *
	 * @param string $notice Notice code.
	 * @param string $tab    Target tab.
	 * @return void
	 */
	private function redirect_with_notice( $notice, $tab = 'labels' ) {
		$url = add_query_arg(
			array(
				'page'       => 'uil-identity-labels',
				'tab'        => sanitize_key( $tab ),
				'uil_notice' => sanitize_key( $notice ),
			),
			admin_url( 'users.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Removes one deleted label ID from every user's assignments.
	 *
	 * Uses a numeric cursor so deleting empty metadata cannot skip later users.
	 *
	 * @param string $label_id Deleted label ID.
	 * @return void
	 */
	private function remove_label_from_all_users( $label_id ) {
		global $wpdb;

		$cursor   = 0;
		$meta_key = $wpdb->get_blog_prefix() . UIL_Plugin::USER_META_KEY;
		do {
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id > %d ORDER BY user_id ASC LIMIT 200",
					$meta_key,
					$cursor
				)
			);

			foreach ( $user_ids as $user_id ) {
				$user_id  = absint( $user_id );
				$assigned = get_user_option( UIL_Plugin::USER_META_KEY, $user_id );
				if ( ! is_array( $assigned ) ) {
					delete_user_option( $user_id, UIL_Plugin::USER_META_KEY, false );
					$cursor = max( $cursor, $user_id );
					continue;
				}

				$assigned = array_values( array_diff( $assigned, array( $label_id ) ) );
				if ( empty( $assigned ) ) {
					delete_user_option( $user_id, UIL_Plugin::USER_META_KEY, false );
				} else {
					update_user_option( $user_id, UIL_Plugin::USER_META_KEY, $assigned, false );
				}
				$cursor = max( $cursor, $user_id );
			}
		} while ( count( $user_ids ) === 200 );
	}

	/**
	 * Normalizes a name for duplicate checks.
	 *
	 * @param string $name Label name.
	 * @return string
	 */
	private function normalize_name( $name ) {
		$name = remove_accents( trim( $name ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
	}

	/**
	 * Limits text by characters where mbstring is available.
	 *
	 * @param string $text   Text to truncate.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private function truncate_text( $text, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $length, 'UTF-8' );
		}
		if ( preg_match_all( '/./us', $text, $characters ) ) {
			return implode( '', array_slice( $characters[0], 0, $length ) );
		}

		return substr( $text, 0, $length );
	}

	/**
	 * Gets one unslashed scalar request value without accepting nested arrays.
	 *
	 * @param array  $source  Request array such as $_POST or $_GET.
	 * @param string $key     Requested key.
	 * @param string $default Default value.
	 * @return string
	 */
	private function get_scalar_request_value( $source, $key, $default = '' ) {
		if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
			return $default;
		}

		return (string) wp_unslash( $source[ $key ] );
	}
}
