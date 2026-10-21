/*
 * Student workspace navigation.
 * The dock stays mounted for the whole session: switching tabs fetches the
 * destination, swaps <main id="main-content"> and lets the browser history
 * follow along, exactly like the recruiter and admin workspaces. That is what
 * makes the change of page feel continuous instead of a white reload.
 *
 * Anything we cannot handle safely (server redirects, a session that expired,
 * a link that leaves the student workspace, a form post) falls straight back
 * to a normal document load, which is itself covered by the cross-document
 * view transition declared in theme.css.
 */
(() => {
  'use strict';

  const dock = document.querySelector('.sv-dock[data-glass-dock]');
  if (!dock || !window.LiquidGlass) return;

  const links = Array.from(dock.querySelectorAll('.sv-dock-link'));
  const glass = window.LiquidGlass.mount({
    bar: dock,
    track: dock.querySelector('#sv-dock-track'),
    links,
    restoreKey: 'sviet-student-dock-index',
  });
  if (!glass) return;

  const workspace = new URL(links[0].href, window.location.href);
  const sectionIndexes = new Map(links.map((link, index) => [link.dataset.svSection, index]));
  const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  let reducedMotion = motionQuery.matches;
  let routeSequence = 0;
  let activeController = null;

  const isWorkspaceRoute = (url) => url.origin === window.location.origin
    && url.pathname === workspace.pathname;

  const indexForURL = (url) => {
    const section = url.searchParams.get('page') || '';
    return sectionIndexes.has(section) ? sectionIndexes.get(section) : -1;
  };

  const directionBetween = (from, to) => (to > from ? 'forward' : to < from ? 'backward' : 'none');

  const markActive = (index) => {
    links.forEach((link, position) => {
      const active = position === index;
      link.classList.toggle('is-active', active);
      if (active) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
  };

  /* New markup is already above the fold, so it must not sit waiting for the
     scroll observer that only runs on a fresh document. */
  const revealNow = (root) => {
    root.querySelectorAll('[data-reveal]').forEach((element) => {
      element.classList.add('is-visible');
    });
  };

  const settleIncomingPage = (main, direction, requestId) => {
    main.classList.remove('is-pending', 'is-entering-forward', 'is-entering-backward');
    void main.offsetWidth;
    if (reducedMotion || direction === 'none') {
      main.classList.add('is-entering');
      window.setTimeout(() => {
        if (requestId === routeSequence) main.classList.remove('is-entering');
      }, 0);
      return;
    }
    main.classList.add(direction === 'backward' ? 'is-entering-backward' : 'is-entering-forward');
    const done = () => {
      main.classList.remove('is-entering-forward', 'is-entering-backward');
      main.removeEventListener('animationend', done);
    };
    main.addEventListener('animationend', done);
    window.setTimeout(() => {
      if (requestId === routeSequence) done();
    }, 460);
  };

  const focusNewPage = (main) => {
    const target = main.querySelector('h1') || main;
    if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
    target.focus({ preventScroll: true });
  };

  const navigateTo = async (index, destination, options = {}) => {
    const { pushHistory = true, force = false } = options;
    const url = destination instanceof URL ? destination : new URL(destination, window.location.href);
    if (!isWorkspaceRoute(url) || index < 0 || index >= links.length) {
      window.location.assign(url.href);
      return;
    }

    /* Read the direction from the address bar, not from the capsule: the
       engine has already moved the pill by the time a click reaches us. */
    const renderedIndex = indexForURL(new URL(window.location.href));
    const direction = directionBetween(Math.max(0, renderedIndex), index);
    glass.setActive(index);
    markActive(index);

    if (!force && url.href === window.location.href) return;

    const requestId = ++routeSequence;
    if (activeController) activeController.abort();
    const controller = new AbortController();
    activeController = controller;

    const currentMain = document.getElementById('main-content');
    if (currentMain) {
      currentMain.dataset.direction = direction;
      currentMain.classList.remove('is-entering-forward', 'is-entering-backward');
      currentMain.classList.add('is-pending');
    }

    try {
      const response = await window.fetch(url.href, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal,
      });
      if (!response.ok || !/^text\/html\b/i.test(response.headers.get('content-type') || '')) {
        throw new Error('The requested page could not be loaded.');
      }

      const html = await response.text();
      if (requestId !== routeSequence) return;
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const incomingMain = parsed.getElementById('main-content');
      const incomingDock = parsed.querySelector('.sv-dock[data-glass-dock]');
      /* A redirect to sign-in or an error page has no student dock: let the
         browser handle it as a real navigation rather than swapping in a
         fragment of a different page. */
      if (!incomingMain || !incomingDock) throw new Error('The page response was incomplete.');

      const outgoingMain = document.getElementById('main-content');
      if (outgoingMain) {
        outgoingMain.replaceWith(document.importNode(incomingMain, true));
      } else {
        throw new Error('The page response was incomplete.');
      }

      document.title = parsed.title || document.title;
      const main = document.getElementById('main-content');
      main.classList.remove('is-pending');
      revealNow(main);
      if (pushHistory) window.history.pushState({ studentWorkspace: true }, '', url.href);
      window.scrollTo({ top: 0, behavior: reducedMotion ? 'auto' : 'smooth' });
      settleIncomingPage(main, direction, requestId);
      focusNewPage(main);
    } catch (error) {
      if (requestId !== routeSequence || (error && error.name === 'AbortError')) return;
      window.location.assign(url.href);
    } finally {
      if (requestId === routeSequence) {
        if (activeController === controller) activeController = null;
        const main = document.getElementById('main-content');
        if (main) main.classList.remove('is-pending');
      }
    }
  };

  /* Selection coming from a drag or the keyboard is ours to route. */
  dock.addEventListener('liquidglass:activate', (event) => {
    const link = links[event.detail.index];
    if (!link) return;
    event.preventDefault();
    navigateTo(event.detail.index, new URL(link.href, window.location.href));
  });

  document.addEventListener('click', (event) => {
    const anchor = event.target.closest('a[href]');
    if (!anchor || event.defaultPrevented || event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (anchor.target && anchor.target.toLowerCase() !== '_self') return;
    if (anchor.hasAttribute('download')) return;

    const url = new URL(anchor.href, window.location.href);
    if (!isWorkspaceRoute(url) || url.hash) return;
    const index = indexForURL(url);
    if (index < 0) return;
    event.preventDefault();
    navigateTo(index, url);
  });

  window.addEventListener('popstate', () => {
    const url = new URL(window.location.href);
    const index = indexForURL(url);
    if (!isWorkspaceRoute(url) || index < 0) {
      window.location.reload();
      return;
    }
    navigateTo(index, url, { pushHistory: false, force: true });
  });

  const onMotionChange = (event) => { reducedMotion = event.matches; };
  if (typeof motionQuery.addEventListener === 'function') motionQuery.addEventListener('change', onMotionChange);
  else if (typeof motionQuery.addListener === 'function') motionQuery.addListener(onMotionChange);
})();
