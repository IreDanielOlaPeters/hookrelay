import 'server-only';
import { cookies, headers } from 'next/headers';
import { isHttps, SESSION_COOKIE } from '@/lib/security';

/** The dashboard session token (issued by POST /api/auth/login), from the httpOnly cookie. */
export async function sessionToken(): Promise<string | undefined> {
  return (await cookies()).get(SESSION_COOKIE)?.value || undefined;
}

export async function setSessionCookie(token: string, expiresAt: string): Promise<void> {
  const h = await headers();
  (await cookies()).set(SESSION_COOKIE, token, {
    httpOnly: true,
    sameSite: 'lax',
    secure: isHttps(null, h.get('x-forwarded-proto')),
    path: '/',
    expires: new Date(expiresAt),
  });
}

export async function clearSessionCookie(): Promise<void> {
  (await cookies()).delete(SESSION_COOKIE);
}
