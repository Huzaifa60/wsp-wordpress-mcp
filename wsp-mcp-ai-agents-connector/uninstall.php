<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

delete_option( 'wsp_mcp_abilities' );
delete_option( 'wsp_mcp_api_key' );
delete_option( 'wsp_mcp_db_version' );
delete_option( 'wsp_mcp_oauth_enabled' );
delete_option( 'wsp_mcp_first_success' );
delete_metadata( 'user', 0, 'wsp_mcp_review_notice', '', true );

wp_clear_scheduled_hook( 'wsp_mcp_session_cleanup' );
wp_clear_scheduled_hook( 'wsp_mcp_audit_log_cleanup' );
wp_clear_scheduled_hook( 'wsp_mcp_oauth_cleanup' );

// Drop the native MCP sessions table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsp_mcp_sessions" );

// Drop the MCP audit log table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsp_mcp_audit_log" );

// Drop the native OAuth authorization server tables.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsp_mcp_oauth_clients" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsp_mcp_oauth_codes" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsp_mcp_oauth_tokens" );
