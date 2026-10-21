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

  /* Password reveal: the button ships hidden and is only shown once we know
     JS can actually flip the input type, so it never appears as dead chrome. */
  document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = document.getElementById(button.getAttribute('data-password-toggle'));
    if (!input) return;
    button.hidden = false;
    const label = button.querySelector('.sr-only');
    button.addEventListener('click', () => {
      const revealing = input.type === 'password';
      input.type = revealing ? 'text' : 'password';
      button.setAttribute('aria-pressed', String(revealing));
      button.setAttribute('aria-label', revealing ? 'Hide password' : 'Show password');
      if (label) label.textContent = revealing ? 'Hide password' : 'Show password';
      input.focus({ preventScroll: true });
      const end = input.value.length;
      try { input.setSelectionRange(end, end); } catch (_) { /* type may not support it */ }
    });
  });

  /* Submit feedback: the server answers with a full page, so without this a
     slow connection looks like a dead button and invites a double submit.
     The form is never prevented from posting. */
  document.querySelectorAll('form.auth-form').forEach((form) => {
    form.addEventListener('submit', () => {
      if (!form.checkValidity || !form.checkValidity()) return;
      const button = form.querySelector('[data-busy-label]');
      if (!button || button.getAttribute('aria-busy') === 'true') return;
      button.setAttribute('aria-busy', 'true');
      button.disabled = true;
      button.dataset.rest = button.innerHTML;
      button.textContent = button.dataset.busyLabel;
    });
  });

  /* A back/forward restore can hand back the page with a disabled button. */
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('form.auth-form [aria-busy="true"]').forEach((button) => {
      button.removeAttribute('aria-busy');
      button.disabled = false;
      if (button.dataset.rest) button.innerHTML = button.dataset.rest;
    });
  });

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
