
/* Public search previews reuse the server's published archive queries. */
(()=>{'use strict';
document.querySelector('[data-search-sort] select')?.addEventListener('change',e=>{const u=new URL(location.href);u.searchParams.set('sort',e.target.value);u.searchParams.delete('p');location.assign(u);});
const form=document.querySelector('[data-advanced-search]');if(!form)return;
const fields=form.querySelector('[data-advanced-fields]'),status=form.querySelector('[data-advanced-status]'),apply=form.querySelector('[data-advanced-apply]'),retry=form.querySelector('[data-advanced-retry]');
const levels=['country','province','county','city'];let timer,controller,sequence=0,changed='',pending=false;
const fa=n=>String(n).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);
const normal=s=>String(s).replace(/ي/g,'ی').replace(/ك/g,'ک').toLocaleLowerCase('fa').trim();
function params(){const p=new URLSearchParams();for(const [k,v] of new FormData(form))if(v!=='')p.append(k,v);p.set('page','advanced-search-data');if(changed)p.set('changed',changed);return p;}
function busy(on){pending=on;form.classList.toggle('is-loading',on);apply.disabled=on;if(on)status.textContent='در حال محاسبه نتایج…';}
function opened(){return [...fields.querySelectorAll('details')].map(d=>d.open);}
async function preview(redraw=true){clearTimeout(timer);controller?.abort();const id=++sequence;busy(true);retry.hidden=true;timer=setTimeout(async()=>{controller=new AbortController();try{const r=await fetch('/index.php?'+params(),{headers:{Accept:'application/json'},credentials:'same-origin',signal:controller.signal});const data=await r.json();if(id!==sequence)return;if(!r.ok||!data.ok)throw Error(data.message||'دریافت فیلترها انجام نشد.');if(redraw){const open=opened(),focus=document.activeElement,selection=focus&&fields.contains(focus)?{name:focus.name,value:focus.value}:null;fields.innerHTML=data.fieldsHtml;fields.querySelectorAll('details').forEach((d,i)=>{if(open[i]!==undefined)d.open=open[i];});if(selection)[...fields.querySelectorAll('input,select')].find(e=>e.name===selection.name&&e.value===selection.value)?.focus({preventScroll:true});}
changed='';const people=data.results==='martyrs';apply.textContent='نمایش '+fa(data.count)+' '+(people?'شهید':'تصویر');status.textContent='تعداد نتایج: '+fa(data.count)+(data.cleared.length?'؛ انتخاب‌های سطوح پایین‌تر پاک شدند.':'');form.querySelector('[data-clear-all]').href='/advanced-search?results='+data.results;busy(false);}catch(e){if(e.name==='AbortError'||id!==sequence)return;busy(false);status.textContent=e.message||'خطای ارتباط؛ دوباره تلاش کنید.';retry.hidden=false;}},250);}
form.addEventListener('change',e=>{if(e.target.matches('[data-option-search]'))return;const input=e.target;if(input.matches('[data-axis-mode=single]')&&input.checked)for(const sibling of form.querySelectorAll('input[type=checkbox]'))if(sibling!==input&&sibling.name===input.name)sibling.checked=false;
const geo=input.closest('[data-advanced-geo]');if(geo){changed=geo.dataset.advancedGeo;const [role,level]=changed.split('.');for(const child of levels.slice(levels.indexOf(level)+1)){const select=form.elements.namedItem('places['+role+']['+child+']');if(select)select.value='';}}
if(input.name==='results'){const sort=form.elements.namedItem('sort');if(input.value==='martyrs'){if(sort.value==='downloads')sort.value='new';sort.querySelector('option[value=downloads]')?.remove();}else if(!sort.querySelector('option[value=downloads]')){const o=document.createElement('option');o.value='downloads';o.textContent='بیشترین دریافت تصاویر';sort.append(o);}}
preview(true);});
form.addEventListener('input',e=>{const input=e.target;if(input.matches('[data-option-search]')){const axis=input.closest('fieldset'),q=normal(input.value);axis.querySelectorAll('[data-option-text]').forEach(row=>row.hidden=q!==''&&!normal(row.dataset.optionText).includes(q));return;}if(input.matches('input:not([type=checkbox])'))preview(false);});
retry.addEventListener('click',()=>preview(true));
form.addEventListener('submit',()=>{controller?.abort();clearTimeout(timer);for(const input of form.querySelectorAll('[data-option-search]'))input.removeAttribute('name');});
})();
