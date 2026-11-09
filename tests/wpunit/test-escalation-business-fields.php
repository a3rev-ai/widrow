<?php
/**
 * The hand-off form's Business name and website are a per-site choice, and a site that
 * has chosen nothing shows neither.
 *
 * @package widrow-ai
 */

declare(strict_types=1);

class Test_Escalation_Business_Fields extends WP_UnitTestCase {

	public function test_a_saved_choice_is_kept(): void {
		foreach ( array( 'required', 'optional', 'hidden' ) as $choice ) {
			$this->assertSame( $choice, Widrow_AI_Admin::sanitize_escalation_business_fields( $choice ) );
		}
	}

	public function test_anything_else_saves_as_hidden(): void {
		$this->assertSame( 'hidden', Widrow_AI_Admin::sanitize_escalation_business_fields( '<b>required</b>x' ) );
		$this->assertSame( 'hidden', Widrow_AI_Admin::sanitize_escalation_business_fields( '' ) );
	}
}
