'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { IconGlyph } from './IconGlyph';

type Item={id:string;label:string;href:string;icon:string|null;imageUrl:string|null;target:string;children?:Item[]};
export default function NavDrawer({items,buttonIcon='☰'}:{items:Item[];buttonIcon?:string}){
  const [open,setOpen]=useState(false);
  useEffect(()=>{
    const esc=(e:KeyboardEvent)=>{if(e.key==='Escape')setOpen(false)};
    window.addEventListener('keydown',esc);
    document.body.style.overflow=open?'hidden':'';
    return()=>{window.removeEventListener('keydown',esc);document.body.style.overflow=''};
  },[open]);
  return <>
    <button className="icon-btn" onClick={()=>setOpen(true)} aria-label="باز کردن منو">{buttonIcon}</button>
    <div className={`drawer-overlay ${open?'open':''}`} onClick={()=>setOpen(false)} />
    <aside className={`drawer ${open?'open':''}`} aria-hidden={!open}>
      <div className="drawer-head"><b>منوی اصلی</b><button className="icon-btn" onClick={()=>setOpen(false)} aria-label="بستن">×</button></div>
      <nav className="drawer-nav">
        {items.map(i=><div key={i.id}>
          <Link href={i.href} target={i.target} onClick={()=>setOpen(false)} className="drawer-link">
            {i.imageUrl?<img src={i.imageUrl} alt=""/>:<IconGlyph icon={i.icon}/>}<span>{i.label}</span>
          </Link>
          {!!i.children?.length&&<div className="drawer-sub">{i.children.map(c=><Link key={c.id} href={c.href} onClick={()=>setOpen(false)}>{c.label}</Link>)}</div>}
        </div>)}
      </nav>
    </aside>
  </>
}
