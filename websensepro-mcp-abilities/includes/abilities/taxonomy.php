<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_categories( $input ) {
    $cats   = get_categories( array( 'hide_empty' => false ) );
    $result = array();
    foreach ( $cats as $c ) {
        $result[] = array( 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => $c->count, 'parent' => $c->parent );
    }
    return array( 'categories' => $result );
}

function wsp_execute_create_category( $input ) {
    $args = array();
    if ( ! empty( $input['description'] ) ) $args['description'] = sanitize_text_field( wp_unslash( $input['description'] ) );
    if ( ! empty( $input['parent'] ) )      $args['parent']      = intval( $input['parent'] );
    $result = wp_insert_term( sanitize_text_field( wp_unslash( $input['name'] ) ), 'category', $args );
    if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
    return array( 'success' => true, 'id' => $result['term_id'], 'name' => sanitize_text_field( wp_unslash( $input['name'] ) ) );
}

function wsp_execute_get_tags( $input ) {
    $tags   = get_tags( array( 'hide_empty' => false ) );
    $result = array();
    foreach ( $tags as $t ) {
        $result[] = array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count );
    }
    return array( 'tags' => $result );
}

function wsp_execute_create_tag( $input ) {
    $args   = ! empty( $input['description'] ) ? array( 'description' => sanitize_text_field( wp_unslash( $input['description'] ) ) ) : array();
    $result = wp_insert_term( sanitize_text_field( wp_unslash( $input['name'] ) ), 'post_tag', $args );
    if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
    return array( 'success' => true, 'id' => $result['term_id'], 'name' => sanitize_text_field( wp_unslash( $input['name'] ) ) );
}
