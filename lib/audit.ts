import { prisma } from './prisma';
export async function audit(actorId:string|undefined|null,action:string,entityType:string,entityId:string,metadata?:unknown){
  try{await prisma.auditLog.create({data:{actorId:actorId||null,action,entityType,entityId,metadata:metadata as any}})}catch{}
}
