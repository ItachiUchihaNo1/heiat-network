(() => {
'use strict';
let count=0;
const dirty=new Set();
const initPlace=(place)=>{
 const selects=[...place.querySelectorAll('[data-geo-level]')];if(!selects.length||place.dataset.geoReady)return;place.dataset.geoReady='1';
 const filter=()=>selects.forEach((s,i)=>{if(!i)return;const parent=selects[i-1].value;[...s.options].forEach(o=>{o.hidden=!!o.value&&(!parent||o.dataset.parent!==parent);o.disabled=o.hidden;});});
 selects.forEach((s,i)=>{s.dataset.previous=s.value;s.addEventListener('change',()=>{
  const children=selects.slice(i+1);if(children.some(c=>c.value)&&!window.confirm('با تغییر سطح بالاتر، گزینه‌های ناسازگار پایین‌تر پاک می‌شوند. ادامه می‌دهید؟')){s.value=s.dataset.previous;filter();return;}
  children.forEach(c=>{c.value='';c.dataset.previous='';});s.dataset.previous=s.value;filter();
 });});filter();
};
document.querySelectorAll('.protocol-place').forEach(initPlace);
document.addEventListener('input',e=>{
 const form=e.target.closest('[data-dirty-form]');if(form)dirty.add(form);
 if(e.target.name==='data[first_name]'||e.target.name==='data[last_name]'){
 const form=e.target.form,display=form?.elements.namedItem('data[display_name]');
 if(display&&(!display.value||display.value===display.dataset.autoName)){const first=form.elements.namedItem('data[first_name]')?.value||'',last=form.elements.namedItem('data[last_name]')?.value||'';display.value=(first+' '+last).trim();display.dataset.autoName=display.value;}
}
 const search=e.target.closest('[data-select-search]');if(search){const select=document.getElementById(search.dataset.selectSearch);if(select){const q=search.value.trim().replaceAll('ي','ی').replaceAll('ك','ک');[...select.options].forEach(o=>{o.hidden=!!q&&!o.selected&&!o.textContent.replaceAll('ي','ی').replaceAll('ك','ک').includes(q);});}}
});
document.addEventListener('change',e=>{const f=e.target.closest('[data-dirty-form]');if(f)dirty.add(f);});
document.addEventListener('submit',e=>{dirty.delete(e.target);});
document.addEventListener('click',e=>{
 const add=e.target.closest('[data-add-record]');if(add){const kind=add.dataset.addRecord;const template=document.querySelector('[data-record-template="'+kind+'"]');const list=document.querySelector('[data-record-list="'+kind+'"]');if(template&&list){const fragment=document.createElement('template');fragment.innerHTML=template.innerHTML.replaceAll('__INDEX__','new'+Date.now()+'_'+(++count));list.appendChild(fragment.content.cloneNode(true));list.querySelectorAll('.protocol-place').forEach(initPlace);dirty.add(add.closest('form'));}return;}
 const remove=e.target.closest('[data-remove-record]');if(remove){dirty.add(remove.closest('form'));remove.closest('.protocol-record').remove();}
});
window.addEventListener('beforeunload',e=>{if(dirty.size){e.preventDefault();e.returnValue='';}});
})();
