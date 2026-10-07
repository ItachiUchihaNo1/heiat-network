import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
export async function GET(req:Request){const {searchParams}=new URL(req.url);const domain=searchParams.get('domain');const data=await prisma.experience.findMany({where:{status:'PUBLISHED',accessLevel:'PUBLIC',...(domain?{domain}:{})},orderBy:{publishedAt:'desc'},take:50});return NextResponse.json({data});}
