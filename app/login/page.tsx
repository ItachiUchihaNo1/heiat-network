import { getCurrentUser } from '@/lib/auth';
import { redirect } from 'next/navigation';
import LoginForm from './LoginForm';
import { getSiteConfig } from '@/lib/cms';

export default async function LoginPage() {
  const [user,site] = await Promise.all([getCurrentUser(),getSiteConfig()]);
  if (user) redirect('/');
  return <div className="auth-shell"><LoginForm siteName={site.siteName} logoUrl={site.logoUrl} logoText={(site as any).logoText}/></div>;
}
