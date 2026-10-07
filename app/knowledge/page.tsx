import Link from 'next/link';
import { AppChrome } from '@/components/AppChrome';
import { prisma } from '@/lib/prisma';
import { DOMAINS } from '@/lib/constants';

export default async function KnowledgePage({searchParams}:{searchParams:Promise<{domain?:string}>}){
  const {domain}=await searchParams;
  const [items,cats]=await Promise.all([prisma.experience.findMany({where:{status:'PUBLISHED',accessLevel:'PUBLIC',...(domain?{domain}:{})},orderBy:{publishedAt:'desc'},take:50}),prisma.issueCategory.findMany({where:{enabled:true},orderBy:{sortOrder:'asc'}}).catch(()=>[])]);
  const domains=cats.length?cats.map(c=>c.name):[...DOMAINS];
  return <AppChrome active="knowledge"><div className="page-head"><h2>بانک تجربه هیأت</h2><p>مسئله، راه‌حل، خطاها، هشدارها و پیش‌نیازهای استخراج‌شده از تجربه واقعی.</p></div>
    <div className="h-scroll" style={{marginBottom:10}}><Link className={`tag ${!domain?'verified':''}`} href="/knowledge">همه</Link>{domains.map(d=><Link className="tag" key={d} href={'/knowledge?domain='+encodeURIComponent(d)}>{d}</Link>)}</div>
    <div className="panel">{items.length?items.map(x=><Link key={x.id} href={'/knowledge/'+x.id} className="list-item"><div className="list-icon">▤</div><div><b>{x.title}</b><small>{x.domain}{x.city?` · ${x.city}`:''} · {x.keyPoints.length} نکته کلیدی</small></div><span className="chev">‹</span></Link>):<div className="empty"><div className="big">▤</div>هنوز تجربه‌ای در این دسته منتشر نشده.</div>}</div>
    <div className="panel"><h3>پاسخ کافی نبود؟</h3><p className="small muted">مسئله را ثبت کن تا ناظر شبکه آن را دقیق کند و صاحبان تجربه مناسب پیشنهاد شوند.</p><Link className="btn btn-solid" href="/issues/new">ثبت مسئله</Link></div>
  </AppChrome>
}
