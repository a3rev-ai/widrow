/*
 * The console on a host other than WordPress — behavioural harness (v9.8.0, W85 S5).
 *
 * StaffAIConfig.host is set by the Tenant Portal's page. Runs the SHIPPED request path,
 * download helpers, chat card, attachments, handover, flags and release check, sliced
 * by marker, inside jsdom with a stub host:
 * - every request goes through host.fetch: no nonce, no cookie, no other network;
 * - a download is fetched through host.fetch as a blob, never followed as a link; a PNG
 *   opens, HTML and SVG are saved as application/octet-stream;
 * - an attachment's address is host.attachmentUrl, and its picture a blob;
 * - Share, voice, the service worker and the manifest check stay off with flags false;
 * - the handover fires only with no host and an https portal_url;
 * - an artifact loads from host.artifactUrl when the host has it (10.1.0, W85 S7b): one
 *   content chunk, src not srcdoc, the watchdog, a refusal, a late answer dropped; a host
 *   without it, and no host, read and frame the artifact exactly as before.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const REQUEST = extract.slice('request path',
	l => l.includes('// Every request that carries the nonce'),
	l => l.includes('\t// Elements'),
	['async function wpFetch', 'async function apiRequest'], 'HOST_REQUEST');
const FILES = extract.slice('file helpers',
	l => l.includes('// Helper function to rewrite download URLs'),
	l => l.startsWith('\t// Create tool output card'),
	['function rewriteDownloadUrl', 'async function shareFile', 'async function openHostFile',
		'async function hostImage', "a[data-host-file]"], 'HOST_FILES');
const CARD = extract.toolOutputCard();
const ATTACH = extract.chatAttachments();
const HANDOVER = extract.slice('handover and flags',
	l => l.includes('── The handover (W85 S5)'),
	l => l.includes('// Load conversations from backend'),
	['function handOverToPortal', 'function hostAllows', 'serviceWorker'], 'HOST_HANDOVER');
const VOICE_INIT = extract.slice('initVoice',
	l => l.startsWith('\tfunction initVoice() {'),
	l => l.startsWith('\t\tspeaker = DefVoice.createSpeaker({'),
	['micBtn.hidden = true'], 'HOST_VOICE') + '\n\t\tthrow new Error("voice went on");\n\t}';
const RELEASE = extract.release();
const MARKDOWN = extract.slice('renderMarkdown',
	l => l.startsWith('\tfunction renderMarkdown(text) {'),
	l => l.includes('// Every request that carries the nonce'),
	["removeAttribute('data-host-file')"], 'HOST_MARKDOWN');
const VIEWER = extract.documentViewer();
const FRAME = extract.artifactFrame();
const fs = require('fs');

let pass = 0, fail = 0;
const results = [];
function check(label, ok, detail) {
	if (ok) { pass++; results.push('  ok   ' + label); }
	else { fail++; results.push('  FAIL ' + label + (detail ? ' — ' + detail : '')); }
}
const tick = () => new Promise(r => setTimeout(r, 0));

const DOOR = 'https://api.defho.test/api/tenant/acme/staff-ai';
function stubHost(flags, files) {
	const calls = [];
	return {
		calls,
		fetch(url, init) {
			calls.push({ url, init: init || {} });
			const f = (files || {})[url.split('?')[0]];
			if (f) return Promise.resolve(new Response(f.body, { status: 200, headers: f.headers }));
			return Promise.resolve(new Response('{"ok":true}', { status: 200, headers: { 'content-type': 'application/json' } }));
		},
		fileUrl: (p) => DOOR + '/download/' + p.replace('/api/files/', ''),
		attachmentUrl: (thread, id, variant) => DOOR + '/attachment/' + thread + '/' + id + (variant ? '/' + variant : ''),
		...(flags || {})
	};
}

function dom() {
	return new JSDOM(`<!doctype html><body><div id="errorBanner"></div>
		<button id="shareBtn">Share</button><div><button id="overflowShare">Share</button></div>
		<button id="micBtn"></button><button id="voiceBtn"></button><div id="messages"></div></body>`,
	{ url: 'https://acme.defho.test/staff-ai/acme/', pretendToBeVisual: true }).window;
}

// The file helpers and the chat card, over a window whose StaffAIConfig carries `host`.
function bootFiles(host, opts) {
	const window = dom(), document = window.document;
	window.StaffAIConfig = { homeUrl: 'https://site.test/', host };
	const seen = { opened: [], saved: [], blobs: [], errors: [], shared: [] };
	window.open = (u) => { seen.opened.push(u); return (opts || {}).popupBlocked ? null : {}; };
	if ((opts || {}).ios) {   // isIOS() reads window.navigator
		Object.defineProperty(window.navigator, 'userAgent', { value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)' });
	}
	const nav = (opts || {}).ios
		? { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', maxTouchPoints: 5,
			share: (d) => {
				if ((opts || {}).shareRefused) return Promise.reject(Object.assign(new Error('refused'), { name: 'NotAllowedError' }));
				seen.shared.push(d.files[0]); return Promise.resolve();
			} }
		: { userAgent: 'Mozilla/5.0 (X11; Linux x86_64)' };
	window.HTMLAnchorElement.prototype.click = function () { seen.saved.push({ href: this.href, download: this.download }); };
	const URLStub = { createObjectURL: (b) => { seen.blobs.push(b); return 'blob:' + seen.blobs.length; }, revokeObjectURL() {} };
	// The shipped list, so a type added there is a type this harness opens.
	const SAFE_OPEN_TYPES = JSON.parse(/var SAFE_OPEN_TYPES = (\[[^\]]*\]);/.exec(ATTACH)[1].replace(/'/g, '"'));
	const files = new window.Function('window', 'document', 'StaffAIConfig', 'SAFE_OPEN_TYPES', 'URL', 'Blob',
		'showError', 't', 'navigator', 'TextDecoder', 'File',
		FILES + '\nreturn { rewriteDownloadUrl, saveHref, openHostFile, hostImage, shareFile, isIOS };')(
		window, document, window.StaffAIConfig, SAFE_OPEN_TYPES, URLStub, Blob,
		(m) => seen.errors.push(m), (k, d) => d, nav, TextDecoder, File);
	const card = new window.Function('window', 'document', 't', 'rewriteDownloadUrl', 'saveHref',
		'isIOS', 'shareFile', 'IMAGE_EXTENSIONS', 'ARTIFACT_TYPE', 'openDocumentViewer', 'hostImage',
		CARD + '\n\treturn createToolOutputCard;')(
		window, document, (k, d) => d, files.rewriteDownloadUrl, files.saveHref, () => false, files.shareFile,
		['png'], 'html', null, files.hostImage);
	return { window, document, files, card, seen, SAFE_OPEN_TYPES, URLStub };
}

function click(window, el) {
	el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
}

(async function () {
	// ── The request path: host.fetch, and nothing of WordPress's ──────────────
	{
		const window = dom();
		const host = stubHost();
		window.StaffAIConfig = { host };
		const globalFetches = [];
		const api = new window.Function('window', 'StaffAIConfig', 'fetch', 't', 'apiBase', 'console',
			'let nonce = StaffAIConfig.nonce;\n' + REQUEST + '\nreturn { apiRequest, wpFetch };')(
			window, { nonce: 'n0nce', nonceUrl: 'https://site.test/nonce', host }, (u) => { globalFetches.push(u); },
			(k, d) => d, DOOR, { error() {} });
		await api.apiRequest('/conversations');
		await api.wpFetch(DOOR + '/status');
		const init = host.calls[0] && host.calls[0].init;
		check('a request goes through host.fetch, to the page\'s own address',
			host.calls.length === 2 && host.calls[0].url === DOOR + '/conversations', JSON.stringify(host.calls.map(c => c.url)));
		check('no other network: no fetch, no nonce refresh', globalFetches.length === 0, JSON.stringify(globalFetches));
		check('no nonce header and no cookie mode on what the host is handed',
			!(init.headers || {})['X-WP-Nonce'] && init.credentials === undefined, JSON.stringify(init));
	}

	// ── Downloads: fetched as a blob through the host, never followed ────────────
	{
		const host = stubHost({}, {
			[DOOR + '/download/t1/chart.png']: { body: 'PNG', headers: { 'content-type': 'image/png' } },
			[DOOR + '/download/t1/page.html']: { body: '<script>alert(1)</script>', headers: { 'content-type': 'text/html', 'content-disposition': 'attachment; filename="page.html"' } },
			[DOOR + '/download/t1/logo.svg']: { body: '<svg/>', headers: { 'content-type': 'image/svg+xml' } }
		});
		const b = bootFiles(host);
		check('DEF\'s /api/files path maps through host.fileUrl',
			b.files.rewriteDownloadUrl('/api/files/t1/chart.png') === DOOR + '/download/t1/chart.png');

		const card = b.card({ file_name: 'chart.png', file_type: 'png', download_url: '/api/files/t1/chart.png' });
		b.document.body.appendChild(card);
		await tick(); await tick();
		const picture = card.querySelector('.tool-output-image');
		check('the chat card\'s picture is fetched through the host and shown from a blob',
			host.calls.length === 1 && /^blob:/.test(picture.getAttribute('src') || ''), JSON.stringify(host.calls));
		host.calls.length = 0;
		const dl = card.querySelector('.tool-output-download');
		click(b.window, dl);
		await tick(); await tick();
		check('the chat card\'s Download is fetched through host.fetch, not followed',
			host.calls.length === 1 && host.calls[0].url.startsWith(DOOR + '/download/t1/chart.png'), JSON.stringify(host.calls));
		const opened = b.seen.blobs[b.seen.blobs.length - 1];
		check('a PNG opens in a tab, from a blob that keeps its type',
			b.seen.opened.length === 1 && opened.type === 'image/png' && b.seen.saved.length === 0,
			JSON.stringify({ opened: b.seen.opened, saved: b.seen.saved }));

		for (const [file, label] of [['page.html', 'HTML'], ['logo.svg', 'SVG']]) {
			const before = { opened: b.seen.opened.length, blobs: b.seen.blobs.length };
			await b.files.openHostFile(DOOR + '/download/t1/' + file);
			const blob = b.seen.blobs[before.blobs];
			check(label + ' is saved as application/octet-stream and never opened',
				b.seen.opened.length === before.opened && blob && blob.type === 'application/octet-stream'
				&& b.seen.saved.length > 0, JSON.stringify({ type: blob && blob.type, saved: b.seen.saved }));
		}
		check('a saved file keeps the name the door gave it', b.seen.saved[0].download === 'page.html', b.seen.saved[0].download);

		const png = b.document.createElement('img'), svg = b.document.createElement('img');
		await b.files.hostImage(png, DOOR + '/download/t1/chart.png');
		await b.files.hostImage(svg, DOOR + '/download/t1/logo.svg');
		check('a picture shows from a blob only when it is a safe raster type',
			/^blob:/.test(png.getAttribute('src') || '') && !svg.getAttribute('src'),
			JSON.stringify({ png: png.getAttribute('src'), svg: svg.getAttribute('src') }));

		// A link in Sue's text to a generated file: marked, and fetched the same way.
		const a = b.document.createElement('a');
		a.setAttribute('href', b.files.rewriteDownloadUrl('/api/files/t1/chart.png'));
		a.setAttribute('data-host-file', '');
		b.document.body.appendChild(a);
		const n = host.calls.length;
		click(b.window, a);
		await tick(); await tick();
		check('a marked link in the chat text is fetched through the host', host.calls.length === n + 1);
	}

	// ── A blocked tab saves; an iPhone takes the share sheet ─────────────────────
	{
		const files = { [DOOR + '/download/t1/chart.png']: { body: 'PNG', headers: { 'content-type': 'image/png' } },
			[DOOR + '/download/t1/page.html']: { body: '<p>', headers: { 'content-type': 'text/html' } } };
		const blocked = bootFiles(stubHost({}, files), { popupBlocked: true });
		await blocked.files.openHostFile(DOOR + '/download/t1/chart.png', 'chart.png');
		check('a PNG whose tab is blocked is saved instead', blocked.seen.saved.length === 1, JSON.stringify(blocked.seen));
		const ios = bootFiles(stubHost({}, files), { ios: true });
		await ios.files.openHostFile(DOOR + '/download/t1/page.html', 'page.html');
		check('on an iPhone the file goes to the share sheet, typed octet-stream, and no tab opens',
			ios.seen.shared.length === 1 && ios.seen.shared[0].type === 'application/octet-stream'
			&& ios.seen.opened.length === 0 && ios.seen.saved.length === 0, JSON.stringify(ios.seen));
		const refused = bootFiles(stubHost({}, files), { ios: true, shareRefused: true });
		await refused.files.openHostFile(DOOR + '/download/t1/page.html', 'page.html');
		check('a share sheet that refuses (NotAllowedError) falls through and saves',
			refused.seen.saved.length === 1 && refused.seen.shared.length === 0, JSON.stringify(refused.seen));
	}

	// ── A link Sue writes can't ask for the token ───────────────────────────────
	{
		const window = new JSDOM('<!doctype html><body></body>', { runScripts: 'outside-only' }).window;
		window.eval(fs.readFileSync(extract.REPO + '/assets/js/vendor/marked.min.js', 'utf8'));
		window.eval(fs.readFileSync(extract.REPO + '/assets/js/vendor/purify.min.js', 'utf8'));
		window.StaffAIConfig = { host: stubHost() };
		const render = new window.Function('window', 'document', 'marked', 'DOMPurify', 'escapeHtml', 'rewriteDownloadUrl',
			MARKDOWN + '\nreturn renderMarkdown;')(window, window.document, window.marked, window.DOMPurify,
			(x) => x, (u) => (u.startsWith('/api/files/') ? DOOR + '/download/' + u.slice(11) : u));
		const html = render('<a href="https://evil.example/steal" data-host-file="x.html">your file</a> and [chart](/api/files/t1/chart.png)');
		const box = window.document.createElement('div');
		box.innerHTML = html;
		const [evil, real] = box.querySelectorAll('a');
		check('a data-host-file written into the reply is stripped; the console marks only its own /api/files link',
			!evil.hasAttribute('data-host-file') && real.hasAttribute('data-host-file'), html);
	}

	// ── A card whose address is not DEF's file path never gets the token ────────
	{
		const host = stubHost();
		const b = bootFiles(host);
		const card = b.card({ file_name: 'x.png', file_type: 'png', download_url: 'https://evil.example/x.png' });
		b.document.body.appendChild(card);
		click(b.window, card.querySelector('.tool-output-download'));
		await tick(); await tick();
		check('a card whose download_url is not /api/files/ makes no host call',
			host.calls.length === 0 && !card.querySelector('.tool-output-download').hasAttribute('data-host-file'),
			JSON.stringify(host.calls));
	}

	// ── No host: the download path is the one it always was ─────────────────────
	{
		const b = bootFiles(undefined);
		check('with no host, /api/files maps to the site\'s download route as before',
			b.files.rewriteDownloadUrl('/api/files/t1/chart.png') === 'https://site.test/staff-ai-download/t1/chart.png');
		const card = b.card({ file_name: 'chart.png', file_type: 'png', download_url: '/api/files/t1/chart.png' });
		check('with no host, the card\'s Download is an ordinary link',
			!card.querySelector('.tool-output-download').hasAttribute('data-host-file'));
	}

	// ── Attachments: the host's address, a blob for the picture ─────────────────
	{
		const host = stubHost({}, { [DOOR + '/attachment/th-1/f-1/thumbnail']: { body: 'PNG', headers: { 'content-type': 'image/png' } } });
		const window = dom(), document = window.document;
		window.StaffAIConfig = { homeUrl: 'https://site.test/', host };
		const blobs = [];
		const hostImage = async (img, url) => {
			const r = await host.fetch(url, {});
			const blob = await r.blob();
			blobs.push(blob);
			img.src = 'blob:thumb';
		};
		const api = new window.Function('window', 'document', 'StaffAIConfig', 'currentConversationId', 'isIOS',
			'shareFile', 'openHostFile', 'hostImage', 'dataUrlToBlob', 'messagesContainer',
			ATTACH + '\nreturn { attachmentUrl, storedAttachment, appendFileAttachments };')(
			window, document, window.StaffAIConfig, 'th-1', () => false, () => {}, () => {}, hostImage, () => null,
			document.getElementById('messages'));
		check('an attachment\'s address is host.attachmentUrl(thread, file, variant)',
			api.attachmentUrl('f-1', 'thumbnail') === DOOR + '/attachment/th-1/f-1/thumbnail');
		const box = document.getElementById('messages');
		api.appendFileAttachments(box, [api.storedAttachment({ file_id: 'f-1', mime_type: 'image/png', filename: 'a.png', has_thumbnail: true })]);
		await tick(); await tick();
		const img = box.querySelector('img');
		check('a stored picture is fetched through the host and shown from a blob',
			host.calls.some(c => c.url === DOOR + '/attachment/th-1/f-1/thumbnail') && img && img.getAttribute('src') === 'blob:thumb',
			JSON.stringify(host.calls));
	}

	// ── Flags: Share, the service worker, voice and the manifest stay off ───────
	function bootHandover(host) {
		const window = dom(), document = window.document;
		const fake = { StaffAIConfig: { host }, location: { replaced: [], replace(u) { this.replaced.push(u); } } };
		const registered = [];
		const nav = { serviceWorker: { register(u) { registered.push(u); return Promise.resolve(); } } };
		const api = new window.Function('window', 'document', 'StaffAIConfig', 'navigator', 'shareBtn', 'URL', 'console',
			HANDOVER + '\nreturn { handOverToPortal };')(
			fake, document, { homeUrl: 'https://site.test/' }, nav, document.getElementById('shareBtn'), URL, { warn() {} });
		return { api, fake, document, registered };
	}
	{
		const h = bootHandover(stubHost({ share: false, voice: false, install: false }));
		check('Share is hidden, in the header and the overflow menu',
			h.document.getElementById('shareBtn').style.display === 'none'
			&& h.document.getElementById('overflowShare').style.display === 'none');
		check('no service worker is registered', h.registered.length === 0, JSON.stringify(h.registered));
		const plain = bootHandover(undefined);
		check('with no host, Share shows and the service worker registers as before',
			plain.document.getElementById('shareBtn').style.display === '' && plain.registered.length === 1);
	}
	{
		const window = dom(), document = window.document;
		const mic = document.getElementById('micBtn'), voice = document.getElementById('voiceBtn');
		let threw = null;
		try {
			new window.Function('window', 'micBtn', 'voiceBtn', 'DefVoice', VOICE_INIT + '\ninitVoice();')(
				{ StaffAIConfig: { host: stubHost({ voice: false }) }, DefVoice: { supported: () => true } },
				mic, voice, { supported: () => true });
		} catch (e) { threw = e; }
		check('voice is off: the mic and the voice button are hidden', !threw && mic.hidden && voice.hidden, threw && threw.message);
	}
	{
		const window = dom();
		window.StaffAIConfig = { host: stubHost({ install: false }) };
		const fetches = [];
		new window.Function('window', 'document', 'StaffAIConfig', 'navigator', 'fetch',
			'var sessionStorage = window.sessionStorage, composerInput = null, _isStreaming = false, isLoading = false,' +
			' conversationOn = false, spokenTurn = false, _streamAbort = null, _eventsSeen = 0, RESUME_GRACE_MS = 0,' +
			' location = { reload() {} }; function hasActiveFiles() { return false; } function endConversation() {}' +
			' function t(k, d) { return d; } function showInfo() {}\n' + RELEASE)(
			window, window.document, { version: '9.8.0', homeUrl: 'https://site.test/' }, { onLine: true },
			(u) => { fetches.push(u); return Promise.resolve({ ok: false }); });
		check('the release check asks for no manifest', fetches.length === 0, JSON.stringify(fetches));
	}

	// ── The handover ──────────────────────────────────────────────────────────
	{
		const portal = { staff_ai_home: 'portal', portal_url: 'https://acme.defho.test/t/acme' };
		const h = bootHandover(undefined);
		check('no host, home portal, an https URL: the page is replaced with it',
			h.api.handOverToPortal(portal) === true && h.fake.location.replaced[0] === 'https://acme.defho.test/t/acme');
		const cases = [
			['with a host, both keys are ignored', bootHandover(stubHost()), portal],
			['an http URL is not followed', bootHandover(undefined), { staff_ai_home: 'portal', portal_url: 'http://acme.defho.test/t/acme' }],
			['a javascript: URL is not followed', bootHandover(undefined), { staff_ai_home: 'portal', portal_url: 'javascript:alert(1)' }],
			['a relative URL is not followed', bootHandover(undefined), { staff_ai_home: 'portal', portal_url: '/t/acme' }],
			['a URL with credentials is not followed', bootHandover(undefined), { staff_ai_home: 'portal', portal_url: 'https://u:p@acme.defho.test/t/acme' }],
			['a site tenancy stays', bootHandover(undefined), { staff_ai_home: 'site', portal_url: 'https://acme.defho.test/t/acme' }],
			['no keys: nothing happens', bootHandover(undefined), { success: true }]
		];
		for (const [label, b, data] of cases) {
			check(label, b.api.handOverToPortal(data) === false && b.fake.location.replaced.length === 0);
		}
	}

	// ── The artifact frame: the host's address (10.1.0, W85 S7b) ────────────────
	// The SHIPPED viewer over the template's own document page. An artifact's content
	// comes in two chunks; host.artifactUrl answers per document, now or when told to.
	const HEAD = '<!doctype html><html><head><style>body{margin:0;', TAIL = 'color:#123}</style></head><body>k</body></html>';
	const DOCS = {
		'art-1': { document_id: 'art-1', title: 'Style kit', file_type: 'html', version: 2, download_url: DOOR + '/download/t1/kit.html' },
		'art-2': { document_id: 'art-2', title: 'Mock-up', file_type: 'html', version: 1, download_url: DOOR + '/download/t1/mock.html' },
		'doc-1': { document_id: 'doc-1', title: 'Runsheet', file_type: 'md', version: 3, download_url: DOOR + '/download/t1/run.md' }
	};
	const ARTIFACT_AT = (id) => 'https://artifacts.defho.test/a/' + id + '?sig=s1';
	function bootViewer(host) {
		const window = new JSDOM('<!doctype html><body>' + extract.templatePage('documentPage') + '</body></html>',
			{ url: 'https://acme.defho.test/staff-ai/acme/', pretendToBeVisual: true }).window;
		const document = window.document;
		if (host !== undefined) window.StaffAIConfig = { host };
		const requests = [], pages = [];
		const t = (k, d) => d;
		const frame = new window.Function('document', 't', FRAME + '\nreturn { ARTIFACT_TYPE, artifactDocument };')(document, t);
		async function apiRequest(url) {
			requests.push(url);
			const id = url.split('/')[2], doc = DOCS[id], second = /offset=/.test(url);
			if (doc.file_type !== 'html') return { document: doc, content: 'Body of ' + id, offset: 0, total_chars: 9, truncated: false, next_offset: null };
			return { document: doc, content: second ? TAIL : HEAD, offset: second ? HEAD.length : 0,
				total_chars: HEAD.length + TAIL.length, truncated: !second, next_offset: second ? null : HEAD.length };
		}
		const showPage = (route) => pages[0].onEnter(decodeURIComponent(route.split('/')[1]));
		const viewer = new window.Function('window', 'document', 'consolePages', 'showPage', 't', 'apiRequest', 'apiBase',
			'safeHttpHref', 'openDocumentViewer', 'isIOS', 'shareFile', 'ARTIFACT_TYPE', 'artifactDocument', 'shareOrigin',
			VIEWER + '\nreturn openDocumentViewer;')(
			window, document, pages, showPage, t, apiRequest, DOOR, (u) => u, null, () => false, () => {},
			frame.ARTIFACT_TYPE, frame.artifactDocument, 'https://defho.test');
		const el = (id) => document.getElementById(id);
		const iframe = el('documentViewerFrame');
		const srcdocWrites = [];
		new window.MutationObserver((rs) => rs.forEach((r) => { if (r.attributeName === 'srcdoc' && iframe.hasAttribute('srcdoc')) srcdocWrites.push(r); }))
			.observe(iframe, { attributes: true, attributeFilter: ['srcdoc'] });
		return { window, frame, requests, srcdocWrites, open: viewer, iframe,
			status: () => el('documentViewerStatus'), title: () => el('documentPageTitle').textContent,
			download: () => el('documentViewerDownload'), text: () => el('documentViewerText'),
			load: () => iframe.dispatchEvent(new window.Event('load')) };
	}
	function urlHost() {
		const asked = [], pending = {};
		return { asked, pending, artifactUrl(id) {
			asked.push(id);
			return new Promise((resolve, reject) => { pending[id] = { resolve, reject }; });
		} };
	}
	{
		const host = urlHost();
		const v = bootViewer(host);
		v.open('art-1', 'Style kit');
		await tick(); await tick();
		host.pending['art-1'].resolve(ARTIFACT_AT('art-1'));
		await tick(); await tick();
		check('with host.artifactUrl, the frame\'s src is the address the host returned, and the frame shows',
			host.asked.join() === 'art-1' && v.iframe.getAttribute('src') === ARTIFACT_AT('art-1') && v.iframe.style.display === '',
			JSON.stringify({ asked: host.asked, src: v.iframe.getAttribute('src') }));
		check('srcdoc is never set', v.srcdocWrites.length === 0 && !v.iframe.hasAttribute('srcdoc'));
		check('only the first content chunk is requested', v.requests.join() === '/documents/art-1/content', JSON.stringify(v.requests));
		check('title, version, the download link and Share render from that first chunk',
			v.title() === 'Style kit' && v.status().textContent === 'ARTIFACT · v2'
			&& v.download().href === DOCS['art-1'].download_url && v.download().style.display === ''
			&& v.window.document.getElementById('documentViewerShare').style.display === '',
			JSON.stringify({ title: v.title(), status: v.status().textContent }));
		v.load();
		check('its own load leaves the artifact open', v.iframe.getAttribute('src') === ARTIFACT_AT('art-1'));
		v.load();
		check('a second load closes the artifact: src removed, frame hidden, the reader told',
			!v.iframe.hasAttribute('src') && v.iframe.style.display === 'none' && /closed/.test(v.status().textContent), v.status().textContent);
	}
	{
		const host = urlHost();
		const v = bootViewer(host);
		v.open('art-1');
		await tick(); await tick();
		host.pending['art-1'].reject(new Error(''));
		await tick(); await tick();
		check('a refused artifactUrl shows the load-error status, with no srcdoc to fall back on and no second chunk',
			v.status().textContent === 'Could not read the document.' && /documents-status-error/.test(v.status().className)
			&& v.srcdocWrites.length === 0 && !v.iframe.hasAttribute('src') && v.iframe.style.display === 'none'
			&& v.requests.length === 1, JSON.stringify({ status: v.status().textContent, requests: v.requests }));
	}
	{
		const host = urlHost();
		const v = bootViewer(host);
		v.open('art-1');
		await tick(); await tick();
		v.open('art-2');
		await tick(); await tick();
		host.pending['art-2'].resolve(ARTIFACT_AT('art-2'));
		await tick(); await tick();
		host.pending['art-1'].resolve(ARTIFACT_AT('art-1'));
		await tick(); await tick();
		v.load();
		check('a late address for a document the reader left is dropped: the frame keeps the one open, and its one load does not close it',
			v.iframe.getAttribute('src') === ARTIFACT_AT('art-2') && v.title() === 'Mock-up' && v.iframe.style.display === '',
			v.iframe.getAttribute('src'));
		v.open('art-1');
		await tick(); await tick();
		v.open('doc-1');
		await tick(); await tick();
		host.pending['art-1'].reject(new Error('gone'));
		await tick(); await tick();
		check('a late refusal is dropped too: the next document keeps its own status, and no artifact sits in the frame',
			v.status().textContent === 'MD · v3 · 9 characters' && !v.iframe.hasAttribute('src')
			&& v.iframe.style.display === 'none' && /Body of doc-1/.test(v.text().textContent), v.status().textContent);
	}
	// A host without artifactUrl, and no host: the 10.0.1 reads and the 10.0.1 frame, to the byte.
	for (const [label, host] of [['a host without artifactUrl', stubHost()], ['no host', undefined]]) {
		const v = bootViewer(host);
		v.open('art-1');
		await tick(); await tick(); await tick();
		check(label + ': both chunks are read and the srcdoc is the policy in front of the whole page, with no src',
			v.requests.join() === '/documents/art-1/content,/documents/art-1/content?offset=' + HEAD.length
			&& v.iframe.getAttribute('srcdoc') === v.frame.artifactDocument(HEAD + TAIL) && !v.iframe.hasAttribute('src'),
			JSON.stringify(v.requests));
	}

	console.log('Console host (W85 S5)');
	console.log(results.join('\n'));
	console.log('\n=== ' + pass + ' passed, ' + fail + ' failed (of ' + (pass + fail) + ') ===');
	process.exit(fail ? 1 : 0);
})();
