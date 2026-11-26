<?php
/**
 * No widrow-ai translation runs before WordPress's init action (WordPress 6.7+ raises
 * "translation loading was triggered too early" otherwise), and the bbPress tickets
 * tool still registers when bbPress is active and stays out when it is not.
 *
 * The tool is constructed on the hook and priority the plugin itself uses (read from
 * includes/class-widrow-ai.php); widrow_ai_register_tools fires where the routes class
 * fires it (read from includes/class-widrow-ai-routes.php). WordPress's own order is
 * then played out: plugins_loaded, then init.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
define( 'WIDROW_AI_API_NAME_SPACE', 'a3-ai/v1' );

$GLOBALS['hooks']      = array();
$GLOBALS['did']        = array();
$GLOBALS['early_i18n'] = array();

function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $hook ][ $priority ][] = $cb;
	return true;
}
function did_action( $hook ) {
	return $GLOBALS['did'][ $hook ] ?? 0;
}
function do_action( $hook ) {
	$GLOBALS['did'][ $hook ] = ( $GLOBALS['did'][ $hook ] ?? 0 ) + 1;
	$by_priority = $GLOBALS['hooks'][ $hook ] ?? array();
	ksort( $by_priority );
	foreach ( $by_priority as $cbs ) {
		foreach ( $cbs as $cb ) {
			$cb();
		}
	}
}
function __( $text, $domain = 'default' ) {
	if ( 'widrow-ai' === $domain && ! did_action( 'init' ) ) {
		$GLOBALS['early_i18n'][] = $text;
	}
	return $text;
}

require_once __DIR__ . '/../includes/class-widrow-ai-api-registry.php';
require_once __DIR__ . '/../includes/tools/class-widrow-ai-tool-base.php';
require_once __DIR__ . '/../includes/tools/class-widrow-ai-tool-bbpress-tickets.php';

$passed = 0;
$failed = 0;
function check( bool $ok, string $name ): void {
	global $passed, $failed;
	$ok ? $passed++ : $failed++;
	echo ( $ok ? "  \xE2\x9C\x93 " : "  \xE2\x9C\x97 FAILED: " ) . $name . "\n";
}

echo "=== Translations load at init ===\n\n";

$main   = file_get_contents( __DIR__ . '/../includes/class-widrow-ai.php' );
$routes = file_get_contents( __DIR__ . '/../includes/class-widrow-ai-routes.php' );
preg_match( "/add_action\(\s*'(\w+)',\s*function \(\) \{\s*new Widrow_AI_Tool_BbPress_Tickets\(\);\s*\},\s*(\d+)\s*\)/", $main, $tool_hook );
preg_match( "/add_action\(\s*'(\w+)',\s*array\( __CLASS__, 'register_tools' \),\s*(\d+)\s*\)/", $routes, $register_hook );
check( 3 === count( $tool_hook ) && 3 === count( $register_hook ), 'the tool construction and the tools registration hooks are found in the plugin source' );

/** One request: the plugin's two hooks, then WordPress's plugins_loaded and init. */
function play_request( array $tool_hook, array $register_hook ): void {
	$GLOBALS['hooks']      = array();
	$GLOBALS['did']        = array();
	$GLOBALS['early_i18n'] = array();
	add_action( $tool_hook[1], function () {
		new Widrow_AI_Tool_BbPress_Tickets();
	}, (int) $tool_hook[2] );
	add_action( $register_hook[1], function () {
		do_action( 'widrow_ai_register_tools' );
	}, (int) $register_hook[2] );
	do_action( 'plugins_loaded' );
	do_action( 'init' );
}

$registry = Widrow_AI_API_Registry::instance();

play_request( $tool_hook, $register_hook );
check( array() === $GLOBALS['early_i18n'], 'no widrow-ai translation runs before init (early: ' . implode( ', ', $GLOBALS['early_i18n'] ) . ')' );
check( ! $registry->is_registered( '/tools/bbp/tickets' ), 'without bbPress the tickets tool does not register' );

// Inside a block, so PHP defines them only from here on (not hoisted to file load).
if ( true ) {
	function bbpress() {}
	function bbp_get_topic_post_type() {
		return 'topic';
	}
}
play_request( $tool_hook, $register_hook );
check( array() === $GLOBALS['early_i18n'], 'with bbPress, still no widrow-ai translation before init' );
check( $registry->is_registered( '/tools/bbp/tickets' ), 'with bbPress the tickets tool registers by the end of init' );
check( 'bbPress Tickets' === ( $registry->get_tools()['/tools/bbp/tickets']['name'] ?? '' ), 'it registers under its translated name' );

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit( $failed > 0 ? 1 : 0 );
