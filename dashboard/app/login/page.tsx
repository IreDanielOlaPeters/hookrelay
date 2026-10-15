import type { Metadata } from 'next';
import { LoginForm } from '@/components/LoginForm';
import { api, type DemoInfo } from '@/lib/api';
import { safeNext } from '@/lib/security';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: 'Sign in' };

export default async function LoginPage(props: { searchParams: Promise<{ next?: string; expired?: string }> }) {
  const searchParams = await props.searchParams;
  let demo: DemoInfo = { demo_mode: false };
  let apiDown = false;
  try {
    demo = await api.demoInfo();
  } catch {
    apiDown = true;
  }

  return (
    <main className="flex min-h-screen flex-col items-center justify-center px-4 py-12">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center justify-center gap-2 text-lg font-semibold text-gray-900">
          <span className="inline-flex h-7 w-7 items-center justify-center rounded-sm bg-gray-900 font-mono text-xs text-white">
            HR
          </span>
          HookRelay
        </div>
        <section className="card p-6">
          <h1 className="mb-4 text-base font-semibold text-gray-900">Sign in to the dashboard</h1>
          {searchParams.expired && (
            <p className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
              Your session has expired. Please sign in again.
            </p>
          )}
          {apiDown && (
            <p className="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
              The HookRelay API is unreachable, so sign-in won&apos;t work yet.
            </p>
          )}
          <LoginForm next={safeNext(searchParams.next)} demo={demo} />
        </section>
        {demo.demo_mode && (
          <p className="mt-4 text-center text-xs text-gray-500">
            This is a public demo. The demo user can browse, send test webhooks and replay deliveries.
          </p>
        )}
      </div>
    </main>
  );
}
