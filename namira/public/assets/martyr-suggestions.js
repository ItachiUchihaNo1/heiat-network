(() => {
 'use strict';
 document.querySelectorAll('form[data-martyr-suggestions]').forEach(form => {
  const first=form.querySelector('[name="first_name"], [name="data[first_name]"]'),last=form.querySelector('[name="last_name"], [name="data[last_name]"]'),box=form.querySelector('[data-similar-results]');
  if(!first||!last||!box)return;
  let timer,controller,revision=0;
  const refresh=()=>{clearTimeout(timer);controller?.abort();const current=++revision;box.replaceChildren();box.hidden=true;if((first.value+' '+last.value).trim().length<2)return;
   timer=setTimeout(async()=>{controller=new AbortController();const url=new URL(form.dataset.martyrSuggestions,location.origin);url.searchParams.set('first',first.value);url.searchParams.set('last',last.value);
    try{const response=await fetch(url,{signal:controller.signal,headers:{Accept:'application/json'}});if(!response.ok)throw Error('lookup');const rows=await response.json();if(current!==revision)return;box.replaceChildren();if(!rows.length)return;box.hidden=false;
     const text=document.createElement('p');text.textContent='نام‌های مشابه؛ برای جلوگیری از تکرار بررسی کنید. ثبت افراد هم‌نام همچنان مجاز است.';box.append(text);
     rows.forEach(row=>{const link=document.createElement('a');link.href=row.url;link.target='_blank';link.rel='noopener';link.textContent=row.first_name+' '+row.last_name+' · '+row.code+(row.father_name?' · فرزند '+row.father_name:'')+(row.location?' · '+row.location:'');const p=document.createElement('p');p.append(link);box.append(p);});
    }catch(error){if(error.name!=='AbortError'&&current===revision){box.hidden=false;box.textContent='جست‌وجوی نام‌های مشابه انجام نشد؛ ثبت پرونده همچنان امکان‌پذیر است.';}}
   },300);
  };first.addEventListener('input',refresh);last.addEventListener('input',refresh);refresh();
 });
})();
