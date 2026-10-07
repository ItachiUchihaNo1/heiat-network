import Link from 'next/link';
import { AppChrome } from '@/components/AppChrome';
import { prisma } from '@/lib/prisma';

export default async function ExpertsPage({searchParams}:{searchParams:Promise<{ticketId?:string}>}){
  const {ticketId}=await searchParams;
  let orderedIds:string[]=[];
  if(ticketId){const mr=await prisma.matchingRequest.findUnique({where:{ticketId}});if(mr&&Array.isArray(mr.suggestedExperts)){orderedIds=(mr.suggestedExperts as any[]).map(x=>x.expertProfileId)}}
  const experts=await prisma.expertProfile.findMany({where:{isVerified:true},include:{user:true,slots:{where:{isBooked:false,startsAt:{gt:new Date()}},orderBy:{startsAt:'asc'},take:2}}});
  const sorted=orderedIds.length?[...experts].sort((a,b)=>orderedIds.indexOf(a.id)-orderedIds.indexOf(b.id)):experts;
  return <AppChrome><div className="page-head"><h2>{ticketId?'صاحبان تجربه پیشنهادی':'صاحبان تجربه'}</h2><p>{ticketId?'بر اساس موضوع، شهر، سابقه و زمان آزاد مرتب شده‌اند.':'پروفایل‌ها بر پایه راهی که فرد واقعاً رفته نمایش داده می‌شوند، نه صرفاً عنوان تخصص.'}</p></div>
    {sorted.map(e=><article className="expert-row" key={e.id}><div className="avatar">👤</div><div><h3>{e.user.name}</h3><span className="verified">تأیید شبکه</span><p><b>راه رفته:</b> {e.headline}</p><div className="tags">{e.tags.slice(0,4).map(t=><span className="tag" key={t}>{t}</span>)}</div><div className="small muted">{e.yearsExperience} سال تجربه · {e.experienceCount} تجربه ثبت‌شده{e.user.city?` · ${e.user.city}`:''}</div></div><div className="expert-actions"><Link className="btn btn-outline" href={'/experts/'+e.id}>پروفایل</Link><Link className="btn btn-solid" href={'/experts/'+e.id+(ticketId?`?ticketId=${ticketId}`:'')}>رزرو مشاوره</Link></div></article>)}
  </AppChrome>
}
