<?php
/**
 * Staff AI endpoint tests.
 *
 * Verifies:
 * - Route registration: the Content AI routes, and no console route (11.0.0)
 * - Permission check logic (auth gate + capability gate)
 * - Error response handling (no secrets, no stack traces)
 *
 * Runs standalone (no WordPress bootstrap).
 *
 * @package widrow-ai/tests
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-stubs.php';

// ── Additional WP stubs needed for Staff AI ─────────────────────────────

// Track registered REST routes.
global $_wp_test_rest_routes, $_wp_test_current_user, $_wp_test_user_caps;
$_wp_test_rest_routes  = array();
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params  = array();
		private $headers = array();
		private $body    = array();

		public function __construct( string $method = 'GET', string $route = '' ) {}

		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function has_param( string $key ): bool {
			return array_key_exists( $key, $this->params );
		}

		public function get_json_params(): array {
			return $this->body;
		}

		public function set_body_params( array $body ): void {
			$this->body = $body;
		}

		public function get_header( string $key ): ?string {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}

		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args ): void {
		global $_wp_test_rest_routes;
		$_wp_test_rest_routes[ $namespace . $route ] = $args;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( string $regex, string $query, string $after = 'bottom' ): void {}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
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

if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( string $var, $default = '' ) {
		return $default;
	}
}

if ( ! function_exists( 'rawurlencode' ) ) {
	// Built-in PHP function, but just in case.
}

// Stub WP_User for wp_get_current_user.
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID             = 0;
		public $user_login     = '';
		public $user_email     = '';
		public $display_name   = '';
		public $user_firstname = '';
		public $roles          = array();

		public function __construct( int $id = 0 ) {
			$this->ID = $id;
		}

		public function exists(): bool {
			return $this->ID > 0;
		}

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

if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( int $id ): void {}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, $value ) {
		return null;
	}
}

// Staff AI resolves the backend URL via \Widrow_AI::get_def_api_url_internal();
// stub it so backend_request() reaches the (stubbed) wp_remote_* layer instead
// of fataling on a missing Widrow_AI class.
if ( ! class_exists( 'Widrow_AI' ) ) {
	class Widrow_AI {
		public static function get_def_api_url_internal(): ?string {
			return $GLOBALS['_widrow_test_api_url'] ?? 'https://def-api.test';
		}
	}
}

// Load the classes under test.
require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-jwt.php';
require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-staff-ai.php';

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

function assert_equals( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected " . var_export( $expected, true ) . ", got " . var_export( $actual, true ) . ")\n";
	}
}

echo "=== Staff AI Tests ===\n";

// ── 1. Route registration ───────────────────────────────────────────────
// 11.0.0: the console moved to the Tenant Portal. Only Content AI's routes
// stay under /staff-ai; none of the console's is registered.
echo "\n[1] REST route registration\n";
Widrow_AI_Staff_AI::register_rest_routes();

$content_routes = array(
	'a3-ai/v1/staff-ai/content/drafts',
	'a3-ai/v1/staff-ai/content/create',
	'a3-ai/v1/staff-ai/content/last-run',
	'a3-ai/v1/staff-ai/content/summary',
	'a3-ai/v1/staff-ai/content/targets',
	'a3-ai/v1/staff-ai/content/target-search',
);
foreach ( $content_routes as $route ) {
	assert_true( isset( $_wp_test_rest_routes[ $route ] ), "route registered: $route" );
}

$console_routes = array(
	'a3-ai/v1/staff-ai/conversations',
	'a3-ai/v1/staff-ai/chat',
	'a3-ai/v1/staff-ai/status',
	'a3-ai/v1/staff-ai/tools',
	'a3-ai/v1/staff-ai/uploads/init',
	'a3-ai/v1/staff-ai/documents',
	'a3-ai/v1/staff-ai/memories',
	'a3-ai/v1/staff-ai/usage',
	'a3-ai/v1/staff-ai/triage-schedule',
	'a3-ai/v1/staff-ai/tasks',
	'a3-ai/v1/staff-ai/user/integrations',
	'a3-ai/v1/staff-ai/projects',
	'a3-ai/v1/staff-ai/mail-connections',
);
foreach ( $console_routes as $route ) {
	assert_true( ! isset( $_wp_test_rest_routes[ $route ] ), "console route NOT registered: $route" );
}
$outside_content = array_filter(
	array_keys( $_wp_test_rest_routes ),
	static fn( $r ) => false !== strpos( $r, '/staff-ai/' ) && false === strpos( $r, '/staff-ai/content/' )
);
assert_equals( array(), array_values( $outside_content ), 'every /staff-ai route left is a Content AI route' );

// 2. All Staff AI routes have permission callbacks. A route registered with a
// single handler has permission_callback at the top level; a multi-method
// route (e.g. GET+POST /content/targets) is a list of handlers, each of which
// must carry its own.
echo "\n[2] Permission callbacks assigned\n";
foreach ( $_wp_test_rest_routes as $route => $args ) {
	if ( strpos( $route, 'staff-ai' ) === false ) {
		continue;
	}
	$handlers = isset( $args['methods'] ) ? array( $args ) : $args;
	foreach ( $handlers as $i => $handler ) {
		assert_true(
			is_array( $handler ) && ! empty( $handler['permission_callback'] ),
			"permission_callback on $route (handler $i)"
		);
	}
}

// ── 4. Permission check: unauthenticated → 401 ─────────────────────────
echo "\n[4] Permission check — unauthenticated\n";
$_wp_test_current_user = null;
$_wp_test_user_caps    = array();
$result = Widrow_AI_Staff_AI::rest_permission_check();
assert_true( is_wp_error( $result ), 'unauthenticated returns WP_Error' );
assert_equals( 'rest_not_logged_in', $result->get_error_code(), 'error code is rest_not_logged_in' );
$data = $result->get_error_data();
assert_equals( 401, $data['status'], 'HTTP status is 401' );

// ── 5. Permission check: authenticated but no capability → 403 ─────────
echo "\n[5] Permission check — no staff_ai capability\n";
$_wp_test_current_user     = new WP_User( 1 );
$_wp_test_current_user->ID = 1;
$_wp_test_user_caps        = array(); // No capabilities.
$result = Widrow_AI_Staff_AI::rest_permission_check();
assert_true( is_wp_error( $result ), 'no capability returns WP_Error' );
// 5.7.7: the capability refusal carries a DISTINCT named code so callers can
// tell the gate from every other 403 (was the generic rest_forbidden).
assert_equals( 'def_staff_access_required', $result->get_error_code(), 'error code is def_staff_access_required' );
$data = $result->get_error_data();
assert_equals( 403, $data['status'], 'HTTP status is 403' );

// ── 6. Permission check: authenticated with capability → true ───────────
echo "\n[6] Permission check — with widrow_staff_access\n";
$_wp_test_user_caps = array( 'widrow_staff_access' );
$result = Widrow_AI_Staff_AI::rest_permission_check();
assert_equals( true, $result, 'staff_access capability grants access' );

// ── 7. Permission check: management access also works ───────────────────
echo "\n[7] Permission check — with widrow_management_access\n";
$_wp_test_user_caps = array( 'widrow_management_access' );
$result = Widrow_AI_Staff_AI::rest_permission_check();
assert_equals( true, $result, 'widrow_management_access capability grants access' );

// ── 8. Error responses don't leak secrets ───────────────────────────────
echo "\n[8] Error message safety\n";
// Backend not configured → should not expose internal paths.
$_wp_test_current_user     = new WP_User( 1 );
$_wp_test_current_user->ID = 1;
$_wp_test_user_caps        = array( 'widrow_staff_access' );
update_option( 'widrow_ai_staff_ai_api_url', '' ); // Not configured.

// Ask Content AI for a draft without a backend URL.
$request = new WP_REST_Request( 'POST', '/staff-ai/content/create' );
$request->set_body_params( array( 'keyphrase' => 'test' ) );
$result = Widrow_AI_Staff_AI::rest_content_create( $request );
assert_true( is_wp_error( $result ), 'no backend URL returns WP_Error' );
$msg = $result->get_error_message();
assert_true( strpos( $msg, 'not configured' ) !== false, 'error mentions configuration needed' );
assert_true( strpos( $msg, '/home/' ) === false, 'no file paths in error' );
assert_true( strpos( $msg, 'password' ) === false, 'no passwords in error' );

echo "\n--- Staff AI Tests: $pass passed, $fail failed ---\n";
exit( $fail > 0 ? 1 : 0 );
