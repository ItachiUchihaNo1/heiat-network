(() => {
 'use strict';
 document.querySelectorAll('[data-share-tools]').forEach(box => {
  if (box.dataset.shareReady) return;
  box.dataset.shareReady = 'true';
  const input = box.querySelector('input'), status = box.querySelector('[data-share-status]');
  const select = () => { input.focus(); input.select(); input.setSelectionRange(0, input.value.length); };
  const copy = async () => {
   status.textContent = '';
   try {
    if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(input.value);
    else { select(); if (!document.execCommand('copy')) throw Error('copy'); }
    status.textContent = 'لینک کپی شد.';
   } catch { select(); status.textContent = 'لینک را انتخاب کرده‌ایم؛ آن را کپی کنید.'; }
  };
  input.addEventListener('click', select);
  box.querySelector('[data-share-copy]').addEventListener('click', copy);
  box.querySelector('[data-share-native]').addEventListener('click', async () => {
   status.textContent = '';
   if (!navigator.share) { await copy(); return; }
   try { await navigator.share({ title: box.dataset.shareTitle, url: input.value }); }
   catch (error) { if (error.name !== 'AbortError') await copy(); }
  });
 });
})();
