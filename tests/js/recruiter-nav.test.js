// Simulated-DOM regression harness for public/assets/js/recruiter-nav.js.
// jsdom has no layout engine, so link/indicator geometry is stubbed. This exercises the
// gesture state machine, not pixels; run it after installing jsdom:
//   npm install --no-save jsdom && node tests/js/recruiter-nav.test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const root = path.join(__dirname, '..', '..');
const SCRIPT = fs.readFileSync(path.join(root, 'public/assets/js/recruiter-nav.js'), 'utf8');
const SECTIONS = ['overview', 'company', 'jobs', 'applicants', 'interviews'];

const page = (activeIndex) => `<!doctype html><html><head><title>${SECTIONS[activeIndex]}</title></head><body class="recruiter-portal">
<div class="rec-topbar"><span class="rec-company-state">Approved</span></div>
<main id="main-content">
  <div class="rec-page-content" id="recruiter-section-content">
    <div class="rec-page-heading"><h1>${SECTIONS[activeIndex]}</h1></div>
  </div>
  <p class="sr-only" id="recruiter-nav-status" aria-live="polite"></p>
</main>
<nav class="rec-bottom-nav">
  <span class="rec-nav-indicator"></span>
  ${SECTIONS.map((section, index) => `<a class="rec-nav-link${index === activeIndex ? ' is-active' : ''}" data-rec-section="${section}" href="recruiter.php?section=${section}"${index === activeIndex ? ' aria-current="page"' : ''}><span class="rec-nav-label">${section}</span></a>`).join('\n  ')}
</nav>
</body></html>`;

function build({ activeIndex = 0, storedIndex = null, reducedMotion = false, linkWidth = 144, indicatorWidth = 42 } = {}) {
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
  const geometry = { left: 0, top: 0, indicatorWidth };
  links.forEach((link, index) => {
    const width = typeof linkWidth === 'function' ? linkWidth(index) : linkWidth;
    const left = typeof linkWidth === 'function'
      ? [0, 152, 300, 448, 600][index]
      : index * width;
    Object.defineProperty(link, 'offsetLeft', { get: () => left });
    Object.defineProperty(link, 'offsetWidth', { get: () => width });
  });
  Object.defineProperty(indicator, 'offsetWidth', { get: () => geometry.indicatorWidth });
  indicator.getBoundingClientRect = () => ({ width: geometry.indicatorWidth, left: 0, top: 0 });
  nav.getBoundingClientRect = () => ({ left: geometry.left, top: geometry.top, width: 720, height: 75 });
  Object.defineProperty(nav, 'clientLeft', { get: () => 0 });

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
  return { dom, window, document, nav, indicator, links, store, requests, pushes, geometry };
}

const pointer = (window, type, { x, y = 30, id = 1, pointerType = 'mouse', button = 0 } = {}) => {
  const event = new window.MouseEvent(type, { bubbles: true, cancelable: true, clientX: x, clientY: y, button });
  Object.defineProperty(event, 'pointerId', { value: id });
  Object.defineProperty(event, 'pointerType', { value: pointerType });
  Object.defineProperty(event, 'isPrimary', { value: true });
  return event;
};

const down = (env, x) => {
  const link = env.links.reduce((best, candidate) => {
    const center = candidate.offsetLeft + (candidate.offsetWidth / 2);
    const distance = Math.abs(center - x);
    return distance < best.distance ? { link: candidate, distance } : best;
  }, { link: env.links[0], distance: Infinity }).link;
  link.dispatchEvent(pointer(env.window, 'pointerdown', { x }));
};

const frame = (window) => new Promise((resolve) => window.requestAnimationFrame(() => resolve()));
const translateX = (indicator) => {
  const match = /translate3d\((-?[\d.]+)px/.exec(indicator.style.transform || '');
  return match ? Number(match[1]) : null;
};

(async () => {
  // 1. Static positioning + storage.
  {
    const env = build({ activeIndex: 0, storedIndex: 2 });
    assert.equal(translateX(env.indicator), 339, 'indicator primes at the stored previous tab');
    await new Promise((resolve) => setTimeout(resolve, 40));
    assert.equal(translateX(env.indicator), 51, 'indicator glides from the stored tab to the active tab');
    assert.equal(env.store.get('sviet-recruiter-nav-index'), '0');
    env.dom.window.close();
  }

  // 2. Dragging follows the pointer, stretches, and commits the destination.
  {
    const env = build({ activeIndex: 0 });
    assert.equal(translateX(env.indicator), 51, 'indicator is centred on overview');
    down(env, 72);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: 120 }));
    await frame(env.window);
    assert(env.nav.classList.contains('is-dragging'), 'drag state engages after the horizontal threshold');
    const stretched = env.indicator.getBoundingClientRect().width;
    assert(Number(env.indicator.style.width.replace('px', '')) > 42, 'indicator stretches while dragging');
    const firstFollow = translateX(env.indicator);
    assert(firstFollow > 0, 'indicator follows the pointer');

    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: 216 }));
    await frame(env.window);
    assert(translateX(env.indicator) > firstFollow, 'indicator keeps following the pointer horizontally');
    assert(env.links[1].classList.contains('is-preview'), 'neighbouring tab previews while dragging');

    env.nav.dispatchEvent(pointer(env.window, 'pointerup', { x: 216 }));
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.equal(env.requests.length, 1, 'drag release requests the destination section once');
    assert(env.requests[0].url.includes('section=company'), 'drag release targets the tab under the pointer');
    assert.equal(env.pushes.length, 1, 'drag release pushes one history entry');
    assert.equal(env.store.get('sviet-recruiter-nav-index'), '1', 'active index is remembered');
    assert.equal(env.indicator.style.width, '', 'indicator returns to its pill width on release');
    assert(!env.nav.classList.contains('is-dragging'));
    assert.equal(env.links[1].getAttribute('aria-current'), 'page');
    assert.equal(env.links[0].getAttribute('aria-current'), null);
    assert.equal(env.document.title, 'company', 'document title follows the section');
    assert.equal(env.document.querySelector('#recruiter-nav-status').textContent, 'company section loaded');
    assert.equal(env.document.querySelector('#recruiter-section-content .rec-page-heading h1').textContent, 'company');
    await new Promise((resolve) => setTimeout(resolve, 30));
    assert.equal(translateX(env.indicator), 195, 'indicator settles on the newly active tab');
    assert(stretched > 0);
    env.dom.window.close();
  }

  // 3. Direction reversal compresses the bubble.
  {
    const env = build({ activeIndex: 2 });
    down(env, 360);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: 420 }));
    await frame(env.window);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: 470 }));
    await frame(env.window);
    const widthForward = Number(env.indicator.style.width.replace('px', ''));
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: 466 }));
    await frame(env.window);
    const widthReversed = Number(env.indicator.style.width.replace('px', ''));
    assert(widthReversed < widthForward, 'reversing direction compresses the indicator');
    env.nav.dispatchEvent(pointer(env.window, 'pointercancel', { x: 466 }));
    assert(!env.nav.classList.contains('is-dragging'), 'pointercancel clears drag state');
    assert.equal(env.indicator.style.width, '', 'pointercancel restores the pill');
    assert.equal(env.requests.length, 0, 'pointercancel never navigates');
    env.dom.window.close();
  }

  // 4. Vertical movement stays a scroll, and taps still navigate.
  {
    const env = build({ activeIndex: 0 });
    down(env, 72);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: 74, y: 90 }));
    await frame(env.window);
    assert(!env.nav.classList.contains('is-dragging'), 'vertical gestures never start a drag');
    env.nav.dispatchEvent(pointer(env.window, 'pointerup', { x: 74, y: 90 }));

    env.links[3].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.equal(env.requests.length, 1);
    assert(env.requests[0].url.includes('section=applicants'), 'tap navigates to the tapped tab');
    assert.equal(translateX(env.indicator), 483);
    env.dom.window.close();
  }

  // 5. A suppressed click after a drag does not double-navigate.
  {
    const env = build({ activeIndex: 0 });
    down(env, 72);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: 216 }));
    await frame(env.window);
    env.nav.dispatchEvent(pointer(env.window, 'pointerup', { x: 216 }));
    env.links[1].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.equal(env.requests.length, 1, 'the synthetic click after a drag is ignored');
    env.dom.window.close();
  }

  // 6. GET filter forms and history navigation reuse the client-side section swap.
  {
    const env = build({ activeIndex: 0 });
    const form = env.document.createElement('form');
    form.method = 'get';
    form.action = 'https://portal.test/recruiter.php';
    form.innerHTML = '<input name="section" value="jobs"><input name="status" value="published">';
    env.document.querySelector('#recruiter-section-content').appendChild(form);
    form.dispatchEvent(new env.window.Event('submit', { bubbles: true, cancelable: true }));
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.equal(env.pushes.length, 1);
    assert(env.pushes[0].includes('section=jobs') && env.pushes[0].includes('status=published'), 'filter submits keep their query');

    env.window.history.pushState({}, '', '/recruiter.php?section=overview');
    env.window.dispatchEvent(new env.window.PopStateEvent('popstate', { state: {} }));
    await new Promise((resolve) => setTimeout(resolve, 30));
    assert.equal(env.requests.length, 2, 'popstate restores the previous section');
    assert(env.requests[1].url.includes('section=overview'), 'popstate fetches the history destination');
    env.dom.window.close();
  }

  // 7. Reduced motion keeps the bubble compact.
  {
    const env = build({ activeIndex: 1, reducedMotion: true });
    down(env, 216);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: 420 }));
    await frame(env.window);
    assert.equal(env.indicator.style.borderRadius, '', 'no liquid deformation with reduced motion');
    assert.equal(Number(env.indicator.style.width.replace('px', '')), 42, 'no stretch with reduced motion');
    env.dom.window.close();
  }

  // 8. Keyboard focus moves between tabs with arrow keys.
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

  // 9. Unknown routes fall back to full navigation.
  {
    const env = build({ activeIndex: 0 });
    env.window.history.pushState({}, '', '/recruiter.php?section=overview&mode=create');
    env.links[2].dispatchEvent(new env.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, detail: 1 }));
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.equal(env.requests.length, 1, 'job creation links use the same section route');
    env.dom.window.close();
  }

  // 10. Grabbing a tab off-centre never makes the bubble jump.
  {
    const env = build({ activeIndex: 0 });
    const resting = translateX(env.indicator);
    down(env, 102);
    env.nav.dispatchEvent(pointer(env.window, 'pointermove', { x: 110 }));
    await frame(env.window);
    const afterFirstFrame = translateX(env.indicator);
    assert(afterFirstFrame - resting < 16, 'bubble tracks the drag without jumping to the pointer');
    env.dom.window.close();
  }

  console.log('PASS recruiter nav gesture suite (10 scenarios, simulated DOM)');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
