(() => {
  'use strict';
  const fa = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 1 });
  const bytes = n => n < 1024 ? `${fa.format(n)} بایت` : n < 1048576 ? `${fa.format(n / 1024)} کیلوبایت` : `${fa.format(n / 1048576)} مگابایت`;
  document.querySelectorAll('form[data-upload-form]').forEach(form => {
    const box = form.querySelector('[data-upload-status]');
    const meter = box.querySelector('[data-upload-progress]');
    const message = box.querySelector('[data-upload-message]');
    const totals = box.querySelector('[data-upload-bytes]');
    let busy = false;
    form.addEventListener('submit', event => {
      if (busy) { event.preventDefault(); return; }
      if (!window.XMLHttpRequest || !window.FormData) return;
      const files = Array.from(form.querySelectorAll('input[type="file"]')).flatMap(input => Array.from(input.files));
      if (!files.length) return; // A request without photos retains the standard form flow.
      event.preventDefault();
      box.hidden = false;
      const limit = Number(form.dataset.maxFiles || 10);
      const invalid = files.find(file => !/\.(jpe?g|png|webp)$/i.test(file.name));
      if (invalid || files.length > limit) {
        meter.value = 0;
        message.textContent = invalid ? 'فرمت مجاز فایل‌ها: JPEG، JPG، PNG و WebP.' : `در هر نوبت حداکثر ${fa.format(limit)} فایل انتخاب کنید.`;
        totals.textContent = '';
        box.dataset.state = 'error';
        return;
      }
      const data = new FormData(form); // Capture fields before disabling controls.
      const controls = Array.from(form.elements).map(control => [control, control.disabled]);
      const restore = () => { busy = false; form.removeAttribute('aria-busy'); controls.forEach(([control, disabled]) => { control.disabled = disabled; }); };
      const fail = text => { restore(); box.dataset.state = 'error'; message.textContent = text; };
      const pending = 'پاسخ سرور دریافت نشد. پیش از ارسال مجدد، نتیجه ثبت را بررسی کنید.';
      busy = true;
      form.setAttribute('aria-busy', 'true');
      controls.forEach(([control]) => { control.disabled = true; });
      box.dataset.state = 'uploading';
      meter.value = 0;
      const selectedBytes = files.reduce((sum, file) => sum + file.size, 0);
      message.textContent = 'در حال شروع ارسال…';
      totals.textContent = `حجم فایل‌های انتخاب‌شده: ${bytes(selectedBytes)}`;
      const processing = () => {
        meter.value = 100;
        box.dataset.state = 'processing';
        message.textContent = 'ارسال به ۱۰۰٪ رسید؛ سرور در حال بررسی، ذخیره و ساخت پیش‌نمایش‌هاست…';
        totals.textContent = 'ارسال کامل شد؛ تا دریافت گزارش سرور این صفحه را باز نگه دارید.';
      };
      const transmit = () => {
      box.dataset.state='uploading';meter.value=0;message.textContent='در حال شروع ارسال…';
      const xhr = new XMLHttpRequest();
      xhr.upload.addEventListener('progress', event => {
        if (event.lengthComputable && event.total > 0) {
          const sent = Math.min(event.loaded, event.total);
          meter.value = Math.floor(sent / event.total * 100);
          message.textContent = `در حال ارسال: ${fa.format(meter.value)}٪`;
          totals.textContent = `ارسال‌شده: ${bytes(sent)} از ${bytes(event.total)} · باقی‌مانده: ${bytes(Math.max(0, event.total - sent))}`;
        } else {
          meter.removeAttribute('value');
          message.textContent = 'در حال ارسال؛ حجم کل انتقال از مرورگر دریافت نشده است.';
          totals.textContent = `ارسال‌شده: ${bytes(event.loaded)} · باقی‌مانده: نامشخص`;
        }
      });
      xhr.upload.addEventListener('load', processing);
      xhr.upload.addEventListener('error', () => fail(pending));
      xhr.upload.addEventListener('timeout', () => fail(pending));
      xhr.addEventListener('load', () => {
        let result;
        try { result = JSON.parse(xhr.responseText); } catch { fail(`پاسخ معتبر دریافت نشد (وضعیت ${fa.format(xhr.status)}). ${pending}`); return; }
        if (xhr.status >= 200 && xhr.status < 300 && result.ok === true && typeof result.redirect === 'string') {
          const target = new URL(result.redirect, location.href);
          if (target.origin !== location.origin) { fail('مسیر پاسخ سرور معتبر نیست.'); return; }
          box.dataset.state = 'done';
          message.textContent = 'پاسخ سرور دریافت شد؛ در حال نمایش گزارش…';
          location.assign(target.href);
        } else fail(result.message || 'بارگذاری کامل نشد؛ پاسخ سرور را بررسی کنید.');
      });
      xhr.addEventListener('error', () => fail(pending));
      xhr.addEventListener('timeout', () => fail(pending));
      xhr.addEventListener('abort', () => fail('ارسال متوقف شد. پیش از تکرار، نتیجه ثبت را بررسی کنید.'));
      try {
        const endpoint = new URL(form.getAttribute('action') || location.href, location.href);
        if (endpoint.origin !== location.origin) throw new Error('Invalid upload endpoint');
        xhr.open('POST', endpoint.href);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.timeout = 600000;
        xhr.send(data);
      } catch { fail(pending); }
      };
      if(form.dataset.uploadPreview==='true'){
        if(typeof window.NamiraImagePreviews!=='function'){fail('ابزار پیش‌نمایش بارگیری نشده است؛ صفحه را با Ctrl+F5 تازه کنید.');return;}
        box.dataset.state='preparing';meter.removeAttribute('value');
        window.NamiraImagePreviews(files,(done,total)=>{
          message.textContent='در حال آماده‌سازی پیش‌نمایش سبک: '+fa.format(done)+' از '+fa.format(total)+' فایل؛ اصل فایل تغییر نمی‌کند.';
          totals.textContent='پس از آماده‌سازی، درصد ارسال و حجم باقی‌مانده نمایش داده می‌شود.';
        }).then(previews=>{
          if(previews.length!==files.length)throw new Error('تعداد پیش‌نمایش‌ها کامل نیست.');
          previews.forEach((blob,index)=>data.append('previews['+index+']',blob,'preview-'+index+(blob.type==='image/png'?'.png':'.webp')));
          transmit();
        }).catch(error=>{totals.textContent='هنوز فایلی به سرور ارسال نشده است.';fail(error.message||'ساخت پیش‌نمایش انجام نشد؛ فایل را بررسی کنید.');});
      }else transmit();
    });
  });
})();
