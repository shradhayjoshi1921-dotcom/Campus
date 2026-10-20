(() => {
  'use strict';

  const nav = document.querySelector('.rec-bottom-nav');
  if (!nav) return;

  const indicator = nav.querySelector('.rec-nav-indicator');
  const links = Array.from(nav.querySelectorAll('.rec-nav-link'));
  if (!indicator || links.length === 0) return;

  const activeIndex = links.findIndex((link) => link.getAttribute('aria-current') === 'page');
  if (activeIndex < 0) return;

  const storageKey = 'sviet-recruiter-nav-index';
  let previousIndex = null;
  try {
    const storedValue = window.sessionStorage.getItem(storageKey);
    if (storedValue !== null && storedValue !== '') {
      const storedIndex = Number(storedValue);
      if (Number.isInteger(storedIndex) && storedIndex >= 0 && storedIndex < links.length) {
        previousIndex = storedIndex;
      }
    }
  } catch (_) {
    // The nav remains usable with its CSS active-state fallback if storage is blocked.
  }

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const offsetFor = (index) => {
    const link = links[index];
    return Math.round(link.offsetLeft + ((link.offsetWidth - indicator.offsetWidth) / 2));
  };
  const moveTo = (index) => {
    indicator.style.transform = `translate3d(${offsetFor(index)}px, 0, 0)`;
  };

  nav.classList.add('has-sliding-indicator', 'is-priming');
  moveTo(previousIndex !== null && previousIndex !== activeIndex && !prefersReducedMotion
    ? previousIndex
    : activeIndex);
  indicator.getBoundingClientRect();

  if (previousIndex !== null && previousIndex !== activeIndex && !prefersReducedMotion) {
    window.requestAnimationFrame(() => {
      nav.classList.remove('is-priming');
      indicator.getBoundingClientRect();
      indicator.classList.add('is-moving');
      indicator.addEventListener('animationend', (event) => {
        if (event.target === indicator) indicator.classList.remove('is-moving');
      }, { once: true });
      moveTo(activeIndex);
    });
  } else {
    window.requestAnimationFrame(() => nav.classList.remove('is-priming'));
  }

  const rememberActiveIndex = () => {
    try {
      window.sessionStorage.setItem(storageKey, String(activeIndex));
    } catch (_) {
      // Navigation still works normally when session storage is unavailable.
    }
  };
  rememberActiveIndex();
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) rememberActiveIndex();
  });

  window.addEventListener('resize', () => {
    nav.classList.add('is-priming');
    moveTo(activeIndex);
    indicator.getBoundingClientRect();
    window.requestAnimationFrame(() => nav.classList.remove('is-priming'));
  }, { passive: true });
})();
