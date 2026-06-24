<?php
/**
 * Transport capability helpers.
 *
 * The plugin ships its own native MCP server (REST endpoint
 * /wp-json/wsp-mcp/v1/mcp), so an MCP transport is always available and no
 * companion plugin is required.
 *
 * As of v2.2.0 the legacy dual-mode path (registering abilities via the
 * WordPress Abilities API / mcp-adapter when present) has been removed. This
 * file is retained as a small stub: `wsp_mcp_transport_available()` is kept for
 * back-compat and readability for any caller/add-on that references it.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Whether the plugin can serve MCP at all.
 *
 * Always true — the native server provides the transport regardless of any
 * companion plugin. Retained for back-compat and readability.
 *
 * @return bool
 */
function wsp_mcp_transport_available() {
	return true;
}
