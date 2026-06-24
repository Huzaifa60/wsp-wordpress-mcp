<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_users( $input ) {
    $users  = get_users();
    $result = array();
    foreach ( $users as $u ) {
        $result[] = array(
            'id'           => $u->ID,
            'display_name' => $u->display_name,
            'email'        => $u->user_email,
            'roles'        => $u->roles,
            'registered'   => $u->user_registered,
        );
    }
    return array( 'users' => $result );
}
