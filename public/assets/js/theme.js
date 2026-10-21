/*
 * Appearance switching for every SVIET portal.
 * The actual theme is applied before paint by the inline bootstrap in each
 * layout; this file wires the visible switch and keeps it in sync.
 */
(() => {
  'use strict';

  const STORAGE_KEY = 'sviet-theme';
  const root = document.documentElement;
  const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  let explicit = false;

  const stored = () => {
    try {
      const value = window.localStorage.getItem(STORAGE_KEY);
      return value === 'dark' || value === 'light' ? value : '';
    } catch (_) {
      return '';
    }
  };

  const resolve = () => {
    const saved = stored();
    explicit = saved !== '';
    return saved || 'light';
  };

  const paint = (theme) => {
    root.dataset.theme = theme;
    root.style.colorScheme = theme;
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
      const next = theme === 'dark' ? 'light' : 'dark';
      button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
      button.setAttribute('aria-label', `Switch to ${next} appearance`);
      button.setAttribute('title', `Switch to ${next} appearance`);
      const label = button.querySelector('.theme-toggle__label');
      if (label) label.textContent = theme === 'dark' ? 'Dark' : 'Light';
    });
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#0b0c0e' : '#fff7f0');
  };

  paint(resolve());

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-theme-toggle]');
    if (!button) return;
    event.preventDefault();
    const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
    explicit = true;
    try {
      window.localStorage.setItem(STORAGE_KEY, next);
    } catch (_) {
      /* Private mode: the choice lasts for this page only. */
    }
    paint(next);
  });

  if (media) {
    const onChange = () => {
      if (!explicit) paint(media.matches ? 'dark' : 'light');
    };
    if (typeof media.addEventListener === 'function') media.addEventListener('change', onChange);
    else if (typeof media.addListener === 'function') media.addListener(onChange);
  }

  window.addEventListener('pageshow', (event) => {
    if (event.persisted) paint(root.dataset.theme || resolve());
  });
})();

/*
 * Watery top menu. The floating bubble cluster has no bar, so the menu is a
 * sheet of water that grows out of the menu bubble.
 */
(() => {
  'use strict';

  const sheet = document.querySelector('[data-bubble-sheet]');
  const openers = Array.from(document.querySelectorAll('[data-bubble-menu]'));
  if (!sheet || openers.length === 0) return;

  const panel = sheet.querySelector('.bubble-sheet__panel');
  const closers = Array.from(sheet.querySelectorAll('[data-bubble-close]'));
  let lastFocus = null;
  let closeTimer = 0;

  const open = (opener) => {
    window.clearTimeout(closeTimer);
    lastFocus = opener;
    sheet.hidden = false;
    document.body.classList.add('sheet-open');
    opener.setAttribute('aria-expanded', 'true');
    window.requestAnimationFrame(() => sheet.classList.add('is-open'));
    const first = panel ? panel.querySelector('a, button') : null;
    if (first) window.setTimeout(() => first.focus({ preventScroll: true }), 180);
  };

  const close = (restoreFocus = true) => {
    sheet.classList.remove('is-open');
    document.body.classList.remove('sheet-open');
    openers.forEach((opener) => opener.setAttribute('aria-expanded', 'false'));
    closeTimer = window.setTimeout(() => { sheet.hidden = true; }, 260);
    if (restoreFocus && lastFocus) lastFocus.focus({ preventScroll: true });
  };

  openers.forEach((opener) => {
    opener.addEventListener('click', () => {
      if (sheet.classList.contains('is-open')) close();
      else open(opener);
    });
  });

  closers.forEach((button) => button.addEventListener('click', () => close()));

  sheet.addEventListener('click', (event) => {
    if (event.target === sheet) close();
  });

  sheet.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
    }
  });

  sheet.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      // Let native navigation happen; just release the scroll lock.
      document.body.classList.remove('sheet-open');
    });
  });

  window.addEventListener('popstate', () => {
    if (sheet.classList.contains('is-open')) close(false);
  });
})();
