(() => {
  'use strict';

  const toggle = document.getElementById('nav-toggle');
  const navigation = document.getElementById('site-navigation');

  if (toggle && navigation) {
    const closeNavigation = () => {
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Open navigation');
      navigation.classList.remove('is-open');
    };

    toggle.addEventListener('click', () => {
      const isOpen = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!isOpen));
      toggle.setAttribute('aria-label', isOpen ? 'Open navigation' : 'Close navigation');
      navigation.classList.toggle('is-open', !isOpen);
    });

    navigation.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', closeNavigation);
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closeNavigation();
    });

    document.addEventListener('click', (event) => {
      if (!event.target.closest('.site-nav')) closeNavigation();
    });

    window.addEventListener('resize', () => {
      if (window.innerWidth > 1100) closeNavigation();
    }, { passive: true });
  }

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const revealTargets = document.querySelectorAll('[data-reveal]');
  if (!reduceMotion && 'IntersectionObserver' in window && revealTargets.length) {
    document.documentElement.classList.add('motion-ready');
    const revealObserver = new IntersectionObserver((entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -35px 0px' });

    revealTargets.forEach((element, index) => {
      element.style.setProperty('--reveal-delay', `${Math.min(index % 4, 3) * 75}ms`);
      revealObserver.observe(element);
    });
  }
})();
