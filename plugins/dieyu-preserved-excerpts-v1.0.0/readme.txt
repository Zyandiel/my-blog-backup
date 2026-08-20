=== 蝶语保留格式摘要 ===
Contributors: butterfly-leaf
Tags: excerpt, argon, paragraph, whitespace, preview
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

为 Argon WordPress 主题的首页、归档、分类和搜索结果文章卡片保留摘要格式。

== 功能 ==

* 手写摘要：保留实际换行、空行、连续空格以及安全 HTML（例如 br 标签）
* 自动摘要：仍从正文开头截取，但保留正文中的段落、换行和空行
* 同时兼容 Argon 的三种文章预览布局
* 沿用 Argon 主题的“文章预览截取字数”设置
* 不修改数据库中的正文和摘要，仅在列表页面渲染时生效
* 不影响文章详情页、Feed、REST API 和密码保护文章

== 安装 ==

1. 在 WordPress 后台进入“插件 → 安装插件 → 上传插件”。
2. 上传本插件 ZIP 并启用。
3. 清除页面缓存，然后刷新首页。

插件启用后自动工作，无需额外设置。摘要长度仍在“Argon 主题选项”中调整。

== 手写摘要 ==

在文章编辑器右侧点击“添加摘要”，直接输入想展示的内容。普通换行和空行会被保留，无需手动输入 br 标签；原来已经使用 br 标签的摘要也能继续显示。

== 自动摘要 ==

摘要为空时，插件从正文开头生成预览。段落之间的空行、段内换行以及列表换行都会被保留，达到 Argon 设置的预览长度后添加省略号。

== 更新日志 ==

= 1.0.0 =

* 首次发布。
