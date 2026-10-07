import { NextResponse } from 'next/server';
import { prisma } from '@/lib/prisma';
import { generateOtp, hashOtp, normalizeIranPhone } from '@/lib/otp';
import { sendOtpSms } from '@/lib/sms';

export async function POST(req: Request) {
  try {
    const { phone: rawPhone } = await req.json();
    const phone = normalizeIranPhone(String(rawPhone || ''));
    if (!phone) return NextResponse.json({ error: 'شماره موبایل معتبر نیست.' }, { status: 400 });

    const last = await prisma.otpCode.findFirst({ where: { phone }, orderBy: { createdAt: 'desc' } });
    if (last && Date.now() - last.createdAt.getTime() < 60_000) {
      return NextResponse.json({ error: 'برای ارسال مجدد یک دقیقه صبر کنید.' }, { status: 429 });
    }

    const code = generateOtp();
    const ttl = Number(process.env.OTP_TTL_MINUTES || 5);
    await prisma.otpCode.create({
      data: { phone, codeHash: hashOtp(phone, code), expiresAt: new Date(Date.now() + ttl * 60_000) }
    });
    await sendOtpSms(phone, code);
    return NextResponse.json({ ok: true, ...(process.env.SMS_PROVIDER === 'development' && process.env.NODE_ENV !== 'production' ? { devCode: code } : {}) });
  } catch (e) {
    console.error(e);
    return NextResponse.json({ error: 'خطا در ارسال کد ورود.' }, { status: 500 });
  }
}
