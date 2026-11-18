<?php
/**
 * The plugin has ONE updater: plugin-update-checker, built in widrow-ai.php.
 *
 * Widrow_AI_GitHub_Updater stays available to module plugins, but built for this
 * plugin's own slug it hooks plugins_api after the library, ignores the answer it
 * is handed and replaces the update details: the latest release's notes in a
 * <pre> block instead of the readme's changelog by version, and the readme's
 * "Tested up to" as written, so a patch release of WordPress is warned as untested.
 *
 * Reads the source of every always-loaded file (comments stripped, so the class's
 * own usage example does not count). Runs standalone.
 *
 * @package widrow-ai/tests
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;

function assert_same( $expected, $actual, string $label ): void {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
		echo "[$pass] $label\n";
	} else {
		$fail++;
		echo '  FAIL: ' . $label . ' (expected ' . var_export( $expected, true )
			. ', got ' . var_export( $actual, true ) . ")\n";
	}
}

/** Whether the PHP source builds Widrow_AI_GitHub_Updater for the plugin's own slug. */
function builds_the_plugins_own_updater( string $src ): bool {
	$code = '';
	foreach ( token_get_all( $src ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$code .= is_array( $token ) ? $token[1] : $token;
	}
	preg_match_all( '/new\s+Widrow_AI_GitHub_Updater\s*\((.*?)\)\s*;/s', $code, $calls );
	foreach ( $calls[1] as $args ) {
		if ( preg_match( '/[\'"]slug[\'"]\s*=>\s*[\'"]widrow-ai[\'"]/', $args ) ) {
			return true;
		}
	}
	return false;
}

$root  = dirname( __DIR__ );
$files = array_merge( array( $root . '/widrow-ai.php' ), glob( $root . '/includes/*.php' ), glob( $root . '/includes/*/*.php' ) );
$found = array();
foreach ( $files as $file ) {
	if ( builds_the_plugins_own_updater( (string) file_get_contents( $file ) ) ) {
		$found[] = substr( $file, strlen( $root ) + 1 );
	}
}
assert_same( array(), $found, 'no always-loaded file builds Widrow_AI_GitHub_Updater for widrow-ai' );

$module = "<?php\nnew Widrow_AI_GitHub_Updater( array(\n\t'file' => __FILE__,\n\t'slug' => 'widrow-ai-module',\n\t'version' => '1.0.0',\n) );\n";
assert_same( false, builds_the_plugins_own_updater( $module ), 'a module building it for its own slug passes' );
assert_same( true, builds_the_plugins_own_updater( str_replace( 'widrow-ai-module', 'widrow-ai', $module ) ), 'the same call for widrow-ai is caught' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
