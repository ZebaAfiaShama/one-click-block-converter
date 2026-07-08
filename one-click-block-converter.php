<?php
/**
 * Plugin Name:       One Click Block Converter
 * Plugin URI:        https://github.com/ZebaAfiaShama/one-click-block-converter
 * Description:       Convert Classic Editor content to Gutenberg blocks in one click — with automatic backups and one-click revert. 100% free, no ads, no upsells, no payment dependencies.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.2
 * Author:            Zeba Afia Shama
 * Author URI:        https://github.com/ZebaAfiaShama
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       one-click-block-converter
 */

defined( 'ABSPATH' ) || exit;

define( 'OCBC_VERSION', '1.0.0' );
define( 'OCBC_PLUGIN_FILE', __FILE__ );
define( 'OCBC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Meta keys used to store the pre-conversion backup.
 */
const OCBC_META_ORIGINAL  = '_ocbc_original_content';
const OCBC_META_CONVERTED = '_ocbc_converted_at';

/**
 * Post types eligible for conversion: public, block-editor capable, REST-visible.
 *
 * @return string[]
 */
function ocbc_get_supported_post_types() {
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
	return apply_filters( 'ocbc_supported_post_types', array_values( $types ) );
}

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ocbc_plugin_action_links' );
function ocbc_plugin_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'tools.php?page=one-click-block-converter' ) ) . '">' . esc_html__( 'Open Converter', 'one-click-block-converter' ) . '</a>'
	);
	return $links;
}

add_action( 'admin_menu', 'ocbc_register_admin_page' );
function ocbc_register_admin_page() {
	add_management_page(
		__( 'Block Converter', 'one-click-block-converter' ),
		__( 'Block Converter', 'one-click-block-converter' ),
		'edit_others_posts',
		'one-click-block-converter',
		'ocbc_render_admin_page'
	);
}

function ocbc_render_admin_page() {
	?>
	<div class="wrap ocbc-wrap">
		<h1><?php esc_html_e( 'One Click Block Converter', 'one-click-block-converter' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Converts Classic Editor content into Gutenberg blocks using the same converter built into the block editor. The original content of every post is backed up automatically, so you can revert any conversion with one click.', 'one-click-block-converter' ); ?>
		</p>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Recommended: create a full database backup before running a bulk conversion on a production site.', 'one-click-block-converter' ); ?></p>
		</div>
		<div id="ocbc-app">
			<p><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span><?php esc_html_e( 'Loading posts…', 'one-click-block-converter' ); ?></p>
		</div>
	</div>
	<?php
}

add_action( 'admin_enqueue_scripts', 'ocbc_enqueue_assets' );
function ocbc_enqueue_assets( $hook ) {
	if ( 'tools_page_one-click-block-converter' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'ocbc-admin',
		OCBC_PLUGIN_URL . 'assets/admin.js',
		array( 'wp-api-fetch', 'wp-autop', 'wp-blocks', 'wp-block-library', 'wp-shortcode', 'wp-dom-ready', 'wp-i18n' ),
		OCBC_VERSION,
		true
	);

	wp_enqueue_style(
		'ocbc-admin',
		OCBC_PLUGIN_URL . 'assets/admin.css',
		array(),
		OCBC_VERSION
	);

	$post_types = array();
	foreach ( ocbc_get_supported_post_types() as $slug ) {
		$object = get_post_type_object( $slug );
		if ( $object ) {
			$post_types[] = array(
				'slug'  => $slug,
				'label' => $object->labels->name,
			);
		}
	}

	wp_localize_script(
		'ocbc-admin',
		'OCBC',
		array(
			'postTypes'   => $post_types,
			'perPage'     => 100,
			'concurrency' => 3,
		)
	);

	wp_set_script_translations( 'ocbc-admin', 'one-click-block-converter' );
}

/* -------------------------------------------------------------------------
 * REST API
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', 'ocbc_register_rest_routes' );
function ocbc_register_rest_routes() {
	register_rest_route(
		'ocbc/v1',
		'/posts',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'ocbc_rest_list_posts',
			'permission_callback' => static function () {
				return current_user_can( 'edit_others_posts' );
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
		'ocbc/v1',
		'/convert',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'ocbc_rest_convert',
			'permission_callback' => 'ocbc_rest_can_edit_post',
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
		'ocbc/v1',
		'/revert',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'ocbc_rest_revert',
			'permission_callback' => 'ocbc_rest_can_edit_post',
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
function ocbc_rest_can_edit_post( $request ) {
	$id = (int) $request['id'];
	return $id > 0 && current_user_can( 'edit_post', $id );
}

/**
 * List classic (not yet converted) or converted posts.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function ocbc_rest_list_posts( $request ) {
	$status    = $request['status'];
	$post_type = $request['post_type'];
	$supported = ocbc_get_supported_post_types();

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
		$args['meta_key'] = OCBC_META_ORIGINAL; // phpcs:ignore WordPress.DB.SlowDBQuery
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
		add_filter( 'posts_where', 'ocbc_filter_classic_where' );
	}

	$query = new WP_Query( $args );

	if ( 'classic' === $status ) {
		remove_filter( 'posts_where', 'ocbc_filter_classic_where' );
	}

	$posts = array();
	foreach ( $query->posts as $post ) {
		$item = array(
			'id'        => $post->ID,
			'title'     => get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'one-click-block-converter' ),
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
			'view_link' => get_permalink( $post->ID ),
		);
		if ( 'classic' === $status ) {
			$item['content'] = $post->post_content;
		} else {
			$item['converted_at'] = (int) get_post_meta( $post->ID, OCBC_META_CONVERTED, true );
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
function ocbc_filter_classic_where( $where ) {
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
function ocbc_rest_convert( $request ) {
	$id      = (int) $request['id'];
	$content = (string) $request['content'];
	$post    = get_post( $id );

	if ( ! $post ) {
		return new WP_Error( 'ocbc_not_found', __( 'Post not found.', 'one-click-block-converter' ), array( 'status' => 404 ) );
	}

	if ( '' === trim( $content ) || ! has_blocks( $content ) ) {
		return new WP_Error( 'ocbc_no_blocks', __( 'Converted content contains no blocks; post left untouched.', 'one-click-block-converter' ), array( 'status' => 400 ) );
	}

	// Keep only the first backup — repeated conversions must not overwrite the true original.
	if ( '' === (string) get_post_meta( $id, OCBC_META_ORIGINAL, true ) ) {
		update_post_meta( $id, OCBC_META_ORIGINAL, wp_slash( $post->post_content ) );
	}
	update_post_meta( $id, OCBC_META_CONVERTED, time() );

	$result = wp_update_post(
		array(
			'ID'           => $id,
			'post_content' => wp_slash( $content ),
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		delete_post_meta( $id, OCBC_META_CONVERTED );
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
function ocbc_rest_revert( $request ) {
	$id       = (int) $request['id'];
	$original = get_post_meta( $id, OCBC_META_ORIGINAL, true );

	if ( '' === (string) $original ) {
		return new WP_Error( 'ocbc_no_backup', __( 'No backup found for this post.', 'one-click-block-converter' ), array( 'status' => 404 ) );
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

	delete_post_meta( $id, OCBC_META_ORIGINAL );
	delete_post_meta( $id, OCBC_META_CONVERTED );

	return new WP_REST_Response( array( 'id' => $id, 'reverted' => true ), 200 );
}
