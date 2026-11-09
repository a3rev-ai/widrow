<?php
/**
 * Plugin Name: Widrow AI
 * Description: AI-powered Digital Employees for your WordPress site. Customer Chat for visitors, Staff AI for your team, and an intelligent Setup Assistant — all connected to the Digital Employee Framework.
 * Version: 10.0.0
 * Author: a3rev
 * Author URI: https://a3rev.com/
 * Text Domain: widrow-ai
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package widrow-ai
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants for upgrade and metadata.
if ( ! defined( 'WIDROW_AI_PLUGIN_NAME' ) ) {
	define( 'WIDROW_AI_PLUGIN_NAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'WIDROW_AI_KEY' ) ) {
	define( 'WIDROW_AI_KEY', 'widrow-ai' );
}
if ( ! defined( 'WIDROW_AI_VERSION' ) ) {
	define( 'WIDROW_AI_VERSION', '10.0.0' );
}

define( 'WIDROW_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WIDROW_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WIDROW_AI_OPTION_KEYS', 'widrow_ai_keys' );
define( 'WIDROW_AI_OPTION_ALLOWED_ORIGINS', 'widrow_ai_allowed_origins' );
define( 'WIDROW_AI_API_NAME_SPACE', 'a3-ai/v1' );
define( 'WIDROW_AI_AUDIENCE', 'digital-employee-framework' );

// Interim auto-updater: GitHub Releases.
// Remove this block once plugin is live on WordPress.org.
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$widrow_ai_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/a3rev-ai/widrow/',
	__FILE__,
	'widrow-ai'
);

// ── A release is only an update once its ZIP is on it (8.2.2) ───────────
// .github/workflows/release.yml fires on `release: [published]` and THEN builds
// and uploads widrow-ai.zip, so for the first minute or two the latest
// release carries no asset. PUC served GitHub's auto-generated source archive in
// that window — the raw repo, tests and CI config and all — because
// enableReleaseAssets() defaults to PREFER_RELEASE_ASSETS and getLatestRelease()
// seeds downloadUrl with $release->zipball_url before a matching asset overwrites
// it (Vcs/GitHubApi.php:105, :129). REQUIRE makes such a release no reference at
// all (:137), so it is not an update until the zip lands, and the Plugins-screen
// re-check below then picks it up within a few minutes of it landing.
//
// REQUIRE on its own does not close the window: chooseReference() falls through
// to the NEXT strategy (Vcs/Api.php:106-111), and getLatestTag() hands back the
// same source zipball for the tag `gh release create` just made (GitHubApi.php:175).
// So the tag and branch fallbacks come off the list as well — this plugin ships
// from a release asset and from nothing else.
$widrow_ai_vcs_api = $widrow_ai_update_checker->getVcsApi();
// Read off the API object's own class: PUC's namespace carries its version
// (…\v5p6\Vcs\Api), which the next library drop renames.
$widrow_ai_vcs_api->enableReleaseAssets( '/^widrow-ai\.zip$/', $widrow_ai_vcs_api::REQUIRE_RELEASE_ASSETS );

add_filter(
	$widrow_ai_update_checker->getUniqueName( 'vcs_update_detection_strategies' ),
	function ( $strategies ) use ( $widrow_ai_vcs_api ) {
		return array_intersect_key( $strategies, array( $widrow_ai_vcs_api::STRATEGY_LATEST_RELEASE => true ) );
	}
);

// Inject plugin icon into update/plugin-info screens.
$widrow_ai_update_checker->addResultFilter( function ( $plugin_info ) {
	$icon_base = plugins_url( 'assets/images/', __FILE__ );
	$plugin_info->icons = array(
		'svg' => $icon_base . 'icon.svg',
		'2x'  => $icon_base . 'icon-256x256.png',
		'1x'  => $icon_base . 'icon-128x128.png',
	);
	return $plugin_info;
} );

// ── The update row keeps up with the release (8.2.2) ────────────────────
// PUC's scheduler checks every 12 hours, and only ONCE AN HOUR on the Plugins
// screen (Scheduler::getEffectiveCheckPeriod gives load-plugins.php 3600s, while
// Dashboard → Updates already gets 60s). On 2026-09-15 four releases shipped in
// one day: the 21:35 update to 8.2.0 ran a check, 8.2.1 published ten minutes
// later, and both production sites were throttled for the rest of that hour —
// the zip went up by hand. These two bring the Plugins screen down to minutes,
// and close the single-update path PUC's own handler skips.
add_action( 'load-plugins.php', function () use ( $widrow_ai_update_checker ) {
	// admin.php:390 fires load-{$pagenow} BEFORE plugins.php:12 turns away a caller
	// who cannot manage plugins, so without this any logged-in account that can
	// reach wp-admin — a Subscriber, a Woo customer — could pump the check.
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( get_transient( 'widrow_ai_puc_recheck' ) ) {
		return;
	}
	// Five minutes, not one: ONE check is three anonymous api.github.com requests
	// (releases/latest, then the plugin header and readme.txt through /contents —
	// Vcs/PluginUpdateChecker::requestInfo), and the anonymous ceiling is 60 an hour
	// per IP. Twelve checks an hour is 36 of them, which leaves room for PUC's own
	// background and Dashboard → Updates checks. Past the ceiling every request is
	// an error, and with only the release strategy left there is nothing to fall
	// back to: checkForUpdates() stores setUpdate(null) (UpdateChecker.php:369) and
	// blanks a genuine pending update, quietly.
	set_transient( 'widrow_ai_puc_recheck', 1, 5 * MINUTE_IN_SECONDS );
	$widrow_ai_update_checker->checkForUpdates();
} );

// Scheduler::upgraderProcessComplete returns unless $hook_extra carries the BULK
// 'plugins' list, so a single "Update now" — and every background auto-update —
// left the row still offering the version that had just been installed.
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) use ( $widrow_ai_update_checker ) {
	if ( ! is_array( $hook_extra ) || isset( $hook_extra['plugins'] )
		|| WIDROW_AI_PLUGIN_NAME !== ( $hook_extra['plugin'] ?? '' ) ) {
		return;
	}
	$widrow_ai_update_checker->resetUpdateState();
	$widrow_ai_update_checker->checkForUpdates();
}, 10, 2 );

// Main plugin class.
require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai.php';
