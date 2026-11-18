/*
 * The message box on a phone (v11.1.0). The size floor and the no-:has() rules off the
 * SHIPPED stylesheet; a state matrix running the shipped composer markup through the
 * stylesheet's own cascade (element.matches + specificity) for every state staff-ai.js
 * produces; the desktop wrapper; the toolbar markup; the shipped model switcher in jsdom.
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

// ── The stylesheet, as rules in file order tagged with their @media ──────
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
function decl(media, selector) {
	const out = {};
	RULES.filter(r => r.media === media && r.selectors.includes(selector)).forEach(r => Object.assign(out, r.decls));
	return out;
}
const px = v => (v && /^\d+(\.\d+)?px$/.test(v) ? parseFloat(v) : NaN);
const phone = s => decl(PHONE, s);

// ── The floor: a later edit that shrinks any of these fails ──────────────
const FLOOR = {
	'.composer-toolbar': d => px(d.gap) >= 8,
	'.composer-toolbar .upload-btn': d => px(d.width) >= 44 && px(d.height) >= 44,
	'.composer-toolbar .mic-btn': d => px(d.width) >= 44 && px(d.height) >= 44,
	'.composer-toolbar .voice-btn': d => px(d.width) >= 44 && px(d.height) >= 44,
	'.composer-toolbar .send-btn': d => px(d.width) >= 48 && px(d.height) >= 48,
	'.composer-toolbar .mic-btn[data-state="idle"]': d => px(d.width) >= 48 && px(d.height) >= 48,
	'.model-chip': d => px(d.height) >= 44 && px(d['font-size']) >= 15,
	'.composer-toolbar .model-select': d => px(d['font-size']) >= 16,
	'.composer-toolbar .mic-btn[data-state="listening"]': d => px(d.height) >= 48,
	'.composer-toolbar .mic-btn[data-state="transcribing"]': d => px(d.height) >= 48,
	'.composer-toolbar .mic-btn[data-state="answering"]': d => px(d.height) >= 48,
	'.composer-hint': d => d.display === 'none'
};
Object.keys(FLOOR).forEach(function (s) {
	check('floor: ' + s, FLOOR[s](phone(s)), JSON.stringify(phone(s)));
});
check('floor: beside an enabled Send the mic stays at least 44 by 44', (function (d) { return px(d.width) >= 44 && px(d.height) >= 44; })(
	phone('.composer-toolbar:has(.send-btn:not(:disabled)) .mic-btn[data-state="idle"]')));
(function () {
	const label = phone('.composer-toolbar .mic-btn[data-state="answering"] .mic-label');
	const lines = +label['-webkit-line-clamp'];
	check('floor: the answering label wraps to at most two lines that fit the 48px pill',
		label['white-space'] === 'normal' && lines === 2 && lines * px(label['font-size']) * parseFloat(label['line-height']) <= 48,
		JSON.stringify(label));
})();

// ── Without :has() a browser drops the whole rule a :has() selector sits in ─
const NO_HAS = Object.keys(FLOOR).filter(s => s !== '.composer-hint').concat([
	'.composer-toolbar .mic-btn[hidden]', '.composer-toolbar .voice-btn[hidden]',
	'.model-select[hidden] + .model-chip', '.model-select:focus-visible + .model-chip', '.composer-hint']);
(function () {
	const bad = RULES.filter(r => r.media === PHONE && r.selectors.some(s => NO_HAS.includes(s))
		&& r.selectors.some(s => s.includes(':has(')));
	const missing = NO_HAS.filter(s => !RULES.some(r => r.media === PHONE && r.selectors.includes(s)));
	check('no :has(): every rule that must hold without it shares no list with a :has() selector',
		bad.length === 0 && missing.length === 0, JSON.stringify({ bad: bad.map(r => r.selectors), missing }));
})();

// ── The state matrix: the shipped markup through the phone cascade ────────
// Specificity, with :is/:not/:has taking their most specific argument.
function spec(sel) {
	const s = [0, 0, 0];
	const add = t => { s[0] += t[0]; s[1] += t[1]; s[2] += t[2]; };
	sel = sel.replace(/:(is|not|has)\(((?:[^()]|\([^()]*\))*)\)/g, function (m, fn, args) {
		add(selectors(args).map(spec).sort(cmp).pop());
		return ' ';
	});
	sel = sel.replace(/\[[^\]]*\]/g, () => { s[1]++; return ' '; })
		.replace(/::[\w-]+/g, () => { s[2]++; return ' '; })
		.replace(/#[\w-]+/g, () => { s[0]++; return ' '; })
		.replace(/[.:][\w-]+/g, () => { s[1]++; return ' '; });
	sel.split(/[\s>+~]+/).forEach(t => { if (t && t !== '*') s[2]++; });
	return s;
}
function cmp(a, b) { return a[0] - b[0] || a[1] - b[1] || a[2] - b[2]; }
const CASCADE = RULES.filter(r => r.media === null || r.media === PHONE);
function matches(el, sel) { try { return el.matches(sel); } catch (e) { return false; } }
function computed(el, prop) {
	let best = null, bestSpec = null;
	CASCADE.forEach(r => {
		if (!(prop in r.decls)) return;
		r.selectors.forEach(sel => {
			if (!matches(el, sel)) return;
			const sp = spec(sel);
			if (!best || cmp(sp, bestSpec) >= 0) { best = r.decls[prop]; bestSpec = sp; }
		});
	});
	return best;
}
function shown(el) {
	for (let e = el; e && !e.classList.contains('composer-toolbar'); e = e.parentElement) {
		const d = computed(e, 'display');
		if (d === 'none' || (d === null && e.hidden)) return false;
	}
	return true;
}

const COMPOSER = extract.phpToHtml(extract.element(extract.templateSource(), 'div',
	l => l.includes('id="composerContainer"'), 'div#composerContainer'), 'div#composerContainer');
function row(setup) {
	const d = new JSDOM('<!doctype html><div id="staff-ai-app"><main class="chat-container">' + COMPOSER + '</main></div>').window.document;
	const $ = id => d.getElementById(id);
	const els = { input: $('composerInput'), mic: $('micBtn'), label: $('micLabel'), voice: $('voiceBtn'),
		select: $('modelSelect'), send: $('sendBtn'), container: $('composerContainer') };
	els.select.innerHTML = '<option value="opus" data-short="Opus 5.5">Opus 5.5 — Deep work</option>';
	els.select.hidden = false;                             // the list arrived
	// setMicState() and updateSendButton(), as staff-ai.js writes them.
	els.setMic = function (state, text) {
		els.mic.dataset.state = state; els.label.textContent = text || ''; els.label.hidden = !text;
	};
	els.setSend = function (disabled, stop) { els.send.classList.toggle('is-stop', !!stop); els.send.disabled = disabled; };
	els.setMic('idle');
	els.setSend(true);
	setup(els);
	const controls = { attach: $('uploadBtn'), mic: els.mic, readback: els.voice, model: d.querySelector('.model-chip'), send: els.send };
	const visible = Object.keys(controls).filter(k => shown(controls[k]));
	const main = visible.filter(k => computed(controls[k], 'width') === '48px');
	return { visible, main, pill: /^1 1/.test(computed(els.mic, 'flex') || ''),
		micWidth: computed(els.mic, 'width'), labelWrap: computed(els.label, 'white-space') };
}
const ALL = ['attach', 'mic', 'readback', 'model', 'send'];
const MATRIX = [
	['empty', e => {}, ['attach', 'mic', 'readback', 'model'], ['mic'], false],
	['typed', e => { e.input.value = 'hi'; e.setSend(false); }, ['attach', 'readback', 'model', 'send'], ['send'], false],
	['files staged, box empty', e => { e.setSend(false); }, ALL, ['send'], false],
	['suggestion ghost text', e => { e.input.value = 'Tell me more'; e.input.classList.add('staff-ai-suggestion-text'); e.setSend(false); },
		ALL, ['send'], false],
	['loading, before the turn id', e => { e.setSend(true); }, ['attach', 'mic', 'readback', 'model'], ['mic'], false],
	['typed reply running', e => { e.setSend(false, true); }, ['attach', 'readback', 'model', 'send'], ['send'], false],
	['stopping', e => { e.setSend(true, true); }, ['attach', 'readback', 'model', 'send'], ['send'], false],
	['listening', e => { e.setMic('listening', '0:03 · Tap to send'); }, ['mic'], [], true],
	['listening, files staged', e => { e.setMic('listening', '0:03 · Tap to send'); e.setSend(false); }, ['mic'], [], true],
	['transcribing', e => { e.setMic('transcribing', 'Transcribing…'); }, ['mic'], [], true],
	['answering, with Stop', e => { e.setMic('answering', 'Ann is answering · tap to end'); e.setSend(false, true); },
		['mic', 'send'], ['send'], true],
	['answering, Stop gone', e => { e.setMic('answering', 'Ann is answering · tap to end'); }, ['mic'], [], true],
	['no microphone', e => { e.mic.hidden = true; e.voice.hidden = true; }, ['attach', 'model', 'send'], ['send'], false],
	['no model picker', e => { e.select.hidden = true; }, ['attach', 'mic', 'readback'], ['mic'], false],
	['read-only', e => { e.container.classList.add('disabled'); }, ['attach', 'mic', 'readback', 'model'], ['mic'], false]
];
MATRIX.forEach(function (m) {
	const r = row(m[1]);
	check('state ' + m[0] + ': shows ' + m[2].join(', ') + '; main ' + (m[3][0] || 'none') + (m[4] ? '; the pill is the row' : ''),
		JSON.stringify(r.visible) === JSON.stringify(m[2]) && JSON.stringify(r.main) === JSON.stringify(m[3]) && r.pill === m[4],
		JSON.stringify(r));
	if (m[0] === 'files staged, box empty' || m[0] === 'suggestion ghost text') {
		check('  and the mic beside Send is the plain 44', r.micWidth === '44px', r.micWidth);
	}
	if (m[0] === 'answering, with Stop') check('  and its label may wrap', r.labelWrap === 'normal', r.labelWrap);
});

// ── Desktop: the wrapper is no box, the chip is not drawn ────────────────
check('desktop: the wrapper is no box and the chip is not drawn',
	decl(null, '.model-picker').display === 'contents' && decl(null, '.model-chip').display === 'none');
(function () {
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
