<?php
/**
 * Writes the Staff AI console as a static page, for a host other than WordPress (W85 S5).
 *
 * Usage: php bin/export-staff-ai-console.php <out-dir>
 *
 * Runs templates/staff-ai-shell.php with WordPress's functions stubbed (translations
 * return their English text, a person's own fields are empty), then:
 * - index.html: the page with no inline script, so its host can send `script-src 'self'`.
 *   It loads config.js, then the host's own bootstrap.js, which sets StaffAIConfig's
 *   person, addresses and `host` before the console's scripts run;
 * - config.js: the page's two inline scripts, the theme set before paint and the
 *   StaffAIConfig strings;
 * - assets/: the stylesheets and scripts the page links.
 * The manifest and home-screen icon links are left out: the installed app is the host's.
 *
 * @package def-core
 */

if ( PHP_SAPI !== 'cli' || $argc !== 2 ) {
	fwrite( STDERR, "Usage: php bin/export-staff-ai-console.php <out-dir>\n" );
	exit( 2 );
}

// A warning while the shell renders would be written into the page: fail the release instead.
set_error_handler(
	function ( $no, $message, $file, $line ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		fwrite( STDERR, "export: $message at $file:$line\n" );
		exit( 1 );
	}
);

$root = dirname( __DIR__ );
$out  = rtrim( $argv[1], '/' );

define( 'ABSPATH', $root . '/' );
define( 'DEF_CORE_PLUGIN_URL', '' );
define( 'DEF_CORE_API_NAME_SPACE', 'a3-ai/v1' );
preg_match( "/define\\(\\s*'DEF_CORE_VERSION',\\s*'([^']+)'/", file_get_contents( $root . '/def-core.php' ), $m );
define( 'DEF_CORE_VERSION', $m[1] );

function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = '' ) { return esc_attr( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return esc_attr( $url ); }
function wp_json_encode( $data ) { return json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ); }
function language_attributes() { echo 'lang="en"'; }
function bloginfo( $show ) { echo 'charset' === $show ? 'UTF-8' : ''; }
function home_url( $path = '' ) { return $path; }
function rest_url( $path = '' ) { return ''; }
function admin_url( $path = '' ) { return ''; }
function wp_login_url( $redirect = '' ) { return ''; }
function wp_logout_url( $redirect = '' ) { return ''; }
function wp_timezone_string() { return ''; }

final class DEF_Core_OAuth {
	public static function get_defho_url() { return ''; }
}

final class Export_User {
	public $ID           = '';
	public $user_email   = '';
	public $first_name   = '';
	public $display_name = '';
	public function has_cap( $cap ) { return false; }
}

$channel           = 'staff_ai';
$user              = new Export_User();
$api_base          = '';
$nonce             = '';
$logo_html         = '';
$welcome_logo_html = '';
$apple_touch_icon  = array( 'src' => '', 'sizes' => '' );

ob_start();
require $root . '/templates/staff-ai-shell.php';
$html = ob_get_clean();

// The page's inline scripts, in order, become config.js.
preg_match_all( '#<script>(.*?)</script>\s*#s', $html, $inline );
$config = implode( "\n", array_map( 'trim', $inline[1] ) ) . "\n";
$first  = true;
$html   = preg_replace_callback(
	'#\s*<script>.*?</script>#s',
	function () use ( &$first ) {
		$tags  = $first ? "\n\t<script src=\"config.js\"></script>\n\t<script src=\"bootstrap.js\"></script>" : '';
		$first = false;
		return $tags;
	},
	$html
);
$html = preg_replace( '#\s*<link rel="(manifest|apple-touch-icon)"[^>]*>#', '', $html );

if ( preg_match( '#<script(?![^>]*\bsrc=)#', $html ) || false !== strpos( $html, '<?php' ) || false !== strpos( $html, '?>' ) ) {
	fwrite( STDERR, "export: an inline script or PHP is left in index.html\n" );
	exit( 1 );
}

if ( ! is_dir( $out ) && ! mkdir( $out, 0755, true ) ) {
	fwrite( STDERR, "export: cannot create $out\n" );
	exit( 1 );
}
file_put_contents( $out . '/index.html', $html );
file_put_contents( $out . '/config.js', $config );

preg_match_all( '#(?:src|href)="(assets/[^"?]+)#', $html, $assets );
foreach ( array_unique( $assets[1] ) as $asset ) {
	if ( ! is_dir( dirname( $out . '/' . $asset ) ) ) {
		mkdir( dirname( $out . '/' . $asset ), 0755, true );
	}
	if ( ! copy( $root . '/' . $asset, $out . '/' . $asset ) ) {
		fwrite( STDERR, "export: cannot copy $asset\n" );
		exit( 1 );
	}
}
echo 'Staff AI console ' . DEF_CORE_VERSION . ' written to ' . $out . ' (' . count( array_unique( $assets[1] ) ) . " assets)\n";
