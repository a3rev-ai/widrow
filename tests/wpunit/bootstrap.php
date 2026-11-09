<?php
/**
 * PHPUnit bootstrap for widrow-ai WP integration tests.
 *
 * Loads the WP test library from wp-env's test environment,
 * then activates widrow-ai so all hooks fire before tests run.
 *
 * @package widrow-ai
 */

// Composer autoload (for PHPUnit + polyfills).
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

/*
 * wp-env maps the WP test library to /wordpress-phpunit/ inside the container.
 * The WP_TESTS_DIR env var can override this if needed.
 */
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__, 2 ) . '/widrow-ai.php';
	}
);

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
