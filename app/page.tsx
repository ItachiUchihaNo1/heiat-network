import { AppChrome } from '@/components/AppChrome';
import { SearchBox } from '@/components/SearchBox';
import { HomeRenderer } from '@/components/HomeRenderer';
import { prisma } from '@/lib/prisma';
import { getCurrentUser } from '@/lib/auth';
import { getHomeCms, getSiteConfig } from '@/lib/cms';
import { DOMAINS } from '@/lib/constants';

export const dynamic='force-dynamic';

export default async function HomePage(){
  const user=await getCurrentUser();
  const [cms,site,experts,experiences,tickets,categories]=await Promise.all([
    getHomeCms(),
    getSiteConfig(),
    prisma.expertProfile.findMany({where:{isVerified:true},include:{user:true,slots:{where:{isBooked:false,startsAt:{gt:new Date()}},orderBy:{startsAt:'asc'},take:1}},take:8,orderBy:{experienceCount:'desc'}}),
    prisma.experience.findMany({where:{status:'PUBLISHED',accessLevel:'PUBLIC'},orderBy:{publishedAt:'desc'},take:8}),
    user?prisma.ticket.findMany({where:{userId:user.id},orderBy:{createdAt:'desc'},take:4}):Promise.resolve([]),
    prisma.issueCategory.findMany({where:{enabled:true},orderBy:{sortOrder:'asc'}}).catch(()=>[])
  ]);
  const fallbackCats=categories.length?categories:DOMAINS.map((name,i)=>({id:name,name,icon:['dashboard','child','heart','media','network','money','building','content','network','service','book','star','star','category'][i],imageUrl:null}));
  const fallbackSections:any[]=[
    {id:'q',type:'quick_actions',columnsDesktop:4,columnsMobile:4,items:[
      {id:'q1',title:'ثبت مسئله',subtitle:'مسئله‌ای داری؟',icon:'plus',linkUrl:user?'/issues/new':'/login'},
      {id:'q2',title:'صاحبان تجربه',subtitle:'فرد مناسب را پیدا کن',icon:'experts',linkUrl:'/experts'},
      {id:'q3',title:'تجربه‌ها',subtitle:'راه‌های رفته‌شده',icon:'knowledge',linkUrl:'/knowledge'},
      {id:'q4',title:'شبکه همراهان',subtitle:'عضویت و نقش‌ها',icon:'network',linkUrl:'/profile'},
    ]},
    {id:'h',type:'hero',eyebrow:'شبکه مشورت و تجربه هیأت',title:'هر مسئله‌ای، یک راه‌رفته دارد.',body:'مسئله را دقیق ثبت کن؛ ابتدا تجربه‌های مرتبط را می‌بینی و اگر کافی نبود، به صاحب تجربه‌ای که این مسیر را واقعاً رفته وصل می‌شوی.',primaryLabel:'ثبت مسئله',primaryUrl:user?'/issues/new':'/login',secondaryLabel:'پیدا کردن صاحب تجربه',secondaryUrl:'/experts'},
    {id:'t',type:'user_tickets',title:'وضعیت مسئله‌های من',primaryLabel:'مشاهده همه',primaryUrl:'/issues'},
    {id:'c',type:'categories',title:'در چه زمینه‌ای دنبال کمک هستی؟',columnsDesktop:4,columnsMobile:4},
    {id:'e',type:'experts',title:'صاحبان تجربه پیشنهادی',subtitle:'افرادی که این مسیر را در میدان تجربه کرده‌اند',primaryLabel:'مشاهده همه',primaryUrl:'/experts'},
    {id:'k',type:'knowledge',title:'شاید پاسخ مسئله‌ات همین‌جا باشد',primaryLabel:'همه تجربه‌ها',primaryUrl:'/knowledge',columnsDesktop:2,columnsMobile:2},
    {id:'x',type:'cta',title:'راهی را رفته‌ای؟',body:'تجربه‌ات می‌تواند مسیر یک هیأت دیگر را کوتاه‌تر کند.',primaryLabel:'به جمع صاحبان تجربه بپیوند',primaryUrl:user?'/profile':'/login'}
  ];
  return <AppChrome active="home"><SearchBox placeholder={(site as any).searchPlaceholder} icon={(site as any).searchIcon}/><div style={{height:12}}/><HomeRenderer sections={cms?.sections?.length?cms.sections:fallbackSections} user={user} experts={experts} experiences={experiences} tickets={tickets} categories={fallbackCats}/></AppChrome>;
}
