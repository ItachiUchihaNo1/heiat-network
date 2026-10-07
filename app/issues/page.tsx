import Link from 'next/link';
import { AppChrome } from '@/components/AppChrome';
import { requireUser } from '@/lib/auth';
import { prisma } from '@/lib/prisma';
import { TICKET_STATUS } from '@/lib/constants';
import IssueUpdate from './IssueUpdate';

export default async function IssuesPage(){
  const user=await requireUser();
  const tickets=await prisma.ticket.findMany({where:{userId:user.id},include:{matchingRequest:true,consultations:{include:{expert:true}}},orderBy:{createdAt:'desc'}});
  return <AppChrome active="issue"><div className="page-head"><h2>مسئله‌های من</h2><p>وضعیت بررسی، تطبیق و رزرو را از اینجا دنبال کن.</p></div><Link className="btn btn-solid block" href="/issues/new">＋ ثبت مسئله جدید</Link>
    {tickets.length?tickets.map(t=><div className="panel" key={t.id}><div style={{display:'flex',justifyContent:'space-between',gap:8,alignItems:'flex-start'}}><div><h3>{t.title}</h3><div className="small muted">{t.domain} · {t.city||'بدون شهر'}</div></div><span className="status blue">{TICKET_STATUS[t.status]||t.status}</span></div><p className="small" style={{lineHeight:1.9}}>{t.description}</p>{t.moderatorNote&&<div className="ai-box">یادداشت ناظر: {t.moderatorNote}</div>}{t.status==='NEEDS_INFO'&&<IssueUpdate ticket={{id:t.id,description:t.description,currentStatusText:t.currentStatusText,experienceNeeded:t.experienceNeeded}}/>}
      {t.status==='MATCHED'&&<Link className="btn btn-solid block" style={{marginTop:10}} href={'/experts?ticketId='+t.id}>مشاهده صاحبان تجربه پیشنهادی</Link>}
      {t.consultations.map(s=><Link key={s.id} href={'/sessions/'+s.id} className="list-item"><div className="list-icon">◫</div><div><b>جلسه با {s.expert.name}</b><small>{new Intl.DateTimeFormat('fa-IR',{dateStyle:'medium',timeStyle:'short'}).format(s.startTime)}</small></div><span className="chev">‹</span></Link>)}
    </div>):<div className="empty"><div className="big">?</div>هنوز مسئله‌ای ثبت نکرده‌ای.</div>}
  </AppChrome>
}
