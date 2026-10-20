/*
 * Recruiter workspace navigation.
 * Routing and content loading stay here; the capsule is the shared
 * LiquidGlass engine (assets/js/liquid-glass.js).
 */
(() => {
  'use strict';

  const nav = document.querySelector('.rec-bottom-nav[data-glass-dock]');
  const main = document.querySelector('#main-content');
  const content = document.querySelector('#recruiter-section-content');
  if (!nav || !main || !content || !window.LiquidGlass) return;

  const links = Array.from(nav.querySelectorAll('.rec-nav-link'));
  const glass = window.LiquidGlass.mount({
    bar: nav,
    links,
    restoreKey: 'sviet-recruiter-nav-index',
  });
  if (!glass) return;

  const sectionIndexes = new Map(links.map((link, index) => [link.dataset.recSection, index]));
  const navRoute = new URL(links[0].href, window.location.href);
  const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  let prefersReducedMotion = reducedMotionQuery.matches;
  let routeSequence = 0;
  let activeController = null;
  let contentSwipe = null;
  let wheelDistance = 0;
  let wheelLocked = false;
  let wheelResetTimer = null;

  const isRecruiterRoute = (url) => url.origin === window.location.origin && url.pathname === navRoute.pathname;
  const indexForURL = (url) => {
    const section = url.searchParams.get('section') || links[0].dataset.recSection;
    return sectionIndexes.has(section) ? sectionIndexes.get(section) : -1;
  };
  const directionBetween = (from, to) => (to > from ? 'forward' : to < from ? 'backward' : 'none');

  const announceSection = (parsedDocument) => {
    const status = document.querySelector('#recruiter-nav-status');
    const heading = parsedDocument.querySelector('.rec-page-heading h1');
    if (status && heading) status.textContent = `${heading.textContent.trim()} section loaded`;
  };

  const updateCompanyState = (parsedDocument) => {
    const current = document.querySelector('.bubble-top .rec-company-state');
    const incoming = parsedDocument.querySelector('.bubble-top .rec-company-state');
    if (current && incoming) current.replaceWith(incoming.cloneNode(true));
  };

  const animateContentIn = (direction, requestId) => {
    content.classList.remove('is-pending', 'is-entering');
    content.dataset.direction = direction;
    void content.offsetWidth;
    content.classList.add('is-entering');
    const clearAnimation = (event) => {
      if (event && event.target !== content) return;
      content.classList.remove('is-entering');
      content.removeEventListener('animationend', clearAnimation);
    };
    content.addEventListener('animationend', clearAnimation);
    window.setTimeout(() => {
      if (requestId === routeSequence) clearAnimation();
    }, prefersReducedMotion ? 0 : 420);
  };

  const stopPendingRoute = () => {
    routeSequence += 1;
    if (activeController) activeController.abort();
    activeController = null;
    main.removeAttribute('aria-busy');
    content.classList.remove('is-pending', 'is-entering');
    content.dataset.direction = 'none';
  };

  const navigateTo = async (index, destination, options = {}) => {
    const { pushHistory = true, force = false } = options;
    const url = destination instanceof URL ? destination : new URL(destination, window.location.href);
    if (!isRecruiterRoute(url) || index < 0 || index >= links.length) {
      window.location.assign(url.href);
      return;
    }

    const direction = directionBetween(Math.max(0, glass.activeIndex()), index);
    glass.setActive(index);

    if (!force && url.href === window.location.href) {
      stopPendingRoute();
      return;
    }

    const requestId = ++routeSequence;
    if (activeController) activeController.abort();
    const controller = new AbortController();
    activeController = controller;
    main.setAttribute('aria-busy', 'true');
    content.dataset.direction = direction;
    content.classList.remove('is-entering');
    content.classList.add('is-pending');

    try {
      const response = await window.fetch(url.href, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        signal: controller.signal,
      });
      if (!response.ok || !/^text\/html\b/i.test(response.headers.get('content-type') || '')) {
        throw new Error('The requested recruiter section could not be loaded.');
      }
      if (response.url && !isRecruiterRoute(new URL(response.url, window.location.href))) {
        throw new Error('The recruiter request left the workspace route.');
      }

      const html = await response.text();
      if (requestId !== routeSequence) return;
      const parsedDocument = new DOMParser().parseFromString(html, 'text/html');
      const incomingContent = parsedDocument.querySelector('#recruiter-section-content');
      const incomingNav = parsedDocument.querySelector('.rec-bottom-nav');
      if (!incomingContent || !incomingNav) throw new Error('The recruiter section response was incomplete.');

      content.innerHTML = incomingContent.innerHTML;
      document.title = parsedDocument.title || document.title;
      const heading = content.querySelector('.rec-page-heading h1');
      if (heading) {
        heading.setAttribute('tabindex', '-1');
        heading.focus({ preventScroll: true });
      }
      updateCompanyState(parsedDocument);
      announceSection(parsedDocument);
      animateContentIn(direction, requestId);
      if (pushHistory) window.history.pushState({ recruiterPortal: true }, '', url.href);
      main.removeAttribute('aria-busy');
      if (pushHistory) window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
    } catch (error) {
      if (requestId !== routeSequence || (error && error.name === 'AbortError')) return;
      window.location.assign(url.href);
    } finally {
      if (requestId === routeSequence) {
        main.removeAttribute('aria-busy');
        if (activeController === controller) activeController = null;
      }
    }
  };

  /* Drag/keyboard selection from the capsule asks the page to take over. */
  nav.addEventListener('liquidglass:activate', (event) => {
    const index = event.detail.index;
    const link = links[index];
    if (!link) return;
    event.preventDefault();
    navigateTo(index, new URL(link.href, window.location.href));
  });

  document.addEventListener('click', (event) => {
    const anchor = event.target.closest('a[href]');
    if (!anchor || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (anchor.target && anchor.target.toLowerCase() !== '_self') return;
    if (anchor.hasAttribute('download')) return;

    const url = new URL(anchor.href, window.location.href);
    if (!isRecruiterRoute(url) || url.hash) return;
    const index = indexForURL(url);
    if (index < 0) return;
    event.preventDefault();
    navigateTo(index, url);
  });

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'get') return;
    const action = new URL(form.action || window.location.href, window.location.href);
    if (!isRecruiterRoute(action)) return;
    const data = event.submitter ? new FormData(form, event.submitter) : new FormData(form);
    action.search = new URLSearchParams(data).toString();
    const index = indexForURL(action);
    if (index < 0) return;
    event.preventDefault();
    navigateTo(index, action);
  });

  window.addEventListener('popstate', () => {
    const url = new URL(window.location.href);
    const index = indexForURL(url);
    if (!isRecruiterRoute(url) || index < 0) {
      window.location.reload();
      return;
    }
    navigateTo(index, url, { pushHistory: false, force: true });
  });

  /* ------------------------------------------------------------ content swipes */
  const resetContentSwipe = () => {
    contentSwipe = null;
    content.classList.remove('is-swiping');
    content.style.transform = '';
    content.style.opacity = '';
  };

  main.addEventListener('pointerdown', (event) => {
    if (event.pointerType !== 'touch' || !event.isPrimary) return;
    if (event.target.closest('a, button, input, select, textarea, summary, [contenteditable="true"], [role="button"]')) return;
    contentSwipe = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, active: false };
  });

  main.addEventListener('pointermove', (event) => {
    if (!contentSwipe || event.pointerId !== contentSwipe.pointerId) return;
    const deltaX = event.clientX - contentSwipe.x;
    const deltaY = event.clientY - contentSwipe.y;
    if (!contentSwipe.active) {
      if (Math.abs(deltaX) < 12 || Math.abs(deltaX) <= Math.abs(deltaY) * 1.2) return;
      contentSwipe.active = true;
      content.classList.add('is-swiping');
    }
    if (prefersReducedMotion) return;
    const follow = Math.max(-22, Math.min(22, deltaX * 0.16));
    content.style.transform = `translate3d(${follow.toFixed(2)}px, 0, 0)`;
    content.style.opacity = String(Math.max(0.82, 1 - Math.abs(deltaX) / 900));
  });

  main.addEventListener('pointerup', (event) => {
    if (!contentSwipe || event.pointerId !== contentSwipe.pointerId) return;
    const deltaX = event.clientX - contentSwipe.x;
    const deltaY = event.clientY - contentSwipe.y;
    const wasActive = contentSwipe.active;
    resetContentSwipe();
    const threshold = Math.max(54, main.clientWidth * 0.12);
    if (wasActive && Math.abs(deltaX) >= threshold && Math.abs(deltaX) > Math.abs(deltaY) * 1.2) {
      const next = Math.max(0, Math.min(links.length - 1, glass.activeIndex() + (deltaX < 0 ? 1 : -1)));
      if (next !== glass.activeIndex()) navigateTo(next, new URL(links[next].href, window.location.href));
    }
  });
  main.addEventListener('pointercancel', resetContentSwipe);

  main.addEventListener('wheel', (event) => {
    if (Math.abs(event.deltaX) < 8 || Math.abs(event.deltaX) <= Math.abs(event.deltaY) * 1.15) return;
    wheelDistance += event.deltaX;
    window.clearTimeout(wheelResetTimer);
    wheelResetTimer = window.setTimeout(() => {
      wheelDistance = 0;
      wheelLocked = false;
    }, 180);
    if (wheelLocked || Math.abs(wheelDistance) < 68) return;
    const next = Math.max(0, Math.min(links.length - 1, glass.activeIndex() + (wheelDistance > 0 ? 1 : -1)));
    wheelDistance = 0;
    wheelLocked = true;
    if (next !== glass.activeIndex()) navigateTo(next, new URL(links[next].href, window.location.href));
    window.setTimeout(() => { wheelLocked = false; }, 460);
  }, { passive: true });

  const updateMotionPreference = (event) => { prefersReducedMotion = event.matches; };
  if (typeof reducedMotionQuery.addEventListener === 'function') reducedMotionQuery.addEventListener('change', updateMotionPreference);
  else if (typeof reducedMotionQuery.addListener === 'function') reducedMotionQuery.addListener(updateMotionPreference);
})();
