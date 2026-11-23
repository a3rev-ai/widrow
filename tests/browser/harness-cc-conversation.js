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
 * the visitor's own words as their message, joined until she answers and kept in the history;
 * her words as text, then the read's written reply, product card and add to cart; a
 * typed message keeps the room, asks for speech and mutes the room's microphone while
 * its reply plays, after her spoken reply; `not_understood` offers typing; each ending;
 * a rejected mint and a room that cannot connect fall back, the second for the page's
 * life with its cause in the voice log; nothing arrives after the end. Each segment of
 * words fills the bubble bound when it starts: an utterance the room delivers twice shows
 * once, and at a talk-over, in any order of arrival, each read's reply lands in its own
 * reply's place and the screen keeps the order things were said.
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
const CARD_REPLY = { ended: false, reply: { id: 'reply-1', content: 'The **drill** is in stock.', tool_outputs: [
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
      micTrack: { side: 'you' },   // replaced when a check restarts the microphone
      getTrackPublication: source => (source === 'microphone' && r.mic.length ?
        { track: { mediaStreamTrack: r.localParticipant.micTrack, stop() { h.micStops++; } } } : undefined),
      setMicrophoneEnabled: (on, opts) => {
        r.mic.push(opts ? { on, opts } : on);
        // h.micGate: the browser's permission prompt still open.
        return h.micFails ? Promise.reject(Object.assign(new Error('no'), { name: 'NotAllowedError' })) : (h.micGate || Promise.resolve());
      } };
    // Ready to hear unless a check says otherwise (her session's `lk.agent.state`).
    r.her = { identity: 'agent', isSpeaking: false, audioLevel: 0,
      attributes: h.herState === null ? {} : { 'lk.agent.state': h.herState || 'listening' } };
    // Her voice's track subscribed, as the client announces it.
    const voice = { kind: 'audio', mediaStreamTrack: { side: 'her' }, attach() {}, detach() {} };
    r.herTrack = () => r.emit('trackSubscribed', voice, { trackName: 'agent_voice' });
    r.herTrackGone = () => r.emit('trackUnsubscribed', voice, { trackName: 'agent_voice' });
    // No employee yet when a check says so; h.join() brings her in.
    r.remoteParticipants = new Map(h.noEmployee ? [] : [['agent', r.her]]);
    r.on = (event, fn) => { r.handlers[event] = fn; return r; };
    r.emit = (event, ...args) => r.handlers[event] && r.handlers[event](...args);
    r.startAudio = () => { h.order.push('startAudio'); return Promise.resolve(); };
    r.registerTextStreamHandler = (topic, fn) => { r.text[topic] = fn; };
    r.connect = (url, token) => {
      r.connectedWith = { url, token };
      if (h.connectFails) return Promise.reject(Object.assign(new Error('could not establish signal connection'), { name: 'ConnectionError' }));
      return (h.connectGate || Promise.resolve()).then(() => { r.state = 'connected'; });
    };
    r.disconnect = () => {
      if (r.state === 'disconnected' && r.closed) return Promise.resolve();
      r.closed = true; r.state = 'disconnected'; h.disconnects++;
      r.emit('disconnected', DisconnectReason.CLIENT_INITIATED);
      return Promise.resolve();
    };
    // The server closes the room: after her announced ending, or as a fault.
    r.close = () => { r.closed = true; r.state = 'disconnected'; r.emit('disconnected', DisconnectReason.UNKNOWN_REASON); };
    r.replyDone = () => r.data('def.reply', { type: 'reply' });
    r.data = (topic, obj) => r.emit('dataReceived', new w.TextEncoder().encode(JSON.stringify(obj)), r.her, 0, topic);
    // Her words as a text stream: say() a piece, close() the segment. `attributes` as the
    // room stamps them (lk.segment_id, lk.transcription_final); none, as the older checks send.
    r.words = (from, attributes) => {
      const queue = [], waiting = [];
      const feed = v => (waiting.length ? waiting.shift()(v) : queue.push(v));
      const reader = { info: { id: 'TS_' + (h.streams = (h.streams || 0) + 1), attributes: attributes || {} },
        [Symbol.asyncIterator]: () => ({
          next: () => (queue.length ? Promise.resolve(queue.shift()) : new Promise(res => waiting.push(res))) }) };
      r.text['lk.transcription'](reader, { identity: from || 'agent' });
      return { say: t => feed({ done: false, value: t }),
        close: () => feed({ done: true }),
        fail: () => (waiting.length ? waiting.shift() : () => {})(Promise.reject(Object.assign(new Error('x'), { name: 'NetworkError' }))) };
    };
  }
  return { Room, RoomEvent: { TrackSubscribed: 'trackSubscribed', TrackUnsubscribed: 'trackUnsubscribed',
    DataReceived: 'dataReceived', Disconnected: 'disconnected' },
    Track: { Kind: { Audio: 'audio' }, Source: { Microphone: 'microphone' } }, ConnectionState: { Disconnected: 'disconnected' }, DisconnectReason };
}

async function boot(opts) {
  opts = opts || {};
  const dom = new JSDOM('<!doctype html><html><head></head><body><div id="host"></div></body></html>',
    { url: 'https://shop.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  extract.stubMatchMedia(w);
  const realST = w.setTimeout.bind(w);
  // opts.clock: a second of the module's is 10 ms here, so its ten-second wait can be run.
  w.setTimeout = (fn, ms) => realST(fn, (ms || 0) >= 1000 ? (opts.clock ? ms / 100 : ms) : Math.min(ms || 0, 1));
  for (const src of VENDOR) w.eval(src);
  w.ReadableStream = function () {};
  for (const k of Object.keys(opts.storage || {})) w.localStorage.setItem(k, opts.storage[k]);
  w.WidrowAiPageContextHelper = { build: thread => ({ canonical_path: '/', for: thread }), markShipped() {} };
  const h = { w, noEmployee: !!opts.noEmployee, order: [], rooms: [], mints: [], roomMints: [], reads: [], typed: [], carts: [], starts: [], spoken: [],
    notes: [], disconnects: 0, micStops: 0, wakeReleases: 0, busy: null, readReply: opts.readReply || CARD_REPLY, roomStatus: 200, typedEvents: null,
    holdReads: false, held: [], levels: {}, herState: opts.herState, sources: [] };
  if (opts.reducedMotion) w.reducedMotion = 'reduce';
  if (opts.webrtc !== false) w.RTCPeerConnection = function () {};
  w.AudioContext = function () {
    h.order.push('unlock');
    Object.assign(this, { state: 'running', destination: {},
      createBufferSource: () => ({ connect() {}, start() { h.order.push('silence'); } }),
      createBuffer: () => ({}), resume: () => Promise.resolve(),
      createMediaStreamDestination: () => ({ stream: {} }),
      close: () => { this.state = 'closed'; h.closed = (h.closed || 0) + 1; return Promise.resolve(); },
      // Each side's analyser fills its bins with h.levels[side] (0 to 255). A closed context throws, as a browser's does.
      createMediaStreamSource: stream => {
        if (this.state === 'closed') throw new Error('InvalidStateError');
        h.sources.push(stream.tracks[0].side);
        return { side: stream.tracks[0].side,
        connect(node) { node.side = this.side; }, disconnect() { h.meterStops = (h.meterStops || 0) + 1; } };
      },
      createAnalyser: () => ({ frequencyBinCount: 8, getByteFrequencyData(bins) { bins.fill(h.levels[this.side] || 0); } }) });
  };
  w.MediaStream = function (tracks) { this.tracks = tracks; };
  w.HTMLMediaElement.prototype.play = function () { h.order.push('prime'); return Promise.resolve(); };
  Object.defineProperty(w.navigator, 'wakeLock', { configurable: true,
    value: { request: () => { h.order.push('wakeLock'); return Promise.resolve({ release() { h.wakeReleased = true; h.wakeReleases++; } }); } } });
  Object.defineProperty(w.navigator, 'language', { configurable: true, value: opts.language === undefined ? 'vi' : opts.language });
  if (opts.lineBlocked) {
    const set = w.Storage.prototype.setItem, get = w.Storage.prototype.getItem;
    w.Storage.prototype.setItem = function (k, v) { if (k === 'def:talk_line_shown') throw new Error('blocked'); return set.call(this, k, v); };
    w.Storage.prototype.getItem = function (k) { if (k === 'def:talk_line_shown') throw new Error('blocked'); return get.call(this, k); };
  }
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
      if (h.roomStatus !== 200) return res({ error: 'refused' }, h.roomStatus);
      if (h.mintReply) return res(h.mintReply, 200);
      const body = JSON.parse(init.body);
      return res({ url: 'wss://media.test', token: 'room-tok', room: 'r' + h.roomMints.length,
        thread_id: body.thread_id || 'thr-new' }, 200);
    }
    if (url.indexOf(ROOM + '/') === 0) {
      h.reads.push({ url, auth: init.headers.Authorization, mintsBefore: h.mints.slice() });
      // h.holdReads: each read waits until a check answers it, h.answer(n, body), in any order.
      if (h.holdReads) return new Promise(done => h.held.push(body => done(res(body, 200))));
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
      speak: text => h.spoken.push(text), discard() {},
      // A check holds the player busy (h.busy = a promise) to keep a reply sounding.
      whenIdle: () => h.busy || Promise.resolve() }),
    createRecorder: o => ({ start: live => { h.starts.push(live); return Promise.resolve(true); }, isRecording: () => false,
      release() { h.recorderReleases = (h.recorderReleases || 0) + 1; }, stop: () => new Promise(() => {}) }),
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
    // The client arrives as the page's script does: its load event fires.
    loadClient: () => {
      w.LivekitClient = fakeLiveKit(w, h);
      const tag = w.document.head.querySelector('script[src="' + LIVEKIT + '"]');
      if (tag) tag.dispatchEvent(new w.Event('load'));
    },
    tap: () => h.mic().click(),
    // The log as it reads, top to bottom: whose message, and its words.
    log: () => Array.from(root.querySelectorAll('.def-cc-message')).filter(m => !h.opening.has(m)).map(m =>
      (m.classList.contains('def-cc-message--user') ? 'you: ' : 'her: ') + m.textContent.trim()),
    // One of the visitor's utterances as the room delivers it: the segment twice, each closed,
    // the same id, first not final then final.
    utter: async (id, text) => {
      for (const final of ['false', 'true']) {
        const s = h.room().words('visitor', { 'lk.segment_id': id, 'lk.transcription_final': final });
        s.say(text); s.close();
        await sleep(5);
      }
    },
    her: id => h.room().words('agent', { 'lk.segment_id': id, 'lk.transcription_final': 'false' }),
    answer: (n, id, content) => h.held[n]({ ended: false, reply: { id, content, tool_outputs: [] } }),
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
  h.opening = new Set(root.querySelectorAll('.def-cc-message'));   // the greeting, before any conversation
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
  check('inside the tap, in order: unlock, both elements primed, wake lock, room, startAudio; the mint only after',
    show(inTap.filter(s => s !== 'speakerUnlock')) === show(['unlock', 'silence', 'prime', 'prime', 'wakeLock', 'room', 'startAudio']),
    show(inTap));
  check('the orb shows grey (connecting) from the tap', h.orb().dataset.state === 'connecting' && h.orbRow().style.display === '');
  check('the line-shown flag is not written at the tap', h.w.localStorage.getItem('def:talk_line_shown') === null);
  check('the visitor\'s line has no part, nor any ancestor of it below the panel', (() => {
    for (let node = h.line(); node && node.classList && !node.classList.contains('def-cc-panel'); node = node.parentNode) {
      if (!node.hasAttribute || node.hasAttribute('part')) return false;
    }
    return !!h.line().parentNode;
  })());
  await sleep(40);
  const mint = h.roomMints[0];
  check('the mint: POST beside the stream route, on the stream token, credentials omitted',
    !!mint && mint.auth === 'Bearer tok-1' && mint.credentials === 'omit', show(mint));
  check('the mint body: the token\'s chat, the time zone, the browser\'s language, the page context and the cart token',
    !!mint && show(Object.keys(mint.body).sort()) === show(['cart_token', 'language', 'page_context', 'thread_id', 'timezone']) &&
      mint.body.language === 'vi' &&
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
  check('blue from the moment it listens', h.orb().dataset.state === 'listening', h.orb().dataset.state);
  check('the line-shown flag is written once it listens', h.w.localStorage.getItem('def:talk_line_shown') === '1');
  // The levels come from each side's own track, measured as the client's analyser helper does.
  h.room().herTrack();
  h.levels = { you: 64, her: 0 };   // about a quarter of full scale
  h.room().localParticipant.isSpeaking = true;
  await sleep(400);
  check('the visitor speaks: listening, moving with their own microphone\'s level (eased to 1 + 0.8 x level)',
    h.orb().dataset.state === 'listening' && /^scale\(1\.20\d\)$/.test(h.orb().style.transform), h.orb().dataset.state + ' ' + h.orb().style.transform);
  h.room().localParticipant.isSpeaking = false;
  h.levels = { you: 0, her: 255 };
  h.room().her.isSpeaking = true;
  await sleep(400);
  check('she speaks: orange (speaking), moving with her own track\'s level, at most 1.4', h.orb().dataset.state === 'speaking' &&
    h.orb().style.transform === 'scale(1.400)', h.orb().dataset.state + ' ' + h.orb().style.transform);
  h.room().her.isSpeaking = false; h.levels = {};
  await sleep(500);
  check('nobody speaks: still listening (blue, no movement); idle is only for the end', h.orb().dataset.state === 'listening' && h.orb().style.transform === '',
    h.orb().dataset.state + ' ' + h.orb().style.transform);

  // ── The visitor's line ──
  check('the visitor\'s line, with the employee\'s name, under the orb at the first conversation',
    h.line().textContent === 'Your voice is turned into text as Joe answers you. No audio recording is kept.' && h.line().style.display === '',
    h.line().textContent);

  // ── Captions, then the read: a new chat ──
  check('the room made a new chat: the widget takes its id and mints a token for it', h.mints.indexOf('thr-new') !== -1, show(h.mints));
  // The visitor's own words: two segments before she answers, one message.
  const heard = h.room().words('visitor');
  heard.say('Do you have ');
  await sleep(10);
  const userBubble = () => Array.from(h.root.querySelectorAll('.def-cc-message--user .def-cc-message-content')).map(b => b.textContent);
  check('the visitor\'s words appear as they say them, as their own message', show(userBubble()) === show(['Do you have ']), show(userBubble()));
  heard.say('the <b onclick="window.pwned=1">drill</b>?');
  heard.close();
  await sleep(10);
  const heard2 = h.room().words('visitor');
  heard2.say('A cordless one.');
  heard2.close();
  await sleep(10);
  check('a second segment before she answers joins the same message; a tag in it is text',
    show(userBubble()) === show(['Do you have the <b onclick="window.pwned=1">drill</b>? A cordless one.']) &&
      !h.root.querySelector('.def-cc-message--user b'), show(userBubble()));
  var announced = () => Array.from(h.root.querySelectorAll('.def-cc-announcer > *')).map(n => n.textContent);
  check('the visitor\'s spoken message is not said while it arrives', announced().filter(l => l.indexOf('A cordless one.') !== -1).length === 0, show(announced()));
  const words = h.room().words();
  words.say('The drill ');
  await sleep(10);
  words.say('<img src=x onerror="window.pwned=1">is in stock.');
  await sleep(10);
  const spoken = h.spokenBubbles()[0];
  check('her words appear as she says them, as text: a tag in them is not markup',
    !!spoken && spoken.textContent.trim() === 'The drill <img src=x onerror="window.pwned=1">is in stock.' &&
      !spoken.querySelector('img') && !h.w.pwned, spoken && spoken.innerHTML);
  check('she starts answering: the visitor\'s message is said once, whole', announced().filter(l => l.indexOf('A cordless one.') !== -1).length === 1, show(announced()));
  check('her captions are not the visitor\'s: one spoken bubble, the visitor\'s message unchanged',
    h.spokenBubbles().length === 1 && userBubble().length === 1, show(userBubble()));
  words.close();
  await sleep(80);
  check('her words done, no read yet: the read waits for the room\'s reply message', h.reads.length === 0, show(h.reads));
  h.room().replyDone();
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
  const asked = 'Do you have the <b onclick="window.pwned=1">drill</b>? A cordless one.';
  check('the local history: both sides, as a typed turn writes them; the chat titled by the visitor\'s words; its card (no add-to-cart replay)',
    history.length === 1 && history[0].id === 'thr-new' && history[0].messages.length === 2 && history[0].title === asked.substring(0, 50) &&
      history[0].messages[0].role === 'user' && history[0].messages[0].content === asked &&
      history[0].messages[1].content === 'The **drill** is in stock.' && history[0].messages[1].tool_outputs.length === 1 &&
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
  check('her goodbye ends it: idle, the orb goes, the mic rests, nothing said as a fault',
    h.orb().dataset.state === 'idle' && h.orbRow().style.display === 'none' && h.mic().dataset.state === 'idle' && h.bubbles().indexOf('The conversation was cut off. Tap the mic to talk again.') === -1);

  // ── A second conversation: the line once; a tap ends it ──
  await h.start();
  check('the second conversation: a new room on the chat it made, the visitor\'s line not shown again',
    h.rooms.length === 2 && h.roomMints[1].body.thread_id === 'thr-new' && h.line().style.display === 'none');
  const late = h.room().words();
  const lateHeard = h.room().words('visitor');
  h.tap();
  await sleep(10);
  late.say('Too late.');
  lateHeard.say('Too late too.');
  await sleep(20);
  check('a tap ends it, the room left, the mic rests', h.disconnects === 1 && h.orbRow().style.display === 'none' && h.mic().dataset.state === 'idle');
  check('a caption after the end draws nothing', h.spokenBubbles().length === 0 && h.bubbles().indexOf('Too late.') === -1);
  check('the visitor\'s words after the end draw nothing', !h.root.textContent.includes('Too late too.'));
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

  // ── One reply, two segments: read once, drawn once, one add to cart ──
  h = await boot();
  await h.start();
  const userWords = h.room().words('visitor');
  userWords.say('Add the drill.'); userWords.close();
  await sleep(10);
  h.room().her.isSpeaking = true;
  await sleep(20);
  const part1 = h.room().words();
  part1.say('Let me check.'); part1.close();
  h.room().her.isSpeaking = false;
  await sleep(2000);   // a pause far past the speaking hold, inside one reply
  h.room().her.isSpeaking = true;
  const part2 = h.room().words();
  part2.say('The drill is in stock.'); part2.close();
  await sleep(20);
  check('a two-second pause between two segments of one reply reads nothing', h.reads.length === 0, show(h.reads));
  h.room().her.isSpeaking = false;
  h.room().replyDone();
  await sleep(100);
  check('a reply in two segments is read once, drawn once and adds to the cart once',
    h.reads.length === 1 && h.spokenBubbles().length === 0 && h.bubbles().filter(b => b === 'The drill is in stock.').length === 1 &&
      h.carts.length === 1, 'reads=' + h.reads.length + ' carts=' + h.carts.length + ' ' + show(h.bubbles()));
  const again = h.room().words();
  again.say('Anything else?'); again.close();
  h.room().replyDone();
  await sleep(100);
  check('a read that hands back a reply already drawn (the same id) draws nothing and adds nothing',
    h.reads.length === 2 && h.bubbles().filter(b => b === 'The drill is in stock.').length === 1 && h.carts.length === 1 &&
      h.spokenBubbles().length === 1, 'reads=' + h.reads.length + ' carts=' + h.carts.length);

  // ── In place, after a talk-over ──
  h = await boot({ readReply: { ended: false, reply: { id: 'r-a', content: 'Written answer.', tool_outputs: [] } } });
  await h.start();
  const sheSays = h.room().words();
  sheSays.say('Spoken answer'); sheSays.close();
  const over = h.room().words('visitor');
  over.say('Wait, one more thing'); over.close();
  await sleep(10);
  h.room().replyDone();
  await sleep(100);
  const order = Array.from(h.root.querySelectorAll('.def-cc-message')).map(m => m.textContent.trim()).filter(Boolean);
  check('the written reply takes her spoken words\' place: the visitor\'s newer words stay below it',
    order.indexOf('Written answer.') !== -1 && order.indexOf('Written answer.') < order.indexOf('Wait, one more thing'), show(order));

  // ── `ended` from the read ──
  h = await boot({ readReply: { ended: true, reason: 'goodbye', reply: { id: 'r-b', content: 'Goodbye.', tool_outputs: [] } } });
  await h.start();
  const bye = h.room().words();
  bye.say('Goodbye.'); bye.close();
  h.room().replyDone();
  await sleep(100);
  check('the read says it has ended (her room message lost): the conversation ends', h.disconnects === 1 && h.orbRow().style.display === 'none');

  // ── A words stream that errors, and a lost reply message ──
  h = await boot({ readReply: { ended: false, reply: { id: 'r-c', content: 'Read anyway.', tool_outputs: [] } } });
  await h.start();
  const broken = h.room().words();
  broken.say('Half a sen');
  await sleep(10);
  broken.fail();
  await sleep(10);
  check('her words stream fails: no read until the room says the reply is over', h.reads.length === 0);
  h.room().replyDone();
  await sleep(100);
  check('…then read on the reply message all the same', h.reads.length === 1 && h.bubbles().indexOf('Read anyway.') !== -1, show(h.bubbles()));
  h = await boot({ readReply: { ended: false, reply: { id: 'r-d', content: 'The last one.', tool_outputs: [] } } });
  await h.start();
  const lost = h.room().words();
  lost.say('The last'); lost.close();
  await sleep(20);
  h.tap();   // the reply message never came
  await sleep(100);
  check('a lost reply message: the read at the end of the conversation draws it', h.reads.length === 1 && h.bubbles().indexOf('The last one.') !== -1, show(h.bubbles()));
  h = await boot({ readReply: {} });
  await h.start();
  const unanswered = h.room().words('visitor');
  unanswered.say('Hello? Anyone?'); unanswered.close();
  await sleep(10);
  h.tap();
  await sleep(50);
  check('the conversation ends before she answers: the visitor\'s spoken message is said then',
    announced().filter(l => l.indexOf('Hello? Anyone?') !== -1).length === 1, show(announced()));

  // ── A typed stream that errors still opens the microphone again ──
  h = await boot();
  await h.start();
  h.typedEvents = [{ type: 'speech', text: 'Partly.', audio_base64: 'AAAA', mime: 'audio/mpeg', seq: 0 },
    { type: 'error', message: 'Something went wrong.' }];
  await h.send('Is it cordless?');
  await sleep(40);
  check('a typed stream that errors: its speech played, the room\'s microphone open again',
    h.room().mic.indexOf(false) !== -1 && h.room().mic[h.room().mic.length - 1] === true && h.disconnects === 0, show(h.room().mic));

  // ── A second typed message while the first reply still plays ──
  let release;
  h.busy = new Promise(r => { release = r; });
  h.typedEvents = [{ type: 'speech', text: 'One.', audio_base64: 'AAAA', mime: 'audio/mpeg', seq: 0 },
    { type: 'done', thread_id: 'thr-new', choices: [{ message: { content: 'One.' } }] }];
  const micBefore = h.room().mic.length;
  await h.send('First?');
  h.typedEvents = [{ type: 'speech', text: 'Two.', audio_base64: 'AAAA', mime: 'audio/mpeg', seq: 0 },
    { type: 'done', thread_id: 'thr-new', choices: [{ message: { content: 'Two.' } }] }];
  await h.send('Second?');
  const whilePlaying = h.room().mic.slice(micBefore);
  check('a second typed message while a reply plays: the microphone stays muted throughout', show(whilePlaying) === show([false]), show(whilePlaying));
  h.busy = null; release();
  await sleep(20);
  check('…and opens once, when both have played', show(h.room().mic.slice(micBefore)) === show([false, true]), show(h.room().mic.slice(micBefore)));

  // ── A mute while connecting ──
  h = await boot();
  let open;
  h.connectGate = new Promise(r => { open = r; });
  h.tap();
  await sleep(20);
  h.typedEvents = [{ type: 'speech', text: 'Early.', audio_base64: 'AAAA', mime: 'audio/mpeg', seq: 0 },
    { type: 'done', thread_id: 'thr-new', choices: [{ message: { content: 'Early.' } }] }];
  h.busy = new Promise(() => {});   // still playing when the room comes up
  await h.send('Hello?');
  open();
  await sleep(40);
  check('a mute asked while connecting holds: the microphone is published, then muted', h.room().mic.length === 2 &&
    h.room().mic[0].on === true && h.room().mic[1] === false, show(h.room().mic));

  // ── Exactly one release on each ending ──
  const endings = {
    goodbye: async x => { x.room().data('def.ended', { type: 'ended', reason: 'goodbye' }); x.room().close(); },
    tap: async x => { x.tap(); },
    dropped: async x => { x.room().close(); },
    hidden: async x => {
      Object.defineProperty(x.w.document, 'visibilityState', { value: 'hidden', configurable: true });
      x.w.document.dispatchEvent(new x.w.Event('visibilitychange'));
    },
    'closed panel': async x => { if (x.w.DEFCustomerChat.panelClosed) x.w.DEFCustomerChat.panelClosed(); },
  };
  for (const name of Object.keys(endings)) {
    h = await boot();
    await h.start();
    await endings[name](h);
    await sleep(20);
    h.room().close();   // a late close from the server changes nothing
    await sleep(20);
    check('one release on ' + name + ': the microphone, the audio, the wake lock, once each; the orb gone',
      h.micStops === 1 && h.closed === 1 && h.wakeReleases === 1 && h.orbRow().style.display === 'none' &&
        h.w.document.body.querySelectorAll('audio').length === 0,
      'mic=' + h.micStops + ' audio=' + h.closed + ' wake=' + h.wakeReleases);
  }
  check('the loader tells the chat when it closes the panel, and when it removes the widget',
    /function closePanel\(\) \{[^]*?DEFCustomerChat\.panelClosed\(\)[^]*?\n\t}\n/.test(extract.customerChatLoader()) &&
      /function destroy\(\) \{\n[^]*?DEFCustomerChat\.panelClosed\(\)[^]*?\n\t}\n/.test(extract.customerChatLoader()));
  h = await boot({ conversation: false });
  await h.start();
  const before = h.recorderReleases || 0;
  h.w.DEFCustomerChat.panelClosed();
  await sleep(10);
  check('today\'s hands-free voice: a closed panel releases the recorder and the mic rests, as a tap does',
    (h.recorderReleases || 0) > before && h.mic().dataset.state === 'idle', 'releases=' + h.recorderReleases + ' mic=' + h.mic().dataset.state);

  // ── The visitor's line ──
  h = await boot({ lineBlocked: true });
  await h.start();
  h.tap(); await sleep(10);
  await h.start();
  check('storage blocked: the line shows at every conversation', h.line().style.display === '' && /answers you/.test(h.line().textContent));
  h = await boot({ config: { strings: { talkLine: 'Rewritten %s.' }, talkLine: 'Translated: %s answers.' } });
  h.roomStatus = 503;
  await h.start();
  check('the line comes from its own field, not the site\'s filterable strings', h.line().textContent === 'Translated: Joe answers.', h.line().textContent);
  check('a conversation that never listened leaves the line to show next time', h.w.localStorage.getItem('def:talk_line_shown') === null);

  // ── A room no employee joins (the service at its load) ──
  h = await boot({ clock: true, noEmployee: true });
  await h.start();
  await sleep(10);
  check('no employee within the wait: nothing yet before it', h.orbRow().style.display === '' && h.starts.length === 0);
  await sleep(150);
  check('no employee within ten seconds: busy, released once, today\'s voice for this tap, said in the widget\'s words',
    h.orbRow().style.display === 'none' && h.micStops === 1 && h.closed === 1 && h.wakeReleases === 1 && h.disconnects === 1 &&
      h.starts.length === 1 && h.bubbles().indexOf('Conversations are busy right now, so the microphone works as usual.') !== -1 &&
      h.notes.indexOf('conversation no employee joined') !== -1,
    'mic=' + h.micStops + ' audio=' + h.closed + ' starts=' + h.starts.length + ' ' + show(h.notes));
  h.noEmployee = false;
  h.tap(); await sleep(10);   // ends today's voice
  await h.start();
  check('…and the next tap tries a conversation again', h.rooms.length === 2 && h.orbRow().style.display === '');
  h = await boot({ clock: true, noEmployee: true });
  await h.start();
  await sleep(30);   // about seven of the module's seconds after the connect
  h.room().remoteParticipants.set('agent', h.room().her);
  await sleep(150);
  check('the employee joins, ready, at about seven seconds: no busy, the conversation stays', h.orbRow().style.display === '' &&
    h.micStops === 0 && h.starts.length === 0);
  h = await boot({ clock: true, noEmployee: true });
  await h.start();
  h.tap();
  await sleep(150);
  check('end() before the wait is over cancels it: one release, no busy line, no today\'s voice',
    h.micStops === 1 && h.closed === 1 && h.starts.length === 0 && h.bubbles().indexOf('Conversations are busy right now, so the microphone works as usual.') === -1);

  // ── The mint's own refusals ──
  h = await boot();
  h.mintReply = { room: 'r9' };
  await h.start();
  check('a mint with no address or token ends as a refused mint: today\'s voice this tap', h.starts.length === 1 &&
    h.notes.some(n => /^conversation mint failed/.test(n)), show(h.notes));
  h.mintReply = null;
  h.tap(); await sleep(10);
  await h.start();
  check('…and the mode stays on for the next tap', h.rooms.length === 2 && h.roomMints.length === 2);
  h = await boot();
  h.mintReply = { url: 'ws://media.test', token: 'room-tok', room: 'r9' };
  await h.start();
  h.mintReply = null;
  h.tap(); await sleep(10);
  await h.start();
  check('an address that is not wss: ends as connect, the room never joined, today\'s voice for the page\'s life',
    h.rooms.length === 1 && !(h.rooms[0].connectedWith || {}).url && h.starts.length === 2, 'rooms=' + h.rooms.length);
  h = await boot({ language: '' });
  await h.start();
  check('an empty browser language sends no language field', !!h.roomMints[0] && !('language' in h.roomMints[0].body), show(h.roomMints[0]));

  // ── Each utterance and each reply in its own bubble, bound when it starts ──
  // The room's real order: the visitor's utterance comes as one segment delivered twice, each
  // closed; at a talk-over their words, the end of her reply (`def.reply`) and the close of her
  // words arrive together, in any order, and the read answers once her next reply has begun.
  h = await boot();
  await h.start();
  await h.utter('U1', 'What time do you open?');
  check('one utterance delivered as two closed segments with one id: its words shown once',
    show(h.log()) === show(['you: What time do you open?']), show(h.log()));
  h = await boot({ readReply: { ended: false, reply: { id: 'w1', content: 'At nine.', tool_outputs: [] } } });
  await h.start();
  const first = h.room().words('visitor', { 'lk.segment_id': 'U1', 'lk.transcription_final': 'false' });
  first.say('What time do you open?'); first.close();
  await sleep(5);
  const starts = h.her('A1');
  starts.say('At');
  await sleep(5);
  const second = h.room().words('visitor', { 'lk.segment_id': 'U1', 'lk.transcription_final': 'true' });
  second.say('What time do you open?'); second.close();
  await sleep(5);
  starts.say(' nine.'); starts.close();
  await sleep(5);
  h.room().replyDone();
  await sleep(40);
  check('the final copy of an utterance arriving after she began: still once, in its own bubble, and once in the history',
    show(h.log()) === show(['you: What time do you open?', 'her: At nine.']) &&
      JSON.parse(h.w.localStorage.getItem('a3rev_threads'))[0].messages[0].content === 'What time do you open?', show(h.log()));

  const together = {
    words: async x => { await x.utter('U2', 'Sorry, and on Sundays?'); },
    replyOver: async x => { x.room().replyDone(); await sleep(5); },
    closed: async x => { x.cut.close(); await sleep(5); },
  };
  const orders = [['words', 'replyOver', 'closed'], ['words', 'closed', 'replyOver'], ['replyOver', 'words', 'closed'],
    ['replyOver', 'closed', 'words'], ['closed', 'words', 'replyOver'], ['closed', 'replyOver', 'words']];
  for (const order of orders) {
    h = await boot();
    h.holdReads = true;
    await h.start();
    await h.utter('U1', 'What time do you open?');
    h.cut = h.her('A1');
    h.cut.say('We open at nine and');
    await sleep(5);
    for (const step of order) await together[step](h);
    const next = h.her('A2');
    next.say('Of course,');
    await sleep(5);
    h.answer(0, 'w1', 'We open at nine and close at five.');
    await sleep(20);
    const midway = h.log();
    next.say(' on Sundays too.'); next.close();
    await sleep(5);
    h.room().replyDone();
    await sleep(20);
    if (h.held[1]) h.answer(1, 'w2', 'We open on Sundays too.');
    await sleep(20);
    check('talk-over (' + order.join(', ') + '): the written reply in the cut reply\'s own bubble, their words after it, her next reply after that',
      show(midway) === show(['you: What time do you open?', 'her: We open at nine and close at five.', 'you: Sorry, and on Sundays?', 'her: Of course,']) &&
        show(h.log()) === show(['you: What time do you open?', 'her: We open at nine and close at five.', 'you: Sorry, and on Sundays?', 'her: We open on Sundays too.']) &&
        h.spokenBubbles().length === 0,
      show(midway) + ' then ' + show(h.log()));
  }

  h = await boot();
  h.holdReads = true;
  await h.start();
  await h.utter('Q', 'Tell me about the courses.');
  const long = h.her('A1');
  long.say('There are three courses');
  await sleep(5);
  await h.utter('H1', 'The first one.');
  await h.utter('H2', 'And the price.');
  await h.utter('H3', 'And the dates.');
  long.say(' on offer.'); long.close();
  await sleep(5);
  h.room().replyDone();
  await sleep(5);
  h.answer(0, 'w1', 'There are three courses on offer.');
  await sleep(20);
  const all = h.her('A2');
  all.say('The first costs ten and starts in May.'); all.close();
  await sleep(5);
  h.room().replyDone();
  await sleep(5);
  h.answer(1, 'w2', 'The first costs ten and starts in May.');
  await sleep(20);
  const kept = JSON.parse(h.w.localStorage.getItem('a3rev_threads'))[0].messages;
  check('three utterances held while she speaks, then one reply: their words once each, in order, in their own message, then the reply',
    show(h.log()) === show(['you: Tell me about the courses.', 'her: There are three courses on offer.',
      'you: The first one. And the price. And the dates.', 'her: The first costs ten and starts in May.']) &&
      kept[2].content === 'The first one. And the price. And the dates.', show(h.log()) + ' ' + show(kept));

  h = await boot();
  await h.start();
  await h.utter('Q', 'Tell me about the courses.');
  const part = h.her('A1');
  part.say('There are three.'); part.close();
  await sleep(5);
  await h.utter('H1', 'The first one.');
  const more = h.her('A1b');
  more.say('Each runs a term.'); more.close();
  await sleep(5);
  await h.utter('H2', 'And the price.');
  check('while she speaks: on screen in the order said, never the visitor\'s words above words of hers said before them',
    show(h.log()) === show(['you: Tell me about the courses.', 'her: There are three.', 'you: The first one.', 'her: Each runs a term.', 'you: And the price.']),
    show(h.log()));

  h = await boot();
  h.holdReads = true;
  await h.start();
  await h.utter('Q1', 'Is there parking?');
  const one = h.her('A1');
  one.say('Yes, behind the hall.'); one.close();
  await sleep(5);
  h.room().replyDone();
  await sleep(5);
  await h.utter('Q2', 'Is it free?');
  const two = h.her('A2');
  two.say('Yes, it is free.'); two.close();
  await sleep(5);
  h.room().replyDone();
  await sleep(5);
  h.answer(1, 'w2', 'Yes, parking is free.');
  await sleep(20);
  h.answer(0, 'w1', 'Yes, there is parking behind the hall.');
  await sleep(20);
  check('two reads answering out of order: each written reply in its own reply\'s place',
    show(h.log()) === show(['you: Is there parking?', 'her: Yes, there is parking behind the hall.', 'you: Is it free?', 'her: Yes, parking is free.']),
    show(h.log()));

  for (const late of [false, true]) {
    h = await boot();
    h.holdReads = true;
    await h.start();
    await h.utter('Q1', 'Is there parking?');
    const one = h.her('A1');
    one.say('Yes, behind the hall.');
    if (!late) one.close();
    await sleep(5);
    h.room().replyDone();
    await sleep(5);
    if (late) { one.close(); await sleep(5); }   // her words' close after the reply message
    h.answer(0, 'w1', 'Yes, there is parking behind the hall.');
    await sleep(20);
    await h.utter('Q2', 'Thanks, that is all.');
    const bye = h.her('A2');
    bye.say('You are welcome, good');   // cut off mid-word: the room closes
    await sleep(5);
    h.room().data('def.ended', { type: 'ended', reason: 'goodbye' });
    h.room().close();
    await sleep(20);
    h.held[1]({ ended: true, reason: 'goodbye', reply: { id: 'bye', content: 'You are welcome, goodbye.', tool_outputs: [] } });
    await sleep(20);
    check('the goodbye' + (late ? ', after a reply whose words closed after its reply message' : '') +
      ': the end\'s read puts the whole written reply in the goodbye\'s bubble, after the words it answers',
      show(h.log()) === show(['you: Is there parking?', 'her: Yes, there is parking behind the hall.', 'you: Thanks, that is all.', 'her: You are welcome, goodbye.']) &&
        h.spokenBubbles().length === 0, show(h.log()));
  }

  // ── Words over her last reply, and the final words, kept ──
  h = await boot({ readReply: { ended: false, reply: { id: 'w1', content: 'We open at nine.', tool_outputs: [] } } });
  await h.start();
  await h.utter('U1', 'When do you open?');
  const last = h.her('A1');
  last.say('We open at');
  await sleep(5);
  await h.utter('U2', 'And on Sundays?');
  h.tap();   // the conversation ends while her reply is on screen
  await sleep(60);
  const thread = JSON.parse(h.w.localStorage.getItem('a3rev_threads') || '[{"messages":[]}]')[0].messages.map(m => m.role + ': ' + m.content);
  check('words said over her last reply, then the end: said once to a screen reader and kept in the history after the reply',
    announced().filter(l => l === 'And on Sundays?').length === 1 &&
      show(thread) === show(['user: When do you open?', 'assistant: We open at nine.', 'user: And on Sundays?']), show(announced()) + ' ' + show(thread));

  h = await boot({ readReply: { ended: false, reply: { id: 'w1', content: 'At nine.', tool_outputs: [] } } });
  await h.start();
  const interim = h.room().words('visitor', { 'lk.segment_id': 'U1', 'lk.transcription_final': 'false' });
  interim.say('What time do you'); interim.close();
  await sleep(5);
  const begun = h.her('A1');
  begun.say('At nine.');
  await sleep(5);
  const finalCopy = h.room().words('visitor', { 'lk.segment_id': 'U1', 'lk.transcription_final': 'true' });
  finalCopy.say('What time do you open?'); finalCopy.close();
  begun.close();
  await sleep(5);
  h.room().replyDone();
  await sleep(40);
  check('the final copy differs from the interim and comes after her first words: the bubble and the history hold the final words',
    show(h.log()) === show(['you: What time do you open?', 'her: At nine.']) &&
      JSON.parse(h.w.localStorage.getItem('a3rev_threads'))[0].messages[0].content === 'What time do you open?', show(h.log()));

  // ── Listening only once she can hear ──
  h = await boot({ herState: null });
  await h.start();
  h.levels = { you: 255 };
  h.room().localParticipant.isSpeaking = true;
  await sleep(60);
  const noState = h.orb().dataset.state + ' ' + h.orb().style.transform;
  h.room().her.attributes = { 'lk.agent.state': 'initializing' };
  await sleep(60);
  const initializing = h.orb().dataset.state + ' ' + h.orb().style.transform;
  check('the employee in the room with no state, then "initializing": still connecting, grey and still, the line-shown flag not written',
    noState === 'connecting ' && initializing === 'connecting ' && h.w.localStorage.getItem('def:talk_line_shown') === null, noState + ' / ' + initializing);
  h.room().her.attributes = { 'lk.agent.state': 'listening' };
  await sleep(60);
  check('…her state says listening: listening, moving with the visitor\'s voice, the flag written',
    h.orb().dataset.state === 'listening' && /scale/.test(h.orb().style.transform) && h.w.localStorage.getItem('def:talk_line_shown') === '1',
    h.orb().dataset.state + ' ' + h.orb().style.transform);
  h = await boot({ noEmployee: true });
  await h.start();
  await sleep(60);
  check('no employee in the room yet: connecting', h.orb().dataset.state === 'connecting', h.orb().dataset.state);
  h = await boot({ herState: null });
  await h.start();
  h.room().her.isSpeaking = true;
  await sleep(60);
  check('she speaks before her state arrives: she can be heard, speaking', h.orb().dataset.state === 'speaking', h.orb().dataset.state);
  h = await boot({ clock: true, herState: null });
  await h.start();
  await sleep(150);
  check('an employee who joins and is never ready: busy at ten seconds, today\'s voice for this tap',
    h.orbRow().style.display === 'none' && h.starts.length === 1 && h.notes.indexOf('conversation employee not ready') !== -1, show(h.notes));
  h = await boot({ clock: true, herState: null });
  await h.start();
  await sleep(30);
  h.room().her.attributes = { 'lk.agent.state': 'listening' };
  await sleep(150);
  check('one who is ready in time: no busy, listening', h.orbRow().style.display === '' && h.starts.length === 0 &&
    h.orb().dataset.state === 'listening', h.orb().dataset.state);

  // ── The orb's levels: released at the end; still under reduced motion ──
  h = await boot();
  await h.start();
  h.room().herTrack();
  h.tap();
  await sleep(20);
  check('both sides\' level meters are released at the end', h.meterStops === 2, 'stops=' + h.meterStops);
  check('reduced motion: the orb keeps its colours and does not move',
    /@media \(prefers-reduced-motion: reduce\) \{[^}]*\.def-cc-orb \{\s*transform: none !important;/.test(
      fs.readFileSync(path.join(extract.REPO, 'assets/css/widrow-ai-customer-chat.css'), 'utf8')));

  // ── The permission prompt left open; talk-over then the end; the meters' lives ──
  h = await boot({ clock: true });
  let allow;
  h.micGate = new Promise(r => { allow = r; });
  h.tap();
  await sleep(200);   // twenty of the module's seconds on the microphone prompt, the employee ready
  check('the microphone prompt left open for twenty seconds with a ready employee: no busy, still connecting',
    h.orbRow().style.display === '' && h.starts.length === 0 && h.orb().dataset.state === 'connecting',
    h.orb().dataset.state + ' starts=' + h.starts.length + ' ' + show(h.notes));
  allow();
  await sleep(60);
  check('…allowed: listening', h.orb().dataset.state === 'listening', h.orb().dataset.state);

  h = await boot({ readReply: { ended: false, reply: { id: 'w1', content: 'We open at nine.', tool_outputs: [] } } });
  await h.start();
  await h.utter('U1', 'When do you open?');
  const cutOff = h.her('A1');
  cutOff.say('We open at');
  await sleep(5);
  await h.utter('U2', 'And on Sundays?');
  cutOff.close();
  h.room().replyDone();   // she is cut off: the reply is over
  await sleep(40);
  h.tap();                // then the conversation ends; the last read hands back the same reply
  await sleep(60);
  const kept2 = JSON.parse(h.w.localStorage.getItem('a3rev_threads'))[0].messages.map(m => m.role + ': ' + m.content);
  check('talked over her, the reply over, then the end: the visitor\'s words said once and in the history',
    announced().filter(l => l === 'And on Sundays?').length === 1 &&
      show(kept2) === show(['user: When do you open?', 'assistant: We open at nine.', 'user: And on Sundays?']), show(announced()) + ' ' + show(kept2));

  h = await boot();
  await h.start();
  h.tap();
  await sleep(10);
  let threw = null;
  try { h.room().herTrack(); } catch (e) { threw = e; }
  check('her track subscribed after the end: nothing measured on the closed audio', !threw && h.sources.indexOf('her') === -1, String(threw));
  h = await boot();
  await h.start();
  h.room().herTrack();
  h.room().herTrackGone();
  await sleep(10);
  check('her track unsubscribed: her meter released', h.meterStops === 1, 'stops=' + h.meterStops);
  h.room().localParticipant.micTrack = { side: 'you' };   // the client restarted the microphone
  h.levels = { you: 64 };
  h.room().localParticipant.isSpeaking = true;
  await sleep(300);
  check('a restarted microphone track: the visitor\'s meter follows it and the orb still moves',
    h.sources.filter(x => x === 'you').length === 2 && /^scale\(1\.20\d\)$/.test(h.orb().style.transform), show(h.sources) + ' ' + h.orb().style.transform);
  h = await boot({ reducedMotion: true });
  await h.start();
  h.room().herTrack();
  h.levels = { you: 255 };
  h.room().localParticipant.isSpeaking = true;
  await sleep(60);
  check('reduced motion: nothing measured, the orb still', h.sources.length === 0 && h.orb().style.transform === '' &&
    h.orb().dataset.state === 'listening', show(h.sources) + ' ' + h.orb().style.transform);

  // ── The button at the box's end: Start voice / Send / End voice where the mode is on ──
  const btn = h => h.root.querySelector('.def-cc-composer-send');
  const box = h => h.root.querySelector('.def-cc-composer-input');
  const type = (h, text) => { box(h).value = text; box(h).dispatchEvent(new h.w.Event('input', { bubbles: true })); };
  // What shows: the button's role, name and tooltip, whether it is enabled, and whether the mic shows.
  const shows = h => {
    const b = btn(h);
    const role = ['start', 'end'].find(r => b.classList.contains('def-cc-composer-send--' + r)) ||
      (b.classList.contains('def-cc-composer-send--talk') ? 'send' : 'today');
    return { role, name: b.getAttribute('aria-label'), title: b.getAttribute('title'), enabled: !b.disabled, mic: h.mic().style.display !== 'none' };
  };
  const today = h => { const now = shows(h); delete now.enabled; return show(now) === show({ role: 'today', name: 'Send message', title: null, mic: true }); };

  h = await boot();
  check('mode on, box empty: one round button "Start voice" (name and tooltip), enabled; no mic',
    show(shows(h)) === show({ role: 'start', name: 'Start voice', title: 'Start voice', enabled: true, mic: false }) &&
      / composer-send--talk /.test(' ' + btn(h).getAttribute('part') + ' ') && / composer-send--start /.test(' ' + btn(h).getAttribute('part') + ' '),
    show(shows(h)) + ' part=' + btn(h).getAttribute('part'));
  box(h).dispatchEvent(new h.w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
  await sleep(20);
  check('Enter in the empty box: nothing sent and no conversation, as today', h.typed.length === 0 && h.rooms.length === 0);
  btn(h).click();
  const tapOrder = h.order.slice();
  check('a tap on Start voice starts the conversation inside the tap: unlock, both elements primed, wake lock, room, startAudio; the mint only after',
    show(tapOrder.filter(s => s !== 'speakerUnlock')) === show(['unlock', 'silence', 'prime', 'prime', 'wakeLock', 'room', 'startAudio']) &&
      h.starts.length === 0, show(tapOrder));
  await sleep(40);
  check('in a conversation, box empty: the same button is "End voice"; no mic',
    show(shows(h)) === show({ role: 'end', name: 'End voice', title: 'End voice', enabled: true, mic: false }), show(shows(h)));
  type(h, 'Do you deliver?');
  check('in a conversation, words typed: the same button is Send', show(shows(h)) === show({ role: 'send', name: 'Send message', title: null, enabled: true, mic: false }), show(shows(h)));
  btn(h).click();
  await sleep(80);
  check('…and it sends, the conversation kept on', h.typed.length === 1 && h.typed[0].messages[0].content === 'Do you deliver?' && h.disconnects === 0 &&
    h.orbRow().style.display === '' && shows(h).role === 'end', show({ typed: h.typed.length, disconnects: h.disconnects, now: shows(h) }));
  btn(h).click();
  await sleep(20);
  check('End voice ends it, as the mic tap did; back to Start voice', h.disconnects === 1 && h.orbRow().style.display === 'none' &&
    shows(h).role === 'start', show({ disconnects: h.disconnects, now: shows(h) }));

  h = await boot();
  type(h, 'Hello');
  check('mode on, words typed: the same button is Send', show(shows(h)) === show({ role: 'send', name: 'Send message', title: null, enabled: true, mic: false }), show(shows(h)));
  type(h, '');
  check('the box cleared: Start voice again', shows(h).role === 'start' && shows(h).name === 'Start voice');
  type(h, 'Hello');
  btn(h).click();
  await sleep(80);
  check('Send sends a typed message, no conversation', h.typed.length === 1 && h.typed[0].messages[0].content === 'Hello' && h.rooms.length === 0 &&
    shows(h).role === 'start', show({ typed: h.typed.length, rooms: h.rooms.length, now: shows(h) }));

  h = await boot({ conversation: false });
  check('mode off: today\'s Send and today\'s mic, unchanged', today(h) &&
    btn(h).getAttribute('part') === 'composer-send', show(shows(h)) + ' part=' + btn(h).getAttribute('part'));
  type(h, 'Hello');
  check('mode off, words typed: today\'s Send, the mic beside it', today(h) && shows(h).enabled, show(shows(h)));

  h = await boot({ load: false });
  check('mode on but the client not loaded yet: today\'s controls', today(h), show(shows(h)));
  h.loadClient();
  check('…and Start voice once it has loaded', shows(h).role === 'start', show(shows(h)));

  h = await boot();
  h.connectFails = true;
  btn(h).click();
  await sleep(40);
  check('the mode fails for the page: the tap goes on as today\'s voice, with today\'s mic to end it', h.starts.length === 1 &&
    today(h), show(shows(h)));
  h.tap();   // ends today's voice
  await sleep(10);
  check('…and after it, today\'s controls for the page\'s life', today(h), show(shows(h)));

  h = await boot({ clock: true, noEmployee: true });
  btn(h).click();
  await sleep(200);
  check('the busy fallback: the tap goes on as today\'s voice, said as before, with today\'s mic to end it',
    h.starts.length === 1 && h.bubbles().indexOf('Conversations are busy right now, so the microphone works as usual.') !== -1 &&
      today(h), show(shows(h)));
  h.tap();   // ends today's voice
  await sleep(10);
  check('…and the next tap is Start voice again', shows(h).role === 'start', show(shows(h)));

  (function () {
    const css = fs.readFileSync(path.join(extract.REPO, 'assets/css/widrow-ai-customer-chat.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    const blocks = css.split(/\.def-cc-composer-send--talk\s*\{/).slice(1).map(b => b.slice(0, b.indexOf('}')));
    const d = blocks[0] || '';
    const size = k => +((new RegExp('(^|[;\\s])' + k + ':\\s*(\\d+)px')).exec(d) || [])[2];
    check('the button at any width, a phone\'s included (one rule, no width query): round, 48 by 48, larger than the mic (44)',
      blocks.length === 1 && size('width') === 48 && size('height') === 48 && /border-radius:\s*50%/.test(d), d);
  })();

  console.log('harness-cc-conversation: ' + pass + ' passed, ' + fail + ' failed');
  console.log(results.join('\n'));
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
