'use client';
import { useState } from 'react';

export default function SessionActions({sessionId,canComplete}:{sessionId:string;canComplete:boolean}){
  const [msg,setMsg]=useState('');
  async function complete(){const r=await fetch('/api/sessions/'+sessionId+'/complete',{method:'POST'});const d=await r.json();setMsg(r.ok?'جلسه به‌عنوان انجام‌شده ثبت شد.':d.error||'خطا');if(r.ok)location.reload();}
  return <>{canComplete&&<button className="btn btn-outline block" onClick={complete}>ثبت پایان جلسه</button>}{msg&&<div className="success" style={{marginTop:8}}>{msg}</div>}</>
}
