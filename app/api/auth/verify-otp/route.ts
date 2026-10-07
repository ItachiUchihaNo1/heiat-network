import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { hashOtp, normalizeIranPhone } from '@/lib/otp';
import { setSessionCookie } from '@/lib/auth';

export async function POST(req: Request) {
  try {
    const { phone: rawPhone, code } = await req.json();
    const phone = normalizeIranPhone(String(rawPhone || ''));
    if (!phone || !/^\d{6}$/.test(String(code || ''))) return NextResponse.json({ error: 'اطلاعات ورود معتبر نیست.' }, { status: 400 });

    const otp = await prisma.otpCode.findFirst({ where: { phone, consumedAt: null }, orderBy: { createdAt: 'desc' } });
    if (!otp || otp.expiresAt < new Date()) return NextResponse.json({ error: 'کد منقضی شده است.' }, { status: 400 });
    const maxAttempts = Number(process.env.OTP_MAX_ATTEMPTS || 5);
    if (otp.attempts >= maxAttempts) return NextResponse.json({ error: 'تعداد تلاش بیش از حد مجاز است.' }, { status: 429 });

    if (otp.codeHash !== hashOtp(phone, String(code))) {
      await prisma.otpCode.update({ where: { id: otp.id }, data: { attempts: { increment: 1 } } });
      return NextResponse.json({ error: 'کد واردشده صحیح نیست.' }, { status: 400 });
    }

    await prisma.otpCode.update({ where: { id: otp.id }, data: { consumedAt: new Date() } });
    const user = await prisma.user.upsert({
      where: { phone },
      update: { verifiedAt: new Date(), lastLoginAt: new Date() },
      create: { phone, roles: ['HAYAT_JOO'], verifiedAt: new Date(), lastLoginAt: new Date() },
    });
    if (!user.isActive) return NextResponse.json({ error: 'این حساب توسط مدیر غیرفعال شده است.' }, { status: 403 });
    await setSessionCookie(user.id);
    return NextResponse.json({ ok: true, needsOnboarding: !user.name || !user.city });
  } catch (e) {
    console.error(e);
    return NextResponse.json({ error: 'ورود ناموفق بود.' }, { status: 500 });
  }
}
