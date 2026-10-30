(()=>{
'use strict';
const host=document.querySelector('[data-home-editor]');if(!host)return;
const init=JSON.parse(document.getElementById('he-initial').textContent),form=document.getElementById('home-editor-form'),list=document.getElementById('he-blocks'),feedback=document.getElementById('he-feedback'),status=document.getElementById('he-save-state'),dialog=document.getElementById('he-preview'),frame=document.getElementById('he-frame');
let version=init.state.version,dirty=false,pending=false;const names=init.labels||{};
const element=(tag,cls,text)=>{const el=document.createElement(tag);if(cls)el.className=cls;if(text!==undefined)el.textContent=text;return el;};
const message=(text,error=false)=>{feedback.textContent=text;feedback.classList.toggle('notice-error',error);feedback.hidden=false;};
const changed=()=>{dirty=true;status.textContent='تغییرات ذخیره‌نشده';};
const field=(label,key,type,value,wide=false)=>{const wrap=element('label',wide?'he-wide':'');wrap.append(document.createTextNode(label));const input=document.createElement(type==='textarea'?'textarea':type==='select'?'select':'input');if(!['select','textarea'].includes(type))input.type=type;input.dataset.field=key;if(type!=='file')input.value=value??'';wrap.append(input);return [wrap,input];};
const option=(select,value,label)=>{const o=element('option','',label);o.value=value;select.append(o);};
function card(b){
 const box=element('details','he-block');box.dataset.id=b.id;box.dataset.type=b.type;box._assets=b.assets||{};box._ids=[...(b.ids||[])];names[b.id]??={};
 const summary=element('summary'),heading=element('span','he-block-title',b.title),type=element('span','he-block-type',init.types[b.type]),ops=element('span','he-operations');summary.append(heading,type,ops);box.append(summary);
 for(const [action,label,symbol]of [['up','جابه‌جایی به بالا','↑'],['down','جابه‌جایی به پایین','↓'],['remove','حذف این بخش','×']]){
  const btn=element('button','',symbol);btn.type='button';btn.setAttribute('aria-label',label);btn.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();if(action==='up'&&box.previousElementSibling)list.insertBefore(box,box.previousElementSibling);else if(action==='down'&&box.nextElementSibling)list.insertBefore(box.nextElementSibling,box);else if(action==='remove'){if(!confirm('این بخش از چیدمان حذف شود؟ اطلاعات اصلی سامانه حذف نمی‌شود.'))return;box.remove();}changed();});ops.append(btn);
 }
 const body=element('div','he-fields'),grid=element('div','he-field-grid');box.append(body);body.append(grid);
 const [titleWrap,title]=field('عنوان بخش','title','text',b.title);title.maxLength=160;title.required=true;title.addEventListener('input',()=>heading.textContent=title.value);grid.append(titleWrap);
 const [activeWrap,active]=field('نمایش این بخش','enabled','select','');option(active,'1','فعال');option(active,'0','غیرفعال');active.value=b.enabled?'1':'0';active.addEventListener('change',()=>box.classList.toggle('he-disabled',active.value==='0'));box.classList.toggle('he-disabled',!b.enabled);grid.append(activeWrap);
 const [descWrap,description]=field('متن کوتاه (اختیاری)','description','textarea',b.description,true);description.maxLength=600;grid.append(descWrap);
 if(['banner','request'].includes(b.type)){
  if(b.type==='banner'){
   const [linkWrap,link]=field('لینک مقصد (داخلی یا https)','link','text',b.link);link.maxLength=1500;grid.append(linkWrap);
   const [buttonWrap,button]=field('متن دکمه','button','text',b.button||'مشاهده بیشتر');button.maxLength=60;grid.append(buttonWrap);
   for(const device of ['desktop','mobile']){
    const [wrap,input]=field(device==='desktop'?'تصویر دسکتاپ':'تصویر موبایل (اختیاری)','upload_'+device,'file');input.accept='image/jpeg,image/png,image/webp';input.name='banner_'+b.id+'_'+device;const assetGroup=element('div');assetGroup.append(wrap);grid.append(assetGroup);
    const current=element('p','he-asset-info',box._assets[device]?'تصویر ذخیره شده است. برای جایگزینی فایل انتخاب کنید.':'بدون تصویر؛ باکس معرفی متنی نمایش داده می‌شود.');assetGroup.append(current);
    if(box._assets[device]){const remove=element('label','he-asset-info');const check=document.createElement('input');check.type='checkbox';check.dataset.field='remove_'+device;remove.append(check,document.createTextNode(' حذف تصویر ذخیره‌شده'));assetGroup.append(remove);}
   }
  }
 }else{
  const [modeWrap,mode]=field('انتخاب محتوا','mode','select','');option(mode,'auto','خودکار · جدیدترین محتوای منتشرشده');option(mode,'manual','انتخاب دستی');mode.value=b.mode||'auto';grid.append(modeWrap);
  const [countWrap,count]=field('تعداد نمایش (۱ تا ۲۴)','count','number',b.count||6);count.min=1;count.max=24;count.required=true;grid.append(countWrap);
  const lookup=element('div','he-lookup');body.append(lookup);const hint=element('p','he-mode-hint','فقط محتوای عمومی و منتشرشده انتخاب می‌شود؛ فراخوان‌ها باید در زمان دریافت آثار باشند.');body.append(hint);
  const chips=element('div','he-selected'),search=document.createElement('input'),options=element('div','he-options');search.type='search';search.placeholder='جست‌وجو در محتوای منتشرشده…';search.setAttribute('aria-label','جست‌وجوی '+init.types[b.type]);lookup.append(chips,search,options);
  let controller,timer;
  function selected(){chips.replaceChildren();for(const id of box._ids){const chip=element('span','he-tag',names[b.id][id]||('مورد '+id+' · فعلاً بدون نمایش عمومی')),del=element('button','','×');del.type='button';del.setAttribute('aria-label','حذف انتخاب '+(names[b.id][id]||id));del.onclick=()=>{box._ids=box._ids.filter(x=>x!==id);selected();for(const check of options.querySelectorAll('input'))check.checked=box._ids.includes(Number(check.value));changed();};chip.append(del);chips.append(chip);}}
  async function load(){controller?.abort();controller=new AbortController();options.textContent='در حال دریافت…';try{const url=new URL(init.lookup,location.href);url.searchParams.set('type',b.type);url.searchParams.set('q',search.value);const response=await fetch(url,{signal:controller.signal,headers:{Accept:'application/json'}});const data=await response.json();if(!response.ok||!data.ok)throw Error(data.message||'دریافت گزینه‌ها انجام نشد.');options.replaceChildren();for(const item of data.items){names[b.id][item.id]=item.label;const label=element('label'),check=document.createElement('input');check.type='checkbox';check.value=item.id;check.checked=box._ids.includes(item.id);check.onchange=()=>{if(check.checked&&!box._ids.includes(item.id)){if(box._ids.length>=24){check.checked=false;message('در هر بخش حداکثر ۲۴ مورد انتخاب کنید.',true);return;}box._ids.push(item.id);}else box._ids=box._ids.filter(x=>x!==item.id);selected();changed();};label.append(check,document.createTextNode(item.label));options.append(label);}if(!data.items.length)options.textContent='محتوای منتشرشده‌ای پیدا نشد.';selected();}catch(error){if(error.name!=='AbortError')options.textContent=error.message;}}
  const setMode=()=>{lookup.hidden=mode.value!=='manual';if(!lookup.hidden)load();};mode.addEventListener('change',setMode);search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(load,300);});selected();lookup.hidden=mode.value!=='manual';box.addEventListener('toggle',()=>{if(box.open&&mode.value==='manual')load();});
 }
 return box;
}
function render(blocks){list.replaceChildren();for(const b of blocks)list.append(card(b));if(!blocks.length)list.append(element('p','he-empty','ویترین خالی است؛ یک بخش اضافه کنید.'));}
function serialize(){return [...list.querySelectorAll('.he-block')].map(box=>{const b={id:box.dataset.id,type:box.dataset.type,ids:box._ids,assets:box._assets,mode:'auto',count:6,link:'',button:'مشاهده بیشتر'};for(const input of box.querySelectorAll('[data-field]')){if(input.type==='file')continue;b[input.dataset.field]=input.type==='checkbox'?input.checked:input.value;}b.enabled=b.enabled==='1';b.count=Number(b.count);return b;});}
form.addEventListener('input',changed);form.addEventListener('change',changed);form.addEventListener('submit',e=>{e.preventDefault();save('draft');});
for(const btn of host.querySelectorAll('[data-add]'))btn.onclick=()=>{if(list.querySelectorAll('.he-block').length>=20){message('حداکثر ۲۰ بخش قابل استفاده است.',true);return;}list.querySelector('.he-empty')?.remove();const b={id:'b'+crypto.randomUUID().replaceAll('-','').slice(0,24),type:btn.dataset.add,title:init.types[btn.dataset.add],description:'',enabled:true,mode:'auto',count:6,ids:[],assets:[]};const el=card(b);list.append(el);el.open=true;el.scrollIntoView({block:'nearest',behavior:'smooth'});changed();};
async function save(intent){if(pending)return false;if(!form.reportValidity())return false;pending=true;for(const button of host.querySelectorAll('[data-save],[data-preview]'))button.disabled=true;const data=new FormData(form);data.set('version',version);data.set('layout',JSON.stringify(serialize()));data.set('intent',intent);status.textContent='در حال ذخیره…';
 try{const response=await fetch(location.href,{method:'POST',body:data,headers:{Accept:'application/json'}});const result=await response.json();if(!response.ok||!result.ok)throw Error(result.message||'ذخیره انجام نشد. ورودی‌ها محفوظ هستند.');version=result.version;dirty=false;render(result.draft);message(result.message);status.textContent=intent==='publish'?'ذخیره و منتشر شد · نسخه '+version:'پیش‌نویس ذخیره شد · نسخه '+version;return true;}catch(error){message(error.message,true);status.textContent='ذخیره نشد؛ ورودی‌ها محفوظ هستند';return false;}finally{pending=false;for(const button of host.querySelectorAll('[data-save],[data-preview]'))button.disabled=false;}}
for(const btn of host.querySelectorAll('[data-save]'))btn.onclick=()=>save(btn.dataset.save);
for(const btn of host.querySelectorAll('[data-preview]'))btn.onclick=async()=>{if(dirty&&!(await save('draft')))return;frame.style.width=btn.dataset.preview+'px';frame.src=init.preview+'&v='+version;dialog.showModal();};
host.querySelector('[data-close-preview]').onclick=()=>dialog.close();dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
render(init.state.draft);
})();