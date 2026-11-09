<?php
/**
 * The 10.0.0 move from the names stored as def-core to the Widrow AI names
 * (Widrow_AI_Legacy_Names::migrate()), and the alias table's two directions.
 *
 * The seven cases:
 *  1. A fresh site: nothing is created.
 *  2. Old names only: everything moves, the old names are gone, values intact.
 *  3. An option never set is skipped; a set-but-empty one still moves.
 *  4. Run twice: the second run changes nothing.
 *  5. Old and new both present: the new one wins and the old one goes.
 *  6. An interrupted run completes on the next run, to the same end state.
 *  7. Counts before and after are logged and match.
 *
 * Runs standalone (no WordPress bootstrap): an in-memory options table, user
 * and post meta, cron array and a $wpdb that answers the migration's queries.
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

// ── Stubs defined before wp-stubs.php so these win ─────────────────────────
// Transients live in the options table here (as on a site without an object
// cache), because that is where the migration finds them.

global $_wp_test_options, $_wp_test_mutations, $_wp_test_not_autoloaded, $_wp_test_fail_after;
$_wp_test_mutations      = 0;
$_wp_test_not_autoloaded = array();
$_wp_test_fail_after     = -1;

/** Every write counts; a test can make the Nth one throw to interrupt a run. */
function _test_mutate(): void {
	global $_wp_test_mutations, $_wp_test_fail_after;
	$_wp_test_mutations++;
	if ( $_wp_test_fail_after >= 0 && $_wp_test_mutations > $_wp_test_fail_after ) {
		throw new RuntimeException( 'interrupted' );
	}
}

function update_option( string $key, $value, $autoload = null ): bool {
	global $_wp_test_options;
	_test_mutate();
	$_wp_test_options[ $key ] = $value;
	return true;
}
function add_option( string $key, $value = '', $deprecated = '', $autoload = true ): bool {
	global $_wp_test_options, $_wp_test_not_autoloaded, $_wp_test_add_fails;
	if ( array_key_exists( $key, $_wp_test_options ) || isset( $_wp_test_add_fails[ $key ] ) ) {
		return false;
	}
	_test_mutate();
	$_wp_test_options[ $key ] = $value;
	if ( ! $autoload ) {
		$_wp_test_not_autoloaded[ $key ] = true;
	}
	return true;
}
function delete_option( string $key ): bool {
	global $_wp_test_options, $_wp_test_not_autoloaded;
	if ( ! array_key_exists( $key, $_wp_test_options ) ) {
		return false;
	}
	_test_mutate();
	unset( $_wp_test_options[ $key ], $_wp_test_not_autoloaded[ $key ] );
	return true;
}
function wp_load_alloptions(): array {
	global $_wp_test_options, $_wp_test_not_autoloaded;
	return array_diff_key( $_wp_test_options, $_wp_test_not_autoloaded );
}
function get_site_option( string $key, $default = false ) {
	return get_option( $key, $default );
}
function delete_site_option( string $key ): bool {
	return delete_option( $key );
}
function delete_transient( string $key ): bool {
	delete_option( '_transient_timeout_' . $key );
	return delete_option( '_transient_' . $key );
}

require_once __DIR__ . '/wp-stubs.php';

// ── User and post meta: rows of [object id, key, value] ────────────────────

function get_user_meta( int $user_id, string $key, bool $single = false ) {
	global $wpdb;
	foreach ( $wpdb->meta[ $wpdb->usermeta ] as $row ) {
		if ( $row[0] === $user_id && $row[1] === $key ) {
			return $row[2];
		}
	}
	return $single ? '' : array();
}
function update_user_meta( int $user_id, string $key, $value ): bool {
	global $wpdb;
	_test_mutate();
	foreach ( $wpdb->meta[ $wpdb->usermeta ] as $i => $row ) {
		if ( $row[0] === $user_id && $row[1] === $key ) {
			$wpdb->meta[ $wpdb->usermeta ][ $i ][2] = $value;
			return true;
		}
	}
	$wpdb->meta[ $wpdb->usermeta ][] = array( $user_id, $key, $value );
	return true;
}

// ── Cron: [timestamp][hook][key] = {schedule, args} ────────────────────────

global $_wp_test_cron;
$_wp_test_cron = array();

function _get_cron_array() {
	global $_wp_test_cron;
	return $_wp_test_cron;
}
function wp_next_scheduled( string $hook, array $args = array() ) {
	global $_wp_test_cron;
	foreach ( $_wp_test_cron as $ts => $hooks ) {
		if ( isset( $hooks[ $hook ][ md5( serialize( $args ) ) ] ) ) {
			return $ts;
		}
	}
	return false;
}
function wp_schedule_event( int $ts, string $recurrence, string $hook, array $args = array() ): bool {
	global $_wp_test_cron;
	_test_mutate();
	$_wp_test_cron[ $ts ][ $hook ][ md5( serialize( $args ) ) ] = array( 'schedule' => $recurrence, 'args' => $args );
	return true;
}
function wp_schedule_single_event( int $ts, string $hook, array $args = array() ): bool {
	global $_wp_test_cron;
	_test_mutate();
	$_wp_test_cron[ $ts ][ $hook ][ md5( serialize( $args ) ) ] = array( 'schedule' => false, 'args' => $args );
	return true;
}
function wp_unschedule_hook( string $hook ) {
	global $_wp_test_cron;
	$n = 0;
	foreach ( $_wp_test_cron as $ts => $hooks ) {
		if ( isset( $hooks[ $hook ] ) ) {
			_test_mutate();
			$n += count( $hooks[ $hook ] );
			unset( $_wp_test_cron[ $ts ][ $hook ] );
		}
	}
	return $n;
}

// ── Roles, cache, logger ───────────────────────────────────────────────────

global $_wp_test_roles_reloads, $_wp_test_cache_flushes, $_wp_test_logged;
$_wp_test_roles_reloads = 0;
$_wp_test_cache_flushes = 0;
$_wp_test_logged        = array();

function wp_roles() {
	return new class() {
		public function for_site(): void {
			global $_wp_test_roles_reloads;
			$_wp_test_roles_reloads++;
		}
	};
}
function wp_cache_flush(): bool {
	global $_wp_test_cache_flushes;
	$_wp_test_cache_flushes++;
	return true;
}
final class Widrow_AI_Logger {
	public static function info( string $source, string $message, array $context = array() ): void {
		global $_wp_test_logged;
		$_wp_test_logged[] = array( $source, $message, $context );
	}
}

// ── $wpdb: answers exactly the queries the migration makes ─────────────────

final class Test_WPDB {
	public $prefix   = 'wp_';
	public $options  = 'wp_options';
	public $usermeta = 'wp_usermeta';
	public $postmeta = 'wp_postmeta';
	public $tables   = array( 'wp_options', 'wp_usermeta', 'wp_postmeta' );
	public $meta     = array( 'wp_usermeta' => array(), 'wp_postmeta' => array() );

	public function get_blog_prefix(): string {
		return $this->prefix;
	}
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}
	public function prepare( string $sql, ...$args ): array {
		return array( $sql, $args );
	}
	private static function like( string $pattern, string $subject ): bool {
		$re = '';
		for ( $i = 0, $n = strlen( $pattern ); $i < $n; $i++ ) {
			$c = $pattern[ $i ];
			if ( '\\' === $c && $i + 1 < $n ) {
				$re .= preg_quote( $pattern[ ++$i ], '/' );
			} elseif ( '%' === $c ) {
				$re .= '.*';
			} elseif ( '_' === $c ) {
				$re .= '.';
			} else {
				$re .= preg_quote( $c, '/' );
			}
		}
		return 1 === preg_match( '/^' . $re . '$/s', $subject );
	}
	public function get_col( $q ): array {
		global $_wp_test_options;
		list( $sql, $args ) = $q;
		if ( "SELECT option_name FROM {$this->options} WHERE option_name LIKE %s" === $sql ) {
			return array_values( array_filter( array_keys( $_wp_test_options ), fn( $k ) => self::like( $args[0], (string) $k ) ) );
		}
		if ( "SELECT user_id FROM {$this->usermeta} WHERE meta_key = %s AND meta_value LIKE %s" === $sql ) {
			$ids = array();
			foreach ( $this->meta[ $this->usermeta ] as $row ) {
				if ( $row[1] === $args[0] && self::like( $args[1], serialize( $row[2] ) ) ) {
					$ids[] = (string) $row[0];
				}
			}
			return $ids;
		}
		throw new LogicException( "unexpected get_col: $sql" );
	}
	public function get_var( $q ) {
		list( $sql, $args ) = $q;
		if ( 'SHOW TABLES LIKE %s' === $sql ) {
			foreach ( $this->tables as $t ) {
				if ( self::like( $args[0], $t ) ) {
					return $t;
				}
			}
			return null;
		}
		if ( preg_match( '/^SELECT COUNT\(\*\) FROM (\S+) WHERE meta_key = %s$/', $sql, $m ) ) {
			return (string) count( array_filter( $this->meta[ $m[1] ] ?? array(), fn( $r ) => $r[1] === $args[0] ) );
		}
		throw new LogicException( "unexpected get_var: $sql" );
	}
	public function query( $q ) {
		if ( is_string( $q ) && preg_match( '/^RENAME TABLE `(\S+)` TO `(\S+)`$/', $q, $m ) ) {
			_test_mutate();
			$this->tables = array_values( array_diff( $this->tables, array( $m[1] ) ) );
			$this->tables[] = $m[2];
			return true;
		}
		list( $sql, $args ) = $q;
		if ( preg_match( '/^DELETE o FROM (\S+) o INNER JOIN \1 n ON n\.(\w+) = o\.\2 AND n\.meta_key = %s WHERE o\.meta_key = %s$/', $sql, $m ) ) {
			list( $new, $old ) = $args;
			$has_new = array();
			foreach ( $this->meta[ $m[1] ] as $r ) {
				if ( $r[1] === $new ) {
					$has_new[ $r[0] ] = true;
				}
			}
			$n = 0;
			foreach ( $this->meta[ $m[1] ] as $i => $r ) {
				if ( $r[1] === $old && isset( $has_new[ $r[0] ] ) ) {
					_test_mutate();
					unset( $this->meta[ $m[1] ][ $i ] );
					$n++;
				}
			}
			return $n;
		}
		if ( preg_match( '/^UPDATE (\S+) SET meta_key = %s WHERE meta_key = %s$/', $sql, $m ) ) {
			list( $new, $old ) = $args;
			$n = 0;
			foreach ( $this->meta[ $m[1] ] as $i => $r ) {
				if ( $r[1] === $old ) {
					_test_mutate();
					$this->meta[ $m[1] ][ $i ][1] = $new;
					$n++;
				}
			}
			return $n;
		}
		throw new LogicException( 'unexpected query: ' . ( is_string( $q ) ? $q : $sql ) );
	}
}

require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-legacy-names.php';

// ── Helpers ─────────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;
function check( bool $cond, string $label ): void {
	global $pass, $fail;
	static $n = 0;
	$n++;
	if ( $cond ) {
		$pass++;
		echo "[$n] $label\n";
	} else {
		$fail++;
		echo "[$n] FAIL: $label\n";
	}
}

function reset_site(): void {
	global $wpdb, $_wp_test_options, $_wp_test_not_autoloaded, $_wp_test_cron, $_wp_test_mutations,
		$_wp_test_fail_after, $_wp_test_add_fails, $_wp_test_logged, $_wp_test_roles_reloads, $_wp_test_cache_flushes;
	$wpdb                    = new Test_WPDB();
	$_wp_test_options        = array();
	$_wp_test_not_autoloaded = array();
	$_wp_test_cron           = array();
	$_wp_test_mutations      = 0;
	$_wp_test_fail_after     = -1;
	$_wp_test_add_fails      = array();
	$_wp_test_logged         = array();
	$_wp_test_roles_reloads  = 0;
	$_wp_test_cache_flushes  = 0;
}

function ksort_deep( array $a ): array {
	ksort( $a );
	return array_map( fn( $v ) => is_array( $v ) ? ksort_deep( $v ) : $v, $a );
}

/** Everything the migration can touch, for comparing two end states (key order ignored). */
function site_state(): array {
	global $wpdb, $_wp_test_options, $_wp_test_not_autoloaded, $_wp_test_cron;
	$opts = $_wp_test_options;
	ksort( $opts );
	$meta = array();
	foreach ( $wpdb->meta as $t => $rows ) {
		$rows = array_values( $rows );
		sort( $rows );
		$meta[ $t ] = $rows;
	}
	$tables = $wpdb->tables;
	sort( $tables );
	$cron = array_filter( array_map( 'array_filter', $_wp_test_cron ) );
	ksort( $cron );
	$not_autoloaded = array_keys( $_wp_test_not_autoloaded );
	sort( $not_autoloaded );
	return ksort_deep( compact( 'opts', 'meta', 'tables', 'cron', 'not_autoloaded' ) );
}

const T0 = 1700000000;

/** A site as 9.8.0 leaves it: one of each kind of stored name. */
function seed_old_site(): void {
	global $wpdb, $_wp_test_options, $_wp_test_not_autoloaded, $_wp_test_cron;
	$_wp_test_options = array(
		'blogname'                         => 'Example',
		'def_core_api_key'                 => 'ENC:ciphertext-of-the-api-key',
		'def_service_auth_secret'          => 'ENC:ciphertext-of-the-service-secret',
		'def_core_keys'                    => array( 'kid' => 'abc123', 'private' => 'PEM', 'public' => 'PEM' ),
		'def_core_staff_ai_api_url'        => 'https://def.example.test',
		'def_core_display_name'            => 'Example Assistant',
		'def_core_db_version'              => '7.0.0',
		'def_core_escalation_customer'     => array( 'to' => 'help@example.test' ),
		'def_core_allowed_origins'         => array(),
		'def_core_chat_ai_notice'          => '0',
		'def_core_share_events_t-42'       => array( array( 'type' => 'share' ) ),
		'_def_oauth_pkce_state1'           => array( 'verifier' => 'v' ),
		'_transient_def_core_connection_test'         => array( 'status' => 'ok' ),
		'_transient_timeout_def_core_connection_test' => T0,
		'_transient_def_core_rl_abcdef'               => 3,
		'_transient_def_gh_update_def-core'           => array( 'version' => '9.8.0' ),
		'external_updates-digital-employees'          => array( 'lastCheck' => T0 ),
		'wp_user_roles'                    => array(
			'administrator' => array( 'name' => 'Administrator', 'capabilities' => array( 'manage_options' => true, 'def_admin_access' => true ) ),
			'shop_manager'  => array( 'name' => 'Shop manager', 'capabilities' => array( 'read' => true, 'def_staff_access' => true ) ),
		),
	);
	$_wp_test_not_autoloaded = array(
		'def_core_api_key'           => true,
		'def_service_auth_secret'    => true,
		'def_core_share_events_t-42' => true,
	);
	$wpdb->meta['wp_usermeta'] = array(
		array( 1, 'wp_capabilities', array( 'administrator' => true, 'def_admin_access' => true ) ),
		array( 2, 'wp_capabilities', array( 'editor' => true, 'def_management_access' => true, 'def_role_hr' => true ) ),
		array( 3, 'wp_capabilities', array( 'subscriber' => true, 'def_staff_access' => true, 'def_role_finance' => false ) ),
		array( 4, 'wp_capabilities', array( 'customer' => true ) ),
		array( 1, 'def_sa_drawer_seen', '1' ),
	);
	$wpdb->meta['wp_postmeta'] = array(
		array( 10, '_def_exclude_from_ingestion', '1' ),
		array( 11, '_def_exclude_from_ingestion', '1' ),
		array( 20, '_def_chat_id', 'thread-abc' ),
		array( 30, '_def_created', '1' ),
	);
	$wpdb->tables[] = 'wp_def_core_logs';
	$_wp_test_cron  = array(
		T0         => array(
			'def_core_log_cleanup'   => array( md5( serialize( array() ) ) => array( 'schedule' => 'daily', 'args' => array() ) ),
			'def_core_cleanup_pkce'  => array( md5( serialize( array() ) ) => array( 'schedule' => 'daily', 'args' => array() ) ),
			'puc_cron_check_updates-digital-employees' => array( md5( serialize( array() ) ) => array( 'schedule' => 'twicedaily', 'args' => array() ) ),
		),
		T0 + 60    => array(
			'def_core_goal_outcome'  => array( md5( serialize( array( 77, 'completed' ) ) ) => array( 'schedule' => false, 'args' => array( 77, 'completed' ) ) ),
		),
	);
}

function caps_of( int $user_id ): array {
	return (array) get_user_meta( $user_id, 'wp_capabilities', true );
}

function old_names_left(): array {
	global $wpdb, $_wp_test_options, $_wp_test_cron;
	$left = array();
	foreach ( array_keys( $_wp_test_options ) as $k ) {
		if ( preg_match( '/(^|_)def_|digital-employees/', (string) $k ) ) {
			$left[] = "option $k";
		}
	}
	foreach ( (array) ( $_wp_test_options['wp_user_roles'] ?? array() ) as $role => $data ) {
		foreach ( array_keys( $data['capabilities'] ?? array() ) as $cap ) {
			if ( 0 === strpos( (string) $cap, 'def_' ) ) {
				$left[] = "role $role $cap";
			}
		}
	}
	foreach ( $wpdb->meta as $table => $rows ) {
		foreach ( $rows as $r ) {
			$keys = is_array( $r[2] ) && 'wp_capabilities' === $r[1] ? array_keys( $r[2] ) : array( $r[1] );
			foreach ( $keys as $k ) {
				if ( preg_match( '/^_?def_/', (string) $k ) ) {
					$left[] = "$table {$r[0]} $k";
				}
			}
		}
	}
	foreach ( $_wp_test_cron as $hooks ) {
		foreach ( array_keys( array_filter( $hooks ) ) as $hook ) {
			if ( preg_match( '/^def_|digital-employees/', $hook ) ) {
				$left[] = "cron $hook";
			}
		}
	}
	foreach ( $wpdb->tables as $t ) {
		if ( false !== strpos( $t, 'def_core' ) ) {
			$left[] = "table $t";
		}
	}
	return $left;
}

// ── The alias table ─────────────────────────────────────────────────────────
echo "\n[alias table] both directions\n";

$round_trip = true;
foreach ( Widrow_AI_Legacy_Names::OPTIONS + Widrow_AI_Legacy_Names::CAPABILITIES as $old => $new ) {
	$round_trip = $round_trip && Widrow_AI_Legacy_Names::to_new( $old ) === $new && Widrow_AI_Legacy_Names::to_old( $new ) === $old
		&& Widrow_AI_Legacy_Names::to_new( $new ) === $new && Widrow_AI_Legacy_Names::to_old( $old ) === $old;
}
check( $round_trip, 'Every option and capability maps old -> new and back; each side is a fixed point of its own direction' );
check( 'widrow_role_hr' === Widrow_AI_Legacy_Names::to_new( 'def_role_hr' ) && 'def_role_hr' === Widrow_AI_Legacy_Names::to_old( 'widrow_role_hr' ), 'def_role_<slug> keeps its slug both ways' );
check( 'widrow_ai_share_events_t-9' === Widrow_AI_Legacy_Names::to_new( 'def_core_share_events_t-9' ), 'A prefixed option keeps its suffix' );
check( 'blogname' === Widrow_AI_Legacy_Names::to_new( 'blogname' ) && 'manage_options' === Widrow_AI_Legacy_Names::to_old( 'manage_options' ), 'Any other name passes through unchanged' );
check( count( Widrow_AI_Legacy_Names::OPTIONS ) === count( array_unique( Widrow_AI_Legacy_Names::OPTIONS ) ), 'No two old options share a new name' );

// ── 1. Fresh site ───────────────────────────────────────────────────────────
echo "\n[1] A fresh site: nothing is created\n";
reset_site();
$_wp_test_options = array( 'blogname' => 'Example' );
$before           = site_state();
$counts           = Widrow_AI_Legacy_Names::migrate();
check( site_state() === $before, 'Options, meta, tables and cron are exactly as they were' );
check( 0 === $_wp_test_mutations, 'Not one write' );
check( array() === $_wp_test_logged, 'Nothing logged when nothing moved' );
check( $counts['before'] === $counts['after'], 'Counts before and after are equal' );

// ── 2. Old names only ───────────────────────────────────────────────────────
echo "\n[2] Old names only: everything moves\n";
reset_site();
seed_old_site();
$counts = Widrow_AI_Legacy_Names::migrate();
check( array() === old_names_left(), 'No old name is left: ' . implode( ', ', old_names_left() ) );
check( 'ENC:ciphertext-of-the-api-key' === get_option( 'widrow_ai_api_key' ) && 'ENC:ciphertext-of-the-service-secret' === get_option( 'widrow_service_auth_secret' ), 'The API key and service secret ciphertexts move byte for byte (connection kept)' );
check( array( 'kid' => 'abc123', 'private' => 'PEM', 'public' => 'PEM' ) === get_option( 'widrow_ai_keys' ), 'The JWT signing keys move, so the kid DEF trusts is unchanged' );
check( '7.0.0' === get_option( 'widrow_ai_db_version' ) && array( 'to' => 'help@example.test' ) === get_option( 'widrow_ai_escalation_customer' ), 'The db-version marker and an array setting move with their values' );
check( isset( $_wp_test_not_autoloaded['widrow_ai_api_key'], $_wp_test_not_autoloaded['widrow_service_auth_secret'], $_wp_test_not_autoloaded['widrow_ai_share_events_t-42'] ) && ! isset( $_wp_test_not_autoloaded['widrow_ai_display_name'] ), 'Autoload is kept: the secrets stay off autoload, the settings stay on' );
check( array( array( 'type' => 'share' ) ) === get_option( 'widrow_ai_share_events_t-42' ) && array( 'verifier' => 'v' ) === get_option( '_widrow_oauth_pkce_state1' ), 'Prefixed options (share events, OAuth state) move with their suffix' );
$roles = get_option( 'wp_user_roles' );
check( true === ( $roles['administrator']['capabilities']['widrow_admin_access'] ?? null ) && true === ( $roles['shop_manager']['capabilities']['widrow_staff_access'] ?? null ) && true === $roles['administrator']['capabilities']['manage_options'], 'Role capabilities are renamed in wp_user_roles; other caps untouched' );
check( 1 === $_wp_test_roles_reloads, 'The in-memory roles are reloaded after the roles option changes' );
check( array( 'administrator' => true, 'widrow_admin_access' => true ) === caps_of( 1 ), 'A user\'s admin grant moves' );
check( array( 'editor' => true, 'widrow_management_access' => true, 'widrow_role_hr' => true ) === caps_of( 2 ), 'Management and a custom role move, the slug kept' );
check( array( 'subscriber' => true, 'widrow_staff_access' => true, 'widrow_role_finance' => false ) === caps_of( 3 ), 'A false (denied) grant moves as false, never widened' );
check( array( 'customer' => true ) === caps_of( 4 ), 'A user with no DEF caps is untouched' );
check( '1' === get_user_meta( 1, 'widrow_sa_drawer_seen', true ), 'User meta moves' );
$post_keys = array_map( fn( $r ) => $r[0] . ':' . $r[1] . '=' . $r[2], $wpdb->meta['wp_postmeta'] );
sort( $post_keys );
check( array( '10:_widrow_exclude_from_ingestion=1', '11:_widrow_exclude_from_ingestion=1', '20:_widrow_chat_id=thread-abc', '30:_widrow_created=1' ) === $post_keys, 'Post meta moves: knowledge exclusions stay excluded, order attribution kept' );
check( 1 === $_wp_test_cache_flushes, 'The object cache is flushed once after meta rows move' );
check( in_array( 'wp_widrow_ai_logs', $wpdb->tables, true ) && ! in_array( 'wp_def_core_logs', $wpdb->tables, true ), 'The log table is renamed' );
check( false === get_option( '_transient_def_core_connection_test' ) && false === get_option( '_transient_timeout_def_core_connection_test' ) && false === get_option( '_transient_def_core_rl_abcdef' ) && false === get_option( '_transient_def_gh_update_def-core' ), 'Old transients (fixed and prefixed, with their timeouts) are deleted' );
check( false === get_option( '_transient_widrow_ai_connection_test' ), 'No transient is created under a new name' );
check( false === get_option( 'external_updates-digital-employees' ), 'The orphan update-checker option is deleted' );
check( T0 === wp_next_scheduled( 'widrow_ai_log_cleanup' ) && 'daily' === $_wp_test_cron[ T0 ]['widrow_ai_log_cleanup'][ md5( serialize( array() ) ) ]['schedule'], 'A recurring event keeps its time and recurrence' );
check( T0 + 60 === wp_next_scheduled( 'widrow_ai_goal_outcome', array( 77, 'completed' ) ) && false === $_wp_test_cron[ T0 + 60 ]['widrow_ai_goal_outcome'][ md5( serialize( array( 77, 'completed' ) ) ) ]['schedule'], 'A single event keeps its time and args' );
check( false === wp_next_scheduled( 'puc_cron_check_updates-digital-employees' ), 'The orphan update-checker cron event is gone' );
$after_old_only = site_state();

// ── 3. Never-set options are skipped ────────────────────────────────────────
echo "\n[3] An option never set is skipped; a set-but-empty one moves\n";
check( false === get_option( 'widrow_ai_logo_id' ) && ! array_key_exists( 'widrow_ai_logo_id', $_wp_test_options ), 'An option the site never set is not created under its new name' );
check( array( 'widrow_ai_chat_ai_notice' ) === array_values( array_filter( array_keys( $_wp_test_options ), fn( $k ) => 0 === strpos( (string) $k, 'widrow_ai_chat_' ) ) ), 'Of the 35 chat options, only the one the site set exists under a new name' );
check( array() === get_option( 'widrow_ai_allowed_origins', 'missing' ) && '0' === get_option( 'widrow_ai_chat_ai_notice', 'missing' ), 'An empty array and a "0" still move (set is not the same as truthy)' );

// ── 4. Run twice ────────────────────────────────────────────────────────────
echo "\n[4] Run twice: the second run changes nothing\n";
$_wp_test_mutations = 0;
$_wp_test_logged    = array();
$second             = Widrow_AI_Legacy_Names::migrate();
check( site_state() === $after_old_only, 'The site is exactly as the first run left it' );
check( 0 === $_wp_test_mutations, 'Not one write on the second run' );
check( array() === $_wp_test_logged && $second['before'] === $second['after'], 'Nothing logged; counts unchanged' );

// ── 5. Old and new both present ─────────────────────────────────────────────
echo "\n[5] Old and new both present: new wins, old goes\n";
reset_site();
seed_old_site();
$_wp_test_options['widrow_ai_display_name'] = 'Set after 10.0.0';
$wpdb->meta['wp_usermeta'][]                = array( 5, 'wp_capabilities', array( 'author' => true, 'def_staff_access' => true, 'widrow_staff_access' => false ) );
$wpdb->meta['wp_postmeta'][]                = array( 10, '_widrow_exclude_from_ingestion', '0' );
$wpdb->tables[]                             = 'wp_widrow_ai_logs';
$_wp_test_options['wp_user_roles']['shop_manager']['capabilities']['widrow_staff_access'] = false;
Widrow_AI_Legacy_Names::migrate();
check( 'Set after 10.0.0' === get_option( 'widrow_ai_display_name' ) && false === get_option( 'def_core_display_name' ), 'Option: the new value is kept, the old row deleted' );
check( array( 'author' => true, 'widrow_staff_access' => false ) === caps_of( 5 ), 'User cap: the new grant wins and the old name is removed, so no stale grant is left to survive a revoke' );
check( array( 'read' => true, 'widrow_staff_access' => false ) === get_option( 'wp_user_roles' )['shop_manager']['capabilities'], 'Role cap: the new grant wins, the old name removed' );
$post10 = array_values( array_filter( $wpdb->meta['wp_postmeta'], fn( $r ) => 10 === $r[0] ) );
check( array( array( 10, '_widrow_exclude_from_ingestion', '0' ) ) === $post10, 'Post meta: the new row wins, the old row for the same post is deleted' );
check( in_array( 'wp_def_core_logs', $wpdb->tables, true ) && in_array( 'wp_widrow_ai_logs', $wpdb->tables, true ), 'Table: both exist, so no rename is attempted (both guarded)' );

// ── 5a. A failed write keeps the old row ────────────────────────────────────
echo "
[5a] A new option that cannot be written leaves the old one for the next run
";
reset_site();
seed_old_site();
$_wp_test_add_fails = array( 'widrow_ai_api_key' => true );
Widrow_AI_Legacy_Names::migrate();
check( 'ENC:ciphertext-of-the-api-key' === get_option( 'def_core_api_key' ) && false === get_option( 'widrow_ai_api_key' ), 'add_option failed: the old row survives, nothing under the new name' );
$_wp_test_add_fails = array();
Widrow_AI_Legacy_Names::migrate();
check( 'ENC:ciphertext-of-the-api-key' === get_option( 'widrow_ai_api_key' ) && false === get_option( 'def_core_api_key' ), 'The next run moves it' );

// ── 5b. List-style role caps (third-party add_role) ─────────────────────────
echo "
[5b] Integer-keyed capability arrays are never mangled
";
reset_site();
$_wp_test_options['wp_user_roles'] = array(
	'listed' => array( 'name' => 'Listed', 'capabilities' => array( 0 => 'read', 1 => 'edit_posts' ) ),
);
Widrow_AI_Legacy_Names::migrate();
check( array( 0 => 'read', 1 => 'edit_posts' ) === get_option( 'wp_user_roles' )['listed']['capabilities'] && 0 === $_wp_test_mutations, 'A list-style caps array with no DEF caps is untouched, with zero writes' );
reset_site();
$_wp_test_options['wp_user_roles'] = array(
	'mixed' => array( 'name' => 'Mixed', 'capabilities' => array( 0 => 'read', 'def_staff_access' => true ) ),
);
Widrow_AI_Legacy_Names::migrate();
check( array( 0 => 'read', 'widrow_staff_access' => true ) === get_option( 'wp_user_roles' )['mixed']['capabilities'], 'A mixed array keeps 0 => read, gains widrow_staff_access, loses def_staff_access' );

// ── 6. An interrupted run ───────────────────────────────────────────────────
echo "\n[6] An interrupted run completes on the next run\n";
// A full run here is 44 writes: inside the options, the users' caps, the cron
// unschedules and the meta renames.
$interrupted_at = array( 3, 24, 35, 40 );
foreach ( $interrupted_at as $after_writes ) {
	reset_site();
	seed_old_site();
	$_wp_test_fail_after = $after_writes;
	$threw               = false;
	try {
		Widrow_AI_Legacy_Names::migrate();
	} catch ( RuntimeException $e ) {
		$threw = true;
	}
	$_wp_test_fail_after = -1;
	$partial             = old_names_left();
	Widrow_AI_Legacy_Names::migrate();
	check( $threw && array() !== $partial && site_state() === $after_old_only, "Stopped after $after_writes writes with old names left; the rerun ends in the same state as an uninterrupted run" );
}

// ── 7. Counts logged and match ──────────────────────────────────────────────
echo "\n[7] Counts before and after are logged and match\n";
reset_site();
seed_old_site();
$counts = Widrow_AI_Legacy_Names::migrate();
check( 1 === count( $_wp_test_logged ) && 'migration' === $_wp_test_logged[0][0] && $counts === $_wp_test_logged[0][2], 'One log entry, source migration, carrying the returned counts' );
$b = $counts['before'];
$a = $counts['after'];
check( 11 === $b['options']['old'] && 0 === $b['options']['new'] && 0 === $a['options']['old'] && 11 === $a['options']['new'], 'Options (9 named + 2 prefixed): 11 old before, 11 new after' );
check( 7 === $b['capabilities']['old'] && 0 === $a['capabilities']['old'] && 7 === $a['capabilities']['new'], 'Capability grants (2 role + 5 user): 7 old before, 7 new after' );
check( 3 === $b['transients']['old'] && 0 === $a['transients']['old'], 'Transients: 3 old before, none after' );
check( 4 === $b['cron']['old'] && 0 === $a['cron']['old'] && 3 === $a['cron']['new'], 'Cron: 4 old events (one orphan) before, 3 new after' );
check( array( 'old' => 1, 'new' => 0 ) === $b['tables'] && array( 'old' => 0, 'new' => 1 ) === $a['tables'], 'Table: old before, new after' );
check( 5 === $b['meta']['old'] && 5 === $a['meta']['new'] && 0 === $a['meta']['old'], 'Meta rows: 5 old before, 5 new after' );
check( 1 === $b['orphans']['old'] && 0 === $a['orphans']['old'], 'Orphan option: 1 before, 0 after' );

// ── The old wp-admin page slugs redirect ────────────────────────────────────
echo "\n[pages] the four old slugs are hidden pages that redirect\n";

final class Test_Redirect extends Exception {}
$_test_pages   = array();
$_test_actions = array();
function add_submenu_page( string $parent, string $page_title, string $menu_title, string $cap, string $slug, $cb = null ) {
	global $_test_pages;
	$_test_pages[ $slug ] = array( $parent, $cap );
	return 'admin_page_' . $slug;
}
function add_action( string $hook, $cb, int $priority = 10, int $args = 1 ): void {
	global $_test_actions;
	$_test_actions[ $hook ] = $cb;
}
function add_query_arg( string $key, string $value ): string {
	$parts = parse_url( $_SERVER['REQUEST_URI'] );
	parse_str( $parts['query'] ?? '', $query );
	$query[ $key ] = $value;
	return $parts['path'] . '?' . http_build_query( $query );
}
function wp_safe_redirect( string $location, int $status = 302 ): bool {
	throw new Test_Redirect( "$status $location" );
}

Widrow_AI_Legacy_Names::register_old_pages();
$hidden = true;
foreach ( Widrow_AI_Legacy_Names::ADMIN_PAGES as $old => $new ) {
	$hidden = $hidden && array( '', 'read' ) === ( $_test_pages[ $old ] ?? null );
}
check( 4 === count( $_test_pages ) && $hidden, 'Each old slug is a hidden page (no parent), open to any logged-in user; the new page checks its own capability' );

$redirects = array();
foreach ( array(
	'def-core'                => '/wp-admin/admin.php?page=def-core',
	'def-core-content-drafts' => '/wp-admin/admin.php?page=def-core-content-drafts',
	'def-core-logs'           => '/wp-admin/admin.php?page=def-core-logs&level=error&paged=2',
	'def-core-staff-ai'       => '/wp-admin/admin.php?page=def-core-staff-ai',
) as $old => $uri ) {
	$_SERVER['REQUEST_URI'] = $uri;
	try {
		( $_test_actions[ 'load-admin_page_' . $old ] )();
		$redirects[ $old ] = 'no redirect';
	} catch ( Test_Redirect $r ) {
		$redirects[ $old ] = $r->getMessage();
	}
}
check(
	array(
		'def-core'                => '302 /wp-admin/admin.php?page=widrow-ai',
		'def-core-content-drafts' => '302 /wp-admin/admin.php?page=widrow-ai-content-drafts',
		'def-core-logs'           => '302 /wp-admin/admin.php?page=widrow-ai-logs&level=error&paged=2',
		'def-core-staff-ai'       => '302 /wp-admin/admin.php?page=widrow-ai-staff-ai',
	) === $redirects,
	'Each redirects (302, wp_safe_redirect) to its new slug, keeping the rest of the query: ' . json_encode( $redirects )
);

// ── The service secret accessor dependents read ─────────────────────────────
echo "\n[secret] Widrow_AI_Encryption::get_service_secret()\n";
reset_site();
check( Widrow_AI_Legacy_Names::to_new( 'def_service_auth_secret' ) === Widrow_AI_Encryption::SERVICE_SECRET_OPTION, 'The accessor reads the key the migration moves the secret to' );
check( null === Widrow_AI_Encryption::get_service_secret(), 'Unset: null' );
Widrow_AI_Encryption::set_secret( Widrow_AI_Encryption::SERVICE_SECRET_OPTION, 'the-service-secret' );
check( 'the-service-secret' === Widrow_AI_Encryption::get_service_secret() && 'the-service-secret' !== get_option( 'widrow_service_auth_secret' ), 'Set: decrypted, while the option holds ciphertext' );

// ── Uninstall deletes the new names only ───────────────────────────────────
echo "\n[uninstall] the new names go; the old plugin's names are left alone\n";

final class WP_User {
	public $caps;
	public function __construct( int $id ) {
		global $_test_user_caps;
		$this->caps = &$_test_user_caps[ $id ];
	}
	public function remove_cap( string $cap ): void {
		unset( $this->caps[ $cap ] );
	}
}
function get_users( array $args ): array {
	global $_test_user_caps;
	return array_keys( $_test_user_caps );
}
reset_site();
$_wp_test_options = array(
	'blogname'                       => 'Example',
	'widrow_ai_api_key'              => 'ENC:new',
	'def_core_api_key'               => 'ENC:old',
	'widrow_service_auth_secret'     => 'ENC:new',
	'def_service_auth_secret'        => 'ENC:old',
	'def_core_display_name'          => 'Old only',
	'_transient_def_core_connection_test' => array(),
);
$_test_user_caps = array(
	1 => array( 'administrator' => true, 'widrow_admin_access' => true, 'def_admin_access' => true ),
	2 => array( 'editor' => true, 'def_staff_access' => true, 'widrow_role_hr' => true, 'def_role_legal' => true ),
);
define( 'WP_UNINSTALL_PLUGIN', 'widrow-ai/widrow-ai.php' );
require WIDROW_AI_PLUGIN_DIR . 'uninstall.php';
check(
	array(
		'blogname'                            => 'Example',
		'def_core_api_key'                    => 'ENC:old',
		'def_service_auth_secret'             => 'ENC:old',
		'def_core_display_name'               => 'Old only',
		'_transient_def_core_connection_test' => array(),
	) === $_wp_test_options,
	'The new options are deleted; the old ones stay, so deleting a 10.0.0 copy that never activated (or after a rollback) leaves the old copy its key, secret and settings'
);
check(
	array( 'administrator' => true, 'def_admin_access' => true ) === $_test_user_caps[1]
		&& array( 'editor' => true, 'def_staff_access' => true, 'def_role_legal' => true ) === $_test_user_caps[2],
	'The new capabilities and widrow_role_* are removed; the old ones and WordPress roles stay'
);

// ── Wiring: activation and upgrade both run it, before anything else ───────
echo "\n[wiring] activation and the db-version step\n";
$main = file_get_contents( WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai.php' );
check( 1 === preg_match( "/function on_activate\(\): void \{\s*(\/\/[^\n]*\n\s*)*if \( defined\( 'DEF_CORE_PLUGIN_NAME' \) \) \{\s*deactivate_plugins\( DEF_CORE_PLUGIN_NAME \);\s*\}\s*(\/\/[^\n]*\n\s*)*Widrow_AI_Legacy_Names::migrate\(\);\s*Widrow_AI_JWT::ensure_keys_exist\(\);/", $main ), 'on_activate() first switches off a still-active 9.x copy, then moves the names, before any default is created under a new name' );
check( 1 === preg_match( "/function maybe_upgrade\(\): void \{\s*\\\$current = get_option\( 'widrow_ai_db_version', '0' \);\s*(\/\/[^\n]*\n\s*)*if \( version_compare\( \\\$current, '10\.0\.0', '<' \) \) \{\s*Widrow_AI_Legacy_Names::migrate\(\);/", $main ), 'maybe_upgrade() runs it first, keyed on the 10.0.0 db-version step (an update does not rerun activation)' );

check( false !== strpos( $main, 'Widrow_AI_Legacy_Names::init();' ), 'The plugin hooks the old-slug pages on every load' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
