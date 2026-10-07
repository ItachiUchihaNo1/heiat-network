'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';

export default function OnboardingForm() {
  const router = useRouter();
  const [loading,setLoading]=useState(false); const [error,setError]=useState('');
  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault(); setLoading(true); setError('');
    const fd = new FormData(e.currentTarget);
    const body = Object.fromEntries(fd.entries());
    const res = await fetch('/api/profile',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body)});
    const data=await res.json(); setLoading(false);
    if(!res.ok) return setError(data.error||'ذخیره اطلاعات ناموفق بود.');
    router.push('/'); router.refresh();
  }
  return <form onSubmit={submit} className="panel">
    <div className="field"><label>نام و نام خانوادگی</label><input name="name" required minLength={3}/></div>
    <div className="form-row"><div className="field"><label>شهر</label><input name="city" required/></div><div className="field"><label>نقش در هیأت</label><input name="roleInOrg" placeholder="مدیر، خادم، مسئول رسانه..." required/></div></div>
    <div className="field"><label>نام هیأت (اختیاری)</label><input name="organizationName"/></div>
    {error&&<div className="error">{error}</div>}
    <button className="btn btn-solid block" disabled={loading}>{loading?'در حال ذخیره...':'تکمیل عضویت'}</button>
  </form>;
}
