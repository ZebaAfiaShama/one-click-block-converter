<?php
/**
 * Plugin Name:       Zeba Classic to Blocks Converter
 * Plugin URI:        https://github.com/ZebaAfiaShama/zeba-classic-to-blocks-converter
 * Description:       Convert Classic Editor content to Gutenberg blocks in one click — with automatic backups and one-click revert. 100% free, no ads, no upsells, no payment dependencies.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.2
 * Author:            Zeba Afia Shama
 * Author URI:        https://github.com/ZebaAfiaShama
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zeba-classic-to-blocks-converter
 */

defined( 'ABSPATH' ) || exit;

define( 'ZCBC_VERSION', '1.0.0' );
define( 'ZCBC_PLUGIN_FILE', __FILE__ );
define( 'ZCBC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Meta keys used to store the pre-conversion backup.
 */
const ZCBC_META_ORIGINAL  = '_zcbc_original_content';
const ZCBC_META_CONVERTED = '_zcbc_converted_at';

/**
 * Post types eligible for conversion: public, block-editor capable, REST-visible.
 *
 * @return string[]
 */
function zcbc_get_supported_post_types() {
	$types = get_post_types(
		array(
			'public'       => true,
			'show_in_rest' => true,
		)
	);

	$types = array_filter(
		$types,
		static function ( $type ) {
			return 'attachment' !== $type && post_type_supports( $type, 'editor' );
		}
	);

	/**
	 * Filter the post types offered in the converter UI.
	 *
	 * @param string[] $types Post type slugs.
	 */
	return apply_filters( 'zcbc_supported_post_types', array_values( $types ) );
}

/**
 * Supported post types the current user may bulk-edit.
 *
 * A user must hold the edit_others_posts capability of each specific post
 * type; a generic capability check is not enough because custom post types
 * can map their own capabilities.
 *
 * @return string[]
 */
function zcbc_get_editable_post_types() {
	return array_values(
		array_filter(
			zcbc_get_supported_post_types(),
			static function ( $type ) {
				$object = get_post_type_object( $type );
				return $object && current_user_can( $object->cap->edit_others_posts );
			}
		)
	);
}

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'zcbc_plugin_action_links' );
function zcbc_plugin_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'tools.php?page=zeba-classic-to-blocks-converter' ) ) . '">' . esc_html__( 'Open Converter', 'zeba-classic-to-blocks-converter' ) . '</a>'
	);
	return $links;
}

add_action( 'admin_menu', 'zcbc_register_admin_page' );
function zcbc_register_admin_page() {
	add_management_page(
		__( 'Block Converter', 'zeba-classic-to-blocks-converter' ),
		__( 'Block Converter', 'zeba-classic-to-blocks-converter' ),
		'edit_others_posts',
		'zeba-classic-to-blocks-converter',
		'zcbc_render_admin_page'
	);
}

function zcbc_render_admin_page() {
	?>
	<div class="wrap zcbc-wrap">
		<h1><?php esc_html_e( 'Zeba Classic to Blocks Converter', 'zeba-classic-to-blocks-converter' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Converts Classic Editor content into Gutenberg blocks using the same converter built into the block editor. The original content of every post is backed up automatically, so you can revert any conversion with one click.', 'zeba-classic-to-blocks-converter' ); ?>
		</p>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Recommended: create a full database backup before running a bulk conversion on a production site.', 'zeba-classic-to-blocks-converter' ); ?></p>
		</div>
		<div id="zcbc-app">
			<p><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span><?php esc_html_e( 'Loading posts…', 'zeba-classic-to-blocks-converter' ); ?></p>
		</div>
	</div>
	<?php
}

add_action( 'admin_enqueue_scripts', 'zcbc_enqueue_assets' );
function zcbc_enqueue_assets( $hook ) {
	if ( 'tools_page_zeba-classic-to-blocks-converter' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'zcbc-admin',
		ZCBC_PLUGIN_URL . 'assets/admin.js',
		array( 'wp-api-fetch', 'wp-autop', 'wp-blocks', 'wp-block-library', 'wp-shortcode', 'wp-dom-ready', 'wp-i18n' ),
		ZCBC_VERSION,
		true
	);

	wp_enqueue_style(
		'zcbc-admin',
		ZCBC_PLUGIN_URL . 'assets/admin.css',
		array(),
		ZCBC_VERSION
	);

	$post_types = array();
	foreach ( zcbc_get_supported_post_types() as $slug ) {
		$object = get_post_type_object( $slug );
		if ( $object ) {
			$post_types[] = array(
				'slug'  => $slug,
				'label' => $object->labels->name,
			);
		}
	}

	wp_localize_script(
		'zcbc-admin',
		'ZCBC',
		array(
			'postTypes'   => $post_types,
			'perPage'     => 100,
			'concurrency' => 3,
		)
	);

	wp_set_script_translations( 'zcbc-admin', 'zeba-classic-to-blocks-converter' );
}

/* -------------------------------------------------------------------------
 * REST API
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', 'zcbc_register_rest_routes' );
function zcbc_register_rest_routes() {
	register_rest_route(
		'zcbc/v1',
		'/posts',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'zcbc_rest_list_posts',
			'permission_callback' => static function () {
				return count( zcbc_get_editable_post_types() ) > 0;
			},
			'args'                => array(
				'status'    => array(
					'type'    => 'string',
					'enum'    => array( 'classic', 'converted' ),
					'default' => 'classic',
				),
				'post_type' => array(
					'type'    => 'string',
					'default' => 'any',
				),
				'page'      => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page'  => array(
					'type'    => 'integer',
					'default' => 100,
					'minimum' => 1,
					'maximum' => 100,
				),
			),
		)
	);

	register_rest_route(
		'zcbc/v1',
		'/convert',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'zcbc_rest_convert',
			'permission_callback' => 'zcbc_rest_can_edit_post',
			'args'                => array(
				'id'      => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'content' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);

	register_rest_route(
		'zcbc/v1',
		'/revert',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'zcbc_rest_revert',
			'permission_callback' => 'zcbc_rest_can_edit_post',
			'args'                => array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Permission callback for single-post operations.
 *
 * @param WP_REST_Request $request Request.
 * @return bool
 */
function zcbc_rest_can_edit_post( $request ) {
	$id = (int) $request['id'];
	return $id > 0 && current_user_can( 'edit_post', $id );
}

/**
 * List classic (not yet converted) or converted posts.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function zcbc_rest_list_posts( $request ) {
	$status    = $request['status'];
	$post_type = $request['post_type'];
	$supported = zcbc_get_editable_post_types();

	if ( 'any' !== $post_type && ! in_array( $post_type, $supported, true ) ) {
		return new WP_REST_Response( array( 'posts' => array(), 'total' => 0, 'total_pages' => 0 ), 200 );
	}

	$args = array(
		'post_type'              => 'any' === $post_type ? $supported : $post_type,
		'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page'         => (int) $request['per_page'],
		'paged'                  => (int) $request['page'],
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'no_found_rows'          => false,
		'update_post_term_cache' => false,
	);

	if ( 'converted' === $status ) {
		$args['meta_key'] = ZCBC_META_ORIGINAL; // phpcs:ignore WordPress.DB.SlowDBQuery
	} else {
		// Skip page-builder posts (e.g. Elementor): their post_content is
		// generated output, not authored classic content.
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array(
				'key'     => '_elementor_edit_mode',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_elementor_edit_mode',
				'value'   => 'builder',
				'compare' => '!=',
			),
		);
		add_filter( 'posts_where', 'zcbc_filter_classic_where' );
	}

	$query = new WP_Query( $args );

	if ( 'classic' === $status ) {
		remove_filter( 'posts_where', 'zcbc_filter_classic_where' );
	}

	$posts = array();
	foreach ( $query->posts as $post ) {
		// map_meta_cap enforces the per-status capability here as well, e.g.
		// edit_private_posts for private content — never expose raw content
		// of a post the user cannot edit.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			continue;
		}
		$item = array(
			'id'        => $post->ID,
			'title'     => get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'zeba-classic-to-blocks-converter' ),
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
			'view_link' => get_permalink( $post->ID ),
		);
		if ( 'classic' === $status ) {
			$item['content'] = $post->post_content;
		} else {
			$item['converted_at'] = (int) get_post_meta( $post->ID, ZCBC_META_CONVERTED, true );
		}
		$posts[] = $item;
	}

	return new WP_REST_Response(
		array(
			'posts'       => $posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		),
		200
	);
}

/**
 * SQL condition selecting classic-content posts: non-empty content without block markup.
 *
 * @param string $where WHERE clause.
 * @return string
 */
function zcbc_filter_classic_where( $where ) {
	global $wpdb;
	$where .= " AND {$wpdb->posts}.post_content NOT LIKE '%<!-- wp:%' AND TRIM({$wpdb->posts}.post_content) != ''";
	return $where;
}

/**
 * Save converted block markup; back up the original first.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function zcbc_rest_convert( $request ) {
	$id      = (int) $request['id'];
	$content = (string) $request['content'];
	$post    = get_post( $id );

	if ( ! $post ) {
		return new WP_Error( 'zcbc_not_found', __( 'Post not found.', 'zeba-classic-to-blocks-converter' ), array( 'status' => 404 ) );
	}

	if ( ! in_array( $post->post_type, zcbc_get_editable_post_types(), true ) ) {
		return new WP_Error( 'zcbc_forbidden_type', __( 'This post type is not supported for conversion.', 'zeba-classic-to-blocks-converter' ), array( 'status' => 403 ) );
	}

	if ( '' === trim( $content ) || ! has_blocks( $content ) ) {
		return new WP_Error( 'zcbc_no_blocks', __( 'Converted content contains no blocks; post left untouched.', 'zeba-classic-to-blocks-converter' ), array( 'status' => 400 ) );
	}

	// Keep only the first backup — repeated conversions must not overwrite the true original.
	if ( '' === (string) get_post_meta( $id, ZCBC_META_ORIGINAL, true ) ) {
		update_post_meta( $id, ZCBC_META_ORIGINAL, wp_slash( $post->post_content ) );
	}
	update_post_meta( $id, ZCBC_META_CONVERTED, time() );

	$result = wp_update_post(
		array(
			'ID'           => $id,
			'post_content' => wp_slash( $content ),
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		delete_post_meta( $id, ZCBC_META_CONVERTED );
		return $result;
	}

	return new WP_REST_Response( array( 'id' => $id, 'converted' => true ), 200 );
}

/**
 * Restore the backed-up original content.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function zcbc_rest_revert( $request ) {
	$id       = (int) $request['id'];
	$original = get_post_meta( $id, ZCBC_META_ORIGINAL, true );

	if ( '' === (string) $original ) {
		return new WP_Error( 'zcbc_no_backup', __( 'No backup found for this post.', 'zeba-classic-to-blocks-converter' ), array( 'status' => 404 ) );
	}

	$result = wp_update_post(
		array(
			'ID'           => $id,
			'post_content' => wp_slash( $original ),
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	delete_post_meta( $id, ZCBC_META_ORIGINAL );
	delete_post_meta( $id, ZCBC_META_CONVERTED );

	return new WP_REST_Response( array( 'id' => $id, 'reverted' => true ), 200 );
}
