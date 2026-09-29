<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Render IDs for every taxonomy, including tags, without creating new terms. */
class Walker_Bip_Terms extends Walker_Category_Checklist {
	public function start_el( &$output, $data_object, $depth = 0, $args = array(), $current_object_id = 0 ) {
		$term = $data_object;
		$taxonomy = isset( $args['taxonomy'] ) ? $args['taxonomy'] : $term->taxonomy;
		$selected = isset( $args['selected_cats'] ) ? (array) $args['selected_cats'] : array();
		$id = 'bip-' . $taxonomy . '-' . $term->term_id;
		$output .= '<li id="' . esc_attr( $id ) . '"><label><input type="checkbox" name="bip_terms[' . esc_attr( $taxonomy ) . '][]" value="id:' . esc_attr( $term->term_id ) . '"' . checked( in_array( (int) $term->term_id, array_map( 'intval', $selected ), true ), true, false ) . disabled( ! empty( $args['disabled'] ), true, false ) . '> ' . esc_html( $term->name ) . '</label>';
	}
}
