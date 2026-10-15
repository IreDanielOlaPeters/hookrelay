import { NextResponse, type NextRequest } from 'next/server';
import { buildCsp, isHttps, isPublicPath, safeNext, securityHeaders, SESSION_COOKIE } from '@/lib/security';

/**
 * Runs before every page and server action:
 *  1. Auth gate: no session cookie -> redirect to /login (the API still validates the token
 *     on every call; an expired one also lands on /login).
 *  2. Security headers: nonce-based CSP, X-Frame-Options, HSTS over HTTPS.
 */
export function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl;
  const https = isHttps(request.nextUrl.protocol, request.headers.get('x-forwarded-proto'));

  if (!isPublicPath(pathname) && !request.cookies.get(SESSION_COOKIE)?.value) {
    const login = new URL('/login', request.url);
    const next = safeNext(pathname + search);
    if (next !== '/') login.searchParams.set('next', next);
    return withHeaders(NextResponse.redirect(login), https);
  }

  const nonce = Buffer.from(crypto.randomUUID()).toString('base64');
  const csp = buildCsp(nonce, process.env.NODE_ENV !== 'production');

  // Next reads the nonce from the request's CSP header and applies it to its own scripts.
  const requestHeaders = new Headers(request.headers);
  requestHeaders.set('x-nonce', nonce);
  requestHeaders.set('Content-Security-Policy', csp);

  return withHeaders(NextResponse.next({ request: { headers: requestHeaders } }), https, csp);
}

function withHeaders(response: NextResponse, https: boolean, csp?: string): NextResponse {
  const headers = securityHeaders(csp ?? `frame-ancestors 'none'`, https);
  for (const [name, value] of Object.entries(headers)) response.headers.set(name, value);
  return response;
}

export const config = {
  // Everything except Next's static assets and the favicon.
  matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],
};
