(() => {
  'use strict';
  const fit = (width,height) => { const ratio=Math.min(1,1600/Math.max(width,height));return [Math.max(1,Math.round(width*ratio)),Math.max(1,Math.round(height*ratio))]; };
  async function preview(file) {
    let bitmap=null,image=null,objectURL=null;
    try {
      if(typeof window.createImageBitmap==='function') {
        try { bitmap=await window.createImageBitmap(file); } catch { /* Some browser decoders need the image element fallback. */ }
      }
      if(!bitmap) {
        objectURL=URL.createObjectURL(file);image=new Image();
        await new Promise((resolve,reject)=>{image.onload=resolve;image.onerror=()=>reject(new Error('تصویر قابل خواندن نیست یا فرمت واقعی آن با پسوند یکسان نیست.'));image.src=objectURL;});
      }
      const source=bitmap||image,width=bitmap?bitmap.width:image.naturalWidth,height=bitmap?bitmap.height:image.naturalHeight;
      if(!width||!height||width>40000||height>40000||width*height>200000000)throw new Error('ابعاد فایل از حد ایمنی ۲۰۰ مگاپیکسل یا ضلع ۴۰۰۰۰ پیکسل بیشتر است.');
      const canvas=document.createElement('canvas');[canvas.width,canvas.height]=fit(width,height);
      const context=canvas.getContext('2d');if(!context)throw new Error('مرورگر امکان ساخت پیش‌نمایش ندارد.');
      context.drawImage(source,0,0,canvas.width,canvas.height);
      const blob=await new Promise((resolve,reject)=>canvas.toBlob(value=>value?resolve(value):reject(new Error('ساخت پیش‌نمایش انجام نشد.')),'image/webp',0.84));
      canvas.width=canvas.height=1;
      if(blob.size>8388608)throw new Error('پیش‌نمایش ساخته‌شده بیش از حد حجیم است.');
      return blob;
    } finally {
      if(bitmap)bitmap.close();
      if(image)image.src='';
      if(objectURL)URL.revokeObjectURL(objectURL);
    }
  }
  window.NamiraImagePreviews=async(files,progress)=>{
    const result=[];
    for(let index=0;index<files.length;index++){
      progress(index,files.length);
      try{result.push(await preview(files[index]));}catch(error){throw new Error(files[index].name+' — '+(error.message||'خطای ساخت پیش‌نمایش'));}
      progress(index+1,files.length);
    }
    return result;
  };
})();
