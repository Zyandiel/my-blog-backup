=== 用户身份标签 ===
Contributors: codex
Tags: users, identity, labels, badges, profile
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

为 WordPress 用户建立独立的身份标签库，并把标签显示在用户 ID 或昵称下方。不会使用或影响文章标签。

== Description ==

“用户身份标签”把身份信息存放在独立的标签库和用户资料中，而不是 WordPress 的文章 `post_tag`。

主要功能：

* 在“用户 → 身份标签”中统一创建标签，支持名称、颜色、说明和排序。
* 在“个人资料”或“编辑用户”页面给用户分配身份。
* 在“用户”列表增加“用户 ID / 身份标签”列，标签直接位于数字 ID 下方。
* 自动支持作者归档页、区块主题的“文章作者名称”区块、BuddyPress 和 bbPress。
* 提供“用户身份标签”动态区块、短代码和 PHP 模板函数。
* 对特殊主题可填写 ID/昵称元素的 CSS 选择器，自动把标签插在其下方。
* 默认只有管理员可创建和分配标签；可选择允许用户给自己分配已有标签。
* 支持 WordPress 个人数据导出与擦除工具。

身份标签是公开展示资料。请不要把邮箱、电话、证件号码等敏感信息作为标签。

== Installation ==

1. 在 WordPress 后台打开“插件 → 安装插件 → 上传插件”。
2. 上传 `user-identity-labels.zip`，安装并启用。
3. 打开“用户 → 身份标签”，先创建需要的身份。
4. 打开“用户”，编辑某个用户并勾选身份标签。
5. 前台可使用自动显示、区块或下面的短代码。

== Usage ==

按当前页面上下文显示标签：

`[identity_labels]`

指定用户：

`[identity_labels user_id="12"]`

明确显示当前登录用户（适合“我的账户”页面）：

`[identity_labels context="current"]`

同时显示数字用户 ID，并在下一行显示标签：

`[user_identity]`

主题模板函数：

`<?php uil_user_identity_labels( get_the_author_meta( 'ID' ) ); ?>`

如果主题已经有 ID 或昵称元素，可在“用户 → 身份标签 → 显示设置”填入 CSS 选择器，例如：

`.profile-header .user-id`

插件会按页面上下文选择用户：个人资料/作者页使用被查看的用户，文章页使用文章作者，其他页面才回退到当前登录用户。也可用 `context="displayed"`、`context="post_author"` 或 `context="current"` 明确来源。

== Frequently Asked Questions ==

= 会影响原来的文章标签吗？ =

不会。插件不注册、不修改也不查询 `post_tag`；身份标签保存在插件自己的全局标签库和站点级用户资料中。

= 为什么我的定制主题没有自动显示？ =

WordPress 没有一个适用于所有主题的“用户名/ID 下方”钩子。请使用“用户身份标签”区块、`[identity_labels]` 短代码，或在显示设置中填写主题 ID 元素的 CSS 选择器。这三种方法都能精确控制位置。CSS 选择器应只匹配当前资料中的一个 ID/昵称元素；非法或未命中的选择器会安全地不显示。

= 自动显示和手动区块能同时开启吗？ =

可以，但同一个作者名称旁可能出现两份标签。如果已经把“用户身份标签”区块或短代码手动放到作者名称下方，请在“显示设置”中关闭对应的自动显示开关。

= 普通用户可以给自己加“管理员”或“认证”标签吗？ =

默认不可以。只有管理员能创建和分配身份。只有管理员主动开启“允许用户给自己选择身份标签”后，用户才可以从现有标签中选择，而且不能创建新标签或编辑其他用户。

= 删除标签后会怎样？ =

该标签会从标签库和所有用户资料中移除。停用插件不会删除数据，重新启用后仍可继续使用。

= 卸载会删除数据吗？ =

默认保留，以防误删用户身份资料。如确实需要彻底清理，请先在 `wp-config.php` 定义 `UIL_DELETE_DATA_ON_UNINSTALL` 为 `true`，再从 WordPress 后台删除插件。

== Changelog ==

= 1.0.0 =

* 首次发布。
* 独立身份标签库、用户分配、用户列表列、短代码、动态区块和主题定位。
* 作者归档、文章作者区块、BuddyPress、bbPress 适配。
* 权限、nonce、输入清理、输出转义、个人数据导出与擦除。
