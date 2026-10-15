import type { ReactNode } from 'react';
import Link from 'next/link';
import { logoutAction } from '@/app/actions';
import { DemoBanner } from '@/components/DemoBanner';
import { Nav } from '@/components/Nav';
import { getMe } from '@/lib/api';

// Every page in this group requires a signed-in user (the proxy checks the cookie,
// getMe() validates the session with the API and redirects to /login if it's gone).
export const dynamic = 'force-dynamic';

export default async function AppLayout({ children }: { children: ReactNode }) {
  const me = await getMe();

  return (
    <>
      {me.demo_mode && <DemoBanner />}
      <div className="md:flex md:min-h-screen">
        <aside className="border-b border-gray-200 bg-white md:w-56 md:shrink-0 md:border-b-0 md:border-r">
          <div className="flex flex-col gap-3 px-4 py-3 md:sticky md:top-0 md:gap-6 md:py-6">
            <Link href="/" className="flex items-center gap-2 text-sm font-semibold text-gray-900">
              <span className="inline-flex h-6 w-6 items-center justify-center rounded-sm bg-gray-900 font-mono text-xs text-white">
                HR
              </span>
              HookRelay
            </Link>
            <Nav />
            <div className="border-t border-gray-200 pt-3 text-xs text-gray-500 md:pt-4">
              <div className="truncate font-medium text-gray-700" title={me.user.email}>
                {me.user.email}
              </div>
              <div className="mb-2">{me.user.role === 'demo' ? 'Demo user (limited access)' : 'Admin'}</div>
              <form action={logoutAction}>
                <button type="submit" className="btn-secondary btn-sm">Sign out</button>
              </form>
            </div>
          </div>
        </aside>
        <main className="min-w-0 flex-1 px-4 py-6 md:px-8">
          <div className="mx-auto max-w-6xl">{children}</div>
        </main>
      </div>
    </>
  );
}
