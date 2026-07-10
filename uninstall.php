<?php
/**
 * Uninstall cleanup for Zeba Classic to Blocks Converter.
 *
 * Removes the plugin's backup metadata. Converted content itself is left
 * exactly as it is — uninstalling never touches post_content.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_post_meta_by_key( '_zcbc_original_content' );
delete_post_meta_by_key( '_zcbc_converted_at' );
