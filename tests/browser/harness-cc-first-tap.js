/*
 * The chat opens on the first tap when a caching plugin delays scripts (11.6.4).
 *
 * A caching plugin that holds a page's scripts until the first touch can, on a
 * phone, cancel that first tap's click and send its own click to the widget's
 * shadow host instead. The launcher and the greeting bubble live inside the
 * shadow root and listened for click only, so the first tap opened nothing.
 * Now a finger acts on its pointerup (released over the button) and cancels the
 * tap's touchend, so the browser sends no second click for it; a mouse, a key and
 * a screen reader still arrive as a click and are handled as before.
 *
 * Runs the SHIPPED loader, whole, in a jsdom page. A tap is dispatched as a phone
 * dispatches it: pointerdown, touchstart, pointerup, touchend, then a click only
 * when the touchend was not cancelled. jsdom does no layout, so each button's box
 * is stubbed to 0..60 px.
 *
 * Bite: CC_LOADER=/path/to/11.6.3/loader.js node tests/browser/harness-cc-first-tap.js
 *       fails 1, 4 and 9: a tap whose click never reaches the button opens nothing.
 *       2 and 3 fail too, on the cancelled touchend alone: there the browser's own
 *       click is what toggles the chat.
 */
const { JSDOM } = require('jsdom');
const extract = require('./extract');

const LOADER = extract.customerChatLoader();
const BOX = { left: 0, top: 0, right: 60, bottom: 60, width: 60, height: 60, x: 0, y: 0 };

async function boot(config) {
  const dom = new JSDOM(
    '<!doctype html><html><body><button id="cta" data-def-chat-trigger>Ask</button></body></html>',
    { url: 'https://example.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  extract.stubMatchMedia(w);
  w.Element.prototype.getBoundingClientRect = () => BOX;
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
  w.eval(LOADER);
  const host = w.document.getElementById('def-customer-chat-host');
  const root = host.shadowRoot;
  const trigger = root.querySelector('.def-cc-trigger');
  // Every value aria-expanded takes, in order: one tap that opens then closes shows twice.
  const seen = [];
  new w.MutationObserver(rs => rs.forEach(() => seen.push(trigger.getAttribute('aria-expanded'))))
    .observe(trigger, { attributes: true, attributeFilter: ['aria-expanded'] });
  return { w, doc: w.document, host, root, trigger, seen };
}

const tick = () => new Promise(r => setTimeout(r, 5));
const opts = { bubbles: true, cancelable: true, composed: true };

// One finger tap on el. lostClick: a caching plugin cancelled the tap's click and
// sent its own to the host. at: where the finger lifts (default inside the box);
// a finger that moved off gets no click from the browser.
function tap(h, el, o) {
  o = o || {};
  const at = o.at || { clientX: 30, clientY: 30 };
  const pt = Object.assign({ pointerType: 'touch', clientX: 30, clientY: 30 }, opts);
  el.dispatchEvent(new h.w.PointerEvent('pointerdown', pt));
  el.dispatchEvent(new h.w.TouchEvent('touchstart', opts));
  if (o.up !== false) el.dispatchEvent(new h.w.PointerEvent('pointerup', Object.assign({}, pt, at)));
  const end = new h.w.TouchEvent('touchend', opts);
  el.dispatchEvent(end);
  if (o.lostClick) {
    h.host.dispatchEvent(new h.w.MouseEvent('click', { bubbles: true, cancelable: true, detail: 1 }));
  } else if (!end.defaultPrevented && !o.at) {
    el.dispatchEvent(new h.w.MouseEvent('click', Object.assign({ detail: 1 }, opts)));
  }
  return end;
}

let pass = 0, fail = 0;
const results = [];
function check(label, cond, detail) {
  if (cond) { pass++; results.push('  ok   ' + String(pass + fail).padStart(2) + '. ' + label); }
  else { fail++; results.push('  FAIL ' + String(pass + fail).padStart(2) + '. ' + label + (detail ? ' -- ' + detail : '')); }
}
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

(async function () {
  let h, end;

  // (a) the finger's pointer events and no click at all
  h = await boot();
  h.trigger.dispatchEvent(new h.w.PointerEvent('pointerdown', Object.assign({ pointerType: 'touch', clientX: 30, clientY: 30 }, opts)));
  h.trigger.dispatchEvent(new h.w.PointerEvent('pointerup', Object.assign({ pointerType: 'touch', clientX: 30, clientY: 30 }, opts)));
  await tick();
  check('a finger\'s pointerdown and pointerup on the launcher, with no click after, open the chat once',
    same(h.seen, ['true']), 'seen=' + JSON.stringify(h.seen));

  // (b) a whole tap, as a phone sends it
  h = await boot();
  end = tap(h, h.trigger);
  await tick();
  check('a whole tap opens the chat once and leaves it open: its touchend is cancelled, so no click follows',
    end.defaultPrevented && same(h.seen, ['true']), 'cancelled=' + end.defaultPrevented + ' seen=' + JSON.stringify(h.seen));
  end = tap(h, h.trigger);
  await tick();
  check('a tap on the open chat\'s launcher closes it once',
    end.defaultPrevented && same(h.seen, ['true', 'false']), 'seen=' + JSON.stringify(h.seen));

  // the fault itself: the caching plugin's click goes to the host, not the button
  h = await boot();
  tap(h, h.trigger, { lostClick: true });
  await tick();
  check('when a caching plugin takes the tap\'s click and clicks the shadow host instead, the first tap still opens the chat, once',
    same(h.seen, ['true']), 'seen=' + JSON.stringify(h.seen));

  // (c) keyboard and screen reader: a click with no pointer events
  h = await boot();
  h.trigger.click();
  await tick();
  h.trigger.click();
  await tick();
  check('a click alone (Enter, Space, a screen reader) still opens the chat, and a second closes it',
    same(h.seen, ['true', 'false']), 'seen=' + JSON.stringify(h.seen));

  // a mouse is left to its click
  h = await boot();
  h.trigger.dispatchEvent(new h.w.PointerEvent('pointerdown', Object.assign({ pointerType: 'mouse', clientX: 30, clientY: 30 }, opts)));
  h.trigger.dispatchEvent(new h.w.PointerEvent('pointerup', Object.assign({ pointerType: 'mouse', clientX: 30, clientY: 30 }, opts)));
  h.trigger.dispatchEvent(new h.w.MouseEvent('click', Object.assign({ detail: 1 }, opts)));
  await tick();
  check('a mouse click opens the chat once: its pointerup is not acted on, the click is',
    same(h.seen, ['true']), 'seen=' + JSON.stringify(h.seen));

  // (d) a finger that moves off the button before it lifts
  h = await boot();
  end = tap(h, h.trigger, { at: { clientX: 30, clientY: 140 } });
  await tick();
  check('a finger lifted outside the launcher\'s box does not open the chat, and its touchend is left alone',
    !end.defaultPrevented && same(h.seen, []), 'cancelled=' + end.defaultPrevented + ' seen=' + JSON.stringify(h.seen));

  // a scroll that starts on the button: the browser cancels the pointer, so no pointerup
  h = await boot();
  end = tap(h, h.trigger, { up: false, lostClick: true });
  h.trigger.dispatchEvent(new h.w.TouchEvent('touchend', opts)); // a touchend replayed later
  await tick();
  check('a scroll that starts on the launcher (no pointerup) and a touchend replayed later open nothing and cancel nothing',
    !end.defaultPrevented && same(h.seen, []), 'seen=' + JSON.stringify(h.seen));

  // (e) the greeting bubble
  const greeting = { greetingBubbleEnabled: '1', greetingBubbleText: 'Hi' };
  h = await boot(greeting);
  await tick();
  const openBtn = h.root.querySelector('.def-cc-greeting-bubble-open');
  end = openBtn && tap(h, openBtn, { lostClick: true });
  await tick();
  check('the greeting bubble: its first tap opens the chat once, though its click was taken, and the bubble goes',
    !!openBtn && end.defaultPrevented && same(h.seen, ['true']) && !h.root.querySelector('.def-cc-greeting-bubble'),
    'bubble=' + !!openBtn + ' seen=' + JSON.stringify(h.seen));

  h = await boot(greeting);
  await tick();
  const x = h.root.querySelector('.def-cc-greeting-bubble-close');
  end = x && tap(h, x);
  await tick();
  check('a tap on the bubble\'s x is not taken as opening: its click comes and dismisses the bubble, the chat stays closed',
    !!x && !end.defaultPrevented && same(h.seen, []) && !h.root.querySelector('.def-cc-greeting-bubble'),
    'seen=' + JSON.stringify(h.seen));

  // the page's own header button: in the page's DOM, so a caching plugin's click reaches it
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
