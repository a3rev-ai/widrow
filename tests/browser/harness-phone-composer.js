/*
 * The message box on a phone (v11.1.0): thumb-sized controls, the mic as the main
 * button, the model chip, and the listening pill across the row.
 *
 * Read off the SHIPPED stylesheet one breakpoint at a time: inside
 * `@media (max-width: 768px)` every composer control is at least 44 by 44 (the main
 * button 48) with 8 between, the model chip is 44 high with 15px text, the pill is the
 * row and 48 high, and the keyboard hint is gone; OUTSIDE every @media the desktop
 * values are exactly what they were before 11.1.0, so a desktop regression fails here.
 * The SHIPPED toolbar markup is checked for the chip's place and names, and the SHIPPED
 * model switcher block runs in jsdom: the chip shows the short name, the list keeps the
 * descriptions.
 *
 * Bite: MODEL_SWITCHER=/tmp/old-switcher.js (the 11.0.0 block) fails the chip checks;
 * the CSS checks bite by editing the stylesheet in a scratch worktree.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const extract = require('./extract');

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(pass + fail).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(pass + fail).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}

// ── The stylesheet, as rules tagged with the @media they sit in ──────────
const CSS = fs.readFileSync(path.join(extract.REPO, 'assets/css/staff-ai.css'), 'utf8')
	.replace(/\/\*[\s\S]*?\*\//g, '');

function closing(css, open) {
	let depth = 0;
	for (let j = open; j < css.length; j++) {
		if (css[j] === '{') depth++;
		else if (css[j] === '}' && --depth === 0) return j;
	}
	throw new Error('unbalanced braces at ' + open);
}
// A selector list split on its top-level commas (`:is(a, b)` stays whole).
function selectors(list) {
	const out = []; let depth = 0, cur = '';
	for (const ch of list) {
		if (ch === '(') depth++;
		if (ch === ')') depth--;
		if (ch === ',' && depth === 0) { out.push(cur); cur = ''; } else cur += ch;
	}
	out.push(cur);
	return out.map(s => s.trim().replace(/\s+/g, ' ')).filter(Boolean);
}
const RULES = [];
(function walk(css, media) {
	let i = 0;
	while (i < css.length) {
		const open = css.indexOf('{', i);
		if (open < 0) break;
		const prelude = css.slice(i, open).trim();
		if (prelude.startsWith('@')) {
			const end = closing(css, open);
			if (prelude.startsWith('@media')) walk(css.slice(open + 1, end), prelude.replace(/\s+/g, ' '));
			i = end + 1;
			continue;
		}
		const end = css.indexOf('}', open);
		const decls = {};
		css.slice(open + 1, end).split(';').forEach(d => {
			const k = d.indexOf(':');
			if (k > 0) decls[d.slice(0, k).trim()] = d.slice(k + 1).trim();
		});
		RULES.push({ media, selectors: selectors(prelude), decls });
		i = end + 1;
	}
})(CSS, null);

const PHONE = '@media (max-width: 768px)';
// The declarations a selector gets in one context, later rules winning.
function decl(media, selector) {
	const out = {};
	RULES.filter(r => r.media === media && r.selectors.includes(selector)).forEach(r => Object.assign(out, r.decls));
	return out;
}
const px = v => (v && /^\d+(\.\d+)?px$/.test(v) ? parseFloat(v) : NaN);
const phone = s => decl(PHONE, s);
const desk = s => decl(null, s);

// ── Phone ────────────────────────────────────────────────────────────────
check('phone: the row has at least 8 between its controls', px(phone('.composer-toolbar').gap) >= 8,
	JSON.stringify(phone('.composer-toolbar')));
['.upload-btn', '.mic-btn', '.voice-btn'].forEach(function (b) {
	const d = phone('.composer-toolbar ' + b);
	check('phone: ' + b + ' is at least 44 by 44 with a 24px icon',
		px(d.width) >= 44 && px(d.height) >= 44 && px(phone('.composer-toolbar ' + b + ' svg').width) >= 24,
		JSON.stringify(d));
});
(function () {
	const send = phone('.composer-toolbar .send-btn');
	check('phone: Send (and Stop) is 48 by 48 with a 24px icon',
		px(send.width) >= 48 && px(send.height) >= 48 && px(phone('.composer-toolbar .send-btn svg').width) >= 24,
		JSON.stringify(send));
	const mic = phone('.composer-toolbar .mic-btn[data-state="idle"]');
	check('phone: the idle mic is the main button, 48 by 48', px(mic.width) >= 48 && px(mic.height) >= 48, JSON.stringify(mic));
	const second = phone('.composer-toolbar:has(.send-btn:not(:disabled)) .mic-btn[data-state="idle"]');
	check('phone: beside an enabled Send the mic stays at least 44 by 44',
		px(second.width) >= 44 && px(second.height) >= 44, JSON.stringify(second));
})();
check('phone: the mic goes once there are words in the box, and while Stop shows',
	phone('.composer:has(.composer-input:not(:placeholder-shown):not(.staff-ai-suggestion-text)) .mic-btn[data-state="idle"]').display === 'none'
	&& phone('.composer-toolbar:has(.send-btn.is-stop) .mic-btn[data-state="idle"]').display === 'none');
check('phone: an empty box with a mic shows no dimmed Send (and never hides Stop)',
	phone('.composer:has(.composer-input:placeholder-shown):has(.mic-btn[data-state="idle"]:not([hidden])) .send-btn:disabled:not(.is-stop)').display === 'none');
check('phone: a mic or read-back the page hid is not drawn',
	phone('.composer-toolbar .mic-btn[hidden]').display === 'none' && phone('.composer-toolbar .voice-btn[hidden]').display === 'none');
(function () {
	const chip = phone('.model-chip');
	check('phone: the model chip is 44 high with 15px text',
		chip.display === 'flex' && px(chip.height) >= 44 && px(chip['font-size']) >= 15, JSON.stringify(chip));
	const sel = phone('.composer-toolbar .model-select');
	check('phone: the select is the tap target over the chip, at 16px so iOS does not zoom',
		sel.position === 'absolute' && sel.inset === '0' && sel.opacity === '0' && px(sel['font-size']) >= 16,
		JSON.stringify(sel));
	check('phone: no chip and no wrapper box while the select is hidden',
		phone('.model-select[hidden] + .model-chip').display === 'none'
		&& phone('.composer-toolbar .model-picker:has(> .model-select[hidden])').display === 'none');
	check('phone: the chip shows the select\'s focus', /solid/.test(phone('.model-picker:focus-within .model-chip').outline || ''));
})();
['listening', 'transcribing', 'answering'].forEach(function (s) {
	const d = phone('.composer-toolbar .mic-btn[data-state="' + s + '"]');
	check('phone: the ' + s + ' pill grows across the row, 48 high, 17px text',
		/^1 1/.test(d.flex || '') && px(d.height) >= 48 && px(d['font-size']) >= 17, JSON.stringify(d));
});
check('phone: while the pill shows, nothing else in the row can be hit but Stop',
	phone('.composer-toolbar:has(.mic-btn:is([data-state="listening"], [data-state="transcribing"], [data-state="answering"])) > :not(.mic-btn):not(.send-btn.is-stop)').display === 'none');
check('phone: the keyboard hint is not displayed', phone('.composer-hint').display === 'none');
check('phone, reduced motion: the listening pulse stops',
	decl('@media (max-width: 768px) and (prefers-reduced-motion: reduce)', '.composer-toolbar .mic-btn[data-state="listening"]').animation === 'none');

// ── Desktop: exactly the 11.0.0 values ───────────────────────────────────
(function () {
	const b = desk('.upload-btn'), m = desk('.mic-btn'), v = desk('.voice-btn');
	check('desktop: attach, mic and read-back stay 32 by 32',
		[b, m, v].every(d => d.width === '32px' && d.height === '32px'), JSON.stringify(b));
	check('desktop: their icons stay 18px', ['.upload-btn svg', '.mic-btn svg', '.voice-btn svg'].every(s => desk(s).width === '18px'));
	const send = desk('.send-btn');
	check('desktop: Send stays 32 by 32 with a 16px icon',
		send.width === '32px' && send.height === '32px' && desk('.send-btn svg').width === '16px', JSON.stringify(send));
	const sel = desk('.model-select');
	check('desktop: the model select stays 12px text, 5px 8px, right of the spacer, at most 220 wide',
		sel['font-size'] === '12px' && sel.padding === '5px 8px' && sel['margin-left'] === 'auto'
		&& sel['margin-right'] === '8px' && sel['max-width'] === '220px' && !('position' in sel) && !('opacity' in sel),
		JSON.stringify(sel));
	const bar = desk('.composer-toolbar');
	check('desktop: the toolbar stays space-between with no gap',
		bar['justify-content'] === 'space-between' && bar['padding-top'] === '8px' && !('gap' in bar), JSON.stringify(bar));
	const hint = desk('.composer-hint');
	check('desktop: the keyboard hint stays, 11px', hint['font-size'] === '11px' && !('display' in hint));
	const pill = desk('.mic-btn[data-state="listening"]');
	check('desktop: the pill stays 12px, sized by its words',
		pill['font-size'] === '12px' && pill.width === 'auto' && pill.padding === '0 10px 0 8px' && !('height' in pill) && !('flex' in pill),
		JSON.stringify(pill));
	check('desktop: the wrapper is no box and the chip is not drawn',
		desk('.model-picker').display === 'contents' && desk('.model-chip').display === 'none');
	const leaks = RULES.filter(r => r.media !== PHONE && !/prefers-reduced-motion/.test(r.media || '')
		&& r.selectors.some(s => /model-picker|model-chip|composer-toolbar[^{]*:has/.test(s))
		&& !(r.media === null && (r.selectors.join() === '.model-picker' || r.selectors.join() === '.model-chip')));
	check('desktop: no rule outside the phone query touches the chip or the toolbar\'s :has() rules',
		leaks.length === 0, JSON.stringify(leaks.map(r => r.selectors)));
})();

// ── The shipped toolbar markup ───────────────────────────────────────────
(function () {
	const html = extract.phpToHtml(extract.element(extract.templateSource(), 'div',
		l => l.includes('class="composer-toolbar"'), 'div.composer-toolbar'), 'div.composer-toolbar');
	const doc = new JSDOM(html).window.document;
	const bar = doc.querySelector('.composer-toolbar');
	const kids = Array.from(bar.children).map(e => e.id || e.className);
	check('markup: attach, mic, read-back, the model picker, Send, in that order',
		JSON.stringify(kids) === JSON.stringify(['uploadBtn', 'micBtn', 'voiceBtn', 'model-picker', 'sendBtn']), JSON.stringify(kids));
	const sel = doc.getElementById('modelSelect');
	check('markup: the select sits in the picker, hidden until filled, still named "AI model"',
		sel.parentElement.className === 'model-picker' && sel.hidden && sel.getAttribute('aria-label') === 'AI model');
	const chip = doc.querySelector('.model-chip');
	check('markup: the chip follows the select and is hidden from a screen reader',
		sel.nextElementSibling === chip && chip.getAttribute('aria-hidden') === 'true' && !!chip.querySelector('#modelChipName'));
})();

// ── The shipped model switcher ───────────────────────────────────────────
const SWITCHER = extract.slice('model switcher',
	l => l.includes('// Model switcher (per-session'),
	l => l.includes('// Header overflow menu (mobile)'),
	['function paintModelChip', 'opt.dataset.short'],
	'MODEL_SWITCHER');
const MODELS = [
	{ id: 'opus', label: 'Opus 5.5', description: 'Deep work' },
	{ id: 'fable', label: 'Fable 5.1', description: 'Most capable' },
	{ id: 'sonnet', label: 'Sonnet 5', description: 'Responsive everyday work' },
	{ id: 'haiku', label: 'Haiku 4.5', description: 'Fastest / cheapest' }
];
async function boot(models, saved) {
	const dom = new JSDOM('<!doctype html><select id="modelSelect" hidden></select>'
		+ '<span class="model-chip"><span id="modelChipName"></span></span>',
		{ url: 'https://example.test/', runScripts: 'outside-only' });
	const w = dom.window;
	if (saved) w.localStorage.setItem('staff-ai-model', saved);
	w.__status = { available_models: models };
	w.eval('(function () {\n'
		+ "var statusUrl = '/status', assistantName = '', assistantNameSubscribers = [];\n"
		+ 'function handOverToPortal() { return false; }\n'
		+ 'function wpFetch() { return Promise.resolve({ ok: true, json: function () { return window.__status; } }); }\n'
		+ SWITCHER + '\n})();');
	for (let i = 0; i < 5; i++) await new Promise(r => setTimeout(r, 0));
	const d = w.document;
	return { w, sel: d.getElementById('modelSelect'), chip: d.getElementById('modelChipName') };
}

(async function () {
	let t = await boot(MODELS);
	check('switcher: the chip shows the first model\'s short name', t.chip.textContent === 'Opus 5.5', t.chip.textContent);
	check('switcher: the list keeps each name with its description',
		Array.from(t.sel.options).map(o => o.textContent).join('|')
		=== 'Opus 5.5 — Deep work|Fable 5.1 — Most capable|Sonnet 5 — Responsive everyday work|Haiku 4.5 — Fastest / cheapest'
		&& t.sel.hidden === false);
	t.sel.value = 'haiku';
	t.sel.dispatchEvent(new t.w.Event('change'));
	check('switcher: a choice repaints the chip and is remembered',
		t.chip.textContent === 'Haiku 4.5' && t.w.localStorage.getItem('staff-ai-model') === 'haiku', t.chip.textContent);

	t = await boot(MODELS, 'sonnet');
	check('switcher: the remembered pick is what the chip names', t.chip.textContent === 'Sonnet 5' && t.sel.value === 'sonnet');

	t = await boot([{ id: 'x', label: '<b>Plain</b>' }]);
	check('switcher: a model with no description reads the same in both, and markup stays text',
		t.chip.textContent === '<b>Plain</b>' && t.sel.options[0].textContent === '<b>Plain</b>' && !t.chip.querySelector('b'));

	t = await boot([]);
	check('switcher: no models leaves the select hidden and the chip empty', t.sel.hidden === true && t.chip.textContent === '');

	console.log('harness-phone-composer: the message box on a phone (v11.1.0)');
	console.log(results.join('\n'));
	console.log(pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})();
