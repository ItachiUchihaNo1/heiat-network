import Link from 'next/link';
import { getCurrentUser } from '@/lib/auth';
import { getMenus, getSiteConfig } from '@/lib/cms';
import NavDrawer from './NavDrawer';
import { IconGlyph } from './IconGlyph';

export async function AppChrome({ children, active = 'home' }: { children: React.ReactNode; active?: string }) {
  const [user, site, drawerItems, bottomItems, headerItems, footerItems] = await Promise.all([
    getCurrentUser(), getSiteConfig(), getMenus('DRAWER'), getMenus('BOTTOM'), getMenus('HEADER'), getMenus('FOOTER')
  ]);
  const fallbackBottom = [
    {id:'h',label:'خانه',href:'/',icon:'home',imageUrl:null,target:'_self'},
    {id:'k',label:'تجربه‌ها',href:'/knowledge',icon:'knowledge',imageUrl:null,target:'_self'},
    {id:'i',label:'ثبت مسئله',href:'/issues/new',icon:'plus',imageUrl:null,target:'_self'},
    {id:'s',label:'مشاوره‌ها',href:'/bookings',icon:'sessions',imageUrl:null,target:'_self'},
    {id:'p',label:'پروفایل',href:'/profile',icon:'profile',imageUrl:null,target:'_self'},
  ];
  const nav = bottomItems.length ? bottomItems : fallbackBottom;
  return (
    <div className="app-shell">
      <header className="topbar"><div className="top-row">
        <div style={{display:'flex',alignItems:'center',gap:8}}>
          <NavDrawer items={drawerItems as any} buttonIcon={(site as any).menuIcon||'☰'}/>
          <Link href="/" className="brand">
            {site.logoUrl?<img className="brand-logo-img" src={site.logoUrl} alt={site.siteName}/>:<div className="logo">{(site as any).logoText||'هـ'}</div>}
            <div><h1>{site.siteName}</h1><small>{site.siteSubtitle}</small></div>
          </Link>
        </div>
        <Link className="icon-btn" href={user ? '/profile' : '/login'} aria-label="پروفایل">{user ? ((site as any).profileIcon||'○') : '↪'}</Link>
      </div>{headerItems.length>0&&<nav className="header-menu">{headerItems.map((i:any)=><Link key={i.id} href={i.href} target={i.target}>{i.imageUrl?<img src={i.imageUrl} alt=""/>:<IconGlyph icon={i.icon}/>}<span>{i.label}</span></Link>)}</nav>}</header>
      <main className="content">{children}</main>
      {(site.footerText||footerItems.length>0||(site as any).supportPhone||(site as any).supportEmail)&&<footer className="app-footer">{footerItems.length>0&&<nav className="footer-menu">{footerItems.map((i:any)=><Link key={i.id} href={i.href} target={i.target}>{i.label}</Link>)}</nav>}{site.footerText&&<div>{site.footerText}</div>}{((site as any).supportPhone||(site as any).supportEmail)&&<div className="footer-contact">{(site as any).supportPhone&&<span>{(site as any).supportPhone}</span>}{(site as any).supportEmail&&<span>{(site as any).supportEmail}</span>}</div>}</footer>}
      {user && <nav className="bottom-nav">{nav.slice(0,5).map((i:any)=>{
        const key=i.href==='/'?'home':i.href.includes('knowledge')?'knowledge':i.href.includes('issues/new')?'issue':i.href.includes('bookings')?'sessions':i.href.includes('profile')?'profile':'';
        const special=i.href.includes('/issues/new') || i.icon==='plus';
        return <Link key={i.id} className={`nav-btn ${active===key?'active':''}`} href={i.href} target={i.target}>
          {special?<span className="nav-plus"><IconGlyph icon={i.icon}/></span>:i.imageUrl?<img className="nav-img" src={i.imageUrl} alt=""/>:<IconGlyph icon={i.icon} className="ni"/>}
          <span className={special?'nav-plus-label':''}>{i.label}</span>
        </Link>
      })}</nav>}
    </div>
  );
}
