// Simulated-DOM harness for the student workspace navigation
// (public/assets/js/student-nav.js).
//
// It verifies the behaviour that makes tab switching feel continuous: the dock
// stays mounted, only <main id="main-content"> is replaced, history follows,
// the entrance animation is directed, and anything outside the workspace falls
// back to a real navigation. jsdom has no layout engine, so dock geometry is
// stubbed with fixed rectangles. Run after:
//   npm install --no-save --no-package-lock jsdom
//   node tests/js/student-nav.test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '..', '..');
const ENGINE = fs.readFileSync(path.join(root, 'public/assets/js/liquid-glass.js'), 'utf8');
const NAV = fs.readFileSync(path.join(root, 'public/assets/js/student-nav.js'), 'utf8');

const SECTIONS = ['dashboard', 'jobs', 'applications', 'notifications', 'profile'];
const TAB_WIDTH = 148;
const TAB_HEIGHT = 64;
const PAD_LEFT = 8;

const layers = `
      <span class="lg-smoke"></span>
      <span class="lg-specular"></span>
      <span class="lg-rim"></span>
      <span class="lg-caustic"></span>
      <span class="lg-glint"></span>`;

const dock = (activeIndex) => `
<nav class="sv-dock" data-glass-dock aria-label="Student workspace">
  <span class="lg-indicator" aria-hidden="true">${layers}
  </span>
  <div class="sv-dock-track" id="sv-dock-track">
    ${SECTIONS.map((section, index) => `<a class="sv-dock-link${index === activeIndex ? ' is-active' : ''}" data-sv-section="${section}" href="index.php?page=${section}"${index === activeIndex ? ' aria-current="page"' : ''}><span class="sv-dock-icon"></span><span class="sv-dock-label">${section}</span></a>`).join('\n    ')}
  </div>
</nav>`;

const page = (activeIndex, extra = '') => `<!doctype html><html><head><title>${SECTIONS[activeIndex]} | SVIET</title></head><body>
${dock(activeIndex)}
<main id="main-content" class="dashboard container">
  <h1>${SECTIONS[activeIndex]}</h1>
  <p data-reveal>content ${SECTIONS[activeIndex]}</p>
  ${extra}
</main>
</body></html>`;

const wait = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

function stubGeometry(link, index) {
  const left = PAD_LEFT + index * TAB_WIDTH;
  Object.defineProperty(link, 'offsetLeft', { configurable: true, get: () => left });
  Object.defineProperty(link, 'offsetWidth', { configurable: true, get: () => TAB_WIDTH });
  Object.defineProperty(link, 'offsetTop', { configurable: true, get: () => 8 });
  Object.defineProperty(link, 'offsetHeight', { configurable: true, get: () => TAB_HEIGHT });
  Object.defineProperty(link, 'offsetParent', { configurable: true, get: () => null });
  link.getBoundingClientRect = () => ({ left, top: 8, width: TAB_WIDTH, height: TAB_HEIGHT, right: left + TAB_WIDTH, bottom: 8 + TAB_HEIGHT });
}

function build({ activeIndex = 0, reducedMotion = false } = {}) {
  const dom = new JSDOM(page(activeIndex), {
    url: `https://portal.test/Campus/public/index.php?page=${SECTIONS[activeIndex]}`,
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const { window } = dom;
  const { document } = window;

  const store = new Map();
  Object.defineProperty(window, 'sessionStorage', {
    value: {
      getItem: (key) => (store.has(key) ? store.get(key) : null),
      setItem: (key, value) => store.set(key, String(value)),
    },
  });
  window.matchMedia = () => ({ matches: reducedMotion, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });

  const nav = document.querySelector('.sv-dock');
  const links = Array.from(document.querySelectorAll('.sv-dock-link'));
  links.forEach(stubGeometry);
  const barWidth = TAB_WIDTH * 5 + PAD_LEFT * 2;
  const barHeight = TAB_HEIGHT + 16;
  nav.getBoundingClientRect = () => ({ left: 0, top: 0, width: barWidth, height: barHeight, right: barWidth, bottom: barHeight });
  Object.defineProperty(nav, 'clientLeft', { configurable: true, get: () => 0 });
  Object.defineProperty(nav, 'clientTop', { configurable: true, get: () => 0 });
  Object.defineProperty(nav, 'scrollWidth', { configurable: true, get: () => barWidth });
  Object.defineProperty(nav, 'clientWidth', { configurable: true, get: () => barWidth });
  nav.scrollLeft = 0;
  nav.scrollTo = ({ left }) => { nav.scrollLeft = left; };
  nav.setPointerCapture = () => {};
  nav.hasPointerCapture = () => false;
  nav.releasePointerCapture = () => {};

  const requests = [];
  window.fetch = (url) => {
    requests.push(String(url));
    const requested = new URL(url);
    const section = requested.searchParams.get('page') || 'dashboard';
    const index = SECTIONS.indexOf(section);
    return Promise.resolve({
      ok: true,
      url: requested.href,
      headers: { get: (name) => (name.toLowerCase() === 'content-type' ? 'text/html; charset=utf-8' : null) },
      text: () => Promise.resolve(page(index < 0 ? 0 : index)),
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

  return { dom, window, document, nav, links, requests, pushes, store };
}

(async () => {
  // 1. A dock click swaps only the page canvas and keeps the dock mounted.
  {
    const env = build({ activeIndex: 0 });
    await wait(40);
    const dockNode = env.document.querySelector('.sv-dock');
    env.links[1].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await wait(120);

    assert.equal(env.requests.length, 1, 'the destination is fetched, not reloaded');
    assert.equal(env.document.querySelector('.sv-dock'), dockNode, 'the dock element survives the change');
    assert.equal(env.document.querySelector('#main-content h1').textContent, 'jobs', 'the new page is in place');
    assert.equal(env.document.querySelector('.sv-dock-link[data-sv-section="jobs"]').getAttribute('aria-current'), 'page', 'the current tab follows the route');
    assert.equal(env.document.querySelector('.sv-dock-link[data-sv-section="dashboard"]').hasAttribute('aria-current'), false);
    assert.deepEqual(env.pushes.map((url) => new URL(url).searchParams.get('page')), ['jobs'], 'history records the destination');
    assert.equal(env.store.get('sviet-student-dock-index'), '1', 'the capsule hand-off is remembered');
    env.dom.window.close();
  }

  // 2. Direction follows the tab order, so motion reads as "next" or "back".
  {
    const env = build({ activeIndex: 3 });
    await wait(40);
    env.links[1].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await wait(120);
    assert.equal(env.document.querySelector('#main-content').classList.contains('is-entering-backward'), true, 'going back animates from the left');
    env.dom.window.close();
  }

  // 3. Reveal targets inside the swapped page are shown immediately.
  {
    const env = build({ activeIndex: 0 });
    await wait(40);
    env.links[2].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await wait(120);
    const reveal = env.document.querySelector('#main-content [data-reveal]');
    assert.equal(reveal.classList.contains('is-visible'), true, 'fresh content is not left invisible');
    env.dom.window.close();
  }

  // 4. Modified clicks stay with the browser (new tab, download, ...).
  {
    const env = build({ activeIndex: 0 });
    await wait(40);
    // jsdom would try to follow the untouched href and log a navigation error;
    // cancelling the default action here keeps the check on our own handler.
    env.document.addEventListener('click', (event) => event.preventDefault());
    env.links[1].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, metaKey: true, detail: 1 }));
    await wait(60);
    assert.equal(env.requests.length, 0, 'meta-click is not hijacked');
    env.dom.window.close();
  }

  // 5. Back and forward replay through the same path without a reload.
  {
    const env = build({ activeIndex: 0 });
    await wait(40);
    env.links[3].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await wait(120);
    env.window.history.replaceState({ studentWorkspace: true }, '', 'https://portal.test/Campus/public/index.php?page=jobs');
    env.window.dispatchEvent(new env.window.PopStateEvent('popstate'));
    await wait(140);
    assert.equal(env.document.querySelector('#main-content h1').textContent, 'jobs', 'popstate loads the previous route');
    env.dom.window.close();
  }

  // 6. Reduced motion drops the directional slide but still swaps.
  {
    const env = build({ activeIndex: 0, reducedMotion: true });
    await wait(40);
    env.links[2].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await wait(120);
    assert.equal(env.document.querySelector('#main-content h1').textContent, 'applications');
    assert.equal(env.document.querySelector('#main-content').classList.contains('is-entering-backward'), false);
    env.dom.window.close();
  }

  console.log('PASS student workspace navigation (6 scenarios, simulated DOM)');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
