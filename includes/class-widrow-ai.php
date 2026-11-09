<?php
/**
 * Class Widrow_AI
 *
 * Main plugin class for the Digital Employee Framework - Core plugin.
 *
 * @package widrow-ai
 * @since 0.2.0
 * @version 0.2.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 *
 * @package widrow-ai
 */
final class Widrow_AI {
	/**
	 * The instance of the Widrow_AI class.
	 *
	 * @var Widrow_AI
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * @return Widrow_AI The instance.
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Load all required files.
		$this->load_dependencies();

		// Load text domain for translations.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Register assets.
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// Initialize components.
		Widrow_AI_Admin::init();
		Widrow_AI_Routes::init();
		Widrow_AI_Legacy_Names::init();
		Widrow_AI_Cache::init();
		Widrow_AI_Staff_AI::init();
		Widrow_AI_Escalation::init();
		Widrow_AI_Connection_Config::init();
		Widrow_AI_OAuth::init();
		Widrow_AI_Export::init();
		Widrow_AI_Search_Export::init();
		Widrow_AI_Knowledge_Export::init();
		Widrow_AI_Knowledge_Exclusion::init();
		Widrow_AI_Sync_Nudge::init();
		Widrow_AI_Staff_Roster::init();
		Widrow_AI_Admin_API::init();
		Widrow_AI_Site_Tools::init();
		Widrow_AI_SEO_Meta::init();
		Widrow_AI_Blocks::init();
		Widrow_AI_Media::init();
		Widrow_AI_Logs_Page::init();
		Widrow_AI_Content_Drafts_Page::init();
		Widrow_AI_Chat_Attribution::init();
		Widrow_AI_Goals::init();
		Widrow_AI_Chat_Button_Block::init();

		// Schedule log cleanup cron.
		Widrow_AI_Logger::schedule_cleanup();

		// Register deactivation hook for logger cleanup.
		register_deactivation_hook( WIDROW_AI_PLUGIN_DIR . 'widrow-ai.php', array( 'Widrow_AI_Logger', 'unschedule_cleanup' ) );

		// Register [def_chat_button] shortcode and action hook.
		add_shortcode( 'def_chat_button', array( $this, 'shortcode_chat_button' ) );
		add_shortcode( 'def_changelog', array( 'Widrow_AI_Changelog', 'render' ) );
		add_action( 'widrow_ai_chat_button', array( $this, 'action_chat_button' ) );

		// Register AJAX handlers for inline login (Loop 6).
		add_action( 'wp_ajax_nopriv_widrow_ai_inline_login', array( $this, 'ajax_inline_login' ) );
		add_action( 'wp_ajax_widrow_ai_inline_login', array( $this, 'ajax_inline_login' ) );

		// Register activation hook.
		register_activation_hook( WIDROW_AI_PLUGIN_DIR . 'widrow-ai.php', array( __CLASS__, 'on_activate' ) );

		// Add settings link to plugin action links.
		add_filter( 'plugin_action_links_' . plugin_basename( WIDROW_AI_PLUGIN_DIR . 'widrow-ai.php' ), array( $this, 'add_settings_link' ) );

		// Check for version upgrade (D-II capabilities).
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * Load all plugin dependencies.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	private function load_dependencies(): void {
		// Main plugin class (this file).
		// Core classes.
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-legacy-names.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-encryption.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-jwt.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-cache.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-changelog.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-admin.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-tools.php';

		// API Registry (must be loaded before routes).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-api-registry.php';

		// GitHub-based auto-updater (available to widrow-ai and all module plugins).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-github-updater.php';

		// Tool base class (for modules).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/tools/class-widrow-ai-tool-base.php';

		// Built-in conditional tools (auto-register when their plugin is active).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/tools/class-widrow-ai-tool-bbpress-tickets.php';

		// Staff AI frontend.
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-staff-ai.php';

		// Escalation email bridge.
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-escalation.php';

		// Theme color detection utility.
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-theme-colors.php';

		// Connection config (receive pushed config from DEFHO).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-connection-config.php';

		// OAuth client (one-click DEFHO connect).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-oauth.php';

		// Shared HMAC auth (used by export + admin API routes).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-hmac-auth.php';

		// Structured logger + logs viewer page.
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-logger.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-logs-page.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-content-drafts-page.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-chat-button-block.php';

		// Knowledge export endpoints.
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-export.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-search-export.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-knowledge-export.php';
		// Page Context Build Plan V1.1 Sub-PR C: server-side page-context
		// detection helpers; consumed by wp_localize_script (frontend) to
		// produce window.WidrowAiPageContext.
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-page-context.php';

		// Per-item exclusion from knowledge ingestion (v3.1.0).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-knowledge-exclusion.php';

		// Event-driven sync nudge — debounced push to DEF on content change (Phase B).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-sync-nudge.php';

		// Staff-AI roster push — User Access ticks → DEF (Usage & Budgets D-U10).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-staff-roster.php';

		// Admin REST API (settings, users, connection, chat proxy).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-admin-api.php';

		// Site Intelligence Tools — REST passthrough for Staff AI.
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-site-tools.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-seo-meta.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-blocks.php';
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-media.php';

		// Routes (registers core tools and allows modules to register).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-routes.php';

		// Chat-driven sale attribution (stamps _widrow_chat_id onto orders).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-chat-attribution.php';

		// Goal outcome reports (W75 G2).
		require_once WIDROW_AI_PLUGIN_DIR . 'includes/class-widrow-ai-goals.php';

		// Plugin inited action hook.
		add_action(
			'plugins_loaded',
			function () {
				do_action( 'widrow_ai_inited' );
			}
		);

		// Instantiate built-in conditional tools (auto-register via should_register()).
		add_action(
			'plugins_loaded',
			function () {
				new Widrow_AI_Tool_BbPress_Tickets();
			},
			20
		);

		// GitHub auto-updater for widrow-ai itself.
		new Widrow_AI_GitHub_Updater( array(
			'file'    => WIDROW_AI_PLUGIN_DIR . 'widrow-ai.php',
			'repo'    => 'a3rev-ai/widrow',
			'slug'    => 'widrow-ai',
			'asset'   => 'widrow-ai.zip',
			'version' => WIDROW_AI_VERSION,
		) );
	}

	/**
	 * Activation hook handler.
	 */
	public static function on_activate(): void {
		// The 9.x plugin shares no class names with this one, so WordPress lets
		// both be active, and its def-core/v1 handlers would load first. It is
		// switched off before its data moves.
		if ( defined( 'DEF_CORE_PLUGIN_NAME' ) ) {
			deactivate_plugins( DEF_CORE_PLUGIN_NAME );
		}
		// Then: the names stored before 10.0.0 move to their Widrow AI names
		// before anything below creates a default under a new name.
		Widrow_AI_Legacy_Names::migrate();
		Widrow_AI_JWT::ensure_keys_exist();
		if ( get_option( WIDROW_AI_OPTION_ALLOWED_ORIGINS ) === false ) {
			add_option( WIDROW_AI_OPTION_ALLOWED_ORIGINS, array(), '', false );
		}
		// Flush rewrite rules for Staff AI endpoint.
		Widrow_AI_Staff_AI::on_activate();
		// Grant widrow_admin_access to administrators.
		self::ensure_widrow_admin_capability();
		// Set button colors from theme if not already configured.
		self::maybe_set_theme_button_colors();
		// Create log table and schedule cleanup.
		Widrow_AI_Logger::create_table();
		Widrow_AI_Logger::schedule_cleanup();
		update_option( 'widrow_ai_db_version', '2.2.0' );
	}

	/**
	 * Check for version upgrade and run required migrations.
	 */
	public static function maybe_upgrade(): void {
		$current = get_option( 'widrow_ai_db_version', '0' );
		// 10.0.0: WordPress does not rerun activation on an update, so the
		// move to the new stored names is keyed here too, before any step
		// reads them; the marker it moves is then the one to compare.
		if ( version_compare( $current, '10.0.0', '<' ) ) {
			Widrow_AI_Legacy_Names::migrate();
			$current = get_option( 'widrow_ai_db_version', '0' );
		}
		if ( version_compare( $current, '2.1.0', '<' ) ) {
			self::ensure_widrow_admin_capability();
			update_option( 'widrow_ai_db_version', '2.1.0' );
		}
		if ( version_compare( $current, '2.2.0', '<' ) ) {
			Widrow_AI_Logger::create_table();
			self::ensure_widrow_admin_capability();
			update_option( 'widrow_ai_db_version', '2.2.0' );
		}
		if ( version_compare( $current, '7.0.0', '<' ) ) {
			// Usage & Budgets D-U10: backfill the Staff-AI roster once, so an
			// existing install populates DEF's usage dashboard without waiting
			// for someone to open User Access and press Save. The option is
			// advanced regardless of delivery — the push is best-effort and
			// re-sends full state on the next save, whereas gating the bump on
			// success would retry on every admin pageview forever.
			Widrow_AI_Staff_Roster::schedule_push();
			update_option( 'widrow_ai_db_version', '7.0.0' );
		}
		if ( version_compare( $current, '10.0.0', '<' ) ) {
			update_option( 'widrow_ai_db_version', '10.0.0' );
		}
	}

	/**
	 * Ensure DEF admin capability exists.
	 * Grants widrow_admin_access to all current administrators.
	 * Includes lockout prevention with V1.1 fallback chain.
	 */
	public static function ensure_widrow_admin_capability(): void {
		$admins = get_users( array(
			'capability' => 'manage_options',
			'fields'     => 'ids',
		) );

		foreach ( $admins as $user_id ) {
			$user = new \WP_User( $user_id );
			if ( ! $user->has_cap( 'widrow_admin_access' ) ) {
				$user->add_cap( 'widrow_admin_access' );
			}
		}

		// Lockout prevention: at least one user must have the capability.
		$def_admins = get_users( array(
			'capability' => 'widrow_admin_access',
			'fields'     => 'ids',
			'number'     => 1,
		) );

		if ( empty( $def_admins ) ) {
			// Fallback 1: admin_email user.
			$admin_email = get_option( 'admin_email' );
			$admin_user  = get_user_by( 'email', $admin_email );

			if ( $admin_user ) {
				$admin_user->add_cap( 'widrow_admin_access' );
			} else {
				// V1.1 fallback: first Administrator by user ID ascending.
				$fallback = get_users( array(
					'role'    => 'administrator',
					'orderby' => 'ID',
					'order'   => 'ASC',
					'number'  => 1,
				) );
				if ( ! empty( $fallback ) ) {
					$fallback[0]->add_cap( 'widrow_admin_access' );
				}
			}
		}
	}

	/**
	 * Register assets (available everywhere, but not enqueued).
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'widrow-ai', false, dirname( plugin_basename( WIDROW_AI_PLUGIN_DIR . 'widrow-ai.php' ) ) . '/languages/' );
	}

	/**
	 * Register all plugin assets.
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function register_assets(): void {
		// Register frontend scripts (only enqueued on frontend via wp_enqueue_scripts).
		wp_register_script(
			'widrow-ai',
			WIDROW_AI_PLUGIN_URL . 'assets/js/widrow-ai.js',
			array(),
			WIDROW_AI_VERSION,
			array( 'in_footer' => true )
		);

		wp_register_script(
			'widrow-ai-cart-sync',
			WIDROW_AI_PLUGIN_URL . 'assets/js/widrow-ai-cart-sync.js',
			array(),
			WIDROW_AI_VERSION,
			array( 'in_footer' => true )
		);

		// Register admin assets (only enqueued on admin pages).
		wp_register_style(
			'widrow-ai-admin',
			WIDROW_AI_PLUGIN_URL . 'assets/css/widrow-ai-admin.css',
			array(),
			WIDROW_AI_VERSION
		);
		wp_register_script(
			'widrow-ai-admin',
			WIDROW_AI_PLUGIN_URL . 'assets/js/widrow-ai-admin.js',
			array(),
			WIDROW_AI_VERSION,
			array( 'in_footer' => true )
		);

		// Content Agent review queue (PR-6) — draft cards on the Content Drafts
		// admin page. Depends on DOMPurify to sanitize untrusted draft HTML.
		wp_register_style(
			'widrow-ai-draft-cards',
			WIDROW_AI_PLUGIN_URL . 'assets/css/widrow-ai-draft-cards.css',
			array( 'widrow-ai-admin' ),
			WIDROW_AI_VERSION
		);
		wp_register_script(
			'widrow-ai-draft-cards',
			WIDROW_AI_PLUGIN_URL . 'assets/js/widrow-ai-draft-cards.js',
			array( 'widrow-ai-purify' ),
			WIDROW_AI_VERSION,
			array( 'in_footer' => true )
		);

		// Content Agent Engine 2.5 — Clusters tab (target nomination + keyphrase
		// queue curation) on the Content Drafts page. Reads the DefDraftCards
		// config localized onto widrow-ai-draft-cards, so it loads after it. All
		// model-authored text is rendered via textContent (no HTML path).
		wp_register_script(
			'widrow-ai-cluster-targets',
			WIDROW_AI_PLUGIN_URL . 'assets/js/widrow-ai-cluster-targets.js',
			array( 'widrow-ai-draft-cards' ),
			WIDROW_AI_VERSION,
			array( 'in_footer' => true )
		);

		// V2 agent persona helper — shared across all three channels (Customer
		// Chat, Staff AI, Setup Assistant). Renders the "X is helping" divider
		// + persistent thinking-row prefix when an Orchestrator V2 specialist
		// takes over from the Concierge. window.DefPersona global, IIFE-loaded.
		wp_register_script(
			'widrow-ai-persona',
			WIDROW_AI_PLUGIN_URL . 'assets/js/def-persona.js',
			array(),
			WIDROW_AI_VERSION,
			array( 'in_footer' => true )
		);

		// Page Context Build Plan V1.1 Sub-PR C: client-side capture +
		// sessionStorage trail accumulation + chat-payload builder.
		// Loaded on the frontend BEFORE the customer-chat module so
		// window.WidrowAiPageContextHelper is available when the chat
		// builds its message body.
		wp_register_script(
			'widrow-ai-page-context',
			WIDROW_AI_PLUGIN_URL . 'assets/js/widrow-ai-page-context.js',
			array(),
			WIDROW_AI_VERSION,
			array( 'in_footer' => true )
		);

		// Native Customer Chat loader (enqueued on frontend).
		// Depends on widrow-ai-persona so window.DefPersona is available
		// before the lazy-loaded chat module fetches. Also depends on
		// widrow-ai-page-context so window.WidrowAiPageContextHelper is
		// loaded + window.WidrowAiPageContext is populated before any
		// chat message is sent.
		wp_register_script(
			'widrow-ai-customer-chat-loader',
			WIDROW_AI_PLUGIN_URL . 'assets/js/widrow-ai-customer-chat-loader.js',
			array( 'widrow-ai-persona', 'widrow-ai-page-context' ),
			WIDROW_AI_VERSION,
			array( 'in_footer' => true )
		);

		// Trigger-button styles for the [def_chat_button] shortcode and the
		// widrow_ai_chat_button action hook. The floating trigger button
		// lives inside the chat Shadow DOM and gets its styles from the
		// loader's inline <style> block — those styles cannot reach
		// shortcode-placed buttons in the main document. This stylesheet
		// mirrors the floating button's design so both render identically.
		wp_register_style(
			'widrow-ai-chat-trigger',
			WIDROW_AI_PLUGIN_URL . 'assets/css/def-chat-trigger.css',
			array(),
			WIDROW_AI_VERSION
		);

		// Full chat module (lazy-loaded by loader, NOT enqueued directly).
		wp_register_script(
			'widrow-ai-customer-chat',
			WIDROW_AI_PLUGIN_URL . 'assets/js/widrow-ai-customer-chat.js',
			array(),
			WIDROW_AI_VERSION,
			array( 'in_footer' => true )
		);

		// Chat styles (injected into Shadow DOM by loader, NOT enqueued directly).
		wp_register_style(
			'widrow-ai-customer-chat',
			WIDROW_AI_PLUGIN_URL . 'assets/css/widrow-ai-customer-chat.css',
			array(),
			WIDROW_AI_VERSION
		);

		// Vendor libraries for customer chat (lazy-loaded by loader, NOT enqueued directly).
		wp_register_script(
			'widrow-ai-marked',
			WIDROW_AI_PLUGIN_URL . 'assets/js/vendor/marked.min.js',
			array(),
			'15.0.12',
			array( 'in_footer' => true )
		);
		wp_register_script(
			'widrow-ai-purify',
			WIDROW_AI_PLUGIN_URL . 'assets/js/vendor/purify.min.js',
			array(),
			'3.1.6',
			array( 'in_footer' => true )
		);

		// Setup Assistant drawer assets.
		wp_register_style(
			'widrow-ai-setup-assistant',
			WIDROW_AI_PLUGIN_URL . 'assets/css/setup-assistant-drawer.css',
			array( 'widrow-ai-admin' ),
			WIDROW_AI_VERSION
		);
		wp_register_script(
			'widrow-ai-setup-assistant',
			WIDROW_AI_PLUGIN_URL . 'assets/js/setup-assistant-drawer.js',
			array( 'widrow-ai-admin', 'widrow-ai-persona' ),
			WIDROW_AI_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Enqueue frontend assets (only on frontend).
	 *
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public function enqueue_frontend_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$current_user   = wp_get_current_user();
		$user_first_name = ( $current_user->ID > 0 )
			? ( $current_user->first_name ?: $current_user->display_name )
			: '';

		$rest_data = array(
			// Existing keys.
			'restUrl'        => esc_url_raw( rest_url( WIDROW_AI_API_NAME_SPACE . '/context-token' ) ),
			'chatStreamUrl'  => esc_url_raw( rest_url( WIDROW_AI_API_NAME_SPACE . '/chat/stream' ) ),
			'streamTokenUrl' => esc_url_raw( rest_url( WIDROW_AI_API_NAME_SPACE . '/chat/stream-token' ) ),
			'uploadInitUrl'  => esc_url_raw( rest_url( WIDROW_AI_API_NAME_SPACE . '/uploads/init' ) ),
			'uploadCommitUrl' => esc_url_raw( rest_url( WIDROW_AI_API_NAME_SPACE . '/uploads/commit' ) ),
			'loginUrl'       => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'siteUrl'        => esc_url_raw( home_url() ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'allowedOrigins' => $this->get_allowed_origins(),
			// User context.
			'isLoggedIn'     => is_user_logged_in() ? '1' : '',
			'userFirstName'  => $user_first_name,
			// Branding.
			'displayName'    => get_option( 'widrow_ai_display_name', get_bloginfo( 'name' ) ),
			// Per-tenant employee name from DEF (Employee & Tools slice 1a/1b). The
			// chat header + greeting render this so the assistant's name matches the
			// override the tenant set in the Tenant Portal — falling back to the
			// branding displayName above when DEF is unreachable or no override is
			// set. The launcher/button label stays the admin branding setting
			// (buttonLabel below) and is intentionally independent of this.
			'assistantName'  => Widrow_AI_Tools::get_customer_assistant_name(),
			'logoUrl'        => $this->get_logo_url_for_frontend(),
			'heroImageUrl'        => $this->get_hero_image_url_for_frontend( 'desktop' ),
			'heroImageMobileUrl'  => $this->get_hero_image_url_for_frontend( 'mobile' ),
			'logoShow'       => '0' !== get_option( 'widrow_ai_logo_show_customer_chat', '1' ),
			'logoMaxHeight'  => (int) get_option( 'widrow_ai_logo_max_height', 40 ),
			// Chat settings.
			'chatDisplayMode'      => get_option( 'widrow_ai_chat_display_mode', 'modal' ),
			'chatDrawerWidth'      => (int) get_option( 'widrow_ai_chat_drawer_width', 400 ),
			'chatSpotlightWidth'   => (int) get_option( 'widrow_ai_chat_spotlight_width', 960 ),
			'chatSpotlightHeight'  => (int) get_option( 'widrow_ai_chat_spotlight_height', 600 ),
			// Button appearance.
			'buttonPosition'  => get_option( 'widrow_ai_chat_button_position', 'right' ),
			'buttonColor'     => get_option( 'widrow_ai_chat_button_color', '#111827' ),
			'buttonHoverColor' => get_option( 'widrow_ai_chat_button_hover_color', '' ),
			'buttonIcon'      => get_option( 'widrow_ai_chat_button_icon', 'chat' ),
			'buttonIconUrl'   => $this->get_button_icon_url(),
			'loadingMarkUrl'  => $this->get_loading_mark_url(),
			'escalationPrefOptions' => self::get_escalation_pref_options(),
			'escalationBusinessFields' => (string) get_option( 'widrow_ai_chat_escalation_business_fields', 'hidden' ),
			'buttonLabel'     => get_option( 'widrow_ai_chat_button_label', 'Chat' ),
			'showFloatingButton' => '0' !== get_option( 'widrow_ai_chat_show_floating', '1' ),
			// Greeting Bubble (v3.12.0) — proactive pop-up above the launcher.
			'greetingBubbleEnabled' => '0' !== get_option( 'widrow_ai_chat_greeting_bubble_enabled', '1' ),
			'greetingBubbleText'    => get_option( 'widrow_ai_chat_greeting_bubble_text', "Hi! I'm your AI assistant\nI'm here to help!" ),
			// Opening message (8.6.0) — the chat's first message; blank = the built-in one.
			'openingMessage'        => get_option( 'widrow_ai_chat_opening_message', '' ),
			// WooCommerce context.
			'wooActive'       => ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) ? '1' : '',
			// AI Disclosure Notice — master toggle + link fields (rendered
			// at the bottom of the chat panel). `aiNoticeEnabled` gates
			// the entire footer; when off, no footer renders regardless
			// of complianceText / privacyUrl / privacyLinkLabel.
			'aiNoticeEnabled'  => '0' !== get_option( 'widrow_ai_chat_ai_notice', '0' ),
			'privacyUrl'       => get_option( 'widrow_ai_chat_privacy_url', '' ),
			// Coalesce empty saved value to the same placeholder the admin
			// Preview uses, so a cleared field still renders sensibly on the
			// frontend instead of falling through to a different default.
			'privacyLinkLabel' => get_option( 'widrow_ai_chat_privacy_link_label', '' ) ?: __( 'Terms & Conditions', 'widrow-ai' ),
			// Welcome state polish (v2.7.0 chips + v2.9.0 per-chip intros).
			'welcomeChip1'        => get_option( 'widrow_ai_chat_welcome_chip_1', '' ),
			'welcomeChip1Intro'   => get_option( 'widrow_ai_chat_welcome_chip_1_intro', '' ),
			'welcomeChip2'        => get_option( 'widrow_ai_chat_welcome_chip_2', '' ),
			'welcomeChip2Intro'   => get_option( 'widrow_ai_chat_welcome_chip_2_intro', '' ),
			'welcomeChip3'        => get_option( 'widrow_ai_chat_welcome_chip_3', '' ),
			'welcomeChip3Intro'   => get_option( 'widrow_ai_chat_welcome_chip_3_intro', '' ),
			// AI Disclosure Notice — the lead sentence. The trailing link
			// words are in privacyLinkLabel and rendered separately.
			// Coalesce empty saved value to the placeholder default so a
			// cleared field still renders the footer (matches admin Preview)
			// instead of suppressing the whole footer at runtime.
			'complianceText'  => get_option( 'widrow_ai_chat_compliance_text', '' ) ?: __( 'AI responses may be inaccurate. By using this assistant, you agree to our', 'widrow-ai' ),
			// API URL for direct fetch.
			'apiBaseUrl'      => self::get_def_api_url(),
			// WP REST URL + nonce for same-origin calls (escalation send).
			'wpRestUrl'       => esc_url_raw( rest_url( WIDROW_AI_API_NAME_SPACE . '/' ) ),
			'wpRestNonce'     => wp_create_nonce( 'wp_rest' ),
			// Bare /wp-json/ root, for cross-namespace REST calls (e.g.
			// WooCommerce Store API at wc/store/v1/cart/add-item).
			'wpRestRoot'      => esc_url_raw( rest_url( '' ) ),
			// WC Store API uses its own nonce action ("wc_store_api") and
			// requires a `Nonce` header on every write, including the first.
			// On successful responses the server rotates the nonce via the
			// `Nonce` response header — the widget tracks that and uses the
			// latest on subsequent calls. This is just the bootstrap value.
			'wcStoreApiNonce' => wp_create_nonce( 'wc_store_api' ),
			// Asset URLs for lazy loading (versioned for cache-busting).
			'chatModuleUrl'   => WIDROW_AI_PLUGIN_URL . 'assets/js/widrow-ai-customer-chat.js?ver=' . WIDROW_AI_VERSION,
			// Voice (7.7.8) — the module Staff AI shares; loaded before the chat module.
			'voiceModuleUrl'  => WIDROW_AI_PLUGIN_URL . 'assets/js/widrow-ai-voice.js?ver=' . WIDROW_AI_VERSION,
			'chatStyleUrl'    => WIDROW_AI_PLUGIN_URL . 'assets/css/widrow-ai-customer-chat.css?ver=' . WIDROW_AI_VERSION,
			'markedUrl'       => WIDROW_AI_PLUGIN_URL . 'assets/js/vendor/marked.min.js?ver=' . WIDROW_AI_VERSION,
			'purifyUrl'       => WIDROW_AI_PLUGIN_URL . 'assets/js/vendor/purify.min.js?ver=' . WIDROW_AI_VERSION,
			// V1.2 Result Cards renderer — loaded after purify, before chat module.
			'productCardsScriptUrl' => WIDROW_AI_PLUGIN_URL . 'assets/js/widrow-ai-product-cards.js?ver=' . WIDROW_AI_VERSION,
			'productCardsStyleUrl'  => WIDROW_AI_PLUGIN_URL . 'assets/css/widrow-ai-product-cards.css?ver=' . WIDROW_AI_VERSION,
			// i18n strings.
			'strings'         => $this->get_chat_strings(),
		);

		// Enqueue native loader (replaces old bridge script).
		wp_localize_script( 'widrow-ai-customer-chat-loader', 'WidrowAI', $rest_data );

		// Page Context Build Plan V1.1 Sub-PR C: inject the page-context
		// payload as `window.WidrowAiPageContext`. JS overrides
		// `canonical_path` from `window.location.pathname` at mount time
		// and adds `referrer_path` at submit time, but every other field
		// is PHP-derived (page type, IDs, queried taxonomy, terms, title,
		// language).
		//
		// v3.4.1 fix: use wp_add_inline_script with wp_json_encode rather
		// than wp_localize_script. WP's localize routine casts every TOP-
		// level scalar value to a string before output (see
		// WP_Scripts::localize), which mangles integer fields like page_id
		// and product_id. The inline-script + json_encode path preserves
		// types end-to-end — integers stay integers, nulls stay nulls.
		// The page-context script (loaded BEFORE the customer-chat module
		// via its dependency entry) reads window.WidrowAiPageContext on
		// mount; nothing else needs to change.
		if ( class_exists( 'Widrow_AI_Page_Context' ) ) {
			/**
			 * Site companions may add to the page context the chat sends with every
			 * message (7.6.5). Keys DEF does not know are ignored on the DEF side.
			 *
			 * @param array $payload The PHP-derived page context.
			 */
			$payload = (array) apply_filters( 'widrow_ai_page_context', Widrow_AI_Page_Context::build_payload() );
			// JSON_HEX_TAG: the payload lands inside an inline <script>; a value carrying
			// `<!--<script>` would otherwise put the parser into script-data double-escaped
			// state and swallow every following script on the page.
			wp_add_inline_script(
				'widrow-ai-page-context',
				'window.WidrowAiPageContext = ' . wp_json_encode( $payload, JSON_HEX_TAG ) . ';',
				'before'
			);
		}

		wp_enqueue_script( 'widrow-ai-customer-chat-loader' );

		// Trigger-button styles for shortcode/hook-placed buttons. The CSS
		// uses var(--def-chat-btn-color) / var(--def-chat-btn-hover); the
		// inline-style below sets those variables from the same admin
		// options that drive the floating button.
		wp_enqueue_style( 'widrow-ai-chat-trigger' );
		$btn_color = get_option( 'widrow_ai_chat_button_color', '#111827' );
		$btn_hover = get_option( 'widrow_ai_chat_button_hover_color', '' );
		if ( '' === $btn_hover ) {
			$btn_hover = $btn_color;
		}
		wp_add_inline_style(
			'widrow-ai-chat-trigger',
			sprintf(
				':root{--def-chat-btn-color:%s;--def-chat-btn-hover:%s;}',
				esc_attr( $btn_color ),
				esc_attr( $btn_hover )
			)
		);

		// Cart sync (unchanged).
		if ( $this->should_enqueue_cart_sync() ) {
			wp_enqueue_script( 'widrow-ai-cart-sync' );
		}
	}

	/**
	 * Check if cart sync script should be enqueued.
	 *
	 * Note: widrow-ai-cart-sync.js is the iframe integration helper used
	 * for embedded-widget deployments where the chat lives in an iframe
	 * separate from the storefront. It listens for postMessage events
	 * from the parent page. The widget itself (widrow-ai-customer-chat.js)
	 * no longer drives cart sync since v2.4.0 — Store API's Cart-Token
	 * handles guest sessions directly. Kept enqueued for any iframe-
	 * integrated tenants that still rely on the helper. Candidate for
	 * deletion in a follow-up if no live tenants are using it.
	 *
	 * @return bool True if WooCommerce is installed and active.
	 * @since 0.2.0
	 */
	private function should_enqueue_cart_sync(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Get allowed origins from options.
	 *
	 * @return array Array of allowed origins.
	 */
	private function get_allowed_origins(): array {
		$origins = get_option( WIDROW_AI_OPTION_ALLOWED_ORIGINS, array() );
		if ( ! is_array( $origins ) ) {
			$origins = array();
		}
		return array_values( array_filter( array_map( 'trim', $origins ) ) );
	}

	/**
	 * Add settings link to plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array Modified links.
	 */
	public function add_settings_link( array $links ): array {
		$url     = admin_url( 'options-general.php?page=widrow-ai' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'widrow-ai' ) . '</a>';
		return $links;
	}

	/**
	 * Handle inline login AJAX request (Loop 6).
	 *
	 * Uses standard WordPress authentication via wp_signon().
	 * This respects any login plugins (2FA, reCAPTCHA, SSO).
	 *
	 * @since 0.3.0
	 */
	public function ajax_inline_login(): void {
		// Verify nonce.
		if ( ! check_ajax_referer( 'wp_rest', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'widrow-ai' ) ) );
			return;
		}

		// Get credentials.
		$username = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '';
		$password = isset( $_POST['pwd'] ) ? $_POST['pwd'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter both username and password.', 'widrow-ai' ) ) );
			return;
		}

		// Attempt login using WordPress standard authentication.
		$creds = array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => true,
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			// Login failed.
			$error_message = $user->get_error_message();
			// Sanitize error message - don't reveal too much.
			if ( strpos( $error_message, 'username' ) !== false || strpos( $error_message, 'password' ) !== false ) {
				$error_message = __( 'Login failed — please check your details and try again.', 'widrow-ai' );
			}
			wp_send_json_error( array( 'message' => $error_message ) );
			return;
		}

		// Login successful - set the auth cookie.
		wp_set_current_user( $user->ID );
		// A nonce is bound to the session in $_COOKIE, which this request doesn't hold yet.
		add_action(
			'set_logged_in_cookie',
			static function ( $cookie ) {
				$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
			}
		);
		wp_set_auth_cookie( $user->ID, true, is_ssl() );

		// Generate a context token for the newly logged-in user.
		// We do this here because the auth cookie won't be available for
		// subsequent JS fetch calls until the browser processes this response.
		$claims = array(
			'sub'          => (string) $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'first_name'   => $user->user_firstname,
			'email'        => $user->user_email,
			'roles'        => array_values( (array) $user->roles ),
			'iss'          => get_site_url(),
			'aud'          => WIDROW_AI_AUDIENCE,
		);
		$token = Widrow_AI_JWT::issue_token( $claims, 300 ); // 5 minutes.

		wp_send_json_success(
			array(
				'user_id'     => $user->ID,
				'token'       => $token,
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'store_nonce' => wp_create_nonce( 'wc_store_api' ),
			)
		);
	}

	// ─── Customer Chat: Shortcode + Action Hook ──────────────────

	/**
	 * Shortcode: [def_chat_button label="Chat with us" class="my-class" icon="sparkle" prompt="What could you do for my business?"]
	 *
	 * `prompt` (8.5.0) is the question the chat asks on the visitor's behalf when the
	 * button is clicked; it rides as `data-def-chat-prompt`. Omit it for a plain open.
	 *
	 * Per-instance attributes override the corresponding Chat Settings
	 * options. When `label`, `icon` are omitted, the saved option values
	 * (`widrow_ai_chat_button_label`, `widrow_ai_chat_button_icon`,
	 * `widrow_ai_chat_button_icon_id`) drive rendering — same surface as
	 * the floating button.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Button HTML.
	 */
	public function shortcode_chat_button( $atts ): string {
		$atts = shortcode_atts( array(
			'label'  => '',
			'class'  => '',
			'icon'   => '',
			'prompt' => '',
		), $atts, 'def_chat_button' );

		// Resolve from the shortcode attr first, then fall back to the
		// Chat Settings option, then a sensible default.
		$label = '' !== $atts['label']
			? $atts['label']
			: get_option( 'widrow_ai_chat_button_label', __( 'Chat', 'widrow-ai' ) );
		$icon  = '' !== $atts['icon']
			? $atts['icon']
			: get_option( 'widrow_ai_chat_button_icon', 'chat' );

		$classes = 'def-chat-trigger-btn';
		if ( ! empty( $atts['class'] ) ) {
			$classes .= ' ' . sanitize_html_class( $atts['class'], '' );
		}

		$prompt      = trim( (string) $atts['prompt'] );
		$prompt_attr = '' !== $prompt ? ' data-def-chat-prompt="' . esc_attr( $prompt ) . '"' : '';

		return sprintf(
			'<button type="button" class="%s" data-def-chat-trigger%s>%s<span class="def-chat-trigger-btn-label">%s</span></button>',
			esc_attr( $classes ),
			$prompt_attr,
			$this->render_chat_button_icon( $icon ),
			esc_html( $label )
		);
	}

	/**
	 * Render the icon markup for the trigger button. Mirrors the SVG paths
	 * the floating button uses (widrow-ai-customer-chat-loader.js, see
	 * createTrigger / icon switch). Same icon set, same proportions, same
	 * sparkle hover animation.
	 *
	 * @param string $icon Icon key: 'chat', 'headset', 'sparkle', 'custom', 'none'.
	 * @return string Icon HTML (already escaped where needed). Empty for 'none'.
	 */
	private function render_chat_button_icon( string $icon ): string {
		switch ( $icon ) {
			case 'custom':
				$icon_id  = (int) get_option( 'widrow_ai_chat_button_icon_id', 0 );
				$icon_url = $icon_id ? wp_get_attachment_image_url( $icon_id, 'thumbnail' ) : '';
				if ( ! $icon_url ) {
					return '';
				}
				return '<span class="def-chat-trigger-btn-icon">'
					. '<img src="' . esc_url( $icon_url ) . '" alt="" aria-hidden="true">'
					. '</span>';

			case 'headset':
				return '<span class="def-chat-trigger-btn-icon">'
					. '<svg viewBox="0 0 24 24" aria-hidden="true">'
					. '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/>'
					. '<path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>'
					. '</svg>'
					. '</span>';

			case 'sparkle':
				return '<span class="def-chat-trigger-btn-icon def-chat-trigger-btn-icon--sparkle">'
					. '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true">'
					. '<path d="M12 2C12.7 6.3 13.2 8.2 15 10C16.8 11.8 18.7 12.3 23 13C18.7 13.7 16.8 14.2 15 16C13.2 17.8 12.7 19.7 12 24C11.3 19.7 10.8 17.8 9 16C7.2 14.2 5.3 13.7 1 13C5.3 12.3 7.2 11.8 9 10C10.8 8.2 11.3 6.3 12 2Z"/>'
					. '<path d="M20 1C20.3 2.6 20.5 3.2 21 3.7C21.5 3.2 21.7 2.6 22 1C21.7 2.6 21.5 3.2 21 3.7C20.5 3.2 20.3 2.6 20 1Z"/>'
					. '<path d="M3 19C3.2 20 3.4 20.4 3.7 20.7C4 20.4 4.2 20 4.4 19C4.2 20 4 20.4 3.7 20.7C3.4 20.4 3.2 20 3 19Z"/>'
					. '</svg>'
					. '</span>';

			case 'none':
				return '';

			case 'chat':
			default:
				return '<span class="def-chat-trigger-btn-icon">'
					. '<svg viewBox="0 0 24 24" aria-hidden="true">'
					. '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'
					. '</svg>'
					. '</span>';
		}
	}

	/**
	 * Action hook: <?php do_action('widrow_ai_chat_button', ['label' => 'Chat']); ?>
	 *
	 * @param array $args Button arguments.
	 */
	public function action_chat_button( $args = array() ): void {
		$args = wp_parse_args( $args, array(
			'label' => __( 'Chat', 'widrow-ai' ),
			'class' => '',
			'icon'  => 'none',
		) );
		echo $this->shortcode_chat_button( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// ─── Customer Chat: Private Helpers ──────────────────────────

	/**
	 * Get logo URL for frontend (image URL, not HTML).
	 * Chain: widrow_ai_logo_id → custom_logo → null.
	 *
	 * @return string|null Logo URL or null.
	 */
	private function get_logo_url_for_frontend(): ?string {
		$logo_id = (int) get_option( 'widrow_ai_logo_id', 0 );
		if ( $logo_id ) {
			$url = wp_get_attachment_image_url( $logo_id, 'medium' );
			if ( $url ) {
				return $url;
			}
		}
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$url = wp_get_attachment_image_url( (int) $custom_logo_id, 'medium' );
			if ( $url ) {
				return $url;
			}
		}
		return null;
	}

	/**
	 * Get the welcome banner image URL for the customer chat (browser-side).
	 * Optional — empty string if no banner is configured.
	 *
	 * Two variants: desktop (~5:1 wide strip, up to 960px wide in Spotlight)
	 * and mobile (~2.7:1 chunkier banner, up to ~480px wide on phones).
	 * Bunnings uses two separate images for the two viewport classes —
	 * the desktop strip is too short on mobile, the mobile banner is too
	 * tall on desktop. The widget renders a `<picture>` element with both
	 * sources when both are configured.
	 *
	 * @param string $variant 'desktop' or 'mobile'.
	 * @return string Banner URL or empty string.
	 */
	private function get_hero_image_url_for_frontend( string $variant = 'desktop' ): string {
		$option_key = 'mobile' === $variant
			? 'widrow_ai_chat_hero_image_mobile_id'
			: 'widrow_ai_chat_hero_image_id';
		$banner_id  = (int) get_option( $option_key, 0 );
		if ( ! $banner_id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $banner_id, 'large' );
		if ( ! $url ) {
			$url = wp_get_attachment_image_url( $banner_id, 'full' );
		}
		return $url ? $url : '';
	}

	/**
	 * Get Python backend API URL for customer chat (browser-side).
	 *
	 * The stored widrow_ai_staff_ai_api_url is for server-side PHP proxy calls
	 * and may use Docker internal hostnames (e.g. http://def-api:8000).
	 * Browser-facing channels (Customer Chat, Setup Assistant SSE) use
	 * direct fetch(), so they need a browser-accessible URL. Replace
	 * non-routable Docker hostnames with the current site's hostname.
	 *
	 * @return string API base URL or empty string.
	 */
	public static function get_def_api_url(): string {
		// Environment-aware: wp-config.php define takes priority,
		// then stored option, then default production URL.
		// Either source may carry a Docker-internal hostname (e.g. "def-api"),
		// so the rewrite below applies uniformly to both.
		if ( defined( 'DEF_API_URL' ) && DEF_API_URL ) {
			$url = rtrim( DEF_API_URL, '/' );
		} else {
			$url = get_option( 'widrow_ai_staff_ai_api_url', '' );
			if ( empty( $url ) ) {
				return 'https://api.defho.ai';
			}
			$url = rtrim( $url, '/' );
		}

		// If the URL host is not browser-routable (Docker internal name),
		// replace it with the current site's hostname.
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return $url;
		}

		$host = $parsed['host'];

		// Localhost, IPs, and real domains are browser-routable.
		if (
			'localhost' === $host ||
			'127.0.0.1' === $host ||
			filter_var( $host, FILTER_VALIDATE_IP ) ||
			preg_match( '/\.[a-z]{2,}$/i', $host )
		) {
			return $url;
		}

		// Docker internal hostname (e.g. "def-api") — swap with site host.
		$site_parsed = wp_parse_url( home_url() );
		$site_host   = $site_parsed['host'] ?? 'localhost';

		$scheme = $parsed['scheme'] ?? 'http';
		$port   = ! empty( $parsed['port'] ) ? ':' . $parsed['port'] : '';
		$path   = $parsed['path'] ?? '';

		return $scheme . '://' . $site_host . $port . $path;
	}

	/**
	 * Get the DEF API URL for server-side (PHP) proxy requests.
	 *
	 * Unlike get_def_api_url() which rewrites Docker hostnames for browser
	 * routing, this returns the raw stored URL — suitable for cURL calls
	 * from WordPress to DEF within the Docker network.
	 *
	 * @return string API base URL for server-side requests.
	 */
	public static function get_def_api_url_internal(): string {
		if ( defined( 'DEF_API_URL' ) && DEF_API_URL ) {
			return rtrim( DEF_API_URL, '/' );
		}

		$url = get_option( 'widrow_ai_staff_ai_api_url', '' );
		if ( empty( $url ) ) {
			return 'https://api.defho.ai';
		}

		return rtrim( $url, '/' );
	}

	/**
	 * Get custom button icon URL from media library.
	 *
	 * @return string|null Icon URL or null.
	 */
	private function get_button_icon_url(): ?string {
		$icon = get_option( 'widrow_ai_chat_button_icon', 'chat' );
		if ( 'custom' !== $icon ) {
			return null;
		}
		$id = (int) get_option( 'widrow_ai_chat_button_icon_id', 0 );
		if ( $id <= 0 ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $id, 'thumbnail' );
		return $url ?: null;
	}

	/**
	 * Get custom loading-mark URL from media library (replaceable loading
	 * mark, theming contract T-D5). Original file URL, not a resized
	 * thumbnail, so an animated GIF/SVG keeps its animation.
	 *
	 * @return string|null Mark URL or null.
	 */
	private function get_loading_mark_url(): ?string {
		$id = (int) get_option( 'widrow_ai_chat_loading_mark_id', 0 );
		if ( $id <= 0 ) {
			return null;
		}
		$url = wp_get_attachment_url( $id );
		return $url ?: null;
	}

	/**
	 * Preferred-contact choices for the Customer Chat hand-off form (5.6.0).
	 * One per line in the admin option; disabled or empty = no select rendered.
	 * The server validates the submitted value against this same list.
	 *
	 * @return array<string> Option labels in configured order.
	 */
	public static function get_escalation_pref_options(): array {
		if ( '1' !== get_option( 'widrow_ai_chat_escalation_pref_enabled', '0' ) ) {
			return array();
		}
		$raw   = (string) get_option( 'widrow_ai_chat_escalation_pref_options', '' );
		$lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );
		return array_values( array_map( 'sanitize_text_field', $lines ) );
	}

	/**
	 * Get i18n strings for chat widget.
	 *
	 * @return array Translatable strings.
	 */
	private function get_chat_strings(): array {
		$strings = array(
			'clearChat'            => __( 'Clear conversation & start fresh', 'widrow-ai' ),
			'clearConfirmTitle'    => __( 'Clear conversation?', 'widrow-ai' ),
			'clearConfirmDesc'     => __( 'This will clear your current conversation. This action cannot be undone.', 'widrow-ai' ),
			'clearConfirmYes'      => __( 'Clear & start fresh', 'widrow-ai' ),
			'cancel'               => __( 'Cancel', 'widrow-ai' ),
			'typePlaceholder'      => __( 'Type your message...', 'widrow-ai' ),
			'greeting'             => __( 'Hello! How can I help you today?', 'widrow-ai' ),
			'sending'              => __( 'Sending...', 'widrow-ai' ),
			'login'                => __( 'Log in', 'widrow-ai' ),
			'loginTitle'           => __( 'Log in to continue', 'widrow-ai' ),
			'loginSubmit'          => __( 'Log in', 'widrow-ai' ),
			'sessionExpired'       => __( 'Session expired — please log in again', 'widrow-ai' ),
			'escalate'             => __( 'Talk to a person', 'widrow-ai' ),
			'escalateDesc'         => __( "Describe your issue below and we'll connect you with a human.", 'widrow-ai' ),
			'escalatePref'         => __( 'How would you like us to contact you?', 'widrow-ai' ),
			'escalateSubmit'       => __( 'Send', 'widrow-ai' ),
			'escalateSuccess'      => __( 'Your email has been sent.', 'widrow-ai' ),
			'escalateAckSent'      => __( "We've emailed you a confirmation.", 'widrow-ai' ),
			'uploadFailed'         => __( 'Upload failed. Please try again.', 'widrow-ai' ),
			'uploadReadFailed'     => __( 'Could not read the file. Please remove it, re-select it and try again.', 'widrow-ai' ),
			'fileTypeNotSupported' => __( 'File type not supported', 'widrow-ai' ),
			'attachAfterFirstMessage' => __( 'Send your first message, then attach the image.', 'widrow-ai' ),
			'offlineTitle'         => __( 'Chat is currently unavailable', 'widrow-ai' ),
			'offlineMessage'       => __( 'This feature is being set up. Please check back soon.', 'widrow-ai' ),
			'privacyPolicy'        => __( 'Privacy Policy', 'widrow-ai' ),
			'connectionError'      => __( 'Unable to connect. Please try again.', 'widrow-ai' ),
			'connectionLost'       => __( 'Connection lost. Retrying...', 'widrow-ai' ),
			// The proxy's own words for a refusal (Widrow_AI_Tools::sse_error_payload), for one streamed direct.
			'assistantUnavailable' => __( 'The assistant is unavailable right now. Please try again.', 'widrow-ai' ),
			'rateLimited'          => __( 'Please wait a moment before sending another message', 'widrow-ai' ),
			'streamIncomplete'     => __( 'Connection lost — the reply above may be incomplete.', 'widrow-ai' ),
			/* translators: %d: seconds until the rate limit window reopens. */
			'retrySuffix'          => __( '(retry in %ds)', 'widrow-ai' ),
			'uploadInitFailed'     => __( 'Upload could not start. Please try again.', 'widrow-ai' ),
			'uploadCommitFailed'   => __( 'Upload could not be completed. Please try again.', 'widrow-ai' ),
			// Voice (7.7.8): the mic in the composer, shown when the employee's Voice switch is on.
			'micStart'             => __( 'Speak', 'widrow-ai' ),
			'micBlockedBySite'     => __( "This site's security settings block the microphone for every visitor (Permissions-Policy). The site admin needs to allow it for this site.", 'widrow-ai' ),
			'micDenied'            => __( 'The microphone is blocked for this site in your browser. Allow it in the site permissions (the icon beside the address bar) and try again.', 'widrow-ai' ),
			'micNotFound'          => __( 'No microphone was found on this device.', 'widrow-ai' ),
			/* translators: %e: the browser's own error name. */
			'micFailed'            => __( "The microphone couldn't start (%e).", 'widrow-ai' ),
			'tapToSpeakAgain'      => __( 'Tap the mic to speak again.', 'widrow-ai' ),
			'micClosedIdle'        => __( 'The mic closed — tap it to speak again.', 'widrow-ai' ),
			/* translators: comma-separated phrases that END a hands-free conversation when spoken alone; the employee's name may lead any of them ("Joe, stop"). */
			'voiceStopPhrases'     => __( "stop, that's all, thanks that's all", 'widrow-ai' ),
			'voiceStopped'         => __( 'Conversation ended.', 'widrow-ai' ),
			'nothingHeard'         => __( 'Nothing was heard. Try again a little closer to the microphone.', 'widrow-ai' ),
			'transcribeFailed'     => __( 'That recording could not be transcribed. Please try again.', 'widrow-ai' ),
			'listening'            => __( "Listening… pause when you're done, or tap to send", 'widrow-ai' ),
			'transcribing'         => __( 'Transcribing…', 'widrow-ai' ),
			'answering'            => __( 'Answering… tap the mic to end', 'widrow-ai' ),
			'voiceNeedsStreaming'  => __( 'Voice needs a browser that can stream replies.', 'widrow-ai' ),
			'voiceLogEmpty'        => __( 'No voice events yet.', 'widrow-ai' ),
		);

		// Per-site overrides for the hand-off form copy (5.6.0): the same form
		// serves support on one site and sales lead capture on another, so the
		// framing is admin-configurable. Empty options keep the defaults.
		$custom_title = (string) get_option( 'widrow_ai_chat_escalation_title', '' );
		if ( '' !== $custom_title ) {
			$strings['escalate'] = $custom_title;
		}
		$custom_desc = (string) get_option( 'widrow_ai_chat_escalation_desc', '' );
		if ( '' !== $custom_desc ) {
			$strings['escalateDesc'] = $custom_desc;
		}

		return apply_filters( 'widrow_ai_chat_strings', $strings );
	}

	// ─── Theme Color Detection (delegates to Widrow_AI_Theme_Colors) ─────

	/**
	 * Set button colors from the active theme on first activation.
	 */
	public static function maybe_set_theme_button_colors(): void {
		Widrow_AI_Theme_Colors::maybe_set_defaults();
	}

	/**
	 * Detect button colors from the active WordPress theme.
	 */
	public static function detect_theme_button_colors(): array {
		return Widrow_AI_Theme_Colors::detect();
	}
}

// Initialize the plugin.
Widrow_AI::instance();
