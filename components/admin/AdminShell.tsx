import Link from 'next/link';
import { IconGlyph } from '@/components/IconGlyph';

type Nav={href:string;icon:string;label:string;roles:string[]};
const links:Nav[]=[
  {href:'/admin',icon:'dashboard',label:'داشبورد',roles:['ADMIN','MODERATOR','KNOWLEDGE_EDITOR']},
  {href:'/admin/cms/home',icon:'edit',label:'صفحه اصلی / صفحه‌ساز',roles:['ADMIN']},
  {href:'/admin/cms/pages',icon:'book',label:'صفحه‌ها',roles:['ADMIN']},
  {href:'/admin/cms/appearance',icon:'settings',label:'ظاهر و برند',roles:['ADMIN']},
  {href:'/admin/cms/menus',icon:'menu',label:'منوها',roles:['ADMIN']},
  {href:'/admin/cms/categories',icon:'category',label:'دسته‌بندی مسائل',roles:['ADMIN']},
  {href:'/admin/cms/media',icon:'media',label:'رسانه و تصاویر',roles:['ADMIN']},
  {href:'/admin/users',icon:'users',label:'کاربران و صاحبان تجربه',roles:['ADMIN']},
  {href:'/admin/operations',icon:'calendar',label:'مسائل و جلسات',roles:['ADMIN']},
  {href:'/admin/moderation',icon:'search',label:'اعتبارسنجی',roles:['ADMIN','MODERATOR']},
  {href:'/admin/knowledge',icon:'knowledge',label:'مدیریت دانش',roles:['ADMIN','KNOWLEDGE_EDITOR']},
  {href:'/admin/audit',icon:'content',label:'گزارش تغییرات',roles:['ADMIN']},
];
export function AdminShell({children,user}:{children:React.ReactNode;user:any}){
 const visible=links.filter(x=>x.roles.some(r=>user.roles.includes(r)));
 return <div className="admin-shell"><aside className="admin-sidebar"><Link href="/admin" className="admin-brand"><div className="logo">هـ</div><div><b>مدیریت شبکه هیأت</b><small>{user.name||user.phone}</small></div></Link><nav>{visible.map(x=><Link key={x.href} href={x.href}><IconGlyph icon={x.icon}/><span>{x.label}</span></Link>)}</nav><div className="admin-sidebar-foot"><Link href="/">← مشاهده سایت</Link><Link href="/profile">پروفایل من</Link></div></aside><div className="admin-main"><div className="admin-mobile-head"><b>پنل مدیریت</b><Link href="/">مشاهده سایت ←</Link></div>{children}</div></div>
}
