<?php
/**
 * A one-request JSON upstream that answers with a scripted status and body, then prints
 * the request it received (request line, headers, body) as one JSON line on stdout.
 *
 * Usage: php json-upstream-server.php <port> <status> <json body>
 * Writes "READY\n" to stdout once listening.
 *
 * @package widrow-ai/tests
 */

declare(strict_types=1);

$port   = (int) ( $argv[1] ?? 0 );
$status = (int) ( $argv[2] ?? 200 );
$body   = (string) ( $argv[3] ?? '{}' );

$server = @stream_socket_server( "tcp://127.0.0.1:$port", $errno, $errstr );
if ( ! $server ) {
	fwrite( STDERR, "bind failed: $errstr\n" );
	exit( 1 );
}
fwrite( STDOUT, "READY\n" );
fflush( STDOUT );

$conn = @stream_socket_accept( $server, 10 );
if ( ! $conn ) {
	exit( 1 );
}
stream_set_timeout( $conn, 2 );

$request_line = '';
$headers      = array();
$length       = 0;
while ( ( $line = fgets( $conn, 8192 ) ) !== false ) {
	if ( "\r\n" === $line || "\n" === $line ) {
		break;
	}
	if ( '' === $request_line ) {
		$request_line = trim( $line );
		continue;
	}
	$headers[] = trim( $line );
	if ( preg_match( '/^Content-Length:\s*(\d+)/i', $line, $m ) ) {
		$length = (int) $m[1];
	}
}
$received = '';
while ( strlen( $received ) < $length && ! feof( $conn ) ) {
	$received .= (string) fread( $conn, $length - strlen( $received ) );
}

fwrite( $conn, "HTTP/1.1 $status X\r\nContent-Type: application/json\r\nContent-Length: " . strlen( $body ) . "\r\nConnection: close\r\n\r\n" . $body );
fclose( $conn );

fwrite( STDOUT, json_encode( array( 'request' => $request_line, 'headers' => $headers, 'body' => $received ) ) . "\n" );
