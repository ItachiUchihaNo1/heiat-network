(()=>{'use strict';
const nav=document.querySelector('.nm-bottom-nav .nm-tab-surface');if(!nav||!window.requestAnimationFrame)return;
const inner=nav.querySelector('.nm-tab-inner'),links=[...inner.querySelectorAll('a')],svg=nav.querySelector('.nm-tab-socket'),path=svg.querySelector('path'),bead=nav.querySelector('.nm-tab-bead');
if(links.length!==5)return;
const motion=window.matchMedia('(prefers-reduced-motion: reduce)'),serverActive=links.findIndex(a=>a.getAttribute('aria-current')==='page');let active=serverActive,width=0,centers=[],x=0,target=0,velocity=0,frame=0,last=0,navigating=false,drag=null,suppressClick=0,timer=0;
function remember(i){try{sessionStorage.setItem('namira.nav.visual-tab',String(i));}catch{}}
function select(i){active=i;links.forEach((a,j)=>{if(j===i)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');});bead.textContent='';bead.hidden=i<0;const icon=links[i]?.querySelector('.nm-bottom-icon svg');if(icon)bead.appendChild(icon.cloneNode(true));}
function paint(){if(!width)return;if(active<0){svg.setAttribute('viewBox',`0 0 ${width} 98`);path.setAttribute('d',`M 12 24 H ${width-12} Q ${width} 24 ${width} 36 V 86 Q ${width} 98 ${width-12} 98 H 12 Q 0 98 0 86 V 36 Q 0 24 12 24 Z`);return;}const c=Math.max(Math.min(...centers),Math.min(Math.max(...centers),x)),R=28,r=7,top=24,cy=24,d=7,dx=Math.sqrt((R+r)**2-d*d),jx=dx*R/(R+r),jy=cy+d*R/(R+r),b=98,k=12;svg.setAttribute('viewBox',`0 0 ${width} 98`);path.setAttribute('d',`M ${k} ${top} H ${c-dx} A ${r} ${r} 0 0 1 ${c-jx} ${jy} A ${R} ${R} 0 0 0 ${c+jx} ${jy} A ${r} ${r} 0 0 1 ${c+dx} ${top} H ${width-k} Q ${width} ${top} ${width} ${top+k} V ${b-k} Q ${width} ${b} ${width-k} ${b} H ${k} Q 0 ${b} 0 ${b-k} V ${top+k} Q 0 ${top} ${k} ${top} Z`);bead.style.transform=`translate3d(${c-23}px,0,0)`;}
function step(t){const dt=Math.min((t-(last||t-16))/1000,.032);last=t;velocity+=((target-x)*180-velocity*23)*dt;x+=velocity*dt;paint();if(Math.abs(target-x)>.08||Math.abs(velocity)>.5){frame=requestAnimationFrame(step);}else{x=target;velocity=0;frame=0;last=0;paint();}}
function move(value,instant=false){target=value;if(instant||motion.matches){cancelAnimationFrame(frame);frame=0;last=0;x=target;velocity=0;paint();}else if(!frame){last=0;frame=requestAnimationFrame(step);}}
function measure(){const rect=nav.getBoundingClientRect();width=rect.width;centers=links.map(a=>{const b=a.getBoundingClientRect();return b.left-rect.left+b.width/2;});move(centers[active]??width/2,true);}
function navigate(i){if(navigating)return;select(i);move(centers[i]);remember(i);if(links[i].href===location.href)return;navigating=true;timer=setTimeout(()=>location.assign(links[i].href),motion.matches?0:90);}
nav.classList.add('nm-tabs-enhanced');select(active);measure();bead.hidden=active<0;
try{const previous=sessionStorage.getItem('namira.nav.visual-tab');if(active>=0&&previous!==null&&/^[0-4]$/.test(previous)&&Number(previous)!==active&&!motion.matches){x=centers[Number(previous)];paint();move(centers[active]??width/2);}}catch{}remember(active);
links.forEach((link,i)=>link.addEventListener('click',event=>{if(event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||!navigator.onLine||new URL(link.href).origin!==location.origin)return;event.preventDefault();if(Date.now()<suppressClick)return;navigate(i);}));
nav.addEventListener('pointerdown',event=>{if(navigating||event.button!==0||!matchMedia('(max-width:760px)').matches)return;drag={id:event.pointerId,start:event.clientX,startY:event.clientY,moved:false};});
nav.addEventListener('pointermove',event=>{if(!drag||drag.id!==event.pointerId)return;if(!drag.moved&&Math.abs(event.clientY-drag.startY)>Math.abs(event.clientX-drag.start)){drag=null;return;}if(!drag.moved&&Math.abs(event.clientX-drag.start)<8)return;drag.moved=true;nav.setPointerCapture(event.pointerId);const rect=nav.getBoundingClientRect();move(Math.max(Math.min(...centers),Math.min(Math.max(...centers),event.clientX-rect.left)));});
nav.addEventListener('pointerup',event=>{if(!drag||drag.id!==event.pointerId)return;const moved=drag.moved;drag=null;if(nav.hasPointerCapture(event.pointerId))nav.releasePointerCapture(event.pointerId);if(moved){suppressClick=Date.now()+500;const i=centers.reduce((best,c,index)=>Math.abs(c-target)<Math.abs(centers[best]-target)?index:best,0);navigate(i);}});
nav.addEventListener('dragstart',event=>event.preventDefault());
nav.addEventListener('pointercancel',()=>{drag=null;move(centers[active]??width/2);});
if(window.ResizeObserver)new ResizeObserver(measure).observe(nav);else addEventListener('resize',measure);
addEventListener('pageshow',event=>{if(event.persisted){clearTimeout(timer);navigating=false;drag=null;select(serverActive);measure();remember(serverActive);}});
})();

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
 trigger.focus({preventScroll:true});
}
function close(immediate=false){
 if(!dialog.open||closing)return;
 if(immediate||matchMedia('(prefers-reduced-motion:reduce)').matches){finishClose();return;}
 closing=true;dialog.classList.add('is-closing');timer=setTimeout(finishClose,170);
}
function markCategory(){
 const selected=location.hash==='#gallery-filters';
 if((new URL(location.href).searchParams.get('page')||({'/images':'gallery'}[location.pathname]))!=='gallery')return;
 for(const link of dialog.querySelectorAll('[data-mobile-page="gallery"]')){
  const active=Boolean(link.dataset.mobileFragment)===selected;
  if(active)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');
 }
}
function open(){
 if(dialog.open)return;
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
media.addEventListener('change',()=>{if(dialog.open)close(true);});
window.addEventListener('pagehide',()=>{if(dialog.open)close(true);});
})();
