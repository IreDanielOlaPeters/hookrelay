import Link from 'next/link';
import { ApiErrorPanel } from '@/components/ApiErrorPanel';
import { DeliveriesTable } from '@/components/DeliveriesTable';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { api, DELIVERY_STATUSES, load } from '@/lib/api';
import { formatDuration, formatNumber } from '@/lib/format';

export const dynamic = 'force-dynamic';

function StatCard({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="card p-4">
      <div className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</div>
      <div className="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{value}</div>
      {hint && <div className="mt-0.5 text-xs text-gray-500">{hint}</div>}
    </div>
  );
}

export default async function OverviewPage() {
  const [stats, failures] = await Promise.all([
    load(api.stats()),
    load(api.listDeliveries({ has_error: 1, per_page: 10 })),
  ]);

  if (!stats.ok) {
    return (
      <>
        <PageHeader title="Overview" />
        <ApiErrorPanel error={stats.error} />
      </>
    );
  }

  const s = stats.data;
  const d = s.deliveries;

  return (
    <>
      <PageHeader title="Overview" description={`Last ${s.window_hours} hours`} />

      <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Events received" value={formatNumber(s.events_received)} />
        <StatCard label="Deliveries" value={formatNumber(d.total)} />
        <StatCard
          label="Attempt latency"
          value={`${formatDuration(s.latency_ms.attempt.p50)}`}
          hint={`p50 · p95 ${formatDuration(s.latency_ms.attempt.p95)}`}
        />
        <StatCard
          label="End-to-end latency"
          value={`${formatDuration(s.latency_ms.end_to_end.p50)}`}
          hint={`p50 · p95 ${formatDuration(s.latency_ms.end_to_end.p95)}`}
        />
      </section>

      <section className="card mt-3 p-4">
        <div className="text-xs font-medium uppercase tracking-wide text-gray-500">
          Deliveries by status
        </div>
        <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
          {DELIVERY_STATUSES.map((status) => {
            const n = d[status] ?? 0;
            const pct = d.total > 0 ? Math.round((n / d.total) * 1000) / 10 : 0;
            const href = status === 'dead' ? '/dead-letter' : `/deliveries?status=${status}`;
            return (
              <Link key={status} href={href} className="rounded-md border border-gray-100 p-3 hover:bg-gray-50">
                <StatusBadge status={status} />
                <div className="mt-2 text-xl font-semibold tabular-nums">{formatNumber(n)}</div>
                <div className="text-xs text-gray-500">{pct}%</div>
              </Link>
            );
          })}
        </div>
      </section>

      <section className="card mt-6">
        <div className="card-header">
          <h2 className="card-title">Recent failures</h2>
          <Link href="/deliveries" className="link text-sm">All deliveries</Link>
        </div>
        {failures.ok ? (
          <DeliveriesTable deliveries={failures.data.data} replay={false} empty="No recent failures." />
        ) : (
          <div className="p-4">
            <ApiErrorPanel error={failures.error} />
          </div>
        )}
      </section>
    </>
  );
}
