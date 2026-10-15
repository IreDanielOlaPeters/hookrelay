/**
 * Pure helpers for the proxy (auth gate + security headers). Kept free of Next imports so
 * they can be unit-tested.
 */

export const SESSION_COOKIE = 'hr_session';

const PUBLIC_PATHS = ['/login', '/healthz'];

/** Paths reachable without a session. Static assets are excluded by the proxy matcher. */
export function isPublicPath(pathname: string): boolean {
  return PUBLIC_PATHS.some((p) => pathname === p || pathname.startsWith(`${p}/`));
}

/**
 * Where to go after login. Only same-site relative paths are allowed, so `?next=` can't be
 * used as an open redirect (`//evil.com`, `/\\evil.com`, `https://…` all fall back to `/`).
 */
export function safeNext(next: string | null | undefined): string {
  if (!next || !next.startsWith('/') || next.startsWith('//') || next.startsWith('/\\')) return '/';
  if (/[\r\n\t]/.test(next) || next.startsWith('/login')) return '/';
  return next;
}

/** Is the original request HTTPS (directly or via a TLS-terminating proxy)? */
export function isHttps(protocol: string | null | undefined, forwardedProto: string | null | undefined): boolean {
  const forwarded = forwardedProto?.split(',')[0]?.trim().toLowerCase();
  if (forwarded) return forwarded === 'https';
  return protocol === 'https:' || protocol === 'https';
}

/** Content-Security-Policy with a per-request nonce for Next's scripts. */
export function buildCsp(nonce: string, dev = false): string {
  const scriptSrc = [`'self'`, `'nonce-${nonce}'`, `'strict-dynamic'`, ...(dev ? [`'unsafe-eval'`] : [])];
  return [
    `default-src 'self'`,
    `script-src ${scriptSrc.join(' ')}`,
    // Next.js and React inject a few inline style attributes.
    `style-src 'self' 'unsafe-inline'`,
    `img-src 'self' data:`,
    `font-src 'self'`,
    `connect-src 'self'`,
    `object-src 'none'`,
    `base-uri 'self'`,
    `form-action 'self'`,
    `frame-ancestors 'none'`,
  ].join('; ');
}

/** Headers added to every dashboard response. HSTS only when the request came over HTTPS. */
export function securityHeaders(csp: string, https: boolean): Record<string, string> {
  return {
    'Content-Security-Policy': csp,
    'X-Frame-Options': 'DENY',
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'strict-origin-when-cross-origin',
    'Permissions-Policy': 'camera=(), microphone=(), geolocation=()',
    ...(https ? { 'Strict-Transport-Security': 'max-age=31536000; includeSubDomains' } : {}),
  };
}
