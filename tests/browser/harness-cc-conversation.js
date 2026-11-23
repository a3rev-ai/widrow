/*
 * Customer Chat's conversation mode — the widget's SHIPPED module whole in jsdom (as
 * harness-direct-stream.js runs it), against a fake LiveKit client and a scripted
 * platform: the voice status, the stream-token route, the room mint, the after-reply
 * read, the typed stream and the shop's cart.
 *
 * What it holds: the mic opens a conversation only when the status says `conversation`,
 * the browser has WebRTC and the site allows the microphone, otherwise today's voice; the
 * client is loaded at the status, only when the mode is on; inside the tap the audio is
 * unlocked, both elements primed, the wake lock asked, the room made and its audio
 * started before the mint is sent; the mint's address and body; a room that made a new
 * chat gets a token for it before the read; the orb's states; the visitor's line once;
 * her words as text, then the read's written reply, product card and add to cart; a
 * typed message keeps the room, asks for speech and mutes the room's microphone while
 * its reply plays, after her spoken reply; `not_understood` offers typing; each ending;
 * a rejected mint and a room that cannot connect fall back, the second for the page's
 * life with its cause in the voice log; nothing arrives after the end.
 *
 * Bite check: CC_MODULE=<a scratch copy of the widget> runs it against that file.
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
const show = v => JSON.stringify(v);
const sleep = ms => new Promise(r => setTimeout(r, ms));

const MODULE = extract.customerChatSource();
const VENDOR = ['assets/js/def-persona.js', 'assets/js/vendor/marked.min.js', 'assets/js/vendor/purify.min.js',
  'assets/js/widrow-ai-product-cards.js'].map(f => fs.readFileSync(path.join(extract.REPO, f), 'utf8'));
const LIVEKIT = 'https://shop.test/wp-content/plugins/widrow-ai/assets/js/vendor/livekit-client-2.21.0/livekit-client.umd.js?ver=1';
const CC = {
  apiBaseUrl: 'https://api.example.test',
  chatStreamUrl: 'https://shop.test/wp-json/def-core/v1/chat/stream',
  streamTokenUrl: 'https://shop.test/wp-json/def-core/v1/chat/stream-token',
  wpRestUrl: 'https://shop.test/wp-json/def-core/v1/',
  wpRestRoot: 'https://shop.test/wp-json/',
  restUrl: 'https://shop.test/wp-json/def-core/v1/context-token',
  loginUrl: 'https://shop.test/wp-admin/admin-ajax.php',
  nonce: 'n0nce', wpRestNonce: 'n0nce', assistantName: 'Joe', isLoggedIn: false, liveKitUrl: LIVEKIT,
};
const DIRECT = 'https://api.example.test/api/chat/stream';
const ROOM = 'https://api.example.test/api/customer/voice/room';
const CART = 'https://shop.test/wp-json/wc/store/v1/cart/add-item';
const NONE = { dataset: {}, style: {}, textContent: '' };   // an element the widget did not draw
const CARD_REPLY = { ended: false, reply: { content: 'The **drill** is in stock.', tool_outputs: [
  { type: 'wp_rest_call', endpoint: 'wc/store/v1/cart/add-item', method: 'POST', tool_name: 'add_to_cart',
    tool_call_id: 'call-1', body: { id: 5, quantity: 1 }, success_message: 'Added.' },
  { result_type: 'wp_product', section_heading: 'Drills',
    result_cards: [{ id: 5, title: 'Cordless Drill', url: 'https://shop.test/p/drill' }] },
] } };

// The client's surface the widget uses, recording what is called and in what order.
function fakeLiveKit(w, h) {
  const DisconnectReason = { UNKNOWN_REASON: 0, CLIENT_INITIATED: 1, 0: 'UNKNOWN_REASON', 1: 'CLIENT_INITIATED' };
  function Room() {
    const r = this;
    h.rooms.push(r);
    h.order.push('room');
    Object.assign(r, { handlers: {}, text: {}, state: 'disconnected', mic: [] });
    r.localParticipant = { identity: 'visitor', isSpeaking: false, audioLevel: 0,
      setMicrophoneEnabled: (on, opts) => {
        r.mic.push(opts ? { on, opts } : on);
        return h.micFails ? Promise.reject(Object.assign(new Error('no'), { name: 'NotAllowedError' })) : Promise.resolve();
      } };
    r.her = { identity: 'agent', isSpeaking: false, audioLevel: 0 };
    r.remoteParticipants = new Map([['agent', r.her]]);
    r.on = (event, fn) => { r.handlers[event] = fn; return r; };
    r.emit = (event, ...args) => r.handlers[event] && r.handlers[event](...args);
    r.startAudio = () => { h.order.push('startAudio'); return Promise.resolve(); };
    r.registerTextStreamHandler = (topic, fn) => { r.text[topic] = fn; };
    r.connect = (url, token) => {
      r.connectedWith = { url, token };
      if (h.connectFails) return Promise.reject(Object.assign(new Error('could not establish signal connection'), { name: 'ConnectionError' }));
      r.state = 'connected';
      return Promise.resolve();
    };
    r.disconnect = () => {
      if (r.state === 'disconnected' && r.closed) return Promise.resolve();
      r.closed = true; r.state = 'disconnected'; h.disconnects++;
      r.emit('disconnected', DisconnectReason.CLIENT_INITIATED);
      return Promise.resolve();
    };
    // The server closes the room: after her announced ending, or as a fault.
    r.close = () => { r.closed = true; r.state = 'disconnected'; r.emit('disconnected', DisconnectReason.UNKNOWN_REASON); };
    r.data = (topic, obj) => r.emit('dataReceived', new w.TextEncoder().encode(JSON.stringify(obj)), r.her, 0, topic);
    // Her words as a text stream: say() a piece, close() the segment.
    r.words = from => {
      const queue = [], waiting = [];
      const feed = v => (waiting.length ? waiting.shift()(v) : queue.push(v));
      r.text['lk.transcription']({ [Symbol.asyncIterator]: () => ({
        next: () => (queue.length ? Promise.resolve(queue.shift()) : new Promise(res => waiting.push(res))) }) },
        { identity: from || 'agent' });
      return { say: t => feed({ done: false, value: t }), close: () => feed({ done: true }) };
    };
  }
  return { Room, RoomEvent: { TrackSubscribed: 'trackSubscribed', TrackUnsubscribed: 'trackUnsubscribed',
    DataReceived: 'dataReceived', Disconnected: 'disconnected' },
    Track: { Kind: { Audio: 'audio' } }, ConnectionState: { Disconnected: 'disconnected' }, DisconnectReason };
}

async function boot(opts) {
  opts = opts || {};
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="host"></div></body></html>',
    { url: 'https://shop.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  extract.stubMatchMedia(w);
  const realST = w.setTimeout.bind(w);
  w.setTimeout = (fn, ms) => realST(fn, (ms || 0) >= 1000 ? ms : Math.min(ms || 0, 1));
  for (const src of VENDOR) w.eval(src);
  w.ReadableStream = function () {};
  for (const k of Object.keys(opts.storage || {})) w.localStorage.setItem(k, opts.storage[k]);
  w.WidrowAiPageContextHelper = { build: thread => ({ canonical_path: '/', for: thread }), markShipped() {} };
  const h = { w, order: [], rooms: [], mints: [], roomMints: [], reads: [], typed: [], carts: [], starts: [], spoken: [],
    notes: [], disconnects: 0, readReply: opts.readReply || CARD_REPLY, roomStatus: 200, typedEvents: null };
  if (opts.webrtc !== false) w.RTCPeerConnection = function () {};
  w.AudioContext = function () {
    h.order.push('unlock');
    Object.assign(this, { state: 'running', destination: {},
      createBufferSource: () => ({ connect() {}, start() { h.order.push('silence'); } }),
      createBuffer: () => ({}), resume: () => Promise.resolve(),
      createMediaStreamDestination: () => ({ stream: {} }), close: () => { h.closed = (h.closed || 0) + 1; return Promise.resolve(); } });
  };
  w.HTMLMediaElement.prototype.play = function () { h.order.push('prime'); return Promise.resolve(); };
  Object.defineProperty(w.navigator, 'wakeLock', { configurable: true,
    value: { request: () => { h.order.push('wakeLock'); return Promise.resolve({ release() { h.wakeReleased = true; } }); } } });
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
      h.mints.push(JSON.parse(init.body).thread);
      return res({ token: 'tok-' + h.mints.length, exp: Math.floor(Date.now() / 1000) + 600, stream_url: DIRECT, live_url: 'wss://x' }, 200);
    }
    if (url === CC.wpRestUrl + 'chat/voice') return res({ enabled: true, conversation: opts.conversation !== false }, 200);
    if (url === ROOM) {
      h.order.push('mint');
      h.roomMints.push({ body: JSON.parse(init.body), auth: init.headers.Authorization, credentials: init.credentials });
      if (h.roomStatus !== 200) return res({ error: 'rooms_full' }, h.roomStatus);
      const body = JSON.parse(init.body);
      return res({ url: 'wss://media.test', token: 'room-tok', room: 'r' + h.roomMints.length,
        thread_id: body.thread_id || 'thr-new' }, 200);
    }
    if (url.indexOf(ROOM + '/') === 0) {
      h.reads.push({ url, auth: init.headers.Authorization, mintsBefore: h.mints.slice() });
      return res(h.readReply, 200);
    }
    if (url === DIRECT || url === CC.chatStreamUrl) {
      const body = JSON.parse(init.body);
      h.typed.push(body);
      return sse(h.typedEvents || [{ type: 'text_delta', text: 'Typed reply.' },
        { type: 'done', thread_id: body.thread_id || 'thr-typed', choices: [{ message: { content: 'Typed reply.' } }] }]);
    }
    if (url === CART) { h.carts.push(JSON.parse(init.body)); return res({ items: [] }, 201); }
    return res({}, 200);
  };
  w.DefVoice = {
    supported: () => true, micAllowedBySite: () => opts.micAllowed !== false, isStopPhrase: () => false,
    createSpeaker: () => ({ stop() {}, unlock() { h.order.push('speakerUnlock'); }, end() {},
      speak: text => h.spoken.push(text), discard() {}, whenIdle: () => Promise.resolve() }),
    createRecorder: o => ({ start: live => { h.starts.push(live); return Promise.resolve(true); }, isRecording: () => false,
      release() {}, stop: () => new Promise(() => {}) }),
    toBase64: () => Promise.resolve('AAAA'), log: () => h.notes.slice(), note: line => h.notes.push(line),
  };
  const root = w.document.getElementById('host').attachShadow({ mode: 'open' });
  root.innerHTML = '<div class="def-cc-panel"><button class="def-cc-panel-close" aria-label="Close chat"></button></div>';
  Object.assign(h, {
    root,
    mic: () => root.querySelector('.def-cc-composer-mic'),
    orb: () => root.querySelector('.def-cc-orb') || NONE,
    orbRow: () => root.querySelector('.def-cc-orb-row') || NONE,
    line: () => root.querySelector('.def-cc-orb-line') || NONE,
    // With no room yet, a stand-in, so a check fails rather than the harness throwing.
    room: () => h.rooms[h.rooms.length - 1] || { mic: [], her: {}, localParticipant: {}, connectedWith: {},
      words: () => ({ say() {}, close() {} }), data() {}, close() {} },
    spokenBubbles: () => root.querySelectorAll('.def-cc-message--spoken'),
    bubbles: () => Array.from(root.querySelectorAll('.def-cc-message--assistant .def-cc-message-content')).map(b => b.textContent.trim()),
    scripts: () => Array.from(w.document.head.querySelectorAll('script')).map(s => s.src),
    loadClient: () => { w.LivekitClient = fakeLiveKit(w, h); },
    tap: () => h.mic().click(),
    // A tap, then the mint, the connect and the microphone settle.
    start: async () => { h.tap(); await sleep(40); },
    send: async text => {
      root.querySelector('.def-cc-composer-input').value = text;
      root.querySelector('.def-cc-composer-input').dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
      await sleep(80);
    },
  });
  w.eval(MODULE);
  w.DEFCustomerChat.init(root, Object.assign({}, CC, opts.config || {}));
  await sleep(40);
  if (opts.load !== false) h.loadClient();
  return h;
}

(async () => {
  // ── The entry rule, and the client's loading ──
  let h = await boot({ conversation: false });
  await h.start();
  check('mode off: the client is not loaded and the mic is today\'s voice (the recorder starts, no room)',
    h.scripts().indexOf(LIVEKIT) === -1 && h.starts.length === 1 && h.rooms.length === 0 && h.roomMints.length === 0,
    'scripts=' + show(h.scripts()) + ' starts=' + h.starts.length + ' rooms=' + h.rooms.length);

  h = await boot({ webrtc: false });
  await h.start();
  check('no WebRTC: the client is not loaded and the mic is today\'s voice',
    h.scripts().indexOf(LIVEKIT) === -1 && h.starts.length === 1 && h.rooms.length === 0, 'scripts=' + show(h.scripts()));

  h = await boot({ micAllowed: false });
  await h.start();
  check('the site blocks the microphone: no room, the widget\'s own message, as today',
    h.rooms.length === 0 && h.roomMints.length === 0 && /Permissions-Policy/.test(h.bubbles().join(' ')), show(h.bubbles()));

  h = await boot({ load: false });
  check('mode on: the plugin\'s own copy of the client is loaded at the status, before any tap',
    h.scripts().indexOf(LIVEKIT) !== -1 && h.rooms.length === 0, 'scripts=' + show(h.scripts()));
  await h.start();
  check('mode on but the client not there yet: the tap is today\'s voice', h.starts.length === 1 && h.roomMints.length === 0);

  // ── Inside the tap, the mint ──
  h = await boot({ storage: { 'def:wc_cart_token': 'cart-123' } });
  h.tap();
  const inTap = h.order.slice();
  check('inside the tap, in the proof page\'s order: unlock, both elements primed, wake lock, room, startAudio; the mint only after',
    show(inTap.filter(s => s !== 'speakerUnlock')) === show(['unlock', 'silence', 'prime', 'prime', 'wakeLock', 'room', 'startAudio']),
    show(inTap));
  check('the orb shows grey (connecting) from the tap', h.orb().dataset.state === 'connecting' && h.orbRow().style.display === '');
  await sleep(40);
  const mint = h.roomMints[0];
  check('the mint: POST beside the stream route, on the stream token, credentials omitted',
    !!mint && mint.auth === 'Bearer tok-1' && mint.credentials === 'omit', show(mint));
  check('the mint body: the token\'s chat, the time zone, the page context and the cart token, as a typed turn has them',
    !!mint && show(Object.keys(mint.body).sort()) === show(['cart_token', 'page_context', 'thread_id', 'timezone']) &&
      mint.body.thread_id === '' && mint.body.cart_token === 'cart-123' && typeof mint.body.timezone === 'string' &&
      show(mint.body.page_context) === show({ canonical_path: '/', for: null }),
    show(mint && mint.body));
  check('the room joined with the mint\'s address and token; the microphone with echo cancellation, noise suppression and gain control',
    h.room().connectedWith.url === 'wss://media.test' && h.room().connectedWith.token === 'room-tok' &&
      show(h.room().mic[0].opts) === show({ echoCancellation: true, noiseSuppression: true, autoGainControl: true }),
    show(h.room().mic));
  check('the room token goes to the client only: not in the page, not in the voice log',
    h.root.innerHTML.indexOf('room-tok') === -1 && h.notes.join(' ').indexOf('room-tok') === -1);

  // ── The orb ──
  check('blue from the moment it listens', ['listening', 'idle'].indexOf(h.orb().dataset.state) !== -1, h.orb().dataset.state);
  h.room().localParticipant.isSpeaking = true; h.room().localParticipant.audioLevel = 0.5;
  await sleep(40);
  check('the visitor speaks: listening, moving with their voice', h.orb().dataset.state === 'listening' && /scale\(1\.3/.test(h.orb().style.transform),
    h.orb().dataset.state + ' ' + h.orb().style.transform);
  h.room().localParticipant.isSpeaking = false;
  h.room().her.isSpeaking = true; h.room().her.audioLevel = 1;
  await sleep(40);
  check('she speaks: orange (speaking), moving with hers', h.orb().dataset.state === 'speaking' && /scale\(1\.6/.test(h.orb().style.transform),
    h.orb().dataset.state + ' ' + h.orb().style.transform);
  h.room().her.isSpeaking = false;
  await sleep(500);
  check('nobody speaks: still (idle, no scale)', h.orb().dataset.state === 'idle' && h.orb().style.transform === '',
    h.orb().dataset.state + ' ' + h.orb().style.transform);

  // ── The visitor's line ──
  check('the visitor\'s line, with the employee\'s name, under the orb at the first conversation',
    h.line().textContent === 'Your voice is turned into text as Joe answers you. No audio recording is kept.' && h.line().style.display === '',
    h.line().textContent);

  // ── Captions, then the read: a new chat ──
  check('the room made a new chat: the widget takes its id and mints a token for it', h.mints.indexOf('thr-new') !== -1, show(h.mints));
  const words = h.room().words();
  words.say('The drill ');
  await sleep(10);
  words.say('<img src=x onerror="window.pwned=1">is in stock.');
  await sleep(10);
  const spoken = h.spokenBubbles()[0];
  check('her words appear as she says them, as text: a tag in them is not markup',
    !!spoken && spoken.textContent.trim() === 'The drill <img src=x onerror="window.pwned=1">is in stock.' &&
      !spoken.querySelector('img') && !h.w.pwned, spoken && spoken.innerHTML);
  check('the visitor\'s own words from the room are not drawn', (() => { h.room().words('visitor').say('hello'); return h.spokenBubbles().length === 1; })());
  words.close();
  await sleep(80);
  const read = h.reads[0];
  check('the reply ends: the after-reply read, GET on the token for the new chat, minted before it',
    !!read && read.url === ROOM + '/r1' && read.mintsBefore.indexOf('thr-new') !== -1 && read.auth === 'Bearer tok-2',
    show(read));
  check('the written reply replaces the spoken words', h.spokenBubbles().length === 0 && h.bubbles().indexOf('The drill is in stock.') !== -1 &&
    !!h.root.querySelector('.def-cc-message--assistant strong'), show(h.bubbles()));
  check('its product card is drawn by the widget\'s own card code', !!h.root.querySelector('.def-cc-result-section .def-cc-result-card'));
  check('its add to cart is carried out as a typed turn\'s: the Store API call, its toast and the cart link',
    show(h.carts) === show([{ id: 5, quantity: 1 }]) && !!h.root.querySelector('.cc-toast') && h.bubbles().indexOf('Here is a link to your cart: View cart') !== -1,
    show(h.carts));
  const history = JSON.parse(h.w.localStorage.getItem('a3rev_threads') || '[]');
  check('the local history: the chat, its written reply and its card (no add-to-cart replay)',
    history.length === 1 && history[0].id === 'thr-new' && history[0].messages.length === 1 &&
      history[0].messages[0].content === 'The **drill** is in stock.' && history[0].messages[0].tool_outputs.length === 1 &&
      h.w.localStorage.getItem('a3rev_thread_id') === 'thr-new', show(history));

  // ── A typed message during the conversation ──
  h.typedEvents = [{ type: 'speech', text: 'Typed reply.', audio_base64: 'AAAA', mime: 'audio/mpeg', seq: 0 },
    { type: 'text_delta', text: 'Typed reply.' }, { type: 'done', thread_id: 'thr-new', choices: [{ message: { content: 'Typed reply.' } }] }];
  h.room().her.isSpeaking = true;   // a spoken reply still sounding
  await sleep(20);
  await h.send('Is it cordless?');
  check('typing keeps the room on; the message goes typed, with speech_out', h.disconnects === 0 && h.orbRow().style.display === '' &&
    h.typed.length === 1 && h.typed[0].speech_out === true && !h.typed[0].via_voice && h.typed[0].messages[0].content === 'Is it cordless?',
    show(h.typed));
  check('its reply\'s voice waits for her spoken reply, the room\'s microphone muted', h.spoken.length === 0 && h.room().mic.indexOf(false) !== -1,
    'spoken=' + show(h.spoken) + ' mic=' + show(h.room().mic));
  h.room().her.isSpeaking = false;
  await sleep(500);
  check('she has finished: the typed reply plays by the widget\'s player, then the microphone opens again',
    show(h.spoken) === show(['Typed reply.']) && h.room().mic[h.room().mic.length - 1] === true, 'mic=' + show(h.room().mic));

  // ── not_understood, and her goodbye ──
  h.room().data('def.notice', { kind: 'not_understood' });
  await sleep(10);
  check('not_understood: the widget offers typing', h.bubbles().indexOf("I couldn't make that out. You can type your message below instead.") !== -1);
  h.room().data('def.ended', { type: 'ended', reason: 'goodbye' });
  h.room().close();
  await sleep(10);
  check('her goodbye ends it: the orb goes, the mic rests, nothing said as a fault',
    h.orbRow().style.display === 'none' && h.mic().dataset.state === 'idle' && h.bubbles().indexOf('The conversation was cut off. Tap the mic to talk again.') === -1);

  // ── A second conversation: the line once; a tap ends it ──
  await h.start();
  check('the second conversation: a new room on the chat it made, the visitor\'s line not shown again',
    h.rooms.length === 2 && h.roomMints[1].body.thread_id === 'thr-new' && h.line().style.display === 'none');
  const late = h.room().words();
  h.tap();
  await sleep(10);
  late.say('Too late.');
  await sleep(20);
  check('a tap ends it, the room left, the mic rests', h.disconnects === 1 && h.orbRow().style.display === 'none' && h.mic().dataset.state === 'idle');
  check('a caption after the end draws nothing', h.spokenBubbles().length === 0 && h.bubbles().indexOf('Too late.') === -1);
  await h.start();
  check('after an ending, the mic starts a conversation again', h.rooms.length === 3 && h.starts.length === 0);

  // ── The page hidden ──
  Object.defineProperty(h.w.document, 'visibilityState', { value: 'hidden', configurable: true });
  h.w.document.dispatchEvent(new h.w.Event('visibilitychange'));
  await sleep(10);
  check('the page hidden ends it (the widget\'s rule)', h.disconnects === 2 && h.orbRow().style.display === 'none');

  // ── A dropped room ──
  h = await boot();
  await h.start();
  h.room().close();
  await sleep(10);
  check('a room dropped mid-conversation: back to the chat with a line, the mode still on',
    h.orbRow().style.display === 'none' && h.bubbles().indexOf('The conversation was cut off. Tap the mic to talk again.') !== -1);
  await h.start();
  check('the next tap starts a conversation again', h.rooms.length === 2 && h.starts.length === 0);

  // ── A rejected mint ──
  h = await boot();
  h.roomStatus = 503;
  await h.start();
  check('a rejected mint: everything released, and this tap goes on as today\'s voice',
    h.starts.length === 1 && h.orbRow().style.display === 'none' && h.closed === 1 && h.wakeReleased === true &&
      h.w.document.body.querySelectorAll('audio').length === 0 && !(h.room().connectedWith || {}).url,
    'starts=' + h.starts.length + ' closed=' + h.closed);
  check('the mint\'s refusal is in the voice log, by status', h.notes.indexOf('conversation mint 503') !== -1, show(h.notes));
  h.roomStatus = 200;
  h.tap();   // ends today's voice, listening since the refused tap
  await sleep(10);
  await h.start();
  check('after a refused mint the next tap tries a room again', h.roomMints.length === 2 && h.rooms.length === 2);

  // ── A room that cannot connect (a page policy blocking the media host) ──
  h = await boot();
  h.connectFails = true;
  h.tap();
  const violation = new h.w.Event('securitypolicyviolation');
  Object.assign(violation, { blockedURI: 'wss://media.test/rtc?access_token=secret', effectiveDirective: 'connect-src' });
  h.w.document.dispatchEvent(violation);
  await sleep(40);
  check('it cannot connect: today\'s voice at once', h.starts.length === 1 && h.orbRow().style.display === 'none');
  check('the cause in the long-press log, without the address\'s query',
    h.notes.indexOf('conversation policy blocked wss://media.test/rtc (connect-src)') !== -1 &&
      h.notes.some(n => /^conversation connect failed \(ConnectionError\)$/.test(n)) && h.notes.join(' ').indexOf('secret') === -1,
    show(h.notes));
  h.connectFails = false;
  h.tap();   // ends today's voice, listening since the failed tap
  await sleep(10);
  await h.start();
  check('for the page\'s life: the next tap is today\'s voice', h.rooms.length === 1 && h.starts.length === 2);

  // ── The microphone refused inside the room ──
  h = await boot();
  h.micFails = true;
  await h.start();
  check('the microphone refused: the room left and the tap goes on as today\'s voice (its own message on a refusal)',
    h.disconnects === 1 && h.starts.length === 1);

  console.log('harness-cc-conversation: ' + pass + ' passed, ' + fail + ' failed');
  console.log(results.join('\n'));
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
