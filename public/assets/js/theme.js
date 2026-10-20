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
      if (label) label.textContent = theme === 'dark' ? 'Dark water' : 'Light orange';
    });
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#051519' : '#fff8f1');
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
