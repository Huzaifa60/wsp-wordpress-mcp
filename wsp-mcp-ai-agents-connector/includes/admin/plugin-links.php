<?php
/**
 * Quick links under the plugin name on the Plugins screen.
 *
 * Adds Settings | Connection | About Us in front of core's Deactivate link so
 * the MCP pages are one click away right after activation.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Prepend the MCP admin page links to this plugin's action links.
 *
 * @param string[] $links Action links core already built (e.g. Deactivate).
 * @return string[]
 */
function wsp_mcp_plugin_action_links( $links ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $links;
	}

	$pages = array(
		'wsp-mcp-abilities'  => __( 'Settings', 'wsp-mcp-ai-agents-connector' ),
		'wsp-mcp-connection' => __( 'Connection', 'wsp-mcp-ai-agents-connector' ),
		'wsp-mcp-about'      => __( 'About Us', 'wsp-mcp-ai-agents-connector' ),
	);

	$ours = array();
	foreach ( $pages as $slug => $label ) {
		$ours[ $slug ] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . $slug ) ),
			esc_html( $label )
		);
	}

	return array_merge( $ours, $links );
}
add_filter(
	'plugin_action_links_' . plugin_basename( WSP_MCP_DIR . 'wsp-mcp-ai-agents-connector.php' ),
	'wsp_mcp_plugin_action_links'
);
