import { NextResponse } from 'next/server';
import { getCurrentUser } from '@/lib/auth';
import { prisma } from '@/lib/prisma';
export async function GET(req:Request){const user=await getCurrentUser();if(!user)return NextResponse.json({error:'Unauthorized'},{status:401});const {searchParams}=new URL(req.url);const ticketId=searchParams.get('ticketId');if(!ticketId)return NextResponse.json({error:'ticketId لازم است'},{status:400});const t=await prisma.ticket.findUnique({where:{id:ticketId},include:{matchingRequest:true}});if(!t||t.userId!==user.id)return NextResponse.json({error:'Not found'},{status:404});return NextResponse.json({data:t.matchingRequest?.suggestedExperts||[]});}
