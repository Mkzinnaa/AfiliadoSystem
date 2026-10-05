(() => {
  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (standalone) return;

  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'pwa-install-button';
  button.textContent = '↓ Instalar app';
  button.setAttribute('aria-label', 'Instalar o Vértice no celular');
  button.hidden = true;
  document.body.append(button);

  const userAgent = navigator.userAgent || '';
  const isIOS = /iPad|iPhone|iPod/.test(userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isAndroid = /Android/i.test(userAgent);
  if (isIOS || isAndroid) button.hidden = false;

  let installPrompt = null;
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event;
    button.hidden = false;
  });

  button.addEventListener('click', async () => {
    if (installPrompt) {
      installPrompt.prompt();
      await installPrompt.userChoice;
      installPrompt = null;
      return;
    }
    if (isIOS) {
      window.alert('No Safari, toque em Compartilhar e escolha “Adicionar à Tela de Início”.');
    } else if (isAndroid) {
      window.alert('No menu do navegador (⋮), escolha “Instalar app” ou “Adicionar à tela inicial”.');
    }
  });

  window.addEventListener('appinstalled', () => { button.hidden = true; });

  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/service-worker.js', { scope: '/' }).catch((error) => console.warn('Vértice PWA:', error));
    }, { once: true });
  }
})();
