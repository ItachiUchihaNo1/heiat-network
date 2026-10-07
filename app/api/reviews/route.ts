import { NextResponse } from 'next/server';
import { z } from 'zod';
import { getCurrentUser } from '@/lib/auth';
import { prisma } from '@/lib/prisma';
const schema=z.object({sessionId:z.string().min(1),rating:z.number().int().min(1).max(5),comment:z.string().trim().max(2000).optional().or(z.literal(''))});
export async function POST(req:Request){const user=await getCurrentUser();if(!user)return NextResponse.json({error:'Unauthorized'},{status:401});const parsed=schema.safeParse(await req.json());if(!parsed.success)return NextResponse.json({error:'اطلاعات بازخورد معتبر نیست.'},{status:400});const s=await prisma.consultationSession.findUnique({where:{id:parsed.data.sessionId},include:{review:true}});if(!s||s.userId!==user.id||s.status!=='COMPLETED')return NextResponse.json({error:'جلسه معتبر نیست.'},{status:409});if(s.review)return NextResponse.json({error:'برای این جلسه قبلاً بازخورد ثبت شده.'},{status:409});const review=await prisma.review.create({data:{sessionId:s.id,userId:user.id,rating:parsed.data.rating,comment:parsed.data.comment||null,flags:[]}});return NextResponse.json({ok:true,review});}
