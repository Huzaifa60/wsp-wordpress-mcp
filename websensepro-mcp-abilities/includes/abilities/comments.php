<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_comments( $input ) {
    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 20;
    $status   = isset( $input['status'] )   ? sanitize_text_field( wp_unslash( $input['status'] ) ) : '';
    $args     = array( 'number' => $per_page );
    if ( $status && 'all' !== $status ) $args['status'] = $status;
    $comments = get_comments( $args );
    $result   = array();
    foreach ( $comments as $c ) {
        $result[] = array(
            'id'      => $c->comment_ID,
            'post_id' => $c->comment_post_ID,
            'author'  => $c->comment_author,
            'email'   => $c->comment_author_email,
            'content' => wp_trim_words( $c->comment_content, 20 ),
            'status'  => $c->comment_approved,
            'date'    => $c->comment_date,
        );
    }
    return array( 'comments' => $result, 'total' => count( $result ) );
}

function wsp_execute_approve_comment( $input ) {
    return wp_set_comment_status( intval( $input['id'] ), 'approve' )
        ? array( 'success' => true,  'message' => 'Comment approved.' )
        : array( 'success' => false, 'error'   => 'Failed to approve.' );
}

function wsp_execute_delete_comment( $input ) {
    return wp_trash_comment( intval( $input['id'] ) )
        ? array( 'success' => true,  'message' => 'Comment trashed.' )
        : array( 'success' => false, 'error'   => 'Failed to trash comment.' );
}
