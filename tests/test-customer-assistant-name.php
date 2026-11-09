<?php
/**
 * Customer assistant-name fetch tests (Widrow_AI_Tools::get_customer_assistant_name).
 *
 * Verifies the cache + fail-safe logic that backs the Customer Chat header/greeting
 * employee name (Employee & Tools slice 1a/1b):
 * - a positive transient cache is returned without hitting DEF
 * - the '' negative-cache sentinel returns '' (caller falls back to branding)
 * - no API key (not connected to DEF) returns '' without any network call
 *
 * The curl SUCCESS path (live DEF returning a name) is covered by the end-to-end
 * canary + DEF's own /api/customer/identity tests, not here (no network in unit).
 *
 * Runs standalone (no WordPress bootstrap). Uses ReflectionMethod is not needed —
 * get_customer_assistant_name() is public.
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

require_once __DIR__ . '/wp-stubs.php';

// Minimal WP stubs used only on paths we don't reach (kept for class load safety).
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return false;
	}
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
// Stub Widrow_AI so get_def_api_url_internal() resolves if ever reached.
if ( ! class_exists( 'Widrow_AI' ) ) {
	class Widrow_AI {
		public static function get_def_api_url_internal(): string {
			return 'http://127.0.0.1:9'; // discard port — never actually called in these tests
		}
	}
}

require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-tools.php';

$pass = 0;
$fail = 0;

function assert_same( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
	} else {
		$fail++;
		echo "  FAIL: $label (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	}
}

function reset_transients(): void {
	global $_wp_test_transients;
	$_wp_test_transients = array();
}

echo "=== Customer Assistant Name Tests ===\n";

// ── 1. Positive cache hit — returns the cached name, no DEF call ──────────
echo "\n[1] Positive transient cache is returned\n";
reset_transients();
set_transient( 'widrow_ai_customer_assistant_name', 'Bruce', 900 );
assert_same( 'Bruce', \Widrow_AI_Tools::get_customer_assistant_name(), 'cached name returned' );

// ── 2. Negative-cache sentinel ('' transient) → '' (use branding fallback) ─
echo "\n[2] Empty-string negative cache returns '' (fallback)\n";
reset_transients();
set_transient( 'widrow_ai_customer_assistant_name', '', 60 );
assert_same( '', \Widrow_AI_Tools::get_customer_assistant_name(), 'negative cache returns empty' );

// ── 3. Not connected (no API key) → '' without any network call ──────────
echo "\n[3] No API key → '' (no network)\n";
reset_transients();
\Widrow_AI_Encryption::set_secret( 'widrow_ai_api_key', '' );
assert_same( '', \Widrow_AI_Tools::get_customer_assistant_name(), 'no api key returns empty' );

echo "\n=== $pass passed, $fail failed ===\n";
exit( $fail > 0 ? 1 : 0 );
