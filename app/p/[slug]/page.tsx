import { notFound } from 'next/navigation';
import { AppChrome } from '@/components/AppChrome';
import { HomeRenderer } from '@/components/HomeRenderer';
import { prisma } from '@/lib/prisma';
import { getCurrentUser } from '@/lib/auth';
export const dynamic='force-dynamic';
export default async function CustomPage({params}:{params:Promise<{slug:string}>}){const {slug}=await params;const [page,user]=await Promise.all([prisma.cmsPage.findUnique({where:{slug},include:{sections:{where:{enabled:true},include:{items:{where:{enabled:true},orderBy:{sortOrder:'asc'}}},orderBy:{sortOrder:'asc'}}}}),getCurrentUser()]);if(!page||!page.isPublished)notFound();return <AppChrome><div className="page-head"><h2>{page.title}</h2></div><HomeRenderer sections={page.sections} user={user} experts={[]} experiences={[]} tickets={[]} categories={[]}/></AppChrome>}
