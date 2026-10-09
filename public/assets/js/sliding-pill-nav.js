(() => {
  'use strict';

  const navSelector = 'body.app-shell .main-nav, body.app-shell .topnav';
  const activeSelector = 'a.selected, a.active, a[aria-current="page"]';

  function setup(nav) {
    if (nav.dataset.slidingPillReady === 'true') return;
    nav.dataset.slidingPillReady = 'true';

    const pill = document.createElement('span');
    pill.className = 'nav-pill-indicator';
    pill.setAttribute('aria-hidden', 'true');
    nav.prepend(pill);

    const links = () => Array.from(nav.querySelectorAll(':scope > a'));
    let active = nav.querySelector(activeSelector);
    if (!active) {
      const path = window.location.pathname.replace(/\/+$/, '').toLowerCase();
      active = links().find(link => {
        try { return new URL(link.href, window.location.href).pathname.replace(/\/+$/, '').toLowerCase() === path; }
        catch { return false; }
      }) || null;
    }
    if (active) active.setAttribute('aria-current', 'page');

    function moveTo(link, visible = true) {
      if (!link || !link.isConnected) {
        pill.classList.remove('is-visible');
        return;
      }
      const navRect = nav.getBoundingClientRect();
      const itemRect = link.getBoundingClientRect();
      const x = itemRect.left - navRect.left - nav.clientLeft + nav.scrollLeft;
      const y = itemRect.top - navRect.top - nav.clientTop + nav.scrollTop;
      pill.style.width = `${itemRect.width}px`;
      pill.style.height = `${itemRect.height}px`;
      pill.style.transform = `translate3d(${x}px, ${y}px, 0)`;
      pill.classList.toggle('is-visible', visible);
    }

    function syncActive(link) {
      if (active && active !== link) active.removeAttribute('aria-current');
      active = link;
      if (active) active.setAttribute('aria-current', 'page');
    }

    nav.addEventListener('pointerover', event => {
      if (event.pointerType !== 'mouse' && event.pointerType !== 'pen') return;
      const link = event.target.closest('a');
      if (link && nav.contains(link)) moveTo(link);
    });

    nav.addEventListener('pointerleave', event => {
      if (event.pointerType === 'touch') return;
      moveTo(active);
    });

    nav.addEventListener('focusin', event => {
      const link = event.target.closest('a');
      if (link && nav.contains(link)) moveTo(link);
    });

    nav.addEventListener('focusout', () => {
      requestAnimationFrame(() => {
        if (!nav.contains(document.activeElement)) moveTo(active);
      });
    });

    nav.addEventListener('click', event => {
      const link = event.target.closest('a');
      if (!link || !nav.contains(link)) return;
      syncActive(link);
      moveTo(link);
    });

    nav.addEventListener('scroll', () => moveTo(active), { passive: true });
    window.addEventListener('resize', () => moveTo(active), { passive: true });
    if ('ResizeObserver' in window) {
      const observer = new ResizeObserver(() => moveTo(active));
      observer.observe(nav);
      links().forEach(link => observer.observe(link));
    }
    requestAnimationFrame(() => moveTo(active));
  }

  function init() { document.querySelectorAll(navSelector).forEach(setup); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
