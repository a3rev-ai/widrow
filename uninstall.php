<?php
/**
 * Digital Employees - Uninstall
 *
 * Removes all plugin options and transients when the plugin is deleted
 * (not deactivated — only on full uninstall via Plugins > Delete).
 *
 * @package widrow-ai
 * @since 2.3.0
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Connection config options.
$options = array(
	'widrow_ai_api_key',
	'widrow_service_auth_secret',
	'widrow_ai_staff_ai_api_url',
	'widrow_ai_conn_config_revision',
	'widrow_ai_conn_last_sync_at',
	'widrow_ai_conn_previous_service_secret',
	'widrow_ai_conn_previous_api_key',
	'widrow_ai_conn_rotation_expires',
	'widrow_ai_external_jwks_url',
	'widrow_ai_external_issuer',
	'widrow_ai_db_version',
	// The name DEF last gave the Creator — the connected tenant's, so it goes
	// when the connection does.
	'widrow_ai_creator_name',
);

// Branding options.
$options[] = 'widrow_ai_logo_id';
$options[] = 'widrow_ai_display_name';
$options[] = 'widrow_ai_logo_show_staff_ai';
$options[] = 'widrow_ai_logo_show_customer_chat';
$options[] = 'widrow_ai_logo_max_height';
$options[] = 'widrow_ai_app_icon_id';

// Chat settings.
$options[] = 'widrow_ai_chat_display_mode';
$options[] = 'widrow_ai_chat_drawer_width';
$options[] = 'widrow_ai_chat_button_position';
$options[] = 'widrow_ai_chat_button_color';
$options[] = 'widrow_ai_chat_button_hover_color';
$options[] = 'widrow_ai_chat_button_icon';
$options[] = 'widrow_ai_chat_button_label';
$options[] = 'widrow_ai_chat_button_icon_id';
$options[] = 'widrow_ai_chat_show_floating';
$options[] = 'widrow_ai_chat_greeting_bubble_enabled';
$options[] = 'widrow_ai_chat_greeting_bubble_text';
$options[] = 'widrow_ai_chat_opening_message';
$options[] = 'widrow_ai_chat_ai_notice';
$options[] = 'widrow_ai_chat_privacy_url';

// Escalation settings.
$options[] = 'widrow_ai_escalation_customer';
$options[] = 'widrow_ai_escalation_setup_assistant';

// Tool and key settings.
$options[] = 'widrow_ai_tools_status';
$options[] = 'widrow_ai_allowed_origins';
$options[] = 'widrow_ai_keys';
$options[] = 'widrow_ai_roles_catalog';
// The once-per-version rewrite flush stamp (8.0.0).
$options[] = 'widrow_ai_rewrite_version';

foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove transients.
delete_transient( 'widrow_ai_encryption_error' );
delete_transient( 'widrow_ai_connection_test' );

// Remove DEF capabilities from all users.
$capabilities = array( 'widrow_staff_access', 'widrow_management_access', 'widrow_admin_access' );
$users = get_users( array( 'fields' => 'ids' ) );
foreach ( $users as $user_id ) {
	$user = new WP_User( $user_id );
	foreach ( $capabilities as $cap ) {
		$user->remove_cap( $cap );
	}
	// Custom-role caps (R4): strip by prefix — the catalog may be stale/gone at uninstall time.
	foreach ( array_keys( $user->caps ) as $cap ) {
		if ( is_string( $cap ) && 0 === strpos( $cap, 'widrow_role_' ) ) {
			$user->remove_cap( $cap );
		}
	}
}
