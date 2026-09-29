<?php
/**
 * Plugin Name: Bulk Images to Posts
 * Plugin URI: https://wordpress.org/plugins/bulk-images-to-posts/
 * Text Domain: bulk-images-to-posts
 * Domain Path: /lang
 * Description: Bulk upload images and automatically create one WordPress post per image, with featured images, titles and categories ready to go.
 * Version: 4.1.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Mezzanine gold
 * Author URI: http://mezzaninegold.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BIP_VERSION', '4.1.1' );
require_once __DIR__ . '/includes/bip-settings.php';
require_once __DIR__ . '/includes/bip-upload.php';

add_action( 'init', 'bip_load_textdomain' );
add_action( 'admin_init', 'bip_register_settings' );
add_action( 'admin_menu', 'bip_create_menu' );
add_action( 'admin_enqueue_scripts', 'bip_admin_assets' );
add_action( 'wp_ajax_bip_upload', 'bip_ajax_upload' );
add_action( 'wp_ajax_bip_save_terms', 'bip_ajax_save_terms' );

function bip_load_textdomain() {
	load_plugin_textdomain( 'bulk-images-to-posts', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );
}

function bip_create_menu() {
	$GLOBALS['bip_admin_pages'] = array();
	$GLOBALS['bip_admin_pages'][] = add_menu_page( __( 'Bulk Images to Posts Uploader', 'bulk-images-to-posts' ), __( 'Bulk', 'bulk-images-to-posts' ), 'manage_options', 'bulk-images-to-post', 'bip_upload_page', 'dashicons-images-alt2' );
	add_submenu_page( 'bulk-images-to-post', __( 'Bulk Images to Post - Upload', 'bulk-images-to-posts' ), __( 'Uploader', 'bulk-images-to-posts' ), 'manage_options', 'bulk-images-to-post', 'bip_upload_page' );
}

function bip_admin_assets( $hook ) {
	if ( ! in_array( $hook, isset( $GLOBALS['bip_admin_pages'] ) ? $GLOBALS['bip_admin_pages'] : array(), true ) ) {
		return;
	}
	wp_enqueue_style( 'bip-css', plugins_url( 'css/style.css', __FILE__ ), array(), BIP_VERSION . '.' . filemtime( __DIR__ . '/css/style.css' ) );
	if ( 'toplevel_page_bulk-images-to-post' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'bip-js', plugins_url( 'js/script.js', __FILE__ ), array( 'jquery', 'plupload' ), BIP_VERSION . '.' . filemtime( __DIR__ . '/js/script.js' ), true );
	$extensions = str_replace( '|', ',', implode( ',', array_keys( bip_image_mimes() ) ) );
	wp_localize_script( 'bip-js', 'bipUploader', array(
		'url'        => admin_url( 'admin-ajax.php' ),
		'nonce'      => wp_create_nonce( 'bip_upload' ),
		'maxSize'    => wp_max_upload_size(),
		'extensions' => $extensions,
		'saved'      => __( 'Changes saved.', 'bulk-images-to-posts' ),
		'saveError'  => __( 'Could not save your selection. Please try again.', 'bulk-images-to-posts' ),
		'queued'     => __( 'Waiting to upload…', 'bulk-images-to-posts' ),
		'edit' => __( 'Edit', 'bulk-images-to-posts' ),
		'view' => __( 'View post', 'bulk-images-to-posts' ),
		'newTab' => __( '(opens in a new tab)', 'bulk-images-to-posts' ),
		'processing' => __( 'Processing image…', 'bulk-images-to-posts' ),
		'finished' => __( 'Upload queue finished.', 'bulk-images-to-posts' ),
		'waiting' => __( 'Waiting: %d', 'bulk-images-to-posts' ),
		'complete'   => __( 'Post created.', 'bulk-images-to-posts' ),
		'error'      => __( 'Upload failed. Check the posts and Media Library before retrying.', 'bulk-images-to-posts' ),
		'leave'      => __( 'Images are still uploading. Leaving this page will interrupt them.', 'bulk-images-to-posts' ),
	) );
}

function bip_upload_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/template.php';
	require_once __DIR__ . '/includes/bip-category-walker.php';
	$post_type = get_option( 'bip_post_type', 'post' );
	$taxonomies = bip_sanitize_taxonomies( get_option( 'bip_taxonomy', array( 'category' ) ) );
	$taxonomies = array_values( array_filter( $taxonomies, function ( $taxonomy ) use ( $post_type ) { return is_object_in_taxonomy( $post_type, $taxonomy ); } ) );
	$terms = bip_sanitize_terms( get_option( 'bip_terms', array() ) );
    $type_object = get_post_type_object( $post_type );
    $summary = array(
        $type_object ? $type_object->labels->name : $post_type,
        'draft' === get_option( 'bip_post_status', 'publish' ) ? __( 'Draft', 'bulk-images-to-posts' ) : __( 'Published', 'bulk-images-to-posts' ),
    );
    if ( get_option( 'bip_photo_date', 0 ) ) { $summary[] = __( 'Photo date enabled', 'bulk-images-to-posts' ); }
    if ( get_option( 'bip_photo_keywords', 0 ) && is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
        $summary[] = get_option( 'bip_create_tags', 0 ) ? __( 'Image keywords: existing and new tags', 'bulk-images-to-posts' ) : __( 'Image keywords: existing tags only', 'bulk-images-to-posts' );
    }
    if ( get_option( 'bip_image_content', 0 ) ) { $summary[] = sprintf( __( 'Image in content: %s', 'bulk-images-to-posts' ), bip_sanitize_size( get_option( 'bip_image_content_size', 'large' ) ) ); }

	?>
	<div class="wrap bip-admin">
		<h1><?php esc_html_e( 'Bulk Images to Posts - Uploader', 'bulk-images-to-posts' ); ?></h1>
        <details class="postbox bip-settings" <?php echo isset( $_GET['settings-updated'] ) ? 'open' : ''; ?>>
            <summary><?php esc_html_e( 'Settings — expand to configure uploads', 'bulk-images-to-posts' ); ?></summary>
            <?php bip_settings_page( true ); ?>
        </details>
		<p><?php esc_html_e( 'Each image creates one post using your saved settings. Select terms before adding images; uploads start automatically.', 'bulk-images-to-posts' ); ?></p>
		<div class="bip-columns <?php echo $taxonomies ? '' : 'bip-no-terms'; ?>">
		<form method="post" action="options.php" id="bip-upload-form" <?php echo $taxonomies ? '' : 'hidden'; ?>>
			<?php settings_fields( 'bip-upload-group' ); ?>
			<input type="hidden" name="bip_terms" value="">
			<?php foreach ( $taxonomies as $taxonomy ) : ?>
				<?php if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) { continue; } ?>
				<div class="postbox">
					<h2><?php echo esc_html( get_taxonomy( $taxonomy )->labels->name ); ?></h2>
					<div class="inside">
						<button type="button" class="button bip-uncheck"><?php esc_html_e( 'Uncheck All', 'bulk-images-to-posts' ); ?></button>
						<ul class="categorychecklist">
						<?php wp_terms_checklist( 0, array(
							'taxonomy' => $taxonomy,
							'selected_cats' => isset( $terms[ $taxonomy ] ) ? $terms[ $taxonomy ] : array(),
							'popular_cats' => array(),
							'checked_ontop' => false,
							'walker' => new Walker_Bip_Terms(),
						) ); ?>
						</ul>
					</div>
				</div>
			<?php endforeach; ?>
			<?php submit_button( null, 'primary', 'bip-save-terms' ); ?>
			<div id="bip-save-result" role="status" aria-live="polite"></div>
		</form>
		<div class="bip-images"><div class="postbox">
			<h2><?php esc_html_e( 'Images', 'bulk-images-to-posts' ); ?></h2>
            <div class="bip-settings-summary inside">
                <strong><?php esc_html_e( 'Settings for new uploads', 'bulk-images-to-posts' ); ?></strong>
                <p><?php echo esc_html( implode( ' · ', $summary ) ); ?></p>
                <p id="bip-summary-terms" aria-live="polite"></p>
            </div>
			<?php require __DIR__ . '/includes/bip-uploader.php'; ?>
		</div>
        <section id="bip-completed" class="postbox" hidden>
            <div class="bip-completed-heading"><h2><?php esc_html_e( 'Completed', 'bulk-images-to-posts' ); ?></h2><button type="button" class="button button-secondary" id="bip-clear-completed"><?php esc_html_e( 'Clear completed', 'bulk-images-to-posts' ); ?></button></div>
            <p class="inside description"><?php esc_html_e( 'Clearing this list does not delete posts or images.', 'bulk-images-to-posts' ); ?></p>
            <ul id="bip-upload-results" class="inside" aria-live="polite" aria-relevant="additions text"></ul>
        </section></div>
		</div>
	</div>
	<?php
}
