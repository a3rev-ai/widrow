<?php
/**
 * Customer Chat's transcribe route through WordPress (11.6.0): the clip a dictation
 * recorded, when the widget cannot post it to the platform directly.
 *
 * Verifies:
 * - the route is registered as a public POST, like the chat stream's proxy;
 * - a logged-in caller with a bad or missing nonce is refused (403) and nothing is forwarded;
 * - the body is forwarded unchanged, by POST, to the platform's
 *   /api/customer/voice/transcribe with the chat proxy's headers: the API key, the
 *   visitor's IP (what the visitor's message allowance is counted by) and, signed in,
 *   the user headers;
 * - the platform's status and JSON come back as they are, a refusal included.
 *
 * Runs standalone against a real one-request upstream (fixtures/json-upstream-server.php).
 *
 * @package widrow-ai/tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WIDROW_AI_PLUGIN_DIR' ) ) {
	define( 'WIDROW_AI_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WIDROW_AI_API_NAME_SPACE' ) ) {
	define( 'WIDROW_AI_API_NAME_SPACE', 'a3-ai/v1' );
}

require_once __DIR__ . '/wp-stubs.php';

global $_tr_logged_in, $_tr_nonce_valid, $_tr_routes, $_tr_upstream;
$_tr_logged_in   = false;
$_tr_nonce_valid = true;
$_tr_routes      = array();
$_tr_upstream    = 'http://127.0.0.1:1';

function is_user_logged_in(): bool {
	global $_tr_logged_in;
	return $_tr_logged_in;
}
function wp_verify_nonce( $nonce, $action ) {
	global $_tr_nonce_valid;
	return $_tr_nonce_valid && 'wp_rest' === $action;
}
function register_rest_route( string $ns, string $route, array $args ): void {
	global $_tr_routes;
	$_tr_routes[ $ns . $route ] = $args;
}
function apply_filters( string $hook, $value ) {
	return $value;
}
function add_action( string $h, $c, int $p = 10, int $a = 1 ): void {}
function __( string $t, string $d = 'default' ): string {
	return $t;
}

class WP_User {
	public $ID           = 0;
	public $display_name = '';
	public $user_email   = '';
	public $roles        = array();
	public function __construct( int $id = 0 ) {
		$this->ID = $id;
	}
}
function wp_get_current_user(): WP_User {
	global $_wp_test_current_user;
	return $_wp_test_current_user ?? new WP_User( 0 );
}

class WP_REST_Request {
	private $headers;
	private $body;
	public function __construct( array $headers = array(), string $body = '' ) {
		$this->headers = $headers;
		$this->body    = $body;
	}
	public function get_header( $key ) {
		foreach ( $this->headers as $name => $value ) {
			if ( strtolower( $name ) === strtolower( $key ) ) {
				return $value;
			}
		}
		return null;
	}
	public function get_body() {
		return $this->body;
	}
}
class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}
class WP_Error {
	public $code;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code;
		$this->data = $data;
	}
}
class Widrow_AI {
	public static function get_def_api_url_internal(): string {
		global $_tr_upstream;
		return $_tr_upstream;
	}
}
// The routes file ends by registering the tool routes; nothing of that is under test here.
class Widrow_AI_API_Registry {
	public static function instance() {
		return new self();
	}
	public function register_all_tools(): void {}
}

require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-tools.php';
require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-routes.php';

$pass = 0;
$fail = 0;
function assert_true( $value, string $label ): void {
	global $pass, $fail;
	if ( $value ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label\n";
	}
}

function free_port(): int {
	$s    = stream_socket_server( 'tcp://127.0.0.1:0', $e, $m );
	$name = stream_socket_get_name( $s, false );
	fclose( $s );
	return (int) substr( $name, strrpos( $name, ':' ) + 1 );
}

/**
 * Call the route against an upstream answering $status / $answer; returns [response, what the upstream received].
 */
function call_route( WP_REST_Request $request, int $status, string $answer ): array {
	global $_tr_upstream;
	$port = free_port();
	$srv  = proc_open(
		sprintf( '%s %s %d %d %s', escapeshellarg( PHP_BINARY ), escapeshellarg( __DIR__ . '/fixtures/json-upstream-server.php' ), $port, $status, escapeshellarg( $answer ) ),
		array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$pipes
	);
	stream_set_blocking( $pipes[1], true );
	if ( 'READY' !== trim( (string) fgets( $pipes[1], 64 ) ) ) {
		proc_terminate( $srv );
		return array( null, null );
	}
	$_tr_upstream = "http://127.0.0.1:$port";
	$response     = Widrow_AI_Tools::rest_proxy_chat_voice_transcribe( $request );
	$received     = null;
	if ( ! ( $response instanceof WP_Error ) ) {
		$received = json_decode( (string) fgets( $pipes[1] ), true );
	}
	proc_terminate( $srv );
	foreach ( $pipes as $p ) {
		fclose( $p );
	}
	proc_close( $srv );
	return array( $response, $received );
}

function has_header( array $received, string $line ): bool {
	return in_array( $line, $received['headers'] ?? array(), true );
}

update_option( 'widrow_ai_api_key', 'key-123' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$clip                   = '{"audio_base64":"QUFBQQ==","audio_mime":"audio/webm;codecs=opus","audio_seconds":2.4}';

echo "=== Customer Chat transcribe route ===\n";

// ── 1. Registered as a public POST, as the chat stream's proxy is ─────────
echo "\n[1] The route\n";
Widrow_AI_Routes::register_rest_routes();
$route = $_tr_routes['a3-ai/v1/chat/voice/transcribe'] ?? null;
$chat  = $_tr_routes['a3-ai/v1/chat/stream'] ?? null;
assert_true( is_array( $route ), 'a3-ai/v1/chat/voice/transcribe is registered' );
assert_true( 'POST' === ( $route['methods'] ?? '' ), 'by POST' );
assert_true( is_array( $chat ) && ( $route['permission_callback'] ?? null ) === $chat['permission_callback'], 'with the chat stream route\'s permission rule (anyone; the nonce rule runs in the callback)' );
assert_true( array( 'Widrow_AI_Tools', 'rest_proxy_chat_voice_transcribe' ) === ( $route['callback'] ?? null ), 'its callback is the transcribe proxy' );

// ── 2. Signed in with a bad or missing nonce: refused, nothing forwarded ──
echo "\n[2] The nonce rule\n";
$_tr_logged_in         = true;
$_tr_nonce_valid       = false;
$_wp_test_current_user = new WP_User( 7 );
foreach ( array( array(), array( 'X-WP-Nonce' => 'stale' ) ) as $headers ) {
	list( $response ) = call_route( new WP_REST_Request( $headers, $clip ), 200, '{"text":"never"}' );
	assert_true( $response instanceof WP_Error && 'invalid_nonce' === $response->code && 403 === ( $response->data['status'] ?? 0 ), 'signed in, ' . ( $headers ? 'a bad' : 'no' ) . ' nonce: 403 invalid_nonce, nothing forwarded' );
}

// ── 3. A visitor: the body forwarded unchanged, with the proxy headers ────
echo "\n[3] A visitor\n";
$_tr_logged_in         = false;
$_wp_test_current_user = null;
list( $response, $received ) = call_route( new WP_REST_Request( array(), $clip ), 200, '{"text":"Two coffees please."}' );
assert_true( is_array( $received ) && 'POST /api/customer/voice/transcribe HTTP/1.1' === $received['request'], 'POST to the platform\'s /api/customer/voice/transcribe (got ' . ( $received['request'] ?? 'nothing' ) . ')' );
assert_true( is_array( $received ) && $clip === $received['body'], 'the body unchanged' );
assert_true( is_array( $received ) && has_header( $received, 'Content-Type: application/json' ) && has_header( $received, 'X-DEF-API-Key: key-123' ), 'the API key and the JSON type' );
assert_true( is_array( $received ) && has_header( $received, 'X-DEF-Client-IP: 203.0.113.7' ), 'the visitor\'s IP, which their allowance is counted by' );
assert_true( is_array( $received ) && ! preg_grep( '/^X-DEF-User/i', $received['headers'] ), 'no user headers for a visitor' );
assert_true( $response instanceof WP_REST_Response && 200 === $response->status && array( 'text' => 'Two coffees please.' ) === $response->data, 'the platform\'s 200 and its text, as they are' );

// ── 4. Signed in with a good nonce: the user headers too ──────────────────
echo "\n[4] Signed in\n";
$_tr_logged_in                       = true;
$_tr_nonce_valid                     = true;
$_wp_test_current_user               = new WP_User( 7 );
$_wp_test_current_user->display_name = 'Ada Lovelace';
list( $response, $received ) = call_route( new WP_REST_Request( array( 'X-WP-Nonce' => 'good' ), $clip ), 200, '{"text":"ok"}' );
assert_true( is_array( $received ) && has_header( $received, 'X-DEF-User: 7' ) && has_header( $received, 'X-DEF-User-Display-Name: Ada%20Lovelace' ), 'X-DEF-User and the display name' );
assert_true( is_array( $received ) && ! preg_grep( '/^X-DEF-User-(Email|Roles|Capabilities)/i', $received['headers'] ), 'no staff-only headers (Customer Chat\'s privacy boundary)' );

// ── 5. A refusal comes back as the platform sent it ───────────────────────
echo "\n[5] Refusals\n";
$_tr_logged_in = false;
foreach ( array(
	array( 422, '{"detail":{"error":"nothing_heard","message":"Nothing was heard."}}' ),
	array( 429, '{"detail":"Too many messages"}' ),
	array( 502, '{"detail":{"error":"voice_unavailable","message":"Voice is unavailable."}}' ),
) as $case ) {
	list( $response ) = call_route( new WP_REST_Request( array(), $clip ), $case[0], $case[1] );
	assert_true( $response instanceof WP_REST_Response && $case[0] === $response->status && json_decode( $case[1], true ) === $response->data, $case[0] . ': the status and the JSON as they are' );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
