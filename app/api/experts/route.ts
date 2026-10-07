import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
export async function GET(){const data=await prisma.expertProfile.findMany({where:{isVerified:true},include:{user:{select:{id:true,name:true,city:true}},slots:{where:{isBooked:false,startsAt:{gt:new Date()}},orderBy:{startsAt:'asc'},take:3}}});return NextResponse.json({data});}
