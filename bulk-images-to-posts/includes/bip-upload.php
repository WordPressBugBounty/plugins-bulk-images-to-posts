<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bip_image_mimes() {
	return array_filter( get_allowed_mime_types(), function ( $mime ) {
		return 0 === strpos( $mime, 'image/' ) && 'image/svg+xml' !== $mime;
	} );
}

function bip_check_ajax_access() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to use this uploader.', 'bulk-images-to-posts' ) ), 403 );
	}
	if ( ! check_ajax_referer( 'bip_upload', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload this page and try again.', 'bulk-images-to-posts' ) ), 403 );
	}
}

function bip_request_terms() {
	if ( ! isset( $_POST['terms'] ) || ! is_string( $_POST['terms'] ) ) {
		return new WP_Error( 'bip_terms', __( 'Invalid term selection. Reload this page and try again.', 'bulk-images-to-posts' ) );
	}
	$terms = json_decode( wp_unslash( $_POST['terms'] ), true );
	if ( ! is_array( $terms ) ) {
		return new WP_Error( 'bip_terms', __( 'Invalid term selection. Reload this page and try again.', 'bulk-images-to-posts' ) );
	}
	return bip_sanitize_terms( $terms );
}

function bip_ajax_save_terms() {
	bip_check_ajax_access();
	$terms = bip_request_terms();
	if ( is_wp_error( $terms ) ) {
		wp_send_json_error( array( 'message' => $terms->get_error_message() ), 400 );
	}
	update_option( 'bip_terms', $terms );
	if ( get_option( 'bip_terms' ) !== $terms ) {
		wp_send_json_error( array( 'message' => __( 'Could not save your selection. Please try again.', 'bulk-images-to-posts' ) ), 500 );
	}
	wp_send_json_success();
}

function bip_ajax_upload() {
	bip_check_ajax_access();
	$terms = bip_request_terms();
	if ( is_wp_error( $terms ) ) {
		wp_send_json_error( array( 'message' => $terms->get_error_message() ), 400 );
	}
	$result = bip_create_post_from_upload( $terms );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
	}
	wp_send_json_success( $result );
}

/** Read optional publishing metadata without changing the uploaded image. */
function bip_photo_options( $file, $post_type ) {
    $result = array( 'date' => '', 'keywords' => array() );
    $meta = wp_read_image_metadata( $file );
    if ( ! is_array( $meta ) ) { return $result; }
    if ( bip_sanitize_flag( get_option( 'bip_photo_date', 0 ) ) ) {
        $stamp = isset( $meta['created_timestamp'] ) ? (int) $meta['created_timestamp'] : 0;
        if ( is_callable( 'exif_read_data' ) ) {
            $exif = @exif_read_data( $file );
            if ( is_array( $exif ) && ! empty( $exif['DateTimeOriginal'] ) ) {
                $zone = wp_timezone();
                if ( ! empty( $exif['OffsetTimeOriginal'] ) && preg_match( '/^[+-](?:0[0-9]|1[0-4]):[0-5][0-9]$/', $exif['OffsetTimeOriginal'] ) ) {
                    $zone = new DateTimeZone( $exif['OffsetTimeOriginal'] );
                }
                $original = DateTimeImmutable::createFromFormat( '!Y:m:d H:i:s', $exif['DateTimeOriginal'], $zone );
                $errors = DateTimeImmutable::getLastErrors();
                if ( $original && ( ! $errors || ( ! $errors['warning_count'] && ! $errors['error_count'] ) ) && $original->getTimestamp() > 0 && $original->getTimestamp() <= time() ) {
                    $result['date'] = $original->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
                }
                // Invalid original dates fall back to upload time, not another metadata date.
                return bip_photo_keywords( $result, $meta, $post_type );
            }
        }

        $info = array();
        @getimagesize( $file, $info );
        $iptc = isset( $info['APP13'] ) ? @iptcparse( $info['APP13'] ) : false;
        if ( is_array( $iptc ) && isset( $iptc['2#055'][0] ) ) {
            $raw = $iptc['2#055'][0] . ( isset( $iptc['2#060'][0] ) ? $iptc['2#060'][0] : '000000' );
            $format = preg_match( '/^[0-9]{14}[+-][0-9]{4}$/', $raw ) ? '!YmdHisO' : '!YmdHis';
            $taken = DateTimeImmutable::createFromFormat( $format, $raw, wp_timezone() );
            $errors = DateTimeImmutable::getLastErrors();
            if ( $taken && ( ! $errors || ( ! $errors['warning_count'] && ! $errors['error_count'] ) ) && $taken->getTimestamp() > 0 && $taken->getTimestamp() <= time() ) {
                $result['date'] = $taken->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
            }
            return bip_photo_keywords( $result, $meta, $post_type );
        }
        // Core's EXIF timestamps represent a camera's wall-clock date in UTC.
        // Interpret timezone-less camera dates using the site's timezone.
        if ( $stamp > 0 ) {
            $date = gmdate( 'Y-m-d H:i:s', $stamp );
            $local = date_create_immutable( $date, wp_timezone() );
            if ( $local && $local->getTimestamp() <= time() ) { $result['date'] = $date; }
        }
    }
    return bip_photo_keywords( $result, $meta, $post_type );
}

function bip_photo_keywords( $result, $meta, $post_type ) {
    if ( bip_sanitize_flag( get_option( 'bip_photo_keywords', 0 ) ) && is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
        foreach ( isset( $meta['keywords'] ) && is_array( $meta['keywords'] ) ? $meta['keywords'] : array() as $keyword ) {
            if ( is_string( $keyword ) ) {
                $keyword = trim( sanitize_text_field( $keyword ) );
                if ( '' !== $keyword ) { $result['keywords'][] = $keyword; }
            }
        }
        $result['keywords'] = array_values( array_unique( $result['keywords'] ) );
    }
    return $result;
}

/** Only accepts a real HTTP upload. Return errors before touching dependent data. */
function bip_create_post_from_upload( $terms ) {
	$post_type = get_option( 'bip_post_type', 'post' );
	$type = is_string( $post_type ) ? get_post_type_object( $post_type ) : null;
	$status = get_option( 'bip_post_status', 'publish' );
	if ( ! $type || ! $type->public || 'attachment' === $post_type || ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
		return new WP_Error( 'bip_settings', __( 'Please choose a valid post type and status in Bulk Settings.', 'bulk-images-to-posts' ) );
	}
	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) || ! current_user_can( $type->cap->create_posts ) || ( 'publish' === $status && ! current_user_can( $type->cap->publish_posts ) ) ) {
		return new WP_Error( 'bip_permission', __( 'You do not have permission to upload images or create these posts.', 'bulk-images-to-posts' ) );
	}
	$selected_taxonomies = bip_sanitize_taxonomies( get_option( 'bip_taxonomy', array( 'category' ) ) );
	$assignments = array();
	foreach ( $terms as $taxonomy => $ids ) {
		if ( ! in_array( $taxonomy, $selected_taxonomies, true ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
			continue;
		}
		$tax = get_taxonomy( $taxonomy );
		if ( $ids && ! current_user_can( $tax->cap->assign_terms ) ) {
			return new WP_Error( 'bip_permission', __( 'You do not have permission to assign the selected terms.', 'bulk-images-to-posts' ) );
		}
		$assignments[ $taxonomy ] = $ids;
	}
	if ( empty( $_FILES['bipImage'] ) || ! is_array( $_FILES['bipImage'] ) ) {
		return new WP_Error( 'bip_file', __( 'Please select an image to upload.', 'bulk-images-to-posts' ) );
	}
	$file = $_FILES['bipImage'];
	if ( ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || ! is_int( $file['error'] ) || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) ) {
		return new WP_Error( 'bip_file', __( 'Invalid upload.', 'bulk-images-to-posts' ) );
	}
	if ( UPLOAD_ERR_OK !== $file['error'] ) {
		return new WP_Error( 'bip_file', __( 'The upload did not complete. Check the file size limit and try again.', 'bulk-images-to-posts' ) );
	}
	$mimes = bip_image_mimes();
	if ( ! is_uploaded_file( $file['tmp_name'] ) || ! in_array( wp_get_image_mime( $file['tmp_name'] ), $mimes, true ) ) {
		return new WP_Error( 'bip_image', __( 'Please upload a supported image file.', 'bulk-images-to-posts' ) );
	}
	$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
	if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
		return new WP_Error( 'bip_image', __( 'The image type or file extension is not supported.', 'bulk-images-to-posts' ) );
	}
	if ( is_multisite() && ! is_upload_space_available() ) {
		return new WP_Error( 'bip_quota', __( 'This site has no upload space remaining.', 'bulk-images-to-posts' ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
    $photo = bip_photo_options( $file['tmp_name'], $post_type );
    $tag_tax = get_taxonomy( 'post_tag' );
    if ( $photo['keywords'] && ( ! current_user_can( $tag_tax->cap->assign_terms ) || ( bip_sanitize_flag( get_option( 'bip_create_tags', 0 ) ) && ! current_user_can( $tag_tax->cap->manage_terms ) ) ) ) {
        return new WP_Error( 'bip_tags', __( 'You do not have permission to import image keywords as tags.', 'bulk-images-to-posts' ) );
    }
	$attachment_id = media_handle_upload( 'bipImage', 0, array(), array( 'test_form' => false, 'mimes' => $mimes ) );
	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}
	$title = str_replace( '-', ' ', pathinfo( wp_basename( $file['name'] ), PATHINFO_FILENAME ) );
	if ( bip_sanitize_flag( get_option( 'bip_image_title' ) ) ) {
		$metadata_title = get_post_field( 'post_title', $attachment_id, 'raw' );
		if ( '' !== trim( $metadata_title ) ) {
			$title = $metadata_title;
		}
	}
	$title = sanitize_text_field( $title );
	$content = '';
	if ( bip_sanitize_flag( get_option( 'bip_image_content' ) ) ) {
		$size = bip_sanitize_size( get_option( 'bip_image_content_size', 'large' ) );
		$image = wp_get_attachment_image( $attachment_id, $size, false, array(
			'alt' => $title,
			'class' => 'attachment-' . sanitize_html_class( $size ) . ' size-' . sanitize_html_class( $size ) . ' wp-image-' . $attachment_id,
		) );
		$content = $image ? '<p>' . $image . '</p>' : '';
	}
	// Finish attachment and taxonomy work before making the post public.
	$post_id = wp_insert_post( wp_slash( array(
		'post_title' => $title,
		'post_type' => $post_type,
		'post_status' => 'draft',
		'post_content' => $content,
		'post_author' => get_current_user_id(),
        'post_date' => $photo['date'] ? $photo['date'] : current_time( 'mysql' ),
        'post_date_gmt' => $photo['date'] ? get_gmt_from_date( $photo['date'] ) : current_time( 'mysql', true ),
	) ), true );
	if ( is_wp_error( $post_id ) ) {
		wp_delete_attachment( $attachment_id, true );
		return $post_id;
	}
	$error = null;
	foreach ( $assignments as $taxonomy => $ids ) {
		$result = wp_set_object_terms( $post_id, array_map( 'intval', $ids ), $taxonomy );
		if ( is_wp_error( $result ) ) {
			$error = $result;
			break;
		}
	}
    if ( ! $error && $photo['keywords'] ) {
        $tag_ids = array();
        foreach ( $photo['keywords'] as $keyword ) {
            $term = get_term_by( 'name', $keyword, 'post_tag' );
            if ( ! $term ) { $term = get_term_by( 'slug', sanitize_title( $keyword ), 'post_tag' ); }
            if ( $term ) {
                $tag_ids[] = (int) $term->term_id;
            } elseif ( bip_sanitize_flag( get_option( 'bip_create_tags', 0 ) ) ) {
                $created = wp_insert_term( $keyword, 'post_tag' );
                if ( is_wp_error( $created ) ) {
                    if ( 'term_exists' === $created->get_error_code() ) { $tag_ids[] = (int) $created->get_error_data(); }
                    else { $error = $created; break; }
                } else { $tag_ids[] = (int) $created['term_id']; }
            }
        }
        if ( ! $error && $tag_ids ) {
            $tag_result = wp_set_object_terms( $post_id, $tag_ids, 'post_tag', true );
            if ( is_wp_error( $tag_result ) ) { $error = $tag_result; }
        }
    }
	if ( ! $error ) {
		$result = wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => $post_id ), true );
		if ( is_wp_error( $result ) ) {
			$error = $result;
		} elseif ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
			$error = new WP_Error( 'bip_thumbnail', __( 'WordPress could not create a featured image for this file.', 'bulk-images-to-posts' ) );
		}
	}
	if ( ! $error && 'publish' === $status ) {
		$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $result ) ) {
			$error = $result;
		}
	}
	if ( $error ) {
		wp_delete_post( $post_id, true );
		wp_delete_attachment( $attachment_id, true );
		return $error;
	}
	return array(
		'post_id' => $post_id,
		'attachment_id' => $attachment_id,
		'title' => get_post_field( 'post_title', $post_id, 'raw' ),
		'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		'view_url' => 'publish' === get_post_status( $post_id ) ? get_permalink( $post_id ) : get_preview_post_link( $post_id ),
		'thumbnail_url' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
	);
}
