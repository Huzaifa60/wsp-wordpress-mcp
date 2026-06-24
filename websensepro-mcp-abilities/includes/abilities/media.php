<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_media( $input ) {
    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 20;
    $args     = array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => $per_page );
    if ( ! empty( $input['type'] ) ) $args['post_mime_type'] = sanitize_mime_type( $input['type'] );
    $q      = new WP_Query( $args );
    $result = array();
    foreach ( $q->posts as $item ) {
        $result[] = array(
            'id'    => $item->ID,
            'title' => $item->post_title,
            'url'   => wp_get_attachment_url( $item->ID ),
            'type'  => $item->post_mime_type,
            'date'  => get_the_date( 'Y-m-d', $item->ID ),
        );
    }
    return array( 'media' => $result, 'total' => $q->found_posts );
}
