import { NextResponse } from 'next/server';
import { z } from 'zod';
import { getCurrentUser } from '@/lib/auth';
import { prisma } from '@/lib/prisma';

const schema=z.object({domain:z.string().trim().min(2).max(100),title:z.string().trim().min(5).max(120),description:z.string().trim().min(20).max(4000),currentStatusText:z.string().trim().max(2000).optional().or(z.literal('')),experienceNeeded:z.string().trim().max(1000).optional().or(z.literal('')),city:z.string().trim().min(2).max(80)});
export async function POST(req:Request){
  const user=await getCurrentUser(); if(!user)return NextResponse.json({error:'ابتدا وارد شوید.'},{status:401});
  const parsed=schema.safeParse(await req.json()); if(!parsed.success)return NextResponse.json({error:'اطلاعات مسئله کامل یا معتبر نیست.'},{status:400});
  const ticket=await prisma.ticket.create({data:{userId:user.id,...parsed.data,status:'SUBMITTED'}});
  await prisma.notification.create({data:{userId:user.id,type:'TICKET_SUBMITTED',payload:{ticketId:ticket.id,title:ticket.title}}});
  return NextResponse.json({ok:true,ticket});
}
export async function GET(){const user=await getCurrentUser();if(!user)return NextResponse.json({error:'Unauthorized'},{status:401});const data=await prisma.ticket.findMany({where:{userId:user.id},orderBy:{createdAt:'desc'}});return NextResponse.json({data});}

const updateSchema=z.object({ticketId:z.string().min(1),description:z.string().trim().min(20).max(4000),currentStatusText:z.string().trim().max(2000).optional().or(z.literal('')),experienceNeeded:z.string().trim().max(1000).optional().or(z.literal(''))});
export async function PATCH(req:Request){const user=await getCurrentUser();if(!user)return NextResponse.json({error:'ابتدا وارد شوید.'},{status:401});const parsed=updateSchema.safeParse(await req.json());if(!parsed.success)return NextResponse.json({error:'اطلاعات تکمیلی معتبر نیست.'},{status:400});const t=await prisma.ticket.findUnique({where:{id:parsed.data.ticketId}});if(!t||t.userId!==user.id)return NextResponse.json({error:'مسئله پیدا نشد.'},{status:404});if(t.status!=='NEEDS_INFO')return NextResponse.json({error:'این مسئله در وضعیت تکمیل اطلاعات نیست.'},{status:409});await prisma.ticket.update({where:{id:t.id},data:{description:parsed.data.description,currentStatusText:parsed.data.currentStatusText,experienceNeeded:parsed.data.experienceNeeded,status:'SUBMITTED'}});return NextResponse.json({ok:true});}
