<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_site_info( $input ) {
    return array(
        'name'        => get_bloginfo( 'name' ),
        'url'         => get_site_url(),
        'tagline'     => get_bloginfo( 'description' ),
        'admin_email' => get_option( 'admin_email' ),
        'wp_version'  => get_bloginfo( 'version' ),
        'language'    => get_bloginfo( 'language' ),
    );
}

function wsp_execute_get_plugins( $input ) {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all    = get_plugins();
    $active = get_option( 'active_plugins', array() );
    $result = array();
    foreach ( $active as $file ) {
        if ( isset( $all[ $file ] ) ) {
            $result[] = array(
                'name'    => $all[ $file ]['Name'],
                'version' => $all[ $file ]['Version'],
                'author'  => $all[ $file ]['Author'],
                'file'    => $file,
            );
        }
    }
    return array( 'active_plugins' => $result, 'total' => count( $result ) );
}
