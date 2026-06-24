<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_search( $input ) {
    $keyword = isset( $input['query'] ) ? sanitize_text_field( wp_unslash( $input['query'] ) ) : '';
    $q       = new WP_Query( array( 's' => $keyword, 'post_status' => 'publish', 'posts_per_page' => 10 ) );
    $results = array();
    foreach ( $q->posts as $p ) {
        $results[] = array( 'id' => $p->ID, 'title' => $p->post_title, 'url' => get_permalink( $p->ID ), 'type' => $p->post_type );
    }
    return array( 'results' => $results, 'total' => $q->found_posts );
}
