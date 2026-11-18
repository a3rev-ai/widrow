<?php
/**
 * The Staff AI app's bundled icons (8.2.7).
 *
 * The console export copies these PNGs beside the console, and its manifest
 * names them. Pinned here: present, square, and with no alpha channel for iOS
 * to mask onto white.
 *
 * @package widrow-ai/tests
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;

function check( bool $cond, string $label ): void {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  ✓ $label\n";
	} else {
		$fail++;
		echo "  ✗ $label\n";
	}
}

echo "=== Staff AI app icon (8.2.7) ===\n";

foreach ( array( 180, 192, 512 ) as $px ) {
	$file = dirname( __DIR__ ) . "/assets/images/staff-ai-icon-$px.png";
	$info = file_exists( $file ) ? getimagesize( $file ) : false;
	check( $info && $info[0] === $px && $info[1] === $px, "staff-ai-icon-$px.png ships, and is {$px}×{$px}" );
	// Colour type 6 (RGBA) / 4 (grey+alpha) / a tRNS chunk are the ways a PNG can
	// carry transparency for iOS to mask onto white. None of them may be here.
	$bytes = $info ? file_get_contents( $file ) : '';
	$type  = $info ? ord( $bytes[25] ) : 99;
	check( in_array( $type, array( 0, 2, 3 ), true ) && strpos( $bytes, 'tRNS' ) === false, "staff-ai-icon-$px.png is fully opaque — no alpha channel, no tRNS" );
}

// ── Summary ──────────────────────────────────────────────────────────────

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
