// Simulated-DOM regression harness for public/assets/js/recruiter-nav.js.
// jsdom has no layout engine, so tab geometry is stubbed with fixed rectangles.
// This exercises the spring/gesture state machine, not pixels. Run after:
//   npm install --no-save --no-package-lock jsdom
//   node tests/js/recruiter-nav.test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '..', '..');
const SCRIPT = fs.readFileSync(path.join(root, 'public/assets/js/recruiter-nav.js'), 'utf8');
const SECTIONS = ['overview', 'company', 'jobs', 'applicants', 'interviews'];
const TAB_WIDTH = 144;
const TAB_HEIGHT = 56;
const INSET = Math.min(13, Math.max(6, TAB_WIDTH * 0.09));
const BASE_WIDTH = TAB_WIDTH - INSET;
const INSET_Y = Math.max(3, Math.min(4, TAB_HEIGHT * 0.06));

const page = (activeIndex) => `<!doctype html><html><head><title>${SECTIONS[activeIndex]}</title></head><body class="recruiter-portal">
<div class="rec-topbar"><span class="rec-company-state">Approved</span></div>
<main id="main-content">
  <div class="rec-page-content" id="recruiter-section-content">
    <div class="rec-page-heading"><h1>${SECTIONS[activeIndex]}</h1></div>
  </div>
  <p class="sr-only" id="recruiter-nav-status" aria-live="polite"></p>
</main>
<nav class="rec-bottom-nav">
  <span class="rec-nav-indicator">
    <span class="rec-nav-bubble-liquid"></span>
    <span class="rec-nav-bubble-rim"></span>
    <span class="rec-nav-bubble-caustic"></span>
  </span>
  ${SECTIONS.map((section, index) => `<a class="rec-nav-link${index === activeIndex ? ' is-active' : ''}" data-rec-section="${section}" href="recruiter.php?section=${section}"${index === activeIndex ? ' aria-current="page"' : ''}><span class="rec-nav-icon"></span><span class="rec-nav-label">${section}</span></a>`).join('\n  ')}
</nav>
</body></html>`;

function build({ activeIndex = 0, storedIndex = null, reducedMotion = false, tabWidth = TAB_WIDTH } = {}) {
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
  window.matchMedia = () => ({ matches: reducedMotion, addEventListener() {}, addListener() {} });

  const nav = document.querySelector('.rec-bottom-nav');
  const indicator = document.querySelector('.rec-nav-indicator');
  const links = Array.from(document.querySelectorAll('.rec-nav-link'));

  links.forEach((link, index) => {
    const left = index * tabWidth;
    Object.defineProperty(link, 'offsetLeft', { get: () => left });
    Object.defineProperty(link, 'offsetWidth', { get: () => tabWidth });
    Object.defineProperty(link, 'offsetTop', { get: () => 7 });
    Object.defineProperty(link, 'offsetHeight', { get: () => TAB_HEIGHT });
    link.getBoundingClientRect = () => ({ left, top: 7, width: tabWidth, height: TAB_HEIGHT, right: left + tabWidth, bottom: 7 + TAB_HEIGHT });
  });

  nav.getBoundingClientRect = () => ({ left: 0, top: 0, width: tabWidth * 5 + 20, height: TAB_HEIGHT + 14 });
  Object.defineProperty(nav, 'clientLeft', { get: () => 0 });
  window.getComputedStyle = (element) => ({
    borderLeftWidth: '1px',
    borderTopWidth: '1px',
    getPropertyValue: () => '',
  });

  const captures = new Set();
  nav.setPointerCapture = (id) => captures.add(id);
  nav.hasPointerCapture = (id) => captures.has(id);
  nav.releasePointerCapture = (id) => captures.delete(id);

  const requests = [];
  window.fetch = (url, options) => {
    requests.push({ url, options });
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

  window.eval(SCRIPT);
  return { dom, window, document, nav, indicator, links, store, requests, pushes };
}

const pointer = (window, type, { x, y = 30, id = 1, pointerType = 'mouse', button = 0 } = {}) => {
  const event = new window.MouseEvent(type, { bubbles: true, cancelable: true, clientX: x, clientY: y, button });
  Object.defineProperty(event, 'pointerId', { value: id });
  Object.defineProperty(event, 'pointerType', { value: pointerType });
  Object.defineProperty(event, 'isPrimary', { value: true });
  return event;
};
const tabCenter = (index, tabWidth = TAB_WIDTH) => (index * tabWidth) + tabWidth / 2;
// Nav has a 1px border, so the absolutely positioned capsule's origin sits 1px in.
const expectedLeft = (index) => tabCenter(index) - (BASE_WIDTH / 2) - 1;
const down = (env, x, extra = {}) => {
  const index = env.links.reduce((best, link, i) => (Math.abs(tabCenter(i) - x) < Math.abs(tabCenter(best) - x) ? i : best), 0);
  env.links[index].dispatchEvent(pointer(env.window, 'pointerdown', { x, ...extra }));
};
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const number = (value, fallback = NaN) => {
  const match = /-?[\d.]+/.exec(value || '');
  return match ? Number(match[0]) : fallback;
};
const translateX = (indicator) => {
  const match = /translate3d\((-?[\d.]+)px/.exec(indicator.style.transform || '');
  return match ? Number(match[1]) : NaN;
};
const width = (indicator) => number(indicator.style.width);

(async () => {
  // 1. Boot: capsule is one tab wide, centred on the active tab, hidden until ready.
  {
    const env = build({ activeIndex: 0 });
    assert.equal(width(env.indicator), Number(BASE_WIDTH.toFixed(2)), 'capsule is inset from the full tab width');
    assert(Math.abs(translateX(env.indicator) - expectedLeft(0)) < 0.2, 'capsule is centred on the active tab');
    assert.equal(env.indicator.style.height, `${(TAB_HEIGHT - INSET_Y * 2).toFixed(1)}px`, 'capsule height follows the tab');
    assert.equal(env.indicator.style.top, `${(7 - 1 + INSET_Y).toFixed(1)}px`, 'capsule is vertically centred');
    assert.equal(env.store.get('sviet-recruiter-nav-index'), '0');
    await wait(40);
    assert(env.nav.classList.contains('has-sliding-indicator'), 'capsule is revealed after the first frame');
    assert.equal(env.indicator.style.getPropertyValue('--rec-slosh'), '0.000', 'interior is level at rest');
    env.dom.window.close();
  }

  // 2. Click: the bubble glides (not jumps) and the section swaps in place.
  {
    const env = build({ activeIndex: 0 });
    await wait(30);
    env.links[3].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await wait(70);
    const midX = translateX(env.indicator);
    assert(midX > tabCenter(0) - BASE_WIDTH / 2 && midX < tabCenter(3) - BASE_WIDTH / 2, 'capsule is mid-flight between tabs');
    assert(width(env.indicator) > BASE_WIDTH, 'capsule stretches while travelling');
    assert(Math.abs(number(env.indicator.style.getPropertyValue('--rec-slosh'))) > 0, 'interior liquid sloshes while travelling');
    assert(env.indicator.classList.contains('is-stretched'), 'stretched state is published for styling');

    await wait(700);
    assert(Math.abs(translateX(env.indicator) - expectedLeft(3)) < 0.2, 'capsule settles exactly on the destination tab');
    assert.equal(width(env.indicator), Number(BASE_WIDTH.toFixed(2)), 'capsule returns to its pill width');
    assert.equal(env.indicator.classList.contains('is-stretched'), false);
    assert.equal(env.indicator.style.getPropertyValue('--rec-slosh'), '0.000', 'liquid levels out after settling');

    assert.equal(env.requests.length, 1, 'one section request per tab change');
    assert(env.requests[0].url.includes('section=applicants'));
    assert.equal(env.pushes.length, 1);
    assert.equal(env.store.get('sviet-recruiter-nav-index'), '3');
    assert.equal(env.links[3].getAttribute('aria-current'), 'page');
    assert.equal(env.links[0].getAttribute('aria-current'), null);
    assert.equal(env.document.title, 'applicants');
    env.dom.window.close();
  }

  // 3. The capsule never leaves its lane: width stays a single tab wide.
  {
    const env = build({ activeIndex: 0 });
    env.links[4].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    let widest = 0;
    for (let frame = 0; frame < 20; frame += 1) {
      await wait(16);
      widest = Math.max(widest, width(env.indicator));
    }
    assert(widest <= TAB_WIDTH + INSET * 0.5 + 0.01, `capsule stays under one tab wide (was ${widest})`);
    assert(widest > BASE_WIDTH, 'but it still deforms measurably');
    env.dom.window.close();
  }

  // 4. Dragging: the capsule follows the pointer continuously and commits on release.
  {
    const env = build({ activeIndex: 0 });
    await wait(30);
    down(env, tabCenter(0));
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(0) + 8 }));
    await wait(50);
    assert(env.nav.classList.contains('is-dragging'), 'vertical-safe horizontal drag engages');
    assert(width(env.indicator) > BASE_WIDTH, 'capsule stretches under the finger');

    const before = translateX(env.indicator);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(1) }));
    await wait(90);
    const after = translateX(env.indicator);
    assert(after > before, 'capsule follows the pointer across tabs');
    assert(env.links[1].classList.contains('is-preview'), 'the hovered tab previews while dragging');
    const dragWidth = width(env.indicator);
    assert(dragWidth > BASE_WIDTH, 'dragging keeps the liquid stretch');

    env.nav.dispatchEvent(pointer(env.window, 'pointerup', { x: tabCenter(1) }));
    await wait(700);
    assert(Math.abs(translateX(env.indicator) - expectedLeft(1)) < 0.2, 'release settles on the nearest tab');
    assert.equal(env.requests.length, 1);
    assert(env.requests[0].url.includes('section=company'));
    assert.equal(env.links[1].getAttribute('aria-current'), 'page');
    assert.equal(env.links[1].classList.contains('is-preview'), false);
    assert.equal(env.nav.classList.contains('is-dragging'), false);
    env.dom.window.close();
  }

  // 5. Reversal: the capsule compresses rather than ballooning.
  {
    const env = build({ activeIndex: 2 });
    down(env, tabCenter(2));
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(2) + 60 }));
    await wait(200);
    const fastWidth = width(env.indicator);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(2) + 58 }));
    await wait(220);
    const reversedWidth = width(env.indicator);
    assert(reversedWidth < fastWidth, 'interior drag compresses the capsule after the burst');
    env.nav.dispatchEvent(pointer(env.window, 'pointercancel', { x: tabCenter(2) + 58 }));
    await wait(700);
    assert.equal(env.requests.length, 0, 'pointercancel never navigates');
    assert(Math.abs(translateX(env.indicator) - expectedLeft(2)) < 0.2, 'pointercancel returns to the active tab');
    env.dom.window.close();
  }

  // 6. Vertical gestures stay page scrolls.
  {
    const env = build({ activeIndex: 0 });
    down(env, tabCenter(0));
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
    const env = build({ activeIndex: 0 });
    down(env, tabCenter(0));
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: tabCenter(1) }));
    await wait(60);
    env.nav.dispatchEvent(pointer(env.window, 'pointerup', { x: tabCenter(1) }));
    env.links[1].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await wait(80);
    assert.equal(env.requests.length, 1, 'the synthetic click is ignored');
    env.dom.window.close();
  }

  // 8. GET filters and history keep using the in-place section swap.
  {
    const env = build({ activeIndex: 0 });
    const form = env.document.createElement('form');
    form.method = 'get';
    form.action = 'https://portal.test/recruiter.php';
    form.innerHTML = '<input name="section" value="jobs"><input name="status" value="published">';
    env.document.querySelector('#recruiter-section-content').appendChild(form);
    form.dispatchEvent(new env.window.Event('submit', { bubbles: true, cancelable: true }));
    await wait(60);
    assert.equal(env.pushes.length, 1);
    assert(env.pushes[0].includes('section=jobs') && env.pushes[0].includes('status=published'));

    env.window.history.pushState({}, '', '/recruiter.php?section=overview');
    env.window.dispatchEvent(new env.window.PopStateEvent('popstate', { state: {} }));
    await wait(60);
    assert.equal(env.requests.length, 2, 'popstate restores the previous section');
    assert(env.requests[1].url.includes('section=overview'));
    await wait(700);
    assert(Math.abs(translateX(env.indicator) - expectedLeft(0)) < 0.2, 'capsule follows history navigation');
    env.dom.window.close();
  }

  // 9. Rapid tab switching stays stable and lands on the last choice.
  {
    const env = build({ activeIndex: 0 });
    for (const index of [4, 1, 3, 2]) {
      env.links[index].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
      await wait(35);
    }
    await wait(800);
    assert(Math.abs(translateX(env.indicator) - expectedLeft(2)) < 0.2, 'the capsule lands on the final tab');
    assert.equal(env.links[2].getAttribute('aria-current'), 'page');
    assert.equal(env.document.querySelectorAll('[aria-current="page"]').length, 1, 'exactly one active tab');
    assert(width(env.indicator) <= TAB_WIDTH + INSET * 0.5 + 0.01, 'no runaway stretch after rapid switching');
    env.dom.window.close();
  }

  // 10. Reduced motion: instant, undeformed, still functional.
  {
    const env = build({ activeIndex: 0, reducedMotion: true });
    await wait(30);
    env.links[4].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await wait(30);
    assert(Math.abs(translateX(env.indicator) - expectedLeft(4)) < 0.2, 'reduced motion moves instantly');
    assert.equal(width(env.indicator), Number(BASE_WIDTH.toFixed(2)), 'no stretch under reduced motion');
    assert.equal(env.indicator.style.getPropertyValue('--rec-slosh'), '0.000');
    assert.equal(env.requests.length, 1, 'sections still change');
    env.dom.window.close();
  }

  // 11. Keyboard support is unchanged.
  {
    const env = build({ activeIndex: 0 });
    env.links[0].focus();
    const key = (name) => {
      const event = new env.window.KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true });
      env.nav.dispatchEvent(event);
      return event;
    };
    assert.equal(key('ArrowRight').defaultPrevented, true);
    assert.equal(env.document.activeElement, env.links[1]);
    key('End');
    assert.equal(env.document.activeElement, env.links[4]);
    env.dom.window.close();
  }

  // 12. Cross-page handoff starts from the previously visited tab.
  {
    const env = build({ activeIndex: 0, storedIndex: 3 });
    assert(Math.abs(translateX(env.indicator) - expectedLeft(3)) < 0.2, 'capsule boots where the visitor came from');
    await wait(700);
    assert(Math.abs(translateX(env.indicator) - expectedLeft(0)) < 0.2, 'then glides to the rendered section');
    env.dom.window.close();
  }

  console.log('PASS recruiter nav liquid-glass suite (12 scenarios, simulated DOM)');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
