import Link from 'next/link';
import { ApiErrorPanel } from '@/components/ApiErrorPanel';
import { Badge } from '@/components/Badge';
import { EmptyRow } from '@/components/EmptyRow';
import { FilterForm } from '@/components/FilterForm';
import { PageHeader } from '@/components/PageHeader';
import { Pagination } from '@/components/Pagination';
import { StatusBadge } from '@/components/StatusBadge';
import { Time } from '@/components/Time';
import { api, DELIVERY_STATUSES, load } from '@/lib/api';
import { dateBound, formatBytes, truncate } from '@/lib/format';
import { pageParam, param, type SearchParams } from '@/lib/params';

export const dynamic = 'force-dynamic';
export const metadata = { title: 'Events' };

export default async function EventsPage({ searchParams }: { searchParams: SearchParams }) {
  const sourceId = param(searchParams, 'source_id');
  const from = param(searchParams, 'from');
  const to = param(searchParams, 'to');
  const page = pageParam(searchParams);

  const [events, sources] = await Promise.all([
    load(
      api.listEvents({
        source_id: sourceId,
        from: dateBound(from, 'start'),
        to: dateBound(to, 'end'),
        page,
        per_page: 25,
      }),
    ),
    load(api.listSources()),
  ]);

  return (
    <>
      <PageHeader title="Events" description="Webhooks received by HookRelay." />
      {!events.ok ? (
        <ApiErrorPanel error={events.error} />
      ) : (
        <section className="card">
          <FilterForm action="/events" resetHref="/events">
            <div className="w-full sm:w-56">
              <label className="label" htmlFor="f-source">Source</label>
              <select id="f-source" name="source_id" className="input" defaultValue={sourceId ?? ''}>
                <option value="">All sources</option>
                {(sources.ok ? sources.data : []).map((s) => (
                  <option key={s.id} value={s.id}>{s.name} ({s.slug})</option>
                ))}
              </select>
            </div>
            <div>
              <label className="label" htmlFor="f-from">From</label>
              <input id="f-from" name="from" type="date" className="input" defaultValue={from} />
            </div>
            <div>
              <label className="label" htmlFor="f-to">To</label>
              <input id="f-to" name="to" type="date" className="input" defaultValue={to} />
            </div>
          </FilterForm>
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>Received</th>
                  <th>Source</th>
                  <th>Content type</th>
                  <th>Verified</th>
                  <th>Idempotency key</th>
                  <th>Size</th>
                  <th>Deliveries</th>
                </tr>
              </thead>
              <tbody>
                {events.data.data.length === 0 && <EmptyRow colSpan={7}>No events match.</EmptyRow>}
                {events.data.data.map((e) => (
                  <tr key={e.id}>
                    <td>
                      <Link href={`/events/${e.id}`} className="link">
                        <Time iso={e.received_at} />
                      </Link>
                    </td>
                    <td>
                      <div className="font-medium text-gray-900">{e.source?.name ?? '—'}</div>
                      <div className="mono text-gray-500">{e.source?.slug}</div>
                    </td>
                    <td className="mono">{e.content_type ?? '—'}</td>
                    <td>
                      {e.verified ? <Badge tone="green">verified</Badge> : <Badge>unverified</Badge>}
                    </td>
                    <td>
                      {e.idempotency_key ? (
                        <span className="mono" title={e.idempotency_key}>
                          {truncate(e.idempotency_key, 20)}
                        </span>
                      ) : (
                        <span className="text-gray-400">—</span>
                      )}
                    </td>
                    <td className="tabular-nums">{formatBytes(e.body_size)}</td>
                    <td>
                      <div className="flex flex-wrap gap-1">
                        {DELIVERY_STATUSES.filter((s) => (e.delivery_counts?.[s] ?? 0) > 0).map((s) => (
                          <StatusBadge key={s} status={s} count={e.delivery_counts[s]} />
                        ))}
                        {DELIVERY_STATUSES.every((s) => !(e.delivery_counts?.[s] ?? 0)) && (
                          <span className="text-xs text-gray-400">none</span>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination
            meta={events.data.meta}
            path="/events"
            query={{ source_id: sourceId, from, to }}
          />
        </section>
      )}
    </>
  );
}
