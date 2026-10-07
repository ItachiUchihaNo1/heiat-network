'use client';
import { useState } from 'react';

export default function ModerationActions({ticketId}:{ticketId:string}){
  const [note,setNote]=useState(''); const [msg,setMsg]=useState(''); const [loading,setLoading]=useState(false);
  async function act(action:'APPROVE'|'NEEDS_INFO'|'REJECT'){setLoading(true);setMsg('');const r=await fetch('/api/admin/moderation',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({ticketId,action,note})});const d=await r.json();setLoading(false);setMsg(r.ok?'عملیات انجام شد.':d.error||'خطا');if(r.ok)setTimeout(()=>location.reload(),500)}
  return <div><div className="field"><label>یادداشت ناظر</label><textarea value={note} onChange={e=>setNote(e.target.value)} placeholder="در صورت نیاز برای کاربر توضیح بنویسید."/></div><div className="expert-actions"><button disabled={loading} className="btn btn-solid" onClick={()=>act('APPROVE')}>تأیید و Matching</button><button disabled={loading} className="btn btn-outline" onClick={()=>act('NEEDS_INFO')}>نیاز به تکمیل</button><button disabled={loading} className="btn btn-danger" onClick={()=>act('REJECT')}>رد</button></div>{msg&&<div className={msg.includes('انجام شد')?'success':'error'} style={{marginTop:8}}>{msg}</div>}</div>
}
