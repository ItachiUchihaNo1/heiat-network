'use client';
import { useState } from 'react';
import { useRouter } from 'next/navigation';

export default function BookingForm({expertProfileId,slots,tickets,initialTicketId}:{expertProfileId:string;slots:{id:string;startsAt:string;duration:number}[];tickets:{id:string;title:string;status:string}[];initialTicketId?:string}){
  const router=useRouter(); const [slotId,setSlotId]=useState(slots[0]?.id||''); const [ticketId,setTicketId]=useState(initialTicketId||tickets[0]?.id||''); const [error,setError]=useState(''); const [loading,setLoading]=useState(false);
  async function book(){setLoading(true);setError('');const r=await fetch('/api/bookings',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({expertProfileId,availableSlotId:slotId,ticketId})});const d=await r.json();setLoading(false);if(!r.ok)return setError(d.error||'رزرو ناموفق بود.');router.push('/sessions/'+d.session.id);router.refresh();}
  if(!tickets.length)return <div className="ai-box">برای رزرو، ابتدا یک مسئله باید توسط ناظر تأیید و وارد مرحله «مشاوران پیشنهاد شدند» شود.</div>;
  if(!slots.length)return <div className="ai-box">در حال حاضر زمان آزاد قابل رزرو ثبت نشده است.</div>;
  return <div className="panel"><h3>رزرو جلسه</h3><div className="field"><label>مسئله</label><select value={ticketId} onChange={e=>setTicketId(e.target.value)}>{tickets.map(t=><option key={t.id} value={t.id}>{t.title}</option>)}</select></div><div className="field"><label>زمان آزاد</label><div className="slot-grid">{slots.map(s=><button type="button" key={s.id} className="slot" style={slotId===s.id?{borderColor:'#0f8a80',background:'#eefaf7'}:{}} onClick={()=>setSlotId(s.id)}>{new Intl.DateTimeFormat('fa-IR',{dateStyle:'medium',timeStyle:'short'}).format(new Date(s.startsAt))}<br/>{s.duration} دقیقه</button>)}</div></div>{error&&<div className="error">{error}</div>}<button className="btn btn-solid block" disabled={loading||!slotId||!ticketId} onClick={book}>{loading?'در حال رزرو...':'تأیید و رزرو'}</button></div>
}
