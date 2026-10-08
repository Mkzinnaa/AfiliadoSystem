(() => {
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const transitionMs = motion.matches ? 0 : 220;
  let loader;
  let loadingVisible = false;
  let pageReady = document.readyState === 'complete';
  let navigationStarted = false;
  let showTimer;
  let removeTimer;

  const ensureLoader = () => {
    if (loader?.isConnected) return loader;
    loader = document.createElement('div');
    loader.className = 'affiliey-page-loader';
    loader.setAttribute('role', 'status');
    loader.setAttribute('aria-live', 'polite');
    loader.setAttribute('aria-label', 'Carregando página');
    loader.innerHTML = `
      <div class="affiliey-loader-content">
        <div class="affiliey-loader-brand" aria-hidden="true">
          <img src="/brand/affiliey-symbol.png" alt="" width="65" height="65">
        </div>
        <div class="affiliey-loader-wordmark">AFFILIEY<span>PLATAFORMA DE PERFORMANCE</span></div>
        <div class="affiliey-loader-signal"><i></i><span>Conectando ao seu espaço</span></div>
        <p class="affiliey-loader-label">Preparando seu painel com segurança</p>
        <div class="affiliey-loader-progress" aria-hidden="true"><span></span></div>
        <div class="affiliey-loader-skeleton" aria-hidden="true">
          <div class="affiliey-skeleton-bar short"></div>
          <div class="affiliey-skeleton-bar medium"></div>
          <div class="affiliey-skeleton-grid">
            <div class="affiliey-skeleton-card"></div>
            <div class="affiliey-skeleton-card"></div>
            <div class="affiliey-skeleton-card"></div>
          </div>
          <div class="affiliey-skeleton-card"></div>
        </div>
      </div>`;
    document.body.append(loader);
    return loader;
  };

  const showLoader = () => {
    clearTimeout(removeTimer);
    loadingVisible = true;
    const node = ensureLoader();
    node.classList.remove('is-hiding');
    requestAnimationFrame(() => node.classList.add('is-visible'));
  };

  const hideLoader = () => {
    pageReady = true;
    clearTimeout(showTimer);
    if (!loadingVisible) {
      loader?.remove();
      return;
    }
    loader?.classList.remove('is-visible');
    loader?.classList.add('is-hiding');
    removeTimer = window.setTimeout(() => {
      loader?.remove();
      loader = null;
      loadingVisible = false;
    }, transitionMs + 30);
  };

  const enterPage = () => {
    document.body.classList.add('affiliey-page-enter');
    window.setTimeout(() => document.body.classList.remove('affiliey-page-enter'), motion.matches ? 0 : 300);
  };

  if (pageReady) {
    enterPage();
  } else {
    showTimer = window.setTimeout(() => {
      if (!pageReady) showLoader();
    }, 150);
    window.addEventListener('load', () => {
      enterPage();
      hideLoader();
    }, { once: true });
  }

  window.addEventListener('pageshow', (event) => {
    navigationStarted = false;
    document.body.classList.remove('affiliey-page-leave');
    if (event.persisted) enterPage();
    hideLoader();
  });

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : event.target?.parentElement;
    const link = target?.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (link.hasAttribute('download') || link.target && link.target !== '_self' || link.hasAttribute('data-no-transition')) return;

    const destination = new URL(link.href, window.location.href);
    if (destination.origin !== window.location.origin) return;
    if (destination.pathname === window.location.pathname && destination.search === window.location.search) return;
    if (destination.hash && destination.pathname === window.location.pathname && destination.search === window.location.search) return;
    if (navigationStarted) return;

    event.preventDefault();
    navigationStarted = true;
    showLoader();
    document.body.classList.add('affiliey-page-leave');
    window.setTimeout(() => window.location.assign(destination.href), transitionMs);
  });

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (event.defaultPrevented || !(form instanceof HTMLFormElement) || form.target && form.target !== '_self' || form.hasAttribute('data-no-transition')) return;
    showLoader();
  });
})();
