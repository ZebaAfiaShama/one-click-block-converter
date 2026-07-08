# BlockShift — One Click Block Converter

Convert Classic Editor content to Gutenberg blocks in one click — with automatic backups and one-click revert. 100% free, no ads, no upsells, no payment dependencies.

WordPress is gradually phasing out the Classic block. Core offers a per-block "Convert to blocks" action — one post at a time. This plugin does your **whole site at once**, safely.

## Features

- **One click** — scan the site, convert every classic post, page and custom post type, with a live progress bar.
- **Faithful conversion** — uses `wp.blocks.rawHandler()`, the exact converter behind the block editor's own "Convert to Blocks" button. Paragraphs, headings, images, galleries, lists, quotes, embeds and shortcodes become proper blocks.
- **Automatic backup & one-click revert** — every post's original content is stored in post meta before conversion. Revert any post at any time.
- **Safe by design** — page-builder (Elementor) posts are skipped automatically; nothing is touched until you click Convert.
- **Per-post control** — convert posts individually if you prefer.

## Usage

1. Install and activate.
2. Go to **Tools → Block Converter**.
3. Review the list of classic content, then click **Convert All to Blocks** (or convert per post).
4. Changed your mind? Click **Revert** next to any converted post.

Recommended: take a full database backup before bulk operations on production.

## Developers

- Post types offered in the UI can be filtered with `ocbc_supported_post_types`.
- REST endpoints: `ocbc/v1/posts`, `ocbc/v1/convert`, `ocbc/v1/revert` (nonce + capability protected).
- Backups live in `_ocbc_original_content` post meta; conversion timestamp in `_ocbc_converted_at`.

## License

GPL-2.0-or-later. See [readme.txt](readme.txt) for the WordPress.org plugin readme.
