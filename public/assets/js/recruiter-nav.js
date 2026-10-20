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
  let dragFrame = null;
  let queuedDragPoint = null;
  let suppressClickUntil = 0;
  let contentSwipe = null;
  let wheelDistance = 0;
  let wheelLocked = false;
  let wheelResetTimer = null;

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

  const offsetFor = (index) => {
    const link = links[index];
    return Math.round(link.offsetLeft + ((link.offsetWidth - indicator.offsetWidth) / 2));
  };

  const moveIndicatorTo = (index) => {
    indicator.style.width = '';
    indicator.style.borderRadius = '';
    indicator.style.transform = `translate3d(${offsetFor(index)}px, 0, 0)`;
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

  nav.classList.add('has-sliding-indicator', 'is-priming');
  const storedIndex = readStoredIndex();
  const startingIndex = storedIndex !== null && storedIndex !== activeIndex && !prefersReducedMotion
    ? storedIndex
    : activeIndex;
  moveIndicatorTo(startingIndex);
  indicator.getBoundingClientRect();
  if (startingIndex !== activeIndex) {
    window.requestAnimationFrame(() => {
      nav.classList.remove('is-priming');
      indicator.classList.add('is-moving');
      indicator.addEventListener('animationend', (event) => {
        if (event.target === indicator) indicator.classList.remove('is-moving');
      }, { once: true });
      moveIndicatorTo(activeIndex);
    });
  } else {
    window.requestAnimationFrame(() => nav.classList.remove('is-priming'));
  }
  rememberActiveIndex();

  const stopPendingRoute = () => {
    routeSequence += 1;
    if (activeController) activeController.abort();
    activeController = null;
    main.removeAttribute('aria-busy');
    content.classList.remove('is-pending', 'is-entering');
    content.dataset.direction = 'none';
  };

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
    }, prefersReducedMotion ? 0 : 420);
  };

  const navigateTo = async (index, destination, options = {}) => {
    const { pushHistory = true, force = false } = options;
    const url = destination instanceof URL ? destination : new URL(destination, window.location.href);
    if (!isRecruiterRoute(url) || index < 0 || index >= links.length) {
      window.location.assign(url.href);
      return;
    }

    const previousIndex = activeIndex;
    const direction = directionBetween(previousIndex, index);
    nav.classList.remove('is-dragging');
    indicator.classList.remove('is-moving');
    updateActiveLink(index);
    moveIndicatorTo(index);

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

  const clearDragStyles = () => {
    nav.classList.remove('is-dragging');
    indicator.style.width = '';
    indicator.style.borderRadius = '';
    setPreviewLink(-1);
  };

  const nearestIndex = (position, centers) => {
    let nearest = 0;
    let nearestDistance = Number.POSITIVE_INFINITY;
    centers.forEach((center, index) => {
      const distance = Math.abs(center - position);
      if (distance < nearestDistance) {
        nearest = index;
        nearestDistance = distance;
      }
    });
    return { index: nearest, distance: nearestDistance };
  };

  const applyDragPoint = (point) => {
    if (!pointerDrag || !pointerDrag.dragging) return;
    const drag = pointerDrag;
    const focusX = point.x - drag.grabOffset;
    const target = nearestIndex(focusX, drag.centers);
    const gaps = drag.centers.slice(1).map((center, index) => center - drag.centers[index]);
    const closestGap = gaps.length ? Math.min(...gaps) : drag.baseWidth;
    const progress = Math.min(1, target.distance / Math.max(1, closestGap / 2));
    const delta = point.x - drag.lastX;
    const direction = Math.sign(delta);
    if (direction && drag.lastDirection && direction !== drag.lastDirection) drag.reversalAt = point.time;
    if (direction) drag.lastDirection = direction;
    const elapsed = Math.max(1, point.time - drag.lastTime);
    const speed = Math.abs(delta) / elapsed;
    const reversalAge = point.time - drag.reversalAt;
    const compression = reversalAge >= 0 && reversalAge < 135
      ? .1 * (1 - (reversalAge / 135))
      : 0;
    const stretch = prefersReducedMotion
      ? 1
      : Math.max(.88, Math.min(1.48, 1 + (progress * .34) + Math.min(.12, speed * .035) - compression));
    const width = drag.baseWidth * stretch;
    const left = Math.max(drag.minimumLeft, Math.min(drag.maximumLeft, focusX - (width / 2)));

    indicator.style.width = `${width.toFixed(2)}px`;
    indicator.style.transform = `translate3d(${left.toFixed(2)}px, 0, 0)`;
    if (!prefersReducedMotion && progress > .04) {
      const lean = direction * 5;
      indicator.style.borderRadius = `${50 + lean}% ${39 - lean}% ${50 - lean}% ${39 + lean}% / 44% 56% 44% 56%`;
    } else {
      indicator.style.borderRadius = '';
    }
    drag.previewIndex = target.index;
    setPreviewLink(target.index);
    drag.lastX = point.x;
    drag.lastTime = point.time;
  };

  const queueDragPoint = (clientX, time) => {
    if (!pointerDrag || !pointerDrag.dragging) return;
    const navRect = pointerDrag.navRect;
    queuedDragPoint = {
      x: clientX - navRect.left - nav.clientLeft,
      time: Number.isFinite(time) ? time : performance.now(),
    };
    if (dragFrame !== null) return;
    dragFrame = window.requestAnimationFrame(() => {
      dragFrame = null;
      const point = queuedDragPoint;
      queuedDragPoint = null;
      if (point) applyDragPoint(point);
    });
  };

  const flushDragPoint = () => {
    if (dragFrame !== null) {
      window.cancelAnimationFrame(dragFrame);
      dragFrame = null;
    }
    const point = queuedDragPoint;
    queuedDragPoint = null;
    if (point) applyDragPoint(point);
  };

  const cancelPointerDrag = () => {
    if (!pointerDrag) return;
    pointerDrag = null;
    queuedDragPoint = null;
    if (dragFrame !== null) {
      window.cancelAnimationFrame(dragFrame);
      dragFrame = null;
    }
    clearDragStyles();
    moveIndicatorTo(activeIndex);
  };

  nav.addEventListener('pointerdown', (event) => {
    if (!event.isPrimary || (event.pointerType === 'mouse' && event.button !== 0)) return;
    if (event.pointerType === 'touch' && event.button !== 0) return;
    const link = event.target.closest('.rec-nav-link');
    if (!link) return;
    const startRect = nav.getBoundingClientRect();
    pointerDrag = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      startLocalX: event.clientX - startRect.left - nav.clientLeft,
      lastX: event.clientX - startRect.left - nav.clientLeft,
      lastTime: event.timeStamp,
      lastDirection: 0,
      reversalAt: -Infinity,
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

      const rect = nav.getBoundingClientRect();
      const baseWidth = indicator.getBoundingClientRect().width || indicator.offsetWidth;
      const centers = links.map((link) => link.offsetLeft + (link.offsetWidth / 2));
      pointerDrag.dragging = true;
      pointerDrag.baseWidth = baseWidth;
      pointerDrag.navRect = rect;
      pointerDrag.centers = centers;
      pointerDrag.minimumLeft = centers[0] - (baseWidth / 2);
      pointerDrag.maximumLeft = centers[centers.length - 1] - (baseWidth / 2);
      const grabX = event.clientX - rect.left - nav.clientLeft;
      const restingCenter = centers[activeIndex] ?? centers[0];
      const grabOffsetLimit = centers.length > 1 ? (centers[1] - centers[0]) / 2 : baseWidth;
      const rawGrabOffset = pointerDrag.startLocalX - restingCenter;
      pointerDrag.grabOffset = Math.max(-grabOffsetLimit, Math.min(grabOffsetLimit, rawGrabOffset));
      pointerDrag.lastX = grabX;
      pointerDrag.lastTime = event.timeStamp;
      nav.classList.add('is-dragging');
      indicator.classList.remove('is-moving');
      try {
        nav.setPointerCapture(event.pointerId);
      } catch (_) {
        // Pointer capture is progressive enhancement; the pointer still tracks on the nav.
      }
    }
    event.preventDefault();
    queueDragPoint(event.clientX, event.timeStamp);
  });

  nav.addEventListener('pointerup', (event) => {
    if (!pointerDrag || event.pointerId !== pointerDrag.pointerId) return;
    const drag = pointerDrag;
    if (!drag.dragging) {
      pointerDrag = null;
      return;
    }

    queueDragPoint(event.clientX, event.timeStamp);
    flushDragPoint();
    const destinationIndex = drag.previewIndex;
    const destinationURL = new URL(links[destinationIndex].href, window.location.href);
    pointerDrag = null;
    clearDragStyles();
    suppressClickUntil = performance.now() + 180;
    try {
      if (nav.hasPointerCapture(event.pointerId)) nav.releasePointerCapture(event.pointerId);
    } catch (_) {
      // Releasing an already-lost pointer capture is harmless.
    }
    navigateTo(destinationIndex, destinationURL);
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
    if (event.detail > 0 && performance.now() < suppressClickUntil) {
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
      const nextIndex = Math.max(0, Math.min(links.length - 1, activeIndex + (deltaX < 0 ? 1 : -1)));
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
    const nextIndex = Math.max(0, Math.min(links.length - 1, activeIndex + (wheelDistance > 0 ? 1 : -1)));
    wheelDistance = 0;
    wheelLocked = true;
    if (nextIndex !== activeIndex) navigateTo(nextIndex, new URL(links[nextIndex].href, window.location.href));
    window.setTimeout(() => { wheelLocked = false; }, 460);
  }, { passive: true });

  window.addEventListener('resize', () => {
    if (pointerDrag && pointerDrag.dragging) return;
    nav.classList.add('is-priming');
    moveIndicatorTo(activeIndex);
    indicator.getBoundingClientRect();
    window.requestAnimationFrame(() => nav.classList.remove('is-priming'));
  }, { passive: true });

  const updateMotionPreference = (event) => {
    prefersReducedMotion = event.matches;
  };
  if (typeof reducedMotionQuery.addEventListener === 'function') {
    reducedMotionQuery.addEventListener('change', updateMotionPreference);
  } else if (typeof reducedMotionQuery.addListener === 'function') {
    reducedMotionQuery.addListener(updateMotionPreference);
  }

  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      updateActiveLink(links.findIndex((link) => link.getAttribute('aria-current') === 'page'));
      moveIndicatorTo(activeIndex);
    }
  });
})();
