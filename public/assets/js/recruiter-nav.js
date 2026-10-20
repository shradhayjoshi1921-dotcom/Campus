(() => {
  'use strict';

  const nav = document.querySelector('.rec-bottom-nav');
  const main = document.querySelector('#main-content');
  const content = document.querySelector('#recruiter-section-content');
  if (!nav || !main || !content) return;

  const indicator = nav.querySelector('.rec-nav-indicator');
  const links = Array.from(nav.querySelectorAll('.rec-nav-link'));
  if (!indicator || links.length !== 5) return;

  const sectionIndexes = new Map(links.map((link, index) => [link.dataset.recSection, index]));
  const activeLinkIndex = links.findIndex((link) => link.getAttribute('aria-current') === 'page');
  if (activeLinkIndex < 0) return;

  const storageKey = 'sviet-recruiter-nav-index';
  const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  let prefersReducedMotion = reducedMotionQuery.matches;
  let activeIndex = activeLinkIndex;
  let routeSequence = 0;
  let activeController = null;
  let pointerDrag = null;
  let suppressClickUntil = 0;
  let contentSwipe = null;
  let wheelDistance = 0;
  let wheelLocked = false;
  let wheelResetTimer = null;
  let metrics = [];

  /* ---------------------------------------------------------------------
   * Geometry: the capsule is exactly one tab wide, centred on that tab.
   * ------------------------------------------------------------------ */
  const measure = () => {
    const navRect = nav.getBoundingClientRect();
    const navStyle = window.getComputedStyle(nav);
    const borderLeft = parseFloat(navStyle.borderLeftWidth) || 0;
    const borderTop = parseFloat(navStyle.borderTopWidth) || 0;
    metrics = links.map((link) => {
      const rect = link.getBoundingClientRect();
      return {
        center: rect.left - navRect.left - borderLeft + rect.width / 2,
        width: rect.width,
        top: rect.top - navRect.top - borderTop,
        height: rect.height,
      };
    });
  };

  /* ---------------------------------------------------------------------
   * Liquid spring. Position is integrated with a fixed 240 Hz step so the
   * motion is frame-rate independent and stable; width and interior slosh
   * are derived from velocity, so the capsule stretches into the direction
   * of travel and settles back to its pill proportions.
   * ------------------------------------------------------------------ */
  const bubble = {
    x: 0,
    v: 0,
    target: 0,
    width: 0,
    baseWidth: 0,
    minWidth: 40,
    frame: 0,
    last: 0,
    dragging: false,
    moving: false,
  };
  const MOVE_SPRING = { stiffness: 300, damping: 27 };
  const DRAG_SPRING = { stiffness: 2200, damping: 94 };
  const FIXED_STEP = 1 / 240;

  const render = () => {
    const stretch = bubble.width - bubble.baseWidth;
    const direction = bubble.v > 0.5 ? 1 : bubble.v < -0.5 ? -1 : 0;
    // Bias the extra width toward the leading edge so the trailing edge lags.
    const lead = direction * stretch * 0.4;
    const left = bubble.x - bubble.width / 2 + lead;
    const speedRatio = Math.min(1, Math.abs(bubble.v) / 1500);
    const squash = 1 - (speedRatio * 0.055);
    const slosh = Math.max(-1, Math.min(1, -bubble.v / 800)) * speedRatio;

    indicator.style.width = `${bubble.width.toFixed(2)}px`;
    indicator.style.transform = `translate3d(${left.toFixed(2)}px, 0, 0) scaleY(${squash.toFixed(4)})`;
    indicator.style.setProperty('--rec-slosh', slosh.toFixed(3));
    indicator.style.setProperty('--rec-stretch', Math.min(1, stretch / Math.max(1, bubble.baseWidth * .18)).toFixed(3));

    const moving = speedRatio > 0.02 || Math.abs(stretch) > .4;
    if (moving !== bubble.moving) {
      bubble.moving = moving;
      indicator.classList.toggle('is-stretched', moving);
    }
  };

  const applyMetrics = () => {
    const narrowest = metrics.reduce((min, metric) => Math.min(min, metric.width), Infinity);
    // Larger droplet: nearly the full tab width and height.
    const inset = Math.max(2, Math.min(5, narrowest * 0.045));
    bubble.baseWidth = Math.max(bubble.minWidth, narrowest - inset);
    bubble.maxWidth = Math.max(bubble.baseWidth + 10, narrowest + inset);
    // The drop is taller than the bar and rises above its top edge.
    const dropHeight = metrics[0].height + Math.max(30, Math.round(metrics[0].height * 0.6));
    indicator.style.height = `${dropHeight.toFixed(1)}px`;
    indicator.style.top = `${(metrics[0].top + metrics[0].height - dropHeight - 2).toFixed(1)}px`;
    if (!bubble.width) bubble.width = bubble.baseWidth;
    bubble.width = Math.min(bubble.width, bubble.baseWidth);
  };

  const desiredWidth = () => {
    const speed = Math.abs(bubble.v);
    const stretch = Math.min(bubble.maxWidth - bubble.baseWidth, speed * 0.0135);
    return bubble.baseWidth + Math.max(0, stretch);
  };

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
    const dt = Math.min(0.05, Math.max(0.008, elapsed > 0 ? elapsed / 1000 : 1 / 60));
    integrate(dt);

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

  const bubbleTo = (index, options = {}) => {
    const metric = metrics[index];
    if (!metric) return;
    bubble.target = metric.center;
    if (options.immediate || prefersReducedMotion) {
      bubble.x = metric.center;
      bubble.v = 0;
      bubble.width = bubble.baseWidth;
      render();
      return;
    }
    startLoop();
  };

  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

  /* ---------------------------------------------------------------------
   * Route + active-state bookkeeping
   * ------------------------------------------------------------------ */
  const readStoredIndex = () => {
    try {
      const storedValue = window.sessionStorage.getItem(storageKey);
      const storedIndex = Number(storedValue);
      return storedValue !== null && storedValue !== '' && Number.isInteger(storedIndex)
        && storedIndex >= 0 && storedIndex < links.length
        ? storedIndex
        : null;
    } catch (_) {
      return null;
    }
  };

  const rememberActiveIndex = () => {
    try {
      window.sessionStorage.setItem(storageKey, String(activeIndex));
    } catch (_) {
      // The navigation remains usable when storage is blocked.
    }
  };

  const updateActiveLink = (index) => {
    activeIndex = index;
    links.forEach((link, linkIndex) => {
      const selected = linkIndex === index;
      link.classList.toggle('is-active', selected);
      link.classList.remove('is-preview');
      if (selected) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
    rememberActiveIndex();
  };

  const setPreviewLink = (index) => {
    links.forEach((link, linkIndex) => link.classList.toggle('is-preview', linkIndex === index && index !== activeIndex));
  };

  const navRoute = new URL(links[0].href, window.location.href);
  const isRecruiterRoute = (url) => url.origin === window.location.origin && url.pathname === navRoute.pathname;
  const indexForURL = (url) => {
    const section = url.searchParams.get('section') || links[0].dataset.recSection;
    return sectionIndexes.has(section) ? sectionIndexes.get(section) : -1;
  };
  const directionBetween = (from, to) => (to > from ? 'forward' : to < from ? 'backward' : 'none');

  /* ---------------------------------------------------------------------
   * Boot: place the capsule silently, reveal it, then glide it in from the
   * tab the visitor came from on the previous page load.
   * ------------------------------------------------------------------ */
  measure();
  applyMetrics();
  const storedIndex = readStoredIndex();
  const startIndex = storedIndex !== null && storedIndex !== activeIndex && !prefersReducedMotion
    ? storedIndex
    : activeIndex;
  bubble.x = metrics[startIndex].center;
  bubble.target = bubble.x;
  bubble.width = bubble.baseWidth;
  render();
  rememberActiveIndex();

  window.requestAnimationFrame(() => {
    nav.classList.add('has-sliding-indicator');
    if (startIndex !== activeIndex) bubbleTo(activeIndex);
  });

  /* ---------------------------------------------------------------------
   * Section loading: same routes, no full reload where it is safe.
   * ------------------------------------------------------------------ */
  const announceSection = (parsedDocument) => {
    const status = document.querySelector('#recruiter-nav-status');
    const heading = parsedDocument.querySelector('.rec-page-heading h1');
    if (status && heading) status.textContent = `${heading.textContent.trim()} section loaded`;
  };

  const updateCompanyState = (parsedDocument) => {
    const current = document.querySelector('.rec-topbar .rec-company-state');
    const incoming = parsedDocument.querySelector('.rec-topbar .rec-company-state');
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
    if (!isRecruiterRoute(url) || index < 0 || index >= links.length) {
      window.location.assign(url.href);
      return;
    }

    const direction = directionBetween(activeIndex, index);
    nav.classList.remove('is-dragging');
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
        headers: {
          Accept: 'text/html',
          'X-Requested-With': 'XMLHttpRequest',
        },
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
      const incomingHeading = content.querySelector('.rec-page-heading h1');
      if (incomingHeading) {
        incomingHeading.setAttribute('tabindex', '-1');
        incomingHeading.focus({ preventScroll: true });
      }
      updateCompanyState(parsedDocument);
      announceSection(parsedDocument);
      animateContentIn(direction, requestId);
      if (pushHistory) window.history.pushState({ recruiterPortal: true }, '', url.href);
      main.removeAttribute('aria-busy');
      if (pushHistory) {
        window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
      }
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
   * Pointer dragging: the spring follows the finger, so the capsule trails
   * slightly behind it and stretches with the drag instead of snapping.
   * ------------------------------------------------------------------ */
  const nearestIndex = (position) => {
    let nearest = 0;
    let nearestDistance = Infinity;
    metrics.forEach((metric, index) => {
      const distance = Math.abs(metric.center - position);
      if (distance < nearestDistance) {
        nearest = index;
        nearestDistance = distance;
      }
    });
    return nearest;
  };

  const cancelPointerDrag = () => {
    pointerDrag = null;
    bubble.dragging = false;
    setPreviewLink(-1);
    nav.classList.remove('is-dragging');
    bubbleTo(activeIndex);
  };

  nav.addEventListener('pointerdown', (event) => {
    if (!event.isPrimary || event.button !== 0) return;
    const link = event.target.closest('.rec-nav-link');
    if (!link) return;
    measure();
    applyMetrics();
    const navRect = nav.getBoundingClientRect();
    const navStyle = window.getComputedStyle(nav);
    const borderLeft = parseFloat(navStyle.borderLeftWidth) || 0;
    pointerDrag = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      localX: event.clientX - navRect.left - borderLeft,
      dragging: false,
      vertical: false,
      previewIndex: activeIndex,
    };
  });

  nav.addEventListener('pointermove', (event) => {
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
      nav.classList.add('is-dragging');
      try {
        nav.setPointerCapture(event.pointerId);
      } catch (_) {
        // Pointer capture is progressive enhancement; the pointer still tracks on the nav.
      }
    }
    event.preventDefault();
    const navRect = nav.getBoundingClientRect();
    const navStyle = window.getComputedStyle(nav);
    const borderLeft = parseFloat(navStyle.borderLeftWidth) || 0;
    const localX = event.clientX - navRect.left - borderLeft;
    bubble.target = clamp(localX, metrics[0].center, metrics[metrics.length - 1].center);
    pointerDrag.previewIndex = nearestIndex(localX);
    setPreviewLink(pointerDrag.previewIndex);
    startLoop();
  });

  nav.addEventListener('pointerup', (event) => {
    if (!pointerDrag || event.pointerId !== pointerDrag.pointerId) return;
    const drag = pointerDrag;
    pointerDrag = null;
    if (!drag.dragging) return;

    bubble.dragging = false;
    nav.classList.remove('is-dragging');
    setPreviewLink(-1);
    suppressClickUntil = window.performance.now() + 180;
    try {
      if (nav.hasPointerCapture(event.pointerId)) nav.releasePointerCapture(event.pointerId);
    } catch (_) {
      // Releasing an already-lost pointer capture is harmless.
    }
    const destinationIndex = drag.previewIndex;
    navigateTo(destinationIndex, new URL(links[destinationIndex].href, window.location.href));
  });

  nav.addEventListener('pointercancel', (event) => {
    if (pointerDrag && event.pointerId === pointerDrag.pointerId) cancelPointerDrag();
  });
  nav.addEventListener('lostpointercapture', (event) => {
    if (pointerDrag && pointerDrag.dragging && event.pointerId === pointerDrag.pointerId) cancelPointerDrag();
  });

  nav.addEventListener('keydown', (event) => {
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
  });

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

  /* ---------------------------------------------------------------------
   * Content swipes (touch) and horizontal trackpad swipes.
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
    const follow = Math.max(-22, Math.min(22, deltaX * .16));
    content.style.transform = `translate3d(${follow.toFixed(2)}px, 0, 0)`;
    content.style.opacity = String(Math.max(.82, 1 - (Math.abs(deltaX) / 900)));
  });
  main.addEventListener('pointerup', (event) => {
    if (!contentSwipe || event.pointerId !== contentSwipe.pointerId) return;
    const deltaX = event.clientX - contentSwipe.x;
    const deltaY = event.clientY - contentSwipe.y;
    const wasActive = contentSwipe.active;
    resetContentSwipe();
    const threshold = Math.max(54, main.clientWidth * .12);
    if (wasActive && Math.abs(deltaX) >= threshold && Math.abs(deltaX) > Math.abs(deltaY) * 1.2) {
      const nextIndex = clamp(activeIndex + (deltaX < 0 ? 1 : -1), 0, links.length - 1);
      if (nextIndex !== activeIndex) navigateTo(nextIndex, new URL(links[nextIndex].href, window.location.href));
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
    const nextIndex = clamp(activeIndex + (wheelDistance > 0 ? 1 : -1), 0, links.length - 1);
    wheelDistance = 0;
    wheelLocked = true;
    if (nextIndex !== activeIndex) navigateTo(nextIndex, new URL(links[nextIndex].href, window.location.href));
    window.setTimeout(() => { wheelLocked = false; }, 460);
  }, { passive: true });

  /* ---------------------------------------------------------------------
   * Resize / motion-preference changes.
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
