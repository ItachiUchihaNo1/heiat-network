import type { Metadata } from 'next';
import './globals.css';
import { getSiteConfig } from '@/lib/cms';

export const dynamic = 'force-dynamic';

export async function generateMetadata(): Promise<Metadata> {
  const site = await getSiteConfig();
  return { title: site.siteName, description: site.siteSubtitle || 'شبکه مشورت و تجربه هیأت' };
}

export default async function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  const site = await getSiteConfig();
  const style = `:root{--primary:${site.primaryColor};--primary2:${site.primary2Color};--accent:${site.accentColor};--bg:${site.backgroundColor};--card:${site.cardColor};--text:${site.textColor};--muted:${site.mutedColor};--app-radius:${site.borderRadius}px;--app-max:${site.maxContentWidth}px;--app-font:${site.fontFamily}}${site.customCss||''}`;
  return <html lang="fa" dir="rtl"><head>{site.faviconUrl&&<link rel="icon" href={site.faviconUrl}/>}<style dangerouslySetInnerHTML={{__html:style}}/></head><body>{children}</body></html>;
}
