const audienceType=document.getElementById('audienceType');
const groupField=document.getElementById('audienceGroupField');
const affiliateField=document.getElementById('audienceAffiliateField');
if(audienceType&&groupField&&affiliateField){
  const groupSelect=groupField.querySelector('select');
  const affiliateSelect=affiliateField.querySelector('select');
  const updateAudience=()=>{
    groupField.style.display=audienceType.value==='group'?'grid':'none';
    affiliateField.style.display=audienceType.value==='affiliate'?'grid':'none';
    if(groupSelect)groupSelect.required=audienceType.value==='group';
    if(affiliateSelect)affiliateSelect.required=audienceType.value==='affiliate';
  };
  audienceType.addEventListener('change',updateAudience);
  updateAudience();
}
