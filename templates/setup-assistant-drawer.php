<?php
/**
 * Setup Assistant drawer template.
 *
 * Renders the right-side slide-in chat panel (the trigger button lives next
 * to the page h1 at each call site). Included by
 * Widrow_AI_Admin::render_settings_page() and
 * Widrow_AI_Content_Drafts_Page::render_page().
 *
 * @package widrow-ai
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'widrow_admin_access' ) ) {
	return;
}
?>

<!-- Drawer container (trigger button is in admin-settings.php next to the h1) -->
<div
	id="def-setup-assistant-drawer"
	class="def-sa-drawer"
	style="display:none"
	aria-hidden="true"
	role="dialog"
	aria-label="<?php esc_attr_e( 'Setup Assistant', 'widrow-ai' ); ?>"
>
	<!-- Backdrop -->
	<div class="def-sa-backdrop"></div>

	<!-- Panel -->
	<div class="def-sa-panel" role="document">
		<!-- Header -->
		<div class="def-sa-header">
			<h2 class="def-sa-title"><?php esc_html_e( 'Sam - Setup Assistant', 'widrow-ai' ); ?></h2>
			<div class="def-sa-header-actions">
				<button type="button" class="def-sa-clear" title="<?php esc_attr_e( 'Clear conversation', 'widrow-ai' ); ?>">
					<span class="dashicons dashicons-trash"></span>
				</button>
				<?php
				/*
				 * One button, two jobs, decided by the viewport. Below 783px it is
				 * Close and shows the x, exactly as before. At 783px and up the
				 * stylesheet swaps in the chevron and the drawer script relabels it
				 * Collapse and gives it aria-expanded — the desktop column has a
				 * rail to collapse to now, so the button that used to be hidden
				 * there is the control for it.
				 *
				 * The x and the chevron both ship; CSS picks one per breakpoint, so
				 * the icon can never lag the label. The aria-label rendered here is
				 * the phone's, which is the one that is right before any script runs.
				 */
				?>
				<button type="button" class="def-sa-close" aria-label="<?php esc_attr_e( 'Close Setup Assistant', 'widrow-ai' ); ?>">
					<span class="dashicons dashicons-no-alt def-sa-close-icon"></span>
					<span class="dashicons dashicons-arrow-right-alt2 def-sa-collapse-icon"></span>
				</button>
			</div>
		</div>

		<!-- Messages area -->
		<div class="def-sa-messages" role="log" aria-live="polite" aria-relevant="additions"></div>

		<!-- Composer footer -->
		<div class="def-sa-composer">
			<textarea
				class="def-sa-input"
				placeholder="<?php esc_attr_e( 'Ask about your setup...', 'widrow-ai' ); ?>"
				rows="1"
				aria-label="<?php esc_attr_e( 'Message', 'widrow-ai' ); ?>"
			></textarea>
			<button type="button" class="def-sa-send" aria-label="<?php esc_attr_e( 'Send message', 'widrow-ai' ); ?>">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
			</button>
		</div>
	</div>
</div>
