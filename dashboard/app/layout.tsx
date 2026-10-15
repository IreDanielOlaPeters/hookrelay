import type { ReactNode } from 'react';
import type { Metadata } from 'next';
import Link from 'next/link';
import { Nav } from '@/components/Nav';
import './globals.css';

export const metadata: Metadata = {
  title: { default: 'HookRelay', template: '%s · HookRelay' },
  description: 'HookRelay webhook relay dashboard',
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      <body>
        <div className="md:flex md:min-h-screen">
          <aside className="border-b border-gray-200 bg-white md:w-56 md:shrink-0 md:border-b-0 md:border-r">
            <div className="flex flex-col gap-3 px-4 py-3 md:sticky md:top-0 md:gap-6 md:py-6">
              <Link href="/" className="flex items-center gap-2 text-sm font-semibold text-gray-900">
                <span className="inline-flex h-6 w-6 items-center justify-center rounded bg-gray-900 font-mono text-xs text-white">
                  HR
                </span>
                HookRelay
              </Link>
              <Nav />
            </div>
          </aside>
          <main className="min-w-0 flex-1 px-4 py-6 md:px-8">
            <div className="mx-auto max-w-6xl">{children}</div>
          </main>
        </div>
      </body>
    </html>
  );
}
