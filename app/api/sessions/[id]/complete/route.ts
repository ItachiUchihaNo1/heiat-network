import { NextResponse } from 'next/server';
import { getCurrentUser } from '@/lib/auth';
import { prisma } from '@/lib/prisma';
export async function POST(_:Request,{params}:{params:Promise<{id:string}>}){const user=await getCurrentUser();if(!user)return NextResponse.json({error:'Unauthorized'},{status:401});const {id}=await params;const s=await prisma.consultationSession.findUnique({where:{id}});if(!s||(s.userId!==user.id&&s.expertId!==user.id))return NextResponse.json({error:'Not found'},{status:404});await prisma.$transaction([prisma.consultationSession.update({where:{id},data:{status:'COMPLETED',completedAt:new Date()}}),prisma.ticket.update({where:{id:s.ticketId},data:{status:'COMPLETED'}})]);return NextResponse.json({ok:true});}
