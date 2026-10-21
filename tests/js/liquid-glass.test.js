// Simulated-DOM regression harness for the shared Liquid Glass engine
// (public/assets/js/liquid-glass.js) and the recruiter dock that consumes it
// (public/assets/js/recruiter-nav.js).
//
// jsdom has no layout engine, so tab geometry is stubbed with fixed rectangles.
// This exercises the spring state machine, the gesture hand-off, the option
// groups and the recruiter routing, not pixels. Run after:
//   npm install --no-save --no-package-lock jsdom
//   node tests/js/liquid-glass.test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '..', '..');
const ENGINE = fs.readFileSync(path.join(root, 'public/assets/js/liquid-glass.js'), 'utf8');
const NAV = fs.readFileSync(path.join(root, 'public/assets/js/recruiter-nav.js'), 'utf8');

const SECTIONS = ['overview', 'company', 'jobs', 'applicants', 'interviews'];
const TAB_WIDTH = 144;
const TAB_HEIGHT = 56;
const PAD_LEFT = 10;
const INSET_X = Math.max(5, Math.min(9, TAB_WIDTH * 0.07));
const BASE_WIDTH = TAB_WIDTH - INSET_X;
const INSET_Y = Math.max(4, Math.min(7, TAB_HEIGHT * 0.08));
const CAPSULE_HEIGHT = TAB_HEIGHT - INSET_Y;

const layers = `
    <span class="lg-smoke"></span>
    <span class="lg-specular"></span>
    <span class="lg-rim"></span>
    <span class="lg-caustic"></span>
    <span class="lg-glint"></span>`;

const page = (activeIndex, extra = '') => `<!doctype html><html><head><title>${SECTIONS[activeIndex]}</title></head><body class="recruiter-portal">
<div class="rec-topbar"><span class="rec-company-state">Approved</span></div>
<main id="main-content">
  <div class="rec-page-content" id="recruiter-section-content">
    <div class="rec-page-heading"><h1>${SECTIONS[activeIndex]}</h1></div>
  </div>
  ${extra}
  <p class="sr-only" id="recruiter-nav-status" aria-live="polite"></p>
</main>
<nav class="rec-bottom-nav" data-glass-dock aria-label="Recruiter workspace">
  <span class="lg-indicator" aria-hidden="true">${layers}
  </span>
  ${SECTIONS.map((section, index) => `<a class="rec-nav-link${index === activeIndex ? ' is-active' : ''}" data-rec-section="${section}" href="recruiter.php?section=${section}"${index === activeIndex ? ' aria-current="page"' : ''}><span class="rec-nav-icon"></span><span class="rec-nav-label">${section}</span></a>`).join('\n  ')}
</nav>
</body></html>`;

function stubGeometry(link, index, visible = true) {
  const left = visible ? PAD_LEFT + index * TAB_WIDTH : 0;
  const width = visible ? TAB_WIDTH : 0;
  const height = visible ? TAB_HEIGHT : 0;
  const top = visible ? 7 : 0;
  Object.defineProperty(link, 'offsetLeft', { configurable: true, get: () => left });
  Object.defineProperty(link, 'offsetWidth', { configurable: true, get: () => width });
  Object.defineProperty(link, 'offsetTop', { configurable: true, get: () => top });
  Object.defineProperty(link, 'offsetHeight', { configurable: true, get: () => height });
  Object.defineProperty(link, 'offsetParent', { configurable: true, get: () => null });
  link.getBoundingClientRect = () => ({ left, top, width, height, right: left + width, bottom: top + height });
}

function buildDock({ activeIndex = 0, storedIndex = null, reducedMotion = false, deferLayout = false } = {}) {
  const dom = new JSDOM(page(activeIndex), {
    url: `https://portal.test/recruiter.php?section=${SECTIONS[activeIndex]}`,
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const { window } = dom;
  const { document } = window;

  const store = new Map();
  if (storedIndex !== null) store.set('sviet-recruiter-nav-index', String(storedIndex));
  Object.defineProperty(window, 'sessionStorage', {
    value: {
      getItem: (key) => (store.has(key) ? store.get(key) : null),
      setItem: (key, value) => store.set(key, String(value)),
    },
  });
  window.matchMedia = () => ({ matches: reducedMotion, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });

  const nav = document.querySelector('.rec-bottom-nav');
  const indicator = document.querySelector('.lg-indicator');
  const links = Array.from(document.querySelectorAll('.rec-nav-link'));

  const applyGeometry = (visible) => {
    links.forEach((link, index) => stubGeometry(link, index, visible));
    const barWidth = visible ? TAB_WIDTH * 5 + PAD_LEFT * 2 : 0;
    const barHeight = visible ? TAB_HEIGHT + 14 : 0;
    nav.getBoundingClientRect = () => ({ left: 0, top: 0, width: barWidth, height: barHeight, right: barWidth, bottom: barHeight });
    Object.defineProperty(nav, 'clientLeft', { configurable: true, get: () => 0 });
    Object.defineProperty(nav, 'clientTop', { configurable: true, get: () => 0 });
    Object.defineProperty(nav, 'scrollWidth', { configurable: true, get: () => barWidth });
    Object.defineProperty(nav, 'clientWidth', { configurable: true, get: () => barWidth });
  };
  applyGeometry(!deferLayout);
  nav.scrollLeft = 0;
  nav.scrollTo = ({ left }) => { nav.scrollLeft = left; };

  const captures = new Set();
  nav.setPointerCapture = (id) => captures.add(id);
  nav.hasPointerCapture = (id) => captures.has(id);
  nav.releasePointerCapture = (id) => captures.delete(id);

  const removeChild = window.Element.prototype.removeChild;
  window.Element.prototype.removeChild = function removeChildPolitely(node) {
    if (node && node.parentNode === this) return removeChild.call(this, node);
    return node;
  };

  const requests = [];
  window.fetch = (url) => {
    requests.push({ url: String(url) });
    const requested = new URL(url);
    const index = SECTIONS.indexOf(requested.searchParams.get('section') || 'overview');
    return Promise.resolve({
      ok: true,
      url: requested.href,
      headers: { get: (name) => (name.toLowerCase() === 'content-type' ? 'text/html; charset=utf-8' : null) },
      text: () => Promise.resolve(page(index)),
    });
  };
  window.scrollTo = () => {};
  const pushes = [];
  const originalPush = window.history.pushState.bind(window.history);
  window.history.pushState = (state, title, url) => {
    pushes.push(String(url));
    originalPush(state, title, url);
  };

  window.eval(ENGINE);
  window.eval(NAV);
  return {
    dom, window, document, nav, indicator, links, store, requests, pushes,
    showLayout: () => applyGeometry(true),
  };
}

function buildGroup({ activeIndex = 1 } = {}) {
  const chips = ['All', 'Open', 'Closed', 'Draft'];
  const dom = new JSDOM(`<!doctype html><html><head><title>groups</title></head><body>
  <nav class="admin-chips lg-group" data-glass-group aria-label="Job status">
    <span class="lg-indicator" aria-hidden="true">${layers}
    </span>
    ${chips.map((chip, index) => `<button type="button" class="admin-chip lg-option${index === activeIndex ? ' is-active' : ''}"${index === activeIndex ? ' aria-current="page"' : ''}>${chip}</button>`).join('\n    ')}
  </nav>
  </body></html>`, { url: 'https://portal.test/admin-jobs.php', runScripts: 'outside-only', pretendToBeVisual: true });
  const { window } = dom;
  const { document } = window;

  window.matchMedia = () => ({ matches: false, addEventListener() {}, addListener() {} });
  const group = document.querySelector('[data-glass-group]');
  const indicator = group.querySelector('.lg-indicator');
  const options = Array.from(group.querySelectorAll('.lg-option'));

  options.forEach((option, index) => {
    const left = 4 + index * 78;
    option.getBoundingClientRect = () => ({ left, top: 4, width: 74, height: 32, right: left + 74, bottom: 36 });
  });
  group.getBoundingClientRect = () => ({ left: 0, top: 0, width: 4 * 78 + 8, height: 40, right: 4 * 78 + 8, bottom: 40 });
  Object.defineProperty(group, 'clientLeft', { get: () => 0 });
  Object.defineProperty(group, 'clientTop', { get: () => 0 });
  Object.defineProperty(group, 'scrollWidth', { get: () => 4 * 78 + 8 });
  Object.defineProperty(group, 'clientWidth', { get: () => 4 * 78 + 8 });
  group.scrollLeft = 0;

  window.eval(ENGINE);
  window.LiquidGlass.mountGroup(group);
  return { dom, window, document, group, indicator, options };
}

const pointer = (window, type, { x, y = 30, id = 1, pointerType = 'mouse', button = 0 } = {}) => {
  const event = new window.MouseEvent(type, { bubbles: true, cancelable: true, clientX: x, clientY: y, button });
  Object.defineProperty(event, 'pointerId', { value: id });
  Object.defineProperty(event, 'pointerType', { value: pointerType });
  Object.defineProperty(event, 'isPrimary', { value: true });
  return event;
};
const tabCenter = (index) => PAD_LEFT + index * TAB_WIDTH + TAB_WIDTH / 2;
const restingLeft = (index) => tabCenter(index) - BASE_WIDTH / 2;
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const number = (value, fallback = NaN) => {
  const match = /-?[\d.]+/.exec(value || '');
  return match ? Number(match[0]) : fallback;
};
const translateX = (indicator) => number(/translate3d\((-?[\d.]+)px/.exec(indicator.style.transform || '')?.[1]);
const baseWidth = (indicator) => number(indicator.style.width, 0);
const scaleOf = (indicator) => {
  const match = /scale3d\((-?[\d.]+),\s*(-?[\d.]+)/.exec(indicator.style.transform || '');
  return match ? [Number(match[1]), Number(match[2])] : [1, 1];
};
// The pill is laid out once and stretched with a transform, so its visible
// width is the laid-out width times the horizontal scale.
const width = (indicator) => baseWidth(indicator) * scaleOf(indicator)[0];
const scaleX = (indicator) => scaleOf(indicator)[0];
const slosh = (indicator) => number(indicator.style.getPropertyValue('--lg-slosh'), 0);
const click = (env, index) => env.links[index].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
const down = (env, index) => env.links[index].dispatchEvent(pointer(env.window, 'pointerdown', { x: tabCenter(index) }));

(async () => {
  // 1. Boot: one shared capsule, one tab wide, centred, hidden until measured.
  {
    const env = buildDock({ activeIndex: 0 });
    assert.equal(baseWidth(env.indicator), Number(BASE_WIDTH.toFixed(2)), 'capsule is laid out inset from the tab width');
    assert.equal(scaleX(env.indicator), 1, 'and rests unstretched');
    assert.equal(width(env.indicator) <= TAB_WIDTH, true, 'capsule never exceeds one tab');
    assert(Math.abs(translateX(env.indicator) - restingLeft(0)) < 0.2, 'capsule is centred on the active tab');
    assert.equal(env.indicator.style.height, `${CAPSULE_HEIGHT.toFixed(1)}px`, 'capsule height follows the tab');
    assert(Math.abs(number(env.indicator.style.top) - (7 + INSET_Y / 2)) < 0.11, 'capsule is vertically centred in the tab');
    assert.equal(env.indicator.querySelectorAll('.lg-smoke, .lg-specular, .lg-rim, .lg-caustic, .lg-glint').length, 5, 'all five material layers are present');
    assert.equal(env.nav.classList.contains('has-liquid-indicator'), false, 'capsule stays hidden before the first frame');
    await wait(40);
    assert(env.nav.classList.contains('has-liquid-indicator'), 'capsule is revealed after the first frame');
    assert.equal(env.store.get('sviet-recruiter-nav-index'), '0', 'the rendered tab becomes the handoff point');
    assert.equal(env.indicator.classList.contains('is-moving'), false, 'capsule is asleep at rest');
    assert.equal(slosh(env.indicator), 0, 'interior is level at rest');
    env.dom.window.close();
  }

  // 2. Click: spring flight, no jump, content swaps in place.
  {
    const env = buildDock({ activeIndex: 0 });
    await wait(30);
    click(env, 3);
    await wait(70);
    const midX = translateX(env.indicator);
    assert(midX > restingLeft(0) + 2 && midX < restingLeft(3), 'capsule is mid-flight between tabs');
    assert(width(env.indicator) > BASE_WIDTH, 'capsule stretches while travelling');
    assert(width(env.indicator) <= TAB_WIDTH + 0.01, 'stretch stays inside a single tab');
    assert(Math.abs(slosh(env.indicator)) > 0, 'interior liquid sloshes while travelling');
    assert(env.indicator.classList.contains('is-moving'), 'moving state is published for styling');
    assert(scaleOf(env.indicator)[1] < 1, 'capsule squashes slightly along travel');

    await wait(900);
    assert(Math.abs(translateX(env.indicator) - restingLeft(3)) < 0.2, 'capsule settles exactly on the destination tab');
    assert.equal(scaleX(env.indicator), 1, 'capsule returns to its pill width');
    assert.equal(env.indicator.classList.contains('is-moving'), false);
    assert.equal(slosh(env.indicator), 0, 'liquid levels out after settling');

    assert.equal(env.requests.length, 1, 'one section request per tab change');
    assert(env.requests[0].url.includes('section=applicants'));
    assert.equal(env.pushes.length, 1, 'history gains exactly one entry');
    assert.equal(env.store.get('sviet-recruiter-nav-index'), '3');
    assert.equal(env.links[3].getAttribute('aria-current'), 'page');
    assert.equal(env.links[0].getAttribute('aria-current'), null);
    assert.equal(env.document.title, 'applicants', 'the incoming section title is applied');
    env.dom.window.close();
  }

  // 3. The capsule never leaves its lane, even on the longest jump.
  {
    const env = buildDock({ activeIndex: 0 });
    click(env, 4);
    let widest = 0;
    for (let frame = 0; frame < 24; frame += 1) {
      await wait(16);
      widest = Math.max(widest, width(env.indicator));
    }
    assert(widest <= TAB_WIDTH + 0.01, `capsule stays under one tab wide (was ${widest})`);
    assert(widest > BASE_WIDTH, 'but it still deforms measurably');
    env.dom.window.close();
  }

  // 4. Dragging: the capsule follows the pointer continuously and commits on release.
  {
    const env = buildDock({ activeIndex: 0 });
    await wait(30);
    down(env, 0);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(0) + 8 }));
    await wait(50);
    assert(env.nav.classList.contains('is-dragging'), 'horizontal drag engages');
    assert(width(env.indicator) > BASE_WIDTH, 'capsule stretches under the finger');

    const before = translateX(env.indicator);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(1) }));
    await wait(120);
    const after = translateX(env.indicator);
    assert(after > before, 'capsule follows the pointer across tabs');
    assert(env.links[1].classList.contains('is-preview'), 'the hovered tab previews while dragging');
    assert(width(env.indicator) <= TAB_WIDTH + 0.01, 'drag stretch stays inside one tab');

    env.nav.dispatchEvent(pointer(env.window, 'pointerup', { x: tabCenter(1) }));
    await wait(900);
    assert(Math.abs(translateX(env.indicator) - restingLeft(1)) < 0.2, 'release settles on the nearest tab');
    assert.equal(env.requests.length, 1, 'release navigates once');
    assert(env.requests[0].url.includes('section=company'));
    assert.equal(env.links[1].getAttribute('aria-current'), 'page');
    assert.equal(env.links[1].classList.contains('is-preview'), false);
    assert.equal(env.nav.classList.contains('is-dragging'), false);
    env.dom.window.close();
  }

  // 5. Reversal compresses instead of ballooning, and cancel never navigates.
  {
    const env = buildDock({ activeIndex: 2 });
    down(env, 2);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(2) + 60 }));
    await wait(200);
    const fastWidth = width(env.indicator);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(2) + 58 }));
    await wait(240);
    const reversedWidth = width(env.indicator);
    assert(reversedWidth < fastWidth, 'reverse drag compresses the capsule after the burst');
    env.nav.dispatchEvent(pointer(env.window, 'pointercancel', { x: tabCenter(2) + 58 }));
    await wait(900);
    assert.equal(env.requests.length, 0, 'pointercancel never navigates');
    assert(Math.abs(translateX(env.indicator) - restingLeft(2)) < 0.2, 'pointercancel returns to the active tab');
    env.dom.window.close();
  }

  // 6. Vertical gestures stay page scrolls.
  {
    const env = buildDock({ activeIndex: 0 });
    down(env, 0);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(0) + 2, y: 90 }));
    await wait(60);
    assert.equal(env.nav.classList.contains('is-dragging'), false, 'vertical movement never engages the drag');
    env.nav.dispatchEvent(pointer(env.window, 'pointerup', { x: tabCenter(0) + 2, y: 90 }));
    await wait(60);
    assert.equal(env.requests.length, 0);
    env.dom.window.close();
  }

  // 7. A drag suppresses the synthetic click underneath.
  {
    const env = buildDock({ activeIndex: 0 });
    down(env, 0);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(1) }));
    await wait(60);
    env.nav.dispatchEvent(pointer(env.window, 'pointerup', { x: tabCenter(1) }));
    click(env, 1);
    await wait(80);
    assert.equal(env.requests.length, 1, 'the synthetic click is ignored');
    env.dom.window.close();
  }

  // 8. GET filters and history keep using the in-place section swap.
  {
    const env = buildDock({ activeIndex: 0 });
    const form = env.document.createElement('form');
    form.method = 'get';
    form.action = 'https://portal.test/recruiter.php';
    form.innerHTML = '<input name="section" value="jobs"><input name="status" value="published">';
    env.document.querySelector('#recruiter-section-content').appendChild(form);
    form.dispatchEvent(new env.window.Event('submit', { bubbles: true, cancelable: true }));
    await wait(80);
    assert.equal(env.pushes.length, 1);
    assert(env.pushes[0].includes('section=jobs') && env.pushes[0].includes('status=published'));

    await wait(900);
    env.window.history.pushState({}, '', '/recruiter.php?section=overview');
    env.window.dispatchEvent(new env.window.PopStateEvent('popstate', { state: {} }));
    await wait(120);
    assert.equal(env.requests.length, 2, 'popstate restores the previous section');
    assert(env.requests[1].url.includes('section=overview'));
    await wait(900);
    assert(Math.abs(translateX(env.indicator) - restingLeft(0)) < 0.2, 'capsule follows history navigation');
    env.dom.window.close();
  }

  // 9. Rapid tab switching stays stable and lands on the last choice.
  {
    const env = buildDock({ activeIndex: 0 });
    for (const index of [4, 1, 3, 2]) {
      click(env, index);
      await wait(35);
    }
    await wait(1000);
    assert(Math.abs(translateX(env.indicator) - restingLeft(2)) < 0.2, 'the capsule lands on the final tab');
    assert.equal(env.links[2].getAttribute('aria-current'), 'page');
    assert.equal(env.document.querySelectorAll('[aria-current="page"]').length, 1, 'exactly one active tab');
    assert(width(env.indicator) <= TAB_WIDTH + 0.01, 'no runaway stretch after rapid switching');
    env.dom.window.close();
  }

  // 10. Reduced motion: instant, undeformed, still functional.
  {
    const env = buildDock({ activeIndex: 0, reducedMotion: true });
    await wait(30);
    click(env, 4);
    await wait(40);
    assert(Math.abs(translateX(env.indicator) - restingLeft(4)) < 0.2, 'reduced motion moves instantly');
    assert.equal(scaleX(env.indicator), 1, 'no stretch under reduced motion');
    assert.equal(slosh(env.indicator), 0);
    assert.equal(env.requests.length, 1, 'sections still change');
    env.dom.window.close();
  }

  // 10b. A tap navigates. Regression: capturing the pointer on pointerdown made
  // the browser retarget the click to the dock, so `event.target` was the <nav>
  // and the link was never followed — drags worked, taps did nothing.
  {
    const env = buildDock({ activeIndex: 0 });
    await wait(40);
    const captured = [];
    env.nav.setPointerCapture = (id) => captured.push(id);

    down(env, 2);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(2) + 1 }));
    env.nav.dispatchEvent(pointer(env.window, 'pointerup', { x: tabCenter(2) + 1 }));
    await wait(60);
    assert.equal(captured.length, 0, 'the dock never captures the pointer, so the click target stays the link');

    click(env, 2);
    await wait(120);
    assert.equal(env.requests.length, 1, 'a tap loads the destination');
    assert(env.requests[0].url.includes('section=jobs'), `a tap opens the tapped section (got ${env.requests[0].url})`);
    assert.equal(env.document.querySelector('.rec-nav-link[data-rec-section="jobs"]').getAttribute('aria-current'), 'page');
    assert.deepEqual(env.pushes.map((url) => new URL(url).searchParams.get('section')), ['jobs'], 'and records history');
    env.dom.window.close();
  }

  // 10c. Even if an engine retargets the click to the dock, the tab under the
  // pointer is found and followed.
  {
    const env = buildDock({ activeIndex: 0 });
    await wait(40);
    env.document.elementFromPoint = () => env.links[3];
    const retargeted = new env.window.MouseEvent('click', {
      bubbles: true, cancelable: true, button: 0, detail: 1, clientX: tabCenter(3), clientY: 30,
    });
    env.nav.dispatchEvent(retargeted);
    await wait(140);
    assert.equal(env.requests.length, 1, 'a retargeted click still routes');
    assert(env.requests[0].url.includes('section=applicants'), 'to the tab under the pointer');
    env.dom.window.close();
  }

  // 11. Keyboard support: focus moves, the capsule previews without navigating.
  {
    const env = buildDock({ activeIndex: 0 });
    env.links[0].focus();
    const key = (name) => {
      const event = new env.window.KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true });
      env.nav.dispatchEvent(event);
      return event;
    };
    assert.equal(key('ArrowRight').defaultPrevented, true);
    assert.equal(env.document.activeElement, env.links[1]);
    await wait(900);
    assert(Math.abs(translateX(env.indicator) - restingLeft(1)) < 0.2, 'focus previews the next tab');
    assert.equal(env.requests.length, 0, 'keyboard preview does not navigate by itself');
    key('End');
    assert.equal(env.document.activeElement, env.links[4]);
    await wait(900);
    assert(Math.abs(translateX(env.indicator) - restingLeft(4)) < 0.2, 'End previews the last tab');
    env.dom.window.close();
  }

  // 12. Cross-page handoff starts where the visitor came from, then lands on the route.
  {
    const env = buildDock({ activeIndex: 0, storedIndex: 3 });
    assert(Math.abs(translateX(env.indicator) - restingLeft(3)) < 0.2, 'capsule boots where the visitor came from');
    await wait(1000);
    assert(Math.abs(translateX(env.indicator) - restingLeft(0)) < 0.2, 'then glides to the rendered section');
    assert.equal(env.store.get('sviet-recruiter-nav-index'), '0', 'the rendered section becomes the new handoff point');
    env.dom.window.close();
  }

  // 13. Option groups reuse the same capsule: click selects, nothing navigates.
  {
    const env = buildGroup({ activeIndex: 1 });
    await wait(40);
    const first = env.options[1].getBoundingClientRect();
    assert.equal(baseWidth(env.indicator), Number((74 - 3).toFixed(2)), 'group capsule is trimmed to the option');
    assert(Math.abs(translateX(env.indicator) - ((first.left + 37) - (74 - 3) / 2)) < 0.2, 'group capsule centres on the option');
    assert.equal(number(env.indicator.style.height), 32 - 3, 'group capsule uses the compact height');

    let selected = null;
    env.group.addEventListener('liquidglass:select', (event) => { selected = event.detail.index; });
    env.options[3].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await wait(900);
    assert.equal(env.options[3].getAttribute('aria-current'), 'page', 'the chosen option is marked current');
    assert.equal(env.options[1].hasAttribute('aria-current'), false);
    assert.equal(selected, 3, 'the group emits an informational select event');
    let widest = 0;
    for (let frame = 0; frame < 12; frame += 1) {
      widest = Math.max(widest, width(env.indicator));
      await wait(16);
    }
    assert(widest <= 75, `group stretch never exceeds the option width (was ${widest})`);
    env.dom.window.close();
  }

  // 14. Mounting before layout is safe; the capsule appears once measured.
  {
    const env = buildDock({ activeIndex: 2, deferLayout: true });
    await wait(40);
    assert.equal(width(env.indicator), 0, 'a dock without a box stays dormant');
    assert.equal(baseWidth(env.indicator), 0, 'nothing is laid out while unmeasured');
    assert.equal(env.nav.classList.contains('has-liquid-indicator'), true, 'reveal is still scheduled');
    env.showLayout();
    env.window.LiquidGlass.controller(env.nav).refresh();
    assert.equal(baseWidth(env.indicator), Number(BASE_WIDTH.toFixed(2)), 'the capsule measures once the dock is laid out');
    assert(Math.abs(translateX(env.indicator) - restingLeft(2)) < 0.2, 'and lands on the rendered tab');
    env.dom.window.close();
  }

  console.log('PASS liquid-glass engine + recruiter dock (16 scenarios, simulated DOM)');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
