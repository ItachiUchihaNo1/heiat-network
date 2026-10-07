import { NextResponse } from 'next/server';
import { z } from 'zod';
import { getCurrentUser } from '@/lib/auth';
import { prisma } from '@/lib/prisma';
const schema=z.object({startsAt:z.string().min(10),duration:z.union([z.literal(30),z.literal(45)])});
export async function POST(req:Request){const user=await getCurrentUser();if(!user)return NextResponse.json({error:'Unauthorized'},{status:401});const parsed=schema.safeParse(await req.json());if(!parsed.success)return NextResponse.json({error:'زمان یا مدت معتبر نیست.'},{status:400});const profile=await prisma.expertProfile.findUnique({where:{userId:user.id}});if(!profile)return NextResponse.json({error:'ابتدا پروفایل صاحب تجربه را بسازید.'},{status:409});const startsAt=new Date(parsed.data.startsAt);if(Number.isNaN(startsAt.getTime())||startsAt<=new Date())return NextResponse.json({error:'زمان باید در آینده باشد.'},{status:400});const slot=await prisma.availabilitySlot.create({data:{expertProfileId:profile.id,startsAt,duration:parsed.data.duration}});return NextResponse.json({ok:true,slot});}
