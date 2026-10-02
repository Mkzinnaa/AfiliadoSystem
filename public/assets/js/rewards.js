(() => {
  const metric = document.querySelector('select[name="metric"]');
  const target = document.querySelector('input[name="target"]');
  if (!metric || !target) return;
  const syncPrecision = () => {
    const countBased = metric.value !== 'revenue';
    target.step = countBased ? '1' : '0.01';
    target.min = countBased ? '1' : '0.01';
  };
  metric.addEventListener('change', syncPrecision);
  syncPrecision();
})();
