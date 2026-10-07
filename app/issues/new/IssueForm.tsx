'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';

export default function IssueForm({city,categories}:{city?:string|null;categories:string[]}){
  const router=useRouter(); const [loading,setLoading]=useState(false); const [error,setError]=useState('');
  async function submit(e:React.FormEvent<HTMLFormElement>){
    e.preventDefault(); setLoading(true); setError('');
    const body=Object.fromEntries(new FormData(e.currentTarget).entries());
    const res=await fetch('/api/issues',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body)});
    const data=await res.json(); setLoading(false);
    if(!res.ok)return setError(data.error||'ثبت مسئله ناموفق بود.');
    router.push('/issues?created=1'); router.refresh();
  }
  return <form className="panel" onSubmit={submit}>
    <div className="steps"><span className="step on"/><span className="step on"/><span className="step on"/></div>
    <div className="field"><label>حوزه مسئله</label><select name="domain" required defaultValue=""><option value="" disabled>انتخاب کنید</option>{categories.map(d=><option key={d}>{d}</option>)}</select></div>
    <div className="field"><label>عنوان کوتاه مسئله</label><input name="title" placeholder="مثلاً جذب و نگهداشت نوجوان در هیأت" required minLength={5} maxLength={120}/></div>
    <div className="field"><label>شرح مسئله</label><textarea name="description" placeholder="مسئله دقیقاً چیست؟ چه چیزی مانع شده؟ تا امروز چه کارهایی انجام داده‌اید؟" required minLength={20}/><div className="help">هرچه صورت مسئله دقیق‌تر باشد، تطبیق با صاحب تجربه بهتر انجام می‌شود.</div></div>
    <div className="field"><label>وضعیت فعلی</label><textarea name="currentStatusText" placeholder="مثلاً ۳۰ نوجوان داریم اما حضورشان منظم نیست."/></div>
    <div className="field"><label>از صاحب تجربه چه کمکی می‌خواهید؟</label><input name="experienceNeeded" placeholder="مثلاً طراحی برنامه ۳ ماهه برای تثبیت تیم نوجوان"/></div>
    <div className="field"><label>شهر</label><input name="city" defaultValue={city||''} required/></div>
    <div className="ai-box">بعد از ثبت، درخواست وارد صف ناظر شبکه می‌شود. ناظر می‌تواند برای دقیق‌تر شدن صورت مسئله سؤال تکمیلی بپرسد؛ سپس صاحبان تجربه مناسب پیشنهاد می‌شوند.</div>
    {error&&<div className="error" style={{marginTop:10}}>{error}</div>}
    <button className="btn btn-solid block" style={{marginTop:12}} disabled={loading}>{loading?'در حال ثبت...':'ثبت و ارسال برای بررسی'}</button>
  </form>
}
