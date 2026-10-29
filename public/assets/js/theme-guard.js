/*
 * Theme guard — keeps a half-styled page from ever facing a student.
 *
 * The dock is the one fixed, floating surface in the product: if its stylesheet
 * never arrives (blocked proxy, stale cache, a typo in a path), the raw rail
 * paints as a wall of text over the page and the only navigation students have
 * is buried under it. The same failure turns an unsized <svg> into a large
 * solid block.
 *
 * So before anything is trusted:
 *   1. probe whether the LogicBloom stylesheet actually applied (it publishes
 *      --lb-accent on :root; a stylesheet that never loaded cannot);
 *   2. probe the dock's own stylesheet with a live element;
 *   3. if either is missing, hide the dock and let the bubble menu — which is
 *      always plain markup — carry the navigation.
 *
 * Nothing here changes a working page: when both stylesheets apply, the only
 * thing that happens is the guard going quiet.
 */
(function () {
  'use strict';

  var DOCK_SELECTOR = '[data-glass-dock], .lg-dock, .sv-dock, .rec-dock, .admin-dock';

  function tokenApplied() {
    var value = getComputedStyle(document.documentElement).getPropertyValue('--lb-accent');
    return value.trim() !== '';
  }

  function dockStylesApplied(dock) {
    if (!dock) return false;
    var probe = document.createElement('div');
    probe.className = dock.className;
    probe.setAttribute('aria-hidden', 'true');
    probe.style.cssText = 'position:absolute;left:-9999px;top:0;visibility:hidden;pointer-events:none;';
    dock.parentNode.appendChild(probe);
    var style = getComputedStyle(probe);
    var applied = style.position === 'fixed' || style.display !== 'inline';
    probe.remove();
    return applied;
  }

  function hideDock(dock, reason) {
    dock.style.display = 'none';
    dock.setAttribute('data-theme-guard', reason);
    // The dock owns bottom clearance; without it the page would keep a gap.
    document.body.classList.remove('has-dock');
    document.documentElement.classList.remove('lg-dock-ready');
  }

  function run() {
    var docks = document.querySelectorAll(DOCK_SELECTOR);
    if (!docks.length) return;

    var themeOk = tokenApplied();
    docks.forEach(function (dock) {
      if (!themeOk || !dockStylesApplied(dock)) {
        hideDock(dock, themeOk ? 'dock-css-missing' : 'theme-css-missing');
      }
    });

    // Any icon that somehow lost its intrinsic size would paint as a solid
    // block; a zero-size box is invisible instead of destructive.
    if (!themeOk) {
      document.querySelectorAll('svg:not([width])').forEach(function (svg) {
        svg.setAttribute('width', '16');
        svg.setAttribute('height', '16');
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run, { once: true });
  } else {
    run();
  }
})();
