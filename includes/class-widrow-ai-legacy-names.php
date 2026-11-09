<?php
/**
 * Class Widrow_AI_Legacy_Names
 *
 * The one table of the names this plugin stored before 10.0.0 (as def-core) and
 * the names it stores now, and the one-time migration that moves a site from
 * the first to the second. Everything that has to understand an old name reads
 * it from here: the migration, the Setup Assistant's setting and role routes
 * (DEF still sends old names until its own change), the redirects for the old
 * wp-admin page slugs, and uninstall.
 *
 * @package widrow-ai
 * @since   10.0.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Widrow_AI_Legacy_Names {

	/** Options: old => new. Moved with their value; the old row is deleted. */
	const OPTIONS = array(
		'def_core_allowed_origins'                 => 'widrow_ai_allowed_origins',
		'def_core_api_key'                         => 'widrow_ai_api_key',
		'def_core_app_icon_id'                     => 'widrow_ai_app_icon_id',
		'def_core_chat_ai_notice'                  => 'widrow_ai_chat_ai_notice',
		'def_core_chat_button_color'               => 'widrow_ai_chat_button_color',
		'def_core_chat_button_hover_color'         => 'widrow_ai_chat_button_hover_color',
		'def_core_chat_button_icon'                => 'widrow_ai_chat_button_icon',
		'def_core_chat_button_icon_id'             => 'widrow_ai_chat_button_icon_id',
		'def_core_chat_button_label'               => 'widrow_ai_chat_button_label',
		'def_core_chat_button_position'            => 'widrow_ai_chat_button_position',
		'def_core_chat_compliance_text'            => 'widrow_ai_chat_compliance_text',
		'def_core_chat_display_mode'               => 'widrow_ai_chat_display_mode',
		'def_core_chat_drawer_width'               => 'widrow_ai_chat_drawer_width',
		'def_core_chat_escalation_ack_body'        => 'widrow_ai_chat_escalation_ack_body',
		'def_core_chat_escalation_ack_enabled'     => 'widrow_ai_chat_escalation_ack_enabled',
		'def_core_chat_escalation_ack_subject'     => 'widrow_ai_chat_escalation_ack_subject',
		'def_core_chat_escalation_business_fields' => 'widrow_ai_chat_escalation_business_fields',
		'def_core_chat_escalation_desc'            => 'widrow_ai_chat_escalation_desc',
		'def_core_chat_escalation_pref_enabled'    => 'widrow_ai_chat_escalation_pref_enabled',
		'def_core_chat_escalation_pref_options'    => 'widrow_ai_chat_escalation_pref_options',
		'def_core_chat_escalation_title'           => 'widrow_ai_chat_escalation_title',
		'def_core_chat_greeting_bubble_enabled'    => 'widrow_ai_chat_greeting_bubble_enabled',
		'def_core_chat_greeting_bubble_text'       => 'widrow_ai_chat_greeting_bubble_text',
		'def_core_chat_hero_image_id'              => 'widrow_ai_chat_hero_image_id',
		'def_core_chat_hero_image_mobile_id'       => 'widrow_ai_chat_hero_image_mobile_id',
		'def_core_chat_loading_mark_id'            => 'widrow_ai_chat_loading_mark_id',
		'def_core_chat_opening_message'            => 'widrow_ai_chat_opening_message',
		'def_core_chat_privacy_link_label'         => 'widrow_ai_chat_privacy_link_label',
		'def_core_chat_privacy_url'                => 'widrow_ai_chat_privacy_url',
		'def_core_chat_show_floating'              => 'widrow_ai_chat_show_floating',
		'def_core_chat_spotlight_height'           => 'widrow_ai_chat_spotlight_height',
		'def_core_chat_spotlight_width'            => 'widrow_ai_chat_spotlight_width',
		'def_core_chat_welcome_chip_1'             => 'widrow_ai_chat_welcome_chip_1',
		'def_core_chat_welcome_chip_1_intro'       => 'widrow_ai_chat_welcome_chip_1_intro',
		'def_core_chat_welcome_chip_2'             => 'widrow_ai_chat_welcome_chip_2',
		'def_core_chat_welcome_chip_2_intro'       => 'widrow_ai_chat_welcome_chip_2_intro',
		'def_core_chat_welcome_chip_3'             => 'widrow_ai_chat_welcome_chip_3',
		'def_core_chat_welcome_chip_3_intro'       => 'widrow_ai_chat_welcome_chip_3_intro',
		'def_core_conn_config_revision'            => 'widrow_ai_conn_config_revision',
		'def_core_conn_last_sync_at'               => 'widrow_ai_conn_last_sync_at',
		'def_core_conn_previous_api_key'           => 'widrow_ai_conn_previous_api_key',
		'def_core_conn_previous_service_secret'    => 'widrow_ai_conn_previous_service_secret',
		'def_core_conn_rotation_expires'           => 'widrow_ai_conn_rotation_expires',
		'def_core_creator_name'                    => 'widrow_ai_creator_name',
		'def_core_db_version'                      => 'widrow_ai_db_version',
		'def_core_deleted_posts'                   => 'widrow_ai_deleted_posts',
		'def_core_display_name'                    => 'widrow_ai_display_name',
		'def_core_escalation_customer'             => 'widrow_ai_escalation_customer',
		'def_core_escalation_setup_assistant'      => 'widrow_ai_escalation_setup_assistant',
		'def_core_escalation_staff_ai'             => 'widrow_ai_escalation_staff_ai',
		'def_core_exclusion_changes'               => 'widrow_ai_exclusion_changes',
		'def_core_external_issuer'                 => 'widrow_ai_external_issuer',
		'def_core_external_jwks_url'               => 'widrow_ai_external_jwks_url',
		'def_core_keys'                            => 'widrow_ai_keys',
		'def_core_log_level'                       => 'widrow_ai_log_level',
		'def_core_logo_id'                         => 'widrow_ai_logo_id',
		'def_core_logo_max_height'                 => 'widrow_ai_logo_max_height',
		'def_core_logo_show_customer_chat'         => 'widrow_ai_logo_show_customer_chat',
		'def_core_logo_show_staff_ai'              => 'widrow_ai_logo_show_staff_ai',
		'def_core_oauth_defho_url'                 => 'widrow_ai_oauth_defho_url',
		'def_core_rewrite_version'                 => 'widrow_ai_rewrite_version',
		'def_core_roles_catalog'                   => 'widrow_ai_roles_catalog',
		'def_core_setup_audit_log'                 => 'widrow_ai_setup_audit_log',
		'def_core_staff_ai_api_url'                => 'widrow_ai_staff_ai_api_url',
		'def_core_status_transitions'              => 'widrow_ai_status_transitions',
		'def_core_tools_status'                    => 'widrow_ai_tools_status',
		'def_core_wf_allowlisted_ips'              => 'widrow_ai_wf_allowlisted_ips',
		'def_core_wf_api_missing_logged'           => 'widrow_ai_wf_api_missing_logged',
		'def_service_auth_secret'                  => 'widrow_service_auth_secret',
	);

	/** Options named by prefix (one per Staff AI thread, one per OAuth start): moved. */
	const OPTION_PREFIXES = array(
		'def_core_share_events_' => 'widrow_ai_share_events_',
		'_def_oauth_pkce_'       => '_widrow_oauth_pkce_',
	);

	/** Capabilities: old => new, in every role and on every user. */
	const CAPABILITIES = array(
		'def_admin_access'      => 'widrow_admin_access',
		'def_staff_access'      => 'widrow_staff_access',
		'def_management_access' => 'widrow_management_access',
		'def_ap_access'         => 'widrow_ap_access',
	);

	/** Custom-role capabilities keep their slug: def_role_<slug> => widrow_role_<slug>. */
	const CAPABILITY_PREFIXES = array(
		'def_role_' => 'widrow_role_',
	);

	/** Old transient names and name prefixes: deleted, they rebuild under the new names. */
	const TRANSIENTS = array(
		'def_core_connection_test',
		'def_core_customer_assistant_name',
		'def_core_encryption_error',
		'def_core_puc_recheck',
		'def_core_ack_',
		'def_core_rl_',
		'def_core_external_jwks_',
		'def_sa_rate_',
		'def_test_email_',
		'def_gh_update_',
	);

	/** WP-Cron hooks: old => new. Each event is rescheduled at its time, recurrence and args. */
	const CRON_HOOKS = array(
		'def_core_cleanup_pkce'      => 'widrow_ai_cleanup_pkce',
		'def_core_goal_outcome'      => 'widrow_ai_goal_outcome',
		'def_core_log_cleanup'       => 'widrow_ai_log_cleanup',
		'def_core_staff_roster_push' => 'widrow_ai_staff_roster_push',
		'def_core_sync_nudge'        => 'widrow_ai_sync_nudge',
	);

	/** Tables, without the site prefix: old => new. */
	const TABLES = array(
		'def_core_logs' => 'widrow_ai_logs',
	);

	/** Post meta (and WooCommerce order meta): old => new. */
	const POST_META = array(
		'_def_exclude_from_ingestion' => '_widrow_exclude_from_ingestion',
		'_def_chat_id'                => '_widrow_chat_id',
		'_def_created'                => '_widrow_created',
		'_def_content_optimized_at'   => '_widrow_content_optimized_at',
		'_def_optimized_keyphrase'    => '_widrow_optimized_keyphrase',
	);

	/** User meta: old => new. */
	const USER_META = array(
		'def_sa_drawer_seen' => 'widrow_sa_drawer_seen',
	);

	/** Left behind by the old update checker's slug: deleted. */
	const ORPHAN_SITE_OPTIONS = array( 'external_updates-digital-employees' );
	const ORPHAN_CRON_HOOKS   = array( 'puc_cron_check_updates-digital-employees' );

	/** wp-admin page slugs: old => new. The old ones redirect (DEFHO links to them). */
	const ADMIN_PAGES = array(
		'def-core'                => 'widrow-ai',
		'def-core-logs'           => 'widrow-ai-logs',
		'def-core-content-drafts' => 'widrow-ai-content-drafts',
		'def-core-staff-ai'       => 'widrow-ai-staff-ai',
	);

	/**
	 * Hook the old page slugs.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_old_pages' ) );
	}

	/**
	 * The old slugs as hidden pages that send the browser to the new slug,
	 * keeping the rest of the query string (and the browser keeps any #tab).
	 * The new page checks its own capability.
	 */
	public static function register_old_pages(): void {
		foreach ( self::ADMIN_PAGES as $old => $new ) {
			$hook = add_submenu_page( '', '', '', 'read', $old, '__return_null' );
			if ( ! $hook ) {
				continue;
			}
			add_action(
				'load-' . $hook,
				static function () use ( $new ) {
					wp_safe_redirect( add_query_arg( 'page', $new ) );
					exit;
				}
			);
		}
	}

	/**
	 * The current name for an option key or capability; any other name unchanged.
	 *
	 * @param string $name Old or new name.
	 * @return string
	 */
	public static function to_new( string $name ): string {
		return self::translate( $name, self::OPTIONS + self::CAPABILITIES, self::OPTION_PREFIXES + self::CAPABILITY_PREFIXES );
	}

	/**
	 * The pre-10.0.0 name for an option key or capability; any other name unchanged.
	 *
	 * @param string $name New or old name.
	 * @return string
	 */
	public static function to_old( string $name ): string {
		return self::translate(
			$name,
			array_flip( self::OPTIONS + self::CAPABILITIES ),
			array_flip( self::OPTION_PREFIXES + self::CAPABILITY_PREFIXES )
		);
	}

	private static function translate( string $name, array $names, array $prefixes ): string {
		if ( isset( $names[ $name ] ) ) {
			return $names[ $name ];
		}
		foreach ( $prefixes as $from => $to ) {
			if ( 0 === strpos( $name, $from ) ) {
				return $to . substr( $name, strlen( $from ) );
			}
		}
		return $name;
	}

	// ─── The migration ──────────────────────────────────────────────────

	/**
	 * Move every stored old name to its new name. Converges: a fresh site gets
	 * nothing, a second run changes nothing, where both names exist the new one
	 * wins, and an interrupted run finishes on the next.
	 *
	 * @return array{before: array, after: array} Counts per category.
	 */
	public static function migrate(): array {
		$before = self::census();

		self::move_options();
		self::move_capabilities();
		self::delete_transients();
		self::move_cron();
		self::rename_tables();
		self::move_meta();
		foreach ( self::ORPHAN_SITE_OPTIONS as $option ) {
			delete_site_option( $option );
		}

		$counts = array(
			'before' => $before,
			'after'  => self::census(),
		);
		if ( $counts['before'] !== $counts['after'] && class_exists( 'Widrow_AI_Logger' ) ) {
			Widrow_AI_Logger::info( 'migration', 'Stored names moved to their Widrow AI names (10.0.0).', $counts );
		}
		return $counts;
	}

	/**
	 * How many of each kind of stored name exist, old and new.
	 *
	 * @return array<string, array{old: int, new: int}>
	 */
	public static function census(): array {
		global $wpdb;
		$missing = new \stdClass();

		$options = array( 'old' => 0, 'new' => 0 );
		foreach ( self::OPTIONS as $old => $new ) {
			$options['old'] += (int) ( get_option( $old, $missing ) !== $missing );
			$options['new'] += (int) ( get_option( $new, $missing ) !== $missing );
		}
		foreach ( self::OPTION_PREFIXES as $old => $new ) {
			$options['old'] += count( self::option_names( $old ) );
			$options['new'] += count( self::option_names( $new ) );
		}

		$caps = array( 'old' => 0, 'new' => 0 );
		$sets = array_column( (array) get_option( self::roles_option(), array() ), 'capabilities' );
		foreach ( self::cap_holders() as $user_caps ) {
			$sets[] = $user_caps;
		}
		foreach ( $sets as $set ) {
			foreach ( array_keys( (array) $set ) as $cap ) {
				$cap = (string) $cap;
				if ( self::cap_to_new( $cap ) !== $cap ) {
					$caps['old']++;
				} elseif ( self::cap_to_old( $cap ) !== $cap ) {
					$caps['new']++;
				}
			}
		}

		$transients = 0;
		foreach ( self::TRANSIENTS as $name ) {
			$transients += count( self::option_names( '_transient_' . $name ) );
		}

		$cron = array( 'old' => 0, 'new' => 0 );
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( self::CRON_HOOKS + array_fill_keys( self::ORPHAN_CRON_HOOKS, '' ) as $old => $new ) {
				$cron['old'] += count( $hooks[ $old ] ?? array() );
				$cron['new'] += '' === $new ? 0 : count( $hooks[ $new ] ?? array() );
			}
		}

		$tables = array( 'old' => 0, 'new' => 0 );
		foreach ( self::TABLES as $old => $new ) {
			$tables['old'] += (int) self::table_exists( $wpdb->prefix . $old );
			$tables['new'] += (int) self::table_exists( $wpdb->prefix . $new );
		}

		$meta = array( 'old' => 0, 'new' => 0 );
		foreach ( self::meta_tables() as list( $table, , $map ) ) {
			foreach ( $map as $old => $new ) {
				$meta['old'] += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key = %s", $old ) );
				$meta['new'] += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key = %s", $new ) );
			}
		}

		$orphans = 0;
		foreach ( self::ORPHAN_SITE_OPTIONS as $option ) {
			$orphans += (int) ( get_site_option( $option, $missing ) !== $missing );
		}

		return array(
			'options'      => $options,
			'capabilities' => $caps,
			'transients'   => array( 'old' => $transients, 'new' => 0 ),
			'cron'         => $cron,
			'tables'       => $tables,
			'meta'         => $meta,
			'orphans'      => array( 'old' => $orphans, 'new' => 0 ),
		);
	}

	private static function move_options(): void {
		foreach ( self::OPTIONS as $old => $new ) {
			self::move_option( $old, $new );
		}
		foreach ( self::OPTION_PREFIXES as $old_prefix => $new_prefix ) {
			foreach ( self::option_names( $old_prefix ) as $old ) {
				self::move_option( $old, $new_prefix . substr( $old, strlen( $old_prefix ) ) );
			}
		}
	}

	/**
	 * Old never set: nothing. New not set: the old value moves, keeping its
	 * autoload. The old row goes only once the new one is there, so where both
	 * existed the new one wins, and a failed write leaves the old for the next run.
	 */
	private static function move_option( string $old, string $new ): void {
		$missing = new \stdClass();
		$value   = get_option( $old, $missing );
		if ( $value === $missing ) {
			return;
		}
		if ( get_option( $new, $missing ) === $missing ) {
			add_option( $new, $value, '', array_key_exists( $old, wp_load_alloptions() ) );
		}
		if ( get_option( $new, $missing ) !== $missing ) {
			delete_option( $old );
		}
	}

	/**
	 * Every role's capabilities (the user_roles option) and every user's own
	 * grants (the capabilities user meta). The new name is added before the old
	 * one is removed, for the same holder, so a later revoke of the new name
	 * leaves no old grant behind.
	 */
	private static function move_capabilities(): void {
		$roles_option = self::roles_option();
		$roles        = get_option( $roles_option, array() );
		$changed      = false;
		foreach ( (array) $roles as $role => $data ) {
			$caps = self::rename_caps( (array) ( $data['capabilities'] ?? array() ) );
			if ( $caps !== ( $data['capabilities'] ?? array() ) ) {
				$roles[ $role ]['capabilities'] = $caps;
				$changed                        = true;
			}
		}
		if ( $changed ) {
			update_option( $roles_option, $roles );
			wp_roles()->for_site();
		}

		$meta_key = self::caps_meta_key();
		foreach ( self::cap_holders( '"def_' ) as $user_id => $caps ) {
			$renamed = self::rename_caps( $caps );
			if ( $renamed !== $caps ) {
				update_user_meta( $user_id, $meta_key, $renamed );
			}
		}
	}

	private static function rename_caps( array $caps ): array {
		foreach ( $caps as $cap => $grant ) {
			$new = self::cap_to_new( (string) $cap );
			if ( $new === (string) $cap ) {
				continue;
			}
			if ( ! array_key_exists( $new, $caps ) ) {
				$caps[ $new ] = $grant;
			}
			unset( $caps[ $cap ] );
		}
		return $caps;
	}

	private static function delete_transients(): void {
		foreach ( self::TRANSIENTS as $name ) {
			foreach ( self::option_names( '_transient_' . $name ) as $option ) {
				delete_transient( substr( $option, strlen( '_transient_' ) ) );
			}
		}
	}

	private static function move_cron(): void {
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( self::CRON_HOOKS as $old => $new ) {
				foreach ( $hooks[ $old ] ?? array() as $event ) {
					$args = $event['args'] ?? array();
					if ( wp_next_scheduled( $new, $args ) ) {
						continue;
					}
					if ( empty( $event['schedule'] ) ) {
						wp_schedule_single_event( (int) $timestamp, $new, $args );
					} else {
						wp_schedule_event( (int) $timestamp, $event['schedule'], $new, $args );
					}
				}
			}
		}
		foreach ( array_merge( array_keys( self::CRON_HOOKS ), self::ORPHAN_CRON_HOOKS ) as $old ) {
			wp_unschedule_hook( $old );
		}
	}

	private static function rename_tables(): void {
		global $wpdb;
		foreach ( self::TABLES as $old => $new ) {
			$old = $wpdb->prefix . $old;
			$new = $wpdb->prefix . $new;
			if ( self::table_exists( $old ) && ! self::table_exists( $new ) ) {
				$wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from constants.
			}
		}
	}

	/**
	 * Rows under the old key are dropped where the same object already has the
	 * new key (new wins), then the rest are renamed in place.
	 */
	private static function move_meta(): void {
		global $wpdb;
		$moved = 0;
		foreach ( self::meta_tables() as list( $table, $id, $map ) ) {
			foreach ( $map as $old => $new ) {
				$moved += (int) $wpdb->query( $wpdb->prepare( "DELETE o FROM {$table} o INNER JOIN {$table} n ON n.{$id} = o.{$id} AND n.meta_key = %s WHERE o.meta_key = %s", $new, $old ) );
				$moved += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET meta_key = %s WHERE meta_key = %s", $new, $old ) );
			}
		}
		if ( $moved > 0 ) {
			wp_cache_flush();
		}
	}

	/** @return array<int, array{0: string, 1: string, 2: array<string, string>}> Table, object id column, old key => new key. */
	private static function meta_tables(): array {
		global $wpdb;
		$tables = array(
			array( $wpdb->postmeta, 'post_id', self::POST_META ),
			array( $wpdb->usermeta, 'user_id', self::USER_META ),
		);
		if ( self::table_exists( $wpdb->prefix . 'wc_orders_meta' ) ) {
			$tables[] = array( $wpdb->prefix . 'wc_orders_meta', 'order_id', self::POST_META );
		}
		return $tables;
	}

	/** @return string[] Option names starting with $prefix. */
	private static function option_names( string $prefix ): array {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}

	/**
	 * Users whose own capabilities name either namespace (or only $like).
	 *
	 * @return array<int, array> User ID => capabilities meta.
	 */
	private static function cap_holders( string $like = '' ): array {
		global $wpdb;
		$meta_key = self::caps_meta_key();
		$holders  = array();
		foreach ( '' === $like ? array( '"def_', '"widrow_' ) : array( $like ) as $pattern ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", $meta_key, '%' . $wpdb->esc_like( $pattern ) . '%' ) );
			foreach ( (array) $ids as $user_id ) {
				$holders[ (int) $user_id ] = (array) get_user_meta( (int) $user_id, $meta_key, true );
			}
		}
		return $holders;
	}

	private static function cap_to_new( string $cap ): string {
		return self::translate( $cap, self::CAPABILITIES, self::CAPABILITY_PREFIXES );
	}

	private static function cap_to_old( string $cap ): string {
		return self::translate( $cap, array_flip( self::CAPABILITIES ), array_flip( self::CAPABILITY_PREFIXES ) );
	}

	private static function roles_option(): string {
		global $wpdb;
		return $wpdb->get_blog_prefix() . 'user_roles';
	}

	private static function caps_meta_key(): string {
		global $wpdb;
		return $wpdb->get_blog_prefix() . 'capabilities';
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
