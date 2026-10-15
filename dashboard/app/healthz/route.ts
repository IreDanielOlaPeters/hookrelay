// Liveness for the container healthcheck. Public (see lib/security.ts) and dependency-free.
export const dynamic = 'force-dynamic';

export function GET() {
  return Response.json({ status: 'ok' });
}
