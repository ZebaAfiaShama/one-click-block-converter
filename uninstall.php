<?php
/**
 * Uninstall cleanup for One Click Block Converter.
 *
 * Removes the plugin's backup metadata. Converted content itself is left
 * exactly as it is — uninstalling never touches post_content.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_post_meta_by_key( '_ocbc_original_content' );
delete_post_meta_by_key( '_ocbc_converted_at' );
