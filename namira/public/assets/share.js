(() => {'use strict';
for(const box of document.querySelectorAll('[data-share-tools]')){
 if(box.dataset.shareReady)continue;box.dataset.shareReady='1';const status=box.querySelector('[data-share-status]'),url=box.dataset.shareUrl;
 async function copy(){status.textContent='';try{if(navigator.clipboard&&isSecureContext)await navigator.clipboard.writeText(url);else{const input=document.createElement('textarea'),focused=document.activeElement;input.value=url;input.setAttribute('aria-label','لینک صفحه');input.style.cssText='position:fixed;left:-9999px;top:0';document.body.append(input);input.select();const done=document.execCommand('copy');input.remove();focused?.focus({preventScroll:true});if(!done)throw Error('copy');}status.textContent='لینک کپی شد.';}catch{status.textContent='کپی خودکار ممکن نشد؛ آدرس صفحه را از مرورگر کپی کنید.';}}
 box.querySelector('[data-share-copy]').addEventListener('click',copy);
 box.querySelector('[data-share-native]').addEventListener('click',async()=>{if(!navigator.share)return copy();try{await navigator.share({title:box.dataset.shareTitle,url});}catch(e){if(e.name!=='AbortError')await copy();}});
}
})();