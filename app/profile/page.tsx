import Link from 'next/link';
import { AppChrome } from '@/components/AppChrome';
import { requireUser } from '@/lib/auth';
import { prisma } from '@/lib/prisma';
import LogoutButton from './LogoutButton';

const roleLabel:Record<string,string>={HAYAT_JOO:'هیأت‌جو',EXPERT:'صاحب تجربه',MODERATOR:'ناظر شبکه',KNOWLEDGE_EDITOR:'مدیر دانش',ADMIN:'ادمین'};
export default async function ProfilePage(){const user=await requireUser();const counts=await Promise.all([prisma.ticket.count({where:{userId:user.id}}),prisma.consultationSession.count({where:{OR:[{userId:user.id},{expertId:user.id}]}}),prisma.notification.count({where:{userId:user.id,readAt:null}})]);return <AppChrome active="profile"><div className="page-head"><h2>پروفایل</h2><p>نقش‌ها، فعالیت‌ها و دسترسی‌های شبکه.</p></div><div className="profile-hero">{user.avatarUrl?<img className="avatar avatar-img" src={user.avatarUrl} alt={user.name||''}/>:<div className="avatar">👤</div>}<div><h3 style={{margin:0}}>{user.name}</h3><div className="small muted">{user.city} · {user.roleInOrg}</div><div className="role-chips">{user.roles.map(r=><span className="role-chip" key={r}>{roleLabel[r]||r}</span>)}</div></div></div>
  <div className="panel"><div className="list-item"><div className="list-icon">?</div><div><b>مسئله‌های من</b><small>{counts[0]} مورد ثبت‌شده</small></div><Link className="chev" href="/issues">‹</Link></div><div className="list-item"><div className="list-icon">◫</div><div><b>مشاوره‌ها</b><small>{counts[1]} جلسه</small></div><Link className="chev" href="/bookings">‹</Link></div><div className="list-item"><div className="list-icon">●</div><div><b>اعلان‌ها</b><small>{counts[2]} اعلان خوانده‌نشده</small></div><span className="chev">‹</span></div></div>
  <div className="panel"><h3>{user.roles.includes('EXPERT')?'پروفایل صاحب تجربه':'راهی را رفته‌ای؟'}</h3><p className="small muted">حوزه‌های تجربه، ظرفیت و زمان‌های آزاد را مدیریت کن. پروفایل تا تأیید شبکه عمومی نمی‌شود.</p><Link className="btn btn-outline block" href="/expert/setup">{user.roles.includes('EXPERT')?'مدیریت پروفایل و زمان‌ها':'درخواست نقش صاحب تجربه'}</Link></div>
  {user.roles.includes('ADMIN')&&<div className="panel admin-card"><h3>داشبورد مدیریت کامل</h3><p className="small muted">ظاهر، منوها، تصاویر، صفحه‌ساز، کاربران و عملیات سامانه.</p><Link className="btn btn-solid block" href="/admin">ورود به داشبورد مدیریت</Link></div>}
  {(user.roles.includes('MODERATOR')||user.roles.includes('ADMIN'))&&<div className="panel admin-card"><h3>پنل ناظر شبکه</h3><Link className="btn btn-solid block" href="/admin/moderation">بررسی مسئله‌های جدید</Link></div>}
  {(user.roles.includes('KNOWLEDGE_EDITOR')||user.roles.includes('ADMIN'))&&<div className="panel admin-card"><h3>پنل مدیر دانش</h3><Link className="btn btn-solid block" href="/admin/knowledge">تبدیل جلسه به تجربه</Link></div>}
  <LogoutButton/>
  </AppChrome>}
