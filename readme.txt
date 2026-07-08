=== One Click Block Converter ===
Contributors: wpdevteam
Tags: gutenberg, classic editor, convert, blocks, migration
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert Classic Editor content to Gutenberg blocks in one click — with automatic backups and one-click revert. 100% free.

== Description ==

WordPress is gradually phasing out the Classic block: core contributors have begun deprecating it, with the long-term goal of making TinyMCE opt-in. Your classic content will keep working — but the modern editing experience, block tools, patterns and styling all live in native blocks. This plugin migrates your entire site's legacy content to native Gutenberg blocks in one click — safely.

WordPress core offers a per-block "Convert to blocks" action, one post at a time. This plugin does it for your whole site at once.

* **One click** — scan your whole site and convert every classic post, page and custom post type at once, with a live progress bar.
* **Faithful conversion** — uses `wp.blocks.rawHandler()`, the exact same converter behind the block editor's own "Convert to Blocks" button. Paragraphs, headings, images, galleries, lists, quotes, tables, embeds and shortcodes become proper blocks.
* **Automatic backup & one-click revert** — the original content of every post is stored before conversion. Changed your mind? Revert any post with one click.
* **Per-post control** — convert posts one at a time if you prefer.
* **100% free** — no ads, no upsells, no premium version, no payment dependency of any kind.

== Frequently Asked Questions ==

= Is WordPress removing the Classic Editor or my classic content? =

No. The Classic Editor plugin is unaffected, and existing classic content will keep rendering and stay editable. What is changing: the Classic block is being deprecated and will eventually be hidden behind an opt-in. Converting now future-proofs your content and unlocks the full block editing experience.

= Is this safe to run on a live site? =

The plugin backs up every post's original content before converting it, and you can revert any post at any time. We still recommend a full database backup before bulk operations — that's just good practice.

= Which post types are supported? =

All public post types that support the editor and are visible in the REST API: posts, pages, and most custom post types. Developers can adjust the list with the `ocbc_supported_post_types` filter.

= What happens to shortcodes and embeds? =

Shortcodes become Shortcode blocks and keep working exactly as before. Embed URLs become native Embed blocks. Content the converter cannot map to a specific block is preserved in an HTML block — nothing is lost.

= Where are the backups stored? =

In post meta (`_ocbc_original_content`). Reverting a post restores the backup and removes the meta. Backups are kept even if you deactivate the plugin.

== Screenshots ==

1. The converter screen: classic content list, one-click Convert All, live progress.
2. Converted posts with one-click Revert.

== Changelog ==

= 1.0.0 =
* Initial release.
