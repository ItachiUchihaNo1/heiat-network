'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';

export default function LoginForm({siteName='شبکه تجربه هیأت',logoUrl,logoText='هـ'}:{siteName?:string;logoUrl?:string|null;logoText?:string}) {
  const router = useRouter();
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [step, setStep] = useState<'phone'|'code'>('phone');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  const [devCode, setDevCode] = useState('');

  async function requestOtp(e: React.FormEvent) {
    e.preventDefault(); setLoading(true); setMessage('');
    const res = await fetch('/api/auth/request-otp', { method: 'POST', headers: {'content-type':'application/json'}, body: JSON.stringify({phone}) });
    const data = await res.json(); setLoading(false);
    if (!res.ok) return setMessage(data.error || 'ارسال کد ناموفق بود.');
    if (data.devCode) setDevCode(data.devCode);
    setStep('code');
    setMessage('کد ورود ارسال شد.');
  }

  async function verifyOtp(e: React.FormEvent) {
    e.preventDefault(); setLoading(true); setMessage('');
    const res = await fetch('/api/auth/verify-otp', { method:'POST', headers:{'content-type':'application/json'}, body: JSON.stringify({phone, code}) });
    const data = await res.json(); setLoading(false);
    if (!res.ok) return setMessage(data.error || 'کد صحیح نیست.');
    router.push(data.needsOnboarding ? '/onboarding' : '/');
    router.refresh();
  }

  return <div className="auth-card">
    <div className="brand" style={{marginBottom:16}}>{logoUrl?<img className="brand-logo-img" src={logoUrl} alt={siteName}/>:<div className="logo">{logoText}</div>}<div><h1>ورود به {siteName}</h1><small>با شماره موبایل</small></div></div>
    {step === 'phone' ? <form onSubmit={requestOtp}>
      <div className="field"><label>شماره موبایل</label><input inputMode="tel" placeholder="09123456789" value={phone} onChange={e=>setPhone(e.target.value)} required /></div>
      <button className="btn btn-solid block" disabled={loading}>{loading?'در حال ارسال...':'دریافت کد ورود'}</button>
    </form> : <form onSubmit={verifyOtp}>
      <div className="field"><label>کد ۶ رقمی</label><input inputMode="numeric" maxLength={6} placeholder="------" value={code} onChange={e=>setCode(e.target.value)} required /></div>
      {devCode && <div className="ai-box">حالت توسعه فعال است؛ کد تست: <b className="code">{devCode}</b></div>}
      <button className="btn btn-solid block" disabled={loading}>{loading?'در حال بررسی...':'ورود'}</button>
      <button type="button" className="btn btn-outline block" style={{marginTop:8}} onClick={()=>{setStep('phone');setCode('');}}>تغییر شماره</button>
    </form>}
    {message && <div className={message.includes('ارسال شد')?'success':'error'} style={{marginTop:10}}>{message}</div>}
    <p className="small muted" style={{marginTop:14}}>با ورود، قوانین حریم خصوصی و استفاده مسئولانه از شبکه را می‌پذیرید. شماره طرفین در جلسات نمایش داده نمی‌شود.</p>
  </div>;
}
