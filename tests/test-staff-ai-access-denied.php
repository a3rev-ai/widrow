<?php
/**
 * Staff AI access-denied refusals — copy, code, and shape (5.7.7).
 *
 * One message, one source: Widrow_AI_Staff_AI::access_denied_message() is the
 * canonical copy, and access_denied_error() the canonical REST refusal with
 * the DISTINCT code `def_staff_access_required` — so a caller can tell "the
 * gate refused you" from every other 401/403 by code, not status arithmetic
 * (the 2026-08-09 wpunit probe could not distinguish a rejected gate from an
 * absent backend; the named code is the fix for that class).
 *
 * Also pins (11.0.0) that the console's BFF passthroughs and the staff
 * stream-token channel are gone: Staff AI is served by the Tenant Portal.
 *
 * NOT pinned here, deliberately: that administrators PASS the gate. That is
 * map_def_capabilities (map_meta_cap) behaviour, and this harness's
 * current_user_can stub does not model the filter — a stub-level pin either
 * way would assert the stub, not the plugin. It belongs to the wpunit suite
 * against real WP.
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package widrow-ai/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Additional stubs ─────────────────────────────────────────────────────────

global $_wp_test_rest_routes, $_wp_test_current_user, $_wp_test_user_caps;
$_wp_test_rest_routes  = array();
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = array() ): bool {
		global $_wp_test_rest_routes;
		$_wp_test_rest_routes[ $namespace . $route ] = $args;
		return true;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( '__return_true' ) ) {
	function __return_true(): bool {
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$a ): void {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$a ): void {}
}
if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( ...$a ): void {}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		global $_wp_test_current_user;
		return $_wp_test_current_user !== null;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		global $_wp_test_user_caps;
		return in_array( $cap, $_wp_test_user_caps, true );
	}
}
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID = 0;
		public function __construct( int $id = 0 ) { $this->ID = $id; }
		public function exists(): bool { return $this->ID > 0; }
		public function has_cap( string $cap ): bool {
			global $_wp_test_user_caps;
			return in_array( $cap, $_wp_test_user_caps, true );
		}
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): WP_User {
		global $_wp_test_current_user;
		return $_wp_test_current_user ?? new WP_User( 0 );
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-api-registry.php';
require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-routes.php';
require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-staff-ai.php';

// ── Test helpers ─────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function assert_test( $cond, string $label ): void {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "[PASS] $label\n";
	} else {
		$fail++;
		echo "  ✗ FAILED: $label\n";
	}
}

function as_user( ?int $id, array $caps ): void {
	global $_wp_test_current_user, $_wp_test_user_caps;
	$_wp_test_current_user = $id === null ? null : new WP_User( $id );
	$_wp_test_user_caps    = $caps;
}

// ── [1] The canonical copy says who can fix it and where ────────────────────
echo "\n[1] access_denied_message() tells the user who can fix it and where\n";

as_user( 6, array() );
$msg = Widrow_AI_Staff_AI::access_denied_message();
assert_test( strpos( $msg, 'Ask your site administrator' ) !== false, 'the user is told who can fix it' );
assert_test( strpos( $msg, 'Setup Assistant' ) !== false, 'the copy names how their administrator grants it' );

// ── [2] The canonical error carries the named code ──────────────────────────
echo "\n[2] access_denied_error() is a named, stable refusal\n";

$err = Widrow_AI_Staff_AI::access_denied_error();
assert_test( $err instanceof WP_Error, 'refusal is a WP_Error' );
assert_test( 'def_staff_access_required' === $err->get_error_code(), 'code is def_staff_access_required, NOT rest_forbidden' );
assert_test( 403 === ( $err->get_error_data()['status'] ?? 0 ), 'status data is 403' );

// ── [3] rest_permission_check: same gate, better refusal ────────────────────
echo "\n[3] rest_permission_check() refuses the same population, with the named code\n";

as_user( null, array() );
$anon = Widrow_AI_Staff_AI::rest_permission_check();
assert_test( $anon instanceof WP_Error && 'rest_not_logged_in' === $anon->get_error_code(), 'anonymous: rest_not_logged_in 401 unchanged' );
assert_test( 401 === ( $anon->get_error_data()['status'] ?? 0 ), 'anonymous status stays 401' );

as_user( 7, array() );
$uncapped = Widrow_AI_Staff_AI::rest_permission_check();
assert_test( $uncapped instanceof WP_Error && 'def_staff_access_required' === $uncapped->get_error_code(), 'logged-in uncapped: named 403 refusal' );

as_user( 8, array( 'widrow_staff_access' ) );
assert_test( true === Widrow_AI_Staff_AI::rest_permission_check(), 'widrow_staff_access passes' );

as_user( 9, array( 'widrow_management_access' ) );
assert_test( true === Widrow_AI_Staff_AI::rest_permission_check(), 'widrow_management_access passes' );

// ── [4] The console's passthroughs and staff stream token are gone (11.0.0) ─
echo "\n[4] No Staff AI passthrough or staff stream token; Customer Chat's routes stay\n";

Widrow_AI_Routes::register_rest_routes();
$ns = WIDROW_AI_API_NAME_SPACE;
assert_test( ! isset( $_wp_test_rest_routes[ $ns . '/staff-ai/chat/stream' ] ), 'no /staff-ai/chat/stream passthrough' );
assert_test( ! isset( $_wp_test_rest_routes[ $ns . '/staff-ai/status' ] ), 'no /staff-ai/status passthrough' );

$token = $_wp_test_rest_routes[ $ns . '/chat/stream-token' ] ?? null;
assert_test( is_array( $token ) && array( 'customer_chat' ) === ( $token['args']['channel']['enum'] ?? null ), 'chat/stream-token: customer_chat is the only channel' );
assert_test( is_array( $token ) && '__return_true' === ( $token['permission_callback'] ?? null ), 'chat/stream-token: open to anyone, as /chat/stream is' );

// Customer Chat voice (7.7.8): the widget's open-time read is a public GET proxied to
// DEF's customer voice status — anonymous visitors included, like /chat/stream.
$voice = $_wp_test_rest_routes[ $ns . '/chat/voice' ] ?? null;
assert_test( is_array( $voice ) && 'GET' === ( $voice['methods'] ?? null ) && '__return_true' === ( $voice['permission_callback'] ?? null ), 'chat/voice: a public GET' );
assert_test( is_array( $voice ) && array( 'Widrow_AI_Tools', 'rest_proxy_chat_voice' ) === ( $voice['callback'] ?? null ), 'chat/voice: proxied by rest_proxy_chat_voice' );

// ── Summary ─────────────────────────────────────────────────────────────────
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
