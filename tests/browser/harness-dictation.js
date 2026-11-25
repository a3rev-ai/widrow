/*
 * Dictation on the microphone (v11.6.0) — both chat screens.
 *
 * Customer Chat runs its SHIPPED module whole in jsdom (as harness-cc-conversation.js does);
 * the console runs its SHIPPED blocks, sliced by marker (as harness-console-conversation.js
 * does), against a fake host. Each gets a fake recorder per createRecorder call, so a check
 * can hold a recording open and hand back what it heard: live words, or a clip.
 *
 * What it holds, on each screen: the microphone shows wherever the voice is on, named for
 * dictation; a tap records on the mic's own recorder, which no pause ends (it is given no
 * silence stop) and whose live socket asks for no turn signal; a second tap puts the words
 * in the box at the cursor, a space from the words around them, the box focused, and sends
 * nothing; what is then sent is a typed message (no via_voice, no audio, no speech_out) and
 * no recorder opens again after its reply; a recording with no live words is posted to the
 * transcribe request (the widget: direct on its stream token, through the site on a 401 or
 * 403 or with no token; the console: through its host) and its text goes in the box; each
 * refusal says the screen's own line and leaves the box as it was; the round button starts
 * and ends the older hands-free voice where conversation mode is off, and during it the mic
 * is that voice's mic, as before.
 */
const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');
const extract = require('./extract');

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(pass + fail).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(pass + fail).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}
async function run(label, fn) {
	try { await fn(); } catch (e) { check(label, false, 'threw ' + String(e && e.stack || e).split('\n').slice(0, 2).join(' ')); }
}
const show = v => JSON.stringify(v);
const sleep = ms => new Promise(r => setTimeout(r, ms));

// One fake recorder per createRecorder call. The mic's own is the one with no silence stop.
function fakeRecorders() {
	const list = [];
	return {
		list,
		create: o => {
			const r = { opts: o, recording: false, starts: [], stops: 0, releases: 0, finish: null };
			r.start = live => { r.starts.push(live); r.recording = true; return Promise.resolve(true); };
			r.isRecording = () => r.recording;
			r.stop = () => { r.recording = false; r.stops++; return new Promise(res => { r.finish = res; }); };
			r.release = () => { r.recording = false; r.releases++; };
			list.push(r);
			return r;
		},
		mic: () => list.find(r => typeof r.opts.onSilence !== 'function'),
		handsFree: () => list.find(r => typeof r.opts.onSilence === 'function'),
	};
}
const SAID = { transcript: 'book a table for two', spoke: true, seconds: 2.4, mime: 'audio/webm', blob: {} };
const CLIP = { spoke: true, seconds: 3.2, mime: 'audio/mp4', blob: {} };

// ── Customer Chat ────────────────────────────────────────────────────────
const MODULE = extract.customerChatSource();
const VENDOR = ['assets/js/def-persona.js', 'assets/js/vendor/marked.min.js', 'assets/js/vendor/purify.min.js',
	'assets/js/widrow-ai-product-cards.js'].map(f => fs.readFileSync(path.join(extract.REPO, f), 'utf8'));
const CC = {
	apiBaseUrl: 'https://api.example.test',
	chatStreamUrl: 'https://shop.test/wp-json/widrow-ai/v1/chat/stream',
	streamTokenUrl: 'https://shop.test/wp-json/widrow-ai/v1/chat/stream-token',
	wpRestUrl: 'https://shop.test/wp-json/widrow-ai/v1/',
	wpRestRoot: 'https://shop.test/wp-json/',
	restUrl: 'https://shop.test/wp-json/widrow-ai/v1/context-token',
	loginUrl: 'https://shop.test/wp-admin/admin-ajax.php',
	nonce: 'n0nce', wpRestNonce: 'n0nce', assistantName: 'Joe', isLoggedIn: false,
};
const DIRECT = 'https://api.example.test/api/chat/stream';
const DIRECT_TRANSCRIBE = 'https://api.example.test/api/customer/voice/transcribe';
const SITE_TRANSCRIBE = CC.wpRestUrl + 'chat/voice/transcribe';

async function bootWidget(opts) {
	opts = opts || {};
	const dom = new JSDOM('<!doctype html><html><head></head><body><div id="host"></div></body></html>',
		{ url: 'https://shop.test/', runScripts: 'outside-only', pretendToBeVisual: true });
	const w = dom.window;
	extract.stubMatchMedia(w);
	const realST = w.setTimeout.bind(w);
	w.setTimeout = (fn, ms) => realST(fn, Math.min(ms || 0, 1));
	for (const src of VENDOR) w.eval(src);
	w.ReadableStream = function () {};
	w.WidrowAiPageContextHelper = { build: () => ({ canonical_path: '/' }), markShipped() {} };
	const recs = fakeRecorders();
	const h = { w, recs, sent: [], transcribes: [], mints: 0,
		direct: opts.direct || (() => ({ status: 200, body: { text: 'from the clip' } })),
		site: opts.site || (() => ({ status: 200, body: { text: 'from the site' } })) };
	const res = (body, status) => Promise.resolve({ ok: status >= 200 && status < 300, status,
		headers: { get: () => null }, json: () => Promise.resolve(body), text: () => Promise.resolve(JSON.stringify(body)) });
	function sse(events) {
		const enc = new w.TextEncoder();
		const chunks = events.map(e => enc.encode('data: ' + JSON.stringify(e) + '\n\n'));
		return Promise.resolve({ ok: true, status: 200, body: { getReader: () => ({
			read: () => Promise.resolve(chunks.length ? { done: false, value: chunks.shift() } : { done: true }) }) } });
	}
	w.fetch = function (url, init) {
		url = String(url); init = init || {};
		if (url === CC.streamTokenUrl) {
			h.mints++;
			if (opts.noToken) return res({}, 503);
			return res({ token: 'tok-' + h.mints, exp: Math.floor(Date.now() / 1000) + 600, stream_url: DIRECT, live_url: 'wss://api.example.test/api/customer/voice/live' }, 200);
		}
		if (url === CC.wpRestUrl + 'chat/voice') return res({ enabled: true, conversation: !!opts.conversation }, 200);
		if (url === DIRECT_TRANSCRIBE || url === SITE_TRANSCRIBE) {
			const call = { road: url === DIRECT_TRANSCRIBE ? 'direct' : 'site', body: JSON.parse(init.body), headers: init.headers, credentials: init.credentials, method: init.method };
			h.transcribes.push(call);
			const a = (call.road === 'direct' ? h.direct : h.site)(call);
			return res(a.body, a.status);
		}
		if (url.indexOf('/api/customer/employee-tools') !== -1) return res({ tools: [] }, 200);
		if (url === DIRECT || url === CC.chatStreamUrl) {
			const body = JSON.parse(init.body);
			h.sent.push(body);
			return sse([{ type: 'text_delta', text: 'Typed reply.' }, { type: 'done', thread_id: 'thr-1', choices: [{ message: { content: 'Typed reply.' } }] }]);
		}
		return res({}, 200);
	};
	w.DefVoice = {
		supported: () => true, micAllowedBySite: () => true, isStopPhrase: () => false,
		createSpeaker: () => ({ stop() {}, unlock() {}, end() {}, speak() {}, discard() {}, whenIdle: () => Promise.resolve() }),
		createRecorder: recs.create,
		toBase64: () => Promise.resolve('QUFBQQ=='), log: () => [], note() {},
	};
	const root = w.document.getElementById('host').attachShadow({ mode: 'open' });
	root.innerHTML = '<div class="def-cc-panel"><button class="def-cc-panel-close" aria-label="Close chat"></button></div>';
	w.eval(MODULE);
	w.DEFCustomerChat.init(root, Object.assign({}, CC));
	await sleep(40);
	const $ = sel => root.querySelector(sel);
	Object.assign(h, {
		root,
		mic: () => $('.def-cc-composer-mic'), box: () => $('.def-cc-composer-input'), btn: () => $('.def-cc-composer-send'),
		bubbles: () => Array.from(root.querySelectorAll('.def-cc-message--assistant .def-cc-message-content')).map(b => b.textContent.trim()),
		role: () => ['start', 'end'].find(r => h.btn().classList.contains('def-cc-composer-send--' + r)) ||
			(h.btn().classList.contains('def-cc-composer-send--talk') ? 'send' : 'today'),
		micShown: () => h.mic().style.display !== 'none',
		type: (text, cursor) => {
			h.box().value = text;
			h.box().dispatchEvent(new w.Event('input', { bubbles: true }));
			if (cursor !== undefined) h.box().setSelectionRange(cursor, cursor);
		},
		// Tap, speak, tap: the mic's recorder hands back `heard`.
		dictate: async heard => {
			h.mic().click();
			await sleep(10);
			h.mic().click();
			await sleep(5);
			const r = recs.mic();
			if (r && r.finish) r.finish(heard);
			await sleep(30);
		},
	});
	return h;
}

// ── The console ──────────────────────────────────────────────────────────
const s = (label, start, end, needs) => extract.slice(label, start, end, needs);
const REQUEST = s('request path', l => l.includes('// Every request that carries the nonce'), l => l.includes('\t// Elements'), ['async function apiRequest']);
const INFO = s('info banner', l => l.includes('\t// Show info'), l => l.includes('// Smart scroll'), ['function showInfo', 'function showError']);
const BUTTON = s('send button', l => l.includes('// Update send button state.'), l => l.includes('// The Stop button end'), ['function updateSendButton']);
const CLICK = s('send button click', l => l.startsWith("\tsendBtn.addEventListener('click'"), l => l.includes('\t// Show info'), ['requestStop()']);
const VOICE = extract.voice();
const DOOR = 'https://api.defho.test/api/tenant/acme/staff-ai';

function bootConsole(opts) {
	opts = opts || {};
	const dom = new JSDOM('<!doctype html><div id="messagesContainer"><div id="messagesList"></div></div>' +
		'<div id="infoBanner"></div><div id="errorBanner"></div>' +
		'<textarea id="composerInput" placeholder="Ask"></textarea><button id="sendBtn"></button>' +
		'<button id="micBtn"></button><span id="micLabel"></span><button id="voiceBtn"></button>',
	{ url: 'https://acme.defho.test/staff-ai/acme/', pretendToBeVisual: true });
	const window = dom.window, document = window.document;
	const $ = id => document.getElementById(id);
	const recs = fakeRecorders();
	const calls = [], sent = [];
	window.DefVoice = {
		supported: () => true, micAllowedBySite: () => true, log: () => [], note() {}, isStopPhrase: () => false,
		createSpeaker: () => ({ mode: 'server', setMode(m) { this.mode = m; }, getMode() { return this.mode; }, unlock() {}, stop() {}, speak() {}, end() {}, whenIdle: () => Promise.resolve() }),
		createRecorder: recs.create,
		toBase64: () => Promise.resolve('QUFBQQ=='),
	};
	const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
	const transcribe = opts.transcribe || (() => json(200, { text: 'from the clip' }));
	const host = {
		fetch(url, init) {
			init = init || {};
			calls.push({ url, method: init.method || 'GET', body: init.body ? JSON.parse(init.body) : null });
			if (url === DOOR + '/voice/transcribe') return Promise.resolve(transcribe());
			return Promise.resolve(json(200, {}));
		},
		voice: true,
		live: () => ({ url: 'wss://api.defho.test/live', token: 'live-tok' }),
	};
	const StaffAIConfig = { nonce: 'n0', host };
	window.StaffAIConfig = StaffAIConfig;
	const deps = {
		window, document, DefVoice: window.DefVoice, navigator: { language: 'en-AU' }, StaffAIConfig, apiBase: DOOR,
		fetch: () => Promise.reject(new Error('no network but the host')), Response, Event: window.Event,
		console: { error() {}, warn() {}, log() {}, info() {} },
		setTimeout, localStorage: window.localStorage,
		messagesList: $('messagesList'), messagesContainer: $('messagesContainer'), errorBanner: $('errorBanner'), infoBanner: $('infoBanner'),
		composerInput: $('composerInput'), sendBtn: $('sendBtn'), micBtn: $('micBtn'), micLabel: $('micLabel'), voiceBtn: $('voiceBtn'),
		hasActiveFiles: () => false, stagedFiles: [], renderMessages() {}, removeTypingMessage() {}, _streamAbort: null,
		chatStreamUrl: DOOR + '/chat/stream', streamTokenLeft: () => 0, isStreaming: false,
		sendMessage: () => { sent.push($('composerInput').value); },
	};
	const HEAD = `
		let nonce = StaffAIConfig.nonce;
		let messages = [], isLoading = false, isReadOnly = false, currentConversationId = 'th-1', assistantName = 'Sue', _isStreaming = false;
		function t(k, d) { return d; }
		function onAssistantName(fn) { fn(); }
		function sendMessageStreaming() { return Promise.resolve(); }
	`;
	const TAIL = `
		initVoice();
		return { state: function () { return { on: conversationOn, mic: micBtn.dataset.state }; } };`;
	const names = Object.keys(deps);
	const api = new window.Function(...names, HEAD + REQUEST + '\n' + INFO + '\n' + BUTTON + '\n' + CLICK + '\n' + VOICE + TAIL)(...names.map(k => deps[k]));
	return {
		window, document, api, recs, calls, sent, $,
		box: () => $('composerInput'),
		role: () => $('sendBtn').dataset.voice || 'today',
		info: () => $('infoBanner').classList.contains('visible') ? $('infoBanner').textContent : '',
		error: () => $('errorBanner').classList.contains('visible') ? $('errorBanner').textContent : '',
		transcribes: () => calls.filter(c => c.url === DOOR + '/voice/transcribe'),
		type: (text, cursor) => {
			$('composerInput').value = text;
			if (cursor !== undefined) $('composerInput').setSelectionRange(cursor, cursor);
		},
		dictate: async heard => {
			$('micBtn').click();
			await sleep(10);
			$('micBtn').click();
			await sleep(5);
			const r = recs.mic();
			if (r && r.finish) r.finish(heard);
			await sleep(30);
		},
	};
}

(async function () {
	// ════ Customer Chat ════
	await run('widget: the controls, conversation off', async () => {
		const h = await bootWidget();
		check('widget, conversation off: the mic shows, named "Dictate"; the round button is Start voice',
			h.micShown() && h.mic().getAttribute('aria-label') === 'Dictate' && h.role() === 'start' &&
				h.btn().getAttribute('aria-label') === 'Start voice',
			show({ mic: h.micShown(), label: h.mic().getAttribute('aria-label'), role: h.role() }));
	});
	await run('widget: the controls, conversation on', async () => {
		const h = await bootWidget({ conversation: true });
		check('widget, conversation on: Start voice as before, and the mic now shows beside it, named "Dictate"',
			h.micShown() && h.mic().getAttribute('aria-label') === 'Dictate' && h.role() === 'start',
			show({ mic: h.micShown(), role: h.role() }));
	});

	await run('widget: tap, speak, tap', async () => {
		const h = await bootWidget();
		h.mic().click();
		await sleep(10);
		const r = h.recs.mic();
		const live = r && r.starts[0];
		check('widget — a tap records on the mic\'s own recorder (no silence stop), live on the stream token with no turn signal, nothing sent',
			!!r && r.recording && !!live && live.turn === false && live.token === 'tok-1' && h.mic().dataset.state === 'listening' &&
				h.sent.length === 0 && h.recs.handsFree().starts.length === 0,
			show({ recorders: h.recs.list.map(x => Object.keys(x.opts)), live, state: h.mic().dataset.state }));
		h.mic().click();
		await sleep(5);
		r.finish(SAID);
		await sleep(30);
		check('widget — the second tap: the words in the box, the box focused, nothing sent, the mic at rest',
			h.box().value === 'book a table for two' && h.root.activeElement === h.box() && h.sent.length === 0 &&
				h.mic().dataset.state === 'idle' && h.role() === 'send',
			show({ value: h.box().value, sent: h.sent.length, state: h.mic().dataset.state, role: h.role() }));
		h.btn().click();
		await sleep(80);
		const body = h.sent[0] || {};
		check('widget — Send sends it as a typed message: no via_voice, no audio, no speech_out; the box empties',
			h.sent.length === 1 && body.messages.slice(-1)[0].content === 'book a table for two' && !('via_voice' in body) &&
				!('audio_base64' in body) && !('speech_out' in body) && h.box().value === '',
			show(body));
		await sleep(40);
		check('widget — after its written reply no recorder opens again',
			h.recs.mic().starts.length === 1 && h.recs.handsFree().starts.length === 0,
			show({ mic: h.recs.mic().starts.length, handsFree: h.recs.handsFree().starts.length }));
	});

	await run('widget: the cursor', async () => {
		const h = await bootWidget();
		h.type('Hello there', 5);
		await h.dictate({ transcript: 'big', spoke: true, seconds: 1 });
		const mid = h.box().value;
		h.type('Hi', 2);
		await h.dictate({ transcript: 'again', spoke: true, seconds: 1 });
		check('widget — the words go in at the cursor, a space from the words around them',
			mid === 'Hello big there' && h.box().value === 'Hi again', show([mid, h.box().value]));
		h.mic().click();
		await sleep(10);
		h.type('Typed while it listened', 23);
		h.mic().click();
		await sleep(5);
		h.recs.mic().finish({ transcript: 'and said too', spoke: true, seconds: 1 });
		await sleep(30);
		check('widget — words typed while it records are kept; the dictated words go where the cursor is when they arrive',
			h.box().value === 'Typed while it listened and said too', h.box().value);
	});

	await run('widget: a pause', async () => {
		const h = await bootWidget();
		h.mic().click();
		await sleep(10);
		const r = h.recs.mic();
		check('widget — no pause ends it: the mic\'s recorder is given no silence stop, only the two-minute and nothing-heard ones',
			typeof r.opts.onSilence !== 'function' && typeof r.opts.onAutoStop === 'function' && typeof r.opts.onIdle === 'function' && r.recording,
			show(Object.keys(r.opts)));
	});

	await run('widget: the clip, direct', async () => {
		const h = await bootWidget();
		h.type('Note:', 5);
		await h.dictate(CLIP);
		const c = h.transcribes[0] || {};
		check('widget — a clip: POST direct beside the token\'s stream, credentials omitted, the token as a Bearer, the clip and the token\'s thread',
			h.transcribes.length === 1 && c.road === 'direct' && c.method === 'POST' && c.credentials === 'omit' &&
				c.headers.Authorization === 'Bearer tok-1' && c.headers['Content-Type'] === 'application/json' &&
				show(c.body) === show({ audio_base64: 'QUFBQQ==', audio_mime: 'audio/mp4', audio_seconds: 3.2, thread_id: '' }),
			show(c));
		check('widget — its text goes in the box, nothing sent', h.box().value === 'Note: from the clip' && h.sent.length === 0, h.box().value);
	});

	for (const status of [401, 403]) {
		await run('widget: direct ' + status, async () => {
			const h = await bootWidget({ direct: () => ({ status, body: { detail: { error: 'stream_token_denied', message: 'no' } } }) });
			await h.dictate(CLIP);
			const site = h.transcribes[1] || {};
			check('widget — direct refused ' + status + ': through the site, with the nonce, the clip as it was; the token re-minted',
				h.transcribes.length === 2 && site.road === 'site' && site.method === 'POST' && site.headers['X-WP-Nonce'] === 'n0nce' &&
					site.body.audio_base64 === 'QUFBQQ==' && site.body.audio_mime === 'audio/mp4' && site.body.audio_seconds === 3.2 &&
					h.box().value === 'from the site' && h.mints === 2,
				show({ calls: h.transcribes.map(c => c.road), site, mints: h.mints, value: h.box().value }));
		});
	}
	await run('widget: no token', async () => {
		const h = await bootWidget({ noToken: true });
		await h.dictate(CLIP);
		check('widget — no stream token: no live socket, the clip goes through the site',
			h.recs.mic().starts[0] === null && h.transcribes.length === 1 && h.transcribes[0].road === 'site' && h.box().value === 'from the site',
			show({ live: h.recs.mic().starts[0], calls: h.transcribes.map(c => c.road) }));
	});

	const LINES = {
		nothingHeard: 'Nothing was heard. Try again a little closer to the microphone.',
		transcribeFailed: 'That recording could not be transcribed. Please try again.',
		voiceUnavailable: "Voice isn't available right now. You can type your message below instead.",
		rateLimited: 'Please wait a moment before sending another message',
	};
	for (const [why, answer, line] of [
		['422 nothing_heard', { status: 422, body: { detail: { error: 'nothing_heard', message: 'x' } } }, LINES.nothingHeard],
		['429', { status: 429, body: { detail: 'Too many messages' } }, LINES.rateLimited],
		['502 voice_unavailable', { status: 502, body: { detail: { error: 'voice_unavailable', message: 'x' } } }, LINES.voiceUnavailable],
		['502 transcription_failed', { status: 502, body: { detail: { error: 'transcription_failed', message: 'x' } } }, LINES.transcribeFailed],
		['415 not_audio', { status: 415, body: { detail: { error: 'not_audio', message: 'x' } } }, LINES.transcribeFailed],
	]) {
		await run('widget: refusal ' + why, async () => {
			const h = await bootWidget({ direct: () => answer });
			h.type('Keep this', 9);
			await h.dictate(CLIP);
			check('widget — refusal ' + why + ': its line, and the box left as it was',
				h.bubbles().slice(-1)[0] === line && h.box().value === 'Keep this' && h.sent.length === 0 && h.mic().dataset.state === 'idle',
				show({ last: h.bubbles().slice(-1)[0], value: h.box().value }));
		});
	}
	await run('widget: nothing heard, voice refused live', async () => {
		let h = await bootWidget();
		h.type('Keep this', 9);
		await h.dictate({ spoke: false, seconds: 3, mime: 'audio/webm', blob: {} });
		const quiet = [h.bubbles().slice(-1)[0], h.box().value, h.transcribes.length];
		h = await bootWidget();
		h.type('Keep this', 9);
		await h.dictate({ spoke: true, seconds: 3, unavailable: true, mime: 'audio/webm', blob: {} });
		check('widget — nothing heard, or the live socket saying voice_unavailable: the line, nothing posted, the box kept',
			show(quiet) === show([LINES.nothingHeard, 'Keep this', 0]) && h.bubbles().slice(-1)[0] === LINES.voiceUnavailable &&
				h.box().value === 'Keep this' && h.transcribes.length === 0,
			show({ quiet, last: h.bubbles().slice(-1)[0] }));
	});

	await run('widget: the round button, conversation off', async () => {
		const h = await bootWidget();
		h.btn().click();
		await sleep(10);
		const hf = h.recs.handsFree();
		check('widget, conversation off — Start voice starts today\'s hands-free voice (its recorder, not the mic\'s); the button is End voice, the mic stays as that voice\'s mic',
			hf.starts.length === 1 && h.recs.mic().starts.length === 0 && h.role() === 'end' && h.micShown() &&
				h.mic().dataset.state === 'listening',
			show({ hf: hf.starts.length, mic: h.recs.mic().starts.length, role: h.role(), state: h.mic().dataset.state }));
		h.mic().click();
		await sleep(5);
		check('widget — during hands-free a tap on the mic is that voice\'s tap (the turn is sent), not dictation',
			hf.stops === 1 && h.recs.mic().starts.length === 0, show({ stops: hf.stops }));
		hf.finish(null);
		await sleep(10);
		h.btn().click();
		await sleep(10);
		h.btn().click();
		await sleep(10);
		check('widget — End voice ends it: the recorder released, back to Start voice',
			hf.releases >= 1 && h.role() === 'start' && h.mic().dataset.state === 'idle', show({ releases: hf.releases, role: h.role() }));
	});

	// ════ The console ════
	await run('console: the controls', async () => {
		const t = bootConsole();
		await sleep(5);
		const markup = /<button type="button" class="mic-btn" id="micBtn"[^>]*>/.exec(extract.phpToHtml(
			extract.element(extract.templateSource(), 'button', l => l.includes('id="micBtn"'), 'micBtn'), 'micBtn'));
		check('console, conversation off: the mic shows, named "Dictate" (its markup and its idle label); the round button is Start voice',
			!t.$('micBtn').hidden && !!markup && /aria-label="Dictate"/.test(markup[0]) && /title="Dictate"/.test(markup[0]) &&
				t.role() === 'start',
			show({ hidden: t.$('micBtn').hidden, markup: markup && markup[0], role: t.role() }));
	});

	await run('console: tap, speak, tap', async () => {
		const t = bootConsole();
		t.$('micBtn').click();
		await sleep(10);
		const r = t.recs.mic();
		const live = r && r.starts[0];
		check('console — a tap records on the mic\'s own recorder (no silence stop), on the host\'s live socket with no turn signal',
			!!r && r.recording && !!live && live.turn === false && live.url === 'wss://api.defho.test/live' && live.token === 'live-tok' &&
				t.$('micBtn').dataset.state === 'listening' && t.recs.handsFree().starts.length === 0,
			show({ live, state: t.$('micBtn').dataset.state }));
		t.type('Hi', 2);
		t.$('micBtn').click();
		await sleep(5);
		r.finish(SAID);
		await sleep(30);
		check('console — the second tap: the words in the box after what is there, the box focused, nothing sent',
			t.box().value === 'Hi book a table for two' && t.document.activeElement === t.box() && t.sent.length === 0 &&
				t.$('micBtn').dataset.state === 'idle' && t.$('micBtn').getAttribute('aria-label') === 'Dictate' && t.calls.length === 0,
			show({ value: t.box().value, sent: t.sent, calls: t.calls.length }));
		check('console — no pause ends it: the mic\'s recorder has no silence stop',
			typeof r.opts.onSilence !== 'function' && typeof r.opts.onAutoStop === 'function' && typeof r.opts.onIdle === 'function');
	});

	await run('console: the cursor', async () => {
		const t = bootConsole();
		t.type('Hello there', 5);
		await t.dictate({ transcript: 'big', spoke: true, seconds: 1 });
		check('console — the words go in at the cursor', t.box().value === 'Hello big there', t.box().value);
	});

	await run('console: the clip', async () => {
		const t = bootConsole();
		await t.dictate(CLIP);
		const c = t.transcribes()[0] || {};
		check('console — a clip: POST the host\'s voice/transcribe with the three audio fields; its text in the box',
			t.transcribes().length === 1 && c.method === 'POST' &&
				show(c.body) === show({ audio_base64: 'QUFBQQ==', audio_mime: 'audio/mp4', audio_seconds: 3.2 }) && t.box().value === 'from the clip',
			show({ c, value: t.box().value }));
	});

	const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
	for (const [why, answer, want] of [
		['422 nothing_heard', () => json(422, { detail: { error: 'nothing_heard', message: 'x' } }), { info: 'Nothing was heard. Try again a little closer to the microphone.' }],
		['502 transcription_failed', () => json(502, { code: 'staff_ai_service_error', message: 'Service error', error: 'transcription_failed' }), { info: 'That recording could not be transcribed. Please try again.' }],
		['502 voice_unavailable', () => json(502, { code: 'staff_ai_service_error', message: 'Service error', error: 'voice_unavailable' }), { info: "Voice isn't available right now. You can type your message below instead." }],
		['415 not_audio', () => json(415, { detail: { error: 'not_audio', message: 'x' } }), { info: 'That recording could not be transcribed. Please try again.' }],
		['502 with no error field', () => json(502, { code: 'staff_ai_service_error', message: 'The assistant is unavailable right now.' }), { error: 'The assistant is unavailable right now.' }],
	]) {
		await run('console: refusal ' + why, async () => {
			const t = bootConsole({ transcribe: answer });
			t.type('Keep this', 9);
			await t.dictate(CLIP);
			const got = want.info ? t.info() : t.error();
			check('console — refusal ' + why + ': ' + (want.info ? 'its line' : 'the service error, as elsewhere') + ', the box left as it was',
				got.indexOf(want.info || want.error) !== -1 && t.box().value === 'Keep this' && t.$('micBtn').dataset.state === 'idle',
				show({ info: t.info(), error: t.error(), value: t.box().value }));
		});
	}

	await run('console: the round button, conversation off', async () => {
		const t = bootConsole();
		t.$('sendBtn').click();
		await sleep(10);
		const hf = t.recs.handsFree();
		check('console, conversation off — Start voice starts today\'s hands-free voice; End voice shows, the mic stays as that voice\'s mic',
			hf.starts.length === 1 && t.recs.mic().starts.length === 0 && t.role() === 'end' && !t.$('micBtn').hidden,
			show({ hf: hf.starts.length, role: t.role(), hidden: t.$('micBtn').hidden }));
		t.$('sendBtn').click();
		await sleep(10);
		check('console — End voice ends it: released, back to Start voice', hf.releases >= 1 && t.role() === 'start' && !t.api.state().on,
			show({ releases: hf.releases, role: t.role() }));
	});

	console.log('harness-dictation: ' + pass + ' passed, ' + fail + ' failed');
	console.log(results.join('\n'));
	process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
