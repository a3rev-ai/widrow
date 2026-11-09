/**
 * Gutenberg sidebar panel for the DEF knowledge-exclusion meta. Vanilla
 * wp.element.createElement (no JSX, no build step) — bound to the
 * `_widrow_exclude_from_ingestion` meta the PHP class registers.
 */
(function (wp) {
	'use strict';
	var META_KEY = '_widrow_exclude_from_ingestion';
	var Panel = (wp.editPost && wp.editPost.PluginDocumentSettingPanel) || wp.editor.PluginDocumentSettingPanel;
	if (!wp.plugins || !Panel) return;

	var __ = wp.i18n.__;
	var el = wp.element.createElement;

	wp.plugins.registerPlugin('widrow-ai-knowledge-exclusion', {
		render: function () {
			var meta = wp.data.useSelect(function (s) {
				return s('core/editor').getEditedPostAttribute('meta') || {};
			}, []);
			if (typeof meta[META_KEY] === 'undefined') return null;
			var editPost = wp.data.useDispatch('core/editor').editPost;

			return el(Panel, {
				name: 'widrow-ai-knowledge-exclusion',
				title: __('Widrow AI', 'widrow-ai'),
			}, el(wp.components.CheckboxControl, {
				label: __('Exclude from Digital Employee knowledge', 'widrow-ai'),
				help: __('When checked, this item is excluded from Digital Employee knowledge. If it was already indexed, it is removed on the next sync — this item only, no Full Sync needed.', 'widrow-ai'),
				checked: !!meta[META_KEY],
				onChange: function (next) {
					var u = {}; u[META_KEY] = !!next;
					editPost({ meta: u });
				},
			}));
		},
	});
})(window.wp);
