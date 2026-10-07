'use server';
import { revalidatePath } from 'next/cache';
import { prisma } from '@/lib/prisma';
import { requireRole } from '@/lib/auth';
import { audit } from '@/lib/audit';
import { intValue, nullableText, safeJson, text } from '@/lib/cms';
import { mkdir, writeFile, unlink } from 'fs/promises';
import path from 'path';
import crypto from 'crypto';

async function admin(){ return requireRole('ADMIN'); }
function checked(fd:FormData,key:string){return fd.get(key)==='on'||fd.get(key)==='true'||fd.get(key)==='1'}
function csv(v:FormDataEntryValue|null){return text(v).split(/[,،\n]/).map(x=>x.trim()).filter(Boolean)}
function paths(){
  [
    '/',
    '/admin',
    '/admin/cms/appearance',
    '/admin/cms/home',
    '/admin/cms/menus',
    '/admin/cms/categories',
    '/admin/cms/media',
    '/admin/cms/pages',
    '/admin/users',
    '/admin/operations',
  ].forEach((path) => revalidatePath(path))
}

export async function saveSiteConfig(fd:FormData){
  const user=await admin();
  const data={
    siteName:text(fd.get('siteName'))||'شبکه تجربه هیأت', siteSubtitle:text(fd.get('siteSubtitle')), searchPlaceholder:text(fd.get('searchPlaceholder'))||'مسئله‌ات را جست‌وجو کن...',
    logoUrl:nullableText(fd.get('logoUrl')),faviconUrl:nullableText(fd.get('faviconUrl')),logoText:text(fd.get('logoText'))||'هـ',menuIcon:text(fd.get('menuIcon'))||'☰',profileIcon:text(fd.get('profileIcon'))||'○',searchIcon:text(fd.get('searchIcon'))||'⌕',
    primaryColor:text(fd.get('primaryColor'))||'#0b4f59',primary2Color:text(fd.get('primary2Color'))||'#0f8a80',accentColor:text(fd.get('accentColor'))||'#23b49b',
    backgroundColor:text(fd.get('backgroundColor'))||'#f5f8f9',cardColor:text(fd.get('cardColor'))||'#ffffff',textColor:text(fd.get('textColor'))||'#163239',mutedColor:text(fd.get('mutedColor'))||'#6d8186',
    borderRadius:Math.max(0,Math.min(40,intValue(fd.get('borderRadius'),18))),maxContentWidth:Math.max(360,Math.min(1600,intValue(fd.get('maxContentWidth'),520))),
    fontFamily:text(fd.get('fontFamily'))||'Tahoma, Segoe UI, sans-serif',customCss:nullableText(fd.get('customCss')),footerText:nullableText(fd.get('footerText')),supportPhone:nullableText(fd.get('supportPhone')),supportEmail:nullableText(fd.get('supportEmail')),
  };
  const row=await prisma.siteConfig.upsert({where:{singletonKey:'default'},update:data,create:{singletonKey:'default',...data}});
  await audit(user.id,'UPDATE','SiteConfig',row.id,{siteName:data.siteName}); paths();
}

export async function saveCmsPage(fd:FormData){
  const user=await admin(); const id=text(fd.get('id')); const slug=text(fd.get('slug')).replace(/^\/+|\/+$/g,'')||`page-${Date.now()}`;
  const data={slug,title:text(fd.get('title'))||slug,seoTitle:nullableText(fd.get('seoTitle')),seoDescription:nullableText(fd.get('seoDescription')),isPublished:checked(fd,'isPublished')};
  const row=id?await prisma.cmsPage.update({where:{id},data}):await prisma.cmsPage.create({data}); await audit(user.id,id?'UPDATE':'CREATE','CmsPage',row.id,{slug}); paths();
}
export async function deleteCmsPage(fd:FormData){const user=await admin();const id=text(fd.get('id'));const row=await prisma.cmsPage.findUnique({where:{id}});if(row?.slug==='home')return;await prisma.cmsPage.delete({where:{id}});await audit(user.id,'DELETE','CmsPage',id);paths();}

export async function saveSection(fd:FormData){
  const user=await admin();const id=text(fd.get('id'));const pageId=text(fd.get('pageId'));const key=text(fd.get('key'))||`section-${Date.now()}`;
  const data={pageId,key,type:text(fd.get('type'))||'cards',title:nullableText(fd.get('title')),subtitle:nullableText(fd.get('subtitle')),eyebrow:nullableText(fd.get('eyebrow')),body:nullableText(fd.get('body')),imageUrl:nullableText(fd.get('imageUrl')),backgroundUrl:nullableText(fd.get('backgroundUrl')),primaryLabel:nullableText(fd.get('primaryLabel')),primaryUrl:nullableText(fd.get('primaryUrl')),secondaryLabel:nullableText(fd.get('secondaryLabel')),secondaryUrl:nullableText(fd.get('secondaryUrl')),columnsDesktop:Math.max(1,Math.min(6,intValue(fd.get('columnsDesktop'),2))),columnsMobile:Math.max(1,Math.min(4,intValue(fd.get('columnsMobile'),1))),sortOrder:intValue(fd.get('sortOrder'),0),enabled:checked(fd,'enabled'),settings:safeJson(fd.get('settings'),{}) as any};
  const row=id?await prisma.cmsSection.update({where:{id},data}):await prisma.cmsSection.create({data});await audit(user.id,id?'UPDATE':'CREATE','CmsSection',row.id,{key,type:data.type});paths();
}
export async function deleteSection(fd:FormData){const user=await admin();const id=text(fd.get('id'));await prisma.cmsSection.delete({where:{id}});await audit(user.id,'DELETE','CmsSection',id);paths();}

export async function saveCmsItem(fd:FormData){
  const user=await admin();const id=text(fd.get('id'));const data={sectionId:text(fd.get('sectionId')),title:text(fd.get('title'))||'آیتم جدید',subtitle:nullableText(fd.get('subtitle')),body:nullableText(fd.get('body')),icon:nullableText(fd.get('icon')),imageUrl:nullableText(fd.get('imageUrl')),badge:nullableText(fd.get('badge')),linkLabel:nullableText(fd.get('linkLabel')),linkUrl:nullableText(fd.get('linkUrl')),sortOrder:intValue(fd.get('sortOrder'),0),enabled:checked(fd,'enabled'),meta:safeJson(fd.get('meta'),{}) as any};
  const row=id?await prisma.cmsItem.update({where:{id},data}):await prisma.cmsItem.create({data});await audit(user.id,id?'UPDATE':'CREATE','CmsItem',row.id,{title:data.title});paths();
}
export async function deleteCmsItem(fd:FormData){const user=await admin();const id=text(fd.get('id'));await prisma.cmsItem.delete({where:{id}});await audit(user.id,'DELETE','CmsItem',id);paths();}

export async function saveMenuItem(fd:FormData){
  const user=await admin();const id=text(fd.get('id'));const data={location:text(fd.get('location'))||'DRAWER',label:text(fd.get('label'))||'آیتم منو',href:text(fd.get('href'))||'#',icon:nullableText(fd.get('icon')),imageUrl:nullableText(fd.get('imageUrl')),target:text(fd.get('target'))||'_self',sortOrder:intValue(fd.get('sortOrder'),0),enabled:checked(fd,'enabled'),parentId:nullableText(fd.get('parentId'))};
  const row=id?await prisma.menuItem.update({where:{id},data}):await prisma.menuItem.create({data});await audit(user.id,id?'UPDATE':'CREATE','MenuItem',row.id,{location:data.location,label:data.label});paths();
}
export async function deleteMenuItem(fd:FormData){const user=await admin();const id=text(fd.get('id'));await prisma.menuItem.delete({where:{id}});await audit(user.id,'DELETE','MenuItem',id);paths();}

export async function saveCategory(fd:FormData){
  const user=await admin();const id=text(fd.get('id'));let slug=text(fd.get('slug'));if(!slug)slug=(text(fd.get('name'))||`cat-${Date.now()}`).replace(/\s+/g,'-');
  const data={slug,name:text(fd.get('name'))||'دسته جدید',description:nullableText(fd.get('description')),icon:nullableText(fd.get('icon')),imageUrl:nullableText(fd.get('imageUrl')),sortOrder:intValue(fd.get('sortOrder'),0),enabled:checked(fd,'enabled')};
  const row=id?await prisma.issueCategory.update({where:{id},data}):await prisma.issueCategory.create({data});await audit(user.id,id?'UPDATE':'CREATE','IssueCategory',row.id,{name:data.name});paths();
}
export async function deleteCategory(fd:FormData){const user=await admin();const id=text(fd.get('id'));await prisma.issueCategory.delete({where:{id}});await audit(user.id,'DELETE','IssueCategory',id);paths();}

const mimeExt:Record<string,string>={'image/jpeg':'jpg','image/png':'png','image/webp':'webp','image/gif':'gif'};
export async function uploadMedia(fd:FormData){
  const user=await admin();const file=fd.get('file');if(!(file instanceof File)||file.size===0)return;
  if(file.size>8*1024*1024)throw new Error('حداکثر حجم فایل ۸ مگابایت است.'); const ext=mimeExt[file.type];if(!ext)throw new Error('فقط JPG، PNG، WEBP و GIF مجاز است.');
  const dir=process.env.UPLOAD_DIR||path.join(process.cwd(),'uploads');await mkdir(dir,{recursive:true});const storageName=`${Date.now()}-${crypto.randomBytes(8).toString('hex')}.${ext}`;await writeFile(path.join(dir,storageName),Buffer.from(await file.arrayBuffer()));
  const row=await prisma.mediaAsset.create({data:{fileName:file.name,storageName,url:`/api/media/${storageName}`,mimeType:file.type,size:file.size,title:nullableText(fd.get('title')),alt:nullableText(fd.get('alt')),createdById:user.id}});await audit(user.id,'CREATE','MediaAsset',row.id,{fileName:file.name});paths();
}
export async function updateMedia(fd:FormData){const user=await admin();const id=text(fd.get('id'));const row=await prisma.mediaAsset.update({where:{id},data:{title:nullableText(fd.get('title')),alt:nullableText(fd.get('alt'))}});await audit(user.id,'UPDATE','MediaAsset',row.id);paths();}
export async function deleteMedia(fd:FormData){const user=await admin();const id=text(fd.get('id'));const row=await prisma.mediaAsset.findUnique({where:{id}});if(!row)return;const dir=process.env.UPLOAD_DIR||path.join(process.cwd(),'uploads');await unlink(path.join(dir,row.storageName)).catch(()=>{});await prisma.mediaAsset.delete({where:{id}});await audit(user.id,'DELETE','MediaAsset',id);paths();}

export async function saveUserAdmin(fd:FormData){
  const actor=await admin();const id=text(fd.get('id'));const roles=fd.getAll('roles').map(String);const data={name:nullableText(fd.get('name')),city:nullableText(fd.get('city')),roleInOrg:nullableText(fd.get('roleInOrg')),avatarUrl:nullableText(fd.get('avatarUrl')),roles:roles.length?roles:['HAYAT_JOO'],isActive:checked(fd,'isActive')};await prisma.user.update({where:{id},data});await audit(actor.id,'UPDATE','User',id,{roles:data.roles,isActive:data.isActive});paths();
}
export async function saveExpertAdmin(fd:FormData){
  const actor=await admin();const userId=text(fd.get('userId'));const existing=await prisma.expertProfile.findUnique({where:{userId}});const data={headline:text(fd.get('headline'))||'صاحب تجربه',bio:text(fd.get('bio'))||'—',domains:csv(fd.get('domains')),tags:csv(fd.get('tags')),yearsExperience:intValue(fd.get('yearsExperience'),0),experienceCount:intValue(fd.get('experienceCount'),0),capacityWeek:intValue(fd.get('capacityWeek'),2),capacityMonth:intValue(fd.get('capacityMonth'),8),isVerified:checked(fd,'isVerified')};const row=existing?await prisma.expertProfile.update({where:{userId},data}):await prisma.expertProfile.create({data:{userId,...data}});await audit(actor.id,existing?'UPDATE':'CREATE','ExpertProfile',row.id);paths();
}

export async function updateTicketAdmin(fd:FormData){const actor=await admin();const id=text(fd.get('id'));const status=text(fd.get('status'));const moderatorNote=nullableText(fd.get('moderatorNote'));await prisma.ticket.update({where:{id},data:{status,moderatorNote}});await audit(actor.id,'UPDATE_STATUS','Ticket',id,{status});paths();}
export async function updateSessionAdmin(fd:FormData){const actor=await admin();const id=text(fd.get('id'));const status=text(fd.get('status'));await prisma.consultationSession.update({where:{id},data:{status,completedAt:status==='COMPLETED'?new Date():undefined}});await audit(actor.id,'UPDATE_STATUS','ConsultationSession',id,{status});paths();}

export async function saveExperienceAdmin(fd:FormData){
  const actor=await requireRole('ADMIN','KNOWLEDGE_EDITOR');
  const id=text(fd.get('id'));
  const data={
    title:text(fd.get('title'))||'تجربه بدون عنوان',domain:text(fd.get('domain'))||'سایر',subdomain:nullableText(fd.get('subdomain')),city:nullableText(fd.get('city')),orgType:nullableText(fd.get('orgType')),coverImageUrl:nullableText(fd.get('coverImageUrl')),
    problem:text(fd.get('problem'))||'—',solutions:csv(fd.get('solutions')),keyPoints:csv(fd.get('keyPoints')),pitfalls:csv(fd.get('pitfalls')),warnings:csv(fd.get('warnings')),prerequisites:csv(fd.get('prerequisites')),tags:csv(fd.get('tags')),
    accessLevel:text(fd.get('accessLevel'))||'PUBLIC',status:text(fd.get('status'))||'PUBLISHED',editedById:actor.id,publishedAt:text(fd.get('status'))==='PUBLISHED'?new Date():null,
  };
  if(!id) throw new Error('شناسه تجربه لازم است.');
  const row=await prisma.experience.update({where:{id},data});await audit(actor.id,'UPDATE','Experience',row.id,{status:data.status});revalidatePath('/knowledge');revalidatePath('/admin/knowledge');
}
export async function deleteExperienceAdmin(fd:FormData){const actor=await requireRole('ADMIN','KNOWLEDGE_EDITOR');const id=text(fd.get('id'));await prisma.experience.delete({where:{id}});await audit(actor.id,'DELETE','Experience',id);revalidatePath('/knowledge');revalidatePath('/admin/knowledge');}
