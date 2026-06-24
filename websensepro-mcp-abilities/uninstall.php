<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

delete_option( 'wsp_mcp_abilities' );
delete_option( 'wsp_mcp_api_key' );
delete_option( 'wsp_mcp_db_version' );

wp_clear_scheduled_hook( 'wsp_mcp_session_cleanup' );

// Drop the native MCP sessions table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsp_mcp_sessions" );
