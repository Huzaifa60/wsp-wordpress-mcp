<?php
/**
 * MCP authentication (Milestone M4).
 *
 * Accepts three credential types at the MCP endpoint:
 *   1. Application Password (HTTP Basic) — validated by WordPress core before
 *      our handler runs, so the current user is already set when we see it.
 *   2. Plugin API key via the X-WSP-MCP-API-Key header.
 *   3. The same API key presented as `Authorization: Bearer <key>` (the
 *      convention most MCP clients use for static credentials).
 *
 * The API key is stored in admin-only settings, so a request bearing it is
 * treated as administrator-trusted and mapped to a site admin user so that
 * capability checks on tools resolve correctly.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSP_MCP_Auth {

	const OPTION_KEY = 'wsp_mcp_api_key';

	/** @return string The plugin API key, generating one on first access. */
	public static function get_api_key() {
		$key = get_option( self::OPTION_KEY, '' );
		if ( '' === $key ) {
			$key = self::regenerate_api_key();
		}
		return $key;
	}

	/** Generate, store and return a fresh API key. */
	public static function regenerate_api_key() {
		$key = bin2hex( random_bytes( 16 ) );
		update_option( self::OPTION_KEY, $key, false );
		return $key;
	}

	/**
	 * Authenticate an MCP request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_REST_Response True on success, JSON-RPC error response otherwise.
	 */
	public static function authenticate( $request ) {
		// 1. Application Password — WordPress core has already validated the
		// Basic header and set the current user by the time we run.
		if ( is_user_logged_in() ) {
			return true;
		}

		$settings_key = self::get_api_key();

		// 2. Authorization: Bearer <api-key>.
		$auth = $request->get_header( 'authorization' );
		if ( is_string( $auth ) && 0 === stripos( $auth, 'bearer ' ) ) {
			$token = trim( substr( $auth, 7 ) );
			if ( hash_equals( $settings_key, $token ) ) {
				self::assume_admin();
				return true;
			}
		}

		// 3. X-WSP-MCP-API-Key header.
		$api_key = $request->get_header( 'x-wsp-mcp-api-key' );
		if ( is_string( $api_key ) && '' !== $api_key && hash_equals( $settings_key, $api_key ) ) {
			self::assume_admin();
			return true;
		}

		return self::challenge();
	}

	/**
	 * Build a SHA-256 fingerprint of the request credential, used to bind a
	 * session to whoever created it.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string Fingerprint hash.
	 */
	public static function fingerprint( $request ) {
		$auth = $request->get_header( 'authorization' );
		if ( is_string( $auth ) && '' !== $auth ) {
			return hash( 'sha256', 'auth:' . $auth );
		}
		$api_key = $request->get_header( 'x-wsp-mcp-api-key' );
		if ( is_string( $api_key ) && '' !== $api_key ) {
			return hash( 'sha256', 'apikey:' . $api_key );
		}
		return hash( 'sha256', 'user:' . get_current_user_id() );
	}

	/**
	 * Capability gate for a tool.
	 *
	 * @param string $cap Capability to require (empty = authenticated only).
	 * @return true|WP_Error
	 */
	public static function require_cap( $cap ) {
		if ( '' === $cap || null === $cap ) {
			return true;
		}
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'forbidden', "You do not have permission ({$cap}) to call this tool." );
		}
		return true;
	}

	/** Map an API-key request to the lowest-ID administrator. */
	private static function assume_admin() {
		if ( is_user_logged_in() ) {
			return;
		}
		$admins = get_users( array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'ID',
		) );
		if ( ! empty( $admins ) ) {
			wp_set_current_user( (int) $admins[0] );
		}
	}

	/** Build a 401 challenge response. */
	private static function challenge() {
		$response = new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'error'   => array(
				'code'    => -32001,
				'message' => 'Authentication required. Use an Application Password (Basic), Authorization: Bearer <api-key>, or the X-WSP-MCP-API-Key header.',
			),
		), 401 );
		$response->header( 'WWW-Authenticate', 'Bearer' );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		return $response;
	}
}
