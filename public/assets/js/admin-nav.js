(() => {
  'use strict';

  const dock = document.querySelector('.admin-dock');
  const track = document.querySelector('#admin-dock-track');
  const main = document.querySelector('#admin-main');
  const content = document.querySelector('#admin-section-content');
  if (!dock || !track || !main || !content) return;

  const indicator = track.querySelector('.admin-dock-indicator');
  const links = Array.from(track.querySelectorAll('.admin-dock-link'));
  if (!indicator || links.length < 2) return;

  const activeLinkIndex = links.findIndex((link) => link.getAttribute('aria-current') === 'page');
  if (activeLinkIndex < 0) return;

  const sectionIndexes = new Map(links.map((link, index) => [link.dataset.adminSection, index]));
  const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  let prefersReducedMotion = reducedMotionQuery.matches;
  let activeIndex = activeLinkIndex;
  let routeSequence = 0;
  let activeController = null;
  let pointerDrag = null;
  let suppressClickUntil = 0;
  let contentSwipe = null;
  let metrics = [];

  /* ---------------------------------------------------------------------
   * The capsule lives inside the scrolled track, so scrolling the rail moves
   * it for free. Geometry is measured in the track's own coordinate space.
   * ------------------------------------------------------------------ */
  const measure = () => {
    metrics = links.map((link) => ({
      left: link.offsetLeft,
      width: link.offsetWidth,
      height: link.offsetHeight,
      top: link.offsetTop,
      center: link.offsetLeft + link.offsetWidth / 2,
    }));
  };

  const bubble = {
    x: 0,
    v: 0,
    target: 0,
    width: 0,
    baseWidth: 0,
    maxWidth: 0,
    frame: 0,
    last: 0,
    dragging: false,
  };
  const MOVE_SPRING = { stiffness: 300, damping: 27 };
  const DRAG_SPRING = { stiffness: 2200, damping: 94 };
  const FIXED_STEP = 1 / 240;

  const render = () => {
    const stretch = bubble.width - bubble.baseWidth;
    const direction = bubble.v > 0.5 ? 1 : bubble.v < -0.5 ? -1 : 0;
    const lead = direction * stretch * 0.4;
    const left = bubble.x - bubble.width / 2 + lead;
    const speedRatio = Math.min(1, Math.abs(bubble.v) / 1500);
    const squash = 1 - speedRatio * 0.055;
    const slosh = Math.max(-1, Math.min(1, -bubble.v / 800)) * speedRatio;

    indicator.style.width = `${bubble.width.toFixed(2)}px`;
    indicator.style.transform = `translate3d(${left.toFixed(2)}px, 0, 0) scaleY(${squash.toFixed(4)})`;
    indicator.style.setProperty('--adm-slosh', slosh.toFixed(3));
    indicator.style.setProperty('--adm-stretch', Math.min(1, stretch / Math.max(1, bubble.baseWidth * 0.18)).toFixed(3));
  };

  const applyMetrics = () => {
    if (metrics.length === 0) return;
    const narrowest = metrics.reduce((min, metric) => Math.min(min, metric.width), Infinity);
    // A larger droplet: the capsule owns almost the whole tab, so it reads as
    // one body of water rather than a highlight sitting inside a button.
    const inset = Math.max(2, Math.min(5, narrowest * 0.045));
    const insetY = Math.max(2, Math.min(3, metrics[0].height * 0.045));
    bubble.baseWidth = Math.max(46, narrowest - inset);
    bubble.maxWidth = narrowest + inset * 0.8;
    indicator.style.height = `${Math.max(40, metrics[0].height - insetY * 2).toFixed(1)}px`;
    indicator.style.top = `${(metrics[0].top + insetY).toFixed(1)}px`;
    if (!bubble.width) bubble.width = bubble.baseWidth;
    bubble.width = Math.min(bubble.width, bubble.baseWidth);
  };

  const desiredWidth = () => bubble.baseWidth + Math.max(0, Math.min(bubble.maxWidth - bubble.baseWidth, Math.abs(bubble.v) * 0.0135));

  const integrate = (dt) => {
    const spring = bubble.dragging ? DRAG_SPRING : MOVE_SPRING;
    let remaining = dt;
    while (remaining > 0) {
      const stepSize = Math.min(FIXED_STEP, remaining);
      bubble.v += (spring.stiffness * (bubble.target - bubble.x) - spring.damping * bubble.v) * stepSize;
      bubble.x += bubble.v * stepSize;
      remaining -= stepSize;
    }
    bubble.width += (desiredWidth() - bubble.width) * Math.min(1, dt * 17);
  };

  const isSettled = () => !bubble.dragging
    && Math.abs(bubble.v) < 1.5
    && Math.abs(bubble.target - bubble.x) < 0.2
    && Math.abs(bubble.width - bubble.baseWidth) < 0.3;

  const step = (now) => {
    bubble.frame = 0;
    const elapsed = now - bubble.last;
    bubble.last = now;
    integrate(Math.min(0.05, Math.max(0.008, elapsed > 0 ? elapsed / 1000 : 1 / 60)));
    if (isSettled()) {
      bubble.x = bubble.target;
      bubble.v = 0;
      bubble.width = bubble.baseWidth;
      render();
      return;
    }
    render();
    bubble.frame = window.requestAnimationFrame(step);
  };

  const startLoop = () => {
    if (prefersReducedMotion) {
      bubble.x = bubble.target;
      bubble.v = 0;
      bubble.width = bubble.baseWidth;
      render();
      return;
    }
    if (bubble.frame) return;
    bubble.last = window.performance.now();
    bubble.frame = window.requestAnimationFrame(step);
  };

  /** Keep a tab and its capsule inside the visible part of the rail. */
  const ensureVisible = (index, smooth = true) => {
    const metric = metrics[index];
    if (!metric) return;
    const viewLeft = track.scrollLeft;
    const viewRight = viewLeft + track.clientWidth;
    const padding = 14;
    let nextLeft = viewLeft;
    if (metric.left - padding < viewLeft) nextLeft = metric.left - padding;
    else if (metric.left + metric.width + padding > viewRight) nextLeft = metric.left + metric.width + padding - track.clientWidth;
    if (Math.abs(nextLeft - viewLeft) < 1) return;
    const behavior = smooth && !prefersReducedMotion ? 'smooth' : 'auto';
    if (typeof track.scrollTo === 'function') track.scrollTo({ left: Math.max(0, nextLeft), behavior });
    else track.scrollLeft = Math.max(0, nextLeft);
  };

  const bubbleTo = (index, options = {}) => {
    const metric = metrics[index];
    if (!metric) return;
    bubble.target = metric.center;
    if (options.immediate || prefersReducedMotion) {
      bubble.x = metric.center;
      bubble.v = 0;
      bubble.width = bubble.baseWidth;
      render();
      ensureVisible(index, false);
      return;
    }
    startLoop();
    ensureVisible(index);
  };

  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

  /* ---------------------------------------------------------------------
   * Active state + routes
   * ------------------------------------------------------------------ */
  const updateActiveLink = (index) => {
    activeIndex = index;
    links.forEach((link, linkIndex) => {
      const selected = linkIndex === index;
      link.classList.toggle('is-active', selected);
      link.classList.remove('is-preview');
      if (selected) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
  };

  const setPreviewLink = (index) => {
    links.forEach((link, linkIndex) => link.classList.toggle('is-preview', linkIndex === index && index !== activeIndex));
  };

  const navRoute = new URL(links[0].href, window.location.href);
  const isAdminRoute = (url) => url.origin === window.location.origin && url.pathname === navRoute.pathname;
  const indexForURL = (url) => {
    const section = url.searchParams.get('section') || links[0].dataset.adminSection;
    return sectionIndexes.has(section) ? sectionIndexes.get(section) : -1;
  };
  const directionBetween = (from, to) => (to > from ? 'forward' : to < from ? 'backward' : 'none');

  /* ---------------------------------------------------------------------
   * Boot
   * ------------------------------------------------------------------ */
  measure();
  applyMetrics();
  bubble.x = metrics[activeIndex].center;
  bubble.target = bubble.x;
  bubble.width = bubble.baseWidth;
  render();
  ensureVisible(activeIndex, false);
  window.requestAnimationFrame(() => dock.classList.add('has-sliding-indicator'));

  /* ---------------------------------------------------------------------
   * Section loading, same routes as before, no full reload where safe.
   * ------------------------------------------------------------------ */
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
    }, prefersReducedMotion ? 0 : 400);
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
    if (!isAdminRoute(url) || index < 0 || index >= links.length) {
      window.location.assign(url.href);
      return;
    }

    const direction = directionBetween(activeIndex, index);
    dock.classList.remove('is-dragging');
    updateActiveLink(index);
    bubbleTo(index);

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

      // Counts change after a review, so refresh the badge without a reload.
      const incomingBadge = incomingDock.querySelector('.admin-dock-badge');
      const currentBadge = dock.querySelector('.admin-dock-badge');
      if (incomingBadge && currentBadge) {
        currentBadge.textContent = incomingBadge.textContent;
        currentBadge.setAttribute('aria-label', incomingBadge.getAttribute('aria-label') || '');
      } else if (incomingBadge && !currentBadge) {
        const badgeHost = dock.querySelector('[data-admin-section="approvals"]');
        if (badgeHost) badgeHost.appendChild(incomingBadge.cloneNode(true));
      }

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

  /* ---------------------------------------------------------------------
   * Dragging: the capsule stays under the finger while the rail pans.
   * ------------------------------------------------------------------ */
  const nearestIndex = (viewportX) => {
    let nearest = activeIndex;
    let nearestDistance = Infinity;
    const trackRect = track.getBoundingClientRect();
    metrics.forEach((metric, index) => {
      const center = metric.center - track.scrollLeft + trackRect.left;
      const distance = Math.abs(center - viewportX);
      if (distance < nearestDistance) {
        nearest = index;
        nearestDistance = distance;
      }
    });
    return nearest;
  };

  const pointerXToContent = (clientX) => clientX - track.getBoundingClientRect().left + track.scrollLeft;

  const cancelPointerDrag = () => {
    pointerDrag = null;
    bubble.dragging = false;
    dock.classList.remove('is-dragging');
    setPreviewLink(-1);
    bubbleTo(activeIndex);
  };

  track.addEventListener('pointerdown', (event) => {
    if (!event.isPrimary || event.button !== 0) return;
    const link = event.target.closest('.admin-dock-link');
    if (!link) return;
    pointerDrag = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      lastX: event.clientX,
      dragging: false,
      vertical: false,
      previewIndex: activeIndex,
    };
  });

  track.addEventListener('pointermove', (event) => {
    if (!pointerDrag || event.pointerId !== pointerDrag.pointerId || pointerDrag.vertical) return;
    const deltaX = event.clientX - pointerDrag.startX;
    const deltaY = event.clientY - pointerDrag.startY;
    if (!pointerDrag.dragging) {
      if (Math.abs(deltaY) > 10 && Math.abs(deltaY) > Math.abs(deltaX) * 1.08) {
        pointerDrag.vertical = true;
        return;
      }
      if (Math.abs(deltaX) < 6 || Math.abs(deltaX) <= Math.abs(deltaY) * 1.08) return;
      pointerDrag.dragging = true;
      bubble.dragging = true;
      bubble.target = bubble.x;
      dock.classList.add('is-dragging');
      try {
        track.setPointerCapture(event.pointerId);
      } catch (_) {
        // Pointer capture is progressive enhancement.
      }
    }
    event.preventDefault();

    const stepDelta = event.clientX - pointerDrag.lastX;
    if (Math.abs(stepDelta) > 0) {
      track.scrollLeft = clamp(track.scrollLeft - stepDelta, 0, Math.max(0, track.scrollWidth - track.clientWidth));
      pointerDrag.lastX = event.clientX;
    }

    const firstLeft = metrics[0].center;
    const lastLeft = metrics[metrics.length - 1].center;
    bubble.target = clamp(pointerXToContent(event.clientX), firstLeft, lastLeft);
    pointerDrag.previewIndex = nearestIndex(event.clientX);
    setPreviewLink(pointerDrag.previewIndex);
    startLoop();
  });

  const finishDrag = (event, cancelled) => {
    if (!pointerDrag || event.pointerId !== pointerDrag.pointerId) return;
    const drag = pointerDrag;
    pointerDrag = null;
    if (!drag.dragging) return;

    bubble.dragging = false;
    dock.classList.remove('is-dragging');
    setPreviewLink(-1);
    try {
      if (track.hasPointerCapture(event.pointerId)) track.releasePointerCapture(event.pointerId);
    } catch (_) {
      // Ignore an already-released pointer.
    }

    if (cancelled) {
      bubbleTo(activeIndex);
      return;
    }
    suppressClickUntil = window.performance.now() + 180;
    const destination = drag.previewIndex;
    navigateTo(destination, new URL(links[destination].href, window.location.href));
  };

  track.addEventListener('pointerup', (event) => finishDrag(event, false));
  track.addEventListener('pointercancel', (event) => finishDrag(event, true));
  track.addEventListener('lostpointercapture', (event) => {
    if (pointerDrag && pointerDrag.dragging && event.pointerId === pointerDrag.pointerId) cancelPointerDrag();
  });

  track.addEventListener('keydown', (event) => {
    const key = event.key;
    if (key !== 'ArrowLeft' && key !== 'ArrowRight' && key !== 'Home' && key !== 'End') return;
    const focusedIndex = links.indexOf(document.activeElement);
    const currentIndex = focusedIndex >= 0 ? focusedIndex : activeIndex;
    let nextIndex = currentIndex;
    if (key === 'ArrowLeft') nextIndex = Math.max(0, currentIndex - 1);
    if (key === 'ArrowRight') nextIndex = Math.min(links.length - 1, currentIndex + 1);
    if (key === 'Home') nextIndex = 0;
    if (key === 'End') nextIndex = links.length - 1;
    event.preventDefault();
    links[nextIndex].focus();
    ensureVisible(nextIndex);
  });

  /* ---------------------------------------------------------------------
   * Click + GET form interception (CSV exports stay native downloads).
   * ------------------------------------------------------------------ */
  document.addEventListener('click', (event) => {
    if (event.detail > 0 && window.performance.now() < suppressClickUntil) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
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

  /* ---------------------------------------------------------------------
   * Content swipes + rail-only wheel handling.
   * ------------------------------------------------------------------ */
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
    const follow = clamp(deltaX * 0.16, -22, 22);
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
      const nextIndex = clamp(activeIndex + (deltaX < 0 ? 1 : -1), 0, links.length - 1);
      if (nextIndex !== activeIndex) navigateTo(nextIndex, new URL(links[nextIndex].href, window.location.href));
    }
  });
  main.addEventListener('pointercancel', resetContentSwipe);

  /* ---------------------------------------------------------------------
   * Resize / motion preference
   * ------------------------------------------------------------------ */
  window.addEventListener('resize', () => {
    if (pointerDrag && pointerDrag.dragging) return;
    measure();
    applyMetrics();
    bubble.x = metrics[activeIndex].center;
    bubble.target = bubble.x;
    bubble.v = 0;
    bubble.width = bubble.baseWidth;
    render();
    ensureVisible(activeIndex, false);
  }, { passive: true });

  const updateMotionPreference = (event) => {
    prefersReducedMotion = event.matches;
    if (prefersReducedMotion) {
      if (bubble.frame) {
        window.cancelAnimationFrame(bubble.frame);
        bubble.frame = 0;
      }
      bubble.x = bubble.target;
      bubble.v = 0;
      bubble.width = bubble.baseWidth;
      render();
    }
  };
  if (typeof reducedMotionQuery.addEventListener === 'function') {
    reducedMotionQuery.addEventListener('change', updateMotionPreference);
  } else if (typeof reducedMotionQuery.addListener === 'function') {
    reducedMotionQuery.addListener(updateMotionPreference);
  }

  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    measure();
    applyMetrics();
    updateActiveLink(links.findIndex((link) => link.getAttribute('aria-current') === 'page'));
    bubbleTo(activeIndex, { immediate: true });
  });
})();
