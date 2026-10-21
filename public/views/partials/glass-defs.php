<?php
/**
 * Shared SVG definitions for the liquid-glass navigation.
 *
 * The pill's interior (`.lg-smoke`) references the `#lg-refract` filter. A
 * `filter: url()` that points at a missing element renders nothing in
 * Chromium, so the definition ships with every layout that mounts the dock.
 * The noise is static and low frequency: it bends the highlight a couple of
 * pixels, exactly like looking through the thick part of a glass bead.
 */
?>
<svg class="lg-defs" aria-hidden="true" focusable="false" width="0" height="0" role="presentation">
    <filter id="lg-refract" x="-15%" y="-15%" width="130%" height="130%" color-interpolation-filters="sRGB">
        <feTurbulence type="fractalNoise" baseFrequency="0.011 0.028" numOctaves="2" seed="9" result="lg-noise"/>
        <feGaussianBlur in="lg-noise" stdDeviation="1.6" result="lg-soft-noise"/>
        <feDisplacementMap in="SourceGraphic" in2="lg-soft-noise" scale="7" xChannelSelector="R" yChannelSelector="G"/>
    </filter>
</svg>
