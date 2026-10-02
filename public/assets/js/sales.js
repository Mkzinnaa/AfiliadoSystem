const exportButton = document.getElementById('exportBtn');
if (exportButton) exportButton.addEventListener('click', () => {
  const rows = [['Afiliado','Grupo','Pedidos','Faturamento','Comissão','Comissão estimada','Status'], ...Array.from(document.querySelectorAll('#salesTable tbody tr')).filter(row => row.cells.length === 7).map(row => Array.from(row.cells).map(cell => cell.innerText.trim().replace(/\s+/g, ' ')))];
  const csv = '\ufeff' + rows.map(row => row.map(value => '"' + value.replace(/"/g, '""') + '"').join(';')).join('\r\n');
  const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8'})); link.download = 'vendas-afiliados.csv'; link.click(); URL.revokeObjectURL(link.href);
});
