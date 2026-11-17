<?php
/**
 * The 11.0.0 upgrade step: the Staff AI console left the plugin for the Tenant Portal.
 *
 * An update never fires the activation hook, so maybe_upgrade() is what reaches every
 * site. The step flushes the stored /staff-ai rewrite rules (left in place, WordPress
 * would serve the home page there), deletes the marker the old once-per-version flush
 * kept, clears the staff roster push's scheduled hook, re-grants DEF Admin to
 * administrators and records the version.
 *
 * @package widrow-ai
 */

declare(strict_types=1);

class Test_Upgrade_11_0_0 extends WP_UnitTestCase {

	public function test_the_step_flushes_the_console_rules_and_clears_what_served_it(): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		// What a 10.x site has stored: the console's rule, and the old flush marker.
		add_rewrite_rule( '^staff-ai/?$', 'index.php?staff-ai=1', 'top' );
		flush_rewrite_rules();
		$this->assertArrayHasKey( '^staff-ai/?$', (array) get_option( 'rewrite_rules' ) );
		$wp_rewrite->extra_rules_top = array();

		update_option( 'widrow_ai_rewrite_version', '10.1.3' );
		wp_schedule_single_event( time() + 30, 'widrow_ai_staff_roster_push' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_option( 'widrow_ai_db_version', '10.0.0' );

		Widrow_AI::maybe_upgrade();

		$this->assertArrayNotHasKey( '^staff-ai/?$', (array) get_option( 'rewrite_rules' ), 'the stored /staff-ai rule is flushed away' );
		$this->assertFalse( get_option( 'widrow_ai_rewrite_version' ), 'the old flush marker is deleted' );
		$this->assertFalse( wp_next_scheduled( 'widrow_ai_staff_roster_push' ), 'the roster push is unscheduled' );
		$this->assertTrue( user_can( $admin, 'widrow_admin_access' ), 'an administrator holds DEF Admin' );
		$this->assertSame( '11.0.0', get_option( 'widrow_ai_db_version' ) );
	}
}
