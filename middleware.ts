import { NextRequest, NextResponse } from 'next/server';

const protectedPrefixes = ['/issues', '/bookings', '/sessions', '/profile', '/onboarding', '/expert', '/admin'];

export function middleware(req: NextRequest) {
  const protectedRoute = protectedPrefixes.some((p) => req.nextUrl.pathname.startsWith(p));
  if (!protectedRoute) return NextResponse.next();
  if (!req.cookies.get('heiat_session')) {
    const url = new URL('/login', req.url);
    url.searchParams.set('next', req.nextUrl.pathname);
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}

export const config = { matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'] };
