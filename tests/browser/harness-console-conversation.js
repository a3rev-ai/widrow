/*
 * Voice conversation mode in the Staff AI console. Runs the SHIPPED blocks, sliced by marker
 * from assets/js/staff-ai.js, in jsdom against a fake host whose conversation() records what
 * the console asks of it and hands back callbacks a check can fire. Its end() answers late
 * (or never), as a real host may. See tests/browser/README.md for what each case covers.
 */
const { JSDOM } = require('jsdom');
const fs = require('fs');
const extract = require('./extract');

const s = (label, start, end, needs) => extract.slice(label, start, end, needs);
const PARSER = s('SSE parser', l => l.includes('// SSE buffer parser'), l => l.includes('// Tool status labels for SSE streaming'), ['function parseSSEBuffer']);
const TOOL_LABELS = s('tool labels', l => l.includes('// Tool status labels for SSE streaming'), l => l.includes('// HTML escape helper'), ['const SSE_TOOL_PACING_MS']);
const ESCAPE = s('escapeHtml', l => l.includes('// HTML escape helper'), l => l.includes('// Markdown rendering'), ['function escapeHtml']);
const TOOL_ROWS = s('tool rows', l => l.includes('SSE STREAMING UI HELPERS'), l => l.startsWith('\tfunction updateTypingLabel('), ['function renderToolStatus']);
const REQUEST = s('request path', l => l.includes('// Every request that carries the nonce'), l => l.includes('\t// Elements'), ['async function apiRequest']);
const STATUS = s('status', l => l.includes('// Model switcher (per-session'), l => l.includes('// Header overflow menu'), ['handOverToPortal(data)']);
const LOAD = s('loadConversation', l => l.includes('\t// Load a specific conversation'), l => l.includes('\t// Update read-only state'), ['async function loadConversation']);
const NEW_CHAT = s('New chat', l => l.includes('// New chat (shared with'), l => l.startsWith("\tnewChatBtn.addEventListener('click'"), ['function resetToNewChat']);
const RENDER = s('renderMessages', l => l.includes('\t// Render messages'), l => l.includes('\t// Helper function to rewrite download URLs'), ['function renderMessages']);
const RECOVER = s('recoverTurn', l => l.includes("// V-S1's client half (7.6.9)"), l => l.includes('// ── C3b: the installed app keeps up'), ['async function recoverTurn']);
const INFO = s('info banner', l => l.includes('\t// Show info'), l => l.includes('// Smart scroll'), ['function showInfo', 'function showError']);
const BUTTON = s('send button', l => l.includes('// Update send button state.'), l => l.includes('// The Stop button end'), ['function updateSendButton']);
const CLICK = s('send button click', l => l.startsWith("\tsendBtn.addEventListener('click'"), l => l.includes('\t// Show info'), ['micTap()', 'requestStop()']);
const SEND = s('send path', l => l.startsWith('\tasync function sendMessage() {'), l => l.includes('// SHARE MODAL'), ['async function sendMessageStreaming', 'function showConfirmChip']);
const VOICE = extract.voice();
const RELEASE = extract.release();
const SHELL = extract.pageShell();
const CSS = fs.readFileSync(require('path').join(extract.REPO, 'assets/css/staff-ai.css'), 'utf8');

const DOOR = 'https://api.defho.test/api/tenant/acme/staff-ai';
const GRANT = 'room-grant-value-123';   // the mint's room token: it must reach no log
const ROOM_ADDR = 'wss://media.test/room';
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
const frame = (seq, evt) => 'id: ' + seq + '\ndata: ' + JSON.stringify(Object.assign({ thread_id: 'th-1', turn_id: 'msg-' + seq }, evt)) + '\n\n';
function sse(chunks) {
	const enc = new TextEncoder();
	let i = 0;
	return new Response(new ReadableStream({ pull(c) { if (i < chunks.length) c.enqueue(enc.encode(chunks[i++])); else c.close(); } }),
		{ status: 200, headers: { 'content-type': 'text/event-stream' } });
}

const HEAD = `
	let nonce = StaffAIConfig.nonce;
	let messages = [], isLoading = false, isReadOnly = false, lastSuggestion = null, currentConversationId = opts.thread || null,
		activeProjectId = null, _isStreaming = false, _userScrolledUp = false, dirtyInput = false, chipEpoch = 0,
		assistantName = 'Sue';
	const assistantNameSubscribers = [];
	function t(k, d) { return d; }
	function onAssistantName(fn) { fn(); }
`;
const TAIL = `
	initVoice();
	consolePages.push({ route: 'documents', el: document.getElementById('docsPage') });
	return {
		sendMessage: sendMessage, resetToNewChat: resetToNewChat, updateSendButton: updateSendButton, loadConversation: loadConversation, quietEnough: quietEnough, showPage: showPage,
		state: function () { return { talk: typeof talk === 'undefined' ? undefined : talk, thread: currentConversationId,
			messages: messages, on: conversationOn, mic: micBtn.dataset.state }; }
	};`;

function boot(opts) {
	opts = opts || {};
	const dom = new JSDOM('<!doctype html><div class="chat-container"><div id="messagesContainer"><div id="messagesList"></div></div>' +
		'<div id="infoBanner"></div><div id="errorBanner"></div></div><div id="confirmChips"></div>' +
		'<textarea id="composerInput" placeholder="Ask"></textarea><button id="sendBtn"></button>' +
		'<button id="micBtn"></button><span id="micLabel"></span><button id="voiceBtn"></button>' +
		'<select id="modelSelect" hidden></select><span id="modelChipName"></span><div id="welcomeMessage"></div>' +
		'<div id="composerContainer"></div><section id="docsPage" hidden></section>',
	{ url: 'https://acme.defho.test/staff-ai/acme/', pretendToBeVisual: true });
	const window = dom.window, document = window.document;
	const $ = id => document.getElementById(id);
	const logs = [];
	const spy = { info: m => logs.push(String(m)), error: (...a) => logs.push(a.map(x => JSON.stringify(x)).join(' ')), warn: (...a) => logs.push(a.join(' ')), log: (...a) => logs.push(a.join(' ')) };
	if (opts.webrtc !== false) window.RTCPeerConnection = function () {};
	window.matchMedia = q => ({ matches: q === '(pointer: coarse)' ? !!opts.phone : false, addEventListener() {} });
	try { window.localStorage.setItem('def_staff_ai_voice_mode', opts.mode || 'server'); } catch (e) {}
	if (opts.model) window.localStorage.setItem('staff-ai-model', opts.model);
	window.DefPersona = { createController: () => ({ handleEvent() {}, formatThinkingLabel: m => m, reset() {} }) };

	// Today's voice: a recorder that records a start, a speaker that records what it is given.
	const rec = { starts: 0 };
	const said = [], order = [];
	let idle = [];
	const speaker = {
		mode: 'server', setMode(m) { this.mode = m; }, getMode() { return this.mode; }, unlock() {}, stop() {},
		speak(text) { said.push(text); order.push('speak'); }, discard() {}, end() {},
		whenIdle() { return new Promise(r => idle.push(r)); },
	};
	window.DefVoice = {
		supported: () => true, micAllowedBySite: () => true, log: () => [], note: m => logs.push(String(m)),
		isStopPhrase: () => false,
		createSpeaker: () => speaker,
		// A phone that hands the mic over only inside a tap refuses a start outside one.
		createRecorder: o => (rec.opts = o, { isRecording: () => false, release() {}, stop: () => Promise.resolve(null),
			start: () => { rec.starts++; return opts.tapOnly && !inTap ? Promise.reject(new Error('needs a tap')) : Promise.resolve(true); } }),
	};

	// The fake host: every request, and each conversation's callbacks. Its end() answers
	// late, or never (opts.silent); opts.failAt fails inside conversation() itself.
	const calls = [], talks = [];
	let prepares = 0, inTap = false;
	const route = opts.route || (() => null);
	const conversation = function (minted, cb) {
		const c = { minted, cb, inTap, ended: 0, mutes: [], over: false };
		c.finish = (kind, error) => { if (c.over) return; c.over = true; cb.onState('idle'); error ? cb.onError(kind) : cb.onEnded(kind); };
		talks.push(c);
		order.push('conversation');
		cb.onState('connecting');
		if (opts.failAt) c.finish(opts.failAt, true);
		return { end() { c.ended++; if (!opts.silent) setTimeout(() => c.finish('end'), 5); }, mute(on) { c.mutes.push(on); order.push('mute:' + on); } };
	};
	if (!opts.noPrepare) conversation.prepare = () => { prepares++; return Promise.resolve(true); };
	const host = {
		fetch(url, init) {
			init = init || {};
			calls.push({ url, method: init.method || 'GET', body: init.body ? JSON.parse(init.body) : null, inTap });
			const own = route(url, init);
			if (own) return Promise.resolve(own);
			if (url === DOOR + '/status') return Promise.resolve(json(200, { voice_conversation: opts.status !== false, available_models: [{ id: 'm-fast', label: 'Fast' }, { id: 'm-deep', label: 'Deep' }] }));
			if (url === DOOR + '/voice/room') return Promise.resolve(json(200, { url: ROOM_ADDR, token: GRANT, room: 'rm-1', thread_id: opts.thread || 'th-new' }));
			if (url.indexOf(DOOR + '/voice/room/') === 0) return Promise.resolve(json(200, { ended: false, confirmations: opts.confirmations || [] }));
			if (url.indexOf(DOOR + '/conversations/') === 0) return Promise.resolve(json(200, { messages: [
				{ role: 'user', content: 'What is on today?' }, { role: 'assistant', content: 'The **written** reply' }] }));
			if (url === DOOR + '/chat/stream') return Promise.resolve(sse(opts.stream || [frame(1, { type: 'done', choices: [{ message: { content: 'Typed reply' } }] })]));
			return Promise.resolve(json(200, {}));
		},
		voice: true,
		...(opts.noConversation ? {} : { conversation }),
	};
	const StaffAIConfig = { nonce: 'n0', host: opts.wordpress ? undefined : host, statusUrl: DOOR + '/status' };
	window.StaffAIConfig = StaffAIConfig;
	const deps = {
		window, document, DefVoice: window.DefVoice, navigator: { language: 'en-AU', onLine: true }, console: spy, opts,
		fetch: () => Promise.reject(new Error('no network but the host')), Response, ReadableStream, TextDecoder, AbortController,
		setTimeout: (fn, ms) => setTimeout(fn, ms >= 1000 ? 0 : ms), setInterval: () => 0, clearInterval() {},
		StaffAIConfig, apiBase: DOOR, chatStreamUrl: DOOR + '/chat/stream', statusUrl: DOOR + '/status', location: { reload() {} },
		localStorage: window.localStorage, sessionStorage: window.sessionStorage,
		messagesList: $('messagesList'), messagesContainer: $('messagesContainer'), errorBanner: $('errorBanner'), infoBanner: $('infoBanner'),
		welcomeMessage: $('welcomeMessage'), confirmChips: $('confirmChips'), composerInput: $('composerInput'), sendBtn: $('sendBtn'),
		micBtn: $('micBtn'), micLabel: $('micLabel'), voiceBtn: $('voiceBtn'), composerContainer: $('composerContainer'), conversationList: null,
		handOverToPortal: () => false, autoResize() {}, hasActiveFiles: () => false, stagedFiles: [], showWelcomeOrTip() {},
		classifySuggestionOutcome: () => ({}), browserTimezone: () => '', clearStagedFiles() {}, uploadAllStagedFiles: async () => ({ success: true, fileIds: [] }),
		renderMarkdown: s => '<p>' + String(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>') + '</p>',
		applyCitations() {}, buildCitationMap: () => ({}), createToolOutputCard: () => null,
		appendFileAttachments() {}, storedAttachment() {}, appendFileIndicators() {},
		loadConversations() {}, rememberConversation() {}, updateReadOnlyState() {}, renderConversationList() {}, clearActiveProject() {},
		renderProjectChip() {}, sidebar: { classList: { contains: () => false } }, toggleSidebar() {},
		isSessionExpiredError: () => false, scrollToBottom() {},
	};
	const names = Object.keys(deps);
	const api = new window.Function(...names, HEAD + PARSER + '\n' + TOOL_LABELS + '\n' + ESCAPE + '\n' + REQUEST + '\n' + INFO + '\n' +
		RECOVER + '\n' + TOOL_ROWS + '\n' + BUTTON + '\n' + CLICK + '\n' + STATUS + '\n' + LOAD + '\n' + SHELL + '\n' + NEW_CHAT + '\n' + RELEASE + '\n' + RENDER + '\n' +
		VOICE + '\n' + SEND + TAIL)(...names.map(k => deps[k]));
	const orb = () => document.querySelector('.talk-orb');
	return {
		window, document, api, calls, talks, rec, said, order, logs, prepares: () => prepares, staged: deps.stagedFiles,
		last: () => talks[talks.length - 1],
		// The tap, and whether the mint and the conversation happened inside it, before any await.
		tap: () => { inTap = true; $('micBtn').click(); inTap = false; },
		// The button at the box's end, tapped; typing into the box (what its input listener does).
		press: () => { inTap = true; $('sendBtn').click(); inTap = false; },
		type: text => { $('composerInput').value = text; api.updateSendButton(); },
		// What shows: the button's role, name, tooltip and whether it is enabled, and whether the mic shows.
		shows: () => ({ role: $('sendBtn').dataset.voice || 'today', name: $('sendBtn').getAttribute('aria-label'),
			title: $('sendBtn').getAttribute('title'), enabled: !$('sendBtn').disabled, mic: !$('micBtn').hidden }),
		idle: () => { const r = idle; idle = []; r.forEach(f => f()); },
		info: () => $('infoBanner').classList.contains('visible') ? $('infoBanner').textContent : '',
		error: () => $('errorBanner').classList.contains('visible') ? $('errorBanner').textContent : '',
		orb, orbState: () => orb() && !orb().hidden ? orb().dataset.state : 'gone',
		bubbles: role => Array.from($('messagesList').querySelectorAll('.message-' + role + ' .message-content')),
		chips: () => Array.from($('confirmChips').querySelectorAll('.confirm-chip')),
		reads: () => calls.filter(c => c.url.indexOf(DOOR + '/voice/room/') === 0).length,
		streams: () => calls.filter(c => c.url === DOOR + '/chat/stream'),
		send: text => { $('composerInput').value = text; return api.sendMessage(); },
		hide: () => { Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true }); document.dispatchEvent(new window.Event('visibilitychange')); },
	};
}
const settle = async () => { for (let i = 0; i < 12; i++) await new Promise(r => setTimeout(r, 2)); };
async function started(opts) {
	const t = boot(opts);
	await settle();   // the status
	t.tap();
	await settle();   // the mint
	return t;
}

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
	if (cond) { pass++; results.push('  ok   ' + String(pass + fail).padStart(2) + '. ' + label); }
	else { fail++; results.push('  FAIL ' + String(pass + fail).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}
async function run(label, fn) {
	try { await fn(); } catch (e) { check(label, false, 'threw ' + (e && e.stack || e).toString().split('\n').slice(0, 2).join(' ')); }
}

(async function () {
	await run('entry', async () => {
		const t = boot({ thread: 'th-1', model: 'm-deep' });
		await settle();
		check('the status says voice_conversation and the host has conversation: prepare() once, before any tap',
			t.prepares() === 1 && t.talks.length === 0, 'prepares ' + t.prepares());
		t.tap();
		const mint = t.calls.find(c => c.url === DOOR + '/voice/room');
		check('a tap starts the conversation inside its own handler, the mint sent before any await',
			t.talks.length === 1 && t.talks[0].inTap && mint && mint.inTap && mint.method === 'POST' && t.rec.starts === 0,
			JSON.stringify({ talks: t.talks.length, mint }));
		check('the mint carries the open chat, the picked model and the browser language',
			JSON.stringify(mint.body) === JSON.stringify({ thread_id: 'th-1', model_id: 'm-deep', language: 'en-AU' }), JSON.stringify(mint.body));
		const minted = await t.talks[0].minted;
		check('the host is handed the mint\'s answer {url, token, room, thread_id}',
			minted.url === ROOM_ADDR && minted.token === GRANT && minted.room === 'rm-1', JSON.stringify(minted));
	});
	await run('new chat mint', async () => {
		const t = boot();
		await settle();
		t.tap();
		const mint = t.calls.find(c => c.url === DOOR + '/voice/room');
		await settle();
		check('no chat open: thread_id "" and the model the status picked; the room\'s new chat becomes the open one',
			mint.body.thread_id === '' && mint.body.model_id === 'm-fast' && t.api.state().thread === 'th-new', JSON.stringify({ body: mint.body, thread: t.api.state().thread }));
	});
	for (const [why, opts] of [['the status says it is off', { status: false }], ['the host has no conversation', { noConversation: true }],
		['the browser has no WebRTC', { webrtc: false }], ['the Voice button is on the device voice', { mode: 'device' }],
		['the Voice button is off', { mode: 'off' }]]) {
		await run('entry ' + why, async () => {
			const t = boot(opts);
			await settle();
			t.tap();
			await settle();
			check('the mic is today\'s voice when ' + why,
				t.talks.length === 0 && t.rec.starts === 1 && !t.calls.some(c => c.url === DOOR + '/voice/room') && t.orbState() === 'gone' &&
				(why !== 'the status says it is off' && why !== 'the host has no conversation' || t.prepares() === 0),
				JSON.stringify({ talks: t.talks.length, starts: t.rec.starts, prepares: t.prepares() }));
		});
	}
	await run('wordpress', async () => {
		const t = boot({ wordpress: true });
		await settle();
		t.tap();
		await settle();
		check('a WordPress site (no host): nothing changes, the mic is today\'s voice', t.talks.length === 0 && t.rec.starts === 1 && t.prepares() === 0);
	});

	await run('orb', async () => {
		const t = await started({ thread: 'th-1' });
		const c = t.last();
		const placed = t.orb() && t.orb().previousElementSibling && t.orb().previousElementSibling.id === 'messagesContainer';
		check('the orb sits under the chat, grey while connecting', placed && t.orbState() === 'connecting', t.orbState());
		c.cb.onState('listening');
		c.cb.onLevel(0.5, 0.9);
		const listening = [t.orbState(), t.orb().style.transform];
		c.cb.onLevel(0, 0.9);
		const still = t.orb().style.transform;
		c.cb.onState('speaking');
		c.cb.onLevel(0.9, 0.5);
		const speaking = [t.orbState(), t.orb().style.transform];
		check('listening: blue, moving with the person\'s voice; still when nobody speaks; speaking: moving with hers',
			listening[0] === 'listening' && listening[1] === 'scale(1.3)' && still === '' && speaking[0] === 'speaking' && speaking[1] === 'scale(1.3)',
			JSON.stringify({ listening, still, speaking }));
		const rule = sel => (new RegExp(sel.replace(/[.[\]"=]/g, '\\$&') + '\\s*\\{([^}]*)\\}').exec(CSS) || [])[1] || '';
		const reduced = /@media \(prefers-reduced-motion: reduce\) \{\s*\/\*[^*]*\*\/\s*\.talk-orb \{\s*transform: none !important;/.test(CSS);
		check('colours: grey connecting (and before any state), blue listening, orange speaking; it does not move under reduced motion',
			/#9ca3af/.test(rule('.talk-orb')) && /#2563eb/.test(rule('.talk-orb[data-state="listening"]')) &&
			/#f97316/.test(rule('.talk-orb[data-state="speaking"]')) && reduced);
	});

	await run('text', async () => {
		const t = await started({ thread: 'th-1' });
		const c = t.last();
		c.cb.onHeard('What is <img src=x onerror=alert(1)>', false);
		c.cb.onHeard('What is <img src=x onerror=alert(1)> on', true);
		c.cb.onHeard('today', false);
		const user = t.bubbles('user');
		check('the person\'s words fill their message as text; a final segment is kept and the next joins it',
			user.length === 1 && user[0].textContent.endsWith('What is <img src=x onerror=alert(1)> on today') && !t.document.querySelector('img'),
			user.map(u => u.textContent).join('|'));
		c.cb.onCaption('Here is **bold** <b>news</b>', false);
		const her = t.bubbles('assistant');
		check('her words show as she says them, as text, never markup',
			her.length === 1 && her[0].textContent === 'Here is **bold** <b>news</b>' && !her[0].querySelector('b,strong'), her.map(h => h.innerHTML).join('|'));
		c.cb.onState('speaking');
		c.cb.onState('listening');
		check('her speaking state ending reads nothing: the read waits for onReply', t.reads() === 0);
		c.cb.onNotice('not_understood');
		check('"not understood" offers typing, in the console\'s words', /type your message/.test(t.info()), t.info());
	});

	await run('reply read', async () => {
		const t = await started({ thread: 'th-1', confirmations: ['Delete the <b>draft</b> invoice?'] });
		const c = t.last();
		c.cb.onHeard('Delete the draft', true);
		c.cb.onCaption('Shall I delete it?', true);
		c.cb.onReply();
		await settle();
		const chip = t.chips();
		check('after the reply: one read of the room, its confirmation a chip, as text',
			t.reads() === 1 && t.calls.some(c => c.url === DOOR + '/voice/room/rm-1') && chip.length === 1 &&
			chip[0].querySelector('.confirm-chip-text').textContent === 'Delete the <b>draft</b> invoice?' && !chip[0].querySelector('b'),
			JSON.stringify({ reads: t.reads(), chips: chip.length }));
		const her = t.bubbles('assistant');
		check('then the chat is read again: her spoken words give way to the written reply',
			her.length === 1 && her[0].querySelector('strong') && her[0].textContent === 'The written reply' &&
			t.calls.some(c => c.url === DOOR + '/conversations/th-1'), her.map(h => h.innerHTML).join('|'));
		c.cb.onHeard('yes', true);
		await settle();
		check('a spoken "yes" never confirms: no message sent, the chip stays', t.streams().length === 0 && t.chips().length === 1);
		t.chips()[0].querySelector('.modal-btn-primary').click();
		await settle();
		const typed = t.streams()[0];
		check('a tap on the chip is a typed message: it confirms, and the conversation stays on',
			typed && typed.body.messages.slice(-1)[0].content === 'Delete the <b>draft</b> invoice?' && !typed.body.via_voice &&
			typed.body.messages.every(m => m.content !== 'yes') && c.ended === 0 && t.api.state().talk,
			JSON.stringify(typed && typed.body));
		c.finish('goodbye');
		await settle();
		check('once more when the conversation ends, and at no other time', t.reads() === 2, 'reads ' + t.reads());
	});

	await run('talk over', async () => {
		const t = await started({ thread: 'th-1' });
		const c = t.last();
		c.cb.onCaption('The answer is', true);
		c.cb.onHeard('wait, actually', false);   // the person talks over her
		c.cb.onReply();
		await settle();
		const user = t.bubbles('user').map(u => u.textContent);
		check('talking over her: the reload keeps their words in progress below the written reply',
			user.length === 2 && user[1].endsWith('wait, actually'), JSON.stringify(user));
	});

	await run('typing', async () => {
		const speech = n => frame(n, { type: 'speech', text: 'Line ' + n, audio_base64: 'AAAA', mime: 'audio/mpeg', seq: n, step: 1 });
		const t = await started({ thread: 'th-1', stream: [speech(1), speech(2), frame(3, { type: 'done', choices: [{ message: { content: 'Typed reply' } }] })] });
		const c = t.last();
		await t.send('What else?');
		await settle();
		const body = t.streams()[0].body;
		check('typing while it is on: the typed route, with speech_out: true; the conversation stays on',
			body.speech_out === true && !body.via_voice && c.ended === 0 && t.api.state().talk && t.api.state().mic === 'conversation', JSON.stringify(body));
		check('that reply\'s speech plays, the room muted before it', JSON.stringify(t.said) === '["Line 1","Line 2"]' &&
			t.order.indexOf('mute:true') > -1 && t.order.indexOf('mute:true') < t.order.indexOf('speak'), JSON.stringify(t.order));
		const mutedWhilePlaying = JSON.stringify(c.mutes);
		t.idle();
		await settle();
		check('unmuted once her speech is over', mutedWhilePlaying === '[true]' && JSON.stringify(c.mutes) === '[true,false]', JSON.stringify(c.mutes));
	});
	await run('reply during typing', async () => {
		const enc = new TextEncoder();
		let ctrl;
		const held = new Response(new ReadableStream({ start(c) { ctrl = c; } }), { status: 200, headers: { 'content-type': 'text/event-stream' } });
		const t = await started({ thread: 'th-1', route: url => url === DOOR + '/chat/stream' ? held : null });
		const c = t.last();
		const sending = t.send('What else?');
		await settle();
		ctrl.enqueue(enc.encode(frame(1, { type: 'text_delta', text: 'Typed ', step: 1 })));
		await settle();
		c.cb.onCaption('and one more thing', true);   // her words arrive while the typed reply streams
		c.cb.onReply();   // her spoken reply ends while the typed one streams
		await settle();
		const during = t.calls.filter(x => x.url === DOOR + '/conversations/th-1').length;
		const streaming = !!t.document.querySelector('#messagesList .message-streaming');   // the typed reply's bubble, still attached
		ctrl.enqueue(enc.encode(frame(2, { type: 'done', choices: [{ message: { content: 'Typed reply' } }] })));
		ctrl.close();
		await sending;
		await settle();
		const after = t.calls.filter(x => x.url === DOOR + '/conversations/th-1').length;
		check('her words or a reply ending while a typed reply streams never redraw over it; the chat is read once it is over',
			t.reads() === 1 && during === 0 && streaming && after === 1, JSON.stringify({ during, after, streaming }));
	});
	await run('typing error', async () => {
		const t = await started({ thread: 'th-1', stream: [frame(1, { type: 'speech', text: 'Line', audio_base64: 'AAAA', mime: 'audio/mpeg', seq: 1, step: 1 }),
			frame(2, { type: 'error', message: 'Down' })] });
		const c = t.last();
		await t.send('What else?');
		await settle();
		t.idle();
		await settle();
		check('a typed reply that fails still unmutes the room', JSON.stringify(c.mutes) === '[true,false]', JSON.stringify(c.mutes));
	});
	await run('typing today', async () => {
		const t = boot({ thread: 'th-1', stream: [frame(1, { type: 'speech', text: 'Line', audio_base64: 'AAAA', mime: 'audio/mpeg', seq: 1, step: 1 }),
			frame(2, { type: 'done', choices: [{ message: { content: 'Typed reply' } }] })] });
		await settle();
		await t.send('What else?');
		await settle();
		check('with no conversation, a typed message is as today: no speech asked for, none played',
			t.streams()[0].body.speech_out === undefined && t.said.length === 0, JSON.stringify(t.streams()[0].body));
	});

	const ENDINGS = [['goodbye', false, 'Conversation ended.'], ['stop_phrase', false, 'Conversation ended.'], ['end', false, 'Conversation ended.'],
		['time_limit', false, 'The conversation reached its time limit. Tap the button to start a new one.'],
		['silence', false, 'Voice stopped unexpectedly. Tap the button to start again.'], ['refused', false, 'Voice stopped unexpectedly. Tap the button to start again.'],
		['unknown', false, 'Voice stopped unexpectedly. Tap the button to start again.'], ['mint', true, 'Voice stopped unexpectedly. Tap the button to start again.'],
		['microphone', true, 'The microphone is blocked for this site in your browser. Allow it in the site permissions (the icon beside the address bar) and try again.'],
		['dropped', true, 'Voice stopped unexpectedly. Tap the button to start again.']];
	for (const [kind, error, line] of ENDINGS) {
		await run('ending ' + kind, async () => {
			const t = await started({ thread: 'th-1' });
			t.last().finish(kind, error);
			await settle();
			t.last().cb.onCaption('late words', false);   // a callback after the ending is ignored
			const info = kind === 'microphone' ? t.error() : t.info(), orb = t.orbState(), mic = t.api.state().mic;
			t.tap();
			check((error ? 'onError ' : 'onEnded ') + kind + ': "' + line + '", the orb gone, and the next tap starts a conversation again',
				info === line && orb === 'gone' && mic === 'idle' && t.bubbles('assistant').every(b => b.textContent !== 'late words') &&
				t.talks.length === 2 && t.rec.starts === 0, JSON.stringify({ info, orb, mic, talks: t.talks.length }));
		});
	}
	for (const kind of ['connect', 'load']) {
		await run('ending ' + kind, async () => {
			const t = await started({ thread: 'th-1' });
			t.last().finish(kind, true);
			await settle();
			const line = t.info(), starts = t.rec.starts;
			t.tap();       // today's voice listens
			await settle();
			t.tap();       // and a tap ends it, as today
			t.tap();       // and the next listens again
			await settle();
			check('onError ' + kind + ': "unavailable", and today\'s voice for the rest of the page\'s life',
				line === 'Voice conversation is unavailable right now. Tap the mic to talk.' && starts === 0 && t.talks.length === 1 && t.rec.starts === 2,
				JSON.stringify({ line, starts, talks: t.talks.length, after: t.rec.starts }));
		});
	}
	await run('busy', async () => {
		const t = await started({ thread: 'th-1' });
		t.last().finish('busy', true);
		await settle();
		const line = t.info(), starts = t.rec.starts;
		check('onError busy: a short line, and today\'s voice for this tap', /busy/.test(line) && starts === 1, JSON.stringify({ line, starts }));
	});
	await run('busy next tap', async () => {
		const t = await started({ thread: 'th-1' });
		t.last().finish('busy', true);
		await settle();
		t.api.resetToNewChat();   // today's voice ends (the conversation it fell back to)
		t.tap();
		check('after busy, the next tap tries a conversation again', t.talks.length === 2, 'talks ' + t.talks.length);
	});
	await run('busy needs a tap', async () => {
		const t = await started({ thread: 'th-1', tapOnly: true });
		t.last().finish('busy', true);
		await settle();
		const line = t.info();
		t.tap();
		await settle();
		const next = [t.talks.length, t.rec.starts];
		t.tap();   // ends today's voice
		t.tap();
		check('busy where today\'s voice wants a tap: the busy line, the next tap is today\'s voice once, the one after tries a conversation',
			/busy/.test(line) && next[0] === 1 && next[1] === 2 && t.talks.length === 2, JSON.stringify({ line, next, talks: t.talks.length }));
	});
	await run('mic ends', async () => {
		const t = await started({ thread: 'th-1' });
		t.tap();
		check('a tap on the mic while it is on ends it', t.last().ended === 1 && t.info() === 'Conversation ended.' && t.talks.length === 1);
	});

	await run('hidden phone', async () => {
		const t = await started({ thread: 'th-1', phone: true });
		t.hide();
		check('on a phone, a hidden page ends it', t.last().ended === 1);
	});
	await run('hidden laptop', async () => {
		const t = await started({ thread: 'th-1' });
		t.hide();
		check('on a laptop, another tab does not', t.last().ended === 0 && t.api.state().talk);
	});
	await run('update held', async () => {
		const t = await started({ thread: 'th-1' });
		const held = t.api.quietEnough();
		t.tap();
		check('the update-on-return is held while one is open', t.talks.length === 1 && t.rec.starts === 0 && held === false && t.api.quietEnough() === true);
	});
	await run('chat change', async () => {
		const t = await started({ thread: 'th-1' });
		await t.api.loadConversation('th-2');
		const opened = t.last().ended;
		const u = await started({ thread: 'th-1' });
		u.api.resetToNewChat();
		check('opening another chat, or New chat, ends it: the room is bound to its chat', opened === 1 && u.last().ended === 1);
	});

	await run('host ends late', async () => {
		const t = await started({ thread: 'th-1' });
		const c = t.last();
		t.tap();
		const now = [t.api.state().talk, t.api.state().mic, t.orbState(), t.info()];
		await settle();   // the host's own onEnded, late
		c.cb.onEnded('goodbye');
		c.cb.onError('dropped');
		await settle();
		check('a tap ends it at once, before the host answers; a late or second answer changes nothing',
			now[0] === null && now[1] === 'idle' && now[2] === 'gone' && now[3] === 'Conversation ended.' && c.ended === 1 &&
			t.info() === 'Conversation ended.' && t.reads() === 1, JSON.stringify({ now, info: t.info(), reads: t.reads() }));
	});
	await run('host never answers', async () => {
		const t = await started({ thread: 'th-1', silent: true });
		t.tap();
		const quiet = t.api.quietEnough();
		t.tap();
		check('a host that never calls back: the update is no longer held, and the next tap starts a conversation',
			quiet === true && t.talks.length === 2 && t.talks[0].ended === 1, JSON.stringify({ quiet, talks: t.talks.length }));
	});
	await run('switch while connecting', async () => {
		let answer;
		const t = boot({ thread: 'th-1', route: url => url === DOOR + '/voice/room' ? new Promise(r => { answer = r; }) : null });
		await settle();
		t.tap();
		await t.api.loadConversation('th-2');
		answer(json(200, { url: ROOM_ADDR, token: GRANT, room: 'rm-1', thread_id: 'th-new' }));
		await settle();
		check('another chat opened while connecting: the mint\'s answer does not take its place', t.api.state().thread === 'th-2', t.api.state().thread);
	});
	await run('chip after switch', async () => {
		const held = [];
		const t = await started({ thread: 'th-1', route: url => url.indexOf(DOOR + '/voice/room/') === 0 ? new Promise(r => held.push(r)) : null });
		t.last().cb.onReply();   // the room read is on its way
		await t.api.loadConversation('th-2');
		held.forEach(r => r(json(200, { ended: false, confirmations: ['Delete the draft invoice?'] })));
		await settle();
		check('a confirmation read for a chat no longer open shows no chip', held.length > 0 && t.chips().length === 0, 'chips ' + t.chips().length);
	});
	await run('fails at once', async () => {
		const t = await started({ thread: 'th-1', failAt: 'connect' });
		const st = t.api.state();
		t.tap();
		check('a host that fails inside conversation() leaves no conversation behind: the next tap is today\'s voice',
			st.talk === null && st.mic === 'idle' && t.talks.length === 1 && t.rec.starts === 1, JSON.stringify({ talk: !!st.talk, starts: t.rec.starts }));
	});
	await run('leave the chat', async () => {
		const t = await started({ thread: 'th-1' });
		t.api.showPage('documents');
		const u = await started({ thread: 'th-1' });
		u.window.dispatchEvent(new u.window.Event('pagehide'));   // signing out, or the session gone
		check('another console page, or leaving the page, ends it', t.last().ended === 1 && !t.api.state().talk && u.last().ended === 1 && !u.api.state().talk);
	});
	await run('older host', async () => {
		const t = await started({ thread: 'th-1', noPrepare: true });
		const mint = t.calls.find(c => c.url === DOOR + '/voice/room');
		check('a host with no prepare(): the status is read in full and the tap starts a conversation',
			t.talks.length === 1 && mint && mint.body.model_id === 'm-fast', JSON.stringify(mint && mint.body));
	});

	await run('no logs', async () => {
		const t = await started({ thread: 'th-1', confirmations: ['Yes'] });
		const c = t.last();
		c.cb.onHeard('hello', true);
		c.cb.onReply();
		await settle();
		c.finish('dropped', true);
		await settle();
		const leak = t.logs.filter(l => l.indexOf(GRANT) > -1 || l.indexOf(ROOM_ADDR) > -1);
		check('no token or room address in any log', leak.length === 0, JSON.stringify(leak));
	});

	// ── The button at the box's end: Start voice / Send / End voice where the mode is on ──
	const J = JSON.stringify;
	const TODAY = { role: 'today', name: 'Send message', title: null, enabled: false, mic: true };
	await run('start voice', async () => {
		const t = boot({ thread: 'th-1' });
		await settle();
		check('mode on, box empty: one button "Start voice" (name and tooltip), enabled; no mic',
			J(t.shows()) === J({ role: 'start', name: 'Start voice', title: 'Start voice', enabled: true, mic: false }), J(t.shows()));
		t.press();
		const mint = t.calls.find(c => c.url === DOOR + '/voice/room');
		check('a tap on Start voice starts the conversation inside its own handler, the mint sent before any await',
			t.talks.length === 1 && t.talks[0].inTap && mint && mint.inTap && t.rec.starts === 0, J({ talks: t.talks.length, mint }));
		await settle();
		check('in a conversation, box empty: the same button is "End voice"; no mic',
			J(t.shows()) === J({ role: 'end', name: 'End voice', title: 'End voice', enabled: true, mic: false }), J(t.shows()));
		check('the hint and the mic\'s name name the button, not the mic',
			t.document.getElementById('micLabel').textContent === 'Speak, or type. Tap the button to end.' &&
			t.document.getElementById('micBtn').getAttribute('aria-label') === 'Speak, or type. Tap the button to end.', t.document.getElementById('micLabel').textContent);
		t.type('What else?');
		check('in a conversation, words typed: the same button is Send', J(t.shows()) === J({ role: 'send', name: 'Send message', title: null, enabled: true, mic: false }), J(t.shows()));
		t.press();
		await settle();
		check('…and it sends, the conversation kept on; the box empty again: End voice',
			t.streams().length === 1 && t.streams()[0].body.messages.slice(-1)[0].content === 'What else?' && t.last().ended === 0 &&
			t.api.state().talk && t.shows().role === 'end', J({ streams: t.streams().length, ended: t.last().ended, now: t.shows() }));
		t.press();
		await settle();
		check('End voice ends it, as the mic tap did; back to Start voice', t.last().ended === 1 && !t.api.state().talk && t.shows().role === 'start',
			J({ ended: t.last().ended, now: t.shows() }));
	});
	await run('start voice typing', async () => {
		const t = boot({ thread: 'th-1' });
		await settle();
		t.type('Hello');
		check('mode on, words typed: Send', J(t.shows()) === J({ role: 'send', name: 'Send message', title: null, enabled: true, mic: false }), J(t.shows()));
		t.type('  ');
		check('the box cleared: Start voice again', t.shows().role === 'start' && t.shows().name === 'Start voice', J(t.shows()));
		t.type('Hello');
		t.press();
		await settle();
		check('Send sends a typed message, no conversation', t.streams().length === 1 && t.talks.length === 0 && t.shows().role === 'start',
			J({ streams: t.streams().length, talks: t.talks.length, now: t.shows() }));
	});
	for (const [why, opts] of [['the mode is off', { status: false }], ['the Voice button is on the device voice', { mode: 'device' }],
		['a WordPress site with no host', { wordpress: true }]]) {
		await run('today ' + why, async () => {
			const t = boot(opts);
			await settle();
			t.type('');
			const empty = t.shows();
			t.type('Hello');
			const typed = t.shows();
			check('where ' + why + ': today\'s Send and today\'s mic, unchanged', J(empty) === J(TODAY) &&
				J(typed) === J(Object.assign({}, TODAY, { enabled: true })), J({ empty, typed }));
		});
	}
	await run('voice button', async () => {
		const t = boot({ mode: 'off' });
		await settle();
		const before = t.shows().role;
		t.document.getElementById('voiceBtn').click();   // off → her voice
		check('the Voice button turned to her voice: Start voice appears', before === 'today' && t.shows().role === 'start', J({ before, now: t.shows() }));
	});
	await run('start voice failed', async () => {
		const t = boot({ thread: 'th-1', failAt: 'connect' });
		await settle();
		t.press();
		await settle();
		check('the mode fails for the page: today\'s Send and mic from then on, told in today\'s words (the mic)',
			J(t.shows()) === J(TODAY) && t.info() === 'Voice conversation is unavailable right now. Tap the mic to talk.', J(t.shows()) + ' ' + t.info());
	});
	await run('start voice busy', async () => {
		const t = boot({ thread: 'th-1' });
		await settle();
		t.press();
		t.last().finish('busy', true);
		await settle();
		check('the busy fallback: the tap goes on as today\'s voice, said as before, with today\'s mic to end it',
			t.rec.starts === 1 && /busy/.test(t.info()) && t.shows().role === 'today' && t.shows().mic, J(t.shows()));
		t.tap();   // ends today's voice
		await settle();
		check('…and the next tap is Start voice again', t.shows().role === 'start', J(t.shows()));
	});
	await run('start voice busy needs a tap', async () => {
		const t = boot({ thread: 'th-1', tapOnly: true });
		await settle();
		t.press();
		t.last().finish('busy', true);
		await settle();
		check('busy where today\'s voice wants a tap: today\'s mic for that next tap', t.shows().role === 'today' && t.shows().mic, J(t.shows()));
	});

	// A typed reply held open, its frames pushed by the check.
	const held = () => {
		const enc = new TextEncoder();
		let ctrl;
		const route = url => url === DOOR + '/chat/stream' ? new Response(new ReadableStream({ start(c) { ctrl = c; } }),
			{ status: 200, headers: { 'content-type': 'text/event-stream' } }) : null;
		return { route, push: f => ctrl.enqueue(enc.encode(f)), close: () => ctrl.close() };
	};
	await run('stop in a conversation', async () => {
		const live = held();
		const t = boot({ thread: 'th-1', route: live.route });
		await settle();
		t.press();
		await settle();
		t.type('What else?');
		t.press();
		await settle();
		live.push(frame(1, { type: 'text_delta', text: 'Here', step: 1 }));
		await settle();
		check('a typed reply streaming in a conversation: the button is Stop, and the mic shows as today\'s end control',
			J(t.shows()) === J({ role: 'today', name: 'Stop', title: null, enabled: true, mic: true }), J(t.shows()));
		t.press();
		await settle();
		check('…Stop stops the reply and keeps the conversation on', t.calls.some(c => /\/turns\/msg-1\/stop$/.test(c.url)) && t.last().ended === 0,
			J(t.calls.map(c => c.url)));
		live.push(frame(2, { type: 'done', choices: [{ message: { content: 'Here' } }] }));
		live.close();
		await settle();
		check('…and the reply over, End voice is back and the mic hidden', J(t.shows()) === J({ role: 'end', name: 'End voice', title: 'End voice', enabled: true, mic: false }), J(t.shows()));
	});
	await run('mic ends while a typed reply streams', async () => {
		const live = held();
		const t = boot({ thread: 'th-1', route: live.route });
		await settle();
		t.press();
		await settle();
		t.type('What else?');
		t.press();
		await settle();
		live.push(frame(1, { type: 'text_delta', text: 'Here', step: 1 }));
		await settle();
		t.tap();
		await settle();
		check('…the mic ends the conversation then', t.last().ended === 1, 'ended ' + t.last().ended);
		live.close();
		await settle();
	});
	await run('file chips', async () => {
		const t = boot({ thread: 'th-1' });
		await settle();
		for (const status of ['failed', 'uploading']) {
			t.staged.length = 0;
			t.staged.push({ status });
			t.type('');
			check('a ' + status + ' file chip with the box empty: today\'s Send and mic', t.shows().role === 'today' && t.shows().name === 'Send message' && t.shows().mic, J(t.shows()));
		}
		t.staged.length = 0;
		t.staged.push({ status: 'uploaded' });
		t.type('');
		check('an uploaded file chip with the box empty: Send', t.shows().role === 'send', J(t.shows()));
	});
	await run('suggestion', async () => {
		const t = boot({ thread: 'th-1' });
		await settle();
		t.document.getElementById('composerInput').classList.add('staff-ai-suggestion-text');
		t.type('Tell me more');
		check('a suggestion in the box: Send, enabled, as today', J(t.shows()) === J({ role: 'send', name: 'Send message', title: null, enabled: true, mic: false }), J(t.shows()));
	});
	await run('fallback lines and focus', async () => {
		const t = boot({ thread: 'th-1' });
		await settle();
		t.press();
		t.last().finish('busy', true);
		await settle();
		t.document.getElementById('micBtn').focus();
		t.rec.opts.onIdle();
		await settle();
		check('the busy fallback closes quietly: the line names the button', t.info() === 'The mic closed. Tap the button to speak again.', t.info());
		check('the focused mic hidden as Start voice takes over: focus moves to the button',
			t.document.activeElement === t.document.getElementById('sendBtn') && t.shows().role === 'start', String(t.document.activeElement && t.document.activeElement.id));
		const off = boot({ status: false });
		await settle();
		off.tap();
		await settle();
		off.rec.opts.onIdle();
		await settle();
		check('mode off: today\'s line, naming the mic', off.info() === 'The mic closed — tap it to speak again.', off.info());
	});

	console.log('Console conversation mode (harness-console-conversation.js)');
	results.forEach(r => console.log(r));
	console.log(pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})();
