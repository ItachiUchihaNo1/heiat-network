import { AppChrome } from '@/components/AppChrome';
import { requireUser } from '@/lib/auth';
import { prisma } from '@/lib/prisma';
import { DOMAINS } from '@/lib/constants';
import IssueForm from './IssueForm';
export default async function NewIssuePage(){const user=await requireUser();const cats=await prisma.issueCategory.findMany({where:{enabled:true},orderBy:{sortOrder:'asc'}}).catch(()=>[]);const categories=cats.length?cats.map(c=>c.name):[...DOMAINS];return <AppChrome active="issue"><div className="page-head"><h2>ثبت مسئله جدید</h2><p>صورت مسئله را دقیق بنویس؛ هدف شبکه، وصل‌کردن تو به کسی است که این مسیر را واقعاً رفته.</p></div><IssueForm city={user.city} categories={categories}/></AppChrome>}
