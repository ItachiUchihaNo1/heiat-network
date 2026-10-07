import { notFound } from 'next/navigation';
import { AppChrome } from '@/components/AppChrome';
import { prisma } from '@/lib/prisma';
import { getCurrentUser } from '@/lib/auth';

function Block({title,items}:{title:string;items:string[]}){if(!items.length)return null;return <div className="panel"><h3>{title}</h3>{items.map((x,i)=><div className="list-item" key={i}><div className="list-icon">{i+1}</div><div><b>{x}</b></div></div>)}</div>}
export default async function ExperiencePage({params}:{params:Promise<{id:string}>}){
  const {id}=await params; const [x,user]=await Promise.all([prisma.experience.findUnique({where:{id}}),getCurrentUser()]); if(!x||x.status!=='PUBLISHED')notFound(); if(x.accessLevel==='MEMBERS'&&!user)notFound(); if(x.accessLevel==='PRIVATE'&&(!user||!user.roles.some(r=>['ADMIN','MODERATOR','KNOWLEDGE_EDITOR'].includes(r))))notFound();
  return <AppChrome active="knowledge">{x.coverImageUrl&&<img className="experience-cover" src={x.coverImageUrl} alt={x.title}/>}<div className="page-head"><h2>{x.title}</h2><p>{x.domain}{x.city?` · ${x.city}`:''}{x.orgType?` · ${x.orgType}`:''}</p></div>
    <div className="panel"><h3>صورت مسئله</h3><p className="small" style={{lineHeight:2}}>{x.problem}</p><div className="tags">{x.tags.map(t=><span key={t} className="tag">{t}</span>)}</div></div>
    <Block title="راه‌حل‌هایی که اجرا شد" items={x.solutions}/><Block title="نکات کلیدی" items={x.keyPoints}/><Block title="خطاها و دام‌ها" items={x.pitfalls}/><Block title="هشدارها" items={x.warnings}/><Block title="پیش‌نیازها" items={x.prerequisites}/>
  </AppChrome>
}
