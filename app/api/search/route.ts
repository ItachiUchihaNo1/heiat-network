import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';

export async function GET(req: Request){
  const {searchParams}=new URL(req.url); const q=(searchParams.get('q')||'').trim();
  if(q.length<2) return NextResponse.json({items:[]});
  const [experiences,experts]=await Promise.all([
    prisma.experience.findMany({where:{status:'PUBLISHED',accessLevel:'PUBLIC',OR:[{title:{contains:q,mode:'insensitive'}},{problem:{contains:q,mode:'insensitive'}},{domain:{contains:q,mode:'insensitive'}}]},take:5}),
    prisma.expertProfile.findMany({where:{isVerified:true,OR:[{headline:{contains:q,mode:'insensitive'}},{bio:{contains:q,mode:'insensitive'}},{user:{name:{contains:q,mode:'insensitive'}}}]},include:{user:true},take:4})
  ]);
  const items=[
    ...experiences.map(x=>({type:'experience',id:x.id,title:x.title,meta:`تجربه · ${x.domain}`,href:`/knowledge/${x.id}`})),
    ...experts.map(x=>({type:'expert',id:x.id,title:x.user.name||'صاحب تجربه',meta:x.headline,href:`/experts/${x.id}`}))
  ];
  return NextResponse.json({items});
}
