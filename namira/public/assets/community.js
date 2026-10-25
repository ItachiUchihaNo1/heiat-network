(()=>{
'use strict';
const lookupState=new WeakMap(),formState=new WeakMap();
document.addEventListener('input',event=>{
 const input=event.target;if(!input.matches('[data-public-lookup-input]'))return;const group=input.closest('[data-public-lookup]'),results=group.querySelector('[data-public-results]'),old=lookupState.get(input);if(old){clearTimeout(old.timer);old.controller.abort();}
 const query=input.value.trim(),controller=new AbortController();results.replaceChildren();if(query.length<2)return;
 const timer=setTimeout(async()=>{try{const url=new URL('/index.php',location.origin);url.searchParams.set('page','public-lookup');url.searchParams.set('kind',group.dataset.publicLookup);url.searchParams.set('q',query);const response=await fetch(url,{credentials:'same-origin',signal:controller.signal});if(!response.ok)throw new Error('جست‌وجو در دسترس نیست.');const rows=await response.json();if(input.value.trim()!==query)return;if(!rows.length)results.textContent='نتیجه‌ای پیدا نشد؛ در صورت امکان مشخصات پیشنهادی را بنویسید.';
 rows.forEach(row=>{const button=document.createElement('button');button.type='button';button.textContent=row.label;button.addEventListener('click',()=>{const select=group.querySelector('[data-public-target]');if(![...select.options].some(o=>o.value===String(row.id)))select.add(new Option(row.label,row.id));select.value=String(row.id);input.value='';results.replaceChildren();});results.append(button);});
 }catch(error){if(error.name!=='AbortError')results.textContent=error.message;}},300);lookupState.set(input,{timer,controller});
});
document.querySelectorAll('form[data-verify-assets]').forEach(form=>{
 form.addEventListener('submit',async event=>{
   let state=formState.get(form);if(state?.ready)return;if(state?.busy){event.preventDefault();return;}
   let assets;try{assets=JSON.parse(form.dataset.verifyAssets);}catch{event.preventDefault();return;}assets=assets.filter(asset=>!asset.selection_name||[...form.elements].some(el=>el.name===asset.selection_name&&el.checked&&el.value===String(asset.selection_value)));
   if(!assets.length)return;event.preventDefault();state={busy:true,ready:false};formState.set(form,state);
   const box=form.querySelector('[data-upload-status]'),message=box.querySelector('[data-upload-message]'),meter=box.querySelector('[data-upload-progress]'),totals=box.querySelector('[data-upload-bytes]');box.hidden=false;box.dataset.state='processing';meter.removeAttribute('value');totals.textContent='اصل فایل روی سرور حفظ می‌شود؛ فقط پیش‌نمایش تأییدشده ارسال خواهد شد.';
   const controls=[...form.elements].map(el=>[el,el.disabled]);controls.forEach(([el])=>el.disabled=true);
   try{
     if(assets.length>5)throw new Error('حداکثر ۵ فایل را برای این انتقال انتخاب کنید.');
     const generated=[];
     for(let n=0;n<assets.length;n++){
       const asset=assets[n],url=new URL(asset.url,location.origin);if(url.origin!==location.origin||url.searchParams.get('page')!=='community-file'||url.searchParams.get('id')!==String(asset.id))throw new Error('نشانی اصل فایل معتبر نیست.');
       message.textContent='خواندن اصل و ساخت پیش‌نمایش تأییدشده: '+new Intl.NumberFormat('fa-IR').format(n+1)+' از '+new Intl.NumberFormat('fa-IR').format(assets.length);
       const response=await fetch(url,{credentials:'same-origin',cache:'no-store',redirect:'error'});if(!response.ok)throw new Error('اصل فایل برای این حساب در دسترس نیست.');const blob=await response.blob();if(blob.size>20971520)throw new Error('اصل فایل از سقف مجاز پردازش مرورگر بزرگ‌تر است.');
       const ext=({'image/jpeg':'jpg','image/png':'png','image/webp':'webp'})[blob.type];if(!ext)throw new Error('نوع واقعی اصل فایل پشتیبانی نمی‌شود.');
       const source=new File([blob],'source-'+asset.id+'.'+ext,{type:blob.type});const previews=await window.NamiraImagePreviews([source],()=>{});generated.push([asset.id,new File([previews[0]],'verified-'+asset.id+'.webp',{type:'image/webp'})]);
     }
     form.querySelectorAll('.community-verify-file').forEach(el=>el.remove());
     generated.forEach(([id,file])=>{const input=document.createElement('input');input.type='file';input.name='verified_previews['+id+']';input.className='community-verify-file';input.hidden=true;const transfer=new DataTransfer();transfer.items.add(file);input.files=transfer.files;form.append(input);});
     controls.forEach(([el,disabled])=>el.disabled=disabled);state.busy=false;state.ready=true;
     if(form.dataset.reviewBeforePublish==='true'){
       let review=form.querySelector('[data-final-preview]');if(!review){review=document.createElement('section');review.dataset.finalPreview='true';form.insertBefore(review,box);}
       review.replaceChildren();const heading=document.createElement('h3');heading.textContent='پیش‌نمایش نهایی ساخته‌شده از اصل فایل';review.append(heading);const grid=document.createElement('div');grid.className='incoming-grid';
       generated.forEach(([id,file])=>{const img=document.createElement('img');img.className='verified-final-preview';img.alt='پیش‌نمایش واقعی اصل اثر؛ برای تصمیم انتشار';const objectURL=URL.createObjectURL(file);img.onload=()=>URL.revokeObjectURL(objectURL);img.src=objectURL;grid.append(img);});review.append(grid);
       const ack=form.querySelector('[name=publication_ack]');ack.checked=false;ack.required=true;const confirmation=document.createElement('input');confirmation.type='hidden';confirmation.name='verified_visual_ack';confirmation.value='1';form.append(confirmation);
       const button=form.querySelector('button[type=submit],button:not([type])');button.textContent='انتشار و اتصال به آرشیو';meter.value=100;box.dataset.state='review';message.textContent='اصل فایل بازخوانی شد؛ پیش‌نمایش نهایی را بررسی و سپس انتشار را تأیید کنید.';totals.textContent='هنوز منتشر نشده است؛ برای انتشار، تأیید و دکمه را دوباره انتخاب کنید.';review.scrollIntoView({behavior:'smooth',block:'center'});
     }else form.requestSubmit();
   }catch(error){controls.forEach(([el,disabled])=>el.disabled=disabled);state.busy=false;box.dataset.state='error';message.textContent=error.message||'ساخت پیش‌نمایش تأییدشده انجام نشد.';totals.textContent='انتشار انجام نشد. اصل فایل و دسترسی حساب را بررسی کنید.';meter.value=0;}
 });
});
document.querySelectorAll('[data-account-avatar]').forEach(input=>{let ready=false,busy=false;const form=input.form;form.addEventListener('submit',async event=>{if(ready||!input.files.length)return;event.preventDefault();if(busy)return;busy=true;const hint=form.querySelector('[data-avatar-hint]');try{if(hint)hint.textContent='در حال بهینه‌سازی تصویر پروفایل…';const blobs=await window.NamiraImagePreviews([input.files[0]],()=>{}),transfer=new DataTransfer();transfer.items.add(new File([blobs[0]],'avatar.webp',{type:'image/webp'}));input.files=transfer.files;ready=true;busy=false;form.requestSubmit();}catch(error){busy=false;if(hint)hint.textContent=error.message;}});});
})();
