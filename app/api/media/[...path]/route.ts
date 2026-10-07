import { NextRequest, NextResponse } from 'next/server';
import { readFile } from 'fs/promises';
import path from 'path';
const mime:Record<string,string>={jpg:'image/jpeg',jpeg:'image/jpeg',png:'image/png',webp:'image/webp',gif:'image/gif'};
export async function GET(_req:NextRequest,{params}:{params:Promise<{path:string[]}>}){
  const p=await params;const name=path.basename((p.path||[]).join('/'));if(!name)return new NextResponse('Not found',{status:404});
  const dir=process.env.UPLOAD_DIR||path.join(process.cwd(),'uploads');
  try{const data=await readFile(path.join(dir,name));const ext=(name.split('.').pop()||'').toLowerCase();return new NextResponse(data,{headers:{'Content-Type':mime[ext]||'application/octet-stream','Cache-Control':'public, max-age=31536000, immutable','X-Content-Type-Options':'nosniff'}})}catch{return new NextResponse('Not found',{status:404})}
}
