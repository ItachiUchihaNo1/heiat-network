import { requireUser } from '@/lib/auth';
import { redirect } from 'next/navigation';
import OnboardingForm from './OnboardingForm';

export default async function OnboardingPage(){
  const user=await requireUser();
  if(user.name && user.city) redirect('/');
  return <div className="auth-shell"><div style={{width:'100%'}}><div className="page-head"><h2>تکمیل عضویت</h2><p>برای تطبیق بهتر مسئله با صاحب تجربه، چند اطلاعات پایه لازم است.</p></div><OnboardingForm/></div></div>;
}
