/*
 * Student dock: a single water capsule that travels between five workspaces.
 * Drag the rail to pan it, tap a destination to open it, or use the arrow
 * keys. On arrival the capsule flows in from the previous workspace so the
 * movement reads as one continuous element rather than five buttons.
 */
(() => {
  'use strict';

  const dock = document.querySelector('.sv-dock');
  const track = document.querySelector('#sv-dock-track');
  if (!dock || !track) return;

  const indicator = track.querySelector('.sv-dock-indicator');
  const links = Array.from(track.querySelectorAll('.sv-dock-link'));
  if (!indicator || links.length < 2) return;

  const activeIndex = links.findIndex((link) => link.getAttribute('aria-current') === 'page');
  if (activeIndex < 0) return;

  const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  let prefersReducedMotion = reducedMotionQuery.matches;
  let index = activeIndex;
  let pointerDrag = null;
  let suppressClickUntil = 0;
  let metrics = [];

  const STORE_KEY = 'sviet-dock-index';

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
  const MOVE_SPRING = { stiffness: 290, damping: 26 };
  const DRAG_SPRING = { stiffness: 2100, damping: 92 };
  const FIXED_STEP = 1 / 240;

  const render = () => {
    const stretch = bubble.width - bubble.baseWidth;
    const direction = bubble.v > 0.5 ? 1 : bubble.v < -0.5 ? -1 : 0;
    const lead = direction * stretch * 0.42;
    const left = bubble.x - bubble.width / 2 + lead;
    const speedRatio = Math.min(1, Math.abs(bubble.v) / 1500);
    const squash = 1 - speedRatio * 0.06;
    const slosh = Math.max(-1, Math.min(1, -bubble.v / 800)) * speedRatio;

    indicator.style.width = `${bubble.width.toFixed(2)}px`;
    indicator.style.transform = `translate3d(${left.toFixed(2)}px, 0, 0) scaleY(${squash.toFixed(4)})`;
    indicator.style.setProperty('--sv-slosh', slosh.toFixed(3));
    indicator.style.setProperty('--sv-stretch', Math.min(1, stretch / Math.max(1, bubble.baseWidth * 0.18)).toFixed(3));
  };

  const applyMetrics = () => {
    if (metrics.length === 0) return;
    const narrowest = metrics.reduce((min, metric) => Math.min(min, metric.width), Infinity);
    const inset = Math.max(2, Math.min(5, narrowest * 0.045));
    const insetY = Math.max(2, Math.min(4, metrics[0].height * 0.05));
    bubble.baseWidth = Math.max(46, narrowest - inset);
    bubble.maxWidth = narrowest + inset * 0.6;
    indicator.style.height = `${Math.max(38, metrics[0].height - insetY * 2).toFixed(1)}px`;
    indicator.style.top = `${(metrics[0].top + insetY).toFixed(1)}px`;
    if (!bubble.width) bubble.width = bubble.baseWidth;
    bubble.width = Math.min(bubble.width, bubble.baseWidth);
  };

  const desiredWidth = () => bubble.baseWidth
    + Math.max(0, Math.min(bubble.maxWidth - bubble.baseWidth, Math.abs(bubble.v) * 0.014));

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

  const settled = () => !bubble.dragging
    && Math.abs(bubble.v) < 1.5
    && Math.abs(bubble.target - bubble.x) < 0.2
    && Math.abs(bubble.width - bubble.baseWidth) < 0.3;

  const step = (now) => {
    bubble.frame = 0;
    const elapsed = now - bubble.last;
    bubble.last = now;
    integrate(Math.min(0.05, Math.max(0.008, elapsed > 0 ? elapsed / 1000 : 1 / 60)));
    if (settled()) {
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

  const ensureVisible = (targetIndex, smooth = true) => {
    const metric = metrics[targetIndex];
    if (!metric) return;
    const viewLeft = track.scrollLeft;
    const viewRight = viewLeft + track.clientWidth;
    const padding = 14;
    let nextLeft = viewLeft;
    if (metric.left - padding < viewLeft) nextLeft = metric.left - padding;
    else if (metric.left + metric.width + padding > viewRight) {
      nextLeft = metric.left + metric.width + padding - track.clientWidth;
    }
    if (Math.abs(nextLeft - viewLeft) < 1) return;
    const behavior = smooth && !prefersReducedMotion ? 'smooth' : 'auto';
    if (typeof track.scrollTo === 'function') track.scrollTo({ left: Math.max(0, nextLeft), behavior });
    else track.scrollLeft = Math.max(0, nextLeft);
  };

  const bubbleTo = (targetIndex, options = {}) => {
    const metric = metrics[targetIndex];
    if (!metric) return;
    bubble.target = metric.center;
    if (options.immediate || prefersReducedMotion) {
      bubble.x = metric.center;
      bubble.v = 0;
      bubble.width = bubble.baseWidth;
      render();
      ensureVisible(targetIndex, false);
      return;
    }
    startLoop();
    ensureVisible(targetIndex);
  };

  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
  const setPreview = (previewIndex) => {
    links.forEach((link, linkIndex) => {
      link.classList.toggle('is-preview', linkIndex === previewIndex && previewIndex !== activeIndex);
    });
  };

  /* ------------------------------------------------------------------ boot */
  measure();
  applyMetrics();
  const stored = Number(window.sessionStorage ? window.sessionStorage.getItem(STORE_KEY) : NaN);
  const cameFrom = Number.isInteger(stored) && stored >= 0 && stored < links.length ? stored : activeIndex;
  if (cameFrom !== activeIndex && !prefersReducedMotion) {
    const origin = metrics[cameFrom];
    bubble.x = origin ? origin.center : metrics[activeIndex].center;
    bubble.v = (activeIndex > cameFrom ? -220 : 220);
  } else {
    bubble.x = metrics[activeIndex].center;
  }
  bubble.target = metrics[activeIndex].center;
  bubble.width = bubble.baseWidth;
  render();
  ensureVisible(activeIndex, false);
  window.requestAnimationFrame(() => {
    dock.classList.add('has-sliding-indicator');
    if (cameFrom !== activeIndex && !prefersReducedMotion) startLoop();
  });
  try {
    if (window.sessionStorage) window.sessionStorage.setItem(STORE_KEY, String(activeIndex));
  } catch (_) {
    /* Storage is optional. */
  }

  /* --------------------------------------------------------------- dragging */
  const nearestIndex = (viewportX) => {
    let nearest = activeIndex;
    let nearestDistance = Infinity;
    const trackRect = track.getBoundingClientRect();
    metrics.forEach((metric, metricIndex) => {
      const center = metric.center - track.scrollLeft + trackRect.left;
      const distance = Math.abs(center - viewportX);
      if (distance < nearestDistance) {
        nearest = metricIndex;
        nearestDistance = distance;
      }
    });
    return nearest;
  };
  const pointerXToContent = (clientX) => clientX - track.getBoundingClientRect().left + track.scrollLeft;

  track.addEventListener('pointerdown', (event) => {
    if (!event.isPrimary || event.button !== 0) return;
    const link = event.target.closest('.sv-dock-link');
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
        /* Progressive enhancement. */
      }
    }
    event.preventDefault();

    const stepDelta = event.clientX - pointerDrag.lastX;
    if (Math.abs(stepDelta) > 0) {
      track.scrollLeft = clamp(track.scrollLeft - stepDelta, 0, Math.max(0, track.scrollWidth - track.clientWidth));
      pointerDrag.lastX = event.clientX;
    }
    bubble.target = clamp(
      pointerXToContent(event.clientX),
      metrics[0].center,
      metrics[metrics.length - 1].center,
    );
    pointerDrag.previewIndex = nearestIndex(event.clientX);
    setPreview(pointerDrag.previewIndex);
    startLoop();
  });

  const finishDrag = (event, cancelled) => {
    if (!pointerDrag || event.pointerId !== pointerDrag.pointerId) return;
    const drag = pointerDrag;
    pointerDrag = null;
    if (!drag.dragging) return;

    bubble.dragging = false;
    dock.classList.remove('is-dragging');
    setPreview(-1);
    try {
      if (track.hasPointerCapture(event.pointerId)) track.releasePointerCapture(event.pointerId);
    } catch (_) {
      /* Already released. */
    }
    if (cancelled) {
      bubbleTo(activeIndex);
      return;
    }
    suppressClickUntil = window.performance.now() + 180;
    const destination = links[drag.previewIndex];
    if (destination && drag.previewIndex !== activeIndex) {
      window.location.assign(destination.href);
    } else {
      bubbleTo(activeIndex);
    }
  };

  track.addEventListener('pointerup', (event) => finishDrag(event, false));
  track.addEventListener('pointercancel', (event) => finishDrag(event, true));
  track.addEventListener('lostpointercapture', (event) => {
    if (pointerDrag && pointerDrag.dragging && event.pointerId === pointerDrag.pointerId) {
      pointerDrag = null;
      bubble.dragging = false;
      dock.classList.remove('is-dragging');
      setPreview(-1);
      bubbleTo(activeIndex);
    }
  });

  /* ------------------------------------------------------------- keyboard */
  track.addEventListener('keydown', (event) => {
    const key = event.key;
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(key)) return;
    const focused = links.indexOf(document.activeElement);
    const current = focused >= 0 ? focused : index;
    let next = current;
    if (key === 'ArrowLeft') next = Math.max(0, current - 1);
    if (key === 'ArrowRight') next = Math.min(links.length - 1, current + 1);
    if (key === 'Home') next = 0;
    if (key === 'End') next = links.length - 1;
    event.preventDefault();
    links[next].focus();
    ensureVisible(next);
  });

  /* ---------------------------------------------------------------- clicks */
  document.addEventListener('click', (event) => {
    if (event.detail > 0 && window.performance.now() < suppressClickUntil) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    const link = event.target.closest('.sv-dock-link');
    if (!link) return;
    const targetIndex = links.indexOf(link);
    if (targetIndex < 0 || targetIndex === index) {
      event.preventDefault();
      bubbleTo(index, { immediate: prefersReducedMotion });
      return;
    }
    index = targetIndex;
    try {
      if (window.sessionStorage) window.sessionStorage.setItem(STORE_KEY, String(targetIndex));
    } catch (_) {
      /* Storage is optional. */
    }
    links.forEach((item, itemIndex) => {
      item.classList.toggle('is-active', itemIndex === targetIndex);
      if (itemIndex === targetIndex) item.setAttribute('aria-current', 'page');
      else item.removeAttribute('aria-current');
    });
    bubbleTo(targetIndex);
    // The href drives navigation; the capsule starts moving immediately.
  });

  /* ----------------------------------------------------------- preferences */
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
    bubbleTo(activeIndex, { immediate: true });
  });
})();
