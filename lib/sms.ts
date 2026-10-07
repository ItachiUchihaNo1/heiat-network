export async function sendOtpSms(phone: string, code: string) {
  const provider = process.env.SMS_PROVIDER || 'development';
  const message = `کد ورود شبکه تجربه هیأت: ${code}\nاین کد تا چند دقیقه معتبر است.`;

  if (provider === 'development') {
    console.log(`[DEV OTP] ${phone}: ${code}`);
    return { ok: true, provider: 'development' };
  }

  if (provider === 'webhook') {
    const url = process.env.SMS_WEBHOOK_URL;
    if (!url) throw new Error('SMS_WEBHOOK_URL is not configured');
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        ...(process.env.SMS_WEBHOOK_TOKEN
          ? { authorization: `Bearer ${process.env.SMS_WEBHOOK_TOKEN}` }
          : {}),
      },
      body: JSON.stringify({ to: phone, message }),
      cache: 'no-store',
    });
    if (!response.ok) throw new Error(`SMS gateway failed: ${response.status}`);
    return { ok: true, provider: 'webhook' };
  }

  throw new Error(`Unsupported SMS_PROVIDER: ${provider}`);
}
