(() => {
'use strict';
const config=document.querySelector('meta[name="namira-eitaa"]'),app=window.Eitaa?.WebApp;
if(!config)return;
const entry=location.pathname==='/eitaa'||new URL(location.href).searchParams.get('page')==='eitaa';
let remembered=false;try{remembered=sessionStorage.getItem('namira.eitaa.context')==='1';}catch{}
const raw=typeof app?.initData==='string'?app.initData:'';
if(!raw&&!remembered){if(entry)message('برای ورود از ایتا، نامیرا را از دکمهٔ برنامک باز کنید.');return;}
if(!app){if(entry)message('اتصال ایتا بارگذاری نشد؛ برنامک را دوباره باز کنید.');return;}
document.body.classList.add('is-eitaa');
let pending=false;
function message(text,button,action,error=false){let box=document.querySelector('.eitaa-status');if(!box){box=document.createElement('section');box.className='eitaa-status';box.setAttribute('aria-live','polite');document.querySelector('main')?.prepend(box);}box.hidden=false;box.classList.toggle('is-error',error);box.replaceChildren();const p=document.createElement('p');p.textContent=text;box.append(p);if(button){const b=document.createElement('button');b.type='button';b.textContent=button;b.addEventListener('click',action);box.append(b);}return box;}
async function post(action,data={}){const body=new URLSearchParams({action,csrf:config.dataset.csrf,...data}),controller=new AbortController(),timer=setTimeout(()=>controller.abort(),18000);try{const response=await fetch('/index.php?page=eitaa-api',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body,signal:controller.signal});const result=await response.json();if(!response.ok||result.ok!==true){const error=new Error(result.message||'اتصال ایتا برقرار نشد.');error.status=response.status;throw error;}if(result.csrf)config.dataset.csrf=result.csrf;return result;}finally{clearTimeout(timer);}}
async function welcome(){if(config.dataset.welcome!=='pending')return;try{const r=await post('eitaa-welcome');config.dataset.welcome=r.state;}catch{/* Sending uncertainty never retries automatically. */}}
function permission(){if(config.dataset.welcome!=='pending')return;if(typeof app.requestWriteAccess!=='function')return;message('برای دریافت پیام خوش‌آمدگویی و اطلاع‌رسانی نامیرا، اجازهٔ ارسال پیام را بدهید.','اجازهٔ پیام در ایتا',()=>app.requestWriteAccess(async allowed=>{const box=document.querySelector('.eitaa-status');if(allowed){await welcome();if(box)box.hidden=true;}else message('اجازهٔ ارسال پیام داده نشد؛ استفاده از نامیرا همچنان ممکن است.');}));}
async function authenticate(link=false){if(pending)return;pending=true;message('در حال اتصال امن به حساب نامیرا…');try{const r=await post('eitaa-auth',{init_data:raw,link:link?'1':'0'});try{sessionStorage.setItem('namira.eitaa.context','1');}catch{}if(r.allows_write)await welcome();location.reload();}catch(error){if(error.status===409&&config.dataset.user==='1'&&!link)message(error.message,'تأیید اتصال به حساب فعلی',()=>authenticate(true),true);else if(error.status===419)message('مرورگر اجازهٔ نگهداری نشست را نداده یا نشست منقضی شده است؛ برنامک را دوباره باز کنید.',null,null,true);else message(error.name==='AbortError'?'پاسخ اتصال دریافت نشد؛ اتصال اینترنت را بررسی کنید.':error.message,'تلاش مجدد',()=>authenticate(link),true);}finally{pending=false;}}
function back(){if(location.pathname==='/'||location.pathname==='/eitaa'){app.BackButton?.hide();return;}app.BackButton?.show();app.BackButton?.onClick(()=>{if(history.length>1)history.back();else location.assign('/eitaa');});}
back();app.ready?.();
const inset=()=>document.body.style.setProperty('--eitaa-safe-top',Math.max(0,Number(app.contentSafeAreaInset?.top)||0)+'px');inset();app.onEvent?.('contentSafeAreaChanged',inset);
document.addEventListener('click',event=>{const a=event.target.closest('a[href]');if(!a||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.altKey||event.shiftKey)return;const u=new URL(a.href,location.href);if(u.protocol==='https:'&&u.origin!==location.origin&&typeof app.openLink==='function'){event.preventDefault();if(u.hostname==='eitaa.com'&&typeof app.openEitaaLink==='function')app.openEitaaLink(u.href);else app.openLink(u.href);}});
if(config.dataset.authenticated==='1'){try{sessionStorage.setItem('namira.eitaa.context','1');}catch{}permission();}
else if(raw)authenticate();
else message('نشست ایتا پایان یافته؛ برنامک را ببندید و دوباره باز کنید.');
})();
