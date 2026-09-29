<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="inside">
	<div id="bip-drop-area">
		<p><?php esc_html_e( 'Drag and Drop images here or click to upload.', 'bulk-images-to-posts' ); ?></p>
		<button type="button" class="button button-primary" id="bip-browse"><?php esc_html_e( 'Select images', 'bulk-images-to-posts' ); ?></button>
	</div>
	<p><?php
		/* translators: %s: maximum upload file size. */
		echo esc_html( sprintf( __( 'Maximum file size: %s.', 'bulk-images-to-posts' ), size_format( wp_max_upload_size() ) ) );
	?></p>
	<noscript><p><?php esc_html_e( 'Please enable JavaScript to upload images.', 'bulk-images-to-posts' ); ?></p></noscript>
	<div id="bip-upload-progress" class="bip-progress notice notice-info inline" hidden role="status">
        <strong id="bip-current-file"></strong>
        <span id="bip-current-status"></span>
        <span id="bip-pending-count"></span>
    </div>
    <section id="bip-upload-errors" hidden aria-live="polite">
        <h3><?php esc_html_e( 'Failed uploads', 'bulk-images-to-posts' ); ?></h3>
        <ul id="bip-error-list" class="bip-error"></ul>
    </section>

</div>
