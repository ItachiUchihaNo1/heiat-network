import { NextResponse } from 'next/server';
import { z } from 'zod';
import crypto from 'crypto';
import { getCurrentUser } from '@/lib/auth';
import { prisma } from '@/lib/prisma';

const schema=z.object({expertProfileId:z.string().min(1),availableSlotId:z.string().min(1),ticketId:z.string().min(1)});
export async function POST(req:Request){
  const user=await getCurrentUser(); if(!user)return NextResponse.json({error:'ابتدا وارد شوید.'},{status:401});
  const parsed=schema.safeParse(await req.json()); if(!parsed.success)return NextResponse.json({error:'اطلاعات رزرو ناقص است.'},{status:400});
  const {expertProfileId,availableSlotId,ticketId}=parsed.data;
  const [ticket,expert,slot]=await Promise.all([
    prisma.ticket.findUnique({where:{id:ticketId}}),
    prisma.expertProfile.findUnique({where:{id:expertProfileId},include:{user:true}}),
    prisma.availabilitySlot.findUnique({where:{id:availableSlotId}})
  ]);
  if(!ticket||ticket.userId!==user.id)return NextResponse.json({error:'مسئله معتبر نیست.'},{status:404});
  if(ticket.status!=='MATCHED')return NextResponse.json({error:'این مسئله هنوز برای رزرو تأیید نشده است.'},{status:409});
  if(!expert||!expert.isVerified)return NextResponse.json({error:'صاحب تجربه معتبر نیست.'},{status:404});
  if(!slot||slot.expertProfileId!==expert.id||slot.isBooked||slot.startsAt<=new Date())return NextResponse.json({error:'این زمان دیگر قابل رزرو نیست.'},{status:409});
  const room=`heiat-${crypto.randomUUID()}`;
  try{
    const session=await prisma.$transaction(async tx=>{
      const updated=await tx.availabilitySlot.updateMany({where:{id:slot.id,isBooked:false},data:{isBooked:true}});
      if(updated.count!==1)throw new Error('slot_taken');
      const s=await tx.consultationSession.create({data:{ticketId:ticket.id,userId:user.id,expertId:expert.userId,expertProfileId:expert.id,availableSlotId:slot.id,startTime:slot.startsAt,duration:slot.duration,meetingRoom:room}});
      await tx.ticket.update({where:{id:ticket.id},data:{status:'SCHEDULED'}});
      await tx.notification.createMany({data:[
        {userId:user.id,type:'BOOKING_CREATED',payload:{sessionId:s.id,startTime:s.startTime.toISOString()}},
        {userId:expert.userId,type:'NEW_BOOKING',payload:{sessionId:s.id,ticketId:ticket.id,startTime:s.startTime.toISOString()}}
      ]});
      return s;
    });
    return NextResponse.json({ok:true,session});
  }catch(e:any){if(e?.message==='slot_taken')return NextResponse.json({error:'این زمان همین الان رزرو شد؛ زمان دیگری انتخاب کنید.'},{status:409});console.error(e);return NextResponse.json({error:'رزرو ناموفق بود.'},{status:500});}
}

export async function GET(){const user=await getCurrentUser();if(!user)return NextResponse.json({error:'Unauthorized'},{status:401});const data=await prisma.consultationSession.findMany({where:{OR:[{userId:user.id},{expertId:user.id}]},include:{ticket:true,user:true,expert:true},orderBy:{startTime:'desc'}});return NextResponse.json({data});}
