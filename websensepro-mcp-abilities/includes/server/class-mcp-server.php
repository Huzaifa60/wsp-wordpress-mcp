<?php
/**
 * Native MCP server (Milestone M1 + M2).
 *
 * Implements the Model Context Protocol over a single Streamable-HTTP REST
 * endpoint, with no dependency on the WordPress MCP Adapter. Speaks JSON-RPC
 * 2.0: initialize, tools/list, tools/call, ping. Tools are contributed by the
 * native tool registry, which wraps the plugin's existing wsp_execute_*
 * callbacks.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSP_MCP_Server {

	/** @var array<string,array> name => tool spec. */
	private static $tools = array();

	/** MCP protocol versions this server recognizes. */
	const SUPPORTED_PROTOCOLS = array( '2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25' );
	const DEFAULT_PROTOCOL     = '2025-06-18';

	/** Rate limit: requests per window per IP. */
	const RATE_MAX    = 120;
	const RATE_WINDOW = 60;

	/** Boot: register tools and the REST route. */
	public static function init() {
		wsp_mcp_register_native_tools();
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register a tool.
	 *
	 * @param string $name MCP tool name (a-z0-9_).
	 * @param array  $spec {
	 *     @type string   $description Human/agent-facing description.
	 *     @type array    $inputSchema JSON Schema for arguments.
	 *     @type callable $callback    fn(array $args): array|WP_Error.
	 *     @type string   $capability  Required capability ('' = authenticated only).
	 *     @type string   $enable_key  Registry key for the admin on/off toggle.
	 * }
	 */
	public static function register_tool( $name, array $spec ) {
		self::$tools[ $name ] = wp_parse_args( $spec, array(
			'description' => '',
			'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
			'callback'    => null,
			'capability'  => '',
			'enable_key'  => '',
		) );
	}

	/** Register the MCP REST endpoint. Auth is enforced inside the handler. */
	public static function register_routes() {
		register_rest_route( 'wsp-mcp/v1', '/mcp', array(
			'methods'             => array( 'GET', 'POST', 'DELETE', 'OPTIONS' ),
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // Auth handled in WSP_MCP_Auth.
		) );
	}

	/** Tools enabled by the admin toggles, keyed by name. */
	private static function enabled_tools() {
		$enabled = array();
		foreach ( self::$tools as $name => $spec ) {
			if ( '' === $spec['enable_key'] || wsp_mcp_is_enabled( $spec['enable_key'] ) ) {
				$enabled[ $name ] = $spec;
			}
		}
		return $enabled;
	}

	/** Main dispatch by HTTP method. */
	public static function handle( WP_REST_Request $request ) {
		$method = $request->get_method();

		if ( 'OPTIONS' === $method ) {
			return self::cors_preflight();
		}

		$origin = self::validate_origin( $request );
		if ( true !== $origin ) {
			return $origin;
		}

		$rate = self::check_rate_limit();
		if ( true !== $rate ) {
			return $rate;
		}

		if ( 'GET' === $method ) {
			// SSE not hosted here; tell clients to use POST (spec-compliant 405).
			$resp = self::rpc_error( null, -32600, 'Use HTTP POST for MCP communication.', 405 );
			$resp->header( 'Allow', 'POST, DELETE, OPTIONS' );
			return $resp;
		}

		if ( 'DELETE' === $method ) {
			return self::handle_delete( $request );
		}

		return self::handle_post( $request );
	}

	/** Handle a JSON-RPC POST. */
	private static function handle_post( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] ) {
			return self::rpc_error( null, -32600, 'Invalid JSON-RPC request.', 400 );
		}

		$rpc_method = isset( $body['method'] ) ? $body['method'] : '';
		$params     = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();
		$id         = isset( $body['id'] ) ? $body['id'] : null;

		// Authenticate every request.
		$auth = WSP_MCP_Auth::authenticate( $request );
		if ( true !== $auth ) {
			return $auth;
		}

		// Notifications (no id) need no response.
		if ( in_array( $rpc_method, array( 'notifications/initialized', 'initialized' ), true ) ) {
			return new WP_REST_Response( null, 202 );
		}

		if ( 'initialize' === $rpc_method ) {
			return self::do_initialize( $request, $params, $id );
		}

		// All other methods require a valid, credential-bound session.
		$session_check = self::validate_session( $request, $id );
		if ( true !== $session_check ) {
			return $session_check;
		}

		switch ( $rpc_method ) {
			case 'tools/list':
				return self::do_tools_list( $id );
			case 'tools/call':
				return self::do_tools_call( $id, $params );
			case 'ping':
				return self::rpc_result( $id, new stdClass() );
			case 'resources/list':
				return self::rpc_result( $id, array( 'resources' => array() ) );
			case 'prompts/list':
				return self::rpc_result( $id, array( 'prompts' => array() ) );
			default:
				return self::rpc_error( $id, -32601, 'Method not found: ' . $rpc_method, 200 );
		}
	}

	/** initialize: negotiate protocol, open a session. */
	private static function do_initialize( WP_REST_Request $request, $params, $id ) {
		$requested = isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';
		$protocol  = in_array( $requested, self::SUPPORTED_PROTOCOLS, true ) ? $requested : self::DEFAULT_PROTOCOL;

		$session_id  = bin2hex( random_bytes( 16 ) );
		$fingerprint = WSP_MCP_Auth::fingerprint( $request );
		WSP_MCP_Session_Store::create_session( $session_id, $fingerprint );

		$result = array(
			'protocolVersion' => $protocol,
			'serverInfo'      => array(
				'name'    => 'WebSensePro MCP',
				'version' => defined( 'WSP_MCP_VERSION' ) ? WSP_MCP_VERSION : '2.0.0',
			),
			'capabilities'    => array(
				'tools' => new stdClass(),
			),
		);

		$response = self::rpc_result( $id, $result );
		$response->header( 'Mcp-Session-Id', $session_id );
		return $response;
	}

	/** tools/list: advertise enabled tools. */
	private static function do_tools_list( $id ) {
		$tools = array();
		foreach ( self::enabled_tools() as $name => $spec ) {
			$tools[] = array(
				'name'        => $name,
				'description' => $spec['description'],
				'inputSchema' => $spec['inputSchema'],
			);
		}
		return self::rpc_result( $id, array( 'tools' => $tools ) );
	}

	/** tools/call: capability-gate then invoke the wrapped callback. */
	private static function do_tools_call( $id, $params ) {
		$name = isset( $params['name'] ) ? $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$enabled = self::enabled_tools();
		if ( ! isset( $enabled[ $name ] ) ) {
			return self::rpc_error( $id, -32602, 'Unknown or disabled tool: ' . $name, 200 );
		}
		$spec = $enabled[ $name ];

		$cap = WSP_MCP_Auth::require_cap( $spec['capability'] );
		if ( is_wp_error( $cap ) ) {
			return self::tool_text( $id, 'Error: ' . $cap->get_error_message(), true );
		}

		if ( ! is_callable( $spec['callback'] ) ) {
			return self::tool_text( $id, 'Error: tool has no handler.', true );
		}

		try {
			$result = call_user_func( $spec['callback'], $args );
		} catch ( \Throwable $e ) {
			return self::tool_text( $id, 'Error: ' . $e->getMessage(), true );
		}

		if ( is_wp_error( $result ) ) {
			return self::tool_text( $id, 'Error: ' . $result->get_error_message(), true );
		}

		return self::tool_text( $id, wp_json_encode( $result, JSON_PRETTY_PRINT ), false );
	}

	/** DELETE: terminate a session. */
	private static function handle_delete( WP_REST_Request $request ) {
		$auth = WSP_MCP_Auth::authenticate( $request );
		if ( true !== $auth ) {
			return $auth;
		}
		$session_id = $request->get_header( 'mcp-session-id' );
		if ( is_string( $session_id ) && '' !== $session_id ) {
			WSP_MCP_Session_Store::delete_session( $session_id );
		}
		return self::with_headers( new WP_REST_Response( null, 200 ) );
	}

	/**
	 * Validate the Mcp-Session-Id header against the store and its fingerprint.
	 *
	 * @return true|WP_REST_Response
	 */
	private static function validate_session( WP_REST_Request $request, $id ) {
		$session_id = $request->get_header( 'mcp-session-id' );
		if ( ! is_string( $session_id ) || '' === $session_id ) {
			return self::rpc_error( $id, -32600, 'Mcp-Session-Id header required. Call initialize first.', 400 );
		}
		if ( ! WSP_MCP_Session_Store::touch_session( $session_id ) ) {
			return self::rpc_error( $id, -32600, 'Session not found or expired. Re-initialize.', 404 );
		}
		$stored = WSP_MCP_Session_Store::get_fingerprint( $session_id );
		if ( '' !== $stored && ! hash_equals( $stored, WSP_MCP_Auth::fingerprint( $request ) ) ) {
			return self::rpc_error( $id, -32600, 'Session credential mismatch. Re-initialize.', 403 );
		}
		return true;
	}

	/* ---------- Origin / rate limiting ---------- */

	private static function validate_origin( WP_REST_Request $request ) {
		$origin = $request->get_header( 'origin' );
		if ( empty( $origin ) ) {
			return true; // Non-browser MCP client.
		}
		$host = wp_parse_url( $origin, PHP_URL_HOST );
		if ( ! $host ) {
			return self::rpc_error( null, -32600, 'Invalid Origin header.', 400 );
		}
		$allowed = apply_filters( 'wsp_mcp_allowed_origins', array(
			wp_parse_url( home_url(), PHP_URL_HOST ),
			'localhost', '127.0.0.1', '::1',
			'claude.ai', 'www.claude.ai', 'chatgpt.com', 'chat.openai.com',
		) );
		if ( ! in_array( $host, $allowed, true ) ) {
			return self::rpc_error( null, -32600, 'Origin not allowed.', 403 );
		}
		return true;
	}

	private static function check_rate_limit() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$key = 'wsp_mcp_rate_' . md5( $ip );
		$data = get_transient( $key );
		if ( false === $data || ! is_array( $data ) ) {
			set_transient( $key, array( 'count' => 1, 'start' => time() ), self::RATE_WINDOW );
			return true;
		}
		$data['count']++;
		set_transient( $key, $data, self::RATE_WINDOW );
		if ( $data['count'] > self::RATE_MAX ) {
			return self::rpc_error( null, -32600, 'Rate limit exceeded.', 429 );
		}
		return true;
	}

	/* ---------- Response helpers ---------- */

	private static function cors_preflight() {
		$response = new WP_REST_Response( null, 204 );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS' );
		$response->header( 'Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, Mcp-Session-Id, X-WSP-MCP-API-Key' );
		$response->header( 'Access-Control-Max-Age', '86400' );
		return $response;
	}

	private static function with_headers( WP_REST_Response $response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Access-Control-Expose-Headers', 'Mcp-Session-Id' );
		return $response;
	}

	private static function rpc_result( $id, $result ) {
		return self::with_headers( new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		), 200 ) );
	}

	private static function rpc_error( $id, $code, $message, $status = 200 ) {
		return self::with_headers( new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array( 'code' => $code, 'message' => $message ),
		), $status ) );
	}

	/** Wrap a tool result as an MCP content block. */
	private static function tool_text( $id, $text, $is_error ) {
		$result = array(
			'content' => array( array( 'type' => 'text', 'text' => (string) $text ) ),
		);
		if ( $is_error ) {
			$result['isError'] = true;
		}
		return self::rpc_result( $id, $result );
	}
}
