import { notFound } from 'next/navigation';
import { AppChrome } from '@/components/AppChrome';
import { requireUser } from '@/lib/auth';
import { prisma } from '@/lib/prisma';
import SessionActions from './SessionActions';
import ReviewForm from './ReviewForm';

export default async function SessionPage({params}:{params:Promise<{id:string}>}){const user=await requireUser();const {id}=await params;const s=await prisma.consultationSession.findUnique({where:{id},include:{ticket:true,user:true,expert:true,experience:true,review:true}});if(!s||(s.userId!==user.id&&s.expertId!==user.id))notFound();const base=(process.env.JITSI_BASE_URL||'https://meet.jit.si').replace(/\/$/,'');const meetingUrl=`${base}/${s.meetingRoom}`;const other=s.userId===user.id?s.expert:s.user;return <AppChrome active="sessions"><div className="page-head"><h2>{s.ticket.title}</h2><p>جلسه با {other.name||'عضو شبکه'} · {s.duration} دقیقه</p></div><div className="panel"><div className="list-item"><div className="list-icon">◷</div><div><b>زمان جلسه</b><small>{new Intl.DateTimeFormat('fa-IR',{dateStyle:'full',timeStyle:'short'}).format(s.startTime)}</small></div></div><div className="list-item"><div className="list-icon">◫</div><div><b>اتاق جلسه</b><small>لینک جلسه فقط برای اعضای همین مشورت نمایش داده می‌شود.</small></div></div></div>
    {s.status==='SCHEDULED'&&<div className="panel"><h3>ورود به جلسه تصویری</h3><p className="small muted">برای MVP از Jitsi استفاده شده است. برای محیط حساس، دامنه Jitsi اختصاصی خودتان را تنظیم کنید.</p><a className="btn btn-solid block" href={meetingUrl} target="_blank" rel="noreferrer">ورود به اتاق جلسه</a><div style={{marginTop:8}}><SessionActions sessionId={s.id} canComplete={true}/></div></div>}
    {s.status==='COMPLETED'&&<><div className="panel"><h3>جلسه انجام شده</h3><p className="small muted">مرحله بعد: رضایت‌سنجی و در صورت رضایت طرفین، استخراج تجربه و ویرایش توسط مدیر دانش.</p>{s.experience&&<a className="btn btn-solid" href={'/knowledge/'+s.experience.id}>مشاهده تجربه منتشرشده</a>}</div>{s.userId===user.id&&!s.review&&<ReviewForm sessionId={s.id}/>}</>}
    <div className="panel"><h3>خلاصه مسئله برای جلسه</h3><p className="small" style={{lineHeight:2}}>{s.ticket.description}</p></div>
  </AppChrome>}
