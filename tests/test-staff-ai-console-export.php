<?php
/**
 * The static console export (W85 S5): bin/export-staff-ai-console.php runs the shell
 * with stubbed WordPress functions and writes a page a host other than WordPress can
 * serve under `script-src 'self'`.
 *
 * Usage: php tests/test-staff-ai-console-export.php
 *
 * @package widrow-ai/tests
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
function check( bool $ok, string $label ): void {
	global $pass, $fail;
	if ( $ok ) {
		$pass++;
		echo "  ok: $label\n";
	} else {
		$fail++;
		echo "  FAIL: $label\n";
	}
}

$out = sys_get_temp_dir() . '/staff-ai-console-export-' . getmypid();
exec( 'php ' . escapeshellarg( dirname( __DIR__ ) . '/bin/export-staff-ai-console.php' ) . ' ' . escapeshellarg( $out ) . ' 2>&1', $lines, $code );
check( 0 === $code, 'the export runs the shell with stubs and exits 0 (' . implode( ' ', $lines ) . ')' );
check( false === stripos( implode( "\n", $lines ), 'warning' ), 'the shell renders with no PHP warning' );

$html   = (string) @file_get_contents( $out . '/index.html' );
$config = (string) @file_get_contents( $out . '/config.js' );

check( '' !== $html && 0 === preg_match( '#<script(?![^>]*\bsrc=)#', $html ), 'index.html has no inline script' );
check( false === strpos( $html, '<?php' ) && false === strpos( $html, '?>' ), 'index.html has no PHP left' );
check( 0 === preg_match( '#\son[a-z]+=#i', $html ), 'index.html has no inline event handler' );
check( false === strpos( $html, 'rel="manifest"' ), 'no manifest link: the installed app is the host\'s' );

$at = function ( string $needle ) use ( $html ) {
	$pos = strpos( $html, $needle );
	return false === $pos ? PHP_INT_MAX : $pos;
};
check(
	$at( '<script src="config.js">' ) < $at( '<script src="bootstrap.js">' )
		&& $at( '<script src="bootstrap.js">' ) < $at( '<script src="assets/js/staff-ai.js' )
		&& $at( '<script src="config.js">' ) < $at( '<body>' ),
	'config.js in the head before paint, then the host\'s bootstrap.js, then the console'
);

check( false !== strpos( $config, 'var StaffAIConfig = {' ) && false !== strpos( $config, 'i18n: {' ), 'config.js carries StaffAIConfig and its strings' );
check( false !== strpos( $config, "localStorage.getItem('staff-ai-theme')" ), 'config.js carries the theme set before paint' );
check( false !== strpos( $config, 'nonce: ""' ) && false !== strpos( $config, 'userEmail: ""' ), 'no person or nonce is baked into the copy' );

preg_match_all( '#(?:src|href)="(assets/[^"?]+)#', $html, $assets );
$missing = array_filter( $assets[1], function ( $a ) use ( $out ) {
	return ! is_file( $out . '/' . $a );
} );
check( count( $assets[1] ) >= 8 && array() === $missing, 'every linked stylesheet and script is in the copy' );

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $out, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $files as $f ) {
	$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
}
@rmdir( $out );

echo "\n--- Console Export Tests: $pass passed, $fail failed ---\n";
exit( $fail ? 1 : 0 );
