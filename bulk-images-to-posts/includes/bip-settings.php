<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bip_post_types() {
	$types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $types['attachment'] );
	return $types;
}

function bip_sanitize_post_type( $value ) {
	$types = bip_post_types();
	return is_string( $value ) && isset( $types[ $value ] ) ? $value : 'post';
}

function bip_sanitize_status( $value ) {
	return in_array( $value, array( 'publish', 'draft' ), true ) ? $value : 'publish';
}

function bip_sanitize_flag( $value ) {
	return in_array( $value, array( true, 1, '1', 'true' ), true ) ? 1 : 0;
}

function bip_sanitize_size( $value ) {
	return is_string( $value ) && in_array( $value, array_merge( get_intermediate_image_sizes(), array( 'full' ) ), true ) ? $value : 'large';
}

function bip_sanitize_taxonomies( $values ) {
	$result = array();
	foreach ( (array) $values as $value ) {
		if ( ! is_string( $value ) ) {
			continue;
		}
		$taxonomy = get_taxonomy( $value );
		if ( $taxonomy && $taxonomy->public ) {
			$result[] = $value;
		}
	}
	return array_values( array_unique( $result ) );
}

/** Keep old option names; normalize 3.1 lists and 3.6 taxonomy/slug maps. */
function bip_sanitize_terms( $values ) {
	if ( ! is_array( $values ) ) {
		return array();
	}
	$legacy_taxonomy = get_option( 'bip_taxonomy', 'category' );
	if ( is_string( $legacy_taxonomy ) ) {
		$flat = true;
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) {
				$flat = false;
				break;
			}
		}
		if ( $flat ) {
			$values = array( $legacy_taxonomy => $values );
		}
	}
	$result = array();
	foreach ( $values as $taxonomy => $terms ) {
		if ( ! is_string( $taxonomy ) || ! taxonomy_exists( $taxonomy ) || ! is_array( $terms ) ) {
			continue;
		}
		$result[ $taxonomy ] = array();
		foreach ( $terms as $value ) {
			if ( ! is_scalar( $value ) || is_bool( $value ) ) {
				continue;
			}
			// Old non-hierarchical values were slugs, including numeric slugs.
			if ( is_string( $value ) && preg_match( '/^id:([0-9]+)$/', $value, $match ) ) {
				$value = (int) $match[1];
			}
			$term = false;
			if ( is_string( $value ) && ! is_taxonomy_hierarchical( $taxonomy ) ) {
				$term = get_term_by( 'slug', $value, $taxonomy );
			}
			if ( ! $term && ( is_int( $value ) || ctype_digit( (string) $value ) ) ) {
				$term = get_term( (int) $value, $taxonomy );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$result[ $taxonomy ][] = (int) $term->term_id;
			}
		}
		$result[ $taxonomy ] = array_values( array_unique( $result[ $taxonomy ] ) );
	}
	return $result;
}

function bip_register_settings() {
	register_setting( 'bip-upload-group', 'bip_terms', array( 'type' => 'array', 'sanitize_callback' => 'bip_sanitize_terms', 'default' => array() ) );
	$settings = array(
		'bip_photo_date' => array( 'bip_sanitize_flag', 0, 'integer' ),
		'bip_photo_keywords' => array( 'bip_sanitize_flag', 0, 'integer' ),
		'bip_create_tags' => array( 'bip_sanitize_flag', 0, 'integer' ),
		'bip_updated' => array( 'bip_sanitize_flag', 0, 'integer' ),
		'bip_post_type' => array( 'bip_sanitize_post_type', 'post', 'string' ),
		'bip_image_title' => array( 'bip_sanitize_flag', 0, 'integer' ),
		'bip_post_status' => array( 'bip_sanitize_status', 'publish', 'string' ),
		'bip_taxonomy' => array( 'bip_sanitize_taxonomies', array( 'category' ), 'array' ),
		'bip_image_content' => array( 'bip_sanitize_flag', 0, 'integer' ),
		'bip_image_content_size' => array( 'bip_sanitize_size', 'large', 'string' ),
	);
	foreach ( $settings as $name => $setting ) {
		register_setting( 'bip-settings-group', $name, array( 'sanitize_callback' => $setting[0], 'default' => $setting[1], 'type' => $setting[2] ) );
	}
}

function bip_settings_select( $name, $options, $selected ) {
	echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
	foreach ( $options as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '"' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
}

function bip_settings_page( $embedded = false ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$taxonomies = bip_sanitize_taxonomies( get_option( 'bip_taxonomy', array( 'category' ) ) );
	
	$sizes = array(
		'full' => __( 'Full', 'bulk-images-to-posts' ),
		'large' => __( 'Large', 'bulk-images-to-posts' ),
		'medium' => __( 'Medium', 'bulk-images-to-posts' ),
		'thumbnail' => __( 'Thumbnail', 'bulk-images-to-posts' ),
	);
	foreach ( get_intermediate_image_sizes() as $size ) {
		if ( ! isset( $sizes[ $size ] ) ) {
			$sizes[ $size ] = $size;
		}
	}
	?>
	<div class="<?php echo $embedded ? 'bip-settings-content' : 'wrap bip-admin'; ?>">
		<?php if ( ! $embedded ) : ?><h1><?php esc_html_e( 'Bulk Images to Posts - Settings', 'bulk-images-to-posts' ); ?></h1><?php endif; ?>
		<?php settings_errors(); ?>
		<form method="post" action="options.php" id="bip-settings-form">
			<?php settings_fields( 'bip-settings-group' ); ?>
			<input type="hidden" name="bip_updated" value="1">
			<table class="form-table" role="presentation">
                <tr><th scope="row"><?php esc_html_e( 'Image metadata', 'bulk-images-to-posts' ); ?></th><td>
                <?php foreach ( array(
                    'bip_photo_date' => __( 'Use date taken as the post date', 'bulk-images-to-posts' ),
                    'bip_photo_keywords' => __( 'Use embedded image keywords as post tags', 'bulk-images-to-posts' ),
                    'bip_create_tags' => __( 'Allow image keywords to create new tags', 'bulk-images-to-posts' ),
                ) as $name => $label ) : ?>
                    <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
                    <label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( bip_sanitize_flag( get_option( $name, 0 ) ), 1 ); ?>> <?php echo esc_html( $label ); ?></label><br>
                <?php endforeach; ?>
                <p class="description"><?php esc_html_e( 'Dates use embedded EXIF/IPTC metadata; missing, invalid or future dates use the upload date. Dates without a timezone use your site timezone. Keywords use IPTC metadata. Existing tags are matched unless new tag creation is enabled. Tags must be supported by the selected post type.', 'bulk-images-to-posts' ); ?></p>
                </td></tr>

				<tr><th scope="row"><label for="bip_post_status"><?php esc_html_e( 'Post Status', 'bulk-images-to-posts' ); ?></label></th><td>
					<?php bip_settings_select( 'bip_post_status', array( 'publish' => __( 'Published', 'bulk-images-to-posts' ), 'draft' => __( 'Draft', 'bulk-images-to-posts' ) ), bip_sanitize_status( get_option( 'bip_post_status', 'publish' ) ) ); ?>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Post Title', 'bulk-images-to-posts' ); ?></th><td>
					<input type="hidden" name="bip_image_title" value="0">
					<label><input type="checkbox" name="bip_image_title" value="1" <?php checked( bip_sanitize_flag( get_option( 'bip_image_title' ) ), 1 ); ?>> <?php esc_html_e( 'Use image metadata title.', 'bulk-images-to-posts' ); ?></label>
					<p class="description"><?php esc_html_e( 'By default the image filename is used.', 'bulk-images-to-posts' ); ?></p>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Post Content', 'bulk-images-to-posts' ); ?></th><td>
					<input type="hidden" name="bip_image_content" value="0">
					<label><input type="checkbox" name="bip_image_content" value="1" <?php checked( bip_sanitize_flag( get_option( 'bip_image_content' ) ), 1 ); ?>> <?php esc_html_e( 'Include the image in the body of the post', 'bulk-images-to-posts' ); ?></label>
					<p><label for="bip_image_content_size"><?php esc_html_e( 'Image size', 'bulk-images-to-posts' ); ?></label>
					<?php bip_settings_select( 'bip_image_content_size', $sizes, bip_sanitize_size( get_option( 'bip_image_content_size', 'large' ) ) ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="bip_post_type"><?php esc_html_e( 'Post Type', 'bulk-images-to-posts' ); ?></label></th><td>
					<?php bip_settings_select( 'bip_post_type', wp_list_pluck( bip_post_types(), 'label' ), get_option( 'bip_post_type', 'post' ) ); ?>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Taxonomies', 'bulk-images-to-posts' ); ?></th><td>
					<input type="hidden" name="bip_taxonomy[]" value="">
					<?php foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) : ?>
						<label><input type="checkbox" name="bip_taxonomy[]" value="<?php echo esc_attr( $taxonomy->name ); ?>" <?php checked( in_array( $taxonomy->name, $taxonomies, true ) ); ?>> <?php echo esc_html( $taxonomy->label ); ?></label><br>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Only taxonomies registered for the selected post type appear in the uploader.', 'bulk-images-to-posts' ); ?></p>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
