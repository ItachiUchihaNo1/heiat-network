import { NextResponse } from 'next/server';
import { z } from 'zod';
import { getCurrentUser } from '@/lib/auth';
import { prisma } from '@/lib/prisma';

const schema = z.object({
  name: z.string().trim().min(3).max(80),
  city: z.string().trim().min(2).max(80),
  roleInOrg: z.string().trim().min(2).max(80),
  organizationName: z.string().trim().max(120).optional().or(z.literal('')),
});

export async function POST(req: Request) {
  const user = await getCurrentUser();
  if (!user) return NextResponse.json({ error: 'ابتدا وارد شوید.' }, { status: 401 });
  const parsed = schema.safeParse(await req.json());
  if (!parsed.success) return NextResponse.json({ error: 'اطلاعات واردشده کامل نیست.' }, { status: 400 });

  const { name, city, roleInOrg, organizationName } = parsed.data;
  await prisma.user.update({ where: { id: user.id }, data: { name, city, roleInOrg } });
  if (organizationName) {
    const org = await prisma.organization.create({ data: { name: organizationName, city, type: 'هیأت' } });
    await prisma.organizationMember.create({ data: { userId: user.id, organizationId: org.id, title: roleInOrg } });
  }
  return NextResponse.json({ ok: true });
}
