/*
 * The way out of a document that crashes the page (11.6.5) — behavioural harness.
 *
 * A document can freeze or crash the whole page: an artifact whose script never
 * ends, a picture the phone cannot show. The phone then reloads the page on the
 * same address, `#document/<id>`, and the console opened the same document again,
 * so the person was trapped. Now a visit is written down while it is open and rubbed
 * out when it ends normally; a start that finds the note does not reopen the
 * document: the chat shows, the address is clean, and one line offers Download and
 * Open again.
 *
 * Runs the SHIPPED page shell and the SHIPPED initDocumentViewer (both sliced by
 * marker) in jsdom, against the #documentPage section sliced out of the template.
 * A "start" is a fresh boot over the same storage, which is what a reload is: a
 * frozen or killed page is modelled by booting again WITHOUT firing any event on the
 * old one (it ran none), the app closed and reopened by a new sessionStorage over
 * the same localStorage. No document here runs a script: the crash is the absence
 * of a normal end, not a loop.
 *
 * Bite check: put the viewer block as it stands on origin/main back —
 *   git show origin/main:assets/js/staff-ai.js | sed -n '/^\t(function initDocumentViewer() {/,/^\t(function initDocuments() {/p' | sed '$d' > /tmp/old-viewer.js
 *   DOCVIEWER=/tmp/old-viewer.js node tests/browser/harness-document-way-out.js
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const SHELL = 'var talk = null;\n' + extract.pageShell();
const VIEWER = extract.documentViewer();

const HTML = `<!doctype html><html><body>
${extract.templateNav()}
<div id="conversationList"></div>
<div class="messages-container" id="messagesContainer"><div id="messagesList"></div></div>
<div class="info-banner" id="infoBanner"></div>
<div id="composerContainer"><textarea id="composerInput"></textarea></div>
<section class="console-page" id="documentsPane" hidden>
  <h1 class="console-page-title" id="documentsTitle" tabindex="-1">My documents</h1>
</section>
${extract.templatePage('documentPage')}
</body></html>`;

// A Storage over a Map: one localStorage shared by every start and every window of
// the same console, as a browser keeps it; sessionStorage one per tab.
function store(init) {
	const m = new Map(Object.entries(init || {}));
	return {
		getItem: k => (m.has(k) ? m.get(k) : null),
		setItem: (k, v) => { m.set(k, String(v)); },
		removeItem: k => { m.delete(k); },
		key: i => Array.from(m.keys())[i] || null,
		get length() { return m.size; },
		copy: () => store(Object.fromEntries(m)),
	};
}

const KINDS = {
	'doc-1': { file_type: 'md', content: 'Body of doc-1' },
	'pic-1': { file_type: 'png', kind: 'image' },
	'art-1': { file_type: 'html', content: '<!doctype html><p>a page</p>' },
};
const docFor = id => ({ document_id: id, title: 'Title of ' + id, file_type: KINDS[id].file_type, version: 2,
	download_url: 'https://e.test/staff-ai-download/tenant-1/' + id + '?staff_ai_save=1' });

function boot(url, local, session, opts) {
	opts = opts || {};
	const dom = new JSDOM(HTML, { url: url || 'https://e.test/staff-ai/', pretendToBeVisual: true });
	const window = dom.window, document = window.document;
	Object.defineProperty(window, 'localStorage', { value: local, configurable: true });
	Object.defineProperty(window, 'sessionStorage', { value: session, configurable: true });
	const frames = [];
	if (opts.hosted) window.StaffAIConfig = { host: { artifactUrl: id => { frames.push(id); return Promise.resolve('https://e.test/frame/' + id); } } };
	const requests = [];
	const held = [];
	async function apiRequest(path) {
		requests.push(path);
		const id = decodeURIComponent((path.match(/documents\/([^/?]+)/) || [])[1] || '');
		const kind = KINDS[id];
		const body = { document: docFor(id), kind: kind.kind, content: kind.content || '', offset: 0,
			total_chars: (kind.content || '').length, truncated: false, next_offset: null };
		if (opts.hold) return new Promise(r => { held.push(() => r(body)); });
		return body;
	}
	const $ = id => document.getElementById(id);
	const api = new window.Function('window', 'document', 'location', 'messagesContainer', 'composerContainer', 'conversationList',
		SHELL + `
		consolePages.push({ route: 'documents', el: document.getElementById('documentsPane'), title: document.getElementById('documentsTitle') });
		return { showPage: showPage, showChat: showChat, applyRoute: applyRoute, consolePages: consolePages,
			open: function () { return openRoute; } };`)(
		window, document, window.location, $('messagesContainer'), $('composerContainer'), $('conversationList'));
	const infoBanner = $('infoBanner');
	const names = ['window', 'document', 'navigator', 'consolePages', 'showPage', 't', 'apiRequest', 'apiBase', 'safeHttpHref',
		'isIOS', 'shareFile', 'ARTIFACT_TYPE', 'artifactDocument', 'shareOrigin', 'hostImage', 'infoBanner', 'showInfo', 'hideInfo'];
	const viewer = new window.Function(...names, 'var openDocumentViewer = null, skipCrashedDocument = null;\n' + VIEWER +
		'\nreturn { open: openDocumentViewer, skip: skipCrashedDocument };')(
		window, document, window.navigator, api.consolePages, api.showPage, (k, d) => d, apiRequest, '/wp-json/a3-ai/v1/staff-ai',
		u => u, () => false, () => {}, 'html', h => h, '', () => {}, infoBanner,
		m => { infoBanner.textContent = m; infoBanner.classList.add('visible'); },
		() => { infoBanner.classList.remove('visible'); });
	// What the console's own boot does, in its order: the way out, then the address.
	if (viewer.skip) viewer.skip();
	api.applyRoute();
	const banner = () => (infoBanner.classList.contains('visible') ? infoBanner.textContent : '');
	const link = text => Array.from(infoBanner.querySelectorAll('a')).find(a => a.textContent === text) || null;
	return {
		window, document, api, requests, frames, banner, link, viewer,
		release: () => held.splice(0).forEach(f => f()),
		onDocument: () => !$('documentPage').hidden,
		chatShows: () => $('documentPage').hidden && $('messagesContainer').style.display === '',
		frameSet: () => !!($('documentViewerFrame').getAttribute('srcdoc') || $('documentViewerFrame').getAttribute('src')),
		click: el => el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 })),
		hide: () => { Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true }); document.dispatchEvent(new window.Event('visibilitychange')); },
		show: () => { Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true }); document.dispatchEvent(new window.Event('visibilitychange')); },
	};
}

const tick = () => new Promise(r => setImmediate(r));
async function until(w, fn) {
	const deadline = Date.now() + 2000;
	while (!fn() && Date.now() < deadline) await new Promise(r => w.setTimeout(r, 5));
	return fn();
}

let pass = 0, fail = 0;
const results = [];
function check(label, ok, detail) {
	if (ok) { pass++; results.push('  ok   ' + label); }
	else { fail++; results.push('  FAIL ' + label + (detail ? ' -- ' + detail : '')); }
}

// The document opened from a card and fully loaded, then the page is gone with no
// event at all: frozen, then killed by the phone.
async function crashedOn(id, local, session, opts) {
	const first = boot(null, local, session, opts);
	first.viewer.open(id, 'Card title of ' + id);
	await tick(); await tick();
	return first;
}

(async function () {
	// ── 1. A start after a visit that did not end normally, for every kind ─────
	for (const id of Object.keys(KINDS)) {
		const local = store(), session = store();
		await crashedOn(id, local, session);
		const t = boot('https://e.test/staff-ai/#document/' + id, local, session.copy());   // the phone's reload
		await tick();
		check(id + ': the start shows the chat, not the document', t.chatShows() && t.api.open() === null);
		check(id + ': the address no longer names the document', t.window.location.hash === '', t.window.location.href);
		check(id + ': its content is not requested and no frame is set', t.requests.length === 0 && !t.frameSet(), JSON.stringify(t.requests));
		check(id + ': one line names it, with Download and Open again',
			t.banner().indexOf('"Title of ' + id + '" could not be opened.') === 0 && !!t.link('Download') && !!t.link('Open again'), t.banner());
		check(id + ': Download is the document page\'s own link', t.link('Download') && t.link('Download').href === docFor(id).download_url,
			t.link('Download') && t.link('Download').href);
		if (t.link('Open again')) t.click(t.link('Open again'));
		await tick();
		check(id + ': Open again opens the document as usual', t.onDocument() && t.window.location.hash === '#document/' + id
			&& t.requests[0] === '/documents/' + id + '/content' && !t.banner(), t.window.location.hash + ' ' + JSON.stringify(t.requests));
		// It crashes again: the same way out on the next start.
		const again = boot('https://e.test/staff-ai/#document/' + id, local, session.copy());
		check(id + ': and if it crashes again, the next start is the way out again', again.chatShows() && again.requests.length === 0 && !!again.banner());
	}

	// The installed app closed and opened again: a new sessionStorage, the same localStorage.
	{
		const local = store();
		await crashedOn('art-1', local, store());
		const t = boot('https://e.test/staff-ai/#document/art-1', local, store());
		check('the app closed and reopened on the address: the way out, too', t.chatShows() && t.requests.length === 0 && !!t.link('Open again'), t.banner());
	}

	// The Tenant Portal: the frame's address is not asked for either.
	{
		const local = store(), session = store();
		await crashedOn('art-1', local, session, { hosted: true });
		const t = boot('https://e.test/staff-ai/#document/art-1', local, session.copy(), { hosted: true });
		await tick();
		check('on a host that gives the frame an address, the address is not requested', t.frames.length === 0 && t.requests.length === 0 && !!t.banner(),
			JSON.stringify(t.frames));
	}

	// Crashed before the load answered: no title, no link the console could offer.
	{
		const local = store(), session = store();
		const first = boot('https://e.test/staff-ai/#document/doc-1', local, session, { hold: true });
		await tick();
		const t = boot('https://e.test/staff-ai/#document/doc-1', local, session.copy());
		check('a visit that never got its answer: the line names no title', t.banner().indexOf('That item could not be opened.') === 0 && !!t.link('Open again'), t.banner());
		first.release();
	}

	// ── 2. A visit that ended normally reopens with no line ─────────────────
	const ends = {
		'Back': async f => { f.click(f.document.querySelector('#documentPage .console-page-back')); await until(f.window, () => f.api.open() === null); },
		'another page': async f => { f.api.showPage('documents'); },
		'pagehide': async f => { f.window.dispatchEvent(new f.window.Event('pagehide')); },
		'visibilitychange to hidden': async f => { f.hide(); },
	};
	for (const name of Object.keys(ends)) {
		const local = store(), session = store();
		const first = await crashedOn('doc-1', local, session);
		await ends[name](first);
		const t = boot('https://e.test/staff-ai/#document/doc-1', local, session.copy());
		await tick();
		check('a visit ended by ' + name + ': the next start reopens it, with no line',
			t.onDocument() && t.requests[0] === '/documents/doc-1/content' && !t.banner(), JSON.stringify(t.requests) + ' ' + t.banner());
	}
	{
		// Put away and brought back while still on the document: it is watched again.
		const local = store(), session = store();
		const first = await crashedOn('doc-1', local, session);
		first.hide();
		first.show();
		const t = boot('https://e.test/staff-ai/#document/doc-1', local, session.copy());
		check('hidden then shown again, then frozen: the next start is the way out', t.chatShows() && !!t.banner());
	}
	{
		// A healthy document left open, the app put away, the phone discards it later.
		const local = store();
		const first = await crashedOn('pic-1', local, store());
		first.hide();
		const t = boot('https://e.test/staff-ai/#document/pic-1', local, store());
		await tick();
		check('a healthy document in an app put away and later discarded reopens as today', t.onDocument() && !t.banner());
	}

	// ── 3. Two windows of the same console ──────────────────────────────────
	{
		const local = store(), sessionA = store();
		const a = await crashedOn('doc-1', local, sessionA);
		// A second window opens the same document and is still on it.
		await crashedOn('doc-1', local, store());
		a.window.dispatchEvent(new a.window.Event('pagehide'));   // A reloads, deliberately
		const t = boot('https://e.test/staff-ai/#document/doc-1', local, sessionA.copy());
		await tick();
		check('a second window on the same document does not make the first refuse its own', t.onDocument() && !t.banner(), t.banner());
	}
	{
		const local = store(), sessionA = store();
		await crashedOn('doc-1', local, sessionA);   // A froze on doc-1 and was killed
		const b = await crashedOn('doc-1', local, store());
		b.api.showChat();                            // B leaves doc-1 normally
		const t = boot('https://e.test/staff-ai/#document/doc-1', local, sessionA.copy());
		check('a second window leaving the document does not rub out the first window\'s note', t.chatShows() && !!t.banner());
	}

	console.log('The way out of a document that crashes the page');
	results.forEach(line => console.log(line));
	console.log('  ' + pass + ' passed, ' + fail + ' failed');
	process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
