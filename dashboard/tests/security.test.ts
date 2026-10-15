import { describe, expect, it } from 'vitest';
import { buildCsp, isHttps, isPublicPath, safeNext, securityHeaders } from '@/lib/security';

describe('isPublicPath', () => {
  it('only lets the login page through without a session', () => {
    expect(isPublicPath('/login')).toBe(true);
    expect(isPublicPath('/')).toBe(false);
    expect(isPublicPath('/events')).toBe(false);
    expect(isPublicPath('/loginx')).toBe(false);
    expect(isPublicPath('/sources')).toBe(false);
  });
});

describe('safeNext', () => {
  it('keeps same-site relative paths', () => {
    expect(safeNext('/events?page=2')).toBe('/events?page=2');
    expect(safeNext('/events/abc')).toBe('/events/abc');
  });

  it('blocks open redirects and loops', () => {
    for (const bad of ['//evil.com', '/\\evil.com', 'https://evil.com', 'evil.com', '/login', '/x\r\nSet-Cookie: a=b', '', null, undefined]) {
      expect(safeNext(bad)).toBe('/');
    }
  });
});

describe('isHttps', () => {
  it('trusts the first X-Forwarded-Proto value, else the request protocol', () => {
    expect(isHttps('http:', 'https')).toBe(true);
    expect(isHttps('http:', 'https, http')).toBe(true);
    expect(isHttps('https:', 'http')).toBe(false);
    expect(isHttps('https:', null)).toBe(true);
    expect(isHttps('http:', undefined)).toBe(false);
  });
});

describe('security headers', () => {
  it('builds a nonce-based CSP that forbids framing, plugins and inline scripts', () => {
    const csp = buildCsp('abc123');
    expect(csp).toContain(`script-src 'self' 'nonce-abc123' 'strict-dynamic'`);
    expect(csp).not.toContain('unsafe-eval');
    expect(csp).toContain(`frame-ancestors 'none'`);
    expect(csp).toContain(`object-src 'none'`);
    expect(buildCsp('n', true)).toContain(`'unsafe-eval'`);
  });

  it('adds HSTS only over HTTPS', () => {
    expect(securityHeaders('x', false)).not.toHaveProperty('Strict-Transport-Security');
    expect(securityHeaders('x', true)['Strict-Transport-Security']).toBe('max-age=31536000; includeSubDomains');
    expect(securityHeaders('x', false)['X-Frame-Options']).toBe('DENY');
  });
});
