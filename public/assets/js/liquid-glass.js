/* ==========================================================================
   Campus — Liquid Glass engine
   One spring-driven glass capsule reused by every portal dock and by the
   segmented option groups. The engine owns only geometry, material state and
   the gesture; routing, fetching and PHP stay exactly where they were.

   Public API
     LiquidGlass.mount({bar, track, links, indicator, restoreKey, layout})
     LiquidGlass.mountGroup(element)      // [data-glass-group]
     LiquidGlass.refresh(scope)           // re-measure after a DOM swap
     LiquidGlass.controller(element)      // the mounted controller, if any

   Events (bubbling from the bar)
     liquidglass:select   {index, source}   informational
     liquidglass:activate {index, source}   cancelable — drag/keyboard select;
                                            preventDefault() to take over routing
   ========================================================================== */
(() => {
  'use strict';

  const STEP = 1 / 240;
  const MOVE_SPRING = { stiffness: 300, damping: 27 };
  const DRAG_SPRING = { stiffness: 2200, damping: 94 };
  const EASE = 'cubic-bezier(.22, 1, .36, 1)';
  const controllers = new WeakMap();

  const motionQuery = window.matchMedia
    ? window.matchMedia('(prefers-reduced-motion: reduce)')
    : null;
  let reduced = motionQuery ? motionQuery.matches : false;

  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

  /* ------------------------------------------------------------------ defs */
  // A single, small displacement filter gives the interior its optical wobble.
  // It is only attached to the inner layer, and the engine removes it while the
  // capsule moves so no filter work happens on spring frames.
  const injectDefs = () => {
    if (document.getElementById('lg-refract')) return;
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.setAttribute('width', '0');
    svg.setAttribute('height', '0');
    svg.style.position = 'absolute';
    svg.style.pointerEvents = 'none';
    svg.innerHTML = `
      <filter id="lg-refract" x="-15%" y="-25%" width="130%" height="150%" color-interpolation-filters="sRGB">
        <feTurbulence type="fractalNoise" baseFrequency="0.014 0.05" numOctaves="2" seed="9" result="noise"/>
        <feDisplacementMap in="SourceGraphic" in2="noise" scale="7" xChannelSelector="R" yChannelSelector="G"/>
      </filter>`;
    document.body.appendChild(svg);
  };

  /* --------------------------------------------------------------- capsule */
  const createCapsule = (config) => {
    const {
      bar,
      track,
      links,
      indicator,
      layout = 'dock',
      restoreKey = '',
      onSelect,
    } = config;

    if (!bar || !indicator || !links || links.length === 0) return null;

    const scrollTrack = track || bar;
    const isGroup = layout === 'group';
    let metrics = [];
    let activeIndex = Math.max(0, links.findIndex((link) => link.getAttribute('aria-current') === 'page'));
    let frame = 0;
    let last = 0;
    let pointer = null;
    let suppressClickUntil = 0;
    let revealed = false;

    const state = {
      x: 0,
      v: 0,
      target: 0,
      width: 0,
      baseWidth: 0,
      maxStretch: 0,
      height: 0,
      top: 0,
      dragging: false,
    };

    const measure = () => {
      const barRect = bar.getBoundingClientRect();
      if (!barRect.width) return;
      const scrollLeft = scrollTrack.scrollLeft || 0;
      metrics = links.map((link) => {
        const rect = link.getBoundingClientRect();
        return {
          center: rect.left - barRect.left - bar.clientLeft + rect.width / 2 + scrollLeft,
          width: rect.width,
          height: rect.height,
          top: rect.top - barRect.top - bar.clientTop,
        };
      });
      const insetX = isGroup ? 3 : Math.max(4, Math.min(8, metrics[0].width * 0.06));
      const insetY = isGroup ? 3 : Math.max(3, Math.min(6, metrics[0].height * 0.07));
      state.baseWidth = Math.max(isGroup ? 40 : 46, metrics[0].width - insetX);
      // The capsule owns one tab, never more: the stretch budget is the inset it
      // was trimmed by, so a stretched capsule still fits inside a single tab.
      state.maxStretch = isGroup
        ? Math.max(6, Math.min(14, metrics[0].width * 0.18))
        : Math.max(6, Math.min(18, insetX));
      state.height = Math.max(isGroup ? 26 : 34, metrics[0].height - insetY);
      state.top = (metrics[0].top || 0) + insetY / 2;
      indicator.style.height = `${state.height.toFixed(1)}px`;
      indicator.style.top = `${Math.max(0, state.top).toFixed(1)}px`;
      if (!state.width) state.width = state.baseWidth;
      state.width = Math.min(state.width, state.baseWidth);
    };

    const render = () => {
      const stretch = state.width - state.baseWidth;
      const direction = state.v > 0.5 ? 1 : state.v < -0.5 ? -1 : 0;
      // Bias the extra width forward so the leading edge leads the trailing edge.
      const lead = direction * stretch * 0.42;
      // `state.x` is measured in the bar's padding-box content space, which
      // already includes the track offset; only the scroll has to be undone.
      const left = state.x - (scrollTrack.scrollLeft || 0) - state.width / 2 + lead;
      const speed = Math.min(1, Math.abs(state.v) / 1600);
      const squash = 1 - speed * 0.05;
      const slosh = clamp(-state.v / 900, -1, 1) * speed;

      indicator.style.width = `${state.width.toFixed(2)}px`;
      indicator.style.transform = `translate3d(${left.toFixed(2)}px, 0, 0) scaleY(${squash.toFixed(4)})`;
      indicator.style.setProperty('--lg-slosh', slosh.toFixed(3));
      indicator.style.setProperty('--lg-stretch', Math.min(1, stretch / Math.max(1, state.baseWidth * 0.2)).toFixed(3));

      const moving = speed > 0.02 || Math.abs(stretch) > 0.4 || state.dragging;
      indicator.classList.toggle('is-moving', moving);
      indicator.classList.toggle('is-dragging', state.dragging);
    };

    const desiredWidth = () => state.baseWidth + Math.min(state.maxStretch, Math.abs(state.v) * 0.013);

    const integrate = (dt) => {
      const spring = state.dragging ? DRAG_SPRING : MOVE_SPRING;
      let remaining = dt;
      while (remaining > 0) {
        const stepSize = Math.min(STEP, remaining);
        state.v += (spring.stiffness * (state.target - state.x) - spring.damping * state.v) * stepSize;
        state.x += state.v * stepSize;
        remaining -= stepSize;
      }
      state.width += (desiredWidth() - state.width) * Math.min(1, dt * 16);
    };

    const settled = () => !state.dragging
      && Math.abs(state.v) < 1.5
      && Math.abs(state.target - state.x) < 0.2
      && Math.abs(state.width - state.baseWidth) < 0.3;

    const step = (now) => {
      frame = 0;
      const elapsed = now - last;
      last = now;
      integrate(clamp(elapsed > 0 ? elapsed / 1000 : 1 / 60, 0.008, 0.05));
      if (settled()) {
        state.x = state.target;
        state.v = 0;
        state.width = state.baseWidth;
        render();
        return;
      }
      render();
      frame = window.requestAnimationFrame(step);
    };

    const start = () => {
      if (reduced) {
        state.x = state.target;
        state.v = 0;
        state.width = state.baseWidth;
        render();
        return;
      }
      if (frame) return;
      last = window.performance.now();
      frame = window.requestAnimationFrame(step);
    };

    const ensureVisible = (index, smooth = true) => {
      if (scrollTrack.scrollWidth <= scrollTrack.clientWidth + 1) return;
      const metric = metrics[index];
      if (!metric) return;
      const viewLeft = scrollTrack.scrollLeft;
      const viewRight = viewLeft + scrollTrack.clientWidth;
      const padding = 16;
      let next = null;
      if (metric.center - metric.width / 2 - padding < viewLeft) next = metric.center - metric.width / 2 - padding;
      else if (metric.center + metric.width / 2 + padding > viewRight) next = metric.center + metric.width / 2 + padding - scrollTrack.clientWidth;
      if (next === null) return;
      const behavior = smooth && !reduced ? 'smooth' : 'auto';
      if (typeof scrollTrack.scrollTo === 'function') scrollTrack.scrollTo({ left: Math.max(0, next), behavior });
      else scrollTrack.scrollLeft = Math.max(0, next);
    };

    const moveTo = (index, options = {}) => {
      const metric = metrics[index];
      if (!metric) return;
      state.target = metric.center;
      if (options.immediate || reduced) {
        state.x = metric.center;
        state.v = 0;
        state.width = state.baseWidth;
        render();
        if (!isGroup) ensureVisible(index, false);
        return;
      }
      start();
      if (!isGroup) ensureVisible(index);
    };

    const setPreview = (index) => {
      links.forEach((link, linkIndex) => {
        link.classList.toggle('is-preview', linkIndex === index && index !== activeIndex);
      });
    };

    const persist = (index) => {
      if (!restoreKey) return;
      try { window.sessionStorage.setItem(restoreKey, String(index)); } catch (_) { /* storage is optional */ }
    };

    const setActive = (index, options = {}) => {
      if (index < 0 || index >= links.length) return;
      activeIndex = index;
      links.forEach((link, linkIndex) => {
        const selected = linkIndex === index;
        link.classList.toggle('is-active', selected);
        link.classList.remove('is-preview');
        if (selected) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
      });
      persist(index);
      moveTo(index, options);
    };

    const emit = (name, index, source, cancelable = false) => bar.dispatchEvent(new CustomEvent(name, {
      bubbles: true,
      cancelable,
      detail: { index, source },
    }));

    const activate = (index, source) => {
      setActive(index);
      if (onSelect) onSelect(index, source);
      // A listener that preventDefaults takes over routing (the portals swap
      // the section in place). Otherwise fall back to a normal navigation.
      const handled = !emit('liquidglass:activate', index, source, true);
      if (handled) return;
      const link = links[index];
      if (link && link.href) window.location.assign(link.href);
    };

    /* -------------------------------------------------------------- pointer */
    const nearest = (position) => {
      let best = activeIndex;
      let distance = Infinity;
      metrics.forEach((metric, index) => {
        const d = Math.abs(metric.center - position);
        if (d < distance) { best = index; distance = d; }
      });
      return best;
    };

    const localX = (clientX) => {
      const barRect = bar.getBoundingClientRect();
      return clientX - barRect.left - bar.clientLeft + (scrollTrack.scrollLeft || 0);
    };

    bar.addEventListener('pointerdown', (event) => {
      if (isGroup || !event.isPrimary || event.button !== 0) return;
      if (!event.target.closest('.sv-dock-link, .admin-dock-link, .rec-nav-link')) return;
      pointer = {
        id: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        lastX: event.clientX,
        dragging: false,
        vertical: false,
        preview: activeIndex,
      };
    });

    bar.addEventListener('pointermove', (event) => {
      if (!pointer || event.pointerId !== pointer.id || pointer.vertical) return;
      const dx = event.clientX - pointer.startX;
      const dy = event.clientY - pointer.startY;
      if (!pointer.dragging) {
        if (Math.abs(dy) > 12 && Math.abs(dy) > Math.abs(dx) * 1.05) {
          pointer.vertical = true;
          return;
        }
        if (Math.abs(dx) < 6 || Math.abs(dx) <= Math.abs(dy) * 1.05) return;
        pointer.dragging = true;
        state.dragging = true;
        state.target = state.x;
        bar.classList.add('is-dragging');
        try { bar.setPointerCapture(event.pointerId); } catch (_) { /* optional */ }
      }
      event.preventDefault();
      if (scrollTrack.scrollWidth > scrollTrack.clientWidth + 1) {
        const stepX = event.clientX - pointer.lastX;
        if (stepX) scrollTrack.scrollLeft = clamp(
          scrollTrack.scrollLeft - stepX,
          0,
          scrollTrack.scrollWidth - scrollTrack.clientWidth,
        );
        pointer.lastX = event.clientX;
      }
      const position = localX(event.clientX);
      state.target = clamp(position, metrics[0].center, metrics[metrics.length - 1].center);
      pointer.preview = nearest(position);
      setPreview(pointer.preview);
      start();
    });

    const finish = (event, cancelled) => {
      if (!pointer || event.pointerId !== pointer.id) return;
      const drag = pointer;
      pointer = null;
      if (!drag.dragging) return;
      state.dragging = false;
      bar.classList.remove('is-dragging');
      setPreview(-1);
      try {
        if (bar.hasPointerCapture(event.pointerId)) bar.releasePointerCapture(event.pointerId);
      } catch (_) { /* already released */ }
      if (cancelled) {
        moveTo(activeIndex);
        return;
      }
      suppressClickUntil = window.performance.now() + 220;
      if (drag.preview !== activeIndex) activate(drag.preview, 'drag');
      else moveTo(activeIndex);
    };

    bar.addEventListener('pointerup', (event) => finish(event, false));
    bar.addEventListener('pointercancel', (event) => finish(event, true));
    bar.addEventListener('lostpointercapture', (event) => {
      if (pointer && pointer.dragging && event.pointerId === pointer.id) {
        pointer = null;
        state.dragging = false;
        bar.classList.remove('is-dragging');
        setPreview(-1);
        moveTo(activeIndex);
      }
    });

    /* ---------------------------------------------------------------- click */
    bar.addEventListener('click', (event) => {
      const link = event.target.closest('.sv-dock-link, .admin-dock-link, .rec-nav-link, .lg-option');
      if (!link || !bar.contains(link)) return;
      if (event.detail > 0 && window.performance.now() < suppressClickUntil) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }
      const index = links.indexOf(link);
      if (index < 0) return;
      // Visual selection happens immediately; navigation stays with the page.
      setActive(index);
      emit('liquidglass:select', index, 'click');
    });

    /* ------------------------------------------------------------- keyboard */
    bar.addEventListener('keydown', (event) => {
      const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
      if (!keys.includes(event.key)) return;
      const focused = links.indexOf(document.activeElement);
      const current = focused >= 0 ? focused : activeIndex;
      let next = current;
      if (event.key === 'ArrowLeft') next = Math.max(0, current - 1);
      if (event.key === 'ArrowRight') next = Math.min(links.length - 1, current + 1);
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = links.length - 1;
      event.preventDefault();
      links[next].focus({ preventScroll: true });
      // Preview the focused destination without committing the route.
      const metric = metrics[next];
      if (metric) {
        state.target = metric.center;
        start();
        ensureVisible(next);
      }
      setPreview(next);
    });

    /* ----------------------------------------------------------------- boot */
    measure();
    measure(); // second pass settles layout after fonts/safe-area resolve
    const stored = (() => {
      if (!restoreKey) return null;
      try {
        const raw = window.sessionStorage.getItem(restoreKey);
        const value = Number(raw);
        return raw !== null && Number.isInteger(value) && value >= 0 && value < links.length ? value : null;
      } catch (_) { return null; }
    })();
    const originIndex = stored !== null && stored !== activeIndex && !reduced ? stored : activeIndex;
    const originMetric = metrics[originIndex] || metrics[activeIndex];
    // A dock can mount before it has a box (hidden container, deferred CSS).
    // Stay dormant and let the ResizeObserver measure it as soon as it does.
    if (originMetric) {
      state.x = originMetric.center;
      state.target = state.x;
    }
    state.width = state.baseWidth;
    render();
    // Bring the rendered destination into view: the dock rail and a long
    // option group (e.g. graduation years) can both start scrolled away.
    ensureVisible(activeIndex, false);

    const reveal = () => {
      if (revealed) return;
      revealed = true;
      bar.classList.add('has-liquid-indicator');
      persist(activeIndex);
      if (originIndex !== activeIndex) moveTo(activeIndex);
    };
    window.requestAnimationFrame(reveal);

    const onScroll = () => { if (!frame) render(); };
    scrollTrack.addEventListener('scroll', onScroll, { passive: true });

    let resizeObserver = null;
    if (typeof ResizeObserver === 'function') {
      resizeObserver = new ResizeObserver(() => {
        if (pointer && pointer.dragging) return;
        measure();
        setActive(activeIndex, { immediate: true });
      });
      resizeObserver.observe(bar);
      links.forEach((link) => resizeObserver.observe(link));
    }

    const onResize = () => {
      if (pointer && pointer.dragging) return;
      measure();
      setActive(activeIndex, { immediate: true });
    };
    window.addEventListener('resize', onResize, { passive: true });

    const onMotionChange = (event) => {
      reduced = event.matches;
      if (reduced) {
        if (frame) window.cancelAnimationFrame(frame);
        frame = 0;
        state.x = state.target;
        state.v = 0;
        state.width = state.baseWidth;
        render();
      }
    };
    if (motionQuery) {
      if (typeof motionQuery.addEventListener === 'function') motionQuery.addEventListener('change', onMotionChange);
      else if (typeof motionQuery.addListener === 'function') motionQuery.addListener(onMotionChange);
    }

    window.addEventListener('pageshow', (event) => {
      if (!event.persisted) return;
      measure();
      setActive(activeIndex, { immediate: true });
    });

    const controller = {
      setActive,
      activeIndex: () => activeIndex,
      refresh: () => { measure(); setActive(activeIndex, { immediate: true }); },
      measure,
      isGroup,
      bar,
    };
    controllers.set(bar, controller);
    return controller;
  };

  /* ------------------------------------------------------------ public API */
  const mount = (options) => {
    if (!options || !options.bar) return null;
    const existing = controllers.get(options.bar);
    if (existing) return existing;
    injectDefs();
    const links = options.links || Array.from(options.bar.querySelectorAll('.sv-dock-link, .admin-dock-link, .rec-nav-link, .lg-option'));
    const indicator = options.indicator || options.bar.querySelector('.lg-indicator');
    return createCapsule({
      bar: options.bar,
      track: options.track || options.bar.querySelector('.sv-dock-track, .admin-dock-track') || options.bar,
      links,
      indicator,
      layout: options.layout || 'dock',
      restoreKey: options.restoreKey || '',
      onSelect: options.onSelect,
    });
  };

  const mountGroup = (group) => {
    if (!group || controllers.has(group)) return controllers.get(group) || null;
    const options = Array.from(group.querySelectorAll('.lg-option'));
    if (options.length === 0) return null;
    injectDefs();
    return createCapsule({
      bar: group,
      track: group,
      links: options,
      indicator: group.querySelector('.lg-indicator'),
      layout: 'group',
    });
  };

  const refresh = (scope = document) => {
    scope.querySelectorAll('[data-glass-group]').forEach((group) => {
      const controller = controllers.get(group);
      if (controller) controller.refresh();
      else mountGroup(group);
    });
    scope.querySelectorAll('[data-glass-dock]').forEach((bar) => {
      const controller = controllers.get(bar);
      if (controller) controller.refresh();
    });
  };

  const boot = () => {
    document.querySelectorAll('[data-glass-group]').forEach(mountGroup);
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();

  window.LiquidGlass = {
    mount,
    mountGroup,
    refresh,
    controller: (element) => controllers.get(element) || null,
    version: '1.0.0',
  };
})();
