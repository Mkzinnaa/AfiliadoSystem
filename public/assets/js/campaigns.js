const campaignModal=document.getElementById('campaignModal');
const openCampaign=document.getElementById('newCampaign');
const closeCampaign=document.getElementById('closeModal');
if(openCampaign&&campaignModal)openCampaign.addEventListener('click',()=>campaignModal.classList.add('show'));
if(closeCampaign)closeCampaign.addEventListener('click',()=>location.href='campaigns.php');
if(campaignModal)campaignModal.addEventListener('click',event=>{if(event.target===campaignModal)location.href='campaigns.php'});
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&campaignModal?.classList.contains('show'))location.href='campaigns.php'});
const metric=document.querySelector('[name="metric"]');
const target=document.querySelector('[name="target"]');
if(metric&&target)metric.addEventListener('change',()=>{const countMetric=metric.value==='orders'||metric.value==='new_customers';const rateMetric=metric.value==='conversion';target.step=countMetric?'1':'0.01';target.min=rateMetric?'0.01':'1';if(rateMetric)target.max='100';else target.removeAttribute('max');target.placeholder=rateMetric?'4.8':(countMetric?'50':'50000')});
const campaignScope=document.getElementById('campaignScope');
const campaignGroupField=document.getElementById('campaignGroupField');
const campaignAffiliateField=document.getElementById('campaignAffiliateField');
if(campaignScope&&campaignGroupField&&campaignAffiliateField){
  const groupSelect=campaignGroupField.querySelector('select');
  const affiliateSelect=campaignAffiliateField.querySelector('select');
  const updateAudience=()=>{
    campaignGroupField.style.display=campaignScope.value==='group'?'grid':'none';
    campaignAffiliateField.style.display=campaignScope.value==='affiliate'?'grid':'none';
    if(groupSelect)groupSelect.required=campaignScope.value==='group';
    if(affiliateSelect)affiliateSelect.required=campaignScope.value==='affiliate';
  };
  campaignScope.addEventListener('change',updateAudience);
  updateAudience();
}
