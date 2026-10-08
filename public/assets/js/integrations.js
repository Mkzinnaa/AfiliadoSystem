document.addEventListener('DOMContentLoaded', () => {
  const platformSelect = document.getElementById('platformSelect');
  const secretField = document.getElementById('providerSecretField');
  const secretInput = document.getElementById('providerSecretInput');
  const secretLabel = document.getElementById('providerSecretLabel');
  const secretHelp = document.getElementById('providerSecretHelp');
  const updatePlatformFields = () => {
    const platform = platformSelect?.value || 'kiwify';
    const needsSecret = platform === 'hotmart' || platform === 'eduzz';
    if (secretField) secretField.hidden = !needsSecret;
    if (secretInput) {
      secretInput.required = needsSecret;
      secretInput.disabled = !needsSecret;
      secretInput.maxLength = platform === 'eduzz' ? 255 : 140;
      secretInput.name = platform === 'eduzz' ? 'platform_secret' : 'hottok';
    }
    if (secretLabel) secretLabel.textContent = platform === 'eduzz' ? 'Origin secret do webhook Eduzz' : 'Hottok da Hotmart';
    if (secretHelp) secretHelp.textContent = platform === 'eduzz'
      ? 'Use o valor originSecret recebido no campo data.producer.originSecret do webhook Eduzz.'
      : 'O Hottok é criptografado e não volta a ser exibido.';
    if (secretInput) {
      secretInput.placeholder = platform === 'eduzz' ? 'Cole o originSecret do webhook' : 'Cole o Hottok do webhook';
    }
    document.querySelectorAll('[data-platform-instructions]').forEach((panel) => {
      panel.hidden = panel.dataset.platformInstructions !== platform;
    });
  };
  platformSelect?.addEventListener('change', updatePlatformFields);
  updatePlatformFields();

  document.querySelectorAll('[data-copy], [data-copy-input]').forEach((button) => {
    button.addEventListener('click', async () => {
      const input = button.hasAttribute('data-copy')
        ? document.getElementById(button.dataset.copy)
        : button.parentElement?.querySelector('input');
      if (!input) return;
      try {
        await navigator.clipboard.writeText(input.value);
      } catch (_) {
        input.select();
        document.execCommand('copy');
      }
      const original = button.textContent;
      button.textContent = 'Copiado!';
      window.setTimeout(() => { button.textContent = original; }, 1600);
    });
  });
});
