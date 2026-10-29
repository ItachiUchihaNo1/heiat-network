(() => {
'use strict';
const trigger=document.querySelector('[data-mobile-menu]'),dialog=document.querySelector('[data-mobile-drawer]');
if(!trigger||!dialog||typeof dialog.showModal!=='function')return;
trigger.hidden=false;
const media=matchMedia('(max-width:760px)'),closeButton=dialog.querySelector('[data-mobile-close]');
let locked=null,closing=false,timer=0;
function unlock(){
 if(!locked)return;
 const saved=locked;locked=null;
 document.body.style.overflow=saved.overflow;document.body.style.position=saved.position;
 document.body.style.top=saved.top;document.body.style.width=saved.width;
 document.documentElement.style.overflow=saved.rootOverflow;
 window.scrollTo(0,saved.y);
}
function finishClose(){
 clearTimeout(timer);if(dialog.open)dialog.close();dialog.classList.remove('is-closing');
 closing=false;trigger.setAttribute('aria-expanded','false');unlock();
 if(media.matches)trigger.focus({preventScroll:true});
}
function close(immediate=false){
 if(!dialog.open||closing)return;
 if(immediate||matchMedia('(prefers-reduced-motion:reduce)').matches){finishClose();return;}
 closing=true;dialog.classList.add('is-closing');timer=setTimeout(finishClose,170);
}
function markCategory(){
 const selected=location.hash==='#gallery-filters';
 if(new URL(location.href).searchParams.get('page')!=='gallery')return;
 for(const link of dialog.querySelectorAll('[data-mobile-page="gallery"]')){
  const active=Boolean(link.dataset.mobileFragment)===selected;
  if(active)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');
 }
}
function open(){
 if(!media.matches||dialog.open)return;
 markCategory();
 locked={y:window.scrollY,overflow:document.body.style.overflow,position:document.body.style.position,top:document.body.style.top,width:document.body.style.width,rootOverflow:document.documentElement.style.overflow};
 document.body.style.overflow='hidden';document.body.style.position='fixed';document.body.style.top=-locked.y+'px';document.body.style.width='100%';document.documentElement.style.overflow='hidden';
 try{dialog.showModal();dialog.querySelector('.nm-drawer-scroll').scrollTop=0;trigger.setAttribute('aria-expanded','true');closeButton.focus({preventScroll:true});}catch(error){unlock();throw error;}
}
trigger.addEventListener('click',open);closeButton.addEventListener('click',()=>close());
dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
dialog.addEventListener('close',()=>{if(locked)finishClose();});
dialog.addEventListener('click',event=>{
 if(event.target===dialog){const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)close();}
 const link=event.target.closest('a[href]');
 if(link&&!event.defaultPrevented&&event.button===0&&!event.ctrlKey&&!event.metaKey&&!event.shiftKey&&!event.altKey)close(true);
});
dialog.addEventListener('keydown',event=>{
 if(event.key!=='Tab')return;
 const options=[...dialog.querySelectorAll('a[href],button:not([disabled]),input:not([type=hidden])')].filter(el=>el.getClientRects().length);
 if(!options.length)return;
 const first=options[0],last=options[options.length-1];
 if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
 else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
});
media.addEventListener('change',()=>{if(!media.matches&&dialog.open)close(true);});
window.addEventListener('pagehide',()=>{if(dialog.open)close(true);});
})();
