import crypto from 'crypto';

export function normalizeIranPhone(input: string) {
  const digits = input.replace(/\D/g, '');
  if (/^09\d{9}$/.test(digits)) return digits;
  if (/^989\d{9}$/.test(digits)) return `0${digits.slice(2)}`;
  if (/^9\d{9}$/.test(digits)) return `0${digits}`;
  return null;
}

export function generateOtp() {
  return crypto.randomInt(100000, 1000000).toString();
}

export function hashOtp(phone: string, code: string) {
  const pepper = process.env.OTP_PEPPER || process.env.AUTH_SECRET || '';
  return crypto.createHash('sha256').update(`${phone}:${code}:${pepper}`).digest('hex');
}
