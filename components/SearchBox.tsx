'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';

export function SearchBox({placeholder='مسئله‌ات را جست‌وجو کن...',icon='⌕'}:{placeholder?:string;icon?:string}){
  const [q,setQ]=useState(''); const [items,setItems]=useState<any[]>([]); const [loading,setLoading]=useState(false);
  useEffect(()=>{
    const t=setTimeout(async()=>{
      if(q.trim().length<2){setItems([]);return;}
      setLoading(true);
      const r=await fetch('/api/search?q='+encodeURIComponent(q));
      const d=await r.json(); setItems(d.items||[]); setLoading(false);
    },300);
    return()=>clearTimeout(t);
  },[q]);
  return <div>
    <div className="search"><span>{icon}</span><input value={q} onChange={e=>setQ(e.target.value)} placeholder={placeholder} />{loading&&<small>...</small>}</div>
    {items.length>0&&<div className="search-results">{items.map((x:any)=><Link key={x.type+x.id} className="search-result" href={x.href}><b>{x.title}</b><small>{x.meta}</small></Link>)}</div>}
  </div>
}
