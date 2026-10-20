/*
 * Admin / TPO workspace navigation.
 * Routing, history and content loading live here; the capsule itself is the
 * shared LiquidGlass engine (assets/js/liquid-glass.js).
 */
(() => {
  'use strict';

  const dock = document.querySelector('.admin-dock[data-glass-dock]');
  const main = document.querySelector('#admin-main');
  const content = document.querySelector('#admin-section-content');
  if (!dock || !main || !content || !window.LiquidGlass) return;

  const links = Array.from(dock.querySelectorAll('.admin-dock-link'));
  const glass = window.LiquidGlass.mount({
    bar: dock,
    track: document.querySelector('#admin-dock-track'),
    links,
    restoreKey: 'sviet-admin-nav-index',
  });
  if (!glass) return;

  const sectionIndexes = new Map(links.map((link, index) => [link.dataset.adminSection, index]));
  const navRoute = new URL(links[0].href, window.location.href);
  const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  let prefersReducedMotion = reducedMotionQuery.matches;
  let routeSequence = 0;
  let activeController = null;
  let contentSwipe = null;

  const isAdminRoute = (url) => url.origin === window.location.origin && url.pathname === navRoute.pathname;
  const indexForURL = (url) => {
    const section = url.searchParams.get('section') || links[0].dataset.adminSection;
    return sectionIndexes.has(section) ? sectionIndexes.get(section) : -1;
  };
  const directionBetween = (from, to) => (to > from ? 'forward' : to < from ? 'backward' : 'none');

  const announceSection = (parsedDocument) => {
    const status = document.querySelector('#admin-nav-status');
    const heading = parsedDocument.querySelector('.admin-page-heading h1');
    if (status && heading) status.textContent = `${heading.textContent.trim()} section loaded`;
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

  const syncBadge = (parsedDocument) => {
    const incoming = parsedDocument.querySelector('.admin-dock-badge');
    const current = dock.querySelector('.admin-dock-badge');
    if (incoming && current) {
      current.textContent = incoming.textContent;
      current.setAttribute('aria-label', incoming.getAttribute('aria-label') || '');
    } else if (incoming && !current) {
      const host = dock.querySelector('[data-admin-section="approvals"]');
      if (host) host.appendChild(incoming.cloneNode(true));
    } else if (!incoming && current) {
      current.remove();
    }
  };

  const navigateTo = async (index, destination, options = {}) => {
    const { pushHistory = true, force = false } = options;
    const url = destination instanceof URL ? destination : new URL(destination, window.location.href);
    if (!isAdminRoute(url) || index < 0 || index >= links.length) {
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
        throw new Error('The requested admin section could not be loaded.');
      }
      if (response.url && !isAdminRoute(new URL(response.url, window.location.href))) {
        throw new Error('The admin request left the workspace route.');
      }

      const html = await response.text();
      if (requestId !== routeSequence) return;
      const parsedDocument = new DOMParser().parseFromString(html, 'text/html');
      const incomingContent = parsedDocument.querySelector('#admin-section-content');
      const incomingDock = parsedDocument.querySelector('.admin-dock');
      if (!incomingContent || !incomingDock) throw new Error('The admin section response was incomplete.');

      content.innerHTML = incomingContent.innerHTML;
      // Filter chips inside the new section are fresh nodes: mount them on the
      // shared material before the content animation starts.
      if (window.LiquidGlass) window.LiquidGlass.refresh(content);
      syncBadge(parsedDocument);
      document.title = parsedDocument.title || document.title;
      const heading = content.querySelector('.admin-page-heading h1');
      if (heading) {
        heading.setAttribute('tabindex', '-1');
        heading.focus({ preventScroll: true });
      }
      announceSection(parsedDocument);
      animateContentIn(direction, requestId);
      if (pushHistory) window.history.pushState({ adminPortal: true }, '', url.href);
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
  dock.addEventListener('liquidglass:activate', (event) => {
    const index = event.detail.index;
    const link = links[index];
    if (!link) return;
    event.preventDefault();
    navigateTo(index, new URL(link.href, window.location.href));
  });

  /* Plain clicks on real links still route through here. */
  document.addEventListener('click', (event) => {
    const anchor = event.target.closest('a[href]');
    if (!anchor || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (anchor.target && anchor.target.toLowerCase() !== '_self') return;
    if (anchor.hasAttribute('download')) return;

    const url = new URL(anchor.href, window.location.href);
    if (!isAdminRoute(url) || url.hash) return;
    if (url.searchParams.has('export')) return;
    const index = indexForURL(url);
    if (index < 0) return;
    event.preventDefault();
    navigateTo(index, url);
  });

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'get') return;
    const action = new URL(form.action || window.location.href, window.location.href);
    if (!isAdminRoute(action)) return;
    const data = event.submitter ? new FormData(form, event.submitter) : new FormData(form);
    action.search = new URLSearchParams(data).toString();
    if (action.searchParams.has('export')) return;
    const index = indexForURL(action);
    if (index < 0) return;
    event.preventDefault();
    navigateTo(index, action);
  });

  window.addEventListener('popstate', () => {
    const url = new URL(window.location.href);
    const index = indexForURL(url);
    if (!isAdminRoute(url) || index < 0) {
      window.location.reload();
      return;
    }
    navigateTo(index, url, { pushHistory: false, force: true });
  });

  /* ---------------------------------------------------- content swipes (touch) */
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

  const updateMotionPreference = (event) => { prefersReducedMotion = event.matches; };
  if (typeof reducedMotionQuery.addEventListener === 'function') reducedMotionQuery.addEventListener('change', updateMotionPreference);
  else if (typeof reducedMotionQuery.addListener === 'function') reducedMotionQuery.addListener(updateMotionPreference);
})();
