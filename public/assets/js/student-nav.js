/*
 * Student workspace navigation.
 * The dock is a set of real links, so routing stays with the browser (and with
 * cross-document view transitions). This file only mounts the shared liquid
 * glass capsule and remembers the last workspace so the capsule flows in from
 * the previous tab on arrival.
 */
(() => {
  'use strict';

  const dock = document.querySelector('.sv-dock[data-glass-dock]');
  if (!dock || !window.LiquidGlass) return;

  window.LiquidGlass.mount({
    bar: dock,
    track: dock.querySelector('#sv-dock-track'),
    links: Array.from(dock.querySelectorAll('.sv-dock-link')),
    restoreKey: 'sviet-student-dock-index',
  });
})();
