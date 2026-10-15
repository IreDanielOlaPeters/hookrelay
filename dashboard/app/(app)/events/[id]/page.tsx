import type { ReactNode } from 'react';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { replayDeliveryAction } from '@/app/actions';
import { ActionButton } from '@/components/ActionButton';
import { ApiErrorPanel } from '@/components/ApiErrorPanel';
import { AttemptTimeline } from '@/components/AttemptTimeline';
import { Badge } from '@/components/Badge';
import { PageHeader } from '@/components/PageHeader';
import { StatusBadge } from '@/components/StatusBadge';
import { Time } from '@/components/Time';
import { api, load, type Delivery } from '@/lib/api';
import { formatBytes, fullTimestamp, prettyJson } from '@/lib/format';

export const dynamic = 'force-dynamic';
export const metadata = { title: 'Event' };

function Meta({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</dt>
      <dd className="mt-0.5 wrap-break-word text-sm text-gray-900">{children}</dd>
    </div>
  );
}

function DeliveryCard({ d }: { d: Delivery }) {
  return (
    <div className="card">
      <div className="card-header">
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          <StatusBadge status={d.status} />
          <span className="font-medium text-gray-900">{d.destination?.name}</span>
          <span className="mono truncate text-gray-500" title={d.destination?.url}>
            {d.destination?.url}
          </span>
        </div>
        {d.status !== 'delivering' && (
          <ActionButton
            action={replayDeliveryAction}
            fields={{ id: d.id }}
            label="Replay"
            pendingLabel="Replaying…"
          />
        )}
      </div>
      <dl className="grid grid-cols-2 gap-4 border-b border-gray-100 px-4 py-3 sm:grid-cols-4">
        <Meta label="Attempts">
          <span className="tabular-nums">
            {d.attempt_count}/{d.max_attempts}
          </span>
        </Meta>
        <Meta label="Next attempt">
          <Time iso={d.next_attempt_at} />
        </Meta>
        <Meta label="Delivery ID">
          <span className="mono">{d.id}</span>
        </Meta>
        <Meta label="Last error">
          {d.last_error ? (
            <span className="mono text-red-700">{d.last_error}</span>
          ) : (
            <span className="text-gray-400">—</span>
          )}
        </Meta>
      </dl>
      <div className="px-4 py-3">
        <h3 className="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Attempts</h3>
        <AttemptTimeline attempts={d.attempts ?? []} />
      </div>
    </div>
  );
}

export default async function EventPage(props: { params: Promise<{ id: string }> }) {
  const params = await props.params;
  const res = await load(api.getEvent(params.id));
  if (!res.ok) {
    if (res.error.kind === 'not_found') notFound();
    return (
      <>
        <PageHeader title="Event" />
        <ApiErrorPanel error={res.error} />
      </>
    );
  }
  const e = res.data;
  const headerRows = Object.entries(e.headers ?? {}).flatMap(([name, values]) =>
    (Array.isArray(values) ? values : [String(values)]).map((v, i) => ({ key: `${name}-${i}`, name, value: v })),
  );
  const isBase64 = e.body_encoding === 'base64';
  const pretty = isBase64 ? null : prettyJson(e.body);

  return (
    <>
      <div className="mb-2 text-sm">
        <Link href="/events" className="link">
          ← Events
        </Link>
      </div>
      <PageHeader
        title={
          <span className="flex flex-wrap items-baseline gap-2">
            Event <span className="mono break-all text-sm font-normal text-gray-500">{e.id}</span>
          </span>
        }
      />

      <section className="card p-4">
        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Meta label="Source">
            {e.source?.name} <span className="mono text-gray-500">({e.source?.slug})</span>
          </Meta>
          <Meta label="Provider">{e.source?.provider ?? '—'}</Meta>
          <Meta label="Received">
            <Time iso={e.received_at} /> <span className="text-gray-500">· {fullTimestamp(e.received_at)}</span>
          </Meta>
          <Meta label="Content type">
            <span className="mono">{e.content_type ?? '—'}</span>
          </Meta>
          <Meta label="Signature">
            {e.verified ? <Badge tone="green">verified</Badge> : <Badge>unverified</Badge>}
          </Meta>
          <Meta label="Body size">{formatBytes(e.body_size)}</Meta>
          <Meta label="Idempotency key">
            <span className="mono break-all">{e.idempotency_key ?? '—'}</span>
          </Meta>
        </dl>
      </section>

      <section className="card mt-6">
        <div className="card-header">
          <h2 className="card-title">Headers</h2>
          <span className="text-xs text-gray-500">{headerRows.length}</span>
        </div>
        <div className="table-wrap max-h-96">
          <table className="table">
            <tbody>
              {headerRows.map((h) => (
                <tr key={h.key}>
                  <td className="mono w-1/3 font-medium text-gray-900">{h.name}</td>
                  <td className="mono whitespace-normal! break-all">{h.value}</td>
                </tr>
              ))}
              {headerRows.length === 0 && (
                <tr>
                  <td className="text-gray-400">No headers recorded.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      <section className="card mt-6">
        <div className="card-header">
          <h2 className="card-title">Body</h2>
          <span className="text-xs text-gray-500">
            {isBase64 ? 'base64-encoded (binary payload, shown raw)' : pretty ? 'JSON (pretty-printed)' : 'raw'}
          </span>
        </div>
        {isBase64 && (
          <p className="border-b border-gray-100 px-4 py-2 text-xs text-amber-800">
            The API returned this body base64-encoded (body_encoding: base64). It is shown raw, without decoding.
          </p>
        )}
        <pre className="max-h-128 overflow-auto whitespace-pre-wrap break-all p-4 font-mono text-xs text-gray-800">
          {e.body ? (pretty ?? e.body) : <span className="text-gray-400">(empty)</span>}
        </pre>
      </section>

      <section className="mt-6">
        <h2 className="mb-3 text-sm font-semibold text-gray-900">
          Deliveries <span className="font-normal text-gray-500">({e.deliveries?.length ?? 0})</span>
        </h2>
        <div className="space-y-4">
          {(e.deliveries ?? []).map((d) => (
            <DeliveryCard key={d.id} d={d} />
          ))}
          {(e.deliveries ?? []).length === 0 && (
            <p className="card p-4 text-sm text-gray-400">
              No deliveries: this source had no enabled connections when the event arrived.
            </p>
          )}
        </div>
      </section>
    </>
  );
}
