<?php
/**
 * Admin settings page template — 5-tab layout.
 * Phase 7 D-I: Foundation tabbed layout with AJAX save.
 * Connection Config Migration: Connection moved to last tab with status dot indicator.
 *
 * Template variables set by Widrow_AI_Admin::render_settings_page():
 *   $conn_api_url    string  DEF API URL (pushed from DEFHO).
 *   $conn_revision   int     Current connection config revision.
 *   $conn_last_sync  string  When the connection was established. Site-local
 *                            `Y-m-d H:i:s`, written by the OAuth callback and a manual
 *                            save only — it is not the content sync, which runs from
 *                            the portal. No offset in the string, so date_i18n() over
 *                            strtotime() is right and wp_date() would apply it twice.
 *   $tools           array   Registered tools from API registry.
 *   $tools_status    array   Tool enable/disable status.
 *
 * @package widrow-ai
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tabs = array(
	'branding'        => __( 'Branding', 'widrow-ai' ),
	'chat-settings'   => __( 'Chat Settings', 'widrow-ai' ),
	'escalation'      => __( 'Contact Form', 'widrow-ai' ),
	'user-roles'      => __( 'User Roles', 'widrow-ai' ),
	'connection'      => __( 'Connection', 'widrow-ai' ),
);

$first_tab = 'branding';
?>
<div class="wrap widrow-ai-wrap">
	<h1><?php esc_html_e( 'Widrow AI', 'widrow-ai' ); ?>
		<?php if ( current_user_can( 'widrow_admin_access' ) ) : ?>
		<!-- Setup Assistant trigger button (inline to prevent layout shift) -->
		<button
			type="button"
			id="def-setup-assistant-trigger"
			class="def-sa-trigger"
			aria-expanded="false"
			aria-controls="def-setup-assistant-drawer"
			title="<?php esc_attr_e( 'Setup Assistant', 'widrow-ai' ); ?>"
		>
			<span class="dashicons dashicons-admin-comments"></span>
			<span class="def-sa-trigger-label"><?php esc_html_e( 'Setup Assistant', 'widrow-ai' ); ?></span>
			<?php // The rail's label, shown only when the desktop column is collapsed. ?>
			<span class="def-sa-trigger-rail"><?php esc_html_e( 'Ask Sam', 'widrow-ai' ); ?></span>
		</button>
		<?php endif; ?>
	</h1>

	<!-- Toast container -->
	<div id="widrow-ai-toast-container" class="widrow-ai-toast-container" aria-live="polite"></div>

	<?php
	// Salt rotation leaves these plaintext options intact but makes the stored
	// secrets undecryptable — so the panel would show "Connected" while chat is
	// actually down. Treat the credential error as effectively disconnected, and
	// surface the Reconnect box below (it re-provisions both secrets, salt-safe).
	$has_encryption_error = (bool) get_transient( Widrow_AI_Encryption::TRANSIENT_ENCRYPTION_ERROR );
	$is_connected = ! empty( $conn_api_url ) && $conn_revision > 0;
	$status_class = ( $is_connected && ! $has_encryption_error ) ? 'connected' : 'disconnected';
	$status_label = $has_encryption_error
		? __( 'Credentials error — reconnect', 'widrow-ai' )
		: ( $is_connected
			? __( 'Connected', 'widrow-ai' )
			: __( 'Not Connected', 'widrow-ai' ) );
	?>

	<!-- Tab Navigation -->
	<nav class="widrow-ai-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings', 'widrow-ai' ); ?>">
		<?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
			<button
				type="button"
				role="tab"
				id="tab-<?php echo esc_attr( $tab_id ); ?>"
				class="widrow-ai-tab<?php echo ( 'connection' === $tab_id ) ? ' widrow-ai-tab-connection' : ''; ?>"
				aria-controls="panel-<?php echo esc_attr( $tab_id ); ?>"
				aria-selected="<?php echo ( $tab_id === $first_tab ) ? 'true' : 'false'; ?>"
				tabindex="<?php echo ( $tab_id === $first_tab ) ? '0' : '-1'; ?>"
			><?php
			if ( 'connection' === $tab_id ) {
				echo '<span class="widrow-ai-conn-dot-tab ' . esc_attr( $status_class ) . '"></span>';
			}
			echo esc_html( $tab_label );
			?></button>
		<?php endforeach; ?>
	</nav>

	<?php // ─── Branding Tab ──────────────────────────────────────────── ?>
	<div
		id="panel-branding"
		role="tabpanel"
		aria-labelledby="tab-branding"
		class="widrow-ai-panel"
		tabindex="0"
		hidden
	>
		<div class="widrow-ai-card">
			<h2><?php esc_html_e( 'Logo & Branding', 'widrow-ai' ); ?></h2>

			<div class="widrow-ai-field">
				<label><?php esc_html_e( 'Logo', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-logo-upload">
					<div id="widrow-ai-logo-preview" class="widrow-ai-logo-preview">
						<?php if ( $logo_url ) : ?>
							<img src="<?php echo esc_url( $logo_url ); ?>" style="max-height: 120px; width: auto;" />
						<?php else : ?>
							<span class="widrow-ai-no-logo"><?php esc_html_e( 'No logo selected', 'widrow-ai' ); ?></span>
						<?php endif; ?>
					</div>
					<input type="hidden" id="widrow_ai_logo_id" data-setting="widrow_ai_logo_id" value="<?php echo esc_attr( $branding['logo_id'] ); ?>" />
					<p class="widrow-ai-logo-actions">
						<button type="button" class="button" id="widrow-ai-select-logo">
							<?php esc_html_e( 'Select Logo', 'widrow-ai' ); ?>
						</button>
						<button type="button" class="button" id="widrow-ai-remove-logo" style="<?php echo $branding['logo_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove Logo', 'widrow-ai' ); ?>
						</button>
					</p>
					<p class="description">
						<?php esc_html_e( 'Upload a logo for your Digital Employees. Used in Staff AI and Customer Chat headers.', 'widrow-ai' ); ?>
					</p>
				</div>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_display_name"><?php esc_html_e( 'Display Name', 'widrow-ai' ); ?></label>
				<input
					type="text"
					id="widrow_ai_display_name"
					data-setting="widrow_ai_display_name"
					value="<?php echo esc_attr( $branding['display_name'] ); ?>"
					class="regular-text"
				/>
				<p class="description">
					<?php esc_html_e( 'Shown in chat headers when no logo is available. Defaults to your site name.', 'widrow-ai' ); ?>
				</p>
			</div>

			<h3><?php esc_html_e( 'Logo Visibility', 'widrow-ai' ); ?></h3>

			<div class="widrow-ai-field widrow-ai-checkbox-field">
				<label>
					<input
						type="checkbox"
						id="widrow_ai_logo_show_staff_ai"
						data-setting="widrow_ai_logo_show_staff_ai"
						value="1"
						<?php checked( $branding['logo_show_staff_ai'] ); ?>
					/>
					<?php esc_html_e( 'Show logo in Staff AI chat header', 'widrow-ai' ); ?>
				</label>
			</div>

			<div class="widrow-ai-field widrow-ai-checkbox-field">
				<label>
					<input
						type="checkbox"
						id="widrow_ai_logo_show_customer_chat"
						data-setting="widrow_ai_logo_show_customer_chat"
						value="1"
						<?php checked( $branding['logo_show_customer_chat'] ); ?>
					/>
					<?php esc_html_e( 'Show logo in Customer Chat header', 'widrow-ai' ); ?>
				</label>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_logo_max_height"><?php esc_html_e( 'Logo Max Height (px)', 'widrow-ai' ); ?></label>
				<input
					type="number"
					id="widrow_ai_logo_max_height"
					data-setting="widrow_ai_logo_max_height"
					value="<?php echo esc_attr( $branding['logo_max_height'] ); ?>"
					min="24"
					max="120"
					class="small-text"
				/>
				<p class="description">
					<?php esc_html_e( 'Maximum display height for the logo. Range: 24–120px.', 'widrow-ai' ); ?>
				</p>
			</div>
		</div>

		<div class="widrow-ai-section">
			<h3><?php esc_html_e( 'Customer Chat Welcome Banner', 'widrow-ai' ); ?></h3>
			<hr />

			<div class="widrow-ai-field">
				<label><?php esc_html_e( 'Desktop Banner (Wide)', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-image-upload" id="widrow-ai-hero-image-upload">
					<?php if ( $branding['hero_image_url'] ) : ?>
						<div class="widrow-ai-image-preview" id="widrow-ai-hero-image-preview">
							<img src="<?php echo esc_url( $branding['hero_image_url'] ); ?>" alt="" style="max-width: 480px; max-height: 120px; object-fit: cover; border-radius: 8px; display: block;" />
						</div>
					<?php else : ?>
						<div class="widrow-ai-image-preview" id="widrow-ai-hero-image-preview" style="display: none;">
							<img src="" alt="" style="max-width: 480px; max-height: 120px; object-fit: cover; border-radius: 8px; display: block;" />
						</div>
					<?php endif; ?>
					<input type="hidden" id="widrow_ai_chat_hero_image_id" data-setting="widrow_ai_chat_hero_image_id" value="<?php echo esc_attr( $branding['hero_image_id'] ); ?>" />
					<div class="widrow-ai-image-buttons">
						<button type="button" class="button" id="widrow-ai-select-hero-image">
							<?php esc_html_e( 'Select Desktop Banner', 'widrow-ai' ); ?>
						</button>
						<button type="button" class="button" id="widrow-ai-remove-hero-image" style="<?php echo $branding['hero_image_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove', 'widrow-ai' ); ?>
						</button>
					</div>
				</div>
				<p class="description">
					<?php esc_html_e( 'Wide-format banner shown above the greeting on desktop. Recommended aspect: ~5:1 (e.g. 2400×480px). Renders at panel width — up to 960px in Spotlight, ~450px in Modal, drawer-width in Drawer. A thin horizontal strip works best.', 'widrow-ai' ); ?>
				</p>
			</div>

			<div class="widrow-ai-field">
				<label><?php esc_html_e( 'Mobile Banner (Tall)', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-image-upload" id="widrow-ai-hero-image-mobile-upload">
					<?php if ( $branding['hero_image_mobile_url'] ) : ?>
						<div class="widrow-ai-image-preview" id="widrow-ai-hero-image-mobile-preview">
							<img src="<?php echo esc_url( $branding['hero_image_mobile_url'] ); ?>" alt="" style="max-width: 320px; max-height: 140px; object-fit: cover; border-radius: 8px; display: block;" />
						</div>
					<?php else : ?>
						<div class="widrow-ai-image-preview" id="widrow-ai-hero-image-mobile-preview" style="display: none;">
							<img src="" alt="" style="max-width: 320px; max-height: 140px; object-fit: cover; border-radius: 8px; display: block;" />
						</div>
					<?php endif; ?>
					<input type="hidden" id="widrow_ai_chat_hero_image_mobile_id" data-setting="widrow_ai_chat_hero_image_mobile_id" value="<?php echo esc_attr( $branding['hero_image_mobile_id'] ); ?>" />
					<div class="widrow-ai-image-buttons">
						<button type="button" class="button" id="widrow-ai-select-hero-image-mobile">
							<?php esc_html_e( 'Select Mobile Banner', 'widrow-ai' ); ?>
						</button>
						<button type="button" class="button" id="widrow-ai-remove-hero-image-mobile" style="<?php echo $branding['hero_image_mobile_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove', 'widrow-ai' ); ?>
						</button>
					</div>
				</div>
				<p class="description">
					<?php esc_html_e( 'Optional tall-format banner used on mobile (≤480px wide). Recommended aspect: ~2.7:1 (e.g. 860×320px). Mobile collapses the panel to ~full screen width, where the wide desktop banner becomes a thin sliver — this taller variant gives mobile visitors more visual impact. If left empty, the desktop banner is used at all viewports.', 'widrow-ai' ); ?>
				</p>
			</div>
		</div>

		<div class="widrow-ai-section">
			<h3><?php esc_html_e( 'Web App Icon', 'widrow-ai' ); ?></h3>
			<hr />

			<div class="widrow-ai-field">
				<label><?php esc_html_e( 'App Icon', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-image-upload" id="widrow-ai-app-icon-upload">
					<?php if ( $branding['app_icon_url'] ) : ?>
						<div class="widrow-ai-image-preview" id="widrow-ai-app-icon-preview">
							<img src="<?php echo esc_url( $branding['app_icon_url'] ); ?>" alt="" style="max-width: 128px; max-height: 128px; border-radius: 16px;" />
						</div>
					<?php else : ?>
						<div class="widrow-ai-image-preview" id="widrow-ai-app-icon-preview" style="display: none;">
							<img src="" alt="" style="max-width: 128px; max-height: 128px; border-radius: 16px;" />
						</div>
					<?php endif; ?>
					<input type="hidden" id="widrow_ai_app_icon_id" data-setting="widrow_ai_app_icon_id" value="<?php echo esc_attr( $branding['app_icon_id'] ); ?>" />
					<div class="widrow-ai-image-buttons">
						<button type="button" class="button" id="widrow-ai-select-app-icon">
							<?php esc_html_e( 'Select Icon', 'widrow-ai' ); ?>
						</button>
						<button type="button" class="button" id="widrow-ai-remove-app-icon" style="<?php echo $branding['app_icon_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove Icon', 'widrow-ai' ); ?>
						</button>
					</div>
				</div>
				<p class="description">
					<?php esc_html_e( 'The icon for the Staff AI app. Upload a square PNG at least 512×512px, opaque and full-bleed with no rounded corners — the phone rounds it for you. If not set, your logo is used, then your site icon, then the Widrow AI icon. After changing it, remove the app from your phone and add it again: phones cache the icon.', 'widrow-ai' ); ?>
				</p>
			</div>
		</div>

		<div class="widrow-ai-save-area">
			<button type="button" class="button button-primary widrow-ai-save-btn" data-tab="branding">
				<?php esc_html_e( 'Save Changes', 'widrow-ai' ); ?>
			</button>
			<span class="spinner"></span>
		</div>
	</div>

	<?php // ─── Chat Settings Tab ────────────────────────────────────────── ?>
	<div
		id="panel-chat-settings"
		role="tabpanel"
		aria-labelledby="tab-chat-settings"
		class="widrow-ai-panel"
		tabindex="0"
		hidden
	>
		<div class="widrow-ai-card">
			<h2><?php esc_html_e( 'Customer Chat Display', 'widrow-ai' ); ?></h2>

			<div class="widrow-ai-field">
				<label><?php esc_html_e( 'Display Mode', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-radio-group">
					<label class="widrow-ai-radio-label">
						<input
							type="radio"
							name="widrow_ai_chat_display_mode"
							data-setting="widrow_ai_chat_display_mode"
							value="modal"
							<?php checked( $chat_settings['display_mode'], 'modal' ); ?>
						/>
						<strong><?php esc_html_e( 'Modal', 'widrow-ai' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Compact panel anchored next to the trigger button (bottom corner).', 'widrow-ai' ); ?></span>
					</label>
					<label class="widrow-ai-radio-label">
						<input
							type="radio"
							name="widrow_ai_chat_display_mode"
							data-setting="widrow_ai_chat_display_mode"
							value="drawer"
							<?php checked( $chat_settings['display_mode'], 'drawer' ); ?>
						/>
						<strong><?php esc_html_e( 'Drawer', 'widrow-ai' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Full-height panel that slides in from the right edge of the screen.', 'widrow-ai' ); ?></span>
					</label>
					<label class="widrow-ai-radio-label">
						<input
							type="radio"
							name="widrow_ai_chat_display_mode"
							data-setting="widrow_ai_chat_display_mode"
							value="spotlight"
							<?php checked( $chat_settings['display_mode'], 'spotlight' ); ?>
						/>
						<strong><?php esc_html_e( 'Spotlight', 'widrow-ai' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Large overlay centered on the page, with a dimmed background. Best for full-attention experiences.', 'widrow-ai' ); ?></span>
					</label>
				</div>
			</div>

			<div id="widrow-ai-drawer-options" style="<?php echo 'drawer' === $chat_settings['display_mode'] ? '' : 'display: none;'; ?>">
				<div class="widrow-ai-field">
					<label for="widrow_ai_chat_drawer_width"><?php esc_html_e( 'Drawer Width (px)', 'widrow-ai' ); ?></label>
					<input
						type="number"
						id="widrow_ai_chat_drawer_width"
						data-setting="widrow_ai_chat_drawer_width"
						value="<?php echo esc_attr( $chat_settings['drawer_width'] ); ?>"
						min="300"
						max="600"
						class="small-text"
					/>
					<p class="description">
						<?php esc_html_e( 'Width of the chat drawer in pixels. Range: 300–600px.', 'widrow-ai' ); ?>
					</p>
				</div>
			</div>

			<div id="widrow-ai-spotlight-options" style="<?php echo 'spotlight' === $chat_settings['display_mode'] ? '' : 'display: none;'; ?>">
				<div class="widrow-ai-field">
					<label for="widrow_ai_chat_spotlight_width"><?php esc_html_e( 'Spotlight Width (px)', 'widrow-ai' ); ?></label>
					<input
						type="number"
						id="widrow_ai_chat_spotlight_width"
						data-setting="widrow_ai_chat_spotlight_width"
						value="<?php echo esc_attr( $chat_settings['spotlight_width'] ); ?>"
						min="600"
						max="1200"
						class="small-text"
					/>
					<p class="description">
						<?php esc_html_e( 'Width of the spotlight overlay in pixels. Range: 600–1200px. Auto-clamped to 92% of screen width on smaller displays.', 'widrow-ai' ); ?>
					</p>
				</div>
				<div class="widrow-ai-field">
					<label for="widrow_ai_chat_spotlight_height"><?php esc_html_e( 'Spotlight Height (px)', 'widrow-ai' ); ?></label>
					<input
						type="number"
						id="widrow_ai_chat_spotlight_height"
						data-setting="widrow_ai_chat_spotlight_height"
						value="<?php echo esc_attr( $chat_settings['spotlight_height'] ); ?>"
						min="500"
						max="800"
						class="small-text"
					/>
					<p class="description">
						<?php esc_html_e( 'Height of the spotlight overlay in pixels. Range: 500–800px. Auto-clamped to 85% of screen height on smaller displays. Goes full-screen on mobile.', 'widrow-ai' ); ?>
					</p>
				</div>
			</div>
		</div>

		<div class="widrow-ai-card">
			<h2><?php esc_html_e( 'Chat Button Appearance', 'widrow-ai' ); ?></h2>

			<div class="widrow-ai-field">
				<label><?php esc_html_e( 'Button Position', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-radio-group">
					<label class="widrow-ai-radio-label">
						<input
							type="radio"
							name="widrow_ai_chat_button_position"
							data-setting="widrow_ai_chat_button_position"
							value="right"
							<?php checked( $button_settings['position'], 'right' ); ?>
						/>
						<strong><?php esc_html_e( 'Right', 'widrow-ai' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Bottom-right corner of the page.', 'widrow-ai' ); ?></span>
					</label>
					<label class="widrow-ai-radio-label">
						<input
							type="radio"
							name="widrow_ai_chat_button_position"
							data-setting="widrow_ai_chat_button_position"
							value="left"
							<?php checked( $button_settings['position'], 'left' ); ?>
						/>
						<strong><?php esc_html_e( 'Left', 'widrow-ai' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Bottom-left corner of the page.', 'widrow-ai' ); ?></span>
					</label>
				</div>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_button_color"><?php esc_html_e( 'Button Color', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-color-field">
					<input
						type="color"
						id="widrow_ai_chat_button_color"
						data-setting="widrow_ai_chat_button_color"
						value="<?php echo esc_attr( $button_settings['color'] ); ?>"
					/>
					<span class="widrow-ai-color-value"><?php echo esc_html( $button_settings['color'] ); ?></span>
				</div>
				<p class="description">
					<?php esc_html_e( 'Brand color for the widget: the floating chat button, the [def_chat_button] shortcode, and the chat panel accents (send button, focus states).', 'widrow-ai' ); ?>
				</p>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_button_hover_color"><?php esc_html_e( 'Button Hover Color', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-color-field">
					<input
						type="color"
						id="widrow_ai_chat_button_hover_color"
						data-setting="widrow_ai_chat_button_hover_color"
						value="<?php echo esc_attr( $button_settings['hover_color'] ? $button_settings['hover_color'] : $button_settings['color'] ); ?>"
					/>
					<span class="widrow-ai-color-value"><?php echo esc_html( $button_settings['hover_color'] ? $button_settings['hover_color'] : $button_settings['color'] ); ?></span>
				</div>
				<p class="description">
					<?php esc_html_e( 'Background color when hovering over the chat button. Defaults to the button color if not set.', 'widrow-ai' ); ?>
				</p>
			</div>

			<div class="widrow-ai-field">
				<label><?php esc_html_e( 'Button Icon', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-radio-group widrow-ai-icon-radios">
					<label class="widrow-ai-radio-label">
						<input
							type="radio"
							name="widrow_ai_chat_button_icon"
							data-setting="widrow_ai_chat_button_icon"
							value="chat"
							<?php checked( $button_settings['icon'], 'chat' ); ?>
						/>
						<span class="widrow-ai-icon-preview">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
						</span>
						<strong><?php esc_html_e( 'Chat bubble', 'widrow-ai' ); ?></strong>
					</label>
					<label class="widrow-ai-radio-label">
						<input
							type="radio"
							name="widrow_ai_chat_button_icon"
							data-setting="widrow_ai_chat_button_icon"
							value="headset"
							<?php checked( $button_settings['icon'], 'headset' ); ?>
						/>
						<span class="widrow-ai-icon-preview">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
						</span>
						<strong><?php esc_html_e( 'Headset', 'widrow-ai' ); ?></strong>
					</label>
					<label class="widrow-ai-radio-label">
						<input
							type="radio"
							name="widrow_ai_chat_button_icon"
							data-setting="widrow_ai_chat_button_icon"
							value="sparkle"
							<?php checked( $button_settings['icon'], 'sparkle' ); ?>
						/>
						<span class="widrow-ai-icon-preview">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C12.7 6.3 13.2 8.2 15 10C16.8 11.8 18.7 12.3 23 13C18.7 13.7 16.8 14.2 15 16C13.2 17.8 12.7 19.7 12 24C11.3 19.7 10.8 17.8 9 16C7.2 14.2 5.3 13.7 1 13C5.3 12.3 7.2 11.8 9 10C10.8 8.2 11.3 6.3 12 2Z"/><path d="M20 1C20.3 2.6 20.5 3.2 21 3.7C21.5 3.2 21.7 2.6 22 1C21.7 2.6 21.5 3.2 21 3.7C20.5 3.2 20.3 2.6 20 1Z"/><path d="M3 19C3.2 20 3.4 20.4 3.7 20.7C4 20.4 4.2 20 4.4 19C4.2 20 4 20.4 3.7 20.7C3.4 20.4 3.2 20 3 19Z"/></svg>
						</span>
						<strong><?php esc_html_e( 'AI sparkle', 'widrow-ai' ); ?></strong>
					</label>
					<label class="widrow-ai-radio-label">
						<input
							type="radio"
							name="widrow_ai_chat_button_icon"
							data-setting="widrow_ai_chat_button_icon"
							value="custom"
							<?php checked( $button_settings['icon'], 'custom' ); ?>
						/>
						<strong><?php esc_html_e( 'Custom', 'widrow-ai' ); ?></strong>
					</label>
				</div>
			</div>

			<div id="widrow-ai-custom-icon-upload" class="widrow-ai-field" style="<?php echo 'custom' === $button_settings['icon'] ? '' : 'display: none;'; ?>">
				<label><?php esc_html_e( 'Custom Icon', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-logo-upload">
					<div id="widrow-ai-icon-preview" class="widrow-ai-logo-preview">
						<?php if ( $button_icon_url ) : ?>
							<img src="<?php echo esc_url( $button_icon_url ); ?>" style="max-height: 48px; width: auto;" />
						<?php else : ?>
							<span class="widrow-ai-no-logo"><?php esc_html_e( 'No icon selected', 'widrow-ai' ); ?></span>
						<?php endif; ?>
					</div>
					<input type="hidden" id="widrow_ai_chat_button_icon_id" data-setting="widrow_ai_chat_button_icon_id" value="<?php echo esc_attr( $button_settings['icon_id'] ); ?>" />
					<p class="widrow-ai-logo-actions">
						<button type="button" class="button" id="widrow-ai-select-icon">
							<?php esc_html_e( 'Select Icon', 'widrow-ai' ); ?>
						</button>
						<button type="button" class="button" id="widrow-ai-remove-icon" style="<?php echo $button_settings['icon_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove Icon', 'widrow-ai' ); ?>
						</button>
					</p>
					<p class="description">
						<?php esc_html_e( 'Upload a custom icon for the chat button. Recommended: 48×48px PNG or SVG.', 'widrow-ai' ); ?>
					</p>
				</div>
			</div>

			<div class="widrow-ai-field">
				<label><?php esc_html_e( 'Loading Mark', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-logo-upload">
					<div id="widrow-ai-loading-mark-preview" class="widrow-ai-logo-preview">
						<?php if ( $loading_mark_url ) : ?>
							<img src="<?php echo esc_url( $loading_mark_url ); ?>" style="max-height: 48px; width: auto;" />
						<?php else : ?>
							<span class="widrow-ai-no-logo"><?php esc_html_e( 'Default spinner', 'widrow-ai' ); ?></span>
						<?php endif; ?>
					</div>
					<input type="hidden" id="widrow_ai_chat_loading_mark_id" data-setting="widrow_ai_chat_loading_mark_id" value="<?php echo esc_attr( $button_settings['loading_mark_id'] ); ?>" />
					<p class="widrow-ai-logo-actions">
						<button type="button" class="button" id="widrow-ai-select-loading-mark">
							<?php esc_html_e( 'Select Mark', 'widrow-ai' ); ?>
						</button>
						<button type="button" class="button" id="widrow-ai-remove-loading-mark" style="<?php echo $button_settings['loading_mark_id'] ? '' : 'display: none;'; ?>">
							<?php esc_html_e( 'Remove Mark', 'widrow-ai' ); ?>
						</button>
					</p>
					<p class="description">
						<?php esc_html_e( 'Shown while the chat loads, in place of the default spinner — e.g. an animated brand mark. Recommended: square SVG, PNG or GIF.', 'widrow-ai' ); ?>
					</p>
				</div>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_button_label"><?php esc_html_e( 'Button Label', 'widrow-ai' ); ?></label>
				<input
					type="text"
					id="widrow_ai_chat_button_label"
					data-setting="widrow_ai_chat_button_label"
					value="<?php echo esc_attr( $button_settings['label'] ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'Chat', 'widrow-ai' ); ?>"
				/>
				<p class="description">
					<?php esc_html_e( 'Text label shown on the chat button — e.g. "Chat", "Ask Joe", "AI Assist", "Get Help". Applies to both the floating button and any [def_chat_button] shortcode placement. Leave empty to use the default "Chat".', 'widrow-ai' ); ?>
				</p>
			</div>

			<div class="widrow-ai-field widrow-ai-checkbox-field">
				<label>
					<input
						type="checkbox"
						id="widrow_ai_chat_show_floating"
						data-setting="widrow_ai_chat_show_floating"
						value="1"
						<?php checked( $button_settings['show_floating'] ); ?>
					/>
					<?php esc_html_e( 'Show floating chat button on frontend', 'widrow-ai' ); ?>
				</label>
			</div>

			<div id="widrow-ai-floating-warning" class="widrow-ai-notice widrow-ai-notice-warning" style="<?php echo $button_settings['show_floating'] ? 'display: none;' : ''; ?>">
				<p>
					<?php esc_html_e( 'The floating chat button is hidden. Make sure you\'ve placed the [def_chat_button] shortcode or theme hook, otherwise visitors won\'t be able to open the chat.', 'widrow-ai' ); ?>
				</p>
			</div>
		</div>

		<div class="widrow-ai-card">
			<h2><?php esc_html_e( 'Greeting Bubble', 'widrow-ai' ); ?></h2>

			<div class="widrow-ai-field widrow-ai-checkbox-field">
				<label>
					<input
						type="checkbox"
						id="widrow_ai_chat_greeting_bubble_enabled"
						data-setting="widrow_ai_chat_greeting_bubble_enabled"
						value="1"
						<?php checked( $button_settings['greeting_bubble_enabled'] ); ?>
					/>
					<?php esc_html_e( 'Show greeting bubble above the chat button', 'widrow-ai' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'A proactive pop-up appears above the chat button about 5 seconds after the page loads, inviting visitors to chat. Clicking the bubble opens the chat; the × button dismisses it. Dismissals are remembered for 24 hours.', 'widrow-ai' ); ?>
				</p>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_greeting_bubble_text"><?php esc_html_e( 'Greeting Text', 'widrow-ai' ); ?></label>
				<textarea
					id="widrow_ai_chat_greeting_bubble_text"
					data-setting="widrow_ai_chat_greeting_bubble_text"
					rows="3"
					class="large-text"
				><?php echo esc_textarea( $button_settings['greeting_bubble_text'] ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Text shown inside the greeting bubble. Line breaks are preserved. Leave blank to hide the bubble.', 'widrow-ai' ); ?>
				</p>
			</div>
		</div>

		<div class="widrow-ai-card">
			<h2><?php esc_html_e( 'Welcome State', 'widrow-ai' ); ?></h2>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_opening_message"><?php esc_html_e( 'Opening Message', 'widrow-ai' ); ?></label>
				<textarea
					id="widrow_ai_chat_opening_message"
					data-setting="widrow_ai_chat_opening_message"
					rows="4"
					class="large-text"
				><?php echo esc_textarea( $button_settings['opening_message'] ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'The first message visitors see when the chat opens, in your assistant\'s words. Line breaks are preserved. Leave blank for the built-in message.', 'widrow-ai' ); ?>
				</p>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_welcome_chip_1"><?php esc_html_e( 'Suggestion Chip 1', 'widrow-ai' ); ?></label>
				<input
					type="text"
					id="widrow_ai_chat_welcome_chip_1"
					data-setting="widrow_ai_chat_welcome_chip_1"
					value="<?php echo esc_attr( $chat_settings['welcome_chip_1'] ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g. Help me find the right product', 'widrow-ai' ); ?>"
				/>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_welcome_chip_1_intro"><?php esc_html_e( 'Chip 1 — Introduction (optional)', 'widrow-ai' ); ?></label>
				<textarea
					id="widrow_ai_chat_welcome_chip_1_intro"
					data-setting="widrow_ai_chat_welcome_chip_1_intro"
					class="large-text"
					rows="4"
					placeholder="<?php esc_attr_e( "e.g. Hi there! I'd love to help you find the right product. To narrow it down, could you tell me what you're shopping for and roughly the size or budget you're looking at?", 'widrow-ai' ); ?>"
				><?php echo esc_textarea( $chat_settings['welcome_chip_1_intro'] ); ?></textarea>
				<p class="description">
					<?php
					/* translators: %s: clickable "Ask your Setup Assistant" link that opens the Setup Assistant pre-seeded with field context. */
					printf(
						esc_html__( 'Need help? %s to draft this introduction.', 'widrow-ai' ),
						sprintf(
							'<button type="button" class="button-link def-sa-field-help" data-sa-field="%s" data-sa-field-label="%s">%s</button>',
							'chat_welcome_chip_1_intro',
							esc_attr__( 'Chip 1 — Introduction', 'widrow-ai' ),
							esc_html__( 'Ask your Setup Assistant', 'widrow-ai' )
						)
					);
					?>
				</p>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_welcome_chip_2"><?php esc_html_e( 'Suggestion Chip 2', 'widrow-ai' ); ?></label>
				<input
					type="text"
					id="widrow_ai_chat_welcome_chip_2"
					data-setting="widrow_ai_chat_welcome_chip_2"
					value="<?php echo esc_attr( $chat_settings['welcome_chip_2'] ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g. Check stock availability', 'widrow-ai' ); ?>"
				/>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_welcome_chip_2_intro"><?php esc_html_e( 'Chip 2 — Introduction (optional)', 'widrow-ai' ); ?></label>
				<textarea
					id="widrow_ai_chat_welcome_chip_2_intro"
					data-setting="widrow_ai_chat_welcome_chip_2_intro"
					class="large-text"
					rows="4"
					placeholder="<?php esc_attr_e( 'e.g. Happy to check stock for you. Which product or category are you interested in, and which store or postcode should I check?', 'widrow-ai' ); ?>"
				><?php echo esc_textarea( $chat_settings['welcome_chip_2_intro'] ); ?></textarea>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_welcome_chip_3"><?php esc_html_e( 'Suggestion Chip 3', 'widrow-ai' ); ?></label>
				<input
					type="text"
					id="widrow_ai_chat_welcome_chip_3"
					data-setting="widrow_ai_chat_welcome_chip_3"
					value="<?php echo esc_attr( $chat_settings['welcome_chip_3'] ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g. Get expert advice or inspiration', 'widrow-ai' ); ?>"
				/>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_welcome_chip_3_intro"><?php esc_html_e( 'Chip 3 — Introduction (optional)', 'widrow-ai' ); ?></label>
				<textarea
					id="widrow_ai_chat_welcome_chip_3_intro"
					data-setting="widrow_ai_chat_welcome_chip_3_intro"
					class="large-text"
					rows="4"
					placeholder="<?php esc_attr_e( "e.g. I'd love to help you get started on a project or find some inspiration. To give you the best advice, could you let me know what you're thinking of working on?", 'widrow-ai' ); ?>"
				><?php echo esc_textarea( $chat_settings['welcome_chip_3_intro'] ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Tappable chips below the greeting. Empty chips are hidden. Each chip has an optional Introduction — when set, clicking the chip shows that intro as an assistant message and waits for the visitor to reply, instead of pre-filling the composer.', 'widrow-ai' ); ?>
				</p>
			</div>
		</div>

		<div class="widrow-ai-card">
			<h2><?php esc_html_e( 'AI Disclosure Notice', 'widrow-ai' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Show a notice in the Chat footer advising that responses are AI-generated. Recommended for transparency and required by some jurisdictions.', 'widrow-ai' ); ?>
			</p>

			<div class="widrow-ai-field widrow-ai-checkbox-field">
				<label>
					<input
						type="checkbox"
						id="widrow_ai_chat_ai_notice"
						data-setting="widrow_ai_chat_ai_notice"
						value="1"
						<?php checked( $chat_settings['ai_notice'] ); ?>
					/>
					<?php esc_html_e( 'Show AI disclosure notice', 'widrow-ai' ); ?>
				</label>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_compliance_text"><?php esc_html_e( 'AI Disclosure Notice', 'widrow-ai' ); ?></label>
				<?php // No maxlength: this is the site owner's own legal / AI-disclosure
				// notice and its length is their call (2026-08-08). A maxlength here
				// would silently swallow pasted overflow, which is the same silent
				// drop the server-side cap was removed for. ?>
				<textarea
					id="widrow_ai_chat_compliance_text"
					data-setting="widrow_ai_chat_compliance_text"
					class="regular-text"
					rows="4"
					placeholder="<?php esc_attr_e( 'AI responses may be inaccurate. By using this assistant, you agree to our', 'widrow-ai' ); ?>"
				><?php echo esc_textarea( $chat_settings['compliance_text'] ); ?></textarea>
				<p class="description">
					<?php
					/* translators: %s: clickable "Ask your Setup Assistant" link that opens the Setup Assistant pre-seeded with field context. */
					printf(
						esc_html__( 'Need help? %s.', 'widrow-ai' ),
						sprintf(
							'<button type="button" class="button-link def-sa-field-help" data-sa-field="%s" data-sa-field-label="%s">%s</button>',
							'chat_compliance_text',
							esc_attr__( 'AI Disclosure Notice', 'widrow-ai' ),
							esc_html__( 'Ask your Setup Assistant', 'widrow-ai' )
						)
					);
					?>
				</p>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_privacy_link_label"><?php esc_html_e( 'Legal Link Label', 'widrow-ai' ); ?></label>
				<input
					type="text"
					id="widrow_ai_chat_privacy_link_label"
					data-setting="widrow_ai_chat_privacy_link_label"
					value="<?php echo esc_attr( $chat_settings['privacy_link_label'] ); ?>"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'Terms & Conditions', 'widrow-ai' ); ?>"
				/>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_privacy_url"><?php esc_html_e( 'Legal Link URL', 'widrow-ai' ); ?></label>
				<input
					type="url"
					id="widrow_ai_chat_privacy_url"
					data-setting="widrow_ai_chat_privacy_url"
					value="<?php echo esc_url( $chat_settings['privacy_url'] ); ?>"
					class="regular-text"
					placeholder="https://example.com/terms-and-conditions"
				/>
			</div>

			<div id="widrow-ai-ai-notice-preview" class="widrow-ai-notice widrow-ai-notice-info">
				<p>
					<strong><?php esc_html_e( 'Preview:', 'widrow-ai' ); ?></strong>
					<?php echo esc_html( $chat_settings['compliance_text'] ); ?>
					<?php if ( $chat_settings['privacy_url'] ) : ?>
						<a href="<?php echo esc_url( $chat_settings['privacy_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $chat_settings['privacy_link_label'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $chat_settings['privacy_link_label'] ); ?>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<div class="widrow-ai-save-area">
			<button type="button" class="button button-primary widrow-ai-save-btn" data-tab="chat-settings">
				<?php esc_html_e( 'Save Changes', 'widrow-ai' ); ?>
			</button>
			<span class="spinner"></span>
		</div>
	</div>

	<?php // ─── Escalation Tab ───────────────────────────────────────────── ?>
	<div
		id="panel-escalation"
		role="tabpanel"
		aria-labelledby="tab-escalation"
		class="widrow-ai-panel"
		tabindex="0"
		hidden
	>
		<div class="widrow-ai-card">
			<h2><?php esc_html_e( 'Where Contact Requests Are Sent', 'widrow-ai' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'When a visitor asks to talk to a person, their request is emailed to the address set for each channel. If no email is set, the WordPress admin email is used.', 'widrow-ai' ); ?>
			</p>

			<?php
			$channels_info = array(
				'customer'        => array(
					'label' => __( 'Customer Chat', 'widrow-ai' ),
					'help'  => __( 'Receives contact requests from Customer Chat, from visitors and signed-in customers.', 'widrow-ai' ),
				),
				'setup_assistant' => array(
					'label' => __( 'Setup Assistant', 'widrow-ai' ),
					'help'  => __( "Enter your DEF Partner's email address here to receive Setup Assistant contact requests.", 'widrow-ai' ),
				),
			);
			?>

			<?php foreach ( $channels_info as $channel_id => $channel_info ) : ?>
				<div class="widrow-ai-field">
					<label for="escalation_<?php echo esc_attr( $channel_id ); ?>">
						<?php echo esc_html( $channel_info['label'] ); ?>
					</label>
					<div class="widrow-ai-escalation-row">
						<input
							type="email"
							id="escalation_<?php echo esc_attr( $channel_id ); ?>"
							data-setting="escalation_<?php echo esc_attr( $channel_id ); ?>"
							value="<?php echo esc_attr( $escalation[ $channel_id ] ); ?>"
							class="regular-text"
							placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
						/>
						<button
							type="button"
							class="button button-small widrow-ai-test-email-btn"
							data-channel="<?php echo esc_attr( $channel_id ); ?>"
						><?php esc_html_e( 'Test Email', 'widrow-ai' ); ?></button>
					</div>
					<p class="description"><?php echo esc_html( $channel_info['help'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="widrow-ai-card">
			<h2><?php esc_html_e( 'Customer Chat Contact Form', 'widrow-ai' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The form shown when a visitor asks to be connected with a person. On a support site the defaults fit; on a sales site you can reframe it as a next-step / contact form.', 'widrow-ai' ); ?>
			</p>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_escalation_title"><?php esc_html_e( 'Form title', 'widrow-ai' ); ?></label>
				<input type="text" id="widrow_ai_chat_escalation_title" data-setting="widrow_ai_chat_escalation_title" value="<?php echo esc_attr( $escalation_form['title'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Talk to a person', 'widrow-ai' ); ?>" />
				<p class="description"><?php esc_html_e( 'Leave empty for the default.', 'widrow-ai' ); ?></p>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_escalation_desc"><?php esc_html_e( 'Form description', 'widrow-ai' ); ?></label>
				<input type="text" id="widrow_ai_chat_escalation_desc" data-setting="widrow_ai_chat_escalation_desc" value="<?php echo esc_attr( $escalation_form['desc'] ); ?>" class="large-text" placeholder="<?php esc_attr_e( "Describe your issue below and we'll connect you with a human.", 'widrow-ai' ); ?>" />
				<p class="description"><?php esc_html_e( 'Leave empty for the default.', 'widrow-ai' ); ?></p>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_escalation_business_fields"><?php esc_html_e( 'Business name and website', 'widrow-ai' ); ?></label>
				<select id="widrow_ai_chat_escalation_business_fields" data-setting="widrow_ai_chat_escalation_business_fields">
					<option value="hidden" <?php selected( $escalation_form['business_fields'], 'hidden' ); ?>><?php esc_html_e( 'Hidden', 'widrow-ai' ); ?></option>
					<option value="optional" <?php selected( $escalation_form['business_fields'], 'optional' ); ?>><?php esc_html_e( 'Optional', 'widrow-ai' ); ?></option>
					<option value="required" <?php selected( $escalation_form['business_fields'], 'required' ); ?>><?php esc_html_e( 'Required', 'widrow-ai' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'For visitors who are not signed in. Choose Required on a site that sells to businesses and uses these details.', 'widrow-ai' ); ?></p>
			</div>

			<div class="widrow-ai-field">
				<label>
					<input type="checkbox" id="widrow_ai_chat_escalation_pref_enabled" data-setting="widrow_ai_chat_escalation_pref_enabled" value="1" <?php checked( $escalation_form['pref_enabled'] ); ?> />
					<?php esc_html_e( 'Ask how the visitor would like to be contacted', 'widrow-ai' ); ?>
				</label>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_escalation_pref_options"><?php esc_html_e( 'Contact options (one per line, in order)', 'widrow-ai' ); ?></label>
				<textarea id="widrow_ai_chat_escalation_pref_options" data-setting="widrow_ai_chat_escalation_pref_options" rows="3" class="regular-text" placeholder="<?php esc_attr_e( "Phone call\nTeams meeting\nEmail", 'widrow-ai' ); ?>"><?php echo esc_textarea( $escalation_form['pref_options'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'The first option is preselected. The select only renders when enabled and at least one option is set.', 'widrow-ai' ); ?></p>
			</div>

			<div class="widrow-ai-field">
				<label>
					<input type="checkbox" id="widrow_ai_chat_escalation_ack_enabled" data-setting="widrow_ai_chat_escalation_ack_enabled" value="1" <?php checked( $escalation_form['ack_enabled'] ); ?> />
					<?php esc_html_e( 'Send an automatic acknowledgement to the visitor', 'widrow-ai' ); ?>
				</label>
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_escalation_ack_subject"><?php esc_html_e( 'Acknowledgement subject', 'widrow-ai' ); ?></label>
				<input type="text" id="widrow_ai_chat_escalation_ack_subject" data-setting="widrow_ai_chat_escalation_ack_subject" value="<?php echo esc_attr( $escalation_form['ack_subject'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Thanks for reaching out', 'widrow-ai' ); ?>" />
			</div>

			<div class="widrow-ai-field">
				<label for="widrow_ai_chat_escalation_ack_body"><?php esc_html_e( 'Acknowledgement message', 'widrow-ai' ); ?></label>
				<textarea id="widrow_ai_chat_escalation_ack_body" data-setting="widrow_ai_chat_escalation_ack_body" rows="3" class="large-text" placeholder="<?php esc_attr_e( "Thanks for reaching out — we've received your message and a real person will be in touch soon.", 'widrow-ai' ); ?>"><?php echo esc_textarea( $escalation_form['ack_body'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Plain text, sent from the site\'s no-reply address. {first-name} adds the visitor\'s first name. Nothing else the visitor typed is included.', 'widrow-ai' ); ?></p>
			</div>
		</div>

		<div class="widrow-ai-save-area">
			<button type="button" class="button button-primary widrow-ai-save-btn" data-tab="escalation">
				<?php esc_html_e( 'Save Changes', 'widrow-ai' ); ?>
			</button>
			<span class="spinner"></span>
		</div>
	</div>

	<?php // ─── User Roles Tab ───────────────────────────────────────────── ?>
	<div
		id="panel-user-roles"
		role="tabpanel"
		aria-labelledby="tab-user-roles"
		class="widrow-ai-panel"
		tabindex="0"
		hidden
	>
		<div class="widrow-ai-card">
			<h2><?php esc_html_e( 'User Access', 'widrow-ai' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Staff and Management both open the Staff AI console, at different document authority: a Management user can read documents a Staff user cannot. Roles give a person the vaults and tools that role holds. DEF Admin opens this settings page.', 'widrow-ai' ); ?>
			</p>

			<?php // S3: narrow the list by vault role. Presentation only — filtering changes nothing a save writes. ?>
			<div class="widrow-ai-access-toolbar">
				<label for="widrow-ai-role-filter"><?php esc_html_e( 'Show people with role', 'widrow-ai' ); ?></label>
				<select id="widrow-ai-role-filter" class="widrow-ai-role-filter">
					<option value=""><?php esc_html_e( 'All roles', 'widrow-ai' ); ?></option>
					<?php foreach ( $roles_catalog as $def_role ) : ?>
						<option value="<?php echo esc_attr( $def_role['slug'] ); ?>"><?php echo esc_html( $def_role['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<span id="widrow-ai-access-count" class="widrow-ai-access-count" aria-live="polite"></span>
			</div>

			<?php
			// S3: one row per person. The vault roles are chips in the Roles cell, never one
			// column per role — the old grid was eleven columns wide at a3rev's five roles,
			// and unreadable past eight. Every capability the save writes is still carried by
			// a .widrow-ai-role-cb input (the role ones hidden, driven by the chips), so the
			// payload this screen submits is the payload the checkbox grid submitted.
			?>
			<table class="widrow-ai-roles-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'widrow-ai' ); ?></th>
						<th><?php esc_html_e( 'WordPress role', 'widrow-ai' ); ?></th>
						<th><?php esc_html_e( 'Access level', 'widrow-ai' ); ?></th>
						<th><?php esc_html_e( 'Roles', 'widrow-ai' ); ?></th>
						<th><?php esc_html_e( 'DEF Admin', 'widrow-ai' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'widrow-ai' ); ?></th>
					</tr>
				</thead>
				<tbody id="widrow-ai-roles-tbody">
					<?php
					foreach ( $def_users as $u ) :
						$is_last_admin = $u->has_cap( 'widrow_admin_access' ) && $widrow_admin_count <= 1;
						$is_locked     = $is_last_admin;
						// map_def_capabilities() is filtered onto map_meta_cap, so
						// has_cap('widrow_staff_access') answers TRUE for every Management and
						// DEF-Admin user and cannot be asked what is STORED. allcaps is the raw
						// merged capability array, which map_meta_cap never touches — the same
						// stored reading Widrow_AI_Staff_Roster::build_roster() takes.
						//
						// Management wins if both are somehow stored. Neither stored stays
						// NEITHER: a DEF Admin who never uses the console is a supported setup
						// ("DEF-Admin alone is NOT a roster row"), and defaulting those rows to
						// Staff here would grant every one of them a console login the moment
						// anyone pressed Save — for someone else entirely.
						$level = '';
						if ( ! empty( $u->allcaps['widrow_management_access'] ) ) {
							$level = 'management';
						} elseif ( ! empty( $u->allcaps['widrow_staff_access'] ) ) {
							$level = 'staff';
						}
						$held  = array();
						foreach ( $roles_catalog as $def_role ) {
							if ( $u->has_cap( 'widrow_role_' . $def_role['slug'] ) ) {
								$held[] = $def_role['slug'];
							}
						}
						?>
						<tr class="widrow-ai-user-row" data-user-id="<?php echo esc_attr( $u->ID ); ?>" data-roles="<?php echo esc_attr( implode( ' ', $held ) ); ?>">
							<td class="widrow-ai-cell-user" data-label="<?php esc_attr_e( 'User', 'widrow-ai' ); ?>">
								<?php
								echo get_avatar( $u->ID, 24, '', '', array( 'class' => 'widrow-ai-user-avatar' ) );
								$first = get_user_meta( $u->ID, 'first_name', true );
								$last  = get_user_meta( $u->ID, 'last_name', true );
								$full  = trim( $first . ' ' . $last );
								if ( $full ) {
									echo '<strong>' . esc_html( $full ) . '</strong> ';
									echo '<span class="widrow-ai-user-login">(' . esc_html( $u->user_login ) . ')</span>';
								} else {
									echo '<strong>' . esc_html( $u->display_name ) . '</strong>';
								}
								?>
								<span class="widrow-ai-user-email"><?php echo esc_html( $u->user_email ); ?></span>
							</td>
							<td class="widrow-ai-cell-wp" data-label="<?php esc_attr_e( 'WordPress role', 'widrow-ai' ); ?>"><?php echo esc_html( implode( ', ', array_map( 'ucfirst', $u->roles ) ) ); ?></td>
							<td class="widrow-ai-cell-access" data-label="<?php esc_attr_e( 'Access level', 'widrow-ai' ); ?>">
								<input type="checkbox" class="widrow-ai-role-cb" data-user="<?php echo esc_attr( $u->ID ); ?>" data-cap="widrow_staff_access" <?php checked( 'staff' === $level ); ?> hidden />
								<input type="checkbox" class="widrow-ai-role-cb" data-user="<?php echo esc_attr( $u->ID ); ?>" data-cap="widrow_management_access" <?php checked( 'management' === $level ); ?> hidden />
							</td>
							<td class="widrow-ai-cell-roles" data-label="<?php esc_attr_e( 'Roles', 'widrow-ai' ); ?>">
								<?php foreach ( $roles_catalog as $def_role ) : ?>
									<input type="checkbox" class="widrow-ai-role-cb" data-user="<?php echo esc_attr( $u->ID ); ?>" data-cap="<?php echo esc_attr( 'widrow_role_' . $def_role['slug'] ); ?>" <?php checked( $u->has_cap( 'widrow_role_' . $def_role['slug'] ) ); ?> hidden />
								<?php endforeach; ?>
							</td>
							<td class="widrow-ai-cell-admin" data-label="<?php esc_attr_e( 'DEF Admin', 'widrow-ai' ); ?>">
								<input
									type="checkbox"
									class="widrow-ai-role-cb widrow-ai-admin-cb"
									data-user="<?php echo esc_attr( $u->ID ); ?>"
									data-cap="widrow_admin_access"
									aria-label="<?php esc_attr_e( 'DEF Admin', 'widrow-ai' ); ?>"
									<?php checked( $u->has_cap( 'widrow_admin_access' ) ); ?>
									<?php disabled( $is_locked ); ?>
								/>
								<?php if ( $is_locked ) : ?>
									<span class="widrow-ai-locked-label" title="<?php esc_attr_e( 'At least one DEF Admin is required', 'widrow-ai' ); ?>"><?php esc_html_e( 'locked', 'widrow-ai' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="widrow-ai-cell-actions<?php echo $is_locked ? ' is-empty' : ''; ?>" data-label="<?php esc_attr_e( 'Actions', 'widrow-ai' ); ?>">
								<?php if ( ! $is_locked ) : ?>
									<button type="button" class="widrow-ai-remove-user-btn" data-user-id="<?php echo esc_attr( $u->ID ); ?>" title="<?php esc_attr_e( 'Remove all DEF access', 'widrow-ai' ); ?>">&times;</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Add User search -->
			<div class="widrow-ai-add-user-section">
				<label for="widrow-ai-user-search"><?php esc_html_e( 'Add User', 'widrow-ai' ); ?></label>
				<div class="widrow-ai-user-search-wrap">
					<input
						type="text"
						id="widrow-ai-user-search"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Search by email or name...', 'widrow-ai' ); ?>"
						autocomplete="off"
					/>
					<div id="widrow-ai-user-search-results" class="widrow-ai-search-results" hidden></div>
				</div>
			</div>
		</div>

		<div class="widrow-ai-save-area">
			<button type="button" class="button button-primary widrow-ai-save-roles-btn">
				<?php esc_html_e( 'Save User Roles', 'widrow-ai' ); ?>
			</button>
			<span class="spinner"></span>
		</div>
	</div>

	<?php // ─── Connection Tab ─────────────────────────────────────────── ?>
	<div
		id="panel-connection"
		role="tabpanel"
		aria-labelledby="tab-connection"
		class="widrow-ai-panel"
		tabindex="0"
		hidden
	>
		<div class="widrow-ai-card">
			<h2><?php esc_html_e( 'Connection Status', 'widrow-ai' ); ?></h2>

			<div class="widrow-ai-conn-status-panel <?php echo esc_attr( $status_class ); ?>">
				<div class="widrow-ai-conn-status-row">
					<span class="widrow-ai-conn-dot"></span>
					<span class="widrow-ai-conn-label"><?php echo esc_html( $status_label ); ?></span>
					<?php // Same condition as $status_class above: on a salt-rotated site the label is "Credentials error — reconnect", which a bare "since" would attach itself to. ?>
					<?php if ( $is_connected && ! $has_encryption_error && ! empty( $conn_last_sync ) ) : ?>
						<span class="widrow-ai-conn-sync">
							<?php
							printf(
								/* translators: %s: date this site was connected, in the site's date format */
								esc_html__( 'since %s', 'widrow-ai' ),
								esc_html( date_i18n( get_option( 'date_format' ), strtotime( $conn_last_sync ) ) )
							);
							?>
						</span>
					<?php endif; ?>
				</div>

				<div class="widrow-ai-conn-actions">
					<?php if ( $is_connected ) : ?>
						<button type="button" id="widrow-ai-test-connection" class="button">
							<?php esc_html_e( 'Test Connection', 'widrow-ai' ); ?>
						</button>
					<?php endif; ?>
					<span id="widrow-ai-connection-result" class="widrow-ai-connection-result"></span>
				</div>
			</div>

			<?php
			// The way through to the Tenant Portal, where content sync and the rest
			// of the tenant's settings live.
			$defho_url = '';
			if ( class_exists( 'Widrow_AI_OAuth' ) && method_exists( 'Widrow_AI_OAuth', 'get_defho_url' ) ) {
				$defho_url = Widrow_AI_OAuth::get_defho_url();
			}
			if ( ! empty( $defho_url ) ) :
			?>
			<p style="margin-top: 16px;">
				<a href="<?php echo esc_url( $defho_url ); ?>" target="_blank" rel="noopener" class="button">
					<?php esc_html_e( 'Open Tenant Portal', 'widrow-ai' ); ?>
					<span class="dashicons dashicons-external" style="margin-top: 3px;"></span>
				</a>
			</p>
			<?php endif; ?>

			<?php if ( ! $is_connected || $has_encryption_error ) : ?>
				<?php // ─── One-Click Connect / Reconnect (also shown on credential error) ── ?>
				<div class="widrow-ai-oauth-connect" id="widrow-ai-oauth-connect">
					<div class="widrow-ai-oauth-connect-box">
						<h3><?php echo $has_encryption_error ? esc_html__( 'Reconnect to DEFHO', 'widrow-ai' ) : esc_html__( 'Connect to DEFHO', 'widrow-ai' ); ?></h3>
						<p class="description">
							<?php echo $has_encryption_error ? esc_html__( 'Your WordPress security keys changed, so the saved DEFHO credentials can no longer be read and your Digital Employees are offline. Reconnect to restore them — you will be redirected to DEFHO to re-authorize.', 'widrow-ai' ) : esc_html__( 'Click the button below to connect your site to the DEFHO platform. You will be redirected to DEFHO to authorize the connection.', 'widrow-ai' ); ?>
						</p>
						<p>
							<button type="button" class="button button-primary button-hero" id="widrow-ai-oauth-start-btn">
								<?php echo $has_encryption_error ? esc_html__( 'Reconnect to DEFHO', 'widrow-ai' ) : esc_html__( 'Connect to DEFHO', 'widrow-ai' ); ?>
							</button>
						</p>
						<span id="widrow-ai-oauth-result" class="widrow-ai-connection-result"></span>
					</div>

					<div class="widrow-ai-manual-fallback">
<?php // Hidden on a credential error: manual entry restores only the API key (not the service secret) and can't recover a salt rotation — Reconnect is the complete path. ?>
						<details<?php echo $has_encryption_error ? ' hidden' : ''; ?>>
							<summary><?php esc_html_e( 'Manual connection (advanced)', 'widrow-ai' ); ?></summary>
							<div class="widrow-ai-manual-connection" id="widrow-ai-manual-connection">
								<p class="description">
									<?php esc_html_e( 'If one-click connect is not available, enter the API Key from your DEF Partner.', 'widrow-ai' ); ?>
								</p>

								<div class="widrow-ai-field">
									<label for="widrow_ai_manual_api_key"><?php esc_html_e( 'API Key', 'widrow-ai' ); ?></label>
									<input
										type="password"
										id="widrow_ai_manual_api_key"
										class="regular-text"
										placeholder="<?php esc_attr_e( 'Enter your API key', 'widrow-ai' ); ?>"
										value=""
										autocomplete="off"
									/>
								</div>

								<div class="widrow-ai-save-area">
									<button type="button" class="button button-primary" id="widrow-ai-save-manual-connection">
										<?php esc_html_e( 'Save Connection', 'widrow-ai' ); ?>
									</button>
									<span class="spinner"></span>
									<span id="widrow-ai-manual-conn-result" class="widrow-ai-connection-result"></span>
								</div>
							</div>
						</details>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $is_connected ) : ?>
				<div class="widrow-ai-disconnect-area" style="margin-top: 16px;">
					<button type="button" class="button" id="widrow-ai-disconnect-btn" style="color: #d63638; border-color: #d63638;">
						<?php esc_html_e( 'Disconnect', 'widrow-ai' ); ?>
					</button>
					<p class="description">
						<?php esc_html_e( 'Disconnecting will remove all connection credentials from this site. You can reconnect at any time.', 'widrow-ai' ); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<div class="widrow-ai-card" style="margin-top: 20px;">
			<h2><?php esc_html_e( 'Logging', 'widrow-ai' ); ?></h2>

			<div class="widrow-ai-field">
				<label for="widrow_ai_log_level"><?php esc_html_e( 'Log Level', 'widrow-ai' ); ?></label>
				<?php $current_log_level = get_option( 'widrow_ai_log_level', 'info' ); ?>
				<select id="widrow_ai_log_level" data-setting="widrow_ai_log_level">
					<option value="debug" <?php selected( $current_log_level, 'debug' ); ?>><?php esc_html_e( 'Debug', 'widrow-ai' ); ?></option>
					<option value="info" <?php selected( $current_log_level, 'info' ); ?>><?php esc_html_e( 'Info', 'widrow-ai' ); ?></option>
					<option value="warning" <?php selected( $current_log_level, 'warning' ); ?>><?php esc_html_e( 'Warning', 'widrow-ai' ); ?></option>
					<option value="error" <?php selected( $current_log_level, 'error' ); ?>><?php esc_html_e( 'Error', 'widrow-ai' ); ?></option>
				</select>
				<p class="description">
					<?php esc_html_e( 'Minimum log level to record. Debug captures all events; Error captures only errors.', 'widrow-ai' ); ?>
				</p>
			</div>

			<div class="widrow-ai-save-area">
				<button type="button" class="button button-primary widrow-ai-save-btn" data-tab="connection">
					<?php esc_html_e( 'Save Changes', 'widrow-ai' ); ?>
				</button>
				<span class="spinner"></span>
			</div>
		</div>
	</div>
</div>
