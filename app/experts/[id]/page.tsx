import { notFound } from 'next/navigation';
import { AppChrome } from '@/components/AppChrome';
import { prisma } from '@/lib/prisma';
import { getCurrentUser } from '@/lib/auth';
import BookingForm from './BookingForm';

export default async function ExpertPage({params,searchParams}:{params:Promise<{id:string}>;searchParams:Promise<{ticketId?:string}>}){
  const {id}=await params; const {ticketId}=await searchParams; const user=await getCurrentUser();
  const expert=await prisma.expertProfile.findUnique({where:{id},include:{user:true,slots:{where:{isBooked:false,startsAt:{gt:new Date()}},orderBy:{startsAt:'asc'},take:12}}}); if(!expert||!expert.isVerified)notFound();
  const tickets=user?await prisma.ticket.findMany({where:{userId:user.id,status:'MATCHED'},orderBy:{createdAt:'desc'}}):[];
  return <AppChrome><div className="profile-hero"><div className="avatar">👤</div><div><h3 style={{margin:0}}>{expert.user.name}</h3><span className="verified">صاحب تجربه تأییدشده</span><div className="small muted" style={{marginTop:6}}>{expert.headline}</div></div></div>
    <div className="panel"><h3>راهی که رفته</h3><p className="small" style={{lineHeight:2}}>{expert.bio}</p><div className="tags">{expert.domains.map(d=><span className="tag" key={d}>{d}</span>)}</div><div className="advisor-meta"><span>{expert.yearsExperience} سال تجربه</span><span>{expert.experienceCount} تجربه ثبت‌شده</span></div></div>
    {!user?<div className="panel"><h3>برای رزرو وارد شوید</h3><a className="btn btn-solid block" href="/login">ورود با شماره موبایل</a></div>:<BookingForm expertProfileId={expert.id} initialTicketId={ticketId} tickets={tickets.map(t=>({id:t.id,title:t.title,status:t.status}))} slots={expert.slots.map(s=>({id:s.id,startsAt:s.startsAt.toISOString(),duration:s.duration}))}/>} 
  </AppChrome>
}
