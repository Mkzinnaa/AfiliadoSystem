const modal=document.getElementById('modal');
const newButton=document.getElementById('newBtn');
const closeButton=document.getElementById('closeBtn');
if(newButton)newButton.onclick=()=>{location.href='affiliates.php?new=1'};
if(closeButton)closeButton.onclick=()=>location.href='affiliates.php';
if(modal){modal.addEventListener('click',e=>{if(e.target===modal)location.href='affiliates.php'});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('show'))location.href='affiliates.php'});}
const settingsModal=document.getElementById('settingsModal');
const settingsButton=document.getElementById('settingsBtn');
const closeSettingsButton=document.getElementById('closeSettingsBtn');
const setSettingsOpen=open=>{if(!settingsModal)return;settingsModal.classList.toggle('show',open);settingsModal.setAttribute('aria-hidden',String(!open));document.body.classList.toggle('modal-open',open);if(open)closeSettingsButton?.focus();else settingsButton?.focus()};
if(settingsButton)settingsButton.addEventListener('click',()=>setSettingsOpen(true));
if(closeSettingsButton)closeSettingsButton.addEventListener('click',()=>setSettingsOpen(false));
if(settingsModal){settingsModal.addEventListener('click',event=>{if(event.target===settingsModal)setSettingsOpen(false)});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&settingsModal.classList.contains('show'))setSettingsOpen(false)})}
document.querySelectorAll('.copy-link').forEach(b=>b.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(b.dataset.link);b.textContent='Copiado!'}catch{prompt('Copie o link do afiliado:',b.dataset.link)}}));
const copyApplicationLink=document.getElementById('copyApplicationLink');
if(copyApplicationLink)copyApplicationLink.addEventListener('click',async()=>{const value=document.getElementById('applicationLink').value;try{await navigator.clipboard.writeText(value);copyApplicationLink.textContent='Copiado!'}catch{prompt('Copie o link de inscrição:',value)}});
document.getElementById('exportBtn').onclick=()=>{const rows=[['Nome','E-mail','Grupo','Vendas','Comissão','Código','Status'],...Array.from(document.querySelectorAll('tbody tr')).filter(r=>r.cells.length===7).map(r=>Array.from(r.cells).map(c=>c.innerText.trim().replace(/\s+/g,' ')))];const csv='\ufeff'+rows.map(r=>r.map(v=>'"'+v.replace(/"/g,'""')+'"').join(';')).join('\r\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));a.download='afiliados.csv';a.click();URL.revokeObjectURL(a.href)};
