/*
 * The chat opens on the first tap when a caching plugin delays scripts (11.6.4).
 *
 * A caching plugin that holds a page's scripts until the first touch can, on a
 * phone, cancel that first tap's click and send its own click instead, at the
 * tapped point, to the element the page sees: for a tap inside the widget, its
 * shadow host. The launcher and the greeting bubble live inside the shadow root,
 * so that click never reached them and the first tap opened nothing.
 *
 * A browser's own click always starts at an element inside the shadow root; only
 * a script starts one at the host itself. So a click that starts at the host is
 * passed on to the widget's button under its point. Every other click, touch and
 * pointer event is left exactly as it was, so a phone, a mouse, a key, a screen
 * reader and a browser that sends a finger as pointer events alone all keep the
 * one click they had.
 *
 * Runs the SHIPPED loader, whole, in a jsdom page. jsdom does no layout, so the
 * shadow root's elementFromPoint is stubbed to return the element the check puts
 * under the point.
 *
 * Bite: CC_LOADER=/path/to/11.6.3/loader.js node tests/browser/harness-cc-first-tap.js
 *       fails 1, 2 and 3: a click sent to the host reaches nothing. A loader that acts
 *       on a touch pointerup and cancels its touchend fails 7 as well: with no touch
 *       events the launcher opens and closes on one tap, and the bubble opens twice.
 *       Without the host-origin test, 5 and 7 fail (every tap acts twice); without the
 *       shadow-root test, 4 fails (a page button behind the point is clicked).
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const LOADER = extract.customerChatLoader();

async function boot(config) {
  const dom = new JSDOM(
    '<!doctype html><html><body><button id="cta" data-def-chat-trigger>Ask</button></body></html>',
    { url: 'https://example.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  extract.stubMatchMedia(w);
  const realTimeout = w.setTimeout.bind(w);
  w.setTimeout = (fn, ms) => realTimeout(fn, ms === 5000 ? 0 : ms); // the greeting's wait
  w.WidrowAI = Object.assign({
    showFloatingButton: '1',
    chatDisplayMode: 'modal',
    chatModuleUrl: '',
    greetingBubbleEnabled: '',
    greetingBubbleText: '',
  }, config || {});
  if (w.document.readyState === 'loading') {
    await new Promise(r => w.document.addEventListener('DOMContentLoaded', r));
  }
  // No layout in jsdom: every box is 0..60 px, and the element under a point is
  // whatever the check says.
  const h = { w, doc: w.document, seen: [], under: null };
  w.Element.prototype.getBoundingClientRect = () => ({ left: 0, top: 0, right: 60, bottom: 60, width: 60, height: 60, x: 0, y: 0 });
  w.ShadowRoot.prototype.elementFromPoint = () => h.under;
  w.eval(LOADER);
  h.host = w.document.getElementById('def-customer-chat-host');
  h.root = h.host.shadowRoot;
  h.trigger = h.root.querySelector('.def-cc-trigger');
  // Every value aria-expanded takes, in order: one tap that opens then closes shows twice.
  // A record holds the value before its change; the value after is the next record's, or the current one.
  new w.MutationObserver(rs => rs.forEach((r, i) =>
    h.seen.push(i + 1 < rs.length ? rs[i + 1].oldValue : h.trigger.getAttribute('aria-expanded'))))
    .observe(h.trigger, { attributes: true, attributeOldValue: true, attributeFilter: ['aria-expanded'] });
  return h;
}

const tick = () => new Promise(r => setTimeout(r, 5));
const opts = { bubbles: true, cancelable: true, composed: true };

// What the caching plugin sends: a click at the host, at the tap's point, over `under`.
function clickHost(h, under) {
  h.under = under;
  h.host.dispatchEvent(new h.w.MouseEvent('click', { bubbles: true, cancelable: true, detail: 1, clientX: 30, clientY: 30 }));
}
// A finger as a browser sends it, with or without touch events, ending in its click.
// The button is under the point, as it is for a real tap.
function tap(h, el, touch) {
  h.under = el;
  const pt = Object.assign({ pointerType: 'touch', clientX: 30, clientY: 30 }, opts);
  el.dispatchEvent(new h.w.PointerEvent('pointerdown', pt));
  if (touch) el.dispatchEvent(new h.w.TouchEvent('touchstart', opts));
  el.dispatchEvent(new h.w.PointerEvent('pointerup', pt));
  const end = new h.w.TouchEvent('touchend', opts);
  if (touch) el.dispatchEvent(end);
  if (end.defaultPrevented) return; // a cancelled touchend gets no click from the browser
  el.dispatchEvent(new h.w.MouseEvent('click', Object.assign({ detail: 1, clientX: 30, clientY: 30 }, opts)));
}

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(pass + fail).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(pass + fail).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const greeting = { greetingBubbleEnabled: '1', greetingBubbleText: 'Hi' };

(async function () {
  let h;

  // The fault: the tap's own click is cancelled and a click is sent to the host instead.
  h = await boot();
  clickHost(h, h.trigger.querySelector('.def-cc-trigger-label'));
  await tick();
  const firstOpen = JSON.stringify(h.seen);
  clickHost(h, h.trigger);
  await tick();
  check('a click sent to the host over the launcher opens the chat once, and a second closes it once',
    firstOpen === '["true"]' && same(h.seen, ['true', 'false']), 'first=' + firstOpen + ' then=' + JSON.stringify(h.seen));

  h = await boot(greeting);
  await tick();
  const openBtn = h.root.querySelector('.def-cc-greeting-bubble-open');
  if (openBtn) clickHost(h, openBtn.querySelector('.def-cc-greeting-bubble-text'));
  await tick();
  check('a click sent to the host over the greeting bubble opens the chat once, and the bubble goes',
    !!openBtn && same(h.seen, ['true']) && !h.root.querySelector('.def-cc-greeting-bubble'),
    'bubble=' + !!openBtn + ' seen=' + JSON.stringify(h.seen));

  h = await boot(greeting);
  await tick();
  const x = h.root.querySelector('.def-cc-greeting-bubble-close');
  if (x) clickHost(h, x);
  await tick();
  check('a click sent to the host over the bubble\'s x dismisses the bubble and leaves the chat closed',
    !!x && same(h.seen, []) && !h.root.querySelector('.def-cc-greeting-bubble'), 'seen=' + JSON.stringify(h.seen));

  // Points over nothing of the widget's are not passed on.
  h = await boot();
  let pageClicks = 0;
  const cta = h.doc.getElementById('cta');
  cta.addEventListener('click', () => pageClicks++);
  clickHost(h, cta);
  await tick();
  clickHost(h, null);
  await tick();
  check('a click sent to the host over a page button, or over nothing, opens nothing and clicks nothing',
    same(h.seen, []) && pageClicks === 0, 'seen=' + JSON.stringify(h.seen) + ' pageClicks=' + pageClicks);

  // Every click a browser makes itself starts inside the shadow root: one action each, as before.
  h = await boot();
  tap(h, h.trigger, true);
  await tick();
  tap(h, h.trigger, true);
  await tick();
  check('a phone\'s tap with touch events (touchstart, pointerup, touchend, click) opens the chat once; a second closes it once',
    same(h.seen, ['true', 'false']), 'seen=' + JSON.stringify(h.seen));

  h = await boot();
  h.under = h.trigger;
  h.trigger.click();
  await tick();
  h.trigger.click();
  await tick();
  check('a click alone (Enter, Space, a screen reader) opens the chat, and a second closes it',
    same(h.seen, ['true', 'false']), 'seen=' + JSON.stringify(h.seen));

  h = await boot();
  tap(h, h.trigger, false);
  await tick();
  const launcherSeen = JSON.stringify(h.seen);
  h = await boot(greeting);
  await tick();
  const bubbleBtn = h.root.querySelector('.def-cc-greeting-bubble-open');
  if (bubbleBtn) tap(h, bubbleBtn, false);
  await tick();
  check('with pointer events and no touch events, a finger\'s tap (pointerdown, pointerup, then the click) opens the chat exactly once from the launcher and from the bubble',
    launcherSeen === '["true"]' && !!bubbleBtn && same(h.seen, ['true']),
    'launcher=' + launcherSeen + ' bubble=' + JSON.stringify(h.seen));

  h = await boot();
  h.doc.getElementById('cta').dispatchEvent(new h.w.MouseEvent('click', { bubbles: true, cancelable: true, detail: 1 }));
  await tick();
  check('a page button marked data-def-chat-trigger still opens the chat on its click',
    same(h.seen, ['true']), 'seen=' + JSON.stringify(h.seen));

  console.log('Customer Chat first-tap harness');
  console.log(results.join('\n'));
  console.log(pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})();
